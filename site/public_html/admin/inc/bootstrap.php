<?php
// Recomp Store admin: shared setup for every admin page.
declare(strict_types=1);

const RS_VERSION = '1.5.0';

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
        // Coming soon (store 1.0.6): listed, with its art and an optional date, but not installable yet.
        'soon' => false, 'date' => '',
        // Where the game keeps its saves, one per line, inside its folder (store 1.0.6: Delete can keep them).
        'saves' => '',
        // The game's own log files, one pattern per line inside its folder (store 1.1.0: Send logs).
        // Empty: the store looks for *.log and files like log.txt or crash_*.txt itself.
        'logs' => '',
        // The player's own files and folders in the game's folder, one per line (store 1.1.2): an
        // update never writes over them (settings, saves, the game files set up from a disc).
        'keep' => '',
        // Extra buttons on the game's page on the website (texture packs, patches...): [{label, url, color}].
        'links' => [],
        // (2.0) Its part of the store: games, emulators, apps or tools; short labels shown on
        // its page (comma-separated, at most 6: "Recomp, Xbox 360"); and whether the store may install
        // it under a new title ID when its own is taken on a console (off: it expects its own folder).
        'category' => 'games', 'tags' => '', 'can_move' => true,
        // (2.0) Testers only: left out of catalog.json and everything public; consoles the admin
        // marked as testers get it in the test catalog (api/catalog.php), marked "testing".
        'testers' => false,
        // Beta: left out of the public catalog; only consoles that redeemed one of its codes see it,
        // and they download beta_file (a zip in RS_BETA_DIR) from this site, not from GitHub.
        'beta' => false, 'beta_file' => '', 'beta_sha256' => '', 'beta_size' => 0, 'beta_version' => '',
    ];
}

// A game's extra buttons, cleaned: a label, an https link and a #rrggbb color each, at most 12.
function rs_clean_links($in): array {
    $out = [];
    foreach (is_array($in) ? $in : [] as $l) {
        if (!is_array($l) || count($out) >= 12) continue;
        $label = mb_substr(trim((string)($l['label'] ?? '')), 0, 40);
        $url = trim((string)($l['url'] ?? ''));
        $color = strtolower(trim((string)($l['color'] ?? '')));
        if ($label === '' || !preg_match('#^https://[^\s<>"]{1,500}$#', $url)) continue;
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) $color = '#2a3446';
        $out[] = ['label' => $label, 'url' => $url, 'color' => $color];
    }
    return $out;
}

// The oldest Recomp Store a game needs: what the admin set, and at least 1.0.3 when it
// uses something older stores can't do (a zip name with * or ?, a disc image copied as it
// is), 1.0.4 when its zip is inside the release's zip. Stores before 1.0.3 never see a
// game that needs 1.0.3 or newer: publish lists those apart, in newerStoreApps, which those
// stores don't read; 1.0.3 lists it and says to update first.
const RS_GATED_FROM = '1.0.3';
const RS_INSIDE_FROM = '1.0.4';
const RS_FILE_FROM = '1.0.6';
const RS_ARCADE_FROM = '1.0.7';
const RS_READY_FROM = '1.0.8';
const RS_PART_FROM = '1.1.2';
// (2.0) A .7z download: the 2.0 previews (1.9.x) and 2.0 unpack them; older stores only read zips.
const RS_7Z_FROM = '1.9.1';
// (2.0) What only the 2.0 store reads: an app that isn't a game (Emulators, Apps, Tools) needs it,
// and anything that needs it is published apart, in v2Apps, which 1.x stores never read.
const RS_V2_FROM = '1.9.1';
function rs_min_store(array $g): string {
    $min = trim((string)$g['min_store']);
    $needs = function (string $v) use (&$min) { if ($min === '' || version_compare($min, $v, '<')) $min = $v; };
    if (rs_category((string)($g['category'] ?? 'games')) !== 'games') $needs(RS_V2_FROM);
    if ($g['source'] === 'github' && strpbrk((string)$g['asset'], '*?') !== false) $needs(RS_GATED_FROM);
    $file = $g['source'] === 'github' ? (string)$g['asset'] : (string)parse_url((string)$g['url'], PHP_URL_PATH);
    if (preg_match('/\.7z$/i', $file)) $needs(RS_7Z_FROM);
    if (trim((string)$g['inside']) !== '') $needs(RS_INSIDE_FROM);
    if ($g['setup']) {
        $slots = json_decode((string)$g['slots'], true);
        foreach (is_array($slots) ? $slots : [] as $sl) {
            if (!is_array($sl)) continue;
            $kind = $sl['kind'] ?? 'iso';
            if ($kind === 'file') $needs(RS_FILE_FROM);
            elseif ($kind === 'arcade') $needs(RS_ARCADE_FROM);
            elseif (!in_array($kind, ['iso', 'stfs'], true)) $needs(RS_GATED_FROM);
            // A set-up file, or a Title Update the store downloads itself (store 1.0.8).
            if (($sl['ready'] ?? '') !== '' || ($sl['updateSha1'] ?? '') !== '' || ($sl['updateId'] ?? '') !== '') $needs(RS_READY_FROM);
            // Part of a disc, the files it must have (store 1.1.2): an older store would copy all of it.
            if (!empty($sl['only']) || !empty($sl['files']) || ($sl['wrong'] ?? '') !== '') $needs(RS_PART_FROM);
        }
    }
    if (rs_keep_list((string)($g['keep'] ?? ''))) $needs(RS_PART_FROM);
    return $min;
}

