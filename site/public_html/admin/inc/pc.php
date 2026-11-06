<?php
// RecompStore (2.8): RecompStore for PC, at recompstore.com/pc. PC recomps live apart from the PS5
// store's games (their own table, their own catalog, pc-catalog.json), so nothing here can reach a
// PS5. Developer accounts and pages are shared.
//
// Rule one, here as everywhere: no game files. A PC recomp is listed only as its developer's own
// GitHub release; players bring their own copy of the game.
//
// The finder looks on GitHub for PC recomps (topics and names recomps go by), reads each one's latest
// release for its Windows (and Linux, macOS) download, guesses the game and the console it came from,
// and, with a SteamGridDB key in Settings, finds its art. You add what it found with one press.
declare(strict_types=1);

const RS_PC_CATALOG = RS_PUBLIC . '/pc-catalog.json';
// The consoles recomps come from, in the order the site lists them.
const RS_PC_ORIGINS = ['n64' => 'Nintendo 64', 'x360' => 'Xbox 360', 'xbox' => 'Xbox', 'ps1' => 'PlayStation', 'ps2' => 'PlayStation 2',
    'ps3' => 'PlayStation 3', 'ps4' => 'PlayStation 4', 'gc' => 'GameCube', 'wii' => 'Wii', 'wiiu' => 'Wii U', 'snes' => 'Super Nintendo', 'gba' => 'Game Boy Advance',
    'gb' => 'Game Boy', 'ds' => 'Nintendo DS', 'dc' => 'Dreamcast', 'saturn' => 'Saturn', 'psp' => 'PSP', 'switch' => 'Nintendo Switch',
    'nes' => 'NES', 'gbc' => 'Game Boy Color', '3ds' => 'Nintendo 3DS', 'vb' => 'Virtual Boy', 'ps5' => 'PlayStation 5', 'genesis' => 'Genesis', 's32x' => '32X',
    'gg' => 'Game Gear', 'arcade' => 'Arcade', 'cdi' => 'CD-i', 'amiga' => 'Amiga', 'pc' => 'PC', 'other' => 'Other'];
// (2.8) What kind of game it is: each one a category of the PC store, with its own page (/pc/<slug>).
const RS_PC_GENRES = ['action' => 'Action', 'adventure' => 'Adventure', 'platformer' => 'Platformers', 'racing' => 'Racing', 'fighting' => 'Fighting',
    'shooter' => 'Shooters', 'sports' => 'Sports', 'rpg' => 'RPG', 'puzzle' => 'Puzzle', 'other' => 'Other'];
const RS_PC_GENRE_SLUGS = ['action' => 'action', 'adventure' => 'adventure', 'platformer' => 'platformers', 'racing' => 'racing', 'fighting' => 'fighting',
    'shooter' => 'shooters', 'sports' => 'sports', 'rpg' => 'rpg', 'puzzle' => 'puzzle'];
// Recompilers and toolkits: not games, never listed as one.
const RS_PC_TOOLKITS = ['n64recomp/n64recomp', 'n64recomp/n64modernruntime', 'hedge-dev/xenonrecomp', 'hedge-dev/xenosrecomp', 'rexglue/rexglue-sdk',
    'hollywoodakeem/rexglue-sdk-yukes', 'ghidraninja/ps2recomp', 'ran-j/ps2recomp', 'ran-j/psxrecomp', 'grumpycoders/pcsx-redux'];
const RS_PC_SEARCHES = ['topic:n64recomp', 'topic:recomp', 'topic:static-recompilation', 'topic:recompilation', 'topic:xenonrecomp', 'topic:rexglue',
    'recompiled in:name', 'recomp in:name', 'recompiled pc port in:description'];

function rs_pc_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS pc_apps (
        id VARCHAR(64) PRIMARY KEY,
        data MEDIUMTEXT NOT NULL,
        sort INT NOT NULL DEFAULT 0,
        updated DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS pc_found (
        repo VARCHAR(160) PRIMARY KEY,
        data MEDIUMTEXT NOT NULL,
        found DATETIME NOT NULL,
        checked DATETIME NOT NULL,
        state VARCHAR(8) NOT NULL DEFAULT \'new\'
    ) CHARACTER SET utf8mb4');
    // (2.8) Players' word on each recomp: runs well, has issues or doesn't run, on which system.
    $db->exec('CREATE TABLE IF NOT EXISTS pc_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        app VARCHAR(64) NOT NULL,
        verdict VARCHAR(8) NOT NULL,
        sys VARCHAR(12) NOT NULL DEFAULT \'\',
        version VARCHAR(40) NOT NULL DEFAULT \'\',
        note VARCHAR(600) NOT NULL DEFAULT \'\',
        ip_hash CHAR(64) NOT NULL,
        created DATETIME NOT NULL,
        hidden TINYINT NOT NULL DEFAULT 0,
        KEY app (app, hidden, created),
        UNIQUE KEY one (app, ip_hash)
    ) CHARACTER SET utf8mb4');
    // (3.9) Longer notes (a bad report has to say what happened), the player's PC in a few words, and
    // their account when they were signed in (never shown; it's how they hear back from the developer).
    if (!$db->query("SHOW COLUMNS FROM pc_feedback LIKE 'specs'")->fetch())
        $db->exec("ALTER TABLE pc_feedback MODIFY note VARCHAR(1200) NOT NULL DEFAULT '', ADD COLUMN account_id INT NULL, ADD COLUMN specs VARCHAR(600) NOT NULL DEFAULT ''");
    // (4.0) Played through Proton: a Windows build on Linux or Steam Deck, as RecompLauncher reports it.
    if (!$db->query("SHOW COLUMNS FROM pc_feedback LIKE 'proton'")->fetch())
        $db->exec("ALTER TABLE pc_feedback ADD COLUMN proton TINYINT(1) NOT NULL DEFAULT 0");
    $done = true;
}

// (4.0) A project's standing, when it's not just active: on hold, or no more updates coming.
const RS_PC_STATUSES = ['paused', 'ended'];

function rs_pc_defaults(): array {
    return ['id' => '', 'name' => '', 'game' => '', 'origin' => 'other', 'repo' => '', 'asset_win' => '', 'asset_linux' => '', 'asset_mac' => '',
        'version' => '', 'released' => '', 'size' => '', 'downloads' => 0, 'stars' => 0,
        'blurb' => '', 'adds' => '', 'description' => '', 'needs' => '', 'credits' => '', 'author' => '', 'dev' => '',
        'icon' => '', 'cover' => '', 'hero' => '', 'logo' => '', 'tags' => '', 'links' => [],
        'featured' => false, 'hidden' => true, 'soon' => false, 'added' => '', 'live_at' => '', 'checked' => '', 'features' => '', 'features_read' => false, 'license' => '',
        'genre' => '', 'media' => '', 'issues' => 0, 'releases' => 0, 'first' => '',
        'ai' => '', 'ai_uses' => [], 'ai_note' => '', 'ai_by' => '', 'boot' => '', 'report_url' => '', 'host' => '',
        'status' => '', 'status_note' => '', 'archived' => false];
}

// (3.9) Where a recomp's project lives: '' GitHub, 'gitlab' GitLab. Its key ('owner/name', or
// 'gitlab.com/group/project') is what the finder and the security checks file it under.
function rs_pc_host(array $a): string { return rs_host_norm((string)($a['host'] ?? '')); }
function rs_pc_key(array $a): string { return rs_repo_key(rs_pc_host($a), (string)($a['repo'] ?? '')); }

// (3.8) Read once per request: pages that ask for the list for every row (submissions, the Inbox)
// don't decode all 600+ recomps each time. Saving one clears the copy.
function rs_pc_apps(bool $includeHidden = true): array {
    if (!isset($GLOBALS['rs_pc_apps_cache'])) {
        rs_pc_tables();
        $all = [];
        foreach (rs_db()->query('SELECT id, data, sort FROM pc_apps ORDER BY sort, id')->fetchAll() as $r) {
            $a = array_merge(rs_pc_defaults(), json_decode((string)$r['data'], true) ?: []);
            $a['id'] = (string)$r['id'];
            $a['sort'] = (int)$r['sort'];
            $all[] = $a;
        }
        $GLOBALS['rs_pc_apps_cache'] = $all;
    }
    return $includeHidden ? $GLOBALS['rs_pc_apps_cache'] : array_values(array_filter($GLOBALS['rs_pc_apps_cache'], fn($a) => !$a['hidden']));
}

