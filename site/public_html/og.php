<?php
// Recomp Store website: a game's link preview picture, /og/<ID>.jpg (1200x630), made from its
// art the first time it's asked for and kept in media/og until the game changes.
declare(strict_types=1);
require __DIR__ . '/admin/inc/og.php';
$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['id'] ?? ''));
$found = $id !== '' ? rs_og_find($id) : null;
$file = $found ? rs_og_file($id, $found[0], $found[1]) : null;
if ($file === null) {
    // Not a game, or this server can't draw: Recomp Store's own picture.
    header('Location: /assets/card.png', true, 302);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($file);
