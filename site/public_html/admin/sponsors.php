<?php
// RecompStore admin (4.8): Sponsors. Book a sponsor into a spot on recomplauncher.com with its
// picture, link and dates; a spot with nothing booked stays off. House ads (your own) fill a spot
// only while nothing paid is booked there. Views and clicks are counted by day.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/sponsors.php';
rs_require_login();
rs_sp_tables();
$db = rs_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($act === 'save') {
            $slot = (string)($_POST['slot'] ?? '');
            if (!isset(RS_SP_SLOTS[$slot])) throw new RuntimeException('Pick a spot.');
            $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 120));
            $link = trim((string)($_POST['link'] ?? ''));
            if ($name === '') throw new RuntimeException('Give the sponsor a name.');
            if (!preg_match('#^https://[^\s]+$#i', $link) || strlen($link) > 500) throw new RuntimeException('The link has to start with https://');
            $starts = (string)($_POST['starts'] ?? '');
            $ends = (string)($_POST['ends'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $starts)) $starts = gmdate('Y-m-d');
            $ends = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) ? $ends : null;
            if ($ends !== null && $ends < $starts) throw new RuntimeException('The end date is before the start date.');
            $alt = trim(mb_substr((string)($_POST['alt'] ?? ''), 0, 160));
            $house = empty($_POST['house']) ? 0 : 1;
            $hasPic = isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($id) {
                $set = 'slot = ?, name = ?, link = ?, alt = ?, starts = ?, ends = ?, house = ?';
                $args = [$slot, $name, $link, $alt, $starts, $ends, $house];
                if ($hasPic) { $set .= ', image = ?'; $args[] = rs_sp_save_image($_FILES['image']); }
                $args[] = $id;
                $db->prepare("UPDATE sponsors SET $set WHERE id = ?")->execute($args);
                rs_flash('Saved ' . $name . '.');
            } else {
                $img = rs_sp_save_image($_FILES['image'] ?? []);
                $db->prepare('INSERT INTO sponsors (slot, name, link, alt, image, starts, ends, house, created) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                    ->execute([$slot, $name, $link, $alt, $img, $starts, $ends, $house]);
                rs_flash('Booked ' . $name . ' into ' . RS_SP_SLOTS[$slot][0] . ($starts > gmdate('Y-m-d') ? ', starting ' . $starts : ', live within 2 minutes') . '.');
            }
            rs_log('sponsors', $name, $act . ' ' . $slot);
        } elseif ($act === 'pause' || $act === 'resume') {
            $db->prepare('UPDATE sponsors SET paused = ? WHERE id = ?')->execute([$act === 'pause' ? 1 : 0, $id]);
            rs_flash($act === 'pause' ? 'Paused. The spot goes quiet within 2 minutes.' : 'Running again.');
        } elseif ($act === 'delete') {
            $st = $db->prepare('SELECT image FROM sponsors WHERE id = ?');
            $st->execute([$id]);
            $img = (string)$st->fetchColumn();
            $db->prepare('DELETE FROM sponsors WHERE id = ?')->execute([$id]);
            $db->prepare('DELETE FROM sponsor_days WHERE sponsor_id = ?')->execute([$id]);
            if (str_starts_with($img, '/media/sponsors/')) @unlink(RS_PUBLIC . $img);
            rs_flash('Deleted, with its numbers.');
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: sponsors.php');
    exit;
}

// A month's numbers for one booking, as a CSV to send the sponsor.
if (isset($_GET['csv'])) {
    $id = (int)$_GET['csv'];
    $st = $db->prepare('SELECT name FROM sponsors WHERE id = ?');
    $st->execute([$id]);
    $name = (string)$st->fetchColumn();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9-]+/', '-', $name ?: 'sponsor') . '-report.csv"');
    echo "Day,Views,Clicks,Click rate\n";
    $st = $db->prepare('SELECT day, views, clicks FROM sponsor_days WHERE sponsor_id = ? ORDER BY day');
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) echo $r['day'], ',', (int)$r['views'], ',', (int)$r['clicks'], ',', $r['views'] ? round($r['clicks'] / $r['views'] * 100, 2) . '%' : '0%', "\n";
    exit;
}

