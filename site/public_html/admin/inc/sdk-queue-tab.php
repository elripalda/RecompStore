<?php
// (4.11) Launcher support > PR queue, rebuilt so it's clear where each game is and nothing goes out by accident.
// Included by sdk-support.php ($csrf set there). Stages: waiting to be drafted (todo), drafting now, ready for you
// (draft), going out (outbox), sent, skipped. Each draft has a review (rs_prq_review): what it gives players, what's
// missing, and what stops it being sent; Send needs "I've looked it over" ticked, and the server checks again.
declare(strict_types=1);

rs_prq_tables();
$qNow = rs_prq_now_ready();
$qHasAi = rs_secret_get('prq_ai_key') !== '';
$qLogin = (string)rs_setting('prq_gh_login', '');
$qHasToken = rs_prq_token() !== '';
$qKeyOnce = rs_is_owner() ? rs_secret_get('prq_key_once') : '';
if ($qKeyOnce !== '') rs_secret_put('prq_key_once', '');
$qStuckAt = time() - RS_PRQ_STUCK;
$qRows = rs_db()->query("SELECT id, app_id, repo, status, error, pick, updated, UNIX_TIMESTAMP(updated) u FROM pc_prq WHERE status IN ('todo', 'drafting', 'outbox', 'skipped')")->fetchAll();
$qCount = ['ready' => (int)rs_db()->query("SELECT COUNT(*) FROM pc_prq WHERE status = 'draft'")->fetchColumn(), 'waiting' => 0, 'drafting' => 0, 'going' => 0,
    'sent' => (int)rs_db()->query("SELECT COUNT(*) FROM pc_prq WHERE status = 'sent'")->fetchColumn(), 'skipped' => 0];
foreach ($qRows as $r) {
    if ($r['status'] === 'drafting' && (int)$r['u'] >= $qStuckAt) $qCount['drafting']++;
    elseif ($r['status'] === 'todo' || $r['status'] === 'drafting') $qCount['waiting']++;
    elseif ($r['status'] === 'outbox') $qCount['going']++;
    else $qCount['skipped']++;
}
$qStages = [
    'waiting' => ['Waiting to be drafted', 'Picked, not written yet'],
    'drafting' => ['Drafting now', 'Being written (a few minutes)'],
    'ready' => ['Ready for you', 'Written: look over, then send'],
    'going' => ['Going out', 'You pressed Send: 5 go out an hour'],
    'sent' => ['Sent', 'Pull request open: up to the developer'],
    'skipped' => ['Skipped', 'No pull request for these'],
];
$qShow = isset($qStages[$_GET['show'] ?? '']) ? (string)$_GET['show'] : ($qCount['ready'] ? 'ready' : ($qCount['drafting'] ? 'drafting' : 'waiting'));
$qWhat = '';
$qDate = fn(string $t) => h(gmdate('M j, H:i', (int)strtotime($t . ' UTC'))) . ' UTC';
$qPage = fn(string $id) => 'sdk-support.php?tab=check&app=' . rawurlencode($id);
// What to do when a draft didn't work, from the error the drafter left.
$qHelp = function (string $err): string {
    if (preg_match('/hourly limit|rate limit/i', $err)) return 'GitHub\'s hourly limit was used up. Press Draft now again in an hour.';
    if (preg_match('/doesn\'t exist|private now|not found/i', $err)) return 'The project is gone or private. Remove it from the queue.';
    if (preg_match('/SDK guide|model (call|busy)|try the next run/i', $err)) return 'A hiccup on the way. Press Draft now again.';
    if (preg_match('/no recomplauncher\.json|no JSON/i', $err)) return 'The drafter couldn\'t write a file for it. Open its page and run a test draft with Opus, or mark it as one Xari does by hand.';
    return 'Press Draft now again. If it fails twice, open its page and run a test draft with another model to see what\'s wrong.';
};
?>
<section class="card q-card">
  <div class="q-rules">
    <strong>How it works:</strong> every morning at 7:23 the drafter writes pull requests for 8 games with no <code>recomplauncher.json</code> (ones you queued first), and you can draft any game straight away from its page or the Games tab.
    <b>Nothing goes to a developer until you press Send pull request.</b> <a href="sdk-support.php?tab=guide">Read the guide</a>
  </div>
  <nav class="q-stages" aria-label="Where each game is">
    <?php foreach ($qStages as $k => [$label, $desc]): ?>
    <a href="sdk-support.php?tab=queue&amp;show=<?= $k ?>" class="q-stage q-st-<?= $k ?><?= $qShow === $k ? ' on' : '' ?>"<?= $qShow === $k ? ' aria-current="true"' : '' ?>><strong><?= number_format($qCount[$k]) ?></strong><span><?= h($label) ?></span><small><?= h($desc) ?></small></a>
    <?php endforeach; ?>
  </nav>
  <?php if (!$qHasAi && rs_is_owner()): ?><p class="warn small">Add the Anthropic API key under Settings at the bottom, or drafts wait for the daily run.</p><?php endif; ?>
  <?php if (!$qHasToken && rs_is_owner()): ?><p class="warn small">Add the GitHub token under Settings at the bottom before sending: it's the account pull requests come from.</p><?php endif; ?>
