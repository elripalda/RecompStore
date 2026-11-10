<?php
// (3.8) Security: every download the stores point at is checked with VirusTotal.
//
// What gets checked, on its own:
//  - a submission's release files, as soon as it arrives;
//  - each PC recomp's and PS5 app's newest release files;
//  - anything pasted into "Check a link" on the Security page.
// A PC recomp's new release from a developer who isn't verified is held: the site and the
// launcher keep offering the version before it until the new files are checked and the hold
// time has passed. Several engines flagging a file holds it until the admin approves it.
//
// VirusTotal's free key allows 4 requests a minute and 500 a day; everything here waits its
// turn. Files are found by their SHA-256 (GitHub publishes it for each release file), and only
// files VirusTotal has never seen are downloaded and sent to it.
declare(strict_types=1);

const RS_VT_API = 'https://www.virustotal.com/api/v3';
const RS_VT_PER_MIN = 4;
const RS_VT_PER_DAY = 480;           // a little under the free key's 500
const RS_VT_KEEP_FOR_NEW = 100;      // (3.8) of those, kept for submissions and "Check a link": updates stop before
const RS_VT_GREY_HOURS = 12;         // (3.8) a held update the line couldn't reach in this long goes out anyway
const RS_VT_BACKFILL_UPLOADS = 60;   // a day, for files already in the stores (new ones go first)
const RS_VT_MAX_BYTES = 650 << 20;   // VirusTotal's largest upload
const RS_VT_FILE_RE = '/\.(zip|7z|rar|exe|msi|appimage|dmg|deb|rpm|tar\.gz|tgz|tar\.xz|tar\.zst|pkg|elf|bin)$/i';

function rs_vt_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS vt_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        repo VARCHAR(160) NOT NULL DEFAULT \'\',
        tag VARCHAR(120) NOT NULL DEFAULT \'\',
        name VARCHAR(255) NOT NULL,
        url VARCHAR(1000) NOT NULL DEFAULT \'\',
        size BIGINT NOT NULL DEFAULT 0,
        sha256 CHAR(64) NULL,
        source VARCHAR(16) NOT NULL DEFAULT \'store\',
        ref VARCHAR(80) NOT NULL DEFAULT \'\',
        prio TINYINT NOT NULL DEFAULT 5,
        status VARCHAR(16) NOT NULL DEFAULT \'new\',
        analysis VARCHAR(120) NOT NULL DEFAULT \'\',
        malicious SMALLINT NOT NULL DEFAULT 0,
        suspicious SMALLINT NOT NULL DEFAULT 0,
        engines SMALLINT NOT NULL DEFAULT 0,
        flagged_by VARCHAR(400) NOT NULL DEFAULT \'\',
        err VARCHAR(255) NOT NULL DEFAULT \'\',
        tries TINYINT NOT NULL DEFAULT 0,
        seen TINYINT NOT NULL DEFAULT 0,
        next_at DATETIME NULL,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        UNIQUE KEY one (repo, tag, name),
        KEY status (status, prio, created),
        KEY sha (sha256)
    ) CHARACTER SET utf8mb4');
    // (3.8) Who answered: VirusTotal, or ClamAV on this server.
    try {
        if (!rs_db()->query("SHOW COLUMNS FROM vt_files LIKE 'scanner'")->fetch()) rs_db()->exec("ALTER TABLE vt_files ADD COLUMN scanner VARCHAR(12) NOT NULL DEFAULT 'vt'");
    } catch (Throwable $e) { /* next time */ }
    $done = true;
}

