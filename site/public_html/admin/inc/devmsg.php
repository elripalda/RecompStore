<?php
// (4.12) Messages with developers, the parts shared by the admin (messages.php) and the developer hub
// (api/dev.php): files sent with a message, the admin's conversation list (waiting for a reply,
// dismissed) and the team's read receipts. Loaded by inc/devhub.php.
declare(strict_types=1);

// What can be sent: pictures, short videos, and logs or small archives. 15 MB a file, 4 a message.
const RS_MSG_FILE_MAX = 15 * 1024 * 1024;
const RS_MSG_FILES_MAX = 4;
const RS_MSG_FILES_TOTAL = 30 * 1024 * 1024;
const RS_MSG_TYPES = [
    'png' => ['image', ['image/png']], 'jpg' => ['image', ['image/jpeg']], 'jpeg' => ['image', ['image/jpeg']],
    'gif' => ['image', ['image/gif']], 'webp' => ['image', ['image/webp']],
    'mp4' => ['video', ['video/mp4']], 'webm' => ['video', ['video/webm']], 'mov' => ['video', ['video/quicktime', 'video/mp4']],
    'txt' => ['file', ['text/plain']], 'log' => ['file', ['text/plain']], 'json' => ['file', ['application/json', 'text/plain']],
    'ini' => ['file', ['text/plain']], 'toml' => ['file', ['text/plain']], 'cfg' => ['file', ['text/plain']],
    'zip' => ['file', ['application/zip']], '7z' => ['file', ['application/x-7z-compressed']],
];

function rs_msg_dir(): string { return dirname(RS_PUBLIC) . '/recompstore-private/msg-files'; }

// $_FILES['files'] (several) as a list of checked files, or an error that says what's wrong.
function rs_msg_files_check($f): array {
    if (!is_array($f) || !isset($f['name'])) return [];
    $names = (array)$f['name'];
    $out = [];
    $total = 0;
    foreach ($names as $i => $name) {
        $err = (int)((array)$f['error'])[$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $name = trim((string)$name);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException($name . ' is too big. Files can be up to 15 MB.');
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException($name . ' didn\'t upload. Try again.');
        $tmp = (string)((array)$f['tmp_name'])[$i];
        $size = (int)((array)$f['size'])[$i];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(RS_MSG_TYPES[$ext])) throw new RuntimeException($name . ': only pictures (PNG, JPG, GIF, WebP), videos (MP4, WebM, MOV), logs and text files, or ZIP and 7z can be sent.');
        if ($size <= 0) throw new RuntimeException($name . ' is empty.');
        if ($size > RS_MSG_FILE_MAX) throw new RuntimeException($name . ' is too big. Files can be up to 15 MB.');
        [$kind, $mimes] = RS_MSG_TYPES[$ext];
        $mime = function_exists('mime_content_type') ? (string)@mime_content_type($tmp) : '';
        // Text files come in many shapes; anything that isn't binary passes as text.
        if ($kind === 'file' && in_array('text/plain', $mimes, true)) {
            if (str_contains((string)file_get_contents($tmp, false, null, 0, 8192), "\0")) throw new RuntimeException($name . ' doesn\'t look like a text file.');
            $mime = 'text/plain';
        } elseif (!in_array($mime, $mimes, true)) throw new RuntimeException($name . ' isn\'t really a .' . $ext . ' file.');
        if ($kind === 'image' && !@getimagesize($tmp)) throw new RuntimeException($name . ' isn\'t a picture we can show.');
        $total += $size;
        if (count($out) >= RS_MSG_FILES_MAX) throw new RuntimeException('Up to ' . RS_MSG_FILES_MAX . ' files a message.');
        if ($total > RS_MSG_FILES_TOTAL) throw new RuntimeException('That\'s more than 30 MB together. Send them in two messages.');
        $clean = trim((string)preg_replace('/[^\w .()+-]+/u', '_', $name), ' ._');
        $out[] = ['tmp' => $tmp, 'name' => mb_substr($clean !== '' ? $clean : 'file.' . $ext, 0, 150), 'ext' => $ext, 'kind' => $kind, 'mime' => $mime, 'size' => $size];
    }
    return $out;
}

