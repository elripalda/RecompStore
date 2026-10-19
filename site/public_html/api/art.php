<?php
// Recomp Store website (2.0): several pictures from /media in one answer, for the PS5 store.
// On a PS5 each request costs about as much as a small picture, so a new store asking for 60
// icons one at a time waited a minute for them.
//
// POST paths=/media/a.png\n/media/b.png... (up to 24). The answer, for each path in order: a
// line "<path> <size>", then that many bytes. Size 0: not here. Size -1: past this answer's
// size cap, ask again on its own.
declare(strict_types=1);

header('Content-Type: application/octet-stream');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

const RS_ART_MAX = 24;
const RS_ART_CAP = 12 << 20;

$media = realpath(dirname(__DIR__) . '/media');
$paths = array_slice(array_values(array_filter(array_map('trim', explode("\n", (string)($_POST['paths'] ?? ''))),
    fn($p) => $p !== '')), 0, RS_ART_MAX);
if ($media === false || !$paths) {
    http_response_code(400);
    exit;
}

$sent = 0;
foreach ($paths as $p) {
    $file = false;
    if (preg_match('#^/media/[A-Za-z0-9][A-Za-z0-9._/-]{0,200}\.(png|jpe?g|webp|gif)$#i', $p) && !str_contains($p, '..')) {
        $file = realpath(dirname(__DIR__) . $p);
        if ($file === false || !str_starts_with($file, $media . DIRECTORY_SEPARATOR) || !is_file($file)) $file = false;
    }
    $size = $file === false ? 0 : (int)filesize($file);
    if ($size > 0 && $sent > 0 && $sent + $size > RS_ART_CAP) {
        echo $p, " -1\n";
        continue;
    }
    $bytes = $size > 0 ? file_get_contents($file) : false;
    if ($bytes === false || strlen($bytes) !== $size) {
        echo $p, " 0\n";
        continue;
    }
    echo $p, ' ', $size, "\n", $bytes;
    $sent += $size;
}
