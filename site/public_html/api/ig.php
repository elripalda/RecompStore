<?php
// RecompLauncher (4.8): Instant Gaming cards on recomps' pages.
//   GET                       -> the switch, the versions being tested and the listings, cached 2 minutes
//   POST ?e=view&id=X&v=a&r=1 -> one view of listing X's card in version a (sent once per page)
//   GET  ?go=X&v=a            -> one click, then on to the game on Instant Gaming with our partner code
//                                (&peek=1: a preview from the admin, not counted)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/connect.php';
require_once dirname(__DIR__) . '/admin/inc/ig.php';

$bot = (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
$idOk = fn(string $s): bool => (bool)preg_match('/^[A-Za-z0-9._-]{1,80}$/', $s);

if (isset($_GET['go'])) {
    $id = (string)$_GET['go'];
    $v = (string)($_GET['v'] ?? '');
    $to = 'https://www.instant-gaming.com/';
    try {
        $c = rs_ig_cfg();
        rs_ig_tables();
        if ($idOk($id)) {
            // (4.9) The recomp's own card, else its game's; counted under that listing.
            $row = rs_ig_for_app($id);
            $url = $row ? rs_ig_clean_url((string)$row['url']) : '';
            if ($url !== '') $to = $url;
            $known = in_array($v, array_column($c['variants'], 'id'), true);
            if ($url !== '' && $known && !$bot && empty($_GET['peek']) && !rs_rate_over('igclick', 40, 3600)) { rs_rate_hit('igclick'); rs_ig_count((string)$row['app_id'], $v, 'clicks'); }
        }
        $to = rs_ig_link($to, $c['igr']);
    } catch (Throwable $e) { $to = rs_ig_link($to, 'recomp'); }
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('Location: ' . $to, true, 302);
    exit;
}

if (($_GET['e'] ?? '') === 'view') {
    $id = (string)($_GET['id'] ?? '');
    $v = (string)($_GET['v'] ?? '');
    if (!$bot && $idOk($id) && in_array($v, RS_IG_IDS, true)) {
        try {
            $c = rs_ig_cfg();
            $row = rs_ig_for_app($id);
            if ($c['on'] && (int)($_GET['r'] ?? 0) === $c['round'] && $row && !(int)$row['paused'] && in_array($v, array_column(rs_ig_live_variants($c), 'id'), true)) rs_ig_count((string)$row['app_id'], $v, 'views');
        } catch (Throwable $e) { /* counting is best-effort */ }
    }
    header('Cache-Control: no-store');
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: public, max-age=120, s-maxage=120');
try { $out = rs_ig_public(); } catch (Throwable $e) { $out = ['on' => false, 'round' => 1, 'v' => [], 'all' => [], 'l' => new stdClass()]; }
if (!$out['l']) $out['l'] = new stdClass();
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
