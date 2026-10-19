<?php
// Recomp Store website (2.0): a phone or computer linked to a PS5, and the store asking what it
// sent. The store (1.9.1 and newer) posts forms with its install ID and token; a browser is known
// by its cookie (rs_device). Answers are JSON. The contract: docs/api-2.0.md, sections 0 and 1.
//   Store:  a=hello | code | poll | unlink | state
//   Browser: a=claim | webcode | mine (GET) | send | redeem | forget
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

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$in = $post ? $_POST : $_GET;
// The site's own pages may send JSON.
if ($post && str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $j = json_decode((string)file_get_contents('php://input', false, null, 0, 70000), true);
    $in = is_array($j) ? $j : [];
}
$s = fn(string $k, int $max = 64): string => isset($in[$k]) && is_scalar($in[$k]) ? substr(trim((string)$in[$k]), 0, $max) : '';
$a = $s('a', 16);

try {
    $db = rs_db();
    rs_link_tables();
} catch (Throwable $e) {
    rs_out(503, ['ok' => false, 'error' => 'recompstore.com can\'t link consoles right now. Try again later.']);
}

// A browser's request that changes something: only from this site's own pages.
$fromSite = function (): void {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== $host) rs_out(403, ['ok' => false, 'error' => 'Do this from recompstore.com.']);
};

$consoleOf = function () use ($db, $s): string {
    $install = rs_console($db, $s('install', 16), $s('token', 32));
    if ($install === null) rs_out(403, ['ok' => false, 'error' => 'This console isn\'t known here. Close the store and open it again.']);
    rs_console_seen($db, $install, $s('store', 32));
    return $install;
};

$devicesOf = function (string $install) use ($db): array {
    $st = $db->prepare('SELECT device_id, label, UNIX_TIMESTAMP(COALESCE(linked, created)) since, UNIX_TIMESTAMP(last_seen) seen FROM link_devices WHERE install_id = ? ORDER BY linked, created');
    $st->execute([$install]);
    return array_map(fn($d) => ['id' => $d['device_id'], 'label' => $d['label'], 'since' => gmdate('Y-m-d', (int)$d['since']), 'seen' => gmdate('Y-m-d\TH:i:s\Z', (int)$d['seen'])], $st->fetchAll());
};

// ---- The store ---------------------------------------------------------------------------------

if ($a === 'hello' && $post) {
    $install = rs_console($db, $s('install', 16), $s('token', 32));
    if ($install !== null) {
        rs_console_seen($db, $install, $s('store', 32));
        // (2.0) tester: the admin marked it; it reads the test catalog (api/catalog.php).
        rs_out(200, ['ok' => true, 'install' => $install, 'token' => $s('token', 32), 'tester' => rs_console_tester($db, $install)]);
    }
    // A new console: 20 an hour from one address at most.
    if (rs_rate_over('hello', 20, 3600)) rs_out(429, ['ok' => false, 'error' => 'Too many new consoles from this connection. Try again in an hour.']);
    rs_rate_hit('hello');
    [$install, $token] = rs_console_new($db);
    rs_console_seen($db, $install, $s('store', 32));
    rs_out(200, ['ok' => true, 'install' => $install, 'token' => $token, 'new' => true, 'tester' => false]);
}

if ($a === 'code' && $post) {
    $install = $consoleOf();
    // A new code takes the place of this console's unused one.
    $db->prepare("DELETE FROM link_codes WHERE kind = 'ps5' AND install_id = ? AND used IS NULL")->execute([$install]);
    if (random_int(0, 20) === 0) $db->exec('DELETE FROM link_codes WHERE expires < (NOW() - INTERVAL 1 DAY)');
    $ins = $db->prepare("INSERT IGNORE INTO link_codes (code, kind, install_id, created, expires) VALUES (?, 'ps5', ?, NOW(), NOW() + INTERVAL 10 MINUTE)");
    for ($i = 0; $i < 30; ++$i) {
        $code = rs_letter_code(6);
        $ins->execute([$code, $install]);
        if ($ins->rowCount() === 1) rs_out(200, ['ok' => true, 'code' => $code, 'url' => rs_abs('/l/' . $code), 'expires' => 600]);
    }
    rs_out(503, ['ok' => false, 'error' => 'recompstore.com couldn\'t make a code right now. Try again.']);
}

