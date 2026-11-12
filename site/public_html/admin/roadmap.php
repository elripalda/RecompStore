<?php
// (4.8) Roadmap: the features and fixes the team means to do, on one board everyone on the admin
// team can add to. Ideas -> Up next -> Working on (with who's on it, so two people never build the
// same thing across time zones) -> Done. Done ones stay a month. Everything happens in place: add with
// Enter and keep going, drag a card to another column, edit it on the card. No page reloads.
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
function rs_rm_ago(?string $dt): string {
    if (!$dt) return '';
    $s = max(0, time() - (int)strtotime($dt . ' UTC'));
    return $s < 60 ? 'just now' : ($s < 3600 ? intdiv($s, 60) . ' min ago' : ($s < 86400 ? intdiv($s, 3600) . ' h ago' : ($s < 86400 * 45 ? intdiv($s, 86400) . ' d ago' : rs_date('M j', (int)strtotime($dt . ' UTC')))));
}
function rs_rm_areas(string $have): string {
    $o = '';
    foreach (RS_RM_AREAS as $k => $n) $o .= '<option value="' . $k . '"' . ($k === $have ? ' selected' : '') . '>' . h($n) . '</option>';
    return $o;
}
// The filters and the board (the page, and every answer the page's script gets back).
function rs_rm_board(string $area): string {
    $uid = (int)(rs_me()['id'] ?? 0);
    $owner = rs_is_owner();
    $all = rs_db()->query("SELECT * FROM team_roadmap WHERE status <> 'done' OR done_at > (UTC_TIMESTAMP() - INTERVAL 30 DAY) ORDER BY updated DESC")->fetchAll();
    $cols = array_fill_keys(array_keys(RS_RM_COLS), []);
    $per = array_fill_keys(array_keys(RS_RM_AREAS), 0);
    $mineN = 0;
    foreach ($all as $r) {
        if ($r['status'] !== 'done' && isset($per[$r['area']])) $per[$r['area']]++;
        if ($r['status'] === 'doing' && (int)$r['on_by'] === $uid) $mineN++;
        if ($area === '' || $r['area'] === $area) $cols[isset(RS_RM_COLS[$r['status']]) ? $r['status'] : 'idea'][] = $r;
    }
    $btn = fn(string $act, int $id, string $label, string $cls = '', string $to = '') => '<button type="button" class="small' . ($cls !== '' ? ' ' . $cls : '') . '" data-rm="' . $act . '" data-id="' . $id . '"' . ($to !== '' ? ' data-to="' . $to . '"' : '') . '>' . h($label) . '</button>';
    $o = '<nav class="rm-filt" aria-label="Area"><a href="roadmap.php" data-area=""' . ($area === '' ? ' class="on"' : '') . '>Everything</a>';
    foreach (RS_RM_AREAS as $k => $n) $o .= '<a href="roadmap.php?area=' . $k . '" data-area="' . $k . '"' . ($area === $k ? ' class="on"' : '') . '>' . h($n) . ($per[$k] ? ' <span>' . $per[$k] . '</span>' : '') . '</a>';
    $o .= ($mineN ? '<em>' . $mineN . ' on you</em>' : '') . '</nav><div class="rm-board">';
    foreach (RS_RM_COLS as $k => [$t, $sub]) {
        $o .= '<section class="rm-col rm-' . $k . '" data-col="' . $k . '"><header><b>' . h($t) . '</b><span class="count">' . count($cols[$k]) . '</span><small>' . h($sub) . '</small></header>';
        if (!$cols[$k]) $o .= '<p class="rm-empty">' . h(['idea' => 'No ideas yet. Add one above.', 'next' => 'Nothing lined up. Drag an idea here.', 'doing' => 'Nobody\'s on anything. Drag something here to take it.', 'done' => 'Nothing done this month yet.'][$k]) . '</p>';
        foreach ($cols[$k] as $r) {
            $id = (int)$r['id'];
            $mine = (int)$r['on_by'] === $uid;
            $canDel = (int)$r['added_by'] === $uid || $owner;
            $link = (string)$r['link'];
            $linkName = $link === '' ? '' : (preg_match('#github\.com/[^/]+/[^/]+/(pull|issues)/(\d+)#', $link, $mm) ? ($mm[1] === 'pull' ? 'PR' : 'Issue') . ' #' . $mm[2] : (str_contains($link, 'discord') ? 'Discord' : 'Link'));
            $o .= '<article class="rm-card' . ($k === 'doing' && $mine ? ' mine' : '') . '" draggable="true" data-id="' . $id . '" data-status="' . h($k) . '"' . ($k === 'doing' && !$mine && !$owner ? ' data-locked="' . h((string)$r['on_name']) . '"' : '') . '>'
                . '<div class="rm-card-top"><span class="rm-area rm-a-' . h((string)$r['area']) . '">' . h(RS_RM_AREAS[$r['area']] ?? 'Other') . '</span>'
                . ($link !== '' ? '<a class="rm-link" href="' . h($link) . '" target="_blank" rel="noopener noreferrer">' . h($linkName) . ' ↗</a>' : '') . '</div>'
                . '<b class="rm-title">' . h((string)$r['title']) . '</b>'
                . (trim((string)$r['body']) !== '' ? '<p class="rm-body" data-rm-more>' . nl2br(h(trim((string)$r['body']))) . '</p>' : '')
                . '<div class="rm-who">'
                . ($k === 'doing' ? '<span class="rm-on"><i></i>' . ($mine ? 'You\'re on it' : h((string)$r['on_name']) . ' is on it') . '</span>' : '')
                . ($k === 'done' ? '<span class="rm-on done"><i></i>Done' . ((string)$r['on_name'] !== '' ? ' by ' . h((string)$r['on_name']) : '') . ' · ' . h(rs_rm_ago((string)$r['done_at'])) . '</span>' : '')
                . '<span class="muted small">Added by ' . h((string)$r['added_name']) . ' · ' . h(rs_rm_ago((string)$r['created'])) . '</span></div>'
                . '<div class="rm-acts">';
            if ($k === 'idea' || $k === 'next') $o .= $btn('take', $id, 'I\'m on it', 'primary') . $btn('move', $id, $k === 'idea' ? 'Up next' : 'Back to ideas', '', $k === 'idea' ? 'next' : 'idea');
            elseif ($k === 'doing') $o .= $btn('move', $id, 'Done', 'primary', 'done') . ($mine || $owner ? $btn('move', $id, 'Let it go', '', 'next') : '');
            else $o .= $btn('move', $id, 'Not done after all', '', 'next');
            $o .= '<button type="button" class="small rm-edit-btn" data-rm-edit aria-expanded="false">Edit</button></div>'
                . '<form class="rm-edit" data-rm-form="edit" hidden><input type="hidden" name="id" value="' . $id . '">'
                . '<label>What is it<input name="title" maxlength="160" value="' . h((string)$r['title']) . '" required></label>'
                . '<label>Area<select name="area">' . rs_rm_areas((string)$r['area']) . '</select></label>'
                . '<label>Details<textarea name="body" rows="3" maxlength="4000">' . h((string)$r['body']) . '</textarea></label>'
                . '<label>Link<input name="link" class="mono" value="' . h($link) . '" placeholder="https://"></label>'
                . '<div class="rm-row"><button class="primary small">Save</button><button type="button" class="small" data-rm-edit>Cancel</button>'
                . ($canDel ? '<button type="button" class="danger small" data-rm="delete" data-id="' . $id . '" data-confirm="Click again to delete">Delete</button>' : '') . '</div></form>'
                . '</article>';
        }
        $o .= '</section>';
    }
    return $o . '</div>';
}

