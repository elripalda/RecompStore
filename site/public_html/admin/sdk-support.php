<?php
// (4.3) SDK support: every recomp on RecompLauncher and whether its own GitHub ships a
// recomplauncher.json, so the team can send the developers that don't a pull request (or a nudge).
// (4.6) Xarishark: red/green status, a star for the README badge, a live progress while checking.
// (4.8) Rebuilt to be quick: the rows go to the page as data and the browser draws 100 at a time,
// with the picked recomp's actions on the side (like Players). Nothing waits on GitHub while the
// page is drawn, and the PR queue and Look up are tabs of their own.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
require_once __DIR__ . '/inc/sdk.php'; // (4.6) the data helpers, shared with the PR queue's API
require_once __DIR__ . '/inc/prq.php'; // (4.6) the PR queue

// One background run at a time, after the answer has gone: the due checks for up to a minute, then
// the pull requests whose state is over an hour old. The half-hourly job does the same; this only
// speeds it up while someone has the page open.
function rs_sdk_kick(array $due, array $stale = []): bool {
    if (!$due && !$stale) return false;
    $run = rs_kv_get('sdk_scan_run');
    if ($run && $run['age'] <= 150) return true;
    rs_kv_put('sdk_scan_run', (string)time());
    register_shutdown_function(function () use ($due, $stale) {
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
        rs_sdk_pr_refresh($stale, 30);
        rs_db()->exec("DELETE FROM kv_cache WHERE k = 'sdk_scan_run'");
    });
    return true;
}

// One recomp as the page's data: just what the table and the side panel show.
function rs_sdk_data(array $row): array {
    $a = $row['a']; $mk = $row['m'];
    return [
        'id' => (string)$a['id'], 'name' => (string)$a['name'], 'game' => (string)($a['game'] ?: $a['name']), 'repo' => (string)$a['repo'],
        'gh' => rs_host_web(rs_pc_host($a), (string)$a['repo']), 'icon' => (string)$a['icon'], 'dl' => (int)$a['downloads'], 'rank' => (int)$row['rank'],
        'origin' => (string)$a['origin'], // (4.8) the console it came from, for the console filter
        'state' => $row['state'], 'pr' => (string)$row['pr'], 'waiting' => (bool)$row['waiting'], 'now' => $row['now'], 'kind' => $row['kind'],
        'badge' => $row['badge'], 'badges' => array_values($row['badges']), 'link' => $row['link'], 'dlss' => (bool)$row['dlss'], 'bare' => rs_sdk_bare($row),
        'mark' => $mk ? ['kind' => (string)$mk['kind'], 'url' => (string)$mk['url'], 'who' => (string)$mk['who'], 'at' => (string)$mk['at']] : null,
        't' => (int)$row['t'],
    ];
}
function rs_sdk_json(array $out): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function rs_sdk_db_now(): int { return (int)rs_db()->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn(); }
$ajax = ($_POST['ajax'] ?? '') === '1';

// Live progress while checking: the counts, and the rows checked since the page last asked.
if (isset($_GET['progress'])) {
    [$rows, $count, $due, $stale] = rs_sdk_all();
    $since = (int)($_GET['since'] ?? 0);
    $changed = [];
    if ($since > 0) foreach ($rows as $row) if ($row['t'] >= $since && count($changed) < 300) $changed[] = rs_sdk_data($row);
    $running = rs_sdk_kick($due, $stale);
    rs_sdk_json(['ok' => true, 'count' => $count, 'running' => $running, 'rows' => $changed, 'now' => rs_sdk_db_now()]);
}

// (4.8) Xarishark: a repo with pull requests closed may still take issues or discussions. Which of
// the two are open, from GitHub (kept a day).
if (isset($_GET['doors'])) {
    $a = rs_pc_app((string)$_GET['doors']);
    if (!$a || rs_pc_host($a) !== '' || (string)$a['repo'] === '') rs_sdk_json(['ok' => false]);
    $k = 'sdkdoors:' . strtolower((string)$a['repo']);
    $c = rs_kv_get($k);
    if (!$c || $c['age'] > 86400) {
        $r = rs_http(rs_gh_api() . '/repos/' . $a['repo'], ['github' => true, 'timeout' => 8, 'max' => 2000000]);
        $j = $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
        if (!is_array($j)) rs_sdk_json(['ok' => false]);
        rs_kv_put($k, (string)json_encode(['issues' => !empty($j['has_issues']), 'discussions' => !empty($j['has_discussions']), 'archived' => !empty($j['archived'])]));
        $c = rs_kv_get($k);
    }
    rs_sdk_json(['ok' => true] + (json_decode((string)$c['v'], true) ?: []));
}