</section>

<?php if ($qShow === 'ready'): $qDrafts = rs_prq_list(['draft'], 200); $qOld = 0; foreach ($qDrafts as $d) if (!rs_prq_review($d)['new']) $qOld++; ?>
<section class="card">
  <div class="q-head"><h2>Ready for you <span class="muted small"><?= count($qDrafts) ?> written, none sent</span></h2>
    <?php if ($qOld && $qNow): ?><form method="post"><?= $csrf ?><button class="btn small" name="action" value="prq_redo_old" onclick="return confirm('Write the <?= $qOld ?> drafts the old drafter made again with the new one? It takes a few minutes each, a few at a time, and costs about $<?= number_format($qOld * 0.34, 0) ?> in all. The current drafts are replaced.')">Make new drafts for the <?= $qOld ?> old ones</button></form><?php endif; ?></div>
  <p class="muted small">Open one to see what it gives players and what it changes. <b>Ready to send</b> means the file passes the launcher's rules and has Easy Setup; <b>Look before sending</b> means something is missing or worth a read; <b>Can't send yet</b> means the file has a problem to fix first.</p>
  <?php if (!$qDrafts): ?><p class="muted">Nothing waiting for you. New drafts show up here after the 7:23 run, or a few minutes after you press Draft.</p><?php endif; ?>
  <?php foreach ($qDrafts as $pq): $qa = rs_pc_app((string)$pq['app_id']); if (!$qa) continue; $rv = rs_prq_review($pq);
      $lv = ['ok' => ['Ready to send', 'ok'], 'warn' => ['Look before sending', 'warn'], 'bad' => ['Can\'t send yet', 'bad']][$rv['level']];
      $qCode = (string)($pq['code'] ?? '') !== '' ? json_decode((string)$pq['code'], true) : null; $isUpd = (string)$pq['pr_url'] !== '';
      $yes = array_map(fn($g) => $g[0], array_filter($rv['gives'], fn($g) => $g[1] === 'yes')); $no = array_map(fn($g) => $g[0], array_filter($rv['gives'], fn($g) => $g[1] === 'no'));
      $confirm = ($isUpd ? 'Update the pull request on ' : 'Send a pull request to ') . $pq['repo'] . "?\n\nIt gives players: " . ($yes ? implode(', ', $yes) : 'nothing beyond installs') . ($no ? "\nNot in it: " . implode(', ', $no) : '') . (!empty($qCode['edits']) ? "\nIt changes their code (" . count((array)$qCode['edits']) . ' edits).' : ''); ?>
  <details class="q-item q-lv-<?= $rv['level'] ?>" id="q-<?= (int)$pq['id'] ?>"<?= (string)$pq['error'] !== '' ? ' open' : '' ?>>
    <summary>
      <span class="q-name"><strong><?= h($qa['name']) ?></strong><span class="muted small"><?= h($pq['repo']) ?> · <?= number_format((int)$qa['downloads']) ?> downloads</span></span>
      <span class="q-pills"><span class="pill <?= $lv[1] ?>"><?= h($lv[0]) ?></span><?php if (!$rv['new']): ?><span class="pill">Old drafter</span><?php endif; ?><?php if ($isUpd): ?><span class="pill blue">Update to a sent PR</span><?php endif; ?><?php if ((string)$pq['error'] !== ''): ?><span class="pill bad">Didn't send</span><?php endif; ?></span>
      <span class="q-when muted small">drafted <?= $qDate((string)$pq['updated']) ?></span>
    </summary>
    <form method="post" class="q-form"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$pq['id'] ?>">
      <?php if ((string)$pq['error'] !== ''): ?><p class="q-box q-bad small"><b>Sending didn't work:</b> <?= h((string)$pq['error']) ?></p><?php endif; ?>
      <?php if ($isUpd): ?><p class="small">This redraft updates <a href="<?= h((string)$pq['pr_url']) ?>" target="_blank" rel="noopener">the pull request we already sent ↗</a> instead of opening a new one.</p><?php endif; ?>
      <?php if ($rv['blockers']): ?><div class="q-box q-bad small"><b>Can't be sent until this is fixed:</b><ul><?php foreach ($rv['blockers'] as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <h4 class="q-h4">What this pull request gives players</h4>
      <ul class="q-gives">
        <li class="q-g-yes"><span class="q-gi">✓</span><span><b>Installs and updates</b><small>from their GitHub releases (works already)</small></span></li>
        <?php foreach ($rv['gives'] as [$gn, $gs, $gh]): ?><li class="q-g-<?= $gs ?>"><span class="q-gi"><?= $gs === 'yes' ? '✓' : ($gs === 'no' ? '✕' : '–') ?></span><span><b><?= h($gn) ?></b><small><?= h($gs === 'na' ? $gh : ($gs === 'no' ? 'Not in it: ' . $gh : $gh)) ?></small></span></li><?php endforeach; ?>
      </ul>
      <?php if ($rv['warns']): ?><div class="q-box q-warn small"><b>Worth knowing before you send:</b><ul><?php foreach ($rv['warns'] as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <?php if (trim((string)$pq['note']) !== ''): ?><details class="q-more" open><summary>The drafter's notes</summary><p class="small"><?= nl2br(h((string)$pq['note'])) ?></p></details><?php endif; ?>
      <?php if (is_array($qCode) && !empty($qCode['edits'])): ?>
      <details class="q-more"><summary>The code changes <span class="muted small"><?= count((array)$qCode['edits']) ?> edit<?= count((array)$qCode['edits']) === 1 ? '' : 's' ?>, adds <?= h(implode(', ', array_map('strval', (array)($qCode['adds'] ?? [])))) ?></span></summary>
        <div class="prq-code"><input type="hidden" name="has_code" value="1">
          <?php foreach ((array)($qCode['explain'] ?? []) as $ex): ?><p class="small">• <?= h((string)$ex) ?></p><?php endforeach; ?>
          <?php foreach ((array)$qCode['edits'] as $ed): ?><div class="prq-diff"><div class="prq-diff-path mono small"><?= h((string)($ed['path'] ?? '')) ?><?= (string)($ed['why'] ?? '') !== '' ? ' · ' . h((string)$ed['why']) : '' ?></div><pre class="prq-del"><?= h((string)($ed['find'] ?? '')) ?></pre><pre class="prq-add"><?= h((string)($ed['replace'] ?? '')) ?></pre></div><?php endforeach; ?>
          <label class="prq-here small"><input type="checkbox" name="code_on" value="1" checked> Include the code changes (untick to send only the file and the badge)</label>
        </div>
      </details>
      <?php endif; ?>
      <?php $f = rs_prq_found_html((string)($pq['found'] ?? '') !== '' ? json_decode((string)$pq['found'], true) : null, (string)$pq['repo']); if ($f !== ''): ?><details class="q-more"><summary>What the drafter found in their project</summary><?= $f ?></details><?php endif; ?>
      <details class="q-more"><summary>Edit the text and the file <span class="muted small">title, description, recomplauncher.json</span></summary>
        <div class="prq-grid">
          <label>Pull request title<input name="title" value="<?= h((string)$pq['title']) ?>" maxlength="200"></label>
          <label class="prq-span">Pull request description<textarea name="body" rows="14"><?= h((string)$pq['body']) ?></textarea></label>
          <label class="prq-span">recomplauncher.json<textarea name="json" rows="14" class="mono" spellcheck="false"><?= h((string)$pq['json']) ?></textarea></label>
        </div>
        <p class="muted small">Press <b>Save my edits</b> to keep them. Send also saves them first.</p>
      </details>
      <div class="q-send">
        <label class="q-look"><input type="checkbox" name="looked" value="1" data-q-look<?= $rv['blockers'] ? ' disabled' : '' ?>> I've looked it over</label>
        <button class="btn primary" name="action" value="prq_send" data-q-send disabled data-q-confirm="<?= h($confirm) ?>" title="<?= $rv['blockers'] ? 'Fix the problem above first' : ($qHasToken ? 'Tick \'I\'ve looked it over\' first' : 'Add the GitHub token first') ?>"<?= $qHasToken && !$rv['blockers'] ? '' : ' data-q-never' ?>><?= $isUpd ? 'Update the pull request' : 'Send pull request' ?></button>
        <button class="btn" name="action" value="prq_save">Save my edits</button>
        <button class="btn" name="action" value="prq_redo" onclick="return confirm('Throw this draft away and write a new one from scratch with the current drafter? It takes a few minutes and costs about $0.34.')">Make a new draft</button>
        <button class="btn danger" name="action" value="prq_skip" onclick="return confirm('<?= $isUpd ? 'Leave the sent pull request as it is?' : 'No pull request for this game? It goes to Skipped and isn\'t drafted again.' ?>')"><?= $isUpd ? 'Keep the sent one as it is' : 'Don\'t send one for this game' ?></button>
        <a class="btn" href="<?= h($qPage((string)$qa['id'])) ?>">Its page</a>
      </div>
      <label class="q-here small"><input type="checkbox" name="here" value="1" checked> When it's sent, also use this file for <?= h($qa['name']) ?> on RecompLauncher right away (as our profile, without the code changes), so players get it before the developer merges. Our older profile stays if it has one.</label>
    </form>
  </details>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($qShow === 'waiting' || $qShow === 'drafting'): $qWork = rs_prq_list(['todo', 'drafting'], 200); ?>
<section class="card">
  <h2><?= $qShow === 'drafting' ? 'Drafting now' : 'Waiting to be drafted' ?></h2>
  <p class="muted small"><?= $qShow === 'drafting' ? 'These are being written right now, a few at a time. Each takes a few minutes and moves to Ready for you when it\'s done; the page refreshes by itself.' : 'Games picked for a pull request that haven\'t been written yet. They\'re drafted at the next 7:23 run, or press Draft now. One that didn\'t work says why and what to do.' ?></p>
  <?php $shown = 0; foreach ($qWork as $w): $wa = rs_pc_app((string)$w['app_id']); $stuck = $w['status'] === 'drafting' && strtotime((string)$w['updated'] . ' UTC') < $qStuckAt; $drafting = $w['status'] === 'drafting' && !$stuck;
      if (($qShow === 'drafting') !== $drafting) continue; $shown++; $err = (string)$w['error']; ?>
  <div class="q-row"<?= $drafting ? ' data-drafting' : '' ?>>
    <span class="q-name"><strong><?= h($wa['name'] ?? $w['app_id']) ?></strong><span class="muted small"><?= h($w['repo']) ?></span></span>
    <span class="q-row-st small"><?php if ($drafting): ?><span class="sdk-spin" aria-hidden="true"></span> Being written…<?php elseif ($stuck): ?><span class="bad">Didn't finish.</span> Press Draft now again.<?php elseif ($err !== ''): ?><span class="bad">Didn't draft: <?= h($err) ?></span><br><span class="muted">What you can do: <?= h($qHelp($err)) ?></span><?php else: ?>Waits for the 7:23 run<?= $qNow ? ', or press Draft now' : '' ?><?php endif; ?></span>
    <?php if (!$drafting): ?><form method="post" class="q-row-btns"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$w['id'] ?>"><?php if ($qNow): ?><button class="btn primary small" name="action" value="prq_now">Draft now</button><?php endif; ?><?php if ($wa): ?><a class="btn small" href="<?= h($qPage((string)$wa['id'])) ?>">Its page</a><?php endif; ?><?php if ($w['status'] === 'todo'): ?><button class="btn small" name="action" value="prq_forget">Remove</button><?php endif; ?></form><?php endif; ?>
  </div>
  <?php endforeach; if (!$shown): ?><p class="muted"><?= $qShow === 'drafting' ? 'Nothing being written right now.' : 'Nothing waiting. Add games from the Games tab (tick them, then Draft PRs now) or from a game\'s page.' ?></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($qShow === 'going'): $qOut = rs_prq_list(['outbox'], 100); ?>
<section class="card">
  <h2>Going out</h2>
  <p class="muted small">You pressed Send on these. At most 5 pull requests go out an hour, so GitHub doesn't treat us as spam; the half-hourly job sends the next ones in this order. Take one back to stop it.</p>
  <?php foreach ($qOut as $pq): $qa = rs_pc_app((string)$pq['app_id']); ?>
  <div class="q-row"><span class="q-name"><strong><?= h($qa['name'] ?? $pq['app_id']) ?></strong><span class="muted small"><?= h((string)$pq['repo']) ?></span></span>
    <span class="q-row-st small">Sent to the line <?= $qDate((string)$pq['updated']) ?><?= (string)$pq['who'] !== '' ? ' by ' . h((string)$pq['who']) : '' ?><?= (string)$pq['error'] !== '' ? '<br><span class="bad">' . h((string)$pq['error']) . '</span>' : '' ?></span>
    <form method="post" class="q-row-btns"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$pq['id'] ?>"><button class="btn small" name="action" value="prq_unqueue">Take it back</button></form></div>
  <?php endforeach; if (!$qOut): ?><p class="muted">Nothing in line.</p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($qShow === 'sent'): $qSent = rs_prq_list(['sent'], 100); ?>
<section class="card">
  <h2>Sent <span class="muted small"><?= number_format($qCount['sent']) ?> pull requests</span></h2>
  <p class="muted small">Open on the developer's repo: they merge it, change it, or close it. If they edit their README later and it conflicts, it's rebuilt on their newest code by itself. <b>Redraft and update</b> writes it again with the current drafter and lets you push that to the same pull request.</p>
  <?php foreach ($qSent as $pq): $qa = rs_pc_app((string)$pq['app_id']); $fx = rs_prq_fix_state((int)$pq['id']); ?>
  <div class="q-row"><span class="q-name"><strong><a href="<?= h((string)$pq['pr_url']) ?>" target="_blank" rel="noopener"><?= h($qa['name'] ?? $pq['repo']) ?> ↗</a></strong><span class="muted small"><?= h((string)$pq['repo']) ?></span></span>
    <span class="q-row-st small">Sent <?= $qDate((string)($pq['sent_at'] ?: $pq['updated'])) ?><?= (string)$pq['who'] !== '' ? ' by ' . h((string)$pq['who']) : '' ?><?php if ($fx && ($fx['why'] ?? '') !== ''): ?><br><span class="warn">Conflict, needs a look: <?= h((string)$fx['why']) ?></span><?php elseif ($fx): ?><br><span class="ok">Rebuilt after a conflict</span><?php endif; ?></span>
    <form method="post" class="q-row-btns"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$pq['id'] ?>"><button class="btn small" name="action" value="prq_reopen">Redraft and update</button><?php if ($qa): ?><a class="btn small" href="<?= h($qPage((string)$qa['id'])) ?>">Its page</a><?php endif; ?></form></div>
  <?php endforeach; if (!$qSent): ?><p class="muted">None sent yet.</p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($qShow === 'skipped'): $qSkip = rs_prq_list(['skipped'], 200); ?>
<section class="card">
  <h2>Skipped</h2>
  <p class="muted small">No pull request for these: you skipped them, or the drafter did (it already has one, it's archived, or it isn't a game it can draft). <b>Put it back</b> lets it be picked and drafted again.</p>
  <?php foreach ($qSkip as $pq): $qa = rs_pc_app((string)$pq['app_id']); ?>
  <div class="q-row"><span class="q-name"><strong><?= h($qa['name'] ?? $pq['app_id']) ?></strong><span class="muted small"><?= h((string)$pq['repo']) ?></span></span>
    <span class="q-row-st small muted"><?= trim((string)$pq['note']) !== '' ? h(mb_substr((string)$pq['note'], 0, 220)) : 'Skipped by hand' ?></span>
    <form method="post" class="q-row-btns"><?= $csrf ?><input type="hidden" name="qid" value="<?= (int)$pq['id'] ?>"><button class="btn small" name="action" value="prq_unskip">Put it back</button></form></div>
  <?php endforeach; if (!$qSkip): ?><p class="muted">None skipped.</p><?php endif; ?>
</section>
<?php endif; ?>

<?php if (rs_is_owner()): ?>
<details class="card prq-setup"<?= !$qHasToken || $qKeyOnce !== '' ? ' open' : '' ?>><summary><b>Settings</b> <span class="muted small">GitHub token, Anthropic key, the daily run's key<?= $qLogin !== '' ? ' · pull requests come from @' . h($qLogin) : '' ?></span></summary>
  <div class="prq-setup-grid">
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="prq_token">
      <label>GitHub token for pull requests <span class="muted small"><?= $qHasToken ? 'saved, for @' . h($qLogin) : 'not set' ?></span>
        <input type="password" name="token" autocomplete="off" placeholder="<?= $qHasToken ? 'Paste a new one to replace it, or leave empty and save to remove it' : 'A fine-grained or classic token with public_repo' ?>"></label>
      <small class="muted">Make it on github.com → Settings → Developer settings → Personal access tokens, signed in as the account the pull requests should come from. A classic token needs the <code>public_repo</code> scope. It's kept encrypted on the server.</small>
      <button class="btn">Save token</button></form>
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="prq_ai">
      <label>Anthropic API key for drafting <span class="muted small"><?= $qHasAi ? 'saved' : 'not set' ?></span>
        <input type="password" name="ai_key" autocomplete="off" placeholder="<?= $qHasAi ? 'Paste a new one to replace it, or leave empty and save to remove it' : 'sk-ant-…' ?>"></label>
      <small class="muted">Make one on console.anthropic.com → API Keys. Draft now and test drafts run on this server with it. It's kept encrypted on the server. Which models it uses is on <a href="ai-usage.php">AI usage</a>.</small>
      <button class="btn">Save key</button></form>
    <form method="post"><?= $csrf ?><input type="hidden" name="action" value="prq_key">
      <label>The daily run's key <span class="muted small"><?= (string)rs_setting('prq_key_hash', '') !== '' ? 'set' : 'not set' ?></span></label>
      <?php if ($qKeyOnce !== ''): ?><input readonly class="mono" value="<?= h($qKeyOnce) ?>" onclick="this.select()"><small class="warn">Copy it now into the RecompStore repo on GitHub as the secret <code>RS_PRQ_KEY</code> (Settings → Secrets and variables → Actions). It isn't shown again.</small>
      <?php else: ?><small class="muted">The 7:23 GitHub Action sends it with every call. Making a new one stops the old one working.</small><?php endif; ?>
      <button class="btn">Make a new key</button></form>
  </div>
</details>
<?php endif; ?>
<script>
// Send stays off until "I've looked it over" is ticked; then it asks once more, listing what's in it.
document.querySelectorAll('.q-form').forEach(function (f) {
  var look = f.querySelector('[data-q-look]'), send = f.querySelector('[data-q-send]');
  if (!look || !send) return;
  look.addEventListener('change', function () { if (!send.hasAttribute('data-q-never')) send.disabled = !look.checked; });
  send.addEventListener('click', function (e) { if (!confirm(send.getAttribute('data-q-confirm'))) e.preventDefault(); });
});
</script>
