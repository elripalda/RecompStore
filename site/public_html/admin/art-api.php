<?php
// RecompStore admin (3.4): the art picker's answers (assets/artpick.js). Signed in only.
//   ?a=sources                         which sources are ready
//   ?a=search&src=..&q=..&origin=..    games by name: [{id, name, year, platform, note}]
//   ?a=images&src=..&id=..&kind=..     one game's pictures of a kind: [{url, thumb, w, h, label}]
//   ?a=img&u=<https url>               the picture itself, from here, so the picker can crop it
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/artsrc.php';
rs_session();
if (empty($_SESSION['uid'])) { http_response_code(403); header('Content-Type: application/json'); echo '{"ok":false,"error":"Sign in again."}'; exit; }
rs_gate(true); // (4.3) part of this login's role and stores
session_write_close();
@set_time_limit(60);

$a = (string)($_GET['a'] ?? '');
$send = function (array $j): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if ($a === 'img') {
    $u = (string)($_GET['u'] ?? '');
    if (!rs_art_host_ok($u)) { http_response_code(400); exit; }
    $r = rs_http($u, ['max' => 25 << 20, 'timeout' => 30]);
    $info = $r['status'] === 200 ? @getimagesizefromstring($r['body']) : false;
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) { http_response_code(404); exit; }
    header('Content-Type: ' . $info['mime']);
    header('Cache-Control: private, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    echo $r['body'];
    exit;
}

$src = (string)($_GET['src'] ?? '');
$sources = rs_art_sources();
if ($a === 'sources') {
    $left = rs_kv_get('art:tgdb-left');
    $send(['ok' => true, 'sources' => $sources, 'tgdbLeft' => $left ? (int)$left['v'] : null]);
}
if (!isset($sources[$src])) $send(['ok' => false, 'error' => 'No such source.']);
if (!$sources[$src]['on']) $send(['ok' => false, 'error' => $sources[$src]['name'] . ' isn\'t set up yet. ' . $sources[$src]['why']]);

try {
    if ($a === 'search') {
        $q = mb_substr((string)($_GET['q'] ?? ''), 0, 120);
        $send(['ok' => true, 'games' => rs_art_search($src, $q, (string)($_GET['origin'] ?? ''))]);
    }
    if ($a === 'images') {
        $id = mb_substr((string)($_GET['id'] ?? ''), 0, 300);
        $kind = (string)($_GET['kind'] ?? 'cover');
        $send(['ok' => true, 'images' => rs_art_images($src, $id, $kind)]);
    }
} catch (Throwable $e) {
    $send(['ok' => false, 'error' => $sources[$src]['name'] . ' didn\'t answer. Try again in a moment.']);
}
$send(['ok' => false, 'error' => 'Unknown request.']);
