<?php
// RecompStore website (2.0): the charts, for the PS5 store's and the site's Top, Trending, New and
// Recently updated: installs counted per console (api/stats.php) and GitHub's numbers, rebuilt at
// most every 10 minutes. The contract: docs/api-2.0.md, section 4.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
@set_time_limit(120);
// (2.6) Never kept by the CDN or a browser: what was just published is in Newest Arrivals on
// the next look (each Publish writes the file again).
header('Cache-Control: no-cache, must-revalidate');
// Most visits: the file, no database. One visit at a time makes the charts again when it's 10
// minutes old; the others meanwhile get the one there is.
$cf = rs_charts_file();
$age = is_file($cf) ? time() - (int)filemtime($cf) : PHP_INT_MAX;
$lock = null;
if ($age >= 600) {
    $lock = @fopen($cf . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) $lock = null;
}
if ($age < 600 || (!$lock && $age < 86400)) {
    readfile($cf);
    exit;
}
try {
    $json = json_encode(rs_charts(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    rs_charts_file_put($json);
    echo $json;
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); $lock = null; }
    // (2.7.3) On Mondays, the developers' weekly email, once the answer is on its way.
    rs_dev_weekly_maybe();
    // (2.7.3) New GitHub releases of apps in the store, to Discord (when that's on).
    rs_release_watch_maybe();
    // (2.7.3) The listings' health and the daily look for new PS5 apps (and title IDs), even on
    // days nobody opens the admin.
    rs_health_maybe();
    // (2.8) PC recomps on GitHub, twice a week.
    rs_pc_find_maybe();
} catch (Throwable $e) {
    rs_errlog('charts', $e);
    // The last charts there are, rather than none.
    if (is_file($cf)) { readfile($cf); exit; }
    http_response_code(503);
    echo '{"ok":false,"error":"The charts can\'t be read right now."}';
}
