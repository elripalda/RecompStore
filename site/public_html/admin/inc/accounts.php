<?php
// (3.0) RecompStore accounts: players (and developers) sign in with Google, Discord, GitHub or an
// email and password. An account keeps a username, saved games, email choices and, later, cloud
// saves. Passwords are only kept as hashes; sign-in cookies can't be read by scripts. Everything is
// off for visitors until the admin turns it on (Accounts page), with a preview for the admin's browser.
declare(strict_types=1);

const RS_ACCT_COOKIE = 'rs_acct';
const RS_ACCT_DAYS = 60;
const RS_ACCT_HOSTS = ['recompstore.com', 'recomplauncher.com'];
const RS_ACCT_RESERVED = ['admin', 'administrator', 'recompstore', 'recomplauncher', 'ripalda', 'elripalda', 'moderator', 'mod', 'support', 'staff',
    'help', 'root', 'system', 'official', 'team', 'security', 'deleted', 'deleteduser', 'anonymous', 'null', 'undefined', 'sony', 'playstation', 'xbox', 'nintendo'];

function rs_acct_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        uid CHAR(16) NOT NULL UNIQUE,
        username VARCHAR(20) NULL,
        username_lc VARCHAR(20) NULL UNIQUE,
        email VARCHAR(190) NULL,
        email_lc VARCHAR(190) NULL UNIQUE,
        email_ok TINYINT NOT NULL DEFAULT 0,
        pass_hash VARCHAR(255) NULL,
        google_sub VARCHAR(64) NULL UNIQUE,
        discord_id VARCHAR(32) NULL UNIQUE,
        github_id BIGINT NULL UNIQUE,
        totp_secret VARCHAR(64) NULL,
        totp_last BIGINT NOT NULL DEFAULT 0,
        totp_on TINYINT NOT NULL DEFAULT 0,
        backup_codes TEXT NULL,
        prefs TEXT NULL,
        banned TINYINT NOT NULL DEFAULT 0,
        name_changed DATETIME NULL,
        created DATETIME NOT NULL,
        last_seen DATETIME NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS account_sessions (
        token_hash CHAR(64) PRIMARY KEY,
        account_id INT NOT NULL,
        created DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        host VARCHAR(40) NOT NULL DEFAULT \'\',
        agent VARCHAR(160) NOT NULL DEFAULT \'\',
        KEY account (account_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS account_tokens (
        token_hash CHAR(64) PRIMARY KEY,
        account_id INT NOT NULL,
        kind VARCHAR(12) NOT NULL,
        data VARCHAR(255) NOT NULL DEFAULT \'\',
        expires DATETIME NOT NULL,
        KEY account (account_id, kind)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS account_saved (
        account_id INT NOT NULL,
        game VARCHAR(80) NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY (account_id, game)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS account_names_held (
        name_lc VARCHAR(20) PRIMARY KEY,
        until DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS account_tries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(12) NOT NULL,
        k CHAR(64) NOT NULL,
        at DATETIME NOT NULL,
        KEY look (kind, k, at)
    ) CHARACTER SET utf8mb4');
    if (rs_col_missing($db, 'accounts', 'avatar')) $db->exec("ALTER TABLE accounts ADD COLUMN avatar VARCHAR(120) NULL");
    // Email sign-ups that never confirmed: gone after 7 days (their username is free again).
    if (random_int(1, 40) === 1) $db->exec("DELETE FROM accounts WHERE email_ok = 0 AND pass_hash IS NOT NULL AND google_sub IS NULL AND discord_id IS NULL AND github_id IS NULL AND created < (UTC_TIMESTAMP() - INTERVAL 7 DAY)");
    $done = true;
}

// ---- Switches and keys -------------------------------------------------------------------------

// The preview cookie: the admin's browser sees accounts before everyone does.
function rs_acct_preview_value(): string { return hash_hmac('sha256', 'accounts-preview', rs_mail_key()); }
function rs_acct_preview(): bool {
    $c = (string)($_COOKIE['rs_acct_pv'] ?? '');
    return $c !== '' && hash_equals(rs_acct_preview_value(), $c);
}
function rs_acct_live(): bool { return rs_setting('acct_on', '0') === '1'; }
function rs_acct_on(): bool { return rs_acct_live() || rs_acct_preview(); }

// Each way to sign in, and whether its keys are in.
function rs_acct_providers(): array {
    $p = [
        'google' => ['name' => 'Google', 'client' => trim((string)rs_setting('acct_google_id', '')), 'secret' => rs_secret_get('acct_google_secret')],
        'discord' => ['name' => 'Discord', 'client' => trim((string)rs_setting('dc_client_id', '')), 'secret' => rs_secret_get('dc_client_secret')],
        'github' => ['name' => 'GitHub', 'client' => trim((string)rs_setting('acct_gh_id', '')), 'secret' => rs_secret_get('acct_gh_secret')],
    ];
    foreach ($p as $k => $v) $p[$k]['ready'] = $v['client'] !== '' && $v['secret'] !== '' && rs_setting('acct_' . $k . '_on', '1') === '1';
    // Discord's app is shared with the beta codes: it only counts once its new redirect is in.
    $p['discord']['ready'] = $p['discord']['ready'] && rs_setting('acct_dc_redirect_done', '') === '1';
    return $p;
}
function rs_acct_ready_providers(): array {
    return array_keys(array_filter(rs_acct_providers(), fn($p) => $p['ready']));
}
function rs_acct_turnstile_site(): string { return trim((string)rs_setting('acct_ts_site', '')); }
function rs_acct_turnstile_ready(): bool { return rs_acct_turnstile_site() !== '' && rs_secret_get('acct_ts_secret') !== ''; }

// The one address every provider sends people back to.
function rs_acct_oauth_redirect(): string { return 'https://recompstore.com/api/oauth.php'; }

// ---- Small helpers ----------------------------------------------------------------------------

function rs_acct_host(): string {
    $h = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    $h = preg_replace('/^www\./', '', $h) ?? $h;
    return in_array($h, RS_ACCT_HOSTS, true) ? $h : $h;
}
// (4.8) This site's own address and name, for links and emails: a sign-up on recomplauncher.com
// gets RecompLauncher's, so people who came for the PC side never land on recompstore.com.
function rs_acct_site(): string { $h = rs_acct_host(); return 'https://' . (in_array($h, RS_ACCT_HOSTS, true) ? $h : 'recompstore.com'); }
function rs_acct_brand(): string { return rs_acct_host() === 'recomplauncher.com' ? 'RecompLauncher' : 'RecompStore'; }
// The site a sign-in started on (where it ends): the address it goes back to.
function rs_acct_home(string $back): string {
    $h = preg_replace('/^www\./', '', strtolower((string)parse_url(rs_acct_back($back), PHP_URL_HOST)));
    return 'https://' . (in_array($h, RS_ACCT_HOSTS, true) ? $h : 'recompstore.com');
}
function rs_acct_https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }

function rs_acct_cookie(string $name, string $value, int $days, string $path = '/', string $same = 'Lax'): void {
    setcookie($name, $value, ['expires' => $days ? time() + $days * 86400 : ($value === '' ? time() - 3600 : 0), 'path' => $path,
        'secure' => rs_acct_https(), 'httponly' => true, 'samesite' => $same]);
}

function rs_acct_try_count(string $kind, string $key, int $minutes): int {
    rs_acct_tables();
    $st = rs_db()->prepare('SELECT COUNT(*) FROM account_tries WHERE kind = ? AND k = ? AND at > (UTC_TIMESTAMP() - INTERVAL ? MINUTE)');
    $st->execute([$kind, hash('sha256', $key), $minutes]);
    return (int)$st->fetchColumn();
}
function rs_acct_try_add(string $kind, string $key): void {
    rs_acct_tables();
    $db = rs_db();
    $db->prepare('INSERT INTO account_tries (kind, k, at) VALUES (?, ?, UTC_TIMESTAMP())')->execute([$kind, hash('sha256', $key)]);
    if (random_int(1, 50) === 1) $db->exec('DELETE FROM account_tries WHERE at < (UTC_TIMESTAMP() - INTERVAL 2 DAY)');
}

// Cloudflare Turnstile: true when the check passed (or when its keys aren't in yet).
function rs_acct_turnstile_ok(string $token): bool {
    if (!rs_acct_turnstile_ready()) return true;
    if ($token === '' || strlen($token) > 2048) return false;
    $r = rs_acct_http('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [], [
        'secret' => rs_secret_get('acct_ts_secret'), 'response' => $token, 'remoteip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
    return !empty($r['json']['success']);
}

// An HTTP call to a provider. $form: sent as a form. -> ['code' => int, 'json' => ?array]
function rs_acct_http(string $method, string $url, array $headers = [], ?array $form = null): array {
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'User-Agent: RecompStore'], $headers);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        $h[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['code' => $code, 'json' => is_array($json) ? $json : null];
}

// ---- Usernames, passwords, emails ----------------------------------------------------------------

// Why a username can't be used, or null. $exceptId: the account changing its own name.
function rs_acct_name_error(string $name, int $exceptId = 0): ?string {
    if (!preg_match('/^[A-Za-z0-9_]{3,20}$/', $name)) return 'Use 3 to 20 letters, numbers or underscores.';
    $lc = strtolower($name);
    foreach (RS_ACCT_RESERVED as $r) if ($lc === $r || ($r !== 'mod' && strlen($r) > 4 && str_contains($lc, $r))) return 'That username is reserved.';
    rs_acct_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT id FROM accounts WHERE username_lc = ?');
    $st->execute([$lc]);
    $id = $st->fetchColumn();
    if ($id !== false && (int)$id !== $exceptId) return 'That username is taken.';
    $st = $db->prepare('SELECT COUNT(*) FROM account_names_held WHERE name_lc = ? AND until > UTC_TIMESTAMP()');
    $st->execute([$lc]);
    if ((int)$st->fetchColumn() && $id === false) return 'That username is taken.';
    return null;
}

// A free username close to $want (for "Try ruben_ps5").
function rs_acct_name_suggest(string $want): string {
    $base = substr(preg_replace('/[^A-Za-z0-9_]/', '', $want) ?: 'player', 0, 14);
    if (strlen($base) < 3) $base = 'player' . $base;
    foreach (['_ps5', '_pc', '_' . random_int(10, 99), '_' . random_int(100, 999), '_' . random_int(1000, 9999)] as $s)
        if (rs_acct_name_error($base . $s) === null) return $base . $s;
    return 'player_' . random_int(10000, 99999);
}

function rs_acct_pass_error(string $p): ?string {
    if (mb_strlen($p) < 10) return 'Use at least 10 characters.';
    if (strlen($p) > 200) return 'That password is too long.';
    return null;
}
function rs_acct_hash(string $p): string {
    return defined('PASSWORD_ARGON2ID') ? password_hash($p, PASSWORD_ARGON2ID) : password_hash($p, PASSWORD_BCRYPT, ['cost' => 12]);
}

function rs_acct_prefs(array $a): array {
    $p = json_decode((string)($a['prefs'] ?? ''), true);
    return array_merge(['weekly' => false, 'launcher' => false, 'updates' => false], is_array($p) ? array_map('boolval', $p) : []);
}

// ---- Accounts ---------------------------------------------------------------------------------

function rs_acct_get(int $id): ?array {
    rs_acct_tables();
    $st = rs_db()->prepare('SELECT * FROM accounts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}
function rs_acct_by(string $col, $value): ?array {
    if (!in_array($col, ['email_lc', 'username_lc', 'google_sub', 'discord_id', 'github_id', 'uid'], true)) return null;
    rs_acct_tables();
    $st = rs_db()->prepare("SELECT * FROM accounts WHERE $col = ?");
    $st->execute([$value]);
    return $st->fetch() ?: null;
}

function rs_acct_create(array $f): array {
    rs_acct_tables();
    $db = rs_db();
    $cols = ['uid' => bin2hex(random_bytes(8)), 'created' => gmdate('Y-m-d H:i:s')] + $f;
    $db->prepare('INSERT INTO accounts (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($cols));
    return rs_acct_get((int)$db->lastInsertId());
}

function rs_acct_update(int $id, array $f): void {
    if (!$f) return;
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
    rs_db()->prepare("UPDATE accounts SET $sets WHERE id = ?")->execute([...array_values($f), $id]);
}

// What the site is told about the signed-in account (never hashes or secrets).
function rs_acct_public(array $a): array {
    $dev = rs_acct_dev($a);
    return [
        'uid' => $a['uid'], 'username' => $a['username'], 'needName' => $a['username'] === null, 'avatar' => rs_acct_avatar_url($a),
        'email' => (string)$a['email'], 'emailOk' => (bool)$a['email_ok'], 'hasPassword' => $a['pass_hash'] !== null,
        'linked' => ['google' => $a['google_sub'] !== null, 'discord' => $a['discord_id'] !== null, 'github' => $a['github_id'] !== null],
        'twoStep' => (bool)$a['totp_on'], 'prefs' => rs_acct_prefs($a), 'created' => substr((string)$a['created'], 0, 10),
        'developer' => $dev ? ['name' => rs_dev_label($dev)] : null,
        'canRename' => $a['name_changed'] === null || strtotime((string)$a['name_changed'] . ' UTC') < time() - 30 * 86400,
    ];
}

// The developer account that goes with a player account: same GitHub, or the same confirmed email.
function rs_acct_dev(array $a): ?array {
    static $cache = [];
    if (array_key_exists($a['id'], $cache)) return $cache[$a['id']];
    $dev = null;
    try {
        rs_dev_tables();
        if ($a['github_id'] !== null) {
            $st = rs_db()->prepare('SELECT id FROM dev_accounts WHERE gh_id = ?');
            $st->execute([(int)$a['github_id']]);
            $id = $st->fetchColumn();
            if ($id !== false) $dev = rs_dev_account((int)$id);
        }
        if (!$dev && $a['email_ok'] && $a['email'] !== null && function_exists('rs_dev_by_email')) $dev = rs_dev_by_email((string)$a['email']) ?: null;
    } catch (Throwable $e) { $dev = null; }
    return $cache[$a['id']] = $dev;
}

// ---- Sessions ---------------------------------------------------------------------------------

function rs_acct_session_start(int $id): void {
    rs_acct_tables();
    $raw = bin2hex(random_bytes(32));
    rs_db()->prepare('INSERT INTO account_sessions (token_hash, account_id, created, last_seen, host, agent) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)')
        ->execute([hash('sha256', $raw), $id, substr(rs_acct_host(), 0, 40), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)]);
    rs_acct_update($id, ['last_seen' => gmdate('Y-m-d H:i:s')]);
    rs_acct_cookie(RS_ACCT_COOKIE, $raw, RS_ACCT_DAYS);
    $_COOKIE[RS_ACCT_COOKIE] = $raw;
}

function rs_acct_current(): ?array {
    static $me = false;
    if ($me !== false) return $me;
    $raw = (string)($_COOKIE[RS_ACCT_COOKIE] ?? '');
    if (!preg_match('/^[0-9a-f]{64}$/', $raw)) return $me = null;
    rs_acct_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT account_id, last_seen FROM account_sessions WHERE token_hash = ? AND last_seen > (UTC_TIMESTAMP() - INTERVAL ' . RS_ACCT_DAYS . ' DAY)');
    $st->execute([hash('sha256', $raw)]);
    $s = $st->fetch();
    if (!$s) return $me = null;
    $a = rs_acct_get((int)$s['account_id']);
    if (!$a || $a['banned']) return $me = null;
    if (strtotime((string)$s['last_seen'] . ' UTC') < time() - 300) {
        $db->prepare('UPDATE account_sessions SET last_seen = UTC_TIMESTAMP() WHERE token_hash = ?')->execute([hash('sha256', $raw)]);
        rs_acct_update((int)$a['id'], ['last_seen' => gmdate('Y-m-d H:i:s')]);
    }
    return $me = $a;
}

function rs_acct_session_end(bool $everywhere = false): void {
    $raw = (string)($_COOKIE[RS_ACCT_COOKIE] ?? '');
    $a = rs_acct_current();
    if ($everywhere && $a) rs_db()->prepare('DELETE FROM account_sessions WHERE account_id = ?')->execute([(int)$a['id']]);
    elseif ($raw !== '') rs_db()->prepare('DELETE FROM account_sessions WHERE token_hash = ?')->execute([hash('sha256', $raw)]);
    rs_acct_cookie(RS_ACCT_COOKIE, '', 0);
}

function rs_acct_sessions(array $a): array {
    $st = rs_db()->prepare('SELECT token_hash, created, last_seen, host, agent FROM account_sessions WHERE account_id = ? ORDER BY last_seen DESC LIMIT 30');
    $st->execute([(int)$a['id']]);
    $mine = hash('sha256', (string)($_COOKIE[RS_ACCT_COOKIE] ?? ''));
    return array_map(fn($s) => ['id' => substr($s['token_hash'], 0, 12), 'created' => $s['created'], 'seen' => $s['last_seen'], 'site' => $s['host'],
        'device' => rs_acct_device((string)$s['agent']), 'current' => hash_equals($s['token_hash'], $mine)], $st->fetchAll());
}

// "Chrome on Windows" from a browser's user agent.
function rs_acct_device(string $ua): string {
    $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'A browser'))));
    $o = preg_match('/PlayStation/', $ua) ? 'PS5' : (preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Mac OS X/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : '')))));
    return $o ? "$b on $o" : $b;
}

