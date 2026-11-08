<?php
// (4.3) My account: everyone's own login. Name, look (Midnight, OLED black, Light), password,
// two-step login and signed-in devices. A new team member lands here first to set their password.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/totp.php';
rs_require_login();
rs_users_2fa();

$uid = (int)$_SESSION['uid'];
$me = rs_me();
$first = (int)$me['must_change'] === 1;
$go = function (string $to = 'account.php'): never { header('Location: ' . $to); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $db = rs_db();
    if ($act === 'theme') {
        $t = (string)($_POST['theme'] ?? '');
        if (isset(RS_THEMES[$t])) $db->prepare('UPDATE users SET theme = ? WHERE id = ?')->execute([$t, $uid]);
        $back = (string)($_POST['back'] ?? '');
        // Back to the page they were on (only an address on this site).
        $go(preg_match('#^/admin/[A-Za-z0-9._/?=&%+-]*$#', $back) ? $back : 'account.php#look');
    }
    if ($first && $act !== 'password') $go('account.php?first=1');
    if ($act === 'name') {
        $db->prepare('UPDATE users SET display_name = ? WHERE id = ?')->execute([mb_substr(trim((string)($_POST['display_name'] ?? '')), 0, 80), $uid]);
        rs_flash('Saved.');
        $go('account.php#you');
    } elseif ($act === 'password') {
        $st = $db->prepare('SELECT pass_hash FROM users WHERE id = ?');
        $st->execute([$uid]);
        $hash = (string)$st->fetchColumn();
        $p = (string)($_POST['password'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), $hash)) rs_flash($first ? 'The temporary password didn\'t match. It\'s the one you just logged in with.' : 'Your current password didn\'t match.', 'err');
        elseif (strlen($p) < 10) rs_flash('Make the new password at least 10 characters.', 'err');
        elseif ($p !== (string)($_POST['password2'] ?? '')) rs_flash('The two new passwords don\'t match.', 'err');
        elseif (password_verify($p, $hash)) rs_flash('Pick a password that\'s different from the one you have now.', 'err');
        else {
            $db->prepare('UPDATE users SET pass_hash = ?, must_change = 0 WHERE id = ?')->execute([password_hash($p, PASSWORD_DEFAULT), $uid]);
            // Every other device signs out; this one stays in.
            rs_team_signout($uid);
            rs_keep_cookie('', time() - 3600);
            unset($_SESSION['keep']);
            rs_login_done($uid);
            rs_log('password', 'changed', '');
            rs_flash($first ? 'You\'re all set. Welcome to the team!' : 'Password changed. Your other devices were signed out.');
            $go($first ? 'dashboard.php' : 'account.php#password');
        }
        $go($first ? 'account.php?first=1' : 'account.php#password');
    } elseif ($act === 'totp_start') {
        $_SESSION['totp_new'] = rs_new_totp_secret();
        $go('account.php#twostep');
    } elseif ($act === 'totp_cancel') {
        unset($_SESSION['totp_new']);
        $go('account.php#twostep');
    } elseif ($act === 'totp_confirm') {
        $secret = (string)($_SESSION['totp_new'] ?? '');
        $step = $secret !== '' ? rs_totp_check($secret, (string)($_POST['code'] ?? ''), 0) : 0;
        if (!$step) rs_flash('That code didn\'t match. Type the newest code your app shows for RecompStore admin.', 'err');
        else {
            $codes = rs_new_recovery_codes();
            $db->prepare('UPDATE users SET totp_secret = ?, totp_last = ?, totp_codes = ? WHERE id = ?')
                ->execute([$secret, $step, json_encode(array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $codes)), $uid]);
            unset($_SESSION['totp_new']);
            $_SESSION['recovery_once'] = $codes;
            rs_log('two-step', 'on', '');
            rs_flash('Two-step login is on. Save your recovery codes below.');
        }
        $go('account.php#twostep');
    } elseif ($act === 'totp_off') {
        $st = $db->prepare('SELECT pass_hash, totp_secret, totp_last FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch();
        $code = (string)($_POST['code'] ?? '');
        $ok = $u && password_verify((string)($_POST['password'] ?? ''), $u['pass_hash'])
            && (rs_totp_check((string)$u['totp_secret'], $code, (int)$u['totp_last']) || rs_use_recovery_code($uid, $code));
        if (!$ok) rs_flash('The password or the code didn\'t match, so two-step login stays on.', 'err');
        else {
            $db->prepare("UPDATE users SET totp_secret = '', totp_codes = NULL, totp_last = 0 WHERE id = ?")->execute([$uid]);
            rs_log('two-step', 'off', '');
            rs_flash('Two-step login is off.');
        }
        $go('account.php#twostep');
    } elseif ($act === 'keep_clear') {
        rs_team_signout($uid);
        rs_keep_cookie('', time() - 3600);
        unset($_SESSION['keep']);
        rs_login_done($uid);
        rs_log('login', 'devices', 'signed out everywhere');
        rs_flash('Every other device is signed out. This one stays logged in until you close the browser.');
        $go('account.php#devices');
    }
    $go();
}

