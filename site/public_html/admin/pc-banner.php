<?php
// RecompStore admin (4.7): the banner on top of RecompLauncher's Home and recomplauncher.com. A list for
// each system (Windows, Mac, Linux), each made of fixed picks and automatic slots (newest release,
// trending, most popular, just updated) that fill themselves and move on every 30 minutes. Save and
// publish: launchers and the site have it within seconds, no launcher update needed.
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/layout.php';
require_once __DIR__ . '/inc/rlpush.php';
rs_require_login();
rs_pc_tables();

$catApps = function (): array {
    $cat = json_decode((string)@file_get_contents(RS_PC_CATALOG), true);
    return is_array($cat['apps'] ?? null) ? $cat['apps'] : [];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rs_check_csrf();
    $slots = json_decode((string)($_POST['slots'] ?? ''), true);
    // What the lists would show, for the page while it's edited.
    if (($_POST['action'] ?? '') === 'preview') {
        header('Content-Type: application/json; charset=utf-8');
        $r = rs_pc_banner_resolve($catApps(), is_array($slots) ? array_map(fn($l) => rs_pc_banner_clean((array)$l), $slots) : null);
        echo json_encode(['why' => $r['why'], 'banners' => $r['banners'], 'merged' => $r['merged']]);
        exit;
    }
    try {
        if (!is_array($slots)) throw new RuntimeException('Nothing to save.');
        $clean = rs_pc_banner_slots_save($slots);
        rs_pc_write_catalog();
        try { rl_push('catalog'); } catch (Throwable $e) { /* launchers pick it up on their next look */ }
        $r = rs_pc_banner_resolve($catApps());
        rs_log('pc-banner', 'pc-catalog', implode(' | ', array_map(fn($os) => $os . ': ' . implode(', ', $r['banners'][$os] ?? []), array_keys(RS_BANNER_OS))));
        rs_flash('Published. Windows shows ' . count($r['banners']['windows']) . ', Mac ' . count($r['banners']['macos']) . ', Linux ' . count($r['banners']['linux']) . '. Automatic slots refresh every 30 minutes.');
    } catch (Throwable $e) {
        rs_flash($e->getMessage(), 'err');
    }
    header('Location: pc-banner.php');
    exit;
}

$apps = array_values(array_filter(rs_pc_apps(false), fn($a) => empty($a['soon'])));
$devs = function_exists('rs_developers') ? rs_developers() : [];
$data = [];
foreach ($apps as $a) {
    $dv = (string)($a['dev'] ?? '') !== '' ? ($devs[(string)$a['dev']] ?? null) : null;
    $data[] = ['id' => $a['id'], 'name' => (string)$a['name'], 'game' => (string)$a['game'],
        'hero' => (string)($a['hero'] ?? ''), 'logo' => (string)($a['logo'] ?? ''), 'blurb' => (string)($a['blurb'] ?? ''),
        'added' => substr((string)($a['live_at'] ?? '') !== '' ? (string)$a['live_at'] : (string)($a['added'] ?? ''), 0, 10), 'released' => substr((string)($a['released'] ?? ''), 0, 10),
        'author' => $dv ? (string)$dv['name'] : (string)($a['author'] ?? ''), 'dev' => $dv !== null, 'verified' => $dv && !empty($dv['verified']),
        'stars' => (int)($a['stars'] ?? 0), 'downloads' => (int)($a['downloads'] ?? 0), 'origin' => RS_PC_ORIGINS[(string)($a['origin'] ?? '')] ?? '',
        'os' => array_keys(array_filter(['windows' => $a['asset_win'], 'macos' => rs_pc_mac_ok((string)$a['asset_mac']), 'linux' => $a['asset_linux']]))];
}
$players = rs_pc_players_map();
foreach ($data as &$d) $d['players'] = (int)($players[$d['id']] ?? 0);
unset($d);
$slots = rs_pc_banner_slots();
$rules = array_map(fn($r) => ['tag' => $r[0], 'name' => $r[1], 'how' => $r[2]], RS_BANNER_RULES);

