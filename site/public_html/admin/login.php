<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';

if (!rs_config()) { header('Location: setup.php'); exit; }
rs_session();
if (!empty($_SESSION['uid'])) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    // Slow down repeated guesses from one session.
    $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
    if ($_SESSION['tries'] > 5) sleep(min(10, $_SESSION['tries'] - 5));
    $st = rs_db()->prepare('SELECT id, pass_hash FROM users WHERE username = ?');
    $st->execute([trim((string)($_POST['username'] ?? ''))]);
    $u = $st->fetch();
    if ($u && password_verify((string)($_POST['password'] ?? ''), $u['pass_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['tries'] = 0;
        header('Location: index.php');
        exit;
    }
    $error = 'That username and password don\'t match.';
}

rs_head('Log in', false);
?>
<section class="card narrow">
  <h1>Log in</h1>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <label>Username<input name="username" required autofocus autocomplete="username"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="primary" type="submit">Log in</button>
  </form>
</section>
<?php rs_foot();
