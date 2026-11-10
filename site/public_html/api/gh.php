<?php
// RecompStore website (2.0): a GitHub repo's releases, through this site. The same JSON GitHub's
// /repos/{repo}/releases?per_page=30 gives, trimmed, cached 15 minutes, so every PS5 asking about
// the same repo costs GitHub one question (and the admin's token, when set, lifts its limit).
// When GitHub can't be reached, the last copy (any age) with X-RS-Stale: 1.
//   GET ?repo=owner/name
declare(strict_types=1);

// (4.6) The copy on disk, when it's fresh, goes out before the rest of the site loads: most of these
// requests are answered by it, and loading the site for each one was most of their cost.
$fastRepo = trim((string)($_GET['repo'] ?? ''));
if (preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $fastRepo) && !str_contains($fastRepo, '..')) {
    $fastF = dirname(__DIR__, 2) . '/recompstore-private/gh/' . md5(strtolower($fastRepo)) . '.json';
    $fastM = @filemtime($fastF);
    if ($fastM !== false && time() - $fastM < 1800) {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=120');
        header('X-RS-Cache-Age: ' . (time() - $fastM));
        readfile($fastF);
        exit;
    }
}
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
// A copy on disk for 30 minutes: every PS5 opening the same app asks this, and most get the file
// without touching the database.
$gdir = dirname(RS_PUBLIC) . '/recompstore-private/gh';
$gf = $gdir . '/' . md5(strtolower($repo)) . '.json';
$gage = is_file($gf) ? time() - (int)filemtime($gf) : PHP_INT_MAX;
if ($gage < 1800) { // (3.3) 30 minutes; a new release refreshes it sooner (admin/inc/rlpush.php)
    header('Cache-Control: public, max-age=120');
    header('X-RS-Cache-Age: ' . $gage);
    readfile($gf);
    exit;
}
// (4.1) While GitHub is slow or refusing, or another request is already asking it about this repo, the
// last copy goes out at once instead of every PS5 holding a PHP worker until GitHub answers.
if (is_file($gf) && rs_gh_cooling('github')) {
    header('Cache-Control: public, max-age=60');
    header('X-RS-Stale: 1');
    readfile($gf);
    exit;
}
try {
    $r = rs_gh_releases($repo);
} catch (Throwable $e) {
    rs_errlog('gh', $e);
    if (is_file($gf)) {
        header('Cache-Control: public, max-age=30');
        header('X-RS-Stale: 1');
        readfile($gf);
        exit;
    }
    http_response_code(503);
    echo '{"message":"recompstore.com can\'t ask GitHub right now."}';
    exit;
}
if ($r['status'] === 200 && !$r['stale']) {
    if (!is_dir($gdir)) @mkdir($gdir, 0755, true);
    if (@file_put_contents($gf . '.' . getmypid(), $r['body']) !== false) @rename($gf . '.' . getmypid(), $gf);
}
http_response_code($r['status']);
header('Cache-Control: public, max-age=' . ($r['status'] === 200 ? 120 : 30));
header('X-RS-Cache-Age: ' . (int)$r['age']);
if ($r['stale']) header('X-RS-Stale: 1');
echo $r['body'];
