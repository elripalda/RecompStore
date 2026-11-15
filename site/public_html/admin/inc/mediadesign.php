<?php
// (4.11) Game media for Easy Setup games and Cover Labels (RecompLauncher Beta 52).
// A listing's media design (its cartridge, card or disc on the setup screen) is kept in the listing's
// data as `media_design` and goes out in pc-catalog.json as `media_design` (its own key: the catalog's
// `media` is the "Around the web" links). Cover Labels are the setting `cover_labels`, out as the
// catalog's top-level `labels`. The editor files are admin/assets/media/ (media-art.js is the
// launcher's own ui/media-art.js, unchanged). See docs/backend/03-pc-catalog.md.
declare(strict_types=1);

const RS_MD_KINDS = ['switch', 'switch2', 'vita', 'nes', 'ds', '3ds', 'gba', 'gb', 'gbc', 'snes', 'n64', 'genesis', 'umd', 'gc', 'bluray', 'dvd'];
const RS_MD_SHAPES = ['tall' => '600x900,342x482,660x930', 'square' => '512x512,1024x1024', 'wide' => '460x215,920x430'];

// A URL on our own /media, as a path ("/media/x.png"), or '' when it isn't one.
function rs_md_own(string $u): string {
    $u = trim($u);
    if (preg_match('#^https?://[^/]+(/media/[A-Za-z0-9._-]+)$#', $u, $m)) {
        $host = strtolower((string)parse_url($u, PHP_URL_HOST));
        $ours = array_map(fn($x) => strtolower((string)parse_url($x, PHP_URL_HOST)), [rs_setting('site_url', 'https://recompstore.com'), 'https://recompstore.com', 'https://recomplauncher.com', 'https://www.recomplauncher.com']);
        $u = in_array($host, $ours, true) ? $m[1] : '';
    }
    return preg_match('#^/media/[A-Za-z0-9._-]+$#', $u) && is_file(RS_MEDIA . '/' . basename($u)) ? $u : '';
}

// A stored SteamGridDB pick (our proxy URL) -> its /media path, downloading it the first time.
function rs_md_sgdb_file(int $imgId, string $listing): string {
    $k = 'sgdbfile:' . $imgId;
    if (($c = rs_kv_get($k)) && is_file(RS_MEDIA . '/' . basename((string)$c['v']))) return (string)$c['v'];
    $src = rs_kv_get('sgdbimg:' . $imgId);
    if (!$src) throw new RuntimeException('That SteamGridDB picture isn\'t known. Search again.');
    $url = (string)$src['v'];
    if (!preg_match('#^https://[a-z0-9.-]*steamgriddb\.com/#i', $url)) throw new RuntimeException('Not a SteamGridDB picture.');
    $r = rs_http($url, ['max' => 25 << 20, 'timeout' => 30]);
    if ($r['status'] !== 200 || $r['body'] === '') throw new RuntimeException('SteamGridDB didn\'t send the picture.');
    $path = rs_md_store_bytes((string)$r['body'], 'pc-' . rs_pc_slug($listing) . '-sgdb', 25 << 20);
    rs_kv_put($k, $path);
    return $path;
}

