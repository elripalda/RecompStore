<?php
// RecompLauncher (3.1g): anonymous usage from the launcher. Each install is a random ID (kept
// here only as a keyed hash), with what version it runs and what it's playing right now; play
// sessions per game; and which days each install was open. No names, emails or addresses.
declare(strict_types=1);

const RS_LS_LIVE_MIN = 6;      // seen in the last 6 minutes = open now
const RS_LS_NEXT = 600;        // the heartbeat the launcher is asked for, in seconds

function rs_ls_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS lstat_installs (
        ih CHAR(64) PRIMARY KEY,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        closed TINYINT NOT NULL DEFAULT 0,
        version VARCHAR(40) NOT NULL DEFAULT \'\',
        os VARCHAR(16) NOT NULL DEFAULT \'\',
        os_version VARCHAR(40) NOT NULL DEFAULT \'\',
        mode VARCHAR(8) NOT NULL DEFAULT \'\',
        signed_in TINYINT NOT NULL DEFAULT 0,
        game_id VARCHAR(64) NULL,
        game_name VARCHAR(120) NULL,
        game_since DATETIME NULL,
        KEY last_seen (last_seen),
        KEY version (version)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS lstat_days (
        day DATE NOT NULL,
        ih CHAR(64) NOT NULL,
        PRIMARY KEY (day, ih)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS lstat_sessions (
        sh CHAR(64) PRIMARY KEY,
        ih CHAR(64) NOT NULL,
        game_id VARCHAR(64) NOT NULL,
        game_name VARCHAR(120) NOT NULL DEFAULT \'\',
        game_version VARCHAR(40) NOT NULL DEFAULT \'\',
        started DATETIME NOT NULL,
        last DATETIME NOT NULL,
        ended DATETIME NULL,
        seconds INT NOT NULL DEFAULT 0,
        KEY started (started),
        KEY game (game_id, started)
    ) CHARACTER SET utf8mb4');
    // (3.7) What's left of installs after their rows are cleared (see rs_ls_purge): counts only.
    $db->exec('CREATE TABLE IF NOT EXISTS lstat_totals (k VARCHAR(32) PRIMARY KEY, n BIGINT NOT NULL DEFAULT 0) CHARACTER SET utf8mb4');
    $done = true;
}

// IDs are stored keyed-hashed, so the table never holds what the launcher sent.
function rs_ls_hash(string $id): string { return hash_hmac('sha256', 'ls|' . strtolower($id), rs_mail_key()); }
function rs_ls_str($v, int $max): string { return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$v) ?? ''), 0, $max); }

