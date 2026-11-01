<?php
// RecompStore admin (3.0): Accounts. Player accounts, sign-in keys and comment moderation.
// Accounts stay off for visitors until "Turn accounts on"; the preview link turns them on in this
// browser only, to try everything first.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_acct_tables();
rs_cm_tables();

if (rs_setting('acct_preview_key', '') === '') rs_set_setting('acct_preview_key', bin2hex(random_bytes(16)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'accounts.php';
    $db = rs_db();
    // (3.1g) "Keep sending": the next 40, answered as JSON so the page can carry on by itself.
    if ($act === 'pm-next-json') {
        header('Content-Type: application/json');
        @set_time_limit(180);
        try {
            $mid = (int)($_POST['id'] ?? 0);
            $st = $db->prepare('SELECT list FROM player_mail WHERE id = ?'); $st->execute([$mid]);
            [$sent, $left] = (string)$st->fetchColumn() === 'beta' ? rs_lbeta_send_batch($mid, 40) : rs_pmail_send_batch($mid, 40);
            echo json_encode(['sent' => $sent, 'left' => $left]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    try {
        if ($act === 'switch') {
            $on = ($_POST['on'] ?? '') === '1';
            rs_set_setting('acct_on', $on ? '1' : '0');
            rs_log('accounts', $on ? 'on' : 'off');
            rs_flash($on ? 'Accounts are on for everyone.' : 'Accounts are off for visitors. Your preview still works.');
        } elseif ($act === 'keys') {
            $text = ['acct_ts_site' => 120, 'acct_google_id' => 200, 'acct_gh_id' => 80, 'dc_client_id' => 40, 'acct_contact' => 190, 'acct_staff' => 300];
            foreach ($text as $k => $max) {
                if (!isset($_POST[$k])) continue;
                $v = mb_substr(trim((string)$_POST[$k]), 0, $max);
                if ($k === 'dc_client_id' && $v === '') continue; // the beta codes use it too: never cleared from here
                rs_set_setting($k, $v);
            }
            foreach (['acct_ts_secret', 'acct_google_secret', 'acct_gh_secret', 'dc_client_secret'] as $k) {
                $v = trim((string)($_POST[$k] ?? ''));
                if ($v !== '') rs_secret_put($k, $v);
            }
            foreach (['google', 'discord', 'github'] as $p) if (isset($_POST['form_' . $p])) rs_set_setting('acct_' . $p . '_on', !empty($_POST['on_' . $p]) ? '1' : '0');
            if (isset($_POST['form_discord'])) rs_set_setting('acct_dc_redirect_done', !empty($_POST['on_discord']) ? '1' : '');
            if (isset($_POST['form_rules'])) rs_set_setting('acct_dev_2fa', !empty($_POST['dev_2fa']) ? '1' : '0');
            $c = trim((string)rs_setting('acct_contact', ''));
            if ($c !== '' && !filter_var($c, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The contact email isn\'t an email address.');
            rs_log('accounts', 'keys');
            rs_flash('Saved.');
            $back = 'accounts.php#' . preg_replace('/[^a-z-]/', '', (string)($_POST['spot'] ?? 'setup'));
        } elseif ($act === 'ban' || $act === 'unban') {
            $id = (int)($_POST['id'] ?? 0);
            rs_acct_update($id, ['banned' => $act === 'ban' ? 1 : 0]);
            if ($act === 'ban') $db->prepare('DELETE FROM account_sessions WHERE account_id = ?')->execute([$id]);
            rs_flash($act === 'ban' ? 'Account closed. They\'re signed out and can\'t sign in.' : 'Account open again.');
            $back = 'accounts.php#people';
        } elseif ($act === 'confirm_email') {
            // (3.1g) Confirm an account's email by hand, when the confirmation email didn't reach them.
            $id = (int)($_POST['id'] ?? 0);
            rs_acct_update($id, ['email_ok' => 1]);
            rs_log('accounts', 'confirm-email ' . $id);
            rs_flash('Email confirmed. They can sign in now.');
            $back = 'accounts.php' . (($_POST['q'] ?? '') !== '' ? '?q=' . rawurlencode((string)$_POST['q']) : '') . '#people';
        } elseif ($act === 'delete') {
            $acc = rs_acct_get((int)($_POST['id'] ?? 0));
            if ($acc) {
                $db->prepare('DELETE FROM comments WHERE account_id = ?')->execute([(int)$acc['id']]);
                rs_acct_delete($acc);
                rs_log('account-delete', (string)$acc['uid'], 'by admin');
            }
            rs_flash('Account and its comments deleted.');
            $back = 'accounts.php#people';
        } elseif ($act === 'pm-send') {
            rs_pmail_tables();
            $list = (string)($_POST['list'] ?? '');
            if (!isset(RS_PMAIL_LISTS[$list]) || $list === 'updates') throw new RuntimeException('Pick who it goes to.');
            $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200);
            $body = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''))), 0, 20000);
            if ($subject === '' || $body === '') throw new RuntimeException('Write a subject and a message.');
            if (!rs_mail_ready()) throw new RuntimeException('Set up the site\'s mailbox on the Mail page first.');
            $db->prepare('INSERT INTO player_mail (list, subject, body, created) VALUES (?, ?, ?, UTC_TIMESTAMP())')->execute([$list, $subject, $body]);
            $mid = (int)$db->lastInsertId();
            $first = max(0, (int)($_POST['first'] ?? 0));
            if ($list === 'beta' && $first > 0) rs_set_setting('pm_cap_' . $mid, (string)$first);
            [$sent, $left] = $list === 'beta' ? rs_lbeta_send_batch($mid, 40) : rs_pmail_send_batch($mid, 40);
            rs_log('player-mail', $list, $subject);
            rs_flash('Sent to ' . $sent . ($left ? '. ' . $left . ' to go: press Send the next 40.' : '. That\'s everyone.'));
            $back = 'accounts.php#emails';
        } elseif ($act === 'pm-next') {
            $mid = (int)($_POST['id'] ?? 0);
            $st = $db->prepare('SELECT list FROM player_mail WHERE id = ?'); $st->execute([$mid]);
            [$sent, $left] = (string)$st->fetchColumn() === 'beta' ? rs_lbeta_send_batch($mid, 40) : rs_pmail_send_batch($mid, 40);
            rs_flash('Sent to ' . $sent . ($left ? '. ' . $left . ' to go.' : '. That\'s everyone.'));
            $back = 'accounts.php#emails';
        } elseif ($act === 'pm-updates') {
            if (!rs_mail_ready()) throw new RuntimeException('Set up the site\'s mailbox on the Mail page first.');
            [$sent, $left] = rs_pmail_updates_send(40);
            rs_flash($sent ? 'Update emails sent to ' . $sent . ($left ? '. ' . $left . ' to go: press it again.' : '.') : 'Nobody has a saved game with a new version right now.');
            $back = 'accounts.php#emails';
        } elseif ($act === 'beta-switch') {
            rs_set_setting('launcher_beta_on', ($_POST['on'] ?? '') === '1' ? '1' : '0');
            rs_flash(($_POST['on'] ?? '') === '1' ? 'The beta pop-up and sign-up are on.' : 'The beta pop-up and sign-up are off.');
            $back = 'accounts.php#beta';
        } elseif ($act === 'beta-remove') {
            rs_lbeta_tables();
            $db->prepare('DELETE FROM launcher_beta WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            rs_flash('Taken off the list.');
            $back = 'accounts.php#beta';
        } elseif ($act === 'fb-hide' || $act === 'fb-show') {
            rs_play_tables();
            $rid = (int)($_POST['id'] ?? 0);
            $db->prepare('UPDATE play_reports SET hidden = ?, flags = IF(? = 0, 0, flags) WHERE id = ?')->execute([$act === 'fb-hide' ? 1 : 0, $act === 'fb-hide' ? 1 : 0, $rid]);
            if ($act === 'fb-show') $db->prepare('DELETE FROM play_flags WHERE report_id = ?')->execute([$rid]);
            // An old-style report hides at its source too, so the copy doesn't come back.
            $src = $db->prepare('SELECT source, legacy_id FROM play_reports WHERE id = ?'); $src->execute([$rid]); $sr = $src->fetch();
            if ($sr && $sr['source'] === 'pcfb') $db->prepare('UPDATE pc_feedback SET hidden = ? WHERE id = ?')->execute([$act === 'fb-hide' ? 1 : 0, (int)$sr['legacy_id']]);
            if ($sr && $sr['source'] === 'ps5fb') $db->prepare('UPDATE ps5_feedback SET hidden = ? WHERE id = ?')->execute([$act === 'fb-hide' ? 1 : 0, (int)$sr['legacy_id']]);
            rs_play_scores_forget();
            rs_flash($act === 'fb-hide' ? 'Report hidden.' : 'Report back up.');
            $back = 'accounts.php#ps5-reports';
        } elseif ($act === 'cm-show' || $act === 'cm-remove') {
            $cid = (int)($_POST['id'] ?? 0);
            if ($act === 'cm-show') {
                $db->prepare('UPDATE comments SET hidden = 0, reports = 0 WHERE id = ?')->execute([$cid]);
                $db->prepare('DELETE FROM comment_reports WHERE comment_id = ?')->execute([$cid]);
            } else {
                $db->prepare('UPDATE comments SET removed = 1, hidden = 0, body = \'\' WHERE id = ?')->execute([$cid]);
                $db->prepare('DELETE FROM comment_reports WHERE comment_id = ?')->execute([$cid]);
            }
            rs_flash($act === 'cm-show' ? 'Comment back up, reports cleared.' : 'Comment removed.');
            $back = 'accounts.php#moderation';
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$db = rs_db();
$live = rs_acct_live();
$prov = rs_acct_providers();
$tsReady = rs_acct_turnstile_ready();
$mailReady = rs_mail_ready();
$contact = (string)rs_setting('acct_contact', '');
$previewUrl = 'https://recompstore.com/api/account.php?a=preview&k=' . rs_setting('acct_preview_key', '');
$redirect = rs_acct_oauth_redirect();
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$admin2fa = false;
try { $st = $db->prepare("SELECT totp_secret FROM users WHERE id = ?"); $st->execute([(int)($_SESSION['uid'] ?? 0)]); $admin2fa = (string)$st->fetchColumn() !== ''; } catch (Throwable $e) { $admin2fa = false; }

$q = trim((string)($_GET['q'] ?? ''));
$total = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$week = (int)$db->query('SELECT COUNT(*) FROM accounts WHERE created > (UTC_TIMESTAMP() - INTERVAL 7 DAY)')->fetchColumn();
if ($q !== '') {
    $st = $db->prepare('SELECT * FROM accounts WHERE username_lc LIKE ? OR email_lc LIKE ? ORDER BY created DESC LIMIT 100');
    $like = '%' . strtolower(addcslashes($q, '%_\\')) . '%';
    $st->execute([$like, $like]);
    $people = $st->fetchAll();
} else $people = $db->query('SELECT * FROM accounts ORDER BY created DESC LIMIT 50')->fetchAll();
$cmCount = (int)$db->query('SELECT COUNT(*) FROM comments WHERE removed = 0')->fetchColumn();
$queue = $db->query("SELECT c.*, a.username FROM comments c LEFT JOIN accounts a ON a.id = c.account_id WHERE c.removed = 0 AND (c.hidden = 1 OR c.reports > 0) ORDER BY c.hidden DESC, c.reports DESC, c.created DESC LIMIT 100")->fetchAll();
$reasons = [];
if ($queue) foreach ($db->query('SELECT comment_id, reason, COUNT(*) n FROM comment_reports GROUP BY comment_id, reason')->fetchAll() as $r) $reasons[(int)$r['comment_id']][] = $r['reason'] . ($r['n'] > 1 ? ' ×' . $r['n'] : '');
$lists = ['weekly' => 0, 'launcher' => 0, 'updates' => 0];
foreach ($db->query("SELECT prefs FROM accounts WHERE email_ok = 1 AND banned = 0 AND prefs IS NOT NULL")->fetchAll() as $r) {
    $p = json_decode((string)$r['prefs'], true) ?: [];
    foreach ($lists as $k => $n) if (!empty($p[$k])) $lists[$k]++;
}
rs_pmail_tables();
rs_ps5fb_tables();
$draft = isset($_GET['draft']) ? rs_pmail_weekly_draft() : null;
$mails = $db->query('SELECT * FROM player_mail ORDER BY id DESC LIMIT 10')->fetchAll();
rs_lbeta_tables();
$audience = ['weekly' => count(rs_pmail_audience('weekly')), 'launcher' => count(rs_pmail_audience('launcher')), 'beta' => count(rs_lbeta_audience())];
$betaRows = $db->query('SELECT b.*, a.username FROM launcher_beta b LEFT JOIN accounts a ON a.id = b.account_id ORDER BY b.id DESC LIMIT 60')->fetchAll();
$betaWaiting = (int)$db->query('SELECT COUNT(*) FROM launcher_beta WHERE confirmed = 0 AND off = 0')->fetchColumn();
$mq = rs_mailq_stats();
$betaOn = rs_lbeta_on();
$updatesDue = count(rs_pmail_updates_due());
rs_play_sync_legacy();
// Flagged ones first, then the newest.
$ps5fb = $db->query('SELECT r.*, a.username FROM play_reports r LEFT JOIN accounts a ON a.id = r.account_id ORDER BY (r.flags > 0) DESC, r.updated DESC LIMIT 60')->fetchAll();
$fbFlagged = (int)$db->query('SELECT COUNT(*) FROM play_reports WHERE flags > 0')->fetchColumn();
$fbTotal = (int)$db->query('SELECT COUNT(*) FROM play_reports')->fetchColumn();
$gameName = function (string $g): string {
    [$k, $id] = explode(':', $g, 2);
    if ($k === 'pc') { $a = rs_pc_app($id); return $a ? $a['name'] . ' (PC)' : $id; }
    $x = rs_game($id); return $x ? $x['name'] . ' (PS5)' : $id;
};
$gameUrl = fn(string $g) => str_starts_with($g, 'pc:') ? 'https://recomplauncher.com/game/' . rawurlencode(substr($g, 3)) : 'https://recompstore.com/game/' . rawurlencode(substr($g, 4));
$tick = fn(bool $ok, string $yes = 'Done', string $no = 'To do') => '<span class="pill ' . ($ok ? 'ok' : 'warn') . '">' . ($ok ? $yes : $no) . '</span>';
$copy = fn(string $v) => '<div class="copyrow"><input class="mono" readonly value="' . h($v) . '" onclick="this.select()"><button type="button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=\'Copied\'">Copy</button></div>';

rs_head('Accounts', true, ['assets/accounts.css']);
?>
<section class="head-row">
  <div>
    <h1>Accounts</h1>
    <p class="muted">Player accounts on recompstore.com and recomplauncher.com: sign-in, usernames, saved games, email choices and comments. <?= $live ? 'They\'re <b>on</b> for everyone.' : 'They\'re <b>off</b> for visitors: nothing about accounts shows on the site until you turn them on.' ?></p>
  </div>
  <div class="actions">
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="switch"><input type="hidden" name="on" value="<?= $live ? '0' : '1' ?>">
      <button class="<?= $live ? 'danger' : 'primary' ?>"<?= $live ? ' onclick="return confirm(\'Turn accounts off for everyone? Nobody can sign in or comment until you turn them back on.\')"' : '' ?>><?= $live ? 'Turn accounts off' : 'Turn accounts on' ?></button></form>
  </div>
</section>

<section class="card ac-check" id="check">
  <h2>Before turning them on</h2>
  <ol class="ac-list">
    <li><?= $tick(true) ?><span><b>Try it yourself first.</b> This turns accounts on in this browser only, so you can sign up, comment and save games before anyone else sees it.</span>
      <span class="ac-row"><a class="btn" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Preview accounts in this browser</a><a class="btn" href="<?= h($previewUrl) ?>&amp;off=1" target="_blank" rel="noopener">Stop previewing</a></span></li>
    <li><?= $tick($tsReady) ?><span><b>Bot check (Cloudflare Turnstile).</b> Free. Steps in <a href="#turnstile">1. Bot check</a>.</span></li>
    <li><?= $tick($prov['google']['ready']) ?><span><b>Sign in with Google.</b> Steps in <a href="#google">2. Google</a>.</span></li>
    <li><?= $tick($prov['discord']['ready']) ?><span><b>Sign in with Discord.</b> Steps in <a href="#discord">3. Discord</a>.</span></li>
    <li><?= $tick($prov['github']['ready']) ?><span><b>Sign in with GitHub.</b> Steps in <a href="#github">4. GitHub</a>.</span></li>
    <li><?= $tick($mailReady) ?><span><b>Email.</b> <?= $mailReady ? 'The site\'s mailbox is set up, so confirm-your-email and new-password emails go out.' : 'The site\'s mailbox isn\'t set up yet (Mail page). Without it, only Google, Discord and GitHub sign-in work.' ?></span></li>
    <li><?= $tick($contact !== '') ?><span><b>Contact email</b> for the Terms and Privacy page. Set it in <a href="#rules">5. Rules</a>.</span></li>
    <li><?= $tick($admin2fa, 'On', 'Turn it on') ?><span><b>Two-step sign-in for this admin.</b> <?= $admin2fa ? 'Your admin login asks for a code.' : 'Turn it on in <a href="settings.php">Settings</a> before accounts go live.' ?></span></li>
  </ol>
  <p class="muted small">A sign-in button only shows on the site once its keys are in. You can turn accounts on with just one way to sign in and add the others later.</p>
</section>

<form method="post" class="ac-setup" id="setup"><?= $csrf ?><input type="hidden" name="action" value="keys">

<section class="card" id="turnstile">
  <div class="ac-head"><h2>1. Bot check: Cloudflare Turnstile</h2><?= $tick($tsReady) ?></div>
  <p class="muted">Free, with no picture puzzles. Most people never see it. It runs on sign-up, after a few wrong passwords, and on a new account's first comments.</p>
  <ol class="steps">
    <li>Open <a href="https://dash.cloudflare.com/sign-up" target="_blank" rel="noopener">dash.cloudflare.com/sign-up</a> and make a free account (or sign in). You don't need to move your domain to Cloudflare.</li>
    <li>In the menu on the left, click <b>Turnstile</b>, then <b>Add widget</b>.</li>
    <li>Widget name: <b>RecompStore</b>. Under <b>Hostnames</b>, click <b>Add hostnames</b> and add both:<?= $copy('recompstore.com') ?><?= $copy('recomplauncher.com') ?></li>
    <li>Widget Mode: <b>Managed</b>. Pre-clearance: <b>No</b>. Click <b>Create</b>.</li>
    <li>Copy the <b>Site Key</b> and the <b>Secret Key</b> into the boxes below, then click Save.</li>
  </ol>
  <div class="grid2">
    <label>Site Key<input name="acct_ts_site" class="mono" value="<?= h(rs_acct_turnstile_site()) ?>" placeholder="0x4AAAAAAA…"></label>
    <label>Secret Key<input name="acct_ts_secret" class="mono" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="<?= rs_secret_get('acct_ts_secret') !== '' ? 'Saved. Paste a new one to replace it.' : '0x4AAAAAAA…' ?>"></label>
  </div>
  <div class="actions"><button class="primary" name="spot" value="turnstile">Save</button></div>
</section>

<section class="card" id="google">
  <div class="ac-head"><h2>2. Sign in with Google</h2><?= $tick($prov['google']['ready']) ?></div>
  <p class="muted">Free. RecompStore only asks Google for the person's email address, so Google doesn't need to review the app.</p>
  <ol class="steps">
    <li>Open <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">console.cloud.google.com/projectcreate</a> (sign in with your Google account). Project name: <b>RecompStore</b>. Click <b>Create</b>, and wait until the bell at the top says it's ready.</li>
    <li>Open <a href="https://console.cloud.google.com/auth/overview" target="_blank" rel="noopener">Google Auth Platform</a> (check the project picker at the top says RecompStore) and click <b>Get started</b>.
      <ul><li>App name: <b>RecompStore</b>. User support email: your email. <b>Next</b>.</li><li>Audience: <b>External</b>. <b>Next</b>.</li><li>Contact information: your email. <b>Next</b>. Tick that you agree, then <b>Create</b>.</li></ul></li>
    <li>In the menu on the left, click <b>Branding</b>. Fill these in and click <b>Save</b>:
      <ul><li>Application home page<?= $copy('https://recompstore.com') ?></li><li>Privacy policy link<?= $copy('https://recompstore.com/privacy') ?></li><li>Terms of service link<?= $copy('https://recompstore.com/terms') ?></li><li>Authorized domains: <b>Add domain</b><?= $copy('recompstore.com') ?></li></ul></li>
    <li>Click <b>Clients</b>, then <b>Create client</b>. Application type: <b>Web application</b>. Name: <b>RecompStore</b>. Under <b>Authorized redirect URIs</b>, click <b>Add URI</b> and paste:<?= $copy($redirect) ?>Click <b>Create</b>.</li>
    <li>A box shows the <b>Client ID</b> and <b>Client secret</b>. Copy both into the boxes below and click Save.</li>
    <li>Click <b>Audience</b>, then <b>Publish app</b> and <b>Confirm</b>. (Until then only you can sign in with Google.)</li>
  </ol>
  <div class="grid2">
    <label>Client ID<input name="acct_google_id" class="mono" value="<?= h($prov['google']['client']) ?>" placeholder="…apps.googleusercontent.com"></label>
    <label>Client secret<input name="acct_google_secret" class="mono" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="<?= $prov['google']['secret'] !== '' ? 'Saved. Paste a new one to replace it.' : 'GOCSPX-…' ?>"></label>
  </div>
  <input type="hidden" name="form_google" value="1"><label class="check"><input type="checkbox" name="on_google" value="1"<?= rs_setting('acct_google_on', '1') === '1' ? ' checked' : '' ?>> Show "Continue with Google" once the keys are in</label>
  <div class="actions"><button class="primary" name="spot" value="google">Save</button></div>
</section>

<section class="card" id="discord">
  <div class="ac-head"><h2>3. Sign in with Discord</h2><?= $tick($prov['discord']['ready']) ?></div>
  <p class="muted"><?= $prov['discord']['client'] !== '' ? 'This uses the Discord app you already set up for beta codes. It only needs one more redirect address.' : 'This uses a Discord app (the same one the beta codes use).' ?></p>
  <ol class="steps">
    <li>Open <a href="https://discord.com/developers/applications" target="_blank" rel="noopener">discord.com/developers/applications</a> and click <?= $prov['discord']['client'] !== '' ? 'your RecompStore app' : '<b>New Application</b> (name it RecompStore)' ?>.</li>
    <li>In the menu on the left, click <b>OAuth2</b>. Under <b>Redirects</b>, click <b>Add Redirect</b> and paste:<?= $copy($redirect) ?>Keep the redirect that's already there. Click <b>Save Changes</b> at the bottom.</li>
    <?php if ($prov['discord']['client'] === '' || $prov['discord']['secret'] === ''): ?>
    <li>On the same page, copy the <b>Client ID</b>, click <b>Reset Secret</b> and copy the <b>Client Secret</b> into the boxes below.</li>
    <?php endif; ?>
    <li>Tick the box below to say the redirect is in, and click Save.</li>
  </ol>
  <div class="grid2">
    <label>Client ID<input name="dc_client_id" class="mono" value="<?= h($prov['discord']['client']) ?>"></label>
    <label>Client Secret<input name="dc_client_secret" class="mono" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="<?= $prov['discord']['secret'] !== '' ? 'Saved (the beta codes use it too). Leave empty to keep it.' : '' ?>"></label>
  </div>
  <input type="hidden" name="form_discord" value="1">
  <label class="check"><input type="checkbox" name="on_discord" value="1"<?= rs_setting('acct_discord_on', '1') === '1' && rs_setting('acct_dc_redirect_done', '') !== '' ? ' checked' : '' ?>> I added the redirect: show "Continue with Discord"</label>
  <div class="actions"><button class="primary" name="spot" value="discord">Save</button></div>
</section>

<section class="card" id="github">
  <div class="ac-head"><h2>4. Sign in with GitHub</h2><?= $tick($prov['github']['ready']) ?></div>
  <p class="muted">Its own small GitHub app, separate from the developer hub's, so developer sign-in keeps working exactly as it does.</p>
  <ol class="steps">
    <li>Open <a href="https://github.com/settings/applications/new" target="_blank" rel="noopener">github.com/settings/applications/new</a>.</li>
    <li>Application name: <b>RecompStore</b>. Homepage URL:<?= $copy('https://recompstore.com') ?>Authorization callback URL:<?= $copy($redirect) ?>Leave Enable Device Flow off. Click <b>Register application</b>.</li>
    <li>Copy the <b>Client ID</b>. Click <b>Generate a new client secret</b> and copy it. Paste both below and click Save.</li>
  </ol>
  <div class="grid2">
    <label>Client ID<input name="acct_gh_id" class="mono" value="<?= h($prov['github']['client']) ?>" placeholder="Ov23li…"></label>
    <label>Client secret<input name="acct_gh_secret" class="mono" type="password" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="<?= $prov['github']['secret'] !== '' ? 'Saved. Paste a new one to replace it.' : '' ?>"></label>
  </div>
  <input type="hidden" name="form_github" value="1"><label class="check"><input type="checkbox" name="on_github" value="1"<?= rs_setting('acct_github_on', '1') === '1' ? ' checked' : '' ?>> Show "Continue with GitHub" once the keys are in</label>
  <div class="actions"><button class="primary" name="spot" value="github">Save</button></div>
</section>

<section class="card" id="rules">
  <div class="ac-head"><h2>5. Rules</h2><?= $tick($contact !== '') ?></div>
  <div class="grid2">
    <label>Contact email<input name="acct_contact" type="email" value="<?= h($contact) ?>" placeholder="support@recompstore.com"><small>Shown on the Terms and Privacy page for questions and "send me my data". A mailbox like support@recompstore.com keeps your own address private.</small></label>
    <label>Your own usernames<input name="acct_staff" value="<?= h((string)rs_setting('acct_staff', '')) ?>" placeholder="ripalda"><small>Comments from these accounts get a RecompStore badge. Separate several with commas.</small></label>
  </div>
  <input type="hidden" name="form_rules" value="1">
  <label class="check"><input type="checkbox" name="dev_2fa" value="1"<?= rs_setting('acct_dev_2fa', '1') === '1' ? ' checked' : '' ?>> Developers can't turn two-step sign-in off once it's on</label>
  <div class="actions"><button class="primary" name="spot" value="rules">Save</button></div>
</section>
</form>

<section class="card" id="people">
  <div class="found-head"><h2>People <span class="pill cyan"><?= $total ?></span> <span class="muted small"><?= $week ?> this week</span></h2>
    <form class="find"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Find a username or email"></form></div>
  <p class="muted small">Ticked email choices (confirmed emails only): <?= $lists['weekly'] ?> weekly new games · <?= $lists['launcher'] ?> RecompLauncher updates · <?= $lists['updates'] ?> updates to saved games.</p>
  <?php if (!$people): ?><p class="muted"><?= $q !== '' ? 'Nobody matches.' : 'No accounts yet.' ?></p><?php else: ?>
  <table class="ac-table">
    <thead><tr><th>Username</th><th>Email</th><th>Signs in with</th><th>Joined</th><th>Last seen</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($people as $p): $ways = array_keys(array_filter(['Password' => $p['pass_hash'] !== null, 'Google' => $p['google_sub'] !== null, 'Discord' => $p['discord_id'] !== null, 'GitHub' => $p['github_id'] !== null])); ?>
      <tr<?= $p['banned'] ? ' class="dim"' : '' ?>>
        <td><b><?= h($p['username'] ?? '(no name yet)') ?></b><?= $p['totp_on'] ? ' <span class="pill">2-step</span>' : '' ?><?= $p['banned'] ? ' <span class="pill bad">Closed</span>' : '' ?></td>
        <td><?= h((string)$p['email']) ?><?= $p['email'] !== null && !$p['email_ok'] ? ' <span class="muted small">not confirmed</span>' : '' ?></td>
        <td><?= h(implode(', ', $ways)) ?></td>
        <td class="small"><?= h(rs_date('M j, Y', strtotime((string)$p['created'] . ' UTC'))) ?></td>
        <td class="small"><?= $p['last_seen'] ? h(rs_date('M j, g:i a', strtotime((string)$p['last_seen'] . ' UTC'))) : '' ?></td>
        <td class="ac-acts">
          <?php if ($p['email'] !== null && !$p['email_ok']): ?><form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="action" value="confirm_email"><button>Confirm</button></form><?php endif; ?>
          <form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="<?= $p['banned'] ? 'unban' : 'ban' ?>"><button><?= $p['banned'] ? 'Reopen' : 'Close' ?></button></form>
          <form method="post" onsubmit="return confirm('Delete <?= h(addslashes((string)($p['username'] ?? 'this account'))) ?> and all their comments? This can\'t be undone.')"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="action" value="delete"><button class="danger">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>

<section class="card" id="emails">
  <h2>Emails to players</h2>
  <p class="ac-mailq<?= $mq['paused'] ? ' warn' : '' ?>"><b>Sending:</b> <?= $mq['today'] ?> sent in the last 24 hours <?= function_exists('rs_relay_on') && rs_relay_on() ? '(through the sending service, no daily limit)' : '(info@ allows 1,000 a day)' ?> · <?= $mq['waiting'] ?> waiting in line<?= $mq['paused'] ? ' · the mail server said slow down, trying again in a few minutes' : '' ?><?= $mq['failed'] ? ' · ' . $mq['failed'] . ' gave up after a day' : '' ?>. Account confirmations and password resets go first.</p>
  <p class="muted">Only people who ticked the box and confirmed their email get these. Every one has a one-click unsubscribe link. Write <code>{username}</code> and each person sees their own name.</p>
  <form method="post" class="grid2"><?= $csrf ?><input type="hidden" name="action" value="pm-send">
    <label>Send to<select name="list"><option value="weekly">News and new games (<?= $audience['weekly'] ?>)</option><option value="launcher">RecompLauncher updates (<?= $audience['launcher'] ?>)</option><option value="beta">RecompLauncher beta list (<?= $audience['beta'] ?>)</option></select></label>
    <label>Only the first<input name="first" type="number" min="1" step="1" placeholder="Everyone"><small>Beta list only: the earliest sign-ups get it first.</small></label>
    <label>Subject<input name="subject" maxlength="200" value="<?= h($draft['subject'] ?? '') ?>" placeholder="New on RecompStore this week"></label>
    <label class="span2">Message<textarea name="body" rows="10" maxlength="20000" placeholder="Hi {username}, …"><?= h($draft['body'] ?? '') ?></textarea><small>Plain text. Links work as they are.</small></label>
    <div class="actions span2"><a class="btn" href="accounts.php?draft=1#emails">Fill in this week's new games</a><button class="primary" onclick="return confirm('Send this email now?')">Send</button></div>
  </form>
  <?php if ($mails): ?>
  <table class="ac-table"><thead><tr><th>Sent</th><th>To</th><th>Subject</th><th>Done</th><th></th></tr></thead><tbody>
  <?php foreach ($mails as $m): $cap = (int)rs_setting('pm_cap_' . (int)$m['id'], '0'); $tot = (int)($audience[$m['list']] ?? 0); if ($cap > 0) $tot = min($tot, $cap); $left = max(0, $tot - (int)$m['sent'] - (int)$m['failed']); ?>
    <tr><td class="small"><?= h(rs_date('M j, g:i a', strtotime((string)$m['created'] . ' UTC'))) ?></td><td><?= h(RS_PMAIL_LISTS[$m['list']] ?? $m['list']) ?></td><td><?= h((string)$m['subject']) ?></td>
      <td class="small"><?= (int)$m['sent'] ?> sent<?= (int)$m['failed'] ? ', ' . (int)$m['failed'] . ' failed' : '' ?></td>
      <td class="ac-acts"><?php if ($left): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="pm-next"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button>Send the next 40</button> <button type="button" class="primary" data-autosend="<?= (int)$m['id'] ?>">Keep sending</button> <span class="small muted" data-autolog><?= $left ?> to go<?= $cap > 0 ? ' (first ' . $cap . ')' : '' ?></span></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <script>
  // (3.1g) Sends 40 at a time until everyone has it, while this page stays open. Stops by itself
  // if a batch doesn't go out, so a refusal never eats through the list.
  document.querySelectorAll('[data-autosend]').forEach((b) => b.addEventListener('click', async () => {
    if (b.dataset.run === '1') { b.dataset.run = ''; b.textContent = 'Keep sending'; return; }
    b.dataset.run = '1'; b.textContent = 'Stop';
    const form = b.closest('form'), log = form.querySelector('[data-autolog]');
    const csrf = form.querySelector('[name=csrf]').value;
    let total = 0;
    while (b.dataset.run === '1') {
      const fd = new FormData(); fd.append('action', 'pm-next-json'); fd.append('id', b.dataset.autosend); fd.append('csrf', csrf);
      let j;
      try { j = await (await fetch('accounts.php', { method: 'POST', body: fd })).json(); }
      catch (e) { log.textContent = 'Lost the connection after ' + total + '. Press Keep sending to carry on.'; break; }
      if (j.error) { log.textContent = j.error; break; }
      total += j.sent;
      log.textContent = total + ' sent just now, ' + j.left + ' to go';
      if (!j.left) { log.textContent = total + ' sent just now. That\'s everyone.'; b.remove(); return; }
      if (j.sent < 20) { log.textContent += '. Stopped: most of that batch didn\'t go out. Check the sending service, then press Keep sending.'; break; }
    }
    b.dataset.run = ''; b.textContent = 'Keep sending';
  }));
  </script>
  <div class="ac-head" style="margin-top:18px"><h3>Updates to saved games</h3><span class="pill<?= $updatesDue ? ' cyan' : '' ?>"><?= $updatesDue ?> waiting</span></div>
  <p class="muted">People who asked to hear when a game they saved gets a new version. After you Publish an update, press this to email everyone whose saved game changed.</p>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="pm-updates"><button<?= $updatesDue ? ' class="primary"' : '' ?>>Email saved-game updates</button></form>
</section>

<section class="card" id="beta">
  <div class="ac-head"><h2>RecompLauncher beta list <span class="pill cyan"><?= $audience['beta'] ?></span> <span class="muted small">people on the list</span></h2>
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="beta-switch"><input type="hidden" name="on" value="<?= $betaOn ? '0' : '1' ?>"><button class="<?= $betaOn ? '' : 'primary' ?>"><?= $betaOn ? 'Turn the pop-up off' : 'Turn the pop-up on' ?></button></form></div>
  <p class="muted">People who want the Windows beta. recomplauncher.com shows them a pop-up once, and the Get RecompLauncher page has the sign-up. Nobody gets an email when they sign up (that ran the mailbox out on the first night). When the beta's ready, email them from <a href="#emails">Emails to players</a> (pick "RecompLauncher beta list").</p>
  <?php if (!$betaRows): ?><p class="muted">Nobody yet.</p><?php else: ?>
  <table class="ac-table"><thead><tr><th>Email</th><th>Account</th><th>Signed up</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($betaRows as $b): ?>
    <tr<?= $b['off'] ? ' class="dim"' : '' ?>><td><?= h((string)$b['email']) ?></td><td><?= h((string)($b['username'] ?? '')) ?></td><td class="small"><?= h(rs_date('M j, g:i a', strtotime((string)$b['created'] . ' UTC'))) ?></td>
      <td><span class="pill <?= $b['off'] ? '' : ($b['confirmed'] ? 'ok' : 'warn') ?>"><?= $b['off'] ? 'Left' : ($b['confirmed'] ? 'Confirmed' : 'Waiting') ?></span></td>
      <td class="ac-acts"><form method="post"><?= $csrf ?><input type="hidden" name="action" value="beta-remove"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button>Remove</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<section class="card" id="ps5-reports">
  <h2>Player reports <span class="pill<?= $fbFlagged ? ' warn' : '' ?>"><?= $fbFlagged ?> flagged</span> <span class="muted small"><?= $fbTotal ?> in all</span></h2>
  <p class="muted">Every player report, PC and PS5 (RecompLauncher's included), flagged ones first. Three flags hide a report until you look.</p>
  <?php if (!$ps5fb): ?><p class="muted">None yet.</p><?php else: ?>
  <table class="ac-table"><thead><tr><th>Game</th><th>Says</th><th>On</th><th>Problems and notes</th><th>By</th><th>When</th><th></th></tr></thead><tbody>
  <?php foreach ($ps5fb as $f): $probs = rs_play_problems_decode((string)$f['problems']); ?>
    <tr<?= $f['hidden'] ? ' class="dim"' : '' ?>><td><?= h($gameName($f['kind'] . ':' . $f['app'])) ?><?= (int)$f['flags'] ? ' <span class="pill bad">' . (int)$f['flags'] . ' flagged</span>' : '' ?></td>
      <td><span class="pill <?= $f['verdict'] === 'runs' ? 'ok' : ($f['verdict'] === 'issues' ? 'warn' : 'bad') ?>"><?= h(RS_PLAY_VERDICTS[$f['verdict']] ?? '') ?></span></td>
      <td class="small"><?= h($f['kind'] === 'pc' ? (RS_PLAY_PC_SYS[$f['sys']] ?? $f['sys']) : 'PS5 ' . (RS_PS5_FW[$f['sys']] ?? $f['sys'])) ?><?= $f['version'] !== '' ? ' · v' . h((string)$f['version']) : '' ?></td>
      <td class="small"><?= h(implode(', ', array_map(fn($k) => RS_PLAY_PROBLEMS[$k] . ' (' . RS_PLAY_SEVERITY[$probs[$k]] . ')', array_keys($probs)))) ?><?= $f['fix'] !== '' ? '<br><b>Helped:</b> ' . h((string)$f['fix']) : '' ?><?= $f['note'] !== '' ? '<br>' . h((string)$f['note']) : '' ?></td>
      <td class="small"><?= $f['username'] ? h((string)$f['username']) : ($f['source'] === 'pcfb' ? 'RecompLauncher' : 'Guest') ?></td>
      <td class="small"><?= h(rs_date('M j', strtotime((string)$f['updated'] . ' UTC'))) ?></td>
      <td><form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="<?= $f['hidden'] ? 'fb-show' : 'fb-hide' ?>"><button><?= $f['hidden'] ? 'Show' : 'Hide' ?></button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<section class="card" id="moderation">
  <h2>Moderation <span class="pill<?= $queue ? ' warn' : '' ?>"><?= count($queue) ?></span> <span class="muted small"><?= $cmCount ?> comments in all</span></h2>
  <p class="muted">Reported comments. Three reports hide a comment until you look.</p>
  <?php if (!$queue): ?><p class="muted">Nothing to look at.</p><?php else: ?>
  <div class="ac-queue">
  <?php foreach ($queue as $c): ?>
    <article class="ac-cm<?= $c['hidden'] ? ' held' : '' ?>">
      <div class="ac-cm-meta"><b><?= h($c['username'] ?? 'deleted user') ?></b> on <a href="<?= h($gameUrl((string)$c['game'])) ?>" target="_blank" rel="noopener"><?= h($gameName((string)$c['game'])) ?></a> · <?= h(rs_date('M j, g:i a', strtotime((string)$c['created'] . ' UTC'))) ?>
        <?= $c['hidden'] ? '<span class="pill bad">Hidden</span>' : '' ?> <span class="pill warn"><?= (int)$c['reports'] ?> <?= (int)$c['reports'] === 1 ? 'report' : 'reports' ?><?= isset($reasons[(int)$c['id']]) ? ': ' . h(implode(', ', $reasons[(int)$c['id']])) : '' ?></span></div>
      <p class="ac-cm-body"><?= nl2br(h((string)$c['body'])) ?></p>
      <div class="ac-acts">
        <form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="cm-show"><button>Keep it up</button></form>
        <form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="cm-remove"><button class="danger">Remove</button></form>
        <form method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$c['account_id'] ?>"><input type="hidden" name="action" value="ban"><button class="danger">Close their account</button></form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php rs_foot();
