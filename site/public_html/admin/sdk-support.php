<?php
// (4.3) SDK support: the most downloaded PC recomps whose own GitHub doesn't ship a
// recomplauncher.json yet, so the team can send their developers a pull request (or a nudge).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

const RS_SDK_TOP = 50;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $id = (string)($_POST['id'] ?? '');
    if (($_POST['action'] ?? '') === 'recheck' && ($a = rs_pc_app($id))) {
        $r = rs_pc_sdk_check($a, true);
        rs_flash($a['name'] . ($r['has'] === 'release' || $r['has'] === 'repo' ? ' ships its own recomplauncher.json now.' : ' still doesn\'t ship one.'));
    } elseif (($_POST['action'] ?? '') === 'recheck_all') {
        rs_db()->exec("DELETE FROM kv_cache WHERE k LIKE 'sdk:%'");
        rs_flash('Checking everything again. It fills in as you reload.');
    }
    header('Location: sdk-support.php' . ($id !== '' ? '#r-' . rawurlencode($id) : ''));
    exit;
}

// Listed recomps, most downloaded first. Each one is checked once a week; a page load checks for at
// most 20 seconds, so the first visit may need a reload or two.
$apps = array_values(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && empty($a['soon']) && (string)$a['repo'] !== ''));
usort($apps, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
$start = microtime(true);
$missing = [];
$has = 0;
$waiting = 0;
foreach ($apps as $rank => $a) {
    if (count($missing) >= RS_SDK_TOP) break;
    $c = rs_kv_get('sdk:' . $a['id']);
    $r = $c && $c['age'] < 7 * 86400 ? json_decode($c['v'], true) : null;
    if (!is_array($r)) {
        if (microtime(true) - $start > 20) { ++$waiting; continue; }
        $r = rs_pc_sdk_check($a);
    }
    if ($r['has'] === 'release' || $r['has'] === 'repo') { ++$has; continue; }
    $boot = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
    $now = is_array($boot) && $boot ? ((int)($boot['format'] ?? 0) === 1 && isset($boot['start']) ? 'json' : 'profile') : (!empty($a['setup_required']) ? 'steps' : 'none');
    $missing[] = ['a' => $a, 'rank' => $rank + 1, 'now' => $now, 'unknown' => $r['has'] === 'unknown'];
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
    <p class="muted">The <?= RS_SDK_TOP ?> most downloaded recomps whose own GitHub doesn't ship a <code>recomplauncher.json</code> yet. Each one is a pull request away from Easy Setup for everyone: open its GitHub, give your AI agent the prompt, and send the developer the result.</p>
  </div>
  <form method="post" class="actions"><?= $csrf ?><input type="hidden" name="action" value="recheck_all"><button class="btn">Check them all again</button></form>
</section>
<div class="role-cards">
  <div class="role-card"><span class="muted small">Ship their own file</span><span class="n"><?= number_format($has) ?></span><p class="muted small">Of the most downloaded checked so far.</p></div>
  <div class="role-card"><span class="muted small">On this list</span><span class="n"><?= count($missing) ?></span><p class="muted small">Most downloaded first. Pick one and send a pull request.</p></div>
  <div class="role-card"><span class="muted small">Still to check</span><span class="n"><?= $waiting ?></span><p class="muted small"><?= $waiting ? 'Reload in a moment to check more.' : 'All checked. Each one is looked at again after a week.' ?></p></div>
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
    </article>
  <?php endforeach; ?>
  <?php if (!$missing): ?><p class="muted">Nothing on the list<?= $waiting ? ' yet. Reload to check more.' : '. Every popular recomp ships its own file.' ?></p><?php endif; ?>
  </div>
</section>
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
    '3. Write recomplauncher.json for Level 1 (Easy Setup). If the project has a settings file, add Level 2 too (its settings shown in the launcher).',
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
