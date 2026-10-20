<?php
// Recomp Store admin: site-wide settings (store release, links, tagline, password).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

$fields = [
    'site_url' => ['Site address', 'https://recompstore.com', 'Used to build full links to images in the catalog.'],
    'tagline' => ['Home page tagline', 'Recompiled games, native on PS5.', 'One line under the logo on the site.'],
    'store_version' => ['Recomp Store version', RS_VERSION, 'The newest store release. The PS5 store updates itself when this is newer.'],
    'store_build' => ['Recomp Store build', '1', 'A whole number; raise it with each release.'],
    'store_url' => ['Store zip download link', '', 'The RecompStore zip on its GitHub release.'],
    'store_sha256' => ['Store zip SHA-256', '', 'The store checks the download against this before installing.'],
    'payload_url' => ['Installer payload link', '', 'RecompStore-Installer.elf on the GitHub release, for the site\'s Get Recomp Store steps.'],
    'discord_url' => ['Discord invite', '', 'Shown on the site and the store\'s About page.'],
    'kofi_url' => ['Ko-fi', 'https://ko-fi.com/ripalda', ''],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    if (($_POST['action'] ?? '') === 'password') {
        $p = (string)($_POST['password'] ?? '');
        if (strlen($p) < 10) rs_flash('Make the new password at least 10 characters.', 'err');
        elseif ($p !== (string)($_POST['password2'] ?? '')) rs_flash('The two passwords don\'t match.', 'err');
        else {
            rs_db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?')->execute([password_hash($p, PASSWORD_DEFAULT), $_SESSION['uid']]);
            rs_flash('Password changed.');
        }
    } elseif (($_POST['action'] ?? '') === 'github') {
        // (2.0) A GitHub token lifts GitHub's limit for the site's questions (60 an hour without).
        $tok = trim((string)($_POST['github_token'] ?? ''));
        if (!empty($_POST['github_clear'])) { rs_set_setting('github_token', ''); rs_flash('The GitHub token is gone. The site asks GitHub without one (60 questions an hour).'); }
        elseif ($tok === '') rs_flash('Nothing changed: paste a token to save it.', 'err');
        elseif (!preg_match('/^[A-Za-z0-9_]{20,255}$/', $tok)) rs_flash('That doesn\'t look like a GitHub token. Copy it again from GitHub.', 'err');
        else {
            rs_set_setting('github_token', $tok);
            $r = rs_http(rs_gh_api() . '/rate_limit', ['github' => true, 'timeout' => 15]);
            $j = json_decode($r['body'], true);
            $limit = (int)($j['resources']['core']['limit'] ?? 0);
            rs_flash($r['status'] === 200 && $limit > 60 ? 'Saved. GitHub takes ' . number_format($limit) . ' questions an hour from the site now.'
                     : ($r['status'] === 401 ? 'Saved, but GitHub refused it. Make a new token and paste it again.' : 'Saved. GitHub didn\'t answer the check, so it\'s untested.'),
                     $r['status'] === 401 ? 'err' : 'ok');
        }
    } elseif (($_POST['action'] ?? '') === 'blocked') {
        // (2.0) Custom sources taken down: one repo (owner/name) or list address per line.
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['blocked_sources'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '' ) continue;
            $line = rtrim((string)preg_replace('#^https://(www\.)?github\.com/#i', '', $line), '/');
            if (!rs_repo_ok($line) && !preg_match('#^https://[^\s<>"]{4,500}$#', $line)) { rs_flash('Each line is a GitHub repo (owner/name) or a list\'s https:// address. Not saved: "' . mb_substr($line, 0, 60) . '"', 'err'); header('Location: settings.php'); exit; }
            $lines[] = $line;
        }
        rs_set_setting('blocked_sources', implode("\n", array_slice(array_unique($lines), 0, 1000)));
        rs_flash('Saved. Stores drop these sources on their next refresh.');
    } elseif (($_POST['action'] ?? '') === 'site_v2') {
        // (2.0) The website's 2.0 side: only the logged-in admin (a preview), or everyone.
        $on = ($_POST['site_v2'] ?? '') === 'all';
        rs_set_setting('site_v2', $on ? 'all' : 'me');
        try {
            rs_catalog_set_site_v2($on);
            rs_flash($on ? 'Saved. Everyone sees Recomp Store 2.0 on the website now.' : 'Saved. Only you see 2.0 on the website (while logged in here); everyone else sees it as before.');
        } catch (Throwable $e) {
            rs_flash('Saved, but catalog.json couldn\'t be written: ' . $e->getMessage(), 'err');
        }
    } elseif (($_POST['action'] ?? '') === 'testers') {
        // (2.0) Consoles that get the test catalog. Only the rows that were on the page change.
        rs_beta_tables();
        $on = array_flip(array_filter(array_map('strval', (array)($_POST['tester'] ?? [])), fn($v) => (bool)preg_match('/^[0-9a-f]{16}$/', $v)));
        $st = rs_db()->prepare('UPDATE beta_installs SET tester = ? WHERE install_id = ?');
        $n = 0;
        foreach (array_slice((array)($_POST['shown'] ?? []), 0, 500) as $id) {
            if (!is_string($id) || !preg_match('/^[0-9a-f]{16}$/', $id)) continue;
            $st->execute([isset($on[$id]) ? 1 : 0, $id]);
            $n += $st->rowCount();
        }
        $all = (int)rs_db()->query('SELECT COUNT(*) FROM beta_installs WHERE tester = 1')->fetchColumn();
        rs_flash('Saved. ' . ($all === 1 ? '1 console is a tester' : $all . ' consoles are testers') . '; ' . ($n ? 'the change reaches them the next time their store starts or refreshes.' : 'nothing changed.'));
        $q = preg_replace('/[^0-9a-f]/', '', strtolower((string)($_POST['console'] ?? '')));
        header('Location: settings.php' . ($q !== '' ? '?console=' . urlencode($q) : '') . '#testers');
        exit;
    } elseif (($_POST['action'] ?? '') === 'taken') {
        // (2.0) Title IDs taken outside the store: one per line, the ID then what uses it.
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string)($_POST['taken_ids'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (!preg_match('/^[A-Z]{4}\d{5}\b/', $line)) { rs_flash('Each line starts with a title ID, like PPSA92004 What uses it. Not saved: "' . mb_substr($line, 0, 60) . '"', 'err'); header('Location: settings.php'); exit; }
            $lines[] = mb_substr($line, 0, 100);
        }
        rs_set_setting('taken_ids', implode("\n", array_slice($lines, 0, 500)));
        rs_flash('Saved. The admin won\'t give a game one of these, and recompstore.com/#/ids lists them.');
    } else {
        foreach ($fields as $k => $_) rs_set_setting($k, trim((string)($_POST[$k] ?? '')));
        rs_flash('Saved. Press Publish on the Games page to send store release changes to the PS5.');
    }
    header('Location: settings.php');
    exit;
}