rs_rm_table();
$db = rs_db();
$uid = (int)rs_me()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $ok = true; $msg = '';
    $get = function (int $id) use ($db): array {
        $st = $db->prepare('SELECT * FROM team_roadmap WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) throw new RuntimeException('That one is gone. Someone may have deleted it.');
        return $r;
    };
    $clean = function (): array {
        $title = trim((string)preg_replace('/\s+/u', ' ', mb_substr((string)($_POST['title'] ?? ''), 0, 160)));
        if (mb_strlen($title) < 2) throw new RuntimeException('Give it a short title.');
        $link = trim((string)($_POST['link'] ?? ''));
        if ($link !== '' && !preg_match('#^https?://\S{3,290}$#i', $link)) throw new RuntimeException('The link should start with https://');
        $area = array_key_exists((string)($_POST['area'] ?? ''), RS_RM_AREAS) ? (string)$_POST['area'] : 'other';
        return [$title, trim(mb_substr(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')), 0, 4000)), $area, $link];
    };
    try {
        if ($act === 'add') {
            [$title, $body, $area, $link] = $clean();
            $to = in_array($_POST['to'] ?? '', ['idea', 'next', 'doing'], true) ? (string)$_POST['to'] : 'idea';
            $db->prepare('INSERT INTO team_roadmap (title, body, area, link, status, added_by, added_name, on_by, on_name, created, updated) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$title, $body, $area, $link, $to, $uid, rs_me_name(), $to === 'doing' ? $uid : 0, $to === 'doing' ? rs_me_name() : '']);
            $msg = 'Added to ' . RS_RM_COLS[$to][0] . '.';
        } elseif ($act === 'edit') {
            $get($id);
            [$title, $body, $area, $link] = $clean();
            $db->prepare('UPDATE team_roadmap SET title = ?, body = ?, area = ?, link = ?, updated = UTC_TIMESTAMP() WHERE id = ?')->execute([$title, $body, $area, $link, $id]);
            $msg = 'Saved.';
        } elseif ($act === 'take' || ($act === 'move' && ($_POST['to'] ?? '') === 'doing')) {
            $r = $get($id);
            if ($r['status'] === 'doing' && (int)$r['on_by'] !== $uid) throw new RuntimeException($r['on_name'] . ' is already on it.');
            $db->prepare("UPDATE team_roadmap SET status = 'doing', on_by = ?, on_name = ?, done_at = NULL, updated = UTC_TIMESTAMP() WHERE id = ?")->execute([$uid, rs_me_name(), $id]);
            $msg = 'It\'s yours now. Everyone sees you\'re on it.';
        } elseif ($act === 'move') {
            $r = $get($id);
            $to = (string)($_POST['to'] ?? '');
            if (!isset(RS_RM_COLS[$to])) throw new RuntimeException('Pick a column.');
            if ($to !== $r['status']) {
                if ($r['status'] === 'doing' && (int)$r['on_by'] !== $uid && $to !== 'done' && !rs_is_owner()) throw new RuntimeException($r['on_name'] . ' is on it. Ask them first.');
                if ($to === 'done') $db->prepare("UPDATE team_roadmap SET status = 'done', done_at = UTC_TIMESTAMP(), on_name = IF(on_name = '', ?, on_name), updated = UTC_TIMESTAMP() WHERE id = ?")->execute([rs_me_name(), $id]);
                else $db->prepare("UPDATE team_roadmap SET status = ?, done_at = NULL, on_by = 0, on_name = '', updated = UTC_TIMESTAMP() WHERE id = ?")->execute([$to, $id]);
                $msg = $to === 'done' ? 'Done. Nice.' : 'Moved to ' . RS_RM_COLS[$to][0] . '.';
            }
        } elseif ($act === 'delete') {
            $r = $get($id);
            if ((int)$r['added_by'] !== $uid && !rs_is_owner()) throw new RuntimeException('Only whoever added it (or the owner) can delete it. Move it to Done instead.');
            $db->prepare('DELETE FROM team_roadmap WHERE id = ?')->execute([$id]);
            $msg = 'Deleted.';
        } else throw new RuntimeException('That button doesn\'t do anything.');
        if ($msg !== '') rs_log('roadmap', $act, $title ?? ($r['title'] ?? (string)$id));
    } catch (Throwable $e) { $ok = false; $msg = $e->getMessage(); }
    $area = array_key_exists((string)($_POST['area_f'] ?? ''), RS_RM_AREAS) ? (string)$_POST['area_f'] : '';
    // The page's script: the new board straight back. Without it: back to the page.
    if (!empty($_SERVER['HTTP_X_RM'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $ok, 'msg' => $msg, 'html' => rs_rm_board($area)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($msg !== '') rs_flash($msg, $ok ? 'ok' : 'err');
    header('Location: roadmap.php' . ($area !== '' ? '?area=' . rawurlencode($area) : ''));
    exit;
}
if (isset($_GET['board'])) { // the filters, without a reload
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo rs_rm_board(array_key_exists((string)$_GET['board'], RS_RM_AREAS) ? (string)$_GET['board'] : '');
    exit;
}

$area = array_key_exists((string)($_GET['area'] ?? ''), RS_RM_AREAS) ? (string)$_GET['area'] : '';
rs_head('Roadmap');
?>
<div class="rm" data-csrf="<?= h(rs_csrf()) ?>" data-area="<?= h($area) ?>">
  <div class="rm-head">
    <h1>Roadmap</h1>
    <p class="muted">What the team means to build or fix. Press <b>I'm on it</b> (or drag a card to Working on) before you start, so nobody builds the same thing twice.</p>
  </div>
  <form class="card rm-quick" data-rm-form="add">
    <div class="rm-quick-row">
      <input name="title" maxlength="160" placeholder="Add something: type it and press Enter" aria-label="What is it" required autocomplete="off">
      <select name="area" aria-label="Area"><?= rs_rm_areas('launcher') ?></select>
      <select name="to" aria-label="Goes in"><option value="idea">Ideas</option><option value="next">Up next</option><option value="doing">Working on (me)</option></select>
      <button type="button" class="rm-more-btn" data-rm-more-btn aria-expanded="false">Details</button>
      <button class="primary">Add</button>
    </div>
    <div class="rm-quick-more" data-rm-more-box hidden>
      <textarea name="body" rows="3" maxlength="4000" placeholder="Details (optional): what it should do, anything to watch out for"></textarea>
      <input name="link" class="mono" placeholder="Link (optional): a PR, issue or Discord message">
    </div>
  </form>
  <div data-rm-board><?= rs_rm_board($area) ?></div>
</div>
<div class="rm-toast" data-rm-toast role="status" hidden></div>
<style>
.adm-body:has(.rm){max-width:none}.adm-body:has(.rm) main{max-width:none}.adm-body:has(.rm) .pins{display:none}
.rm-head{margin:4px 0 14px}.rm-head h1{margin:0 0 4px}.rm-head p{margin:0;max-width:760px}
.rm-quick{margin:0 0 14px;padding:12px}
.rm-quick-row{display:grid;grid-template-columns:minmax(0,1fr) 150px 170px auto auto;gap:8px;align-items:center}
.rm-quick-row input{min-width:0}
.rm-more-btn[aria-expanded=true]{background:rgba(94,245,236,.12);border-color:rgba(94,245,236,.5);color:var(--cyan-ink)}
.rm-quick-more{display:grid;grid-template-columns:minmax(0,2fr) minmax(0,1fr);gap:8px;margin-top:8px;align-items:start}.rm-quick-more[hidden]{display:none}.rm-quick-more textarea{resize:vertical}
.rm-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:4px}
.rm-filt{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
.rm-filt a{padding:6px 12px;border-radius:99px;border:1px solid var(--line);color:var(--muted);font-weight:600;font-size:13px;text-decoration:none;display:inline-flex;gap:6px;align-items:center}
.rm-filt a.on{color:var(--frost);border-color:rgba(94,245,236,.5);background:rgba(94,245,236,.1)}.rm-filt a span{font-size:11.5px;padding:0 6px;border-radius:99px;background:rgba(230,232,235,.1)}
.rm-filt em{margin-left:auto;font-style:normal;font-size:12.5px;font-weight:600;color:var(--cyan-ink,#5ef5ec)}
.rm-board{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;align-items:start}
.rm-col{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:10px;min-width:0;display:flex;flex-direction:column;gap:8px;min-height:120px;transition:border-color .12s,background .12s}
.rm-col.drop{border-color:var(--cyan,#34F2E8);background:rgba(52,242,232,.05)}
.rm-col header{display:grid;grid-template-columns:auto auto 1fr;align-items:center;gap:8px;padding:4px 4px 6px}.rm-col header small{grid-column:1/-1;color:var(--muted);font-size:12px}
.rm-col header b::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:8px;vertical-align:1px;background:var(--muted)}
.rm-next header b::before{background:var(--cyan,#34F2E8)}.rm-doing header b::before{background:#ffc857}.rm-done header b::before{background:#5fe3a1}
.rm-empty{margin:4px;color:var(--muted);font-size:13px}
.rm-card{display:flex;flex-direction:column;gap:8px;padding:11px 12px;border-radius:10px;background:var(--surface,#171b21);border:1px solid var(--line);cursor:grab}
.rm-card:active{cursor:grabbing}.rm-card.dragging{opacity:.45}.rm-card.mine{border-color:rgba(255,200,87,.55)}.rm-card.busy{opacity:.6;pointer-events:none}
.rm-card-top{display:flex;align-items:center;gap:8px}
.rm-area{font-size:11.5px;font-weight:700;padding:2px 8px;border-radius:5px;background:rgba(230,232,235,.08);color:var(--soft,#c9d1d9)}
.rm-a-launcher{background:rgba(52,242,232,.12);color:#5ef5ec}.rm-a-site{background:rgba(143,193,255,.14);color:#a9cdff}.rm-a-admin{background:rgba(205,184,255,.14);color:#d7c6ff}.rm-a-store{background:rgba(255,200,87,.13);color:#ffd27a}
.rm-link{margin-left:auto;font-size:12px;font-weight:600;color:var(--cyan-ink,#5ef5ec)}
.rm-title{font-size:14px;line-height:1.35;overflow-wrap:anywhere}
.rm-body{margin:0;color:var(--soft,#c9d1d9);font-size:13px;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere;cursor:pointer}.rm-body.open{display:block}
.rm-who{display:flex;flex-direction:column;gap:3px}
.rm-on{display:inline-flex;align-items:center;gap:7px;font-size:12.5px;font-weight:600;color:#ffc857}.rm-on i{width:7px;height:7px;border-radius:50%;background:currentColor}.rm-on.done{color:#5fe3a1}
.rm-acts{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.rm-edit-btn{margin-left:auto;background:none;border-color:transparent;color:var(--muted)}.rm-edit-btn:hover{color:var(--frost)}
.rm-edit{display:flex;flex-direction:column;gap:8px;padding-top:8px;border-top:1px solid var(--line);cursor:auto}.rm-edit[hidden]{display:none}
.rm-edit label{display:flex;flex-direction:column;gap:4px;font-size:12.5px}
button[data-confirming]{background:#ffc857!important;color:#1a1200!important;border-color:#ffc857!important}
.rm-toast{position:fixed;left:50%;bottom:22px;transform:translateX(-50%);z-index:60;max-width:min(520px,calc(100% - 32px));padding:11px 16px;border-radius:10px;background:#1c2a2c;border:1px solid rgba(94,245,236,.45);color:var(--frost);font-weight:600;box-shadow:0 10px 30px rgba(0,0,0,.45)}
.rm-toast.err{background:#2c1c1c;border-color:rgba(255,138,128,.5)}.rm-toast[hidden]{display:none}
@media (max-width:1100px){.rm-board{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:760px){.rm-quick-row{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.rm-quick-row input{grid-column:1/-1}.rm-quick-more{grid-template-columns:1fr}}
@media (max-width:640px){.rm-board{grid-template-columns:1fr}}
</style>
<script>
(function () {
  'use strict';
  var root = document.querySelector('.rm'), boardEl = root.querySelector('[data-rm-board]'), toastEl = document.querySelector('[data-rm-toast]');
  var csrf = root.getAttribute('data-csrf'), area = root.getAttribute('data-area'), addForm = root.querySelector('[data-rm-form="add"]');
  var tT = null, busy = false;
  function toast(msg, err) {
    if (!msg) return;
    toastEl.textContent = msg; toastEl.classList.toggle('err', !!err); toastEl.hidden = false;
    clearTimeout(tT); tT = setTimeout(function () { toastEl.hidden = true; }, err ? 6000 : 2500);
  }
  // Every change: one request, the board comes back, nothing reloads.
  function send(fields, card) {
    var fd = fields instanceof FormData ? fields : new FormData();
    if (!(fields instanceof FormData)) Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    fd.append('csrf', csrf); fd.append('area_f', area);
    if (card) card.classList.add('busy');
    return fetch('roadmap.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-RM': '1' } })
      .then(function (r) {
        if (!r.ok) throw new Error(r.status === 400 ? 'This page expired. Reload it and try again.' : 'The admin answered ' + r.status + '. Try again in a moment.');
        return r.json();
      })
      .then(function (j) { boardEl.innerHTML = j.html; toast(j.msg, !j.ok); return j; })
      .catch(function (e) { if (card) card.classList.remove('busy'); toast(e.message || 'Couldn\'t reach the admin. Check your connection.', true); return { ok: false }; });
  }
  // Adding: Enter, and the box stays ready for the next one.
  addForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (busy) return;
    var title = addForm.elements.title;
    if (!title.value.trim()) { title.focus(); return; }
    busy = true;
    var fd = new FormData(addForm); fd.append('action', 'add');
    send(fd).then(function (j) {
      busy = false;
      if (j.ok) { title.value = ''; addForm.elements.body.value = ''; addForm.elements.link.value = ''; }
      title.focus();
    });
  });
  var moreBtn = addForm.querySelector('[data-rm-more-btn]'), moreBox = addForm.querySelector('[data-rm-more-box]');
  moreBtn.addEventListener('click', function () { moreBox.hidden = !moreBox.hidden; moreBtn.setAttribute('aria-expanded', String(!moreBox.hidden)); if (!moreBox.hidden) addForm.elements.body.focus(); });
  addForm.elements.body.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) addForm.requestSubmit(); });
  // Buttons on the cards, editing on the card, the area filters.
  root.addEventListener('click', function (e) {
    var t = e.target;
    var f = t.closest('.rm-filt a');
    if (f) {
      e.preventDefault();
      area = f.getAttribute('data-area'); root.setAttribute('data-area', area);
      try { history.replaceState(null, '', 'roadmap.php' + (area ? '?area=' + area : '')); } catch (x) {}
      fetch('roadmap.php?board=' + encodeURIComponent(area), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.text(); }).then(function (h) { boardEl.innerHTML = h; });
      return;
    }
    if (t.closest('[data-rm-more]')) { t.closest('[data-rm-more]').classList.toggle('open'); return; }
    var ed = t.closest('[data-rm-edit]');
    if (ed) {
      var card = ed.closest('.rm-card'), form = card.querySelector('.rm-edit');
      form.hidden = !form.hidden;
      card.setAttribute('draggable', form.hidden ? 'true' : 'false');
      card.querySelector('.rm-edit-btn').setAttribute('aria-expanded', String(!form.hidden));
      if (!form.hidden) form.elements.title.focus();
      return;
    }
    var b = t.closest('[data-rm]');
    if (!b) return;
    var c = b.getAttribute('data-confirm');
    if (c && !b.hasAttribute('data-confirming')) {
      b.setAttribute('data-confirming', b.textContent); b.textContent = c;
      setTimeout(function () { if (b.isConnected && b.hasAttribute('data-confirming')) { b.textContent = b.getAttribute('data-confirming'); b.removeAttribute('data-confirming'); } }, 3500);
      return;
    }
    var fields = { action: b.getAttribute('data-rm'), id: b.getAttribute('data-id') };
    if (b.hasAttribute('data-to')) fields.to = b.getAttribute('data-to');
    send(fields, b.closest('.rm-card'));
  });
  root.addEventListener('submit', function (e) {
    var form = e.target;
    if (form.getAttribute('data-rm-form') !== 'edit') return;
    e.preventDefault();
    var fd = new FormData(form); fd.append('action', 'edit');
    send(fd, form.closest('.rm-card'));
  });
  // Drag a card to another column: to Working on takes it, to Done finishes it.
  var drag = null;
  root.addEventListener('dragstart', function (e) {
    var card = e.target.closest && e.target.closest('.rm-card');
    if (!card || card.getAttribute('draggable') !== 'true') return;
    drag = card; card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', card.getAttribute('data-id')); } catch (x) {}
  });
  root.addEventListener('dragend', function () { if (drag) drag.classList.remove('dragging'); drag = null; root.querySelectorAll('.rm-col.drop').forEach(function (c) { c.classList.remove('drop'); }); });
  root.addEventListener('dragover', function (e) {
    var col = drag && e.target.closest && e.target.closest('.rm-col');
    if (!col || col.getAttribute('data-col') === drag.getAttribute('data-status')) return;
    e.preventDefault(); e.dataTransfer.dropEffect = 'move';
    root.querySelectorAll('.rm-col.drop').forEach(function (c) { if (c !== col) c.classList.remove('drop'); });
    col.classList.add('drop');
  });
  root.addEventListener('drop', function (e) {
    var col = drag && e.target.closest && e.target.closest('.rm-col');
    if (!col) return;
    e.preventDefault();
    var to = col.getAttribute('data-col'), card = drag, from = card.getAttribute('data-status');
    col.classList.remove('drop');
    if (to === from) return;
    if (card.hasAttribute('data-locked') && to !== 'done') { toast(card.getAttribute('data-locked') + ' is on it. Ask them first.', true); return; }
    var empty = col.querySelector('.rm-empty'); if (empty) empty.remove();
    col.insertBefore(card, col.querySelector('.rm-card'));
    send({ action: to === 'doing' ? 'take' : 'move', id: card.getAttribute('data-id'), to: to }, card);
  });
})();
</script>
<?php rs_foot();
