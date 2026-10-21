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
  const listed = games.filter((g) => !g.hidden && !g.soon && !g.beta); // in the catalog, in the admin's order
  // (2.0) A testers-only app: only testers' stores show it in a section.
  const optName = (g) => g.name + (g.testers ? ' (testers only)' : '');
  const soonGames = games.filter((g) => !g.hidden && g.soon);
  let sections = JSON.parse(JSON.stringify(S.sections || []));
  let dirty = false;
  // (2.1) The header: carousel, spotlight, split, mosaic or none, its games and its turn time.
  const HS = S.headerStyles || {};
  let header = Object.assign({ style: 'carousel', interval: 8, games: [] }, S.header || {});
  header.games = (header.games || []).slice();
  const HCOUNT = { carousel: 6, spotlight: 1, split: 6, mosaic: 3, none: 0 };
  const headerGames = () => {
    const picked = (header.games || []).map((id) => listed.find((g) => g.id === id)).filter(Boolean);
    if (picked.length) return picked;
    const feat = listed.filter((g) => g.featured);
    return feat.length ? feat : listed.filter((g) => g.hero).slice(0, 3);
  };
  const THUMB = {
    carousel: '<rect x="2" y="4" width="56" height="26" rx="4"/><circle cx="25" cy="34" r="1.6"/><circle cx="30" cy="34" r="1.6"/><circle cx="35" cy="34" r="1.6"/>',
    spotlight: '<rect x="2" y="2" width="56" height="34" rx="4"/>',
    split: '<rect x="2" y="4" width="36" height="30" rx="4"/><rect x="41" y="4" width="17" height="9" rx="2"/><rect x="41" y="14.5" width="17" height="9" rx="2"/><rect x="41" y="25" width="17" height="9" rx="2"/>',
    mosaic: '<rect x="2" y="4" width="36" height="30" rx="4"/><rect x="41" y="4" width="17" height="14" rx="3"/><rect x="41" y="20" width="17" height="14" rx="3"/>',
    none: '<path d="M8 10h44M8 19h30M8 28h38" stroke-linecap="round"/>',
  };
  const hstylesEl = document.querySelector('[data-hstyles]');
  const hgamesEl = document.querySelector('[data-hgames]');
  const hint = document.querySelector('[data-hinterval]');
  function renderHeader() {
    if (!hstylesEl) return;
    hstylesEl.innerHTML = Object.entries(HS).map(([k, [name, help]]) => `<button type="button" role="radio" aria-checked="${header.style === k}" class="hstyle${header.style === k ? ' on' : ''}" data-hstyle="${esc(k)}">
        <svg viewBox="0 0 60 38" width="60" height="38" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6">${THUMB[k] || ''}</svg>
        <strong>${esc(name)}</strong><small>${esc(help)}</small></button>`).join('');
    const most = HCOUNT[header.style] || 0;
    // A carousel or split lists as many as you pick: one empty picker more each time, up to six.
    const n = header.style === 'carousel' || header.style === 'split' ? Math.min(most, header.games.filter(Boolean).length + 1) : most;
    if (!n) { hgamesEl.innerHTML = ''; }
    else {
      const opts = (sel) => `<option value="">${header.style === 'spotlight' ? 'The first featured game' : header.games.length ? 'Add a game' : 'The games marked Featured'}</option>` + listed.map((g) => `<option value="${esc(g.id)}"${sel === g.id ? ' selected' : ''}>${esc(optName(g))}</option>`).join('');
      const label = header.style === 'spotlight' ? 'Game' : header.style === 'mosaic' ? 'Games (three)' : 'Games, in order (leave empty for the ones marked Featured)';
      hgamesEl.innerHTML = `<span class="lbl">${esc(label)}</span><div class="hpicks">${Array.from({ length: n }, (_, i) => `<select data-hpick="${i}" aria-label="Header game ${i + 1}">${opts(header.games[i] || '')}</select>`).join('')}</div>`;
    }
    const turns = header.style === 'carousel' || header.style === 'split';
    hint.hidden = !turns;
    const r = hint.querySelector('[data-interval]');
    r.value = header.interval;
    hint.querySelector('[data-interval-out]').textContent = header.interval;
  }
  if (hstylesEl) {
    hstylesEl.addEventListener('click', (e) => {
      const b = e.target.closest('[data-hstyle]');
      if (!b) return;
      header.style = b.dataset.hstyle;
      header.games = header.games.slice(0, HCOUNT[header.style] || 0);
      dirty = true; note.textContent = 'Not saved yet.';
      renderHeader(); renderPreview();
    });
    hgamesEl.addEventListener('change', (e) => {
      const sel = e.target.closest('[data-hpick]');
      if (!sel) return;
      header.games[+sel.dataset.hpick] = sel.value;
      header.games = header.games.filter(Boolean);
      dirty = true; note.textContent = 'Not saved yet.';
      renderHeader(); renderPreview();
    });
    hint.querySelector('[data-interval]').addEventListener('input', (e) => {
      header.interval = +e.target.value;
      hint.querySelector('[data-interval-out]').textContent = header.interval;
      dirty = true; note.textContent = 'Not saved yet.';
    });
  }

  const NAMES = { games: 'Games', soon: 'Coming soon', spotlight: 'Spotlight', category: 'Category',
    top: 'Top charts', trending: 'Trending', new: 'New', updated: 'Recently updated', liked: 'Most liked', collection: 'Collection' };
  const ALL3 = [['row', 'Row'], ['grid', 'Grid'], ['wide', 'Wide tiles']];
  const LAYOUTS = {
    games: ALL3,
    soon: [['row', 'Row'], ['wide', 'Wide tiles']],
    category: ALL3, top: ALL3, trending: ALL3, new: ALL3, updated: ALL3, liked: ALL3, collection: ALL3,
  };
  // (2.0) The charts: filled in live by the store and the site (installs, and GitHub's releases).
  const CHARTS = { top: 'The apps on the most consoles.', trending: 'The most new installs in the last 7 days.',
    new: 'The newest apps, by when you first published them.', updated: 'The apps with the newest releases.',
    liked: 'The apps with the most thumbs-up from consoles (Recomp Store 2.3.0).' };
  const isChart = (t) => Object.prototype.hasOwnProperty.call(CHARTS, t);
  // (2.0) The parts of the store: a category section lists every game in one of them.
  const CATS = S.categories || { games: 'Games', emulators: 'Emulators', apps: 'Apps', tools: 'Tools' };
  const inCat = (c) => listed.filter((g) => (g.category || 'games') === c);
  // (2.5) A Category section's order, or a Games section's showing every game: its page's ('')
  // or its own. Worked out the same way as the catalog does it (admin/inc/bootstrap.php, rs_sort_ids).
  const SSORTS = S.sectionSorts || { '': 'Same as its page', az: 'A to Z', new: 'Newest first', updated: 'Recently updated', installs: 'Most installed', custom: 'Your own order' };
  const PAGE = S.pageSorts || {};
  const SORTN = S.sortNames || {};
  const FACTS = S.facts || {};
  const fact = (m, id) => (FACTS[m] && FACTS[m][id] != null ? +FACTS[m][id] : undefined);
  const byName = (x, y) => String(x.name).localeCompare(String(y.name), undefined, { numeric: true, sensitivity: 'base' });
  function sortList(list, sort, order) {
    const at = new Map(list.map((g, i) => [g.id, i]));
    const tie = (x, y) => at.get(x.id) - at.get(y.id);
    if (sort === 'custom') {
      const ids = (order || []).filter((id, k, a) => at.has(id) && a.indexOf(id) === k);
      return ids.map((id) => list[at.get(id)]).concat(list.filter((g) => !ids.includes(g.id)));
    }
    const out = list.slice();
    if (sort === 'az') out.sort((x, y) => byName(x, y) || tie(x, y));
    else if (sort === 'installs') out.sort((x, y) => (fact('installs', y.id) || 0) - (fact('installs', x.id) || 0) || byName(x, y) || tie(x, y));
    else if (sort === 'new' || sort === 'updated') {
      const t = (g) => (sort === 'updated' ? fact('updated', g.id) : undefined) ?? fact('first', g.id) ?? Infinity;
      out.sort((x, y) => (t(y) - t(x)) || tie(x, y));
    }
    return out;
  }
  const ownOrder = (s) => s.type === 'category' || (s.type === 'games' && s.all);
  // What the section lists, in order. A category with no order of its own follows its page's.
  function itemsOf(s) {
    const base = s.type === 'category' ? inCat(s.category) : listed;
    if (s.sort) return sortList(base, s.sort, s.order);
    return s.type === 'category' && PAGE[s.category] && PAGE[s.category] !== 'manual' ? sortList(base, PAGE[s.category]) : base;
  }
  const pageName = (s) => s.type === 'category' ? `Same as the ${CATS[s.category] || 'part'} page (${(SORTN[PAGE[s.category] || 'manual'] || 'your order').toLowerCase()})` : 'Same as the Games page';
  const GRIP = '<svg width="12" height="18" viewBox="0 0 14 22" aria-hidden="true"><g fill="currentColor"><circle cx="3.5" cy="4" r="2"/><circle cx="10.5" cy="4" r="2"/><circle cx="3.5" cy="11" r="2"/><circle cx="10.5" cy="11" r="2"/><circle cx="3.5" cy="18" r="2"/><circle cx="10.5" cy="18" r="2"/></g></svg>';
  function orderHTML(s) {
    const items = itemsOf(s);
    const opts = Object.entries(SSORTS).map(([v, t]) => `<option value="${esc(v)}" ${(s.sort || '') === v ? 'selected' : ''}>${esc(v === '' ? pageName(s) : t)}</option>`).join('');
    const what = s.type === 'category' ? (items.length === 1 ? '1 app' : items.length + ' apps') : (items.length === 1 ? '1 app' : `All ${items.length} apps`);
    let body;
    if (!items.length) body = `<p class="muted small-note">Nothing in it yet, so the store leaves it out. Set a game's Category on its page.</p>`;
    else if (s.sort === 'custom') body = `<p class="muted small-note">${what}. Drag them by the handle, or use the arrows. Apps you add later go at the end until you move them.</p>
          <ol class="picked olist" data-olist>${items.map((g, k) => `
            <li data-id="${esc(g.id)}"><button type="button" class="grip" data-ogrip aria-label="Move ${esc(g.name)}: drag, or use the arrow keys" title="Drag to move">${GRIP}</button><span class="num">${k + 1}</span>${g.icon ? `<img src="${esc(g.icon)}" alt="">` : `<span class="blank">${esc(g.name.slice(0, 2))}</span>`}<span>${esc(optName(g))}</span>
              <button type="button" class="icon-btn" data-omove="${k},-1" ${k === 0 ? 'disabled' : ''} aria-label="Earlier">↑</button>
              <button type="button" class="icon-btn" data-omove="${k},1" ${k === items.length - 1 ? 'disabled' : ''} aria-label="Later">↓</button></li>`).join('')}</ol>`;
    else body = `<p class="muted small-note">${what}: ${items.map((g) => esc(g.name)).join(', ')}.${s.sort === 'new' || s.sort === 'updated' || s.sort === 'installs' ? ' Worked out again each time you Publish.' : ''}</p>`;
    return `<label>Order<select data-sort>${opts}</select></label>${body}`;
  }
  // (2.3.0) Collections (Admin > Collections): a section shows one as a row.
  const COLLS = S.collections || [];
  const coll = (id) => COLLS.find((c) => c.id === id);
  const inColl = (id) => ((coll(id) || {}).ids || []).map((x) => listed.find((g) => g.id === x)).filter(Boolean);

  function changed(relist) {
    dirty = true;
    note.textContent = 'Changes not saved yet. Save, then Publish.';
    note.classList.add('warn');
    if (relist) renderList();
    renderPreview();
  }

  // ---- The sections, as cards --------------------------------------------------------
  function renderList() {
    // (2.5) A long own-order list keeps its scroll position across redraws.
    const kept = {};
    listEl.querySelectorAll('.card.sec').forEach((c) => { const ol = c.querySelector('[data-olist]'); if (ol) kept[c.dataset.i] = ol.scrollTop; });
    listEl.innerHTML = '';
    if (!sections.length) {
      listEl.innerHTML = '<p class="muted">No sections, so the store and the site show the default: every game in a grid, then Coming soon. Add one below to arrange it yourself.</p>';
    }
    sections.forEach((s, i) => {
      const card = document.createElement('section');
      card.className = 'card sec';
      card.dataset.i = i;
      const layouts = LAYOUTS[s.type] || [];
      const chosen = (s.games || []).map(byId).filter(Boolean);
      const addable = listed.filter((g) => !(s.games || []).includes(g.id));
      card.innerHTML = `
        <div class="sec-head">
          <span class="tag${s.type === 'soon' ? ' soon' : s.type === 'spotlight' || s.type === 'category' || s.type === 'collection' || isChart(s.type) ? ' cyan' : ''}">${esc(NAMES[s.type])}</span>
          <span class="sec-title">${esc(s.title || (s.type === 'spotlight' ? (byId(s.game) || {}).name || '' : s.type === 'category' ? CATS[s.category] || '' : s.type === 'collection' ? (coll(s.collection) || {}).title || '' : ''))}</span>
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
          ${s.all ? orderHTML(s) : `
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
        ${isChart(s.type) ? `<label>Only<select data-only><option value="">Every part of the store</option>${Object.entries(CATS).map(([v, t]) => `<option value="${esc(v)}" ${s.category === v ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
          <p class="muted small-note">${CHARTS[s.type]} Filled in live, so the preview just shows your ${s.category ? esc((CATS[s.category] || '').toLowerCase()) : 'games'}. From Recomp Store 2.0; older stores leave it out${s.category ? ', and stores before 2.2 show every part in it' : ''}.</p>` : ''}
        ${s.type === 'category' ? `<label>Part of the store<select data-cat>${Object.entries(CATS).map(([v, t]) => `<option value="${esc(v)}" ${s.category === v ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></label>
          ${orderHTML(s)}` : ''}
        ${s.type === 'collection' ? (COLLS.length ? `<label>Collection<select data-coll>${COLLS.map((c) => `<option value="${esc(c.id)}" ${s.collection === c.id ? 'selected' : ''}>${esc(c.title)}</option>`).join('')}</select></label>
          <p class="muted small-note">${inColl(s.collection).length ? `${inColl(s.collection).length === 1 ? '1 app' : inColl(s.collection).length + ' apps'}: ${inColl(s.collection).map((g) => esc(g.name)).join(', ')}.` : 'Nothing listed in it yet, so the store leaves it out.'} An empty title uses the collection's name. Cross on the title opens its page in the store, with Install all. Stores before 2.3 leave it out.</p>`
          : '<p class="muted small-note">No collections yet. Make one on the <a href="collections.php">Collections</a> page.</p>') : ''}
        ${s.type === 'spotlight' ? `<label>Game<select data-spot>${listed.map((g) => `<option value="${esc(g.id)}" ${s.game === g.id ? 'selected' : ''}>${esc(optName(g))}</option>`).join('')}</select></label>
          <p class="muted small-note">A still banner of its backdrop, framed as on the Art card.</p>` : ''}`;
      listEl.appendChild(card);
      const ol = card.querySelector('[data-olist]');
      if (ol && kept[i]) ol.scrollTop = kept[i];

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
      if (cat) cat.addEventListener('change', () => { s.category = cat.value; if (s.sort === 'custom') s.order = itemsOf(Object.assign({}, s, { sort: '' })).map((g) => g.id); changed(true); });
      // (2.5) Its order. Your own order starts from the one it had.
      const so = card.querySelector('[data-sort]');
      if (so) so.addEventListener('change', () => {
        const was = itemsOf(s).map((g) => g.id);
        if (so.value) s.sort = so.value; else delete s.sort;
        if (s.sort === 'custom') s.order = was; else delete s.order;
        changed(true);
      });
      const cl = card.querySelector('[data-coll]');
      if (cl) cl.addEventListener('change', () => { s.collection = cl.value; changed(true); });
      // (2.2) A chart of one part of the store only.
      const only = card.querySelector('[data-only]');
      if (only) only.addEventListener('change', () => {
        if (only.value) s.category = only.value; else delete s.category;
        changed(true);
      });
    });
  }

  // ---- (2.5) Your own order: arrows, arrow keys on the handle, or dragging it ---------------
  const secOf = (el) => sections[+el.closest('.card.sec').dataset.i];
  function reorder(s, from, to, focus) {
    const ids = itemsOf(s).map((g) => g.id);
    if (to < 0 || to >= ids.length || from === to) return;
    const [id] = ids.splice(from, 1);
    ids.splice(to, 0, id);
    s.order = ids;
    changed(true);
    if (focus) {
      const card = listEl.querySelector(`.card.sec[data-i="${sections.indexOf(s)}"]`);
      const li = card && card.querySelector(`[data-olist] li[data-id="${CSS.escape(id)}"]`);
      if (li) { li.scrollIntoView({ block: 'nearest' }); li.querySelector(focus).focus({ preventScroll: true }); }
    }
  }
  listEl.addEventListener('click', (e) => {
    const b = e.target.closest('[data-omove]');
    if (!b) return;
    const [k, d] = b.dataset.omove.split(',').map(Number);
    const s = secOf(b);
    reorder(s, k, k + d, (k + d === 0 && d < 0) || (k + d === itemsOf(s).length - 1 && d > 0) ? '[data-ogrip]' : `[data-omove="${k + d},${d}"]`);
  });
  listEl.addEventListener('keydown', (e) => {
    const g = e.target.closest('[data-ogrip]');
    if (!g) return;
    const li = g.closest('li'), s = secOf(g);
    const k = [...li.parentNode.children].indexOf(li), n = li.parentNode.children.length;
    const to = { ArrowUp: k - 1, ArrowDown: k + 1, Home: 0, End: n - 1 }[e.key];
    if (to === undefined) return;
    e.preventDefault();
    reorder(s, k, to, '[data-ogrip]');
  });
  let odrag = null;
  const oplace = () => {
    const { row, ol, y } = odrag;
    const others = [...ol.children].filter((r) => r !== row);
    let before = null;
    for (const r of others) { const b = r.getBoundingClientRect(); if (y < b.top + b.height / 2) { before = r; break; } }
    if (before !== row.nextElementSibling || (!before && ol.lastElementChild !== row)) {
      const tops = new Map(others.map((r) => [r, r.getBoundingClientRect().top]));
      ol.insertBefore(row, before);
      others.forEach((r) => {
        const dy = tops.get(r) - r.getBoundingClientRect().top;
        if (!dy) return;
        r.style.transition = 'none';
        r.style.transform = `translateY(${dy}px)`;
        requestAnimationFrame(() => { r.style.transition = 'transform .15s ease'; r.style.transform = ''; });
      });
    }
    row.style.transform = '';
    row.style.transform = `translateY(${y - odrag.grab - row.getBoundingClientRect().top}px)`;
  };
  const otick = () => {
    if (!odrag) return;
    if (odrag.moved) {
      // Near the list's top or bottom (it scrolls), or the window's: scroll so it can go all the way.
      const b = odrag.ol.getBoundingClientRect(), y = odrag.y, edge = 44;
      const v = y < b.top + edge ? -Math.ceil((b.top + edge - y) / 5) : y > b.bottom - edge ? Math.ceil((y - b.bottom + edge) / 5) : 0;
      const before = odrag.ol.scrollTop;
      if (v) odrag.ol.scrollTop += v;
      const w = y < 70 ? -Math.ceil((70 - y) / 6) : y > innerHeight - 70 ? Math.ceil((y - innerHeight + 70) / 6) : 0;
      if (w && odrag.ol.scrollTop === before) window.scrollBy(0, w);
      if (v || w) oplace();
    }
    odrag.raf = requestAnimationFrame(otick);
  };
  listEl.addEventListener('pointerdown', (e) => {
    const grip = e.target.closest('[data-ogrip]');
    if (!grip || e.button !== 0 || odrag) return;
    e.preventDefault();
    const row = grip.closest('li');
    odrag = { row, grip, ol: row.parentNode, s: secOf(grip), id: e.pointerId, y: e.clientY, y0: e.clientY, grab: e.clientY - row.getBoundingClientRect().top, moved: false, raf: 0 };
    grip.setPointerCapture(e.pointerId);
    row.classList.add('dragging');
    document.body.classList.add('dragging');
    odrag.raf = requestAnimationFrame(otick);
  });
  window.addEventListener('pointermove', (e) => {
    if (!odrag || e.pointerId !== odrag.id) return;
    odrag.y = e.clientY;
    if (!odrag.moved && Math.abs(odrag.y - odrag.y0) < 4) return;
    odrag.moved = true;
    oplace();
  });
  const oend = (e) => {
    if (!odrag || e.pointerId !== odrag.id) return;
    const { row, grip, ol, s, raf, moved } = odrag;
    odrag = null;
    cancelAnimationFrame(raf);
    row.style.transform = '';
    row.classList.remove('dragging');
    document.body.classList.remove('dragging');
    if (grip.hasPointerCapture(e.pointerId)) grip.releasePointerCapture(e.pointerId);
    if (!moved) { grip.focus({ preventScroll: true }); return; }
    const keep = ol.scrollTop, id = row.dataset.id;
    s.order = [...ol.children].map((r) => r.dataset.id);
    changed(true);
    const card = listEl.querySelector(`.card.sec[data-i="${sections.indexOf(s)}"]`);
    const nol = card && card.querySelector('[data-olist]');
    if (nol) { nol.scrollTop = keep; const g = nol.querySelector(`li[data-id="${CSS.escape(id)}"] [data-ogrip]`); if (g) g.focus({ preventScroll: true }); }
  };
  window.addEventListener('pointerup', oend);
  window.addEventListener('pointercancel', oend);

  document.querySelectorAll('[data-add]').forEach((b) => b.addEventListener('click', () => {
    const t = b.dataset.add;
    if (t === 'games') sections.push({ type: 'games', title: '', layout: 'row', all: false, games: [] });
    else if (t === 'soon') sections.push({ type: 'soon', title: 'Coming soon', layout: 'row' });
    else if (t === 'category') sections.push({ type: 'category', title: 'Emulators', layout: 'row', category: 'emulators' });
    else if (t === 'collection') sections.push({ type: 'collection', title: '', layout: 'row', collection: (COLLS[0] || {}).id || '' });
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
    const slides = headerGames();
    // (2.1) The header, in the style picked (store 2.1 sizes: spotlight 520 tall; split and mosaic
    // a 1150-wide main card with the rest beside it).
    const card = (g, x, yy, w, h, big) => `<div class="d-card" style="${pos(x, yy, w, h)}">${pic(g)}<div class="d-card-words"><strong${big ? ' class="big"' : ''}>${esc(g.name)}</strong>${big ? `<span>${esc(g.blurb || '')}</span>` : ''}</div></div>`;
    if (slides.length && header.style !== 'none') {
      if (header.style === 'spotlight') {
        html += banner(slides[0], y, 'SPOTLIGHT', 0).replace(`height:${BANNER_H}px`, 'height:520px').replace('class="d-banner"', 'class="d-banner tall"');
        y += 520 + SEC_GAP;
      } else if (header.style === 'split') {
        html += banner(slides[0], y, 'FEATURED', Math.min(slides.length, 6)).replace('width:1728px', 'width:1150px').replace('class="d-banner"', 'class="d-banner narrow"');
        const rest = slides.slice(1, 4), ch = (BANNER_H - 2 * 16) / 3;
        rest.forEach((g, k) => (html += card(g, 96 + 1150 + 24, y + k * (ch + 16), 1728 - 1150 - 24, ch, false)));
        y += BANNER_H + SEC_GAP;
      } else if (header.style === 'mosaic') {
        const m = slides.slice(0, 3), sh = (BANNER_H - 16) / 2;
        if (m[0]) html += card(m[0], 96, y, 1150, BANNER_H, true);
        m.slice(1).forEach((g, k) => (html += card(g, 96 + 1150 + 24, y + k * (sh + 16), 1728 - 1150 - 24, sh, false)));
        y += BANNER_H + SEC_GAP;
      } else {
        html += banner(slides[0], y, 'FEATURED', slides.length);
        y += BANNER_H + SEC_GAP;
      }
    }
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
      const items = s.type === 'soon' ? soonGames : ownOrder(s) ? itemsOf(s) : s.type === 'collection' ? inColl(s.collection) : isChart(s.type) ? (s.category ? inCat(s.category) : listed) : (s.games || []).map((id) => listed.find((g) => g.id === id)).filter(Boolean);
      if (!items.length) {
        html += `<div class="d-empty" style="${pos(96, y, 1728, 64)}">${esc(s.title || NAMES[s.type])}: nothing in it, so the store leaves it out</div>`;
        y += 64 + SEC_GAP;
        return;
      }
      const head = s.title || (s.type === 'collection' ? (coll(s.collection) || {}).title || '' : '');
      if (head) { html += `<div class="d-head" style="${pos(96, y - 2)}">${esc(head)}${s.type === 'collection' ? ' <span class="d-more">›</span>' : ''}</div>`; y += HEAD; }
      if ((s.type === 'games' || s.type === 'category' || s.type === 'collection' || isChart(s.type)) && s.layout === 'grid') {
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
    saveForm.querySelector('[name="header"]').value = JSON.stringify({ style: header.style, interval: header.interval, games: header.games.filter(Boolean) });
    dirty = false;
  });
  if (publishForm) publishForm.addEventListener('submit', (e) => {
    if (dirty && !confirm('Discover has changes you haven\'t saved. Publish without them?')) e.preventDefault();
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  window.addEventListener('resize', renderPreview);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(renderPreview);
  renderHeader();
  renderList();
  renderPreview();
})();