// Image bytes (PNG, JPEG or WebP) saved as they are into /media, so PNG transparency stays. -> '/media/<name>'
function rs_md_store_bytes(string $bytes, string $prefix, int $max, array $types = ['png', 'jpg', 'webp'], int $maxW = 0): string {
    if (strlen($bytes) > $max) throw new RuntimeException('That picture is too big (' . round($max / 1048576) . ' MB at most).');
    $info = @getimagesizefromstring($bytes);
    $ext = match ($info['mime'] ?? '') { 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', default => '' };
    if ($ext === '' || !in_array($ext, $types, true)) throw new RuntimeException('Use a ' . strtoupper(implode(', ', $types)) . ' picture.');
    if ($maxW && (int)$info[0] > $maxW) throw new RuntimeException('That picture is wider than ' . $maxW . ' px.');
    if (!is_dir(RS_MEDIA)) mkdir(RS_MEDIA, 0755, true);
    $name = preg_replace('/[^a-z0-9-]+/', '-', strtolower($prefix)) . '-' . substr(hash('sha256', $bytes), 0, 10) . '.' . $ext;
    if (!is_file(RS_MEDIA . '/' . $name) && file_put_contents(RS_MEDIA . '/' . $name, $bytes) === false) throw new RuntimeException('Could not save the picture.');
    return '/media/' . $name;
}

// The SteamGridDB game for a listing, remembered on it once found (sgdb_game).
function rs_md_sgdb_game(array &$a): int {
    if ((int)($a['sgdb_game'] ?? 0) > 0) return (int)$a['sgdb_game'];
    $q = trim((string)($a['game'] ?: $a['name']));
    if ($q === '' || rs_sgdb_key() === '') return 0;
    $hits = rs_sgdb('/search/autocomplete/' . rawurlencode($q)) ?? [];
    if (!$hits) return 0;
    $g = rs_sgdb_pick($hits, $q, (string)(($a['about']['released'] ?? '') ?: ''));
    $id = (int)($g['id'] ?? 0);
    if ($id) { $a['sgdb_game'] = $id; rs_pc_save($a); }
    return $id;
}

// A media design, checked: only the known keys, in range. null clears it.
function rs_md_clean(?array $m): ?array {
    if (!$m) return null;
    $num = fn($v, $lo, $hi) => is_numeric($v) ? max($lo, min($hi, (float)$v)) : null;
    $o = [];
    if (in_array($m['kind'] ?? null, RS_MD_KINDS, true)) $o['kind'] = $m['kind'];
    if (in_array($m['art'] ?? null, ['cover', 'hero', 'url', 'scan'], true)) $o['art'] = $m['art'];
    if (isset($m['src']) && is_string($m['src'])) {
        $s = $m['src'];
        if (preg_match('#media-api\.php\?a=sgdb-image&id=(\d+)&listing=([A-Za-z0-9._-]+)#', $s, $mm)) $s = rs_md_sgdb_file((int)$mm[1], $mm[2]);
        if (($s = rs_md_own($s)) !== '') $o['src'] = $s;
    }
    if (in_array($m['style'] ?? null, ['image', 'blur', 'color'], true)) $o['style'] = $m['style'];
    if (is_array($m['crop'] ?? null)) {
        $c = $m['crop']; $x = $num($c['x'] ?? null, 0, 1); $y = $num($c['y'] ?? null, 0, 1); $z = $num($c['z'] ?? null, 1, 4);
        if ($x !== null && $y !== null && $z !== null) {
            $o['crop'] = ['x' => round($x, 4), 'y' => round($y, 4), 'z' => round($z, 4)];
            foreach (['w', 'h'] as $k) if (isset($c[$k]) && is_numeric($c[$k]) && (int)$c[$k] > 0) $o['crop'][$k] = (int)$c[$k];
        }
    }
    if (isset($m['color']) && is_string($m['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $m['color'])) $o['color'] = strtolower($m['color']);
    if (is_array($m['logo'] ?? null)) {
        $l = $m['logo']; $x = $num($l['x'] ?? null, 0, 1); $y = $num($l['y'] ?? null, 0, 1); $s = $num($l['s'] ?? null, 0.05, 1.5);
        $lg = ['on' => !empty($l['on'])];
        if ($x !== null) $lg['x'] = round($x, 4);
        if ($y !== null) $lg['y'] = round($y, 4);
        if ($s !== null) $lg['s'] = round($s, 4);
        if (isset($l['a']) && is_numeric($l['a']) && (float)$l['a'] > 0) $lg['a'] = round((float)$l['a'], 4);
        $o['logo'] = $lg;
    }
    if (isset($m['v']) && is_numeric($m['v'])) $o['v'] = $m['v'] + 0;
    // A url or scan needs its picture; without one it falls back to the cover.
    if (in_array($o['art'] ?? '', ['url', 'scan'], true) && empty($o['src'])) unset($o['art']);
    return $o ?: null;
}

// Cover Labels, checked: { console: { src, s, y, a, h, push } }.
function rs_md_labels_clean(array $in): array {
    $out = [];
    foreach ($in as $con => $l) {
        if (!is_string($con) || !preg_match('/^[a-z0-9_-]{1,24}$/', $con) || !is_array($l)) continue;
        $src = rs_md_own((string)($l['src'] ?? ''));
        if ($src === '') continue;
        $out[$con] = ['src' => $src, 's' => round(max(0.3, min(1.2, (float)($l['s'] ?? 1))), 4), 'y' => round(max(-0.2, min(0.3, (float)($l['y'] ?? 0))), 4),
            'a' => round(max(0.01, (float)($l['a'] ?? 6.67)), 4), 'h' => round(max(0, min(0.4, (float)($l['h'] ?? 0.1))), 4), 'push' => !empty($l['push'])];
    }
    return $out;
}
function rs_md_labels(): array { $j = json_decode((string)rs_setting('cover_labels', '{}'), true); return is_array($j) ? $j : []; }

// For the catalog: the design with its picture as a full URL, or null.
function rs_md_catalog(array $a): ?array {
    $m = $a['media_design'] ?? null;
    if (!is_array($m) || !$m) return null;
    if (!empty($m['src'])) $m['src'] = rs_abs((string)$m['src']);
    return $m;
}
function rs_md_catalog_labels(): array {
    $out = [];
    foreach (rs_md_labels() as $con => $l) { $l['src'] = rs_abs((string)$l['src']); $out[$con] = $l; }
    return $out;
}

// Whether a listing has Easy Setup (the editor shows for these).
// From the published catalog (one read for the whole list page); worked out only for ones not in it.
function rs_md_easy(array $a): bool {
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach ((array)(json_decode((string)@file_get_contents(RS_PC_CATALOG), true)['apps'] ?? []) as $e) $map[(string)($e['id'] ?? '')] = !empty($e['support']['easy_setup']);
    }
    return $map[$a['id']] ?? !empty(rs_pc_support($a)['easy_setup']);
}

// What the editor is given for a listing's pictures: our own paths; a picture from elsewhere goes
// through media-api.php?a=img so the editor's canvas can read it.
function rs_md_art_url(string $u): string {
    if ($u === '') return '';
    if (($own = rs_md_own($u)) !== '') return $own;
    return preg_match('#^https://#', $u) ? 'media-api.php?a=img&u=' . rawurlencode($u) : '';
}