// ---- (3.8) ClamAV on this server ------------------------------------------------------------------
// Updates and GitHub finds are looked up on VirusTotal by their SHA-256 (one request). When
// VirusTotal doesn't know the file, or the day's requests are used up, ClamAV checks it here instead
// of uploading it: no limit. Submissions and "Check a link" get the full VirusTotal scan while the
// day allows it; with "ClamAV for submissions too" on, ClamAV takes them once the day's requests run out.
function rs_clam_bin(): string {
    foreach (['/usr/bin/clamdscan', '/usr/local/bin/clamdscan'] as $b) if (is_file($b) && is_executable($b)) return $b;
    return '';
}
function rs_clam_on(): bool {
    static $on = null;
    if ($on !== null) return $on;
    if (rs_clam_bin() === '' || !function_exists('proc_open')) return $on = false;
    return $on = is_file('/run/clamav/clamd.ctl') || is_file('/var/run/clamav/clamd.ctl') || file_exists('/run/clamav/clamd.ctl');
}
// -> ['ok' => bool, 'found' => '' or the signature, 'err' => '']
function rs_clam_scan(string $file): array {
    $bin = rs_clam_bin();
    if ($bin === '') return ['ok' => false, 'found' => '', 'err' => 'ClamAV isn\'t installed.'];
    $p = proc_open([$bin, '--no-summary', '--fdpass', '--infected', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) return ['ok' => false, 'found' => '', 'err' => 'ClamAV couldn\'t start.'];
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    if ($code === 0) return ['ok' => true, 'found' => '', 'err' => ''];
    // Only a named find counts. A bare exit 1 with no FOUND line is treated as a failed check and tried again.
    if ($code === 1 && preg_match('/:\s*(.+?)\s+FOUND/', (string)$out, $m)) return ['ok' => true, 'found' => mb_substr($m[1], 0, 200), 'err' => ''];
    if ($code === 1) return ['ok' => false, 'found' => '', 'err' => mb_substr('ClamAV gave no answer: ' . trim((string)$out . ' ' . (string)$err), 0, 200)];
    return ['ok' => false, 'found' => '', 'err' => mb_substr(trim((string)$err) ?: 'ClamAV error ' . $code, 0, 200)];
}
// ClamAV's answer saved like VirusTotal's: a find holds the update for you (red).
function rs_clam_check(array $f, string $tmp): bool {
    $id = (int)$f['id'];
    if (!is_file($tmp)) {
        $why = rs_vt_fetch($f, $tmp);
        if ($why === 'big') { rs_vt_save($id, ['status' => 'big']); return true; }
        if ($why !== '') { rs_vt_save($id, ['tries' => (int)$f['tries'] + 1, 'err' => $why, 'status' => (int)$f['tries'] >= 3 ? 'error' : 'new']); return true; }
    }
    $r = rs_clam_scan($tmp);
    @unlink($tmp);
    if (!$r['ok']) { rs_vt_save($id, ['tries' => (int)$f['tries'] + 1, 'err' => $r['err'], 'next_at' => gmdate('Y-m-d H:i:s', time() + 600)]); return true; }
    $red = rs_vt_cfg()['red_at'];
    rs_vt_save($id, ['status' => 'done', 'scanner' => 'clamav', 'err' => '', 'engines' => 1, 'suspicious' => 0,
        'malicious' => $r['found'] !== '' ? $red : 0, 'flagged_by' => $r['found'] !== '' ? 'ClamAV: ' . $r['found'] : '']);
    return true;
}

// ---- Settings -----------------------------------------------------------------------------------

function rs_vt_key(): string { return function_exists('rs_secret_get') ? rs_secret_get('vt_key') : ''; }
function rs_vt_on(): bool { return rs_vt_key() !== ''; }
function rs_vt_hold_mins(string $key, string $oldKey, int $oldDefault): int {
    $m = rs_setting($key, '');
    return max(0, min(10080, $m !== '' ? (int)$m : (int)rs_setting($oldKey, (string)$oldDefault) * 60));
}
// (4.1) 90 -> "1 h 30 min", 30 -> "30 min", 120 -> "2 h"
function rs_vt_mins_text(int $m): string {
    if ($m < 60) return $m . ' min';
    return intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' min' : '');
}
function rs_vt_cfg(): array {
    return [
        // (4.1) Hold times in minutes (they were whole hours; the old settings still count until saved again).
        'hold' => rs_vt_hold_mins('vt_hold_mins', 'vt_hold_hours', 12),
        'hold_verified' => rs_vt_hold_mins('vt_hold_verified_mins', 'vt_hold_verified', 0),
        'red_at' => max(1, min(20, (int)rs_setting('vt_red_at', '3'))),
        'auto' => rs_setting('vt_auto', '1') === '1',
        'clam_subs' => rs_setting('vt_clam_subs', '1') === '1',
    ];
}

// green (nothing found), yellow (1 or 2 engines, usually an unsigned program), red (held for you),
// grey (waiting, too big, or not checked).
function rs_vt_verdict(array $f): string {
    if (($f['status'] ?? '') !== 'done') return 'grey';
    $c = rs_vt_cfg();
    if ((int)$f['malicious'] >= $c['red_at']) return 'red';
    if ((int)$f['malicious'] + (int)$f['suspicious'] > 0) return 'yellow';
    return 'green';
}
function rs_vt_link(array $f): string { return !empty($f['sha256']) ? 'https://www.virustotal.com/gui/file/' . $f['sha256'] : ''; }

// ---- What VirusTotal allows -----------------------------------------------------------------------

function rs_vt_budget(bool $take = false, bool $upload = false, bool $backfill = false, bool $low = false): bool {
    $c = rs_kv_get('vt_calls');
    $now = time();
    $x = $c ? (json_decode($c['v'], true) ?: []) : [];
    $min = array_values(array_filter((array)($x['min'] ?? []), fn($t) => $now - (int)$t < 62));
    $day = (string)($x['day'] ?? '') === gmdate('Y-m-d') ? (int)($x['n'] ?? 0) : 0;
    $up = (string)($x['day'] ?? '') === gmdate('Y-m-d') ? (int)($x['up'] ?? 0) : 0;
    $hold = (int)($x['hold'] ?? 0);
    if ($hold > $now) return false; // VirusTotal said "too many" a moment ago
    if (count($min) >= RS_VT_PER_MIN || $day >= RS_VT_PER_DAY) return false;
    if ($low && $day >= RS_VT_PER_DAY - RS_VT_KEEP_FOR_NEW) return false;
    if ($backfill && $upload && $up >= RS_VT_BACKFILL_UPLOADS) return false;
    if ($take) {
        $min[] = $now;
        rs_kv_put('vt_calls', (string)json_encode(['min' => $min, 'day' => gmdate('Y-m-d'), 'n' => $day + 1, 'up' => $up + ($upload ? 1 : 0), 'hold' => $hold]));
    }
    return true;
}
function rs_vt_backoff(int $sec): void {
    $c = rs_kv_get('vt_calls');
    $x = $c ? (json_decode($c['v'], true) ?: []) : [];
    $x['hold'] = time() + $sec;
    rs_kv_put('vt_calls', (string)json_encode($x));
}
function rs_vt_today(): int {
    $c = rs_kv_get('vt_calls');
    $x = $c ? (json_decode($c['v'], true) ?: []) : [];
    return (string)($x['day'] ?? '') === gmdate('Y-m-d') ? (int)($x['n'] ?? 0) : 0;
}

// One call to VirusTotal. -> [status, json]
function rs_vt_call(string $method, string $url, ?array $post = null): array {
    $key = rs_vt_key();
    $ch = curl_init(str_starts_with($url, 'http') ? $url : RS_VT_API . $url);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 15,
          CURLOPT_HTTPHEADER => ['x-apikey: ' . $key, 'accept: application/json'], CURLOPT_CUSTOMREQUEST => $method];
    if ($post !== null) $o[CURLOPT_POSTFIELDS] = $post;
    curl_setopt_array($ch, $o);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code === 429) rs_vt_backoff(90);
    return [$code, is_string($body) ? (json_decode($body, true) ?: []) : []];
}

