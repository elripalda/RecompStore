<?php
// RecompStore admin (4.8): the search box at the top of every page. ?q= -> JSON groups of matches:
// players (name, email, Discord/GitHub id), PC recomps, PS5 games, developers and admin pages.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
rs_require_login();
header('Content-Type: application/json');
header('Cache-Control: no-store');
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$out = [];
if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'groups' => []]); exit; }
$lq = mb_strtolower($q);
$has = fn(string ...$hay) => (bool)array_filter($hay, fn($x) => $x !== '' && str_contains(mb_strtolower($x), $lq));

// Admin pages
$pages = [['dashboard.php', 'Overview'], ['submissions.php', 'Submissions'], ['auto.php', 'Auto-add'], ['dev-accounts.php', 'Developer requests'], ['security.php', 'Security'],
    ['inbox.php', 'Inbox'], ['inbox.php?show=logs', 'Player logs'], ['players.php', 'Players'], ['accounts.php', 'Sign-in & moderation'], ['mail.php', 'Mail'], ['developers.php', 'Developer pages'],
    ['messages.php', 'Developer messages'], ['community.php', 'Discord & notes'], ['docs.php', 'Docs'], ['pc.php', 'PC recomps'], ['pc.php?view=found', 'Found on GitHub (PC)'],
    ['pc.php?view=reports', 'Player reports'], ['titles.php', 'Versions'], ['boot.php', 'Boot profiles'], ['achievements.php', 'Achievements'], ['pc-banner.php', 'Banner'], ['ai.php', 'AI check'],
    ['index.php', 'PS5 games'], ['edit.php', 'Add a PS5 game'], ['discover.php', 'Discover'], ['collections.php', 'Collections'], ['beta.php', 'PS5 beta'], ['health.php', 'Found on GitHub (PS5)'],
    ['launcher.php', 'Launcher'], ['launcher-stats.php', 'Launcher live stats'], ['launcher-beta.php', 'Launcher beta'], ['sdk-support.php', 'SDK support'], ['analytics.php', 'Analytics'],
    ['status.php', 'Server health'], ['statcard.php', 'Stats card'], ['log.php', 'Change log'], ['sponsors.php', 'Sponsors'], ['team.php', 'Team'], ['settings.php', 'Settings'], ['account.php', 'My account']];
$pg = [];
foreach ($pages as [$href, $label]) if ($has($label) && rs_can_page($href)) $pg[] = ['href' => $href, 'label' => $label, 'note' => 'Page'];
if ($pg) $out[] = ['title' => 'Pages', 'items' => array_slice($pg, 0, 5)];

// Players
if (rs_can_page('players.php')) {
    try {
        require_once __DIR__ . '/inc/accounts.php';
        rs_acct_tables();
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $lq) . '%';
        $sql = 'SELECT id, username, email, email_ok, last_seen FROM accounts WHERE username_lc LIKE ? OR email_lc LIKE ?';
        $args = [$like, $like];
        if (ctype_digit($q)) { $sql .= ' OR discord_id = ? OR github_id = ? OR id = ?'; array_push($args, $q, $q, (int)$q); }
        $st = rs_db()->prepare($sql . ' ORDER BY (username_lc = ?) DESC, last_seen DESC LIMIT 6');
        $st->execute(array_merge($args, [$lq]));
        $pl = [];
        foreach ($st->fetchAll() as $r) $pl[] = ['href' => 'players.php?id=' . (int)$r['id'], 'label' => (string)($r['username'] ?? '(no name yet)'), 'note' => (string)$r['email'] . ($r['email_ok'] ? '' : ' · not verified')];
        if ($pl) $out[] = ['title' => 'Players', 'items' => $pl];
    } catch (Throwable $e) { /* accounts not set up */ }
}

// PC recomps
if (rs_can_page('pc.php')) {
    $pc = [];
    foreach (rs_pc_apps() as $a) {
        if (!$has((string)$a['name'], (string)$a['repo'], (string)$a['id'], (string)($a['game'] ?? ''))) continue;
        $pc[] = ['href' => 'pc.php?edit=' . rawurlencode((string)$a['id']) . '#edit', 'label' => (string)$a['name'], 'note' => (string)$a['repo'] . (!empty($a['hidden']) ? ' · hidden' : '')];
        if (count($pc) >= 6) break;
    }
    if ($pc) $out[] = ['title' => 'PC recomps', 'items' => $pc];
}

// PS5 games
if (rs_can_page('index.php')) {
    $ps = [];
    foreach (rs_games() as $g) {
        if (!$has((string)$g['name'], (string)$g['id'], (string)($g['repo'] ?? ''))) continue;
        $ps[] = ['href' => 'edit.php?id=' . rawurlencode((string)$g['id']), 'label' => (string)$g['name'], 'note' => (string)$g['id'] . (!empty($g['hidden']) ? ' · hidden' : '')];
        if (count($ps) >= 6) break;
    }
    if ($ps) $out[] = ['title' => 'PS5 games', 'items' => $ps];
}

// Developers
if (rs_can_page('developers.php')) {
    $dv = [];
    foreach (rs_developers() as $slug => $d) {
        if (!$has((string)$d['name'], (string)$slug, (string)($d['aliases'] ?? ''))) continue;
        $dv[] = ['href' => 'developers.php?edit=' . rawurlencode((string)$slug), 'label' => (string)$d['name'], 'note' => '/dev/' . $slug . (!empty($d['verified']) ? ' · verified' : '')];
        if (count($dv) >= 4) break;
    }
    if ($dv) $out[] = ['title' => 'Developers', 'items' => $dv];
}
echo json_encode(['ok' => true, 'groups' => $out], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