function rs_pc_app(string $id): ?array {
    rs_pc_tables();
    $st = rs_db()->prepare('SELECT id, data, sort FROM pc_apps WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $a = array_merge(rs_pc_defaults(), json_decode((string)$r['data'], true) ?: []);
    $a['id'] = (string)$r['id'];
    $a['sort'] = (int)$r['sort'];
    return $a;
}

function rs_pc_save(array $a): void {
    unset($GLOBALS['rs_pc_apps_cache']);
    rs_pc_tables();
    $db = rs_db();
    $data = $a; unset($data['id'], $data['sort']);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $st = $db->prepare('SELECT COUNT(*) FROM pc_apps WHERE id = ?');
    $st->execute([$a['id']]);
    if ((int)$st->fetchColumn()) $db->prepare('UPDATE pc_apps SET data = ?, updated = NOW() WHERE id = ?')->execute([$json, $a['id']]);
    else {
        $max = (int)$db->query('SELECT COALESCE(MAX(sort), 0) FROM pc_apps')->fetchColumn();
        $db->prepare('INSERT INTO pc_apps (id, data, sort, updated) VALUES (?, ?, ?, NOW())')->execute([$a['id'], $json, $max + 10]);
    }
}

// A page address from a repo or a name: zelda64recomp, unleashedrecomp...
function rs_pc_slug(string $s): string {
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '');
    return substr(trim($s, '-'), 0, 60) ?: 'app';
}

// ---- What a repo is ---------------------------------------------------------------------------

// The console a recomp's game came from, from what its repo says about itself.
function rs_pc_origin_guess(string $text): string {
    $t = strtolower($text);
    $rules = [['n64', '/\b(n64|nintendo 64|n64recomp|ultra ?64|majora|ocarina|banjo-kazooie|goldeneye 007\b(?! xbla))/'],
        ['x360', '/\b(xbox ?360|x360|xenon|xenonrecomp|rexglue|xex)\b/'], ['xbox', '/\b(original xbox|xbox \(original\)|xboxrecomp)\b/'],
        ['ps3', '/\b(ps3|playstation 3|rpcs3)\b/'], ['ps4', '/\b(ps4|playstation 4)\b/'], ['ps2', '/\b(ps2|playstation 2|ps2recomp)\b/'],
        ['ps1', '/\b(ps1|psx|playstation 1|psxrecomp|playstation one)\b/'], ['gc', '/\b(gamecube|ngc|dolphin decomp)\b/'], ['wiiu', '/\b(wii ?u|cemu)\b/'], ['wii', '/\bwii\b/'], ['psp', '/\b(psp|playstation portable)\b/'],
        ['snes', '/\b(snes|super nintendo|super famicom)\b/'], ['gba', '/\b(gba|game boy advance)\b/'], ['ds', '/\b(nds|nintendo ds)\b/'],
        ['gb', '/\b(game ?boy|gbc)\b/'], ['dc', '/\bdreamcast\b/'], ['saturn', '/\bsega saturn\b/'], ['switch', '/\b(nintendo switch|las ?recomp)\b/']];
    foreach ($rules as [$k, $re]) if (preg_match($re, $t)) return $k;
    return 'other';
}

// The game's name, from the repo's name and description: "Zelda64Recomp" -> "Zelda64",
// "A recompilation of The Legend of Zelda: Majora's Mask for PC" -> that title.
function rs_pc_game_guess(string $repoName, string $desc): string {
    if (preg_match('/(?:recompilation|recomp|port|decompilation) of (.+?)(?: for| to| on| \(|\.|,|$)/i', $desc, $m) && mb_strlen(trim($m[1])) >= 3)
        return trim(preg_replace('/^(the )?(xbox 360|n64|nintendo 64|ps2|ps3|gamecube|wii) (game )?/i', '', trim($m[1])) ?? $m[1]);
    $n = preg_replace('/[-_ ]?(recompiled|recomp|pc|port|decomp)$/i', '', $repoName) ?? $repoName;
    $n = preg_replace('/(?<=[a-z])(?=[A-Z])|[-_]+/', ' ', $n) ?? $n;
    return trim($n) ?: $repoName;
}

// A release's downloads for each system: [win, linux, mac] => asset name (or ''). Game data never:
// a ROM, disc image or anything huge is left out (and flagged).
function rs_pc_assets(array $assets, array &$flags): array {
    $out = ['win' => '', 'linux' => '', 'mac' => ''];
    $score = ['win' => -1, 'linux' => -1, 'mac' => -1];
    foreach ($assets as $a) {
        $n = (string)($a['name'] ?? '');
        $l = strtolower($n);
        if (preg_match('/\.(z64|n64|v64|iso|xex|xiso|bin|cue|chd|gcm|rvz|wbfs|nsp|xci|pkg)$/', $l)) { $flags['gamefiles'] = true; continue; }
        if ((int)($a['size'] ?? 0) > (3 << 30)) { $flags['huge'] = true; continue; }
        if (!preg_match('/\.(zip|7z|exe|msi|tar\.gz|tgz|appimage|dmg|flatpak)$/', $l)) continue;
        if (preg_match('/(ps5|ps4|prospero|switch|android|apk|ios|source|src|debug|symbols|pdb)/', $l)) continue;
        $sys = preg_match('/(linux|appimage|ubuntu|steamdeck|flatpak|\.tar\.gz|\.tgz)/', $l) ? 'linux'
            : (preg_match('/(mac|osx|darwin|\.dmg)/', $l) ? 'mac' : 'win');
        $s = ($sys === 'win' && preg_match('/(win|windows|x64|x86_64|msvc|pc)/', $l)) ? 2 : 1;
        if ($s > $score[$sys]) { $score[$sys] = $s; $out[$sys] = $n; }
    }
    return $out;
}

// One repo read from GitHub (or, (3.9), a GitLab project): what the finder and Add need.
// -> [ok, ...] or [ok => false, why]
function rs_pc_read_repo(string $repo, string $host = ''): array {
    $host = rs_host_norm($host);
    if ($host === 'gitlab') return rs_pc_read_gitlab($repo);
    if (!rs_repo_ok($repo)) return ['ok' => false, 'why' => 'Not a GitHub repo.'];
    if (in_array(strtolower($repo), RS_PC_TOOLKITS, true)) return ['ok' => false, 'why' => 'A recompiler, not a game.'];
    $info = rs_gh_cached($repo . '#pcinfo', rs_gh_api() . '/repos/' . $repo, 3600, fn(array $j): array => [
        'full_name' => (string)($j['full_name'] ?? ''), 'name' => (string)($j['name'] ?? ''), 'description' => (string)($j['description'] ?? ''),
        'stars' => (int)($j['stargazers_count'] ?? 0), 'topics' => array_values(array_map('strval', (array)($j['topics'] ?? []))),
        'owner' => (string)($j['owner']['login'] ?? ''), 'avatar' => (string)($j['owner']['avatar_url'] ?? ''), 'homepage' => (string)($j['homepage'] ?? ''),
        'archived' => (bool)($j['archived'] ?? false), 'fork' => (bool)($j['fork'] ?? false),
        'issues' => (int)($j['open_issues_count'] ?? 0),
        'license' => is_array($j['license'] ?? null) && ($j['license']['spdx_id'] ?? 'NOASSERTION') !== 'NOASSERTION' ? (string)$j['license']['spdx_id'] : '']);
    if ($info['status'] === 404) return ['ok' => false, 'why' => 'That repo doesn\'t exist, or it\'s private.'];
    if ($info['status'] !== 200) return ['ok' => false, 'why' => 'GitHub didn\'t answer. Try again later.'];
    $i = json_decode((string)$info['body'], true) ?: [];
    return rs_pc_read_releases($repo, '', $i);
}

// (3.9) A GitLab project, read the same way: its facts from /projects/:id, its releases in GitHub's shape.
function rs_pc_read_gitlab(string $repo): array {
    if (!rs_gl_path_ok($repo)) return ['ok' => false, 'why' => 'Not a GitLab project.'];
    $i = rs_gl_project($repo, 3600);
    $st = (int)($i['_status'] ?? 200);
    if ($st === 404) return ['ok' => false, 'why' => 'That GitLab project doesn\'t exist, or it\'s private.'];
    if ($st !== 200 || !$i) return ['ok' => false, 'why' => 'GitLab didn\'t answer. Try again later.'];
    if ((string)$i['full_name'] === '') $i['full_name'] = $repo;
    return rs_pc_read_releases((string)$i['full_name'], 'gitlab', $i);
}

