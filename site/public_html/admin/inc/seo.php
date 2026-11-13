<?php
// RecompStore website (2.6): what search engines and link previews read. The site is one page
// (index.html) that its script draws; for each address (/, /games, /how, /game/<ID>...) the server
// puts that page's own title, description, links to it in every language and structured data in
// its head, and its text and links in the page itself (shown only where the script doesn't run).
// Reads only the published catalog.json and the site's language files.
declare(strict_types=1);

const RS_SEO_LANGS = ['en', 'en-GB', 'es', 'es-419', 'fr', 'fr-CA', 'pt', 'pt-BR', 'it', 'de', 'nl', 'pl', 'tr', 'ru', 'uk', 'vi', 'id', 'th', 'ar', 'ja', 'zh-Hans', 'zh-Hant', 'ko'];
const RS_SEO_BASE_LANG = ['en-GB' => 'en', 'es-419' => 'es', 'fr-CA' => 'fr', 'pt-BR' => 'pt'];

function rs_seo_public(): string { return dirname(__DIR__, 2); }

function rs_seo_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

// The language the address asks for (?lang=de), exactly as the site names it, or ''.
function rs_seo_lang(): string {
    $want = strtolower(is_string($_GET['lang'] ?? null) ? $_GET['lang'] : '');
    foreach (RS_SEO_LANGS as $c) if (strtolower($c) === $want) return $c;
    return '';
}

// The site's text in a language: English => translation (its base language's first).
function rs_seo_dict(string $lang): array {
    static $cache = [];
    if ($lang === '' || $lang === 'en') return [];
    if (isset($cache[$lang])) return $cache[$lang];
    $files = isset(RS_SEO_BASE_LANG[$lang]) && RS_SEO_BASE_LANG[$lang] !== 'en' ? [RS_SEO_BASE_LANG[$lang], $lang] : [$lang];
    $d = [];
    foreach ($files as $f) {
        $j = json_decode((string)@file_get_contents(rs_seo_public() . '/assets/i18n/' . $f . '.json'), true);
        if (is_array($j)) $d = array_merge($d, $j);
    }
    return $cache[$lang] = $d;
}

// t(): the text in the asked language; tf(): with {name} filled in. (No counted texts here: their
// forms per language are the script's job.)
function rs_seo_t(string $en): string {
    $d = rs_seo_dict(rs_seo_lang());
    return isset($d[$en]) && is_string($d[$en]) && $d[$en] !== '' ? $d[$en] : $en;
}
function rs_seo_tf(string $en, array $v): string {
    return preg_replace_callback('/\{(\w+)\}/', fn($m) => array_key_exists($m[1], $v) ? (string)$v[$m[1]] : $m[0], rs_seo_t($en));
}
// The same names as the site's script, so tools/make-site-i18n.py finds these texts too.
if (!function_exists('t')) { function t(string $en): string { return rs_seo_t($en); } }
if (!function_exists('tf')) { function tf(string $en, array $v): string { return rs_seo_tf($en, $v); } }
// A text that's translated later (in a table): the tool finds it here.
if (!function_exists('N_')) { function N_(string $en): string { return $en; } }

function rs_seo_catalog(): array {
    static $cat = null;
    if ($cat === null) {
        require_once __DIR__ . '/catcache.php'; // (4.1)
        $cat = rs_json_cached(rs_seo_public() . '/catalog.json');
        if (!is_array($cat)) $cat = [];
    }
    return $cat;
}
function rs_seo_v2(): bool { $c = rs_seo_catalog(); return !empty($c['site']['v2']); }

