<?php
// RecompStore admin (4.10): PC recomps > Emergency backup. Which games keep a copy of their current
// release on Backblaze, for which systems, and the switch to that copy when a repo goes down.
// See inc/backup.php.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/backup.php';
require_once __DIR__ . '/inc/repowatch.php';
rs_require_login();
rs_bk_tables();
$cfg = rs_bk_cfg();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $ids = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['ids'] ?? ''))), fn($x) => preg_match('/^[a-z0-9-]{1,100}$/', $x)));
    $os = array_values(array_filter((array)($_POST['os'] ?? []), 'is_string'));
    try {
        if ($act === 'settings') {
            rs_bk_save_cfg(['os' => array_map(fn($k) => in_array($k, $os, true), array_combine(array_keys(RS_BK_OS), array_keys(RS_BK_OS))),
                'auto' => !empty($_POST['auto']), 'pick' => (string)($_POST['pick'] ?? 'hand'), 'top' => (int)($_POST['top'] ?? 100), 'max_mb' => (int)($_POST['max_mb'] ?? 4000)]);
            if (!array_filter(rs_bk_cfg()['os'])) throw new RuntimeException('Pick at least one system.');
            $n = rs_bk_auto_pick();
            rs_log('backup', 'settings', 'saved');
            rs_flash('Saved.' . ($n ? ' ' . $n . ' more games are tracked now; they\'re copied on the next run.' : ''));
        } elseif ($act === 'track') {
            if (!$ids) throw new RuntimeException('Tick some games first.');
            if (!$os) throw new RuntimeException('Pick at least one system.');
            $n = rs_bk_track($ids, $os);
            $go = rs_bk_launch();
            rs_log('backup', 'track', $n . ' games');
            rs_flash($n . ' ' . ($n === 1 ? 'game' : 'games') . ' in line to be backed up' . ($go ? '. Copying has started.' : '; the run that\'s going picks them up.'));
        } elseif ($act === 'untrack') {
            $n = rs_bk_untrack($ids);
            rs_flash($n . ' stopped. Their copies stay until you remove them.');
        } elseif ($act === 'remove') {
            $n = rs_bk_remove($ids);
            rs_log('backup', 'remove', $n . ' games');
            rs_flash($n . ' ' . ($n === 1 ? 'backup' : 'backups') . ' deleted.' . ($n < count($ids) ? ' Games switched to their backup were left alone.' : ''));
        } elseif ($act === 'run') {
            rs_flash(rs_bk_launch() ? 'Copying started. This page shows how it\'s going.' : 'A run is already going.');
        } elseif ($act === 'activate' && $ids) {
            rs_flash(rs_bk_activate($ids[0]));
        } elseif ($act === 'deactivate' && $ids) {
            rs_flash(rs_bk_deactivate($ids[0]));
        }
    } catch (Throwable $e) { rs_flash($e->getMessage(), 'err'); }
    header('Location: backup.php');
    exit;
}

