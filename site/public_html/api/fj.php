<?php
// RecompStore website (4.9): a project's releases on a developer's own Forgejo server (Longshot on
// git.magicbean.dev), through this site, in the same JSON shape /api/gh.php gives for a GitHub repo.
// Only servers on the admin's list are asked (Settings, forgejo_hosts). Cached 15 minutes in the
// database; the last copy (any age) goes out with X-RS-Stale: 1 when the server can't be reached.
//   GET ?repo=server/owner/name
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');

$repo = trim((string)($_GET['repo'] ?? ''));
$repo = strtolower((string)strtok($repo, '/')) . substr($repo, strlen((string)strtok($repo, '/')));
if (!rs_fj_path_ok($repo)) {
    http_response_code(400);
    echo '{"message":"Ask for a project as server/owner/name, on a server we read."}';
    exit;
}
try {
    $r = rs_fj_releases($repo);
} catch (Throwable $e) {
    rs_errlog('fj', $e);
    http_response_code(503);
    echo '{"message":"recompstore.com can\'t ask that server right now."}';
    exit;
}
http_response_code($r['status']);
header('Cache-Control: public, max-age=' . ($r['status'] === 200 ? 120 : 30));
header('X-RS-Cache-Age: ' . (int)$r['age']);
if ($r['stale']) header('X-RS-Stale: 1');
echo $r['body'];
