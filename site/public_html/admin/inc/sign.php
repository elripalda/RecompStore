<?php
// Recomp Store website (2.1): signing with Recomp Store Signer, and the admin's change log.
//
// Once the signer has told the site its public keys (Settings > Signer), Publish no longer writes
// catalog.json: it writes a waiting copy, and the signer on the owner's PC shows what changed and
// signs it. Only then does it go out, as catalog.json with catalog.sig beside it. The testers'
// catalog goes the same way, and every beta zip needs a signature before a 2.1 store installs it.
// Stores from 2.1 check every signature against the keys built into them, so a break-in here
// can't push anything to them. Format: signer/sig.go, app/src/rs_sig.h.
declare(strict_types=1);

const RS_SIG_HEADER = 'RecompStore signature v1';
const RS_SIG_KINDS = ['catalog', 'catalog-test', 'store', 'beta'];

// Beside public_html when the host allows it, otherwise public_html/private-files (blocked).
function rs_private_dir(): string {
    $outside = dirname(RS_PUBLIC) . '/recompstore-private';
    $inside = RS_PUBLIC . '/private-files';
    $dir = is_dir($outside) ? $outside : (is_dir($inside) ? $inside : (is_writable(dirname(RS_PUBLIC)) ? $outside : $inside));
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not make the folder ' . $dir . '.');
    if (str_starts_with($dir, RS_PUBLIC) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    return $dir;
}

function rs_key_id(string $pubHex): string {
    return substr(hash('sha256', (string)hex2bin($pubHex)), 0, 16);
}

// The signer's public keys: [['name' => 'main'|'backup', 'id' => 16 hex, 'pub' => 64 hex], ...].
function rs_sign_keys(): array {
    $keys = json_decode(rs_setting('sign_keys', '[]'), true);
    $out = [];
    foreach (is_array($keys) ? $keys : [] as $k) {
        if (is_array($k) && preg_match('/^[0-9a-f]{64}$/', (string)($k['pub'] ?? ''))) {
            $out[] = ['name' => (string)($k['name'] ?? 'key'), 'id' => rs_key_id($k['pub']), 'pub' => $k['pub']];
        }
    }
    return $out;
}

function rs_sign_on(): bool {
    return rs_sign_keys() !== [];
}

// "main: <hex> (id)\nbackup: <hex> (id)" from the signer's public-keys.txt.
function rs_parse_public_keys(string $text): array {
    preg_match_all('/^(main|backup): ([0-9a-f]{64})/m', $text, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) $out[] = ['name' => $x[1], 'id' => rs_key_id($x[2]), 'pub' => $x[2]];
    return $out;
}

function rs_sig_parse(string $text): ?array {
    $text = str_replace("\r\n", "\n", $text);
    if (!str_starts_with($text, RS_SIG_HEADER . "\n")) return null;
    $f = [];
    foreach (explode("\n", $text) as $line) {
        $p = strpos($line, ': ');
        if ($p !== false) $f[substr($line, 0, $p)] = trim(substr($line, $p + 2));
    }
    $sig = [
        'kind' => (string)($f['kind'] ?? ''), 'file' => basename((string)($f['file'] ?? '')), 'size' => (int)($f['size'] ?? 0),
        'sha256' => strtolower((string)($f['sha256'] ?? '')), 'signed' => (string)($f['signed'] ?? ''),
        'key' => (string)($f['key'] ?? ''), 'sig' => strtolower((string)($f['sig'] ?? '')),
    ];
    if (!in_array($sig['kind'], RS_SIG_KINDS, true) || !preg_match('/^[0-9a-f]{64}$/', $sig['sha256'])
        || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $sig['signed']) || !preg_match('/^[0-9a-f]{16}$/', $sig['key'])
        || !preg_match('/^[0-9a-f]{128}$/', $sig['sig'])) return null;
    return $sig;
}

// The signature's fields when it's good for these bytes (or this SHA-256) and of this kind.
function rs_sig_ok(string $text, string $kind, string $sha256): ?array {
    $sig = rs_sig_parse($text);
    if ($sig === null || $sig['kind'] !== $kind || !hash_equals($sig['sha256'], strtolower($sha256))) return null;
    $msg = RS_SIG_HEADER . "\nkind: {$sig['kind']}\nsha256: {$sig['sha256']}\nsigned: {$sig['signed']}\n";
    foreach (rs_sign_keys() as $k) {
        if ($k['id'] === $sig['key'] && rs_ed25519_verify((string)hex2bin($sig['sig']), $msg, (string)hex2bin($k['pub']))) return $sig;
    }
    return null;
}

