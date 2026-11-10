<?php
// (4.4) Recomp Achievements: write and check each recomp's list (SDK format 1), pick the test
// accounts that can use it while it's private, and watch unlocks and the Ranked review queue.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/ach.php';
rs_require_login();

$game = (string)($_GET['game'] ?? '');
$draft = null; $errs = []; $warns = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'testers') {
        $names = array_values(array_unique(array_filter(array_map(fn($s) => strtolower(trim($s)), preg_split('/[\s,]+/', (string)($_POST['testers'] ?? '')) ?: []))));
        rs_set_setting('ach_testers', implode(',', $names));
        rs_flash(count($names) . ' test ' . (count($names) === 1 ? 'account' : 'accounts') . ' saved.');
        header('Location: achievements.php'); exit;
    }
    if ($act === 'save') {
        $game = (string)($_POST['game'] ?? '');
        $raw = (string)($_POST['list'] ?? '');
        if (!rs_ach_game_ok($game) || !rs_pc_app($game)) { $errs[] = 'No PC recomp has the id "' . $game . '".'; $draft = $raw; }
        else {
            [$errs, $warns, $clean] = rs_ach_check($raw, $game);
            if ($clean) {
                rs_ach_list_save($game, $clean, !empty($_POST['live']), rs_me_name());
                rs_flash('Saved the list for ' . $game . ($warns ? ' (' . count($warns) . ' to look at).' : '.'), $warns ? 'warn' : 'ok');
                header('Location: achievements.php?game=' . rawurlencode($game)); exit;
            }
            $draft = $raw;
        }
    }
    if ($act === 'delete' && rs_ach_game_ok((string)($_POST['game'] ?? ''))) {
        rs_ach_list_delete((string)$_POST['game']);
        rs_flash('List removed. Players\' unlocks are kept.');
        header('Location: achievements.php'); exit;
    }
    if ($act === 'apps') {
        $ids = array_map('intval', (array)($_POST['ids'] ?? []));
        $to = (string)($_POST['to'] ?? '');
        if (!empty($_POST['oldest'])) $ids = array_map(fn($r) => (int)$r['account_id'], rs_ach_applications('pending', max(1, min(500, (int)$_POST['oldest']))));
        $n = rs_ach_decide($ids, $to, rs_me_name());
        rs_flash($n . ' ' . ($n === 1 ? 'application' : 'applications') . ' ' . ($to === 'approved' ? 'approved' : ($to === 'declined' ? 'declined' : 'moved back to waiting')) . '.');
        header('Location: achievements.php#apps'); exit;
    }
    if ($act === 'apply_open') {
        rs_set_setting('ach_apply_open', !empty($_POST['open']) ? '1' : '0');
        rs_set_setting('ach_auto_lists', !empty($_POST['auto']) ? '1' : '0');
        rs_flash('Saved.');
        header('Location: achievements.php#apps'); exit;
    }
    if ($act === 'review_done') {
        rs_ach_tables();
        rs_db()->prepare('UPDATE ach_review SET done = 1 WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        header('Location: achievements.php#review'); exit;
    }
}

