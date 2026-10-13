<?php
// Recomp Store admin: shared setup for every admin page.
declare(strict_types=1);

const RS_VERSION = '1.2.1';

// Where the site's public files live (public_html) and the admin folder.
define('RS_PUBLIC', dirname(__DIR__, 2));
define('RS_ADMIN', dirname(__DIR__));
define('RS_MEDIA', RS_PUBLIC . '/media');
define('RS_CATALOG', RS_PUBLIC . '/catalog.json');

// The config (database login) is kept outside public_html when the host allows it,
// so it can never be downloaded. Otherwise it sits in admin/inc, which .htaccess blocks.
function rs_config_path(): string {
    $outside = dirname(RS_PUBLIC) . '/recompstore-config.php';
    if (is_file($outside)) return $outside;
    $inside = __DIR__ . '/config.php';
    if (is_file($inside)) return $inside;
    return is_writable(dirname(RS_PUBLIC)) ? $outside : $inside;
}

function rs_config(): ?array {
    $p = rs_config_path();
    if (!is_file($p)) return null;
    $c = require $p;
    return is_array($c) ? $c : null;
}

function rs_db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = rs_config();
    if (!$c) { header('Location: setup.php'); exit; }
    $pdo = new PDO(
        'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
        $c['db_user'], $c['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function rs_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('rs_admin');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/admin', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    session_start();
}

function rs_require_login(): void {
    rs_session();
    if (!rs_config()) { header('Location: setup.php'); exit; }
    if (empty($_SESSION['uid'])) { header('Location: login.php'); exit; }
}

function rs_csrf(): string {
    rs_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function rs_check_csrf(): void {
    rs_session();
    $t = $_POST['csrf'] ?? '';
    if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(400);
        exit('This form expired. Go back, reload the page and try again.');
    }
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function rs_flash(?string $msg = null, string $kind = 'ok'): ?array {
    rs_session();
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---- Games ------------------------------------------------------------------

// Every field a game has, with its default. The admin form edits these;
// publish turns them into the catalog the PS5 store and the website read.
function rs_game_defaults(): array {
    return [
        'id' => '', 'name' => '', 'kind' => 'original', 'author' => 'elripalda',
        'blurb' => '', 'description' => '', 'news' => '', 'credits' => '',
        'version' => '', 'build' => 1, 'released' => '', 'size' => '', 'needs' => 'Xbox 360 ISO',
        'source' => 'github', 'repo' => '', 'asset' => '', 'url' => '', 'sha256' => '',
        // When the release only has a bigger zip (a source archive): where the game's own zip
        // is inside it, its whole name there (* and ? allowed). Needs Recomp Store 1.0.4.
        'inside' => '',
        'icon' => '', 'hero' => '', 'hero_web' => '',
        'focus_x' => 50, 'focus_y' => 30, 'zoom' => 100,
        // A logo in place of the name: where it sits on the banner (b) and the game page (p),
        // nudged in the store's own pixels (x, y) and sized in % (s).
        'logo' => '', 'logo_bx' => 0, 'logo_by' => 0, 'logo_bs' => 100, 'logo_px' => 0, 'logo_py' => 0, 'logo_ps' => 100,
        'featured' => false, 'hidden' => false,
        'setup' => false, 'data_dir' => '', 'slots' => '',
        // The oldest Recomp Store that can install it ('' for any; see rs_min_store()).
        'min_store' => '',
    ];
}

// The oldest Recomp Store a game needs: what the admin set, and at least 1.0.3 when it
// uses something older stores can't do (a zip name with * or ?, a disc image copied as it
// is), 1.0.4 when its zip is inside the release's zip. Stores before 1.0.3 never see a
// game that needs 1.0.3 or newer: publish lists those apart, in newerStoreApps, which those
// stores don't read; 1.0.3 lists it and says to update first.
const RS_GATED_FROM = '1.0.3';
const RS_INSIDE_FROM = '1.0.4';
function rs_min_store(array $g): string {
    $min = trim((string)$g['min_store']);
    $needs = function (string $v) use (&$min) { if ($min === '' || version_compare($min, $v, '<')) $min = $v; };
    if ($g['source'] === 'github' && strpbrk((string)$g['asset'], '*?') !== false) $needs(RS_GATED_FROM);
    if (trim((string)$g['inside']) !== '') $needs(RS_INSIDE_FROM);
    if ($g['setup']) {
        $slots = json_decode((string)$g['slots'], true);
        foreach (is_array($slots) ? $slots : [] as $sl) {
            if (is_array($sl) && !in_array($sl['kind'] ?? 'iso', ['iso', 'stfs'], true)) $needs(RS_GATED_FROM);
        }
    }
    return $min;
}

function rs_games(bool $includeHidden = true): array {
    $rows = rs_db()->query('SELECT id, data, sort FROM games ORDER BY sort ASC, id ASC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $g = array_merge(rs_game_defaults(), json_decode($r['data'], true) ?: []);
        $g['id'] = $r['id'];
        $g['sort'] = (int)$r['sort'];
        if (!$includeHidden && $g['hidden']) continue;
        $out[] = $g;
    }
    return $out;
}

function rs_game(string $id): ?array {
    $st = rs_db()->prepare('SELECT id, data, sort FROM games WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $g = array_merge(rs_game_defaults(), json_decode($r['data'], true) ?: []);
    $g['id'] = $r['id'];
    $g['sort'] = (int)$r['sort'];
    return $g;
}

function rs_save_game(array $g, ?string $oldId = null): void {
    $db = rs_db();
    $data = $g; unset($data['id'], $data['sort']);
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($oldId !== null && $oldId !== $g['id']) {
        $db->prepare('UPDATE games SET id = ?, data = ?, updated = NOW() WHERE id = ?')->execute([$g['id'], $json, $oldId]);
        return;
    }
    $st = $db->prepare('SELECT COUNT(*) FROM games WHERE id = ?');
    $st->execute([$g['id']]);
    if ((int)$st->fetchColumn() > 0) {
        $db->prepare('UPDATE games SET data = ?, updated = NOW() WHERE id = ?')->execute([$json, $g['id']]);
    } else {
        $max = (int)$db->query('SELECT COALESCE(MAX(sort), 0) FROM games')->fetchColumn();
        $db->prepare('INSERT INTO games (id, data, sort, updated) VALUES (?, ?, ?, NOW())')->execute([$g['id'], $json, $max + 10]);
    }
}

function rs_setting(string $k, ?string $default = null): ?string {
    $st = rs_db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function rs_set_setting(string $k, string $v): void {
    rs_db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

// ---- Images -------------------------------------------------------------------

// Saves an uploaded image into /media as a new file and returns its public path.
// kind 'icon': square 512x512 PNG. kind 'hero': 3840 wide JPG for the PS5, plus a 1920 copy for the website.
function rs_save_image(array $file, string $kind, string $id): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('The upload did not arrive. Try again.');
    if ($file['size'] > 25 * 1024 * 1024) throw new RuntimeException('That image is over 25 MB.');
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('Use a PNG, JPG or WebP image.');
    }
    if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('This server is missing PHP\'s GD image library.');
    $src = match ($info[2]) {
        IMAGETYPE_PNG => imagecreatefrompng($file['tmp_name']),
        IMAGETYPE_JPEG => imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_WEBP => imagecreatefromwebp($file['tmp_name']),
    };
    if (!$src) throw new RuntimeException('That image could not be read.');
    if (!is_dir(RS_MEDIA)) mkdir(RS_MEDIA, 0755, true);
    $w = imagesx($src); $hgt = imagesy($src);
    $slug = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?: 'game';
    $tag = bin2hex(random_bytes(4));
    $out = [];
    if ($kind === 'logo') {
        // Kept as a PNG with its transparency, at most 1600x800.
        $k = min(1.0, 1600 / max(1, $w), 800 / max(1, $hgt));
        $tw = max(1, (int)round($w * $k)); $th = max(1, (int)round($hgt * $k));
        $dst = imagecreatetruecolor($tw, $th);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $hgt);
        $name = "$slug-logo-$tag.png";
        imagepng($dst, RS_MEDIA . "/$name", 7);
        $out['logo'] = "/media/$name";
    } elseif ($kind === 'icon') {
        $side = min($w, $hgt);
        $dst = imagecreatetruecolor(512, 512);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($hgt - $side, 2), 512, 512, $side, $side);
        $name = "$slug-icon-$tag.png";
        imagepng($dst, RS_MEDIA . "/$name", 7);
        $out['icon'] = "/media/$name";
    } else {
        foreach ([3840 => 'hero', 1920 => 'hero_web'] as $tw => $key) {
            $tw2 = min($tw, $w); $th = (int)round($hgt * $tw2 / $w);
            $dst = imagecreatetruecolor($tw2, $th);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw2, $th, $w, $hgt);
            $name = "$slug-$key-$tag.jpg";
            imagejpeg($dst, RS_MEDIA . "/$name", $tw === 3840 ? 88 : 82);
            $out[$key] = "/media/$name";
        }
    }
    return $out;
}

// ---- Publish ------------------------------------------------------------------

// Builds catalog.json from the database. Written to a temporary file first and then
// renamed over the old one, so the PS5 store never reads a half-written catalog.
function rs_build_catalog(): array {
    $base = rs_setting('site_url', 'https://recompstore.com');
    $abs = fn(string $p) => $p === '' ? '' : (preg_match('#^https?://#', $p) ? $p : rtrim($base, '/') . $p);
    $apps = [];
    $newer = [];
    $featured = [];
    foreach (rs_games(false) as $i => $g) {
        $app = [
            'id' => $g['id'], 'name' => $g['name'], 'order' => $i,
            'kind' => $g['kind'], 'author' => $g['author'],
            'blurb' => $g['blurb'], 'description' => $g['description'], 'news' => $g['news'], 'credits' => $g['credits'],
            'version' => $g['version'], 'build' => (int)$g['build'], 'released' => $g['released'],
            'size' => $g['size'], 'needs' => $g['needs'],
            'source' => $g['source'] === 'github'
                ? ['type' => 'github', 'repo' => $g['repo'], 'asset' => $g['asset']]
                : ['type' => 'url'],
            'url' => $g['url'], 'sha256' => strtolower(trim($g['sha256'])),
            'icon' => $abs($g['icon']), 'hero' => $abs($g['hero']), 'heroWeb' => $abs($g['hero_web']),
            'banner' => ['x' => (int)$g['focus_x'], 'y' => (int)$g['focus_y'], 'zoom' => (int)$g['zoom']],
            'logo' => $g['logo'] === '' ? null : [
                'url' => $abs($g['logo']),
                'banner' => ['x' => (int)$g['logo_bx'], 'y' => (int)$g['logo_by'], 'scale' => (int)$g['logo_bs']],
                'page' => ['x' => (int)$g['logo_px'], 'y' => (int)$g['logo_py'], 'scale' => (int)$g['logo_ps']],
            ],
            'setup' => null,
        ];
        if ($g['setup']) {
            $slots = json_decode((string)$g['slots'], true);
            $app['setup'] = ['dataDir' => $g['data_dir'], 'slots' => is_array($slots) ? $slots : []];
        }
        if (trim((string)$g['inside']) !== '') $app['source']['inside'] = trim((string)$g['inside']);
        $min = rs_min_store($g);
        if ($min !== '') $app['minStore'] = $min;
        if ($min !== '' && version_compare($min, RS_GATED_FROM, '>=')) $newer[] = $app;
        else $apps[] = $app;
        if ($g['featured']) $featured[] = $g['id'];
    }
    return [
        'catalogVersion' => 1,
        'updated' => gmdate('c'),
        'store' => [
            'version' => rs_setting('store_version', RS_VERSION),
            'build' => (int)rs_setting('store_build', '1'),
            'url' => rs_setting('store_url', ''),
            'sha256' => rs_setting('store_sha256', ''),
        ],
        'site' => [
            'tagline' => rs_setting('tagline', 'Recompiled games, native on PS5.'),
            'payload' => rs_setting('payload_url', ''),
            'discord' => rs_setting('discord_url', ''),
            'kofi' => rs_setting('kofi_url', 'https://ko-fi.com/ripalda'),
        ],
        'featured' => $featured,
        'apps' => $apps,
        'newerStoreApps' => $newer,
    ];
}

function rs_publish(): int {
    $cat = rs_build_catalog();
    $json = json_encode($cat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = RS_CATALOG . '.tmp';
    if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, RS_CATALOG)) {
        throw new RuntimeException('Could not write catalog.json. Check that public_html is writable.');
    }
    rs_set_setting('published_at', gmdate('c'));
    return count($cat['apps']) + count($cat['newerStoreApps']);
}