// (2.0) Title IDs other apps use outside the store, one per line ("PPSA92004 what it is"): the
// admin won't give a game one of them, and recompstore.com/#/ids lists them so developers can
// steer clear too. Edited under Settings.
const RS_TAKEN_DEFAULT = "PPSA92004 An app already on many consoles\nPPSA92405 An app seen in /data/homebrew\nPPSA99901 ps5-plume-triangle demo (Nazky)";
function rs_taken_ids(): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', (string)rs_setting('taken_ids', RS_TAKEN_DEFAULT)) as $line) {
        if (!preg_match('/^\s*([A-Z]{4}\d{5})\b\s*(.*)$/', $line, $m)) continue;
        $out[$m[1]] = mb_substr(trim($m[2]), 0, 80);
    }
    return $out;
}
// (2.0) The block the PS5 store gives a game whose own title ID is taken on a console (its title
// ID guard): never a game's own ID.
const RS_MOVE_BLOCK = ['PPSA97001', 'PPSA97999'];
// Apps already out with an ID in that block (from before it was kept): they can be listed, and the
// PS5 store skips them (it skips every ID in the catalog and in the taken list too).
const RS_MOVE_BLOCK_IN_USE = ['PPSA97358' => 'XPSemu', 'PPSA97510' => 'PSXS5'];
function rs_move_block(string $id): bool {
    if (isset(RS_MOVE_BLOCK_IN_USE[$id])) return false;
    return (bool)preg_match('/^PPSA97\d{3}$/', $id) && $id >= RS_MOVE_BLOCK[0] && $id <= RS_MOVE_BLOCK[1];
}

// (2.0) The parts of the store, as the admin and the website name them.
const RS_CATEGORIES = ['games' => 'Games', 'emulators' => 'Emulators', 'apps' => 'Apps', 'tools' => 'Tools'];
function rs_category(string $c): string {
    return array_key_exists($c, RS_CATEGORIES) ? $c : 'games';
}
// (2.0) Short labels for a game's page: comma-separated, each 1-24 characters, at most 6.
function rs_tags_list(string $text): array {
    $out = [];
    foreach (explode(',', $text) as $t) {
        $t = trim(preg_replace('/\s+/', ' ', $t));
        if ($t === '' || mb_strlen($t) > 24 || count($out) >= 6 || in_array($t, $out, true)) continue;
        $out[] = $t;
    }
    return $out;
}

// A game's save folders: one per line (or comma), inside its folder (or a full /data path),
// spelled plainly. '' when one isn't, with $bad set to it.
function rs_saves_list(string $text, ?string &$bad = null): array {
    $out = [];
    foreach (preg_split('/[\r\n,]+/', $text) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (strlen($p) > 200 || preg_match('#(^|/)\.\.?(/|$)|//|\\\\|[\x00-\x1f\x7f]#', $p) || ($p[0] === '/' && !str_starts_with($p, '/data/'))) { $bad = $p; return []; }
        $out[] = rtrim($p, '/');
        if (count($out) >= 16) break;
    }
    return $out;
}

