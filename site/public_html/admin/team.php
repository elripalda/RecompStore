<?php
// (4.3) Team: the owner makes logins for curators and managers, picks the stores each one works
// on, and can reset a password, switch a login off, or sign it out everywhere.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_roles_schema();
require_once __DIR__ . '/inc/teampub.php';
rs_tp_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $who = $id ? (rs_team_get($id)['username'] ?? '') : '';
    try {
        if ($act === 'add') {
            $user = trim((string)($_POST['username'] ?? ''));
            $pw = rs_team_add($user, (string)($_POST['display_name'] ?? ''), (string)($_POST['role'] ?? ''), (array)($_POST['stores'] ?? []));
            $_SESSION['team_once'] = ['user' => $user, 'pw' => $pw, 'new' => true];
            rs_log('team', 'added', $user . ' as ' . ($_POST['role'] ?? ''));
            rs_flash($user . ' can log in now. Send them the details below.');
        } elseif ($act === 'save') {
            rs_team_save($id, (string)($_POST['display_name'] ?? ''), (string)($_POST['role'] ?? ''), (array)($_POST['stores'] ?? []));
            rs_log('team', 'changed', $who . ': ' . ($_POST['role'] ?? '') . ', ' . implode('+', (array)($_POST['stores'] ?? [])));
            rs_flash('Saved ' . $who . '.');
        } elseif ($act === 'pub') {
            // (4.8) How they show on recomplauncher.com/team.
            rs_tp_save($id, $_POST);
            rs_log('team', 'website', $who . ': ' . (!empty($_POST['show']) ? 'shown' : 'hidden'));
            rs_flash(!empty($_POST['show']) ? $who . ' shows on the Team page.' : $who . ' doesn\'t show on the Team page.');
        } elseif ($act === 'reset') {
            $pw = rs_team_reset($id);
            $_SESSION['team_once'] = ['user' => $who, 'pw' => $pw, 'new' => false];
            rs_log('team', 'password reset', $who);
            rs_flash('New temporary password for ' . $who . '. They\'re signed out and pick their own at the next login.');
        } elseif ($act === 'off' || $act === 'on') {
            rs_team_active($id, $act === 'on');
            rs_log('team', $act === 'on' ? 'switched on' : 'switched off', $who);
            rs_flash($act === 'on' ? $who . ' can log in again.' : $who . ' is switched off and signed out.');
        } elseif ($act === '2fa_off') {
            rs_team_2fa_off($id);
            rs_log('team', 'two-step off', $who);
            rs_flash('Two-step login is off for ' . $who . '. They can set it up again from My account.');
        } elseif ($act === 'signout') {
            rs_team_signout($id);
            if ($id === (int)$_SESSION['uid']) rs_login_done($id);
            rs_log('team', 'signed out', $who);
            rs_flash($who . ' is signed out on every device.');
        } elseif ($act === 'remove') {
            if (($_POST['confirm'] ?? '') !== $who) throw new RuntimeException('Type the username to remove the login.');
            rs_team_remove($id);
            rs_log('team', 'removed', $who);
            rs_flash($who . ' was removed.');
        }
    } catch (RuntimeException $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: team.php' . ($id ? '#u' . $id : ''));
    exit;
}

$team = rs_team();
// (4.8) What each one shows on recomplauncher.com/team.
$pub = [];
foreach (rs_db()->query('SELECT id, pub_show, pub_name, pub_title, pub_github, pub_discord, pub_about, pub_order FROM users') as $r) $pub[(int)$r['id']] = $r;
$once = $_SESSION['team_once'] ?? null;
unset($_SESSION['team_once']);
$meId = (int)$_SESSION['uid'];
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$ago = function (?string $t): string {
    if (!$t) return 'Never';
    $s = time() - (int)strtotime($t);
    if ($s < 3600) return 'Just now';
    if ($s < 86400) return floor($s / 3600) . 'h ago';
    if ($s < 86400 * 30) return floor($s / 86400) . 'd ago';
    return rs_date('M j, Y', (int)strtotime($t));
};
$counts = array_count_values(array_column($team, 'role'));
$storePick = function (string $have) {
    $h = explode(',', $have);
    $o = '';
    foreach (RS_STORES as $k => $n) $o .= '<label class="check"><input type="checkbox" name="stores[]" value="' . h($k) . '"' . (in_array($k, $h, true) ? ' checked' : '') . '> ' . h($n) . '</label>';
    return $o;
};
$rolePick = function (string $have) {
    $o = '';
    foreach (RS_ROLES as $k => $n) $o .= '<option value="' . h($k) . '"' . ($k === $have ? ' selected' : '') . '>' . h($n) . '</option>';
    return $o;
};