if ($a === 'poll' && $post) {
    $install = $consoleOf();
    $actions = [];
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT q.id, q.kind, q.data, d.label FROM link_queue q LEFT JOIN link_devices d ON d.device_id = q.device_id
                            WHERE q.install_id = ? AND q.taken IS NULL ORDER BY q.id LIMIT 20 FOR UPDATE');
        $st->execute([$install]);
        $rows = $st->fetchAll();
        if ($rows) {
            $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
            $db->exec("UPDATE link_queue SET taken = NOW() WHERE id IN ($ids)");
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $rows = [];
    }
    foreach ($rows as $r) {
        $d = json_decode((string)$r['data'], true);
        $actions[] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'data' => is_array($d) ? (object)$d : (object)[], 'from' => (string)($r['label'] ?? '')];
    }
    if (random_int(0, 50) === 0) $db->exec('DELETE FROM link_queue WHERE taken IS NOT NULL AND taken < (NOW() - INTERVAL 7 DAY)');
    rs_out(200, ['ok' => true, 'devices' => $devicesOf($install), 'actions' => $actions]);
}

if ($a === 'unlink' && $post) {
    $install = $consoleOf();
    $db->prepare('UPDATE link_devices SET install_id = NULL, linked = NULL WHERE device_id = ? AND install_id = ?')->execute([$s('device', 16), $install]);
    rs_out(200, ['ok' => true]);
}