$st = rs_db()->prepare('SELECT username, totp_secret, totp_codes FROM users WHERE id = ?');
$st->execute([$uid]);
$u = $st->fetch() ?: ['username' => '', 'totp_secret' => '', 'totp_codes' => null];
$twoOn = (string)$u['totp_secret'] !== '';
$codesLeft = count((array)json_decode((string)($u['totp_codes'] ?? '[]'), true));
$newSecret = (string)($_SESSION['totp_new'] ?? '');
$recovery = (array)($_SESSION['recovery_once'] ?? []);
unset($_SESSION['recovery_once']);
rs_keep_table();
$kst = rs_db()->prepare('SELECT selector, ua, used FROM admin_keep WHERE uid = ? AND expires > ? ORDER BY used DESC');
$kst->execute([$uid, time()]);
$keepRows = $kst->fetchAll();
$keepName = function (string $ua): string {
    $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'A browser')));
    $o = preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/iPhone|iPad/', $ua) ? 'iPhone or iPad' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Mac OS X/', $ua) ? 'Mac' : (preg_match('/Linux/', $ua) ? 'Linux' : ''))));
    return $b . ($o !== '' ? ' on ' . $o : '');
};
$role = (string)$me['role'];
$stores = implode(' and ', array_map(fn($s) => RS_STORES[$s], $me['stores_list']));
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';

rs_head('My account', !$first, ['assets/account.css']);
?>
<?php if ($first): ?>
<section class="card narrow welcome">
  <h1>Welcome, <?= h(rs_me_name()) ?></h1>
  <p class="muted">You're on the RecompStore admin team as a <strong><?= h(RS_ROLES[$role]) ?></strong> for <?= h($stores) ?>. First, swap the temporary password you were given for one only you know.</p>
  <form method="post" class="stack" autocomplete="off">
    <?= $csrf ?><input type="hidden" name="action" value="password">
    <label>Temporary password<input type="password" name="current" required autocomplete="current-password"></label>
    <label>New password<input type="password" name="password" minlength="10" required autocomplete="new-password"><small>At least 10 characters.</small></label>
    <label>New password again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <div class="row"><button class="btn primary">Save and continue</button><a class="link" href="logout.php">Log out</a></div>
  </form>
</section>
<?php rs_foot(); return; endif; ?>
<section class="head-row">
  <div>
    <h1>My account</h1>
    <p class="muted">Your login, how the admin looks for you, and keeping your account safe.</p>
  </div>
</section>

<div class="acct-grid">
<section class="card" id="you">
  <h2>You</h2>
  <div class="who">
    <span class="me-av big" aria-hidden="true"><?= h(mb_strtoupper(mb_substr(rs_me_name(), 0, 1))) ?></span>
    <div>
      <strong><?= h(rs_me_name()) ?></strong>
      <div class="muted small">Logs in as <code><?= h((string)$me['username']) ?></code></div>
      <div class="chips-row"><span class="role-chip role-<?= h($role) ?>"><?= h(RS_ROLES[$role]) ?></span><?php foreach ($me['stores_list'] as $s): ?><span class="store-chip store-<?= h($s) ?>"><?= h(RS_STORES[$s]) ?></span><?php endforeach; ?></div>
    </div>
  </div>
  <p class="muted small"><?= h(RS_ROLE_ABOUT[$role]) ?><?= $role !== 'owner' ? ' Need more? Ask the owner.' : '' ?></p>
  <form method="post" class="stack">
    <?= $csrf ?><input type="hidden" name="action" value="name">
    <label>Name the team sees<input name="display_name" maxlength="80" value="<?= h((string)$me['display_name']) ?>" placeholder="<?= h((string)$me['username']) ?>"></label>
    <div><button class="btn">Save</button></div>
  </form>
</section>

