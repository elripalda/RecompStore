<?php
// Recomp Store admin (2.0): whether this browser is logged into the admin. The website asks while
// its 2.0 side is "Only me" (Settings), to show the admin the preview. Here under /admin because
// the admin's session cookie only goes to /admin. No session is started for anyone else.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$admin = false;
if (isset($_COOKIE['rs_admin']) && is_string($_COOKIE['rs_admin']) && preg_match('/^[A-Za-z0-9,-]{16,256}$/', $_COOKIE['rs_admin'])) {
    rs_session();
    $admin = !empty($_SESSION['uid']);
    session_write_close();
}
echo json_encode(['ok' => true, 'admin' => $admin]);
