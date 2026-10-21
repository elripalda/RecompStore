<?php
// Recomp Store website, 2.3.0's parts (docs/store-2.3.md): what a game needs to be ready to play,
// its mods, collections, likes, tester feedback, the Discord posts, and the lists of apps a linked
// phone keeps for Restore my apps. Loaded by bootstrap.php.
declare(strict_types=1);

// ---- Ready to play: what a game needs, ticked on its page ----------------------------------------
const RS_REQUIRES = ['loader' => 'A payload loader (port 9021)', 'smp' => 'ShadowMountPlus', 'kstuff' => 'kstuff',
                     'etahen' => 'etaHEN', 'internet' => 'Internet', 'usb' => 'A USB drive'];
function rs_requires_clean($v): array {
    $out = [];
    foreach (is_array($v) ? $v : [] as $k) if (is_string($k) && isset(RS_REQUIRES[$k]) && !in_array($k, $out, true)) $out[] = $k;
    return $out;
}

// ---- Mods: GitHub release files, checked by their SHA-256 -------------------------------------------
// One per line in the editor is awkward for six fields: it's JSON, like the file slots, checked here.
function rs_mods_clean(string $json, ?string &$bad = null): array {
    $bad = null;
    if (trim($json) === '') return [];
    $in = json_decode($json, true);
    if (!is_array($in) || !array_is_list($in)) { $bad = 'Mods: that isn\'t a JSON list ([ ... ]).'; return []; }
    $out = [];
    $ids = [];
    foreach ($in as $i => $m) {
        $n = $i + 1;
        if (!is_array($m)) { $bad = "Mods: entry $n isn't an object."; return []; }
        $mod = [
            'id' => (string)($m['id'] ?? ''), 'name' => trim((string)($m['name'] ?? '')), 'author' => trim((string)($m['author'] ?? '')),
            'blurb' => trim((string)($m['blurb'] ?? '')), 'repo' => trim((string)($m['repo'] ?? '')), 'asset' => trim((string)($m['asset'] ?? '')),
            'sha256' => strtolower(trim((string)($m['sha256'] ?? ''))), 'size' => max(0, (int)($m['size'] ?? 0)), 'target' => trim((string)($m['target'] ?? '')),
        ];
        if (!preg_match('/^[A-Za-z0-9_.-]{1,48}$/', $mod['id']) || in_array($mod['id'], $ids, true)) { $bad = "Mods: entry $n needs an id of its own (letters, digits, - _ .)."; return []; }
        if ($mod['name'] === '' || mb_strlen($mod['name']) > 120) { $bad = "Mods: {$mod['id']} needs a name."; return []; }
        if (!rs_repo_ok($mod['repo'])) { $bad = "Mods: {$mod['id']}'s repo isn't owner/name."; return []; }
        if (!preg_match('#^[A-Za-z0-9._+-]{1,80}/[A-Za-z0-9._+-]{1,160}$#', $mod['asset']) || str_contains($mod['asset'], '..')) { $bad = "Mods: {$mod['id']}'s file is tag/name (v1.0/hd-pack.zip)."; return []; }
        if (!preg_match('/^[0-9a-f]{64}$/', $mod['sha256'])) { $bad = "Mods: {$mod['id']} needs the file's SHA-256 (64 hex)."; return []; }
        if (!preg_match('/^[A-Za-z0-9 _.()+-]{1,64}$/', $mod['target']) || $mod['target'][0] === '.' || str_contains($mod['target'], '..')) { $bad = "Mods: {$mod['id']}'s folder is one plain name inside the mods folder."; return []; }
        $ids[] = $mod['id'];
        $out[] = array_filter($mod, fn($v) => $v !== '' && $v !== 0);
        if (count($out) >= 64) break;
    }
    return $out;
}
function rs_mods_dir_ok(string $d): bool {
    return $d === '' || (bool)preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}(/[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}){0,3}$#', $d) && !str_contains($d, '..');
}

// ---- Collections ------------------------------------------------------------------------------
function rs_collections(): array {
    $c = json_decode((string)rs_setting('collections', '[]'), true);
    return is_array($c) ? array_values(array_filter($c, 'is_array')) : [];
}
function rs_collections_clean(array $in): array {
    $out = [];
    $seen = [];
    foreach ($in as $c) {
        if (!is_array($c) || count($out) >= 32) continue;
        $id = (string)($c['id'] ?? '');
        $title = mb_substr(trim((string)($c['title'] ?? '')), 0, 80);
        if (!preg_match('/^[a-z0-9-]{1,48}$/', $id) || $title === '' || isset($seen[$id])) continue;
        $ids = [];
        foreach ((array)($c['ids'] ?? []) as $g) if (is_string($g) && preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $g) && !in_array($g, $ids, true) && count($ids) < 60) $ids[] = $g;
        $seen[$id] = true;
        $out[] = ['id' => $id, 'title' => $title, 'blurb' => mb_substr(trim((string)($c['blurb'] ?? '')), 0, 300), 'ids' => $ids];
    }
    return $out;
}
// What the catalog says: each collection's apps that are listed in it.
function rs_catalog_collections(array $listed): array {
    $out = [];
    foreach (rs_collections() as $c) {
        $ids = array_values(array_filter($c['ids'] ?? [], fn($id) => in_array($id, $listed, true)));
        if ($ids) $out[] = ['id' => $c['id'], 'title' => $c['title'], 'blurb' => $c['blurb'] ?? '', 'ids' => $ids];
    }
    return $out;
}

