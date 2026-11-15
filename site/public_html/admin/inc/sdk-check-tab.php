<?php
// (4.11) Launcher support > Check a game. Included by sdk-support.php ($csrf, $tab set there).
// One recomp: what players get in RecompLauncher feature by feature (inc/sdkcheck.php), test drafts with
// the models side by side (inc/prq.php rs_prq_dry_*), and what to do with a draft: the PR queue, our own
// profile, or the developer directly.
declare(strict_types=1);

$chkId = trim((string)($_GET['app'] ?? ''));
$chkA = $chkId !== '' ? rs_pc_app($chkId) : null;
$chkApps = array_values(array_filter(rs_pc_apps(true), fn($x) => (string)$x['repo'] !== ''));
usort($chkApps, fn($x, $y) => strcasecmp((string)$x['name'], (string)$y['name']));
$chkHealth = $chkA ? rs_sdk_health($chkA) : null;
$chkTests = rs_prq_dry_list($chkA ? (string)$chkA['id'] : '', $chkA ? 30 : 20);
$chkRunning = count(array_filter($chkTests, fn($r) => ($r['status'] ?? '') === 'running'));
$chkModelName = function (string $m): string {
    $n = fn(string $x) => str_contains($x, 'opus') ? 'Opus' : (str_contains($x, 'haiku') ? 'Haiku' : (str_contains($x, 'sonnet') ? 'Sonnet' : $x));
    return $n($m);
};
$chkUsd = fn(float $v): string => '$' . number_format($v, $v < 1 ? 3 : 2);
$chkStateIcon = ['ok' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    'bad' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round"><path d="M7 7l10 10M17 7 7 17"/></svg>', 'warn' => '!', 'off' => '–'];
$chkSource = ['release' => ['The developer\'s own file, from their release', 'ok'], 'ours' => ['Our profile (until the developer ships their own)', 'blue'],
    'ours_old' => ['Our older profile', 'blue'], 'none' => ['Nothing yet: the launcher guesses', 'bad']];
?>
<section class="card chk-pick">
  <form method="get" class="chk-pick-f">
    <input type="hidden" name="tab" value="check">
    <label>Pick a recomp<input name="app" list="chk-apps" value="<?= h($chkA ? (string)$chkA['id'] : '') ?>" placeholder="Start typing a name" autocomplete="off" required></label>
    <datalist id="chk-apps"><?php foreach ($chkApps as $x): ?><option value="<?= h((string)$x['id']) ?>"><?= h((string)$x['name']) ?></option><?php endforeach; ?></datalist>
    <button class="btn primary">Check it</button>
  </form>
  <p class="muted small">What the launcher really uses for the game, checked against the launcher's own rules, its download and what players report. Then draft its <code>recomplauncher.json</code> with the model you pick: a test draft is only shown here, never saved to the queue or sent.</p>
</section>

<?php if ($chkA && $chkHealth): $src = $chkSource[$chkHealth['source']] ?? $chkSource['none']; $rel = $chkHealth['release']; ?>
<section class="card chk-head">
  <div class="chk-title">
    <h2><?= h((string)$chkA['name']) ?></h2>
    <p class="small"><a href="<?= h(rs_host_web(rs_pc_host($chkA), (string)$chkA['repo'])) ?>" target="_blank" rel="noopener"><?= h((string)$chkA['repo']) ?> ↗</a>
      <?php if (!empty($rel['tag'])): ?> · release <a href="<?= h((string)($rel['url'] ?? '')) ?>" target="_blank" rel="noopener"><?= h((string)$rel['tag']) ?> ↗</a><?php endif; ?>
      · <a href="pc.php?edit=<?= h(rawurlencode((string)$chkA['id'])) ?>">Edit listing</a> · <a href="https://recomplauncher.com/game/<?= h((string)$chkA['id']) ?>" target="_blank" rel="noopener">Its page ↗</a></p>
    <p class="chk-src">Players get: <span class="pill <?= $src[1] ?>"><?= h($src[0]) ?></span></p>
  </div>
  <form method="post" class="chk-head-act"><?= $csrf ?><input type="hidden" name="id" value="<?= h((string)$chkA['id']) ?>">
    <span class="muted small">Checked <?= h(gmdate('M j, H:i', (int)$chkHealth['at'])) ?> UTC</span>
    <button class="btn" name="action" value="chk_fresh">Check again</button>
  </form>
</section>

<section class="card">
  <h3>What players get <span class="muted small">from the file the launcher uses</span></h3>
  <ul class="chk-feats">
  <?php foreach (RS_SDK_HEALTH_FEATS as $fk => $fname): $f = $chkHealth['feats'][$fk] ?? ['state' => 'off', 'how' => '', 'fix' => '']; ?>
    <li class="chk-f-<?= h($f['state']) ?>"><span class="chk-f-i"><?= $chkStateIcon[$f['state']] ?? '–' ?></span>
      <span><b><?= h($fname) ?></b><small><?= h((string)$f['how']) ?></small><?php if ((string)$f['fix'] !== ''): ?><small class="chk-fix">Fix: <?= h((string)$f['fix']) ?></small><?php endif; ?></span></li>
  <?php endforeach; ?>
  </ul>
  <?php $schemaAll = array_filter($chkHealth['schema']); if ($schemaAll): ?>
  <div class="chk-schema">
    <h4>Against the launcher's rules</h4>
    <?php foreach ($schemaAll as $where => $errs): ?>
    <p class="small"><b><?= h(['release' => 'The file in their release', 'repo' => 'The file in their repo', 'ours' => 'Our profile'][$where] ?? $where) ?>:</b></p>
    <ul class="chk-errs small"><?php foreach ($errs as [$path, $msg]): ?><li><span class="mono"><?= h((string)$path) ?></span> <?= h((string)$msg) ?></li><?php endforeach; ?></ul>
    <?php endforeach; ?>
  </div>
  <?php elseif ($chkHealth['docs']): ?><p class="ok small chk-schema-ok">Every file passes the launcher's rules.</p><?php endif; ?>
  <?php $pl = $chkHealth['players']; $rp = (array)($pl['reports'] ?? []); ?>
  <p class="small chk-players"><b>Players, last 60 days:</b> <?= (int)($rp['runs'] ?? 0) ?> plays great · <?= (int)($rp['issues'] ?? 0) ?> has problems · <?= (int)($rp['broken'] ?? 0) ?> won't run · <?= (int)($pl['logs'] ?? 0) ?> logs sent (<?= (int)($pl['crashes'] ?? 0) ?> crashes)
    <?php if ((int)($pl['logs'] ?? 0)): ?> · <a href="inbox.php?show=logs&amp;app=<?= h(rawurlencode((string)$chkA['id'])) ?>">See the logs</a><?php endif; ?></p>
  <?php foreach ($chkHealth['docs'] as $where => $txt): ?>
  <details class="chk-doc"><summary class="small"><?= h(['release' => 'The file in their release', 'repo' => 'The file in their repo', 'ours' => 'Our profile'][$where] ?? $where) ?></summary><pre class="mono"><?= h((string)$txt) ?></pre></details>
  <?php endforeach; ?>
</section>

<section class="card chk-draft">
  <h3>Draft it</h3>
  <form method="post" class="chk-draft-f"><?= $csrf ?><input type="hidden" name="id" value="<?= h((string)$chkA['id']) ?>">
    <fieldset class="chk-models"><legend class="small">Test draft with</legend>
      <label><input type="checkbox" name="models[]" value="haiku"> <b>Haiku</b> <small class="muted">cheapest</small></label>
      <label><input type="checkbox" name="models[]" value="sonnet" checked> <b>Sonnet</b> <small class="muted">balanced</small></label>
      <label><input type="checkbox" name="models[]" value="opus"> <b>Opus</b> <small class="muted">strongest</small></label>
      <label><input type="checkbox" name="models[]" value="haiku+sonnet"> <b>Haiku reads, Sonnet codes</b> <small class="muted">mixed</small></label>
    </fieldset>
    <div class="chk-draft-btns">
      <button class="btn primary" name="action" value="chk_test"<?= rs_prq_now_ready() ? '' : ' disabled title="Add the Anthropic API key in PR queue > Setup first"' ?>>Run test drafts</button>
      <button class="btn" name="action" value="chk_queue" title="The usual draft, straight into the PR queue with the model from Settings > AI usage">Draft for the PR queue</button>
    </div>
    <p class="muted small">Each model drafts the whole thing on its own (the json, the PR text and any code change), so you can compare them below. It takes a few minutes; what each cost shows with it and goes on <a href="ai-usage.php">AI usage</a>.</p>
  </form>
</section>
<?php endif; ?>

<section class="card chk-tests"<?= $chkRunning ? ' data-running="' . $chkRunning . '"' : '' ?> data-app="<?= h($chkA ? (string)$chkA['id'] : '') ?>">
  <h3><?= $chkA ? 'Test drafts' : 'Latest test drafts' ?> <?php if ($chkRunning): ?><span class="muted small"><span class="sdk-spin" aria-hidden="true"></span> <?= $chkRunning ?> working</span><?php endif; ?></h3>
  <?php if (!$chkTests): ?><p class="muted"><?= $chkA ? 'None yet. Run one above.' : 'None yet. Pick a recomp above.' ?></p><?php endif; ?>
  <?php foreach ($chkTests as $t): $ta = $chkA ?: rs_pc_app((string)$t['id']); $d = (array)($t['draft'] ?? []); $st = (string)($t['status'] ?? '');
      $mName = $chkModelName((string)$t['model']) . ((string)($t['code_model'] ?? '') !== '' && $t['code_model'] !== $t['model'] ? ' reads, ' . $chkModelName((string)$t['code_model']) . ' codes' : '');
      $code = is_array($d['code'] ?? null) ? $d['code'] : null; ?>
  <details class="chk-t chk-t-<?= h($st) ?>">
    <summary>
      <span class="chk-t-name"><strong><?= h($mName) ?></strong><?php if (!$chkA && $ta): ?><span class="muted small"><a href="sdk-support.php?tab=check&amp;app=<?= h(rawurlencode((string)$ta['id'])) ?>"><?= h((string)$ta['name']) ?></a></span><?php endif; ?></span>
      <span class="chk-t-st small"><?= $st === 'running' ? '<span class="sdk-spin" aria-hidden="true"></span> Drafting…' : ($st === 'done' ? ($code ? 'Done, with code changes' : 'Done') : ($st === 'skipped' ? 'Skipped' : ($st === 'stopped' ? 'Stopped' : 'Didn\'t finish'))) ?></span>
      <span class="chk-t-cost small"><?= $st === 'running' ? '' : h($chkUsd((float)$t['cost'])) . ' · ' . (int)$t['calls'] . ' calls' . ($t['secs'] !== null ? ' · ' . (int)ceil($t['secs'] / 60) . ' min' : '') ?></span>
      <span class="chk-t-when muted small"><?= h(gmdate('M j, H:i', (int)($t['finished'] ?? $t['started'] ?? time()))) ?></span>
    </summary>
    <?php if ($st === 'skipped' || $st === 'failed'): ?><p class="small <?= $st === 'failed' ? 'bad' : '' ?>"><?= h((string)($d['skip_reason'] ?? $d['error'] ?? '')) ?></p><?php endif; ?>
    <?php if ($st === 'done'): ?>
      <?php if (trim((string)($d['note'] ?? '')) !== ''): ?><p class="prq-note small"><strong>Drafter's notes:</strong> <?= nl2br(h((string)$d['note'])) ?></p><?php endif; ?>
      <?= rs_prq_found_html(is_array($d['found'] ?? null) ? $d['found'] : null, (string)($ta['repo'] ?? '')) ?>
      <?php if ($code && !empty($code['edits'])): ?>
      <div class="prq-code"><h4>Code changes <span class="muted small">adds <?= h(implode(', ', array_map('strval', (array)($code['adds'] ?? [])))) ?></span></h4>
        <?php foreach ((array)($code['explain'] ?? []) as $ex): ?><p class="small">• <?= h((string)$ex) ?></p><?php endforeach; ?>
        <?php foreach ((array)$code['edits'] as $ed): ?><div class="prq-diff"><div class="prq-diff-path mono small"><?= h((string)($ed['path'] ?? '')) ?><?= (string)($ed['why'] ?? '') !== '' ? ' · ' . h((string)$ed['why']) : '' ?></div><pre class="prq-del"><?= h((string)($ed['find'] ?? '')) ?></pre><pre class="prq-add"><?= h((string)($ed['replace'] ?? '')) ?></pre></div><?php endforeach; ?>
      </div>
      <?php endif; ?>
      <details class="chk-doc"><summary class="small">Pull request text</summary><pre class="chk-body"><?= h((string)($d['body'] ?? '')) ?></pre></details>
      <details class="chk-doc"><summary class="small">recomplauncher.json</summary><pre class="mono"><?= h((string)json_encode($d['json'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></pre></details>
      <?php if ($ta): $why = 'We made a recomplauncher.json for ' . $ta['name'] . ' so RecompLauncher can set it up from the player\'s own copy, with its settings, saves and logs. If you put it in your release zip, next to the program, the launcher reads it from each release:' . "\n\n" . json_encode($d['json'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); $cl = rs_pc_contact_links($ta, $why); ?>
      <form method="post" class="chk-t-btns"><?= $csrf ?><input type="hidden" name="id" value="<?= h((string)$ta['id']) ?>"><input type="hidden" name="name" value="<?= h((string)$t['name']) ?>">
        <button class="btn primary" name="action" value="chk_dry_queue" title="Into the PR queue, where it's looked over and sent like any other">Put in the PR queue</button>
        <button class="btn" name="action" value="chk_dry_here" onclick="return confirm('Use this file for <?= h(addslashes((string)$ta['name'])) ?> on RecompLauncher now? Players get it with the next catalog update.')" title="Our profile in the catalog: players get it without the developer doing anything (code changes can't come this way)">Use as our profile</button>
        <?php foreach ($cl as [$label, $href, $kind]): if ($kind === 'gh' && $label === 'Its repo') continue; ?><a class="btn" href="<?= h($href) ?>"<?= $kind === 'msg' ? '' : ' target="_blank" rel="noopener"' ?>><?= h($label === 'Email' ? 'Email the file' : ($label === 'Open an issue' ? 'Send as an issue' : $label)) ?></a><?php endforeach; ?>
        <button class="btn danger" name="action" value="chk_dry_delete">Delete</button>
      </form>
      <?php endif; ?>
    <?php elseif ($st !== 'running'): ?>
      <form method="post" class="chk-t-btns"><?= $csrf ?><input type="hidden" name="id" value="<?= h((string)$t['id']) ?>"><input type="hidden" name="name" value="<?= h((string)$t['name']) ?>"><button class="btn danger" name="action" value="chk_dry_delete">Delete</button></form>
    <?php endif; ?>
  </details>
  <?php endforeach; ?>
</section>
<?php // (4.11) The drafter's own log for test runs: what it's doing right now, and why one didn't finish.
$chkLog = rs_prq_dry_dir() . '/run.log';
$chkLines = is_file($chkLog) ? array_slice(file($chkLog, FILE_IGNORE_NEW_LINES) ?: [], -60) : [];
if ($chkLines): ?>
<details class="card chk-log"><summary><b>Drafter log</b> <span class="muted small">the last <?= count($chkLines) ?> lines of the test runs</span></summary><pre class="mono"><?= h(implode("\n", $chkLines)) ?></pre></details>
<?php endif; ?>
<?php if ($chkRunning): ?>
<script>
(function () {
  var box = document.querySelector('.chk-tests'), app = box.getAttribute('data-app'), was = +box.getAttribute('data-running');
  var t = setInterval(function () {
    fetch('sdk-support.php?dry_poll=' + encodeURIComponent(app), { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (j.ok && j.running < was) { clearInterval(t); location.reload(); }
    }).catch(function () {});
  }, 8000);
})();
</script>
<?php endif; ?>
