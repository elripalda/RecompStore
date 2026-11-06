<?php
// RecompLauncher website (3.1): what the launcher calls for accounts and cloud saves, and what the
// account page calls for "RecompLauncher on your PCs" and "Cloud saves". docs: LAUNCHER-API.md.
//
// The launcher (no cookies; its PC's token in X-RL-Token or Authorization: Bearer):
//   POST a=link-start {device, os, version}  -> {code, poll, url, interval, expires}
//   POST a=link-poll {poll}                   -> {status: pending|ok|expired|denied, token?, account?}
//   GET  a=me                                 -> {account, saves: {used, quota}}
//   POST a=signout                            -> this PC signed out
//   GET  a=saves&game=                        -> {versions[], used, quota}   (no game: {games[]})
//   PUT  a=save-put&game=&hash=&made=&played=&device=   body: the copy (zip) -> {v, used, quota}
//   GET  a=save-get&game=&v=                  -> the copy (zip)
//   POST a=save-delete {game, v}
//   (4.1) While cloud saves are paused (Settings), these four and the account page's cloud calls answer
//         423 {ok: false, error: "Cloud saves are paused ..."}.
//   (3.2) GET a=inbox -> {items[]} (games sent from the website)   POST a=inbox-done {id, result}
//   (3.2) GET a=betas -> {betas[]}   POST a=beta-join {id}   POST a=beta-leave {id}
// The launcher, signed in or not (an install ID it made itself):
//   (3.2) POST a=report {install, app, verdict, version, note, mins, gpu, cpu, ram_gb, osver}
//         (3.9) and specs (free text, 600). For a log, and the 15-character note rule, use api/pc.php a=feedback.
//   (3.2) POST a=hello {install, version, os, osver, cpu, cores, ram_gb, gpu, gpu_driver, screen, lang, pads, games, bigpicture}
//   (3.2) POST a=events {install, events: [{kind, at, game, version, secs, code, detail}]}
// The account page (its sign-in cookie; POSTs from the site's own pages only):
//   GET  a=pcs                -> {pcs[]}          POST a=pc-remove {id}
//   GET  a=cloud              -> {games[], used, quota}
//   GET  a=cloud-get&game=&v= -> the copy (zip)   POST a=cloud-delete {game, v}
//   (3.2) GET a=send-pcs&game= -> {pcs[]} (the PCs that can run it)   POST a=send {game, pc}
//   (3.2) GET a=my-betas -> {betas[]}   POST a=web-beta-join {id}   POST a=web-beta-leave {id}
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/rl_cloud.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function rs_rl_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $code < 400] + $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function rs_rl_err(int $code, string $why): void { rs_rl_out($code, ['ok' => false, 'error' => $why]); }
// (4.1) A refused cloud save upload, noted (time, game, size, reason) so the admin can see why saves
// don't go up. Kept small: the file starts over past 2 MB.
function rs_rl_put_err(int $code, string $why, string $game = '', string $note = ''): void {
    $f = dirname(RS_PUBLIC) . '/recompstore-private/rl-put-errors.log';
    if (@filesize($f) > 2 << 20) @unlink($f);
    @file_put_contents($f, gmdate('c') . "\t$code\t" . substr($game, 0, 80) . "\t" . (int)($_SERVER['CONTENT_LENGTH'] ?? 0) . "\t$why" . ($note !== '' ? " ($note)" : '') . "\n", FILE_APPEND | LOCK_EX);
    rs_rl_err($code, $why);
}

