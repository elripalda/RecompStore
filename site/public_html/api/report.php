<?php
// Recomp Store website (2.0): a bug report finished on a phone. The store sends its logs from a
// game's page (api/logs.php) and shows a code; recompstore.com/r/<code> opens a form for what
// happened and a few pictures, which join those logs in the admin's Inbox.
//   GET  a=info    code              -> {ok, app:{id,name}, version, sent, finished}
//   POST a=finish  code what [before] [contact] pictures[] (multipart)  -> {ok, message}
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
$s = function (string $k, int $max) use ($in): string {
    $v = isset($in[$k]) && is_string($in[$k]) ? trim($in[$k]) : '';
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($v, 'UTF-8')) ?? '';
    return mb_substr($v, 0, $max);
};
$a = $s('a', 16);

try {
    $db = rs_db();
    rs_report_tables();
} catch (Throwable $e) {
    rs_out(503, ['ok' => false, 'error' => 'Reports can\'t be taken right now. Try again later.']);
}

// The report a code belongs to, when it's still good.
$find = function (string $code) use ($db): ?array {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    if (strlen($code) !== 6) return null;
    $st = $db->prepare('SELECT r.code, r.inbox_id, r.finished, UNIX_TIMESTAMP(r.created) created, i.app_id, i.version, i.contact
                        FROM report_codes r JOIN inbox i ON i.id = r.inbox_id WHERE r.code = ? AND r.expires > NOW()');
    $st->execute([$code]);
    $r = $st->fetch();
    return $r ?: null;
};
$appName = function (string $id): string {
    if ($id === 'store' || $id === '') return 'Recomp Store';
    $g = rs_game($id);
    return $g ? (string)$g['name'] : $id;
};

if ($a === 'info') {
    if (rs_rate_over('report-info', 60, 3600)) rs_out(429, ['ok' => false, 'error' => 'Too many tries. Wait a bit and try again.']);
    $r = $find($s('code', 16));
    if (!$r) {
        rs_rate_hit('report-info');
        rs_out(200, ['ok' => false, 'error' => 'That report code didn\'t work. It may be more than a week old: send the logs again from the store.']);
    }
    rs_out(200, ['ok' => true, 'app' => ['id' => $r['app_id'], 'name' => $appName((string)$r['app_id'])], 'version' => (string)$r['version'],
                 'sent' => gmdate('Y-m-d\TH:i:s\Z', (int)$r['created']), 'finished' => $r['finished'] !== null]);
}

if ($a === 'finish' && $post) {
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== $host) rs_out(403, ['ok' => false, 'error' => 'Send the report from recompstore.com.']);
    if (rs_rate_over('report', 20, 3600)) rs_out(429, ['ok' => false, 'error' => 'That\'s a lot of reports at once. Try again in an hour.']);
    rs_rate_hit('report');
    // A form too big for the server arrives empty.
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) rs_out(413, ['ok' => false, 'error' => 'The pictures are too big together. Send fewer or smaller ones.']);
    $r = $find($s('code', 16));
    if (!$r) rs_out(200, ['ok' => false, 'error' => 'That report code didn\'t work. It may be more than a week old: send the logs again from the store.']);
    if ($r['finished'] !== null) rs_out(200, ['ok' => false, 'error' => 'This report is already finished. Thanks!']);
    $what = $s('what', 5000);
    $before = $s('before', 5000);
    $contact = $s('contact', 200);
    if (mb_strlen($what) < 10) rs_out(422, ['ok' => false, 'error' => 'Tell us a little more about what happened.']);

    // The pictures: up to 4, PNG, JPEG or WebP, 8 MB each, checked to really be pictures.
    $pics = [];
    $f = $_FILES['pictures'] ?? null;
    if (is_array($f) && is_array($f['name'] ?? null)) {
        foreach ($f['name'] as $i => $name) {
            $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if (count($pics) >= 4) rs_out(422, ['ok' => false, 'error' => 'Four pictures at most.']);
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)$f['size'][$i] > (8 << 20)) rs_out(422, ['ok' => false, 'error' => 'Each picture can be 8 MB at most.']);
            if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'][$i])) rs_out(422, ['ok' => false, 'error' => 'A picture didn\'t arrive. Try again.']);
            $tmp = (string)$f['tmp_name'][$i];
            $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $info = @getimagesize($tmp);
            $types = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            if (!isset($types[$mime]) || !$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)
                || $info[0] < 1 || $info[1] < 1 || $info[0] > 12000 || $info[1] > 12000) {
                rs_out(422, ['ok' => false, 'error' => 'Only pictures can be sent: PNG, JPEG or WebP.']);
            }
            $pics[] = ['tmp' => $tmp, 'mime' => $mime, 'ext' => $types[$mime], 'size' => (int)$f['size'][$i], 'w' => (int)$info[0], 'h' => (int)$info[1]];
        }
    }

    try {
        $dir = $pics ? rs_report_dir_ready() : '';
        $db->beginTransaction();
        $up = $db->prepare('UPDATE report_codes SET finished = NOW() WHERE code = ? AND finished IS NULL');
        $up->execute([$r['code']]);
        if ($up->rowCount() !== 1) { $db->rollBack(); rs_out(200, ['ok' => false, 'error' => 'This report is already finished. Thanks!']); }
        $db->prepare('INSERT INTO inbox_reports (inbox_id, what, before_text, contact, finished) VALUES (?, ?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE what = VALUES(what), before_text = VALUES(before_text), contact = VALUES(contact), finished = NOW()')
           ->execute([(int)$r['inbox_id'], $what, $before, $contact]);
        $put = $db->prepare('INSERT INTO inbox_pictures (inbox_id, file, mime, size, w, h, created) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $moved = [];
        foreach ($pics as $p) {
            $file = (int)$r['inbox_id'] . '-' . bin2hex(random_bytes(8)) . '.' . $p['ext'];
            if (!move_uploaded_file($p['tmp'], $dir . '/' . $file)) throw new RuntimeException('move');
            @chmod($dir . '/' . $file, 0644);
            $moved[] = $dir . '/' . $file;
            $put->execute([(int)$r['inbox_id'], $file, $p['mime'], $p['size'], $p['w'], $p['h']]);
        }
        // Back to the top of the Inbox.
        $db->prepare("UPDATE inbox SET status = 'new' WHERE id = ?")->execute([(int)$r['inbox_id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        foreach ($moved ?? [] as $m) @unlink($m);
        rs_out(500, ['ok' => false, 'error' => 'The report couldn\'t be saved right now. Try again later.']);
    }
    // A phone linked to the console that sent the logs: the store says thanks too.
    if ((string)$r['contact'] !== '') {
        $dev = rs_device($db);
        if ($dev && $dev['install_id'] === $r['contact']) rs_link_queue($db, (string)$r['contact'], $dev['device_id'], 'report', ['report' => $r['code']]);
    }
    rs_out(200, ['ok' => true, 'message' => 'Thanks, the report is in. It went straight to the developer with your logs.']);
}

rs_out(400, ['ok' => false, 'error' => 'Unknown request.']);
