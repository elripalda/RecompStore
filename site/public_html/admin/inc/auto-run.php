<?php
// (4.7) Auto-add's background run: php auto-run.php [draft ids]. Started by rs_auto_launch; never from the web.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/pc.php';
if (is_file(__DIR__ . '/vt.php')) require_once __DIR__ . '/vt.php';
require_once __DIR__ . '/autoadd.php';
@set_time_limit(0);
ignore_user_abort(true);
// (4.7) Its own ids first, in turn. (4.8) Then, while fewer than RS_AUTO_WORKERS runs are busy, it
// takes the newest one waiting (a fresh Check it doesn't wait behind a big batch), so a big batch keeps several runs going instead of
// one run working through a long list while the others sit done. Drafts still waiting are touched
// each time, so the page shows them in line rather than stopped.
$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1)), fn($i) => $i > 0));
$touch = fn() => rs_db()->exec("UPDATE pc_auto SET updated = NOW() WHERE status = 'queued'");
foreach ($ids as $id) {
    $touch();
    try { rs_auto_run($id); } catch (Throwable $e) { error_log('auto-run ' . $id . ': ' . $e->getMessage()); }
}
for ($n = 0; $n < 500; $n++) {
    if ((int)rs_db()->query("SELECT COUNT(*) FROM pc_auto WHERE status = 'running' AND updated > NOW() - INTERVAL " . RS_AUTO_STUCK . " SECOND")->fetchColumn() >= RS_AUTO_WORKERS) break;
    $next = (int)rs_db()->query("SELECT id FROM pc_auto WHERE status = 'queued' ORDER BY id DESC LIMIT 1")->fetchColumn();
    if (!$next) break;
    $touch();
    try { rs_auto_run($next); } catch (Throwable $e) { error_log('auto-run ' . $next . ': ' . $e->getMessage()); }
}
