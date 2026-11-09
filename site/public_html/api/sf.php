<?php
// RecompStore website (4.3): a SourceForge project's releases, through this site, in the same JSON
// shape /api/gh.php gives for a GitHub repo (rs_sf_release_list): [{tag_name, name, published_at,
// prerelease, draft, body, html_url, assets: [{name, size, browser_download_url, download_count, md5}]}],
// newest first. Each top folder of the project's files is a release; its downloads are
// https://sourceforge.net/projects/<name>/files/.../download links (never source archives). Cached
// like gh.php: 15 minutes in the database, 30 on disk, and the last copy (any age) with X-RS-Stale: 1
// when SourceForge can't be reached.
//   GET ?repo=name  (the project's short name, as in sourceforge.net/projects/<name>)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');

$repo = strtolower(trim((string)($_GET['repo'] ?? '')));
if (!rs_sf_name_ok($repo)) {
    http_response_code(400);
    echo '{"message":"Ask for a project by its short name."}';
    exit;
}
$gf = rs_sf_disk_file($repo);
$gage = is_file($gf) ? time() - (int)filemtime($gf) : PHP_INT_MAX;
if ($gage < 1800) {
    header('Cache-Control: public, max-age=120');
    header('X-RS-Cache-Age: ' . $gage);
    echo rs_sf_with_digests($repo, (string)file_get_contents($gf));
    exit;
}
try {
    $r = rs_sf_releases($repo);
} catch (Throwable $e) {
    rs_errlog('sf', $e);
    if (is_file($gf)) {
        header('Cache-Control: public, max-age=30');
        header('X-RS-Stale: 1');
        echo rs_sf_with_digests($repo, (string)file_get_contents($gf));
        exit;
    }
    http_response_code(503);
    echo '{"message":"recompstore.com can\'t ask SourceForge right now."}';
    exit;
}
if ($r['status'] === 200 && !$r['stale']) rs_sf_disk_put($repo, $r['body']);
// SourceForge busy or away, and nothing in the database: the disk copy, however old.
if (($r['status'] === 429 || $r['status'] === 502) && is_file($gf)) {
    header('Cache-Control: public, max-age=30');
    header('X-RS-Stale: 1');
    echo rs_sf_with_digests($repo, (string)file_get_contents($gf));
    exit;
}
http_response_code($r['status']);
header('Cache-Control: public, max-age=' . ($r['status'] === 200 ? 120 : 30));
header('X-RS-Cache-Age: ' . (int)$r['age']);
if ($r['stale']) header('X-RS-Stale: 1');
echo $r['status'] === 200 ? rs_sf_with_digests($repo, (string)$r['body']) : $r['body'];
