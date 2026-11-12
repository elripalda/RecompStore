<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/totp.php';
require_once __DIR__ . '/inc/adminlink.php';

if (!rs_config()) { header('Location: setup.php'); exit; }
rs_session();
if (!empty($_SESSION['uid'])) { header('Location: ' . (function_exists('rs_home_page') ? rs_home_page() : 'dashboard.php')); exit; }
rs_users_2fa();
// (4.8) The users table's account_id column (merged RecompLauncher accounts), made before it's read.
$alOk = true;
try { rs_al_schema(); } catch (Throwable $e) { $alOk = false; }

$error = '';
// (2.1) After the password: the authenticator code, when two-step login is on. (4.8) The admin
// login's code, or, for one merged with a RecompLauncher account that has no admin code, the account's.
$step2 = !empty($_SESSION['pending_uid']) && (int)($_SESSION['pending_at'] ?? 0) > time() - 300;
if (!$step2) unset($_SESSION['pending_uid'], $_SESSION['pending_at'], $_SESSION['pending_kind']);
$pendKind = (string)($_SESSION['pending_kind'] ?? 'admin');
// (4.8) Signed in on this site with a RecompLauncher account that's merged with an admin login.
$me = null;
$meAdmin = null;
if ($alOk) { try { $me = rs_acct_current(); $meAdmin = $me ? rs_al_admin_for((int)$me['id']) : null; } catch (Throwable $e) { $me = null; $meAdmin = null; } }
// Into the admin as $uid: straight in, or the code first. $kind: which code (admin | acct).
$enter = function (int $uid, bool $keep, string $how, string $kind = 'admin') {
    if ($kind !== '') {
        session_regenerate_id(true);
        $_SESSION['pending_uid'] = $uid;
        $_SESSION['pending_at'] = time();
        $_SESSION['pending_keep'] = $keep;
        $_SESSION['pending_kind'] = $kind;
        header('Location: login.php');
        exit;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    rs_login_done($uid);
    $_SESSION['tries'] = 0;
    if ($keep) rs_keep_issue($uid);
    $st = rs_db()->prepare('SELECT username FROM users WHERE id = ?');
    $st->execute([$uid]);
    rs_log('login', (string)$st->fetchColumn(), $how);
    header('Location: ' . (function_exists('rs_home_page') ? rs_home_page() : 'dashboard.php')); // (2.3) the Dashboard first
    exit;
};

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
        if ($pendKind === 'acct') {
            // The merged RecompLauncher account's own code (or one of its backup codes).
            $pa = rs_al_account_for($uid);
            $step = 0;
            $recovery = false;
            $acctOk = $u && $pa && $pa['totp_on'] && rs_acct_2fa_check($pa, $code);
        }
        if ($pendKind !== 'acct') {
            $step = $u ? rs_totp_check((string)$u['totp_secret'], $code, (int)$u['totp_last']) : 0;
            $recovery = !$step && $u && rs_use_recovery_code($uid, $code);
        }
        if ($step || $recovery || !empty($acctOk)) {
            if ($step) rs_db()->prepare('UPDATE users SET totp_last = ? WHERE id = ?')->execute([$step, $uid]);
            session_regenerate_id(true);
            $keep = !empty($_SESSION['pending_keep']);
            unset($_SESSION['pending_uid'], $_SESSION['pending_at'], $_SESSION['pending_keep'], $_SESSION['pending_kind']);
            $_SESSION['uid'] = $uid;
            rs_login_done($uid);
            if ($keep) rs_keep_issue($uid);
            $_SESSION['tries'] = 0;
            rs_log('login', (string)$u['username'], !empty($acctOk) ? 'with the RecompLauncher account\'s code' : ($recovery ? 'with a recovery code' : 'with the authenticator'));
            if ($recovery) rs_flash('You logged in with a recovery code. It won\'t work again. If your phone is lost, turn two-step login off and on again in Settings.', 'err');
            header('Location: ' . (function_exists('rs_home_page') ? rs_home_page() : 'dashboard.php')); // (2.3) the Dashboard first
            exit;
        }
        rs_rate_hit('login');
        rs_log('login-failed', (string)($u['username'] ?? ''), 'wrong code');
        $error = 'That code didn\'t work. Use the newest one from your authenticator app.';
    } elseif (($_POST['action'] ?? '') === 'acct') {
        // (4.8) Continue as the RecompLauncher account this browser is signed in with.
        if (!$me || !$meAdmin) { $error = 'Sign in with the RecompLauncher account that\'s merged with your admin login first.'; }
        else $enter((int)$meAdmin['id'], !empty($_POST['keep']), 'with the RecompLauncher account ' . (string)$me['username'], (string)$meAdmin['totp_secret'] !== '' ? 'admin' : '');
    } else {
        $name = trim((string)($_POST['username'] ?? ''));
        $pw = (string)($_POST['password'] ?? '');
        rs_roles_schema();
        $st = rs_db()->prepare('SELECT id, pass_hash, totp_secret, active' . ($alOk ? ', account_id' : '') . ' FROM users WHERE username = ?');
        $st->execute([$name]);
        $u = $st->fetch();
        // (4.8) Or the username or email of a RecompLauncher account merged with an admin login.
        $pa = null;
        if ($u && (int)($u['account_id'] ?? 0) > 0) $pa = rs_acct_get((int)$u['account_id']);
        if (!$u && $alOk) {
            $pa = str_contains($name, '@') ? rs_acct_by('email_lc', mb_strtolower($name)) : rs_acct_by('username_lc', mb_strtolower($name));
            $ad = $pa ? rs_al_admin_for((int)$pa['id']) : null;
            if ($ad) { $u = ['id' => $ad['id'], 'pass_hash' => $ad['pass_hash'], 'totp_secret' => $ad['totp_secret'], 'active' => $ad['active']]; $name = (string)$ad['username']; }
            else $pa = null;
        }
        // (4.3) A login the owner switched off can't sign in.
        if ($u && !(int)$u['active']) $u = null;
        $viaAcct = $u && $pa && empty($pa['banned']) && $pa['pass_hash'] !== null && !password_verify($pw, (string)$u['pass_hash']) && password_verify($pw, (string)$pa['pass_hash']);
        if ($viaAcct) $enter((int)$u['id'], !empty($_POST['keep']), 'with the RecompLauncher account\'s password', (string)$u['totp_secret'] !== '' ? 'admin' : ($pa['totp_on'] ? 'acct' : ''));
        if ($u && password_verify($pw, $u['pass_hash'])) {
            if ((string)$u['totp_secret'] !== '') {
                session_regenerate_id(true);
                $_SESSION['pending_uid'] = (int)$u['id'];
                $_SESSION['pending_at'] = time();
                $_SESSION['pending_keep'] = !empty($_POST['keep']);
                $_SESSION['pending_kind'] = 'admin';
                header('Location: login.php');
                exit;
            }
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            rs_login_done((int)$u['id']);
            $_SESSION['tries'] = 0;
            if (!empty($_POST['keep'])) rs_keep_issue((int)$u['id']);
            rs_log('login', $name, 'password only');
            header('Location: ' . (function_exists('rs_home_page') ? rs_home_page() : 'dashboard.php')); // (2.3) the Dashboard first
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
    <p class="muted"><?= $pendKind === 'acct' ? 'Open your authenticator app and type the 6-digit code for your RecompLauncher account. Lost your phone? Type one of its backup codes instead.' : 'Open your authenticator app and type the 6-digit code for RecompStore admin. Lost your phone? Type one of your recovery codes instead.' ?></p>
    <label>Code<input name="code" required autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="11" spellcheck="false" class="mono"></label>
    <button class="primary" type="submit">Log in</button>
    <p class="muted"><a href="logout.php">Start over</a></p>
    <?php else: ?>
    <?php if ($meAdmin): ?>
    <div class="login-acct">
      <div class="who"><?= rs_acct_avatar_url($me) !== '' ? '<img src="' . h(rs_acct_avatar_url($me)) . '" alt="">' : '<span class="me-av" aria-hidden="true">' . h(mb_strtoupper(mb_substr((string)$me['username'], 0, 1))) . '</span>' ?><span><b><?= h((string)$me['username']) ?></b><span class="muted small">Signed in with your RecompLauncher account</span></span></div>
      <button class="primary" type="submit" name="action" value="acct" formnovalidate>Continue as <?= h((string)$me['username']) ?></button>
      <p class="muted small" style="margin:0">Or use a username and password below.</p>
    </div>
    <?php endif; ?>
    <label>Username or email<input name="username" <?= $meAdmin ? '' : 'required autofocus ' ?>autocomplete="username"></label>
    <label>Password<input type="password" name="password" <?= $meAdmin ? '' : 'required ' ?>autocomplete="current-password"></label>
    <label class="check"><input type="checkbox" name="keep" value="1" checked> Keep me signed in on this device for 30 days</label>
    <button class="<?= $meAdmin ? '' : 'primary' ?>" type="submit">Log in</button>
    <?php if (!$meAdmin): ?><p class="muted small">Merged your admin login with your RecompLauncher account? <a href="/signin?back=<?= rawurlencode('/admin/login.php') ?>">Sign in with your RecompLauncher account</a>.</p><?php endif; ?>
    <?php endif; ?>
  </form>
</section>
<?php rs_foot();
