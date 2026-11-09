<?php
// (4.3) SDK support: the most downloaded PC recomps whose own GitHub doesn't ship a
// recomplauncher.json yet, so the team can send their developers a pull request (or a nudge).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

const RS_SDK_TOP = 50;

// Recomps taken off the list by hand: a pull request is already open for it ('pr'), or its developer
// said they don't want to support RecompLauncher ('no'). [id => [kind, url, note, who, at]]
function rs_sdk_marks(): array { return json_decode((string)rs_setting('sdk_marks', '{}'), true) ?: []; }
function rs_sdk_marks_save(array $m): void { rs_set_setting('sdk_marks', (string)json_encode($m, JSON_UNESCAPED_SLASHES)); }
// A GitHub link has to be on this recomp's own repo: github.com/<owner>/<repo>/(pull|issues|discussions)/<n>.
function rs_sdk_link(array $a, string $url, bool $prOnly): array {
    $url = trim($url);
    if (!preg_match('#^https?://(?:www\.)?github\.com/([\w.-]+)/([\w.-]+)/(pull|issues|discussions)/(\d+)#i', $url, $m)) return [null, $prOnly ? 'Paste the pull request\'s GitHub link, like github.com/owner/repo/pull/12.' : 'Paste a GitHub link to the pull request, issue or discussion.'];
    if (strcasecmp($m[1] . '/' . $m[2], (string)$a['repo']) !== 0) return [null, 'That link is on ' . $m[1] . '/' . $m[2] . ', not on ' . $a['repo'] . '. Check you pasted the right one.'];
    if ($prOnly && strtolower($m[3]) !== 'pull') return [null, 'That\'s an issue or discussion, not a pull request.'];
    $kind = strtolower($m[3]) === 'pull' ? 'pulls' : (strtolower($m[3]) === 'issues' ? 'issues' : '');
    if ($kind !== '') {
        $r = rs_http(rs_gh_api() . '/repos/' . $a['repo'] . '/' . $kind . '/' . $m[4], ['github' => true, 'timeout' => 10, 'max' => 2000000]);
        if ($r['status'] === 404) return [null, 'GitHub says #' . $m[4] . ' doesn\'t exist on ' . $a['repo'] . '.'];
    }
    return ['https://github.com/' . $a['repo'] . '/' . strtolower($m[3]) . '/' . $m[4], ''];
}
// A pull request's state on GitHub (open, merged or closed), kept an hour.
function rs_sdk_pr_state(array $a, string $url): string {
    if (!preg_match('#/pull/(\d+)$#', $url, $m)) return '';
    $k = 'sdkpr:' . $a['id'] . ':' . $m[1];
    if (($c = rs_kv_get($k)) && $c['age'] < 3600) return $c['v'];
    $r = rs_http(rs_gh_api() . '/repos/' . $a['repo'] . '/pulls/' . $m[1], ['github' => true, 'timeout' => 8, 'max' => 2000000]);
    $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
    $st = is_array($j) ? (!empty($j['merged_at']) ? 'merged' : (string)($j['state'] ?? '')) : '';
    if ($st !== '') rs_kv_put($k, $st);
    return $st;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $id = (string)($_POST['id'] ?? '');
    if (($_POST['action'] ?? '') === 'recheck' && ($a = rs_pc_app($id))) {
        $r = rs_pc_sdk_check($a, true);
        rs_flash($a['name'] . ($r['has'] === 'release' || $r['has'] === 'repo' ? ' ships its own recomplauncher.json now.' : ' still doesn\'t ship one.'));
    } elseif (in_array($_POST['action'] ?? '', ['mark_pr', 'mark_no'], true) && ($a = rs_pc_app($id))) {
        $pr = $_POST['action'] === 'mark_pr';
        $url = trim((string)($_POST['url'] ?? ''));
        [$link, $why] = $url === '' && !$pr ? ['', ''] : rs_sdk_link($a, $url, $pr);
        if ($link === null) rs_flash($why, 'err');
        else {
            $m = rs_sdk_marks();
            $m[$id] = ['kind' => $pr ? 'pr' : 'no', 'url' => $link, 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200), 'who' => rs_me_name(), 'at' => gmdate('Y-m-d')];
            rs_sdk_marks_save($m);
            rs_log('sdk', $id, $pr ? 'pull request: ' . $link : 'won\'t support' . ($link ? ': ' . $link : ''));
            rs_flash($pr ? $a['name'] . ' moved to Pull requests open.' : $a['name'] . ' is off the list.');
        }
    } elseif (($_POST['action'] ?? '') === 'unmark' && ($a = rs_pc_app($id))) {
        $m = rs_sdk_marks();
        unset($m[$id]);
        rs_sdk_marks_save($m);
        rs_log('sdk', $id, 'back on the list');
        rs_flash($a['name'] . ' is back on the list.');
    } elseif (($_POST['action'] ?? '') === 'recheck_all') {
        rs_db()->exec("DELETE FROM kv_cache WHERE k LIKE 'sdk:%'");
        rs_flash('Checking everything again in the background. Reload in a minute or two.');
    }
    header('Location: sdk-support.php' . ($id !== '' ? '#r-' . rawurlencode($id) : ''));
    exit;
}

