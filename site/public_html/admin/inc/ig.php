<?php
// RecompStore (4.8): Instant Gaming on recomplauncher.com. A recomp's page can show where to buy the
// original game, through our partner link (igr). (4.9) A card belongs to a game: one listing covers every
// version of it (its title group, key "t:<title id>"), or a single recomp (its id) when it has no group.
// Name, platform, price and stock are read from the Instant Gaming page itself and kept up to date.
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
    // (4.9) Read from the page: auto (keep price and stock updated), region, in stock, when last read.
    foreach (['auto TINYINT NOT NULL DEFAULT 1', 'region VARCHAR(60) NOT NULL DEFAULT \'\'', 'stock TINYINT NOT NULL DEFAULT 1',
        'fetched DATETIME NULL', 'fetch_err VARCHAR(160) NOT NULL DEFAULT \'\''] as $col)
        $db->exec('ALTER TABLE ig_listings ADD COLUMN IF NOT EXISTS ' . $col);
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

// (4.9) The games a card can go on: every version group (all its recomps at once) and every recomp
// that isn't in one. key => [name, line (console and how many versions), member recomp ids].
function rs_ig_games(array $apps): array {
    require_once __DIR__ . '/titles.php';
    $out = []; $inGroup = [];
    foreach (rs_titles() as $t) {
        $ids = array_values(array_filter((array)$t['pc'], fn($id) => isset($apps[$id])));
        if (!$ids) continue;
        foreach ($ids as $id) $inGroup[$id] = true;
        $shown = array_filter($ids, fn($id) => empty($apps[$id]['hidden']));
        $o = (string)($apps[$ids[0]]['origin'] ?? '');
        $out['t:' . $t['id']] = ['name' => (string)$t['name'], 'line' => trim((RS_PC_ORIGINS[$o] ?? '') . ' · ' . count($shown) . ' version' . (count($shown) === 1 ? '' : 's'), ' ·'),
            'ids' => $ids, 'shown' => (bool)$shown, 'dl' => array_sum(array_map(fn($id) => (int)($apps[$id]['downloads'] ?? 0), $ids)), 'origin' => $o];
    }
    foreach ($apps as $id => $a) {
        if (isset($inGroup[$id])) continue;
        $o = (string)($a['origin'] ?? '');
        $out[(string)$id] = ['name' => (string)($a['game'] ?: $a['name']), 'line' => trim((RS_PC_ORIGINS[$o] ?? '') . ' · ' . (string)$a['name'], ' ·'),
            'ids' => [(string)$id], 'shown' => empty($a['hidden']), 'dl' => (int)($a['downloads'] ?? 0), 'origin' => $o];
    }
    uasort($out, fn($x, $y) => strcasecmp($x['name'], $y['name']));
    return $out;
}

// The listing a recomp's page shows: its own, else its game's (group). null: none.
function rs_ig_for_app(string $appId): ?array {
    rs_ig_tables();
    $keys = [$appId];
    try {
        require_once __DIR__ . '/titles.php';
        foreach (rs_titles() as $t) if (in_array($appId, (array)$t['pc'], true)) $keys[] = 't:' . $t['id'];
    } catch (Throwable $e) { /* no groups */ }
    $st = rs_db()->prepare('SELECT * FROM ig_listings WHERE app_id = ?');
    foreach ($keys as $k) { $st->execute([$k]); if ($r = $st->fetch()) return $r; }
    return null;
}