$rows = rs_bk_rows();
$pl = rs_pc_players_map();
$watch = [];
foreach (rs_db()->query("SELECT app_id, state FROM repo_watch WHERE store = 'pc'") as $w) $watch[(string)$w['app_id']] = (string)$w['state'];
$sizeOf = function (string $s): int {
    return preg_match('/([0-9.]+)\s*(KB|MB|GB)/i', $s, $m) ? (int)((float)$m[1] * ['KB' => 1e3, 'MB' => 1e6, 'GB' => 1e9][strtoupper($m[2])]) : 0;
};
$list = []; $allBytes = 0;
foreach (rs_pc_apps(true) as $a) {
    $id = (string)$a['id'];
    $r = $rows[$id] ?? null;
    if (!empty($a['soon']) || (!empty($a['hidden']) && !$r)) continue;
    $av = rs_bk_avail($a);
    if (!$av && !$r) continue;
    $sz = $sizeOf((string)$a['size']);
    if (empty($a['hidden'])) $allBytes += $sz;
    $list[] = ['id' => $id, 'n' => (string)$a['name'], 'ic' => (string)$a['icon'] !== '' ? rs_abs((string)$a['icon']) : '', 'pl' => (int)($pl[$id] ?? 0),
        'sz' => $sz, 'av' => $av, 'lv' => (string)$a['version'], 'w' => $watch[$id] ?? '', 'hid' => !empty($a['hidden']),
        'r' => $r ? ['t' => (int)$r['tracked'], 'p' => (string)$r['picked'], 'os' => rs_bk_os_for($r, $cfg), 'own' => (string)$r['os'] !== '', 'st' => (string)$r['status'],
            'v' => (string)$r['version'], 'b' => (int)$r['bytes'], 'at' => (string)$r['backed_at'], 'e' => (string)$r['err'], 'a' => (int)$r['active'],
            'have' => array_values(array_unique(array_column($r['files'], 'os'))), 'srv' => (int)$r['served']] : null];
}
$tot = rs_bk_totals();
$running = rs_bk_running();
$last = rs_bk_last();
$now = $running ? (string)(rs_kv_get('bk_now')['v'] ?? '') : '';
$queue = count(rs_bk_queue());
$gb = fn(int $b): string => $b >= 1e9 ? number_format($b / 1e9, 1) . ' GB' : number_format($b / 1e6) . ' MB';
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$b2 = rs_b2_on();

rs_head('Emergency backup', true, ['assets/backup.css']);
?>
<div class="bk-head">
  <div>
    <h1>Emergency backup <span class="<?= $b2 ? 'pill ok' : 'pill warn' ?>"><?= $b2 ? 'Backblaze connected' : 'Backblaze not set up' ?></span></h1>
    <p class="muted">A copy of each tracked game's current release, kept on Backblaze with the cloud saves. A new update replaces it once it's live. Players only get it if a repo goes down and you switch that game to its backup, from here or from the repo watch on Submissions. Never for a takedown.</p>
  </div>
  <form method="post"><?= $csrf ?><button name="action" value="run" class="primary"<?= $running || !$b2 ? ' disabled' : '' ?>><?= $running ? 'Copying…' : 'Back up now' ?></button></form>
</div>

<div class="stats-tiles bk-tiles">
  <div><strong><?= number_format($tot['backed']) ?></strong><span>backed up of <?= number_format($tot['tracked']) ?> tracked</span></div>
  <div><strong><?= h($gb($tot['bytes'])) ?></strong><span>stored · about $<?= number_format(max(0, $tot['bytes'] - 1e10) / 1e12 * RS_BK_TB_USD, 2) ?> a month</span></div>
  <div><strong><?= number_format($queue) ?></strong><span><?= $running ? 'waiting · copying now' : 'waiting' ?><?= !$running && !empty($last['at']) ? ' · last run ' . h(rs_date('M j, g:i a', (int)$last['at'])) : '' ?></span></div>
  <div><strong><?= number_format($tot['active']) ?></strong><span>on their backup now<?= $tot['errors'] ? ' · ' . $tot['errors'] . ' failed' : '' ?></span></div>
</div>
<?php if ($running && $now !== ''): ?><p class="bk-now"><span class="bk-spin"></span><?= h($now) ?></p><?php endif; ?>
<?php if (!empty($last['err'])): ?><p class="bk-now bk-err"><?= h($last['err']) ?></p><?php endif; ?>

