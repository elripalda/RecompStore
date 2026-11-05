<?php
// RecompStore admin (2.9): AI check. Every RecompLauncher recomp with its AI-assisted label and
// what its GitHub shows: AI agents' files, commits credited to AI tools, the README saying so.
// The check suggests; the label is the admin's (or the developer's) call.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_pc_tables();
@set_time_limit(180);

// (2.9) RecompLauncher only: the PS5 store doesn't label AI use.
$store = 'pc';

// Every app of the store: [key, name, repo, hidden, ai fields, edit link].
function rs_ai_rows(string $store): array {
    $rows = [];
    if ($store === 'pc') {
        // (3.9) The AI check reads GitHub only: a GitLab recomp shows as not on GitHub.
        foreach (rs_pc_apps() as $a) $rows[] = ['key' => $a['id'], 'name' => $a['name'], 'repo' => rs_pc_host($a) === '' ? (string)$a['repo'] : '', 'hidden' => !empty($a['hidden']), 'soon' => !empty($a['soon']),
            'ai' => (string)$a['ai'], 'ai_by' => (string)$a['ai_by'], 'ai_uses' => (array)$a['ai_uses'], 'edit' => 'pc.php?edit=' . rawurlencode($a['id']) . '#edit'];
    } else {
        foreach (rs_games() as $g) $rows[] = ['key' => $g['id'], 'name' => $g['name'], 'repo' => $g['source'] === 'github' ? (string)$g['repo'] : '', 'hidden' => !empty($g['hidden']) || !empty($g['beta']) || !empty($g['testers']), 'soon' => !empty($g['soon']),
            'ai' => (string)$g['ai'], 'ai_by' => (string)$g['ai_by'], 'ai_uses' => (array)$g['ai_uses'], 'edit' => 'edit.php?id=' . rawurlencode($g['id'])];
    }
    return $rows;
}

function rs_ai_set(string $store, string $key, string $v): string {
    if ($store === 'pc') {
        $a = rs_pc_app($key);
        if (!$a) throw new RuntimeException('That app is gone.');
        $a['ai'] = $v;
        $a['ai_uses'] = $v === 'yes' ? (rs_ai_uses_clean($a['ai_uses']) ?: ['code']) : [];
        if ($v !== 'yes') $a['ai_note'] = '';
        $a['ai_by'] = $v === '' ? '' : 'store';
        rs_pc_save($a);
        if (empty($a['hidden'])) rs_pc_publish_one($a['id']);
        return $a['name'];
    }
    $g = rs_game($key);
    if (!$g) throw new RuntimeException('That app is gone.');
    $g['ai'] = $v;
    $g['ai_uses'] = $v === 'yes' ? (rs_ai_uses_clean($g['ai_uses']) ?: ['code']) : [];
    if ($v !== 'yes') $g['ai_note'] = '';
    $g['ai_by'] = $v === '' ? '' : 'store';
    rs_save_game($g, null);
    return $g['name'];
}