// The patterns for a game's log files (store 1.1.0's Send logs): inside its folder, * and ? allowed.
function rs_logs_list(string $text, ?string &$bad = null): array {
    $out = [];
    foreach (preg_split('/[\r\n,]+/', $text) as $p) {
        $p = trim($p);
        if ($p === '') continue;
        if (strlen($p) > 160 || $p[0] === '/' || preg_match('#(^|/)\.\.?(/|$)|//|\\\\|[\x00-\x1f\x7f]#', $p)) { $bad = $p; return []; }
        $out[] = $p;
        if (count($out) >= 12) break;
    }
    return $out;
}

// What an update leaves alone (store 1.1.2): files and folders inside the game's folder, one
// per line, spelled plainly (no * or ?). '' when one isn't, with $bad set to it.
function rs_keep_list(string $text, ?string &$bad = null): array {
    $out = [];
    foreach (preg_split('/[\r\n,]+/', $text) as $p) {
        $p = rtrim(trim($p), '/');
        if ($p === '') continue;
        if (strlen($p) > 160 || $p[0] === '/' || preg_match('#(^|/)\.\.?(/|$)|//|\\\\|[*?:\x00-\x1f\x7f]#', $p)) { $bad = $p; return []; }
        $out[] = $p;
        if (count($out) >= 32) break;
    }
    return $out;
}

// A path inside a disc or a game's folder, spelled plainly: relative, no "..", no backslash.
function rs_plain_rel($p): bool {
    return is_string($p) && $p !== '' && strlen($p) <= 200 && $p[0] !== '/' && !preg_match('#(^|/)\.\.?(/|$)|//|\\\\|:|[\x00-\x1f\x7f]#', $p);
}