// What both hosts share: the newest release, its files per system, the numbers.
function rs_pc_read_releases(string $repo, string $host, array $i): array {
    $rel = rs_host_releases($host, $repo, 3600);
    $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
    $latest = null;
    foreach ($list as $r) if (empty($r['draft']) && empty($r['prerelease'])) { $latest = $r; break; }
    if (!$latest && $list) $latest = $list[0];
    $flags = [];
    $assets = $latest ? rs_pc_assets((array)$latest['assets'], $flags) : ['win' => '', 'linux' => '', 'mac' => ''];
    $size = 0; $downloads = 0;
    foreach ($list as $r) foreach ((array)$r['assets'] as $a) $downloads += (int)($a['download_count'] ?? 0);
    if ($latest) foreach ((array)$latest['assets'] as $a) if ($a['name'] === $assets['win']) $size = (int)$a['size'];
    $text = $i['name'] . ' ' . $i['description'] . ' ' . implode(' ', $i['topics']);
    return ['ok' => true, 'repo' => (string)$i['full_name'] ?: $repo, 'name' => (string)$i['name'], 'description' => (string)$i['description'],
        'stars' => (int)$i['stars'], 'topics' => $i['topics'], 'owner' => (string)$i['owner'], 'avatar' => (string)$i['avatar'],
        'archived' => (bool)$i['archived'], 'fork' => (bool)$i['fork'], 'license' => (string)($i['license'] ?? ''), 'issues' => (int)($i['issues'] ?? 0),
        'releases' => count(array_filter($list, fn($r) => empty($r['draft']))), 'first' => $list ? substr((string)end($list)['published_at'], 0, 10) : '',
        'game' => rs_pc_game_guess((string)$i['name'], (string)$i['description']), 'origin' => rs_pc_origin_guess($text),
        'version' => $latest ? rs_tidy_version((string)$latest['tag_name']) : '', 'released' => $latest ? substr((string)$latest['published_at'], 0, 10) : '',
        'assets' => $assets, 'size' => $size, 'downloads' => $downloads, 'flags' => $flags,
        'pattern' => [
            'win' => $assets['win'] !== '' && $latest ? rs_asset_pattern($assets['win'], (string)$latest['tag_name']) : '',
            'linux' => $assets['linux'] !== '' && $latest ? rs_asset_pattern($assets['linux'], (string)$latest['tag_name']) : '',
            'mac' => $assets['mac'] !== '' && $latest ? rs_asset_pattern($assets['mac'], (string)$latest['tag_name']) : '']]
        + ($host === 'gitlab' ? ['host' => 'gitlab'] : []);
}

// ---- The finder -------------------------------------------------------------------------------

// Looks on GitHub for PC recomps for up to $budget seconds. -> [new found, looked at]
function rs_pc_find(int $budget = 25): array {
    rs_pc_tables();
    $db = rs_db();
    $start = microtime(true);
    $new = $looked = 0;
    $have = [];
    foreach ($db->query('SELECT repo, state, UNIX_TIMESTAMP(checked) t FROM pc_found')->fetchAll() as $r) $have[strtolower((string)$r['repo'])] = [(string)$r['state'], (int)$r['t']];
    $listed = [];
    foreach (rs_pc_apps() as $a) if ($a['repo'] !== '') $listed[strtolower(rs_pc_key($a))] = true;
    // PS5 ports aren't PC recomps.
    $ps5 = [];
    foreach (rs_games(true) as $g) if ((string)$g['repo'] !== '') $ps5[strtolower((string)$g['repo'])] = true;
    $put = $db->prepare('INSERT INTO pc_found (repo, data, found, checked, state) VALUES (?, ?, NOW(), NOW(), ?) ON DUPLICATE KEY UPDATE data = VALUES(data), checked = NOW(),
        state = IF(state IN (\'skip\', \'added\'), state, VALUES(state))');
    foreach (RS_PC_SEARCHES as $q) {
        if (microtime(true) - $start > $budget) break;
        $s = rs_gh_cached('pcsearch:' . $q, rs_gh_api() . '/search/repositories?q=' . rawurlencode($q . ' fork:false archived:false') . '&sort=stars&per_page=50', 86400,
            fn(array $r): array => ['items' => array_map(fn($x) => ['full_name' => (string)($x['full_name'] ?? '')], array_slice((array)($r['items'] ?? []), 0, 50))]);
        $items = $s['status'] === 200 ? (json_decode((string)$s['body'], true)['items'] ?? []) : [];
        foreach ($items as $x) {
            if (microtime(true) - $start > $budget) break 2;
            $repo = (string)$x['full_name'];
            $k = strtolower($repo);
            if (!rs_repo_ok($repo) || isset($ps5[$k]) || preg_match('/(ps5|prospero|ps4|switch|vita|3ds|android)/i', $repo)) continue;
            if (isset($have[$k]) && ($have[$k][0] !== 'none' || $have[$k][1] > time() - 14 * 86400) && $have[$k][1] > time() - 7 * 86400) continue;
            ++$looked;
            $r = rs_pc_read_repo($repo);
            $state = isset($listed[$k]) ? 'added' : 'new';
            if (!$r['ok'] || ($r['assets']['win'] === '' && $r['assets']['linux'] === '' && $r['assets']['mac'] === '')) $state = 'none'; // nothing to download (yet)
            $put->execute([$repo, json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $state]);
            $have[$k] = [$state, time()];
            if ($state === 'new') ++$new;
        }
    }
    rs_kv_put('pc_find_run', (string)time());
    return [$new, $looked];
}

// Looks a repo up by hand (the admin's box): it lands in the found list either way.
// (3.9) A gitlab.com link is read from GitLab; it's filed as gitlab.com/<project>.
function rs_pc_find_one(string $repo): array {
    rs_pc_tables();
    $l = rs_repo_from_link($repo);
    if (!$l) return ['ok' => false, 'why' => 'Paste a GitHub repo or a GitLab project, like github.com/owner/name or gitlab.com/group/project.'];
    $r = rs_pc_read_repo($l['repo'], $l['host']);
    if (!$r['ok']) return $r;
    $key = rs_repo_key($l['host'], (string)$r['repo']);
    $listed = false;
    foreach (rs_pc_apps() as $a) if (strcasecmp(rs_pc_key($a), $key) === 0) $listed = true;
    rs_db()->prepare('INSERT INTO pc_found (repo, data, found, checked, state) VALUES (?, ?, NOW(), NOW(), ?) ON DUPLICATE KEY UPDATE data = VALUES(data), checked = NOW(), state = VALUES(state)')
        ->execute([$key, json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $listed ? 'added' : 'new']);
    return $r;
}

function rs_pc_found(string $state = 'new'): array {
    rs_pc_tables();
    $st = rs_db()->prepare('SELECT repo, data, found, state FROM pc_found WHERE state = ? ORDER BY found DESC');
    $st->execute([$state]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $d = json_decode((string)$r['data'], true) ?: [];
        // (3.9) key: the row's own (gitlab.com/<project> for GitLab); repo: the project's path.
        $d['key'] = (string)$r['repo'];
        $d['host'] = rs_host_norm((string)($d['host'] ?? ''));
        $d['repo'] = $d['host'] === 'gitlab' && (string)($d['repo'] ?? '') !== '' ? (string)$d['repo'] : (string)$r['repo'];
        $d['found_at'] = (string)$r['found'];
        $out[] = $d;
    }
    usort($out, fn($a, $b) => ($b['stars'] ?? 0) <=> ($a['stars'] ?? 0));
    return $out;
}

// Twice a week, after the page has gone (like the PS5 finder).
function rs_pc_find_maybe(): void {
    try {
        $last = rs_kv_get('pc_find_try');
        if ($last && $last['age'] < 302400) return;
        rs_kv_put('pc_find_try', (string)time());
    } catch (Throwable $e) { return; }
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
        @set_time_limit(90);
        try { rs_pc_find(40); } catch (Throwable $e) { /* next time */ }
    });
}

// ---- Art from SteamGridDB (with the key in Settings) -------------------------------------------

function rs_sgdb_key(): string { return trim((string)rs_setting('sgdb_key', '')); }

function rs_sgdb(string $path): ?array {
    $key = rs_sgdb_key();
    if ($key === '') return null;
    $c = rs_kv_get('sgdb:' . $path);
    if ($c && $c['age'] < 7 * 86400) return json_decode($c['v'], true);
    $r = rs_http('https://www.steamgriddb.com/api/v2' . $path, ['headers' => ['Authorization: Bearer ' . $key], 'timeout' => 15, 'max' => 2 << 20]);
    if ($r['status'] !== 200) return null;
    $j = json_decode($r['body'], true);
    if (!is_array($j) || empty($j['success'])) return null;
    rs_kv_put('sgdb:' . $path, json_encode($j['data'] ?? []));
    return $j['data'] ?? [];
}

