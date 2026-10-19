<?php
// Recomp Store admin (2.0): a picture a player added to a bug report on their phone. They're kept
// where the web can't reach them (rs_report_dir); only a logged-in admin sees them, through here.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_require_login();
rs_report_tables();

$st = rs_db()->prepare('SELECT file, mime FROM inbox_pictures WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$p = $st->fetch();
$path = $p ? rs_report_dir() . '/' . basename((string)$p['file']) : '';
if (!$p || !is_file($path) || !in_array($p['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
    http_response_code(404);
    exit('That picture isn\'t here anymore.');
}
header('Content-Type: ' . $p['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'');
header('Cache-Control: private, max-age=3600');
if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="' . basename((string)$p['file']) . '"');
readfile($path);
