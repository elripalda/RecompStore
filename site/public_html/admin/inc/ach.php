<?php
// (4.4) Recomp Achievements: the server side. Lists (SDK format 1), launcher sessions, unlocks with
// the Ranked rules, and Mastered games. Dev only for now: the API answers test accounts alone
// (setting ach_testers), and nothing reaches the public catalog until ach_public is on.
declare(strict_types=1);

const RS_ACH_POINTS = [5, 10, 25, 50];
const RS_ACH_SOURCES = ['memory', 'native', 'events'];
const RS_ACH_CONSOLES = ['n64', 'snes', 'gb', 'gba', 'psx', 'ps2', 'xbox', 'x360'];
const RS_ACH_TYPES = ['progression', 'win', 'missable'];
// Ranked rules. A session counts as live when its last check-in is this recent.
const RS_ACH_LIVE_GAP = 900;       // 15 minutes past the last check-in
const RS_ACH_BURST_SECS = 30;      // native unlocks this soon after a save load or session start...
const RS_ACH_BURST_MIN = 3;        // ...and at least this many together = a save replaying old unlocks
const RS_ACH_FLOOD = 20;           // more unlocks than this in one minute go to the review queue

function rs_ach_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS ach_lists (
        game VARCHAR(80) PRIMARY KEY,
        data MEDIUMTEXT NOT NULL,
        live TINYINT NOT NULL DEFAULT 0,
        updated DATETIME NOT NULL,
        who VARCHAR(80) NOT NULL DEFAULT \'\'
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_sessions (
        id CHAR(32) PRIMARY KEY,
        account_id INT NOT NULL,
        game VARCHAR(80) NOT NULL,
        version VARCHAR(40) NOT NULL DEFAULT \'\',
        started DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        checkins INT NOT NULL DEFAULT 0,
        save_loaded DATETIME NULL,
        casual VARCHAR(40) NOT NULL DEFAULT \'\',
        KEY acct_game (account_id, game)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_unlocks (
        account_id INT NOT NULL,
        game VARCHAR(80) NOT NULL,
        ach INT UNSIGNED NOT NULL,
        source VARCHAR(10) NOT NULL,
        points SMALLINT NOT NULL DEFAULT 0,
        at DATETIME NOT NULL,
        session CHAR(32) NOT NULL DEFAULT \'\',
        ranked TINYINT NOT NULL DEFAULT 0,
        reason VARCHAR(40) NOT NULL DEFAULT \'\',
        PRIMARY KEY (account_id, game, ach),
        KEY game_ach (game, ach),
        KEY recent (at)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_mastered (
        account_id INT NOT NULL,
        game VARCHAR(80) NOT NULL,
        at DATETIME NOT NULL,
        ranked TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (account_id, game)
    ) CHARACTER SET utf8mb4');
    // Native achievements' names and gamerscore, as players' launchers read them from their own copy.
    $db->exec('CREATE TABLE IF NOT EXISTS ach_native (
        game VARCHAR(80) NOT NULL,
        ach INT UNSIGNED NOT NULL,
        title VARCHAR(120) NOT NULL DEFAULT \'\',
        description VARCHAR(300) NOT NULL DEFAULT \'\',
        gamerscore SMALLINT NOT NULL DEFAULT 0,
        seen DATETIME NOT NULL,
        PRIMARY KEY (game, ach)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_review (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        game VARCHAR(80) NOT NULL,
        session CHAR(32) NOT NULL DEFAULT \'\',
        why VARCHAR(200) NOT NULL,
        at DATETIME NOT NULL,
        done TINYINT NOT NULL DEFAULT 0,
        KEY open_items (done, at)
    ) CHARACTER SET utf8mb4');
    // Schema 2: late sessions (start, check-ins, end and save loads as the launcher kept them),
    // and where each game's ReXGlue unlock file turned up.
    if (rs_setting('ach_schema', '1') !== '2') {
        $have = array_column($db->query('SHOW COLUMNS FROM ach_sessions')->fetchAll(), 'Field');
        foreach (['ended' => 'DATETIME NULL', 'times' => 'TEXT NULL', 'loads' => 'TEXT NULL', 'local_id' => "VARCHAR(64) NOT NULL DEFAULT ''"] as $c => $def)
            if (!in_array($c, $have, true)) $db->exec("ALTER TABLE ach_sessions ADD COLUMN $c $def");
        $idx = array_column($db->query('SHOW INDEX FROM ach_sessions')->fetchAll(), 'Key_name');
        if (!in_array('acct_local', $idx, true)) $db->exec('ALTER TABLE ach_sessions ADD KEY acct_local (account_id, local_id)');
        $db->exec('CREATE TABLE IF NOT EXISTS ach_folders (
            game VARCHAR(80) NOT NULL,
            folder VARCHAR(200) NOT NULL,
            n INT NOT NULL DEFAULT 1,
            seen DATETIME NOT NULL,
            PRIMARY KEY (game, folder)
        ) CHARACTER SET utf8mb4');
        rs_set_setting('ach_schema', '2');
    }
    // Tester applications (from the launcher's Achievements page or recomplauncher.com/achievements).
    $db->exec('CREATE TABLE IF NOT EXISTS ach_apply (
        account_id INT PRIMARY KEY,
        status VARCHAR(10) NOT NULL DEFAULT \'pending\',
        applied DATETIME NOT NULL,
        decided DATETIME NULL,
        who VARCHAR(80) NOT NULL DEFAULT \'\',
        os VARCHAR(20) NOT NULL DEFAULT \'\',
        plays VARCHAR(300) NOT NULL DEFAULT \'\',
        why VARCHAR(600) NOT NULL DEFAULT \'\',
        discord VARCHAR(60) NOT NULL DEFAULT \'\',
        terms VARCHAR(10) NOT NULL DEFAULT \'\',
        KEY by_status (status, applied)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_ach_game_ok(string $g): bool { return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $g); }

// Xbox 360 gamerscore onto the 5 / 10 / 25 / 50 scale, so totals compare across games.
function rs_ach_points_from_gs(int $gs): int { return $gs <= 10 ? 5 : ($gs <= 30 ? 10 : ($gs <= 75 ? 25 : 50)); }

// ---- testers ---------------------------------------------------------------------------------

function rs_ach_testers(): array {
    $raw = (string)rs_setting('ach_testers', '');
    return array_values(array_filter(array_map(fn($s) => strtolower(trim($s)), preg_split('/[\s,]+/', $raw) ?: [])));
}
// A tester: named in the admin's list, or an approved application.
function rs_ach_is_tester(array $acct): bool {
    if (in_array(strtolower((string)($acct['username'] ?? '')), rs_ach_testers(), true)) return true;
    $app = isset($acct['id']) ? rs_ach_application((int)$acct['id']) : null;
    return $app && $app['status'] === 'approved';
}

// ---- tester applications ------------------------------------------------------------------------

// The tester terms' version. Changing the terms means a new version: applying asks for this one.
const RS_ACH_TERMS_V = '1';
const RS_ACH_OS = ['windows', 'linux', 'mac', 'steamdeck'];
const RS_ACH_REAPPLY_DAYS = 14;

function rs_ach_apply_open(): bool { return rs_setting('ach_apply_open', '1') === '1'; }

function rs_ach_application(int $acct, bool $fresh = false): ?array {
    static $cache = [];
    if (!$fresh && array_key_exists($acct, $cache)) return $cache[$acct];
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT * FROM ach_apply WHERE account_id = ?');
    $st->execute([$acct]);
    return $cache[$acct] = ($st->fetch() ?: null);
}

// -> null when it went in, or the reason it didn't (for the player).
function rs_ach_apply(array $acct, array $in): ?string {
    if (!rs_ach_apply_open()) return 'Tester applications are closed right now. Check back soon.';
    if (($acct['username'] ?? null) === null) return 'Pick a username for your account first.';
    if (($in['agree'] ?? null) !== true || (string)($in['terms'] ?? '') !== RS_ACH_TERMS_V) return 'Read and accept the tester terms to apply.';
    $os = strtolower(trim((string)($in['os'] ?? '')));
    if (!in_array($os, RS_ACH_OS, true)) return 'Pick the system you play on.';
    $clip = fn(string $k, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($in[$k] ?? ''))), 0, $n);
    $plays = $clip('plays', 300); $why = $clip('why', 600); $discord = $clip('discord', 60);
    if (mb_strlen($plays) < 2) return 'Tell us a few recomps you play.';
    $have = rs_ach_application((int)$acct['id'], true);
    if ($have && $have['status'] === 'approved') return null;
    if ($have && $have['status'] === 'declined' && strtotime((string)$have['decided'] . ' UTC') > time() - RS_ACH_REAPPLY_DAYS * 86400)
        return 'You applied recently. You can apply again after ' . gmdate('M j', strtotime((string)$have['decided'] . ' UTC') + RS_ACH_REAPPLY_DAYS * 86400) . '.';
    rs_db()->prepare('INSERT INTO ach_apply (account_id, status, applied, os, plays, why, discord, terms) VALUES (?, \'pending\', UTC_TIMESTAMP(), ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = \'pending\', applied = VALUES(applied), decided = NULL, who = \'\', os = VALUES(os), plays = VALUES(plays), why = VALUES(why), discord = VALUES(discord), terms = VALUES(terms)')
        ->execute([(int)$acct['id'], $os, $plays, $why, $discord, RS_ACH_TERMS_V]);
    return null;
}

// What the launcher and the site show a signed-in player.
function rs_ach_status(array $acct): array {
    $app = rs_ach_application((int)$acct['id'], true);
    return ['tester' => rs_ach_is_tester($acct), 'apply_open' => rs_ach_apply_open(), 'terms' => RS_ACH_TERMS_V,
        'needs_name' => ($acct['username'] ?? null) === null,
        'application' => $app ? ['status' => $app['status'], 'applied' => (int)strtotime($app['applied'] . ' UTC'),
            'decided' => $app['decided'] ? (int)strtotime($app['decided'] . ' UTC') : null] : null];
}

function rs_ach_applications(string $status, int $n = 200): array {
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT p.*, a.username, a.email, a.created FROM ach_apply p LEFT JOIN accounts a ON a.id = p.account_id WHERE p.status = ? ORDER BY p.applied ' . ($status === 'pending' ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(1000, $n)));
    $st->execute([$status]);
    return $st->fetchAll();
}
function rs_ach_application_counts(): array {
    rs_ach_tables();
    $out = ['pending' => 0, 'approved' => 0, 'declined' => 0];
    foreach (rs_db()->query('SELECT status, COUNT(*) n FROM ach_apply GROUP BY status')->fetchAll() as $r) $out[$r['status']] = (int)$r['n'];
    return $out;
}
// Approve or decline applications. -> how many changed.
function rs_ach_decide(array $ids, string $status, string $who): int {
    if (!in_array($status, ['approved', 'declined', 'pending'], true) || !$ids) return 0;
    rs_ach_tables();
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $st = rs_db()->prepare('UPDATE ach_apply SET status = ?, decided = ' . ($status === 'pending' ? 'NULL' : 'UTC_TIMESTAMP()') . ', who = ? WHERE account_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_merge([$status, mb_substr($who, 0, 80)], $ids));
    return $st->rowCount();
}

// ---- lists -----------------------------------------------------------------------------------

// Checks a list against SDK format 1. -> [errors[], warnings[], cleaned list or null]
function rs_ach_check(string $json, string $game): array {
    $e = []; $w = [];
    $l = json_decode($json, true);
    if (!is_array($l) || array_is_list($l)) return [['Not a JSON object.'], [], null];
    if ((int)($l['format'] ?? 0) !== 1) $e[] = 'format has to be 1.';
    if (($l['game'] ?? '') !== $game) $e[] = 'game has to be "' . $game . '" (this recomp\'s id).';
    $src = (string)($l['source'] ?? '');
    if (!in_array($src, RS_ACH_SOURCES, true)) $e[] = 'source is memory, native or events.';
    if (!in_array((string)($l['console'] ?? ''), RS_ACH_CONSOLES, true)) $e[] = 'console is one of ' . implode(', ', RS_ACH_CONSOLES) . '.';
    if (isset($l['copies']) && !is_array($l['copies'])) $e[] = 'copies is a list.';
    if (isset($l['voids']) && !is_array($l['voids'])) $e[] = 'voids is a list of setting keys.';
    $list = $l['achievements'] ?? [];
    if (!is_array($list) || ($list && !array_is_list($list))) { $e[] = 'achievements is a list.'; $list = []; }
    if ($src !== 'native' && !$list) $e[] = 'A memory or events list needs achievements.';
    $ids = [];
    foreach ($list as $i => $a) {
        $n = 'Achievement ' . ($i + 1);
        if (!is_array($a)) { $e[] = "$n isn't an object."; continue; }
        $id = $a['id'] ?? null;
        if (!is_int($id) || $id < 1) { $e[] = "$n: id is a whole number from 1."; continue; }
        if (isset($ids[$id])) $e[] = "$n: id $id is used twice.";
        $ids[$id] = true;
        if ($src !== 'native') {
            if (trim((string)($a['title'] ?? '')) === '') $e[] = "#$id needs a title.";
            if (!in_array($a['points'] ?? null, RS_ACH_POINTS, true)) $e[] = "#$id: points is 5, 10, 25 or 50.";
        }
        if (isset($a['type']) && !in_array($a['type'], RS_ACH_TYPES, true)) $e[] = "#$id: type is progression, win or missable.";
        if ($src === 'memory' && trim((string)($a['trigger'] ?? '')) === '' && trim((string)($a['event'] ?? '')) === '') $e[] = "#$id needs a trigger.";
        if ($src === 'events' && trim((string)($a['event'] ?? '')) === '') $e[] = "#$id needs an event name.";
        if (mb_strlen((string)($a['title'] ?? '')) > 80) $w[] = "#$id: the title is long for the toast (80+ letters).";
    }
    if ($list && !array_filter($list, fn($a) => is_array($a) && ($a['type'] ?? '') === 'win')) $w[] = 'No achievement has type "win" (finishing the game).';
    return [$e, $w, $e ? null : $l];
}

function rs_ach_list(string $game): ?array {
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT data, live FROM ach_lists WHERE game = ?');
    $st->execute([$game]);
    $r = $st->fetch();
    if (!$r) return null;
    $l = json_decode((string)$r['data'], true);
    return is_array($l) ? $l + ['_live' => (int)$r['live']] : null;
}

function rs_ach_lists(): array {
    rs_ach_tables();
    $out = [];
    foreach (rs_db()->query('SELECT game, data, live, updated, who FROM ach_lists ORDER BY game')->fetchAll() as $r) {
        $l = json_decode((string)$r['data'], true) ?: [];
        $out[$r['game']] = ['list' => $l, 'live' => (int)$r['live'], 'updated' => $r['updated'], 'who' => $r['who']];
    }
    return $out;
}

function rs_ach_list_save(string $game, array $list, bool $live, string $who): void {
    rs_ach_tables();
    rs_db()->prepare('INSERT INTO ach_lists (game, data, live, updated, who) VALUES (?, ?, ?, UTC_TIMESTAMP(), ?)
        ON DUPLICATE KEY UPDATE data = VALUES(data), live = VALUES(live), updated = VALUES(updated), who = VALUES(who)')
        ->execute([$game, json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $live ? 1 : 0, $who]);
}

function rs_ach_list_delete(string $game): void {
    rs_ach_tables();
    rs_db()->prepare('DELETE FROM ach_lists WHERE game = ?')->execute([$game]);
}

// What the launcher gets for a game: the list as saved (memory and events lists carry their
// rules), plus the native names players' launchers have read so far.
function rs_ach_list_for_launcher(string $game): ?array {
    $l = rs_ach_list($game);
    if (!$l) return null;
    unset($l['_live']);
    if (($l['source'] ?? '') === 'native') {
        $st = rs_db()->prepare('SELECT ach, title, description, gamerscore FROM ach_native WHERE game = ? ORDER BY ach');
        $st->execute([$game]);
        $l['names'] = array_map(fn($r) => ['id' => (int)$r['ach'], 'title' => $r['title'], 'description' => $r['description'],
            'points' => rs_ach_points_from_gs((int)$r['gamerscore'])], $st->fetchAll());
    }
    return $l;
}

// ---- sessions --------------------------------------------------------------------------------

// A session, live or sent late. $t: started, checkins[], ended, save_loaded[] (unix times the launcher
// kept), local (its own session id: the same one sent twice gets the same session back), unlock_folder.
function rs_ach_session_start(int $acct, string $game, string $version, string $casual, array $t = []): string {
    rs_ach_tables();
    $db = rs_db();
    $now = time();
    $local = preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($t['local'] ?? '')) ? (string)$t['local'] : '';
    if ($local !== '') {
        $st = $db->prepare('SELECT id FROM ach_sessions WHERE account_id = ? AND local_id = ?');
        $st->execute([$acct, $local]);
        if ($id = $st->fetchColumn()) return (string)$id;
    }
    $clamp = fn($x) => max($now - 30 * 86400, min($now, (int)$x));
    $started = isset($t['started']) && (int)$t['started'] > 0 ? $clamp($t['started']) : $now;
    $times = [];
    foreach ((array)($t['checkins'] ?? []) as $c) if ((int)$c > 0) $times[] = $clamp($c);
    $times[] = $started;
    $ended = isset($t['ended']) && (int)$t['ended'] > 0 ? max($started, $clamp($t['ended'])) : 0;
    if ($ended) $times[] = $ended;
    $times = array_values(array_unique(array_filter($times, fn($x) => $x >= $started)));
    sort($times);
    $times = array_slice($times, -2000);
    $loads = [];
    foreach ((array)($t['save_loaded'] ?? []) as $c) if ((int)$c > 0) $loads[] = max($started, $clamp($c));
    sort($loads);
    $loads = array_slice(array_values(array_unique($loads)), -200);
    $id = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO ach_sessions (id, account_id, game, version, started, last_seen, checkins, save_loaded, casual, ended, times, loads, local_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $acct, $game, mb_substr($version, 0, 40), gmdate('Y-m-d H:i:s', $started), gmdate('Y-m-d H:i:s', max($times)),
            max(0, count($times) - 1), $loads ? gmdate('Y-m-d H:i:s', end($loads)) : null, mb_substr($casual, 0, 40),
            $ended ? gmdate('Y-m-d H:i:s', $ended) : null, json_encode($times), json_encode($loads), $local]);
    $folder = rs_ach_folder_clean((string)($t['unlock_folder'] ?? ''));
    if ($folder !== '') $db->prepare('INSERT INTO ach_folders (game, folder, n, seen) VALUES (?, ?, 1, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE n = n + 1, seen = VALUES(seen)')->execute([$game, $folder]);
    // The game's native achievement list, as the recomp gave it at hello (names, gamerscore).
    if (!empty($t['native']) && is_array($t['native'])) {
        rs_ach_native_store($game, $t['native']);
        rs_ach_auto_list($game, $t['native'], in_array((string)($t['console'] ?? ''), RS_ACH_CONSOLES, true) ? (string)$t['console'] : 'x360', $acct);
    }
    return $id;
}

// ---- automatic lists ----------------------------------------------------------------------------

// Online-only achievements (Xbox LIVE matches and the like) stay out of a list, so Mastered can be
// reached offline. Read from the achievement's own words.
function rs_ach_native_online(array $a): bool {
    $t = (string)($a['title'] ?? '') . ' ' . (string)($a['description'] ?? '');
    return (bool)preg_match('/xbox\s*live|\bonline\b|\branked\s+(match|game|exhibition)|\bmultiplayer\s+online|system\s*link|\bLIVE\b/i', $t)
        && !preg_match('/\bco-?op\b(?!.*(online|live))/i', $t);
}

// The first time a game's native list comes in (from the player's own copy, at session start) and the
// game has no list yet, one is made from it: points from gamerscore, online-only ones left out, live
// for testers, and a note in the review queue so the admin can look it over. -> true when made.
function rs_ach_auto_list(string $game, array $native, string $console, int $acct): bool {
    if (rs_ach_list($game) || rs_setting('ach_auto_lists', '1') !== '1') return false;
    $seen = []; $keep = []; $online = [];
    foreach (array_slice($native, 0, 400) as $a) {
        if (!is_array($a)) continue;
        $id = (int)($a['id'] ?? 0);
        $title = trim(mb_substr((string)($a['title'] ?? ''), 0, 120));
        if ($id < 1 || isset($seen[$id])) continue;
        $seen[$id] = true;
        if ($title === '') continue;
        if (rs_ach_native_online($a)) { $online[] = $id; continue; }
        $gs = max(0, min(1000, (int)($a['gamerscore'] ?? 0)));
        $keep[] = ['id' => $id, 'title' => $title, 'description' => trim(mb_substr((string)($a['description'] ?? ''), 0, 300)),
            'points' => rs_ach_points_from_gs($gs), 'gamerscore' => $gs];
    }
    // A real table: a handful at least, named, and not more gamerscore than a disc can hold.
    $gsTotal = array_sum(array_column($keep, 'gamerscore'));
    if (count($keep) < 3 || count($keep) < count($seen) / 2 || $gsTotal > 2000) return false;
    usort($keep, fn($x, $y) => $x['id'] <=> $y['id']);
    $list = ['format' => 1, 'game' => $game, 'source' => 'native', 'console' => $console, 'versions' => '>=0.0.0',
        'total' => count($keep), 'auto' => true, 'online' => $online, 'achievements' => $keep];
    $who = '';
    try { $st = rs_db()->prepare('SELECT username FROM accounts WHERE id = ?'); $st->execute([$acct]); $who = (string)$st->fetchColumn(); } catch (Throwable $e) {}
    rs_ach_list_save($game, $list, true, 'auto' . ($who !== '' ? ' · ' . $who : ''));
    rs_db()->prepare('INSERT INTO ach_review (account_id, game, session, why, at) VALUES (?, ?, \'\', ?, UTC_TIMESTAMP())')
        ->execute([$acct, $game, 'New list made from this player\'s copy: ' . count($keep) . ' achievements' . ($online ? ', ' . count($online) . ' online-only left out' : '') . '. Look it over.']);
    return true;
}

// The unlock folder as the launcher writes it, without the player's name: it has to start with one of
// these and can't climb out of it.
function rs_ach_folder_clean(string $f): string {
    $parts = array_values(array_filter(explode('\\', str_replace('/', '\\', trim($f))), fn($p) => $p !== ''));
    if (!$parts || strlen($f) > 200) return '';
    if (!in_array($parts[0], ['{game}', '%APPDATA%', '%LOCALAPPDATA%', '%USERPROFILE%', '%DOCUMENTS%'], true)) return '';
    foreach (array_slice($parts, 1) as $p) if ($p === '.' || $p === '..' || preg_match('/[:*?"<>|\x00-\x1f%{}]/', $p)) return '';
    return implode('\\', $parts);
}

// Most reported folder per game -> [game => folder]
function rs_ach_folders(): array {
    rs_ach_tables();
    $out = [];
    foreach (rs_db()->query('SELECT game, folder, n FROM ach_folders ORDER BY game, n DESC, seen DESC')->fetchAll() as $r)
        if (!isset($out[$r['game']])) $out[$r['game']] = $r['folder'];
    return $out;
}

function rs_ach_native_store(string $game, array $list): void {
    $st = rs_db()->prepare('INSERT INTO ach_native (game, ach, title, description, gamerscore, seen) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE title = IF(VALUES(title) <> \'\', VALUES(title), title), description = IF(VALUES(description) <> \'\', VALUES(description), description),
        gamerscore = IF(VALUES(gamerscore) > 0, VALUES(gamerscore), gamerscore), seen = VALUES(seen)');
    foreach (array_slice($list, 0, 1000) as $a) {
        if (!is_array($a) || (int)($a['id'] ?? 0) < 1) continue;
        $st->execute([$game, (int)$a['id'], mb_substr((string)($a['title'] ?? ''), 0, 120), mb_substr((string)($a['description'] ?? ''), 0, 300), max(0, min(1000, (int)($a['gamerscore'] ?? 0)))]);
    }
}

function rs_ach_session(int $acct, string $id): ?array {
    if (!preg_match('/^[0-9a-f]{32}$/', $id)) return null;
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT * FROM ach_sessions WHERE id = ? AND account_id = ?');
    $st->execute([$id, $acct]);
    $r = $st->fetch();
    if (!$r) return null;
    // Stored as UTC; read as UTC here, whatever the database's own time zone is.
    $r['s'] = (int)strtotime($r['started'] . ' UTC');
    $r['l'] = (int)strtotime($r['last_seen'] . ' UTC');
    $r['sl'] = $r['save_loaded'] ? (int)strtotime($r['save_loaded'] . ' UTC') : 0;
    $r['e'] = !empty($r['ended']) ? (int)strtotime($r['ended'] . ' UTC') : 0;
    $r['tl'] = array_map('intval', json_decode((string)($r['times'] ?? ''), true) ?: []);
    $r['ll'] = array_map('intval', json_decode((string)($r['loads'] ?? ''), true) ?: []);
    if ($r['sl'] && !in_array($r['sl'], $r['ll'], true)) $r['ll'][] = $r['sl'];
    return $r;
}

// A check-in: the session is still running. casual / save_loaded come from the recomp's messages.
function rs_ach_checkin(array $s, string $casual, bool $saveLoaded): void {
    $now = time();
    $times = $s['tl'] ?: [(int)$s['s']];
    $times[] = $now;
    $loads = $s['ll'];
    if ($saveLoaded) $loads[] = $now;
    $sql = 'UPDATE ach_sessions SET last_seen = UTC_TIMESTAMP(), checkins = checkins + 1, times = ?, loads = ?';
    $args = [json_encode(array_slice($times, -2000)), json_encode(array_slice($loads, -200))];
    if ($casual !== '' && (string)$s['casual'] === '') { $sql .= ', casual = ?'; $args[] = mb_substr($casual, 0, 40); }
    if ($saveLoaded) $sql .= ', save_loaded = UTC_TIMESTAMP()';
    $args[] = $s['id'];
    rs_db()->prepare($sql . ' WHERE id = ?')->execute($args);
}

// ---- unlocks and the Ranked rules ---------------------------------------------------------------

// One batch of unlocks from a session. Each: {id, source, at (unix), casual?, reason?, earlier?,
// title?, description?, gamerscore? (native names read from the player's copy)}.
// -> [results [{id, ranked, reason, new}], mastered (bool)]
function rs_ach_record(array $acct, array $s, array $unlocks): array {
    rs_ach_tables();
    $db = rs_db();
    $game = (string)$s['game'];
    $list = rs_ach_list($game);
    $byId = [];
    foreach ((array)($list['achievements'] ?? []) as $a) if (isset($a['id'])) $byId[(int)$a['id']] = $a;
    $now = time();
    $started = (int)$s['s'];
    $lastSeen = max((int)$s['l'], $started);
    $sessionCasual = (string)$s['casual'];

    // Native unlocks clustered right after the session start or a save load = a save replaying them.
    $nativeTimes = [];
    foreach ($unlocks as $u) if (($u['source'] ?? '') === 'native' && empty($u['earlier'])) $nativeTimes[] = (int)($u['at'] ?? $now);
    $burstFrom = function (int $t) use ($nativeTimes): bool {
        return count(array_filter($nativeTimes, fn($x) => $x >= $t && $x <= $t + RS_ACH_BURST_SECS)) >= RS_ACH_BURST_MIN;
    };
    $burstStart = $burstFrom($started);
    $burstLoads = array_values(array_filter($s['ll'], fn($t) => $burstFrom($t)));
    $times = $s['tl'] ?: [$started, $lastSeen];
    $ended = (int)$s['e'];
    // Live: within the session, and close to a time the launcher saw it running (start, a check-in, the end).
    $live = function (int $at) use ($times, $started, $ended): bool {
        if ($at < $started - 60 || ($ended && $at > $ended + 60)) return false;
        foreach ($times as $t) if ($at >= $t - 60 && $at <= $t + RS_ACH_LIVE_GAP) return true;
        return false;
    };

    $get = $db->prepare('SELECT ranked FROM ach_unlocks WHERE account_id = ? AND game = ? AND ach = ?');
    $ins = $db->prepare('INSERT INTO ach_unlocks (account_id, game, ach, source, points, at, session, ranked, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $up = $db->prepare('UPDATE ach_unlocks SET ranked = 1, reason = \'\', at = ?, session = ? WHERE account_id = ? AND game = ? AND ach = ? AND ranked = 0');
    $meta = $db->prepare('INSERT INTO ach_native (game, ach, title, description, gamerscore, seen) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE title = IF(title = \'\', VALUES(title), title), description = IF(description = \'\', VALUES(description), description), gamerscore = IF(gamerscore = 0, VALUES(gamerscore), gamerscore)');
    $results = [];
    $recent = 0;
    foreach (array_slice($unlocks, 0, 200) as $u) {
        if (!is_array($u)) continue;
        $id = (int)($u['id'] ?? 0);
        $src = (string)($u['source'] ?? '');
        if ($id < 1 || !in_array($src, RS_ACH_SOURCES, true)) continue;
        $at = (int)($u['at'] ?? $now);
        if ($at > $now + 300) $at = $now;
        $gs = max(0, min(1000, (int)($u['gamerscore'] ?? 0)));
        if ($src === 'native' && (($u['title'] ?? '') !== '' || $gs > 0))
            $meta->execute([$game, $id, mb_substr((string)($u['title'] ?? ''), 0, 120), mb_substr((string)($u['description'] ?? ''), 0, 300), $gs]);
        if ($src !== 'native' && !isset($byId[$id])) { $results[] = ['id' => $id, 'ranked' => false, 'reason' => 'unknown', 'new' => false]; continue; }
        $points = $src === 'native' ? rs_ach_points_from_gs($gs ?: (int)rs_ach_native_gs($game, $id)) : (int)($byId[$id]['points'] ?? 0);

        // The Ranked rules, first match wins.
        $why = '';
        if (!empty($u['earlier'])) $why = 'earlier';
        elseif ($sessionCasual !== '') $why = $sessionCasual;
        elseif (!empty($u['casual']) || trim((string)($u['reason'] ?? '')) !== '') $why = mb_substr(trim((string)($u['reason'] ?? '')), 0, 40) ?: 'casual';
        elseif (!$live($at)) $why = 'no_session';
        elseif ($src === 'native' && $burstStart && $at <= $started + RS_ACH_BURST_SECS) $why = 'save_replay';
        elseif ($src === 'native' && array_filter($burstLoads, fn($t) => $at >= $t && $at <= $t + RS_ACH_BURST_SECS)) $why = 'save_replay';
        $ranked = $why === '';
        if ($ranked && $at > $now - 60) ++$recent;

        $get->execute([(int)$acct['id'], $game, $id]);
        $had = $get->fetchColumn();
        if ($had === false) {
            $ins->execute([(int)$acct['id'], $game, $id, $src, $points, gmdate('Y-m-d H:i:s', $at), $s['id'], $ranked ? 1 : 0, $why]);
            $results[] = ['id' => $id, 'ranked' => $ranked, 'reason' => $why, 'new' => true];
        } elseif ((int)$had === 0 && $ranked) {
            // Casual before, earned properly now: it moves up to Ranked.
            $up->execute([gmdate('Y-m-d H:i:s', $at), $s['id'], (int)$acct['id'], $game, $id]);
            $results[] = ['id' => $id, 'ranked' => true, 'reason' => '', 'new' => false];
        } else {
            $results[] = ['id' => $id, 'ranked' => (int)$had === 1, 'reason' => (int)$had === 1 ? '' : $why, 'new' => false];
        }
    }
    if ($recent > RS_ACH_FLOOD)
        $db->prepare('INSERT INTO ach_review (account_id, game, session, why, at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([(int)$acct['id'], $game, $s['id'], $recent . ' Ranked unlocks inside a minute']);
    $forMastered = $list ?: (array_filter($unlocks, fn($u) => is_array($u) && ($u['source'] ?? '') === 'native') ? ['source' => 'native'] : null);
    return [$results, rs_ach_check_mastered((int)$acct['id'], $game, $forMastered)];
}

function rs_ach_native_gs(string $game, int $id): int {
    $st = rs_db()->prepare('SELECT gamerscore FROM ach_native WHERE game = ? AND ach = ?');
    $st->execute([$game, $id]);
    return (int)($st->fetchColumn() ?: 0);
}

// Mastered when every achievement on the list is unlocked; Ranked when every one of them is.
// Native lists give their count as "total" (the launcher reads it from the player's copy).
function rs_ach_check_mastered(int $acct, string $game, ?array $list): bool {
    if (!$list) return false;
    if (($list['source'] ?? '') === 'native') {
        $total = (int)($list['total'] ?? 0);
        if ($total < 1) { $st = rs_db()->prepare('SELECT COUNT(*) FROM ach_native WHERE game = ?'); $st->execute([$game]); $total = (int)$st->fetchColumn(); }
    } else $total = count((array)($list['achievements'] ?? []));
    if ($total < 1) return false;
    $st = rs_db()->prepare('SELECT COUNT(*) n, SUM(ranked) r FROM ach_unlocks WHERE account_id = ? AND game = ?');
    $st->execute([$acct, $game]);
    $c = $st->fetch();
    if ((int)$c['n'] < $total) return false;
    $ranked = (int)$c['r'] >= $total ? 1 : 0;
    rs_db()->prepare('INSERT INTO ach_mastered (account_id, game, at, ranked) VALUES (?, ?, UTC_TIMESTAMP(), ?)
        ON DUPLICATE KEY UPDATE ranked = GREATEST(ranked, VALUES(ranked))')->execute([$acct, $game, $ranked]);
    return true;
}

// A player's unlocks in one game, for the launcher's Achievements tab.
function rs_ach_mine(int $acct, string $game): array {
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT ach, source, points, at, ranked, reason FROM ach_unlocks WHERE account_id = ? AND game = ? ORDER BY at');
    $st->execute([$acct, $game]);
    $rows = array_map(fn($r) => ['id' => (int)$r['ach'], 'source' => $r['source'], 'points' => (int)$r['points'], 'at' => (int)strtotime($r['at'] . ' UTC'),
        'ranked' => (bool)$r['ranked'], 'reason' => $r['reason']], $st->fetchAll());
    $m = rs_db()->prepare('SELECT ranked FROM ach_mastered WHERE account_id = ? AND game = ?');
    $m->execute([$acct, $game]);
    $mk = $m->fetchColumn();
    return ['unlocks' => $rows, 'points' => array_sum(array_map(fn($r) => $r['ranked'] ? $r['points'] : 0, $rows)),
        'mastered' => $mk === false ? null : ((int)$mk ? 'ranked' : 'casual')];
}

// Dev tools' Reset: a test account clears its own unlocks for one game.
function rs_ach_reset(int $acct, string $game): int {
    rs_ach_tables();
    $st = rs_db()->prepare('DELETE FROM ach_unlocks WHERE account_id = ? AND game = ?');
    $st->execute([$acct, $game]);
    rs_db()->prepare('DELETE FROM ach_mastered WHERE account_id = ? AND game = ?')->execute([$acct, $game]);
    return $st->rowCount();
}

// Admin: recent unlocks and the open review items.
function rs_ach_recent(int $n = 40): array {
    rs_ach_tables();
    return rs_db()->query('SELECT u.*, a.username FROM ach_unlocks u LEFT JOIN accounts a ON a.id = u.account_id ORDER BY u.at DESC LIMIT ' . max(1, min(200, $n)))->fetchAll();
}
function rs_ach_review_open(): array {
    rs_ach_tables();
    return rs_db()->query('SELECT r.*, a.username FROM ach_review r LEFT JOIN accounts a ON a.id = r.account_id WHERE r.done = 0 ORDER BY r.at DESC LIMIT 100')->fetchAll();
}
function rs_ach_unlock_counts(string $game): array {
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT ach, COUNT(*) n, SUM(ranked) r FROM ach_unlocks WHERE game = ? GROUP BY ach');
    $st->execute([$game]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['ach']] = [(int)$r['n'], (int)$r['r']];
    return $out;
}

// ---- games, profiles and leaderboards ----------------------------------------------------------

// A catalog entry's name and art, from the small per-app file a publish writes (not the whole catalog).
function rs_ach_app_card(string $game): array {
    static $cache = [];
    if (isset($cache[$game])) return $cache[$game];
    $a = null;
    $f = dirname(RS_PUBLIC) . '/recompstore-private/pc-app/' . $game . '.json';
    if (rs_ach_game_ok($game) && is_file($f)) $a = (json_decode((string)file_get_contents($f), true) ?: [])['app'] ?? null;
    if (!is_array($a) && function_exists('rs_pc_app')) $a = rs_pc_app($game);
    $a = is_array($a) ? $a : [];
    return $cache[$game] = ['id' => $game, 'name' => (string)($a['name'] ?? $game), 'game' => (string)($a['game'] ?? ''),
        'cover' => (string)($a['cover'] ?? ''), 'icon' => (string)($a['icon'] ?? ''), 'hero' => (string)($a['hero'] ?? '')];
}

// How many achievements count for Mastered, and their points.
function rs_ach_list_totals(array $list, string $game): array {
    $items = (array)($list['achievements'] ?? []);
    if (($list['source'] ?? '') === 'native' && !$items) {
        $st = rs_db()->prepare('SELECT gamerscore FROM ach_native WHERE game = ?');
        $st->execute([$game]);
        $gs = $st->fetchAll(PDO::FETCH_COLUMN);
        $n = (int)($list['total'] ?? 0) ?: count($gs);
        return [$n, array_sum(array_map(fn($g) => rs_ach_points_from_gs((int)$g), $gs))];
    }
    $n = ($list['source'] ?? '') === 'native' ? ((int)($list['total'] ?? 0) ?: count($items)) : count($items);
    return [$n, array_sum(array_map(fn($a) => (int)($a['points'] ?? 0), $items))];
}

// Every game with a live list: what the Achievements pages list as compatible.
function rs_ach_games(): array {
    rs_ach_tables();
    $db = rs_db();
    $players = []; $mastered = [];
    foreach ($db->query('SELECT game, COUNT(DISTINCT account_id) n FROM ach_unlocks GROUP BY game')->fetchAll() as $r) $players[$r['game']] = (int)$r['n'];
    foreach ($db->query('SELECT game, COUNT(*) n FROM ach_mastered GROUP BY game')->fetchAll() as $r) $mastered[$r['game']] = (int)$r['n'];
    $out = [];
    foreach (rs_ach_lists() as $g => $l) {
        if (!$l['live']) continue;
        [$n, $pts] = rs_ach_list_totals($l['list'], $g);
        $out[] = rs_ach_app_card($g) + ['console' => (string)($l['list']['console'] ?? ''), 'source' => (string)($l['list']['source'] ?? ''),
            'achievements' => $n, 'points' => $pts, 'players' => $players[$g] ?? 0, 'mastered' => $mastered[$g] ?? 0,
            'added' => (int)strtotime($l['updated'] . ' UTC')];
    }
    usort($out, fn($a, $b) => [$b['players'], $a['name']] <=> [$a['players'], $b['name']]);
    return $out;
}

// Leaderboard: Ranked points (one game, or every game), then Mastered games, then who got there first.
function rs_ach_board(string $game = '', int $n = 50): array {
    rs_ach_tables();
    $where = $game !== '' ? 'WHERE u.game = ?' : '';
    $st = rs_db()->prepare('SELECT u.account_id, a.username, a.avatar, SUM(u.points * u.ranked) pts, SUM(u.ranked) ranked, COUNT(*) total, MAX(u.at) last,
            (SELECT COUNT(*) FROM ach_mastered m WHERE m.account_id = u.account_id AND m.ranked = 1' . ($game !== '' ? ' AND m.game = u.game' : '') . ') mastered
        FROM ach_unlocks u JOIN accounts a ON a.id = u.account_id AND a.banned = 0 AND a.username IS NOT NULL ' . $where . '
        GROUP BY u.account_id, a.username, a.avatar' . ($game !== '' ? ', u.game' : '') . '
        ORDER BY pts DESC, mastered DESC, last ASC LIMIT ' . max(1, min(200, $n)));
    $st->execute($game !== '' ? [$game] : []);
    $out = []; $rank = 0;
    foreach ($st->fetchAll() as $r) $out[] = ['rank' => ++$rank, 'username' => $r['username'], 'avatar' => rs_acct_avatar_url($r),
        'points' => (int)$r['pts'], 'ranked' => (int)$r['ranked'], 'unlocks' => (int)$r['total'], 'mastered' => (int)$r['mastered']];
    return $out;
}

function rs_ach_stats(): array {
    rs_ach_tables();
    $db = rs_db();
    $u = $db->query('SELECT COUNT(DISTINCT account_id) p, COUNT(*) n, COALESCE(SUM(ranked), 0) r FROM ach_unlocks')->fetch();
    return ['players' => (int)$u['p'], 'unlocks' => (int)$u['n'], 'ranked' => (int)$u['r'],
        'mastered' => (int)$db->query('SELECT COUNT(*) FROM ach_mastered')->fetchColumn(),
        'games' => count(array_filter(rs_ach_lists(), fn($l) => $l['live'])),
        'testers' => rs_ach_application_counts()['approved'] + count(rs_ach_testers())];
}

// An achievement's name, from its list or from what players' copies reported.
function rs_ach_title(string $game, int $id): array {
    static $lists = [];
    $l = $lists[$game] ??= (rs_ach_list($game) ?: []);
    foreach ((array)($l['achievements'] ?? []) as $a) if ((int)($a['id'] ?? 0) === $id) return [(string)($a['title'] ?? ''), (string)($a['description'] ?? '')];
    $st = rs_db()->prepare('SELECT title, description FROM ach_native WHERE game = ? AND ach = ?');
    $st->execute([$game, $id]);
    $r = $st->fetch();
    return $r ? [(string)$r['title'], (string)$r['description']] : ['', ''];
}

// A player's whole picture: points, rank, every game they've played, Mastered games, latest unlocks.
function rs_ach_profile(int $acct): array {
    rs_ach_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT game, COUNT(*) n, SUM(ranked) r, SUM(points * ranked) pts, MAX(at) last FROM ach_unlocks WHERE account_id = ? GROUP BY game ORDER BY last DESC');
    $st->execute([$acct]);
    $rows = $st->fetchAll();
    $ms = $db->prepare('SELECT game, at, ranked FROM ach_mastered WHERE account_id = ? ORDER BY at DESC');
    $ms->execute([$acct]);
    $mast = [];
    foreach ($ms->fetchAll() as $m) $mast[$m['game']] = $m;
    $lists = rs_ach_lists();
    $games = []; $points = 0; $unlocks = 0; $ranked = 0;
    foreach ($rows as $r) {
        $g = $r['game'];
        [$n, $pts] = isset($lists[$g]) ? rs_ach_list_totals($lists[$g]['list'], $g) : [0, 0];
        $points += (int)$r['pts']; $unlocks += (int)$r['n']; $ranked += (int)$r['r'];
        $games[] = rs_ach_app_card($g) + ['unlocked' => (int)$r['n'], 'ranked' => (int)$r['r'], 'achievements' => $n, 'points' => (int)$r['pts'], 'points_total' => $pts,
            'mastered' => isset($mast[$g]) ? ((int)$mast[$g]['ranked'] ? 'ranked' : 'casual') : null, 'last' => (int)strtotime($r['last'] . ' UTC')];
    }
    $mastered = [];
    foreach ($mast as $g => $m) $mastered[] = rs_ach_app_card($g) + ['at' => (int)strtotime($m['at'] . ' UTC'), 'ranked' => (bool)$m['ranked']];
    $rs = $db->prepare('SELECT game, ach, points, at, ranked FROM ach_unlocks WHERE account_id = ? ORDER BY at DESC LIMIT 12');
    $rs->execute([$acct]);
    $recent = [];
    foreach ($rs->fetchAll() as $r) {
        [$title, $desc] = rs_ach_title($r['game'], (int)$r['ach']);
        $recent[] = ['game' => $r['game'], 'name' => rs_ach_app_card($r['game'])['name'], 'id' => (int)$r['ach'], 'title' => $title, 'description' => $desc,
            'points' => (int)$r['points'], 'at' => (int)strtotime($r['at'] . ' UTC'), 'ranked' => (bool)$r['ranked']];
    }
    // Rank: players with more Ranked points, plus one. Zero points = no rank yet.
    $rank = null;
    if ($points > 0) {
        $st = $db->query('SELECT COUNT(*) FROM (SELECT account_id FROM ach_unlocks GROUP BY account_id HAVING SUM(points * ranked) > ' . $points . ') x');
        $rank = (int)$st->fetchColumn() + 1;
    }
    $players = (int)$db->query('SELECT COUNT(DISTINCT account_id) FROM ach_unlocks')->fetchColumn();
    return ['points' => $points, 'rank' => $rank, 'players' => $players, 'unlocks' => $unlocks, 'ranked' => $ranked,
        'mastered_count' => count($mastered), 'games' => $games, 'mastered' => $mastered, 'recent' => $recent];
}
