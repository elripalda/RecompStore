<?php
// (4.10) Emergency backup. A copy of each tracked recomp's current release (the one that passed the
// safety check and went live) kept on Backblaze B2, next to the cloud saves. Only the latest is kept:
// a new update replaces it once the new copy is safely up. Nothing changes for players until a repo
// goes down and you switch that game to its backup (Submissions > Repo watch, or Emergency backup);
// then the catalog marks it "backup": true and the site and RecompLauncher download from
// /api/backup.php instead. Never offered for a takedown (DMCA or blocked).
// The copying runs in the background (inc/backup-run.php): started from the 30-minute job when there's
// work, or from Back up now.
declare(strict_types=1);
require_once __DIR__ . '/pc.php';
require_once __DIR__ . '/b2.php';

const RS_BK_OS = ['windows' => 'Windows', 'linux' => 'Linux', 'macos' => 'Mac'];
const RS_BK_RETRY = 6 * 3600;       // a failed copy is tried again after this
const RS_BK_BUDGET = 50 * 60;       // one background run, at most
const RS_BK_NO_TAKEDOWN = ['dmca', 'blocked'];
const RS_BK_TB_USD = 6.95;          // Backblaze B2, per TB a month (Nov 2026)

function rs_bk_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS pc_backup (
        app_id VARCHAR(100) NOT NULL PRIMARY KEY,
        tracked TINYINT NOT NULL DEFAULT 0,
        picked VARCHAR(6) NOT NULL DEFAULT \'hand\',
        os VARCHAR(40) NOT NULL DEFAULT \'\',
        status VARCHAR(10) NOT NULL DEFAULT \'none\',
        version VARCHAR(80) NOT NULL DEFAULT \'\',
        tag VARCHAR(160) NOT NULL DEFAULT \'\',
        files MEDIUMTEXT NULL,
        bytes BIGINT NOT NULL DEFAULT 0,
        backed_at DATETIME NULL,
        tried_at DATETIME NULL,
        err VARCHAR(300) NOT NULL DEFAULT \'\',
        active TINYINT NOT NULL DEFAULT 0,
        active_at DATETIME NULL,
        served INT NOT NULL DEFAULT 0
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// ---- Settings -----------------------------------------------------------------------------------------

function rs_bk_cfg(): array {
    $c = json_decode((string)rs_setting('bk_cfg', ''), true);
    $c = is_array($c) ? $c : [];
    $os = is_array($c['os'] ?? null) ? $c['os'] : [];
    return [
        'os' => array_map(fn($k) => !array_key_exists($k, $os) || !empty($os[$k]), array_combine(array_keys(RS_BK_OS), array_keys(RS_BK_OS))),
        'auto' => !array_key_exists('auto', $c) || !empty($c['auto']),            // keep tracked games on their newest update
        'pick' => in_array($c['pick'] ?? '', ['hand', 'top', 'all'], true) ? $c['pick'] : 'hand',
        'top' => max(10, min(1000, (int)($c['top'] ?? 100))),
        'max_mb' => max(100, min(8000, (int)($c['max_mb'] ?? 4000))),
    ];
}
function rs_bk_save_cfg(array $c): void { rs_set_setting('bk_cfg', (string)json_encode($c)); }

// ---- Rows ---------------------------------------------------------------------------------------------

function rs_bk_rows(): array {
    rs_bk_tables();
    $out = [];
    foreach (rs_db()->query('SELECT * FROM pc_backup') as $r) {
        $r['files'] = json_decode((string)$r['files'], true) ?: [];
        $out[(string)$r['app_id']] = $r;
    }
    return $out;
}
function rs_bk_row(string $id): ?array {
    rs_bk_tables();
    $st = rs_db()->prepare('SELECT * FROM pc_backup WHERE app_id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['files'] = json_decode((string)$r['files'], true) ?: [];
    return $r;
}
function rs_bk_upsert(string $id, array $set): void {
    rs_bk_tables();
    $cols = array_keys($set);
    $sql = 'INSERT INTO pc_backup (app_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ') ON DUPLICATE KEY UPDATE '
        . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));
    rs_db()->prepare($sql)->execute(array_merge([$id], array_values($set)));
}

// The games switched to their backup. -> [id => true] (cached for the catalog build)
function rs_bk_active_map(): array {
    static $m = null;
    if ($m !== null) return $m;
    $m = [];
    try {
        rs_bk_tables();
        foreach (rs_db()->query('SELECT app_id, version, files FROM pc_backup WHERE active = 1') as $r)
            $m[(string)$r['app_id']] = ['version' => (string)$r['version'], 'files' => json_decode((string)$r['files'], true) ?: []];
    } catch (Throwable $e) { /* no table yet */ }
    return $m;
}

