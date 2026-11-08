<?php
// RecompStore (2.7.3): players' logs, smarter. A log's crash signature (the first line that looks
// like the error, with numbers and addresses taken out) so the same problem sent by many players
// shows as one, and zips: one report, or every report for an app (or all of them) at once.
declare(strict_types=1);

// The line in a log that says what went wrong, made the same for every player: addresses, numbers,
// paths and times become #, so "Segfault at 0x7f12a0 in thread 3" and "Segfault at 0x812ff in
// thread 9" are one problem. '' when nothing looks like an error.
// (3.4) $appId and $file: a RecompLauncher launcher-trail.txt only counts for the report's own game
// (rs_log_trail_lines), and the launcher's own chatter (notes, GitHub fallbacks, pictures) is never
// the headline.
function rs_log_signature(string $text, string $appId = '', string $file = ''): string {
    if ($text === '') return '';
    $text = substr($text, -262144); // the end of a log is where it went wrong
    $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
    if (rs_log_is_trail($file, $text)) $lines = rs_log_trail_lines($lines, $appId);
    $lines = array_values(array_filter($lines, fn($l) => !rs_log_noise((string)$l)));
    $strong = '/\b(panic|fatal|segfault|segmentation fault|sigsegv|sigabrt|sigbus|sigill|access violation|unhandled exception|uncaught|assert(ion)?( failed)?|abort(ed)?|crash(ed)?|core dump|stack overflow|out of memory|kernel panic)\b/i';
    $weak = '/\b(error|exception|failed|failure|cannot|can\'t|couldn\'t|unable to|not found|missing|denied|invalid)\b/i';
    $pick = '';
    // The last strong line wins (closest to the end), else the last weak one.
    for ($i = count($lines) - 1; $i >= 0 && $pick === ''; --$i) if (preg_match($strong, $lines[$i])) $pick = $lines[$i];
    for ($i = count($lines) - 1; $i >= 0 && $pick === ''; --$i) if (preg_match($weak, $lines[$i]) && !preg_match('/\b(no error|0 errors|without error)\b/i', $lines[$i])) $pick = $lines[$i];
    if ($pick === '') return '';
    $s = $pick;
    $s = preg_replace('/^\s*(\[[^\]]{0,40}\]\s*)+/', '', $s) ?? $s;                       // [12:01:02] [INFO]
    $s = preg_replace('/^\s*\d{2,4}[-\/.]\d{1,2}[-\/.]\d{1,4}[ T][\d:.,]+\S*\s*/', '', $s) ?? $s; // 2026-10-25 12:01:02.123
    $s = preg_replace('/^\s*\d{1,2}:\d{2}(:\d{2})?([.,]\d+)?\s+/', '', $s) ?? $s;          // 12:01:02 (3.4)
    $s = preg_replace('/^\s*(\[[^\]]{0,40}\]\s*)+/', '', $s) ?? $s;
    $s = preg_replace('/0x[0-9a-f]+/i', '#', $s) ?? $s;
    $s = preg_replace('/\b[0-9a-f]{8,}\b/i', '#', $s) ?? $s;
    $s = preg_replace('#(/[^\s/:]+){2,}/?#', '/…', $s) ?? $s;
    $s = preg_replace('/\d+/', '#', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', trim($s)) ?? $s;
    return mb_substr($s, 0, 160);
}

// (3.4) Whether a report's file is RecompLauncher's launcher-trail.txt (by its name, or, for files
// sent without one, by Beta 22's section line).
function rs_log_is_trail(string $file, string $text): bool {
    $base = strtolower(basename(str_replace('\\', '/', $file)));
    if ($base !== '') return $base === 'launcher-trail.txt';
    return preg_match('/^== Everything, newest ==\s*$/m', $text) === 1;
}

// (3.4) The lines of a launcher trail that are about the report's game. Beta 22 and later send
// "== <app id> ==" with that game's own lines, then "== Everything, newest ==": only the first part
// counts. Older launchers send one list for every game: lines that name another game's id go.
function rs_log_trail_lines(array $lines, string $appId): array {
    $appId = strtolower(trim($appId));
    if ($appId === '' || $appId === 'launcher' || $appId === 'store') return $lines;
    $own = null;
    $in = false;
    foreach ($lines as $l) {
        if (preg_match('/^\s*==\s*(.+?)\s*==\s*$/', (string)$l, $m)) {
            $in = strtolower($m[1]) === $appId;
            if ($in) $own = [];
            continue;
        }
        if ($in) $own[] = $l;
    }
    if ($own !== null) return $own;
    $ids = rs_log_known_ids();
    return array_values(array_filter($lines, function ($l) use ($ids, $appId) {
        if (!preg_match_all('/(?<![a-z0-9._-])[a-z0-9]+(?:[._-][a-z0-9]+)+(?![a-z0-9_-])/', strtolower((string)$l), $m)) return true;
        foreach ($m[0] as $tok) if ($tok !== $appId && isset($ids[$tok])) return false;
        return true;
    }));
}

// (3.4) Every game id in the catalogs (PC and PS5), lowercase, as keys.
function rs_log_known_ids(): array {
    static $ids = null;
    if ($ids !== null) return $ids;
    $ids = [];
    try { foreach (rs_db()->query('SELECT id FROM pc_apps')->fetchAll(PDO::FETCH_COLUMN) as $i) $ids[strtolower((string)$i)] = true; } catch (Throwable $e) {}
    try { foreach (array_keys(rs_games()) as $i) $ids[strtolower((string)$i)] = true; } catch (Throwable $e) {}
    return $ids;
}

// (3.4) The launcher's own chatter, never a log's headline: its notes, release info and pictures that
// came another way, and GitHub being slow. Times and [tags] at the start are looked past.
function rs_log_noise(string $line): bool {
    $s = preg_replace('/^\s*(\[[^\]]{0,40}\]\s*)+/', '', $line) ?? $line;
    $s = preg_replace('/^\s*\d{2,4}[-\/.]\d{1,2}[-\/.]\d{1,4}[ T][\d:.,]+\S*\s*/', '', $s) ?? $s;
    $s = preg_replace('/^\s*\d{1,2}:\d{2}(:\d{2})?([.,]\d+)?\s*/', '', $s) ?? $s;
    $s = preg_replace('/^\s*(\[[^\]]{0,40}\]\s*)+/', '', $s) ?? $s;
    $s = ltrim($s, " \t-·•|");
    return preg_match('/^(note:|release info for .* came from github|pictures from .* (didn\'t|didn’t|did not) (come|load)|github\.com had a problem sending)/iu', $s) === 1;
}

// The signature of one report: from its files, newest file last.
function rs_log_report_signature(int $inboxId, string $appId = ''): string {
    $st = rs_db()->prepare('SELECT name, RIGHT(body, 65536) AS b FROM inbox_files WHERE inbox_id = ? ORDER BY id');
    $st->execute([$inboxId]);
    $sig = '';
    foreach ($st->fetchAll() as $f) { $s = rs_log_signature((string)$f['b'], $appId, (string)$f['name']); if ($s !== '') $sig = $s; }
    return $sig;
}

// Safe on Windows, macOS and Linux: no / \ : * ? " < > |, no trailing dots or spaces.
function rs_zip_clean(string $s): string {
    $s = preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', '-', $s) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '', " .-");
}

// One report's files for a zip: [folder name, [path => bytes]]. $forDev leaves out what only you
// see (the console, a tester's code and the contact they left).
function rs_log_report_items(array $r, bool $forDev = false): array {
    $db = rs_db();
    $game = rs_game((string)$r['app_id']);
    $name = $r['app_id'] === 'store' ? 'RecompStore' : (string)($game['name'] ?? $r['app_id']);
    $version = trim((string)$r['version']) !== '' ? (string)$r['version'] : 'not installed';
    $what = ($r['feedback'] ?? '') !== '' ? 'Feedback ' . (RS_VERDICTS[$r['feedback']] ?? 'Other') : (RS_LOG_CATEGORIES[$r['category'] ?? ''] ?? 'Logs');
    $tester = !$forDev && trim((string)$r['contact']) !== '' ? 'tester ' . substr((string)$r['contact'], 0, 6) : '';
    $day = rs_date('Y-m-d', strtotime((string)$r['created']));
    $folder = mb_substr(rs_zip_clean(implode(' - ', array_filter([$name, $version, $what, $tester, $day], fn($p) => $p !== ''))) ?: 'logs', 0, 120);
    $items = [];
    $text = [];
    $text[] = $name . ' · ' . ($version === 'not installed' ? 'not installed' : 'version ' . $version) . ($r['name'] !== '' ? ' · RecompStore ' . $r['name'] : '');
    $text[] = $what . ($tester !== '' ? ' · beta ' . $tester : '');
    $text[] = 'Sent ' . rs_date('M j, Y g:i a', strtotime((string)$r['created'])) . (!$forDev && $r['contact'] !== '' ? ' · console ' . $r['contact'] : '');
    if (trim((string)$r['body']) !== '') { $text[] = ''; $text[] = 'Message:'; $text[] = trim((string)$r['body']); }
    $rep = $db->prepare('SELECT * FROM inbox_reports WHERE inbox_id = ?');
    $rep->execute([(int)$r['id']]);
    if ($p = $rep->fetch()) {
        $text[] = '';
        $text[] = 'Finished on their phone, ' . rs_date('M j, g:i a', strtotime((string)$p['finished'])) . ':';
        $text[] = 'What happened: ' . trim((string)$p['what']);
        if (trim((string)$p['before_text']) !== '') $text[] = 'Right before: ' . trim((string)$p['before_text']);
        if (!$forDev && trim((string)$p['contact']) !== '') $text[] = 'Contact: ' . trim((string)$p['contact']);
    }
    $sig = rs_log_report_signature((int)$r['id'], (string)$r['app_id']);
    if ($sig !== '') { $text[] = ''; $text[] = 'Looks like: ' . $sig; }
    $items['message.txt'] = implode("\r\n", $text) . "\r\n";
    $seen = ['message.txt' => true];
    $f = $db->prepare('SELECT name, body FROM inbox_files WHERE inbox_id = ? ORDER BY id');
    $f->execute([(int)$r['id']]);
    foreach ($f->fetchAll() as $x) {
        $parts = array_values(array_filter(explode('/', str_replace('\\', '/', (string)$x['name'])), fn($s) => $s !== '' && $s !== '.' && $s !== '..'));
        $path = implode('/', array_map('rs_zip_clean', $parts)) ?: 'log.txt';
        for ($n = 2; isset($seen[$path]); ++$n) $path = preg_replace('/(\.[^.\/]*)?$/', ' (' . $n . ')$1', $path, 1);
        $seen[$path] = true;
        $items[$path] = (string)$x['body'];
    }
    $pics = $db->prepare('SELECT file, mime FROM inbox_pictures WHERE inbox_id = ? ORDER BY id');
    $pics->execute([(int)$r['id']]);
    $i = 0;
    foreach ($pics->fetchAll() as $p) {
        $file = rs_report_dir() . '/' . basename((string)$p['file']);
        if (!is_file($file)) continue;
        $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$p['mime']] ?? 'bin';
        $items['pictures/picture ' . (++$i) . '.' . $ext] = (string)file_get_contents($file);
    }
    return [$folder, $items];
}