rs_head('Team', true, ['assets/account.css']);
?>
<section class="head-row">
  <div>
    <h1>Team</h1>
    <p class="muted">Logins for the people who help run RecompStore and RecompLauncher. Each one sees only what their role and stores allow.</p>
  </div>
</section>

<?php if ($once): ?>
<section class="card once-card">
  <h2><?= $once['new'] ? 'Login made for ' : 'New password for ' ?><?= h($once['user']) ?></h2>
  <p class="muted">Shown once. Send these privately (a DM, not a public channel). They set their own password the first time they log in.</p>
  <pre class="mono codes" id="team-once">Admin: https://<?= h(preg_replace('/[^A-Za-z0-9.:-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'recompstore.com'))) ?>/admin/
Username: <?= h($once['user']) ?>

Temporary password: <?= h($once['pw']) ?></pre>
  <button type="button" class="btn" data-copy="team-once">Copy</button>
</section>
<?php endif; ?>

<div class="role-cards four">
  <?php foreach (['maker', 'curator', 'manager', 'owner'] as $r): ?>
  <div class="role-card"><span class="role-chip role-<?= $r ?>"><?= h(RS_ROLES[$r]) ?></span><span class="n"><?= (int)($counts[$r] ?? 0) ?></span><p class="muted small"><?= h(RS_ROLE_ABOUT[$r]) ?></p></div>
  <?php endforeach; ?>
</div>

<section class="card">
  <h2>Add someone</h2>
  <form method="post" class="team-add">
    <?= $csrf ?><input type="hidden" name="action" value="add">
    <label>Username<input name="username" required pattern="[A-Za-z0-9._\-]{3,40}" placeholder="e.g. xarishark" autocomplete="off"></label>
    <label>Name<input name="display_name" maxlength="80" placeholder="Shown to the team"></label>
    <label>Role<select name="role"><?= $rolePick('curator') ?></select></label>
    <fieldset><legend>Stores</legend><div class="row"><?= $storePick('pc') ?></div></fieldset>
    <div class="go"><button class="btn primary">Make the login</button></div>
  </form>
  <p class="muted small">You'll get a temporary password to send them. Two-step login is optional; they can turn it on from My account.</p>
</section>

