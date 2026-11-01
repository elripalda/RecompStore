<?php
// RecompLauncher (3.1g): the launcher's update check, and the build downloads it points at.
//   GET platform=windows|linux|macos   -> {ok, version, notes, size, sha256, url, published, github}
//                                         (version null: nothing published for that platform yet)
//   GET dl=<id>&s=<sig>                 -> the build itself
// The launcher asks when it opens (and now and then while it's open); when "version" is newer
// than its own, it offers the update and downloads "url", checking "sha256".
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/betawave.php';

header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');

if (isset($_GET['dl'])) {
    $id = (int)$_GET['dl'];
    if (!hash_equals(rs_lb_sig($id), (string)($_GET['s'] ?? ''))) { http_response_code(403); header('Content-Type: text/plain'); echo 'Not a good link.'; exit; }
    $b = rs_lb_get($id);
    if (!$b) { http_response_code(404); header('Content-Type: text/plain'); echo 'That build is gone.'; exit; }
    rs_lb_send($b);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$p = strtolower((string)($_GET['platform'] ?? 'windows'));
if (!isset(RS_LB_PLATFORMS[$p])) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'Unknown platform.']); exit; }
// (3.6) launcher/version.json is answered from here too (launcher/.htaccess), so a new release on
// GitHub reaches launchers without anyone touching the server. If anything goes wrong, the last
// version.json written goes out instead.
try {
    $feed = rs_lb_feed($p);
    if ($p === 'windows') rs_lb_refresh_json();
    echo json_encode($feed, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('launcher-update.php: ' . $e->getMessage());
    $f = RS_PUBLIC . '/launcher/version.json';
    if ($p === 'windows' && is_file($f)) readfile($f);
    else { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'Try again in a moment.']); }
}