// The systems kept for a game: its own pick, else the defaults.
function rs_bk_os_for(?array $row, array $cfg): array {
    $own = $row ? array_values(array_filter(explode(',', (string)$row['os']), fn($x) => isset(RS_BK_OS[$x]))) : [];
    return $own ?: array_keys(array_filter($cfg['os']));
}
// The systems a game has a download for.
function rs_bk_avail(array $a): array {
    return array_keys(array_filter(['windows' => $a['asset_win'] ?? '', 'linux' => $a['asset_linux'] ?? '', 'macos' => rs_pc_mac_ok((string)($a['asset_mac'] ?? ''))]));
}

function rs_bk_watch_state(string $id): string {
    try {
        require_once __DIR__ . '/repowatch.php';
        $w = rs_watch_row('pc', $id);
        return (string)($w['state'] ?? '');
    } catch (Throwable $e) { return ''; }
}

// Does this tracked game need a (new) copy? -> '' or why
function rs_bk_needs(array $a, ?array $row, array $cfg): string {
    if (!$row || !(int)$row['tracked'] || (int)$row['active']) return '';
    if ($row['status'] === 'queued') return 'asked';
    if ($row['status'] === 'error' && strtotime((string)$row['tried_at'] . ' UTC') > time() - RS_BK_RETRY) return '';
    $want = array_values(array_intersect(rs_bk_os_for($row, $cfg), rs_bk_avail($a)));
    if (!$want) return '';
    if ($row['status'] !== 'ok' && $row['status'] !== 'error') return 'new';
    if ($row['status'] === 'error') return 'retry';
    if (!$cfg['auto']) return '';
    if ((string)$row['version'] !== (string)$a['version']) return 'update';
    $have = array_values(array_unique(array_column($row['files'], 'os')));
    sort($have); sort($want);
    return $have !== $want ? 'systems' : '';
}

// ---- Choosing games automatically --------------------------------------------------------------------

// Most played / every listed game, per the settings. Games picked by hand are never touched.
function rs_bk_auto_pick(): int {
    $cfg = rs_bk_cfg();
    $rows = rs_bk_rows();
    $listed = [];
    foreach (rs_pc_apps(false) as $a) if (empty($a['soon']) && rs_bk_avail($a)) $listed[(string)$a['id']] = $a;
    $want = [];
    if ($cfg['pick'] === 'all') $want = array_keys($listed);
    elseif ($cfg['pick'] === 'top') {
        $pl = rs_pc_players_map();
        $ids = array_keys($listed);
        usort($ids, fn($x, $y) => ($pl[$y] ?? 0) <=> ($pl[$x] ?? 0) ?: strcmp($x, $y));
        $want = array_slice($ids, 0, $cfg['top']);
    }
    $want = array_flip($want);
    $n = 0;
    foreach ($want as $id => $_) {
        $r = $rows[$id] ?? null;
        if ($r && (int)$r['tracked']) continue;
        rs_bk_upsert((string)$id, ['tracked' => 1, 'picked' => 'auto', 'status' => $r && $r['status'] === 'ok' ? 'ok' : 'none']);
        $n++;
    }
    // Picked automatically and no longer in the list: stop updating it (its copy stays until removed).
    foreach ($rows as $id => $r) if ($r['picked'] === 'auto' && (int)$r['tracked'] && !isset($want[$id]))
        rs_db()->prepare('UPDATE pc_backup SET tracked = 0 WHERE app_id = ?')->execute([$id]);
    return $n;
}

// ---- Copying ------------------------------------------------------------------------------------------

function rs_bk_dir(): string {
    $d = dirname(RS_PUBLIC) . '/recompstore-private/backup';
    if (!is_dir($d)) @mkdir($d, 0750, true);
    return $d;
}
function rs_bk_key(string $id, string $tag, string $name): string {
    $t = preg_replace('/[^A-Za-z0-9._-]/', '_', $tag) ?: 'release';
    return 'backup/' . $id . '/' . $t . '/' . preg_replace('/[^A-Za-z0-9._+-]/', '_', $name);
}

// The release that's live for this game: the one the safety check let out, else the one with its version.
function rs_bk_release(array $a): ?array {
    $rel = rs_host_releases(rs_pc_host($a), (string)$a['repo'], 3600);
    if ((int)$rel['status'] !== 200) throw new RuntimeException('Its repo didn\'t answer (' . (int)$rel['status'] . ').');
    $list = array_values(array_filter(json_decode((string)$rel['body'], true) ?: [], fn($x) => is_array($x) && empty($x['draft'])));
    $ok = (string)($a['ok_tag'] ?? '');
    if ($ok !== '' && !str_starts_with($ok, 'moved:')) foreach ($list as $r) if ((string)$r['tag_name'] === $ok) return $r;
    foreach ($list as $r) if ($a['version'] !== '' && rs_tidy_version((string)$r['tag_name']) === (string)$a['version']) return $r;
    return rs_pc_pick_release($list);
}

