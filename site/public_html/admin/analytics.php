<?php
// Recomp Store admin (2.1): Analytics. Consoles using the store, installs, updates and removals per
// app, betas, store versions, and the website's visits, for the last 7, 30 or 90 days. Counted by
// Recomp Store 2.0 and newer and by the website itself: no cookies, nothing that says who anyone is.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_stats_tables();
rs_link_tables();
rs_beta_tables();
rs_analytics_tables();
$db = rs_db();

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) $days = 30;
$from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
$n = function (string $q, array $a = []) use ($db): int { $st = $db->prepare($q); $st->execute($a); return (int)$st->fetchColumn(); };

// Headline numbers for the range.
$today = $n('SELECT COUNT(*) FROM console_days WHERE day = UTC_DATE()');
$active = $n('SELECT COUNT(DISTINCT install_id) FROM console_days WHERE day >= ?', [$from]);
$newConsoles = $n('SELECT COUNT(*) FROM beta_installs WHERE created >= ?', [$from]);
$installs = $n("SELECT COALESCE(SUM(n),0) FROM app_events WHERE kind = 'install' AND day >= ?", [$from]);
$updates = $n("SELECT COALESCE(SUM(n),0) FROM app_events WHERE kind = 'update' AND day >= ?", [$from]);
$visitors = $n('SELECT COUNT(*) FROM site_visitors WHERE day >= ?', [$from]);
$views = $n('SELECT COALESCE(SUM(views),0) FROM site_pages WHERE day >= ?', [$from]);
$phones = $n('SELECT COUNT(*) FROM link_devices WHERE install_id IS NOT NULL');
$allConsoles = $n('SELECT COUNT(*) FROM beta_installs');

// Day by day.
$charts = [
    'consoles' => ['title' => 'Consoles that opened Recomp Store', 'series' => [
        ['name' => 'Consoles', 'data' => rs_series('SELECT day, COUNT(*) FROM console_days WHERE day >= ? GROUP BY day', [$from], $days)]]],
    'apps' => ['title' => 'Installs and updates', 'series' => [
        ['name' => 'Installs', 'data' => rs_series("SELECT day, SUM(n) FROM app_events WHERE kind = 'install' AND day >= ? GROUP BY day", [$from], $days)],
        ['name' => 'Updates', 'data' => rs_series("SELECT day, SUM(n) FROM app_events WHERE kind = 'update' AND day >= ? GROUP BY day", [$from], $days)]]],
    'site' => ['title' => 'Website visitors and page views', 'series' => [
        ['name' => 'Visitors', 'data' => rs_series('SELECT day, COUNT(*) FROM site_visitors WHERE day >= ? GROUP BY day', [$from], $days)],
        ['name' => 'Page views', 'data' => rs_series('SELECT day, SUM(views) FROM site_pages WHERE day >= ? GROUP BY day', [$from], $days)]]],
];

// Apps: on consoles now, and what happened in the range.
$names = [];
foreach (rs_games() as $g) $names[$g['id']] = $g['name'];
$apps = [];
foreach ($db->query('SELECT app_id, SUM(removed_at IS NULL) have, COUNT(*) ever FROM app_installs GROUP BY app_id') as $r) $apps[$r['app_id']] = ['have' => (int)$r['have'], 'ever' => (int)$r['ever'], 'install' => 0, 'update' => 0, 'remove' => 0];
$st = $db->prepare('SELECT app_id, kind, SUM(n) n FROM app_events WHERE day >= ? GROUP BY app_id, kind');
$st->execute([$from]);
foreach ($st as $r) {
    $apps[$r['app_id']] ??= ['have' => 0, 'ever' => 0, 'install' => 0, 'update' => 0, 'remove' => 0];
    $apps[$r['app_id']][$r['kind']] = (int)$r['n'];
}
uasort($apps, fn($a, $b) => [$b['have'], $b['install']] <=> [$a['have'], $a['install']]);

$versions = $db->query("SELECT store_version, COUNT(*) n FROM beta_installs WHERE last_seen > (NOW() - INTERVAL 30 DAY) AND store_version <> '' GROUP BY store_version ORDER BY n DESC LIMIT 12")->fetchAll();
$vMax = max(1, ...array_map(fn($v) => (int)$v['n'], $versions ?: [['n' => 1]]));

