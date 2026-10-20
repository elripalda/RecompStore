<?php
// Recomp Store 2.0: the PS5 store and this site talking. A console's identity, the phones and PCs
// linked to it and what they send it, bug reports finished on a phone, GitHub through this site
// (cached, for rate limits and custom sources), and installs counted for the charts.
// The contract the store codes against: docs/api-2.0.md in the source.
declare(strict_types=1);

// ---- A console ---------------------------------------------------------------------------------

// A store install (beta_installs, since 1.1.0) when its ID and token match; null otherwise.
function rs_console(PDO $db, string $install, string $token): ?string {
    if (!preg_match('/^[0-9a-f]{16}$/', $install) || !preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    rs_beta_tables();
    $st = $db->prepare('SELECT token_hash FROM beta_installs WHERE install_id = ?');
    $st->execute([$install]);
    $h = $st->fetchColumn();
    return is_string($h) && hash_equals($h, hash('sha256', $token)) ? $install : null;
}

function rs_console_seen(PDO $db, string $install, string $store): void {
    rs_count_console($db, $install); // (2.1) Analytics: consoles a day
    $store = preg_replace('/[^0-9A-Za-z.\-]/', '', $store) ?? '';
    if ($store !== '') {
        $db->prepare('UPDATE beta_installs SET last_seen = NOW(), store_version = ? WHERE install_id = ?')->execute([substr($store, 0, 32), $install]);
    } else {
        $db->prepare('UPDATE beta_installs SET last_seen = NOW() WHERE install_id = ?')->execute([$install]);
    }
}

// Whether the admin marked this console as a tester (Settings, Testers).
function rs_console_tester(PDO $db, string $install): bool {
    rs_beta_tables();
    $st = $db->prepare('SELECT tester FROM beta_installs WHERE install_id = ?');
    $st->execute([$install]);
    return (int)$st->fetchColumn() === 1;
}

// A new console: its ID, and the token only it gets (the site keeps its hash).
function rs_console_new(PDO $db): array {
    rs_beta_tables();
    $install = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO beta_installs (install_id, token_hash, created, last_seen) VALUES (?, ?, NOW(), NOW())')
       ->execute([$install, hash('sha256', $token)]);
    return [$install, $token];
}

// ---- Limits: how often one address may do something ----------------------------------------

function rs_rate_table(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS rate_tries (
        ip_hash CHAR(64) NOT NULL,
        kind VARCHAR(16) NOT NULL,
        at DATETIME NOT NULL,
        INDEX (kind, ip_hash, at)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// True when this address already did $kind $max times in the last $seconds.
function rs_rate_over(string $kind, int $max, int $seconds): bool {
    rs_rate_table();
    $st = rs_db()->prepare('SELECT COUNT(*) FROM rate_tries WHERE kind = ? AND ip_hash = ? AND at > (NOW() - INTERVAL ? SECOND)');
    $st->execute([$kind, rs_ip_hash(), $seconds]);
    return (int)$st->fetchColumn() >= $max;
}

function rs_rate_hit(string $kind): void {
    rs_rate_table();
    $db = rs_db();
    $db->prepare('INSERT INTO rate_tries (ip_hash, kind, at) VALUES (?, ?, NOW())')->execute([rs_ip_hash(), $kind]);
    if (random_int(0, 50) === 0) $db->exec('DELETE FROM rate_tries WHERE at < (NOW() - INTERVAL 2 DAY)');
}

// ---- Linking a phone or PC to a console ---------------------------------------------------------

// Codes people read off a screen: no 0/O, 1/I/L, 2/Z, 5/S, 8/B.
const RS_LINK_ALPHABET = 'ACDEFGHJKMNPQRTUVWXY34679';
const RS_DEVICE_COOKIE = 'rs_device';

function rs_link_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    rs_beta_tables();
    $db->exec('CREATE TABLE IF NOT EXISTS link_codes (
        code CHAR(8) PRIMARY KEY,
        kind VARCHAR(8) NOT NULL,
        install_id CHAR(16) NULL,
        device_id CHAR(16) NULL,
        created DATETIME NOT NULL,
        expires DATETIME NOT NULL,
        used DATETIME NULL,
        INDEX (install_id), INDEX (device_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS link_devices (
        device_id CHAR(16) PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        install_id CHAR(16) NULL,
        label VARCHAR(60) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        linked DATETIME NULL,
        INDEX (install_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS link_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        install_id CHAR(16) NOT NULL,
        device_id CHAR(16) NOT NULL DEFAULT \'\',
        kind VARCHAR(16) NOT NULL,
        data TEXT NOT NULL,
        created DATETIME NOT NULL,
        taken DATETIME NULL,
        INDEX (install_id, taken)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS link_state (
        install_id CHAR(16) PRIMARY KEY,
        state MEDIUMTEXT NOT NULL,
        updated DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// A 6-character code for the PS5 to show (or for a bug report), not in use.
function rs_letter_code(int $len = 6): string {
    $out = '';
    for ($i = 0; $i < $len; ++$i) $out .= RS_LINK_ALPHABET[random_int(0, strlen(RS_LINK_ALPHABET) - 1)];
    return $out;
}

// An 8-digit web code, never one a beta code has.
function rs_web_code(PDO $db): string {
    rs_link_tables();
    $chk = $db->prepare('SELECT (SELECT COUNT(*) FROM beta_codes WHERE code = ?) + (SELECT COUNT(*) FROM link_codes WHERE code = ?)');
    for ($i = 0; $i < 50; ++$i) {
        $code = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $chk->execute([$code, $code]);
        if ((int)$chk->fetchColumn() === 0) return $code;
    }
    throw new RuntimeException('no free code');
}

// An unused, unexpired web code, as a row; null otherwise.
function rs_live_web_code(PDO $db, string $code): ?array {
    rs_link_tables();
    $st = $db->prepare("SELECT * FROM link_codes WHERE code = ? AND kind = 'web' AND used IS NULL AND expires > NOW()");
    $st->execute([$code]);
    $r = $st->fetch();
    return $r ?: null;
}

// What a browser calls itself, roughly, for the PS5's Linked devices list.
function rs_device_label(string $ua): string {
    return match (true) {
        (bool)preg_match('/iPhone/i', $ua) => 'iPhone',
        (bool)preg_match('/iPad/i', $ua) => 'iPad',
        (bool)preg_match('/Android/i', $ua) && (bool)preg_match('/Mobile/i', $ua) => 'Android phone',
        (bool)preg_match('/Android/i', $ua) => 'Android tablet',
        (bool)preg_match('/CrOS/i', $ua) => 'Chromebook',
        (bool)preg_match('/Windows/i', $ua) => 'Windows PC',
        (bool)preg_match('/Macintosh|Mac OS X/i', $ua) => 'Mac',
        (bool)preg_match('/Linux/i', $ua) => 'Linux PC',
        default => 'Phone or computer',
    };
}

function rs_clean_label(string $s): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F<>]/u', '', mb_scrub($s, 'UTF-8')) ?? '');
    return mb_substr($s, 0, 40);
}

function rs_device_cookie(string $value, int $expires): void {
    setcookie(RS_DEVICE_COOKIE, $value, [
        'expires' => $expires, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

// This browser, when it has a device cookie the site knows; null otherwise.
function rs_device(PDO $db): ?array {
    $c = (string)($_COOKIE[RS_DEVICE_COOKIE] ?? '');
    if (!preg_match('/^([0-9a-f]{16})\.([0-9a-f]{32})$/', $c, $m)) return null;
    rs_link_tables();
    $st = $db->prepare('SELECT * FROM link_devices WHERE device_id = ?');
    $st->execute([$m[1]]);
    $d = $st->fetch();
    if (!$d || !hash_equals((string)$d['token_hash'], hash('sha256', $m[2]))) return null;
    $db->prepare('UPDATE link_devices SET last_seen = NOW() WHERE device_id = ?')->execute([$d['device_id']]);
    return $d;
}

// A new device for this browser, and its cookie (400 days).
function rs_device_make(PDO $db, string $label): array {
    rs_link_tables();
    $id = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(16));
    $label = $label !== '' ? $label : rs_device_label((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $db->prepare('INSERT INTO link_devices (device_id, token_hash, install_id, label, created, last_seen) VALUES (?, ?, NULL, ?, NOW(), NOW())')
       ->execute([$id, hash('sha256', $token), $label]);
    rs_device_cookie($id . '.' . $token, time() + 400 * 86400);
    $st = $db->prepare('SELECT * FROM link_devices WHERE device_id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

// Something for a console to pick up on its next poll.
function rs_link_queue(PDO $db, string $install, string $device, string $kind, array $data): void {
    rs_link_tables();
    $db->prepare('INSERT INTO link_queue (install_id, device_id, kind, data, created) VALUES (?, ?, ?, ?, NOW())')
       ->execute([$install, $device, $kind, json_encode((object)$data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
}

// ---- Bug reports finished on a phone ------------------------------------------------------------

function rs_report_tables(): void {
    static $done = false;
    if ($done) return;
    rs_inbox_table();
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS report_codes (
        code CHAR(6) PRIMARY KEY,
        inbox_id INT NOT NULL,
        created DATETIME NOT NULL,
        expires DATETIME NOT NULL,
        finished DATETIME NULL,
        INDEX (inbox_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS inbox_reports (
        inbox_id INT PRIMARY KEY,
        what TEXT NOT NULL,
        before_text TEXT NOT NULL,
        contact VARCHAR(200) NOT NULL DEFAULT \'\',
        finished DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS inbox_pictures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inbox_id INT NOT NULL,
        file VARCHAR(80) NOT NULL,
        mime VARCHAR(32) NOT NULL,
        size INT NOT NULL,
        w INT NOT NULL DEFAULT 0,
        h INT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL,
        INDEX (inbox_id)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// A report code for an inbox item (logs a player sent), valid 7 days.
function rs_report_code(PDO $db, int $inboxId): string {
    rs_report_tables();
    $ins = $db->prepare('INSERT IGNORE INTO report_codes (code, inbox_id, created, expires) VALUES (?, ?, NOW(), NOW() + INTERVAL 7 DAY)');
    for ($i = 0; $i < 30; ++$i) {
        $code = rs_letter_code(6);
        $ins->execute([$code, $inboxId]);
        if ($ins->rowCount() === 1) return $code;
    }
    throw new RuntimeException('no free report code');
}

// Where report pictures are kept: beside public_html when the host allows it (never served
// directly), otherwise public_html/report-files, which its own .htaccess blocks. The admin sees
// them through admin/picture.php.
function rs_report_dir(): string {
    $outside = dirname(RS_PUBLIC) . '/recompstore-reports';
    if (is_dir($outside)) return $outside;
    $inside = RS_PUBLIC . '/report-files';
    if (is_dir($inside)) return $inside;
    return is_writable(dirname(RS_PUBLIC)) ? $outside : $inside;
}

function rs_report_dir_ready(): string {
    $dir = rs_report_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not make the report pictures folder ' . $dir . '.');
    if (str_starts_with($dir, RS_PUBLIC) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    return $dir;
}

// ---- GitHub, through this site ---------------------------------------------------------------
// The API base can be pointed elsewhere for tests (RS_GH_API in the environment); downloads may
// come from github.com, *.githubusercontent.com, or that test host.

function rs_gh_api(): string {
    $e = getenv('RS_GH_API');
    return rtrim(is_string($e) && $e !== '' ? $e : 'https://api.github.com', '/');
}

function rs_gh_allowed(string $url): bool {
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return false;
    $host = strtolower($p['host']);
    $test = parse_url(rs_gh_api());
    if (is_array($test) && ($test['host'] ?? '') !== 'api.github.com' && strtolower((string)($test['host'] ?? '')) === $host) return true;
    if ($p['scheme'] !== 'https') return false;
    return $host === 'github.com' || $host === 'api.github.com' || str_ends_with($host, '.githubusercontent.com');
}

// One HTTP request. o: headers [], range [from, to|null], max (bytes kept, more is an error),
// timeout (seconds), github (send the admin's token when the host is GitHub's API).
// -> [status, body, error, url (the final one), headers (lowercase name => value)]
function rs_http(string $url, array $o = []): array {
    $out = ['status' => 0, 'body' => '', 'error' => '', 'url' => $url, 'headers' => []];
    $c = curl_init($url);
    $headers = $o['headers'] ?? [];
    if (!empty($o['range'])) $headers[] = 'Range: bytes=' . $o['range'][0] . '-' . ($o['range'][1] ?? '');
    if (!empty($o['github'])) {
        $tok = trim((string)rs_setting('github_token', ''));
        if ($tok !== '' && str_starts_with($url, rs_gh_api() . '/')) $headers[] = 'Authorization: Bearer ' . $tok;
        $headers[] = 'Accept: application/vnd.github+json';
        $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
    }
    $max = (int)($o['max'] ?? (8 << 20));
    $body = '';
    $tooBig = false;
    $opts = [
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => (int)($o['timeout'] ?? 20),
        CURLOPT_USERAGENT => 'RecompStore-site', CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$out) {
            $p = strpos($line, ':');
            if ($p !== false) $out['headers'][strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body, $max, &$tooBig) {
            if (strlen($body) + strlen($chunk) > $max) { $tooBig = true; return 0; }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    $ca = getenv('RS_CA_BUNDLE');
    if (is_string($ca) && $ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
    curl_setopt_array($c, $opts);
    curl_exec($c);
    $out['status'] = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $out['url'] = (string)curl_getinfo($c, CURLINFO_EFFECTIVE_URL);
    if ($tooBig) { $out['error'] = 'too big'; $out['status'] = 0; }
    elseif (curl_errno($c)) $out['error'] = curl_error($c);
    curl_close($c);
    $out['body'] = $body;
    // A redirect to somewhere that isn't GitHub's: treated as nothing came.
    if (!rs_gh_allowed($out['url']) && !empty($o['github_only'])) { $out['status'] = 0; $out['body'] = ''; $out['error'] = 'redirected away'; }
    return $out;
}

function rs_cache_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS gh_cache (
        k VARCHAR(220) PRIMARY KEY,
        status INT NOT NULL,
        body MEDIUMTEXT NOT NULL,
        fetched DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS kv_cache (
        k VARCHAR(220) PRIMARY KEY,
        v MEDIUMTEXT NOT NULL,
        at DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// A cached value and its age in seconds, or null.
function rs_kv_get(string $k): ?array {
    rs_cache_tables();
    $st = rs_db()->prepare('SELECT v, TIMESTAMPDIFF(SECOND, at, NOW()) AS age FROM kv_cache WHERE k = ?');
    $st->execute([$k]);
    $r = $st->fetch();
    return $r ? ['v' => (string)$r['v'], 'age' => (int)$r['age']] : null;
}

function rs_kv_put(string $k, string $v): void {
    rs_cache_tables();
    rs_db()->prepare('INSERT INTO kv_cache (k, v, at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE v = VALUES(v), at = NOW()')->execute([$k, $v]);
}

function rs_repo_ok(string $repo): bool {
    return (bool)preg_match('#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $repo) && !str_contains($repo, '..');
}

// GitHub's answer for a key ('<repo>' releases, '<repo>#info' the repo), from the cache when
// it's younger than $maxAge, else asked again. When GitHub can't be reached the cached copy is
// used whatever its age (stale). -> [status, body, stale, age]
function rs_gh_cached(string $key, string $url, int $maxAge, callable $trim): array {
    rs_cache_tables();
    $db = rs_db();
    $k = strtolower($key);
    $st = $db->prepare('SELECT status, body, TIMESTAMPDIFF(SECOND, fetched, NOW()) AS age FROM gh_cache WHERE k = ?');
    $st->execute([$k]);
    $have = $st->fetch() ?: null;
    if ($have && (int)$have['age'] < $maxAge) return ['status' => (int)$have['status'], 'body' => (string)$have['body'], 'stale' => false, 'age' => (int)$have['age']];
    $r = rs_http($url, ['github' => true, 'max' => 16 << 20, 'timeout' => 20]);
    if ($r['status'] === 200) {
        $j = json_decode($r['body'], true);
        if (is_array($j)) {
            $body = json_encode($trim($j), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $db->prepare('INSERT INTO gh_cache (k, status, body, fetched) VALUES (?, 200, ?, NOW()) ON DUPLICATE KEY UPDATE status = 200, body = VALUES(body), fetched = NOW()')->execute([$k, $body]);
            return ['status' => 200, 'body' => $body, 'stale' => false, 'age' => 0];
        }
    } elseif ($r['status'] === 404) {
        $body = '{"message":"Not Found"}';
        $db->prepare('INSERT INTO gh_cache (k, status, body, fetched) VALUES (?, 404, ?, NOW()) ON DUPLICATE KEY UPDATE status = 404, body = VALUES(body), fetched = NOW()')->execute([$k, $body]);
        return ['status' => 404, 'body' => $body, 'stale' => false, 'age' => 0];
    }
    if ($have) return ['status' => (int)$have['status'], 'body' => (string)$have['body'], 'stale' => true, 'age' => (int)$have['age']];
    // 403 or 429 from GitHub's API: its limit for this site (a token in Settings lifts it).
    if ($r['status'] === 403 || $r['status'] === 429) return ['status' => 429, 'body' => '{"message":"GitHub is busy right now. Try again in a few minutes."}', 'stale' => false, 'age' => 0];
    return ['status' => 502, 'body' => '{"message":"GitHub didn\'t answer. Try again in a moment."}', 'stale' => false, 'age' => 0];
}

// A repo's releases, as GitHub lists them, trimmed to what the store and the site read.
function rs_gh_releases(string $repo, int $maxAge = 900): array {
    return rs_gh_cached($repo, rs_gh_api() . '/repos/' . $repo . '/releases?per_page=30', $maxAge, function (array $list): array {
        $out = [];
        foreach (array_slice(array_values($list), 0, 30) as $r) {
            if (!is_array($r)) continue;
            $assets = [];
            foreach (is_array($r['assets'] ?? null) ? $r['assets'] : [] as $a) {
                if (!is_array($a)) continue;
                $x = ['name' => (string)($a['name'] ?? ''), 'size' => (int)($a['size'] ?? 0),
                      'browser_download_url' => (string)($a['browser_download_url'] ?? ''), 'download_count' => (int)($a['download_count'] ?? 0)];
                if (isset($a['digest']) && is_string($a['digest'])) $x['digest'] = $a['digest'];
                $assets[] = $x;
            }
            $out[] = [
                'tag_name' => (string)($r['tag_name'] ?? ''), 'name' => (string)($r['name'] ?? ''),
                'published_at' => (string)($r['published_at'] ?? ''), 'prerelease' => (bool)($r['prerelease'] ?? false),
                'draft' => (bool)($r['draft'] ?? false), 'body' => mb_strcut((string)($r['body'] ?? ''), 0, 20000, 'UTF-8'),
                'html_url' => (string)($r['html_url'] ?? ''), 'assets' => $assets,
            ];
        }
        return $out;
    });
}

// The repo itself: its description, stars and owner.
function rs_gh_repo(string $repo, int $maxAge = 3600): ?array {
    $r = rs_gh_cached($repo . '#info', rs_gh_api() . '/repos/' . $repo, $maxAge, fn(array $j): array => [
        'full_name' => (string)($j['full_name'] ?? ''), 'description' => (string)($j['description'] ?? ''),
        'stargazers_count' => (int)($j['stargazers_count'] ?? 0), 'html_url' => (string)($j['html_url'] ?? ''),
        'owner' => ['login' => (string)($j['owner']['login'] ?? ''), 'avatar_url' => (string)($j['owner']['avatar_url'] ?? '')],
        'archived' => (bool)($j['archived'] ?? false),
    ]);
    if ($r['status'] !== 200) return null;
    $j = json_decode($r['body'], true);
    return is_array($j) ? $j : null;
}

// ---- Reading a zip on GitHub without downloading it -------------------------------------------
// Its directory is at its end: a Range request for the last 64 KB finds it, then the two small
// files the store needs (the app's sce_sys/param.json and icon0.png) are read the same way.

function rs_le16(string $b, int $o): int { return ord($b[$o]) | ord($b[$o + 1]) << 8; }
function rs_le32(string $b, int $o): int { return rs_le16($b, $o) | rs_le16($b, $o + 2) << 16; }
function rs_le64(string $b, int $o): int { return rs_le32($b, $o) | rs_le32($b, $o + 4) << 32; }

function rs_range(string $url, int $from, int $to): ?string {
    $r = rs_http($url, ['range' => [$from, $to], 'max' => ($to - $from + 1) + 1024, 'timeout' => 25, 'github_only' => true]);
    if ($r['status'] === 206) return $r['body'];
    if ($r['status'] === 200 && $from === 0) return substr($r['body'], 0, $to + 1);
    return null;
}

// -> ['ok' => bool, 'error' => why, 'entries' => [name => [method, crc, csize, usize, local]], 'prefix' => the app's folder]
function rs_zip_directory(string $url, int $size): array {
    if ($size < 22) return ['ok' => false, 'error' => 'not a zip'];
    $tailFrom = max(0, $size - 65557);
    $tail = rs_range($url, $tailFrom, $size - 1);
    if ($tail === null) return ['ok' => false, 'error' => 'the download couldn\'t be read'];
    $e = strrpos($tail, "PK\x05\x06");
    if ($e === false || strlen($tail) - $e < 22) return ['ok' => false, 'error' => 'not a zip'];
    $count = rs_le16($tail, $e + 10);
    $cdSize = rs_le32($tail, $e + 12);
    $cdAt = rs_le32($tail, $e + 16);
    if ($cdAt === 0xFFFFFFFF || $cdSize === 0xFFFFFFFF || $count === 0xFFFF) {
        // Zip64: the locator sits right before the end record.
        $l = $e - 20;
        if ($l < 0 || substr($tail, $l, 4) !== "PK\x06\x07") return ['ok' => false, 'error' => 'zip64 without its locator'];
        $z64At = rs_le64($tail, $l + 8);
        $z = $z64At >= $tailFrom ? substr($tail, $z64At - $tailFrom, 56) : rs_range($url, $z64At, $z64At + 55);
        if ($z === null || strlen($z) < 56 || substr($z, 0, 4) !== "PK\x06\x06") return ['ok' => false, 'error' => 'zip64 end record unreadable'];
        $count = rs_le64($z, 32);
        $cdSize = rs_le64($z, 40);
        $cdAt = rs_le64($z, 48);
    }
    if ($cdSize > (16 << 20) || $cdAt + $cdSize > $size) return ['ok' => false, 'error' => 'directory too big or damaged'];
    $cd = $cdAt >= $tailFrom && $cdAt + $cdSize <= $size ? substr($tail, $cdAt - $tailFrom, $cdSize) : rs_range($url, $cdAt, $cdAt + $cdSize - 1);
    if ($cd === null || strlen($cd) < $cdSize) return ['ok' => false, 'error' => 'directory unreadable'];
    $entries = [];
    $p = 0;
    for ($i = 0; $i < $count && $p + 46 <= strlen($cd); ++$i) {
        if (substr($cd, $p, 4) !== "PK\x01\x02") break;
        $method = rs_le16($cd, $p + 10);
        $crc = rs_le32($cd, $p + 16);
        $cs = rs_le32($cd, $p + 20);
        $us = rs_le32($cd, $p + 24);
        $nl = rs_le16($cd, $p + 28);
        $xl = rs_le16($cd, $p + 30);
        $cl = rs_le16($cd, $p + 32);
        $local = rs_le32($cd, $p + 42);
        $name = substr($cd, $p + 46, $nl);
        $extra = substr($cd, $p + 46 + $nl, $xl);
        // Zip64 sizes and offset, in that order, for the fields that are 0xFFFFFFFF.
        for ($x = 0; $x + 4 <= strlen($extra);) {
            $id = rs_le16($extra, $x);
            $len = rs_le16($extra, $x + 2);
            if ($id === 1) {
                $q = $x + 4;
                if ($us === 0xFFFFFFFF && $q + 8 <= $x + 4 + $len) { $us = rs_le64($extra, $q); $q += 8; }
                if ($cs === 0xFFFFFFFF && $q + 8 <= $x + 4 + $len) { $cs = rs_le64($extra, $q); $q += 8; }
                if ($local === 0xFFFFFFFF && $q + 8 <= $x + 4 + $len) { $local = rs_le64($extra, $q); }
            }
            $x += 4 + $len;
        }
        $entries[str_replace('\\', '/', $name)] = ['method' => $method, 'crc' => $crc, 'csize' => $cs, 'usize' => $us, 'local' => $local];
        $p += 46 + $nl + $xl + $cl;
    }
    if (!$entries) return ['ok' => false, 'error' => 'empty zip'];
    // The app's folder: the shallowest one with eboot.bin and sce_sys/param.json; a zip with
    // several apps side by side (Perfect Dark and GoldenEye in one) has several, all as shallow.
    $found = [];
    foreach ($entries as $name => $_) {
        if (!preg_match('#^(.*/)?eboot\.bin$#i', $name, $m)) continue;
        $pre = (string)($m[1] ?? '');
        if (isset($entries[$pre . 'sce_sys/param.json'])) $found[$pre] = substr_count($pre, '/');
    }
    if (!$found) return ['ok' => false, 'error' => 'no PS5 app in it', 'entries' => $entries];
    $least = min($found);
    $prefixes = array_keys(array_filter($found, fn($d) => $d === $least));
    sort($prefixes);
    return ['ok' => true, 'entries' => $entries, 'prefix' => $prefixes[0], 'prefixes' => array_slice($prefixes, 0, 8)];
}

// One file out of the zip by its directory entry, checked against its size and CRC; null if not.
function rs_zip_file(string $url, array $en, int $maxSize): ?string {
    if ($en['usize'] > $maxSize || $en['csize'] > $maxSize + 1024) return null;
    $want = 30 + 512 + $en['csize'];
    $buf = rs_range($url, $en['local'], $en['local'] + $want - 1);
    if ($buf === null || strlen($buf) < 30 || substr($buf, 0, 4) !== "PK\x03\x04") return null;
    $start = 30 + rs_le16($buf, 26) + rs_le16($buf, 28);
    if (strlen($buf) < $start + $en['csize']) {
        $buf = rs_range($url, $en['local'], $en['local'] + $start + $en['csize'] - 1);
        if ($buf === null || strlen($buf) < $start + $en['csize']) return null;
    }
    $data = substr($buf, $start, $en['csize']);
    if ($en['method'] === 8) $data = @gzinflate($data, $en['usize'] + 1);
    elseif ($en['method'] !== 0) return null;
    if (!is_string($data) || strlen($data) !== $en['usize']) return null;
    if ((crc32($data) & 0xFFFFFFFF) !== $en['crc']) return null;
    return $data;
}

// ---- Custom sources: a repo, or a list of them, turned into catalog entries -------------------

const RS_CUSTOM_FROM = '1.9.1';

function rs_blocked_list(): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)rs_setting('blocked_sources', '')) as $l) {
        $l = strtolower(trim($l));
        if ($l !== '' && $l[0] !== '#') $out[] = rtrim($l, '/');
    }
    return $out;
}

// A repo (owner/name) or a list's URL the admin took down.
function rs_source_blocked(string $kind, string $value): bool {
    $v = strtolower(rtrim(trim($value), '/'));
    foreach (rs_blocked_list() as $b) {
        if ($kind === 'repo' && ($b === $v || $b === 'https://github.com/' . $v || $b === 'github.com/' . $v)) return true;
        if ($kind === 'list' && ($b === $v || (str_starts_with($b, 'https://') && str_starts_with($v, $b)))) return true;
    }
    return false;
}

// A release file that looks like a PS5 build: how likely (higher is better), -1 never.
function rs_asset_score(string $name): int {
    $n = strtolower($name);
    if (!preg_match('/\.(zip|7z)$/', $n)) return -1;
    if (preg_match('/(^|[^a-z])(pc|win|win32|win64|windows|linux|mac|macos|osx|source|src|debug|symbols|pdb|android|switch|xbox)([^a-z]|$)/', $n)) return -1;
    $s = 1;
    if (preg_match('/ps5|prospero|ppsa\d{5}/', $n)) $s += 3;
    if (str_ends_with($n, '.zip')) $s += 1;
    return $s;
}

// The release file's name with its version turned into * when the tag is in it.
function rs_asset_pattern(string $name, string $tag): string {
    foreach ([$tag, ltrim($tag, 'vV')] as $t) {
        if ($t !== '' && strlen($t) >= 2 && ($p = strpos($name, $t)) !== false) return substr($name, 0, $p) . '*' . substr($name, $p + strlen($t));
    }
    return $name;
}

function rs_pattern_matches(string $pattern, string $name): bool {
    $re = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
    return (bool)preg_match($re, $name);
}

function rs_media_gh(string $bytes, string $key): ?string {
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] < 16 || $info[0] > 4096 || $info[1] > 4096) return null;
    $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
    $dir = RS_MEDIA . '/gh';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return null;
    $name = sha1($key) . '.' . $ext;
    if (!is_file($dir . '/' . $name) && @file_put_contents($dir . '/' . $name, $bytes) === false) return null;
    return '/media/gh/' . $name;
}

function rs_tidy_version(string $tag): string {
    return preg_match('/^[vV][._-]?(?=\d)/', $tag) ? preg_replace('/^[vV][._-]?/', '', $tag) : $tag;
}

// One repo as a catalog entry (cached 15 minutes, failures 5). -> ['ok' => true, 'app' => entry]
// or ['ok' => false, 'error' => words for the player]. $asset: a list's pattern, or ''.
function rs_source_repo(string $repo, string $asset = '', bool $fresh = false): array {
    $key = 'src:' . strtolower($repo) . '|' . $asset;
    $c = $fresh ? null : rs_kv_get($key);
    if ($c) {
        $j = json_decode($c['v'], true);
        if (is_array($j) && (isset($j['apps']) || !($j['ok'] ?? false)) && $c['age'] < (($j['ok'] ?? false) ? 900 : 300)) return $j;
    }
    $res = rs_source_repo_build($repo, $asset);
    rs_kv_put($key, json_encode($res, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $res;
}

function rs_source_repo_build(string $repo, string $asset): array {
    $rel = rs_gh_releases($repo);
    if ($rel['status'] === 404) return ['ok' => false, 'error' => 'That GitHub repo doesn\'t exist, or it\'s private.'];
    if ($rel['status'] === 429) return ['ok' => false, 'error' => 'GitHub is busy right now. Try again in a few minutes.'];
    if ($rel['status'] !== 200) return ['ok' => false, 'error' => 'GitHub didn\'t answer. Try again in a moment.'];
    $list = json_decode($rel['body'], true);
    if (!is_array($list) || !$list) return ['ok' => false, 'error' => 'This repo has no releases yet.'];
    $info = rs_gh_repo($repo) ?? [];
    $pick = null;
    $sawPkg = false;
    $found = null;
    foreach ($list as $r) {
        if (!empty($r['draft'])) continue;
        $cands = [];
        foreach ($r['assets'] as $a) {
            if (preg_match('/\.pkg$/i', $a['name'])) $sawPkg = true;
            if ($asset !== '') { if (rs_pattern_matches($asset, $a['name'])) $cands[] = [$a, 10]; continue; }
            $s = rs_asset_score($a['name']);
            if ($s >= 0) $cands[] = [$a, $s];
        }
        if (!$cands) continue;
        usort($cands, fn($x, $y) => $y[1] <=> $x[1]);
        // A zip has to have a PS5 app in it; a 7z is taken by its name (or as the only one).
        foreach ($cands as [$a, $s]) {
            if (!rs_gh_allowed($a['browser_download_url'])) continue;
            if (preg_match('/\.zip$/i', $a['name'])) {
                $dir = rs_zip_directory($a['browser_download_url'], (int)$a['size']);
                if (!$dir['ok']) continue;
                $found = ['release' => $r, 'asset' => $a, 'zip' => $dir];
                break;
            }
            if (preg_match('/\.7z$/i', $a['name']) && ($s >= 4 || count($cands) === 1 || $asset !== '')) {
                $found = ['release' => $r, 'asset' => $a, 'zip' => null];
                break;
            }
        }
        if ($found) break;
    }
    if (!$found) {
        return ['ok' => false, 'error' => $sawPkg
            ? 'This repo only has a .pkg. Recomp Store can\'t install packages yet.'
            : 'No PS5 build in this repo\'s releases: Recomp Store needs a .zip or .7z with the app\'s folder (eboot.bin and sce_sys).'];
    }
    $r = $found['release'];
    $a = $found['asset'];
    $owner = explode('/', $repo)[0];
    // Each app folder in the zip: its title ID, name and icon from its own sce_sys. A 7z: one
    // app, learned on install.
    $parts = [];
    if ($found['zip']) {
        $dir = $found['zip'];
        foreach ($dir['prefixes'] as $pre) {
            $part = ['titleId' => '', 'name' => '', 'icon' => ''];
            $pj = rs_zip_file($a['browser_download_url'], $dir['entries'][$pre . 'sce_sys/param.json'], 256 << 10);
            $p = $pj !== null ? json_decode($pj, true) : null;
            if (is_array($p)) {
                if (preg_match('/^[A-Z]{4}\d{5}$/', (string)($p['titleId'] ?? ''))) $part['titleId'] = (string)$p['titleId'];
                $lp = is_array($p['localizedParameters'] ?? null) ? $p['localizedParameters'] : [];
                $lang = (string)($lp['defaultLanguage'] ?? 'en-US');
                $part['name'] = (string)($lp[$lang]['titleName'] ?? '');
                if ($part['name'] === '') foreach ($lp as $v) if (is_array($v) && !empty($v['titleName'])) { $part['name'] = (string)$v['titleName']; break; }
            }
            $iconKey = null;
            foreach ($dir['entries'] as $n => $_) if (strcasecmp($n, $pre . 'sce_sys/icon0.png') === 0) $iconKey = $n;
            if ($iconKey !== null) {
                $png = rs_zip_file($a['browser_download_url'], $dir['entries'][$iconKey], 4 << 20);
                if ($png !== null) $part['icon'] = (string)rs_media_gh($png, $repo . '|' . $r['tag_name'] . '|' . $dir['entries'][$iconKey]['crc']);
            }
            // Two folders that aren't two apps (no title ID, or the same one): only the first counts.
            if ($parts && ($part['titleId'] === '' || in_array($part['titleId'], array_column($parts, 'titleId'), true))) continue;
            $parts[] = $part;
        }
    }
    if (!$parts) $parts[] = ['titleId' => '', 'name' => '', 'icon' => ''];
    $avatar = '';
    $needAvatar = false;
    foreach ($parts as $pt) if ($pt['icon'] === '') $needAvatar = true;
    if ($needAvatar && !empty($info['owner']['avatar_url']) && rs_gh_allowed((string)$info['owner']['avatar_url'])) {
        $av = (string)$info['owner']['avatar_url'];
        $k = 'avatar:' . $av;
        $have = rs_kv_get($k);
        if ($have && $have['age'] < 7 * 86400 && $have['v'] !== '') $avatar = $have['v'];
        else {
            $u = $av . (str_contains($av, '?') ? '&' : '?') . 's=256';
            $g = rs_http($u, ['max' => 2 << 20, 'timeout' => 15]);
            if ($g['status'] === 200) $avatar = (string)rs_media_gh($g['body'], $k);
            rs_kv_put($k, $avatar);
        }
    }
    $mb = (int)$a['size'];
    $downloads = 0;
    foreach ($list as $rr) foreach ($rr['assets'] as $aa) if ($aa['name'] === $a['name'] || rs_pattern_matches(rs_asset_pattern($a['name'], $r['tag_name']), $aa['name'])) $downloads += (int)$aa['download_count'];
    $desc = trim((string)($info['description'] ?? ''));
    $apps = [];
    foreach ($parts as $pt) {
    $titleId = $pt['titleId'];
    $name = $pt['name'];
    $icon = $pt['icon'] !== '' ? $pt['icon'] : $avatar;
    $app = [
        'id' => $titleId !== '' ? $titleId : 'gh-' . substr(sha1(strtolower($repo)), 0, 10),
        'titleId' => $titleId,
        'name' => $name !== '' ? mb_substr($name, 0, 80) : explode('/', $repo)[1],
        'kind' => 'community', 'author' => $owner,
        'blurb' => mb_substr($desc, 0, 180), 'description' => mb_substr($desc, 0, 2000),
        'version' => rs_tidy_version((string)$r['tag_name']), 'released' => substr((string)$r['published_at'], 0, 10),
        'size' => $mb > 0 ? rs_human_size($mb) : '',
        'source' => ['type' => 'github', 'repo' => $repo, 'asset' => $asset !== '' ? $asset : rs_asset_pattern($a['name'], (string)$r['tag_name'])],
        'icon' => $icon !== '' ? rs_abs($icon) : '', 'hero' => '',
        'category' => 'apps', 'tags' => [], 'minStore' => RS_CUSTOM_FROM,
        'custom' => true, 'sourceName' => $repo,
        'stars' => (int)($info['stargazers_count'] ?? 0), 'downloads' => $downloads,
    ];
    if (!empty($a['digest']) && preg_match('/^sha256:([0-9a-f]{64})$/', (string)$a['digest'], $m)) $app['sha256'] = $m[1];
    $apps[] = $app;
    }
    return ['ok' => true, 'apps' => $apps];
}

// A list's address: https only (or, in tests, the test GitHub's own host).
function rs_list_url_ok(string $u): bool {
    if (preg_match('#^https://[^\s<>"]{4,500}$#', $u)) return true;
    $t = getenv('RS_GH_API');
    if (!is_string($t) || $t === '') return false;
    $a = parse_url($u);
    $b = parse_url($t);
    return is_array($a) && is_array($b) && ($a['scheme'] ?? '') === ($b['scheme'] ?? '') && ($a['host'] ?? '') === ($b['host'] ?? '') && ($a['port'] ?? 0) === ($b['port'] ?? 0);
}

// A list someone hosts: { name, author, apps: [ {repo, asset?, name?, category?, tags?, description?} ] }.
function rs_source_list(string $url, float $deadline): array {
    $r = rs_http($url, ['max' => 256 << 10, 'timeout' => 15, 'headers' => ['Accept: application/json']]);
    if (!rs_list_url_ok($r['url'])) return ['ok' => false, 'error' => 'A list has to be at an https:// address.'];
    if ($r['error'] === 'too big') return ['ok' => false, 'error' => 'That list is too big (256 KB at most).'];
    if ($r['status'] !== 200) return ['ok' => false, 'error' => $r['status'] === 404 ? 'Nothing is at that address.' : 'That list couldn\'t be read right now. Try again in a moment.'];
    $j = json_decode($r['body'], true);
    if (!is_array($j) || !is_array($j['apps'] ?? null)) return ['ok' => false, 'error' => 'That isn\'t a Recomp Store list: it needs a name and apps, each with a GitHub repo.'];
    $clean = fn($v, int $n) => mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)$v) ?? ''), 0, $n);
    $out = ['ok' => true, 'source' => ['kind' => 'list', 'value' => $url, 'name' => $clean($j['name'] ?? '', 60) ?: 'A list', 'author' => $clean($j['author'] ?? '', 60)],
            'apps' => [], 'errors' => []];
    $seen = [];
    foreach (array_slice($j['apps'], 0, 100) as $x) {
        if (!is_array($x)) continue;
        $repo = preg_replace('#^https://github\.com/#i', '', trim((string)($x['repo'] ?? '')));
        $repo = rtrim((string)$repo, '/');
        if (!rs_repo_ok($repo)) { $out['errors'][] = ['repo' => mb_substr((string)($x['repo'] ?? ''), 0, 120), 'error' => 'Only GitHub repos (owner/name) can be in a list.']; continue; }
        if (isset($seen[strtolower($repo)])) continue;
        $seen[strtolower($repo)] = true;
        if (rs_source_blocked('repo', $repo)) { $out['errors'][] = ['repo' => $repo, 'error' => 'This source was taken down.']; continue; }
        $asset = trim((string)($x['asset'] ?? ''));
        if ($asset !== '' && (strlen($asset) > 120 || !preg_match('/^[^\/\\\\]+\.(zip|7z)$/i', $asset))) $asset = '';
        $cachedOnly = microtime(true) > $deadline;
        if ($cachedOnly) {
            $c = rs_kv_get('src:' . strtolower($repo) . '|' . $asset);
            $res = $c ? json_decode($c['v'], true) : null;
            if (!is_array($res)) { $out['errors'][] = ['repo' => $repo, 'error' => 'Still being checked. It shows up on the next refresh.']; continue; }
        } else {
            $res = rs_source_repo($repo, $asset);
        }
        if (!($res['ok'] ?? false) || empty($res['apps'])) { $out['errors'][] = ['repo' => $repo, 'error' => (string)($res['error'] ?? 'It couldn\'t be read.')]; continue; }
        foreach ($res['apps'] as $app) {
            // The list's own name is for a repo with one app; two apps keep their own names.
            if (count($res['apps']) === 1 && ($n = $clean($x['name'] ?? '', 80)) !== '') $app['name'] = $n;
            if (($d = $clean($x['description'] ?? '', 2000)) !== '') { $app['description'] = $d; $app['blurb'] = mb_substr($d, 0, 180); }
            $cat = (string)($x['category'] ?? '');
            if (array_key_exists($cat, RS_CATEGORIES)) $app['category'] = $cat;
            if (is_array($x['tags'] ?? null)) $app['tags'] = rs_tags_list(implode(',', array_map('strval', array_filter($x['tags'], 'is_scalar'))));
            $app['sourceName'] = $out['source']['name'];
            $out['apps'][] = $app;
        }
    }
    return $out;
}

// ---- Installs counted, and the charts ----------------------------------------------------------

function rs_stats_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS app_installs (
        install_id CHAR(16) NOT NULL,
        app_id VARCHAR(48) NOT NULL,
        version VARCHAR(64) NOT NULL DEFAULT \'\',
        first_at DATETIME NOT NULL,
        last_at DATETIME NOT NULL,
        removed_at DATETIME NULL,
        PRIMARY KEY (install_id, app_id),
        INDEX (app_id), INDEX (first_at)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS app_published (
        app_id VARCHAR(48) PRIMARY KEY,
        first_at DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// Every app id in the published catalog, with its entry.
function rs_published_apps(): array {
    $cat = json_decode((string)@file_get_contents(RS_CATALOG), true);
    $out = [];
    foreach (['apps', 'newerStoreApps', 'v2Apps'] as $l) foreach (is_array($cat[$l] ?? null) ? $cat[$l] : [] as $a) if (is_array($a) && isset($a['id'])) $out[(string)$a['id']] = $a;
    return $out;
}

// The charts, rebuilt at most every 10 minutes. GitHub's numbers come from the cache, at most
// 10 repos refreshed per rebuild (6 hours old or older).
function rs_charts(bool $fresh = false): array {
    if (!$fresh && ($c = rs_kv_get('charts')) && $c['age'] < 600) {
        $j = json_decode($c['v'], true);
        if (is_array($j)) return $j;
    }
    rs_stats_tables();
    $db = rs_db();
    $apps = rs_published_apps();
    $ids = array_keys($apps);
    // When each was first published (the ones from before 2.0: their release date).
    $first = $db->query('SELECT app_id, UNIX_TIMESTAMP(first_at) t FROM app_published')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($apps as $id => $a) {
        if (isset($first[$id])) continue;
        $t = strtotime((string)($a['released'] ?? '')) ?: time();
        $db->prepare('INSERT IGNORE INTO app_published (app_id, first_at) VALUES (?, FROM_UNIXTIME(?))')->execute([$id, $t]);
        $first[$id] = $t;
    }
    $top = $db->query('SELECT app_id, COUNT(*) n FROM app_installs WHERE removed_at IS NULL GROUP BY app_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $week = $db->query('SELECT app_id, COUNT(*) n FROM app_installs WHERE first_at > (NOW() - INTERVAL 7 DAY) GROUP BY app_id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $stats = [];
    $updated = [];
    $refreshed = 0;
    foreach ($apps as $id => $a) {
        $stats[$id] = ['installs' => (int)($top[$id] ?? 0), 'stars' => 0, 'downloads' => 0];
        $when = strtotime((string)($a['released'] ?? '')) ?: 0;
        $repo = (string)($a['source']['repo'] ?? '');
        if (($a['source']['type'] ?? '') === 'github' && rs_repo_ok($repo)) {
            $age = $refreshed < 10 ? 6 * 3600 : PHP_INT_MAX;
            $rel = rs_gh_releases($repo, $age);
            $info = rs_gh_repo($repo, $age);
            if ($age !== PHP_INT_MAX) ++$refreshed;
            $stats[$id]['stars'] = (int)($info['stargazers_count'] ?? 0);
            $list = $rel['status'] === 200 ? json_decode($rel['body'], true) : [];
            $pat = (string)($a['source']['asset'] ?? '');
            foreach (is_array($list) ? $list : [] as $r) {
                if (!empty($r['draft'])) continue;
                $match = false;
                foreach ($r['assets'] as $x) if ($pat !== '' && rs_pattern_matches($pat, $x['name'])) { $stats[$id]['downloads'] += (int)$x['download_count']; $match = true; }
                if ($match) $when = max($when, (int)(strtotime((string)$r['published_at']) ?: 0));
            }
        }
        // No release date known (GitHub unreachable, a fixed link): when it was first published.
        $updated[$id] = $when > 0 ? $when : (int)($first[$id] ?? 0);
    }
    $order = fn(array $m) => array_keys(array_filter($m, fn($n) => $n > 0));
    $topIds = array_filter($top, fn($id) => isset($apps[$id]), ARRAY_FILTER_USE_KEY);
    arsort($topIds);
    $weekIds = array_filter($week, fn($id) => isset($apps[$id]), ARRAY_FILTER_USE_KEY);
    arsort($weekIds);
    $newIds = array_intersect_key($first, $apps);
    arsort($newIds);
    arsort($updated);
    // Apps from custom sources: listed once three or more consoles have them. Never one of the
    // admin's own apps that isn't out (testers only, hidden, beta).
    $community = [];
    $mine = array_flip(array_map('strval', $db->query('SELECT id FROM games')->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($top as $id => $n) if (!isset($apps[$id]) && !isset($mine[(string)$id]) && $n >= 3) $community[] = ['id' => (string)$id, 'installs' => (int)$n];
    usort($community, fn($x, $y) => $y['installs'] <=> $x['installs']);
    $out = [
        // When it was built: 'built', since 'updated' is the Recently updated list.
        'ok' => true, 'built' => gmdate('c'),
        'top' => array_map(fn($id) => ['id' => (string)$id, 'installs' => (int)$topIds[$id]], array_slice(array_keys($topIds), 0, 50)),
        'trending' => array_map(fn($id) => ['id' => (string)$id, 'installs' => (int)$weekIds[$id]], array_slice(array_keys($weekIds), 0, 50)),
        'new' => array_map('strval', array_slice(array_keys($newIds), 0, 50)),
        'updated' => array_map('strval', array_slice($order($updated), 0, 50)),
        'stats' => (object)$stats,
        'community' => array_slice($community, 0, 50),
        // (2.2) People also installed: for each app, others its consoles have (ids only).
        'also' => (object)rs_charts_also($apps, $top, $fresh),
    ];
    rs_kv_put('charts', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $out;
}

// (2.2) People also installed, for apps in the published catalog (so never hidden, beta or
// testers-only ones): app id => up to 8 other ids, most telling first. Rebuilt at most hourly.
// An app gets a list once 5 consoles have it; another app is on it once 3 of those consoles have
// that one too. Ranked by the installs together beyond what the other app's popularity alone
// would give (together - A's installs x B's installs / consoles), so an app on nearly every console
// isn't at the top of every list; only ones above that line are listed. Only ids go out.
const RS_ALSO_MIN_APP = 5, RS_ALSO_MIN_PAIR = 3, RS_ALSO_MAX = 8;
function rs_charts_also(array $apps, array $top, bool $fresh = false): array {
    $map = null;
    if (!$fresh && ($c = rs_kv_get('charts_also')) && $c['age'] < 3600) {
        $j = json_decode($c['v'], true);
        if (is_array($j)) $map = $j;
    }
    if ($map === null) {
        $map = rs_also_build(array_keys($apps), $top);
        rs_kv_put('charts_also', json_encode((object)$map, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    // Hidden or taken off since it was built: left out now.
    $out = [];
    foreach ($map as $id => $list) {
        if (!isset($apps[$id]) || !is_array($list)) continue;
        $keep = array_values(array_filter(array_map('strval', $list), fn($x) => isset($apps[$x]) && $x !== (string)$id));
        if ($keep) $out[(string)$id] = array_slice($keep, 0, RS_ALSO_MAX);
    }
    return $out;
}

function rs_also_build(array $ids, array $top): array {
    $ids = array_values(array_filter(array_map('strval', $ids), fn($x) => $x !== ''));
    $from = array_values(array_filter($ids, fn($x) => (int)($top[$x] ?? 0) >= RS_ALSO_MIN_APP));
    if (!$from) return [];
    $db = rs_db();
    $consoles = (int)$db->query('SELECT COUNT(DISTINCT install_id) FROM app_installs WHERE removed_at IS NULL')->fetchColumn();
    if ($consoles < 1) return [];
    // One pass: every pair of catalog apps on the same console, counted, 3 or more only.
    $in = fn(array $l) => implode(',', array_fill(0, count($l), '?'));
    $st = $db->prepare('SELECT a.app_id, b.app_id, COUNT(*) AS n FROM app_installs a
        JOIN app_installs b ON b.install_id = a.install_id AND b.app_id <> a.app_id AND b.removed_at IS NULL
        WHERE a.removed_at IS NULL AND a.app_id IN (' . $in($from) . ') AND b.app_id IN (' . $in($ids) . ')
        GROUP BY a.app_id, b.app_id HAVING n >= ' . RS_ALSO_MIN_PAIR);
    $st->execute(array_merge($from, $ids));
    $pairs = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$x, $y, $n]) {
        $x = (string)$x; $y = (string)$y; $n = (int)$n;
        $expected = (int)($top[$x] ?? 0) * (int)($top[$y] ?? 0) / $consoles;
        $beyond = $n - $expected;
        if ($beyond <= 0) continue;
        $pairs[$x][] = [$y, $beyond, $n];
    }
    $map = [];
    foreach ($pairs as $x => $list) {
        usort($list, fn($p, $q) => [$q[1], $q[2], $p[0]] <=> [$p[1], $p[2], $q[0]]);
        $map[$x] = array_map(fn($p) => $p[0], array_slice($list, 0, RS_ALSO_MAX));
    }
    return $map;
}
