<?php
// RecompStore (4.9): Instant Gaming prices, every 5 minutes (/etc/cron.d/recompstore-ig). Each link read
// more than 4 minutes ago is read again (price in every currency it's shown in, stock, countries), and
// what game pages read is rebuilt when anything changed.
declare(strict_types=1);
chdir('/var/www/recompstore/public_html');
require 'admin/inc/bootstrap.php';
require_once 'admin/inc/connect.php';
require_once 'admin/inc/ig.php';
$lock = fopen('/tmp/recompstore-ig.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
[$ok, $bad] = rs_ig_refresh(240, 200);
if ($ok || $bad) echo gmdate('c') . ' read ' . $ok . ($bad ? ', ' . $bad . ' failed' : '') . "\n";
