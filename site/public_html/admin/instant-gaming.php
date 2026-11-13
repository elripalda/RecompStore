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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'instant-gaming.php';
    try {
        if ($act === 'onoff') {
            $c['on'] = !$c['on'];
            rs_ig_save($c);
            rs_log('sponsors', 'Instant Gaming', $c['on'] ? 'on' : 'off');
            rs_flash($c['on'] ? 'On. Linked recomps show the card within 2 minutes.' : 'Off. The cards are gone within 2 minutes.');
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
            if (!isset($apps[$id])) throw new RuntimeException('Pick a recomp from the list.');
            $url = rs_ig_clean_url((string)($_POST['url'] ?? ''));
            if ($url === '') throw new RuntimeException('Paste the game\'s page on instant-gaming.com (it starts with https://www.instant-gaming.com/).');
            $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 160));
            if ($title === '') throw new RuntimeException('Give the card the game\'s name.');
            $platform = trim(mb_substr((string)($_POST['platform'] ?? ''), 0, 60));
            $num = function ($v) { $v = str_replace(',', '.', trim((string)$v)); return $v === '' ? null : (is_numeric($v) && (float)$v > 0 && (float)$v < 100000 ? round((float)$v, 2) : false); };
            $price = $num($_POST['price'] ?? '');
            $old = $num($_POST['old_price'] ?? '');
            if ($price === false || $old === false) throw new RuntimeException('Prices are numbers, like 57.68.');
            if ($old !== null && $price !== null && $old <= $price) $old = null;
            $cur = in_array($_POST['currency'] ?? '', ['USD', 'EUR', 'GBP'], true) ? $_POST['currency'] : 'USD';
            $tag = max(0, min(2, (int)($_POST['tag'] ?? 0)));
            $db->prepare('INSERT INTO ig_listings (app_id, url, title, platform, price, old_price, currency, tag, paused, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW(), NOW())
                ON DUPLICATE KEY UPDATE url = VALUES(url), title = VALUES(title), platform = VALUES(platform), price = VALUES(price), old_price = VALUES(old_price), currency = VALUES(currency), tag = VALUES(tag), updated = NOW()')
                ->execute([$id, $url, $title, $platform, $price, $old, $cur, $tag]);
            rs_log('sponsors', 'Instant Gaming', 'listing ' . $id);
            rs_flash('Saved ' . $apps[$id]['name'] . '.' . ($c['on'] ? ' Its card shows within 2 minutes.' : ' It shows once Instant Gaming is turned on.'));
            $back .= '#listings';
        } elseif (in_array($act, ['listing_pause', 'listing_resume', 'listing_delete', 'listing_rule'], true)) {
            $id = (string)($_POST['app_id'] ?? '');
            if ($act === 'listing_delete') {
                $db->prepare('DELETE FROM ig_listings WHERE app_id = ?')->execute([$id]);
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
    header('Location: ' . $back);
    exit;
}

$rows = rs_ig_listings();
$linked = array_column($rows, null, 'app_id');
$stats = rs_ig_stats($c['round']);
$live = rs_ig_live_variants($c);
$shareSum = array_sum(array_column($live, 'share')) ?: 1;
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$rl = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host) ? '' : 'https://recomplauncher.com';
$peekId = '';
foreach ($rows as $r) if (!$r['paused'] && isset($apps[$r['app_id']]) && empty($apps[$r['app_id']]['hidden'])) { $peekId = $r['app_id']; break; }
if ($peekId === '' && $rows) $peekId = $rows[0]['app_id'];
$small = array_values(array_filter($rows, fn($r) => ($p = rs_ig_pct($r['price'], $r['old_price'])) > 0 && $p < $c['tagMin']));
$edit = null;
if (isset($_GET['edit']) && isset($linked[(string)$_GET['edit']])) $edit = $linked[(string)$_GET['edit']];
if (!$edit && isset($_GET['add']) && isset($apps[(string)$_GET['add']])) {
    $a = $apps[(string)$_GET['add']];
    $edit = ['app_id' => $a['id'], 'url' => '', 'title' => $a['game'] ?: $a['name'], 'platform' => '', 'price' => null, 'old_price' => null, 'currency' => 'USD', 'tag' => 0, 'new' => true];
}
// Recomps of games still sold today (Switch and Switch 2) that aren't linked yet, most downloaded first.
$maybe = array_values(array_filter($apps, fn($a) => empty($a['hidden']) && empty($a['soon']) && in_array($a['origin'] ?? '', ['switch2', 'switch'], true) && !isset($linked[$a['id']])));
usort($maybe, fn($x, $y) => (int)$y['downloads'] <=> (int)$x['downloads']);
$maybe = array_slice($maybe, 0, 8);
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
$pickList = [];
foreach ($apps as $a) if (empty($a['hidden'])) $pickList[$a['id']] = ['n' => (string)$a['name'], 'g' => (string)($a['game'] ?: $a['name'])];
uasort($pickList, fn($x, $y) => strcasecmp($x['n'], $y['n']));
$searchUrl = fn(string $q): string => 'https://www.instant-gaming.com/en/search/?q=' . rawurlencode($q) . '&igr=' . rawurlencode($c['igr']);
$count = fn(int $n, string $w): string => number_format($n) . ' ' . $w . ($n === 1 ? '' : 's');
$money = fn($v, string $cur): string => $v === null || (float)$v <= 0 ? '' : (['EUR' => '€', 'GBP' => '£'][$cur] ?? '$') . number_format((float)$v, 2);
rs_head('Instant Gaming', true, ['assets/ig.css']);
?>
<div class="ig-head">
  <div>
    <h1>Instant Gaming <span class="<?= $c['on'] ? 'pill ok' : 'pill' ?>"><?= $c['on'] ? 'On' : 'Off' ?></span></h1>
    <p class="muted">Recomps' pages show where to buy the original game, through your partner link. Sales show on <a href="https://www.instant-gaming.com/en/partnership/" target="_blank" rel="noopener">your Instant Gaming dashboard</a>; views and clicks are counted here.</p>
  </div>
  <form method="post"><?= $csrf ?><button name="action" value="onoff"<?= $c['on'] ? '' : ' class="primary"' ?>><?= $c['on'] ? 'Turn it off' : 'Turn it on' ?></button></form>