// What's wrong with a game's file list, or null. The store checks these too and won't set
// up a slot that breaks them; here they're caught before they're published.
function rs_slots_error(array $slots): ?string {
    $leaf = fn($v) => is_string($v) && $v !== '' && strlen($v) <= 128 && $v[0] !== '.' && !preg_match('#[/\\\\:\x00-\x1f\x7f]#', $v);
    $keys = [];
    foreach ($slots as $i => $sl) {
        $n = 'File ' . ($i + 1);
        if (!is_array($sl)) return "$n isn't an entry like the template's.";
        $key = $sl['key'] ?? '';
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key)) return "$n: key has to be letters, numbers, - or _ (like game).";
        if (in_array($key, $keys, true)) return "$n: another file already uses the key $key.";
        $keys[] = $key;
        $kind = $sl['kind'] ?? 'iso';
        if (!in_array($kind, ['iso', 'stfs', 'image', 'file', 'arcade'], true)) return "$n: kind has to be iso, stfs, image, file or arcade.";
        if (isset($sl['ready']) && $sl['ready'] !== '' && (!is_string($sl['ready']) || strlen($sl['ready']) > 200 || $sl['ready'][0] === '/' || substr($sl['ready'], -1) === '/' || preg_match('#(^|/)\.\.?(/|$)|//|\\\\|[\x00-\x1f\x7f]#', $sl['ready'])))
            return "$n: ready is a file inside the slot's folder, like Default.xexp or Data/GameLogic.dllp.";
        foreach (['updateId', 'updateSha1'] as $f)
            if (isset($sl[$f]) && $sl[$f] !== '' && (!is_string($sl[$f]) || !preg_match('/^[0-9A-Fa-f]{40}\z/', $sl[$f])))
                return "$n: $f is 40 letters and numbers.";
        if ((($sl['updateId'] ?? '') !== '') !== (($sl['updateSha1'] ?? '') !== ''))
            return "$n: a Title Update the store downloads needs both updateId and updateSha1.";
        if (($sl['updateId'] ?? '') !== '' && ($kind !== 'stfs' || !empty($sl['multiple']) || !preg_match('/^[0-9A-Fa-f]{8}\z/', (string)($sl['titleId'] ?? ''))))
            return "$n: only a Title Update (kind stfs, with its titleId) can be downloaded by the store.";
        // Part of a disc and the files it must have (store 1.1.2), for an Xbox 360 disc.
        $only = $sl['only'] ?? [];
        if (is_string($only)) $only = preg_split('/[\s,]+/', trim($only), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($only) || count($only) > 16) return "$n: only is the disc's folders to copy, like [\"DBZ3\"].";
        $only = array_map(fn($o) => is_string($o) ? rtrim($o, '/') : $o, $only);
        foreach ($only as $o) if (!rs_plain_rel($o)) return "$n: only is the disc's folders to copy, like [\"DBZ3\"] (no leading /, no ..).";
        if (isset($sl['files']) && !is_array($sl['files'])) return "$n: files is a list like [{\"path\": \"DBZ3/yae3_xenon.xex\", \"size\": 4890624, \"crc32\": \"F67F6C97\"}].";
        $wantFiles = $sl['files'] ?? [];
        if (count($wantFiles) > 512) return "$n: files lists 512 files at most.";
        foreach ($wantFiles as $k => $w) {
            $wn = "$n, files entry " . ((int)$k + 1);
            if (!is_array($w) || !rs_plain_rel($w['path'] ?? null)) return "$wn: path is the file's path on the disc, like DBZ3/us/adx_usa.afs.";
            if (!is_int($w['size'] ?? null) || $w['size'] < 1 || $w['size'] > 4294967295) return "$wn: size is the file's size in bytes, a whole number.";
            if (isset($w['crc32']) && $w['crc32'] !== '' && (!is_string($w['crc32']) || !preg_match('/^[0-9A-Fa-f]{8}\z/', $w['crc32']))) return "$wn: crc32 is 8 letters and numbers, like F67F6C97.";
            if ($only) {
                $inside = false;
                foreach ($only as $o) $inside = $inside || strcasecmp($w['path'], $o) === 0 || stripos($w['path'], $o . '/') === 0;
                if (!$inside) return "$wn: {$w['path']} isn't in the folders only copies, so it would never be there.";
            }
        }
        if (isset($sl['wrong']) && (!is_string($sl['wrong']) || mb_strlen($sl['wrong']) > 300)) return "$n: wrong is what to tell a player whose disc isn't the right one (300 characters at most).";
        if (($only || $wantFiles || ($sl['wrong'] ?? '') !== '') && $kind !== 'iso') return "$n: only, files and wrong are for a disc (kind iso).";
        $to = (string)($sl['to'] ?? '.');
        if ($to !== '.' && ($to === '' || $to[0] === '/' || preg_match('#(^|/)\.\.?(/|$)|//|\\\\|[\x00-\x1f\x7f]#', $to))) return "$n: to is a folder inside the game's, like game or baseq3 (or . for right in it).";
        if ($kind !== 'file') continue;
        foreach (['name', 'made'] as $f) if (isset($sl[$f]) && $sl[$f] !== '' && !$leaf($sl[$f])) return "$n: $f has to be one file name, with no / or \\ (like DIABDAT.MPQ).";
        foreach (['ext', 'pick', 'also'] as $f) if (isset($sl[$f]) && (!is_string($sl[$f]) || preg_match('#[/\\\\\x00-\x1f\x7f]#', $sl[$f]))) return "$n: $f is names or patterns only, with no / or \\.";
        if (isset($sl['sha1']) && (!is_string($sl['sha1']) || !preg_match('/^\s*([0-9A-Fa-f]{40}[\s,]*)*$/', $sl['sha1']))) return "$n: sha1 is SHA-1s (40 letters and numbers each), separated by spaces.";
        if (isset($sl['need']) && (!is_int($sl['need']) || $sl['need'] < 1 || $sl['need'] > 64)) return "$n: need is a number from 1 to 64.";
        if (isset($sl['n64']) && !is_bool($sl['n64'])) return "$n: n64 is true or false.";
    }
    return null;
}

// ---- Discover: the store's and the site's sections -------------------------------------

// Before any are set: every game in a grid, then what's coming.
function rs_discover_default(): array {
    return [
        ['type' => 'games', 'title' => 'Latest games', 'layout' => 'grid', 'all' => true, 'games' => []],
        ['type' => 'soon', 'title' => 'Coming soon', 'layout' => 'row'],
    ];
}

