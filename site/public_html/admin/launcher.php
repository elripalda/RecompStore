<?php
// RecompStore admin (3.2): RecompLauncher itself. Who's using it and on what (from the launcher's
// usage data), what goes wrong (installs that failed, games that crashed), its betas of PC recomps,
// and its own updates (the version the launcher's update notice offers).
// (3.3) Launch: the beta going out on recomplauncher.com/get. The build, the page's words, a preview
// link, the switch that makes it live, and the email to the beta list.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/rl_cloud.php';
require_once __DIR__ . '/inc/rl_launch.php';
rs_require_login();
rs_rl_more_tables();
$db = rs_db();
$view = (string)($_GET['view'] ?? 'launch');
if (!in_array($view, ['launch', 'players', 'problems', 'betas', 'updates'], true)) $view = 'launch';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    rs_check_csrf();
    $back = 'launcher.php?view=' . $view;
    try {
        switch ((string)($_POST['action'] ?? '')) {
            case 'publish':
                $v = rs_rl_publish(trim((string)($_POST['version'] ?? '')), (string)($_POST['file'] ?? ''), (string)($_POST['notes'] ?? ''));
                rs_flash('RecompLauncher ' . $v['version'] . ' is out. Launchers offer it the next time they check (within a few hours, or when they open).');
                break;
            case 'upload':
                $f = $_FILES['installer'] ?? null;
                if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new RuntimeException('The installer didn\'t arrive. Try again.');
                $name = basename((string)$f['name']);
                if (!preg_match('/^RecompLauncher_[0-9A-Za-z._-]+_x64-setup\.exe$/', $name)) throw new RuntimeException('Use the installer as it was built: RecompLauncher_<version>_x64-setup.exe');
                if (!is_dir(RS_PUBLIC . '/launcher')) mkdir(RS_PUBLIC . '/launcher', 0755, true);
                if (!move_uploaded_file($f['tmp_name'], RS_PUBLIC . '/launcher/' . $name)) throw new RuntimeException('It couldn\'t be saved. Try again.');
                rs_flash('Uploaded ' . $name . '. Publish it below when it\'s ready.');
                break;
            case 'launch-save':
                rs_rl_launch_page_save($_POST);
                rs_flash('Saved. Open the preview to see it.');
                break;
            case 'launch-reset':
                rs_set_setting('rl_launch_page', '');
                rs_flash('Back to the starting words.');
                break;
            case 'launch-live':
                if (($_POST['on'] ?? '') === '1') {
                    if (!rs_rl_version()) throw new RuntimeException('Publish a version first: that\'s what the page downloads.');
                    rs_rl_launch_set_live(true);
                    rs_flash('It\'s live. recomplauncher.com/get has the download now, and the pop-up says the beta is out.');
                } else {
                    rs_rl_launch_set_live(false);
                    rs_flash('Back to the sign-up. The download is off the Get page.');
                }
                break;
            case 'launch-key':
                rs_set_setting('rl_launch_key', '');
                rs_rl_launch_key();
                rs_flash('New preview link made. The old one stops working.');
                break;
            case 'beta-save': {
                $id = (int)($_POST['id'] ?? 0);
                $app = (string)($_POST['app'] ?? '');
                if (!rs_rl_app($app)) throw new RuntimeException('Pick a recomp.');
                $channel = ($_POST['channel'] ?? '') === 'tag' ? 'tag' : 'prerelease';
                $tag = trim((string)($_POST['tag'] ?? ''));
                if ($channel === 'tag' && $tag === '') throw new RuntimeException('Give the release tag, like v1.3.0-beta2.');
                $access = isset(RS_RL_ACCESS[$_POST['access'] ?? '']) ? (string)$_POST['access'] : 'open';
                $f = [$app, mb_substr(trim((string)($_POST['title'] ?? '')), 0, 80), mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 4000), $channel, mb_substr($tag, 0, 80), $access, empty($_POST['active']) ? 0 : 1];
                if ($id) $db->prepare('UPDATE pc_betas SET app = ?, title = ?, notes = ?, channel = ?, tag = ?, access = ?, active = ? WHERE id = ?')->execute([...$f, $id]);
                else $db->prepare('INSERT INTO pc_betas (app, title, notes, channel, tag, access, active, created) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute($f);
                rs_flash('Saved.');
                break;
            }
            case 'member': {
                $beta = (int)$_POST['beta'];
                $status = in_array($_POST['status'] ?? '', ['in', 'out'], true) ? (string)$_POST['status'] : 'in';
                $acc = (int)($_POST['account'] ?? 0);
                if (!$acc && ($u = trim((string)($_POST['username'] ?? ''))) !== '') {
                    $a = rs_acct_by('username_lc', mb_strtolower($u));
                    if (!$a) throw new RuntimeException('No account is called ' . $u . '.');
                    $acc = (int)$a['id'];
                }
                if (!$acc) throw new RuntimeException('Type a username.');
                $db->prepare('INSERT INTO pc_beta_members (beta_id, account_id, status, created) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status = VALUES(status)')->execute([$beta, $acc, $status]);
                rs_flash($status === 'in' ? 'They\'re in.' : 'They\'re out of that beta.');
                break;
            }
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$q = fn(string $sql, array $a = []) => (function () use ($db, $sql, $a) { $st = $db->prepare($sql); $st->execute($a); return $st->fetchAll(); })();
$one = fn(string $sql, array $a = []) => (int)($q($sql, $a)[0]['n'] ?? 0);
$when = fn(?string $d) => $d ? rs_date('M j, g:i a', strtotime($d . ' UTC')) : '';
$bar = function (array $rows, string $label = 'k') {
    if (!$rows) return '<p class="muted">Nothing yet.</p>';
    $max = max(1, ...array_map(fn($r) => (int)$r['n'], $rows));
    $out = '<div class="rl-bars">';
    foreach ($rows as $r) $out .= '<div class="rl-bar"><span>' . h((string)($r[$label] !== '' ? $r[$label] : '(not known)')) . '</span><i style="width:' . round(100 * (int)$r['n'] / $max) . '%"></i><b>' . (int)$r['n'] . '</b></div>';
    return $out . '</div>';
};

rs_head('RecompLauncher');
?>
<style>
.rl-tabs{display:flex;gap:8px;margin:0 0 18px;flex-wrap:wrap}.rl-tabs a{padding:8px 14px;border-radius:10px;background:rgba(var(--ink-rgb),.06);text-decoration:none}.rl-tabs a.on{background:var(--cyan);color:var(--on-accent)}
.rl-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}@media (max-width:900px){.rl-grid{grid-template-columns:1fr}}
.rl-nums{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.rl-nums div{padding:14px;border-radius:12px;background:rgba(var(--ink-rgb),.05)}.rl-nums b{display:block;font-size:26px}.rl-nums span{color:var(--muted);font-size:13px}
.rl-launch-top{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;flex-wrap:wrap}.rl-launch-top h2{margin-top:0}
.rl-launch-acts{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.rl-launch-acts form{margin:0}
a.btn{display:inline-flex;align-items:center;padding:9px 14px;border-radius:10px;background:rgba(var(--ink-rgb),.08);color:inherit;text-decoration:none}a.btn:hover{background:rgba(var(--ink-rgb),.14)}
.rl-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:16px}@media (max-width:900px){.rl-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}@media (max-width:560px){.rl-steps{grid-template-columns:1fr}}
.rl-steps>div{display:flex;flex-direction:column;gap:6px;padding:14px;border-radius:12px;background:rgba(var(--ink-rgb),.05)}.rl-steps .status-pill{align-self:flex-start}
.linkish{background:none;border:0;padding:0;color:var(--cyan-ink);cursor:pointer;font:inherit;text-decoration:underline}
.rl-grid>label{display:block;min-width:0}
.rl-bars{display:grid;gap:6px}.rl-bar{display:grid;grid-template-columns:minmax(0,220px) 1fr 50px;gap:10px;align-items:center;font-size:13.5px}.rl-bar span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.rl-bar i{height:8px;border-radius:99px;background:linear-gradient(90deg,var(--cyan),#377BB6);min-width:2px}.rl-bar b{text-align:right}
</style>
<section class="head-row"><div><h1>RecompLauncher</h1><p class="muted">The app itself: its launch, who uses it and on what, what goes wrong, its betas and its updates. Usage comes from launchers with "Share usage and diagnostics" on (Settings, General).</p></div></section>
<nav class="rl-tabs"><?php foreach (['launch' => 'Launch', 'players' => 'Players', 'problems' => 'Problems', 'betas' => 'Betas', 'updates' => 'Updates'] as $k => $n): ?><a href="?view=<?= $k ?>"<?= $view === $k ? ' class="on"' : '' ?>><?= $n ?></a><?php endforeach; ?></nav>

<?php if ($view === 'launch'):
    $cur = rs_rl_version();
    $files = rs_rl_installers();
    $live = rs_rl_launch_live();
    $pg = rs_rl_launch_page();
    $custom = rs_setting('rl_launch_page', '') !== '';
    $dl = rs_rl_launch_downloads();
    $prev = rs_rl_launch_preview_url();
    $list = function_exists('rs_lbeta_audience') ? count(rs_lbeta_audience()) : 0;
    $tick = fn(bool $ok) => $ok ? '<span class="status-pill used">Done</span>' : '<span class="status-pill">To do</span>';
    $mailSubject = 'The RecompLauncher beta is out';
    $mailBody = "Hi,\n\nThe RecompLauncher beta is out for Windows. You signed up for it, so you're hearing first.\n\nGet it here: https://recomplauncher.com/get\n\nIt installs PC recomps in one click, keeps them updated, backs up your saves, and has a Big Picture mode for the TV. If you already have recomps installed, it picks them up right where they are.\n\nWindows may warn you the first time, because the beta isn't signed yet. Click More info, then Run anyway.\n\nIf something breaks, let the launcher send the crash log, or tell us in the Discord. That's what the beta is for.\n\nThanks for being early,\nRipalda";
?>
<section class="card">
  <div class="rl-launch-top">
    <div><h2>The beta on recomplauncher.com/get <?= $live ? '<span class="status-pill used">Live</span>' : '<span class="status-pill off">Not live</span>' ?></h2>
      <p class="muted"><?= $live ? 'Everyone sees the download. The pop-up on recomplauncher.com says the beta is out.' : 'Everyone still sees the sign-up. Only the preview link shows the download page.' ?></p></div>
    <div class="rl-launch-acts">
      <a class="btn" href="<?= h($prev) ?>" target="_blank" rel="noopener">Open the preview</a>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="launch-live">
        <?php if ($live): ?><input type="hidden" name="on" value="0"><button onclick="return confirm('Take the download off the Get page and go back to the sign-up?')">Back to the sign-up</button>
        <?php else: ?><input type="hidden" name="on" value="1"><button class="primary"<?= $cur ? '' : ' disabled' ?> onclick="return confirm('Make the beta live? Everyone on recomplauncher.com/get gets the download.')">Go live</button><?php endif; ?></form>
    </div>
  </div>
  <div class="rl-steps">
    <div><b>1. The build</b><?= $tick((bool)$cur) ?><span class="muted"><?= $cur ? 'RecompLauncher ' . h($cur['version']) . ' is published. The page downloads it.' : 'Upload the installer and publish it below.' ?></span></div>
    <div><b>2. The page</b><?= $tick($custom) ?><span class="muted"><?= $custom ? 'Your words are saved.' : 'It has starting words. Read them over and save.' ?></span></div>
    <div><b>3. Check the preview</b><span class="status-pill">Yours</span><span class="muted">Open it on your phone too. It only works for whoever has the link.</span></div>
    <div><b>4. Go live</b><?= $tick($live) ?><span class="muted"><?= $live ? h($dl['total'] . ' downloads so far, ' . $dl['today'] . ' today.') : 'One button, and back again just as fast.' ?></span></div>
    <div><b>5. Tell the beta list</b><span class="status-pill"><?= $list ?> people</span><span class="muted">From Accounts, Emails, a batch at a time. The draft is at the bottom.</span></div>
    <div><b>Preview link</b><span class="status-pill">Private</span><span class="muted"><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="launch-key"><button class="linkish" onclick="return confirm('Make a new preview link? The old one stops working.')">Make a new one</button></form> if it got shared.</span></div>
  </div>
</section>

<section class="card"><h2>The build</h2>
  <p class="muted">The Get page always downloads the version published here, the same one the launcher's own update offers.<?php if ($cur): ?> Out now: <b>RecompLauncher <?= h($cur['version']) ?></b> · <?= h(round(((int)($cur['size'] ?? 0)) / 1048576, 1) . ' MB') ?> · <?= h($cur['date'] ?? '') ?> · SHA-256 <span class="code"><?= h(substr((string)($cur['sha256'] ?? ''), 0, 16)) ?>…</span><?php endif; ?></p>
  <form method="post" enctype="multipart/form-data" class="filters"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="upload">
    <label class="grow" style="flex:1">Upload an installer <span class="muted">(RecompLauncher_&lt;version&gt;_x64-setup.exe)</span><input type="file" name="installer" accept=".exe"></label><button>Upload</button></form>
  <?php if ($files): ?>
  <form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish">
    <div class="filters"><label>Version<input name="version" placeholder="0.9.2" required pattern="\d+\.\d+\.\d+(-(beta|alpha)\.\d+)?" value="<?= h($cur['version'] ?? '') ?>"></label>
      <label class="grow" style="flex:1">Installer<select name="file"><?php foreach ($files as $f): ?><option value="<?= h($f['file']) ?>"<?= rawurldecode(basename((string)($cur['url'] ?? ''))) === $f['file'] ? ' selected' : '' ?>><?= h($f['file']) ?> (<?= round($f['size'] / 1048576, 1) ?> MB)</option><?php endforeach; ?></select></label></div>
    <label>What's new <span class="muted">(Markdown, shown in the launcher)</span><textarea name="notes" rows="5"><?= h($cur['notes'] ?? '') ?></textarea></label>
    <p><button class="primary" onclick="return confirm('Publish this version? Every launcher that\'s out there gets offered it too.')">Publish</button></p>
  </form><?php else: ?><p class="muted">Nothing in launcher/ yet.</p><?php endif; ?>
</section>

<section class="card"><h2>The page</h2>
  <p class="muted">What recomplauncher.com/get shows once it's live. One item per line in the lists. Questions: the question on one line, the answer under it, an empty line between each.</p>
  <form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="launch-save">
    <div class="rl-grid">
      <label>Headline<input name="headline" maxlength="200" value="<?= h($pg['headline']) ?>"></label>
      <label>Under the button<input name="soon" maxlength="200" value="<?= h($pg['soon']) ?>"></label>
    </div>
    <label>Intro<textarea name="intro" rows="2" maxlength="600"><?= h($pg['intro']) ?></textarea></label>
    <div class="rl-grid">
      <label>Install it <span class="muted">(steps, in order)</span><textarea name="steps" rows="6"><?= h($pg['steps']) ?></textarea></label>
      <label>What you need<textarea name="reqs" rows="6"><?= h($pg['reqs']) ?></textarea></label>
    </div>
    <div class="rl-grid">
      <label>Windows warning <span class="muted">(its title, then the text)</span><input name="warn_title" maxlength="120" value="<?= h($pg['warn_title']) ?>"><textarea name="warn" rows="5" style="margin-top:8px"><?= h($pg['warn']) ?></textarea></label>
      <label>Known issues<textarea name="issues" rows="7"><?= h($pg['issues']) ?></textarea></label>
    </div>
    <label>Questions<textarea name="faq" rows="14"><?= h($pg['faq']) ?></textarea></label>
    <label>Found a bug? <span class="muted">(the Discord button goes under it)</span><textarea name="bugs" rows="3"><?= h($pg['bugs']) ?></textarea></label>
    <p class="rl-launch-acts"><button class="primary">Save</button> <a class="btn" href="<?= h($prev) ?>" target="_blank" rel="noopener">Open the preview</a></p>
  </form>
  <?php if ($custom): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="launch-reset"><button class="linkish" onclick="return confirm('Throw away your words and go back to the starting ones?')">Go back to the starting words</button></form><?php endif; ?>
</section>

<section class="card"><h2>The email to the beta list</h2>
  <p class="muted"><?= $list ?> confirmed people are on the list. Send it from <a href="accounts.php#emails">Accounts, Emails</a>: pick the RecompLauncher beta list and send it a batch at a time. Hostinger's mailbox has a daily sending limit, so a big list can take more than a day. Send it after you go live, so the link works.</p>
  <label>Subject<input readonly value="<?= h($mailSubject) ?>" onclick="this.select()"></label>
  <label>Email<textarea readonly rows="14" onclick="this.select()"><?= h($mailBody) ?></textarea></label>
</section>

<?php elseif ($view === 'players'):
    $d1 = $one('SELECT COUNT(*) n FROM rl_installs WHERE last_seen > UTC_TIMESTAMP() - INTERVAL 1 DAY');
    $d7 = $one('SELECT COUNT(*) n FROM rl_installs WHERE last_seen > UTC_TIMESTAMP() - INTERVAL 7 DAY');
    $d30 = $one('SELECT COUNT(*) n FROM rl_installs WHERE last_seen > UTC_TIMESTAMP() - INTERVAL 30 DAY');
    $all = $one('SELECT COUNT(*) n FROM rl_installs');
    $recent = 'last_seen > UTC_TIMESTAMP() - INTERVAL 30 DAY';
    $by = fn(string $col, int $lim = 12) => $q("SELECT $col k, COUNT(*) n FROM rl_installs WHERE $recent GROUP BY k ORDER BY n DESC LIMIT $lim");
    $ram = $q("SELECT CASE WHEN ram_gb = 0 THEN '' WHEN ram_gb < 8 THEN 'Under 8 GB' WHEN ram_gb < 16 THEN '8 to 15 GB' WHEN ram_gb < 32 THEN '16 to 31 GB' ELSE '32 GB or more' END k, COUNT(*) n FROM rl_installs WHERE $recent GROUP BY k ORDER BY n DESC");
    $games = $q("SELECT game k, COUNT(DISTINCT install_id) n, ROUND(SUM(secs)/3600) hours FROM rl_events WHERE kind = 'session' AND at > UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY game ORDER BY n DESC LIMIT 15");
    foreach ($games as &$g) $g['k'] = rs_rl_game_name((string)$g['k']) . ' (' . (int)$g['hours'] . ' h)'; unset($g);
?>
<section class="card"><div class="rl-nums"><div><b><?= $d1 ?></b><span>Today</span></div><div><b><?= $d7 ?></b><span>This week</span></div><div><b><?= $d30 ?></b><span>This month</span></div><div><b><?= $all ?></b><span>Ever</span></div></div></section>
<div class="rl-grid">
  <section class="card"><h2>Most played (players, this month)</h2><?= $games ? $bar($games) : '<p class="muted">Nothing yet.</p>' ?></section>
  <section class="card"><h2>Graphics cards</h2><?= $bar($by('gpu')) ?></section>
  <section class="card"><h2>Systems</h2><?= $bar($by('osver')) ?></section>
  <section class="card"><h2>Processors</h2><?= $bar($by('cpu')) ?></section>
  <section class="card"><h2>Memory</h2><?= $bar($ram) ?></section>
  <section class="card"><h2>Screens</h2><?= $bar($by('screen')) ?></section>
  <section class="card"><h2>Launcher versions</h2><?= $bar($by('version')) ?></section>
  <section class="card"><h2>Languages</h2><?= $bar($by('lang')) ?></section>
  <section class="card"><h2>Controllers</h2><?= $bar($by('pads')) ?></section>
  <section class="card"><h2>Big Picture mode</h2><?= $bar($q("SELECT IF(bigpicture = 1, 'Uses it', 'Desktop only') k, COUNT(*) n FROM rl_installs WHERE $recent GROUP BY k ORDER BY n DESC")) ?></section>
</div>

<?php elseif ($view === 'problems'):
    $fails = $q("SELECT game, version, detail, COUNT(*) n, MAX(at) last FROM rl_events WHERE kind IN ('install', 'update') AND detail <> '' AND at > UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY game, version, detail ORDER BY n DESC LIMIT 40");
    $crash = $q("SELECT game, version, code, COUNT(*) n, COUNT(DISTINCT install_id) pcs, MAX(at) last FROM rl_events WHERE kind = 'crash' AND at > UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY game, version, code ORDER BY n DESC LIMIT 40");
    $errs = $q("SELECT detail, COUNT(*) n, MAX(at) last FROM rl_events WHERE kind = 'error' AND at > UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY detail ORDER BY n DESC LIMIT 30");
?>
<section class="card"><h2>Games that closed within 15 seconds (this month)</h2><p class="muted">Usually a missing runtime, a driver too old, or a game file problem. The exit code says which, if the game's developer documents it.</p>
  <?php if (!$crash): ?><p class="muted">None.</p><?php else: ?><div class="table-wrap"><table class="codes"><thead><tr><th>Game</th><th>Version</th><th>Exit code</th><th>Times</th><th>PCs</th><th>Last</th></tr></thead><tbody>
  <?php foreach ($crash as $r): ?><tr><td><?= h(rs_rl_game_name($r['game'])) ?></td><td><?= h($r['version']) ?></td><td class="code"><?= $r['code'] === null ? '' : h((string)$r['code']) ?></td><td><?= (int)$r['n'] ?></td><td><?= (int)$r['pcs'] ?></td><td class="muted"><?= h($when($r['last'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></section>
<section class="card"><h2>Installs and updates that failed (this month)</h2>
  <?php if (!$fails): ?><p class="muted">None.</p><?php else: ?><div class="table-wrap"><table class="codes"><thead><tr><th>Game</th><th>Version</th><th>What it said</th><th>Times</th><th>Last</th></tr></thead><tbody>
  <?php foreach ($fails as $r): ?><tr><td><?= h(rs_rl_game_name($r['game'])) ?></td><td><?= h($r['version']) ?></td><td><?= h($r['detail']) ?></td><td><?= (int)$r['n'] ?></td><td class="muted"><?= h($when($r['last'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></section>
<section class="card"><h2>Launcher errors (this month)</h2>
  <?php if (!$errs): ?><p class="muted">None.</p><?php else: ?><div class="table-wrap"><table class="codes"><thead><tr><th>What it said</th><th>Times</th><th>Last</th></tr></thead><tbody>
  <?php foreach ($errs as $r): ?><tr><td><?= h($r['detail']) ?></td><td><?= (int)$r['n'] ?></td><td class="muted"><?= h($when($r['last'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></section>

<?php elseif ($view === 'betas'):
    $betas = rs_rl_betas_all();
    $cat = json_decode((string)@file_get_contents(RS_PUBLIC . '/pc-catalog.json'), true);
    $apps = array_map(fn($a) => [(string)$a['id'], (string)$a['name']], (array)($cat['apps'] ?? []));
    usort($apps, fn($x, $y) => strcasecmp($x[1], $y[1]));
    $edit = (int)($_GET['edit'] ?? 0);
    $form = function (array $b) use ($apps) { ?>
    <form method="post" class="stack-form">
      <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="beta-save"><input type="hidden" name="id" value="<?= (int)($b['id'] ?? 0) ?>">
      <div class="filters">
        <label>Recomp<select name="app"><?php foreach ($apps as [$id, $n]): ?><option value="<?= h($id) ?>"<?= ($b['app'] ?? '') === $id ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
        <label class="grow" style="flex:1">Name <span class="muted">(optional)</span><input name="title" maxlength="80" value="<?= h($b['title'] ?? '') ?>" placeholder="Like 1.3 beta: new renderer"></label>
      </div>
      <div class="filters">
        <label>Build<select name="channel"><option value="prerelease"<?= ($b['channel'] ?? '') !== 'tag' ? ' selected' : '' ?>>Its newest GitHub pre-release</option><option value="tag"<?= ($b['channel'] ?? '') === 'tag' ? ' selected' : '' ?>>One release, by tag</option></select></label>
        <label>Tag <span class="muted">(for one release)</span><input name="tag" maxlength="80" value="<?= h($b['tag'] ?? '') ?>" placeholder="v1.3.0-beta2"></label>
        <label>Who can play<select name="access"><?php foreach (RS_RL_ACCESS as $k => $n): ?><option value="<?= $k ?>"<?= ($b['access'] ?? 'open') === $k ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
      </div>
      <label>What to test <span class="muted">(players see this)</span><textarea name="notes" rows="3" maxlength="4000"><?= h($b['notes'] ?? '') ?></textarea></label>
      <p><label class="check" style="display:inline-flex"><input type="checkbox" name="active" value="1"<?= !isset($b['active']) || (int)$b['active'] ? ' checked' : '' ?>> Open</label> <button class="primary">Save</button></p>
    </form>
    <?php };
?>
<section class="card"><h2>New beta</h2><p class="muted">A beta is a recomp's test build from its own GitHub. Players see it in RecompLauncher and on their account page, join, and play it from the launcher; going back to the normal release is one button.</p><?php $form([]); ?></section>
<?php foreach ($betas as $b): $members = rs_rl_beta_members((int)$b['id']); ?>
<section class="card">
  <h2><?= h($b['name']) ?><?= $b['title'] !== '' ? ': ' . h($b['title']) : '' ?> <?= (int)$b['active'] ? '<span class="status-pill used">Open</span>' : '<span class="status-pill off">Closed</span>' ?></h2>
  <p class="muted"><?= $b['channel'] === 'tag' ? 'Release ' . h($b['tag']) : 'Its newest pre-release' ?> · <?= h(RS_RL_ACCESS[$b['access']] ?? $b['access']) ?> · <?= count(array_filter($members, fn($m) => $m['status'] === 'in')) ?> in<?php $asked = count(array_filter($members, fn($m) => $m['status'] === 'asked')); echo $asked ? ', ' . $asked . ' asking' : ''; ?></p>
  <?php if ($edit === (int)$b['id']) $form($b); else echo '<p><a href="?view=betas&amp;edit=' . (int)$b['id'] . '">Edit</a></p>'; ?>
  <form method="post" class="filters"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="member"><input type="hidden" name="beta" value="<?= (int)$b['id'] ?>"><input type="hidden" name="status" value="in">
    <label class="grow" style="flex:1">Let someone in<input name="username" maxlength="20" placeholder="Their username"></label><button>Add</button></form>
  <?php if ($members): ?><div class="table-wrap"><table class="codes"><thead><tr><th>Who</th><th>Status</th><th>Since</th><th></th></tr></thead><tbody>
  <?php foreach ($members as $m): ?><tr><td><?= h($m['username'] ?? '(deleted)') ?></td><td><?= ['in' => '<span class="status-pill used">In</span>', 'asked' => '<span class="status-pill">Asking</span>', 'out' => '<span class="status-pill off">Out</span>'][$m['status']] ?? h($m['status']) ?></td><td class="muted"><?= h($when($m['created'])) ?></td>
    <td style="text-align:right"><form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="member"><input type="hidden" name="beta" value="<?= (int)$b['id'] ?>"><input type="hidden" name="account" value="<?= (int)$m['account_id'] ?>">
      <?php if ($m['status'] !== 'in'): ?><button name="status" value="in" class="primary">Let in</button><?php endif; ?><?php if ($m['status'] !== 'out'): ?><button name="status" value="out">Remove</button><?php endif; ?></form></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endforeach; ?>

<?php else:
    $cur = rs_rl_version();
    $files = rs_rl_installers();
?>
<section class="card"><h2>Out now</h2>
  <?php if (!$cur): ?><p class="muted">Nothing published yet: launchers don't offer an update.</p>
  <?php else: ?><p><b>RecompLauncher <?= h($cur['version']) ?></b> <span class="muted">· <?= h($cur['date'] ?? '') ?> · <?= h(basename((string)$cur['url'])) ?></span></p><?php endif; ?>
</section>
<section class="card"><h2>Upload an installer</h2><p class="muted">The one the build makes: RecompLauncher_&lt;version&gt;_x64-setup.exe. It goes in launcher/ on the site.</p>
  <form method="post" enctype="multipart/form-data" class="filters"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="upload">
    <label class="grow" style="flex:1">Installer<input type="file" name="installer" accept=".exe"></label><button>Upload</button></form>
  <?php if ($files): ?><p class="muted">In launcher/: <?= h(implode(', ', array_map(fn($f) => $f['file'] . ' (' . round($f['size'] / 1048576, 1) . ' MB)', $files))) ?></p><?php endif; ?>
</section>
<section class="card"><h2>Publish a version</h2><p class="muted">Every launcher offers it within a few hours (and when it opens), downloads it, checks it and installs it. The notes show in the launcher as What's new.</p>
  <?php if (!$files): ?><p class="muted">Upload an installer first.</p><?php else: ?>
  <form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="publish">
    <div class="filters"><label>Version<input name="version" placeholder="0.9.1" required pattern="\d+\.\d+\.\d+(-(beta|alpha)\.\d+)?"></label>
      <label class="grow" style="flex:1">Installer<select name="file"><?php foreach ($files as $f): ?><option><?= h($f['file']) ?></option><?php endforeach; ?></select></label></div>
    <label>What's new <span class="muted">(Markdown)</span><textarea name="notes" rows="10"><?= h($cur['notes'] ?? '') ?></textarea></label>
    <p><button class="primary" onclick="return confirm('Offer this version to every launcher?')">Publish</button></p>
  </form><?php endif; ?>
</section>
<?php endif; ?>
<?php rs_foot();
