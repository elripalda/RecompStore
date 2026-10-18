<?php
// Recomp Store website: link previews. A game's own link (recompstore.com/game/<ID>) carries
// its name, blurb and a 1200x630 picture (a JPEG) made from its art, so Discord, X, iMessage and the
// rest show a card for it. Reads only the published catalog.json and the media folder.
declare(strict_types=1);

const RS_OG_REV = 2;      // bump when the picture's design changes: every card is made again
const RS_OG_W = 1200;
const RS_OG_H = 630;

function rs_og_public(): string { return dirname(__DIR__, 2); }

// The site's address as this request reached it (https://recompstore.com).
function rs_og_base(): string {
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'recompstore.com'));
    return ($https ? 'https://' : 'http://') . ($host !== '' ? $host : 'recompstore.com');
}

// A published game, or Coming soon entry: [entry, true when it's Coming soon], or null.
function rs_og_find(string $id): ?array {
    static $cat = null;
    if ($cat === null) {
        $cat = json_decode((string)@file_get_contents(rs_og_public() . '/catalog.json'), true);
        if (!is_array($cat)) $cat = [];
    }
    foreach (['apps', 'newerStoreApps', 'comingSoon'] as $list) {
        foreach ((array)($cat[$list] ?? []) as $a) {
            if (is_array($a) && ($a['id'] ?? '') === $id) return [$a, $list === 'comingSoon'];
        }
    }
    return null;
}

// Changes whenever anything on the card does, so a shared link picks up the new picture.
function rs_og_version(array $a, bool $soon): string {
    return substr(md5(json_encode([RS_OG_REV, $soon, $a['name'] ?? '', $a['blurb'] ?? '', $a['kind'] ?? '', $a['author'] ?? '',
        $a['date'] ?? '', $a['icon'] ?? '', $a['heroWeb'] ?? '', $a['hero'] ?? '', $a['logo']['url'] ?? '', $a['banner'] ?? []])), 0, 10);
}

// A media URL from the catalog as a file on this site, or null.
function rs_og_local(?string $url): ?string {
    if (!is_string($url) || $url === '') return null;
    $path = (string)parse_url($url, PHP_URL_PATH);
    if (!preg_match('#^/(media|assets)/[A-Za-z0-9._-]+$#', $path)) return null;
    $file = rs_og_public() . $path;
    return is_file($file) ? $file : null;
}

function rs_og_load(?string $file) {
    if ($file === null) return null;
    $data = @file_get_contents($file);
    if ($data === false || $data === '') return null;
    $im = @imagecreatefromstring($data);
    if (!$im) return null;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    return $im;
}

function rs_og_font(string $name): string { return __DIR__ . '/fonts/' . $name; }

// Text drawn with its top at $y (the font's cap height, roughly), left at $x. Returns its width.
function rs_og_text($im, string $font, float $size, int $x, int $y, int $color, string $text, float $spacing = 0): int {
    if ($spacing <= 0) {
        $b = imagettfbbox($size, 0, $font, 'H');
        imagettftext($im, $size, 0, $x, $y - $b[7], $color, $font, $text);
        $w = imagettfbbox($size, 0, $font, $text);
        return $w[2] - $w[0];
    }
    $cx = $x;
    $b = imagettfbbox($size, 0, $font, 'H');
    foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        imagettftext($im, $size, 0, (int)round($cx), $y - $b[7], $color, $font, $ch);
        $w = imagettfbbox($size, 0, $font, $ch);
        $cx += ($w[2] - $w[0]) + $spacing + ($ch === ' ' ? $size * 0.28 : 0);
    }
    return (int)round($cx - $x);
}

function rs_og_width(string $font, float $size, string $text): int {
    $b = imagettfbbox($size, 0, $font, $text);
    return $b[2] - $b[0];
}

// Words into at most $max lines of $width; the last one ends in … when there's more.
function rs_og_wrap(string $font, float $size, int $width, string $text, int $max): array {
    if ($max <= 0) return [];
    $lines = [];
    $line = '';
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    foreach ($words as $i => $w) {
        $try = $line === '' ? $w : "$line $w";
        if ($line !== '' && rs_og_width($font, $size, $try) > $width) {
            $lines[] = $line;
            $line = $w;
            if (count($lines) === $max) { $line = ''; $cut = true; break; }
        } else {
            $line = $try;
        }
    }
    if ($line !== '') $lines[] = $line;
    if (!empty($cut)) {
        $last = array_pop($lines);
        while ($last !== '' && rs_og_width($font, $size, $last . '…') > $width) $last = preg_replace('/\s*\S+$/u', '', $last);
        $lines[] = rtrim($last, ' ,.;:–—-') . '…';
    }
    return $lines;
}

