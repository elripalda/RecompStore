<?php
// RecompStore admin (4.3): Boot profiles. Every recomp's boot profile in one list (how the launcher
// starts it, its Easy Setup, its game settings), what the launcher would skip in each, and bringing in
// the profiles that used to be built into RecompLauncher so they're fixed here, not in a launcher build.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/boot.php';
rs_require_login();
rs_pc_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    try {
        if ($act === 'import') {
            // Saved on top of each one's newest copy, one at a time, so nothing else on it changes.
            $n = 0; $done = [];
            foreach (rs_boot_import_plan() as [$id, , $what, $json]) {
                $a = rs_pc_app($id);
                if (!$a) continue;
                $a['boot'] = $json;
                rs_pc_save($a);
                $n++; $done[$what] = ($done[$what] ?? 0) + 1;
            }
            rs_log('boot-import', 'pc', $n . ' profiles: ' . json_encode($done));
            rs_flash($n ? 'Brought in ' . $n . ' boot profile' . ($n === 1 ? '' : 's') . '. Players get the same as before; Publish PC puts them in the catalog.' : 'Nothing to bring in: the admin already has them all.');
        }
    } catch (Throwable $e) { rs_flash($e->getMessage(), 'err'); }
    header('Location: boot.php');
    exit;
}

$apps = rs_pc_apps();
$bi = rs_boot_builtin();
$plan = rs_boot_import_plan();
$planBy = []; foreach ($plan as [$id, , $what]) $planBy[$id] = $what;
$orphans = rs_boot_orphans();
$filter = (string)($_GET['f'] ?? '');
$rows = []; $count = ['admin' => 0, 'launcher' => 0, 'easy' => 0, 'settings' => 0, 'err' => 0, 'warn' => 0];
foreach ($apps as $a) {
    $own = (string)($a['boot'] ?? '') !== '' ? json_decode((string)$a['boot'], true) : null;
    $src = is_array($own) && $own ? 'admin' : (isset($bi[$a['id']]) ? 'launcher' : '');
    if ($src === '') continue;
    $eff = rs_boot_effective($a);
    $probs = rs_boot_check($eff);
    $e = count(array_filter($probs, fn($p) => $p[0] === 'err')); $w = count($probs) - $e;
    $easy = in_array((string)($eff['files']['how'] ?? ''), RS_BOOT_EASY, true);
    $ns = count((array)($eff['settings']['settings'] ?? []));
    $count[$src]++; if ($easy) $count['easy']++; if ($ns) $count['settings']++; if ($e) $count['err']++; elseif ($w) $count['warn']++;
    if ($filter === 'problems' && !$e && !$w) continue;
    if ($filter === 'easy' && !$easy) continue;
    if ($filter === 'settings' && !$ns) continue;
    if ($filter === 'launcher' && $src !== 'launcher' && !isset($planBy[$a['id']])) continue;
    $rows[] = compact('a', 'src', 'eff', 'probs', 'e', 'w', 'easy', 'ns');
}

rs_head('Boot profiles', true, ['assets/boot.css']);
$f = fn(string $k, string $label) => '<a class="btn' . ($filter === $k ? ' primary' : '') . '" href="boot.php' . ($k !== '' ? '?f=' . $k : '') . '">' . $label . '</a>';
?>
<h1>Boot profiles</h1>
<p class="muted">How RecompLauncher starts each recomp, sets it up from the player's copy (Easy Setup) and which game settings it shows. Edit one from its page; it reaches players with the next Publish PC, without a launcher update.</p>

<div class="card">
  <h2 style="margin-top:0">From the launcher</h2>
  <p>RecompLauncher has <?= count($bi) ?> profiles built in. <?php if ($plan): ?><strong><?= count($plan) ?></strong> of them aren't fully in the admin yet<?php
      $by = []; foreach ($plan as [, , $what]) foreach (explode('+', $what) as $x) $by[$x] = ($by[$x] ?? 0) + 1;
      $bits = []; if (!empty($by['fill'])) $bits[] = $by['fill'] . ' have none here'; if (!empty($by['settings'])) $bits[] = $by['settings'] . ' are missing the launcher\'s game settings'; if (!empty($by['extras'])) $bits[] = $by['extras'] . ' get a fix the launcher adds on top';
      echo ' (' . h(implode(', ', $bits)) . ').'; ?>
  Bringing them in changes nothing for players: the catalog then says what the launcher already does, and from then on they're fixed here.<?php else: ?>All of them are in the admin.<?php endif; ?></p>
  <?php if ($plan): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>"><button name="action" value="import" class="primary">Bring in <?= count($plan) ?> profile<?= count($plan) === 1 ? '' : 's' ?></button> <a class="btn" href="boot.php?f=launcher">See which</a></form><?php endif; ?>
  <?php if ($orphans): ?><p class="muted" style="margin-bottom:0"><?= count($orphans) ?> of the launcher's profiles are for ids that aren't in the admin (renamed or removed): <span class="mono"><?= h(implode(', ', $orphans)) ?></span></p><?php endif; ?>
</div>

<div class="card">
  <p style="display:flex;flex-wrap:wrap;gap:8px;margin-top:0"><?= $f('', 'All (' . ($count['admin'] + $count['launcher']) . ')') ?> <?= $f('easy', 'Easy Setup (' . $count['easy'] . ')') ?> <?= $f('settings', 'With game settings (' . $count['settings'] . ')') ?> <?= $f('problems', 'Needs a look (' . ($count['err'] + $count['warn']) . ')') ?> <?= $f('launcher', 'Only in the launcher (' . count($plan) . ')') ?></p>
  <table class="boot-table">
    <thead><tr><th>Recomp</th><th>Profile</th><th>Where</th><th>The launcher says</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $a = $r['a']; ?>
      <tr>
        <td><a href="pc.php?edit=<?= h(rawurlencode($a['id'])) ?>#boot"><?= h((string)$a['name']) ?></a><br><span class="muted mono" style="font-size:12px"><?= h($a['id']) ?></span><?= $a['hidden'] ? ' <span class="pill">Hidden</span>' : '' ?></td>
        <td><?= $r['easy'] ? '<span class="pill boot-easy" style="margin-left:0">Easy Setup</span> ' : '' ?><?= h(rs_boot_summary($r['eff'])) ?></td>
        <td class="boot-n"><?= $r['src'] === 'admin' ? 'Admin' . (isset($planBy[$a['id']]) ? ' <span class="pill" title="' . h($planBy[$a['id']]) . '">+ launcher</span>' : '') : 'Launcher only' ?></td>
        <td><?php if (!$r['probs']): ?><span class="ok-text" style="color:var(--ok)">Fine</span><?php else: ?><ul class="boot-probs"><?php foreach (array_slice($r['probs'], 0, 3) as [$t, $m]): ?><li class="<?= $t ?>"><?= h($m) ?></li><?php endforeach; ?><?= count($r['probs']) > 3 ? '<li>' . (count($r['probs']) - 3) . ' more on its page</li>' : '' ?></ul><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="muted">None.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php rs_foot();
