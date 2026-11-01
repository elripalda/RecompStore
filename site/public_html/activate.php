<?php
// RecompLauncher website (3.1): recomplauncher.com/activate. RecompLauncher shows a code (or opens
// this page with it); the signed-in player says yes, and that PC is signed in to their account.
// Not signed in: off to sign in (or make an account) and straight back here.
declare(strict_types=1);
require __DIR__ . '/admin/inc/bootstrap.php';
require_once __DIR__ . '/admin/inc/rl_cloud.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; script-src 'none'; form-action 'self'; frame-ancestors 'none'");

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$code = rs_rl_norm_code((string)($_POST['code'] ?? $_GET['code'] ?? ''));
$here = 'https://recomplauncher.com/activate' . ($code !== '' ? '?code=' . $code : '');
$mark = '<svg viewBox="0 0 256 256" aria-hidden="true"><defs><linearGradient id="m" x1="0" y1="38" x2="0" y2="218" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#34f2e8"/><stop offset="1" stop-color="#377bb6"/></linearGradient></defs><g fill="url(#m)" transform="translate(4 -2)"><path d="M40 40H88V168H116L64 220L12 168H40Z"/><path d="M104 40H172L216 84V116L180 152H104V116H164L180 100V92L164 76H104Z"/><path d="M132 168H184L236 220H184Z"/></g></svg>';

function rs_rl_page(string $title, string $body): void {
    global $mark;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . ' · RecompLauncher</title><link rel="icon" href="/favicon.ico"><style>'
        . "@font-face{font-family:Figtree;font-weight:500;src:url(/assets/fonts/figtree-500.woff2) format('woff2')}@font-face{font-family:Figtree;font-weight:700;src:url(/assets/fonts/figtree-700.woff2) format('woff2')}"
        . "@font-face{font-family:Archivo;font-weight:100 900;font-stretch:62% 125%;src:url(/assets/fonts/archivo-wdth.woff2) format('woff2')}"
        . '*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#0B1220;color:#F4F8FC;font:500 16px/1.5 Figtree,system-ui,sans-serif}'
        . 'body{display:grid;place-items:center;padding:32px 16px;min-height:100vh;background:radial-gradient(60% 50% at 15% -10%,rgba(52,242,232,.14),transparent 70%),radial-gradient(55% 55% at 100% -10%,rgba(55,123,182,.32),transparent 70%),#0B1220}'
        . '.box{width:100%;max-width:460px;padding:36px 32px;border-radius:24px;background:#0f1829;border:1px solid rgba(244,248,252,.1);text-align:center}'
        . '.mark{width:56px;height:56px;margin:0 auto 18px}h1{font:800 28px/1.15 Archivo,sans-serif;font-stretch:112%;letter-spacing:-.02em;margin:0 0 10px}'
        . 'p{margin:0 0 14px;color:#a7b3c4}b{color:#F4F8FC}.code{font:700 30px/1 Archivo,monospace;letter-spacing:.12em;color:#34F2E8;margin:6px 0 18px}'
        . '.who{display:flex;align-items:center;justify-content:center;gap:10px;margin:0 0 20px;color:#F4F8FC}.who img{width:32px;height:32px;border-radius:50%}'
        . '.acts{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:8px}.btn{display:flex;align-items:center;justify-content:center;height:48px;padding:0 18px;border-radius:12px;border:1px solid rgba(244,248,252,.14);background:rgba(244,248,252,.06);color:#F4F8FC;font:700 15px Figtree,sans-serif;text-decoration:none;cursor:pointer}'
        . '.btn.main{background:#34F2E8;border-color:#34F2E8;color:#06121A}.btn.wide{grid-column:span 2}'
        . 'input{width:100%;height:56px;border-radius:12px;border:1px solid rgba(244,248,252,.18);background:#0B1220;color:#F4F8FC;font:700 24px Archivo,monospace;letter-spacing:.14em;text-align:center;text-transform:uppercase;margin:4px 0 14px}'
        . '.small{font-size:13px;color:#6f7d90;margin:18px 0 0}.small a{color:#a7b3c4}'
        . '</style></head><body><main class="box"><div class="mark">' . $mark . '</div>' . $body . '</main></body></html>';
    exit;
}