rs_head('Banner', true);
?>
<section class="head-row">
  <div>
    <h1>Home banner</h1>
    <p class="muted">The slider on top of RecompLauncher's Home and recomplauncher.com. Each system has its own list. A slot is a recomp you pick, or an automatic one that fills itself with what's new or popular on that system and moves on by itself every 30 minutes. Only recomps with a build for that system and a wide backdrop are shown there.</p>
  </div>
</section>

<form method="post" id="bn-form">
  <input type="hidden" name="csrf" value="<?= h(rs_csrf()) ?>">
  <input type="hidden" name="slots" id="bn-slots">
  <section class="card">
    <div class="bn-top">
      <div class="bn-tabs" role="tablist">
        <?php foreach (RS_BANNER_OS as $os => $label): ?><button type="button" role="tab" data-os="<?= $os ?>"><?= h($label) ?> <span class="n" data-n="<?= $os ?>"></span></button><?php endforeach; ?>
      </div>
      <div class="bn-acts">
        <label class="bn-copy">Copy this list to <select id="bn-copy"><option value="">…</option><?php foreach (RS_BANNER_OS as $os => $label): ?><option value="<?= $os ?>"><?= h($label) ?></option><?php endforeach; ?><option value="all">Both others</option></select></label>
        <button class="primary" type="submit">Save and publish</button>
      </div>
    </div>
    <ol class="bn-list" id="bn-list"></ol>
    <p class="muted bn-empty" id="bn-empty" hidden>Nothing in this list yet. Add an automatic slot or a recomp below.</p>
    <datalist id="bn-tags"><option value="New Release"><option value="Just Updated"><option value="Most Popular"><option value="Trending"><option value="Staff Pick"><option value="Online Play"><option value="New on RecompLauncher"></datalist>
  </section>

  <section class="card">
    <h2>Add an automatic slot</h2>
    <div class="bn-rules">
      <?php foreach ($rules as $k => $r): ?><button type="button" class="bn-rule" data-rule="<?= h($k) ?>"><b><?= h($r['name']) ?></b><span><?= h($r['how']) ?></span></button><?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <h2>Add a recomp</h2>
    <div class="bn-find">
      <input type="search" id="bn-q" class="bn-q" placeholder="Search by name, game or who made it" autocomplete="off">
      <label class="bn-sort">Sort by <select id="bn-sort"><option value="added">Newest added</option><option value="players">Most players</option><option value="stars">Most GitHub stars</option><option value="downloads">Most downloads</option><option value="released">Latest release</option><option value="name">Name</option></select></label>
    </div>
    <ul class="bn-results" id="bn-results"></ul>
    <p class="muted small">Only recomps with a wide backdrop picture and a build for this system are listed here. Add a backdrop on a recomp's edit page.</p>
  </section>
</form>

