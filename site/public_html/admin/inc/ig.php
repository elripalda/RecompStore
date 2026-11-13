<?php
// RecompStore (4.8): Instant Gaming on recomplauncher.com. A recomp's page can show where to buy the
// original game, through our partner link (igr). Which listings show it, and with what name, platform
// and price, is set by hand on Sponsors > Instant Gaming: there's no catalog feed to match against.
// The card can sit in a few places on the page; visitors are split between the versions being tested
// (each keeps the one they got first), and each version counts its views and clicks by day.
declare(strict_types=1);

const RS_IG_PLACES = [
    'side-top' => ['Side, top', 'a small card above the download box'],
    'side-bottom' => ['Side, bottom', 'the same card, under the download box'],
    'wide' => ['Wide strip', 'a long strip in the main column, above the tabs'],
];
const RS_IG_BUTTONS = [
    'hover' => 'Orange on hover',
    'orange' => 'Always orange',
];
const RS_IG_IDS = ['a', 'b', 'c', 'd'];

function rs_ig_tables(): void {
    static $done = false;
    if ($done) return;
    $db = rs_db();
    $db->exec('CREATE TABLE IF NOT EXISTS ig_listings (
        app_id VARCHAR(80) NOT NULL PRIMARY KEY,
        url VARCHAR(500) NOT NULL,
        title VARCHAR(160) NOT NULL,
        platform VARCHAR(60) NOT NULL DEFAULT \'\',
        price DECIMAL(8,2) NULL,
        old_price DECIMAL(8,2) NULL,
        currency CHAR(3) NOT NULL DEFAULT \'USD\',
        tag TINYINT NOT NULL DEFAULT 0,
        paused TINYINT NOT NULL DEFAULT 0,
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL
    ) CHARACTER SET utf8mb4');
    $db->exec('CREATE TABLE IF NOT EXISTS ig_days (
        day DATE NOT NULL,
        round INT NOT NULL,
        app_id VARCHAR(80) NOT NULL,
        variant CHAR(1) NOT NULL,
        views INT NOT NULL DEFAULT 0,
        clicks INT NOT NULL DEFAULT 0,
        PRIMARY KEY (day, round, app_id, variant),
        KEY round (round, variant)
    ) CHARACTER SET utf8mb4');
    $done = true;
}

// The settings, with defaults filled in. on: shown to visitors at all. igr: our partner code.
// variants: the versions being tested. round: goes up when a new test starts (stats and visitors'
// versions start over). price: show prices. tag: show the discount tag; tagMin: only from this %.
function rs_ig_defaults(): array {
    return ['on' => false, 'igr' => 'recomp', 'price' => true, 'tag' => true, 'tagMin' => 10, 'round' => 1, 'roundAt' => gmdate('Y-m-d'),
        'variants' => [
            ['id' => 'a', 'name' => 'Side, top', 'place' => 'side-top', 'btn' => 'hover', 'share' => 50, 'on' => true],
            ['id' => 'b', 'name' => 'Wide strip', 'place' => 'wide', 'btn' => 'hover', 'share' => 50, 'on' => true],
        ]];
}

function rs_ig_cfg(): array {
    $d = rs_ig_defaults();
    $c = json_decode((string)rs_setting('ig_cfg', ''), true);
    if (!is_array($c)) return $d;
    $c += $d;
    $vs = [];
    foreach ((array)$c['variants'] as $v) {
        if (!is_array($v) || !in_array($v['id'] ?? '', RS_IG_IDS, true)) continue;
        $vs[] = ['id' => $v['id'], 'name' => mb_substr(trim((string)($v['name'] ?? '')), 0, 60) ?: 'Version ' . strtoupper($v['id']),
            'place' => isset(RS_IG_PLACES[$v['place'] ?? '']) ? $v['place'] : 'side-top', 'btn' => isset(RS_IG_BUTTONS[$v['btn'] ?? '']) ? $v['btn'] : 'hover',
            'share' => max(0, min(100, (int)($v['share'] ?? 0))), 'on' => !empty($v['on'])];
    }
    $c['variants'] = $vs ?: $d['variants'];
    $c['on'] = !empty($c['on']);
    $c['price'] = !empty($c['price']);
    $c['tag'] = !empty($c['tag']);
    $c['tagMin'] = max(0, min(90, (int)$c['tagMin']));
    $c['round'] = max(1, (int)$c['round']);
    $c['igr'] = preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string)$c['igr']) ? (string)$c['igr'] : 'recomp';
    return $c;
}