// Is the key right? -> '' or what went wrong.
function rs_vt_test(): string {
    if (!rs_vt_on()) return 'Paste the key first.';
    [$code, $j] = rs_vt_call('GET', '/files/275a021bbfb6489e54d471899f7db9d1663fc695ec2fe2a2c4538aabf651fd0f'); // the EICAR test file
    if ($code === 200) return '';
    if ($code === 401 || $code === 403) return 'VirusTotal turned the key down. Copy it again from your VirusTotal profile, API key.';
    return 'VirusTotal answered HTTP ' . $code . ($code === 0 ? ' (it couldn\'t be reached)' : '') . '.';
}

// ---- Adding files to check ------------------------------------------------------------------------

// prio: 1 a link you pasted, 2 a submission, 3 a new release (update), 5 already in a store.
function rs_vt_add(string $repo, string $tag, array $asset, string $source, string $ref, int $prio): void {
    rs_vt_tables();
    $name = (string)($asset['name'] ?? '');
    if ($name === '') return;
    $sha = '';
    if (preg_match('/^sha256:([0-9a-f]{64})$/i', (string)($asset['digest'] ?? ''), $m)) $sha = strtolower($m[1]);
    $db = rs_db();
    $st = $db->prepare('SELECT id, prio, source FROM vt_files WHERE repo = ? AND tag = ? AND name = ?');
    $st->execute([$repo, $tag, $name]);
    if ($row = $st->fetch()) {
        if ($prio < (int)$row['prio']) $db->prepare('UPDATE vt_files SET prio = ?, source = ?, ref = ?, updated = NOW() WHERE id = ?')->execute([$prio, $source, $ref, $row['id']]);
        return;
    }
    // The same file checked before (another tag, another listing): its answer is copied.
    $done = null;
    if ($sha !== '') {
        $q = $db->prepare("SELECT * FROM vt_files WHERE sha256 = ? AND status = 'done' ORDER BY updated DESC LIMIT 1");
        $q->execute([$sha]);
        $done = $q->fetch() ?: null;
    }
    $db->prepare('INSERT INTO vt_files (repo, tag, name, url, size, sha256, source, ref, prio, status, malicious, suspicious, engines, flagged_by, created, updated)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute([
        $repo, $tag, mb_substr($name, 0, 255), mb_substr((string)($asset['browser_download_url'] ?? $asset['url'] ?? ''), 0, 1000), (int)($asset['size'] ?? 0),
        $sha !== '' ? $sha : null, $source, mb_substr($ref, 0, 80), $prio, $done ? 'done' : 'new',
        $done ? (int)$done['malicious'] : 0, $done ? (int)$done['suspicious'] : 0, $done ? (int)$done['engines'] : 0, $done ? (string)$done['flagged_by'] : '']);
}

// (3.8) The one file worth checking for a recomp's release: its Windows download when it has one
// (where nearly everyone plays), else Linux, else macOS. The free key's day can't cover every system.
function rs_vt_main_patterns(array $patterns): array {
    foreach (['windows', 'win', 'asset_win', 'linux', 'asset_linux', 'macos', 'mac', 'asset_mac'] as $k)
        if (isset($patterns[$k]) && trim((string)$patterns[$k]) !== '') return [(string)$patterns[$k]];
    $list = array_values(array_filter(array_map('strval', $patterns), fn($p) => trim($p) !== ''));
    return $list ? [$list[0]] : [];
}

// The release files a listing points at: matching its patterns, else every download-looking file.
function rs_vt_pick(array $assets, array $patterns): array {
    $out = [];
    foreach ($assets as $a) {
        $n = (string)($a['name'] ?? '');
        if ($n === '' || preg_match('/(source|src|symbols|debug|sha256|\.sig$|\.txt$|\.json$|\.md$)/i', $n)) continue;
        if ($patterns) { foreach ($patterns as $p) if ($p !== '' && rs_pattern_matches($p, $n)) { $out[] = $a; break; } }
        elseif (preg_match(RS_VT_FILE_RE, $n)) $out[] = $a;
    }
    return $out;
}

// What GitHub said last about a repo's releases, without asking it again (the 30-minute refresh keeps it fresh).
// (3.9) $repo is a check key: 'gitlab.com/<project>' reads GitLab's copy.
function rs_vt_peek_releases(string $repo): array {
    $k = strtolower($repo);
    if (str_starts_with($k, 'gitlab.com/')) $k = 'gl:' . substr($k, 11);
    elseif (str_starts_with($k, 'sourceforge.net/')) $k = 'sf:' . substr($k, 16); // (4.3)
    $st = rs_db()->prepare('SELECT body FROM gh_cache WHERE k = ? AND status = 200');
    $st->execute([$k]);
    $b = $st->fetchColumn();
    return $b ? (json_decode((string)$b, true) ?: []) : [];
}
// (3.9) A project's releases on GitHub or GitLab, as a list (empty when it couldn't be read).
function rs_vt_list(string $host, string $repo, int $maxAge = 3600): array {
    if (!rs_host_repo_ok($host, $repo)) return [];
    $rel = rs_host_releases($host, $repo, $maxAge);
    return $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
}
function rs_vt_newest(array $list, bool $pre = true): ?array {
    foreach ($list as $r) if (empty($r['draft']) && ($pre || empty($r['prerelease']))) return $r;
    return null;
}

