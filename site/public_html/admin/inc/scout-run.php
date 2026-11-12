<?php
// (4.7) The daily scout's run: php scout-run.php [now]. Cron starts it at noon Phoenix
// (/etc/cron.d/recompstore-scout); "now" is Run now on Auto-add (runs even when the daily run is off).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/pc.php';
if (is_file(__DIR__ . '/vt.php')) require_once __DIR__ . '/vt.php';
require_once __DIR__ . '/autoadd.php';
require_once __DIR__ . '/scout.php';
@set_time_limit(0);
ignore_user_abort(true);
$now = ($argv[1] ?? '') === 'now';
if (!$now && !rs_scout_on()) exit;
if (!$now && rs_scout_running()) exit;
$s = rs_scout_run(true);
echo gmdate('c'), ' looked at ', $s['looked'], ', picked ', $s['picked'], ', drafted ', count($s['drafted']), $s['error'] !== '' ? ' · ' . $s['error'] : '', "\n";
