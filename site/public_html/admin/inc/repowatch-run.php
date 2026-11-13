<?php
// (4.8) The repo watch's run: php repowatch-run.php [now]. Cron starts it twice a day as www-data
// (/etc/cron.d/recompstore-repowatch, 5:23 and 17:23 UTC); "now" is Scan now in the hub, or a page
// that found the last run too old. One run at a time.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/repowatch.php';
@set_time_limit(0);
ignore_user_abort(true);
$lock = fopen(sys_get_temp_dir() . '/recompstore-repowatch.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0); // the last run is still going
rs_kv_put('watch_running', '1');
try {
    $s = rs_watch_scan(function (string $m) { echo gmdate('c'), ' ', $m, "\n"; });
    echo gmdate('c'), ' checked ', $s['checked'], ' in ', $s['took'], 's: ', $s['ok'], ' up, ', $s['flag'], ' need a look, ', $s['unk'], ' unclear',
        $s['new'] ? ' · new: ' . implode(', ', array_map(fn($x) => $x['store'] . ':' . $x['id'] . ' ' . $x['state'], $s['new'])) : '',
        $s['auth'] ? ' · GitHub turned the token down' : '', $s['err'] !== '' ? ' · ' . $s['err'] : '', "\n";
} catch (Throwable $e) {
    echo gmdate('c'), ' failed: ', $e->getMessage(), "\n";
} finally {
    rs_kv_put('watch_running', '0');
}