// Keeps checked files with a message, and a short list of them on the message itself.
function rs_msg_files_store(int $msgId, array $files): void {
    $db = rs_db();
    $sub = gmdate('Ym');
    $dir = rs_msg_dir() . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) throw new RuntimeException('Couldn\'t keep the files. Try again.');
    $list = [];
    foreach ($files as $f) {
        $tok = bin2hex(random_bytes(16));
        $rel = $sub . '/' . $tok . '.' . $f['ext'];
        if (!@move_uploaded_file($f['tmp'], rs_msg_dir() . '/' . $rel) && !@rename($f['tmp'], rs_msg_dir() . '/' . $rel)) continue;
        @chmod(rs_msg_dir() . '/' . $rel, 0640);
        $db->prepare('INSERT INTO dev_msg_files (token, msg_id, name, mime, kind, size, path, created) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
           ->execute([$tok, $msgId, $f['name'], $f['mime'], $f['kind'], $f['size'], $rel]);
        $list[] = ['t' => $tok, 'name' => $f['name'], 'kind' => $f['kind'], 'size' => $f['size']];
    }
    if ($list) $db->prepare('UPDATE dev_messages SET files = ? WHERE id = ?')->execute([json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $msgId]);
}

function rs_msg_files_of(array $r): array {
    $l = json_decode((string)($r['files'] ?? ''), true);
    return is_array($l) ? array_values(array_filter($l, fn($x) => is_array($x) && preg_match('/^[0-9a-f]{32}$/', (string)($x['t'] ?? '')))) : [];
}

// One file and its message, by token (null if there's none).
function rs_msg_file_get(string $tok): ?array {
    if (!preg_match('/^[0-9a-f]{32}$/', $tok)) return null;
    rs_dev_tables();
    $st = rs_db()->prepare('SELECT f.*, m.channel, m.room, m.account_id, m.from_admin, m.deleted FROM dev_msg_files f JOIN dev_messages m ON m.id = f.msg_id WHERE f.token = ?');
    $st->execute([$tok]);
    return $st->fetch() ?: null;
}

// Sends a file: pictures and videos to show in the page (with ranges, so a video can be skipped
// through), everything else as a download. Never run as a page of our site.
function rs_msg_file_send(array $f): void {
    $path = rs_msg_dir() . '/' . $f['path'];
    if (!is_file($path)) { http_response_code(404); exit('That file is gone.'); }
    $size = (int)filesize($path);
    $show = in_array($f['kind'], ['image', 'video'], true);
    header('Content-Type: ' . ($show ? $f['mime'] : ($f['mime'] === 'text/plain' ? 'text/plain; charset=utf-8' : 'application/octet-stream')));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Content-Disposition: ' . ($show ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode((string)$f['name']));
    header('Cache-Control: private, max-age=86400');
    header('Accept-Ranges: bytes');
    $start = 0; $end = $size - 1;
    if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); } else { $start = (int)$m[1]; if ($m[2] !== '') $end = min($end, (int)$m[2]); }
        if ($start > $end || $start >= $size) { http_response_code(416); header('Content-Range: bytes */' . $size); exit; }
        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Length: ' . ($end - $start + 1));
    $h = fopen($path, 'rb');
    fseek($h, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($h)) { $chunk = fread($h, (int)min(65536, $left)); if ($chunk === false) break; echo $chunk; $left -= strlen($chunk); }
    fclose($h);
    exit;
}