// (4.9) What an Instant Gaming product page says: its name, platform, region, price, the price before
// the discount, currency and whether it's in stock. Read from the page's own markup (the price it sells
// at is in <meta itemprop="price">). -> [ok, why, name, platform, region, price, old, currency, stock]
function rs_ig_read(string $url): array {
    $url = rs_ig_clean_url($url);
    if ($url === '') return ['ok' => false, 'why' => 'That isn\'t a game page on instant-gaming.com.'];
    $r = rs_http($url, ['timeout' => 20, 'max' => 3000000, 'headers' => ['Accept-Language: en-US,en;q=0.8', 'Accept: text/html',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36']]);
    if ((int)$r['status'] !== 200) return ['ok' => false, 'why' => 'Instant Gaming didn\'t answer (' . (int)$r['status'] . '). Try again in a minute.'];
    $h = (string)$r['body'];
    $meta = function (string $prop) use ($h): string {
        return preg_match('~<meta[^>]+itemprop="' . preg_quote($prop, '~') . '"[^>]+content="([^"]*)"~i', $h, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
    };
    $price = $meta('price');
    if (!is_numeric($price) || (float)$price <= 0) return ['ok' => false, 'why' => 'Couldn\'t find a price on that page. Is it a product page (not search or a category)?'];
    $cur = strtoupper($meta('priceCurrency')) ?: 'USD';
    $stock = !preg_match('~OutOfStock|SoldOut|Discontinued~i', $meta('availability'));
    // The price before the discount: the "retail" figure in the product's own price box.
    $old = null;
    if (preg_match('~class="discounts".{0,1500}?class="retail"[^>]*>(.{0,400}?)</div>~s', $h, $m)
        && preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)/', trim(strip_tags($m[1])), $n)) {
        $n = (float)str_replace(',', '.', $n[1]);
        if ($n > (float)$price) $old = round($n, 2);
    }
    // Name and platform from its breadcrumb (Nintendo > Switch 2 > The Legend of Zelda: ...), region from
    // its title ("Buy X - Switch 2 - Latin America").
    $name = ''; $plat = '';
    if (preg_match_all('~"position":(\d+),"item":\{"@id":"[^"]*","name":"([^"]*)"~', $h, $m, PREG_SET_ORDER)) {
        $crumbs = array_map(fn($x) => json_decode('"' . $x[2] . '"') ?? $x[2], $m);
        $name = (string)end($crumbs);
        if (count($crumbs) >= 2) $plat = (string)$crumbs[count($crumbs) - 2];
    }
    $og = preg_match('~<meta[^>]+property="og:title"[^>]+content="([^"]*)"~i', $h, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
    $parts = array_map('trim', explode(' - ', (string)preg_replace('/^(Buy|Comprar|Acheter|Kaufen)\s+/i', '', $og)));
    if ($name === '' && $parts) $name = $parts[0];
    $region = count($parts) >= 3 ? (string)end($parts) : '';
    if ($plat === '' && count($parts) >= 2) $plat = $parts[1];
    return ['ok' => true, 'why' => '', 'name' => mb_substr($name, 0, 160), 'platform' => mb_substr($plat, 0, 60), 'region' => mb_substr($region, 0, 60),
        'price' => round((float)$price, 2), 'old' => $old, 'currency' => in_array($cur, ['USD', 'EUR', 'GBP'], true) ? $cur : 'USD', 'stock' => $stock];
}

// (4.9) Prices follow Instant Gaming: every listing set to auto is read again when it's older than $age
// seconds. Only price, discount, currency and stock change; the name and platform line stay yours.
function rs_ig_refresh(int $age = 3 * 3600, int $max = 40): array {
    rs_ig_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT app_id, url FROM ig_listings WHERE auto = 1 AND (fetched IS NULL OR fetched < UTC_TIMESTAMP() - INTERVAL ? SECOND) ORDER BY fetched IS NULL DESC, fetched LIMIT ' . (int)$max);
    $st->execute([$age]);
    $done = 0; $bad = 0;
    foreach ($st->fetchAll() as $row) {
        $x = rs_ig_read((string)$row['url']);
        if (!$x['ok']) { $db->prepare('UPDATE ig_listings SET fetched = UTC_TIMESTAMP(), fetch_err = ? WHERE app_id = ?')->execute([mb_substr($x['why'], 0, 160), $row['app_id']]); $bad++; continue; }
        $db->prepare('UPDATE ig_listings SET price = ?, old_price = ?, currency = ?, stock = ?, region = IF(? <> \'\', ?, region), fetched = UTC_TIMESTAMP(), fetch_err = \'\' WHERE app_id = ?')
            ->execute([$x['price'], $x['old'], $x['currency'], $x['stock'] ? 1 : 0, $x['region'], $x['region'], $row['app_id']]);
        $done++;
        usleep(400000);
    }
    return [$done, $bad];
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
    // (4.9) A game's card goes on every version of it; a recomp's own card wins over its game's.
    $members = [];
    try {
        require_once __DIR__ . '/titles.php';
        foreach (rs_titles() as $t) $members['t:' . $t['id']] = (array)$t['pc'];
    } catch (Throwable $e) { /* no groups */ }
    $rows = rs_ig_listings();
    usort($rows, fn($x, $y) => (str_starts_with((string)$y['app_id'], 't:') ? 1 : 0) <=> (str_starts_with((string)$x['app_id'], 't:') ? 1 : 0));
    foreach ($rows as $r) {
        $deal = rs_ig_shows_deal($r, $c);
        $cur = $sym[$r['currency']] ?? '$';
        $fmt = fn($v) => $v === null || (float)$v <= 0 ? '' : ($r['currency'] === 'EUR' ? number_format((float)$v, 2, ',', '') . ' €' : $cur . number_format((float)$v, 2));
        $card = ['t' => (string)$r['title'], 'p' => (string)$r['platform'],
            'price' => $c['price'] ? $fmt($r['price']) : '', 'old' => $c['price'] && $deal ? $fmt($r['old_price']) : '',
            'pct' => $c['price'] && $deal ? rs_ig_pct($r['price'], $r['old_price']) : 0, 'off' => (bool)$r['paused'] || !(int)($r['stock'] ?? 1)];
        $key = (string)$r['app_id'];
        foreach (str_starts_with($key, 't:') ? ($members[$key] ?? []) : [$key] as $id) $l[$id] = $card;
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
