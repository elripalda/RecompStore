<?php
// (4.8) Request a Recomp: players ask for a project from RecompLauncher's Coming Soon page (POST
// api/pc.php a=recomp-request). One row per project with how many players asked, so the most wanted
// show first on Auto-add > Requests; each player's ask (and note) is kept under it.
declare(strict_types=1);

const RS_REQ_URL_MAX = 300;
const RS_REQ_NOTE_MAX = 500;
const RS_REQ_PER_INSTALL = 10;  // a day, per install (and per account when signed in)
const RS_REQ_PER_NETWORK = 30;  // a day
const RS_REQ_STATUS = ['new' => 'New', 'added' => 'Auto-added', 'listed' => 'Already listed', 'done' => 'Done', 'declined' => 'Declined'];

function rs_req_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec("CREATE TABLE IF NOT EXISTS pc_requests (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        url VARCHAR(300) NOT NULL,
        url_key VARCHAR(191) NOT NULL,
        asks INT UNSIGNED NOT NULL DEFAULT 1,
        status VARCHAR(12) NOT NULL DEFAULT 'new',
        app_id VARCHAR(64) NOT NULL DEFAULT '',
        handled_by VARCHAR(64) NOT NULL DEFAULT '',
        handled_at DATETIME NULL,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        UNIQUE KEY (url_key),
        INDEX (status, asks)
    ) CHARACTER SET utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS pc_request_asks (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        request_id INT UNSIGNED NOT NULL,
        install CHAR(64) NOT NULL,
        account_id INT UNSIGNED NULL,
        note VARCHAR(500) NOT NULL DEFAULT '',
        version VARCHAR(40) NOT NULL DEFAULT '',
        ip_hash CHAR(64) NOT NULL,
        created DATETIME NOT NULL,
        UNIQUE KEY (request_id, install),
        INDEX (install, created),
        INDEX (ip_hash, created)
    ) CHARACTER SET utf8mb4");
    $done = true;
}

// A project link as one key, so the same project asked for twice is one row: host without www,
// lowercase, no query, no trailing slash or .git. On GitHub and Codeberg only owner/name counts, so a
// link to its releases or a file is the same project.
function rs_req_key(string $url): string {
    $u = parse_url($url);
    $host = preg_replace('/^www\./', '', strtolower((string)($u['host'] ?? '')));
    $path = strtolower(trim((string)($u['path'] ?? ''), '/'));
    $path = (string)preg_replace('/\.git$/', '', $path);
    if (in_array($host, ['github.com', 'codeberg.org'], true)) $path = implode('/', array_slice(explode('/', $path), 0, 2));
    if ($host === 'gitlab.com') $path = (string)preg_replace('#/-/.*$#', '', $path);
    if ($host === 'sourceforge.net') $path = (string)preg_replace('#^(projects/[^/]+).*$#', '$1', $path);
    return substr($host . '/' . $path, 0, 191);
}

// A link the launcher sends, checked again here (Beta 41: GitHub or GitLab, typed as developer/project):
// https://github.com/<owner>/<repo> or https://gitlab.com/<group>/.../<project>, nothing else. A link to a
// page inside the project (its releases, a file) counts as the project. -> the clean link, or null.
function rs_req_clean(string $url): ?string {
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > RS_REQ_URL_MAX || preg_match('/\s/', $url)) return null;
    $u = parse_url($url);
    if (!$u || strtolower((string)($u['scheme'] ?? '')) !== 'https' || isset($u['user']) || isset($u['pass']) || isset($u['port'])) return null;
    $host = (string)preg_replace('/^www\./', '', strtolower((string)($u['host'] ?? '')));
    $parts = array_values(array_filter(explode('/', (string)preg_replace('#\.git$#i', '', trim((string)($u['path'] ?? ''), '/'))), 'strlen'));
    if ($host === 'github.com') {
        if (count($parts) < 2 || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $parts[0]) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $parts[1])) return null;
        return 'https://github.com/' . $parts[0] . '/' . (string)preg_replace('/\.git$/i', '', $parts[1]);
    }
    if ($host === 'gitlab.com') {
        $cut = array_search('-', $parts, true);
        if ($cut !== false) $parts = array_slice($parts, 0, $cut);
        if (count($parts) < 2) return null;
        foreach ($parts as $p) if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $p)) return null;
        return 'https://gitlab.com/' . implode('/', $parts);
    }
    return null;
}

// The recomp already on RecompLauncher for this link: [app, 'listed' | 'soon'], or null. Hidden
// listings aren't given away: they count as a new request.
function rs_req_known(string $key): ?array {
    foreach (rs_pc_apps() as $a) {
        if ((string)$a['repo'] === '' || !empty($a['hidden'])) continue;
        if (rs_req_key(rs_host_web(rs_pc_host($a), (string)$a['repo'])) === $key) return [$a, !empty($a['soon']) ? 'soon' : 'listed'];
    }
    return null;
}