// A plain zip (stored, no compression): no PHP extension needed, and logs are small.
// $files: [[path, bytes, unix time]].
function rs_zip_stored(array $files): string {
    $zip = '';
    $dir = '';
    $count = 0;
    foreach ($files as [$path, $bytes, $time]) {
        $t = getdate($time);
        $dtime = (($t['hours'] & 31) << 11) | (($t['minutes'] & 63) << 5) | intdiv($t['seconds'], 2);
        $ddate = ((max(1980, $t['year']) - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        $crc = crc32($bytes);
        $len = strlen($bytes);
        $offset = strlen($zip);
        // Bit 11: the names are UTF-8.
        $zip .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dtime, $ddate, $crc, $len, $len, strlen($path), 0) . $path . $bytes;
        $dir .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dtime, $ddate, $crc, $len, $len, strlen($path), 0, 0, 0, 0, 0, $offset) . $path;
        ++$count;
    }
    return $zip . $dir . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($dir), strlen($zip), 0);
}

// Many reports in one zip, a folder each (newest first), up to $maxBytes. -> [zip, reports in it, left out]
function rs_log_zip_many(array $rows, bool $forDev, int $maxBytes = 64 << 20): array {
    $files = [];
    $total = 0;
    $n = $left = 0;
    $folders = [];
    foreach ($rows as $r) {
        [$folder, $items] = rs_log_report_items($r, $forDev);
        $size = array_sum(array_map('strlen', $items));
        if ($total + $size > $maxBytes) { ++$left; continue; }
        for ($k = 2, $base = $folder; isset($folders[$folder]); ++$k) $folder = $base . ' (' . $k . ')';
        $folders[$folder] = true;
        $time = (int)strtotime((string)$r['created']);
        foreach ($items as $path => $bytes) $files[] = [$folder . '/' . $path, $bytes, $time];
        $total += $size;
        ++$n;
    }
    return [rs_zip_stored($files), $n, $left];
}