$lists = rs_ach_lists();
$editing = $game !== '' ? ($lists[$game] ?? null) : null;
$template = json_encode([
    'format' => 1, 'game' => $game !== '' ? $game : 'recomp-id', 'source' => 'memory', 'console' => 'snes',
    'copies' => ['sha1:'], 'versions' => '>=1.0.0', 'voids' => [],
    'achievements' => [
        ['id' => 1, 'title' => 'First Steps', 'description' => 'Clear the first level', 'points' => 5, 'type' => 'progression', 'trigger' => '0xH0000=1'],
        ['id' => 2, 'title' => 'The End', 'description' => 'Beat the final boss', 'points' => 50, 'type' => 'win', 'trigger' => '0xH0001=1'],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$text = $draft ?? ($editing ? json_encode($editing['list'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $template);
$counts = $editing ? rs_ach_unlock_counts($game) : [];
$recent = rs_ach_recent(30);
$review = rs_ach_review_open();
$testers = rs_ach_testers();
$appCounts = rs_ach_application_counts();
$appView = in_array((string)($_GET['apps'] ?? ''), ['approved', 'declined'], true) ? (string)$_GET['apps'] : 'pending';
$apps = rs_ach_applications($appView, 300);
$osLabel = ['windows' => 'Windows', 'linux' => 'Linux', 'mac' => 'Mac', 'steamdeck' => 'Steam Deck'];
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$reasonLabel = ['earlier' => 'had it before', 'no_session' => 'no live session', 'save_replay' => 'replayed by a save', 'imported_save' => 'someone else\'s save',
    'cheats' => 'cheats on', 'save_state' => 'save state', 'casual' => 'Casual', 'unknown' => 'not on the list'];

rs_head('Recomp Achievements', true, ['assets/account.css']);
?>
<section class="head-row">
  <div>
    <h1>Recomp Achievements</h1>
    <p class="muted">Alpha: only approved testers (and the test accounts below) can use it in RecompLauncher. Anyone signed in can apply from the launcher's Achievements page or recomplauncher.com/achievements. Lists follow SDK format 1. Every achievement shows the star icon, and finishing a whole list earns that game's Mastered badge.</p>
  </div>
</section>
<div class="role-cards">
  <div class="role-card"><span class="muted small">Lists</span><span class="n"><?= count($lists) ?></span><p class="muted small"><?= count(array_filter($lists, fn($l) => $l['live'])) ?> live for testers.</p></div>
  <div class="role-card"><span class="muted small">Testers</span><span class="n"><?= $appCounts['approved'] + count($testers) ?></span><p class="muted small"><?= $appCounts['pending'] ?> waiting to be approved.</p></div>
  <div class="role-card"><span class="muted small">To review</span><span class="n"><?= count($review) ?></span><p class="muted small">Unlock bursts too fast to be real play.</p></div>
</div>

<section class="card">
  <h2><?= $editing ? 'Edit the list for ' . h($game) : ($game !== '' ? 'New list for ' . h($game) : 'Add a list') ?></h2>
  <?php if ($errs): ?><div class="flash err"><?= implode('<br>', array_map('h', $errs)) ?></div><?php endif; ?>
  <?php if ($warns): ?><div class="flash warn"><?= implode('<br>', array_map('h', $warns)) ?></div><?php endif; ?>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="save">
    <label>Recomp id<input name="game" value="<?= h($game) ?>" required pattern="[a-z0-9][a-z0-9-]*" placeholder="supermarioworldrecomp" spellcheck="false"></label>
    <label>List (JSON)<textarea name="list" rows="18" class="mono" spellcheck="false"><?= h((string)$text) ?></textarea></label>
    <p class="muted small">source: <code>memory</code> (each achievement has a <code>trigger</code>, an rcheevos rule), <code>events</code> (each has an <code>event</code> name the port fires) or <code>native</code> (Xbox 360: names and points come from the player's disc; give <code>total</code>, the number of achievements, for Mastered). points: 5, 10, 25 or 50.</p>
    <label class="check"><input type="checkbox" name="live" value="1"<?= !$editing || $editing['live'] ? ' checked' : '' ?>> Live for test accounts</label>
    <div class="actions"><button class="btn primary">Check and save</button><?php if ($game !== ''): ?> <a class="btn" href="achievements.php">New list</a><?php endif; ?></div>
  </form>
  <?php if ($editing): ?>
  <h3>Unlocks on players' PCs</h3>
  <table class="ac-table"><thead><tr><th>#</th><th>Achievement</th><th>Points</th><th>Unlocked</th><th>Ranked</th></tr></thead><tbody>
  <?php foreach ((array)($editing['list']['achievements'] ?? []) as $x): $c = $counts[(int)($x['id'] ?? 0)] ?? [0, 0]; ?>
    <tr><td><?= (int)($x['id'] ?? 0) ?></td><td><?= h((string)($x['title'] ?? '')) ?></td><td><?= (int)($x['points'] ?? 0) ?></td><td><?= $c[0] ?></td><td><?= $c[1] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <form method="post" onsubmit="return confirm('Remove this list? Players keep what they unlocked.')"><?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="game" value="<?= h($game) ?>"><button class="btn danger">Remove the list</button></form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Lists</h2>
  <?php if (!$lists): ?><p class="muted">No lists yet.</p><?php else: ?>
  <table class="ac-table"><thead><tr><th>Recomp</th><th>Source</th><th>Achievements</th><th>Live</th><th>Updated</th></tr></thead><tbody>
  <?php foreach ($lists as $g => $l): $app = rs_pc_app($g); $n = ($l['list']['source'] ?? '') === 'native' ? (int)($l['list']['total'] ?? 0) : count((array)($l['list']['achievements'] ?? [])); ?>
    <tr><td><a href="achievements.php?game=<?= h(rawurlencode($g)) ?>"><?= h($app['name'] ?? $g) ?></a></td><td><?= h((string)($l['list']['source'] ?? '')) ?></td><td><?= $n ?: '–' ?></td><td><?= $l['live'] ? 'Yes' : 'No' ?></td><td class="muted small"><?= h((string)$l['updated']) ?> UTC<?= $l['who'] !== '' ? ' · ' . h($l['who']) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<section class="card" id="apps">
  <h2>Tester applications</h2>
  <form method="post" class="actions"><?= $csrf ?><input type="hidden" name="action" value="apply_open">
    <label class="check"><input type="checkbox" name="open" value="1"<?= rs_ach_apply_open() ? ' checked' : '' ?>> Applications open</label>
    <label class="check"><input type="checkbox" name="auto" value="1"<?= rs_setting('ach_auto_lists', '1') === '1' ? ' checked' : '' ?>> Make Xbox 360 lists automatically from testers' copies</label>
    <button class="btn">Save</button></form>
  <p class="muted small">
    <a href="achievements.php?apps=pending#apps"<?= $appView === 'pending' ? ' class="b"' : '' ?>>Waiting (<?= $appCounts['pending'] ?>)</a> ·
    <a href="achievements.php?apps=approved#apps"<?= $appView === 'approved' ? ' class="b"' : '' ?>>Approved (<?= $appCounts['approved'] ?>)</a> ·
    <a href="achievements.php?apps=declined#apps"<?= $appView === 'declined' ? ' class="b"' : '' ?>>Declined (<?= $appCounts['declined'] ?>)</a></p>
  <?php if ($appView === 'pending' && $apps): ?>
  <form method="post" class="actions"><?= $csrf ?><input type="hidden" name="action" value="apps"><input type="hidden" name="to" value="approved">
    <label>Approve the oldest<input type="number" name="oldest" value="25" min="1" max="500" style="width:6em"></label><button class="btn primary">Approve</button></form>
  <?php endif; ?>
  <?php if (!$apps): ?><p class="muted">None here.</p><?php else: ?>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="apps">
  <table class="ac-table"><thead><tr><th></th><th>Account</th><th>Plays on</th><th>Recomps they play</th><th>Why</th><th>Applied (UTC)</th></tr></thead><tbody>
  <?php foreach ($apps as $r): ?>
    <tr><td><input type="checkbox" name="ids[]" value="<?= (int)$r['account_id'] ?>"></td>
      <td><?= h((string)$r['username']) ?><?= $r['discord'] !== '' ? '<br><span class="muted small">Discord: ' . h($r['discord']) . '</span>' : '' ?><br><span class="muted small">Account since <?= h(substr((string)$r['created'], 0, 10)) ?></span></td>
      <td><?= h($osLabel[$r['os']] ?? $r['os']) ?></td><td class="small"><?= h($r['plays']) ?></td><td class="small"><?= h($r['why']) ?></td>
      <td class="small"><?= h($r['applied']) ?><?= $r['who'] !== '' ? '<br><span class="muted">' . h($r['who']) . '</span>' : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <div class="actions">
    <?php if ($appView !== 'approved'): ?><button class="btn primary" name="to" value="approved">Approve the ticked ones</button><?php endif; ?>
    <?php if ($appView !== 'declined'): ?><button class="btn danger" name="to" value="declined">Decline the ticked ones</button><?php endif; ?>
    <?php if ($appView !== 'pending'): ?><button class="btn" name="to" value="pending">Back to waiting</button><?php endif; ?>
  </div></form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Test accounts</h2>
  <p class="muted small">Always testers, without applying (you and the team). These accounts also get the launcher's dev tools.</p>
  <form method="post"><?= $csrf ?><input type="hidden" name="action" value="testers">
    <label>RecompLauncher usernames, separated by commas<textarea name="testers" rows="3" class="mono" spellcheck="false"><?= h(implode(', ', $testers)) ?></textarea></label>
    <div class="actions"><button class="btn">Save</button></div>
  </form>
</section>

<section class="card" id="review">
  <h2>To review</h2>
  <?php if (!$review): ?><p class="muted">Nothing to look at.</p><?php else: ?>
  <table class="ac-table"><thead><tr><th>When (UTC)</th><th>Player</th><th>Recomp</th><th>Why</th><th></th></tr></thead><tbody>
  <?php foreach ($review as $r): ?>
    <tr><td class="small"><?= h($r['at']) ?></td><td><?= h((string)$r['username']) ?></td><td><?= h($r['game']) ?></td><td><?= h($r['why']) ?></td>
      <td><form method="post"><?= $csrf ?><input type="hidden" name="action" value="review_done"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn">Done</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Latest unlocks</h2>
  <?php if (!$recent): ?><p class="muted">None yet.</p><?php else: ?>
  <table class="ac-table"><thead><tr><th>When (UTC)</th><th>Player</th><th>Recomp</th><th>#</th><th>From</th><th>Counts as</th></tr></thead><tbody>
  <?php foreach ($recent as $r): ?>
    <tr><td class="small"><?= h($r['at']) ?></td><td><?= h((string)$r['username']) ?></td><td><?= h($r['game']) ?></td><td><?= (int)$r['ach'] ?></td><td><?= h($r['source']) ?></td>
      <td><?= (int)$r['ranked'] ? 'Ranked' : 'Casual' . ($r['reason'] !== '' ? ' (' . h($reasonLabel[$r['reason']] ?? $r['reason']) . ')' : '') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</section>
<?php rs_foot();
