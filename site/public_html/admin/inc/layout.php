<?php
// Recomp Store admin: page header and footer.
declare(strict_types=1);

function rs_head(string $title, bool $nav = true): void {
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
<link rel="stylesheet" href="assets/admin.css?v=<?= RS_VERSION ?>">
</head>
<body>
<header class="bar">
  <a class="brand" href="index.php"><img src="/assets/logo-512.png" alt=""><span>Recomp Store</span><em>admin</em></a>
  <?php if ($nav): ?>
  <nav>
    <a href="index.php">Games</a>
    <?php $inboxNew = rs_inbox_new_count(); ?>
    <a href="inbox.php">Inbox<?= $inboxNew ? ' <span class="count">' . $inboxNew . '</span>' : '' ?></a>
    <a href="settings.php">Settings</a>
    <a href="/" target="_blank" rel="noopener">View site</a>
    <a href="logout.php">Log out</a>
  </nav>
  <?php endif; ?>
</header>
<main>
<?php if ($flash): ?><div class="flash <?= h($flash['kind']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>
<?php
}

function rs_foot(array $scripts = []): void {
    foreach ($scripts as $s) echo '<script src="' . h($s) . '?v=' . RS_VERSION . '"></script>' . "\n";
    echo "</main>\n</body>\n</html>\n";
}
