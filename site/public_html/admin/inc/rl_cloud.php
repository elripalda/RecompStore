<?php
// (3.1) RecompLauncher and accounts: signing a PC in with a short code, the list of PCs, and cloud
// saves. Used by api/launcher.php (the launcher, and the account page) and activate.php (the page
// that says yes to a code). Saves sit in recompstore-private/cloud-saves/<account uid>/<game>/,
// the newest five per game, 300 MB per account.
// (3.2) Also: "Get it on my PC" (a game sent from the website to a signed-in PC), betas of PC
// recomps, "How did it run?" reports from the launcher, the launcher's usage and diagnostics, and
// the launcher's own updates (launcher/version.json).
declare(strict_types=1);

const RS_RL_QUOTA = 300 * 1024 * 1024; // (4.8) doubled from 150 MB
const RS_RL_KEEP = 5;
require_once __DIR__ . '/b2.php';
const RS_RL_CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
// Never in a save upload: game files, programs, archives.
// (4.1) .bin is left out: many games keep their saves as small .bin files (the launcher sends .bin up
// to 16 MB, RS_RL_BIN_MAX); a bigger one is a disc image.
const RS_RL_BIN_MAX = 16 * 1024 * 1024;
const RS_RL_NOT_SAVES = ['iso', 'cue', 'chd', 'cso', 'gcm', 'rvz', 'wbfs', 'wad', 'nsp', 'xci', 'xex', 'xbe', 'z64', 'n64', 'v64', 'sfc', 'smc',
    'gba', 'nds', '3ds', 'elf', 'rpx', 'exe', 'dll', 'so', 'msi', 'zip', '7z', 'rar', 'appimage', 'mp4', 'mkv'];

function rs_rl_tables(): void {
    static $done = false;
    if ($done) return;
    rs_acct_tables();
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS launcher_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        account_id INT NOT NULL,
        name VARCHAR(64) NOT NULL DEFAULT \'\',
        os VARCHAR(16) NOT NULL DEFAULT \'\',
        version VARCHAR(16) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        KEY account (account_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS launcher_links (
        poll_hash CHAR(64) PRIMARY KEY,
        code CHAR(9) NOT NULL UNIQUE,
        name VARCHAR(64) NOT NULL DEFAULT \'\',
        os VARCHAR(16) NOT NULL DEFAULT \'\',
        version VARCHAR(16) NOT NULL DEFAULT \'\',
        account_id INT NULL,
        status VARCHAR(8) NOT NULL DEFAULT \'pending\',
        expires DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS cloud_saves (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        game VARCHAR(80) NOT NULL,
        v CHAR(16) NOT NULL,
        hash CHAR(64) NOT NULL,
        size INT NOT NULL,
        made INT NOT NULL,
        device VARCHAR(64) NOT NULL DEFAULT \'\',
        played INT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL,
        UNIQUE KEY ver (account_id, game, v),
        KEY account (account_id, game)
    ) CHARACTER SET utf8mb4');
    if (random_int(1, 30) === 1) $db->exec('DELETE FROM launcher_links WHERE expires < (UTC_TIMESTAMP() - INTERVAL 1 HOUR)');
    $done = true;
}

function rs_rl_clean(string $s, int $max): string {
    return mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $s) ?? ''), 0, $max);
}

// ---- Signing a PC in --------------------------------------------------------------------------

function rs_rl_code(): string {
    $c = '';
    for ($i = 0; $i < 8; $i++) $c .= RS_RL_CODE_CHARS[random_int(0, strlen(RS_RL_CODE_CHARS) - 1)];
    return substr($c, 0, 4) . '-' . substr($c, 4);
}

// "abcd efgh" -> "ABCD-EFGH", or '' when it can't be a code.
function rs_rl_norm_code(string $c): string {
    $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c) ?? '');
    return strlen($c) === 8 ? substr($c, 0, 4) . '-' . substr($c, 4) : '';
}

// A new code for a launcher. -> [code, poll secret]
function rs_rl_link_start(string $name, string $os, string $version): array {
    rs_rl_tables();
    $db = rs_db();
    $poll = bin2hex(random_bytes(24));
    for ($i = 0; $i < 5; $i++) {
        $code = rs_rl_code();
        try {
            $db->prepare('INSERT INTO launcher_links (poll_hash, code, name, os, version, expires) VALUES (?, ?, ?, ?, ?, (UTC_TIMESTAMP() + INTERVAL 10 MINUTE))')
                ->execute([hash('sha256', $poll), $code, rs_rl_clean($name, 64) ?: 'A PC', rs_rl_clean($os, 16), rs_rl_clean($version, 16)]);
            return [$code, $poll];
        } catch (PDOException $e) {
            if ($i === 4) throw $e;
        }
    }
    throw new RuntimeException('No code');
}

function rs_rl_link_by_code(string $code): ?array {
    rs_rl_tables();
    $st = rs_db()->prepare('SELECT * FROM launcher_links WHERE code = ? AND expires > UTC_TIMESTAMP()');
    $st->execute([$code]);
    return $st->fetch() ?: null;
}

// The website said yes (or no) for the signed-in account.
function rs_rl_link_answer(string $code, int $accountId, bool $yes): bool {
    rs_rl_tables();
    $st = rs_db()->prepare('UPDATE launcher_links SET account_id = ?, status = ? WHERE code = ? AND status = \'pending\' AND expires > UTC_TIMESTAMP()');
    $st->execute([$yes ? $accountId : null, $yes ? 'ok' : 'denied', $code]);
    return $st->rowCount() === 1;
}

