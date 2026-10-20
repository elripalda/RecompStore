<?php
// Recomp Store website (2.1): a developer submits a game port, emulator, app or tool for the store,
// with its GitHub repo, the details for its page and, if they want, its pictures. It lands in the
// admin's Inbox under Submissions, where it can be added to the store as a hidden draft.
//   POST multipart: kind, name, author, contact, repo, asset, title_id, version, blurb, description,
//   needs, credits, link1..3, notes, rights=1, website (honeypot), icon, hero, logo, shots[] (up to 4)
//   -> {ok, message} or {ok:false, error}
declare(strict_types=1);
require dirname(__DIR__) . '/admin/inc/bootstrap.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rs_sub_out(int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') rs_sub_out(405, ['ok' => false, 'error' => 'Send the form.']);
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== parse_url('http://' . $host, PHP_URL_HOST)) rs_sub_out(403, ['ok' => false, 'error' => 'Send it from recompstore.com.']);
if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) rs_sub_out(413, ['ok' => false, 'error' => 'The pictures are too big together. Send smaller ones.']);

$s = function (string $k, int $max): string {
    $v = isset($_POST[$k]) && is_string($_POST[$k]) ? trim(str_replace("\r\n", "\n", $_POST[$k])) : '';
    return mb_substr(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $v) ?? '', 0, $max);
};
if ($s('website', 200) !== '') rs_sub_out(200, ['ok' => true, 'message' => 'Thanks! It\'s in.']); // a bot

$kind = $s('kind', 16);
$kinds = ['games' => 'game port', 'emulators' => 'emulator', 'apps' => 'app', 'tools' => 'tool'];
$d = [
    'category' => isset($kinds[$kind]) ? $kind : '',
    'name' => $s('name', 120), 'author' => $s('author', 80), 'contact' => $s('contact', 200),
    'repo' => rtrim((string)preg_replace('#^https?://(www\.)?github\.com/#i', '', $s('repo', 255)), '/'),
    'asset' => $s('asset', 160), 'title_id' => strtoupper($s('title_id', 16)), 'version' => $s('version', 40),
    'blurb' => $s('blurb', 160), 'description' => $s('description', 6000), 'needs' => $s('needs', 160),
    'credits' => $s('credits', 1000), 'notes' => $s('notes', 2000), 'links' => [],
];
foreach (['link1', 'link2', 'link3'] as $k) {
    $l = $s($k, 300);
    if ($l === '') continue;
    if (!preg_match('#^https://[^\s<>"]{3,290}$#', $l)) rs_sub_out(422, ['ok' => false, 'error' => 'Links have to start with https://']);
    $d['links'][] = $l;
}
$d['repo'] = preg_replace('#\.git$#', '', $d['repo']);
if ($d['category'] === '') rs_sub_out(422, ['ok' => false, 'error' => 'Pick what it is: a game port, an emulator, an app or a tool.']);
if (mb_strlen($d['name']) < 2) rs_sub_out(422, ['ok' => false, 'error' => 'Give it a name.']);
if (mb_strlen($d['author']) < 2) rs_sub_out(422, ['ok' => false, 'error' => 'Say who made it (your name or handle).']);
if (mb_strlen($d['contact']) < 3) rs_sub_out(422, ['ok' => false, 'error' => 'Leave an email or a Discord name so we can reach you.']);
if (!preg_match('#^[A-Za-z0-9_.-]{1,39}/[A-Za-z0-9_.-]{1,100}$#', $d['repo'])) rs_sub_out(422, ['ok' => false, 'error' => 'The GitHub repo goes like github.com/you/your-app.']);
if ($d['title_id'] !== '' && !preg_match('/^[A-Z]{4}\d{5}$/', $d['title_id'])) rs_sub_out(422, ['ok' => false, 'error' => 'A title ID is four letters and five numbers, like PPSA12345. Leave it empty if you don\'t have one yet.']);
if ($d['asset'] !== '' && !preg_match('/\.(zip|7z)$/i', $d['asset'])) rs_sub_out(422, ['ok' => false, 'error' => 'The release file is the .zip or .7z on your GitHub release, like MyApp-*.zip.']);
if (mb_strlen($d['description']) < 20) rs_sub_out(422, ['ok' => false, 'error' => 'Tell players a bit more about it in the description.']);
if (($_POST['rights'] ?? '') !== '1') rs_sub_out(422, ['ok' => false, 'error' => 'Tick the box to confirm you made it or may submit it.']);

