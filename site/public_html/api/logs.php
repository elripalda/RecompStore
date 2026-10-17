<?php
// Recomp Store website: logs a player sends from the PS5 store (Recomp Store 1.1.0 and newer),
// from a game's page: the store's log, the game's own log files and an optional message.
// They land in the admin's Inbox under Logs. Form POST in, JSON out.
//   app, version (the game's, as installed), store (the store's version), message,
//   install + token (a beta tester's console, when it has one), name[] + text[] (the files)
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_reply(405, ['ok' => false, 'error' => 'Send logs from Recomp Store.']);

$s = function (string $k, int $max): string {
    $v = isset($_POST[$k]) && is_string($_POST[$k]) ? trim($_POST[$k]) : '';
    return mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($v, 'UTF-8')) ?? '', 0, $max);
};

$app = $s('app', 32);
if ($app !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $app)) rs_reply(422, ['ok' => false, 'error' => 'That isn\'t a game in Recomp Store.']);
$store = preg_replace('/[^0-9A-Za-z.\-]/', '', $s('store', 32));
$version = $s('version', 64);
$message = $s('message', 2000);

// The files: at most 10, each kept to its last 400 KB, 2 MB in all.
$names = is_array($_POST['name'] ?? null) ? array_values($_POST['name']) : [];
$texts = is_array($_POST['text'] ?? null) ? array_values($_POST['text']) : [];
$files = [];
$total = 0;
foreach ($names as $i => $n) {
    if (count($files) >= 10 || !is_string($n) || !isset($texts[$i]) || !is_string($texts[$i])) continue;
    $n = preg_replace('#[^A-Za-z0-9 ._()/+-]#', '_', trim($n));
    $n = trim(preg_replace('#(^|/)\.\.?(?=/|$)#', '', $n), '/ ');
    if ($n === '') $n = 'log-' . ($i + 1) . '.txt';
    $t = $texts[$i];
    if (strlen($t) > 400000) $t = substr($t, -400000);
    $t = mb_scrub($t, 'UTF-8');
    if ($total + strlen($t) > 2000000) break;
    $total += strlen($t);
    $files[] = ['name' => mb_substr($n, 0, 160), 'body' => $t];
}
if (!$files && $message === '') rs_reply(422, ['ok' => false, 'error' => 'There was nothing to send.']);

try {
    rs_inbox_table();
    $db = rs_db();

    // A beta tester's console, when the store sent its ID and token.
    $console = '';
    $install = $s('install', 16);
    $token = $s('token', 32);
    if (preg_match('/^[0-9a-f]{16}$/', $install) && preg_match('/^[0-9a-f]{32}$/', $token)) {
        rs_beta_tables();
        $st = $db->prepare('SELECT token_hash FROM beta_installs WHERE install_id = ?');
        $st->execute([$install]);
        $h = $st->fetchColumn();
        if (is_string($h) && hash_equals($h, hash('sha256', $token))) $console = $install;
    }

    $ip = rs_ip_hash();
    // Ten an hour from one address, and 120 an hour from everyone together.
    $got = (int)$db->query("SELECT GET_LOCK('rs_inbox', 5)")->fetchColumn();
    if ($got !== 1) rs_reply(503, ['ok' => false, 'error' => 'recompstore.com is busy. Try again in a moment.']);
    try {
        $st = $db->prepare("SELECT COUNT(*) FROM inbox WHERE kind = 'logs' AND ip_hash = ? AND created > (NOW() - INTERVAL 1 HOUR)");
        $st->execute([$ip]);
        $mine = (int)$st->fetchColumn();
        $all = (int)$db->query("SELECT COUNT(*) FROM inbox WHERE kind = 'logs' AND created > (NOW() - INTERVAL 1 HOUR)")->fetchColumn();
        if ($mine >= 10 || $all >= 120) {
            $db->query("SELECT RELEASE_LOCK('rs_inbox')");
            rs_reply(429, ['ok' => false, 'error' => 'That\'s a lot of logs at once. Try again in an hour.']);
        }
        $db->beginTransaction();
        $db->prepare('INSERT INTO inbox (kind, app_id, name, contact, repo, version, body, status, ip_hash, created)
                      VALUES (\'logs\', ?, ?, ?, \'\', ?, ?, \'new\', ?, NOW())')
           ->execute([$app === '' ? 'store' : $app, $store === '' ? '' : 'Recomp Store ' . $store, $console, $version, $message, $ip]);
        $id = (int)$db->lastInsertId();
        $put = $db->prepare('INSERT INTO inbox_files (inbox_id, name, size, body) VALUES (?, ?, ?, ?)');
        foreach ($files as $f) $put->execute([$id, $f['name'], strlen($f['body']), $f['body']]);
        $db->commit();
    } finally {
        if ($db->inTransaction()) $db->rollBack();
        $db->query("SELECT RELEASE_LOCK('rs_inbox')");
    }
} catch (Throwable $e) {
    rs_reply(500, ['ok' => false, 'error' => 'The logs couldn\'t be saved right now. Try again later.']);
}
rs_reply(200, ['ok' => true, 'message' => 'Sent. Thanks for helping.']);
