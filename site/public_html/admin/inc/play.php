<?php
// (3.1) Player reports, for every version of every game: PC recomps and PS5 apps alike.
// Anyone can post one: signed in it shows their name and picture, as a guest it says Guest (and
// passes the bot check). One per person per version; a new one replaces theirs. Each says how
// it plays, on what, how far they got, how it runs, what went wrong and what fixed it.
// The older, simpler reports (RecompLauncher's pc_feedback, the website's ps5_feedback) are
// copied in as they come, so nothing is lost and the launcher's old endpoint keeps working.
// From all of it: a score for each version (0-100), on each system, and badges for each game.
declare(strict_types=1);

const RS_PLAY_VERDICTS = ['runs' => 'Plays great', 'issues' => 'Has problems', 'broken' => 'Won\'t run'];
const RS_PLAY_PC_SYS = ['windows' => 'Windows', 'linux' => 'Linux', 'macos' => 'macOS', 'deck' => 'Steam Deck'];
const RS_PLAY_PROGRESS = ['boots' => 'It starts', 'menus' => 'Menus', 'ingame' => 'In game', 'half' => 'Halfway', 'done' => 'Finished it'];
const RS_PLAY_FPS = ['u20' => 'Under 20', '20' => '20-30', '30' => '30-45', '45' => '45-60', '60' => '60', '60p' => 'Over 60'];
const RS_PLAY_RES = ['720' => '720p', '1080' => '1080p', '1440' => '1440p', '4k' => '4K', 'other' => 'Other'];
const RS_PLAY_PROBLEMS = ['crash' => 'Crashes', 'graphics' => 'Graphics', 'audio' => 'Audio', 'controls' => 'Controls',
    'saves' => 'Saves', 'performance' => 'Performance', 'cutscenes' => 'Cutscenes', 'online' => 'Online'];
const RS_PLAY_SEVERITY = [1 => 'Minor', 2 => 'Annoying', 3 => 'Game-breaking'];
const RS_PLAY_FLAGS = ['spam' => 'Spam', 'rude' => 'Rude', 'files' => 'Links to game files', 'wrong' => 'Wrong game or nonsense'];

