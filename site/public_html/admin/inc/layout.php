<?php
// RecompStore admin: page header and footer.
// (4.3) A sidebar grouped by job (Stores, Curation, Support, Insights, Admin), showing only what
// this login's role and stores allow; the section you're in opens to show its pages.
declare(strict_types=1);

// Small line icons for the sidebar (24px grid, drawn with the text colour).
function rs_icon(string $n): string {
    $p = [
        'home' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
        'pc' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
        'ps5' => '<path d="M7 9h10a4 4 0 0 1 4 4v2a3 3 0 0 1-5.2 2L14 15h-4l-1.8 2A3 3 0 0 1 3 15v-2a4 4 0 0 1 4-4z"/><path d="M8 12v2M7 13h2"/><circle cx="16" cy="12.5" r=".6"/><circle cx="17.5" cy="14" r=".6"/>',
        'dev' => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M13.5 5l-3 14"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.4 8-8 9-4.6-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'inbox' => '<path d="M4 13l2.5-7h11L20 13v6H4z"/><path d="M4 13h4.5l1 2h5l1-2H20"/>',
        'players' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.2-4 4.3-6 8-6s6.8 2 8 6"/>',
        'chart' => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
        'pulse' => '<path d="M3 12h4l2-6 4 12 2-6h6"/>',
        'team' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.9-3.4 3.4-5 6.5-5s5.6 1.6 6.5 5"/><circle cx="17" cy="9" r="2.6"/><path d="M16.5 14.2c2.6.2 4.3 1.8 5 4.8"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'log' => '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h7"/>',
        'out' => '<path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10"/>',
        'ext' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.2-4 4.3-6 8-6s6.8 2 8 6"/>',
    ][$n] ?? '';
    return '<svg class="ic" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

// (2.3) $css: extra stylesheets for the page (Analytics, the Dashboard, the Games list).
function rs_head(string $title, bool $nav = true, array $css = []): void {
    if ($nav && function_exists('rs_vt_after')) rs_vt_after(); // (3.8)
    $flash = rs_flash();
    $theme = function_exists('rs_theme') && !empty($_SESSION['uid']) ? rs_theme() : '';
    ?><!doctype html>
<html lang="en"<?= $theme !== '' ? ' data-theme="' . h($theme) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="<?= $theme === 'light' ? 'light' : 'dark' ?>">
<title><?= h($title) ?> · RecompStore admin</title>
<link rel="icon" href="/assets/logo-512.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(RS_ADMIN . '/assets/admin.css') ?>">
<?php foreach ($css as $c): ?><link rel="stylesheet" href="<?= h($c) ?>?v=<?= (int)@filemtime(RS_ADMIN . '/' . $c) ?>">
<?php endforeach; ?>
</head>
<?php if (!$nav): $GLOBALS['rs_head_bare'] = true; ?>
<body class="bare">
<header class="bar">
  <a class="brand" href="dashboard.php"><img src="/assets/mark.svg" alt=""><span>Recomp<b>Store</b></span><em>admin</em></a>
</header>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['kind']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<?php return; endif;

    $here = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $view = (string)($_GET['view'] ?? '');
    $inboxNew = rs_inbox_new_count();
    $subNew = rs_inbox_new_count(true);
    $devWait = rs_dev_waiting() + $subNew + rs_dev_admin_unread();
    $vtNeed = function_exists('rs_vt_need_count') ? rs_vt_need_count() : 0;
    $cnt = fn(int $n) => $n ? ' <span class="count">' . $n . '</span>' : '';
    // (4.7) Auto-add drafts waiting to be looked at (the daily scout's included).
    $autoN = 0;
    if (rs_can_page('auto.php')) { try { $autoN = (int)rs_db()->query("SELECT COUNT(*) FROM pc_auto WHERE status = 'review'")->fetchColumn(); } catch (Throwable $e) { /* no table yet */ } }
    // Each section: [label, icon, its pages [href, label, matches?], count]. A page matches when
    // it's the file in the address (and, for pc.php, the ?view=).
    $pcAt = fn(string $v) => fn() => $here === 'pc.php' && $view === $v;
    // Title ID clashes: a red count beside the PS5 store's Found on GitHub.
    $radar = rs_can_page('health.php') && function_exists('rs_radar_count') ? rs_radar_count() : 0;
    $red = fn(int $n) => $n ? ' <span class="count red" title="Title ID ' . ($n === 1 ? 'clash' : 'clashes') . '">' . $n . '</span>' : '';
    $sections = [
        'Overview' => [
            ['dashboard.php', 'Dashboard', 'home', [], 0],
        ],
        'Stores' => [
            ['pc.php', 'PC · RecompLauncher', 'pc', [
                ['pc.php', 'Recomps', $pcAt('')], ['auto.php', 'Auto-add' . $cnt($autoN), null], ['pc.php?view=found', 'Found on GitHub', $pcAt('found')], ['pc.php?view=reports', 'Player reports', $pcAt('reports')],
                ['titles.php', 'Versions', null], ['boot.php', 'Boot profiles', null], ['sdk-support.php', 'SDK support', null], ['achievements.php', 'Achievements', null], ['pc-banner.php', 'Banner', null], ['ai.php', 'AI check', null],
                ['launcher-beta.php', 'Launcher beta', null], ['launcher-stats.php', 'Launcher stats', null], ['launcher.php', 'Launcher', null]], 0],
            ['index.php', 'PS5 · RecompStore', 'ps5', [
                ['index.php', 'Games', fn() => in_array($here, ['index.php', 'edit.php'], true)], ['discover.php', 'Discover', null], ['collections.php', 'Collections', null],
                ['beta.php', 'Beta', fn() => in_array($here, ['beta.php', 'beta-upload.php'], true)], ['feedback.php', 'Feedback', null], ['import.php', 'Import', null],
                ['health.php', 'Found on GitHub' . $red($radar), null], ['icons.php', 'Icons', null]], $radar],
        ],
        'Curation' => [
            ['developers.php', 'Developers', 'dev', [
                ['developers.php', 'Developer pages', null], ['submissions.php', 'Submissions' . $cnt($subNew), null], ['dev-accounts.php', 'Accounts', null],
                ['messages.php', 'Messages' . $cnt(rs_dev_admin_unread()), null], ['community.php', 'Discord & notes', null], ['docs.php', 'Docs', null]], $devWait],
            ['security.php', 'Security', 'shield', [], $vtNeed],
        ],
        'Support' => [
            ['inbox.php', 'Inbox', 'inbox', [], $inboxNew],
            ['accounts.php', 'Players', 'players', [['accounts.php', 'Accounts', null], ['mail.php', 'Mail', null]], 0],
        ],
        'Insights' => [
            ['analytics.php', 'Analytics', 'chart', [], 0],
            ['log.php', 'Change log', 'log', [], 0],
            ['status.php', 'Status', 'pulse', [], 0],
        ],
        'Admin' => [
            ['team.php', 'Team', 'team', [], 0],
            ['settings.php', 'Settings', 'gear', [], 0],
        ],
    ];
    $also = ['analytics.php' => ['stats.php', 'app-stats.php'], 'status.php' => ['diag-db.php'], 'security.php' => [], 'inbox.php' => []];
    $isOn = function (array $pg) use ($here): bool {
        [$href, , $m] = $pg;
        return $m ? (bool)$m() : $here === basename((string)strtok($href, '?'));
    };
    $crumb = ['', $title];
    $me = rs_me();
    $first = mb_strtoupper(mb_substr(rs_me_name(), 0, 1));
    ?>
<body class="adm">
<input type="checkbox" id="side-open" class="side-check" hidden>
<aside class="side" aria-label="Admin menu">
  <a class="brand" href="dashboard.php"><img src="/assets/mark.svg" alt=""><span>Recomp<b>Store</b></span><em>admin</em></a>
  <nav class="side-nav">
  <?php foreach ($sections as $group => $items):
      $shown = array_values(array_filter($items, fn($it) => rs_can_page($it[0])));
      if (!$shown) continue; ?>
    <div class="side-group">
      <h3><?= h($group) ?></h3>
      <?php foreach ($shown as [$href, $label, $icon, $pages, $n]):
          $kids = array_values(array_filter($pages, fn($pg) => rs_can_page($pg[0])));
          $on = $here === basename($href) || in_array($here, $also[$href] ?? [], true);
          foreach ($kids as $pg) if ($isOn($pg)) { $on = true; $crumb = [strip_tags($label), trim(strip_tags(preg_replace('#<span class="count.*?</span>#', '', $pg[1])))]; }
          if ($on && $crumb[0] === '') $crumb = [$group, strip_tags($label)]; ?>
      <a class="side-link<?= $on ? ' on' : '' ?>" href="<?= h($href) ?>"<?= $on && !$kids ? ' aria-current="page"' : '' ?>><?= rs_icon($icon) ?><span><?= h($label) ?></span><?= $n ? '<span class="count' . ($href === 'index.php' ? ' red' : '') . '">' . $n . '</span>' : '' ?></a>
      <?php if ($on && count($kids) > 1): ?>
      <div class="side-kids">
        <?php foreach ($kids as $pg): $k = $isOn($pg); ?><a href="<?= h($pg[0]) ?>"<?= $k ? ' class="on" aria-current="page"' : '' ?>><?= $pg[1] ?></a><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  </nav>
  <div class="side-foot">
    <a class="side-link" href="https://recomplauncher.com/" target="_blank" rel="noopener"><?= rs_icon('ext') ?><span>View site</span></a>
  </div>
</aside>
<label for="side-open" class="side-scrim" aria-hidden="true"></label>
<div class="adm-main">
<header class="top">
  <label for="side-open" class="side-toggle" title="Menu"><?= rs_icon('menu') ?><span class="sr">Menu</span></label>
  <div class="crumbs"><?php if ($crumb[0] !== '' && $crumb[0] !== $crumb[1]): ?><span><?= h($crumb[0]) ?></span><i aria-hidden="true">/</i><?php endif; ?><strong><?= h($crumb[1]) ?></strong></div>
  <span class="grow"></span>
  <a class="top-site" href="https://recomplauncher.com/" target="_blank" rel="noopener"><?= rs_icon('ext') ?><span>View site</span></a>
  <details class="me-menu">
    <summary><span class="me-av" aria-hidden="true"><?= h($first) ?></span><span class="me-name"><?= h(rs_me_name()) ?></span><span class="role-chip role-<?= h((string)($me['role'] ?? '')) ?>"><?= h(RS_ROLES[$me['role'] ?? ''] ?? '') ?></span></summary>
    <div class="me-pop">
      <div class="me-head"><strong><?= h(rs_me_name()) ?></strong><span class="muted small"><?= h(RS_ROLES[$me['role'] ?? ''] ?? '') ?> · <?= h(implode(' + ', array_map(fn($s) => RS_STORES[$s], $me['stores_list'] ?? []))) ?></span></div>
      <a href="account.php"><?= rs_icon('user') ?>My account</a>
      <?php if (rs_can_page('team.php')): ?><a href="team.php"><?= rs_icon('team') ?>Team</a><?php endif; ?>
      <form method="post" action="account.php" class="me-theme"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="theme"><input type="hidden" name="back" value="<?= h((string)($_SERVER['REQUEST_URI'] ?? '')) ?>">
        <span class="muted small">Look</span>
        <div class="seg3"><?php foreach (RS_THEMES as $k => $n): ?><button name="theme" value="<?= h($k) ?>"<?= rs_theme() === $k ? ' class="on" aria-pressed="true"' : '' ?>><?= h($n === 'OLED black' ? 'OLED' : $n) ?></button><?php endforeach; ?></div>
      </form>
      <a href="logout.php"><?= rs_icon('out') ?>Log out</a>
    </div>
  </details>
</header>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['kind']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<?php
// (2.9) A site in maintenance mode says so on every admin page, so it's never left down by accident.
require_once __DIR__ . '/maint.php';
$mt = rs_maint_state();
$down = array_keys(array_filter(['RecompStore (PS5)' => $mt['ps5']['on'], 'RecompLauncher (PC)' => $mt['pc']['on']]));
if ($down): ?>
<div class="signbar maintbar" role="status"><strong>Maintenance mode</strong><span><?= h(implode(' and ', $down)) ?> <?= count($down) > 1 ? 'are' : 'is' ?> down for visitors.</span><?php if (rs_can_page('settings.php')): ?><a href="settings.php#maintenance">Change</a><?php endif; ?></div>
<?php endif; ?>
<?php if (rs_is_owner() && ($waiting = rs_sign_waiting()) > 0): ?>
<div class="signbar" role="status"><strong>Waiting for your signature</strong><span><?= $waiting === 1 ? '1 thing' : $waiting . ' things' ?> you published or uploaded won't reach anyone until you sign <?= $waiting === 1 ? 'it' : 'them' ?> in RecompStore Signer on your PC.</span></div>
<?php endif; ?>
<?php
}

function rs_foot(array $scripts = []): void {
    // (2.3) Settings: the jump links down its left side.
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'settings.php') $scripts[] = 'assets/settings-nav.js';
    // Each file's own date: a browser never keeps an old copy after an update.
    foreach ($scripts as $s) echo '<script src="' . h($s) . '?v=' . (int)@filemtime(RS_ADMIN . '/' . $s) . '"></script>' . "\n";
    echo "</main>\n" . (empty($GLOBALS['rs_head_bare']) ? "</div>\n" : '') . "</body>\n</html>\n";
}
