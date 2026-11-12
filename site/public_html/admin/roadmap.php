<?php
// (4.8) Roadmap: the features and fixes the team means to do, on one board everyone on the admin
// team can add to. Ideas -> Up next -> Working on (with who's on it, so two people never build the
// same thing across time zones) -> Done. Done ones stay a month.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
rs_require_login();

const RS_RM_COLS = ['idea' => ['Ideas', 'Anything worth doing someday'], 'next' => ['Up next', 'Agreed on, nobody\'s on it yet'],
    'doing' => ['Working on', 'Someone\'s on it: leave it to them'], 'done' => ['Done', 'The last 30 days']];
const RS_RM_AREAS = ['launcher' => 'Launcher', 'site' => 'Website', 'admin' => 'Admin', 'store' => 'PS5 store', 'other' => 'Other'];

function rs_rm_table(): void {
    if (rs_setting('roadmap_schema', '') === '1') return;
    rs_db()->exec("CREATE TABLE IF NOT EXISTS team_roadmap (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(160) NOT NULL,
        body TEXT NULL,
        area VARCHAR(12) NOT NULL DEFAULT 'other',
        link VARCHAR(300) NOT NULL DEFAULT '',
        status VARCHAR(8) NOT NULL DEFAULT 'idea',
        added_by INT NOT NULL DEFAULT 0,
        added_name VARCHAR(80) NOT NULL DEFAULT '',
        on_by INT NOT NULL DEFAULT 0,
        on_name VARCHAR(80) NOT NULL DEFAULT '',
        created DATETIME NOT NULL,
        updated DATETIME NOT NULL,
        done_at DATETIME NULL,
        KEY status (status)
    ) DEFAULT CHARSET=utf8mb4");
    rs_set_setting('roadmap_schema', '1');
}
rs_rm_table();
$db = rs_db();
$me = rs_me();
$uid = (int)$me['id'];
$get = function (int $id) use ($db): array {
    $st = $db->prepare('SELECT * FROM team_roadmap WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) throw new RuntimeException('That one is gone.');
    return $r;
};
$clean = function (): array {
    $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 160));
    if (mb_strlen($title) < 3) throw new RuntimeException('Give it a short title.');
    $link = trim((string)($_POST['link'] ?? ''));
    if ($link !== '' && !preg_match('#^https://[^\s]{4,290}$#', $link)) throw new RuntimeException('The link has to start with https://');
    $area = array_key_exists($_POST['area'] ?? '', RS_RM_AREAS) ? (string)$_POST['area'] : 'other';
    return [$title, trim(mb_substr(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), 0, 4000)), $area, $link];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($act === 'add') {
            [$title, $body, $area, $link] = $clean();
            $to = in_array($_POST['to'] ?? '', ['idea', 'next', 'doing'], true) ? (string)$_POST['to'] : 'idea';
            $db->prepare('INSERT INTO team_roadmap (title, body, area, link, status, added_by, added_name, on_by, on_name, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$title, $body, $area, $link, $to, $uid, rs_me_name(), $to === 'doing' ? $uid : 0, $to === 'doing' ? rs_me_name() : '']);
            rs_flash('Added to ' . RS_RM_COLS[$to][0] . '.');
        } elseif ($act === 'edit') {
            $get($id);
            [$title, $body, $area, $link] = $clean();
            $db->prepare('UPDATE team_roadmap SET title = ?, body = ?, area = ?, link = ?, updated = UTC_TIMESTAMP() WHERE id = ?')->execute([$title, $body, $area, $link, $id]);
            rs_flash('Saved.');
        } elseif ($act === 'take') {
            $r = $get($id);
            if ($r['status'] === 'doing' && (int)$r['on_by'] !== $uid) throw new RuntimeException($r['on_name'] . ' is already on it.');
            $db->prepare("UPDATE team_roadmap SET status = 'doing', on_by = ?, on_name = ?, updated = UTC_TIMESTAMP() WHERE id = ?")->execute([$uid, rs_me_name(), $id]);
            rs_flash('It\'s yours. It shows as yours to everyone.');
        } elseif ($act === 'move') {
            $r = $get($id);
            $to = (string)($_POST['to'] ?? '');
            if (!isset(RS_RM_COLS[$to]) || $to === 'doing') throw new RuntimeException('Pick a column.');
            if ($r['status'] === 'doing' && (int)$r['on_by'] !== $uid && $to !== 'done' && !rs_is_owner()) throw new RuntimeException($r['on_name'] . ' is on it. Ask them first.');
            if ($to === 'done') $db->prepare("UPDATE team_roadmap SET status = 'done', done_at = UTC_TIMESTAMP(), on_name = IF(on_name = '', ?, on_name), updated = UTC_TIMESTAMP() WHERE id = ?")->execute([rs_me_name(), $id]);
            else $db->prepare("UPDATE team_roadmap SET status = ?, done_at = NULL, on_by = 0, on_name = '', updated = UTC_TIMESTAMP() WHERE id = ?")->execute([$to, $id]);
            rs_flash($to === 'done' ? 'Done. Nice.' : 'Moved to ' . RS_RM_COLS[$to][0] . '.');
        } elseif ($act === 'delete') {
            $r = $get($id);
            if ((int)$r['added_by'] !== $uid && !rs_is_owner()) throw new RuntimeException('Only whoever added it (or the owner) can delete it. Move it to Done instead.');
            $db->prepare('DELETE FROM team_roadmap WHERE id = ?')->execute([$id]);
            rs_flash('Deleted.');
        }
        if (in_array($act, ['add', 'edit', 'take', 'move', 'delete'], true)) rs_log('roadmap', $act, isset($title) ? $title : (string)$id);
    } catch (Throwable $e) { rs_flash($e->getMessage(), 'err'); }
    header('Location: roadmap.php' . (isset($_POST['area_f']) && $_POST['area_f'] !== '' ? '?area=' . rawurlencode((string)$_POST['area_f']) : ''));
    exit;
}

