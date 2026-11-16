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
    // (4.9) A game's Instant Gaming links, one per region it's sold in, each with the countries its key works in.
    $db->exec('CREATE TABLE IF NOT EXISTS ig_offers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        listing VARCHAR(80) NOT NULL,
        url VARCHAR(500) NOT NULL,
        region VARCHAR(60) NOT NULL DEFAULT \'\',
        countries TEXT NOT NULL,
        unknown_names TEXT NOT NULL,
        price_eur DECIMAL(8,2) NOT NULL,
        old_eur DECIMAL(8,2) NULL,
        stock TINYINT NOT NULL DEFAULT 1,
        fetched DATETIME NULL,
        fetch_err VARCHAR(160) NOT NULL DEFAULT \'\',
        created DATETIME NOT NULL,
        UNIQUE KEY one (listing, url(191)),
        KEY listing (listing)
    ) CHARACTER SET utf8mb4');
    $db->exec('ALTER TABLE ig_offers ADD COLUMN IF NOT EXISTS prices TEXT NULL');
    // (4.9) Worldwide: this link is also shown in every country none of the game's other keys covers.
    $db->exec('ALTER TABLE ig_offers ADD COLUMN IF NOT EXISTS world TINYINT NOT NULL DEFAULT 0');
    $done = true;
}