$a = (string)($_GET['a'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$in = [];
if ($method === 'POST') {
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true);
    if (!is_array($in)) $in = [];
}
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');

// (4.1) Cloud saves paused (Settings, Cloud saves): the launcher's cloud calls, and the account page's,
// answer 423 with a plain reason, before anything touches the account or the storage.
const RS_RL_SAVES_PAUSED = 'Cloud saves are paused while we move them to new storage. Your saves on this PC are safe.';
if (in_array($a, ['saves', 'save-get', 'save-put', 'save-delete', 'cloud', 'cloud-get', 'cloud-delete'], true) && rs_setting('rl_saves_paused', '0') === '1') {
    rs_rl_err(423, RS_RL_SAVES_PAUSED);
}

try {
    if (!rs_acct_on() && !in_array($a, ['report', 'hello', 'events'], true)) rs_rl_err(503, 'Accounts aren\'t open yet. They\'re coming soon.');
    // (3.3) The launcher's inbox check: nothing waiting for this PC (no file), answered without the database.
    if ($a === 'inbox' && $method === 'GET' && is_file(rs_rl_inbox_dir() . '/.ready')) {
        $raw = (string)($_SERVER['HTTP_X_RL_TOKEN'] ?? '');
        if ($raw === '' && preg_match('/^Bearer\s+(\S+)$/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) $raw = $m[1];
        if (preg_match('/^[0-9a-f]{64}$/', $raw) && !is_file(rs_rl_inbox_dir() . '/' . hash('sha256', $raw))) rs_rl_out(200, ['items' => []]);
    }
    rs_rl_tables();
    $space = fn(array $acc) => ['used' => rs_rl_used((int)$acc['id']), 'quota' => RS_RL_QUOTA];

    // ---- the account page (cookie) ----
    if (in_array($a, ['pcs', 'pc-remove', 'cloud', 'cloud-get', 'cloud-delete', 'send-pcs', 'send', 'my-betas', 'web-beta-join', 'web-beta-leave'], true)) {
        $me = rs_acct_current();
        if (!$me) rs_rl_err(401, 'Sign in first.');
        if ($method === 'POST') {
            $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
            $oh = preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST)));
            if ($origin === '' || $oh !== rs_acct_host() || ($_SERVER['HTTP_X_RS'] ?? '') !== '1') rs_rl_err(403, 'Do that from the website.');
        }
        switch ($a) {
            case 'pcs': rs_rl_out(200, ['pcs' => rs_rl_devices((int)$me['id'])]);
            case 'pc-remove':
                if ($method !== 'POST') rs_rl_err(405, 'POST');
                rs_rl_device_remove((int)$me['id'], (int)($in['id'] ?? 0));
                rs_rl_out(200, ['pcs' => rs_rl_devices((int)$me['id'])]);
            case 'cloud': rs_rl_out(200, ['games' => rs_rl_games((int)$me['id'])] + $space($me));
            case 'cloud-get':
                $g = (string)($_GET['game'] ?? ''); $v = (string)($_GET['v'] ?? '');
                $f = rs_rl_file($me, $g, $v);
                if (!$f) rs_rl_err(404, 'That save is gone.');
                rs_rl_send($f, "$g-save-$v.zip");
            case 'cloud-delete':
                if ($method !== 'POST') rs_rl_err(405, 'POST');
                rs_rl_delete($me, (string)($in['game'] ?? ''), (string)($in['v'] ?? ''));
                rs_rl_out(200, ['games' => rs_rl_games((int)$me['id'])] + $space($me));
            case 'send-pcs':
                rs_rl_out(200, ['pcs' => rs_rl_pcs_for((int)$me['id'], (string)($_GET['game'] ?? ''))]);
            case 'send': {
                if ($method !== 'POST') rs_rl_err(405, 'POST');
                $game = (string)($in['game'] ?? '');
                $pc = (int)($in['pc'] ?? 0);
                $can = array_filter(rs_rl_pcs_for((int)$me['id'], $game), fn($d) => $d['id'] === $pc);
                if (!$can) rs_rl_err(400, 'That PC can\'t run it.');
                rs_rl_send_game((int)$me['id'], $pc, $game);
                rs_rl_out(200, ['pc' => array_values($can)[0]['name']]);
            }
            case 'my-betas': rs_rl_out(200, ['betas' => rs_rl_betas_for((int)$me['id'])]);
            case 'web-beta-join':
                if ($method !== 'POST') rs_rl_err(405, 'POST');
                try { $st = rs_rl_beta_join((int)$me['id'], (int)($in['id'] ?? 0)); } catch (RuntimeException $e) { rs_rl_err(400, $e->getMessage()); }
                rs_rl_out(200, ['status' => $st, 'betas' => rs_rl_betas_for((int)$me['id'])]);
            case 'web-beta-leave':
                if ($method !== 'POST') rs_rl_err(405, 'POST');
                rs_rl_beta_leave((int)$me['id'], (int)($in['id'] ?? 0));
                rs_rl_out(200, ['betas' => rs_rl_betas_for((int)$me['id'])]);
        }
    }

    // ---- the launcher, signed in or not ----
    if (in_array($a, ['report', 'hello', 'events'], true)) {
        if ($method !== 'POST') rs_rl_err(405, 'POST');
        $install = (string)($in['install'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $install)) rs_rl_err(400, 'No install ID.');
        $dev = rs_acct_on() ? rs_rl_device() : null;
        $acc = $dev ? $dev[0] : null;
        if (rs_acct_try_count('rl-' . $a, $ip, 60) >= ($a === 'report' ? 30 : 120)) rs_rl_err(429, 'Too many at once. Try again later.');
        rs_acct_try_add('rl-' . $a, $ip);
        rs_rl_more_tables();
        if ($a === 'hello') { rs_rl_hello($install, $acc, $in); rs_rl_out(200, []); }
        if ($a === 'events') rs_rl_out(200, ['kept' => rs_rl_events($install, (array)($in['events'] ?? []))]);
        $app = (string)($in['app'] ?? '');
        if (!rs_rl_app($app)) rs_rl_err(404, 'That recomp isn\'t on RecompLauncher.');
        $verdict = (string)($in['verdict'] ?? '');
        if (!in_array($verdict, ['runs', 'issues', 'broken'], true)) rs_rl_err(422, 'Pick how it plays.');
        $c = fn(string $k, int $max) => rs_rl_clean((string)($in[$k] ?? ''), $max);
        rs_rl_report(['app' => $app, 'verdict' => $verdict, 'sys' => in_array($in['sys'] ?? '', ['windows', 'linux', 'macos', 'deck'], true) ? $in['sys'] : 'windows',
            'version' => preg_replace('/[^\w .+-]/u', '', $c('version', 40)) ?? '', 'note' => $c('note', 1000), 'specs' => is_string($in['specs'] ?? null) ? mb_substr($in['specs'], 0, 2000) : '', 'gpu' => $c('gpu', 160), 'cpu' => $c('cpu', 120),
            'ram_gb' => max(0, min(4096, (int)($in['ram_gb'] ?? 0))), 'osver' => $c('osver', 80), 'mins' => max(0, min(100000, (int)($in['mins'] ?? 0)))], $acc, $install);
        rs_rl_out(200, []);
    }

    // ---- signing a PC in ----
    if ($a === 'link-start') {
        if ($method !== 'POST') rs_rl_err(405, 'POST');
        if (rs_acct_try_count('rl-link', $ip, 10) >= 20) rs_rl_err(429, 'Too many tries. Wait a few minutes and try again.');
        rs_acct_try_add('rl-link', $ip);
        [$code, $poll] = rs_rl_link_start((string)($in['device'] ?? ''), (string)($in['os'] ?? ''), (string)($in['version'] ?? ''));
        rs_rl_out(200, ['code' => $code, 'poll' => $poll, 'url' => 'https://recomplauncher.com/activate?code=' . $code, 'interval' => 3, 'expires' => 600]);
    }
    if ($a === 'link-poll') {
        if ($method !== 'POST') rs_rl_err(405, 'POST');
        rs_rl_out(200, rs_rl_link_poll((string)($in['poll'] ?? '')));
    }

    // ---- everything else: the launcher's token ----
    $dev = rs_rl_device();
    if (!$dev) rs_rl_err(401, 'Sign in again.');
    [$me, $pc] = $dev;
    $game = (string)($_GET['game'] ?? $in['game'] ?? '');
    switch ($a) {
        case 'me':
            rs_rl_out(200, ['account' => rs_rl_user($me), 'saves' => $space($me)]);
        case 'signout':
            rs_rl_device_remove((int)$me['id'], (int)$pc['id']);
            rs_rl_out(200, []);
        case 'saves':
            if ($game === '') rs_rl_out(200, ['games' => rs_rl_games((int)$me['id'])] + $space($me));
            if (!rs_rl_game_ok($game)) rs_rl_err(400, 'That isn\'t a game.');
            rs_rl_out(200, ['versions' => rs_rl_versions((int)$me['id'], $game)] + $space($me));
        case 'save-put': {
            if ($method !== 'PUT' && $method !== 'POST') rs_rl_err(405, 'PUT');
            if (!rs_rl_game_ok($game)) rs_rl_put_err(400, 'That isn\'t a game.', $game);
            $hash = strtolower((string)($_GET['hash'] ?? ''));
            if (!preg_match('/^[0-9a-f]{64}$/', $hash)) rs_rl_put_err(400, 'That isn\'t a save copy.', $game, 'bad hash');
            if (rs_acct_try_count('rl-put', (string)$me['id'], 60) >= 120) rs_rl_err(429, 'Too many uploads. Try again in a while.');
            rs_acct_try_add('rl-put', (string)$me['id']);
            $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($len <= 0) rs_rl_put_err(400, 'Nothing arrived.', $game);
            if ($len > RS_RL_QUOTA) rs_rl_put_err(413, 'That save is bigger than your 150 MB of cloud space.', $game);
            $tmp = tempnam(sys_get_temp_dir(), 'rls');
            $out = fopen($tmp, 'wb');
            $inp = fopen('php://input', 'rb');
            $n = stream_copy_to_stream($inp, $out, RS_RL_QUOTA + 1);
            fclose($inp); fclose($out);
            if ($n !== $len) { @unlink($tmp); rs_rl_put_err(400, 'The upload didn\'t arrive whole. Try again.', $game); }
            if ($why = rs_rl_check_zip($tmp, $note)) { @unlink($tmp); rs_rl_put_err(400, $why, $game, (string)$note); }
            try {
                $r = rs_rl_put($me, $game, $tmp, $hash, (int)($_GET['made'] ?? time()), max(0, (int)($_GET['played'] ?? 0)), (string)($_GET['device'] ?? $pc['name']));
            } catch (RangeException $e) {
                @unlink($tmp);
                rs_rl_put_err(413, $e->getMessage(), $game);
            }
            @unlink($tmp);
            rs_rl_out(200, ['v' => $r['v'], 'same' => $r['same']] + $space($me));
        }
        case 'save-get': {
            $f = rs_rl_file($me, $game, (string)($_GET['v'] ?? ''));
            if (!$f) rs_rl_err(404, 'That save is gone.');
            rs_rl_send($f, "$game.zip");
        }
        case 'inbox': {
            rs_rl_inbox_ready();
            $items = rs_rl_inbox((int)$pc['id']);
            if (!$items) rs_rl_inbox_flag((string)$pc['token_hash'], false);
            rs_rl_out(200, ['items' => $items]);
        }
        case 'inbox-done':
            if ($method !== 'POST') rs_rl_err(405, 'POST');
            rs_rl_inbox_done((int)$pc['id'], (int)($in['id'] ?? 0), (string)($in['result'] ?? 'done'));
            rs_rl_out(200, []);
        case 'betas':
            rs_rl_out(200, ['betas' => rs_rl_betas_for((int)$me['id'])]);
        case 'beta-join':
            if ($method !== 'POST') rs_rl_err(405, 'POST');
            try { $st = rs_rl_beta_join((int)$me['id'], (int)($in['id'] ?? 0)); } catch (RuntimeException $e) { rs_rl_err(400, $e->getMessage()); }
            rs_rl_out(200, ['status' => $st, 'betas' => rs_rl_betas_for((int)$me['id'])]);
        case 'beta-leave':
            if ($method !== 'POST') rs_rl_err(405, 'POST');
            rs_rl_beta_leave((int)$me['id'], (int)($in['id'] ?? 0));
            rs_rl_out(200, ['betas' => rs_rl_betas_for((int)$me['id'])]);
        case 'save-delete':
            if ($method !== 'POST') rs_rl_err(405, 'POST');
            rs_rl_delete($me, $game, (string)($in['v'] ?? ''));
            rs_rl_out(200, ['versions' => rs_rl_versions((int)$me['id'], $game)] + $space($me));
    }
    rs_rl_err(400, 'Unknown request.');
} catch (Throwable $e) {
    if (function_exists('rs_errlog')) rs_errlog('launcher.php', $e);
    rs_rl_err(500, 'Something went wrong on the website. Try again in a moment.');
}