// ---- One-time tokens: confirm email, new password, two-step, hand-off between the two sites ----

function rs_acct_token_new(int $id, string $kind, int $minutes, string $data = ''): string {
    rs_acct_tables();
    $raw = bin2hex(random_bytes(24));
    $db = rs_db();
    if ($kind !== 'handoff') $db->prepare('DELETE FROM account_tokens WHERE account_id = ? AND kind = ?')->execute([$id, $kind]);
    $db->prepare('INSERT INTO account_tokens (token_hash, account_id, kind, data, expires) VALUES (?, ?, ?, ?, (UTC_TIMESTAMP() + INTERVAL ? MINUTE))')
        ->execute([hash('sha256', $raw), $id, $kind, mb_substr($data, 0, 255), $minutes]);
    if (random_int(1, 20) === 1) $db->exec('DELETE FROM account_tokens WHERE expires < UTC_TIMESTAMP()');
    return $raw;
}
// Uses a token up. -> [account id, data] or null.
function rs_acct_token_use(string $raw, string $kind, bool $keep = false): ?array {
    if (!preg_match('/^[0-9a-f]{48}$/', $raw)) return null;
    rs_acct_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT account_id, data FROM account_tokens WHERE token_hash = ? AND kind = ? AND expires > UTC_TIMESTAMP()');
    $st->execute([hash('sha256', $raw), $kind]);
    $r = $st->fetch();
    if (!$r) return null;
    if (!$keep) $db->prepare('DELETE FROM account_tokens WHERE token_hash = ?')->execute([hash('sha256', $raw)]);
    return [(int)$r['account_id'], (string)$r['data']];
}

