<?php
// Recomp Store admin: bug reports, recomp submissions and messages sent from the website, and
// logs players send from the store (1.1.0: a game's page, Send logs).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_inbox_table();

$show = in_array($_GET['show'] ?? '', ['bug', 'submit', 'message', 'logs', 'done'], true) ? $_GET['show'] : 'all';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $db = rs_db();
    if ($action === 'done') $db->prepare("UPDATE inbox SET status = 'done' WHERE id = ?")->execute([$id]);
    elseif ($action === 'new') $db->prepare("UPDATE inbox SET status = 'new' WHERE id = ?")->execute([$id]);
    elseif ($action === 'delete') {
        $db->prepare('DELETE FROM inbox_files WHERE inbox_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM inbox WHERE id = ?')->execute([$id]);
        rs_flash('Deleted.');
    }
    header('Location: inbox.php?show=' . urlencode($show));
    exit;
}

$where = match ($show) {
    'bug' => "WHERE kind = 'bug' AND status <> 'done'",
    'submit' => "WHERE kind = 'submit' AND status <> 'done'",
    'message' => "WHERE kind = 'message' AND status <> 'done'",
    'logs' => "WHERE kind = 'logs' AND status <> 'done'",
    'done' => "WHERE status = 'done'",
    default => "WHERE status <> 'done'",
};
$rows = rs_db()->query("SELECT * FROM inbox $where ORDER BY created DESC LIMIT 300")->fetchAll();
$counts = rs_db()->query("SELECT kind, COUNT(*) n FROM inbox WHERE status <> 'done' GROUP BY kind")->fetchAll(PDO::FETCH_KEY_PAIR);
$names = [];
foreach (rs_games() as $g) $names[$g['id']] = $g['name'];
$names['store'] = 'Recomp Store itself';
// Logs: each message's files (not their text, which opens on its own page).
$files = [];
$logIds = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['kind'] === 'logs'));
if ($logIds) {
    foreach (rs_db()->query('SELECT id, inbox_id, name, size FROM inbox_files WHERE inbox_id IN (' . implode(',', $logIds) . ') ORDER BY id') as $f) {
        $files[(int)$f['inbox_id']][] = $f;
    }
}
$kb = fn(int $b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB';

rs_head('Inbox');
?>
<section class="head-row">
  <div>
    <h1>Inbox</h1>
    <p class="muted">Bug reports, recomp submissions and messages from the website, and logs sent from the store. Only you see these.</p>
  </div>
</section>
<nav class="tabs">
  <a href="inbox.php" <?= $show === 'all' ? 'aria-current="page"' : '' ?>>Open</a>
  <a href="inbox.php?show=bug" <?= $show === 'bug' ? 'aria-current="page"' : '' ?>>Bug reports<?= !empty($counts['bug']) ? ' <span class="count">' . (int)$counts['bug'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=submit" <?= $show === 'submit' ? 'aria-current="page"' : '' ?>>Submissions<?= !empty($counts['submit']) ? ' <span class="count">' . (int)$counts['submit'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=message" <?= $show === 'message' ? 'aria-current="page"' : '' ?>>Messages<?= !empty($counts['message']) ? ' <span class="count">' . (int)$counts['message'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=logs" <?= $show === 'logs' ? 'aria-current="page"' : '' ?>>Logs<?= !empty($counts['logs']) ? ' <span class="count">' . (int)$counts['logs'] . '</span>' : '' ?></a>
  <a href="inbox.php?show=done" <?= $show === 'done' ? 'aria-current="page"' : '' ?>>Done</a>
</nav>

<section class="list">
<?php foreach ($rows as $r): $bug = $r['kind'] === 'bug'; $label = ['bug' => 'Bug', 'submit' => 'Submission', 'message' => 'Message', 'logs' => 'Logs'][$r['kind']] ?? $r['kind']; ?>
  <article class="msg<?= $r['status'] === 'new' ? ' unread' : '' ?>">
    <?php if ($r['kind'] === 'logs'): $fs = $files[(int)$r['id']] ?? []; ?>
    <header>
      <span class="tag cyan">Logs</span>
      <strong><?= h($names[$r['app_id']] ?? $r['app_id']) ?></strong>
      <span class="muted"><?= $r['version'] !== '' ? 'version ' . h($r['version']) : 'not installed' ?><?= $r['name'] !== '' ? ' · ' . h($r['name']) : '' ?></span>
      <?php if ($r['contact'] !== ''): ?><span class="tag beta" title="Console <?= h($r['contact']) ?>">Beta tester · <?= h(substr($r['contact'], 0, 6)) ?></span><?php endif; ?>
      <span class="muted when"><?= h(date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <?php if (trim($r['body']) !== ''): ?><p class="body"><?= nl2br(h($r['body'])) ?></p><?php else: ?><p class="body muted">No message, just the logs.</p><?php endif; ?>
    <ul class="log-files">
      <?php foreach ($fs as $f): ?>
        <li><a href="log-file.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener"><?= h($f['name']) ?></a> <span class="muted"><?= h($kb((int)$f['size'])) ?></span> · <a href="log-file.php?id=<?= (int)$f['id'] ?>&amp;dl=1">Download</a></li>
      <?php endforeach; ?>
      <?php if (!$fs): ?><li class="muted">No files came with it.</li><?php endif; ?>
    </ul>
    <?php else: ?>
    <header>
      <span class="tag<?= $bug ? '' : ' cyan' ?>"><?= h($label) ?></span>
      <?php if ($bug): ?>
        <strong><?= h($names[$r['app_id']] ?? $r['app_id']) ?></strong>
        <?php if ($r['version'] !== ''): ?><span class="muted">version <?= h($r['version']) ?></span><?php endif; ?>
      <?php else: ?>
        <strong><?= h($r['name']) ?></strong>
      <?php endif; ?>
      <span class="muted when"><?= h(date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <p class="body"><?= nl2br(h($r['body'])) ?></p>
    <p class="meta">
      <?php if ($r['contact'] !== ''): ?>
        <?= filter_var($r['contact'], FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . h($r['contact']) . '">' . h($r['contact']) . '</a>' : h($r['contact']) ?>
      <?php else: ?><span class="muted">No contact left</span><?php endif; ?>
      <?php if ($r['repo'] !== ''): $u = preg_match('#^https://#', $r['repo']) ? $r['repo'] : 'https://' . $r['repo']; ?>
        · <a href="<?= h($u) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_replace('#^https://(www\.)?#', '', $r['repo'])) ?></a>
      <?php endif; ?>
    </p>
    <?php endif; ?>
    <div class="row-actions">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <?php if ($r['status'] === 'done'): ?><button name="action" value="new">Reopen</button>
        <?php else: ?><button name="action" value="done" class="primary">Mark done</button><?php endif; ?>
      </form>
      <form method="post" onsubmit="return confirm('Delete this message for good?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="delete" class="danger">Delete</button></form>
    </div>
  </article>
<?php endforeach; ?>
<?php if (!$rows): ?><p class="muted">Nothing here.</p><?php endif; ?>
</section>
<?php
// Opening the inbox counts as reading what's shown.
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], array_filter($rows, fn($r) => $r['status'] === 'new'));
    if ($ids) rs_db()->exec('UPDATE inbox SET status = \'read\' WHERE id IN (' . implode(',', $ids) . ')');
}
rs_foot();
