<?php
// RecompStore admin (3.4): the art picker's sources. Each one finds games by name and lists their
// pictures (cover, backdrop, logo, icon) with their sizes, for admin/art-api.php. The keys live in
// Settings; Libretro and Steam need none. Answers are kept a week (kv_cache) so the same game isn't
// asked for twice, which also keeps TheGamesDB's monthly allowance safe.
declare(strict_types=1);

const RS_ART_SOURCES = [
    'sgdb' => 'SteamGridDB',
    'igdb' => 'IGDB',
    'tgdb' => 'TheGamesDB',
    'libretro' => 'Libretro',
    'steam' => 'Steam',
];
const RS_ART_KINDS = ['cover', 'hero', 'logo', 'icon'];
// Where pictures may be fetched from (the picker's copy for cropping, and saving).
const RS_ART_HOSTS = ['steamgriddb.com', 'igdb.com', 'thegamesdb.net', 'libretro.com', 'steamstatic.com', 'akamaihd.net', 'steampowered.com'];

// Libretro's folder per console (thumbnails.libretro.com), by the PC store's origin keys.
const RS_ART_LIBRETRO = [
    'n64' => 'Nintendo - Nintendo 64', 'snes' => 'Nintendo - Super Nintendo Entertainment System', 'gba' => 'Nintendo - Game Boy Advance',
    'gb' => 'Nintendo - Game Boy', 'ds' => 'Nintendo - Nintendo DS', 'gc' => 'Nintendo - GameCube', 'wii' => 'Nintendo - Wii', 'wiiu' => 'Nintendo - Wii U',
    'ps1' => 'Sony - PlayStation', 'ps2' => 'Sony - PlayStation 2', 'ps3' => 'Sony - PlayStation 3', 'ps4' => 'Sony - PlayStation 4', 'psp' => 'Sony - PlayStation Portable',
    'dc' => 'Sega - Dreamcast', 'saturn' => 'Sega - Saturn', 'xbox' => 'Microsoft - Xbox', 'x360' => 'Microsoft - Xbox 360', 'nes' => 'Nintendo - Nintendo Entertainment System',
    'genesis' => 'Sega - Mega Drive - Genesis', '3ds' => 'Nintendo - Nintendo 3DS',
];

function rs_art_igdb_id(): string { return trim((string)rs_setting('igdb_client_id', '')); }
function rs_art_igdb_secret(): string { return trim((string)rs_setting('igdb_client_secret', '')); }
function rs_art_tgdb_key(): string { return trim((string)rs_setting('tgdb_key', '')); }

// Which sources are ready: [id => ['name' => ..., 'on' => bool, 'why' => '']].
function rs_art_sources(): array {
    $on = [
        'sgdb' => function_exists('rs_sgdb_key') && rs_sgdb_key() !== '',
        'igdb' => rs_art_igdb_id() !== '' && rs_art_igdb_secret() !== '',
        'tgdb' => rs_art_tgdb_key() !== '',
        'libretro' => true,
        'steam' => true,
    ];
    $out = [];
    foreach (RS_ART_SOURCES as $id => $name) $out[$id] = ['name' => $name, 'on' => $on[$id], 'why' => $on[$id] ? '' : 'Add its key in Settings, Art sources.'];
    return $out;
}

