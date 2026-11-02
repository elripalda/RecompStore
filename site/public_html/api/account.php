<?php
// RecompStore website (3.0): player accounts.
//   GET  a=me                          -> {ok, on, account|null, providers[], turnstile, contact}
//   GET  a=name&u=                     -> {ok, free, error, suggest}   (as they type a username)
//   POST a=signup username email password turnstile agree weekly launcher updates
//   GET  a=confirm&t=                  -> email confirmed, then the account page
//   POST a=signin login password turnstile -> {ok} or {ok, twoStep}
//   POST a=twostep code                -> {ok}
//   POST a=forgot email turnstile      -> a link to set a new password (always says it was sent)
//   POST a=reset t password            -> new password, signed in
//   GET  a=oauth&p=google|discord|github[&back=][&link=1] -> off to sign in there (back via oauth.php)
//   GET  a=handoff&t=&back=            -> signed in on this site too (from the other one)
//   POST a=handoff-url back            -> {url} that signs in on the other site and goes on to back
//   POST a=username username            -> picks or changes the username
//   POST a=email email                  -> changes the email (confirm again)
//   POST a=password current new         -> sets or changes the password
//   POST a=prefs news updates            (news: RecompStore and RecompLauncher updates)
//   POST a=resend email                -> the confirm link again
//   POST a=avatar (form: file)          -> a profile picture (128x128)   a=avatar-off
//   POST a=unlink p                     -> takes Google / Discord / GitHub off (one way to sign in stays)
//   POST a=2fa-begin                    -> {secret, uri}   a=2fa-on code -> {codes[]}   a=2fa-off code
//   GET  a=sessions                     -> {sessions[]}    POST a=session-end id | a=signout [all]
//   GET  a=saved                        -> {saved[]}       POST a=save game on
//   GET  a=export                       -> everything kept about you, as a file
//   POST a=delete confirm               -> the account and what's in it, gone
//   GET  a=preview&k=                   -> (admin) accounts on in this browser before everyone
//   GET  a=unsub&u=&l=&s=               -> off one email list (the link in every email)
// POSTs are JSON from this site's own pages only (Origin and an X-RS header are checked).
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/totp.php';
// (3.1) Every page view asks who's signed in: a good moment to send a few waiting emails, after the answer.
if (function_exists('rs_mailq_after')) rs_mailq_after();
if (function_exists('rs_vt_after')) rs_vt_after(); // (3.8)

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

function rs_ac_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function rs_ac_err(int $code, string $why, array $more = []): void { rs_ac_out($code, ['ok' => false, 'error' => $why] + $more); }
function rs_ac_go(string $url): void { header('Location: ' . $url, true, 302); exit; }

$a = (string)($_GET['a'] ?? $_POST['a'] ?? '');
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = [];
if ($post) {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $oh = preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST)));
    if ($origin === '' || $oh !== rs_acct_host() || ($_SERVER['HTTP_X_RS'] ?? '') !== '1') rs_ac_err(403, 'Do that from the website.');
    // (3.0) A profile picture comes as a form with the file, not JSON.
    if (($a === 'avatar') && !empty($_FILES['file'])) $in = ['a' => 'avatar'];
    else {
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 16384), true);
        if (!is_array($in)) rs_ac_err(400, 'The form didn\'t arrive whole. Try again.');
        $a = (string)($in['a'] ?? $a);
    }
}
// (3.1g) Signed-out visitors all get the same answer to a=me: a 30-second copy of it.
if ($a === 'me' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && !preg_match('/^[0-9a-f]{64}$/', (string)($_COOKIE['rs_acct'] ?? ''))) { require_once dirname(__DIR__) . '/admin/inc/jcache.php'; rs_json_cache('acct-me-' . (str_contains((string)($_SERVER['HTTP_HOST'] ?? ''), 'recomplauncher') ? 'rl' : 'rs'), 30); }
$s = fn(string $k, int $max = 200) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max);