// The settings, with defaults filled in. on: shown to visitors at all. igr: our partner code.
// variants: the versions being tested. round: goes up when a new test starts (stats and visitors'
// versions start over). price: show prices. tag: show the discount tag; tagMin: only from this %.
function rs_ig_defaults(): array {
    return ['on' => false, 'launcher' => false, 'igr' => 'recomp', 'price' => true, 'tag' => true, 'tagMin' => 10, 'round' => 1, 'roundAt' => gmdate('Y-m-d'),
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
    $c['launcher'] = !empty($c['launcher']); // RecompLauncher shows the cards only when this is on too
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

// (4.9) What an Instant Gaming product page says: name, platform, region, price and the price before the
// discount (both in euros, the store's own base), stock, and the countries its key works in
// (window.countriesAvailability). Its currency table (window.currencies: each currency's rate from euros
// and how it's written) is kept too, so a visitor's price is worked out exactly the way the store does it.
function rs_ig_fetch(string $url, string $cur): array {
    return rs_http($url . '?currency=' . rawurlencode($cur), ['timeout' => 20, 'max' => 3000000, 'headers' => ['Accept-Language: en-US,en;q=0.8', 'Accept: text/html',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36']]);
}

// The page's own price and its price before the discount, in the currency it was asked for, exactly as
// the store writes them ($57.45, was $58). -> [price, old|null] or null.
function rs_ig_page_price(string $h, string $cur, array $rates): ?array {
    if (!preg_match('~<meta[^>]+itemprop="price"[^>]+content="([0-9.]+)"~i', $h, $m) || (float)$m[1] <= 0) return null;
    if (preg_match('~<meta[^>]+itemprop="priceCurrency"[^>]+content="([A-Z]{3})"~i', $h, $c) && $c[1] !== $cur) return null;
    $price = round((float)$m[1], 2);
    $old = null;
    if (preg_match('~class="discounts".{0,1500}?class="retail"[^>]*>(.{0,400}?)</div>~s', $h, $m)) {
        $t = preg_replace('/[^0-9.,]/', '', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5));
        $dec = (string)($rates[$cur]['dec'] ?? '.');
        $t = $dec === ',' ? str_replace(',', '.', str_replace('.', '', $t)) : str_replace(',', '', $t);
        if (is_numeric($t) && (float)$t > $price) $old = round((float)$t, 2);
    }
    return [$price, $old];
}

// (4.13) $currencies false: only the page itself (name, region, countries); prices come from the feed.
function rs_ig_read(string $url, bool $world = false, bool $currencies = true): array {
    require_once __DIR__ . '/countries.php';
    $url = rs_ig_clean_url($url);
    if ($url === '') return ['ok' => false, 'why' => 'That isn\'t a game page on instant-gaming.com.'];
    $r = rs_ig_fetch($url, 'EUR');
    if ((int)$r['status'] !== 200) return ['ok' => false, 'why' => 'Instant Gaming didn\'t answer (' . (int)$r['status'] . '). Try again in a minute.'];
    $h = (string)$r['body'];
    $meta = function (string $prop) use ($h): string {
        return preg_match('~<meta[^>]+itemprop="' . preg_quote($prop, '~') . '"[^>]+content="([^"]*)"~i', $h, $m) ? html_entity_decode($m[1], ENT_QUOTES) : '';
    };
    // The price in euros: data-price-eur on the price, whatever currency the page came in.
    $eur = preg_match('~itemprop="price"[^>]*data-price-eur="([0-9.]+)"~', $h, $m) ? (float)$m[1] : 0.0;
    $shown = (float)$meta('price'); $shownCur = strtoupper($meta('priceCurrency')) ?: 'EUR';
    $rates = [];
    if (preg_match('~window\.currencies\s*=\s*(\{.*?\});\s*window~s', $h, $m) && is_array($cj = json_decode($m[1], true))) {
        foreach ($cj as $code => $c) {
            if (!preg_match('/^[A-Z]{3}$/', (string)$code) || !is_numeric($c['tx'] ?? null) || (float)$c['tx'] <= 0) continue;
            $rates[$code] = ['tx' => (float)$c['tx'], 'fmt' => html_entity_decode((string)($c['format'] ?? '%s'), ENT_QUOTES | ENT_HTML5),
                'dec' => (string)($c['decimal_separator'] ?? '.'), 'th' => (string)($c['thousand_separator'] ?? '')];
        }
    }
    if ($eur <= 0 && $shown > 0) $eur = $shownCur === 'EUR' ? $shown : (isset($rates[$shownCur]) ? round($shown / $rates[$shownCur]['tx'], 2) : 0.0);
    if ($eur <= 0) return ['ok' => false, 'why' => 'Couldn\'t find a price on that page. Is it a product page (not search or a category)?'];
    $stock = !preg_match('~OutOfStock|SoldOut|Discontinued~i', $meta('availability'));
    // The price before the discount: the "retail" figure in the product's own price box, in the page's currency.
    $old = null;
    if (preg_match('~class="discounts".{0,1500}?class="retail"[^>]*>(.{0,400}?)</div>~s', $h, $m)
        && preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)/', trim(strip_tags($m[1])), $n)) {
        $n = (float)str_replace(',', '.', $n[1]);
        $nEur = $shownCur === 'EUR' ? $n : (isset($rates[$shownCur]) ? $n / $rates[$shownCur]['tx'] : 0);
        if ($nEur > $eur) $old = round($nEur, 2);
    }
    // Where the key works. Every name must be one we know: a name we can't place is listed, never guessed.
    $codes = []; $unknown = [];
    if (preg_match('~window\.countriesAvailability\s*=\s*(\[.*?\]);~s', $h, $m) && is_array($list = json_decode($m[1], true))) {
        foreach ($list as $cn) {
            $k = preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$cn) ?: (string)$cn));
            if (isset(RS_COUNTRY_NAMES[$k])) $codes[RS_COUNTRY_NAMES[$k]] = true; else $unknown[] = (string)$cn;
        }
    }
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
    if ($rates) rs_set_setting('ig_rates', json_encode($rates, JSON_UNESCAPED_UNICODE));
    // Each currency its countries pay in, read from the page in that currency: the store's own figures,
    // to the cent, never our own conversion.
    $rt = $rates ?: rs_ig_rates();
    $prices = [];
    if ($pp = rs_ig_page_price($h, 'EUR', $rt)) $prices['EUR'] = $pp;
    $need = [];
    foreach (array_keys($codes) as $cc) $need[rs_ig_currency_for($cc, $rt)] = true;
    // A worldwide link can be shown anywhere, so it's read in every currency the store has.
    if ($world) foreach (array_keys($rt) as $cur) $need[$cur] = true;
    if (!$currencies) $need = [];
    foreach (array_keys($need) as $cur) {
        if (isset($prices[$cur])) continue;
        $rc = rs_ig_fetch($url, $cur);
        if ((int)$rc['status'] === 200 && ($pp = rs_ig_page_price((string)$rc['body'], $cur, $rt))) $prices[$cur] = $pp;
        usleep(200000);
    }
    return ['ok' => true, 'why' => '', 'name' => mb_substr($name, 0, 160), 'platform' => mb_substr($plat, 0, 60), 'region' => mb_substr($region, 0, 60),
        'eur' => round($eur, 2), 'old_eur' => $old, 'stock' => $stock, 'countries' => array_keys($codes), 'unknown' => $unknown, 'prices' => $prices];
}

