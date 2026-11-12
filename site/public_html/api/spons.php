<?php
// RecompStore (4.8): sponsor spots for recomplauncher.com.
//   GET               -> what runs today ({"s":[...]}), cached 2 minutes
//   POST ?seen=1,2    -> one view each (sent once per page by spons.js)
//   GET  ?go=ID       -> one click, then on to the sponsor's link
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/sponsors.php';

$bot = (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

if (isset($_GET['go'])) {
    $id = (int)$_GET['go'];
    $link = '';
    try {
        rs_sp_tables();
        $st = rs_db()->prepare('SELECT link FROM sponsors WHERE id = ?');
        $st->execute([$id]);
        $link = (string)$st->fetchColumn();
        if ($link !== '' && !$bot) rs_sp_count([$id], 'clicks');
    } catch (Throwable $e) { /* still send them on */ }
    if (!preg_match('#^https://#i', $link)) $link = 'https://recomplauncher.com/';
    header('Cache-Control: no-store');
    header('Location: ' . $link, true, 302);
    exit;
}

if (isset($_GET['seen'])) {
    if (!$bot) { try { rs_sp_count(explode(',', (string)$_GET['seen']), 'views'); } catch (Throwable $e) { /* counting is best-effort */ } }
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: public, max-age=120, s-maxage=120');
try { $s = rs_sp_live(); } catch (Throwable $e) { $s = []; }
echo json_encode(['s' => $s], JSON_UNESCAPED_SLASHES);
