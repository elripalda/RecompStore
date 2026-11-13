<?php
// RecompStore admin (4.8): Players. Every account, searchable and a page at a time, and one account
// opened on the side to fix: confirm the email, send the reset, sign them out, turn off two-step,
// their RecompLauncher PCs and cloud saves, close or delete the account.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/rl_cloud.php';
require_once __DIR__ . '/inc/adminlink.php';
rs_require_login();
rs_acct_tables();
rs_rl_tables();
$db = rs_db();

$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 120));
$f = in_array($_GET['f'] ?? '', ['unverified', 'new', 'saves', 'banned', 'twostep'], true) ? (string)$_GET['f'] : '';
$p = max(1, (int)($_GET['p'] ?? 1));
$sel = (int)($_GET['id'] ?? 0);
$per = 50;
$self = function (array $over = []) use ($q, $f, $p, $sel): string {
    $a = array_filter(array_merge(['q' => $q, 'f' => $f, 'p' => $p > 1 ? $p : '', 'id' => $sel ?: ''], $over), fn($v) => $v !== '' && $v !== null && $v !== 0);
    return 'players.php' . ($a ? '?' . http_build_query($a) : '');
};

// ---- Fixes ---------------------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    // (4.9) Confirmation emails in waves (see rs_acct_wave in inc/accounts.php).
    if (in_array($act, ['wave_start', 'wave_save', 'wave_pause'], true)) {
        try {
            $w = rs_acct_wave();
            foreach (['size' => [10, 1000], 'gap' => [5, 1440], 'cap' => [10, 100000]] as $k => [$lo, $hi]) if (isset($_POST[$k])) $w[$k] = max($lo, min($hi, (int)$_POST[$k]));
            if ($act === 'wave_start') {
                if (!rs_mail_ready()) throw new RuntimeException('Set up sending on the Mail page first.');
                if (!$w['cursor'] || $w['done']) { $w['cursor'] = (int)$db->query('SELECT COALESCE(MAX(id), 0) + 1 FROM accounts')->fetchColumn(); $w['sent'] = 0; $w['waves'] = 0; $w['start'] = time(); $w['done'] = false; }
                $w['on'] = true;
                $w['last'] = 0;
                rs_flash('Started. The first wave goes out with the next emails, newest accounts first.');
            } elseif ($act === 'wave_pause') {
                $w['on'] = false;
                rs_flash('Paused. Start again to pick up where it stopped.');
            } else rs_flash('Saved.');
            rs_acct_wave_save($w);
            rs_log('player', 'confirmation waves', $act . ' (' . $w['size'] . ' per wave, every ' . $w['gap'] . ' min, ' . $w['cap'] . ' a day)');
        } catch (Throwable $e) {
            rs_flash($e->getMessage(), 'err');
        }
        header('Location: ' . $self(['f' => 'unverified', 'id' => '']));
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    $acc = rs_acct_get($id);
    $back = $self(['id' => $id]);
    try {
        if (!$acc) throw new RuntimeException('That account is gone.');
        $who = (string)($acc['username'] ?? ('#' . $id));
        switch ($act) {
            case 'verify':
                rs_acct_update($id, ['email_ok' => 1]);
                rs_flash('Marked ' . $who . '\'s email as confirmed.');
                break;
            case 'send_verify':
                if (!rs_mail_ready()) throw new RuntimeException('Set up sending on the Mail page first.');
                rs_acct_mail_confirm($acc);
                rs_flash('Sent ' . $who . ' a new confirm link.');
                break;
            case 'send_reset':
                if (!rs_mail_ready()) throw new RuntimeException('Set up sending on the Mail page first.');
                rs_acct_mail_reset($acc);
                rs_flash('Sent ' . $who . ' a password reset link (good for 30 minutes).');
                break;
            case 'signout':
                $db->prepare('DELETE FROM account_sessions WHERE account_id = ?')->execute([$id]);
                rs_flash($who . ' is signed out everywhere (website). Their launcher PCs stay linked.');
                break;
            case 'twostep_off':
                $db->prepare('UPDATE accounts SET totp_on = 0, totp_secret = NULL, backup_codes = NULL WHERE id = ?')->execute([$id]);
                rs_flash('Two-step sign-in is off for ' . $who . '. They can turn it on again from their account.');
                break;
            case 'ban':
            case 'unban':
                rs_acct_update($id, ['banned' => $act === 'ban' ? 1 : 0]);
                if ($act === 'ban') $db->prepare('DELETE FROM account_sessions WHERE account_id = ?')->execute([$id]);
                rs_flash($act === 'ban' ? $who . ' is closed: signed out and can\'t sign in.' : $who . ' is open again.');
                break;
            case 'device_remove':
                rs_rl_device_remove($id, (int)($_POST['device'] ?? 0));
                rs_flash('That PC is unlinked from ' . $who . '.');
                break;
            case 'save_delete':
                rs_rl_delete($acc, (string)($_POST['game'] ?? ''), (string)($_POST['v'] ?? ''));
                rs_flash('Deleted that cloud save copy.');
                break;
            // (4.8) Merge with an admin login (owners only): the admin login takes this account's
            // username and can be signed into with this account. See inc/adminlink.php.
            case 'admin_merge':
                $uid = (int)($_POST['admin'] ?? 0);
                $was = (string)(rs_team_get($uid)['username'] ?? '');
                $name = rs_al_merge($uid, $id);
                rs_log('team', 'merged', $was . ' with the account ' . $who . ($was !== $name ? ' (now ' . $name . ')' : ''));
                rs_flash(($was !== $name ? 'The admin login ' . $was . ' is now ' . $name . '. ' : 'Merged. ') . 'Sign in to the admin with this RecompLauncher account (Continue as ' . $name . ' on the login page, or its username or email and password). The admin password still works as a backup.');
                break;
            case 'admin_unmerge':
                $adm = rs_al_admin_for($id, true);
                if (!$adm) throw new RuntimeException('This account isn\'t merged with an admin login.');
                rs_al_unmerge((int)$adm['id']);
                rs_log('team', 'unmerged', $adm['username'] . ' from the account ' . $who);
                rs_flash('Unmerged. ' . $adm['username'] . ' signs in with its own admin password again.');
                break;
            case 'delete':
                if (rs_al_admin_for($id, true)) throw new RuntimeException('This account is merged with an admin login. Unmerge it first.');
                if (trim((string)($_POST['confirm'] ?? '')) !== 'DELETE') throw new RuntimeException('Type DELETE to delete the account.');
                $GLOBALS['rs_admin_delete'] = true;
                rs_acct_delete($acc);
                $back = $self(['id' => '']);
                rs_flash('Deleted ' . $who . ', with their saves and linked PCs.');
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
        rs_log('player', $who, $act);
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

// A cloud save copy, downloaded for a look.
if (isset($_GET['save'], $_GET['v']) && $sel) {
    $acc = rs_acct_get($sel);
    $file = $acc ? rs_rl_file($acc, (string)$_GET['save'], (string)$_GET['v']) : null;
    if ($file) { rs_log('player', (string)($acc['username'] ?? $sel), 'downloaded save ' . $_GET['save']); rs_rl_send($file, $_GET['save'] . '-' . $_GET['v'] . '.zip'); }
    rs_flash('That save copy isn\'t there any more.', 'err');
    header('Location: ' . $self());
    exit;
}

// ---- The list ------------------------------------------------------------------------------------
$where = [];
$args = [];
if ($q !== '') {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
    $w = '(username_lc LIKE ? OR email_lc LIKE ?';
    array_push($args, $like, $like);
    if (ctype_digit($q)) { $w .= ' OR discord_id = ? OR github_id = ? OR id = ?'; array_push($args, $q, $q, (int)$q); }
    $where[] = $w . ')';
}
if ($f === 'unverified') $where[] = 'email_ok = 0 AND email IS NOT NULL';
if ($f === 'new') $where[] = 'created >= UTC_DATE()';
if ($f === 'banned') $where[] = 'banned = 1';
if ($f === 'twostep') $where[] = 'totp_on = 1';
if ($f === 'saves') $where[] = 'id IN (SELECT DISTINCT account_id FROM cloud_saves)';
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$st = $db->prepare('SELECT COUNT(*) FROM accounts' . $w);
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$p = min($p, $pages);
$st = $db->prepare('SELECT id, username, email, email_ok, pass_hash IS NOT NULL pw, google_sub IS NOT NULL g, discord_id IS NOT NULL d, github_id IS NOT NULL gh, totp_on, banned, created, last_seen FROM accounts'
    . $w . ' ORDER BY ' . ($q !== '' ? '(username_lc = ' . $db->quote(mb_strtolower($q)) . ') DESC, ' : '') . ($f === 'new' ? 'created' : 'COALESCE(last_seen, created)') . ' DESC LIMIT ' . $per . ' OFFSET ' . (($p - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll();
$ids = array_map(fn($r) => (int)$r['id'], $rows);
$saves = [];
if ($ids) foreach ($db->query('SELECT account_id, COUNT(DISTINCT game) n FROM cloud_saves WHERE account_id IN (' . implode(',', $ids) . ') GROUP BY account_id') as $r) $saves[(int)$r['account_id']] = (int)$r['n'];
$all = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$today = (int)$db->query('SELECT COUNT(*) FROM accounts WHERE created >= UTC_DATE()')->fetchColumn();

$when = function (?string $at): string {
    if (!$at) return '–';
    $t = strtotime($at . ' UTC') ?: 0;
    $s = time() - $t;
    if ($s < 120) return 'just now';
    if ($s < 3600) return floor($s / 60) . ' min ago';
    if ($s < 86400) return floor($s / 3600) . ' h ago';
    if ($s < 7 * 86400) return floor($s / 86400) . ' d ago';
    return rs_date('M j, Y', $t);
};
$ways = fn(array $r) => implode(' · ', array_keys(array_filter(['Password' => $r['pw'], 'Google' => $r['g'], 'Discord' => $r['d'], 'GitHub' => $r['gh']]))) ?: '–';

// ---- The one opened ------------------------------------------------------------------------------
$acc = $sel ? rs_acct_get($sel) : null;
if ($acc) {
    $devices = rs_rl_devices($sel);
    $games = rs_rl_games($sel);
    $used = rs_rl_used($sel);
    $sess = $db->prepare('SELECT COUNT(*), MAX(last_seen) FROM account_sessions WHERE account_id = ?');
    $sess->execute([$sel]);
    [$sessN, $sessAt] = $sess->fetch(PDO::FETCH_NUM);
    $inbox = [];
    if ((string)$acc['email'] !== '') {
        try { $ib = $db->prepare('SELECT id, kind, app_id, created FROM inbox WHERE contact = ? ORDER BY id DESC LIMIT 5'); $ib->execute([(string)$acc['email']]); $inbox = $ib->fetchAll(); } catch (Throwable $e) { /* no inbox */ }
    }
}
// (4.8) The admin login this account is merged with, and the ones it could be merged with.
$admin = $acc ? rs_al_admin_for($sel, true) : null;
$admins = $acc && !$admin && rs_is_owner() ? array_values(array_filter(rs_al_admins(), fn($u) => (int)$u['account_id'] <= 0)) : [];
// (4.9) Confirmation waves, shown with the Not verified list.
if ($f === 'unverified') {
    $wave = rs_acct_wave();
    $waveLeft = $wave['cursor'] ? rs_acct_wave_left($wave) : (int)$db->query('SELECT COUNT(*) FROM accounts WHERE email_ok = 0 AND email IS NOT NULL AND banned = 0 AND ' . RS_ACCT_WAVE_FRESH)->fetchColumn();
    $waveRoom = rs_acct_wave_room($wave);
    $waveNext = max(0, (int)$wave['last'] + (int)$wave['gap'] * 60 - time());
}
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$hid = fn(string $act, int $id) => $csrf . '<input type="hidden" name="action" value="' . h($act) . '"><input type="hidden" name="id" value="' . $id . '">';
$mb = fn(int $b) => $b <= 0 ? '0 MB' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int)round($b / 1024)) . ' KB');

rs_head('Players');
?>
<div class="ov-head"><h1>Players</h1><span class="muted small"><?= number_format($all) ?> accounts · <?= number_format($today) ?> new today</span></div>
<div class="pl-wrap">
<div class="pl-list">
  <div class="pl-tools">
    <form class="pl-q" method="get"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Find by username, email, Discord or GitHub id" aria-label="Find a player" autofocus><?php if ($f !== ''): ?><input type="hidden" name="f" value="<?= h($f) ?>"><?php endif; ?></form>
    <?php foreach (['' => 'All', 'unverified' => 'Not verified', 'new' => 'New today', 'saves' => 'Has cloud saves', 'twostep' => 'Two-step on', 'banned' => 'Closed'] as $k => $n): ?>
    <a class="btn chip<?= $f === $k ? ' on' : '' ?>" href="<?= h($self(['f' => $k, 'p' => '', 'id' => ''])) ?>"><?= h($n) ?></a>
    <?php endforeach; ?>
  </div>
  <?php if ($f === 'unverified'): ?>
  <section class="card pl-wave">
    <div class="pl-wave-head">
      <h2>Confirmation emails <?= $wave['on'] ? '<span class="pill ok">Sending</span>' : ($wave['done'] ? '<span class="pill ok">Done</span>' : ($wave['cursor'] ? '<span class="pill warn">Paused</span>' : '')) ?></h2>
      <p class="muted"><?= number_format($waveLeft) ?> <?= $waveLeft === 1 ? 'account is' : 'accounts are' ?> waiting for one. They go out newest first, a wave at a time, once the mail line is clear. New sign-ups get theirs right away, ahead of the waves.</p>
    </div>
    <?php if ($wave['cursor']): ?>
    <p class="pl-wave-state"><b><?= number_format((int)$wave['sent']) ?></b> sent in <?= (int)$wave['waves'] ?> <?= (int)$wave['waves'] === 1 ? 'wave' : 'waves' ?><?php
      if ($wave['done']) echo ' · everyone has one now';
      elseif (!$wave['on']) echo ' · paused';
      elseif ($waveRoom <= 0) echo ' · today\'s limit is used up, the next wave goes when there\'s room';
      elseif ($waveNext > 0) echo ' · next wave in about ' . max(1, (int)ceil($waveNext / 60)) . ' min';
      else echo ' · next wave as soon as the line is clear'; ?>.</p>
    <?php endif; ?>
    <form method="post" class="pl-wave-form"><?= $csrf ?>
      <label>Per wave<input type="number" name="size" min="10" max="1000" value="<?= max(10, min(1000, (int)$wave['size'])) ?>"></label>
      <label>Minutes apart<input type="number" name="gap" min="5" max="1440" value="<?= max(5, min(1440, (int)$wave['gap'])) ?>"></label>
      <label>Daily limit<input type="number" name="cap" min="10" max="100000" value="<?= max(10, min(100000, (int)$wave['cap'])) ?>"></label>
      <div class="pl-wave-acts">
        <?php if ($wave['on']): ?><button name="action" value="wave_save">Save</button><button name="action" value="wave_pause">Pause</button>
        <?php else: ?><button class="primary" name="action" value="wave_start"><?= $wave['cursor'] && !$wave['done'] ? 'Start again' : 'Start sending' ?></button><?php if ($wave['cursor']): ?><button name="action" value="wave_save">Save</button><?php endif; ?><?php endif; ?>
      </div>
    </form>
    <p class="muted small">The daily limit counts every email the line sent in the last 24 hours (confirmations, resets, submissions), so waves only use what's left: <?= number_format($waveRoom) ?> today. Brevo's free plan allows 300 a day; raise it once you're on Amazon SES.</p>
  </section>
  <?php endif; ?>
  <div class="card" style="padding:0;overflow-x:auto">
    <?php if (!$rows): ?><p class="muted" style="padding:16px"><?= $q !== '' ? 'Nobody matches “' . h($q) . '”.' : 'No accounts here.' ?></p><?php else: ?>
    <table class="pl-table">
      <thead><tr><th>Player</th><th>Email</th><th>Verified</th><th>Signs in with</th><th>Saves</th><th>Joined</th><th>Last seen</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $href = $self(['id' => (int)$r['id']]); ?>
        <tr<?= (int)$r['id'] === $sel ? ' class="on"' : '' ?> onclick="location.href='<?= h($href) ?>'">
          <td><a href="<?= h($href) ?>"><b><?= h((string)($r['username'] ?? '(no name yet)')) ?></b></a><?= $r['banned'] ? ' <span class="no-tag">Closed</span>' : '' ?><?= $r['totp_on'] ? ' <span class="pill">2-step</span>' : '' ?></td>
          <td><?= h((string)$r['email']) ?></td>
          <td><?= $r['email'] === null ? '–' : ($r['email_ok'] ? '<span class="ok-tag">Yes</span>' : '<span class="no-tag">No</span>') ?></td>
          <td><?= h($ways($r)) ?></td>
          <td><?= (int)($saves[(int)$r['id']] ?? 0) ?: '–' ?></td>
          <td><?= h(rs_date('M j, Y', strtotime((string)$r['created'] . ' UTC') ?: 0)) ?></td>
          <td><?= h($when($r['last_seen'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <div class="pl-pager">
    <span><?= $total ? number_format(($p - 1) * $per + 1) . '–' . number_format(min($total, $p * $per)) . ' of ' . number_format($total) : '0' ?></span>
    <span class="grow"></span>
    <?php if ($p > 1): ?><a class="btn" href="<?= h($self(['p' => $p - 1])) ?>">Previous</a><?php endif; ?>
    <span>Page <?= $p ?> of <?= number_format($pages) ?></span>
    <?php if ($p < $pages): ?><a class="btn" href="<?= h($self(['p' => $p + 1])) ?>">Next</a><?php endif; ?>
  </div>
</div>

<aside class="pl-side card" aria-label="Account">
<?php if (!$acc): ?>
  <h2>Pick a player</h2>
  <p class="muted">Search above or click a row to open their account here: confirm their email, send a reset, look at their cloud saves and linked PCs.</p>
<?php else: $name = (string)($acc['username'] ?? '(no name yet)'); ?>
  <div class="pl-who">
    <span class="me-av" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
    <div style="flex:1;min-width:0"><strong style="font-size:15px"><?= h($name) ?></strong><div class="muted small">Joined <?= h(rs_date('M j, Y', strtotime((string)$acc['created'] . ' UTC') ?: 0)) ?> · last seen <?= h($when($acc['last_seen'])) ?></div></div>
    <?= $acc['banned'] ? '<span class="no-tag">Closed</span>' : ($acc['email'] !== null ? ($acc['email_ok'] ? '<span class="ok-tag">Verified</span>' : '<span class="no-tag">Not verified</span>') : '') ?>
  </div>
  <div class="kv"><span>Email</span><span><?= h((string)($acc['email'] ?? '–')) ?></span></div>
  <div class="kv"><span>Signs in with</span><span><?= h(implode(' · ', array_keys(array_filter(['Password' => $acc['pass_hash'] !== null, 'Google' => $acc['google_sub'] !== null, 'Discord' => $acc['discord_id'] !== null, 'GitHub' => $acc['github_id'] !== null]))) ?: '–') ?></span></div>
  <?php if ($acc['discord_id'] !== null): ?><div class="kv"><span>Discord id</span><span><?= h((string)$acc['discord_id']) ?></span></div><?php endif; ?>
  <div class="kv"><span>Two-step</span><span><?= $acc['totp_on'] ? 'On' : 'Off' ?></span></div>
  <div class="kv"><span>Website</span><span><?= (int)$sessN ?> signed-in browser<?= (int)$sessN === 1 ? '' : 's' ?><?= $sessAt ? ' · ' . h($when((string)$sessAt)) : '' ?></span></div>
  <div class="kv"><span>Account id</span><span>#<?= (int)$acc['id'] ?></span></div>

  <div class="pl-acts">
    <?php if ($acc['email'] !== null && !$acc['email_ok']): ?>
    <form method="post"><?= $hid('verify', $sel) ?><button class="primary">Mark email verified</button></form>
    <form method="post"><?= $hid('send_verify', $sel) ?><button>Resend confirm email</button></form>
    <?php endif; ?>
    <?php if ($acc['email'] !== null): ?><form method="post"><?= $hid('send_reset', $sel) ?><button>Send password reset</button></form><?php endif; ?>
    <form method="post"><?= $hid('signout', $sel) ?><button>Sign out everywhere</button></form>
    <?php if ($acc['totp_on']): ?><form method="post" onsubmit="return confirm('Turn off two-step sign-in for <?= h(addslashes($name)) ?>?')"><?= $hid('twostep_off', $sel) ?><button>Turn off two-step</button></form><?php endif; ?>
  </div>

  <h3 class="sub" style="margin:16px 0 4px;font-size:13px">Admin login</h3>
  <?php if ($admin): ?>
  <div class="kv"><span>Merged with</span><span style="flex:1"><b><?= h((string)$admin['username']) ?></b> · <?= h(RS_ROLES[$admin['role']] ?? (string)$admin['role']) ?><?= (int)$admin['active'] ? '' : ' · switched off' ?></span>
    <?php if (rs_is_owner()): ?><form method="post" style="margin:0" onsubmit="return confirm('Unmerge <?= h(addslashes((string)$admin['username'])) ?> from this account? The admin login keeps its name and signs in with its own password again.')"><?= $hid('admin_unmerge', $sel) ?><button>Unmerge</button></form><?php endif; ?></div>
  <p class="muted small">Signs in to the admin with this account. The admin login's own password still works as a backup.</p>
  <?php elseif ($admins && $acc['username'] !== null): ?>
  <form method="post" class="pl-merge" onsubmit="return confirm('Merge this account with the admin login you picked? The admin login takes the username <?= h(addslashes($name)) ?>.')">
    <?= $hid('admin_merge', $sel) ?>
    <select name="admin" aria-label="Admin login" required><option value="">Pick an admin login</option><?php foreach ($admins as $u): ?><option value="<?= (int)$u['id'] ?>"><?= h((string)$u['username']) ?> (<?= h(RS_ROLES[$u['role']] ?? (string)$u['role']) ?>)</option><?php endforeach; ?></select>
    <button class="primary">Merge with admin login</button>
  </form>
  <p class="muted small">The admin login takes the username <b><?= h($name) ?></b>, signs in with this account, and shows this account's picture on the Team page.</p>
  <?php else: ?>
  <p class="muted small"><?= $acc['username'] === null ? 'Picks a username first, then it can be merged with an admin login.' : (rs_is_owner() ? 'Every admin login is merged already.' : 'Not merged with an admin login.') ?></p>
  <?php endif; ?>

  <h3 class="sub" style="margin:16px 0 4px;font-size:13px">RecompLauncher PCs <span class="muted small"><?= count($devices) ?></span></h3>
  <?php if (!$devices): ?><p class="muted small">No PCs linked.</p><?php endif; ?>
  <?php foreach ($devices as $d): ?>
  <div class="kv"><span><?= h((string)$d['name']) ?></span><span style="flex:1"><?= h(trim($d['os'] . ' · ' . $d['version'], ' ·')) ?> · <?= h($when((string)$d['seen'])) ?></span>
    <form method="post" style="margin:0" onsubmit="return confirm('Unlink this PC?')"><?= $hid('device_remove', $sel) ?><input type="hidden" name="device" value="<?= (int)$d['id'] ?>"><button class="icon-btn" title="Unlink this PC" aria-label="Unlink this PC"><?= rs_icon('x') ?></button></form></div>
  <?php endforeach; ?>

  <h3 class="sub" style="margin:16px 0 4px;font-size:13px">Cloud saves <span class="muted small"><?= h($mb($used)) ?> of <?= (int)(RS_RL_QUOTA / 1048576) ?> MB</span></h3>
  <?php if (!$games): ?><p class="muted small">No cloud saves.</p><?php endif; ?>
  <?php foreach ($games as $g): ?>
  <details style="border-top:1px solid var(--line);padding:6px 0">
    <summary style="cursor:pointer;font-size:13px"><b><?= h((string)$g['name']) ?></b> <span class="muted small"><?= count($g['versions']) ?> cop<?= count($g['versions']) === 1 ? 'y' : 'ies' ?> · newest <?= $g['versions'] ? h(rs_date('M j, g:i a', (int)$g['versions'][0]['made'])) : '' ?></span></summary>
    <?php foreach ($g['versions'] as $v): ?>
    <div class="kv"><span><?= h(rs_date('M j, g:i a', (int)$v['made'])) ?></span><span style="flex:1"><?= h($mb((int)$v['size'])) ?> · <?= h((string)$v['device']) ?></span>
      <a class="btn" href="<?= h($self(['save' => $g['game'], 'v' => $v['v']])) ?>">Get</a>
      <form method="post" style="margin:0" onsubmit="return confirm('Delete this save copy? It can\'t be brought back.')"><?= $hid('save_delete', $sel) ?><input type="hidden" name="game" value="<?= h((string)$g['game']) ?>"><input type="hidden" name="v" value="<?= h((string)$v['v']) ?>"><button class="icon-btn danger" title="Delete this copy" aria-label="Delete this copy"><?= rs_icon('x') ?></button></form></div>
    <?php endforeach; ?>
  </details>
  <?php endforeach; ?>

  <?php if ($inbox): ?>
  <h3 class="sub" style="margin:16px 0 4px;font-size:13px">Sent in from this email</h3>
  <?php foreach ($inbox as $m): ?><div class="kv"><span><?= h(ucfirst((string)$m['kind'])) ?></span><span><a href="inbox.php#m<?= (int)$m['id'] ?>"><?= h((string)$m['app_id'] ?: 'Message') ?></a> · <?= h($when((string)$m['created'])) ?></span></div><?php endforeach; ?>
  <?php endif; ?>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:18px;padding-top:12px;border-top:1px solid var(--line)">
    <form method="post" style="margin:0"><?= $hid($acc['banned'] ? 'unban' : 'ban', $sel) ?><button class="<?= $acc['banned'] ? '' : 'danger' ?>"><?= $acc['banned'] ? 'Reopen account' : 'Close account' ?></button></form>
    <form method="post" style="margin:0;display:flex;gap:6px" onsubmit="return confirm('Delete <?= h(addslashes($name)) ?> for good, with their saves and linked PCs?')"><?= $hid('delete', $sel) ?><input name="confirm" placeholder="Type DELETE" aria-label="Type DELETE to confirm" style="width:110px"><button class="danger">Delete</button></form>
  </div>
<?php endif; ?>
</aside>
</div>
<?php rs_foot(); ?>
