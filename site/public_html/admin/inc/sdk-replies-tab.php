<?php
// (4.13) Launcher support > Replies: what developers answered on the pull requests and issues we sent,
// open and closed, read from GitHub every hour (rs_prq_reply_scan). Included by sdk-support.php
// ($csrf and $rpThreads set there).
declare(strict_types=1);

$rpAgo = function (int $t): string {
    if ($t <= 0) return '';
    $d = time() - $t;
    $s = $d < 3600 ? max(1, intdiv($d, 60)) . ' min ago' : ($d < 86400 ? intdiv($d, 3600) . ' h ago' : ($d < 30 * 86400 ? intdiv($d, 86400) . ' d ago' : gmdate('M j, Y', $t)));
    return '<time datetime="' . gmdate('c', $t) . '" title="' . gmdate('Y-m-d H:i', $t) . ' UTC">' . $s . '</time>';
};
$rpText = function (string $b, int $max = 1500): string {
    $b = trim((string)preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $b)));
    if (mb_strlen($b) > $max) $b = mb_substr($b, 0, $max) . '…';
    return nl2br(h($b), false);
};
$rpGroups = [
    'new' => ['New', 'Something from them since we last looked', fn($t) => $t['new']],
    'waiting' => ['Waiting on us', 'Open, and they spoke last', fn($t) => $t['waiting']],
    'open' => ['Open', 'Pull requests and issues still open', fn($t) => $t['state'] === 'open'],
    'closed' => ['Merged or closed', 'Done one way or the other', fn($t) => $t['state'] !== 'open'],
    'all' => ['All', 'Everything we sent', fn($t) => true],
];
$rpCount = [];
foreach ($rpGroups as $k => $g) $rpCount[$k] = count(array_filter($rpThreads, $g[2]));
$rpShow = isset($rpGroups[$_GET['show'] ?? '']) ? (string)$_GET['show'] : ($rpCount['new'] ? 'new' : ($rpCount['waiting'] ? 'waiting' : 'all'));
$rpList = array_filter($rpThreads, $rpGroups[$rpShow][2]);
uasort($rpList, fn($x, $y) => max((int)$y['news_at'], (int)$y['ours_at']) <=> max((int)$x['news_at'], (int)$x['ours_at']));
$rpScan = rs_kv_get('prq_reply_scan');
$rpState = ['open' => ['Open', 'blue'], 'merged' => ['Merged', 'ok'], 'closed' => ['Closed', 'bad']];
$rpKind = ['opened' => 'opened it', 'comment' => 'commented', 'review' => 'reviewed', 'code' => 'commented on the code', 'merged' => 'merged it', 'closed' => 'closed it'];
?>
<div class="rp-groups" role="group" aria-label="Show">
  <?php foreach ($rpGroups as $k => $g): ?>
  <a class="q-stage<?= $rpShow === $k ? ' on' : '' ?>" href="sdk-support.php?tab=replies&amp;show=<?= $k ?>"<?= $rpShow === $k ? ' aria-current="true"' : '' ?> style="--k:<?= $k === 'new' ? '#ff9f43' : ($k === 'waiting' ? 'var(--warn)' : ($k === 'closed' ? 'var(--ok)' : 'var(--blue)')) ?>"><strong><?= number_format($rpCount[$k]) ?></strong><span><?= h($g[0]) ?></span><small><?= h($g[1]) ?></small></a>
  <?php endforeach; ?>
</div>

