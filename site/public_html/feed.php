<?php
// (4.8) RecompLauncher's feed: new recomps and updates, newest first, the same as the What's new page.
// recomplauncher.com/feed.xml (RSS 2.0) and /feed.json (JSON Feed 1.1), from the published catalog.
declare(strict_types=1);
require __DIR__ . '/admin/inc/catcache.php';
$json = ($_GET['f'] ?? '') === 'json';
$cat = rs_json_cached(__DIR__ . '/pc-catalog.json');
$apps = is_array($cat['apps'] ?? null) ? $cat['apps'] : [];
$base = 'https://recomplauncher.com';
$at = function (string $s): int { if ($s === '') return 0; $t = strtotime(str_contains($s, 'T') ? $s : $s . 'T12:00:00Z'); return $t ?: 0; };
$now = time();
$cut = $now - 30 * 86400;
$items = []; $fresh = [];
foreach ($apps as $a) {
    if (!is_array($a) || !empty($a['soon'])) continue;
    $t = $at((string)($a['added'] ?? ''));
    if ($t >= $cut && $t <= $now + 86400) { $items[] = ['new', $a, min($t, $now)]; $fresh[(string)$a['id']] = true; }
}
foreach ($apps as $a) {
    if (!is_array($a) || !empty($a['soon']) || isset($fresh[(string)$a['id']])) continue;
    $t = $at((string)($a['released'] ?? ''));
    if ($t >= $cut && $t <= $now + 86400) $items[] = ['upd', $a, min($t, $now)]; // a release's day, not after now
}
usort($items, fn($x, $y) => $y[2] <=> $x[2]);
$items = array_slice($items, 0, 60);
$title = fn(array $i) => ($i[0] === 'new' ? 'New: ' : 'Updated: ') . (string)$i[1]['name'] . ($i[0] === 'upd' && !empty($i[1]['version']) ? ' ' . $i[1]['version'] : '');
$summary = function (array $i): string {
    $a = $i[1];
    $s = trim((string)($a['blurb'] ?? ''));
    if ($s === '') $s = (string)$a['name'] . (!empty($a['game']) && $a['game'] !== $a['name'] ? ', a PC recomp of ' . $a['game'] : ', a PC recomp') . '. Bring your own copy of the game.';
    return $i[0] === 'new' ? 'New on RecompLauncher. ' . $s : 'Updated' . (!empty($a['version']) ? ' to ' . $a['version'] : '') . '. ' . $s;
};
$link = fn(array $i) => $base . '/game/' . rawurlencode((string)$i[1]['id']);
header('Cache-Control: public, max-age=600');
header('Access-Control-Allow-Origin: *');
if ($json) {
    header('Content-Type: application/feed+json; charset=utf-8');
    echo json_encode(['version' => 'https://jsonfeed.org/version/1.1', 'title' => 'RecompLauncher: What\'s new', 'home_page_url' => $base . '/whats-new', 'feed_url' => $base . '/feed.json',
        'description' => 'New PC recomps and updates on RecompLauncher.', 'icon' => $base . '/assets/logo-512.png', 'language' => 'en',
        'items' => array_map(fn($i) => array_filter(['id' => $i[0] . ':' . $i[1]['id'] . ':' . ($i[0] === 'upd' ? (string)($i[1]['version'] ?? '') : 'live'), 'url' => $link($i), 'title' => $title($i),
            'summary' => $summary($i), 'content_text' => $summary($i), 'image' => (string)($i[1]['hero'] ?? '') ?: null, 'banner_image' => (string)($i[1]['hero'] ?? '') ?: null,
            'date_published' => gmdate('c', $i[2]), 'tags' => [$i[0] === 'new' ? 'New' : 'Updated']], fn($v) => $v !== null), $items)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
header('Content-Type: application/rss+xml; charset=utf-8');
$x = fn(string $s) => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>',
    '<title>RecompLauncher: What\'s new</title><link>', $base, '/whats-new</link><description>New PC recomps and updates on RecompLauncher.</description><language>en</language>',
    '<atom:link href="', $base, '/feed.xml" rel="self" type="application/rss+xml"/>',
    '<image><url>', $base, '/assets/logo-512.png</url><title>RecompLauncher: What\'s new</title><link>', $base, '/whats-new</link></image>',
    '<lastBuildDate>', gmdate(DATE_RSS, $items ? $items[0][2] : $now), '</lastBuildDate>';
foreach ($items as $i) {
    $img = (string)($i[1]['hero'] ?? '') ?: (string)($i[1]['cover'] ?? '');
    echo '<item><title>', $x($title($i)), '</title><link>', $x($link($i)), '</link>',
        '<guid isPermaLink="false">', $x($i[0] . ':' . $i[1]['id'] . ':' . ($i[0] === 'upd' ? (string)($i[1]['version'] ?? '') : 'live')), '</guid>',
        '<pubDate>', gmdate(DATE_RSS, $i[2]), '</pubDate><category>', $i[0] === 'new' ? 'New' : 'Updated', '</category>',
        '<description>', $x(($img !== '' && preg_match('#^https://#', $img) ? '<p><img src="' . htmlspecialchars($img, ENT_QUOTES) . '" alt=""></p>' : '') . '<p>' . htmlspecialchars($summary($i), ENT_QUOTES) . '</p>'), '</description></item>';
}
echo '</channel></rss>';