// (4.7) The PR queue's live status while Draft now runs.
if (isset($_GET['prq_poll'])) {
    rs_prq_tables();
    $c = rs_db()->query("SELECT SUM(status = 'drafting' AND updated >= NOW() - INTERVAL " . RS_PRQ_STUCK . " SECOND) d, SUM(status = 'draft') r, SUM(status = 'todo') w FROM pc_prq")->fetch();
    rs_sdk_json(['ok' => true, 'drafting' => (int)$c['d'], 'drafts' => (int)$c['r'], 'waiting' => (int)$c['w']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $id = (string)($_POST['id'] ?? '');
    $msg = ''; $kind = 'ok';
    if (($_POST['action'] ?? '') === 'recheck' && ($a = rs_pc_app($id))) {
        $r = rs_pc_sdk_check($a, true, true);
        $msg = $a['name'] . ($r['has'] === 'release' || $r['has'] === 'repo' ? ' ships its own recomplauncher.json.' : ($r['has'] === 'none' ? ' doesn\'t ship one yet.' : ': GitHub didn\'t answer. Try again in a minute.'))
            . (array_key_exists('badge', $r) && $r['badge'] !== null ? ($r['badge'] ? ' Its README has the ' . implode(', ', array_map(fn($b) => RS_BADGE_NAMES[$b] ?? $b, (array)($r['badges'] ?? []))) . ' badge.' : (!empty($r['link']) ? ' No badge, but its README links to us.' : ' No badge or link to us in its README.')) : '');
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
    } elseif (($_POST['action'] ?? '') === 'mark_issue' && ($a = rs_pc_app($id))) {
        // (4.8) The issue or discussion sent instead of a pull request.
        [$link, $why] = rs_sdk_link($a, trim((string)($_POST['url'] ?? '')), false);
        if ($link === null || str_contains((string)$link, '/pull/')) { $msg = $link === null ? $why : 'That\'s a pull request: use "A pull request is open" for it.'; $kind = 'err'; }
        else {
            $m = rs_sdk_marks();
            $m[$id] = ['kind' => 'issue', 'url' => $link, 'note' => '', 'who' => rs_me_name(), 'at' => gmdate('Y-m-d')];
            rs_sdk_marks_save($m);
            $what = str_contains($link, '/discussions/') ? 'discussion' : 'issue';
            rs_log('sdk', $id, $what . ' open: ' . $link);
            $msg = $a['name'] . ' marked: ' . $what . ' open.';
        }
    } elseif (($_POST['action'] ?? '') === 'mark_locked' && ($a = rs_pc_app($id))) {
        // (4.6) Xarishark: the repo doesn't take pull requests or issues, so there's nothing to link to.
        $m = rs_sdk_marks();
        $m[$id] = ['kind' => 'locked', 'url' => '', 'note' => '', 'who' => rs_me_name(), 'at' => gmdate('Y-m-d')];
        rs_sdk_marks_save($m);
        rs_log('sdk', $id, 'pull requests closed on its repo');
        $msg = $a['name'] . ' marked: pull requests closed.';
    } elseif (($_POST['action'] ?? '') === 'unmark' && ($a = rs_pc_app($id))) {
        $m = rs_sdk_marks();
        unset($m[$id]);
        rs_sdk_marks_save($m);
        rs_log('sdk', $id, 'back on the list');
        $msg = $a['name'] . '\'s mark is cleared.';
    } elseif (($_POST['action'] ?? '') === 'recheck_ids' || (($_POST['action'] ?? '') === 'recheck_all' && trim((string)($_POST['ids'] ?? '')) !== '')) {
        // (4.6) Xarishark: Check again for the picked ones, or for just the ones the filters show.
        $ids = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['ids'] ?? ''))), fn($x) => preg_match('/^[a-z0-9._-]{1,100}$/i', $x)));
        if ($ids) {
            rs_cache_tables();
            $in = implode(',', array_map(fn($x) => rs_db()->quote('sdk:' . $x), array_slice($ids, 0, 2000)));
            rs_db()->exec("UPDATE kv_cache SET at = at - INTERVAL 8 DAY WHERE k IN ($in)");
            $msg = 'Checking ' . count($ids) . ' again. The progress shows next to the button.';
        } else { $msg = 'Pick some first.'; $kind = 'err'; }
        $id = '';
    } elseif (($_POST['action'] ?? '') === 'prq_queue') {
        $ids = array_filter(array_map('trim', explode(',', (string)($_POST['ids'] ?? ''))));
        $n = rs_prq_queue($ids);
        // (4.7) Draft now: straight away on this server when the Anthropic key is saved.
        $started = 0; $why = '';
        if (rs_prq_now_ready()) { try { $started = rs_prq_run($ids); } catch (Throwable $e) { $why = $e->getMessage(); } }
        if ($started) $msg = 'Drafting ' . $started . ' now, a few at a time. Each takes a few minutes and shows up below when it\'s done.';
        elseif ($why !== '') $msg = $why;
        elseif ($n) $msg = $n . ' queued for tomorrow\'s drafts. Add the Anthropic API key in Setup to draft right away.';
        else $msg = 'Nothing to draft: already drafted or sent (see below), or not on GitHub.';
        $id = '';
        rs_flash($msg, $started || $n ? 'ok' : 'err');
        header('Location: sdk-support.php?tab=queue'); exit;
    } elseif (str_starts_with((string)($_POST['action'] ?? ''), 'prq_')) {
        // (4.6) The PR queue: save, send, skip, draft again; and (owner) the token and the drafter's key.
        $act = (string)$_POST['action'];
        $qid = (int)($_POST['qid'] ?? 0);
        try {
            if ($act === 'prq_save' || $act === 'prq_send') {
                $j = json_decode((string)($_POST['json'] ?? ''), true);
                if (!is_array($j)) throw new RuntimeException('The recomplauncher.json isn\'t valid JSON. Fix it and save again.');
                rs_db()->prepare("UPDATE pc_prq SET title = ?, body = ?, json = ?, updated = NOW() WHERE id = ? AND status = 'draft'")
                    ->execute([mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200) ?: RS_PRQ_TITLE, mb_substr(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), 0, 60000), json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $qid]);
                if ($act === 'prq_send') {
                    @set_time_limit(120);
                    $wasSent = (string)(rs_prq_get($qid)['pr_url'] ?? '') !== '';
                    $url = rs_prq_send_or_queue($qid, !empty($_POST['here']), !empty($_POST['code_on']) || empty($_POST['has_code']), rs_me_name());
                    if ($url === '') rs_flash('In line to send. Pull requests go out ' . RS_PRQ_PER_HOUR . ' an hour at most, so it goes out within the hour.');
                    else rs_flash(($wasSent ? 'Pull request updated: ' : 'Pull request sent: ') . $url . (!empty($_POST['here']) ? '. RecompLauncher uses the file now too.' : ''));
                }
                else rs_flash('Saved.');
            } elseif ($act === 'prq_skip') {
                $row = rs_prq_get($qid);
                if ($row && (string)$row['pr_url'] !== '') { rs_prq_keep_sent($qid); rs_flash('Left as it was: the pull request wasn\'t changed.'); }
                else {
                    rs_db()->prepare("UPDATE pc_prq SET status = 'skipped', updated = NOW() WHERE id = ?")->execute([$qid]);
                    rs_flash('Skipped. It won\'t be drafted again.');
                }
            } elseif ($act === 'prq_unqueue') {
                rs_db()->prepare("UPDATE pc_prq SET status = 'draft', updated = NOW() WHERE id = ? AND status = 'outbox'")->execute([$qid]);
                rs_flash('Taken out of the line. It\'s back with the drafts.');
            } elseif ($act === 'prq_reopen') {
                // (4.9) A sent pull request drafted again (with the command-line checks), then updated with one click.
                rs_prq_reopen($qid);
                $row = rs_prq_get($qid);
                if ($row && rs_prq_now_ready() && rs_prq_run([(string)$row['app_id']])) rs_flash('Drafting it again now. It shows up under the drafts in a few minutes, with Update pull request.');
                else rs_flash('Back in line: the next daily run drafts it again.');
                $qid = 0;
            } elseif ($act === 'prq_redo' || $act === 'prq_now') {
                // Draft again (a draft), or Draft now (one waiting): on this server at once when it can.
                if ($act === 'prq_redo') rs_db()->prepare("UPDATE pc_prq SET status = 'todo', json = NULL, body = NULL, note = NULL, found = NULL, code = NULL, body_plain = NULL, error = NULL, updated = NOW() WHERE id = ? AND status <> 'sent'")->execute([$qid]);
                $row = rs_prq_get($qid);
                if ($row && rs_prq_now_ready() && rs_prq_run([(string)$row['app_id']])) rs_flash('Drafting it now. It shows up below in a few minutes.');
                else rs_flash(rs_prq_now_ready() ? 'It\'s already being drafted.' : 'Back in line: the next daily run drafts it. Add the Anthropic API key in Setup to draft right away.');
                $qid = 0;
            } elseif ($act === 'prq_redo_all') {
                // (4.10) Every draft not sent yet, written again with the latest drafter (redrafts of sent ones too).
                $ids = array_column(rs_db()->query("SELECT app_id FROM pc_prq WHERE status = 'draft' ORDER BY updated DESC LIMIT 40")->fetchAll(), 'app_id');
                if ($ids) rs_db()->exec("UPDATE pc_prq SET status = 'todo', json = NULL, body = NULL, note = NULL, found = NULL, code = NULL, body_plain = NULL, error = NULL, updated = NOW() WHERE status = 'draft' AND app_id IN (" . implode(',', array_map(fn($x) => rs_db()->quote((string)$x), $ids)) . ')');
                $n = $ids && rs_prq_now_ready() ? rs_prq_run($ids) : 0;
                rs_flash($n ? 'Drafting ' . $n . ' again, a few at a time. Each takes a few minutes and shows up below when it\'s done.' : 'Back in line for the next daily run.');
                $qid = 0;
            } elseif ($act === 'prq_forget') {
                rs_db()->prepare("DELETE FROM pc_prq WHERE id = ? AND status = 'todo'")->execute([$qid]);
                rs_flash('Taken out of the queue.');
                $qid = 0;
            } elseif ($act === 'prq_ai' && rs_is_owner()) {
                $k = trim((string)($_POST['ai_key'] ?? ''));
                if ($k !== '') {
                    $c = curl_init('https://api.anthropic.com/v1/models?limit=1');
                    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['x-api-key: ' . $k, 'anthropic-version: 2023-06-01']]);
                    curl_exec($c); $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
                    if ($code !== 200) throw new RuntimeException('Anthropic didn\'t accept that key (' . ($code ?: 'no answer') . ').');
                }
                rs_secret_put('prq_ai_key', $k);
                rs_flash($k !== '' ? 'Saved. Draft now works right away.' : 'Anthropic key removed. Drafts wait for the daily run.');
            } elseif ($act === 'prq_token' && rs_is_owner()) {
                $t = trim((string)($_POST['token'] ?? ''));
                rs_secret_put('prq_gh_token', $t);
                if ($t !== '') { $me = rs_prq_gh('GET', '/user'); if ($me['status'] !== 200) { rs_secret_put('prq_gh_token', ''); throw new RuntimeException('GitHub didn\'t accept that token (' . $me['status'] . ').'); } rs_set_setting('prq_gh_login', (string)$me['json']['login']); rs_flash('Saved. Pull requests will come from @' . $me['json']['login'] . '.'); }
                else { rs_set_setting('prq_gh_login', ''); rs_flash('Token removed.'); }
            } elseif ($act === 'prq_key' && rs_is_owner()) {
                // Kept encrypted on the server until the page shows it once (the login session could
                // bring it back on a later page when two requests overlapped).
                rs_secret_put('prq_key_once', rs_prq_key_new());
                rs_flash('New key made. Copy it now: it\'s shown only once.');
            }
        } catch (Throwable $e) { rs_flash($e->getMessage(), 'err'); }
        header('Location: sdk-support.php?tab=queue' . ($qid ? '#q-' . $qid : '')); exit;
    } elseif (($_POST['action'] ?? '') === 'recheck_all') {
        // Everything becomes due, but keeps what it showed until its new check is in.
        rs_cache_tables();
        rs_db()->exec("UPDATE kv_cache SET at = at - INTERVAL 8 DAY WHERE k LIKE 'sdk:%'");
        $msg = 'Checking everything again. The progress shows next to the button.';
        $id = '';
    }
    if ($ajax) {
        [$rows, $count, $due, $stale] = rs_sdk_all();
        $running = rs_sdk_kick($due, $stale);
        rs_sdk_json(['ok' => $kind === 'ok', 'msg' => $msg, 'id' => $id, 'row' => $id !== '' && isset($rows[$id]) ? rs_sdk_data($rows[$id]) : null, 'count' => $count, 'running' => $running, 'now' => rs_sdk_db_now()]);
    }
    if ($msg !== '') rs_flash($msg, $kind);
    header('Location: sdk-support.php' . ($id !== '' ? '?id=' . rawurlencode($id) : ''));
    exit;
}


