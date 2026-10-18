<?php
// Recomp Store admin: a beta zip dropped on a game's Beta card, uploaded in pieces so a zip of
// any size gets through (the server's upload limit is per request, not per file).
//   POST ?a=start   {game, name, size}           -> {token, chunk}
//   POST ?a=chunk   &token=&offset=  (raw bytes)  -> {received}
//   POST ?a=finish  {token, version}             -> {name, size, sha256, label}
//   POST ?a=cancel  {token}
// The pieces go into a .part file in the beta folder; finished, it's checked (a zip, the size
// it was meant to be) and named <ID>-<beta version>.zip. Saving the game then makes it the beta.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

rs_session();
if (empty($_SESSION['uid'])) out(401, ['ok' => false, 'error' => 'Sign in to the admin again, then drop the zip again.']);
$t = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) out(400, ['ok' => false, 'error' => 'This page expired. Reload it and drop the zip again.']);
session_write_close(); // the pieces mustn't hold up the rest of the admin
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'Not like that.']);

const RS_CHUNK_MAX = 8 << 20;
const RS_BETA_MAX = 16 << 30; // 16 GB

function bytes_of(string $v): int {
    $v = trim($v); $n = (float)$v; $u = strtolower(substr($v, -1));
    return (int)($n * ($u === 'g' ? 1 << 30 : ($u === 'm' ? 1 << 20 : ($u === 'k' ? 1 << 10 : 1))));
}
// A piece fits in one request with room to spare.
function chunk_size(): int {
    $post = bytes_of((string)ini_get('post_max_size'));
    return max(256 << 10, min(RS_CHUNK_MAX, $post > 0 ? intdiv($post, 2) : RS_CHUNK_MAX));
}

$a = (string)($_GET['a'] ?? '');
$in = $a === 'chunk' ? [] : (json_decode((string)file_get_contents('php://input'), true) ?: []);
$dir = rs_beta_dir_ready();
$token = preg_replace('/[^a-f0-9]/', '', (string)($a === 'chunk' ? ($_GET['token'] ?? '') : ($in['token'] ?? '')));
$part = $dir . '/.upload-' . $token . '.part';
$meta = $dir . '/.upload-' . $token . '.json';

try {
    if ($a === 'start') {
        // Leftovers from uploads that never finished, a day old or more.
        foreach (glob($dir . '/.upload-*') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
        $game = (string)($in['game'] ?? '');
        $size = (int)($in['size'] ?? 0);
        $name = basename((string)($in['name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $game)) out(422, ['ok' => false, 'error' => 'Save the game once (it needs a title ID) before uploading its beta.']);
        if (!preg_match('/\.zip$/i', $name)) out(422, ['ok' => false, 'error' => 'The beta has to be a .zip.']);
        if ($size <= 0 || $size > RS_BETA_MAX) out(422, ['ok' => false, 'error' => 'That zip is empty or too big.']);
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $size + (200 << 20)) out(507, ['ok' => false, 'error' => 'There isn\'t room for it on the server (' . rs_human_size((int)$free) . ' free).']);
        $token = bin2hex(random_bytes(12));
        $part = $dir . '/.upload-' . $token . '.part';
        $meta = $dir . '/.upload-' . $token . '.json';
        if (file_put_contents($part, '') === false) throw new RuntimeException('The beta folder can\'t be written.');
        file_put_contents($meta, json_encode(['game' => $game, 'name' => $name, 'size' => $size]));
        out(200, ['ok' => true, 'token' => $token, 'chunk' => chunk_size()]);
    }

    if ($token === '' || !is_file($part) || !is_file($meta)) out(404, ['ok' => false, 'error' => 'That upload isn\'t here anymore. Drop the zip again.']);
    $m = json_decode((string)file_get_contents($meta), true) ?: [];

    if ($a === 'chunk') {
        $offset = (int)($_GET['offset'] ?? -1);
        clearstatcache(true, $part);
        $have = filesize($part);
        $body = fopen('php://input', 'rb');
        $data = stream_get_contents($body, RS_CHUNK_MAX + 1);
        fclose($body);
        if ($data === false || $data === '' || strlen($data) > RS_CHUNK_MAX) out(400, ['ok' => false, 'error' => 'A piece of the zip didn\'t arrive whole.']);
        // A piece sent again after a dropped answer: already here.
        if ($offset >= 0 && $offset + strlen($data) <= $have) out(200, ['ok' => true, 'received' => $have]);
        if ($offset !== $have) out(409, ['ok' => false, 'error' => 'The pieces got out of order.', 'received' => $have]);
        if ($have + strlen($data) > (int)$m['size']) out(400, ['ok' => false, 'error' => 'The zip is bigger than it said.']);
        $fh = fopen($part, 'ab');
        $ok = $fh && fwrite($fh, $data) === strlen($data);
        if ($fh) fclose($fh);
        if (!$ok) throw new RuntimeException('The server ran out of room while saving the zip.');
        touch($meta);
        out(200, ['ok' => true, 'received' => $have + strlen($data)]);
    }

    if ($a === 'cancel') {
        @unlink($part); @unlink($meta);
        out(200, ['ok' => true]);
    }

    if ($a === 'finish') {
        clearstatcache(true, $part);
        if (filesize($part) !== (int)$m['size']) out(409, ['ok' => false, 'error' => 'Not all of the zip arrived. Drop it again.']);
        if (file_get_contents($part, false, null, 0, 4) !== "PK\x03\x04") { @unlink($part); @unlink($meta); out(422, ['ok' => false, 'error' => 'That file isn\'t a zip.']); }
        @set_time_limit(600);
        // Named after the game and its beta version, never over a zip already there.
        $version = trim((string)($in['version'] ?? ''));
        $stem = preg_replace('/[^A-Za-z0-9._-]/', '', $m['game'] . '-' . ($version !== '' ? $version : pathinfo($m['name'], PATHINFO_FILENAME)));
        $name = $stem . '.zip';
        for ($i = 2; is_file($dir . '/' . $name); $i++) $name = $stem . '-' . $i . '.zip';
        $sha = hash_file('sha256', $part);
        if (!rename($part, $dir . '/' . $name)) throw new RuntimeException('The zip couldn\'t be put in the beta folder.');
        @unlink($meta);
        rs_beta_remember_hash($dir . '/' . $name, $sha);
        $size = (int)$m['size'];
        out(200, ['ok' => true, 'name' => $name, 'size' => $size, 'sha256' => $sha,
                  'label' => $name . ' (' . rs_human_size($size) . ', ' . date('M j') . ')']);
    }
    out(400, ['ok' => false, 'error' => 'Not something this does.']);
} catch (Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage()]);
}