// IG's currency table (from the last page read): code => [tx (from euros), fmt ("$%s"), dec, th].
function rs_ig_rates(): array {
    $r = json_decode((string)rs_setting('ig_rates', ''), true);
    return is_array($r) && $r ? $r : ['EUR' => ['tx' => 1.0, 'fmt' => "%s\u{a0}€", 'dec' => '.', 'th' => ''], 'USD' => ['tx' => 1.105, 'fmt' => '$%s', 'dec' => '.', 'th' => '']];
}

// A euro price in a currency, worked out and written the way Instant Gaming does it.
function rs_ig_money(float $eur, string $cur, array $rates): string {
    $c = $rates[$cur] ?? $rates['USD'] ?? ['tx' => 1.0, 'fmt' => '%s', 'dec' => '.', 'th' => ''];
    return rs_ig_fmt(round($eur * (float)$c['tx'], 2), $cur, $rates);
}
// An amount already in a currency, written the store's way (a whole number without cents, like its "was $58").
function rs_ig_fmt(float $v, string $cur, array $rates, bool $whole = false): string {
    $c = $rates[$cur] ?? ['fmt' => '%s', 'dec' => '.', 'th' => ''];
    $d = $whole && abs($v - round($v)) < 0.005 ? 0 : 2;
    return str_replace('&nbsp;', "\u{a0}", str_replace('%s', number_format($v, $d, $c['dec'] ?: '.', (string)$c['th']), (string)$c['fmt']));
}

// The currency Instant Gaming shows a visitor from a country (ISO 3166 code).
function rs_ig_currency_for(string $cc, array $rates): string {
    require_once __DIR__ . '/countries.php';
    $cur = RS_COUNTRY_CURRENCY[$cc] ?? 'USD';
    return isset($rates[$cur]) ? $cur : (isset($rates['USD']) ? 'USD' : 'EUR');
}

// Save what a page said onto one of a game's links.
function rs_ig_offer_store(string $listing, string $url, array $x): void {
    rs_ig_feed_tables();
    // (4.13) Prices from the feed when it lets us in: every currency at once, to the cent.
    if (rs_ig_feed_ok() && ($pid = rs_ig_prod_id($url)) && ($by = rs_ig_feed_prices([$pid])) && ($p = $by[$pid] ?? null) && $p['eur'] > 0) {
        $x['eur'] = $p['eur']; $x['old_eur'] = $p['old']; $x['prices'] = $p['prices']; $x['stock'] = $p['stock'];
        if ($p['region'] !== '') $x['region'] = $p['region'];
    }
    rs_db()->prepare('INSERT INTO ig_offers (listing, url, region, countries, unknown_names, price_eur, old_eur, prices, stock, fetched, fetch_err, created)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), \'\', UTC_TIMESTAMP())
        ON DUPLICATE KEY UPDATE region = VALUES(region), countries = VALUES(countries), unknown_names = VALUES(unknown_names), price_eur = VALUES(price_eur),
        old_eur = VALUES(old_eur), prices = VALUES(prices), stock = VALUES(stock), fetched = VALUES(fetched), fetch_err = \'\'')
        ->execute([$listing, $url, $x['region'], json_encode($x['countries']), json_encode($x['unknown'], JSON_UNESCAPED_UNICODE), $x['eur'], $x['old_eur'], json_encode($x['prices'] ?? new stdClass()), $x['stock'] ? 1 : 0]);
    rs_db()->prepare('UPDATE ig_offers SET prod_id = ?, cc_read = UTC_TIMESTAMP() WHERE listing = ? AND url = ?')->execute([rs_ig_prod_id($url), $listing, $url]);
}

