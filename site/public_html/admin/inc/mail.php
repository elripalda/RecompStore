<?php
// RecompStore admin (2.7.1): email from the site's own mailbox (Hostinger Email, info@recompstore.com
// by default). Replies to the Inbox and emails to the developer list go out through the mailbox's
// SMTP server, and a copy is put in its Sent folder, so the whole conversation is in webmail.
// The mailbox password is typed in the admin's Mail page and kept encrypted with a key made from
// the config file, which lives outside the database.
declare(strict_types=1);

const RS_MAIL_DEFAULTS = ['mail_from' => 'info@recompstore.com', 'mail_name' => 'RecompStore',
    'mail_host' => 'smtp.hostinger.com', 'mail_port' => '465', 'mail_imap' => 'imap.hostinger.com',
    'mail_sign' => "Ruben (elripalda)\nRecompStore · https://recompstore.com"];

function rs_mail_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    // Who's on the developer list. status: on, pending (signed up, not confirmed yet) or off.
    $db->exec('CREATE TABLE IF NOT EXISTS mail_list (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        name VARCHAR(120) NOT NULL DEFAULT \'\',
        source VARCHAR(24) NOT NULL DEFAULT \'\',
        status VARCHAR(8) NOT NULL DEFAULT \'on\',
        token CHAR(32) NOT NULL,
        ip_hash CHAR(64) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        changed DATETIME NOT NULL,
        UNIQUE KEY (email), UNIQUE KEY (token), INDEX (status), INDEX (ip_hash, created)
    ) CHARACTER SET utf8mb4');
    // An email to the list, sent a few at a time.
    $db->exec('CREATE TABLE IF NOT EXISTS mail_blasts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        subject VARCHAR(200) NOT NULL,
        body MEDIUMTEXT NOT NULL,
        status VARCHAR(8) NOT NULL DEFAULT \'sending\',
        total INT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    // Everything sent: replies (with their words), each list email, tests and sign-up confirmations.
    $db->exec('CREATE TABLE IF NOT EXISTS mail_sent (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(8) NOT NULL,
        inbox_id INT NULL,
        blast_id INT NULL,
        list_id INT NULL,
        to_addr VARCHAR(190) NOT NULL,
        subject VARCHAR(200) NOT NULL DEFAULT \'\',
        body MEDIUMTEXT NULL,
        ok TINYINT NOT NULL DEFAULT 1,
        error VARCHAR(255) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        INDEX (inbox_id), INDEX (blast_id, list_id), INDEX (created)
    ) CHARACTER SET utf8mb4');
    // (2.7.2) Emails made in the builder (blocks, JSON) and sent to people picked by hand (only_ids, JSON ids).
    if (!$db->query("SHOW COLUMNS FROM mail_blasts LIKE 'blocks'")->fetch()) $db->exec('ALTER TABLE mail_blasts ADD COLUMN blocks MEDIUMTEXT NULL, ADD COLUMN only_ids TEXT NULL');
    $done = true;
}

function rs_mail_get(string $k): string {
    return (string)rs_setting($k, RS_MAIL_DEFAULTS[$k] ?? '');
}

// The key the mailbox password is locked with: from the config file, never stored in the database.
function rs_mail_key(): string {
    $c = rs_config() ?: [];
    return hash('sha256', 'rs-mail|' . ($c['db_pass'] ?? '') . '|' . ($c['db_name'] ?? '') . '|' . ($c['db_user'] ?? ''), true);
}