// The launcher asking whether its code was approved. Approved: a token for this PC, handed over
// once (only its hash is kept), and the code is used up.
function rs_rl_link_poll(string $poll): array {
    rs_rl_tables();
    if (!preg_match('/^[0-9a-f]{48}$/', $poll)) return ['status' => 'expired'];
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM launcher_links WHERE poll_hash = ?');
    $st->execute([hash('sha256', $poll)]);
    $l = $st->fetch();
    if (!$l || strtotime($l['expires'] . ' UTC') < time()) return ['status' => 'expired'];
    if ($l['status'] === 'denied') {
        $db->prepare('DELETE FROM launcher_links WHERE poll_hash = ?')->execute([hash('sha256', $poll)]);
        return ['status' => 'denied'];
    }
    if ($l['status'] !== 'ok' || !$l['account_id']) return ['status' => 'pending'];
    $a = rs_acct_get((int)$l['account_id']);
    $db->prepare('DELETE FROM launcher_links WHERE poll_hash = ?')->execute([hash('sha256', $poll)]);
    if (!$a || $a['banned']) return ['status' => 'denied'];
    $raw = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO launcher_devices (token_hash, account_id, name, os, version, created, last_seen) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
        ->execute([hash('sha256', $raw), (int)$a['id'], $l['name'], $l['os'], $l['version']]);
    return ['status' => 'ok', 'token' => $raw, 'account' => rs_rl_user($a)];
}

// What the launcher is told about the account.
function rs_rl_user(array $a): array {
    $pic = rs_acct_avatar_url($a);
    $out = ['uid' => $a['uid'], 'username' => (string)$a['username'], 'avatar' => $pic !== '' ? 'https://recompstore.com' . $pic : ''];
    // (3.1g) A verified developer: an approved developer account with the same GitHub or email.
    try {
        rs_dev_tables();
        $gh = (int)($a['github_id'] ?? 0);
        $mail = strtolower((string)($a['email'] ?? ''));
        if ($gh > 0 || ($mail !== '' && !empty($a['email_ok']))) {
            $st = rs_db()->prepare("SELECT slug FROM dev_accounts WHERE status = 'approved' AND slug <> '' AND ((gh_id = ? AND ? > 0) OR (email <> '' AND LOWER(email) = ?)) LIMIT 1");
            $st->execute([$gh, $gh, $mail === '' ? '-' : $mail]);
            $slug = (string)($st->fetchColumn() ?: '');
            $d = $slug !== '' ? (rs_developers()[$slug] ?? null) : null;
            if ($d) $out['dev'] = ['slug' => $slug, 'name' => (string)($d['name'] ?? $slug), 'verified' => !empty($d['verified'])];
        }
    } catch (Throwable $e) { /* no developer info: the launcher falls back to matching the name */ }
    return $out;
}

// The launcher's token -> [account, device row], or null.
function rs_rl_device(): ?array {
    $raw = (string)($_SERVER['HTTP_X_RL_TOKEN'] ?? '');
    if ($raw === '') {
        $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) $raw = $m[1];
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $raw)) return null;
    rs_rl_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT * FROM launcher_devices WHERE token_hash = ?');
    $st->execute([hash('sha256', $raw)]);
    $d = $st->fetch();
    if (!$d) return null;
    $a = rs_acct_get((int)$d['account_id']);
    if (!$a || $a['banned']) return null;
    if (strtotime($d['last_seen'] . ' UTC') < time() - 300) {
        $db->prepare('UPDATE launcher_devices SET last_seen = UTC_TIMESTAMP() WHERE id = ?')->execute([(int)$d['id']]);
        rs_acct_update((int)$a['id'], ['last_seen' => gmdate('Y-m-d H:i:s')]);
    }
    return [$a, $d];
}

function rs_rl_devices(int $accountId): array {
    rs_rl_tables();
    $st = rs_db()->prepare('SELECT id, name, os, version, created, last_seen FROM launcher_devices WHERE account_id = ? ORDER BY last_seen DESC');
    $st->execute([$accountId]);
    return array_map(fn($d) => ['id' => (int)$d['id'], 'name' => $d['name'], 'os' => $d['os'], 'version' => $d['version'], 'created' => $d['created'], 'seen' => $d['last_seen']], $st->fetchAll());
}

function rs_rl_device_remove(int $accountId, int $id): void {
    rs_rl_tables();
    rs_db()->prepare('DELETE FROM launcher_devices WHERE account_id = ? AND id = ?')->execute([$accountId, $id]);
}

// ---- Cloud saves ------------------------------------------------------------------------------

