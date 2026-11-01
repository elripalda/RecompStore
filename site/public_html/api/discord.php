<?php
// RecompStore website (2.7.1): signing in with Discord for beta codes (recompstore.com/beta).
//   GET                    -> off to Discord (?game=ID: back to that beta's card)
//   GET ?code=&state=      -> back from Discord: signed in on this browser, then /beta
//   POST a=out             -> signed out
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$back = function (string $result, string $game = ''): void {
    header('Location: /beta?dc=' . $result . ($game !== '' ? '&game=' . rawurlencode($game) : ''));
    exit;
};
$cookie = ['path' => '/api/discord.php', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) { http_response_code(403); echo '{"ok":false}'; exit; }
    rs_dc_forget();
    echo '{"ok":true}';
    exit;
}

try {
    if (!rs_dc_ready()) $back('off');
    $game = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['game'] ?? '')) ?? '';
    if (isset($_GET['code']) || isset($_GET['error'])) {
        // Back from Discord: the state has to match the one this browser was sent off with.
        $saved = (string)($_COOKIE['rs_dc_state'] ?? '');
        setcookie('rs_dc_state', '', ['expires' => time() - 3600] + $cookie);
        [$state, $game] = array_pad(explode('|', $saved, 2), 2, '');
        $game = preg_replace('/[^A-Za-z0-9_-]/', '', $game) ?? '';
        if ($state === '' || !hash_equals($state, (string)($_GET['state'] ?? ''))) $back('again', $game);
        if (isset($_GET['error'])) { if ($game === 'hub') { header('Location: /hub?dc=no'); exit; } $back('no', $game); }
        $user = rs_dc_finish((string)$_GET['code']);
        rs_dc_set($user);
        // (2.7.2) From the developer hub: Discord linked to their developer account (and the role).
        if ($game === 'hub' && ($dev = rs_dev_current())) {
            rs_db()->prepare('UPDATE dev_accounts SET discord_id = ?, discord_name = ? WHERE id = ?')->execute([(string)$user['id'], mb_substr((string)($user['handle'] ?: $user['name']), 0, 64), $dev['id']]);
            $dev['discord_id'] = (string)$user['id'];
            rs_dev_discord_role($dev);
            header('Location: /hub?dc=ok');
            exit;
        }
        $back('ok', $game);
    }
    $state = bin2hex(random_bytes(16));
    setcookie('rs_dc_state', $state . '|' . $game, ['expires' => time() + 600] + $cookie);
    header('Location: ' . rs_dc_login_url($state));
    exit;
} catch (Throwable $e) {
    $back('err', $game ?? '');
}
