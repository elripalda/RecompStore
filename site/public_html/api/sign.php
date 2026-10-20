<?php
// Recomp Store website (2.1): Recomp Store Signer's side. Answered only with the signer token
// (Settings > Signer), which lets the signer see what's waiting and hand signatures over; a
// signature still has to check out against the keys (here, and in every 2.1 store).
//   POST a=hello   keys=<public-keys.txt>    -> {ok, message}  (the first keys told are kept)
//   GET  a=pending                           -> {ok, items:[{kind, id?, name?, file, size, sha256, when, note?}]}
//   GET  a=get     kind=catalog|catalog-test -> the exact bytes waiting
//   POST a=put     sig=<signature> [id]      -> {ok, message}
declare(strict_types=1);
const RS_SIGNER_CALL = true;
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_sign_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    rs_db();
    if (rs_rate_over('signbad', 10, 3600)) rs_sign_out(429, ['ok' => false, 'error' => 'Too many wrong tokens from here. Try again in an hour.']);
    $token = (string)($_SERVER['HTTP_X_SIGNER_TOKEN'] ?? '');
    $want = rs_setting('signer_token_hash', '');
    if ($want === '' || !preg_match('/^[0-9a-f]{64}$/', $token) || !hash_equals($want, hash('sha256', $token))) {
        rs_rate_hit('signbad');
        rs_sign_out(403, ['ok' => false, 'error' => 'That signer token isn\'t right. Make a new one in Settings > Signer.']);
    }
    $a = (string)($_GET['a'] ?? '');
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

    if ($a === 'hello' && $post) {
        $keys = rs_parse_public_keys((string)($_POST['keys'] ?? ''));
        if (count($keys) < 2) rs_sign_out(400, ['ok' => false, 'error' => 'The signer didn\'t send its public keys.']);
        $have = rs_sign_keys();
        if ($have === []) {
            rs_set_setting('sign_keys', json_encode($keys));
            rs_log('signer', 'keys', 'set: ' . implode(', ', array_map(fn($k) => $k['name'] . ' ' . $k['id'], $keys)));
            rs_sign_out(200, ['ok' => true, 'message' => 'Signing is on: Publish now waits for this signer.']);
        }
        $same = array_column($have, 'pub') === array_column($keys, 'pub') || count(array_intersect(array_column($have, 'pub'), array_column($keys, 'pub'))) === count($keys);
        if (!$same) rs_sign_out(409, ['ok' => false, 'error' => 'The site already has other keys. If you made new ones on purpose, clear them in Settings > Signer first.']);
        rs_log('signer', 'connected', '');
        rs_sign_out(200, ['ok' => true, 'message' => 'Its keys match the site\'s.']);
    }
    if (!rs_sign_on()) rs_sign_out(409, ['ok' => false, 'error' => 'Connect the signer first.']);

    if ($a === 'pending') rs_sign_out(200, ['ok' => true, 'items' => rs_sign_pending()]);

    if ($a === 'get') {
        $kind = (string)($_GET['kind'] ?? '');
        if (!in_array($kind, rs_catalog_kinds(), true) || !is_file(rs_pending_file($kind))) rs_sign_out(404, ['ok' => false, 'error' => 'Nothing like that is waiting.']);
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        readfile(rs_pending_file($kind));
        exit;
    }

    if ($a === 'put' && $post) {
        $text = (string)($_POST['sig'] ?? '');
        $sig = rs_sig_parse($text);
        if ($sig === null) rs_sign_out(400, ['ok' => false, 'error' => 'That isn\'t a signature.']);
        if (in_array($sig['kind'], rs_catalog_kinds(), true)) rs_sign_out(200, ['ok' => true, 'message' => rs_publish_signed($sig['kind'], $text)]);
        if ($sig['kind'] === 'beta') rs_sign_out(200, ['ok' => true, 'message' => rs_store_beta_sig($text)]);
        rs_sign_out(400, ['ok' => false, 'error' => 'A store update\'s signature goes on GitHub with the release, not here.']);
    }
    rs_sign_out(400, ['ok' => false, 'error' => 'Unknown request.']);
} catch (Throwable $e) {
    rs_sign_out(409, ['ok' => false, 'error' => $e->getMessage()]);
}