// What everyone sees on the site: the apps (and Coming soon), in the admin's order.
function rs_seo_apps(): array {
    $c = rs_seo_catalog();
    $out = [];
    foreach (array_merge((array)($c['apps'] ?? []), (array)($c['newerStoreApps'] ?? []), rs_seo_v2() ? (array)($c['v2Apps'] ?? []) : []) as $a) {
        if (is_array($a) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($a['id'] ?? ''))) $out[] = $a;
    }
    usort($out, fn($x, $y) => ($x['order'] ?? 1e9) <=> ($y['order'] ?? 1e9));
    return $out;
}
function rs_seo_soon(): array {
    $c = rs_seo_catalog();
    $out = [];
    foreach (array_merge((array)($c['comingSoon'] ?? []), rs_seo_v2() ? (array)($c['comingSoonV2'] ?? []) : []) as $a) {
        if (is_array($a) && preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($a['id'] ?? ''))) $out[] = $a;
    }
    usort($out, fn($x, $y) => ($x['order'] ?? 1e9) <=> ($y['order'] ?? 1e9));
    return $out;
}
function rs_seo_devs(): array {
    // (2.7.2) developers.json (kept up to date by the admin) when it's there, else the catalog's.
    static $live = null;
    $live ??= json_decode((string)@file_get_contents(rs_seo_public() . '/developers.json'), true) ?: [];
    $c = is_array($live['developers'] ?? null) ? $live : rs_seo_catalog();
    return array_values(array_filter((array)($c['developers'] ?? []), fn($d) => is_array($d) && preg_match('/^[a-z0-9-]{1,40}$/', (string)($d['slug'] ?? ''))));
}