// A picture drawn into a box, cropped to fill it (fx, fy: which part stays in view, 0..1).
function rs_og_cover($dst, $src, int $x, int $y, int $w, int $h, float $fx = 0.5, float $fy = 0.5, float $zoom = 1.0): void {
    $sw = imagesx($src); $sh = imagesy($src);
    $scale = max($w / $sw, $h / $sh) * max(1.0, $zoom);
    $cw = $w / $scale; $ch = $h / $scale;
    $cx = max(0, min($sw - $cw, $sw * $fx - $cw / 2));
    $cy = max(0, min($sh - $ch, $sh * $fy - $ch / 2));
    imagecopyresampled($dst, $src, $x, $y, (int)round($cx), (int)round($cy), $w, $h, (int)round($cw), (int)round($ch));
}

// A square picture with rounded corners, drawn at (x, y).
function rs_og_rounded($dst, $src, int $x, int $y, int $size, int $r): void {
    $t = imagecreatetruecolor($size, $size);
    imagealphablending($t, false);
    imagesavealpha($t, true);
    imagefill($t, 0, 0, imagecolorallocatealpha($t, 0, 0, 0, 127));
    imagealphablending($t, true);
    rs_og_cover($t, $src, 0, 0, $size, $size);
    imagealphablending($t, false);
    for ($py = 0; $py < $size; $py++) {
        for ($px = 0; $px < $size; $px++) {
            $dx = $px < $r ? $r - $px - 0.5 : ($px >= $size - $r ? $px - ($size - $r) + 0.5 : 0);
            $dy = $py < $r ? $r - $py - 0.5 : ($py >= $size - $r ? $py - ($size - $r) + 0.5 : 0);
            if ($dx <= 0 || $dy <= 0) continue;
            $d = sqrt($dx * $dx + $dy * $dy) - $r; // > 0 outside the corner's curve
            if ($d < -1) continue;
            $c = imagecolorat($t, $px, $py);
            $a = ($c >> 24) & 127;
            $keep = $d >= 0 ? 0.0 : -$d;              // 0..1 across the edge's last pixel
            $na = (int)round(127 - (127 - $a) * $keep);
            imagesetpixel($t, $px, $py, ($c & 0xFFFFFF) | ($na << 24));
        }
    }
    imagealphablending($dst, true);
    imagecopy($dst, $t, $x, $y, 0, 0, $size, $size);
    imagedestroy($t);
}

