<?php
// RecompStore website (2.6): descriptions and patch notes in the reader's language, for the PS5
// store and this site (admin/inc/translate.php). Only text the catalog or GitHub already shows
// is worth asking about; anything else costs the same and helps no one, so a browser or console
// that asks for a lot of new text in a short time is told to wait.
//   POST lang texts[] (or JSON {"lang":..., "texts":[...]})
//   -> {on, texts: [translation | null, ...]}   null: not available now (show the original)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require dirname(__DIR__) . '/admin/inc/translate.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

function rs_tr_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_tr_out(405, ['message' => 'Send a POST.']);
$in = $_POST;
if (!$in) {
    $j = json_decode((string)file_get_contents('php://input', false, null, 0, 200000), true);
    $in = is_array($j) ? $j : [];
}
$lang = is_string($in['lang'] ?? null) ? $in['lang'] : '';
if (!isset(RS_TR_LANGS[$lang])) rs_tr_out(400, ['message' => 'Ask for one of: ' . implode(', ', array_keys(RS_TR_LANGS)) . '.']);
$texts = $in['texts'] ?? [];
if (!is_array($texts) || !$texts || count($texts) > 40) rs_tr_out(400, ['message' => 'Send 1 to 40 texts.']);
$total = 0;
$clean = [];
foreach (array_values($texts) as $t) {
    if (!is_string($t)) rs_tr_out(400, ['message' => 'Each text is a string.']);
    $t = mb_scrub($t, 'UTF-8');
    if (mb_strlen($t) > 12000) $t = mb_substr($t, 0, 12000);
    $total += mb_strlen($t);
    $clean[] = $t;
}
if ($total > 40000) rs_tr_out(413, ['message' => 'That\'s more than 40,000 characters at once.']);

try {
    // Kept translations are free to give; new ones are counted per address.
    $kept = rs_translate($clean, $lang, false);
    $new = 0;
    foreach ($kept as $i => $t) if ($t === null) $new += mb_strlen($clean[$i]);
    $may = $new === 0;
    if ($new > 0) {
        $may = !rs_rate_over('translate', 40, 3600);
        if ($may) rs_rate_hit('translate');
    }
    $out = $may && $new > 0 ? rs_translate($clean, $lang, true) : $kept;
    rs_tr_out(200, ['on' => rs_tr_on(), 'texts' => $out]);
} catch (Throwable $e) {
    rs_errlog('tr', $e);
    rs_tr_out(503, ['on' => false, 'texts' => array_fill(0, count($clean), null)]);
}
