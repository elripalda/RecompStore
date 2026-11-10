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
function rs_ach_is_tester(array $acct): bool {
    return in_array(strtolower((string)($acct['username'] ?? '')), rs_ach_testers(), true);
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

function rs_ach_session_start(int $acct, string $game, string $version, string $casual): string {
    rs_ach_tables();
    $id = bin2hex(random_bytes(16));
    rs_db()->prepare('INSERT INTO ach_sessions (id, account_id, game, version, started, last_seen, casual) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)')
        ->execute([$id, $acct, $game, mb_substr($version, 0, 40), mb_substr($casual, 0, 40)]);
    return $id;
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
    return $r;
}

// A check-in: the session is still running. casual / save_loaded come from the recomp's messages.
function rs_ach_checkin(array $s, string $casual, bool $saveLoaded): void {
    $sql = 'UPDATE ach_sessions SET last_seen = UTC_TIMESTAMP(), checkins = checkins + 1';
    $args = [];
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
    $loadAt = (int)($s['sl'] ?? 0);

    // Native unlocks clustered right after the session start or a save load = a save replaying them.
    $nativeTimes = [];
    foreach ($unlocks as $u) if (($u['source'] ?? '') === 'native' && empty($u['earlier'])) $nativeTimes[] = (int)($u['at'] ?? $now);
    $burstFrom = function (int $t) use ($nativeTimes): bool {
        return count(array_filter($nativeTimes, fn($x) => $x >= $t && $x <= $t + RS_ACH_BURST_SECS)) >= RS_ACH_BURST_MIN;
    };
    $burstStart = $burstFrom($started);
    $burstLoad = $loadAt > 0 && $burstFrom($loadAt);

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
        elseif (!empty($u['casual'])) $why = mb_substr((string)($u['reason'] ?? 'casual'), 0, 40) ?: 'casual';
        elseif ($at < $started - 60 || $at > $lastSeen + RS_ACH_LIVE_GAP) $why = 'no_session';
        elseif ($src === 'native' && $burstStart && $at <= $started + RS_ACH_BURST_SECS) $why = 'save_replay';
        elseif ($src === 'native' && $burstLoad && $at >= $loadAt && $at <= $loadAt + RS_ACH_BURST_SECS) $why = 'save_replay';
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
    return [$results, rs_ach_check_mastered((int)$acct['id'], $game, $list)];
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
    $total = ($list['source'] ?? '') === 'native' ? (int)($list['total'] ?? 0) : count((array)($list['achievements'] ?? []));
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