rs_head('Settings');
?>
<section class="head-row"><div><h1>Settings</h1></div></section>
<section class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
    <div class="grid2">
    <?php foreach ($fields as $k => [$label, $default, $help]): ?>
      <label><?= h($label) ?><input name="<?= h($k) ?>" value="<?= h(rs_setting($k, $default)) ?>"><?php if ($help): ?><small><?= h($help) ?></small><?php endif; ?></label>
    <?php endforeach; ?>
    </div>
    <button class="primary">Save settings</button>
  </form>
</section>
<?php $v2all = rs_site_v2(); ?>
<section class="card" id="site-v2">
  <h2>Recomp Store 2.0 on the website</h2>
  <form method="post" class="choice">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="site_v2">
    <label class="check"><input type="radio" name="site_v2" value="me" <?= $v2all ? '' : 'checked' ?>> <span><strong>Only me (preview)</strong><small>Visitors see the site as it is today: games only, no categories, charts, My PS5 or Title IDs page. You see all of 2.0 while you're logged in here, with a Preview ribbon, and so does a browser linked to a PS5 (it has 2.0). Links from a PS5 (to link a phone or finish a bug report) work for everyone either way.</small></span></label>
    <label class="check"><input type="radio" name="site_v2" value="all" <?= $v2all ? 'checked' : '' ?>> <span><strong>Everyone</strong><small>Turn this on when Recomp Store 2.0 is out. It changes catalog.json's site.v2 at once: nothing else waiting is published.</small></span></label>
    <button class="btn">Save</button>
  </form>