function rs_ig_offers(?string $listing = null): array {
    rs_ig_tables();
    if ($listing === null) return rs_db()->query('SELECT * FROM ig_offers ORDER BY listing, id')->fetchAll();
    $st = rs_db()->prepare('SELECT * FROM ig_offers WHERE listing = ? ORDER BY id');
    $st->execute([$listing]);
    return $st->fetchAll();
}

// (4.9) Prices follow Instant Gaming: every link is read again once it's older than $age seconds (price,
// discount, stock and the countries it works in). A link that can't be read keeps its last numbers and
// says so; one that fails for a day stops showing, so a dead page never sends anyone anywhere.
function rs_ig_refresh(int $age = 600, int $max = 80): array {
    rs_ig_tables();
    $db = rs_db();
    $st = $db->prepare('SELECT id, listing, url, world FROM ig_offers WHERE fetched IS NULL OR fetched < UTC_TIMESTAMP() - INTERVAL ? SECOND ORDER BY fetched LIMIT ' . (int)$max);
    $st->execute([$age]);
    $done = 0; $bad = 0;
    foreach ($st->fetchAll() as $row) {
        $x = rs_ig_read((string)$row['url'], (bool)(int)$row['world']);
        if ($x['ok']) { rs_ig_offer_store((string)$row['listing'], (string)$row['url'], $x); $done++; }
        else {
            $db->prepare('UPDATE ig_offers SET fetch_err = ?, stock = IF(fetched < UTC_TIMESTAMP() - INTERVAL 1 DAY, 0, stock) WHERE id = ?')->execute([mb_substr($x['why'], 0, 160), $row['id']]);
            $bad++;
        }
        usleep(300000);
    }
    if ($done || $bad) rs_ig_cache_write();
    return [$done, $bad];
}

// (4.9) What /api/ig.php hands out, before it's narrowed to the visitor's country: kept in a file so
// game pages are answered without the database. Rebuilt whenever a card or a price changes.
function rs_ig_cache_file(): string { return dirname(__DIR__, 3) . '/recompstore-private/ig-cache.json'; }

function rs_ig_cache_build(): array {
    $c = rs_ig_cfg();
    $members = [];
    try { require_once __DIR__ . '/titles.php'; foreach (rs_titles() as $t) foreach ((array)$t['pc'] as $id) $members['t:' . $t['id']][] = (string)$id; } catch (Throwable $e) { /* no groups */ }
    $offers = [];
    foreach (rs_ig_offers() as $o) {
        if (!(int)$o['stock']) continue;
        $offers[(string)$o['listing']][] = ['c' => json_decode((string)$o['countries'], true) ?: [], 'eur' => (float)$o['price_eur'],
            'old' => $o['old_eur'] !== null ? (float)$o['old_eur'] : null, 'url' => (string)$o['url'], 'p' => json_decode((string)($o['prices'] ?? ''), true) ?: new stdClass(),
            'w' => (bool)(int)($o['world'] ?? 0)];
    }
    $games = [];
    foreach (rs_ig_listings() as $r) {
        $k = (string)$r['app_id'];
        if ((int)$r['paused'] || empty($offers[$k])) continue;
        $games[$k] = ['t' => (string)$r['title'], 'p' => (string)$r['platform'], 'tag' => (int)$r['tag'], 'ids' => str_starts_with($k, 't:') ? ($members[$k] ?? []) : [$k], 'offers' => $offers[$k]];
    }
    return ['on' => $c['on'], 'launcher' => !empty($c['launcher']), 'round' => $c['round'], 'igr' => $c['igr'], 'price' => $c['price'], 'tag' => $c['tag'], 'tagMin' => $c['tagMin'],
        'v' => array_map(fn($x) => ['id' => $x['id'], 'place' => $x['place'], 'btn' => $x['btn'], 'share' => $x['share']], rs_ig_live_variants($c)),
        'all' => array_map(fn($x) => ['id' => $x['id'], 'place' => $x['place'], 'btn' => $x['btn']], $c['variants']),
        'rates' => rs_ig_rates(), 'games' => $games, 'at' => time()];
}

