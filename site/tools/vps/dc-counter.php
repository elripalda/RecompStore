<?php
// (4.6) The Discord member counter: renames the "Members: 12,345" channel when the number changed.
// Run by cron as www-data every 10 minutes:
//   */10 * * * * php /var/www/recompstore/dc-counter.php >> /var/log/recompstore-dc-counter.log 2>&1
// It does nothing while the counter is off on the Beta page. The bot token never goes in the log.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$lock = fopen(sys_get_temp_dir() . '/recompstore-dc-counter.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);
chdir('/var/www/recompstore/public_html');
require 'admin/inc/bootstrap.php';
if (!rs_dc_count_on()) exit(0);
try {
    $s = rs_dc_count_run();
    echo gmdate('c') . ' members ' . ($s['count'] ?? '?') . ($s['err'] !== '' ? ' | ' . $s['err'] : '') . "\n";
} catch (Throwable $e) {
    echo gmdate('c') . ' failed: ' . substr($e->getMessage(), 0, 200) . "\n";
}