// Looks for anything new to check: submissions, store listings, new releases. At most every 10 minutes.
function rs_vt_discover(bool $force = false): void {
    if (!$force) {
        $k = rs_kv_get('vt_discover');
        if ($k && $k['age'] < 600) return;
    }
    rs_kv_put('vt_discover', '1');
    rs_vt_tables();
    $db = rs_db();
    // Submissions waiting for you (opened or not: opening the Inbox marks them read).
    foreach ($db->query("SELECT id, repo, data FROM inbox WHERE kind = 'submit' AND status IN ('new', 'read') AND created > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchAll() as $s) {
        $d = json_decode((string)$s['data'], true) ?: [];
        $repo = (string)($d['repo'] ?? '');
        $host = rs_host_norm((string)($d['host'] ?? ''));
        if (!rs_host_repo_ok($host, $repo)) continue;
        $r = rs_vt_newest(rs_vt_list($host, $repo));
        if (!$r) continue;
        $pats = ($d['platform'] ?? '') === 'pc' ? rs_vt_main_patterns((array)($d['assets'] ?? [])) : array_filter([(string)($d['asset'] ?? '')]);
        foreach (rs_vt_pick((array)$r['assets'], $pats) as $a) rs_vt_add(rs_repo_key($host, $repo), (string)$r['tag_name'], $a, 'submission', 'm' . $s['id'], 2);
    }
    // (3.8) Only what's new: a PC recomp's held update. What's already in the stores isn't checked
    // in bulk; "Check again" or "Check a link" checks any one of them.
    foreach (rs_pc_apps() as $a) {
        $tag = (string)($a['pending']['tag'] ?? '');
        if ($a['repo'] === '' || $tag === '') continue;
        foreach (rs_vt_peek_releases(rs_pc_key($a)) as $r) {
            if ((string)$r['tag_name'] !== $tag) continue;
            foreach (rs_vt_pick((array)$r['assets'], rs_vt_main_patterns(['asset_win' => $a['asset_win'], 'asset_linux' => $a['asset_linux'], 'asset_mac' => $a['asset_mac']])) as $f) rs_vt_add(rs_pc_key($a), $tag, $f, 'update', 'pc:' . $a['id'], 3);
        }
    }
    rs_vt_promote();
}

// ---- Checking -----------------------------------------------------------------------------------

function rs_vt_tmp(int $id): string { return sys_get_temp_dir() . '/rs-vt-' . $id . '.bin'; }

// Downloads a file to check it. -> '' or the reason it couldn't. Any https link (or http) is fetched:
// GitHub's release files, (3.9) a GitLab project's own release links, and links pasted by hand.
function rs_vt_fetch(array $f, string $to): string {
    if ((string)$f['url'] === '') return 'No download link.';
    if (!preg_match('#^https?://#i', (string)$f['url'])) return 'Not a web link.';
    $fp = fopen($to, 'wb');
    $ch = curl_init((string)$f['url']);
    $got = 0;
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 6, CURLOPT_TIMEOUT => 900, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        CURLOPT_USERAGENT => 'RecompStore-security', CURLOPT_NOPROGRESS => false,
        CURLOPT_XFERINFOFUNCTION => function ($c, $dlTotal, $dl) use (&$got) { $got = $dl; return $dl > RS_VT_MAX_BYTES ? 1 : 0; }]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);
    if ($got > RS_VT_MAX_BYTES) { @unlink($to); return 'big'; }
    if (!$ok || $code >= 400) { @unlink($to); return 'The download didn\'t work (HTTP ' . $code . ').'; }
    return '';
}

function rs_vt_save(int $id, array $set): void {
    $set['updated'] = gmdate('Y-m-d H:i:s');
    $cols = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
    rs_db()->prepare("UPDATE vt_files SET $cols WHERE id = ?")->execute(array_merge(array_values($set), [$id]));
}

function rs_vt_results(array $attr, string $statsKey): array {
    $s = (array)($attr[$statsKey] ?? []);
    $names = [];
    foreach ((array)($attr[$statsKey === 'stats' ? 'results' : 'last_analysis_results'] ?? []) as $eng => $r)
        if (in_array($r['category'] ?? '', ['malicious', 'suspicious'], true)) $names[] = $eng . ($r['result'] ? ': ' . $r['result'] : '');
    $total = 0;
    foreach ($s as $v) $total += (int)$v;
    return ['malicious' => (int)($s['malicious'] ?? 0), 'suspicious' => (int)($s['suspicious'] ?? 0), 'engines' => $total,
            'flagged_by' => mb_substr(implode(' · ', array_slice($names, 0, 8)), 0, 400)];
}

