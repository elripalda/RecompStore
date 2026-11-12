<?php
// RecompStore website (3.0): where Google, Discord and GitHub send people back after signing in.
// This one address (https://recompstore.com/api/oauth.php) is what goes in each provider's settings.
// (4.8) A sign-in started on recomplauncher.com comes back there signed in (a hand-off link).
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$go = function (string $url): void { header('Location: ' . $url, true, 302); exit; };
// (4.8) A sign-in ends on the site it started on (recomplauncher.com or here), problems included.
$home = 'https://recompstore.com';
$fail = function (string $why) use (&$home, $go) { $go($home . '/signin?note=' . rawurlencode($why)); };

try {
    rs_acct_tables();
    if (!rs_acct_on()) $go('https://recompstore.com/');
    $st = rs_acct_state_read((string)($_GET['state'] ?? ''));
    rs_acct_cookie('rs_oauth', '', 0, '/api/');
    if (!$st) $fail('That sign-in took too long or came from somewhere else. Try again.');
    $back = (string)$st['b'];
    $home = rs_acct_home($back);
    if (!empty($_GET['error'])) $go($home . '/signin');
    $p = (string)$st['p'];
    if (!in_array($p, rs_acct_ready_providers(), true)) $fail('That way to sign in is turned off.');
    $code = (string)($_GET['code'] ?? '');
    if ($code === '' || strlen($code) > 512) $fail('The sign-in didn\'t come back whole. Try again.');
    [$pid, $email, $emailOk] = rs_acct_oauth_finish($p, $code);
    $me = $st['m'] === 'link' ? rs_acct_current() : null;
    $acc = rs_acct_oauth_account($p, $pid, $email, $emailOk, $me);
    if ($acc['banned']) $fail('This account was closed for breaking the house rules.');
    if ($me) $go($home . '/account?note=linked');
    $r = rs_acct_sign_in($acc);
    // The second step (an authenticator code) is waiting here, so it's asked here.
    if (!empty($r['twoStep'])) $go('https://recompstore.com/signin?step=code&back=' . rawurlencode($back));
    // A new account picks its username first, on the site it started on (signed in there first).
    if ($acc['username'] === null) $go(rs_acct_after((int)$acc['id'], $home . '/signin?step=name&back=' . rawurlencode($back)));
    $go(rs_acct_after((int)$acc['id'], $back));
} catch (Throwable $e) {
    error_log('oauth.php: ' . $e->getMessage());
    $fail($e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong on our side. Try again.');
}