// ---- Email --------------------------------------------------------------------------------------

function rs_acct_mail_confirm(array $a): void {
    if (!rs_mail_ready() || $a['email'] === null) return;
    $t = rs_acct_token_new((int)$a['id'], 'confirm', 60 * 48, (string)$a['email_lc']);
    $link = rs_acct_site() . '/api/account.php?a=confirm&t=' . $t;
    $brand = rs_acct_brand();
    rs_mailq_add((string)$a['email'], 'Confirm your ' . $brand . ' account',
        "Hi " . ($a['username'] ?: 'there') . ",\n\nConfirm your email for $brand with this link:\n$link\n\nIt works for 48 hours. If you didn't make an account, ignore this email and nothing happens.",
        ['kind' => 'confirm'], 1, true);
}

function rs_acct_mail_reset(array $a): void {
    if (!rs_mail_ready() || $a['email'] === null) return;
    $t = rs_acct_token_new((int)$a['id'], 'reset', 30);
    $link = rs_acct_site() . '/account/reset?t=' . $t;
    $brand = rs_acct_brand();
    rs_mailq_add((string)$a['email'], 'Set a new ' . $brand . ' password',
        "Hi " . ($a['username'] ?: 'there') . ",\n\nSet a new password for $brand here:\n$link\n\nThe link works once, for 30 minutes. If you didn't ask for this, ignore this email: your password stays as it is.",
        ['kind' => 'reset'], 1, true);
}

