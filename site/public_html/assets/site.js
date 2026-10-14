// Recomp Store website. Everything shown comes from /catalog.json, which the admin publishes.
(function () {
  'use strict';
  const view = document.getElementById('view');
  const dlg = document.getElementById('get');
  const backdrop = document.getElementById('backdrop-img');
  let cat = null;
  let slideTimer = null;

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
  function tileHTML(a, soon) {
    const href = soon ? `#/soon/${encodeURIComponent(a.id)}` : `#/game/${encodeURIComponent(a.id)}`;
    const sub = soon ? (a.date || 'Coming soon') : (a.kind === 'original' ? 'Recomp Store' : esc(a.author)) + (a.version ? ' · ' + esc(a.version) : '');
    return `
      <a class="tile" href="${href}" data-id="${esc(a.id)}"${soon ? ' data-soon' : ''}>
        <span class="tile-art">${iconHTML(a, 256)}${soon ? '<span class="badge-soon">Soon</span>' : ''}</span>
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
      const soon = sec.type === 'soon';
      const items = soon ? (cat.soon || []) : sec.all ? cat.apps : (sec.games || []).map(byId).filter(Boolean);
      if (sec.type !== 'games' && !soon) return '';
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

  function home() {
    const featured = (cat.featured || []).map(byId).filter(Boolean);
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
      ${sectionsHTML()}
      <section class="help">
        <a class="glass help-card" href="#/report">
          <span class="kicker">Found a bug?</span>
          <strong>Report it</strong>
          <span>Pick the game and tell us what happened. It goes straight to the developer.</span>
        </a>
        <a class="glass help-card" href="#/submit">
          <span class="kicker">Made a recomp?</span>
          <strong>Submit your port</strong>
          <span>Send us your PS5 port of a recompiled game and we'll get in touch about listing it.</span>
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
            <div class="chips"><span class="tag cyan">Coming soon</span>${a.date ? `<span class="tag">${esc(a.date)}</span>` : ''}${a.author ? `<span class="tag">By ${esc(a.author)}</span>` : ''}</div>
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

  // ---- Game page ------------------------------------------------------------------
  function game(id) {
    const a = byId(id);
    if (!a) { notFound(); return; }
    const repoUrl = a.source && a.source.type === 'github' && /^[\w.-]+\/[\w.-]+$/.test(a.source.repo || '') ? `https://github.com/${a.source.repo}` : '';
    const rows = [
      ['Title ID', a.id], ['Version', a.version], ['Download', a.size], ['You bring', 'Your ' + (a.needs || 'own game')],
      ['Installs to', '/data/homebrew/' + a.id], ['Needs', a.minStore ? 'Recomp Store ' + a.minStore + ' or newer' : ''],
    ].filter((r) => r[1]);
    view.innerHTML = `
      <article class="game">
        <a class="back" href="#/">← All games</a>
        <div class="game-grid">
          <div class="game-info">
            <div class="chips">${tagFor(a)}<span class="tag">${esc(a.id)}</span></div>
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
            <a class="btn primary" href="#/get" data-get>Install with Recomp Store</a>
            ${a.minStore ? `<p class="note needs">Needs Recomp Store ${esc(a.minStore)} or newer. On your PS5, update the store from Latest Updates first.</p>` : ''}
            <a class="btn" id="dl" href="${esc(a.url || (repoUrl ? repoUrl + '/releases/latest' : '#'))}" target="_blank" rel="noopener">${repoUrl && a.source.inside ? "The developer's release" : 'Download the zip'}</a>
            <dl>${rows.map((r) => `<div><dt>${esc(r[0])}</dt><dd${r[0] === 'Version' ? ' data-version' : ''}>${esc(r[1])}</dd></div>`).join('')}</dl>
            ${repoUrl ? `<a class="src" href="${esc(repoUrl)}" target="_blank" rel="noopener">Source on GitHub ↗</a>` : ''}
            <p class="note">Updates through Recomp Store replace only the app. Your game install, saves, DLC and mods stay where they are.</p>
          </aside>
        </div>
      </article>`;
    setBackdrop(a);
    resolveDownload(a);
    const notes = document.getElementById('notes');
    if (notes && repoUrl) notes.addEventListener('toggle', () => { if (notes.open) loadNotes(a.source.repo, notes.querySelector('[data-notes]')); }, { once: false });
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
        <a class="back" href="#/">← All games</a>
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
    const form = view.querySelector('form');
    const status = form.querySelector('.status');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      data.kind = kind;
      const need = bug ? [['app', 'Choose the game the bug is in.'], ['body', 'Tell us what happened.']]
                       : [['name', 'Add your name or handle.'], ['email', 'Add your email.'], ['repo', 'Add the GitHub repository link.'], ['body', 'Tell us about the port.']];
      for (const [k, msg] of need) if (!String(data[k] || '').trim()) { status.textContent = msg; status.className = 'status bad'; form.querySelector(`[name="${k}"]`).focus(); return; }
      const btn = form.querySelector('button');
      btn.disabled = true;
      status.textContent = 'Sending…'; status.className = 'status';
      fetch('/api/inbox.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
        .then((r) => r.json().catch(() => ({ ok: false })))
        .then((res) => {
          if (res.ok) {
            form.innerHTML = `<div class="sent"><strong>${bug ? 'Thanks, the report is in.' : 'Thanks, it\'s on its way.'}</strong><p>${bug ? 'It went straight to the developer.' : 'We\'ll be in touch at the email you gave.'}</p><a class="btn" href="#/">Back to all games</a></div>`;
          } else {
            status.textContent = res.error || 'That didn\'t send. Try again in a moment.'; status.className = 'status bad'; btn.disabled = false;
          }
        })
        .catch(() => { status.textContent = 'That didn\'t send. Check your connection and try again.'; status.className = 'status bad'; btn.disabled = false; });
    });
  }

  function notFound() {
    view.innerHTML = '<section class="intro"><h1>That page isn\'t here.</h1><p><a href="#/">Back to all games</a></p></section>';
    setBackdrop(null);
  }

  // ---- Router ---------------------------------------------------------------------
  function route() {
    clearInterval(slideTimer);
    const h = location.hash.replace(/^#/, '') || '/';
    if (h === '/get') { if (!dlg.open) dlg.showModal(); if (!view.innerHTML) home(); return; }
    if (dlg.open) dlg.close();
    const m = h.match(/^\/game\/(.+)$/);
    const ms = h.match(/^\/soon\/(.+)$/);
    if (m) game(decodeURIComponent(m[1]));
    else if (ms) soonPage(decodeURIComponent(ms[1]));
    else if (h === '/about') about();
    else if (h === '/report') formPage('bug');
    else if (h === '/submit') formPage('submit');
    else home();
    const here = h === '/about' ? 'about' : h === '/report' ? 'report' : h === '/submit' ? 'submit' : 'home';
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

  fetch('/catalog.json', { cache: 'no-cache' })
    .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then((c) => {
      cat = c;
      // Games that need Recomp Store 1.0.3 or newer are listed apart (older stores don't read them).
      cat.apps = (c.apps || []).concat(c.newerStoreApps || []);
      cat.apps.sort((x, y) => (x.order ?? 1e9) - (y.order ?? 1e9)); // the admin's order, both lists together
      cat.soon = (c.comingSoon || []).slice().sort((x, y) => (x.order ?? 1e9) - (y.order ?? 1e9));
      const s = cat.site || {};
      document.getElementById('payload-slot').innerHTML = s.payload
        ? `<a class="btn primary" href="${esc(s.payload)}">Download RecompStore-Installer.elf</a>`
        : '<em class="soon">The installer is on its way with the first release.</em>';
      document.getElementById('foot-links').innerHTML = [
        s.discord ? `<a href="${esc(s.discord)}" target="_blank" rel="noopener">Discord</a>` : '',
        s.kofi ? `<a href="${esc(s.kofi)}" target="_blank" rel="noopener">Ko-fi</a>` : '',
        '<a href="https://github.com/elripalda/RecompStore" target="_blank" rel="noopener">GitHub</a>',
        '<a href="#/report">Report a bug</a>',
        '<a href="#/submit">Submit your recomp</a>',
        '<a href="#/about">About</a>',
      ].filter(Boolean).join('');
      window.addEventListener('hashchange', route);
      route();
    })
    .catch(() => { view.innerHTML = '<section class="intro"><h1>The catalog didn\'t load.</h1><p>Refresh the page in a moment.</p></section>'; });
})();