// Ed25519 with PHP's sodium extension, or, where the host leaves it out, sodium_compat (pure PHP,
// in sodium_compat/ beside this file).
function rs_ed25519_verify(string $sig, string $msg, string $pub): bool {
    if (strlen($sig) !== 64 || strlen($pub) !== 32) return false;
    if (function_exists('sodium_crypto_sign_verify_detached')) return sodium_crypto_sign_verify_detached($sig, $msg, $pub);
    if (!class_exists('ParagonIE_Sodium_Compat', false)) require_once __DIR__ . '/sodium_compat/autoload.php';
    ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true; // its own code, never a half-there extension
    try {
        return ParagonIE_Sodium_Compat::crypto_sign_verify_detached($sig, $msg, $pub);
    } catch (Throwable $e) {
        return false;
    }
}

// ---- Catalogs waiting for a signature ---------------------------------------------------------

function rs_catalog_kinds(): array {
    return ['catalog', 'catalog-test'];
}

function rs_pending_file(string $kind): string {
    return rs_private_dir() . '/pending-' . $kind . '.json';
}

// Where a signed catalog goes out: everyone's is public; the testers' only through api/catalog.php.
function rs_signed_file(string $kind): string {
    return $kind === 'catalog' ? RS_CATALOG : rs_private_dir() . '/catalog-test.json';
}

function rs_sig_file(string $kind): string {
    return $kind === 'catalog' ? RS_PUBLIC . '/catalog.sig' : rs_private_dir() . '/catalog-test.json.sig';
}

function rs_catalog_json(array $cat): string {
    $json = json_encode($cat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('The catalog couldn\'t be written as JSON.');
    return $json . "\n";
}

function rs_put_atomic(string $path, string $bytes): void {
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $bytes) === false || !rename($tmp, $path)) throw new RuntimeException('Could not write ' . basename($path) . '.');
}

function rs_write_pending(string $kind, array $cat): void {
    rs_put_atomic(rs_pending_file($kind), rs_catalog_json($cat));
}

// What's waiting for the signer: catalogs published since, and beta zips nobody signed yet.
function rs_sign_pending(): array {
    $items = [];
    foreach (rs_catalog_kinds() as $kind) {
        $p = rs_pending_file($kind);
        if (!is_file($p)) continue;
        $bytes = (string)file_get_contents($p);
        $items[] = ['kind' => $kind, 'file' => $kind === 'catalog' ? 'catalog.json' : 'catalog-test.json', 'size' => strlen($bytes),
            'sha256' => hash('sha256', $bytes), 'when' => gmdate('M j, H:i', (int)filemtime($p)) . ' UTC'];
    }
    foreach (rs_beta_builds_unsigned() as $b) $items[] = $b;
    return $items;
}