<style>
.bn-top{display:flex;justify-content:space-between;align-items:center;gap:12px 16px;flex-wrap:wrap;margin-bottom:14px}
.bn-tabs{display:inline-flex;gap:4px;padding:4px;border-radius:12px;background:rgba(255,255,255,.05)}
.bn-tabs button{border:0;background:none;padding:8px 16px;border-radius:9px;font-weight:700;color:#c6d0dd;cursor:pointer}
.bn-tabs button[aria-selected=true]{background:#34F2E8;color:#05080d}
.bn-tabs .n{font-weight:600;opacity:.75;font-variant-numeric:tabular-nums}
.bn-acts{display:flex;gap:12px;align-items:center;flex-wrap:wrap}
.bn-copy{display:flex;align-items:center;gap:8px;font-size:13px;color:#93a0b3;white-space:nowrap}
.bn-list{list-style:none;margin:0;padding:0;display:grid;gap:10px}
.bn-item{display:grid;grid-template-columns:28px 220px minmax(0,1fr) auto;align-items:center;gap:10px 14px;padding:10px;border:1px solid rgba(255,255,255,.1);border-radius:12px;background:rgba(255,255,255,.03)}
.bn-item.auto{border-style:dashed;border-color:rgba(52,242,232,.35)}
.bn-item.off{opacity:.55}
.bn-item.drag{opacity:.4}
.bn-item.over{border-color:#34F2E8}
.bn-n{font-weight:700;text-align:center;color:#93a0b3;font-variant-numeric:tabular-nums}
.bn-art{position:relative;aspect-ratio:16/7;border-radius:8px;overflow:hidden;background:#0b1220 center/cover no-repeat;cursor:grab}
.bn-art img{position:absolute;left:8%;bottom:10%;max-width:60%;max-height:55%;object-fit:contain;filter:drop-shadow(0 2px 6px rgba(0,0,0,.6))}
.bn-art .ph{position:absolute;inset:0;display:grid;place-items:center;font-size:12px;color:#93a0b3;text-align:center;padding:8px}
.bn-name b{display:block}
.bn-name span{font-size:13px;color:#93a0b3}
.bn-badge{display:inline-flex;align-items:center;height:20px;padding:0 8px;margin-right:6px;border-radius:999px;font-size:11px;font-weight:700;color:#34F2E8;background:rgba(52,242,232,.12);border:1px solid rgba(52,242,232,.4);vertical-align:2px}
.bn-warn{display:block;font-size:12px;color:#f5b301;margin-top:3px}
.bn-btns{display:flex;gap:6px}
.bn-btns button{min-width:36px}
.bn-fields{grid-column:2/-1;display:grid;grid-template-columns:220px minmax(0,1fr);gap:10px}
.bn-fields.one{grid-template-columns:220px minmax(0,1fr)}
.bn-fields label{display:grid;gap:4px;font-size:12px;color:#93a0b3}
.bn-fields input,.bn-fields select{width:100%}
.bn-rules{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.bn-rule{display:flex;flex-direction:column;align-items:flex-start;gap:4px;text-align:left;padding:12px 14px;border-radius:12px;border:1px dashed rgba(52,242,232,.35);background:rgba(52,242,232,.04);color:inherit;cursor:pointer}
.bn-rule:hover{border-color:#34F2E8}
.bn-rule span{font-size:12px;color:#93a0b3;font-weight:400}
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
@media (max-width:1000px){.bn-rules{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width:820px){.bn-find{grid-template-columns:1fr}.bn-fields{grid-column:1/-1;grid-template-columns:1fr}.bn-item{grid-template-columns:24px 120px minmax(0,1fr)}.bn-btns{grid-column:1/-1;justify-content:flex-end}.bn-results{grid-template-columns:1fr}}
</style>

<script>
(() => {
  const APPS = <?= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const RULES = <?= json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  const OSN = <?= json_encode(RS_BANNER_OS) ?>;
  const CSRF = <?= json_encode(rs_csrf()) ?>;
  const byId = Object.fromEntries(APPS.map((a) => [a.id, a]));
  const lists = <?= json_encode((object)$slots, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  let os = 'windows';
  try { os = sessionStorage.getItem('bn-os') || 'windows'; } catch (e) {}
  if (!OSN[os]) os = 'windows';
  let preview = { why: {}, banners: {} };
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const bg = (u) => (u ? `background-image:url('${esc(u)}')` : '');
  const fmtD = (d) => { if (!d) return ''; const t = new Date(d + 'T12:00:00'); return isNaN(t) ? d : t.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' }); };
  const fmtN = (n) => (n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, '') + 'k' : String(n || 0));
  const by = (a) => (a.author ? `<span class="by">by ${esc(a.author)}${a.dev ? `<span class="bn-check${a.verified ? '' : ' dev'}" title="${a.verified ? 'Verified developer' : 'Has a developer page'}">&#10003;</span>` : ''}</span>` : '');
  const meta = (a) => `<span class="bn-meta">${by(a)}<span>${a.added ? 'Added ' + esc(fmtD(a.added)) : ''}</span>${a.players ? `<span>${esc(fmtN(a.players))} players</span>` : ''}<span>&#9733; ${esc(fmtN(a.stars))}</span><span>${esc(fmtN(a.downloads))} downloads</span>${a.origin ? `<span>${esc(a.origin)}</span>` : ''}</span>`;
  const SORTS = {
    added: (x, y) => String(y.added).localeCompare(String(x.added)) || String(y.released).localeCompare(String(x.released)),
    released: (x, y) => String(y.released).localeCompare(String(x.released)),
    players: (x, y) => y.players - x.players || y.downloads - x.downloads,
    stars: (x, y) => y.stars - x.stars,
    downloads: (x, y) => y.downloads - x.downloads,
    name: (x, y) => x.name.localeCompare(y.name),
  };
  const list = () => (lists[os] = lists[os] || []);
  // What's typed is kept while the list is redrawn.
  const keep = () => document.querySelectorAll('[data-f]').forEach((el) => { const [i, k] = el.dataset.f.split('|'); if (list()[+i]) { if (el.value) list()[+i][k] = el.value; else delete list()[+i][k]; } });
  let dragFrom = -1, timer = 0;

  function ask() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const fd = new FormData();
      fd.append('csrf', CSRF); fd.append('action', 'preview'); fd.append('slots', JSON.stringify(lists));
      try { preview = await fetch('pc-banner.php', { method: 'POST', body: fd }).then((r) => r.json()); } catch (e) { return; }
      draw(false);
    }, 250);
  }

  function row(sl, i) {
    const n = list().length;
    const shown = ((preview.why || {})[os] || [])[i];
    const a = sl.type === 'pick' ? byId[sl.id] : byId[shown] || null;
    const warn = shown && !byId[shown] ? shown : (sl.type === 'pick' && !a ? 'No longer listed' : '');
    const art = a && a.hero ? `<span class="bn-art" style="${bg(a.hero)}">${a.logo ? `<img src="${esc(a.logo)}" alt="">` : ''}</span>` : `<span class="bn-art"><span class="ph">${sl.type === 'auto' ? 'Fills itself' : ''}</span></span>`;
    const name = sl.type === 'auto'
      ? `<span class="bn-name"><b><span class="bn-badge">Automatic</span>${esc(RULES[sl.rule].name)}</b><span>${a ? 'Showing now: ' + esc(a.name) : esc(RULES[sl.rule].how)}</span>${a ? meta(a) : ''}${warn ? `<span class="bn-warn">${esc(warn)}</span>` : ''}</span>`
      : `<span class="bn-name"><b>${esc(a ? a.name : sl.id)}</b><span>${esc(a ? a.game : '')}</span>${a ? meta(a) : ''}${warn ? `<span class="bn-warn">${esc(warn)}</span>` : ''}</span>`;
    const fields = sl.type === 'auto'
      ? `<span class="bn-fields one"><label>Tag<input list="bn-tags" data-f="${i}|tag" maxlength="32" value="${esc(sl.tag || '')}" placeholder="${esc(RULES[sl.rule].tag)}"></label><label>Kind<select data-rule="${i}">${Object.entries(RULES).map(([k, r]) => `<option value="${k}"${k === sl.rule ? ' selected' : ''}>${esc(r.name)}</option>`).join('')}</select></label></span>`
      : `<span class="bn-fields"><label>Tag (optional)<input list="bn-tags" data-f="${i}|tag" maxlength="32" value="${esc(sl.tag || '')}" placeholder="New Release"></label><label>Line under the logo (optional)<input data-f="${i}|text" maxlength="160" value="${esc(sl.text || '')}" placeholder="${esc((a && a.blurb) || 'Its own description')}"></label></span>`;
    return `<li class="bn-item${sl.type === 'auto' ? ' auto' : ''}${warn ? ' off' : ''}" draggable="true" data-i="${i}">
      <span class="bn-n">${i + 1}</span>${art}${name}
      <span class="bn-btns"><button type="button" data-up="${i}" ${i === 0 ? 'disabled' : ''} aria-label="Move up">&uarr;</button><button type="button" data-down="${i}" ${i === n - 1 ? 'disabled' : ''} aria-label="Move down">&darr;</button><button type="button" data-rm="${i}">Remove</button></span>
      ${fields}</li>`;
  }

  function draw(again = true) {
    keep();
    document.querySelectorAll('.bn-tabs [data-os]').forEach((b) => b.setAttribute('aria-selected', b.dataset.os === os));
    Object.keys(OSN).forEach((k) => { const el = document.querySelector(`[data-n="${k}"]`); const shownN = ((preview.banners || {})[k] || []).length; el.textContent = (lists[k] || []).length ? `(${shownN})` : ''; });
    $('bn-empty').hidden = list().length > 0;
    $('bn-list').innerHTML = list().map(row).join('');
    $('bn-slots').value = JSON.stringify(lists);
    search();
    if (again) ask();
  }

  function search() {
    const q = $('bn-q').value.trim().toLowerCase();
    const have = new Set(list().filter((s) => s.type === 'pick').map((s) => s.id));
    const res = APPS.filter((a) => a.hero && a.os.includes(os) && !have.has(a.id) && (!q || (a.name + ' ' + a.game + ' ' + a.author).toLowerCase().includes(q))).sort(SORTS[$('bn-sort').value] || SORTS.added);
    $('bn-results').innerHTML = res.slice(0, q ? 40 : 20).map((a) => `<li><span class="thumb" style="${bg(a.hero)}"></span><span class="nm"><b>${esc(a.name)}</b><span>${esc(a.game)}</span>${meta(a)}</span><button type="button" data-add="${esc(a.id)}">Add</button></li>`).join('')
      || `<li class="muted" style="grid-column:1/-1;border:0">${q ? 'No recomp with a backdrop and a ' + esc(OSN[os]) + ' build matches that.' : ''}</li>`;
  }

  document.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.os) { keep(); os = b.dataset.os; try { sessionStorage.setItem('bn-os', os); } catch (er) {} draw(false); return; }
    if (b.dataset.rule && b.classList.contains('bn-rule')) { keep(); list().push({ type: 'auto', rule: b.dataset.rule }); }
    else if (b.dataset.add) { keep(); list().push({ type: 'pick', id: b.dataset.add }); }
    else if (b.dataset.rm !== undefined) { keep(); list().splice(+b.dataset.rm, 1); }
    else if (b.dataset.up !== undefined) { keep(); const i = +b.dataset.up; [list()[i - 1], list()[i]] = [list()[i], list()[i - 1]]; }
    else if (b.dataset.down !== undefined) { keep(); const i = +b.dataset.down; [list()[i + 1], list()[i]] = [list()[i], list()[i + 1]]; }
    else return;
    draw();
  });
  document.addEventListener('change', (e) => {
    const s = e.target.closest('select[data-rule]');
    if (s) { keep(); list()[+s.dataset.rule].rule = s.value; draw(); return; }
    if (e.target.id === 'bn-copy' && e.target.value) {
      keep();
      const to = e.target.value === 'all' ? Object.keys(OSN).filter((k) => k !== os) : [e.target.value];
      to.filter((k) => k !== os).forEach((k) => { lists[k] = JSON.parse(JSON.stringify(list())); });
      e.target.value = '';
      draw();
    }
  });
  document.addEventListener('input', (e) => { if (e.target.dataset && e.target.dataset.f) { keep(); $('bn-slots').value = JSON.stringify(lists); } });
  const L = $('bn-list');
  L.addEventListener('dragstart', (e) => { const li = e.target.closest('.bn-item'); if (!li) return; dragFrom = +li.dataset.i; li.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; });
  L.addEventListener('dragend', () => { dragFrom = -1; L.querySelectorAll('.bn-item').forEach((x) => x.classList.remove('drag', 'over')); });
  L.addEventListener('dragover', (e) => { const li = e.target.closest('.bn-item'); if (!li || dragFrom < 0) return; e.preventDefault(); L.querySelectorAll('.over').forEach((x) => x.classList.remove('over')); li.classList.add('over'); });
  L.addEventListener('drop', (e) => {
    const li = e.target.closest('.bn-item');
    if (!li || dragFrom < 0) return;
    e.preventDefault();
    keep();
    const [m] = list().splice(dragFrom, 1);
    list().splice(+li.dataset.i, 0, m);
    dragFrom = -1;
    draw();
  });
  $('bn-q').addEventListener('input', search);
  $('bn-sort').addEventListener('change', search);
  $('bn-q').addEventListener('keydown', (e) => { if (e.key === 'Enter') e.preventDefault(); });
  $('bn-form').addEventListener('submit', () => { keep(); $('bn-slots').value = JSON.stringify(lists); });
  draw();
})();
</script>
<?php rs_foot(); ?>