// One step for one file. -> false when VirusTotal can't take more right now.
function rs_vt_step(array $f): bool {
    $id = (int)$f['id'];
    $backfill = (int)$f['prio'] >= 5;
    $low = (int)$f['prio'] >= 3; // updates and finds: they leave room in the day for submissions
    $tmp = rs_vt_tmp($id);
    if ($f['status'] === 'new') {
        if (empty($f['sha256'])) {
            if ((int)$f['size'] > RS_VT_MAX_BYTES) { rs_vt_save($id, ['status' => 'big']); return true; }
            $why = rs_vt_fetch($f, $tmp);
            if ($why === 'big') { rs_vt_save($id, ['status' => 'big']); return true; }
            if ($why !== '') { rs_vt_save($id, ['tries' => (int)$f['tries'] + 1, 'err' => $why, 'status' => (int)$f['tries'] >= 3 ? 'error' : 'new']); return true; }
            $f['sha256'] = hash_file('sha256', $tmp);
            rs_vt_save($id, ['sha256' => $f['sha256'], 'size' => (int)filesize($tmp)]);
        }
        $sub = !$low && rs_vt_cfg()['clam_subs'] && rs_clam_on(); // a submission: ClamAV only when VirusTotal is out for the day
        $clam = $low && rs_clam_on();
        if (!rs_vt_budget(true, false, false, $low)) return ($clam || $sub) ? rs_clam_check($f, $tmp) : false; // the day's requests are gone: ClamAV
        [$code, $j] = rs_vt_call('GET', '/files/' . $f['sha256']);
        if ($code === 200) {
            rs_vt_save($id, ['status' => 'done', 'scanner' => 'vt', 'err' => ''] + rs_vt_results((array)($j['data']['attributes'] ?? []), 'last_analysis_stats'));
            @unlink($tmp);
            return true;
        }
        if ($code === 404 && $clam) return rs_clam_check($f, $tmp); // new to VirusTotal: ClamAV here instead of an upload
        if ($code === 404 && $sub && !rs_vt_budget(false, false, false, false)) return rs_clam_check($f, $tmp); // no room left to upload it today
        if ($code === 404) { rs_vt_save($id, ['status' => (int)$f['size'] > RS_VT_MAX_BYTES ? 'big' : 'upload']); return true; }
        if ($code === 429) return false;
        rs_vt_save($id, ['tries' => (int)$f['tries'] + 1, 'err' => 'VirusTotal answered HTTP ' . $code, 'status' => (int)$f['tries'] >= 3 ? 'error' : 'new']);
        return true;
    }
    if ($f['status'] === 'upload') {
        if (!rs_vt_budget(false, true, $backfill, $low)) {
            $sub = !$low && rs_vt_cfg()['clam_subs'] && rs_clam_on();
            return $sub ? rs_clam_check($f, $tmp) : false; // waiting for an upload VirusTotal can't take today
        }
        if (!is_file($tmp)) {
            $why = rs_vt_fetch($f, $tmp);
            if ($why === 'big') { rs_vt_save($id, ['status' => 'big']); return true; }
            if ($why !== '') { rs_vt_save($id, ['tries' => (int)$f['tries'] + 1, 'err' => $why, 'status' => (int)$f['tries'] >= 3 ? 'error' : 'upload']); return true; }
        }
        $size = (int)filesize($tmp);
        $url = '/files';
        if ($size > (32 << 20)) {
            if (!rs_vt_budget(true, false, $backfill, $low)) return false;
            [$c, $u] = rs_vt_call('GET', '/files/upload_url');
            if ($c !== 200 || empty($u['data'])) { rs_vt_save($id, ['err' => 'No upload link from VirusTotal (HTTP ' . $c . ')', 'tries' => (int)$f['tries'] + 1]); return $c !== 429; }
            $url = (string)$u['data'];
        }
        if (!rs_vt_budget(true, true, $backfill, $low)) return false;
        [$code, $j] = rs_vt_call('POST', $url, ['file' => new CURLFile($tmp, 'application/octet-stream', (string)$f['name'])]);
        if ($code === 200 && !empty($j['data']['id'])) {
            rs_vt_save($id, ['status' => 'analyzing', 'analysis' => (string)$j['data']['id'], 'next_at' => gmdate('Y-m-d H:i:s', time() + 150), 'err' => '']); // most are done in two or three minutes
            @unlink($tmp);
            return true;
        }
        rs_vt_save($id, ['tries' => (int)$f['tries'] + 1, 'err' => 'The upload didn\'t go through (HTTP ' . $code . ')', 'status' => (int)$f['tries'] >= 3 ? 'error' : 'upload']);
        return $code !== 429;
    }
    if ($f['status'] === 'analyzing') {
        if (!rs_vt_budget(true, false, false, $low)) return false;
        [$code, $j] = rs_vt_call('GET', '/analyses/' . rawurlencode((string)$f['analysis']));
        $attr = (array)($j['data']['attributes'] ?? []);
        if ($code === 200 && ($attr['status'] ?? '') === 'completed') {
            rs_vt_save($id, ['status' => 'done', 'scanner' => 'vt'] + rs_vt_results($attr, 'stats'));
            return true;
        }
        rs_vt_save($id, ['next_at' => gmdate('Y-m-d H:i:s', time() + 120)]);
        return $code !== 429;
    }
    return true;
}