// ---- Two-step sign-in (an authenticator app) ---------------------------------------------------

function rs_acct_2fa_check(array $a, string $code): bool {
    $code = trim($code);
    if (preg_match('/^\d{6}$/', preg_replace('/\s/', '', $code) ?? '')) {
        $c = rs_totp_check((string)$a['totp_secret'], $code, (int)$a['totp_last']);
        if ($c) { rs_acct_update((int)$a['id'], ['totp_last' => $c]); return true; }
        return false;
    }
    // A backup code: each works once.
    $codes = json_decode((string)$a['backup_codes'], true) ?: [];
    $want = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    foreach ($codes as $i => $h) if ($want !== '' && password_verify($want, (string)$h)) {
        unset($codes[$i]);
        rs_acct_update((int)$a['id'], ['backup_codes' => json_encode(array_values($codes))]);
        return true;
    }
    return false;
}

function rs_acct_backup_codes(): array {
    $plain = [];
    for ($i = 0; $i < 8; $i++) $plain[] = substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5);
    return $plain;
}

// Signs in, or (with two-step on) asks for the code first. -> ['done' => true] or ['twoStep' => true]
function rs_acct_sign_in(array $a): array {
    if ($a['totp_on']) {
        $t = rs_acct_token_new((int)$a['id'], '2fa', 10);
        rs_acct_cookie('rs_acct_2fa', $t, 0, '/api/');
        return ['twoStep' => true];
    }
    rs_acct_session_start((int)$a['id']);
    return ['done' => true];
}

// ---- Signing in with Google, Discord or GitHub --------------------------------------------------

function rs_acct_oauth_url(string $p, string $state): string {
    $c = rs_acct_providers()[$p];
    $r = rs_acct_oauth_redirect();
    return match ($p) {
        'google' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $c['client'], 'redirect_uri' => $r, 'response_type' => 'code',
            'scope' => 'openid email', 'state' => $state, 'prompt' => 'select_account']),
        'discord' => 'https://discord.com/oauth2/authorize?' . http_build_query(['client_id' => $c['client'], 'redirect_uri' => $r, 'response_type' => 'code',
            'scope' => 'identify email', 'state' => $state, 'prompt' => 'none']),
        'github' => 'https://github.com/login/oauth/authorize?' . http_build_query(['client_id' => $c['client'], 'redirect_uri' => $r, 'scope' => 'user:email',
            'state' => $state, 'allow_signup' => 'true']),
    };
}

// The provider's answer as [id, email, email confirmed]. Throws with what went wrong.
function rs_acct_oauth_finish(string $p, string $code): array {
    $c = rs_acct_providers()[$p];
    $r = rs_acct_oauth_redirect();
    if ($p === 'google') {
        $t = rs_acct_http('POST', 'https://oauth2.googleapis.com/token', [], ['code' => $code, 'client_id' => $c['client'], 'client_secret' => $c['secret'], 'redirect_uri' => $r, 'grant_type' => 'authorization_code']);
        $tok = (string)($t['json']['access_token'] ?? '');
        if ($tok === '') throw new RuntimeException('Google didn\'t sign you in. Try again.');
        $u = rs_acct_http('GET', 'https://openidconnect.googleapis.com/v1/userinfo', ['Authorization: Bearer ' . $tok])['json'] ?? [];
        if (empty($u['sub'])) throw new RuntimeException('Google didn\'t say who you are. Try again.');
        return [(string)$u['sub'], (string)($u['email'] ?? ''), !empty($u['email_verified'])];
    }
    if ($p === 'discord') {
        $t = rs_acct_http('POST', 'https://discord.com/api/oauth2/token', [], ['code' => $code, 'client_id' => $c['client'], 'client_secret' => $c['secret'], 'redirect_uri' => $r, 'grant_type' => 'authorization_code']);
        $tok = (string)($t['json']['access_token'] ?? '');
        if ($tok === '') throw new RuntimeException('Discord didn\'t sign you in. Try again.');
        $u = rs_acct_http('GET', 'https://discord.com/api/users/@me', ['Authorization: Bearer ' . $tok])['json'] ?? [];
        if (empty($u['id'])) throw new RuntimeException('Discord didn\'t say who you are. Try again.');
        return [(string)$u['id'], (string)($u['email'] ?? ''), !empty($u['verified'])];
    }
    $t = rs_acct_http('POST', 'https://github.com/login/oauth/access_token', [], ['code' => $code, 'client_id' => $c['client'], 'client_secret' => $c['secret'], 'redirect_uri' => $r]);
    $tok = (string)($t['json']['access_token'] ?? '');
    if ($tok === '') throw new RuntimeException('GitHub didn\'t sign you in. Try again.');
    $h = ['Authorization: Bearer ' . $tok, 'Accept: application/vnd.github+json'];
    $u = rs_acct_http('GET', 'https://api.github.com/user', $h)['json'] ?? [];
    if (empty($u['id'])) throw new RuntimeException('GitHub didn\'t say who you are. Try again.');
    $email = ''; $ok = false;
    foreach ((rs_acct_http('GET', 'https://api.github.com/user/emails', $h)['json'] ?? []) as $e)
        if (!empty($e['primary'])) { $email = (string)($e['email'] ?? ''); $ok = !empty($e['verified']); }
    return [(string)$u['id'], $email, $ok];
}