function rs_mail_set_password(string $pass): void {
    if ($pass === '') { rs_set_setting('mail_pass', ''); return; }
    $iv = random_bytes(12);
    $tag = '';
    $box = openssl_encrypt($pass, 'aes-256-gcm', rs_mail_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($box === false) throw new RuntimeException('The password couldn\'t be locked away on this server.');
    rs_set_setting('mail_pass', base64_encode($iv . $tag . $box));
}

function rs_mail_password(): string {
    $raw = base64_decode((string)rs_setting('mail_pass', ''), true);
    if (!$raw || strlen($raw) <= 28) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', rs_mail_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

// (3.1g) The sending service's key, locked away the same way as the mailbox password.
function rs_mail_lock(string $plain): string {
    if ($plain === '') return '';
    $iv = random_bytes(12);
    $tag = '';
    $box = openssl_encrypt($plain, 'aes-256-gcm', rs_mail_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($box === false) throw new RuntimeException('The key couldn\'t be locked away on this server.');
    return base64_encode($iv . $tag . $box);
}

function rs_mail_unlock(string $setting): string {
    $raw = base64_decode((string)rs_setting($setting, ''), true);
    if (!$raw || strlen($raw) <= 28) return '';
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', rs_mail_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}

// On when it's switched on and has a key: then everything goes out through it.
function rs_relay_on(): bool {
    return rs_setting('relay_on', '0') === '1' && rs_mail_unlock('relay_key') !== '';
}

function rs_mail_ready(): bool {
    if (rs_relay_on()) return (bool)filter_var(rs_mail_get('mail_from'), FILTER_VALIDATE_EMAIL);
    return filter_var(rs_mail_get('mail_from'), FILTER_VALIDATE_EMAIL) && rs_mail_password() !== '';
}

// ---- The message itself ---------------------------------------------------------------------

function rs_mail_header_text(string $s): string {
    $s = trim(preg_replace('/[\r\n]+/', ' ', $s) ?? '');
    return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

// The logo at the top of every email, centered.
function rs_mail_logo(): string {
    return '<div style="padding:4px 0 22px;text-align:center"><a href="https://recompstore.com/"><img src="https://recompstore.com/assets/email-logo.png" alt="RecompStore" width="200" style="display:inline-block;width:200px;height:auto;border:0"></a></div>';
}

// Plain text to simple HTML: paragraphs, line breaks and links.
function rs_mail_html(string $text, string $footer = ''): string {
    $para = function (string $t): string {
        $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $t = preg_replace('#\bhttps?://[^\s<>"\']+[^\s<>"\'.,;:!?)]#', '<a href="$0" style="color:#1f6fb2">$0</a>', $t) ?? $t;
        return nl2br($t, false);
    };
    $body = '';
    foreach (preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $text))) as $p) {
        if (trim($p) !== '') $body .= '<p style="margin:0 0 16px">' . $para(trim($p)) . '</p>';
    }
    $foot = $footer !== '' ? '<p style="margin:24px 0 0;padding-top:16px;border-top:1px solid #e3e8ef;font-size:13px;color:#6b7686">' . $para($footer) . '</p>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
        . '<body style="margin:0;background:#f3f6f9"><div style="max-width:600px;margin:0 auto;padding:28px 20px">'
        . rs_mail_logo()
        . '<div style="background:#fff;border-radius:14px;padding:28px 26px;font:16px/1.6 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1b2430">'
        . $body . $foot . '</div></div></body></html>';
}

// opt: html, footer (added to both parts), unsub (URL for List-Unsubscribe), reply_to
function rs_mail_build(string $to, string $subject, string $text, array $opt = []): array {
    $from = rs_mail_get('mail_from');
    $name = (string)($opt['from_name'] ?? rs_mail_get('mail_name'));
    $footer = (string)($opt['footer'] ?? '');
    $plain = rtrim(str_replace("\r\n", "\n", $text)) . ($footer !== '' ? "\n\n--\n" . $footer : '') . "\n";
    $html = $opt['html'] ?? rs_mail_html($text, $footer);
    $id = bin2hex(random_bytes(12)) . '@' . (substr(strrchr($from, '@'), 1) ?: 'recompstore.com');
    $b = 'rs-' . bin2hex(random_bytes(10));
    $h = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . rs_mail_header_text($name) . ' <' . $from . '>',
        'To: <' . $to . '>',
        'Subject: ' . rs_mail_header_text($subject),
        'Message-ID: <' . $id . '>',
        'MIME-Version: 1.0',
    ];
    if (!empty($opt['reply_to']) && filter_var($opt['reply_to'], FILTER_VALIDATE_EMAIL)) $h[] = 'Reply-To: <' . $opt['reply_to'] . '>';
    if (!empty($opt['unsub'])) {
        $h[] = 'List-Unsubscribe: <' . $opt['unsub'] . '>, <mailto:' . $from . '?subject=unsubscribe>';
        $h[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
    }
    $h[] = 'Content-Type: multipart/alternative; boundary="' . $b . '"';
    $msg = implode("\r\n", $h) . "\r\n\r\n"
        . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . rtrim(chunk_split(base64_encode($plain), 76, "\r\n")) . "\r\n"
        . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . rtrim(chunk_split(base64_encode($html), 76, "\r\n")) . "\r\n"
        . "--$b--\r\n";
    return ['from' => $from, 'data' => $msg, 'id' => $id];
}

// ---- Sending: SMTP (SSL on 465, or STARTTLS on 587) ------------------------------------------

function rs_smtp_read($s): array {
    $all = '';
    while (($line = fgets($s, 1024)) !== false) {
        $all .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return [(int)substr($all, 0, 3), trim($all)];
}

function rs_smtp_cmd($s, string $cmd, array $ok, string $what = ''): string {
    if ($cmd !== '') fwrite($s, $cmd . "\r\n");
    [$code, $text] = rs_smtp_read($s);
    if (!in_array($code, $ok, true)) {
        $said = mb_substr(preg_replace('/\s+/', ' ', $text) ?? '', 0, 160);
        throw new RuntimeException(($what !== '' ? $what . ': ' : '') . ($said !== '' ? $said : 'no answer from the mail server'));
    }
    return $text;
}

// One SMTP connection for one or more messages: [[to, data], ...]. Returns an error per message ('' = sent).
// (3.1g) Gmail rate-limits mail that comes through the sending service's address when a lot goes out
// at once (421 4.7.28), which held up account emails. So a few emails at a time that are all for
// Gmail (account confirmations, password resets) go straight from the mailbox, which has its own
// daily allowance; any the mailbox can't send go through the service as before.
function rs_smtp_send(array $messages): array {
    $relay = rs_relay_on();
    if ($relay && $messages && count($messages) <= 3 && rs_setting('relay_gmail_direct', '0') === '1') {
        $gmail = true;
        foreach ($messages as [$to]) if (!preg_match('/@(gmail|googlemail)\.com$/i', trim((string)$to))) $gmail = false;
        if ($gmail) {
            $out = null;
            try { if (rs_mail_password() !== '') $out = rs_smtp_send_via($messages, false); } catch (Throwable $e) { $out = null; }
            if ($out === null) return rs_smtp_send_via($messages, true);
            $again = array_filter($messages, fn($k) => ($out[$k] ?? 'x') !== '', ARRAY_FILTER_USE_KEY);
            if ($again) { try { foreach (rs_smtp_send_via($again, true) as $k => $err) $out[$k] = $err; } catch (Throwable $e) { /* keeps the mailbox's error */ } }
            return $out;
        }
    }
    return rs_smtp_send_via($messages, $relay);
}

function rs_smtp_send_via(array $messages, bool $relay): array {
    $host = rs_mail_get('mail_host');
    $port = (int)rs_mail_get('mail_port');
    $user = rs_mail_get('mail_from');
    $login = $user;
    $pass = rs_mail_password();
    // (3.1g) Through the sending service: signed in with its key, still from the mailbox's address.
    if ($relay) {
        $host = (string)rs_setting('relay_host', 'smtp.resend.com');
        $port = (int)rs_setting('relay_port', '465');
        if (!in_array($port, [465, 587, 2525], true)) $port = 465;
        $login = (string)rs_setting('relay_user', 'resend');
        $pass = rs_mail_unlock('relay_key');
    }
    if ($pass === '') throw new RuntimeException('Add the mailbox password on the Mail page first.');
    $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
    $s = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) throw new RuntimeException('Couldn\'t reach ' . $host . ':' . $port . ' (' . $errstr . ')');
    stream_set_timeout($s, 30);
    $out = [];
    try {
        rs_smtp_cmd($s, '', [220], 'Mail server');
        $me = 'recompstore.com';
        $ehlo = rs_smtp_cmd($s, 'EHLO ' . $me, [250], 'Mail server');
        if ($port !== 465 && stripos($ehlo, 'STARTTLS') !== false) {
            rs_smtp_cmd($s, 'STARTTLS', [220], 'Mail server');
            if (!stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('The mail server\'s secure connection didn\'t start.');
            rs_smtp_cmd($s, 'EHLO ' . $me, [250], 'Mail server');
        }
        rs_smtp_cmd($s, 'AUTH LOGIN', [334], 'Sign-in');
        rs_smtp_cmd($s, base64_encode($login), [334], 'Sign-in');
        rs_smtp_cmd($s, base64_encode($pass), [235], $relay ? 'The sending service\'s key didn\'t work' : 'The mailbox password didn\'t work');
        foreach ($messages as $i => [$to, $data]) {
            // The service takes about two a second; a short gap keeps every one under that.
            if ($relay && $i > 0) usleep(550000);
            try {
                rs_smtp_cmd($s, 'MAIL FROM:<' . $user . '>', [250], 'Sender');
                rs_smtp_cmd($s, 'RCPT TO:<' . $to . '>', [250, 251], $to);
                rs_smtp_cmd($s, 'DATA', [354], 'Message');
                // Lines that start with a dot get a second one (SMTP's rule).
                $body = preg_replace('/^\./m', '..', str_replace("\r\n", "\n", $data));
                fwrite($s, str_replace("\n", "\r\n", rtrim((string)$body, "\n")) . "\r\n.\r\n");
                rs_smtp_cmd($s, '', [250], 'Message');
                $out[$i] = '';
            } catch (RuntimeException $e) {
                $out[$i] = $e->getMessage();
                @fwrite($s, "RSET\r\n");
                @rs_smtp_read($s);
                if (feof($s)) { foreach ($messages as $j => $_) $out[$j] ??= 'The mail server closed the connection.'; break; }
            }
        }
        @fwrite($s, "QUIT\r\n");
    } finally {
        @fclose($s);
    }
    return $out;
}

// A copy in the mailbox's Sent folder (IMAP), so it shows up in webmail. Best effort: false if not.
function rs_mail_save_sent(string $data): bool {
    $host = rs_mail_get('mail_imap');
    $pass = rs_mail_password();
    if ($host === '' || $pass === '') return false;
    $s = @stream_socket_client('ssl://' . $host . ':993', $errno, $errstr, 15, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['peer_name' => $host]]));
    if (!$s) return false;
    stream_set_timeout($s, 20);
    $q = fn(string $v) => '"' . addcslashes($v, "\"\\") . '"';
    $until = function (string $tag) use ($s): array {
        $lines = [];
        while (($l = fgets($s, 8192)) !== false) {
            $lines[] = rtrim($l, "\r\n");
            if (str_starts_with($l, $tag . ' ')) break;
        }
        return $lines;
    };
    try {
        fgets($s, 1024);
        fwrite($s, 'a1 LOGIN ' . $q(rs_mail_get('mail_from')) . ' ' . $q($pass) . "\r\n");
        $r = $until('a1');
        if (!str_contains((string)end($r), 'OK')) return false;
        fwrite($s, "a2 LIST \"\" \"*\"\r\n");
        $folder = '';
        foreach ($until('a2') as $l) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:"[^"]*"|NIL) (.+)$/i', $l, $m)) continue;
            $name = trim($m[2], '"');
            if (stripos($m[1], '\\Sent') !== false) { $folder = $name; break; }
            if ($folder === '' && preg_match('/^(INBOX[.\/])?Sent( Items| Messages)?$/i', $name)) $folder = $name;
        }
        if ($folder === '') $folder = 'INBOX.Sent';
        $msg = str_replace("\n", "\r\n", str_replace("\r\n", "\n", $data));
        fwrite($s, 'a3 APPEND ' . $q($folder) . ' (\\Seen) {' . strlen($msg) . "}\r\n");
        $go = fgets($s, 1024);
        if ($go === false || $go[0] !== '+') return false;
        fwrite($s, $msg . "\r\n");
        $r = $until('a3');
        fwrite($s, "a4 LOGOUT\r\n");
        return str_contains((string)end($r), 'OK');
    } catch (Throwable $e) {
        return false;
    } finally {
        @fclose($s);
    }
}

