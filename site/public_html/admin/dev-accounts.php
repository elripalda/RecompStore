<?php
// RecompStore admin (2.7.2): developer accounts. Requests to approve (from invites you sent, or from
// recompstore.com/developers), invites, the developers who have a hub, changes they suggested for
// their app pages, and GitHub sign-in's setup.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_dev_tables();
$db = rs_db();

// A developer gets an email when something about their account happens (best effort).
$tell = function (array $acc, string $subject, array $blocks): void {
    if (!rs_mail_ready() || !filter_var((string)$acc['email'], FILTER_VALIDATE_EMAIL)) return;
    [$html, $text] = rs_mb_email($blocks, ['name' => $acc['name'] ?: ($acc['gh_login'] ?: 'there')], rs_mail_get('mail_sign'), "You're getting this because you have a developer account on RecompStore.");
    try { rs_mail_send((string)$acc['email'], $subject, $text, ['html' => $html, 'footer' => "You're getting this because you have a developer account on RecompStore.", 'save' => true]); } catch (Throwable $e) { rs_flash('Done, but the email to them didn\'t go out: ' . $e->getMessage(), 'err'); }
};
// (2.8) A change's app: a PS5 game, or "pc:<id>" for a PC recomp.
$appOf = fn(string $id): ?array => str_starts_with($id, 'pc:') ? rs_pc_app(substr($id, 3)) : rs_game($id);
$appEdit = fn(string $id): string => str_starts_with($id, 'pc:') ? 'pc.php?edit=' . rawurlencode(substr($id, 3)) . '#edit' : 'edit.php?id=' . rawurlencode($id);
$appPage = fn(string $id): string => str_starts_with($id, 'pc:') ? 'https://recomplauncher.com/game/' . rawurlencode(substr($id, 3)) : '/game/' . rawurlencode($id);
$account = function (int $id) use ($db): ?array {
    $st = $db->prepare('SELECT * FROM dev_accounts WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $back = 'dev-accounts.php';
    try {
        if ($action === 'github') {
            rs_set_setting('gh_client_id', preg_replace('/[^A-Za-z0-9._-]/', '', (string)($_POST['gh_client_id'] ?? '')) ?? '');
            if (trim((string)($_POST['gh_client_secret'] ?? '')) !== '') rs_secret_put('gh_client_secret', trim((string)$_POST['gh_client_secret']));
            rs_set_setting('dc_dev_role', preg_replace('/\D/', '', (string)($_POST['dc_dev_role'] ?? '')) ?? '');
            rs_log('settings', 'GitHub sign-in', 'saved');
            rs_flash(rs_dev_ready() ? 'Saved. Developers can sign in at recompstore.com/developers.' : 'Saved. It needs the Client ID and the Client Secret to work.');
            $back = 'dev-accounts.php#setup';
        } elseif ($action === 'approve') {
            $acc = rs_dev_approve($id, (string)($_POST['page'] ?? ''));
            rs_log('developers', 'approved', rs_dev_label($acc) . ' -> ' . $acc['slug']);
            $tell($acc, 'You\'re in: your RecompStore developer hub', [
                ['t' => 'heading', 'size' => 'big', 'text' => 'Welcome aboard, {name}'],
                ['t' => 'text', 'text' => "Your developer account on RecompStore is approved. Your page now has the Verified developer badge, and your hub is open: your numbers, your players' logs, your links and betas for your apps."],
                ['t' => 'button', 'label' => 'Open my developer hub', 'url' => 'https://recompstore.com/hub', 'style' => 'main'],
                ['t' => 'text', 'text' => 'Anything you need, just reply to this email.'],
            ]);
            rs_flash('Approved ' . ($acc['name'] ?: rs_dev_label($acc)) . '. Their Verified page is live at /dev/' . $acc['slug'] . '.');
        } elseif ($action === 'access') {
            // (3.1g) A sign-in link for a developer who can't get in yet: their email saved on the
            // account, and a link to set a password, good for 7 days. You press Send.
            $who = rs_dev_account($id);
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            if (!$who) throw new RuntimeException('That account is gone.');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException('That isn\'t an email address.');
            if (!rs_mail_ready()) throw new RuntimeException('Set up sending on the Mail page first.');
            $st = $db->prepare('SELECT id FROM dev_accounts WHERE LOWER(email) = ? AND id <> ? LIMIT 1');
            $st->execute([$email, $id]);
            if ($st->fetchColumn()) throw new RuntimeException('Another developer account already uses ' . $email . '.');
            $token = bin2hex(random_bytes(32));
            $db->prepare('UPDATE dev_accounts SET email = ?, mail_token = ?, mail_token_until = NOW() + INTERVAL 7 DAY WHERE id = ?')->execute([$email, hash('sha256', 'reset|' . $token), $id]);
            [$html, $text] = rs_mb_email([
                ['t' => 'text', 'text' => "Hi {name},\n\nYour RecompStore developer account is ready" . ((string)$who['slug'] !== '' ? ', with your Verified developer page' : '') . ". Set a password and you're in. The link works for 7 days."],
                ['t' => 'button', 'label' => 'Set my password', 'url' => rs_abs('/developers?reset=' . $token), 'style' => 'main'],
                ['t' => 'text', 'text' => "After that, sign in at recompstore.com/developers with this email.\n\nAnything you need, just reply to this email."],
            ], ['name' => $who['name'] ?: 'there'], rs_mail_get('mail_sign'), '');
            rs_mail_send($email, 'Your RecompStore developer account is ready', $text, ['html' => $html]);
            rs_log('dev-access', (string)$id, $email);
            rs_flash('Sent a sign-in link to ' . $email . '. It works for 7 days.');
        } elseif ($action === 'decline' || $action === 'remove') {
            // (3.6) Turned down with the reasons, in an email you write here: they can ask again later.
            $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
            if ($action === 'decline' && $body !== '') {
                $acc0 = $account($id);
                if (!$acc0) throw new RuntimeException('That account is gone.');
                if (!rs_mail_ready()) throw new RuntimeException('Set up the mailbox on the Mail page first.');
                if (!filter_var((string)$acc0['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('They have no email address to tell.');
                [$html, $text] = rs_mb_email([['t' => 'text', 'text' => $body]], ['name' => $acc0['name'] ?: ($acc0['gh_login'] ?: 'there')], rs_mail_get('mail_sign'), "You're getting this because you asked for a developer account on RecompStore.");
                rs_mail_send((string)$acc0['email'], mb_substr(trim((string)($_POST['subject'] ?? 'About your developer account')), 0, 200) ?: 'About your developer account', $text, ['html' => $html, 'save' => true]);
                rs_log('developers', 'declined', rs_dev_label($acc0) . ': ' . mb_substr((string)($_POST['reason'] ?? ''), 0, 300));
            }
            $db->prepare("UPDATE dev_accounts SET status = 'rejected', decided = NOW() WHERE id = ?")->execute([$id]);
            $db->prepare('DELETE FROM dev_sessions WHERE account_id = ?')->execute([$id]);
            rs_flash($action === 'remove' ? 'Their hub is closed. Their developer page stays until you remove it on the Developers page.' : 'Turned down. They can ask again later.');
        } elseif ($action === 'invite' || $action === 'invite-hold') {
            // (2.7.3) Set them up first: their page (picked, matched or made, with their GitHub picture
            // and link), then the invite, sent now or kept until you press Send.
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $name = mb_substr(trim((string)($_POST['who'] ?? '')), 0, 120);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('That isn\'t an email address.');
            $send = $action === 'invite';
            if ($send && !rs_mail_ready()) throw new RuntimeException('Set up the mailbox on the Mail page first.');
            $gh = trim((string)($_POST['gh'] ?? ''));
            $page = (string)($_POST['page'] ?? '');
            $setup = ($gh !== '' || $page !== '' || !empty($_POST['make'])) ? rs_dev_presetup($name, $gh, $page) : null;
            $url = rs_dev_invite_url($email, $name, $setup ? $setup['slug'] : '', !empty($_POST['approve']));
            $inv = rs_dev_invite(substr($url, -24));
            $db->prepare('UPDATE dev_invites SET held = ? WHERE id = ?')->execute([$send ? 0 : 1, $inv['id']]);
            if ($send) rs_dev_invite_send($inv);
            $msg = $setup ? ($setup['new'] ? 'Made their page /dev/' . $setup['slug'] : 'Their page is /dev/' . $setup['slug']) . ' (' . $setup['apps'] . ' ' . ($setup['apps'] === 1 ? 'app' : 'apps') . ' on it). ' : '';
            rs_flash($msg . ($send ? 'Invite sent to ' . $email . '.' : 'Invite for ' . $email . ' is ready. Press Send in the list when you want it to go.'));
            $back = 'dev-accounts.php#invite';
        } elseif ($action === 'dev-note') {
            // (2.7.3) A note to a developer about a change they made (you press Send).
            $st = $db->prepare('SELECT c.*, a.email, a.name AS acc_name FROM dev_changes c JOIN dev_accounts a ON a.id = c.account_id WHERE c.id = ?');
            $st->execute([$id]);
            $c = $st->fetch();
            $note = trim(str_replace("\r\n", "\n", (string)($_POST['note'] ?? '')));
            if (!$c || $note === '') throw new RuntimeException('Write a note first.');
            if (!filter_var((string)$c['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('There\'s no email for this developer.');
            if (!rs_mail_ready()) throw new RuntimeException('Set up the mailbox on the Mail page first.');
            $g = $appOf((string)$c['app_id']);
            $blocks = [['t' => 'text', 'text' => "Hi {name},\n\n" . $note], ['t' => 'button', 'label' => 'Open my developer hub', 'url' => 'https://recompstore.com/hub', 'style' => 'plain']];
            [$html, $text] = rs_mb_email($blocks, ['name' => $c['acc_name'] ?: 'there'], rs_mail_get('mail_sign'), '');
            $subj = 'About your ' . ($g['name'] ?? 'app') . ' page on RecompStore';
            rs_mail_send((string)$c['email'], $subj, $text, ['html' => $html, 'save' => true]);
            rs_mail_log('reply', (string)$c['email'], $subj, $note, '');
            $db->prepare("UPDATE dev_changes SET message = CONCAT(COALESCE(message, ''), ?) WHERE id = ?")->execute(["\n[You] " . $note, $id]);
            rs_flash('Note sent to ' . $c['email'] . '.');
            $back = 'dev-accounts.php#edits';
        } elseif ($action === 'invite-send') {
            if (!rs_mail_ready()) throw new RuntimeException('Set up the mailbox on the Mail page first.');
            $st = $db->prepare('SELECT * FROM dev_invites WHERE id = ? AND used_by IS NULL');
            $st->execute([$id]);
            $inv = $st->fetch();
            if (!$inv) throw new RuntimeException('That invite is gone or used.');
            rs_dev_invite_send($inv);
            rs_flash('Invite sent to ' . $inv['email'] . '.');
            $back = 'dev-accounts.php#invite';
        } elseif ($action === 'weekly') {
            rs_set_setting('dev_weekly', !empty($_POST['on']) ? '1' : '0');
            rs_flash(!empty($_POST['on']) ? 'The weekly email goes out on Mondays.' : 'The weekly email is off.');
            $back = 'dev-accounts.php#weekly';
        } elseif ($action === 'weekly-now') {
            [$sent, $skipped, $err] = rs_dev_weekly_send();
            rs_flash('Weekly email: ' . $sent . ' sent' . ($skipped ? ', ' . $skipped . ' with no apps yet skipped' : '') . ($err ? '. Didn\'t go: ' . implode('; ', array_slice($err, 0, 3)) : '.'), $err ? 'err' : 'ok');
            $back = 'dev-accounts.php#weekly';
        } elseif ($action === 'uninvite') {
            $db->prepare('DELETE FROM dev_invites WHERE id = ? AND used_by IS NULL')->execute([$id]);
            $back = 'dev-accounts.php#invite';
        } elseif ($action === 'claim-ok' || $action === 'claim-no') {
            // (3.0) A developer says an app or recomp is theirs, but its GitHub isn't.
            $st = $db->prepare("SELECT * FROM dev_claims WHERE id = ? AND status = 'pending'");
            $st->execute([$id]);
            $cl = $st->fetch();
            if (!$cl) throw new RuntimeException('That claim was already handled.');
            $acc = $account((int)$cl['account_id']);
            if (!$acc) throw new RuntimeException('That developer is gone.');
            if ($action === 'claim-ok' && $acc['status'] !== 'approved') throw new RuntimeException('Their hub isn\'t open, so it can\'t go on their page. Turn it down, or approve them first.');
            $g = $appOf((string)$cl['app_id']);
            if ($action === 'claim-ok') {
                rs_dev_claim_apply($acc, (string)$cl['app_id']);
                rs_log('developers', 'claim', 'gave ' . $cl['app_id'] . ' to ' . rs_dev_label($acc));
            }
            $db->prepare('UPDATE dev_claims SET status = ?, decided = NOW() WHERE id = ?')->execute([$action === 'claim-ok' ? 'approved' : 'rejected', $id]);
            // They hear about it in their hub's messages.
            rs_dev_msg_add('dm', (int)$acc['id'], true, $action === 'claim-ok'
                ? ($g['name'] ?? $cl['app_id']) . ' is on your developer page now.' . (str_starts_with((string)$cl['app_id'], 'pc:') ? '' : ' Your name shows on it with the next store update.')
                : 'We couldn\'t confirm that ' . ($g['name'] ?? $cl['app_id']) . ' is yours, so it stays as it is for now. Reply here if we got it wrong.');
            rs_flash($action === 'claim-ok' ? ($g['name'] ?? $cl['app_id']) . ' is on ' . ($acc['name'] ?: rs_dev_label($acc)) . '\'s page now.' . (str_starts_with((string)$cl['app_id'], 'pc:') ? '' : ' Press Publish when you\'re ready.') : 'Turned down. They got a message in their hub.');
            $back = 'dev-accounts.php#claims';
        } elseif ($action === 'edit-undo') {
            // (3.8) Undo a change a developer made: what was there before goes back on the page.
            $st = $db->prepare("SELECT * FROM dev_changes WHERE id = ? AND status IN ('live', 'approved')");
            $st->execute([$id]);
            $ch = $st->fetch();
            if (!$ch) throw new RuntimeException('That change was already undone.');
            $data = json_decode((string)$ch['data'], true) ?: [];
            $before = (array)($data['_before'] ?? []);
            if (!$before) throw new RuntimeException('That change is from before Undo was there, so there\'s nothing to put back. Change it on its edit page.');
            $pc = str_starts_with((string)$ch['app_id'], 'pc:');
            $g = $appOf((string)$ch['app_id']);
            if (!$g || !$pc) throw new RuntimeException('That recomp isn\'t in the admin anymore.');
            foreach (RS_DEV_PC_FIELDS as $k => $max) if (array_key_exists($k, $before)) $g[$k] = (string)$before[$k];
            foreach ((array)($before['art'] ?? []) as $k => $path) if (in_array($k, RS_DEV_PC_ART, true)) $g[$k] = (string)$path;
            rs_pc_save($g);
            rs_pc_publish_one($g['id']);
            $db->prepare("UPDATE dev_changes SET status = 'undone', decided = NOW() WHERE id = ?")->execute([$id]);
            rs_log('developers', 'undo', 'undid ' . implode(', ', array_keys(array_diff_key($data, ['_before' => 1]))) . ' on ' . $g['id']);
            rs_flash('Undone. ' . $g['name'] . '\'s page is back to how it was.');
            $back = 'dev-accounts.php#edits';
        } elseif ($action === 'change-ok' || $action === 'change-no') {
            $st = $db->prepare("SELECT * FROM dev_changes WHERE id = ? AND status = 'pending'");
            $st->execute([$id]);
            $ch = $st->fetch();
            if (!$ch) throw new RuntimeException('That change was already handled.');
            $acc = $account((int)$ch['account_id']);
            $pc = str_starts_with((string)$ch['app_id'], 'pc:');
            $g = $appOf((string)$ch['app_id']);
            if ($action === 'change-ok') {
                if (!$g) throw new RuntimeException('That app isn\'t in the admin anymore.');
                $data = json_decode((string)$ch['data'], true) ?: [];
                if ($pc) {
                    // A PC recomp's change goes on its page straight away.
                    foreach (RS_DEV_PC_FIELDS as $k => $max) if (array_key_exists($k, $data)) $g[$k] = mb_substr((string)$data[$k], 0, $max);
                    // (4.0) A move to another repo of theirs: the listing follows it, its next release is checked first.
                    if (!empty($data['move']['repo'])) $g = rs_pc_switch_repo($g, (string)$data['move']['repo']);
                    if (array_key_exists('features', $data)) { $g['features_read'] = true; $g['features_v'] = 2; }
                    // (3.8) Their new pictures. The old ones are kept in the change, so Undo can put them back.
                    $was = [];
                    foreach ((array)($data['art'] ?? []) as $k => $path) {
                        if (!in_array($k, RS_DEV_PC_ART, true) || !preg_match('#^/media/pc-#', (string)$path)) continue;
                        $was[$k] = (string)($g[$k] ?? '');
                        $g[$k] = (string)$path;
                        $g[$k . '_src'] = 'developer';
                    }
                    if ($was) $db->prepare('UPDATE dev_changes SET data = ? WHERE id = ?')->execute([json_encode($data + ['_before' => ['art' => $was]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $id]);
                    rs_pc_save($g);
                    rs_pc_publish_one($g['id']);
                } else {
                    foreach (RS_DEV_CHANGE_FIELDS as $k => $max) if (array_key_exists($k, $data)) $g[$k] = $k === 'video' ? rs_youtube_id((string)$data[$k]) : mb_substr((string)$data[$k], 0, $max);
                    rs_save_game($g, null);
                }
                rs_log('developers', 'change', 'applied ' . implode(', ', array_keys($data)) . ' for ' . $g['id'] . ' from ' . ($acc ? rs_dev_label($acc) : '?'));
            }
            if ($action === 'change-no') rs_dev_pc_art_drop((array)((json_decode((string)$ch['data'], true) ?: [])['art'] ?? []));
            $db->prepare('UPDATE dev_changes SET status = ?, decided = NOW() WHERE id = ?')->execute([$action === 'change-ok' ? 'approved' : 'rejected', $id]);
            // (3.8) Pictures: they hear back in their hub's messages either way.
            if ($acc && $pc && !empty((json_decode((string)$ch['data'], true) ?: [])['art'])) rs_dev_msg_add('dm', (int)$acc['id'], true, $action === 'change-ok'
                ? 'Your new pictures for ' . ($g['name'] ?? $ch['app_id']) . ' are on its page now.'
                : 'We didn\'t use the new pictures you sent for ' . ($g['name'] ?? $ch['app_id']) . '. Reply here if you\'d like to know why or try others.');
            if ($acc && $action === 'change-ok' && !($pc && !empty((json_decode((string)$ch['data'], true) ?: [])['art']))) $tell($acc, 'Your changes to ' . ($g['name'] ?? $ch['app_id']) . ' are approved', [
                ['t' => 'text', 'text' => "Hi {name},\n\nYour changes to " . ($g['name'] ?? $ch['app_id']) . "'s page are approved. They show up in the store and on the site with the next update."],
            ]);
            rs_flash($action === 'change-ok' ? 'Applied to ' . ($g['name'] ?? $ch['app_id']) . ($pc ? '. It\'s on its page now.' : '. Press Publish when you\'re ready.') : 'Turned down.');
            $back = 'dev-accounts.php#changes';
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

if (!is_file(RS_PUBLIC . '/developers.json')) rs_dev_write_public();
$rows = $db->query("SELECT * FROM dev_accounts WHERE status IN ('pending', 'approved') ORDER BY FIELD(status, 'pending', 'approved'), COALESCE(requested, created) DESC")->fetchAll();
$pending = array_values(array_filter($rows, fn($r) => $r['status'] === 'pending'));
$approved = array_values(array_filter($rows, fn($r) => $r['status'] === 'approved'));
$invites = $db->query('SELECT i.*, a.gh_login, a.email AS acc_email FROM dev_invites i LEFT JOIN dev_accounts a ON a.id = i.used_by ORDER BY i.id DESC LIMIT 50')->fetchAll();
$changes = $db->query("SELECT c.*, a.gh_login, a.name FROM dev_changes c JOIN dev_accounts a ON a.id = c.account_id WHERE c.status = 'pending' ORDER BY c.id")->fetchAll();
$edits = $db->query("SELECT c.*, a.gh_login, a.name, a.email FROM dev_changes c JOIN dev_accounts a ON a.id = c.account_id WHERE c.status = 'live' OR (c.status = 'approved' AND c.app_id LIKE 'pc:%' AND c.data LIKE '%\"_before\"%') ORDER BY c.id DESC LIMIT 30")->fetchAll();
$claims = $db->query("SELECT c.*, a.gh_login, a.name, a.slug, a.status AS acc_status FROM dev_claims c JOIN dev_accounts a ON a.id = c.account_id WHERE c.status = 'pending' ORDER BY c.id")->fetchAll();
$plats = fn(array $r) => implode('', array_map(fn($p) => '<span class="pill">' . h($p) . '</span>', rs_dev_platform_labels((string)($r['platforms'] ?? ''))));
$gh = rs_gh_oauth();
$allPages = rs_developers();
// Their GitHub, or that they signed up with an email.
$who = fn(array $r) => (string)$r['gh_login'] !== ''
    ? '<a href="https://github.com/' . h(rawurlencode((string)$r['gh_login'])) . '" target="_blank" rel="noopener noreferrer">github.com/' . h((string)$r['gh_login']) . '</a>'
    : '<span class="pill">Email account</span>';
$pic = fn(array $r, int $n) => (string)$r['avatar'] !== ''
    ? '<img class="dev-req-pic" src="' . h((string)$r['avatar']) . '" alt="" width="' . $n . '" height="' . $n . '">'
    : '<span class="dev-req-pic dev-req-letter" style="width:' . $n . 'px;height:' . $n . 'px">' . h(mb_strtoupper(mb_substr((string)($r['name'] ?: $r['email']), 0, 1))) . '</span>';
$when = fn(?string $d) => $d ? rs_date('M j, Y', strtotime($d)) : '';
$fieldNames = ['name' => 'Name', 'tags' => 'Tags', 'setup_steps' => 'Setup steps', 'art' => 'Pictures', 'blurb' => 'One-line summary', 'description' => 'Description', 'news' => 'What\'s new', 'needs' => 'What players need', 'credits' => 'Credits', 'video' => 'Video', 'features' => 'What\'s in it', 'media' => 'Around the web'];

rs_head('Developer accounts');
?>
<section class="head-row">
  <div>
    <h1>Developer accounts</h1>
    <p class="muted">Developers sign in with GitHub (or an email and password) at <a href="/developers" target="_blank">recompstore.com/developers</a> and ask for a hub, or follow an invite you sent. Nobody gets in until you approve them here. Approving gives them a Verified developer page they look after themselves.</p>
  </div>
  <div class="actions"><a class="btn" href="developers.php">Developer pages</a></div>
</section>

<section class="card" id="requests">
  <h2>Waiting for you <?= $pending ? '<span class="count">' . count($pending) . '</span>' : '' ?></h2>
  <?php if (!$pending): ?><p class="muted">No requests right now.</p><?php endif; ?>
  <?php foreach ($pending as $r): $apps = rs_dev_apps($r); ?>
  <div class="dev-req">
    <?= $pic($r, 56) ?>
    <div class="dev-req-words">
      <strong><?= h($r['name'] ?: $r['email']) ?></strong> <?= $who($r) ?>
      <?= $r['invite_id'] !== null ? '<span class="pill ok">Invited</span>' : '<span class="pill">Asked on the site</span>' ?><?= $plats($r) ?: ' <span class="pill">PC or PS5 not said yet</span>' ?>
      <div class="dev-req-links"><?= (string)$r['gh_login'] !== '' ? '<span class="pill ok">GitHub connected</span>' : '<span class="pill">No GitHub yet</span>' ?><?= $r['slug'] !== '' ? ' <span class="pill ok">Page: /dev/' . h($r['slug']) . '</span>' : '' ?><?= $r['discord_name'] !== '' ? ' <span class="pill ok">Discord @' . h($r['discord_name']) . '</span>' : '' ?></div>
      <div class="muted"><?= h($r['email']) ?> · asked <?= h($when($r['requested'] ?? $r['created'])) ?></div>
      <?php if (trim((string)$r['note']) !== ''): ?><p class="body"><?= nl2br(h((string)$r['note'])) ?></p><?php endif; ?>
      <div class="muted">Their apps in the store: <?= $apps ? h(implode(', ', array_map(fn($g) => $g['name'], $apps))) : ((string)$r['gh_login'] !== '' ? 'none found on their GitHub yet' : 'none yet (no GitHub on this account, so if they already have a developer page, pick it under Their page)') ?></div>
    </div>
    <form method="post" class="dev-req-act"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <label class="dev-req-page">Their page<select name="page"><option value="">Match or make one for them</option><?php foreach ($allPages as $ps => $pd): ?><option value="<?= h($ps) ?>"<?= $r['slug'] === $ps ? ' selected' : '' ?>><?= h($pd['name']) ?> (/dev/<?= h($ps) ?>)</option><?php endforeach; ?></select></label>
      <button name="action" value="approve" class="primary">Approve</button>
    </form>
    <details class="dev-decline" data-decline>
      <summary>Turn down · tell them why</summary>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="decline"><input type="hidden" name="reason" value="">
        <div class="dec-presets"><?php foreach ([
          ['No GitHub history', 'Your GitHub doesn\'t have much history yet. We verify developers with some public work and activity behind them.'],
          ['No projects yet', 'We couldn\'t find a released project of yours yet. Once you have one out with a release on GitHub, ask again.'],
          ['GitHub not connected', 'Your account isn\'t connected to GitHub, so we couldn\'t see your work. Connect it from your hub and ask again.'],
          ['Missing information', 'Some information is missing from your request. Tell us what you make and link your projects.'],
          ['Couldn\'t confirm it\'s yours', 'We couldn\'t confirm that the projects you named are yours. Ask again from the GitHub account that owns them.'],
          ['Not a fit', 'Developer accounts are for people making recomps, ports, emulators and homebrew for the store, so this one isn\'t a fit.'],
        ] as [$k, $v]): ?><label class="check"><input type="checkbox" data-why="<?= h($v) ?>" data-short="<?= h($k) ?>"> <?= h($k) ?></label><?php endforeach; ?></div>
        <label>Anything else <span class="muted">(optional)</span><textarea data-own rows="2" maxlength="1000"></textarea></label>
        <label>Subject<input name="subject" maxlength="200" value="About your RecompStore developer account"></label>
        <label>Message<textarea name="body" rows="8" data-hello="Hi {name}," data-intro="Thanks for asking for a developer account on RecompStore. We can't approve it right now:" data-outro="You're welcome to ask again once that's sorted. Just reply to this email if you have questions."></textarea><small>Leave it empty to turn them down without an email.</small></label>
        <button class="danger">Turn down<?= rs_mail_ready() ? ' and send' : '' ?></button>
      </form>
    </details>
  </div>
  <?php endforeach; ?>
</section>

<section class="card" id="changes">
  <h2>App page changes to review <?= $changes ? '<span class="count">' . count($changes) . '</span>' : '' ?></h2>
  <?php if (!$changes): ?><p class="muted">Nothing waiting.</p><?php endif; ?>
  <?php foreach ($changes as $c): $g = $appOf((string)$c['app_id']); $data = json_decode((string)$c['data'], true) ?: []; ?>
  <div class="dev-change">
    <p><strong><?= h($g['name'] ?? $c['app_id']) ?></strong><?= str_starts_with((string)$c['app_id'], 'pc:') ? ' <span class="pill">PC</span>' : '' ?> <span class="muted">from <?= h($c['name'] ?: $c['gh_login']) ?> · <?= h($when($c['created'])) ?></span></p>
    <?php if (trim((string)$c['message']) !== ''): ?><p class="body"><em><?= nl2br(h((string)$c['message'])) ?></em></p><?php endif; ?>
    <?php foreach ($data as $k => $v): if ($k === '_before') continue; ?>
    <?php if ($k === 'move'): ?>
    <div class="dev-diff"><span class="lbl">Move to another repo</span>
      <div class="dev-diff-cols"><div><span class="muted">Now</span><p><a href="https://github.com/<?= h((string)($g['repo'] ?? '')) ?>" target="_blank" rel="noopener"><?= h((string)($g['repo'] ?? '')) ?></a></p></div>
        <div><span class="muted">Asks for</span><p><a href="https://github.com/<?= h((string)$v['repo']) ?>" target="_blank" rel="noopener"><?= h((string)$v['repo']) ?></a> <span class="muted">(theirs; its next release is checked before it goes out)</span></p></div></div>
    </div>
    <?php continue; endif; ?>
    <?php if ($k === 'art'): foreach ((array)$v as $ak => $path): ?>
    <div class="dev-diff"><span class="lbl"><?= h(['cover' => 'Cover', 'hero' => 'Backdrop', 'logo' => 'Logo', 'icon' => 'Icon'][$ak] ?? $ak) ?></span>
      <div class="dev-diff-cols"><div><span class="muted">Now</span><p><?= (string)($g[$ak] ?? '') !== '' ? '<img class="dev-art dev-art-' . h($ak) . '" src="' . h((string)$g[$ak]) . '" alt="">' : '<span class="muted">none</span>' ?></p></div>
        <div><span class="muted">New</span><p><a href="<?= h((string)$path) ?>" target="_blank"><img class="dev-art dev-art-<?= h($ak) ?>" src="<?= h((string)$path) ?>" alt=""></a></p></div></div>
    </div>
    <?php endforeach; continue; endif; ?>
    <div class="dev-diff"><span class="lbl"><?= h($fieldNames[$k] ?? $k) ?></span>
      <div class="dev-diff-cols"><div><span class="muted">Now</span><p><?= nl2br(h((string)($g[$k] ?? ''))) ?: '<span class="muted">empty</span>' ?></p></div><div><span class="muted">Suggested</span><p><?= nl2br(h((string)$v)) ?: '<span class="muted">empty</span>' ?></p></div></div>
    </div>
    <?php endforeach; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <button name="action" value="change-ok" class="primary">Apply it</button> <button name="action" value="change-no" class="danger">Turn down</button>
    </form>
  </div>
  <?php endforeach; ?>
</section>

<section class="card" id="claims">
  <h2>Claims to check <?= $claims ? '<span class="count">' . count($claims) . '</span>' : '' ?></h2>
  <p class="muted">A developer says an app or recomp is theirs, but its GitHub repo isn't under their account. Ones that are theirs by GitHub go on their page by themselves.</p>
  <?php if (!$claims): ?><p class="muted">Nothing waiting.</p><?php endif; ?>
  <?php foreach ($claims as $c): $g = $appOf((string)$c['app_id']); ?>
  <div class="dev-change">
    <p><strong><a href="<?= h($appEdit((string)$c['app_id'])) ?>"><?= h($g['name'] ?? $c['app_id']) ?></a></strong><?= str_starts_with((string)$c['app_id'], 'pc:') ? ' <span class="pill">PC</span>' : ' <span class="pill">PS5</span>' ?>
      <span class="muted"><?= $g && (string)$g['repo'] !== '' ? 'github.com/' . h((string)$g['repo']) . ' · ' : '' ?><?= ($own = rs_dev_app_owner((string)$c['app_id'], $g)) !== '' ? 'now on <a href="/dev/' . h(rawurlencode($own)) . '" target="_blank">' . h(rs_developers()[$own]['name'] ?? $own) . '\'s page</a> · ' : '' ?>claimed by <?= h($c['name'] ?: $c['gh_login']) ?><?= $c['acc_status'] !== 'approved' ? ' <span class="pill">Hub not open</span>' : '' ?><?= (string)$c['gh_login'] !== '' ? ' (github.com/' . h((string)$c['gh_login']) . ')' : '' ?> · <?= h($when($c['created'])) ?></span></p>
    <?php if (trim((string)$c['note']) !== ''): ?><p class="body"><em><?= nl2br(h((string)$c['note'])) ?></em></p><?php endif; ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <button name="action" value="claim-ok" class="primary">Put it on their page</button> <button name="action" value="claim-no" class="danger">Turn down</button>
      <a class="btn" href="messages.php?dev=<?= (int)$c['account_id'] ?>">Message them</a>
    </form>
  </div>
  <?php endforeach; ?>
</section>

<section class="card" id="edits">
  <h2>Changes developers made</h2>
  <p class="muted">Verified developers change their own app pages. On PS5 apps: words, pictures, screenshots and video, out with your next Publish. On PC recomps: the words go live at once, and new pictures wait under App page changes to review until you approve them. You get an email for each one. PC changes can be undone here. Want something done differently? Send them a note.</p>
<style>.dev-art{max-width:100%;max-height:180px;border-radius:8px;background:rgba(var(--ink-rgb),.04)}.dev-art-cover{max-height:220px}.dev-art-icon{max-height:96px}</style>
  <?php if (!$edits): ?><p class="muted">Nothing yet.</p><?php endif; ?>
  <?php foreach ($edits as $c): $g = $appOf((string)$c['app_id']); $data = json_decode((string)$c['data'], true) ?: []; ?>
  <div class="dev-change">
    <p><strong><a href="<?= h($appEdit((string)$c['app_id'])) ?>"><?= h($g['name'] ?? $c['app_id']) ?></a></strong><?= str_starts_with((string)$c['app_id'], 'pc:') ? ' <span class="pill">PC</span>' : '' ?> <span class="muted">by <?= h($c['name'] ?: $c['gh_login']) ?> · <?= h($when($c['created'])) ?> · <a href="<?= h($appPage((string)$c['app_id'])) ?>" target="_blank">its page</a></span></p>
    <p class="muted"><?= h(implode(', ', array_map(fn($k) => $fieldNames[$k] ?? ['icon' => 'Icon', 'hero' => 'Backdrop', 'logo' => 'Logo', 'screens' => 'Screenshots'][$k] ?? $k, array_keys(array_diff_key($data, ['_before' => 1]))))) ?></p>
    <?php if (!empty($data['_before'])): ?>
    <details><summary>What changed</summary>
      <?php foreach (array_diff_key($data, ['_before' => 1, 'art' => 1]) as $k => $v): ?>
      <div class="dev-diff"><span class="lbl"><?= h($fieldNames[$k] ?? $k) ?></span>
        <div class="dev-diff-cols"><div><span class="muted">Before</span><p><?= nl2br(h((string)($data['_before'][$k] ?? ''))) ?: '<span class="muted">empty</span>' ?></p></div><div><span class="muted">Now</span><p><?= nl2br(h((string)$v)) ?: '<span class="muted">empty</span>' ?></p></div></div>
      </div>
      <?php endforeach; ?>
      <?php foreach ((array)($data['art'] ?? []) as $ak => $path): ?>
      <div class="dev-diff"><span class="lbl"><?= h(['cover' => 'Cover', 'hero' => 'Backdrop', 'logo' => 'Logo', 'icon' => 'Icon'][$ak] ?? $ak) ?></span>
        <div class="dev-diff-cols"><div><span class="muted">Before</span><p><?= (string)($data['_before']['art'][$ak] ?? '') !== '' ? '<img class="dev-art dev-art-' . h($ak) . '" src="' . h((string)$data['_before']['art'][$ak]) . '" alt="">' : '<span class="muted">none</span>' ?></p></div><div><span class="muted">Now</span><p><img class="dev-art dev-art-<?= h($ak) ?>" src="<?= h((string)$path) ?>" alt=""></p></div></div>
      </div>
      <?php endforeach; ?>
    </details>
    <form method="post" onsubmit="return confirm('Put this page back to how it was before this change?');"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button name="action" value="edit-undo" class="danger">Undo this change</button></form>
    <?php endif; ?>
    <?php if (trim((string)$c['message']) !== ''): ?><p class="body"><em><?= nl2br(h(trim((string)$c['message']))) ?></em></p><?php endif; ?>
    <details><summary>Send them a note</summary>
      <form method="post" class="dev-note"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <textarea name="note" rows="3" placeholder="Looks great! One idea: a wider backdrop would fill the banner better."></textarea>
        <button name="action" value="dev-note" class="primary"<?= rs_mail_ready() && filter_var((string)$c['email'], FILTER_VALIDATE_EMAIL) ? '' : ' disabled' ?>>Send the note</button></form></details>
  </div>
  <?php endforeach; ?>
</section>

<section class="card" id="invite">
  <h2>Set up and invite a developer</h2>
  <p class="muted">Get their page ready first: pick the page their apps are on, or type their GitHub username and their page is made or matched for you, with their GitHub picture and link, and their apps on it. Then keep the invite for later or send it now. They make their account with the link (GitHub or email) and show up under Waiting for you, already linked to their page, for you to look over and approve. Tick "Approve them as soon as they accept" to skip that step.</p>
  <form method="post" class="filters dev-invite-form">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <label>Email<input name="email" type="email" required></label>
    <label>Their name<input name="who" maxlength="120" placeholder="How the email greets them"></label>
    <label>GitHub username <span class="muted">(optional)</span><input name="gh" maxlength="39" placeholder="griches" autocomplete="off"></label>
    <label>Their page<select name="page"><option value="">Match by name or GitHub, or make one</option><?php foreach ($allPages as $ps => $pd): ?><option value="<?= h($ps) ?>"><?= h($pd['name']) ?> (/dev/<?= h($ps) ?>)</option><?php endforeach; ?></select></label>
    <label class="check"><input type="checkbox" name="make" value="1" checked> Set up their page now</label>
    <label class="check"><input type="checkbox" name="approve" value="1"> Approve them as soon as they accept</label>
    <button name="action" value="invite-hold">Set up, send later</button>
    <button name="action" value="invite" class="primary"<?= rs_mail_ready() ? '' : ' disabled title="Set up the mailbox on the Mail page first"' ?>>Set up and send</button>
  </form>
  <?php if ($invites): ?>
  <div class="table-wrap"><table class="codes">
    <thead><tr><th>For</th><th>Name</th><th>Page</th><th>Made</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($invites as $i): $held = !empty($i['held']) && $i['used_by'] === null; ?><tr><td><?= h($i['email']) ?></td><td><?= h($i['name']) ?></td>
      <td><?= (string)($i['slug'] ?? '') !== '' ? '<a href="/dev/' . h(rawurlencode((string)$i['slug'])) . '" target="_blank">/dev/' . h((string)$i['slug']) . '</a>' : '<span class="muted">none yet</span>' ?></td>
      <td class="muted"><?= h($when($i['created'])) ?></td>
      <td><?= $i['used_by'] !== null ? '<span class="status-pill used">Used by ' . h((string)$i['gh_login'] !== '' ? '@' . $i['gh_login'] : (string)$i['acc_email']) . '</span>' : ($held ? '<span class="status-pill">Not sent yet</span>' : '<span class="status-pill">Sent, not used yet</span>') ?><?= !empty($i['approve']) && $i['used_by'] === null ? ' <span class="pill ok">Approves on accept</span>' : '' ?></td>
      <td class="invite-act"><?php if ($i['used_by'] === null): ?><input class="copy-link" readonly value="https://recompstore.com/developers?invite=<?= h($i['code']) ?>" onclick="this.select()" title="Their invite link">
        <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><?php if ($held): ?><button name="action" value="invite-send" class="primary"<?= rs_mail_ready() ? '' : ' disabled' ?>>Send</button> <?php endif; ?><button name="action" value="uninvite" class="danger">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="card" id="hubs">
  <h2>Developers with a hub</h2>
  <?php if (!$approved): ?><p class="muted">Nobody yet.</p><?php endif; ?>
  <?php foreach ($approved as $r): ?>
  <div class="dev-req">
    <?= $pic($r, 44) ?>
    <div class="dev-req-words"><strong><?= h($r['name'] ?: $r['email']) ?></strong> <a href="/dev/<?= h(rawurlencode($r['slug'])) ?>" target="_blank">/dev/<?= h($r['slug']) ?></a><?= $plats($r) ?>
      <div class="muted"><?= (string)$r['gh_login'] !== '' ? 'github.com/' . h($r['gh_login']) : h($r['email']) ?><?= $r['discord_name'] !== '' ? ' · Discord @' . h($r['discord_name']) : '' ?> · last here <?= h($when($r['last_seen'])) ?></div>
      <details class="dev-access"><summary class="muted small">Send a sign-in link</summary>
        <form method="post" class="actions"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <input name="email" type="email" maxlength="190" value="<?= h((string)$r['email']) ?>" placeholder="Their email" required aria-label="Their email">
          <button name="action" value="access"<?= rs_mail_ready() ? '' : ' disabled title="Set up sending on the Mail page first"' ?>>Send the link</button></form>
        <p class="small muted">Saves this email on their account and emails them a link to set a password (good for 7 days). Then they sign in at recompstore.com/developers with it.</p>
      </details></div>
    <form method="post" class="dev-req-act"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
      <a class="btn" href="messages.php?dev=<?= (int)$r['id'] ?>">Message</a>
      <button name="action" value="remove" class="danger" onclick="return confirm('Close <?= h(addslashes((string)($r['name'] ?: $r['email']))) ?>\'s hub?')">Close their hub</button></form>
  </div>
  <?php endforeach; ?>
</section>

<section class="card" id="weekly">
  <h2>Weekly email</h2>
  <p class="muted">Every Monday, each developer with a hub gets their apps' week: on PS5s, new installs, GitHub downloads and how many logs players sent. They can turn it off in their hub.</p>
  <?php $wkLast = rs_kv_get('dev_weekly_at'); ?>
  <form method="post" class="row-form"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="weekly">
    <label class="check"><input type="checkbox" name="on" value="1"<?= rs_setting('dev_weekly', '1') === '1' ? ' checked' : '' ?> onchange="this.form.submit()"> Send it on Mondays</label>
    <span class="muted"><?= $wkLast ? 'Last sent ' . h($when(gmdate('Y-m-d H:i:s', time() - $wkLast['age']))) : 'Not sent yet' ?></span></form>
  <form method="post" onsubmit="return confirm('Send this week\'s email to every developer who gets it, now?')"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <button name="action" value="weekly-now"<?= rs_mail_ready() ? '' : ' disabled title="Set up the mailbox on the Mail page first"' ?>>Send it now</button></form>
</section>

<section class="card" id="setup">
  <h2>GitHub sign-in <?= rs_dev_ready() ? '<span class="status-pill used">On</span>' : '<span class="status-pill off">Not set up</span>' ?></h2>
  <details<?= rs_dev_ready() ? '' : ' open' ?>><summary class="muted">How to set it up</summary>
    <ol class="muted">
      <li>Go to <a href="https://github.com/settings/developers" target="_blank" rel="noopener">github.com/settings/developers</a>, OAuth Apps, New OAuth App.</li>
      <li>Name: RecompStore. Homepage URL: <code>https://recompstore.com</code>. Authorization callback URL: <code><?= h(rs_dev_redirect()) ?></code></li>
      <li>Register it, then copy the Client ID, press Generate a new client secret, and paste both below. You can upload the RecompStore logo there too, so the sign-in screen shows it.</li>
    </ol>
  </details>
  <form method="post" class="filters" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="github">
    <label>Client ID<input name="gh_client_id" value="<?= h($gh['client']) ?>"></label>
    <label>Client Secret<input name="gh_client_secret" type="password" autocomplete="new-password" placeholder="<?= $gh['secret'] !== '' ? 'Saved. Paste a new one to change it' : 'From GitHub' ?>"></label>
    <label>Discord role for developers <span class="muted">(optional)</span><input name="dc_dev_role" value="<?= h((string)rs_setting('dc_dev_role', '')) ?>" placeholder="Role ID" inputmode="numeric"><small>Given when an approved developer links Discord (needs the bot from Beta codes).</small></label>
    <button class="primary">Save</button>
  </form>
</section>
<?php ?>
<style>
.dev-decline{margin-top:10px;grid-column:1/-1}
.dev-decline summary{cursor:pointer;color:var(--bad)}
.dev-decline form{display:grid;gap:10px;margin-top:10px}
.dec-presets{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px 16px}
@media (max-width:820px){.dec-presets{grid-template-columns:1fr}}
</style>
<script>
// (3.6) Turn down: the reasons you tick become the email, which stays yours to change.
document.querySelectorAll('[data-decline]').forEach((box) => {
  const f = box.querySelector('form'), body = f.querySelector('textarea[name=body]'), own = f.querySelector('[data-own]');
  let touched = false;
  body.addEventListener('input', () => { touched = true; });
  const build = () => {
    const picked = [...f.querySelectorAll('[data-why]:checked')];
    f.reason.value = [...picked.map((c) => c.dataset.short), own.value.trim()].filter(Boolean).join(' · ');
    if (touched) return;
    const lines = picked.map((c) => '- ' + c.dataset.why);
    if (own.value.trim()) lines.push('- ' + own.value.trim());
    body.value = lines.length ? body.dataset.hello + '\n\n' + body.dataset.intro + '\n\n' + lines.join('\n') + '\n\n' + body.dataset.outro : '';
  };
  f.addEventListener('change', build);
  own.addEventListener('input', build);
});
</script>
<?php
rs_foot();
