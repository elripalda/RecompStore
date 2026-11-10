<?php
// (4.4) Recomp Achievements: what RecompLauncher and the site call.
// Signed in = the launcher's PC token (X-RL-Token or Authorization: Bearer), or the site's account cookie.
//
// Open to everyone:
//   GET  a=games                                    -> {games[{id, name, cover, icon, console, achievements, points, players, mastered}]}
//   GET  a=board&game=?                              -> {board[{rank, username, avatar, points, ranked, unlocks, mastered}]}  (Ranked points)
//   GET  a=stats                                     -> {stats{players, unlocks, ranked, mastered, games, testers}}
// Any signed-in account:
//   GET  a=status                                   -> {tester, apply_open, terms, needs_name, application{status, applied, decided}|null}
//   POST a=apply {agree: true, terms, os, plays, why?, discord?} -> {status}   (os: windows, linux, mac, steamdeck)
// Testers only (403 otherwise):
//   GET  a=access                                   -> {}  (older launchers' check)
//   GET  a=profile                                  -> {profile{points, rank, players, unlocks, ranked, mastered_count, games[], mastered[], recent[]}}
//   POST a=session-start {game, version, casual?, started?, checkins?, ended?, save_loaded?, local?, unlock_folder?, native?, console?}
//        -> {session}. casual: why this whole session can't be Ranked ("imported_save"...).
//        A session sent late (offline, or the game closed first) carries its own times, all unix:
//        started, checkins[], ended, save_loaded[]. local = the launcher's session id (sent twice, same
//        session back). unlock_folder = where the ReXGlue unlock file is, as {game}, %APPDATA%,
//        %LOCALAPPDATA%, %USERPROFILE% or %DOCUMENTS% plus the rest. native = the game's achievement table
//        read from the player's copy [{id, title, description, gamerscore}]: a game with no list yet gets one
//        made from it.
//   POST a=checkin {session, casual?, save_loaded?} -> {}
//   POST a=unlocks {session, unlocks: [{id, source, at, casual?, reason?, earlier?, title?, description?, gamerscore?}]}
//        -> {results: [{id, ranked, reason, new}], mastered}
//   GET  a=list&game=                                -> {list}  (404 when the game has none)
//   GET  a=mine&game=                                -> {unlocks[{id, source, points, at, ranked, reason}], points, mastered}
//   POST a=reset {game}                              -> {cleared}  (dev tools: this account's unlocks for one game)
declare(strict_types=1);

$a = (string)($_GET['a'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
// The open calls are the same for everyone: a short cache in front of the database.
if ($method === 'GET' && in_array($a, ['games', 'board', 'stats'], true)) {
    require_once dirname(__DIR__) . '/admin/inc/jcache.php';
    $g = preg_replace('/[^a-z0-9-]/', '', (string)($_GET['game'] ?? ''));
    rs_json_cache('ach-' . $a . ($g !== '' ? '-' . substr($g, 0, 60) : ''), 60);
}

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

$in = [];
if ($method === 'POST') {
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 262144), true);
    if (!is_array($in)) $in = [];
}

try {
    if ($a === 'games') rs_ach_out(200, ['games' => rs_ach_games()]);
    if ($a === 'board') {
        $game = (string)($_GET['game'] ?? '');
        if ($game !== '' && !rs_ach_game_ok($game)) rs_ach_out(400, ['error' => 'game is a recomp id.']);
        rs_ach_out(200, ['board' => rs_ach_board($game, 100)]);
    }
    if ($a === 'stats') rs_ach_out(200, ['stats' => rs_ach_stats()]);
} catch (Throwable $e) {
    error_log('ach api: ' . $e->getMessage());
    rs_ach_out(500, ['error' => 'Something went wrong on our side.']);
}

// Signed in: the launcher's token first, else the site's cookie. A cookie only counts for a POST
// that comes from one of our own sites.
$acct = null; $byCookie = false;
if (rs_acct_on()) {
    $dev = rs_rl_device();
    if ($dev) [$acct] = $dev;
    elseif (($me = rs_acct_current())) { $acct = $me; $byCookie = true; }
}
if (!$acct) rs_ach_out(401, ['error' => 'Sign in first.']);
if ($byCookie && $method === 'POST') {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    $host = strtolower((string)parse_url($origin, PHP_URL_HOST));
    if (!in_array($host, ['recompstore.com', 'recomplauncher.com', 'www.recompstore.com', 'www.recomplauncher.com'], true)) rs_ach_out(403, ['error' => 'Not from our site.']);
}
if (rs_acct_try_count('ach', 'a' . (int)$acct['id'], 60) >= 240) rs_ach_out(429, ['error' => 'Too many at once. Try again in a minute.']);
rs_acct_try_add('ach', 'a' . (int)$acct['id']);

try {
    if ($a === 'status') rs_ach_out(200, rs_ach_status($acct));
    if ($a === 'apply') {
        if ($method !== 'POST') rs_ach_out(405, ['error' => 'POST']);
        if (rs_acct_try_count('ach-apply', 'a' . (int)$acct['id'], 60) >= 5) rs_ach_out(429, ['error' => 'Try again in a few minutes.']);
        rs_acct_try_add('ach-apply', 'a' . (int)$acct['id']);
        $err = rs_ach_apply($acct, $in);
        if ($err !== null) rs_ach_out(400, ['error' => $err]);
        rs_ach_out(200, rs_ach_status($acct));
    }

    if (!rs_ach_is_tester($acct)) rs_ach_out(403, ['error' => 'Recomp Achievements is in alpha. Apply to be a tester.']);
    if ($a === 'access') rs_ach_out(200, []);
    if ($a === 'profile') rs_ach_out(200, ['profile' => rs_ach_profile((int)$acct['id'])]);
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