// (3.3) Who sent a report, as a name a person can tell apart: the same console (or, without one,
// the same address that day) always gets the same made-up name, like "Swift-Falcon-3F2A".
function rs_log_person(array $r): string {
    static $adj = ['Swift', 'Brave', 'Quiet', 'Lucky', 'Clever', 'Bright', 'Calm', 'Bold', 'Sunny', 'Rapid', 'Gentle', 'Mighty', 'Noble', 'Witty', 'Frosty', 'Cosmic',
        'Silver', 'Golden', 'Crimson', 'Azure', 'Jade', 'Amber', 'Shadow', 'Pixel', 'Turbo', 'Retro', 'Neon', 'Lunar', 'Solar', 'Stormy', 'Misty', 'Royal'];
    static $noun = ['Falcon', 'Otter', 'Tiger', 'Panda', 'Wolf', 'Fox', 'Hawk', 'Lynx', 'Raven', 'Badger', 'Koala', 'Dolphin', 'Puma', 'Bison', 'Heron', 'Gecko',
        'Comet', 'Rocket', 'Golem', 'Knight', 'Ninja', 'Wizard', 'Ranger', 'Pilot', 'Racer', 'Titan', 'Phoenix', 'Dragon', 'Yeti', 'Kraken', 'Sphinx', 'Griffin'];
    $key = trim((string)($r['contact'] ?? ''));
    if ($key === '') $key = 'ip:' . (string)($r['ip_hash'] ?? '');
    $h = hash('sha256', 'person|' . strtolower($key));
    return $adj[hexdec(substr($h, 0, 2)) % count($adj)] . '-' . $noun[hexdec(substr($h, 2, 2)) % count($noun)] . '-' . strtoupper(substr($h, 4, 4));
}

