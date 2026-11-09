<?php
// RecompStore website (2.6): the home page and the site's own pages at their own addresses
// (/games, /how, /get, /about, /dev/<name>...). Each is the site's page with its title, description,
// language links and text for search engines and link previews; the site's script then draws it
// as usual. Anything going wrong here still gives the plain page.
declare(strict_types=1);

// (2.9) Maintenance mode: while a side is down, its pages show only the logo and the admin's message.
$p0 = is_string($_GET['p'] ?? null) ? $_GET['p'] : 'home';
require __DIR__ . '/admin/inc/maint.php';
rs_maint_gate(str_starts_with($p0, 'pc') ? 'pc' : 'ps5');

$page = (string)file_get_contents(__DIR__ . '/index.html');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
// A code a PS5 shows (/l/<code>, /r/<code>): the site's own page as it is; its script reads the code.
if ($p0 === 'code') { echo $page; exit; }

try {
    require __DIR__ . '/admin/inc/seo.php';
    $e = 'rs_seo_e';
    $p = preg_replace('/[^a-z0-9-]/', '', is_string($_GET['p'] ?? null) ? $_GET['p'] : 'home');
    $base = rs_seo_base();
    $cat = rs_seo_catalog();
    $site = is_array($cat['site'] ?? null) ? $cat['site'] : [];
    $apps = rs_seo_apps();
    $soon = rs_seo_soon();
    $nav = rs_seo_nav();
    $name = fn(string $title) => $title . ' · RecompStore';
    $byo = '<p><strong>' . $e(t('No game files here.')) . '</strong> ' . $e(t('RecompStore and the GitHub repositories it links to host only the ports and their tools. Every game needs your own copy.')) . '</p>';
    $cats = ['games' => [N_('Games'), N_('PS5 homebrew games'), N_('Recompiled games and ports for jailbroken PS5, ready to install from RecompStore.')],
        'emulators' => [N_('Emulators'), N_('PS5 emulators'), N_('Emulators for jailbroken PS5, ready to install from RecompStore.')],
        'apps' => [N_('Apps'), N_('PS5 homebrew apps'), N_('Homebrew apps for jailbroken PS5: media, streaming and more, ready to install from RecompStore.')],
        'tools' => [N_('Tools'), N_('PS5 homebrew tools'), N_('Tools for jailbroken PS5: files, payloads and mods, ready to install from RecompStore.')]];
    $inCat = fn(string $k) => array_values(array_filter($apps, fn($a) => ((string)($a['category'] ?? 'games') ?: 'games') === $k));
    $list = fn(array $items) => ['@type' => 'ItemList', 'itemListElement' => array_map(fn($a, $i) => ['@type' => 'ListItem', 'position' => $i + 1,
        'url' => $base . '/game/' . rawurlencode((string)$a['id']), 'name' => (string)($a['name'] ?? $a['id'])], $items, array_keys($items))];
    $m = null;

    if ($p === 'home') {
        $body = '<h1>' . $e(!empty($site['tagline']) ? t((string)$site['tagline']) : t('Recompiled games, native on PS5.')) . '</h1>'
            . '<p>' . $e(t('Install and update PS5 ports of recompiled games. No account, no game files: bring your own disc.')) . '</p>' . $nav;
        if (rs_seo_v2()) {
            foreach ($cats as $k => [$label]) {
                $items = $inCat($k);
                if ($items) $body .= '<h2><a href="/' . $k . '">' . $e(t($label)) . '</a></h2>' . rs_seo_app_list($items);
            }
        } else {
            $body .= '<h2>' . $e(t('Latest games')) . '</h2>' . rs_seo_app_list($apps);
        }
        if ($soon) $body .= '<h2><a href="/soon">' . $e(t('Coming soon')) . '</a></h2>' . rs_seo_app_list($soon, true);
        $m = ['title' => t('RecompStore — PS5 homebrew and jailbreak app store'), 'path' => '/', 'body' => $body . $byo,
            'desc' => t('Free PS5 homebrew and jailbreak app store: recompiled games, emulators, apps and tools for jailbroken PS5, installed and kept up to date from your console.')];
    } elseif (isset($cats[$p])) {
        [$label, $title, $desc] = $cats[$p];
        $items = $inCat($p);
        $m = ['title' => $name(t($title)), 'ogTitle' => t($title), 'desc' => t($desc), 'path' => '/' . $p,
            'body' => '<h1>' . $e(t($label)) . '</h1><p>' . $e(t($desc)) . '</p>' . $nav . rs_seo_app_list($items) . $byo,
            'jsonld' => ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => t($title), 'url' => $base . '/' . $p, 'mainEntity' => $list($items)],
            'noindex' => !rs_seo_v2() || !$items];
    } elseif ($p === 'how') {
        $steps = [
            [t('Install RecompStore'), t('Send the installer to your jailbroken PS5\'s payload loader. The store lands on your home screen and keeps itself up to date.')],
            [t('Pick a game'), t('Browse the store and install a port. It comes straight from its developer\'s GitHub release.')],
            [t('Bring your own backup'), t('Back up a game you own: a disc image, or the game\'s file from your console. Copy it to a USB drive or your PS5.')],
            [t('Set it up'), t('On the game\'s page, choose your backup under Your game files. The store puts every file where the port expects it, and can fetch a Title Update the game needs.')],
            [t('Play'), t('Start it from the store or your home screen. Updates keep your game files and saves.')],
        ];
        $faq = [
            [t('Is it free?'), t('Yes. RecompStore and everything in it are free. Some developers take donations on their own pages.')],
            [t('Do I need an account?'), t('No. The store works without one. Linking a phone or PC is optional and only needs a code from your PS5.')],
            [t('Where do the apps come from?'), t('Each one comes from its developer\'s own GitHub release, checked against the store\'s list before it\'s installed.')],
            [t('What does Bring your files mean?'), t('The port needs files from a game you own. On the game\'s page in the store, choose them under Your game files and the store sets everything up.')],
            [t('Do updates keep my saves?'), t('Yes. An update replaces only the app. Your game files, saves, DLC and mods stay where they are.')],
        ];
        $m = ['title' => $name(t('How it works')), 'path' => '/how',
            'desc' => t('Install RecompStore once. After that, every game, emulator, app and tool installs and updates from your PS5.'),
            'body' => '<h1>' . $e(t('From your own copy to your PS5, in five steps')) . '</h1>' . $nav
                . '<ol>' . implode('', array_map(fn($s) => '<li><strong>' . $e($s[0]) . '</strong> ' . $e($s[1]) . '</li>', $steps)) . '</ol>'
                . '<h2>' . $e(t('Questions')) . '</h2><dl>' . implode('', array_map(fn($q) => '<dt>' . $e($q[0]) . '</dt><dd>' . $e($q[1]) . '</dd>', $faq)) . '</dl>' . $byo,
            'jsonld' => ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($q) => ['@type' => 'Question', 'name' => $q[0],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]], $faq)]];
    } elseif ($p === 'get') {
        $payload = is_string($site['payload'] ?? null) && preg_match('#^https://#', $site['payload']) ? $site['payload'] : '';
        $m = ['title' => $name(t('How to install RecompStore on a jailbroken PS5')), 'ogTitle' => t('Get RecompStore'), 'path' => '/get',
            'desc' => t('How to install RecompStore on a jailbroken PS5: download the installer payload, send it to your PS5, and open the store from your home screen.'),
            'body' => '<h1>' . $e(t('Get RecompStore')) . '</h1><p>' . $e(t('Installs once, then keeps itself and your games up to date.')) . '</p>' . $nav . '<ol>'
                . '<li><strong>' . $e(t('Jailbreak your PS5 and start your payload loader.')) . '</strong> ' . $e(t('Any loader on port 9021 works, such as etaHEN\'s or elfldr. You\'ll also need kstuff and ShadowMountPlus 1.7 Beta 5 or newer.')) . '</li>'
                . '<li><strong>' . $e(t('Download the installer payload.')) . '</strong> ' . ($payload !== '' ? '<a href="' . $e($payload) . '">RecompStore-Installer.elf</a>' : '') . '</li>'
                . '<li><strong>' . $e(t('Send it to your PS5.')) . '</strong> ' . $e(t('Use any payload sender, or from a computer on the same network:')) . ' <code>nc -N &lt;your PS5\'s IP&gt; 9021 &lt; RecompStore-Installer.elf</code></li>'
                . '<li><strong>' . $e(t('Open RecompStore from your home screen.')) . '</strong> ' . $e(t('The installer has ShadowMountPlus add it. From then on the store updates itself.')) . '</li></ol>'
                . '<p>' . t('Download the RecompStore zip from <a href="https://github.com/elripalda/RecompStore/releases/latest" target="_blank" rel="noopener">its GitHub releases</a>, unzip it, and copy the folder into <code>/data/homebrew/</code> on your PS5 over FTP. Then register it the way you register your other homebrew.') . '</p>' . $byo];
    } elseif ($p === 'about') {
        $m = ['title' => $name(t('About')), 'path' => '/about',
            'desc' => rs_seo_short(t('RecompStore doesn\'t host any game files or copyrighted game content, and neither do the GitHub repositories it links to. They hold only the ports and their tools. Every game needs your own legally obtained copy, which you set up yourself.'), 200),
            'body' => '<h1>RecompStore</h1>' . $nav . '<p>' . $e(t('RecompStore doesn\'t host any game files or copyrighted game content, and neither do the GitHub repositories it links to. They hold only the ports and their tools. Every game needs your own legally obtained copy, which you set up yourself.')) . '</p>'
                . '<p>' . $e(t('RecompStore and the games listed in it aren\'t affiliated with or endorsed by Sony Interactive Entertainment, Microsoft, or any game\'s publisher or developer. All trademarks belong to their owners.')) . '</p>'];
    } elseif ($p === 'contact') {
        $m = ['title' => $name(t('Contact')), 'path' => '/contact',
            'desc' => t('Found a bug, made a recomp, or have a question? Here\'s how to reach us.'),
            'body' => '<h1>' . $e(t('Contact')) . '</h1><p>' . $e(t('Found a bug, made a recomp, or have a question? Here\'s how to reach us.')) . '</p>'
                . '<p><a href="mailto:info@recompstore.com">info@recompstore.com</a></p>' . $nav];
    } elseif ($p === 'press') {
        // (2.7.1) The press kit: badges, logos, colors and words for developers.
        $m = ['title' => $name(t('Press kit')), 'path' => '/press',
            'desc' => t('The RecompStore press kit: Get it on RecompStore badges, logos, colors and words for developers of PS5 homebrew.'),
            'image' => $base . '/assets/press/badge-dark.png', 'imageW' => 702, 'imageH' => 168, 'imageAlt' => 'Get it on RecompStore',
            'body' => '<h1>' . $e(t('Press kit')) . '</h1><p>' . $e(t('Badges, logos and words for telling people where to get your app.')) . '</p>'
                . '<p><img src="/assets/press/badge-dark.svg" alt="Get it on RecompStore" width="234" height="56"></p>'
                . '<p><a href="/assets/press/recompstore-press-kit.zip">' . $e(t('Download everything (.zip)')) . '</a></p>'
                . '<h2>' . $e(t('Using the name and logo')) . '</h2><ul><li>' . $e(t('Write the name as one word: RecompStore.')) . '</li><li>'
                . $e(t('Use the badge and logos as they are. Don\'t recolor, stretch or redraw them.')) . '</li><li>'
                . $e(t('Link the badge to your app\'s page on RecompStore, so players land in the right place.')) . '</li></ul>'
                . '<p><a href="/submit">' . $e(t('Submit an app')) . '</a></p>' . $nav];
    } elseif ($p === 'developers') {
        // (2.7.2) Developer accounts: what they are, and signing in with GitHub.
        $m = ['title' => $name(t('For developers')), 'path' => '/developers',
            'desc' => t('A developer account on RecompStore gives you a verified page, your numbers, your players\' logs and betas for your apps. It\'s free, and we approve every account by hand.'),
            'body' => '<h1>' . $e(t('Your apps, your page, your players')) . '</h1><p>' . $e(t('A developer account on RecompStore gives you a verified page, your numbers, your players\' logs and betas for your apps. It\'s free, and we approve every account by hand.')) . '</p>'
                . '<ul><li>' . $e(t('A verified developer page')) . '</li><li>' . $e(t('Your numbers')) . '</li><li>' . $e(t('Your players\' logs')) . '</li><li>' . $e(t('Support from players')) . '</li><li>' . $e(t('Betas for your apps')) . '</li></ul>'
                . '<p><a href="/press">' . $e(t('Press kit')) . '</a> · <a href="/submit">' . $e(t('Submit an app')) . '</a></p>' . $nav];
    } elseif ($p === 'dmca') {
        // (2.9) Copyright & DMCA (in English): no games here, and how to send a notice.
        $m = ['title' => 'Copyright & DMCA · RecompStore', 'path' => '/dmca', 'langs' => false,
            'desc' => 'RecompStore and RecompLauncher don\'t host any games or game files. Every listing is a developer\'s own project on GitHub. How to send a copyright notice or counter-notice.',
            'body' => '<h1>Copyright &amp; DMCA</h1><h2>We don\'t host games.</h2><p>RecompStore and RecompLauncher don\'t host, sell or share any games. No ROMs, no ISOs, no disc images, no game files and no game content of any kind. Each listing points to a fan project published by its own developer on GitHub, and every one of them needs your own legally owned copy of the game.</p><p>To report a copyright problem, use the form on this page or email info@recompstore.com.</p>' . $nav];
    } elseif ($p === 'hub') {
        $m = ['title' => $name(t('Developer hub')), 'path' => '/hub', 'noindex' => true, 'desc' => t('Your developer hub on RecompStore.'), 'body' => '<h1>' . $e(t('Developer hub')) . '</h1>' . $nav];
    } elseif ($p === 'beta') {
        // (2.7.1) Betas: what's in testing and on the way, with codes through Discord.
        $m = ['title' => $name(t('Betas')), 'path' => '/beta',
            'desc' => t('Try new games and apps before everyone else. Sign in with Discord to get a code, then unlock the beta in RecompStore.'),
            'body' => '<h1>' . $e(t('Test what\'s next')) . '</h1><p>' . $e(t('Try new games and apps before everyone else. Sign in with Discord to get a code, then unlock the beta in RecompStore.')) . '</p>'
                . '<ol><li>' . $e(t('Sign in with Discord. You need to be in the RecompStore Discord server.')) . '</li><li>' . $e(t('Get a code for an open beta. Each code works on one PS5.')) . '</li><li>'
                . $e(t('On your PS5, open RecompStore, go to Settings and choose Redeem a code. If this phone is linked to your PS5, you can unlock it from here.')) . '</li></ol>' . $nav];
    } elseif ($p === 'submit') {
        $m = ['title' => $name(t('Submit to RecompStore')), 'path' => '/submit',
            'desc' => rs_seo_short(t('Made something for jailbroken PS5? Send it in. Everything you fill in here goes on its page in the store, so the more you give, the faster it can go live. Only the RecompStore developer sees this form.'), 200),
            'body' => '<h1>' . $e(t('Submit to RecompStore')) . '</h1><p>' . $e(t('A game port, an emulator, an app or a tool: send its GitHub, details and pictures, and it can go straight onto its own page.')) . '</p>' . $nav];
    } elseif ($p === 'report') {
        $m = ['title' => $name(t('Report a bug')), 'path' => '/report',
            'desc' => t('Tell us what went wrong and which game it was in. The more you can say about what you did right before, the faster it gets fixed.'),
            'body' => '<h1>' . $e(t('Report a bug')) . '</h1><p>' . $e(t('Pick the game and tell us what happened. It goes straight to the developer.')) . '</p>' . $nav];
    } elseif ($p === 'ids') {
        $m = ['title' => $name(t('Title IDs')), 'path' => '/ids', 'noindex' => !rs_seo_v2(),
            'desc' => t('Two apps with the same title ID clash on a PS5: installing one writes over the other. Check yours here before you release.'),
            'body' => '<h1>' . $e(t('Title IDs')) . '</h1><p>' . $e(t('Two apps with the same title ID clash on a PS5: installing one writes over the other. Check yours here before you release.')) . '</p>' . $nav];
    } elseif ($p === 'soon') {
        $m = ['title' => $name(t('Coming soon')), 'path' => '/soon', 'noindex' => !$soon,
            'desc' => t('Games, emulators, apps and tools on their way to RecompStore.'),
            'body' => '<h1>' . $e(t('On the way to RecompStore')) . '</h1>' . $nav . rs_seo_app_list($soon, true)];
    } elseif ($p === 'link' || $p === 'my-ps5') {
        $m = ['title' => $name($p === 'link' ? t('Link your PS5') : t('My PS5')), 'path' => '/' . $p, 'noindex' => true,
            'desc' => t('Install from your phone or computer straight to your PS5 with RecompStore.')];
    } elseif ($p === 'dev') {
        $slug = preg_replace('/[^a-z0-9-]/', '', is_string($_GET['slug'] ?? null) ? $_GET['slug'] : '');
        $dev = null;
        foreach (rs_seo_devs() as $d) if ($d['slug'] === $slug) $dev = $d;
        if ($dev === null) {
            http_response_code(404);
            $m = ['title' => 'RecompStore', 'path' => '/', 'noindex' => true, 'desc' => t('That page isn\'t here.')];
        } else {
            $mine = array_values(array_filter($apps, fn($a) => ($a['dev'] ?? '') === $slug || in_array((string)($a['id'] ?? ''), (array)($dev['apps'] ?? []), true)));
            $dn = (string)($dev['name'] ?? $slug);
            $about = trim((string)($dev['about'] ?? ''));
            $m = ['title' => $name($dn), 'ogTitle' => $dn, 'path' => '/dev/' . $slug, 'noindex' => !$mine,
                'desc' => $about !== '' ? rs_seo_short($about) : tf('Apps by {name} in RecompStore.', ['name' => $dn]),
                'image' => is_string($dev['avatar'] ?? null) && preg_match('#^https?://#', $dev['avatar']) ? $dev['avatar'] : null,
                'imageW' => 512, 'imageH' => 512,
                'body' => '<h1>' . $e($dn) . '</h1>' . ($about !== '' ? '<p>' . $e($about) . '</p>' : '') . $nav . rs_seo_app_list($mine),
                'jsonld' => ['@context' => 'https://schema.org', '@type' => 'ProfilePage', 'url' => $base . '/dev/' . $slug,
                    'mainEntity' => array_filter(['@type' => 'Person', 'name' => $dn, 'description' => $about ?: null,
                        'sameAs' => array_values(array_filter((array)($dev['links'] ?? []), fn($u) => is_string($u) && preg_match('#^https://#', $u))) ?: null])]];
            if ($m['image'] === null) unset($m['image'], $m['imageW'], $m['imageH']);
        }
    }
    // (2.8) The PC side, RecompLauncher (2.9): recomplauncher.com, /all, /how, /game/<id>, /get (the
    // app). Its addresses drop the /pc (RecompStore's old /pc links forward there).
    if ($m === null && str_starts_with($p, 'pc')) {
        $L = rs_launcher();
        $pp = fn(string $u): string => !$L ? $u : ($u === '/pc/launcher' ? '/get' : (preg_replace('#^/pc#', '', $u) ?: '/'));
        $name = fn(string $title) => $title . ' · RecompLauncher';
        require_once __DIR__ . '/admin/inc/catcache.php'; // (4.1) opcache's copy, not decoded per page
        $pc = rs_json_cached(__DIR__ . '/pc-catalog.json');
        $pcApps = is_array($pc['apps'] ?? null) ? $pc['apps'] : [];
        $pcByo = '<p><strong>' . $e(t('No game files here')) . '</strong> ' . $e(t('RecompLauncher lists only each recomp, from its developer\'s own GitHub. Every game needs your own legally owned copy.')) . '</p>';
        $pcNav = '<nav><a href="' . $pp('/pc') . '">RecompLauncher</a> · <a href="' . $pp('/pc/all') . '">' . $e(t('All PC recomps')) . '</a> · <a href="' . $pp('/pc/how') . '">' . $e(t('How it works')) . '</a> · <a href="' . $e(rs_store_base()) . '/">RecompStore for PS5</a></nav>';
        $pcList = '<ul>' . implode('', array_map(fn($a) => '<li><a href="' . $pp('/pc/game/') . rawurlencode((string)$a['id']) . '">' . $e((string)$a['name']) . '</a>'
            . (!empty($a['game']) && $a['game'] !== $a['name'] ? ' — ' . $e((string)$a['game']) : '') . '</li>', $pcApps)) . '</ul>';
        $pcDesc = t('Console games rebuilt to run natively on Windows, Linux and Mac. Bring your own copy, and play.');
        if ($p === 'pc') {
            // (4.1) People search "recomp launcher", two words: the title, description, text and structured
            // data all say it that way too, next to RecompLauncher.
            $en = in_array(rs_seo_lang(), ['', 'en'], true);
            $aka = 'RecompLauncher (Recomp Launcher)';
            $homeDesc = $en ? 'RecompLauncher (Recomp Launcher) is a free, open-source launcher for PC recomps: console games rebuilt to run natively on Windows, Linux and Mac. Install them in one click and keep them up to date.' : $pcDesc;
            $m = ['title' => preg_replace('/^RecompLauncher/', $aka, t('RecompLauncher — recompiled games for Windows, Linux and Mac')), 'ogTitle' => 'RecompLauncher', 'path' => $pp('/pc'), 'desc' => $homeDesc, 'noindex' => !$pcApps,
                'body' => '<h1>' . $e(t('Recompiled games, native on PC.')) . '</h1><p>' . $e($homeDesc) . '</p><p>' . $e(t('Install and update recompiled PC games. No account, no game files: bring your own disc.')) . '</p>' . $pcNav . $pcList . $pcByo,
                'jsonld' => ['@context' => 'https://schema.org', '@graph' => [
                    ['@type' => 'WebSite', '@id' => $base . '/#site', 'name' => 'RecompLauncher', 'alternateName' => ['Recomp Launcher', 'RecompLauncher app', 'PC recomp launcher', 'Recompiled PC games'], 'url' => $base . '/'],
                    ['@type' => 'SoftwareApplication', '@id' => $base . '/#app', 'name' => 'RecompLauncher', 'alternateName' => 'Recomp Launcher',
                        'description' => 'A free, open-source launcher for PC recomps: it installs recompiled console games, keeps them up to date and sets them up from your own copy.',
                        'applicationCategory' => 'GameApplication', 'operatingSystem' => 'Windows, Linux, macOS', 'url' => $base . '/', 'downloadUrl' => $base . '/get',
                        'image' => $base . '/assets/launcher-card.png', 'isAccessibleForFree' => true, 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD'],
                        'sameAs' => ['https://github.com/elripalda/RecompLauncher']]]]];
        } elseif ($p === 'pc-all') {
            $m = ['title' => $name(t('All PC recomps')), 'path' => $pp('/pc/all'), 'desc' => $pcDesc, 'noindex' => !$pcApps,
                'body' => '<h1>' . $e(t('All PC recomps')) . '</h1>' . $pcNav . $pcList . $pcByo];
        } elseif (isset(($pcCats = ['pc-action' => ['action', N_('Action')], 'pc-adventure' => ['adventure', N_('Adventure')], 'pc-platformers' => ['platformer', N_('Platformers')],
            'pc-racing' => ['racing', N_('Racing')], 'pc-fighting' => ['fighting', N_('Fighting')], 'pc-shooters' => ['shooter', N_('Shooters')], 'pc-sports' => ['sports', N_('Sports')],
            'pc-rpg' => ['rpg', N_('RPG')], 'pc-puzzle' => ['puzzle', N_('Puzzle')]])[$p])) {
            $g = $pcCats[$p];
            $mine = array_values(array_filter($pcApps, fn($a) => ($a['genre'] ?? '') === $g[0]));
            $list = '<ul>' . implode('', array_map(fn($a) => '<li><a href="' . $pp('/pc/game/') . rawurlencode((string)$a['id']) . '">' . $e((string)$a['name']) . '</a>'
                . (!empty($a['game']) && $a['game'] !== $a['name'] ? ' — ' . $e((string)$a['game']) : '') . '</li>', $mine)) . '</ul>';
            $m = ['title' => $name(t($g[1])), 'ogTitle' => $name(t($g[1])), 'path' => $pp('/' . str_replace('pc-', 'pc/', $p)), 'desc' => $pcDesc, 'noindex' => !$mine,
                'body' => '<h1>' . $e(t($g[1])) . '</h1>' . $pcNav . $list . $pcByo];
        } elseif ($p === 'pc-soon') {
            $soon = array_values(array_filter($pcApps, fn($a) => !empty($a['soon'])));
            $m = ['title' => $name(t('Coming soon')), 'path' => $pp('/pc/soon'), 'desc' => t('Recomps being worked on now. Nothing to download yet: follow them on GitHub to see when they land.'), 'noindex' => !$soon,
                'body' => '<h1>' . $e(t('Coming soon')) . '</h1><ul>' . implode('', array_map(fn($a) => '<li><a href="' . $pp('/pc/game/') . rawurlencode((string)$a['id']) . '">' . $e((string)$a['name']) . '</a></li>', $soon)) . '</ul>' . $pcNav . $pcByo];
        } elseif ($p === 'pc-settings') {
            $m = ['title' => $name(t('Settings')), 'path' => $pp('/pc/settings'), 'noindex' => true, 'desc' => t('Saved in this browser.'), 'body' => '<h1>' . $e(t('Settings')) . '</h1>' . $pcNav];
        } elseif ($p === 'pc-sdk') {
            // (3.6) The RecompLauncher SDK, public.
            $m = ['title' => $name('RecompLauncher SDK'), 'ogTitle' => 'RecompLauncher SDK', 'path' => $pp('/pc/sdk'),
                'desc' => t('One small file in your release, recomplauncher.json, and your recomp installs, sets itself up and shows its settings in RecompLauncher like a native PC game.'),
                'body' => '<h1>RecompLauncher SDK</h1><p>' . $e(t('One small file in your release, recomplauncher.json, and your recomp installs, sets itself up and shows its settings in RecompLauncher like a native PC game.')) . '</p><p><a href="/sdk-files/guide.md">' . $e(t('Read the guide')) . '</a></p>' . $pcNav];
        } elseif ($p === 'pc-press') {
            $m = ['title' => $name(t('Press kit')), 'path' => $pp('/pc/press'), 'desc' => t('Badges, logos and words for telling people where to get your app.'),
                'image' => $base . '/assets/press/launcher/badge-dark.png', 'imageW' => 834, 'imageH' => 168, 'imageAlt' => 'Get it on RecompLauncher',
                'body' => '<h1>' . $e(t('Press kit')) . '</h1><p><img src="/assets/press/launcher/badge-dark.svg" alt="Get it on RecompLauncher" height="56"></p><p><a href="/assets/press/launcher/recomplauncher-press-kit.zip">' . $e(t('Download everything (.zip)')) . '</a></p>' . $pcNav];
        } elseif (in_array($p, ['pc-top', 'pc-new', 'pc-consoles', 'pc-launcher'], true)) {
            $h1 = ['pc-top' => t('Top charts'), 'pc-new' => t('New'), 'pc-consoles' => t('Consoles'), 'pc-launcher' => t('Get RecompLauncher')][$p];
            // (4.1) The download page, as people search for it.
            if ($p === 'pc-launcher' && in_array(rs_seo_lang(), ['', 'en'], true)) {
                $m = ['title' => 'Download RecompLauncher (Recomp Launcher) for Windows, Linux and Mac', 'ogTitle' => 'Get RecompLauncher', 'path' => $pp('/pc/launcher'),
                    'desc' => 'Download RecompLauncher, the free Recomp Launcher for Windows, Linux and Mac (Apple Silicon): one app that installs PC recomps, keeps them up to date and sets them up from your own copy.',
                    'body' => '<h1>' . $e($h1) . '</h1><p>Download RecompLauncher, the free Recomp Launcher for Windows, Linux and Mac (Apple Silicon): one app that installs PC recomps, keeps them up to date and sets them up from your own copy.</p>' . $pcNav . $pcByo];
            }
            $d = $p === 'pc-launcher' ? t('One app for every PC recomp. It installs them, keeps them up to date, sets them up from your own copy, and gives every game the same simple settings.') : $pcDesc;
            if ($m === null) $m = ['title' => $name($h1), 'ogTitle' => $name($h1), 'path' => $pp('/' . str_replace('pc-', 'pc/', $p)), 'desc' => $d, 'noindex' => !$pcApps,
                'body' => '<h1>' . $e($h1) . '</h1><p>' . $e($d) . '</p>' . $pcNav . ($p === 'pc-launcher' ? '' : $pcList) . $pcByo];
        } elseif ($p === 'pc-how') {
            $m = ['title' => $name(t('How it works')) , 'path' => $pp('/pc/how'),
                'desc' => t('RecompLauncher is a home for recompiled games: find them, see what they need, and get them from their developers.'),
                'body' => '<h1>' . $e(t('How it works')) . '</h1><p>' . $e(t('RecompLauncher is a home for recompiled games: find them, see what they need, and get them from their developers.')) . '</p>' . $pcNav . $pcByo];
        } elseif ($p === 'pc-game') {
            $id = preg_replace('/[^a-z0-9-]/', '', is_string($_GET['id'] ?? null) ? $_GET['id'] : '');
            $a = null;
            foreach ($pcApps as $x) if (($x['id'] ?? '') === $id) $a = $x;
            if ($a !== null) {
                $an = (string)$a['name'];
                $gn = (string)($a['game'] ?? '') ?: $an;
                $about = trim((string)($a['blurb'] ?? '')) ?: tf('{name} is a PC recomp of {game}. Bring your own copy of the game.', ['name' => $an, 'game' => $gn]);
                $m = ['title' => $name(tf('{name} — PC recomp of {game}', ['name' => $an, 'game' => $gn])), 'ogTitle' => $an, 'path' => $pp('/pc/game/') . $id,
                    'desc' => rs_seo_short($about), 'image' => !empty($a['hero']) ? (string)$a['hero'] : (!empty($a['cover']) ? (string)$a['cover'] : null),
                    'body' => '<h1>' . $e($an) . '</h1><p>' . $e($about) . '</p>' . $pcNav
                        . (!empty($a['repo']) ? '<p><a href="' . $e((['gitlab' => 'https://gitlab.com/', 'sourceforge' => 'https://sourceforge.net/projects/'][(string)($a['host'] ?? '')] ?? 'https://github.com/') . (string)$a['repo']) . '">' . $e(['gitlab' => t('Source on GitLab'), 'sourceforge' => t('Files on SourceForge')][(string)($a['host'] ?? '')] ?? t('Source on GitHub')) . '</a></p>' : '') . $pcByo, // (3.9)
                    'jsonld' => ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => $an, 'url' => $base . $pp('/pc/game/') . $id,
                        'applicationCategory' => 'GameApplication', 'operatingSystem' => implode(', ', array_map(fn($s) => ['windows' => 'Windows', 'linux' => 'Linux', 'macos' => 'macOS'][$s] ?? $s, (array)($a['systems'] ?? []))),
                        'offers' => ['@type' => 'Offer', 'price' => 0, 'priceCurrency' => 'USD']]];
                if ($m['image'] === null) unset($m['image']);
            }
        }
    }
    if ($m === null) { http_response_code(404); $m = ['title' => rs_launcher() ? 'RecompLauncher' : 'RecompStore', 'path' => '/', 'noindex' => true, 'desc' => t('That page isn\'t here.')]; }
    echo rs_seo_render($page, $m);
} catch (Throwable $err) {
    echo $page;
}