</div>

<div class="ig-pair">
  <form method="post" class="card ig-set">
    <?= $csrf ?>
    <h2>Partner code</h2>
    <label>igr<input name="igr" value="<?= h($c['igr']) ?>" maxlength="40" pattern="[A-Za-z0-9_-]{1,40}" required></label>
    <p class="muted small">Added to every click, like instant-gaming.com/…?igr=<?= h($c['igr']) ?>. It's on your Instant Gaming partnership page.</p>
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
  <div class="ig-sec-head"><div><h2>Recomps with a card</h2><p class="muted small">Only these recomps show it. Prices change on Instant Gaming and differ by country, so check them now and then (or turn prices off).</p></div></div>
  <?php if (!$rows): ?>
  <p class="muted">None yet. Add one below: pick the recomp, paste the game's page on Instant Gaming, and fill in the price.</p>
  <?php else: ?>
  <div class="ig-rows">
    <?php foreach ($rows as $r): $a = $apps[$r['app_id']] ?? null; $n = $stats['l'][$r['app_id']] ?? []; $pct = rs_ig_pct($r['price'], $r['old_price']); $deal = rs_ig_shows_deal($r, $c);
        $status = !$a || !empty($a['hidden']) ? ['Recomp hidden', 'pill warn'] : ($r['paused'] ? ['Paused', 'pill'] : ($c['on'] ? ['Showing', 'pill ok'] : ['Ready (switch is off)', 'pill'])); ?>
    <div class="ig-row">
      <div class="ig-lgrid">
        <div class="ig-app"><?php if ($a && ($a['cover'] ?? '') !== ''): ?><img src="<?= h($a['cover']) ?>" alt="" loading="lazy"><?php else: ?><span class="ig-nocover" aria-hidden="true"></span><?php endif; ?><div><b title="<?= h($a['name'] ?? $r['app_id']) ?>"><?= h($a['name'] ?? $r['app_id']) ?></b><a class="small" href="<?= h($rl . '/game/' . rawurlencode($r['app_id'])) ?>" target="_blank" rel="noopener">Its page ↗</a></div></div>
        <div class="ig-card-cell"><b title="<?= h($r['title']) ?>"><?= h($r['title']) ?></b><span class="muted small"><?= h($r['platform'] ?: 'No platform line') ?> · <a href="<?= h($r['url']) ?>" target="_blank" rel="noopener">On Instant Gaming ↗</a></span></div>
        <div class="ig-price"><?php if ($r['price'] !== null): ?><b><?= h($money($r['price'], $r['currency'])) ?></b><?php if ($pct): ?><s class="muted"><?= h($money($r['old_price'], $r['currency'])) ?></s><span class="<?= $deal ? 'pill ok' : 'pill' ?>" title="<?= $deal ? 'The tag shows' : 'The tag is hidden' ?>">−<?= $pct ?>%<?= $deal ? '' : ' hidden' ?></span><?php endif; ?><?php else: ?><span class="muted">No price</span><?php endif; ?></div>
        <div class="ig-stat"><span class="<?= $status[1] ?>"><?= h($status[0]) ?></span></div>
      </div>
      <?php if ((int)$r['tag'] === 1 && $pct > 0 && $pct < $c['tagMin']): ?><div class="ig-warn"><span>Only <?= $pct ?>% off, and its tag is set to always show. A tag this small can look like noise.</span><form method="post"><?= $csrf ?><input type="hidden" name="app_id" value="<?= h($r['app_id']) ?>"><button class="small" name="action" value="listing_rule">Hide it (follow the rule)</button></form></div><?php endif; ?>
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
    <h2><?= $edit && empty($edit['new']) ? 'Edit ' . h($apps[$edit['app_id']]['name'] ?? $edit['app_id']) : 'Add a recomp' ?></h2>
    <label class="span2">Recomp<input name="app_id" list="ig-apps" required value="<?= h((string)($edit['app_id'] ?? '')) ?>" placeholder="Start typing its name" autocomplete="off"<?= $edit && empty($edit['new']) ? ' readonly' : '' ?>></label>
    <datalist id="ig-apps"><?php foreach ($pickList as $id => $p): ?><option value="<?= h($id) ?>"><?= h($p['n'] . ($p['g'] !== $p['n'] ? ' · ' . $p['g'] : '')) ?></option><?php endforeach; ?></datalist>
    <label class="span2">The game on Instant Gaming<span class="ig-inline"><input name="url" type="url" required value="<?= h((string)($edit['url'] ?? '')) ?>" placeholder="https://www.instant-gaming.com/en/…"><a class="btn" data-ig-find href="<?= h($searchUrl((string)($edit['title'] ?? ''))) ?>" target="_blank" rel="noopener">Find it</a></span></label>
    <label>Name on the card<input name="title" required maxlength="160" value="<?= h((string)($edit['title'] ?? '')) ?>" placeholder="The Legend of Zelda: Ocarina of Time"></label>
    <label>Platform line<input name="platform" maxlength="60" value="<?= h((string)($edit['platform'] ?? '')) ?>" placeholder="Switch 2 key"></label>
    <label>Price<input name="price" inputmode="decimal" value="<?= $edit && $edit['price'] !== null ? h(number_format((float)$edit['price'], 2, '.', '')) : '' ?>" placeholder="57.68"></label>
    <label>Was (before the discount)<input name="old_price" inputmode="decimal" value="<?= $edit && $edit['old_price'] !== null ? h(number_format((float)$edit['old_price'], 2, '.', '')) : '' ?>" placeholder="59.00"></label>
    <label>Currency<select name="currency"><?php foreach (['USD' => 'US dollars ($)', 'EUR' => 'Euros (€)', 'GBP' => 'Pounds (£)'] as $k => $cn): ?><option value="<?= $k ?>"<?= ($edit['currency'] ?? 'USD') === $k ? ' selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?></select></label>
    <label>Discount tag<select name="tag"><?php foreach (['By the rule (from ' . (int)$c['tagMin'] . '% off)', 'Always show it', 'Never show it'] as $k => $tn): ?><option value="<?= $k ?>"<?= (int)($edit['tag'] ?? 0) === $k ? ' selected' : '' ?>><?= h($tn) ?></option><?php endforeach; ?></select></label>
    <p class="span2 ig-hint" data-ig-hint aria-live="polite"></p>
    <div class="span2 ig-acts"><button name="action" value="listing_save" class="primary"><?= $edit && empty($edit['new']) ? 'Save' : 'Add it' ?></button><?php if ($edit): ?><a class="btn" href="instant-gaming.php#listings">Cancel</a><?php endif; ?></div>
  </form>
  <section class="card ig-maybe">
    <h2>Might be on Instant Gaming</h2>
    <p class="muted small">Recomps of Switch and Switch 2 games, the ones most likely sold as keys, most downloaded first.</p>
    <?php if (!$maybe): ?><p class="muted">Nothing left to check.</p><?php else: ?>
    <ul>
      <?php foreach ($maybe as $a): ?>
      <li><div><b><?= h($a['name']) ?></b><span class="muted small"><?= h($a['game'] ?: $a['name']) ?></span></div><span class="ig-rowacts"><a class="btn small" href="<?= h($searchUrl((string)($a['game'] ?: $a['name']))) ?>" target="_blank" rel="noopener">Find</a><a class="btn small" href="instant-gaming.php?add=<?= rawurlencode($a['id']) ?>#edit">Add</a></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>
<script type="application/json" id="ig-names"><?= json_encode(array_map(fn($p) => $p['g'], $pickList), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<?php rs_foot(['assets/ig.js']); ?>