// (3.3) A log's "what went wrong" line, worked out once and kept (inbox.sig; "-" for none), so the
// Inbox doesn't read every log's text each time it opens.
function rs_inbox_sig_column(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $f = dirname(RS_PUBLIC) . '/recompstore-private/inbox-sig-column.done';
    if (!is_file($f)) {
        if (!$db->query("SHOW COLUMNS FROM inbox LIKE 'sig'")->fetch()) $db->exec("ALTER TABLE inbox ADD COLUMN sig VARCHAR(255) NOT NULL DEFAULT '', ADD INDEX sig_app (kind, app_id, sig(64))");
        @touch($f);
    }
    // (3.4) Headlines worked out again: launcher trails now count only for the report's own game.
    $f2 = dirname(RS_PUBLIC) . '/recompstore-private/inbox-sig-v2.done';
    if (!is_file($f2)) {
        try { $db->exec("UPDATE inbox SET sig = '' WHERE kind = 'logs'"); @touch($f2); } catch (Throwable $e) {}
    }
    $done = true;
}
function rs_log_sig_of(array &$r): string {
    $s = (string)($r['sig'] ?? '');
    if ($s === '') {
        $s = mb_substr(rs_log_report_signature((int)$r['id'], (string)($r['app_id'] ?? '')), 0, 250);
        if ($s === '') $s = '-';
        try { rs_db()->prepare('UPDATE inbox SET sig = ? WHERE id = ?')->execute([$s, (int)$r['id']]); } catch (Throwable $e) {}
        $r['sig'] = $s;
    }
    return $s === '-' ? '' : $s;
}

// (3.3) Many reports in one zip, a folder per person, each with a folder per report in it (its log
// files, message.txt and pictures). -> [zip, reports in it, left out, people]
function rs_log_zip_people(array $rows, int $maxBytes = 64 << 20): array {
    $files = [];
    $total = $n = $left = 0;
    $used = [];
    $people = [];
    foreach ($rows as $r) {
        [$folder, $items] = rs_log_report_items($r, false);
        $size = array_sum(array_map('strlen', $items));
        if ($total + $size > $maxBytes) { ++$left; continue; }
        $who = rs_log_person($r);
        $people[$who] = true;
        $path = $who . '/' . $folder;
        for ($k = 2, $base = $path; isset($used[$path]); ++$k) $path = $base . ' (' . $k . ')';
        $used[$path] = true;
        $time = (int)strtotime((string)$r['created']);
        foreach ($items as $p => $bytes) $files[] = [$path . '/' . $p, $bytes, $time];
        $total += $size;
        ++$n;
    }
    return [rs_zip_stored($files), $n, $left, count($people)];
}

// Sends a zip to the browser and ends.
function rs_zip_send(string $zip, string $name): void {
    header('Content-Type: application/zip');
    header('Content-Length: ' . strlen($zip));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    $ascii = preg_replace('/[^A-Za-z0-9 ._()\'-]/', '_', $name);
    header("Content-Disposition: attachment; filename=\"$ascii.zip\"; filename*=UTF-8''" . rawurlencode($name . '.zip'));
    echo $zip;
    exit;
}