$tab = in_array($_GET['tab'] ?? '', ['queue', 'lookup'], true) ? (string)$_GET['tab'] : 'list';
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
rs_prq_tables();
$draftN = (int)rs_db()->query("SELECT COUNT(*) FROM pc_prq WHERE status = 'draft'")->fetchColumn();
$listN = 0;

if ($tab === 'list') {
    [$rows, $count, $due, $stale] = rs_sdk_all();
    rs_sdk_kick($due, $stale);
    $listN = $count['all'];
    $pageData = [
        'rows' => array_map('rs_sdk_data', array_values($rows)), 'count' => $count, 'now' => rs_sdk_db_now(),
        'csrf' => rs_csrf(), 'draftNow' => rs_prq_now_ready(), 'badgeNames' => RS_BADGE_NAMES, 'origins' => RS_PC_ORIGINS,
    ];
}

// Look up a GitHub repo that isn't on RecompLauncher (owner/name or a github.com link): its prompt
// and badge still work, so a pull request can go out before it's listed. Listed ones point at the table.
$q = trim((string)($_GET['q'] ?? ''));
$found = [];
$outside = null;
$parked = null;
if ($tab === 'lookup' && mb_strlen($q) >= 2) {
    [$rows] = rs_sdk_all();
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
        foreach (rs_pc_apps() as $a) if (strcasecmp((string)$a['repo'], $want) === 0 && rs_pc_host($a) === '') {
            $listed = true;
            // In the catalog but not in the table: hidden, or still Coming soon.
            if (!isset($rows[$a['id']])) $parked = $a;
            break;
        }
        if (!$listed) {
            $r = rs_http(rs_gh_api() . '/repos/' . $want, ['github' => true, 'timeout' => 8, 'max' => 2000000]);
            $j = $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
            $outside = is_array($j) ? ['repo' => (string)$j['full_name'], 'about' => (string)($j['description'] ?? ''), 'stars' => (int)($j['stargazers_count'] ?? 0)] : ['repo' => $want, 'missing' => $r['status'] === 404];
        }
    }
}
$sdkState = ['ships' => ['Ships its own file', 'ok'], 'pr' => ['PR or issue open', 'blue'], 'no' => ['Won\'t support it', 'warn'], 'locked' => ['Pull requests closed', 'violet'], 'missing' => ['No file yet', 'bad'], 'unknown' => ['Couldn\'t check yet', '']];

