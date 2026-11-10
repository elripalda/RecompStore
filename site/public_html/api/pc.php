<?php
// RecompStore for PC (2.8): a recomp's README, and what players say about how it runs.
//   GET  ?a=readme&id=<app>            -> {md, raw, blob, url}   (the developer's README, cached a day)
//   GET  ?a=feedback&id=<app>          -> {counts: {runs, issues, broken}, items: [...]}
//   GET  ?a=app&id=<app>              -> {app: {...}}            (one recomp's whole entry, for its page)
//   GET  ?a=counts                     -> {counts: {app: {runs, issues, broken}}}
//   POST a=feedback id verdict sys note version   (one per player and recomp: a new one replaces theirs)
// (4.0) GET a=notifies -> {apps}; POST a=notify app on -> {on}: Notify me when a Coming soon game is out.
// (4.0) POST a=feedback also takes proton=1 (Linux or Steam Deck, the Windows build through Proton). GET
//        feedback gives each item's proton, and proton: {n, runs} for the summary line.
// (3.9) Reports developers can act on:
//   POST a=feedback also takes specs (OS, CPU, GPU, RAM, driver; 600 characters) and, as multipart, a
//        log file (text, 2 MB). Has problems / Won't run need a note of 15 characters or more. Signed
//        in (RecompLauncher's Bearer token, or this site's cookie) it's tied to the account, so the
//        player hears back. -> {ok, id}
//   GET  a=my-reports                  -> {reports[]}  the signed-in player's PC reports, with answers
//   POST a=report-reply id text        -> {ok, replies[]}  the player answers the developer
// Signed in on the website, POSTs come from this site's own pages (Origin and the X-RS header).
declare(strict_types=1);