// The best art SteamGridDB has for a game: [game, cover, hero, logo, icon] (URLs, '' when none).
// $avoid: per kind, pictures another recomp already wears (two recomps of one game get their own);
// $skip: how many more to pass over, for "Different art".
function rs_sgdb_art(string $game, array $avoid = [], int $skip = 0): array {
    $out = ['game' => '', 'cover' => '', 'hero' => '', 'logo' => '', 'icon' => ''];
    $hits = rs_sgdb('/search/autocomplete/' . rawurlencode($game));
    if (!$hits) return $out;
    $g = $hits[0];
    $out['game'] = (string)($g['name'] ?? '');
    $id = (int)($g['id'] ?? 0);
    $pick = function (?array $list, string $kind) use ($avoid, $skip): string {
        $urls = array_values(array_filter(array_map(fn($x) => (string)($x['url'] ?? ''), (array)$list), fn($u) => $u !== '' && !in_array($u, (array)($avoid[$kind] ?? []), true)));
        if (!$urls) return $list ? (string)($list[0]['url'] ?? '') : '';
        return $urls[$skip % count($urls)];
    };
    $out['cover'] = $pick(rs_sgdb('/grids/game/' . $id . '?dimensions=600x900&types=static&nsfw=false&humor=false'), 'cover');
    $out['hero'] = $pick(rs_sgdb('/heroes/game/' . $id . '?types=static&nsfw=false&humor=false'), 'hero');
    $out['logo'] = $pick(rs_sgdb('/logos/game/' . $id . '?types=static&nsfw=false&humor=false'), 'logo');
    $out['icon'] = $pick(rs_sgdb('/icons/game/' . $id . '?types=static&nsfw=false&humor=false'), 'icon');
    return $out;
}

// The SteamGridDB pictures the other recomps already wear: [kind => [url, ...]].
function rs_pc_art_used(string $exceptId): array {
    $used = [];
    foreach (rs_pc_apps() as $x) if ($x['id'] !== $exceptId) foreach (['cover', 'hero', 'logo', 'icon'] as $k) if (!empty($x[$k . '_src'])) $used[$k][] = (string)$x[$k . '_src'];
    return $used;
}

// A picture from the web into /media, as the PS5 games' are (icon 512, hero 3840 + 1920, logo
// 1600x800 PNG) or a cover (600x900 JPEG). -> fields to merge, or [].
function rs_pc_media(string $url, string $kind, string $id): array {
    if (!preg_match('#^https://#', $url)) return [];
    $r = rs_http($url, ['max' => 25 << 20, 'timeout' => 30]);
    if ($r['status'] !== 200 || $r['body'] === '') return [];
    $tmp = tempnam(sys_get_temp_dir(), 'pcart');
    file_put_contents($tmp, $r['body']);
    try {
        if ($kind === 'cover') return rs_pc_media_file($tmp, $id);
        $out = rs_save_image(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($r['body'])], $kind, 'pc-' . rs_pc_slug($id));
        if ($kind === 'hero') return ['hero' => $out['hero_web'] ?? $out['hero'] ?? ''];
        return $out;
    } catch (Throwable $e) {
        return [];
    } finally {
        @unlink($tmp);
    }
}

// A cover (a file already here) at 600x900, cropped to fit. -> ['cover' => path]
function rs_pc_media_file(string $tmp, string $id): array {
    [$src, $w, $h] = rs_image_open(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int)filesize($tmp)]);
    $dst = imagecreatetruecolor(600, 900);
    $k = max(600 / $w, 900 / $h);
    $sw = (int)round(600 / $k); $sh = (int)round(900 / $k);
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $sw, 2), intdiv($h - $sh, 2), 600, 900, $sw, $sh);
    $name = 'pc-' . rs_pc_slug($id) . '-cover-' . bin2hex(random_bytes(4)) . '.jpg';
    imagejpeg($dst, RS_MEDIA . '/' . $name, 85);
    return ['cover' => '/media/' . $name];
}

// A found recomp, added as a hidden PC app (you look it over, then show it): its GitHub facts, and
// its art from SteamGridDB when there's a key (else the developer's GitHub picture as its icon).
// (3.9) $repo: owner/name, a GitHub link, or a GitLab project (gitlab.com/group/project, with or without https://).
function rs_pc_add(string $repo): array {
    $l = rs_repo_from_link($repo);
    $want = $l ? rs_repo_key($l['host'], $l['repo']) : trim($repo);
    $f = null;
    foreach (array_merge(rs_pc_found('new'), rs_pc_found('skip')) as $x) if (strcasecmp($x['key'], $want) === 0) $f = $x;
    if (!$f) $f = rs_pc_find_one($repo);
    if (empty($f['ok']) && !isset($f['repo'])) throw new RuntimeException($f['why'] ?? 'That repo couldn\'t be read.');
    if (empty($f['repo'])) throw new RuntimeException($f['why'] ?? 'That repo couldn\'t be read.');
    $host = rs_host_norm((string)($f['host'] ?? ''));
    $id = rs_pc_slug((string)$f['name']);
    $taken = array_column(rs_pc_apps(), 'id');
    for ($n = 2; in_array($id, $taken, true); ++$n) $id = rs_pc_slug((string)$f['name']) . '-' . $n;
    $a = array_merge(rs_pc_defaults(), [
        'id' => $id, 'name' => preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', (string)$f['name']) ?: (string)$f['name'], 'game' => (string)$f['game'], 'origin' => (string)$f['origin'],
        'repo' => (string)$f['repo'], 'asset_win' => (string)($f['pattern']['win'] ?? ''), 'asset_linux' => (string)($f['pattern']['linux'] ?? ''), 'asset_mac' => (string)($f['pattern']['mac'] ?? ''),
        'version' => (string)$f['version'], 'released' => (string)$f['released'], 'size' => $f['size'] ? rs_human_size((int)$f['size']) : '',
        'downloads' => (int)$f['downloads'], 'stars' => (int)$f['stars'], 'blurb' => mb_substr((string)$f['description'], 0, 160),
        'author' => (string)$f['owner'], 'needs' => 'Your own copy of ' . ($f['game'] ?: 'the game'), 'hidden' => true, 'added' => gmdate('Y-m-d'),
        'host' => $host,
    ]);
    if (rs_sgdb_key() !== '' && $a['game'] !== '') {
        $art = rs_sgdb_art($a['game'], rs_pc_art_used($id));
        if ($art['game'] !== '') $a['game'] = $art['game'];
        foreach (['cover', 'hero', 'logo', 'icon'] as $k) if ($art[$k] !== '' && ($m = rs_pc_media($art[$k], $k, $id))) { $a = array_merge($a, $m); $a[$k . '_src'] = $art[$k]; }
    }
    if ($a['icon'] === '' && preg_match('#^https://avatars\.githubusercontent\.com/#', (string)$f['avatar'])) $a = array_merge($a, rs_pc_media((string)$f['avatar'] . '&s=512', 'icon', $id));
    elseif ($a['icon'] === '' && $host === 'gitlab' && preg_match('#^https://gitlab\.com/uploads/#', (string)$f['avatar'])) $a = array_merge($a, rs_pc_media((string)$f['avatar'], 'icon', $id));
    unset($a['hero_web']);
    $a['license'] = (string)($f['license'] ?? '');
    $a = rs_pc_fill_features($a);
    if ($host === '') $a = rs_ai_on_add($a, $a['repo']); // (2.9) (3.9) the AI check reads GitHub only
    rs_pc_save($a);
    rs_db()->prepare("UPDATE pc_found SET state = 'added' WHERE repo = ?")->execute([rs_pc_key($a)]);
    return $a;
}

// ---- README and key features (2.8) ------------------------------------------------------------

// A repo's README as Markdown (cached a day), and where its relative pictures live.
function rs_pc_readme(string $repo, string $host = ''): array {
    if (rs_host_norm($host) === 'gitlab') return rs_pc_readme_gitlab($repo);
    if (!rs_repo_ok($repo)) return ['status' => 400, 'md' => ''];
    $r = rs_gh_cached($repo . '#readme', rs_gh_api() . '/repos/' . $repo . '/readme', 86400, function (array $j): array {
        $md = (string)base64_decode((string)($j['content'] ?? ''), false);
        if (strlen($md) > 200000) $md = substr($md, 0, 200000);
        return ['md' => mb_convert_encoding($md, 'UTF-8', 'UTF-8'), 'path' => (string)($j['path'] ?? 'README.md'), 'url' => (string)($j['html_url'] ?? '')];
    });
    $j = json_decode((string)$r['body'], true) ?: [];
    $dir = trim(dirname((string)($j['path'] ?? 'README.md')), './');
    return ['status' => $r['status'], 'md' => (string)($j['md'] ?? ''), 'url' => (string)($j['url'] ?? ''),
        'raw' => 'https://raw.githubusercontent.com/' . $repo . '/HEAD/' . ($dir !== '' ? $dir . '/' : ''),
        'blob' => 'https://github.com/' . $repo . '/blob/HEAD/' . ($dir !== '' ? $dir . '/' : '')];
}

