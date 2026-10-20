<?php
// Recomp Store admin (2.1): the change log. Every change made in the admin, every login and
// failed login, and what the signer signed, newest first.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_log_table();

$only = (string)($_GET['only'] ?? '');
$kinds = ['' => 'Everything', 'login' => 'Logins', 'login-failed' => 'Failed logins', 'publish' => 'Publish', 'signed' => 'Signed', 'change' => 'Changes', 'settings' => 'Settings', 'signer' => 'Signer', 'two-step' => 'Two-step login'];
if (!isset($kinds[$only])) $only = '';
$st = $only === '' ? rs_db()->query('SELECT * FROM admin_log ORDER BY id DESC LIMIT 500')
                   : rs_db()->prepare('SELECT * FROM admin_log WHERE action = ? ORDER BY id DESC LIMIT 500');
if ($only !== '') $st->execute([$only]);
$rows = $st->fetchAll();
$fails = (int)rs_db()->query("SELECT COUNT(*) FROM admin_log WHERE action = 'login-failed' AND at > (NOW() - INTERVAL 1 DAY)")->fetchColumn();

rs_head('Change log');
?>
<section class="head-row"><div><h1>Change log</h1><p class="muted">The last 500 entries. Addresses are kept only as a code, so the same code means the same address.</p></div><a class="btn" href="settings.php">Settings</a></section>
<?php if ($fails >= 5): ?><div class="flash err"><?= $fails ?> failed logins in the last day. If that wasn't you, turn on two-step login in Settings.</div><?php endif; ?>
<section class="card">
  <div class="chips" role="tablist" aria-label="Show">
    <?php foreach ($kinds as $k => $label): ?><a class="chip<?= $only === $k ? ' on' : '' ?>" href="log.php<?= $k !== '' ? '?only=' . urlencode($k) : '' ?>"><?= h($label) ?></a> <?php endforeach; ?>
  </div>
  <?php if (!$rows): ?><p class="muted">Nothing yet.</p><?php else: ?>
  <div class="table-wrap"><table class="stats log">
    <thead><tr><th>When</th><th>Who</th><th>What</th><th>On</th><th>Detail</th><th>From</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr<?= $r['action'] === 'login-failed' ? ' class="warn"' : '' ?>>
        <td><?= h(date('M j, g:i a', strtotime((string)$r['at']))) ?></td>
        <td><?= h((string)$r['who']) ?></td>
        <td><?= h((string)$r['action']) ?></td>
        <td><?= h((string)$r['target']) ?></td>
        <td class="detail"><?= h((string)$r['detail']) ?></td>
        <td><code><?= h(substr((string)$r['ip_hash'], 0, 6)) ?></code></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<?php rs_foot();
