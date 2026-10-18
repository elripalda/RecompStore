<?php
// Recomp Store website: a game's own link, recompstore.com/game/<ID> (Coming soon entries:
// /soon/<ID>). It's the site's page with the game's name, blurb and picture in its head, so the
// link shows a card when it's shared; the site's script then opens the game's page.
declare(strict_types=1);
require __DIR__ . '/admin/inc/og.php';

$id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['id'] ?? ''));
$html = (string)file_get_contents(__DIR__ . '/index.html');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
$found = $id !== '' ? rs_og_find($id) : null;
if (!$found) {
    http_response_code(404);
    echo $html;
    exit;
}
[$a, $soon] = $found;
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
$base = rs_og_base();
$url = $base . ($soon ? '/soon/' : '/game/') . rawurlencode($id);
$name = trim((string)($a['name'] ?? $id));
$blurb = trim((string)($a['blurb'] ?? ''));
if ($blurb === '') $blurb = trim((string)($a['description'] ?? ''));
if (mb_strlen($blurb) > 200) $blurb = rtrim(mb_substr($blurb, 0, 197), " ,.;:") . '…';
$desc = $soon ? 'Coming soon to Recomp Store' . (($a['date'] ?? '') !== '' ? ', ' . $a['date'] : '') . '. ' . $blurb
              : ($blurb !== '' ? $blurb : 'Install it on your PS5 with Recomp Store.');
$image = $base . '/og/' . rawurlencode($id) . '.jpg?v=' . rs_og_version($a, $soon);

$head = implode("\n", [
    '<title>' . $e($name) . ' · Recomp Store</title>',
    '<meta name="description" content="' . $e($desc) . '">',
    '<link rel="canonical" href="' . $e($url) . '">',
    '<meta property="og:type" content="website">',
    '<meta property="og:site_name" content="Recomp Store">',
    '<meta property="og:url" content="' . $e($url) . '">',
    '<meta property="og:title" content="' . $e($name) . '">',
    '<meta property="og:description" content="' . $e($desc) . '">',
    '<meta property="og:image" content="' . $e($image) . '">',
    '<meta property="og:image:width" content="' . RS_OG_W . '">',
    '<meta property="og:image:height" content="' . RS_OG_H . '">',
    '<meta property="og:image:alt" content="' . $e($name . ' on Recomp Store') . '">',
    '<meta name="twitter:card" content="summary_large_image">',
    '<meta name="twitter:title" content="' . $e($name) . '">',
    '<meta name="twitter:description" content="' . $e($desc) . '">',
    '<meta name="twitter:image" content="' . $e($image) . '">',
]);
// The page's own title and card tags give way to the game's.
$html = preg_replace('#<title>.*?</title>\s*#s', '', $html, 1);
$html = preg_replace('#<meta (name|property)="(description|og:[a-z:_]+|twitter:[a-z:_]+)"[^>]*>\s*#', '', $html);
$html = preg_replace('#<link rel="canonical"[^>]*>\s*#', '', $html);
$html = preg_replace_callback('#<meta name="viewport"[^>]*>#', fn($m) => $m[0] . "\n" . $head, $html, 1);
echo $html;