// ---- The admin's conversations ------------------------------------------------------------------
// Everyone the team has a conversation with: the last message (who wrote it, a short preview),
// unread, waiting (the developer wrote last, so they're waiting on us), and dismissed.
function rs_msg_threads(): array {
    rs_dev_tables();
    $rows = rs_db()->query("SELECT a.id, a.name, a.gh_login, a.email, a.avatar, a.slug, a.status, a.platforms, m.last_id, m.last_at, m.unread, m.n,
            l.from_admin AS last_team, l.body AS last_body, l.files AS last_files, COALESCE(t.dismissed, 0) AS dismissed
        FROM dev_accounts a
        JOIN (SELECT account_id, MAX(id) AS last_id, MAX(created) AS last_at, COUNT(*) AS n, SUM(from_admin = 0 AND read_admin = 0 AND deleted = 0) AS unread
              FROM dev_messages WHERE channel = 'dm' AND deleted = 0 GROUP BY account_id) m ON m.account_id = a.id
        JOIN dev_messages l ON l.id = m.last_id
        LEFT JOIN dev_threads t ON t.account_id = a.id
        ORDER BY m.last_id DESC")->fetchAll();
    foreach ($rows as &$r) {
        $r['waiting'] = !(int)$r['last_team'];
        $prev = trim((string)preg_replace('/\s+/', ' ', (string)$r['last_body']));
        if ($prev === '' && rs_msg_files_of(['files' => $r['last_files']])) $prev = 'Sent a file';
        $r['preview'] = ((int)$r['last_team'] ? 'You: ' : '') . mb_strimwidth($prev, 0, 90, '…');
    }
    return $rows;
}

// Developers with an account and no conversation yet (for New message and the bottom of Dismissed).
function rs_msg_quiet(): array {
    rs_dev_tables();
    return rs_db()->query("SELECT a.id, a.name, a.gh_login, a.email, a.avatar, a.slug, a.status FROM dev_accounts a
        WHERE a.status IN ('approved', 'pending') AND NOT EXISTS (SELECT 1 FROM dev_messages m WHERE m.channel = 'dm' AND m.account_id = a.id AND m.deleted = 0)
        ORDER BY a.status = 'approved' DESC, a.name, a.gh_login")->fetchAll();
}

function rs_msg_dismiss(int $accId, bool $on): void {
    rs_dev_tables();
    rs_db()->prepare('INSERT INTO dev_threads (account_id, dismissed, dismissed_at) VALUES (?, ?, ' . ($on ? 'NOW()' : 'NULL') . ')
        ON DUPLICATE KEY UPDATE dismissed = VALUES(dismissed), dismissed_at = VALUES(dismissed_at)')->execute([$accId, $on ? 1 : 0]);
}

// Conversations in the inbox the developer is waiting on (for the menu's count).
function rs_msg_waiting_count(): int {
    try {
        rs_dev_tables();
        return (int)rs_db()->query("SELECT COUNT(*) FROM (SELECT account_id, MAX(id) AS last_id FROM dev_messages WHERE channel = 'dm' AND deleted = 0 GROUP BY account_id) m
            JOIN dev_messages l ON l.id = m.last_id LEFT JOIN dev_threads t ON t.account_id = m.account_id
            WHERE l.from_admin = 0 AND COALESCE(t.dismissed, 0) = 0")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

// ---- (4.12) A team member's own developer account -------------------------------------------------
// The developer account whose GitHub is the one on their Team card (On the website > GitHub), so the
// owner decides who is linked to what. -> the dev_accounts row, or null.
function rs_msg_my_dev(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $c = null;
    try {
        $uid = (int)($_SESSION['uid'] ?? 0);
        if ($uid <= 0) return null;
        $db = rs_db();
        if (rs_col_missing($db, 'users', 'pub_github')) return null;
        $st = $db->prepare('SELECT pub_github FROM users WHERE id = ?');
        $st->execute([$uid]);
        $gh = trim((string)$st->fetchColumn(), " @\t");
        if ($gh === '') return null;
        rs_dev_tables();
        $st = $db->prepare("SELECT * FROM dev_accounts WHERE LOWER(gh_login) = LOWER(?) AND status IN ('approved', 'pending') ORDER BY id LIMIT 1");
        $st->execute([$gh]);
        $c = $st->fetch() ?: null;
    } catch (Throwable $e) { $c = null; }
    return $c;
}
