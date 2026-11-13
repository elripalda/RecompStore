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
// (4.8) Then one run (whichever gets the pump lock) keeps VirusTotal's line moving while drafts are
// waiting on it: the line otherwise moves only when someone loads a page, so a big batch overnight sat
// at "VirusTotal checking" with most of the day's allowance unused. Stops when nothing urgent is left,
// the day's allowance is used up, or after about three hours.
if (function_exists('rs_vt_run') && rs_vt_on()) {
    $pump = fopen(sys_get_temp_dir() . '/rs-vt-pump.lock', 'c');
    if ($pump && flock($pump, LOCK_EX | LOCK_NB)) {
        $end = time() + 3 * 3600;
        while (time() < $end && rs_vt_day_ok()) {
            $left = (int)rs_db()->query("SELECT COUNT(*) FROM vt_files WHERE status IN ('new', 'upload', 'analyzing') AND prio <= 2")->fetchColumn();
            if (!$left && !(int)rs_db()->query("SELECT COUNT(*) FROM pc_auto WHERE status IN ('queued', 'running')")->fetchColumn()) break;
            if (rs_vt_run(55) === 0) sleep(15);
        }
        flock($pump, LOCK_UN);
    }
}
