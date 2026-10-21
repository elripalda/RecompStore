<?php
// Recomp Store admin (2.3.0): what testers say about each beta, build by build. Testers send it from
// the beta's page in the store (Feedback: Works, Crashes, Slow or Other, a note and their logs); each
// one is also in the Inbox under Logs, with its files.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

rs_inbox_table();
rs_inbox_feedback_columns();
$db = rs_db();
$names = [];
foreach (rs_games() as $g) $names[$g['id']] = $g['name'];
$app = (string)($_GET['app'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $app)) $app = '';

// Each app and build: how many said what.
$sum = $db->query("SELECT app_id, build, feedback, COUNT(*) n, MAX(created) last, MAX(id) lastid FROM inbox
                   WHERE kind = 'logs' AND feedback <> '' GROUP BY app_id, build, feedback")->fetchAll();
$byApp = [];
foreach ($sum as $r) {
    $a = (string)$r['app_id'];
    $b = (string)$r['build'] !== '' ? (string)$r['build'] : '(not given)';
    $byApp[$a] ??= ['builds' => [], 'total' => 0, 'last' => '', 'lastid' => 0];
    $byApp[$a]['builds'][$b] ??= ['works' => 0, 'crashes' => 0, 'slow' => 0, 'other' => 0, 'last' => '', 'lastid' => 0];
    $byApp[$a]['builds'][$b][$r['feedback']] = (int)$r['n'];
    $byApp[$a]['builds'][$b]['last'] = max($byApp[$a]['builds'][$b]['last'], (string)$r['last']);
    $byApp[$a]['builds'][$b]['lastid'] = max($byApp[$a]['builds'][$b]['lastid'], (int)$r['lastid']);
    $byApp[$a]['total'] += (int)$r['n'];
    $byApp[$a]['last'] = max($byApp[$a]['last'], (string)$r['last']);
    $byApp[$a]['lastid'] = max($byApp[$a]['lastid'], (int)$r['lastid']);
}
// Newest first: the app, and its build, that heard from a tester last.
uasort($byApp, fn($x, $y) => $y['lastid'] <=> $x['lastid']);
foreach ($byApp as &$x) uasort($x['builds'], fn($p, $q) => $q['lastid'] <=> $p['lastid']);
unset($x);

$notes = [];
if ($app !== '') {
    $st = $db->prepare("SELECT id, build, feedback, body, contact, created FROM inbox WHERE kind = 'logs' AND feedback <> '' AND app_id = ? ORDER BY created DESC LIMIT 200");
    $st->execute([$app]);
    $notes = $st->fetchAll();
}

rs_head('Feedback');
?>
<section class="head-row">
  <div>
    <h1>Feedback<?= $app !== '' ? ': ' . h($names[$app] ?? $app) : '' ?></h1>
    <p class="muted">What testers say about each beta, from the Feedback button on its page in the store (Recomp Store 2.3.0). Each one is in the Inbox too, under Logs, with the files it sent.</p>
  </div>
  <?php if ($app !== ''): ?><div class="actions"><a class="btn" href="feedback.php">Every beta</a></div><?php endif; ?>
</section>

<?php if (!$byApp): ?>
<section class="card"><p class="muted">No feedback yet. Testers see a Feedback button on a beta's page in the store; what they send shows up here.</p></section>
<?php endif; ?>

<?php foreach ($byApp as $id => $x): if ($app !== '' && $id !== $app) continue; ?>
<section class="card fb-app">
  <div class="fb-head">
    <h2><?= h($names[$id] ?? $id) ?> <span class="muted"><?= h($id) ?></span></h2>
    <span class="muted"><?= $x['total'] ?> from testers · last <?= h(date('M j, g:i a', strtotime($x['last']))) ?></span>
    <?php if ($app === ''): ?><a class="btn" href="feedback.php?app=<?= h(rawurlencode($id)) ?>">Read the notes</a><?php endif; ?>
  </div>
  <table class="fb-table">
    <thead><tr><th>Build</th><?php foreach (RS_VERDICTS as $vl): ?><th><?= h($vl) ?></th><?php endforeach; ?><th>Last</th></tr></thead>
    <tbody>
    <?php foreach ($x['builds'] as $b => $c): $n = max(1, $c['works'] + $c['crashes'] + $c['slow'] + $c['other']); ?>
      <tr>
        <td class="mono"><?= h($b) ?></td>
        <?php foreach (array_keys(RS_VERDICTS) as $vk): ?>
        <td><span class="fb-n fb-<?= h($vk) ?><?= $c[$vk] ? '' : ' zero' ?>"><?= (int)$c[$vk] ?></span><?php if ($c[$vk]): ?> <span class="muted"><?= round(100 * $c[$vk] / $n) ?>%</span><?php endif; ?></td>
        <?php endforeach; ?>
        <td class="muted"><?= h(date('M j', strtotime($c['last']))) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endforeach; ?>

<?php if ($app !== ''): ?>
<section class="list">
  <?php foreach ($notes as $r): ?>
  <article class="msg">
    <header>
      <span class="tag fb-<?= h($r['feedback']) ?>"><?= h(RS_VERDICTS[$r['feedback']] ?? 'Other') ?></span>
      <span class="mono"><?= h($r['build'] !== '' ? $r['build'] : '(build not given)') ?></span>
      <?php if ($r['contact'] !== ''): ?><span class="tag beta" title="Console <?= h($r['contact']) ?>">Tester · <?= h(substr($r['contact'], 0, 6)) ?></span><?php endif; ?>
      <span class="muted when"><?= h(date('M j, Y g:i a', strtotime($r['created']))) ?></span>
    </header>
    <?php if (trim((string)$r['body']) !== ''): ?><p class="body"><?= nl2br(h($r['body'])) ?></p><?php else: ?><p class="body muted">No note.</p><?php endif; ?>
    <p class="small-note"><a href="inbox.php?show=logs#m<?= (int)$r['id'] ?>">Its logs in the Inbox</a></p>
  </article>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php rs_foot();
