<?php
// Recomp Store admin: one log file a player sent from the store, as plain text (dl=1: a download).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_require_login();
rs_inbox_table();
$st = rs_db()->prepare('SELECT f.name, f.body, i.app_id, i.created FROM inbox_files f JOIN inbox i ON i.id = f.inbox_id WHERE f.id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch();
if (!$f) { http_response_code(404); exit('That log is gone.'); }
$file = preg_replace('/[^A-Za-z0-9._-]/', '_', $f['app_id'] . '-' . date('Ymd-His', strtotime($f['created'])) . '-' . basename($f['name']));
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $file . '"');
echo $f['body'];
