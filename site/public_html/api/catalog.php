<?php
// Recomp Store website (2.0): the test catalog, for consoles the admin marked as testers
// (Settings, Testers). The same JSON as catalog.json, built the same way, but made now from the
// admin's games as they're saved (no Publish needed) and with the testers-only apps in, each
// marked "testing": true. The contract: docs/api-2.0.md, section 6.
//   POST a=test  install token store  -> the catalog, or HTTP 403 {ok:false, tester:false, error}
//   POST a=testsig (the same)          -> (2.1) its signature from Recomp Store Signer (text)
// (2.1) Once the signer is set up, the testers' catalog is the one last signed (published with
// Publish like everyone's), not one made now.
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
$a = $s('a', 16);
if (!$post || ($a !== 'test' && $a !== 'testsig')) rs_catalog_out(400, ['ok' => false, 'error' => 'Ask with a=test, your console\'s ID and token.']);

try {
    $db = rs_db();
    $install = rs_console($db, $s('install', 16), $s('token', 32));
    if ($install === null) rs_catalog_out(403, ['ok' => false, 'tester' => false, 'error' => 'This console isn\'t known here. Close the store and open it again.']);
    rs_console_seen($db, $install, $s('store', 32));
    if (!rs_console_tester($db, $install)) rs_catalog_out(403, ['ok' => false, 'tester' => false, 'error' => 'This console isn\'t a tester.']);
    if (rs_sign_on() && is_file(rs_signed_file('catalog-test')) && is_file(rs_sig_file('catalog-test'))) {
        $file = $a === 'testsig' ? rs_sig_file('catalog-test') : rs_signed_file('catalog-test');
        http_response_code(200);
        header($a === 'testsig' ? 'Content-Type: text/plain; charset=utf-8' : 'Content-Type: application/json; charset=utf-8');
        readfile($file);
        exit;
    }
    if ($a === 'testsig') rs_catalog_out(404, ['ok' => false, 'error' => 'The testers\' catalog isn\'t signed yet.']);
    rs_catalog_out(200, rs_build_catalog(true));
} catch (Throwable $e) {
    rs_catalog_out(503, ['ok' => false, 'error' => 'The test catalog can\'t be made right now. Try again in a moment.']);
}