function rs_rl_game_ok(string $g): bool { return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/', $g); }

// Where a copy sits on Backblaze: the same layout the server's folder had.
function rs_rl_key(array $a, string $game, string $v): string { return 'cloud-saves/' . $a['uid'] . '/' . $game . '/' . $v . '.zip'; }

function rs_rl_dir(array $a, string $game = ''): string {
    $d = dirname(RS_PUBLIC) . '/recompstore-private/cloud-saves/' . $a['uid'];
    return $game === '' ? $d : $d . '/' . $game;
}

function rs_rl_used(int $accountId): int {
    rs_rl_tables();
    $st = rs_db()->prepare('SELECT COALESCE(SUM(size), 0) FROM cloud_saves WHERE account_id = ?');
    $st->execute([$accountId]);
    return (int)$st->fetchColumn();
}

function rs_rl_versions(int $accountId, string $game): array {
    rs_rl_tables();
    $st = rs_db()->prepare('SELECT v, hash, size, made, device, played, created FROM cloud_saves WHERE account_id = ? AND game = ? ORDER BY made DESC, id DESC');
    $st->execute([$accountId, $game]);
    return array_map(fn($r) => ['v' => $r['v'], 'hash' => $r['hash'], 'size' => (int)$r['size'], 'made' => (int)$r['made'], 'device' => $r['device'], 'played' => (int)$r['played']], $st->fetchAll());
}

function rs_rl_games(int $accountId): array {
    rs_rl_tables();
    $st = rs_db()->prepare('SELECT DISTINCT game FROM cloud_saves WHERE account_id = ? ORDER BY game');
    $st->execute([$accountId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $g) $out[] = ['game' => $g, 'name' => rs_rl_game_name($g), 'versions' => rs_rl_versions($accountId, $g)];
    usort($out, fn($x, $y) => ($y['versions'][0]['made'] ?? 0) <=> ($x['versions'][0]['made'] ?? 0));
    return $out;
}

// A game's name from the PC catalog, for the account page.
function rs_rl_game_name(string $game): string {
    static $names = null;
    if ($names === null) {
        $names = [];
        $f = RS_PUBLIC . '/pc-catalog.json';
        $cat = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        foreach (($cat['apps'] ?? []) as $app) if (isset($app['id'], $app['name'])) $names[(string)$app['id']] = (string)$app['name'];
    }
    return $names[$game] ?? $game;
}

// Why an upload isn't a save copy, or null when it is one.
function rs_rl_check_zip(string $file, ?string &$note = null): ?string {
    $note = '';
    $head = (string)file_get_contents($file, false, null, 0, 4);
    if ($head !== "PK\x03\x04") { $note = 'not a zip'; return 'That isn\'t a save copy.'; }
    if (!class_exists('ZipArchive')) return null;
    $z = new ZipArchive();
    if ($z->open($file) !== true) { $note = 'zip won\'t open'; return 'That isn\'t a save copy.'; }
    $has = false; $total = 0;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $s = $z->statIndex($i);
        if (!$s) continue;
        $name = (string)$s['name'];
        if ($name === 'rlsave.json') { $has = true; continue; }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, RS_RL_NOT_SAVES, true) || ($ext === 'bin' && (int)$s['size'] > RS_RL_BIN_MAX)) { $note = substr($name, -60) . ', ' . (int)$s['size']; $z->close(); return 'Game files and programs can\'t go in cloud saves.'; }
        if ((int)$s['size'] > 64 * 1024 * 1024) { $note = substr($name, -60) . ', ' . (int)$s['size']; $z->close(); return 'One of those files is too big to be a save.'; }
        $total += (int)$s['size'];
    }
    $z->close();
    if (!$has) { $note = 'no rlsave.json'; return 'That isn\'t a save copy.'; }
    if ($total > 4 * RS_RL_QUOTA) return 'That\'s too big to be a save.';
    return null;
}

// Keeps an uploaded copy. -> ['v' => ..., 'same' => bool] or throws with a reason for the player.
function rs_rl_put(array $a, string $game, string $file, string $hash, int $made, int $played, string $device): array {
    rs_rl_tables();
    $id = (int)$a['id'];
    $size = (int)filesize($file);
    $have = rs_rl_versions($id, $game);
    // The same saves as the newest copy: nothing new to keep.
    if ($have && hash_equals($have[0]['hash'], $hash)) return ['v' => $have[0]['v'], 'same' => true];
    // Room: what's kept now, less the oldest copy this one replaces, plus this one.
    $drop = count($have) >= RS_RL_KEEP ? array_slice($have, RS_RL_KEEP - 1) : [];
    $after = rs_rl_used($id) - array_sum(array_column($drop, 'size')) + $size;
    // (4.1) Short of room: older copies go first, oldest first, this game's and then the others'. The
    // newest copy of every game is always kept, so a save only fails when those alone fill the space.
    $more = [];
    if ($after > RS_RL_QUOTA) {
        $gone = array_flip(array_column($drop, 'v'));
        $st = rs_db()->prepare('SELECT game, v, size, made FROM cloud_saves WHERE account_id = ? ORDER BY game, made DESC, id DESC');
        $st->execute([$id]);
        $older = []; $seen = [];
        foreach ($st->fetchAll() as $r) {
            if (!isset($seen[$r['game']])) { $seen[$r['game']] = 1; continue; } // its newest copy stays
            if (!isset($gone[$r['v']])) $older[] = $r;
        }
        usort($older, fn($x, $y) => [$x['game'] !== $game, (int)$x['made']] <=> [$y['game'] !== $game, (int)$y['made']]);
        foreach ($older as $r) {
            if ($after <= RS_RL_QUOTA) break;
            $more[] = $r; $after -= (int)$r['size'];
        }
    }
    if ($after > RS_RL_QUOTA) throw new RangeException('Your cloud saves are full (' . (int)(RS_RL_QUOTA / 1048576) . ' MB). Delete some on your account page at recomplauncher.com.');
    $v = bin2hex(random_bytes(8));
    // (4.7) Kept here first and answered at once; with B2 on, the copy goes up to B2 after the
    // launcher has its answer (rs_rl_b2_later). Before, the B2 upload ran first, and a slow one plus
    // a slow player upload went past Cloudflare's 100 seconds (524), which the launcher took as the
    // whole site being down.
    $dir = rs_rl_dir($a, $game);
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Cloud saves can\'t be kept right now. Try again later.');
    if (!rename($file, "$dir/$v.zip") && !copy($file, "$dir/$v.zip")) throw new RuntimeException('Cloud saves can\'t be kept right now. Try again later.');
    if (rs_b2_on()) rs_rl_b2_later($a, $game, $v);
    rs_db()->prepare('INSERT INTO cloud_saves (account_id, game, v, hash, size, made, device, played, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$id, $game, $v, $hash, $size, $made, rs_rl_clean($device, 64), $played]);
    foreach ($drop as $old) rs_rl_delete($a, $game, $old['v']);
    foreach ($more as $old) rs_rl_delete($a, (string)$old['game'], (string)$old['v']);
    return ['v' => $v, 'same' => false];
}

function rs_rl_file(array $a, string $game, string $v): ?string {
    if (!rs_rl_game_ok($game) || !preg_match('/^[0-9a-f]{16}$/', $v)) return null;
    rs_rl_tables();
    $st = rs_db()->prepare('SELECT 1 FROM cloud_saves WHERE account_id = ? AND game = ? AND v = ?');
    $st->execute([(int)$a['id'], $game, $v]);
    if (!$st->fetchColumn()) return null;
    $f = rs_rl_dir($a, $game) . "/$v.zip";
    if (is_file($f)) return $f; // (4.7) still here (not up on B2 yet): sent from here
    if (rs_b2_on()) return 'b2:' . rs_rl_key($a, $game, $v);
    return null;
}

