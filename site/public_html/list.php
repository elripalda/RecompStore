<?php
// RecompStore website (2.7.1): the developer list's own links. /list/on/<token> confirms a sign-up,
// /list/off/<token> unsubscribes. Opening the link shows a button (so a mail scanner that follows
// links can't do it); the button, or a mail app's one-click unsubscribe, does it.
declare(strict_types=1);
require __DIR__ . '/admin/inc/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$a = ($_GET['a'] ?? '') === 'on' ? 'on' : 'off';
$t = preg_match('/^[a-f0-9]{32}$/', (string)($_GET['t'] ?? '')) ? (string)$_GET['t'] : '';
$row = null;
try {
    rs_mail_tables();
    if ($t !== '') {
        $st = rs_db()->prepare('SELECT * FROM mail_list WHERE token = ?');
        $st->execute([$t]);
        $row = $st->fetch() ?: null;
    }
} catch (Throwable $e) {
    $row = null;
}

$done = false;
if ($row && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    rs_db()->prepare('UPDATE mail_list SET status = ?, changed = NOW() WHERE id = ?')->execute([$a, $row['id']]);
    $done = true;
    // A mail app's one-click unsubscribe only needs to hear back that it worked.
    if (($_POST['List-Unsubscribe'] ?? '') === 'One-Click') { http_response_code(200); echo 'OK'; exit; }
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (!$row) {
    http_response_code(404);
    [$title, $text, $button] = ['That link doesn\'t work', 'It may be old or copied wrong. Write to info@recompstore.com and we\'ll sort it out.', ''];
} elseif ($a === 'off') {
    [$title, $text, $button] = $done || $row['status'] === 'off'
        ? ['You\'re off the list', 'No more emails from RecompStore\'s developer list to ' . $row['email'] . '.', '']
        : ['Unsubscribe?', $row['email'] . ' stops getting emails from RecompStore\'s developer list.', 'Unsubscribe'];
} else {
    [$title, $text, $button] = $done || $row['status'] === 'on'
        ? ['You\'re on the list', 'Thanks! News for developers goes to ' . $row['email'] . '. Every email has a link to unsubscribe.', '']
        : ['Confirm your email', 'Join RecompStore\'s developer list with ' . $row['email'] . '?', 'Yes, sign me up'];
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($title) ?> · RecompStore</title>
<link rel="icon" href="/assets/logo-512.png">
<link rel="stylesheet" href="/assets/site.css?v=25">
<style>
.list-page{max-width:520px;margin:12vh auto 0;padding:32px 28px;text-align:center}
.list-page img{width:72px;height:72px;margin-bottom:16px}
.list-page h1{font-size:30px}
.list-page form{margin-top:22px}
.list-page .btn{display:inline-flex;min-height:52px;padding:0 28px;border-radius:999px;cursor:pointer;font-size:17px}
.list-page a.home{display:inline-block;margin-top:22px;color:var(--cyan)}
</style>
</head>
<body>
<main>
  <section class="glass list-page">
    <img src="/assets/logo-512.png" alt="">
    <h1><?= $e($title) ?></h1>
    <p><?= $e($text) ?></p>
    <?php if ($button !== ''): ?><form method="post"><button class="btn primary" type="submit"><?= $e($button) ?></button></form><?php endif; ?>
    <a class="home" href="/">recompstore.com</a>
  </section>
</main>
</body>
</html>