try {
    rs_acct_tables();
    // The admin's preview: a link from the Accounts page turns accounts on in that browser.
    if ($a === 'preview') {
        $k = (string)($_GET['k'] ?? '');
        if ($k === '' || !hash_equals((string)rs_setting('acct_preview_key', ''), $k)) rs_ac_go('/');
        if (!empty($_GET['off'])) rs_acct_cookie('rs_acct_pv', '', 0);
        else rs_acct_cookie('rs_acct_pv', rs_acct_preview_value(), 30);
        rs_ac_go(empty($_GET['off']) ? '/account' : '/');
    }

    // One click from an email: off that list (works even with accounts switched off).
    if ($a === 'unsub') {
        $uid = (string)($_GET['u'] ?? ''); $l = (string)($_GET['l'] ?? '');
        $acc = preg_match('/^[0-9a-f]{16}$/', $uid) ? rs_acct_by('uid', $uid) : null;
        $ok = $acc && isset(RS_PMAIL_LISTS[$l]) && hash_equals(rs_pmail_sig($uid, $l), (string)($_GET['s'] ?? ''));
        if ($ok) { $p = rs_acct_prefs($acc); $p[$l] = false; rs_acct_update((int)$acc['id'], ['prefs' => json_encode($p)]); }
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RecompStore</title>'
            . '<body style="background:#0B1220;color:#F4F8FC;font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;padding:16px">'
            . '<div style="max-width:440px;text-align:center"><h1 style="font-size:24px">' . ($ok ? 'You\'re unsubscribed' : 'That link didn\'t work') . '</h1><p>'
            . ($ok ? 'No more ' . h(strtolower(RS_PMAIL_LISTS[$l])) . ' emails. You can change your choices any time on your account page.' : 'Change your email choices on your account page instead.')
            . '</p><p><a style="color:#34F2E8" href="https://recompstore.com/account">Your account</a></p></div>';
        exit;
    }

    $me = rs_acct_current();
    if ($a === 'me') {
        $on = rs_acct_on();
        rs_ac_out(200, ['ok' => true, 'on' => $on, 'account' => $on && $me ? rs_acct_public($me) : null,
            'providers' => $on ? rs_acct_ready_providers() : [], 'turnstile' => $on ? rs_acct_turnstile_site() : '',
            'contact' => (string)rs_setting('acct_contact', 'support@recompstore.com'), 'mail' => rs_mail_ready()]);
    }
    if (!rs_acct_on()) rs_ac_err(404, 'Accounts aren\'t open yet.');
    $ip = rs_ip_hash();

    // ---- Coming back from an email link, or from the other site ----
    if ($a === 'confirm') {
        $r = rs_acct_token_use((string)($_GET['t'] ?? ''), 'confirm');
        if (!$r) rs_ac_go('https://recompstore.com/account?note=link-old');
        $acc = rs_acct_get($r[0]);
        if ($acc && (string)$acc['email_lc'] === $r[1]) {
            rs_acct_update((int)$acc['id'], ['email_ok' => 1]);
            if (!$me) rs_acct_sign_in($acc);
        }
        rs_ac_go('https://recompstore.com/account?note=confirmed');
    }
    if ($a === 'handoff') {
        $r = rs_acct_token_use((string)($_GET['t'] ?? ''), 'handoff');
        $back = rs_acct_back((string)($_GET['back'] ?? '/'));
        if ($r && ($acc = rs_acct_get($r[0])) && !$acc['banned']) rs_acct_session_start((int)$acc['id']);
        rs_ac_go($back);
    }
    if ($a === 'oauth') {
        $p = (string)($_GET['p'] ?? '');
        if (!in_array($p, rs_acct_ready_providers(), true)) rs_ac_go('https://recompstore.com/signin?note=provider-off');
        // Every sign-in with a provider happens on recompstore.com (the address they send people back to).
        if (rs_acct_host() !== 'recompstore.com') rs_ac_go('https://recompstore.com/api/account.php?' . http_build_query($_GET));
        $link = !empty($_GET['link']) && $me;
        rs_ac_go(rs_acct_oauth_url($p, rs_acct_state_make($p, $link ? 'link' : 'in', (string)($_GET['back'] ?? '/account'))));
    }

    if (!$post) {
        if ($a === 'name') {
            $u = (string)($_GET['u'] ?? '');
            $err = rs_acct_name_error($u, $me ? (int)$me['id'] : 0);
            rs_ac_out(200, ['ok' => true, 'free' => $err === null, 'error' => $err, 'suggest' => $err === 'That username is taken.' ? rs_acct_name_suggest($u) : '']);
        }
        if (!$me) rs_ac_err(401, 'Sign in first.');
        if ($a === 'sessions') rs_ac_out(200, ['ok' => true, 'sessions' => rs_acct_sessions($me)]);
        if ($a === 'saved') rs_ac_out(200, ['ok' => true, 'saved' => rs_acct_saved($me)]);
        if ($a === 'export') {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="recompstore-account.json"');
            echo json_encode(rs_acct_export($me), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        rs_ac_err(400, 'Unknown request.');
    }

    // ---- Signing up and in ----
    if ($a === 'signup') {
        if (!rs_mail_ready()) rs_ac_err(503, 'Email sign-up isn\'t open yet. Use Google, Discord or GitHub.');
        if (rs_acct_try_count('signup', $ip, 60) >= 5) rs_ac_err(429, 'Too many new accounts from here. Try again in an hour.');
        if (!rs_acct_turnstile_ok($s('turnstile', 2048))) rs_ac_err(422, 'The check that you\'re a person didn\'t pass. Try again.', ['field' => 'turnstile']);
        if (empty($in['agree'])) rs_ac_err(422, 'Tick the box to agree to the Terms and Privacy.', ['field' => 'agree']);
        $user = $s('username', 40);
        if ($e = rs_acct_name_error($user)) rs_ac_err(422, $e, ['field' => 'username', 'suggest' => $e === 'That username is taken.' ? rs_acct_name_suggest($user) : '']);
        $email = strtolower($s('email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) rs_ac_err(422, 'That doesn\'t look like an email address.', ['field' => 'email']);
        $had = rs_acct_by('email_lc', $email);
        if ($had && !$had['email_ok'] && $had['pass_hash'] !== null) {
            // Signed up before and never confirmed: the link again.
            rs_acct_try_add('signup', $ip);
            try { rs_acct_mail_confirm($had); } catch (Throwable $e) { error_log('account.php confirm mail: ' . $e->getMessage()); }
            rs_ac_out(200, ['ok' => true, 'confirm' => true, 'email' => $email]);
        }
        if ($had) rs_ac_err(422, 'There\'s already an account with that email. Sign in, or set a new password.', ['field' => 'email']);
        $pass = (string)($in['password'] ?? '');
        if ($e = rs_acct_pass_error($pass)) rs_ac_err(422, $e, ['field' => 'password']);
        rs_acct_try_add('signup', $ip);
        $acc = rs_acct_create(['username' => $user, 'username_lc' => strtolower($user), 'email' => $email, 'email_lc' => $email, 'pass_hash' => rs_acct_hash($pass),
            'prefs' => json_encode(['weekly' => !empty($in['news']) || !empty($in['weekly']), 'launcher' => !empty($in['news']) || !empty($in['launcher']), 'updates' => !empty($in['updates'])])]);
        // (3.0) The account works once its email is confirmed: nothing is signed in until then.
        // (3.1) The email waits in line if it can't go this second: the account stays either way.
        try { rs_acct_mail_confirm($acc); } catch (Throwable $e) { error_log('account.php confirm mail: ' . $e->getMessage()); }
        rs_ac_out(200, ['ok' => true, 'confirm' => true, 'email' => $email]);
    }
    if ($a === 'resend') {
        $email = strtolower($s('email', 190));
        if (rs_acct_try_count('resend', $ip, 60) >= 4) rs_ac_err(429, 'That\'s a few already. Check your spam folder, or try again in an hour.');
        rs_acct_try_add('resend', $ip);
        $acc = filter_var($email, FILTER_VALIDATE_EMAIL) ? rs_acct_by('email_lc', $email) : ($me ?: null);
        if ($acc && !$acc['email_ok'] && !$acc['banned']) { try { rs_acct_mail_confirm($acc); } catch (Throwable $e) { error_log('account.php confirm mail: ' . $e->getMessage()); } }
        rs_ac_out(200, ['ok' => true, 'message' => 'If that account is waiting, a new link is on its way. Check your spam folder too.']);
    }
    if ($a === 'signin') {
        $login = strtolower($s('login', 190));
        $fails = rs_acct_try_count('signin', $ip, 15) + rs_acct_try_count('signin-a', $login, 15);
        if ($fails >= 5 && !rs_acct_turnstile_ok($s('turnstile', 2048))) rs_ac_err(422, 'Too many tries. Do the check below, then try again.', ['needCheck' => true]);
        if ($fails >= 20) rs_ac_err(429, 'Too many tries. Wait 15 minutes, or set a new password.');
        $acc = str_contains($login, '@') ? rs_acct_by('email_lc', $login) : rs_acct_by('username_lc', $login);
        $pass = (string)($in['password'] ?? '');
        if (!$acc || $acc['pass_hash'] === null || !password_verify($pass, (string)$acc['pass_hash'])) {
            rs_acct_try_add('signin', $ip); rs_acct_try_add('signin-a', $login);
            usleep(random_int(150000, 400000));
            rs_ac_err(422, $acc && $acc['pass_hash'] === null ? 'That account signs in with Google, Discord or GitHub.' : 'That username or password isn\'t right.', ['needCheck' => $fails + 1 >= 5 && rs_acct_turnstile_ready()]);
        }
        if ($acc['banned']) rs_ac_err(403, 'This account was closed for breaking the house rules.');
        if (!$acc['email_ok'] && $acc['google_sub'] === null && $acc['discord_id'] === null && $acc['github_id'] === null)
            rs_ac_err(403, 'Confirm your email first: the link is in the email we sent you.', ['needConfirm' => true, 'email' => (string)$acc['email']]);
        if (password_needs_rehash((string)$acc['pass_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) rs_acct_update((int)$acc['id'], ['pass_hash' => rs_acct_hash($pass)]);
        rs_ac_out(200, ['ok' => true] + rs_acct_sign_in($acc));
    }
    if ($a === 'twostep') {
        $t = (string)($_COOKIE['rs_acct_2fa'] ?? '');
        if (rs_acct_try_count('2fa', $ip, 15) >= 8) rs_ac_err(429, 'Too many wrong codes. Wait 15 minutes.');
        $r = rs_acct_token_use($t, '2fa', true);
        if (!$r || !($acc = rs_acct_get($r[0]))) rs_ac_err(422, 'That took too long. Sign in again.', ['restart' => true]);
        if (!rs_acct_2fa_check($acc, $s('code', 20))) { rs_acct_try_add('2fa', $ip); rs_ac_err(422, 'That code isn\'t right. Use the newest one from your app.'); }
        rs_acct_token_use($t, '2fa');
        rs_acct_cookie('rs_acct_2fa', '', 0, '/api/');
        rs_acct_session_start((int)$acc['id']);
        rs_ac_out(200, ['ok' => true]);
    }
    if ($a === 'forgot') {
        $email = strtolower($s('email', 190));
        if (rs_acct_try_count('forgot', $ip, 60) >= 5) rs_ac_err(429, 'Too many requests. Try again in an hour.');
        if (!rs_acct_turnstile_ok($s('turnstile', 2048))) rs_ac_err(422, 'The check that you\'re a person didn\'t pass. Try again.');
        rs_acct_try_add('forgot', $ip);
        $acc = filter_var($email, FILTER_VALIDATE_EMAIL) ? rs_acct_by('email_lc', $email) : null;
        if ($acc && !$acc['banned']) rs_acct_mail_reset($acc);
        rs_ac_out(200, ['ok' => true, 'message' => 'If there\'s an account with that email, a link to set a new password is on its way.']);
    }
    if ($a === 'reset') {
        $pass = (string)($in['password'] ?? '');
        if ($e = rs_acct_pass_error($pass)) rs_ac_err(422, $e, ['field' => 'password']);
        $r = rs_acct_token_use($s('t', 60), 'reset');
        if (!$r || !($acc = rs_acct_get($r[0]))) rs_ac_err(422, 'That link has expired. Ask for a new one.');
        rs_acct_update((int)$acc['id'], ['pass_hash' => rs_acct_hash($pass), 'email_ok' => 1]);
        rs_db()->prepare('DELETE FROM account_sessions WHERE account_id = ?')->execute([(int)$acc['id']]);
        rs_ac_out(200, ['ok' => true] + rs_acct_sign_in(rs_acct_get((int)$acc['id'])));
    }

    // ---- Everything below is for the signed-in account ----
    if (!$me) rs_ac_err(401, 'Sign in first.');
    $id = (int)$me['id'];
    $mustProve = function (string $field = 'confirm') use ($me, $in): void {
        // Changing how you sign in asks for the password (or a two-step code, or the username without one).
        $v = (string)($in[$field] ?? '');
        if ($me['pass_hash'] !== null && password_verify($v, (string)$me['pass_hash'])) return;
        if ($me['totp_on'] && rs_acct_2fa_check($me, $v)) return;
        if ($me['pass_hash'] === null && !$me['totp_on'] && $me['username'] !== null && strcasecmp($v, (string)$me['username']) === 0) return;
        rs_ac_err(422, $me['pass_hash'] !== null ? 'Type your password to do that.' : ($me['totp_on'] ? 'Type a code from your authenticator app to do that.' : 'Type your username to do that.'), ['field' => $field]);
    };

    // Going on to the other site signed in (the sign-in page on recompstore.com, back to recomplauncher.com).
    if ($a === 'handoff-url') rs_ac_out(200, ['ok' => true, 'url' => rs_acct_after($id, $s('back', 500))]);
    if ($a === 'signout') { rs_acct_session_end(!empty($in['all'])); rs_ac_out(200, ['ok' => true]); }
    if ($a === 'session-end') {
        $sid = preg_replace('/[^0-9a-f]/', '', $s('id', 12));
        if (strlen($sid) === 12) rs_db()->prepare('DELETE FROM account_sessions WHERE account_id = ? AND token_hash LIKE ?')->execute([$id, $sid . '%']);
        rs_ac_out(200, ['ok' => true, 'sessions' => rs_acct_sessions($me)]);
    }
    if ($a === 'username') {
        $user = $s('username', 40);
        if ($me['username'] !== null && !rs_acct_public($me)['canRename']) rs_ac_err(422, 'You can change your username once every 30 days.');
        if ($e = rs_acct_name_error($user, $id)) rs_ac_err(422, $e, ['suggest' => $e === 'That username is taken.' ? rs_acct_name_suggest($user) : '']);
        if ($me['username_lc'] !== null && $me['username_lc'] !== strtolower($user))
            rs_db()->prepare('INSERT INTO account_names_held (name_lc, until) VALUES (?, (UTC_TIMESTAMP() + INTERVAL 30 DAY)) ON DUPLICATE KEY UPDATE until = VALUES(until)')->execute([$me['username_lc']]);
        $first = $me['username'] === null;
        if (isset($in['agree']) && empty($in['agree'])) rs_ac_err(422, 'Tick the box to agree to the Terms and Privacy.');
        $f = ['username' => $user, 'username_lc' => strtolower($user)];
        if (!$first) $f['name_changed'] = gmdate('Y-m-d H:i:s');
        if ($first) $f['prefs'] = json_encode(['weekly' => !empty($in['news']), 'launcher' => !empty($in['news']), 'updates' => false]);
        rs_acct_update($id, $f);
        rs_ac_out(200, ['ok' => true, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === 'prefs') {
        $news = array_key_exists('news', $in) ? !empty($in['news']) : null;
        rs_acct_update($id, ['prefs' => json_encode(['weekly' => $news ?? !empty($in['weekly']), 'launcher' => $news ?? !empty($in['launcher']), 'updates' => !empty($in['updates'])])]);
        rs_ac_out(200, ['ok' => true, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === 'email') {
        $email = strtolower($s('email', 190));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) rs_ac_err(422, 'That doesn\'t look like an email address.');
        if ($me['pass_hash'] !== null || $me['totp_on']) $mustProve();
        $other = rs_acct_by('email_lc', $email);
        if ($other && (int)$other['id'] !== $id) rs_ac_err(422, 'Another account uses that email.');
        if (rs_acct_try_count('email', (string)$id, 60) >= 4) rs_ac_err(429, 'Try again in an hour.');
        rs_acct_try_add('email', (string)$id);
        rs_acct_update($id, ['email' => $email, 'email_lc' => $email, 'email_ok' => 0]);
        rs_acct_mail_confirm(rs_acct_get($id));
        rs_ac_out(200, ['ok' => true, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === 'password') {
        $new = (string)($in['new'] ?? '');
        if ($me['pass_hash'] !== null && !password_verify((string)($in['current'] ?? ''), (string)$me['pass_hash'])) rs_ac_err(422, 'Your current password isn\'t right.', ['field' => 'current']);
        if ($e = rs_acct_pass_error($new)) rs_ac_err(422, $e, ['field' => 'new']);
        rs_acct_update($id, ['pass_hash' => rs_acct_hash($new)]);
        $raw = hash('sha256', (string)($_COOKIE[RS_ACCT_COOKIE] ?? ''));
        rs_db()->prepare('DELETE FROM account_sessions WHERE account_id = ? AND token_hash <> ?')->execute([$id, $raw]);
        rs_ac_out(200, ['ok' => true, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === 'avatar') {
        if (rs_acct_try_count('avatar', (string)$id, 60) >= 10) rs_ac_err(429, 'Try again in a bit.');
        rs_acct_try_add('avatar', (string)$id);
        try { rs_acct_avatar_save($_FILES['file'] ?? [], $me); } catch (RuntimeException $e) { rs_ac_err(422, $e->getMessage()); }
        rs_ac_out(200, ['ok' => true, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === 'avatar-off') {
        rs_acct_avatar_drop($me);
        rs_ac_out(200, ['ok' => true, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === 'unlink') {
        $p = $s('p', 10);
        $col = ['google' => 'google_sub', 'discord' => 'discord_id', 'github' => 'github_id'][$p] ?? '';
        if ($col === '') rs_ac_err(400, 'Unknown sign-in.');
        $ways = ($me['pass_hash'] !== null ? 1 : 0) + ($me['google_sub'] !== null ? 1 : 0) + ($me['discord_id'] !== null ? 1 : 0) + ($me['github_id'] !== null ? 1 : 0);
        if ($ways <= 1) rs_ac_err(422, 'Add another way to sign in first (a password, or another account), so you can still get in.');
        rs_acct_update($id, [$col => null]);
        rs_ac_out(200, ['ok' => true, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === '2fa-begin') {
        if ($me['totp_on']) rs_ac_err(422, 'Two-step sign-in is already on.');
        $secret = rs_new_totp_secret();
        rs_acct_update($id, ['totp_secret' => $secret]);
        rs_ac_out(200, ['ok' => true, 'secret' => $secret, 'uri' => 'otpauth://totp/' . rawurlencode('RecompStore:' . (string)($me['username'] ?? 'account')) . '?secret=' . $secret . '&issuer=RecompStore&digits=6&period=30']);
    }
    if ($a === '2fa-on') {
        if ($me['totp_on'] || (string)$me['totp_secret'] === '') rs_ac_err(422, 'Start again from the account page.');
        $c = rs_totp_check((string)$me['totp_secret'], $s('code', 12), 0);
        if (!$c) rs_ac_err(422, 'That code isn\'t right. Use the newest one from your app.');
        $codes = rs_acct_backup_codes();
        rs_acct_update($id, ['totp_on' => 1, 'totp_last' => $c, 'backup_codes' => json_encode(array_map(fn($x) => password_hash(str_replace('-', '', $x), PASSWORD_DEFAULT), $codes))]);
        rs_ac_out(200, ['ok' => true, 'codes' => $codes, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === '2fa-off') {
        if (!$me['totp_on']) rs_ac_out(200, ['ok' => true]);
        if (!rs_acct_2fa_check($me, $s('code', 20))) rs_ac_err(422, 'That code isn\'t right.');
        if (rs_acct_dev($me) && rs_setting('acct_dev_2fa', '1') === '1') rs_ac_err(422, 'Developer accounts keep two-step sign-in on.');
        rs_acct_update($id, ['totp_on' => 0, 'totp_secret' => null, 'backup_codes' => null]);
        rs_ac_out(200, ['ok' => true, 'account' => rs_acct_public(rs_acct_get($id))]);
    }
    if ($a === 'save') {
        $g = rs_acct_game_key($s('game', 80));
        if (!$g) rs_ac_err(400, 'Unknown game.');
        if (!empty($in['on'])) {
            $n = rs_db()->prepare('SELECT COUNT(*) FROM account_saved WHERE account_id = ?');
            $n->execute([$id]);
            if ((int)$n->fetchColumn() >= 500) rs_ac_err(422, 'Your library is full (500 games).');
            rs_pmail_tables();
            rs_db()->prepare('INSERT IGNORE INTO account_saved (account_id, game, created, seen_version) VALUES (?, ?, UTC_TIMESTAMP(), ?)')->execute([$id, $g, rs_pmail_version_of($g)]);
        } else rs_db()->prepare('DELETE FROM account_saved WHERE account_id = ? AND game = ?')->execute([$id, $g]);
        rs_ac_out(200, ['ok' => true, 'saved' => !empty($in['on'])]);
    }
    if ($a === 'delete') {
        $mustProve();
        rs_acct_delete($me);
        rs_log('account-delete', (string)$me['uid'], '');
        rs_ac_out(200, ['ok' => true]);
    }
    rs_ac_err(400, 'Unknown request.');
} catch (Throwable $e) {
    error_log('account.php: ' . $e->getMessage());
    rs_ac_err(500, 'Something went wrong on our side. Try again in a moment.');
}
