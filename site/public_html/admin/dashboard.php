<?php
// Recomp Store admin (2.3): the Dashboard, where logging in lands. Today's numbers, what's waiting
// (signatures, the Inbox, changes not published yet), the latest changes, and every game one click
// away.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_stats_tables();
rs_link_tables();
rs_beta_tables();
rs_analytics_tables();
rs_inbox_table();
rs_log_table();

$today = gmdate('Y-m-d');
$yday = gmdate('Y-m-d', time() - 86400);
$two = fn(string $sql) => [rs_an_int($sql, [$today]), rs_an_int($sql, [$yday])];
$consoles = $two('SELECT COUNT(*) FROM console_days WHERE day = ?');
$installs = $two("SELECT COALESCE(SUM(n), 0) FROM app_events WHERE kind = 'install' AND day = ?");
$updates = $two("SELECT COALESCE(SUM(n), 0) FROM app_events WHERE kind = 'update' AND day = ?");
$newC = $two('SELECT COUNT(*) FROM beta_installs WHERE DATE(created) = ?');
$visitors = $two('SELECT COUNT(*) FROM site_visitors WHERE day = ?');
$views = rs_an_int('SELECT COALESCE(SUM(views), 0) FROM site_pages WHERE day = ?', [$today]);
$betas = $two('SELECT COUNT(*) FROM beta_downloads WHERE day = ?');
$inboxToday = rs_an_int('SELECT COUNT(*) FROM inbox WHERE DATE(created) = CURDATE()');

// What's waiting.
$open = rs_an_pairs("SELECT kind, COUNT(*) FROM inbox WHERE status = 'new' GROUP BY kind");
$sign = rs_sign_waiting();
$published = (string)rs_setting('published_at', '');
$changed = $published !== '' ? rs_an_int('SELECT COUNT(*) FROM games WHERE UNIX_TIMESTAMP(updated) > ?', [(int)strtotime($published)]) : rs_an_int('SELECT COUNT(*) FROM games');
$wait = [
    ['n' => $sign, 'label' => $sign === 1 ? 'Thing to sign' : 'Things to sign', 'note' => 'In Recomp Store Signer on your PC. Until then they reach no one.', 'href' => 'settings.php#signer', 'show' => rs_sign_on()],
    ['n' => $changed, 'label' => $changed === 1 ? 'Game changed since the last publish' : 'Games changed since the last publish', 'note' => $published !== '' ? 'Last published ' . gmdate('M j, g:i a', (int)strtotime($published)) . ' UTC.' : 'Not published yet.', 'href' => 'index.php', 'show' => true],
    ['n' => (int)($open['submit'] ?? 0), 'label' => 'Submissions', 'note' => 'Apps people sent in for the store.', 'href' => 'inbox.php?show=submit', 'show' => true],
    ['n' => (int)($open['logs'] ?? 0), 'label' => 'Logs', 'note' => 'Sent from consoles' . (rs_an_has('inbox', 'feedback') ? ', with testers\' feedback' : '') . '.', 'href' => 'inbox.php?show=logs', 'show' => true],
    ['n' => (int)($open['bug'] ?? 0), 'label' => 'Bug reports', 'note' => 'From the website.', 'href' => 'inbox.php?show=bug', 'show' => true],
    ['n' => (int)($open['message'] ?? 0) + (int)($open['source'] ?? 0), 'label' => 'Messages and sources', 'note' => 'Everything else in the Inbox.', 'href' => 'inbox.php', 'show' => true],
];
$wait = array_values(array_filter($wait, fn($w) => $w['show']));
$anyWaiting = array_sum(array_column($wait, 'n')) > 0;

$acts = rs_an_rows('SELECT at, who, action, target, detail FROM admin_log ORDER BY at DESC, id DESC LIMIT 8');
$actNames = ['login' => 'Logged in', 'login-failed' => 'Failed login', 'publish' => 'Published', 'signed' => 'Signed', 'change' => 'Changed',
    'settings' => 'Settings', 'signer' => 'Signer', 'two-step' => 'Two-step login', 'discover' => 'Discover'];
$ago = function (string $at): string {
    $t = strtotime($at) ?: 0;
    $s = time() - $t;
    if ($s < 60) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    return date('M j', $t);
};

$games = rs_games(true);
$installed = rs_an_pairs('SELECT app_id, COUNT(*) FROM app_installs WHERE removed_at IS NULL GROUP BY app_id');

// The last 14 days, small.
$charts = ['week' => rs_an_chart([
    'Consoles' => rs_series('SELECT day, COUNT(*) FROM console_days WHERE day >= ? GROUP BY day', [gmdate('Y-m-d', time() - 13 * 86400)], 14),
    'Installs' => rs_series("SELECT day, SUM(n) FROM app_events WHERE kind = 'install' AND day >= ? GROUP BY day", [gmdate('Y-m-d', time() - 13 * 86400)], 14)])];

$vs = function (int $now, int $then): string {
    if ($now === $then) return 'Same as yesterday';
    return '<span class="' . ($now > $then ? 'up' : 'down') . '">' . ($now > $then ? '+' : '−') . number_format(abs($now - $then)) . '</span> on yesterday (' . number_format($then) . ')';
};
$kpi = fn(string $label, int $v, string $small = '') => '<div class="kpi"><span>' . h($label) . '</span><strong>' . rs_an_fmt($v) . '</strong>' . ($small !== '' ? '<small>' . $small . '</small>' : '') . '</div>';
$catNames = RS_CATEGORIES;

