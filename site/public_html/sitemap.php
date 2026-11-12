<?php
// RecompStore website (2.6): /sitemap.xml, every page search engines should know: the site's own
// pages, each app's and Coming soon entry's page, and each developer's. Made from catalog.json.
declare(strict_types=1);
require __DIR__ . '/admin/inc/seo.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$base = rs_seo_base();
// (2.9) Each side its own: RecompStore's PS5 pages here, RecompLauncher's PC pages on recomplauncher.com.
$L = rs_launcher();
$when = @filemtime(__DIR__ . '/catalog.json');
$lastmod = $when ? gmdate('Y-m-d', $when) : null;
$apps = rs_seo_apps();
$soon = rs_seo_soon();
$urls = $L ? [] : [['/', '1.0'], ['/get', '0.9'], ['/how', '0.8']];
if (!$L && rs_seo_v2()) {
    foreach (['games', 'emulators', 'apps', 'tools'] as $k) {
        foreach ($apps as $a) if (((string)($a['category'] ?? 'games') ?: 'games') === $k) { $urls[] = ['/' . $k, '0.8']; break; }
    }
    $urls[] = ['/ids', '0.4'];
}
if (!$L && $soon) $urls[] = ['/soon', '0.6'];
if (!$L) foreach (['/submit' => '0.6', '/press' => '0.5', '/beta' => '0.5', '/developers' => '0.6', '/report' => '0.4', '/about' => '0.5', '/contact' => '0.4', '/dmca' => '0.3'] as $u => $pri) $urls[] = [$u, $pri];
// (2.7.1) Each app's page dated by its newest version, so search engines come back for new apps
// and updates rather than every page each time the catalog changes.
$today = gmdate('Y-m-d');
if (!$L) foreach ($apps as $a) {
    $rel = (string)($a['released'] ?? '');
    $urls[] = ['/game/' . rawurlencode((string)$a['id']), '0.7', preg_match('/^\d{4}-\d{2}-\d{2}$/', $rel) && $rel <= $today ? $rel : null];
}
if (!$L) foreach ($soon as $a) $urls[] = ['/soon/' . rawurlencode((string)$a['id']), '0.5'];
// (2.8) RecompStore for PC.
$pcCat = json_decode((string)@file_get_contents(__DIR__ . '/pc-catalog.json'), true);
$pcApps = is_array($pcCat['apps'] ?? null) ? $pcCat['apps'] : [];
if ($L && $pcApps) {
    array_push($urls, ['/', '1.0'], ['/all', '0.8'], ['/top', '0.7'], ['/new', '0.7'], ['/consoles', '0.5'], ['/get', '0.6'], ['/how', '0.5'], ['/press', '0.4'], ['/soon', '0.5']);
    foreach (['action' => 'action', 'adventure' => 'adventure', 'platformer' => 'platformers', 'racing' => 'racing', 'fighting' => 'fighting', 'shooter' => 'shooters', 'sports' => 'sports', 'rpg' => 'rpg', 'puzzle' => 'puzzle'] as $g => $slug)
        foreach ($pcApps as $a) if (($a['genre'] ?? '') === $g) { $urls[] = ['/' . $slug, '0.6']; break; }
    foreach ($pcApps as $a) {
        $rel = (string)($a['released'] ?? '');
        $urls[] = ['/game/' . rawurlencode((string)$a['id']), '0.7', preg_match('/^\d{4}-\d{2}-\d{2}$/', $rel) && $rel <= $today ? $rel : null];
    }
}
if (!$L) foreach (rs_seo_devs() as $d) {
    foreach ($apps as $a) if (($a['dev'] ?? '') === $d['slug']) { $urls[] = ['/dev/' . $d['slug'], '0.5']; break; }
}
// (4.8) RecompLauncher's developer hub, Submit page and each developer with a PC recomp.
if ($L) {
    array_push($urls, ['/dev', '0.6'], ['/submit', '0.5'], ['/contact', '0.3'], ['/dmca', '0.3']);
    foreach (rs_seo_devs() as $d) {
        foreach ($pcApps as $a) if (($a['dev'] ?? '') === $d['slug']) { $urls[] = ['/dev/' . $d['slug'], '0.5']; break; }
    }
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $row) {
    [$u, $pri] = $row;
    $lm = $row[2] ?? $lastmod;
    echo '  <url><loc>' . htmlspecialchars($base . $u, ENT_XML1, 'UTF-8') . '</loc>' . ($lm ? '<lastmod>' . $lm . '</lastmod>' : '') . '<priority>' . $pri . '</priority></url>' . "\n";
}
echo "</urlset>\n";