// Finds or makes the account for a provider's sign-in, or adds the provider to $me.
// -> the account. Throws when the provider account is someone else's.
function rs_acct_oauth_account(string $p, string $pid, string $email, bool $emailOk, ?array $me): array {
    $col = ['google' => 'google_sub', 'discord' => 'discord_id', 'github' => 'github_id'][$p];
    $had = rs_acct_by($col, $pid);
    if ($me) {
        if ($had && (int)$had['id'] !== (int)$me['id']) throw new RuntimeException('That ' . ucfirst($p) . ' account is already used by another RecompStore account.');
        rs_acct_update((int)$me['id'], [$col => $pid]);
        return rs_acct_get((int)$me['id']);
    }
    if ($had) return $had;
    $lc = strtolower(trim($email));
    $valid = $emailOk && filter_var($lc, FILTER_VALIDATE_EMAIL) && strlen($lc) <= 190;
    // The same confirmed email as an account that confirmed it too: the same person.
    if ($valid) {
        $same = rs_acct_by('email_lc', $lc);
        if ($same && $same['email_ok']) { rs_acct_update((int)$same['id'], [$col => $pid]); return rs_acct_get((int)$same['id']); }
        if ($same) throw new RuntimeException('An account with that email is waiting to be confirmed. Confirm it from the email we sent, or sign in with its password.');
    }
    return rs_acct_create([$col => $pid, 'email' => $valid ? $lc : null, 'email_lc' => $valid ? $lc : null, 'email_ok' => $valid ? 1 : 0]);
}

// The signed state that goes to the provider and comes back: provider, what for, where to go after.
function rs_acct_state_make(string $p, string $mode, string $back): string {
    $body = rtrim(strtr(base64_encode(json_encode(['p' => $p, 'm' => $mode, 'b' => $back, 'n' => bin2hex(random_bytes(8)), 't' => time()])), '+/', '-_'), '=');
    $sig = substr(hash_hmac('sha256', $body, rs_mail_key()), 0, 32);
    rs_acct_cookie('rs_oauth', $sig, 0, '/api/');
    return $body . '.' . $sig;
}
function rs_acct_state_read(string $state): ?array {
    [$body, $sig] = array_pad(explode('.', $state, 2), 2, '');
    if ($body === '' || !hash_equals(substr(hash_hmac('sha256', $body, rs_mail_key()), 0, 32), $sig)) return null;
    if (!hash_equals((string)($_COOKIE['rs_oauth'] ?? ''), $sig)) return null;
    $j = json_decode((string)base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($j) || (int)($j['t'] ?? 0) < time() - 900) return null;
    return $j;
}

// Where to go after signing in: a page on one of the two sites, else the account page (a path is on
// this site).
function rs_acct_back(string $b): string {
    $u = parse_url($b);
    if (!$u) return rs_acct_site() . '/account';
    $host = preg_replace('/^www\./', '', strtolower((string)($u['host'] ?? '')));
    if ($host === '' && str_starts_with($b, '/') && !str_starts_with($b, '//')) return rs_acct_site() . $b;
    if (in_array($host, RS_ACCT_HOSTS, true) && ($u['scheme'] ?? '') === 'https') return $b;
    return rs_acct_site() . '/account';
}

// After signing in on recompstore.com, going on to recomplauncher.com signs in there too.
function rs_acct_after(int $id, string $back): string {
    $back = rs_acct_back($back);
    $host = preg_replace('/^www\./', '', strtolower((string)parse_url($back, PHP_URL_HOST)));
    if ($host !== '' && $host !== rs_acct_host() && in_array($host, RS_ACCT_HOSTS, true))
        return 'https://' . $host . '/api/account.php?' . http_build_query(['a' => 'handoff', 't' => rs_acct_token_new($id, 'handoff', 2), 'back' => $back]);
    return $back;
}

// ---- Saved games, deleting, exporting -------------------------------------------------------

function rs_acct_game_key(string $g): ?string {
    return preg_match('/^(pc:[a-z0-9-]{1,64}|ps5:[A-Za-z0-9_-]{1,32})$/', $g) ? $g : null;
}

function rs_acct_saved(array $a): array {
    $st = rs_db()->prepare('SELECT game, created FROM account_saved WHERE account_id = ? ORDER BY created DESC');
    $st->execute([(int)$a['id']]);
    return $st->fetchAll();
}

function rs_acct_delete(array $a): void {
    $db = rs_db();
    $id = (int)$a['id'];
    foreach (['account_sessions', 'account_tokens', 'account_saved'] as $t) $db->prepare("DELETE FROM $t WHERE account_id = ?")->execute([$id]);
    if (function_exists('rs_cm_tables')) { rs_cm_tables(); $db->prepare('DELETE FROM comment_votes WHERE account_id = ?')->execute([$id]); }
    if ($a['username_lc'] !== null)
        $db->prepare('INSERT INTO account_names_held (name_lc, until) VALUES (?, (UTC_TIMESTAMP() + INTERVAL 30 DAY)) ON DUPLICATE KEY UPDATE until = VALUES(until)')->execute([$a['username_lc']]);
    rs_acct_avatar_drop($a);
    // (3.1) RecompLauncher: its signed-in PCs and cloud saves go too.
    if (is_file(__DIR__ . '/rl_cloud.php')) { require_once __DIR__ . '/rl_cloud.php'; rs_rl_forget($a); }
    $db->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
    rs_acct_deleted_count();
    if (PHP_SAPI !== 'cli' && !headers_sent() && empty($GLOBALS['rs_admin_delete'])) rs_acct_cookie(RS_ACCT_COOKIE, '', 0);
}