</section>
<?php
// (2.0) Testers: the consoles that get the test catalog. Those marked first, then the 50 seen most
// recently, or the ones whose Console ID starts with what was searched.
rs_beta_tables();
$find = preg_replace('/[^0-9a-f]/', '', strtolower((string)($_GET['console'] ?? '')));
if ($find !== '') {
    $st = rs_db()->prepare('SELECT install_id, store_version, tester, created, last_seen FROM beta_installs WHERE install_id LIKE ? ORDER BY last_seen DESC LIMIT 50');
    $st->execute([substr($find, 0, 16) . '%']);
    $consoles = $st->fetchAll();
} else {
    $consoles = rs_db()->query('(SELECT install_id, store_version, tester, created, last_seen FROM beta_installs WHERE tester = 1)
        UNION (SELECT install_id, store_version, tester, created, last_seen FROM beta_installs ORDER BY last_seen DESC LIMIT 50)
        ORDER BY tester DESC, last_seen DESC')->fetchAll();
}
$testerCount = (int)rs_db()->query('SELECT COUNT(*) FROM beta_installs WHERE tester = 1')->fetchColumn();
$when = fn(string $t) => date('M j, Y g:i a', strtotime($t));
?>
<section class="card" id="testers">
  <h2>Testers <?php if ($testerCount): ?><span class="count"><?= $testerCount ?></span><?php endif; ?></h2>
  <p class="muted">A tester's store reads the test catalog: every app as you save it here, without Publish, including the ones set to Testers only (marked as testing). Everyone else gets the published catalog. Ask a tester for the Console ID in Recomp Store's Settings, under About.</p>
  <form method="get" class="find-row" action="settings.php#testers">
    <input name="console" value="<?= h($find) ?>" maxlength="16" placeholder="Find a Console ID" autocomplete="off" spellcheck="false" class="mono">
    <button class="btn">Find</button><?php if ($find !== ''): ?> <a href="settings.php#testers">Show all</a><?php endif; ?>
  </form>
  <?php if ($consoles): ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="testers"><input type="hidden" name="console" value="<?= h($find) ?>">
    <div class="table-wrap"><table class="stats testers">
      <thead><tr><th>Tester</th><th>Console ID</th><th>Recomp Store</th><th>Last seen</th><th>First seen</th></tr></thead>
      <tbody>
      <?php foreach ($consoles as $c): $id = (string)$c['install_id']; ?>
        <tr<?= (int)$c['tester'] ? ' class="on"' : '' ?>>
          <td><input type="hidden" name="shown[]" value="<?= h($id) ?>"><input type="checkbox" name="tester[]" value="<?= h($id) ?>" aria-label="Tester: <?= h(substr($id, 0, 8)) ?>" <?= (int)$c['tester'] ? 'checked' : '' ?>></td>
          <td><code class="cid"><?= h(substr($id, 0, 8)) ?></code><span class="muted rest"><?= h(substr($id, 8)) ?></span></td>
          <td><?= $c['store_version'] !== '' ? h((string)$c['store_version']) : '<span class="muted">?</span>' ?></td>
          <td><?= h($when((string)$c['last_seen'])) ?></td>
          <td class="muted"><?= h($when((string)$c['created'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <button class="btn">Save testers</button>
  </form>
  <?php else: ?>
  <p class="muted"><?= $find !== '' ? 'No console\'s ID starts with ' . h($find) . '.' : 'No consoles yet. A console shows up here once Recomp Store 2.0 opens on it.' ?></p>
  <?php endif; ?>
</section>
<section class="card">
  <h2>Title IDs taken outside the store</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="taken">
    <label><span>One per line: the ID, then what uses it</span><textarea name="taken_ids" rows="6" class="mono" placeholder="PPSA92004 An app already on many consoles"><?= h((string)rs_setting('taken_ids', RS_TAKEN_DEFAULT)) ?></textarea><small>The admin won't give a game one of these, and <a href="/#/ids" target="_blank">recompstore.com/#/ids</a> lists them with every ID in the store, so developers can pick one that won't clash. <?= h(RS_MOVE_BLOCK[0]) ?> to <?= h(RS_MOVE_BLOCK[1]) ?> are always kept for the PS5 store itself.</small></label>
    <button class="btn">Save the list</button>
  </form>
</section>
<section class="card">
  <h2>GitHub</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="github">
    <?php $hasTok = trim((string)rs_setting('github_token', '')) !== ''; ?>
    <label><span>GitHub token <span class="muted">(optional)</span></span><input type="text" name="github_token" autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore style="-webkit-text-security: disc; text-security: disc" placeholder="<?= $hasTok ? 'A token is saved. Paste a new one to replace it.' : 'Paste a token' ?>"><small>Every PS5 asks recompstore.com about releases and custom sources, and the site asks GitHub once for everyone. Without a token GitHub answers 60 questions an hour; with one, 5,000. Make a fine-grained token on GitHub (Settings, Developer settings, Personal access tokens) with read-only access to public repositories, and paste it here.</small></label>
    <div class="actions"><button class="btn">Save the token</button><?php if ($hasTok): ?><label class="check"><input type="checkbox" name="github_clear" value="1"> Remove the saved token</label><?php endif; ?></div>
  </form>
</section>
<section class="card">
  <h2>Blocked sources</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="blocked">
    <label><span>One per line: a GitHub repo (owner/name) or a list's address</span><textarea name="blocked_sources" rows="5" class="mono" placeholder="someone/bad-repo"><?= h((string)rs_setting('blocked_sources', '')) ?></textarea><small>Custom sources people add in the store can be anything. Ones listed here are refused, and stores that already added them drop them on their next refresh. Reports people send about a source are in the Inbox, under Sources.</small></label>
    <button class="btn">Save the list</button>
  </form>
</section>
<section class="card">
  <h2>Change your admin password</h2>
  <form method="post" class="grid2">
    <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><input type="hidden" name="action" value="password">
    <label>New password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
    <label>New password again<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label>
    <div><button class="btn">Change password</button></div>
  </form>
</section>
<?php rs_foot();