// (4.1) The two questions asked most (a game's entry, and what players say about it) are answered here,
// before the rest of the site loads: the entry from the cached catalog, the reports from a copy at most
// a minute old. Thousands of these a minute were most of the server's CPU.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && is_string($_GET['a'] ?? null) && is_string($_GET['id'] ?? null)
    && preg_match('/^[a-z0-9-]{1,64}$/', $_GET['id'])) {
    $fastId = $_GET['id'];
    if ($_GET['a'] === 'app') {
        // (4.4) Its own file, written at each publish (admin/inc/pc.php rs_pc_write_app_files).
        $fastA = dirname(__DIR__, 2) . '/recompstore-private/pc-app/' . $fastId . '.json';
        // (4.6) Served whenever it's there, even while a publish is still rewriting them all: a few
        // minutes old is fine for an entry (players cache it 2 minutes anyway). Waiting for "newer than
        // the catalog" sent every request to the whole catalog for the minutes a rewrite takes, which on
        // a busy server made the rewrite slower still.
        if (is_file($fastA)) {
            header('X-Content-Type-Options: nosniff');
            header('Content-Type: application/json; charset=utf-8');
            // (4.6) Cloudflare keeps it an hour (refreshing it in the background), browsers 2 minutes.
            header('Cache-Control: public, max-age=120, s-maxage=3600, stale-while-revalidate=86400, stale-if-error=86400');
            readfile($fastA);
            exit;
        }
        require_once dirname(__DIR__) . '/admin/inc/catcache.php';
        foreach ((array)(rs_json_cached(dirname(__DIR__) . '/pc-catalog.json')['apps'] ?? []) as $fx) {
            if (($fx['id'] ?? '') !== $fastId) continue;
            header('X-Content-Type-Options: nosniff');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: public, max-age=120, s-maxage=3600, stale-while-revalidate=86400, stale-if-error=86400');
            echo json_encode(['ok' => true, 'app' => $fx], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($_GET['a'] === 'feedback') {
        $fastF = dirname(__DIR__, 2) . '/recompstore-private/pc-fb/' . $fastId . '.json';
        if (is_file($fastF) && time() - (int)@filemtime($fastF) < 60) {
            header('X-Content-Type-Options: nosniff');
            header('Content-Type: application/json; charset=utf-8');
            // (4.6) Players' reports: Cloudflare keeps them 5 minutes (refreshing in the background).
            header('Cache-Control: public, max-age=60, s-maxage=300, stale-while-revalidate=3600, stale-if-error=86400');
            readfile($fastF);
            exit;
        }
    }
}

require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/rl_cloud.php'; // (3.9) RecompLauncher's sign-in
require_once dirname(__DIR__) . '/admin/inc/catcache.php';

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

function rs_pc_out(int $code, array $body, int $cache = 0): void {
    http_response_code($code);
    // (4.6) Answers for everyone: Cloudflare may hand out an older copy while it fetches a new one, or
    // while the server is down, rather than every request waiting on the server.
    header('Cache-Control: ' . ($cache > 0 ? 'public, max-age=' . $cache . ', s-maxage=' . max($cache, 300) . ', stale-while-revalidate=86400, stale-if-error=86400' : 'no-store'));
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Only recomps on the PC store's published list.
function rs_pc_listed(string $id): ?array {
    if (!preg_match('/^[a-z0-9-]{1,64}$/', $id)) return null;
    // (4.6) Its own small file first (written at each publish), then the whole catalog.
    $own = dirname(__DIR__, 2) . '/recompstore-private/pc-app/' . $id . '.json';
    if (is_file($own) && is_array($j = json_decode((string)@file_get_contents($own), true)) && is_array($j['app'] ?? null)) return $j['app'];
    // (4.1) The catalog as opcache's cached array, not decoded again on every request (admin/inc/catcache.php).
    $cat = rs_json_cached(RS_PC_CATALOG);
    foreach ((array)($cat['apps'] ?? []) as $a) if (($a['id'] ?? '') === $id) return $a;
    return null;
}

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = $post ? $_POST : $_GET;
// (3.9) The website's account POSTs are JSON.
if ($post && str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json'))
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true) ?: [];
$s = fn(string $k, int $max): string => isset($in[$k]) && is_scalar($in[$k]) ? mb_substr(trim((string)$in[$k]), 0, $max) : '';
$a = $s('a', 16);

// (3.9) Who's sending: RecompLauncher's token, else this site's sign-in. A cookie only counts on a POST
// from the site's own pages, so another site can't send something as you.
function rs_pc_me(bool $post): ?array {
    if (!rs_acct_on()) return null;
    $dev = rs_rl_device();
    if ($dev) return $dev[0];
    if ($post) {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST)));
        $ok = ['recompstore.com', 'recomplauncher.com', rs_acct_host(), preg_replace('/recompstore/', 'recomplauncher', rs_acct_host())];
        if ($origin === '' || !in_array($host, $ok, true)) return null;
    }
    return rs_acct_current();
}
// A cookie-signed POST also needs the X-RS header (the launcher's token needs nothing more).
function rs_pc_cookie_post_ok(): bool {
    return rs_rl_device() !== null || ($_SERVER['HTTP_X_RS'] ?? '') === '1';
}

try {
    if ($a === 'counts' && !$post) rs_pc_out(200, ['ok' => true, 'counts' => (object)rs_pc_feedback_counts()], 120);
    // ---- (3.9) The player's own reports, and answering the developer ----
    if ($a === 'my-reports' && !$post) {
        $me = rs_pc_me(false);
        if (!$me) rs_pc_out(401, ['ok' => false, 'error' => 'Sign in to see your reports.']);
        rs_play_sync_legacy();
        $st = rs_db()->prepare("SELECT * FROM play_reports WHERE kind = 'pc' AND account_id = ? ORDER BY updated DESC LIMIT 100");
        $st->execute([(int)$me['id']]);
        $rows = $st->fetchAll();
        $replies = rs_play_replies(array_column($rows, 'id'));
        // (4.6) Each game's name from its own small file, not the whole catalog decoded per request.
        $names = [];
        foreach (array_unique(array_column($rows, 'app')) as $nid) if (is_array($na = rs_pc_listed((string)$nid))) $names[$nid] = (string)($na['name'] ?? $nid);
        rs_pc_out(200, ['ok' => true, 'reports' => array_map(fn($x) => ['id' => (int)$x['id'], 'app' => (string)$x['app'], 'name' => (string)($names[$x['app']] ?? $x['app']),
            'verdict' => (string)$x['verdict'], 'sys' => (string)$x['sys'], 'version' => (string)$x['version'], 'note' => (string)$x['note'],
            'specs' => rs_play_specs_line($x), 'log' => !empty($x['has_log']), 'hidden' => (bool)$x['hidden'],
            'from' => $x['source'] === 'pcfb' ? 'launcher' : 'site', 'when' => gmdate('c', strtotime((string)$x['updated'] . ' UTC')),
            'replies' => $replies[(int)$x['id']] ?? []], $rows)]);
    }
    if ($a === 'report-reply' && $post) {
        $me = rs_pc_me(true);
        if (!$me) rs_pc_out(401, ['ok' => false, 'error' => 'Sign in to answer.']);
        if (!rs_pc_cookie_post_ok()) rs_pc_out(403, ['ok' => false, 'error' => 'Do that from the website.']);
        $r = rs_play_report((int)$s('id', 12));
        if (!$r || (int)($r['account_id'] ?? 0) !== (int)$me['id']) rs_pc_out(404, ['ok' => false, 'error' => 'That report isn\'t yours, or it\'s gone.']);
        $text = trim(str_replace(["\r\n", "\r"], "\n", preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', (string)($in['text'] ?? '')) ?? ''));
        if (($e = rs_cm_body_error($text)) !== null) rs_pc_out(422, ['ok' => false, 'error' => $e]);
        if (rs_rate_over('pc-reply', 20, 3600)) rs_pc_out(429, ['ok' => false, 'error' => 'That\'s a lot of answers in an hour. Try again a bit later.']);
        rs_rate_hit('pc-reply');
        rs_play_reply_add($r, $text, null, (int)$me['id']);
        rs_pc_out(200, ['ok' => true, 'replies' => rs_play_replies([(int)$r['id']])[(int)$r['id']] ?? []]);
    }
    // (4.0) Notify me (RecompLauncher's sign-in, or this site's): the Coming soon games they're waiting on.
    if ($a === 'notifies' && !$post) {
        $me = rs_pc_me(false);
        if (!$me) rs_pc_out(401, ['ok' => false, 'error' => 'Sign in first.']);
        rs_pc_out(200, ['ok' => true, 'apps' => rs_pc_notify_list((int)$me['id'])]);
    }
    if ($a === 'notify' && $post) {
        $me = rs_pc_me(true);
        if (!$me) rs_pc_out(401, ['ok' => false, 'error' => 'Sign in first.']);
        if (!rs_pc_cookie_post_ok()) rs_pc_out(403, ['ok' => false, 'error' => 'Do that from the website.']);
        $x = preg_match('/^[a-z0-9-]{1,64}$/', $s('app', 64)) ? rs_pc_app($s('app', 64)) : null;
        if (!$x || $x['hidden']) rs_pc_out(404, ['ok' => false, 'error' => 'Unknown game.']);
        if (empty($me['email_ok'])) rs_pc_out(422, ['ok' => false, 'error' => 'Confirm your email first. The link is in the email we sent you.']);
        try { rs_pc_notify_set((int)$me['id'], (string)$x['id'], !empty($in['on'])); } catch (RuntimeException $e) { rs_pc_out(422, ['ok' => false, 'error' => $e->getMessage()]); }
        rs_pc_out(200, ['ok' => true, 'on' => !empty($in['on'])]);
    }
    $app = rs_pc_listed($s('id', 64));
    if ($app === null) rs_pc_out(404, ['ok' => false, 'error' => 'That recomp isn\'t on RecompLauncher.']);
    if ($a === 'app' && !$post) rs_pc_out(200, ['ok' => true, 'app' => $app], 120);
    // (4.6) How a release's files were checked (RecompLauncher's Downloads): for each file, clean or
    // not and who checked it (ClamAV on this server, or VirusTotal). Only the store's own recomps.
    if ($a === 'scan' && !$post) {
        $tag = $s('tag', 120);
        if ($tag === '') rs_pc_out(400, ['ok' => false, 'error' => 'Which version?']);
        $full = rs_pc_app((string)$app['id']);
        $files = [];
        $waiting = false;
        if ($full && $full['repo'] !== '') {
            rs_vt_tables();
            $st = rs_db()->prepare('SELECT name, status, malicious, suspicious, engines, scanner FROM vt_files WHERE repo = ? AND tag = ? ORDER BY name');
            $st->execute([rs_pc_key($full), $tag]);
            $sys = ['windows' => (string)$full['asset_win'], 'linux' => (string)$full['asset_linux'], 'macos' => (string)$full['asset_mac']];
            foreach ($st->fetchAll() as $r) {
                $v = rs_vt_verdict($r);
                if ($v === 'grey') $waiting = true;
                $for = [];
                foreach ($sys as $k => $p) if ($p !== '' && rs_pattern_matches($p, (string)$r['name'])) $for[] = $k;
                $files[] = ['name' => (string)$r['name'], 'for' => $for, 'verdict' => $v, 'scanner' => $r['scanner'] === 'clamav' ? 'clamav' : 'virustotal',
                    'engines' => (int)$r['engines'], 'found' => (int)$r['malicious'] + (int)$r['suspicious']];
            }
        }
        // A check still running is asked about again soon; a finished one stays as it is.
        rs_pc_out(200, ['ok' => true, 'tag' => $tag, 'files' => $files], $files && !$waiting ? 3600 : 120);
    }
    if ($a === 'readme' && !$post) {
        $rh = rs_host_norm((string)($app['host'] ?? '')); // (3.9) GitHub or GitLab
        $r = rs_pc_readme((string)$app['repo'], $rh);
        if ($r['status'] !== 200) rs_pc_out($r['status'] === 404 ? 404 : 503, ['ok' => false, 'error' => $r['status'] === 404 ? 'No README on its ' . rs_host_name($rh) . '.' : rs_host_name($rh) . ' didn\'t answer. Try again in a moment.']);
        rs_pc_out(200, ['ok' => true, 'md' => $r['md'], 'raw' => $r['raw'], 'blob' => $r['blob'], 'url' => $r['url']], 600);
    }
    if ($a === 'feedback' && !$post) {
        $c = rs_pc_feedback_counts()[$app['id']] ?? [];
        // (3.9) With the player's PC in a line, whether a log came with it, and the answers.
        $list = rs_pc_feedback_list($app['id']);
        $play = [];
        if ($list) {
            rs_play_tables();
            $st = rs_db()->query("SELECT id, legacy_id, has_log FROM play_reports WHERE source = 'pcfb' AND legacy_id IN (" . implode(',', array_map(fn($x) => (int)$x['id'], $list)) . ')');
            foreach ($st->fetchAll() as $p) $play[(int)$p['legacy_id']] = $p;
        }
        $replies = rs_play_replies(array_map(fn($p) => (int)$p['id'], $play));
        $items = array_map(fn($x) => ['verdict' => $x['verdict'], 'sys' => $x['sys'], 'proton' => !empty($x['proton']), 'version' => $x['version'], 'note' => $x['note'],
            'specs' => rs_play_specs_line($x), 'log' => !empty($play[(int)$x['id']]['has_log']),
            'replies' => isset($play[(int)$x['id']]) ? ($replies[(int)$play[(int)$x['id']]['id']] ?? []) : [],
            'when' => gmdate('c', strtotime((string)$x['created']))], $list);
        // (4.0) Of the reports played through Proton: how many, and how many say it plays great.
        $px = array_filter($list, fn($x) => !empty($x['proton']));
        $fbBody = ['ok' => true, 'counts' => ['runs' => $c['runs'] ?? 0, 'issues' => $c['issues'] ?? 0, 'broken' => $c['broken'] ?? 0], 'items' => $items,
            'proton' => ['n' => count($px), 'runs' => count(array_filter($px, fn($x) => $x['verdict'] === 'runs'))]];
        // (4.1) A copy for the next minute's askers (answered at the top, before the site loads).
        $fbDir = dirname(RS_PUBLIC) . '/recompstore-private/pc-fb';
        if (!is_dir($fbDir)) @mkdir($fbDir, 0755, true);
        $fbJson = json_encode($fbBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($fbJson !== false && @file_put_contents($fbDir . '/' . $app['id'] . '.json.' . getmypid(), $fbJson) !== false) @rename($fbDir . '/' . $app['id'] . '.json.' . getmypid(), $fbDir . '/' . $app['id'] . '.json');
        rs_pc_out(200, $fbBody, 60); // (4.6) the same for everyone, like the copy above
    }
    if ($a === 'feedback' && $post) {
        if ($s('website', 100) !== '') rs_pc_out(200, ['ok' => true]); // the hidden field: a bot
        $verdict = $s('verdict', 8);
        if (!isset(RS_PC_VERDICTS[$verdict])) rs_pc_out(422, ['ok' => false, 'error' => 'Pick how it plays.']);
        $sys = $s('sys', 12);
        if (!isset(RS_PC_SYSTEMS[$sys])) rs_pc_out(422, ['ok' => false, 'error' => 'Pick what you play it on.']);
        $note = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s('note', 1000)) ?? '';
        // (3.9) A bad report says what happened, so the developer can do something with it.
        if ($verdict !== 'runs' && mb_strlen(trim($note)) < 15) rs_pc_out(422, ['ok' => false, 'error' => 'Tell the developer what happened and when.', 'field' => 'note']);
        $version = preg_replace('/[^\w .+-]/u', '', $s('version', 40)) ?? '';
        $specs = rs_play_specs_clean(isset($in['specs']) && is_string($in['specs']) ? $in['specs'] : '');
        // (4.0) RecompLauncher says when a Linux or Steam Deck player ran the Windows build through Proton.
        $proton = in_array($sys, ['linux', 'deck'], true) && in_array(strtolower($s('proton', 5)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
        // (3.9) A log, checked before anything is saved: text only, 2 MB at most.
        $log = !empty($_FILES['log']) && (int)($_FILES['log']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE ? $_FILES['log'] : null;
        if ($log && ($e = rs_play_log_error($log)) !== null) rs_pc_out(422, ['ok' => false, 'error' => $e, 'field' => 'log']);
        if (rs_rate_over('pc-feedback', 12, 3600)) rs_pc_out(429, ['ok' => false, 'error' => 'That\'s a lot at once. Try again later.']);
        rs_rate_hit('pc-feedback');
        rs_pc_tables();
        // Signed in, the report is the account's (the same one RecompLauncher's own reports use).
        $me = rs_pc_me(true);
        $who = $me ? hash('sha256', 'acct:' . $me['id']) : rs_ip_hash();
        $db = rs_db();
        $db->prepare('INSERT INTO pc_feedback (app, verdict, sys, version, note, ip_hash, created, account_id, specs, proton) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)
            ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), sys = VALUES(sys), version = VALUES(version), note = VALUES(note), created = NOW(), hidden = 0,
            account_id = COALESCE(VALUES(account_id), account_id), specs = IF(VALUES(specs) <> \'\', VALUES(specs), specs), proton = VALUES(proton)')
            ->execute([$app['id'], $verdict, $sys, $version, $note, $who, $me ? (int)$me['id'] : null, $specs, $proton]);
        $fb = $db->prepare('SELECT id FROM pc_feedback WHERE app = ? AND ip_hash = ?');
        $fb->execute([$app['id'], $who]);
        $rid = rs_play_from_pcfb((int)$fb->fetchColumn(), ['app' => $app['id'], 'who' => $who, 'verdict' => $verdict, 'sys' => $sys, 'version' => $version,
            'note' => $note, 'specs' => $specs, 'proton' => $proton], $me ? (int)$me['id'] : null);
        if ($log && $rid) {
            try { rs_play_log_save($rid, $log); } catch (RuntimeException $e) { @unlink(dirname(RS_PUBLIC) . '/recompstore-private/pc-fb/' . $app['id'] . '.json'); rs_pc_out(200, ['ok' => true, 'id' => $rid, 'warning' => 'Your report is in, but the log couldn\'t be kept.']); }
        }
        if ($verdict !== 'runs') rs_play_dev_ping($app['id']);
        @unlink(dirname(RS_PUBLIC) . '/recompstore-private/pc-fb/' . $app['id'] . '.json'); // (4.1) its reports changed
        rs_pc_out(200, ['ok' => true, 'id' => $rid]);
    }
    rs_pc_out(400, ['ok' => false, 'error' => 'Unknown request.']);
} catch (Throwable $e) {
    rs_pc_out(500, ['ok' => false, 'error' => 'Something went wrong here. Try again in a moment.']);
}
