<?php
// RecompLauncher (4.8): Instant Gaming cards on recomps' pages.
//   GET                       -> the switch, the versions being tested and the cards for this visitor
//   POST ?e=view&id=X&v=a&r=1 -> one view of recomp X's card in version a (sent once per page)
//   GET  ?go=X&v=a            -> one click, then on to the game on Instant Gaming with our partner code
//                                (&peek=1: a preview from the admin, not counted)
// (4.9) Cards are for the visitor's country only (Cloudflare's CF-IPCountry): a game shows the Instant
// Gaming link whose key works there, priced in the currency the store shows that country, worked out
// from the store's own rates. No link for their country, or no country at all: no card, and a click
// can't get past that either. Answered from a file (admin/inc/ig.php rs_ig_cache_write), no database.
declare(strict_types=1);

$igFile = dirname(__DIR__, 2) . '/recompstore-private/ig-cache.json';
$cc = strtoupper((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''));
if (!preg_match('/^[A-Z]{2}$/', $cc) || in_array($cc, ['XX', 'T1'], true)) $cc = '';
$idOk = fn(string $s): bool => (bool)preg_match('/^[A-Za-z0-9._-]{1,80}$/', $s);

$igData = function () use ($igFile): array {
    $d = is_file($igFile) ? json_decode((string)@file_get_contents($igFile), true) : null;
    if (is_array($d) && time() - (int)($d['at'] ?? 0) < 6 * 3600) return $d;
    // Missing or old: built again (the refresh job keeps it new; this is the fallback).
    require_once dirname(__DIR__) . '/admin/inc/bootstrap.php';
    require_once dirname(__DIR__) . '/admin/inc/connect.php';
    require_once dirname(__DIR__) . '/admin/inc/ig.php';
    rs_ig_cache_write();
    $d = json_decode((string)@file_get_contents($igFile), true);
    return is_array($d) ? $d : ['on' => false, 'round' => 1, 'v' => [], 'all' => [], 'games' => [], 'rates' => []];
};
// The link for this country: the cheapest whose key works here.
$offerFor = function (array $g, string $cc): ?array {
    if ($cc === '') return null;
    $best = null;
    foreach ($g['offers'] as $o) if (in_array($cc, $o['c'], true) && (!$best || $o['eur'] < $best['eur'])) $best = $o;
    return $best;
};
// A recomp's card for this country: its own card if it has a key for here, else its version group's.
// -> [game + key, offer] or null.
$pickFor = function (array $d, string $appId, string $cc) use ($offerFor): ?array {
    if (isset($d['games'][$appId]) && ($o = $offerFor($d['games'][$appId], $cc))) return [$d['games'][$appId] + ['key' => $appId], $o];
    foreach ($d['games'] as $k => $g) if ($k !== $appId && in_array($appId, $g['ids'], true) && ($o = $offerFor($g, $cc))) return [$g + ['key' => $k], $o];
    return null;
};
$currencyFor = function (string $cc, array $rates): string {
    require_once dirname(__DIR__) . '/admin/inc/countries.php';
    $cur = RS_COUNTRY_CURRENCY[$cc] ?? 'USD';
    return isset($rates[$cur]) ? $cur : (isset($rates['USD']) ? 'USD' : 'EUR');
};
$money = function (float $eur, string $cur, array $rates): string {
    $c = $rates[$cur] ?? ['tx' => 1.0, 'fmt' => '%s', 'dec' => '.', 'th' => ''];
    return str_replace('&nbsp;', "\u{a0}", str_replace('%s', number_format(round($eur * (float)$c['tx'], 2), 2, $c['dec'] ?: '.', $c['th']), $c['fmt']));
};

$bot = (bool)preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

if (isset($_GET['go'])) {
    $id = (string)$_GET['go'];
    $v = (string)($_GET['v'] ?? '');
    $back = 'https://recomplauncher.com/' . ($idOk($id) ? 'game/' . rawurlencode($id) : '');
    $to = '';
    try {
        $d = $igData();
        [$g, $o] = ($idOk($id) ? $pickFor($d, $id, $cc) : null) ?? [null, null];
        if ($o) {
            $to = $o['url'] . '?' . http_build_query(['igr' => (string)$d['igr'], 'currency' => $currencyFor($cc, $d['rates']),
                'utm_source' => 'recomplauncher', 'utm_medium' => 'game', 'utm_campaign' => 'link']);
            if (in_array($v, array_column($d['all'], 'id'), true) && !$bot && empty($_GET['peek'])) {
                require_once dirname(__DIR__) . '/admin/inc/bootstrap.php';
                require_once dirname(__DIR__) . '/admin/inc/ig.php';
                if (!rs_rate_over('igclick', 40, 3600)) { rs_rate_hit('igclick'); rs_ig_count((string)$g['key'], $v, 'clicks'); }
            }
        }
    } catch (Throwable $e) { $to = ''; }
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    // No key for this country: back to the game's page, never to a key that won't work.
    header('Location: ' . ($to !== '' ? $to : $back), true, 302);
    exit;
}

if (($_GET['e'] ?? '') === 'view') {
    $id = (string)($_GET['id'] ?? '');
    $v = (string)($_GET['v'] ?? '');
    if (!$bot && $idOk($id) && preg_match('/^[a-d]$/', $v)) {
        try {
            $d = $igData();
            [$g] = $pickFor($d, $id, $cc) ?? [null];
            if ($d['on'] && (int)($_GET['r'] ?? 0) === (int)$d['round'] && $g && in_array($v, array_column($d['v'], 'id'), true)) {
                require_once dirname(__DIR__) . '/admin/inc/bootstrap.php';
                require_once dirname(__DIR__) . '/admin/inc/ig.php';
                rs_ig_count((string)$g['key'], $v, 'views');
            }
        } catch (Throwable $e) { /* counting is best-effort */ }
    }
    header('Cache-Control: no-store');
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');
// Different for each country, so Cloudflare keeps none of it; the browser keeps it 2 minutes.
header('Cache-Control: private, max-age=120');
header('Vary: CF-IPCountry');
try {
    $d = $igData();
    $l = [];
    $cur = $cc !== '' ? $currencyFor($cc, $d['rates']) : '';
    // A group's card first, so a recomp's own card can take its place.
    $games = $d['games'];
    uksort($games, fn($a, $b) => (str_starts_with($b, 't:') ? 1 : 0) <=> (str_starts_with($a, 't:') ? 1 : 0));
    foreach ($games as $k => $g) {
        $o = $offerFor($g, $cc);
        if (!$o) continue;
        $pct = ($o['old'] && $o['old'] > $o['eur']) ? (int)round(($o['old'] - $o['eur']) / $o['old'] * 100) : 0;
        $deal = $pct > 0 && $d['tag'] && $g['tag'] !== 2 && ($g['tag'] === 1 || $pct >= (int)$d['tagMin']);
        $card = ['t' => $g['t'], 'p' => $g['p'], 'price' => $d['price'] ? $money($o['eur'], $cur, $d['rates']) : '',
            'old' => $d['price'] && $deal ? $money((float)$o['old'], $cur, $d['rates']) : '', 'pct' => $d['price'] && $deal ? $pct : 0, 'off' => false];
        foreach ($g['ids'] as $id) $l[$id] = $card;
    }
    $out = ['on' => (bool)$d['on'], 'round' => (int)$d['round'], 'v' => $d['v'], 'all' => $d['all'], 'cc' => $cc, 'cur' => $cur, 'l' => $l ?: new stdClass()];
} catch (Throwable $e) { $out = ['on' => false, 'round' => 1, 'v' => [], 'all' => [], 'l' => new stdClass()]; }
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
