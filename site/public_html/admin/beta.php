<?php
// Recomp Store admin: beta codes. Make codes for a beta game, see who redeemed them, cancel them.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_beta_tables();
$db = rs_db();

$games = rs_games();
$names = array_column($games, 'name', 'id');
$betaGames = array_values(array_filter($games, fn($g) => $g['beta']));
$filter = isset($_GET['game']) && isset($names[$_GET['game']]) ? (string)$_GET['game'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = $_POST['action'] ?? '';
    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    $back = 'beta.php' . ($filter !== '' ? '?game=' . urlencode($filter) : '');
    try {
        if ($action === 'make') {
            $gid = (string)($_POST['game'] ?? '');
            $n = max(1, min(200, (int)($_POST['count'] ?? 1)));
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 120);
            $g = rs_game($gid);
            if (!$g || !$g['beta']) throw new RuntimeException('Pick a game that has Beta ticked.');
            $made = rs_beta_make_codes($gid, $n, $note);
            $_SESSION['new_codes'] = ['game' => $g['name'], 'codes' => $made];
            $back = 'beta.php?game=' . urlencode($gid);
        } elseif ($action === 'revoke' && strlen($code) === 8) {
            $db->prepare('UPDATE beta_codes SET revoked = 1 WHERE code = ?')->execute([$code]);
            rs_flash('Cancelled ' . rs_beta_format($code) . '. That console loses the game the next time its store checks in.');
        } elseif ($action === 'restore' && strlen($code) === 8) {
            $db->prepare('UPDATE beta_codes SET revoked = 0 WHERE code = ?')->execute([$code]);
            rs_flash('Turned ' . rs_beta_format($code) . ' back on.');
        } elseif ($action === 'delete' && strlen($code) === 8) {
            $db->prepare('DELETE FROM beta_codes WHERE code = ? AND install_id IS NULL')->execute([$code]);
            rs_flash('Deleted ' . rs_beta_format($code) . '.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$newCodes = $_SESSION['new_codes'] ?? null;
unset($_SESSION['new_codes']);

$sql = 'SELECT c.*, i.last_seen, i.store_version FROM beta_codes c LEFT JOIN beta_installs i ON i.install_id = c.install_id';
$args = [];
if ($filter !== '') { $sql .= ' WHERE c.game_id = ?'; $args[] = $filter; }
$sql .= ' ORDER BY c.created DESC, c.code ASC LIMIT 1000';
$st = $db->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
$when = fn(?string $d) => $d ? date('M j, g:i a', strtotime($d)) : '';

rs_head('Beta codes');
?>
<section class="head-row">
  <div>
    <h1>Beta codes</h1>
    <p class="muted">Each code unlocks one beta game on one console, in Recomp Store 1.1.0 or newer: Settings, Redeem a code. Codes look like 12-345-678.</p>
  </div>
</section>

<?php if ($newCodes): ?>
<section class="card">
  <h2><?= count($newCodes['codes']) ?> new <?= count($newCodes['codes']) === 1 ? 'code' : 'codes' ?> for <?= h($newCodes['game']) ?></h2>
  <p class="muted">Copy them now and send one to each tester. They stay listed below.</p>
  <div class="codes-box" data-copy-box><?= h(implode("\n", array_map('rs_beta_format', $newCodes['codes']))) ?></div>
  <p><button type="button" data-copy-codes>Copy all</button></p>
</section>
<?php endif; ?>

<section class="card">
  <h2>Make codes</h2>
  <?php if (!$betaGames): ?>
    <p class="muted">No game is a beta yet. Open a game, tick Beta and give it a beta zip, then come back here.</p>
  <?php else: ?>
  <form method="post" class="filters">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="make">
    <label>Game<select name="game"><?php foreach ($betaGames as $g): ?><option value="<?= h($g['id']) ?>" <?= $filter === $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?></select></label>
    <label style="min-width:120px">How many<input type="number" name="count" min="1" max="200" value="1"></label>
    <label class="grow" style="flex:1">Note <span class="muted">(optional)</span><input name="note" maxlength="120" placeholder="Who it's for, like Discord testers"></label>
    <button class="primary">Make codes</button>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <form method="get" class="filters">
    <label>Show<select name="game" onchange="this.form.submit()">
      <option value="">Every game</option>
      <?php foreach ($games as $g): ?><option value="<?= h($g['id']) ?>" <?= $filter === $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?><?= $g['beta'] ? ' (beta)' : '' ?></option><?php endforeach; ?>
    </select></label>
    <span class="muted"><?= count($rows) ?> <?= count($rows) === 1 ? 'code' : 'codes' ?>, <?= count(array_filter($rows, fn($r) => $r['install_id'] !== null && !(int)$r['revoked'])) ?> in use</span>
  </form>
  <?php if (!$rows): ?><p class="muted">No codes yet.</p><?php else: ?>
  <div class="table-wrap"><table class="codes"><!-- (2.3) scrolls inside its card on a phone -->
    <thead><tr><th>Code</th><th>Game</th><th>Note</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $used = $r['install_id'] !== null; $off = (int)$r['revoked'] === 1; ?>
      <tr>
        <td class="code"><?= h(rs_beta_format($r['code'])) ?></td>
        <td><?= h($names[$r['game_id']] ?? $r['game_id']) ?></td>
        <td class="muted"><?= h($r['note']) ?></td>
        <td>
          <?php if ($off): ?><span class="status-pill off">Cancelled</span>
          <?php elseif ($used): ?><span class="status-pill used">Redeemed</span>
          <?php else: ?><span class="status-pill">Not used yet</span><?php endif; ?>
          <?php if ($used): ?><br><span class="muted" title="Console <?= h($r['install_id']) ?>">Console <?= h(substr($r['install_id'], 0, 6)) ?> · <?= h($when($r['redeemed'])) ?><?= $r['last_seen'] ? ' · last seen ' . h($when($r['last_seen'])) : '' ?><?= $r['store_version'] ? ' · store ' . h($r['store_version']) : '' ?></span><?php endif; ?>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="code" value="<?= h($r['code']) ?>">
            <?php if ($off): ?><button name="action" value="restore">Turn back on</button>
            <?php else: ?><button name="action" value="revoke" onclick="return confirm('Cancel <?= h(rs_beta_format($r['code'])) ?>? <?= $used ? 'That console loses the game.' : 'It can\\\'t be redeemed anymore.' ?>')">Cancel</button><?php endif; ?>
            <?php if (!$used): ?><button name="action" value="delete" class="danger" onclick="return confirm('Delete <?= h(rs_beta_format($r['code'])) ?>?')">Delete</button><?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<script>
document.querySelector('[data-copy-codes]')?.addEventListener('click', (e) => {
  const t = document.querySelector('[data-copy-box]').textContent.trim();
  navigator.clipboard && navigator.clipboard.writeText(t).then(() => { e.target.textContent = 'Copied'; setTimeout(() => (e.target.textContent = 'Copy all'), 1500); });
});
</script>
<?php rs_foot();
