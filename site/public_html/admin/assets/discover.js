// Recomp Store admin: Discover's sections, edited here, with a preview drawn at the store's
// own sizes (its 1920x1080 screen, scaled to fit): the same banner, titles, tiles and gaps.
(function () {
  'use strict';
  const S = window.RS_DISCOVER;
  const listEl = document.querySelector('[data-sections]');
  const wrap = document.querySelector('[data-preview]');
  if (!S || !listEl || !wrap) return;
  const saveForm = document.querySelector('[data-save]');
  const publishForm = document.querySelector('[data-publish]');
  const note = saveForm.querySelector('[data-save-note]');

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const games = S.games || [];
  const byId = (id) => games.find((g) => g.id === id);
  const listed = games.filter((g) => !g.hidden && !g.soon); // in the catalog, in the admin's order
  // (2.0) A testers-only app: only testers' stores show it in a section.
  const optName = (g) => g.name + (g.testers ? ' (testers only)' : '');
  const soonGames = games.filter((g) => !g.hidden && g.soon);
  let sections = JSON.parse(JSON.stringify(S.sections || []));
  let dirty = false;

  const NAMES = { games: 'Games', soon: 'Coming soon', spotlight: 'Spotlight', category: 'Category',
    top: 'Top charts', trending: 'Trending', new: 'New', updated: 'Recently updated' };
  const ALL3 = [['row', 'Row'], ['grid', 'Grid'], ['wide', 'Wide tiles']];
  const LAYOUTS = {
    games: ALL3,
    soon: [['row', 'Row'], ['wide', 'Wide tiles']],
    category: ALL3, top: ALL3, trending: ALL3, new: ALL3, updated: ALL3,
  };
  // (2.0) The charts: filled in live by the store and the site (installs, and GitHub's releases).
  const CHARTS = { top: 'The apps on the most consoles.', trending: 'The most new installs in the last 7 days.',
    new: 'The newest apps, by when you first published them.', updated: 'The apps with the newest releases.' };
  const isChart = (t) => Object.prototype.hasOwnProperty.call(CHARTS, t);
  // (2.0) The parts of the store: a category section lists every game in one of them.
  const CATS = S.categories || { games: 'Games', emulators: 'Emulators', apps: 'Apps', tools: 'Tools' };
  const inCat = (c) => listed.filter((g) => (g.category || 'games') === c);

  function changed(relist) {
    dirty = true;
    note.textContent = 'Changes not saved yet. Save, then Publish.';
    note.classList.add('warn');
    if (relist) renderList();
    renderPreview();
  }

  // ---- The sections, as cards --------------------------------------------------------
  function renderList() {
    listEl.innerHTML = '';
    if (!sections.length) {
      listEl.innerHTML = '<p class="muted">No sections, so the store and the site show the default: every game in a grid, then Coming soon. Add one below to arrange it yourself.</p>';
    }
    sections.forEach((s, i) => {
      const card = document.createElement('section');
      card.className = 'card sec';
      const layouts = LAYOUTS[s.type] || [];
      const chosen = (s.games || []).map(byId).filter(Boolean);
      const addable = listed.filter((g) => !(s.games || []).includes(g.id));
      card.innerHTML = `
        <div class="sec-head">
          <span class="tag${s.type === 'soon' ? ' soon' : s.type === 'spotlight' || s.type === 'category' || isChart(s.type) ? ' cyan' : ''}">${esc(NAMES[s.type])}</span>
          <span class="sec-title">${esc(s.title || (s.type === 'spotlight' ? (byId(s.game) || {}).name || '' : s.type === 'category' ? CATS[s.category] || '' : ''))}</span>
          <span class="sec-tools">
            <button type="button" class="icon-btn" data-move="-1" aria-label="Move up" ${i === 0 ? 'disabled' : ''}>↑</button>
            <button type="button" class="icon-btn" data-move="1" aria-label="Move down" ${i === sections.length - 1 ? 'disabled' : ''}>↓</button>
            <button type="button" class="icon-btn danger" data-remove aria-label="Remove">✕</button>
          </span>
        </div>
        <label><span>${s.type === 'spotlight' ? 'Line above the name <span class="muted">(optional)</span>' : 'Title'}</span><input data-title maxlength="60" value="${esc(s.title || '')}" placeholder="${s.type === 'spotlight' ? 'Spotlight' : s.type === 'soon' ? 'Coming soon' : 'Shooters'}"></label>
        ${layouts.length ? `<div class="seg" role="radiogroup" aria-label="Layout">${layouts.map(([v, t]) => `<label><input type="radio" name="layout${i}" value="${v}" data-layout ${s.layout === v ? 'checked' : ''}><span>${t}</span></label>`).join('')}</div>` : ''}
        ${s.type === 'games' ? `
          <div class="seg" role="radiogroup" aria-label="Which games">
            <label><input type="radio" name="all${i}" value="1" data-all ${s.all ? 'checked' : ''}><span>Every game</span></label>
            <label><input type="radio" name="all${i}" value="0" data-all ${s.all ? '' : 'checked'}><span>Choose games</span></label>
          </div>
          ${s.all ? '<p class="muted small-note">Every game, in the order of the Games page.</p>' : `
          <ol class="picked">${chosen.map((g, k) => `
            <li>${g.icon ? `<img src="${esc(g.icon)}" alt="">` : `<span class="blank">${esc(g.name.slice(0, 2))}</span>`}<span>${esc(g.name)}</span>
              <button type="button" class="icon-btn" data-gmove="${k},-1" ${k === 0 ? 'disabled' : ''} aria-label="Earlier">↑</button>
              <button type="button" class="icon-btn" data-gmove="${k},1" ${k === chosen.length - 1 ? 'disabled' : ''} aria-label="Later">↓</button>
              <button type="button" class="icon-btn" data-gremove="${k}" aria-label="Take out">✕</button></li>`).join('')}</ol>
          ${chosen.length ? '' : '<p class="muted small-note">No games yet: the store leaves an empty section out.</p>'}
          ${addable.length ? `<select data-gadd><option value="">Add a game…</option>${addable.map((g) => `<option value="${esc(g.id)}">${esc(optName(g))}</option>`).join('')}</select>` : ''}`}` : ''}
        ${s.type === 'soon' ? `<p class="muted small-note">${soonGames.length
          ? `The ${soonGames.length === 1 ? 'game' : soonGames.length + ' games'} marked Coming soon, in the order of the Games page: ${soonGames.map((g) => esc(g.name)).join(', ')}.`
          : 'No game is marked Coming soon yet, so the store leaves this out. Tick Coming soon on a game to list it here.'}</p>` : ''}
        ${isChart(s.type) ? `<p class="muted small-note">${CHARTS[s.type]} Filled in live, so the preview just shows your games. From Recomp Store 2.0; older stores leave it out.</p>` : ''}
        ${s.type === 'category' ? `<label>Part of the store<select data-cat>${Object.entries(CATS).map(([v, t]) => `<option value="${esc(v)}" ${s.category === v ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
          <p class="muted small-note">${inCat(s.category).length
            ? `${inCat(s.category).length === 1 ? '1 game' : inCat(s.category).length + ' games'}, in the order of the Games page: ${inCat(s.category).map((g) => esc(g.name)).join(', ')}.`
            : 'Nothing in it yet, so the store leaves it out. Set a game\'s Category on its page.'} Stores before 2.0 leave this section out.</p>` : ''}
        ${s.type === 'spotlight' ? `<label>Game<select data-spot>${listed.map((g) => `<option value="${esc(g.id)}" ${s.game === g.id ? 'selected' : ''}>${esc(optName(g))}</option>`).join('')}</select></label>
          <p class="muted small-note">A still banner of its backdrop, framed as on the Art card.</p>` : ''}`;
      listEl.appendChild(card);

      card.querySelector('[data-title]').addEventListener('input', (e) => {
        s.title = e.target.value;
        if (s.type !== 'spotlight') card.querySelector('.sec-title').textContent = s.title;
        changed(false);
      });
      card.querySelectorAll('[data-move]').forEach((b) => b.addEventListener('click', () => {
        const j = i + +b.dataset.move;
        [sections[i], sections[j]] = [sections[j], sections[i]];
        changed(true);
      }));
      card.querySelector('[data-remove]').addEventListener('click', () => { sections.splice(i, 1); changed(true); });
      card.querySelectorAll('[data-layout]').forEach((r) => r.addEventListener('change', () => { s.layout = r.value; changed(false); }));
      card.querySelectorAll('[data-all]').forEach((r) => r.addEventListener('change', () => { s.all = r.value === '1'; changed(true); }));
      card.querySelectorAll('[data-gmove]').forEach((b) => b.addEventListener('click', () => {
        const [k, d] = b.dataset.gmove.split(',').map(Number);
        const ids = s.games.filter((id) => byId(id));
        [ids[k], ids[k + d]] = [ids[k + d], ids[k]];
        s.games = ids;
        changed(true);
      }));
      card.querySelectorAll('[data-gremove]').forEach((b) => b.addEventListener('click', () => {
        const ids = s.games.filter((id) => byId(id));
        ids.splice(+b.dataset.gremove, 1);
        s.games = ids;
        changed(true);
      }));
      const add = card.querySelector('[data-gadd]');
      if (add) add.addEventListener('change', () => { if (add.value) { s.games = (s.games || []).concat(add.value); changed(true); } });
      const spot = card.querySelector('[data-spot]');
      if (spot) spot.addEventListener('change', () => { s.game = spot.value; changed(true); });
      const cat = card.querySelector('[data-cat]');
      if (cat) cat.addEventListener('change', () => { s.category = cat.value; changed(true); });
    });
  }

  document.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => {
    const t = b.dataset.add;
    if (t === 'games') sections.push({ type: 'games', title: '', layout: 'row', all: false, games: [] });
    else if (t === 'soon') sections.push({ type: 'soon', title: 'Coming soon', layout: 'row' });
    else if (t === 'category') sections.push({ type: 'category', title: 'Emulators', layout: 'row', category: 'emulators' });
    else if (isChart(t)) sections.push({ type: t, title: NAMES[t], layout: 'row' });
    else sections.push({ type: 'spotlight', title: '', game: (listed[0] || {}).id || '' });
    changed(true);
    listEl.lastElementChild && listEl.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }));

  // ---- The preview: the store's Discover, at its own sizes --------------------------
  // Its numbers (src/store_app.cpp, 2.0): content from y 136; banner 1728x360; the category buttons
  // under it (88 tall) when two or more parts of the store have apps; a title 50 tall; tiles 216
  // with 32 between, 7 to a line, a line of them 296; grid lines 18 apart; wide tiles three at a
  // time, 552x280 (a line 360); 44 between sections; what's shown ends at y 1000.
  const TILE = 216, GAP = 32, PER = 7, LINE = 296, GRID_GAP = 18, BANNER_H = 360, HEAD = 50, SEC_GAP = 44;
  const WIDE_W = 552, WIDE_H = 280, WIDE_LINE = 360, WIDE_GAP = (1728 - WIDE_W * 3) / 2, CAT_H = 88;
  const pos = (x, y, w, h) => `left:${x}px;top:${y}px${w ? `;width:${w}px` : ''}${h ? `;height:${h}px` : ''}`;
  const sub = (g) => g.soon ? (g.date || 'Coming soon') : (g.kind === 'original' ? 'Recomp Store' : g.author ? 'By ' + g.author : '');
  const pic = (g) => g.hero ? `<img src="${esc(g.hero)}" alt="" style="object-position:${g.x}% ${g.y}%;transform-origin:${g.x}% ${g.y}%;transform:scale(${Math.max(1, g.zoom / 100)})">` : '';

  function tile(g, x, y) {
    return `<div class="d-tile" style="${pos(x, y, TILE, TILE)}">${g.icon ? `<img src="${esc(g.icon)}" alt="">` : `<span class="mono">${esc(g.name.slice(0, 2))}</span>`}${g.soon ? '<b class="d-soon">SOON</b>' : ''}</div>
      <div class="d-name" style="${pos(x, y + TILE + 12, TILE + 20)}">${esc(g.name)}</div>
      <div class="d-sub" style="${pos(x, y + TILE + 42, TILE + 20)}">${esc(sub(g))}</div>`;
  }
  function wide(g, x, y) {
    return `<div class="d-wide" style="${pos(x, y, WIDE_W, WIDE_H)}">${g.hero ? pic(g) : g.icon ? `<img class="mid" src="${esc(g.icon)}" alt="">` : `<span class="mono">${esc(g.name.slice(0, 2))}</span>`}${g.hero && g.icon ? `<img class="corner" src="${esc(g.icon)}" alt="">` : ''}${g.soon ? '<b class="d-soon">SOON</b>' : ''}</div>
      <div class="d-name" style="${pos(x, y + WIDE_H + 12, WIDE_W)}">${esc(g.name)}</div>
      <div class="d-sub" style="${pos(x, y + WIDE_H + 42, WIDE_W)}">${esc(sub(g))}</div>`;
  }
  function banner(g, y, kicker, dots) {
    return `<div class="d-banner" style="${pos(96, y, 1728, BANNER_H)}">
        ${g.hero ? `<img class="blur" src="${esc(g.hero)}" alt="">` : ''}
        <div class="d-pic">${pic(g)}</div>
        <div class="d-words"><span class="d-kick">${esc(kicker)} · NEW ON RECOMP STORE</span><strong>${esc(g.name)}</strong><span class="d-blurb">${esc(g.blurb || '')}</span></div>
        ${dots > 1 ? `<span class="d-dots">${Array.from({ length: dots }, (_, k) => `<i class="${k === 0 ? 'on' : ''}"></i>`).join('')}</span>` : ''}
      </div>`;
  }
  const arrow = (x, y, dir) => `<span class="d-arrow" style="${pos(x, y)}">${dir > 0 ? '▶' : '◀'}</span>`;

  function renderPreview() {
    let html = `<img class="d-logo" src="/assets/logo-512.png" alt="" style="${pos(96, 50, 52, 52)}"><span class="d-brand" style="${pos(164, 56)}">Recomp Store</span>
      <div class="d-tabs" style="${pos(640, 46)}"><span class="on">Discover</span><span>Library</span><span>Latest Updates</span><span>Settings</span></div>`;
    let y = 136;
    const feat = listed.filter((g) => g.featured);
    const slides = feat.length ? feat : listed.filter((g) => g.hero).slice(0, 3);
    if (slides.length) { html += banner(slides[0], y, 'FEATURED', slides.length); y += BANNER_H + SEC_GAP; }
    // (2.0) The category buttons: the parts of the store that have apps, when two or more do.
    const cats = Object.entries(CATS).filter(([c]) => inCat(c).length);
    if (cats.length >= 2) {
      const bw = (1728 - 24 * 3) / 4;
      cats.forEach(([c, name], k) => (html += `<div class="d-cat" style="${pos(96 + k * (bw + 24), y, bw, CAT_H)}"><i></i><span>${esc(name)}</span><b>${inCat(c).length}</b></div>`));
      y += CAT_H + SEC_GAP;
    }
    const DEFAULT = [{ type: 'games', title: 'Latest games', layout: 'grid', all: true, games: [] }, { type: 'soon', title: 'Coming soon', layout: 'row' }];
    (sections.length ? sections : DEFAULT).forEach((s) => {
      if (s.type === 'spotlight') {
        const g = listed.find((x) => x.id === s.game);
        if (!g) return;
        html += banner(g, y, (s.title || 'Spotlight').toUpperCase(), 0);
        y += BANNER_H + SEC_GAP;
        return;
      }
      const items = s.type === 'soon' ? soonGames : s.type === 'category' ? inCat(s.category) : isChart(s.type) ? listed : s.all ? listed : (s.games || []).map((id) => listed.find((g) => g.id === id)).filter(Boolean);
      if (!items.length) {
        html += `<div class="d-empty" style="${pos(96, y, 1728, 64)}">${esc(s.title || NAMES[s.type])}: nothing in it, so the store leaves it out</div>`;
        y += 64 + SEC_GAP;
        return;
      }
      if (s.title) { html += `<div class="d-head" style="${pos(96, y - 2)}">${esc(s.title)}</div>`; y += HEAD; }
      if ((s.type === 'games' || s.type === 'category' || isChart(s.type)) && s.layout === 'grid') {
        for (let i = 0; i < items.length; i += PER) {
          items.slice(i, i + PER).forEach((g, k) => (html += tile(g, 96 + k * (TILE + GAP), y)));
          y += LINE + GRID_GAP;
        }
        y += SEC_GAP - GRID_GAP;
      } else if (s.layout === 'wide') {
        items.slice(0, 3).forEach((g, k) => (html += wide(g, 96 + k * (WIDE_W + WIDE_GAP), y)));
        if (items.length > 3) html += arrow(1850, y + WIDE_H / 2 - 16, 1);
        y += WIDE_LINE + SEC_GAP;
      } else {
        items.slice(0, PER).forEach((g, k) => (html += tile(g, 96 + k * (TILE + GAP), y)));
        if (items.length > PER) html += arrow(1850, y + TILE / 2 - 16, 1);
        y += LINE + SEC_GAP;
      }
    });
    const H = Math.max(1080, y - SEC_GAP + 60);
    html += `<div class="d-fold" style="${pos(0, 1000, 1920)}"><span>Bottom of the TV screen: below here, the player scrolls down</span></div>`;
    let stage = wrap.querySelector('.stage');
    if (!stage) { stage = document.createElement('div'); stage.className = 'stage dstage'; wrap.appendChild(stage); }
    stage.style.width = '1920px';
    stage.style.height = H + 'px';
    stage.innerHTML = html;
    const k = wrap.clientWidth / 1920;
    stage.style.transform = `scale(${k})`;
    wrap.style.height = H * k + 'px';
  }

  saveForm.addEventListener('submit', () => {
    saveForm.querySelector('[name="sections"]').value = JSON.stringify(sections);
    dirty = false;
  });
  if (publishForm) publishForm.addEventListener('submit', (e) => {
    if (dirty && !confirm('Discover has changes you haven\'t saved. Publish without them?')) e.preventDefault();
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  window.addEventListener('resize', renderPreview);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(renderPreview);
  renderList();
  renderPreview();
})();