// Sections as the admin saved them, cleaned: known types and layouts, titles of a sane
// length, game IDs that look like IDs. At most 32.
function rs_clean_sections($in): array {
    $out = [];
    foreach (is_array($in) ? $in : [] as $s) {
        if (!is_array($s) || count($out) >= 32) continue;
        $type = in_array($s['type'] ?? '', ['games', 'soon', 'spotlight', 'category', 'top', 'trending', 'new', 'updated'], true) ? $s['type'] : null;
        if (!$type) continue;
        $title = mb_substr(trim((string)($s['title'] ?? '')), 0, 60);
        $id = fn($v) => is_string($v) && preg_match('/^[A-Za-z0-9_-]{3,32}$/', $v) ? $v : null;
        if (in_array($type, ['top', 'trending', 'new', 'updated'], true)) {
            // (2.0) The charts (installs counted by the store, and GitHub's releases): filled in by
            // the store and the site from /api/charts.php. Stores before 2.0 leave them out.
            $layout = in_array($s['layout'] ?? '', ['row', 'grid', 'wide'], true) ? $s['layout'] : 'row';
            $out[] = ['type' => $type, 'title' => $title, 'layout' => $layout];
            continue;
        }
        if ($type === 'category') {
            // (2.0) Every app in one part of the store; stores before 2.0 leave it out.
            $layout = in_array($s['layout'] ?? '', ['row', 'grid', 'wide'], true) ? $s['layout'] : 'row';
            $out[] = ['type' => 'category', 'title' => $title, 'layout' => $layout, 'category' => rs_category((string)($s['category'] ?? 'games'))];
            continue;
        }
        if ($type === 'spotlight') {
            $game = $id($s['game'] ?? '');
            if ($game) $out[] = ['type' => 'spotlight', 'title' => $title, 'game' => $game];
            continue;
        }
        $layouts = $type === 'soon' ? ['row', 'wide'] : ['row', 'grid', 'wide'];
        $layout = in_array($s['layout'] ?? '', $layouts, true) ? $s['layout'] : ($type === 'soon' ? 'row' : 'grid');
        if ($type === 'soon') { $out[] = ['type' => 'soon', 'title' => $title, 'layout' => $layout]; continue; }
        $games = [];
        foreach (is_array($s['games'] ?? null) ? $s['games'] : [] as $g) if (($g = $id($g)) && !in_array($g, $games, true) && count($games) < 64) $games[] = $g;
        $out[] = ['type' => 'games', 'title' => $title, 'layout' => $layout, 'all' => !empty($s['all']), 'games' => $games];
    }
    return $out;
}

