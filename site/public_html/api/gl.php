<?php
// RecompStore website (3.9): a GitLab project's releases, through this site, in the same JSON shape
// /api/gh.php gives for a GitHub repo (rs_gl_release_list): [{tag_name, name, published_at,
// prerelease, draft, body, html_url, assets: [{name, size, browser_download_url, download_count}]}],
// newest first, with project_id (3.9). Files are the release's links on https://gitlab.com/<project>/ (never GitLab's
// source archives); GitLab doesn't give sizes, so size is 0. Cached like gh.php: 15 minutes in the
// database, 30 on disk, and the last copy (any age) with X-RS-Stale: 1 when GitLab can't be reached.
//   GET ?repo=group/project  (or group/sub/project, URL-encoded)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');

$repo = trim((string)($_GET['repo'] ?? ''));
if (!rs_gl_path_ok($repo)) {
    http_response_code(400);
    echo '{"message":"Ask for a project as group/project."}';
    exit;
}
$gf = rs_gl_disk_file($repo);
$gage = is_file($gf) ? time() - (int)filemtime($gf) : PHP_INT_MAX;
if ($gage < 1800) {
    header('Cache-Control: public, max-age=120');
    header('X-RS-Cache-Age: ' . $gage);
    readfile($gf);
    exit;
}
try {
    $r = rs_gl_releases($repo);
} catch (Throwable $e) {
    rs_errlog('gl', $e);
    if (is_file($gf)) {
        header('Cache-Control: public, max-age=30');
        header('X-RS-Stale: 1');
        readfile($gf);
        exit;
    }
    http_response_code(503);
    echo '{"message":"recompstore.com can\'t ask GitLab right now."}';
    exit;
}
if ($r['status'] === 200 && !$r['stale']) rs_gl_disk_put($repo, $r['body']);
// GitLab busy or away, and nothing in the database: the disk copy, however old.
if (($r['status'] === 429 || $r['status'] === 502) && is_file($gf)) {
    header('Cache-Control: public, max-age=30');
    header('X-RS-Stale: 1');
    readfile($gf);
    exit;
}
http_response_code($r['status']);
header('Cache-Control: public, max-age=' . ($r['status'] === 200 ? 120 : 30));
header('X-RS-Cache-Age: ' . (int)$r['age']);
if ($r['stale']) header('X-RS-Stale: 1');
echo $r['body'];
