<?php
// RecompStore admin (3.0): Versions. A game with more than one version (two PC recomps of it, or a
// PC recomp and its PS5 port) gets one page on the site with every version to pick from. Here you
// put versions under a game, pick the Recommended one, and give PS5 versions the game's art.
// Changes go out with Publish PC (and Publish on the PS5 side for new PS5 art).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();
rs_pc_tables();
rs_titles_table();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $tid = (string)($_POST['title'] ?? '');
    $back = 'titles.php' . ($tid !== '' ? '#t-' . rawurlencode($tid) : '');
    try {
        if ($act === 'create' || $act === 'group') {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120);
            if ($name === '') throw new RuntimeException('Give the game a name.');
            $origin = isset(RS_PC_ORIGINS[$_POST['origin'] ?? '']) ? (string)$_POST['origin'] : 'other';
            $t = array_merge(rs_title_defaults(), ['id' => rs_title_new_id($name), 'name' => $name, 'origin' => $origin]);
            rs_title_save($t);
            foreach ((array)($_POST['pc'] ?? []) as $id) if (rs_pc_app((string)$id)) rs_title_add_member($t['id'], 'pc', (string)$id);
            foreach ((array)($_POST['ps5'] ?? []) as $id) if (rs_game((string)$id)) rs_title_add_member($t['id'], 'ps5', (string)$id);
            rs_log('title-add', $t['id'], $name);
            rs_flash($name . ' is set up. Publish PC to put it on the site.');
            $back = 'titles.php#t-' . rawurlencode($t['id']);
        } elseif ($act === 'save') {
            $t = rs_title($tid);
            if (!$t) throw new RuntimeException('That game is gone.');
            $t['name'] = mb_substr(trim((string)($_POST['name'] ?? $t['name'])), 0, 120) ?: $t['name'];
            $t['origin'] = isset(RS_PC_ORIGINS[$_POST['origin'] ?? '']) ? (string)$_POST['origin'] : $t['origin'];
            $pick = (string)($_POST['pick'] ?? '');
            $t['pick'] = preg_match('/^(pc|ps5):[A-Za-z0-9_-]{1,64}$/', $pick) ? $pick : '';
            rs_title_save($t);
            if (($add = (string)($_POST['add_pc'] ?? '')) !== '' && rs_pc_app($add)) rs_title_add_member($tid, 'pc', $add);
            if (($add = (string)($_POST['add_ps5'] ?? '')) !== '' && rs_game($add)) rs_title_add_member($tid, 'ps5', $add);
            rs_flash('Saved ' . $t['name'] . '. Publish PC to put it on the site.');
        } elseif ($act === 'remove') {
            rs_title_remove_member($tid, (string)($_POST['kind'] ?? ''), (string)($_POST['id'] ?? ''));
            rs_flash('Taken out. Its own page stays as it was.');
        } elseif ($act === 'art') {
            $t = rs_title($tid);
            if (!$t) throw new RuntimeException('That game is gone.');
            $n = rs_title_ps5_art($t);
            rs_flash($n ? 'Art added to ' . $n . ' PS5 ' . ($n === 1 ? 'version' : 'versions') . '. Publish and sign on the PS5 side to send it out.' : 'Its PS5 versions already have their art.');
        } elseif ($act === 'delete') {
            $t = rs_title($tid);
            rs_title_delete($tid);
            rs_log('title-delete', $tid, $t['name'] ?? '');
            rs_flash('Ungrouped. Each version keeps its own page.');
            $back = 'titles.php';
        }
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: ' . $back);
    exit;
}

$titles = rs_titles();
$pcApps = [];
foreach (rs_pc_apps() as $a) $pcApps[$a['id']] = $a;
$ps5 = [];
foreach (rs_games() as $g) $ps5[$g['id']] = $g;
$recent = rs_title_recent_counts();
$suggest = rs_title_suggestions();
$inTitle = ['pc' => [], 'ps5' => []];
foreach ($titles as $t) { foreach ($t['pc'] as $id) $inTitle['pc'][$id] = $t['name']; foreach ($t['ps5'] as $id) $inTitle['ps5'][$id] = $t['name']; }
$origin = fn(string $k) => RS_PC_ORIGINS[$k] ?? 'Other';
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '">';
$pcOptions = function (string $sel = '') use ($pcApps, $inTitle): string {
    $o = '<option value="">Add a PC recomp…</option>';
    foreach ($pcApps as $id => $a) if (!isset($inTitle['pc'][$id])) $o .= '<option value="' . h($id) . '"' . ($sel === $id ? ' selected' : '') . '>' . h($a['name'] . ($a['game'] !== '' && $a['game'] !== $a['name'] ? ' (' . $a['game'] . ')' : '')) . '</option>';
    return $o;
};
$ps5Options = function () use ($ps5, $inTitle): string {
    $o = '<option value="">Add a PS5 app…</option>';
    foreach ($ps5 as $id => $g) if (!isset($inTitle['ps5'][$id])) $o .= '<option value="' . h($id) . '">' . h($g['name'] . ' · ' . $id) . '</option>';
    return $o;
};
$verdicts = function (string $id) use ($recent): string {
    $c = $recent[$id] ?? [];
    $n = ($c['runs'] ?? 0) + ($c['issues'] ?? 0) + ($c['broken'] ?? 0);
    return $n ? round(100 * ($c['runs'] ?? 0) / $n) . '% plays great · ' . $n . ($n === 1 ? ' report' : ' reports') . ' in 60 days' : 'No reports in 60 days';
};