// Pictures, all optional: an icon, a backdrop, a logo and up to 4 screenshots. PNG, JPEG or WebP,
// 8 MB each, checked to really be pictures.
$pics = [];
$take = function (string $role, string $tmp, int $err, int $size) use (&$pics) {
    if ($err === UPLOAD_ERR_NO_FILE) return;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || $size > (8 << 20)) rs_sub_out(422, ['ok' => false, 'error' => 'Each picture can be 8 MB at most.']);
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) rs_sub_out(422, ['ok' => false, 'error' => 'A picture didn\'t arrive. Try again.']);
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($types[$mime]) || !$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
        || $info[0] < 16 || $info[1] < 16 || $info[0] > 12000 || $info[1] > 12000) rs_sub_out(422, ['ok' => false, 'error' => 'Pictures have to be PNG, JPEG or WebP.']);
    $pics[] = ['role' => $role, 'tmp' => $tmp, 'mime' => $mime, 'ext' => $types[$mime], 'size' => $size, 'w' => (int)$info[0], 'h' => (int)$info[1]];
};
foreach (['icon', 'hero', 'logo'] as $role) {
    $f = $_FILES[$role] ?? null;
    if (is_array($f) && !is_array($f['name'] ?? null)) $take($role, (string)$f['tmp_name'], (int)$f['error'], (int)$f['size']);
}
$f = $_FILES['shots'] ?? null;
if (is_array($f) && is_array($f['name'] ?? null)) {
    $n = 0;
    foreach ($f['name'] as $i => $_) {
        if ((int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (++$n > 4) rs_sub_out(422, ['ok' => false, 'error' => 'Four screenshots at most.']);
        $take('shot', (string)$f['tmp_name'][$i], (int)$f['error'][$i], (int)$f['size'][$i]);
    }
}

try {
    $db = rs_db();
    rs_inbox_table();
    rs_report_tables();
    rs_submission_columns();
    // Limits: 5 an hour from one address, 60 an hour from everyone.
    if (!$db->query("SELECT GET_LOCK('rs_inbox', 5)")->fetchColumn()) rs_sub_out(503, ['ok' => false, 'error' => 'Busy. Try again in a moment.']);
    $ip = rs_ip_hash();
    $mine = $db->prepare('SELECT COUNT(*) FROM inbox WHERE ip_hash = ? AND created > (NOW() - INTERVAL 1 HOUR)');
    $mine->execute([$ip]);
    $all = (int)$db->query('SELECT COUNT(*) FROM inbox WHERE created > (NOW() - INTERVAL 1 HOUR)')->fetchColumn();
    if ((int)$mine->fetchColumn() >= 5 || $all >= 60) { $db->query("SELECT RELEASE_LOCK('rs_inbox')"); rs_sub_out(429, ['ok' => false, 'error' => 'Lots of messages right now. Try again in an hour.']); }

    $dir = $pics ? rs_report_dir_ready() : '';
    $db->beginTransaction();
    $db->prepare('INSERT INTO inbox (kind, app_id, name, contact, repo, version, body, status, ip_hash, created, data) VALUES (\'submit\', ?, ?, ?, ?, ?, ?, \'new\', ?, NOW(), ?)')
       ->execute([$d['title_id'], $d['name'], $d['contact'], 'https://github.com/' . $d['repo'], $d['version'], $d['description'], $ip,
           json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    $id = (int)$db->lastInsertId();
    $put = $db->prepare('INSERT INTO inbox_pictures (inbox_id, file, mime, size, w, h, created, role) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)');
    foreach ($pics as $k => $p) {
        $file = 'sub' . $id . '-' . $p['role'] . '-' . $k . '-' . bin2hex(random_bytes(6)) . '.' . $p['ext'];
        if (!move_uploaded_file($p['tmp'], $dir . '/' . $file)) throw new RuntimeException('A picture couldn\'t be saved.');
        $put->execute([$id, $file, $p['mime'], $p['size'], $p['w'], $p['h'], $p['role']]);
    }
    $db->commit();
    $db->query("SELECT RELEASE_LOCK('rs_inbox')");
    rs_sub_out(200, ['ok' => true, 'message' => 'Thanks! ' . $d['name'] . ' is in. If it fits the store, it\'s added with your details, and you\'ll hear back at ' . $d['contact'] . '.']);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    rs_sub_out(503, ['ok' => false, 'error' => 'It didn\'t go through. Try again in a moment.']);
}
