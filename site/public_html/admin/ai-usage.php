<?php
// RecompStore admin (4.10): Settings > AI usage. What each feature spends on Claude, which ones may use it and
// with which model, and the daily budget. Rules (inc/rules.php) do the work when a feature is off.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/aiuse.php';
rs_require_login();
rs_ai_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    if (!rs_is_owner()) { rs_flash('Only the owner changes these.', 'err'); header('Location: ai-usage.php'); exit; }
    $mode = [];
    foreach (RS_AI_FEATURES as $k => [, , $allowed]) { $v = (string)($_POST['mode'][$k] ?? ''); if (in_array($v, $allowed, true)) $mode[$k] = $v; }
    $price = [];
    foreach (array_keys(RS_AI_PRICES) as $m) foreach (['in', 'out', 'cr', 'cw'] as $f) $price[$m][$f] = max(0, (float)($_POST['price'][$m][$f] ?? 0));
    rs_ai_save_cfg(['mode' => $mode, 'cap' => max(0, (float)($_POST['cap'] ?? 3)), 'price' => $price]);
    rs_set_setting('sub_autostart', !empty($_POST['sub_autostart']) ? '1' : '0');
    rs_log('settings', 'ai', 'AI usage saved');
    rs_flash('Saved.');
    header('Location: ai-usage.php');
    exit;
}

$cfg = rs_ai_cfg();
$today = gmdate('Y-m-d');
$sp = ['Today' => rs_ai_spend($today, $today), 'Last 7 days' => rs_ai_spend(gmdate('Y-m-d', time() - 6 * 86400), $today), 'Last 30 days' => rs_ai_spend(gmdate('Y-m-d', time() - 29 * 86400), $today)];
$days = [];
$st = rs_db()->prepare('SELECT day, model, SUM(tin) i, SUM(tout) o, SUM(tcache_read) r, SUM(tcache_write) w FROM ai_usage WHERE day >= ? GROUP BY day, model ORDER BY day');
$st->execute([gmdate('Y-m-d', time() - 13 * 86400)]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $days[$r['day']] = ($days[$r['day']] ?? 0) + rs_ai_cost((string)$r['model'], (int)$r['i'], (int)$r['o'], (int)$r['r'], (int)$r['w']);
$maxDay = max(array_merge([0.01], array_values($days)));
$usd = fn(float $v): string => '$' . number_format($v, $v < 10 ? 2 : 0);
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$labels = ['off' => 'Off (rules only)', 'haiku' => 'Haiku (cheapest)', 'sonnet' => 'Sonnet (balanced)', 'opus' => 'Opus (strongest)'];

rs_head('AI usage', true, ['assets/ai-usage.css']);
?>
<div class="au-head">
  <h1>AI usage</h1>
  <p class="muted">What Claude costs, by feature. Costs are worked out from the tokens Anthropic sends back with every answer and the prices below, so they're a close estimate; your Anthropic console has the exact bill. When a feature is off, plain rules do the work (sorting, page text from the submission or README) and it costs nothing.</p>
</div>

<div class="stats-tiles au-tiles">
  <?php foreach ($sp as $label => $s): ?><div><strong><?= h($usd($s['total']['cost'])) ?></strong><span><?= h($label) ?> · <?= number_format($s['total']['calls']) ?> calls</span></div><?php endforeach; ?>
  <div><strong><?= h($usd($cfg['cap'])) ?></strong><span>daily budget · <?= $cfg['cap'] > 0 ? h($usd(max(0, $cfg['cap'] - $sp['Today']['total']['cost']))) . ' left today' : 'no limit' ?></span></div>
</div>

<section class="card au-days">
  <h2>Last 14 days</h2>
  <div class="au-bars">
    <?php for ($i = 13; $i >= 0; $i--): $d = gmdate('Y-m-d', time() - $i * 86400); $v = $days[$d] ?? 0; ?>
    <div class="au-bar" title="<?= h($d . ': ' . $usd($v)) ?>"><span style="height:<?= max(2, round($v / $maxDay * 100)) ?>%"></span><small><?= h(gmdate('j', strtotime($d))) ?></small></div>
    <?php endfor; ?>
  </div>
</section>

<section class="card">
  <h2>By feature</h2>
  <table class="au-table">
    <thead><tr><th>Feature</th><th>Uses</th><th>Today</th><th>7 days</th><th>30 days</th></tr></thead>
    <tbody>
    <?php foreach (RS_AI_FEATURES as $k => [$name]): ?>
      <tr><td><?= h($name) ?></td><td><?= h($labels[$cfg['mode'][$k]] ?? $cfg['mode'][$k]) ?></td>
        <?php foreach ($sp as $s): ?><td><?= h($usd((float)($s[$k]['cost'] ?? 0))) ?><small class="muted"> · <?= (int)($s[$k]['calls'] ?? 0) ?></small></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted small">Counting started with this page (Nov 14, 2026), so earlier spend isn't in here.</p>
</section>

<form method="post" class="card au-set">
  <?= $csrf ?>
  <h2>Which features use AI</h2>
  <div class="au-grid">
    <?php foreach (RS_AI_FEATURES as $k => [$name, , $allowed]): ?>
    <label><?= h($name) ?><select name="mode[<?= $k ?>]"<?= rs_is_owner() ? '' : ' disabled' ?>><?php foreach ($allowed as $o): ?><option value="<?= $o ?>"<?= $cfg['mode'][$k] === $o ? ' selected' : '' ?>><?= h($labels[$o]) ?></option><?php endforeach; ?></select></label>
    <?php endforeach; ?>
    <label class="au-cap">Daily budget (US$)<input type="number" name="cap" min="0" step="0.5" value="<?= h((string)$cfg['cap']) ?>"<?= rs_is_owner() ? '' : ' disabled' ?>><small class="muted">0 means no limit. Once it's used up, AI features stop until midnight UTC; rules keep working.</small></label>
    <label class="au-check"><input type="checkbox" name="sub_autostart" value="1"<?= rs_setting('sub_autostart', '1') === '1' ? ' checked' : '' ?><?= rs_is_owner() ? '' : ' disabled' ?>> Start the checks as soon as a PC submission arrives</label>
  </div>
  <h2>Prices <span class="muted small">US$ per million tokens, from Anthropic's pricing page</span></h2>
  <div class="au-prices">
    <?php foreach (RS_AI_PRICE_NAMES as $m => $mn): ?>
    <fieldset><legend><?= $mn ?></legend>
      <?php foreach (['in' => 'Input', 'out' => 'Output', 'cr' => 'Cache read', 'cw' => 'Cache write'] as $f => $fn): ?>
      <label><?= $fn ?><input type="number" step="0.01" min="0" name="price[<?= $m ?>][<?= $f ?>]" value="<?= h((string)$cfg['price'][$m][$f]) ?>"<?= rs_is_owner() ? '' : ' disabled' ?>></label>
      <?php endforeach; ?>
    </fieldset>
    <?php endforeach; ?>
  </div>
  <?php if (rs_is_owner()): ?><div class="au-foot"><button class="primary">Save</button></div><?php endif; ?>
</form>
<?php rs_foot(); ?>