rs_head('SDK support', true, ['assets/sdk-support.css']);
?>
<section class="sdk-head">
  <div>
    <h1>SDK support</h1>
    <p class="muted">Which recomps ship a <code>recomplauncher.json</code> on their own GitHub. Each one without it is a pull request away from Easy Setup.</p>
  </div>
  <?php if ($tab === 'list'): ?>
  <div class="sdk-head-act">
    <span class="sdk-prog" id="sdk-prog" aria-live="polite"<?= $count['todo'] ? '' : ' hidden' ?>><span class="sdk-spin" aria-hidden="true"></span><span id="sdk-prog-t">Checking <?= number_format($count['all'] - $count['todo']) ?> of <?= number_format($count['all']) ?></span></span>
    <button type="button" class="btn" id="sdk-all-b">Check all again</button>
  </div>
  <?php endif; ?>
</section>
<nav class="sdk-tabs" aria-label="SDK support">
  <a href="sdk-support.php"<?= $tab === 'list' ? ' class="on" aria-current="page"' : '' ?>>Recomps<?= $listN ? '<span>' . number_format($listN) . '</span>' : '' ?></a>
  <a href="sdk-support.php?tab=queue"<?= $tab === 'queue' ? ' class="on" aria-current="page"' : '' ?>>PR queue<?= $draftN ? '<span class="hot">' . $draftN . '</span>' : '' ?></a>
  <a href="sdk-support.php?tab=lookup"<?= $tab === 'lookup' ? ' class="on" aria-current="page"' : '' ?>>Look up a repo</a>
</nav>
<?php if ($tab === 'list'): ?>
<div class="sdk-kpis" id="sdk-kpis" role="group" aria-label="Filter by status">
  <?php foreach (['missing', 'ships', 'pr', 'no', 'locked', 'unknown'] as $k): ?>
  <button type="button" class="sdk-kpi k-<?= $k ?>" data-state="<?= $k ?>" aria-pressed="false"><span><?= h($k === 'unknown' ? 'Not checked' : $sdkState[$k][0]) ?></span><strong data-n="<?= $k ?>"><?= number_format($count[$k]) ?></strong></button>
  <?php endforeach; ?>
