<?php
// Recomp Store admin: site-wide settings (store release, links, tagline, password).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/totp.php';
rs_require_login();
rs_users_2fa();

$fields = [
    'site_url' => ['Site address', 'https://recompstore.com', 'Used to build full links to images in the catalog.'],
    'tagline' => ['Home page tagline', 'Recompiled games, native on PS5.', 'One line under the logo on the site.'],
    'store_version' => ['Recomp Store version', RS_VERSION, 'The newest store release. The PS5 store updates itself when this is newer.'],
    'store_build' => ['Recomp Store build', '1', 'A whole number; raise it with each release.'],
    'store_url' => ['Store zip download link', '', 'The RecompStore zip on its GitHub release.'],
    'store_sha256' => ['Store zip SHA-256', '', 'The store checks the download against this before installing.'],
    'payload_url' => ['Installer payload link', '', 'RecompStore-Installer.elf on the GitHub release, for the site\'s Get Recomp Store steps.'],
    'discord_url' => ['Discord invite', '', 'Shown on the site and the store\'s About page.'],
    'kofi_url' => ['Ko-fi', 'https://ko-fi.com/ripalda', ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'sign_token') {
        // (2.1) The token Recomp Store Signer uses here. Shown once; only its hash is kept.
        $t = bin2hex(random_bytes(32));
        rs_set_setting('signer_token_hash', hash('sha256', $t));
        $_SESSION['signer_token_once'] = $t;
        rs_log('signer', 'token', 'made a new token (the old one stops working)');
        rs_flash('A new signer token is below. Paste it into Recomp Store Signer on your PC.');
        header('Location: settings.php#signer');
        exit;
    } elseif ($act === 'sign_reset') {
        if (empty($_POST['sure'])) rs_flash('Tick the box to forget the keys.', 'err');
        else {
            rs_set_setting('sign_keys', '[]');
            rs_set_setting('signer_token_hash', '');
            rs_log('signer', 'keys', 'forgotten: Publish goes out unsigned again');
            rs_flash('The site forgot the signer\'s keys. Publish goes out unsigned again, which stores from 2.1 won\'t take.');
        }
        header('Location: settings.php#signer');
        exit;
    } elseif ($act === 'totp_start') {
        $_SESSION['totp_new'] = rs_new_totp_secret();
        header('Location: settings.php#twostep');
        exit;
    } elseif ($act === 'totp_confirm') {
        $secret = (string)($_SESSION['totp_new'] ?? '');
        $step = $secret !== '' ? rs_totp_check($secret, (string)($_POST['code'] ?? ''), 0) : 0;
        if (!$step) rs_flash('That code didn\'t match. Type the newest code your app shows for Recomp Store admin.', 'err');
        else {
            $codes = rs_new_recovery_codes();
            rs_db()->prepare('UPDATE users SET totp_secret = ?, totp_last = ?, totp_codes = ? WHERE id = ?')
                ->execute([$secret, $step, json_encode(array_map(fn($c) => password_hash(str_replace('-', '', $c), PASSWORD_DEFAULT), $codes)), $_SESSION['uid']]);
            unset($_SESSION['totp_new']);
            $_SESSION['recovery_once'] = $codes;
            rs_log('two-step', 'on', '');
            rs_flash('Two-step login is on. Save your recovery codes below.');
        }
        header('Location: settings.php#twostep');
        exit;
    } elseif ($act === 'totp_off') {
        $st = rs_db()->prepare('SELECT pass_hash, totp_secret, totp_last FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $u = $st->fetch();
        $code = (string)($_POST['code'] ?? '');
        $ok = $u && password_verify((string)($_POST['password'] ?? ''), $u['pass_hash'])
            && (rs_totp_check((string)$u['totp_secret'], $code, (int)$u['totp_last']) || rs_use_recovery_code((int)$_SESSION['uid'], $code));
        if (!$ok) rs_flash('The password or the code didn\'t match, so two-step login stays on.', 'err');
        else {
            rs_db()->prepare("UPDATE users SET totp_secret = '', totp_codes = NULL, totp_last = 0 WHERE id = ?")->execute([$_SESSION['uid']]);
            rs_log('two-step', 'off', '');
            rs_flash('Two-step login is off.');
        }
        header('Location: settings.php#twostep');
        exit;
    } elseif ($act === 'totp_cancel') {
        unset($_SESSION['totp_new']);
        header('Location: settings.php#twostep');
        exit;
    }
    if (($_POST['action'] ?? '') === 'password') {
        $p = (string)($_POST['password'] ?? '');
        if (strlen($p) < 10) rs_flash('Make the new password at least 10 characters.', 'err');
        elseif ($p !== (string)($_POST['password2'] ?? '')) rs_flash('The two passwords don\'t match.', 'err');
        else {
            rs_db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($p, PASSWORD_DEFAULT), $_SESSION['uid']]);
            rs_log('password', 'changed', '');
            rs_flash('Password changed.');
        }
    } elseif (($_POST['action'] ?? '') === 'github') {
        // (2.0) A GitHub token lifts GitHub's limit for the site's questions (60 an hour without).
        $tok = trim((string)($_POST['github_token'] ?? ''));
        if (!empty($_POST['github_clear'])) { rs_set_setting('github_token', ''); rs_flash('The GitHub token is gone. The site asks GitHub without one (60 questions an hour).'); }
        elseif ($tok === '') rs_flash('Nothing changed: paste a token to save it.', 'err');
        elseif (!preg_match('/^[A-Za-z0-9_]{20,255}$/', $tok)) rs_flash('That doesn\'t look like a GitHub token. Copy it again from GitHub.', 'err');
        else {
            rs_set_setting('github_token', $tok);
            $r = rs_http(rs_gh_api() . '/rate_limit', ['github' => true, 'timeout' => 15]);
            $j = json_decode($r['body'], true);
            $limit = (int)($j['resources']['core']['limit'] ?? 0);
            rs_flash($r['status'] === 200 && $limit > 60 ? 'Saved. GitHub takes ' . number_format($limit) . ' questions an hour from the site now.'
                     : ($r['status'] === 401 ? 'Saved, but GitHub refused it. Make a new token and paste it again.' : 'Saved. GitHub didn\'t answer the check, so it\'s untested.'),
                     $r['status'] === 401 ? 'err' : 'ok');
        }
    } elseif (($_POST['action'] ?? '') === 'r2') {
        // (2.0.1) Beta zips from Cloudflare R2: the bucket and an API token's keys.
        $acct = strtolower(trim((string)($_POST['r2_account'] ?? '')));
        $bucket = strtolower(trim((string)($_POST['r2_bucket'] ?? '')));
        $key = trim((string)($_POST['r2_key'] ?? ''));
        $secret = trim((string)($_POST['r2_secret'] ?? ''));
        if (!empty($_POST['r2_clear'])) {
            foreach (['r2_account', 'r2_bucket', 'r2_key', 'r2_secret'] as $k) rs_set_setting($k, '');
            rs_flash('Cloudflare R2 is off. Beta zips download from this site again.');
        } elseif (!preg_match('/^[0-9a-f]{32}$/', $acct)) rs_flash('The account ID is 32 letters and numbers. It\'s in the R2 page\'s Account details, and in the S3 address after https://.', 'err');
        elseif (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket)) rs_flash('The bucket name is lowercase letters, numbers and dashes, as you named it on Cloudflare.', 'err');
        elseif ($key !== '' && !preg_match('/^[A-Za-z0-9]{16,128}$/', $key)) rs_flash('That doesn\'t look like an R2 access key ID. Copy it again from the token page.', 'err');
        elseif ($secret !== '' && !preg_match('#^[A-Za-z0-9/+=]{16,128}$#', $secret)) rs_flash('That doesn\'t look like an R2 secret access key. Copy it again from the token page.', 'err');
        elseif (($key === '' && trim((string)rs_setting('r2_key', '')) === '') || ($secret === '' && trim((string)rs_setting('r2_secret', '')) === '')) rs_flash('Paste the access key ID and the secret access key too.', 'err');
        else {
            rs_set_setting('r2_account', $acct);
            rs_set_setting('r2_bucket', $bucket);
            if ($key !== '') rs_set_setting('r2_key', $key);
            if ($secret !== '') rs_set_setting('r2_secret', $secret);
            rs_set_setting('r2_on', empty($_POST['r2_on']) ? '0' : '1');
            $h = rs_r2_head(rs_r2(), '');
            rs_flash($h['status'] === 200 ? 'Saved. Cloudflare R2 answered, and the bucket is there. Copy a beta zip to it from its game\'s page, under Beta.'
                     : ($h['error'] !== '' ? 'Saved, but Cloudflare couldn\'t be reached from this server: ' . $h['error']
                     : ($h['status'] === 403 ? 'Saved, but Cloudflare refused the keys. Check the access key, the secret and that the token can read and write ' . $bucket . '.'
                     : ($h['status'] === 404 ? 'Saved, but there\'s no bucket called ' . $bucket . ' on that account.' : 'Saved, but Cloudflare answered HTTP ' . $h['status'] . '.'))),
                     $h['status'] === 200 ? 'ok' : 'err');
        }
    } elseif (($_POST['action'] ?? '') === 'blocked') {
        // (2.0) Custom sources taken down: one repo (owner/name) or list address per line.
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['blocked_sources'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '' ) continue;
            $line = rtrim((string)preg_replace('#^https://(www\.)?github\.com/#i', '', $line), '/');
            if (!rs_repo_ok($line) && !preg_match('#^https://[^\s<>"]{4,500}$#', $line)) { rs_flash('Each line is a GitHub repo (owner/name) or a list\'s https:// address. Not saved: "' . mb_substr($line, 0, 60) . '"', 'err'); header('Location: settings.php'); exit; }
            $lines[] = $line;
        }
        rs_set_setting('blocked_sources', implode("\n", array_slice(array_unique($lines), 0, 1000)));
        rs_flash('Saved. Stores drop these sources on their next refresh.');
    } elseif (($_POST['action'] ?? '') === 'site_v2') {
        // (2.0) The website's 2.0 side: only the logged-in admin (a preview), or everyone.
        $on = ($_POST['site_v2'] ?? '') === 'all';
        rs_set_setting('site_v2', $on ? 'all' : 'me');
        try {
            rs_catalog_set_site_v2($on);
            rs_flash($on ? 'Saved. Everyone sees Recomp Store 2.0 on the website now.' : 'Saved. Only you see 2.0 on the website (while logged in here); everyone else sees it as before.');
        } catch (Throwable $e) {
            rs_flash('Saved, but catalog.json couldn\'t be written: ' . $e->getMessage(), 'err');
        }
    } elseif (($_POST['action'] ?? '') === 'testers') {
        // (2.0) Consoles that get the test catalog. Only the rows that were on the page change.
        rs_beta_tables();
        $on = array_flip(array_filter(array_map('strval', (array)($_POST['tester'] ?? [])), fn($v) => (bool)preg_match('/^[0-9a-f]{16}$/', $v)));
        $st = rs_db()->prepare('UPDATE beta_installs SET tester = ? WHERE install_id = ?');
        $n = 0;
        foreach (array_slice((array)($_POST['shown'] ?? []), 0, 500) as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-f]{16}$/', $id)) continue;
            $st->execute([isset($on[$id]) ? 1 : 0, $id]);
            $n += $st->rowCount();
        }
        $all = (int)rs_db()->query('SELECT COUNT(*) FROM beta_installs WHERE tester = 1')->fetchColumn();
        rs_flash('Saved. ' . ($all === 1 ? '1 console is a tester' : $all . ' consoles are testers') . '; ' . ($n ? 'the change reaches them the next time their store starts or refreshes.' : 'nothing changed.'));
        $q = preg_replace('/[^0-9a-f]/', '', strtolower((string)($_POST['console'] ?? '')));
        header('Location: settings.php' . ($q !== '' ? '?console=' . urlencode($q) : '') . '#testers');
        exit;
    } elseif ($act === 'tabs') {
        // (2.2) The category tabs in the store's top bar by default, in the order they were on the page.
        if (!empty($_POST['tabs_default'])) {
            rs_db()->prepare('DELETE FROM settings WHERE k = ?')->execute(['store_tabs']);
            rs_log('settings', 'store tabs', 'back to the default (all four)');
            rs_flash('Saved: stores show Games, Emulators, Apps and Tools, as before. Press Publish to send it out.');
        } else {
            $show = array_flip(array_map('strval', (array)($_POST['show'] ?? [])));
            $tabs = array_values(array_filter(rs_clean_tabs((array)($_POST['order'] ?? [])), fn($k) => isset($show[$k])));
            rs_set_setting('store_tabs', json_encode($tabs));
            $names = array_map(fn($k) => RS_CATEGORIES[$k], $tabs);
            rs_log('settings', 'store tabs', $tabs ? implode(', ', $names) : 'none (only Discover, Library and Settings)');
            rs_flash('Saved: ' . ($tabs ? implode(', ', $names) : 'no category tabs, only Discover, Library and Settings') . '. Press Publish to send it out.');
        }
        header('Location: settings.php#tabs');
        exit;
    } elseif ($act === 'smp_check') {
        // (2.2) drakmor's releases, asked again now.
        [$ok, $list, $msg] = rs_smp_releases(true);
        rs_flash($ok ? 'GitHub lists ' . count($list) . ' release' . (count($list) === 1 ? '' : 's') . ' of ShadowMountPlus.' . ($msg !== '' ? ' ' . $msg : '') : $msg, $ok ? 'ok' : 'err');
        header('Location: settings.php?smp=1#smp');
        exit;
    } elseif ($act === 'smp_pin') {
        $tag = trim((string)($_POST['tag'] ?? ''));
        $why = '';
        @set_time_limit(120);
        $pin = rs_smp_fetch($tag, $why);
        if (!$pin) {
            rs_flash('Not changed. ' . $why, 'err');
        } else {
            rs_set_setting('smp_pin', json_encode($pin, JSON_UNESCAPED_SLASHES));
            rs_log('settings', 'shadowmountplus', 'offers ' . $pin['version'] . ' (' . $pin['asset'] . ($pin['elf'] !== '' ? ' > ' . $pin['elf'] : '') . ', ' . $pin['size'] . ' bytes, sha256 ' . substr($pin['sha256'], 0, 16) . ')');
            rs_flash('Saved: stores offer ShadowMountPlus ' . $pin['version'] . '. Press Publish to send it out.');
        }
        header('Location: settings.php?smp=1#smp');
        exit;
    } elseif ($act === 'smp_clear') {
        rs_db()->prepare('DELETE FROM settings WHERE k = ?')->execute(['smp_pin']);
        rs_log('settings', 'shadowmountplus', 'no version offered');
        rs_flash('Saved: stores don\'t offer a ShadowMountPlus download. Press Publish to send it out.');
        header('Location: settings.php#smp');
        exit;
    } elseif ($act === 'smp_min') {
        $min = trim((string)($_POST['smp_min'] ?? ''));
        if ($min === '') $min = RS_SMP_MIN_DEFAULT;
        if (!rs_smp_version_ok($min)) rs_flash('A version is letters, numbers, dots and dashes, like 1.7beta5.', 'err');
        else {
            rs_set_setting('smp_min', $min);
            rs_log('settings', 'shadowmountplus minimum', $min);
            rs_flash('Saved: stores warn about ShadowMountPlus older than ' . $min . '. Press Publish to send it out.');
        }
        header('Location: settings.php#smp');
        exit;
    } elseif (($_POST['action'] ?? '') === 'taken') {
        // (2.0) Title IDs taken outside the store: one per line, the ID then what uses it.
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['taken_ids'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^[A-Z]{4}\d{5}\b/', $line)) { rs_flash('Each line starts with a title ID, like PPSA92004 What uses it. Not saved: "' . mb_substr($line, 0, 60) . '"', 'err'); header('Location: settings.php'); exit; }
            $lines[] = mb_substr($line, 0, 100);
        }
        rs_set_setting('taken_ids', implode("\n", array_slice($lines, 0, 500)));
        rs_flash('Saved. The admin won\'t give a game one of these, and recompstore.com/#/ids lists them.');
    } else {
        foreach ($fields as $k => $_) rs_set_setting($k, trim((string)($_POST[$k] ?? '')));
        rs_log('settings', 'site', '');
        rs_flash('Saved. Press Publish on the Games page to send store release changes to the PS5.');
    }
    if (in_array($act, ['github', 'r2', 'blocked', 'site_v2', 'taken'], true)) rs_log('settings', $act, '');
    header('Location: settings.php');
    exit;
}