// A cached JSON answer, or what $fetch returns (cached when not null).
function rs_art_cached(string $key, int $ttl, callable $fetch) {
    $c = rs_kv_get('art:' . substr($key, 0, 200));
    if ($c && $c['age'] < $ttl) { $v = json_decode($c['v'], true); if ($v !== null) return $v; }
    $v = $fetch();
    if ($v !== null) rs_kv_put('art:' . substr($key, 0, 200), json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $v;
}

function rs_art_json(array $r) {
    return $r['status'] === 200 ? json_decode((string)$r['body'], true) : null;
}

// ---- Search: [{id, name, year, platform, note}] ---------------------------------------------------

function rs_art_search(string $src, string $q, string $origin = ''): array {
    $q = trim(preg_replace('/\s+/', ' ', $q));
    if ($q === '') return [];
    return match ($src) {
        'sgdb' => rs_art_sgdb_search($q),
        'igdb' => rs_art_igdb_search($q),
        'tgdb' => rs_art_tgdb_search($q),
        'libretro' => rs_art_libretro_search($q, $origin),
        'steam' => rs_art_steam_search($q),
        default => [],
    };
}

// ---- Pictures: [{url, thumb, w, h, label}] for one kind --------------------------------------------

function rs_art_images(string $src, string $id, string $kind): array {
    if (!in_array($kind, RS_ART_KINDS, true)) return [];
    return match ($src) {
        'sgdb' => rs_art_sgdb_images((int)$id, $kind),
        'igdb' => rs_art_igdb_images((int)$id, $kind),
        'tgdb' => rs_art_tgdb_images((int)$id, $kind),
        'libretro' => rs_art_libretro_images($id, $kind),
        'steam' => rs_art_steam_images((int)$id, $kind),
        default => [],
    };
}

// ---- SteamGridDB --------------------------------------------------------------------------------

function rs_art_sgdb_search(string $q): array {
    $hits = rs_sgdb('/search/autocomplete/' . rawurlencode($q)) ?? [];
    return array_map(fn($g) => ['id' => (string)($g['id'] ?? ''), 'name' => (string)($g['name'] ?? ''),
        'year' => !empty($g['release_date']) ? gmdate('Y', (int)$g['release_date']) : '', 'platform' => '', 'note' => !empty($g['verified']) ? 'Verified' : ''], array_slice($hits, 0, 20));
}

function rs_art_sgdb_images(int $id, string $kind): array {
    if ($id <= 0) return [];
    $f = '?nsfw=false&humor=false';
    $paths = match ($kind) {
        'cover' => ['/grids/game/' . $id . $f . '&dimensions=600x900,342x482,660x930'],
        'hero' => ['/heroes/game/' . $id . $f, '/grids/game/' . $id . $f . '&dimensions=920x430,460x215'],
        'logo' => ['/logos/game/' . $id . $f],
        'icon' => ['/icons/game/' . $id . $f],
    };
    $out = [];
    foreach ($paths as $p) foreach ((array)(rs_sgdb($p) ?? []) as $x) {
        if (empty($x['url']) || str_ends_with(strtolower((string)$x['url']), '.ico')) continue;
        $out[] = ['url' => (string)$x['url'], 'thumb' => (string)($x['thumb'] ?? $x['url']), 'w' => (int)($x['width'] ?? 0), 'h' => (int)($x['height'] ?? 0),
            'label' => trim(ucfirst((string)($x['style'] ?? '')) . (!empty($x['upvotes']) ? ' · ' . (int)$x['upvotes'] . ((int)$x['upvotes'] === 1 ? ' vote' : ' votes') : ''), ' ·')];
    }
    return $out;
}

// ---- IGDB (Twitch) ------------------------------------------------------------------------------

function rs_art_igdb_token(bool $fresh = false): string {
    $id = rs_art_igdb_id(); $secret = rs_art_igdb_secret();
    if ($id === '' || $secret === '') return '';
    $c = rs_kv_get('art:igdb-token');
    if (!$fresh && $c) { $t = json_decode($c['v'], true); if (is_array($t) && $c['age'] < (int)($t['ttl'] ?? 0) - 3600) return (string)$t['token']; }
    $r = rs_http('https://id.twitch.tv/oauth2/token?client_id=' . rawurlencode($id) . '&client_secret=' . rawurlencode($secret) . '&grant_type=client_credentials',
        ['post' => '', 'timeout' => 15]);
    $j = rs_art_json($r);
    if (!is_array($j) || empty($j['access_token'])) return '';
    rs_kv_put('art:igdb-token', json_encode(['token' => (string)$j['access_token'], 'ttl' => (int)($j['expires_in'] ?? 0)]));
    return (string)$j['access_token'];
}

function rs_art_igdb(string $endpoint, string $body): ?array {
    $tok = rs_art_igdb_token();
    if ($tok === '') return null;
    $go = fn(string $t) => rs_http('https://api.igdb.com/v4/' . $endpoint, ['post' => $body, 'timeout' => 15,
        'headers' => ['Client-ID: ' . rs_art_igdb_id(), 'Authorization: Bearer ' . $t, 'Accept: application/json', 'Content-Type: text/plain']]);
    $r = $go($tok);
    if ($r['status'] === 401) { $tok = rs_art_igdb_token(true); if ($tok === '') return null; $r = $go($tok); }
    $j = rs_art_json($r);
    return is_array($j) ? $j : null;
}

function rs_art_igdb_search(string $q): array {
    $list = rs_art_cached('igdb-s:' . strtolower($q), 7 * 86400, fn() => rs_art_igdb('games',
        'search "' . str_replace(['\\', '"'], ['', ''], $q) . '"; fields name,first_release_date,version_title,platforms.abbreviation,platforms.name; limit 30;'));
    return array_map(fn($g) => ['id' => (string)($g['id'] ?? ''), 'name' => (string)($g['name'] ?? ''),
        'year' => !empty($g['first_release_date']) ? gmdate('Y', (int)$g['first_release_date']) : '',
        'platform' => implode(', ', array_slice(array_map(fn($p) => (string)($p['abbreviation'] ?? $p['name'] ?? ''), (array)($g['platforms'] ?? [])), 0, 5)),
        'note' => (string)($g['version_title'] ?? '')], (array)$list);
}

function rs_art_igdb_images(int $id, string $kind): array {
    if ($id <= 0) return [];
    $g = rs_art_cached('igdb-i:' . $id, 7 * 86400, function () use ($id) {
        $j = rs_art_igdb('games', 'fields cover.image_id,cover.width,cover.height,artworks.image_id,artworks.width,artworks.height,screenshots.image_id,screenshots.width,screenshots.height; where id = ' . $id . ';');
        return $j[0] ?? null;
    });
    if (!is_array($g)) return [];
    $pic = fn(array $x, string $thumb, string $label) => ['url' => 'https://images.igdb.com/igdb/image/upload/t_original/' . $x['image_id'] . '.jpg',
        'thumb' => 'https://images.igdb.com/igdb/image/upload/' . $thumb . '/' . $x['image_id'] . '.jpg', 'w' => (int)($x['width'] ?? 0), 'h' => (int)($x['height'] ?? 0), 'label' => $label];
    $out = [];
    $cover = !empty($g['cover']['image_id']) ? [$pic($g['cover'], 't_cover_big', 'Box art')] : [];
    $art = array_map(fn($x) => $pic($x, 't_screenshot_med', 'Artwork'), array_filter((array)($g['artworks'] ?? []), fn($x) => !empty($x['image_id'])));
    $shots = array_map(fn($x) => $pic($x, 't_screenshot_med', 'Screenshot'), array_filter((array)($g['screenshots'] ?? []), fn($x) => !empty($x['image_id'])));
    if ($kind === 'cover') $out = array_merge($cover, $art);
    elseif ($kind === 'hero') $out = array_merge($art, $shots);
    elseif ($kind === 'icon') $out = array_merge($cover, $art);
    return array_values($out); // IGDB has no logos
}

// ---- TheGamesDB ---------------------------------------------------------------------------------

function rs_art_tgdb(string $path, array $q): ?array {
    $key = rs_art_tgdb_key();
    if ($key === '') return null;
    $r = rs_http('https://api.thegamesdb.net' . $path . '?' . http_build_query(['apikey' => $key] + $q), ['timeout' => 15]);
    $j = rs_art_json($r);
    if (is_array($j) && isset($j['remaining_monthly_allowance'])) rs_kv_put('art:tgdb-left', (string)(int)$j['remaining_monthly_allowance']);
    return is_array($j) ? $j : null;
}

function rs_art_tgdb_platforms(): array {
    return (array)rs_art_cached('tgdb-platforms', 30 * 86400, function () {
        $j = rs_art_tgdb('/v1/Platforms', []);
        $p = $j['data']['platforms'] ?? null;
        if (!is_array($p)) return null;
        $out = [];
        foreach ($p as $x) if (isset($x['id'])) $out[(string)$x['id']] = (string)($x['name'] ?? '');
        return $out;
    });
}

function rs_art_tgdb_search(string $q): array {
    $games = rs_art_cached('tgdb-s:' . strtolower($q), 7 * 86400, function () use ($q) {
        $j = rs_art_tgdb('/v1.1/Games/ByGameName', ['name' => $q]);
        return is_array($j['data']['games'] ?? null) ? $j['data']['games'] : null;
    });
    if (!$games) return [];
    $plat = rs_art_tgdb_platforms();
    return array_map(fn($g) => ['id' => (string)($g['id'] ?? ''), 'name' => (string)($g['game_title'] ?? ''),
        'year' => substr((string)($g['release_date'] ?? ''), 0, 4), 'platform' => (string)($plat[(string)($g['platform'] ?? '')] ?? ''), 'note' => ''], array_slice((array)$games, 0, 30));
}

function rs_art_tgdb_images(int $id, string $kind): array {
    if ($id <= 0) return [];
    $d = rs_art_cached('tgdb-i:' . $id, 7 * 86400, function () use ($id) {
        $j = rs_art_tgdb('/v1/Games/Images', ['games_id' => $id]);
        return is_array($j['data'] ?? null) ? $j['data'] : null;
    });
    if (!is_array($d)) return [];
    $base = (array)($d['base_url'] ?? []);
    $orig = (string)($base['original'] ?? 'https://cdn.thegamesdb.net/images/original/');
    $small = (string)($base['medium'] ?? $base['thumb'] ?? $orig);
    $want = match ($kind) {
        'cover' => ['boxart'], 'hero' => ['fanart', 'screenshot', 'banner'], 'logo' => ['clearlogo'], 'icon' => ['boxart', 'clearlogo'],
    };
    $out = [];
    foreach ($want as $type) foreach ((array)(($d['images'] ?? [])[(string)$id] ?? []) as $x) {
        if (($x['type'] ?? '') !== $type || empty($x['filename'])) continue;
        if ($type === 'boxart' && ($x['side'] ?? 'front') === 'back' && $kind === 'icon') continue;
        [$w, $h] = array_map('intval', array_pad(explode('x', (string)($x['resolution'] ?? '')), 2, 0));
        $label = ucfirst($type === 'clearlogo' ? 'logo' : $type) . ($type === 'boxart' ? ' (' . (string)($x['side'] ?? 'front') . ')' : '');
        $out[] = ['url' => $orig . $x['filename'], 'thumb' => ($type === 'clearlogo' ? $orig : $small) . $x['filename'], 'w' => $w, 'h' => $h, 'label' => $label];
    }
    // Front covers first.
    usort($out, fn($a, $b) => (int)str_contains($a['label'], 'back') <=> (int)str_contains($b['label'], 'back'));
    return $out;
}

// ---- Libretro thumbnails (no key) ---------------------------------------------------------------

function rs_art_libretro_list(string $system): array {
    $dir = dirname(RS_PUBLIC) . '/recompstore-private/art-cache';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $f = $dir . '/libretro-' . md5($system) . '.json';
    if (is_file($f) && time() - (int)filemtime($f) < 14 * 86400) { $l = json_decode((string)file_get_contents($f), true); if (is_array($l)) return $l; }
    $r = rs_http('https://thumbnails.libretro.com/' . rawurlencode($system) . '/Named_Boxarts/', ['timeout' => 25, 'max' => 16 << 20]);
    if ($r['status'] !== 200) return [];
    preg_match_all('/href="([^"]+)\.png"/', (string)$r['body'], $m);
    $list = array_values(array_unique(array_map(fn($s) => rawurldecode(html_entity_decode($s)), $m[1])));
    if ($list) @file_put_contents($f, json_encode($list, JSON_UNESCAPED_UNICODE));
    return $list;
}

function rs_art_norm(string $s): string {
    $s = mb_strtolower(preg_replace('/\s*[\(\[].*$/u', '', $s));
    $s = str_replace(['&', ' and '], ' ', $s);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s));
}