</div>
<div class="sdk-wrap">
  <section class="card sdk-list">
    <div class="sdk-tools">
      <input type="search" id="sdk-find" placeholder="Search name, game or repo" spellcheck="false" autocomplete="off" aria-label="Search">
      <select id="sdk-readme" aria-label="README"><option value="">Any README</option><option value="badge">★ Has our badge</option><option value="link">☆ Links to us only</option><option value="bare">No badge or link</option></select>
      <select id="sdk-kind" aria-label="Type"><option value="">Every type</option><option value="console">Console recomps</option><option value="pc">PC games</option></select>
      <select id="sdk-console" aria-label="Console"><option value="">Every console</option></select>
      <select id="sdk-sort" aria-label="Sort"><option value="rank">Most downloaded</option><option value="name">Name</option><option value="state">Status</option></select>
    </div>
    <div class="sdk-sub">
      <span class="muted" id="sdk-shown">Loading…</span>
      <button type="button" class="linkish" id="sdk-clear" hidden>Clear filters</button>
      <div class="sdk-picked" id="sdk-picked" hidden>
        <strong id="sdk-picked-n">0 picked</strong>
        <button type="button" class="btn" data-bulk="recheck">Check again</button>
        <button type="button" class="btn primary" data-bulk="draft"><?= rs_prq_now_ready() ? 'Draft PRs now' : 'Queue for PR drafts' ?></button>
        <button type="button" class="btn" data-bulk="clear">Clear</button>
      </div>
    </div>
    <div class="sdk-scroll">
      <table class="sdk-table" id="sdk-table">
        <thead><tr>
          <th class="c-pick"><input type="checkbox" id="sdk-pick-all" aria-label="Pick every recomp the filters show"></th>
          <th class="c-rank">#</th>
          <th>Recomp</th>
          <th>Status</th>
          <th class="c-num">Downloads</th>
        </tr></thead>
        <tbody id="sdk-body"><tr class="sdk-skel"><td colspan="5"><span class="sdk-spin" aria-hidden="true"></span> Loading recomps…</td></tr></tbody>
      </table>
    </div>
    <div class="sdk-pager" id="sdk-pager" hidden>
      <button type="button" class="btn" data-page="-1" aria-label="Previous page">←</button>
      <span id="sdk-page-t"></span>
      <button type="button" class="btn" data-page="1" aria-label="Next page">→</button>
    </div>
  </section>
  <aside class="card sdk-side" id="sdk-side" aria-label="Recomp" aria-live="polite">
    <h2>Pick a recomp</h2>
    <p class="muted">Click a row to see where it stands and what to send its developer: the agent prompt, the badge, Check again and the marks.</p>
  </aside>
</div>
<details class="card sdk-how">
  <summary>How this page works</summary>
  <ul>
    <li><b>Pull requests closed</b>: the side panel checks whether the repo still takes issues or discussions, and gives you a ready message for either. Paste the link back once it's posted and the recomp moves to <b>PR or issue open</b>.</li>
    <li><b>No file yet</b> (red) means the recomp's GitHub doesn't ship a <code>recomplauncher.json</code>. Open it, give your AI agent the <b>agent prompt</b>, and send the developer the pull request. The prompt also adds our badge, checks the project against the Recomp Standards and ends with a note inviting the developer to claim it.</li>
    <li><b>Ships its own file</b> (green) is done. If its README has no RecompLauncher badge, <b>Copy badge PR prompt</b> adds just that.</li>
    <li>The second pill is the README: <b>★ Badge</b> means it has one of our badges (the Verified developer one counts), <b>☆ Link only</b> means it only links to us. <b>No badge or link</b> marks the ones that ship the file (or got a merged pull request) but mention us nowhere.</li>
    <li>Pick rows with the boxes, then <b>Draft PRs now</b> to have the drafter write their pull requests. They show up under <b>PR queue</b>, and nothing is sent until you click Send there.</li>
    <li>Every recomp is checked again on its own each week. <b>Check all again</b> (or Check again on the picked ones) does it now; the progress shows at the top.</li>
  </ul>
