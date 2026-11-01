<?php
// RecompStore admin (3.6): the banner on top of recomplauncher.com. Pick the recomps that slide by,
// put them in order, and Save and publish: the site has it within seconds (no GitHub check needed).
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/rlpush.php';
rs_require_login();
rs_pc_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    try {
        $ids = rs_pc_banner_save((array)($_POST['ids'] ?? []));
        rs_pc_banner_save_info((array)($_POST['tag'] ?? []), (array)($_POST['text'] ?? []), $ids);
        rs_pc_write_catalog();
        try { rl_push('catalog'); } catch (Throwable $e) { /* launchers pick it up on their next look */ }
        rs_log('pc-banner', 'pc-catalog', implode(', ', $ids));
        rs_flash('Published. The banner has ' . count($ids) . ($ids && count($ids) === 1 ? ' game' : ' games') . ' and is live on recomplauncher.com.');
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: pc-banner.php');
    exit;
}

$apps = array_values(array_filter(rs_pc_apps(false), fn($a) => empty($a['soon'])));
$byId = [];
foreach ($apps as $a) $byId[$a['id']] = $a;
$banner = array_values(array_filter(rs_pc_banner(), fn($id) => isset($byId[$id])));
$pick = fn(array $a) => ['id' => $a['id'], 'name' => (string)$a['name'], 'game' => (string)$a['game'],
    'hero' => (string)($a['hero'] ?? ''), 'logo' => (string)($a['logo'] ?? ''), 'cover' => (string)($a['cover'] ?? '')];
$data = array_map($pick, $apps);
// (3.6) More to pick by: when it was added, who made it (a check for a developer with a page here),
// GitHub stars and downloads, and its console.
$devs = function_exists('rs_developers') ? rs_developers() : [];
foreach ($data as &$d) {
    $a = $byId[$d['id']];
    $dv = (string)($a['dev'] ?? '') !== '' ? ($devs[(string)$a['dev']] ?? null) : null;
    $d['blurb'] = (string)($a['blurb'] ?? '');
    $d['added'] = substr((string)($a['added'] ?? ''), 0, 10);
    $d['released'] = substr((string)($a['released'] ?? ''), 0, 10);
    $d['author'] = $dv ? (string)$dv['name'] : (string)($a['author'] ?? '');
    $d['dev'] = $dv !== null;
    $d['verified'] = $dv && !empty($dv['verified']);
    $d['stars'] = (int)($a['stars'] ?? 0);
    $d['downloads'] = (int)($a['downloads'] ?? 0);
    $d['origin'] = RS_PC_ORIGINS[(string)($a['origin'] ?? '')] ?? '';
}
unset($d);
$info = rs_pc_banner_info();

rs_head('Banner', true);
?>
<section class="head-row">
  <div>
    <h1>Home banner</h1>
    <p class="muted">The slider on top of recomplauncher.com. Add the recomps you want, drag or use the arrows to put them in order, give a slide a tag or its own line if you like, then Save and publish. It goes live right away.</p>
  </div>
</section>

<form method="post" id="bn-form">
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <section class="card">
    <div class="bn-top">
      <h2>In the banner <span class="muted" id="bn-count"></span></h2>
      <button class="primary" type="submit">Save and publish</button>
    </div>
    <ol class="bn-list" id="bn-list"></ol>
    <datalist id="bn-tags"><option value="New release"><option value="Just updated"><option value="Most popular"><option value="Trending"><option value="Staff pick"><option value="Online play"><option value="New on RecompLauncher"></datalist>
    <p class="muted bn-empty" id="bn-empty" hidden>Nothing in the banner. Add a recomp below.</p>
  </section>

  <section class="card">
    <h2>Add a recomp</h2>
    <div class="bn-find">
      <input type="search" id="bn-q" class="bn-q" placeholder="Search by name, game or who made it" autocomplete="off">
      <label class="bn-sort">Sort by <select id="bn-sort"><option value="added">Newest added</option><option value="stars">Most GitHub stars</option><option value="downloads">Most downloads</option><option value="released">Latest release</option><option value="name">Name</option></select></label>
    </div>
    <ul class="bn-results" id="bn-results"></ul>
    <p class="muted small">Only recomps with a wide backdrop picture can go in the banner. Add one on its edit page.</p>
  </section>
</form>

