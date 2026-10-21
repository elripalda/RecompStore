<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/totp.php';

if (!rs_config()) { header('Location: setup.php'); exit; }
rs_session();
if (!empty($_SESSION['uid'])) { header('Location: dashboard.php'); exit; }
rs_users_2fa();

$error = '';
// (2.1) After the password: the authenticator code, when two-step login is on.
$step2 = !empty($_SESSION['pending_uid']) && (int)($_SESSION['pending_at'] ?? 0) > time() - 300;
if (!$step2) unset($_SESSION['pending_uid'], $_SESSION['pending_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    // Slow down repeated guesses from one session, and (2.1) from one address.
    $_SESSION['tries'] = ($_SESSION['tries'] ?? 0) + 1;
    if ($_SESSION['tries'] > 5) sleep(min(10, $_SESSION['tries'] - 5));
    if (rs_rate_over('login', 10, 900)) {
        $error = 'Too many tries from here. Wait 15 minutes, then try again.';
    } elseif ($step2) {
        $uid = (int)$_SESSION['pending_uid'];
        $st = rs_db()->prepare('SELECT username, totp_secret, totp_last FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
        $code = trim((string)($_POST['code'] ?? ''));
        $step = $u ? rs_totp_check((string)$u['totp_secret'], $code, (int)$u['totp_last']) : 0;
        $recovery = !$step && $u && rs_use_recovery_code($uid, $code);
        if ($step || $recovery) {
            if ($step) rs_db()->prepare('UPDATE users SET totp_last = ? WHERE id = ?')->execute([$step, $uid]);
            session_regenerate_id(true);
            unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
            $_SESSION['uid'] = $uid;
            $_SESSION['tries'] = 0;
            rs_log('login', (string)$u['username'], $recovery ? 'with a recovery code' : 'with the authenticator');
            if ($recovery) rs_flash('You logged in with a recovery code. It won\'t work again. If your phone is lost, turn two-step login off and on again in Settings.', 'err');
            header('Location: dashboard.php'); // (2.3) the Dashboard first
            exit;
        }
        rs_rate_hit('login');
        rs_log('login-failed', (string)($u['username'] ?? ''), 'wrong code');
        $error = 'That code didn\'t work. Use the newest one from your authenticator app.';
    } else {
        $name = trim((string)($_POST['username'] ?? ''));
        $st = rs_db()->prepare('SELECT id, pass_hash, totp_secret FROM users WHERE username = ?');
        $st->execute([$name]);
        $u = $st->fetch();
        if ($u && password_verify((string)($_POST['password'] ?? ''), $u['pass_hash'])) {
            if ((string)$u['totp_secret'] !== '') {
                session_regenerate_id(true);
                $_SESSION['pending_uid'] = (int)$u['id'];
                $_SESSION['pending_at'] = time();
                header('Location: login.php');
                exit;
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['tries'] = 0;
            rs_log('login', $name, 'password only');
            header('Location: dashboard.php'); // (2.3) the Dashboard first
            exit;
        }
        rs_rate_hit('login');
        rs_log('login-failed', mb_substr($name, 0, 60), 'wrong password');
        $error = 'That username and password don\'t match.';
    }
}

rs_head('Log in', false);
?>
<section class="card narrow">
  <h1>Log in</h1>
  <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <?php if ($step2): ?>
    <p class="muted">Open your authenticator app and type the 6-digit code for Recomp Store admin. Lost your phone? Type one of your recovery codes instead.</p>
    <label>Code<input name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="11" spellcheck="false" class="mono"></label>
    <button class="primary" type="submit">Log in</button>
    <p class="muted"><a href="logout.php">Start over</a></p>
    <?php else: ?>
    <label>Username<input name="username" required autofocus autocomplete="username"></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="primary" type="submit">Log in</button>
    <?php endif; ?>
  </form>
</section>
<?php rs_foot();