function rs_ig_save(array $c): void {
    rs_set_setting('ig_cfg', json_encode($c, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

// The versions visitors can get right now (on, with a share above 0).
function rs_ig_live_variants(array $c): array {
    return array_values(array_filter($c['variants'], fn($v) => $v['on'] && $v['share'] > 0));
}

// An Instant Gaming product page, cleaned: https, their domain, no query or fragment.
function rs_ig_clean_url(string $u): string {
    $u = trim($u);
    if (!preg_match('~^https://(www\.)?instant-gaming\.com/[^\s?#]+~i', $u, $m)) return '';
    return strlen($m[0]) <= 400 ? $m[0] : '';
}

// Where a click goes: the product page with our partner code on it.
function rs_ig_link(string $url, string $igr): string {
    return $url . (str_contains($url, '?') ? '&' : '?') . 'igr=' . rawurlencode($igr);
}

// How much off, in whole percent (0 when there's no older price).
function rs_ig_pct($price, $old): int {
    $p = (float)$price; $o = (float)$old;
    return ($o > 0 && $p > 0 && $p < $o) ? (int)round(($o - $p) / $o * 100) : 0;
}

// Whether a listing shows its discount (the tag and the crossed-out price). tag: 0 = by the rule
// (the tag is on and the discount reaches tagMin), 1 = always, 2 = never.
function rs_ig_shows_deal(array $l, array $c): bool {
    $pct = rs_ig_pct($l['price'] ?? null, $l['old_price'] ?? null);
    if ($pct <= 0 || !$c['tag'] || (int)$l['tag'] === 2) return false;
    return (int)$l['tag'] === 1 || $pct >= $c['tagMin'];
}

function rs_ig_listings(): array {
    rs_ig_tables();
    return rs_db()->query('SELECT * FROM ig_listings ORDER BY paused, title')->fetchAll();
}

// What the site needs, for /api/ig.php: the switch, the versions and the listings (paused ones too,
// so a peek can show them; the site leaves them out otherwise).
function rs_ig_public(): array {
    $c = rs_ig_cfg();
    $sym = ['USD' => '$', 'EUR' => '€', 'GBP' => '£'];
    $l = [];
    foreach (rs_ig_listings() as $r) {
        $deal = rs_ig_shows_deal($r, $c);
        $cur = $sym[$r['currency']] ?? '$';
        $fmt = fn($v) => $v === null || (float)$v <= 0 ? '' : ($r['currency'] === 'EUR' ? number_format((float)$v, 2, ',', '') . ' €' : $cur . number_format((float)$v, 2));
        $l[$r['app_id']] = ['t' => (string)$r['title'], 'p' => (string)$r['platform'],
            'price' => $c['price'] ? $fmt($r['price']) : '', 'old' => $c['price'] && $deal ? $fmt($r['old_price']) : '',
            'pct' => $c['price'] && $deal ? rs_ig_pct($r['price'], $r['old_price']) : 0, 'off' => (bool)$r['paused']];
    }
    $v = array_map(fn($x) => ['id' => $x['id'], 'place' => $x['place'], 'btn' => $x['btn'], 'share' => $x['share']], rs_ig_live_variants($c));
    $all = array_map(fn($x) => ['id' => $x['id'], 'place' => $x['place'], 'btn' => $x['btn']], $c['variants']);
    return ['on' => $c['on'], 'round' => $c['round'], 'v' => $v, 'all' => $all, 'l' => $l];
}

// One view or click for a listing under a version, in this round.
function rs_ig_count(string $appId, string $variant, string $col): void {
    rs_ig_tables();
    $col = $col === 'clicks' ? 'clicks' : 'views';
    $c = rs_ig_cfg();
    rs_db()->prepare("INSERT INTO ig_days (day, round, app_id, variant, $col) VALUES (UTC_DATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE $col = $col + 1")
        ->execute([$c['round'], $appId, $variant]);
}

// This round's numbers: by version, by listing, and by listing and version.
function rs_ig_stats(int $round): array {
    rs_ig_tables();
    $by = ['v' => [], 'l' => [], 'lv' => [], 'days' => []];
    $st = rs_db()->prepare('SELECT app_id, variant, SUM(views) views, SUM(clicks) clicks FROM ig_days WHERE round = ? GROUP BY app_id, variant');
    $st->execute([$round]);
    foreach ($st->fetchAll() as $r) {
        foreach ([['v', $r['variant']], ['l', $r['app_id']], ['lv', $r['app_id'] . '|' . $r['variant']]] as [$k, $key]) {
            $by[$k][$key]['views'] = ($by[$k][$key]['views'] ?? 0) + (int)$r['views'];
            $by[$k][$key]['clicks'] = ($by[$k][$key]['clicks'] ?? 0) + (int)$r['clicks'];
        }
    }
    $st = rs_db()->prepare('SELECT day, variant, SUM(views) views, SUM(clicks) clicks FROM ig_days WHERE round = ? AND day >= UTC_DATE() - INTERVAL 13 DAY GROUP BY day, variant ORDER BY day');
    $st->execute([$round]);
    foreach ($st->fetchAll() as $r) $by['days'][$r['day']][$r['variant']] = ['views' => (int)$r['views'], 'clicks' => (int)$r['clicks']];
    return $by;
}

function rs_ig_rate(array $n): string {
    $v = (int)($n['views'] ?? 0);
    return $v ? number_format(($n['clicks'] ?? 0) / $v * 100, 2) . '%' : '–';
}
