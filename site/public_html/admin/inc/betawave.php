<?php
// RecompLauncher beta (3.1g): invites in waves. Each wave goes to the earliest sign-ups who haven't
// been invited yet; everyone is invited once, ever (one row per person, whatever the wave). The
// invite is a designed email with the person's own link: recomplauncher.com/get?beta=<token>,
// which turns the Get page's sign-up box into the download for them.
declare(strict_types=1);
require_once __DIR__ . '/rlpush.php';

const RS_LBW_NAMES = [1 => 'First wave', 2 => 'Second wave', 3 => 'Third wave', 4 => 'Fourth wave', 5 => 'Fifth wave'];

function rs_lbw_tables(): void {
    static $done = false;
    if ($done) return;
    rs_lbeta_tables();
    rs_db()->exec('CREATE TABLE IF NOT EXISTS launcher_beta_wave (
        beta_id INT PRIMARY KEY,
        wave TINYINT NOT NULL,
        token CHAR(32) NOT NULL,
        ok TINYINT NOT NULL DEFAULT 0,
        err VARCHAR(200) NOT NULL DEFAULT \'\',
        sent_at DATETIME NOT NULL,
        opened_at DATETIME NULL,
        downloads INT NOT NULL DEFAULT 0,
        UNIQUE KEY token (token),
        KEY wave (wave, ok)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_lbw_name(int $wave): string { return RS_LBW_NAMES[$wave] ?? ('Wave ' . $wave); }
function rs_lbw_link(string $token): string { return 'https://recomplauncher.com/get?beta=' . $token; }
// The preview link for the admin's own test emails: works like an invite, counts nothing.
function rs_lbw_test_token(): string {
    $t = (string)rs_setting('lbw_test_token', '');
    if (!preg_match('/^[a-f0-9]{32}$/', $t)) { $t = bin2hex(random_bytes(16)); rs_set_setting('lbw_test_token', $t); }
    return $t;
}

// A link's invite: ['wave' => n, 'test' => bool, 'beta_id' => id] or null.
function rs_lbw_check(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    if (hash_equals(rs_lbw_test_token(), $token)) return ['wave' => max(1, (int)rs_setting('lbw_wave', '1')), 'test' => true, 'beta_id' => 0];
    rs_lbw_tables();
    $st = rs_db()->prepare('SELECT w.beta_id, w.wave FROM launcher_beta_wave w JOIN launcher_beta b ON b.id = w.beta_id WHERE w.token = ? AND w.ok = 1');
    $st->execute([$token]);
    $r = $st->fetch();
    return $r ? ['wave' => (int)$r['wave'], 'test' => false, 'beta_id' => (int)$r['beta_id']] : null;
}

function rs_lbw_html(int $wave, string $link, string $off): string {
    $F = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
    $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    // The wave's own ticket when there's one on the server, otherwise the general "You're in" pass.
    $img = is_file(dirname(__DIR__, 2) . '/assets/email/beta-wave-' . $wave . '.jpg') ? 'beta-wave-' . $wave . '.jpg' : 'beta-pass.jpg';
    $img = 'https://recompstore.com/assets/email/' . $img;
    $step = fn(int $n, string $t, string $s) => '<tr><td style="padding:0 0 10px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0f1a2c;border:1px solid #22314a;border-radius:16px"><tr>'
        . '<td width="56" valign="top" style="padding:18px 0 18px 18px"><div style="width:36px;height:36px;border-radius:50%;background:#34F2E8;background-image:linear-gradient(180deg,#34F2E8,#377BB6);color:#05080d;font:800 17px/36px ' . $F . ';text-align:center">' . $n . '</div></td>'
        . '<td style="padding:18px 20px 18px 6px;font:15px/1.5 ' . $F . ';color:#C9D6E4"><b style="color:#F4F8FC;font-size:16px">' . $t . '</b><br>' . $s . '</td></tr></table></td></tr>';
    $what = $wave === 1 ? 'You signed up early, so you\'re in the first wave of the RecompLauncher beta.' : 'Your spot came up: you\'re in the ' . strtolower(rs_lbw_name($wave)) . ' of the RecompLauncher beta.';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><meta name="color-scheme" content="dark"><meta name="supported-color-schemes" content="dark"><title>RecompLauncher beta</title></head>'
        . '<body style="margin:0;padding:0;background:#05080d">'
        . '<div style="display:none;max-height:0;overflow:hidden">Your RecompLauncher beta pass is here. Download it with your own link.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#05080d" style="background:#05080d"><tr><td align="center" style="padding:16px 12px 40px">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px">'
        . '<tr><td style="line-height:0;padding:0 0 16px"><a href="' . $e($link) . '"><img src="' . $img . '" width="600" alt="RecompLauncher Beta Pass: ' . $e(rs_lbw_name($wave)) . '" style="display:block;width:100%;height:auto;border:0"></a></td></tr>'
        . '<tr><td bgcolor="#0B1220" style="background:#0B1220;border-radius:24px;padding:36px 36px 32px;font:16px/1.65 ' . $F . ';color:#C9D6E4">'
        . '<p style="margin:0 0 16px;font-size:18px;color:#F4F8FC"><b>Hey there,</b></p>'
        . '<p style="margin:0 0 16px">' . $e($what) . ' Install, update and play your PC recomps in one place, before anyone else.</p>'
        . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:26px 0 12px"><tr><td bgcolor="#34F2E8" style="border-radius:999px;background:#34F2E8;background-image:linear-gradient(180deg,#34F2E8,#377BB6)"><a href="' . $e($link) . '" style="display:inline-block;padding:17px 38px;font:800 17px ' . $F . ';color:#05080d;text-decoration:none;border-radius:999px">Download the beta &rarr;</a></td></tr></table>'
        . '<p style="margin:0 0 30px;font-size:13px;color:#7f8ca0">This link is yours. It only opens for people in this wave, so keep it to yourself.</p>'
        . '<p style="margin:0 0 14px;font:800 12px ' . $F . ';letter-spacing:.18em;color:#34F2E8">GETTING SET UP</p>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
        . $step(1, 'Install it', 'Run the setup file. If Windows says “Windows protected your PC”, click More info, then Run anyway.')
        . $step(2, 'Make it yours', 'Pick how you play and where your games are, then sign in with your RecompStore account (or skip it) to keep your saves in the cloud.')
        . $step(3, 'Add your games', 'Already have recomps? Open Library → Add games you have and it brings them in, or grab new ones from Discover.')
        . '</table>'
        . '<p style="margin:22px 0 0">It\'s a beta, so expect a few rough edges. If a game crashes, press Send it to Ripalda in the window that pops up. For anything else, use Settings → Beta → Send feedback. Every report helps.</p>'
        . '<p style="margin:12px 0 0;font-size:13px;color:#7f8ca0">Windows 10 or 11, 64-bit. On an old 0.9 test build? Uninstall it first.</p>'
        . '<p style="margin:22px 0 0;color:#F4F8FC">Thanks for being early,<br><b>Ripalda</b></p>'
        . '</td></tr>'
        . '<tr><td align="center" style="padding:24px 20px 0;font:12px/1.6 ' . $F . ';color:#5d6a7e">You\'re getting this because you signed up for the RecompLauncher beta.<br>'
        . '<a href="' . $e($off) . '" style="color:#7f8ca0">Leave the list</a> &nbsp;&middot;&nbsp; <a href="https://recomplauncher.com" style="color:#7f8ca0">recomplauncher.com</a></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function rs_lbw_text(int $wave, string $link): string {
    return "Hey there,\n\n"
        . ($wave === 1 ? "You signed up early, so you're in the first wave of the RecompLauncher beta." : "Your spot came up: you're in the " . strtolower(rs_lbw_name($wave)) . " of the RecompLauncher beta.")
        . "\n\nDownload it with your own link:\n$link\n\nThis link is yours. It only opens for people in this wave, so keep it to yourself.\n\n"
        . "Getting set up:\n1. Install it: run the setup file. If Windows says \"Windows protected your PC\", click More info, then Run anyway.\n2. Make it yours: pick how you play and where your games are, then sign in with your RecompStore account (or skip it) to keep your saves in the cloud.\n3. Add your games: already have recomps? Open Library > Add games you have, or grab new ones from Discover.\n\n"
        . "It's a beta, so expect a few rough edges. If a game crashes, press Send it to Ripalda in the window that pops up. For anything else, use Settings > Beta > Send feedback. Every report helps.\n\nWindows 10 or 11, 64-bit. On an old 0.9 test build? Uninstall it first.\n\nThanks for being early,\nRipalda";
}

function rs_lbw_build(string $to, int $wave, string $token, string $off): string {
    $link = rs_lbw_link($token);
    $subject = $wave === 1 ? "You're in: the RecompLauncher beta" : "You're in: RecompLauncher beta, " . strtolower(rs_lbw_name($wave));
    return rs_mail_build($to, $subject, rs_lbw_text($wave, $link), [
        'html' => rs_lbw_html($wave, $link, $off), 'unsub' => $off, 'from_name' => 'RecompLauncher',
        'footer' => "You're getting this because you signed up for the RecompLauncher beta.\nLeave the list: $off",
    ])['data'];
}

// The admin's test copy, to the site's mailbox (or $to).
function rs_lbw_test(int $wave, string $to = ''): string {
    $to = $to !== '' ? $to : rs_mail_get('mail_from');
    $err = rs_smtp_send([[$to, rs_lbw_build($to, $wave, rs_lbw_test_token(), 'https://recomplauncher.com/get')]])[0] ?? 'Not sent.';
    if ($err !== '') throw new RuntimeException($err);
    return $to;
}

// Numbers per wave: [wave => ['sent' => , 'failed' => , 'opened' => , 'downloads' => ]], plus who's left.
function rs_lbw_stats(): array {
    rs_lbw_tables();
    $db = rs_db();
    $waves = [];
    foreach ($db->query('SELECT wave, SUM(ok = 1) sent, SUM(ok = 0) failed, SUM(opened_at IS NOT NULL) opened, SUM(downloads) downloads FROM launcher_beta_wave GROUP BY wave ORDER BY wave') as $r)
        $waves[(int)$r['wave']] = ['sent' => (int)$r['sent'], 'failed' => (int)$r['failed'], 'opened' => (int)$r['opened'], 'downloads' => (int)$r['downloads']];
    $left = (int)$db->query('SELECT COUNT(*) FROM launcher_beta b LEFT JOIN launcher_beta_wave w ON w.beta_id = b.id WHERE b.confirmed = 1 AND b.off = 0 AND w.beta_id IS NULL')->fetchColumn();
    return ['waves' => $waves, 'left' => $left];
}

// Sends the next $n of the wave in progress (setting lbw_wave, lbw_size). -> [sent, failed, left in this wave]
// Each person is written down before their email goes, so nobody can get two invites.
function rs_lbw_step(int $n = 40): array {
    rs_lbw_tables();
    $db = rs_db();
    $wave = (int)rs_setting('lbw_wave', '0');
    $size = (int)rs_setting('lbw_size', '0');
    if ($wave < 1 || $size < 1) return [0, 0, 0];
    $st = $db->prepare('SELECT COUNT(*) FROM launcher_beta_wave WHERE wave = ?');
    $st->execute([$wave]);
    $room = $size - (int)$st->fetchColumn();
    if ($room <= 0) return [0, 0, 0];
    $take = min($n, $room);
    $got = $db->query('SELECT GET_LOCK(\'rs_lbw\', 5)')->fetchColumn();
    if ((int)$got !== 1) throw new RuntimeException('Another batch is still going out. Try again in a moment.');
    try {
        $rows = $db->query('SELECT b.id, b.email FROM launcher_beta b LEFT JOIN launcher_beta_wave w ON w.beta_id = b.id WHERE b.confirmed = 1 AND b.off = 0 AND w.beta_id IS NULL ORDER BY b.id LIMIT ' . (int)$take)->fetchAll();
        if (!$rows) return [0, 0, 0];
        $ins = $db->prepare('INSERT IGNORE INTO launcher_beta_wave (beta_id, wave, token, ok, sent_at) VALUES (?, ?, ?, 0, UTC_TIMESTAMP())');
        $mine = [];
        foreach ($rows as $r) {
            $tok = bin2hex(random_bytes(16));
            $ins->execute([(int)$r['id'], $wave, $tok]);
            if ($ins->rowCount() === 1) $mine[] = ['id' => (int)$r['id'], 'email' => (string)$r['email'], 'token' => $tok];
        }
    } finally {
        $db->query('SELECT RELEASE_LOCK(\'rs_lbw\')');
    }
    return rs_lbw_deliver($mine, $wave, $size);
}

// Sends to rows already written down. -> [sent, failed, left in this wave]
function rs_lbw_deliver(array $people, int $wave, int $size): array {
    $db = rs_db();
    $msgs = [];
    foreach ($people as $p) $msgs[] = [$p['email'], rs_lbw_build($p['email'], $wave, $p['token'], rs_lbeta_off_url($p['id']))];
    $errs = $msgs ? rs_smtp_send($msgs) : [];
    $ok = 0;
    $up = $db->prepare('UPDATE launcher_beta_wave SET ok = ?, err = ?, sent_at = UTC_TIMESTAMP() WHERE beta_id = ?');
    foreach ($people as $i => $p) {
        $e = (string)($errs[$i] ?? '');
        $up->execute([$e === '' ? 1 : 0, mb_substr($e, 0, 200), $p['id']]);
        if ($e === '') $ok++;
    }
    $st = $db->prepare('SELECT COUNT(*) FROM launcher_beta_wave WHERE wave = ?');
    $st->execute([$wave]);
    $inWave = (int)$st->fetchColumn();
    $avail = (int)$db->query('SELECT COUNT(*) FROM launcher_beta b LEFT JOIN launcher_beta_wave w ON w.beta_id = b.id WHERE b.confirmed = 1 AND b.off = 0 AND w.beta_id IS NULL')->fetchColumn();
    return [$ok, count($people) - $ok, max(0, min($size - $inWave, $avail))];
}

// Tries the ones that didn't go out again (same person, same link: still one invite each).
function rs_lbw_retry(int $wave, int $n = 40): array {
    rs_lbw_tables();
    $st = rs_db()->prepare('SELECT w.beta_id id, b.email, w.token FROM launcher_beta_wave w JOIN launcher_beta b ON b.id = w.beta_id WHERE w.wave = ? AND w.ok = 0 AND b.off = 0 ORDER BY w.beta_id LIMIT ' . (int)$n);
    $st->execute([$wave]);
    $people = array_map(fn($r) => ['id' => (int)$r['id'], 'email' => (string)$r['email'], 'token' => (string)$r['token']], $st->fetchAll());
    return rs_lbw_deliver($people, $wave, (int)rs_setting('lbw_size', '0'));
}

// ---- (3.1g) RecompLauncher builds: uploaded on the Launcher beta page, one current per platform ----
// The current build is what invite links download and what the launcher's update check offers.

const RS_LB_PLATFORMS = ['windows' => 'Windows', 'linux' => 'Linux', 'macos' => 'macOS'];
const RS_LB_EXT = ['exe', 'msi', 'zip', '7z', 'appimage', 'dmg', 'gz'];

function rs_lb_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS launcher_builds (
        id INT AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(40) NOT NULL,
        platform VARCHAR(12) NOT NULL DEFAULT \'windows\',
        file VARCHAR(190) NOT NULL,
        size BIGINT NOT NULL DEFAULT 0,
        sha256 CHAR(64) NOT NULL DEFAULT \'\',
        notes TEXT NULL,
        r2_key VARCHAR(255) NOT NULL DEFAULT \'\',
        live TINYINT NOT NULL DEFAULT 0,
        downloads INT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL,
        KEY live (platform, live)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// Builds are kept beside the PS5 betas, never served directly.
function rs_lb_dir(): string {
    $d = rs_beta_dir_ready() . '/launcher';
    if (!is_dir($d) && !@mkdir($d, 0755, true)) throw new RuntimeException('Could not make the launcher builds folder.');
    return $d;
}

function rs_lb_all(): array {
    rs_lb_tables();
    return rs_db()->query('SELECT * FROM launcher_builds ORDER BY id DESC')->fetchAll();
}
function rs_lb_get(int $id): ?array {
    rs_lb_tables();
    $st = rs_db()->prepare('SELECT * FROM launcher_builds WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function rs_lb_current(string $platform = 'windows'): ?array {
    rs_lb_tables();
    $st = rs_db()->prepare('SELECT * FROM launcher_builds WHERE platform = ? AND live = 1 ORDER BY id DESC LIMIT 1');
    $st->execute([$platform]);
    return $st->fetch() ?: null;
}
function rs_lb_make_current(int $id): void {
    $b = rs_lb_get($id);
    if (!$b) throw new RuntimeException('That build is gone.');
    $db = rs_db();
    $db->prepare('UPDATE launcher_builds SET live = 0 WHERE platform = ?')->execute([$b['platform']]);
    $db->prepare('UPDATE launcher_builds SET live = 1 WHERE id = ?')->execute([$id]);
    if ($b['platform'] === 'windows') rs_lb_publish_json();
}

// Where a build is on this server (its own folder, the name it was uploaded with).
function rs_lb_path(array $b): string {
    $rel = str_replace(['\\', '..'], ['/', ''], (string)$b['file']);
    return rs_lb_dir() . '/' . ltrim($rel, '/');
}

// The file's name when someone downloads it: the name it was uploaded with.
function rs_lb_download_name(array $b): string {
    return basename((string)$b['file']);
}

// A link to a build that doesn't run out (used by the update check), signed so it can't be guessed.
function rs_lb_sig(int $id): string { return substr(hash_hmac('sha256', "lb-dl|$id", rs_mail_key()), 0, 24); }
function rs_lb_url(int $id): string { return 'https://recomplauncher.com/api/launcher-update.php?dl=' . $id . '&s=' . rs_lb_sig($id); }

// The file the launcher itself checks each time it opens: recomplauncher.com/launcher/version.json
// (0.5 Beta 1 and later). Rewritten whenever the current Windows build changes.
function rs_lb_publish_json(): void {
    $dir = RS_PUBLIC . '/launcher';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not make the launcher folder.');
    if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "<IfModule mod_headers.c>\nHeader set Cache-Control \"no-cache, must-revalidate\"\nHeader set Access-Control-Allow-Origin \"*\"\n</IfModule>\n");
    $feed = rs_lb_json_feed();
    $old = json_decode((string)@file_get_contents($dir . '/version.json'), true);
    if (@file_put_contents($dir . '/version.json.tmp', json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false || !@rename($dir . '/version.json.tmp', $dir . '/version.json'))
        throw new RuntimeException('Could not write launcher/version.json.');
    // (3.1g) A new version: launchers that listen are told to look now.
    if (!empty($feed['version']) && (string)($old['version'] ?? '') !== (string)$feed['version']) rl_push('launcher');
}

// (3.7) What version.json says: the Windows build, and the Linux AppImage under "linux"
// (0.9 and later read it there and replace their AppImage in place).
function rs_lb_json_feed(): array {
    $feed = rs_lb_feed('windows');
    try { $lx = rs_lb_gh_latest('linux'); } catch (Throwable $e) { $lx = null; }
    if ($lx) $feed['linux'] = ['version' => $lx['version'], 'url' => $lx['url'], 'sha256' => $lx['sha256'], 'size' => $lx['size'],
        'deb' => (string)($lx['deb']['url'] ?? '')];
    return $feed;
}

// Sends the build: from Cloudflare R2 when it's there, otherwise straight from this server.
function rs_lb_send(array $b, bool $count = true): void {
    if ($count) rs_db()->prepare('UPDATE launcher_builds SET downloads = downloads + 1 WHERE id = ?')->execute([(int)$b['id']]);
    // (3.6) On GitHub: the download comes from there.
    if (($gh = rs_lb_gh_url_for($b)) !== '') { header('Cache-Control: no-store'); header('Location: ' . $gh, true, 302); exit; }
    $r2 = rs_r2();
    if ($r2 && (string)$b['r2_key'] !== '') {
        // R2 has every file as application/zip, so the browser is told it's the installer and its name.
        header('Location: ' . rs_r2_presign($r2, 'GET', (string)$b['r2_key'], 3600, null, ['response-content-type' => 'application/octet-stream',
            'response-content-disposition' => 'attachment; filename="' . rs_lb_download_name($b) . '"']), true, 302);
        exit;
    }
    $f = rs_lb_path($b);
    if (!is_file($f)) { http_response_code(404); header('Content-Type: text/plain'); echo 'This build is missing on the server.'; exit; }
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($f));
    header('Content-Disposition: attachment; filename="' . rs_lb_download_name($b) . '"');
    header('X-Content-Type-Options: nosniff');
    $fh = fopen($f, 'rb');
    while (!feof($fh)) { echo fread($fh, 1 << 20); flush(); }
    fclose($fh);
    exit;
}

// What the launcher's update check gets.
function rs_lb_feed(string $platform): array {
    $b = rs_lb_current($platform);
    $g = rs_lb_gh_latest($platform);
    // (3.6) A newer (or the same) release on GitHub wins: the launcher downloads it from there.
    if ($g && (!$b || version_compare($g['version'], rs_lb_semver_of($b), '>='))) return rs_lb_gh_feed($platform, $g, $b);
    if (!$b) return ['ok' => true, 'platform' => $platform, 'version' => null];
    // "version" is the build's own number (0.5.0-beta.1, from the installer's name) so the launcher
    // can compare it with its own; "name" is the label shown to people (0.5 Beta 1).
    // (3.1g) A build published from the Launcher page carries its exact version (0.75.0-beta.2),
    // and its name is made readable from it (0.75 Beta 2).
    $semver = rs_lb_semver_of($b);
    $label = rs_lb_is_semver((string)$b['version']) ? rs_lb_semver_name($semver) : (string)$b['version'];
    return ['ok' => true, 'platform' => $platform, 'channel' => 'beta', 'version' => $semver, 'name' => $label,
        'notes' => (string)$b['notes'], 'size' => (int)$b['size'], 'sha256' => (string)$b['sha256'],
        'url' => rs_lb_url((int)$b['id']), 'published' => gmdate('c', strtotime((string)$b['created'] . ' UTC')),
        'date' => gmdate('Y-m-d', strtotime((string)$b['created'] . ' UTC')),
        'github' => 'https://github.com/elripalda/RecompLauncher',
        // (3.1g) Where 0.75 Beta 10 and later listen for "look now" messages (see rlpush.php).
        'push' => RL_PUSH_SSE];
}

// A build's exact version (0.75.0-beta.21), from what it was published as or its installer's name.
function rs_lb_semver_of(array $b): string {
    if (rs_lb_is_semver((string)$b['version'])) return (string)$b['version'];
    return preg_match('/(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)(?=_|-x64|\.exe|$)/', basename((string)$b['file']), $m) ? $m[1] : (string)$b['version'];
}

// ---- (3.6) The launcher's releases on GitHub -------------------------------------------------
// The newest release of elripalda/RecompLauncher (betas included) that has a Windows installer.
// Publishing a release there is all it takes: the update check and the site's download buttons
// follow it within a few minutes. When GitHub can't be reached, the last answer it gave is used,
// and with none at all the build uploaded on the Launcher beta page is used as before.
const RS_LB_GH_REPO = 'elripalda/RecompLauncher';
function rs_lb_gh_on(): bool { return (string)rs_setting('lb_github', '1') !== '0'; }
// (3.7) Linux too: the newest release with an AppImage, plus its .deb when it has one.
const RS_LB_GH_ASSET = ['windows' => '/_x64-setup\\.exe$/i', 'linux' => '/_amd64\\.AppImage$/i'];
function rs_lb_gh_latest(string $platform = 'windows', int $maxAge = 120): ?array {
    if (!isset(RS_LB_GH_ASSET[$platform]) || !rs_lb_gh_on() || !function_exists('rs_gh_releases')) return null;
    if (!is_array($GLOBALS['rs_lb_gh_memo'] ?? null)) $GLOBALS['rs_lb_gh_memo'] = [];
    $memo = &$GLOBALS['rs_lb_gh_memo'][$platform];
    if ($maxAge > 0 && $memo !== null) return $memo ?: null;
    $memo = false;
    try { $r = rs_gh_releases(RS_LB_GH_REPO, $maxAge); } catch (Throwable $e) { error_log('launcher github: ' . $e->getMessage()); return null; }
    if ((int)$r['status'] !== 200) return null;
    $best = null;
    foreach (json_decode((string)$r['body'], true) ?: [] as $rel) {
        if (!is_array($rel) || !empty($rel['draft'])) continue;
        $ver = ltrim((string)($rel['tag_name'] ?? ''), 'vV');
        if (!rs_lb_is_semver($ver) || ($best && version_compare($ver, $best['version'], '<='))) continue;
        $deb = null;
        foreach ((array)($rel['assets'] ?? []) as $a) {
            $u = (string)($a['browser_download_url'] ?? '');
            if (preg_match('/_amd64\.deb$/i', (string)($a['name'] ?? '')) && str_starts_with($u, 'https://github.com/')) $deb = ['url' => $u, 'size' => (int)($a['size'] ?? 0)];
        }
        foreach ((array)($rel['assets'] ?? []) as $a) {
            $u = (string)($a['browser_download_url'] ?? '');
            if (!preg_match(RS_LB_GH_ASSET[$platform], (string)($a['name'] ?? '')) || !str_starts_with($u, 'https://github.com/')) continue;
            $sha = strtolower((string)($a['digest'] ?? ''));
            $best = ['version' => $ver, 'url' => $u, 'size' => (int)($a['size'] ?? 0),
                'sha256' => str_starts_with($sha, 'sha256:') ? substr($sha, 7) : '',
                'notes' => (string)($rel['body'] ?? ''), 'published' => (string)($rel['published_at'] ?? ''),
                'page' => (string)($rel['html_url'] ?? ''), 'deb' => $platform === 'linux' ? $deb : null];
            break;
        }
    }
    $memo = $best ?: false;
    return $best;
}
// (3.6) Every launcher release on GitHub with its installer's downloads, newest first (admin).
function rs_lb_gh_list(int $maxAge = 120): array {
    if (!function_exists('rs_gh_releases')) return [];
    try { $r = rs_gh_releases(RS_LB_GH_REPO, $maxAge); } catch (Throwable $e) { return []; }
    if ((int)$r['status'] !== 200) return [];
    $out = [];
    foreach (json_decode((string)$r['body'], true) ?: [] as $rel) {
        if (!is_array($rel) || !empty($rel['draft'])) continue;
        // (3.7) Windows and Linux counted apart: the installer; the AppImage and the .deb.
        $win = 0; $appimage = 0; $deb = 0; $file = '';
        foreach ((array)($rel['assets'] ?? []) as $a) {
            $nm = (string)($a['name'] ?? ''); $c = (int)($a['download_count'] ?? 0);
            if (preg_match('/_x64-setup\.exe$/i', $nm)) { $win += $c; $file = $nm; }
            elseif (preg_match('/_amd64\.AppImage$/i', $nm)) { $appimage += $c; $file = $nm; }
            elseif (preg_match('/_amd64\.deb$/i', $nm)) { $deb += $c; $file = $nm; }
        }
        if ($file === '') continue;
        $out[] = ['version' => ltrim((string)$rel['tag_name'], 'vV'), 'published' => (string)($rel['published_at'] ?? ''), 'downloads' => $win + $appimage + $deb,
            'windows' => $win, 'appimage' => $appimage, 'deb' => $deb, 'linux' => $appimage + $deb,
            'pre' => !empty($rel['prerelease']), 'page' => (string)($rel['html_url'] ?? '')];
    }
    return $out;
}
function rs_lb_gh_feed(string $platform, array $g, ?array $b): array {
    $t = strtotime($g['published']) ?: time();
    // The same version uploaded here too: its notes (the ones written on the Launcher page) are kept.
    $notes = $b && rs_lb_semver_of($b) === $g['version'] && trim((string)$b['notes']) !== '' ? (string)$b['notes'] : $g['notes'];
    return ['ok' => true, 'platform' => $platform, 'channel' => 'beta', 'version' => $g['version'], 'name' => rs_lb_semver_name($g['version']),
        'notes' => $notes, 'size' => $g['size'], 'sha256' => $g['sha256'], 'url' => $g['url'],
        'published' => gmdate('c', $t), 'date' => gmdate('Y-m-d', $t), 'source' => 'github', 'release' => $g['page'],
        'github' => 'https://github.com/' . RS_LB_GH_REPO, 'push' => RL_PUSH_SSE];
}
// Where a download of this build goes on GitHub, when it's there (the same version, or the live
// build when GitHub already has a newer one). '' = send it from here.
function rs_lb_gh_url_for(array $b): string {
    if ((string)$b['platform'] !== 'windows' || !($g = rs_lb_gh_latest('windows'))) return '';
    $c = version_compare($g['version'], rs_lb_semver_of($b));
    return $c === 0 || ($c > 0 && (int)$b['live'] === 1) ? $g['url'] : '';
}
// Rewrites launcher/version.json when what it should say has changed (a new release on GitHub).
function rs_lb_refresh_json(): void {
    try {
        $want = rs_lb_json_feed();
        $have = json_decode((string)@file_get_contents(RS_PUBLIC . '/launcher/version.json'), true) ?: [];
        if (empty($want['version'])) return;
        foreach (['version', 'url', 'sha256', 'notes'] as $k) if ((string)($have[$k] ?? '') !== (string)($want[$k] ?? '')) { rs_lb_publish_json(); return; }
        if ((string)($have['linux']['url'] ?? '') !== (string)($want['linux']['url'] ?? '')) { rs_lb_publish_json(); return; }
    } catch (Throwable $e) { error_log('version.json refresh: ' . $e->getMessage()); }
}

// 0.9.2, or a beta or alpha of one: 0.75.0-beta.2, 1.0.0-alpha.1.
function rs_lb_is_semver(string $v): bool { return (bool)preg_match('/^\d+\.\d+\.\d+(-(beta|alpha)\.\d+)?$/', $v); }

// "0.75 Beta 2" for 0.75.0-beta.2; a plain release stays as it is (0.9.2).
function rs_lb_semver_name(string $v): string {
    if (!preg_match('/^(\d+)\.(\d+)\.(\d+)-(beta|alpha)\.(\d+)$/', $v, $m)) return $v;
    return $m[1] . '.' . $m[2] . ($m[3] !== '0' ? '.' . $m[3] : '') . ' ' . ucfirst($m[4]) . ' ' . $m[5];
}