// (4.8) Accounts deleted, counted by day (UTC) for the admin's Overview.
function rs_acct_deleted_count(int $n = 1): void {
    try {
        $db = rs_db();
        $db->exec('CREATE TABLE IF NOT EXISTS acct_deleted_days (day DATE PRIMARY KEY, n INT NOT NULL DEFAULT 0)');
        $db->prepare('INSERT INTO acct_deleted_days (day, n) VALUES (UTC_DATE(), ?) ON DUPLICATE KEY UPDATE n = n + VALUES(n)')->execute([$n]);
    } catch (Throwable $e) { /* counting is best-effort */ }
}

function rs_acct_export(array $a): array {
    $out = ['username' => $a['username'], 'email' => $a['email'], 'emailConfirmed' => (bool)$a['email_ok'], 'created' => $a['created'],
        'signIn' => array_keys(array_filter(['password' => $a['pass_hash'] !== null, 'google' => $a['google_sub'] !== null, 'discord' => $a['discord_id'] !== null, 'github' => $a['github_id'] !== null])),
        'twoStep' => (bool)$a['totp_on'], 'emails' => rs_acct_prefs($a), 'saved' => rs_acct_saved($a), 'sessions' => rs_acct_sessions($a)];
    if (function_exists('rs_cm_tables')) {
        rs_cm_tables();
        $st = rs_db()->prepare('SELECT game, body, created FROM comments WHERE account_id = ? ORDER BY created');
        $st->execute([(int)$a['id']]);
        $out['comments'] = $st->fetchAll();
    }
    if (is_file(__DIR__ . '/rl_cloud.php')) { require_once __DIR__ . '/rl_cloud.php'; $out += rs_rl_export($a); }
    return $out;
}

// ---- (3.0) Emails to players who ticked a box --------------------------------------------------
// Three lists, from each account's choices: weekly (new games), launcher (RecompLauncher updates),
// updates (a game they saved got a new version). Every email has a one-click unsubscribe link.

const RS_PMAIL_LISTS = ['weekly' => 'News and new games', 'launcher' => 'RecompLauncher updates', 'updates' => 'Updates to saved games', 'beta' => 'RecompLauncher beta list'];