// One player's ask. Returns '' when it went in, else the words the launcher shows as they are.
function rs_req_add(string $url, string $note, string $install, string $version, ?int $accountId): string {
    rs_req_tables();
    $db = rs_db();
    $clean = rs_req_clean($url);
    if ($clean === null) return 'Request a project on GitHub or GitLab.';
    $key = rs_req_key($clean);
    if ($known = rs_req_known($key)) return $known[1] === 'soon' ? 'It\'s already in RecompLauncher\'s Coming Soon. Press Notify Me on it to hear when it\'s out.' : 'It\'s already in RecompLauncher.';
    $who = hash('sha256', 'rq|' . $install);
    $ip = rs_ip_hash();
    $st = $db->prepare('SELECT COUNT(*) FROM pc_request_asks WHERE install = ? AND created > (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
    $st->execute([$who]);
    $many = (int)$st->fetchColumn() >= RS_REQ_PER_INSTALL;
    if (!$many && $accountId) {
        $st = $db->prepare('SELECT COUNT(*) FROM pc_request_asks WHERE account_id = ? AND created > (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
        $st->execute([$accountId]);
        $many = (int)$st->fetchColumn() >= RS_REQ_PER_INSTALL;
    }
    if ($many) return 'That\'s a lot of requests for today. Try again tomorrow.';
    $st = $db->prepare('SELECT COUNT(*) FROM pc_request_asks WHERE ip_hash = ? AND created > (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
    $st->execute([$ip]);
    if ((int)$st->fetchColumn() >= RS_REQ_PER_NETWORK) return 'Too many requests from your network today. Try again tomorrow.';
    $st = $db->prepare('SELECT id, status FROM pc_requests WHERE url_key = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    if ($row) {
        $mine = $db->prepare('SELECT 1 FROM pc_request_asks WHERE request_id = ? AND install = ?');
        $mine->execute([(int)$row['id'], $who]);
        if ($row['status'] === 'declined') return 'We looked at this one already and it can\'t go on RecompLauncher.';
        if ($mine->fetchColumn()) return ''; // the same player again: fine, but still one vote
        $rid = (int)$row['id'];
        $db->prepare('UPDATE pc_requests SET asks = asks + 1, updated = UTC_TIMESTAMP() WHERE id = ?')->execute([$rid]);
    } else {
        $db->prepare("INSERT INTO pc_requests (url, url_key, asks, status, created, updated) VALUES (?, ?, 1, 'new', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([$clean, $key]);
        $rid = (int)$db->lastInsertId();
    }
    $db->prepare('INSERT INTO pc_request_asks (request_id, install, account_id, note, version, ip_hash, created) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
        ->execute([$rid, $who, $accountId, mb_substr(trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\r\n", "\n", $note))), 0, RS_REQ_NOTE_MAX), mb_substr((string)preg_replace('/[^\w.+-]/', '', $version), 0, 40), $ip]);
    return '';
}

function rs_req_new_count(): int {
    try { rs_req_tables(); return (int)rs_db()->query("SELECT COUNT(*) FROM pc_requests WHERE status = 'new'")->fetchColumn(); } catch (Throwable $e) { return 0; }
}

// The list for Auto-add > Requests: most asked first, each with its newest notes.
function rs_req_list(bool $handled, int $limit = 200): array {
    rs_req_tables();
    $db = rs_db();
    $rows = $db->query('SELECT * FROM pc_requests WHERE status ' . ($handled ? "<> 'new' ORDER BY handled_at DESC" : "= 'new' ORDER BY asks DESC, updated DESC") . ' LIMIT ' . max(1, $limit))->fetchAll();
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $notes = [];
    $signed = [];
    $people = [];
    $asks = $db->query('SELECT request_id, install, note, account_id, version, created FROM pc_request_asks WHERE request_id IN (' . implode(',', $ids) . ') ORDER BY created DESC')->fetchAll();
    // (4.8) Who asked: the account's username when they were signed in, otherwise their install (shortened).
    $accIds = array_values(array_unique(array_filter(array_map(fn($n) => (int)$n['account_id'], $asks))));
    $names = $accIds ? $db->query('SELECT id, username FROM accounts WHERE id IN (' . implode(',', $accIds) . ')')->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    foreach ($asks as $n) {
        $rid = (int)$n['request_id'];
        if ($n['account_id'] !== null) $signed[$rid] = ($signed[$rid] ?? 0) + 1;
        $who = $n['account_id'] !== null && (string)($names[(int)$n['account_id']] ?? '') !== '' ? (string)$names[(int)$n['account_id']] : 'Player ' . substr((string)$n['install'], 0, 6);
        if (trim((string)$n['note']) !== '' && count($notes[$rid] ?? []) < 5) $notes[$rid][] = ['note' => (string)$n['note'], 'at' => (string)$n['created'], 'who' => $who, 'version' => (string)$n['version']];
        if (count($people[$rid] ?? []) < 20) $people[$rid][] = ['who' => $who, 'signed' => $n['account_id'] !== null, 'version' => (string)$n['version'], 'at' => (string)$n['created'], 'note' => (string)$n['note']];
    }
    foreach ($rows as &$r) { $r['notes'] = $notes[(int)$r['id']] ?? []; $r['signed'] = $signed[(int)$r['id']] ?? 0; $r['people'] = $people[(int)$r['id']] ?? []; }
    return $rows;
}

// (4.8) A request gone for good, with every player's ask.
function rs_req_delete(int $id): void {
    rs_req_tables();
    rs_db()->prepare('DELETE FROM pc_request_asks WHERE request_id = ?')->execute([$id]);
    rs_db()->prepare('DELETE FROM pc_requests WHERE id = ?')->execute([$id]);
}

function rs_req_get(int $id): ?array {
    rs_req_tables();
    $st = rs_db()->prepare('SELECT * FROM pc_requests WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function rs_req_mark(int $id, string $status, string $appId = ''): void {
    if (!isset(RS_REQ_STATUS[$status])) throw new RuntimeException('Unknown status.');
    rs_req_tables();
    rs_db()->prepare('UPDATE pc_requests SET status = ?, app_id = ?, handled_by = ?, handled_at = ' . ($status === 'new' ? 'NULL' : 'UTC_TIMESTAMP()') . ', updated = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([$status, $appId, $status === 'new' ? '' : rs_me_name(), $id]);
}