if ($a === 'state' && $post) {
    $install = $consoleOf();
    $raw = isset($in['state']) && is_string($in['state']) ? $in['state'] : '';
    if (strlen($raw) > 65536) rs_out(413, ['ok' => false, 'error' => 'Too much to keep.']);
    $j = json_decode($raw, true);
    if (!is_array($j) || array_is_list($j) && $j !== []) rs_out(422, ['ok' => false, 'error' => 'That isn\'t a state the site can read.']);
    $db->prepare('INSERT INTO link_state (install_id, state, updated) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE state = VALUES(state), updated = NOW()')
       ->execute([$install, json_encode((object)$j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    rs_out(200, ['ok' => true]);
}

// ---- A browser -------------------------------------------------------------------------------

if ($a === 'claim' && $post) {
    $fromSite();
    if (rs_rate_over('claim', 10, 3600)) rs_out(429, ['ok' => false, 'error' => 'Too many tries. Wait an hour and try again.']);
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s('code', 32)) ?? '');
    $st = $db->prepare("SELECT * FROM link_codes WHERE code = ? AND kind = 'ps5' AND used IS NULL AND expires > NOW()");
    $st->execute([$code]);
    $row = strlen($code) === 6 ? $st->fetch() : false;
    if (!$row) {
        rs_rate_hit('claim');
        rs_out(200, ['ok' => false, 'error' => 'That code didn\'t work. Make a new one on your PS5.']);
    }
    $up = $db->prepare("UPDATE link_codes SET used = NOW() WHERE code = ? AND used IS NULL");
    $up->execute([$code]);
    if ($up->rowCount() !== 1) rs_out(200, ['ok' => false, 'error' => 'That code didn\'t work. Make a new one on your PS5.']);
    $label = rs_clean_label($s('label', 80));
    $dev = rs_device($db) ?? rs_device_make($db, $label);
    $db->prepare('UPDATE link_devices SET install_id = ?, linked = NOW()' . ($label !== '' ? ', label = ?' : '') . ' WHERE device_id = ?')
       ->execute($label !== '' ? [$row['install_id'], $label, $dev['device_id']] : [$row['install_id'], $dev['device_id']]);
    $db->prepare('UPDATE link_codes SET device_id = ? WHERE code = ?')->execute([$dev['device_id'], $code]);
    $st = $db->prepare('SELECT store_version FROM beta_installs WHERE install_id = ?');
    $st->execute([$row['install_id']]);
    rs_out(200, ['ok' => true, 'console' => ['label' => 'PS5', 'store' => (string)$st->fetchColumn()]]);
}

if ($a === 'webcode' && $post) {
    $fromSite();
    if (rs_rate_over('webcode', 20, 3600)) rs_out(429, ['ok' => false, 'error' => 'Too many codes at once. Wait a bit and try again.']);
    rs_rate_hit('webcode');
    $dev = rs_device($db) ?? rs_device_make($db, rs_clean_label($s('label', 80)));
    $db->prepare("DELETE FROM link_codes WHERE kind = 'web' AND device_id = ? AND used IS NULL")->execute([$dev['device_id']]);
    $code = rs_web_code($db);
    $db->prepare("INSERT INTO link_codes (code, kind, device_id, created, expires) VALUES (?, 'web', ?, NOW(), NOW() + INTERVAL 10 MINUTE)")
       ->execute([$code, $dev['device_id']]);
    rs_out(200, ['ok' => true, 'code' => $code, 'expires' => 600]);
}

if ($a === 'mine') {
    $dev = rs_device($db);
    if (!$dev || $dev['install_id'] === null) rs_out(200, ['ok' => true, 'linked' => false] + ($dev ? ['device' => ['id' => $dev['device_id'], 'label' => $dev['label']]] : []));
    $st = $db->prepare('SELECT store_version, UNIX_TIMESTAMP(last_seen) seen FROM beta_installs WHERE install_id = ?');
    $st->execute([$dev['install_id']]);
    $c = $st->fetch() ?: ['store_version' => '', 'seen' => time()];
    $st = $db->prepare('SELECT state, UNIX_TIMESTAMP(updated) at FROM link_state WHERE install_id = ?');
    $st->execute([$dev['install_id']]);
    $sr = $st->fetch();
    $state = $sr ? json_decode((string)$sr['state'], true) : null;
    rs_out(200, [
        'ok' => true, 'linked' => true,
        'device' => ['id' => $dev['device_id'], 'label' => $dev['label']],
        'console' => ['id' => substr(hash('sha256', 'console|' . $dev['install_id']), 0, 10), 'label' => 'PS5', 'store' => (string)$c['store_version'], 'seen' => gmdate('Y-m-d\TH:i:s\Z', (int)$c['seen'])],
        'state' => is_array($state) ? (object)$state : (object)[],
        'state_at' => $sr ? gmdate('Y-m-d\TH:i:s\Z', (int)$sr['at']) : '',
    ]);
}

// What only a linked browser can do.
$linked = function () use ($db, $fromSite): array {
    $fromSite();
    $dev = rs_device($db);
    if (!$dev || $dev['install_id'] === null) rs_out(200, ['ok' => false, 'linked' => false, 'error' => 'This browser isn\'t linked to a PS5. Link it first.']);
    return $dev;
};

if ($a === 'send' && $post) {
    $dev = $linked();
    $kind = $s('kind', 16);
    $raw = $in['data'] ?? '';
    $data = is_array($raw) ? $raw : json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($data)) $data = [];
    if ($kind === 'install') {
        $app = (string)($data['app'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.-]{3,48}$/', $app)) rs_out(422, ['ok' => false, 'error' => 'That isn\'t an app Recomp Store knows.']);
        $data = ['app' => $app];
    } elseif ($kind === 'source') {
        $repo = rtrim((string)preg_replace('#^https://(www\.)?github\.com/#i', '', trim((string)($data['repo'] ?? ''))), '/');
        $list = trim((string)($data['list'] ?? ''));
        if ($repo !== '' && rs_repo_ok($repo)) $data = ['repo' => $repo];
        elseif ($list !== '' && strlen($list) <= 500 && preg_match('#^https://[^\s<>"]+$#', $list)) $data = ['list' => $list];
        else rs_out(422, ['ok' => false, 'error' => 'Enter a GitHub repo like owner/name, or a list\'s https:// address.']);
        $what = $data['repo'] ?? $data['list'];
        if (rs_source_blocked(isset($data['repo']) ? 'repo' : 'list', $what)) rs_out(200, ['ok' => false, 'blocked' => true, 'error' => 'This source was taken down.']);
    } else {
        rs_out(400, ['ok' => false, 'error' => 'Unknown request.']);
    }
    if (rs_rate_over('send', 60, 3600)) rs_out(429, ['ok' => false, 'error' => 'That\'s a lot at once. Wait a bit and try again.']);
    rs_rate_hit('send');
    $st = $db->prepare('SELECT COUNT(*) FROM link_queue WHERE install_id = ? AND taken IS NULL');
    $st->execute([$dev['install_id']]);
    if ((int)$st->fetchColumn() >= 20) rs_out(200, ['ok' => false, 'error' => 'Your PS5 has 20 things waiting already. Open Recomp Store on it so it can catch up.']);
    rs_link_queue($db, $dev['install_id'], $dev['device_id'], $kind, $data);
    rs_out(200, ['ok' => true, 'message' => 'Sent. Your PS5 picks it up in a few seconds while Recomp Store is open.']);
}