rs_head('Settings');
?>
<section class="head-row"><div><h1>Settings</h1></div><a class="btn" href="log.php">Change log</a></section>
<?php
// (2.1) Security: the signer on the owner's PC, and two-step login.
$keys = rs_sign_keys();
$tokenOnce = (string)($_SESSION['signer_token_once'] ?? '');
unset($_SESSION['signer_token_once']);
$hasToken = rs_setting('signer_token_hash', '') !== '';
$waiting = rs_sign_waiting();
$me = rs_db()->prepare('SELECT username, totp_secret, totp_codes FROM users WHERE id = ?');
$me->execute([$_SESSION['uid']]);
$me = $me->fetch() ?: ['username' => '', 'totp_secret' => '', 'totp_codes' => null];
$twoOn = (string)$me['totp_secret'] !== '';
$codesLeft = count((array)json_decode((string)($me['totp_codes'] ?? '[]'), true));
$newSecret = (string)($_SESSION['totp_new'] ?? '');
$recovery = (array)($_SESSION['recovery_once'] ?? []);
unset($_SESSION['recovery_once']);
?>
<section class="card" id="signer">
  <h2>Signer <?= $keys ? '<span class="pill ok">On</span>' : '<span class="pill">Off</span>' ?></h2>
  <?php if ($keys): ?>
  <p class="muted">Publish waits for Recomp Store Signer on your PC: it shows what changed, and only what you sign goes out. Beta zips need its signature too. Stores from 2.1 check every signature against your keys, so a break-in here can't push anything to them.</p>
  <p>Keys: <?php foreach ($keys as $k): ?><code><?= h($k['name']) ?> <?= h($k['id']) ?></code> <?php endforeach; ?></p>
  <?php if ($waiting): ?><p class="warn"><?= $waiting === 1 ? '1 thing is' : $waiting . ' things are' ?> waiting for your signature. Open Recomp Store Signer on your PC.</p><?php endif; ?>
  <?php else: ?>
  <p class="muted">Recomp Store Signer runs on your PC and keeps the signing key there. Make a token here, paste it into the signer (step 3), and from then on Publish waits for your signature.</p>
  <?php endif; ?>
  <?php if ($tokenOnce !== ''): ?>
  <div class="once">
    <strong>Your signer token</strong> <span class="muted">(shown once)</span>
    <div class="copyrow"><input readonly value="<?= h($tokenOnce) ?>" class="mono" id="signer-token" spellcheck="false"><button type="button" class="btn" data-copy="signer-token">Copy</button></div>
  </div>
  <?php endif; ?>
  <form method="post" class="actions">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="sign_token">
    <button class="btn"><?= $hasToken ? 'Make a new signer token' : 'Make a signer token' ?></button>
    <?php if ($hasToken): ?><small class="muted">The old one stops working.</small><?php endif; ?>
  </form>
  <?php if ($keys): ?>
  <details class="danger"><summary>Forget the keys</summary>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="sign_reset">
    <p class="muted">Only for new keys (the store has to be updated with them first). Until a signer connects again, Publish goes out unsigned, and stores from 2.1 won't take it.</p>
    <label class="check"><input type="checkbox" name="sure" value="1"> Forget the signer's keys and token</label>
    <button class="btn">Forget them</button>
  </form>
  </details>
  <?php endif; ?>
