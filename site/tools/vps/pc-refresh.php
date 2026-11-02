<?php
// (3.5) Keeps the PC recomps' GitHub numbers (version, size, downloads, stars) fresh in the background,
// so Publish PC doesn't have to ask GitHub about every recomp while you wait. Run by cron as www-data:
//   */30 * * * * php /var/www/recompstore/pc-refresh.php >> /var/log/recompstore-pc-refresh.log 2>&1
// It only updates the saved recomps; the site changes when Publish PC is pressed, as before.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$lock = fopen(sys_get_temp_dir() . '/recompstore-pc-refresh.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0); // the last run is still going
chdir('/var/www/recompstore/public_html');
require 'admin/inc/bootstrap.php';
require_once 'admin/inc/pc.php';
$t = microtime(true);
$n = rs_pc_refresh_github(1500, in_array('--all', $argv, true) ? 0 : 1800); // --all: every recomp now
echo gmdate('c') . ' checked ' . $n . ' in ' . round(microtime(true) - $t) . "s\n";
