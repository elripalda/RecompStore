<?php
// RecompStore website (2.7.1): the betas on recompstore.com/beta, and codes for them.
//   GET               -> {ok, discord: {ready, invite, bot}, me: {name, avatar, can} | null, betas: [...]}
//   POST JSON {game}  -> {ok, code} or {ok:false, error, why}
// A code comes to someone signed in with Discord who's in the RecompStore server (api/discord.php).
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_b_out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    rs_dc_tables();
    $u = rs_dc_user();
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) rs_b_out(403, ['ok' => false, 'error' => 'Do this from recompstore.com.']);
        $in = json_decode((string)file_get_contents('php://input', false, null, 0, 2048), true);
        $game = is_array($in) ? (string)preg_replace('/[^A-Za-z0-9_-]/', '', (string)($in['game'] ?? '')) : '';
        if (!$u) rs_b_out(200, ['ok' => false, 'why' => 'signin', 'error' => 'Sign in with Discord first.']);
        try {
            rs_b_out(200, ['ok' => true, 'code' => rs_dc_claim($u, $game)]);
        } catch (RuntimeException $e) {
            rs_b_out(200, ['ok' => false, 'why' => rs_dc_allowed($u), 'error' => $e->getMessage()]);
        }
    }
    $c = rs_dc_conf();
    $avatar = $u && ($u['avatar'] ?? '') !== '' && preg_match('/^(a_)?[0-9a-f]{32}$/', (string)$u['avatar'])
        ? 'https://cdn.discordapp.com/avatars/' . rawurlencode((string)$u['id']) . '/' . $u['avatar'] . '.png?size=64' : '';
    rs_b_out(200, [
        'ok' => true,
        'discord' => ['ready' => rs_dc_ready(), 'invite' => preg_match('#^https://#', $c['invite']) ? $c['invite'] : '', 'bot' => $c['bot'] !== ''],
        'me' => $u ? ['name' => (string)$u['name'], 'avatar' => $avatar, 'can' => rs_dc_allowed($u), 'joined' => !empty($u['joined'])] : null,
        'betas' => rs_dc_betas($u),
    ]);
} catch (Throwable $e) {
    rs_b_out(503, ['ok' => false, 'error' => 'Betas can\'t be shown right now. Try again later.']);
}
