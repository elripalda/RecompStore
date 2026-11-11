<?php
// RecompStore admin (2.6): developers. Each one gets a page on the site (#/dev/<slug>) with their
// apps and links, and a Verified badge you set. Games are matched to a developer by their Author
// (the developer's name, page name or other names), or picked on the game's own page.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

$devs = rs_developers();
$editSlug = isset($_GET['edit']) ? (string)$_GET['edit'] : null;
$isNew = isset($_GET['new']);
$d = $editSlug !== null && isset($devs[$editSlug]) ? $devs[$editSlug] : rs_dev_defaults();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $old = (string)($_POST['old_slug'] ?? '');
    $old = isset($devs[$old]) ? $old : null;
    if (($_POST['action'] ?? '') === 'delete' && $old !== null) {
        rs_dev_delete($old);
        rs_log('developers', 'removed', $devs[$old]['name'] . ' (' . $old . ')');
        rs_flash('Removed ' . $devs[$old]['name'] . '. Their games keep their Author. Press Publish to update the site.');
        header('Location: developers.php');
        exit;
    }
    $d = $old !== null ? $devs[$old] : rs_dev_defaults();
    $d['name'] = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
    $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
    if ($slug === '') $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($d['name'])), '-');
    $d['slug'] = substr($slug, 0, 40);
    $d['aliases'] = mb_substr(trim((string)($_POST['aliases'] ?? '')), 0, 200);
    $d['about'] = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['about'] ?? ''))), 0, 400);
    $badLink = null;
    $d['links'] = rs_dev_links_clean((array)($_POST['links'] ?? []), $badLink);
    $d['verified'] = !empty($_POST['verified']);
    if (!empty($_POST['avatar_remove'])) $d['avatar'] = '';
    if ($d['name'] === '') $error = 'Give the developer a name.';
    elseif (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $d['slug'])) $error = 'Page name: lowercase letters, numbers and - only, like griches or lozaza-mastro.';
    elseif ($d['slug'] !== $old && isset($devs[$d['slug']])) $error = 'Another developer already has the page name ' . $d['slug'] . '.';
    elseif ($badLink !== null) $error = $badLink . ': a full link that starts with https://';
    if ($error === '') {
        try {
            if (!empty($_FILES['avatar_file']['name'])) $d['avatar'] = rs_save_avatar($_FILES['avatar_file'], $d['slug']);
            rs_dev_save($d, $old);
            rs_log('developers', $old === null ? 'added' : 'changed', $d['name'] . ' (' . $d['slug'] . ')' . ($d['verified'] ? ', verified' : ''));
            rs_flash('Saved ' . $d['name'] . '. Press Publish to put their page on the site.');
            header('Location: developers.php?edit=' . urlencode($d['slug']));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
    $editSlug = $old;
    $isNew = $old === null;
}

// Which games each developer gets: by Author, or picked on the game's page.
$games = rs_games();
$byDev = [];
foreach ($games as $g) {
    $s = rs_dev_for($g, $devs);
    if ($s !== '') $byDev[$s][] = ['id' => $g['id'], 'name' => $g['name'] ?: $g['id'], 'picked' => ($g['dev'] ?? '') === $s, 'hidden' => $g['hidden'] || !empty($g['testers'])];
}
$editing = $isNew || ($editSlug !== null && isset($devs[$editSlug])) || $error !== '';

rs_head('Developers');
?>
<section class="head-row">
  <div>
    <h1>Developers</h1>
    <p class="muted">Each developer gets a page on the site with their apps and links. Games are matched by their Author, or picked on the game's page.</p>
  </div>
  <div class="actions"><?php $w = rs_dev_waiting(); ?><a class="btn<?= $w ? ' primary' : '' ?>" href="dev-accounts.php">Developer accounts<?= $w ? ' (' . $w . ' waiting)' : '' ?></a><a class="btn primary" href="developers.php?new=1">Add a developer</a></div>
</section>
<?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

<?php if ($editing): ?>
<form method="post" enctype="multipart/form-data" class="edit card dev-edit">
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <input type="hidden" name="old_slug" value="<?= h($isNew ? '' : (string)$editSlug) ?>">
  <h2><?= $isNew ? 'New developer' : h($d['name']) ?></h2>
  <div class="dev-edit-grid">
    <div class="dev-avatar-edit">
      <div class="dev-avatar big"><?= $d['avatar'] !== '' ? '<img src="' . h($d['avatar']) . '" alt="">' : '<span>' . h(mb_strtoupper(mb_substr($d['name'] ?: '?', 0, 1))) . '</span>' ?></div>
      <label>Picture<input type="file" name="avatar_file" accept="image/png,image/jpeg,image/webp"><small>Square works best; cropped to the center.</small></label>
      <?php if ($d['avatar'] !== ''): ?><label class="check"><input type="checkbox" name="avatar_remove"> Remove the picture</label><?php endif; ?>
    </div>
    <div class="grid2">
      <label>Name<input name="name" value="<?= h($d['name']) ?>" maxlength="60" required placeholder="Gary Riches"></label>
      <label>Page name<input name="slug" value="<?= h($d['slug']) ?>" maxlength="40" placeholder="griches" class="mono"><small>recompstore.com/#/dev/<?= h($d['slug'] !== '' ? $d['slug'] : 'name') ?>. Empty: made from the name.</small></label>
      <label class="span2"><span>Other names <span class="muted">(optional)</span></span><input name="aliases" value="<?= h($d['aliases']) ?>" maxlength="200" placeholder="griches, Gary R"><small>Comma-separated. Games whose Author is any of these, the name or the page name are theirs.</small></label>
      <label class="span2"><span>About <span class="muted">(optional)</span></span><input name="about" value="<?= h($d['about']) ?>" maxlength="400" placeholder="Ports GameCube and N64 games to PS5."></label>
      <?php foreach (RS_DEV_LINKS as $k => $label): ?>
      <label><span><?= h($label) ?> <span class="muted">(optional)</span></span><input name="links[<?= h($k) ?>]" value="<?= h((string)($d['links'][$k] ?? '')) ?>" maxlength="300" placeholder="https://"></label>
      <?php endforeach; ?>
      <div class="checks span2"><label class="check"><input type="checkbox" name="verified" <?= $d['verified'] ? 'checked' : '' ?>> Verified developer (you've checked it's really them)</label></div>
    </div>
  </div>
  <?php if (!$isNew): ?>
  <div class="dev-games">
    <h3>Their games</h3>
    <?php if (!empty($byDev[$editSlug])): ?>
    <ul><?php foreach ($byDev[$editSlug] as $x): ?><li><a href="edit.php?id=<?= h(rawurlencode($x['id'])) ?>"><?= h($x['name']) ?></a><span class="muted"><?= $x['picked'] ? ' · picked on its page' : ' · by Author' ?><?= $x['hidden'] ? ' · not public' : '' ?></span></li><?php endforeach; ?></ul>
    <?php else: ?><p class="muted">No games match yet. Add their Author name above, or pick them on a game's page.</p><?php endif; ?>
  </div>
  <?php endif; ?>
  <div class="dev-actions">
    <button class="primary">Save</button>
    <a class="btn" href="developers.php">Cancel</a>
    <?php if (!$isNew): ?><button class="btn danger" name="action" value="delete" formnovalidate onclick="return confirm('Remove <?= h(addslashes($d['name'])) ?>? Their games stay, without a page.');">Remove</button><?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php
// (4.7) A list, with a search: there are hundreds of developers now.
$pcByDev = [];
foreach (rs_pc_apps() as $x) if ((string)($x['dev'] ?? '') !== '') $pcByDev[(string)$x['dev']] = ($pcByDev[(string)$x['dev']] ?? 0) + 1;
?>
<section class="card dv-list">
  <div class="dv-top"><input type="search" id="dv-q" placeholder="Search <?= count($devs) ?> developers by name or page name" autocomplete="off"><span class="muted" id="dv-n"></span></div>
  <?php if (!$devs): ?><p class="muted">No developers yet. Add one, and the games whose Author matches get a link to their page.</p><?php else: ?>
  <table class="dv-table">
    <thead><tr><th>Developer</th><th>Page</th><th class="num">PS5 apps</th><th class="num">PC recomps</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($devs as $s => $x): $n = count($byDev[$s] ?? []); $p = (int)($pcByDev[$s] ?? 0); ?>
    <tr class="<?= $s === $editSlug ? 'on' : '' ?>" data-find="<?= h(mb_strtolower($x['name'] . ' ' . $s . ' ' . (is_array($x['aliases'] ?? '') ? implode(' ', $x['aliases']) : (string)($x['aliases'] ?? '')))) ?>">
      <td><a class="dv-who" href="developers.php?edit=<?= h(rawurlencode($s)) ?>"><span class="dev-avatar"><?= $x['avatar'] !== '' ? '<img src="' . h($x['avatar']) . '" alt="" loading="lazy">' : '<span>' . h(mb_strtoupper(mb_substr($x['name'], 0, 1))) . '</span>' ?></span><strong><?= h($x['name']) ?></strong><?= $x['verified'] ? '<span class="pill ok">Verified</span>' : '' ?></a></td>
      <td class="mono muted">/dev/<?= h($s) ?></td>
      <td class="num"><?= $n ?: '<span class="muted">0</span>' ?></td>
      <td class="num"><?= $p ?: '<span class="muted">0</span>' ?></td>
      <td class="act"><a class="btn" href="developers.php?edit=<?= h(rawurlencode($s)) ?>">Edit</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<style>
.dv-top{display:flex;gap:12px;align-items:center;margin-bottom:12px}
.dv-top input{flex:1;min-width:0}
.dv-table{width:100%;border-collapse:collapse}
.dv-table th{text-align:left;font-size:12px;color:#93a0b3;font-weight:600;padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.08)}
.dv-table td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.05);vertical-align:middle}
.dv-table tr.on td{background:rgba(52,242,232,.06)}
.dv-table .num{text-align:right;font-variant-numeric:tabular-nums;width:1%;white-space:nowrap}
.dv-table .act{text-align:right;width:1%}
.dv-who{display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none;min-width:0}
.dv-who .dev-avatar{width:32px;height:32px;flex:none}
.dv-who strong{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media (max-width:720px){.dv-table th:nth-child(2),.dv-table td:nth-child(2){display:none}}
</style>
<script>
(() => {
  const q = document.getElementById('dv-q'), rows = [...document.querySelectorAll('.dv-table tbody tr')], n = document.getElementById('dv-n');
  if (!q) return;
  const run = () => { const w = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean); let k = 0; rows.forEach((r) => { const on = w.every((x) => r.dataset.find.includes(x)); r.hidden = !on; if (on) k++; }); n.textContent = w.length ? k + ' found' : ''; };
  q.addEventListener('input', run);
})();
</script>
<?php rs_foot();
