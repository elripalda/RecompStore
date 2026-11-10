<?php
// (4.3) SDK support: every recomp on RecompLauncher and whether its own GitHub ships a
// recomplauncher.json, so the team can send the developers that don't a pull request (or a nudge).
// (4.6) Xarishark: one colored line instead of the four boxes, red/green status, a star for the
// README badge, a live progress while checking, and Check again / marks without reloading the page.
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

// Every listed recomp with a repo, most downloaded first.
function rs_sdk_apps(): array {
    $apps = array_values(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && empty($a['soon']) && (string)$a['repo'] !== ''));
    usort($apps, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
    return $apps;
}
// All the checks in one query instead of one per recomp. [id => [v, age, t]]
function rs_sdk_checks(): array {
    $out = [];
    try {
        rs_cache_tables();
        foreach (rs_db()->query("SELECT k, v, TIMESTAMPDIFF(SECOND, at, NOW()) AS age, UNIX_TIMESTAMP(at) AS t FROM kv_cache WHERE k LIKE 'sdk:%'") as $kr)
            $out[substr((string)$kr['k'], 4)] = ['v' => json_decode((string)$kr['v'], true), 'age' => (int)$kr['age'], 't' => (int)$kr['t']];
    } catch (Throwable $e) { rs_errlog('sdk checks', $e); }
    return $out;
}
// One recomp's row: its status, the README badge, what Easy Setup runs on today, and whether it's
// due a check (never checked, older than a week, or checked before the badge was looked at).
function rs_sdk_row(array $a, int $rank, ?array $c, array $marks): array {
    $r = $c && is_array($c['v']) ? $c['v'] : null;
    $got = $r ? (string)($r['has'] ?? 'unknown') : 'waiting';
    $mk = $marks[$a['id']] ?? null;
    // A pull request that got merged and shipped: it's done, whatever the mark says.
    if (in_array($got, ['release', 'repo'], true)) $state = 'ships';
    elseif ($mk) $state = $mk['kind'] === 'pr' ? 'pr' : 'no';
    elseif ($got === 'none') $state = 'missing';
    else $state = 'unknown';
    $boot = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
    $now = is_array($boot) && $boot ? ((int)($boot['format'] ?? 0) === 1 && isset($boot['start']) ? 'json' : 'profile') : (!empty($a['setup_required']) ? 'steps' : 'none');
    return ['a' => $a, 'rank' => $rank, 'state' => $state, 'waiting' => $got === 'waiting', 'm' => $mk, 'now' => $now,
        'kind' => in_array((string)$a['origin'], ['pc', 'other'], true) ? 'pc' : 'console',
        'pr' => $state === 'pr' ? rs_sdk_pr_state($a, (string)$mk['url']) : '',
        'badge' => $r && array_key_exists('badge', $r) ? (bool)$r['badge'] : null,
        'dlss' => !empty($r['dlss']),
        'due' => !$r || $c['age'] >= 7 * 86400 || !array_key_exists('badge', $r)];
}
// Every row, the counts per status, and the ones due a check (most downloaded first).
function rs_sdk_all(): array {
    $apps = rs_sdk_apps();
    $checked = rs_sdk_checks();
    $marks = rs_sdk_marks();
    $rows = [];
    $count = ['all' => 0, 'missing' => 0, 'ships' => 0, 'pr' => 0, 'no' => 0, 'unknown' => 0, 'badge' => 0, 'todo' => 0];
    $due = [];
    foreach ($apps as $i => $a) {
        $row = rs_sdk_row($a, $i + 1, $checked[$a['id']] ?? null, $marks);
        $row['t'] = (int)($checked[$a['id']]['t'] ?? 0);
        $rows[$a['id']] = $row;
        ++$count['all'];
        ++$count[$row['state']];
        if ($row['badge']) ++$count['badge'];
        if ($row['due']) { ++$count['todo']; $due[] = $a['id']; }
    }
    return [$rows, $count, $due];
}
// One background run at a time, after the answer has gone: the due ones for up to a minute. The
// half-hourly job does the same; this only speeds it up while someone has the page open.
function rs_sdk_kick(array $due): bool {
    if (!$due) return false;
    $run = rs_kv_get('sdk_scan_run');
    if ($run && $run['age'] <= 150) return true;
    rs_kv_put('sdk_scan_run', (string)time());
    register_shutdown_function(function () use ($due) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        @set_time_limit(150);
        $t0 = microtime(true);
        foreach ($due as $id) {
            if (microtime(true) - $t0 > 60) break;
            if (($a = rs_pc_app($id)) && rs_pc_sdk_due($a)) { try { rs_pc_sdk_check($a, true); } catch (Throwable $e) { /* next run */ } }
        }
        rs_db()->exec("DELETE FROM kv_cache WHERE k = 'sdk_scan_run'");
    });
    return true;
}

