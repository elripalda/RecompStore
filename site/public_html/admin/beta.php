<?php
// RecompStore admin: beta codes. Make codes for a beta game, see who redeemed them, cancel them.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_beta_tables();
$db = rs_db();

$games = rs_games();
$names = array_column($games, 'name', 'id');
$betaGames = array_values(array_filter($games, fn($g) => rs_has_beta($g)));
$filter = isset($_GET['game']) && isset($names[$_GET['game']]) ? (string)$_GET['game'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $action = $_POST['action'] ?? '';
    $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
    $back = 'beta.php' . ($filter !== '' ? '?game=' . urlencode($filter) : '');
    try {
        if ($action === 'discord') {
            // (2.7.1) Discord sign-in for recompstore.com/beta. The secret and bot token are never shown again.
            $id = fn(string $k) => preg_replace('/\D/', '', (string)($_POST[$k] ?? '')) ?? '';
            rs_set_setting('dc_client_id', $id('dc_client_id'));
            rs_set_setting('dc_guild_id', $id('dc_guild_id'));
            rs_set_setting('dc_give_role', $id('dc_give_role'));
            rs_set_setting('dc_need_role', $id('dc_need_role'));
            if (trim((string)($_POST['dc_client_secret'] ?? '')) !== '') rs_secret_put('dc_client_secret', trim((string)$_POST['dc_client_secret']));
            if (trim((string)($_POST['dc_bot_token'] ?? '')) !== '') rs_secret_put('dc_bot_token', trim((string)$_POST['dc_bot_token']));
            if (!empty($_POST['dc_bot_clear'])) rs_secret_put('dc_bot_token', '');
            rs_log('settings', 'Discord sign-in', 'saved');
            rs_flash(rs_dc_ready() ? 'Saved. Open recompstore.com/beta and sign in with Discord to try it.' : 'Saved. It needs the Client ID, the Client Secret and the server ID to work.');
            $back = 'beta.php#discord';
        } elseif ($action === 'dc_counter' || $action === 'dc_count_now') {
            // (4.6) The member counter channel at the top of the Discord server.
            if ($action === 'dc_counter') {
                $on = ($_POST['on'] ?? '') === '1';
                rs_set_setting('dc_count_on', $on ? '1' : '0');
                rs_log('settings', 'Discord member counter', $on ? 'on' : 'off');
            }
            if (rs_dc_count_on()) {
                $cs = rs_dc_count_run();
                if ($cs['err'] !== '') rs_flash($cs['err'], 'err');
                else rs_flash('The channel shows ' . ($cs['live'] ?? rs_dc_count_name((int)$cs['count'])) . '. It updates every 10 minutes.');
            } else rs_flash('Member counter off. The channel stays as it is; delete it in Discord if you don\'t want it.');
            $back = 'beta.php#dc-counter';
        } elseif ($action === 'make') {
            $gid = (string)($_POST['game'] ?? '');
            $n = max(1, min(200, (int)($_POST['count'] ?? 1)));
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 120);
            $g = rs_game($gid);
            if (!$g || !rs_has_beta($g)) throw new RuntimeException('Pick a game that has Beta ticked, or a beta build for testers.');
            $made = rs_beta_make_codes($gid, $n, $note);
            $_SESSION['new_codes'] = ['game' => $g['name'], 'codes' => $made];
            $back = 'beta.php?game=' . urlencode($gid);
        } elseif ($action === 'revoke' && strlen($code) === 8) {
            $db->prepare('UPDATE beta_codes SET revoked = 1 WHERE code = ?')->execute([$code]);
            rs_flash('Cancelled ' . rs_beta_format($code) . '. That console loses the game the next time its store checks in.');
        } elseif ($action === 'restore' && strlen($code) === 8) {
            $db->prepare('UPDATE beta_codes SET revoked = 0 WHERE code = ?')->execute([$code]);
            rs_flash('Turned ' . rs_beta_format($code) . ' back on.');
        } elseif ($action === 'delete' && strlen($code) === 8) {
            $db->prepare('DELETE FROM beta_codes WHERE code = ? AND install_id IS NULL')->execute([$code]);
            rs_flash('Deleted ' . rs_beta_format($code) . '.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$newCodes = $_SESSION['new_codes'] ?? null;
unset($_SESSION['new_codes']);

$sql = 'SELECT c.*, i.last_seen, i.store_version FROM beta_codes c LEFT JOIN beta_installs i ON i.install_id = c.install_id';
$args = [];
if ($filter !== '') { $sql .= ' WHERE c.game_id = ?'; $args[] = $filter; }
$sql .= ' ORDER BY c.created DESC, c.code ASC LIMIT 1000';
$st = $db->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();
$when = fn(?string $d) => $d ? rs_date('M j, g:i a', strtotime($d)) : '';

rs_head('Beta codes');
?>
<section class="head-row">
  <div>
    <h1>Beta codes</h1>
    <p class="muted">Each code unlocks one beta game on one console, in RecompStore 1.1.0 or newer: Settings, Redeem a code. Codes look like 12-345-678.</p>
  </div>
</section>

<?php $dc = rs_dc_conf(); rs_dc_tables();
$dcRecent = rs_db()->query('SELECT * FROM beta_discord ORDER BY created DESC LIMIT 15')->fetchAll(); ?>
<section class="card" id="discord">
  <h2>Codes through Discord <?= rs_dc_ready() ? '<span class="status-pill used">On</span>' : '<span class="status-pill off">Not set up</span>' ?></h2>
  <p class="muted">On <a href="/beta" target="_blank">recompstore.com/beta</a> testers sign in with Discord. If they're in your server<?= $dc['need_role'] !== '' ? ' and have the role you picked' : '' ?>, they get a code for an open beta right there, one per Discord account per game. Open a beta on its game page (Beta card): show it on the page, let Discord codes through, and set how many.</p>
  <details<?= rs_dc_ready() ? '' : ' open' ?>><summary class="muted">How to set it up</summary>
    <ol class="muted steps">
      <li>Go to <a href="https://discord.com/developers/applications" target="_blank" rel="noopener">discord.com/developers/applications</a>, New Application, name it RecompStore.</li>
      <li>OAuth2: copy the Client ID and Client Secret into the boxes below, and under Redirects add <code><?= h(rs_dc_redirect()) ?></code></li>
      <li>In Discord, turn on Developer Mode (Settings, Advanced), right-click your server, Copy Server ID.</li>
      <li>Optional, so the site can add people to the server and give them a tester role: in the app's Bot tab, Reset Token and paste it below, then add the bot to your server<?= $dc['client'] !== '' ? ' with <a href="https://discord.com/oauth2/authorize?client_id=' . h(rawurlencode($dc['client'])) . '&amp;scope=bot&amp;permissions=268435457" target="_blank" rel="noopener">this link</a>' : ' (the link shows here once the Client ID is saved)' ?>. In Server Settings, Roles, drag the bot's role above the tester role.</li>
    </ol>
  </details>
  <form method="post" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="discord">
    <div class="filters">
      <label>Client ID<input name="dc_client_id" value="<?= h($dc['client']) ?>" inputmode="numeric"></label>
      <label>Client Secret<input name="dc_client_secret" type="password" autocomplete="new-password" placeholder="<?= $dc['secret'] !== '' ? 'Saved. Paste a new one to change it' : 'From OAuth2' ?>"></label>
      <label>Server ID<input name="dc_guild_id" value="<?= h($dc['guild']) ?>" inputmode="numeric"></label>
    </div>
    <div class="filters">
      <label>Bot token <span class="muted">(optional)</span><input name="dc_bot_token" type="password" autocomplete="new-password" placeholder="<?= $dc['bot'] !== '' ? 'Saved. Paste a new one to change it' : 'Joins people to the server' ?>"></label>
      <label>Give this role to testers <span class="muted">(optional)</span><input name="dc_give_role" value="<?= h($dc['give_role']) ?>" inputmode="numeric" placeholder="Role ID"></label>
      <label>Only people with this role <span class="muted">(optional)</span><input name="dc_need_role" value="<?= h($dc['need_role']) ?>" inputmode="numeric" placeholder="Role ID"></label>
    </div>
    <p class="muted">Invite link shown to people who aren't in the server: <?= $dc['invite'] !== '' ? h($dc['invite']) : 'none yet' ?> (Settings, Discord invite).</p>
    <p><button class="primary">Save</button> <?php if ($dc['bot'] !== ''): ?><label class="check" style="display:inline-flex;margin-left:12px"><input type="checkbox" name="dc_bot_clear" value="1"> Remove the bot token</label><?php endif; ?></p>
  </form>
  <?php $cs = rs_dc_count_state(); $cOn = rs_dc_count_on(); $cChan = trim((string)rs_setting('dc_count_chan', '')); ?>
  <div class="dc-counter" id="dc-counter">
    <h3>Member counter <?= $cOn ? ($cs['err'] !== '' ? '<span class="status-pill off">Needs a look</span>' : '<span class="status-pill used">On</span>') : '<span class="status-pill">Off</span>' ?></h3>
    <p class="muted">A voice channel at the top of your server named "Members: 12,345". Everyone sees it and nobody can join it. The bot above renames it every 10 minutes when the number changes (Discord allows only a couple of renames per 10 minutes).</p>
    <dl class="dc-counter-facts">
      <div><dt>Channel</dt><dd><?php if ($cChan !== '' && $dc['guild'] !== ''): ?><a href="https://discord.com/channels/<?= h(rawurlencode($dc['guild'])) ?>/<?= h(rawurlencode($cChan)) ?>" target="_blank" rel="noopener"><?= h((string)($cs['live'] ?? '') ?: 'Open in Discord') ?></a><?php else: ?><span class="muted">Made the first time it's on</span><?php endif; ?></dd></div>
      <div><dt>Last count</dt><dd><?= $cs['count'] !== null ? number_format((int)$cs['count']) : '<span class="muted">None yet</span>' ?></dd></div>
      <div><dt>Last renamed</dt><dd><?= $cs['renamed'] ? h(rs_date('M j, g:i a', (int)$cs['renamed'])) : '<span class="muted">Never</span>' ?></dd></div>
      <div><dt>Last checked</dt><dd><?= $cs['checked'] ? h(rs_date('M j, g:i a', (int)$cs['checked'])) : '<span class="muted">Never</span>' ?></dd></div>
    </dl>
    <?php if ($cOn && $cs['err'] !== ''): ?><p class="err-note"><?= h($cs['err']) ?><?php if (str_contains($cs['err'], 'Manage Channels') && $dc['client'] !== ''): ?> Give it with <a href="https://discord.com/oauth2/authorize?client_id=<?= h(rawurlencode($dc['client'])) ?>&amp;scope=bot&amp;permissions=<?= RS_DC_COUNT_PERMS ?>&amp;guild_id=<?= h(rawurlencode($dc['guild'])) ?>" target="_blank" rel="noopener">this link</a> (adds Manage Channels to the bot's role), then press Update now.<?php endif; ?></p><?php endif; ?>
    <div class="dc-counter-btns">
      <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="dc_counter"><input type="hidden" name="on" value="<?= $cOn ? '0' : '1' ?>"><button class="<?= $cOn ? '' : 'primary' ?>"><?= $cOn ? 'Turn it off' : 'Turn it on' ?></button></form>
      <?php if ($cOn): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="dc_count_now"><button>Update now</button></form><?php endif; ?>
    </div>
  </div>
  <?php if ($dcRecent): ?>
  <h3>Latest codes through Discord</h3>
  <div class="table-wrap"><table class="codes">
    <thead><tr><th>Who</th><th>Game</th><th>Code</th><th>When</th></tr></thead>
    <tbody><?php foreach ($dcRecent as $r): ?><tr><td><?= h($r['username']) ?></td><td><?= h($names[$r['game_id']] ?? $r['game_id']) ?></td><td class="code"><?= h(rs_beta_format($r['code'])) ?></td><td class="muted"><?= h($when($r['created'])) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php if ($newCodes): ?>
<section class="card">
  <h2><?= count($newCodes['codes']) ?> new <?= count($newCodes['codes']) === 1 ? 'code' : 'codes' ?> for <?= h($newCodes['game']) ?></h2>
  <p class="muted">Copy them now and send one to each tester. They stay listed below.</p>
  <div class="codes-box" data-copy-box><?= h(implode("\n", array_map('rs_beta_format', $newCodes['codes']))) ?></div>
  <p><button type="button" data-copy-codes>Copy all</button></p>
</section>
<?php endif; ?>

<section class="card">
  <h2>Make codes</h2>
  <?php if (!$betaGames): ?>
    <p class="muted">No game is a beta yet. Open a game, tick Beta and give it a beta zip, then come back here.</p>
  <?php else: ?>
  <form method="post" class="filters">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="make">
    <label>Game<select name="game"><?php foreach ($betaGames as $g): ?><option value="<?= h($g['id']) ?>" <?= $filter === $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?></option><?php endforeach; ?></select></label>
    <label style="min-width:120px">How many<input type="number" name="count" min="1" max="200" value="1"></label>
    <label class="grow" style="flex:1">Note <span class="muted">(optional)</span><input name="note" maxlength="120" placeholder="Who it's for, like Discord testers"></label>
    <button class="primary">Make codes</button>
  </form>
  <?php endif; ?>
</section>

<section class="card">
  <form method="get" class="filters">
    <label>Show<select name="game" onchange="this.form.submit()">
      <option value="">Every game</option>
      <?php foreach ($games as $g): ?><option value="<?= h($g['id']) ?>" <?= $filter === $g['id'] ? 'selected' : '' ?>><?= h($g['name']) ?><?= $g['beta'] ? ' (beta)' : '' ?></option><?php endforeach; ?>
    </select></label>
    <span class="muted"><?= count($rows) ?> <?= count($rows) === 1 ? 'code' : 'codes' ?>, <?= count(array_filter($rows, fn($r) => $r['install_id'] !== null && !(int)$r['revoked'])) ?> in use</span>
  </form>
  <?php if (!$rows): ?><p class="muted">No codes yet.</p><?php else: ?>
  <div class="table-wrap"><table class="codes"><!-- (2.3) scrolls inside its card on a phone -->
    <thead><tr><th>Code</th><th>Game</th><th>Note</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $used = $r['install_id'] !== null; $off = (int)$r['revoked'] === 1; ?>
      <tr>
        <td class="code"><?= h(rs_beta_format($r['code'])) ?></td>
        <td><?= h($names[$r['game_id']] ?? $r['game_id']) ?></td>
        <td class="muted"><?= h($r['note']) ?></td>
        <td>
          <?php if ($off): ?><span class="status-pill off">Cancelled</span>
          <?php elseif ($used): ?><span class="status-pill used">Redeemed</span>
          <?php else: ?><span class="status-pill">Not used yet</span><?php endif; ?>
          <?php if ($used): ?><br><span class="muted" title="Console <?= h($r['install_id']) ?>">Console <?= h(substr($r['install_id'], 0, 6)) ?> · <?= h($when($r['redeemed'])) ?><?= $r['last_seen'] ? ' · last seen ' . h($when($r['last_seen'])) : '' ?><?= $r['store_version'] ? ' · store ' . h($r['store_version']) : '' ?></span><?php endif; ?>
        </td>
        <td style="text-align:right;white-space:nowrap">
          <form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="code" value="<?= h($r['code']) ?>">
            <?php if ($off): ?><button name="action" value="restore">Turn back on</button>
            <?php else: ?><button name="action" value="revoke" onclick="return confirm('Cancel <?= h(rs_beta_format($r['code'])) ?>? <?= $used ? 'That console loses the game.' : 'It can\\\'t be redeemed anymore.' ?>')">Cancel</button><?php endif; ?>
            <?php if (!$used): ?><button name="action" value="delete" class="danger" onclick="return confirm('Delete <?= h(rs_beta_format($r['code'])) ?>?')">Delete</button><?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<script>
document.querySelector('[data-copy-codes]')?.addEventListener('click', (e) => {
  const t = document.querySelector('[data-copy-box]').textContent.trim();
  navigator.clipboard && navigator.clipboard.writeText(t).then(() => { e.target.textContent = 'Copied'; setTimeout(() => (e.target.textContent = 'Copy all'), 1500); });
});
</script>
<style>
.dc-counter{margin:22px 0 4px;padding-top:18px;border-top:1px solid var(--line)}
.dc-counter h3{margin:0 0 6px}
.dc-counter-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:14px 0}
.dc-counter-facts div{background:rgba(var(--ink-rgb),.05);border:1px solid var(--line);border-radius:12px;padding:10px 12px;min-width:0}
.dc-counter-facts dt{color:var(--muted);font-size:13px}
.dc-counter-facts dd{margin:2px 0 0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dc-counter-btns{display:flex;gap:8px;flex-wrap:wrap}
.dc-counter-btns form{margin:0}
.dc-counter .err-note{color:var(--bad)}
@media (max-width:860px){.dc-counter-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>
<?php rs_foot();