// The site's address as this request reached it, without a trailing slash.
function rs_seo_base(): string {
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = preg_replace('/[^A-Za-z0-9.:\-\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'recompstore.com'));
    return ($https ? 'https://' : 'http://') . ($host !== '' ? $host : 'recompstore.com');
}

// (2.9) RecompLauncher: the PC side's own address, recomplauncher.com (the same site, parked on it).
function rs_launcher(): bool { return (bool)preg_match('/(^|\.)recomplauncher\./i', (string)($_SERVER['HTTP_HOST'] ?? '')); }
// The other side's address: RecompStore's from RecompLauncher, and the other way round.
function rs_store_base(): string { return preg_replace('/recomplauncher/i', 'recompstore', rs_seo_base()); }
function rs_launcher_base(): string { return preg_replace('/recompstore/i', 'recomplauncher', rs_seo_base()); }

// A short description from a longer text: whole words, at most $max characters.
function rs_seo_short(string $s, int $max = 200): string {
    $s = trim(preg_replace('/\s+/u', ' ', $s));
    if (mb_strlen($s) <= $max) return $s;
    $cut = mb_substr($s, 0, $max - 1);
    $sp = mb_strrpos($cut, ' ');
    if ($sp !== false && $sp > $max * 0.6) $cut = mb_substr($cut, 0, $sp);
    return rtrim($cut, " ,.;:") . '…';
}

// index.html with a page's head and text. $m: title, desc, path ('/games'), image, imageW, imageH,
// imageAlt, jsonld (array or null), body (HTML), noindex (bool), langs (false: no ?lang= alternates).
function rs_seo_render(string $html, array $m): string {
    $e = 'rs_seo_e';
    $base = rs_seo_base();
    $lang = rs_seo_lang();
    $path = (string)($m['path'] ?? '/');
    $url = $base . $path . ($lang !== '' && $lang !== 'en' ? '?lang=' . rawurlencode($lang) : '');
    $image = (string)($m['image'] ?? ($base . (rs_launcher() ? '/assets/launcher-card.png' : '/assets/card.png')));
    // (2.9) RecompLauncher's pages say what site they're on.
    if (rs_launcher() && !array_key_exists('jsonld', $m)) $m['jsonld'] = ['@context' => 'https://schema.org', '@type' => 'WebSite', '@id' => $base . '/#site', 'name' => 'RecompLauncher',
        'alternateName' => ['Recomp Launcher', 'PC recomps', 'Recompiled PC games'], 'url' => $base . '/'];
    $head = [
        '<title>' . $e($m['title']) . '</title>',
        '<meta name="description" content="' . $e($m['desc']) . '">',
        '<link rel="canonical" href="' . $e($url) . '">',
    ];
    if (!empty($m['noindex'])) $head[] = '<meta name="robots" content="noindex">';
    // (4.8) RecompLauncher's feed of new recomps and updates, for feed readers.
    if (rs_launcher()) $head[] = '<link rel="alternate" type="application/rss+xml" title="RecompLauncher: What\'s new" href="' . $e($base . '/feed.xml') . '">';
    $head = array_merge($head, [
        '<meta property="og:type" content="website">',
        '<meta property="og:site_name" content="' . (rs_launcher() ? 'RecompLauncher' : 'RecompStore') . '">',
        '<meta property="og:url" content="' . $e($url) . '">',
        '<meta property="og:title" content="' . $e($m['ogTitle'] ?? $m['title']) . '">',
        '<meta property="og:description" content="' . $e($m['desc']) . '">',
        '<meta property="og:image" content="' . $e($image) . '">',
        '<meta property="og:image:width" content="' . (int)($m['imageW'] ?? 1200) . '">',
        '<meta property="og:image:height" content="' . (int)($m['imageH'] ?? 630) . '">',
    ]);
    if (!empty($m['imageAlt'])) $head[] = '<meta property="og:image:alt" content="' . $e($m['imageAlt']) . '">';
    if ($lang !== '') $head[] = '<meta property="og:locale" content="' . $e(str_replace('-', '_', $lang)) . '">';
    $head = array_merge($head, [
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . $e($m['ogTitle'] ?? $m['title']) . '">',
        '<meta name="twitter:description" content="' . $e($m['desc']) . '">',
        '<meta name="twitter:image" content="' . $e($image) . '">',
    ]);
    $alts = '';
    if (($m['langs'] ?? true) !== false && empty($m['noindex'])) {
        // English is the plain address; Latin American Spanish is listed by country (search engines
        // don't take the region "419").
        $tags = [];
        foreach (RS_SEO_LANGS as $c) {
            $href = $base . $path . ($c === 'en' ? '' : '?lang=' . rawurlencode($c));
            foreach ($c === 'es-419' ? ['es-MX', 'es-AR', 'es-CO', 'es-CL', 'es-PE', 'es-VE', 'es-EC', 'es-GT', 'es-US'] : [$c] as $h) {
                $tags[] = '<link rel="alternate" hreflang="' . $h . '" href="' . $e($href) . '">';
            }
        }
        $tags[] = '<link rel="alternate" hreflang="x-default" href="' . $e($base . $path) . '">';
        $alts = implode("\n", $tags);
    }
    // The page's own title, card tags and structured data give way to this page's.
    $html = preg_replace('#<title>.*?</title>\s*#s', '', $html, 1);
    $html = preg_replace('#<meta (name|property)="(description|robots|og:[a-z:_]+|twitter:[a-z:_]+)"[^>]*>\s*#', '', $html);
    $html = preg_replace('#<link rel="canonical"[^>]*>\s*#', '', $html);
    if (array_key_exists('jsonld', $m)) {
        $html = preg_replace('#<script type="application/ld\+json">.*?</script>\s*#s', '', $html, 1);
        if (is_array($m['jsonld'])) $head[] = '<script type="application/ld+json">' . json_encode($m['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . '</script>';
    }
    $html = preg_replace_callback('#<meta name="viewport"[^>]*>#', fn($x) => $x[0] . "\n" . implode("\n", $head), $html, 1);
    $html = str_replace('<!--rs:alternates-->', $alts, $html);
    if ($lang !== '') $html = preg_replace('#<html lang="[^"]*"#', '<html lang="' . $e($lang) . '"' . ($lang === 'ar' ? ' dir="rtl"' : ''), $html, 1);
    if (!empty($m['body'])) $html = str_replace('<!--rs:content-->', '<div class="ssr">' . $m['body'] . '</div>', $html);
    // (2.9) RecompLauncher's own name from the first paint.
    if (rs_launcher()) $html = str_replace(['<span>Recomp<b>Store</b></span>', '<body>'], ['<span>Recomp<b>Launcher</b></span>', '<body data-platform="pc">'], $html);
    return $html;
}

// A link list of apps, as the page's text: name, who made it, its one-line summary.
// (2.7.3) Who made an app, for its card and lists: its developer page's name, else its author.
function rs_seo_by(array $a): string {
    $dev = (string)($a['dev'] ?? '');
    foreach (rs_seo_devs() as $d) {
        $mine = $dev !== '' ? ($d['slug'] ?? '') === $dev : in_array((string)($a['id'] ?? ''), (array)($d['apps'] ?? []), true);
        if ($mine && trim((string)($d['name'] ?? '')) !== '') return trim((string)$d['name']);
    }
    return ($a['kind'] ?? '') === 'original' ? 'RecompStore' : trim((string)($a['author'] ?? ''));
}

function rs_seo_app_list(array $apps, bool $soon = false): string {
    $e = 'rs_seo_e';
    if (!$apps) return '';
    $li = array_map(function ($a) use ($e, $soon) {
        $blurb = trim((string)($a['blurb'] ?? ''));
        if ($blurb === '') $blurb = rs_seo_short((string)($a['description'] ?? ''), 160);
        $by = rs_seo_by($a);
        return '<li><a href="/' . ($soon ? 'soon' : 'game') . '/' . rawurlencode((string)$a['id']) . '">' . $e($a['name'] ?? $a['id']) . '</a>'
            . ($by !== '' ? ' · ' . $e(rs_seo_tf('By {author}', ['author' => $by])) : '')
            . ($blurb !== '' ? '<br>' . $e($blurb) : '') . '</li>';
    }, $apps);
    return '<ul>' . implode('', $li) . '</ul>';
}

// The site's main links, as text (for anything that reads the page without its script).
function rs_seo_nav(): string {
    $t = 'rs_seo_t';
    $e = 'rs_seo_e';
    $links = [['/', $t('Discover')]];
    if (rs_seo_v2()) foreach (['games' => 'Games', 'emulators' => 'Emulators', 'apps' => 'Apps', 'tools' => 'Tools'] as $k => $n) $links[] = ['/' . $k, $t($n)];
    foreach ([['/how', 'How it works'], ['/get', 'Get RecompStore'], ['/submit', 'Submit an app'], ['/press', 'Press kit'], ['/report', 'Report a bug'], ['/about', 'About'], ['/contact', 'Contact']] as [$u, $n]) $links[] = [$u, $t($n)];
    return '<nav><ul>' . implode('', array_map(fn($l) => '<li><a href="' . $e($l[0]) . '">' . $e($l[1]) . '</a></li>', $links)) . '</ul></nav>';
}

// Structured data for one app.
function rs_seo_app_ld(array $a, string $url, string $image): array {
    $cat = (string)($a['category'] ?? 'games');
    $ld = [
        '@type' => 'SoftwareApplication',
        'name' => (string)($a['name'] ?? $a['id']),
        'url' => $url,
        'operatingSystem' => 'PlayStation 5',
        'applicationCategory' => $cat === 'games' ? 'GameApplication' : ($cat === 'tools' ? 'UtilitiesApplication' : ($cat === 'emulators' ? 'GameApplication' : 'MultimediaApplication')),
        'image' => $image,
        'isAccessibleForFree' => true,
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
    ];
    $desc = trim((string)($a['description'] ?? '')) ?: trim((string)($a['blurb'] ?? ''));
    if ($desc !== '') $ld['description'] = rs_seo_short($desc, 500);
    if (!empty($a['version'])) $ld['softwareVersion'] = (string)$a['version'];
    // (2.7.1) When this version came out, and who lists it.
    $rel = (string)($a['released'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rel) && $rel <= gmdate('Y-m-d')) $ld['dateModified'] = $rel;
    $ld['publisher'] = ['@type' => 'Organization', 'name' => 'RecompStore', 'url' => 'https://recompstore.com/'];
    if (!empty($a['tags']) && is_array($a['tags'])) $ld['keywords'] = implode(', ', array_slice(array_map('strval', $a['tags']), 0, 12));
    if (!empty($a['author'])) $ld['author'] = ['@type' => 'Person', 'name' => (string)$a['author']];
    $repo = (string)($a['source']['repo'] ?? '');
    if (($a['source']['type'] ?? '') === 'github' && preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        $ld['downloadUrl'] = 'https://github.com/' . $repo . '/releases/latest';
        $ld['sameAs'] = 'https://github.com/' . $repo;
    }
    return $ld;
}
