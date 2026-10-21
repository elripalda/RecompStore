<?php
// Recomp Store admin: page header and footer.
declare(strict_types=1);

// (2.3) $css: extra stylesheets for the page (Analytics, the Dashboard, the Games list).
function rs_head(string $title, bool $nav = true, array $css = []): void {
    $flash = rs_flash();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · Recomp Store admin</title>
<link rel="icon" href="/assets/logo-512.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css?v=<?= (int)@filemtime(RS_ADMIN . '/assets/admin.css') ?>">
<?php foreach ($css as $c): ?><link rel="stylesheet" href="<?= h($c) ?>?v=<?= (int)@filemtime(RS_ADMIN . '/' . $c) ?>">
<?php endforeach; ?>
</head>
<body>
<header class="bar">
  <a class="brand" href="dashboard.php"><img src="/assets/logo-512.png" alt=""><span>Recomp Store</span><em>admin</em></a>
  <?php if ($nav): ?>
  <nav>
    <?php
    $here = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $inboxNew = rs_inbox_new_count();
    $links = [['dashboard.php', 'Dashboard', []], ['index.php', 'Games', ['edit.php']], ['discover.php', 'Discover', []], ['collections.php', 'Collections', []], ['beta.php', 'Beta', []],
        ['feedback.php', 'Feedback', []], ['import.php', 'Import', []],
        ['inbox.php', 'Inbox' . ($inboxNew ? ' <span class="count">' . $inboxNew . '</span>' : ''), []], ['analytics.php', 'Analytics', ['stats.php', 'app-stats.php']],
        ['settings.php', 'Settings', ['log.php']]];
    foreach ($links as [$href, $label, $also]): ?>
    <a href="<?= h($href) ?>"<?= $here === $href || in_array($here, $also, true) ? ' class="on" aria-current="page"' : '' ?>><?= $label ?></a>
    <?php endforeach; ?>
    <a href="/" target="_blank" rel="noopener">View site</a>
    <a href="logout.php">Log out</a>
  </nav>
  <?php endif; ?>
</header>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['kind']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<?php if ($nav && ($waiting = rs_sign_waiting()) > 0): ?>
<div class="signbar" role="status"><strong>Waiting for your signature</strong><span><?= $waiting === 1 ? '1 thing' : $waiting . ' things' ?> you published or uploaded won't reach anyone until you sign <?= $waiting === 1 ? 'it' : 'them' ?> in Recomp Store Signer on your PC.</span></div>
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