function rs_bk_download(string $url, string $to, int $maxBytes): string {
    if (!preg_match('#^https://#', $url)) return 'no download link';
    $fh = @fopen($to, 'wb');
    if (!$fh) return 'can\'t write the temporary file';
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 8, CURLOPT_TIMEOUT => 3600, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_USERAGENT => 'RecompLauncher-Backup', CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => fn($ch, $dt, $d) => $d > $maxBytes ? 1 : 0]);
    $ok = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $got = (int)curl_getinfo($c, CURLINFO_SIZE_DOWNLOAD);
    curl_close($c);
    fclose($fh);
    if ($got > $maxBytes) { @unlink($to); return 'bigger than the ' . round($maxBytes / 1048576) . ' MB limit'; }
    if (!$ok || $code !== 200) { @unlink($to); return 'download failed (HTTP ' . $code . ')'; }
    return '';
}

// One game: its live release's files for the systems wanted, up to B2; the old copy goes once the new
// one is up. -> '' or what went wrong
function rs_bk_copy(array $a, array $cfg, ?callable $say = null): string {
    $id = (string)$a['id'];
    $row = rs_bk_row($id);
    rs_bk_upsert($id, ['status' => 'working', 'tried_at' => gmdate('Y-m-d H:i:s')]);
    try {
        if (in_array(rs_bk_watch_state($id), ['gone', 'private', 'norel', 'dmca', 'blocked'], true)) throw new RuntimeException('Its repo is down right now; the copy you have is kept.');
        $rel = rs_bk_release($a);
        if (!$rel) throw new RuntimeException('No release to copy.');
        $flags = [];
        $pick = rs_pc_assets((array)($rel['assets'] ?? []), $flags);
        $byName = [];
        foreach ((array)$rel['assets'] as $x) $byName[(string)$x['name']] = $x;
        $map = ['windows' => $pick['win'] ?? '', 'linux' => $pick['linux'] ?? '', 'macos' => rs_pc_mac_ok((string)($pick['mac'] ?? ''))];
        $want = array_intersect(rs_bk_os_for($row, $cfg), rs_bk_avail($a));
        $todo = [];
        foreach ($want as $os) if (($n = (string)$map[$os]) !== '' && isset($byName[$n])) $todo[$n][] = $os;
        if (!$todo) throw new RuntimeException('Its release has no file for the systems picked.');
        $tag = (string)$rel['tag_name'];
        $old = $row ? (array)$row['files'] : [];
        $oldBy = [];
        foreach ($old as $f) $oldBy[(string)$f['key']] = $f;
        $files = []; $bytes = 0;
        foreach ($todo as $name => $oses) {
            $key = rs_bk_key($id, $tag, $name);
            if (isset($oldBy[$key])) { // already up from an earlier run (same release)
                $f = $oldBy[$key]; $f['os'] = $oses[0]; $f['oses'] = $oses;
                $files[] = $f; $bytes += (int)$f['size'];
                continue;
            }
            $tmp = rs_bk_dir() . '/' . $id . '-' . getmypid() . '.part';
            if ($say) $say("$id: downloading $name");
            $e = rs_bk_download((string)($byName[$name]['browser_download_url'] ?? ''), $tmp, $cfg['max_mb'] * 1048576);
            if ($e !== '') throw new RuntimeException($name . ': ' . $e);
            $size = (int)filesize($tmp);
            $sha = (string)hash_file('sha256', $tmp);
            try {
                if ($say) $say("$id: uploading $name (" . round($size / 1048576, 1) . ' MB)');
                rs_b2_put_big($key, $tmp);
            } finally { @unlink($tmp); }
            $files[] = ['os' => $oses[0], 'oses' => $oses, 'name' => $name, 'size' => $size, 'sha256' => $sha, 'key' => $key];
            $bytes += $size;
        }
        // One entry per system, so the launcher can pick its own.
        $out = [];
        foreach ($files as $f) foreach ($f['oses'] as $os) { $g = $f; $g['os'] = $os; unset($g['oses']); $out[] = $g; }
        $keep = array_flip(array_column($out, 'key'));
        foreach ($old as $f) if (!isset($keep[(string)$f['key']])) rs_b2_delete((string)$f['key']);
        rs_bk_upsert($id, ['status' => 'ok', 'version' => (string)($a['version'] ?: rs_tidy_version($tag)), 'tag' => $tag, 'files' => json_encode($out, JSON_UNESCAPED_SLASHES),
            'bytes' => $bytes, 'backed_at' => gmdate('Y-m-d H:i:s'), 'err' => '']);
        return '';
    } catch (Throwable $e) {
        // Whatever was there before stays usable.
        rs_bk_upsert($id, ['status' => 'error', 'err' => mb_substr($e->getMessage(), 0, 290)]);
        return $e->getMessage();
    }
}

