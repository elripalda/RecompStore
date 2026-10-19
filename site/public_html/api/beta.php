<?php
// Recomp Store website: beta codes for the PS5 store (Recomp Store 1.1.0 and newer).
//   POST a=redeem   code, [install, token], store  -> unlocks the code's game for this store install
//   POST a=list     install, token, store          -> the beta games this install has
//   GET  a=file     id, install, sig               -> a beta game's zip, for an install that has it
// A store install is known by an ID and a secret token the server makes on its first redeem;
// the store keeps both in its data folder. Answers are JSON, except the zip.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$s = fn(string $k, int $max = 64): string => isset($in[$k]) && is_string($in[$k]) ? substr(trim($in[$k]), 0, $max) : '';
$a = $s('a', 16);

try {
    rs_beta_tables();
    $db = rs_db();
} catch (Throwable $e) {
    rs_out(503, ['ok' => false, 'error' => 'Recomp Store can\'t check codes right now. Try again later.']);
}

// The install that sent this, when its ID and token match; null otherwise.
function rs_install(PDO $db, string $install, string $token): ?string {
    if (!preg_match('/^[0-9a-f]{16}$/', $install) || !preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    $st = $db->prepare('SELECT token_hash FROM beta_installs WHERE install_id = ?');
    $st->execute([$install]);
    $h = $st->fetchColumn();
    return is_string($h) && hash_equals($h, hash('sha256', $token)) ? $install : null;
}

function rs_seen(PDO $db, string $install, string $store): void {
    $db->prepare('UPDATE beta_installs SET last_seen = NOW(), store_version = ? WHERE install_id = ?')
       ->execute([preg_replace('/[^0-9A-Za-z.\-]/', '', $store), $install]);
}

if ($a === 'redeem' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = rs_ip_hash();
    // Ten wrong codes an hour from one address, then a pause: 8 digits can't be guessed through this.
    $st = $db->prepare('SELECT COUNT(*) FROM beta_tries WHERE ip_hash = ? AND at > (NOW() - INTERVAL 1 HOUR)');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) rs_out(429, ['ok' => false, 'error' => 'Too many tries. Wait an hour and try again.']);
    $wrong = function (string $msg) use ($db, $ip): void {
        $db->prepare('INSERT INTO beta_tries (ip_hash, at) VALUES (?, NOW())')->execute([$ip]);
        $db->exec('DELETE FROM beta_tries WHERE at < (NOW() - INTERVAL 1 DAY)');
        rs_out(200, ['ok' => false, 'error' => $msg]);
    };

    $code = preg_replace('/\D/', '', $s('code', 32));
    if (strlen($code) !== 8) $wrong('A code is 8 numbers, like 12-345-678.');
    $st = $db->prepare('SELECT * FROM beta_codes WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    if (!$row) {
        // (2.0) A code the website made to link a phone or computer: that browser is linked to
        // this console (made now if it has no ID yet).
        $web = rs_live_web_code($db, $code);
        if ($web && $web['device_id'] !== null) {
            $install = rs_install($db, $s('install'), $s('token'));
            $newToken = '';
            if ($install === null) [$install, $newToken] = rs_console_new($db);
            $up = $db->prepare('UPDATE link_codes SET used = NOW(), install_id = ? WHERE code = ? AND used IS NULL');
            $up->execute([$install, $code]);
            if ($up->rowCount() === 1) {
                $db->prepare('UPDATE link_devices SET install_id = ?, linked = NOW() WHERE device_id = ?')->execute([$install, $web['device_id']]);
                $st = $db->prepare('SELECT label FROM link_devices WHERE device_id = ?');
                $st->execute([$web['device_id']]);
                $label = (string)$st->fetchColumn();
                rs_seen($db, $install, $s('store', 32));
                $out = ['ok' => true, 'linked' => true, 'install' => $install,
                        'message' => 'Your ' . ($label !== '' && $label !== 'Phone or computer' ? $label : 'phone') . ' is linked. You can send things to this PS5 from recompstore.com now.'];
                if ($newToken !== '') $out['token'] = $newToken;
                rs_out(200, $out);
            }
        }
        $wrong('That code isn\'t right. Check the numbers and try again.');
    }
    if ((int)$row['revoked']) rs_out(200, ['ok' => false, 'error' => 'That code has been cancelled.']);
    $g = rs_game((string)$row['game_id']);
    if (!$g) rs_out(200, ['ok' => false, 'error' => 'That code\'s game isn\'t in Recomp Store anymore.']);

    // This install, or a new one.
    $install = rs_install($db, $s('install'), $s('token'));
    $newToken = '';
    if ($install !== null && $row['install_id'] !== null && $row['install_id'] !== $install) {
        rs_out(200, ['ok' => false, 'error' => 'That code was already used on another console.']);
    }
    if ($install === null) {
        if ($row['install_id'] !== null) rs_out(200, ['ok' => false, 'error' => 'That code was already used on another console.']);
        $install = bin2hex(random_bytes(8));
        $newToken = bin2hex(random_bytes(16));
        $db->prepare('INSERT INTO beta_installs (install_id, token_hash, created, last_seen) VALUES (?, ?, NOW(), NOW())')
           ->execute([$install, hash('sha256', $newToken)]);
    }
    if ($row['install_id'] === null) {
        $up = $db->prepare('UPDATE beta_codes SET install_id = ?, redeemed = NOW() WHERE code = ? AND install_id IS NULL');
        $up->execute([$install, $code]);
        if ($up->rowCount() !== 1) rs_out(200, ['ok' => false, 'error' => 'That code was already used on another console.']);
    }
    rs_seen($db, $install, $s('store', 32));
    $out = ['ok' => true, 'install' => $install, 'game' => ['id' => $g['id'], 'name' => $g['name']]];
    if ($newToken !== '') $out['token'] = $newToken;
    $out['message'] = $g['beta'] ? $g['name'] . ' is unlocked. It\'s in your Library.'
                                 : $g['name'] . ' is out for everyone now. Find it in the store.';
    rs_out(200, $out);
}

