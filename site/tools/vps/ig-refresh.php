<?php
// RecompStore: Instant Gaming, once an hour at minute 37 (/etc/cron.d/recompstore-ig). (4.13) From the
// partner feed: the prices, discounts, stock and region of the products our cards use, each key's countries
// from its page once a week, and the whole catalog once a day to find our games in it. Until the feed lets
// the server in, each link's page is read instead. What game pages read is rebuilt after.
declare(strict_types=1);
chdir('/var/www/recompstore/public_html');
require 'admin/inc/bootstrap.php';
require_once 'admin/inc/connect.php';
require_once 'admin/inc/pc.php';
require_once 'admin/inc/ig.php';
@set_time_limit(900);
$lock = fopen('/tmp/recompstore-ig.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;
$line = rs_ig_feed_refresh();
if ($line !== '') echo gmdate('c') . ' ' . $line . "\n";
