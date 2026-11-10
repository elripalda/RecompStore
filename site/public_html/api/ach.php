<?php
// (4.4) Recomp Achievements: what RecompLauncher calls (its PC's token in X-RL-Token or
// Authorization: Bearer). Test accounts only for now (admin: Recomp Achievements > Testers).
//   GET  a=access                                   -> {ok: true} for test accounts (403 otherwise)
//   POST a=session-start {game, version, casual?, started?, checkins?, ended?, save_loaded?, local?, unlock_folder?, native?}
//        -> {session}. casual: why this whole session can't be Ranked ("imported_save"...).
//        A session sent late (offline, or the game closed first) carries its own times, all unix:
//        started, checkins[], ended, save_loaded[]. local = the launcher's session id (sent twice, same
//        session back). unlock_folder = where the ReXGlue unlock file is, as {game}, %APPDATA%,
//        %LOCALAPPDATA%, %USERPROFILE% or %DOCUMENTS% plus the rest. native = the recomp's native_list.
//   POST a=checkin {session, casual?, save_loaded?} -> {}
//   POST a=unlocks {session, unlocks: [{id, source, at, casual?, reason?, earlier?, title?, description?, gamerscore?}]}
//        -> {results: [{id, ranked, reason, new}], mastered}
//   GET  a=list&game=                                -> {list}  (404 when the game has none)
//   GET  a=mine&game=                                -> {unlocks[{id, source, points, at, ranked, reason}], points, mastered}
//   POST a=reset {game}                              -> {cleared}  (dev tools: this account's unlocks for one game)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/rl_cloud.php';
require_once dirname(__DIR__) . '/admin/inc/ach.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Content-Type: application/json; charset=utf-8');

function rs_ach_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode(['ok' => $code < 400] + $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$a = (string)($_GET['a'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$in = [];
if ($method === 'POST') {
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 262144), true);
    if (!is_array($in)) $in = [];
}

$dev = rs_acct_on() ? rs_rl_device() : null;
if (!$dev) rs_ach_out(401, ['error' => 'Sign in to RecompLauncher first.']);
[$acct] = $dev;
if (!rs_ach_is_tester($acct)) rs_ach_out(403, ['error' => 'Recomp Achievements isn\'t open yet.']);
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (rs_acct_try_count('ach', 'a' . (int)$acct['id'], 60) >= 240) rs_ach_out(429, ['error' => 'Too many at once. Try again in a minute.']);
rs_acct_try_add('ach', 'a' . (int)$acct['id']);

try {
    if ($a === 'access') rs_ach_out(200, []);
    if ($a === 'reset') {
        if ($method !== 'POST') rs_ach_out(405, ['error' => 'POST']);
        $game = (string)($in['game'] ?? '');
        if (!rs_ach_game_ok($game)) rs_ach_out(400, ['error' => 'game is a recomp id.']);
        rs_ach_out(200, ['cleared' => rs_ach_reset((int)$acct['id'], $game)]);
    }
    if ($a === 'session-start') {
        if ($method !== 'POST') rs_ach_out(405, ['error' => 'POST']);
        $game = (string)($in['game'] ?? '');
        if (!rs_ach_game_ok($game) || !rs_rl_app($game)) rs_ach_out(404, ['error' => 'That recomp isn\'t on RecompLauncher.']);
        $sid = rs_ach_session_start((int)$acct['id'], $game, (string)($in['version'] ?? ''), preg_replace('/[^a-z0-9_:]/', '', strtolower((string)($in['casual'] ?? ''))), $in);
        rs_ach_out(200, ['session' => $sid]);
    }
    if ($a === 'checkin' || $a === 'unlocks') {
        if ($method !== 'POST') rs_ach_out(405, ['error' => 'POST']);
        $s = rs_ach_session((int)$acct['id'], (string)($in['session'] ?? ''));
        if (!$s) rs_ach_out(404, ['error' => 'No such session.']);
        if ($a === 'checkin') {
            rs_ach_checkin($s, preg_replace('/[^a-z0-9_]/', '', strtolower((string)($in['casual'] ?? ''))), !empty($in['save_loaded']));
            rs_ach_out(200, []);
        }
        $list = $in['unlocks'] ?? null;
        if (!is_array($list) || !array_is_list($list)) rs_ach_out(400, ['error' => 'unlocks is a list.']);
        [$results, $mastered] = rs_ach_record($acct, $s, $list);
        rs_ach_out(200, ['results' => $results, 'mastered' => $mastered]);
    }
    if ($a === 'list' || $a === 'mine') {
        $game = (string)($_GET['game'] ?? '');
        if (!rs_ach_game_ok($game)) rs_ach_out(400, ['error' => 'game is a recomp id.']);
        if ($a === 'mine') rs_ach_out(200, rs_ach_mine((int)$acct['id'], $game));
        $l = rs_ach_list_for_launcher($game);
        if (!$l) rs_ach_out(404, ['error' => 'No achievements for this recomp yet.']);
        rs_ach_out(200, ['list' => $l]);
    }
    rs_ach_out(400, ['error' => 'Unknown call.']);
} catch (Throwable $e) {
    error_log('ach api: ' . $e->getMessage());
    rs_ach_out(500, ['error' => 'Something went wrong on our side.']);
}