if ($a === 'list' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $install = rs_install($db, $s('install'), $s('token'));
    if ($install === null) rs_out(403, ['ok' => false, 'error' => 'This console isn\'t known here.']);
    rs_seen($db, $install, $s('store', 32));
    $st = $db->prepare('SELECT DISTINCT game_id FROM beta_codes WHERE install_id = ? AND revoked = 0');
    $st->execute([$install]);
    $apps = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
        $g = rs_game((string)$id);
        if (!$g || !$g['beta'] || $g['hidden'] || $g['beta_file'] === '') continue;
        $apps[] = rs_beta_entry($g, $install, 1000 + $i);
    }
    rs_out(200, ['ok' => true, 'apps' => $apps]);
}

if ($a === 'file') {
    $id = $s('id', 32);
    $install = $s('install', 16);
    $sig = $s('sig', 32);
    if (!preg_match('/^[0-9a-f]{16}$/', $install) || !hash_equals(rs_beta_sig($install, $id), $sig)) rs_out(403, ['ok' => false, 'error' => 'Not allowed.']);
    $st = $db->prepare('SELECT COUNT(*) FROM beta_codes WHERE install_id = ? AND game_id = ? AND revoked = 0');
    $st->execute([$install, $id]);
    $g = rs_game($id);
    if ((int)$st->fetchColumn() === 0 || !$g || !$g['beta'] || $g['beta_file'] === '') rs_out(403, ['ok' => false, 'error' => 'Not allowed.']);
    $path = rs_beta_dir() . '/' . basename((string)$g['beta_file']);
    if (!is_file($path)) rs_out(404, ['ok' => false, 'error' => 'The beta file is missing.']);

    // The zip, whole or from a byte range (a resumed download).
    $size = filesize($path);
    $from = 0; $to = $size - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $from = max(0, $size - (int)$m[2]); }
        else { $from = (int)$m[1]; if ($m[2] !== '') $to = min($to, (int)$m[2]); }
        if ($from > $to || $from >= $size) { header('Content-Range: bytes */' . $size); http_response_code(416); exit; }
        http_response_code(206);
        header("Content-Range: bytes $from-$to/$size");
    }
    rs_seen($db, $install, '');
    @set_time_limit(0);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Length: ' . ($to - $from + 1));
    header('Accept-Ranges: bytes');
    header('Content-Disposition: attachment; filename="' . $id . '-beta.zip"');
    $fh = fopen($path, 'rb');
    fseek($fh, $from);
    $left = $to - $from + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, (int)min(1 << 20, $left));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

rs_out(400, ['ok' => false, 'error' => 'Unknown request.']);