function rs_sign_waiting(): int {
    try {
        return rs_sign_on() ? count(rs_sign_pending()) : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

// The signed catalog goes out: the exact bytes the signer saw, with their signature.
function rs_publish_signed(string $kind, string $sigText): string {
    $p = rs_pending_file($kind);
    if (!is_file($p)) throw new RuntimeException('There\'s no ' . ($kind === 'catalog' ? 'catalog' : 'testers\' catalog') . ' waiting.');
    $bytes = (string)file_get_contents($p);
    $sig = rs_sig_ok($sigText, $kind, hash('sha256', $bytes));
    if ($sig === null) throw new RuntimeException('That signature isn\'t good for the catalog waiting here (published again since?).');
    $old = rs_sig_parse((string)@file_get_contents(rs_sig_file($kind)));
    if ($old !== null && strcmp($sig['signed'], $old['signed']) < 0) throw new RuntimeException('That signature is older than the one out now.');
    rs_put_atomic(rs_signed_file($kind), $bytes);
    rs_put_atomic(rs_sig_file($kind), $sigText);
    @unlink($p);
    if ($kind === 'catalog') rs_after_publish(json_decode($bytes, true) ?: []);
    rs_log('signed', $kind, 'key ' . $sig['key']);
    return $kind === 'catalog' ? 'Published. Every PS5 gets it on its next refresh.' : 'Published to testers.';
}

// After the catalog goes out (signed, or straight away before signing is set up).
function rs_after_publish(array $cat): void {
    rs_set_setting('published_at', gmdate('c'));
    try {
        rs_stats_tables();
        $mark = rs_db()->prepare('INSERT IGNORE INTO app_published (app_id, first_at) VALUES (?, NOW())');
        foreach (array_merge($cat['apps'] ?? [], $cat['newerStoreApps'] ?? [], $cat['v2Apps'] ?? []) as $a) $mark->execute([(string)($a['id'] ?? '')]);
    } catch (Throwable $e) {
    }
}

// ---- Beta zips ---------------------------------------------------------------------------------

function rs_sig_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS signatures (
        sha256 CHAR(64) NOT NULL,
        kind VARCHAR(16) NOT NULL,
        file VARCHAR(190) NOT NULL DEFAULT \'\',
        sig TEXT NOT NULL,
        key_id CHAR(16) NOT NULL,
        signed_at DATETIME NOT NULL,
        PRIMARY KEY (kind, sha256)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_sig_for(string $kind, string $sha256): string {
    if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) return '';
    try {
        rs_sig_tables();
        $st = rs_db()->prepare('SELECT sig FROM signatures WHERE kind = ? AND sha256 = ?');
        $st->execute([$kind, $sha256]);
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

// A beta game's builds: the current zip first, then the earlier ones kept for testers to go back to.
function rs_beta_builds(array $g): array {
    $out = [];
    if (($g['beta_file'] ?? '') !== '') {
        $out[] = ['file' => $g['beta_file'], 'version' => (string)($g['beta_version'] ?: $g['version']), 'sha256' => (string)$g['beta_sha256'],
            'size' => (int)$g['beta_size'], 'notes' => (string)($g['beta_notes'] ?? ''), 'at' => (string)($g['beta_at'] ?? ''), 'current' => true];
    }
    foreach ((array)($g['beta_history'] ?? []) as $h) {
        if (!is_array($h) || ($h['file'] ?? '') === '' || ($h['file'] ?? '') === ($g['beta_file'] ?? '')) continue;
        if (!is_file(rs_beta_dir() . '/' . basename((string)$h['file']))) continue;
        $out[] = ['file' => (string)$h['file'], 'version' => (string)($h['version'] ?? ''), 'sha256' => (string)($h['sha256'] ?? ''),
            'size' => (int)($h['size'] ?? 0), 'notes' => (string)($h['notes'] ?? ''), 'at' => (string)($h['at'] ?? ''), 'current' => false];
    }
    return $out;
}

function rs_beta_builds_unsigned(): array {
    $out = [];
    foreach (rs_games(true) as $g) {
        if (!$g['beta']) continue;
        foreach (rs_beta_builds($g) as $b) {
            if (!preg_match('/^[0-9a-f]{64}$/', $b['sha256']) || rs_sig_for('beta', $b['sha256']) !== '') continue;
            $out[] = ['kind' => 'beta', 'id' => $g['id'], 'name' => $g['name'] . ' ' . $b['version'], 'file' => $b['file'], 'size' => $b['size'],
                'sha256' => $b['sha256'], 'when' => $b['at'] !== '' ? $b['at'] : '', 'note' => $b['current'] ? '' : 'An earlier build testers can go back to.'];
        }
    }
    return $out;
}

function rs_store_beta_sig(string $sigText): string {
    $sig = rs_sig_parse($sigText);
    if ($sig === null || $sig['kind'] !== 'beta') throw new RuntimeException('That isn\'t a beta signature.');
    $match = null;
    foreach (rs_games(true) as $g) {
        if (!$g['beta']) continue;
        foreach (rs_beta_builds($g) as $b) {
            if (hash_equals(strtolower($b['sha256']), $sig['sha256'])) { $match = [$g, $b]; break 2; }
        }
    }
    if ($match === null) throw new RuntimeException('No beta zip here has that SHA-256. Upload it in the admin first.');
    if (rs_sig_ok($sigText, 'beta', $sig['sha256']) === null) throw new RuntimeException('That signature isn\'t made with your keys.');
    rs_sig_tables();
    rs_db()->prepare('REPLACE INTO signatures (sha256, kind, file, sig, key_id, signed_at) VALUES (?, ?, ?, ?, ?, NOW())')
        ->execute([$sig['sha256'], 'beta', $match[1]['file'], $sigText, $sig['key']]);
    rs_log('signed', 'beta ' . $match[0]['id'], $match[1]['file']);
    return 'Signed. Testers can install ' . $match[0]['name'] . ' ' . $match[1]['version'] . '.';
}

// ---- The admin's change log ----------------------------------------------------------------------

function rs_log_table(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS admin_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        at DATETIME NOT NULL,
        who VARCHAR(64) NOT NULL DEFAULT \'\',
        ip_hash CHAR(64) NOT NULL DEFAULT \'\',
        action VARCHAR(32) NOT NULL,
        target VARCHAR(190) NOT NULL DEFAULT \'\',
        detail TEXT NOT NULL,
        INDEX (at)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// Who did what, kept for the admin to look back on (Settings > Change log). Never fails a page.
function rs_log(string $action, string $target = '', string $detail = ''): void {
    try {
        rs_log_table();
        $who = '';
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['uid'])) {
            $st = rs_db()->prepare('SELECT username FROM users WHERE id = ?');
            $st->execute([(int)$_SESSION['uid']]);
            $who = (string)($st->fetchColumn() ?: '');
        } elseif (defined('RS_SIGNER_CALL')) {
            $who = 'signer';
        }
        rs_db()->prepare('INSERT INTO admin_log (at, who, ip_hash, action, target, detail) VALUES (NOW(), ?, ?, ?, ?, ?)')
            ->execute([$who, rs_ip_hash(), substr($action, 0, 32), substr($target, 0, 190), substr($detail, 0, 2000)]);
    } catch (Throwable $e) {
    }
}
