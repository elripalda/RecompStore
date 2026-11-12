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
// (4.7) One or more ids, run in turn. The ones still waiting are touched before each run, so the page
// shows them as in line rather than stopped.
$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1)), fn($i) => $i > 0));
foreach ($ids as $i => $id) {
    $rest = array_slice($ids, $i + 1);
    if ($rest) rs_db()->exec("UPDATE pc_auto SET updated = NOW() WHERE status = 'queued' AND id IN (" . implode(',', $rest) . ')');
    try { rs_auto_run($id); } catch (Throwable $e) { error_log('auto-run ' . $id . ': ' . $e->getMessage()); }
}
