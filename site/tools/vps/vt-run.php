<?php
// (3.8) Works through the security check line from the command line, without waiting for admin
// page loads:  sudo -u www-data php /var/www/recompstore/vt-run.php [seconds]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
chdir('/var/www/recompstore/public_html');
require 'admin/inc/bootstrap.php';
require_once 'admin/inc/pc.php';
require_once 'admin/inc/vt.php';
$t = microtime(true);
$n = rs_vt_run((float)($argv[1] ?? 280));
echo gmdate('c') . ' steps ' . $n . ' in ' . round(microtime(true) - $t) . "s\n";
