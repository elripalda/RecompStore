<?php
// RecompStore admin (3.1g): who's using RecompLauncher, from its anonymous pings. Live now
// (refreshes itself), active players by day, versions, and the most played games.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/lstats.php';
rs_require_login();

// The live block, for the page to refresh every 20 seconds.
if (isset($_GET['live'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(rs_ls_live());
    exit;
}

// Wipe the numbers (before launch, after testing).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ls-reset') {
    rs_check_csrf();
    rs_ls_tables();
    foreach (['lstat_installs', 'lstat_days', 'lstat_sessions'] as $t) rs_db()->exec('TRUNCATE TABLE ' . $t);
    rs_log('launcher-stats', 'reset', '');
    rs_flash('Launcher stats cleared.');
    header('Location: launcher-stats.php'); exit;
}

$live = rs_ls_live();
$s = rs_ls_summary();
$peak = max(1, max($s['series']));
$ping = rs_abs('/api/launcher-ping.php');

rs_head('Launcher stats', true, ['assets/accounts.css']);
?>
<section class="head-row">
  <div>
    <h1>RecompLauncher stats</h1>
    <p class="muted">From the launcher's anonymous pings: one random ID per install, no names or emails. Players who turn off usage stats in the launcher aren't counted.</p>
  </div>
</section>

<section class="ls-cards">
  <div class="card"><span class="muted small"><i class="ls-dot"></i>Open right now</span><b data-ls="open"><?= number_format($live['open']) ?></b><span class="muted small">launchers in the last few minutes</span></div>
  <div class="card"><span class="muted small">Playing right now</span><b data-ls="playing"><?= number_format($live['playing']) ?></b><span class="muted small">in a game</span></div>
  <div class="card"><span class="muted small">Active today</span><b><?= number_format($s['today']) ?></b><span class="muted small"><?= number_format($s['new_today']) ?> new today</span></div>
  <div class="card"><span class="muted small">Active this week</span><b><?= number_format($s['week']) ?></b><span class="muted small"><?= number_format($s['month']) ?> in 30 days</span></div>
  <div class="card"><span class="muted small">Installs ever</span><b><?= number_format($s['installs']) ?></b><span class="muted small">launchers that have checked in</span></div>
  <div class="card"><span class="muted small">Played today</span><b><?= h(number_format((float)$s['hours_today'], 1)) ?> h</b><span class="muted small">across every game</span></div>
  <div class="card"><span class="muted small">Signed in</span><b><?= (int)$s['signed_pct'] ?>%</b><span class="muted small">of this week's players</span></div>
  <div class="card"><span class="muted small">TV mode</span><b><?= (int)$s['tv_pct'] ?>%</b><span class="muted small">of this week's players</span></div>
</section>

<?php $byOs = rs_ls_by_os(); $osNames = ['windows' => 'Windows', 'linux' => 'Linux', 'macos' => 'macOS', 'other' => 'Other']; ?>
<section class="card">
  <h2>By system</h2>
  <table class="ac-table"><thead><tr><th>System</th><th>Open now</th><th>Active today</th><th>Active this week</th><th>Installs ever</th></tr></thead><tbody>
  <?php foreach ($byOs as $k => $r): if (!in_array($k, ['windows', 'linux'], true) && !$r['ever']) continue; ?>
    <tr><td><?= h($osNames[$k]) ?></td><td><?= number_format($r['open']) ?></td><td><?= number_format($r['today']) ?></td><td><?= number_format($r['week']) ?></td><td><?= number_format($r['ever']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <p class="small muted">From what each launcher reports when it checks in.</p>
</section>

<section class="ls-two">
  <section class="card">
    <h2>Playing right now</h2>
    <div data-ls="games"><?php if ($live['games']): ?>
      <table class="ac-table"><thead><tr><th>Game</th><th>Players</th></tr></thead><tbody>
      <?php foreach ($live['games'] as $g): ?><tr><td><?= h((string)($g['name'] ?: $g['game_id'])) ?></td><td><?= number_format((int)$g['n']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
    <?php else: ?><p class="muted">Nobody's in a game right now.</p><?php endif; ?></div>
    <p class="small muted" data-ls="at">Updates on its own every 20 seconds.</p>
  </section>
  <section class="card">
    <h2>Versions</h2>
    <?php if ($s['versions']): $vt = max(1, array_sum(array_map(fn($v) => (int)$v['n'], $s['versions']))); ?>
      <table class="ac-table"><thead><tr><th>Version</th><th>Installs</th><th>Share</th></tr></thead><tbody>
      <?php foreach ($s['versions'] as $v): ?><tr><td><?= h((string)$v['version'] !== '' ? (string)$v['version'] : 'Unknown') ?></td><td><?= number_format((int)$v['n']) ?></td><td><?= (int)round(100 * (int)$v['n'] / $vt) ?>%</td></tr><?php endforeach; ?>
      </tbody></table>
      <p class="small muted">Installs seen in the last 7 days.</p>
    <?php else: ?><p class="muted">No launchers have checked in yet.</p><?php endif; ?>
  </section>
</section>

<section class="card">
  <h2>Active players, last 14 days</h2>
  <div class="ls-bars" role="img" aria-label="Active players per day">
    <?php foreach ($s['series'] as $d => $n): ?>
      <div class="ls-bar" title="<?= h(rs_date('D M j', strtotime($d . ' 12:00 UTC'))) ?>: <?= number_format($n) ?>"><span><?= $n ? number_format($n) : '' ?></span><i style="height:<?= max(2, (int)round(100 * $n / $peak)) ?>%"></i><small><?= h(gmdate('j', strtotime($d))) ?></small></div>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2>Most played this week</h2>
  <?php if ($s['top']): ?>
  <table class="ac-table"><thead><tr><th>Game</th><th>Players</th><th>Sessions</th><th>Hours</th><th>Hours per player</th></tr></thead><tbody>
  <?php foreach ($s['top'] as $g): ?>
    <tr><td><?= h((string)($g['name'] ?: $g['game_id'])) ?></td><td><?= number_format((int)$g['players']) ?></td><td><?= number_format((int)$g['sessions']) ?></td><td><?= h(number_format((float)$g['hours'], 1)) ?></td><td><?= h(number_format((float)$g['hours'] / max(1, (int)$g['players']), 1)) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p class="muted">No play sessions yet. They show up once the launcher starts sending them.</p><?php endif; ?>
  <p class="small muted">The launcher sends to <code><?= h($ping) ?></code></p>
  <form method="post" class="actions" onsubmit="return confirm('Clear every launcher stat and start from zero?')"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="ls-reset"><button class="danger">Start fresh</button><span class="small muted">Clears these numbers, for after testing.</span></form>
</section>

<style>
.ls-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.ls-cards .card{display:flex;flex-direction:column;gap:4px;margin:0}
.ls-cards b{font-size:26px;line-height:1.15}
.ls-dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--cyan);margin-right:6px;box-shadow:0 0 8px var(--cyan);animation:lsp 2s infinite}
@keyframes lsp{50%{opacity:.35}}
.ls-two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-bottom:18px}
.ls-two .card{margin:0}
.ls-bars{display:grid;grid-template-columns:repeat(14,minmax(0,1fr));gap:6px;align-items:end;height:180px;margin-top:12px}
.ls-bar{display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;gap:4px}
.ls-bar i{display:block;width:100%;border-radius:6px 6px 2px 2px;background:linear-gradient(180deg,var(--cyan),#377BB6)}
.ls-bar span{font-size:11px;color:var(--muted,var(--muted))}
.ls-bar small{font-size:11px;color:var(--muted,var(--muted))}
@media (max-width:900px){.ls-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.ls-two{grid-template-columns:1fr}}
@media (max-width:520px){.ls-bar span{display:none}}
</style>
<script>
(() => {
  const $ = k => document.querySelector('[data-ls="' + k + '"]');
  const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const tick = async () => {
    if (document.hidden) return;
    try {
      const r = await fetch('launcher-stats.php?live=1', {credentials: 'same-origin', cache: 'no-store'});
      if (!r.ok) return;
      const d = await r.json();
      $('open').textContent = d.open.toLocaleString();
      $('playing').textContent = d.playing.toLocaleString();
      $('games').innerHTML = d.games.length
        ? '<table class="ac-table"><thead><tr><th>Game</th><th>Players</th></tr></thead><tbody>' + d.games.map(g => '<tr><td>' + esc(g.name || g.game_id) + '</td><td>' + Number(g.n).toLocaleString() + '</td></tr>').join('') + '</tbody></table>'
        : '<p class="muted">Nobody\'s in a game right now.</p>';
      $('at').textContent = 'Updated ' + new Date().toLocaleTimeString([], {hour: 'numeric', minute: '2-digit', second: '2-digit'}) + '. Updates on its own every 20 seconds.';
    } catch (e) {}
  };
  setInterval(tick, 20000);
  document.addEventListener('visibilitychange', tick);
})();
</script>
<?php rs_foot();