<form method="post" class="card bk-set">
  <?= $csrf ?>
  <div class="bk-set-grid">
    <fieldset>
      <legend>Systems to keep</legend>
      <div class="bk-chips">
        <?php foreach (RS_BK_OS as $k => $n): ?><label class="bk-chip"><input type="checkbox" name="os[]" value="<?= $k ?>"<?= $cfg['os'][$k] ? ' checked' : '' ?>><span><?= $n ?></span></label><?php endforeach; ?>
      </div>
      <small class="muted">The default for games you track. A batch can pick its own below.</small>
    </fieldset>
    <fieldset>
      <legend>Which games</legend>
      <select name="pick">
        <option value="hand"<?= $cfg['pick'] === 'hand' ? ' selected' : '' ?>>Only the ones I pick</option>
        <option value="top"<?= $cfg['pick'] === 'top' ? ' selected' : '' ?>>The most played, plus my picks</option>
        <option value="all"<?= $cfg['pick'] === 'all' ? ' selected' : '' ?>>Every listed game</option>
      </select>
      <label class="bk-inline">Most played: top <input type="number" name="top" min="10" max="1000" value="<?= $cfg['top'] ?>" class="bk-num"></label>
    </fieldset>
    <fieldset>
      <legend>Updates</legend>
      <label class="bk-check"><input type="checkbox" name="auto" value="1"<?= $cfg['auto'] ? ' checked' : '' ?>> Back up each new update once it's live (the old copy is replaced)</label>
      <label class="bk-inline">Skip files over <input type="number" name="max_mb" min="100" max="8000" step="100" value="<?= $cfg['max_mb'] ?>" class="bk-num"> MB</label>
    </fieldset>
  </div>
  <div class="bk-set-foot"><small class="muted">All <?= number_format(count(array_filter($list, fn($x) => !$x['hid']))) ?> listed games come to about <?= h($gb($allBytes)) ?> for Windows alone (about $<?= number_format($allBytes / 1e12 * RS_BK_TB_USD, 2) ?> a month).</small><button name="action" value="settings">Save</button></div>
</form>

<section class="card bk-list">
  <div class="bk-tools">
    <input type="search" id="bk-q" placeholder="Search games">
    <select id="bk-f">
      <option value="all">All games</option>
      <option value="ok">Backed up</option>
      <option value="none">Not backed up</option>
      <option value="tracked">Tracked</option>
      <option value="old">Update waiting</option>
      <option value="err">Failed</option>
      <option value="down">Repo down</option>
      <option value="on">On their backup</option>
    </select>
    <select id="bk-s">
      <option value="pop">Most played</option>
      <option value="az">A to Z</option>
      <option value="big">Biggest</option>
      <option value="new">Backed up lately</option>
    </select>
    <span class="muted bk-count" id="bk-count"></span>
  </div>
  <form method="post" class="bk-bulk" id="bk-bulk" hidden>
    <?= $csrf ?>
    <input type="hidden" name="ids" id="bk-ids">
    <b id="bk-sel">0 picked</b>
    <span class="bk-chips">
      <?php foreach (RS_BK_OS as $k => $n): ?><label class="bk-chip"><input type="checkbox" name="os[]" value="<?= $k ?>"<?= $cfg['os'][$k] ? ' checked' : '' ?>><span><?= $n ?></span></label><?php endforeach; ?>
    </span>
    <span class="bk-bulk-acts">
      <button name="action" value="track" class="primary">Back these up</button>
      <button name="action" value="untrack">Stop tracking</button>
      <button name="action" value="remove" class="bk-danger" data-confirm="Delete their copies?">Delete backups</button>
      <button type="button" id="bk-clear">Clear</button>
    </span>
  </form>
  <div class="bk-table" role="table">
    <div class="bk-tr bk-th" role="row"><label class="bk-c-ck"><input type="checkbox" id="bk-all" title="Pick everything shown"></label><span>Game</span><span>Systems</span><span>Size</span><span>Backup</span><span></span></div>
    <div id="bk-rows"></div>
  </div>
  <button type="button" class="bk-more" id="bk-more" hidden>Show more</button>
</section>
<form method="post" id="bk-one" hidden><?= $csrf ?><input type="hidden" name="action"><input type="hidden" name="ids"></form>
<script type="application/json" id="bk-data"><?= json_encode(['list' => $list, 'os' => RS_BK_OS, 'down' => array_keys(array_filter(RS_WATCH_STATES, fn($x) => $x[0] === 'bad')), 'takedown' => RS_BK_NO_TAKEDOWN], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php rs_foot(['assets/backup.js']); ?>