// Listed recomps, most downloaded first. The page only reads what's been checked; the checking runs
// after the page has gone (a minute at a time), so it never keeps anyone waiting.
// Only today's console recomps: not PC games, and projects that started in 2025 or later (older
// projects with big download counts would crowd out the ones worth a pull request).
const RS_SDK_SINCE = '2025-01-01';
$apps = array_values(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && empty($a['soon']) && (string)$a['repo'] !== ''
    && !in_array((string)$a['origin'], ['pc', 'other'], true)
    && ((string)$a['first'] !== '' ? (string)$a['first'] : (string)$a['released']) >= RS_SDK_SINCE));
usort($apps, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
$missing = [];
$has = 0;
$waiting = 0;
$toCheck = [];
$marks = rs_sdk_marks();
$prs = $nos = [];
foreach ($apps as $rank => $a) {
    // A pull request that got merged and shipped: it's done, whatever the mark says.
    if (isset($marks[$a['id']]) && ($c0 = rs_kv_get('sdk:' . $a['id'])) && in_array(json_decode($c0['v'], true)['has'] ?? '', ['release', 'repo'], true)) { ++$has; continue; }
    if (isset($marks[$a['id']])) { if ($marks[$a['id']]['kind'] === 'pr') $prs[] = ['a' => $a, 'm' => $marks[$a['id']]]; else $nos[] = ['a' => $a, 'm' => $marks[$a['id']]]; continue; }
    if (count($missing) >= RS_SDK_TOP) continue;
    $c = rs_kv_get('sdk:' . $a['id']);
    $r = $c && $c['age'] < 7 * 86400 ? json_decode($c['v'], true) : null;
    if (!is_array($r)) { ++$waiting; $toCheck[] = $a['id']; continue; }
    if ($r['has'] === 'release' || $r['has'] === 'repo') { ++$has; continue; }
    $boot = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
    $now = is_array($boot) && $boot ? ((int)($boot['format'] ?? 0) === 1 && isset($boot['start']) ? 'json' : 'profile') : (!empty($a['setup_required']) ? 'steps' : 'none');
    $missing[] = ['a' => $a, 'rank' => $rank + 1, 'now' => $now, 'unknown' => $r['has'] === 'unknown'];
}
// One background run at a time: the next unchecked ones, for up to a minute.
if ($toCheck) {
    $run = rs_kv_get('sdk_scan_run');
    if (!$run || $run['age'] > 150) {
        rs_kv_put('sdk_scan_run', (string)time());
        register_shutdown_function(function () use ($toCheck) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            ignore_user_abort(true);
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            @set_time_limit(150);
            $t0 = microtime(true);
            foreach ($toCheck as $id) {
                if (microtime(true) - $t0 > 60) break;
                if ($a = rs_pc_app($id)) { try { rs_pc_sdk_check($a); } catch (Throwable $e) { /* next run */ } }
            }
            rs_db()->exec("DELETE FROM kv_cache WHERE k = 'sdk_scan_run'");
        });
    }
}
$nowLabel = [
    'json' => ['Its file, in our catalog', 'The developer\'s recomplauncher.json is pasted into the catalog. Shipping it in the release keeps it up to date.'],
    'profile' => ['Our setup profile', 'Easy Setup works from a profile we wrote. The developer\'s own file would be better.'],
    'steps' => ['Setup steps only', 'Players follow written steps before the first play.'],
    'none' => ['Nothing yet', 'Players set it up by hand.'],
];
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';

rs_head('SDK support', true, ['assets/account.css']);
?>
<section class="head-row">
  <div>
    <h1>SDK support</h1>
    <p class="muted">The <?= RS_SDK_TOP ?> most downloaded console recomps whose own GitHub doesn't ship a <code>recomplauncher.json</code> yet. Each one is a pull request away from Easy Setup for everyone: open its GitHub, give your AI agent the prompt, and send the developer the result.</p>
  </div>
  <form method="post" class="actions"><?= $csrf ?><input type="hidden" name="action" value="recheck_all"><button class="btn">Check them all again</button></form>
</section>
<div class="role-cards">
  <div class="role-card"><span class="muted small">Ship their own file</span><span class="n"><?= number_format($has) ?></span><p class="muted small">Of the most downloaded checked so far.</p></div>
  <div class="role-card"><span class="muted small">On this list</span><span class="n"><?= count($missing) ?></span><p class="muted small">Most downloaded first. Pick one and send a pull request.</p></div>
  <div class="role-card"><span class="muted small">Still to check</span><span class="n"><?= $waiting ?></span><p class="muted small"><?= $waiting ? 'Being checked in the background. Reload in a minute.' : 'All checked. Each one is looked at again after a week.' ?></p></div>
