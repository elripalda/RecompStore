<?php
// (4.10) Emergency backup's run: php backup-run.php. Started in the background by the 30-minute job when
// there's something to copy, or by Back up now on the Emergency backup page. One run at a time.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/backup.php';
@set_time_limit(0);
ignore_user_abort(true);
$lock = fopen(sys_get_temp_dir() . '/recompstore-backup.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0); // the last run is still going
rs_kv_put('bk_running', '1');
$t0 = time();
try {
    $s = rs_bk_run(function (string $m) { echo gmdate('c'), ' ', $m, "\n"; rs_kv_put('bk_now', $m); });
    $s['at'] = time();
    $s['took'] = time() - $t0;
    rs_kv_put('bk_last', (string)json_encode($s));
    echo gmdate('c'), ' done: ', $s['done'], ' backed up, ', $s['failed'], ' failed, ', $s['left'], ' left', $s['err'] !== '' ? ' · ' . $s['err'] : '', "\n";
} catch (Throwable $e) {
    rs_kv_put('bk_last', (string)json_encode(['at' => time(), 'done' => 0, 'failed' => 0, 'left' => 0, 'err' => $e->getMessage()]));
    echo gmdate('c'), ' failed: ', $e->getMessage(), "\n";
} finally {
    rs_kv_put('bk_running', '0');
    rs_kv_put('bk_now', '');
}