function rs_mail_log(string $kind, string $to, string $subject, ?string $body, string $error, ?int $inbox = null, ?int $blast = null, ?int $list = null): void {
    rs_mail_tables();
    rs_db()->prepare('INSERT INTO mail_sent (kind, inbox_id, blast_id, list_id, to_addr, subject, body, ok, error, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$kind, $inbox, $blast, $list, mb_substr($to, 0, 190), mb_substr($subject, 0, 200), $body, $error === '' ? 1 : 0, mb_substr($error, 0, 255)]);
}

// One email, sent now, with a copy in Sent. Throws with the reason if it didn't go.
function rs_mail_send(string $to, string $subject, string $text, array $opt = []): void {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That isn\'t an email address: ' . $to);
    $m = rs_mail_build($to, $subject, $text, $opt);
    $err = rs_smtp_send([[$to, $m['data']]])[0] ?? 'Not sent.';
    if ($err !== '') throw new RuntimeException($err);
    if (!empty($opt['save'])) rs_mail_save_sent($m['data']);
}

// ---- The developer list ---------------------------------------------------------------------

function rs_list_url(string $action, string $token): string {
    return 'https://recompstore.com/list/' . $action . '/' . $token;
}

function rs_list_footer(string $token): string {
    return "You're getting this because you're on RecompStore's developer list.\nUnsubscribe: " . rs_list_url('off', $token);
}