// One ping from a launcher. -> seconds until the next heartbeat.
// (3.7) Usage data is kept 90 days (the privacy page says so). Older rows go; installs are
// counted by system into lstat_totals first, so the all-time numbers stay.
const RS_LS_KEEP_DAYS = 90;
function rs_ls_purge(): void {
    rs_ls_tables();
    $db = rs_db();
    $cut = $db->quote(gmdate('Y-m-d H:i:s', time() - RS_LS_KEEP_DAYS * 86400));
    $db->exec("INSERT INTO lstat_totals (k, n) SELECT CONCAT('installs:', " . RS_LS_OSK . ") kk, COUNT(*) c FROM lstat_installs i WHERE i.last_seen < $cut GROUP BY kk
        ON DUPLICATE KEY UPDATE n = n + VALUES(n)");
    $db->exec("DELETE FROM lstat_installs WHERE last_seen < $cut");
    $db->exec("DELETE FROM lstat_days WHERE day < DATE($cut)");
    $db->exec("DELETE FROM lstat_sessions WHERE started < $cut");
}
function rs_ls_ping(array $in): int {
    rs_ls_tables();
    $db = rs_db();
    if (random_int(1, 500) === 1) { try { rs_ls_purge(); } catch (Throwable $e) { error_log('lstat purge: ' . $e->getMessage()); } }
    $install = (string)($in['install'] ?? '');
    if (!preg_match('/^[0-9A-Fa-f-]{16,64}$/', $install)) throw new InvalidArgumentException('install');
    $event = (string)($in['event'] ?? 'heartbeat');
    if (!in_array($event, ['open', 'heartbeat', 'game_start', 'game_stop', 'close'], true)) throw new InvalidArgumentException('event');
    $ih = rs_ls_hash($install);
    $version = rs_ls_str($in['version'] ?? '', 40);
    $os = strtolower(rs_ls_str($in['os'] ?? '', 16));
    $osv = rs_ls_str($in['os_version'] ?? '', 40);
    $mode = in_array(($in['mode'] ?? ''), ['desk', 'tv'], true) ? (string)$in['mode'] : '';
    $signed = !empty($in['signed_in']) ? 1 : 0;
    $g = is_array($in['game'] ?? null) ? $in['game'] : [];
    $gid = strtolower(rs_ls_str($g['id'] ?? '', 64));
    if ($gid !== '' && !preg_match('/^[a-z0-9._-]+$/', $gid)) $gid = '';
    $gname = rs_ls_str($g['name'] ?? '', 120);
    $gver = rs_ls_str($g['version'] ?? '', 40);
    $session = (string)($in['session'] ?? '');
    $sh = preg_match('/^[0-9A-Fa-f-]{16,64}$/', $session) ? rs_ls_hash('s|' . $install . '|' . $session) : '';
    $next = max(60, (int)rs_setting('lstat_next', (string)RS_LS_NEXT));

    // A heartbeat that comes too soon after the last one is only counted, not written.
    if ($event === 'heartbeat') {
        $st = $db->prepare('SELECT TIMESTAMPDIFF(SECOND, last_seen, UTC_TIMESTAMP()) FROM lstat_installs WHERE ih = ?');
        $st->execute([$ih]);
        $ago = $st->fetchColumn();
        if ($ago !== false && (int)$ago < 30) return $next;
    }

    $playing = in_array($event, ['heartbeat', 'game_start'], true) && $gid !== '';
    $db->prepare('INSERT INTO lstat_installs (ih, first_seen, last_seen, closed, version, os, os_version, mode, signed_in, game_id, game_name, game_since)
        VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, IF(? IS NULL, NULL, UTC_TIMESTAMP()))
        ON DUPLICATE KEY UPDATE last_seen = UTC_TIMESTAMP(), closed = VALUES(closed),
            version = IF(VALUES(version) <> \'\', VALUES(version), version), os = IF(VALUES(os) <> \'\', VALUES(os), os),
            os_version = IF(VALUES(os_version) <> \'\', VALUES(os_version), os_version), mode = IF(VALUES(mode) <> \'\', VALUES(mode), mode),
            signed_in = VALUES(signed_in),
            game_since = IF(VALUES(game_id) IS NULL, NULL, IF(game_id <=> VALUES(game_id), game_since, UTC_TIMESTAMP())),
            game_id = VALUES(game_id), game_name = VALUES(game_name)')
        ->execute([$ih, $event === 'close' ? 1 : 0, $version, $os, $osv, $mode, $signed,
                   $playing ? $gid : null, $playing ? $gname : null, $playing ? $gid : null]);
    $db->prepare('INSERT IGNORE INTO lstat_days (day, ih) VALUES (UTC_DATE(), ?)')->execute([$ih]);

    // Play sessions: started, kept alive by heartbeats, finished with the time the launcher measured.
    if ($sh !== '' && $gid !== '') {
        if ($event === 'game_stop') {
            $secs = max(0, min(86400, (int)($in['seconds'] ?? 0)));
            $db->prepare('INSERT INTO lstat_sessions (sh, ih, game_id, game_name, game_version, started, last, ended, seconds)
                VALUES (?, ?, ?, ?, ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)
                ON DUPLICATE KEY UPDATE last = UTC_TIMESTAMP(), ended = UTC_TIMESTAMP(), seconds = GREATEST(seconds, VALUES(seconds))')
                ->execute([$sh, $ih, $gid, $gname, $gver, $secs, $secs]);
        } elseif ($event === 'game_start' || $event === 'heartbeat') {
            $db->prepare('INSERT INTO lstat_sessions (sh, ih, game_id, game_name, game_version, started, last, seconds)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)
                ON DUPLICATE KEY UPDATE last = UTC_TIMESTAMP(), seconds = IF(ended IS NULL, GREATEST(seconds, TIMESTAMPDIFF(SECOND, started, UTC_TIMESTAMP())), seconds)')
                ->execute([$sh, $ih, $gid, $gname, $gver]);
        }
    }
    return $next;
}

// (3.7) Which system a launcher runs on, from what it reports (windows, linux, macos...).
const RS_LS_OSK = "CASE WHEN i.os LIKE 'win%' THEN 'windows' WHEN i.os LIKE '%linux%' OR i.os LIKE '%steam%' THEN 'linux' WHEN i.os LIKE 'mac%' OR i.os LIKE 'darwin%' THEN 'macos' ELSE 'other' END";
// Launchers by system: open now, active today, this week, and ever.
function rs_ls_by_os(): array {
    rs_ls_tables();
    $db = rs_db();
    $k = RS_LS_OSK;
    $out = [];
    foreach (['windows', 'linux', 'macos', 'other'] as $o) $out[$o] = ['open' => 0, 'today' => 0, 'week' => 0, 'ever' => 0];
    $put = function (string $col, string $sql) use ($db, &$out) { foreach ($db->query($sql) as $r) $out[(string)$r['k']][$col] = (int)$r['n']; };
    $put('open', "SELECT $k k, COUNT(*) n FROM lstat_installs i WHERE i.last_seen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . RS_LS_LIVE_MIN . " MINUTE) AND i.closed = 0 GROUP BY k");
    $put('today', "SELECT $k k, COUNT(*) n FROM lstat_days d JOIN lstat_installs i ON i.ih = d.ih WHERE d.day = UTC_DATE() GROUP BY k");
    $put('week', "SELECT $k k, COUNT(DISTINCT d.ih) n FROM lstat_days d JOIN lstat_installs i ON i.ih = d.ih WHERE d.day > DATE_SUB(UTC_DATE(), INTERVAL 7 DAY) GROUP BY k");
    $put('ever', "SELECT $k k, COUNT(*) n FROM lstat_installs i GROUP BY k");
    foreach ($db->query("SELECT SUBSTRING(k, 10) o, n FROM lstat_totals WHERE k LIKE 'installs:%'") as $r) if (isset($out[(string)$r['o']])) $out[(string)$r['o']]['ever'] += (int)$r['n'];
    return $out;
}

// The numbers for the admin page.
function rs_ls_live(): array {
    rs_ls_tables();
    $db = rs_db();
    $live = 'last_seen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . RS_LS_LIVE_MIN . ' MINUTE) AND closed = 0';
    $open = (int)$db->query("SELECT COUNT(*) FROM lstat_installs WHERE $live")->fetchColumn();
    $games = $db->query("SELECT game_id, MAX(game_name) name, COUNT(*) n FROM lstat_installs WHERE $live AND game_id IS NOT NULL GROUP BY game_id ORDER BY n DESC, name LIMIT 30")->fetchAll();
    $os = [];
    foreach ($db->query('SELECT ' . RS_LS_OSK . " k, COUNT(*) n FROM lstat_installs i WHERE i.last_seen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . RS_LS_LIVE_MIN . ' MINUTE) AND i.closed = 0 GROUP BY k') as $r) $os[(string)$r['k']] = (int)$r['n'];
    return ['open' => $open, 'playing' => array_sum(array_map(fn($g) => (int)$g['n'], $games)), 'games' => $games, 'os' => $os, 'at' => gmdate('c')];
}

function rs_ls_summary(): array {
    rs_ls_tables();
    $db = rs_db();
    $one = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    $days = [];
    foreach ($db->query('SELECT day, COUNT(*) n FROM lstat_days WHERE day > DATE_SUB(UTC_DATE(), INTERVAL 14 DAY) GROUP BY day ORDER BY day') as $r) $days[(string)$r['day']] = (int)$r['n'];
    $series = [];
    for ($i = 13; $i >= 0; $i--) { $d = gmdate('Y-m-d', strtotime("-$i day")); $series[$d] = $days[$d] ?? 0; }
    return [
        'today' => $one('SELECT COUNT(*) FROM lstat_days WHERE day = UTC_DATE()'),
        'week' => $one('SELECT COUNT(DISTINCT ih) FROM lstat_days WHERE day > DATE_SUB(UTC_DATE(), INTERVAL 7 DAY)'),
        'month' => $one('SELECT COUNT(DISTINCT ih) FROM lstat_days WHERE day > DATE_SUB(UTC_DATE(), INTERVAL 30 DAY)'),
        'installs' => $one('SELECT COUNT(*) FROM lstat_installs') + $one("SELECT COALESCE(SUM(n), 0) FROM lstat_totals WHERE k LIKE 'installs:%'"),
        'new_today' => $one('SELECT COUNT(*) FROM lstat_installs WHERE first_seen >= UTC_DATE()'),
        'hours_today' => round($one('SELECT COALESCE(SUM(seconds), 0) FROM lstat_sessions WHERE started >= UTC_DATE()') / 3600, 1),
        'signed_pct' => ($w = $one('SELECT COUNT(*) FROM lstat_installs WHERE last_seen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)')) ? (int)round(100 * $one('SELECT COUNT(*) FROM lstat_installs WHERE signed_in = 1 AND last_seen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)') / $w) : 0,
        'tv_pct' => $w ? (int)round(100 * $one("SELECT COUNT(*) FROM lstat_installs WHERE mode = 'tv' AND last_seen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)") / $w) : 0,
        'series' => $series,
        'top' => $db->query('SELECT game_id, MAX(game_name) name, COUNT(DISTINCT ih) players, COUNT(*) sessions, ROUND(SUM(seconds) / 3600, 1) hours
            FROM lstat_sessions WHERE started > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) GROUP BY game_id ORDER BY players DESC, hours DESC LIMIT 25')->fetchAll(),
        'versions' => $db->query('SELECT version, COUNT(*) n FROM lstat_installs WHERE last_seen > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY) GROUP BY version ORDER BY n DESC LIMIT 12')->fetchAll(),
    ];
}
