<?php
// RecompLauncher website (3.0): the beta sign-up list for RecompLauncher on Windows.
//   GET  a=info                -> {ok, on, accounts}       (the pop-up and the Get page ask this)
//   POST a=join email website  -> {ok, state: sent|there}  (JSON; a confirm link is emailed)
//   GET  a=confirm&t=          -> confirmed, then the Get page
//   GET  a=off&b=&s=           -> off the list (the link in every beta email)
// (3.3) The beta going out (admin: RecompLauncher, Launch):
//   GET  a=info&k=             -> also {launch}: the download and the Get page's words, once it's
//                                 live, or before that with the preview key (null otherwise)
//   GET  a=download            -> counted, then the installer (only once it's live)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/rl_launch.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function rs_lb_out(int $code, array $body): void { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); exit; }
function rs_lb_page(string $title, string $text): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RecompLauncher</title>'
        . '<body style="background:#0B1220;color:#F4F8FC;font:16px/1.5 system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;margin:0;padding:16px">'
        . '<div style="max-width:440px;text-align:center"><h1 style="font-size:24px">' . h($title) . '</h1><p>' . h($text) . '</p><p><a style="color:#34F2E8" href="https://recomplauncher.com/get">RecompLauncher</a></p></div>';
    exit;
}

$a = (string)($_GET['a'] ?? '');
// (3.1g) The launch info is the same for everyone without a preview key: a 30-second copy of it.
if ($a === 'info' && (string)($_GET['k'] ?? '') === '') { require_once dirname(__DIR__) . '/admin/inc/jcache.php'; rs_json_cache('lb-info', 30); }
try {
    rs_acct_tables();
    rs_lbeta_tables();
    if ($a === 'info') {
        $launch = null;
        try {
            require_once dirname(__DIR__) . '/admin/inc/betawave.php';
            rs_lb_refresh_json();
            $k = (string)($_GET['k'] ?? '');
            $preview = $k !== '' && rs_rl_launch_preview_ok($k);
            if ($preview || rs_rl_launch_live()) $launch = rs_rl_launch_public($preview);
        } catch (Throwable $e) { error_log('launcher-beta.php launch: ' . $e->getMessage()); }
        rs_lb_out(200, ['ok' => true, 'on' => rs_lbeta_on(), 'accounts' => rs_acct_on(), 'launch' => $launch]);
    }
    if ($a === 'download') {
        // (3.6) The newest release on GitHub, if there's one newer than what version.json says.
        try { require_once dirname(__DIR__) . '/admin/inc/betawave.php'; rs_lb_refresh_json(); } catch (Throwable $e) { error_log('launcher-beta.php refresh: ' . $e->getMessage()); }
        $v = rs_rl_version();
        // (3.7) The Linux builds: the AppImage, or the .deb.
        $os = (string)($_GET['os'] ?? '');
        if ($os === 'linux' || $os === 'deb') {
            $g = function_exists('rs_lb_gh_latest') ? rs_lb_gh_latest('linux') : null;
            $u = $g ? ($os === 'deb' ? (string)($g['deb']['url'] ?? '') : (string)$g['url']) : '';
            if (!rs_rl_launch_live() || $u === '') { header('Location: https://recomplauncher.com/get', true, 302); exit; }
            try { rs_rl_launch_count(); } catch (Throwable $e) { error_log('launcher-beta.php count: ' . $e->getMessage()); }
            header('Location: ' . $u, true, 302);
            exit;
        }
        // (4.2) The Mac build: the Apple Silicon .dmg.
        if ($os === 'mac') {
            $g = function_exists('rs_lb_gh_latest') && rs_lb_mac_shown() ? rs_lb_gh_latest('macos') : null;
            $u = $g ? (string)$g['url'] : '';
            if (!rs_rl_launch_live() || $u === '') { header('Location: https://recomplauncher.com/get', true, 302); exit; }
            try { rs_rl_launch_count(); } catch (Throwable $e) { error_log('launcher-beta.php count: ' . $e->getMessage()); }
            header('Location: ' . $u, true, 302);
            exit;
        }
        if (!rs_rl_launch_live() || empty($v['url'])) { header('Location: https://recomplauncher.com/get', true, 302); exit; }
        try { rs_rl_launch_count(); } catch (Throwable $e) { error_log('launcher-beta.php count: ' . $e->getMessage()); }
        header('Location: ' . (string)$v['url'], true, 302);
        exit;
    }
    if ($a === 'confirm') {
        $t = (string)($_GET['t'] ?? '');
        $ok = false;
        if (preg_match('/^[0-9a-f]{48}$/', $t)) {
            $st = rs_db()->prepare('UPDATE launcher_beta SET confirmed = 1, confirmed_at = UTC_TIMESTAMP(), token = \'\' WHERE token = ?');
            $st->execute([hash('sha256', $t)]);
            $ok = $st->rowCount() > 0;
        }
        header('Location: https://recomplauncher.com/get?beta=' . ($ok ? 'confirmed' : 'old'), true, 302);
        exit;
    }
    if ($a === 'off') {
        $id = (int)($_GET['b'] ?? 0);
        $ok = $id > 0 && hash_equals(rs_lbeta_sig($id), (string)($_GET['s'] ?? ''));
        if ($ok) rs_db()->prepare('UPDATE launcher_beta SET off = 1 WHERE id = ?')->execute([$id]);
        rs_lb_page($ok ? 'You\'re off the beta list' : 'That link didn\'t work', $ok ? 'No more RecompLauncher beta emails.' : 'Sign up again on the RecompLauncher page if you changed your mind.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_lb_out(400, ['ok' => false, 'error' => 'Unknown request.']);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '' || preg_replace('/^www\./', '', strtolower((string)parse_url($origin, PHP_URL_HOST))) !== rs_acct_host()) rs_lb_out(403, ['ok' => false, 'error' => 'Sign up from the website.']);
    if (!rs_lbeta_on()) rs_lb_out(404, ['ok' => false, 'error' => 'The beta list is closed right now.']);
    $in = json_decode((string)file_get_contents('php://input', false, null, 0, 4096), true);
    if (!is_array($in)) rs_lb_out(400, ['ok' => false, 'error' => 'The form didn\'t arrive whole. Try again.']);
    if (trim((string)($in['website'] ?? '')) !== '') rs_lb_out(200, ['ok' => true, 'state' => 'sent']); // a bot
    $email = strtolower(trim((string)($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) rs_lb_out(422, ['ok' => false, 'error' => 'That doesn\'t look like an email address.']);
    if (!rs_mail_ready()) rs_lb_out(503, ['ok' => false, 'error' => 'Sign-ups aren\'t open right now. Try again later.']);
    $ip = rs_ip_hash();
    if (rs_acct_try_count('beta', $ip, 60) >= 5) rs_lb_out(429, ['ok' => false, 'error' => 'Lots of sign-ups from here. Try again in an hour.']);
    rs_acct_try_add('beta', $ip);
    try { $state = rs_lbeta_join($email, rs_acct_current()); }
    catch (RuntimeException $e) { error_log('launcher-beta.php mail: ' . $e->getMessage()); rs_lb_out(503, ['ok' => false, 'error' => 'We couldn\'t send the email just now. Try again in a few minutes.']); }
    rs_lb_out(200, ['ok' => true, 'state' => $state]);
} catch (Throwable $e) {
    error_log('launcher-beta.php: ' . $e->getMessage());
    rs_lb_out(500, ['ok' => false, 'error' => 'Something went wrong on our side. Try again in a moment.']);
}
