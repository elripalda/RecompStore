<?php
// (4.3) SDK support: every recomp on RecompLauncher and whether its own GitHub ships a
// recomplauncher.json, so the team can send the developers that don't a pull request (or a nudge).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

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
    $back = trim((string)($_POST['q'] ?? ''));
    header('Location: sdk-support.php' . ($back !== '' ? '?q=' . rawurlencode($back) . ($id !== '' ? '#s-' . rawurlencode($id) : '') : ($id !== '' ? '#r-' . rawurlencode($id) : '')));
    exit;
}


// Every listed recomp with a repo, most downloaded first, in one table the team filters in the page.
// The page only reads what's been checked; the checking runs after the page has gone (a minute at a
// time), so it never keeps anyone waiting.
$apps = array_values(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && empty($a['soon']) && (string)$a['repo'] !== ''));
usort($apps, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
$marks = rs_sdk_marks();
// All the checks in one query instead of one per recomp.
$checked = [];
try {
    rs_cache_tables();
    foreach (rs_db()->query("SELECT k, v, TIMESTAMPDIFF(SECOND, at, NOW()) AS age FROM kv_cache WHERE k LIKE 'sdk:%'") as $kr) {
        $checked[substr((string)$kr['k'], 4)] = ['v' => json_decode((string)$kr['v'], true), 'age' => (int)$kr['age']];
    }
} catch (Throwable $e) { rs_errlog('sdk checks', $e); }
$rows = [];
$count = ['all' => 0, 'missing' => 0, 'ships' => 0, 'pr' => 0, 'no' => 0, 'unknown' => 0];
$waiting = 0;
$toCheck = [];
foreach ($apps as $rank => $a) {
    $c = $checked[$a['id']] ?? null;
    $r = $c && is_array($c['v']) ? $c['v'] : null;
    $fresh = $c && $c['age'] < 7 * 86400;
    if (!$r || !$fresh) $toCheck[] = $a['id'];
    $got = $r ? (string)($r['has'] ?? 'unknown') : 'waiting';
    $mk = $marks[$a['id']] ?? null;
    // A pull request that got merged and shipped: it's done, whatever the mark says.
    if (in_array($got, ['release', 'repo'], true)) $state = 'ships';
    elseif ($mk) $state = $mk['kind'] === 'pr' ? 'pr' : 'no';
    elseif ($got === 'none') $state = 'missing';
    else $state = 'unknown';
    if ($got === 'waiting' && $state === 'unknown') ++$waiting;
    $boot = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
    $now = is_array($boot) && $boot ? ((int)($boot['format'] ?? 0) === 1 && isset($boot['start']) ? 'json' : 'profile') : (!empty($a['setup_required']) ? 'steps' : 'none');
    $kind = in_array((string)$a['origin'], ['pc', 'other'], true) ? 'pc' : 'console';
    $prSt = $state === 'pr' ? rs_sdk_pr_state($a, (string)$mk['url']) : '';
    $rows[] = ['a' => $a, 'rank' => $rank + 1, 'state' => $state, 'waiting' => $got === 'waiting', 'm' => $mk, 'now' => $now, 'kind' => $kind, 'pr' => $prSt];
    ++$count['all'];
    ++$count[$state];
}
// One background run at a time: the next unchecked ones (most downloaded first), for up to a minute.
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

// Search: any listed PC recomp, not only the top of the list, so a pull request sent to a smaller
// project can be found and marked too. Unchecked ones are checked right here (a few at a time).
$q = trim((string)($_GET['q'] ?? ''));
$found = [];
$outside = null;
if (mb_strlen($q) >= 2) {
    // A GitHub link or owner/name: search by the repo it points at.
    $gm = [];
    $isRepo = (bool)preg_match('#^(?:https?://)?(?:www\.)?(?:github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?(?:[/?\#].*)?$#i', $q, $gm);
    $ql = $isRepo ? mb_strtolower($gm[1] . '/' . $gm[2]) : mb_strtolower($q);
    $all = array_values(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && (string)$a['repo'] !== ''
        && (str_contains(mb_strtolower((string)$a['name']), $ql) || str_contains(mb_strtolower((string)$a['game']), $ql)
            || str_contains(mb_strtolower((string)$a['repo']), $ql) || str_contains(mb_strtolower((string)$a['id']), $ql))));
    usort($all, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
    $live = 0;
    foreach (array_slice($all, 0, 15) as $a) {
        $c = rs_kv_get('sdk:' . $a['id']);
        $r = $c && $c['age'] < 7 * 86400 ? json_decode($c['v'], true) : null;
        if (!is_array($r) && $live < 5) { ++$live; try { $r = rs_pc_sdk_check($a); } catch (Throwable $e) { $r = null; } }
        $got = is_array($r) ? (string)($r['has'] ?? 'unknown') : 'waiting';
        $mk = $marks[$a['id']] ?? null;
        $state = in_array($got, ['release', 'repo'], true) ? 'ships' : ($mk ? ($mk['kind'] === 'pr' ? 'pr' : 'no') : ($got === 'none' ? 'missing' : 'unknown'));
        $found[] = ['a' => $a, 'state' => $state, 'm' => $mk];
    }
    // A GitHub repo that isn't on RecompLauncher at all (owner/name or a github.com link): its prompt
    // and badge still work, so a pull request can go out before it's listed.
    if ($isRepo) {
        $want = $gm[1] . '/' . $gm[2];
        $listed = false;
        foreach (rs_pc_apps() as $a) if (strcasecmp((string)$a['repo'], $want) === 0 && rs_pc_host($a) === '') { $listed = true; break; }
        if (!$listed) {
            $r = rs_http(rs_gh_api() . '/repos/' . $want, ['github' => true, 'timeout' => 8, 'max' => 2000000]);
            $j = $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
            $outside = is_array($j) ? ['repo' => (string)$j['full_name'], 'about' => (string)($j['description'] ?? ''), 'stars' => (int)($j['stargazers_count'] ?? 0)] : ['repo' => $want, 'missing' => $r['status'] === 404];
        }
    }
}

$stateLabel = ['ships' => ['Ships its own file', ' ok'], 'pr' => ['Pull request open', ''], 'no' => ['Won\'t support it', ' warn'], 'missing' => ['No file yet', ''], 'unknown' => ['Couldn\'t check yet', ' warn']];
$chips = ['all' => 'All', 'missing' => 'No file yet', 'ships' => 'Ships its own file', 'pr' => 'Pull request open', 'no' => 'Won\'t support it', 'unknown' => 'Not checked'];

rs_head('SDK support', true, ['assets/account.css']);
?>
<section class="head-row">
  <div>
    <h1>SDK support</h1>
    <p class="muted">Every recomp on RecompLauncher, and whether its own GitHub ships a <code>recomplauncher.json</code>. Filter by status, type or name, and sort by any column. Each one without a file is a pull request away from Easy Setup for everyone: open its GitHub, give your AI agent the prompt, and send the developer the result. The prompt also adds the "Get it on RecompLauncher" badge to their README and checks the project against the Recomp Standards (adding CHD and the other standard formats where it's a small change), and ends the pull request with a "For the developer" note inviting them to claim it. For the ones that already ship a file, Copy badge PR prompt adds just the badge. The ⋯ button on a row has the badge code, Check again and the marks.</p>
  </div>
  <form method="post" class="actions"><?= $csrf ?><input type="hidden" name="action" value="recheck_all"><button class="btn">Check them all again</button></form>
</section>
<div class="role-cards four">
  <div class="role-card"><span class="muted small">Ship their own file</span><span class="n"><?= number_format($count['ships']) ?></span><p class="muted small">Easy Setup straight from the developer.</p></div>
  <div class="role-card"><span class="muted small">No file yet</span><span class="n"><?= number_format($count['missing']) ?></span><p class="muted small">Pick one and send a pull request.</p></div>
  <div class="role-card"><span class="muted small">Pull requests open</span><span class="n"><?= number_format($count['pr']) ?></span><p class="muted small">Waiting on the developer.</p></div>
  <div class="role-card"><span class="muted small">Still to check</span><span class="n"><?= number_format($waiting) ?></span><p class="muted small"><?= $waiting ? 'Being checked in the background, a few hundred an hour.' : 'All checked. Each one is looked at again after a week.' ?></p></div>
</div>
<section class="card sdk-card">
  <div class="sdk-bar">
    <div class="sdk-chips" role="group" aria-label="Status">
      <?php foreach ($chips as $k => $label): ?><button type="button" class="sdk-chip<?= $k === 'all' ? ' on' : '' ?>" data-sdk-state="<?= $k ?>" aria-pressed="<?= $k === 'all' ? 'true' : 'false' ?>"><?= h($label) ?><span class="n"><?= number_format($count[$k]) ?></span></button><?php endforeach; ?>
    </div>
    <div class="sdk-tools">
      <select id="sdk-kind" aria-label="Type"><option value="">Every type</option><option value="console">Console recomps</option><option value="pc">PC games</option></select>
      <input type="search" id="sdk-find" placeholder="Filter by name, game or repo" spellcheck="false" autocomplete="off" aria-label="Filter">
    </div>
  </div>
  <p class="muted small sdk-shown" id="sdk-shown">Showing <?= number_format($count['all']) ?> of <?= number_format($count['all']) ?></p>
  <div class="sdk-scroll">
    <table class="sdk-table" id="sdk-table">
      <thead><tr>
        <th class="c-rank"><button type="button" data-sdk-sort="rank" aria-sort="ascending">#</button></th>
        <th><button type="button" data-sdk-sort="name">Recomp</button></th>
        <th class="c-num"><button type="button" data-sdk-sort="dl">Downloads</button></th>
        <th><button type="button" data-sdk-sort="state">Status</button></th>
        <th class="c-act"><span class="sr-only">Actions</span></th>
      </tr></thead>
      <?php foreach ($rows as $row): $a = $row['a']; $st = $row['state']; $mk = $row['m']; [$sl, $sc] = $stateLabel[$st];
        if ($st === 'pr' && $row['pr'] !== '') { $sl = 'Pull request ' . ($row['pr'] === 'merged' ? 'merged' : ($row['pr'] === 'closed' ? 'closed' : 'open')); $sc = $row['pr'] === 'merged' ? ' ok' : ($row['pr'] === 'closed' ? ' warn' : ''); }
        if ($st === 'unknown' && $row['waiting']) { $sl = 'Not checked yet'; $sc = ''; }
        [$nl, $nw] = $nowLabel[$row['now']];
        $gh = rs_host_web(rs_pc_host($a), (string)$a['repo']);
        $find = mb_strtolower($a['name'] . ' ' . $a['game'] . ' ' . $a['repo'] . ' ' . $a['id']); ?>
      <tbody class="sdk-item" id="r-<?= h($a['id']) ?>" data-state="<?= $st ?>" data-kind="<?= $row['kind'] ?>" data-rank="<?= (int)$row['rank'] ?>" data-dl="<?= (int)$a['downloads'] ?>" data-name="<?= h(mb_strtolower((string)$a['name'])) ?>" data-now="<?= h($row['now']) ?>" data-find="<?= h($find) ?>">
        <tr>
          <td class="c-rank"><?= (int)$row['rank'] ?></td>
          <td><div class="sdk-who"><?php if ($a['icon']): ?><img src="<?= h($a['icon']) ?>" alt="" width="32" height="32" loading="lazy"><?php else: ?><span class="sdk-ico"><?= h(mb_substr($a['name'], 0, 2)) ?></span><?php endif; ?><div class="sdk-name"><a href="pc.php?edit=<?= h(rawurlencode($a['id'])) ?>"><strong><?= h($a['name']) ?></strong></a><span class="muted small"><?= h($a['repo']) ?><?= $row['kind'] === 'pc' ? ' · PC game' : '' ?></span></div></div></td>
          <td class="c-num"><?= number_format((int)$a['downloads']) ?></td>
          <td><div class="sdk-st"><span class="pill<?= $sc ?>"><?= h($sl) ?></span><span class="muted small" title="Easy Setup now: <?= h($nw) ?>"><?= h($nl) ?></span></div></td>
          <td class="c-act"><div class="sdk-btns">
            <a class="btn" href="<?= h($gh) ?>" target="_blank" rel="noopener">GitHub ↗</a>
            <?php if ($st === 'ships'): ?><button type="button" class="btn primary" data-badgepr-repo="<?= h($a['repo']) ?>" data-badgepr-id="<?= h($a['id']) ?>" data-badgepr-game="<?= h($a['name']) ?>">Copy badge PR prompt</button>
            <?php elseif ($st === 'pr' && (string)$mk['url'] !== ''): ?><a class="btn" href="<?= h((string)$mk['url']) ?>" target="_blank" rel="noopener">Pull request ↗</a>
            <?php else: ?><button type="button" class="btn primary" data-prompt-repo="<?= h($a['repo']) ?>" data-prompt-url="<?= h($gh) ?>" data-prompt-id="<?= h($a['id']) ?>" data-prompt-game="<?= h((string)($a['game'] ?: $a['name'])) ?>">Copy agent prompt</button><?php endif; ?>
            <button type="button" class="btn sdk-more" aria-expanded="false" aria-controls="m-<?= h($a['id']) ?>" aria-label="More for <?= h($a['name']) ?>" title="Badge, Check again, marks">⋯</button>
          </div></td>
        </tr>
        <tr class="sdk-extra" id="m-<?= h($a['id']) ?>" hidden><td colspan="5"><div class="sdk-extra-in">
          <div class="sdk-btns sdk-btns-l">
            <?php if ($st !== 'ships'): ?><button type="button" class="btn" data-badge-id="<?= h($a['id']) ?>">Copy badge</button><button type="button" class="btn" data-badgepr-repo="<?= h($a['repo']) ?>" data-badgepr-id="<?= h($a['id']) ?>" data-badgepr-game="<?= h($a['name']) ?>">Copy badge PR prompt</button><?php endif; ?>
            <?php if ($st === 'pr'): ?><button type="button" class="btn" data-prompt-repo="<?= h($a['repo']) ?>" data-prompt-url="<?= h($gh) ?>" data-prompt-id="<?= h($a['id']) ?>" data-prompt-game="<?= h((string)($a['game'] ?: $a['name'])) ?>">Copy agent prompt</button><?php endif; ?>
            <form method="post"><?= $csrf ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn" title="Check its GitHub again">Check again</button></form>
            <?php if ($mk): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="unmark"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn">Clear the mark</button></form><span class="muted small">Marked by <?= h((string)$mk['who']) ?> on <?= h((string)$mk['at']) ?><?= $st === 'no' && (string)$mk['url'] !== '' ? ' · <a href="' . h((string)$mk['url']) . '" target="_blank" rel="noopener">where they said so ↗</a>' : '' ?></span><?php endif; ?>
          </div>
          <?php if (!$mk && $st !== 'ships'): ?>
          <div class="sdk-mark-forms">
            <form method="post" class="sdk-mark-form"><?= $csrf ?><input type="hidden" name="action" value="mark_pr"><input type="hidden" name="id" value="<?= h($a['id']) ?>">
              <label>A pull request is open<input name="url" required placeholder="https://github.com/<?= h($a['repo']) ?>/pull/…" spellcheck="false"></label><button class="btn">Save</button></form>
            <form method="post" class="sdk-mark-form"><?= $csrf ?><input type="hidden" name="action" value="mark_no"><input type="hidden" name="id" value="<?= h($a['id']) ?>">
              <label>The developer doesn't want it<input name="url" placeholder="Link where they said so (optional)" spellcheck="false"></label><button class="btn danger">Mark</button></form>
          </div>
          <?php endif; ?>
        </div></td></tr>
      </tbody>
      <?php endforeach; ?>
      <tbody id="sdk-none" hidden><tr><td colspan="5" class="sdk-empty"><span class="muted">Nothing matches.</span> <a class="btn" id="sdk-lookup" href="#lookup">Look it up on GitHub</a></td></tr></tbody>
    </table>
  </div>
</section>
<section class="card" id="lookup">
  <h2>Not on RecompLauncher yet?</h2>
  <p class="muted small">Paste a GitHub link or owner/name to get the prompt and badge for a recomp that isn't listed, so a pull request can go out before it's added.</p>
  <form method="get" class="sdk-search" action="sdk-support.php#lookup">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="github.com/owner/name, or owner/name" spellcheck="false" autocomplete="off">
    <button class="btn primary">Look up</button><?php if ($q !== ''): ?> <a class="btn" href="sdk-support.php">Clear</a><?php endif; ?>
  </form>
  <?php if ($q !== ''): ?>
  <div class="sdk-list" style="margin-top:12px">
  <?php foreach ($found as $f): $a = $f['a']; [$sl, $sc] = $stateLabel[$f['state']]; ?>
    <article class="sdk-row-s">
      <div class="sdk-name"><strong><?= h($a['name']) ?></strong><span class="muted small"><?= h($a['repo']) ?> · <?= number_format((int)$a['downloads']) ?> downloads</span></div>
      <span class="pill<?= $sc ?>"><?= h($sl) ?></span>
      <div class="sdk-btns"><a class="btn" href="#r-<?= h($a['id']) ?>" data-sdk-jump="<?= h($a['id']) ?>">Show it in the table</a></div>
    </article>
  <?php endforeach; ?>
  <?php if ($outside): ?>
    <article class="sdk-row-s">
      <div class="sdk-name"><strong><?= h($outside['repo']) ?></strong><span class="muted small"><?= !empty($outside['missing']) ? 'GitHub has no repo by that name.' : 'Not on RecompLauncher yet' . ($outside['about'] !== '' ? ' · ' . h(mb_strimwidth($outside['about'], 0, 90, '…')) : '') ?></span></div>
      <span class="pill<?= !empty($outside['missing']) ? ' warn' : '' ?>"><?= !empty($outside['missing']) ? 'Not found' : 'Not listed' ?></span>
      <div class="sdk-btns">
        <?php if (empty($outside['missing'])): ?>
        <a class="btn" href="https://github.com/<?= h($outside['repo']) ?>" target="_blank" rel="noopener">GitHub ↗</a>
        <button type="button" class="btn primary" data-prompt-repo="<?= h($outside['repo']) ?>" data-prompt-id="">Copy agent prompt</button> <button type="button" class="btn" data-badge-id="">Copy badge</button>
        <?php if (rs_role_at_least('curator')): ?><a class="btn" href="pc.php#found">Add it to the store</a><?php endif; ?>
        <?php endif; ?>
      </div>
    </article>
  <?php endif; ?>
  <?php if (!$found && !$outside): ?><p class="muted">Nothing matches "<?= h($q) ?>". Paste its GitHub link or owner/name.</p><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<style>
.sdk-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:0 0 10px}
.sdk-chips{display:flex;flex-wrap:wrap;gap:8px}
.sdk-chip{display:inline-flex;align-items:center;height:34px;padding:0 14px;border-radius:999px;border:1px solid rgba(var(--ink-rgb),.16);background:rgba(var(--ink-rgb),.06);color:var(--frost);font:600 14px Figtree,sans-serif;cursor:pointer}
.sdk-chip .n{opacity:.7;margin-left:6px;font-weight:700}
.sdk-chip.on{background:var(--cyan);border-color:var(--cyan);color:var(--on-accent)}
.sdk-chip:focus-visible{outline:2px solid var(--cyan);outline-offset:2px}
.sdk-tools{display:flex;gap:8px;align-items:center}
.sdk-tools select,.sdk-tools input{height:40px;padding:0 12px;margin:0;box-sizing:border-box;font-size:14px;line-height:normal}
.sdk-tools select{min-width:160px}
.sdk-tools input{min-width:260px}
.sdk-shown{margin:0 0 8px}
.sdk-scroll{max-height:min(1360px,calc(100vh - 150px));overflow:auto;border:1px solid var(--line);border-radius:14px;overscroll-behavior:contain}
.sdk-table{width:100%;border-collapse:separate;border-spacing:0;font-size:14.5px}
.sdk-table thead th{position:sticky;top:0;z-index:2;background:var(--surface);text-align:left;padding:0;border-bottom:1px solid var(--line);white-space:nowrap}
.sdk-table thead th button,.sdk-table thead th>span{display:block;width:100%;padding:10px 12px;background:none;border:0;color:var(--muted);font:600 13.5px Figtree,sans-serif;text-align:inherit;cursor:pointer}
.sdk-table thead th button:hover{color:var(--frost)}
.sdk-table thead th button[aria-sort]::after{content:" ↓";color:var(--cyan-ink)}
.sdk-table thead th button[aria-sort=ascending]::after{content:" ↑"}
.sdk-table td{padding:6px 12px;border-bottom:1px solid rgba(var(--ink-rgb),.06);vertical-align:middle}
.sdk-item:hover>tr:first-child td{background:rgba(var(--ink-rgb),.03)}
.sdk-table .c-rank{width:40px;color:var(--muted);font-weight:700;font-variant-numeric:tabular-nums}
.sdk-table .c-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.sdk-table thead .c-num button{text-align:right}
.sdk-st{display:flex;flex-direction:column;align-items:flex-start;gap:3px}
.sdk-st .pill{white-space:nowrap}
.sdk-st .small{white-space:nowrap;font-size:12.5px}
.sdk-more{min-width:38px;font-size:16px!important;letter-spacing:1px}
.sdk-table .c-act{width:1%;white-space:nowrap}
.sdk-table .c-act .sdk-btns{flex-wrap:nowrap}
.sdk-who{display:flex;gap:10px;align-items:center;min-width:0}
.sdk-who img,.sdk-ico{width:32px;height:32px;border-radius:9px;object-fit:cover;flex:none}
.sdk-ico{display:grid;place-items:center;background:rgba(var(--ink-rgb),.08);font:700 12px Sora,sans-serif}
.sdk-name{display:flex;flex-direction:column;min-width:0}
.sdk-name a{color:var(--frost);text-decoration:none}
.sdk-name span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:300px}
.sdk-btns{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.sdk-btns-l{justify-content:flex-start}
.sdk-btns form{margin:0}
.sdk-btns .btn{min-height:32px;padding:5px 11px;font-size:13.5px}
.sdk-more[aria-expanded=true]{background:rgba(var(--ink-rgb),.12)}
.sdk-extra td{background:rgba(var(--ink-rgb),.035)}
.sdk-extra-in{display:flex;flex-direction:column;gap:10px;padding:6px 0}
.sdk-empty{text-align:center;padding:24px 12px!important}
.sdk-mark-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.sdk-mark-form{display:flex;gap:8px;align-items:flex-end}
.sdk-mark-form label{flex:1;margin:0;font-size:14px}
.sdk-search{display:flex;gap:8px;align-items:center}
.sdk-search input{flex:1;min-width:0}
.sdk-list{display:flex;flex-direction:column;gap:8px}
.sdk-row-s{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:14px;align-items:center;padding:10px 12px;border:1px solid var(--line);border-radius:14px}
.sdk-flash td{animation:sdkflash 1.6s ease-out}
@keyframes sdkflash{from{background:rgba(52,242,232,.22)}to{background:transparent}}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
@media (max-width:860px){.sdk-tools{width:100%}.sdk-tools input{min-width:0;flex:1}.sdk-table .c-rank,.sdk-table .c-num{display:none}.sdk-mark-forms{grid-template-columns:minmax(0,1fr)}.sdk-row-s{grid-template-columns:minmax(0,1fr)}.sdk-name span{max-width:200px}}
</style>
<script>
(function () {
  var table = document.getElementById('sdk-table');
  if (!table) return;
  var items = Array.prototype.slice.call(table.querySelectorAll('tbody.sdk-item'));
  var none = document.getElementById('sdk-none');
  var shown = document.getElementById('sdk-shown');
  var find = document.getElementById('sdk-find');
  var kind = document.getElementById('sdk-kind');
  var lookup = document.getElementById('sdk-lookup');
  var order = {missing: 0, unknown: 1, pr: 2, no: 3, ships: 4};
  var nowOrder = {none: 0, steps: 1, profile: 2, json: 3};
  var st = {state: 'all', kind: '', q: '', sort: 'rank', dir: 1};
  try { var saved = JSON.parse(sessionStorage.getItem('sdk-view') || 'null'); if (saved) { st.state = saved.state || 'all'; st.kind = saved.kind || ''; st.sort = saved.sort || 'rank'; st.dir = saved.dir || 1; } } catch (e) {}
  function save() { try { sessionStorage.setItem('sdk-view', JSON.stringify({state: st.state, kind: st.kind, sort: st.sort, dir: st.dir})); } catch (e) {} }
  function apply() {
    var n = 0, q = st.q.toLowerCase().trim();
    // A pasted GitHub link filters by its owner/name.
    var m = q.match(/^(?:https?:\/\/)?(?:www\.)?github\.com\/([\w.-]+\/[\w.-]+)/);
    if (m) q = m[1].replace(/\.git$/, '');
    items.forEach(function (it) {
      var ok = (st.state === 'all' || it.dataset.state === st.state) && (!st.kind || it.dataset.kind === st.kind) && (!q || it.dataset.find.indexOf(q) !== -1);
      it.hidden = !ok;
      if (ok) n++;
    });
    none.hidden = n > 0;
    if (lookup) { lookup.hidden = !st.q.trim(); lookup.href = 'sdk-support.php?q=' + encodeURIComponent(st.q.trim()) + '#lookup'; }
    shown.textContent = 'Showing ' + n.toLocaleString() + ' of ' + items.length.toLocaleString();
    document.querySelectorAll('[data-sdk-state]').forEach(function (b) { var on = b.dataset.sdkState === st.state; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    kind.value = st.kind;
  }
  function key(it) {
    if (st.sort === 'name') return it.dataset.name;
    if (st.sort === 'dl') return -Number(it.dataset.dl);
    if (st.sort === 'state') return order[it.dataset.state] * 1e6 + Number(it.dataset.rank);
    if (st.sort === 'now') return nowOrder[it.dataset.now] * 1e6 + Number(it.dataset.rank);
    return Number(it.dataset.rank);
  }
  function sort() {
    var list = items.slice().sort(function (a, b) { var x = key(a), y = key(b); return (x < y ? -1 : x > y ? 1 : 0) * st.dir; });
    list.forEach(function (it) { table.insertBefore(it, none); });
    table.querySelectorAll('[data-sdk-sort]').forEach(function (b) {
      if (b.dataset.sdkSort === st.sort) b.setAttribute('aria-sort', st.dir === 1 ? 'ascending' : 'descending'); else b.removeAttribute('aria-sort');
    });
  }
  document.querySelectorAll('[data-sdk-state]').forEach(function (b) { b.addEventListener('click', function () { st.state = b.dataset.sdkState; save(); apply(); }); });
  kind.addEventListener('change', function () { st.kind = kind.value; save(); apply(); });
  find.addEventListener('input', function () { st.q = find.value; apply(); });
  find.addEventListener('keydown', function (e) { if (e.key === 'Escape' && find.value) { e.preventDefault(); find.value = ''; st.q = ''; apply(); } });
  table.querySelectorAll('[data-sdk-sort]').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.dataset.sdkSort;
      if (st.sort === k) st.dir = -st.dir; else { st.sort = k; st.dir = 1; }
      save(); sort();
    });
  });
  table.addEventListener('click', function (e) {
    var b = e.target.closest('.sdk-more');
    if (!b) return;
    var row = document.getElementById(b.getAttribute('aria-controls'));
    var open = b.getAttribute('aria-expanded') !== 'true';
    b.setAttribute('aria-expanded', open ? 'true' : 'false');
    row.hidden = !open;
  });
  // Show one row: clear the filters that hide it, open its More, scroll it into view.
  function jump(id) {
    var it = document.getElementById('r-' + id);
    if (!it) return;
    if (it.hidden) { st.state = 'all'; st.kind = ''; st.q = ''; find.value = ''; apply(); }
    var more = it.querySelector('.sdk-more'), row = it.querySelector('.sdk-extra');
    if (more && row) { more.setAttribute('aria-expanded', 'true'); row.hidden = false; }
    it.scrollIntoView({block: 'center'});
    it.classList.add('sdk-flash');
    setTimeout(function () { it.classList.remove('sdk-flash'); }, 1700);
  }
  document.addEventListener('click', function (e) { var a = e.target.closest('[data-sdk-jump]'); if (a) { e.preventDefault(); jump(a.dataset.sdkJump); } });
  sort();
  apply();
  // Back from a Check again or a mark: keep that row in view.
  if (location.hash.indexOf('#r-') === 0) jump(decodeURIComponent(location.hash.slice(3)));
})();
</script>
<?php rs_foot(['assets/sdk-prompt.js']);