function rs_art_libretro_search(string $q, string $origin): array {
    $systems = isset(RS_ART_LIBRETRO[$origin]) ? [$origin => RS_ART_LIBRETRO[$origin]] : [];
    if (!$systems) return [['id' => '', 'name' => 'Pick the game\'s console first (the From box) so Libretro knows where to look.', 'year' => '', 'platform' => '', 'note' => '']];
    $words = array_filter(explode(' ', rs_art_norm($q)));
    $out = [];
    foreach ($systems as $sys) foreach (rs_art_libretro_list($sys) as $name) {
        $n = ' ' . rs_art_norm($name) . ' ';
        $ok = true;
        foreach ($words as $w) if (!str_contains($n, ' ' . $w . ' ') && !str_contains($n, ' ' . $w)) { $ok = false; break; }
        if (!$ok) continue;
        preg_match('/\(([^)]*)\)/', $name, $reg);
        $out[] = ['id' => $sys . '|' . $name, 'name' => preg_replace('/\s*[\(\[].*$/', '', $name), 'year' => '', 'platform' => preg_replace('/^[^-]+ - /', '', $sys), 'note' => $reg[1] ?? '',
            'score' => abs(strlen(rs_art_norm($name)) - strlen(implode(' ', $words)))];
        if (count($out) >= 200) break;
    }
    usort($out, fn($a, $b) => $a['score'] <=> $b['score']);
    return array_map(function ($x) { unset($x['score']); return $x; }, array_slice($out, 0, 40));
}