rs_head('Versions', true, ['assets/titles.css']);
?>
<section class="head-row">
  <div>
    <h1>Versions</h1>
    <p class="muted">A game with more than one version gets one page on the site, with every version to pick from: two PC recomps of it, or a PC recomp and its PS5 port. Lists show the game once. A version without art borrows it from another version of the same game. Changes go out with <b>Publish PC</b>.</p>
  </div>
</section>

<?php if ($suggest): ?>
<section class="card">
  <h2>Looks like the same game <span class="pill cyan"><?= count($suggest) ?></span></h2>
  <p class="muted">These share a game name. Group them if they are versions of one game.</p>
  <div class="tt-sugg">
  <?php foreach ($suggest as $s): ?>
    <form method="post" class="tt-sugg-row"><?= $csrf ?><input type="hidden" name="action" value="group"><input type="hidden" name="name" value="<?= h($s['name']) ?>"><input type="hidden" name="origin" value="<?= h($s['origin']) ?>">
      <div><strong><?= h($s['name']) ?></strong><small class="muted"><?= h($origin($s['origin'])) ?></small></div>
      <div class="tt-chips">
        <?php foreach ($s['pc'] as $id): ?><span class="tt-chip"><input type="hidden" name="pc[]" value="<?= h($id) ?>"><em>PC</em><?= h($pcApps[$id]['name'] ?? $id) ?></span><?php endforeach; ?>
        <?php foreach ($s['ps5'] as $id): ?><span class="tt-chip"><input type="hidden" name="ps5[]" value="<?= h($id) ?>"><em class="ps5">PS5</em><?= h($ps5[$id]['name'] ?? $id) ?></span><?php endforeach; ?>
      </div>
      <button class="primary">Group these</button>
    </form>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="card">
  <h2>New game</h2>
  <form method="post" class="tt-new"><?= $csrf ?><input type="hidden" name="action" value="create">
    <label>Game<input name="name" required maxlength="120" placeholder="Jet Set Radio Future"></label>
    <label>Console<select name="origin"><?php foreach (RS_PC_ORIGINS as $k => $n): ?><option value="<?= h($k) ?>"><?= h($n) ?></option><?php endforeach; ?></select></label>
    <label>A PC recomp<select name="pc[]"><?= $pcOptions() ?></select></label>
    <label>A PS5 app<select name="ps5[]"><?= $ps5Options() ?></select></label>
    <div class="actions"><button class="primary">Add the game</button></div>
  </form>
  <p class="muted small">Add more versions to it afterwards, below.</p>
</section>