function rs_pmail_tables(): void {
    static $done = false;
    if ($done) return;
    rs_acct_tables();
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS player_mail (
        id INT AUTO_INCREMENT PRIMARY KEY,
        list VARCHAR(12) NOT NULL,
        subject VARCHAR(200) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        created DATETIME NOT NULL,
        sent INT NOT NULL DEFAULT 0,
        failed INT NOT NULL DEFAULT 0
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS player_mail_sent (
        mail_id INT NOT NULL,
        account_id INT NOT NULL,
        ok TINYINT NOT NULL DEFAULT 1,
        PRIMARY KEY (mail_id, account_id)
    ) CHARACTER SET utf8mb4');
    if (rs_col_missing($db, 'account_saved', 'seen_version'))
        $db->exec("ALTER TABLE account_saved ADD COLUMN seen_version VARCHAR(40) NOT NULL DEFAULT ''");
    $done = true;
}

function rs_pmail_sig(string $uid, string $list): string { return substr(hash_hmac('sha256', "unsub|$uid|$list", rs_mail_key()), 0, 24); }
function rs_pmail_unsub_url(array $a, string $list): string {
    return 'https://recompstore.com/api/account.php?' . http_build_query(['a' => 'unsub', 'u' => $a['uid'], 'l' => $list, 's' => rs_pmail_sig((string)$a['uid'], $list)]);
}
function rs_pmail_footer(array $a, string $list): string {
    $why = ['weekly' => 'you asked for the weekly new-games email', 'launcher' => 'you asked for RecompLauncher update emails', 'updates' => 'you asked to hear when a game you saved gets an update'][$list] ?? 'you asked for it';
    return "You're getting this because $why on your RecompStore account.\nUnsubscribe with one click: " . rs_pmail_unsub_url($a, $list) . "\nChange your email choices: https://recompstore.com/account";
}

// Everyone on a list: a confirmed email, not closed, the box ticked.
function rs_pmail_audience(string $list): array {
    rs_pmail_tables();
    $out = [];
    foreach (rs_db()->query('SELECT * FROM accounts WHERE email_ok = 1 AND banned = 0 AND email IS NOT NULL AND prefs IS NOT NULL')->fetchAll() as $a)
        if (!empty(rs_acct_prefs($a)[$list])) $out[(int)$a['id']] = $a;
    return $out;
}

// Sends the next $n of an email's list. -> [sent now, still to go]
function rs_pmail_send_batch(int $mailId, int $n = 40): array {
    rs_pmail_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM player_mail WHERE id = ?');
    $st->execute([$mailId]);
    $m = $st->fetch();
    if (!$m) throw new RuntimeException('That email is gone.');
    $done = [];
    $d = $db->prepare('SELECT account_id FROM player_mail_sent WHERE mail_id = ?');
    $d->execute([$mailId]);
    foreach ($d->fetchAll() as $r) $done[(int)$r['account_id']] = true;
    $todo = array_values(array_filter(rs_pmail_audience((string)$m['list']), fn($a) => !isset($done[(int)$a['id']])));
    $batch = array_slice($todo, 0, $n);
    if (!$batch) return [0, 0];
    $msgs = [];
    foreach ($batch as $a) {
        $text = str_replace('{username}', (string)($a['username'] ?? 'there'), (string)$m['body']);
        $msgs[] = [(string)$a['email'], rs_mail_build((string)$a['email'], (string)$m['subject'], $text, ['footer' => rs_pmail_footer($a, (string)$m['list']), 'unsub' => rs_pmail_unsub_url($a, (string)$m['list'])])['data']];
    }
    $errs = rs_smtp_send($msgs);
    $ok = 0;
    $ins = $db->prepare('INSERT IGNORE INTO player_mail_sent (mail_id, account_id, ok) VALUES (?, ?, ?)');
    foreach ($batch as $i => $a) { $good = ($errs[$i] ?? '') === ''; $ok += $good ? 1 : 0; $ins->execute([$mailId, (int)$a['id'], $good ? 1 : 0]); }
    $db->prepare('UPDATE player_mail SET sent = sent + ?, failed = failed + ? WHERE id = ?')->execute([$ok, count($batch) - $ok, $mailId]);
    return [$ok, count($todo) - count($batch)];
}

// A first draft of the weekly email: what went live or got updated in the last 7 days.
function rs_pmail_weekly_draft(): array {
    $since = time() - 7 * 86400;
    $ps5 = []; $pc = [];
    foreach (rs_games(false) as $g) {
        $t = strtotime((string)($g['released'] ?? '') . ' UTC');
        if ($t && $t >= $since && empty($g['soon'])) $ps5[] = '- ' . $g['name'] . ($g['version'] !== '' ? ' ' . $g['version'] : '') . ': https://recompstore.com/game/' . rawurlencode((string)$g['id']);
    }
    foreach (rs_pc_apps(false) as $a) {
        $t = strtotime((string)($a['live_at'] ?? ''));
        if ($t && $t >= $since && empty($a['soon'])) $pc[] = '- ' . $a['name'] . ($a['game'] !== '' && $a['game'] !== $a['name'] ? ' (' . $a['game'] . ')' : '') . ': https://recomplauncher.com/game/' . rawurlencode((string)$a['id']);
    }
    $body = "Hi {username},\n\nHere's what's new this week.\n";
    if ($ps5) $body .= "\nOn PS5 (RecompStore):\n" . implode("\n", $ps5) . "\n";
    if ($pc) $body .= "\nOn PC (RecompLauncher):\n" . implode("\n", $pc) . "\n";
    if (!$ps5 && !$pc) $body .= "\n(Nothing new went up in the last 7 days. Write a few lines here, or skip this week.)\n";
    $body .= "\nHave fun, and keep sending logs when something breaks.\n";
    return ['subject' => 'New on RecompStore this week', 'body' => $body];
}

// The version a saved game is at now ('' when it's gone).
function rs_pmail_version_of(string $game): string {
    [$k, $id] = explode(':', $game, 2);
    if ($k === 'pc') { $a = rs_pc_app($id); return $a && !$a['hidden'] ? (string)$a['version'] : ''; }
    $g = rs_game($id);
    return $g && empty($g['hidden']) ? (string)$g['version'] : '';
}
function rs_pmail_name_of(string $game): array {
    [$k, $id] = explode(':', $game, 2);
    if ($k === 'pc') { $a = rs_pc_app($id); return [$a ? (string)$a['name'] : $id, 'https://recomplauncher.com/game/' . rawurlencode($id), 'PC']; }
    $g = rs_game($id);
    return [$g ? (string)$g['name'] : $id, 'https://recompstore.com/game/' . rawurlencode($id), 'PS5'];
}

// Saved games with a newer version than when they were saved (or last emailed about), per person.
// -> [account id => ['account' => row, 'games' => [[game, version], ...]]]
function rs_pmail_updates_due(): array {
    rs_pmail_tables();
    $aud = rs_pmail_audience('updates');
    if (!$aud) return [];
    $rows = rs_db()->query('SELECT account_id, game, seen_version FROM account_saved WHERE account_id IN (' . implode(',', array_keys($aud)) . ')')->fetchAll();
    $now = [];
    $out = [];
    foreach ($rows as $r) {
        $g = (string)$r['game'];
        $now[$g] ??= rs_pmail_version_of($g);
        if ($now[$g] === '' || (string)$r['seen_version'] === '' || $now[$g] === (string)$r['seen_version']) continue;
        $id = (int)$r['account_id'];
        $out[$id] ??= ['account' => $aud[$id], 'games' => []];
        $out[$id]['games'][] = [$g, $now[$g]];
    }
    return $out;
}

// Emails the next $n people their saved-game updates. -> [sent, still to go]
function rs_pmail_updates_send(int $n = 40): array {
    $due = rs_pmail_updates_due();
    $batch = array_slice($due, 0, $n, true);
    if (!$batch) return [0, 0];
    $msgs = [];
    foreach ($batch as $x) {
        $a = $x['account'];
        $lines = array_map(function ($gv) { [$name, $url, $pf] = rs_pmail_name_of($gv[0]); return "- $name ($pf) is now on version {$gv[1]}: $url"; }, $x['games']);
        $text = 'Hi ' . ($a['username'] ?? 'there') . ",\n\n" . (count($lines) === 1 ? 'A game you saved got an update:' : 'Games you saved got updates:') . "\n" . implode("\n", $lines) . "\n\nOn PS5, RecompStore updates it for you. Your game files and saves stay where they are.\n";
        $msgs[] = [(string)$a['email'], rs_mail_build((string)$a['email'], count($lines) === 1 ? 'A game you saved got an update' : 'Games you saved got updates', $text, ['footer' => rs_pmail_footer($a, 'updates'), 'unsub' => rs_pmail_unsub_url($a, 'updates')])['data']];
    }
    $errs = rs_smtp_send($msgs);
    $up = rs_db()->prepare('UPDATE account_saved SET seen_version = ? WHERE account_id = ? AND game = ?');
    $ok = 0; $i = 0;
    foreach ($batch as $id => $x) {
        if (($errs[$i++] ?? '') === '') { $ok++; foreach ($x['games'] as $gv) $up->execute([$gv[1], $id, $gv[0]]); }
    }
    return [$ok, count($due) - count($batch)];
}


// ---- (3.0) Profile pictures: a small square, 128 pixels, kept in /media/avatars ----------------

function rs_acct_avatar_url(array $a): string {
    $f = (string)($a['avatar'] ?? '');
    return $f !== '' ? '/media/avatars/' . rawurlencode($f) : '';
}

function rs_acct_avatar_save(array $file, array $a): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The picture didn\'t arrive. Try again.');
    if ((int)$file['size'] > 5 * 1024 * 1024) throw new RuntimeException('Use a picture under 5 MB.');
    [$src, $w, $h] = rs_image_open($file);
    $side = min($w, $h);
    $dst = imagecreatetruecolor(128, 128);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 20, 31, 51));
    imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), 128, 128, $side, $side);
    $dir = RS_MEDIA . '/avatars';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = $a['uid'] . '-' . bin2hex(random_bytes(4)) . '.jpg';
    if (!imagejpeg($dst, $dir . '/' . $name, 86)) throw new RuntimeException('The picture couldn\'t be saved. Try again.');
    rs_acct_avatar_drop($a);
    rs_acct_update((int)$a['id'], ['avatar' => $name]);
    return $name;
}

