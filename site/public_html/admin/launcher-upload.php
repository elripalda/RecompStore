<?php
// RecompStore admin (3.1g): a RecompLauncher build dropped on the Launcher beta page, uploaded in
// pieces so a build of any size gets through (the server's upload limit is per request).
//   POST ?a=start   {name, size}                                  -> {token, chunk}
//   POST ?a=chunk   &token=&offset=  (raw bytes)                   -> {received}
//   POST ?a=finish  {token, version, platform, notes, current}     -> {id}
//   POST ?a=cancel  {token}
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/betawave.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function out(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

rs_session();
if (empty($_SESSION['uid'])) out(401, ['ok' => false, 'error' => 'Sign in to the admin again, then drop the build again.']);
$t = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) out(400, ['ok' => false, 'error' => 'This page expired. Reload it and drop the build again.']);
session_write_close();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(405, ['ok' => false, 'error' => 'Not like that.']);

const RS_LB_CHUNK_MAX = 8 << 20;
const RS_LB_MAX = 4 << 30; // 4 GB

function lb_bytes(string $v): int {
    $v = trim($v); $n = (float)$v; $u = strtolower(substr($v, -1));
    return (int)($n * ($u === 'g' ? 1 << 30 : ($u === 'm' ? 1 << 20 : ($u === 'k' ? 1 << 10 : 1))));
}
function lb_chunk(): int {
    $post = lb_bytes((string)ini_get('post_max_size'));
    return max(256 << 10, min(RS_LB_CHUNK_MAX, $post > 0 ? intdiv($post, 2) : RS_LB_CHUNK_MAX));
}

$a = (string)($_GET['a'] ?? '');
$in = $a === 'chunk' ? [] : (json_decode((string)file_get_contents('php://input'), true) ?: []);
try {
    $dir = rs_lb_dir();
    $token = preg_replace('/[^a-f0-9]/', '', (string)($a === 'chunk' ? ($_GET['token'] ?? '') : ($in['token'] ?? '')));
    $part = $dir . '/.upload-' . $token . '.part';
    $meta = $dir . '/.upload-' . $token . '.json';

    if ($a === 'start') {
        foreach (glob($dir . '/.upload-*') ?: [] as $old) if (filemtime($old) < time() - 86400) @unlink($old);
        $size = (int)($in['size'] ?? 0);
        $name = basename((string)($in['name'] ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, RS_LB_EXT, true)) out(422, ['ok' => false, 'error' => 'A build is an .exe, .msi, .zip, .7z, .AppImage, .dmg or .tar.gz.']);
        if ($size <= 0 || $size > RS_LB_MAX) out(422, ['ok' => false, 'error' => 'That file is empty or too big.']);
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $size + (200 << 20)) out(507, ['ok' => false, 'error' => 'There isn\'t room for it on the server (' . rs_human_size((int)$free) . ' free).']);
        $token = bin2hex(random_bytes(12));
        $part = $dir . '/.upload-' . $token . '.part';
        $meta = $dir . '/.upload-' . $token . '.json';
        if (file_put_contents($part, '') === false) throw new RuntimeException('The builds folder can\'t be written.');
        file_put_contents($meta, json_encode(['name' => $name, 'ext' => $ext, 'size' => $size]));
        out(200, ['ok' => true, 'token' => $token, 'chunk' => lb_chunk()]);
    }

    if ($token === '' || !is_file($part) || !is_file($meta)) out(404, ['ok' => false, 'error' => 'That upload isn\'t here anymore. Drop the build again.']);
    $m = json_decode((string)file_get_contents($meta), true) ?: [];

    if ($a === 'chunk') {
        $offset = (int)($_GET['offset'] ?? -1);
        clearstatcache(true, $part);
        $have = filesize($part);
        $data = stream_get_contents(fopen('php://input', 'rb'), RS_LB_CHUNK_MAX + 1);
        if ($data === false || $data === '' || strlen($data) > RS_LB_CHUNK_MAX) out(400, ['ok' => false, 'error' => 'A piece of the build didn\'t arrive whole.']);
        if ($offset >= 0 && $offset + strlen($data) <= $have) out(200, ['ok' => true, 'received' => $have]);
        if ($offset !== $have) out(409, ['ok' => false, 'error' => 'The pieces got out of order.', 'received' => $have]);
        if ($have + strlen($data) > (int)$m['size']) out(400, ['ok' => false, 'error' => 'The build is bigger than it said.']);
        $fh = fopen($part, 'ab');
        $ok = $fh && fwrite($fh, $data) === strlen($data);
        if ($fh) fclose($fh);
        if (!$ok) throw new RuntimeException('The server ran out of room while saving the build.');
        touch($meta);
        out(200, ['ok' => true, 'received' => $have + strlen($data)]);
    }

    if ($a === 'cancel') { @unlink($part); @unlink($meta); out(200, ['ok' => true]); }

    if ($a === 'finish') {
        clearstatcache(true, $part);
        if (filesize($part) !== (int)$m['size']) out(409, ['ok' => false, 'error' => 'Not all of the build arrived. Drop it again.']);
        $version = mb_substr(trim((string)($in['version'] ?? '')), 0, 40);
        if ($version === '') out(422, ['ok' => false, 'error' => 'Give the build a version, like 0.5 Beta 1.']);
        $platform = (string)($in['platform'] ?? 'windows');
        if (!isset(RS_LB_PLATFORMS[$platform])) $platform = 'windows';
        @set_time_limit(900);
        // The file keeps the name it was uploaded with, in a folder of its own.
        $orig = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$m['name']);
        $sub = gmdate('Ymd-His') . '-' . substr($token, 0, 6);
        if (!@mkdir($dir . '/' . $sub, 0755)) throw new RuntimeException('The builds folder can\'t be written.');
        $name = $sub . '/' . $orig;
        $sha = hash_file('sha256', $part);
        if (!rename($part, $dir . '/' . $name)) throw new RuntimeException('The build couldn\'t be put in the builds folder.');
        @unlink($meta);
        // On Cloudflare R2 too when it's set up, so downloads come from there.
        $key = '';
        $note = '';
        $r2 = rs_r2();
        if ($r2) {
            $err = '';
            if (rs_r2_put_file($r2, 'launcher/' . $name, $dir . '/' . $name, $err)) $key = 'launcher/' . $name;
            else $note = 'Saved, but the copy on Cloudflare R2 didn\'t work (' . $err . '). Downloads come from this server.';
        }
        rs_lb_tables();
        rs_db()->prepare('INSERT INTO launcher_builds (version, platform, file, size, sha256, notes, r2_key, created) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$version, $platform, $name, (int)$m['size'], $sha, mb_substr(trim((string)($in['notes'] ?? '')), 0, 4000), $key]);
        $id = (int)rs_db()->lastInsertId();
        if (!empty($in['current'])) rs_lb_make_current($id);
        rs_log('launcher-build', $version, $platform . ', ' . rs_human_size((int)$m['size']) . (!empty($in['current']) ? ', current' : ''));
        out(200, ['ok' => true, 'id' => $id, 'note' => $note]);
    }
    out(400, ['ok' => false, 'error' => 'Not something this does.']);
} catch (Throwable $e) {
    out(500, ['ok' => false, 'error' => $e->getMessage()]);
}
