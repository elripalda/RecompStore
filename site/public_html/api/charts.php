<?php
// Recomp Store website (2.0): the charts, for the PS5 store's and the site's Top, Trending, New and
// Recently updated: installs counted per console (api/stats.php) and GitHub's numbers, rebuilt at
// most every 10 minutes. The contract: docs/api-2.0.md, section 4.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
@set_time_limit(120);
try {
    $c = rs_charts();
    header('Cache-Control: public, max-age=120');
    echo json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{"ok":false,"error":"The charts can\'t be read right now."}';
}
