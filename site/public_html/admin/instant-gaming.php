<?php
// RecompStore admin (4.8): Sponsors > Instant Gaming. Which recomps' pages show where to buy the original
// game (through our partner link), what the card says, where it sits, and the test between versions:
// each visitor keeps the version they got first, and every version counts its views and clicks.
// Sales are on Instant Gaming's own partner dashboard; this counts what happens on our side.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/ig.php';
rs_require_login();
rs_ig_tables();
$db = rs_db();
$c = rs_ig_cfg();
$apps = [];
foreach (rs_pc_apps(true) as $a) $apps[(string)$a['id']] = $a;
// (4.9) Cards go on games: a version group (every recomp of it) or a recomp on its own.
$games = rs_ig_games($apps);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'instant-gaming.php';
    // (4.9) Read an Instant Gaming page for the form: name, platform, region, price, stock.
    if ($act === 'read') {
        header('Content-Type: application/json');
        $x = rs_ig_read((string)($_POST['url'] ?? ''));
        if ($x['ok']) {
            $rt = rs_ig_rates();
            $x['link'] = rs_ig_link(rs_ig_clean_url((string)$_POST['url']), $c['igr'], 'USD');
            $pu = $x['prices']['USD'] ?? null;
            $x['usd'] = $pu ? rs_ig_fmt((float)$pu[0], 'USD', $rt) : rs_ig_money($x['eur'], 'USD', $rt);
            $x['old_usd'] = $pu && $pu[1] ? rs_ig_fmt((float)$pu[1], 'USD', $rt, true) : '';
            require_once __DIR__ . '/inc/countries.php';
            $x['curs'] = array_values(array_unique(array_map(fn($cc) => rs_ig_currency_for($cc, $rt), $x['countries'])));
        }
        echo json_encode($x, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        if ($act === 'onoff') {
            $c['on'] = !$c['on'];
            rs_ig_save($c);
            rs_log('sponsors', 'Instant Gaming', $c['on'] ? 'on' : 'off');
            rs_flash($c['on'] ? 'On. Linked recomps show the card within 2 minutes.' : 'Off. The cards are gone within 2 minutes.');
        } elseif ($act === 'launcher') {
            $c['launcher'] = !$c['launcher'];
            rs_ig_save($c);
            rs_log('sponsors', 'Instant Gaming', $c['launcher'] ? 'launcher on' : 'launcher off');
            rs_flash($c['launcher'] ? 'RecompLauncher will show the cards too, within a few minutes.' : 'RecompLauncher stops showing the cards within a few minutes.');
        } elseif ($act === 'code') {
            $igr = trim((string)($_POST['igr'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $igr)) throw new RuntimeException('The partner code is letters, numbers, - and _ only.');
            $c['igr'] = $igr;
            rs_ig_save($c);
            rs_flash('Saved. Clicks now carry igr=' . $igr . '.');
        } elseif ($act === 'display') {
            $c['price'] = !empty($_POST['price']);
            $c['tag'] = !empty($_POST['tag']);
            $c['tagMin'] = max(0, min(90, (int)($_POST['tagMin'] ?? 10)));
            rs_ig_save($c);
            rs_flash('Saved.');
        } elseif ($act === 'variants') {
            $vs = [];
            foreach ($c['variants'] as $v) {
                $id = $v['id'];
                $vs[] = ['id' => $id, 'name' => mb_substr(trim((string)($_POST['name'][$id] ?? $v['name'])), 0, 60) ?: 'Version ' . strtoupper($id),
                    'place' => isset(RS_IG_PLACES[$_POST['place'][$id] ?? '']) ? $_POST['place'][$id] : $v['place'],
                    'btn' => isset(RS_IG_BUTTONS[$_POST['btn'][$id] ?? '']) ? $_POST['btn'][$id] : $v['btn'],
                    'share' => max(0, min(100, (int)($_POST['share'][$id] ?? $v['share']))), 'on' => !empty($_POST['on'][$id])];
            }
            if (!array_filter($vs, fn($v) => $v['on'] && $v['share'] > 0)) throw new RuntimeException('Keep at least one version on, with a share above 0.');
            $c['variants'] = $vs;
            rs_ig_save($c);
            rs_flash('Saved. Visitors who already have a version keep it; new ones are split by the new shares.');
        } elseif ($act === 'add_variant') {
            $used = array_column($c['variants'], 'id');
            $id = current(array_diff(RS_IG_IDS, $used));
            if (!$id) throw new RuntimeException('Four versions is the most.');
            $taken = array_column($c['variants'], 'place');
            $place = current(array_diff(array_keys(RS_IG_PLACES), $taken)) ?: 'side-top';
            $c['variants'][] = ['id' => $id, 'name' => RS_IG_PLACES[$place][0], 'place' => $place, 'btn' => 'hover', 'share' => 0, 'on' => false];
            rs_ig_save($c);
            rs_flash('Added version ' . strtoupper($id) . ', off for now. Set its share and turn it on.');
        } elseif ($act === 'del_variant') {
            $id = (string)($_POST['id'] ?? '');
            $left = array_values(array_filter($c['variants'], fn($v) => $v['id'] !== $id));
            if (!$left || !array_filter($left, fn($v) => $v['on'] && $v['share'] > 0)) throw new RuntimeException('That would leave no version running.');
            $c['variants'] = $left;
            rs_ig_save($c);
            rs_flash('Removed version ' . strtoupper($id) . '. Its visitors get one of the others.');
        } elseif ($act === 'round') {
            $c['round']++;
            $c['roundAt'] = gmdate('Y-m-d');
            rs_ig_save($c);
            rs_log('sponsors', 'Instant Gaming', 'new test round ' . $c['round']);
            rs_flash('New round started. Numbers start from zero and every visitor gets a version again.');
        } elseif ($act === 'listing_save') {
            $id = trim((string)($_POST['app_id'] ?? ''));
            if (!isset($games[$id])) throw new RuntimeException('Pick the game from the search.');
            // (4.9) One link per region it's sold in. Each is read now: price, stock and the countries its key works in.
            $urls = [];
            foreach (preg_split('/\s+/', (string)($_POST['urls'] ?? '')) as $u) if (($u = rs_ig_clean_url($u)) !== '') $urls[$u] = true;
            if (!$urls) throw new RuntimeException('Paste the game\'s page on instant-gaming.com (one link per region, like its US key and its Latin America key).');
            // (4.9) Worldwide: one of the links can also be shown in every country no other key covers.
            $world = rs_ig_clean_url((string)($_POST['world'] ?? ''));
            if ($world !== '' && !isset($urls[$world])) $world = '';
            $read = []; $first = null;
            foreach (array_keys($urls) as $u) {
                $x = rs_ig_read($u, $u === $world);
                if (!$x['ok']) throw new RuntimeException($u . ': ' . $x['why']);
                if (!$x['countries'] && $u !== $world) throw new RuntimeException($u . ': its page doesn\'t say which countries the key works in, so it can only be used as the worldwide link.');
                $read[$u] = $x; $first = $first ?? $x;
            }
            $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 160)) ?: $first['name'];
            $platform = trim(mb_substr((string)($_POST['platform'] ?? ''), 0, 60)) ?: $first['platform'];
            $tag = max(0, min(2, (int)($_POST['tag'] ?? 0)));
            $db->prepare('INSERT INTO ig_listings (app_id, url, title, platform, price, old_price, currency, tag, paused, auto, region, stock, fetched, created, updated)
                VALUES (?, ?, ?, ?, NULL, NULL, \'EUR\', ?, 0, 1, \'\', 1, UTC_TIMESTAMP(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE url = VALUES(url), title = VALUES(title), platform = VALUES(platform), tag = VALUES(tag), updated = NOW()')
                ->execute([$id, array_key_first($read), $title, $platform, $tag]);
            foreach ($read as $u => $x) rs_ig_offer_store($id, $u, $x);
            $db->prepare('UPDATE ig_offers SET world = (url = ?) WHERE listing = ?')->execute([$world, $id]);
            $keep = array_keys($read);
            foreach (rs_ig_offers($id) as $o) if (!in_array($o['url'], $keep, true)) $db->prepare('DELETE FROM ig_offers WHERE id = ?')->execute([$o['id']]);
            // Moving a recomp's own card up to its game: the old one goes.
            if (str_starts_with($id, 't:')) foreach ($games[$id]['ids'] as $m) { $db->prepare('DELETE FROM ig_listings WHERE app_id = ?')->execute([$m]); $db->prepare('DELETE FROM ig_offers WHERE listing = ?')->execute([$m]); }
            $cov = [];
            foreach ($read as $x) foreach ($x['countries'] as $cc) $cov[$cc] = true;
            rs_log('sponsors', 'Instant Gaming', 'listing ' . $id . ' (' . count($read) . ' link' . (count($read) === 1 ? '' : 's') . ', ' . count($cov) . ' countries)');
            rs_flash('Saved ' . $games[$id]['name'] . (count($games[$id]['ids']) > 1 ? ', on all ' . count($games[$id]['ids']) . ' versions' : '') . '. '
                . ($world !== '' ? 'Shown worldwide: a regional key in the ' . count($cov) . ' countr' . (count($cov) === 1 ? 'y' : 'ies') . ' they cover, the worldwide link everywhere else.'
                : 'Shown in ' . count($cov) . ' countr' . (count($cov) === 1 ? 'y' : 'ies') . ' (where its key works); everyone else sees no card.'));
            $back .= '#listings';
        } elseif ($act === 'refresh_now') {
            [$ok, $bad] = rs_ig_refresh(0, 200);
            rs_flash('Read ' . $ok . ' page' . ($ok === 1 ? '' : 's') . ' again' . ($bad ? '; ' . $bad . ' couldn\'t be read' : '') . '.');
            $back .= '#listings';
        } elseif (in_array($act, ['listing_pause', 'listing_resume', 'listing_delete', 'listing_rule'], true)) {
            $id = (string)($_POST['app_id'] ?? '');
            if ($act === 'listing_delete') {
                $db->prepare('DELETE FROM ig_listings WHERE app_id = ?')->execute([$id]);
                $db->prepare('DELETE FROM ig_offers WHERE listing = ?')->execute([$id]);
                rs_flash('Removed. Its numbers stay in the totals for this round.');
            } elseif ($act === 'listing_rule') {
                $db->prepare('UPDATE ig_listings SET tag = 0, updated = NOW() WHERE app_id = ?')->execute([$id]);
                rs_flash('Its discount tag follows the rule now.');
            } else {
                $db->prepare('UPDATE ig_listings SET paused = ?, updated = NOW() WHERE app_id = ?')->execute([$act === 'listing_pause' ? 1 : 0, $id]);
                rs_flash($act === 'listing_pause' ? 'Paused. Its card is gone within 2 minutes.' : 'Showing again.');
            }
            $back .= '#listings';
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    // What game pages read (api/ig.php) follows every change here.
    try { rs_ig_cache_write(); } catch (Throwable $e) { /* rebuilt on the next refresh */ }
    header('Location: ' . $back);
    exit;
}

$rows = rs_ig_listings();
// (4.9) Each game's links (one per region), the store's currency table, and country names to show.
$offersBy = [];
foreach (rs_ig_offers() as $o) $offersBy[(string)$o['listing']][] = $o;
$rates = rs_ig_rates();
require_once __DIR__ . '/inc/countries.php';
$ccName = [];
foreach (RS_COUNTRY_NAMES as $nm => $cc) if (!isset($ccName[$cc])) $ccName[$cc] = $nm;
if (class_exists('Locale')) foreach (array_keys($ccName) as $cc) { $n = \Locale::getDisplayRegion('-' . $cc, 'en'); if ($n && $n !== $cc) $ccName[$cc] = $n; }
$linked = array_column($rows, null, 'app_id');
$stats = rs_ig_stats($c['round']);
$live = rs_ig_live_variants($c);
$shareSum = array_sum(array_column($live, 'share')) ?: 1;
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$rl = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) ? '' : 'https://recomplauncher.com';
// (4.9) A listing's recomp to open: the first of its game's versions that's out.
$firstShown = function (string $key) use ($games, $apps): string {
    foreach ($games[$key]['ids'] ?? [] as $id) if (empty($apps[$id]['hidden'])) return $id;
    return (string)($games[$key]['ids'][0] ?? '');
};
$coverOf = function (string $key) use ($games, $apps): string {
    foreach ($games[$key]['ids'] ?? [] as $id) if ((string)($apps[$id]['cover'] ?? '') !== '') return (string)$apps[$id]['cover'];
    return '';
};
$peekId = '';
foreach ($rows as $r) if (!$r['paused'] && !empty($games[$r['app_id']]['shown'])) { $peekId = $firstShown($r['app_id']); break; }
if ($peekId === '' && $rows) $peekId = $firstShown($rows[0]['app_id']);
$small = array_values(array_filter(rs_ig_offers(), fn($o) => ($p = rs_ig_pct($o['price_eur'], $o['old_eur'])) > 0 && $p < $c['tagMin']));
$edit = null;
if (isset($_GET['edit']) && isset($linked[(string)$_GET['edit']])) $edit = $linked[(string)$_GET['edit']];
if (!$edit && isset($_GET['add']) && isset($games[(string)$_GET['add']])) {
    $edit = ['app_id' => (string)$_GET['add'], 'url' => '', 'title' => '', 'platform' => '', 'price' => null, 'old_price' => null, 'currency' => 'USD', 'tag' => 0, 'auto' => 1, 'region' => '', 'new' => true];
}
// Games still sold today (Switch and Switch 2) without a card yet, most downloaded first.
$maybe = array_filter($games, fn($g, $k) => $g['shown'] && in_array($g['origin'], ['switch2', 'switch'], true) && !isset($linked[$k]) && !array_filter($g['ids'], fn($id) => isset($linked[$id])), ARRAY_FILTER_USE_BOTH);
uasort($maybe, fn($x, $y) => $y['dl'] <=> $x['dl']);
$maybe = array_slice($maybe, 0, 8, true);
// The verdict on the test: a version is ahead once every running one has enough views to compare.
$need = 300;
$verdict = '';
if (count($live) > 1) {
    $low = min(array_map(fn($v) => (int)($stats['v'][$v['id']]['views'] ?? 0), $live));
    if ($low < $need) $verdict = 'Too early to call: every version needs about ' . number_format($need) . ' views (the lowest has ' . number_format($low) . ').';
    else {
        $rates = [];
        foreach ($live as $v) $rates[$v['id']] = ($stats['v'][$v['id']]['clicks'] ?? 0) / max(1, $stats['v'][$v['id']]['views'] ?? 0);
        arsort($rates);
        $ids = array_keys($rates);
        $lead = $ids[0];
        $gap = $rates[$ids[1]] > 0 ? ($rates[$lead] / $rates[$ids[1]] - 1) * 100 : 100;
        $name = current(array_filter($live, fn($v) => $v['id'] === $lead))['name'];
        $verdict = $gap < 10 ? 'Close so far: the versions are within 10% of each other.' : strtoupper($lead) . ' (' . $name . ') is ahead, with ' . round($gap) . '% more clicks per view.';
    }
}
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
// The game search: every game with a version that's out, its versions' names to match on too.
$pickList = [];
foreach ($games as $k => $g) if ($g['shown']) $pickList[] = ['k' => $k, 'n' => $g['name'], 'l' => $g['line'], 'c' => $coverOf($k), 'has' => isset($linked[$k]),
    'v' => array_values(array_map(fn($id) => (string)$apps[$id]['name'], array_filter($g['ids'], fn($id) => empty($apps[$id]['hidden']))))];
$searchUrl = fn(string $q): string => 'https://www.instant-gaming.com/en/search/?q=' . rawurlencode($q) . '&igr=' . rawurlencode($c['igr']);
$count = fn(int $n, string $w): string => number_format($n) . ' ' . $w . ($n === 1 ? '' : 's');
$money = fn($v, string $cur): string => $v === null || (float)$v <= 0 ? '' : (['EUR' => '€', 'GBP' => '£'][$cur] ?? '$') . number_format((float)$v, 2);
rs_head('Instant Gaming', true, ['assets/ig.css']);
?>
<div class="ig-head">
  <div>
    <h1>Instant Gaming <span class="<?= $c['on'] ? 'pill ok' : 'pill' ?>"><?= $c['on'] ? 'On' : 'Off' ?></span> <span class="<?= $c['launcher'] ? 'pill ok' : 'pill' ?>">Launcher <?= $c['launcher'] ? 'on' : 'off' ?></span></h1>
    <p class="muted">Recomps' pages show where to buy the original game, through your partner link. Sales show on <a href="https://www.instant-gaming.com/en/partnership/" target="_blank" rel="noopener">your Instant Gaming dashboard</a>; views and clicks are counted here.</p>
  </div>
  <div class="ig-btns">
    <form method="post"><?= $csrf ?><button name="action" value="launcher" title="Shows the same cards on game pages in RecompLauncher (needs the cards on as well)"><?= $c['launcher'] ? 'Hide in the launcher' : 'Show in the launcher' ?></button></form>
    <form method="post"><?= $csrf ?><button name="action" value="onoff"<?= $c['on'] ? '' : ' class="primary"' ?>><?= $c['on'] ? 'Turn it off' : 'Turn it on' ?></button></form>
  </div>
</div>

<div class="ig-pair">
  <form method="post" class="card ig-set">
    <?= $csrf ?>
    <h2>Partner code</h2>
    <label>igr<input name="igr" value="<?= h($c['igr']) ?>" maxlength="40" pattern="[A-Za-z0-9_-]{1,40}" required></label>
    <p class="muted small">Added to every click, with the visitor's currency: instant-gaming.com/…?igr=<?= h($c['igr']) ?>&amp;currency=USD&amp;utm_source=recomplauncher. It's on your Instant Gaming partnership page.</p>
    <div class="ig-acts"><button name="action" value="code">Save</button></div>
  </form>
  <form method="post" class="card ig-set">
    <?= $csrf ?>
    <h2>Price and discount</h2>
    <label class="check"><input type="checkbox" name="price" value="1"<?= $c['price'] ? ' checked' : '' ?>> Show prices <span class="muted small">(off: the button says See price)</span></label>
    <label class="check"><input type="checkbox" name="tag" value="1"<?= $c['tag'] ? ' checked' : '' ?>> Show the discount tag, but only from <input class="ig-num" type="number" name="tagMin" min="0" max="90" value="<?= (int)$c['tagMin'] ?>">% off</label>
    <p class="muted small"><?= $small ? count($small) . ' listing' . (count($small) === 1 ? ' is' : 's are') . ' under ' . (int)$c['tagMin'] . '% off, so ' . (count($small) === 1 ? 'its tag stays' : 'their tags stay') . ' hidden. A tag like −2% reads as noise.' : 'Small discounts hide their tag and old price, so the card stays clean.' ?></p>
    <div class="ig-acts"><button name="action" value="display">Save</button></div>
  </form>
</div>

<section class="card" id="test">
  <div class="ig-sec-head">
    <div><h2>The test</h2><p class="muted small">Round <?= (int)$c['round'] ?>, since <?= h(rs_date('M j', strtotime($c['roundAt'] . ' 12:00 UTC'))) ?>. Each visitor keeps the version they got first. Shares split new visitors (50 and 50 is even).</p></div>
    <form method="post" onsubmit="return confirm('Start a new round? The numbers start from zero and every visitor gets a version again.')"><?= $csrf ?><button name="action" value="round">Start a new round</button></form>
  </div>
  <?php if ($verdict !== ''): ?><p class="ig-verdict"><?= h($verdict) ?></p><?php endif; ?>
  <form method="post"><?= $csrf ?>
  <div class="ig-rows">
    <?php foreach ($c['variants'] as $v): $n = $stats['v'][$v['id']] ?? []; $isLive = $v['on'] && $v['share'] > 0; $V = strtoupper($v['id']); $k = $v['id']; ?>
    <div class="ig-row">
      <div class="ig-vgrid">
        <div class="ig-field"><label for="ig-n-<?= $k ?>">Version</label><span class="ig-vname"><span class="ig-vid"><?= $V ?></span><input id="ig-n-<?= $k ?>" name="name[<?= $k ?>]" value="<?= h($v['name']) ?>" maxlength="60"></span></div>
        <div class="ig-field"><label for="ig-p-<?= $k ?>">Where it sits</label><select id="ig-p-<?= $k ?>" name="place[<?= $k ?>]"><?php foreach (RS_IG_PLACES as $pk => [$pn]): ?><option value="<?= $pk ?>"<?= $v['place'] === $pk ? ' selected' : '' ?>><?= h($pn) ?></option><?php endforeach; ?></select></div>
        <div class="ig-field"><label for="ig-b-<?= $k ?>">Buy button</label><select id="ig-b-<?= $k ?>" name="btn[<?= $k ?>]"><?php foreach (RS_IG_BUTTONS as $bk => $bn): ?><option value="<?= $bk ?>"<?= $v['btn'] === $bk ? ' selected' : '' ?>><?= h($bn) ?></option><?php endforeach; ?></select></div>
        <div class="ig-field"><label for="ig-s-<?= $k ?>">Share</label><span class="ig-share"><input id="ig-s-<?= $k ?>" class="ig-num" type="number" name="share[<?= $k ?>]" min="0" max="100" value="<?= (int)$v['share'] ?>"><label class="ig-on"><input type="checkbox" name="on[<?= $k ?>]" value="1"<?= $v['on'] ? ' checked' : '' ?>> On</label></span></div>
      </div>
      <div class="ig-rowfoot">
        <span class="ig-res"><b><?= rs_ig_rate($n) ?></b> click rate · <?= $count((int)($n['views'] ?? 0), 'view') ?> · <?= $count((int)($n['clicks'] ?? 0), 'click') ?> · <?= $isLive ? round($v['share'] / $shareSum * 100) . '% of new visitors' : 'not running' ?></span>
        <span class="ig-rowacts">
          <?php if ($peekId !== ''): ?><a class="btn small" href="<?= h($rl . '/game/' . rawurlencode($peekId) . '?igpeek=' . $k) ?>" target="_blank" rel="noopener">Preview</a><?php endif; ?>
          <?php if (count($c['variants']) > 1): ?><button class="small" name="action" value="del_variant" formnovalidate data-ig-del="<?= $k ?>">Remove</button><?php endif; ?>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <input type="hidden" name="id" value="" data-ig-del-field>
  <div class="ig-acts"><button name="action" value="variants" class="primary">Save the test</button><?php if (count($c['variants']) < 4): ?><button name="action" value="add_variant" formnovalidate>Add a version</button><?php endif; ?></div>
  </form>
  <p class="muted small"><?= implode(' · ', array_map(fn($k, $pl) => '<b>' . h($pl[0]) . '</b>: ' . h($pl[1]), array_keys(RS_IG_PLACES), RS_IG_PLACES)) ?>.</p>
  <?php if ($peekId === ''): ?><p class="muted small">Link a recomp below to preview the versions on its page.</p><?php else: ?><p class="muted small">Preview opens <?= h($apps[$peekId]['name'] ?? $peekId) ?> with that version, even while it's off. Previews aren't counted.</p><?php endif; ?>
</section>

<section class="card" id="listings">
  <div class="ig-sec-head"><div><h2>Games with a card</h2><p class="muted small">A game's card shows on every version of it. Prices, discounts and stock are read from Instant Gaming every 3 hours, so the card matches their page.</p></div>
    <?php if ($rows): ?><form method="post"><?= $csrf ?><button name="action" value="refresh_now">Check prices now</button></form><?php endif; ?></div>
  <?php if (!$rows): ?>
  <p class="muted">None yet. Add one below: search the game, paste its page on Instant Gaming, and the rest fills in.</p>
  <?php else: ?>
  <div class="ig-rows">
    <?php foreach ($rows as $r): $g = $games[$r['app_id']] ?? null; $n = $stats['l'][$r['app_id']] ?? []; $open = $firstShown($r['app_id']); $cv = $coverOf($r['app_id']);
        $offs = $offersBy[$r['app_id']] ?? []; $live = array_filter($offs, fn($o) => (int)$o['stock']); $cov = []; $isWorld = (bool)array_filter($live, fn($o) => (int)$o['world']);
        foreach ($live as $o) foreach (json_decode((string)$o['countries'], true) ?: [] as $cc) $cov[$cc] = true;
        $status = !$g || !$g['shown'] ? ['No version out', 'pill warn'] : ($r['paused'] ? ['Paused', 'pill'] : (!$live ? ['Out of stock', 'pill warn'] : ($c['on'] ? ['Showing', 'pill ok'] : ['Ready (switch is off)', 'pill']))); ?>
    <div class="ig-row">
      <div class="ig-lgrid">
        <div class="ig-app"><?php if ($cv !== ''): ?><img src="<?= h($cv) ?>" alt="" loading="lazy"><?php else: ?><span class="ig-nocover" aria-hidden="true"></span><?php endif; ?><div><b title="<?= h($g['name'] ?? $r['app_id']) ?>"><?= h($g['name'] ?? $r['app_id']) ?></b><span class="muted small"><?= h($g['line'] ?? '') ?><?php if ($open !== ''): ?> · <a href="<?= h($rl . '/game/' . rawurlencode($open)) ?>" target="_blank" rel="noopener">Page ↗</a><?php endif; ?></span></div></div>
        <div class="ig-card-cell"><b title="<?= h($r['title']) ?>"><?= h($r['title']) ?></b><span class="muted small"><?= h($r['platform'] ?: 'No platform line') ?></span><span class="muted small"><?= $isWorld ? 'Shown worldwide' : 'Shown in ' . count($cov) . ' countr' . (count($cov) === 1 ? 'y' : 'ies') . ', nowhere else' ?></span></div>
        <div class="ig-stat"><span class="<?= $status[1] ?>"><?= h($status[0]) ?></span></div>
      </div>
      <ul class="ig-offers">
        <?php foreach ($offs as $o): $codes = json_decode((string)$o['countries'], true) ?: []; $unk = json_decode((string)$o['unknown_names'], true) ?: []; $pct = rs_ig_pct($o['price_eur'], $o['old_eur']);
            $when = $o['fetched'] ? rs_ago(max(0, time() - (int)strtotime($o['fetched'] . ' UTC'))) : 'never'; ?>
        <li>
          <span class="ig-reg"><b><?= h($o['region'] ?: 'Region not given') ?></b><?php if ((int)$o['world']): ?> <span class="pill ok">Worldwide</span><?php endif; ?> <a class="small" href="<?= h(rs_ig_link($o['url'], $c['igr'], 'USD')) ?>" target="_blank" rel="noopener">Page ↗</a></span>
          <span class="ig-pr"><?php $pm = json_decode((string)($o['prices'] ?? ''), true) ?: []; foreach ($pm as $pc => $pv): ?><span class="ig-cur"><b><?= h(rs_ig_fmt((float)$pv[0], $pc, $rates)) ?></b><?php if (!empty($pv[1])): ?> <s class="muted small"><?= h(rs_ig_fmt((float)$pv[1], $pc, $rates, true)) ?></s><?php endif; ?></span><?php endforeach; ?><?php if (!$pm): ?><b><?= h(rs_ig_money((float)$o['price_eur'], 'USD', $rates)) ?></b><?php endif; ?></span>
          <details class="ig-cc"><summary><?= count($codes) ?> countr<?= count($codes) === 1 ? 'y' : 'ies' ?><?= $unk ? ' <span class="warn">(' . count($unk) . ' not placed)</span>' : '' ?></summary><p class="small"><?= h(implode(', ', array_map(fn($cc) => $ccName[$cc] ?? $cc, $codes))) ?><?= $unk ? '<br><span class="warn">Names we couldn\'t place (left out): ' . h(implode(', ', $unk)) . '</span>' : '' ?></p></details>
          <span class="muted small"><?= !(int)$o['stock'] ? '<span class="warn">Out of stock</span> · ' : '' ?><?= $o['fetch_err'] !== '' ? '<span class="warn">Last read failed: ' . h($o['fetch_err']) . '</span>' : 'Read ' . h($when) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <div class="ig-rowfoot">
        <span class="ig-res"><b><?= rs_ig_rate($n) ?></b> click rate · <?= $count((int)($n['views'] ?? 0), 'view') ?> · <?= $count((int)($n['clicks'] ?? 0), 'click') ?></span>
        <span class="ig-rowacts">
          <a class="btn small" href="instant-gaming.php?edit=<?= rawurlencode($r['app_id']) ?>#edit">Edit</a>
          <form method="post"><?= $csrf ?><input type="hidden" name="app_id" value="<?= h($r['app_id']) ?>"><button class="small" name="action" value="<?= $r['paused'] ? 'listing_resume' : 'listing_pause' ?>"><?= $r['paused'] ? 'Resume' : 'Pause' ?></button></form>
          <form method="post" onsubmit="return confirm('Remove the card from this recomp?')"><?= $csrf ?><input type="hidden" name="app_id" value="<?= h($r['app_id']) ?>"><button class="small danger" name="action" value="listing_delete">Remove</button></form>
        </span>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<div class="ig-pair ig-pair-wide">
  <form method="post" class="card ig-form" id="edit" data-tagmin="<?= (int)$c['tagMin'] ?>" data-tagon="<?= $c['tag'] ? '1' : '0' ?>" data-igr="<?= h($c['igr']) ?>">
    <?= $csrf ?>
    <?php $eg = $edit ? ($games[$edit['app_id']] ?? null) : null; $editing = $edit && empty($edit['new']); ?>
    <h2><?= $editing ? 'Edit ' . h($eg['name'] ?? $edit['app_id']) : 'Add a game' ?></h2>
    <div class="span2 ig-pick" data-ig-pick>
      <input type="hidden" name="app_id" value="<?= h((string)($edit['app_id'] ?? '')) ?>">
      <label>Game<input type="search" data-ig-q value="<?= h($eg['name'] ?? '') ?>" placeholder="Search a game or one of its versions" autocomplete="off"<?= $editing ? ' readonly' : '' ?>></label>
      <ul class="ig-results" data-ig-results hidden></ul>
      <div class="ig-chosen" data-ig-chosen<?= $eg ? '' : ' hidden' ?>><?php if ($eg): ?><b><?= h($eg['name']) ?></b><span class="muted small"><?= h($eg['line']) ?></span><?php endif; ?></div>
    </div>
    <?php $eUrls = $edit && empty($edit['new']) ? implode("\n", array_column($offersBy[$edit['app_id']] ?? [], 'url')) : ''; ?>
    <label class="span2">Its pages on Instant Gaming, one per region<span class="ig-inline ig-urls"><textarea name="urls" rows="3" required spellcheck="false" placeholder="https://www.instant-gaming.com/en/24209-buy-…  (its US key)&#10;https://www.instant-gaming.com/en/24210-buy-…  (its Latin America key)"><?= h($eUrls) ?></textarea><span class="ig-urlbtns"><button type="button" data-ig-read>Read them</button><a class="btn" data-ig-find href="<?= h($searchUrl((string)($eg['name'] ?? ''))) ?>" target="_blank" rel="noopener">Find it</a></span></span><small>Each page says which countries its key works in. A visitor only sees the card if one of these keys works where they are, priced in their currency, and the button opens that key's page.</small></label>
    <div class="span2 ig-read-out" data-ig-read-out aria-live="polite"></div>
    <?php $eWorld = ''; foreach ($offersBy[$edit['app_id'] ?? ''] ?? [] as $o) if ((int)$o['world']) $eWorld = (string)$o['url']; ?>
    <label class="span2">Worldwide<select name="world" data-ig-world>
      <option value="">Off: only in countries where one of these keys works</option>
      <?php foreach ($offersBy[$edit['app_id'] ?? ''] ?? [] as $o): ?><option value="<?= h($o['url']) ?>"<?= $eWorld === $o['url'] ? ' selected' : '' ?>>On: everywhere else gets the <?= h($o['region'] ?: 'this') ?> key</option><?php endforeach; ?>
    </select><small>On: every visitor sees the card. Countries a regional key covers get that key; everyone else gets the one you pick here, priced in their currency.</small></label>
    <label>Name on the card<input name="title" maxlength="160" value="<?= h((string)($edit['title'] ?? '')) ?>" placeholder="Filled in from the page"></label>
    <label>Platform line<input name="platform" maxlength="60" value="<?= h((string)($edit['platform'] ?? '')) ?>" placeholder="Switch 2"></label>
    <label class="span2">Discount tag<select name="tag"><?php foreach (['By the rule (from ' . (int)$c['tagMin'] . '% off)', 'Always show it', 'Never show it'] as $k => $tn): ?><option value="<?= $k ?>"<?= (int)($edit['tag'] ?? 0) === $k ? ' selected' : '' ?>><?= h($tn) ?></option><?php endforeach; ?></select></label>
    <p class="span2 ig-hint" data-ig-hint aria-live="polite"></p>
    <div class="span2 ig-acts"><button name="action" value="listing_save" class="primary"><?= $edit && empty($edit['new']) ? 'Save' : 'Add it' ?></button><?php if ($edit): ?><a class="btn" href="instant-gaming.php#listings">Cancel</a><?php endif; ?></div>
  </form>
  <section class="card ig-maybe">
    <h2>Might be on Instant Gaming</h2>
    <p class="muted small">Switch and Switch 2 games, the ones most likely sold as keys, most downloaded first.</p>
    <?php if (!$maybe): ?><p class="muted">Nothing left to check.</p><?php else: ?>
    <ul>
      <?php foreach ($maybe as $k => $g): ?>
      <li><div><b><?= h($g['name']) ?></b><span class="muted small"><?= h($g['line']) ?></span></div><span class="ig-rowacts"><a class="btn small" href="<?= h($searchUrl($g['name'])) ?>" target="_blank" rel="noopener">Find</a><a class="btn small" href="instant-gaming.php?add=<?= rawurlencode($k) ?>#edit">Add</a></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>
<script type="application/json" id="ig-games"><?= json_encode($pickList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php rs_foot(['assets/ig.js']); ?>
