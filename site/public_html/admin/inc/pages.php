<?php
// RecompStore website (2.6): screenshots and a video on a game's page, and developer pages
// (#/dev/<slug>). The catalog carries `screens`, `video` and `dev` on each app, and a
// `developers` list; the PS5 store skips them for now.
declare(strict_types=1);

const RS_SCREENS_MAX = 8;
// A developer's links, in the order the site shows them.
// (2.7.2) Patreon, PayPal, YouTube and a support link of any kind too (the Support button on their apps).
const RS_DEV_LINKS = ['github' => 'GitHub', 'kofi' => 'Ko-fi', 'patreon' => 'Patreon', 'paypal' => 'PayPal', 'support' => 'Support', 'discord' => 'Discord', 'x' => 'X', 'youtube' => 'YouTube', 'site' => 'Website'];

// ---- Screenshots and video ---------------------------------------------------------

// A YouTube link (watch, youtu.be, shorts, embed) or a bare video ID -> the 11-character ID, or ''.
function rs_youtube_id(string $v): string {
    $v = trim($v);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $v)) return $v;
    $re = '#^https://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:[^\#]*&)?v=|embed/|shorts/|live/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})(?:[?&\#/][^\s]*)?$#';
    return preg_match($re, $v, $m) ? $m[1] : '';
}

// A game's screenshots as saved: [{url, thumb}], files in /media only, at most RS_SCREENS_MAX.
function rs_screens_clean($in): array {
    $out = [];
    $ok = fn($p) => is_string($p) && (bool)preg_match('#^/media/[A-Za-z0-9._-]+\.jpg$#', $p);
    foreach (is_array($in) ? $in : [] as $s) {
        if (!is_array($s) || !$ok($s['url'] ?? null) || !$ok($s['thumb'] ?? null)) continue;
        $out[] = ['url' => $s['url'], 'thumb' => $s['thumb']];
        if (count($out) >= RS_SCREENS_MAX) break;
    }
    return $out;
}

// An uploaded picture, opened: [GD image, width, height]. The same checks as rs_save_image().
function rs_image_open(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The upload did not arrive. Try again.');
    if ($file['size'] > 25 * 1024 * 1024) throw new RuntimeException('That image is over 25 MB.');
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) throw new RuntimeException('Use a PNG, JPG or WebP image.');
    if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('This server is missing PHP\'s GD image library.');
    $src = match ($info[2]) {
        IMAGETYPE_PNG => imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_JPEG => imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_WEBP => imagecreatefromwebp($file['tmp_name']),
    };
    if (!$src) throw new RuntimeException('That image could not be read.');
    if (!is_dir(RS_MEDIA)) mkdir(RS_MEDIA, 0755, true);
    return [$src, imagesx($src), imagesy($src)];
}

// A screenshot: 1920 wide (a smaller one kept at its size) and a 640-wide thumbnail, both JPEG.
function rs_save_screen(array $file, string $id): array {
    [$src, $w, $h] = rs_image_open($file);
    $slug = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?: 'game';
    $tag = bin2hex(random_bytes(4));
    $out = [];
    foreach ([1920 => 'url', 640 => 'thumb'] as $tw => $key) {
        $tw2 = min($tw, $w); $th = max(1, (int)round($h * $tw2 / $w));
        $dst = imagecreatetruecolor($tw2, $th);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw2, $th, $w, $h);
        $name = "$slug-shot-" . ($key === 'url' ? '' : 't-') . "$tag.jpg";
        imagejpeg($dst, RS_MEDIA . "/$name", $key === 'url' ? 84 : 78);
        $out[$key] = "/media/$name";
    }
    return $out;
}