// Works through the line for up to $seconds. -> how many steps.
function rs_vt_run(float $seconds = 20): int {
    if (!rs_vt_on()) return 0;
    $lock = fopen(sys_get_temp_dir() . '/rs-vt.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return 0;
    rs_vt_tables();
    @set_time_limit(1200);
    $until = microtime(true) + $seconds;
    $n = 0;
    try {
        rs_vt_discover();
        // (3.8) When VirusTotal can't take more, the files ClamAV can check here still go on.
        $onlyClam = false;
        $subsToo = rs_vt_cfg()['clam_subs'];
        while (microtime(true) < $until) {
            $f = rs_db()->query("SELECT * FROM vt_files WHERE status IN ('new', 'upload', 'analyzing') AND (next_at IS NULL OR next_at <= UTC_TIMESTAMP())"
                . ($onlyClam ? ($subsToo ? " AND status IN ('new', 'upload') AND prio <= 4" : " AND status = 'new' AND prio BETWEEN 3 AND 4") : '') . ' ORDER BY prio, created LIMIT 1')->fetch();
            if (!$f) break;
            if (!rs_vt_step($f)) {
                if ($onlyClam || !rs_clam_on()) break;
                $onlyClam = true;
                continue;
            }
            $n++;
        }
        if ($n) rs_vt_promote();
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    return $n;
}

// After this request's answer has gone out: a turn at the line, at most every 20 seconds.
function rs_vt_after(): void {
    static $set = false;
    if ($set) return;
    $set = true;
    $stamp = sys_get_temp_dir() . '/rs-vt-stamp';
    if (is_file($stamp) && time() - (int)@filemtime($stamp) < 20) return;
    // (4.6) Not while the server is overloaded: the checks wait until it's calm again.
    $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    if ($load && $load[0] > 6) return;
    @touch($stamp);
    register_shutdown_function(function () {
        try {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            if (rs_vt_on()) rs_vt_run(40);
        } catch (Throwable $e) { error_log('vt run: ' . $e->getMessage()); }
    });
}

// ---- Updates on hold (PC recomps) --------------------------------------------------------------

function rs_vt_trusted(array $a): bool {
    if ((string)($a['dev'] ?? '') === '') return false;
    if (function_exists('rs_pc_host') && rs_pc_host($a) === 'sourceforge') return false; // (4.3) the full hold every time
    static $devs = null;
    $devs ??= rs_developers();
    return !empty($devs[$a['dev']]['verified']);
}

// The files of one release: [rows], and what they add up to: green, yellow, red, or grey (not done).
function rs_vt_release(string $repo, string $tag): array {
    rs_vt_tables();
    $st = rs_db()->prepare('SELECT * FROM vt_files WHERE repo = ? AND tag = ? ORDER BY name');
    $st->execute([$repo, $tag]);
    $rows = $st->fetchAll();
    $v = $rows ? 'green' : 'grey';
    foreach ($rows as $r) {
        $x = in_array($r['status'], ['big', 'error'], true) ? 'green' : rs_vt_verdict($r); // can't be checked: the hold decides
        if ($x === 'red') { $v = 'red'; break; }
        if ($x === 'grey') $v = 'grey';
        elseif ($x === 'yellow' && $v === 'green') $v = 'yellow';
    }
    return [$rows, $v];
}

// The refresh found a new release for a recomp. -> the recomp, with it held or let through.
function rs_vt_hold(array $a, array $latest, array $r): array {
    $tag = (string)$latest['tag_name'];
    if ($tag === (string)($a['blocked_tag'] ?? '')) return $a;
    $a['pending'] = ['tag' => $tag, 'version' => (string)$r['version'], 'released' => (string)$r['released'],
        'size' => $r['size'] ? rs_human_size((int)$r['size']) : '', 'since' => (string)($a['pending']['tag'] ?? '') === $tag ? (string)$a['pending']['since'] : gmdate('c')];
    foreach (rs_vt_pick((array)$latest['assets'], rs_vt_main_patterns(['asset_win' => $a['asset_win'], 'asset_linux' => $a['asset_linux'], 'asset_mac' => $a['asset_mac']])) as $f)
        rs_vt_add(rs_pc_key($a), $tag, $f, 'update', 'pc:' . $a['id'], 3);
    return $a;
}

// Lets held releases through once they're checked and their time is up; tells you about red ones.
function rs_vt_promote(): void {
    $cfg = rs_vt_cfg();
    foreach (rs_pc_apps() as $a) {
        $p = $a['pending'] ?? null;
        if (!is_array($p) || empty($p['tag'])) continue;
        [, $v] = rs_vt_release(rs_pc_key($a), (string)$p['tag']);
        if ($v === 'red') {
            if (empty($p['told'])) {
                $a['pending']['told'] = gmdate('c');
                rs_pc_save($a);
                rs_log('security', $a['id'], 'held: ' . $p['tag'] . ' was flagged');
                try {
                    if (function_exists('rs_mail_ready') && rs_mail_ready()) rs_mail_send(rs_mail_get('mail_from'), 'Held: ' . $a['name'] . ' ' . $p['version'],
                        'VirusTotal flagged the new release of ' . $a['name'] . ' (' . $p['tag'] . '). Players keep the version before it until you look at it.' . "\n\n" . rs_abs('/admin/security.php'));
                } catch (Throwable $e) { /* it's on the Security page either way */ }
            }
            continue;
        }
        if (!$cfg['auto']) continue;
        $secs = (rs_vt_trusted($a) ? $cfg['hold_verified'] : $cfg['hold']) * 60;
        $waited = time() - (int)strtotime((string)$p['since']);
        // (3.8) Not checked yet: it waits, but not forever. A busy day can use up the free key, and
        // players shouldn't be stuck on an old version because of that; it's still checked afterwards.
        if ($v === 'grey' && $waited < max($secs, RS_VT_GREY_HOURS * 3600)) continue;
        // (4.3) A SourceForge release never goes out unchecked: it waits for its check (or for you).
        if ($v === 'grey' && rs_pc_host($a) === 'sourceforge') continue;
        if ($waited < $secs) continue;
        if ($v === 'grey') rs_log('security', $a['id'], 'let out unchecked after ' . RS_VT_GREY_HOURS . ' h: ' . $p['tag']);
        rs_vt_release_go($a);
    }
}

// The held release goes out: to the recomp, and to the site and launcher straight away.
function rs_vt_release_go(array $a, string $who = 'auto'): void {
    $p = (array)($a['pending'] ?? []);
    if (empty($p['tag'])) return;
    $a['ok_tag'] = (string)$p['tag'];
    if ($p['version'] !== '') $a['version'] = (string)$p['version'];
    if ($p['released'] !== '') $a['released'] = (string)$p['released'];
    if ($p['size'] !== '') $a['size'] = (string)$p['size'];
    unset($a['pending']);
    rs_pc_save($a);
    rs_log('security', $a['id'], 'released ' . $a['ok_tag'] . ' (' . $who . ')');
    if (!$a['hidden']) rs_pc_publish_one($a['id']);
}

function rs_vt_block(array $a): void {
    $p = (array)($a['pending'] ?? []);
    if (empty($p['tag'])) return;
    $a['blocked_tag'] = (string)$p['tag'];
    unset($a['pending']);
    rs_pc_save($a);
    rs_log('security', $a['id'], 'blocked ' . $a['blocked_tag']);
}

// For the catalog: the checked files of the release that's out, so the launcher can match them.
function rs_vt_catalog_files(array $a): array {
    $tag = (string)($a['ok_tag'] ?? '');
    if ($tag === '' || $a['repo'] === '') return [];
    rs_vt_tables();
    $st = rs_db()->prepare("SELECT name, sha256, status, malicious, suspicious FROM vt_files WHERE repo = ? AND tag = ? AND sha256 IS NOT NULL");
    $st->execute([rs_pc_key($a), $tag]);
    $out = [];
    $sys = ['windows' => $a['asset_win'], 'linux' => $a['asset_linux'], 'macos' => $a['asset_mac']];
    foreach ($st->fetchAll() as $r) foreach ($sys as $k => $p) if ($p !== '' && rs_pattern_matches($p, (string)$r['name']))
        $out[$k] = ['name' => (string)$r['name'], 'sha256' => (string)$r['sha256'], 'scan' => $r['status'] === 'done' ? rs_vt_verdict($r) : 'pending'];
    return $out;
}

// ---- Check a link --------------------------------------------------------------------------------

// A GitHub repo, a release page or a file link. -> what it is and its files, each queued to check.
function rs_vt_check_link(string $url): array {
    $url = trim($url);
    if (preg_match('#^(?:https?://)?(?:www\.)?github\.com/([\w.-]+/[\w.-]+?)(?:\.git)?(?:/releases/(?:tag/([^/?\#]+)|download/([^/]+)/([^/?\#]+)|latest))?/?(?:[?\#].*)?$#i', $url, $m)) {
        $repo = $m[1];
        $gi = rs_gh_repo($repo) ?: [];
        $info = ['description' => (string)($gi['description'] ?? ''), 'stars' => (int)($gi['stargazers_count'] ?? 0), 'owner' => (string)($gi['owner']['login'] ?? ''), 'archived' => !empty($gi['archived'])];
        $rel = rs_gh_releases($repo, 300);
        $list = $rel['status'] === 200 ? (json_decode((string)$rel['body'], true) ?: []) : [];
        $want = urldecode((string)($m[2] ?? '') !== '' ? $m[2] : (string)($m[3] ?? ''));
        $r = null;
        foreach ($list as $x) if ($want !== '' && (string)$x['tag_name'] === $want) $r = $x;
        $r ??= rs_vt_newest($list);
        $files = $r ? rs_vt_pick((array)$r['assets'], !empty($m[4]) ? [urldecode($m[4])] : []) : [];
        foreach ($files as $f) rs_vt_add($repo, (string)$r['tag_name'], $f, 'link', 'link', 1);
        $flags = [];
        if ($r) rs_pc_assets((array)$r['assets'], $flags);
        $where = [];
        foreach (rs_pc_apps() as $a) if (rs_pc_host($a) === '' && strcasecmp($a['repo'], $repo) === 0) $where[] = 'PC: ' . $a['name'] . ($a['hidden'] ? ' (hidden)' : '');
        foreach (rs_games() as $g) if (strcasecmp((string)$g['repo'], $repo) === 0) $where[] = 'PS5: ' . $g['name'] . (!empty($g['hidden']) ? ' (hidden)' : '');
        return ['ok' => true, 'repo' => $repo, 'info' => $info, 'tag' => $r ? (string)$r['tag_name'] : '', 'when' => $r ? (string)$r['published_at'] : '',
            'pre' => $r ? !empty($r['prerelease']) : false, 'releases' => count($list), 'flags' => array_keys($flags), 'where' => $where,
            'files' => array_map(fn($f) => (string)$f['name'], $files)];
    }
    // (3.9) A GitLab project, or one of its release pages (/-/releases/<tag>).
    if (preg_match('#^(?:https?://)?(?:www\.)?gitlab\.com/#i', $url)) {
        $tagWant = preg_match('#/-/releases/([^/?\#]+)#', $url, $tm) ? urldecode($tm[1]) : '';
        $l = rs_repo_from_link($url);
        if (!$l) return ['ok' => false, 'error' => 'That isn\'t a GitLab project link.'];
        $repo = $l['repo'];
        $gi = rs_gl_project($repo) ?: [];
        if (isset($gi['_status'])) $gi = [];
        $info = ['description' => (string)($gi['description'] ?? ''), 'stars' => (int)($gi['stars'] ?? 0), 'owner' => (string)($gi['owner'] ?? ''), 'archived' => !empty($gi['archived'])];
        $list = rs_vt_list('gitlab', $repo, 300);
        $r = null;
        foreach ($list as $x) if ($tagWant !== '' && (string)$x['tag_name'] === $tagWant) $r = $x;
        $r ??= rs_vt_newest($list);
        $key = rs_repo_key('gitlab', $repo);
        $files = $r ? rs_vt_pick((array)$r['assets'], []) : [];
        foreach ($files as $f) rs_vt_add($key, (string)$r['tag_name'], $f, 'link', 'link', 1);
        $flags = [];
        if ($r) rs_pc_assets((array)$r['assets'], $flags);
        $where = [];
        foreach (rs_pc_apps() as $a) if (strcasecmp(rs_pc_key($a), $key) === 0) $where[] = 'PC: ' . $a['name'] . ($a['hidden'] ? ' (hidden)' : '');
        return ['ok' => true, 'repo' => $key, 'info' => $info, 'tag' => $r ? (string)$r['tag_name'] : '', 'when' => $r ? (string)$r['published_at'] : '',
            'pre' => $r ? !empty($r['prerelease']) : false, 'releases' => count($list), 'flags' => array_keys($flags), 'where' => $where,
            'files' => array_map(fn($f) => (string)$f['name'], $files)];
    }
    // (4.3) A SourceForge project, or one of its version folders (/files/<version>/).
    if (preg_match('#^(?:https?://)?(?:www\.)?sourceforge\.net/(?:projects|p)/#i', $url)) {
        $tagWant = preg_match('#/files/([^/?\#]+)/#', $url, $tm) ? urldecode($tm[1]) : '';
        $l = rs_repo_from_link($url);
        if (!$l || $l['host'] !== 'sourceforge') return ['ok' => false, 'error' => 'That isn\'t a SourceForge project link.'];
        $repo = $l['repo'];
        $si = rs_sf_project($repo) ?: [];
        if (isset($si['_status'])) $si = [];
        $info = ['description' => (string)($si['description'] ?? ''), 'stars' => 0, 'owner' => (string)($si['owner'] ?? ''), 'archived' => false];
        $list = rs_vt_list('sourceforge', $repo, 300);
        $r = null;
        foreach ($list as $x) if ($tagWant !== '' && (string)$x['tag_name'] === $tagWant) $r = $x;
        $r ??= rs_vt_newest($list);
        $key = rs_repo_key('sourceforge', $repo);
        $files = $r ? rs_vt_pick((array)$r['assets'], []) : [];
        foreach ($files as $f) rs_vt_add($key, (string)$r['tag_name'], $f, 'link', 'link', 1);
        $flags = [];
        if ($r) rs_pc_assets((array)$r['assets'], $flags);
        $where = [];
        foreach (rs_pc_apps() as $a) if (strcasecmp(rs_pc_key($a), $key) === 0) $where[] = 'PC: ' . $a['name'] . ($a['hidden'] ? ' (hidden)' : '');
        return ['ok' => true, 'repo' => $key, 'info' => $info, 'tag' => $r ? (string)$r['tag_name'] : '', 'when' => $r ? (string)$r['published_at'] : '',
            'pre' => false, 'releases' => count($list), 'flags' => array_keys($flags), 'where' => $where,
            'files' => array_map(fn($f) => (string)$f['name'], $files)];
    }
    if (preg_match('#^https://[^\s]+/([^/?\#\s]+)$#i', $url, $m)) {
        rs_vt_add('', 'link', ['name' => urldecode($m[1]), 'url' => $url, 'size' => 0], 'link', 'link', 1);
        return ['ok' => true, 'repo' => '', 'tag' => 'link', 'files' => [urldecode($m[1])], 'where' => [], 'flags' => []];
    }
    return ['ok' => false, 'error' => 'Paste a GitHub repo, a GitLab project, a release page, or a link to a file.'];
}

function rs_vt_rows(string $repo, string $tag, array $names): array {
    rs_vt_tables();
    if (!$names) return [];
    $in = implode(',', array_fill(0, count($names), '?'));
    $st = rs_db()->prepare("SELECT * FROM vt_files WHERE repo = ? AND tag = ? AND name IN ($in) ORDER BY name");
    $st->execute(array_merge([$repo, $tag], $names));
    return array_map(fn($r) => ['name' => $r['name'], 'size' => (int)$r['size'], 'status' => $r['status'], 'verdict' => rs_vt_verdict($r),
        'malicious' => (int)$r['malicious'], 'suspicious' => (int)$r['suspicious'], 'engines' => (int)$r['engines'], 'flagged_by' => $r['flagged_by'], 'scanner' => (string)($r['scanner'] ?? 'vt'),
        'err' => $r['err'], 'link' => rs_vt_link($r)], $st->fetchAll());
}

// ---- Badges for the other admin pages ------------------------------------------------------------

// The newest checked release of a repo, summed up. -> [verdict, text, link] or null
function rs_vt_repo_badge(string $repo, string $tag = ''): ?array {
    if ($repo === '') return null;
    rs_vt_tables();
    if ($tag === '') {
        $st = rs_db()->prepare('SELECT tag FROM vt_files WHERE repo = ? ORDER BY created DESC LIMIT 1');
        $st->execute([$repo]);
        $tag = (string)$st->fetchColumn();
        if ($tag === '') return null;
    }
    [$rows, $v] = rs_vt_release($repo, $tag);
    if (!$rows) return null;
    $bad = 0; $eng = 0; $link = '';
    foreach ($rows as $r) { $bad = max($bad, (int)$r['malicious'] + (int)$r['suspicious']); $eng = max($eng, (int)$r['engines']); $link = $link ?: rs_vt_link($r); }
    $waiting = count(array_filter($rows, fn($r) => in_array($r['status'], ['new', 'upload', 'analyzing'], true)));
    $clamOnly = $rows && !array_filter($rows, fn($r) => ($r['scanner'] ?? 'vt') !== 'clamav');
    $text = $waiting ? 'Checking ' . count($rows) . ($waiting === 1 && count($rows) === 1 ? ' file' : ' files') . '…'
        : ($clamOnly ? ($v === 'green' ? 'Clean (ClamAV)' : 'ClamAV found something')
        : ($v === 'green' ? 'Clean' . ($eng ? ' (0 of ' . $eng . ')' : '') : ($bad . ' of ' . $eng . ' engines flagged')));
    return [$waiting ? 'grey' : $v, $text . ' · ' . $tag, $link];
}

function rs_vt_badge_html(?array $b): string {
    if (!$b) return '';
    [$v, $text, $link] = $b;
    $icon = ['green' => '✓', 'yellow' => '!', 'red' => '✕', 'grey' => '…'][$v] ?? '…';
    $inner = '<span class="vt-dot">' . $icon . '</span>' . h($text);
    return $link ? '<a class="vt-badge ' . $v . '" href="' . h($link) . '" target="_blank" rel="noopener" title="VirusTotal report">' . $inner . '</a>'
        : '<span class="vt-badge ' . $v . '">' . $inner . '</span>';
}

// For the admin's top bar: red files in submissions and held updates from the last two weeks.
function rs_vt_need_count(): int {
    try {
        if (!rs_vt_on()) return 0;
        rs_vt_tables();
        $st = rs_db()->prepare("SELECT COUNT(DISTINCT repo, tag) FROM vt_files WHERE status = 'done' AND malicious >= ? AND source IN ('update', 'submission')
            AND seen = 0 AND updated > DATE_SUB(NOW(), INTERVAL 14 DAY)");
        $st->execute([rs_vt_cfg()['red_at']]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