$area = array_key_exists($_GET['area'] ?? '', RS_RM_AREAS) ? (string)$_GET['area'] : '';
$rows = $db->query("SELECT * FROM team_roadmap WHERE status <> 'done' OR done_at > (UTC_TIMESTAMP() - INTERVAL 30 DAY) ORDER BY updated DESC")->fetchAll();
$all = $rows;
if ($area !== '') $rows = array_values(array_filter($rows, fn($r) => $r['area'] === $area));
$cols = array_fill_keys(array_keys(RS_RM_COLS), []);
foreach ($rows as $r) $cols[isset(RS_RM_COLS[$r['status']]) ? $r['status'] : 'idea'][] = $r;
$mineN = count(array_filter($all, fn($r) => $r['status'] === 'doing' && (int)$r['on_by'] === $uid));
$csrf = '<input type="hidden" name="csrf" value="' . h(rs_csrf()) . '"><input type="hidden" name="area_f" value="' . h($area) . '">';
$ago = function (string $dt): string {
    $s = max(0, time() - (int)strtotime($dt . ' UTC'));
    return $s < 3600 ? max(1, intdiv($s, 60)) . ' min ago' : ($s < 86400 ? intdiv($s, 3600) . ' h ago' : ($s < 86400 * 45 ? intdiv($s, 86400) . ' d ago' : rs_date('M j', (int)strtotime($dt . ' UTC'))));
};
$areaSel = function (string $have) {
    $o = '';
    foreach (RS_RM_AREAS as $k => $n) $o .= '<option value="' . $k . '"' . ($k === $have ? ' selected' : '') . '>' . h($n) . '</option>';
    return $o;
};
rs_head('Roadmap');
?>
<div class="rm">
  <div class="rm-head">
    <div><h1>Roadmap</h1><p class="muted">What the team means to build or fix. Add ideas, press <b>I'm on it</b> before you start so nobody builds the same thing twice, and check it off when it's in.</p></div>
    <button type="button" class="primary" data-rm-add>+ Add something</button>
  </div>
  <form method="post" class="card rm-new" data-rm-form hidden><?= $csrf ?><input type="hidden" name="action" value="add">
    <div class="rm-grid">
      <label class="wide">What is it<input name="title" maxlength="160" placeholder="Short, like: Sync ROMs between your own PCs" required></label>
      <label>Area<select name="area"><?= $areaSel('launcher') ?></select></label>
      <label>Goes in<select name="to"><option value="idea">Ideas</option><option value="next">Up next</option><option value="doing">Working on (I'm on it)</option></select></label>
      <label class="wide">Details <span class="muted small">optional</span><textarea name="body" rows="3" maxlength="4000" placeholder="What it should do, anything to watch out for"></textarea></label>
      <label class="wide">Link <span class="muted small">optional: a PR, issue or Discord message</span><input name="link" class="mono" placeholder="https://github.com/elripalda/RecompLauncher/pull/…"></label>
    </div>
    <div class="rm-row"><button class="primary">Add it</button><button type="button" data-rm-cancel>Cancel</button></div>
  </form>
  <nav class="rm-filt" aria-label="Area"><a href="roadmap.php"<?= $area === '' ? ' class="on"' : '' ?>>Everything</a><?php foreach (RS_RM_AREAS as $k => $n): $c = count(array_filter($all, fn($r) => $r['area'] === $k && $r['status'] !== 'done')); ?><a href="roadmap.php?area=<?= $k ?>"<?= $area === $k ? ' class="on"' : '' ?>><?= h($n) ?><?= $c ? ' <span>' . $c . '</span>' : '' ?></a><?php endforeach; ?><?php if ($mineN): ?><em><?= $mineN ?> on you</em><?php endif; ?></nav>
  <div class="rm-board">
    <?php foreach (RS_RM_COLS as $k => [$t, $sub]): ?>
    <section class="rm-col rm-<?= $k ?>">
      <header><b><?= h($t) ?></b><span class="count"><?= count($cols[$k]) ?></span><small><?= h($sub) ?></small></header>
      <?php if (!$cols[$k]): ?><p class="rm-empty"><?= h(['idea' => 'No ideas yet.', 'next' => 'Nothing lined up.', 'doing' => 'Nobody\'s on anything.', 'done' => 'Nothing done this month yet.'][$k]) ?></p><?php endif; ?>
      <?php foreach ($cols[$k] as $r): $mine = (int)$r['on_by'] === $uid; ?>
      <article class="rm-card<?= $k === 'doing' && $mine ? ' mine' : '' ?>">
        <div class="rm-card-top"><span class="rm-area rm-a-<?= h($r['area']) ?>"><?= h(RS_RM_AREAS[$r['area']] ?? 'Other') ?></span><?php if ($r['link'] !== ''): ?><a class="rm-link" href="<?= h($r['link']) ?>" target="_blank" rel="noopener noreferrer"><?= h(preg_match('#github\.com/[^/]+/([^/]+)/(pull|issues)/(\d+)#', $r['link'], $mm) ? ($mm[2] === 'pull' ? 'PR' : 'Issue') . ' #' . $mm[3] : 'Link') ?> ↗</a><?php endif; ?></div>
        <b class="rm-title"><?= h($r['title']) ?></b>
        <?php if (trim((string)$r['body']) !== ''): ?><p class="rm-body"><?= nl2br(h(trim((string)$r['body']))) ?></p><?php endif; ?>
        <div class="rm-who">
          <?php if ($k === 'doing'): ?><span class="rm-on"><i></i><?= $mine ? 'You\'re on it' : h($r['on_name']) . ' is on it' ?></span>
          <?php elseif ($k === 'done'): ?><span class="rm-on done"><i></i>Done<?= $r['on_name'] !== '' ? ' by ' . h($r['on_name']) : '' ?> · <?= h($ago((string)$r['done_at'])) ?></span><?php endif; ?>
          <span class="muted small">Added by <?= h($r['added_name']) ?> · <?= h($ago((string)$r['created'])) ?></span>
        </div>
        <div class="rm-acts">
          <?php if ($k === 'idea' || $k === 'next'): ?>
            <form method="post"><?= $csrf ?><input type="hidden" name="action" value="take"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="primary small">I'm on it</button></form>
            <form method="post"><?= $csrf ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="<?= $k === 'idea' ? 'next' : 'idea' ?>"><button class="small"><?= $k === 'idea' ? 'Up next' : 'Back to ideas' ?></button></form>
          <?php elseif ($k === 'doing'): ?>
            <form method="post"><?= $csrf ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="done"><button class="primary small">Done</button></form>
            <?php if ($mine || rs_is_owner()): ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="next"><button class="small">Let it go</button></form>
            <?php else: ?><form method="post"><?= $csrf ?><input type="hidden" name="action" value="take"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="small" disabled title="<?= h($r['on_name']) ?> is on it">Taken</button></form><?php endif; ?>
          <?php else: ?>
            <form method="post"><?= $csrf ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="to" value="next"><button class="small">Not done after all</button></form>
          <?php endif; ?>
          <details class="rm-edit"><summary class="small">Edit</summary>
            <form method="post"><?= $csrf ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <label>What is it<input name="title" maxlength="160" value="<?= h($r['title']) ?>" required></label>
              <label>Area<select name="area"><?= $areaSel((string)$r['area']) ?></select></label>
              <label>Details<textarea name="body" rows="3" maxlength="4000"><?= h((string)$r['body']) ?></textarea></label>
              <label>Link<input name="link" class="mono" value="<?= h($r['link']) ?>"></label>
              <div class="rm-row"><button class="primary small">Save</button></div>
            </form>
            <?php if ((int)$r['added_by'] === $uid || rs_is_owner()): ?><form method="post" onsubmit="return confirm('Delete it for good?')"><?= $csrf ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="danger small">Delete</button></form><?php endif; ?>
          </details>
        </div>
      </article>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
  </div>
</div>
<style>
.adm-body:has(.rm){max-width:none}.adm-body:has(.rm) main{max-width:none}.adm-body:has(.rm) .pins{display:none}
.rm-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:4px 0 14px}.rm-head h1{margin:0 0 4px}.rm-head p{margin:0;max-width:720px}.rm-head>button{flex:none}
.rm-new{margin:0 0 14px}.rm-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.rm-grid label{display:flex;flex-direction:column;gap:6px}.rm-grid .wide{grid-column:1/-1}
.rm-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.rm-filt{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
.rm-filt a{padding:6px 12px;border-radius:99px;border:1px solid var(--line);color:var(--muted);font-weight:600;font-size:13px;text-decoration:none;display:inline-flex;gap:6px;align-items:center}
.rm-filt a.on{color:var(--frost);border-color:rgba(94,245,236,.5);background:rgba(94,245,236,.1)}.rm-filt a span{font-size:11.5px;padding:0 6px;border-radius:99px;background:rgba(230,232,235,.1)}
.rm-filt em{margin-left:auto;font-style:normal;font-size:12.5px;font-weight:600;color:var(--cyan-ink,#5ef5ec)}
.rm-board{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;align-items:start}
.rm-col{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:10px;min-width:0;display:flex;flex-direction:column;gap:8px}
.rm-col header{display:grid;grid-template-columns:auto auto 1fr;align-items:center;gap:8px;padding:4px 4px 6px}.rm-col header small{grid-column:1/-1;color:var(--muted);font-size:12px}
.rm-col header b::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:8px;vertical-align:1px;background:var(--muted)}
.rm-next header b::before{background:var(--cyan,#34F2E8)}.rm-doing header b::before{background:#ffc857}.rm-done header b::before{background:#5fe3a1}
.rm-empty{margin:4px;color:var(--muted);font-size:13px}
.rm-card{display:flex;flex-direction:column;gap:8px;padding:11px 12px;border-radius:10px;background:var(--surface,#171b21);border:1px solid var(--line)}
.rm-card.mine{border-color:rgba(255,200,87,.55)}
.rm-card-top{display:flex;align-items:center;gap:8px}
.rm-area{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:5px;background:rgba(230,232,235,.08);color:var(--soft,#c9d1d9)}
.rm-a-launcher{background:rgba(52,242,232,.12);color:#5ef5ec}.rm-a-site{background:rgba(143,193,255,.14);color:#a9cdff}.rm-a-admin{background:rgba(205,184,255,.14);color:#d7c6ff}.rm-a-store{background:rgba(255,200,87,.13);color:#ffd27a}
.rm-link{margin-left:auto;font-size:12px;font-weight:600;color:var(--cyan-ink,#5ef5ec)}
.rm-title{font-size:14px;line-height:1.35}
.rm-body{margin:0;color:var(--soft,#c9d1d9);font-size:13px;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.rm-who{display:flex;flex-direction:column;gap:3px}
.rm-on{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:#ffc857}.rm-on i{width:7px;height:7px;border-radius:50%;background:currentColor}.rm-on.done{color:#5fe3a1}
.rm-acts{display:flex;gap:6px;flex-wrap:wrap;align-items:center}.rm-acts form{margin:0}
.rm-edit{margin-left:auto}.rm-edit[open]{flex-basis:100%;margin-left:0}.rm-edit summary{cursor:pointer;color:var(--muted);list-style:none;display:inline-block;padding:2px 0}.rm-edit summary::-webkit-details-marker{display:none}
.rm-edit form{display:flex;flex-direction:column;gap:8px;margin-top:8px}.rm-edit label{display:flex;flex-direction:column;gap:4px;font-size:12.5px}
@media (max-width:1100px){.rm-board{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:640px){.rm-board{grid-template-columns:1fr}.rm-grid{grid-template-columns:1fr}.rm-head{flex-direction:column;align-items:stretch}}
</style>
<script>
(function () {
  var f = document.querySelector('[data-rm-form]');
  document.querySelector('[data-rm-add]').addEventListener('click', function () { f.hidden = false; f.querySelector('input[name=title]').focus(); });
  f.querySelector('[data-rm-cancel]').addEventListener('click', function () { f.hidden = true; });
})();
</script>
<?php rs_foot();