// A developer's picture: 256x256 PNG, cropped to the center square.
function rs_save_avatar(array $file, string $slug): string {
    [$src, $w, $h] = rs_image_open($file);
    $side = min($w, $h);
    $dst = imagecreatetruecolor(256, 256);
    imagealphablending($dst, false); imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 256, 256, $side, $side);
    $name = 'dev-' . (preg_replace('/[^a-z0-9-]/', '', $slug) ?: 'dev') . '-' . bin2hex(random_bytes(4)) . '.png';
    imagepng($dst, RS_MEDIA . "/$name", 7);
    return "/media/$name";
}

// ---- Developers ----------------------------------------------------------------------

function rs_dev_table(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS developers (slug VARCHAR(40) NOT NULL PRIMARY KEY, data MEDIUMTEXT NOT NULL, updated DATETIME NOT NULL) CHARACTER SET utf8mb4');
}

function rs_dev_defaults(): array {
    return ['slug' => '', 'name' => '', 'aliases' => '', 'about' => '', 'links' => [], 'avatar' => '', 'verified' => false];
}

// Every developer, slug => developer, by name.
function rs_developers(): array {
    rs_dev_table();
    $out = [];
    foreach (rs_db()->query('SELECT slug, data FROM developers')->fetchAll() as $r) {
        $d = array_merge(rs_dev_defaults(), json_decode((string)$r['data'], true) ?: []);
        $d['slug'] = (string)$r['slug'];
        $out[$d['slug']] = $d;
    }
    uasort($out, fn($x, $y) => strnatcasecmp($x['name'], $y['name']));
    return $out;
}

function rs_dev_save(array $d, ?string $oldSlug = null): void {
    rs_dev_table();
    $db = rs_db();
    $data = $d; unset($data['slug']);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($oldSlug !== null && $oldSlug !== $d['slug']) {
        $db->prepare('UPDATE developers SET slug = ?, data = ?, updated = NOW() WHERE slug = ?')->execute([$d['slug'], $json, $oldSlug]);
        // Games pointed at the old slug follow it.
        foreach (rs_games() as $g) if (($g['dev'] ?? '') === $oldSlug) { $g['dev'] = $d['slug']; rs_save_game($g); }
        // PC recomps and the developer accounts on that page follow it too.
        if (function_exists('rs_pc_apps')) foreach (rs_pc_apps(false) as $a) if (($a['dev'] ?? '') === $oldSlug) { $a['dev'] = $d['slug']; rs_pc_save($a); }
        try { $db->prepare('UPDATE dev_accounts SET slug = ? WHERE slug = ?')->execute([$d['slug'], $oldSlug]); } catch (Throwable $e) { /* no accounts table yet */ }
        rs_dev_write_public();
        return;
    }
    $db->prepare('INSERT INTO developers (slug, data, updated) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE data = VALUES(data), updated = NOW()')->execute([$d['slug'], $json]);
    rs_dev_write_public();
}

function rs_dev_delete(string $slug): void {
    rs_dev_table();
    rs_db()->prepare('DELETE FROM developers WHERE slug = ?')->execute([$slug]);
    foreach (rs_games() as $g) if (($g['dev'] ?? '') === $slug) { $g['dev'] = ''; rs_save_game($g); }
    rs_dev_write_public();
}