</details>
<div class="sdk-toast" id="sdk-toast" role="status" aria-live="polite" hidden></div>
<script type="application/json" id="sdk-data"><?= json_encode($pageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif; ?>
<?php if ($tab === 'queue'): ?>
<?php // (4.6) The PR queue
$prqDrafts = rs_prq_list(['draft']);
$prqWork = rs_prq_list(['todo', 'drafting'], 50); // (4.7) waiting, drafting now, or didn't draft
$prqWaiting = count(array_filter($prqWork, fn($r) => $r['status'] === 'todo'));
$prqNow = rs_prq_now_ready();
$prqHasAi = rs_secret_get('prq_ai_key') !== '';
$prqSent = rs_prq_list(['sent'], 20);
$prqOut = rs_prq_list(['outbox'], 50); // (4.9) waiting their turn, 5 an hour
$prqLogin = (string)rs_setting('prq_gh_login', '');
$prqHasToken = rs_prq_token() !== '';
$prqKeyOnce = rs_is_owner() ? rs_secret_get('prq_key_once') : '';
if ($prqKeyOnce !== '') rs_secret_put('prq_key_once', '');
if (!empty($_SESSION['prq_key_once'])) rs_session_write(function () { unset($_SESSION['prq_key_once']); }); // from before
$prqPick = ['new' => 'Just added', 'popular' => 'Popular port', 'asked' => 'Queued by hand'];
?>
<section class="card prq-card" id="prq">
  <div class="an-head"><h2>PR queue</h2><span class="muted small"><?= count($prqDrafts) ?> to look over<?= $prqWaiting ? ' · ' . $prqWaiting . ' waiting' : '' ?> · <?= $prqNow ? 'Draft now, and daily at 7:23' : 'drafted daily at 7:23' ?><?= $prqLogin !== '' ? ' · sent as @' . h($prqLogin) : '' ?></span><?php if ($prqDrafts && $prqNow): ?><form method="post" class="prq-redo-all"><?= $csrf ?><button class="btn small" name="action" value="prq_redo_all" onclick="return confirm('Draft all <?= count($prqDrafts) ?> again from scratch with the latest drafter?')">Draft them all again</button></form><?php endif; ?></div>
  <p class="muted small">Pick recomps on the <a href="sdk-support.php">Recomps</a> tab and click <strong>Draft PRs now</strong>: their <code>recomplauncher.json</code> and pull request are written in about a minute and show up here. Every day at 7:23 the drafter also picks a few with no file yet by itself (half just added, half popular console ports). Look each draft over, fix anything, then Send: it forks the repo, adds the file and the badge, opens the pull request and marks the recomp "Pull request open". Nothing is sent until you click Send.</p>
  <?php if (!$prqHasAi && rs_is_owner()): ?><p class="warn small">Add the Anthropic API key in Setup below so Draft now works right away. Until then, picked recomps wait for the daily run.</p><?php endif; ?>
  <?php if ($prqWork): ?>
  <ul class="prq-work" id="prq-work">
  <?php foreach ($prqWork as $w): $wa = rs_pc_app((string)$w['app_id']); $stuck = $w['status'] === 'drafting' && strtotime((string)$w['updated'] . ' UTC') < time() - RS_PRQ_STUCK; $drafting = $w['status'] === 'drafting' && !$stuck; ?>
    <li class="prq-w<?= $drafting ? ' on' : '' ?>"<?= $drafting ? ' data-drafting' : '' ?>>
      <?php if ($drafting): ?><span class="sdk-spin" aria-hidden="true"></span><?php endif; ?>
      <span class="prq-name"><strong><?= h($wa['name'] ?? $w['app_id']) ?></strong><span class="muted small"><?= h($w['repo']) ?></span></span>
      <span class="prq-w-st small"><?= $drafting ? 'Drafting now…' : ($stuck ? '<span class="bad">Didn\'t finish. Try again.</span>' : ((string)$w['error'] !== '' ? '<span class="bad">Didn\'t draft: ' . h((string)$w['error']) . '</span>' : 'Waiting for ' . ($prqNow ? 'Draft now' : 'the daily run'))) ?></span>
      <?php if (!$drafting): ?><form method="post" class="prq-w-btns"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$w['id'] ?>"><?php if ($prqNow): ?><button class="btn primary" name="action" value="prq_now">Draft now</button><?php endif; ?><?php if ($w['status'] === 'todo'): ?><button class="btn" name="action" value="prq_forget">Remove</button><?php endif; ?></form><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if (!$prqHasToken && rs_is_owner()): ?><p class="warn small">Add the GitHub token below before sending: it's the account the pull requests come from.</p><?php endif; ?>
  <?php if (!$prqDrafts): ?><p class="muted">No drafts right now.</p><?php endif; ?>
  <?php foreach ($prqDrafts as $pq): $qa = rs_pc_app((string)$pq['app_id']); if (!$qa) continue; ?>
  <details class="prq-item" id="q-<?= (int)$pq['id'] ?>"<?= (string)$pq['error'] !== '' ? ' open' : '' ?>>
    <summary><span class="prq-name"><strong><?= h($qa['name']) ?></strong><span class="muted small"><?= h($pq['repo']) ?> · <?= number_format((int)$qa['downloads']) ?> downloads</span></span>
      <?php if (isset($prqPick[$pq['pick']])): ?><span class="pill"><?= h($prqPick[$pq['pick']]) ?></span><?php endif; ?>
      <?php if ((string)$pq['error'] !== ''): ?><span class="pill bad">Didn't send</span><?php endif; ?></summary>
    <form method="post" class="prq-form"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$pq['id'] ?>">
      <?php if ((string)$pq['error'] !== ''): ?><p class="bad small"><?= h((string)$pq['error']) ?></p><?php endif; ?>
      <?php if ((string)$pq['pr_url'] !== ''): ?><p class="small">Redraft of <a href="<?= h((string)$pq['pr_url']) ?>" target="_blank" rel="noopener">the pull request we sent ↗</a>. Update pull request pushes this file and description to it.</p><?php endif; ?>
      <?php if (trim((string)$pq['note']) !== ''): ?><p class="prq-note small"><strong>Drafter's notes:</strong> <?= nl2br(h((string)$pq['note'])) ?></p><?php endif; ?>
      <?php $pqFound = (string)($pq['found'] ?? '') !== '' ? json_decode((string)$pq['found'], true) : null; if (is_array($pqFound) && (!empty($pqFound['features']) || !empty($pqFound['options']))): ?>
      <div class="prq-found">
        <h4>What it already has <span class="muted small">what the drafter saw in the project, and where</span></h4>
        <ol class="prq-steps">
        <?php foreach ((array)($pqFound['features'] ?? []) as $f): if (!is_array($f) || trim((string)($f['what'] ?? '')) === '') continue;
            $has = $f['has'] ?? null; $isGame = strtolower(trim((string)$f['what'])) === 'game location';
            $cls = $has === true ? 'ok' : ($has === false ? ($isGame ? 'bad' : 'no') : 'wait'); ?>
          <li class="prq-s-<?= $cls ?>"><span class="prq-s-i"><?= $has === true ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>' : ($has === false && $isGame ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M7 7l10 10M17 7 7 17"/></svg>' : '–') ?></span>
            <span><b><?= h((string)$f['what']) ?><?php if ($has === true && !empty($f['used'])): ?> <span class="pill ok">In the file</span><?php endif; ?></b>
            <small><?= h(trim((string)($f['how'] ?? '')) !== '' ? (string)$f['how'] : ($has === false ? 'Not found' : 'Couldn\'t tell')) ?><?php $w = rs_prq_where((string)$pq['repo'], (string)($f['where'] ?? '')); if ($w !== ''): ?> · <?= $w ?><?php endif; ?></small></span></li>
        <?php endforeach; ?>
        </ol>
        <?php $pqOpts = array_values(array_filter((array)($pqFound['options'] ?? []), fn($o) => is_array($o) && trim((string)($o['flag'] ?? '')) !== '')); if ($pqOpts): ?>
        <h4 class="prq-h4b">Command-line options it reads <span class="muted small"><?= count($pqOpts) ?></span></h4>
        <dl class="prq-facts"><?php foreach ($pqOpts as $o): ?><div><dt class="mono"><?= h((string)$o['flag']) ?></dt><dd><?= h((string)($o['does'] ?? '')) ?><?php $w = rs_prq_where((string)$pq['repo'], (string)($o['where'] ?? '')); if ($w !== ''): ?><span class="prq-where small"><?= $w ?></span><?php endif; ?></dd></div><?php endforeach; ?></dl>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="prq-links small"><a href="https://github.com/<?= h($pq['repo']) ?>" target="_blank" rel="noopener">GitHub ↗</a> · <a href="https://github.com/<?= h($pq['repo']) ?>/releases" target="_blank" rel="noopener">Releases ↗</a> · <a href="https://recomplauncher.com/game/<?= h($qa['id']) ?>" target="_blank" rel="noopener">Its page ↗</a></div>
      <div class="prq-grid">
        <label>Pull request title<input name="title" value="<?= h((string)$pq['title']) ?>" maxlength="200"></label>
        <label class="prq-span">Pull request description<textarea name="body" rows="14"><?= h((string)$pq['body']) ?></textarea></label>
        <label class="prq-span">recomplauncher.json<textarea name="json" rows="14" class="mono" spellcheck="false"><?= h((string)$pq['json']) ?></textarea></label>
      </div>
      <?php $pqCode = (string)($pq['code'] ?? '') !== '' ? json_decode((string)$pq['code'], true) : null; if (is_array($pqCode) && !empty($pqCode['edits'])): ?>
      <div class="prq-code"><input type="hidden" name="has_code" value="1">
        <h4>Code changes <span class="muted small">adds <?= h(implode(', ', array_map('strval', (array)($pqCode['adds'] ?? [])))) ?></span></h4>
        <?php foreach ((array)($pqCode['explain'] ?? []) as $ex): ?><p class="small">• <?= h((string)$ex) ?></p><?php endforeach; ?>
        <?php foreach ((array)$pqCode['edits'] as $ed): ?>
        <div class="prq-diff"><div class="prq-diff-path mono small"><?= h((string)($ed['path'] ?? '')) ?></div><pre class="prq-del"><?= h((string)($ed['find'] ?? '')) ?></pre><pre class="prq-add"><?= h((string)($ed['replace'] ?? '')) ?></pre></div>
        <?php endforeach; ?>
        <label class="prq-here small"><input type="checkbox" name="code_on" value="1" checked> Include the code changes (off: only recomplauncher.json and the badge, as before)</label>
      </div>
      <?php endif; ?>
      <div class="prq-btns">
        <?php $isUpd = (string)$pq['pr_url'] !== ''; ?>
        <button class="btn primary" name="action" value="prq_send" onclick="return confirm('<?= $isUpd ? 'Update the pull request on ' : 'Open this pull request on ' ?><?= h(addslashes((string)$pq['repo'])) ?>?')"<?= $prqHasToken ? '' : ' disabled title="Add the GitHub token first"' ?>><?= $isUpd ? 'Update pull request' : 'Send pull request' ?></button>
        <button class="btn" name="action" value="prq_save">Save</button>
        <button class="btn" name="action" value="prq_redo" title="<?= $prqNow ? 'Writes it again from scratch, right away' : 'The next daily run drafts it again' ?>">Draft it again</button>
        <button class="btn danger" name="action" value="prq_skip"><?= $isUpd ? 'Keep it as it was' : 'Skip' ?></button>
        <label class="prq-here small"><input type="checkbox" name="here" value="1" checked> Use it on RecompLauncher now, until the developer merges it</label>
      </div>
    </form>
  </details>
  <?php endforeach; ?>
  <?php if ($prqOut): ?>
  <h3 class="prq-h3">Going out next <span class="muted small"><?= RS_PRQ_PER_HOUR ?> an hour, in this order</span></h3>
  <ul class="prq-sent"><?php foreach ($prqOut as $pq): ?><li><span><?= h((string)$pq['repo']) ?></span><span class="muted small">queued <?= h((string)$pq['updated']) ?><?= (string)$pq['who'] !== '' ? ' · ' . h((string)$pq['who']) : '' ?><?= (string)$pq['error'] !== '' ? ' · ' . h((string)$pq['error']) : '' ?></span>
    <form method="post" class="prq-sent-act"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$pq['id'] ?>"><button class="btn small" name="action" value="prq_unqueue">Take it back</button></form></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if ($prqSent): ?>
  <h3 class="prq-h3">Sent lately</h3>
  <ul class="prq-sent"><?php foreach ($prqSent as $pq): $fx = rs_prq_fix_state((int)$pq['id']); ?><li><a href="<?= h((string)$pq['pr_url']) ?>" target="_blank" rel="noopener"><?= h((string)$pq['repo']) ?></a><span class="muted small"><?= h((string)$pq['sent_at']) ?><?= (string)$pq['who'] !== '' ? ' · ' . h((string)$pq['who']) : '' ?></span>
    <form method="post" class="prq-sent-act"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$pq['id'] ?>"><button class="btn small" name="action" value="prq_reopen" title="Draft the file again from scratch (game location, saves, starting straight in), then update this pull request with one click">Draft it again</button></form>
    <?php if ($fx && ($fx['why'] ?? '') !== ''): ?><span class="small warn">Conflict, needs a look: <?= h((string)$fx['why']) ?></span><?php elseif ($fx): ?><span class="small ok">Re-drafted after a conflict<?= (int)$fx['n'] > 1 ? ' (' . (int)$fx['n'] . ' times)' : '' ?>, <?= h((string)$fx['at']) ?> UTC</span><?php endif; ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php if (rs_is_owner()): ?>
  <details class="prq-setup"<?= !$prqHasToken || $prqKeyOnce !== '' ? ' open' : '' ?>><summary>Setup</summary>
    <div class="prq-setup-grid">
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="prq_token">
        <label>GitHub token for pull requests <span class="muted small"><?= $prqHasToken ? 'saved, for @' . h($prqLogin) : 'not set' ?></span>
          <input type="password" name="token" autocomplete="off" placeholder="<?= $prqHasToken ? 'Paste a new one to replace it, or leave empty and save to remove it' : 'A fine-grained or classic token with public_repo' ?>"></label>
        <small class="muted">Make it on github.com → Settings → Developer settings → Personal access tokens, signed in as the account the pull requests should come from. A classic token needs the <code>public_repo</code> scope. It's kept encrypted on the server.</small>
        <button class="btn">Save token</button></form>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="prq_ai">
        <label>Anthropic API key for Draft now <span class="muted small"><?= $prqHasAi ? 'saved' : 'not set' ?></span>
          <input type="password" name="ai_key" autocomplete="off" placeholder="<?= $prqHasAi ? 'Paste a new one to replace it, or leave empty and save to remove it' : 'sk-ant-…' ?>"></label>
        <small class="muted">Make one on console.anthropic.com → API Keys. Draft now runs the drafter on this server with it, so drafts are ready in about a minute. It's kept encrypted on the server.</small>
        <button class="btn">Save key</button></form>
      <form method="post"><?= $csrf ?><input type="hidden" name="action" value="prq_key">
        <label>Drafter key <span class="muted small"><?= (string)rs_setting('prq_key_hash', '') !== '' ? 'set' : 'not set' ?></span></label>
        <?php if ($prqKeyOnce !== ''): ?><input readonly class="mono" value="<?= h($prqKeyOnce) ?>" onclick="this.select()"><small class="warn">Copy it now into the RecompStore repo on GitHub as the secret <code>RS_PRQ_KEY</code> (Settings → Secrets and variables → Actions). It isn't shown again.</small>
        <?php else: ?><small class="muted">The daily GitHub Action sends it with every call. Making a new one stops the old one working.</small><?php endif; ?>
        <button class="btn">Make a new key</button></form>
    </div>
  </details>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($tab === 'lookup'): ?>
