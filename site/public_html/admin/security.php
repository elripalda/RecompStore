<?php
// (3.8) Security: VirusTotal checks of every download the stores point at, updates held until
// they're checked, and "Check a link" for anything you're thinking of adding.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_vt_tables();

$json = function (array $j): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($j, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

// ---- The link checker's answers (assets/vt.js) ----
$a = (string)($_GET['a'] ?? '');
if ($a === 'check' || $a === 'poll') {
    session_write_close();
    if (!rs_vt_on()) $json(['ok' => false, 'error' => 'Add your VirusTotal key below first.']);
    if ($a === 'check') {
        $r = rs_vt_check_link((string)($_GET['url'] ?? ''));
        if (empty($r['ok'])) $json($r);
    } else {
        $r = ['ok' => true, 'repo' => (string)($_GET['repo'] ?? ''), 'tag' => (string)($_GET['tag'] ?? ''), 'files' => array_map('strval', (array)($_GET['f'] ?? []))];
    }
    rs_vt_run(8); // its files go first in line
    $r['rows'] = rs_vt_rows($r['repo'], $r['tag'], $r['files']);
    $r['done'] = !array_filter($r['rows'], fn($x) => in_array($x['status'], ['new', 'upload', 'analyzing'], true));
    $json($r);
}

$byId = fn(string $id) => rs_pc_app($id);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'key') {
            $k = trim((string)($_POST['vt_key'] ?? ''));
            if ($k !== '') rs_secret_put('vt_key', $k);
            $why = rs_vt_test();
            rs_flash($why === '' ? 'VirusTotal is connected. Checks start on their own.' : $why, $why === '' ? 'ok' : 'err');
        } elseif ($act === 'forget') {
            rs_secret_put('vt_key', '');
            rs_flash('The key is gone. Nothing is checked until you add one again.');
        } elseif ($act === 'settings') {
            rs_set_setting('vt_hold_mins', (string)max(0, min(10080, (int)($_POST['hold'] ?? 30))));
            rs_set_setting('vt_hold_verified_mins', (string)max(0, min(10080, (int)($_POST['hold_verified'] ?? 0))));
            rs_set_setting('vt_red_at', (string)max(1, min(20, (int)($_POST['red_at'] ?? 3))));
            rs_set_setting('vt_auto', !empty($_POST['auto']) ? '1' : '0');
            rs_set_setting('vt_clam_subs', !empty($_POST['clam_subs']) ? '1' : '0');
            rs_flash('Saved.', 'ok');
        } elseif ($act === 'approve' || $act === 'block') {
            $app = $byId((string)($_POST['id'] ?? ''));
            if (!$app || empty($app['pending']['tag'])) throw new RuntimeException('That update isn\'t waiting any more.');
            if ($act === 'approve') { rs_vt_release_go($app, 'approved'); rs_flash($app['name'] . ' ' . ($app['pending']['version'] ?: $app['pending']['tag']) . ' is out.', 'ok'); }
            else { rs_vt_block($app); rs_flash($app['name'] . ' stays on the version before. That release won\'t be offered.', 'ok'); }
            rs_db()->prepare('UPDATE vt_files SET seen = 1 WHERE repo = ? AND tag = ?')->execute([rs_pc_key($app), $app['pending']['tag']]);
        } elseif ($act === 'release-clean') {
            // (4.1) Every held update that's checked and clean (green or yellow) goes out now, without waiting out its hold.
            $out = [];
            foreach (rs_pc_apps() as $x) {
                if (empty($x['pending']['tag'])) continue;
                [, $v] = rs_vt_release(rs_pc_key($x), (string)$x['pending']['tag']);
                if ($v !== 'green' && $v !== 'yellow') continue;
                $tag = (string)$x['pending']['tag'];
                rs_vt_release_go($x, 'approved (all clean)');
                rs_db()->prepare('UPDATE vt_files SET seen = 1 WHERE repo = ? AND tag = ?')->execute([rs_pc_key($x), $tag]);
                $out[] = $x['name'];
            }
            rs_flash($out ? count($out) . ' out: ' . implode(', ', $out) . '.' : 'Nothing clean was waiting.', 'ok');
        } elseif ($act === 'seen') {
            rs_db()->prepare('UPDATE vt_files SET seen = 1 WHERE repo = ? AND tag = ?')->execute([(string)($_POST['repo'] ?? ''), (string)($_POST['tag'] ?? '')]);
            rs_flash('Marked as seen.', 'ok');
        } elseif ($act === 'recheck') {
            rs_db()->prepare("UPDATE vt_files SET status = 'new', tries = 0, err = '', next_at = NULL, prio = LEAST(prio, 2) WHERE id = ?")->execute([(int)($_POST['fid'] ?? 0)]);
            rs_flash('It\'s back in line.', 'ok');
        } elseif ($act === 'clear-line') {
            // (3.8) Empties the line. Held updates and waiting submissions come back on their own
            // (one file each), so only what still matters gets checked.
            $n = rs_db()->exec("DELETE FROM vt_files WHERE status IN ('new', 'upload', 'analyzing')");
            rs_kv_put('vt_discover', '0'); // looked at again on the next turn
            rs_flash('Cleared ' . (int)$n . ' from the line. Updates still on hold and waiting submissions come back in by themselves.', 'ok');
        } elseif ($act === 'clear-list') {
            rs_set_setting('vt_list_from', gmdate('Y-m-d H:i:s'));
            rs_flash('Latest checks cleared. New ones show up here as they finish.', 'ok');
        } elseif ($act === 'run') {
            session_write_close();
            rs_vt_discover(true);
            $n = rs_vt_run(25);
            rs_flash($n ? $n . ' checks done.' : 'Nothing could be checked right now: the line is empty, or VirusTotal\'s per-minute limit is used up.', 'ok');
        }
    } catch (Throwable $e) { rs_flash($e->getMessage(), 'err'); }
    header('Location: security.php' . (($_POST['back'] ?? '') === 'settings' ? '#settings' : ''));
    exit;
}

