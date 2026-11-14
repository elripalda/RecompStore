<?php
// (4.10) Trending: RecompLauncher's weekly chart, like a Hot 100. Each recomp is ranked by how many
// different launchers played it that week (a session of a minute or more), then by hours played. A
// chart is dated the Friday that ends its week (midnight Phoenix time) and frozen then, so it changes
// once a week. Each entry carries last week's place, its best place and its weeks on the chart.
// Published to /trending.json for the website; play counts themselves stay private.
declare(strict_types=1);
require_once __DIR__ . '/lstats.php';
require_once __DIR__ . '/pc.php';

const RS_TREND_SIZE = 50;
const RS_TREND_MIN_SEC = 60;
const RS_TREND_TZ = 7; // hours behind UTC: the week turns at midnight Phoenix time (no daylight saving)

function rs_trend_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS pc_trend (
        week DATE NOT NULL,
        app_id VARCHAR(100) NOT NULL,
        pos SMALLINT NOT NULL,
        players INT NOT NULL,
        seconds BIGINT NOT NULL,
        PRIMARY KEY (week, app_id),
        KEY week_pos (week, pos)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// The newest chart's date: the last Friday whose midnight (Phoenix) has passed.
function rs_trend_latest_week(int $now = 0): string {
    $t = ($now ?: time()) - RS_TREND_TZ * 3600;
    $back = ((int)gmdate('N', $t) - 5 + 7) % 7; // Friday is 5
    return gmdate('Y-m-d', $t - $back * 86400);
}
// [start, end) of a chart's week in UTC, as database times.
function rs_trend_span(string $week): array {
    $end = strtotime($week . ' 00:00:00 UTC') + RS_TREND_TZ * 3600;
    return [gmdate('Y-m-d H:i:s', $end - 7 * 86400), gmdate('Y-m-d H:i:s', $end)];
}

// Builds (or builds again) one week's chart from the launchers' play sessions. -> entries ranked.
function rs_trend_build(string $week): int {
    rs_ls_tables();
    rs_trend_tables();
    [$from, $to] = rs_trend_span($week);
    $listed = [];
    foreach (rs_pc_apps(false) as $a) if (empty($a['hidden']) && empty($a['soon'])) $listed[(string)$a['id']] = true;
    $st = rs_db()->prepare('SELECT game_id, COUNT(DISTINCT ih) p, SUM(seconds) s FROM lstat_sessions
        WHERE started >= ? AND started < ? AND seconds >= ' . RS_TREND_MIN_SEC . ' GROUP BY game_id ORDER BY p DESC, s DESC LIMIT 400');
    $st->execute([$from, $to]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($listed[(string)$r['game_id']])) continue;
        $rows[] = $r;
        if (count($rows) >= RS_TREND_SIZE) break;
    }
    $db = rs_db();
    $db->beginTransaction();
    $db->prepare('DELETE FROM pc_trend WHERE week = ?')->execute([$week]);
    $ins = $db->prepare('INSERT INTO pc_trend (week, app_id, pos, players, seconds) VALUES (?, ?, ?, ?, ?)');
    foreach ($rows as $i => $r) $ins->execute([$week, (string)$r['game_id'], $i + 1, (int)$r['p'], (int)$r['s']]);
    $db->commit();
    return count($rows);
}

// The newest chart with each entry's movement. -> ['week', 'entries' => [...]] or null.
function rs_trend_chart(): ?array {
    rs_trend_tables();
    $db = rs_db();
    $week = (string)($db->query('SELECT MAX(week) FROM pc_trend')->fetchColumn() ?: '');
    if ($week === '') return null;
    $prev = (string)($db->query('SELECT MAX(week) FROM pc_trend WHERE week < ' . $db->quote($week))->fetchColumn() ?: '');
    $last = [];
    if ($prev !== '') { $st = $db->prepare('SELECT app_id, pos FROM pc_trend WHERE week = ?'); $st->execute([$prev]); foreach ($st->fetchAll(PDO::FETCH_NUM) as [$id, $rk]) $last[$id] = (int)$rk; }
    $hist = [];
    $st = $db->prepare('SELECT app_id, MIN(pos) peak, COUNT(*) weeks FROM pc_trend WHERE week <= ? GROUP BY app_id');
    $st->execute([$week]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $hist[(string)$r['app_id']] = [(int)$r['peak'], (int)$r['weeks']];
    $st = $db->prepare('SELECT app_id, pos FROM pc_trend WHERE week = ? ORDER BY pos');
    $st->execute([$week]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $id = (string)$r['app_id']; $rk = (int)$r['pos'];
        [$peak, $weeks] = $hist[$id] ?? [$rk, 1];
        $lw = $last[$id] ?? null;
        $move = $lw !== null ? ($lw > $rk ? 'up' : ($lw < $rk ? 'down' : 'same')) : ($weeks > 1 ? 're' : ($prev === '' ? 'same' : 'new'));
        $out[] = ['id' => $id, 'rank' => $rk, 'last' => $lw, 'peak' => $peak, 'weeks' => $weeks, 'move' => $move];
    }
    [$from, $to] = rs_trend_span($week);
    return ['week' => $week, 'from' => gmdate('Y-m-d', strtotime($from . ' UTC') - RS_TREND_TZ * 3600), 'to' => gmdate('Y-m-d', strtotime($to . ' UTC') - RS_TREND_TZ * 3600 - 1),
        'next' => gmdate('Y-m-d', strtotime($week . ' UTC') + 7 * 86400), 'first' => $prev === '', 'entries' => $out];
}

function rs_trend_file(): string { return RS_PUBLIC . '/trending.json'; }
function rs_trend_write(): void {
    $c = rs_trend_chart();
    if (!$c) return;
    $c['ok'] = true;
    $c['updated'] = gmdate('c');
    $tmp = rs_trend_file() . '.' . getmypid();
    if (@file_put_contents($tmp, (string)json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, rs_trend_file());
}

// From the 30-minute job: the new chart once its Friday comes (and, the first time, the weeks before it
// that the play data fully covers, so movement and weeks on the chart mean something from day one).
function rs_trend_maybe(): string {
    rs_ls_tables();
    rs_trend_tables();
    $db = rs_db();
    $week = rs_trend_latest_week();
    $have = (int)$db->query('SELECT COUNT(*) FROM pc_trend WHERE week = ' . $db->quote($week))->fetchColumn();
    $did = [];
    if (!$have) {
        if (!(int)$db->query('SELECT COUNT(*) FROM pc_trend')->fetchColumn()) {
            $first = (string)($db->query('SELECT MIN(started) FROM lstat_sessions')->fetchColumn() ?: '');
            for ($w = 12; $w >= 1; $w--) {
                $old = gmdate('Y-m-d', strtotime($week . ' UTC') - $w * 7 * 86400);
                if ($first !== '' && rs_trend_span($old)[0] >= $first) { rs_trend_build($old); $did[] = $old; }
            }
        }
        $n = rs_trend_build($week);
        $did[] = $week . ' (' . $n . ')';
        rs_trend_write();
    } elseif (!is_file(rs_trend_file())) rs_trend_write();
    return implode(', ', $did);
}