// (3.9) A GitLab project's README: the file its project page names (README.md when it names none),
// on its default branch, from /projects/:id/repository/files/<file>?ref=<branch> (cached a day).
function rs_pc_readme_gitlab(string $repo): array {
    if (!rs_gl_path_ok($repo)) return ['status' => 400, 'md' => ''];
    $p = rs_gl_project($repo, 86400);
    $st = (int)($p['_status'] ?? 200);
    if ($st !== 200 || !$p) return ['status' => $st === 404 ? 404 : 503, 'md' => ''];
    $branch = (string)$p['default_branch'];
    if ($branch === '') return ['status' => 404, 'md' => '']; // an empty project
    $web = 'https://gitlab.com/' . $repo;
    $file = 'README.md';
    $ru = (string)$p['readme_url'];
    if ($ru === '' && (string)$p['web_url'] !== '') return ['status' => 404, 'md' => ''];
    $pre = '/-/blob/' . $branch . '/';
    if (($at = strpos($ru, $pre)) !== false) {
        $f = rawurldecode(substr($ru, $at + strlen($pre)));
        if ($f !== '' && strlen($f) < 300 && !str_contains($f, '..') && preg_match('#^[\w./ -]+$#u', $f)) $file = $f;
    }
    $r = rs_gh_cached('gl:' . $repo . '#readme', rs_gl_api() . '/projects/' . rawurlencode($repo) . '/repository/files/' . rawurlencode($file) . '?ref=' . rawurlencode($branch), 86400,
        function (array $j) use ($file): array {
            $md = (string)base64_decode((string)($j['content'] ?? ''), false);
            if (strlen($md) > 200000) $md = substr($md, 0, 200000);
            return ['md' => mb_convert_encoding($md, 'UTF-8', 'UTF-8'), 'path' => (string)($j['file_path'] ?? $file)];
        }, 'gitlab');
    $j = json_decode((string)$r['body'], true) ?: [];
    $path = (string)($j['path'] ?? $file);
    $dir = trim(dirname($path), './');
    $seg = fn(string $s) => implode('/', array_map('rawurlencode', explode('/', $s)));
    $sub = $dir !== '' ? $seg($dir) . '/' : '';
    return ['status' => $r['status'], 'md' => (string)($j['md'] ?? ''), 'url' => $web . '/-/blob/' . $seg($branch) . '/' . $seg($path),
        'raw' => $web . '/-/raw/' . $seg($branch) . '/' . $sub, 'blob' => $web . '/-/blob/' . $seg($branch) . '/' . $sub];
}

// The README's list under a Features (or Highlights, Improvements...) heading: up to 8 short lines.
function rs_pc_features_from_md(string $md): array {
    $md = preg_replace('/<!--.*?-->/s', '', $md);
    $lines = preg_split('/\R/', $md);
    $in = false; $out = [];
    foreach ($lines as $l) {
        $t = trim($l);
        if (preg_match('/^#{1,6}\s*(.+)$/', $t, $m) || preg_match('/^<h[1-6][^>]*>(.*?)<\/h[1-6]>$/i', $t, $m)) {
            if ($in && $out) break;
            $h = strip_tags($m[1]);
            $in = (bool)preg_match('/\b(features?|highlights|improvements|enhancements|what\'s (new|included|different))\b/i', $h)
                && !preg_match('/\b(planned|upcoming|todo|to do|roadmap|missing|known|future|wip|not yet|issues?)\b/i', $h);
            continue;
        }
        if (!$in) continue;
        if (preg_match('/^(?:[-*+]|\d+[.)])\s+(.+)$/', $t, $m)) {
            $x = $m[1];
            $x = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $x);
            $x = preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $x);
            $x = trim(strip_tags(str_replace(['**', '__', '`'], '', $x)));
            $x = preg_replace('/\s+/', ' ', $x);
            // Long ones end at their first sentence (or their name, before a colon or dash).
            if (mb_strlen($x) > 90 && preg_match('/^(.{20,90}?)(?:\.\s|:\s|\s[–—-]\s)/u', $x, $c)) $x = $c[1];
            if ($x !== '' && mb_strlen($x) >= 4) $out[] = mb_strimwidth($x, 0, 120, '…');
            if (count($out) >= 8) break;
        } elseif ($t !== '' && $out && !preg_match('/^\s{2,}/', $l)) {
            break;
        }
    }
    return $out;
}

// Key features from the README, once (the admin's own edits stay).
function rs_pc_fill_features(array $a, bool $force = false): array {
    if ($a['repo'] === '' || (!$force && $a['features_read'] && (int)($a['features_v'] ?? 0) >= 2)) return $a;
    $r = rs_pc_readme($a['repo'], rs_pc_host($a));
    if ($r['status'] !== 200) return $a;
    $f = rs_pc_features_from_md($r['md']);
    if ($f || $force || (int)($a['features_v'] ?? 0) < 2) $a['features'] = implode("\n", $f);
    $a['features_read'] = true;
    $a['features_v'] = 2;
    return $a;
}

// ---- Around the web (2.8): videos and posts about a recomp, added in the admin -------------------

// One URL per line -> [{type: youtube|reddit|x|link, url, id?, host}]
function rs_pc_media_links(string $text): array {
    $out = [];
    foreach (preg_split('/\R/', $text) as $l) {
        $u = trim($l);
        if (!preg_match('#^https://[^\s<>"]{4,400}$#', $u)) continue;
        $host = strtolower((string)parse_url($u, PHP_URL_HOST));
        $host = preg_replace('/^(www|m|old|new|mobile)\./', '', $host);
        if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $u, $m)) $out[] = ['type' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=' . $m[1], 'id' => $m[1], 'host' => 'YouTube'];
        elseif ($host === 'reddit.com' || $host === 'redd.it') $out[] = ['type' => 'reddit', 'url' => $u, 'host' => 'Reddit', 'title' => rs_pc_reddit_title($u)];
        elseif ($host === 'x.com' || $host === 'twitter.com') $out[] = ['type' => 'x', 'url' => $u, 'host' => 'X', 'title' => preg_match('#^https://[^/]+/([A-Za-z0-9_]{1,15})/status/#', $u, $m) ? '@' . $m[1] : ''];
        else $out[] = ['type' => 'link', 'url' => $u, 'host' => $host];
        if (count($out) >= 12) break;
    }
    return $out;
}

// A Reddit post's title from its address (r/<sub>/comments/<id>/<slug>).
function rs_pc_reddit_title(string $u): string {
    if (!preg_match('#/r/([A-Za-z0-9_]{2,21})/comments/[a-z0-9]+/([a-z0-9_]+)#i', $u, $m)) return '';
    return 'r/' . $m[1] . ' · ' . ucfirst(str_replace('_', ' ', $m[2]));
}

// ---- Player feedback (2.8) --------------------------------------------------------------------

const RS_PC_VERDICTS = ['runs' => 'Plays great', 'issues' => 'Has problems', 'broken' => 'Won\'t run'];
const RS_PC_SYSTEMS = ['windows' => 'Windows', 'linux' => 'Linux', 'macos' => 'macOS', 'deck' => 'Steam Deck'];

// {app: {runs: n, issues: n, broken: n}}
function rs_pc_feedback_counts(): array {
    rs_pc_tables();
    $out = [];
    foreach (rs_db()->query('SELECT app, verdict, COUNT(*) AS n FROM pc_feedback WHERE hidden = 0 GROUP BY app, verdict')->fetchAll() as $r)
        $out[(string)$r['app']][(string)$r['verdict']] = (int)$r['n'];
    return $out;
}

// (3.9) A recomp's "how to report bugs" link: https only, 300 characters at most. '' when it isn't one.
function rs_pc_report_url(string $u): string {
    $u = trim($u);
    if ($u === '' || mb_strlen($u) > 300 || !preg_match('#^https://[^\s<>"\']+$#i', $u)) return '';
    $host = (string)parse_url($u, PHP_URL_HOST);
    return $host !== '' && filter_var($u, FILTER_VALIDATE_URL) ? $u : '';
}

