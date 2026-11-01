<?php
// RecompStore admin (3.1g): saves a beta email ticket picture (drawn in the admin's browser) to
// /assets/email/. Only the ticket names, only JPEGs, only for a signed-in admin.
//   POST ?name=beta-wave-1 … beta-wave-5 | beta-pass   (raw JPEG bytes, X-CSRF header) -> {ok, url}
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function out(int $code, array $b): void { http_response_code($code); echo json_encode($b, JSON_UNESCAPED_SLASHES); exit; }

rs_session();
if (empty($_SESSION['uid'])) out(401, ['ok' => false, 'error' => 'Sign in to the admin again.']);
$t = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) out(400, ['ok' => false, 'error' => 'This page expired. Reload it.']);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'Not like that.']);

$name = (string)($_GET['name'] ?? '');
if (!preg_match('/^beta-(wave-[1-5]|pass)$/', $name)) out(422, ['ok' => false, 'error' => 'Not a ticket name.']);
$data = (string)file_get_contents('php://input', false, null, 0, (2 << 20) + 1);
if ($data === '' || strlen($data) > (2 << 20)) out(422, ['ok' => false, 'error' => 'Empty or too big.']);
if (substr($data, 0, 3) !== "\xFF\xD8\xFF") out(422, ['ok' => false, 'error' => 'Not a JPEG.']);
$dir = RS_PUBLIC . '/assets/email';
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) out(500, ['ok' => false, 'error' => 'Could not make the folder.']);
if (file_put_contents($dir . '/' . $name . '.jpg', $data) === false) out(500, ['ok' => false, 'error' => 'Could not save it.']);
rs_log('email-asset', $name, strlen($data) . ' bytes');
out(200, ['ok' => true, 'url' => 'https://recompstore.com/assets/email/' . $name . '.jpg', 'bytes' => strlen($data), 'sha256' => hash('sha256', $data)]);