$q = fn(string $sql) => (function () use ($db, $sql, $from) { $s = $db->prepare($sql); $s->execute([$from]); return $s->fetchAll(); })();
$pages = $q('SELECT path, SUM(views) n FROM site_pages WHERE day >= ? GROUP BY path ORDER BY n DESC LIMIT 15');
$refs = $q("SELECT ref, COUNT(*) n FROM site_visitors WHERE day >= ? GROUP BY ref ORDER BY n DESC LIMIT 12");
$devices = $q('SELECT device, COUNT(*) n FROM site_visitors WHERE day >= ? GROUP BY device ORDER BY n DESC');
$betaRows = [];
foreach (rs_games(true) as $g) {
    if (!$g['beta']) continue;
    $c = $db->prepare('SELECT COUNT(*) all_codes, SUM(install_id IS NOT NULL) used FROM beta_codes WHERE game_id = ? AND revoked = 0');
    $c->execute([$g['id']]);
    $cc = $c->fetch();
    $betaRows[] = ['name' => $g['name'], 'version' => $g['beta_version'] ?: $g['version'], 'codes' => (int)$cc['all_codes'], 'used' => (int)$cc['used'],
        'downloads' => $n('SELECT COUNT(*) FROM beta_downloads WHERE game_id = ? AND day >= ?', [$g['id'], $from])];
}
$deviceNames = ['ps5' => 'PS5 browser', 'phone' => 'Phone', 'tablet' => 'Tablet', 'computer' => 'Computer'];
$fmt = fn(int $v) => $v >= 10000 ? round($v / 1000, $v >= 100000 ? 0 : 1) . 'K' : number_format($v);
$bar = fn(int $v, int $max) => '<span class="ibar" style="--w:' . round(100 * $v / max(1, $max), 1) . '%" aria-hidden="true"></span>';

rs_head('Analytics');
?>
<section class="head-row">
  <div><h1>Analytics</h1><p class="muted">Counted by Recomp Store 2.0 and newer and by the website, in UTC days. No cookies and nothing that says who anyone is. Started counting day by day with 2.1, so earlier days show 0.</p></div>
</section>
<nav class="chips" aria-label="Range">
  <?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $d => $label): ?>
  <a class="chip<?= $days === $d ? ' on' : '' ?>" href="analytics.php?days=<?= $d ?>"<?= $days === $d ? ' aria-current="page"' : '' ?>><?= h($label) ?></a>
  <?php endforeach; ?>
</nav>
<div class="kpis">
  <div class="kpi"><span>Consoles today</span><strong><?= $fmt($today) ?></strong></div>
  <div class="kpi"><span>Consoles in <?= $days ?> days</span><strong><?= $fmt($active) ?></strong><small><?= $fmt($newConsoles) ?> new · <?= $fmt($allConsoles) ?> ever</small></div>
  <div class="kpi"><span>Installs</span><strong><?= $fmt($installs) ?></strong><small><?= $fmt($updates) ?> updates</small></div>
  <div class="kpi"><span>Website visitors</span><strong><?= $fmt($visitors) ?></strong><small><?= $fmt($views) ?> page views</small></div>
  <div class="kpi"><span>Linked phones and PCs</span><strong><?= $fmt($phones) ?></strong></div>
