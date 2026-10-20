<?php
// Recomp Store website (2.1): what the Analytics page counts, day by day. Consoles that opened the
// store, installs, updates and removals per app, beta downloads, and the website's visits. No
// cookies and no third parties: a website visitor is a code made from their address and browser
// with a salt that changes every day, so the same person can't be followed from one day to the next.
declare(strict_types=1);

function rs_analytics_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS console_days (day DATE NOT NULL, install_id CHAR(16) NOT NULL, PRIMARY KEY (day, install_id)) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS app_events (day DATE NOT NULL, app_id VARCHAR(64) NOT NULL, kind VARCHAR(8) NOT NULL, n INT NOT NULL DEFAULT 0, PRIMARY KEY (day, app_id, kind)) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS beta_downloads (day DATE NOT NULL, game_id VARCHAR(64) NOT NULL, install_id CHAR(16) NOT NULL, PRIMARY KEY (day, game_id, install_id)) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS site_pages (day DATE NOT NULL, path VARCHAR(120) NOT NULL, views INT NOT NULL DEFAULT 0, PRIMARY KEY (day, path)) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS site_visitors (day DATE NOT NULL, vhash CHAR(16) NOT NULL, device VARCHAR(8) NOT NULL, ref VARCHAR(80) NOT NULL, hits INT NOT NULL DEFAULT 1, PRIMARY KEY (day, vhash)) CHARACTER SET utf8mb4');
    $done = true;
}

// A console opened the store today (called with every request it makes; one row a day).
function rs_count_console(PDO $db, string $install): void {
    static $seen = [];
    if (isset($seen[$install])) return;
    $seen[$install] = true;
    try {
        rs_analytics_tables();
        $db->prepare('INSERT IGNORE INTO console_days (day, install_id) VALUES (UTC_DATE(), ?)')->execute([$install]);
    } catch (Throwable $e) {
    }
}

// An install (first time on that console), an update (a new version over one) or a removal.
function rs_count_app(PDO $db, string $app, string $kind): void {
    try {
        rs_analytics_tables();
        $db->prepare('INSERT INTO app_events (day, app_id, kind, n) VALUES (UTC_DATE(), ?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$app, $kind]);
    } catch (Throwable $e) {
    }
}

function rs_count_beta_download(PDO $db, string $game, string $install): void {
    try {
        rs_analytics_tables();
        $db->prepare('INSERT IGNORE INTO beta_downloads (day, game_id, install_id) VALUES (UTC_DATE(), ?, ?)')->execute([$game, $install]);
    } catch (Throwable $e) {
    }
}

function rs_device_kind(string $ua): string {
    if (preg_match('/PlayStation 5|PS5/i', $ua)) return 'ps5';
    if (preg_match('/iPad|Tablet/i', $ua)) return 'tablet';
    if (preg_match('/Mobi|iPhone|Android/i', $ua)) return 'phone';
    return 'computer';
}

function rs_is_bot(string $ua): bool {
    return $ua === '' || (bool)preg_match('/bot|crawl|spider|slurp|preview|fetch|monitor|curl|wget|python|headless|lighthouse/i', $ua);
}

// A page of the website was shown. $path is the page (#/game/X becomes /game/X), $ref where the
// visitor came from (another site's address; only its host is kept).
function rs_count_visit(string $path, string $ref): void {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (rs_is_bot($ua)) return;
    $path = '/' . ltrim((string)preg_replace('#[^A-Za-z0-9/_.-]#', '', $path), '/');
    $path = (string)preg_replace('#^/(l|r)/.*$#', '/$1', $path); // link and report codes aren't kept
    $path = substr($path, 0, 120);
    $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
    $self = strtolower((string)parse_url(rs_setting('site_url', 'https://recompstore.com'), PHP_URL_HOST));
    $host = preg_replace('/^www\./', '', $host) ?? '';
    if ($host === '' || $host === $self || $host === preg_replace('/^www\./', '', (string)($_SERVER['HTTP_HOST'] ?? ''))) $host = 'direct';
    $salt = rs_setting('visit_salt_' . gmdate('Ymd'), '');
    if ($salt === '') {
        $salt = bin2hex(random_bytes(16));
        rs_set_setting('visit_salt_' . gmdate('Ymd'), $salt);
        // Yesterday's and older salts go, so old codes can never be remade.
        try { rs_db()->exec("DELETE FROM settings WHERE k LIKE 'visit\\_salt\\_%' AND k <> " . rs_db()->quote('visit_salt_' . gmdate('Ymd'))); } catch (Throwable $e) {}
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $vhash = substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 16);
    try {
        rs_analytics_tables();
        $db = rs_db();
        $st = $db->prepare('INSERT INTO site_visitors (day, vhash, device, ref, hits) VALUES (UTC_DATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1');
        $st->execute([$vhash, rs_device_kind($ua), substr($host, 0, 80)]);
        $h = $db->prepare('SELECT hits FROM site_visitors WHERE day = UTC_DATE() AND vhash = ?');
        $h->execute([$vhash]);
        if ((int)$h->fetchColumn() > 500) return; // one visitor hammering the site counts once it calms down
        $db->prepare('INSERT INTO site_pages (day, path, views) VALUES (UTC_DATE(), ?, 1) ON DUPLICATE KEY UPDATE views = views + 1')->execute([$path]);
    } catch (Throwable $e) {
    }
}

// ---- What the Analytics page reads -----------------------------------------------------------

// One value per day from $from to today (UTC), zeros where nothing happened.
function rs_series(string $sql, array $args, int $days): array {
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) $out[gmdate('Y-m-d', time() - $i * 86400)] = 0;
    try {
        $st = rs_db()->prepare($sql);
        $st->execute($args);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as [$d, $n]) if (isset($out[(string)$d])) $out[(string)$d] = (int)$n;
    } catch (Throwable $e) {
    }
    return $out;
}