rs_head('Dashboard', true, ['assets/analytics.css']);
?>
<section class="head-row">
  <div><h1>Dashboard</h1><p class="muted">Today so far, in UTC (<?= h(gmdate('D M j, g:i a')) ?>).</p></div>
  <div class="actions">
    <a class="btn" href="edit.php">Add a game</a>
    <form method="post" action="index.php"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish"><button class="primary">Publish</button></form>
  </div>
</section>
<nav class="dash-links" aria-label="Go to">
  <a href="index.php">Games</a><a href="discover.php">Discover</a><a href="beta.php">Beta</a><a href="inbox.php">Inbox</a>
  <a href="analytics.php">Analytics</a><a href="analytics.php?tab=apps">Apps</a><a href="analytics.php?tab=downloads">Downloads</a>
  <a href="import.php">Import</a><a href="settings.php">Settings</a><a href="log.php">Change log</a><a href="/" target="_blank" rel="noopener">View site</a>
</nav>
<div class="kpis k6">
  <?= $kpi('Consoles today', $consoles[0], $vs($consoles[0], $consoles[1])) ?>
  <?= $kpi('New consoles', $newC[0], $vs($newC[0], $newC[1])) ?>
  <?= $kpi('Installs today', $installs[0], number_format($updates[0]) . ($updates[0] === 1 ? ' update' : ' updates') . ' · yesterday ' . number_format($installs[1])) ?>
  <?= $kpi('Website visitors', $visitors[0], number_format($views) . ' page views · yesterday ' . number_format($visitors[1])) ?>
  <?= $kpi('Beta downloads', $betas[0], 'Yesterday ' . number_format($betas[1])) ?>
  <?= $kpi('Inbox today', $inboxToday, '<a href="inbox.php">' . (array_sum($open) ? number_format(array_sum($open)) . ' not read' : 'All read') . '</a>') ?>
</div>
<div class="dash-grid">
  <section class="card">
    <h2>Waiting for you</h2>
    <?php if (!$anyWaiting): ?><p class="muted small-note">Nothing waiting. Everything's signed, published and read.</p><?php endif; ?>
    <ul class="wait">
      <?php foreach ($wait as $w): ?>
      <li><a href="<?= h($w['href']) ?>"<?= $w['n'] ? '' : ' class="zero"' ?>><span class="n"><?= (int)$w['n'] ?></span><span><?= h($w['label']) ?><small><?= h($w['note']) ?></small></span><span class="go" aria-hidden="true">→</span></a></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="card">
    <div class="an-head"><h2>Latest changes</h2><a class="an-csv an-plain" href="log.php">Change log</a></div>
    <?php if (!$acts): ?><p class="muted">Nothing yet.</p><?php else: ?>
    <ul class="acts">
      <?php foreach ($acts as $r): ?>
      <li><time datetime="<?= h(date('c', strtotime((string)$r['at']) ?: 0)) ?>" title="<?= h((string)$r['at']) ?>"><?= h($ago((string)$r['at'])) ?></time>
        <span class="what"><?= h($actNames[(string)$r['action']] ?? ucfirst(str_replace('phone ', 'Phone: ', (string)$r['action']))) ?><?= (string)$r['target'] !== '' ? ': ' . h((string)$r['target']) : '' ?><small><?= h(trim(((string)$r['who'] !== '' ? (string)$r['who'] . ' · ' : '') . mb_strimwidth((string)$r['detail'], 0, 120, '…'), ' ·')) ?></small></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
</div>
<section class="card chart-card">
  <div class="an-head"><h2>The last 14 days</h2><a class="an-csv an-plain" href="analytics.php">Analytics</a></div>
  <div class="chart" data-chart="week" tabindex="0" role="img" aria-label="Consoles that opened the store and installs, per day for the last 14 days. Use the arrow keys to read each day."></div>
</section>
<section class="card">
  <div class="an-head"><h2>Games</h2><span class="muted an-count"><?= count($games) ?></span><a class="an-csv an-plain" href="index.php">Order and publish</a></div>
  <?php if (!$games): ?><p class="muted">No games yet. <a href="edit.php">Add one</a>.</p><?php else: ?>
  <div class="dash-games">
    <?php foreach ($games as $g):
      $tags = [];
      if ($g['hidden']) $tags[] = 'Hidden';
      if (!empty($g['testers'])) $tags[] = 'Testers only';
      if ($g['beta']) $tags[] = 'Beta';
      if ($g['soon']) $tags[] = 'Coming soon';
      if ($g['featured']) $tags[] = 'Featured';
      $line = $tags ? implode(' · ', $tags) : (($catNames[rs_category((string)$g['category'])] ?? '') . ($g['version'] !== '' ? ' · ' . $g['version'] : ''));
      if (!empty($installed[$g['id']])) $line .= ' · ' . number_format($installed[$g['id']]) . ' on consoles'; ?>
    <a class="g<?= $g['hidden'] ? ' dim' : '' ?>" href="edit.php?id=<?= h(rawurlencode($g['id'])) ?>">
      <?php if ($g['icon']): ?><img src="<?= h($g['icon']) ?>" alt="" loading="lazy"><?php else: ?><span class="icon"><?= h(mb_substr($g['name'] ?: '?', 0, 2)) ?></span><?php endif; ?>
      <span class="nm"><strong><?= h($g['name'] ?: 'Untitled') ?></strong><small><?= h($line) ?></small></span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<script>window.RS_CHARTS = <?= json_encode((object)$charts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<?php rs_foot(['assets/analytics.js']);