// "Check again" from an app's edit page: checks its repo now, then back to where it came from.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['repo'])) {
    $repo = (string)$_GET['repo'];
    try {
        $s = rs_ai_scan($repo, 0);
        rs_flash('Checked ' . $repo . ' on GitHub: ' . strtolower(RS_AI_VERDICTS[$s['verdict']] ?? $s['verdict']) . '.');
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $back = preg_match('#/admin/((edit|pc|ai)\.php\?[^\s"<>]*)$#', $ref, $m) ? $m[1] : 'ai.php?store=' . $store;
    header('Location: ' . $back);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'scan_batch') {
        // A few repos per call, the page calls again until none are left (GitHub's limit is the brake).
        header('Content-Type: application/json');
        $force = !empty($_POST['all']);
        $since = (int)($_POST['since'] ?? 0);
        $todo = [];
        foreach (rs_ai_rows($store) as $r) {
            if ($r['repo'] === '' || !rs_repo_ok($r['repo'])) continue;
            $c = rs_ai_scan_cached($r['repo']);
            if (!$c || ($force && (int)$c['at'] < $since)) $todo[strtolower($r['repo'])] = $r['repo'];
        }
        $done = 0;
        $err = '';
        // Each repo is several questions to GitHub: a few per call keeps it inside the host's time limit.
        foreach (array_slice(array_values($todo), 0, max(1, min(6, (int)($_POST['n'] ?? 2)))) as $repo) {
            try { rs_ai_scan($repo, 0); ++$done; } catch (Throwable $e) { $err = $e->getMessage(); break; }
        }
        echo json_encode(['ok' => $err === '', 'done' => $done, 'left' => max(0, count($todo) - $done), 'error' => $err]);
        exit;
    }
    try {
        if ($act === 'set') {
            $v = (string)($_POST['ai'] ?? '');
            $name = rs_ai_set($store, (string)($_POST['key'] ?? ''), in_array($v, ['yes', 'no'], true) ? $v : '');
            rs_log('ai', 'label', $name . ': ' . ($v === 'yes' ? 'AI-assisted' : ($v === 'no' ? 'not AI-assisted' : 'not decided')));
            rs_flash($name . ($v === 'yes' ? ' is labeled AI-assisted.' : ($v === 'no' ? ' is marked not AI-assisted.' : ' is back to not decided.'))
                . ($store === 'ps5' ? ' Publish to send it to the store and the site.' : ' It\'s on RecompLauncher now.'));
        } elseif ($act === 'label_signs') {
            $n = 0;
            foreach (rs_ai_rows($store) as $r) {
                if ($r['ai'] !== '' || $r['repo'] === '') continue;
                $c = rs_ai_scan_cached($r['repo']);
                if ($c && $c['verdict'] === 'signs') { rs_ai_set($store, $r['key'], 'yes'); ++$n; }
            }
            rs_log('ai', 'label', 'labeled ' . $n . ' with signs of AI as AI-assisted');
            rs_flash('Labeled ' . $n . ' AI-assisted.' . ($store === 'ps5' && $n ? ' Publish to send them out.' : ''));
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ai.php?store=' . $store . '&view=' . rawurlencode((string)($_POST['view'] ?? '')));
    exit;
}

$rows = rs_ai_rows($store);
foreach ($rows as &$r) $r['scan'] = $r['repo'] !== '' ? rs_ai_scan_cached($r['repo']) : null;
unset($r);
$verdict = fn($r) => $r['scan']['verdict'] ?? '';
$views = [
    'decide' => ['To decide', fn($r) => $r['ai'] === '' && in_array($verdict($r), ['signs', 'mentions'], true)],
    'conflict' => ['Developer says no', fn($r) => $r['ai'] === 'no' && $r['ai_by'] === 'developer' && $verdict($r) === 'signs'],
    'yes' => ['AI-assisted', fn($r) => $r['ai'] === 'yes'],
    'no' => ['Not AI-assisted', fn($r) => $r['ai'] === 'no'],
    'clear' => ['No signs found', fn($r) => $r['ai'] === '' && $verdict($r) === 'clear'],
    'unchecked' => ['Not checked', fn($r) => $r['repo'] !== '' && $r['scan'] === null],
    'all' => ['All', fn($r) => true],
];
$view = (string)($_GET['view'] ?? '');
if (!isset($views[$view])) $view = 'decide';
$counts = array_map(fn($v) => count(array_filter($rows, $v[1])), $views);
$list = array_values(array_filter($rows, $views[$view][1]));
usort($list, fn($a, $b) => [$a['hidden'], strtolower($a['name'])] <=> [$b['hidden'], strtolower($b['name'])]);
$signsUnlabeled = count(array_filter($rows, fn($r) => $r['ai'] === '' && $verdict($r) === 'signs'));

rs_head('AI check');
?>
<section class="head-row"><div><h1>AI check <span class="muted">RecompLauncher</span></h1>
  <p class="muted">Which PC recomps were made with help from AI tools. Labeled ones show "AI-assisted" on their page, and visitors can hide them everywhere in the site's Settings. The GitHub check looks for AI agents' files in the repo, commits credited to AI tools, and the README saying so. It only finds signs: no signs doesn't mean no AI, and one sign doesn't always mean much. You decide.</p></div></section>
<section class="card ai-tools">
  <div class="ai-tools-row">
    <button class="btn primary" data-scan>Check <?= $counts['unchecked'] ? 'the ' . $counts['unchecked'] . ' not checked yet' : 'everything again' ?></button>
    <span class="muted" data-scan-state role="status"></span>
    <?php if ($signsUnlabeled): ?>
    <form method="post" class="inline" onsubmit="return confirm('Label all <?= $signsUnlabeled ?> apps with signs of AI as AI-assisted?')"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="label_signs"><input type="hidden" name="store" value="<?= $store ?>"><input type="hidden" name="view" value="<?= h($view) ?>"><button class="btn">Label the <?= $signsUnlabeled ?> with signs AI-assisted</button></form>
    <?php endif; ?>
  </div>
  <nav class="ai-tabs"><?php foreach ($views as $k => [$label]): ?><a href="ai.php?store=<?= $store ?>&amp;view=<?= $k ?>"<?= $view === $k ? ' class="on"' : '' ?>><?= h($label) ?> <span class="count"><?= $counts[$k] ?></span></a><?php endforeach; ?></nav>
</section>
<section class="card">
  <?php if (!$list): ?><p class="muted">Nothing here.</p><?php else: ?>
  <ul class="ai-list">
    <?php foreach ($list as $r): $s = $r['scan']; ?>
    <li>
      <div class="ai-row-head">
        <a href="<?= h($r['edit']) ?>"><strong><?= h($r['name']) ?></strong></a>
        <?php if ($r['hidden']): ?><span class="pill">Hidden</span><?php elseif ($r['soon']): ?><span class="pill">Soon</span><?php endif; ?>
        <?php if ($r['ai'] === 'yes'): ?><span class="pill warn">AI-assisted<?= $r['ai_by'] === 'developer' ? ' · developer said' : '' ?></span>
        <?php elseif ($r['ai'] === 'no'): ?><span class="pill ok">Not AI-assisted<?= $r['ai_by'] === 'developer' ? ' · developer said' : '' ?></span><?php endif; ?>
        <?php if ($r['repo'] !== ''): ?><a class="muted mono ai-repo" href="https://github.com/<?= h($r['repo']) ?>" target="_blank" rel="noopener"><?= h($r['repo']) ?></a><?php endif; ?>
      </div>
      <?php if ($s): ?>
      <div class="ai-scan"><span class="pill <?= ['signs' => 'warn', 'mentions' => 'warn', 'clear' => 'ok', 'error' => ''][$s['verdict']] ?? '' ?>"><?= h(RS_AI_VERDICTS[$s['verdict']] ?? '') ?></span> <span class="muted"><?= h(rs_date('M j', (int)$s['at'])) ?></span>
        <?php if ($s['evidence']): ?><ul><?php foreach ($s['evidence'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
      <?php elseif ($r['repo'] === ''): ?><div class="ai-scan muted">Not on GitHub: nothing to check.</div><?php endif; ?>
      <div class="ai-actions">
        <?php foreach (['yes' => 'AI-assisted', 'no' => 'Not AI-assisted', '' => 'Not decided'] as $v => $label): if ($r['ai'] === $v) continue; ?>
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="set"><input type="hidden" name="store" value="<?= $store ?>"><input type="hidden" name="view" value="<?= h($view) ?>"><input type="hidden" name="key" value="<?= h($r['key']) ?>"><input type="hidden" name="ai" value="<?= $v ?>"><button class="btn<?= $v === '' ? ' link' : '' ?>"><?= h($label) ?></button></form>
        <?php endforeach; ?>
        <?php if ($r['repo'] !== ''): ?><a class="link" href="ai.php?store=<?= $store ?>&amp;repo=<?= rawurlencode($r['repo']) ?>">Check again</a><?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<script>
(function () {
  const b = document.querySelector('[data-scan]'), st = document.querySelector('[data-scan-state]');
  if (!b) return;
  const all = <?= $counts['unchecked'] ? 'false' : 'true' ?>, since = Math.floor(Date.now() / 1000);
  let checked = 0;
  b.addEventListener('click', async () => {
    b.disabled = true;
    for (;;) {
      const fd = new FormData();
      fd.set('csrf', <?= json_encode(rs_csrf()) ?>); fd.set('action', 'scan_batch'); fd.set('store', <?= json_encode($store) ?>);
      if (all) { fd.set('all', '1'); fd.set('since', String(since)); }
      let j;
      try { j = await fetch('ai.php', { method: 'POST', body: fd }).then((r) => r.json()); } catch (e) { st.textContent = 'Stopped: the site didn\'t answer. Try again.'; break; }
      checked += j.done;
      st.textContent = 'Checked ' + checked + (j.left ? ', ' + j.left + ' to go…' : '. Done.');
      if (!j.ok) { st.textContent = j.error + ' (' + checked + ' checked)'; break; }
      if (!j.left || !j.done) { location.reload(); break; }
    }
    b.disabled = false;
  });
})();
</script>
<?php rs_foot();