// Adds someone (or turns them back on when $force). Returns 'added', 'back', 'there' or 'off' (they left; not re-added).
function rs_list_add(string $email, string $name, string $source, string $status = 'on', bool $force = false, string $ip = ''): string {
    rs_mail_tables();
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('That isn\'t an email address.');
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM mail_list WHERE email = ?');
    $st->execute([$email]);
    $row = $st->fetch();
    if ($row) {
        if ($row['status'] === 'on') return 'there';
        if ($row['status'] === 'off' && !$force) return 'off';
        $db->prepare('UPDATE mail_list SET status = ?, changed = NOW() WHERE id = ?')->execute([$status, $row['id']]);
        return 'back';
    }
    $db->prepare('INSERT INTO mail_list (email, name, source, status, token, ip_hash, created, changed) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())')
       ->execute([$email, mb_substr(trim($name), 0, 120), $source, $status, bin2hex(random_bytes(16)), $ip]);
    return 'added';
}

// Everyone who sent a submission with an email address: [email => name].
// The developer list from submissions (2.7.3): only people whose app is in the store or waiting
// to be approved (it's in the admin's games, listed or a draft), and not developers who already
// have a hub (they hear from RecompStore there). Test addresses are left out.
function rs_list_submitters(): array {
    rs_inbox_table();
    $ids = $repos = [];
    foreach (rs_games(true) as $g) {
        $ids[strtoupper((string)$g['id'])] = true;
        if ((string)$g['repo'] !== '') $repos[strtolower((string)$g['repo'])] = true;
    }
    $hubs = [];
    try {
        rs_dev_tables();
        foreach (rs_db()->query("SELECT email FROM dev_accounts WHERE status = 'approved' AND email <> ''") as $a) $hubs[strtolower(trim((string)$a['email']))] = true;
    } catch (Throwable $e) { /* no developer accounts yet */ }
    $out = [];
    foreach (rs_db()->query("SELECT app_id, repo, contact, name, data FROM inbox WHERE kind = 'submit' ORDER BY created") as $r) {
        $d = json_decode((string)($r['data'] ?? ''), true);
        $mail = strtolower(trim((string)$r['contact']));
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || isset($hubs[$mail])) continue;
        if (preg_match('/@(test|example)\.(com|org|net)$/', $mail)) continue;
        $repo = strtolower(trim((string)preg_replace('#^https://github\.com/#i', '', (string)$r['repo']), '/'));
        if (!isset($ids[strtoupper((string)$r['app_id'])]) && ($repo === '' || !isset($repos[$repo]))) continue;
        $out[$mail] = is_array($d) && trim((string)($d['author'] ?? '')) !== '' ? (string)$d['author'] : (string)$r['name'];
    }
    return $out;
}

// The email address to reply to for an Inbox item ('' when they didn't leave one).
function rs_inbox_email(array $r, ?array $report = null): string {
    foreach ([(string)($r['contact'] ?? ''), (string)($report['contact'] ?? '')] as $c) {
        if (filter_var(trim($c), FILTER_VALIDATE_EMAIL)) return trim($c);
    }
    return '';
}
