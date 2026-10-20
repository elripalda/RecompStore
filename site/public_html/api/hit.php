<?php
// Recomp Store website (2.1): a page of the site was shown (the Analytics page counts them). Sent by
// assets/site.js with sendBeacon; nothing is stored about the visitor but a code that changes daily.
//   POST JSON {p: "/game/PPSA92011", r: "https://www.reddit.com/..."}  -> 204
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
http_response_code(204);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) exit;
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) exit;
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true);
if (!is_array($in)) exit;
try {
    rs_count_visit((string)($in['p'] ?? '/'), (string)($in['r'] ?? ''));
} catch (Throwable $e) {
}
