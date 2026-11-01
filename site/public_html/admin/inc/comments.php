<?php
// (3.0) Comments on every game page (PC recomps and PS5 apps), for signed-in players: replies one
// level deep, Helpful votes, a Developer badge on the creator's own game, and Report. Three reports
// hide a comment until the admin looks (Accounts > Moderation). Links to ROMs, ISOs and file hosts
// are refused when posting.
declare(strict_types=1);

const RS_CM_BLOCKED_HOSTS = ['vimm.net', 'romsfun.com', 'cdromance.org', 'cdromance.com', 'myrient.erista.me', 'emuparadise.me', 'coolrom.com', 'coolrom.com.au',
    'romspedia.com', 'wowroms.com', 'romsgames.net', 'romspure.cc', 'nopaystation.com', 'mega.nz', 'mega.io', 'mediafire.com', '1fichier.com', 'pixeldrain.com',
    'gofile.io', 'rapidgator.net', 'uptobox.com', 'nitroflare.com', 'krakenfiles.com', 'workupload.com', 'buzzheavier.com', 'romhustler.org', 'retrostic.com',
    'thepiratebay.org', '1337x.to', 'rutracker.org', 'nxbrew.net', 'hexrom.com', 'romulation.org', 'edgeemu.net', 'ziperto.com'];

