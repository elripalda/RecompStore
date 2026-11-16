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

// (4.10) A GitHub / GitLab link: where that repo stands with us, and a way to queue it when it's new.
if (rs_can_page('pc.php') && preg_match('#(github\.com|gitlab\.com|sourceforge\.net|codeberg\.org|^[\w.-]+/[\w.-]+$)#i', $q)) {
    require_once __DIR__ . '/inc/pc.php';
    $rsq = rs_pc_repo_status($q);
    if ($rsq) {
        $it = [];
        if ($a = $rsq['app']) {
            $hw = rs_pc_hidden_why($a);
            $it[] = ['href' => 'pc.php?edit=' . rawurlencode((string)$a['id']) . '#edit', 'label' => 'Already listed: ' . $a['name'],
                'note' => !empty($a['soon']) ? 'Coming soon' : ($hw !== '' ? 'Hidden · ' . $hw : 'Live on the store')];
        } elseif ($rsq['auto'] && in_array($rsq['auto'], ['queued', 'running', 'review', 'failed'], true)) {
            $it[] = ['href' => 'auto.php', 'label' => 'On Auto-add', 'note' => ['queued' => 'Waiting to be checked', 'running' => 'Being checked now', 'review' => 'Checked, waiting for someone to Publish', 'failed' => 'Auto-add couldn\'t finish it'][$rsq['auto']]];
        } elseif ($rsq['found'] === 'new') {
            $it[] = ['href' => 'pc.php?view=found', 'label' => 'Waiting in Found on GitHub', 'note' => 'Not checked yet. Add, Auto-add or Skip it there.'];
        } elseif ($rsq['found'] === 'skipped') {
            $it[] = ['href' => 'pc.php?lookup=' . rawurlencode($rsq['link']) . '&t=' . rawurlencode(rs_csrf()), 'label' => 'Skipped before', 'note' => 'Put it back in Found on GitHub to check again'];
        } else {
            $it[] = ['href' => 'pc.php?lookup=' . rawurlencode($rsq['link']) . '&t=' . rawurlencode(rs_csrf()), 'label' => 'Not on RecompStore yet', 'note' => 'Add it to Found on GitHub to be checked'];
        }
        // (4.13) Xarishark: where it stands on Launcher support, one click to its page there.
        if ($a && rs_can_page('sdk-support.php')) {
            try {
                require_once __DIR__ . '/inc/sdk.php';
                require_once __DIR__ . '/inc/prq.php';
                $sk = rs_kv_get('sdk:' . $a['id']);
                $sc = $sk ? ['v' => json_decode((string)$sk['v'], true), 'age' => (int)$sk['age'], 't' => 0] : null;
                $sq = rs_db()->prepare('SELECT * FROM pc_prq WHERE app_id = ? ORDER BY id DESC LIMIT 1');
                $sq->execute([(string)$a['id']]);
                $qr = $sq->fetch(PDO::FETCH_ASSOC) ?: null;
                $row = rs_sdk_row($a, 0, $sc, rs_sdk_marks(), $qr ? [(string)$a['id'] => $qr] : [], array_intersect_key(rs_prq_replies(), [(string)$a['id'] => 1]));
                $sLab = ['ships' => 'Ships its own file', 'pr' => 'Pull request open', 'replied' => 'Developer replied', 'queue' => 'In the PR queue', 'no' => 'Won\'t support it', 'locked' => 'Pull requests closed', 'missing' => 'No file yet', 'unknown' => 'Couldn\'t check yet'][$row['state']] ?? $row['state'];
                $it[] = ['href' => 'sdk-support.php?tab=check&app=' . rawurlencode((string)$a['id']), 'label' => 'Launcher support: ' . $sLab,
                    'note' => ['json' => 'Its file, in our catalog', 'profile' => 'Our setup profile', 'steps' => 'Setup steps only', 'none' => 'Nothing yet'][$row['now']] ?? ''];
            } catch (Throwable $e) { rs_errlog('find sdk', $e); }
        }
        $it[] = ['href' => $rsq['link'], 'label' => 'Open the repo', 'note' => $rsq['key']];
        $out[] = ['title' => 'This repo', 'items' => $it];
    }
}
$has = fn(string ...$hay) => (bool)array_filter($hay, fn($x) => $x !== '' && str_contains(mb_strtolower($x), $lq));

// Admin pages
$pages = [['dashboard.php', 'Overview'], ['hub.php', 'Submissions'], ['hub.php?tab=requests', 'Requests'], ['hub.php?tab=found', 'Found on GitHub'], ['hub.php?tab=guide', 'How to approve'], ['roadmap.php', 'Roadmap'], ['dev-accounts.php', 'Developer requests'], ['security.php', 'Security'],
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
