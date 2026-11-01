<?php
// RecompStore admin (2.6): one player's report as a zip, in one click: every log file they sent,
// message.txt with what they wrote (and what they finished on their phone), and the pictures.
// Named for what it's about: "Snes9x PS5 - v2.8 - Won't install - tester 494f2d - 2026-10-23".
// (2.7.3) ?all=1 [&app=ID] [&days=N]: every report (for one app), a folder each, in one zip.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_require_login();
rs_inbox_table();
rs_report_tables();
rs_submission_columns();
rs_inbox_category_column();
@set_time_limit(120);

// (3.3) Logs picked in the Inbox: ?ids=1,2,3 (up to 500), a folder per person.
if (isset($_REQUEST['ids'])) {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$_REQUEST['ids'])), fn($i) => $i > 0))), 0, 500);
    if (!$ids) { http_response_code(400); exit('Pick some logs first.'); }
    $rows = rs_db()->query("SELECT * FROM inbox WHERE kind = 'logs' AND id IN (" . implode(',', $ids) . ') ORDER BY created DESC')->fetchAll();
    if (!$rows) { http_response_code(404); exit('Those logs are gone.'); }
    [$zip, $n, $left, $people] = rs_log_zip_people($rows);
    rs_zip_send($zip, rs_zip_clean('Logs - ' . $n . ($n === 1 ? ' report' : ' reports') . ' from ' . $people . ($people === 1 ? ' person' : ' people') . ' - ' . gmdate('Y-m-d')));
}

if (!empty($_GET['all'])) {
    $app = (string)($_GET['app'] ?? '');
    $days = max(0, min(3650, (int)($_GET['days'] ?? 0)));
    $sql = "SELECT * FROM inbox WHERE kind = 'logs'" . ($app !== '' ? ' AND app_id = ?' : '') . ($days ? ' AND created > (NOW() - INTERVAL ' . $days . ' DAY)' : '') . ' ORDER BY created DESC LIMIT 500';
    $st = rs_db()->prepare($sql);
    $st->execute($app !== '' ? [$app] : []);
    $rows = $st->fetchAll();
    if (!$rows) { http_response_code(404); exit('No logs to download.'); }
    [$zip] = rs_log_zip_people($rows);
    $g = $app !== '' ? rs_game($app) : null;
    rs_zip_send($zip, rs_zip_clean(($app === '' ? 'All logs' : ($app === 'store' ? 'RecompStore' : (string)($g['name'] ?? $app)) . ' logs') . ' - ' . gmdate('Y-m-d')));
}

$st = rs_db()->prepare("SELECT * FROM inbox WHERE id = ? AND kind = 'logs'");
$st->execute([(int)($_GET['id'] ?? 0)]);
$r = $st->fetch();
if (!$r) { http_response_code(404); exit('Those logs are gone.'); }
[$folder, $items] = rs_log_report_items($r, false);
$time = (int)strtotime((string)$r['created']);
rs_zip_send(rs_zip_stored(array_map(fn($p, $b) => [$folder . '/' . $p, $b, $time], array_keys($items), array_values($items))), $folder);