function rs_cm_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        game VARCHAR(80) NOT NULL,
        account_id INT NOT NULL,
        parent_id INT NULL,
        body TEXT NOT NULL,
        created DATETIME NOT NULL,
        helpful INT NOT NULL DEFAULT 0,
        reports INT NOT NULL DEFAULT 0,
        hidden TINYINT NOT NULL DEFAULT 0,
        removed TINYINT NOT NULL DEFAULT 0,
        KEY game (game, parent_id, created),
        KEY author (account_id, created)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS comment_votes (
        comment_id INT NOT NULL,
        account_id INT NOT NULL,
        PRIMARY KEY (comment_id, account_id),
        KEY account (account_id)
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS comment_reports (
        comment_id INT NOT NULL,
        account_id INT NOT NULL,
        reason VARCHAR(20) NOT NULL,
        created DATETIME NOT NULL,
        PRIMARY KEY (comment_id, account_id)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// Why a comment can't go up as written, or null.
function rs_cm_body_error(string $body): ?string {
    $len = mb_strlen($body);
    if ($len < 2) return 'Write a little more.';
    if ($len > 2000) return 'Keep it under 2,000 characters.';
    preg_match_all('#\b(?:https?://|www\.)[^\s<>"\')\]]+#i', $body, $m);
    if (count($m[0]) > 3) return 'Three links at most.';
    foreach ($m[0] as $u) {
        $url = str_starts_with(strtolower($u), 'www.') ? 'https://' . $u : $u;
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
        $path = strtolower((string)parse_url($url, PHP_URL_PATH));
        foreach (RS_CM_BLOCKED_HOSTS as $b) if ($host === $b || str_ends_with($host, '.' . $b)) return 'Links to ROM, ISO and file-sharing sites aren\'t allowed here.';
        if (preg_match('#\.(iso|xiso|xex|pkg|wbfs|rvz|nsp|xci|cia|3ds|nds|z64|n64|v64|sfc|smc|gba|gbc|cso|chd|7z|rar)(\b|$)#', $path)) return 'Links to game files aren\'t allowed here.';
        if (($host === 'archive.org' || str_ends_with($host, '.archive.org')) && str_contains($path, '/download/')) return 'Links to game files aren\'t allowed here.';
    }
    return null;
}

// A comment's author as shown: username and badge ('team' for the admin's own accounts,
// 'dev' when they made this game).
function rs_cm_badge(array $acc, string $game): string {
    $staff = array_filter(array_map('trim', explode(',', strtolower((string)rs_setting('acct_staff', '')))));
    if ($acc['username_lc'] !== null && in_array($acc['username_lc'], $staff, true)) return 'team';
    $dev = rs_acct_dev($acc);
    if (!$dev) return '';
    [$kind, $id] = explode(':', $game, 2);
    try {
        $apps = $kind === 'pc' ? (function_exists('rs_dev_pc_apps') ? rs_dev_pc_apps($dev) : []) : (function_exists('rs_dev_apps') ? rs_dev_apps($dev) : []);
        foreach ($apps as $x) if ((string)($x['id'] ?? '') === $id) return 'dev';
    } catch (Throwable $e) { return ''; }
    return '';
}

// Every comment on a game, top ones with their replies. $me: the signed-in account (for Helpful).
function rs_cm_list(string $game, ?array $me, string $sort = 'helpful'): array {
    rs_cm_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT c.*, a.username, a.username_lc, a.uid, a.github_id, a.email, a.email_ok, a.avatar, a.id AS aid FROM comments c LEFT JOIN accounts a ON a.id = c.account_id
        WHERE c.game = ? AND (c.hidden = 0 OR c.account_id = ?) ORDER BY c.created ASC LIMIT 600');
    $st->execute([$game, $me ? (int)$me['id'] : 0]);
    $rows = $st->fetchAll();
    $voted = [];
    if ($me && $rows) {
        $v = $db->prepare('SELECT comment_id FROM comment_votes WHERE account_id = ? AND comment_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')');
        $v->execute([(int)$me['id']]);
        foreach ($v->fetchAll() as $r) $voted[(int)$r['comment_id']] = true;
    }
    $badges = [];
    $shape = function (array $r) use ($me, $voted, $game, &$badges): array {
        $gone = $r['aid'] === null;
        if (!$gone && !isset($badges[$r['aid']])) $badges[$r['aid']] = rs_cm_badge(['id' => (int)$r['aid'], 'username_lc' => $r['username_lc'], 'github_id' => $r['github_id'], 'email' => $r['email'], 'email_ok' => $r['email_ok']], $game);
        return ['id' => (int)$r['id'], 'user' => $gone ? null : (string)$r['username'], 'badge' => $gone ? '' : $badges[$r['aid']], 'avatar' => $gone ? '' : rs_acct_avatar_url(['avatar' => $r['avatar']]),
            'body' => $r['removed'] ? '' : (string)$r['body'], 'removed' => (bool)$r['removed'], 'held' => (bool)$r['hidden'],
            'created' => gmdate('c', strtotime((string)$r['created'] . ' UTC')), 'helpful' => (int)$r['helpful'],
            'mine' => $me && (int)$r['account_id'] === (int)$me['id'], 'voted' => isset($voted[(int)$r['id']]), 'replies' => []];
    };
    $top = []; $kids = [];
    foreach ($rows as $r) if ($r['parent_id'] === null) $top[(int)$r['id']] = $shape($r); else $kids[(int)$r['parent_id']][] = $shape($r);
    foreach ($kids as $pid => $list) if (isset($top[$pid])) $top[$pid]['replies'] = $list;
    // A removed comment with no replies left under it isn't shown at all.
    $top = array_filter($top, fn($c) => !$c['removed'] || $c['replies']);
    $top = array_values($top);
    usort($top, $sort === 'new' ? fn($x, $y) => strcmp($y['created'], $x['created']) : fn($x, $y) => [$y['helpful'], $y['created']] <=> [$x['helpful'], $x['created']]);
    return $top;
}

function rs_cm_count(string $game): int {
    rs_cm_tables();
    $st = rs_db()->prepare('SELECT COUNT(*) FROM comments WHERE game = ? AND hidden = 0 AND removed = 0');
    $st->execute([$game]);
    return (int)$st->fetchColumn();
}

// ---- (3.0) Player reports for PS5 apps: plays great / has problems / won't run, on which firmware.
// The same as RecompLauncher's, one per person (signed-in account, else the connection) per app.
const RS_PS5_FW = ['1' => '1.xx', '2' => '2.xx', '3' => '3.xx', '4' => '4.xx', '5' => '5.xx', '6' => '6.xx', '7' => '7.xx', '8' => '8.xx', '9' => '9.xx', '10' => '10.xx', '11' => '11.xx', '12' => '12.xx', '13' => '13.xx', 'other' => 'Not sure'];
const RS_PS5_VERDICTS = ['runs' => 'Plays great', 'issues' => 'Has problems', 'broken' => 'Won\'t run'];

function rs_ps5fb_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS ps5_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        app VARCHAR(32) NOT NULL,
        verdict VARCHAR(8) NOT NULL,
        fw VARCHAR(6) NOT NULL DEFAULT \'\',
        version VARCHAR(40) NOT NULL DEFAULT \'\',
        note VARCHAR(600) NOT NULL DEFAULT \'\',
        who CHAR(64) NOT NULL,
        account_id INT NULL,
        created DATETIME NOT NULL,
        hidden TINYINT NOT NULL DEFAULT 0,
        KEY app (app, hidden, created),
        UNIQUE KEY one (app, who)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_ps5fb_counts(): array {
    rs_ps5fb_tables();
    $out = [];
    foreach (rs_db()->query('SELECT app, verdict, COUNT(*) n FROM ps5_feedback WHERE hidden = 0 GROUP BY app, verdict')->fetchAll() as $r)
        $out[(string)$r['app']][(string)$r['verdict']] = (int)$r['n'];
    return $out;
}

function rs_ps5fb_list(string $app, bool $withHidden = false, int $limit = 60): array {
    rs_ps5fb_tables();
    $st = rs_db()->prepare('SELECT f.id, f.verdict, f.fw, f.version, f.note, f.created, f.hidden, a.username FROM ps5_feedback f LEFT JOIN accounts a ON a.id = f.account_id
        WHERE f.app = ?' . ($withHidden ? '' : ' AND f.hidden = 0') . ' ORDER BY f.created DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute([$app]);
    return $st->fetchAll();
}

function rs_ps5fb_recent(int $limit = 50): array {
    rs_ps5fb_tables();
    return rs_db()->query('SELECT f.*, a.username FROM ps5_feedback f LEFT JOIN accounts a ON a.id = f.account_id ORDER BY f.created DESC LIMIT ' . max(1, min(200, $limit)))->fetchAll();
}

// (3.1) Player reports and version scores.
require_once __DIR__ . '/play.php';
require_once __DIR__ . '/mailq.php';