function rs_ig_cache_write(): void {
    $f = rs_ig_cache_file();
    @mkdir(dirname($f), 0775, true);
    $tmp = $f . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode(rs_ig_cache_build(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $f);
}

// Where a click goes: the product page with our partner code on it.
// (4.9) With the visitor's currency, and tagged as ours the way Instant Gaming's partner links are.
function rs_ig_link(string $url, string $igr, string $cur = ''): string {
    $q = ['igr' => $igr];
    if ($cur !== '') $q['currency'] = $cur;
    $q += ['utm_source' => 'recomplauncher', 'utm_medium' => 'game', 'utm_campaign' => 'link'];
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($q);
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

// ---- (4.13) Instant Gaming's catalog feed (exportCatalog) ----
// Our partner feed: prices in every currency, the price before the discount, stock and region for each
// product, read from one address instead of from each product page. Their rules: the user agent
// IG-ExportCatalog-Fetcher, at most once an hour (it's cached an hour on their side), never in the first
// minute of the hour (our cron runs at :37), and only from the server's address they let in.
// - Every hour: the products our cards use (prod_ids), so the cards follow their prices and stock.
// - Once a day: the whole catalog, kept in recompstore-private/ig-catalog.json, to find our games in it.
// - Until the feed lets us in (it answers 401 "Not Allowed"), prices are read from the product pages as
//   before, once an hour.
// A product page is still read when a link is added, and once a week after, for the countries its key
// works in (the feed only says the region).
const RS_IG_FEED_UA = 'IG-ExportCatalog-Fetcher';
const RS_IG_FEED_CURS = ['AED', 'AUD', 'BRL', 'CAD', 'CHF', 'CZK', 'DKK', 'EUR', 'GBP', 'PLN', 'USD', 'RSD', 'SEK'];

function rs_ig_feed_tables(): void {
    static $done = false;
    if ($done) return;
    rs_ig_tables();
    $db = rs_db();
    $db->exec('ALTER TABLE ig_offers ADD COLUMN IF NOT EXISTS prod_id INT NOT NULL DEFAULT 0');
    $db->exec('ALTER TABLE ig_offers ADD COLUMN IF NOT EXISTS cc_read DATETIME NULL');
    $done = true;
}

// The product number in a product page's address (https://www.instant-gaming.com/en/815-buy-…). 0: none.
function rs_ig_prod_id(string $url): int {
    return preg_match('~instant-gaming\.com/(?:[a-z]{2}(?:-[a-z]{2})?/)?(\d+)-~i', $url, $m) ? (int)$m[1] : 0;
}

function rs_ig_feed_url(array $params = []): string {
    $c = rs_ig_cfg();
    return 'https://www.instant-gaming.com/en/exportCatalog/json/?' . http_build_query(['igr' => $c['igr']] + $params);
}

// Reads the feed. -> [status, products (list) or null, why it failed]. $file: written there as it
// arrives (the whole catalog is big), else kept in memory.
function rs_ig_feed_get(array $params, int $timeout = 60, string $file = ''): array {
    $ch = curl_init(rs_ig_feed_url($params));
    $fh = $file !== '' ? fopen($file . '.part', 'wb') : null;
    $body = '';
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => RS_IG_FEED_UA, CURLOPT_ENCODING => '', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, $fh) {
            if ($fh) return fwrite($fh, $chunk);
            if (strlen($body) + strlen($chunk) > (64 << 20)) return 0;
            $body .= $chunk;
            return strlen($chunk);
        }]);
    curl_exec($ch);
    $st = curl_errno($ch) ? 0 : (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) { fclose($fh); $body = $st === 200 ? (string)file_get_contents($file . '.part') : (string)@file_get_contents($file . '.part', false, null, 0, 300); @unlink($file . '.part'); }
    if ($st !== 200) {
        $why = $st === 401 || $st === 403 ? 'Instant Gaming says "' . trim(mb_substr(strip_tags($body), 0, 60)) . '": the server\'s address isn\'t on their list yet (your partner manager adds it).'
            : ($st ? 'Instant Gaming answered ' . $st . '.' : 'No answer from Instant Gaming' . ($err !== '' ? ' (' . $err . ')' : '') . '.');
        return [$st, null, $why];
    }
    $j = json_decode($body, true);
    unset($body);
    if (!is_array($j)) return [$st, null, 'The feed wasn\'t JSON.'];
    // A list of products, or a list under a key, or products keyed by id.
    foreach (['products', 'data', 'items', 'catalog'] as $k) if (isset($j[$k]) && is_array($j[$k])) { $j = $j[$k]; break; }
    $list = array_values(array_filter($j, 'is_array'));
    return [$st, $list, ''];
}

