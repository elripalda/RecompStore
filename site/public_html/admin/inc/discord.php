<?php
// RecompStore (2.7.1): beta codes through Discord. On recompstore.com/beta a tester signs in with
// Discord; if they're in the RecompStore Discord server (or the site's bot adds them to it), they
// get a code for a beta that's open, made for them on the spot, one per Discord account per game.
// The Discord app's secret and the bot token are kept encrypted like the mailbox password.
declare(strict_types=1);

// Discord's API (RS_DC_API in the environment points it at a stand-in when testing).
define('RS_DC_API', (string)(getenv('RS_DC_API') ?: 'https://discord.com/api/v10'));
const RS_DC_COOKIE = 'rs_dc';
const RS_DC_HOURS = 2; // a sign-in counts this long; then Discord is asked again (one click)

function rs_secret_put(string $k, string $v): void {
    if ($v === '') { rs_set_setting($k, ''); return; }
    $iv = random_bytes(12);
    $tag = '';
    $box = openssl_encrypt($v, 'aes-256-gcm', rs_mail_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($box === false) throw new RuntimeException('That couldn\'t be locked away on this server.');
    rs_set_setting($k, base64_encode($iv . $tag . $box));
}

function rs_secret_get(string $k): string {
    $raw = base64_decode((string)rs_setting($k, ''), true);
    if (!$raw || strlen($raw) <= 28) return '';
    $v = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', rs_mail_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $v === false ? '' : $v;
}

function rs_dc_tables(): void {
    static $done = false;
    if ($done) return;
    rs_beta_tables();
    rs_db()->exec('CREATE TABLE IF NOT EXISTS beta_discord (
        discord_id VARCHAR(24) NOT NULL,
        game_id VARCHAR(32) NOT NULL,
        username VARCHAR(64) NOT NULL DEFAULT \'\',
        code CHAR(8) NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY (discord_id, game_id), INDEX (game_id)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_dc_conf(): array {
    return [
        'client' => trim((string)rs_setting('dc_client_id', '')),
        'secret' => rs_secret_get('dc_client_secret'),
        'guild' => trim((string)rs_setting('dc_guild_id', '')),
        'bot' => rs_secret_get('dc_bot_token'),
        'give_role' => trim((string)rs_setting('dc_give_role', '')),
        'need_role' => trim((string)rs_setting('dc_need_role', '')),
        'invite' => trim((string)rs_setting('discord_url', '')),
    ];
}

function rs_dc_ready(): bool {
    $c = rs_dc_conf();
    return $c['client'] !== '' && $c['secret'] !== '' && $c['guild'] !== '';
}

function rs_dc_redirect(): string {
    return rs_abs('/api/discord.php');
}

// ---- Talking to Discord ---------------------------------------------------------------------

// -> [status, decoded JSON (or null), raw body]
function rs_dc_call(string $method, string $path, array $o = []): array {
    $c = curl_init(str_starts_with($path, 'https://') ? $path : RS_DC_API . $path);
    $headers = ['User-Agent: RecompStore (https://recompstore.com, 2.7)'];
    if (!empty($o['bearer'])) $headers[] = 'Authorization: Bearer ' . $o['bearer'];
    if (!empty($o['bot'])) $headers[] = 'Authorization: Bot ' . $o['bot'];
    $body = null;
    if (isset($o['form'])) { $body = http_build_query($o['form']); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    elseif (isset($o['json'])) { $body = json_encode($o['json']); $headers[] = 'Content-Type: application/json'; }
    elseif (in_array($method, ['PUT', 'POST'], true)) { $body = ''; $headers[] = 'Content-Length: 0'; }
    curl_setopt_array($c, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15]);
    if ($body !== null) curl_setopt($c, CURLOPT_POSTFIELDS, $body);
    $ca = getenv('RS_CA_BUNDLE');
    if (is_string($ca) && $ca !== '' && is_file($ca)) curl_setopt($c, CURLOPT_CAINFO, $ca);
    $raw = curl_exec($c);
    $status = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    $raw = is_string($raw) ? $raw : '';
    return [$status, json_decode($raw, true), $raw];
}

// The address that sends someone to Discord to sign in. $state comes back with them.
function rs_dc_login_url(string $state): string {
    $c = rs_dc_conf();
    $scopes = ['identify', 'guilds.members.read'];
    if ($c['bot'] !== '') $scopes[] = 'guilds.join';
    return 'https://discord.com/oauth2/authorize?' . http_build_query([
        'client_id' => $c['client'], 'response_type' => 'code', 'redirect_uri' => rs_dc_redirect(),
        'scope' => implode(' ', $scopes), 'state' => $state, 'prompt' => 'none',
    ]);
}

// Back from Discord with ?code=: who they are, and whether they're in the server (joining them
// to it when the bot can). -> ['id', 'name', 'avatar', 'member' (bool), 'roles' []]
function rs_dc_finish(string $code): array {
    $c = rs_dc_conf();
    [$st, $tok] = rs_dc_call('POST', '/oauth2/token', ['form' => [
        'client_id' => $c['client'], 'client_secret' => $c['secret'], 'grant_type' => 'authorization_code',
        'code' => $code, 'redirect_uri' => rs_dc_redirect(),
    ]]);
    $access = is_array($tok) ? (string)($tok['access_token'] ?? '') : '';
    if ($st !== 200 || $access === '') throw new RuntimeException('Discord didn\'t sign you in. Try again.');
    [$st, $me] = rs_dc_call('GET', '/users/@me', ['bearer' => $access]);
    if ($st !== 200 || !is_array($me) || empty($me['id'])) throw new RuntimeException('Discord didn\'t say who you are. Try again.');
    $user = ['id' => (string)$me['id'], 'name' => (string)($me['global_name'] ?? '') ?: (string)($me['username'] ?? ''),
        'handle' => (string)($me['username'] ?? ''), 'avatar' => (string)($me['avatar'] ?? ''), 'member' => false, 'roles' => []];
    [$st, $mem] = rs_dc_call('GET', '/users/@me/guilds/' . rawurlencode($c['guild']) . '/member', ['bearer' => $access]);
    if ($st === 200 && is_array($mem)) {
        $user['member'] = true;
        $user['roles'] = array_values(array_map('strval', (array)($mem['roles'] ?? [])));
    } elseif ($c['bot'] !== '') {
        // Not in the server yet: the site's bot adds them (that's what guilds.join was asked for).
        [$st] = rs_dc_call('PUT', '/guilds/' . rawurlencode($c['guild']) . '/members/' . rawurlencode($user['id']),
            ['bot' => $c['bot'], 'json' => ['access_token' => $access]]);
        if ($st === 201 || $st === 204) { $user['member'] = true; $user['joined'] = true; }
    }
    return $user;
}

// A tester role on Discord, when the bot can give it. Best effort.
function rs_dc_give_role(string $userId): void {
    $c = rs_dc_conf();
    if ($c['bot'] === '' || $c['give_role'] === '') return;
    rs_dc_call('PUT', '/guilds/' . rawurlencode($c['guild']) . '/members/' . rawurlencode($userId) . '/roles/' . rawurlencode($c['give_role']), ['bot' => $c['bot']]);
}

// ---- Who's signed in on this browser: a signed cookie, for RS_DC_HOURS -------------------------

function rs_dc_sig(string $data): string {
    return hash_hmac('sha256', 'dc|' . $data, rs_beta_secret());
}

function rs_dc_set(array $user): void {
    $user['at'] = time();
    $data = rtrim(strtr(base64_encode(json_encode($user)), '+/', '-_'), '=');
    setcookie(RS_DC_COOKIE, $data . '.' . rs_dc_sig($data), ['expires' => time() + RS_DC_HOURS * 3600, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

function rs_dc_user(): ?array {
    $c = (string)($_COOKIE[RS_DC_COOKIE] ?? '');
    if (!preg_match('/^([A-Za-z0-9_-]+)\.([0-9a-f]{64})$/', $c, $m) || !hash_equals(rs_dc_sig($m[1]), $m[2])) return null;
    $u = json_decode((string)base64_decode(strtr($m[1], '-_', '+/')), true);
    if (!is_array($u) || empty($u['id']) || (int)($u['at'] ?? 0) < time() - RS_DC_HOURS * 3600) return null;
    return $u;
}

function rs_dc_forget(): void {
    setcookie(RS_DC_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
}

// ---- Betas on the public page ---------------------------------------------------------------

// Can this signed-in tester get codes? '' yes, else why not.
function rs_dc_allowed(array $u): string {
    $c = rs_dc_conf();
    if (empty($u['member'])) return 'join';
    if ($c['need_role'] !== '' && !in_array($c['need_role'], (array)($u['roles'] ?? []), true)) return 'role';
    return '';
}

function rs_dc_taken(string $gameId): int {
    rs_dc_tables();
    $st = rs_db()->prepare('SELECT COUNT(*) FROM beta_discord WHERE game_id = ?');
    $st->execute([$gameId]);
    return (int)$st->fetchColumn();
}

// The betas shown on recompstore.com/beta, for this tester (or nobody signed in).
function rs_dc_betas(?array $u): array {
    rs_dc_tables();
    $mine = [];
    if ($u) {
        $st = rs_db()->prepare('SELECT game_id, code FROM beta_discord WHERE discord_id = ?');
        $st->execute([(string)$u['id']]);
        $mine = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    $out = [];
    foreach (rs_games() as $g) {
        if (!rs_has_beta($g) || empty($g['beta_public'])) continue;
        $limit = max(0, (int)($g['beta_limit'] ?? 0));
        $taken = rs_dc_taken($g['id']);
        $status = $g['beta_file'] === '' ? 'soon' : (empty($g['beta_discord']) ? 'closed' : ($limit > 0 && $taken >= $limit ? 'full' : 'open'));
        $out[] = [
            'id' => $g['id'], 'name' => $g['name'], 'icon' => $g['icon'], 'hero' => $g['hero_web'] ?: $g['hero'],
            'blurb' => $g['blurb'] !== '' ? $g['blurb'] : mb_substr((string)$g['description'], 0, 200),
            'version' => $g['beta_version'], 'category' => $g['category'], 'status' => $status,
            'left' => $limit > 0 && $status !== 'soon' ? max(0, $limit - $taken) : null,
            'notes' => $status === 'soon' ? '' : mb_substr((string)$g['beta_notes'], 0, 600),
            'code' => isset($mine[$g['id']]) ? rs_beta_format((string)$mine[$g['id']]) : null,
        ];
    }
    // Open ones first, then full or by hand, then on the way.
    $rank = ['open' => 0, 'full' => 1, 'closed' => 2, 'soon' => 3];
    usort($out, fn($a, $b) => $rank[$a['status']] <=> $rank[$b['status']]);
    return $out;
}

// A code for this tester and game: theirs if they have one, else a new one. -> formatted code
function rs_dc_claim(array $u, string $gameId): string {
    rs_dc_tables();
    $db = rs_db();
    $why = rs_dc_allowed($u);
    if ($why === 'join') throw new RuntimeException('Join the RecompStore Discord first, then sign in again.');
    if ($why === 'role') throw new RuntimeException('Codes are for testers with the beta role on Discord. Ask in the server.');
    $g = rs_game($gameId);
    if (!$g || !rs_has_beta($g) || empty($g['beta_public'])) throw new RuntimeException('That beta isn\'t here anymore.');
    $st = $db->prepare('SELECT code FROM beta_discord WHERE discord_id = ? AND game_id = ?');
    $st->execute([(string)$u['id'], $gameId]);
    if (($have = $st->fetchColumn()) !== false) return rs_beta_format((string)$have);
    if ($g['beta_file'] === '') throw new RuntimeException('This beta hasn\'t started yet.');
    if (empty($g['beta_discord'])) throw new RuntimeException('Codes for this beta are handed out on Discord.');
    if (!$db->query("SELECT GET_LOCK('rs_dc_claim', 5)")->fetchColumn()) throw new RuntimeException('Busy. Try again in a moment.');
    try {
        $limit = max(0, (int)($g['beta_limit'] ?? 0));
        if ($limit > 0 && rs_dc_taken($gameId) >= $limit) throw new RuntimeException('All the spots are taken. Watch the Discord for more.');
        $who = (string)($u['handle'] ?? '') !== '' ? '@' . $u['handle'] : (string)$u['name'];
        $made = rs_beta_make_codes($gameId, 1, mb_substr('Discord ' . $who, 0, 120));
        if (!$made) throw new RuntimeException('No code could be made. Try again.');
        $db->prepare('INSERT INTO beta_discord (discord_id, game_id, username, code, created) VALUES (?, ?, ?, ?, NOW())')
           ->execute([(string)$u['id'], $gameId, mb_substr($who, 0, 64), $made[0]]);
    } finally {
        $db->query("SELECT RELEASE_LOCK('rs_dc_claim')");
    }
    rs_dc_give_role((string)$u['id']);
    rs_log('beta', $gameId, 'Discord code for ' . $who);
    return rs_beta_format($made[0]);
}