// (4.7) After the answer has gone: this copy up to B2, then the one kept here removed. One that
// doesn't go up stays here (and is sent from here) and is tried again by a later upload's sweep.
function rs_rl_b2_later(array $a, string $game, string $v): void {
    static $queued = [];
    $queued[] = [$a, $game, $v];
    if (count($queued) > 1) return;
    register_shutdown_function(function () use (&$queued) {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        @set_time_limit(300);
        foreach ($queued as [$a, $game, $v]) rs_rl_b2_push($a, $game, $v);
        if (random_int(1, 20) === 1) rs_rl_b2_sweep(5);
    });
}
function rs_rl_b2_push(array $a, string $game, string $v): bool {
    $f = rs_rl_dir($a, $game) . "/$v.zip";
    if (!is_file($f)) return true;
    try {
        rs_b2_put(rs_rl_key($a, $game, $v), $f);
    } catch (Throwable $e) {
        error_log('cloud save to B2 later: ' . $e->getMessage());
        return false;
    }
    // Deleted meanwhile (a newer copy pushed it out): gone from B2 too.
    $st = rs_db()->prepare('SELECT 1 FROM cloud_saves WHERE account_id = ? AND game = ? AND v = ?');
    $st->execute([(int)$a['id'], $game, $v]);
    if (!$st->fetchColumn()) { rs_b2_delete(rs_rl_key($a, $game, $v)); }
    @unlink($f);
    @rmdir(dirname($f));
    return true;
}
// Copies still kept here more than 10 minutes after they arrived (their push failed): tried again.
function rs_rl_b2_sweep(int $max): void {
    $base = dirname(RS_PUBLIC) . '/recompstore-private/cloud-saves';
    if (!is_dir($base)) return;
    $n = 0;
    foreach ((array)glob($base . '/*/*/*.zip') as $f) {
        if ($n >= $max) break;
        if (filemtime($f) > time() - 600) continue;
        $v = basename($f, '.zip');
        $game = basename(dirname($f));
        $st = rs_db()->prepare('SELECT a.* FROM cloud_saves c JOIN accounts a ON a.id = c.account_id WHERE c.game = ? AND c.v = ? LIMIT 1');
        $st->execute([$game, $v]);
        $acc = $st->fetch();
        if (!$acc) { @unlink($f); continue; }
        if (rs_rl_dir($acc, $game) . "/$v.zip" !== $f) continue;
        rs_rl_b2_push($acc, $game, $v);
        ++$n;
    }
}

function rs_rl_delete(array $a, string $game, string $v): void {
    if (!rs_rl_game_ok($game) || !preg_match('/^[0-9a-f]{16}$/', $v)) return;
    rs_db()->prepare('DELETE FROM cloud_saves WHERE account_id = ? AND game = ? AND v = ?')->execute([(int)$a['id'], $game, $v]);
    if (rs_b2_on()) rs_b2_delete(rs_rl_key($a, $game, $v));
    @unlink(rs_rl_dir($a, $game) . "/$v.zip");
    @rmdir(rs_rl_dir($a, $game));
}

function rs_rl_send(string $file, string $name): void {
    if (str_starts_with($file, 'b2:')) {
        header('Cache-Control: no-store');
        header('Location: ' . rs_b2_link(substr($file, 3), $name), true, 302);
        exit;
    }
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($file));
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"');
    readfile($file);
    exit;
}

// An account being deleted: its PCs and cloud saves go too.
function rs_rl_forget(array $a): void {
    rs_rl_tables();
    $db = rs_db();
    $id = (int)$a['id'];
    if (rs_b2_on()) {
        $st = $db->prepare('SELECT game, v FROM cloud_saves WHERE account_id = ?');
        $st->execute([$id]);
        foreach ($st->fetchAll() as $r) rs_b2_delete(rs_rl_key($a, (string)$r['game'], (string)$r['v']));
    }
    $db->prepare('DELETE FROM launcher_devices WHERE account_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM launcher_links WHERE account_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM cloud_saves WHERE account_id = ?')->execute([$id]);
    $dir = rs_rl_dir($a);
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }
}

// For "Download my data": the PCs and the saves kept.
function rs_rl_export(array $a): array {
    return ['launcherPCs' => rs_rl_devices((int)$a['id']), 'cloudSaves' => rs_rl_games((int)$a['id'])];
}

// ---- (3.2) Get it on my PC --------------------------------------------------------------------

