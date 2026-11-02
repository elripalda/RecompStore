<?php
// (3.5) Cloud saves on Backblaze B2 (its S3-compatible API), so they never sit on the web server.
// The login lives outside public_html in recompstore-b2.php:
//   <?php return ['endpoint' => 's3.us-west-004.backblazeb2.com', 'bucket' => '...', 'key_id' => '...', 'key' => '...'];
// Uploads go up from here; downloads are short-lived signed links, so the file comes straight from B2.
declare(strict_types=1);

function rs_b2_cfg(): ?array {
    static $c = false;
    if ($c !== false) return $c;
    $f = dirname(RS_PUBLIC) . '/recompstore-b2.php';
    $x = is_file($f) ? require $f : null;
    // Or what was saved in admin, Settings, Cloud saves (the key locked like the site's other secrets).
    if (!is_array($x) && function_exists('rs_setting')) {
        require_once __DIR__ . '/mail.php';
        require_once __DIR__ . '/discord.php';
        $x = ['endpoint' => trim((string)rs_setting('b2_endpoint', '')), 'bucket' => trim((string)rs_setting('b2_bucket', '')),
              'key_id' => trim((string)rs_setting('b2_key_id', '')), 'key' => rs_secret_get('b2_key')];
    }
    $ok = is_array($x) && !empty($x['endpoint']) && !empty($x['bucket']) && !empty($x['key_id']) && !empty($x['key']);
    if ($ok) {
        $x['endpoint'] = preg_replace('#^https?://#', '', rtrim((string)$x['endpoint'], '/'));
        $x['region'] = (string)($x['region'] ?? (preg_match('/^s3\.([a-z0-9-]+)\.backblazeb2\.com$/', $x['endpoint'], $m) ? $m[1] : 'us-east-1'));
    }
    return $c = $ok ? $x : null;
}

function rs_b2_on(): bool { return rs_b2_cfg() !== null; }

function rs_b2_path(string $key): string {
    $c = rs_b2_cfg();
    return '/' . rawurlencode($c['bucket']) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
}

function rs_b2_sign_key(string $secret, string $date, string $region): string {
    $k = hash_hmac('sha256', $date, 'AWS4' . $secret, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', 's3', $k, true);
    return hash_hmac('sha256', 'aws4_request', $k, true);
}

// One signed request (header auth). -> [status, body]
function rs_b2_request(string $method, string $key, ?string $file = null, array $extra = []): array {
    $c = rs_b2_cfg();
    if (!$c) throw new RuntimeException('Cloud storage isn\'t set up.');
    $now = gmdate('Ymd\THis\Z');
    $date = substr($now, 0, 8);
    $path = rs_b2_path($key);
    $payload = 'UNSIGNED-PAYLOAD';
    $headers = ['host' => $c['endpoint'], 'x-amz-content-sha256' => $payload, 'x-amz-date' => $now] + array_change_key_case($extra);
    ksort($headers);
    $canonHeaders = '';
    foreach ($headers as $k => $v) $canonHeaders .= $k . ':' . trim((string)$v) . "\n";
    $signed = implode(';', array_keys($headers));
    $canon = "$method\n$path\n\n$canonHeaders\n$signed\n$payload";
    $scope = "$date/{$c['region']}/s3/aws4_request";
    $sts = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canon);
    $sig = hash_hmac('sha256', $sts, rs_b2_sign_key($c['key'], $date, $c['region']));
    $auth = "AWS4-HMAC-SHA256 Credential={$c['key_id']}/$scope, SignedHeaders=$signed, Signature=$sig";

    $ch = curl_init('https://' . $c['endpoint'] . $path);
    $h = ['Authorization: ' . $auth];
    foreach ($headers as $k => $v) if ($k !== 'host') $h[] = "$k: $v";
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10];
    $fp = null;
    if ($file !== null) {
        $fp = fopen($file, 'rb');
        $size = (int)filesize($file);
        $opts += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fp, CURLOPT_INFILESIZE => $size];
        $h[] = 'Content-Length: ' . $size;
    }
    curl_setopt_array($ch, $opts + [CURLOPT_HTTPHEADER => $h]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($fp) fclose($fp);
    return [$code, is_string($body) ? $body : ''];
}

function rs_b2_put(string $key, string $file): void {
    for ($try = 0; $try < 3; $try++) {
        [$code] = rs_b2_request('PUT', $key, $file, ['content-type' => 'application/zip']);
        if ($code >= 200 && $code < 300) return;
        usleep(300000 * ($try + 1));
    }
    throw new RuntimeException('Cloud saves can\'t be kept right now. Try again later.');
}

function rs_b2_delete(string $key): void {
    try { rs_b2_request('DELETE', $key); } catch (Throwable $e) { /* gone or unreachable: the database row is what counts */ }
}

// A link that downloads the file straight from B2 for a few minutes.
function rs_b2_link(string $key, string $filename, int $ttl = 600): string {
    $c = rs_b2_cfg();
    $now = gmdate('Ymd\THis\Z');
    $date = substr($now, 0, 8);
    $scope = "$date/{$c['region']}/s3/aws4_request";
    $q = [
        'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
        'X-Amz-Credential' => "{$c['key_id']}/$scope",
        'X-Amz-Date' => $now,
        'X-Amz-Expires' => (string)$ttl,
        'X-Amz-SignedHeaders' => 'host',
        'response-content-disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"',
        'response-content-type' => 'application/zip',
    ];
    ksort($q);
    $qs = implode('&', array_map(fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v), array_keys($q), $q));
    $path = rs_b2_path($key);
    $canon = "GET\n$path\n$qs\nhost:{$c['endpoint']}\n\nhost\nUNSIGNED-PAYLOAD";
    $sts = "AWS4-HMAC-SHA256\n$now\n$scope\n" . hash('sha256', $canon);
    $sig = hash_hmac('sha256', $sts, rs_b2_sign_key($c['key'], $date, $c['region']));
    return 'https://' . $c['endpoint'] . $path . '?' . $qs . '&X-Amz-Signature=' . $sig;
}

// Settings' check: a small file up, read back through a link, and removed. -> '' when it all worked.
function rs_b2_test(): string {
    if (!rs_b2_on()) return 'Fill in all four boxes first.';
    $t = tempnam(sys_get_temp_dir(), 'b2t');
    $word = 'recomp check ' . bin2hex(random_bytes(4));
    file_put_contents($t, $word);
    try {
        [$code, $body] = rs_b2_request('PUT', 'check/hello.txt', $t, ['content-type' => 'text/plain']);
        if ($code < 200 || $code >= 300) {
            if (preg_match('#<Message>(.*?)</Message>#s', $body, $m)) return 'Backblaze said: ' . trim($m[1]);
            return 'Backblaze answered HTTP ' . $code . ($code === 0 ? ' (it couldn\'t be reached)' : '') . '.';
        }
        $ch = curl_init(rs_b2_link('check/hello.txt', 'hello.txt', 60));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
        $got = (string)curl_exec($ch);
        $gc = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        rs_b2_delete('check/hello.txt');
        if ($gc !== 200 || $got !== $word) return 'The upload worked, but reading it back through a link answered HTTP ' . $gc . '.';
        return '';
    } finally { @unlink($t); }
}
