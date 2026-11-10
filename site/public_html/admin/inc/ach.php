<?php
// (4.4) Recomp Achievements: the server side. Lists (SDK format 1), launcher sessions, unlocks with
// the Ranked rules, and Mastered games. Dev only for now: the API answers test accounts alone
// (setting ach_testers), and nothing reaches the public catalog until ach_public is on.
declare(strict_types=1);

const RS_ACH_POINTS = [5, 10, 25, 50];
const RS_ACH_SOURCES = ['memory', 'native', 'events'];
const RS_ACH_CONSOLES = ['n64', 'snes', 'gb', 'gba', 'psx', 'ps2', 'xbox', 'x360'];
const RS_ACH_TYPES = ['progression', 'win', 'missable'];
const RS_ACH_LB_FORMATS = ['score', 'time', 'frames', 'millisecs', 'seconds', 'minutes', 'value'];
const RS_ACH_TICKET_KINDS = ['didnt_unlock' => 'Didn\'t unlock', 'too_early' => 'Unlocked by itself or too early', 'wrong_text' => 'Wrong name or description', 'other' => 'Something else'];
const RS_ACH_EVENT_KINDS = ['unlocks' => 'Unlock achievements', 'beat' => 'Beat games', 'master' => 'Master games', 'play' => 'Play games'];
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
    // (4.6) Beaten games, broken-achievement reports, events, rich presence and game leaderboards.
    $db->exec('CREATE TABLE IF NOT EXISTS ach_beaten (
        account_id INT NOT NULL, game VARCHAR(80) NOT NULL, at DATETIME NOT NULL, ranked TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (account_id, game)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_tickets (
        id INT AUTO_INCREMENT PRIMARY KEY, account_id INT NOT NULL, game VARCHAR(80) NOT NULL, ach INT UNSIGNED NOT NULL,
        kind VARCHAR(20) NOT NULL, note VARCHAR(500) NOT NULL DEFAULT \'\', version VARCHAR(40) NOT NULL DEFAULT \'\',
        at DATETIME NOT NULL, status VARCHAR(10) NOT NULL DEFAULT \'open\', decided DATETIME NULL, who VARCHAR(80) NOT NULL DEFAULT \'\', answer VARCHAR(300) NOT NULL DEFAULT \'\',
        KEY open_game (status, game), KEY mine (account_id, game, ach)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_events (
        id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(80) NOT NULL, description VARCHAR(300) NOT NULL DEFAULT \'\',
        kind VARCHAR(10) NOT NULL, games TEXT NULL, console VARCHAR(10) NOT NULL DEFAULT \'\', goal INT NOT NULL DEFAULT 1,
        starts DATETIME NOT NULL, ends DATETIME NOT NULL, created DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_event_done (
        event_id INT NOT NULL, account_id INT NOT NULL, at DATETIME NOT NULL, PRIMARY KEY (event_id, account_id), KEY by_acct (account_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_presence (
        account_id INT PRIMARY KEY, game VARCHAR(80) NOT NULL, text VARCHAR(200) NOT NULL DEFAULT \'\', at DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ach_lb (
        game VARCHAR(80) NOT NULL, board INT UNSIGNED NOT NULL, account_id INT NOT NULL, value BIGINT NOT NULL,
        at DATETIME NOT NULL, session CHAR(32) NOT NULL DEFAULT \'\', ranked TINYINT NOT NULL DEFAULT 0,
        PRIMARY KEY (game, board, account_id), KEY board_value (game, board, ranked, value)
    ) CHARACTER SET utf8mb4');
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

// (4.5) The team's sticky note for testers, pinned on the launcher's Achievements page.
// -> null, or {id, title, text, button?, url?}. id changes with every new note, so a tester who folded
// the last one sees the new one open again.
function rs_ach_note(): ?array {
    $n = json_decode((string)rs_setting('ach_note', ''), true);
    if (!is_array($n) || trim((string)($n['text'] ?? '')) === '') return null;
    $out = ['id' => (string)($n['id'] ?? ''), 'title' => (string)($n['title'] ?? ''), 'text' => (string)$n['text']];
    if (!empty($n['button']) && !empty($n['url'])) { $out['button'] = (string)$n['button']; $out['url'] = (string)$n['url']; }
    return $out;
}
function rs_ach_note_save(string $title, string $text, string $button, string $url): ?string {
    $text = trim(str_replace("\r", '', $text));
    if ($text === '') { rs_set_setting('ach_note', ''); return null; }
    $url = trim($url);
    if ($url !== '' && !preg_match('#^https://[^\s]+$#', $url)) return 'The button link has to start with https://';
    rs_set_setting('ach_note', json_encode(['id' => (string)time(), 'title' => mb_substr(trim($title), 0, 80), 'text' => mb_substr($text, 0, 1000),
        'button' => $url !== '' ? (mb_substr(trim($button), 0, 40) ?: 'Open') : '', 'url' => mb_substr($url, 0, 300)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return null;
}

// What the launcher and the site show a signed-in player.
function rs_ach_status(array $acct): array {
    $app = rs_ach_application((int)$acct['id'], true);
    // dev: the launcher's dev tools (channel log, memory viewer, rule tester, Reset), for the team
    // accounts in the admin's test account list.
    $team = in_array(strtolower((string)($acct['username'] ?? '')), rs_ach_testers(), true);
    return ['tester' => rs_ach_is_tester($acct), 'dev' => $team, 'apply_open' => rs_ach_apply_open(), 'terms' => RS_ACH_TERMS_V,
        'needs_name' => ($acct['username'] ?? null) === null, 'note' => rs_ach_is_tester($acct) ? rs_ach_note() : null,
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
        if (isset($a['bonus']) && !is_bool($a['bonus'])) $e[] = "#$id: bonus is true or false.";
        if (!empty($a['bonus']) && ($a['type'] ?? '') === 'win') $e[] = "#$id: a bonus achievement can't be the win.";
    }
    // (4.6) Game leaderboards: {id, title, description?, format, lower_is_better?, and for memory lists
    // mem (rcheevos STA/CAN/SUB/VAL) or start, cancel, submit, value}. Events lists submit from the port's code.
    $boards = $l['leaderboards'] ?? [];
    if (!is_array($boards) || ($boards && !array_is_list($boards))) { $e[] = 'leaderboards is a list.'; $boards = []; }
    $bids = [];
    foreach ($boards as $i => $b) {
        $n = 'Leaderboard ' . ($i + 1);
        if (!is_array($b) || !is_int($b['id'] ?? null) || $b['id'] < 1) { $e[] = "$n: id is a whole number from 1."; continue; }
        if (isset($bids[$b['id']])) $e[] = "$n: id {$b['id']} is used twice.";
        $bids[$b['id']] = true;
        if (trim((string)($b['title'] ?? '')) === '') $e[] = "$n needs a title.";
        if (!in_array($b['format'] ?? '', RS_ACH_LB_FORMATS, true)) $e[] = "$n: format is " . implode(', ', RS_ACH_LB_FORMATS) . '.';
        if ($src === 'memory' && trim((string)($b['mem'] ?? '')) === '' && (trim((string)($b['start'] ?? '')) === '' || trim((string)($b['submit'] ?? '')) === '' || trim((string)($b['value'] ?? '')) === ''))
            $e[] = "$n needs mem, or start, cancel, submit and value.";
    }
    if (isset($l['rich_presence']) && (!is_string($l['rich_presence']) || strlen($l['rich_presence']) > 65536)) $e[] = 'rich_presence is a script (text, under 64 KB).';
    if (isset($l['rich_presence']) && $src !== 'memory') $w[] = 'rich_presence only runs on memory lists. Ports set their own text with rl_ach_presence.';
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
    // (4.6) The game's native achievement list, as the recomp gave it (names, gamerscore). The launcher
    // reads it from the player's default.xex a moment after the game starts, so it usually comes with a
    // second session-start for a session the website already has: it counts either way.
    $native = function () use ($t, $game, $acct): void {
        if (empty($t['native']) || !is_array($t['native'])) return;
        rs_ach_native_store($game, $t['native']);
        rs_ach_auto_list($game, $t['native'], in_array((string)($t['console'] ?? ''), RS_ACH_CONSOLES, true) ? (string)$t['console'] : 'x360', $acct);
    };
    $local = preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($t['local'] ?? '')) ? (string)$t['local'] : '';
    if ($local !== '') {
        $st = $db->prepare('SELECT id FROM ach_sessions WHERE account_id = ? AND local_id = ?');
        $st->execute([$acct, $local]);
        if ($id = $st->fetchColumn()) { $native(); return (string)$id; }
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
    $native();
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
    $mastered = rs_ach_check_mastered((int)$acct['id'], $game, $forMastered);
    $beaten = rs_ach_check_beaten((int)$acct['id'], $game, $list);
    return [$results, $mastered, $beaten, rs_ach_events_check((int)$acct['id'])];
}

function rs_ach_native_gs(string $game, int $id): int {
    $st = rs_db()->prepare('SELECT gamerscore FROM ach_native WHERE game = ? AND ach = ?');
    $st->execute([$game, $id]);
    return (int)($st->fetchColumn() ?: 0);
}

// The ids that count for Mastered (every achievement but bonus ones), or null when the list doesn't
// name them (a native list with only a total).
function rs_ach_counted_ids(?array $list): ?array {
    $items = (array)($list['achievements'] ?? []);
    if (!$items) return null;
    return array_values(array_map(fn($a) => (int)$a['id'], array_filter($items, fn($a) => is_array($a) && isset($a['id']) && empty($a['bonus']))));
}

// Mastered when every counted achievement is unlocked; Ranked when every one of them is.
function rs_ach_check_mastered(int $acct, string $game, ?array $list): bool {
    if (!$list) return false;
    $ids = rs_ach_counted_ids($list);
    $db = rs_db();
    if ($ids !== null) {
        if (!$ids) return false;
        $st = $db->prepare('SELECT COUNT(*) n, COALESCE(SUM(ranked), 0) r FROM ach_unlocks WHERE account_id = ? AND game = ? AND ach IN (' . implode(',', $ids) . ')');
        $st->execute([$acct, $game]);
        $total = count($ids);
    } else {
        $total = (int)($list['total'] ?? 0);
        if ($total < 1) { $st = $db->prepare('SELECT COUNT(*) FROM ach_native WHERE game = ?'); $st->execute([$game]); $total = (int)$st->fetchColumn(); }
        if ($total < 1) return false;
        $st = $db->prepare('SELECT COUNT(*) n, COALESCE(SUM(ranked), 0) r FROM ach_unlocks WHERE account_id = ? AND game = ?');
        $st->execute([$acct, $game]);
    }
    $c = $st->fetch();
    if ((int)$c['n'] < $total) return false;
    $db->prepare('INSERT INTO ach_mastered (account_id, game, at, ranked) VALUES (?, ?, UTC_TIMESTAMP(), ?)
        ON DUPLICATE KEY UPDATE ranked = GREATEST(ranked, VALUES(ranked))')->execute([$acct, $game, (int)$c['r'] >= $total ? 1 : 0]);
    return true;
}

// Beaten (as RetroAchievements has it): every progression achievement and at least one win. Only lists
// that mark a win can be Beaten. Ranked when all of those unlocks are.
function rs_ach_beat_ids(?array $list): array {
    $prog = []; $win = [];
    foreach ((array)($list['achievements'] ?? []) as $a) {
        if (!is_array($a) || !isset($a['id']) || !empty($a['bonus'])) continue;
        if (($a['type'] ?? '') === 'progression') $prog[] = (int)$a['id'];
        if (($a['type'] ?? '') === 'win') $win[] = (int)$a['id'];
    }
    return [$prog, $win];
}
function rs_ach_check_beaten(int $acct, string $game, ?array $list): bool {
    [$prog, $win] = rs_ach_beat_ids($list);
    if (!$win) return false;
    $st = rs_db()->prepare('SELECT ach, ranked FROM ach_unlocks WHERE account_id = ? AND game = ? AND ach IN (' . implode(',', array_merge($prog, $win)) . ')');
    $st->execute([$acct, $game]);
    $have = [];
    foreach ($st->fetchAll() as $r) $have[(int)$r['ach']] = (int)$r['ranked'];
    foreach ($prog as $id) if (!isset($have[$id])) return false;
    $wins = array_values(array_filter($win, fn($id) => isset($have[$id])));
    if (!$wins) return false;
    $ranked = !array_filter($prog, fn($id) => !$have[$id]) && array_filter($wins, fn($id) => $have[$id]) ? 1 : 0;
    rs_db()->prepare('INSERT INTO ach_beaten (account_id, game, at, ranked) VALUES (?, ?, UTC_TIMESTAMP(), ?)
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
    $b = rs_db()->prepare('SELECT ranked FROM ach_beaten WHERE account_id = ? AND game = ?');
    $b->execute([$acct, $game]);
    $bk = $b->fetchColumn();
    $t = rs_db()->prepare('SELECT ach, status FROM ach_tickets WHERE account_id = ? AND game = ? ORDER BY id');
    $t->execute([$acct, $game]);
    $reported = [];
    foreach ($t->fetchAll() as $r) $reported[(int)$r['ach']] = $r['status'];
    [$rare, $players] = rs_ach_rarity($game);
    return ['unlocks' => $rows, 'points' => array_sum(array_map(fn($r) => $r['ranked'] ? $r['points'] : 0, $rows)),
        'mastered' => $mk === false ? null : ((int)$mk ? 'ranked' : 'casual'), 'beaten' => $bk === false ? null : ((int)$bk ? 'ranked' : 'casual'),
        'rarity' => (object)$rare, 'players' => $players, 'reported' => (object)$reported,
        'boards' => rs_ach_lb_mine($acct, $game)];
}

// Dev tools' Reset: a test account clears its own unlocks for one game.
function rs_ach_reset(int $acct, string $game): int {
    rs_ach_tables();
    $st = rs_db()->prepare('DELETE FROM ach_unlocks WHERE account_id = ? AND game = ?');
    $st->execute([$acct, $game]);
    rs_db()->prepare('DELETE FROM ach_mastered WHERE account_id = ? AND game = ?')->execute([$acct, $game]);
    rs_db()->prepare('DELETE FROM ach_beaten WHERE account_id = ? AND game = ?')->execute([$acct, $game]);
    rs_db()->prepare('DELETE FROM ach_lb WHERE account_id = ? AND game = ?')->execute([$acct, $game]);
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
    $counted = array_values(array_filter($items, fn($a) => is_array($a) && empty($a['bonus'])));
    $n = ($list['source'] ?? '') === 'native' && empty($list['auto']) && !$counted ? (int)($list['total'] ?? 0) : count($counted);
    return [$n, array_sum(array_map(fn($a) => (int)($a['points'] ?? 0), $counted))];
}

// Every game with a live list: what the Achievements pages list as compatible.
function rs_ach_games(): array {
    rs_ach_tables();
    $db = rs_db();
    $players = []; $mastered = [];
    foreach ($db->query('SELECT game, COUNT(DISTINCT account_id) n FROM ach_unlocks GROUP BY game')->fetchAll() as $r) $players[$r['game']] = (int)$r['n'];
    foreach ($db->query('SELECT game, COUNT(*) n FROM ach_mastered GROUP BY game')->fetchAll() as $r) $mastered[$r['game']] = (int)$r['n'];
    $beaten = [];
    foreach ($db->query('SELECT game, COUNT(*) n FROM ach_beaten GROUP BY game')->fetchAll() as $r) $beaten[$r['game']] = (int)$r['n'];
    $out = [];
    foreach (rs_ach_lists() as $g => $l) {
        if (!$l['live']) continue;
        [$n, $pts] = rs_ach_list_totals($l['list'], $g);
        $out[] = rs_ach_app_card($g) + ['console' => (string)($l['list']['console'] ?? ''), 'source' => (string)($l['list']['source'] ?? ''),
            'achievements' => $n, 'points' => $pts, 'players' => $players[$g] ?? 0, 'mastered' => $mastered[$g] ?? 0, 'beaten' => $beaten[$g] ?? 0,
            'leaderboards' => count((array)($l['list']['leaderboards'] ?? [])), 'rich_presence' => isset($l['list']['rich_presence']),
            'added' => (int)strtotime($l['updated'] . ' UTC')];
    }
    usort($out, fn($a, $b) => [$b['players'], $a['name']] <=> [$a['players'], $b['name']]);
    return $out;
}


function rs_ach_stats(): array {
    rs_ach_tables();
    $db = rs_db();
    $u = $db->query('SELECT COUNT(DISTINCT account_id) p, COUNT(*) n, COALESCE(SUM(ranked), 0) r FROM ach_unlocks')->fetch();
    return ['players' => (int)$u['p'], 'unlocks' => (int)$u['n'], 'ranked' => (int)$u['r'],
        'mastered' => (int)$db->query('SELECT COUNT(*) FROM ach_mastered')->fetchColumn(),
        'beaten' => (int)$db->query('SELECT COUNT(*) FROM ach_beaten')->fetchColumn(),
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

// ---- rarity and score ------------------------------------------------------------------------------

// Who has each achievement, out of everyone who played the game in RecompLauncher (a session).
// -> [[id => ['n' => unlocks, 'rate' => 0..1]], players]
function rs_ach_rarity(string $game): array {
    rs_ach_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT COUNT(DISTINCT account_id) FROM (SELECT account_id FROM ach_sessions WHERE game = ? UNION SELECT account_id FROM ach_unlocks WHERE game = ?) x');
    $st->execute([$game, $game]);
    $players = max(0, (int)$st->fetchColumn());
    $st = $db->prepare('SELECT ach, COUNT(*) n FROM ach_unlocks WHERE game = ? GROUP BY ach');
    $st->execute([$game]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['ach']] = ['n' => (int)$r['n'], 'rate' => $players ? round((int)$r['n'] / $players, 4) : 0];
    return [$out, $players];
}

// Score: Ranked points weighted by how rare each achievement is (RetroAchievements' RetroPoints idea):
// points x the square root of (players of the game / players who have it), from 1x up to 5x.
const RS_ACH_SCORE_SQL = 'ROUND(SUM(u.points * u.ranked * LEAST(5, GREATEST(1, SQRT(gp.n / GREATEST(1, ac.n))))))';
const RS_ACH_SCORE_JOIN = ' LEFT JOIN (SELECT game, COUNT(DISTINCT account_id) n FROM (SELECT game, account_id FROM ach_sessions UNION SELECT game, account_id FROM ach_unlocks) y GROUP BY game) gp ON gp.game = u.game
    LEFT JOIN (SELECT game, ach, COUNT(*) n FROM ach_unlocks WHERE ranked = 1 GROUP BY game, ach) ac ON ac.game = u.game AND ac.ach = u.ach ';

// Leaderboard: by Ranked points (or by score), then Mastered games, then who got there first.
function rs_ach_board(string $game = '', int $n = 50, string $by = 'points'): array {
    rs_ach_tables();
    $g = $game !== '';
    $order = $by === 'score' ? 'score DESC, pts DESC' : 'pts DESC, score DESC';
    $st = rs_db()->prepare('SELECT u.account_id, a.username, a.avatar, SUM(u.points * u.ranked) pts, ' . RS_ACH_SCORE_SQL . ' score, SUM(u.ranked) ranked, COUNT(*) total, MAX(u.at) last,
            (SELECT COUNT(*) FROM ach_mastered m WHERE m.account_id = u.account_id AND m.ranked = 1' . ($g ? ' AND m.game = ?' : '') . ') mastered,
            (SELECT COUNT(*) FROM ach_beaten b WHERE b.account_id = u.account_id AND b.ranked = 1' . ($g ? ' AND b.game = ?' : '') . ') beaten
        FROM ach_unlocks u JOIN accounts a ON a.id = u.account_id AND a.banned = 0 AND a.username IS NOT NULL' . RS_ACH_SCORE_JOIN . ($g ? 'WHERE u.game = ? ' : '') . '
        GROUP BY u.account_id, a.username, a.avatar
        ORDER BY ' . $order . ', mastered DESC, last ASC LIMIT ' . max(1, min(200, $n)));
    $st->execute($g ? [$game, $game, $game] : []);
    $out = []; $rank = 0;
    foreach ($st->fetchAll() as $r) $out[] = ['rank' => ++$rank, 'username' => $r['username'], 'avatar' => rs_acct_avatar_url($r),
        'points' => (int)$r['pts'], 'score' => (int)$r['score'], 'ranked' => (int)$r['ranked'], 'unlocks' => (int)$r['total'],
        'mastered' => (int)$r['mastered'], 'beaten' => (int)$r['beaten']];
    return $out;
}

// One player's Ranked points and score (overall), and where that puts them.
function rs_ach_totals(int $acct): array {
    $db = rs_db();
    $st = $db->prepare('SELECT COALESCE(SUM(u.points * u.ranked), 0) pts, COALESCE(' . RS_ACH_SCORE_SQL . ', 0) score FROM ach_unlocks u' . RS_ACH_SCORE_JOIN . 'WHERE u.account_id = ?');
    $st->execute([$acct]);
    $t = $st->fetch();
    $pts = (int)$t['pts']; $score = (int)$t['score'];
    $rank = null; $srank = null;
    if ($pts > 0) {
        $rank = (int)$db->query('SELECT COUNT(*) FROM (SELECT account_id FROM ach_unlocks GROUP BY account_id HAVING SUM(points * ranked) > ' . $pts . ') x')->fetchColumn() + 1;
        $srank = (int)$db->query('SELECT COUNT(*) FROM (SELECT u.account_id FROM ach_unlocks u' . RS_ACH_SCORE_JOIN . 'GROUP BY u.account_id HAVING ' . RS_ACH_SCORE_SQL . ' > ' . $score . ') x')->fetchColumn() + 1;
    }
    return [$pts, $score, $rank, $srank];
}

// A player's whole picture: points, score, rank, every game they've played, Beaten and Mastered games,
// latest unlocks, events won, what they're playing now.
function rs_ach_profile(int $acct): array {
    rs_ach_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT game, COUNT(*) n, SUM(ranked) r, SUM(points * ranked) pts, MAX(at) last FROM ach_unlocks WHERE account_id = ? GROUP BY game ORDER BY last DESC');
    $st->execute([$acct]);
    $rows = $st->fetchAll();
    $awards = ['mastered' => [], 'beaten' => []];
    foreach (['mastered' => 'ach_mastered', 'beaten' => 'ach_beaten'] as $k => $tbl) {
        $ms = $db->prepare("SELECT game, at, ranked FROM $tbl WHERE account_id = ? ORDER BY at DESC");
        $ms->execute([$acct]);
        foreach ($ms->fetchAll() as $m) $awards[$k][$m['game']] = $m;
    }
    $lists = rs_ach_lists();
    $games = []; $unlocks = 0; $ranked = 0;
    foreach ($rows as $r) {
        $g = $r['game'];
        [$n, $pts] = isset($lists[$g]) ? rs_ach_list_totals($lists[$g]['list'], $g) : [0, 0];
        $unlocks += (int)$r['n']; $ranked += (int)$r['r'];
        $games[] = rs_ach_app_card($g) + ['unlocked' => (int)$r['n'], 'ranked' => (int)$r['r'], 'achievements' => $n, 'points' => (int)$r['pts'], 'points_total' => $pts,
            'mastered' => isset($awards['mastered'][$g]) ? ((int)$awards['mastered'][$g]['ranked'] ? 'ranked' : 'casual') : null,
            'beaten' => isset($awards['beaten'][$g]) ? ((int)$awards['beaten'][$g]['ranked'] ? 'ranked' : 'casual') : null,
            'last' => (int)strtotime($r['last'] . ' UTC')];
    }
    $award = fn(string $k) => array_values(array_map(fn($g, $m) => rs_ach_app_card($g) + ['at' => (int)strtotime($m['at'] . ' UTC'), 'ranked' => (bool)$m['ranked']],
        array_keys($awards[$k]), $awards[$k]));
    $rs = $db->prepare('SELECT game, ach, points, at, ranked FROM ach_unlocks WHERE account_id = ? ORDER BY at DESC LIMIT 12');
    $rs->execute([$acct]);
    $recent = []; $rarities = [];
    foreach ($rs->fetchAll() as $r) {
        [$title, $desc] = rs_ach_title($r['game'], (int)$r['ach']);
        [$rare] = $rarities[$r['game']] ??= rs_ach_rarity($r['game']);
        $recent[] = ['game' => $r['game'], 'name' => rs_ach_app_card($r['game'])['name'], 'id' => (int)$r['ach'], 'title' => $title, 'description' => $desc,
            'points' => (int)$r['points'], 'at' => (int)strtotime($r['at'] . ' UTC'), 'ranked' => (bool)$r['ranked'], 'rarity' => $rare[(int)$r['ach']]['rate'] ?? null];
    }
    [$points, $score, $rank, $srank] = rs_ach_totals($acct);
    $players = (int)$db->query('SELECT COUNT(DISTINCT account_id) FROM ach_unlocks')->fetchColumn();
    return ['points' => $points, 'score' => $score, 'rank' => $rank, 'score_rank' => $srank, 'players' => $players, 'unlocks' => $unlocks, 'ranked' => $ranked,
        'mastered_count' => count($awards['mastered']), 'beaten_count' => count($awards['beaten']), 'games' => $games,
        'mastered' => $award('mastered'), 'beaten' => $award('beaten'), 'recent' => $recent,
        'events' => rs_ach_events_won($acct), 'playing' => rs_ach_presence_get($acct)];
}

// ---- broken achievement reports (RetroAchievements' tickets) ----------------------------------------

// -> [ticket id, null] or [0, the reason it didn't go in]
function rs_ach_report(array $acct, string $game, int $id, string $kind, string $note, string $version): array {
    rs_ach_tables();
    if (!isset(RS_ACH_TICKET_KINDS[$kind])) return [0, 'Pick what went wrong.'];
    $l = rs_ach_list($game);
    if (!$l) return [0, 'This game has no achievements.'];
    $ids = array_map(fn($a) => (int)($a['id'] ?? 0), (array)($l['achievements'] ?? []));
    if ($ids && !in_array($id, $ids, true)) return [0, 'That achievement isn\'t on the list.'];
    $db = rs_db();
    $st = $db->prepare('SELECT id FROM ach_tickets WHERE account_id = ? AND game = ? AND ach = ? AND status = \'open\'');
    $st->execute([(int)$acct['id'], $game, $id]);
    if ($have = $st->fetchColumn()) return [(int)$have, null];
    $db->prepare('INSERT INTO ach_tickets (account_id, game, ach, kind, note, version, at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([(int)$acct['id'], $game, $id, $kind, mb_substr(trim(preg_replace('/\s+/u', ' ', $note)), 0, 500), mb_substr($version, 0, 40)]);
    return [(int)$db->lastInsertId(), null];
}
function rs_ach_tickets(string $status = 'open', string $game = '', int $n = 300): array {
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT t.*, a.username FROM ach_tickets t LEFT JOIN accounts a ON a.id = t.account_id WHERE t.status = ?' . ($game !== '' ? ' AND t.game = ?' : '') . ' ORDER BY t.game, t.ach, t.at LIMIT ' . max(1, min(1000, $n)));
    $st->execute($game !== '' ? [$status, $game] : [$status]);
    return $st->fetchAll();
}
function rs_ach_ticket_counts(): array {
    rs_ach_tables();
    $out = [];
    foreach (rs_db()->query('SELECT game, ach, COUNT(*) n FROM ach_tickets WHERE status = \'open\' GROUP BY game, ach')->fetchAll() as $r) $out[$r['game']][(int)$r['ach']] = (int)$r['n'];
    return $out;
}
function rs_ach_ticket_close(array $ids, string $status, string $answer, string $who): int {
    if (!in_array($status, ['fixed', 'notabug', 'open'], true) || !$ids) return 0;
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $st = rs_db()->prepare('UPDATE ach_tickets SET status = ?, decided = ' . ($status === 'open' ? 'NULL' : 'UTC_TIMESTAMP()') . ', who = ?, answer = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_merge([$status, mb_substr($who, 0, 80), mb_substr(trim($answer), 0, 300)], $ids));
    return $st->rowCount();
}

// ---- events (RetroAchievements' monthly events) ---------------------------------------------------

function rs_ach_event_save(array $in): ?string {
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 80);
    $kind = (string)($in['kind'] ?? '');
    $starts = strtotime((string)($in['starts'] ?? '') . ' UTC'); $ends = strtotime((string)($in['ends'] ?? '') . ' 23:59:59 UTC');
    if ($title === '') return 'Give the event a title.';
    if (!isset(RS_ACH_EVENT_KINDS[$kind])) return 'Pick what players have to do.';
    if (!$starts || !$ends || $ends <= $starts) return 'Pick a start and an end date (the end after the start).';
    $games = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string)($in['games'] ?? '')) ?: []), 'rs_ach_game_ok'));
    $console = in_array((string)($in['console'] ?? ''), RS_ACH_CONSOLES, true) ? (string)$in['console'] : '';
    rs_ach_tables();
    rs_db()->prepare('INSERT INTO ach_events (title, description, kind, games, console, goal, starts, ends, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$title, mb_substr(trim((string)($in['description'] ?? '')), 0, 300), $kind, implode(',', $games), $console, max(1, min(10000, (int)($in['goal'] ?? 1))),
            gmdate('Y-m-d H:i:s', $starts), gmdate('Y-m-d H:i:s', $ends)]);
    return null;
}
function rs_ach_event_delete(int $id): void {
    rs_ach_tables();
    rs_db()->prepare('DELETE FROM ach_events WHERE id = ?')->execute([$id]);
    rs_db()->prepare('DELETE FROM ach_event_done WHERE event_id = ?')->execute([$id]);
}
function rs_ach_event_row(array $e): array {
    return ['id' => (int)$e['id'], 'title' => $e['title'], 'description' => $e['description'], 'kind' => $e['kind'],
        'games' => array_values(array_filter(explode(',', (string)$e['games']))), 'console' => $e['console'], 'goal' => (int)$e['goal'],
        'starts' => (int)strtotime($e['starts'] . ' UTC'), 'ends' => (int)strtotime($e['ends'] . ' UTC')];
}
// Every event, newest first, with how many players finished each.
function rs_ach_events(bool $withDone = true): array {
    rs_ach_tables();
    $done = [];
    if ($withDone) foreach (rs_db()->query('SELECT event_id, COUNT(*) n FROM ach_event_done GROUP BY event_id')->fetchAll() as $r) $done[(int)$r['event_id']] = (int)$r['n'];
    return array_map(fn($e) => rs_ach_event_row($e) + ['finished' => $done[(int)$e['id']] ?? 0],
        rs_db()->query('SELECT * FROM ach_events ORDER BY starts DESC, id DESC LIMIT 100')->fetchAll());
}
// How far one player is in one event (Ranked only): -> [have, goal]
function rs_ach_event_progress(int $acct, array $e): array {
    $db = rs_db();
    $games = $e['games'];
    if ($e['console'] !== '') {
        $byConsole = array_keys(array_filter(rs_ach_lists(), fn($l) => ($l['list']['console'] ?? '') === $e['console']));
        $games = $games ? array_values(array_intersect($games, $byConsole)) : $byConsole;
        if (!$games) return [0, $e['goal']];
    }
    $in = $games ? ' AND game IN (' . implode(',', array_map(fn($g) => $db->quote($g), $games)) . ')' : '';
    $from = gmdate('Y-m-d H:i:s', $e['starts']); $to = gmdate('Y-m-d H:i:s', $e['ends']);
    $sql = ['unlocks' => 'SELECT COUNT(*) FROM ach_unlocks WHERE account_id = ? AND ranked = 1 AND at BETWEEN ? AND ?',
        'beat' => 'SELECT COUNT(*) FROM ach_beaten WHERE account_id = ? AND ranked = 1 AND at BETWEEN ? AND ?',
        'master' => 'SELECT COUNT(*) FROM ach_mastered WHERE account_id = ? AND ranked = 1 AND at BETWEEN ? AND ?',
        'play' => 'SELECT COUNT(DISTINCT game) FROM ach_sessions WHERE account_id = ? AND started BETWEEN ? AND ?'][$e['kind']] ?? null;
    if (!$sql) return [0, $e['goal']];
    $st = $db->prepare($sql . $in);
    $st->execute([$acct, $from, $to]);
    return [(int)$st->fetchColumn(), $e['goal']];
}
// After an unlock: events this player just finished. -> [{id, title}]
function rs_ach_events_check(int $acct): array {
    rs_ach_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT e.* FROM ach_events e LEFT JOIN ach_event_done d ON d.event_id = e.id AND d.account_id = ? WHERE d.event_id IS NULL AND UTC_TIMESTAMP() BETWEEN e.starts AND e.ends');
    $st->execute([$acct]);
    $won = [];
    foreach ($st->fetchAll() as $row) {
        $e = rs_ach_event_row($row);
        [$have, $goal] = rs_ach_event_progress($acct, $e);
        if ($have < $goal) continue;
        $db->prepare('INSERT IGNORE INTO ach_event_done (event_id, account_id, at) VALUES (?, ?, UTC_TIMESTAMP())')->execute([$e['id'], $acct]);
        $won[] = ['id' => $e['id'], 'title' => $e['title']];
    }
    return $won;
}
// Running and past events for one player: with progress on running ones and the badge on finished ones.
function rs_ach_events_won(int $acct): array {
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT event_id, at FROM ach_event_done WHERE account_id = ?');
    $st->execute([$acct]);
    $done = [];
    foreach ($st->fetchAll() as $r) $done[(int)$r['event_id']] = (int)strtotime($r['at'] . ' UTC');
    $out = [];
    foreach (rs_ach_events(false) as $e) {
        $running = time() >= $e['starts'] && time() <= $e['ends'];
        if (!isset($done[$e['id']]) && !$running) continue;
        [$have, $goal] = isset($done[$e['id']]) ? [$e['goal'], $e['goal']] : rs_ach_event_progress($acct, $e);
        $out[] = $e + ['won' => $done[$e['id']] ?? null, 'have' => min($have, $goal), 'running' => $running];
    }
    return $out;
}

// ---- rich presence ------------------------------------------------------------------------------

function rs_ach_presence_set(int $acct, string $game, string $text): void {
    rs_ach_tables();
    $text = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text)), 0, 200);
    rs_db()->prepare('INSERT INTO ach_presence (account_id, game, text, at) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE game = VALUES(game), text = VALUES(text), at = VALUES(at)')
        ->execute([$acct, $game, $text]);
}
// What they're playing now: set in the last 10 minutes (a session's check-ins keep it fresh).
function rs_ach_presence_get(int $acct): ?array {
    rs_ach_tables();
    $st = rs_db()->prepare('SELECT game, text, at FROM ach_presence WHERE account_id = ? AND at > UTC_TIMESTAMP() - INTERVAL 10 MINUTE');
    $st->execute([$acct]);
    $r = $st->fetch();
    return $r ? ['game' => $r['game'], 'name' => rs_ach_app_card($r['game'])['name'], 'text' => $r['text'], 'at' => (int)strtotime($r['at'] . ' UTC')] : null;
}

// ---- game leaderboards -----------------------------------------------------------------------------

function rs_ach_list_board(?array $list, int $board): ?array {
    foreach ((array)($list['leaderboards'] ?? []) as $b) if (is_array($b) && (int)($b['id'] ?? 0) === $board) return $b;
    return null;
}
// An entry from a session. Kept when it's the player's best; Ranked by the same live-session rule as
// unlocks (and Casual entries never replace a Ranked best). -> {best, ranked, rank, new_best} or null
function rs_ach_lb_submit(array $acct, array $s, int $board, int $value, int $at): ?array {
    rs_ach_tables();
    $game = (string)$s['game'];
    $b = rs_ach_list_board(rs_ach_list($game), $board);
    if (!$b) return null;
    $low = !empty($b['lower_is_better']) || in_array($b['format'] ?? '', ['time', 'frames', 'millisecs', 'seconds', 'minutes'], true) && !isset($b['lower_is_better']);
    $now = time();
    if ($at > $now + 300 || $at < 1) $at = $now;
    $times = $s['tl'] ?: [(int)$s['s'], (int)$s['l']];
    $live = false;
    if ($at >= (int)$s['s'] - 60 && (!$s['e'] || $at <= (int)$s['e'] + 60)) foreach ($times as $t) if ($at >= $t - 60 && $at <= $t + RS_ACH_LIVE_GAP) { $live = true; break; }
    $ranked = $live && (string)$s['casual'] === '' ? 1 : 0;
    $db = rs_db();
    $st = $db->prepare('SELECT value, ranked FROM ach_lb WHERE game = ? AND board = ? AND account_id = ?');
    $st->execute([$game, $board, (int)$acct['id']]);
    $have = $st->fetch();
    $better = !$have || ($ranked > (int)$have['ranked']) || ($ranked === (int)$have['ranked'] && ($low ? $value < (int)$have['value'] : $value > (int)$have['value']));
    if ($have && (int)$have['ranked'] === 1 && $ranked === 0) $better = false;
    if ($better) $db->prepare('INSERT INTO ach_lb (game, board, account_id, value, at, session, ranked) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value), at = VALUES(at), session = VALUES(session), ranked = VALUES(ranked)')
        ->execute([$game, $board, (int)$acct['id'], $value, gmdate('Y-m-d H:i:s', $at), $s['id'], $ranked]);
    $best = $better ? $value : (int)$have['value'];
    $bestRanked = $better ? $ranked : (int)$have['ranked'];
    $rank = null;
    if ($bestRanked) {
        $st = $db->prepare('SELECT COUNT(*) FROM ach_lb WHERE game = ? AND board = ? AND ranked = 1 AND value ' . ($low ? '<' : '>') . ' ?');
        $st->execute([$game, $board, $best]);
        $rank = (int)$st->fetchColumn() + 1;
    }
    return ['best' => $best, 'ranked' => (bool)$bestRanked, 'rank' => $rank, 'new_best' => $better];
}
// A game's leaderboards with their top entries (Ranked only).
function rs_ach_lb_top(string $game, int $n = 10): array {
    $l = rs_ach_list($game);
    $out = [];
    foreach ((array)($l['leaderboards'] ?? []) as $b) {
        if (!is_array($b) || !isset($b['id'])) continue;
        $low = !empty($b['lower_is_better']) || in_array($b['format'] ?? '', ['time', 'frames', 'millisecs', 'seconds', 'minutes'], true) && !isset($b['lower_is_better']);
        $st = rs_db()->prepare('SELECT l.value, l.at, a.username, a.avatar FROM ach_lb l JOIN accounts a ON a.id = l.account_id AND a.banned = 0 AND a.username IS NOT NULL
            WHERE l.game = ? AND l.board = ? AND l.ranked = 1 ORDER BY l.value ' . ($low ? 'ASC' : 'DESC') . ', l.at ASC LIMIT ' . max(1, min(100, $n)));
        $st->execute([$game, (int)$b['id']]);
        $rank = 0;
        $out[] = ['id' => (int)$b['id'], 'title' => (string)($b['title'] ?? ''), 'description' => (string)($b['description'] ?? ''), 'format' => (string)($b['format'] ?? 'value'),
            'lower_is_better' => $low, 'entries' => array_map(function ($r) use (&$rank) { return ['rank' => ++$rank, 'username' => $r['username'], 'avatar' => rs_acct_avatar_url($r),
                'value' => (int)$r['value'], 'at' => (int)strtotime($r['at'] . ' UTC')]; }, $st->fetchAll())];
    }
    return $out;
}
function rs_ach_lb_mine(int $acct, string $game): array {
    $st = rs_db()->prepare('SELECT board, value, ranked, at FROM ach_lb WHERE account_id = ? AND game = ?');
    $st->execute([$acct, $game]);
    return array_map(fn($r) => ['id' => (int)$r['board'], 'value' => (int)$r['value'], 'ranked' => (bool)$r['ranked'], 'at' => (int)strtotime($r['at'] . ' UTC')], $st->fetchAll());
}

// ---- public pages: a game, a player -----------------------------------------------------------------

// Everything recomplauncher.com/achievements/<game> shows. null when the game has no live list.
function rs_ach_public_game(string $game): ?array {
    $l = rs_ach_list($game);
    if (!$l || empty($l['_live'])) return null;
    [$rare, $players] = rs_ach_rarity($game);
    $items = [];
    foreach ((array)($l['achievements'] ?? []) as $a) {
        if (!is_array($a) || !isset($a['id'])) continue;
        $id = (int)$a['id'];
        $items[] = ['id' => $id, 'title' => (string)($a['title'] ?? ''), 'description' => (string)($a['description'] ?? ''), 'points' => (int)($a['points'] ?? 0),
            'type' => (string)($a['type'] ?? ''), 'bonus' => !empty($a['bonus']), 'unlocks' => $rare[$id]['n'] ?? 0, 'rarity' => $rare[$id]['rate'] ?? 0];
    }
    $db = rs_db();
    $first = fn(string $tbl) => array_map(fn($r) => ['username' => $r['username'], 'avatar' => rs_acct_avatar_url($r), 'at' => (int)strtotime($r['at'] . ' UTC'), 'ranked' => (bool)$r['ranked']],
        (function () use ($db, $tbl, $game) { $st = $db->prepare("SELECT m.at, m.ranked, a.username, a.avatar FROM $tbl m JOIN accounts a ON a.id = m.account_id AND a.banned = 0 AND a.username IS NOT NULL WHERE m.game = ? ORDER BY m.ranked DESC, m.at ASC LIMIT 10"); $st->execute([$game]); return $st->fetchAll(); })());
    $st = $db->prepare('SELECT u.ach, u.at, u.ranked, a.username, a.avatar FROM ach_unlocks u JOIN accounts a ON a.id = u.account_id AND a.banned = 0 AND a.username IS NOT NULL WHERE u.game = ? ORDER BY u.at DESC LIMIT 12');
    $st->execute([$game]);
    $recent = array_map(function ($r) use ($game) { [$t] = rs_ach_title($game, (int)$r['ach']); return ['id' => (int)$r['ach'], 'title' => $t, 'username' => $r['username'], 'avatar' => rs_acct_avatar_url($r), 'at' => (int)strtotime($r['at'] . ' UTC'), 'ranked' => (bool)$r['ranked']]; }, $st->fetchAll());
    [$n, $pts] = rs_ach_list_totals($l, $game);
    return ['game' => rs_ach_app_card($game) + ['console' => (string)($l['console'] ?? ''), 'source' => (string)($l['source'] ?? '')],
        'achievements' => $items, 'count' => $n, 'points' => $pts, 'players' => $players, 'can_beat' => (bool)rs_ach_beat_ids($l)[1],
        'mastered' => $first('ach_mastered'), 'beaten' => $first('ach_beaten'), 'recent' => $recent,
        'board' => rs_ach_board($game, 20), 'leaderboards' => rs_ach_lb_top($game, 10), 'rich_presence' => isset($l['rich_presence'])];
}

// A player's public profile, by username. null when they have nothing to show.
function rs_ach_public_user(string $name): ?array {
    if (!preg_match('/^[A-Za-z0-9_.-]{2,40}$/', $name)) return null;
    $st = rs_db()->prepare('SELECT id, username, avatar, created, banned FROM accounts WHERE username = ?');
    $st->execute([$name]);
    $a = $st->fetch();
    if (!$a || (int)$a['banned']) return null;
    $p = rs_ach_profile((int)$a['id']);
    if (!$p['unlocks'] && !rs_ach_is_tester($a)) return null;
    return ['user' => ['username' => $a['username'], 'avatar' => rs_acct_avatar_url($a), 'since' => substr((string)$a['created'], 0, 10), 'tester' => rs_ach_is_tester($a)]] + $p;
}
