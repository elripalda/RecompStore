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
    'gb' => 'Game Boy', 'ds' => 'Nintendo DS', 'dc' => 'Dreamcast', 'saturn' => 'Saturn', 'psp' => 'PSP', 'switch' => 'Nintendo Switch', 'switch2' => 'Nintendo Switch 2',
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
// (4.1) The above take the top 50 by stars, so new and small projects never showed up. These look at
// everything pushed in the last 30 days, newest first, 100 a search.
const RS_PC_SEARCHES_RECENT = ['recomp in:name', 'recompiled in:name', 'recompilation in:name', '"static recompilation"', '"native pc port"',
    '"pc port" in:description', 'decomp "pc port"', 'decompilation port in:description', 'rexglue', 'xenonrecomp', 'ps2recomp', 'ps3recomp', 'psxrecomp', 'n64recomp'];

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
    if (rs_col_missing($db, 'pc_feedback', 'specs'))
        $db->exec("ALTER TABLE pc_feedback MODIFY note VARCHAR(1200) NOT NULL DEFAULT '', ADD COLUMN account_id INT NULL, ADD COLUMN specs VARCHAR(600) NOT NULL DEFAULT ''");
    // (4.0) Played through Proton: a Windows build on Linux or Steam Deck, as RecompLauncher reports it.
    if (rs_col_missing($db, 'pc_feedback', 'proton'))
        $db->exec("ALTER TABLE pc_feedback ADD COLUMN proton TINYINT(1) NOT NULL DEFAULT 0");
    $done = true;
}

// (4.0) A project's standing, when it's not just active: on hold, or no more updates coming.
// (4.3) complete: finished by its developer's word, so it's never shown as ended or quiet.
const RS_PC_STATUSES = ['complete', 'paused', 'ended'];

function rs_pc_defaults(): array {
    return ['id' => '', 'name' => '', 'game' => '', 'origin' => 'other', 'repo' => '', 'asset_win' => '', 'asset_linux' => '', 'asset_mac' => '',
        'version' => '', 'released' => '', 'size' => '', 'downloads' => 0, 'stars' => 0,
        'blurb' => '', 'adds' => '', 'description' => '', 'needs' => '', 'credits' => '', 'author' => '', 'dev' => '',
        'icon' => '', 'cover' => '', 'hero' => '', 'logo' => '', 'tags' => '', 'links' => [],
        'featured' => false, 'hidden' => true, 'soon' => false, 'added' => '', 'live_at' => '', 'checked' => '', 'features' => '', 'features_read' => false, 'license' => '',
        'genre' => '', 'media' => '', 'issues' => 0, 'releases' => 0, 'first' => '',
        'ai' => '', 'ai_uses' => [], 'ai_note' => '', 'ai_by' => '', 'boot' => '', 'copy_ids' => '', 'report_url' => '', 'host' => '', 'follow' => '',
        'status' => '', 'status_note' => '', 'archived' => false];
}

// (3.9) Where a recomp's project lives: '' GitHub, 'gitlab' GitLab. Its key ('owner/name', or
// 'gitlab.com/group/project') is what the finder and the security checks file it under.
// (4.1) Copy IDs as the launcher reads them: upper case, letters and digits, 3 to 12 long, no repeats.
function rs_pc_copy_ids(string $s): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtoupper($s)) ?: [] as $x) {
        $x = preg_replace('/[^A-Z0-9]/', '', $x);
        if (strlen($x) >= 3 && strlen($x) <= 12 && !in_array($x, $out, true)) $out[] = $x;
    }
    return array_slice($out, 0, 24);
}
// (4.7) A recomp that carries several games (COGBox: Gears of War 1 to Judgment; Athelas: the Lord of
// the Rings trilogy): one install, and in RecompLauncher a tile per game. Each game: id (a-z0-9, 1 to 16,
// its full id is "<recomp id>--<game id>"), name, args (added after start.args to play it), title_id
// (Xbox 360, names its achievement unlock file), inputs (the setup inputs only it needs), and its own art.
const RS_PC_GAMES_MAX = 12;
function rs_pc_games(array $a): array {
    $out = [];
    foreach ((array)($a['games'] ?? []) as $g) {
        if (!is_array($g) || !preg_match('/^[a-z0-9]{1,16}$/', (string)($g['id'] ?? '')) || trim((string)($g['name'] ?? '')) === '') continue;
        $out[] = $g;
    }
    return count($out) >= 2 ? $out : [];
}
// The admin's lines: "id | name | launch args | title id | inputs", one game per line. Art and other
// fields a game already had (same id) stay. -> [games, error or '']
function rs_pc_games_parse(string $text, array $old): array {
    $had = [];
    foreach ((array)$old as $g) if (is_array($g) && isset($g['id'])) $had[(string)$g['id']] = $g;
    $out = []; $seen = [];
    foreach (preg_split('/\R/', trim($text)) ?: [] as $n => $line) {
        if (trim($line) === '') continue;
        $c = array_map('trim', explode('|', $line));
        $id = strtolower($c[0] ?? '');
        $name = mb_substr($c[1] ?? '', 0, 120);
        if (!preg_match('/^[a-z0-9]{1,16}$/', $id)) return [[], 'Line ' . ($n + 1) . ': the id is 1 to 16 lowercase letters and digits (gow2).'];
        if ($name === '') return [[], 'Line ' . ($n + 1) . ': it needs a name.'];
        if (isset($seen[$id])) return [[], 'Line ' . ($n + 1) . ': "' . $id . '" is there twice.'];
        $seen[$id] = true;
        $args = array_values(array_filter(preg_split('/\s+/', mb_substr($c[2] ?? '', 0, 300)) ?: [], fn($x) => $x !== ''));
        $tid = strtoupper(preg_replace('/^0x/i', '', $c[3] ?? ''));
        if ($tid !== '' && !preg_match('/^[0-9A-F]{8}$/', $tid)) return [[], 'Line ' . ($n + 1) . ': a title id is 8 hex digits (4D53082D).'];
        $inputs = array_values(array_filter(array_map('trim', explode(',', $c[4] ?? '')), fn($x) => preg_match('/^[a-z0-9_.-]{1,40}$/', $x)));
        $g = array_merge($had[$id] ?? [], ['id' => $id, 'name' => $name]);
        foreach (['args' => $args, 'title_id' => $tid, 'inputs' => $inputs] as $k => $v) { if ($v === [] || $v === '') unset($g[$k]); else $g[$k] = $v; }
        $out[] = $g;
    }
    if (count($out) > RS_PC_GAMES_MAX) return [[], 'Up to ' . RS_PC_GAMES_MAX . ' games.'];
    if (count($out) === 1) return [[], 'A pack has two games or more. For one game, leave this empty.'];
    return [$out, ''];
}
function rs_pc_games_text(array $games): string {
    return implode("\n", array_map(fn($g) => rtrim(implode(' | ', [$g['id'], $g['name'], implode(' ', (array)($g['args'] ?? [])), (string)($g['title_id'] ?? ''), implode(', ', (array)($g['inputs'] ?? []))]), ' |'), $games));
}
// Art from SteamGridDB for each game that has none yet (or all of them with $redo). -> [app, games given art]
function rs_pc_games_art(array $a, bool $redo = false): array {
    $games = (array)($a['games'] ?? []); $n = 0;
    foreach ($games as $i => $g) {
        if (!is_array($g) || (!$redo && (string)($g['cover'] ?? '') !== '')) continue;
        $art = rs_sgdb_art((string)$g['name']);
        $m = rs_pc_media_many(array_intersect_key($art, array_flip(['cover', 'hero', 'logo', 'icon'])), $a['id'] . '-' . $g['id']);
        foreach (['cover', 'hero', 'logo', 'icon'] as $k) if (!empty($m[$k])) $games[$i][$k] = $m[$k];
        if (!empty($m['cover'])) $n++;
    }
    $a['games'] = $games;
    return [$a, $n];
}
// For the catalog: the games with their art as full addresses.
function rs_pc_games_entry(array $a): array {
    return array_map(function ($g) {
        $e = ['id' => (string)$g['id'], 'name' => (string)$g['name']];
        foreach (['args', 'title_id', 'inputs'] as $k) if (!empty($g[$k])) $e[$k] = $g[$k];
        foreach (['icon', 'cover', 'hero', 'logo'] as $k) if ((string)($g[$k] ?? '') !== '') $e[$k] = rs_abs((string)$g[$k]);
        return $e;
    }, rs_pc_games($a));
}
// A game's full id ("cogbox--gow2") -> [recomp, game] when it's one of a pack's games, else null.
function rs_pc_game_of(string $full): ?array {
    if (!preg_match('/^([a-z0-9][a-z0-9_.-]*)--([a-z0-9]{1,16})$/i', $full, $m)) return null;
    $a = rs_pc_app($m[1]);
    if (!$a) return null;
    foreach (rs_pc_games($a) as $g) if ($g['id'] === $m[2]) return [$a, $g];
    return null;
}

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
        ['gb', '/\b(game ?boy|gbc)\b/'], ['dc', '/\bdreamcast\b/'], ['saturn', '/\bsega saturn\b/'], ['switch2', '/\b(nintendo )?switch ?2\b/'], ['switch', '/\b(nintendo switch|las ?recomp)\b/']];
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
    // (4.3) Each system's file is scored, not just the first that fits: the game's own build beats an
    // add-on (a DLSS pack, a mod pack, a server, a patch), 64-bit beats 32-bit, a portable build beats
    // an installer, and between equals the bigger file (the game, not its extras) wins.
    $out = ['win' => '', 'linux' => '', 'mac' => ''];
    $best = ['win' => null, 'linux' => null, 'mac' => null];
    foreach ($assets as $a) {
        $n = (string)($a['name'] ?? '');
        $l = strtolower($n);
        $size = (int)($a['size'] ?? 0);
        if (preg_match('/\.(z64|n64|v64|iso|xex|xiso|bin|cue|chd|gcm|rvz|wbfs|nsp|xci|pkg)$/', $l)) { $flags['gamefiles'] = true; continue; }
        if ($size > (3 << 30)) { $flags['huge'] = true; continue; }
        // (4.3) .rar too: the launcher installs it on Windows, Linux and Mac.
        if (!preg_match('/\.(zip|7z|rar|exe|msi|tar\.gz|tgz|appimage|dmg|flatpak)$/', $l)) continue;
        // (4.8) Whole words only: "ios" used to match inside Wario's Woods and BioShock, so their builds
        // were skipped and Auto-add said there was no download.
        if (preg_match('/(^|[^a-z0-9])(ps5|ps4|prospero|switch2?|nx|nsp|xci|android|apk|aab|ios|ipa|vita|source|source-?code|src|debug|symbols|pdb)([^a-z0-9]|$)/', $l)
            || preg_match('/\.(apk|ipa|nsp|xci|nro|vpk|pdb)$/', $l)) continue;
        // Not the game itself: an add-on, an upscaler pack, a mod or texture pack, a soundtrack, a
        // dedicated server, an SDK, a patch (but a "patcher" can be the game's own way in).
        if (preg_match('/(add-?on|dlss|reshade|mod-?pack|texture|soundtrack|(^|[-_. ])ost([-_. ]|$)|wallpaper|manual|(^|[-_. ])server|dedicated|(^|[-_. ])sdk([-_. ]|$)|(^|[-_. ])patch(?!er)|hotfix-only)/', $l)) { $flags['extras'] = true; continue; }
        $sys = preg_match('/(windows|win64|win32|(^|[-_. ])win([-_. ]|$))/', $l) && !preg_match('/(linux|appimage|darwin|macos)/', $l) ? 'win'
            : (preg_match('/(linux|appimage|ubuntu|steamdeck|flatpak|\.tar\.gz|\.tgz)/', $l) ? 'linux'
            : (preg_match('/(mac|osx|darwin|\.dmg)/', $l) ? 'mac' : 'win'));
        $inst = (bool)preg_match('/(setup|installer|(^|[-_. ])install(?!ed)|\.msi$)/', $l);
        $sc = 10;
        // A file that names Windows beats one that only says "pc" or "x64" (a project's own updater zip,
        // "Game-PC-1.0.zip", sits next to "Game.PC.1.0.-.Windows.zip" in some releases).
        if ($sys === 'win' && preg_match('/(windows|win64|(^|[-_. ])win([-_. ]|$))/', $l)) $sc += 3;
        elseif ($sys === 'win' && preg_match('/(win|x64|x86_64|msvc|pc)/', $l)) $sc += 2;
        if (preg_match('/(x86(?!_64)|win32|i386|i686|32-?bit)/', $l)) $sc -= 2;
        if ($sys === 'linux' && preg_match('/(arm64|aarch64)/', $l)) $sc -= 2;
        if ($sys === 'mac' && preg_match('/(arm64|universal|apple)/', $l)) $sc += 2;
        if ($sys === 'win' && preg_match('/\.(tar\.gz|tgz)$/', $l)) $sc -= 1; // Windows players open a .zip more easily
        if ($inst) $sc -= 5;
        $cur = $best[$sys];
        if ($cur === null || $sc > $cur['sc'] || ($sc === $cur['sc'] && $size > $cur['size'])) $best[$sys] = ['sc' => $sc, 'size' => $size, 'name' => $n, 'inst' => $inst];
    }
    foreach ($best as $sys => $b) if ($b) $out[$sys] = $b['name'];
    // Only an installer for Windows: the launcher's installer flow (Installer only), not a plain install.
    if ($best['win'] && $best['win']['inst']) {
        // A small "...Setup.zip" is usually a kit that builds the game from the player's copy (Setup required).
        if (preg_match('/\.(zip|7z|rar)$/', strtolower($best['win']['name'])) && $best['win']['size'] > 0 && $best['win']['size'] < (25 << 20)) $flags['setup_kit'] = true;
        else $flags['installer_only'] = true;
    }
    return $out;
}