</section>
<section class="card" id="twostep">
  <h2>Two-step login <?= $twoOn ? '<span class="pill ok">On</span>' : '<span class="pill">Off</span>' ?></h2>
  <?php if ($recovery): ?>
  <div class="once">
    <strong>Recovery codes</strong> <span class="muted">(shown once: save them somewhere safe, like a password manager or on paper)</span>
    <pre class="mono codes" id="recovery-codes"><?= h(implode("\n", $recovery)) ?></pre>
    <button type="button" class="btn" data-copy="recovery-codes">Copy</button>
    <p class="muted">Each one logs you in once if your phone is lost.</p>
  </div>
  <?php endif; ?>
  <?php if ($twoOn): ?>
  <p class="muted">Logging in asks for your password and then the 6-digit code from your authenticator app. <?= $codesLeft ?> recovery code<?= $codesLeft === 1 ? '' : 's' ?> left.</p>
  <details><summary>Turn two-step login off</summary>
  <form method="post" class="grid2">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="totp_off">
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <label>Code from the app (or a recovery code)<input name="code" required autocomplete="one-time-code" class="mono"></label>
    <div><button class="btn">Turn it off</button></div>
  </form>
  </details>
  <?php elseif ($newSecret !== ''): ?>
  <ol class="steps">
    <li>Open an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, 1Password, Authy) and add an account by scanning this:
      <div class="qr" id="totp-qr" data-uri="<?= h(rs_totp_uri($newSecret, (string)$me['username'])) ?>"></div>
      <small class="muted">Can't scan? Type this key instead: <code><?= h(trim(chunk_split($newSecret, 4, ' '))) ?></code></small></li>
    <li>Type the 6-digit code the app shows:
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="totp_confirm">
        <input name="code" required autocomplete="one-time-code" inputmode="numeric" maxlength="7" class="mono" placeholder="123456" aria-label="Code">
        <button class="btn primary">Turn it on</button>
      </form>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="totp_cancel"><button class="link">Cancel</button></form></li>
  </ol>
  <?php else: ?>
  <p class="muted">With it on, your password alone isn't enough to log in: a code from your phone is needed too. Strongly recommended, since this admin decides what every PS5 installs.</p>
  <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="totp_start"><button class="btn primary">Set up two-step login</button></form>
  <?php endif; ?>