<section class="card" id="lookup">
  <h2>Not on RecompLauncher yet?</h2>
  <p class="muted small">Paste a GitHub link or owner/name to get its agent prompt and badge, so a pull request can go out before it's listed. Ones already listed point you to their row, and hidden or Coming soon ones link to their page. To list a new one, use Add it to the store.</p>
  <form method="get" class="sdk-search" action="sdk-support.php">
    <input type="hidden" name="tab" value="lookup">
    <input type="search" name="q" value="<?= h($q) ?>" placeholder="github.com/owner/name, or owner/name" spellcheck="false" autocomplete="off" autofocus>
    <button class="btn primary">Look up</button><?php if ($q !== ''): ?> <a class="btn" href="sdk-support.php?tab=lookup">Clear</a><?php endif; ?>
  </form>
  <?php if ($q !== ''): ?>
  <div class="sdk-found">
  <?php foreach ($found as $f): $a = $f['a']; [$sl, $sc] = $sdkState[$f['state']]; ?>
    <article class="sdk-row-s">
      <div class="sdk-name"><strong><?= h($a['name']) ?></strong><span class="muted small"><?= h($a['repo']) ?> · <?= number_format((int)$a['downloads']) ?> downloads</span></div>
      <span class="pill <?= $sc ?>"><?= h($sl) ?></span>
      <a class="btn" href="sdk-support.php?id=<?= h(rawurlencode($a['id'])) ?>">Open it</a>
    </article>
  <?php endforeach; ?>
  <?php if ($outside): ?>
    <article class="sdk-row-s">
      <div class="sdk-name"><strong><?= h($outside['repo']) ?></strong><span class="muted small"><?= !empty($outside['missing']) ? 'GitHub has no repo by that name.' : 'Not on RecompLauncher yet' . ($outside['about'] !== '' ? ' · ' . h(mb_strimwidth($outside['about'], 0, 90, '…')) : '') ?></span></div>
      <span class="pill <?= !empty($outside['missing']) ? 'warn' : '' ?>"><?= !empty($outside['missing']) ? 'Not found' : 'Not listed' ?></span>
      <div class="sdk-btns">
        <?php if (empty($outside['missing'])): ?>
        <a class="btn" href="https://github.com/<?= h($outside['repo']) ?>" target="_blank" rel="noopener">GitHub ↗</a>
        <button type="button" class="btn primary" data-prompt-repo="<?= h($outside['repo']) ?>" data-prompt-id="">Copy agent prompt</button>
        <button type="button" class="btn" data-badge-id="">Copy badge</button>
        <?php if (rs_role_at_least('curator')): ?><a class="btn" href="pc.php#found">Add it to the store</a><?php endif; ?>
        <?php endif; ?>
      </div>
    </article>
  <?php endif; ?>
  <?php if ($parked): ?>
    <article class="sdk-row-s">
      <div class="sdk-name"><strong><?= h($parked['name']) ?></strong><span class="muted small"><?= h($parked['repo']) ?> · listed, but <?= !empty($parked['hidden']) ? 'hidden' : 'still Coming soon' ?>, so it isn't in the table</span></div>
      <span class="pill"><?= !empty($parked['hidden']) ? 'Hidden' : 'Coming soon' ?></span>
      <a class="btn" href="pc.php?edit=<?= h(rawurlencode($parked['id'])) ?>">Open it</a>
    </article>
  <?php endif; ?>
  <?php if (!$found && !$outside && !$parked): ?><p class="muted">Nothing matches "<?= h($q) ?>". Paste its GitHub link or owner/name.</p><?php endif; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if ($tab === 'queue'): ?>
<script>
// (4.7) While Draft now runs: ask every few seconds, and reload once a draft is in.
(function () {
  if (!document.querySelector('[data-drafting]')) return;
  var start = document.querySelectorAll('[data-drafting]').length, tries = 0;
  var t = setInterval(function () {
    if (++tries > 150) { clearInterval(t); return; }
    fetch('sdk-support.php?prq_poll=1', { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j && j.ok && j.drafting < start) { clearInterval(t); location.reload(); }
    }).catch(function () {});
  }, 4000);
})();
</script>
<?php endif; ?>
<?php rs_foot($tab === 'list' ? ['assets/sdk-prompt.js', 'assets/sdk-support.js'] : ($tab === 'lookup' ? ['assets/sdk-prompt.js'] : []));