function rs_pc_feedback_list(string $app, bool $withHidden = false, int $limit = 60): array {
    rs_pc_tables();
    $st = rs_db()->prepare('SELECT id, verdict, sys, version, note, specs, created, hidden, proton FROM pc_feedback WHERE app = ?' . ($withHidden ? '' : ' AND hidden = 0') . ' ORDER BY created DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute([$app]);
    return $st->fetchAll();
}

// ---- The catalog the site (and later the PC launcher) reads ------------------------------------

// (3.5) About the game: the original game's facts from IGDB (release date, who made it, genres,
// modes, a short summary), shown in its own card. Uses the IGDB keys from Settings.
const RS_IGDB_PLATFORMS = ['n64' => 4, 'x360' => 12, 'xbox' => 11, 'ps1' => 7, 'ps2' => 8, 'ps3' => 9, 'ps4' => 48, 'gc' => 21, 'wii' => 5, 'wiiu' => 41,
    'snes' => 19, 'gba' => 24, 'gb' => 33, 'ds' => 20, 'dc' => 23, 'saturn' => 32, 'psp' => 38, 'switch' => 130, 'nes' => 18, 'gbc' => 22, '3ds' => 37,
    'vb' => 87, 'ps5' => 167, 'genesis' => 29, 's32x' => 30, 'gg' => 35, 'arcade' => 52, 'cdi' => 117, 'amiga' => 16];

function rs_pc_about_norm(string $s): string {
    $t = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : false;
    if (is_string($t) && $t !== '') $s = $t;
    $s = strtolower(preg_replace('/\s*[\(\[].*?[\)\]]\s*/', ' ', $s));
    return preg_replace('/[^a-z0-9]+/', '', str_replace(['&', 'é', 'ō', 'ū'], ['and', 'e', 'o', 'u'], $s));
}

function rs_pc_about_fetch(array $a, int $igdbId = 0): ?array {
    require_once __DIR__ . '/artsrc.php';
    if (rs_art_igdb_token() === '') throw new RuntimeException('Add the IGDB keys in Settings first.');
    $F = 'fields name,url,first_release_date,summary,genres.name,game_modes.name,platforms.id,platforms.name,involved_companies.developer,'
        . 'involved_companies.publisher,involved_companies.company.name,franchises.name,collection.name,total_rating,total_rating_count;';
    $q = trim(preg_split('/;| \/ | \+ /', (string)($a['game'] !== '' ? $a['game'] : $a['name']))[0]);
    if ($igdbId > 0) $list = (array)(rs_art_igdb('games', $F . ' where id = ' . $igdbId . ';') ?? []);
    else {
        $clean = trim(preg_replace('/\s*\((?:[^)]*)\)\s*/', ' ', $q));
        $list = (array)(rs_art_igdb('games', 'search "' . str_replace(['\\', '"'], '', $clean) . '"; ' . $F . ' where version_parent = null; limit 20;') ?? []);
    }
    $want = rs_pc_about_norm($q); $plat = RS_IGDB_PLATFORMS[$a['origin']] ?? 0;
    $best = null; $bestScore = 0;
    foreach ($list as $g) {
        if (!is_array($g) || empty($g['id'])) continue;
        $n = rs_pc_about_norm((string)($g['name'] ?? ''));
        $s = $igdbId > 0 ? 1000 : 0;
        if ($n === $want) $s += 100;
        elseif ($n !== '' && $want !== '' && (str_starts_with($n, $want) || str_starts_with($want, $n))) $s += 40;
        elseif ($n !== '' && $want !== '' && (str_contains($n, $want) || str_contains($want, $n))) $s += 20;
        if ($plat && in_array($plat, array_map(fn($p) => (int)($p['id'] ?? 0), (array)($g['platforms'] ?? [])), true)) $s += 50;
        if (!empty($g['summary'])) $s += 5;
        $s += min(10, (int)(($g['total_rating_count'] ?? 0) / 20));
        if ($s > $bestScore) { $bestScore = $s; $best = $g; }
    }
    if (!$best || $bestScore < 90) return null;
    $g = $best;
    $co = (array)($g['involved_companies'] ?? []);
    $names = fn(string $role) => array_values(array_unique(array_map(fn($c) => (string)$c['company']['name'], array_filter($co, fn($c) => !empty($c[$role]) && !empty($c['company']['name'])))));
    $list2 = fn($k, $n) => array_slice(array_values(array_unique(array_filter(array_map(fn($x) => (string)($x['name'] ?? ''), (array)($g[$k] ?? []))))), 0, $n);
    $sum = trim(preg_replace('/\s+/', ' ', (string)($g['summary'] ?? '')));
    if (mb_strlen($sum) > 700) { $cut = mb_substr($sum, 0, 700); $p = max(mb_strrpos($cut, '. '), mb_strrpos($cut, '! '), mb_strrpos($cut, '? ')); $sum = $p > 200 ? mb_substr($cut, 0, $p + 1) : rtrim($cut) . '…'; }
    return array_filter(['igdb' => (int)$g['id'], 'url' => (string)($g['url'] ?? ''), 'name' => (string)($g['name'] ?? ''),
        'released' => !empty($g['first_release_date']) ? gmdate('Y-m-d', (int)$g['first_release_date']) : '',
        'developer' => implode(', ', array_slice($names('developer'), 0, 3)), 'publisher' => implode(', ', array_slice($names('publisher'), 0, 3)),
        'genres' => $list2('genres', 4), 'modes' => $list2('game_modes', 4), 'platforms' => $list2('platforms', 10),
        'series' => (string)(($g['franchises'][0]['name'] ?? '') ?: ($g['collection']['name'] ?? '')),
        'rating' => (int)($g['total_rating_count'] ?? 0) >= 5 ? (int)round((float)($g['total_rating'] ?? 0)) : 0,
        'summary' => $sum], fn($v) => $v !== '' && $v !== [] && $v !== 0);
}

// (3.5) Sharper art: swaps a backdrop or logo for a bigger one SteamGridDB has for the same game,
// the best voted first. $have: kind => the width it is now. -> [app, kinds changed]
function rs_pc_art_upgrade(array $a, array $have): array {
    require_once __DIR__ . '/artsrc.php';
    $hits = rs_sgdb('/search/autocomplete/' . rawurlencode($a['game'] !== '' ? trim(preg_split('/[;\/]/', $a['game'])[0]) : $a['name']));
    if (!$hits) return [$a, []];
    $gid = (int)($hits[0]['id'] ?? 0); $used = rs_pc_art_used($a['id']); $done = [];
    $votes = fn(array $x) => preg_match('/(\d+) votes?/', (string)$x['label'], $m) ? (int)$m[1] : 0;
    foreach ($have as $k => $w) {
        if (!in_array($k, ['hero', 'logo'], true)) continue;
        $list = array_values(array_filter(rs_art_sgdb_images($gid, $k), fn($x) => $x['w'] >= max((int)$w * 1.15, $k === 'hero' ? 1920 : 600)
            && !in_array($x['url'], (array)($used[$k] ?? []), true) && ($k !== 'hero' || ($x['h'] > 0 && $x['w'] / $x['h'] > 2.2))));
        if (!$list) continue;
        usort($list, fn($p, $q) => [$votes($q), $q['w']] <=> [$votes($p), $p['w']]);
        foreach (array_slice($list, 0, 3) as $pick) {
            $m = rs_pc_media($pick['url'], $k, $a['id']);
            if (!empty($m[$k])) { $a[$k] = $m[$k]; $a[$k . '_src'] = $pick['url']; $done[] = $k; break; }
        }
    }
    return [$a, $done];
}

function rs_pc_catalog(): array {
    $abs = 'rs_abs';
    $apps = [];
    foreach (rs_pc_apps(false) as $i => $a) {
        $e = ['id' => $a['id'], 'name' => $a['name'], 'game' => $a['game'], 'origin' => $a['origin'], 'order' => $i,
            'repo' => $a['repo'], 'version' => $a['version'], 'released' => $a['released'], 'size' => $a['size'],
            'downloads' => (int)$a['downloads'], 'stars' => (int)$a['stars'], 'blurb' => $a['blurb'], 'adds' => (string)($a['adds'] ?? ''), 'description' => $a['description'],
            'needs' => $a['needs'], 'credits' => $a['credits'], 'author' => $a['author'],
            'icon' => $abs($a['icon']), 'cover' => $abs($a['cover']), 'hero' => $abs($a['hero']), 'logo' => $abs($a['logo']),
            'systems' => array_keys(array_filter(['windows' => $a['asset_win'], 'linux' => $a['asset_linux'], 'macos' => $a['asset_mac']])),
            'assets' => array_filter(['windows' => $a['asset_win'], 'linux' => $a['asset_linux'], 'macos' => $a['asset_mac']]),
            'tags' => array_values(array_filter(array_map('trim', explode(',', (string)$a['tags'])))),
            // (2.9) Newest arrivals: when it went live (to the second), else the day it was added.
            'added' => (string)($a['live_at'] ?? '') !== '' ? (string)$a['live_at'] : $a['added'],
            'features' => array_values(array_filter(array_map('trim', explode("\n", (string)$a['features'])))), 'license' => (string)$a['license'],
            'genre' => isset(RS_PC_GENRES[$a['genre']]) ? $a['genre'] : 'other', 'media' => rs_pc_media_links((string)$a['media']),
            'issues' => (int)$a['issues'], 'releases' => (int)$a['releases'], 'first' => (string)$a['first']];
        if ($a['dev'] !== '') $e['dev'] = $a['dev'];
        // (3.9) A GitLab project: "repo" is its path there (group/project). Left out for GitHub.
        if (rs_pc_host($a) === 'gitlab') $e['host'] = 'gitlab';
        // (3.9) Where its developer wants bugs reported (their own page or issue form).
        if (($ru = rs_pc_report_url((string)($a['report_url'] ?? ''))) !== '') $e['report_url'] = $ru;
        // (3.8) The release that's out (the launcher installs this one) and its checked files.
        if ((string)($a['ok_tag'] ?? '') !== '') $e['tag'] = (string)$a['ok_tag'];
        if (function_exists('rs_vt_catalog_files') && ($vf = rs_vt_catalog_files($a))) $e['files'] = $vf;
        if ($a['featured']) $e['featured'] = true;
        if (!empty($a['soon'])) $e['soon'] = true;
        if (!empty($a['about']) && is_array($a['about'])) $e['about'] = $a['about'];
        if (!empty($a['setup_required'])) { $e['setup_required'] = true; $e['setup_steps'] = array_values(array_filter(array_map('trim', explode("\n", (string)($a['setup_steps'] ?? ''))))); }
        if ($a['links']) $e['links'] = $a['links'];
        // (4.0) Where the project stands: 'ended' (no more updates) or 'paused' (on hold), set on its edit
        // page, with the developer's own words if any. "archived": closed on GitHub, shown as ended too.
        $life = in_array((string)($a['status'] ?? ''), RS_PC_STATUSES, true) ? (string)$a['status'] : '';
        if ($life !== '') $e['status'] = $life;
        if (!empty($a['archived'])) $e['archived'] = true;
        if (($life !== '' || !empty($a['archived'])) && trim((string)($a['status_note'] ?? '')) !== '') $e['status_note'] = trim((string)$a['status_note']);
        $e += rs_ai_entry($a); // (2.9)
        // (3.3) How RecompLauncher starts it and sets it up from the player's copy (CURATOR-SETUP-GUIDE.md).
        $boot = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
        if (is_array($boot) && $boot) $e['boot'] = $boot;
        $apps[] = $e;
    }
    $origins = [];
    foreach (RS_PC_ORIGINS as $k => $n) if (array_filter($apps, fn($x) => $x['origin'] === $k)) $origins[] = ['id' => $k, 'name' => $n];
    $devs = [];
    foreach (rs_developers() as $slug => $d) if (array_filter($apps, fn($x) => ($x['dev'] ?? '') === $slug))
        $devs[] = ['slug' => $slug, 'name' => (string)$d['name'], 'verified' => !empty($d['verified']), 'avatar' => (string)($d['avatar'] ?? '') !== '' ? rs_abs((string)$d['avatar']) : ''];
    // (3.0) Games with more than one version, and art one version lends another.
    $titles = rs_title_catalog($apps);
    // (3.6) The banner on top of the PC home page, in the order picked on the Banner page.
    $have = array_flip(array_column($apps, 'id'));
    $banner = array_values(array_filter(rs_pc_banner(), fn($id) => isset($have[$id])));
    return ['catalogVersion' => 1, 'platform' => 'pc', 'updated' => gmdate('c'), 'apps' => $apps, 'origins' => $origins, 'developers' => $devs, 'titles' => $titles, 'banner' => $banner,
        'bannerInfo' => (object)array_intersect_key(rs_pc_banner_info(), array_flip($banner))];
}

// ---- (3.6) The PC home banner -------------------------------------------------------------------
// The recomps in the slider on top of recomplauncher.com, in order. Kept as a list (setting
// pc_banner); each one is also marked Featured, so everything that reads Featured agrees.
function rs_pc_banner(): array {
    $ids = json_decode((string)rs_setting('pc_banner', ''), true);
    if (is_array($ids) && $ids) return array_values(array_filter(array_map('strval', $ids), fn($x) => preg_match('/^[A-Za-z0-9._-]{1,64}$/', $x)));
    $out = [];
    foreach (rs_pc_apps(false) as $a) if (!empty($a['featured'])) $out[] = $a['id'];
    return $out;
}
// Each banner slide's own words: a tag above it (New release, Most popular...) and, if set, a line
// in place of the recomp's blurb. {id: {tag, text}}
function rs_pc_banner_info(): array {
    $j = json_decode((string)rs_setting('pc_banner_info', ''), true);
    $out = [];
    foreach (is_array($j) ? $j : [] as $id => $v) {
        if (!is_array($v)) continue;
        $tag = trim((string)($v['tag'] ?? '')); $text = trim((string)($v['text'] ?? ''));
        if ($tag !== '' || $text !== '') $out[(string)$id] = array_filter(['tag' => $tag, 'text' => $text], fn($x) => $x !== '');
    }
    return $out;
}
function rs_pc_banner_save_info(array $tags, array $texts, array $ids): void {
    $out = [];
    foreach ($ids as $id) {
        $tag = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($tags[$id] ?? ''))), 0, 32);
        $text = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($texts[$id] ?? ''))), 0, 160);
        if ($tag !== '' || $text !== '') $out[$id] = array_filter(['tag' => $tag, 'text' => $text], fn($x) => $x !== '');
    }
    rs_set_setting('pc_banner_info', json_encode($out, JSON_UNESCAPED_UNICODE));
}
function rs_pc_banner_save(array $ids): array {
    $ok = [];
    foreach (rs_pc_apps() as $a) $ok[$a['id']] = $a;
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn($x) => isset($ok[$x]))));
    rs_set_setting('pc_banner', json_encode($ids));
    $on = array_flip($ids);
    foreach ($ok as $id => $a) {
        $want = isset($on[$id]);
        if ((bool)$a['featured'] !== $want) { $a['featured'] = $want; rs_pc_save($a); }
    }
    return $ids;
}
// (3.5) The published catalog, written in one line (the launcher reads it), and next to it the
// website's lighter copy: the same list without what only a game's own page shows (the original
// game's details, the launch settings, "What's in it"). The site asks for those one game at a time.
function rs_pc_write_files(array $cat): bool {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $json = json_encode($cat, $flags);
    $tmp = RS_PC_CATALOG . '.tmp';
    if ($json === false || file_put_contents($tmp, $json . "\n") === false || !rename($tmp, RS_PC_CATALOG)) return false;
    rs_pc_write_web($cat);
    return true;
}
function rs_pc_web_copy(array $cat): array {
    foreach ($cat['apps'] as &$a) {
        $series = is_array($a['about'] ?? null) ? (string)($a['about']['series'] ?? '') : '';
        unset($a['about'], $a['boot'], $a['features']);
        if ($series !== '') $a['about'] = ['series' => $series];
        $a['lite'] = true;
    }
    unset($a);
    return $cat;
}
function rs_pc_write_web(array $cat): void {
    $f = RS_PUBLIC . '/pc-catalog-web.json';
    $json = json_encode(rs_pc_web_copy($cat), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false && file_put_contents($f . '.tmp', $json . "\n") !== false) @rename($f . '.tmp', $f);
}

