<?php
// RecompStore admin: what the database is doing right now (connections, limits, slow work).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_require_login();
session_write_close();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$db = rs_db();
$q = function (string $sql) use ($db) { try { return $db->query($sql)->fetchAll(PDO::FETCH_NUM); } catch (Throwable $e) { return [['ERR', $e->getMessage()]]; } };
echo "== limits\n";
foreach ($q("SHOW VARIABLES WHERE Variable_name IN ('max_user_connections','max_connections','wait_timeout','interactive_timeout','max_execution_time','version','innodb_buffer_pool_size','long_query_time')") as $r) echo implode(' = ', $r), "\n";
echo "\n== status\n";
foreach ($q("SHOW STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Max_used_connections','Aborted_connects','Connection_errors_max_connections','Uptime','Slow_queries','Questions')") as $r) echo implode(' = ', $r), "\n";
echo "\n== user limits\n";
foreach ($q("SELECT CURRENT_USER()") as $r) echo implode(' ', $r), "\n";
foreach ($q("SHOW GRANTS") as $r) echo implode(' ', $r), "\n";
echo "\n== this site's connections now\n";
$pl = $q('SHOW FULL PROCESSLIST');
echo count($pl), " connections\n";
foreach ($pl as $r) echo implode(' | ', array_map(fn($v) => mb_substr((string)$v, 0, 160), $r)), "\n";
echo "\n== biggest tables\n";
foreach ($q("SELECT table_name, table_rows, ROUND((data_length+index_length)/1048576,1) mb FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY (data_length+index_length) DESC LIMIT 15") as $r) echo implode(' | ', $r), "\n";
echo "\n== recent connection errors (private log)\n";
$f = dirname(RS_PUBLIC) . '/recompstore-private/db-errors.log';
echo is_file($f) ? implode('', array_slice(file($f) ?: [], -40)) : "(none yet)\n";