function rs_play_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS play_reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kind VARCHAR(4) NOT NULL,
        app VARCHAR(64) NOT NULL,
        who CHAR(64) NOT NULL,
        account_id INT NULL,
        show_name TINYINT NOT NULL DEFAULT 1,
        verdict VARCHAR(8) NOT NULL,
        sys VARCHAR(12) NOT NULL DEFAULT \'\',
        version VARCHAR(40) NOT NULL DEFAULT \'\',
        progress VARCHAR(8) NOT NULL DEFAULT \'\',
        fps VARCHAR(4) NOT NULL DEFAULT \'\',
        res VARCHAR(6) NOT NULL DEFAULT \'\',
        problems VARCHAR(400) NOT NULL DEFAULT \'\',
        gpu VARCHAR(80) NOT NULL DEFAULT \'\',
        cpu VARCHAR(80) NOT NULL DEFAULT \'\',
        ram SMALLINT NOT NULL DEFAULT 0,
        fix VARCHAR(600) NOT NULL DEFAULT \'\',
        note VARCHAR(1200) NOT NULL DEFAULT \'\',
        helpful INT NOT NULL DEFAULT 0,
        flags INT NOT NULL DEFAULT 0,
        hidden TINYINT NOT NULL DEFAULT 0,
        source VARCHAR(8) NOT NULL DEFAULT \'site\',
        legacy_id INT NULL,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        UNIQUE KEY one (kind, app, who),
        UNIQUE KEY legacy (source, legacy_id),
        KEY version (kind, app, hidden, updated),
        KEY author (account_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS play_votes (
        report_id INT NOT NULL,
        who CHAR(64) NOT NULL,
        PRIMARY KEY (report_id, who)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS play_flags (
        report_id INT NOT NULL,
        who CHAR(64) NOT NULL,
        reason VARCHAR(8) NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY (report_id, who)
    ) CHARACTER SET utf8mb4');
    // (3.9) What a developer needs to answer a PC report: the player's PC in a few words, their log
    // (kept outside the website, never public), and whether the developer has seen it yet.
    if (!$db->query("SHOW COLUMNS FROM play_reports LIKE 'specs'")->fetch()) {
        $db->exec("ALTER TABLE play_reports ADD COLUMN specs VARCHAR(600) NOT NULL DEFAULT '', ADD COLUMN log_name VARCHAR(120) NOT NULL DEFAULT '',
            ADD COLUMN has_log TINYINT NOT NULL DEFAULT 0, ADD COLUMN dev_seen TINYINT NOT NULL DEFAULT 0");
        $db->exec('UPDATE play_reports SET dev_seen = 1'); // the ones from before aren't news
    }
    // (4.0) Played through Proton (a Windows build on Linux or Steam Deck), as RecompLauncher reports it.
    if (!$db->query("SHOW COLUMNS FROM play_reports LIKE 'proton'")->fetch())
        $db->exec("ALTER TABLE play_reports ADD COLUMN proton TINYINT NOT NULL DEFAULT 0");
    // (3.9) Developers answer reports, and the player can answer back. Shown under the report.
    $db->exec('CREATE TABLE IF NOT EXISTS play_report_replies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        from_dev TINYINT NOT NULL DEFAULT 0,
        dev_account_id INT NULL,
        account_id INT NULL,
        text VARCHAR(2000) NOT NULL,
        created DATETIME NOT NULL,
        KEY report (report_id, created)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// ---- The older reports, copied in as they arrive ------------------------------------------------
function rs_play_sync_legacy(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    rs_play_tables();
    $db = rs_db();
    // At most once a minute is plenty.
    $last = (int)rs_setting('play_legacy_at', '0');
    if (time() - $last < 60) return;
    rs_set_setting('play_legacy_at', (string)time());
    $tables = array_column($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM), 0);
    // RecompLauncher's (pc_feedback): one per connection, stamped in the server's own clock.
    // (3.9) With the player's account (never shown by name) and their PC's specs.
    if (in_array('pc_feedback', $tables, true)) {
        rs_pc_tables();
        $db->exec("INSERT INTO play_reports (kind, app, who, account_id, show_name, verdict, sys, version, note, specs, hidden, source, legacy_id, created, updated)
            SELECT 'pc', f.app, f.ip_hash, f.account_id, 0, f.verdict, f.sys, f.version, f.note, f.specs, f.hidden, 'pcfb', f.id,
                   DATE_SUB(f.created, INTERVAL TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) SECOND),
                   DATE_SUB(f.created, INTERVAL TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW()) SECOND)
            FROM pc_feedback f
            ON DUPLICATE KEY UPDATE verdict = IF(play_reports.source = 'pcfb', VALUES(verdict), play_reports.verdict),
                account_id = IF(play_reports.source = 'pcfb', COALESCE(VALUES(account_id), play_reports.account_id), play_reports.account_id),
                specs = IF(play_reports.source = 'pcfb' AND VALUES(specs) <> '', VALUES(specs), play_reports.specs),
                show_name = IF(play_reports.source = 'pcfb', 0, play_reports.show_name),
                sys = IF(play_reports.source = 'pcfb', VALUES(sys), play_reports.sys),
                version = IF(play_reports.source = 'pcfb', VALUES(version), play_reports.version),
                note = IF(play_reports.source = 'pcfb', VALUES(note), play_reports.note),
                hidden = IF(play_reports.source = 'pcfb', VALUES(hidden), play_reports.hidden),
                updated = IF(play_reports.source = 'pcfb', GREATEST(play_reports.updated, VALUES(updated)), play_reports.updated)");
    }
    // The website's PS5 ones (ps5_feedback, 3.0): already UTC, and may belong to an account.
    if (in_array('ps5_feedback', $tables, true)) {
        $db->exec("INSERT INTO play_reports (kind, app, who, account_id, verdict, sys, version, note, hidden, source, legacy_id, created, updated)
            SELECT 'ps5', f.app, f.who, f.account_id, f.verdict, f.fw, f.version, f.note, f.hidden, 'ps5fb', f.id, f.created, f.created
            FROM ps5_feedback f
            ON DUPLICATE KEY UPDATE verdict = IF(play_reports.source = 'ps5fb', VALUES(verdict), play_reports.verdict),
                sys = IF(play_reports.source = 'ps5fb', VALUES(sys), play_reports.sys),
                note = IF(play_reports.source = 'ps5fb', VALUES(note), play_reports.note),
                hidden = IF(play_reports.source = 'ps5fb', VALUES(hidden), play_reports.hidden),
                updated = IF(play_reports.source = 'ps5fb', GREATEST(play_reports.updated, VALUES(updated)), play_reports.updated)");
    }
}

// ---- Reading and writing ---------------------------------------------------------------------------
function rs_play_problems_decode(string $s): array {
    $out = [];
    foreach (explode(',', $s) as $p) {
        [$k, $v] = array_pad(explode(':', $p, 2), 2, '1');
        if (isset(RS_PLAY_PROBLEMS[$k])) $out[$k] = max(1, min(3, (int)$v));
    }
    return $out;
}
function rs_play_problems_encode(array $p): string {
    $out = [];
    foreach ($p as $k => $v) if (isset(RS_PLAY_PROBLEMS[$k])) $out[] = $k . ':' . max(1, min(3, (int)$v));
    return implode(',', $out);
}

// Who's posting: their account, else this connection.
function rs_play_who(?array $me): string {
    return $me ? hash('sha256', 'acct|' . $me['id']) : rs_ip_hash();
}

// $r: verdict sys version progress fps res problems(array) gpu cpu ram fix note show_name. -> report id
function rs_play_save(string $kind, string $app, ?array $me, array $r, string $source = 'site'): int {
    rs_play_tables();
    $who = rs_play_who($me);
    $db = rs_db();
    // A guest who signs in later: their guest report on this connection becomes theirs.
    if ($me) $db->prepare('DELETE FROM play_reports WHERE kind = ? AND app = ? AND who = ? AND account_id IS NULL AND source = \'site\'')->execute([$kind, $app, rs_ip_hash()]);
    // (3.9) With the player's PC in a few words; a changed report is news to its developer again.
    $db->prepare('INSERT INTO play_reports (kind, app, who, account_id, show_name, verdict, sys, version, progress, fps, res, problems, gpu, cpu, ram, fix, note, specs, source, created, updated)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), show_name = VALUES(show_name), verdict = VALUES(verdict), sys = VALUES(sys),
            version = VALUES(version), progress = VALUES(progress), fps = VALUES(fps), res = VALUES(res), problems = VALUES(problems),
            gpu = VALUES(gpu), cpu = VALUES(cpu), ram = VALUES(ram), fix = VALUES(fix), note = VALUES(note), specs = VALUES(specs), source = VALUES(source),
            hidden = 0, flags = 0, dev_seen = 0, updated = UTC_TIMESTAMP()')
        ->execute([$kind, $app, $who, $me ? (int)$me['id'] : null, !empty($r['show_name']) ? 1 : 0, $r['verdict'], $r['sys'], $r['version'],
            $r['progress'], $r['fps'], $r['res'], rs_play_problems_encode($r['problems']), $r['gpu'], $r['cpu'], (int)$r['ram'],
            $r['fix'], $r['note'], (string)($r['specs'] ?? ''), $source]);
    $st = $db->prepare('SELECT id FROM play_reports WHERE kind = ? AND app = ? AND who = ?');
    $st->execute([$kind, $app, $who]);
    rs_play_scores_forget();
    return (int)$st->fetchColumn();
}

function rs_play_item(array $x, ?string $myWho, array $voted, array $replies = []): array {
    $named = $x['account_id'] !== null && (int)$x['show_name'] === 1 && $x['username'] !== null;
    return [
        // (3.9) The player's PC in one line, whether a log came with it (only its developer sees the
        // log itself), and the developer's answers.
        'specs' => rs_play_specs_line($x), 'log' => !empty($x['has_log']),
        'replies' => array_map(fn($r) => $r['dev'] ? $r : ['name' => $named ? (string)$x['username'] : null] + $r, $replies[(int)$x['id']] ?? []),
        'id' => (int)$x['id'], 'verdict' => (string)$x['verdict'], 'sys' => (string)$x['sys'], 'proton' => !empty($x['proton']), 'version' => (string)$x['version'],
        'progress' => (string)$x['progress'], 'fps' => (string)$x['fps'], 'res' => (string)$x['res'],
        'problems' => (object)rs_play_problems_decode((string)$x['problems']),
        'gpu' => (string)$x['gpu'], 'cpu' => (string)$x['cpu'], 'ram' => (int)$x['ram'],
        'fix' => (string)$x['fix'], 'note' => (string)$x['note'], 'helpful' => (int)$x['helpful'],
        'user' => $named ? (string)$x['username'] : null,
        'avatar' => $named && function_exists('rs_acct_avatar_url') ? rs_acct_avatar_url(['avatar' => $x['avatar']]) : '',
        'signed' => $x['account_id'] !== null, 'app' => $x['source'] === 'pcfb' ? 'launcher' : 'site',
        'mine' => $myWho !== null && hash_equals((string)$x['who'], $myWho), 'voted' => isset($voted[(int)$x['id']]),
        'when' => gmdate('c', strtotime((string)$x['updated'] . ' UTC')),
    ];
}

// Every report on a version, with its summary. $sort: helpful | new. $sys: one system, or ''.
function rs_play_list(string $kind, string $app, ?array $me, string $sort = 'helpful', string $sys = '', int $limit = 80): array {
    rs_play_sync_legacy();
    $db = rs_db();
    $myWho = rs_play_who($me);
    $where = 'r.kind = ? AND r.app = ? AND (r.hidden = 0 OR r.who = ?)';
    $args = [$kind, $app, $myWho];
    if ($sys !== '') { $where .= ' AND r.sys = ?'; $args[] = $sys; }
    $order = $sort === 'new' ? 'r.updated DESC' : '(r.helpful + IF(r.fix <> \'\', 2, 0) + IF(r.note <> \'\', 1, 0)) DESC, r.updated DESC';
    $st = $db->prepare("SELECT r.*, a.username, a.avatar FROM play_reports r LEFT JOIN accounts a ON a.id = r.account_id WHERE $where ORDER BY $order LIMIT " . max(1, min(200, $limit)));
    $st->execute($args);
    $rows = $st->fetchAll();
    $voted = [];
    if ($rows) {
        $v = $db->prepare('SELECT report_id FROM play_votes WHERE who = ? AND report_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')');
        $v->execute([$myWho]);
        foreach ($v->fetchAll() as $r) $voted[(int)$r['report_id']] = true;
    }
    $replies = rs_play_replies(array_map(fn($r) => (int)$r['id'], $rows));
    return array_map(fn($x) => rs_play_item($x, $myWho, $voted, $replies), $rows);
}

// ---- (3.9) Specs, logs and answers -----------------------------------------------------------------

const RS_PLAY_LOG_MAX = 2 << 20;

// The player's PC as they (or RecompLauncher) gave it: OS, CPU, GPU, RAM, driver. Plain text, a few lines.
function rs_play_specs_clean(string $s): string {
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $s) ?? '';
    $s = preg_replace("/\n{2,}/", "\n", preg_replace('/[ \t]{2,}/', ' ', $s) ?? '') ?? '';
    return mb_substr(trim($s), 0, 600);
}

// One line for a list: the specs, or the parts the older reports kept on their own.
function rs_play_specs_line(array $x): string {
    $s = trim((string)($x['specs'] ?? ''));
    if ($s !== '') return implode(' · ', array_filter(array_map('trim', explode("\n", $s))));
    return implode(' · ', array_filter([(string)($x['gpu'] ?? ''), (string)($x['cpu'] ?? ''), (int)($x['ram'] ?? 0) ? (int)$x['ram'] . ' GB RAM' : '']));
}

function rs_play_log_path(int $rid): string { return dirname(RS_PUBLIC) . '/recompstore-private/pc-logs/' . $rid . '.log.gz'; }

// An uploaded log, checked: null when it's fine, else what to tell the player. Text only, 2 MB at most.
function rs_play_log_error(array $f): ?string {
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'Logs can be 2 MB at most.';
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) return 'The log didn\'t arrive whole. Try again.';
    if ((int)$f['size'] > RS_PLAY_LOG_MAX || (int)@filesize((string)$f['tmp_name']) > RS_PLAY_LOG_MAX) return 'Logs can be 2 MB at most.';
    if ((int)$f['size'] === 0) return 'That log is empty.';
    $head = (string)@file_get_contents((string)$f['tmp_name'], false, null, 0, 8192);
    if ($head === '' || str_contains($head, "\0")) return 'Send the log as a text file, like a .txt or .log.';
    return null;
}

// Keeps a report's log, gzipped, outside public_html. A newer log from the same player replaces it.
function rs_play_log_save(int $rid, array $f): void {
    $path = rs_play_log_path($rid);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('No room for logs.');
    $gz = gzencode((string)file_get_contents((string)$f['tmp_name']), 6);
    if ($gz === false || file_put_contents($path . '.tmp', $gz) === false || !rename($path . '.tmp', $path)) throw new RuntimeException('The log couldn\'t be kept.');
    $name = mb_substr(preg_replace('/[^\w .()+-]/u', '_', basename((string)($f['name'] ?? ''))) ?? '', 0, 120);
    rs_db()->prepare('UPDATE play_reports SET has_log = 1, log_name = ? WHERE id = ?')->execute([$name !== '' ? $name : 'log.txt', $rid]);
}

// Sends a report's log as a text file download (for its developer or the admin), then stops.
function rs_play_log_send(array $r): void {
    $gz = is_file(rs_play_log_path((int)$r['id'])) ? @file_get_contents(rs_play_log_path((int)$r['id'])) : false;
    $text = $gz !== false ? @gzdecode($gz) : false;
    if ($text === false) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('That log is gone.'); }
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string)($r['log_name'] ?: 'log.txt')) ?? 'log.txt';
    if (!preg_match('/\.(txt|log)$/i', $name)) $name .= '.txt';
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $r['app'] . '-report-' . (int)$r['id'] . '-' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex');
    header('Cache-Control: no-store');
    echo $text;
    exit;
}

