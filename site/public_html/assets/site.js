// Recomp Store website. Everything shown comes from /catalog.json, which the admin publishes.
(function () {
  'use strict';
  const view = document.getElementById('view');
  const dlg = document.getElementById('get');
  const backdrop = document.getElementById('backdrop-img');
  let cat = null;
  let slideTimer = null;
  let charts = null; // (2.0) /api/charts.php: Top, Trending, New, Recently updated
  // (2.0) The website's 2.0 side (categories, charts, apps only 2.0 lists, My PS5, Title IDs) shows
  // when the admin turned it on for everyone (catalog.json site.v2), to the logged-in admin as a
  // preview, and to a browser linked to a PS5 (it has 2.0). Everyone else sees the site as before.
  let siteV2 = false;
  let admin = false;
  const v2on = () => siteV2 || admin || !!(me && me.linked);

  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const byId = (id) => cat.apps.find((a) => a.id === id);
  const soonById = (id) => (cat.soon || []).find((a) => a.id === id);
  const art = (a) => a.heroWeb || a.hero || '';
  const tagFor = (a) => a.kind === 'original'
    ? '<span class="tag cyan">Recomp Store</span>'
    : `<span class="tag">By ${esc(a.author)}</span>`;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Catalog values that go inside style="": numbers as numbers, a picture's URL with nothing
  // that could end the url() or the attribute.
  const num = (v, d, lo, hi) => { const n = Number(v); return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : d; };
  const frame = (b) => { b = b || {}; return { x: num(b.x, 50, 0, 100), y: num(b.y, 30, 0, 100), z: num(b.zoom, 100, 100, 400) / 100 }; };
  const cssUrl = (u) => esc(String(u || '').replace(/[()'"\\\s\x00-\x1f]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase().padStart(2, '0')));

  function setBackdrop(a) {
    const src = a ? art(a) : '';
    if (!src) { backdrop.classList.remove('on'); return; }
    if (backdrop.getAttribute('src') !== src) {
      backdrop.classList.remove('on');
      backdrop.onload = () => backdrop.classList.add('on');
      backdrop.src = src;
    } else backdrop.classList.add('on');
  }

  function iconHTML(a, size) {
    return a.icon
      ? `<img class="ico" src="${esc(a.icon)}" alt="" width="${size}" height="${size}" loading="lazy">`
      : `<span class="ico mono">${esc((a.name || '?').slice(0, 2))}</span>`;
  }

  // ---- Home: the featured banner, then the sections the admin arranged (as in the store) --
  const DEFAULT_SECTIONS = [
    { type: 'games', title: 'Latest games', layout: 'grid', all: true },
    { type: 'soon', title: 'Coming soon', layout: 'row' },
  ];
  function tileHTML(a, soon, rank) {
    const href = soon ? `#/soon/${encodeURIComponent(a.id)}` : `#/game/${encodeURIComponent(a.id)}`;
    const sub = soon ? (a.date || 'Coming soon') : (a.kind === 'original' ? 'Recomp Store' : esc(a.author)) + (a.version ? ' · ' + esc(a.version) : '');
    return `
      <a class="tile" href="${href}" data-id="${esc(a.id)}"${soon ? ' data-soon' : ''}>
        <span class="tile-art">${iconHTML(a, 256)}${soon ? '<span class="badge-soon">Soon</span>' : ''}${rank ? `<span class="rank">${rank}</span>` : ''}</span>
        <span class="tile-name">${esc(a.name)}</span>
        <span class="tile-sub">${soon ? esc(sub) : sub}</span>
      </a>`;
  }
  function wideHTML(a, soon) {
    const href = soon ? `#/soon/${encodeURIComponent(a.id)}` : `#/game/${encodeURIComponent(a.id)}`;
    const b = frame(a.banner);
    return `
      <a class="wide" href="${href}" data-id="${esc(a.id)}"${soon ? ' data-soon' : ''}>
        <span class="wide-art">${art(a)
          ? `<img src="${esc(art(a))}" alt="" loading="lazy" style="object-position:${b.x}% ${b.y}%;transform-origin:${b.x}% ${b.y}%;transform:scale(${b.z})">${a.icon ? `<img class="wide-icon" src="${esc(a.icon)}" alt="" loading="lazy">` : ''}`
          : iconHTML(a, 256)}${soon ? '<span class="badge-soon">Soon</span>' : ''}</span>
        <span class="tile-name">${esc(a.name)}</span>
        <span class="tile-sub">${esc(soon ? (a.date || 'Coming soon') : (a.kind === 'original' ? 'Recomp Store' : a.author))}</span>
      </a>`;
  }
  function spotlightHTML(a, kicker) {
    const b = frame(a.banner);
    return `
      <section class="slider still" aria-label="${esc(kicker)}">
        <a class="slide on" href="#/game/${encodeURIComponent(a.id)}">
          <span class="slide-blur" style="background-image:url('${cssUrl(art(a))}')"></span>
          <span class="slide-art"><img src="${esc(art(a))}" alt="" loading="lazy" style="object-position:${b.x}% ${b.y}%;transform-origin:${b.x}% ${b.y}%;--z:${b.z}"></span>
          <span class="slide-glass">
            <span class="kicker">${esc(kicker)}</span>
            ${a.logo && a.logo.url
              ? `<img class="slide-logo" src="${esc(a.logo.url)}" alt="${esc(a.name)}" style="--s:${(a.logo.banner && a.logo.banner.scale ? a.logo.banner.scale : 100) / 100}">`
              : `<span class="slide-name">${esc(a.name)}</span>`}
            <span class="slide-blurb">${esc(a.blurb || a.description)}</span>
          </span>
        </a>
      </section>`;
  }
  function sectionsHTML() {
    const list = cat.discover && Array.isArray(cat.discover.sections) && cat.discover.sections.length ? cat.discover.sections : DEFAULT_SECTIONS;
    return list.map((sec) => {
      if (sec.type === 'spotlight') {
        const a = byId(sec.game);
        return a ? spotlightHTML(a, sec.title || 'Spotlight') : '';
      }
      if (['top', 'trending', 'new', 'updated'].includes(sec.type)) {
        // (2.0) The charts: installs counted by the store, and GitHub's releases.
        if (!charts || !v2on()) return '';
        const ranked = sec.type === 'top' || sec.type === 'trending';
        const ids = (charts[sec.type] || []).map((x) => (typeof x === 'string' ? x : x && x.id));
        // (2.2) A chart of one part of the store only.
        const items = ids.map(byId).filter((a) => a && (!sec.category || (a.category || 'games') === sec.category)).slice(0, sec.layout === 'grid' ? 24 : 12);
        if (!items.length) return '';
        const title = `<h2>${esc(sec.title || { top: 'Top charts', trending: 'Trending', new: 'New', updated: 'Recently updated' }[sec.type])}</h2>`;
        if (sec.layout === 'wide') return `<section class="games">${title}<div class="rowscroll wides">${items.map((a) => wideHTML(a, false)).join('')}</div></section>`;
        const tiles = items.map((a, i) => tileHTML(a, false, ranked ? i + 1 : 0)).join('');
        return `<section class="games">${title}<div class="${sec.layout === 'grid' ? 'grid' : 'rowscroll'}">${tiles}</div></section>`;
      }
      const soon = sec.type === 'soon';
      if (sec.type === 'category' && !v2on()) return '';
      // (2.0) A category section: every game in one part of the store (Games, Emulators, Apps, Tools).
      const items = soon ? (cat.soon || []) : sec.type === 'category' ? inCat(sec.category)
        : sec.all ? cat.apps : (sec.games || []).map(byId).filter(Boolean);
      if (sec.type !== 'games' && sec.type !== 'category' && !soon) return '';
      if (!items.length) return '';
      const title = sec.title ? `<h2>${esc(sec.title)}</h2>` : '';
      if (sec.layout === 'wide') return `<section class="games">${title}<div class="rowscroll wides">${items.map((a) => wideHTML(a, soon)).join('')}</div></section>`;
      if (sec.layout === 'grid' && !soon) return `<section class="games">${title}<div class="grid">${items.map((a) => tileHTML(a, false)).join('')}</div></section>`;
      return `<section class="games">${title}<div class="rowscroll">${items.map((a) => tileHTML(a, soon)).join('')}</div></section>`;
    }).join('');
  }

  // ---- How it works: the steps from the store to playing, and that no game files are here ----
  const HOW_ICONS = {
    install: '<path d="M12 3.5v10m0 0-4-4m4 4 4-4"/><path d="M4.5 15v3a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3"/>',
    pick: '<rect x="3.5" y="3.5" width="7" height="7" rx="2"/><rect x="13.5" y="3.5" width="7" height="7" rx="2"/><rect x="3.5" y="13.5" width="7" height="7" rx="2"/><rect x="13.5" y="13.5" width="7" height="7" rx="2"/>',
    backup: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="2.4"/><path d="M12 6.2a5.8 5.8 0 0 1 5.8 5.8"/>',
    setup: '<path d="M3.5 7.5a2 2 0 0 1 2-2h4l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/><path d="m9 13.6 2.2 2.2 4-4.2"/>',
    play: '<circle cx="12" cy="12" r="8.5"/><path d="M10.2 8.8v6.4l5-3.2z"/>',
    shield: '<path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6z"/><path d="m8.8 12 2.3 2.3 4.2-4.3"/>',
  };
  const howIcon = (k) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${HOW_ICONS[k]}</svg>`;
  function howHTML() {
    const steps = [
      ['install', 'Install Recomp Store', 'Send the installer to your jailbroken PS5\'s payload loader. The store lands on your home screen and keeps itself up to date.', '<a href="#/get">How to get it</a>'],
      ['pick', 'Pick a game', 'Browse the store and install a port. It comes straight from its developer\'s GitHub release.'],
      ['backup', 'Bring your own backup', 'Back up a game you own: a disc image, or the game\'s file from your console. Copy it to a USB drive or your PS5.'],
      ['setup', 'Set it up', 'On the game\'s page, choose your backup under Your game files. The store puts every file where the port expects it, and can fetch a Title Update the game needs.'],
      ['play', 'Play', 'Start it from the store or your home screen. Updates keep your game files and saves.'],
    ];
    return `
      <section class="how" aria-labelledby="how-title">
        <span class="kicker">How it works</span>
        <h2 id="how-title">From your own copy to your PS5, in five steps</h2>
        <ol class="flow">${steps.map(([icon, title, text, more], i) => `
          <li>
            <span class="flow-icon">${howIcon(icon)}<span class="flow-n" aria-hidden="true">${i + 1}</span></span>
            <strong>${title}</strong>
            <p>${text}${more ? ' ' + more : ''}</p>
          </li>`).join('')}
        </ol>
        <div class="glass byo">
          ${howIcon('shield')}
          <div>
            <strong>No game files here. Ever.</strong>
            <p>Recomp Store and this website don't host, sell or share games, disc images, ROMs or any other game files, and neither do the GitHub repositories they link to. They hold only the ports and their tools. Every game needs a backup of your own legally obtained copy: you bring it, the store sets it up.</p>
          </div>
        </div>
      </section>`;
  }

  // ---- (2.0) The parts of the store: a row of buttons on the home page, and a page each ----
  const CATS = [
    { key: 'games', route: '/games', name: 'Games', icon: '<path d="M7.5 8.5h9a4.5 4.5 0 0 1 4.4 5.4l-.6 3a2.6 2.6 0 0 1-4.5 1.2L14 16h-4l-1.8 2.1a2.6 2.6 0 0 1-4.5-1.2l-.6-3A4.5 4.5 0 0 1 7.5 8.5z"/><path d="M8 11v3M6.5 12.5h3"/><circle cx="15.5" cy="11.6" r=".9"/><circle cx="17.4" cy="13.6" r=".9"/>' },
    { key: 'emulators', route: '/emulators', name: 'Emulators', icon: '<rect x="4" y="15" width="16" height="5.5" rx="2"/><path d="M12 15V8.6"/><circle cx="12" cy="6.2" r="2.6"/><circle cx="16.6" cy="17.75" r=".9"/><path d="M6.8 17.75h3"/>' },
    { key: 'apps', route: '/apps', name: 'Apps', icon: '<rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/>' },
    { key: 'tools', route: '/tools', name: 'Tools', icon: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>' },
  ];
  const catIcon = (c) => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${c.icon}</svg>`;
  // (2.1) A category's apps in the order the admin chose for it (catalog categories[key].sort).
  const sortCat = (key, list) => {
    const mode = (cat.categories && cat.categories[key] && cat.categories[key].sort) || 'manual';
    const name = (a) => String(a.name || a.id).toLowerCase();
    const at = (ids) => { const m = {}; (ids || []).forEach((x, i) => { m[typeof x === 'string' ? x : x && x.id] = i; }); return (a) => (a.id in m ? m[a.id] : 1e9); };
    const out = list.slice();
    if (mode === 'az') out.sort((x, y) => name(x).localeCompare(name(y)));
    else if (mode === 'installs' && charts && charts.stats) {
      const n = (a) => (charts.stats[a.id] && charts.stats[a.id].installs) || 0;
      out.sort((x, y) => n(y) - n(x) || name(x).localeCompare(name(y)));
    } else if ((mode === 'new' || mode === 'updated') && charts) {
      const pos = at(charts[mode]);
      out.sort((x, y) => pos(x) - pos(y) || (x.order ?? 1e9) - (y.order ?? 1e9));
    }
    return out;
  };
  const inCat = (key) => sortCat(key, cat.apps.filter((a) => (a.category || 'games') === key));
  // Only parts that have something get a button, and the row shows when at least two do.
  function catRowHTML() {
    if (!v2on()) return '';
    const have = CATS.map((c) => [c, inCat(c.key).length]).filter(([, n]) => n > 0);
    if (have.length < 2) return '';
    return `<nav class="cat-row" aria-label="Browse by category">${have.map(([c, n]) => `
      <a class="glass cat-btn" href="#${c.route}"><span class="cat-ico">${catIcon(c)}</span><span class="cat-name">${esc(c.name)}</span><span class="cat-n">${n}</span></a>`).join('')}</nav>`;
  }
  function categoryPage(key) {
    const c = CATS.find((x) => x.key === key);
    const apps = inCat(key);
    // Its apps' labels, the most used first, to filter by.
    const count = {};
    apps.forEach((a) => (Array.isArray(a.tags) ? a.tags : []).forEach((t) => { if (typeof t === 'string' && t) count[t] = (count[t] || 0) + 1; }));
    const tags = Object.keys(count).sort((x, y) => count[y] - count[x] || x.localeCompare(y)).slice(0, 16);
    view.innerHTML = `
      <article class="cat-page">
        <a class="back" href="#/">← All games</a>
        <header class="cat-head"><span class="cat-ico big">${catIcon(c)}</span><div><h1>${esc(c.name)}</h1><p class="muted">${apps.length === 1 ? '1 app' : apps.length + ' apps'}</p></div></header>
        ${tags.length ? `<div class="chips filter" role="group" aria-label="Filter by label"><button type="button" class="tag on" data-tag="">All</button>${tags.map((t) => `<button type="button" class="tag" data-tag="${esc(t)}">${esc(t)}</button>`).join('')}</div>` : ''}
        ${apps.length ? '<div class="grid" data-grid></div>' : '<p class="lead">Nothing here yet.</p>'}
      </article>`;
    setBackdrop(null);
    const grid = view.querySelector('[data-grid]');
    const draw = (tag) => {
      if (!grid) return;
      const list = tag ? apps.filter((a) => Array.isArray(a.tags) && a.tags.includes(tag)) : apps;
      grid.innerHTML = list.map((a) => tileHTML(a, false)).join('');
      grid.querySelectorAll('.tile').forEach((t) => t.addEventListener('pointerenter', () => { const a = byId(t.dataset.id); if (a && art(a)) setBackdrop(a); }));
    };
    draw('');
    view.querySelectorAll('[data-tag]').forEach((b) => b.addEventListener('click', () => {
      view.querySelectorAll('[data-tag]').forEach((x) => x.classList.toggle('on', x === b));
      draw(b.dataset.tag);
    }));
  }

  function home() {
    const featured = ((v2on() && Array.isArray(cat.featuredV2) ? cat.featuredV2 : cat.featured) || []).map(byId).filter(Boolean);
    const slides = featured.length ? featured : cat.apps.filter((a) => art(a)).slice(0, 3);
    view.innerHTML = `
      <section class="intro">
        <h1>${esc((cat.site && cat.site.tagline) || 'Recompiled games, native on PS5.')}</h1>
        <p>Install and update PS5 ports of recompiled games. No account, no game files: bring your own disc.</p>
      </section>
      ${slides.length ? `
      <section class="slider" aria-roledescription="carousel" aria-label="Featured games">
        ${slides.map((a, i) => `
          <a class="slide${i === 0 ? ' on' : ''}" href="#/game/${encodeURIComponent(a.id)}" aria-hidden="${i === 0 ? 'false' : 'true'}" tabindex="${i === 0 ? 0 : -1}" data-slide="${i}">
            <span class="slide-blur" style="background-image:url('${cssUrl(art(a))}')"></span>
            <span class="slide-art"><img src="${esc(art(a))}" alt="" style="object-position:${frame(a.banner).x}% ${frame(a.banner).y}%;transform-origin:${frame(a.banner).x}% ${frame(a.banner).y}%;--z:${frame(a.banner).z}"></span>
            <span class="slide-glass">
              <span class="kicker">Featured</span>
              ${a.logo && a.logo.url
                ? `<img class="slide-logo" src="${esc(a.logo.url)}" alt="${esc(a.name)}" style="--s:${(a.logo.banner && a.logo.banner.scale ? a.logo.banner.scale : 100) / 100}">`
                : `<span class="slide-name">${esc(a.name)}</span>`}
              <span class="slide-blurb">${esc(a.blurb || a.description)}</span>
            </span>
          </a>`).join('')}
        ${slides.length > 1 ? `<div class="dots">${slides.map((a, i) => `<button data-dot="${i}" aria-label="Show ${esc(a.name)}"${i === 0 ? ' aria-current="true"' : ''}></button>`).join('')}</div>` : ''}
      </section>` : ''}
      ${catRowHTML()}
      ${sectionsHTML()}
      <section class="help">
        <a class="glass help-card" href="#/report">
          <span class="kicker">Found a bug?</span>
          <strong>Report it</strong>
          <span>Pick the game and tell us what happened. It goes straight to the developer.</span>
        </a>
        <a class="glass help-card" href="#/submit">
          <span class="kicker">Made something for PS5?</span>
          <strong>Submit to Recomp Store</strong>
          <span>A game port, an emulator, an app or a tool: send its GitHub, details and pictures, and it can go straight onto its own page.</span>
        </a>
      </section>
      ${howHTML()}`;
    setBackdrop(slides[0]);
    // Slider: crossfade every 7 seconds, with dots to jump.
    const els = [...view.querySelectorAll('.slide')];
    const dots = [...view.querySelectorAll('[data-dot]')];
    let cur = 0;
    const show = (i) => {
      cur = (i + els.length) % els.length;
      els.forEach((el, k) => { el.classList.toggle('on', k === cur); el.setAttribute('aria-hidden', k === cur ? 'false' : 'true'); el.tabIndex = k === cur ? 0 : -1; });
      dots.forEach((d, k) => d.toggleAttribute('aria-current', k === cur));
      setBackdrop(slides[cur]);
    };
    dots.forEach((d) => d.addEventListener('click', () => { show(+d.dataset.dot); restart(); }));
    const restart = () => { clearInterval(slideTimer); if (els.length > 1 && !reduced) slideTimer = setInterval(() => show(cur + 1), 7000); };
    restart();
    view.querySelectorAll('.tile, .wide').forEach((t) => t.addEventListener('pointerenter', () => {
      const a = t.hasAttribute('data-soon') ? soonById(t.dataset.id) : byId(t.dataset.id);
      if (a && art(a)) setBackdrop(a);
    }));
  }

  // ---- A Coming soon game's page ------------------------------------------------------
  function soonPage(id) {
    const a = soonById(id);
    if (!a) { notFound(); return; }
    const follow = a.source && a.source.type === 'github' && /^[\w.-]+\/[\w.-]+$/.test(a.source.repo || '') ? `https://github.com/${a.source.repo}`
      : /^https:\/\//.test(a.url || '') ? a.url : '';
    view.innerHTML = `
      <article class="game">
        <a class="back" href="#/">← All games</a>
        <div class="game-grid">
          <div class="game-info">
            <div class="chips"><span class="tag cyan">Coming soon</span>${a.date ? `<span class="tag">${esc(a.date)}</span>` : ''}${a.author ? `<span class="tag">By ${esc(a.author)}</span>` : ''}${shareHTML(a, 'soon')}</div>
            ${a.logo && a.logo.url
              ? `<h1 class="logo-h1"><img src="${esc(a.logo.url)}" alt="${esc(a.name)}" style="--s:${(a.logo.page && a.logo.page.scale ? a.logo.page.scale : 100) / 100}"></h1>`
              : `<h1>${esc(a.name)}</h1>`}
            <p class="lead">${esc(a.description || a.blurb)}</p>
          </div>
          <aside class="glass panel">
            <div class="panel-head">${iconHTML(a, 112)}<div><strong>${esc(a.name)}</strong><span>${a.author ? 'By ' + esc(a.author) : 'Coming soon'}</span></div></div>
            <div class="btn soon-btn" aria-disabled="true">${a.date ? 'Coming ' + esc(a.date) : 'Coming soon'}</div>
            <p class="note">It isn't out yet. When it is, it shows up in Recomp Store on your PS5 and here, ready to install.</p>
            ${follow ? `<a class="src" href="${esc(follow)}" target="_blank" rel="noopener">Follow it${follow.includes('github.com') ? ' on GitHub' : ''} ↗</a>` : ''}
          </aside>
        </div>
      </article>`;
    setBackdrop(a);
  }

  // ---- A game's own link -------------------------------------------------------------
  // recompstore.com/game/<ID> (Coming soon: /soon/<ID>) shows the game's card when it's shared
  // in Discord, X, Messages and the like; the server puts the game's name and picture in it.
  const ownLink = (a, kind) => `${location.origin}/${kind}/${encodeURIComponent(a.id)}`;
  function shareHTML(a, kind) {
    return `<button type="button" class="tag share" data-share="${esc(ownLink(a, kind))}" data-title="${esc(a.name)}">Share</button>`;
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-share]');
    if (!b) return;
    const url = b.dataset.share;
    const done = (t) => { b.textContent = t; setTimeout(() => (b.textContent = 'Share'), 1600); };
    // Phones: the share sheet. Elsewhere: the link, copied.
    if (navigator.share && matchMedia('(pointer: coarse)').matches) {
      navigator.share({ title: b.dataset.title, url }).catch(() => {});
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(() => done('Link copied'), () => window.prompt('Copy this link', url));
    } else {
      window.prompt('Copy this link', url);
    }
  });

  // ---- Game page ------------------------------------------------------------------
  // The admin's extra buttons (texture packs, patches...): its text and color, dark text on a light color.
  function linksHTML(a) {
    const ok = (Array.isArray(a.links) ? a.links : []).filter((l) => l && /^https:\/\//.test(l.url || '') && l.label);
    if (!ok.length) return '';
    const ink = (hex) => {
      const n = parseInt(String(hex).slice(1), 16) || 0;
      const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
      return 0.2126 * lin(n >> 16 & 255) + 0.7152 * lin(n >> 8 & 255) + 0.0722 * lin(n & 255) > 0.4 ? '#0B1220' : '#F4F8FC';
    };
    return `<div class="extra-links">${ok.map((l) => {
      const c = /^#[0-9a-f]{6}$/i.test(l.color || '') ? l.color : '#2a3446';
      return `<a class="btn extra" href="${esc(l.url)}" target="_blank" rel="noopener" style="--b:${c};--t:${ink(c)}">${esc(l.label)}</a>`;
    }).join('')}</div>`;
  }

  // (2.2) People also installed: other apps on the consoles that have this one (the charts' "also").
  function alsoHTML(a) {
    if (!v2on() || !charts || !charts.also || !Array.isArray(charts.also[a.id])) return '';
    const items = charts.also[a.id].map(byId).filter(Boolean).slice(0, 8);
    if (!items.length) return '';
    return `<section class="games also"><h2>People also installed</h2><div class="rowscroll">${items.map((x) => tileHTML(x, false)).join('')}</div></section>`;
  }
  function game(id) {
    const a = byId(id);
    if (!a) { notFound(); return; }
    const repoUrl = a.source && a.source.type === 'github' && /^[\w.-]+\/[\w.-]+$/.test(a.source.repo || '') ? `https://github.com/${a.source.repo}` : '';
    const rows = [
      ['Title ID', a.id], ['Version', a.version], ['Download', a.size], ['You bring', 'Your ' + (a.needs || 'own game')],
      ['Installs to', '/data/homebrew/' + a.id], ['Needs', a.minStore ? 'Recomp Store ' + a.minStore + ' or newer' : ''],
      ['On consoles', v2on() && charts && charts.stats && charts.stats[a.id] && charts.stats[a.id].installs ? Number(charts.stats[a.id].installs).toLocaleString() : ''],
    ].filter((r) => r[1]);
    view.innerHTML = `
      <article class="game">
        <a class="back" href="#/">← All games</a>
        <div class="game-grid">
          <div class="game-info">
            <div class="chips">${tagFor(a)}<span class="tag">${esc(a.id)}</span>${shareHTML(a, 'game')}</div>
            ${a.logo && a.logo.url
              ? `<h1 class="logo-h1"><img src="${esc(a.logo.url)}" alt="${esc(a.name)}" style="--s:${(a.logo.page && a.logo.page.scale ? a.logo.page.scale : 100) / 100}"></h1>`
              : `<h1>${esc(a.name)}</h1>`}
            <p class="lead">${esc(a.description)}</p>
            ${a.news || repoUrl ? `
            <details class="glass news" id="notes">
              <summary><span class="kicker">What's new${a.version ? ' · ' + esc(a.version) : ''}</span>${a.news ? `<p>${esc(a.news)}</p>` : ''}${repoUrl ? '<span class="more">All patch notes</span>' : ''}</summary>
              <div class="notes" data-notes><p class="muted">Loading the notes from GitHub…</p></div>
            </details>` : ''}
            ${a.setup ? setupHTML(a) : ''}
            ${a.credits ? `<p class="credits">${esc(a.credits)}</p>` : ''}
            <div class="glass disc-note"><span class="kicker">Bring your own disc</span><p>Recomp Store and this game's GitHub repository don't host any game files. Set it up from your own legally obtained copy.</p></div>
          </div>
          <aside class="glass panel">
            <div class="panel-head">${iconHTML(a, 112)}<div><strong>${esc(a.name)}</strong><span>${a.kind === 'original' ? 'Recomp Store' : 'By ' + esc(a.author)}</span></div></div>
            ${installHTML(a)}
            ${a.minStore ? `<p class="note needs">Needs Recomp Store ${esc(a.minStore)} or newer. On your PS5, update the store from Latest Updates first.</p>` : ''}
            <a class="btn" id="dl" href="${esc(a.url || (repoUrl ? repoUrl + '/releases/latest' : '#'))}" target="_blank" rel="noopener">${repoUrl && a.source.inside ? "The developer's release" : 'Download the zip'}</a>
            <dl>${rows.map((r) => `<div><dt>${esc(r[0])}</dt><dd${r[0] === 'Version' ? ' data-version' : ''}>${esc(r[1])}</dd></div>`).join('')}</dl>
            ${repoUrl ? `<a class="src" href="${esc(repoUrl)}" target="_blank" rel="noopener">Source on GitHub ↗</a>` : ''}
            <p class="note">Updates through Recomp Store replace only the app. Your game install, saves, DLC and mods stay where they are.</p>
            ${linksHTML(a)}
          </aside>
        </div>
        ${alsoHTML(a)}
      </article>`;
    setBackdrop(a);
    resolveDownload(a);
    const notes = document.getElementById('notes');
    if (notes && repoUrl) notes.addEventListener('toggle', () => { if (notes.open) loadNotes(a.source.repo, notes.querySelector('[data-notes]')); }, { once: false });
  }

  // (2.2) What's new's plain text, as the store draws it: lines starting with "- " are bullets, a blank
  // line starts a new paragraph, and a single line break stays one.
  function notesBlocks(text) {
    const html = [];
    let para = [], list = [];
    const flush = () => {
      if (para.length) html.push(`<p>${para.map(esc).join('<br>')}</p>`);
      if (list.length) html.push(`<ul>${list.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>`);
      para = []; list = [];
    };
    String(text || '').replace(/\r\n?/g, '\n').split('\n').forEach((raw) => {
      const line = raw.replace(/\s+$/, '');
      if (!line.trim()) { flush(); return; }
      if (/^- /.test(line)) { if (para.length) flush(); list.push(line.slice(2)); return; }
      if (list.length) flush();
      para.push(line);
    });
    flush();
    return html.join('');
  }

  // ---- Patch notes: every release on the game's GitHub repo -------------------------
  const notesCache = {};
  function md(text) {
    // A small, safe Markdown: escaped first, then headings, lists, bold, code and https links.
    const inline = (t) => esc(t)
      .replace(/!\[[^\]]*\]\([^)]*\)/g, '')
      .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>')
      .replace(/\*\*([^*]+)\*\*|__([^_]+)__/g, (m, x, y) => `<strong>${x || y}</strong>`)
      .replace(/`([^`]+)`/g, '<code>$1</code>');
    const out = [];
    let list = false;
    String(text || '').replace(/<!--[\s\S]*?-->/g, '').split(/\r?\n/).forEach((raw) => {
      const line = raw.trim();
      const li = line.match(/^[-*+]\s+(.*)$/);
      if (li) { if (!list) { out.push('<ul>'); list = true; } out.push(`<li>${inline(li[1])}</li>`); return; }
      if (list) { out.push('</ul>'); list = false; }
      if (!line || /^(-{3,}|\*{3,}|_{3,})$/.test(line)) return;
      const h = line.match(/^#{1,6}\s+(.*)$/);
      if (h) out.push(`<h4>${inline(h[1])}</h4>`);
      else { const t = inline(line.replace(/^>\s?/, '')); if (t.trim()) out.push(`<p>${t}</p>`); }
    });
    if (list) out.push('</ul>');
    return out.join('');
  }
  function loadNotes(repo, el) {
    const show = (rel) => {
      if (!rel || !rel.length) { el.innerHTML = '<p class="muted">No releases on GitHub yet.</p>'; return; }
      el.innerHTML = rel.filter((r) => !r.draft).map((r) => `
        <section class="release">
          <h3>${esc(r.name || r.tag_name)}</h3>
          <p class="when">${esc(new Date(r.published_at).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' }))}${r.prerelease ? ' · Pre-release' : ''}${r.name && r.name !== r.tag_name ? ' · ' + esc(r.tag_name) : ''}</p>
          ${md(r.body) || '<p class="muted">No notes for this release.</p>'}
        </section>`).join('');
    };
    if (notesCache[repo]) { show(notesCache[repo]); return; }
    fetch(`https://api.github.com/repos/${repo}/releases?per_page=30`, { headers: { Accept: 'application/vnd.github+json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((rel) => { notesCache[repo] = rel; show(rel); })
      .catch(() => { el.innerHTML = `<p class="muted">GitHub's notes couldn't be loaded. <a href="https://github.com/${esc(repo)}/releases" target="_blank" rel="noopener">See them on GitHub</a>.</p>`; });
  }

  // A zip name with * (any run of characters) or ? (any one), as the store matches it.
  const nameMatches = (pattern, name) => new RegExp('^' + pattern.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$').test(name);

  // For a GitHub source, point the download straight at the zip on the newest release that
  // has it (as the store does), and show that release's version.
  function resolveDownload(a) {
    if (!a.source || a.source.type !== 'github' || !a.source.repo || !a.source.asset) return;
    fetch(`https://api.github.com/repos/${a.source.repo}/releases?per_page=10`, { headers: { Accept: 'application/vnd.github+json' } })
      .then((r) => (r.ok ? r.json() : null))
      .then((list) => {
        if (!Array.isArray(list)) return;
        for (const rel of list) {
          if (rel.draft || !Array.isArray(rel.assets)) continue;
          const asset = rel.assets.find((x) => nameMatches(a.source.asset, x.name));
          if (!asset) continue;
          const link = document.getElementById('dl');
          if (link && a.source.inside) {
            // The PS5 files are a zip inside this bigger one: the release page, where the
            // developer explains their own way to set it up.
            link.href = rel.html_url || `https://github.com/${a.source.repo}/releases`;
            link.textContent = `The developer's release${rel.tag_name ? ' (' + rel.tag_name + ')' : ''}`;
          } else if (link) {
            link.href = asset.browser_download_url;
            link.textContent = `Download ${asset.name} (${(asset.size / 1048576).toFixed(0)} MB)`;
          }
          const ver = document.querySelector('[data-version]');
          if (ver && rel.tag_name) ver.textContent = rel.tag_name.replace(/^v(?=\d)/i, '');
          return;
        }
      })
      .catch(() => {});
  }

  function setupHTML(a) {
    const s = a.setup || {};
    const slots = Array.isArray(s.slots) ? s.slots : [];
    const what = (sl) => sl.kind === 'iso' ? 'the files inside your disc image' : sl.kind === 'stfs' ? 'unpacked from the package' + (sl.updateId ? ', or downloaded by the store' : '')
      : sl.kind === 'arcade' ? 'unpacked from your Xbox Live Arcade game file'
      : sl.kind === 'image' ? 'your disc image as it is' + (sl.discId ? ', disc ' + sl.discId : '') : '';
    const lines = slots.map((sl) => sl.to === '.' || sl.to === ''
      ? `└── (${esc(sl.label)}: ${what(sl)}, right in this folder)`
      : `├── ${esc(sl.to)}/   ${esc(sl.label)}${what(sl) ? ' (' + what(sl) + ')' : ''}`);
    return `
      <section class="glass setup">
        <span class="kicker">Your game files</span>
        <p>Recomp Store sets this game up from your own ${slots.length > 1 ? 'files' : slots[0] && slots[0].kind === 'arcade' ? 'game file (from the console\'s 000D0000 folder)' : slots[0] && slots[0].kind === 'file' ? 'file' : 'disc image'}: on the game's page in the store, choose ${slots.length > 1 ? 'them' : 'it'} under Your game files and press Set up. Done by hand, it looks like this:</p>
        <pre class="tree">${esc(s.dataDir || '/data/homebrew/' + a.id)}/\n${lines.join('\n')}</pre>
      </section>`;
  }

  // ---- About ----------------------------------------------------------------------
  function about() {
    const s = cat.site || {};
    view.innerHTML = `
      <article class="about">
        <div class="about-head"><img src="/assets/logo-512.png" alt=""><div><h1>Recomp Store</h1><p>Made by elripalda.</p></div></div>
        <div class="glass notice">
          <span class="kicker">Bring your own disc</span>
          <p>Recomp Store doesn't host any game files or copyrighted game content, and neither do the GitHub repositories it links to. They hold only the ports and their tools. Every game needs your own legally obtained copy, which you set up yourself.</p>
          <p>Title Updates the store downloads for you come from XboxUnity's community archive; Recomp Store doesn't host them either.</p>
          <p class="small">Recomp Store and the games listed in it aren't affiliated with or endorsed by Sony Interactive Entertainment, Microsoft, or any game's publisher or developer. All trademarks belong to their owners.</p>
        </div>
        <h2>Adding your game</h2>
        <ol class="steps plain">
          <li><strong>Install the game from Recomp Store.</strong> The port lands in /data/homebrew/&lt;title ID&gt;.</li>
          <li><strong>Back up your own copy.</strong> A disc image, or the game's file from your Xbox 360 for an Xbox Live Arcade game. Neither the store nor this site ever downloads games.</li>
          <li><strong>Copy it to your PS5.</strong> Anywhere on the console over FTP, or on a USB drive.</li>
          <li><strong>Set up the game files in the store.</strong> On the game's page, choose your backup under Your game files and press Set up. If the game needs a Title Update, the store can download it for you. You do this once: updates keep your install and saves.</li>
        </ol>
        <p class="links">${[
          '<a href="#/report">Report a bug</a>',
          s.discord ? `<a href="${esc(s.discord)}" target="_blank" rel="noopener">Discord · help</a>` : '',
          s.kofi ? `<a href="${esc(s.kofi)}" target="_blank" rel="noopener">Ko-fi</a>` : '',
          '<a href="https://github.com/elripalda/RecompStore" target="_blank" rel="noopener">GitHub</a>',
        ].filter(Boolean).join(' · ')}</p>
      </article>`;
    setBackdrop(null);
  }

  // ---- Forms: bug reports and recomp submissions (they land in the admin's Inbox) ------
  function formPage(kind) {
    const bug = kind === 'bug';
    const games = cat.apps.map((a) => `<option value="${esc(a.id)}">${esc(a.name)}</option>`).join('');
    view.innerHTML = `
      <article class="form-page">
        <a class="back" href="#/contact">← Contact</a>
        <h1>${bug ? 'Report a bug' : 'Submit your recomp'}</h1>
        <p class="lead">${bug
          ? 'Tell us what went wrong and which game it was in. The more you can say about what you did right before, the faster it gets fixed.'
          : 'Ported a recompiled game to PS5? Tell us about it. We\'ll look at it, get in touch, and talk about putting it in Recomp Store.'}</p>
        <form class="glass form" novalidate>
          ${bug ? `
            <label>Which game?<select name="app" required><option value="">Choose a game</option>${games}<option value="store">Recomp Store itself</option></select></label>
            <label>Version <span class="opt">optional</span><input name="version" maxlength="64" placeholder="Shown on the game's page, e.g. 1.0.0"></label>
            <label>What happened?<textarea name="body" rows="7" maxlength="5000" required placeholder="What you did, what you expected, and what happened instead."></textarea></label>
            <label>How can we reach you? <span class="opt">optional</span><input name="contact" maxlength="200" placeholder="Email or Discord name, if you'd like a reply"></label>`
          : `
            <label>Your name or handle<input name="name" maxlength="120" required autocomplete="nickname"></label>
            <label>Email<input name="email" type="email" maxlength="200" required autocomplete="email" placeholder="So we can get back to you"></label>
            <label>GitHub repository<input name="repo" type="url" maxlength="255" required placeholder="https://github.com/you/your-port"></label>
            <label>What is it?<textarea name="body" rows="6" maxlength="5000" required placeholder="The game, what it needs from the player, and how far along it is."></textarea></label>`}
          <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
          <p class="form-note">${bug ? 'Bug reports are only seen by the developer.' : 'Only the Recomp Store developer sees this. Your email is used just to talk about your port.'}</p>
          <div class="form-row"><button class="btn primary" type="submit">${bug ? 'Send the report' : 'Send it'}</button><span class="status" role="status"></span></div>
        </form>
      </article>`;
    setBackdrop(null);
    wireForm(view.querySelector('form'), kind,
      bug ? [['app', 'Choose the game the bug is in.'], ['body', 'Tell us what happened.']]
          : [['name', 'Add your name or handle.'], ['email', 'Add your email.'], ['repo', 'Add the GitHub repository link.'], ['body', 'Tell us about the port.']],
      bug ? ['Thanks, the report is in.', 'It went straight to the developer.'] : ['Thanks, it\'s on its way.', 'We\'ll be in touch at the email you gave.']);
  }

  // (2.1) Submit a game port, emulator, app or tool: its repo, the details for its page and, if the
  // developer wants, its pictures. Lands in the admin's Inbox, ready to add to the store as a draft.
  function submitPage() {
    const kinds = [['games', 'Game port', 'A recompiled or ported game'], ['emulators', 'Emulator', 'Plays other consoles\' games'],
      ['apps', 'App', 'Media, streaming, anything else'], ['tools', 'Tool', 'For the PS5 itself: files, payloads, mods']];
    view.innerHTML = `
      <article class="form-page submit-page">
        <a class="back" href="#/contact">← Contact</a>
        <h1>Submit to Recomp Store</h1>
        <p class="lead">Made something for jailbroken PS5? Send it in. Everything you fill in here goes on its page in the store, so the more you give, the faster it can go live. Only the Recomp Store developer sees this form.</p>
        <form class="glass form" novalidate enctype="multipart/form-data">
          <fieldset class="kinds"><legend>What is it?</legend>
            ${kinds.map(([k, n, d], i) => `<label class="kind"><input type="radio" name="kind" value="${k}"${i === 0 ? ' checked' : ''}><span><strong>${n}</strong><small>${d}</small></span></label>`).join('')}
          </fieldset>
          <h2>The basics</h2>
          <div class="two">
            <label>Name<input name="name" maxlength="120" required placeholder="What players will see"></label>
            <label>Made by<input name="author" maxlength="80" required autocomplete="nickname" placeholder="Your name or handle"></label>
            <label>How can we reach you?<input name="contact" maxlength="200" required placeholder="Email or Discord name"></label>
            <label>GitHub repository<input name="repo" maxlength="255" required placeholder="https://github.com/you/your-app" inputmode="url" spellcheck="false"></label>
            <label>Release file <span class="opt">optional</span><input name="asset" maxlength="160" placeholder="MyApp-*.zip" spellcheck="false"><small>The .zip or .7z on your GitHub release the store should install. Put * for the part that changes with each version.</small></label>
            <label>Version <span class="opt">optional</span><input name="version" maxlength="40" placeholder="1.0.0"></label>
            <label>Title ID <span class="opt">optional</span><input name="title_id" maxlength="9" placeholder="PPSA12345" spellcheck="false" class="mono"><small>Already have one? Check it doesn't clash on the <a href="#/ids" target="_blank">Title IDs page</a>. No? We'll pick one with you.</small></label>
          </div>
          <h2>Its page in the store</h2>
          <label>One-line summary<input name="blurb" maxlength="160" placeholder="Shown under its name on Discover"></label>
          <label>Description<textarea name="description" rows="7" maxlength="6000" required placeholder="What it is, what works, what players should know."></textarea></label>
          <label data-needs>What players need <span class="opt">optional</span><input name="needs" maxlength="160" placeholder="For example: your own Xbox 360 disc image"></label>
          <label>Credits <span class="opt">optional</span><textarea name="credits" rows="2" maxlength="1000" placeholder="Who made what, and what it builds on"></textarea></label>
          <div class="three">
            <label>Link <span class="opt">optional</span><input name="link1" maxlength="300" placeholder="https://discord.gg/..." inputmode="url"></label>
            <label>Link <span class="opt">optional</span><input name="link2" maxlength="300" placeholder="https://ko-fi.com/..." inputmode="url"></label>
            <label>Link <span class="opt">optional</span><input name="link3" maxlength="300" placeholder="https://your.site" inputmode="url"></label>
          </div>
          <h2>Pictures <span class="opt">optional</span></h2>
          <p class="form-note">PNG, JPEG or WebP, 8 MB each. No pictures? They can be added later.</p>
          <div class="pics">
            <label class="pic">Icon<input type="file" name="icon" accept="image/png,image/jpeg,image/webp" data-pic="icon"><small>Square, 512×512 or bigger.</small><span class="pv icon" data-pv="icon" aria-hidden="true"></span></label>
            <label class="pic">Backdrop<input type="file" name="hero" accept="image/png,image/jpeg,image/webp" data-pic="hero"><small>16:9, ideally 3840×2160. Behind its page and on Discover.</small><span class="pv hero" data-pv="hero" aria-hidden="true"><span class="pv-logo" data-pv="logo"></span></span></label>
            <label class="pic">Logo<input type="file" name="logo" accept="image/png,image/webp" data-pic="logo"><small>Its title as a picture, on a transparent background. Shown over the backdrop.</small></label>
            <label class="pic">Screenshots<input type="file" name="shots[]" accept="image/png,image/jpeg,image/webp" multiple data-pic="shots"><small>Up to 4, from the PS5.</small><span class="pv shots" data-pv="shots" aria-hidden="true"></span></label>
          </div>
          <label>Anything else? <span class="opt">optional</span><textarea name="notes" rows="3" maxlength="2000" placeholder="Anything the developer of Recomp Store should know"></textarea></label>
          <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
          <label class="check"><input type="checkbox" name="rights" value="1" required> I made this, or I'm allowed to submit it. It doesn't include game files or anything else copyrighted.</label>
          <div class="form-row"><button class="btn primary" type="submit">Send it</button><span class="status" role="status"></span></div>
        </form>
      </article>`;
    setBackdrop(null);
    const form = view.querySelector('form');
    const status = form.querySelector('.status');
    const needs = form.querySelector('[data-needs]');
    const kindNow = () => (form.querySelector('input[name="kind"]:checked') || {}).value;
    form.addEventListener('change', (e) => {
      if (e.target.name === 'kind') needs.hidden = kindNow() !== 'games';
      const pic = e.target.dataset && e.target.dataset.pic;
      if (!pic) return;
      const files = [...e.target.files];
      const url = (f) => URL.createObjectURL(f);
      if (pic === 'shots') {
        const box = form.querySelector('[data-pv="shots"]');
        box.innerHTML = files.slice(0, 4).map((f) => `<img src="${url(f)}" alt="">`).join('');
        if (files.length > 4) { status.textContent = 'Four screenshots at most: only the first four go.'; status.className = 'status bad'; }
        return;
      }
      const pv = form.querySelector(`[data-pv="${pic}"]`);
      if (pv) pv.style.backgroundImage = files[0] ? `url("${url(files[0])}")` : '';
    });
    const need = [['name', 'Give it a name.'], ['author', 'Say who made it.'], ['contact', 'Leave an email or a Discord name.'],
      ['repo', 'Add the GitHub repository.'], ['description', 'Add a description.']];
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      for (const [k, msg] of need) if (!String(fd.get(k) || '').trim()) { status.textContent = msg; status.className = 'status bad'; form.querySelector(`[name="${k}"]`).focus(); return; }
      if (!fd.get('rights')) { status.textContent = 'Tick the box to confirm you made it or may submit it.'; status.className = 'status bad'; return; }
      const shots = fd.getAll('shots[]').filter((f) => f && f.size);
      fd.delete('shots[]');
      shots.slice(0, 4).forEach((f) => fd.append('shots[]', f));
      const btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      status.textContent = 'Sending…'; status.className = 'status';
      fetch('/api/submit.php', { method: 'POST', body: fd })
        .then((r) => r.json().catch(() => ({ ok: false })))
        .then((res) => {
          if (res.ok) form.innerHTML = `<div class="sent"><strong>Thanks, it's in.</strong><p>${esc(res.message || '')}</p><a class="btn" href="#/">Back to the store</a></div>`;
          else { status.textContent = res.error || 'That didn\'t send. Try again in a moment.'; status.className = 'status bad'; btn.disabled = false; }
        })
        .catch(() => { status.textContent = 'That didn\'t send. Check your connection and try again.'; status.className = 'status bad'; btn.disabled = false; });
    });
  }

  // Sends a form to the Inbox: the fields in need must be filled; sent is the thank-you.
  function wireForm(form, kind, need, sent) {
    const status = form.querySelector('.status');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      data.kind = kind;
      for (const [k, msg] of need) if (!String(data[k] || '').trim()) { status.textContent = msg; status.className = 'status bad'; form.querySelector(`[name="${k}"]`).focus(); return; }
      const btn = form.querySelector('button');
      btn.disabled = true;
      status.textContent = 'Sending…'; status.className = 'status';
      fetch('/api/inbox.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
        .then((r) => r.json().catch(() => ({ ok: false })))
        .then((res) => {
          if (res.ok) {
            form.innerHTML = `<div class="sent"><strong>${sent[0]}</strong><p>${sent[1]}</p><a class="btn" href="#/">Back to all games</a></div>`;
          } else {
            status.textContent = res.error || 'That didn\'t send. Try again in a moment.'; status.className = 'status bad'; btn.disabled = false;
          }
        })
        .catch(() => { status.textContent = 'That didn\'t send. Check your connection and try again.'; status.className = 'status bad'; btn.disabled = false; });
    });
  }

  // ---- Contact: the two forms, a message, and the email address ---------------------------
  const CONTACT_EMAIL = 'info@recompstore.com';
  function contactPage() {
    view.innerHTML = `
      <article class="form-page contact">
        <a class="back" href="#/">← All games</a>
        <h1>Contact</h1>
        <p class="lead">Found a bug, made a recomp, or have a question? Here's how to reach us.</p>
        <section class="help contact-cards">
          <a class="glass help-card" href="#/report">
            <span class="kicker">Found a bug?</span>
            <strong>Report it</strong>
            <span>Pick the game and tell us what happened. It goes straight to the developer.</span>
          </a>
          <a class="glass help-card" href="#/submit">
            <span class="kicker">Made something for PS5?</span>
            <strong>Submit to Recomp Store</strong>
            <span>A game port, an emulator, an app or a tool, with its details and pictures.</span>
          </a>
          <a class="glass help-card" href="mailto:${CONTACT_EMAIL}">
            <span class="kicker">Email us</span>
            <strong class="mail">${CONTACT_EMAIL}</strong>
            <span>For questions and anything else.</span>
          </a>
        </section>
        <h2 class="contact-h2">Send a message</h2>
        <form class="glass form" novalidate>
          <label>Your name or handle<input name="name" maxlength="120" required autocomplete="nickname"></label>
          <label>Email<input name="email" type="email" maxlength="200" required autocomplete="email" placeholder="So we can reply"></label>
          <label>Message<textarea name="body" rows="6" maxlength="5000" required></textarea></label>
          <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
          <p class="form-note">Only the Recomp Store developer sees this. Your email is used just to reply.</p>
          <div class="form-row"><button class="btn primary" type="submit">Send the message</button><span class="status" role="status"></span></div>
        </form>
      </article>`;
    setBackdrop(null);
    wireForm(view.querySelector('form'), 'message',
      [['name', 'Add your name or handle.'], ['email', 'Add your email.'], ['body', 'Write your message.']],
      ['Thanks, your message is in.', 'We\'ll reply to the email you gave.']);
  }

  // ---- Title IDs (2.0): what's taken, so two apps never share one on a PS5 ----------------
  function idsPage() {
    view.innerHTML = `
      <article class="form-page ids">
        <a class="back" href="#/">← All games</a>
        <h1>Title IDs</h1>
        <p class="lead">Two apps with the same title ID clash on a PS5: installing one writes over the other. Check yours here before you release.</p>
        <form class="glass form ids-check" novalidate>
          <label>Your title ID<input name="id" maxlength="9" placeholder="PPSA12345" autocomplete="off" spellcheck="false"></label>
          <div class="form-row"><button class="btn primary" type="submit">Check it</button><span class="status" role="status"></span></div>
        </form>
        <section class="ids-list"><p class="muted">Loading the list…</p></section>
      </article>`;
    setBackdrop(null);
    const list = view.querySelector('.ids-list');
    const form = view.querySelector('.ids-check');
    const status = form.querySelector('.status');
    const input = form.querySelector('[name="id"]');
    let data = null;
    const row = (id, what, kind) => `<li class="glass"><code>${esc(id)}</code><span>${esc(what)}</span><em class="tag ${kind}">${kind === 'store' ? 'In Recomp Store' : kind === 'test' ? 'Reserved' : kind === 'block' ? 'Kept by the store' : 'Taken'}</em></li>`;
    fetch('/api/ids.php', { cache: 'no-cache' })
      .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then((d) => {
        data = d;
        const b = d.block || {};
        list.innerHTML = `
          <h2>In Recomp Store</h2><ul class="ids-ul">${(d.store || []).map((x) => row(x.id, x.name, x.testing ? 'test' : 'store')).join('')}</ul>
          <h2>Taken outside the store</h2><ul class="ids-ul">${(d.taken || []).map((x) => row(x.id, x.note || 'Another app', 'taken')).join('')}</ul>
          <h2>Kept by the store</h2><ul class="ids-ul">${row(`${b.from} – ${b.to}`, 'Recomp Store gives these to an app whose own ID is already taken on a console. Don\'t release with one.', 'block')}</ul>
          <p class="muted">Using an ID that isn't listed? <a href="#/contact">Tell us</a> and we'll add it.</p>`;
      })
      .catch(() => { list.innerHTML = '<p class="muted">The list didn\'t load. Refresh the page in a moment.</p>'; });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const id = input.value.trim().toUpperCase();
      input.value = id;
      if (!/^[A-Z]{4}\d{5}$/.test(id)) { status.textContent = 'A title ID is four letters and five numbers, like PPSA12345.'; status.className = 'status bad'; return; }
      if (!data) { status.textContent = 'The list is still loading. Try again in a second.'; status.className = 'status bad'; return; }
      const inStore = (data.store || []).find((x) => x.id === id);
      const taken = (data.taken || []).find((x) => x.id === id);
      const b = data.block || {};
      if (inStore) { status.textContent = inStore.testing ? `${id} is taken: it's kept for an app that isn't out yet.` : `${id} is taken: ${inStore.name} uses it.`; status.className = 'status bad'; }
      else if (taken) { status.textContent = `${id} is taken${taken.note ? ': ' + taken.note : ''}.`; status.className = 'status bad'; }
      else if (id >= b.from && id <= b.to) { status.textContent = `${id} is kept by the store for apps it moves. Pick another.`; status.className = 'status bad'; }
      else { status.textContent = `${id} isn't on our list. Tell us when you use it, so nobody else does.`; status.className = 'status ok'; }
    });
  }

  // ---- (2.0) A phone or computer linked to a PS5 ---------------------------------------------
  // me: what /api/link.php says about this browser ({linked, device, console, state}). Linked, a
  // game's page installs it on the PS5, and My PS5 shows what's on it.
  let me = null;
  let pageTimers = [];
  const later = (fn, ms) => { const t = setTimeout(fn, ms); pageTimers.push(t); return t; };
  const every = (fn, ms) => { const t = setInterval(fn, ms); pageTimers.push(t); return t; };
  const stopPageTimers = () => { pageTimers.forEach((t) => { clearTimeout(t); clearInterval(t); }); pageTimers = []; };
  const failed = { ok: false, error: 'That didn\'t go through. Check your connection and try again.' };
  const api = (a, data) => fetch('/api/link.php', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ a }, data || {})),
  }).then((r) => r.json().catch(() => failed)).catch(() => failed);
  function refreshMe() {
    return fetch('/api/link.php?a=mine', { cache: 'no-store', credentials: 'same-origin' })
      .then((r) => r.json()).catch(() => null)
      .then((j) => { if (j && j.ok) me = j; else if (!me) me = { ok: true, linked: false }; markLinked(); return me; });
  }
  function markLinked() {
    document.querySelectorAll('[data-nav="my"]').forEach((n) => { n.hidden = !(me && me.linked); });
    setLists();
  }
  // What the site lists: today's catalog, plus what only 2.0 lists when its 2.0 side shows.
  let raw = null;
  function setLists() {
    if (!raw || !cat) return;
    const on = v2on();
    cat.apps = (raw.apps || []).concat(raw.newerStoreApps || [], on ? raw.v2Apps || [] : []);
    cat.apps.sort((x, y) => (x.order ?? 1e9) - (y.order ?? 1e9)); // the admin's order, all lists together
    cat.soon = (raw.comingSoon || []).concat(on ? raw.comingSoonV2 || [] : []).sort((x, y) => (x.order ?? 1e9) - (y.order ?? 1e9));
    cat.categories = raw.categories || {}; // (2.1) each category's sort
    const ids = document.querySelector('[data-foot-ids]');
    if (ids) ids.hidden = !on;
  }
  // What to call this browser: a phone, or a computer (once linked, the name the site gave it).
  const deviceWord = () => (matchMedia('(pointer: coarse)').matches || /Mobi|iPhone|iPad|Android/i.test(navigator.userAgent) ? 'phone' : 'computer');
  const thisDevice = () => (me && me.device && me.device.label && me.device.label !== 'Phone or computer' ? me.device.label : deviceWord());
  const code6 = (c) => String(c || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 6);
  const show6 = (c) => (c.length > 3 ? c.slice(0, 3) + '-' + c.slice(3) : c);
  const show8 = (c) => String(c).replace(/^(\d{2})(\d{3})(\d{3})$/, '$1-$2-$3');
  function ago(iso) {
    const s = Math.max(0, (Date.now() - Date.parse(iso)) / 1000);
    if (!Number.isFinite(s)) return '';
    if (s < 90) return 'just now';
    if (s < 3600) return Math.round(s / 60) + ' minutes ago';
    if (s < 86400 * 1.5) return Math.round(s / 3600) + (Math.round(s / 3600) === 1 ? ' hour ago' : ' hours ago');
    return Math.round(s / 86400) + ' days ago';
  }
  const appsOnPs5 = () => (me && me.linked && me.state && Array.isArray(me.state.apps) ? me.state.apps.filter((x) => x && x.id) : []);
  const onPs5 = (id) => appsOnPs5().find((x) => x.id === id);
  const PS5_SVG = '<svg viewBox="0 0 48 48" aria-hidden="true"><rect x="9" y="6" width="18" height="36" rx="6"/><path d="M27 9c5 1 9 4 9 15s-4 14-9 15"/><path d="M15 16h6M15 22h6"/></svg>';
  const PHONE_SVG = '<svg viewBox="0 0 48 48" aria-hidden="true"><rect x="14" y="5" width="20" height="38" rx="5"/><path d="M21 37h6"/></svg>';
  const linkArt = (done) => `
    <div class="link-art${done ? ' done' : ''}" aria-hidden="true">
      <span class="dev">${PHONE_SVG}</span><span class="wire"><i></i><i></i><i></i></span><span class="dev ps5">${PS5_SVG}</span>
      ${done ? '<span class="tick"><svg viewBox="0 0 24 24"><path d="m6 12.5 4 4 8-9"/></svg></span>' : ''}
    </div>`;

  function linkedDone(box) {
    box.innerHTML = `
      ${linkArt(true)}
      <h1>Linked</h1>
      <p class="lead">This ${deviceWord()} and your PS5 are linked. Install apps on it from any game's page here, or open My PS5 to see what's on it.</p>
      <div class="btn-col"><a class="btn primary big" href="#/my-ps5">Open My PS5</a><a class="btn" href="#/">Browse the store</a></div>`;
  }

  // recompstore.com/l/<code>: the code the PS5 shows (its QR code opens this), one button.
  function linkClaimPage(code) {
    const c = code6(code);
    view.innerHTML = `
      <article class="form-page link-page">
        <div class="glass link-card">
          ${linkArt(false)}
          <span class="kicker">Recomp Store</span>
          <h1>Link this ${deviceWord()} to your PS5</h1>
          <p class="lead">Your PS5 is showing <strong class="code-inline">${esc(show6(c))}</strong>. Once they're linked, you can install apps and add sources on your PS5 from here.</p>
          <button class="btn primary big" type="button" data-claim>Link this ${deviceWord()}</button>
          <p class="status" role="status"></p>
          <p class="small">${me && me.linked ? 'This browser is linked to a PS5 already. Linking moves it to this one. ' : ''}No account needed. Unlink it any time, here or in Recomp Store's Settings.</p>
        </div>
      </article>`;
    setBackdrop(null);
    const card = view.querySelector('.link-card');
    const btn = card.querySelector('[data-claim]');
    const st = card.querySelector('.status');
    btn.addEventListener('click', () => {
      btn.disabled = true;
      st.textContent = 'Linking…'; st.className = 'status';
      api('claim', { code: c }).then((res) => {
        if (!res.ok) { st.textContent = res.error || 'That code didn\'t work. Make a new one on your PS5.'; st.className = 'status bad'; btn.disabled = false; return; }
        refreshMe().then(() => linkedDone(card));
      });
    });
  }

  // #/link: type the code the PS5 shows, or make a code here to type on the PS5.
  function linkPage() {
    const dw = deviceWord();
    view.innerHTML = `
      <article class="form-page link-page">
        <a class="back" href="#/">← All games</a>
        <h1>Link your PS5</h1>
        <p class="lead">Link this ${dw} to Recomp Store on your PS5. Then install apps and add sources on it from here, without the controller. No account needed.</p>
        ${me && me.linked ? `<p class="glass note-card">This ${dw} is linked already. <a href="#/my-ps5">Open My PS5</a>, or link it again below to move it to another PS5.</p>` : ''}
        <div class="link-ways">
          <form class="glass form" data-way="ps5" novalidate>
            <span class="kicker">Your PS5 shows a code</span>
            <p class="form-note">In Recomp Store on your PS5, open Settings and choose to link a phone. Scan the QR code it shows, or type its code here.</p>
            <label>The code on your PS5<input name="code" class="code-input" maxlength="7" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="K7Q-4X2"></label>
            <div class="form-row"><button class="btn primary" type="submit">Link</button><span class="status" role="status"></span></div>
          </form>
          <div class="glass form" data-way="web">
            <span class="kicker">Or type a code on your PS5</span>
            <p class="form-note">Make a code here, then type it on your PS5 where you redeem codes: Settings, then Redeem a code.</p>
            <div class="webcode" hidden><strong class="big-code"></strong><span class="muted" data-left></span></div>
            <div class="form-row"><button class="btn" type="button" data-make>Make a code</button><span class="status" role="status"></span></div>
          </div>
        </div>
      </article>`;
    setBackdrop(null);
    const f = view.querySelector('[data-way="ps5"]');
    const input = f.querySelector('input');
    const st = f.querySelector('.status');
    input.addEventListener('input', () => { const c = code6(input.value); input.value = show6(c); });
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      const c = code6(input.value);
      if (c.length !== 6) { st.textContent = 'The code has 6 letters and numbers, like K7Q-4X2.'; st.className = 'status bad'; input.focus(); return; }
      const b = f.querySelector('button');
      b.disabled = true; st.textContent = 'Linking…'; st.className = 'status';
      api('claim', { code: c }).then((res) => {
        b.disabled = false;
        if (!res.ok) { st.textContent = res.error; st.className = 'status bad'; return; }
        refreshMe().then(() => { const w = view.querySelector('.link-ways'); w.classList.add('one'); w.innerHTML = '<div class="glass link-card"></div>'; linkedDone(w.firstChild); });
      });
    });
    const web = view.querySelector('[data-way="web"]');
    const make = web.querySelector('[data-make]');
    const wst = web.querySelector('.status');
    make.addEventListener('click', () => {
      make.disabled = true; wst.textContent = 'Making a code…'; wst.className = 'status';
      const before = me && me.linked && me.console ? me.console.id : '';
      api('webcode').then((res) => {
        make.disabled = false;
        if (!res.ok) { wst.textContent = res.error; wst.className = 'status bad'; return; }
        stopPageTimers();
        web.querySelector('.webcode').hidden = false;
        web.querySelector('.big-code').textContent = show8(res.code);
        make.textContent = 'Make another';
        wst.textContent = 'Waiting for your PS5…'; wst.className = 'status';
        const until = Date.now() + (res.expires || 600) * 1000;
        const left = web.querySelector('[data-left]');
        const tick = () => {
          const s = Math.max(0, Math.round((until - Date.now()) / 1000));
          left.textContent = s ? `Works for ${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')} more` : 'This code ran out. Make another.';
          if (!s) { stopPageTimers(); wst.textContent = ''; }
        };
        tick();
        every(tick, 1000);
        every(() => refreshMe().then(() => {
          if (me && me.linked && me.console && me.console.id !== before) {
            stopPageTimers();
            const w = view.querySelector('.link-ways'); w.classList.add('one'); w.innerHTML = '<div class="glass link-card"></div>'; linkedDone(w.firstChild);
          }
        }), 3000);
      });
    });
  }

  // #/my-ps5: the linked PS5, what's on it, and what this browser can send it.
  function myPage() {
    view.innerHTML = `<article class="form-page my-ps5"><a class="back" href="#/">← All games</a><h1>My PS5</h1><div data-my><p class="muted">Loading…</p></div></article>`;
    setBackdrop(null);
    refreshMe().then(drawMy);
    every(() => refreshMe().then(() => { if (!document.activeElement || !view.contains(document.activeElement) || document.activeElement === document.body) drawMy(); }), 30000);
  }
  // (2.3.0) Requests sent from here (Delete, Move, ...): their number, until the PS5 says how it went.
  const myReqs = {};
  let reqTimer = 0;
  const gb = (b) => (Number(b) / 1073741824 >= 1000 ? (Number(b) / 1099511627776).toFixed(2) + ' TB' : (Number(b) / 1073741824).toFixed(1) + ' GB');
  const sizeWords = (b) => (Number(b) > 0 ? (Number(b) >= 1073741824 ? gb(b) : Math.max(1, Math.round(Number(b) / 1048576)) + ' MB') : '');
  function reqLine(key) {
    const st = me && me.state || {};
    const mine = Object.entries(myReqs).filter(([, r]) => r.key === key).map(([id]) => Number(id)).sort((x, y) => y - x)[0];
    if (!mine) return '';
    const r = (Array.isArray(st.requests) ? st.requests : []).find((q) => Number(q.id) === mine);
    if (!r || r.state === 'waiting') return `<span class="req-line">Waiting for you to confirm on the PS5…</span>`;
    return `<span class="req-line ${r.state === 'done' ? 'ok' : 'bad'}">${esc(r.state === 'ignored' ? 'Ignored on the PS5.' : r.text || (r.state === 'done' ? 'Done.' : 'It didn\'t work.'))}</span>`;
  }
  function appRow(x) {
    const a = x.id ? byId(x.id) : null;
    const name = (a && a.name) || x.name || x.id || String(x.folder || '').split('/').pop();
    const st = me && me.state || {};
    const v2 = Number(st.v) >= 2;
    const key = x.id || x.folder;
    const drives = (Array.isArray(st.drives) ? st.drives : []).filter((d) => d.root && d.root !== x.root);
    return `
      <li class="glass ps5-app" data-key="${esc(key)}">
        ${a ? `<a href="#/game/${encodeURIComponent(a.id)}">${iconHTML(a, 56)}</a>` : `<span class="ico mono">${esc(String(name).slice(0, 2))}</span>`}
        <span class="ps5-app-words"><strong>${a ? `<a href="#/game/${encodeURIComponent(a.id)}">${esc(name)}</a>` : esc(name)}</strong>
          <span>${x.version ? esc(x.version) : 'Installed'}${x.update ? ` · <em class="upd">${esc(x.update)} ready</em>` : ''}${v2 && x.size > 0 ? ' · ' + sizeWords(x.size) : ''}${v2 && x.where ? ' · ' + esc(x.where) : ''}</span>
          ${v2 ? reqLine(key) : ''}</span>
        ${x.update ? `<button class="btn small" type="button" data-send-app="${esc(x.id)}">Update</button>` : ''}
        ${v2 ? `<span class="ps5-acts">
          ${x.store ? `<button class="chip-btn${x.fav ? ' on' : ''}" type="button" data-act="favorite" data-on="${x.fav ? 0 : 1}" aria-pressed="${x.fav ? 'true' : 'false'}">${x.fav ? '★ Favorite' : '☆ Favorite'}</button>
          <button class="chip-btn${x.auto ? ' on' : ''}" type="button" data-act="autoupdate" data-on="${x.auto ? 0 : 1}" aria-pressed="${x.auto ? 'true' : 'false'}">Auto-update ${x.auto ? 'on' : 'off'}</button>` : ''}
          ${drives.length ? `<select class="chip-btn" data-act="move" aria-label="Move ${esc(name)} to"><option value="">Move to…</option>${drives.map((d) => `<option value="${esc(d.root)}">${esc(d.label)}</option>`).join('')}</select>` : ''}
          <button class="chip-btn danger" type="button" data-act="delete">Delete</button>
        </span>` : ''}
      </li>`;
  }
  function storageHTML(st) {
    const drives = Array.isArray(st.drives) ? st.drives : [];
    if (!drives.length) return '';
    return `<h2 class="my-h2">Storage</h2><div class="glass drives-card">${drives.map((d) => {
      const sized = d.sized && Number(d.total) > 0;
      const used = sized ? Math.min(100, Math.max(0, 100 * (1 - Number(d.free) / Number(d.total)))) : 0;
      return `<div class="drive-row"><span class="drive-name">${esc(d.label)}</span><span class="drive-num">${sized ? gb(d.free) + ' free of ' + gb(d.total) : 'Size unknown'}</span>
        <span class="drive-bar${sized ? '' : ' unknown'}"><i style="width:${used.toFixed(1)}%"></i></span></div>`;
    }).join('')}</div>`;
  }
  function downloadsHTML(st) {
    const q = st.queue || {};
    const waiting = Array.isArray(q.waiting) ? q.waiting : [];
    if (!q.now && !waiting.length) return '';
    const nm = (id) => { const a = byId(id); return esc((a && a.name) || id); };
    return `<h2 class="my-h2">Downloads</h2><ul class="ps5-apps">
      ${q.now ? `<li class="glass ps5-app"><span class="ps5-app-words"><strong>${nm(q.now)}</strong><span>${esc(q.step || 'Installing')}${Number(q.total) > 0 ? ' · ' + Math.round(100 * Number(q.done) / Number(q.total)) + '%' : ''}</span></span></li>` : ''}
      ${waiting.filter((id) => id !== q.now).map((id) => `<li class="glass ps5-app" data-key="${esc(id)}"><span class="ps5-app-words"><strong>${nm(id)}</strong><span>Waiting${reqLine(id) ? '' : ''}</span>${reqLine(id)}</span>
        <button class="btn small" type="button" data-act="cancel">Cancel</button></li>`).join('')}
    </ul>`;
  }
  function sendAct(kind, key, extra, btn) {
    const st = me && me.state || {};
    const x = (Array.isArray(st.apps) ? st.apps : []).find((y) => (y.id || y.folder) === key) || { id: key };
    if (kind === 'delete' && !confirm(`Ask your PS5 to delete ${x.name || key}? It asks you to confirm on the PS5 first.`)) return;
    const data = Object.assign(x.id ? { app: x.id } : { folder: x.folder }, extra || {});
    if (btn) btn.disabled = true;
    api('send', { kind, data }).then((res) => {
      if (btn) btn.disabled = false;
      if (!res.ok) { alert(res.error || 'That didn\'t go through.'); return; }
      if (res.req && (kind === 'delete' || kind === 'move' || kind === 'cancel')) myReqs[res.req] = { key, kind };
      if (kind === 'favorite' || kind === 'autoupdate') { x[kind === 'favorite' ? 'fav' : 'auto'] = !!extra.on; }
      drawMy();
      clearTimeout(reqTimer);
      let tries = 0;
      const tick = () => refreshMe().then(() => { drawMy(); if (++tries < 40 && Object.keys(myReqs).length) reqTimer = setTimeout(tick, 4000); });
      reqTimer = setTimeout(tick, 4000);
    });
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-act]');
    if (!b || !b.closest('.my-ps5')) return;
    const li = b.closest('[data-key]');
    if (!li) return;
    const kind = b.dataset.act;
    sendAct(kind, li.dataset.key, kind === 'favorite' || kind === 'autoupdate' ? { on: Number(b.dataset.on) } : {}, b);
  });
  document.addEventListener('change', (e) => {
    const sel = e.target.closest('select[data-act="move"]');
    if (!sel || !sel.value || !sel.closest('.my-ps5')) return;
    const li = sel.closest('[data-key]');
    const to = sel.value;
    const label = sel.options[sel.selectedIndex].textContent;
    sel.value = '';
    if (li && confirm(`Ask your PS5 to move it to ${label}? It asks you to confirm on the PS5 first.`)) sendAct('move', li.dataset.key, { to }, sel);
  });
  function drawMy() {
    const box = view.querySelector('[data-my]');
    if (!box) return;
    if (!me || !me.linked) {
      box.innerHTML = `
        <div class="glass link-card">
          ${linkArt(false)}
          <p class="lead">This ${deviceWord()} isn't linked to a PS5 yet. Link it to see what's on your PS5 and install apps on it from here.</p>
          <a class="btn primary big" href="#/link">Link your PS5</a>
        </div>`;
      return;
    }
    const st = me.state || {};
    const apps = (Number(st.v) >= 2 && Array.isArray(st.apps) ? st.apps.filter((x) => x && (x.id || x.folder)) : appsOnPs5())
      .slice().sort((x, y) => String(x.name || x.id).localeCompare(String(y.name || y.id)));
    const ups = apps.filter((x) => x.update);
    const srcs = Array.isArray(st.sources) ? st.sources.filter((x) => x && x.value) : [];
    const sendable = cat.apps.filter((a) => !onPs5(a.id)).slice().sort((x, y) => x.name.localeCompare(y.name));
    const free = Number(st.free_gb);
    box.innerHTML = `
      <section class="glass console-card">
        <span class="console-ico">${PS5_SVG}</span>
        <div class="console-words">
          <strong>Your PS5</strong>
          <span>${me.console.store ? 'Recomp Store ' + esc(me.console.store) + ' · ' : ''}seen ${esc(ago(me.console.seen))}${Number.isFinite(free) && free > 0 ? ' · ' + free.toFixed(1) + ' GB free' : ''}</span>
        </div>
        <span class="tag">Linked to this ${esc(thisDevice())}</span>
      </section>
      ${storageHTML(st)}
      ${downloadsHTML(st)}
      <h2 class="my-h2">On your PS5 <span class="muted">${apps.length || ''}</span></h2>
      ${ups.length ? `<p class="glass note-card upd-card">${ups.length === 1 ? '1 update is' : ups.length + ' updates are'} ready. Install ${ups.length === 1 ? 'it' : 'them'} from here, or from Latest Updates on your PS5.</p>` : ''}
      ${apps.length ? `<ul class="ps5-apps">${apps.map(appRow).join('')}</ul>`
        : `<p class="muted">${me.state_at ? 'Nothing from Recomp Store is installed on it yet.' : 'Open Recomp Store on your PS5 and what\'s on it shows up here.'}</p>`}
      <div class="my-forms">
        <form class="glass form" data-send-form novalidate>
          <span class="kicker">Install an app</span>
          <label>App<select name="app"><option value="">Choose an app</option>${sendable.map((a) => `<option value="${esc(a.id)}">${esc(a.name)}</option>`).join('')}</select></label>
          <div class="form-row"><button class="btn primary" type="submit">Install on your PS5</button><span class="status" role="status"></span></div>
        </form>
        <form class="glass form" data-source-form novalidate>
          <span class="kicker">Add a custom source</span>
          <p class="form-note">A GitHub repo (owner/name) or the https:// address of a list of them. Apps from it aren't checked by Recomp Store, and your PS5 asks you before adding it.</p>
          <label>Repo or list<input name="v" maxlength="500" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="owner/name"></label>
          <div class="src-preview" hidden></div>
          <div class="form-row"><button class="btn" type="submit" data-step="check">Check it</button><span class="status" role="status"></span></div>
          ${srcs.length ? `<div class="srcs"><span class="muted">On your PS5:</span> ${srcs.map((x) => `<span class="tag">${esc(x.name || x.value)}</span>`).join(' ')}</div>` : ''}
        </form>
        <form class="glass form" data-redeem-form novalidate>
          <span class="kicker">Redeem a beta code</span>
          <label>Code<input name="code" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="12-345-678"></label>
          <div class="form-row"><button class="btn" type="submit">Redeem</button><span class="status" role="status"></span></div>
        </form>
      </div>
      <p class="unlink-row"><button class="linkish" type="button" data-forget>Unlink this ${esc(thisDevice())}</button></p>`;
    // Install an app
    const sf = box.querySelector('[data-send-form]');
    sf.addEventListener('submit', (e) => {
      e.preventDefault();
      const s = sf.querySelector('.status');
      const id = sf.querySelector('[name="app"]').value;
      if (!id) { s.textContent = 'Choose an app.'; s.className = 'status bad'; return; }
      sendInstall(id, s, sf.querySelector('button'));
    });
    // A custom source: checked here first, then sent.
    const src = box.querySelector('[data-source-form]');
    const prev = src.querySelector('.src-preview');
    const sbtn = src.querySelector('button');
    const sst = src.querySelector('.status');
    let checked = null;
    const sv = src.querySelector('[name="v"]');
    sv.addEventListener('input', () => { checked = null; prev.hidden = true; sbtn.textContent = 'Check it'; sst.textContent = ''; });
    src.addEventListener('submit', (e) => {
      e.preventDefault();
      const v = sv.value.trim().replace(/^https:\/\/(www\.)?github\.com\//i, '').replace(/\/+$/, '');
      const isRepo = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(v);
      const isList = /^https:\/\/\S+$/.test(sv.value.trim());
      if (!isRepo && !isList) { sst.textContent = 'Enter a GitHub repo like owner/name, or a list\'s https:// address.'; sst.className = 'status bad'; return; }
      if (checked) {
        sbtn.disabled = true; sst.textContent = 'Sending…'; sst.className = 'status';
        api('send', { kind: 'source', data: checked }).then((res) => {
          sbtn.disabled = false;
          sst.textContent = res.ok ? 'Sent. Your PS5 asks you to confirm it, then adds it.' : res.error; sst.className = res.ok ? 'status ok' : 'status bad';
          if (res.ok) { checked = null; sbtn.textContent = 'Check it'; }
        });
        return;
      }
      sbtn.disabled = true; sst.textContent = 'Checking…'; sst.className = 'status';
      fetch('/api/source.php?' + (isRepo ? 'repo=' + encodeURIComponent(v) : 'list=' + encodeURIComponent(sv.value.trim())))
        .then((r) => r.json()).catch(() => ({ ok: false, error: 'That couldn\'t be checked right now. Try again in a moment.' }))
        .then((res) => {
          sbtn.disabled = false;
          if (!res.ok) { sst.textContent = res.error; sst.className = 'status bad'; prev.hidden = true; return; }
          const apps = res.apps || [];
          prev.hidden = false;
          prev.innerHTML = `
            <strong>${esc(res.source && res.source.name)}</strong>${res.source && res.source.author ? ` <span class="muted">by ${esc(res.source.author)}</span>` : ''}
            <ul>${apps.slice(0, 8).map((a) => `<li>${a.icon ? `<img src="${esc(a.icon)}" alt="" loading="lazy">` : `<span class="ico mono">${esc(String(a.name).slice(0, 2))}</span>`}<span>${esc(a.name)}${a.version ? ` <span class="muted">${esc(a.version)}${a.size ? ' · ' + esc(a.size) : ''}</span>` : ''}</span></li>`).join('')}</ul>
            ${apps.length > 8 ? `<p class="muted">And ${apps.length - 8} more.</p>` : ''}
            ${(res.errors || []).length ? `<p class="muted">${res.errors.length === 1 ? '1 repo in it couldn\'t be read' : res.errors.length + ' repos in it couldn\'t be read'}: ${res.errors.slice(0, 3).map((x) => esc(x.repo) + ' (' + esc(x.error) + ')').join('; ')}</p>` : ''}`;
          if (!apps.length) { sst.textContent = 'Nothing in it can be installed yet.'; sst.className = 'status bad'; return; }
          checked = isRepo ? { repo: v } : { list: sv.value.trim() };
          sbtn.textContent = 'Send to your PS5';
          sst.textContent = ''; sst.className = 'status';
        });
    });
    // A beta code
    const rf = box.querySelector('[data-redeem-form]');
    const rc = rf.querySelector('[name="code"]');
    rc.addEventListener('input', () => { const d = rc.value.replace(/\D/g, '').slice(0, 8); rc.value = d.length > 5 ? d.slice(0, 2) + '-' + d.slice(2, 5) + '-' + d.slice(5) : d.length > 2 ? d.slice(0, 2) + '-' + d.slice(2) : d; });
    rf.addEventListener('submit', (e) => {
      e.preventDefault();
      const s = rf.querySelector('.status');
      const d = rc.value.replace(/\D/g, '');
      if (d.length !== 8) { s.textContent = 'A code is 8 numbers, like 12-345-678.'; s.className = 'status bad'; return; }
      const b = rf.querySelector('button');
      b.disabled = true; s.textContent = 'Checking…'; s.className = 'status';
      api('redeem', { code: d }).then((res) => { b.disabled = false; s.textContent = res.ok ? res.message : res.error; s.className = res.ok ? 'status ok' : 'status bad'; if (res.ok) rc.value = ''; });
    });
    box.querySelector('[data-forget]').addEventListener('click', () => {
      if (!confirm(`Unlink this ${thisDevice()} from your PS5? You can link it again any time.`)) return;
      api('forget').then(() => { me = { ok: true, linked: false }; markLinked(); drawMy(); });
    });
  }

  // Installing an app on the linked PS5: from a game's page, My PS5, or an Update button.
  function sendInstall(id, status, btn) {
    if (btn) btn.disabled = true;
    if (status) { status.hidden = false; status.textContent = 'Sending…'; status.className = status.className.replace(/\b(ok|bad)\b/g, '').trim(); }
    api('send', { kind: 'install', data: { app: id } }).then((res) => {
      if (btn) btn.disabled = false;
      if (!status) return;
      status.textContent = res.ok ? res.message : res.error || 'That didn\'t go through.';
      status.classList.add(res.ok ? 'ok' : 'bad');
      if (res.linked === false) refreshMe();
    });
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-send-app]');
    if (!b || b.closest('form')) return;
    const holder = b.closest('.panel, .ps5-app');
    let st = holder && holder.querySelector('.send-status');
    if (!st && holder && holder.classList.contains('ps5-app')) {
      st = document.createElement('span'); st.className = 'note send-status'; holder.querySelector('.ps5-app-words').appendChild(st);
    }
    sendInstall(b.dataset.sendApp, st, b);
  });

  // A game's page, its install button: on the linked PS5, or the way to get the store.
  function installHTML(a) {
    if (!v2on()) return '<a class="btn primary" href="#/get" data-get>Install with Recomp Store</a>';
    if (me && me.linked) {
      const on = onPs5(a.id);
      const label = on ? (on.update ? `Update to ${esc(on.update)} on your PS5` : 'Install again on your PS5') : 'Install on your PS5';
      return `${on ? `<p class="on-ps5"><span class="dot"></span>On your PS5${on.version ? ' · ' + esc(on.version) : ''}</p>` : ''}
        <button class="btn primary" type="button" data-send-app="${esc(a.id)}">${label}</button>
        <p class="note send-status" role="status" hidden></p>
        <a class="small-link" href="#/get">Don't have Recomp Store yet?</a>`;
    }
    return `<a class="btn primary" href="#/get" data-get>Install with Recomp Store</a>
      <a class="small-link" href="#/link">Have Recomp Store? Link your PS5 to install from here.</a>`;
  }

  // ---- (2.0) /r/<code>: a bug report the store sent, finished here ----------------------------
  function reportPage(code) {
    const c = code6(code);
    view.innerHTML = `
      <article class="form-page report-page">
        <span class="kicker">Bug report</span>
        <h1>Finish your report</h1>
        <div data-r><p class="muted">Loading…</p></div>
      </article>`;
    setBackdrop(null);
    const box = view.querySelector('[data-r]');
    fetch('/api/report.php?a=info&code=' + encodeURIComponent(c), { cache: 'no-store' })
      .then((r) => r.json()).catch(() => ({ ok: false, error: 'recompstore.com didn\'t answer. Try again in a moment.' }))
      .then((info) => {
        if (!info.ok) { box.innerHTML = `<p class="lead">${esc(info.error)}</p><a class="btn" href="#/report">Report a bug another way</a>`; return; }
        if (info.finished) { box.innerHTML = '<p class="lead">This report is finished already. Thanks for helping!</p><a class="btn" href="#/">Back to all games</a>'; return; }
        const g = byId(info.app.id);
        box.innerHTML = `
          <p class="lead">Your logs from <strong>${esc(info.app.name)}</strong>${info.version ? ' ' + esc(info.version) : ''} are in. Tell us what happened, and add pictures if they help.</p>
          <form class="glass form" novalidate>
            <div class="report-about">${g ? iconHTML(g, 56) : ''}<span>${esc(info.app.name)}<span class="muted">${info.version ? 'Version ' + esc(info.version) + ' · ' : ''}sent ${esc(ago(info.sent))}</span></span></div>
            <label>What happened?<textarea name="what" rows="6" maxlength="5000" required placeholder="What you expected, and what happened instead."></textarea></label>
            <label>What were you doing right before? <span class="opt">optional</span><textarea name="before" rows="3" maxlength="5000" placeholder="The menu, the level, the button you pressed…"></textarea></label>
            <label>Pictures <span class="opt">optional, up to 4</span><input name="pictures[]" type="file" accept="image/png,image/jpeg,image/webp" multiple class="pics-input"></label>
            <div class="pics-preview"></div>
            <label>How can we reach you? <span class="opt">optional</span><input name="contact" maxlength="200" placeholder="Email or Discord name, if you'd like a reply"></label>
            <div class="form-row"><button class="btn primary" type="submit">Send the report</button><span class="status" role="status"></span></div>
          </form>`;
        const f = box.querySelector('form');
        const st = f.querySelector('.status');
        const pics = f.querySelector('.pics-input');
        const pv = f.querySelector('.pics-preview');
        let urls = [];
        pics.addEventListener('change', () => {
          urls.forEach((u) => URL.revokeObjectURL(u));
          urls = [...pics.files].slice(0, 4).map((x) => URL.createObjectURL(x));
          pv.innerHTML = urls.map((u) => `<img src="${u}" alt="">`).join('');
          st.textContent = pics.files.length > 4 ? 'Four pictures at most: only the first four go.' : ''; st.className = 'status';
        });
        f.addEventListener('submit', (e) => {
          e.preventDefault();
          const fv = (n) => f.querySelector(`[name="${n}"]`);
          if (fv('what').value.trim().length < 10) { st.textContent = 'Tell us a little more about what happened.'; st.className = 'status bad'; fv('what').focus(); return; }
          const files = [...pics.files].slice(0, 4);
          if (files.some((x) => x.size > 8 * 1048576)) { st.textContent = 'Each picture can be 8 MB at most.'; st.className = 'status bad'; return; }
          if (files.some((x) => !/^image\/(png|jpeg|webp)$/.test(x.type))) { st.textContent = 'Only pictures can be sent: PNG, JPEG or WebP.'; st.className = 'status bad'; return; }
          const fd = new FormData();
          fd.append('a', 'finish'); fd.append('code', c);
          fd.append('what', fv('what').value); fd.append('before', fv('before').value); fd.append('contact', fv('contact').value);
          files.forEach((x) => fd.append('pictures[]', x, x.name));
          const b = f.querySelector('button');
          b.disabled = true; st.textContent = files.length ? 'Sending the report and pictures…' : 'Sending…'; st.className = 'status';
          fetch('/api/report.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then((r) => r.json()).catch(() => ({ ok: false, error: 'That didn\'t send. Check your connection and try again.' }))
            .then((res) => {
              if (!res.ok) { b.disabled = false; st.textContent = res.error; st.className = 'status bad'; return; }
              f.innerHTML = `<div class="sent"><strong>Thanks, the report is in.</strong><p>${esc(res.message)}</p><a class="btn" href="#/">Back to all games</a></div>`;
            });
        });
      });
  }

  function notFound() {
    view.innerHTML = '<section class="intro"><h1>That page isn\'t here.</h1><p><a href="#/">Back to all games</a></p></section>';
    setBackdrop(null);
  }

  // ---- Router ---------------------------------------------------------------------
  // The site's pages are #/about, #/contact...; a game's page is its own link, /game/<ID>, so the
  // address bar always has the link that shows the game's card when it's shared.
  function current() {
    if (location.hash.length > 1) return location.hash.slice(1);
    const p = location.pathname.match(/^\/(game|soon)\/([A-Za-z0-9_-]+)\/?$/);
    // (2.0) /l/<code>: link this phone to a PS5; /r/<code>: finish a bug report.
    const q = location.pathname.match(/^\/(l|r)\/([A-Za-z0-9-]{4,12})\/?$/);
    return p ? `/${p[1]}/${p[2]}` : q ? `/${q[1]}/${q[2]}` : '/';
  }
  let routed = '';
  // (2.1) Each page shown counts once on the admin's Analytics page (no cookies; see api/hit.php).
  let firstHit = true;
  function countVisit(h) {
    try {
      const ref = firstHit ? document.referrer : '';
      firstHit = false;
      const body = JSON.stringify({ p: h || '/', r: ref });
      if (navigator.sendBeacon) navigator.sendBeacon('/api/hit.php', new Blob([body], { type: 'application/json' }));
      else fetch('/api/hit.php', { method: 'POST', body, keepalive: true, headers: { 'Content-Type': 'application/json' } }).catch(() => {});
    } catch (e) { /* counting never gets in the way */ }
  }
  function route() {
    clearInterval(slideTimer);
    stopPageTimers();
    const h = current();
    countVisit(h);
    if (h === '/get') { if (!dlg.open) dlg.showModal(); if (!view.innerHTML) home(); routed = location.href; return; }
    if (dlg.open) dlg.close();
    const m = h.match(/^\/game\/(.+)$/);
    const ms = h.match(/^\/soon\/(.+)$/);
    const own = m ? byId(decodeURIComponent(m[1])) && `/game/${m[1]}` : ms ? soonById(decodeURIComponent(ms[1])) && `/soon/${ms[1]}` : '';
    const coded = h.match(/^\/(l|r)\/([A-Za-z0-9-]{4,12})$/);
    if (own && location.pathname + location.hash !== own) history.replaceState(history.state, '', own);
    else if (!own && !coded && location.pathname !== '/') history.replaceState(history.state, '', '/' + location.hash);
    routed = location.href;
    if (m) game(decodeURIComponent(m[1]));
    else if (ms) soonPage(decodeURIComponent(ms[1]));
    else if (h === '/about') about();
    else if (h === '/report') formPage('bug');
    else if (h === '/submit') submitPage();
    else if (h === '/contact') contactPage();
    else if (h === '/ids' && v2on()) idsPage();
    else if (coded && coded[1] === 'l') linkClaimPage(coded[2]);
    else if (coded) reportPage(coded[2]);
    else if (h === '/link') linkPage();
    else if (['/games', '/emulators', '/apps', '/tools'].includes(h) && v2on()) categoryPage(h.slice(1));
    else if (h === '/my-ps5') myPage();
    else home();
    const here = h === '/about' ? 'about' : ['/contact', '/report', '/submit', '/ids'].includes(h) ? 'contact' : ['/link', '/my-ps5'].includes(h) || (coded && coded[1] === 'l') ? 'my' : 'home';
    document.querySelectorAll('[data-nav]').forEach((n) => n.toggleAttribute('aria-current', n.dataset.nav === here));
    window.scrollTo({ top: 0, behavior: reduced ? 'auto' : 'smooth' });
    view.classList.remove('enter'); void view.offsetWidth; view.classList.add('enter');
  }

  dlg.addEventListener('close', () => { if (location.hash === '#/get') history.back(); });
  dlg.addEventListener('click', (e) => { if (e.target === dlg || e.target.closest('[data-close]')) dlg.close(); });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    const el = document.querySelector(b.dataset.copy);
    navigator.clipboard && navigator.clipboard.writeText(el.textContent.trim()).then(() => { b.textContent = 'Copied'; setTimeout(() => (b.textContent = 'Copy'), 1500); });
  });

  // (2.0) At most a moment for the charts and this browser's link: the page doesn't wait longer.
  const atMost = (p, ms) => Promise.race([p, new Promise((ok) => setTimeout(() => ok(null), ms))]);
  const chartsLoad = atMost(fetch('/api/charts.php').then((r) => (r.ok ? r.json() : null)).catch(() => null), 2500);
  const meLoad = atMost(refreshMe(), 2000);
  // Only the admin's own browser gets a yes here (its login cookie only goes to /admin).
  const adminLoad = atMost(fetch('/admin/me.php', { cache: 'no-store', credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : null)).catch(() => null), 2000);
  fetch('/catalog.json', { cache: 'no-cache' })
    .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then((c) => Promise.all([c, chartsLoad, meLoad, adminLoad]))
    .then(([c, ch, , ad]) => {
      charts = ch && ch.ok ? ch : null;
      siteV2 = !!(c.site && c.site.v2 === true);
      admin = !!(ad && ad.admin === true);
      // Games that need Recomp Store 1.0.3 or newer are listed apart (older stores don't read them),
      // and (2.0) what only 2.0 lists, apart again.
      raw = c;
      cat = Object.assign({}, c);
      setLists();
      const s = cat.site || {};
      if (admin && !siteV2) {
        const rib = document.createElement('a');
        rib.className = 'preview-ribbon';
        rib.href = '/admin/settings.php#site-v2';
        rib.innerHTML = '<strong>Preview</strong> Only you see 2.0';
        rib.title = 'Visitors see the site without 2.0. Change it in the admin\'s Settings.';
        document.body.appendChild(rib);
      }
      // (2.2) What's new in the store, as the admin wrote it: under the steps in Get Recomp Store.
      const wn = cat.whats_new;
      if (wn && typeof wn.body === 'string' && wn.body.trim() && !document.getElementById('whats-new')) {
        const d = document.createElement('details');
        d.className = 'manual whats-new';
        d.id = 'whats-new';
        d.innerHTML = `<summary>${esc(wn.title || "What's new in " + (wn.version || ''))}</summary><div class="wn-body">${notesBlocks(wn.body)}</div>`;
        const man = dlg.querySelector('details.manual');
        man.parentNode.insertBefore(d, man);
      }
      document.getElementById('payload-slot').innerHTML = s.payload
        ? `<a class="btn primary" href="${esc(s.payload)}">Download RecompStore-Installer.elf</a>`
        : '<em class="soon">The installer is on its way with the first release.</em>';
      document.getElementById('foot-links').innerHTML = [
        s.discord ? `<a href="${esc(s.discord)}" target="_blank" rel="noopener">Discord</a>` : '',
        s.kofi ? `<a href="${esc(s.kofi)}" target="_blank" rel="noopener">Ko-fi</a>` : '',
        '<a href="https://github.com/elripalda/RecompStore" target="_blank" rel="noopener">GitHub</a>',
        '<a href="#/report">Report a bug</a>',
        '<a href="#/submit">Submit to the store</a>',
        '<a href="#/ids" data-foot-ids>Title IDs</a>',
        '<a href="#/contact">Contact</a>',
        '<a href="#/about">About</a>',
      ].filter(Boolean).join('');
      setLists();
      // A link or Back can bring both events for one move: the page is drawn once.
      const moved = () => { if (location.href !== routed) route(); };
      window.addEventListener('hashchange', moved);
      window.addEventListener('popstate', moved);
      route();
    })
    .catch(() => { view.innerHTML = '<section class="intro"><h1>The catalog didn\'t load.</h1><p>Refresh the page in a moment.</p></section>'; });
})();