<section class="card">
  <h2>Everyone <span class="count"><?= count($team) ?></span></h2>
  <div class="team-list">
  <?php foreach ($team as $t): $tid = (int)$t['id']; $mine = $tid === $meId; $off = !(int)$t['active']; ?>
  <article class="member<?= $off ? ' off' : '' ?>" id="u<?= $tid ?>">
    <div class="member-top">
      <span class="me-av" aria-hidden="true"><?= h(mb_strtoupper(mb_substr((string)($t['display_name'] ?: $t['username']), 0, 1))) ?></span>
      <div class="member-name">
        <strong><?= h((string)($t['display_name'] ?: $t['username'])) ?></strong><?= $mine ? ' <span class="pill">You</span>' : '' ?>
        <div class="muted small"><code><?= h((string)$t['username']) ?></code> · last in <?= h($ago($t['last_login'])) ?></div>
      </div>
      <div class="chips-row">
        <span class="role-chip role-<?= h((string)$t['role']) ?>"><?= h(RS_ROLES[$t['role']] ?? $t['role']) ?></span>
        <?php foreach (explode(',', (string)$t['stores']) as $s): if (!isset(RS_STORES[$s])) continue; ?><span class="store-chip store-<?= h($s) ?>"><?= h(RS_STORES[$s]) ?></span><?php endforeach; ?>
        <?php if ((int)$t['twostep']): ?><span class="pill ok" title="Two-step login is on">2-step</span><?php endif; ?>
        <?php if (!empty($pub[$tid]['pub_show'])): ?><span class="pill ok" title="Shown on recomplauncher.com/team">On the website</span><?php endif; ?>
        <?php if ((int)$t['must_change']): ?><span class="pill warn" title="Hasn't set their own password yet">Invited</span><?php endif; ?>
        <?php if ($off): ?><span class="pill">Switched off</span><?php endif; ?>
      </div>
    </div>
    <details class="member-edit">
      <summary>Manage</summary>
      <form method="post" class="team-add">
        <?= $csrf ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $tid ?>">
        <label>Name<input name="display_name" maxlength="80" value="<?= h((string)$t['display_name']) ?>"></label>
        <label>Role<select name="role"><?= $rolePick((string)$t['role']) ?></select></label>
        <fieldset><legend>Stores</legend><div class="row"><?= $storePick((string)$t['stores']) ?></div></fieldset>
        <div class="go"><button class="btn primary">Save</button></div>
      </form>
      <?php $pb = $pub[$tid] ?? []; ?>
      <form method="post" class="team-add team-pub">
        <?= $csrf ?><input type="hidden" name="action" value="pub"><input type="hidden" name="id" value="<?= $tid ?>">
        <h3>On the website</h3>
        <p class="muted small">recomplauncher.com/team, so developers can check that someone who reaches out is really with us. Leave a box empty to use their name and role.</p>
        <label class="check"><input type="checkbox" name="show" value="1"<?= !empty($pb['pub_show']) ? ' checked' : '' ?>> Show on the Team page</label>
        <label>Name<input name="name" maxlength="60" value="<?= h((string)($pb['pub_name'] ?? '')) ?>" placeholder="<?= h((string)($t['display_name'] ?: $t['username'])) ?>"></label>
        <label>Title<input name="title" maxlength="40" value="<?= h((string)($pb['pub_title'] ?? '')) ?>" placeholder="<?= h(RS_ROLES[$t['role']] ?? '') ?>"></label>
        <label>GitHub<input name="github" maxlength="40" value="<?= h((string)($pb['pub_github'] ?? '')) ?>" placeholder="username" spellcheck="false"></label>
        <label>Discord<input name="discord" maxlength="40" value="<?= h((string)($pb['pub_discord'] ?? '')) ?>" placeholder="username" spellcheck="false"></label>
        <label class="wide">What they do<input name="about" maxlength="200" value="<?= h((string)($pb['pub_about'] ?? '')) ?>" placeholder="One line, like: Talks to developers and tests every beta."></label>
        <label>Order<input name="order" type="number" min="-99" max="99" value="<?= (int)($pb['pub_order'] ?? 0) ?>"></label>
        <div class="go"><button class="btn primary">Save</button></div>
      </form>
      <div class="member-actions">
        <form method="post" onsubmit="return confirm('Give <?= h((string)$t['username']) ?> a new temporary password? They\'ll be signed out.');"><?= $csrf ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= $tid ?>"><button class="btn">Reset password</button></form>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="signout"><input type="hidden" name="id" value="<?= $tid ?>"><button class="btn">Sign out everywhere</button></form>
        <?php if ((int)$t['twostep']): ?><form method="post" onsubmit="return confirm('Turn off two-step login for <?= h((string)$t['username']) ?>? Only if they lost their phone and codes.');"><?= $csrf ?><input type="hidden" name="action" value="2fa_off"><input type="hidden" name="id" value="<?= $tid ?>"><button class="btn">Turn off two-step</button></form><?php endif; ?>
        <?php if (!$mine): ?>
        <form method="post"><?= $csrf ?><input type="hidden" name="action" value="<?= $off ? 'on' : 'off' ?>"><input type="hidden" name="id" value="<?= $tid ?>"><button class="btn<?= $off ? ' primary' : '' ?>"><?= $off ? 'Switch on' : 'Switch off' ?></button></form>
        <?php endif; ?>
      </div>
      <?php if (!$mine): ?>
      <form method="post" class="member-remove"><?= $csrf ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= $tid ?>">
        <label>Remove for good: type <code><?= h((string)$t['username']) ?></code><input name="confirm" autocomplete="off"></label><button class="btn danger">Remove</button>
      </form>
      <?php endif; ?>
    </details>
  </article>
  <?php endforeach; ?>
  </div>
</section>
<?php rs_foot(['assets/security.js']);
