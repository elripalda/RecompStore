<?php
// RecompStore website: a game's own link, recompstore.com/game/<ID> (Coming soon entries:
// /soon/<ID>). It's the site's page with the game's name, blurb and picture in its head, so the
// link shows a card when it's shared; the site's script then opens the game's page.
declare(strict_types=1);
require __DIR__ . '/admin/inc/maint.php';
rs_maint_gate('ps5');
require __DIR__ . '/admin/inc/og.php';

$id = preg_replace('/[^A-Za-z0-9_-]/', '', is_string($_GET['id'] ?? null) ? $_GET['id'] : '');
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
$name = trim((string)($a['name'] ?? $id));
$blurb = trim((string)($a['blurb'] ?? ''));
if ($blurb === '') $blurb = trim((string)($a['description'] ?? ''));
$image = $base . '/og/' . rawurlencode($id) . '.jpg?v=' . rs_og_version($a, $soon);

// (2.6) Through the site's page builder: the game's card, its language links, structured data,
// and its text and links in the page for search engines.
require __DIR__ . '/admin/inc/seo.php';
$path = ($soon ? '/soon/' : '/game/') . rawurlencode($id);
$by = rs_seo_by($a);
$full = trim((string)($a['description'] ?? ''));
$catKey = (string)($a['category'] ?? 'games') ?: 'games';
$cats = ['games' => [N_('Games'), N_('PS5 homebrew games')], 'emulators' => [N_('Emulators'), N_('PS5 emulators')],
    'apps' => [N_('Apps'), N_('PS5 homebrew apps')], 'tools' => [N_('Tools'), N_('PS5 homebrew tools')]];
if (!isset($cats[$catKey])) $catKey = 'games';
[$catName, $catTitle] = $cats[$catKey];
$repo = (string)($a['source']['repo'] ?? '');
$repoUrl = ($a['source']['type'] ?? '') === 'github' && preg_match('#^[\w.-]+/[\w.-]+$#', $repo) ? 'https://github.com/' . $repo : '';
$needs = trim((string)($a['needs'] ?? ''));

// (2.7.1) What people search for: the name with "PS5" and "homebrew", in the title and the
// description, in whichever language the page is in.
$heads = ['games' => N_('{name} — PS5 homebrew game'), 'emulators' => N_('{name} — PS5 emulator'),
    'apps' => N_('{name} — PS5 homebrew app'), 'tools' => N_('{name} — PS5 homebrew tool')];
$kinds = ['games' => N_('A PS5 homebrew game'), 'emulators' => N_('A PS5 emulator'),
    'apps' => N_('A PS5 homebrew app'), 'tools' => N_('A PS5 homebrew tool')];
$headline = $soon ? tf('{name} — coming soon to PS5', ['name' => $name]) : tf($heads[$catKey], ['name' => $name]);
$tail = $soon ? t('Coming soon to RecompStore, the homebrew store for PS5.') : t('Free PS5 homebrew, installed and kept up to date by RecompStore.');
$lead = $blurb !== '' ? rs_seo_short($blurb, max(70, 158 - mb_strlen($tail))) : '';
$desc = $lead !== '' ? $lead . (preg_match('/[.!?…]$/u', $lead) ? ' ' : '. ') . $tail : $tail;

$facts = array_filter([
    t('Version') => (string)($a['version'] ?? ''),
    t('Download') => (string)($a['size'] ?? ''),
    t('You bring') => $needs !== '' ? tf('Your {thing}', ['thing' => $needs]) : '',
]);
$body = '<p><a href="/">RecompStore</a>' . (!$soon && rs_seo_v2() ? ' › <a href="/' . $e($catKey) . '">' . $e(t($catName)) . '</a>' : '') . '</p>'
    . '<h1>' . $e($name) . '</h1>'
    . '<p>' . $e(t($kinds[$catKey])) . ($by !== '' ? ' · ' . $e(tf('By {author}', ['author' => $by])) : '') . '</p>'
    . ($soon ? '<p>' . $e(t('Coming soon')) . (($a['date'] ?? '') !== '' ? ' · ' . $e($a['date']) : '') . '</p>' : '')
    . '<p>' . $e($full !== '' ? $full : $blurb) . '</p>'
    . ($facts ? '<dl>' . implode('', array_map(fn($k, $v) => '<div><dt>' . $e($k) . '</dt><dd>' . $e($v) . '</dd></div>', array_keys($facts), $facts)) . '</dl>' : '');