function rs_art_libretro_images(string $id, string $kind): array {
    [$sys, $name] = array_pad(explode('|', $id, 2), 2, '');
    if ($sys === '' || $name === '' || !in_array($sys, RS_ART_LIBRETRO, true)) return [];
    $folders = match ($kind) {
        'cover' => ['Named_Boxarts' => 'Box art'], 'hero' => ['Named_Snaps' => 'In game', 'Named_Titles' => 'Title screen'],
        'logo' => ['Named_Logos' => 'Logo'], 'icon' => ['Named_Boxarts' => 'Box art', 'Named_Logos' => 'Logo'],
    };
    $out = [];
    // Libretro swaps these characters for _ in its file names.
    $file = str_replace(['&', '*', '/', ':', '`', '<', '>', '?', '\\', '|', '"'], '_', $name) . '.png';
    foreach ($folders as $folder => $label) {
        $u = 'https://thumbnails.libretro.com/' . rawurlencode($sys) . '/' . $folder . '/' . rawurlencode($file);
        $out[] = ['url' => $u, 'thumb' => $u, 'w' => 0, 'h' => 0, 'label' => $label];
    }
    return $out;
}

// ---- Steam store (no key) -----------------------------------------------------------------------

function rs_art_steam_search(string $q): array {
    $items = rs_art_cached('steam-s:' . strtolower($q), 7 * 86400, function () use ($q) {
        $j = rs_art_json(rs_http('https://store.steampowered.com/api/storesearch/?' . http_build_query(['term' => $q, 'l' => 'english', 'cc' => 'US']), ['timeout' => 15]));
        return is_array($j['items'] ?? null) ? $j['items'] : null;
    });
    return array_map(fn($x) => ['id' => (string)($x['id'] ?? ''), 'name' => (string)($x['name'] ?? ''), 'year' => '', 'platform' => 'PC', 'note' => (string)($x['type'] ?? '') === 'app' ? '' : (string)($x['type'] ?? '')], array_slice((array)$items, 0, 20));
}

