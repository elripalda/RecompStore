<?php
// Recomp Store admin (2.1): two-step login with an authenticator app (TOTP, RFC 6238: 30-second,
// 6-digit codes, HMAC-SHA1), plus one-time recovery codes for a lost phone.
declare(strict_types=1);

function rs_users_2fa(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    foreach (['totp_secret' => "VARCHAR(64) NOT NULL DEFAULT ''", 'totp_codes' => 'TEXT NULL', 'totp_last' => 'BIGINT NOT NULL DEFAULT 0'] as $col => $def) {
        $has = $db->query("SHOW COLUMNS FROM users LIKE '$col'")->fetch();
        if (!$has) $db->exec("ALTER TABLE users ADD COLUMN $col $def");
    }
    $done = true;
}

function rs_b32_encode(string $bin): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) $out .= $a[bindec(str_pad($chunk, 5, '0'))];
    return $out;
}

function rs_b32_decode(string $s): string {
    $a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($a, $c)), 5, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function rs_totp_at(string $secretB32, int $counter): string {
    $h = hash_hmac('sha1', pack('J', $counter), rs_b32_decode($secretB32), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

// The time step the code is for (one either side allowed for a slow clock), or 0. A step at or
// before $last was already used and doesn't count again.
function rs_totp_check(string $secretB32, string $code, int $last): int {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return 0;
    $now = intdiv(time(), 30);
    foreach ([0, -1, 1] as $d) {
        $c = $now + $d;
        if ($c > $last && hash_equals(rs_totp_at($secretB32, $c), $code)) return $c;
    }
    return 0;
}

function rs_new_totp_secret(): string {
    return rs_b32_encode(random_bytes(20));
}

function rs_totp_uri(string $secretB32, string $user): string {
    $host = parse_url(rs_setting('site_url', 'https://recompstore.com'), PHP_URL_HOST) ?: 'recompstore.com';
    return 'otpauth://totp/' . rawurlencode('Recomp Store admin:' . $user . '@' . $host) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode('Recomp Store admin') . '&digits=6&period=30';
}

// Eight one-time codes for a lost phone: shown once, kept as hashes.
function rs_new_recovery_codes(): array {
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $raw = strtolower(rs_b32_encode(random_bytes(6)));
        $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
    }
    return $codes;
}

function rs_use_recovery_code(int $uid, string $code): bool {
    $code = strtolower(preg_replace('/[^a-z2-7]/i', '', $code));
    if (strlen($code) !== 10) return false;
    $st = rs_db()->prepare('SELECT totp_codes FROM users WHERE id = ?');
    $st->execute([$uid]);
    $hashes = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($hashes)) return false;
    foreach ($hashes as $i => $hsh) {
        if (is_string($hsh) && password_verify($code, $hsh)) {
            unset($hashes[$i]);
            rs_db()->prepare('UPDATE users SET totp_codes = ? WHERE id = ?')->execute([json_encode(array_values($hashes)), $uid]);
            return true;
        }
    }
    return false;
}