// The card: the game's art across it, darkened toward the left, where its icon, its name (or
// logo), its blurb and Recomp Store's own mark sit.
function rs_og_render(array $a, bool $soon) {
    $W = RS_OG_W; $H = RS_OG_H;
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    imagefill($im, 0, 0, imagecolorallocate($im, 0x0B, 0x12, 0x20));

    $hero = rs_og_load(rs_og_local($a['heroWeb'] ?? null) ?? rs_og_local($a['hero'] ?? null));
    $icon = rs_og_load(rs_og_local($a['icon'] ?? null));
    $banner = is_array($a['banner'] ?? null) ? $a['banner'] : [];
    if ($hero) {
        rs_og_cover($im, $hero, 0, 0, $W, $H, ($banner['x'] ?? 50) / 100, ($banner['y'] ?? 30) / 100, ($banner['zoom'] ?? 100) / 100);
    } elseif ($icon) {
        // No wide picture: the icon, blown up into a soft wash of its colors.
        $small = imagecreatetruecolor(24, 13);
        rs_og_cover($small, $icon, 0, 0, 24, 13);
        imagecopyresampled($im, $small, 0, 0, 0, 0, $W, $H, 24, 13);
        for ($i = 0; $i < 6; $i++) imagefilter($im, IMG_FILTER_GAUSSIAN_BLUR);
        imagedestroy($small);
    }
    // Darker toward the left and the bottom, so the words read on any picture.
    for ($x = 0; $x < $W; $x++) {
        $t = $x / $W;
        $dark = $t < 0.32 ? 0.93 : ($t < 0.78 ? 0.93 - ($t - 0.32) / 0.46 * 0.68 : 0.25);
        imageline($im, $x, 0, $x, $H, imagecolorallocatealpha($im, 0x0B, 0x12, 0x20, (int)round(127 * (1 - $dark))));
    }
    for ($y = (int)($H * 0.55); $y < $H; $y++) {
        $t = ($y - $H * 0.55) / ($H * 0.45);
        imageline($im, 0, $y, $W, $y, imagecolorallocatealpha($im, 0x0B, 0x12, 0x20, (int)round(127 * (1 - 0.55 * $t))));
    }

    $sora = rs_og_font('Sora-Bold.ttf');
    $bold = rs_og_font('Figtree-Bold.ttf');
    $semi = rs_og_font('Figtree-SemiBold.ttf');
    $reg = rs_og_font('Figtree-Regular.ttf');
    $white = imagecolorallocate($im, 0xF4, 0xF8, 0xFC);
    $cyan = imagecolorallocate($im, 0x35, 0xD6, 0xF4);
    $soft = imagecolorallocate($im, 0xCD, 0xD6, 0xE2);
    $dim = imagecolorallocate($im, 0x95, 0xA2, 0xB4);
    $L = 64;

    // Recomp Store, top left.
    if ($mark = rs_og_load(rs_og_public() . '/assets/logo-512.png')) {
        imagecopyresampled($im, $mark, $L, 54, 0, 0, 60, 60, imagesx($mark), imagesy($mark));
        imagedestroy($mark);
    }
    rs_og_text($im, $sora, 25, $L + 78, 74, $white, 'Recomp Store');

    // The game's icon, what it is, its logo (or name) and its blurb: laid out first, then
    // drawn as one block in the middle of the space under Recomp Store's mark.
    $kicker = $soon ? 'COMING SOON' . (($a['date'] ?? '') !== '' ? ' · ' . mb_strtoupper((string)$a['date']) : '')
                    : (($a['kind'] ?? '') === 'original' ? 'PS5 · RECOMP STORE' : 'PS5' . (($a['author'] ?? '') !== '' ? ' · BY ' . mb_strtoupper((string)$a['author']) : ''));
    $logo = rs_og_load(rs_og_local($a['logo']['url'] ?? null));
    $iconSize = 116;
    $lw = $lh = 0;
    if ($logo) {
        $s = min(560 / imagesx($logo), ($icon ? 118 : 160) / imagesy($logo));
        $lw = (int)round(imagesx($logo) * $s);
        $lh = (int)round(imagesy($logo) * $s);
    }
    $size = 46;
    $name = (string)($a['name'] ?? '');
    $lines = $logo ? [] : rs_og_wrap($sora, $size, 660, $name, 2);
    if (count($lines) > 1) { $size = 38; $lines = rs_og_wrap($sora, $size, 660, $name, 2); }
    $nameH = $logo ? $lh : (int)round(count($lines) * $size * 1.62) - (int)round($size * 0.5);
    $blurb = rs_og_wrap($reg, 19, 640, trim((string)($a['blurb'] ?? '')), 2);
    $blockH = ($icon ? $iconSize + 28 : 0) + 20 + 18 + $nameH + ($blurb ? 24 + count($blurb) * 34 - 10 : 0);
    $top = 140; $bottom = RS_OG_H - 96;
    $y = max($top, (int)round($top + ($bottom - $top - $blockH) / 2));
    if ($icon) {
        rs_og_rounded($im, $icon, $L, $y, $iconSize, 28);
        $y += $iconSize + 28;
    }
    rs_og_text($im, $bold, 15, $L, $y, $cyan, $kicker, 2.2);
    $y += 20 + 18;
    if ($logo) {
        imagecopyresampled($im, $logo, $L, $y, 0, 0, $lw, $lh, imagesx($logo), imagesy($logo));
        imagedestroy($logo);
    } else {
        $ly = $y;
        foreach ($lines as $line) {
            rs_og_text($im, $sora, $size, $L, $ly, $white, $line);
            $ly += (int)round($size * 1.62);
        }
    }
    $y += $nameH + 24;
    foreach ($blurb as $line) {
        rs_og_text($im, $reg, 19, $L, $y, $soft, $line);
        $y += 34;
    }
    rs_og_text($im, $semi, 15, $L, RS_OG_H - 64, $dim, 'recompstore.com');

    if ($hero) imagedestroy($hero);
    if ($icon) imagedestroy($icon);
    return $im;
}

// The card's file, made the first time it's asked for (and again when the game changes).
function rs_og_file(string $id, array $a, bool $soon): ?string {
    if (!function_exists('imagettftext') || !function_exists('imagecreatefromstring')) return null;
    $dir = rs_og_public() . '/media/og';
    $v = rs_og_version($a, $soon);
    $file = "$dir/$id-$v.jpg";
    if (is_file($file)) return $file;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
    $im = rs_og_render($a, $soon);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = imagejpeg($im, $tmp, 86); /* a JPEG: small enough for every app's preview (WhatsApp wants under ~300 KB) */
    imagedestroy($im);
    if (!$ok || !@rename($tmp, $file)) { @unlink($tmp); return null; }
    foreach (glob("$dir/$id-*.jpg") ?: [] as $old) if ($old !== $file) @unlink($old); // the game's older cards
    return $file;
}