$on = rs_vt_on();
$cfg = rs_vt_cfg();
$db = rs_db();
$waiting = (int)$db->query("SELECT COUNT(*) FROM vt_files WHERE status IN ('new', 'upload', 'analyzing')")->fetchColumn();
$doneToday = (int)$db->query("SELECT COUNT(*) FROM vt_files WHERE status = 'done' AND updated > DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
$total = (int)$db->query("SELECT COUNT(*) FROM vt_files WHERE status = 'done'")->fetchColumn();
$red = $db->prepare("SELECT * FROM vt_files WHERE status = 'done' AND malicious >= ? AND seen = 0 AND source IN ('update', 'submission', 'store') ORDER BY prio, updated DESC LIMIT 40");
$red->execute([$cfg['red_at']]);
$red = $red->fetchAll();
$held = array_values(array_filter(rs_pc_apps(), fn($x) => !empty($x['pending']['tag'])));
$listFrom = (string)rs_setting('vt_list_from', '');
$recent = $db->prepare("SELECT * FROM vt_files WHERE (status <> 'new' OR source IN ('submission', 'update', 'link')) AND updated > ? ORDER BY updated DESC LIMIT 40");
$recent->execute([$listFrom !== '' ? $listFrom : '2000-01-01']);
$recent = $recent->fetchAll();
$clam = rs_clam_on();
$names = [];
foreach (rs_pc_apps() as $x) $names['pc:' . $x['id']] = $x['name'];
foreach (rs_games() as $g) $names['ps5:' . $g['id']] = $g['name'];
$srcLabel = ['link' => 'Link', 'submission' => 'Submission', 'update' => 'Update', 'store' => 'In the store', 'finder' => 'GitHub find'];
$stLabel = ['new' => 'In line', 'upload' => 'Sending', 'analyzing' => 'Scanning', 'big' => 'Too big to send', 'error' => 'Couldn\'t check'];
$when = function (string $t): string {
    $s = time() - (int)strtotime($t . ' UTC');
    return $s < 90 ? 'just now' : ($s < 3600 ? round($s / 60) . ' min ago' : ($s < 86400 ? round($s / 3600) . ' h ago' : round($s / 86400) . ' d ago'));
};
$fileBadge = function (array $f) use ($stLabel): string {
    $v = rs_vt_verdict($f);
    if ($f['status'] !== 'done') return '<span class="vt-badge grey"><span class="vt-dot">…</span>' . h($stLabel[$f['status']] ?? $f['status']) . '</span>';
    if (($f['scanner'] ?? 'vt') === 'clamav') return rs_vt_badge_html([$v, $v === 'green' ? 'Clean · ClamAV' : 'ClamAV found something', '']);
    $txt = $v === 'green' ? 'Clean' : (((int)$f['malicious'] + (int)$f['suspicious']) . ' of ' . (int)$f['engines']);
    return rs_vt_badge_html([$v, $txt . ($v === 'green' && $f['engines'] ? ' · 0 of ' . (int)$f['engines'] : ''), rs_vt_link($f)]);
};

rs_head('Security', true, ['assets/vt.css']);
?>
<section class="head-row">
  <div>
    <h1>Security</h1>
    <p class="muted">New downloads are checked with VirusTotal: submissions as they arrive, and new releases of the games in the stores. A new release from a developer who isn't verified waits until it's checked. Paste any link below to check it.</p>
  </div>
  <div class="vt-state <?= $on ? 'on' : '' ?>"><i></i><?= $on ? 'VirusTotal connected · ' . max(0, RS_VT_PER_DAY - rs_vt_today()) . ' checks left today' : 'VirusTotal not connected' ?></div>
</section>

<?php if (!$on && !rs_is_owner()): ?>
<section class="card"><h2>VirusTotal isn't connected yet</h2><p class="muted">The owner connects it. Until then, nothing new is checked.</p></section>
<?php elseif (!$on): ?>
<section class="card vt-setup">
  <h2>Connect VirusTotal</h2>
  <ol class="vt-steps">
    <li><span>1</span><div><strong>Make a free account</strong><small>At <a href="https://www.virustotal.com/gui/join-us" target="_blank" rel="noopener">virustotal.com</a>. Use an email you check.</small></div></li>
    <li><span>2</span><div><strong>Copy your API key</strong><small>Click your name at the top right, then API key, and copy it.</small></div></li>
    <li><span>3</span><div><strong>Paste it here</strong><small>It's locked away like the site's other passwords. Only its end is ever shown.</small></div></li>
  </ol>
  <form method="post" class="vt-keyform"><?= '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">' ?><input type="hidden" name="action" value="key">
    <input type="text" name="vt_key" placeholder="Paste the VirusTotal API key" autocomplete="off" spellcheck="false" data-lpignore="true" data-1p-ignore style="-webkit-text-security:disc" required>
    <button class="primary">Connect</button></form>
</section>
<?php endif; ?>

<section class="card vt-check">
  <h2>Check a link</h2>
  <p class="muted">A GitHub repo, a GitLab project, a release page or a link to a file. You get its rundown and a VirusTotal result for each download, usually within a minute.</p>
  <form class="vt-checkform" data-vt-check>
    <input type="url" name="url" placeholder="https://github.com/owner/repo, https://gitlab.com/group/project or https://sourceforge.net/projects/name" required autocomplete="off" spellcheck="false">
    <button class="primary">Check it</button>
  </form>
  <div class="vt-result" data-vt-result hidden></div>
</section>

<section class="vt-tiles">
  <div class="card vt-tile <?= $red ? 'red' : '' ?>"><span>Needs you</span><b><?= count($red) ?></b><small><?= $red ? 'flagged by ' . $cfg['red_at'] . '+ engines' : 'nothing flagged' ?></small></div>
  <div class="card vt-tile"><span>Updates on hold</span><b><?= count($held) ?></b><small><?= rs_vt_mins_text($cfg['hold']) ?> for unverified developers</small></div>
  <div class="card vt-tile"><span>In line</span><b><?= $waiting ?></b><small>files waiting for a check</small></div>
  <div class="card vt-tile"><span>Checked</span><b><?= number_format($total) ?></b><small><?= $doneToday ?> in the last day</small></div>
</section>

<section class="card">
  <div class="vt-head"><h2>Needs you</h2></div>
  <?php if (!$red): ?><p class="vt-empty">Nothing needs you. Anything several engines flag shows up here, and its update waits.</p><?php else: ?>
  <div class="vt-list">
    <?php foreach ($red as $f): $app = str_starts_with($f['ref'], 'pc:') ? rs_pc_app(substr($f['ref'], 3)) : null; $isHeld = $app && (string)($app['pending']['tag'] ?? '') === $f['tag']; ?>
    <div class="vt-row red">
      <div class="vt-what"><strong><?= h($names[$f['ref']] ?? ($f['repo'] ?: $f['name'])) ?></strong><small><?= h($f['repo']) ?> · <?= h($f['tag']) ?> · <?= h($f['name']) ?></small>
        <?php if ($f['flagged_by'] !== ''): ?><small class="vt-engines"><?= h($f['flagged_by']) ?></small><?php endif; ?></div>
      <div class="vt-src"><span class="vt-chip"><?= h($srcLabel[$f['source']] ?? $f['source']) ?></span></div>
      <div class="vt-res"><?= $fileBadge($f) ?></div>
      <div class="vt-acts">
        <?php if ($isHeld): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= h($app['id']) ?>"><button>Approve</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="block"><input type="hidden" name="id" value="<?= h($app['id']) ?>"><button class="danger">Block</button></form>
        <?php elseif ($f['source'] === 'submission'): ?><a class="button" href="submissions.php#<?= h($f['ref']) ?>">Open submission</a><?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="seen"><input type="hidden" name="repo" value="<?= h($f['repo']) ?>"><input type="hidden" name="tag" value="<?= h($f['tag']) ?>"><button class="ghost">Mark seen</button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="card">
  <?php $clean = array_filter($held, function ($x) { [, $v] = rs_vt_release(rs_pc_key($x), (string)$x['pending']['tag']); return $v === 'green' || $v === 'yellow'; }); ?>
  <div class="vt-head"><h2>Updates on hold</h2>
    <?php if ($clean): ?><div class="vt-acts"><form method="post" onsubmit="return confirm('Let out all <?= count($clean) ?> clean updates now?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="release-clean"><button>Let out all clean (<?= count($clean) ?>)</button></form></div><?php endif; ?></div>
  <?php if (!$held): ?><p class="vt-empty">No updates waiting. New releases from developers who aren't verified wait here until they're checked<?= $cfg['hold'] ? ' and ' . rs_vt_mins_text($cfg['hold']) . ' has passed' : '' ?>.</p><?php else: ?>
  <div class="vt-list">
    <?php foreach ($held as $x): $p = $x['pending']; [$rows, $v] = rs_vt_release(rs_pc_key($x), (string)$p['tag']);
      $hrs = rs_vt_trusted($x) ? $cfg['hold_verified'] : $cfg['hold']; $left = max(0, (int)strtotime((string)$p['since']) + $hrs * 60 - time()); ?>
    <div class="vt-row <?= $v ?>">
      <div class="vt-what"><strong><?= h($x['name']) ?></strong><small><?= h($x['version'] ?: '?') ?> → <?= h($p['version'] ?: $p['tag']) ?> · <?= rs_vt_trusted($x) ? 'verified developer' : 'not verified' ?></small></div>
      <div class="vt-src"><span class="vt-chip"><?= $v === 'red' ? 'Waiting for you' : ($v === 'grey' ? 'Being checked' : ($left ? 'Out in ' . rs_vt_mins_text(max(1, (int)ceil($left / 60))) : 'Going out')) ?></span></div>
      <div class="vt-res"><?= rs_vt_badge_html(rs_vt_repo_badge(rs_pc_key($x), (string)$p['tag'])) ?: '<span class="vt-badge grey"><span class="vt-dot">…</span>In line</span>' ?></div>
      <div class="vt-acts">
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= h($x['id']) ?>"><button>Let it out now</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="block"><input type="hidden" name="id" value="<?= h($x['id']) ?>"><button class="danger">Block</button></form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<section class="card">
  <div class="vt-head"><h2>Latest checks</h2>
    <div class="vt-acts">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="run"><button<?= $on ? '' : ' disabled' ?>>Check now</button></form>
      <form method="post" onsubmit="return confirm('Clear the list? The checks themselves are kept.');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="clear-list"><button class="ghost">Clear list</button></form>
      <form method="post" onsubmit="return confirm('Empty the line of <?= (int)$waiting ?> files waiting to be checked? Held updates and waiting submissions come back in by themselves.');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="clear-line"><button class="ghost"<?= $waiting ? '' : ' disabled' ?>>Clear the line (<?= (int)$waiting ?>)</button></form>
    </div></div>
  <?php if (!$recent): ?><p class="vt-empty">Nothing checked yet. <?= $on ? 'The first checks start within a few minutes.' : 'Connect VirusTotal above to start.' ?></p><?php else: ?>
  <div class="vt-list compact">
    <?php foreach ($recent as $f): ?>
    <div class="vt-row <?= rs_vt_verdict($f) ?>">
      <div class="vt-what"><strong><?= h($names[$f['ref']] ?? ($f['repo'] ?: $f['name'])) ?></strong><small><?= h($f['name']) ?><?= $f['size'] ? ' · ' . h(rs_human_size((int)$f['size'])) : '' ?> · <?= h($f['tag']) ?></small>
        <?php if ($f['err'] !== '' && $f['status'] !== 'done'): ?><small class="vt-err"><?= h($f['err']) ?></small><?php endif; ?></div>
      <div class="vt-src"><span class="vt-chip"><?= h($srcLabel[$f['source']] ?? $f['source']) ?></span><small class="muted"><?= h($when((string)$f['updated'])) ?></small></div>
      <div class="vt-res"><?= $fileBadge($f) ?></div>
      <div class="vt-acts"><?php if (in_array($f['status'], ['error', 'big', 'done'], true)): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="recheck"><input type="hidden" name="fid" value="<?= (int)$f['id'] ?>"><button class="ghost">Check again</button></form><?php endif; ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php if (rs_is_owner()): ?>
<section class="card" id="settings">
  <h2>Settings</h2>
  <form method="post" class="vt-settings"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="settings"><input type="hidden" name="back" value="settings">
    <label><span>Hold for developers who aren't verified</span><span class="vt-num"><input type="number" name="hold" min="0" max="10080" step="5" value="<?= $cfg['hold'] ?>"> minutes</span><small>How long a new release waits after it's checked and clean.</small></label>
    <label><span>Hold for verified developers</span><span class="vt-num"><input type="number" name="hold_verified" min="0" max="10080" step="5" value="<?= $cfg['hold_verified'] ?>"> minutes</span><small>0: out as soon as it's checked.</small></label>
    <label><span>Hold for you when flagged by</span><span class="vt-num"><input type="number" name="red_at" min="1" max="20" value="<?= $cfg['red_at'] ?>"> engines</span><small>Unsigned programs often get 1 or 2 flags that mean nothing. Those show yellow and don't hold anything.</small></label>
    <label class="vt-toggle"><input type="checkbox" name="auto" value="1"<?= $cfg['auto'] ? ' checked' : '' ?>><span><strong>Let clean updates out on their own</strong><small>Off: every held update waits for Let it out now.</small></span></label>
    <label class="vt-toggle"><input type="checkbox" name="clam_subs" value="1"<?= $cfg['clam_subs'] ? ' checked' : '' ?><?= $clam ? '' : ' disabled' ?>><span><strong>ClamAV for submissions too</strong><small>When the day's VirusTotal checks run out, submissions and pasted links are checked by ClamAV instead of waiting for tomorrow.</small></span></label>
    <div class="vt-save"><button class="primary">Save</button></div>
  </form>
  <p class="vt-line"><span class="vt-state<?= $clam ? ' on' : '' ?>"><i></i><?= $clam ? 'ClamAV is on' : 'ClamAV isn\'t installed' ?></span><small class="muted"><?= $clam ? 'Updates and GitHub finds VirusTotal doesn\'t know yet, or that come in after the day\'s VirusTotal requests are used up, are checked by ClamAV on this server instead' . ($cfg['clam_subs'] ? ', and so are submissions' : '') . '. No daily limit.' : 'Install it on the server (clamav-daemon) and updates get checked here when VirusTotal is busy.' ?></small></p>
  <?php if ($on): ?>
  <form method="post" class="vt-keyform"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="key"><input type="hidden" name="back" value="settings">
    <input type="text" name="vt_key" placeholder="Saved. Paste a new key to replace it." autocomplete="off" spellcheck="false" data-lpignore="true" data-1p-ignore style="-webkit-text-security:disc">
    <button>Save and test</button></form>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php rs_foot(['assets/vt.js']);