</section>
<section class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <div class="grid2">
    <?php foreach ($fields as $k => [$label, $default, $help]): ?>
      <label><?= h($label) ?><input name="<?= h($k) ?>" value="<?= h(rs_setting($k, $default)) ?>"><?php if ($help): ?><small><?= h($help) ?></small><?php endif; ?></label>
    <?php endforeach; ?>
    </div>
    <button class="primary">Save settings</button>
  </form>
</section>
<?php
// (2.2) The store's default tabs, and the ShadowMountPlus it offers.
$tabsSaved = rs_store_tabs();
$tabsOn = $tabsSaved ?? array_keys(RS_CATEGORIES);
$tabRows = array_merge($tabsOn, array_values(array_diff(array_keys(RS_CATEGORIES), $tabsOn)));
$pin = rs_smp_pin();
$smpOpen = isset($_GET['smp']);
[$smpOk, $smpList, $smpMsg] = $smpOpen ? rs_smp_releases(false) : [true, [], ''];
?>
<section class="card" id="tabs">
  <h2>Store tabs</h2>
  <p class="muted">The parts of the store that get a tab in the store's top bar, in this order. Discover, Library and Settings are always there, and every part stays on Discover either way. Players can change theirs in the store's Customize. Recomp Store 2.2 and newer.</p>
  <form method="post" data-tabs>
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="tabs">
    <ol class="tab-order">
    <?php foreach ($tabRows as $i => $k): ?>
      <li data-tab>
        <input type="hidden" name="order[]" value="<?= h($k) ?>">
        <label class="check"><input type="checkbox" name="show[]" value="<?= h($k) ?>" <?= in_array($k, $tabsOn, true) ? 'checked' : '' ?>> <?= h(RS_CATEGORIES[$k]) ?></label>
        <span class="sec-tools">
          <button type="button" class="icon-btn" data-tab-move="-1" aria-label="Move <?= h(RS_CATEGORIES[$k]) ?> up">↑</button>
          <button type="button" class="icon-btn" data-tab-move="1" aria-label="Move <?= h(RS_CATEGORIES[$k]) ?> down">↓</button>
        </span>
      </li>
    <?php endforeach; ?>
    </ol>
    <div class="actions">
      <button class="btn">Save tabs</button>
      <?php if ($tabsSaved !== null): ?><button class="link" name="tabs_default" value="1">Back to the default</button><?php endif; ?>
      <small class="muted"><?= $tabsSaved === null ? 'Not set: stores show all four, as before.' : 'Press Publish after saving.' ?></small>
    </div>
  </form>
