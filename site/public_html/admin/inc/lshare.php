<?php
// RecompLauncher (3.1g): download links you hand out yourself, outside the invite waves. Each one
// stops working at its time limit, after its number of downloads, or when you turn it off. It
// always gives the current Windows build.
declare(strict_types=1);

const RS_LS_SHARE_TIMES = ['1h' => ['1 hour', 3600], '24h' => ['24 hours', 86400], '3d' => ['3 days', 259200], '7d' => ['7 days', 604800], '30d' => ['30 days', 2592000]];

function rs_lsh_tables(): void {
    static $done = false;
    if ($done) return;
    rs_db()->exec('CREATE TABLE IF NOT EXISTS launcher_share (
        id INT AUTO_INCREMENT PRIMARY KEY,
        token CHAR(32) NOT NULL UNIQUE,
        label VARCHAR(80) NOT NULL DEFAULT \'\',
        expires DATETIME NOT NULL,
        max_dl INT NOT NULL DEFAULT 0,
        opens INT NOT NULL DEFAULT 0,
        downloads INT NOT NULL DEFAULT 0,
        off TINYINT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $done = true;
}

function rs_lsh_url(string $token): string { return 'https://recomplauncher.com/api/launcher-share.php?k=' . $token; }

function rs_lsh_make(string $label, string $time, int $max): array {
    rs_lsh_tables();
    if (!isset(RS_LS_SHARE_TIMES[$time])) throw new RuntimeException('Pick how long the link lasts.');
    $tok = bin2hex(random_bytes(16));
    rs_db()->prepare('INSERT INTO launcher_share (token, label, expires, max_dl, created) VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), ?, UTC_TIMESTAMP())')
        ->execute([$tok, mb_substr(trim($label), 0, 80), RS_LS_SHARE_TIMES[$time][1], max(0, min(100000, $max))]);
    return ['token' => $tok, 'url' => rs_lsh_url($tok)];
}

function rs_lsh_all(): array {
    rs_lsh_tables();
    return rs_db()->query('SELECT *, expires <= UTC_TIMESTAMP() AS gone FROM launcher_share ORDER BY id DESC LIMIT 50')->fetchAll();
}

function rs_lsh_get(string $token): ?array {
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) return null;
    rs_lsh_tables();
    $st = rs_db()->prepare('SELECT *, expires <= UTC_TIMESTAMP() AS gone, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires) AS secs FROM launcher_share WHERE token = ?');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

// Why a link doesn't work any more, or '' while it does.
function rs_lsh_dead(array $s): string {
    if ((int)$s['off']) return 'This link was turned off.';
    if ((int)$s['gone']) return 'This link has expired.';
    if ((int)$s['max_dl'] > 0 && (int)$s['downloads'] >= (int)$s['max_dl']) return 'This link has been used up.';
    return '';
}

function rs_lsh_left(int $secs): string {
    if ($secs >= 86400) { $d = (int)round($secs / 86400); return $d . ($d === 1 ? ' day' : ' days'); }
    if ($secs >= 3600) { $h = (int)round($secs / 3600); return $h . ($h === 1 ? ' hour' : ' hours'); }
    $m = max(1, (int)round($secs / 60)); return $m . ($m === 1 ? ' minute' : ' minutes');
}
