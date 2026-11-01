<?php
// RecompLauncher (3.1g): the launcher's anonymous usage pings.
//   POST (JSON) {install, event: open|heartbeat|game_start|game_stop|close, version, os, os_version,
//                mode: desk|tv, signed_in, session, game: {id, name, version}, seconds}
//   -> {ok: true, next: <seconds until the next heartbeat>}
// The launcher sends nothing when "Share anonymous usage stats" is off.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/lstats.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }
$raw = (string)file_get_contents('php://input', false, null, 0, 4097);
$in = strlen($raw) <= 4096 ? json_decode($raw, true) : null;
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false]); exit; }
try {
    $next = rs_ls_ping($in);
    echo json_encode(['ok' => true, 'next' => $next]);
    // (3.1g) After the answer: a quick look for new releases and catalog changes to tell launchers
    // about (at most once a minute, a few seconds at a time).
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        try { require_once dirname(__DIR__) . '/admin/inc/rlpush.php'; rl_push_watch(6); } catch (Throwable $e) { error_log('rlpush watch: ' . $e->getMessage()); }
    });
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
} catch (Throwable $e) {
    error_log('launcher-ping: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'next' => RS_LS_NEXT]);
}