// One repo read from GitHub (or, (3.9), a GitLab project): what the finder and Add need.
// -> [ok, ...] or [ok => false, why]
function rs_pc_read_repo(string $repo, string $host = ''): array {
    $host = rs_host_norm($host);
    if ($host === 'gitlab') return rs_pc_read_gitlab($repo);
    if ($host === 'sourceforge') return rs_pc_read_sourceforge($repo);
    if ($host === 'forgejo') return rs_pc_read_forgejo($repo);
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

// (4.9) A project on a developer's own Forgejo server (Longshot on git.magicbean.dev).
function rs_pc_read_forgejo(string $repo): array {
    if (!rs_fj_path_ok($repo)) return ['ok' => false, 'why' => 'Not a project on a Forgejo server on the list (Settings).'];
    $i = rs_fj_project($repo, 3600);
    $st = (int)($i['_status'] ?? 200);
    if ($st === 404) return ['ok' => false, 'why' => 'That project doesn\'t exist, or it\'s private.'];
    if ($st !== 200 || !$i) return ['ok' => false, 'why' => 'Its server didn\'t answer. Try again later.'];
    return rs_pc_read_releases($repo, 'forgejo', $i);
}

// (4.3) A SourceForge project: its facts from its project API, its releases from its file feed.
function rs_pc_read_sourceforge(string $repo): array {
    if (!rs_sf_name_ok($repo)) return ['ok' => false, 'why' => 'Not a SourceForge project.'];
    $i = rs_sf_project($repo, 3600);
    $st = (int)($i['_status'] ?? 200);
    if ($st === 404) return ['ok' => false, 'why' => 'That SourceForge project doesn\'t exist.'];
    if ($st !== 200 || !$i) return ['ok' => false, 'why' => 'SourceForge didn\'t answer. Try again later.'];
    $i['full_name'] = $repo;
    return rs_pc_read_releases($repo, 'sourceforge', $i);
}

// What every host shares: the newest release, its files per system, the numbers.
// (4.8) The release players get: the newest by when it came out (GitHub lists by the tag's commit
// date, so a source-only release made from an older commit can sit above the builds), skipping drafts
// and pre-releases; when that one has no build, the newest one that does, as the launcher does. -> null
// when there's no release.
function rs_pc_pick_release(array $list): ?array {
    $rels = rs_rel_sorted(array_filter($list, fn($x) => is_array($x) && empty($x['draft'])));
    if (!$rels) return null;
    $full = array_values(array_filter($rels, fn($x) => empty($x['prerelease'])));
    $flags = [];
    foreach ([$full, $rels] as $pool) foreach ($pool as $r) if (array_filter(rs_pc_assets((array)($r['assets'] ?? []), $flags))) return $r;
    return $full[0] ?? $rels[0];
}

function rs_pc_read_releases(string $repo, string $host, array $i): array {
    $rel = rs_host_releases($host, $repo, 3600);
    $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
    $latest = rs_pc_pick_release($list);
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
        'tag' => $latest ? (string)$latest['tag_name'] : '',
        'assets' => $assets, 'size' => $size, 'downloads' => $downloads, 'flags' => $flags,
        'pattern' => [
            'win' => $assets['win'] !== '' && $latest ? rs_asset_pattern($assets['win'], (string)$latest['tag_name']) : '',
            'linux' => $assets['linux'] !== '' && $latest ? rs_asset_pattern($assets['linux'], (string)$latest['tag_name']) : '',
            'mac' => $assets['mac'] !== '' && $latest ? rs_asset_pattern($assets['mac'], (string)$latest['tag_name']) : '']]
        + ($host !== '' ? ['host' => $host] : []);
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
    $since = gmdate('Y-m-d', time() - 30 * 86400);
    $searches = array_merge(array_map(fn($q) => [$q, 'stars', 50], RS_PC_SEARCHES), array_map(fn($q) => [$q . ' pushed:>' . $since, 'updated', 100], RS_PC_SEARCHES_RECENT));
    foreach ($searches as [$q, $sort, $per]) {
        if (microtime(true) - $start > $budget) break;
        $s = rs_gh_cached('pcsearch:' . $sort . ':' . $q, rs_gh_api() . '/search/repositories?q=' . rawurlencode($q . ' fork:false archived:false') . '&sort=' . $sort . '&per_page=' . $per, 86400,
            fn(array $r): array => ['items' => array_map(fn($x) => ['full_name' => (string)($x['full_name'] ?? '')], array_slice((array)($r['items'] ?? []), 0, 100))]);
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

// (4.1) The Discord server's #recomp-suggestions: links people post there land in the found list too,
// read with the site's bot (it needs View Channel and Read Message History there, and the Message
// Content Intent switched on for the app). Each link is looked at once, then again after 3 days if it
// had nothing to download. -> [new found, looked at, problem ('' when fine)]
const RS_DC_SUGGEST_CHANNEL = '1565266599353516132';
const RS_DC_SUGGEST_GUILD = '1556689421896654858';
function rs_pc_find_discord(int $budget = 30): array {
    rs_pc_tables();
    $start = microtime(true);
    $bot = function_exists('rs_dc_conf') ? (string)rs_dc_conf()['bot'] : '';
    $chan = trim((string)rs_setting('dc_suggest_channel', RS_DC_SUGGEST_CHANNEL));
    $done = function (int $new, int $looked, string $why) {
        rs_kv_put('pc_find_dc', (string)json_encode(['at' => time(), 'new' => $new, 'looked' => $looked, 'why' => $why]));
        return [$new, $looked, $why];
    };
    if ($bot === '' || !preg_match('/^\d{5,25}$/', $chan)) return $done(0, 0, 'The site\'s Discord bot isn\'t set up.');
    // (4.3) A forum: each post is a thread; its title and first message are read like a message was.
    $forum = rs_setting('dc_suggest_forum', '') === $chan;
    if ($forum) { [$st, $msgs] = rs_dc_forum_posts($chan, $bot); }
    else [$st, $msgs] = rs_dc_call('GET', '/channels/' . $chan . '/messages?limit=100', ['bot' => $bot]);
    if ($st === 403 || $st === 404) return $done(0, 0, 'The bot can\'t read #recomp-suggestions. Add it to the server and let it see that channel and its history.');
    if ($st !== 200 || !is_array($msgs)) return $done(0, 0, 'Discord didn\'t answer (' . $st . '). It tries again later.');
    $db = rs_db();
    $have = [];
    foreach ($db->query('SELECT repo, state, UNIX_TIMESTAMP(checked) t FROM pc_found')->fetchAll() as $r) $have[strtolower((string)$r['repo'])] = [(string)$r['state'], (int)$r['t']];
    $listed = $shown = [];
    foreach (rs_pc_apps() as $a) if ($a['repo'] !== '') { $listed[strtolower(rs_pc_key($a))] = true; if (empty($a['hidden'])) $shown[strtolower(rs_pc_key($a))] = true; }
    $put = $db->prepare('INSERT INTO pc_found (repo, data, found, checked, state) VALUES (?, ?, NOW(), NOW(), ?) ON DUPLICATE KEY UPDATE data = VALUES(data), checked = NOW(),
        state = IF(state IN (\'skip\', \'added\'), state, VALUES(state))');
    $new = $looked = 0;
    $blank = 0;
    foreach ($msgs as $m) {
        $text = (string)($m['content'] ?? '');
        foreach ((array)($m['embeds'] ?? []) as $e) $text .= ' ' . (string)($e['url'] ?? '') . ' ' . (string)($e['description'] ?? '');
        if (trim($text) === '' && empty($m['attachments'])) ++$blank;
        if (!preg_match_all('#https?://(?:www\.)?(?:github\.com|gitlab\.com)/[\w.-]+/[\w.-]+#i', $text, $mm)) continue;
        foreach (array_unique($mm[0]) as $url) {
            if (microtime(true) - $start > $budget) break 2;
            $l = rs_repo_from_link($url);
            if (!$l) continue;
            $k = strtolower(rs_repo_key($l['host'], $l['repo']));
            // (4.3) A forum post for a recomp that's already on RecompLauncher gets tagged and closed.
            // (A hidden draft is still being looked at: its post stays as it is.)
            if (isset($listed[$k])) { if ($forum && !empty($m['thread']) && isset($shown[$k])) rs_dc_forum_close((string)$m['thread'], 'Already listed'); continue; }
            if (isset($have[$k]) && ($have[$k][0] !== 'none' || $have[$k][1] > time() - 3 * 86400)) continue;
            ++$looked;
            $r = rs_pc_read_repo($l['repo'], $l['host']);
            $have[$k] = ['none', time()];
            if (!$r['ok']) continue;
            $r['via'] = 'discord';
            $r['by'] = (string)($m['author']['global_name'] ?? $m['author']['username'] ?? '');
            $r['msg'] = !empty($m['thread']) ? 'https://discord.com/channels/' . RS_DC_SUGGEST_GUILD . '/' . $m['thread'] : 'https://discord.com/channels/' . RS_DC_SUGGEST_GUILD . '/' . $chan . '/' . (string)($m['id'] ?? '');
            if (!empty($m['thread'])) $r['thread'] = (string)$m['thread'];
            $state = ($r['assets']['win'] === '' && $r['assets']['linux'] === '' && $r['assets']['mac'] === '') ? 'none' : 'new';
            $put->execute([rs_repo_key($l['host'], (string)$r['repo']), json_encode($r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $state]);
            $have[$k] = [$state, time()];
            if ($state === 'new') ++$new;
        }
    }
    // Messages come back empty when the app's Message Content Intent is off.
    $why = $msgs && $blank === count($msgs) ? 'Discord hides the messages\' text from the bot. Turn on Message Content Intent for the app in the Discord Developer Portal (Bot page).' : '';
    return $done($new, $looked, $why);
}

// ---- (4.3) #recomp-suggestions as a forum ------------------------------------------------------
// One post per suggestion. The site reads the posts (open and recently closed), and when a suggested
// recomp is added, or is already listed, its post gets that tag and is closed, so the forum only
// shows what's still waiting.
const RS_DC_FORUM_TAGS = [['Added', '✅'], ['Already listed', '📚'], ['Looking into it', '👀'], ['Can\'t be added', '⛔']];

// The forum's posts as messages: [status, [['id', 'content', 'embeds', 'author', 'thread']]].
function rs_dc_forum_posts(string $forum, string $bot): array {
    $threads = [];
    [$st, $act] = rs_dc_call('GET', '/guilds/' . RS_DC_SUGGEST_GUILD . '/threads/active', ['bot' => $bot]);
    if ($st !== 200) return [$st, null];
    foreach ((array)($act['threads'] ?? []) as $t) if ((string)($t['parent_id'] ?? '') === $forum) $threads[] = $t;
    [$st2, $arc] = rs_dc_call('GET', '/channels/' . $forum . '/threads/archived/public?limit=50', ['bot' => $bot]);
    if ($st2 === 200) foreach ((array)($arc['threads'] ?? []) as $t) $threads[] = $t;
    $out = [];
    foreach (array_slice($threads, 0, 80) as $t) {
        $id = (string)$t['id'];
        // The post's first message has the thread's own ID.
        [$s3, $m] = rs_dc_call('GET', '/channels/' . $id . '/messages/' . $id, ['bot' => $bot]);
        $m = $s3 === 200 && is_array($m) ? $m : [];
        $out[] = ['id' => $id, 'thread' => $id, 'content' => (string)($t['name'] ?? '') . ' ' . (string)($m['content'] ?? ''),
            'embeds' => (array)($m['embeds'] ?? []), 'attachments' => (array)($m['attachments'] ?? []), 'author' => (array)($m['author'] ?? [])];
    }
    return [200, $out];
}

// Tags a forum post (Added, Already listed, ...) and closes it. Best effort: the bot needs Manage Threads.
function rs_dc_forum_close(string $thread, string $tag): bool {
    try {
        $bot = function_exists('rs_dc_conf') ? (string)rs_dc_conf()['bot'] : '';
        $forum = (string)rs_setting('dc_suggest_forum', '');
        if ($bot === '' || $forum === '' || !preg_match('/^\d{5,25}$/', $thread)) return false;
        $tags = json_decode((string)(rs_kv_get('dc_forum_tags')['v'] ?? ''), true);
        if (!is_array($tags) || (rs_kv_get('dc_forum_tags')['age'] ?? 1e9) > 86400) {
            [$st, $ch] = rs_dc_call('GET', '/channels/' . $forum, ['bot' => $bot]);
            $tags = [];
            foreach ((array)($ch['available_tags'] ?? []) as $x) $tags[(string)$x['name']] = (string)$x['id'];
            rs_kv_put('dc_forum_tags', (string)json_encode($tags));
        }
        [$st, $t] = rs_dc_call('GET', '/channels/' . $thread, ['bot' => $bot]);
        if ($st !== 200 || (string)($t['parent_id'] ?? '') !== $forum) return false;
        $applied = array_values(array_unique(array_merge((array)($t['applied_tags'] ?? []), isset($tags[$tag]) ? [$tags[$tag]] : [])));
        if (!empty($t['thread_metadata']['archived'])) rs_dc_call('PATCH', '/channels/' . $thread, ['bot' => $bot, 'json' => ['archived' => false]]);
        [$st] = rs_dc_call('PATCH', '/channels/' . $thread, ['bot' => $bot, 'json' => ['applied_tags' => array_slice($applied, 0, 5), 'archived' => true]]);
        return $st === 200;
    } catch (Throwable $e) { return false; }
}

// One time: makes #recomp-suggestions a forum. The old channel stays, read-only, renamed, under it.
// -> what happened, in words.
function rs_dc_suggest_forum_setup(): string {
    $bot = function_exists('rs_dc_conf') ? (string)rs_dc_conf()['bot'] : '';
    if ($bot === '') return 'The site\'s Discord bot isn\'t set up.';
    $g = RS_DC_SUGGEST_GUILD;
    $old = trim((string)rs_setting('dc_suggest_channel', RS_DC_SUGGEST_CHANNEL));
    [$st, $chans] = rs_dc_call('GET', '/guilds/' . $g . '/channels', ['bot' => $bot]);
    if ($st !== 200 || !is_array($chans)) return 'Discord didn\'t list the channels (' . $st . ').';
    $was = null;
    foreach ($chans as $c) if ((string)$c['id'] === $old) $was = $c;
    if (!$was) return 'The old #recomp-suggestions channel wasn\'t found.';
    if ((int)$was['type'] === 15) return 'It\'s a forum already.';
    $tags = array_map(fn($t) => ['name' => $t[0], 'emoji_name' => $t[1], 'moderated' => true], RS_DC_FORUM_TAGS);
    $json = ['name' => 'recomp-suggestions', 'type' => 15, 'parent_id' => $was['parent_id'] ?? null, 'position' => (int)($was['position'] ?? 0),
        'permission_overwrites' => (array)($was['permission_overwrites'] ?? []),
        'topic' => "Suggest a recomp for RecompLauncher.\n\nOne post per recomp: the game in the title, its GitHub or GitLab link in the post. Search first so it isn't posted twice.\n\nWhen a recomp is added (or it's already on RecompLauncher), its post gets tagged and closed, so what's open here is still waiting.",
        'available_tags' => $tags, 'default_reaction_emoji' => ['emoji_name' => '👍'], 'default_sort_order' => 0, 'default_forum_layout' => 1];
    [$st, $new] = rs_dc_call('POST', '/guilds/' . $g . '/channels', ['bot' => $bot, 'json' => $json]);
    if ($st !== 201 && $st !== 200) return 'Discord didn\'t make the forum (' . $st . '): ' . mb_substr(json_encode($new), 0, 300);
    $fid = (string)$new['id'];
    rs_set_setting('dc_suggest_forum', $fid);
    rs_set_setting('dc_suggest_old', $old);
    rs_set_setting('dc_suggest_channel', $fid);
    // The old one: renamed, read-only for everyone, right under the forum.
    $ow = (array)($was['permission_overwrites'] ?? []);
    $found = false;
    foreach ($ow as &$o) if ((string)$o['id'] === $g) { $o['deny'] = (string)((int)$o['deny'] | 2048 | 274877906944 | 34359738368); $found = true; }
    unset($o);
    if (!$found) $ow[] = ['id' => $g, 'type' => 0, 'allow' => '0', 'deny' => (string)(2048 | 274877906944 | 34359738368)];
    [$st2] = rs_dc_call('PATCH', '/channels/' . $old, ['bot' => $bot, 'json' => ['name' => 'old-recomp-suggestions', 'topic' => 'Moved: post suggestions in the recomp-suggestions forum.', 'permission_overwrites' => $ow, 'position' => (int)($was['position'] ?? 0) + 1]]);
    return 'Made the forum (' . $fid . ').' . ($st2 === 200 ? ' The old channel is now old-recomp-suggestions, read-only.' : ' Couldn\'t change the old channel (' . $st2 . ').');
}

// ---- (4.3) SDK support: does the recomp ship its own recomplauncher.json? ----------------------
// Looked for in its repo (top folder) and at the top of its Windows release zip. Kept a week.
// -> ['has' => 'release'|'repo'|'none'|'unknown', 'at' => time]
// $readmeNow: read the README from GitHub now (Check again); otherwise its copy from the last day.
// (4.9) A developer's own recomplauncher.json at the top of their project, on any host we read: GitHub,
// GitLab or a Forgejo server (Longshot). -> [status 200|404|other, text]. SourceForge has no repo files.
function rs_sdk_repo_file(string $host, string $repo): array {
    $host = rs_host_norm($host);
    if ($repo === '' || $host === 'sourceforge') return [0, ''];
    if ($host === 'gitlab') {
        $r = rs_http(rs_gl_api() . '/projects/' . rawurlencode($repo) . '/repository/files/recomplauncher.json/raw?ref=HEAD', ['gitlab' => true, 'timeout' => 8, 'max' => 400000]);
        return [(int)$r['status'], $r['status'] === 200 ? (string)$r['body'] : ''];
    }
    if ($host === 'forgejo') {
        if (!rs_fj_path_ok($repo)) return [0, ''];
        $r = rs_http(rs_fj_api($repo) . '/raw/recomplauncher.json', ['timeout' => 8, 'max' => 400000]);
        return [(int)$r['status'], $r['status'] === 200 ? (string)$r['body'] : ''];
    }
    $r = rs_http(rs_gh_api() . '/repos/' . $repo . '/contents/recomplauncher.json', ['github' => true, 'timeout' => 8, 'max' => 400000]);
    if ($r['status'] !== 200) return [(int)$r['status'], ''];
    $j = json_decode((string)$r['body'], true);
    return [200, is_array($j) && isset($j['content']) ? (string)base64_decode((string)$j['content']) : ''];
}

function rs_pc_sdk_check(array $a, bool $fresh = false, bool $readmeNow = false): array {
    $k = 'sdk:' . $a['id'];
    if (!$fresh && ($c = rs_kv_get($k)) && $c['age'] < 7 * 86400 && is_array($x = json_decode($c['v'], true))) return $x;
    $out = ['has' => 'unknown', 'at' => time()];
    $repo = (string)$a['repo'];
    if ($repo !== '') {
        [$st, $txt] = rs_sdk_repo_file(rs_pc_host($a), $repo);
        if ($st === 200) { $out['has'] = 'repo'; $out += rs_pc_sdk_keys($txt); }
        elseif ($st === 404) $out['has'] = 'none';
    }
    if ($out['has'] !== 'repo') {
        try {
            $rel = rs_pc_release_for($a);
            $pat = trim((string)$a['asset_win']);
            foreach ($rel ? (array)$rel['assets'] : [] as $f) {
                if ($pat === '' || !rs_pattern_matches($pat, (string)$f['name']) || !preg_match('/\.zip$/i', (string)$f['name'])) continue;
                $z = rs_zip_directory((string)$f['browser_download_url'], (int)$f['size']);
                if (empty($z['ok'])) break;
                $out['has'] = 'none';
                foreach ((array)$z['entries'] as $n => $en) if (preg_match('#^([^/]+/)?recomplauncher\.json$#i', (string)$n)) {
                    $out['has'] = 'release';
                    $txt = rs_zip_file((string)$f['browser_download_url'], $en, 400000);
                    if (is_string($txt)) $out += rs_pc_sdk_keys($txt);
                    break;
                }
                break;
            }
        } catch (Throwable $e) { /* unknown for now */ }
    }
    // (4.6) Whether its README has the "Get it on RecompLauncher" badge, for the SDK support table. A
    // Check again asks GitHub now (its last copy if GitHub doesn't answer); otherwise the day-old copy
    // does. Never deleting the copy first: when GitHub was busy, that left nothing to read, the badge
    // unknown, and the recomp due again on every run. Left out only when there's no copy at all.
    try {
        $rd = rs_pc_readme($repo, rs_pc_host($a), $readmeNow ? 0 : 86400);
        $rs = (int)$rd['status'];
        if ($rs === 200) { $out['badges'] = rs_pc_badge_kinds((string)$rd['md']); $out['badge'] = (bool)$out['badges']; $out['link'] = rs_pc_readme_link((string)$rd['md']); }
        elseif ($rs === 404) { $out['badge'] = false; $out['badges'] = []; $out['link'] = false; }
        elseif (!in_array($rs, [0, 429, 500, 502, 503, 504], true)) $out['badge'] = null; // can't be read: looked at, unknown
    } catch (Throwable $e) { /* next check */ }
    rs_kv_put($k, (string)json_encode($out));
    return $out;
}

// (4.8) Submit > Easy Setup, "Check my repo": the same look as the SDK check (the repo's top folder,
// then the top of the newest release's zip) for a project that may not be listed yet. Releases are read
// fresh (a minute), so a release published a moment ago is seen. Nothing is kept.
// -> ['has' => repo|release|none|unknown, 'tag', 'easy', 'settings', 'dumps', 'norelease']
function rs_pc_sdk_probe(string $repo, string $host, string $pat): array {
    $out = ['has' => 'unknown'];
    $read = function (string $txt) use (&$out, $repo) {
        $k = rs_pc_sdk_keys($txt);
        $keys = (array)($k['keys'] ?? []);
        $out['easy'] = !empty($k['easy']);
        $out['settings'] = !empty($k['settings']);
        $out['dumps'] = !empty($k['dumps']);
        $out['bad'] = !$keys; // there, but not JSON we can read
    };
    [$st, $txt] = rs_sdk_repo_file($host, $repo);
    if ($st === 200) { $out['has'] = 'repo'; $read($txt); }
    elseif ($st === 404) $out['has'] = 'none';
    $rel = rs_host_releases($host, $repo, 60);
    $list = $rel['status'] === 200 ? rs_rel_sorted(json_decode((string)$rel['body'], true) ?: []) : [];
    $x = null;
    foreach ($list as $y) if (empty($y['draft']) && empty($y['prerelease'])) { $x = $y; break; }
    if (!$x) foreach ($list as $y) if (empty($y['draft'])) { $x = $y; break; }
    if (!$x) { $out['norelease'] = $rel['status'] === 200; return $out; }
    $out['tag'] = (string)($x['tag_name'] ?? ($x['name'] ?? ''));
    if ($out['has'] === 'repo') return $out;
    // The zips to look in: the Windows file they named, else any zip that looks like a build (two at most).
    $zips = [];
    foreach ((array)$x['assets'] as $f) {
        $n = (string)($f['name'] ?? '');
        if (!preg_match('/\.zip$/i', $n) || preg_match('/source|src|symbols|debug|pdb/i', $n)) continue;
        if ($pat !== '' && rs_pattern_matches($pat, $n)) array_unshift($zips, $f); else $zips[] = $f;
    }
    foreach (array_slice($zips, 0, 2) as $f) {
        $z = rs_zip_directory((string)$f['browser_download_url'], (int)$f['size']);
        if (empty($z['ok'])) continue;
        if ($out['has'] === 'unknown') $out['has'] = 'none';
        foreach ((array)$z['entries'] as $n => $en) if (preg_match('#^([^/]+/)?recomplauncher\.json$#i', (string)$n)) {
            $out['has'] = 'release';
            $txt = rs_zip_file((string)$f['browser_download_url'], $en, 400000);
            $read(is_string($txt) ? $txt : '');
            return $out;
        }
    }
    return $out;
}

// (4.6) Which of our badges a README has: "launcher" (Get it on RecompLauncher), "store" (Get it on
// RecompStore) and "dev" (Verified developer on RecompStore, which links to the developer's page, not
// the app). Any of them counts as a badge in the SDK support table.
const RS_BADGE_NAMES = ['launcher' => 'Get it on RecompLauncher', 'store' => 'Get it on RecompStore', 'dev' => 'Verified developer'];
function rs_pc_badge_kinds(string $md): array {
    $k = [];
    if (preg_match('#recomplauncher\.com/[^\s"\')]*badge[^\s"\')]*\.(svg|png)|Get it on RecompLauncher#i', $md)) $k[] = 'launcher';
    if (preg_match('#recompstore\.com/assets/press/badge[^\s"\')]*\.(svg|png)|Get it on RecompStore#i', $md)) $k[] = 'store';
    // (4.8) Developer pages are on recomplauncher.com/dev/<name> too.
    if (preg_match('#recomp(?:store|launcher)\.com/assets/press/(?:launcher/)?verified[^\s"\')]*\.(svg|png)|Verified developer on Recomp(?:Store|Launcher)|recomp(?:store|launcher)\.com/dev/[\w-]+#i', $md)) $k[] = 'dev';
    return $k;
}
// (4.6) Xarishark: a developer who doesn't want the badge should at least link to us. Any link to
// RecompLauncher or RecompStore in the README (the badges link there too).
function rs_pc_readme_link(string $md): bool {
    return (bool)preg_match('#https?://(?:www\.)?(?:recomplauncher|recompstore)\.com#i', $md);
}

// (4.6) Due a check: never checked, older than a week, or checked before the README badge was looked at.
function rs_pc_sdk_due(array $a): bool {
    $c = rs_kv_get('sdk:' . $a['id']);
    if (!$c || $c['age'] >= 7 * 86400) return true;
    $v = json_decode($c['v'], true);
    return !is_array($v) || !array_key_exists('badge', $v);
}

// What a developer's own recomplauncher.json covers, for the launcher's "Launcher support" list:
// its top-level keys, and whether it lists verified copies (game.dumps).
// (4.9) Easy Setup, as the SDK has it: "start", and the player's copy either in the older "game" part or in
// "inputs" (a required entry whose role is game or disc; no role means game). A single-file game (a .jar, a
// ROM) can only be described through inputs. Runtime (shared, like DLSS), key, DLC and update inputs don't count.
function rs_sdk_easy(array $j): bool {
    if (!isset($j['start'])) return false;
    if (!empty($j['game'])) return true;
    foreach ((array)($j['inputs'] ?? []) as $in) {
        if (!is_array($in) || !empty($in['optional']) || !empty($in['shared'])) continue;
        if (in_array((string)($in['role'] ?? 'game'), ['game', 'disc'], true)) return true;
    }
    return false;
}

function rs_pc_sdk_keys(string $json): array {
    $j = json_decode($json, true);
    if (!is_array($j)) return [];
    $dlss = false; // (4.6) it asks the launcher for NVIDIA's DLSS file
    foreach ((array)($j['inputs'] ?? []) as $in) if (is_array($in) && ($in['shared'] ?? '') === 'nvidia.dlss') $dlss = true;
    // (4.9) Settings in the launcher: what it shows is the "settings" list ("config" alone only says where the file is).
    return ['keys' => array_values(array_filter(array_keys($j), 'is_string')), 'dumps' => !empty($j['game']['dumps']), 'dlss' => $dlss, 'easy' => rs_sdk_easy($j),
        'settings' => is_array($j['settings'] ?? null) && $j['settings'] !== []];
}

// (4.3) The catalog's "support" list per recomp: which launcher features it has, and who made each
// one work: "dev" (the recomp's own recomplauncher.json) or "launcher" (our profile in the catalog).
// Only cached SDK checks are read here; nothing goes to GitHub while the catalog is built.
// (4.4) Achievement folders and (when ach_public is on) the live lists, read once per publish.
function rs_pc_ach_maps(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [[], []];
    try {
        require_once __DIR__ . '/ach.php';
        $m[0] = rs_ach_folders();
        if (rs_setting('ach_public', '0') === '1')
            foreach (rs_ach_lists() as $g => $l) if ($l['live']) $m[1][$g] = $l['list'];
    } catch (Throwable $e) { /* the catalog goes out without them */ }
    return $m;
}

function rs_pc_support(array $a): array {
    if (!function_exists('rs_boot_effective')) require_once __DIR__ . '/boot.php';
    $c = rs_kv_get('sdk:' . $a['id']);
    $sdk = $c ? (json_decode($c['v'], true) ?: []) : [];
    $keys = (array)($sdk['keys'] ?? []);
    $dev = fn(string $k) => in_array($k, $keys, true);
    $b = rs_boot_effective($a);
    $full = (int)($b['format'] ?? 0) === 1;
    $files = (array)($b['files'] ?? []);
    $out = ['sdk' => in_array($sdk['has'] ?? '', ['release', 'repo', 'none'], true) ? $sdk['has'] : 'unknown'];
    // (4.9) Checks from before 'easy' was kept: the old game + start test until the weekly check refreshes them.
    if (array_key_exists('easy', $sdk) ? !empty($sdk['easy']) : ($dev('game') && $dev('start'))) $out['easy_setup'] = 'dev';
    elseif ($full ? rs_sdk_easy($b) : in_array((string)($files['how'] ?? ''), RS_BOOT_EASY, true)) $out['easy_setup'] = 'launcher';
    if (array_key_exists('settings', $sdk) ? !empty($sdk['settings']) : $dev('settings')) $out['settings'] = 'dev';
    elseif ($full ? !empty($b['settings']) : !empty($b['settings']['settings'])) $out['settings'] = 'launcher';
    if ($dev('prepare')) $out['prepare'] = 'dev';
    if (!empty($sdk['dumps'])) $out['verified'] = 'dev';
    elseif (!$full && (($files['id'] ?? '') !== '' || ($files['n64_id'] ?? '') !== '')) $out['verified'] = 'launcher';
    foreach (['saves', 'mods', 'looks', 'actions'] as $k) if ($dev($k)) $out[$k] = 'dev';
    return $out;
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
        $d['repo'] = $d['host'] !== '' && (string)($d['repo'] ?? '') !== '' ? (string)$d['repo'] : (string)$r['repo'];
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
// (4.1) The Discord suggestions, every half hour while the found list is open, after the page has gone.
function rs_pc_find_discord_maybe(): void {
    $last = rs_kv_get('pc_find_dc');
    if ($last && $last['age'] < 1800) return;
    rs_kv_put('pc_find_dc', (string)json_encode(['at' => time(), 'new' => 0, 'looked' => 0, 'why' => ''] + (json_decode((string)($last['v'] ?? ''), true) ?: [])));
    register_shutdown_function(function () {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @set_time_limit(60);
        try { rs_pc_find_discord(40); } catch (Throwable $e) { /* next time */ }
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

// (4.1) Several SteamGridDB lists at once: [key => path] -> [key => data or null]. Cached ones come from the cache.
function rs_sgdb_many(array $paths): array {
    $key = rs_sgdb_key();
    $out = []; $ask = [];
    foreach ($paths as $k => $path) {
        $c = rs_kv_get('sgdb:' . $path);
        if ($c && $c['age'] < 7 * 86400) $out[$k] = json_decode($c['v'], true);
        elseif ($key !== '') $ask[$k] = ['https://www.steamgriddb.com/api/v2' . $path, ['Authorization: Bearer ' . $key]];
        else $out[$k] = null;
    }
    foreach ($ask ? rs_http_many($ask, 15, 2 << 20) : [] as $k => $r) {
        $j = $r['status'] === 200 ? json_decode($r['body'], true) : null;
        if (is_array($j) && !empty($j['success'])) { rs_kv_put('sgdb:' . $paths[$k], json_encode($j['data'] ?? [])); $out[$k] = $j['data'] ?? []; }
        else $out[$k] = null;
    }
    return $out;
}
// (4.1) The chosen pictures downloaded at once, then made into the store's sizes. [kind => url] -> fields to merge
function rs_pc_media_many(array $urls, string $id): array {
    $got = rs_http_many(array_filter($urls, fn($u) => is_string($u) && $u !== ''), 30);
    $out = [];
    foreach ($urls as $k => $u) {
        $body = ($got[$k]['status'] ?? 0) === 200 ? (string)$got[$k]['body'] : null;
        $m = $body !== null && $body !== '' ? rs_pc_media($u, $k, $id, $body) : rs_pc_media($u, $k, $id);
        if ($m) { unset($m['hero_web']); $out = array_merge($out, $m); $out[$k . '_src'] = $u; }
    }
    return $out;
}

// The best art SteamGridDB has for a game: [game, cover, hero, logo, icon] (URLs, '' when none).
// $avoid: per kind, pictures another recomp already wears (two recomps of one game get their own);
// $skip: how many more to pass over, for "Different art".
// (4.1) $year: the original's release year (from About the game), so a remake doesn't wear the
// original's art (Ratchet & Clank on PS4 is the 2016 game, not the 2002 one).
function rs_sgdb_art(string $game, array $avoid = [], int $skip = 0, string $year = ''): array {
    $out = ['game' => '', 'cover' => '', 'hero' => '', 'logo' => '', 'icon' => ''];
    $hits = rs_sgdb('/search/autocomplete/' . rawurlencode($game));
    if (!$hits) return $out;
    $g = rs_sgdb_pick($hits, $game, $year);
    $out['game'] = (string)($g['name'] ?? '');
    $id = (int)($g['id'] ?? 0);
    $pick = function (?array $list, string $kind) use ($avoid, $skip): string {
        $urls = array_values(array_filter(array_map(fn($x) => (string)($x['url'] ?? ''), (array)$list), fn($u) => $u !== '' && !in_array($u, (array)($avoid[$kind] ?? []), true)));
        if (!$urls) return $list ? (string)($list[0]['url'] ?? '') : '';
        return $urls[$skip % count($urls)];
    };
    // (4.1) The four lists asked of SteamGridDB at once, not one after another.
    $lists = rs_sgdb_many(['cover' => '/grids/game/' . $id . '?dimensions=600x900&types=static&nsfw=false&humor=false',
        'hero' => '/heroes/game/' . $id . '?types=static&nsfw=false&humor=false', 'logo' => '/logos/game/' . $id . '?types=static&nsfw=false&humor=false',
        'icon' => '/icons/game/' . $id . '?types=static&nsfw=false&humor=false']);
    foreach (['cover', 'hero', 'logo', 'icon'] as $k) $out[$k] = $pick($lists[$k] ?? null, $k);
    return $out;
}

// (4.1) Which SteamGridDB game a search means: the one named like it from the same year when the
// year is known (a remake shares its name with the original), else the first hit.
function rs_sgdb_pick(array $hits, string $game, string $year = ''): array {
    $year = preg_match('/^\d{4}/', $year) ? (int)substr($year, 0, 4) : 0;
    if ($year) {
        $want = rs_pc_about_norm($game);
        $best = null; $bestScore = 0;
        foreach (array_slice($hits, 0, 20) as $i => $h) {
            if (!is_array($h) || empty($h['release_date'])) continue;
            $d = abs((int)gmdate('Y', (int)$h['release_date']) - $year);
            if ($d > 1) continue;
            $n = rs_pc_about_norm((string)($h['name'] ?? ''));
            $score = ($n === $want ? 100 : (str_contains($n, $want) || str_contains($want, $n) ? 40 : 0)) + ($d === 0 ? 10 : 0) - $i;
            if ($score > $bestScore) { $bestScore = $score; $best = $h; }
        }
        if ($best && $bestScore >= 30) return $best;
    }
    return (array)$hits[0];
}
// The year of the original game, from its About the game card, or ''.
function rs_pc_about_year(array $a): string {
    $r = is_array($a['about'] ?? null) ? (string)($a['about']['released'] ?? '') : '';
    return preg_match('/^\d{4}/', $r) ? substr($r, 0, 4) : '';
}

// The SteamGridDB pictures the other recomps already wear: [kind => [url, ...]].
function rs_pc_art_used(string $exceptId): array {
    $used = [];
    foreach (rs_pc_apps() as $x) if ($x['id'] !== $exceptId) foreach (['cover', 'hero', 'logo', 'icon'] as $k) if (!empty($x[$k . '_src'])) $used[$k][] = (string)$x[$k . '_src'];
    return $used;
}

// A picture from the web into /media, as the PS5 games' are (icon 512, hero 3840 + 1920, logo
// 1600x800 PNG) or a cover (600x900 JPEG). -> fields to merge, or [].
function rs_pc_media(string $url, string $kind, string $id, ?string $body = null): array {
    if (!preg_match('#^https://#', $url)) return [];
    // (4.1) $body: the picture already downloaded (rs_pc_media_many).
    $r = $body !== null ? ['status' => 200, 'body' => $body] : rs_http($url, ['max' => 25 << 20, 'timeout' => 30]);
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
// (4.3) A Coming soon game that has no repo yet (announced on X, say): its name, game, and where to
// follow it. Shown on Coming soon only; it gets its repo (and downloads) later, through its edit page.
// (4.6) Coming soon watch. Once a day (from pc-refresh.php, or "Check now" on the Dashboard), every
// Coming soon listing is looked at: one with a project, for a release or pre-release with files put
// out since it was added; one with no project yet, for a new GitHub repo named after its game. What
// turns up stays on the Dashboard until it's dismissed, or the listing leaves Coming soon.
const RS_SOON_WATCH_EVERY = 86400;
function rs_pc_soon_watch_state(): array {
    $kv = rs_kv_get('pc:soon_watch');
    $w = $kv ? (json_decode((string)$kv['v'], true) ?: []) : [];
    return ['at' => (int)($w['at'] ?? 0), 'last' => (array)($w['last'] ?? []), 'items' => (array)($w['items'] ?? [])];
}
function rs_pc_soon_watch_save(array $w): void {
    rs_kv_put('pc:soon_watch', json_encode($w, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
function rs_pc_soon_watch(bool $force = false): int {
    $w = rs_pc_soon_watch_state();
    if (!$force && time() - $w['at'] < RS_SOON_WATCH_EVERY) return 0;
    $soon = [];
    foreach (rs_pc_apps() as $a) if (!empty($a['soon'])) $soon[$a['id']] = $a;
    $w['items'] = array_filter($w['items'], fn($it) => isset($soon[(string)($it['id'] ?? '')]));
    $w['last'] = array_intersect_key($w['last'], $soon);
    $new = 0;
    foreach ($soon as $id => $a) {
        $host = rs_pc_host($a);
        $since = (string)($a['added'] ?? '');
        try {
            if ((string)$a['repo'] !== '') {
                $r = rs_host_releases($host, (string)$a['repo'], 6 * 3600);
                if ((int)$r['status'] !== 200) continue;
                $hit = null;
                foreach ((array)json_decode((string)$r['body'], true) as $x) {
                    if (!is_array($x) || !empty($x['draft'])) continue;
                    // Real downloads only: not the source archive, checksums or signatures.
                    $files = array_values(array_filter(array_map(fn($f) => (string)($f['name'] ?? ''), (array)($x['assets'] ?? [])),
                        fn($n) => $n !== '' && !preg_match('/(^|[-_.])(source|src)([-_.]|$)|sha256|checksums?|\.sig$|^latest\.json$/i', $n)));
                    if (!$files) continue;
                    $hit = ['tag' => (string)($x['tag_name'] ?? ''), 'pre' => !empty($x['prerelease']), 'date' => substr((string)($x['published_at'] ?? ''), 0, 10),
                        'files' => array_slice($files, 0, 4), 'url' => (string)($x['html_url'] ?? '') ?: rs_host_releases_url($host, (string)$a['repo'])];
                    break;
                }
                if (!$hit) continue;
                $key = 'r:' . $hit['tag'];
                if (($w['last'][$id] ?? '') === $key) continue;
                $w['last'][$id] = $key;
                if ($since !== '' && $hit['date'] !== '' && $hit['date'] < $since) continue; // already out when it was added
                $w['items'][$id] = ['id' => $id, 'kind' => $hit['pre'] ? 'pre' : 'release', 'repo' => rs_repo_key($host, (string)$a['repo']), 'found' => gmdate('c')] + $hit;
                ++$new;
            } else {
                $q = trim((string)preg_replace('/[^\pL\pN\s]+/u', ' ', (string)($a['game'] !== '' ? $a['game'] : $a['name'])));
                if ($q === '') continue;
                $res = rs_http(rs_gh_api() . '/search/repositories?per_page=5&sort=updated&q=' . rawurlencode($q . ' in:name,description created:>=' . ($since !== '' ? $since : gmdate('Y-m-d', time() - 30 * 86400))), ['github' => true, 'timeout' => 10]);
                if ((int)$res['status'] !== 200) continue;
                foreach ((array)(json_decode((string)$res['body'], true)['items'] ?? []) as $it) {
                    if (!preg_match('/recomp|decomp|port|native|\bpc\b/i', (string)$it['full_name'] . ' ' . (string)($it['description'] ?? ''))) continue;
                    $key = 'g:' . strtolower((string)$it['full_name']);
                    if (($w['last'][$id] ?? '') !== $key) {
                        $w['last'][$id] = $key;
                        $w['items'][$id] = ['id' => $id, 'kind' => 'repo', 'tag' => '', 'pre' => false, 'date' => substr((string)($it['created_at'] ?? ''), 0, 10), 'files' => [],
                            'url' => (string)$it['html_url'], 'repo' => (string)$it['full_name'], 'found' => gmdate('c')];
                        ++$new;
                    }
                    break;
                }
            }
        } catch (Throwable $e) { /* looked at again next run */ }
    }
    $w['at'] = time();
    rs_pc_soon_watch_save($w);
    if ($new) rs_log('pc', 'soon-watch', $new . ' new on Coming soon');
    return $new;
}
// What the watch found, newest first, for the listings still on Coming soon.
function rs_pc_soon_news(): array {
    $out = [];
    foreach (rs_pc_soon_watch_state()['items'] as $it) {
        $a = rs_pc_app((string)($it['id'] ?? ''));
        if ($a && !empty($a['soon'])) $out[] = $it + ['name' => $a['name']];
    }
    usort($out, fn($x, $y) => strcmp((string)$y['found'], (string)$x['found']));
    return $out;
}
function rs_pc_soon_dismiss(string $id): void {
    $w = rs_pc_soon_watch_state();
    if ($id === '*') $w['items'] = []; else unset($w['items'][$id]);
    rs_pc_soon_watch_save($w);
}

function rs_pc_add_soon(string $name, string $follow): array {
    $name = trim(preg_replace('/\s+/', ' ', $name));
    if ($name === '' || mb_strlen($name) > 120) throw new RuntimeException('Give it a name.');
    if (rs_pc_report_url($follow) === '') throw new RuntimeException('The follow link has to be a full https:// address.');
    $id = rs_pc_slug($name);
    $taken = array_column(rs_pc_apps(), 'id');
    for ($n = 2; in_array($id, $taken, true); ++$n) $id = rs_pc_slug($name) . '-' . $n;
    $a = array_merge(rs_pc_defaults(), ['id' => $id, 'name' => $name, 'game' => $name, 'follow' => rs_pc_report_url($follow), 'soon' => true, 'hidden' => true, 'added' => gmdate('Y-m-d')]);
    rs_pc_save($a);
    rs_log('pc', 'add', $id . ' (Coming soon, no repo yet)');
    return $a;
}

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
    // (4.1) About the game from IGDB right away (by game and console), so the art can be picked for the
    // right year and nothing has to be asked again after adding.
    if ($a['game'] !== '') {
        try { if ($ab = rs_pc_about_fetch($a)) $a['about'] = $ab; else $a['about_none'] = gmdate('Y-m-d'); } catch (Throwable $e) { /* IGDB not set up: later */ }
    }
    if (rs_sgdb_key() !== '' && $a['game'] !== '') {
        $art = rs_sgdb_art($a['game'], rs_pc_art_used($id), 0, rs_pc_about_year($a));
        if ($art['game'] !== '') $a['game'] = $art['game'];
        $a = array_merge($a, rs_pc_media_many(array_intersect_key($art, array_flip(['cover', 'hero', 'logo', 'icon'])), $id));
    }
    if ($a['icon'] === '' && preg_match('#^https://avatars\.githubusercontent\.com/#', (string)$f['avatar'])) $a = array_merge($a, rs_pc_media((string)$f['avatar'] . '&s=512', 'icon', $id));
    elseif ($a['icon'] === '' && $host === 'gitlab' && preg_match('#^https://gitlab\.com/uploads/#', (string)$f['avatar'])) $a = array_merge($a, rs_pc_media((string)$f['avatar'], 'icon', $id));
    unset($a['hero_web']);
    $a['license'] = (string)($f['license'] ?? '');
    // (4.3) What the release itself says about setting it up.
    $fl = (array)($f['flags'] ?? []);
    if (!empty($fl['setup_kit'])) {
        $a['setup_required'] = true;
        $a['setup_steps'] = "Press Open Folder and run the setup that came with it (Windows may say it protected your PC: More info, then Run anyway).\nPick your copy of the game when it asks.\nWait for it to finish building, then come back and press Play.";
    } elseif (!empty($fl['installer_only'])) {
        $a['blocked_why'] = 'Only a setup .exe (an installer): tick Installer only once a launcher with the installer flow is out';
    }
    $a = rs_pc_fill_features($a);
    if ($host === '') $a = rs_ai_on_add($a, $a['repo']); // (2.9) (3.9) the AI check reads GitHub only
    rs_pc_save($a);
    rs_db()->prepare("UPDATE pc_found SET state = 'added' WHERE repo = ?")->execute([rs_pc_key($a)]);
    // (4.3) Suggested in the Discord forum: its post gets the Added tag and closes.
    if (!empty($f['thread'])) rs_dc_forum_close((string)$f['thread'], 'Added');
    return $a;
}

// ---- README and key features (2.8) ------------------------------------------------------------

// A repo's README as Markdown (cached a day), and where its relative pictures live.
function rs_pc_readme(string $repo, string $host = '', int $maxAge = 86400): array {
    if (rs_host_norm($host) === 'gitlab') return rs_pc_readme_gitlab($repo);
    if (rs_host_norm($host) === 'sourceforge') return ['status' => 404, 'md' => '']; // (4.3) its page has the README
    if (rs_host_norm($host) === 'forgejo') return ['status' => 404, 'md' => '']; // (4.9) read on its own server
    if (!rs_repo_ok($repo)) return ['status' => 400, 'md' => ''];
    $r = rs_gh_cached($repo . '#readme', rs_gh_api() . '/repos/' . $repo . '/readme', $maxAge, function (array $j): array {
        $md = (string)base64_decode((string)($j['content'] ?? ''), false);
        if (strlen($md) > 200000) $md = substr($md, 0, 200000);
        return ['md' => mb_convert_encoding($md, 'UTF-8', 'UTF-8'), 'path' => (string)($j['path'] ?? 'README.md'), 'url' => (string)($j['html_url'] ?? '')];
    });
    $j = json_decode((string)$r['body'], true) ?: [];
    $dir = trim(dirname((string)($j['path'] ?? 'README.md')), './');
    return ['status' => $r['status'], 'md' => (string)($j['md'] ?? ''), 'url' => (string)($j['url'] ?? ''), 'age' => (int)($r['age'] ?? 0),
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
const RS_IGDB_PLATFORMS = ['pc' => [6, 13], 'n64' => 4, 'x360' => 12, 'xbox' => 11, 'ps1' => 7, 'ps2' => 8, 'ps3' => 9, 'ps4' => 48, 'gc' => 21, 'wii' => 5, 'wiiu' => 41,
    'snes' => 19, 'gba' => 24, 'gb' => 33, 'ds' => 20, 'dc' => 23, 'saturn' => 32, 'psp' => 38, 'switch' => 130, 'switch2' => 508, 'nes' => 18, 'gbc' => 22, '3ds' => 37,
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
    // (4.1) The console counts: a game of the same name on another one (Whiplash, the 2026 shooter,
    // for ROLLER's 1995 PC racer) loses points, so a name alone isn't a match.
    $want = rs_pc_about_norm($q); $plat = (array)(RS_IGDB_PLATFORMS[$a['origin']] ?? []);
    $best = null; $bestScore = 0;
    foreach ($list as $g) {
        if (!is_array($g) || empty($g['id'])) continue;
        $n = rs_pc_about_norm((string)($g['name'] ?? ''));
        $s = $igdbId > 0 ? 1000 : 0;
        if ($n === $want) $s += 100;
        elseif ($n !== '' && $want !== '' && (str_starts_with($n, $want) || str_starts_with($want, $n))) $s += 40;
        elseif ($n !== '' && $want !== '' && (str_contains($n, $want) || str_contains($want, $n))) $s += 20;
        $on = array_map(fn($p) => (int)($p['id'] ?? 0), (array)($g['platforms'] ?? []));
        if ($plat && array_intersect($plat, $on)) $s += 50;
        elseif ($plat && $on && $igdbId <= 0) $s -= 60;
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
    $gid = (int)(rs_sgdb_pick($hits, $a['game'] !== '' ? trim(preg_split('/[;\/]/', $a['game'])[0]) : $a['name'], rs_pc_about_year($a))['id'] ?? 0); $used = rs_pc_art_used($a['id']); $done = [];
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

// (4.7) How many PCs played or installed each recomp from RecompLauncher in the last 90 days (the
// launcher's events, kept 90 days): a number developers can trust, unlike GitHub's download count, which
// drops when a release's files are uploaded again. Worked out at most once an hour. -> [app id => n]
function rs_pc_players_map(): array {
    $c = rs_kv_get('pc:players90');
    if ($c && $c['age'] < 3600 && is_array($m = json_decode((string)$c['v'], true))) return $m;
    $m = [];
    try {
        foreach (rs_db()->query("SELECT game, COUNT(DISTINCT install_id) n FROM rl_events WHERE kind IN ('install', 'session') AND game <> '' GROUP BY game") as $r) $m[(string)$r['game']] = (int)$r['n'];
    } catch (Throwable $e) { return $c ? (json_decode((string)$c['v'], true) ?: []) : []; }
    rs_kv_put('pc:players90', json_encode($m));
    return $m;
}

function rs_pc_catalog(): array {
    $abs = 'rs_abs';
    $apps = [];
    // (4.3) Games handheld players say run well (player reports, last 90 days).
    if (!function_exists('rs_handheld_good')) require_once __DIR__ . '/rl_cloud.php';
    $handheld = function_exists('rs_handheld_good') ? rs_handheld_good() : [];
    $players = rs_pc_players_map(); // (4.7)
    foreach (rs_pc_apps(false) as $i => $a) {
        // (4.3) A SourceForge project goes out only once one of its releases has been checked.
        if (rs_pc_host($a) === 'sourceforge' && ((string)($a['ok_tag'] ?? '') === '' || str_starts_with((string)$a['ok_tag'], 'moved:'))) continue;
        $e = ['id' => $a['id'], 'name' => $a['name'], 'game' => $a['game'], 'origin' => $a['origin'], 'order' => $i,
            'repo' => $a['repo'], 'version' => $a['version'], 'released' => $a['released'], 'size' => $a['size'],
            'downloads' => (int)$a['downloads'], 'stars' => (int)$a['stars'], 'blurb' => $a['blurb'], 'adds' => (string)($a['adds'] ?? ''), 'description' => $a['description'],
            'needs' => $a['needs'], 'credits' => $a['credits'], 'author' => $a['author'],
            'icon' => $abs($a['icon']), 'cover' => $abs($a['cover']), 'hero' => $abs($a['hero']), 'logo' => $abs($a['logo']),
            // (4.2) Mac only with a download the launcher can install there: a .zip that isn't Intel-only.
            'systems' => array_keys(array_filter(['windows' => $a['asset_win'], 'linux' => $a['asset_linux'], 'macos' => rs_pc_mac_ok($a['asset_mac'])])),
            'assets' => array_filter(['windows' => $a['asset_win'], 'linux' => $a['asset_linux'], 'macos' => rs_pc_mac_ok($a['asset_mac'])]),
            'tags' => array_values(array_filter(array_map('trim', explode(',', (string)$a['tags'])))),
            // (2.9) Newest arrivals: when it went live (to the second), else the day it was added.
            'added' => (string)($a['live_at'] ?? '') !== '' ? (string)$a['live_at'] : $a['added'],
            'features' => array_values(array_filter(array_map('trim', explode("\n", (string)$a['features'])))), 'license' => (string)$a['license'],
            'genre' => isset(RS_PC_GENRES[$a['genre']]) ? $a['genre'] : 'other', 'media' => rs_pc_media_links((string)$a['media']),
            'issues' => (int)$a['issues'], 'releases' => (int)$a['releases'], 'first' => (string)$a['first']];
        if ($a['dev'] !== '') $e['dev'] = $a['dev'];
        // (4.7) Several games in one recomp: a tile per game in RecompLauncher.
        if ($pg = rs_pc_games_entry($a)) $e['games'] = $pg;
        if (!empty($players[$a['id']])) $e['players'] = $players[$a['id']]; // (4.7) PCs, last 90 days
        // (3.9) A GitLab project: "repo" is its path there (group/project). Left out for GitHub.
        if (rs_pc_host($a) !== '') $e['host'] = rs_pc_host($a); // (4.3) 'gitlab' or 'sourceforge'
        // (3.9) Where its developer wants bugs reported (their own page or issue form).
        if (($ru = rs_pc_report_url((string)($a['report_url'] ?? ''))) !== '') $e['report_url'] = $ru;
        if (isset($handheld[$a['id']])) $e['handheld'] = $handheld[$a['id']];
        // (4.3) A Coming soon game with no repo yet: where to follow it (the developer's post or page).
        if ((string)$a['repo'] === '' && ($fu = rs_pc_report_url((string)($a['follow'] ?? ''))) !== '') $e['follow'] = $fu;
        // (3.8) The release that's out (the launcher installs this one) and its checked files.
        if ((string)($a['ok_tag'] ?? '') !== '') $e['tag'] = (string)$a['ok_tag'];
        if (function_exists('rs_vt_catalog_files') && ($vf = rs_vt_catalog_files($a))) $e['files'] = $vf;
        if ($a['featured']) $e['featured'] = true;
        if (!empty($a['soon'])) $e['soon'] = true;
        if (!empty($a['about']) && is_array($a['about'])) $e['about'] = $a['about'];
        if (!empty($a['setup_required'])) { $e['setup_required'] = true; $e['setup_steps'] = array_values(array_filter(array_map('trim', explode("\n", (string)($a['setup_steps'] ?? ''))))); }
        // (4.3) A setup .exe only: RecompLauncher runs it, then finds the folder it installed to.
        if (!empty($a['installer'])) {
            $e['installer'] = true; $e['setup_required'] = true;
            if (empty($e['setup_steps'])) $e['setup_steps'] = ['Press Set Up in RecompLauncher and run the installer.', 'Keep the install folder it suggests.', 'When the installer closes, RecompLauncher finds the game and it\'s ready to play.'];
        }
        if ($a['links']) $e['links'] = $a['links'];
        // (4.0) Where the project stands: 'ended' (no more updates) or 'paused' (on hold), set on its edit
        // page, with the developer's own words if any. "archived": closed on GitHub, shown as ended too.
        $life = in_array((string)($a['status'] ?? ''), RS_PC_STATUSES, true) ? (string)$a['status'] : '';
        if ($life !== '') $e['status'] = $life;
        if (!empty($a['archived']) && $life !== 'complete') $e['archived'] = true;
        if (($life !== '' || !empty($a['archived'])) && trim((string)($a['status_note'] ?? '')) !== '') $e['status_note'] = trim((string)$a['status_note']);
        $e += rs_ai_entry($a); // (2.9)
        // (3.3) How RecompLauncher starts it and sets it up from the player's copy (CURATOR-SETUP-GUIDE.md).
        $boot = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
        // (4.1) A developer's own recomplauncher.json pasted in goes out as it is ("launcher"), so the
        // launcher shows Easy Setup and its game settings before the game is installed too.
        if (is_array($boot) && $boot) { if ((int)($boot['format'] ?? 0) === 1 && isset($boot['start'])) $e['launcher'] = $boot; else $e['boot'] = $boot; }
        if ($ci = rs_pc_copy_ids((string)($a['copy_ids'] ?? ''))) $e['copy_ids'] = $ci;
        $e['support'] = rs_pc_support($a);
        // (4.9) Xbox 360: the game's title ID where we know it (its launcher profile), so achievements reach the
        // launcher before the game writes its first unlock file.
        if ((string)$a['origin'] === 'x360' && !isset($e['title_id'])) {
            $bt = rs_boot_effective($a);
            $tid = strtoupper((string)($bt['files']['id'] ?? ($bt['title_id'] ?? '')));
            if (preg_match('/^[0-9A-F]{8}$/', $tid)) $e['title_id'] = $tid;
        }
        // (4.4) Recomp Achievements: where this game's ReXGlue unlock file turned up on players' PCs,
        // and, once it's public, the game's list.
        [$achFolders, $achLists] = rs_pc_ach_maps();
        if (isset($achFolders[$a['id']])) $e['achievements_folder'] = $achFolders[$a['id']];
        if (isset($achLists[$a['id']])) $e['achievements'] = $achLists[$a['id']];
        $apps[] = $e;
    }
    // (4.3) Entries only newer launchers can read go in "apps_min": [{min, apps}], which older ones
    // don't look at. Cloudflare keeps one copy of the catalog for everyone, so it can't be picked by
    // the launcher's version. A SourceForge recomp needs Beta 27 (Beta 26 takes it for a GitHub repo).
    $newer = [];
    $apps = array_values(array_filter($apps, function ($e) use (&$newer) {
        if (($e['host'] ?? '') === 'sourceforge') { $newer['0.90.0-beta.27'][] = $e; return false; }
        // (4.9) A Forgejo project (Longshot): only for the launcher that reads Forgejo releases. Until one is
        // out the setting stays far ahead, so it's on the website and in no launcher.
        if (($e['host'] ?? '') === 'forgejo') { $newer[(string)rs_setting('forgejo_launcher_min', '99.0.0')][] = $e; return false; }
        return true;
    }));
    $origins = [];
    foreach (RS_PC_ORIGINS as $k => $n) if (array_filter($apps, fn($x) => $x['origin'] === $k)) $origins[] = ['id' => $k, 'name' => $n];
    $devs = [];
    foreach (rs_developers() as $slug => $d) if (array_filter($apps, fn($x) => ($x['dev'] ?? '') === $slug))
        $devs[] = ['slug' => $slug, 'name' => (string)$d['name'], 'verified' => !empty($d['verified']), 'avatar' => (string)($d['avatar'] ?? '') !== '' ? rs_abs((string)$d['avatar']) : ''];
    // (3.0) Games with more than one version, and art one version lends another.
    $titles = rs_title_catalog($apps);
    // (4.7) The Home banner: a list per system (fixed picks and slots that fill themselves), merged for launchers.
    $bn = rs_pc_banner_resolve($apps);
    $cat = ['catalogVersion' => 1, 'platform' => 'pc', 'updated' => gmdate('c'), 'apps' => $apps, 'origins' => $origins, 'developers' => $devs, 'titles' => $titles, 'banner' => $bn['merged'],
        'bannerInfo' => (object)$bn['info'], 'banners' => $bn['banners']];
    // (4.7) Hidden listings another one replaces (a stale mirror, a moved project): {old id: new id}, so the
    // launcher can move players who have the old one. Only to a listing that's out.
    $replaced = [];
    $have = array_flip(array_column($apps, 'id'));
    foreach (rs_pc_apps() as $x) if (!empty($x['hidden']) && (string)($x['replaced_by'] ?? '') !== '' && isset($have[(string)$x['replaced_by']])) $replaced[$x['id']] = (string)$x['replaced_by'];
    $cat['replaced'] = (object)$replaced;
    // (4.8) A removed recomp's old link shows what happened and others like it (Repo watch turns it on).
    if (rs_setting('pc_gone_page', '') === '1') $cat['gonePage'] = true;
    if ($newer) foreach ($newer as $min => $list) $cat['apps_min'][] = ['min' => $min, 'apps' => $list];
    // (4.3) Every profile the launcher had built in is in the admin now: Beta 27 and newer use the
    // catalog's alone (its built-in ones only when there's no catalog at all).
    if (!function_exists('rs_boot_import_plan')) require_once __DIR__ . '/boot.php';
    if (rs_boot_builtin() && !rs_boot_import_plan()) $cat['boot_all'] = true;
    return $cat;
}

// (4.3) Every entry in a catalog, the ones in apps_min (for newer launchers) too.
function rs_pc_cat_apps(array $cat): array {
    $all = is_array($cat['apps'] ?? null) ? $cat['apps'] : [];
    foreach ((array)($cat['apps_min'] ?? []) as $g) foreach ((array)($g['apps'] ?? []) as $e) $all[] = $e;
    return $all;
}

// ---- (3.6) The PC home banner -------------------------------------------------------------------
// The recomps in the slider on top of recomplauncher.com, in order. Kept as a list (setting
// pc_banner); each one is also marked Featured, so everything that reads Featured agrees.
// ---- (4.7) The Home banner: a list per system, with fixed picks and slots that fill themselves ----
// Each list is slots: {type: "pick", id, tag?, text?} or {type: "auto", rule, tag?}. Launchers already
// hide banner games that don't run on the player's system, so the catalog's "banner" is the three lists
// merged (Windows first, then what Mac and Linux add), and each system ends up with its own. "banners"
// carries the lists themselves, for the website (picked by the visitor's system) and newer launchers.
const RS_BANNER_OS = ['windows' => 'Windows', 'macos' => 'Mac', 'linux' => 'Linux'];
const RS_BANNER_RULES = [
    'new' => ['New Release', 'Newest release', 'Out in the last three weeks, the one players took to most.'],
    'rising' => ['Trending', 'Trending', 'Newer recomps (two months) gaining stars, downloads and players fastest.'],
    'popular' => ['Most Popular', 'Most popular', 'Played on the most PCs from RecompLauncher (90 days), then downloads.'],
    'updated' => ['Just Updated', 'Just updated', 'A well-known recomp with a new version this week.'],
];
function rs_pc_banner_slots(): array {
    $j = json_decode((string)rs_setting('pc_banner_slots', ''), true);
    $out = [];
    if (is_array($j)) {
        foreach (array_keys(RS_BANNER_OS) as $os) $out[$os] = rs_pc_banner_clean((array)($j[$os] ?? []));
        return $out;
    }
    // From the one list it was before: the same picks for every system, with their words.
    $info = rs_pc_banner_info();
    $old = json_decode((string)rs_setting('pc_banner', ''), true);
    $slots = [];
    foreach (is_array($old) ? $old : [] as $id) $slots[] = ['type' => 'pick', 'id' => (string)$id] + ($info[(string)$id] ?? []);
    foreach (array_keys(RS_BANNER_OS) as $os) $out[$os] = rs_pc_banner_clean($slots);
    return $out;
}
function rs_pc_banner_clean(array $slots): array {
    $out = [];
    foreach (array_slice($slots, 0, 12) as $sl) {
        if (!is_array($sl)) continue;
        $w = ['tag' => mb_substr(trim(preg_replace('/\s+/', ' ', (string)($sl['tag'] ?? ''))), 0, 32), 'text' => mb_substr(trim(preg_replace('/\s+/', ' ', (string)($sl['text'] ?? ''))), 0, 160)];
        if (($sl['type'] ?? '') === 'auto' && isset(RS_BANNER_RULES[(string)($sl['rule'] ?? '')])) $out[] = array_filter(['type' => 'auto', 'rule' => (string)$sl['rule'], 'tag' => $w['tag']], fn($x) => $x !== '');
        elseif (($sl['type'] ?? '') === 'pick' && preg_match('/^[A-Za-z0-9._-]{1,64}$/', (string)($sl['id'] ?? ''))) $out[] = array_filter(['type' => 'pick', 'id' => (string)$sl['id']] + $w, fn($x) => $x !== '');
    }
    return $out;
}
function rs_pc_banner_slots_save(array $slots): array {
    $clean = [];
    foreach (array_keys(RS_BANNER_OS) as $os) $clean[$os] = rs_pc_banner_clean((array)($slots[$os] ?? []));
    rs_set_setting('pc_banner_slots', json_encode($clean, JSON_UNESCAPED_UNICODE));
    // Featured: the recomps picked by hand for any system.
    $on = [];
    foreach ($clean as $list) foreach ($list as $sl) if ($sl['type'] === 'pick') $on[$sl['id']] = true;
    foreach (rs_pc_apps() as $a) { $want = isset($on[$a['id']]); if ((bool)$a['featured'] !== $want) { $a['featured'] = $want; rs_pc_save($a); } }
    return $clean;
}
// Does a catalog entry run on this system (a build for it), and can it go in the banner (a backdrop)?
function rs_pc_banner_fits(array $e, string $os): bool {
    if (!empty($e['soon']) || (string)($e['hero'] ?? '') === '') return false;
    return in_array($os, (array)($e['systems'] ?? []), true) || !empty(((array)($e['assets'] ?? []))[$os]);
}
// How much players took to it: downloads, GitHub stars and RecompLauncher players, on a log scale.
function rs_pc_banner_pull(array $e): float {
    return log10(1 + (int)($e['downloads'] ?? 0)) * 2 + log10(1 + (int)($e['stars'] ?? 0)) * 3 + log10(1 + (int)($e['players'] ?? 0)) * 3;
}
function rs_pc_banner_days(string $d): float {
    $t = $d !== '' ? strtotime($d) : false;
    return $t ? max(0, (time() - $t) / 86400) : 9999;
}
// The candidates for an automatic slot, best first.
function rs_pc_banner_rank(array $apps, string $rule, string $os): array {
    $c = [];
    foreach ($apps as $e) {
        if (!rs_pc_banner_fits($e, $os)) continue;
        // How new: its first release (joining RecompLauncher later doesn't make an old recomp new).
        $born = rs_pc_banner_days((string)($e['first'] ?? '') !== '' ? (string)$e['first'] : (string)($e['added'] ?? ''));
        $upd = rs_pc_banner_days((string)($e['released'] ?? ''));
        $pull = rs_pc_banner_pull($e);
        $score = match ($rule) {
            'new' => $born <= 21 ? $pull + 4 - $born / 7 : null,
            'rising' => $born <= 60 ? $pull / sqrt($born / 7 + 1) : null,
            'popular' => (int)($e['players'] ?? 0) * 1000000 + (int)($e['downloads'] ?? 0),
            'updated' => $upd <= 7 && $born > 21 && $pull >= 8 ? $pull - $upd : null,
            default => null,
        };
        if ($score !== null) $c[] = [$score, $e];
    }
    usort($c, fn($x, $y) => $y[0] <=> $x[0]);
    return array_column($c, 1);
}
// The lists filled in: [banners => [os => ids], info => [id => {tag, text}], merged => ids, why => [os => [line]]].
function rs_pc_banner_resolve(array $apps, ?array $slots = null): array {
    $slots ??= rs_pc_banner_slots();
    $by = [];
    foreach ($apps as $e) $by[(string)$e['id']] = $e;
    $merged = []; $info = []; $banners = []; $why = [];
    foreach (array_keys(RS_BANNER_OS) as $os) {
        $ids = []; $games = [];
        $add = function (array $e, array $words) use (&$ids, &$games, &$merged, &$info) {
            $ids[] = $e['id'];
            $games[(string)($e['title'] ?? $e['id'])] = true;
            if (!in_array($e['id'], $merged, true)) $merged[] = $e['id'];
            if (!isset($info[$e['id']]) && ($words = array_filter($words, fn($x) => (string)$x !== ''))) $info[$e['id']] = $words;
        };
        foreach ((array)($slots[$os] ?? []) as $sl) {
            if ($sl['type'] === 'pick') {
                $e = $by[$sl['id']] ?? null;
                if (!$e || in_array($e['id'], $ids, true)) { $why[$os][] = ''; continue; }
                if (!rs_pc_banner_fits($e, $os)) { $why[$os][] = 'Not shown: no ' . RS_BANNER_OS[$os] . ' build' . ((string)($e['hero'] ?? '') === '' ? ' or backdrop' : ''); continue; }
                $add($e, ['tag' => $sl['tag'] ?? '', 'text' => $sl['text'] ?? '']);
                $why[$os][] = $e['id'];
                continue;
            }
            $pickFrom = array_values(array_filter(rs_pc_banner_rank($apps, $sl['rule'], $os), fn($e) => !in_array($e['id'], $ids, true) && !isset($games[(string)($e['title'] ?? $e['id'])])));
            if (!$pickFrom) { $why[$os][] = 'Nothing fits right now'; continue; }
            // Among the best three, one another system already shows: fewer extra slides on Windows.
            $e = $pickFrom[0];
            foreach (array_slice($pickFrom, 0, 3) as $x) if (in_array($x['id'], $merged, true)) { $e = $x; break; }
            $add($e, ['tag' => $sl['tag'] ?? RS_BANNER_RULES[$sl['rule']][0]]);
            $why[$os][] = $e['id'];
        }
        $banners[$os] = $ids;
    }
    return ['banners' => $banners, 'info' => $info, 'merged' => $merged, 'why' => $why];
}
// Every 30 minutes (pc-refresh): the automatic slots move on with what's new and popular, without a Publish.
function rs_pc_banner_patch(): bool {
    $cat = json_decode((string)@file_get_contents(RS_PC_CATALOG), true);
    if (!is_array($cat) || !is_array($cat['apps'] ?? null)) return false;
    $r = rs_pc_banner_resolve($cat['apps']);
    if (($cat['banner'] ?? null) === $r['merged'] && ($cat['banners'] ?? null) == $r['banners'] && (array)($cat['bannerInfo'] ?? []) == $r['info']) return false;
    $cat['banner'] = $r['merged']; $cat['banners'] = $r['banners']; $cat['bannerInfo'] = (object)$r['info'];
    $cat['updated'] = gmdate('c');
    return rs_pc_write_files($cat);
}
// The merged list (old callers).
function rs_pc_banner(): array {
    $cat = json_decode((string)@file_get_contents(RS_PC_CATALOG), true);
    return is_array($cat['banner'] ?? null) ? $cat['banner'] : [];
}
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
// (3.5) The published catalog, written in one line (the launcher reads it), and next to it the
// website's lighter copy: the same list without what only a game's own page shows (the original
// game's details, the launch settings, "What's in it"). The site asks for those one game at a time.
function rs_pc_write_files(array $cat, ?string $only = null): bool {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $json = json_encode($cat, $flags);
    $tmp = RS_PC_CATALOG . '.tmp';
    if ($json === false || file_put_contents($tmp, $json . "\n") === false || !rename($tmp, RS_PC_CATALOG)) return false;
    rs_json_gz(RS_PC_CATALOG);
    rs_pc_write_web($cat);
    // (4.6) One recomp brought up to date: only its own file. The others are still right, and the API
    // serves each game's file whenever it's there (rewriting all 1,400+ every few minutes kept the
    // server busy).
    rs_pc_write_app_files($cat, $only);
    // (4.4) The PHP copy the API reads is built here, once, not by the first players' requests.
    require_once __DIR__ . '/catcache.php';
    rs_json_cached(RS_PC_CATALOG);
    return true;
}
// (4.4) Each recomp's entry as its own small file, the answer to api/pc.php?a=app as it is: the most
// asked question no longer loads the whole catalog. All rewritten each time (the API only trusts ones
// as new as the catalog); ones no longer listed go.
function rs_pc_write_app_files(array $cat, ?string $only = null): void {
    $dir = dirname(RS_PUBLIC) . '/recompstore-private/pc-app';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $keep = [];
    foreach ((array)($cat['apps'] ?? []) as $a) {
        $id = (string)($a['id'] ?? '');
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $id)) continue;
        if ($only !== null && $id !== $only) { $keep["$id.json"] = true; continue; }
        $keep["$id.json"] = true;
        $body = json_encode(['ok' => true, 'app' => $a], $flags);
        if ($body === false) continue;
        $f = "$dir/$id.json";
        $tmp = "$f." . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $body) === false || !@rename($tmp, $f)) @unlink($tmp);
    }
    foreach (glob("$dir/*.json") ?: [] as $f) if (!isset($keep[basename($f)])) @unlink($f);
}
function rs_pc_web_copy(array $cat): array {
    $cat['apps'] = rs_pc_cat_apps($cat); // the website shows every host
    unset($cat['apps_min'], $cat['boot_all']);
    foreach ($cat['apps'] as &$a) {
        $series = is_array($a['about'] ?? null) ? (string)($a['about']['series'] ?? '') : '';
        unset($a['about'], $a['boot'], $a['launcher'], $a['features'], $a['copy_ids']);
        if ($series !== '') $a['about'] = ['series' => $series];
        $a['lite'] = true;
    }
    unset($a);
    return $cat;
}
function rs_pc_write_web(array $cat): void {
    $f = RS_PUBLIC . '/pc-catalog-web.json';
    $json = json_encode(rs_pc_web_copy($cat), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false && file_put_contents($f . '.tmp', $json . "\n") !== false && @rename($f . '.tmp', $f)) rs_json_gz($f);
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
    foreach ((array)($cat['apps_min'] ?? []) as $gi => $g) foreach ((array)($g['apps'] ?? []) as $i => $e)
        if (($e['id'] ?? '') === $id && $fresh) { $fresh['order'] = $e['order'] ?? $fresh['order']; $cat['apps_min'][$gi]['apps'][$i] = $fresh; $hit = true; }
    if (!$hit) return false;
    $cat['updated'] = gmdate('c');
    return rs_pc_write_files($cat, $id);
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
    // (4.1) Looked at per system: one system's file still matching (Linux) used to stop a renamed
    // Windows file from being followed, and the launcher said there was no Windows build.
    // (3.8) The release players get when one still has their files (the newest may just have other
    // ones: Switch, Android), else the newest full release.
    $latest = rs_pc_release_for($a);
    if (!$latest) {
        $rel = rs_host_releases(rs_pc_host($a), $a['repo'], 3600);
        $list = $rel['status'] === 200 ? rs_rel_sorted(json_decode((string)$rel['body'], true) ?: []) : [];
        foreach ($list as $x) if (empty($x['draft']) && empty($x['prerelease'])) { $latest = $x; break; }
    }
    if (!$latest) return $a;
    $names = array_map(fn($x) => (string)$x['name'], (array)$latest['assets']);
    foreach (['win' => 'asset_win', 'linux' => 'asset_linux', 'mac' => 'asset_mac'] as $sys => $k) {
        $p = trim((string)$a[$k]);
        $new = (string)($r['assets'][$sys] ?? '');
        if ($p === '' || $new === '') continue;
        $hit = false;
        foreach ($names as $n) if (rs_pattern_matches($p, $n)) { $hit = true; break; }
        if ($hit || !in_array($new, $names, true)) continue; // the new name has to be in that same release
        $np = rs_pc_pattern($new, (string)$latest['tag_name']);
        if ($np !== '' && $np !== $p) { $a[$k] = $np; rs_log('pc-asset', $a['id'], "$sys: $p -> $np"); }
    }
    return $a;
}

// The release rs_pc_read_repo goes by (rs_pc_pick_release).
function rs_pc_latest_release(string $repo, string $host = ''): ?array {
    $rel = rs_host_releases($host, $repo, 3600);
    return rs_pc_pick_release($rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : []);
}

// (3.8) The newest release (not a draft or pre-release) with a file matching one of the recomp's
// patterns; null when none of the recent ones has one.
function rs_pc_release_for(array $a): ?array {
    $pats = array_values(array_filter([(string)$a['asset_win'], (string)$a['asset_linux'], (string)$a['asset_mac']], fn($p) => trim($p) !== ''));
    $rel = rs_host_releases(rs_pc_host($a), (string)$a['repo'], 3600);
    $list = $rel['status'] === 200 ? rs_rel_sorted(json_decode((string)$rel['body'], true) ?: []) : [];
    $has = function (array $x) use ($pats): bool { foreach ((array)$x['assets'] as $f) foreach ($pats as $p) if (rs_pattern_matches($p, (string)$f['name'])) return true; return false; };
    foreach ($list as $x) {
        if (!empty($x['draft']) || !empty($x['prerelease'])) continue;
        if (!$pats || $has($x)) return $x;
    }
    // (4.0) A project that only puts out pre-releases (nightlies, alphas): its newest one with the file,
    // so it gets updates too. Projects with a full release that has the file stay on full releases.
    if ($pats) foreach ($list as $x) if (empty($x['draft']) && $has($x)) return $x;
    return null;
}

// (4.0) Is the download players get the right one? Looked at with each background refresh.
// -> null when the host didn't answer (keep what we knew), [] when it's fine, else
// ['dl' => gone|nofile|prerelease|stuck|oldfile, 'why' => one plain sentence].
function rs_pc_dl_check(array $a): ?array {
    if (!empty($a['soon']) || (string)$a['repo'] === '') return [];
    $pat = trim((string)$a['asset_win']) ?: (trim((string)$a['asset_linux']) ?: trim((string)$a['asset_mac']));
    if ($pat === '') return [];
    $rel = rs_host_releases(rs_pc_host($a), (string)$a['repo'], 3600);
    if ($rel['status'] === 404) return ['dl' => 'gone', 'why' => 'Its project page is gone: renamed, made private or deleted.'];
    if ($rel['status'] === 451) return ['dl' => 'gone', 'why' => 'Its repo was taken down (blocked for legal reasons, usually a DMCA notice).'];
    if ($rel['status'] !== 200) return null;
    $list = rs_rel_sorted(array_filter(json_decode((string)$rel['body'], true) ?: [], fn($x) => empty($x['draft'])));
    $stable = array_values(array_filter($list, fn($x) => empty($x['prerelease'])));
    $hit = function (array $x) use ($pat): string { foreach ((array)$x['assets'] as $f) if (rs_pattern_matches($pat, (string)$f['name'])) return (string)$f['name']; return ''; };
    // Like rs_pc_release_for: full releases, or its pre-releases when no full release has its file.
    $pool = array_values(array_filter($stable, fn($x) => $hit($x) !== '')) ? $stable : $list;
    if (!$pool) return ['dl' => 'nofile', 'why' => 'It has no releases.'];
    $stable = $pool;
    $newest = $stable[0];
    $name = $hit($newest);
    if ($name !== '') {
        // A file the developer marked as old (…_OLD.zip) while a newer one sits next to it.
        if (preg_match('/(^|[^a-z])(old|deprecated|backup|outdated)([^a-z]|$)/i', (string)preg_replace('/\.(zip|7z|rar|tar\.gz|exe|appimage|dmg)$/i', '', $name))) {
            $others = array_values(array_filter(array_map(fn($f) => (string)$f['name'], (array)$newest['assets']), fn($n) => $n !== $name && preg_match('/\.(zip|7z|exe)$/i', $n) && !preg_match('/(linux|mac|android)|(^|[^a-z0-9])(ios|apk|ipa)([^a-z0-9]|$)/i', $n)));
            return ['dl' => 'oldfile', 'why' => 'Players get ' . $name . ', which looks like an old copy' . ($others ? '. The release also has ' . implode(', ', array_slice($others, 0, 3)) . '.' : '.')];
        }
        return [];
    }
    $older = '';
    foreach (array_slice($stable, 1) as $x) if ($hit($x) !== '') { $older = (string)$x['tag_name']; break; }
    $winish = array_values(array_filter(array_map(fn($f) => (string)$f['name'], (array)$newest['assets']),
        fn($n) => preg_match('/\.(zip|7z|exe|rar)$/i', $n) && preg_match('/(win|x64|x86_64|amd64|pc)/i', $n) && !preg_match('/(linux|mac|android|switch|ps5|xbox)|(^|[^a-z0-9])ios([^a-z0-9]|$)/i', $n)));
    if ($older !== '' && $winish) return ['dl' => 'stuck', 'why' => 'Its newest release (' . $newest['tag_name'] . ') has ' . implode(', ', array_slice($winish, 0, 2)) . ', which doesn\'t match ' . $pat . ', so players still get ' . $older . '.'];
    if ($older === '') return ['dl' => 'nofile', 'why' => 'None of its releases has a file that matches ' . $pat . '.'];
    return [];
}

// (4.0) Does its README say development moved to another repo? -> 'owner/name' or ''. Only the top of
// the README counts: that's where a "this project has moved" note goes.
function rs_pc_moved_check(array $a): string {
    if ((string)$a['repo'] === '' || rs_pc_host($a) !== '') return '';
    $md = substr((string)(rs_pc_readme((string)$a['repo'])['md'] ?? ''), 0, 4000);
    $re = '/(?:has moved|have moved|moved to|moving to|migrated to|development (?:continues|has moved|now happens)|continues (?:at|in|on)|continued (?:at|in|on)|new (?:home|repo(?:sitory)?|location)|now lives (?:at|in)|(?:project|repo(?:sitory)?) (?:has been|was) (?:moved|transferred|renamed))[\s\S]{0,200}?github\.com\/([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+)/i';
    if (!preg_match_all($re, $md, $m)) return '';
    foreach ($m[1] as $to) {
        $to = (string)preg_replace('/(\.git|[.)\]]+)$/', '', $to);
        if (strcasecmp($to, (string)$a['repo']) !== 0 && rs_repo_ok($to)) return $to;
    }
    return '';
}

// (4.0) Points a recomp at another repo (it moved): the same listing, the new repo's files. Its next
// release is held for the security checks like any update, since it comes from somewhere new.
function rs_pc_switch_repo(array $a, string $to, string $host = ''): array {
    $r = rs_pc_read_repo($to, $host);
    if (empty($r['ok'])) throw new RuntimeException($r['why'] ?? 'That repo couldn\'t be read.');
    $from = rs_pc_key($a);
    $a['repo'] = (string)$r['repo'];
    $a['host'] = rs_host_norm($host);
    // Keep a download pattern that still fits the new repo's files; else take the one it was read with.
    $rel = rs_host_releases($a['host'], $a['repo'], 600);
    $names = [];
    foreach (array_slice(json_decode((string)$rel['body'], true) ?: [], 0, 5) as $x) foreach ((array)($x['assets'] ?? []) as $f) $names[] = (string)$f['name'];
    foreach (['win' => 'asset_win', 'linux' => 'asset_linux', 'mac' => 'asset_mac'] as $k => $field) {
        $cur = trim((string)$a[$field]);
        $fits = $cur !== '' && array_filter($names, fn($n) => rs_pattern_matches($cur, $n));
        if (!$fits && (string)($r['pattern'][$k] ?? '') !== '') $a[$field] = (string)$r['pattern'][$k];
    }
    $a['ok_tag'] = 'moved:' . (string)($a['ok_tag'] ?? '');
    unset($a['pending']);
    foreach (['moved_to', 'moved_ack', 'dl_state', 'dl_why', 'dl_ack'] as $k) $a[$k] = '';
    $a['moved_at'] = time();
    $a['gh_at'] = 0; // read again on the next refresh
    rs_log('pc', 'move', $a['id'] . ': ' . $from . ' -> ' . rs_pc_key($a));
    return $a;
}

// (4.0) What needs a look on a recomp: a download problem or a move you haven't marked as fine.
// (4.2) RecompLauncher on Mac installs .zip files only, and runs on Apple Silicon. '' when a Mac
// download pattern is fine, otherwise why it isn't.
function rs_pc_mac_why(string $pat): string {
    $p = strtolower(trim($pat));
    if ($p === '') return '';
    // (4.3) The launcher on Mac installs .zip, .dmg, .rar and .7z (since #37).
    if (!preg_match('/\.(zip|dmg|rar|7z)$/', $p)) return 'The Mac download has to be a .zip, .dmg, .rar or .7z. RecompLauncher on Mac can\'t install a .tar.gz, a .pkg or a file with no type.';
    if (preg_match('/(ppc|powerpc|tiger|leopard)/', $p)) return 'This Mac download is for old PowerPC Macs. RecompLauncher on Mac needs an Apple Silicon or universal build.';
    if (preg_match('/(x86[_-]?64|amd64|intel|(^|[^a-z0-9])x64([^a-z0-9]|$))/', $p) && !preg_match('/(arm64|aarch64|universal|apple[-_ ]?silicon)/', $p))
        return 'This Mac download looks Intel-only by its name. RecompLauncher on Mac needs an Apple Silicon or universal build.';
    return '';
}
// The Mac pattern when it's fine, '' when it isn't.
function rs_pc_mac_ok(string $pat): string { return rs_pc_mac_why($pat) === '' ? $pat : ''; }

function rs_pc_alerts(array $a): array {
    $out = [];
    if ((string)($a['dl_state'] ?? '') !== '' && (string)($a['dl_ack'] ?? '') !== $a['dl_state'] . '|' . ($a['dl_why'] ?? '')) $out['dl'] = (string)$a['dl_why'];
    if ((string)($a['moved_to'] ?? '') !== '' && (string)($a['moved_ack'] ?? '') !== $a['moved_to']) $out['moved'] = (string)$a['moved_to'];
    return $out;
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
        // (4.0) Whether players get the right file, and (once a week) whether the project moved.
        try {
            $dl = rs_pc_dl_check($a);
            if ($dl !== null) { $a['dl_state'] = (string)($dl['dl'] ?? ''); $a['dl_why'] = (string)($dl['why'] ?? ''); }
            if (time() - (int)($a['moved_at'] ?? 0) > 6 * 86400) { $a['moved_to'] = rs_pc_moved_check($a); $a['moved_at'] = time(); }
        } catch (Throwable $e) { /* looked at again next time */ }
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
        // (4.3) A SourceForge release is checked even when it's the first one.
        $sfFirst = rs_pc_host($a) === 'sourceforge' && $okTag === '' && $tag !== '' && function_exists('rs_vt_on') && rs_vt_on();
        if (!$sfFirst && (!function_exists('rs_vt_on') || !rs_vt_on() || $tag === '' || $okTag === '' || $tag === $okTag)) {
            $a['version'] = $r['version']; $a['released'] = $r['released'];
            if ($r['size']) $a['size'] = rs_human_size((int)$r['size']);
            if ($tag !== '') $a['ok_tag'] = $tag;
            unset($a['pending']);
        } else $a = rs_vt_hold($a, $latest, $r);
        rs_pc_save($a);
    }
    return $n;
}

// ---- (4.0) Notify me: players ask to hear when a Coming soon recomp comes out ---------------------
function rs_pc_notify_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS pc_notify (
        account_id INT NOT NULL,
        app VARCHAR(64) NOT NULL,
        created DATETIME NOT NULL,
        sent DATETIME NULL,
        PRIMARY KEY (account_id, app), INDEX (app, sent)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// The recomps this account is waiting on (not emailed about yet).
function rs_pc_notify_list(int $accountId): array {
    rs_pc_notify_tables();
    $st = rs_db()->prepare('SELECT app FROM pc_notify WHERE account_id = ? AND sent IS NULL');
    $st->execute([$accountId]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

function rs_pc_notify_set(int $accountId, string $app, bool $on): void {
    rs_pc_notify_tables();
    if (!$on) { rs_db()->prepare('DELETE FROM pc_notify WHERE account_id = ? AND app = ? AND sent IS NULL')->execute([$accountId, $app]); return; }
    $n = rs_db()->prepare('SELECT COUNT(*) FROM pc_notify WHERE account_id = ? AND sent IS NULL');
    $n->execute([$accountId]);
    if ((int)$n->fetchColumn() >= 200) throw new RuntimeException('You\'re waiting on 200 games already.');
    rs_db()->prepare('INSERT INTO pc_notify (account_id, app, created, sent) VALUES (?, ?, UTC_TIMESTAMP(), NULL) ON DUPLICATE KEY UPDATE sent = NULL, created = UTC_TIMESTAMP()')->execute([$accountId, $app]);
}

// Emails everyone whose game came out (listed, not Coming soon any more), one email each, and marks
// them done. Runs after Publish PC. -> how many emails went.
function rs_pc_notify_send(int $max = 40): int {
    rs_pc_notify_tables();
    $rows = rs_db()->query('SELECT n.account_id, n.app FROM pc_notify n JOIN accounts a ON a.id = n.account_id WHERE n.sent IS NULL AND a.email_ok = 1 AND a.banned = 0 AND a.email IS NOT NULL')->fetchAll();
    if (!$rows) return 0;
    $out = [];
    foreach ($rows as $r) {
        $x = rs_pc_app((string)$r['app']);
        if (!$x || $x['hidden'] || !empty($x['soon'])) continue;
        $out[(int)$r['account_id']][] = $x;
    }
    $out = array_slice($out, 0, $max, true);
    if (!$out) return 0;
    $msgs = []; $ids = [];
    foreach ($out as $accId => $apps) {
        $a = rs_acct_get($accId);
        if (!$a || (string)($a['email'] ?? '') === '') continue;
        $lines = array_map(fn($x) => '- ' . $x['name'] . ': https://recomplauncher.com/game/' . rawurlencode($x['id']), $apps);
        $one = count($apps) === 1;
        $text = 'Hi ' . ($a['username'] ?? 'there') . ",\n\n" . ($one ? $apps[0]['name'] . ' is out. You asked us to tell you when it was ready:' : 'Games you were waiting for are out:') . "\n" . implode("\n", $lines)
            . "\n\nInstall it in RecompLauncher, or get it from its page.\n";
        $msgs[] = [(string)$a['email'], rs_mail_build((string)$a['email'], $one ? $apps[0]['name'] . ' is out' : 'Games you were waiting for are out', $text,
            ['footer' => "You're getting this because you pressed Notify Me on " . ($one ? $apps[0]['name'] : 'these games') . "'s page. It's only sent once."])['data']];
        $ids[] = [$accId, array_column($apps, 'id')];
    }
    if (!$msgs) return 0;
    try { $errs = rs_smtp_send($msgs); } catch (RuntimeException $e) { return 0; } // mail not set up: tried again next Publish
    $up = rs_db()->prepare('UPDATE pc_notify SET sent = UTC_TIMESTAMP() WHERE account_id = ? AND app = ?');
    $ok = 0;
    foreach ($ids as $i => [$accId, $appIds]) if (($errs[$i] ?? '') === '') { $ok++; foreach ($appIds as $id) $up->execute([$accId, $id]); }
    return $ok;
}

function rs_pc_publish(): int {
    rs_pc_refresh_github(20);
    if (function_exists('rs_titles_autogroup')) rs_titles_autogroup(); // (3.8) one game, one listing
    $cat = rs_pc_catalog();
    if (!rs_pc_write_files($cat))
        throw new RuntimeException('Could not write pc-catalog.json. Check that public_html is writable.');
    rs_log('publish', 'pc-catalog', count($cat['apps']) . ' apps');
    if (function_exists('rs_kv_put')) rs_kv_put('dash_pc_changed', '0'); // (4.3) the Dashboard's count
    // (4.0) Players waiting on a game that just came out hear about it; and new ones go to Discord.
    try { if (($n = rs_pc_notify_send(40)) > 0) rs_log('publish', 'notify', $n . ' Notify Me emails'); } catch (Throwable $e) { /* tried again next Publish */ }
    try { rs_pc_discord_announce($cat); } catch (Throwable $e) { /* best effort */ }
    return count($cat['apps']);
}