// What's waiting. -> [[app, why], ...] most played first
function rs_bk_queue(): array {
    $cfg = rs_bk_cfg();
    $rows = rs_bk_rows();
    $pl = rs_pc_players_map();
    $q = [];
    foreach (rs_pc_apps(true) as $a) {
        $why = rs_bk_needs($a, $rows[(string)$a['id']] ?? null, $cfg);
        if ($why !== '') $q[] = [$a, $why];
    }
    usort($q, fn($x, $y) => [$x[1] === 'asked' ? 0 : 1, -($pl[$x[0]['id']] ?? 0)] <=> [$y[1] === 'asked' ? 0 : 1, -($pl[$y[0]['id']] ?? 0)]);
    return $q;
}

// A background run: through the queue until it's empty or the time's up. -> summary
function rs_bk_run(?callable $say = null): array {
    $t0 = time();
    $cfg = rs_bk_cfg();
    if (!rs_b2_on()) return ['done' => 0, 'failed' => 0, 'left' => 0, 'err' => 'Backblaze isn\'t set up (Settings > Cloud saves).'];
    rs_bk_auto_pick();
    // A run that was cut off leaves "working" rows: they go back in the line.
    rs_db()->exec("UPDATE pc_backup SET status = 'queued' WHERE status = 'working' AND tried_at < (UTC_TIMESTAMP() - INTERVAL 2 HOUR)");
    $done = $failed = 0;
    $q = rs_bk_queue();
    foreach ($q as $i => [$a, $why]) {
        if (time() - $t0 > RS_BK_BUDGET) return ['done' => $done, 'failed' => $failed, 'left' => count($q) - $i, 'err' => ''];
        $e = rs_bk_copy($a, $cfg, $say);
        if ($e === '') { $done++; if ($say) $say($a['id'] . ': backed up (' . $why . ')'); }
        else { $failed++; if ($say) $say($a['id'] . ': ' . $e); }
        rs_kv_put('bk_running', '1');
    }
    return ['done' => $done, 'failed' => $failed, 'left' => 0, 'err' => ''];
}

function rs_bk_running(): bool { $c = rs_kv_get('bk_running'); return $c !== null && $c['v'] === '1' && $c['age'] < 1800; }
function rs_bk_last(): array { $c = rs_kv_get('bk_last'); $j = $c ? json_decode($c['v'], true) : null; return is_array($j) ? $j : []; }

// Starts a run in the background. -> false when one is going
function rs_bk_launch(): bool {
    if (rs_bk_running()) return false;
    rs_kv_put('bk_running', '1');
    $p = proc_open('setsid php ' . escapeshellarg(__DIR__ . '/backup-run.php') . ' >> ' . escapeshellarg(rs_bk_dir() . '/run.log') . ' 2>&1 &',
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, rs_bk_dir(),
        ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => rs_bk_dir(), 'LANG' => 'C.UTF-8']);
    if (!is_resource($p)) { rs_kv_put('bk_running', '0'); return false; }
    proc_close($p);
    return true;
}

// From the 30-minute job: pick games, and start a run when there's something to copy.
function rs_bk_maybe(): string {
    if (!rs_b2_on()) return '';
    rs_bk_tables();
    $picked = rs_bk_auto_pick();
    if (rs_bk_running()) return 'running';
    $n = count(rs_bk_queue());
    if ($n && rs_bk_launch()) return 'started (' . $n . ' waiting' . ($picked ? ', ' . $picked . ' newly picked' : '') . ')';
    return '';
}

// ---- Choices from the page ------------------------------------------------------------------------