</section>
<section class="card" id="smp">
  <h2>ShadowMountPlus <?= $pin ? '<span class="pill ok">' . h($pin['version']) . '</span>' : '<span class="pill">None offered</span>' ?></h2>
  <p class="muted">The store can download ShadowMountPlus for players, so they don't have to find it themselves. Pick the release you've tested: the site downloads it once to note its checksum, and stores take only that file, from drakmor's GitHub. It goes out with the signed catalog when you Publish.</p>
  <?php if ($pin): ?>
  <dl class="smp-pin">
    <div><dt>Offered</dt><dd><?= h($pin['version']) ?><?= $pin['published'] !== '' ? ' <span class="muted">· released ' . h(date('M j, Y', strtotime($pin['published']))) . '</span>' : '' ?></dd></div>
    <div><dt>File</dt><dd><code><?= h($pin['asset']) ?></code><?= $pin['elf'] !== '' ? ' › <code>' . h($pin['elf']) . '</code>' : '' ?> <span class="muted">· <?= h(number_format($pin['size'] / 1048576, 1)) ?> MB</span></dd></div>
    <div><dt>SHA-256</dt><dd><code class="hash"><?= h($pin['sha256']) ?></code></dd></div>
  </dl>
  <?php endif; ?>
  <div class="actions">
    <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="smp_check"><button class="btn">Check GitHub</button></form>
    <?php if (!$smpOpen): ?><a href="settings.php?smp=1#smp">Show the releases</a><?php endif; ?>
    <?php if ($pin): ?><form method="post" class="inline" onsubmit="return confirm('Stop offering ShadowMountPlus in the store?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="smp_clear"><button class="link">Stop offering it</button></form><?php endif; ?>
  </div>
  <?php if ($smpOpen): ?>
    <?php if (!$smpOk): ?><p class="warn"><?= h($smpMsg) ?></p>
    <?php elseif (!$smpList): ?><p class="muted">GitHub lists no releases.</p>
    <?php else: ?>
    <?php if ($smpMsg !== ''): ?><p class="muted"><?= h($smpMsg) ?></p><?php endif; ?>
    <div class="table-wrap"><table class="stats smp-rel">
      <thead><tr><th>Release</th><th>Released</th><th>File</th><th></th></tr></thead>
      <tbody>
      <?php foreach (array_slice($smpList, 0, 12) as $rel): $a = rs_smp_asset($rel); $tag = (string)$rel['tag_name']; $on = $pin && $pin['version'] === $tag; ?>
        <tr<?= $on ? ' class="on"' : '' ?>>
          <td><strong><?= h($tag) ?></strong><?= !empty($rel['prerelease']) ? ' <span class="pill">Pre-release</span>' : '' ?></td>
          <td class="muted"><?= $rel['published_at'] !== '' ? h(date('M j, Y', strtotime((string)$rel['published_at']))) : '' ?></td>
          <td><?= $a ? '<code>' . h((string)$a['name']) . '</code> <span class="muted">' . h(number_format((int)($a['size'] ?? 0) / 1048576, 1)) . ' MB</span>' : '<span class="muted">No .elf or zip</span>' ?></td>
          <td><?php if ($on): ?><span class="pill ok">Offered</span><?php elseif ($a): ?>
            <form method="post" class="inline" data-smp-pin><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="smp_pin"><input type="hidden" name="tag" value="<?= h($tag) ?>"><button class="btn">Offer this one</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  <?php endif; ?>
  <form method="post" class="find-row smp-min">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="smp_min">
    <label>Oldest that works<input name="smp_min" value="<?= h(rs_smp_min()) ?>" maxlength="40" class="mono" spellcheck="false"></label>
    <button class="btn">Save</button>
    <small class="muted">Stores warn players running an older one. Before 1.7beta5, ShadowMountPlus pauses kstuff while the store is open, and sending it again takes the open store's storage away.</small>
  </form>
