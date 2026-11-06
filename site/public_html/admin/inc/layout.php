<?php
// RecompStore admin: page header and footer.
declare(strict_types=1);

// (2.3) $css: extra stylesheets for the page (Analytics, the Dashboard, the Games list).
function rs_head(string $title, bool $nav = true, array $css = []): void {
    if ($nav && function_exists('rs_vt_after')) rs_vt_after(); // (3.8)
    $flash = rs_flash();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · RecompStore admin</title>
<link rel="icon" href="/assets/logo-512.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(RS_ADMIN . '/assets/admin.css') ?>">
<?php foreach ($css as $c): ?><link rel="stylesheet" href="<?= h($c) ?>?v=<?= (int)@filemtime(RS_ADMIN . '/' . $c) ?>">
<?php endforeach; ?>
</head>
<body>
<header class="bar">
  <a class="brand" href="dashboard.php"><img src="/assets/mark.svg" alt=""><span>Recomp<b>Store</b></span><em>admin</em></a>
  <?php if ($nav): ?>
  <nav>
    <?php
    // (2.8) Two stores, one admin: the PS5 store's pages and the PC store's pages each have their
    // own row under the bar; the rest is shared.
    $here = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $inboxNew = rs_inbox_new_count();
    $pcView = $here === 'pc.php' ? (string)($_GET['view'] ?? '') : '';
    $ps5 = [['index.php', 'Games', ['edit.php']], ['discover.php', 'Discover', []], ['collections.php', 'Collections', []], ['beta.php', 'Beta', ['beta-upload.php']],
        ['feedback.php', 'Feedback', []], ['import.php', 'Import', []], ['health.php', 'Found on GitHub', []], ['icons.php', 'Icons', []]];
    $pc = [['pc.php', 'Recomps', ''], ['pc.php?view=found', 'Found on GitHub', 'found'], ['pc.php?view=reports', 'Player reports', 'reports']];
    // (3.0) Games with more than one version.
    $pc[] = ['titles.php', 'Versions', 'titles'];
    // (3.6) The slider on top of the PC home page.
    $pc[] = ['pc-banner.php', 'Banner', 'banner'];
    if ($here === 'pc-banner.php') { $pcView = 'banner'; }
    if ($here === 'titles.php') { $pcView = 'titles'; }
    $inPs5 = false;
    foreach ($ps5 as [$href, , $also]) if ($here === $href || in_array($here, $also, true)) $inPs5 = true;
    $inPc = $here === 'pc.php' || $here === 'titles.php' || $here === 'pc-banner.php';
    // (2.9) The AI check: RecompLauncher's recomps.
    if ($here === 'ai.php') { $inPc = true; $inPs5 = false; $pcView = 'ai'; }
    $pc[] = ['ai.php', 'AI check', 'ai'];
    // (3.1g) The RecompLauncher beta: builds, the beta page and the invite emails.
    $pc[] = ['launcher-beta.php', 'Launcher beta', 'lbeta'];
    if ($here === 'launcher-beta.php') { $inPc = true; $inPs5 = false; $pcView = 'lbeta'; }
    // (3.1g) What the launcher's anonymous pings say: who's on, what they're playing.
    $pc[] = ['launcher-stats.php', 'Launcher stats', 'lstats'];
    if ($here === 'launcher-stats.php') { $inPc = true; $inPs5 = false; $pcView = 'lstats'; }
    // (3.2) RecompLauncher itself
    if ($here === 'launcher.php') { $inPc = true; $inPs5 = false; $pcView = 'launcher'; }
    $pc[] = ['launcher.php', 'Launcher', 'launcher'];
    $links = [['dashboard.php', 'Dashboard', $here === 'dashboard.php'], ['index.php', 'PS5', $inPs5], ['pc.php', 'PC', $inPc],
        ['developers.php', 'Developers' . (($devWait = rs_dev_waiting() + rs_inbox_new_count(true)) ? ' <span class="count">' . $devWait . '</span>' : ''), in_array($here, ['developers.php', 'submissions.php', 'dev-accounts.php', 'docs.php', 'community.php'], true)],
        ['messages.php', 'Messages' . (($msgNew = rs_dev_admin_unread()) ? ' <span class="count">' . $msgNew . '</span>' : ''), $here === 'messages.php'],
        ['accounts.php', 'Accounts', $here === 'accounts.php'], ['inbox.php', 'Inbox' . ($inboxNew ? ' <span class="count">' . $inboxNew . '</span>' : ''), $here === 'inbox.php'], ['mail.php', 'Mail', $here === 'mail.php'],
        ['analytics.php', 'Analytics', in_array($here, ['analytics.php', 'stats.php', 'app-stats.php'], true)], ['security.php', 'Security' . (($vtNeed = function_exists('rs_vt_need_count') ? rs_vt_need_count() : 0) ? ' <span class="count">' . $vtNeed . '</span>' : ''), $here === 'security.php'], ['status.php', 'Status', in_array($here, ['status.php', 'diag-db.php'], true)], ['settings.php', 'Settings', in_array($here, ['settings.php', 'log.php'], true)]];
    foreach ($links as [$href, $label, $on]): ?>
    <a href="<?= h($href) ?>"<?= $on ? ' class="on" aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a href="/" target="_blank" rel="noopener">View site</a>
    <a href="logout.php">Log out</a>
  </nav>
  <?php // (3.1) The developer side: their pages, their accounts, messages and the docs.
  // (3.7) Submissions moved here from the Inbox.
  $subNew = rs_inbox_new_count(true);
  $devPages = [['developers.php', 'Developer pages'], ['submissions.php', 'Submissions' . ($subNew ? ' (' . $subNew . ')' : '')], ['dev-accounts.php', 'Accounts'], ['messages.php', 'Messages'], ['community.php', 'Discord & notes'], ['docs.php', 'Docs']];
  if (in_array($here, array_column($devPages, 0), true)): ?>
  <nav class="subbar" aria-label="Developers">
    <span class="subbar-tag dev" style="background:rgba(138,75,255,.18);color:#cdb8ff;border:1px solid rgba(138,75,255,.5)">DEVELOPERS</span>
    <?php foreach ($devPages as [$href, $label]): ?><a href="<?= h($href) ?>"<?= $here === $href ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
  </nav>
  <?php endif; ?>
  <?php if ($inPs5 || $inPc): ?>
  <nav class="subbar" aria-label="<?= $inPs5 ? 'PS5 store' : 'PC store' ?>">
    <span class="subbar-tag <?= $inPs5 ? 'ps5' : 'pc' ?>"><?= $inPs5 ? 'PS5' : 'PC' ?></span>
    <?php if ($inPs5): foreach ($ps5 as [$href, $label, $also]): ?>
    <a href="<?= h($href) ?>"<?= $here === strtok($href, '?') || in_array($here, $also, true) ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; else: foreach ($pc as [$href, $label, $v]): ?>
    <a href="<?= h($href) ?>"<?= $pcView === $v ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</header>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['kind']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<?php
// (2.9) A site in maintenance mode says so on every admin page, so it's never left down by accident.
if ($nav) {
    require_once __DIR__ . '/maint.php';
    $mt = rs_maint_state();
    $down = array_keys(array_filter(['RecompStore (PS5)' => $mt['ps5']['on'], 'RecompLauncher (PC)' => $mt['pc']['on']]));
    if ($down): ?>
<div class="signbar maintbar" role="status"><strong>Maintenance mode</strong><span><?= h(implode(' and ', $down)) ?> <?= count($down) > 1 ? 'are' : 'is' ?> down for visitors.</span><a href="settings.php#maintenance">Change</a></div>
<?php endif;
} ?>
<?php if ($nav && ($waiting = rs_sign_waiting()) > 0): ?>
<div class="signbar" role="status"><strong>Waiting for your signature</strong><span><?= $waiting === 1 ? '1 thing' : $waiting . ' things' ?> you published or uploaded won't reach anyone until you sign <?= $waiting === 1 ? 'it' : 'them' ?> in RecompStore Signer on your PC.</span></div>
<?php endif; ?>
<?php
}

function rs_foot(array $scripts = []): void {
    // (2.3) Settings: the jump links down its left side.
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'settings.php') $scripts[] = 'assets/settings-nav.js';
    // Each file's own date: a browser never keeps an old copy after an update.
    foreach ($scripts as $s) echo '<script src="' . h($s) . '?v=' . (int)@filemtime(RS_ADMIN . '/' . $s) . '"></script>' . "\n";
    echo "</main>\n</body>\n</html>\n";
}