// (2.7.2) The developers as the site shows them, in public_html/developers.json, written whenever a
// developer changes (and at each publish): their pages and Support buttons update at once, without
// waiting for the next catalog. Each one's apps: the listed (public) apps that are theirs.
function rs_dev_write_public(): void {
    try {
        $devs = rs_developers();
        $apps = [];
        foreach (rs_games() as $g) {
            if ($g['hidden'] || $g['beta'] || !empty($g['testers']) || $g['soon']) continue;
            if (($s = rs_dev_for($g, $devs)) !== '') $apps[$s][] = (string)$g['id'];
        }
        $list = [];
        foreach ($devs as $s => $d) {
            if (empty($apps[$s]) && !$d['verified']) continue;
            $list[] = rs_dev_public($d) + ['apps' => $apps[$s] ?? []];
        }
        $json = json_encode(['developers' => $list, 'built' => gmdate('c')], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $tmp = RS_PUBLIC . '/developers.json.tmp';
        if (@file_put_contents($tmp, $json) !== false && @rename($tmp, RS_PUBLIC . '/developers.json')) rs_json_gz(RS_PUBLIC . '/developers.json');
    } catch (Throwable $e) { /* the catalog's copy still works */ }
}

// The words a name is matched by: lowercase letters and digits only ("Gary Riches" = "garyriches").
function rs_dev_key(string $s): string {
    return (string)preg_replace('/[^a-z0-9]/', '', mb_strtolower($s));
}

// A game's developer: its own pick ('-' = none), else the one whose name, slug or other names
// match its author. '' when none.
function rs_dev_for(array $g, array $devs): string {
    $pick = (string)($g['dev'] ?? '');
    if ($pick === '-') return '';
    if ($pick !== '' && isset($devs[$pick])) return $pick;
    $k = rs_dev_key((string)($g['author'] ?? ''));
    // (2.7.3) RecompStore's own apps (Originals, or made by "RecompStore") are the admin's: his
    // developer page (setting own_dev, elripalda unless changed).
    $own = (string)rs_setting('own_dev', 'elripalda');
    if ((($g['kind'] ?? '') === 'original' || $k === 'recompstore') && isset($devs[$own])) return $own;
    if ($k === '') return '';
    foreach ($devs as $slug => $d) {
        $names = array_merge([$d['name'], $slug], preg_split('/\s*,\s*/', (string)$d['aliases'], -1, PREG_SPLIT_NO_EMPTY));
        foreach ($names as $n) if (rs_dev_key((string)$n) === $k) return $slug;
    }
    return '';
}

// A developer's links, cleaned: https addresses only, known keys only. $bad names the first wrong one.
function rs_dev_links_clean(array $in, ?string &$bad = null): array {
    $out = [];
    foreach (RS_DEV_LINKS as $k => $label) {
        $u = trim((string)($in[$k] ?? ''));
        if ($u === '') continue;
        if (!preg_match('#^https://[^\s<>"\']{3,300}$#', $u)) { $bad ??= $label; continue; }
        $out[$k] = $u;
    }
    return $out;
}

// What the catalog and the site show of a developer.
function rs_dev_public(array $d): array {
    $out = ['slug' => $d['slug'], 'name' => $d['name']];
    if ($d['verified']) $out['verified'] = true;
    if ($d['avatar'] !== '') $out['avatar'] = rs_abs($d['avatar']);
    if (trim((string)$d['about']) !== '') $out['about'] = trim((string)$d['about']);
    if ($d['links']) $out['links'] = $d['links'];
    return $out;
}

// A game's screenshots, video and developer as its catalog entry carries them.
function rs_app_pages(array $g): array {
    static $devs = null;
    $devs ??= rs_developers();
    $out = [];
    $shots = rs_screens_clean($g['screens'] ?? []);
    if ($shots) $out['screens'] = array_map(fn($s) => ['url' => rs_abs($s['url']), 'thumb' => rs_abs($s['thumb'])], $shots);
    $vid = rs_youtube_id((string)($g['video'] ?? ''));
    if ($vid !== '') $out['video'] = ['type' => 'youtube', 'id' => $vid];
    $dev = rs_dev_for($g, $devs);
    if ($dev !== '') $out['dev'] = $dev;
    return $out;
}

// The catalog's developers: the ones with at least one game in the admin, so a page never links
// to an empty developer. Older stores skip it.
function rs_catalog_pages(): array {
    $devs = rs_developers();
    if (!$devs) return [];
    $used = [];
    foreach (rs_games() as $g) if (($s = rs_dev_for($g, $devs)) !== '') $used[$s] = true;
    $list = array_values(array_map('rs_dev_public', array_filter($devs, fn($d) => isset($used[$d['slug']]))));
    return $list ? ['developers' => $list] : [];
}
