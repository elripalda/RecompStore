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

// (4.1) Back to the site they came from: a RecompLauncher developer signs in on recomplauncher.com, and
// that site's cookies (the hub sign-in, this sign-in's state) aren't on recompstore.com. Both addresses
// are in the Discord app's Redirects.
function rs_dc_redirect(): string {
    if (preg_match('/(^|\.)recomplauncher\.com(:\d+)?$/i', (string)($_SERVER['HTTP_HOST'] ?? ''))) return 'https://recomplauncher.com/api/discord.php';
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

// ---- (4.0) The Discord server: a Developer role in its own color, shown apart in the member list,
// and developer-only channels. Done by the site's bot (Beta page: bot token, server ID), which needs
// Manage Roles and Manage Channels. Each step looks first, so pressing Set up again changes nothing.
const RS_DC_DEV_COLOR = 0x34F2E8;
const RS_DC_VIEW = 1024; const RS_DC_SEND = 2048; const RS_DC_HISTORY = 65536;

// The link that adds the site's bot to the server with what it needs (and nothing more).
function rs_dc_bot_invite(): string {
    $c = rs_dc_conf();
    if ($c['client'] === '') return '';
    // View channels, send messages, read history, manage channels, manage roles.
    $perm = RS_DC_VIEW | RS_DC_SEND | RS_DC_HISTORY | 16 | 268435456;
    return 'https://discord.com/oauth2/authorize?' . http_build_query(['client_id' => $c['client'], 'scope' => 'bot', 'permissions' => (string)$perm, 'guild_id' => $c['guild'], 'disable_guild_select' => 'true']);
}

// What's there now: [ready, why, role id, channel ids].
function rs_dc_server_state(): array {
    $c = rs_dc_conf();
    $out = ['ready' => false, 'why' => '', 'role' => trim((string)rs_setting('dc_dev_role', '')), 'chat' => trim((string)rs_setting('dc_dev_chat', '')), 'news' => trim((string)rs_setting('dc_dev_news', ''))];
    if ($c['guild'] === '') { $out['why'] = 'No server ID yet (Beta page).'; return $out; }
    if ($c['bot'] === '') { $out['why'] = 'No bot token yet (Beta page).'; return $out; }
    [$st] = rs_dc_call('GET', '/guilds/' . rawurlencode($c['guild']) . '/roles', ['bot' => $c['bot']]);
    if ($st === 401) { $out['why'] = 'Discord says the bot token isn\'t right.'; return $out; }
    if ($st === 403 || $st === 404) { $out['why'] = 'The bot isn\'t in the server yet. Add it with the link below.'; return $out; }
    if ($st !== 200) { $out['why'] = 'Discord didn\'t answer (' . $st . '). Try again in a minute.'; return $out; }
    $out['ready'] = true;
    return $out;
}

// Makes (or finds) the Developer role and the Developers channels. -> what it did, line by line.
function rs_dc_server_setup(): array {
    $c = rs_dc_conf();
    $g = rawurlencode($c['guild']);
    $bot = ['bot' => $c['bot']];
    $done = [];
    // The bot's own user: it keeps seeing the private channels it makes.
    [$st, $me] = rs_dc_call('GET', '/users/@me', $bot);
    if ($st !== 200 || empty($me['id'])) throw new RuntimeException('Discord didn\'t say who the bot is (' . $st . ').');
    $botId = (string)$me['id'];
    // 1. The role.
    [$st, $roles] = rs_dc_call('GET', '/guilds/' . $g . '/roles', $bot);
    if ($st !== 200) throw new RuntimeException('Couldn\'t read the server\'s roles (' . $st . '). Is the bot in the server?');
    $roleId = trim((string)rs_setting('dc_dev_role', ''));
    $have = null;
    foreach ((array)$roles as $r) if ((string)$r['id'] === $roleId || ($roleId === '' && strcasecmp((string)$r['name'], 'Developer') === 0)) $have = $r;
    if ($have) {
        $roleId = (string)$have['id'];
        rs_dc_call('PATCH', '/guilds/' . $g . '/roles/' . $roleId, $bot + ['json' => ['color' => RS_DC_DEV_COLOR, 'hoist' => true, 'mentionable' => true]]);
        $done[] = 'The Developer role was there already: its color and its own spot in the member list are set.';
    } else {
        [$st, $r] = rs_dc_call('POST', '/guilds/' . $g . '/roles', $bot + ['json' => ['name' => 'Developer', 'color' => RS_DC_DEV_COLOR, 'hoist' => true, 'mentionable' => true, 'permissions' => '0']]);
        if ($st !== 200 || empty($r['id'])) throw new RuntimeException('Couldn\'t make the Developer role (' . $st . '). The bot needs Manage Roles.');
        $roleId = (string)$r['id'];
        $done[] = 'Made the Developer role, in RecompStore cyan, shown apart in the member list.';
    }
    rs_set_setting('dc_dev_role', $roleId);
    // 2. The channels: a Developers category only developers (and the bot) can see.
    [$st, $chans] = rs_dc_call('GET', '/guilds/' . $g . '/channels', $bot);
    if ($st !== 200) throw new RuntimeException('Couldn\'t read the server\'s channels (' . $st . ').');
    $find = function (string $id, string $name, int $type, string $parent = '') use ($chans) {
        foreach ((array)$chans as $ch) if ((string)$ch['id'] === $id) return $ch;
        foreach ((array)$chans as $ch) if ((int)$ch['type'] === $type && strcasecmp((string)$ch['name'], $name) === 0 && ($parent === '' || (string)($ch['parent_id'] ?? '') === $parent)) return $ch;
        return null;
    };
    $private = fn(int $devAllow, int $devDeny = 0) => [
        ['id' => $c['guild'], 'type' => 0, 'deny' => (string)RS_DC_VIEW, 'allow' => '0'],                       // everyone: can't see it
        ['id' => $roleId, 'type' => 0, 'allow' => (string)$devAllow, 'deny' => (string)$devDeny],                // developers
        ['id' => $botId, 'type' => 1, 'allow' => (string)(RS_DC_VIEW | RS_DC_SEND | RS_DC_HISTORY), 'deny' => '0']]; // the bot
    $mk = function (string $key, string $name, int $type, array $over, string $parent, string $topic) use ($find, $g, $bot, &$done) {
        $ch = $find(trim((string)rs_setting($key, '')), $name, $type, $parent);
        if ($ch) { $done[] = '#' . $name . ' was there already.'; rs_set_setting($key, (string)$ch['id']); return (string)$ch['id']; }
        $body = ['name' => $name, 'type' => $type, 'permission_overwrites' => $over];
        if ($parent !== '') $body['parent_id'] = $parent;
        if ($topic !== '') $body['topic'] = $topic;
        [$st, $x] = rs_dc_call('POST', '/guilds/' . $g . '/channels', $bot + ['json' => $body]);
        if ($st !== 201 && $st !== 200 || empty($x['id'])) throw new RuntimeException('Couldn\'t make ' . $name . ' (' . $st . '). The bot needs Manage Channels.');
        rs_set_setting($key, (string)$x['id']);
        $done[] = 'Made ' . ($type === 4 ? 'the ' . $name . ' category' : '#' . $name) . '.';
        return (string)$x['id'];
    };
    $all = RS_DC_VIEW | RS_DC_SEND | RS_DC_HISTORY;
    $cat = $mk('dc_dev_category', 'Developers', 4, $private($all), '', '');
    $mk('dc_dev_chat', 'dev-chat', 0, $private($all), $cat, 'For RecompStore and RecompLauncher developers: ask, share, plan.');
    $mk('dc_dev_news', 'dev-announcements', 0, $private(RS_DC_VIEW | RS_DC_HISTORY, RS_DC_SEND), $cat, 'News for developers from the RecompStore team.');
    rs_log('discord', 'setup', implode(' ', $done));
    return $done;
}

// Gives the Developer role to every approved developer who linked Discord. -> [given, without Discord]
function rs_dc_sync_devs(): array {
    $c = rs_dc_conf();
    $role = trim((string)rs_setting('dc_dev_role', ''));
    if ($c['bot'] === '' || $c['guild'] === '' || $role === '') throw new RuntimeException('Set up the server first.');
    rs_dev_tables();
    $given = 0; $none = 0;
    foreach (rs_db()->query("SELECT * FROM dev_accounts WHERE status = 'approved'")->fetchAll() as $acc) {
        if ((string)$acc['discord_id'] === '') { $none++; continue; }
        [$st] = rs_dc_call('PUT', '/guilds/' . rawurlencode($c['guild']) . '/members/' . rawurlencode((string)$acc['discord_id']) . '/roles/' . rawurlencode($role), ['bot' => $c['bot']]);
        if ($st === 204) $given++;
    }
    return [$given, $none];
}

// A message from the team in #dev-announcements (a team note, when you tick it). Best effort.
function rs_dc_dev_news(string $text, string $link = ''): bool {
    $c = rs_dc_conf();
    $ch = trim((string)rs_setting('dc_dev_news', ''));
    if ($c['bot'] === '' || $ch === '') return false;
    [$st] = rs_dc_call('POST', '/channels/' . rawurlencode($ch) . '/messages', ['bot' => $c['bot'], 'json' => [
        'content' => mb_substr($text . ($link !== '' ? "\n" . $link : ''), 0, 1900), 'allowed_mentions' => ['parse' => []]]]);
    return $st === 200;
}

// ---- (4.0) PC releases on Discord: after Publish PC, new recomps and new versions, with their cover --
// Its own webhook (a PC channel), else the PS5 one. Never in the way of publishing.
function rs_pc_discord_hook(): string { $h = (string)rs_setting('discord_pc_webhook', ''); return rs_discord_hook_ok($h) ? $h : (rs_discord_hook_ok(rs_discord_hook()) ? rs_discord_hook() : ''); }
// (4.1) New versions can have their own channel (#recomp-updates): its webhook, or else the bot posts there.
function rs_pc_discord_upd_hook(): string { $h = (string)rs_setting('discord_pc_upd_webhook', ''); return rs_discord_hook_ok($h) ? $h : ''; }
function rs_pc_discord_upd_on(): bool {
    return rs_pc_discord_upd_hook() !== '' || (trim((string)rs_setting('dc_pc_upd_chan', '')) !== '' && rs_dc_conf()['bot'] !== '');
}
function rs_pc_discord_post(array $payload, ?string &$why = null, string $to = 'new'): bool {
    if ($to === 'update' && rs_pc_discord_upd_hook() === '' && rs_pc_discord_upd_on()) {
        unset($payload['username']);
        [$st] = rs_dc_call('POST', '/channels/' . rawurlencode(trim((string)rs_setting('dc_pc_upd_chan', ''))) . '/messages', ['bot' => rs_dc_conf()['bot'], 'json' => $payload]);
        if ($st !== 200) { $why = 'Discord answered ' . ($st ?: 'nothing') . '.'; return false; }
        return true;
    }
    $hook = $to === 'update' && rs_pc_discord_upd_hook() !== '' ? rs_pc_discord_upd_hook() : rs_pc_discord_hook();
    if ($hook === '') { $why = 'No webhook set.'; return false; }
    $ch = curl_init($hook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { $why = 'Discord answered ' . ($code ?: 'nothing') . '.'; return false; }
    return true;
}
function rs_pc_discord_announce(array $cat): void {
    $want = json_decode((string)rs_setting('discord_pc_posts', '{"new":true,"update":true}'), true) ?: [];
    $seen = json_decode((string)(rs_kv_get('pc_dc_seen')['v'] ?? ''), true);
    $now = [];
    foreach ((array)$cat['apps'] as $a) if (empty($a['soon'])) $now[(string)$a['id']] = (string)$a['version'];
    rs_kv_put('pc_dc_seen', json_encode($now));
    if (!is_array($seen) || (rs_pc_discord_hook() === '' && !rs_pc_discord_upd_on())) return; // the first time: remember what's there, post nothing
    $byId = array_column((array)$cat['apps'], null, 'id');
    $new = []; $upd = [];
    foreach ($now as $id => $v) {
        $a = $byId[$id];
        if (!array_key_exists($id, $seen)) { if (!empty($want['new'])) $new[] = $a; }
        // Old PC games (origin pc) update often and aren't news here: only console recomps' updates.
        elseif ($v !== '' && $seen[$id] !== $v && !empty($want['update']) && ($a['origin'] ?? '') !== 'pc') $upd[] = $a;
    }
    $embeds = []; $updEmbeds = [];
    foreach ($new as $a) $embeds[] = ['title' => mb_substr('New: ' . $a['name'], 0, 250), 'url' => 'https://recomplauncher.com/game/' . rawurlencode((string)$a['id']),
        'description' => mb_substr(trim((string)($a['blurb'] ?? '')) . ((string)($a['game'] ?? '') !== '' && $a['game'] !== $a['name'] ? "\nRebuilt from " . $a['game'] : ''), 0, 400),
        'color' => RS_DC_DEV_COLOR] + ((string)($a['cover'] ?? '') !== '' ? ['thumbnail' => ['url' => (string)$a['cover']]] : []);
    foreach ($upd as $a) $updEmbeds[] = ['title' => mb_substr('Updated: ' . $a['name'] . ' ' . $a['version'], 0, 250), 'url' => 'https://recomplauncher.com/game/' . rawurlencode((string)$a['id']),
        'color' => 0x5FE3A1] + ((string)($a['cover'] ?? '') !== '' ? ['thumbnail' => ['url' => (string)$a['cover']]] : []);
    $send = function (array $list, string $to): void {
        if (!$list) return;
        $more = count($list) - 10;
        $payload = ['username' => 'RecompLauncher', 'embeds' => array_slice($list, 0, 10), 'allowed_mentions' => ['parse' => []]];
        if ($more > 0) $payload['content'] = 'And ' . $more . ' more on https://recomplauncher.com/all';
        $ok = rs_pc_discord_post($payload, $why, $to);
        rs_log('discord', 'pc-post', ($to === 'update' ? 'updates: ' : '') . ($ok ? count($list) . ' posted' : 'not posted: ' . $why));
    };
    // Updates in #recomp-updates when it's set up; else everything together, as before.
    if (rs_pc_discord_upd_on()) { $send($embeds, 'new'); $send($updEmbeds, 'update'); }
    else $send(array_merge($embeds, $updEmbeds), 'new');
}

// (4.1) Makes (or finds) #recomp-updates right under the new-recomps channel, with the same who-sees-what,
// and gives it a RecompLauncher webhook when the bot may make one (else the bot posts there itself).
function rs_dc_pc_updates_setup(): array {
    $c = rs_dc_conf();
    if ($c['bot'] === '' || $c['guild'] === '') throw new RuntimeException('Connect the bot first (Discord server, above).');
    $g = rawurlencode($c['guild']);
    $bot = ['bot' => $c['bot']];
    [$st, $me] = rs_dc_call('GET', '/users/@me', $bot);
    if ($st !== 200 || empty($me['id'])) throw new RuntimeException('Discord didn\'t say who the bot is (' . $st . ').');
    $botId = (string)$me['id'];
    // Where the new-recomps channel is: its webhook knows.
    $parent = ''; $pos = null; $over = []; $near = '';
    $hook = (string)rs_setting('discord_pc_webhook', '');
    if (rs_discord_hook_ok($hook)) {
        [$st, $w] = rs_dc_call('GET', $hook);
        if ($st === 200 && !empty($w['channel_id'])) {
            [$st, $nc] = rs_dc_call('GET', '/channels/' . rawurlencode((string)$w['channel_id']), $bot);
            if ($st === 200 && is_array($nc)) {
                $parent = (string)($nc['parent_id'] ?? '');
                $pos = (int)($nc['position'] ?? 0) + 1;
                $over = (array)($nc['permission_overwrites'] ?? []);
                $near = (string)($nc['name'] ?? '');
            }
        }
    }
    [$st, $chans] = rs_dc_call('GET', '/guilds/' . $g . '/channels', $bot);
    if ($st !== 200) throw new RuntimeException('Couldn\'t read the server\'s channels (' . $st . ').');
    $id = trim((string)rs_setting('dc_pc_upd_chan', ''));
    $have = null;
    foreach ((array)$chans as $ch) if ((string)$ch['id'] === $id) $have = $ch;
    if (!$have) foreach ((array)$chans as $ch) if ((int)$ch['type'] === 0 && strcasecmp((string)$ch['name'], 'recomp-updates') === 0) $have = $ch;
    $done = [];
    if ($have) {
        $id = (string)$have['id'];
        $done[] = '#recomp-updates was there already.';
    } else {
        $copy = array_values(array_filter(array_map(fn($o) => ['id' => (string)$o['id'], 'type' => (int)$o['type'], 'allow' => (string)$o['allow'], 'deny' => (string)$o['deny']], $over),
            fn($o) => $o['id'] !== $botId));
        $mine = ['id' => $botId, 'type' => 1, 'allow' => (string)(RS_DC_VIEW | RS_DC_SEND | RS_DC_HISTORY | 16384), 'deny' => '0'];
        $body = ['name' => 'recomp-updates', 'type' => 0, 'topic' => 'New versions of PC recomps on RecompLauncher.'];
        if ($parent !== '') $body['parent_id'] = $parent;
        if ($pos !== null) $body['position'] = $pos;
        $x = null;
        // The bot can only hand out what it has itself: try the fullest version first.
        foreach ([array_merge($copy, [$mine]), $copy, null] as $try) {
            $b = $body;
            if ($try !== null) $b['permission_overwrites'] = $try;
            [$st, $x] = rs_dc_call('POST', '/guilds/' . $g . '/channels', $bot + ['json' => $b]);
            if (($st === 200 || $st === 201) && !empty($x['id'])) break;
            $x = null;
        }
        if (!$x) throw new RuntimeException('Couldn\'t make #recomp-updates (' . $st . '). The bot needs Manage Channels.');
        $id = (string)$x['id'];
        $done[] = 'Made #recomp-updates' . ($near !== '' ? ', under #' . $near : '') . '.';
    }
    rs_set_setting('dc_pc_upd_chan', $id);
    if (rs_pc_discord_upd_hook() === '') {
        [$st, $w] = rs_dc_call('POST', '/channels/' . rawurlencode($id) . '/webhooks', $bot + ['json' => ['name' => 'RecompLauncher']]);
        if ($st === 200 && !empty($w['id']) && !empty($w['token'])) {
            rs_set_setting('discord_pc_upd_webhook', 'https://discord.com/api/webhooks/' . $w['id'] . '/' . $w['token']);
            $done[] = 'Posts there come from RecompLauncher, like the new ones.';
        } else $done[] = 'The bot posts the updates there itself.';
    }
    rs_log('discord', 'setup', implode(' ', $done));
    return $done;
}

// ---- (4.0) Team notes: short notes from the RecompStore team, pinned on top of the developer hub
// and/or the site until you take them down (players can close one; it stays closed for them).
function rs_notes_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS team_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        body VARCHAR(400) NOT NULL,
        link VARCHAR(300) NOT NULL DEFAULT \'\',
        link_text VARCHAR(40) NOT NULL DEFAULT \'\',
        place VARCHAR(8) NOT NULL DEFAULT \'devs\',
        tone VARCHAR(8) NOT NULL DEFAULT \'cyan\',
        active TINYINT NOT NULL DEFAULT 1,
        created DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $done = true;
}
const RS_NOTE_PLACES = ['devs' => 'Developer hub', 'site' => 'Website (players)', 'both' => 'Both'];
const RS_NOTE_TONES = ['cyan' => 'News (cyan)', 'gold' => 'Heads-up (gold)', 'violet' => 'Developers (violet)'];
function rs_notes(bool $activeOnly = true, string $for = ''): array {
    rs_notes_tables();
    $rows = rs_db()->query('SELECT * FROM team_notes' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY id DESC LIMIT 50')->fetchAll();
    if ($for !== '') $rows = array_values(array_filter($rows, fn($r) => $r['place'] === 'both' || $r['place'] === $for));
    return array_map(fn($r) => ['id' => (int)$r['id'], 'body' => (string)$r['body'], 'link' => (string)$r['link'], 'linkText' => (string)$r['link_text'],
        'place' => (string)$r['place'], 'tone' => (string)$r['tone'], 'active' => (bool)$r['active'], 'created' => (string)$r['created']], $rows);
}

// ---- (4.6) Member counter: a voice channel at the top of the Discord server named "Members: 12,345",
// renamed by the beta bot from the VPS every 10 minutes (dc-counter.php). Nobody can join it. Discord
// allows 2 renames per channel per 10 minutes, so it renames at most once a run, and only when the
// number changed. The channel's ID is kept in the settings (dc_count_chan); what happened last time
// (count, when, any problem) in kv_cache, so the settings aren't rewritten every run.
const RS_DC_COUNT_GAP = 300;        // never rename the channel more often than this (seconds)
const RS_DC_CONNECT = 1048576;      // the Connect permission (1 << 20)
const RS_DC_VIEW = 1024;            // View Channel
const RS_DC_MANAGE = 16;            // Manage Channels
const RS_DC_COUNT_PERMS = 268435473; // the bot's invite permissions with Manage Channels added

function rs_dc_count_on(): bool { return rs_setting('dc_count_on', '0') === '1'; }
function rs_dc_count_name(int $n): string { return 'Members: ' . number_format($n); }
// -> [chan, count, renamed (unix), checked (unix), err, retry (unix)]
function rs_dc_count_state(): array {
    $c = rs_kv_get('dc_counter');
    $s = $c ? (json_decode($c['v'], true) ?: []) : [];
    return $s + ['count' => null, 'renamed' => 0, 'checked' => 0, 'err' => '', 'retry' => 0];
}
function rs_dc_count_save(array $s): void { rs_kv_put('dc_counter', (string)json_encode($s)); }
// A problem in plain words, for the admin. The bot token never goes in here.
function rs_dc_count_why(int $st, $j, string $doing): string {
    $code = is_array($j) ? (int)($j['code'] ?? 0) : 0;
    if ($st === 401) return 'Discord turned the bot token down. Paste a new one above.';
    if ($st === 403 || $code === 50013 || $code === 50001) return 'The bot isn\'t allowed to ' . $doing . '. It needs Manage Channels.';
    if ($st === 404 && $doing === 'read the server') return 'The bot isn\'t in that server (check the Server ID).';
    if ($st === 0) return 'Couldn\'t reach Discord. It tries again on the next run.';
    return 'Discord answered ' . $st . ' while trying to ' . $doing . '. It tries again on the next run.';
}

// One run: read the member count, make the channel if it isn't there, rename it if the number changed.
// Update now on the Beta page runs the same thing (still never sooner than RS_DC_COUNT_GAP after a rename).
// -> the state after the run
function rs_dc_count_run(): array {
    $s = rs_dc_count_state();
    $c = rs_dc_conf();
    $now = time();
    $s['checked'] = $now;
    if (!rs_dc_count_on()) { $s['err'] = ''; rs_dc_count_save($s); return $s; }
    if ($c['bot'] === '' || $c['guild'] === '') { $s['err'] = 'It needs the bot token and the Server ID above.'; rs_dc_count_save($s); return $s; }
    if ($s['retry'] > $now) { rs_dc_count_save($s); return $s; } // Discord asked us to wait
    $wait = function (int $st, $j) use (&$s, $now): bool {
        if ($st !== 429) return false;
        $s['retry'] = $now + (int)ceil((float)(is_array($j) ? ($j['retry_after'] ?? 60) : 60)) + 1;
        $s['err'] = 'Discord asked to wait a little. It tries again on the next run.';
        return true;
    };

    [$st, $g] = rs_dc_call('GET', '/guilds/' . rawurlencode($c['guild']) . '?with_counts=true', ['bot' => $c['bot']]);
    if ($wait($st, $g)) { rs_dc_count_save($s); return $s; }
    if ($st !== 200 || !is_array($g) || !isset($g['approximate_member_count'])) { $s['err'] = rs_dc_count_why($st, $g, 'read the server'); rs_dc_count_save($s); return $s; }
    $n = (int)$g['approximate_member_count'];
    $name = rs_dc_count_name($n);
    $chan = trim((string)rs_setting('dc_count_chan', ''));

    // The bot's own user, so it can keep seeing and renaming its channel on servers where the
    // default role can't view channels.
    $me = ($k = rs_kv_get('dc_bot_user')) ? $k['v'] : '';
    if ($me === '') {
        [$st, $u] = rs_dc_call('GET', '/users/@me', ['bot' => $c['bot']]);
        if ($wait($st, $u)) { rs_dc_count_save($s); return $s; }
        if ($st !== 200 || empty($u['id'])) { $s['err'] = rs_dc_count_why($st, $u, 'read its own account'); rs_dc_count_save($s); return $s; }
        $me = (string)$u['id'];
        rs_kv_put('dc_bot_user', $me);
    }
    $mine = ['id' => $me, 'type' => 1, 'allow' => (string)(RS_DC_VIEW | RS_DC_MANAGE), 'deny' => '0'];

    // Its channel: still there? Read from the server's channel list, which needs no access to the
    // channel itself. (Deleted by hand: made again.)
    if ($chan !== '') {
        [$st, $list] = rs_dc_call('GET', '/guilds/' . rawurlencode($c['guild']) . '/channels', ['bot' => $c['bot']]);
        if ($wait($st, $list)) { rs_dc_count_save($s); return $s; }
        if ($st !== 200 || !is_array($list)) { $s['err'] = rs_dc_count_why($st, $list, 'list the channels'); rs_dc_count_save($s); return $s; }
        $ch = null;
        foreach ($list as $x) if ((string)($x['id'] ?? '') === $chan) { $ch = $x; break; }
        if (!$ch) { $chan = ''; rs_set_setting('dc_count_chan', ''); }
        else {
            $s['live'] = (string)($ch['name'] ?? '');
            // Made before the bot gave itself access: give it now.
            $has = false;
            foreach ((array)($ch['permission_overwrites'] ?? []) as $o) if ((string)($o['id'] ?? '') === $me && ((int)($o['allow'] ?? 0) & RS_DC_VIEW)) $has = true;
            if (!$has) {
                [$st, $r] = rs_dc_call('PUT', '/channels/' . rawurlencode($chan) . '/permissions/' . rawurlencode($me), ['bot' => $c['bot'], 'json' => ['type' => 1, 'allow' => $mine['allow'], 'deny' => '0']]);
                if ($wait($st, $r)) { rs_dc_count_save($s); return $s; }
                if ($st !== 204 && $st !== 200) { $s['err'] = rs_dc_count_why($st, $r, 'give itself access to the channel'); rs_dc_count_save($s); return $s; }
            }
        }
    }
    if ($chan === '') {
        // A voice channel at the very top: everyone sees it, nobody can connect; the bot keeps access.
        [$st, $ch] = rs_dc_call('POST', '/guilds/' . rawurlencode($c['guild']) . '/channels', ['bot' => $c['bot'], 'json' => [
            'name' => $name, 'type' => 2, 'position' => 0,
            'permission_overwrites' => [['id' => $c['guild'], 'type' => 0, 'allow' => '0', 'deny' => (string)RS_DC_CONNECT], $mine],
        ]]);
        if ($wait($st, $ch)) { rs_dc_count_save($s); return $s; }
        if (($st !== 200 && $st !== 201) || empty($ch['id'])) { $s['err'] = rs_dc_count_why($st, $ch, 'make the channel'); rs_dc_count_save($s); return $s; }
        rs_set_setting('dc_count_chan', (string)$ch['id']);
        // Pull it to the top (position 0 on create isn't always honored).
        rs_dc_call('PATCH', '/guilds/' . rawurlencode($c['guild']) . '/channels', ['bot' => $c['bot'], 'json' => [['id' => (string)$ch['id'], 'position' => 0]]]);
        rs_log('discord', 'member counter', 'made the channel: ' . $name);
        $s = ['count' => $n, 'renamed' => $now, 'checked' => $now, 'err' => '', 'retry' => 0, 'live' => $name];
        rs_dc_count_save($s);
        return $s;
    }

    // Rename only when the number changed, and never more often than Discord allows.
    if (($s['live'] ?? '') === $name) { $s['count'] = $n; $s['err'] = ''; rs_dc_count_save($s); return $s; }
    if ($now - (int)$s['renamed'] < RS_DC_COUNT_GAP) { $s['err'] = ''; rs_dc_count_save($s); return $s; }
    [$st, $ch] = rs_dc_call('PATCH', '/channels/' . rawurlencode($chan), ['bot' => $c['bot'], 'json' => ['name' => $name]]);
    if ($wait($st, $ch)) { rs_dc_count_save($s); return $s; }
    if ($st !== 200) { $s['err'] = rs_dc_count_why($st, $ch, 'rename the channel'); rs_dc_count_save($s); return $s; }
    $s = ['count' => $n, 'renamed' => $now, 'checked' => $now, 'err' => '', 'retry' => 0, 'live' => $name];
    rs_dc_count_save($s);
    return $s;
}
