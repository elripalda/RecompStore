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
require_once __DIR__ . '/inc/prq-view.php'; // (4.11) a draft's feature list
require_once __DIR__ . '/inc/sdkcheck.php'; // (4.11) Check a game

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
        'mark' => $mk ? ['kind' => (string)$mk['kind'], 'url' => (string)$mk['url'], 'who' => (string)$mk['who'], 'at' => (string)$mk['at'], 'queue' => !empty($mk['queue'])] : null,
        'q' => (string)($row['q'] ?? ''), // (4.12) its PR queue stage
        'reply' => !empty($row['reply']) ? ['who' => (string)$row['reply']['who'], 'at' => (string)$row['reply']['at'], 'text' => (string)$row['reply']['text'], 'url' => (string)$row['reply']['url']] : null,
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

// (4.11) Check a game: the test drafts' states, so the page reloads when one is in.
if (isset($_GET['dry_poll'])) {
    $l = rs_prq_dry_list((string)$_GET['dry_poll'], 30);
    rs_sdk_json(['ok' => true, 'running' => count(array_filter($l, fn($r) => ($r['status'] ?? '') === 'running')), 'n' => count($l)]);
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
    } elseif (str_starts_with((string)($_POST['action'] ?? ''), 'chk_')) {
        // (4.11) Check a game: check again, test drafts (models side by side), and what to do with one.
        $act = (string)$_POST['action'];
        // (4.12) A submission not on RecompLauncher yet (id sub-<inbox id>): test drafts only.
        $chkSubPost = preg_match('/^sub-(\d+)$/', $id, $sm) ? (int)$sm[1] : 0;
        $a = $chkSubPost ? null : rs_pc_app($id);
        try {
            if ($chkSubPost) {
                if ($act === 'chk_test') {
                    $models = array_values(array_intersect((array)($_POST['models'] ?? []), ['haiku', 'sonnet', 'opus', 'haiku+sonnet', 'sonnet+opus']));
                    if (!$models) throw new RuntimeException('Tick at least one model.');
                    if (!rs_prq_sub_pick($chkSubPost)) throw new RuntimeException('That submission isn\'t a PC recomp on GitHub, so the drafter can\'t read it.');
                    rs_prq_dry_start(['sub-' . $chkSubPost], $models);
                    rs_flash('Making its recomplauncher.json with ' . count($models) . ' model' . (count($models) === 1 ? '' : 's') . '. Each takes a few minutes and shows up below. Nothing is sent.');
                } elseif ($act === 'chk_dry_delete') { rs_prq_dry_delete((string)($_POST['name'] ?? '')); rs_flash('Deleted.'); }
                header('Location: sdk-support.php?tab=check&sub=' . $chkSubPost); exit;
            }
            if (!$a) throw new RuntimeException('Pick a recomp first.');
            if ($act === 'chk_fresh') { rs_sdk_health($a, true); rs_pc_sdk_check($a, true, true); rs_flash('Checked again.'); }
            elseif ($act === 'chk_test') {
                $models = array_values(array_intersect((array)($_POST['models'] ?? []), ['haiku', 'sonnet', 'opus', 'haiku+sonnet', 'sonnet+opus']));
                if (!$models) throw new RuntimeException('Tick at least one model.');
                rs_prq_dry_start([$a['id']], $models);
                rs_flash('Test draft started with ' . count($models) . ' model' . (count($models) === 1 ? '' : 's') . '. Each takes a few minutes and shows up below. Nothing is saved to the queue or sent.');
            } elseif ($act === 'chk_queue') {
                rs_prq_queue([$a['id']]);
                $n = rs_prq_now_ready() ? rs_prq_run([$a['id']]) : 0;
                rs_flash($n ? 'Drafting it for the PR queue now.' : 'In the PR queue for the next run (or it\'s already drafted there).');
            } elseif ($act === 'chk_dry_queue') {
                $why = rs_prq_dry_to_queue((string)($_POST['name'] ?? ''));
                if ($why !== '') throw new RuntimeException($why);
                rs_flash('In the PR queue. Look it over there and Send.');
            } elseif ($act === 'chk_dry_here') {
                $r = rs_prq_dry_get((string)($_POST['name'] ?? ''));
                $j = is_array($r['draft']['json'] ?? null) ? $r['draft']['json'] : null;
                if (!$j) throw new RuntimeException('That test draft has no recomplauncher.json.');
                $why = rs_prq_use_here($a, $j);
                if ($why !== '') throw new RuntimeException($why);
                rs_sdk_health($a, true);
                rs_flash('RecompLauncher uses this file for ' . $a['name'] . ' now (our profile), until the developer ships their own.');
            } elseif ($act === 'chk_dry_delete') { rs_prq_dry_delete((string)($_POST['name'] ?? '')); rs_flash('Deleted.'); }
        } catch (Throwable $e) { rs_flash($e->getMessage(), 'err'); if ($chkSubPost) { header('Location: sdk-support.php?tab=check&sub=' . $chkSubPost); exit; } }
        header('Location: sdk-support.php?tab=check' . ($a ? '&app=' . rawurlencode($a['id']) : '')); exit;
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
        header('Location: sdk-support.php?tab=queue&show=' . ($started ? 'drafting' : 'waiting')); exit;
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
                    // (4.11) Never by accident: "I've looked it over" ticked, and nothing that stops it (checked here too).
                    if (empty($_POST['looked'])) throw new RuntimeException('Tick "I\'ve looked it over" first. Nothing was sent; your edits are saved.');
                    $rv = rs_prq_review((array)rs_prq_get($qid));
                    if ($rv['blockers']) throw new RuntimeException('Not sent: ' . $rv['blockers'][0] . ' Your edits are saved.');
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
            } elseif ($act === 'prq_reply_ok') {
                // (4.12) Answered somewhere else (Discord, email): it goes back to Sent.
                $rid = (string)($_POST['app'] ?? '');
                if ($rid !== '') { rs_prq_reply_ok($rid); rs_log('sdk', $rid, 'developer reply marked answered'); }
                rs_flash('Marked answered. It\'s back with the sent ones.', 'ok');
            } elseif ($act === 'prq_auto') {
                if (!rs_is_owner()) throw new RuntimeException('Only the owner can change that.');
                rs_set_setting('prq_auto_green', !empty($_POST['on']) ? '1' : '0');
                rs_flash(!empty($_POST['on']) ? 'Green drafts go in line by themselves now.' : 'Every draft waits for you again.', 'ok');
            } elseif ($act === 'prq_unskip') {
                // (4.11) Back on the list: it can be picked and drafted again.
                rs_db()->prepare("DELETE FROM pc_prq WHERE id = ? AND status = 'skipped'")->execute([$qid]);
                rs_flash('Back on the list. Draft it from its page, or it can be picked by a daily run.');
                $qid = 0;
            } elseif ($act === 'prq_redo_old') {
                // (4.11) The drafts the old drafter made, written again with the new one.
                $ids = [];
                foreach (rs_prq_list(['draft'], 200) as $d) if ((string)$d['pr_url'] === '' && !rs_prq_review($d)['new']) $ids[] = (string)$d['app_id'];
                $ids = array_slice($ids, 0, 40);
                if ($ids) rs_db()->exec("UPDATE pc_prq SET status = 'todo', json = NULL, body = NULL, note = NULL, found = NULL, code = NULL, body_plain = NULL, error = NULL, updated = NOW() WHERE status = 'draft' AND app_id IN (" . implode(',', array_map(fn($x) => rs_db()->quote($x), $ids)) . ')');
                $n = $ids && rs_prq_now_ready() ? rs_prq_run($ids) : 0;
                rs_flash($n ? 'Writing ' . $n . ' again with the new drafter, a few at a time. They show under Drafting now, then Ready for you.' : 'Nothing to redraft.');
                header('Location: sdk-support.php?tab=queue&show=drafting'); exit;
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
        $to = $act === 'prq_send' ? '&show=' . ((string)(rs_prq_get($qid)['status'] ?? '') === 'outbox' ? 'going' : 'sent') : (in_array($act, ['prq_now', 'prq_reopen', 'prq_redo'], true) ? '&show=drafting' : ($act === 'prq_unqueue' ? '&show=ready' : (in_array($act, ['prq_token', 'prq_ai', 'prq_key'], true) ? '' : '&show=' . rawurlencode((string)($_POST['show'] ?? '')))));
        header('Location: sdk-support.php?tab=queue' . ($to === '&show=' ? '' : $to) . ($qid && $act !== 'prq_send' ? '#q-' . $qid : '')); exit;
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


$tab = in_array($_GET['tab'] ?? '', ['queue', 'lookup', 'check', 'guide'], true) ? (string)$_GET['tab'] : 'list';
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
$sdkState = ['ships' => ['Ships its own file', 'ok'], 'pr' => ['PR or issue open', 'blue'], 'replied' => ['Developer replied', 'hot'], 'queue' => ['In the PR queue', 'blue'], 'no' => ['Won\'t support it', 'warn'], 'locked' => ['Pull requests closed', 'violet'], 'missing' => ['No file yet', 'bad'], 'unknown' => ['Couldn\'t check yet', '']];

rs_head('Launcher support', true, ['assets/sdk-support.css']);
?>
<section class="sdk-head">
  <div>
    <h1>Launcher support</h1>
    <p class="muted">What each recomp gets in RecompLauncher, and the <code>recomplauncher.json</code> that gives it Easy Setup, settings, saves and logs: check a game, draft its file, then send it to the developer or use it ourselves.</p>
  </div>
  <?php if ($tab === 'list'): ?>
  <div class="sdk-head-act">
    <span class="sdk-prog" id="sdk-prog" aria-live="polite"<?= $count['todo'] ? '' : ' hidden' ?>><span class="sdk-spin" aria-hidden="true"></span><span id="sdk-prog-t">Checking <?= number_format($count['all'] - $count['todo']) ?> of <?= number_format($count['all']) ?></span></span>
    <button type="button" class="btn" id="sdk-all-b">Check all again</button>
  </div>
  <?php endif; ?>
</section>
<nav class="sdk-tabs" aria-label="Launcher support">
  <a href="sdk-support.php"<?= $tab === 'list' ? ' class="on" aria-current="page"' : '' ?>>Games<?= $listN ? '<span>' . number_format($listN) . '</span>' : '' ?></a>
  <a href="sdk-support.php?tab=queue"<?= $tab === 'queue' ? ' class="on" aria-current="page"' : '' ?>>PR queue<?= $draftN ? '<span class="hot" title="Ready for you to look over">' . $draftN . '</span>' : '' ?></a>
  <a href="sdk-support.php?tab=check"<?= in_array($tab, ['check', 'lookup'], true) ? ' class="on" aria-current="page"' : '' ?>>Check a game</a>
  <a href="sdk-support.php?tab=guide"<?= $tab === 'guide' ? ' class="on" aria-current="page"' : '' ?>>Guide</a>
</nav>
<?php if ($tab === 'list'): ?>
<div class="sdk-kpis" id="sdk-kpis" role="group" aria-label="Filter by status">
  <?php foreach (['missing', 'queue', 'pr', 'replied', 'ships', 'no', 'locked', 'unknown'] as $k): ?>
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
<?php if ($tab === 'queue') require __DIR__ . '/inc/sdk-queue-tab.php'; ?>
<?php if ($tab === 'guide') require __DIR__ . '/inc/sdk-guide-tab.php'; ?>
<?php if ($tab === 'check') require __DIR__ . '/inc/sdk-check-tab.php'; ?>
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