try {
    if (!rs_acct_on()) rs_rl_page('Coming soon', '<h1>Accounts are coming soon</h1><p>Signing in to RecompLauncher opens here when accounts do. Everything in the launcher works without one.</p>');
    $me = rs_acct_current();
    if (!$me || $me['username'] === null) {
        // Sign in (or make an account) on recompstore.com, then straight back to this page.
        $new = !empty($_GET['new']) ? 'new=1&' : '';
        header('Location: https://recompstore.com/signin?' . $new . 'back=' . rawurlencode($here), true, 302);
        exit;
    }
    $who = '<div class="who">' . (rs_acct_avatar_url($me) !== '' ? '<img src="' . $h(rs_acct_avatar_url($me)) . '" alt="">' : '') . '<span>Signed in as <b>' . $h($me['username']) . '</b></span></div>';
    $type = '<form method="get" action="/activate"><p>Type the code RecompLauncher shows.</p><input name="code" maxlength="9" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="ABCD-EFGH" autofocus><div class="acts"><button class="btn main wide" type="submit">Continue</button></div></form>';
    if ($code === '') rs_rl_page('Connect RecompLauncher', '<h1>Connect RecompLauncher</h1>' . $who . $type);

    $link = rs_rl_link_by_code($code);
    if (!$link || $link['status'] !== 'pending') {
        rs_rl_page('Code not found', '<h1>That code didn\'t work</h1><p>Codes last 10 minutes and work once. Press Sign in in RecompLauncher again for a new one.</p>' . $type);
    }
    // The answer. The form carries a check tied to this browser's sign-in and the code.
    $check = hash_hmac('sha256', 'rl-activate|' . $code . '|' . (string)($_COOKIE[RS_ACCT_COOKIE] ?? ''), rs_mail_key());
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (!hash_equals($check, (string)($_POST['check'] ?? ''))) rs_rl_page('Try again', '<h1>Try that again</h1><p>That page was open too long. Go back to RecompLauncher and press Sign in again.</p>');
        $yes = ($_POST['answer'] ?? '') === 'yes';
        rs_rl_link_answer($code, (int)$me['id'], $yes);
        if ($yes) rs_rl_page('Connected', '<h1>You\'re signed in</h1>' . $who . '<p>Go back to RecompLauncher. It picks this up by itself in a few seconds.</p><p class="small">You can sign this PC out any time from <a href="/account">your account</a>.</p>');
        rs_rl_page('Not connected', '<h1>Not connected</h1><p>RecompLauncher on <b>' . $h($link['name']) . '</b> wasn\'t signed in. You can close this page.</p>');
    }
    rs_rl_page('Connect RecompLauncher', '<h1>Sign in RecompLauncher?</h1>' . $who
        . '<p>RecompLauncher on <b>' . $h($link['name']) . '</b> wants to use your account for cloud saves.</p><div class="code">' . $h($code) . '</div>'
        . '<p>Check that this is the code RecompLauncher shows.</p>'
        . '<form method="post" action="/activate"><input type="hidden" name="code" value="' . $h($code) . '"><input type="hidden" name="check" value="' . $h($check) . '">'
        . '<div class="acts"><button class="btn" type="submit" name="answer" value="no">Not me</button><button class="btn main" type="submit" name="answer" value="yes">Sign in</button></div></form>'
        . '<p class="small">Not ' . $h($me['username']) . '? <a href="https://recompstore.com/account">Switch accounts</a></p>');
} catch (Throwable $e) {
    if (function_exists('rs_errlog')) rs_errlog('activate.php', $e);
    rs_rl_page('Something went wrong', '<h1>Something went wrong</h1><p>Try again in a moment.</p>');
}