const RS_SDK_NOW = [
    'json' => ['Its file, in our catalog', 'The developer\'s recomplauncher.json is pasted into the catalog. Shipping it in the release keeps it up to date.'],
    'profile' => ['Our setup profile', 'Easy Setup works from a profile we wrote. The developer\'s own file would be better.'],
    'steps' => ['Setup steps only', 'Players follow written steps before the first play.'],
    'none' => ['Nothing yet', 'Players set it up by hand.'],
];
const RS_SDK_STATE = ['ships' => ['Ships its own file', ' ok'], 'pr' => ['Pull request open', ' blue'], 'no' => ['Won\'t support it', ' warn'], 'missing' => ['No file yet', ' bad'], 'unknown' => ['Couldn\'t check yet', '']];

// One recomp's table row (a <tbody>: the row and its ⋯ panel), for the page and for the live updates.
function rs_sdk_row_html(array $row, string $csrf): string {
    $a = $row['a']; $st = $row['state']; $mk = $row['m']; [$sl, $sc] = RS_SDK_STATE[$st];
    if ($st === 'pr' && $row['pr'] !== '') { $sl = 'Pull request ' . ($row['pr'] === 'merged' ? 'merged' : ($row['pr'] === 'closed' ? 'closed' : 'open')); $sc = $row['pr'] === 'merged' ? ' ok' : ($row['pr'] === 'closed' ? ' warn' : ' blue'); }
    if ($st === 'unknown' && $row['waiting']) $sl = 'Not checked yet';
    [$nl, $nw] = RS_SDK_NOW[$row['now']];
    $gh = rs_host_web(rs_pc_host($a), (string)$a['repo']);
    $find = mb_strtolower($a['name'] . ' ' . $a['game'] . ' ' . $a['repo'] . ' ' . $a['id']);
    $id = h($a['id']);
    $promptBtn = fn(string $cls) => '<button type="button" class="btn' . $cls . '" data-prompt-repo="' . h($a['repo']) . '" data-prompt-url="' . h($gh) . '" data-prompt-id="' . $id . '" data-prompt-game="' . h((string)($a['game'] ?: $a['name'])) . '">Copy agent prompt</button>';
    $badgePrBtn = fn(string $cls) => '<button type="button" class="btn' . $cls . '" data-badgepr-repo="' . h($a['repo']) . '" data-badgepr-id="' . $id . '" data-badgepr-game="' . h($a['name']) . '">Copy badge PR prompt</button>';
    $form = fn(string $action, string $label) => '<form method="post" data-sdk-ajax>' . $csrf . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn">' . $label . '</button></form>';
    ob_start(); ?>
      <tbody class="sdk-item" id="r-<?= $id ?>" data-id="<?= $id ?>" data-state="<?= $st ?>" data-kind="<?= $row['kind'] ?>" data-rank="<?= (int)$row['rank'] ?>" data-dl="<?= (int)$a['downloads'] ?>" data-name="<?= h(mb_strtolower((string)$a['name'])) ?>" data-badge="<?= $row['badge'] ? '1' : '0' ?>" data-find="<?= h($find) ?>">
        <tr>
          <td class="c-rank"><?= (int)$row['rank'] ?></td>
          <td><div class="sdk-who"><?php if ($a['icon']): ?><img src="<?= h($a['icon']) ?>" alt="" width="32" height="32" loading="lazy"><?php else: ?><span class="sdk-ico"><?= h(mb_substr($a['name'], 0, 2)) ?></span><?php endif; ?><div class="sdk-name"><span class="sdk-title"><a href="pc.php?edit=<?= h(rawurlencode($a['id'])) ?>"><strong><?= h($a['name']) ?></strong></a><?php if ($row['badge']): ?><span class="sdk-star" title="Its README has the Get it on RecompLauncher badge" aria-label="Badge in README">★</span><?php endif; ?></span><span class="muted small"><?= h($a['repo']) ?><?= $row['kind'] === 'pc' ? ' · PC game' : '' ?></span></div></div></td>
          <td class="c-num"><?= number_format((int)$a['downloads']) ?></td>
          <td><div class="sdk-st"><span class="pill<?= $sc ?>"><?= h($sl) ?></span><span class="muted small" title="Easy Setup now: <?= h($nw) ?>"><?= h($nl) ?><?= $row['dlss'] ? ' · gets DLSS from the launcher' : '' ?></span></div></td>
          <td class="c-act"><div class="sdk-btns">
            <a class="btn" href="<?= h($gh) ?>" target="_blank" rel="noopener">GitHub ↗</a>
            <?php if ($st === 'ships'): ?><?= $row['badge'] ? '' : $badgePrBtn(' primary') ?>
            <?php elseif ($st === 'pr' && (string)$mk['url'] !== ''): ?><a class="btn" href="<?= h((string)$mk['url']) ?>" target="_blank" rel="noopener">Pull request ↗</a>
            <?php else: ?><?= $promptBtn(' primary') ?><?php endif; ?>
            <button type="button" class="btn sdk-more" aria-expanded="false" aria-controls="m-<?= $id ?>" aria-label="More for <?= h($a['name']) ?>" title="Badge, Check again, marks">⋯</button>
          </div></td>
        </tr>
        <tr class="sdk-extra" id="m-<?= $id ?>" hidden><td colspan="5"><div class="sdk-extra-in">
          <div class="sdk-btns sdk-btns-l">
            <?php if ($st !== 'ships'): ?><button type="button" class="btn" data-badge-id="<?= $id ?>">Copy badge</button><?= $row['badge'] ? '' : $badgePrBtn('') ?><?php endif; ?>
            <?php if ($st === 'pr'): ?><?= $promptBtn('') ?><?php endif; ?>
            <?= $form('recheck', 'Check again') ?>
            <?php if ($mk): ?><?= $form('unmark', 'Clear the mark') ?><span class="muted small">Marked by <?= h((string)$mk['who']) ?> on <?= h((string)$mk['at']) ?><?= $st === 'no' && (string)$mk['url'] !== '' ? ' · <a href="' . h((string)$mk['url']) . '" target="_blank" rel="noopener">where they said so ↗</a>' : '' ?></span><?php endif; ?>
          </div>
          <?php if (!$mk && $st !== 'ships'): ?>
          <div class="sdk-mark-forms">
            <form method="post" class="sdk-mark-form" data-sdk-ajax><?= $csrf ?><input type="hidden" name="action" value="mark_pr"><input type="hidden" name="id" value="<?= $id ?>">
              <label>A pull request is open<input name="url" required placeholder="https://github.com/<?= h($a['repo']) ?>/pull/…" spellcheck="false"></label><button class="btn">Save</button></form>
            <form method="post" class="sdk-mark-form" data-sdk-ajax><?= $csrf ?><input type="hidden" name="action" value="mark_no"><input type="hidden" name="id" value="<?= $id ?>">
              <label>The developer doesn't want it<input name="url" placeholder="Link where they said so (optional)" spellcheck="false"></label><button class="btn danger">Mark</button></form>
          </div>
          <?php endif; ?>
        </div></td></tr>
      </tbody>
<?php
    return (string)ob_get_clean();
}
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$ajax = ($_POST['ajax'] ?? '') === '1';
function rs_sdk_json(array $out): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function rs_sdk_db_now(): int { return (int)rs_db()->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn(); }