$news = trim((string)($a['news'] ?? ''));
if (!$soon && $news !== '') $body .= '<h2>' . $e(t('What\'s new')) . '</h2><p>' . $e($news) . '</p>';
if (!$soon) {
    // How it gets on a PS5, step by step.
    $steps = ['<li>' . $e(t('Install RecompStore on your jailbroken PS5.')) . ' <a href="/get">' . $e(t('Get RecompStore')) . '</a></li>',
        '<li>' . $e(tf('Open RecompStore and find {name} in {category}.', ['name' => $name, 'category' => t($catName)])) . '</li>',
        '<li>' . $e($repoUrl !== '' ? t('Press Install. The store downloads it from its developer\'s GitHub release and keeps it up to date.')
                                    : t('Press Install, and the store keeps it up to date.')) . '</li>'];
    if ($needs !== '') $steps[] = '<li>' . $e(tf('Set it up from your own {thing}.', ['thing' => $needs])) . '</li>';
    $body .= '<h2>' . $e(tf('How to install {name} on PS5', ['name' => $name])) . '</h2><ol>' . implode('', $steps) . '</ol>';
}
$body .= ($repoUrl !== '' ? '<p><a href="' . $e($repoUrl) . '">' . $e(t('Source on GitHub')) . '</a></p>' : '')
    . '<p>' . $e(t('RecompStore and this game\'s GitHub repository don\'t host any game files. Set it up from your own legally obtained copy.')) . '</p>';
// More of the same kind: links that lead search engines from one app to the next.
$more = array_slice(array_values(array_filter(rs_seo_apps(), fn($x) => ((string)($x['category'] ?? 'games') ?: 'games') === $catKey && (string)$x['id'] !== $id)), 0, 8);
if ($more) $body .= '<h2><a href="/' . $e($catKey) . '">' . $e(t($catTitle)) . '</a></h2><ul>'
    . implode('', array_map(fn($x) => '<li><a href="/game/' . rawurlencode((string)$x['id']) . '">' . $e($x['name'] ?? $x['id']) . '</a></li>', $more)) . '</ul>';
$body .= '<p><a href="/get">' . $e(t('Get RecompStore')) . '</a> · <a href="/how">' . $e(t('How it works')) . '</a></p>';

$crumbs = [['@type' => 'ListItem', 'position' => 1, 'name' => 'RecompStore', 'item' => $base . '/']];
if (!$soon && rs_seo_v2()) $crumbs[] = ['@type' => 'ListItem', 'position' => 2, 'name' => t($catName), 'item' => $base . '/' . $catKey];
$crumbs[] = ['@type' => 'ListItem', 'position' => count($crumbs) + 1, 'name' => $name, 'item' => $base . $path];
$ld = ['@context' => 'https://schema.org', '@graph' => [
    rs_seo_app_ld($a, $base . $path, $image),
    ['@type' => 'BreadcrumbList', 'itemListElement' => $crumbs],
]];
if ($soon) unset($ld['@graph'][0]['offers'], $ld['@graph'][0]['downloadUrl']);
echo rs_seo_render($html, [
    'title' => $headline . ' · RecompStore', 'ogTitle' => $name, 'desc' => $desc, 'path' => $path,
    'image' => $image, 'imageW' => RS_OG_W, 'imageH' => RS_OG_H, 'imageAlt' => $name . ' on RecompStore',
    'jsonld' => $ld, 'body' => $body,
]);
