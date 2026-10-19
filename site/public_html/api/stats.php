<?php
// Recomp Store website (2.0): the PS5 store says when it installed or removed an app, so the charts
// can count consoles. Only the console's store ID, the app and its version are kept, once per
// console and app (a reinstall isn't a new install).
//   POST a=installed  install token store app version     POST a=removed  install token store app
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

function rs_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_out(405, ['ok' => false, 'error' => 'From Recomp Store only.']);
$s = fn(string $k, int $max): string => isset($_POST[$k]) && is_string($_POST[$k]) ? substr(trim($_POST[$k]), 0, $max) : '';
$a = $s('a', 16);
$app = $s('app', 48);
if (!in_array($a, ['installed', 'removed'], true)) rs_out(400, ['ok' => false, 'error' => 'Unknown request.']);
if (!preg_match('/^[A-Za-z0-9_.-]{3,48}$/', $app)) rs_out(422, ['ok' => false, 'error' => 'That isn\'t an app.']);
try {
    $db = rs_db();
    $install = rs_console($db, $s('install', 16), $s('token', 32));
    if ($install === null) rs_out(403, ['ok' => false, 'error' => 'This console isn\'t known here.']);
    rs_console_seen($db, $install, $s('store', 32));
    rs_stats_tables();
    $version = preg_replace('/[\x00-\x1F\x7F]/', '', $s('version', 64)) ?? '';
    if ($a === 'installed') {
        $db->prepare('INSERT INTO app_installs (install_id, app_id, version, first_at, last_at, removed_at) VALUES (?, ?, ?, NOW(), NOW(), NULL)
                      ON DUPLICATE KEY UPDATE version = VALUES(version), last_at = NOW(), removed_at = NULL')->execute([$install, $app, $version]);
    } else {
        $db->prepare('UPDATE app_installs SET removed_at = NOW(), last_at = NOW() WHERE install_id = ? AND app_id = ?')->execute([$install, $app]);
    }
} catch (Throwable $e) {
    rs_out(503, ['ok' => false, 'error' => 'Not counted right now.']);
}
rs_out(200, ['ok' => true]);