</div>
<?php foreach ($charts as $key => $c): ?>
<section class="card chart-card">
  <h2><?= h($c['title']) ?></h2>
  <div class="chart" data-chart="<?= h($key) ?>" tabindex="0" role="img" aria-label="<?= h($c['title']) ?>, per day. Use the arrow keys to read each day."></div>
  <details><summary>Show as a table</summary>
    <div class="table-wrap"><table class="stats"><thead><tr><th>Day</th><?php foreach ($c['series'] as $s): ?><th class="n"><?= h($s['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach (array_reverse(array_keys($c['series'][0]['data'])) as $day): ?><tr><td><?= h(date('D M j', strtotime($day))) ?></td><?php foreach ($c['series'] as $s): ?><td class="n"><?= (int)$s['data'][$day] ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
  </details>
</section>
<?php endforeach; ?>
<section class="card">
  <h2>Apps</h2>
  <?php if (!$apps): ?><p class="muted">No installs counted yet. Stores from 2.0 on count them.</p><?php else: ?>
  <div class="table-wrap"><table class="stats">
    <thead><tr><th>App</th><th class="n">On consoles now</th><th class="n">Installs</th><th class="n">Updates</th><th class="n">Removed</th><th class="n">Ever installed</th></tr></thead>
    <tbody>
    <?php foreach (array_slice($apps, 0, 300, true) as $id => $r): ?>
      <tr><td><?= h($names[$id] ?? $id) ?> <span class="muted"><?= h((string)$id) ?></span></td><td class="n"><?= $r['have'] ?></td><td class="n"><?= $r['install'] ?></td><td class="n"><?= $r['update'] ?></td><td class="n"><?= $r['remove'] ?></td><td class="n"><?= $r['ever'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted small-note">Installs, updates and removals are in the range above; "on consoles now" and "ever" are all time.</p>
  <?php endif; ?>
</section>
<div class="grid2 analytics-pair">
<section class="card">
  <h2>Store versions in use</h2>
  <?php if (!$versions): ?><p class="muted">Nothing yet.</p><?php else: ?>
  <table class="stats bars"><thead><tr><th>Version</th><th class="n">Consoles (30 days)</th></tr></thead><tbody>
  <?php foreach ($versions as $v): ?><tr><td><?= h($v['store_version']) ?></td><td class="n"><?= $bar((int)$v['n'], $vMax) ?><?= (int)$v['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Betas</h2>
  <?php if (!$betaRows): ?><p class="muted">No betas right now.</p><?php else: ?>
  <table class="stats"><thead><tr><th>Beta</th><th class="n">Codes used</th><th class="n">Downloads</th></tr></thead><tbody>
  <?php foreach ($betaRows as $b): ?><tr><td><?= h($b['name']) ?> <span class="muted"><?= h($b['version']) ?></span></td><td class="n"><?= $b['used'] ?> of <?= $b['codes'] ?></td><td class="n"><?= $b['downloads'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="muted small-note">Downloads from this site, one per console a day. Downloads from Cloudflare R2 go straight to Cloudflare and aren't counted here.</p>
  <?php endif; ?>
</section>
</div>
<div class="grid2 analytics-pair">
<section class="card">
  <h2>Website pages</h2>
  <?php if (!$pages): ?><p class="muted">No visits counted yet.</p><?php else: $pm = (int)$pages[0]['n']; ?>
  <table class="stats bars"><thead><tr><th>Page</th><th class="n">Views</th></tr></thead><tbody>
  <?php foreach ($pages as $p): ?><tr><td><code><?= h((string)$p['path']) ?></code></td><td class="n"><?= $bar((int)$p['n'], $pm) ?><?= (int)$p['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Where visitors came from</h2>
  <?php if (!$refs): ?><p class="muted">No visits counted yet.</p><?php else: $rm = (int)$refs[0]['n']; ?>
  <table class="stats bars"><thead><tr><th>From</th><th class="n">Visitors</th></tr></thead><tbody>
  <?php foreach ($refs as $r): ?><tr><td><?= $r['ref'] === 'direct' ? 'Typed in, or a bookmark or app' : h((string)$r['ref']) ?></td><td class="n"><?= $bar((int)$r['n'], $rm) ?><?= (int)$r['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <h3>Devices</h3>
  <table class="stats bars"><tbody>
  <?php $dm = max(1, ...array_map(fn($d) => (int)$d['n'], $devices ?: [['n' => 1]])); foreach ($devices as $d): ?><tr><td><?= h($deviceNames[$d['device']] ?? $d['device']) ?></td><td class="n"><?= $bar((int)$d['n'], $dm) ?><?= (int)$d['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
</div>
<script>window.RS_CHARTS = <?= json_encode(array_map(fn($c) => ['series' => array_map(fn($s) => ['name' => $s['name'], 'values' => array_values($s['data'])], $c['series']), 'days' => array_keys($c['series'][0]['data'])], $charts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php rs_foot(['assets/analytics.js']);
