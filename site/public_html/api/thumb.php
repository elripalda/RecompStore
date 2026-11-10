<?php
// (3.0) Small copies of store pictures for phones and tiles. /api/thumb.php?f=<file in /media>&w=<width>
// The first request makes the copy in /media/t/<width>/; after that it's read straight from disk, and
// browsers keep it for a year (the file names already change whenever a picture does).
declare(strict_types=1);

const RS_THUMB_W = [160, 256, 320, 400, 640, 960]; // (4.6) 320: the launcher's Recomp Achievements covers

$f = (string)($_GET['f'] ?? '');
$w = (int)($_GET['w'] ?? 0);
$media = dirname(__DIR__) . '/media';

function rs_thumb_out(string $path, string $type): void {
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(jpe?g|png|webp)$/i', $f) || !in_array($w, RS_THUMB_W, true)) {
    http_response_code(400);
    exit;
}
$src = $media . '/' . $f;
if (!is_file($src)) { http_response_code(404); exit; }
$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
$srcType = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');

// No image library, or a picture that is small already: the original does.
if (!function_exists('imagecreatetruecolor')) rs_thumb_out($src, $srcType);
$info = @getimagesize($src);
if (!$info || $info[0] <= $w || $info[0] * $info[1] > 40000000) rs_thumb_out($src, $srcType);

$alpha = $ext !== 'jpg' && $ext !== 'jpeg';
$webp = $alpha && function_exists('imagewebp');
$outExt = $alpha ? ($webp ? 'webp' : 'png') : 'jpg';
$outType = $outExt === 'webp' ? 'image/webp' : ($outExt === 'png' ? 'image/png' : 'image/jpeg');
$dir = $media . '/t/' . $w;
$out = $dir . '/' . preg_replace('/\.[a-z]+$/i', '', $f) . '.' . $outExt;
if (is_file($out) && filemtime($out) >= filemtime($src)) rs_thumb_out($out, $outType);

$im = match ($ext) {
    'png' => @imagecreatefrompng($src),
    'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
    default => @imagecreatefromjpeg($src),
};
if (!$im) rs_thumb_out($src, $srcType);
$h = max(1, (int)round($info[1] * $w / $info[0]));
$dst = imagecreatetruecolor($w, $h);
if ($alpha) { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
imagecopyresampled($dst, $im, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
imagedestroy($im);
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$tmp = $out . '.' . bin2hex(random_bytes(4)) . '.tmp';
$ok = match ($outExt) {
    'webp' => imagewebp($dst, $tmp, 82),
    'png' => imagepng($dst, $tmp, 8),
    default => imagejpeg($dst, $tmp, 80),
};
imagedestroy($dst);
if (!$ok || !@rename($tmp, $out)) { @unlink($tmp); rs_thumb_out($src, $srcType); }
rs_thumb_out($out, $outType);