// Tick games: track them (with these systems, or the defaults) and copy them next run.
function rs_bk_track(array $ids, array $os): int {
    $os = array_values(array_filter($os, fn($x) => isset(RS_BK_OS[$x])));
    $all = array_keys(RS_BK_OS);
    $osStr = $os && count($os) < count($all) ? implode(',', $os) : '';
    $n = 0;
    foreach ($ids as $id) {
        $id = (string)$id;
        if (!rs_pc_app($id)) continue;
        $r = rs_bk_row($id);
        rs_bk_upsert($id, ['tracked' => 1, 'picked' => 'hand', 'os' => $osStr, 'status' => $r && (int)$r['active'] ? $r['status'] : 'queued', 'err' => '']);
        $n++;
    }
    return $n;
}
function rs_bk_untrack(array $ids): int {
    $n = 0;
    foreach ($ids as $id) { rs_bk_tables(); $st = rs_db()->prepare("UPDATE pc_backup SET tracked = 0, picked = 'hand', status = IF(status IN ('queued', 'working'), IF(files IS NULL OR files = '[]', 'none', 'ok'), status) WHERE app_id = ?"); $st->execute([(string)$id]); $n += $st->rowCount(); }
    return $n;
}
// Deletes the copies (not for a game switched to its backup). -> how many removed
function rs_bk_remove(array $ids): int {
    $n = 0;
    foreach ($ids as $id) {
        $r = rs_bk_row((string)$id);
        if (!$r || (int)$r['active']) continue;
        foreach ((array)$r['files'] as $f) rs_b2_delete((string)$f['key']);
        rs_db()->prepare("UPDATE pc_backup SET tracked = 0, picked = 'hand', status = 'none', files = NULL, bytes = 0, version = '', tag = '', backed_at = NULL, err = '' WHERE app_id = ?")->execute([(string)$id]);
        $n++;
    }
    return $n;
}

// Switch a game to its backup (its repo is down). -> message
function rs_bk_activate(string $id): string {
    $a = rs_pc_app($id);
    $r = rs_bk_row($id);
    if (!$a) throw new RuntimeException('That game isn\'t listed.');
    if (!$r || !$r['files']) throw new RuntimeException($a['name'] . ' has no backup yet.');
    if (in_array(rs_bk_watch_state($id), RS_BK_NO_TAKEDOWN, true)) throw new RuntimeException($a['name'] . ' was taken down, so the backup can\'t be used for it.');
    rs_db()->prepare('UPDATE pc_backup SET active = 1, active_at = UTC_TIMESTAMP() WHERE app_id = ?')->execute([$id]);
    $note = '';
    if (!empty($a['hidden']) && (string)($a['watch_hid'] ?? '') !== '') { // hidden by the repo watch: back on the store
        $a['hidden'] = false;
        $a['watch_hid'] = '';
        rs_pc_save($a);
        $note = ' It\'s back on the store.';
    }
    try { require_once __DIR__ . '/repowatch.php'; rs_db()->prepare('UPDATE repo_watch SET ack = state WHERE store = ? AND app_id = ?')->execute(['pc', $id]); rs_watch_pc_out(); }
    catch (Throwable $e) { rs_pc_write_catalog(); }
    rs_log('backup', 'pc:' . $id, 'switched to emergency backup (' . $r['version'] . ')');
    return $a['name'] . ' now downloads from its emergency backup (' . ($r['version'] ?: $r['tag']) . ').' . $note;
}
function rs_bk_deactivate(string $id): string {
    $a = rs_pc_app($id);
    rs_bk_tables();
    rs_db()->prepare('UPDATE pc_backup SET active = 0 WHERE app_id = ?')->execute([$id]);
    try { require_once __DIR__ . '/repowatch.php'; rs_watch_pc_out(); } catch (Throwable $e) { rs_pc_write_catalog(); }
    rs_log('backup', 'pc:' . $id, 'back to its repo');
    return ($a['name'] ?? $id) . ' downloads from its repo again.';
}

// For the catalog: a game on its backup shows the backup's version and files.
function rs_bk_catalog_entry(array $e): array {
    $m = rs_bk_active_map()[(string)$e['id']] ?? null;
    if (!$m || !$m['files']) return $e;
    $e['backup'] = true;
    $as = [];
    foreach ($m['files'] as $f) $as[(string)$f['os']] = (string)$f['name'];
    $e['assets'] = $as;
    $e['systems'] = array_keys($as);
    if ($m['version'] !== '') $e['version'] = $m['version'];
    return $e;
}

function rs_bk_totals(): array {
    rs_bk_tables();
    $r = rs_db()->query("SELECT SUM(tracked) t, SUM(status = 'ok' OR (files IS NOT NULL AND files <> '[]')) b, SUM(bytes) s, SUM(active) a, SUM(status = 'error') e FROM pc_backup")->fetch(PDO::FETCH_ASSOC);
    return ['tracked' => (int)$r['t'], 'backed' => (int)$r['b'], 'bytes' => (int)$r['s'], 'active' => (int)$r['a'], 'errors' => (int)$r['e']];
}