<section class="card">
  <div class="rp-head">
    <div>
      <h2><?= h($rpGroups[$rpShow][0]) ?> <span class="muted small"><?= number_format(count($rpList)) ?></span></h2>
      <p class="muted small">Every pull request and issue we sent, with what the developer said, merged or closed. Open ones are read from GitHub every hour, closed ones for a while after. Something of theirs after our last comment shows as new until one of us comments on GitHub or presses <b>Got it</b>.<?= $rpScan ? ' Last read ' . $rpAgo((int)$rpScan['v']) . '.' : '' ?></p>
    </div>
    <form method="post"><?= $csrf ?><button class="btn" name="action" value="prq_reply_check">Check now</button></form>
  </div>
  <?php foreach ($rpList as $rid => $t): $qa = rs_pc_app((string)$rid); $m = $t['last']; $st = $rpState[$t['state']] ?? $rpState['open']; $msgs = rs_prq_thread_msgs((string)$rid); ?>
  <article class="rp-item<?= $t['new'] ? ' is-new' : '' ?>" id="rp-<?= h((string)$rid) ?>">
    <div class="rp-top">
      <div class="rp-name"><strong><?= h($qa['name'] ?? $rid) ?></strong>
        <span class="muted small"><?= h((string)$t['repo']) ?> · <?= $t['kind'] === 'pull' ? 'Pull request' : 'Issue' ?> #<?= (int)$t['num'] ?></span></div>
      <div class="rp-pills"><span class="pill <?= $st[1] ?>"><?= $st[0] ?></span><?php if ($t['new']): ?><span class="pill hot">New</span><?php endif; ?><?php if ($t['waiting']): ?><span class="pill warn">Waiting on us</span><?php endif; ?></div>
    </div>
    <?php if ($m): ?>
    <div class="rp-msg"><div class="rp-by"><b><?= h((string)$m['who']) ?></b> <?= h($rpKind[$m['kind']] ?? 'wrote') ?> · <?= $rpAgo((int)$m['at']) ?></div><div class="rp-body"><?= (string)$m['body'] !== '' ? $rpText((string)$m['body'], 700) : '<span class="muted">(no text)</span>' ?></div></div>
    <?php elseif ($t['state'] !== 'open' && (int)$t['news_at'] > 0): ?>
    <p class="rp-note"><?= $t['state'] === 'merged' ? 'Merged' : 'Closed' ?> by the developer <?= $rpAgo((int)$t['closed_at']) ?>, without a comment.</p>
    <?php else: ?>
    <p class="rp-note muted">No reply yet<?= (int)$t['ours_at'] ? '. Our last word ' . $rpAgo((int)$t['ours_at']) : '' ?>.</p>
    <?php endif; ?>
    <?php if (count($msgs) > 1): ?>
    <details class="rp-thread"><summary>Whole conversation (<?= count($msgs) ?>)</summary>
      <ol>
      <?php foreach ($msgs as $x): ?>
        <li class="<?= (int)$x['mine'] ? 'us' : 'them' ?>"><div class="rp-by"><b><?= h((string)$x['who']) ?></b><?= (int)$x['mine'] ? ' <span class="pill blue">us</span>' : '' ?> <?= h($rpKind[$x['kind']] ?? 'wrote') ?> · <?= $rpAgo((int)$x['at']) ?> <a href="<?= h((string)$x['url']) ?>" target="_blank" rel="noopener">↗</a></div>
          <?php if ((string)$x['body'] !== ''): ?><div class="rp-body"><?= $rpText((string)$x['body']) ?></div><?php endif; ?></li>
      <?php endforeach; ?>
      </ol>
    </details>
    <?php endif; ?>
    <form method="post" class="rp-btns"><?= $csrf ?><input type="hidden" name="app" value="<?= h((string)$rid) ?>"><input type="hidden" name="back" value="replies"><input type="hidden" name="show" value="<?= h($rpShow) ?>">
      <a class="btn small primary" href="<?= h($m ? (string)$m['url'] : (string)$t['url']) ?>" target="_blank" rel="noopener"><?= $t['state'] === 'open' ? 'Answer on GitHub ↗' : 'Open on GitHub ↗' ?></a>
      <?php if ($t['new']): ?><button class="btn small" name="action" value="prq_reply_ok">Got it</button><?php endif; ?>
      <?php if ($qa): ?><a class="btn small" href="sdk-support.php?tab=check&amp;app=<?= h(rawurlencode((string)$qa['id'])) ?>">Its page</a><?php endif; ?>
    </form>
  </article>
  <?php endforeach; if (!$rpList): ?>
  <p class="muted"><?= $rpThreads ? ($rpShow === 'new' ? 'Nothing new from developers.' : 'None here.') : 'Nothing read yet. Press Check now, or wait for the next hourly look.' ?></p>
  <?php endif; ?>
</section>
