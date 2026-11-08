<?php
// RecompStore admin (4.0): the community side. The Discord server (a Developer role in its own color
// and developer-only channels, made by the site's bot), PC release posts on Discord, and team notes
// pinned on top of the developer hub and/or the site.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_notes_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $back = 'community.php';
    try {
        if ($act === 'dc-setup') {
            $st = rs_dc_server_state();
            if (!$st['ready']) throw new RuntimeException($st['why']);
            $done = rs_dc_server_setup();
            [$given, $none] = rs_dc_sync_devs();
            rs_flash(implode(' ', $done) . ' Gave the role to ' . $given . ($given === 1 ? ' developer' : ' developers') . ($none ? ' (' . $none . ' haven\'t linked Discord yet)' : '') . '.');
            $back = 'community.php#discord';
        } elseif ($act === 'dc-sync') {
            [$given, $none] = rs_dc_sync_devs();
            rs_flash('Gave the Developer role to ' . $given . ($given === 1 ? ' developer' : ' developers') . '.' . ($none ? ' ' . $none . ' haven\'t linked Discord in their hub yet; they get it when they do.' : ''));
            $back = 'community.php#discord';
        } elseif ($act === 'pc-posts') {
            // The webhook is a secret: never shown again in full, never logged.
            $hook = trim((string)($_POST['discord_pc_webhook'] ?? ''));
            if (!empty($_POST['clear'])) { rs_set_setting('discord_pc_webhook', ''); rs_flash('The PC webhook is gone. PC posts use the PS5 one, if it\'s set.'); }
            elseif (($_POST['do'] ?? '') === 'test') {
                $ok = rs_pc_discord_post(['username' => 'RecompLauncher', 'embeds' => [['title' => 'RecompLauncher is connected', 'description' => 'New PC recomps and updates show up here when you press Publish PC.', 'color' => RS_DC_DEV_COLOR, 'url' => 'https://recomplauncher.com/']]], $why);
                rs_flash($ok ? 'Sent. Look in the Discord channel.' : 'Not sent: ' . $why, $ok ? 'ok' : 'err');
            } elseif ($hook !== '' && !rs_discord_hook_ok($hook)) {
                throw new RuntimeException('That isn\'t a Discord webhook link. In Discord: the channel\'s settings, Integrations, Webhooks, Copy Webhook URL.');
            } else {
                if ($hook !== '') rs_set_setting('discord_pc_webhook', $hook);
                rs_set_setting('discord_pc_posts', json_encode(['new' => !empty($_POST['new']), 'update' => !empty($_POST['update'])]));
                rs_log('settings', 'discord', 'PC posts saved' . ($hook !== '' ? ' (new webhook)' : ''));
                rs_flash(rs_pc_discord_hook() !== '' ? 'Saved. Press Send a test to check it.' : 'Saved. Paste the webhook link to turn the posts on.');
            }
            $back = 'community.php#posts';
        } elseif ($act === 'pc-upd-setup') {
            rs_flash(implode(' ', rs_dc_pc_updates_setup()) . ' Press Send a test to check it.');
            $back = 'community.php#posts';
        } elseif ($act === 'pc-upd-test') {
            $ok = rs_pc_discord_post(['username' => 'RecompLauncher', 'embeds' => [['title' => 'Recomp updates go here', 'description' => 'New versions of PC recomps show up in this channel when you press Publish PC. New recomps stay in their own channel.', 'color' => 0x5FE3A1, 'url' => 'https://recomplauncher.com/']]], $why, 'update');
            rs_flash($ok ? 'Sent. Look in #recomp-updates.' : 'Not sent: ' . $why, $ok ? 'ok' : 'err');
            $back = 'community.php#posts';
        } elseif ($act === 'pc-upd-off') {
            rs_set_setting('dc_pc_upd_chan', '');
            rs_set_setting('discord_pc_upd_webhook', '');
            rs_log('settings', 'discord', 'PC updates back with the new recomps');
            rs_flash('Updates go back to the same channel as new recomps. The #recomp-updates channel is still on Discord; delete it there if you don\'t want it.');
            $back = 'community.php#posts';
        } elseif ($act === 'note-add') {
            $body = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['body'] ?? '')) ?? ''), 0, 400);
            $link = trim((string)($_POST['link'] ?? ''));
            if ($body === '') throw new RuntimeException('Write the note first.');
            if ($link !== '' && !preg_match('#^https://[^\s<>"]{3,290}$#', $link)) throw new RuntimeException('The link has to be a full https:// address.');
            $place = isset(RS_NOTE_PLACES[$_POST['place'] ?? '']) ? (string)$_POST['place'] : 'devs';
            $tone = isset(RS_NOTE_TONES[$_POST['tone'] ?? '']) ? (string)$_POST['tone'] : 'cyan';
            rs_db()->prepare('INSERT INTO team_notes (body, link, link_text, place, tone, active, created) VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())')
                ->execute([$body, $link, mb_substr(trim((string)($_POST['link_text'] ?? '')), 0, 40), $place, $tone]);
            $posted = !empty($_POST['to_discord']) && rs_dc_dev_news($body, $link);
            rs_log('notes', 'add', $place . ': ' . mb_substr($body, 0, 80));
            rs_flash('Pinned.' . ($posted ? ' Also posted in #dev-announcements.' : (!empty($_POST['to_discord']) ? ' It couldn\'t go to Discord: set up the server first.' : '')));
            $back = 'community.php#notes';
        } elseif ($act === 'note-toggle' || $act === 'note-delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($act === 'note-delete') rs_db()->prepare('DELETE FROM team_notes WHERE id = ?')->execute([$id]);
            else rs_db()->prepare('UPDATE team_notes SET active = 1 - active WHERE id = ?')->execute([$id]);
            $back = 'community.php#notes';
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$c = rs_dc_conf();
$state = rs_dc_server_state();
$invite = rs_dc_bot_invite();
$pcHookOwn = rs_discord_hook_ok((string)rs_setting('discord_pc_webhook', ''));
$pcHook = rs_pc_discord_hook();
$updOn = rs_pc_discord_upd_on();
$pcPosts = json_decode((string)rs_setting('discord_pc_posts', '{"new":true,"update":true}'), true) ?: [];
$notes = rs_notes(false);
rs_dev_tables();
$devsLinked = (int)rs_db()->query("SELECT COUNT(*) FROM dev_accounts WHERE status = 'approved' AND discord_id <> ''")->fetchColumn();
$devsAll = (int)rs_db()->query("SELECT COUNT(*) FROM dev_accounts WHERE status = 'approved'")->fetchColumn();
$chan = fn(string $k) => ($id = trim((string)rs_setting($k, ''))) !== '' && $c['guild'] !== '' ? 'https://discord.com/channels/' . rawurlencode($c['guild']) . '/' . rawurlencode($id) : '';

rs_head('Discord & notes', true, ['assets/health.css']);
?>
<section class="head-row"><div><h1>Discord &amp; team notes</h1>
  <p class="muted">Developers get their own role and channels on your Discord, new PC recomps are posted there when you press Publish PC, and notes from the team can sit on top of the developer hub or the site.</p></div></section>

<section class="card" id="discord">
  <h2>Discord server</h2>
  <?php if (!$state['ready']): ?>
  <p class="cm-warn"><strong>Not connected yet.</strong> <?= h($state['why']) ?></p>
  <ol class="steps">
    <li>Open <a href="https://discord.com/developers/applications" target="_blank" rel="noopener">your Discord app</a> (the one on the Beta page), go to <b>Bot</b>, press <b>Reset Token</b> and copy it.</li>
    <li>Paste it under <b>Bot token</b> on the <a href="beta.php#discord">Beta page</a> and save.</li>
    <?php if ($invite !== ''): ?><li>Add the bot to your server with <a href="<?= h($invite) ?>" target="_blank" rel="noopener">this link</a>. It asks only for what it needs: see channels, send messages, manage channels and manage roles.</li><?php endif; ?>
    <li>In Discord, Server Settings, Roles: drag the bot's role near the top, above where the Developer role should sit.</li>
    <li>Come back and press <b>Set up</b>.</li>
  </ol>
  <?php else: ?>
  <p class="muted">The bot is in the server. <b>Set up</b> makes (or finds) these, and pressing it again changes nothing:</p>
  <?php endif; ?>
  <ul class="dc-list">
    <li><span class="dc-dot" style="background:var(--cyan)"></span><b>Developer</b> role, in RecompStore cyan, shown apart in the member list. Approved developers get it when they link Discord in their hub. <?= $state['role'] !== '' ? '<span class="pill ok">made</span>' : '' ?></li>
    <li><b>Developers</b> category, only developers can see it:
      <b>#dev-chat</b> <?= ($u = $chan('dc_dev_chat')) ? '<a href="' . h($u) . '" target="_blank" rel="noopener">open</a>' : '' ?> for talking, and
      <b>#dev-announcements</b> <?= ($u = $chan('dc_dev_news')) ? '<a href="' . h($u) . '" target="_blank" rel="noopener">open</a>' : '' ?> where only the team posts.</li>
  </ul>
  <p class="muted"><?= $devsLinked ?> of <?= $devsAll ?> approved developers have linked Discord.</p>
  <div class="actions">
    <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><button class="primary" name="action" value="dc-setup"<?= $state['ready'] ? '' : ' disabled' ?>>Set up</button>
      <button name="action" value="dc-sync"<?= $state['ready'] && $state['role'] !== '' ? '' : ' disabled' ?>>Give developers the role</button></form>
  </div>
</section>

<section class="card" id="posts">
  <h2>New PC recomps on Discord</h2>
  <p class="muted">When you press Publish PC, new recomps and new versions are posted with their cover and a link (up to 10 a post). Old PC games' updates are left out, so the channel stays about recomps. <?= $pcHook === '' ? 'Off until there\'s a webhook.' : ($pcHookOwn ? 'Posting to its own channel.' : 'Posting to the PS5 webhook\'s channel; paste another webhook to give PC its own.') ?></p>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="pc-posts">
    <label>Webhook link for PC<input type="text" name="discord_pc_webhook" autocomplete="off" spellcheck="false" data-lpignore="true" data-1p-ignore style="-webkit-text-security:disc" placeholder="<?= $pcHookOwn ? h('Saved: ' . rs_discord_hook_shown((string)rs_setting('discord_pc_webhook', '')) . '. Paste a new one to replace it.') : 'Paste the webhook link' ?>"><small>In Discord: the channel's settings, Integrations, Webhooks, New Webhook, Copy Webhook URL. Anyone with it can post there, so only its end is shown here again.</small></label>
    <div class="checks"><label class="check"><input type="checkbox" name="new" value="1"<?= !empty($pcPosts['new']) ? ' checked' : '' ?>> New recomps</label><label class="check"><input type="checkbox" name="update" value="1"<?= !empty($pcPosts['update']) ? ' checked' : '' ?>> New versions</label></div>
    <div class="actions"><button class="btn primary">Save</button><?php if ($pcHook !== ''): ?><button class="btn" name="do" value="test">Send a test</button><?php endif; ?><?php if ($pcHookOwn): ?><label class="check"><input type="checkbox" name="clear" value="1"> Remove the PC webhook</label><?php endif; ?></div>
  </form>
  <h3 class="cm-sub">Updates in their own channel</h3>
  <?php if ($updOn): ?>
  <p class="muted">New versions go to <b>#recomp-updates</b> <?= ($u = $chan('dc_pc_upd_chan')) ? '<a href="' . h($u) . '" target="_blank" rel="noopener">open</a>' : '' ?>; new recomps stay in their channel.</p>
  <form method="post" class="actions"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><button class="btn" name="action" value="pc-upd-test">Send a test</button><button class="btn" name="action" value="pc-upd-off">Put updates back with new recomps</button></form>
  <?php else: ?>
  <p class="muted">Right now new recomps and new versions share one channel. <b>Make #recomp-updates</b> has the bot make a channel right under it, seen by the same people, and new versions go there from then on.</p>
  <form method="post" class="actions"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><button class="btn primary" name="action" value="pc-upd-setup"<?= $state['ready'] ? '' : ' disabled title="Connect the bot first"' ?>>Make #recomp-updates</button></form>
  <?php endif; ?>
</section>

<section class="card" id="notes">
  <h2>Team notes</h2>
  <p class="muted">A short note pinned on top of the developer hub, the site, or both, until you take it down. Players can close one and it stays closed for them.</p>
  <form method="post" class="form grid2">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="note-add">
    <label class="span2">Note<textarea name="body" rows="2" maxlength="400" required placeholder="We just added Discord: link it in your hub to get the Developer role and the developer channels."></textarea></label>
    <label>Link<input name="link" maxlength="300" class="mono" placeholder="https://discord.gg/… (optional)"></label>
    <label>Link text<input name="link_text" maxlength="40" placeholder="Join our Discord (optional)"></label>
    <label>Where<select name="place"><?php foreach (RS_NOTE_PLACES as $k => $n): ?><option value="<?= h($k) ?>"><?= h($n) ?></option><?php endforeach; ?></select></label>
    <label>Look<select name="tone"><?php foreach (RS_NOTE_TONES as $k => $n): ?><option value="<?= h($k) ?>"><?= h($n) ?></option><?php endforeach; ?></select></label>
    <div class="checks span2"><label class="check"><input type="checkbox" name="to_discord" value="1"<?= $chan('dc_dev_news') ? '' : ' disabled' ?>> Also post it in #dev-announcements</label></div>
    <div class="actions span2"><button class="primary">Pin it</button></div>
  </form>
  <?php if ($notes): ?>
  <ul class="note-list">
    <?php foreach ($notes as $n): ?>
    <li class="note-row tone-<?= h($n['tone']) ?><?= $n['active'] ? '' : ' off' ?>">
      <div><p><?= h($n['body']) ?><?php if ($n['link'] !== ''): ?> <a href="<?= h($n['link']) ?>" target="_blank" rel="noopener"><?= h($n['linkText'] !== '' ? $n['linkText'] : $n['link']) ?></a><?php endif; ?></p>
        <small class="muted"><?= h(RS_NOTE_PLACES[$n['place']] ?? $n['place']) ?> · <?= h(rs_date('M j, Y', strtotime($n['created'] . ' UTC'))) ?><?= $n['active'] ? '' : ' · taken down' ?></small></div>
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="id" value="<?= $n['id'] ?>">
        <button name="action" value="note-toggle"><?= $n['active'] ? 'Take down' : 'Pin again' ?></button> <button name="action" value="note-delete" class="danger" onclick="return confirm('Delete this note?')">Delete</button></form>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?><p class="muted">No notes yet.</p><?php endif; ?>
</section>
<?php rs_foot();
