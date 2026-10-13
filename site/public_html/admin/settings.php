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