function rs_rl_more_tables(): void {
    static $done = false;
    if ($done) return;
    rs_rl_tables();
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS launcher_sends (
        id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        device_id INT NOT NULL,
        game VARCHAR(64) NOT NULL,
        created DATETIME NOT NULL,
        done DATETIME NULL,
        result VARCHAR(16) NOT NULL DEFAULT \'\',
        KEY dev (device_id, done)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS pc_betas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        app VARCHAR(64) NOT NULL,
        title VARCHAR(80) NOT NULL DEFAULT \'\',
        notes TEXT NULL,
        channel VARCHAR(12) NOT NULL DEFAULT \'prerelease\',
        tag VARCHAR(80) NOT NULL DEFAULT \'\',
        access VARCHAR(8) NOT NULL DEFAULT \'open\',
        active TINYINT NOT NULL DEFAULT 1,
        created DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS pc_beta_members (
        beta_id INT NOT NULL,
        account_id INT NOT NULL,
        status VARCHAR(8) NOT NULL DEFAULT \'asked\',
        created DATETIME NOT NULL,
        PRIMARY KEY (beta_id, account_id),
        KEY account (account_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS rl_installs (
        install_id CHAR(32) PRIMARY KEY,
        account_id INT NULL,
        first_seen DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        version VARCHAR(16) NOT NULL DEFAULT \'\',
        os VARCHAR(16) NOT NULL DEFAULT \'\',
        osver VARCHAR(80) NOT NULL DEFAULT \'\',
        cpu VARCHAR(120) NOT NULL DEFAULT \'\',
        cores SMALLINT NOT NULL DEFAULT 0,
        ram_gb SMALLINT NOT NULL DEFAULT 0,
        gpu VARCHAR(160) NOT NULL DEFAULT \'\',
        gpu_driver VARCHAR(40) NOT NULL DEFAULT \'\',
        screen VARCHAR(40) NOT NULL DEFAULT \'\',
        lang VARCHAR(12) NOT NULL DEFAULT \'\',
        pads VARCHAR(80) NOT NULL DEFAULT \'\',
        games SMALLINT NOT NULL DEFAULT 0,
        bigpicture TINYINT NOT NULL DEFAULT 0,
        KEY seen (last_seen)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS rl_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        install_id CHAR(32) NOT NULL,
        at DATETIME NOT NULL,
        kind VARCHAR(12) NOT NULL,
        game VARCHAR(64) NOT NULL DEFAULT \'\',
        version VARCHAR(40) NOT NULL DEFAULT \'\',
        secs INT NOT NULL DEFAULT 0,
        code INT NULL,
        detail VARCHAR(255) NOT NULL DEFAULT \'\',
        KEY kind (kind, at),
        KEY game (game, kind)
    ) CHARACTER SET utf8mb4');
    // Launcher reports: what the PC was, next to the player's word.
    rs_pc_tables();
    foreach (['gpu' => "VARCHAR(160) NOT NULL DEFAULT ''", 'cpu' => "VARCHAR(120) NOT NULL DEFAULT ''", 'ram_gb' => 'SMALLINT NOT NULL DEFAULT 0',
        'osver' => "VARCHAR(80) NOT NULL DEFAULT ''", 'src' => "VARCHAR(8) NOT NULL DEFAULT ''", 'mins' => 'INT NOT NULL DEFAULT 0',
        'device' => "VARCHAR(40) NOT NULL DEFAULT ''"] as $col => $def) { // (4.3) device: the handheld, when it's one
        if (rs_col_missing($db, 'pc_feedback', $col)) $db->exec("ALTER TABLE pc_feedback ADD COLUMN $col $def");
    }
    // (3.7) Diagnostics are kept 90 days, and none of it is tied to an account (the privacy page says so).
    if (random_int(1, 200) === 1) {
        $db->exec('DELETE FROM rl_events WHERE at < (UTC_TIMESTAMP() - INTERVAL 90 DAY)');
        $db->exec('DELETE FROM rl_installs WHERE last_seen < (UTC_TIMESTAMP() - INTERVAL 90 DAY)');
    }
    if (rs_setting('rl_installs_unlinked', '0') !== '1') { $db->exec('UPDATE rl_installs SET account_id = NULL WHERE account_id IS NOT NULL'); rs_set_setting('rl_installs_unlinked', '1'); }
    $done = true;
}

// The PC catalog's entry for a recomp, or null.
function rs_rl_app(string $id): ?array {
    static $apps = null;
    if ($apps === null) {
        $apps = [];
        $cat = is_file(RS_PUBLIC . '/pc-catalog.json') ? json_decode((string)file_get_contents(RS_PUBLIC . '/pc-catalog.json'), true) : null;
        $all = (array)($cat['apps'] ?? []);
        foreach ((array)($cat['apps_min'] ?? []) as $g) foreach ((array)($g['apps'] ?? []) as $a) $all[] = $a; // (4.3) newer launchers' entries
        foreach ($all as $a) if (isset($a['id'])) $apps[(string)$a['id']] = $a;
    }
    return $apps[$id] ?? null;
}

// The account's PCs that can run a recomp (its systems include the PC's).
function rs_rl_pcs_for(int $accountId, string $game): array {
    $app = rs_rl_app($game);
    if (!$app) return [];
    $sys = (array)($app['systems'] ?? []);
    return array_values(array_filter(rs_rl_devices($accountId), fn($d) => in_array($d['os'], $sys, true)));
}

function rs_rl_send_game(int $accountId, int $deviceId, string $game): void {
    rs_rl_more_tables();
    $db = rs_db();
    // Already waiting for that PC: once is enough.
    $st = $db->prepare('SELECT id FROM launcher_sends WHERE account_id = ? AND device_id = ? AND game = ? AND done IS NULL');
    $st->execute([$accountId, $deviceId, $game]);
    if (!$st->fetchColumn())
        $db->prepare('INSERT INTO launcher_sends (account_id, device_id, game, created) VALUES (?, ?, ?, UTC_TIMESTAMP())')->execute([$accountId, $deviceId, $game]);
    rs_rl_inbox_flag_device($deviceId, true);
}

// (3.3) A PC's inbox is asked about every minute or two by every open launcher, and it's nearly
// always empty. A small file per PC (named by its token's hash) says "something is waiting"; with
// no file, api/launcher.php answers "nothing" without opening the database.
function rs_rl_inbox_dir(): string { return dirname(RS_PUBLIC) . '/recompstore-private/rl-inbox'; }
function rs_rl_inbox_flag(string $tokenHash, bool $on): void {
    if (!preg_match('/^[0-9a-f]{64}$/', $tokenHash)) return;
    $d = rs_rl_inbox_dir();
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if ($on) @touch("$d/$tokenHash"); else @unlink("$d/$tokenHash");
}
function rs_rl_inbox_flag_device(int $deviceId, bool $on): void {
    $st = rs_db()->prepare('SELECT token_hash FROM launcher_devices WHERE id = ?');
    $st->execute([$deviceId]);
    $h = $st->fetchColumn();
    if ($h) rs_rl_inbox_flag((string)$h, $on);
}
// Whether the fast answer can be trusted yet: once, every PC with something waiting gets its file.
function rs_rl_inbox_ready(): bool {
    $d = rs_rl_inbox_dir();
    if (is_file("$d/.ready")) return true;
    try {
        rs_rl_more_tables();
        if (!is_dir($d)) @mkdir($d, 0755, true);
        foreach (rs_db()->query('SELECT DISTINCT d.token_hash FROM launcher_sends s JOIN launcher_devices d ON d.id = s.device_id WHERE s.done IS NULL AND s.created > (UTC_TIMESTAMP() - INTERVAL 30 DAY)') as $r)
            rs_rl_inbox_flag((string)$r['token_hash'], true);
        @touch("$d/.ready");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function rs_rl_inbox(int $deviceId): array {
    rs_rl_more_tables();
    $st = rs_db()->prepare('SELECT id, game, created FROM launcher_sends WHERE device_id = ? AND done IS NULL AND created > (UTC_TIMESTAMP() - INTERVAL 30 DAY) ORDER BY id');
    $st->execute([$deviceId]);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'game' => $r['game'], 'name' => rs_rl_game_name($r['game']), 'sent' => $r['created']], $st->fetchAll());
}

function rs_rl_inbox_done(int $deviceId, int $id, string $result): void {
    rs_rl_more_tables();
    rs_db()->prepare('UPDATE launcher_sends SET done = UTC_TIMESTAMP(), result = ? WHERE id = ? AND device_id = ?')->execute([mb_substr($result, 0, 16), $id, $deviceId]);
}

// ---- (3.2) Betas of PC recomps ----------------------------------------------------------------
// A beta is a recomp's test builds from its own GitHub: its newest pre-release, or one release by
// tag. Open: anyone signed in joins. Request: they ask, the admin lets them in. Invite: only those
// the admin adds.

const RS_RL_ACCESS = ['open' => 'Anyone signed in', 'request' => 'They ask, you let them in', 'invite' => 'Only people you add'];

function rs_rl_betas_all(bool $activeOnly = false): array {
    rs_rl_more_tables();
    $rows = rs_db()->query('SELECT * FROM pc_betas' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, id DESC')->fetchAll();
    return array_map(fn($b) => $b + ['name' => rs_rl_game_name((string)$b['app'])], $rows);
}

// The betas someone can see: open and request ones, and invite ones they're in. -> with their status.
function rs_rl_betas_for(?int $accountId): array {
    rs_rl_more_tables();
    $mine = [];
    if ($accountId) {
        $st = rs_db()->prepare('SELECT beta_id, status FROM pc_beta_members WHERE account_id = ?');
        $st->execute([$accountId]);
        foreach ($st->fetchAll() as $m) $mine[(int)$m['beta_id']] = (string)$m['status'];
    }
    $out = [];
    foreach (rs_rl_betas_all(true) as $b) {
        $status = $mine[(int)$b['id']] ?? '';
        if ($status === 'out') continue;
        if ($b['access'] === 'invite' && $status !== 'in') continue;
        $app = rs_rl_app((string)$b['app']);
        if (!$app) continue;
        $out[] = ['id' => (int)$b['id'], 'app' => $b['app'], 'name' => (string)($app['name'] ?? $b['app']), 'title' => (string)$b['title'], 'notes' => (string)$b['notes'],
            'channel' => $b['channel'], 'tag' => $b['channel'] === 'tag' ? (string)$b['tag'] : '', 'access' => $b['access'], 'status' => $status,
            'systems' => array_values((array)($app['systems'] ?? [])), 'cover' => (string)($app['cover'] ?? '')];
    }
    return $out;
}

// Join (open: straight in; request: asked). -> the new status.
function rs_rl_beta_join(int $accountId, int $betaId): string {
    rs_rl_more_tables();
    $st = rs_db()->prepare('SELECT * FROM pc_betas WHERE id = ? AND active = 1');
    $st->execute([$betaId]);
    $b = $st->fetch();
    if (!$b || $b['access'] === 'invite') throw new RuntimeException('That beta isn\'t open to join.');
    $status = $b['access'] === 'open' ? 'in' : 'asked';
    $cur = rs_db()->prepare('SELECT status FROM pc_beta_members WHERE beta_id = ? AND account_id = ?');
    $cur->execute([$betaId, $accountId]);
    $was = $cur->fetchColumn();
    if ($was === 'in') return 'in';
    if ($was === 'out') throw new RuntimeException('That beta isn\'t open to you.');
    rs_db()->prepare('INSERT INTO pc_beta_members (beta_id, account_id, status, created) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status = VALUES(status)')->execute([$betaId, $accountId, $status]);
    return $status;
}

function rs_rl_beta_leave(int $accountId, int $betaId): void {
    rs_rl_more_tables();
    rs_db()->prepare("DELETE FROM pc_beta_members WHERE beta_id = ? AND account_id = ? AND status <> 'out'")->execute([$betaId, $accountId]);
}

function rs_rl_beta_members(int $betaId): array {
    rs_rl_more_tables();
    $st = rs_db()->prepare('SELECT m.account_id, m.status, m.created, a.username FROM pc_beta_members m LEFT JOIN accounts a ON a.id = m.account_id WHERE m.beta_id = ? ORDER BY m.status, m.created');
    $st->execute([$betaId]);
    return $st->fetchAll();
}

// ---- (3.2) "How did it run?" from the launcher --------------------------------------------------

// (4.3) Which handheld a report came from: '' for a normal PC. The launcher's own word first (from
// Beta 27 or so it sends "device"), else the Steam Deck system, else what the specs give away.
const RS_HANDHELDS = ['Steam Deck', 'ROG Ally', 'ROG Xbox Ally', 'Legion Go', 'MSI Claw', 'Ayaneo', 'GPD', 'OneXPlayer', 'Zotac Zone', 'Other handheld'];
function rs_handheld_of(string $sys, string $device, string $specs): string {
    foreach (RS_HANDHELDS as $h) if (strcasecmp(trim($device), $h) === 0) return $h;
    if (stripos($device, 'deck') !== false) return 'Steam Deck';
    if ($sys === 'deck') return 'Steam Deck';
    $t = $specs;
    if (preg_match('/steam ?deck|\bjupiter\b|\bgalileo\b|AMD Custom APU 0(405|932)|Sephiroth/i', $t)) return 'Steam Deck';
    if (preg_match('/xbox ally|RC73/i', $t)) return 'ROG Xbox Ally';
    if (preg_match('/ROG Ally|RC71L|RC72L/i', $t)) return 'ROG Ally';
    if (preg_match('/Legion Go|\b83E1\b|\b83L3\b|\b83N[0-9]\b/i', $t)) return 'Legion Go';
    if (preg_match('/MSI Claw|\bClaw (A1M|8|7)\b/i', $t)) return 'MSI Claw';
    if (preg_match('/AYANEO/i', $t)) return 'Ayaneo';
    if (preg_match('/\bGPD\b/', $t)) return 'GPD';
    if (preg_match('/ONEXPLAYER|OneXFly/i', $t)) return 'OneXPlayer';
    if (preg_match('/ZOTAC ZONE/i', $t)) return 'Zotac Zone';
    if (preg_match('/Ryzen Z1|Z1 Extreme|Z2 Extreme|Ryzen Z2/i', $t)) return 'Other handheld';
    return '';
}

// (4.3) Games handheld players say run well: at least 3 handheld reports in the last 90 days, and 7 in
// 10 of them "runs" ("has issues" counts half). -> [app id => ['ok' => true, 'devices' => [...], 'n' => int]]
function rs_handheld_good(): array {
    try {
        rs_rl_more_tables();
        $rows = rs_db()->query("SELECT app, verdict, sys, device, specs, cpu FROM pc_feedback WHERE hidden = 0 AND created > (NOW() - INTERVAL 90 DAY) AND (device <> '' OR sys = 'deck' OR specs REGEXP 'deck|jupiter|galileo|0405|0932|ally|rc7[123]|legion go|claw|ayaneo|gpd|onexplayer|zotac zone|ryzen z[12]')")->fetchAll();
    } catch (Throwable $e) { return []; }
    $by = [];
    foreach ($rows as $r) {
        $d = (string)$r['device'] !== '' ? (string)$r['device'] : rs_handheld_of((string)$r['sys'], '', (string)$r['specs'] . "\n" . (string)$r['cpu']);
        if ($d === '') continue;
        $a = (string)$r['app'];
        $by[$a]['n'] = ($by[$a]['n'] ?? 0) + 1;
        $w = ['runs' => 1.0, 'issues' => 0.5][(string)$r['verdict']] ?? 0.0;
        $by[$a]['score'] = ($by[$a]['score'] ?? 0) + $w;
        if ((string)$r['verdict'] !== 'broken' && $d !== 'Other handheld') $by[$a]['devices'][$d] = true;
    }
    $out = [];
    foreach ($by as $a => $x) {
        if ($x['n'] < 3 || $x['score'] / $x['n'] < 0.7) continue;
        $out[$a] = ['ok' => true, 'devices' => array_keys($x['devices'] ?? []), 'n' => $x['n']];
    }
    return $out;
}

// One report per player and recomp (a new one replaces theirs): keyed by their account, or by the
// launcher's install when they aren't signed in.
function rs_rl_report(array $r, ?array $acc, string $install): void {
    rs_rl_more_tables();
    $key = $acc ? 'acct:' . $acc['id'] : 'rl:' . $install;
    $who = hash('sha256', $key);
    // (3.9) The PC's specs in one place (sent as specs, else made from its parts), and the account, so
    // the player hears back when the developer answers.
    $specs = rs_play_specs_clean((string)($r['specs'] ?? ''));
    if ($specs === '') $specs = rs_play_specs_clean(implode("\n", array_filter([$r['osver'] !== '' ? 'OS: ' . $r['osver'] : '', $r['cpu'] !== '' ? 'CPU: ' . $r['cpu'] : '',
        $r['gpu'] !== '' ? 'GPU: ' . $r['gpu'] : '', (int)$r['ram_gb'] ? 'RAM: ' . (int)$r['ram_gb'] . ' GB' : ''])));
    $db = rs_db();
    // (4.3) The handheld it was played on: what the launcher says, else what its specs give away.
    $device = rs_handheld_of((string)$r['sys'], (string)($r['device'] ?? ''), $specs . "\n" . (string)$r['cpu']);
    $db->prepare('INSERT INTO pc_feedback (app, verdict, sys, version, note, ip_hash, created, gpu, cpu, ram_gb, osver, src, mins, account_id, specs, device) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, \'launcher\', ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), sys = VALUES(sys), version = VALUES(version), note = VALUES(note), created = NOW(), hidden = 0,
        gpu = VALUES(gpu), cpu = VALUES(cpu), ram_gb = VALUES(ram_gb), osver = VALUES(osver), src = VALUES(src), mins = VALUES(mins),
        account_id = COALESCE(VALUES(account_id), account_id), specs = VALUES(specs), device = VALUES(device)')
        ->execute([$r['app'], $r['verdict'], $r['sys'], $r['version'], $r['note'], $who, $r['gpu'], $r['cpu'], $r['ram_gb'], $r['osver'], $r['mins'], $acc ? (int)$acc['id'] : null, $specs, $device]);
    // The game page's reports (play_reports, 3.2) copy pc_feedback in about once a minute; this one
    // goes in now, with the PC's parts, which that copy leaves alone.
    $st = $db->prepare('SELECT id FROM pc_feedback WHERE app = ? AND ip_hash = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$r['app'], $who]);
    $id = (int)$st->fetchColumn();
    if (!$id) return;
    try {
        rs_play_from_pcfb($id, ['app' => $r['app'], 'who' => $who, 'verdict' => $r['verdict'], 'sys' => $r['sys'], 'version' => $r['version'], 'note' => $r['note'],
            'gpu' => $r['gpu'], 'cpu' => $r['cpu'], 'ram' => (int)$r['ram_gb'], 'specs' => $specs], $acc ? (int)$acc['id'] : null);
        if ($r['verdict'] !== 'runs') rs_play_dev_ping((string)$r['app']);
    } catch (Throwable $e) { /* the copy picks it up */ }
}

// ---- (3.2) Usage and diagnostics ---------------------------------------------------------------

function rs_rl_hello(string $install, ?array $acc, array $in): void {
    rs_rl_more_tables();
    $c = fn(string $k, int $max) => rs_rl_clean((string)($in[$k] ?? ''), $max);
    $n = fn(string $k, int $max) => max(0, min($max, (int)($in[$k] ?? 0)));
    rs_db()->prepare('INSERT INTO rl_installs (install_id, account_id, first_seen, last_seen, version, os, osver, cpu, cores, ram_gb, gpu, gpu_driver, screen, lang, pads, games, bigpicture)
        VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE account_id = NULL, last_seen = UTC_TIMESTAMP(), version = VALUES(version), os = VALUES(os), osver = VALUES(osver),
        cpu = VALUES(cpu), cores = VALUES(cores), ram_gb = VALUES(ram_gb), gpu = VALUES(gpu), gpu_driver = VALUES(gpu_driver), screen = VALUES(screen), lang = VALUES(lang),
        pads = VALUES(pads), games = VALUES(games), bigpicture = VALUES(bigpicture)')
        ->execute([$install, null, $c('version', 16), $c('os', 16), $c('osver', 80), $c('cpu', 120), $n('cores', 512), $n('ram_gb', 4096),
            $c('gpu', 160), $c('gpu_driver', 40), $c('screen', 40), $c('lang', 12), $c('pads', 80), $n('games', 999), empty($in['bigpicture']) ? 0 : 1]);
}

const RS_RL_EVENT_KINDS = ['session', 'install', 'update', 'uninstall', 'crash', 'error', 'setup', 'profile']; // (4.3) profile: how a boot profile's step went

function rs_rl_events(string $install, array $events): int {
    rs_rl_more_tables();
    $st = rs_db()->prepare('INSERT INTO rl_events (install_id, at, kind, game, version, secs, code, detail) VALUES (?, FROM_UNIXTIME(?), ?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach (array_slice($events, 0, 200) as $e) {
        if (!is_array($e) || !in_array($e['kind'] ?? '', RS_RL_EVENT_KINDS, true)) continue;
        $at = (int)($e['at'] ?? time());
        if ($at < time() - 30 * 86400 || $at > time() + 3600) $at = time();
        $st->execute([$install, $at, $e['kind'], rs_rl_clean((string)($e['game'] ?? ''), 64), rs_rl_clean((string)($e['version'] ?? ''), 40),
            max(0, min(864000, (int)($e['secs'] ?? 0))), isset($e['code']) && is_numeric($e['code']) ? (int)$e['code'] : null, rs_rl_clean((string)($e['detail'] ?? ''), 255)]);
        $n++;
    }
    return $n;
}

// ---- (3.2) The launcher's own updates ---------------------------------------------------------
// public_html/launcher/version.json: {version, url, sha256, size, notes, date}. The launcher checks
// it, downloads url (here or GitHub), checks sha256 and installs it.

function rs_rl_version_file(): string { return RS_PUBLIC . '/launcher/version.json'; }

function rs_rl_version(): array {
    $f = rs_rl_version_file();
    return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
}

function rs_rl_installers(): array {
    $out = [];
    foreach (glob(RS_PUBLIC . '/launcher/RecompLauncher_*_x64-setup.exe') ?: [] as $f) $out[] = ['file' => basename($f), 'size' => filesize($f), 'at' => filemtime($f)];
    usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
    return $out;
}

function rs_rl_publish(string $version, string $file, string $notes): array {
    // (3.1g) Betas and alphas too: 0.75.0-beta.2, 1.0.0-alpha.1.
    if (!preg_match('/^\d+\.\d+\.\d+(-(beta|alpha)\.\d+)?$/', $version)) throw new RuntimeException('Write the version like 0.9.1, or 0.75.0-beta.2 for a beta.');
    $file = basename($file);
    $path = RS_PUBLIC . '/launcher/' . $file;
    if (!preg_match('/^RecompLauncher_[0-9A-Za-z._-]+_x64-setup\.exe$/', $file) || !is_file($path)) throw new RuntimeException('Pick one of the installers in launcher/.');
    // (3.1g) It goes out as the current Windows build on the Launcher beta page, so launcher/version.json,
    // the Get page, invite links and share links all give this one: the signed download link (from
    // Cloudflare R2 when it's set up), its size, sha256 and published time, with the exact version.
    require_once __DIR__ . '/betawave.php';
    rs_lb_tables();
    $db = rs_db();
    $sha = hash_file('sha256', $path);
    $notes = mb_substr(trim($notes), 0, 8000);
    $st = $db->prepare("SELECT id FROM launcher_builds WHERE platform = 'windows' AND sha256 = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$sha]);
    $id = (int)$st->fetchColumn();
    if ($id) {
        $db->prepare('UPDATE launcher_builds SET version = ?, notes = ? WHERE id = ?')->execute([$version, $notes, $id]);
    } else {
        $dir = rs_lb_dir();
        $sub = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!@mkdir($dir . '/' . $sub, 0755) || !@copy($path, $dir . '/' . $sub . '/' . $file)) throw new RuntimeException('The installer couldn\'t be copied to the builds folder.');
        $key = '';
        $r2 = rs_r2();
        if ($r2) { $err = ''; if (rs_r2_put_file($r2, 'launcher/' . $sub . '/' . $file, $dir . '/' . $sub . '/' . $file, $err)) $key = 'launcher/' . $sub . '/' . $file; }
        $db->prepare("INSERT INTO launcher_builds (version, platform, file, size, sha256, notes, r2_key, created) VALUES (?, 'windows', ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
           ->execute([$version, $sub . '/' . $file, (int)filesize($path), $sha, $notes, $key]);
        $id = (int)$db->lastInsertId();
    }
    rs_lb_make_current($id);
    return rs_rl_version();
}
