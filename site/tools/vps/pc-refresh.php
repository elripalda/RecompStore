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
// SDK support: look for a recomplauncher.json in the recomps not checked this week, most downloaded
// first, for up to four minutes a run, so the admin table fills in without anyone keeping it open.
$t = microtime(true);
$s = 0;
try {
    rs_cache_tables();
    $seen = [];
    foreach (rs_db()->query("SELECT k FROM kv_cache WHERE k LIKE 'sdk:%' AND at > NOW() - INTERVAL 7 DAY") as $r) $seen[substr((string)$r['k'], 4)] = true;
    $todo = array_values(array_filter(rs_pc_apps(), fn($a) => empty($a['hidden']) && empty($a['soon']) && (string)$a['repo'] !== '' && !isset($seen[$a['id']])));
    usort($todo, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
    foreach ($todo as $a) {
        if (microtime(true) - $t > 240) break;
        try { rs_pc_sdk_check($a); ++$s; } catch (Throwable $e) { /* next run */ }
    }
    echo gmdate('c') . ' sdk checked ' . $s . ' of ' . count($todo) . ' in ' . round(microtime(true) - $t) . "s\n";
} catch (Throwable $e) { echo gmdate('c') . ' sdk: ' . $e->getMessage() . "\n"; }
