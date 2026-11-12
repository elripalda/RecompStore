<?php
// (4.7) Auto-add's background run: php auto-run.php <draft id>. Started by rs_auto_launch; never from the web.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/pc.php';
if (is_file(__DIR__ . '/vt.php')) require_once __DIR__ . '/vt.php';
require_once __DIR__ . '/autoadd.php';
@set_time_limit(0);
ignore_user_abort(true);
$id = (int)($argv[1] ?? 0);
if ($id > 0) rs_auto_run($id);
