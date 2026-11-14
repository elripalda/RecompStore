<?php
// RecompLauncher (4.10): emergency backup downloads, for a game whose repo is down and that was
// switched to its backup (pc-catalog.json marks it "backup": true). Nothing here for other games.
//   GET ?id=X          -> {ok, id, version, tag, files: [{name, os, size, sha256, url}]}
//   GET ?get=X&f=NAME  -> 302 to the file on Backblaze (a link good for 15 minutes)
// os is windows, linux or macos. Never for a game that was taken down.
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';
require_once dirname(__DIR__) . '/admin/inc/backup.php';

header('Access-Control-Allow-Origin: *');
header('X-Robots-Tag: noindex');
$idOk = fn(string $s): bool => (bool)preg_match('/^[a-z0-9-]{1,100}$/', $s);
$fail = function (int $code, string $why): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(['ok' => false, 'error' => $why]);
    exit;
};
$usable = function (string $id) use ($fail): array {
    $r = rs_bk_row($id);
    if (!$r || !(int)$r['active'] || !$r['files']) $fail(404, 'No emergency backup is in use for this game.');
    if (in_array(rs_bk_watch_state($id), RS_BK_NO_TAKEDOWN, true)) $fail(451, 'This game was taken down.');
    return $r;
};

if (isset($_GET['get'])) {
    $id = (string)$_GET['get'];
    $name = (string)($_GET['f'] ?? '');
    if (!$idOk($id)) $fail(400, 'Bad game.');
    $r = $usable($id);
    $hit = null;
    foreach ($r['files'] as $f) if ((string)$f['name'] === $name) { $hit = $f; break; }
    if (!$hit) $fail(404, 'No such file in the backup.');
    if (rs_rate_over('bkget', 40, 3600)) $fail(429, 'Too many downloads from here. Try again in a bit.');
    rs_rate_hit('bkget');
    rs_db()->prepare('UPDATE pc_backup SET served = served + 1 WHERE app_id = ?')->execute([$id]);
    if (!rs_b2_on()) $fail(503, 'The backup storage isn\'t reachable right now.');
    header('Cache-Control: no-store');
    header('Location: ' . rs_b2_link((string)$hit['key'], (string)$hit['name'], 900, 'application/octet-stream'), true, 302);
    exit;
}

$id = (string)($_GET['id'] ?? '');
if (!$idOk($id)) $fail(400, 'Bad game.');
$r = $usable($id);
$files = array_map(fn($f) => ['name' => (string)$f['name'], 'os' => (string)$f['os'], 'size' => (int)$f['size'], 'sha256' => (string)$f['sha256'],
    'url' => 'https://recomplauncher.com/api/backup.php?get=' . rawurlencode($id) . '&f=' . rawurlencode((string)$f['name'])], $r['files']);
header('Content-Type: application/json');
header('Cache-Control: public, max-age=60');
echo json_encode(['ok' => true, 'id' => $id, 'version' => (string)$r['version'], 'tag' => (string)$r['tag'], 'files' => $files], JSON_UNESCAPED_SLASHES);