// Answers on reports, oldest first: [report id => [{id, dev, name, text, when}]]. A developer's answer
// carries their name; a player's is named by the report (or not at all, for a guest).
function rs_play_replies(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    rs_play_tables();
    if (function_exists('rs_dev_tables')) rs_dev_tables();
    $st = rs_db()->query('SELECT r.id, r.report_id, r.from_dev, r.text, r.created, d.name AS dev_name, d.gh_login FROM play_report_replies r
        LEFT JOIN dev_accounts d ON d.id = r.dev_account_id WHERE r.report_id IN (' . implode(',', $ids) . ') ORDER BY r.created, r.id');
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $dev = (int)$r['from_dev'] === 1;
        $out[(int)$r['report_id']][] = ['id' => (int)$r['id'], 'dev' => $dev,
            'name' => $dev ? ((string)$r['dev_name'] !== '' ? (string)$r['dev_name'] : ((string)$r['gh_login'] !== '' ? (string)$r['gh_login'] : 'Developer')) : null,
            'text' => (string)$r['text'], 'when' => gmdate('c', strtotime((string)$r['created'] . ' UTC'))];
    }
    return $out;
}

// A pc_feedback report (RecompLauncher's, or the older site endpoint's) put on the game page now,
// rather than with the next minute's copy, with what only it carries. -> its play_reports id
function rs_play_from_pcfb(int $fbId, array $r, ?int $accountId): int {
    rs_play_tables();
    $db = rs_db();
    $db->prepare("INSERT INTO play_reports (kind, app, who, account_id, show_name, verdict, sys, version, note, gpu, cpu, ram, specs, source, legacy_id, proton, created, updated)
        VALUES ('pc', ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, 'pcfb', ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE account_id = COALESCE(VALUES(account_id), account_id), show_name = 0, verdict = VALUES(verdict), sys = VALUES(sys), proton = VALUES(proton),
        version = VALUES(version), note = VALUES(note), gpu = IF(VALUES(gpu) <> '', VALUES(gpu), gpu), cpu = IF(VALUES(cpu) <> '', VALUES(cpu), cpu),
        ram = IF(VALUES(ram) > 0, VALUES(ram), ram), specs = IF(VALUES(specs) <> '', VALUES(specs), specs), hidden = 0, flags = 0, dev_seen = 0, updated = UTC_TIMESTAMP()")
        ->execute([$r['app'], $r['who'], $accountId, $r['verdict'], $r['sys'], $r['version'], $r['note'], mb_substr((string)($r['gpu'] ?? ''), 0, 80),
            mb_substr((string)($r['cpu'] ?? ''), 0, 80), min(32767, (int)($r['ram'] ?? 0)), (string)($r['specs'] ?? ''), $fbId, !empty($r['proton']) ? 1 : 0]);
    $st = $db->prepare("SELECT id FROM play_reports WHERE kind = 'pc' AND app = ? AND who = ?");
    $st->execute([$r['app'], $r['who']]);
    rs_play_scores_forget();
    return (int)$st->fetchColumn();
}

// A report and its recomp, or null.
function rs_play_report(int $rid): ?array {
    rs_play_tables();
    $st = rs_db()->prepare("SELECT * FROM play_reports WHERE id = ? AND kind = 'pc'");
    $st->execute([$rid]);
    return $st->fetch() ?: null;
}

// An answer on a report. $dev: the developer account answering, else the player ($accountId).
function rs_play_reply_add(array $report, string $text, ?array $dev, ?int $accountId): void {
    rs_db()->prepare('INSERT INTO play_report_replies (report_id, from_dev, dev_account_id, account_id, text, created) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([(int)$report['id'], $dev ? 1 : 0, $dev ? (int)$dev['id'] : null, $dev ? null : $accountId, $text]);
    // A player's answer is news to the developer again.
    if (!$dev) rs_db()->prepare('UPDATE play_reports SET dev_seen = 0 WHERE id = ?')->execute([(int)$report['id']]);
    $app = rs_pc_app((string)$report['app']);
    $game = $app ? (string)$app['name'] : (string)$report['app'];
    if ($dev) {
        // The player hears about it by email, when they have a confirmed address.
        if ($report['account_id'] === null) return;
        $acc = rs_acct_get((int)$report['account_id']);
        if (!$acc || $acc['banned'] || !(int)$acc['email_ok'] || (string)$acc['email'] === '') return;
        $who = (string)$dev['name'] !== '' ? (string)$dev['name'] : 'The developer';
        rs_play_later(fn() => rs_play_mail((string)$acc['email'], 'The developer of ' . $game . ' answered your report',
            $who . ' answered your report on ' . $game . ":\n\n" . $text . "\n\nSee it and answer back on your account page:\n" . rs_abs('/account?tab=reports') . "\n"));
        return;
    }
    foreach (rs_play_app_devs($app) as $d) {
        rs_play_later(fn() => rs_play_mail((string)$d['email'], 'A player answered you about ' . $game,
            'The player who sent a report on ' . $game . " answered you:\n\n" . $text . "\n\nAnswer them in your developer hub:\n" . rs_abs('/hub?tab=reports') . "\n"));
    }
}

// The developer accounts a PC recomp belongs to: its repo's owner (them, or an organization they're
// in, as last seen on GitHub), or it's on their developer page. Only ones with an email.
function rs_play_app_devs(?array $app): array {
    if (!$app || !function_exists('rs_dev_tables')) return [];
    rs_dev_tables();
    $owner = strtolower((string)strtok((string)$app['repo'], '/'));
    $out = [];
    foreach (rs_db()->query("SELECT * FROM dev_accounts WHERE status = 'approved' AND email <> ''")->fetchAll() as $d) {
        $mine = (string)$d['slug'] !== '' && (string)$app['dev'] === (string)$d['slug'];
        if (!$mine && $owner !== '' && (string)$d['gh_login'] !== '') {
            $mine = strtolower((string)$d['gh_login']) === $owner;
            if (!$mine && ($c = rs_kv_get('dev_orgs_' . $d['gh_id']))) $mine = in_array($owner, array_map('strtolower', (array)json_decode($c['v'], true)), true);
        }
        if ($mine && filter_var((string)$d['email'], FILTER_VALIDATE_EMAIL)) $out[] = $d;
    }
    return $out;
}

function rs_play_mail(string $to, string $subject, string $text): void {
    try {
        if (!rs_mail_ready()) return;
        rs_mail_send($to, $subject, $text);
        if (function_exists('rs_mail_log')) rs_mail_log('reports', $to, $subject, null, '');
    } catch (Throwable $e) {
        try { if (function_exists('rs_mail_log')) rs_mail_log('reports', $to, $subject, null, $e->getMessage()); } catch (Throwable $e2) { /* fine */ }
    }
}

// Runs after the answer is on its way, so emails don't hold up the page.
function rs_play_later(callable $fn): void {
    static $jobs = null;
    if ($jobs === null) {
        $jobs = new ArrayObject();
        $list = $jobs;
        register_shutdown_function(function () use ($list) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
            foreach ($list as $job) { try { $job(); } catch (Throwable $e) { error_log('play later: ' . $e->getMessage()); } }
        });
    }
    $jobs->append($fn);
}

// A new Has problems or Won't run report: its developer gets an email, at most one an hour for each
// recomp, with everything new since the last one. A recomp that had to wait is sent later
// (rs_play_dev_mail_after, from the pages players open).
function rs_play_dev_ping(string $app): void {
    try {
        $due = json_decode((string)(rs_kv_get('pcrep_due')['v'] ?? '[]'), true);
        $due = is_array($due) ? $due : [];
        if (!in_array($app, $due, true)) { $due[] = $app; rs_kv_put('pcrep_due', json_encode(array_values($due))); }
        rs_play_later('rs_play_dev_mail_due');
    } catch (Throwable $e) { /* the report is saved either way */ }
}

function rs_play_dev_mail_after(): void {
    static $set = false;
    if ($set) return;
    $set = true;
    $stamp = sys_get_temp_dir() . '/rs-pcrep-' . substr(md5(__DIR__), 0, 8);
    if (is_file($stamp) && time() - (int)@filemtime($stamp) < 300) return;
    @touch($stamp);
    rs_play_later('rs_play_dev_mail_due');
}

function rs_play_dev_mail_due(): void {
    static $ran = false;
    if ($ran) return;
    $ran = true;
    $due = json_decode((string)(rs_kv_get('pcrep_due')['v'] ?? '[]'), true);
    if (!is_array($due) || !$due) return;
    $left = [];
    foreach ($due as $app) {
        $app = (string)$app;
        $last = rs_kv_get('pcrep_mail_' . $app);
        if ($last && $last['age'] < 3600) { $left[] = $app; continue; }
        rs_play_dev_mail($app, $last ? (string)$last['v'] : gmdate('Y-m-d H:i:s', time() - 86400));
    }
    rs_kv_put('pcrep_due', json_encode($left));
}

// The email: what came in for one recomp since $since (UTC).
function rs_play_dev_mail(string $appId, string $since): void {
    rs_play_tables();
    $st = rs_db()->prepare("SELECT verdict, sys, version, note FROM play_reports WHERE kind = 'pc' AND app = ? AND hidden = 0 AND verdict IN ('issues', 'broken') AND updated > ? ORDER BY updated DESC LIMIT 50");
    $st->execute([$appId, $since]);
    $rows = $st->fetchAll();
    if (!$rows) return;
    rs_kv_put('pcrep_mail_' . $appId, gmdate('Y-m-d H:i:s'));
    $app = rs_pc_app($appId);
    $devs = rs_play_app_devs($app);
    if (!$devs) return;
    $game = (string)$app['name'];
    $n = count($rows);
    $lines = array_map(fn($r) => '- ' . (RS_PLAY_VERDICTS[$r['verdict']] ?? $r['verdict']) . ' · ' . (RS_PLAY_PC_SYS[$r['sys']] ?? $r['sys'])
        . ($r['version'] !== '' ? ' · ' . $r['version'] : '') . ($r['note'] !== '' ? ': ' . mb_strimwidth(preg_replace('/\s+/', ' ', (string)$r['note']) ?? '', 0, 200, '…') : ''), array_slice($rows, 0, 10));
    $text = ($n === 1 ? 'A player reported a problem with ' : $n . ' players reported problems with ') . $game . ":\n\n" . implode("\n", $lines)
        . ($n > 10 ? "\n…and " . ($n - 10) . ' more.' : '') . "\n\nRead them, download logs and answer in your developer hub:\n" . rs_abs('/hub?tab=reports')
        . "\n\nYou get at most one of these an hour for each of your recomps.\n";
    foreach ($devs as $d) rs_play_mail((string)$d['email'], ($n === 1 ? 'New report' : $n . ' new reports') . ' for ' . $game, $text);
}

// ---- Scores ---------------------------------------------------------------------------------------
// A report's points: Plays great 100, Has problems 70 down to 30 by how bad its problems are,
// Won't run 0. Its weight: newer counts more (half every 120 days), signed in a little more,
// a detailed one a little more, helpful votes a little more.
function rs_play_points(array $r): float {
    if ($r['verdict'] === 'runs') {
        $worst = 0;
        foreach (rs_play_problems_decode((string)$r['problems']) as $v) $worst = max($worst, $v);
        return $worst >= 3 ? 75.0 : ($worst === 2 ? 90.0 : 100.0);
    }
    if ($r['verdict'] === 'broken') return 0.0;
    $p = rs_play_problems_decode((string)$r['problems']);
    if (!$p) return 55.0;
    $worst = max($p);
    return $worst >= 3 ? 30.0 : ($worst === 2 ? 50.0 : 70.0);
}
function rs_play_weight(array $r, int $now): float {
    $age = max(0, $now - (int)strtotime((string)$r['updated'] . ' UTC')) / 86400;
    $w = pow(0.5, $age / 120);
    if ($r['account_id'] !== null) $w *= 1.25;
    $detail = ($r['progress'] !== '' ? 1 : 0) + ($r['fps'] !== '' ? 1 : 0) + ($r['problems'] !== '' || $r['verdict'] === 'runs' ? 1 : 0) + ($r['gpu'] !== '' ? 1 : 0) + ($r['fix'] !== '' || $r['note'] !== '' ? 1 : 0);
    $w *= 1 + 0.06 * $detail;
    $w *= 1 + min(0.5, 0.1 * (int)$r['helpful']);
    return $w;
}

function rs_play_scores_forget(): void { rs_set_setting('play_scores_at', '0'); }

// Every version's score: [kind => [app => [score, n, w, verdicts, sys => [sys => [score, n]], problems => [k => share], recent => n30, trend]]]
// Kept for five minutes; a new report clears it.
function rs_play_scores(): array {
    rs_play_sync_legacy();
    $cacheAt = (int)rs_setting('play_scores_at', '0');
    if (time() - $cacheAt < 300) {
        $c = json_decode((string)rs_setting('play_scores', ''), true);
        if (is_array($c)) return $c;
    }
    $now = time();
    $acc = [];
    $st = rs_db()->query('SELECT kind, app, account_id, verdict, sys, progress, fps, problems, gpu, fix, note, helpful, updated FROM play_reports WHERE hidden = 0');
    while ($r = $st->fetch()) {
        $k = $r['kind']; $a = $r['app'];
        $pts = rs_play_points($r); $w = rs_play_weight($r, $now);
        $age = ($now - (int)strtotime((string)$r['updated'] . ' UTC')) / 86400;
        $e = &$acc[$k][$a];
        $e ??= ['sw' => 0.0, 'w' => 0.0, 'n' => 0, 'v' => ['runs' => 0, 'issues' => 0, 'broken' => 0], 'sys' => [], 'pr' => [], 'n30' => 0, 'new' => [0.0, 0.0], 'old' => [0.0, 0.0]];
        $e['sw'] += $pts * $w; $e['w'] += $w; $e['n']++;
        if (isset($e['v'][$r['verdict']])) $e['v'][$r['verdict']]++;
        if ($r['sys'] !== '') { $s = &$e['sys'][$r['sys']]; $s ??= [0.0, 0.0, 0]; $s[0] += $pts * $w; $s[1] += $w; $s[2]++; unset($s); }
        foreach (array_keys(rs_play_problems_decode((string)$r['problems'])) as $p) $e['pr'][$p] = ($e['pr'][$p] ?? 0) + 1;
        if ($age <= 30) { $e['n30']++; $e['new'][0] += $pts; $e['new'][1]++; } else { $e['old'][0] += $pts; $e['old'][1]++; }
        unset($e);
    }
    // A Bayesian average: a version with few reports leans toward the middle until more come in.
    $prior = 60.0; $pw = 1.5;
    $out = [];
    foreach ($acc as $k => $apps) foreach ($apps as $a => $e) {
        $sys = [];
        foreach ($e['sys'] as $s => [$sw, $w, $n]) $sys[$s] = ['score' => (int)round(($sw + $prior * $pw) / ($w + $pw)), 'n' => $n];
        arsort($e['pr']);
        $pr = [];
        foreach (array_slice($e['pr'], 0, 4, true) as $p => $n) $pr[$p] = round($n / $e['n'], 2);
        $trend = ($e['new'][1] >= 2 && $e['old'][1] >= 2) ? (int)round($e['new'][0] / $e['new'][1] - $e['old'][0] / $e['old'][1]) : 0;
        $out[$k][$a] = ['score' => (int)round(($e['sw'] + $prior * $pw) / ($e['w'] + $pw)), 'n' => $e['n'], 'verdicts' => $e['v'],
            'sys' => $sys, 'problems' => $pr, 'recent' => $e['n30'], 'trend' => $trend];
    }
    rs_set_setting('play_scores', json_encode($out, JSON_UNESCAPED_SLASHES));
    rs_set_setting('play_scores_at', (string)time());
    return $out;
}

// ---- Versions of one game, side by side, with their badges and where they came from -----------------
// A repo's fork parent, from GitHub (kept a day).
function rs_play_fork_of(string $repo): string {
    if (!function_exists('rs_gh_cached') || !rs_repo_ok($repo)) return '';
    $r = rs_gh_cached($repo . '#fork', rs_gh_api() . '/repos/' . $repo, 86400, fn(array $j): array => [
        'fork' => (bool)($j['fork'] ?? false), 'parent' => (string)($j['parent']['full_name'] ?? ''), 'source' => (string)($j['source']['full_name'] ?? ''),
    ]);
    if ($r['status'] !== 200) return '';
    $j = json_decode($r['body'], true);
    return is_array($j) && !empty($j['fork']) ? (string)($j['parent'] ?? '') : '';
}

// Badges for the versions of a game. $rows: [id => [score, n, downloads, released, updated(ts)]]
function rs_play_badges(array $rows): array {
    $b = array_fill_keys(array_keys($rows), []);
    if (count($rows) < 2) return $b;
    $top = null; $most = null; $active = null;
    foreach ($rows as $id => $r) {
        if ($r['n'] >= 3 && ($top === null || $r['score'] > $rows[$top]['score'])) $top = $id;
        if ($r['downloads'] > 0 && ($most === null || $r['downloads'] > $rows[$most]['downloads'])) $most = $id;
        if ($r['updated'] > 0 && ($active === null || $r['updated'] > $rows[$active]['updated'])) $active = $id;
        if ($r['released'] > 0 && time() - $r['released'] < 75 * 86400 && $r['n'] >= 2 && $r['score'] >= 70) $b[$id][] = 'rising';
    }
    if ($top !== null) $b[$top][] = 'top';
    if ($most !== null) $b[$most][] = 'played';
    if ($active !== null && time() - $rows[$active]['updated'] < 45 * 86400) $b[$active][] = 'active';
    return $b;
}