$rows = $db->query('SELECT s.*, COALESCE(SUM(d.views), 0) views, COALESCE(SUM(d.clicks), 0) clicks,
    COALESCE(SUM(CASE WHEN d.day >= UTC_DATE() - INTERVAL 6 DAY THEN d.views END), 0) week
    FROM sponsors s LEFT JOIN sponsor_days d ON d.sponsor_id = s.id GROUP BY s.id ORDER BY s.paused, s.starts DESC, s.id DESC')->fetchAll();
$live = array_column(rs_sp_live(), 'id', 'slot');
$edit = null;
if (isset($_GET['edit'])) foreach ($rows as $r) if ((int)$r['id'] === (int)$_GET['edit']) $edit = $r;
$today = gmdate('Y-m-d');
$state = function (array $r) use ($today, $live): array {
    if ($r['paused']) return ['Paused', 'pill'];
    if ($r['starts'] > $today) return ['Starts ' . rs_date('M j', strtotime($r['starts'] . ' 12:00 UTC')), 'pill'];
    if ($r['ends'] !== null && $r['ends'] < $today) return ['Ended', 'pill'];
    return in_array((int)$r['id'], $live, true) ? ['Live', 'pill ok'] : ['Waiting (a paid sponsor has the spot)', 'pill warn'];
};
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
rs_head('Sponsors');
?>
<div class="ov-head"><h1>Sponsors</h1><span class="muted small">Spots on recomplauncher.com stay off until something's booked. Changes show within 2 minutes.</span></div>

<div class="sp-spots">
  <?php foreach (RS_SP_SLOTS as $k => [$n, $where, $size]): $on = isset($live[$k]); ?>
  <section class="card sp-spot">
    <div class="ov-head" style="margin-bottom:6px"><h2><?= h($n) ?></h2><span class="<?= $on ? 'pill ok' : 'pill' ?>"><?= $on ? 'Live' : 'Off' ?></span></div>
    <p class="muted small" style="margin:0 0 6px"><?= h($where) ?></p>
    <p class="small" style="margin:0">Picture: <b><?= h($size) ?></b> px</p>
  </section>
  <?php endforeach; ?>
</div>

<section class="card" id="book">
  <h2><?= $edit ? 'Edit ' . h($edit['name']) : 'Book a sponsor' ?></h2>
  <form method="post" enctype="multipart/form-data" class="sp-form"><?= $csrf ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <label>Sponsor<input name="name" required maxlength="120" value="<?= h((string)($edit['name'] ?? '')) ?>" placeholder="Instant Gaming"></label>
    <label>Spot<select name="slot"><?php foreach (RS_SP_SLOTS as $k => [$n, , $size]): ?><option value="<?= h($k) ?>"<?= ($edit['slot'] ?? '') === $k ? ' selected' : '' ?>><?= h($n . ' · ' . $size) ?></option><?php endforeach; ?></select></label>
    <label>Link (where a click goes)<input name="link" type="url" required maxlength="500" value="<?= h((string)($edit['link'] ?? '')) ?>" placeholder="https://…"></label>
    <label>Picture<?= $edit ? ' (leave empty to keep it)' : '' ?><input name="image" type="file" accept="image/png,image/jpeg,image/webp,image/gif"<?= $edit ? '' : ' required' ?>></label>
    <label>Starts<input name="starts" type="date" value="<?= h((string)($edit['starts'] ?? $today)) ?>"></label>
    <label>Ends (empty = until you stop it)<input name="ends" type="date" value="<?= h((string)($edit['ends'] ?? '')) ?>"></label>
    <label class="span2">Words for screen readers<input name="alt" maxlength="160" value="<?= h((string)($edit['alt'] ?? '')) ?>" placeholder="Instant Gaming: games for less"></label>
    <label class="check span2"><input type="checkbox" name="house" value="1"<?= !empty($edit['house']) ? ' checked' : '' ?>> House ad (one of yours, like Agents of Fandom): shows only while no paid sponsor has the spot, marked "From us"</label>
    <div class="actions span2"><button class="primary"><?= $edit ? 'Save' : 'Book it' ?></button><?php if ($edit): ?><a class="btn" href="sponsors.php">Cancel</a><?php endif; ?></div>
  </form>
</section>

<section class="card">
  <h2>Bookings</h2>
  <?php if (!$rows): ?><p class="muted">Nothing booked yet. Every spot is off, so the site looks exactly as it does now.</p><?php else: ?>
  <div style="overflow-x:auto"><table class="pl-table" style="cursor:default">
    <thead><tr><th></th><th>Sponsor</th><th>Spot</th><th>Dates</th><th>Status</th><th>Views (7 days)</th><th>Views</th><th>Clicks</th><th>Click rate</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$st, $cls] = $state($r); ?>
      <tr>
        <td><img src="<?= h($r['image']) ?>" alt="" style="width:64px;height:40px;object-fit:cover;border-radius:6px;display:block"></td>
        <td><b><?= h($r['name']) ?></b><?= $r['house'] ? ' <span class="pill">House</span>' : '' ?><div class="muted small"><a href="<?= h($r['link']) ?>" target="_blank" rel="noopener"><?= h(parse_url($r['link'], PHP_URL_HOST) ?: $r['link']) ?></a></div></td>
        <td><?= h(RS_SP_SLOTS[$r['slot']][0] ?? $r['slot']) ?></td>
        <td class="small"><?= h(rs_date('M j', strtotime($r['starts'] . ' 12:00 UTC'))) ?> – <?= $r['ends'] ? h(rs_date('M j, Y', strtotime($r['ends'] . ' 12:00 UTC'))) : 'ongoing' ?></td>
        <td><span class="<?= $cls ?>"><?= h($st) ?></span></td>
        <td><?= number_format((int)$r['week']) ?></td>
        <td><?= number_format((int)$r['views']) ?></td>
        <td><?= number_format((int)$r['clicks']) ?></td>
        <td><?= $r['views'] ? round($r['clicks'] / $r['views'] * 100, 2) . '%' : '–' ?></td>
        <td style="display:flex;gap:6px">
          <a class="btn" href="sponsors.php?edit=<?= (int)$r['id'] ?>#book">Edit</a>
          <a class="btn" href="sponsors.php?csv=<?= (int)$r['id'] ?>" title="Day-by-day views and clicks to send the sponsor">Report</a>
          <form method="post" style="margin:0"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="<?= $r['paused'] ? 'resume' : 'pause' ?>"><?= $r['paused'] ? 'Resume' : 'Pause' ?></button></form>
          <form method="post" style="margin:0" onsubmit="return confirm('Delete this booking and its numbers?')"><?= $csrf ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button name="action" value="delete" class="danger">Delete</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>
<style>
.sp-spots{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:14px}
.sp-spot{margin:0}
.sp-form{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 16px}
.sp-form .span2{grid-column:span 2}
@media (max-width:800px){.sp-spots,.sp-form{grid-template-columns:minmax(0,1fr)}.sp-form .span2{grid-column:auto}}
</style>
<?php rs_foot(); ?>