if ($a === 'redeem' && $post) {
    // A beta code typed on the phone, for the linked console (the same rules as on the PS5).
    $dev = $linked();
    $ip = rs_ip_hash();
    rs_beta_tables();
    $st = $db->prepare('SELECT COUNT(*) FROM beta_tries WHERE ip_hash = ? AND at > (NOW() - INTERVAL 1 HOUR)');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= 10) rs_out(429, ['ok' => false, 'error' => 'Too many tries. Wait an hour and try again.']);
    $wrong = function (string $msg) use ($db, $ip): void {
        $db->prepare('INSERT INTO beta_tries (ip_hash, at) VALUES (?, NOW())')->execute([$ip]);
        rs_out(200, ['ok' => false, 'error' => $msg]);
    };
    $code = preg_replace('/\D/', '', $s('code', 32)) ?? '';
    if (strlen($code) !== 8) $wrong('A code is 8 numbers, like 12-345-678.');
    $st = $db->prepare('SELECT * FROM beta_codes WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    if (!$row) $wrong('That code isn\'t right. Check the numbers and try again.');
    if ((int)$row['revoked']) rs_out(200, ['ok' => false, 'error' => 'That code has been cancelled.']);
    $g = rs_game((string)$row['game_id']);
    if (!$g) rs_out(200, ['ok' => false, 'error' => 'That code\'s game isn\'t in Recomp Store anymore.']);
    $install = (string)$dev['install_id'];
    if ($row['install_id'] !== null && $row['install_id'] !== $install) rs_out(200, ['ok' => false, 'error' => 'That code was already used on another console.']);
    if ($row['install_id'] === null) {
        $up = $db->prepare('UPDATE beta_codes SET install_id = ?, redeemed = NOW() WHERE code = ? AND install_id IS NULL');
        $up->execute([$install, $code]);
        if ($up->rowCount() !== 1) rs_out(200, ['ok' => false, 'error' => 'That code was already used on another console.']);
    }
    rs_link_queue($db, $install, $dev['device_id'], 'refresh', []);
    rs_out(200, ['ok' => true, 'game' => ['id' => $g['id'], 'name' => $g['name']],
                 'message' => $g['beta'] ? $g['name'] . ' is unlocked on your PS5. It shows up in the Library in a few seconds while Recomp Store is open.'
                                         : $g['name'] . ' is out for everyone now. Find it in the store.']);
}

if ($a === 'forget' && $post) {
    $fromSite();
    $dev = rs_device($db);
    if ($dev) {
        $db->prepare("DELETE FROM link_codes WHERE device_id = ? AND used IS NULL")->execute([$dev['device_id']]);
        $db->prepare('DELETE FROM link_devices WHERE device_id = ?')->execute([$dev['device_id']]);
    }
    rs_device_cookie('', time() - 3600);
    rs_out(200, ['ok' => true]);
}

rs_out(400, ['ok' => false, 'error' => 'Unknown request.']);
