<?php
// RecompStore admin (2.6): Health. Listings that need a look (a renamed repo, a missing zip, a
// changed title ID, a dead link), and new PS5 apps that aren't in the store yet.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_health_tables();
@set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'health.php';
    try {
        if ($act === 'check') {
            [$n, $left] = rs_health_run(25, true);
            if ($left < 0) rs_flash('A check is already running. Give it a minute, then reload.');
            else rs_flash('Checked ' . $n . ($n === 1 ? ' listing' : ' listings') . '.' . ($left > 0 ? ' ' . $left . ' more are checked next time (GitHub is asked a little at a time).' : ''));
        } elseif ($act === 'fix_repo') {
            $id = (string)($_POST['id'] ?? '');
            $repo = trim((string)($_POST['repo'] ?? ''));
            $g = rs_game($id);
            if (!$g || !rs_repo_ok($repo)) throw new RuntimeException('That fix didn\'t arrive. Reload and try again.');
            $old = (string)$g['repo'];
            $g['repo'] = $repo;
            rs_save_game($g);
            rs_log('change', 'health.php ' . mb_substr($id, 0, 40), 'repo ' . $old . ' -> ' . $repo);
            [$state, $problems] = rs_health_check($g);
            rs_health_save($g['id'], $state, $problems);
            rs_flash($g['name'] . ' now gets its downloads from ' . $repo . '. Press Publish to send it out.');
        } elseif ($act === 'found_now') {
            [$n, $looked] = rs_found_refresh(25);
            rs_flash($n ? 'Found ' . $n . ' new ' . ($n === 1 ? 'app' : 'apps') . '.' : 'Nothing new this time' . ($looked ? ' (' . $looked . ' looked at)' : '') . '.');
            $back .= '#found';
        } elseif ($act === 'clash_ok' || $act === 'clash_separate') {
            // (4.3) Settled either way: the same app under another name, or separate apps you know about.
            rs_radar_ok((string)($_POST['sig'] ?? ''));
            $tid = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['tid'] ?? '')));
            rs_log('radar', $tid, $act === 'clash_ok' ? 'same app' : 'separate apps');
            rs_flash($act === 'clash_ok' ? 'Settled: the same app. It comes back only if another app turns up with that ID.' : 'Settled: separate apps. It comes back only if another app turns up with that ID.');
            $back .= '#radar';
        } elseif ($act === 'skip' || $act === 'unskip') {
            rs_db()->prepare('UPDATE found_apps SET state = ? WHERE k = ?')->execute([$act === 'skip' ? 'skip' : 'new', (string)($_POST['k'] ?? '')]);
            $back .= '#found';
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

rs_health_maybe();
$sum = rs_health_summary();
$found = rs_found_list();
$games = [];
foreach (rs_health_listed() as $g) $games[$g['id']] = $g;
$need = array_filter($sum['rows'], fn($r) => $r[0] !== 'ok');
uasort($need, fn($a, $b) => ($a[0] === 'bad' ? 0 : 1) <=> ($b[0] === 'bad' ? 0 : 1));
$fine = array_filter($sum['rows'], fn($r) => $r[0] === 'ok');
$ago = function (?string $t): string {
    $s = $t === null ? null : time() - (int)$t;
    if ($s === null) return 'not yet';
    if ($s < 120) return 'just now';
    if ($s < 7200) return floor($s / 60) . ' minutes ago';
    if ($s < 172800) return floor($s / 3600) . ' hours ago';
    return floor($s / 86400) . ' days ago';
};
$lastRun = rs_kv_get('health_run');
$lastFound = rs_kv_get('found_run');
$icon = fn(array $g) => $g['icon'] ? '<img src="' . h($g['icon']) . '" alt="" loading="lazy">' : '<span class="blank">' . h(mb_substr($g['name'] ?: '?', 0, 2)) . '</span>';
$importForm = function (array $entries, string $label, string $cls = '') {
    return '<form method="post" action="import.php"><input type="hidden" name="csrf" value="' . h(rs_csrf()) . '"><input type="hidden" name="action" value="preview">'
        . '<input type="hidden" name="lookup" value="1"><input type="hidden" name="json" value="' . h(json_encode(['apps' => $entries], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '">'
        . '<button' . ($cls !== '' ? ' class="' . h($cls) . '"' : '') . '>' . h($label) . '</button></form>';
};
$clashes = rs_radar_clashes();
$look = strtoupper(trim((string)($_GET['tid'] ?? '')));
$lookRes = preg_match('/^[A-Z]{4}\d{5}$/', $look) ? rs_tid_uses($look) : null;
$useRow = fn(array $u) => '<li><span class="pill' . ($u['where'] === 'store' ? ' cyan' : '') . '">' . h(rs_tid_where($u['where'])) . '</span> '
    . ($u['link'] !== '' ? '<a href="' . h($u['link']) . '"' . (str_starts_with($u['link'], 'https://') ? ' target="_blank" rel="noopener"' : '') . '>' . h($u['name']) . '</a>' : h($u['name']))
    . ($u['repo'] !== '' ? ' <span class="muted">' . h($u['repo']) . '</span>' : '') . '</li>';
$via = ['prospero' => 'Prospero Store\'s catalog', 'github' => 'GitHub'];
$catNames = RS_CATEGORIES;

rs_head('Health', true, ['assets/health.css']);
?>
<section class="head-row">
  <div>
    <h1>Health</h1>
    <p class="muted">Every listing is checked about once a day, the way a PS5 would install it: its GitHub repo, the release and zip it gets, and the title ID inside. Last checked <?= h($ago($lastRun['v'] ?? null)) ?>.</p>
  </div>
  <div class="actions">
    <a class="btn" href="icons.php">Refresh icons</a><form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="check"><button class="primary">Check now</button></form>
  </div>
</section>

<section class="card radar<?= $clashes ? ' radar-red' : '' ?>" id="radar">
  <h2>Title ID radar <span class="pill<?= $clashes ? ' bad' : ' ok' ?>"><?= $clashes ? count($clashes) . (count($clashes) === 1 ? ' clash' : ' clashes') : 'No clashes' ?></span></h2>
  <p class="muted">Every title ID in the store, the taken list, the Inbox and the PS5 apps found on GitHub and in Prospero Store's catalog (looked at daily, <a href="#found">below</a>). Two different apps on one ID write over each other on a PS5.</p>
  <?php foreach ($clashes as $tid => $c): ?>
  <div class="clash">
    <p><strong class="mono"><?= h($tid) ?></strong> is used by <?= count(array_unique(array_column($c['uses'], 'key'))) ?> different apps:</p>
    <ul class="uses"><?php foreach ($c['uses'] as $u) echo $useRow($u); ?></ul>
    <form method="post" class="clash-pick"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="sig" value="<?= h($c['sig']) ?>"><input type="hidden" name="tid" value="<?= h($tid) ?>"><button name="action" value="clash_ok">Same app</button><button name="action" value="clash_separate">Separate apps</button></form>
  </div>
  <?php endforeach; ?>
  <form method="get" class="tid-look" action="health.php#radar">
    <label>Look up a title ID <input name="tid" maxlength="9" placeholder="PPSA12345" value="<?= h($look) ?>" class="mono" spellcheck="false" autocomplete="off"></label><button>Look up</button>
  </form>
  <?php if ($lookRes): ?>
  <div class="tid-res">
    <?php if ($lookRes['block']): ?><p class="bad-text"><strong><?= h($look) ?></strong> is in the block the PS5 store keeps for itself (<?= h(RS_MOVE_BLOCK[0]) ?> to <?= h(RS_MOVE_BLOCK[1]) ?>).</p><?php endif; ?>
    <?php if (!$lookRes['uses']): ?><p><strong class="mono"><?= h($look) ?></strong> isn't used by anything we know of.</p>
    <?php else: ?><p><strong class="mono"><?= h($look) ?></strong> is used by:</p><ul class="uses"><?php foreach ($lookRes['uses'] as $u) echo $useRow($u); ?></ul><?php endif; ?>
  </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Need a look <span class="pill<?= $need ? ' warn' : ' ok' ?>"><?= count($need) ?></span></h2>
  <?php if ($sum['unchecked']): ?><p class="muted small-note"><?= (int)$sum['unchecked'] ?> <?= $sum['unchecked'] === 1 ? 'listing hasn\'t' : 'listings haven\'t' ?> been checked yet. Check now does the next ones.</p><?php endif; ?>
  <?php if (!$need): ?>
  <p class="muted"><?= $sum['ok'] ? 'Nothing needs a look. Every listing checked installs the way it should.' : 'Nothing checked yet.' ?></p>
  <?php else: ?>
  <ul class="hl">
    <?php foreach ($need as $id => [$state, $problems, $checked]): $g = $games[$id] ?? null; if (!$g) continue; ?>
    <li class="<?= h($state) ?>">
      <a class="hl-app" href="edit.php?id=<?= h(rawurlencode($id)) ?>"><?= $icon($g) ?><span><strong><?= h($g['name'] ?: $id) ?></strong><small><?= h($id) ?> · <?= h($g['source'] === 'url' ? 'fixed link' : (string)$g['repo']) ?><?= !empty($g['testers']) ? ' · testers only' : '' ?></small></span></a>
      <ul class="hl-why">
        <?php foreach ($problems as $p): ?>
        <li class="<?= h((string)$p[0]) ?>"><span class="dot" aria-hidden="true"></span><span><?= h((string)$p[1]) ?></span>
          <?php if (!empty($p[2]['repo'])): ?>
          <form method="post" class="hl-fix"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="fix_repo"><input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="repo" value="<?= h((string)$p[2]['repo']) ?>"><button>Use <?= h((string)$p[2]['repo']) ?></button></form>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <small class="muted hl-when">Checked <?= h($ago((string)strtotime($checked . ' UTC'))) ?></small>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if ($fine): ?>
  <details class="hl-fine"><summary><?= count($fine) ?> <?= count($fine) === 1 ? 'listing is' : 'listings are' ?> fine</summary>
    <p class="muted small-note"><?= implode(', ', array_map(fn($id) => h(($games[$id]['name'] ?? '') ?: $id) . (in_array('note', array_column($fine[$id][1], 0), true) ? ' <span class="muted">(archived on GitHub)</span>' : ''), array_keys($fine))) ?></p>
  </details>
  <?php endif; ?>
</section>

<section class="card" id="found">
  <div class="found-head">
    <h2>New apps found <span class="pill<?= $found['new'] ? ' cyan' : '' ?>"><?= count($found['new']) ?></span></h2>
    <div class="found-acts">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="found_now"><button>Look again</button></form>
      <?php if (count($found['new']) > 1) echo $importForm(array_map('rs_found_import_entry', $found['new']), 'Import all ' . count($found['new'])); ?>
    </div>
  </div>
  <p class="muted small-note">PS5 apps that aren't in RecompStore yet, from <?= h($via['prospero']) ?> and a GitHub search, looked at once a day (last <?= h($ago($lastFound['v'] ?? null)) ?>). Add with Import opens the import preview with it filled in: it comes in as Testers only, so nothing reaches players until you set it to Everyone and publish.</p>
  <?php if (!$found['new']): ?>
  <p class="muted">Nothing new right now.</p>
  <?php else: ?>
  <div class="found">
    <?php foreach ($found['new'] as $r): $d = $r['data']; ?>
    <article>
      <div class="found-top">
        <?php if (!empty($d['icon'])): ?><img src="<?= h((string)$d['icon']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="blank"><?= h(mb_substr((string)$r['name'], 0, 2)) ?></span><?php endif; ?>
        <div><strong><?= h((string)$r['name']) ?></strong><small><?= h(trim(((string)($d['author'] ?? '') !== '' ? 'By ' . $d['author'] . ' · ' : '') . ((string)$r['title_id'] ?: '') . ' · ' . ($catNames[$d['category'] ?? 'apps'] ?? 'Apps'), ' ·')) ?></small></div>
      </div>
      <?php if (!empty($d['description'])): ?><p class="found-desc"><?= h(mb_strimwidth((string)$d['description'], 0, 220, '…')) ?></p><?php endif; ?>
      <p class="found-src"><a href="https://github.com/<?= h((string)$r['repo']) ?>" target="_blank" rel="noopener"><?= h((string)$r['repo']) ?></a><span class="muted"> · from <?= h($via[(string)$r['via']] ?? (string)$r['via']) ?><?= !empty($d['version']) ? ' · ' . h((string)$d['version']) : '' ?></span></p>
      <div class="found-btns">
        <?= $importForm([rs_found_import_entry($r)], 'Add with Import', 'primary') ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="skip"><input type="hidden" name="k" value="<?= h((string)$r['k']) ?>"><button>Not interested</button></form>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($found['skip']): ?>
  <details class="hl-fine"><summary>Not interested (<?= count($found['skip']) ?>)</summary>
    <ul class="skipped">
      <?php foreach ($found['skip'] as $r): ?>
      <li><span><?= h((string)$r['name']) ?> <small class="muted"><?= h((string)$r['repo']) ?></small></span><form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="unskip"><input type="hidden" name="k" value="<?= h((string)$r['k']) ?>"><button class="link">Bring back</button></form></li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>
</section>
<?php rs_foot();