</div>
<section class="card">
  <div class="sdk-list">
  <?php foreach ($missing as $m): $a = $m['a']; [$nl, $nw] = $nowLabel[$m['now']]; $gh = 'https://github.com/' . $a['repo']; ?>
    <article class="sdk-row" id="r-<?= h($a['id']) ?>">
      <span class="sdk-rank">#<?= (int)$m['rank'] ?></span>
      <?php if ($a['icon']): ?><img src="<?= h($a['icon']) ?>" alt="" width="44" height="44" loading="lazy"><?php else: ?><span class="sdk-ico"><?= h(mb_substr($a['name'], 0, 2)) ?></span><?php endif; ?>
      <div class="sdk-name"><a href="pc.php?edit=<?= h(rawurlencode($a['id'])) ?>"><strong><?= h($a['name']) ?></strong></a><span class="muted small"><?= h($a['repo']) ?> · <?= number_format((int)$a['downloads']) ?> downloads</span></div>
      <span class="pill" title="<?= h($nw) ?>"><?= h($m['unknown'] ? 'Couldn\'t check' : $nl) ?></span>
      <div class="sdk-btns">
        <a class="btn" href="<?= h($gh) ?>" target="_blank" rel="noopener">GitHub ↗</a>
        <button type="button" class="btn primary" data-prompt-repo="<?= h($a['repo']) ?>" data-prompt-game="<?= h((string)($a['game'] ?: $a['name'])) ?>">Copy agent prompt</button>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn" title="Check its GitHub again">Check again</button></form>
      </div>
      <details class="sdk-mark">
        <summary>Take it off the list</summary>
        <div class="sdk-mark-forms">
          <form method="post" class="sdk-mark-form"><?= $csrf ?><input type="hidden" name="action" value="mark_pr"><input type="hidden" name="id" value="<?= h($a['id']) ?>">
            <label>A pull request is open<input name="url" required placeholder="https://github.com/<?= h($a['repo']) ?>/pull/…" spellcheck="false"></label><button class="btn">Save</button></form>
          <form method="post" class="sdk-mark-form"><?= $csrf ?><input type="hidden" name="action" value="mark_no"><input type="hidden" name="id" value="<?= h($a['id']) ?>">
            <label>The developer doesn't want it<input name="url" placeholder="Link where they said so (optional)" spellcheck="false"></label><button class="btn danger">Remove</button></form>
        </div>
      </details>
    </article>
  <?php endforeach; ?>
  <?php if (!$missing): ?><p class="muted">Nothing on the list<?= $waiting ? ' yet. Reload in a minute to see more.' : '. Every popular recomp ships its own file.' ?></p><?php endif; ?>
  </div>
</section>
<?php if ($prs): ?>
<section class="card">
  <h2>Pull requests open <span class="count"><?= count($prs) ?></span></h2>
  <p class="muted small">Off the list while their pull request is waiting. When one is merged, press Check again on it once the developer's next release is out.</p>
  <div class="sdk-list">
  <?php foreach ($prs as $p): $a = $p['a']; $st = rs_sdk_pr_state($a, (string)$p['m']['url']); ?>
    <article class="sdk-row sdk-row-s" id="r-<?= h($a['id']) ?>">
      <div class="sdk-name"><strong><?= h($a['name']) ?></strong><span class="muted small"><?= h($a['repo']) ?> · added by <?= h((string)$p['m']['who']) ?> on <?= h((string)$p['m']['at']) ?></span></div>
      <span class="pill<?= $st === 'merged' ? ' ok' : ($st === 'closed' ? ' warn' : '') ?>"><?= h($st === 'merged' ? 'Merged' : ($st === 'closed' ? 'Closed, not merged' : ($st === 'open' ? 'Open' : 'Couldn\'t check'))) ?></span>
      <div class="sdk-btns">
        <a class="btn" href="<?= h((string)$p['m']['url']) ?>" target="_blank" rel="noopener">Pull request ↗</a>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn">Check again</button></form>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="unmark"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn">Back on the list</button></form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php if ($nos): ?>
<section class="card">
  <details>
    <summary><strong>Won't support RecompLauncher</strong> <span class="count"><?= count($nos) ?></span></summary>
    <div class="sdk-list" style="margin-top:12px">
    <?php foreach ($nos as $p): $a = $p['a']; ?>
      <article class="sdk-row sdk-row-s">
        <div class="sdk-name"><strong><?= h($a['name']) ?></strong><span class="muted small"><?= h($a['repo']) ?> · <?= h((string)$p['m']['who']) ?>, <?= h((string)$p['m']['at']) ?></span></div>
        <span></span>
        <div class="sdk-btns">
          <?php if ((string)$p['m']['url'] !== ''): ?><a class="btn" href="<?= h((string)$p['m']['url']) ?>" target="_blank" rel="noopener">Where they said so ↗</a><?php endif; ?>
          <form method="post"><?= $csrf ?><input type="hidden" name="action" value="unmark"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn">Back on the list</button></form>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  </details>