// One product from the feed, in our words. eur/old: euros; prices: currency => [price, price before | null].
function rs_ig_feed_norm(array $p): ?array {
    $id = (int)($p['id'] ?? 0);
    if ($id <= 0) return null;
    $num = fn($v) => is_numeric(str_replace(',', '.', (string)$v)) ? round((float)str_replace(',', '.', (string)$v), 2) : 0.0;
    $eur = $num($p['price'] ?? 0); $retail = $num($p['retail'] ?? 0);
    $prices = [];
    $cp = is_array($p['currency_prices'] ?? null) ? $p['currency_prices'] : [];
    $rp = is_array($p['retail_prices'] ?? null) ? $p['retail_prices'] : [];
    foreach ($cp as $cur => $v) {
        $cur = strtoupper((string)$cur);
        if (!preg_match('/^[A-Z]{3}$/', $cur) || ($pv = $num($v)) <= 0) continue;
        $ov = $num($rp[$cur] ?? 0);
        $prices[$cur] = [$pv, $ov > $pv ? $ov : null];
    }
    if ($eur > 0 && !isset($prices['EUR'])) $prices['EUR'] = [$eur, $retail > $eur ? $retail : null];
    if ($eur <= 0 && isset($prices['EUR'])) $eur = $prices['EUR'][0];
    return ['id' => $id, 'name' => mb_substr(trim(html_entity_decode((string)($p['name'] ?? ''), ENT_QUOTES | ENT_HTML5)), 0, 160),
        'type' => mb_substr(trim((string)($p['type'] ?? '')), 0, 60), 'url' => (string)($p['url'] ?? ''), 'img' => (string)($p['img'] ?? ''),
        'region' => mb_substr(trim((string)($p['region'] ?? '')), 0, 60), 'stock' => (int)($p['stock'] ?? 1) === 1,
        'steam' => (string)($p['steam_id'] ?? ''), 'eur' => $eur, 'old' => $retail > $eur ? $retail : null, 'disc' => (int)($p['discount'] ?? 0), 'prices' => $prices];
}

// The feed's status for the admin page: [at, ok, why, n (products read), full (when the catalog was read), fullN].
function rs_ig_feed_state(): array {
    $s = json_decode((string)rs_setting('ig_feed', ''), true);
    return (is_array($s) ? $s : []) + ['at' => 0, 'ok' => false, 'why' => 'Not read yet.', 'n' => 0, 'full' => 0, 'fullN' => 0, 'okAt' => 0];
}
function rs_ig_feed_state_save(array $s): void { rs_set_setting('ig_feed', json_encode($s, JSON_UNESCAPED_UNICODE)); }
function rs_ig_feed_ok(): bool { $s = rs_ig_feed_state(); return $s['ok'] && time() - (int)$s['okAt'] < 6 * 3600; }

// Prices, discount, stock and region from the feed, for these products. -> [products read, [id => product]] or null when the feed said no.
function rs_ig_feed_prices(array $ids): ?array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $s = rs_ig_feed_state();
    $s['at'] = time();
    $by = [];
    foreach (array_chunk($ids, 150) as $chunk) {
        [$st, $list, $why] = rs_ig_feed_get(['prod_ids' => implode(',', $chunk), 'with_currencies' => 1]);
        if ($list === null) { $s['ok'] = false; $s['why'] = $why; rs_ig_feed_state_save($s); return null; }
        foreach ($list as $p) if ($n = rs_ig_feed_norm($p)) $by[$n['id']] = $n;
    }
    $s['ok'] = true; $s['why'] = ''; $s['okAt'] = time(); $s['n'] = count($by);
    rs_ig_feed_state_save($s);
    return $by;
}