</section>
<?php $v2all = rs_site_v2(); ?>
<section class="card" id="site-v2">
  <h2>Recomp Store 2.0 on the website</h2>
  <form method="post" class="choice">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="site_v2">
    <label class="check"><input type="radio" name="site_v2" value="me" <?= $v2all ? '' : 'checked' ?>> <span><strong>Only me (preview)</strong><small>Visitors see the site as it is today: games only, no categories, charts, My PS5 or Title IDs page. You see all of 2.0 while you're logged in here, with a Preview ribbon, and so does a browser linked to a PS5 (it has 2.0). Links from a PS5 (to link a phone or finish a bug report) work for everyone either way.</small></span></label>
    <label class="check"><input type="radio" name="site_v2" value="all" <?= $v2all ? 'checked' : '' ?>> <span><strong>Everyone</strong><small>Turn this on when Recomp Store 2.0 is out. It changes catalog.json's site.v2 at once: nothing else waiting is published.</small></span></label>
    <button class="btn">Save</button>
  </form>
</section>
<?php
// (2.0) Testers: the consoles that get the test catalog. Those marked first, then the 50 seen most
// recently, or the ones whose Console ID starts with what was searched.
rs_beta_tables();
$find = preg_replace('/[^0-9a-f]/', '', strtolower((string)($_GET['console'] ?? '')));
if ($find !== '') {
    $st = rs_db()->prepare('SELECT install_id, store_version, tester, created, last_seen FROM beta_installs WHERE install_id LIKE ? ORDER BY last_seen DESC LIMIT 50');
    $st->execute([substr($find, 0, 16) . '%']);
    $consoles = $st->fetchAll();
} else {
    $consoles = rs_db()->query('(SELECT install_id, store_version, tester, created, last_seen FROM beta_installs WHERE tester = 1)
        UNION (SELECT install_id, store_version, tester, created, last_seen FROM beta_installs ORDER BY last_seen DESC LIMIT 50)
        ORDER BY tester DESC, last_seen DESC')->fetchAll();
}
$testerCount = (int)rs_db()->query('SELECT COUNT(*) FROM beta_installs WHERE tester = 1')->fetchColumn();
$when = fn(string $t) => date('M j, Y g:i a', strtotime($t));
?>
<section class="card" id="testers">
  <h2>Testers <?php if ($testerCount): ?><span class="count"><?= $testerCount ?></span><?php endif; ?></h2>
  <p class="muted">A tester's store reads the test catalog: every app <?= rs_sign_on() ? 'as of your last signed Publish' : 'as you save it here, without Publish' ?>, including the ones set to Testers only (marked as testing). Everyone else gets the published catalog. Ask a tester for the Console ID in Recomp Store's Settings, under About.</p>
  <form method="get" class="find-row" action="settings.php#testers">
    <input name="console" value="<?= h($find) ?>" maxlength="16" placeholder="Find a Console ID" autocomplete="off" spellcheck="false" class="mono">
    <button class="btn">Find</button><?php if ($find !== ''): ?> <a href="settings.php#testers">Show all</a><?php endif; ?>
  </form>
  <?php if ($consoles): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="testers"><input type="hidden" name="console" value="<?= h($find) ?>">
    <div class="table-wrap"><table class="stats testers">
      <thead><tr><th>Tester</th><th>Console ID</th><th>Recomp Store</th><th>Last seen</th><th>First seen</th></tr></thead>
      <tbody>
      <?php foreach ($consoles as $c): $id = (string)$c['install_id']; ?>
        <tr<?= (int)$c['tester'] ? ' class="on"' : '' ?>>
          <td><input type="hidden" name="shown[]" value="<?= h($id) ?>"><input type="checkbox" name="tester[]" value="<?= h($id) ?>" aria-label="Tester: <?= h(substr($id, 0, 8)) ?>" <?= (int)$c['tester'] ? 'checked' : '' ?>></td>
          <td><code class="cid"><?= h(substr($id, 0, 8)) ?></code><span class="muted rest"><?= h(substr($id, 8)) ?></span></td>
          <td><?= $c['store_version'] !== '' ? h((string)$c['store_version']) : '<span class="muted">?</span>' ?></td>
          <td><?= h($when((string)$c['last_seen'])) ?></td>
          <td class="muted"><?= h($when((string)$c['created'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <button class="btn">Save testers</button>
  </form>
  <?php else: ?>
  <p class="muted"><?= $find !== '' ? 'No console\'s ID starts with ' . h($find) . '.' : 'No consoles yet. A console shows up here once Recomp Store 2.0 opens on it.' ?></p>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Title IDs taken outside the store</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="taken">
    <label><span>One per line: the ID, then what uses it</span><textarea name="taken_ids" rows="6" class="mono" placeholder="PPSA92004 An app already on many consoles"><?= h((string)rs_setting('taken_ids', RS_TAKEN_DEFAULT)) ?></textarea><small>The admin won't give a game one of these, and <a href="/#/ids" target="_blank">recompstore.com/#/ids</a> lists them with every ID in the store, so developers can pick one that won't clash. <?= h(RS_MOVE_BLOCK[0]) ?> to <?= h(RS_MOVE_BLOCK[1]) ?> are always kept for the PS5 store itself.</small></label>
    <button class="btn">Save the list</button>
  </form>
</section>
<section class="card">
  <h2>GitHub</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="github">
    <?php $hasTok = trim((string)rs_setting('github_token', '')) !== ''; ?>
    <label><span>GitHub token <span class="muted">(optional)</span></span><input type="text" name="github_token" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore style="-webkit-text-security: disc; text-security: disc" placeholder="<?= $hasTok ? 'A token is saved. Paste a new one to replace it.' : 'Paste a token' ?>"><small>Every PS5 asks recompstore.com about releases and custom sources, and the site asks GitHub once for everyone. Without a token GitHub answers 60 questions an hour; with one, 5,000. Make a fine-grained token on GitHub (Settings, Developer settings, Personal access tokens) with read-only access to public repositories, and paste it here.</small></label>
    <div class="actions"><button class="btn">Save the token</button><?php if ($hasTok): ?><label class="check"><input type="checkbox" name="github_clear" value="1"> Remove the saved token</label><?php endif; ?></div>
  </form>
</section>
<section class="card" id="r2">
  <h2>Beta downloads from Cloudflare R2</h2>
  <?php $r2 = rs_r2(); $hasSecret = trim((string)rs_setting('r2_secret', '')) !== ''; $hasKey = trim((string)rs_setting('r2_key', '')) !== ''; ?>
  <p class="muted">A beta zip downloads from this site at about 0.5 MB/s. From Cloudflare R2 it comes from Cloudflare's network instead, and testers still need a code: each tester's store gets a link that works for 12 hours. Make a bucket on R2, then an R2 API token with Object Read &amp; Write on it, and fill these in. Then copy each beta zip to R2 from its game's page, under Beta.</p>
  <form method="post" class="grid2">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="r2">
    <label>Account ID<input name="r2_account" value="<?= h((string)rs_setting('r2_account', '')) ?>" maxlength="32" autocomplete="off" spellcheck="false" class="mono"><small>32 letters and numbers, on the R2 page.</small></label>
    <label>Bucket<input name="r2_bucket" value="<?= h((string)rs_setting('r2_bucket', '')) ?>" maxlength="63" autocomplete="off" spellcheck="false" placeholder="recompstore-beta"></label>
    <label>Access key ID<input type="text" name="r2_key" autocomplete="off" spellcheck="false" data-lpignore="true" data-1p-ignore class="mono" placeholder="<?= $hasKey ? 'Saved. Paste a new one to replace it.' : 'From the API token page' ?>"></label>
    <label>Secret access key<input type="text" name="r2_secret" autocomplete="off" spellcheck="false" data-lpignore="true" data-1p-ignore class="mono" style="-webkit-text-security: disc; text-security: disc" placeholder="<?= $hasSecret ? 'Saved. Paste a new one to replace it.' : 'Shown once, when you make the token' ?>"></label>
    <label class="check span2"><input type="checkbox" name="r2_on" value="1" <?= rs_setting('r2_on', '1') === '1' ? 'checked' : '' ?>> Testers download beta zips from R2 (untick to send them back to this site, for example if a PS5 can't reach Cloudflare)</label>
    <div class="actions span2"><button class="btn">Save and check</button><?php if ($r2): ?><label class="check"><input type="checkbox" name="r2_clear" value="1"> Turn R2 off and forget the keys</label><?php endif; ?></div>
  </form>
</section>
<section class="card">
  <h2>Blocked sources</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="blocked">
    <label><span>One per line: a GitHub repo (owner/name) or a list's address</span><textarea name="blocked_sources" rows="5" class="mono" placeholder="someone/bad-repo"><?= h((string)rs_setting('blocked_sources', '')) ?></textarea><small>Custom sources people add in the store can be anything. Ones listed here are refused, and stores that already added them drop them on their next refresh. Reports people send about a source are in the Inbox, under Sources.</small></label>
    <button class="btn">Save the list</button>
  </form>
</section>
<section class="card">
  <h2>Change your admin password</h2>
  <form method="post" class="grid2">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="password">
    <label>New password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>New password again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <div><button class="btn">Change password</button></div>
  </form>
</section>
<?php rs_foot(['assets/qrcode.js', 'assets/security.js', 'assets/settings.js']);
