<?php
// Recomp Store website (2.0): the test catalog, for consoles the admin marked as testers
// (Settings, Testers). The same JSON as catalog.json, built the same way, but made now from the
// admin's games as they're saved (no Publish needed) and with the testers-only apps in, each
// marked "testing": true. The contract: docs/api-2.0.md, section 6.
//   POST a=test  install token store  -> the catalog, or HTTP 403 {ok:false, tester:false, error}
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_catalog_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$s = fn(string $k, int $max): string => isset($_POST[$k]) && is_string($_POST[$k]) ? substr(trim($_POST[$k]), 0, $max) : '';
if (!$post || $s('a', 16) !== 'test') rs_catalog_out(400, ['ok' => false, 'error' => 'Ask with a=test, your console\'s ID and token.']);

try {
    $db = rs_db();
    $install = rs_console($db, $s('install', 16), $s('token', 32));
    if ($install === null) rs_catalog_out(403, ['ok' => false, 'tester' => false, 'error' => 'This console isn\'t known here. Close the store and open it again.']);
    rs_console_seen($db, $install, $s('store', 32));
    if (!rs_console_tester($db, $install)) rs_catalog_out(403, ['ok' => false, 'tester' => false, 'error' => 'This console isn\'t a tester.']);
    rs_catalog_out(200, rs_build_catalog(true));
} catch (Throwable $e) {
    rs_catalog_out(503, ['ok' => false, 'error' => 'The test catalog can\'t be made right now. Try again in a moment.']);
}