<section class="card" id="look">
  <h2>Look</h2>
  <p class="muted small">Just for you. Switch any time from your name at the top right.</p>
  <form method="post" class="theme-pick">
    <?= $csrf ?><input type="hidden" name="action" value="theme">
    <?php foreach (RS_THEMES as $k => $n): ?>
    <button name="theme" value="<?= h($k) ?>" class="theme-card t-<?= h($k === '' ? 'midnight' : $k) ?><?= rs_theme() === $k ? ' on' : '' ?>"<?= rs_theme() === $k ? ' aria-pressed="true"' : '' ?>>
      <span class="swatch" aria-hidden="true"><i></i><i></i><i></i></span>
      <span><?= h($n) ?></span>
    </button>
    <?php endforeach; ?>
  </form>
</section>

<section class="card" id="password">
  <h2>Password</h2>
  <form method="post" class="stack">
    <?= $csrf ?><input type="hidden" name="action" value="password">
    <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
    <label>New password<input type="password" name="password" minlength="10" required autocomplete="new-password"><small>At least 10 characters. Your other devices sign out.</small></label>
    <label>New password again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <div><button class="btn">Change password</button></div>
  </form>
</section>

<section class="card" id="twostep">
  <h2>Two-step login <?= $twoOn ? '<span class="pill ok">On</span>' : '<span class="pill">Off</span>' ?></h2>
  <?php if ($recovery): ?>
  <div class="once">
    <strong>Recovery codes</strong> <span class="muted">(shown once: keep them somewhere safe, like a password manager)</span>
    <pre class="mono codes" id="recovery-codes"><?= h(implode("\n", $recovery)) ?></pre>
    <button type="button" class="btn" data-copy="recovery-codes">Copy</button>
    <p class="muted small">Each one logs you in once if you lose your phone.</p>
  </div>
  <?php endif; ?>
  <?php if ($twoOn): ?>
  <p class="muted">Logging in asks for your password and then the 6-digit code from your authenticator app. <?= $codesLeft ?> recovery code<?= $codesLeft === 1 ? '' : 's' ?> left.</p>
  <details><summary>Turn two-step login off</summary>
  <form method="post" class="stack">
    <?= $csrf ?><input type="hidden" name="action" value="totp_off">
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <label>Code from the app (or a recovery code)<input name="code" required autocomplete="one-time-code" class="mono"></label>
    <div><button class="btn">Turn it off</button></div>
  </form>
  </details>
  <?php elseif ($newSecret !== ''): ?>
  <ol class="steps">
    <li>Open an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, 1Password, Authy) and scan this:
      <div class="qr" id="totp-qr" data-uri="<?= h(rs_totp_uri($newSecret, (string)$u['username'])) ?>"></div>
      <small class="muted">Can't scan? Type this key instead: <code><?= h(trim(chunk_split($newSecret, 4, ' '))) ?></code></small></li>
    <li>Type the 6-digit code the app shows:
      <form method="post" class="inline">
        <?= $csrf ?><input type="hidden" name="action" value="totp_confirm">
        <input name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="7" class="mono" placeholder="123456" aria-label="Code">
        <button class="btn primary">Turn it on</button>
      </form>
      <form method="post" class="inline"><?= $csrf ?><input type="hidden" name="action" value="totp_cancel"><button class="link">Cancel</button></form></li>
  </ol>
  <?php else: ?>
  <p class="muted">Optional, but a good idea: with it on, your password alone isn't enough to log in. A code from your phone is needed too.</p>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="totp_start"><button class="btn primary">Set up two-step login</button></form>
  <?php endif; ?>
</section>

<section class="card wide" id="devices">
  <h2>Signed-in devices <?php if ($keepRows): ?><span class="count"><?= count($keepRows) ?></span><?php endif; ?></h2>
  <?php if (!$keepRows): ?>
  <p class="muted">None kept. Tick "Keep me signed in" when you log in, and that browser stays signed in for 30 days.</p>
  <?php else: ?>
  <ul class="keep-list">
    <?php foreach ($keepRows as $k): ?><li><strong><?= h($keepName((string)$k['ua'])) ?></strong><?= ($_SESSION['keep'] ?? '') === $k['selector'] ? ' <span class="pill ok">This one</span>' : '' ?> <span class="muted">· last used <?= h(rs_date('M j, g:i a', (int)$k['used'])) ?></span></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <form method="post" onsubmit="return confirm('Sign out every other device?');"><?= $csrf ?><input type="hidden" name="action" value="keep_clear"><button class="btn danger">Sign out everywhere else</button></form>
</section>
</div>
<?php rs_foot(['assets/qrcode.js', 'assets/security.js']);
