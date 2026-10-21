<?php
// Recomp Store website (2.3.0): the thumbs-up on an app's page. One per console and app; the counts
// go out with the charts (api/charts.php, likes: {id: n}).
//   POST a=like|unlike  install token store app     POST a=mine  install token  -> liked: [ids]
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

function rs_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_out(405, ['ok' => false, 'error' => 'From Recomp Store only.']);
$s = fn(string $k, int $max): string => isset($_POST[$k]) && is_string($_POST[$k]) ? substr(trim($_POST[$k]), 0, $max) : '';
$a = $s('a', 16);
if (!in_array($a, ['like', 'unlike', 'mine'], true)) rs_out(400, ['ok' => false, 'error' => 'Unknown request.']);
$app = $s('app', 64);
if ($a !== 'mine' && !preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $app)) rs_out(422, ['ok' => false, 'error' => 'That isn\'t an app.']);
try {
    $db = rs_db();
    if (rs_rate_over('likes', 120, 600)) rs_out(429, ['ok' => false, 'error' => 'Too many at once. Try again in a few minutes.']);
    rs_rate_hit('likes');
    $install = rs_console($db, $s('install', 16), $s('token', 32));
    if ($install === null) rs_out(403, ['ok' => false, 'error' => 'This console isn\'t known here.']);
    rs_likes_table();
    if ($a === 'mine') {
        $st = $db->prepare('SELECT app_id FROM likes WHERE install_id = ? ORDER BY created DESC LIMIT 2000');
        $st->execute([$install]);
        rs_out(200, ['ok' => true, 'liked' => array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN))]);
    }
    if ($a === 'like') $db->prepare('INSERT IGNORE INTO likes (install_id, app_id, created) VALUES (?, ?, NOW())')->execute([$install, $app]);
    else $db->prepare('DELETE FROM likes WHERE install_id = ? AND app_id = ?')->execute([$install, $app]);
    $n = $db->prepare('SELECT COUNT(*) FROM likes WHERE app_id = ?');
    $n->execute([$app]);
    rs_out(200, ['ok' => true, 'likes' => (int)$n->fetchColumn()]);
} catch (Throwable $e) {
    rs_out(503, ['ok' => false, 'error' => 'Not counted right now.']);
}