// Writes pc-catalog.json from what's saved, without asking GitHub again (seconds, not minutes).
function rs_pc_write_catalog(): int {
    $cat = rs_pc_catalog();
    if (!rs_pc_write_files($cat))
        throw new RuntimeException('Could not write pc-catalog.json.');
    return count($cat['apps']);
}

// (2.8) One recomp's entry in the published pc-catalog.json brought up to date, the rest left as
// they were published (a verified developer's own change goes out straight away).
function rs_pc_publish_one(string $id): bool {
    $cat = json_decode((string)@file_get_contents(RS_PC_CATALOG), true);
    if (!is_array($cat) || !is_array($cat['apps'] ?? null)) return false;
    $fresh = null;
    foreach (rs_pc_catalog()['apps'] as $e) if ($e['id'] === $id) $fresh = $e;
    $hit = false;
    foreach ($cat['apps'] as $i => $e) if (($e['id'] ?? '') === $id && $fresh) { $fresh['order'] = $e['order'] ?? $fresh['order']; $cat['apps'][$i] = $fresh; $hit = true; }
    if (!$hit) return false;
    $cat['updated'] = gmdate('c');
    return rs_pc_write_files($cat);
}

// (2.9) Takes one recomp off pc-catalog.json at once (a copyright notice), without a full Publish.
function rs_pc_unlist_one(string $id): bool {
    $cat = json_decode((string)@file_get_contents(RS_PC_CATALOG), true);
    if (!is_array($cat) || !is_array($cat['apps'] ?? null)) return false;
    $before = count($cat['apps']);
    $cat['apps'] = array_values(array_filter($cat['apps'], fn($e) => ($e['id'] ?? '') !== $id));
    if (count($cat['apps']) === $before) return true;
    $cat['updated'] = gmdate('c');
    return rs_pc_write_files($cat);
}

