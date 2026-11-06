<?php
// (4.0) Team notes for the site: short notes from the RecompStore team pinned on top of the pages
// (the admin's Discord & notes page). GET -> {ok, notes: [{id, body, link, linkText, tone}]}
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
try {
    $notes = array_map(fn($n) => array_intersect_key($n, array_flip(['id', 'body', 'link', 'linkText', 'tone'])), rs_notes(true, 'site'));
    header('Cache-Control: public, max-age=120');
    echo json_encode(['ok' => true, 'notes' => $notes], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'notes' => []]);
}