// Live progress while checking: the counts, and the rows checked since the page last asked.
if (isset($_GET['progress'])) {
    [$rows, $count, $due] = rs_sdk_all();
    $since = (int)($_GET['since'] ?? 0);
    $changed = [];
    if ($since > 0) foreach ($rows as $id => $row) if ($row['t'] >= $since && count($changed) < 150) $changed[$id] = rs_sdk_row_html($row, $csrf);
    $running = rs_sdk_kick($due);
    rs_sdk_json(['ok' => true, 'count' => $count, 'running' => $running, 'rows' => $changed, 'now' => rs_sdk_db_now()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $id = (string)($_POST['id'] ?? '');
    $msg = ''; $kind = 'ok';
    if (($_POST['action'] ?? '') === 'recheck' && ($a = rs_pc_app($id))) {
        $r = rs_pc_sdk_check($a, true);
        $msg = $a['name'] . ($r['has'] === 'release' || $r['has'] === 'repo' ? ' ships its own recomplauncher.json.' : ($r['has'] === 'none' ? ' doesn\'t ship one yet.' : ': GitHub didn\'t answer. Try again in a minute.'))
            . (array_key_exists('badge', $r) && $r['badge'] !== null ? ($r['badge'] ? ' Its README has the badge.' : ' No badge in its README.') : '');
    } elseif (in_array($_POST['action'] ?? '', ['mark_pr', 'mark_no'], true) && ($a = rs_pc_app($id))) {
        $pr = $_POST['action'] === 'mark_pr';
        $url = trim((string)($_POST['url'] ?? ''));
        [$link, $why] = $url === '' && !$pr ? ['', ''] : rs_sdk_link($a, $url, $pr);
        if ($link === null) { $msg = $why; $kind = 'err'; }
        else {
            $m = rs_sdk_marks();
            $m[$id] = ['kind' => $pr ? 'pr' : 'no', 'url' => $link, 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200), 'who' => rs_me_name(), 'at' => gmdate('Y-m-d')];
            rs_sdk_marks_save($m);
            rs_log('sdk', $id, $pr ? 'pull request: ' . $link : 'won\'t support' . ($link ? ': ' . $link : ''));
            $msg = $pr ? $a['name'] . ' marked: pull request open.' : $a['name'] . ' marked: won\'t support it.';
        }
    } elseif (($_POST['action'] ?? '') === 'unmark' && ($a = rs_pc_app($id))) {
        $m = rs_sdk_marks();
        unset($m[$id]);
        rs_sdk_marks_save($m);
        rs_log('sdk', $id, 'back on the list');
        $msg = $a['name'] . '\'s mark is cleared.';
    } elseif (($_POST['action'] ?? '') === 'recheck_all') {
        // Everything becomes due, but keeps what it showed until its new check is in.
        rs_cache_tables();
        rs_db()->exec("UPDATE kv_cache SET at = at - INTERVAL 8 DAY WHERE k LIKE 'sdk:%'");
        $msg = 'Checking everything again. The progress shows next to the button.';
        $id = '';
    }
    if ($ajax) {
        [$rows, $count, $due] = rs_sdk_all();
        $running = rs_sdk_kick($due);
        rs_sdk_json(['ok' => $kind === 'ok', 'msg' => $msg, 'id' => $id, 'row' => $id !== '' && isset($rows[$id]) ? rs_sdk_row_html($rows[$id], $csrf) : '', 'count' => $count, 'running' => $running, 'now' => rs_sdk_db_now()]);
    }
    if ($msg !== '') rs_flash($msg, $kind);
    header('Location: sdk-support.php' . ($id !== '' ? '#r-' . rawurlencode($id) : ''));
    exit;
}

[$rows, $count, $due] = rs_sdk_all();
rs_sdk_kick($due);
$pageNow = rs_sdk_db_now();

// Look up a GitHub repo that isn't on RecompLauncher (owner/name or a github.com link): its prompt
// and badge still work, so a pull request can go out before it's listed. Listed ones point at the table.
$q = trim((string)($_GET['q'] ?? ''));
$found = [];
$outside = null;
if (mb_strlen($q) >= 2) {
    $gm = [];
    $isRepo = (bool)preg_match('#^(?:https?://)?(?:www\.)?(?:github\.com/)?([\w.-]+)/([\w.-]+?)(?:\.git)?(?:[/?\#].*)?$#i', $q, $gm);
    $ql = $isRepo ? mb_strtolower($gm[1] . '/' . $gm[2]) : mb_strtolower($q);
    foreach ($rows as $row) {
        $a = $row['a'];
        if (str_contains(mb_strtolower((string)$a['name']), $ql) || str_contains(mb_strtolower((string)$a['game']), $ql) || str_contains(mb_strtolower((string)$a['repo']), $ql) || str_contains(mb_strtolower((string)$a['id']), $ql)) $found[] = $row;
        if (count($found) >= 15) break;
    }
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
$chips = ['all' => 'All', 'missing' => 'No file yet', 'ships' => 'Ships its own file', 'pr' => 'Pull request open', 'no' => 'Won\'t support it', 'unknown' => 'Not checked', 'badge' => 'Badge in README'];
$meter = ['ships' => 'ship their own file', 'missing' => 'no file yet', 'pr' => 'pull request open', 'no' => 'won\'t support it', 'unknown' => 'not checked'];

rs_head('SDK support', true, ['assets/account.css']);
?>
<section class="head-row">
  <div>
    <h1>SDK support</h1>
    <p class="muted">Every recomp on RecompLauncher, and whether its own GitHub ships a <code>recomplauncher.json</code>. Red ones don't yet, green ones do, and ★ means its README has the "Get it on RecompLauncher" badge. Each red one is a pull request away from Easy Setup for everyone: open its GitHub, give your AI agent the prompt, and send the developer the result. The prompt also adds the badge and checks the project against the Recomp Standards (adding CHD and the other standard formats where it's a small change), and ends the pull request with a "For the developer" note inviting them to claim it. For green ones without the ★, Copy badge PR prompt adds just the badge. The ⋯ button on a row has the badge code, Check again and the marks.</p>
  </div>
  <div class="sdk-head-act">
    <span class="sdk-prog" id="sdk-prog" aria-live="polite"<?= $count['todo'] ? '' : ' hidden' ?>><span class="sdk-spin" aria-hidden="true"></span><span id="sdk-prog-t">Checking: <?= number_format($count['all'] - $count['todo']) ?> of <?= number_format($count['all']) ?> done</span></span>
    <form method="post" class="actions" data-sdk-ajax id="sdk-all"><?= $csrf ?><input type="hidden" name="action" value="recheck_all"><button class="btn">Check them all again</button></form>
  </div>
</section>
<section class="card sdk-card">
  <div class="sdk-meter" id="sdk-meter" role="img" aria-label="<?php foreach ($meter as $k => $l) echo number_format($count[$k]) . ' ' . $l . '. '; ?>">
    <?php foreach ($meter as $k => $l): ?><span class="m-<?= $k ?>" data-m="<?= $k ?>" data-l="<?= h($l) ?>" style="flex-grow:<?= $count[$k] ?>" title="<?= number_format($count[$k]) . ' ' . h($l) ?>"></span><?php endforeach; ?>
  </div>
  <div class="sdk-bar">
    <div class="sdk-chips" role="group" aria-label="Status">
      <?php foreach ($chips as $k => $label): ?><button type="button" class="sdk-chip<?= $k === 'all' ? ' on' : '' ?>" data-sdk-state="<?= $k ?>" aria-pressed="<?= $k === 'all' ? 'true' : 'false' ?>"><?php if ($k === 'badge'): ?><span class="sdk-star" aria-hidden="true">★</span><?php elseif ($k !== 'all'): ?><i class="dot m-<?= $k ?>" aria-hidden="true"></i><?php endif; ?><?= h($label) ?><span class="n" data-n="<?= $k ?>"><?= number_format($count[$k]) ?></span></button><?php endforeach; ?>
    </div>
    <div class="sdk-tools">
      <select id="sdk-kind" aria-label="Type"><option value="">Every type</option><option value="console">Console recomps</option><option value="pc">PC games</option></select>
      <input type="search" id="sdk-find" placeholder="Filter by name, game or repo" spellcheck="false" autocomplete="off" aria-label="Filter">
    </div>
  </div>
  <p class="muted small sdk-shown" id="sdk-shown">Showing <?= number_format($count['all']) ?> of <?= number_format($count['all']) ?></p>
  <div class="sdk-scroll">
    <table class="sdk-table" id="sdk-table" data-now="<?= $pageNow ?>" data-todo="<?= $count['todo'] ?>">
      <thead><tr>
        <th class="c-rank"><button type="button" data-sdk-sort="rank" aria-sort="ascending">#</button></th>
        <th><button type="button" data-sdk-sort="name">Recomp</button></th>
        <th class="c-num"><button type="button" data-sdk-sort="dl">Downloads</button></th>
        <th><button type="button" data-sdk-sort="state">Status</button></th>
        <th class="c-act"><span class="sr-only">Actions</span></th>
      </tr></thead>
      <?php foreach ($rows as $row) echo rs_sdk_row_html($row, $csrf); ?>
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
  <?php foreach ($found as $f): $a = $f['a']; [$sl, $sc] = RS_SDK_STATE[$f['state']]; ?>
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
<div class="sdk-toast" id="sdk-toast" role="status" aria-live="polite" hidden></div>
<style>
.sdk-head-act{display:flex;gap:12px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
.sdk-head-act form{margin:0}
.sdk-prog{display:inline-flex;align-items:center;gap:8px;color:var(--muted);font:600 14px Figtree,sans-serif;font-variant-numeric:tabular-nums;white-space:nowrap}
.sdk-prog[hidden]{display:none}
.sdk-spin{width:14px;height:14px;border-radius:50%;border:2px solid rgba(var(--ink-rgb),.2);border-top-color:var(--cyan-ink);animation:sdkspin .8s linear infinite}
@keyframes sdkspin{to{transform:rotate(360deg)}}
.sdk-meter{display:flex;height:10px;border-radius:999px;overflow:hidden;background:rgba(var(--ink-rgb),.08);margin:0 0 14px;gap:2px}
.sdk-meter span{flex-basis:0;min-width:0;transition:flex-grow .6s ease}
.m-ships{background:var(--ok)}.m-missing{background:var(--bad)}.m-pr{background:var(--blue)}.m-no{background:var(--warn)}.m-unknown{background:rgba(var(--ink-rgb),.28)}
.sdk-bar{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between;margin:0 0 10px}
.sdk-chips{display:flex;flex-wrap:wrap;gap:8px}
.sdk-chip{display:inline-flex;align-items:center;gap:7px;height:34px;padding:0 14px;border-radius:999px;border:1px solid rgba(var(--ink-rgb),.16);background:rgba(var(--ink-rgb),.06);color:var(--frost);font:600 14px Figtree,sans-serif;cursor:pointer}
.sdk-chip .n{opacity:.7;font-weight:700}
.sdk-chip .dot{width:9px;height:9px;border-radius:50%;flex:none}
.sdk-chip.on{background:var(--cyan);border-color:var(--cyan);color:var(--on-accent)}
.sdk-chip.on .sdk-star{color:var(--on-accent)}
.sdk-chip:focus-visible{outline:2px solid var(--cyan);outline-offset:2px}
.sdk-star{color:#f5b301;font-size:15px;line-height:1}
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
.sdk-table .c-act{width:1%;white-space:nowrap}
.sdk-table .c-act .sdk-btns{flex-wrap:nowrap}
.sdk-who{display:flex;gap:10px;align-items:center;min-width:0}
.sdk-who img,.sdk-ico{width:32px;height:32px;border-radius:9px;object-fit:cover;flex:none}
.sdk-ico{display:grid;place-items:center;background:rgba(var(--ink-rgb),.08);font:700 12px Sora,sans-serif}
.sdk-name{display:flex;flex-direction:column;min-width:0}
.sdk-title{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:300px}
.sdk-title .sdk-star{margin-left:6px;vertical-align:1px}
.sdk-name a{color:var(--frost);text-decoration:none}
.sdk-name>.small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:300px}
.sdk-st{display:flex;flex-direction:column;align-items:flex-start;gap:3px}
.sdk-st .pill{white-space:nowrap;margin-left:0}
.sdk-st .small{white-space:nowrap;font-size:12.5px}
.pill.bad{background:rgba(255,138,128,.16);color:var(--bad)}
.pill.blue{background:rgba(143,193,255,.16);color:var(--blue)}
.sdk-btns{display:flex;gap:6px;align-items:center;justify-content:flex-end;flex-wrap:wrap}
.sdk-btns-l{justify-content:flex-start}
.sdk-btns form{margin:0}
.sdk-btns .btn{min-height:32px;padding:5px 11px;font-size:13.5px}
.sdk-more{min-width:38px;font-size:16px!important;letter-spacing:1px}
.sdk-more[aria-expanded=true]{background:rgba(var(--ink-rgb),.12)}
.sdk-extra td{background:rgba(var(--ink-rgb),.035)}
.sdk-extra-in{display:flex;flex-direction:column;gap:10px;padding:6px 0}
.sdk-empty{text-align:center;padding:24px 12px!important}
.sdk-mark-forms{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
.sdk-mark-form{display:flex;gap:8px;align-items:flex-end}
.sdk-mark-form label{flex:1;margin:0;font-size:14px}
.btn.sdk-busy{opacity:.6;pointer-events:none}
.sdk-search{display:flex;gap:8px;align-items:center}
.sdk-search input{flex:1;min-width:0}
.sdk-list{display:flex;flex-direction:column;gap:8px}
.sdk-row-s{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:14px;align-items:center;padding:10px 12px;border:1px solid var(--line);border-radius:14px}
.sdk-flash td{animation:sdkflash 1.6s ease-out}
@keyframes sdkflash{from{background:rgba(52,242,232,.22)}to{background:transparent}}
.sdk-toast{position:fixed;right:20px;bottom:20px;z-index:50;max-width:420px;padding:12px 16px;border-radius:12px;background:var(--surface);border:1px solid var(--line);box-shadow:0 8px 30px rgba(0,0,0,.35);font-size:14.5px}
.sdk-toast[hidden]{display:none}
.sdk-toast.err{border-color:var(--bad);color:var(--bad)}
.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
@media (prefers-reduced-motion:reduce){.sdk-spin{animation:none}.sdk-meter span{transition:none}}
@media (max-width:860px){.sdk-tools{width:100%}.sdk-tools input{min-width:0;flex:1}.sdk-table .c-rank,.sdk-table .c-num{display:none}.sdk-mark-forms{grid-template-columns:minmax(0,1fr)}.sdk-row-s{grid-template-columns:minmax(0,1fr)}.sdk-name>.small{max-width:200px}}
</style>
<script>
(function () {
  var table = document.getElementById('sdk-table');
  if (!table) return;
  var none = document.getElementById('sdk-none');
  var shown = document.getElementById('sdk-shown');
  var find = document.getElementById('sdk-find');
  var kind = document.getElementById('sdk-kind');
  var lookup = document.getElementById('sdk-lookup');
  var prog = document.getElementById('sdk-prog'), progT = document.getElementById('sdk-prog-t'), spin = prog.querySelector('.sdk-spin');
  var toast = document.getElementById('sdk-toast'), toastT = 0;
  var order = {missing: 0, unknown: 1, pr: 2, no: 3, ships: 4};
  var st = {state: 'all', kind: '', q: '', sort: 'rank', dir: 1};
  var since = Number(table.dataset.now), polling = 0;
  function items() { return Array.prototype.slice.call(table.querySelectorAll('tbody.sdk-item')); }
  try { var saved = JSON.parse(sessionStorage.getItem('sdk-view') || 'null'); if (saved) { st.state = saved.state || 'all'; st.kind = saved.kind || ''; st.sort = saved.sort === 'now' ? 'rank' : (saved.sort || 'rank'); st.dir = saved.dir || 1; } } catch (e) {}
  function save() { try { sessionStorage.setItem('sdk-view', JSON.stringify({state: st.state, kind: st.kind, sort: st.sort, dir: st.dir})); } catch (e) {} }
  function fmt(n) { return Number(n).toLocaleString(); }
  function apply() {
    var n = 0, all = items(), q = st.q.toLowerCase().trim();
    // A pasted GitHub link filters by its owner/name.
    var m = q.match(/^(?:https?:\/\/)?(?:www\.)?github\.com\/([\w.-]+\/[\w.-]+)/);
    if (m) q = m[1].replace(/\.git$/, '');
    all.forEach(function (it) {
      var ok = (st.state === 'all' || it.dataset.state === st.state || (st.state === 'badge' && it.dataset.badge === '1')) && (!st.kind || it.dataset.kind === st.kind) && (!q || it.dataset.find.indexOf(q) !== -1);
      it.hidden = !ok;
      if (ok) n++;
    });
    none.hidden = n > 0;
    if (lookup) { lookup.hidden = !st.q.trim(); lookup.href = 'sdk-support.php?q=' + encodeURIComponent(st.q.trim()) + '#lookup'; }
    shown.textContent = 'Showing ' + fmt(n) + ' of ' + fmt(all.length);
    document.querySelectorAll('[data-sdk-state]').forEach(function (b) { var on = b.dataset.sdkState === st.state; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    kind.value = st.kind;
  }
  function key(it) {
    if (st.sort === 'name') return it.dataset.name;
    if (st.sort === 'dl') return -Number(it.dataset.dl);
    if (st.sort === 'state') return order[it.dataset.state] * 1e6 + Number(it.dataset.rank);
    return Number(it.dataset.rank);
  }
  function sort() {
    items().sort(function (a, b) { var x = key(a), y = key(b); return (x < y ? -1 : x > y ? 1 : 0) * st.dir; }).forEach(function (it) { table.insertBefore(it, none); });
    table.querySelectorAll('[data-sdk-sort]').forEach(function (b) {
      if (b.dataset.sdkSort === st.sort) b.setAttribute('aria-sort', st.dir === 1 ? 'ascending' : 'descending'); else b.removeAttribute('aria-sort');
    });
  }
  // The counts, the colored line and the progress next to the button.
  function counts(c) {
    document.querySelectorAll('[data-n]').forEach(function (el) { if (c[el.dataset.n] !== undefined) el.textContent = fmt(c[el.dataset.n]); });
    var label = [];
    document.querySelectorAll('[data-m]').forEach(function (el) {
      var t = fmt(c[el.dataset.m]) + ' ' + el.dataset.l;
      el.style.flexGrow = c[el.dataset.m]; el.title = t; label.push(t);
    });
    document.getElementById('sdk-meter').setAttribute('aria-label', label.join('. '));
    table.dataset.todo = c.todo;
    if (c.todo > 0) { prog.hidden = false; spin.hidden = false; progT.textContent = 'Checking: ' + fmt(c.all - c.todo) + ' of ' + fmt(c.all) + ' done'; }
    else if (!prog.hidden) { spin.hidden = true; progT.textContent = 'All ' + fmt(c.all) + ' checked'; setTimeout(function () { if (Number(table.dataset.todo) === 0) prog.hidden = true; }, 5000); }
  }
  // A row from the server takes the old one's place, keeping its ⋯ open if it was.
  function put(id, html) {
    var old = document.getElementById('r-' + id);
    if (!old || !html) return;
    var wasOpen = old.querySelector('.sdk-more[aria-expanded=true]');
    var tmp = document.createElement('table'); tmp.innerHTML = html.trim();
    var neu = tmp.querySelector('tbody'); if (!neu) return;
    old.replaceWith(neu);
    if (wasOpen) { var b = neu.querySelector('.sdk-more'), r = neu.querySelector('.sdk-extra'); if (b && r) { b.setAttribute('aria-expanded', 'true'); r.hidden = false; } }
    neu.classList.add('sdk-flash'); setTimeout(function () { neu.classList.remove('sdk-flash'); }, 1700);
  }
  function say(msg, err) {
    if (!msg) return;
    toast.textContent = msg; toast.className = 'sdk-toast' + (err ? ' err' : ''); toast.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(function () { toast.hidden = true; }, err ? 7000 : 4000);
  }
  function poll() {
    if (polling) return;
    polling = setTimeout(function tick() {
      fetch('sdk-support.php?progress=1&since=' + since, {credentials: 'same-origin', headers: {Accept: 'application/json'}})
        .then(function (r) { return r.json(); })
        .then(function (j) {
          var ids = Object.keys(j.rows || {});
          ids.forEach(function (id) { put(id, j.rows[id]); });
          if (ids.length) { if (st.sort === 'state') sort(); apply(); }
          since = j.now - 1;
          counts(j.count);
          polling = j.count.todo > 0 ? setTimeout(tick, document.hidden ? 15000 : 5000) : 0;
        })
        .catch(function () { polling = setTimeout(tick, 15000); });
    }, 4000);
  }
  // Check again, the marks and Check them all again, without reloading the page.
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-sdk-ajax]');
    if (!f || !window.fetch) return;
    e.preventDefault();
    var btn = f.querySelector('button'), was = btn.textContent;
    var fd = new FormData(f); fd.append('ajax', '1');
    btn.classList.add('sdk-busy'); btn.textContent = fd.get('action') === 'recheck' ? 'Checking…' : (fd.get('action') === 'recheck_all' ? 'Starting…' : 'Saving…');
    fetch('sdk-support.php', {method: 'POST', body: fd, credentials: 'same-origin', headers: {Accept: 'application/json'}})
      .then(function (r) { if (!r.ok) throw new Error(r.status === 400 ? 'This page expired. Reload it and try again.' : 'Something went wrong. Try again.'); return r.json(); })
      .then(function (j) {
        if (j.row) { put(j.id, j.row); if (st.sort === 'state') sort(); apply(); }
        if (j.now) since = Math.min(since, j.now - 1);
        counts(j.count);
        say(j.msg, !j.ok);
        if (j.count.todo > 0) poll();
      })
      .catch(function (err) { say(err.message, true); })
      .then(function () { if (document.body.contains(btn)) { btn.classList.remove('sdk-busy'); btn.textContent = was; } });
  });
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
  // Show one row: clear the filters that hide it, open its ⋯, scroll it into view.
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
  if (Number(table.dataset.todo) > 0) poll();
  if (location.hash.indexOf('#r-') === 0) jump(decodeURIComponent(location.hash.slice(3)));
})();
</script>
<?php rs_foot(['assets/sdk-prompt.js']);