</section>
<?php endif; ?>
<style>
.sdk-list{display:flex;flex-direction:column;gap:8px}
.sdk-row{display:grid;grid-template-columns:44px 44px minmax(0,1fr) auto auto;gap:14px;align-items:center;padding:10px 12px;border:1px solid var(--line);border-radius:14px}
.sdk-row img,.sdk-ico{width:44px;height:44px;border-radius:12px;object-fit:cover}
.sdk-ico{display:grid;place-items:center;background:rgba(var(--ink-rgb),.08);font:700 14px Sora,sans-serif}
.sdk-rank{color:var(--muted);font-weight:700;font-variant-numeric:tabular-nums}
.sdk-name{display:flex;flex-direction:column;min-width:0}
.sdk-name a{color:var(--frost);text-decoration:none}
.sdk-name span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sdk-btns{display:flex;gap:8px;align-items:center}
.sdk-btns form{margin:0}
.sdk-btns .btn{min-height:38px;padding:7px 12px;font-size:14px}
.sdk-row-s{grid-template-columns:minmax(0,1fr) auto auto}
.sdk-mark{grid-column:1/-1;margin:0}
.sdk-mark summary{margin:0;font-size:13.5px}
.sdk-mark-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:10px}
.sdk-mark-form{display:flex;gap:8px;align-items:flex-end}
.sdk-mark-form label{flex:1;margin:0;font-size:14px}
@media (max-width:980px){.sdk-mark-forms{grid-template-columns:minmax(0,1fr)}.sdk-row-s{grid-template-columns:minmax(0,1fr)}}
@media (max-width:980px){.sdk-row{grid-template-columns:44px minmax(0,1fr)}.sdk-rank{display:none}.sdk-row .pill,.sdk-btns{grid-column:1/-1}}
</style>
<script>
// The prompt for an AI agent working in the recomp's repo: add the SDK file and ship it in releases.
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-prompt-repo]');
  if (!b) return;
  var repo = b.getAttribute('data-prompt-repo'), game = b.getAttribute('data-prompt-game');
  var text = [
    'Add RecompLauncher support to ' + repo + ' (a PC recomp of ' + game + '), as a pull request.',
    '',
    'RecompLauncher (https://recomplauncher.com) installs and updates PC recomps straight from their GitHub releases. Its SDK lets a recomp set itself up from the player\'s own copy of the game and show its settings in the launcher, all from one file: recomplauncher.json. The recomp itself doesn\'t change.',
    '',
    '1. Read the guide: https://recomplauncher.com/sdk-files/guide.md',
    '   The JSON schema: https://recomplauncher.com/schema/recomplauncher.json',
    '   Two worked examples: https://recomplauncher.com/sdk-files/examples/fifawc14.json and https://recomplauncher.com/sdk-files/examples/streetball.json',
    '2. Look at how this project finds the game\'s files, what it needs from the player\'s copy (disc image, ROM or extracted files), how it starts, and where it keeps its settings.',
    '3. Write recomplauncher.json with every level that fits this project:',
    '   - Level 1, Easy Setup ("game" and "start"): the player picks their copy, the launcher checks it, sets it up and starts the game.',
    '   - Level 2, settings in the launcher ("config" and "settings"): if the project has a settings file or command-line options, show them in the launcher.',
    '   - Level 3, full setup ("prepare" and "game.dumps"): if the project does work before the first start (extracting assets, fonts or menu art from the disc, building a cache), add a "prepare" step that runs it without a window and prints progress lines; add "dumps" with the known good copies of the game (name, size and CRC32 from Redump) so players get the verified badge.',
    '4. Ship it in every release: put recomplauncher.json at the top of each release zip, next to the executable, and inside the AppImage at /recomplauncher.json if there is one. Update the release workflow or packaging script so it\'s always included.',
    '5. Check the file against the schema. Don\'t change how the game runs, and never add any game files.',
    '6. Open the pull request with a short, friendly description of what it adds for players and that nothing else changes.',
    '',
    'When you\'re done, tell me what you set up and anything you weren\'t sure about.'
  ].join('\n');
  var label = b.textContent;
  var done = function (ok) { b.textContent = ok ? 'Copied' : 'Couldn\'t copy'; setTimeout(function () { b.textContent = label; }, 2500); };
  if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { done(true); }, function () { done(false); }); else done(false);
});
</script>
<?php rs_foot();
