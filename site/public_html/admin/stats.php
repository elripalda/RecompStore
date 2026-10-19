<?php
// Recomp Store admin (2.0): how many consoles have each app (the store says when it installs or
// removes one), how many consoles opened the store lately, and how many phones are linked.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_stats_tables();
rs_link_tables();
$db = rs_db();
$n = fn(string $q) => (int)$db->query($q)->fetchColumn();
$week = $n('SELECT COUNT(*) FROM beta_installs WHERE last_seen > (NOW() - INTERVAL 7 DAY)');
$month = $n('SELECT COUNT(*) FROM beta_installs WHERE last_seen > (NOW() - INTERVAL 30 DAY)');
$all = $n('SELECT COUNT(*) FROM beta_installs');
$phones = $n('SELECT COUNT(*) FROM link_devices WHERE install_id IS NOT NULL');
$versions = $db->query("SELECT store_version, COUNT(*) n FROM beta_installs WHERE last_seen > (NOW() - INTERVAL 30 DAY) AND store_version <> '' GROUP BY store_version ORDER BY n DESC LIMIT 12")->fetchAll();
$rows = $db->query('SELECT app_id, SUM(removed_at IS NULL) have, COUNT(*) ever, SUM(first_at > (NOW() - INTERVAL 7 DAY)) week FROM app_installs GROUP BY app_id ORDER BY have DESC, ever DESC LIMIT 300')->fetchAll();
$names = [];
foreach (rs_games() as $g) $names[$g['id']] = $g['name'];
rs_head('Stats');
?>
<section class="head-row"><div><h1>Stats</h1><p class="muted">Counted by Recomp Store 2.0 and newer: each console once per app. Nothing that tells who anyone is.</p></div></section>
<div class="stats-tiles">
  <div><strong><?= number_format($week) ?></strong><span>consoles opened the store this week</span></div>
  <div><strong><?= number_format($month) ?></strong><span>in the last 30 days</span></div>
  <div><strong><?= number_format($all) ?></strong><span>consoles ever</span></div>
  <div><strong><?= number_format($phones) ?></strong><span>phones and computers linked</span></div>
</div>
<section class="card">
  <h2>Apps</h2>
  <?php if (!$rows): ?><p class="muted">No installs counted yet. Stores from 2.0 on count them.</p><?php else: ?>
  <table class="stats">
    <thead><tr><th>App</th><th class="n">On consoles now</th><th class="n">New this week</th><th class="n">Ever installed</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= h($names[$r['app_id']] ?? $r['app_id']) ?> <span class="muted"><?= h($r['app_id']) ?></span></td><td class="n"><?= (int)$r['have'] ?></td><td class="n"><?= (int)$r['week'] ?></td><td class="n"><?= (int)$r['ever'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php if ($versions): ?>
<section class="card">
  <h2>Store versions in use</h2>
  <table class="stats"><thead><tr><th>Version</th><th class="n">Consoles (30 days)</th></tr></thead><tbody>
  <?php foreach ($versions as $v): ?><tr><td><?= h($v['store_version']) ?></td><td class="n"><?= (int)$v['n'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
</section>
<?php endif; ?>
<?php rs_foot();