<section class="card">
  <h2>Games with versions <span class="pill<?= $titles ? ' cyan' : '' ?>"><?= count($titles) ?></span></h2>
  <?php if (!$titles): ?><p class="muted">None yet. Group a suggestion above or add a game.</p><?php endif; ?>
  <div class="tt-list">
  <?php foreach ($titles as $t):
    $pcShown = array_filter($t['pc'], fn($id) => isset($pcApps[$id]) && !$pcApps[$id]['hidden']);
    $ps5Shown = array_values(array_filter($t['ps5'], fn($id) => isset($ps5[$id]) && empty($ps5[$id]['hidden'])));
    $auto = rs_title_pick(array_merge($t, ['pick' => '']), array_intersect_key($pcApps, array_flip($pcShown)), $ps5Shown, $recent);
    $total = count($t['pc']) + count($t['ps5']);
    $needArt = array_filter($t['ps5'], fn($id) => isset($ps5[$id]) && ($ps5[$id]['icon'] === '' || $ps5[$id]['hero'] === ''));
  ?>
    <article class="tt-game" id="t-<?= h($t['id']) ?>">
      <form method="post" class="tt-form"><?= $csrf ?><input type="hidden" name="action" value="save"><input type="hidden" name="title" value="<?= h($t['id']) ?>">
        <div class="tt-head">
          <label class="tt-name">Game<input name="name" value="<?= h($t['name']) ?>" maxlength="120"></label>
          <label>Console<select name="origin"><?php foreach (RS_PC_ORIGINS as $k => $n): ?><option value="<?= h($k) ?>"<?= $t['origin'] === $k ? ' selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select></label>
          <span class="pill<?= $total >= 2 ? ' cyan' : '' ?>"><?= $total ?> <?= $total === 1 ? 'version' : 'versions' ?></span>
        </div>
        <?php if ($total < 2): ?><p class="muted small">It needs two versions before the site shows a picker.</p><?php endif; ?>
        <table class="tt-table">
          <thead><tr><th>Recommended</th><th>Version</th><th>Players</th><th></th></tr></thead>
          <tbody>
            <tr><td><label class="check"><input type="radio" name="pick" value=""<?= $t['pick'] === '' ? ' checked' : '' ?>> Best rated</label></td><td colspan="3" class="muted small">Right now: <?= h($auto === '' ? 'none shown yet' : (str_starts_with($auto, 'pc:') ? ($pcApps[substr($auto, 3)]['name'] ?? $auto) : ($ps5[substr($auto, 4)]['name'] ?? $auto))) ?></td></tr>
            <?php foreach ($t['pc'] as $id): $a = $pcApps[$id] ?? null; ?>
            <tr<?= !$a || $a['hidden'] ? ' class="dim"' : '' ?>>
              <td><label class="check"><input type="radio" name="pick" value="pc:<?= h($id) ?>"<?= $t['pick'] === "pc:$id" ? ' checked' : '' ?>> Pin</label></td>
              <td><span class="tt-chip"><em>PC</em><?= $a ? '<a href="pc.php?edit=' . h(rawurlencode($id)) . '#edit">' . h($a['name']) . '</a>' : h($id) . ' (gone)' ?></span><small class="muted"><?= $a ? h(($a['author'] ?: '?') . ' · ' . ($a['version'] ?: 'no release') . ($a['hidden'] ? ' · hidden' : '')) : '' ?></small></td>
              <td class="small"><?= h($verdicts($id)) ?></td>
              <td><button form="rm-<?= h($t['id'] . '-pc-' . $id) ?>" class="linkish">Take out</button></td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($t['ps5'] as $id): $g = $ps5[$id] ?? null; ?>
            <tr<?= !$g || !empty($g['hidden']) ? ' class="dim"' : '' ?>>
              <td><label class="check"><input type="radio" name="pick" value="ps5:<?= h($id) ?>"<?= $t['pick'] === "ps5:$id" ? ' checked' : '' ?>> Pin</label></td>
              <td><span class="tt-chip"><em class="ps5">PS5</em><?= $g ? '<a href="edit.php?id=' . h(rawurlencode($id)) . '">' . h($g['name']) . '</a>' : h($id) . ' (gone)' ?></span><small class="muted"><?= $g ? h($id . ' · ' . ($g['version'] ?: 'no version') . (!empty($g['hidden']) ? ' · hidden' : '') . ($g['icon'] === '' || $g['hero'] === '' ? ' · no art' : '')) : '' ?></small></td>
              <td class="small muted">Reports come with accounts</td>
              <td><button form="rm-<?= h($t['id'] . '-ps5-' . $id) ?>" class="linkish">Take out</button></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="tt-add">
          <select name="add_pc"><?= $pcOptions() ?></select>
          <select name="add_ps5"><?= $ps5Options() ?></select>
          <button class="primary">Save</button>
        </div>
      </form>
      <?php foreach ($t['pc'] as $id): ?><form method="post" id="rm-<?= h($t['id'] . '-pc-' . $id) ?>" hidden><?= $csrf ?><input type="hidden" name="action" value="remove"><input type="hidden" name="title" value="<?= h($t['id']) ?>"><input type="hidden" name="kind" value="pc"><input type="hidden" name="id" value="<?= h($id) ?>"></form><?php endforeach; ?>
      <?php foreach ($t['ps5'] as $id): ?><form method="post" id="rm-<?= h($t['id'] . '-ps5-' . $id) ?>" hidden><?= $csrf ?><input type="hidden" name="action" value="remove"><input type="hidden" name="title" value="<?= h($t['id']) ?>"><input type="hidden" name="kind" value="ps5"><input type="hidden" name="id" value="<?= h($id) ?>"></form><?php endforeach; ?>
      <div class="tt-foot">
        <?php if ($needArt && $t['pc']): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="art"><input type="hidden" name="title" value="<?= h($t['id']) ?>"><button>Give its PS5 <?= count($needArt) === 1 ? 'version' : 'versions' ?> the game's art</button></form><?php endif; ?>
        <form method="post" onsubmit="return confirm('Ungroup <?= h(addslashes($t['name'])) ?>? Each version keeps its own page.')"><?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="title" value="<?= h($t['id']) ?>"><button class="danger">Ungroup</button></form>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php rs_foot();
