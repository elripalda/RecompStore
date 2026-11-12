<?php
// RecompStore admin (4.8): the Overview's numbers as JSON, polled every 10 seconds. The "right now"
// numbers are counted fresh (cheap, indexed); the day-by-day series and top lists are kept 5 minutes.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/lstats.php';
rs_require_login();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$db = rs_db();
$safe = function (callable $f, $fallback = 0) { try { return $f(); } catch (Throwable $e) { return $fallback; } };
$one = fn(string $sql, array $a = []) => $safe(function () use ($db, $sql, $a) { $st = $db->prepare($sql); $st->execute($a); return (int)$st->fetchColumn(); });
$mgr = rs_role_at_least('manager');
$pc = rs_has_store('pc');
$out = ['ok' => true, 'at' => gmdate('c')];

// Right now
if ($pc) {
    $live = $safe(fn() => rs_ls_live(), ['open' => 0, 'playing' => 0, 'games' => []]);
    $out['open'] = (int)$live['open'];
    $out['playing'] = (int)$live['playing'];
    $out['now_games'] = array_map(fn($g) => ['name' => (string)$g['name'], 'n' => (int)$g['n']], array_slice((array)$live['games'], 0, 8));
    $out['players_today'] = $one('SELECT COUNT(*) FROM lstat_days WHERE day = UTC_DATE()');
    $out['players_yday'] = $one('SELECT COUNT(*) FROM lstat_days WHERE day = UTC_DATE() - INTERVAL 1 DAY');
}
if ($mgr) {
    $out['acc_total'] = $one('SELECT COUNT(*) FROM accounts');
    $out['acc_ok'] = $one('SELECT COUNT(*) FROM accounts WHERE email_ok = 1');
    $out['acc_today'] = $one('SELECT COUNT(*) FROM accounts WHERE created >= UTC_DATE()');
    $out['acc_yday'] = $one('SELECT COUNT(*) FROM accounts WHERE created >= UTC_DATE() - INTERVAL 1 DAY AND created < UTC_DATE()');
    $out['del_today'] = $one('SELECT COALESCE(SUM(n), 0) FROM acct_deleted_days WHERE day = UTC_DATE()');
    $out['seen_today'] = $one('SELECT COUNT(*) FROM accounts WHERE last_seen >= UTC_DATE()');
}

// Slower numbers, kept 5 minutes: 90 days a day at a time, the top games and the hardware.
$c = rs_kv_get('ov_stats2');
$stats = $c && $c['age'] < 300 ? json_decode($c['v'], true) : null;
if (!is_array($stats)) {
    $days = [];
    for ($i = 89; $i >= 0; $i--) $days[gmdate('Y-m-d', time() - $i * 86400)] = ['new' => 0, 'play' => 0, 'del' => 0];
    $fill = function (string $sql, string $k) use (&$days, $db, $safe) {
        $safe(function () use (&$days, $db, $sql, $k) { foreach ($db->query($sql)->fetchAll(PDO::FETCH_NUM) as $r) if (isset($days[(string)$r[0]])) $days[(string)$r[0]][$k] = (int)$r[1]; });
    };
    $fill('SELECT DATE(created) d, COUNT(*) FROM accounts WHERE created >= UTC_DATE() - INTERVAL 90 DAY GROUP BY d', 'new');
    $fill('SELECT day, COUNT(*) FROM lstat_days WHERE day >= UTC_DATE() - INTERVAL 90 DAY GROUP BY day', 'play');
    $fill('SELECT day, n FROM acct_deleted_days WHERE day >= UTC_DATE() - INTERVAL 90 DAY', 'del');
    $stats = ['days' => array_map(fn($d, $v) => ['d' => $d] + $v, array_keys($days), $days)];
    $stats['games'] = $safe(fn() => array_map(fn($r) => ['name' => (string)$r[0], 'n' => (int)$r[1]],
        $db->query('SELECT MAX(game_name), COUNT(DISTINCT ih) n FROM lstat_sessions WHERE started > UTC_TIMESTAMP() - INTERVAL 1 DAY GROUP BY game_id ORDER BY n DESC LIMIT 10')->fetchAll(PDO::FETCH_NUM)), []);
    $hw = function (string $col, int $lim) use ($db, $safe) {
        return $safe(fn() => array_map(fn($r) => ['name' => (string)$r[0], 'n' => (int)$r[1]],
            $db->query("SELECT $col, COUNT(*) n FROM rl_installs WHERE last_seen > UTC_TIMESTAMP() - INTERVAL 7 DAY AND $col <> '' GROUP BY $col ORDER BY n DESC LIMIT $lim")->fetchAll(PDO::FETCH_NUM)), []);
    };
    $stats['gpus'] = $hw('gpu', 10);
    $stats['os'] = $hw('os', 4);
    $stats['versions'] = $hw('version', 5);
    rs_kv_put('ov_stats2', (string)json_encode($stats));
}
if (!$mgr) unset($stats['days']);
echo json_encode($out + $stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
