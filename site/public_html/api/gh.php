<?php
// Recomp Store website (2.0): a GitHub repo's releases, through this site. The same JSON GitHub's
// /repos/{repo}/releases?per_page=30 gives, trimmed, cached 15 minutes, so every PS5 asking about
// the same repo costs GitHub one question (and the admin's token, when set, lifts its limit).
// When GitHub can't be reached, the last copy (any age) with X-RS-Stale: 1.
//   GET ?repo=owner/name
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');

$repo = trim((string)($_GET['repo'] ?? ''));
if (!rs_repo_ok($repo)) {
    http_response_code(400);
    echo '{"message":"Ask for a repo as owner/name."}';
    exit;
}
try {
    $r = rs_gh_releases($repo);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{"message":"recompstore.com can\'t ask GitHub right now."}';
    exit;
}
http_response_code($r['status']);
header('Cache-Control: public, max-age=' . ($r['status'] === 200 ? 120 : 30));
header('X-RS-Cache-Age: ' . (int)$r['age']);
if ($r['stale']) header('X-RS-Stale: 1');
echo $r['body'];