<style>
.bn-top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:14px}
.bn-top h2{margin:0}
.bn-list{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.bn-item{display:grid;grid-template-columns:28px 220px minmax(0,1fr) auto;align-items:center;gap:10px 14px;padding:10px;border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.03)}
.bn-item.drag{opacity:.4}
.bn-item.over{border-color:#34F2E8}
.bn-n{font-weight:700;text-align:center;color:#93a0b3;font-variant-numeric:tabular-nums}
.bn-art{position:relative;aspect-ratio:16/7;border-radius:8px;overflow:hidden;background:#0b1220 center/cover no-repeat;cursor:grab}
.bn-art img{position:absolute;left:8%;bottom:10%;max-width:60%;max-height:55%;object-fit:contain;filter:drop-shadow(0 2px 6px rgba(0,0,0,.6))}
.bn-name b{display:block}
.bn-name span{font-size:13px;color:#93a0b3}
.bn-btns{display:flex;gap:6px}
.bn-fields{grid-column:2/-1;display:grid;grid-template-columns:220px minmax(0,1fr);gap:10px}
.bn-fields label{display:grid;gap:4px;font-size:12px;color:#93a0b3}
.bn-fields input{width:100%}
.bn-btns button{min-width:36px}
.bn-find{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;margin:8px 0 12px}
.bn-q{width:100%}
.bn-sort{display:flex;align-items:center;gap:8px;font-size:13px;color:#93a0b3;white-space:nowrap}
.bn-meta{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:12px;color:#93a0b3;margin-top:3px}
.bn-meta .by{color:#c6d0dd}
.bn-check{display:inline-grid;place-items:center;width:14px;height:14px;margin-left:4px;border-radius:50%;background:#34F2E8;color:#05080d;font-size:10px;font-weight:800;vertical-align:-2px}
.bn-check.dev{background:#8a4bff;color:#fff}
.bn-results{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.bn-results li{display:grid;grid-template-columns:120px minmax(0,1fr) auto;align-items:center;gap:12px;padding:8px;border:1px solid rgba(255,255,255,.08);border-radius:10px}
.bn-results .thumb{aspect-ratio:16/7;border-radius:6px;background:#0b1220 center/cover no-repeat}
.bn-results .nm{min-width:0}
.bn-results .nm b{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bn-results .nm span{font-size:13px;color:#93a0b3}
@media (max-width:820px){.bn-find{grid-template-columns:1fr}.bn-fields{grid-column:1/-1;grid-template-columns:1fr}.bn-item{grid-template-columns:24px 120px minmax(0,1fr)}.bn-btns{grid-column:1/-1;justify-content:flex-end}.bn-results{grid-template-columns:1fr}}
</style>

<script>
(() => {
  const APPS = <?= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const byId = Object.fromEntries(APPS.map((a) => [a.id, a]));
  let list = <?= json_encode($banner) ?>;
  const info = <?= json_encode((object)$info, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  // What's typed is kept while the list is redrawn (moved, added, removed).
  const keep = () => document.querySelectorAll('[data-f]').forEach((el) => { const [id, k] = el.dataset.f.split('|'); (info[id] = info[id] || {})[k] = el.value; });
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const bg = (u) => u ? `background-image:url('${esc(u)}')` : '';
  let dragFrom = -1;
  const fmtD = (d) => { if (!d) return ''; const t = new Date(d + 'T12:00:00'); return isNaN(t) ? d : t.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); };
  const fmtN = (n) => n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k' : String(n || 0);
  // Who made it: a check for a developer with a page here (cyan when verified).
  const by = (a) => a.author ? `<span class="by">by ${esc(a.author)}${a.dev ? `<span class="bn-check${a.verified ? '' : ' dev'}" title="${a.verified ? 'Verified developer' : 'Has a developer page'}">&#10003;</span>` : ''}</span>` : '';
  const meta = (a) => `<span class="bn-meta">${by(a)}<span>${a.added ? 'Added ' + esc(fmtD(a.added)) : ''}</span><span>&#9733; ${esc(fmtN(a.stars))}</span><span>${esc(fmtN(a.downloads))} downloads</span>${a.origin ? `<span>${esc(a.origin)}</span>` : ''}</span>`;
  const SORTS = {
    added: (x, y) => String(y.added).localeCompare(String(x.added)) || String(y.released).localeCompare(String(x.released)),
    released: (x, y) => String(y.released).localeCompare(String(x.released)),
    stars: (x, y) => y.stars - x.stars,
    downloads: (x, y) => y.downloads - x.downloads,
    name: (x, y) => x.name.localeCompare(y.name),
  };

  function draw() {
    keep();
    $('bn-count').textContent = list.length ? '(' + list.length + ')' : '';
    $('bn-empty').hidden = list.length > 0;
    $('bn-list').innerHTML = list.map((id, i) => {
      const a = byId[id];
      return `<li class="bn-item" draggable="true" data-i="${i}">
        <span class="bn-n">${i + 1}</span>
        <span class="bn-art" style="${bg(a.hero)}">${a.logo ? `<img src="${esc(a.logo)}" alt="">` : ''}</span>
        <span class="bn-name"><b>${esc(a.name)}</b><span>${esc(a.game)}</span>${meta(a)}</span>
        <span class="bn-btns">
          <button type="button" data-up="${i}" ${i === 0 ? 'disabled' : ''} aria-label="Move up">&uarr;</button>
          <button type="button" data-down="${i}" ${i === list.length - 1 ? 'disabled' : ''} aria-label="Move down">&darr;</button>
          <button type="button" data-rm="${i}">Remove</button>
        </span>
        <input type="hidden" name="ids[]" value="${esc(id)}">
        <span class="bn-fields">
          <label>Tag (optional)<input list="bn-tags" name="tag[${esc(id)}]" data-f="${esc(id)}|tag" maxlength="32" value="${esc((info[id] || {}).tag || '')}" placeholder="New release"></label>
          <label>Line under the logo (optional)<input name="text[${esc(id)}]" data-f="${esc(id)}|text" maxlength="160" value="${esc((info[id] || {}).text || '')}" placeholder="${esc(a.blurb || 'Its own description')}"></label>
        </span>
      </li>`;
    }).join('');
    search();
  }

  function search() {
    const q = $('bn-q').value.trim().toLowerCase();
    const res = APPS.filter((a) => a.hero && !list.includes(a.id) && (!q || (a.name + ' ' + a.game + ' ' + a.author).toLowerCase().includes(q)))
      .sort(SORTS[$('bn-sort').value] || SORTS.added);
    // With nothing typed, the top of the list by the chosen sort (newest added first).
    const shown = res.slice(0, q ? 40 : 20);
    $('bn-results').innerHTML = shown.map((a) => `<li>
      <span class="thumb" style="${bg(a.hero)}"></span>
      <span class="nm"><b>${esc(a.name)}</b><span>${esc(a.game)}</span>${meta(a)}</span>
      <button type="button" data-add="${esc(a.id)}">Add</button></li>`).join('')
      || (q ? '<li class="muted" style="grid-column:1/-1;border:0">No recomp with a backdrop matches that.</li>' : '');
  }

  document.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.add) { list.push(b.dataset.add); draw(); }
    else if (b.dataset.rm !== undefined) { list.splice(+b.dataset.rm, 1); draw(); }
    else if (b.dataset.up !== undefined) { const i = +b.dataset.up; [list[i - 1], list[i]] = [list[i], list[i - 1]]; draw(); }
    else if (b.dataset.down !== undefined) { const i = +b.dataset.down; [list[i + 1], list[i]] = [list[i], list[i + 1]]; draw(); }
  });
  const L = $('bn-list');
  L.addEventListener('dragstart', (e) => { const li = e.target.closest('.bn-item'); if (!li) return; dragFrom = +li.dataset.i; li.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; });
  L.addEventListener('dragend', () => { dragFrom = -1; L.querySelectorAll('.bn-item').forEach((x) => x.classList.remove('drag', 'over')); });
  L.addEventListener('dragover', (e) => { const li = e.target.closest('.bn-item'); if (!li || dragFrom < 0) return; e.preventDefault(); L.querySelectorAll('.over').forEach((x) => x.classList.remove('over')); li.classList.add('over'); });
  L.addEventListener('drop', (e) => {
    const li = e.target.closest('.bn-item');
    if (!li || dragFrom < 0) return;
    e.preventDefault();
    const to = +li.dataset.i;
    const [m] = list.splice(dragFrom, 1);
    list.splice(to, 0, m);
    dragFrom = -1;
    draw();
  });
  $('bn-q').addEventListener('input', search);
  $('bn-sort').addEventListener('change', search);
  $('bn-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
  draw();
})();
</script>
<?php rs_foot(); ?>