// Puts the feed's numbers on our links. A product the feed no longer has is marked, and out of stock after a day.
function rs_ig_feed_apply(array $by, array $offers): int {
    $db = rs_db();
    $up = $db->prepare('UPDATE ig_offers SET price_eur = ?, old_eur = ?, prices = ?, stock = ?, region = IF(? = \'\', region, ?), fetched = UTC_TIMESTAMP(), fetch_err = \'\' WHERE id = ?');
    $miss = $db->prepare('UPDATE ig_offers SET fetch_err = ?, stock = IF(fetched < UTC_TIMESTAMP() - INTERVAL 1 DAY, 0, stock) WHERE id = ?');
    $n = 0;
    foreach ($offers as $o) {
        $p = $by[(int)$o['prod_id']] ?? null;
        if (!$p || $p['eur'] <= 0) { $miss->execute(['Not in Instant Gaming\'s catalog right now.', $o['id']]); continue; }
        $up->execute([$p['eur'], $p['old'], json_encode($p['prices'] ?: new stdClass()), $p['stock'] ? 1 : 0, $p['region'], $p['region'], $o['id']]);
        ++$n;
    }
    return $n;
}

// The hourly run (ig-refresh.php). -> a line for the log
function rs_ig_feed_refresh(bool $full = false): string {
    rs_ig_feed_tables();
    $db = rs_db();
    // Links added before the feed: their product number from the address.
    foreach ($db->query('SELECT id, url FROM ig_offers WHERE prod_id = 0')->fetchAll() as $o)
        if ($pid = rs_ig_prod_id((string)$o['url'])) $db->prepare('UPDATE ig_offers SET prod_id = ? WHERE id = ?')->execute([$pid, $o['id']]);
    $offers = $db->query('SELECT id, listing, url, prod_id, world, cc_read FROM ig_offers')->fetchAll();
    $line = [];
    $by = $offers ? rs_ig_feed_prices(array_column($offers, 'prod_id')) : [];
    if ($by === null) {
        // Not let in yet: the product pages, as before.
        [$ok, $bad] = rs_ig_refresh(3000, 200);
        $line[] = 'feed refused (' . rs_ig_feed_state()['why'] . '), read ' . $ok . ' pages' . ($bad ? ', ' . $bad . ' failed' : '');
    } else {
        $n = rs_ig_feed_apply($by, $offers);
        if ($offers) $line[] = 'feed: ' . $n . ' of ' . count($offers) . ' links priced';
        // Where each key works, from its page once a week (a few a run).
        $cc = 0;
        foreach ($offers as $o) {
            if ($cc >= 8) break;
            if ($o['cc_read'] !== null && strtotime($o['cc_read'] . ' UTC') > time() - 7 * 86400) continue;
            $x = rs_ig_read((string)$o['url'], false, false);
            if ($x['ok']) $db->prepare('UPDATE ig_offers SET countries = ?, unknown_names = ?, cc_read = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([json_encode($x['countries']), json_encode($x['unknown'], JSON_UNESCAPED_UNICODE), $o['id']]);
            ++$cc;
            usleep(500000);
        }
        if ($cc) $line[] = 'countries read for ' . $cc;
        // The whole catalog once a day, to find our games in it.
        $s = rs_ig_feed_state();
        if ($full || time() - (int)$s['full'] > 23 * 3600) $line[] = rs_ig_catalog_refresh();
    }
    rs_ig_cache_write();
    return implode('; ', array_filter($line));
}

function rs_ig_catalog_file(): string { return dirname(__DIR__, 3) . '/recompstore-private/ig-catalog.json'; }
function rs_ig_suggest_file(): string { return dirname(__DIR__, 3) . '/recompstore-private/ig-suggest.json'; }

// The whole catalog, kept small: [id, name, type, url, region, stock, eur, old, img].
function rs_ig_catalog_refresh(): string {
    @ini_set('memory_limit', '1024M');
    $f = rs_ig_catalog_file();
    @mkdir(dirname($f), 0775, true);
    [$st, $list, $why] = rs_ig_feed_get([], 240, $f . '.raw');
    $s = rs_ig_feed_state();
    if ($list === null) { $s['why'] = $why; rs_ig_feed_state_save($s); return 'catalog: ' . $why; }
    $out = [];
    foreach ($list as $p) {
        $n = rs_ig_feed_norm($p);
        if ($n && $n['name'] !== '') $out[] = [$n['id'], $n['name'], $n['type'], $n['url'], $n['region'], $n['stock'] ? 1 : 0, $n['eur'], $n['old'], $n['img']];
    }
    unset($list);
    file_put_contents($f . '.tmp', json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    rename($f . '.tmp', $f);
    $s = rs_ig_feed_state();
    $s['full'] = time(); $s['fullN'] = count($out);
    rs_ig_feed_state_save($s);
    $m = rs_ig_suggest_build($out);
    return 'catalog: ' . count($out) . ' products, ' . $m . ' of our games found';
}

// A name for matching: lower case, plain letters and numbers, single spaces.
function rs_ig_norm(string $s): string {
    $s = str_replace(['&', '™', '®', '©', '’', "'"], [' and ', '', '', '', '', ''], $s);
    $a = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    $s = strtolower($a !== false ? $a : $s);
    return trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^a-z0-9]+/', ' ', $s)));
}

// Words that can follow a game's name in a product's name without making it another game.
const RS_IG_TAIL = '/^((nintendo )?switch( 2)?|nintendo|xbox( one| series x s| series)?( pc)?|xbox 360|pc|steam|epic( games)?|playstation [45]|ps[45]|eshop|key|edition|standard( edition)?|eu|us|uk|europe|united states|north america|latam|latin america|row|worldwide|global| )+$/';

// Our games found in the catalog: game key => products, the best fit first. -> how many games have one
function rs_ig_suggest_build(array $cat): int {
    require_once __DIR__ . '/pc.php';
    $apps = [];
    foreach (rs_pc_apps(true) as $a) $apps[(string)$a['id']] = $a;
    $games = rs_ig_games($apps);
    $idx = [];
    foreach ($cat as $i => $p) {
        $n = rs_ig_norm((string)$p[1]);
        if ($n === '') continue;
        $w = explode(' ', $n);
        $idx[$w[0] . ' ' . ($w[1] ?? '')][] = $i;
        $idx[$w[0]][] = $i;
    }
    $out = [];
    foreach ($games as $k => $g) {
        if (!$g['shown']) continue;
        $gn = rs_ig_norm((string)$g['name']);
        if (strlen($gn) < 3) continue;
        $gw = explode(' ', $gn);
        $cands = $idx[$gw[0] . ' ' . ($gw[1] ?? '')] ?? (count($gw) === 1 ? ($idx[$gw[0]] ?? []) : []);
        $hits = [];
        foreach (array_unique($cands) as $i) {
            $p = $cat[$i];
            $pn = rs_ig_norm((string)$p[1]);
            if ($pn === $gn) $score = 3;
            elseif (str_starts_with($pn, $gn . ' ') && preg_match(RS_IG_TAIL, substr($pn, strlen($gn) + 1))) $score = 2;
            else continue;
            $t = strtolower((string)$p[2]);
            $fit = in_array($g['origin'], ['switch', 'switch2'], true) ? (str_contains($t, 'nintendo') || str_contains($t, 'switch') || str_contains($t, 'eshop'))
                : ($g['origin'] === 'x360' ? str_contains($t, 'xbox') : false);
            $hits[] = ['id' => (int)$p[0], 'name' => (string)$p[1], 'type' => (string)$p[2], 'url' => (string)$p[3], 'region' => (string)$p[4],
                'stock' => (bool)$p[5], 'eur' => (float)$p[6], 'old' => $p[7] !== null ? (float)$p[7] : null, 'img' => (string)($p[8] ?? ''),
                'score' => $score + ($fit ? 1 : 0) + ($p[5] ? 1 : 0)];
        }
        if (!$hits) continue;
        usort($hits, fn($x, $y) => [$y['score'], $x['eur']] <=> [$x['score'], $y['eur']]);
        $out[$k] = array_slice($hits, 0, 8);
    }
    file_put_contents(rs_ig_suggest_file(), json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return count($out);
}

function rs_ig_suggestions(): array {
    $j = json_decode((string)@file_get_contents(rs_ig_suggest_file()), true);
    return is_array($j) ? $j : [];
}