// ---- Inbox: bug reports and recomp submissions from the website -----------------

function rs_inbox_table(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS inbox (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(16) NOT NULL,
        app_id VARCHAR(64) NOT NULL DEFAULT \'\',
        name VARCHAR(120) NOT NULL DEFAULT \'\',
        contact VARCHAR(200) NOT NULL DEFAULT \'\',
        repo VARCHAR(255) NOT NULL DEFAULT \'\',
        version VARCHAR(64) NOT NULL DEFAULT \'\',
        body TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT \'new\',
        ip_hash CHAR(64) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        INDEX (kind, created), INDEX (ip_hash, created)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_inbox_new_count(): int {
    try {
        rs_inbox_table();
        return (int)rs_db()->query("SELECT COUNT(*) FROM inbox WHERE status = 'new'")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

// The sender's address, kept only as a salted hash: enough to slow down floods, not to identify anyone.
function rs_ip_hash(): string {
    $salt = rs_setting('inbox_salt');
    if (!$salt) { $salt = bin2hex(random_bytes(16)); rs_set_setting('inbox_salt', $salt); }
    // IPv6 addresses count by their /64, since one connection usually has a whole /64.
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) $ip = bin2hex(substr($bin, 0, 8)) . '::/64';
    return hash('sha256', $salt . '|' . $ip);
}