function rs_art_steam_images(int $id, string $kind): array {
    if ($id <= 0) return [];
    $cdn = 'https://shared.cloudflare.steamstatic.com/store_item_assets/steam/apps/' . $id . '/';
    $out = match ($kind) {
        'cover' => [['library_600x900_2x.jpg', 'Library cover', 600 * 2, 900 * 2], ['library_600x900.jpg', 'Library cover', 600, 900]],
        'hero' => [['library_hero.jpg', 'Library backdrop', 3840, 1240], ['page_bg_raw.jpg', 'Store background', 0, 0], ['header.jpg', 'Header', 460, 215]],
        'logo' => [['logo.png', 'Logo', 0, 0], ['logo_2x.png', 'Logo', 0, 0]],
        'icon' => [['library_600x900_2x.jpg', 'Library cover', 1200, 1800]],
    };
    $out = array_map(fn($x) => ['url' => $cdn . $x[0], 'thumb' => $cdn . $x[0], 'w' => $x[2], 'h' => $x[3], 'label' => $x[1]], $out);
    if ($kind === 'hero') {
        $shots = rs_art_cached('steam-d:' . $id, 7 * 86400, function () use ($id) {
            $j = rs_art_json(rs_http('https://store.steampowered.com/api/appdetails?appids=' . $id . '&filters=screenshots', ['timeout' => 15]));
            return is_array($j[(string)$id]['data']['screenshots'] ?? null) ? $j[(string)$id]['data']['screenshots'] : [];
        });
        foreach (array_slice((array)$shots, 0, 12) as $s) if (!empty($s['path_full']))
            $out[] = ['url' => (string)$s['path_full'], 'thumb' => (string)($s['path_thumbnail'] ?? $s['path_full']), 'w' => 1920, 'h' => 1080, 'label' => 'Screenshot'];
    }
    return $out;
}

// ---- The picture itself, for the picker's crop (same origin) --------------------------------------

function rs_art_host_ok(string $url): bool {
    $p = parse_url($url);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host'])) return false;
    $h = strtolower($p['host']);
    foreach (RS_ART_HOSTS as $ok) if ($h === $ok || str_ends_with($h, '.' . $ok)) return true;
    return false;
}