// (3.5) When a developer renames their download (a date or "-x86_64" added, a new version scheme),
// the saved file pattern stops matching and the launcher says there's no build for that system.
// If the newest release has a file for that system, the pattern follows it.
function rs_pc_pattern(string $name, string $tag): string {
    $p = rs_asset_pattern($name, $tag);
    // A build date or commit in the name changes every release too.
    $p = preg_replace('/(?<=[-_.+])(?:\d{8}(?:[-_]\d{4,6})?|g?[0-9a-f]{7,12})(?=[-_.])/i', '*', $p) ?? $p;
    if ($p !== $name) return preg_replace('/\*(?:[-_.]?\*)+/', '*', $p) ?? $p;
    // No tag in the name: the version-looking part (1.2.3, v0.4-beta, a build date) becomes *.
    $p = preg_replace('/(?<![A-Za-z0-9])v?\d+(?:[._]\d+)+(?:[-_.]?(?:alpha|beta|rc|pre|preview)[\w.]*)?(?:[-_]\d{8}(?:[-_]\d{4,6})?)?/i', '*', $name, 1) ?? $name;
    return $p !== $name ? $p : $name;
}
function rs_pc_heal_assets(array $a, array $r): array {
    if (rs_pc_release_for($a)) return $a; // (3.8) a recent release still has its files: the newest just has other ones (Switch, Android)
    $rel = rs_host_releases(rs_pc_host($a), $a['repo'], 3600);
    $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
    $latest = null;
    foreach ($list as $x) if (empty($x['draft']) && empty($x['prerelease'])) { $latest = $x; break; }
    if (!$latest) return $a;
    $names = array_map(fn($x) => (string)$x['name'], (array)$latest['assets']);
    foreach (['win' => 'asset_win', 'linux' => 'asset_linux', 'mac' => 'asset_mac'] as $sys => $k) {
        $p = trim((string)$a[$k]);
        $new = (string)($r['assets'][$sys] ?? '');
        if ($p === '' || $new === '') continue;
        $hit = false;
        foreach ($names as $n) if (rs_pattern_matches($p, $n)) { $hit = true; break; }
        if ($hit) continue;
        $np = rs_pc_pattern($new, (string)$latest['tag_name']);
        if ($np !== '' && $np !== $p) { $a[$k] = $np; rs_log('pc-asset', $a['id'], "$sys: $p -> $np"); }
    }
    return $a;
}

// The release rs_pc_read_repo goes by: the newest that isn't a draft or a pre-release, else the newest.
function rs_pc_latest_release(string $repo, string $host = ''): ?array {
    $rel = rs_host_releases($host, $repo, 3600);
    $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
    foreach ($list as $x) if (empty($x['draft']) && empty($x['prerelease'])) return $x;
    return $list[0] ?? null;
}

// (3.8) The newest release (not a draft or pre-release) with a file matching one of the recomp's
// patterns; null when none of the recent ones has one.
function rs_pc_release_for(array $a): ?array {
    $pats = array_values(array_filter([(string)$a['asset_win'], (string)$a['asset_linux'], (string)$a['asset_mac']], fn($p) => trim($p) !== ''));
    $rel = rs_host_releases(rs_pc_host($a), (string)$a['repo'], 3600);
    $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
    foreach ($list as $x) {
        if (!empty($x['draft']) || !empty($x['prerelease'])) continue;
        if (!$pats) return $x;
        foreach ((array)$x['assets'] as $f) foreach ($pats as $p) if (rs_pattern_matches($p, (string)$f['name'])) return $x;
    }
    return null;
}

// (3.5) Each listed recomp's release as GitHub has it now: version, size, downloads. Oldest checked
// first, and only for as long as $seconds allows, so Publish never waits minutes on GitHub (the
// server's own refresh, tools/vps/pc-refresh.php, keeps the rest up to date in the background).
// Ones checked in the last $fresh seconds are skipped. -> how many were checked.
function rs_pc_refresh_github(float $seconds, int $fresh = 1800): int {
    $until = microtime(true) + $seconds;
    $apps = array_values(array_filter(rs_pc_apps(), fn($a) => $a['repo'] !== '' && time() - (int)($a['gh_at'] ?? 0) >= $fresh));
    usort($apps, fn($x, $y) => (int)($x['gh_at'] ?? 0) <=> (int)($y['gh_at'] ?? 0));
    $n = 0;
    foreach ($apps as $a) {
        if (microtime(true) > $until) break;
        $f = rs_pc_fill_features($a);
        $r = rs_pc_read_repo($a['repo'], rs_pc_host($a));
        $n++;
        // What GitHub says goes onto the recomp as it is now (an edit saved meanwhile stays).
        $a = rs_pc_app($a['id']);
        if (!$a) continue;
        foreach (['features', 'features_read', 'features_v'] as $k) if (array_key_exists($k, $f)) $a[$k] = $f[$k];
        $a['gh_at'] = time();
        if (!empty($r['ok'])) $a = rs_pc_heal_assets($a, $r);
        // (4.0) Archived on GitHub: the developer has closed it. The site and launcher show it as ended.
        if (!empty($r['ok'])) $a['archived'] = !empty($r['archived']);
        if (empty($r['ok']) || $r['version'] === '') { rs_pc_save($a); continue; }
        if (!empty($r['license'])) $a['license'] = (string)$r['license'];
        $a['issues'] = (int)($r['issues'] ?? 0); $a['releases'] = (int)($r['releases'] ?? 0); $a['first'] = (string)($r['first'] ?? '');
        $a['stars'] = (int)$r['stars']; $a['downloads'] = (int)$r['downloads'];
        // (3.8) A new release goes out at once when security checks are off, the first time a recomp is
        // seen, or when it's the release that's out already; otherwise it's held until it's checked.
        // (3.8) The newest release that has one of this recomp's files: a release with only a Switch,
        // Android or HD-pack download is passed over, so players never get the wrong file.
        $latest = rs_pc_release_for($a);
        if (!$latest) { rs_pc_save($a); continue; }
        $r['version'] = rs_tidy_version((string)$latest['tag_name']);
        $r['released'] = substr((string)$latest['published_at'], 0, 10);
        $r['size'] = 0;
        foreach ((array)$latest['assets'] as $x) if ($a['asset_win'] !== '' && rs_pattern_matches((string)$a['asset_win'], (string)$x['name'])) { $r['size'] = (int)($x['size'] ?? 0); break; }
        $tag = (string)$latest['tag_name'];
        $okTag = (string)($a['ok_tag'] ?? '');
        if (!function_exists('rs_vt_on') || !rs_vt_on() || $tag === '' || $okTag === '' || $tag === $okTag) {
            $a['version'] = $r['version']; $a['released'] = $r['released'];
            if ($r['size']) $a['size'] = rs_human_size((int)$r['size']);
            if ($tag !== '') $a['ok_tag'] = $tag;
            unset($a['pending']);
        } else $a = rs_vt_hold($a, $latest, $r);
        rs_pc_save($a);
    }
    return $n;
}

function rs_pc_publish(): int {
    rs_pc_refresh_github(20);
    if (function_exists('rs_titles_autogroup')) rs_titles_autogroup(); // (3.8) one game, one listing
    $cat = rs_pc_catalog();
    if (!rs_pc_write_files($cat))
        throw new RuntimeException('Could not write pc-catalog.json. Check that public_html is writable.');
    rs_log('publish', 'pc-catalog', count($cat['apps']) . ' apps');
    return count($cat['apps']);
}