// ---- Likes: one per console and app ------------------------------------------------------------
function rs_likes_table(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS likes (
        install_id CHAR(16) NOT NULL,
        app_id VARCHAR(64) NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY (install_id, app_id),
        INDEX (app_id)
    ) CHARACTER SET utf8mb4');
    $done = true;
}
function rs_likes_counts(): array {
    try {
        rs_likes_table();
        $out = [];
        foreach (rs_db()->query('SELECT app_id, COUNT(*) n FROM likes GROUP BY app_id ORDER BY n DESC LIMIT 2000') as $r) $out[(string)$r['app_id']] = (int)$r['n'];
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

// ---- Tester feedback: Send logs from a beta's page, with a verdict and the build -------------------
const RS_VERDICTS = ['works' => 'Works', 'crashes' => 'Crashes', 'slow' => 'Slow', 'other' => 'Other'];
function rs_inbox_feedback_columns(): void {
    static $done = false;
    if ($done) return;
    rs_inbox_category_column();
    $db = rs_db();
    if (!$db->query("SHOW COLUMNS FROM inbox LIKE 'feedback'")->fetch()) $db->exec("ALTER TABLE inbox ADD COLUMN feedback VARCHAR(16) NOT NULL DEFAULT ''");
    if (!$db->query("SHOW COLUMNS FROM inbox LIKE 'build'")->fetch()) $db->exec("ALTER TABLE inbox ADD COLUMN build VARCHAR(64) NOT NULL DEFAULT ''");
    $done = true;
}

// ---- Restore my apps: each linked device keeps its consoles' last lists (kept after unlinking) ----
// One list per device and console (install), so a store set up again from scratch, linked to the same
// phone, still finds the list its old install left; the 4 newest per device are kept.
function rs_link_apps_table(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS link_apps (
        device_id CHAR(16) NOT NULL,
        install_id CHAR(16) NOT NULL,
        apps MEDIUMTEXT NOT NULL,
        made DATETIME NOT NULL,
        PRIMARY KEY (device_id, install_id),
        INDEX (device_id, made)
    ) CHARACTER SET utf8mb4');
    $done = true;
}
// From a console's state: its store apps, for every device linked to it. A state with none (a store
// just set up again) never writes over a list.
function rs_link_apps_save(PDO $db, string $install, array $state): void {
    $apps = [];
    foreach (is_array($state['apps'] ?? null) ? $state['apps'] : [] as $a) {
        if (!is_array($a) || empty($a['store']) || !preg_match('/^[A-Za-z0-9_.-]{3,48}$/', (string)($a['id'] ?? ''))) continue;
        $apps[] = ['id' => (string)$a['id'], 'name' => mb_substr((string)($a['name'] ?? ''), 0, 120), 'version' => mb_substr((string)($a['version'] ?? ''), 0, 40)];
        if (count($apps) >= 300) break;
    }
    if (!$apps) return;
    rs_link_apps_table();
    $st = $db->prepare('SELECT device_id FROM link_devices WHERE install_id = ?');
    $st->execute([$install]);
    $json = json_encode($apps, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $put = $db->prepare('INSERT INTO link_apps (device_id, install_id, apps, made) VALUES (?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE apps = VALUES(apps), made = NOW()');
    $old = $db->prepare('SELECT install_id FROM link_apps WHERE device_id = ? ORDER BY made DESC LIMIT 100 OFFSET 4');
    $drop = $db->prepare('DELETE FROM link_apps WHERE device_id = ? AND install_id = ?');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $dev) {
        $put->execute([$dev, $install, $json]);
        $old->execute([$dev]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $i) $drop->execute([$dev, $i]);
    }
}
// What Restore my apps offers a console: the lists kept for the devices linked to it now, newest first.
function rs_link_apps_lists(PDO $db, string $install): array {
    rs_link_apps_table();
    $st = $db->prepare('SELECT a.install_id, a.apps, UNIX_TIMESTAMP(a.made) made, d.label FROM link_apps a JOIN link_devices d ON d.device_id = a.device_id
                        WHERE d.install_id = ? ORDER BY a.made DESC LIMIT 16');
    $st->execute([$install]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $apps = json_decode((string)$r['apps'], true);
        if (!is_array($apps) || !$apps) continue;
        $label = trim((string)$r['label']) !== '' ? (string)$r['label'] : 'Your phone';
        $out[] = ['from' => mb_substr($label . ($r['install_id'] === $install ? ' (this PS5 now)' : ''), 0, 60), 'made' => gmdate('Y-m-d', (int)$r['made']), 'apps' => $apps];
    }
    return $out;
}

// ---- Discord: a post when a signed publish goes out ----------------------------------------------
// The webhook is a secret the owner pastes in Settings: kept in the settings table, shown back only
// as its last characters, never logged, never in the catalog.
function rs_discord_hook(): string { return (string)rs_setting('discord_webhook', ''); }
function rs_discord_hook_ok(string $u): bool {
    return (bool)preg_match('#^https://(discord\.com|discordapp\.com|ptb\.discord\.com|canary\.discord\.com)/api/webhooks/\d{5,30}/[A-Za-z0-9_-]{20,120}$#D', $u);
}
function rs_discord_hook_shown(string $u): string {
    if ($u === '' || !preg_match('#/webhooks/(\d+)/(.+)$#', $u, $m)) return '';
    return '…/webhooks/' . substr($m[1], 0, 4) . '…' . substr($m[2], -4);
}
// At most one a minute: the publish posts and the tests each have their own minute, so a test
// never holds back the post of a publish right after it.
function rs_discord_send(array $payload, ?string &$why = null, string $kind = 'post'): bool {
    $why = null;
    $hook = rs_discord_hook();
    if (!rs_discord_hook_ok($hook)) { $why = 'No webhook set.'; return false; }
    $key = $kind === 'test' ? 'discord_last_test' : 'discord_last_post';
    if (time() - (int)rs_setting($key, '0') < 60) { $why = 'At most one ' . ($kind === 'test' ? 'test' : 'post') . ' a minute.'; return false; }
    rs_set_setting($key, (string)time());
    // Desktop tests only (defined by a CLI test script, never by a web request): a local stand-in.
    $test = defined('RS_DISCORD_TEST_URL');
    $ch = curl_init($test ? RS_DISCORD_TEST_URL : $hook);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => $test ? CURLPROTO_HTTP : CURLPROTO_HTTPS]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) { $why = 'Discord answered ' . ($code ?: 'nothing') . '.'; return false; }
    return true;
}
// What changed between the catalog before and the one that just went out: new apps, and updates.
function rs_discord_changes(array $before, array $after): array {
    $list = fn($c) => array_merge($c['apps'] ?? [], $c['newerStoreApps'] ?? [], $c['v2Apps'] ?? []);
    $old = [];
    foreach ($list($before) as $a) if (is_array($a) && isset($a['id'])) $old[(string)$a['id']] = (string)($a['version'] ?? '');
    $out = [];
    foreach ($list($after) as $a) {
        if (!is_array($a) || !isset($a['id'])) continue;
        $id = (string)$a['id'];
        if (!array_key_exists($id, $old)) $out[] = ['kind' => 'new', 'app' => $a];
        elseif (($a['version'] ?? '') !== '' && $old[$id] !== (string)$a['version']) $out[] = ['kind' => 'update', 'app' => $a, 'from' => $old[$id]];
    }
    return $out;
}
function rs_discord_after_publish(array $before, array $after): void {
    try {
        if (!rs_discord_hook_ok(rs_discord_hook()) || !$before) return; // no post for the very first catalog
        $want = json_decode((string)rs_setting('discord_posts', '{"new":true,"update":true}'), true) ?: [];
        $embeds = [];
        foreach (rs_discord_changes($before, $after) as $ch) {
            if (empty($want[$ch['kind']])) continue;
            $a = $ch['app'];
            $e = ['title' => mb_substr(($ch['kind'] === 'new' ? 'New: ' : 'Updated: ') . (string)($a['name'] ?? $a['id']) . (isset($a['version']) && $a['version'] !== '' ? ' ' . $a['version'] : ''), 0, 250),
                  'description' => mb_substr((string)($ch['kind'] === 'new' ? ($a['blurb'] ?? '') : ($a['news'] ?? '')), 0, 400),
                  'url' => rs_abs('/game/' . rawurlencode((string)$a['id'])), 'color' => $ch['kind'] === 'new' ? 0x35D6F4 : 0x5FE3A1];
            if (!empty($a['icon'])) $e['thumbnail'] = ['url' => (string)$a['icon']];
            $embeds[] = $e;
            if (count($embeds) >= 10) break;
        }
        if (!$embeds) return;
        $ok = rs_discord_send(['username' => 'Recomp Store', 'embeds' => $embeds], $why);
        rs_log('discord', 'post', $ok ? count($embeds) . ' change' . (count($embeds) === 1 ? '' : 's') . ' posted' : 'not posted: ' . $why);
    } catch (Throwable $e) {
        // Never in the way of publishing.
    }
}