function rs_discover_sections(): array {
    $saved = rs_setting('discover_sections');
    if ($saved === null) return rs_discover_default();
    return rs_clean_sections(json_decode($saved, true));
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

// A path on this site as a full https:// address.
function rs_abs(string $p): string {
    $base = rs_setting('site_url', 'https://recompstore.com');
    return $p === '' ? '' : (preg_match('#^https?://#', $p) ? $p : rtrim($base, '/') . $p);
}

// One game as the store reads it (an entry of catalog.json's apps or newerStoreApps).
function rs_app_entry(array $g, int $order): array {
    $abs = 'rs_abs';
    $app = [
        'id' => $g['id'], 'name' => $g['name'], 'order' => $order,
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
    $links = rs_clean_links($g['links']);
    if ($links) $app['links'] = $links;
    $saves = rs_saves_list((string)$g['saves']);
    if ($saves) $app['saves'] = $saves;
    $logs = rs_logs_list((string)($g['logs'] ?? ''));
    if ($logs) $app['logs'] = $logs;
    $keep = rs_keep_list((string)($g['keep'] ?? ''));
    if ($keep) $app['keep'] = $keep;
    // (2.0) Older stores don't read these, and list every entry as a game, as before.
    $app['category'] = rs_category((string)($g['category'] ?? 'games'));
    $tags = rs_tags_list((string)($g['tags'] ?? ''));
    if ($tags) $app['tags'] = $tags;
    if (array_key_exists('can_move', $g) && !$g['can_move']) $app['canMove'] = false;
    $min = rs_min_store($g);
    if ($min !== '') $app['minStore'] = $min;
    return $app;
}

// Builds catalog.json from the database. Written to a temporary file first and then
// renamed over the old one, so the PS5 store never reads a half-written catalog.
// Beta games are left out: only consoles that redeemed one of their codes get them (api/beta.php).
// (2.0) Apps only the 2.0 store reads (not a game, or needing 1.9.1) go in v2Apps, never in apps
// or newerStoreApps, so a 1.x store never lists them; Coming soon ones that aren't games, in
// comingSoonV2. featured names only what 1.x stores list; featuredV2 is the admin's whole list.
// Testers-only apps are left out, unless $testing (the test catalog, api/catalog.php): then
// they're in, each marked "testing".
function rs_build_catalog(bool $testing = false): array {
    $abs = 'rs_abs';
    $apps = [];
    $newer = [];
    $v2 = [];
    $featuredV2 = [];
    $soon = [];
    $soonV2 = [];
    foreach (array_values(array_filter(rs_games(false), fn($g) => !$g['beta'] && ($testing || empty($g['testers'])))) as $i => $g) {
        $notGame = rs_category((string)$g['category']) !== 'games';
        if ($g['soon']) {
            // Not out yet: what the store and the site show of it, nothing to install.
            $entry = [
                'id' => $g['id'], 'name' => $g['name'], 'order' => $i, 'kind' => $g['kind'], 'author' => $g['author'],
                'blurb' => $g['blurb'], 'description' => $g['description'], 'date' => trim((string)$g['date']),
                'icon' => $abs($g['icon']), 'hero' => $abs($g['hero']), 'heroWeb' => $abs($g['hero_web']),
                'banner' => ['x' => (int)$g['focus_x'], 'y' => (int)$g['focus_y'], 'zoom' => (int)$g['zoom']],
                'logo' => $g['logo'] === '' ? null : [
                    'url' => $abs($g['logo']),
                    'banner' => ['x' => (int)$g['logo_bx'], 'y' => (int)$g['logo_by'], 'scale' => (int)$g['logo_bs']],
                    'page' => ['x' => (int)$g['logo_px'], 'y' => (int)$g['logo_py'], 'scale' => (int)$g['logo_ps']],
                ],
            ];
            if ($g['source'] === 'github' && preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', (string)$g['repo'])) $entry['source'] = ['type' => 'github', 'repo' => $g['repo']];
            elseif (preg_match('#^https://#', (string)$g['url'])) $entry['url'] = $g['url'];
            if (!empty($g['testers'])) $entry['testing'] = true;
            if ($notGame) { $entry['category'] = rs_category((string)$g['category']); $soonV2[] = $entry; }
            else $soon[] = $entry;
            continue;
        }
        $app = rs_app_entry($g, $i);
        if (!empty($g['testers'])) $app['testing'] = true;
        $min = $app['minStore'] ?? '';
        if ($notGame || ($min !== '' && version_compare($min, RS_V2_FROM, '>='))) $v2[] = $app;
        elseif ($min !== '' && version_compare($min, RS_GATED_FROM, '>=')) $newer[] = $app;
        else $apps[] = $app;
        if ($g['featured']) $featuredV2[] = $g['id'];
    }
    $old = array_merge(array_column($apps, 'id'), array_column($newer, 'id'));
    $featured = array_values(array_filter($featuredV2, fn($id) => in_array($id, $old, true)));
    // Discover's sections, as the admin arranged them: a spotlight only on a game that's listed.
    $listed = array_merge($old, array_column($v2, 'id'));
    $sections = [];
    foreach (rs_discover_sections() as $s) {
        if ($s['type'] === 'spotlight' && !in_array($s['game'], $listed, true)) continue;
        if ($s['type'] === 'games') $s['games'] = array_values(array_filter($s['games'], fn($id) => in_array($id, $listed, true)));
        $sections[] = $s;
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
            // (2.0) Whether the website shows its 2.0 side to everyone (true) or only to the
            // logged-in admin as a preview (false). Settings changes it in place, no Publish needed.
            'v2' => rs_site_v2(),
        ],
        'featured' => $featured,
        'apps' => $apps,
        'newerStoreApps' => $newer,
        // Read by Recomp Store 1.0.6 and newer, and the site; older stores skip them.
        'comingSoon' => $soon,
        // (2.0) Read only by the 2.0 store, and the site when its 2.0 side is on.
        'featuredV2' => $featuredV2,
        'v2Apps' => $v2,
        'comingSoonV2' => $soonV2,
        'discover' => ['sections' => $sections],
    ];
}

// (2.0) The website's 2.0 side: 'all' shows it to everyone; anything else ('me', the default) only
// to the logged-in admin, with a Preview ribbon.
function rs_site_v2(): bool {
    return rs_setting('site_v2', 'me') === 'all';
}

// (2.0) Writes catalog.json as it is now with only site.v2 changed: switching the website's 2.0
// side doesn't publish anything else that's waiting.
function rs_catalog_set_site_v2(bool $on): void {
    $cat = json_decode((string)@file_get_contents(RS_CATALOG), true);
    if (!is_array($cat)) { rs_publish(); return; }
    $cat['site'] = (is_array($cat['site'] ?? null) ? $cat['site'] : []);
    $cat['site']['v2'] = $on;
    rs_write_catalog($cat);
}

function rs_write_catalog(array $cat): void {
    $json = json_encode($cat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = RS_CATALOG . '.tmp';
    if ($json === false || file_put_contents($tmp, $json . "\n") === false || !rename($tmp, RS_CATALOG)) {
        throw new RuntimeException('Could not write catalog.json. Check that public_html is writable.');
    }
}

function rs_publish(): int {
    $cat = rs_build_catalog();
    rs_write_catalog($cat);
    rs_set_setting('published_at', gmdate('c'));
    // (2.0) When each app first went out, for the charts' New.
    try {
        rs_stats_tables();
        $mark = rs_db()->prepare('INSERT IGNORE INTO app_published (app_id, first_at) VALUES (?, NOW())');
        foreach (array_merge($cat['apps'], $cat['newerStoreApps'], $cat['v2Apps']) as $a) $mark->execute([(string)$a['id']]);
    } catch (Throwable $e) {
    }
    return count($cat['apps']) + count($cat['newerStoreApps']) + count($cat['v2Apps']);
}

// How many Coming soon entries the last build listed (for Publish's message).
function rs_soon_count(): int {
    $c = json_decode((string)@file_get_contents(RS_CATALOG), true);
    return is_array($c) && is_array($c['comingSoon'] ?? null) ? count($c['comingSoon']) : 0;
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
    // Log files a player sent from the store (kind 'logs'), kept with their message.
    rs_db()->exec('CREATE TABLE IF NOT EXISTS inbox_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        inbox_id INT NOT NULL,
        name VARCHAR(160) NOT NULL,
        size INT NOT NULL DEFAULT 0,
        body MEDIUMTEXT NOT NULL,
        INDEX (inbox_id)
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

// ---- Beta: games only testers with a code get ---------------------------------------------
// A beta game is left out of the public catalog. You make codes for it (8 digits, shown as
// 12-345-678); a console that redeems one in Recomp Store 1.1.0 gets the game in its library,
// and downloads its zip from this site (RS_BETA_DIR), never from a public link. One console
// per code: the store install that redeems it keeps it, until you cancel the code.

const RS_BETA_FROM = '1.1.0';

// Where beta zips are kept: beside public_html when the host allows it (never served directly),
// otherwise public_html/beta-files, which its own .htaccess blocks.
function rs_beta_dir(): string {
    $outside = dirname(RS_PUBLIC) . '/recompstore-beta';
    if (is_dir($outside)) return $outside;
    $inside = RS_PUBLIC . '/beta-files';
    if (is_dir($inside)) return $inside;
    return is_writable(dirname(RS_PUBLIC)) ? $outside : $inside;
}

function rs_beta_dir_ready(): string {
    $dir = rs_beta_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not make the beta folder ' . $dir . '.');
    if (str_starts_with($dir, RS_PUBLIC) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    return $dir;
}

// A beta zip's SHA-256, kept beside it (<zip>.sha256) once worked out: a big zip takes a while.
function rs_beta_remember_hash(string $zip, string $sha): void {
    @file_put_contents($zip . '.sha256', $sha . ' ' . filesize($zip) . ' ' . filemtime($zip) . "\n");
}
function rs_beta_hash(string $zip): string {
    $kept = explode(' ', trim((string)@file_get_contents($zip . '.sha256')));
    if (count($kept) === 3 && preg_match('/^[0-9a-f]{64}$/', $kept[0]) && (int)$kept[1] === filesize($zip) && (int)$kept[2] === filemtime($zip)) return $kept[0];
    @set_time_limit(600);
    $sha = hash_file('sha256', $zip);
    rs_beta_remember_hash($zip, $sha);
    return $sha;
}

// The zips in the beta folder (uploaded here or with hPanel's File Manager), newest first.
function rs_beta_files(): array {
    $dir = rs_beta_dir();
    $out = [];
    foreach (is_dir($dir) ? (glob($dir . '/*.zip') ?: []) : [] as $p) $out[basename($p)] = ['size' => filesize($p), 'time' => filemtime($p)];
    uasort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

function rs_beta_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS beta_codes (
        code CHAR(8) PRIMARY KEY,
        game_id VARCHAR(32) NOT NULL,
        note VARCHAR(120) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        install_id CHAR(16) NULL,
        redeemed DATETIME NULL,
        revoked TINYINT NOT NULL DEFAULT 0,
        INDEX (game_id), INDEX (install_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS beta_installs (
        install_id CHAR(16) PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        created DATETIME NOT NULL,
        last_seen DATETIME NOT NULL,
        store_version VARCHAR(32) NOT NULL DEFAULT \'\',
        tester TINYINT NOT NULL DEFAULT 0
    ) CHARACTER SET utf8mb4');
    // (2.0) A console the admin marked as a tester (Settings, Testers): it gets the test catalog.
    if (!$db->query("SHOW COLUMNS FROM beta_installs LIKE 'tester'")->fetch()) {
        $db->exec('ALTER TABLE beta_installs ADD COLUMN tester TINYINT NOT NULL DEFAULT 0');
    }
    $db->exec('CREATE TABLE IF NOT EXISTS beta_tries (
        ip_hash CHAR(64) NOT NULL,
        at DATETIME NOT NULL,
        INDEX (ip_hash, at)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_beta_format(string $code): string {
    return substr($code, 0, 2) . '-' . substr($code, 2, 3) . '-' . substr($code, 5, 3);
}

// $n new codes for a game, each unused and unique.
function rs_beta_make_codes(string $gameId, int $n, string $note): array {
    rs_beta_tables();
    $db = rs_db();
    $ins = $db->prepare('INSERT IGNORE INTO beta_codes (code, game_id, note, created) VALUES (?, ?, ?, NOW())');
    // (2.0) Never the number of a web link code (they're typed into the same keypad).
    rs_link_tables();
    $web = $db->prepare('SELECT COUNT(*) FROM link_codes WHERE code = ?');
    $made = [];
    for ($tries = 0; count($made) < $n && $tries < $n * 20; ++$tries) {
        $code = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $web->execute([$code]);
        if ((int)$web->fetchColumn() > 0) continue;
        $ins->execute([$code, $gameId, $note]);
        if ($ins->rowCount() === 1) $made[] = $code;
    }
    return $made;
}

// The secret the beta download links are signed with (made once, kept in settings).
function rs_beta_secret(): string {
    $s = rs_setting('beta_secret');
    if (!$s) { $s = bin2hex(random_bytes(32)); rs_set_setting('beta_secret', $s); }
    return $s;
}

function rs_beta_sig(string $install, string $gameId): string {
    return substr(hash_hmac('sha256', $install . '|' . $gameId, rs_beta_secret()), 0, 32);
}

// A beta game as the store reads it, for one install: downloads come from this site, signed for it.
function rs_beta_entry(array $g, string $install, int $order): array {
    $app = rs_app_entry($g, $order);
    $app['source'] = ['type' => 'url'];
    $app['url'] = rs_abs('/api/beta.php') . '?a=file&id=' . rawurlencode($g['id']) . '&install=' . $install . '&sig=' . rs_beta_sig($install, $g['id']);
    $app['sha256'] = strtolower((string)$g['beta_sha256']);
    if (trim((string)$g['beta_version']) !== '') $app['version'] = trim((string)$g['beta_version']);
    if ((int)$g['beta_size'] > 0) {
        $app['size'] = rs_human_size((int)$g['beta_size']);
        $app['bytes'] = (int)$g['beta_size']; // store 1.1.2: progress, and the download in pieces
    }
    $app['beta'] = true;
    $min = $app['minStore'] ?? '';
    if ($min === '' || version_compare($min, RS_BETA_FROM, '<')) $app['minStore'] = RS_BETA_FROM;
    return $app;
}

function rs_human_size(int $b): string {
    if ($b >= 1024 ** 3) return round($b / 1024 ** 3, 1) . ' GB';
    if ($b >= 1024 ** 2) return round($b / 1024 ** 2) . ' MB';
    return max(1, (int)round($b / 1024)) . ' KB';
}

// (2.0) The store and the site talking: consoles, linked phones, reports, GitHub, sources, charts.
require __DIR__ . '/connect.php';