function rs_acct_avatar_drop(array $a): void {
    $f = basename((string)($a['avatar'] ?? ''));
    if ($f !== '' && preg_match('/^[0-9a-f]{16}-[0-9a-f]{8}\.jpg$/', $f)) @unlink(RS_MEDIA . '/avatars/' . $f);
    rs_acct_update((int)$a['id'], ['avatar' => null]);
}

// ---- (3.0) RecompLauncher beta: an email list, each address confirmed by a link --------------

function rs_lbeta_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS launcher_beta (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL UNIQUE,
        account_id INT NULL,
        confirmed TINYINT NOT NULL DEFAULT 0,
        off TINYINT NOT NULL DEFAULT 0,
        token CHAR(64) NOT NULL DEFAULT \'\',
        ip_hash CHAR(64) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        confirmed_at DATETIME NULL
    ) CHARACTER SET utf8mb4');
    // (3.1) Everyone who was still waiting for the confirmation email is on the list now.
    if (rs_setting('lbeta_no_confirm', '') !== '1') {
        rs_db()->exec('UPDATE launcher_beta SET confirmed = 1, confirmed_at = UTC_TIMESTAMP(), token = \'\' WHERE confirmed = 0 AND off = 0');
        rs_set_setting('lbeta_no_confirm', '1');
    }
    $done = true;
}
function rs_lbeta_on(): bool { return rs_setting('launcher_beta_on', '1') === '1'; }
function rs_lbeta_sig(int $id): string { return substr(hash_hmac('sha256', "beta-off|$id", rs_mail_key()), 0, 24); }
function rs_lbeta_off_url(int $id): string { return 'https://recomplauncher.com/api/launcher-beta.php?' . http_build_query(['a' => 'off', 'b' => $id, 's' => rs_lbeta_sig($id)]); }

// Puts an address on the list (or sends its link again). -> 'sent' | 'there'
function rs_lbeta_join(string $email, ?array $acc): string {
    rs_lbeta_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM launcher_beta WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row && $row['confirmed'] && !$row['off']) return 'there';
    $raw = bin2hex(random_bytes(24));
    if ($row) $db->prepare('UPDATE launcher_beta SET token = ?, off = 0, account_id = COALESCE(account_id, ?) WHERE id = ?')->execute([hash('sha256', $raw), $acc ? (int)$acc['id'] : null, (int)$row['id']]);
    else $db->prepare('INSERT INTO launcher_beta (email, account_id, token, ip_hash, created) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')->execute([$email, $acc ? (int)$acc['id'] : null, hash('sha256', $raw), rs_ip_hash()]);
    // Signed in with this same confirmed email: no link needed.
    if ($acc && $acc['email_ok'] && strtolower((string)$acc['email']) === $email) {
        $db->prepare('UPDATE launcher_beta SET confirmed = 1, confirmed_at = UTC_TIMESTAMP(), token = \'\' WHERE email = ?')->execute([$email]);
        return 'there';
    }
    // (3.1) No confirmation email any more (it ran the mailbox out on day one): on the list straight
    // away. The one "the beta is ready" email has the unsubscribe link.
    $db->prepare('UPDATE launcher_beta SET confirmed = 1, confirmed_at = UTC_TIMESTAMP(), token = \'\' WHERE email = ?')->execute([$email]);
    return 'there';
}

function rs_lbeta_audience(): array {
    rs_lbeta_tables();
    return rs_db()->query('SELECT * FROM launcher_beta WHERE confirmed = 1 AND off = 0 ORDER BY id')->fetchAll();
}

// Sends the next $n of a beta-list email. -> [sent, still to go]
function rs_lbeta_send_batch(int $mailId, int $n = 40): array {
    rs_pmail_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM player_mail WHERE id = ? AND list = \'beta\'');
    $st->execute([$mailId]);
    $m = $st->fetch();
    if (!$m) throw new RuntimeException('That email is gone.');
    $done = [];
    $d = $db->prepare('SELECT account_id FROM player_mail_sent WHERE mail_id = ?');
    $d->execute([$mailId]);
    foreach ($d->fetchAll() as $r) $done[-(int)$r['account_id']] = true;
    // (3.1g) Only the earliest N when the email was sent to "the first N".
    $aud = rs_lbeta_audience();
    $cap = (int)rs_setting('pm_cap_' . $mailId, '0');
    if ($cap > 0) $aud = array_slice($aud, 0, $cap);
    $todo = array_values(array_filter($aud, fn($b) => !isset($done[(int)$b['id']])));
    $batch = array_slice($todo, 0, $n);
    if (!$batch) return [0, 0];
    $msgs = [];
    foreach ($batch as $b) {
        $off = rs_lbeta_off_url((int)$b['id']);
        $msgs[] = [(string)$b['email'], rs_mail_build((string)$b['email'], (string)$m['subject'], str_replace('{username}', 'there', (string)$m['body']),
            ['footer' => "You're getting this because you signed up for the RecompLauncher beta.\nLeave the list with one click: $off", 'unsub' => $off])['data']];
    }
    $errs = rs_smtp_send($msgs);
    $ok = 0;
    $ins = $db->prepare('INSERT IGNORE INTO player_mail_sent (mail_id, account_id, ok) VALUES (?, ?, ?)');
    foreach ($batch as $i => $b) { $good = ($errs[$i] ?? '') === ''; $ok += $good ? 1 : 0; $ins->execute([$mailId, -(int)$b['id'], $good ? 1 : 0]); }
    $db->prepare('UPDATE player_mail SET sent = sent + ?, failed = failed + ? WHERE id = ?')->execute([$ok, count($batch) - $ok, $mailId]);
    return [$ok, count($todo) - count($batch)];
}
