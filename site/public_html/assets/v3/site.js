// RecompStore website. Everything shown comes from /catalog.json, which the admin publishes.
(function () {
  'use strict';

  // ---- (2.7) The boot, once a visit, as the store's: the logo builds itself on the aurora (arrow,
  // bowl, leg), the name comes in under it, then the logo flies up into the bar as the page shows.
  // A click skips it; it doesn't play with reduced motion, or for search engines' previews.
  (function boot() {
    try {
      if (sessionStorage.getItem('rs-booted')) return;
      sessionStorage.setItem('rs-booted', '1');
    } catch (e) { return; }
    if (!window.matchMedia || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (/bot|crawl|spider|preview/i.test(navigator.userAgent || '')) return;
    const el = document.createElement('div');
    el.className = 'boot';
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = '<div class="boot-bg"></div><div class="boot-in"><svg class="boot-mark" viewBox="0 0 256 256" width="200" height="200">' +
      '<defs><linearGradient id="boot-g" x1="0" y1="38" x2="0" y2="218" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#34f2e8"/><stop offset="1" stop-color="#377bb6"/></linearGradient></defs>' +
      '<g fill="url(#boot-g)" transform="translate(4 -2)"><path d="M40 40H88V168H116L64 220L12 168H40Z"/><path d="M104 40H172L216 84V116L180 152H104V116H164L180 100V92L164 76H104Z"/><path d="M132 168H184L236 220H184Z"/></g></svg>' +
      '<div class="boot-word">Recomp<b>' + (/(^|\.)recomplauncher\./i.test(location.hostname) ? 'Launcher' : 'Store') + '</b></div></div>';
    document.documentElement.classList.add('booting');
    document.body.appendChild(el);
    let gone = false;
    const fly = () => {
      if (gone) return;
      gone = true;
      const mark = el.querySelector('.boot-mark');
      const target = document.querySelector('.top .brand img');
      if (mark && target) {
        const a = mark.getBoundingClientRect(), b = target.getBoundingClientRect();
        if (a.width > 0 && b.width > 0)
          mark.style.transform = `translate(${b.left - a.left}px, ${b.top - a.top}px) scale(${b.width / a.width})`;
      }
      el.classList.add('fly');
      setTimeout(() => { document.documentElement.classList.remove('booting'); el.remove(); }, 820);
    };
    el.addEventListener('click', fly);
    setTimeout(fly, 1750);
  })();

  // ---- (2.6) The site in the visitor's language ----------------------------------------------
  // Every English text is written here and passed through t(), tf() or tn(); assets/i18n/<code>.json
  // holds a language's {"English": "translation"} (tools/make-site-i18n.py lists what's to translate).
  // What the admin writes and what comes from the catalog stays as it is.
  const LANGS = [['en', 'English (US)'], ['en-GB', 'English (UK)'], ['es', 'Español (España)'], ['es-419', 'Español (Latinoamérica)'],
    ['fr', 'Français (France)'], ['fr-CA', 'Français (Canada)'], ['pt', 'Português (Portugal)'], ['pt-BR', 'Português (Brasil)'],
    ['it', 'Italiano'], ['de', 'Deutsch'], ['nl', 'Nederlands'], ['pl', 'Polski'], ['tr', 'Türkçe'], ['ru', 'Русский'], ['uk', 'Українська'],
    ['vi', 'Tiếng Việt'], ['id', 'Bahasa Indonesia'], ['th', 'ไทย'], ['ar', 'العربية'], ['ja', '日本語'], ['zh-Hans', '简体中文'], ['zh-Hant', '繁體中文'], ['ko', '한국어']];
  const CODES = LANGS.map((l) => l[0]);
  // A regional language shows its base language's text where it has none of its own.
  const BASE = { 'en-GB': 'en', 'es-419': 'es', 'fr-CA': 'fr', 'pt-BR': 'pt' };
  // The translations go with this script's version (site.js?v=...), so a new version fetches new ones.
  const VER = ((document.currentScript && document.currentScript.src.match(/[?&]v=([\w.-]+)/)) || [])[1] || '';
  let lang = 'en';
  let dict = {};
  const N_ = (s) => s; // English that t() is given later (a table of labels): the tool finds it
  const t = (s) => (Object.prototype.hasOwnProperty.call(dict, s) && dict[s] ? dict[s] : s);
  // Arabic's digits stay 0-9, as in the store.
  const locale = () => (lang === 'ar' ? 'ar-u-nu-latn' : lang);
  const fmtNum = (n, digits) => {
    try { return new Intl.NumberFormat(locale(), digits == null ? {} : { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(n); }
    catch (e) { return digits == null ? String(n) : Number(n).toFixed(digits); }
  };
  // A count's form: Polish, Russian and Ukrainian "one|few|many" (21 takes "one"), Arabic "two|few|many|other".
  function pickForm(text, n) {
    if (text.indexOf('|') < 0 || n == null) return text;
    const f = text.split('|'), a = Math.abs(Number(n)) || 0, d = a % 10, h = a % 100, base = BASE[lang] || lang;
    let i = 0;
    if (base === 'ru' || base === 'uk') i = d === 1 && h !== 11 ? 0 : d >= 2 && d <= 4 && (h < 12 || h > 14) ? 1 : 2;
    else if (base === 'pl') i = a === 1 ? 0 : d >= 2 && d <= 4 && (h < 12 || h > 14) ? 1 : 2;
    else if (base === 'ar' && f.length === 4) i = a === 2 ? 0 : h >= 3 && h <= 10 ? 1 : h >= 11 && h <= 99 ? 2 : 3;
    return f[Math.min(i, f.length - 1)];
  }
  // tf('Updated {date}', {date: x}): translated, then each {name} filled (numbers in the language's way).
  // Values go in as they are: in HTML, escape them first.
  const tf = (text, v) => pickForm(t(text), v && v.n).replace(/\{(\w+)\}/g, (m, k) => (v && k in v ? (typeof v[k] === 'number' ? fmtNum(v[k]) : String(v[k])) : m));
  // tn(3, '1 app', '{n} apps')
  const tn = (n, one, many) => tf(n === 1 ? one : many, { n });
  const rtl = () => lang === 'ar';
  const backArrow = () => (rtl() ? '→' : '←');
  const within = (p, ms) => Promise.race([p, new Promise((ok) => setTimeout(() => ok(null), ms))]);

  // The language: the visitor's choice, else the browser's, else English.
  function matchLang(tag) {
    const parts = String(tag || '').toLowerCase().split('-');
    const base = parts[0];
    const region = parts.slice(1).find((x) => x.length === 2 || /^\d{3}$/.test(x)) || '';
    const script = parts.slice(1).find((x) => x.length === 4) || '';
    if (base === 'en') return ['gb', 'ie', 'au', 'nz'].includes(region) ? 'en-GB' : 'en';
    if (base === 'es') return !region || region === 'es' ? 'es' : 'es-419';
    if (base === 'fr') return region === 'ca' ? 'fr-CA' : 'fr';
    if (base === 'pt') return region === 'br' ? 'pt-BR' : 'pt';
    if (base === 'zh') return script === 'hant' || ['tw', 'hk', 'mo'].includes(region) ? 'zh-Hant' : 'zh-Hans';
    return CODES.includes(base) ? base : '';
  }
  function pickLang() {
    // (2.6) A link with ?lang=de (search engines' links to each language) picks it for this visit.
    const asked = matchLang(new URLSearchParams(location.search).get('lang') || '');
    if (asked) return asked;
    let saved = '';
    try { saved = localStorage.getItem('lang') || ''; } catch (e) { /* storage off: the browser's language */ }
    if (CODES.includes(saved)) return saved;
    const list = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language || 'en'];
    for (const tag of list) { const m = matchLang(tag); if (m) return m; }
    return 'en';
  }
  function setDir() {
    document.documentElement.lang = lang;
    document.documentElement.dir = rtl() ? 'rtl' : 'ltr';
  }
  // A language's text (its base language's first), at most 2.5 s; else English.
  function loadLang(code) {
    const files = code === 'en' ? [] : BASE[code] && BASE[code] !== 'en' ? [BASE[code], code] : [code];
    const get = (c) => fetch(`/assets/i18n/${c}.json${VER ? '?v=' + encodeURIComponent(VER) : ''}`)
      .then((r) => (r.ok ? r.json() : {})).catch(() => ({}));
    return within(Promise.all(files.map(get)).then((ds) => Object.assign({}, ...ds.map((d) => (d && typeof d === 'object' ? d : {})))), 2500)
      .then((d) => {
        lang = d ? code : 'en';
        dict = d || {};
        setDir();
      });
  }
  // The page's own text (index.html): data-i18n="English" is the element's text; data-i18n-html, its
  // markup (kept from the page as it came); data-i18n-attr="aria-label title", those attributes.
  const pageText = new WeakMap();
  const squash = (x) => String(x).replace(/\s+/g, ' ').trim();
  function applyStatic() {
    document.querySelectorAll('[data-i18n]').forEach((el) => { el.textContent = t(el.getAttribute('data-i18n')); });
    document.querySelectorAll('[data-i18n-html]').forEach((el) => {
      if (!pageText.has(el)) pageText.set(el, squash(el.innerHTML));
      el.innerHTML = t(pageText.get(el));
    });
    document.querySelectorAll('[data-i18n-attr]').forEach((el) => {
      if (!pageText.has(el)) {
        const keep = {};
        el.getAttribute('data-i18n-attr').split(/\s+/).filter(Boolean).forEach((a) => { keep[a] = el.getAttribute(a) || ''; });
        pageText.set(el, keep);
      }
      const keep = pageText.get(el);
      Object.keys(keep).forEach((a) => el.setAttribute(a, t(keep[a])));
    });
  }
  const GLOBE = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.4 2.4 3.5 5.3 3.5 8.5s-1.1 6.1-3.5 8.5c-2.4-2.4-3.5-5.3-3.5-8.5s1.1-6.1 3.5-8.5z"/></svg>';
  // The language: a select in the footer and the menu, and (2.6) a globe in the header with the list.
  const langSelect = () => `<label class="lang-pick">${GLOBE}<span>${t('Language')}</span>
      <select>${LANGS.map(([c, n]) => `<option value="${c}" lang="${c}"${c === lang ? ' selected' : ''}>${n}</option>`).join('')}</select></label>`;
  const shortCode = (c) => ({ 'en-GB': 'EN', 'es-419': 'ES', 'fr-CA': 'FR', 'pt-BR': 'PT', 'zh-Hans': '中文', 'zh-Hant': '中文' }[c] || c.toUpperCase());
  function langPicker() {
    ['lang-row', 'drawer-lang'].forEach((id) => {
      const row = document.getElementById(id);
      if (!row) return;
      row.innerHTML = langSelect();
      row.querySelector('select').addEventListener('change', (e) => setLang(e.target.value));
    });
    // (2.9) RecompLauncher's menu also has its AI switch.
    const drawer = document.getElementById('drawer-lang');
    if (drawer && LAUNCHER) {
      drawer.insertAdjacentHTML('afterbegin', aiSwitchHTML('drawer') + osPickerHTML('drawer'));
      wireAiSwitch(drawer);
      wireOs(drawer);
    }
    // (2.9) The header's settings: a gear, with the language list and (on RecompLauncher) the AI switch.
    const chip = document.getElementById('lang-chip');
    if (!chip) return;
    chip.innerHTML = `<button type="button" class="icon-btn lang-btn set-btn" aria-haspopup="true" aria-expanded="false" aria-label="${t('Settings')}" title="${t('Settings')}">${GEAR}</button>
      <div class="lang-menu set-menu" hidden>
        ${LAUNCHER ? aiSwitchHTML('menu') + osPickerHTML('menu') : ''}
        <p class="set-menu-head">${GLOBE}${t('Language')}</p>
        <ul role="menu">${LANGS.map(([c, n]) => `<li role="none"><button type="button" role="menuitemradio" aria-checked="${c === lang}" lang="${c}" data-lang="${c}">${n}</button></li>`).join('')}</ul>
      </div>`;
    const btn = chip.querySelector('.lang-btn'), menu = chip.querySelector('.lang-menu');
    if (LAUNCHER) { wireAiSwitch(menu); wireOs(menu); }
    const close = () => { menu.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      const opening = menu.hidden;
      menu.hidden = !opening;
      btn.setAttribute('aria-expanded', String(opening));
      if (opening) { const on = menu.querySelector('[aria-checked="true"]'); if (on) { on.focus({ preventScroll: true }); on.scrollIntoView({ block: 'nearest' }); } }
    });
    menu.addEventListener('click', (e) => { const b = e.target.closest('[data-lang]'); if (b) { close(); setLang(b.dataset.lang); } });
    menu.addEventListener('keydown', (e) => {
      const items = [...menu.querySelectorAll('[data-lang]')];
      const at = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { e.preventDefault(); (items[at + 1] || items[0]).focus(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); (items[at - 1] || items[items.length - 1]).focus(); }
      else if (e.key === 'Escape') { e.stopPropagation(); close(); btn.focus(); }
    });
    fitHeader();
  }
  const GEAR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
  // (2.9) The AI switch, wherever it shows (the header's settings, the menu, the Settings page): one
  // choice, and every copy of the switch follows it.
  const aiSwitchHTML = (where) => `<label class="set-ai set-ai-${where}"><span class="set-ai-words"><strong>${t('Hide AI-assisted recomps')}</strong></span>
    <span class="switch"><input type="checkbox" data-hide-ai${hideAI ? ' checked' : ''}><span class="switch-track" aria-hidden="true"></span></span></label>`;
  function setHideAI(v) {
    hideAI = !!v; aiPrefSet = true;
    try { localStorage.setItem(AI_PREF, hideAI ? '1' : '0'); } catch (e) { /* only this visit */ }
    document.querySelectorAll('input[data-hide-ai]').forEach((x) => { x.checked = hideAI; });
    if (typeof pcFilter === 'function') pcFilter();
    if (cat) route(true); // the page again, without what's hidden now
  }
  // (2.9) Which system's recomps to show. Each system's logo, once its file is in assets/os/<key>.png.
  const OS_LOGOS = ['windows', 'macos', 'linux'];
  const osIcon = (k) => (OS_LOGOS.includes(k) ? `<span class="ico-m os-ico" style="--m:url('/assets/os/${k}.png?v=1')" aria-hidden="true"></span>` : '');
  const OS_CHOICES = () => [['', t('All')], ['windows', 'Windows'], ['macos', 'macOS'], ['linux', 'Linux']];
  const osPickerHTML = (where) => `<div class="set-os set-os-${where}"><span class="set-os-label">${t('Show recomps for')}</span>
    <div class="ids2-tabs set-os-tabs" role="group" aria-label="${t('Show recomps for')}">${OS_CHOICES().map(([k, n]) => `<button type="button" data-os="${k}" class="${onlyOS === k ? 'on' : ''}" aria-pressed="${onlyOS === k}" title="${esc(n)}">${osIcon(k)}<span class="os-t${k ? '' : ' os-all'}">${esc(n)}</span></button>`).join('')}</div></div>`;
  function setOS(k, quiet) {
    onlyOS = ['windows', 'macos', 'linux'].includes(k) ? k : '';
    try { if (onlyOS) localStorage.setItem(OS_PREF, onlyOS); else localStorage.removeItem(OS_PREF); } catch (e) { /* only this visit */ }
    document.querySelectorAll('[data-os]').forEach((b) => { const on = b.dataset.os === onlyOS; b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); });
    if (typeof pcFilter === 'function') pcFilter();
    if (!quiet && cat) route(true);
  }
  const wireOs = (root) => root.querySelectorAll('[data-os]').forEach((b) => b.addEventListener('click', (e) => { e.stopPropagation(); setOS(b.dataset.os); }));
  const wireAiSwitch = (root) => root.querySelectorAll('input[data-hide-ai]').forEach((x) => x.addEventListener('change', () => setHideAI(x.checked)));
  // A click anywhere else closes the header's settings.
  document.addEventListener('click', (e) => {
    const chip = document.getElementById('lang-chip');
    const menu = chip && chip.querySelector('.lang-menu');
    if (!menu || menu.hidden || chip.contains(e.target)) return;
    menu.hidden = true;
    chip.querySelector('.lang-btn').setAttribute('aria-expanded', 'false');
  });
  let relang = () => {}; // once the catalog is in: the page drawn again in the new language
  function setLang(code) {
    if (!CODES.includes(code)) return;
    try { localStorage.setItem('lang', code); } catch (e) { /* only for this visit, then */ }
    // A ?lang= in the address gives way to the choice made here.
    const q = new URLSearchParams(location.search);
    if (q.has('lang')) { q.delete('lang'); history.replaceState(history.state, '', location.pathname + (q.toString() ? '?' + q : '') + location.hash); routed = location.href; }
    loadLang(code).then(() => { applyStatic(); langPicker(); relang(); });
  }
  const langLoad = loadLang(pickLang()).then(() => { applyStatic(); langPicker(); });
  const view = document.getElementById('view');
  // (2.9) RecompLauncher's Settings (/settings): hide AI-assisted recomps everywhere on the PC side.
  // Kept in this browser (taken along as ?noai= when the PC side is opened from RecompStore).
  const AI_PREF = 'rs-hide-ai';
  let hideAI = false, aiPrefSet = false;
  try { const v = localStorage.getItem(AI_PREF); aiPrefSet = v === '1' || v === '0'; hideAI = v === '1'; } catch (e) { /* storage off: everything shows */ }
  (() => {
    const q = new URLSearchParams(location.search);
    if (!q.has('noai')) return;
    hideAI = q.get('noai') === '1'; aiPrefSet = true;
    try { localStorage.setItem(AI_PREF, hideAI ? '1' : '0'); } catch (e) { /* only this visit */ }
    q.delete('noai');
    history.replaceState(history.state, '', location.pathname + (q.toString() ? '?' + q : '') + location.hash);
  })();
  const isAI = (a) => !!(a && a.ai);
  // (2.9) RecompLauncher: show only recomps with a build for one system ('' every system). In this browser.
  const OS_PREF = 'rs-os';
  let onlyOS = '';
  try { const v = localStorage.getItem(OS_PREF) || ''; onlyOS = ['windows', 'macos', 'linux'].includes(v) ? v : ''; } catch (e) { /* every system */ }
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
    ? '<span class="tag cyan">RecompStore</span>'
    : `<span class="tag">${tf('By {author}', { author: esc(a.author) })}</span>`;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Catalog values that go inside style="": numbers as numbers, a picture's URL with nothing
  // that could end the url() or the attribute.
  const num = (v, d, lo, hi) => { const n = Number(v); return Number.isFinite(n) ? Math.min(hi, Math.max(lo, n)) : d; };
  const frame = (b) => { b = b || {}; return { x: num(b.x, 50, 0, 100), y: num(b.y, 30, 0, 100), z: num(b.zoom, 100, 100, 400) / 100 }; };
  const cssUrl = (u) => esc(String(u || '').replace(/[()'"\\\s\x00-\x1f]/g, (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase().padStart(2, '0')));

  // (2.6) Two pictures that crossfade: the next one shows once it's loaded, never a dark gap between.
  const backs = [backdrop, document.querySelector('.backdrop-b') || backdrop];
  let backOn = 0, backWant = '';
  function setBackdrop(a) {
    const src = a ? art(a) : '';
    backWant = src;
    const cur = backs[backOn], next = backs[1 - backOn];
    if (!src) { backs.forEach((b) => b.classList.remove('on')); return; }
    if (cur.getAttribute('src') === src) { cur.classList.add('on'); if (next !== cur) next.classList.remove('on'); return; }
    const show = () => {
      if (backWant !== src) return;
      next.classList.add('on');
      if (next !== cur) cur.classList.remove('on');
      backOn = backs.indexOf(next);
    };
    next.onload = null;
    if (next.getAttribute('src') === src && next.complete && next.naturalWidth) { show(); return; }
    next.onload = show;
    next.src = src;
  }
  // A game's big picture, fetched as soon as you point at it: its page's backdrop is then instant.
  const warmed = new Set();
  function warm(a) {
    const src = a && art(a);
    if (!src || warmed.has(src)) return;
    warmed.add(src);
    const img = new Image();
    img.decoding = 'async';
    img.src = src;
  }

  function iconHTML(a, size) {
    return a.icon
      ? `<img class="ico" src="${esc(a.icon)}" alt="" width="${size}" height="${size}" loading="lazy" decoding="async">`
      : `<span class="ico mono">${esc((a.name || '?').slice(0, 2))}</span>`;
  }

  // ---- Home: the featured banner, then the sections the admin arranged (as in the store) --
  const DEFAULT_SECTIONS = [
    { type: 'games', title: N_('Latest games'), layout: 'grid', all: true },
    { type: 'soon', title: N_('Coming soon'), layout: 'row' },
  ];
  function tileHTML(a, soon, rank) {
    const href = soon ? `/soon/${encodeURIComponent(a.id)}` : `/game/${encodeURIComponent(a.id)}`;
    const sub = soon ? (a.date || t('Coming soon')) : [whoHTML(a), a.version ? esc(a.version) : ''].filter(Boolean).join(' · ');
    return `
      <a class="tile" href="${href}" data-id="${esc(a.id)}"${soon ? ' data-soon' : ''}>
        <span class="tile-art">${iconHTML(a, 256)}${soon ? `<span class="badge-soon">${t('Soon')}</span>` : ''}${rank ? `<span class="rank">${rank}</span>` : ''}</span>
        <span class="tile-name" dir="auto">${esc(a.name)}</span>
        <span class="tile-sub" dir="auto">${soon ? esc(sub) : sub}</span>
      </a>`;
  }
  function wideHTML(a, soon) {
    const href = soon ? `/soon/${encodeURIComponent(a.id)}` : `/game/${encodeURIComponent(a.id)}`;
    const b = frame(a.banner);
    return `
      <a class="wide" href="${href}" data-id="${esc(a.id)}"${soon ? ' data-soon' : ''}>
        <span class="wide-art">${art(a)
          ? `<img src="${esc(art(a))}" alt="" loading="lazy" style="object-position:${b.x}% ${b.y}%;transform-origin:${b.x}% ${b.y}%;transform:scale(${b.z})">${a.icon ? `<img class="wide-icon" src="${esc(a.icon)}" alt="" loading="lazy">` : ''}`
          : iconHTML(a, 256)}${soon ? `<span class="badge-soon">${t('Soon')}</span>` : ''}</span>
        <span class="tile-name" dir="auto">${esc(a.name)}</span>
        <span class="tile-sub" dir="auto">${soon ? esc(a.date || t('Coming soon')) : whoHTML(a)}</span>
      </a>`;
  }
  function spotlightHTML(a, kicker) {
    const b = frame(a.banner);
    return `
      <section class="slider still" aria-label="${esc(kicker)}" data-accent="${esc(a.id)}">
        <a class="slide on" href="/game/${encodeURIComponent(a.id)}">
          <span class="slide-blur" style="background-image:url('${cssUrl(art(a))}')"></span>
          <span class="slide-art"><img src="${esc(art(a))}" alt="" loading="lazy" style="object-position:${b.x}% ${b.y}%;transform-origin:${b.x}% ${b.y}%;--z:${b.z}"></span>
          <span class="slide-glass">
            <span class="kicker">${esc(kicker)}</span>
            ${a.logo && a.logo.url
              ? `<img class="slide-logo" src="${esc(a.logo.url)}" alt="${esc(a.name)}" style="--s:${(a.logo.banner && a.logo.banner.scale ? a.logo.banner.scale : 100) / 100}">`
              : `<span class="slide-name" dir="auto">${esc(a.name)}</span>`}
            <span class="slide-blurb" dir="auto">${esc(a.blurb || a.description)}</span>
          </span>
        </a>
      </section>`;
  }
  // Each section, as its own piece of HTML (the home page puts bands between them). data-accent: the
  // app whose colour tints the page while the section is in front of you.
  function sectionsHTML() {
    const acc = (items) => (items[0] ? ` data-accent="${esc(items[0].id)}"` : '');
    const list = cat.discover && Array.isArray(cat.discover.sections) && cat.discover.sections.length ? cat.discover.sections : DEFAULT_SECTIONS;
    return list.map((sec) => {
      if (sec.type === 'spotlight') {
        const a = byId(sec.game);
        return a ? spotlightHTML(a, sec.title ? t(sec.title) : t('Spotlight')) : '';
      }
      if (sec.type === 'developer') {
        // (2.7.3) Developer spotlight: who they are, and their apps.
        const d = devBySlug(sec.dev);
        const items = d ? cat.apps.filter((x) => x.dev === d.slug) : [];
        if (!d || !items.length) return '';
        const lead = items.find((x) => art(x));
        return `<section class="games dev-spot"${acc(items)}>
          <div class="glass dev-spot-card">
            ${lead ? `<span class="dev-spot-art" style="background-image:url('${cssUrl(art(lead))}')"></span>` : ''}
            <div class="dev-spot-who">${devPicHTML(d, 88)}<div>
              <span class="kicker">${esc(sec.title ? t(sec.title) : t('Developer spotlight'))}</span>
              <h2><bdi>${esc(d.name)}</bdi>${d.verified ? tick() : ''}</h2>
              ${d.verified ? `<span class="verified-pill">${tick()}${t('Verified developer')}</span>` : ''}
              ${d.about ? `<p dir="auto">${esc(d.about)}</p>` : ''}
              <a class="btn" href="/dev/${encodeURIComponent(d.slug)}">${tn(items.length, 'See their app', 'See all {n} of their apps')} ${SVG.arrow}</a>
            </div></div>
          </div>
          <div class="rowscroll">${items.slice(0, 12).map((a) => tileHTML(a, false)).join('')}</div>
        </section>`;
      }
      if (['top', 'trending', 'new', 'updated'].includes(sec.type)) {
        // (2.0) The charts: installs counted by the store, and GitHub's releases.
        if (!charts || !v2on()) return '';
        const ranked = sec.type === 'top' || sec.type === 'trending';
        const ids = (charts[sec.type] || []).map((x) => (typeof x === 'string' ? x : x && x.id));
        // (2.2) A chart of one part of the store only.
        const items = ids.map(byId).filter((a) => a && (!sec.category || (a.category || 'games') === sec.category)).slice(0, sec.layout === 'grid' ? 24 : 12);
        if (!items.length) return '';
        const title = `<h2>${esc(t(sec.title || { top: N_('Top charts'), trending: N_('Trending'), new: N_('New'), updated: N_('Recently updated') }[sec.type]))}</h2>`;
        if (sec.layout === 'wide') return `<section class="games"${acc(items)}>${title}<div class="rowscroll wides">${items.map((a) => wideHTML(a, false)).join('')}</div></section>`;
        const tiles = items.map((a, i) => tileHTML(a, false, ranked ? i + 1 : 0)).join('');
        return `<section class="games"${acc(items)}>${title}<div class="${sec.layout === 'grid' ? 'grid' : 'rowscroll'}">${tiles}</div></section>`;
      }
      const soon = sec.type === 'soon';
      if (sec.type === 'category' && !v2on()) return '';
      // (2.0) A category section: every game in one part of the store (Games, Emulators, Apps, Tools).
      const items = soon ? (cat.soon || []) : sec.type === 'category' ? inCat(sec.category)
        : sec.all ? cat.apps : (sec.games || []).map(byId).filter(Boolean);
      if (sec.type !== 'games' && sec.type !== 'category' && !soon) return '';
      if (!items.length) return '';
      const catRoute = sec.type === 'category' && (CATS.find((c) => c.key === sec.category) || {}).route;
      const title = sec.title ? `<h2 dir="auto">${catRoute ? `<a class="pc-row-link" href="${catRoute}">${esc(t(sec.title))}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></a>` : esc(t(sec.title))}</h2>` : '';
      if (sec.layout === 'wide') return `<section class="games"${acc(items)}>${title}<div class="rowscroll wides">${items.map((a) => wideHTML(a, soon)).join('')}</div></section>`;
      if (sec.layout === 'grid' && !soon) return `<section class="games"${acc(items)}>${title}<div class="grid">${items.map((a) => tileHTML(a, false)).join('')}</div></section>`;
      return `<section class="games"${acc(items)}>${title}<div class="rowscroll">${items.map((a) => tileHTML(a, soon)).join('')}</div></section>`;
    }).filter(Boolean);
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
      ['install', t('Install RecompStore'), t('Send the installer to your jailbroken PS5\'s payload loader. The store lands on your home screen and keeps itself up to date.'), `<a href="/get">${t('How to get it')}</a>`],
      ['pick', t('Pick a game'), t('Browse the store and install a port. It comes straight from its developer\'s GitHub release.')],
      ['backup', t('Bring your own backup'), t('Back up a game you own: a disc image, or the game\'s file from your console. Copy it to a USB drive or your PS5.')],
      ['setup', t('Set it up'), t('On the game\'s page, choose your backup under Your game files. The store puts every file where the port expects it, and can fetch a Title Update the game needs.')],
      ['play', t('Play'), t('Start it from the store or your home screen. Updates keep your game files and saves.')],
    ];
    return `
      <section class="how" aria-labelledby="how-title">
        <span class="kicker">${t('How it works')}</span>
        <h2 id="how-title">${t('From your own copy to your PS5, in five steps')}</h2>
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
            <strong>${t('No game files here. Ever.')}</strong>
            <p>${t('RecompStore and this website don\'t host, sell or share games, disc images, ROMs or any other game files, and neither do the GitHub repositories they link to. They hold only the ports and their tools. Every game needs a backup of your own legally obtained copy: you bring it, the store sets it up.')}</p>
          </div>
        </div>
      </section>`;
  }

  // ---- (2.0) The parts of the store: a row of buttons on the home page, and a page each ----
  const CATS = [
    { key: 'games', route: '/games', name: N_('Games'), icon: '<path d="M7.5 8.5h9a4.5 4.5 0 0 1 4.4 5.4l-.6 3a2.6 2.6 0 0 1-4.5 1.2L14 16h-4l-1.8 2.1a2.6 2.6 0 0 1-4.5-1.2l-.6-3A4.5 4.5 0 0 1 7.5 8.5z"/><path d="M8 11v3M6.5 12.5h3"/><circle cx="15.5" cy="11.6" r=".9"/><circle cx="17.4" cy="13.6" r=".9"/>' },
    { key: 'emulators', route: '/emulators', name: N_('Emulators'), icon: '<rect x="4" y="15" width="16" height="5.5" rx="2"/><path d="M12 15V8.6"/><circle cx="12" cy="6.2" r="2.6"/><circle cx="16.6" cy="17.75" r=".9"/><path d="M6.8 17.75h3"/>' },
    { key: 'apps', route: '/apps', name: N_('Apps'), icon: '<rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/>' },
    { key: 'tools', route: '/tools', name: N_('Tools'), icon: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>' },
  ];
  /* (2.7) The store's own category icons, in the brand gradient. */
  const catIcon = (c) => `<img src="/assets/cat/${c.key}.png" alt="" width="30" height="30">`;
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
    return `<nav class="cat-row" aria-label="${t('Browse by category')}">${have.map(([c, n]) => `
      <a class="glass cat-btn" href="${c.route}"><span class="cat-ico">${catIcon(c)}</span><span class="cat-name">${esc(t(c.name))}</span><span class="cat-n">${n}</span></a>`).join('')}</nav>`;
  }
  function categoryPage(key) {
    const c = CATS.find((x) => x.key === key);
    const apps = inCat(key);
    // Its apps' labels, the most used first, to filter by.
    const count = {};
    apps.forEach((a) => (Array.isArray(a.tags) ? a.tags : []).forEach((tag) => { if (typeof tag === 'string' && tag) count[tag] = (count[tag] || 0) + 1; }));
    const tags = Object.keys(count).sort((x, y) => count[y] - count[x] || x.localeCompare(y)).slice(0, 16);
    view.innerHTML = `
      <article class="cat-page">
        <a class="back" href="/">${backArrow()} ${t('All games')}</a>
        <header class="cat-head"><span class="cat-ico big">${catIcon(c)}</span><div><h1>${esc(t(c.name))}</h1><p class="muted">${tn(apps.length, '1 app', '{n} apps')}</p></div></header>
        ${tags.length ? `<div class="chips filter" role="group" aria-label="${t('Filter by label')}"><button type="button" class="tag on" data-tag="">${t('All')}</button>${tags.map((tag) => `<button type="button" class="tag" data-tag="${esc(tag)}">${esc(tag)}</button>`).join('')}</div>` : ''}
        ${apps.length ? '<div class="grid" data-grid></div>' : `<p class="lead">${t('Nothing here yet.')}</p>`}
      </article>`;
    setBackdrop(apps.find((a) => art(a)) || null);
    setAccent(apps[0] || null);
    const grid = view.querySelector('[data-grid]');
    const draw = (tag) => {
      if (!grid) return;
      const list = tag ? apps.filter((a) => Array.isArray(a.tags) && a.tags.includes(tag)) : apps;
      grid.innerHTML = list.map((a) => tileHTML(a, false)).join('');
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
    const secs = sectionsHTML();
    // (2.6) That no game files are here, between the rows; and a place to send your own app, at the end.
    const byo = `
      <section class="band byo-band">
        <span class="band-icon">${howIcon('shield')}</span>
        <div class="band-words"><strong>${t('No game files here. Ever.')}</strong><p>${t('RecompStore and the GitHub repositories it links to host only the ports and their tools. Every game needs your own copy.')}</p></div>
        <a class="btn ghost" href="/how">${t('How it works')}</a>
      </section>`;
    const at = Math.min(2, secs.length);
    view.innerHTML = `
      <section class="intro home-intro">
        <div class="intro-words">
          <h1 dir="auto">${esc(cat.site && cat.site.tagline ? t(cat.site.tagline) : t('Recompiled games, native on PS5.'))}</h1>
          <p>${t('Install and update PS5 ports of recompiled games. No account, no game files: bring your own disc.')}</p>
        </div>
        <div class="cta-row"><a class="btn primary" href="/get">${t('Get RecompStore')}</a><a class="btn ghost" href="/how">${t('How it works')}</a></div>
      </section>
      ${slides.length ? `
      <section class="slider" aria-roledescription="carousel" aria-label="${t('Featured games')}">
        ${slides.map((a, i) => `
          <a class="slide${i === 0 ? ' on' : ''}" href="/game/${encodeURIComponent(a.id)}" aria-hidden="${i === 0 ? 'false' : 'true'}" tabindex="${i === 0 ? 0 : -1}" data-slide="${i}">
            <span class="slide-blur" style="background-image:url('${cssUrl(art(a))}')"></span>
            <span class="slide-art"><img src="${esc(art(a))}" alt=""${i === 0 ? ' fetchpriority="high"' : ' loading="lazy"'} style="object-position:${frame(a.banner).x}% ${frame(a.banner).y}%;transform-origin:${frame(a.banner).x}% ${frame(a.banner).y}%;--z:${frame(a.banner).z}"></span>
            <span class="slide-text">
              <span class="slide-meta">${esc(t((CATS.find((c) => c.key === (a.category || 'games')) || CATS[0]).name))}${a.author ? ` · <bdi>${esc(a.author)}</bdi>` : ''}</span>
              <span class="slide-name" dir="auto">${esc(a.name)}</span>
              <span class="slide-blurb" dir="auto">${esc(a.blurb || a.description)}</span>
            </span>
          </a>`).join('')}
        ${slides.length > 1 ? `<div class="thumbs">${slides.map((a, i) => `<button type="button" data-dot="${i}" aria-label="${tf('Show {name}', { name: esc(a.name) })}"${i === 0 ? ' aria-current="true"' : ''}><img src="${esc(art(a))}" alt="" loading="lazy"></button>`).join('')}</div>` : ''}
      </section>` : ''}
      ${catRowHTML()}
      ${secs.slice(0, at).join('')}
      ${byo}
      ${secs.slice(at).join('')}
      <section class="band submit-band">
        <div class="band-words">
          <span class="kicker">${t('Made something for PS5?')}</span>
          <h2>${t('Submit to RecompStore')}</h2>
          <p>${t('A game port, an emulator, an app or a tool: send its GitHub, details and pictures, and it can go straight onto its own page.')}</p>
          <div class="cta-row"><a class="btn primary" href="/submit">${t('Submit an app')}</a>${v2on() ? `<a class="btn ghost" href="/ids">${t('Title IDs')}</a>` : ''}</div>
        </div>
        <a class="glass report-card" href="/report">
          <span class="kicker">${t('Found a bug?')}</span>
          <strong>${t('Report it')}</strong>
          <span>${t('Pick the game and tell us what happened. It goes straight to the developer.')}</span>
        </a>
      </section>`;
    setBackdrop(slides[0]);
    setAccent(slides[0] || null);
    // Slider: crossfade every 7 seconds, with dots to jump.
    const els = [...view.querySelectorAll('.slider:not(.still) .slide')];
    const dots = [...view.querySelectorAll('[data-dot]')];
    let cur = 0;
    const show = (i) => {
      cur = (i + els.length) % els.length;
      els.forEach((el, k) => { el.classList.toggle('on', k === cur); el.setAttribute('aria-hidden', k === cur ? 'false' : 'true'); el.tabIndex = k === cur ? 0 : -1; });
      dots.forEach((d, k) => d.toggleAttribute('aria-current', k === cur));
      // The page follows the slide while the slider is what's in front of you.
      if (window.scrollY < 400) { setBackdrop(slides[cur]); setAccent(slides[cur]); }
    };
    setFromSlide = (el) => { const a = el && slides[+el.dataset.slide]; if (a) { setBackdrop(a); setAccent(a); } };
    dots.forEach((d) => d.addEventListener('click', () => { show(+d.dataset.dot); restart(); }));
    const restart = () => { clearInterval(slideTimer); if (els.length > 1 && !reduced) slideTimer = setInterval(() => show(cur + 1), 7000); };
    restart();
    // (2.7.3) A swipe on a phone goes to the next or previous slide.
    const box = view.querySelector('.slider:not(.still)');
    if (box && els.length > 1) {
      let x0 = null, y0 = 0, swiped = false;
      box.addEventListener('touchstart', (e) => { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; swiped = false; }, { passive: true });
      box.addEventListener('touchmove', (e) => {
        if (x0 === null) return;
        const dx = e.touches[0].clientX - x0, dy = e.touches[0].clientY - y0;
        if (Math.abs(dx) > 10 && Math.abs(dx) > Math.abs(dy)) box.style.setProperty('--drag', `${Math.max(-60, Math.min(60, dx / 3))}px`);
      }, { passive: true });
      box.addEventListener('touchend', (e) => {
        if (x0 === null) return;
        const dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
        x0 = null;
        box.style.removeProperty('--drag');
        if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.2) {
          swiped = true;
          const rtl = document.documentElement.dir === 'rtl';
          show(cur + ((dx < 0) !== rtl ? 1 : -1));
          restart();
        }
      });
      box.addEventListener('touchcancel', () => { x0 = null; box.style.removeProperty('--drag'); });
      // A swipe isn't a tap on the slide's link.
      box.addEventListener('click', (e) => { if (swiped) { e.preventDefault(); e.stopPropagation(); swiped = false; } }, true);
    }
  }

  // ---- A Coming soon game's page ------------------------------------------------------
  function soonPage(id) {
    const a = soonById(id);
    if (!a) { notFound(); return; }
    const follow = a.source && a.source.type === 'github' && /^[\w.-]+\/[\w.-]+$/.test(a.source.repo || '') ? `https://github.com/${a.source.repo}`
      : /^https:\/\//.test(a.url || '') ? a.url : '';
    view.innerHTML = `
      <article class="game">
        <a class="back" href="/">${backArrow()} ${t('All games')}</a>
        <div class="game-grid">
          <div class="game-info">
            <div class="chips"><span class="tag cyan">${t('Coming soon')}</span>${a.date ? `<span class="tag">${esc(a.date)}</span>` : ''}${a.author ? `<span class="tag">${tf('By {author}', { author: esc(a.author) })}</span>` : ''}${shareHTML(a, 'soon')}</div>
            ${a.logo && a.logo.url
              ? `<h1 class="logo-h1"><img src="${esc(a.logo.url)}" alt="${esc(a.name)}" style="--s:${(a.logo.page && a.logo.page.scale ? a.logo.page.scale : 100) / 100}"></h1>`
              : `<h1 dir="auto">${esc(a.name)}</h1>`}
            <p class="lead" dir="auto">${esc(a.description || a.blurb)}</p>
          </div>
          <aside class="glass panel">
            <div class="panel-head">${iconHTML(a, 112)}<div><strong dir="auto">${esc(a.name)}</strong><span>${a.author ? tf('By {author}', { author: esc(a.author) }) : t('Coming soon')}</span></div></div>
            <div class="btn soon-btn" aria-disabled="true">${a.date ? tf('Coming {date}', { date: esc(a.date) }) : t('Coming soon')}</div>
            <p class="note">${t('It isn\'t out yet. When it is, it shows up in RecompStore on your PS5 and here, ready to install.')}</p>
            ${follow ? `<a class="src" href="${esc(follow)}" target="_blank" rel="noopener">${follow.includes('github.com') ? t('Follow it on GitHub') : t('Follow it')} ↗</a>` : ''}
          </aside>
        </div>
      </article>`;
    setBackdrop(a);
    setAccent(a);
  }

  // ---- A game's own link -------------------------------------------------------------
  // recompstore.com/game/<ID> (Coming soon: /soon/<ID>) shows the game's card when it's shared
  // in Discord, X, Messages and the like; the server puts the game's name and picture in it.
  const ownLink = (a, kind) => `${location.origin}/${kind}/${encodeURIComponent(a.id)}`;
  function shareHTML(a, kind) {
    return `<button type="button" class="tag share" data-share="${esc(ownLink(a, kind))}" data-title="${esc(a.name)}">${t('Share')}</button>`;
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-share]');
    if (!b) return;
    const url = b.dataset.share;
    const done = (msg) => { b.textContent = msg; setTimeout(() => (b.textContent = t('Share')), 1600); };
    // Phones: the share sheet. Elsewhere: the link, copied.
    if (navigator.share && matchMedia('(pointer: coarse)').matches) {
      navigator.share({ title: b.dataset.title, url }).catch(() => {});
    } else if (navigator.clipboard) {
      navigator.clipboard.writeText(url).then(() => done(t('Link copied')), () => window.prompt(t('Copy this link'), url));
    } else {
      window.prompt(t('Copy this link'), url);
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
  // ---- (2.6) Screenshots, a video, and developer pages --------------------------------
  const DEV_LINKS = { github: 'GitHub', kofi: 'Ko-fi', patreon: 'Patreon', paypal: 'PayPal', support: N_('Support'), discord: 'Discord', x: 'X', youtube: 'YouTube', site: N_('Website') };
  const devList = () => (cat && Array.isArray(cat.developers) ? cat.developers : []);
  const devBySlug = (s) => devList().find((d) => d && d.slug === s);
  const devOf = (a) => (a && a.dev ? devBySlug(a.dev) : null);
  // (2.7.2) The developer's Ko-fi, Patreon, PayPal or other support link, as a button on their app pages.
  const supportHTML = (a) => {
    const d = devOf(a);
    const u = d && d.links ? ['kofi', 'patreon', 'paypal', 'support'].map((k) => d.links[k]).find((x) => /^https:\/\//.test(x || '')) : '';
    return u ? `<a class="btn support-btn" href="${esc(u)}" target="_blank" rel="noopener">♥ ${tf('Support {name}', { name: esc(d.name) })}</a>` : '';
  };
  const tick = () => '<svg class="verified" viewBox="0 0 24 24" role="img" aria-label="' + t('Verified developer') + '"><title>' + t('Verified developer') + '</title><path d="M12 2.5l2.4 1.8 3-.1.9 2.8 2.5 1.7-.9 2.9.9 2.8-2.5 1.8-.9 2.8-3-.1L12 21.5l-2.4-1.8-3 .1-.9-2.8-2.5-1.8.9-2.8-.9-2.9 2.5-1.7.9-2.8 3 .1z" fill="currentColor"/><path d="M8.3 12.2l2.5 2.5 5-5.1" fill="none" stroke="#0B1220" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  // Who made it, as plain text for tiles (a tile is a link already): the developer's name with the
  // Verified tick, else the author, else RecompStore for its own apps.
  const devName = (a, d) => d.name || a.author || '';
  function whoHTML(a) {
    const d = devOf(a);
    if (d) return `<span class="who">${esc(devName(a, d))}${d.verified ? tick() : ''}</span>`;
    return esc(a.kind === 'original' ? 'RecompStore' : a.author || '');
  }
  function byLine(a) {
    const d = devOf(a);
    if (!d) return a.kind === 'original' ? 'RecompStore' : tf('By {author}', { author: esc(a.author) });
    return tf('By {author}', { author: `<a href="/dev/${encodeURIComponent(d.slug)}">${esc(devName(a, d))}</a>` }) + (d.verified ? tick() : '');
  }
  // (2.7.3) Under the app's name: who made it, with their picture and the Verified badge.
  function devLineHTML(a) {
    const d = devOf(a);
    if (!d) return '';
    return `<a class="dev-line" href="/dev/${encodeURIComponent(d.slug)}">${devPicHTML(d, 28)}<span>${tf('By {author}', { author: `<bdi>${esc(devName(a, d))}</bdi>` })}</span>${d.verified ? `<span class="verified-pill">${tick()}${t('Verified developer')}</span>` : ''}</a>`;
  }
  const devPicHTML = (d, n) => `<span class="dev-pic" style="width:${n}px;height:${n}px">${d.avatar ? `<img src="${esc(d.avatar)}" alt="" width="${n}" height="${n}" loading="lazy">` : `<span>${esc(String(d.name || '?').slice(0, 1).toUpperCase())}</span>`}</span>`;
  // (2.7.3) The card about the developer on their app's page: who they are, how many apps, their page and support link.
  function devCardHTML(a) {
    const d = devOf(a);
    if (!d) return '';
    const n = cat.apps.filter((x) => x.dev === d.slug).length;
    return `<section class="glass dev-card">
      <span class="kicker">${d.verified ? t('From a verified developer') : t('The developer')}</span>
      <div class="dev-card-head">${devPicHTML(d, 56)}<div><strong><bdi>${esc(d.name)}</bdi>${d.verified ? tick() : ''}</strong><span class="muted">${tn(n, '1 app in RecompStore', '{n} apps in RecompStore')}</span></div></div>
      ${d.about ? `<p dir="auto">${esc(d.about)}</p>` : ''}
      ${d.verified ? `<p class="dev-card-why">${t('Verified developers are checked by RecompStore and look after their own pages and updates.')}</p>` : ''}
      <div class="dev-card-btns"><a class="btn" href="/dev/${encodeURIComponent(d.slug)}">${t('See their page')} ${SVG.arrow}</a>${supportHTML(a)}</div>
    </section>`;
  }
  const shotsOf = (a) => (Array.isArray(a.screens) ? a.screens : []).filter((x) => x && /^https?:\/\//.test(x.url || '') && /^https?:\/\//.test(x.thumb || ''));
  const videoOf = (a) => (a.video && a.video.type === 'youtube' && /^[\w-]{11}$/.test(a.video.id || '') ? a.video.id : '');
  function mediaHTML(a) {
    const shots = shotsOf(a), vid = videoOf(a);
    if (!shots.length && !vid) return '';
    const poster = shots.length ? shots[0].url : art(a);
    return `<section class="media" aria-label="${vid ? t('Video and screenshots') : t('Screenshots')}">
      ${vid ? `<div class="video" data-video="${esc(vid)}"><button type="button" class="video-poster" data-play aria-label="${t('Play the video (loads from YouTube)')}">${poster ? `<img src="${esc(poster)}" alt="" loading="lazy">` : ''}<span class="play" aria-hidden="true"></span><span class="video-note">${t('Plays from YouTube')}</span></button></div>` : ''}
      ${shots.length ? `<div class="shots">${shots.map((x, i) => `<button type="button" class="shot" data-shot="${i}" aria-label="${tf('Screenshot {i} of {total}, open it bigger', { i: i + 1, total: shots.length })}"><img src="${esc(x.thumb)}" alt="" loading="lazy"></button>`).join('')}</div>` : ''}
    </section>`;
  }
  function wireMedia(a) {
    const box = view.querySelector('[data-video]');
    if (box) box.querySelector('[data-play]').addEventListener('click', () => {
      // Nothing comes from YouTube until here.
      box.innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(box.dataset.video)}?autoplay=1&rel=0" title="${tf('{name}: video', { name: esc(a.name) })}" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>`;
      box.querySelector('iframe').focus();
    });
    const shots = shotsOf(a);
    view.querySelectorAll('[data-shot]').forEach((b) => b.addEventListener('click', () => lightbox(shots, Number(b.dataset.shot), a.name, b)));
  }
  // Big screenshots: arrows, keyboard (left, right, Escape) and swipe.
  function lightbox(shots, start, name, opener) {
    let i = start, x0 = null, swiped = false;
    const d = document.createElement('dialog');
    d.className = 'lightbox';
    d.setAttribute('aria-label', tf('{name}: screenshots', { name }));
    d.innerHTML = `<figure><img alt=""><figcaption aria-live="polite"></figcaption></figure>
      <button type="button" class="lb-btn lb-prev" data-prev aria-label="${t('Previous screenshot')}">‹</button>
      <button type="button" class="lb-btn lb-next" data-next aria-label="${t('Next screenshot')}">›</button>
      <button type="button" class="lb-btn lb-close" data-close-lb aria-label="${t('Close')}">✕</button>`;
    document.body.appendChild(d);
    const img = d.querySelector('img'), cap = d.querySelector('figcaption');
    const show = () => {
      img.src = shots[i].url;
      img.alt = tf('{name}, screenshot {i}', { name, i: i + 1 });
      cap.textContent = `${i + 1} / ${shots.length}`;
      d.querySelector('[data-prev]').hidden = d.querySelector('[data-next]').hidden = shots.length < 2;
    };
    const go = (k) => { i = (i + k + shots.length) % shots.length; show(); };
    d.addEventListener('click', (e) => {
      if (swiped) { swiped = false; return; }
      if (e.target.closest('[data-prev]')) go(-1);
      else if (e.target.closest('[data-next]')) go(1);
      else if (e.target.closest('[data-close-lb]') || e.target === d || e.target.tagName === 'FIGURE') d.close();
    });
    d.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
    });
    d.addEventListener('pointerdown', (e) => { x0 = e.clientX; });
    d.addEventListener('pointerup', (e) => {
      if (x0 === null) return;
      const dx = e.clientX - x0;
      x0 = null;
      if (Math.abs(dx) > 50 && shots.length > 1) { swiped = true; go(dx < 0 ? 1 : -1); }
    });
    d.addEventListener('close', () => { d.remove(); if (opener) opener.focus(); });
    show();
    d.showModal();
  }
  function moreFromDevHTML(a) {
    const d = devOf(a);
    if (!d) return '';
    const items = cat.apps.filter((x) => x.dev === d.slug && x.id !== a.id).slice(0, 12);
    if (!items.length) return '';
    return `<section class="games also"><h2>${tf('More from {name}', { name: `<a href="/dev/${encodeURIComponent(d.slug)}">${esc(d.name)}</a>` })}</h2><div class="rowscroll">${items.map((x) => tileHTML(x, false)).join('')}</div></section>`;
  }
  function devPage(slug) {
    const d = devBySlug(slug);
    if (!d) { notFound(); return; }
    const apps = cat.apps.filter((x) => x.dev === d.slug);
    const links = Object.entries(d.links || {}).filter(([k, u]) => DEV_LINKS[k] && /^https:\/\//.test(u || ''))
      .map(([k, u]) => `<a class="btn" href="${esc(u)}" target="_blank" rel="noopener">${esc(t(DEV_LINKS[k]))}</a>`).join('');
    view.innerHTML = `
      <article class="devpage">
        <a class="back" href="/">${backArrow()} ${t('All games')}</a>
        <header class="dev-head">
          <span class="dev-pic">${d.avatar ? `<img src="${esc(d.avatar)}" alt="">` : `<span>${esc(String(d.name || '?').slice(0, 1).toUpperCase())}</span>`}</span>
          <div class="dev-words">
            <h1><bdi>${esc(d.name)}</bdi>${d.verified ? tick() : ''}</h1>
            ${d.verified ? `<p class="verified-line">${t('Verified developer')}</p>` : ''}
            ${d.about ? `<p class="lead" dir="auto">${esc(d.about)}</p>` : ''}
            ${links ? `<div class="dev-links">${links}</div>` : ''}
          </div>
        </header>
        <section class="games"><h2>${tn(apps.length, '1 app in RecompStore', '{n} apps in RecompStore')}</h2>
          ${apps.length ? `<div class="grid">${apps.map((x) => tileHTML(x, false)).join('')}</div>` : `<p class="muted">${t('Nothing listed right now.')}</p>`}
        </section>
      </article>`;
    setBackdrop(apps.find((x) => art(x)) || null);
    setAccent(apps[0] || null);
    // (2.8) Their PC recomps too.
    const page = view.querySelector('.devpage');
    pcLoad().then(() => {
      const pcs = pcCat.apps.filter((x) => x.dev === d.slug);
      if (!pcs.length || !page.isConnected) return;
      page.insertAdjacentHTML('beforeend', `<section class="games"><h2>${t('PC recomps')}</h2><div class="pc-grid">${pcs.map(pcPoster).join('')}</div></section>`);
    });
  }

  function alsoHTML(a) {
    if (!v2on() || !charts || !charts.also || !Array.isArray(charts.also[a.id])) return '';
    const items = charts.also[a.id].map(byId).filter(Boolean).slice(0, 8);
    if (!items.length) return '';
    return `<section class="games also"><h2>${t('People also installed')}</h2><div class="rowscroll">${items.map((x) => tileHTML(x, false)).join('')}</div></section>`;
  }
  function game(id) {
    const a = byId(id);
    if (!a) { notFound(); return; }
    const repoUrl = a.source && a.source.type === 'github' && /^[\w.-]+\/[\w.-]+$/.test(a.source.repo || '') ? `https://github.com/${a.source.repo}` : '';
    const rows = [
      ['id', t('Title ID'), a.id], ['version', t('Version'), a.version], ['size', t('Download'), a.size],
      ['needs', t('You bring'), a.needs ? tf('Your {thing}', { thing: a.needs }) : (a.category || 'games') === 'games' ? t('Your own game') : ''],
      ['dir', t('Installs to'), '/data/homebrew/' + a.id], ['min', t('Needs'), a.minStore ? tf('RecompStore {version} or newer', { version: a.minStore }) : ''],
      ['installs', t('On consoles'), v2on() && charts && charts.stats && charts.stats[a.id] && charts.stats[a.id].installs ? fmtNum(Number(charts.stats[a.id].installs)) : ''],
    ].filter((r) => r[2]);
    view.innerHTML = `
      <article class="game">
        <a class="back" href="/">${backArrow()} ${t('All games')}</a>
        <div class="game-grid">
          <div class="game-info">
            <div class="game-hero-m" aria-hidden="true"><span class="game-hero-pic">${art(a) ? `<img src="${esc(art(a))}" alt="" loading="lazy" style="object-position:${frame(a.banner).x}% ${frame(a.banner).y}%">` : `<span class="game-hero-blur" style="background-image:url('${cssUrl(a.icon || '')}')"></span>`}</span><span class="game-hero-ico">${iconHTML(a, 112)}</span></div>
            <div class="chips">${devOf(a) ? '' : tagFor(a)}<span class="tag">${esc(a.id)}</span>${setupBadge(a)}${shareHTML(a, 'game')}</div>
            ${a.logo && a.logo.url
              ? `<h1 class="logo-h1"><img src="${esc(a.logo.url)}" alt="${esc(a.name)}" style="--s:${(a.logo.page && a.logo.page.scale ? a.logo.page.scale : 100) / 100}"></h1>`
              : `<h1 dir="auto">${esc(a.name)}</h1>`}
            ${devLineHTML(a)}
            <p class="lead" dir="auto">${esc(a.description)}</p>
            ${mediaHTML(a)}
            ${devCardHTML(a)}
            ${a.news || repoUrl ? `
            <details class="glass news" id="notes">
              <summary><span class="kicker">${a.news || a.version ? t("What's new") + (a.version ? ' · ' + esc(a.version) : '') : t('All patch notes')}</span>${a.news ? `<p dir="auto">${esc(a.news)}</p>` : ''}${repoUrl ? `<span class="more">${t('All patch notes')}</span>` : ''}</summary>
              <div class="notes" data-notes><p class="muted">${t('Loading the notes from GitHub…')}</p></div>
            </details>` : ''}
            ${a.manualSetup ? `<div class="glass manual-note"><span class="kicker">${t('Not plug and play')}</span><p dir="auto">${esc(a.manualSetup)}</p></div>` : ''}
            ${a.setup ? setupHTML(a) : ''}
            ${a.credits ? `<p class="credits" dir="auto">${esc(a.credits)}</p>` : ''}
            ${howToHTML(a, repoUrl)}
            <div class="glass disc-note"><span class="kicker">${t('Bring your own disc')}</span><p>${t('RecompStore and this game\'s GitHub repository don\'t host any game files. Set it up from your own legally obtained copy.')}</p></div>
          </div>
          <aside class="glass panel">
            <div class="panel-head">${iconHTML(a, 112)}<div><strong dir="auto">${esc(a.name)}</strong><span>${byLine(a)}</span></div></div>
            ${installHTML(a)}
            ${a.minStore ? `<p class="note needs">${tf('Needs RecompStore {version} or newer. On your PS5, update the store from Updates first.', { version: esc(a.minStore) })}</p>` : ''}
            <a class="btn" id="dl" href="${esc(a.url || (repoUrl ? repoUrl + '/releases/latest' : '#'))}" target="_blank" rel="noopener">${repoUrl && a.source.inside ? t("The developer's release") : t('Download the zip')}</a>
            <dl>${rows.map((r) => `<div><dt>${esc(r[1])}</dt><dd dir="auto"${r[0] === 'version' ? ' data-version' : ''}>${esc(r[2])}</dd></div>`).join('')}</dl>
            ${repoUrl ? `<a class="src" href="${esc(repoUrl)}" target="_blank" rel="noopener">${t('Source on GitHub')} ↗</a>` : ''}
            <p class="note">${t('Updates through RecompStore replace only the app. Your game install, saves, DLC and mods stay where they are.')}</p>
            ${linksHTML(a)}
            <a class="src badge-link" href="/press?app=${encodeURIComponent(a.id)}">${t('Made this? Get a badge for your README')}</a>
          </aside>
        </div>
        ${moreFromDevHTML(a)}
        ${alsoHTML(a)}
      </article>`;
    setBackdrop(a);
    setAccent(a);
    wireMedia(a);
    resolveDownload(a);
    // (2.6) The description in the reader's language, once the site has it translated.
    const lead = view.querySelector('.game .lead');
    if (lead && a.description) translateWritten([a.description]).then(([x]) => { if (x) showTranslated(lead, esc(x), esc(a.description)); });
    const notes = document.getElementById('notes');
    if (notes && repoUrl) notes.addEventListener('toggle', () => { if (notes.open) loadNotes(a.source.repo, notes.querySelector('[data-notes]')); }, { once: false });
  }

  // (2.7.1) How it gets on a PS5, step by step, folded away under its title.
  function howToHTML(a, repoUrl) {
    const cat = CATS.find((x) => x.key === (a.category || 'games'));
    const steps = [
      `${esc(t('Install RecompStore on your jailbroken PS5.'))} <a href="/get">${esc(t('Get RecompStore'))}</a>`,
      esc(tf('Open RecompStore and find {name} in {category}.', { name: a.name, category: t(cat ? cat.name : 'Games') })),
      esc(repoUrl ? t("Press Install. The store downloads it from its developer's GitHub release and keeps it up to date.") : t('Press Install, and the store keeps it up to date.')),
    ];
    if (a.needs) steps.push(esc(tf('Set it up from your own {thing}.', { thing: a.needs })));
    return `<details class="glass news howto"><summary><span class="kicker">${esc(tf('How to install {name} on PS5', { name: a.name }))}</span></summary><ol>${steps.map((x) => `<li>${x}</li>`).join('')}</ol></details>`;
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

  // (2.6) What people wrote (descriptions, patch notes) in the reader's language, from
  // /api/tr.php: DeepL, once the admin has given the site a key; each text translated once there
  // and kept. null where there's no translation (show it as written). English readers only ask
  // about patch notes (some are written in another language).
  const trCache = {};
  function translateWritten(texts, notes) {
    if (!texts.length || ((lang === 'en' || lang === 'en-GB') && !notes)) return Promise.resolve(texts.map(() => null));
    const key = (s) => lang + '\u001f' + s;
    const need = [...new Set(texts.filter((s) => s && !(key(s) in trCache)))].slice(0, 40);
    const ask = need.length
      ? fetch('/api/tr.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ lang, texts: need }) })
        .then((r) => (r.ok ? r.json() : null))
        .then((j) => { need.forEach((s, i) => { const v = j && Array.isArray(j.texts) ? j.texts[i] : null; trCache[key(s)] = typeof v === 'string' && v !== s ? v : null; }); })
        .catch(() => {})
      : Promise.resolve();
    return ask.then(() => texts.map((s) => (s ? trCache[key(s)] || null : null)));
  }
  // Puts a translation in el, with a line under it to go back to what was written (and again).
  function showTranslated(el, html, originalHtml) {
    if (!el || !el.isConnected) return;
    el.innerHTML = html;
    const note = document.createElement('p');
    note.className = 'tr-note';
    note.innerHTML = `${esc(t('Translated automatically'))} · <button type="button" class="linkish">${esc(t('Show original'))}</button>`;
    let original = false;
    const b = note.querySelector('button');
    b.addEventListener('click', () => {
      original = !original;
      el.innerHTML = original ? originalHtml : html;
      b.textContent = original ? t('Show translation') : t('Show original');
    });
    el.after(note);
  }

  // ---- Patch notes: every release on the game's GitHub repo -------------------------
  const notesCache = {};
  function md(text) {
    // A small, safe Markdown: escaped first, then headings, lists, bold, code and https links.
    const inline = (str) => esc(str)
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
      else { const para = inline(line.replace(/^>\s?/, '')); if (para.trim()) out.push(`<p>${para}</p>`); }
    });
    if (list) out.push('</ul>');
    return out.join('');
  }
  // (2.8) Release notes go through the README renderer: GitHub's HTML in them (pictures, links,
  // line breaks, <details>) shows as it should, and their headings sit under the release's own.
  const notesMd = (text, repo) => mdFull(text, `https://raw.githubusercontent.com/${repo}/HEAD/`, `https://github.com/${repo}/blob/HEAD/`)
    .replace(/<(\/?)h[23]\b/g, '<$1h4');
  function loadNotes(repo, el) {
    const show = (rel) => {
      if (!rel || !rel.length) { el.innerHTML = `<p class="muted">${t('No releases on GitHub yet.')}</p>`; return; }
      el.innerHTML = rel.filter((r) => !r.draft).map((r) => `
        <section class="release" dir="auto">
          <h3>${esc(r.name || r.tag_name)}</h3>
          <p class="when">${esc(new Date(r.published_at).toLocaleDateString(locale(), { year: 'numeric', month: 'long', day: 'numeric' }))}${r.prerelease ? ' · ' + t('Pre-release') : ''}${r.name && r.name !== r.tag_name ? ' · ' + esc(r.tag_name) : ''}</p>
          <div class="rel-body">${notesMd(r.body, repo) || `<p class="muted">${t('No notes for this release.')}</p>`}</div>
        </section>`).join('');
      // (2.6) In the reader's language, as they come.
      const shown = rel.filter((r) => !r.draft);
      translateWritten(shown.map((r) => r.body || ''), true).then((tx) => {
        const bodies = el.querySelectorAll('.rel-body');
        tx.forEach((x, i) => { if (x && bodies[i]) showTranslated(bodies[i], notesMd(x, repo), notesMd(shown[i].body, repo)); });
      });
    };
    if (notesCache[repo]) { show(notesCache[repo]); return; }
    fetch(`https://api.github.com/repos/${repo}/releases?per_page=30`, { headers: { Accept: 'application/vnd.github+json' } })
      .then((r) => (r.ok ? r.json() : Promise.reject(r.status)))
      .then((rel) => { notesCache[repo] = rel; show(rel); })
      .catch(() => { el.innerHTML = `<p class="muted">${tf("GitHub's notes couldn't be loaded. {link}.", { link: `<a href="https://github.com/${esc(repo)}/releases" target="_blank" rel="noopener">${t('See them on GitHub')}</a>` })}</p>`; });
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
            link.textContent = rel.tag_name ? tf("The developer's release ({version})", { version: rel.tag_name }) : t("The developer's release");
          } else if (link) {
            link.href = asset.browser_download_url;
            link.textContent = tf('Download {file} ({size})', { file: asset.name, size: tf('{size} MB', { size: Math.round(asset.size / 1048576) }) });
          }
          const ver = document.querySelector('[data-version]');
          if (ver && rel.tag_name) ver.textContent = rel.tag_name.replace(/^v(?=\d)/i, '');
          return;
        }
      })
      .catch(() => {});
  }

  // (2.6) Every game's setup badge, from its listing: Manual setup (it says what you do by hand),
  // Bring your files (the store sets it up from yours), or Plug and play.
  function setupBadge(a) {
    const slots = a.setup && Array.isArray(a.setup.slots) ? a.setup.slots : [];
    if (a.manualSetup) return `<span class="tag warn" title="${t('Needs a step you do yourself')}">${t('Manual setup')}</span>`;
    if (slots.length) return `<span class="tag info" title="${t('RecompStore sets it up from your own files')}">${t('Bring your files')}</span>`;
    return `<span class="tag good" title="${t('Install it and play')}">${t('Plug and play')}</span>`;
  }
  function setupHTML(a) {
    const s = a.setup || {};
    const slots = Array.isArray(s.slots) ? s.slots : [];
    const what = (sl) => sl.kind === 'iso' ? t('the files inside your disc image')
      : sl.kind === 'stfs' ? (sl.updateId ? t('unpacked from the package, or downloaded by the store') : t('unpacked from the package'))
      : sl.kind === 'arcade' ? t('unpacked from your Xbox Live Arcade game file')
      : sl.kind === 'image' ? (sl.discId ? tf('your disc image as it is, disc {disc}', { disc: esc(sl.discId) }) : t('your disc image as it is'))
      : sl.kind === 'folder' ? (sl.pick ? tf('everything in your folder, the one with {file}', { file: esc(sl.pick) }) : t('everything in your folder')) : '';
    const lines = slots.map((sl) => sl.to === '.' || sl.to === ''
      ? `└── ${tf('({label}: {what}, right in this folder)', { label: esc(sl.label), what: what(sl) })}`
      : `├── ${esc(sl.to)}/   ${esc(sl.label)}${what(sl) ? ' (' + what(sl) + ')' : ''}`);
    const kind = slots.length > 1 ? 'many' : slots[0] && slots[0].kind === 'arcade' ? 'arcade' : slots[0] && slots[0].kind === 'file' ? 'file' : slots[0] && slots[0].kind === 'folder' ? 'folder' : 'image';
    const intro = {
      many: N_("RecompStore sets this game up from your own files: on the game's page in the store, choose them under Your game files and press Set up. Done by hand, it looks like this:"),
      arcade: N_("RecompStore sets this game up from your own game file (from the console's 000D0000 folder): on the game's page in the store, choose it under Your game files and press Set up. Done by hand, it looks like this:"),
      file: N_("RecompStore sets this game up from your own file: on the game's page in the store, choose it under Your game files and press Set up. Done by hand, it looks like this:"),
      folder: N_("RecompStore sets this game up from your own folder: on the game's page in the store, choose it under Your game files and press Set up. Done by hand, it looks like this:"),
      image: N_("RecompStore sets this game up from your own disc image: on the game's page in the store, choose it under Your game files and press Set up. Done by hand, it looks like this:"),
    }[kind];
    return `
      <section class="glass setup">
        <span class="kicker">${t('Your game files')}</span>
        <p>${t(intro)}</p>
        <pre class="tree">${esc(s.dataDir || '/data/homebrew/' + a.id)}/\n${lines.join('\n')}</pre>
      </section>`;
  }

  // ---- About ----------------------------------------------------------------------
  function about() {
    const s = cat.site || {};
    view.innerHTML = `
      <article class="about">
        <div class="about-head"><img src="/assets/logo-512.png" alt=""><div><h1>RecompStore</h1><p>${tf('Made by {name}.', { name: 'elripalda' })}</p></div></div>
        <div class="glass notice">
          <span class="kicker">${t('Bring your own disc')}</span>
          <p>${t('RecompStore doesn\'t host any game files or copyrighted game content, and neither do the GitHub repositories it links to. They hold only the ports and their tools. Every game needs your own legally obtained copy, which you set up yourself.')}</p>
          <p>${t('Title Updates the store downloads for you come from XboxUnity\'s community archive; RecompStore doesn\'t host them either.')}</p>
          <p class="small">${t('RecompStore and the games listed in it aren\'t affiliated with or endorsed by Sony Interactive Entertainment, Microsoft, or any game\'s publisher or developer. All trademarks belong to their owners.')}</p>
        </div>
        <h2>${t('Adding your game')}</h2>
        <ol class="steps plain">
          <li><strong>${t('Install the game from RecompStore.')}</strong> ${tf('The port lands in {path}.', { path: '/data/homebrew/&lt;' + esc(t('title ID')) + '&gt;' })}</li>
          <li><strong>${t('Back up your own copy.')}</strong> ${t('A disc image, or the game\'s file from your Xbox 360 for an Xbox Live Arcade game. Neither the store nor this site ever downloads games.')}</li>
          <li><strong>${t('Copy it to your PS5.')}</strong> ${t('Anywhere on the console over FTP, or on a USB drive.')}</li>
          <li><strong>${t('Set up the game files in the store.')}</strong> ${t('On the game\'s page, choose your backup under Your game files and press Set up. If the game needs a Title Update, the store can download it for you. You do this once: updates keep your install and saves.')}</li>
        </ol>
        <p class="links">${[
          `<a href="/report">${t('Report a bug')}</a>`,
          s.discord ? `<a href="${esc(s.discord)}" target="_blank" rel="noopener">Discord · ${t('help')}</a>` : '',
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
        <a class="back" href="/contact">${backArrow()} ${t('Contact')}</a>
        <h1>${bug ? t('Report a bug') : t('Submit your recomp')}</h1>
        <p class="lead">${bug
          ? t('Tell us what went wrong and which game it was in. The more you can say about what you did right before, the faster it gets fixed.')
          : t('Ported a recompiled game to PS5? Tell us about it. We\'ll look at it, get in touch, and talk about putting it in RecompStore.')}</p>
        <form class="glass form" novalidate>
          ${bug ? `
            <label>${t('Which game?')}<select name="app" required><option value="">${t('Choose a game')}</option>${games}<option value="store">${t('RecompStore itself')}</option></select></label>
            <label>${t('Version')} <span class="opt">${t('optional')}</span><input name="version" maxlength="64" placeholder="${t('Shown on the game\'s page, e.g. 1.0.0')}"></label>
            <label>${t('What happened?')}<textarea name="body" rows="7" maxlength="5000" required placeholder="${t('What you did, what you expected, and what happened instead.')}"></textarea></label>
            <label>${t('How can we reach you?')} <span class="opt">${t('optional')}</span><input name="contact" maxlength="200" placeholder="${t('Email or Discord name, if you\'d like a reply')}"></label>`
          : `
            <label>${t('Your name or handle')}<input name="name" maxlength="120" required autocomplete="nickname"></label>
            <label>${t('Email')}<input name="email" type="email" maxlength="200" required autocomplete="email" placeholder="${t('So we can get back to you')}"></label>
            <label>${t('GitHub repository')}<input name="repo" type="url" maxlength="255" required placeholder="https://github.com/you/your-port"></label>
            <label>${t('What is it?')}<textarea name="body" rows="6" maxlength="5000" required placeholder="${t('The game, what it needs from the player, and how far along it is.')}"></textarea></label>`}
          <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
          <p class="form-note">${bug ? t('Bug reports are only seen by the developer.') : t('Only the RecompStore developer sees this. Your email is used just to talk about your port.')}</p>
          <div class="form-row"><button class="btn primary" type="submit">${bug ? t('Send the report') : t('Send it')}</button><span class="status" role="status"></span></div>
        </form>
      </article>`;
    setBackdrop(null);
    wireForm(view.querySelector('form'), kind,
      bug ? [['app', t('Choose the game the bug is in.')], ['body', t('Tell us what happened.')]]
          : [['name', t('Add your name or handle.')], ['email', t('Add your email.')], ['repo', t('Add the GitHub repository link.')], ['body', t('Tell us about the port.')]],
      bug ? [t('Thanks, the report is in.'), t('It went straight to the developer.')] : [t('Thanks, it\'s on its way.'), t('We\'ll be in touch at the email you gave.')]);
  }

  // (2.1) Submit a game port, emulator, app or tool: its repo, the details for its page and, if the
  // developer wants, its pictures. Lands in the admin's Inbox, ready to add to the store as a draft.
  function submitPage() {
    const kinds = [['games', t('Game port'), t('A recompiled or ported game')], ['emulators', t('Emulator'), t('Plays other consoles\' games')],
      ['apps', t('App'), t('Media, streaming, anything else')], ['tools', t('Tool'), t('For the PS5 itself: files, payloads, mods')]];
    view.innerHTML = `
      <article class="form-page submit-page">
        <a class="back" href="/contact">${backArrow()} ${t('Contact')}</a>
        <h1>${t('Submit to RecompStore')}</h1>
        <p class="lead">${t('Made something for jailbroken PS5? Send it in. Everything you fill in here goes on its page in the store, so the more you give, the faster it can go live. Only the RecompStore developer sees this form.')}</p>
        <div data-sub-who></div>
        <form class="glass form" novalidate enctype="multipart/form-data">
          <div class="sub-repos" data-sub-repos hidden></div>
          <fieldset class="kinds plats"><legend>${t('Where does it run?')}</legend>
            ${[['ps5', 'PS5', t('A homebrew app for jailbroken PS5')], ['pc', 'PC', t('A recomp for Windows, Linux or Mac')]].map(([k, n, d]) => `<label class="kind"><input type="radio" name="platform" value="${k}"${(k === 'pc') === (new URLSearchParams(location.search).get('platform') === 'pc') ? ' checked' : ''}><span><strong>${n}</strong><small>${d}</small></span></label>`).join('')}
          </fieldset>
          <fieldset class="kinds" data-ps5-only><legend>${t('What is it?')}</legend>
            ${kinds.map(([k, n, d], i) => `<label class="kind"><input type="radio" name="kind" value="${k}"${i === 0 ? ' checked' : ''}><span><strong>${n}</strong><small>${d}</small></span></label>`).join('')}
          </fieldset>
          <h2>${t('The basics')}</h2>
          <div class="two">
            <label>${t('Name')}<input name="name" maxlength="120" required placeholder="${t('What players will see')}"></label>
            <label>${t('Made by')}<input name="author" maxlength="80" required autocomplete="nickname" placeholder="${t('Your name or handle')}"></label>
            <label>${t('How can we reach you?')}<input name="contact" maxlength="200" required placeholder="${t('Email or Discord name')}"></label>
            <label>${t('GitHub repository')}<input name="repo" maxlength="255" required placeholder="https://github.com/you/your-app" inputmode="url" spellcheck="false"></label>
            <label data-ps5-only>${t('Release file')} <span class="opt">${t('optional')}</span><input name="asset" maxlength="160" placeholder="MyApp-*.zip" spellcheck="false"><small>${t('The .zip or .7z on your GitHub release the store should install. Put * for the part that changes with each version.')}</small></label>
            <label>${t('Version')} <span class="opt">${t('optional')}</span><input name="version" maxlength="40" placeholder="1.0.0"></label>
            <label data-ps5-only>${t('Title ID')} <span class="opt">${t('optional')}</span><input name="title_id" maxlength="9" placeholder="PPSA12345" spellcheck="false" class="mono"><small>${tf('Already have one? Check it doesn\'t clash on the {link}. No? We\'ll pick one with you.', { link: `<a href="/ids" target="_blank">${t('Title IDs page')}</a>` })}</small><small class="tid-check" data-tid-check role="status"></small></label>
          </div>
          <h2>${t('Its page in the store')}</h2>
          <label>${t('One-line summary')}<input name="blurb" maxlength="160" placeholder="${t('Shown under its name on Discover')}"></label>
          <label>${t('Description')}<textarea name="description" rows="7" maxlength="6000" required placeholder="${t('What it is, what works, what players should know.')}"></textarea></label>
          <label data-needs>${t('What players need')} <span class="opt">${t('optional')}</span><input name="needs" maxlength="160" placeholder="${t('For example: your own Xbox 360 disc image')}"></label>
          <label>${t('Credits')} <span class="opt">${t('optional')}</span><textarea name="credits" rows="2" maxlength="1000" placeholder="${t('Who made what, and what it builds on')}"></textarea></label>
          <fieldset class="sub-ai" data-pc-only><legend>${t('Did AI tools help make it?')}</legend>
            <p class="form-note">${t('Code, art, writing or voices made with help from AI (Claude, ChatGPT, Copilot, Cursor...). If yes, its page on RecompLauncher says AI-assisted, and people can choose to hide AI-assisted recomps. Be honest: we look at its GitHub too.')}</p>
            <div class="ai-yn"><label class="check"><input type="radio" name="ai" value="no"> ${t('No')}</label><label class="check"><input type="radio" name="ai" value="yes"> ${t('Yes')}</label></div>
            <div class="ai-yes" hidden>
              <div class="ai-uses"><span class="muted">${t('What for?')}</span>${[['code', t('Code')], ['art', t('Art')], ['text', t('Writing')], ['audio', t('Voice or music')]].map(([k, n]) => `<label class="check"><input type="checkbox" name="ai_uses[]" value="${k}"${k === 'code' ? ' checked' : ''}> ${n}</label>`).join('')}</div>
              <label>${t('In a few words')} <span class="opt">${t('optional')}</span><input name="ai_note" maxlength="200" placeholder="${t('Claude helped write the renderer')}"></label>
            </div>
          </fieldset>
          <div class="three">
            <label>${t('Web link')} <span class="opt">${t('optional')}</span><input name="link1" maxlength="300" placeholder="https://discord.gg/..." inputmode="url"></label>
            <label>${t('Web link')} <span class="opt">${t('optional')}</span><input name="link2" maxlength="300" placeholder="https://ko-fi.com/..." inputmode="url"></label>
            <label>${t('Web link')} <span class="opt">${t('optional')}</span><input name="link3" maxlength="300" placeholder="https://your.site" inputmode="url"></label>
          </div>
          <h2>${t('Pictures')} <span class="opt">${t('optional')}</span></h2>
          <p class="form-note">${t('PNG, JPEG or WebP, 8 MB each. No pictures? They can be added later.')}</p>
          <div class="pics">
            <label class="pic">${t('Icon')}<input type="file" name="icon" accept="image/png,image/jpeg,image/webp" data-pic="icon"><small>${t('Square, 512×512 or bigger.')}</small><span class="pv icon" data-pv="icon" aria-hidden="true"></span></label>
            <label class="pic">${t('Backdrop')}<input type="file" name="hero" accept="image/png,image/jpeg,image/webp" data-pic="hero"><small>${t('16:9, ideally 3840×2160. Behind its page and on Discover.')}</small><span class="pv hero" data-pv="hero" aria-hidden="true"><span class="pv-logo" data-pv="logo"></span></span></label>
            <label class="pic">${t('Logo')}<input type="file" name="logo" accept="image/png,image/webp" data-pic="logo"><small>${t('Its title as a picture, on a transparent background. Shown over the backdrop.')}</small></label>
            <label class="pic">${t('Screenshots')}<input type="file" name="shots[]" accept="image/png,image/jpeg,image/webp" multiple data-pic="shots"><small>${t('Up to 4, from the PS5.')}</small><span class="pv shots" data-pv="shots" aria-hidden="true"></span></label>
          </div>
          <label>${t('Anything else?')} <span class="opt">${t('optional')}</span><textarea name="notes" rows="3" maxlength="2000" placeholder="${t('Anything the developer of RecompStore should know')}"></textarea></label>
          <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
          <label class="check"><input type="checkbox" name="rights" value="1" required> ${t('I made this, or I\'m allowed to submit it. It doesn\'t include game files or anything else copyrighted.')}</label>
          <label class="check"><input type="checkbox" name="news" value="1"> ${t('Email me when there\'s something new for developers. Unsubscribe anytime.')}</label>
          <div class="form-row"><button class="btn primary" type="submit">${t('Send it')}</button><span class="status" role="status"></span></div>
        </form>
      </article>`;
    setBackdrop(null);
    const form = view.querySelector('form');
    const status = form.querySelector('.status');
    const needs = form.querySelector('[data-needs]');
    // (2.7.3) Signed in as a developer: their details filled in, their repos to pick from, and the
    // app read from the repo's latest release (name, version, file, title ID). Not signed in: a
    // word about developer accounts.
    let dev = null;
    let myIds = []; // (2.7.3) the title IDs this developer reserved: theirs to use
    const who = view.querySelector('[data-sub-who]');
    const repoBox = form.querySelector('[data-sub-repos]');
    const tidNote = form.querySelector('[data-tid-check]');
    const setIf = (k, v) => { const el = form.querySelector(`[name="${k}"]`); if (el && v && !el.value.trim()) el.value = v; };
    const devGet = (a, extra) => fetch('/api/dev.php?a=' + a + (extra || ''), { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null);
    let ids = null;
    const idsLoad = () => ids || (ids = fetch('/api/ids.php', { cache: 'no-cache' }).then((r) => r.json()).catch(() => null));
    const checkTid = (id, clash) => {
      id = String(id || '').trim().toUpperCase();
      if (!id) { tidNote.textContent = ''; tidNote.className = 'tid-check'; return; }
      if (!/^[A-Z]{4}\d{5}$/.test(id)) { tidNote.textContent = t('A title ID is four letters and five numbers, like PPSA12345.'); tidNote.className = 'tid-check bad'; return; }
      const repo = String(form.repo.value || '').replace(/^https?:\/\/(www\.)?github\.com\//i, '').replace(/\/+$/, '').toLowerCase();
      const say = (bad, text) => { tidNote.textContent = text; tidNote.className = 'tid-check ' + (bad ? 'bad' : 'ok'); };
      if (myIds.includes(id) && !(clash && clash.length)) { say(false, tf('{id} is reserved for you.', { id })); return; }
      if (clash && clash.length) { say(true, tf('{id} clashes: {what}. Two apps with one title ID write over each other on a PS5. Pick another.', { id, what: clash.map((c) => c.name + (c.repo ? ` (${c.repo})` : '')).join(', ') })); return; }
      idsLoad().then((d) => {
        if (!d || form.title_id.value.trim().toUpperCase() !== id) return;
        const b = d.block || {};
        const hit = (d.store || []).find((x) => x.id === id) || (d.taken || []).find((x) => x.id === id) || (d.github || []).find((x) => x.id === id && String(x.repo).toLowerCase() !== repo)
          || (myIds.includes(id) ? null : (d.reserved || []).map((x) => ({ id: x.id, name: tf('Reserved by {name}', { name: x.by }) })).find((x) => x.id === id));
        if (hit) say(true, tf('{id} is already used: {what}. Pick another, or leave it empty and we\'ll pick one with you.', { id, what: hit.name || hit.note || t('another app') }));
        else if (id >= b.from && id <= b.to) say(true, tf('{id} is kept by the store for apps it moves. Pick another.', { id }));
        else say(false, tf('{id} is free as far as we know.', { id }));
      });
    };
    form.title_id.addEventListener('change', () => checkTid(form.title_id.value));
    const lookup = (repo) => {
      if (!dev || !repo) return;
      const st = repoBox.querySelector('.status') || status;
      st.textContent = t('Reading its latest release…'); st.className = 'status';
      devGet('lookup', '&repo=' + encodeURIComponent(repo)).then((j) => {
        if (!j || !j.ok) { st.textContent = (j && j.error) || t('That didn\'t go through.'); st.className = 'status bad'; return; }
        const a = j.app || {};
        form.repo.value = 'https://github.com/' + a.repo;
        setIf('name', a.name); setIf('version', a.version); setIf('asset', a.asset); setIf('title_id', a.titleId); setIf('blurb', a.blurb);
        st.textContent = j.found ? t('Filled in from its latest release. Check it over.') : (j.note || t('No PS5 build in its releases yet. Fill in the rest by hand.'));
        st.className = 'status ' + (j.found ? 'ok' : 'bad');
        checkTid(form.title_id.value, j.clash);
      });
    };
    devGet('me').then((j) => {
      if (!view.contains(form)) return;
      if (!j || !j.account) {
        who.innerHTML = `<aside class="glass sub-dev"><div><strong>${t('Make apps for PS5? Get a developer account')}</strong>
          <span>${t('It\'s free. Sign in and this form fills itself in from your GitHub, and you get your own verified page, install numbers, players\' logs and betas.')}</span></div>
          <div class="sub-dev-btns"><a class="btn primary" href="/developers?signup=1">${t('Create a developer account')}</a><a class="btn" href="/developers?signin=1">${t('Sign in')}</a></div></aside>`;
        return;
      }
      dev = j;
      const acc = j.account, verified = j.profile && j.profile.verified;
      who.innerHTML = `<aside class="glass sub-dev in"><div><strong>${tf('Sending in as {name}', { name: esc((j.profile && j.profile.name) || acc.name || acc.login) })}${verified ? ` <span class="verified-pill">${t('Verified developer')}</span>` : ''}</strong>
        <span>${t('Your details are filled in, and you can follow it in your developer hub.')}</span></div><div class="sub-dev-btns"><a class="btn" href="/hub">${t('Your hub')}</a></div></aside>`;
      setIf('author', (j.profile && j.profile.name) || acc.name || acc.login);
      setIf('contact', acc.email);
      if (acc.status === 'approved') devGet('ids').then((r) => {
        myIds = ((r && r.ids) || []).filter((x) => !x.used).map((x) => x.id);
        if (!myIds.length) return;
        const dl = document.createElement('datalist');
        dl.id = 'my-title-ids';
        dl.innerHTML = myIds.map((x) => `<option value="${esc(x)}">${t('Reserved for you')}</option>`).join('');
        form.appendChild(dl);
        form.title_id.setAttribute('list', 'my-title-ids');
        if (myIds.length === 1) setIf('title_id', myIds[0]);
        checkTid(form.title_id.value);
      });
      if (!acc.github) return;
      repoBox.hidden = false;
      repoBox.innerHTML = `<label>${t('Pick one of your GitHub repos')}<select data-repo-pick><option value="">${t('Loading your repos…')}</option></select><small>${t('We read its latest release and fill in what we can.')}</small></label><span class="status" role="status"></span>`;
      devGet('repos').then((r) => {
        const sel = repoBox.querySelector('[data-repo-pick]');
        const list = (r && r.repos) || [];
        sel.innerHTML = `<option value="">${list.length ? t('Choose a repo') : t('No public repos found')}</option>` + list.map((x) => `<option value="${esc(x.repo)}"${x.inStore ? ' disabled' : ''}>${esc(x.repo)}${x.inStore ? ' · ' + t('already in the store') : ''}</option>`).join('');
        sel.addEventListener('change', () => lookup(sel.value));
      });
    });
    form.repo.addEventListener('change', () => { if (dev && /github\.com\/[^/]+\/[^/]+|^[\w.-]+\/[\w.-]+$/i.test(form.repo.value.trim())) lookup(form.repo.value.trim()); });
    const kindNow = () => (form.querySelector('input[name="kind"]:checked') || {}).value;
    // (2.8) A PC recomp: no title ID or PS5 release file, and always a game.
    const platNow = () => (form.querySelector('input[name="platform"]:checked') || {}).value || 'ps5';
    const showPlat = () => {
      const pc = platNow() === 'pc';
      form.querySelectorAll('[data-ps5-only]').forEach((el) => { el.hidden = pc; });
      form.querySelectorAll('[data-pc-only]').forEach((el) => { el.hidden = !pc; });
      if (pc) { const g = form.querySelector('input[name="kind"][value="games"]'); if (g) g.checked = true; }
      const subLead = view.querySelector('.submit-page > .lead');
      if (subLead) subLead.textContent = pc ? t('Made a PC recomp? Send it in for RecompLauncher. Everything you fill in here goes on its page, so the more you give, the faster it can go live. Only the RecompStore developer sees this form.')
        : t('Made something for jailbroken PS5? Send it in. Everything you fill in here goes on its page in the store, so the more you give, the faster it can go live. Only the RecompStore developer sees this form.');
      needs.hidden = kindNow() !== 'games';
    };
    showPlat();
    form.addEventListener('change', (e) => {
      if (e.target.name === 'platform') showPlat();
      if (e.target.name === 'kind') needs.hidden = kindNow() !== 'games';
      if (e.target.name === 'ai') form.querySelector('.sub-ai .ai-yes').hidden = e.target.value !== 'yes';
      const pic = e.target.dataset && e.target.dataset.pic;
      if (!pic) return;
      const files = [...e.target.files];
      const url = (f) => URL.createObjectURL(f);
      if (pic === 'shots') {
        const box = form.querySelector('[data-pv="shots"]');
        box.innerHTML = files.slice(0, 4).map((f) => `<img src="${url(f)}" alt="">`).join('');
        if (files.length > 4) { status.textContent = t('Four screenshots at most: only the first four go.'); status.className = 'status bad'; }
        return;
      }
      const pv = form.querySelector(`[data-pv="${pic}"]`);
      if (pv) pv.style.backgroundImage = files[0] ? `url("${url(files[0])}")` : '';
    });
    const need = [['name', t('Give it a name.')], ['author', t('Say who made it.')], ['contact', t('Leave an email or a Discord name.')],
      ['repo', t('Add the GitHub repository.')], ['description', t('Add a description.')]];
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const fd = new FormData(form);
      for (const [k, msg] of need) if (!String(fd.get(k) || '').trim()) { status.textContent = msg; status.className = 'status bad'; form.querySelector(`[name="${k}"]`).focus(); return; }
      if (fd.get('platform') === 'pc' && !fd.get('ai')) { status.textContent = t('Say whether AI tools helped make it.'); status.className = 'status bad'; form.querySelector('input[name=ai]').focus(); return; }
      if (!fd.get('rights')) { status.textContent = t('Tick the box to confirm you made it or may submit it.'); status.className = 'status bad'; return; }
      const shots = fd.getAll('shots[]').filter((f) => f && f.size);
      fd.delete('shots[]');
      shots.slice(0, 4).forEach((f) => fd.append('shots[]', f));
      const btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      status.textContent = t('Sending…'); status.className = 'status';
      if (dev) fd.append('a', 'submit');
      const sentName = String(fd.get('author') || ''), sentMail = String(fd.get('contact') || '');
      fetch(dev ? '/api/dev.php' : '/api/submit.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then((r) => r.json().catch(() => ({ ok: false })))
        .then((res) => {
          if (res.ok && dev) form.innerHTML = `<div class="sent"><strong>${t('Thanks, it\'s in.')}</strong><p>${esc(res.message || '')}</p><a class="btn primary" href="/hub">${t('Open your developer hub')}</a></div>`;
          else if (res.ok) {
            form.innerHTML = `<div class="sent"><strong>${t('Thanks, it\'s in.')}</strong><p>${esc(res.message || '')}</p>
              <div class="sub-next glass"><strong>${t('Next: get your developer account')}</strong><span>${t('Follow your app, see how many PS5s have it, read players\' logs and run betas. It takes a minute.')}</span>
              <a class="btn primary" href="/developers?signup=1" data-go-signup>${t('Create a developer account')}</a></div>
              <a class="btn" href="/">${t('Back to the store')}</a></div>`;
            const go = form.querySelector('[data-go-signup]');
            if (go) go.addEventListener('click', () => { try { sessionStorage.setItem('rs_signup', JSON.stringify({ name: sentName, email: /@/.test(sentMail) ? sentMail : '' })); } catch (e) { /* fine */ } });
          }
          else { status.textContent = res.error || t('That didn\'t send. Try again in a moment.'); status.className = 'status bad'; btn.disabled = false; }
        })
        .catch(() => { status.textContent = t('That didn\'t send. Check your connection and try again.'); status.className = 'status bad'; btn.disabled = false; });
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
      status.textContent = t('Sending…'); status.className = 'status';
      fetch('/api/inbox.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
        .then((r) => r.json().catch(() => ({ ok: false })))
        .then((res) => {
          if (res.ok) {
            form.innerHTML = `<div class="sent"><strong>${sent[0]}</strong><p>${sent[1]}</p><a class="btn" href="/">${t('Back to all games')}</a></div>`;
          } else {
            status.textContent = res.error || t('That didn\'t send. Try again in a moment.'); status.className = 'status bad'; btn.disabled = false;
          }
        })
        .catch(() => { status.textContent = t('That didn\'t send. Check your connection and try again.'); status.className = 'status bad'; btn.disabled = false; });
    });
  }

  // ---- Contact: the two forms, a message, and the email address ---------------------------
  const CONTACT_EMAIL = 'info@recompstore.com';
  function contactPage() {
    view.innerHTML = `
      <article class="page contact">
        <header class="page-head center">
          <span class="kicker">RecompStore</span>
          <h1>${t('Contact')}</h1>
          <p class="lead">${t('Found a bug, made a recomp, or have a question? Here\'s how to reach us.')}</p>
        </header>
        <section class="help contact-cards">
          <a class="glass help-card" href="/report">
            <span class="kicker">${t('Found a bug?')}</span>
            <strong>${t('Report it')}</strong>
            <span>${t('Pick the game and tell us what happened. It goes straight to the developer.')}</span>
          </a>
          <a class="glass help-card" href="/submit">
            <span class="kicker">${t('Made something for PS5?')}</span>
            <strong>${t('Submit to RecompStore')}</strong>
            <span>${t('A game port, an emulator, an app or a tool, with its details and pictures.')}</span>
          </a>
          <a class="glass help-card" href="mailto:${CONTACT_EMAIL}">
            <span class="kicker">${t('Email us')}</span>
            <strong class="mail">${CONTACT_EMAIL}</strong>
            <span>${t('For questions and anything else.')}</span>
          </a>
        </section>
        <h2 class="contact-h2">${t('Send a message')}</h2>
        <form class="glass form contact-form" novalidate>
          <label>${t('Your name or handle')}<input name="name" maxlength="120" required autocomplete="nickname"></label>
          <label>${t('Email')}<input name="email" type="email" maxlength="200" required autocomplete="email" placeholder="${t('So we can reply')}"></label>
          <label>${t('Message')}<textarea name="body" rows="6" maxlength="5000" required></textarea></label>
          <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
          <p class="form-note">${t('Only the RecompStore developer sees this. Your email is used just to reply.')}</p>
          <div class="form-row"><button class="btn primary" type="submit">${t('Send the message')}</button><span class="status" role="status"></span></div>
        </form>
      </article>`;
    setBackdrop(null);
    wireForm(view.querySelector('form'), 'message',
      [['name', t('Add your name or handle.')], ['email', t('Add your email.')], ['body', t('Write your message.')]],
      [t('Thanks, your message is in.'), t('We\'ll reply to the email you gave.')]);
  }


  // ---- (2.9) Copyright & DMCA: what we host (no games), how to send a notice or a counter-notice ----
  // The legal text stays in English, as notices are handled in English.
  // (2.9) AI-assisted: a label on its page, and a note saying what for and who told us.
  const AI_USE_WORDS = { code: N_('code'), art: N_('art'), text: N_('writing'), audio: N_('voice or music') };
  const aiUses = (a) => {
    const words = ((a.ai && a.ai.uses) || ['code']).map((u) => t(AI_USE_WORDS[u] || u));
    try { return new Intl.ListFormat(locale(), { style: 'long', type: 'conjunction' }).format(words); } catch (e) { return words.join(', '); }
  };
  const aiChip = (a, pc) => (isAI(a) ? `<a class="${pc ? 'pc-pill' : 'tag'} ai-tag" href="#ai-note" title="${esc(tf('AI tools helped with its {uses}.', { uses: aiUses(a) }))}">${SVG_AI}${t('AI-assisted')}</a>` : '');
  const SVG_AI = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5l1.9 5.1 5.1 1.9-5.1 1.9L12 17.5l-1.9-5.1L5 10.5l5.1-1.9z"/><path d="M18.5 15.5l.8 2.2 2.2.8-2.2.8-.8 2.2-.8-2.2-2.2-.8 2.2-.8z"/></svg>';
  function aiNoteHTML(a, pc) {
    if (!isAI(a)) return '';
    const by = a.ai.by === 'developer' ? tf('Its developer says AI tools helped with its {uses}.', { uses: aiUses(a) }) : tf('Its project on GitHub shows AI tools helped with its {uses}.', { uses: aiUses(a) });
    const set = '/pc/settings';
    return `<section class="glass ai-note" id="ai-note"><span class="kicker">${SVG_AI}${t('AI-assisted')}</span>
      <p>${esc(by)}</p>${a.ai.note ? `<p class="ai-quote" dir="auto">“${esc(a.ai.note)}”</p>` : ''}
      <p class="ai-set">${hideAI ? `${t('You\'ve hidden AI-assisted recomps, so this one doesn\'t show anywhere else on RecompLauncher.')} <a href="${set}">${t('Settings')}</a>` : `${t('Rather not see AI-assisted recomps?')} <a href="${set}">${t('Hide them in Settings')}</a>`}</p></section>`;
  }

  // (2.9) Settings: choices for this browser, on both sites.
  function settingsPage(pc) {
    if (pc) pcTitle = `${t('Settings')} · RecompLauncher`;
    setBackdrop(null);
    view.innerHTML = `
      <article class="page settings-page">
        <header class="page-head center">
          <h1>${t('Settings')}</h1>
          <p class="lead">${t('Saved in this browser.')}</p>
        </header>
        <section class="glass set-card">
          <div class="set-row">
            <div class="set-words"><h2>${SVG_AI}${t('Hide AI-assisted recomps')}</h2>
              <p>${t('Recomps made with help from AI tools, for their code, art, writing or voices, are labeled AI-assisted. Turn this on and they won\'t show anywhere on RecompLauncher: not in lists, search, the banner or the charts.')}</p></div>
            <label class="switch"><input type="checkbox" data-hide-ai${hideAI ? ' checked' : ''}><span class="switch-track" aria-hidden="true"></span><span class="sr-only">${t('Hide AI-assisted recomps')}</span></label>
          </div>
          <p class="set-status" data-ai-status role="status"></p>
          <details class="set-more"><summary>${t('How apps get labeled')}</summary>
            <p>${t('Developers tell us when they send a recomp in, and can change it in their developer hub. We also look at each project on GitHub for signs of AI tools, like their files in the project or changes credited to them. The label only covers what we know: a recomp without it may still have used AI.')}</p></details>
        </section>
        <section class="glass set-card">
          <div class="set-row set-row-os">
            <div class="set-words"><h2>${t('Show recomps for')}</h2><p>${t('Only recomps with a build for your system show anywhere on RecompLauncher.')}</p></div>
            ${osPickerHTML('page')}
          </div>
        </section>
        <section class="glass set-card">
          <div class="set-row">
            <div class="set-words"><h2>${GLOBE}${t('Language')}</h2><p>${t('The language the site is in.')}</p></div>
            <div class="set-lang" data-set-lang></div>
          </div>
        </section>
      </article>`;
    const langBox = view.querySelector('[data-set-lang]');
    langBox.innerHTML = `<select aria-label="${t('Language')}">${LANGS.map(([c, n]) => `<option value="${c}" lang="${c}"${c === lang ? ' selected' : ''}>${n}</option>`).join('')}</select>`;
    langBox.querySelector('select').addEventListener('change', (e) => setLang(e.target.value));
    const st = view.querySelector('[data-ai-status]');
    const say = () => pcLoad().then(() => {
      const n = ((pcCat && pcCat.all) || []).filter(isAI).length;
      st.textContent = hideAI ? tn(n, '1 AI-assisted recomp is hidden.', '{n} AI-assisted recomps are hidden.') : tn(n, '1 recomp is labeled AI-assisted right now.', '{n} recomps are labeled AI-assisted right now.');
    });
    say();
    wireAiSwitch(view);
    wireOs(view);
  }

  function dmcaPage() {
    const GH_DMCA = 'https://docs.github.com/en/site-policy/content-removal-policies/dmca-takedown-policy';
    view.innerHTML = `
      <article class="page dmca" lang="en" dir="ltr">
        <header class="page-head center">
          <span class="kicker">RecompStore &amp; RecompLauncher</span>
          <h1>Copyright &amp; DMCA</h1>
          <p class="lead">How we handle copyright, and how to report a problem.</p>
        </header>
        <section class="glass dmca-nogames">
          <h2>We don't host games.</h2>
          <p>RecompStore and RecompLauncher don't host, sell or share any games. No ROMs, no ISOs, no disc images, no game files and no game content of any kind.</p>
          <p>Each listing points to a fan project published by its own developer on GitHub, and every one of them needs your own legally owned copy of the game. We don't accept submissions that include game files, and we take down any listing found to include them.</p>
          <p class="small">Neither site is affiliated with or endorsed by Sony Interactive Entertainment, Microsoft, Nintendo, or any game's publisher or developer. All trademarks belong to their owners.</p>
        </section>
        <div class="dmca-cols">
          <section class="glass card-pad">
            <h2>Report a copyright problem</h2>
            <p>If you own the rights to something you believe is listed here without permission, send a notice with the form below, or by email to <a href="mailto:${CONTACT_EMAIL}">${CONTACT_EMAIL}</a>. It needs:</p>
            <ol class="dmca-list">
              <li>Your name and contact details, and who you represent if you're acting for someone else.</li>
              <li>The copyrighted work you believe is being infringed.</li>
              <li>The link to the listing on recompstore.com or recomplauncher.com.</li>
              <li>A statement that you believe in good faith the use isn't authorized by the owner, its agent or the law.</li>
              <li>A statement that the notice is accurate and, under penalty of perjury, that you're the owner or authorized to act for them.</li>
              <li>Your signature. Typing your full name is fine.</li>
            </ol>
          </section>
          <section class="glass card-pad">
            <h2>What happens next</h2>
            <ul class="dmca-list">
              <li>We hide the listing while we look into it, and let its developer know.</li>
              <li>If the notice holds up, the listing stays off both sites.</li>
              <li>Developers who get repeated valid notices are removed for good.</li>
              <li>Taking a listing off our sites doesn't remove the project from GitHub. To have the project itself taken down, contact <a href="${GH_DMCA}" target="_blank" rel="noopener">GitHub</a>.</li>
            </ul>
            <h2>Counter-notice</h2>
            <p>If your listing was taken down and you believe it was a mistake, send a counter-notice with the form below. It needs your name, address and phone number, the listing that was removed, a statement under penalty of perjury that it was removed by mistake or misidentification, your consent to the jurisdiction of the federal court for your address (or, outside the US, any district where we can be found) and to accept service from the person who sent the notice, and your signature. We pass it on to them and may put the listing back after 10 to 14 business days, unless they tell us they've gone to court.</p>
          </section>
        </div>
        <p class="dmca-warn">Knowingly false notices or counter-notices can make you liable for damages (17 U.S.C. § 512(f)).</p>
        <h2 class="contact-h2">Send a notice</h2>
        <form class="glass form dmca-form" novalidate>
          <fieldset class="kinds dmca-kinds"><legend>What are you sending?</legend>
            <label class="kind"><input type="radio" name="notice" value="takedown" checked><span><strong>Copyright notice</strong><small>Something listed here infringes a work you own.</small></span></label>
            <label class="kind"><input type="radio" name="notice" value="counter"><span><strong>Counter-notice</strong><small>Your listing was taken down by mistake.</small></span></label>
          </fieldset>
          <div class="dmca-two">
            <label>Your full name<input name="name" maxlength="120" required autocomplete="name"></label>
            <label>Email<input name="email" type="email" maxlength="200" required autocomplete="email"></label>
          </div>
          <label data-for="takedown">On behalf of <span class="opt">(optional: the company or person you represent)</span><input name="company" maxlength="200" autocomplete="organization"></label>
          <label data-for="counter" hidden>Address and phone number<textarea name="address" rows="3" maxlength="500"></textarea></label>
          <label>Link to the listing<input name="url" type="url" maxlength="300" required placeholder="https://recomplauncher.com/game/..."></label>
          <label data-for="takedown">The copyrighted work<textarea name="work" rows="3" maxlength="2000" placeholder="What you own, and where it can be found"></textarea></label>
          <label>Details <span class="opt">(optional)</span><textarea name="body" rows="4" maxlength="5000"></textarea></label>
          <label class="check" data-for="takedown"><input type="checkbox" name="good_faith" value="1"> I believe in good faith that the use described isn't authorized by the copyright owner, its agent or the law.</label>
          <label class="check" data-for="takedown"><input type="checkbox" name="accurate" value="1"> The information in this notice is accurate and, under penalty of perjury, I am the owner or authorized to act on the owner's behalf.</label>
          <label class="check" data-for="counter" hidden><input type="checkbox" name="mistake" value="1"> Under penalty of perjury, I believe in good faith the listing was removed by mistake or misidentification.</label>
          <label class="check" data-for="counter" hidden><input type="checkbox" name="consent" value="1"> I consent to the jurisdiction of the federal district court for my address (or, outside the US, any district where RecompStore can be found), and I'll accept service of process from the person who sent the notice.</label>
          <label>Signature <span class="opt">(type your full name)</span><input name="signature" maxlength="120" required></label>
          <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
          <p class="form-note">Only the site's owner sees this. A counter-notice, including your contact details, is passed on to the person who sent the notice.</p>
          <div class="form-row"><button class="btn primary" type="submit">Send</button><span class="status" role="status"></span></div>
        </form>
      </article>`;
    setBackdrop(null);
    const form = view.querySelector('.dmca-form');
    const kindNow = () => form.querySelector('input[name=notice]:checked').value;
    const sync = () => form.querySelectorAll('[data-for]').forEach((el) => { el.hidden = el.dataset.for !== kindNow(); });
    form.querySelectorAll('input[name=notice]').forEach((r) => r.addEventListener('change', sync));
    const status = form.querySelector('.status');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const data = Object.fromEntries(new FormData(form).entries());
      data.kind = 'copyright';
      const k = kindNow();
      const need = [['name', 'Add your full name.'], ['email', 'Add your email.'], ['url', 'Add the link to the listing.']]
        .concat(k === 'takedown' ? [['work', 'Describe the copyrighted work.'], ['good_faith', 'Tick the good-faith statement.'], ['accurate', 'Tick the accuracy statement.']]
          : [['address', 'Add your address and phone number.'], ['mistake', 'Tick the statement about the mistake.'], ['consent', 'Tick the consent to jurisdiction.']])
        .concat([['signature', 'Type your full name as your signature.']]);
      for (const [f, msg] of need) if (!String(data[f] || '').trim()) { status.textContent = msg; status.className = 'status bad'; const el = form.querySelector(`[name="${f}"]`); if (el) el.focus(); return; }
      const btn = form.querySelector('button[type=submit]');
      btn.disabled = true; status.textContent = 'Sending…'; status.className = 'status';
      fetch('/api/inbox.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) })
        .then((r) => r.json().catch(() => ({ ok: false })))
        .then((res) => {
          if (res.ok) form.innerHTML = `<div class="sent"><strong>Thanks, it's in.</strong><p>We'll reply to the email you gave.</p></div>`;
          else { status.textContent = res.error || 'That didn\'t send. Try again in a moment.'; status.className = 'status bad'; btn.disabled = false; }
        })
        .catch(() => { status.textContent = 'That didn\'t send. Check your connection and try again.'; status.className = 'status bad'; btn.disabled = false; });
    });
  }

  // ---- Title IDs (2.0): what's taken, so two apps never share one on a PS5 ----------------
  function idsPage() {
    // (2.7.3) A lookup for everyone: search an ID, an app or a developer, then every ID in use below.
    // Reserving one is for developer accounts, in their hub.
    const SEARCH = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg>';
    view.innerHTML = `
      <article class="page ids2">
        <header class="ids2-head">
          <span class="kicker">${t('For developers')}</span>
          <h1>${t('Title ID lookup')}</h1>
          <p class="lead">${t('Two apps with the same title ID clash on a PS5: installing one writes over the other. Check yours before you release.')}</p>
          <label class="ids2-search glass">${SEARCH}<input type="search" name="q" placeholder="${t('Search a title ID, app or developer')}" autocomplete="off" spellcheck="false" aria-label="${t('Search a title ID, app or developer')}"></label>
          <div class="ids2-verdict" data-verdict aria-live="polite"></div>
        </header>
        <div class="ids2-stats" data-stats></div>
        <div class="ids2-bar"><nav class="ids2-tabs" role="tablist" data-tabs></nav><span class="muted ids2-note" data-checked></span></div>
        <section class="glass ids2-list" data-list><p class="muted">${t('Loading the list…')}</p></section>
        <aside class="glass ids2-dev">
          <div><strong>${t('Making an app?')}</strong><span>${t('Developers get a free title ID in their developer hub, held for them and shown here as reserved.')}</span></div>
          <a class="btn" href="/developers">${t('Developer accounts')}</a>
        </aside>
      </article>`;
    setBackdrop(null);
    const page = view.querySelector('.ids2');
    const input = page.querySelector('[name="q"]');
    const listEl = page.querySelector('[data-list]');
    const verdict = page.querySelector('[data-verdict]');
    const KINDS = { store: t('In RecompStore'), test: t('Coming'), reserved: t('Reserved'), github: t('Elsewhere'), taken: t('Elsewhere') };
    let rows = [], block = {}, tab = 'all', data = null;
    const pending = new URLSearchParams(location.search).get('q') || '';
    const draw = () => {
      const q = input.value.trim().toLowerCase();
      const shown = rows.filter((r) => (tab === 'all' || r.group === tab) && (!q || r.text.includes(q)));
      listEl.innerHTML = shown.length ? `<ol class="ids2-rows">${shown.slice(0, 600).map((r) => `
        <li>${r.href ? `<a href="${esc(r.href)}"${/^https:/.test(r.href) ? ' target="_blank" rel="noopener"' : ''}>` : '<div>'}
          <code>${esc(r.id)}</code>
          <span class="ids2-app">${r.icon ? `<img src="${esc(r.icon)}" alt="" width="36" height="36" loading="lazy">` : `<span class="ids2-ico">${esc((r.name || '?').slice(0, 1))}</span>`}<span><strong>${esc(r.name)}</strong>${r.by ? `<small>${esc(r.by)}</small>` : ''}</span></span>
          <em class="ids2-tag ${r.kind}">${KINDS[r.kind]}</em>
        ${r.href ? '</a>' : '</div>'}</li>`).join('')}</ol>${shown.length > 600 ? `<p class="muted">${tf('{n} more: search to narrow it down.', { n: fmtNum(shown.length - 600) })}</p>` : ''}`
        : `<p class="muted ids2-empty">${q ? t('Nothing in use matches that.') : t('Nothing here yet.')}</p>`;
      // A whole title ID typed: free or taken, in one line.
      const id = input.value.trim().toUpperCase();
      if (/^[A-Z]{4}\d{5}$/.test(id) && data) {
        const hits = rows.filter((r) => r.id === id);
        if (hits.length) verdict.innerHTML = `<p class="ids2-v taken"><code>${esc(id)}</code> ${t('is taken')}: ${hits.map((h) => `<strong>${esc(h.name)}</strong>${h.by ? ' · ' + esc(h.by) : ''}`).join(', ')}</p>`;
        else if (id >= block.from && id <= block.to) verdict.innerHTML = `<p class="ids2-v taken"><code>${esc(id)}</code> ${t('is in the block the store keeps for apps it moves. Pick another.')}</p>`;
        else verdict.innerHTML = `<p class="ids2-v free"><code>${esc(id)}</code> ${t('is free as far as we know.')} <a href="/developers">${t('Reserve it with a developer account')}</a></p>`;
      } else if (/^[a-z]{4}\d/i.test(input.value.trim()) && input.value.trim().length < 9) verdict.innerHTML = `<p class="ids2-v hint">${t('A title ID is four letters and five numbers, like PPSA12345.')}</p>`;
      else verdict.innerHTML = '';
    };
    const tabsEl = page.querySelector('[data-tabs]');
    const drawTabs = () => {
      const count = (g) => rows.filter((r) => g === 'all' || r.group === g).length;
      tabsEl.innerHTML = [['all', t('All')], ['store', t('In RecompStore')], ['reserved', t('Reserved')], ['elsewhere', t('Elsewhere')]]
        .map(([k, n]) => `<button type="button" role="tab" aria-selected="${tab === k}" class="${tab === k ? 'on' : ''}" data-tab="${k}">${n} <span>${fmtNum(count(k))}</span></button>`).join('');
      tabsEl.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { tab = b.dataset.tab; drawTabs(); draw(); }));
    };
    input.addEventListener('input', draw);
    fetch('/api/ids.php', { cache: 'no-cache' })
      .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then((d) => {
        if (!page.isConnected) return;
        data = d; block = d.block || {};
        const add = (o) => rows.push(Object.assign(o, { text: [o.id, o.name, o.by, o.repo].filter(Boolean).join(' ').toLowerCase() }));
        (d.store || []).forEach((x) => add({ id: x.id, name: x.name, by: x.by || '', icon: x.icon || '', group: 'store', kind: x.testing || x.soon ? 'test' : 'store', href: x.testing ? '' : '/game/' + encodeURIComponent(x.id) }));
        (d.reserved || []).forEach((x) => add({ id: x.id, name: t('Reserved for an app on its way'), by: x.by, group: 'reserved', kind: 'reserved', href: x.slug ? '/dev/' + encodeURIComponent(x.slug) : '' }));
        (d.github || []).forEach((x) => add({ id: x.id, name: x.name, by: x.repo, repo: x.repo, group: 'elsewhere', kind: 'github', href: 'https://github.com/' + x.repo }));
        (d.taken || []).forEach((x) => add({ id: x.id, name: x.note || t('Another app'), group: 'elsewhere', kind: 'taken' }));
        rows.sort((a, b) => a.id.localeCompare(b.id));
        page.querySelector('[data-stats]').innerHTML = [
          [fmtNum((d.store || []).length), t('in RecompStore')], [fmtNum((d.reserved || []).length), t('reserved by developers')],
          [fmtNum((d.github || []).length + (d.taken || []).length), t('used by apps elsewhere')]]
          .map(([n, l]) => `<div class="glass"><strong>${n}</strong><span>${l}</span></div>`).join('');
        if (d.checked) page.querySelector('[data-checked]').textContent = tf('New PS5 apps looked for {when}', { when: ago(new Date(Number(d.checked) * 1000).toISOString()) });
        drawTabs();
        if (pending) input.value = pending;
        draw();
      })
      .catch(() => { listEl.innerHTML = `<p class="muted">${t('The list didn\'t load. Refresh the page in a moment.')}</p>`; });
    if (!matchMedia('(hover: none)').matches) input.focus();
  }

  // ---- (2.8) The PC side, RecompLauncher (2.9): recomplauncher.com -------------------------------------------
  // PC recomps (pc-catalog.json, from the admin's PC page): each one only ever its developer's own
  // GitHub release. No game files: players bring their own copy. Its own home, list and pages; the
  // header's PS5 / PC switch moves between the two stores.
  const PC_ORIGINS = { n64: 'Nintendo 64', x360: 'Xbox 360', xbox: 'Xbox', ps1: 'PlayStation', ps2: 'PlayStation 2', ps3: 'PlayStation 3', ps4: 'PlayStation 4',
    gc: 'GameCube', wii: 'Wii', wiiu: 'Wii U', snes: 'Super Nintendo', gba: 'Game Boy Advance', gb: 'Game Boy', ds: 'Nintendo DS', dc: 'Dreamcast', saturn: 'Saturn', psp: 'PSP', switch: 'Nintendo Switch', other: N_('Other') };
  const SYS_NAMES = { windows: 'Windows', linux: 'Linux', macos: 'macOS' };
  let pcCat = null, pcLoading = null;
  const pcLoad = () => pcCat ? Promise.resolve(pcCat) : (pcLoading || (pcLoading = fetch('/pc-catalog.json' + (VER ? '?v=' + Date.now() : ''), { cache: 'no-cache' })
    .then((r) => (r.ok ? r.json() : { apps: [] })).catch(() => ({ apps: [] })).then((c) => {
      pcCat = Object.assign({ apps: [], origins: [], developers: [] }, c);
      pcCat.all = pcCat.apps;
      pcFilter();
      return pcCat;
    })));
  // (2.9) Coming soon: being worked on, nothing to download yet. They have their own page and stay
  // out of every other list. Hidden AI-assisted ones are out of every list; their pages still open.
  function pcFilter() {
    if (!pcCat) return;
    let all = hideAI ? pcCat.all.filter((a) => !isAI(a)) : pcCat.all;
    // Coming soon ones have no builds yet: they stay.
    if (onlyOS) all = all.filter((a) => a.soon || (a.systems || []).includes(onlyOS));
    pcCat.soon = all.filter((a) => a.soon);
    pcCat.apps = all.filter((a) => !a.soon);
  }
  const pcById = (id) => (pcCat ? pcCat.all.find((a) => a.id === id) : null);
  const originName = (k) => (k === 'other' ? t('Other') : PC_ORIGINS[k] || k);
  const myOS = () => { const u = navigator.userAgent; return /Windows/i.test(u) ? 'windows' : /Mac OS X|Macintosh/i.test(u) ? 'macos' : /Linux|X11/i.test(u) ? 'linux' : 'windows'; };
  let pcTitle = '';
  // (2.9) RecompLauncher: the PC side has its own name and address, recomplauncher.com (the same site,
  // parked on it). There its pages drop the /pc: /all, /top, /game/<id>, and /get for the app. Links
  // between the two sides open on the other's address; RecompStore's old /pc links forward there.
  const SPLIT = /(^|\.)recomp(store|launcher)\./i.test(location.hostname);
  const LAUNCHER = /(^|\.)recomplauncher\./i.test(location.hostname);
  const STORE_ORIGIN = location.origin.replace(/recomplauncher/i, 'recompstore');
  const LAUNCHER_ORIGIN = location.origin.replace(/recompstore/i, 'recomplauncher');
  const PC_PATH_RE = /^\/pc(\/(all|how|top|new|consoles|launcher|press|soon|settings|action|adventure|platformers|racing|fighting|shooters|sports|rpg|puzzle|game\/[a-z0-9-]{1,64}))?$/;
  const pcOut = (h) => (h === '/pc/launcher' ? '/get' : h.replace(/^\/pc/, '') || '/');
  const pcIn = (p) => (p === '/get' ? '/pc/launcher' : p === '/' ? '/pc' : '/pc' + p);
  // The other side gets this one's language along.
  const withLang = (u) => { if (!lang || lang === 'en') return u; const x = new URL(u); if (!x.searchParams.has('lang')) x.searchParams.set('lang', lang); return x.href; };
  const withPrefs = (u) => { if (!aiPrefSet) return u; const x = new URL(u); x.searchParams.set('noai', hideAI ? '1' : '0'); return x.href; };
  const otherSide = (u) => SPLIT && u.origin !== location.origin && /(^|\.)recomp(store|launcher)\./i.test(u.hostname);
  ['click', 'auxclick'].forEach((ev) => document.addEventListener(ev, (e) => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a) return;
    try { const u = new URL(a.href); if (otherSide(u)) a.href = withPrefs(withLang(u.href)); } catch (err) { /* a link as it is */ }
  }, true));
  function siteLink(a, again) {
    if (!SPLIT || (a.hasAttribute('data-fx') && !again)) return;
    const href = a.getAttribute('href') || '';
    a.setAttribute('data-fx', '');
    if (!href.startsWith('/') || href.startsWith('//')) return;
    const u = new URL(href, location.origin);
    const path = u.pathname.replace(/(.)\/+$/, '$1');
    if (PC_PATH_RE.test(path)) {
      const out = pcOut(path) + u.search + u.hash;
      a.setAttribute('href', LAUNCHER ? out : LAUNCHER_ORIGIN + out);
    } else if (LAUNCHER && !/^\/(api|assets|media)\//.test(path)) a.setAttribute('href', STORE_ORIGIN + href);
  }
  const siteLinks = (root) => { if (!SPLIT || !root) return; if (root.matches && root.matches('a[href]')) siteLink(root); if (root.querySelectorAll) root.querySelectorAll('a[href]').forEach((a) => siteLink(a)); };
  if (SPLIT) {
    siteLinks(document);
    new MutationObserver((ms) => ms.forEach((m) => m.addedNodes.forEach((n) => { if (n.nodeType === 1) siteLinks(n); }))).observe(document.body, { childList: true, subtree: true });
  }
  function setPlatform(pf) {
    document.body.dataset.platform = pf;
    document.querySelectorAll('[data-plat]').forEach((a) => { a.classList.toggle('on', a.dataset.plat === pf); if (a.dataset.plat === pf) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
    document.querySelectorAll('header .brand, .drawer .brand, footer .brand').forEach((a) => {
      a.setAttribute('href', pf === 'pc' ? '/pc' : '/'); siteLink(a, true);
      const w = a.querySelector('span'); if (w) w.innerHTML = pf === 'pc' ? 'Recomp<b>Launcher</b>' : 'Recomp<b>Store</b>';
    });
    document.querySelectorAll('a.submit-btn:not(#drawer-discord):not(.hub-btn)').forEach((a) => { a.setAttribute('href', pf === 'pc' ? '/submit?platform=pc' : '/submit'); siteLink(a, true); });
    if (pf === 'pc' && !pcCat) pcLoad();
  }
  // (2.8) Each console's own colour, for its pill.
  const PC_COLORS = { n64: '#e2453c', x360: '#5dc21e', xbox: '#2fa84f', ps1: '#9aa4b2', ps2: '#3d7dd8', ps3: '#7b8794', ps4: '#2f6fe0', gc: '#8a6cf0',
    wii: '#4fc3f7', wiiu: '#00a2c7', snes: '#b38cf2', gba: '#6c63ff', gb: '#9bbc0f', ds: '#aab4c2', dc: '#ff8a3d', saturn: '#4f7cff', psp: '#5b6b8c', switch: '#e60012', other: '#a7b3c4' };
  const SVG_DISC = '<svg class="pc-disc" viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6.5"/><circle cx="8" cy="8" r="1.8"/></svg>';
  const originPill = (k) => `<span class="pc-pill" style="--c:${PC_COLORS[k] || PC_COLORS.other}">${esc(originName(k))}</span>`;
  // What players say: the one word most of them picked, and how many said anything.
  let pcCounts = null;
  const pcCountsLoad = () => (pcCounts ? Promise.resolve(pcCounts) : fetch('/api/pc.php?a=counts').then((r) => (r.ok ? r.json() : null)).catch(() => null)
    .then((j) => (pcCounts = (j && j.counts) || {})));
  const VERDICTS = { runs: N_('Plays great'), issues: N_('Has problems'), broken: N_("Won't run") };
  const verdictOf = (c) => {
    if (!c) return null;
    const n = (c.runs || 0) + (c.issues || 0) + (c.broken || 0);
    if (!n) return null;
    const top = ['runs', 'issues', 'broken'].reduce((x, y) => ((c[y] || 0) > (c[x] || 0) ? y : x), 'runs');
    return { key: top, n };
  };
  const verdictChip = (c, small) => {
    const v = verdictOf(c);
    if (!v) return '';
    const words = `${t(VERDICTS[v.key])} · ${tn(v.n, '1 report', '{n} reports')}`;
    return small ? `<span class="pc-verdict v-${v.key}" title="${esc(words)}"><i aria-hidden="true"></i>${esc(fmtNum(v.n))}</span>` : `<span class="pc-verdict v-${v.key}"><i aria-hidden="true"></i>${words}</span>`;
  };
  // (2.8) A tile: the cover, the recomp's name, the game it's built from, and its console's tag.
  // Each line stays one line: a long one shrinks to fit (fitTiles), never wraps.
  const onColor = (hex) => { const n = parseInt(String(hex).slice(1), 16); const l = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255; return l > 0.55 ? '#0B1220' : '#ffffff'; };
  const consoleTag = (k) => { const c = PC_COLORS[k] || PC_COLORS.other; return `<span class="pc-tag" style="--c:${c};--on:${onColor(c)}">${esc(originName(k))}</span>`; };
  // A shorter way to say a long line, used only when the whole one can't fit: the recomp's name
  // without "Recomp"/"Port" (its tag already says it's a recomp), the game without its series.
  const shortName = (n) => String(n).replace(/[\s-]+(recompiled|recompilation|recomp|pc port|port|decomp(ilation)?|for pc|pc)$/i, '').trim();
  const shortGame = (g) => (/: /.test(g) ? String(g).split(': ').slice(1).join(': ') : g);
  const fitAttrs = (full, short) => (short && short !== full ? ` data-short="${esc(short)}" data-full="${esc(full)}"` : '');
  const starCount = (n) => { try { return new Intl.NumberFormat(locale(), { notation: 'compact', maximumFractionDigits: 1 }).format(n); } catch (e) { return fmtNum(n); } };
  const osBadges = (a) => (OS_LOGOS.length && (a.systems || []).length ? `<span class="pc-os" aria-label="${esc((a.systems || []).map((k) => SYS_NAMES[k] || k).join(', '))}">${['windows', 'macos', 'linux'].filter((k) => a.systems.includes(k)).map(osIcon).join('')}</span>` : '');
  const pcPoster = (a) => `<a class="pc-tile" href="/pc/game/${encodeURIComponent(a.id)}">
      <span class="pc-poster">${osBadges(a)}${a.cover ? `<img src="${esc(a.cover)}" alt="" loading="lazy" decoding="async">` : `<span class="pc-poster-fb"${a.hero ? ` style="background-image:url('${cssUrl(a.hero)}')"` : ''}>${a.icon ? `<img src="${esc(a.icon)}" alt="" loading="lazy">` : `<b>${esc((a.name || '?').slice(0, 2))}</b>`}</span>`}</span>
      <strong class="pc-name pc-fit" dir="auto" title="${esc(a.name)}"${fitAttrs(a.name, shortName(a.name).toLowerCase() === String(a.game || '').toLowerCase() ? '' : shortName(a.name))}>${esc(a.name)}</strong>
      <small class="pc-game pc-fit" dir="auto"${a.game && a.game !== a.name ? ` title="${esc(a.game)}"${fitAttrs(a.game, shortGame(a.game))}>${esc(a.game)}` : '>&nbsp;'}</small>
      ${consoleTag(a.origin)}${a.stars ? `<span class="pc-stars" aria-label="${esc(tn(a.stars, '1 star on GitHub', '{n} stars on GitHub'))}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.2l2.6 5.6 6.1.7-4.5 4.2 1.2 6.1L12 16.8 6.6 19.8l1.2-6.1-4.5-4.2 6.1-.7z"/></svg>${esc(starCount(a.stars))}</span>` : ''}</a>`;
  // Shrinks each tile line that doesn't fit, a half pixel at a time, down to a floor; an ellipsis only
  // past that. Runs on new tiles as they appear, and on every tile when the window changes size.
  const tileFitOne = (el) => {
    if (el.dataset.full) el.textContent = el.dataset.full;
    else if (el.firstElementChild && el.firstElementChild.classList.contains('pc-glide')) el.textContent = el.firstElementChild.textContent;
    el.style.fontSize = '';
    const w = el.clientWidth;
    if (!w) return false;
    const name = el.classList.contains('pc-name');
    const base = parseFloat(getComputedStyle(el).fontSize);
    const shrink = (floor) => { let size = parseFloat(el.style.fontSize) || base; while (el.scrollWidth > w + 0.5 && size > floor) { size -= 0.5; el.style.fontSize = size + 'px'; } };
    // The whole line, a little smaller; then the short way to say it; then smaller still.
    shrink(name ? 14 : 12);
    if (el.scrollWidth > w + 0.5 && el.dataset.short) { el.textContent = el.dataset.short; el.style.fontSize = ''; shrink(name ? 14 : 12); }
    shrink(name ? 12 : 10.5);
    // Still too long: no "…". The end fades out, and the line glides across when the tile is hovered.
    if (el.firstElementChild && el.firstElementChild.classList.contains('pc-glide')) el.textContent = el.firstElementChild.textContent;
    const over = el.scrollWidth - w;
    el.classList.toggle('pc-long', over > 0.5);
    if (over > 0.5) { const t = el.textContent; el.innerHTML = `<span class="pc-glide">${esc(t)}</span>`; el.style.setProperty('--shift', -Math.ceil(over + 8) + 'px'); el.style.setProperty('--glide', Math.max(1.2, over / 40).toFixed(2) + 's'); }
    return true;
  };
  const fitTiles = (root, all) => (root || document).querySelectorAll(all ? '.pc-fit' : '.pc-fit:not([data-fit])').forEach((el) => { if (tileFitOne(el)) el.dataset.fit = '1'; });
  let tileFitQueued = false;
  const tileFitSoon = (all) => { if (tileFitQueued) return; tileFitQueued = true; requestAnimationFrame(() => { tileFitQueued = false; fitTiles(document, all); }); };
  new MutationObserver(() => tileFitSoon(false)).observe(document.documentElement, { childList: true, subtree: true });
  let tileFitTimer = 0;
  addEventListener('resize', () => { clearTimeout(tileFitTimer); tileFitTimer = setTimeout(() => tileFitSoon(true), 120); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => tileFitSoon(true));
  const pcByLine = (a) => {
    const d = a.dev && ((pcCat.developers || []).find((x) => x.slug === a.dev) || devBySlug(a.dev));
    return d ? `<a class="dev-line" href="/dev/${encodeURIComponent(d.slug)}">${t('By')} <span>${esc(d.name)}</span>${d.verified ? tick() : ''}</a>`
      : a.author ? `<span class="dev-line">${t('By')} <span>${esc(a.author)}</span></span>` : '';
  };
  const pcNoFiles = () => `<div class="glass disc-note pc-byo"><span class="kicker">${t('No game files here')}</span><p>${t('RecompLauncher lists only each recomp, from its developer\'s own GitHub. Every game needs your own legally owned copy.')}</p></div>
    <p class="pc-art-credit">${tf('Cover art and logos from {site}', { site: '<a href="https://www.steamgriddb.com" target="_blank" rel="noopener">SteamGridDB</a>' })}</p>`;

  // (2.8) Every PC category has its own address: /pc/action, /pc/platformers...
  const PC_CAT_SLUG = { action: 'action', adventure: 'adventure', platformer: 'platformers', racing: 'racing', fighting: 'fighting', shooter: 'shooters', sports: 'sports', rpg: 'rpg', puzzle: 'puzzle' };
  const PC_CAT_RE = /^\/pc\/(action|adventure|platformers|racing|fighting|shooters|sports|rpg|puzzle)$/;
  const pcCatBySlug = (s) => Object.keys(PC_CAT_SLUG).find((k) => PC_CAT_SLUG[k] === s) || '';
  function pcRoute(h) {
    setBackdrop(null);
    setAccent(null);
    view.innerHTML = `<p class="muted pc-loading">${t('Loading…')}</p>`;
    Promise.all([pcLoad(), Promise.race([pcCountsLoad(), new Promise((ok) => setTimeout(ok, 700))])]).then(() => {
      if (!h.startsWith('/pc') || current() !== h) return;
      const g = h.match(/^\/pc\/game\/([a-z0-9-]{1,64})$/);
      const c = h.match(PC_CAT_RE);
      if (g) pcGame(decodeURIComponent(g[1]));
      else if (h === '/pc/all') pcAll(new URLSearchParams(location.search).get('kind') || '');
      else if (c) pcAll(pcCatBySlug(c[1]));
      else if (h === '/pc/how') pcHow();
      else if (h === '/pc/top' || h === '/pc/new') pcList(h.slice(4));
      else if (h === '/pc/consoles') pcConsoles();
      else if (h === '/pc/launcher') pcLauncher();
      else if (h === '/pc/press') pcPress();
      else if (h === '/pc/soon') pcSoon();
      else if (h === '/pc/settings') settingsPage(true);
      else pcHome();
      document.title = pcTitle;
      enhanceRows(view);
    });
  }

  // (2.8) Each PC category's own picture, for its button under the banner.
  // (2.9) Each PC category's icon (assets/cat/pc/<key>.png, white on clear), drawn in the site's own
  // colour through a mask, so it follows the button's colour like the rest.
  const PC_CAT_ICONS = ['action', 'adventure', 'platformer', 'racing', 'fighting', 'shooter', 'sports', 'rpg', 'puzzle'];
  const pcCatIcon = (k) => (PC_CAT_ICONS.includes(k) ? `<span class="ico-m" style="--m:url('/assets/cat/pc/${k}.png?v=1')" aria-hidden="true"></span>` : '');
  function pcHome() {
    pcTitle = t('RecompLauncher — recompiled games for Windows, Linux and Mac');
    const apps = pcCat.apps;
    const byDl = (x, y) => (y.downloads || 0) - (x.downloads || 0);
    // The banner: every featured recomp with a backdrop, in the admin's order.
    const slides = apps.filter((a) => a.featured && a.hero);
    // Newest arrivals: the latest to join RecompLauncher, then the latest release.
    const newest = apps.slice().sort((x, y) => String(y.added || '').localeCompare(String(x.added || '')) || String(y.released || '').localeCompare(String(x.released || ''))).slice(0, 16);
    const charts = apps.slice().sort(byDl).slice(0, 16);
    // (2.9.1) Each row's title opens its full page: Top charts, Newest arrivals, each category.
    const row = (title, items, href, cls) => items.length ? `<section class="games pc-row${cls ? ' ' + cls : ''}"><div class="pc-row-head"><h2>${href ? `<a class="pc-row-link" href="${href}">${title}<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg></a>` : title}</h2></div><div class="rowscroll pc-posters">${items.map(pcPoster).join('')}</div></section>` : '';
    const inCat = (k) => apps.filter((a) => a.genre === k).sort(byDl);
    const ranked = (html) => { let n = 0; return html.replace(/<span class="pc-poster">/g, () => `<span class="pc-poster"><b class="pc-rank" aria-hidden="true">${fmtNum(++n)}</b>`); };
    // Every category with enough recomps to fill a row, the biggest first.
    const cats = PC_CATS.filter((k) => inCat(k).length >= 3).sort((x, y) => inCat(y).length - inCat(x).length);
    // Its buttons sit in even rows: as many across as divides them evenly.
    const across = cats.length <= 5 ? cats.length : cats.length % 4 === 0 ? 4 : cats.length % 3 === 0 ? 3 : cats.length % 5 === 0 ? 5 : 4;
    const catBtns = cats.length >= 2 ? `<nav class="cat-row pc-cat-row" style="--across:${across}" aria-label="${t('Browse by category')}">${cats.map((k) => `
      <a class="glass cat-btn" href="/pc/${PC_CAT_SLUG[k]}"><span class="cat-ico">${pcCatIcon(k)}</span><span class="cat-name">${esc(t(PC_GENRES[k]))}</span><span class="cat-n">${fmtNum(inCat(k).length)}</span></a>`).join('')}</nav>` : '';
    const ogN = {};
    apps.forEach((a) => { if (a.origin) ogN[a.origin] = (ogN[a.origin] || 0) + 1; });
    const ogKeys = Object.keys(ogN).sort((x, y) => (x === 'other') - (y === 'other') || ogN[y] - ogN[x]);
    const consoleStrip = ogKeys.length > 1 ? `<nav class="con-strip" aria-label="${t('Browse by console')}">${ogKeys.map((k) => `<a href="/pc/all?from=${encodeURIComponent(k)}"><i style="--c:${PC_COLORS[k] || PC_COLORS.other}" aria-hidden="true"></i>${esc(originName(k))}<span>${fmtNum(ogN[k])}</span></a>`).join('')}</nav>` : '';
    view.innerHTML = `
      <article class="page pc-home">
        <section class="intro home-intro pc-home-intro">
          <div class="intro-words">
            <h1 dir="auto">${t('Recompiled games, native on PC.')}</h1>
            <p>${t('Install and update recompiled PC games. No account, no game files: bring your own disc.')}</p>
          </div>
          <div class="cta-row"><a class="btn ghost" href="/pc/how">${t('How it works')}</a></div>
        </section>
        ${slides.length ? `
        <section class="slider pc-slider" aria-roledescription="carousel" aria-label="${t('Featured games')}">
          ${slides.map((a, i) => `
            <a class="slide${i === 0 ? ' on' : ''}" href="/pc/game/${encodeURIComponent(a.id)}" aria-hidden="${i === 0 ? 'false' : 'true'}" tabindex="${i === 0 ? 0 : -1}" data-slide="${i}">
              <span class="slide-blur" style="background-image:url('${cssUrl(a.hero)}')"></span>
              <span class="slide-art"><img src="${esc(a.hero)}" alt=""${i === 0 ? ' fetchpriority="high"' : ' loading="lazy"'}></span>
              <span class="slide-text">
                <span class="slide-meta"><i class="dot" style="--c:${PC_COLORS[a.origin] || PC_COLORS.other}" aria-hidden="true"></i>${esc(originName(a.origin))}${a.game ? ` · <bdi>${esc(a.game)}</bdi>` : ''}</span>
                <span class="slide-name" dir="auto">${esc(a.name)}</span>
                <span class="slide-blurb" dir="auto">${esc(a.blurb || '')}</span>
              </span>
            </a>`).join('')}
          ${slides.length > 1 ? `<div class="thumbs">${slides.map((a, i) => `<button type="button" data-dot="${i}" aria-label="${tf('Show {name}', { name: esc(a.name) })}"${i === 0 ? ' aria-current="true"' : ''}><img src="${esc(a.hero)}" alt="" loading="lazy"></button>`).join('')}</div>` : ''}
        </section>` : ''}
        ${catBtns}
        ${consoleStrip}
        ${apps.length ? '' : `<p class="muted">${t('The first PC recomps are on their way.')}</p>`}
        ${ranked(row(t('Top charts'), charts, '/pc/top', 'pc-charts'))}
        ${row(t('Newest arrivals'), newest, '/pc/new')}
        ${cats.map((k) => row(t(PC_GENRES[k]), inCat(k), '/pc/' + PC_CAT_SLUG[k])).join('')}
        <section class="pc-intro"><h2>${t('What is a PC recomp?')}</h2><p>${t('Every recomp here is a fan project that turns a console game into a real PC game: higher resolutions, widescreen, high frame rates and mods. Each one comes from its developer\'s own GitHub, and you bring your own copy of the game.')}</p></section>
        ${pcNoFiles()}
      </article>`;
    // The banner crossfades every 7 seconds; the dots jump.
    const els = [...view.querySelectorAll('.pc-slider .slide')];
    const dots = [...view.querySelectorAll('.pc-slider [data-dot]')];
    let cur = 0;
    const show = (i) => {
      cur = (i + els.length) % els.length;
      els.forEach((el, k) => { el.classList.toggle('on', k === cur); el.setAttribute('aria-hidden', k === cur ? 'false' : 'true'); el.tabIndex = k === cur ? 0 : -1; });
      dots.forEach((d, k) => d.toggleAttribute('aria-current', k === cur));
    };
    const restart = () => { clearInterval(slideTimer); if (els.length > 1 && !reduced) slideTimer = setInterval(() => show(cur + 1), 7000); };
    dots.forEach((d) => d.addEventListener('click', () => { show(+d.dataset.dot); restart(); }));
    restart();
  }

  function pcAll(kind) {
    let genre = PC_GENRES[kind] ? kind : '';
    let origin = new URLSearchParams(location.search).get('from') || '';
    const title = () => (genre && PC_CATS.includes(genre) ? t(PC_GENRES[genre]) : t('All PC recomps'));
    pcTitle = `${title()} · RecompLauncher`;
    view.innerHTML = `
      <article class="page pc-all">
        <header class="page-head"><span class="kicker">RecompLauncher</span><div class="pc-title-row"><h1 data-title>${title()}</h1><span class="pc-count" data-count role="status" aria-live="polite"></span></div></header>
        <div class="pc-filters"><label class="ids2-search glass pc-find"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></svg><input type="search" placeholder="${t('Search PC recomps')}" aria-label="${t('Search PC recomps')}"></label>
          <div class="pc-filter-rows"><nav class="ids2-tabs" data-genres></nav><nav class="ids2-tabs" data-origins></nav><nav class="ids2-tabs" data-oses></nav></div></div>
        <div class="pc-grid" data-grid></div>
        ${pcNoFiles()}
      </article>`;
    const input = view.querySelector('.pc-find input'), grid = view.querySelector('[data-grid]'), gtabs = view.querySelector('[data-genres]'), otabs = view.querySelector('[data-origins]');
    const genresHere = Object.keys(PC_GENRES).filter((k) => pcCat.apps.some((a) => (a.genre || 'other') === k));
    // (2.9.1) How many recomps the page shows right now. It follows every filter and the search, and
    // counts up or down to the new number instead of jumping.
    const countEl = view.querySelector('[data-count]');
    let shown = -1, countAnim = 0;
    const countText = (n) => tn(n, '1 recomp', '{n} recomps');
    const setCount = (n) => {
      cancelAnimationFrame(countAnim);
      const from = shown < 0 ? n : shown;
      shown = n;
      countEl.setAttribute('aria-label', countText(n));
      if (reduced || from === n) { countEl.textContent = countText(n); return; }
      countEl.classList.remove('bump'); void countEl.offsetWidth; countEl.classList.add('bump');
      const t0 = performance.now(), dur = Math.min(650, 260 + Math.abs(n - from) * 6);
      const step = (now) => {
        const p = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - p, 3);
        countEl.textContent = countText(Math.round(from + (n - from) * e));
        if (p < 1) countAnim = requestAnimationFrame(step);
      };
      countAnim = requestAnimationFrame(step);
    };
    const draw = (animate) => {
      const q = fold(input.value.trim());
      const list = pcCat.apps.filter((a) => (!genre || (a.genre || 'other') === genre) && (!origin || a.origin === origin)
        && (!q || fold([a.name, a.game, a.author, originName(a.origin), t(PC_GENRES[a.genre || 'other'])].join(' ')).includes(q)));
      setCount(list.length);
      grid.innerHTML = list.length ? list.map((a, i) => pcPoster(a).replace('<a class="pc-tile"', `<a class="pc-tile${animate && !reduced ? ' pop' : ''}" style="--i:${Math.min(i, 14)}"`)).join('') : `<p class="muted">${t('Nothing matches that.')}</p>`;
      gtabs.innerHTML = [['', t('All')], ...genresHere.map((k) => [k, t(PC_GENRES[k])])].map(([k, n]) => `<button type="button" class="${genre === k ? 'on' : ''}" data-g="${esc(k)}">${esc(n)}</button>`).join('');
      otabs.innerHTML = [['', t('Every console')], ...(pcCat.origins || []).map((o) => [o.id, originName(o.id)])].map(([k, n]) => `<button type="button" class="${origin === k ? 'on' : ''}" data-o="${esc(k)}">${esc(n)}</button>`).join('');
      gtabs.querySelectorAll('[data-g]').forEach((b) => b.addEventListener('click', () => {
        genre = b.dataset.g;
        // The four categories keep their own address; the rest stay on /pc/all.
        const path = genre && PC_CAT_SLUG[genre] ? '/pc/' + PC_CAT_SLUG[genre] : '/pc/all' + (genre ? '?kind=' + genre : '');
        history.replaceState(history.state, '', path);
        routed = location.href;
        view.querySelector('[data-title]').textContent = title();
        document.title = pcTitle = `${title()} · RecompLauncher`;
        document.querySelectorAll('[data-nav]').forEach((n) => { if (n.dataset.nav === 'pc-all') n.setAttribute('aria-current', 'page'); else n.removeAttribute('aria-current'); });
        draw(true);
      }));
      otabs.querySelectorAll('[data-o]').forEach((b) => b.addEventListener('click', () => { origin = b.dataset.o; draw(true); }));
      // (2.9) Which system: the same choice as in Settings, kept for every page.
      const stabs = view.querySelector('[data-oses]');
      stabs.innerHTML = [['', t('Every system')], ...OS_CHOICES().slice(1)].map(([k, n]) => `<button type="button" class="${onlyOS === k ? 'on' : ''}" data-os="${k}">${osIcon(k)}${esc(n)}</button>`).join('');
      stabs.querySelectorAll('[data-os]').forEach((b) => b.addEventListener('click', () => { setOS(b.dataset.os, true); draw(true); }));
    };
    input.addEventListener('input', () => draw(false));
    draw(true);
  }

  function pcHow() {
    pcTitle = `${t('How it works')} · RecompLauncher`;
    const steps = [[t('Pick a recomp'), t('Each one rebuilds a console game as a real PC game, made by its own developer and shared on their GitHub.')],
      [t('Bring your own copy'), t('Recomps never come with the game. You use your own legally owned copy, as each recomp\'s page explains.')],
      [t('Download and play'), t('Get the build for Windows, Linux or Mac straight from the developer\'s release, set it up, and play.')]];
    view.innerHTML = `
      <article class="page pc-how">
        <header class="page-head center"><span class="kicker">RecompLauncher</span><h1>${t('How it works')}</h1>
          <p class="lead">${t('RecompLauncher is a home for recompiled games: find them, see what they need, and get them from their developers.')}</p></header>
        <section class="pc-steps">${steps.map(([h2, p2], i) => `<div class="glass card-pad"><span class="perk-ico" aria-hidden="true">${i + 1}</span><h2>${h2}</h2><p>${p2}</p></div>`).join('')}</section>
        <section class="split pc-how-more">
          <div class="glass card-pad"><h2>${t('Made a PC recomp?')}</h2><p>${t('Send it in, or get a developer account: your own page, your numbers and your players in one place, for PC and PS5.')}</p>
            <div class="cta-row"><a class="btn primary" href="/submit?platform=pc">${t('Submit a game')}</a><a class="btn" href="/developers">${t('Developer accounts')}</a></div></div>
          <div class="glass card-pad"><h2>${t('The app is on its way')}</h2><p>${t('The RecompLauncher app will install, update and launch your recomps for you, and set up your game files. Until then, every download here goes straight to its developer\'s release.')}</p>
            <div class="cta-row"><a class="btn" href="/pc/launcher">${t('Get RecompLauncher')}</a></div></div>
        </section>
        ${pcNoFiles()}
      </article>`;
  }

  // (2.8) Top charts and New: every PC recomp in one grid, most downloaded or newest first.
  function pcList(kind) {
    const top = kind === 'top';
    const title = top ? t('Top charts') : t('New');
    pcTitle = `${title} · RecompLauncher`;
    const apps = pcCat.apps.slice().sort(top
      ? (x, y) => (y.downloads || 0) - (x.downloads || 0)
      : (x, y) => String(y.added || '').localeCompare(String(x.added || '')) || String(y.released || '').localeCompare(String(x.released || '')));
    let html = apps.map((a, i) => pcPoster(a).replace('<a class="pc-tile"', `<a class="pc-tile${reduced ? '' : ' pop'}" style="--i:${Math.min(i, 14)}"`)).join('');
    if (top) { let n = 0; html = html.replace(/<span class="pc-poster">/g, () => `<span class="pc-poster"><b class="pc-rank" aria-hidden="true">${fmtNum(++n)}</b>`); }
    view.innerHTML = `
      <article class="page pc-all">
        <header class="page-head"><span class="kicker">RecompLauncher</span><h1>${title}</h1>
          <p class="lead">${top ? t('The most downloaded PC recomps, counted from their developers\' GitHub releases.') : t('The latest recomps to arrive on RecompLauncher.')}</p></header>
        <div class="pc-grid${top ? ' pc-charts' : ''}">${html || `<p class="muted">${t('The first PC recomps are on their way.')}</p>`}</div>
        ${pcNoFiles()}
      </article>`;
  }

  // (2.9) Coming soon: recomps being worked on, with no release to download yet.
  function pcSoon() {
    pcTitle = `${t('Coming soon')} · RecompLauncher`;
    const list = (pcCat.soon || []).slice().sort((x, y) => (y.stars || 0) - (x.stars || 0));
    // Even rows: as many across as divides them evenly (no lone tile on the last row).
    const n = list.length;
    const across = n <= 7 ? Math.max(n, 1) : [7, 6, 5, 4, 3].find((k) => n % k === 0) || [7, 6, 5, 4].find((k) => n % k >= 2) || 4;
    view.innerHTML = `
      <article class="page pc-all pc-soon">
        <header class="page-head"><span class="kicker">RecompLauncher</span><h1>${t('Coming soon')}</h1>
          <p class="lead">${t('Recomps being worked on now. Nothing to download yet: follow them on GitHub to see when they land.')}</p></header>
        <div class="pc-grid pc-soon-grid" style="--across:${across}">${list.map(pcPoster).join('') || `<p class="muted">${t('Nothing here right now.')}</p>`}</div>
        ${pcNoFiles()}
      </article>`;
  }

  // (2.8) Consoles: the recomps grouped by the console their game first came out on.
  function pcConsoles() {
    pcTitle = `${t('Consoles')} · RecompLauncher`;
    const byDl = (x, y) => (y.downloads || 0) - (x.downloads || 0);
    const list = (pcCat.origins || []).map((o) => ({ id: o.id, apps: pcCat.apps.filter((a) => a.origin === o.id).sort(byDl) })).filter((o) => o.apps.length)
      .sort((x, y) => y.apps.length - x.apps.length);
    // Cards in even rows: as many across as divides them evenly.
    const n = list.length;
    const across = n <= 4 ? n : n % 4 === 0 ? 4 : n % 3 === 0 ? 3 : 4;
    view.innerHTML = `
      <article class="page pc-consoles">
        <header class="page-head"><span class="kicker">RecompLauncher</span><h1>${t('Consoles')}</h1>
          <p class="lead">${t('Browse recomps by the console their game first came out on.')}</p></header>
        <div class="pc-con-grid" style="--across:${across}">${list.map((o) => {
          const c = PC_COLORS[o.id] || PC_COLORS.other;
          const covers = o.apps.filter((a) => a.cover).slice(0, 3);
          return `<a class="glass pc-con" href="/pc/all?from=${encodeURIComponent(o.id)}" style="--c:${c}">
            <span class="pc-con-art" aria-hidden="true">${covers.map((a, i) => `<img src="${esc(a.cover)}" alt="" loading="lazy" style="--k:${i}">`).join('')}</span>
            <span class="pc-con-words"><strong>${esc(originName(o.id))}</strong><small>${tn(o.apps.length, '1 recomp', '{n} recomps')}</small></span>
          </a>`;
        }).join('')}</div>
        ${pcNoFiles()}
      </article>`;
  }


  // (2.9) RecompLauncher's press kit (recomplauncher.com/press): its badge for each recomp, its logos.
  function pcPress() {
    pcTitle = `${t('Press kit')} · RecompLauncher`;
    const K = 'https://recomplauncher.com';
    const A = '/assets/press/launcher/';
    const apps = pcCat.apps.slice().sort((x, y) => String(x.name).localeCompare(String(y.name)));
    const want = new URLSearchParams(location.search).get('app') || '';
    let app = apps.find((a) => a.id === want) || null;
    let theme = 'dark';
    const logos = [
      ['badge-dark', t('Badge'), t('For light or dark backgrounds'), 'dark'], ['badge-light', t('Badge'), t('For dark backgrounds'), 'light'],
      ['logo-horizontal-white', t('Logo'), t('For dark backgrounds'), 'dark'], ['logo-horizontal-dark', t('Logo'), t('For light backgrounds'), 'light'],
      ['wordmark-white', t('Wordmark'), t('For dark backgrounds'), 'dark'], ['wordmark-dark', t('Wordmark'), t('For light backgrounds'), 'light'],
    ];
    const colors = [['#34F2E8', t('Gradient top')], ['#377BB6', t('Gradient bottom')], ['#020509', t('Ink')], ['#F4F8FC', t('Frost')]];
    const rules = [t('Write the name as one word: RecompLauncher.'), t('Use the badge and logos as they are. Don\'t recolor, stretch or redraw them.'),
      t('Give them room, and use the light versions on light backgrounds.'), t('Link the badge to your app\'s page on RecompLauncher, so players land in the right place.')];
    view.innerHTML = `
      <article class="page press-page pc-press">
        <header class="get-hero glass">
          <img class="get-logo" src="${A}icon-1024.png" alt="" width="120" height="120">
          <div>
            <h1>${t('Press kit')}</h1>
            <p class="lead">${t('Badges, logos and words for telling people where to get your app.')}</p>
            <div class="cta-row"><a class="btn primary" href="${A}recomplauncher-press-kit.zip" download>${t('Download everything (.zip)')}</a><a class="btn ghost" href="/submit?platform=pc">${t('Submit a game')}</a></div>
          </div>
        </header>
        <section class="glass card-pad press-badge" id="badge">
          <h2>${t('Get it on RecompLauncher badge')}</h2>
          <div class="press-pick">
            <label>${t('Your app')}<select data-app><option value="">RecompLauncher</option>${apps.map((a) => `<option value="${esc(a.id)}"${app && app.id === a.id ? ' selected' : ''}>${esc(a.name)}</option>`).join('')}</select></label>
            <div class="chips filter" role="group" aria-label="${t('Badge')}"><button type="button" class="tag on" data-theme="dark">${t('Dark')}</button><button type="button" class="tag" data-theme="light">${t('Light')}</button></div>
          </div>
          <div class="press-preview" data-preview></div>
          <div data-snips></div>
        </section>
        <section class="press-logos">
          <h2>${t('Logos')}</h2>
          <div class="press-grid">${logos.map(([f, name, note, bg]) => `
            <figure class="glass press-logo ${bg}"><div class="press-art"><img src="${A}${f}.svg" alt="${esc(name)}" loading="lazy"></div>
              <figcaption><strong>${esc(name)}</strong><span>${esc(note)}</span><span class="press-files"><a href="${A}${f}.svg" download>SVG</a><a href="${A}${f}.png" download>PNG</a></span></figcaption></figure>`).join('')}
            <figure class="glass press-logo dark"><div class="press-art"><img src="${A}mark.svg" alt="${esc(t('Mark'))}" loading="lazy"></div>
              <figcaption><strong>${t('Mark')}</strong><span>${t('For light or dark backgrounds')}</span><span class="press-files"><a href="${A}mark.svg" download>SVG</a><a href="${A}mark.png" download>PNG</a></span></figcaption></figure>
            <figure class="glass press-logo dark"><div class="press-art"><img src="${A}icon-1024.png" alt="${esc(t('App icon'))}" loading="lazy" class="press-icon"></div>
              <figcaption><strong>${t('App icon')}</strong><span>1024 × 1024</span><span class="press-files"><a href="${A}icon-1024.png" download>PNG</a></span></figcaption></figure>
          </div>
        </section>
        <section class="split">
          <div>
            <div class="glass card-pad"><h2>${t('Colors')}</h2><div class="press-colors">${colors.map(([hex, name]) => `
              <button type="button" class="press-color" data-hex="${hex}"><span class="sw" style="background:${hex}"></span><strong>${esc(name)}</strong><code>${hex}</code></button>`).join('')}</div>
              <p class="note">${t('The logo\'s gradient runs from top to bottom, #34F2E8 to #377BB6.')}</p></div>
            <div class="glass card-pad press-words"><h2>${t('Words you can use')}</h2><div data-words></div></div>
          </div>
          <aside>
            <div class="glass card-pad"><span class="kicker">${t('Using the name and logo')}</span><ul class="press-rules">${rules.map((r) => `<li>${esc(r)}</li>`).join('')}</ul></div>
            <div class="glass card-pad"><span class="kicker">${t('Get your app on RecompLauncher')}</span><p>${t('Send it in, or get a developer account: your own page, your numbers and your players in one place, for PC and PS5.')}</p>
              <div class="cta-row"><a class="btn primary" href="/submit?platform=pc">${t('Submit a game')}</a><a class="btn ghost" href="/developers">${t('Developer accounts')}</a></div></div>
          </aside>
        </section>
      </article>`;
    setBackdrop(null);
    setAccent(null);
    const block = (id, label, text) => `<div class="press-snip"><span class="kicker">${esc(label)}</span><span class="cmd-row"><code class="cmd" id="${id}">${esc(text)}</code><button class="copy" type="button" data-copy="#${id}">${t('Copy')}</button></span></div>`;
    const draw = () => {
      const url = app ? `${K}/game/${encodeURIComponent(app.id)}` : `${K}/`;
      const svg = `${K}${A}badge-${theme}.svg`, png = `${K}${A}badge-${theme}.png`;
      view.querySelector('[data-preview]').className = 'press-preview ' + (theme === 'light' ? 'on-dark' : 'on-light');
      view.querySelector('[data-preview]').innerHTML = `<a href="${esc(url)}"><img src="${A}badge-${theme}.svg" alt="Get it on RecompLauncher" height="56"></a>`;
      view.querySelector('[data-snips]').innerHTML = block('snip-md', t('For your README (Markdown)'), `[![Get it on RecompLauncher](${svg})](${url})`)
        + block('snip-html', 'HTML', `<a href="${url}"><img src="${svg}" alt="Get it on RecompLauncher" height="56"></a>`)
        + block('snip-bb', t('For forums (BBCode)'), `[url=${url}][img]${png}[/img][/url]`);
      view.querySelector('[data-words]').innerHTML = block('word-short', t('About RecompLauncher'), t('RecompLauncher: console games rebuilt to run natively on Windows, Linux and Mac, straight from their developers.'))
        + (app ? block('word-app', t('About your app'), `${tf('{name} is on RecompLauncher, the free home for recompiled PC games. Get it straight from its developer:', { name: app.name })} ${url}`) : '');
    };
    draw();
    view.querySelector('[data-app]').addEventListener('change', (e) => {
      app = apps.find((a) => a.id === e.target.value) || null;
      const q = new URLSearchParams(location.search);
      if (app) q.set('app', app.id); else q.delete('app');
      history.replaceState(history.state, '', (LAUNCHER ? '/press' : '/pc/press') + (q.toString() ? '?' + q : ''));
      draw();
    });
    view.querySelectorAll('[data-theme]').forEach((b) => b.addEventListener('click', () => {
      theme = b.dataset.theme;
      view.querySelectorAll('[data-theme]').forEach((x) => x.classList.toggle('on', x === b));
      draw();
    }));
    if (app) requestAnimationFrame(() => { const el = document.getElementById('badge'); if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 90, behavior: 'instant' }); });
    view.querySelectorAll('[data-hex]').forEach((b) => b.addEventListener('click', () => {
      navigator.clipboard && navigator.clipboard.writeText(b.dataset.hex).then(() => { const c = b.querySelector('code'); c.textContent = t('Copied'); setTimeout(() => (c.textContent = b.dataset.hex), 1200); });
    }));
  }

  // (2.8) RecompLauncher: what's coming to Windows.
  function pcLauncher() {
    pcTitle = `${t('Get RecompLauncher')} · RecompLauncher`;
    const covers = pcCat.apps.filter((a) => a.cover).sort((x, y) => (y.downloads || 0) - (x.downloads || 0)).slice(0, 8);
    const feats = [
      ['library', t('Your whole library'), t('Every recomp in one place, with covers, play time and updates, including the ones you already have.')],
      ['disc', t('Set up from your disc'), t('Point it at your own disc image or ROM once. It checks it, and puts what each game needs where it goes.')],
      ['settings', t('One settings screen'), t('Resolution, frame rate, anti-aliasing and the rest, laid out the same way for every game and picked to suit your PC.')],
      ['updates', t('Updates on their own'), t('New releases come straight from each developer\'s GitHub, checked before they install.')],
      ['tv', t('TV mode'), t('A full-screen view made for a controller, the couch and the Steam Deck.')],
      ['ps5', t('Works with your PS5'), t('Games that are on both can be set up once on your PC and sent to RecompStore on your PS5.')]
    ];
    const ico = {
      library: '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"/>',
      disc: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="2.4"/>',
      settings: '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
      updates: '<path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 20h14"/>',
      tv: '<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M8 21h8M12 17v4"/>',
      ps5: '<path d="M4 12h16M14 6l6 6-6 6"/>'
    };
    view.innerHTML = `
      <article class="page pc-launcher">
        <header class="pc-launch-hero">
          <div class="pc-launch-words">
            <span class="kicker">${t('Coming soon to Windows')}</span>
            <h1>RecompLauncher</h1>
            <p class="lead">${t('One app for every PC recomp. It installs them, keeps them up to date, sets them up from your own copy, and gives every game the same simple settings.')}</p>
            <div class="cta-row"><a class="btn primary" href="/pc/all">${t('Browse PC recomps')}</a><a class="btn" href="/pc/how">${t('How it works')}</a></div>
          </div>
          <div class="glass pc-launch-win" aria-hidden="true">
            <span class="pc-launch-bar"><i></i><i></i><i></i></span>
            <span class="pc-launch-grid">${covers.map((a) => `<img src="${esc(a.cover)}" alt="" loading="lazy">`).join('')}</span>
          </div>
        </header>
        <section class="pc-launch-feats">${feats.map(([k, h, p]) => `<div class="glass card-pad"><span class="cat-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ico[k]}</svg></span><h2>${h}</h2><p>${p}</p></div>`).join('')}</section>
        ${pcNoFiles()}
      </article>`;
  }

  // (2.8) A developer's README, shown safely: Markdown and the bits of HTML READMEs use, escaped
  // first. Pictures and links that point inside the repo go to GitHub's copies.
  function mdFull(text, raw, blob) {
    const abs = (u, base) => { u = String(u || '').trim().replace(/^<|>$/g, ''); if (/^https?:\/\//i.test(u)) return u; if (/^[a-z]+:/i.test(u) || u.startsWith('#') || !u) return ''; try { return new URL(u.replace(/^\.\//, ''), base).href; } catch (e) { return ''; } };
    const badge = (u) => /shields\.io|badge|badgen\.net|img\.shields|\/workflows\/|actions\/workflows|discordapp\.com\/api\/guilds|ko-fi\.com\/img|buymeacoffee/i.test(u);
    let src = String(text || '').replace(/\r\n?/g, '\n').replace(/<!--[\s\S]*?-->/g, '');
    // The HTML READMEs use: pictures, links, line breaks, headings; the rest of the tags go.
    src = src.replace(/<img\b[^>]*>/gi, (tag) => { const s = (tag.match(/\bsrc\s*=\s*["']([^"']+)["']/i) || [])[1]; const al = (tag.match(/\balt\s*=\s*["']([^"']*)["']/i) || [])[1] || ''; return s ? `\n![${al}](${s})\n` : ''; })
      .replace(/<a\b[^>]*\bhref\s*=\s*["']([^"']+)["'][^>]*>([\s\S]*?)<\/a>/gi, (m, h, inner) => (/!\[/.test(inner) ? inner : `[${inner.replace(/<[^>]+>/g, '').trim() || h}](${h})`))
      .replace(/<br\s*\/?>/gi, '\n').replace(/<h([1-6])[^>]*>([\s\S]*?)<\/h\1>/gi, (m, n, x) => `\n${'#'.repeat(+n)} ${x.replace(/<[^>]+>/g, '').trim()}\n`)
      .replace(/<\/?(p|div|details|summary|center|picture|source|table|thead|tbody|tr|td|th|span|sup|sub|kbd|b|i|strong|em)\b[^>]*>/gi, (m, tag) => (/^(b|strong)$/i.test(tag) ? '**' : /^(i|em)$/i.test(tag) ? '_' : '\n'))
      .replace(/<[^>]+>/g, '');
    const inline = (str) => esc(str)
      .replace(/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;[^)]*&quot;)?\)/g, (m, al, u) => { const x = abs(u.replace(/&amp;/g, '&'), raw); return x && !badge(x) ? `<img src="${esc(x)}" alt="${al}" loading="lazy">` : ''; })
      .replace(/\[\s*\]\([^)\s]*\)/g, '')
      .replace(/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;[^)]*&quot;)?\)/g, (m, label, u) => { const x = abs(u.replace(/&amp;/g, '&'), blob); return x ? `<a href="${esc(x)}" target="_blank" rel="noopener noreferrer">${label}</a>` : label; })
      .replace(/(^|[\s(])(https?:\/\/[^\s<)]+)/g, (m, pre, u) => `${pre}<a href="${u}" target="_blank" rel="noopener noreferrer">${u.replace(/^https?:\/\//, '').slice(0, 60)}</a>`)
      .replace(/\*\*([^*]+)\*\*|__([^_]+)__/g, (m, x, y) => `<strong>${x || y}</strong>`)
      .replace(/(^|[^\w*])\*([^*\s][^*]*)\*(?!\w)/g, '$1<em>$2</em>').replace(/(^|[^\w])_([^_\s][^_]*)_(?!\w)/g, '$1<em>$2</em>')
      .replace(/~~([^~]+)~~/g, '<del>$1</del>')
      .replace(/`([^`]+)`/g, '<code>$1</code>');
    const lines = src.split('\n');
    const out = [];
    let list = '', para = [];
    const flush = () => { if (para.length) { const p = inline(para.join(' ')); if (p.replace(/<[^>]+>/g, '').trim() || /<img/.test(p)) out.push(`<p>${p}</p>`); para = []; } };
    const endList = () => { if (list) { out.push(`</${list}>`); list = ''; } };
    for (let i = 0; i < lines.length; i++) {
      const rawLine = lines[i], line = rawLine.trim();
      const fence = line.match(/^(```|~~~)/);
      if (fence) {
        flush(); endList();
        const code = [];
        for (i++; i < lines.length && !lines[i].trim().startsWith(fence[1]); i++) code.push(lines[i]);
        out.push(`<pre><code>${esc(code.join('\n'))}</code></pre>`);
        continue;
      }
      if (/^\|.*\|$/.test(line) && /^\|?\s*:?-{2,}/.test((lines[i + 1] || '').trim())) {
        flush(); endList();
        const cells = (l) => l.trim().replace(/^\||\|$/g, '').split('|').map((c) => inline(c.trim()));
        const head = cells(line);
        const rows = [];
        for (i += 2; i < lines.length && /^\|.*\|$/.test(lines[i].trim()); i++) rows.push(cells(lines[i]));
        i--;
        out.push(`<div class="md-table"><table><thead><tr>${head.map((c) => `<th>${c}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${c}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
        continue;
      }
      const h = line.match(/^(#{1,6})\s+(.*?)\s*#*$/);
      if (h) { flush(); endList(); const n = Math.min(4, Math.max(2, h[1].length + 1)); out.push(`<h${n}>${inline(h[2])}</h${n}>`); continue; }
      const ul = line.match(/^[-*+]\s+(?:\[( |x)\]\s+)?(.*)$/i), ol = line.match(/^\d+[.)]\s+(.*)$/);
      if (ul || ol) {
        flush();
        const kind = ul ? 'ul' : 'ol';
        if (list !== kind) { endList(); out.push(`<${kind}>`); list = kind; }
        const sub = /^\s{2,}/.test(rawLine) ? ' class="sub"' : '';
        out.push(`<li${sub}>${ul && ul[1] ? (ul[1].toLowerCase() === 'x' ? '☑ ' : '☐ ') : ''}${inline(ul ? ul[2] : ol[1])}</li>`);
        continue;
      }
      if (!line) { flush(); endList(); continue; }
      if (/^(-{3,}|\*{3,}|_{3,})$/.test(line)) { flush(); endList(); out.push('<hr>'); continue; }
      if (line.startsWith('>')) { flush(); endList(); const q = line.replace(/^>\s?/, '').replace(/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]\s*/i, ''); if (q.trim()) out.push(`<blockquote>${inline(q)}</blockquote>`); continue; }
      if (list && /^\s{2,}/.test(rawLine)) { out[out.length - 1] = out[out.length - 1].replace(/<\/li>$/, ' ' + inline(line) + '</li>'); continue; }
      endList();
      para.push(line);
    }
    flush(); endList();
    return out.join('').replace(/<\/blockquote><blockquote>/g, '<br>');
  }

  // (2.8) A small picture for each line of "What's in it", from what the line is about.
  const FEAT_ICONS = [
    [/\b(fps|frame ?rate|framerate|60|120|144|smooth|performance|fast)\b/i, '<path d="M4 15a8 8 0 1 1 16 0"/><path d="M12 15l4-5"/>'],
    [/\b(4k|8k|resolution|widescreen|ultrawide|hd|1080|1440|graphics|render|ray|msaa|anti-?alias|texture|shadow|vulkan|d3d|directx)\b/i, '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'],
    [/\b(controller|gamepad|gyro|input|keyboard|mouse|rebind|button|xinput|sdl)\b/i, '<path d="M7 9h10a4 4 0 0 1 4 4v1a3 3 0 0 1-5.4 1.8L14 14h-4l-1.6 1.8A3 3 0 0 1 3 14v-1a4 4 0 0 1 4-4z"/><path d="M8 11v3M6.5 12.5h3"/>'],
    [/\b(mods?|modding|plugins?|texture packs?|custom)\b/i, '<path d="M10 4h4v3a2 2 0 1 0 0 4v3h-3a2 2 0 1 1-4 0H4v-4a2 2 0 1 0 0-4V4h6z"/>'],
    [/\b(language|translation|localiz|subtitles?)\b/i, '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.6 2.5 14.4 0 17M12 3.5c-2.5 2.6-2.5 14.4 0 17"/>'],
    [/\b(saves?|backup|cloud|progress)\b/i, '<path d="M5 4h11l3 3v13H5z"/><path d="M8 4v5h7V4M8 20v-6h8v6"/>'],
    [/\b(online|multiplayer|co-?op|netplay|players?)\b/i, '<circle cx="9" cy="9" r="3"/><circle cx="17" cy="10" r="2.5"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0M14 19a4 4 0 0 1 7 0"/>'],
    [/\b(audio|sound|music)\b/i, '<path d="M4 10v4h4l5 4V6L8 10z"/><path d="M16 9a4 4 0 0 1 0 6M18.5 6.5a7.5 7.5 0 0 1 0 11"/>'],
    [/\b(updates?|install|launcher|setup|plug and play|easy)\b/i, '<path d="M12 4v10M8 10l4 4 4-4"/><path d="M5 18h14"/>'],
    [/\b(settings|options|menu|config)\b/i, '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>'],
  ];
  const featIcon = (txt) => `<svg viewBox="0 0 24 24" aria-hidden="true">${(FEAT_ICONS.find(([re]) => re.test(txt)) || [null, '<path d="M12 3l2.4 5.6L20 9.3l-4.3 3.9 1.2 5.8L12 16l-4.9 3 1.2-5.8L4 9.3l5.6-.7z"/>'])[1]}</svg>`;
  const PC_GENRES = { action: N_('Action'), adventure: N_('Adventure'), platformer: N_('Platformers'), racing: N_('Racing'), fighting: N_('Fighting'), shooter: N_('Shooters'), sports: N_('Sports'), rpg: N_('RPG'), puzzle: N_('Puzzle'), other: N_('Other') };
  const PC_CATS = Object.keys(PC_GENRES).filter((k) => k !== 'other');

  function pcGame(id) {
    const a = pcById(id);
    if (!a) { pcTitle = 'RecompLauncher'; view.innerHTML = `<article class="page"><h1>${t('That page isn\'t here.')}</h1><p><a href="/pc">${t('Back to all PC recomps')}</a></p></article>`; return; }
    pcTitle = `${tf('{name} — PC recomp of {game}', { name: a.name, game: a.game || a.name })} · RecompLauncher`;
    if (a.hero) setBackdrop({ hero: a.hero });
    const os = myOS();
    const systems = (a.systems || []).slice().sort((x, y) => (x === os ? -1 : y === os ? 1 : 0));
    const when = a.released ? new Date(a.released + 'T12:00:00Z') : null;
    const first = a.first ? new Date(a.first + 'T12:00:00Z') : null;
    const facts = [
      [t('Runs on'), (a.systems || []).length ? `<span class="pc-sys">${a.systems.map((x) => `<span>${osIcon(x)}${SYS_NAMES[x]}</span>`).join('')}</span>` : ''],
      [t('Last updated'), when ? `${esc(ago(when.toISOString()))}<small>${esc(when.toLocaleDateString(locale(), { dateStyle: 'medium' }))}</small>` : ''],
      [t('Version'), esc(a.version || '')], [t('Download'), esc(a.size || '')],
      [t('Downloads'), a.downloads ? esc(fmtNum(a.downloads)) : ''], [t('Stars on GitHub'), a.stars ? esc(fmtNum(a.stars)) : ''],
      [t('Releases'), a.releases ? esc(fmtNum(a.releases)) : ''], [t('First release'), first ? esc(first.toLocaleDateString(locale(), { dateStyle: 'medium' })) : ''],
      [t('Open issues'), a.repo ? esc(fmtNum(a.issues || 0)) : ''], [t('License'), esc(a.license || '')]].filter(([, v]) => v);
    const tags = (a.tags || []).map((x) => `<span class="pc-pill plain">${esc(x)}</span>`).join('');
    const genre = a.genre && a.genre !== 'other' ? `<a class="pc-pill plain" href="/pc/all?kind=${esc(a.genre)}">${esc(t(PC_GENRES[a.genre]))}</a>` : '';
    const gh = `https://github.com/${esc(a.repo)}`;
    const feats = (a.features || []).length ? `<section class="glass pc-side-card pc-feats"><h2>${t("What's in it")}</h2><ul>${a.features.map((f) => `<li dir="auto">${featIcon(f)}<span>${esc(f)}</span></li>`).join('')}</ul></section>` : '';
    const about = a.description ? `<section class="glass pc-side-card pc-about"><h2>${t('About this recomp')}</h2><div class="pc-desc-body" dir="auto">${md(a.description)}</div></section>` : '';
    // Rows under the page: what people say about it elsewhere, games like it, and its developer's others.
    const media = (a.media || []).map((m) => m.type === 'youtube'
      ? `<a class="pc-media yt" href="${esc(m.url)}" target="_blank" rel="noopener" data-yt="${esc(m.id)}"><span class="pc-media-pic"><img src="https://i.ytimg.com/vi/${esc(m.id)}/hqdefault.jpg" alt="" loading="lazy"><i aria-hidden="true"></i></span><span class="pc-media-words"><b>YouTube</b><small>${t('Watch it here')}</small></span></a>`
      : `<a class="pc-media ${esc(m.type)}" href="${esc(m.url)}" target="_blank" rel="noopener"><span class="pc-media-pic"><span>${esc(m.type === 'x' ? 'X' : m.type === 'reddit' ? 'Reddit' : (m.host || '').replace(/\.[a-z]+$/, ''))}</span></span><span class="pc-media-words"><b>${esc(m.host || '')}</b><small dir="auto">${esc(m.title || m.url.replace(/^https:\/\/(www\.)?/, '').slice(0, 70))}</small></span></a>`).join('');
    const others = pcCat.apps.filter((x) => x.id !== a.id);
    const like = others.filter((x) => a.genre && a.genre !== 'other' && x.genre === a.genre)
      .concat(others.filter((x) => x.origin === a.origin && !(a.genre && x.genre === a.genre))).slice(0, 12);
    const mine = a.dev ? others.filter((x) => x.dev === a.dev) : [];
    const devName = a.dev && (((pcCat.developers || []).find((d) => d.slug === a.dev) || devBySlug(a.dev) || {}).name);
    const row = (title, items) => items.length ? `<section class="games pc-row"><div class="pc-row-head"><h2>${title}</h2></div><div class="rowscroll pc-posters">${items.map(pcPoster).join('')}</div></section>` : '';
    view.innerHTML = `
      <article class="game pc-game">
        <a class="back" href="/pc">${backArrow()} ${t('All PC recomps')}</a>
        <div class="game-grid">
          <div class="game-info">
            <div class="pc-game-head">
              ${a.cover ? `<img class="pc-game-cover" src="${esc(a.cover)}" alt="" width="200" height="300">` : ''}
              <div class="pc-game-words">
                <div class="pc-pills">${originPill(a.origin)}${genre}<span class="pc-pill plain">${t('Recomp')}</span>${aiChip(a, true)}${tags}</div>
                <h1 dir="auto">${esc(a.name)}</h1>
                ${a.game && a.game !== a.name ? `<p class="pc-based big">${SVG_DISC}${tf('Rebuilt from {game}', { game: `<strong dir="auto">${esc(a.game)}</strong>` })}</p>` : ''}
                ${pcByLine(a)}
                <p class="pc-head-verdict" data-head-verdict>${pcCounts ? verdictChip(pcCounts[a.id]) : ''}</p>
              </div>
            </div>
            ${a.blurb ? `<p class="lead" dir="auto">${esc(a.blurb)}</p>` : ''}
            <nav class="pc-tabs" role="tablist"><span class="pc-tab-mark" aria-hidden="true"></span>
              <button type="button" role="tab" data-tab="readme" class="on" aria-selected="true">${t('From the developer')}</button>
              <button type="button" role="tab" data-tab="releases">${t('Updates')}</button>
              <button type="button" role="tab" data-tab="feedback">${t('Player reports')}<span data-fb-n></span></button>
            </nav>
            <div class="pc-tab-wrap">
              <section class="pc-tab glass pc-readme" data-panel="readme"><p class="muted">${t('Loading…')}</p></section>
              <section class="pc-tab glass notes pc-releases" data-panel="releases" hidden><p class="muted">${t('Loading the notes from GitHub…')}</p></section>
              <section class="pc-tab" data-panel="feedback" hidden></section>
            </div>
            ${a.credits ? `<p class="credits" dir="auto">${esc(a.credits)}</p>` : ''}
            ${aiNoteHTML(a, true)}
            ${pcNoFiles()}
          </div>
          <aside class="pc-side">
            <div class="glass panel">
              <div class="panel-head">${a.icon ? `<img src="${esc(a.icon)}" alt="" width="112" height="112" class="pc-panel-ico">` : a.cover ? `<img src="${esc(a.cover)}" alt="" class="pc-panel-ico cover">` : ''}<div><strong dir="auto">${esc(a.name)}</strong><span>${esc(originName(a.origin))}</span></div></div>
              ${a.soon ? `<div class="pc-dl pc-dl-soon"><span class="pc-soon-pill">${t('Coming soon')}</span><a class="btn primary" href="${gh}" target="_blank" rel="noopener">${t('Follow it on GitHub')}</a></div>` : `<div class="pc-dl" data-dl>${systems.map((sx, i) => `<a class="btn${i === 0 ? ' primary' : ''}" href="${gh}/releases/latest" target="_blank" rel="noopener" data-sys="${sx}">${osIcon(sx)}${tf('Download for {system}', { system: SYS_NAMES[sx] })}</a>`).join('') || `<a class="btn primary" href="${gh}/releases/latest" target="_blank" rel="noopener">${t("The developer's release")}</a>`}</div>`}
              <div class="pc-links">
                <a href="${gh}" target="_blank" rel="noopener">${t('View on GitHub')}<span aria-hidden="true">↗</span></a>
                <a href="${gh}/releases" target="_blank" rel="noopener">${t('All releases')}<span aria-hidden="true">↗</span></a>
                <a href="${gh}/issues" target="_blank" rel="noopener">${t('Report an issue')}<span aria-hidden="true">↗</span></a>
              </div>
              ${a.needs ? `<p class="note needs"><strong>${t('You bring')}:</strong> ${esc(a.needs)}</p>` : ''}
              <dl class="pc-facts">${facts.map(([k, v]) => `<div><dt>${k}</dt><dd dir="auto">${v}</dd></div>`).join('')}</dl>
              <p class="note">${t('Each download comes straight from the developer\'s own GitHub release.')}</p>
            </div>
            ${feats}
            ${about}
          </aside>
        </div>
        ${media ? `<section class="games pc-row"><div class="pc-row-head"><h2>${t('Around the web')}</h2></div><div class="rowscroll pc-media-row">${media}</div></section>` : ''}
        ${mine.length ? row(tf('More from {name}', { name: esc(devName || a.author || '') }), mine) : ''}
        ${row(t('More like this'), like)}
      </article>`;
    // The tabs: each opens with a quick fade, the marker slides under it, and each loads once.
    const loaded = {};
    const tabs = view.querySelector('.pc-tabs'), mark = tabs.querySelector('.pc-tab-mark');
    const place = (b) => { mark.style.width = b.offsetWidth + 'px'; mark.style.transform = `translateX(${b.offsetLeft - 5}px)`; };
    const open = (name, first) => {
      let btn = null;
      tabs.querySelectorAll('[data-tab]').forEach((b) => { const on = b.dataset.tab === name; b.classList.toggle('on', on); b.setAttribute('aria-selected', on ? 'true' : 'false'); if (on) btn = b; });
      if (btn) place(btn);
      view.querySelectorAll('.pc-tab').forEach((p) => {
        const show = p.dataset.panel === name;
        p.hidden = !show;
        if (show && !first && !reduced) { p.classList.remove('pc-in'); void p.offsetWidth; p.classList.add('pc-in'); }
      });
      if (loaded[name]) return;
      loaded[name] = true;
      const panel = view.querySelector(`[data-panel="${name}"]`);
      if (name === 'readme') {
        fetch('/api/pc.php?a=readme&id=' + encodeURIComponent(a.id)).then((r) => r.json()).then((j) => {
          if (!j.ok) { panel.innerHTML = `<p class="muted">${esc(j.error || t('No README on its GitHub.'))}</p>`; return; }
          panel.innerHTML = `<div class="md-body" dir="auto">${mdFull(j.md, j.raw, j.blob)}</div><a class="src" href="${esc(j.url || gh)}" target="_blank" rel="noopener">${t('Read it on GitHub')} ↗</a>`;
        }).catch(() => { panel.innerHTML = `<p class="muted">${t('GitHub didn\'t answer. Try again in a moment.')}</p>`; });
      } else if (name === 'releases') loadNotes(a.repo, panel);
      else if (name === 'feedback') pcFeedback(a, panel);
    };
    // (2.9) A long tab (a big README, many updates) folds to where the side column ends, with Show more,
    // so the rows under it (the AI note, no game files, More like this) aren't pushed far down.
    const fold = (panel) => {
      if (!panel || !panel.isConnected || panel.hidden) return;
      const more = panel.nextElementSibling && panel.nextElementSibling.classList.contains('pc-more') ? panel.nextElementSibling : null;
      if (panel.dataset.open === '1') return;
      panel.style.maxHeight = '';
      panel.classList.remove('pc-folded');
      const side = view.querySelector('.pc-side'), pr = panel.getBoundingClientRect();
      const sr = side ? side.getBoundingClientRect() : null;
      const beside = sr && sr.width && sr.left >= pr.right - 4;
      const h = Math.max(beside ? sr.bottom - pr.top : 0, 520);
      const long = panel.scrollHeight > h + 160;
      if (long) { panel.style.maxHeight = h + 'px'; panel.classList.add('pc-folded'); }
      if (long && !more) {
        panel.insertAdjacentHTML('afterend', `<button type="button" class="pc-more">${t('Show more')}</button>`);
        const b = panel.nextElementSibling;
        b.addEventListener('click', () => {
          const opening = panel.dataset.open !== '1';
          panel.dataset.open = opening ? '1' : '';
          if (opening) { panel.style.maxHeight = ''; panel.classList.remove('pc-folded'); b.textContent = t('Show less'); }
          else { b.textContent = t('Show more'); fold(panel); const top = panel.getBoundingClientRect().top + window.scrollY - 110; if (window.scrollY > top) window.scrollTo({ top, behavior: 'smooth' }); }
        });
      } else if (!long && more) more.remove();
      if (more) more.hidden = false;
    };
    const foldOpen = () => view.querySelectorAll('.pc-tab').forEach((p) => {
      const more = p.nextElementSibling && p.nextElementSibling.classList.contains('pc-more') ? p.nextElementSibling : null;
      if (p.hidden) { if (more) more.hidden = true; } else fold(p);
    });
    // Each tab folds once its words are in, and again as its pictures load.
    const watch = new MutationObserver(() => requestAnimationFrame(foldOpen));
    view.querySelectorAll('.pc-tab').forEach((p) => watch.observe(p, { childList: true, subtree: true }));
    view.addEventListener('load', (e) => { if (e.target.tagName === 'IMG' && e.target.closest('.pc-tab')) requestAnimationFrame(foldOpen); }, true);
    window.addEventListener('resize', () => { if (view.contains(tabs)) foldOpen(); }, { passive: true });
    tabs.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { open(b.dataset.tab); requestAnimationFrame(foldOpen); }));
    open('readme', true);
    requestAnimationFrame(() => { const b = tabs.querySelector('.on'); if (b) { place(b); tabs.classList.add('ready'); } });
    window.addEventListener('resize', () => { const b = tabs.querySelector('.on'); if (b && b.isConnected) place(b); }, { passive: true });
    // A video plays right here, from YouTube's privacy-friendly player.
    view.querySelectorAll('[data-yt]').forEach((el) => el.addEventListener('click', (e) => {
      e.preventDefault();
      const pic = el.querySelector('.pc-media-pic');
      pic.innerHTML = `<iframe src="https://www.youtube-nocookie.com/embed/${encodeURIComponent(el.dataset.yt)}?autoplay=1&rel=0" title="YouTube" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen></iframe>`;
      el.classList.add('playing');
    }));
    // How many have said how it plays, on the tab, once known.
    pcCountsLoad().then((c) => {
      const v = verdictOf(c[a.id]);
      const n = view.querySelector('[data-fb-n]'), hv = view.querySelector('[data-head-verdict]');
      if (n && v) n.textContent = ' ' + fmtNum(v.n);
      if (hv && !hv.innerHTML.trim()) hv.innerHTML = verdictChip(c[a.id]);
    });
    // The real files from its latest release, straight from GitHub (through this site).
    const pat = (p) => new RegExp('^' + String(p).replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$', 'i');
    fetch('/api/gh.php?repo=' + encodeURIComponent(a.repo)).then((r) => r.json()).then((rels) => {
      const rel = (Array.isArray(rels) ? rels : []).find((r) => !r.draft && !r.prerelease) || (Array.isArray(rels) ? rels[0] : null);
      if (!rel) return;
      view.querySelectorAll('[data-sys]').forEach((b) => {
        const p = (a.assets || {})[b.dataset.sys];
        const hit = p && (rel.assets || []).find((x) => pat(p).test(x.name));
        if (hit) { b.href = hit.browser_download_url; b.title = hit.name; }
      });
    }).catch(() => { /* the button still opens its releases */ });
  }

  // (2.8) Player feedback: how it runs for people, on what, and a few words.
  function pcFeedback(a, panel) {
    const SYS = [['windows', 'Windows'], ['linux', 'Linux'], ['macos', 'macOS'], ['deck', 'Steam Deck']];
    const mineSys = { windows: 'windows', linux: 'linux', macos: 'macos' }[myOS()] || 'windows';
    panel.innerHTML = `<div class="pc-fb-sum glass" data-sum></div>
      <form class="glass form pc-fb-form" novalidate>
        <h3>${t('How does it play for you?')}</h3>
        <div class="pc-fb-verdicts">${Object.keys(VERDICTS).map((k) => `<label class="v-${k}"><input type="radio" name="verdict" value="${k}"><span><i aria-hidden="true"></i>${t(VERDICTS[k])}</span></label>`).join('')}</div>
        <div class="two">
          <label>${t('You play on')}<select name="sys">${SYS.map(([k, n]) => `<option value="${k}"${k === mineSys ? ' selected' : ''}>${n}</option>`).join('')}</select></label>
          <label>${t('Version')}<input name="version" maxlength="40" value="${esc(a.version || '')}"></label>
        </div>
        <label>${t('Anything others should know?')} <span class="opt">${t('optional')}</span><textarea name="note" rows="3" maxlength="500" placeholder="${t('What works, what doesn\'t, settings that helped')}"></textarea></label>
        <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
        <div class="form-row"><button class="btn primary" type="submit">${t('Send')}</button><span class="status" role="status"></span></div>
      </form>
      <div class="pc-fb-list" data-list></div>`;
    const draw = () => fetch('/api/pc.php?a=feedback&id=' + encodeURIComponent(a.id), { cache: 'no-store' }).then((r) => r.json()).then((j) => {
      if (!j.ok) return;
      const c = j.counts, n = c.runs + c.issues + c.broken;
      const pct = (x) => (n ? Math.round((x / n) * 100) : 0);
      panel.querySelector('[data-sum]').innerHTML = n ? `
        <div class="pc-fb-bar">${['runs', 'issues', 'broken'].map((k) => c[k] ? `<span class="v-${k}" style="flex:${c[k]}"></span>` : '').join('')}</div>
        <div class="pc-fb-legend">${['runs', 'issues', 'broken'].map((k) => `<span class="v-${k}"><i aria-hidden="true"></i>${t(VERDICTS[k])}<b>${fmtNum(c[k])}</b><small>${pct(c[k])}%</small></span>`).join('')}</div>`
        : `<p class="muted">${t('No reports yet. Tell others how it plays for you.')}</p>`;
      panel.querySelector('[data-list]').innerHTML = j.items.map((x) => `
        <article class="glass pc-fb-item">
          <div class="pc-fb-top"><span class="pc-verdict v-${esc(x.verdict)}"><i aria-hidden="true"></i>${t(VERDICTS[x.verdict] || '')}</span>
            <span class="muted">${esc((SYS.find((s) => s[0] === x.sys) || ['', ''])[1])}${x.version ? ' · ' + esc(x.version) : ''} · ${esc(ago(x.when))}</span></div>
          ${x.note ? `<p dir="auto">${esc(x.note)}</p>` : ''}
        </article>`).join('');
      const tab = view.querySelector('[data-fb-n]');
      if (tab) tab.textContent = n ? ' ' + fmtNum(n) : '';
    }).catch(() => {});
    draw();
    const form = panel.querySelector('form'), status = form.querySelector('.status');
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const v = form.querySelector('input[name="verdict"]:checked');
      if (!v) { status.textContent = t('Pick how it plays.'); status.className = 'status bad'; return; }
      const fd = new FormData(form);
      fd.set('a', 'feedback'); fd.set('id', a.id);
      status.textContent = t('Sending…'); status.className = 'status';
      fetch('/api/pc.php', { method: 'POST', body: fd }).then((r) => r.json()).then((j) => {
        if (!j.ok) { status.textContent = j.error || t('That didn\'t go through. Try again.'); status.className = 'status bad'; return; }
        status.textContent = t('Thanks! It\'s on the page.'); status.className = 'status ok';
        form.note.value = '';
        pcCounts = null;
        draw();
      }).catch(() => { status.textContent = t('That didn\'t go through. Try again.'); status.className = 'status bad'; });
    });
  }

  // ---- (2.0) A phone or computer linked to a PS5 ---------------------------------------------
  // me: what /api/link.php says about this browser ({linked, device, console, state}). Linked, a
  // game's page installs it on the PS5, and My PS5 shows what's on it.
  let me = null;
  let pageTimers = [];
  const later = (fn, ms) => { const id = setTimeout(fn, ms); pageTimers.push(id); return id; };
  const every = (fn, ms) => { const id = setInterval(fn, ms); pageTimers.push(id); return id; };
  const stopPageTimers = () => { pageTimers.forEach((id) => { clearTimeout(id); clearInterval(id); }); pageTimers = []; };
  const failed = () => ({ ok: false, error: t('That didn\'t go through. Check your connection and try again.') });
  const api = (a, data) => fetch('/api/link.php', {
    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ a }, data || {})),
  }).then((r) => r.json().catch(failed)).catch(failed);
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
    const apps = (raw.apps || []).concat(raw.newerStoreApps || [], on ? raw.v2Apps || [] : []);
    apps.sort((x, y) => (x.order ?? 1e9) - (y.order ?? 1e9)); // the admin's order, all lists together
    const soon = (raw.comingSoon || []).concat(on ? raw.comingSoonV2 || [] : []).sort((x, y) => (x.order ?? 1e9) - (y.order ?? 1e9));
    cat.apps = apps;
    cat.soon = soon;
    cat.categories = raw.categories || {}; // (2.1) each category's sort
    menuCats();
    footerHTML();
  }
  // What to call this browser: a phone, or a computer (once linked, the name the site gave it).
  const deviceWord = () => (matchMedia('(pointer: coarse)').matches || /Mobi|iPhone|iPad|Android/i.test(navigator.userAgent) ? 'phone' : 'computer');
  // (2.6) Sentences about this browser come whole for a phone and a computer (and with the name the site
  // gave it, once linked), so each language can say them its own way.
  const deviceName = () => (me && me.device && me.device.label && me.device.label !== 'Phone or computer' ? me.device.label : '');
  const byDevice = (phone, computer, v) => tf(deviceWord() === 'phone' ? phone : computer, v);
  const byThisDevice = (phone, computer, named, html) => (deviceName() ? tf(named, { device: html ? esc(deviceName()) : deviceName() }) : byDevice(phone, computer));
  const code6 = (c) => String(c || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 6);
  const show6 = (c) => (c.length > 3 ? c.slice(0, 3) + '-' + c.slice(3) : c);
  const show8 = (c) => String(c).replace(/^(\d{2})(\d{3})(\d{3})$/, '$1-$2-$3');
  function ago(iso) {
    const s = Math.max(0, (Date.now() - Date.parse(iso)) / 1000);
    if (!Number.isFinite(s)) return '';
    if (s < 90) return t('just now');
    const [v, unit] = s < 3600 ? [Math.round(s / 60), 'minute'] : s < 86400 * 1.5 ? [Math.round(s / 3600), 'hour'] : [Math.round(s / 86400), 'day'];
    // (2.6) In the visitor's language, as the browser says it.
    try { return new Intl.RelativeTimeFormat(locale(), { numeric: 'always' }).format(-v, unit); } catch (e) { return v + ' ' + unit + (v === 1 ? '' : 's') + ' ago'; }
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
      <h1>${t('Linked')}</h1>
      <p class="lead">${byDevice('This phone and your PS5 are linked. Install apps on it from any game\'s page here, or open My PS5 to see what\'s on it.', 'This computer and your PS5 are linked. Install apps on it from any game\'s page here, or open My PS5 to see what\'s on it.')}</p>
      <div class="btn-col"><a class="btn primary big" href="/my-ps5">${t('Open My PS5')}</a><a class="btn" href="/">${t('Browse the store')}</a></div>`;
  }

  // recompstore.com/l/<code>: the code the PS5 shows (its QR code opens this), one button.
  function linkClaimPage(code) {
    const c = code6(code);
    view.innerHTML = `
      <article class="form-page link-page">
        <div class="glass link-card">
          ${linkArt(false)}
          <span class="kicker">RecompStore</span>
          <h1>${byDevice('Link this phone to your PS5', 'Link this computer to your PS5')}</h1>
          <p class="lead">${tf('Your PS5 is showing {code}. Once they\'re linked, you can install apps and add sources on your PS5 from here.', { code: `<strong class="code-inline">${esc(show6(c))}</strong>` })}</p>
          <button class="btn primary big" type="button" data-claim>${byDevice('Link this phone', 'Link this computer')}</button>
          <p class="status" role="status"></p>
          <p class="small">${me && me.linked ? t('This browser is linked to a PS5 already. Linking moves it to this one.') + ' ' : ''}${t('No account needed. Unlink it any time, here or in RecompStore\'s Settings.')}</p>
        </div>
      </article>`;
    setBackdrop(null);
    const card = view.querySelector('.link-card');
    const btn = card.querySelector('[data-claim]');
    const st = card.querySelector('.status');
    btn.addEventListener('click', () => {
      btn.disabled = true;
      st.textContent = t('Linking…'); st.className = 'status';
      api('claim', { code: c }).then((res) => {
        if (!res.ok) { st.textContent = res.error || t('That code didn\'t work. Make a new one on your PS5.'); st.className = 'status bad'; btn.disabled = false; return; }
        refreshMe().then(() => linkedDone(card));
      });
    });
  }

  // #/link: type the code the PS5 shows, or make a code here to type on the PS5.
  function linkPage() {
    view.innerHTML = `
      <article class="form-page link-page">
        <a class="back" href="/">${backArrow()} ${t('All games')}</a>
        <h1>${t('Link your PS5')}</h1>
        <p class="lead">${byDevice('Link this phone to RecompStore on your PS5. Then install apps and add sources on it from here, without the controller. No account needed.', 'Link this computer to RecompStore on your PS5. Then install apps and add sources on it from here, without the controller. No account needed.')}</p>
        ${me && me.linked ? `<p class="glass note-card">${byDevice('This phone is linked already. {link}, or link it again below to move it to another PS5.', 'This computer is linked already. {link}, or link it again below to move it to another PS5.', { link: `<a href="/my-ps5">${t('Open My PS5')}</a>` })}</p>` : ''}
        <div class="link-ways">
          <form class="glass form" data-way="ps5" novalidate>
            <span class="kicker">${t('Your PS5 shows a code')}</span>
            <p class="form-note">${t('In RecompStore on your PS5, open Settings and choose to link a phone. Scan the QR code it shows, or type its code here.')}</p>
            <label>${t('The code on your PS5')}<input name="code" class="code-input" maxlength="7" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="K7Q-4X2"></label>
            <div class="form-row"><button class="btn primary" type="submit">${t('Link')}</button><span class="status" role="status"></span></div>
          </form>
          <div class="glass form" data-way="web">
            <span class="kicker">${t('Or type a code on your PS5')}</span>
            <p class="form-note">${t('Make a code here, then type it on your PS5 where you redeem codes: Settings, then Redeem a code.')}</p>
            <div class="webcode" hidden><strong class="big-code"></strong><span class="muted" data-left></span></div>
            <div class="form-row"><button class="btn" type="button" data-make>${t('Make a code')}</button><span class="status" role="status"></span></div>
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
      if (c.length !== 6) { st.textContent = t('The code has 6 letters and numbers, like K7Q-4X2.'); st.className = 'status bad'; input.focus(); return; }
      const b = f.querySelector('button');
      b.disabled = true; st.textContent = t('Linking…'); st.className = 'status';
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
      make.disabled = true; wst.textContent = t('Making a code…'); wst.className = 'status';
      const before = me && me.linked && me.console ? me.console.id : '';
      api('webcode').then((res) => {
        make.disabled = false;
        if (!res.ok) { wst.textContent = res.error; wst.className = 'status bad'; return; }
        stopPageTimers();
        web.querySelector('.webcode').hidden = false;
        web.querySelector('.big-code').textContent = show8(res.code);
        make.textContent = t('Make another');
        wst.textContent = t('Waiting for your PS5…'); wst.className = 'status';
        const until = Date.now() + (res.expires || 600) * 1000;
        const left = web.querySelector('[data-left]');
        const tick = () => {
          const s = Math.max(0, Math.round((until - Date.now()) / 1000));
          left.textContent = s ? tf('Works for {time} more', { time: `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}` }) : t('This code ran out. Make another.');
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
    view.innerHTML = `<article class="form-page my-ps5"><a class="back" href="/">${backArrow()} ${t('All games')}</a><h1>${t('My PS5')}</h1><div data-my><p class="muted">${t('Loading…')}</p></div></article>`;
    setBackdrop(null);
    refreshMe().then(drawMy);
    every(() => refreshMe().then(() => { if (!document.activeElement || !view.contains(document.activeElement) || document.activeElement === document.body) drawMy(); }), 30000);
  }
  // (2.3.0) Requests sent from here (Delete, Move, ...): their number, until the PS5 says how it went.
  const myReqs = {};
  let reqTimer = 0;
  const gb = (b) => (Number(b) / 1073741824 >= 1000 ? tf('{size} TB', { size: fmtNum(Number(b) / 1099511627776, 2) }) : tf('{size} GB', { size: fmtNum(Number(b) / 1073741824, 1) }));
  const sizeWords = (b) => (Number(b) > 0 ? (Number(b) >= 1073741824 ? gb(b) : tf('{size} MB', { size: Math.max(1, Math.round(Number(b) / 1048576)) })) : '');
  function reqLine(key) {
    const st = me && me.state || {};
    const mine = Object.entries(myReqs).filter(([, r]) => r.key === key).map(([id]) => Number(id)).sort((x, y) => y - x)[0];
    if (!mine) return '';
    const r = (Array.isArray(st.requests) ? st.requests : []).find((q) => Number(q.id) === mine);
    if (!r || r.state === 'waiting') return `<span class="req-line">${t('Waiting for you to confirm on the PS5…')}</span>`;
    return `<span class="req-line ${r.state === 'done' ? 'ok' : 'bad'}">${esc(r.state === 'ignored' ? t('Ignored on the PS5.') : r.text || (r.state === 'done' ? t('Done.') : t('It didn\'t work.')))}</span>`;
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
        ${a ? `<a href="/game/${encodeURIComponent(a.id)}">${iconHTML(a, 56)}</a>` : `<span class="ico mono">${esc(String(name).slice(0, 2))}</span>`}
        <span class="ps5-app-words"><strong>${a ? `<a href="/game/${encodeURIComponent(a.id)}">${esc(name)}</a>` : esc(name)}</strong>
          <span>${x.version ? esc(x.version) : t('Installed')}${x.update ? ` · <em class="upd">${tf('{version} ready', { version: esc(x.update) })}</em>` : ''}${v2 && x.size > 0 ? ' · ' + sizeWords(x.size) : ''}${v2 && x.where ? ' · ' + esc(x.where) : ''}</span>
          ${v2 ? reqLine(key) : ''}</span>
        ${x.update ? `<button class="btn small" type="button" data-send-app="${esc(x.id)}">${t('Update')}</button>` : ''}
        ${v2 ? `<span class="ps5-acts">
          ${x.store ? `<button class="chip-btn${x.fav ? ' on' : ''}" type="button" data-act="favorite" data-on="${x.fav ? 0 : 1}" aria-pressed="${x.fav ? 'true' : 'false'}">${x.fav ? '★' : '☆'} ${t('Favorite')}</button>
          <button class="chip-btn${x.auto ? ' on' : ''}" type="button" data-act="autoupdate" data-on="${x.auto ? 0 : 1}" aria-pressed="${x.auto ? 'true' : 'false'}">${x.auto ? t('Auto-update on') : t('Auto-update off')}</button>` : ''}
          ${drives.length ? `<select class="chip-btn" data-act="move" aria-label="${tf('Move {name} to', { name: esc(name) })}"><option value="">${t('Move to…')}</option>${drives.map((d) => `<option value="${esc(d.root)}">${esc(d.label)}</option>`).join('')}</select>` : ''}
          <button class="chip-btn danger" type="button" data-act="delete">${t('Delete')}</button>
        </span>` : ''}
      </li>`;
  }
  function storageHTML(st) {
    const drives = Array.isArray(st.drives) ? st.drives : [];
    if (!drives.length) return '';
    return `<h2 class="my-h2">${t('Storage')}</h2><div class="glass drives-card">${drives.map((d) => {
      const sized = d.sized && Number(d.total) > 0;
      const used = sized ? Math.min(100, Math.max(0, 100 * (1 - Number(d.free) / Number(d.total)))) : 0;
      return `<div class="drive-row"><span class="drive-name">${esc(d.label)}</span><span class="drive-num">${sized ? tf('{free} free of {total}', { free: gb(d.free), total: gb(d.total) }) : t('Size unknown')}</span>
        <span class="drive-bar${sized ? '' : ' unknown'}"><i style="width:${used.toFixed(1)}%"></i></span></div>`;
    }).join('')}</div>`;
  }
  function downloadsHTML(st) {
    const q = st.queue || {};
    const waiting = Array.isArray(q.waiting) ? q.waiting : [];
    if (!q.now && !waiting.length) return '';
    // The app's name and icon from the catalog; the PS5's name for it, else its ID, for one that isn't in it.
    const names = q.names && typeof q.names === 'object' ? q.names : {};
    const nm = (id) => { const a = byId(id); return esc((a && a.name) || names[id] || id); };
    const ico = (id) => { const a = byId(id); return a ? iconHTML(a, 48) : `<span class="ico mono">${esc(String(names[id] || id).slice(0, 2))}</span>`; };
    const pct = Number(q.total) > 0 ? Math.round(100 * Number(q.done) / Number(q.total)) : -1;
    return `<h2 class="my-h2">${t('Downloads')}</h2><ul class="ps5-apps">
      ${q.now ? `<li class="glass ps5-app">${ico(q.now)}<span class="ps5-app-words"><strong>${nm(q.now)}</strong><span>${q.step ? esc(q.step) : t('Installing')}${pct >= 0 ? ' · ' + pct + '%' : ''}</span>
        ${pct >= 0 ? `<span class="dl-bar"><i style="width:${pct}%"></i></span>` : ''}</span></li>` : ''}
      ${waiting.filter((id) => id !== q.now).map((id) => `<li class="glass ps5-app" data-key="${esc(id)}">${ico(id)}<span class="ps5-app-words"><strong>${nm(id)}</strong><span>${t('Waiting')}</span>${reqLine(id)}</span>
        <button class="btn small" type="button" data-act="cancel">${t('Cancel')}</button></li>`).join('')}
    </ul>`;
  }
  function sendAct(kind, key, extra, btn) {
    const st = me && me.state || {};
    const x = (Array.isArray(st.apps) ? st.apps : []).find((y) => (y.id || y.folder) === key) || { id: key };
    if (kind === 'delete' && !confirm(tf('Ask your PS5 to delete {name}? It asks you to confirm on the PS5 first.', { name: x.name || key }))) return;
    const data = Object.assign(x.id ? { app: x.id } : { folder: x.folder }, extra || {});
    if (btn) btn.disabled = true;
    api('send', { kind, data }).then((res) => {
      if (btn) btn.disabled = false;
      if (!res.ok) { alert(res.error || t('That didn\'t go through.')); return; }
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
    if (li && confirm(tf('Ask your PS5 to move it to {drive}? It asks you to confirm on the PS5 first.', { drive: label }))) sendAct('move', li.dataset.key, { to }, sel);
  });
  function drawMy() {
    const box = view.querySelector('[data-my]');
    if (!box) return;
    if (!me || !me.linked) {
      box.innerHTML = `
        <div class="glass link-card">
          ${linkArt(false)}
          <p class="lead">${byDevice('This phone isn\'t linked to a PS5 yet. Link it to see what\'s on your PS5 and install apps on it from here.', 'This computer isn\'t linked to a PS5 yet. Link it to see what\'s on your PS5 and install apps on it from here.')}</p>
          <a class="btn primary big" href="/link">${t('Link your PS5')}</a>
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
          <strong>${t('Your PS5')}</strong>
          <span>${me.console.store ? 'RecompStore ' + esc(me.console.store) + ' · ' : ''}${tf('seen {when}', { when: esc(ago(me.console.seen)) })}${Number.isFinite(free) && free > 0 ? ' · ' + tf('{size} GB free', { size: fmtNum(free, 1) }) : ''}</span>
        </div>
        <span class="tag">${byThisDevice('Linked to this phone', 'Linked to this computer', 'Linked to this {device}', true)}</span>
      </section>
      ${storageHTML(st)}
      ${downloadsHTML(st)}
      <h2 class="my-h2">${t('On your PS5')} <span class="muted">${apps.length || ''}</span></h2>
      ${ups.length ? `<p class="glass note-card upd-card">${tn(ups.length, '1 update is ready. Install it from here, or from Updates on your PS5.', '{n} updates are ready. Install them from here, or from Updates on your PS5.')}</p>` : ''}
      ${apps.length ? `<ul class="ps5-apps">${apps.map(appRow).join('')}</ul>`
        : `<p class="muted">${me.state_at ? t('Nothing from RecompStore is installed on it yet.') : t('Open RecompStore on your PS5 and what\'s on it shows up here.')}</p>`}
      <div class="my-forms">
        ${Number(st.v) >= 2 ? `<form class="glass form" data-type-form novalidate>
          <span class="kicker">${t('Type on your PS5')}</span>
          <p class="form-note">${t('Open the keyboard on your PS5 (a search, a message), then type here instead.')}</p>
          <label>${t('Text')}<input name="t" maxlength="200" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="${t('What to type')}"></label>
          <div class="form-row"><button class="btn primary" type="submit">${t('Send to the keyboard')}</button><span class="status" role="status"></span></div>
        </form>` : ''}
        <form class="glass form" data-send-form novalidate>
          <span class="kicker">${t('Install an app')}</span>
          <label>${t('App')}<select name="app"><option value="">${t('Choose an app')}</option>${sendable.map((a) => `<option value="${esc(a.id)}">${esc(a.name)}</option>`).join('')}</select></label>
          <div class="form-row"><button class="btn primary" type="submit">${t('Install on your PS5')}</button><span class="status" role="status"></span></div>
        </form>
        <form class="glass form" data-source-form novalidate>
          <span class="kicker">${t('Add a custom source')}</span>
          <p class="form-note">${t('A GitHub repo (owner/name) or the https:// address of a list of them. Apps from it aren\'t checked by RecompStore, and your PS5 asks you before adding it.')}</p>
          <label>${t('Repo or list')}<input name="v" maxlength="500" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="owner/name"></label>
          <div class="src-preview" hidden></div>
          <div class="form-row"><button class="btn" type="submit" data-step="check">${t('Check it')}</button><span class="status" role="status"></span></div>
          ${srcs.length ? `<div class="srcs"><span class="muted">${t('On your PS5:')}</span> ${srcs.map((x) => `<span class="tag">${esc(x.name || x.value)}</span>`).join(' ')}</div>` : ''}
        </form>
        <form class="glass form" data-redeem-form novalidate>
          <span class="kicker">${t('Redeem a beta code')}</span>
          <label>${t('Code')}<input name="code" inputmode="numeric" maxlength="10" autocomplete="off" placeholder="12-345-678"></label>
          <div class="form-row"><button class="btn" type="submit">${t('Redeem')}</button><span class="status" role="status"></span></div>
        </form>
      </div>
      <p class="unlink-row"><button class="linkish" type="button" data-forget>${byThisDevice('Unlink this phone', 'Unlink this computer', 'Unlink this {device}', true)}</button></p>`;
    // (2.3.0) Typing on the PS5's open keyboard
    const typeForm = box.querySelector('[data-type-form]');
    if (typeForm) typeForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const s = typeForm.querySelector('.status'), text = typeForm.querySelector('[name="t"]').value.trim();
      if (!text) { s.textContent = t('Type something first.'); s.className = 'status bad'; return; }
      const b = typeForm.querySelector('button');
      b.disabled = true; s.textContent = t('Sending…'); s.className = 'status';
      api('send', { kind: 'text', data: { text } }).then((res) => {
        b.disabled = false;
        s.textContent = res.ok ? t('Sent. It shows up in the PS5\'s keyboard in a few seconds.') : res.error || t('That didn\'t go through.');
        s.className = res.ok ? 'status ok' : 'status bad';
        if (res.ok) typeForm.querySelector('[name="t"]').value = '';
      });
    });
    // Install an app
    const sf = box.querySelector('[data-send-form]');
    sf.addEventListener('submit', (e) => {
      e.preventDefault();
      const s = sf.querySelector('.status');
      const id = sf.querySelector('[name="app"]').value;
      if (!id) { s.textContent = t('Choose an app.'); s.className = 'status bad'; return; }
      sendInstall(id, s, sf.querySelector('button'));
    });
    // A custom source: checked here first, then sent.
    const src = box.querySelector('[data-source-form]');
    const prev = src.querySelector('.src-preview');
    const sbtn = src.querySelector('button');
    const sst = src.querySelector('.status');
    let checked = null;
    const sv = src.querySelector('[name="v"]');
    sv.addEventListener('input', () => { checked = null; prev.hidden = true; sbtn.textContent = t('Check it'); sst.textContent = ''; });
    src.addEventListener('submit', (e) => {
      e.preventDefault();
      const v = sv.value.trim().replace(/^https:\/\/(www\.)?github\.com\//i, '').replace(/\/+$/, '');
      const isRepo = /^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/.test(v);
      const isList = /^https:\/\/\S+$/.test(sv.value.trim());
      if (!isRepo && !isList) { sst.textContent = t('Enter a GitHub repo like owner/name, or a list\'s https:// address.'); sst.className = 'status bad'; return; }
      if (checked) {
        sbtn.disabled = true; sst.textContent = t('Sending…'); sst.className = 'status';
        api('send', { kind: 'source', data: checked }).then((res) => {
          sbtn.disabled = false;
          sst.textContent = res.ok ? t('Sent. Your PS5 asks you to confirm it, then adds it.') : res.error; sst.className = res.ok ? 'status ok' : 'status bad';
          if (res.ok) { checked = null; sbtn.textContent = t('Check it'); }
        });
        return;
      }
      sbtn.disabled = true; sst.textContent = t('Checking…'); sst.className = 'status';
      fetch('/api/source.php?' + (isRepo ? 'repo=' + encodeURIComponent(v) : 'list=' + encodeURIComponent(sv.value.trim())))
        .then((r) => r.json()).catch(() => ({ ok: false, error: t('That couldn\'t be checked right now. Try again in a moment.') }))
        .then((res) => {
          sbtn.disabled = false;
          if (!res.ok) { sst.textContent = res.error; sst.className = 'status bad'; prev.hidden = true; return; }
          const apps = res.apps || [];
          prev.hidden = false;
          prev.innerHTML = `
            <strong>${esc(res.source && res.source.name)}</strong>${res.source && res.source.author ? ` <span class="muted">${tf('by {author}', { author: esc(res.source.author) })}</span>` : ''}
            <ul>${apps.slice(0, 8).map((a) => `<li>${a.icon ? `<img src="${esc(a.icon)}" alt="" loading="lazy">` : `<span class="ico mono">${esc(String(a.name).slice(0, 2))}</span>`}<span>${esc(a.name)}${a.version ? ` <span class="muted">${esc(a.version)}${a.size ? ' · ' + esc(a.size) : ''}</span>` : ''}</span></li>`).join('')}</ul>
            ${apps.length > 8 ? `<p class="muted">${tn(apps.length - 8, 'And 1 more.', 'And {n} more.')}</p>` : ''}
            ${(res.errors || []).length ? `<p class="muted">${tn(res.errors.length, '1 repo in it couldn\'t be read', '{n} repos in it couldn\'t be read')}: ${res.errors.slice(0, 3).map((x) => esc(x.repo) + ' (' + esc(x.error) + ')').join('; ')}</p>` : ''}`;
          if (!apps.length) { sst.textContent = t('Nothing in it can be installed yet.'); sst.className = 'status bad'; return; }
          checked = isRepo ? { repo: v } : { list: sv.value.trim() };
          sbtn.textContent = t('Send to your PS5');
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
      if (d.length !== 8) { s.textContent = t('A code is 8 numbers, like 12-345-678.'); s.className = 'status bad'; return; }
      const b = rf.querySelector('button');
      b.disabled = true; s.textContent = t('Checking…'); s.className = 'status';
      api('redeem', { code: d }).then((res) => { b.disabled = false; s.textContent = res.ok ? res.message : res.error; s.className = res.ok ? 'status ok' : 'status bad'; if (res.ok) rc.value = ''; });
    });
    box.querySelector('[data-forget]').addEventListener('click', () => {
      if (!confirm(byThisDevice('Unlink this phone from your PS5? You can link it again any time.', 'Unlink this computer from your PS5? You can link it again any time.', 'Unlink this {device} from your PS5? You can link it again any time.'))) return;
      api('forget').then(() => { me = { ok: true, linked: false }; markLinked(); drawMy(); });
    });
  }

  // Installing an app on the linked PS5: from a game's page, My PS5, or an Update button.
  function sendInstall(id, status, btn) {
    if (btn) btn.disabled = true;
    if (status) { status.hidden = false; status.textContent = t('Sending…'); status.className = status.className.replace(/\b(ok|bad)\b/g, '').trim(); }
    api('send', { kind: 'install', data: { app: id } }).then((res) => {
      if (btn) btn.disabled = false;
      if (!status) return;
      status.textContent = res.ok ? res.message : res.error || t('That didn\'t go through.');
      status.classList.add(res.ok ? 'ok' : 'bad');
      if (res.linked === false) refreshMe();
    });
  }
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-send-app]');
    if (!b || b.closest('form')) return;
    const holder = b.closest('.panel, .ps5-app, .q-actions');
    let st = holder && holder.querySelector('.send-status');
    if (!st && holder && holder.classList.contains('ps5-app')) {
      st = document.createElement('span'); st.className = 'note send-status'; holder.querySelector('.ps5-app-words').appendChild(st);
    }
    sendInstall(b.dataset.sendApp, st, b);
  });

  // A game's page, its install button: on the linked PS5, or the way to get the store.
  function installHTML(a) {
    if (!v2on()) return `<a class="btn primary" href="/get" data-get>${t('Install with RecompStore')}</a>`;
    if (me && me.linked) {
      const on = onPs5(a.id);
      const label = on ? (on.update ? tf('Update to {version} on your PS5', { version: esc(on.update) }) : t('Install again on your PS5')) : t('Install on your PS5');
      return `${on ? `<p class="on-ps5"><span class="dot"></span>${t('On your PS5')}${on.version ? ' · ' + esc(on.version) : ''}</p>` : ''}
        <button class="btn primary" type="button" data-send-app="${esc(a.id)}">${label}</button>
        <p class="note send-status" role="status" hidden></p>
        <a class="small-link" href="/get">${t('Don\'t have RecompStore yet?')}</a>`;
    }
    return `<a class="btn primary" href="/get" data-get>${t('Install with RecompStore')}</a>
      <a class="small-link" href="/link">${t('Have RecompStore? Link your PS5 to install from here.')}</a>`;
  }

  // ---- (2.6) The page around the content: header, menu, search, the background's colour --------
  const topBar = document.getElementById('top');
  const drawer = document.getElementById('drawer');
  const searchBox = document.getElementById('search');
  const SVG = {
    chev: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>',
    close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>',
    check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>',
    arrow: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    discord: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" stroke="none" d="M19.3 5.4A16.6 16.6 0 0 0 15.2 4l-.5 1a15.4 15.4 0 0 0-5.4 0l-.5-1a16.5 16.5 0 0 0-4.1 1.4C2.1 9.3 1.4 13.1 1.8 16.9a16.7 16.7 0 0 0 5 2.6l1.1-1.7a10.8 10.8 0 0 1-1.7-.8l.4-.3a11.9 11.9 0 0 0 10.8 0l.4.3c-.5.3-1.1.6-1.7.8l1.1 1.7a16.6 16.6 0 0 0 5-2.6c.5-4.4-.8-8.2-2.9-11.5zM8.7 14.6c-1 0-1.8-.9-1.8-2s.8-2 1.8-2 1.8.9 1.8 2-.8 2-1.8 2zm6.6 0c-1 0-1.8-.9-1.8-2s.8-2 1.8-2 1.8.9 1.8 2-.8 2-1.8 2z"/></svg>',
    github: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" stroke="none" d="M12 2.2a10 10 0 0 0-3.2 19.5c.5.1.7-.2.7-.5v-1.7c-2.8.6-3.4-1.3-3.4-1.3-.5-1.2-1.1-1.5-1.1-1.5-.9-.6.1-.6.1-.6 1 .1 1.5 1 1.5 1 .9 1.6 2.4 1.1 3 .9.1-.7.4-1.1.6-1.4-2.2-.3-4.6-1.1-4.6-5 0-1.1.4-2 1-2.7-.1-.3-.4-1.3.1-2.7 0 0 .8-.3 2.7 1a9.4 9.4 0 0 1 5 0c1.9-1.3 2.7-1 2.7-1 .5 1.4.2 2.4.1 2.7.6.7 1 1.6 1 2.7 0 3.9-2.4 4.7-4.6 5 .4.3.7.9.7 1.9v2.8c0 .3.2.6.7.5A10 10 0 0 0 12 2.2z"/></svg>',
    kofi: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h12.5v6.5a4.5 4.5 0 0 1-4.5 4.5H8.5A4.5 4.5 0 0 1 4 13.5z"/><path d="M16.5 8.5h1.3a2.7 2.7 0 0 1 0 5.4h-1.3"/><path d="M8.6 10.4c.6-.7 1.6-.7 2.1 0 .5-.7 1.5-.7 2.1 0 .6.8.2 1.7-.5 2.3l-1.6 1.4-1.6-1.4c-.7-.6-1.1-1.5-.5-2.3z" fill="currentColor"/></svg>',
  };

  // The header turns to glass once the page moves under it.
  const onScroll = () => topBar.classList.toggle('scrolled', window.scrollY > 8);
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();
  // The menu's links go into the side menu when they don't fit beside the buttons (it depends on
  // the language and the window), and then the submit button keeps only its icon if it must.
  function fitHeader() {
    const inner = topBar.querySelector('.top-in');
    const fits = () => {
      const cs = getComputedStyle(inner);
      const kids = [...inner.children].filter((el) => getComputedStyle(el).display !== 'none');
      const need = kids.reduce((sum, el) => sum + el.getBoundingClientRect().width, 0) + (parseFloat(cs.columnGap) || 0) * (kids.length - 1);
      return need <= inner.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) + 1;
    };
    topBar.classList.remove('snug', 'lean', 'compact', 'tight');
    if (fits()) return;
    topBar.classList.add('snug'); // the menu's links a little closer together first
    if (fits()) return;
    topBar.classList.add('lean'); // (2.8) then Discord tucks away and Submit shows just its +
    if (fits()) return;
    topBar.classList.remove('lean');
    topBar.classList.replace('snug', 'compact');
    if (!fits()) topBar.classList.add('tight');
  }
  let fitQueued = false;
  window.addEventListener('resize', () => { if (fitQueued) return; fitQueued = true; requestAnimationFrame(() => { fitQueued = false; fitHeader(); }); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(fitHeader);
  fitHeader();

  // Phones and narrow windows: the menu slides in from the side.
  const menuBtn = document.getElementById('menu-open');
  function fillDrawer() {
    const nav = document.getElementById('drawer-nav');
    nav.innerHTML = [...document.querySelectorAll('.main-nav a')].filter((a) => !a.hidden && a.hasAttribute('data-pc') === (document.body.dataset.platform === 'pc'))
      .map((a) => `<a href="${a.getAttribute('href')}"${a.hasAttribute('aria-current') ? ' aria-current="page"' : ''}>${esc(a.textContent)}</a>`).join('');
  }
  function openDrawer() {
    fillDrawer();
    drawer.hidden = false;
    requestAnimationFrame(() => drawer.classList.add('open'));
    menuBtn.setAttribute('aria-expanded', 'true');
    document.body.classList.add('locked');
    const first = drawer.querySelector('a, button');
    if (first) first.focus();
  }
  function closeDrawer() {
    if (drawer.hidden) return;
    drawer.classList.remove('open');
    menuBtn.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('locked');
    setTimeout(() => { if (!drawer.classList.contains('open')) drawer.hidden = true; }, reduced ? 0 : 320);
  }
  menuBtn.addEventListener('click', () => (drawer.hidden ? openDrawer() : closeDrawer()));
  drawer.addEventListener('click', (e) => { if (e.target === drawer || e.target.closest('[data-drawer-close], a')) closeDrawer(); });

  // Search: everything in the store, as you type.
  const searchQ = document.getElementById('search-q');
  const searchOut = document.getElementById('search-results');
  const fold = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  const catName = (key) => { const c = CATS.find((x) => x.key === (key || 'games')); return c ? t(c.name) : ''; };
  function searchItems(q) {
    if (document.body.dataset.platform === 'pc') {
      const words = fold(q).split(/\s+/).filter(Boolean);
      return ((pcCat && pcCat.apps) || []).map((a) => {
        const hay = fold([a.name, a.game, a.author, originName(a.origin)].join(' '));
        if (!words.every((w) => hay.includes(w))) return null;
        const name = fold(a.name + ' ' + (a.game || ''));
        return [a, false, !words.length ? (-(a.downloads || 0)) : name.startsWith(words[0]) ? 0 : name.includes(words[0]) ? 1 : 2, true];
      }).filter(Boolean).sort((x, y) => x[2] - y[2] || String(x[0].name).localeCompare(String(y[0].name))).slice(0, q ? 24 : 8);
    }
    const all = cat.apps.map((a) => [a, false]).concat((cat.soon || []).map((a) => [a, true]));
    if (!q) {
      const top = charts && Array.isArray(charts.top) ? charts.top.map((x) => byId(typeof x === 'string' ? x : x && x.id)).filter(Boolean) : [];
      return (top.length ? top : cat.apps).slice(0, 8).map((a) => [a, false]);
    }
    const words = fold(q).split(/\s+/).filter(Boolean);
    return all.map(([a, soon]) => {
      const hay = fold([a.name, a.author, a.id, (a.tags || []).join(' '), catName(a.category)].join(' '));
      if (!words.every((w) => hay.includes(w))) return null;
      const name = fold(a.name);
      return [a, soon, name.startsWith(words[0]) ? 0 : name.includes(words[0]) ? 1 : 2];
    }).filter(Boolean).sort((x, y) => x[2] - y[2] || String(x[0].name).localeCompare(String(y[0].name))).slice(0, 24);
  }
  function drawSearch() {
    const q = searchQ.value.trim();
    const items = searchItems(q);
    const popular = !q && (document.body.dataset.platform === 'pc' ? items.length : charts && Array.isArray(charts.top) && charts.top.length);
    searchOut.innerHTML = (popular ? `<p class="search-label">${t('Popular')}</p>` : '') + (items.length
      ? items.map(([a, soon, , pc]) => pc ? `
        <a class="sr" role="option" href="/pc/game/${encodeURIComponent(a.id)}">
          <span class="sr-ico pc-sr">${a.cover ? `<img src="${esc(a.cover)}" alt="" loading="lazy">` : iconHTML(a, 56)}</span>
          <span class="sr-words"><strong dir="auto">${esc(a.name)}</strong><span dir="auto">${esc([a.game && a.game !== a.name ? a.game : '', originName(a.origin)].filter(Boolean).join(' · '))}</span></span>
        </a>` : `
        <a class="sr" role="option" href="/${soon ? 'soon' : 'game'}/${encodeURIComponent(a.id)}" data-id="${esc(a.id)}"${soon ? ' data-soon' : ''}>
          <span class="sr-ico">${iconHTML(a, 56)}</span>
          <span class="sr-words"><strong dir="auto">${esc(a.name)}</strong><span dir="auto">${esc([soon ? t('Coming soon') : catName(a.category), devOf(a) ? devOf(a).name : a.kind === 'original' ? 'RecompStore' : a.author].filter(Boolean).join(' · '))}</span></span>
        </a>`).join('')
      : `<p class="search-none">${tf('Nothing matches “{q}”.', { q: esc(q) })}</p>`);
  }
  function openSearch() {
    if (!cat) return;
    if (document.body.dataset.platform === 'pc' && !pcCat) pcLoad().then(() => { if (!searchBox.hidden) drawSearch(); });
    closeDrawer();
    searchBox.hidden = false;
    requestAnimationFrame(() => searchBox.classList.add('open'));
    document.body.classList.add('locked');
    searchQ.value = '';
    drawSearch();
    searchQ.focus();
  }
  function closeSearch() {
    if (searchBox.hidden) return;
    searchBox.classList.remove('open');
    document.body.classList.remove('locked');
    setTimeout(() => { if (!searchBox.classList.contains('open')) searchBox.hidden = true; }, reduced ? 0 : 200);
  }
  document.getElementById('search-open').addEventListener('click', openSearch);
  searchQ.addEventListener('input', drawSearch);
  searchBox.addEventListener('click', (e) => { if (e.target === searchBox || e.target.closest('[data-search-close]')) closeSearch(); });
  searchBox.addEventListener('keydown', (e) => {
    const rows = [...searchOut.querySelectorAll('.sr')];
    const at = rows.indexOf(document.activeElement);
    if (e.key === 'ArrowDown') { e.preventDefault(); (rows[at + 1] || rows[0] || searchQ).focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); (at > 0 ? rows[at - 1] : searchQ).focus(); }
    else if (e.key === 'Enter' && document.activeElement === searchQ && rows[0]) { e.preventDefault(); rows[0].click(); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeSearch(); closeDrawer(); return; }
    const typing = /^(input|textarea|select)$/i.test((document.activeElement || {}).tagName || '') || (document.activeElement && document.activeElement.isContentEditable);
    if (!typing && !document.querySelector('dialog[open]') && (e.key === '/' || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k'))) { e.preventDefault(); openSearch(); }
  });

  // The background's colour: the featured game's, then whichever part of the page is in front
  // of you as you scroll, from its first app's icon.
  const accents = new Map();
  function accentOf(a) {
    const src = a && a.icon;
    if (!src) return Promise.resolve(null);
    if (accents.has(src)) return accents.get(src);
    const p = new Promise((ok) => {
      const img = new Image();
      img.crossOrigin = 'anonymous';
      img.decoding = 'async';
      img.onload = () => {
        try {
          const c = document.createElement('canvas');
          c.width = c.height = 28;
          const g = c.getContext('2d', { willReadFrequently: true });
          g.drawImage(img, 0, 0, 28, 28);
          const d = g.getImageData(0, 0, 28, 28).data;
          // The strongest hue: pixels sorted into twelve hues, weighed by how colourful they are.
          const bins = Array.from({ length: 12 }, () => ({ w: 0, r: 0, g: 0, b: 0 }));
          for (let i = 0; i < d.length; i += 4) {
            if (d[i + 3] < 160) continue;
            const r = d[i] / 255, gg = d[i + 1] / 255, b = d[i + 2] / 255;
            const mx = Math.max(r, gg, b), mn = Math.min(r, gg, b), s = mx ? (mx - mn) / mx : 0;
            if (s < 0.25 || mx < 0.2) continue;
            let h = mx === mn ? 0 : mx === r ? (gg - b) / (mx - mn) : mx === gg ? 2 + (b - r) / (mx - mn) : 4 + (r - gg) / (mx - mn);
            h = ((h * 60) + 360) % 360;
            const bin = bins[Math.floor(h / 30) % 12], w = s * s * mx;
            bin.w += w; bin.r += d[i] * w; bin.g += d[i + 1] * w; bin.b += d[i + 2] * w;
          }
          const best = bins.reduce((x, y) => (y.w > x.w ? y : x));
          ok(best.w > 2 ? [best.r / best.w, best.g / best.w, best.b / best.w].map(Math.round) : null);
        } catch (e) { ok(null); }
      };
      img.onerror = () => ok(null);
      img.src = src;
    });
    accents.set(src, p);
    return p;
  }
  const BRAND = [52, 242, 232]; /* (2.7) the brand's top colour */
  let accentWant = '';
  function setAccent(a) {
    const key = (a && a.id) || '';
    accentWant = key;
    (a ? accentOf(a) : Promise.resolve(null)).then((rgb) => {
      if (accentWant !== key) return;
      const c = rgb || BRAND;
      const a1 = `rgb(${c[0]} ${c[1]} ${c[2]})`;
      // A second colour beside it: the same hue turned a little, for depth.
      // (2.7) The store's own colours: the brand's blue beside its cyan (no more purple).
      const a2 = rgb ? `rgb(${Math.round(c[2] * 0.6 + 40)} ${Math.round(c[0] * 0.5 + 40)} ${Math.round(c[1] * 0.7 + 50)})` : 'rgb(55 123 182)';
      if (a1 === accNow) return;
      accNow = a1;
      const root = document.documentElement.style;
      root.setProperty('--acc', a1);
      root.setProperty('--acc2', a2);
      if (glows.length < 2) return;
      const next = glows[1 - glowOn];
      next.style.setProperty('--acc', a1);
      next.style.setProperty('--acc2', a2);
      next.classList.add('on');
      glows[glowOn].classList.remove('on');
      glowOn = 1 - glowOn;
    });
  }
  const glows = [...document.querySelectorAll('.backdrop .glow')];
  let glowOn = 0, accNow = '';
  // Pointing at a game in a list: its picture is fetched at once; after a short pause (not while the
  // pointer just crosses a row) the page takes its art and colour.
  let hoverTimer = 0, hoverEl = null;
  view.addEventListener('pointerover', (e) => {
    const el = e.target.closest('a.tile, a.wide');
    if (!el || el === hoverEl) return;
    hoverEl = el;
    const a = el.hasAttribute('data-soon') ? soonById(el.dataset.id) : byId(el.dataset.id);
    if (!a) return;
    warm(a);
    clearTimeout(hoverTimer);
    if (e.pointerType === 'touch' || !view.querySelector('.home-intro, .cat-page')) return;
    hoverTimer = setTimeout(() => { if (art(a)) setBackdrop(a); setAccent(a); }, 140);
  });
  view.addEventListener('pointerout', (e) => {
    const el = e.target.closest('a.tile, a.wide');
    if (el && !el.contains(e.relatedTarget)) { hoverEl = null; clearTimeout(hoverTimer); }
  });
  let sectionWatch = null;
  function watchSections() {
    if (sectionWatch) sectionWatch.disconnect();
    if (!('IntersectionObserver' in window)) return;
    sectionWatch = new IntersectionObserver((entries) => {
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        const el = en.target;
        const a = el.dataset.accent ? (byId(el.dataset.accent) || soonById(el.dataset.accent)) : null;
        if (el.classList.contains('slider') && !el.classList.contains('still')) { const on = el.querySelector('.slide.on'); setFromSlide(on); return; }
        if (a) { setAccent(a); if (art(a)) setBackdrop(a); }
      });
    }, { rootMargin: '-38% 0px -52% 0px' });
    view.querySelectorAll('[data-accent], .slider').forEach((el) => sectionWatch.observe(el));
  }
  let setFromSlide = () => {};

  // Rows that scroll sideways: no scrollbar; arrows at their sides on a computer, a swipe on a phone.
  function enhanceRows(root) {
    root.querySelectorAll('.rowscroll').forEach((row) => {
      if (row.parentElement.classList.contains('row')) return;
      const wrap = document.createElement('div');
      wrap.className = 'row';
      row.parentNode.insertBefore(wrap, row);
      wrap.appendChild(row);
      const mk = (dir) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'row-btn ' + dir;
        b.setAttribute('aria-label', dir === 'prev' ? t('Previous') : t('Next'));
        b.innerHTML = SVG.chev;
        wrap.appendChild(b);
        return b;
      };
      const prev = mk('prev'), next = mk('next');
      const sign = () => (getComputedStyle(row).direction === 'rtl' ? -1 : 1);
      const update = () => {
        const max = row.scrollWidth - row.clientWidth;
        const x = Math.abs(row.scrollLeft);
        wrap.classList.toggle('can-prev', x > 4);
        wrap.classList.toggle('can-next', x < max - 4);
        prev.disabled = x <= 4;
        next.disabled = x >= max - 4;
      };
      const step = (k) => row.scrollBy({ left: k * sign() * Math.max(200, row.clientWidth * 0.82), behavior: reduced ? 'auto' : 'smooth' });
      prev.addEventListener('click', () => step(-1));
      next.addEventListener('click', () => step(1));
      row.addEventListener('scroll', update, { passive: true });
      if ('ResizeObserver' in window) new ResizeObserver(update).observe(row);
      update();
    });
  }

  // (2.7.3) A tile goes straight to the app's full page (no quick look any more); from search,
  // the search closes on the way.
  document.addEventListener('click', (e) => { if (e.target.closest('a.sr')) closeSearch(); });

  // The footer's links: the store, getting started, for developers, and about.
  function footerHTML() {
    const s = cat.site || {};
    const cats = v2on() ? CATS.filter((c) => inCat(c.key).length) : [];
    // (2.9) RecompLauncher's own: the PC side's pages, and RecompStore for PS5.
    const cols = LAUNCHER ? [
      ['RecompLauncher', [['/pc', t('Discover')], ['/pc/all', t('All recomps')], ['/pc/top', t('Top charts')], ['/pc/new', t('New')], ['/pc/soon', t('Coming soon')], ['/pc/consoles', t('Consoles')], ['/pc/settings', t('Settings')]]],
      [t('Get started'), [['/pc/how', t('How it works')], ['/pc/launcher', t('Get RecompLauncher')]]],
      [t('Developers'), [['/developers', t('Developer accounts')], ['/developers?signin=1', t('Developer sign in')], ['/submit?platform=pc', t('Submit a game')], ['/pc/press', t('Press kit')]]],
      ['RecompStore', [['/', t('PS5 homebrew store')], ['/about', t('About')], ['/contact', t('Contact')], ['/dmca', 'Copyright & DMCA']]],
    ] : [
      [t('Store'), [['/', t('Discover')], ...cats.map((c) => [c.route, t(c.name)]), ...(cat.soon && cat.soon.length ? [['/soon', t('Coming soon')]] : []), ['/pc', 'RecompLauncher']]],
      [t('Get started'), [['/get', t('Get RecompStore')], ['/how', t('How it works')], ['/beta', t('Betas')], ...(v2on() ? [['/link', t('Link your PS5')]] : [])]],
      [t('Developers'), [['/developers', t('Developer accounts')], ['/developers?signin=1', t('Developer sign in')], ['/submit', t('Submit an app')], ['/press', t('Press kit')], ...(v2on() ? [['/ids', t('Title IDs')]] : []), ['/report', t('Report a bug')]]],
      ['RecompStore', [['/about', t('About')], ['/contact', t('Contact')], ['/dmca', 'Copyright & DMCA']]],
    ];
    document.getElementById('foot-cols').innerHTML = cols.map(([h, links]) => `
      <div class="foot-col"><h3>${esc(h)}</h3><ul>${links.map(([u, n]) => `<li><a href="${esc(u)}">${esc(n)}</a></li>`).join('')}</ul></div>`).join('');
    // (2.7.1) The Discord server, one tap away: in the header and the menu.
    const td = document.getElementById('top-discord'), dd = document.getElementById('drawer-discord');
    if (td && s.discord) { td.href = s.discord; td.innerHTML = SVG.discord; td.title = t('Join the Discord'); td.hidden = false; }
    if (dd && s.discord) { dd.href = s.discord; if (!dd.querySelector('svg')) dd.insertAdjacentHTML('afterbegin', SVG.discord); dd.hidden = false; }
    document.getElementById('foot-social').innerHTML = [
      s.discord ? `<a href="${esc(s.discord)}" target="_blank" rel="noopener" aria-label="Discord">${SVG.discord}</a>` : '',
      '<a href="https://github.com/elripalda/RecompStore" target="_blank" rel="noopener" aria-label="GitHub">' + SVG.github + '</a>',
      s.kofi ? `<a href="${esc(s.kofi)}" target="_blank" rel="noopener" aria-label="Ko-fi">${SVG.kofi}</a>` : '',
    ].join('');
  }
  // Which parts of the store get a place in the menu: the ones with something in them.
  function menuCats() {
    document.querySelectorAll('.main-nav [data-cat]').forEach((a) => {
      const key = a.getAttribute('href').replace(/^#?\//, '');
      a.hidden = !(v2on() && inCat(key).length);
    });
    fitHeader();
  }

  // ---- (2.6) How it works: the steps, what you need, and the questions people ask ---------------
  const NEEDS = () => [
    [t('A jailbroken PS5 with a payload loader'), t('Any loader on port 9021, such as etaHEN or elfldr.')],
    ['kstuff', t('So the store can write to your PS5\'s storage.')],
    [t('ShadowMountPlus 1.7 Beta 5 or newer'), t('It puts the store and your games on the home screen. The store can get it for you.')],
    [t('Your own games'), t('A disc image or the game\'s own file, on a USB drive or copied to the PS5 over FTP.')],
  ];
  const needsHTML = () => `<ul class="needs-list">${NEEDS().map(([a, b]) => `<li><span class="tick">${SVG.check}</span><div><strong>${esc(a)}</strong><span>${esc(b)}</span></div></li>`).join('')}</ul>`;
  function howPage() {
    const faq = [
      [t('Is it free?'), t('Yes. RecompStore and everything in it are free. Some developers take donations on their own pages.')],
      [t('Do I need an account?'), t('No. The store works without one. Linking a phone or PC is optional and only needs a code from your PS5.')],
      [t('Where do the apps come from?'), t('Each one comes from its developer\'s own GitHub release, checked against the store\'s list before it\'s installed.')],
      [t('What does Bring your files mean?'), t('The port needs files from a game you own. On the game\'s page in the store, choose them under Your game files and the store sets everything up.')],
      [t('Do updates keep my saves?'), t('Yes. An update replaces only the app. Your game files, saves, DLC and mods stay where they are.')],
    ];
    view.innerHTML = `
      <article class="page how-page">
        <header class="page-head center">
          <span class="kicker">${t('How it works')}</span>
          <h1>${t('From your own copy to your PS5, in five steps')}</h1>
          <p class="lead">${t('Install RecompStore once. After that, every game, emulator, app and tool installs and updates from your PS5.')}</p>
          <div class="cta-row"><a class="btn primary" href="/get">${t('Get RecompStore')}</a><a class="btn ghost" href="/">${t('Browse the store')}</a></div>
        </header>
        ${howHTML()}
        <section class="split">
          <div class="glass card-pad"><span class="kicker">${t('What you need')}</span>${needsHTML()}</div>
          <div class="glass card-pad faq"><span class="kicker">${t('Questions')}</span>${faq.map(([q, a]) => `<details><summary>${esc(q)}</summary><p>${esc(a)}</p></details>`).join('')}</div>
        </section>
      </article>`;
    setBackdrop(null);
    setAccent(null);
  }

  // ---- (2.6) Get RecompStore: its own page, step by step ----------------------------------------
  function getPage() {
    const s = cat.site || {};
    const wn = cat.whats_new;
    const steps = [
      [t('Jailbreak your PS5 and start your payload loader.'), esc(t('Any loader on port 9021 works, such as etaHEN\'s or elfldr. You\'ll also need kstuff and ShadowMountPlus 1.7 Beta 5 or newer.'))],
      [t('Download the installer payload.'), s.payload ? `<a class="btn primary inline" href="${esc(s.payload)}">${tf('Download {file}', { file: 'RecompStore-Installer.elf' })}</a>` : `<em class="soon">${t('The installer is on its way with the first release.')}</em>`],
      [t('Send it to your PS5.'), `${esc(t('Use any payload sender, or from a computer on the same network:'))}
        <span class="cmd-row"><code class="cmd" id="send-cmd">nc -N &lt;your PS5's IP&gt; 9021 &lt; RecompStore-Installer.elf</code><button class="copy" type="button" data-copy="#send-cmd">${t('Copy')}</button></span>`],
      [t('Open RecompStore from your home screen.'), esc(t('The installer has ShadowMountPlus add it. From then on the store updates itself.'))],
    ];
    const trouble = [
      [t('RecompStore isn\'t on my home screen'), t('Check that ShadowMountPlus is running, then send the installer again. It adds the store\'s tile when it\'s done.')],
      [t('Nothing happens when I send the installer'), t('Make sure your payload loader is running and listening on port 9021, and that your computer or phone is on the same network as the PS5.')],
      [t('The store says it can\'t write to /data'), t('Start etaHEN (or kstuff with your loader) first, then open the store again.')],
    ];
    const manual = N_('Download the RecompStore zip from <a href="https://github.com/elripalda/RecompStore/releases/latest" target="_blank" rel="noopener">its GitHub releases</a>, unzip it, and copy the folder into <code>/data/homebrew/</code> on your PS5 over FTP. Then register it the way you register your other homebrew.');
    view.innerHTML = `
      <article class="page get-page">
        <header class="get-hero glass">
          <img class="get-logo" src="/assets/logo-512.png" alt="" width="120" height="120">
          <div>
            <h1>${t('Get RecompStore')}</h1>
            <p class="lead">${t('Installs once, then keeps itself and your games up to date.')}</p>
            <div class="cta-row">
              ${s.payload ? `<a class="btn primary" href="${esc(s.payload)}">${tf('Download {file}', { file: 'RecompStore-Installer.elf' })}</a>` : ''}
              <a class="btn ghost" href="/how">${t('How it works')}</a>
            </div>
          </div>
        </header>
        <section class="split">
          <div>
            <h2>${t('Install it in four steps')}</h2>
            <ol class="get-steps">${steps.map(([h, body], i) => `<li class="glass"><span class="n">${i + 1}</span><div><strong>${esc(h)}</strong><p>${body}</p></div></li>`).join('')}</ol>
            <details class="glass manual card-pad"><summary>${t('Install by hand instead')}</summary><p>${t(manual)}</p></details>
            ${wn && typeof wn.body === 'string' && wn.body.trim() ? `<details class="glass manual card-pad whats-new" dir="auto"><summary>${esc(wn.title || tf("What's new in {version}", { version: wn.version || '' }))}</summary><div class="wn-body">${notesBlocks(wn.body)}</div></details>` : ''}
          </div>
          <aside>
            <div class="glass card-pad"><span class="kicker">${t('What you need')}</span>${needsHTML()}</div>
            <div class="glass card-pad faq"><span class="kicker">${t('If something goes wrong')}</span>${trouble.map(([q, a]) => `<details><summary>${esc(q)}</summary><p>${esc(a)}</p></details>`).join('')}
              ${s.discord ? `<p class="faq-more"><a href="${esc(s.discord)}" target="_blank" rel="noopener">${t('Ask on Discord')} ↗</a></p>` : ''}</div>
          </aside>
        </section>
      </article>`;
    setBackdrop(null);
    setAccent(null);
  }

  // ---- (2.7.1) Press kit: badges, logos, colors and words for developers ---------------------------
  function pressPage() {
    const K = 'https://recompstore.com';
    const A = '/assets/press/';
    const apps = cat.apps.slice().sort((x, y) => String(x.name).localeCompare(String(y.name)));
    const want = new URLSearchParams(location.search).get('app') || '';
    let app = apps.find((a) => a.id === want) || null;
    let theme = 'dark';
    const logos = [
      ['badge-dark', t('Badge'), t('For light or dark backgrounds'), 'dark'], ['badge-light', t('Badge'), t('For dark backgrounds'), 'light'],
      ['verified-dark', t('Verified developer badge'), t('For approved developers\' READMEs'), 'dark'], ['verified-light', t('Verified developer badge'), t('For dark backgrounds'), 'light'],
      ['logo-horizontal-white', t('Logo'), t('For dark backgrounds'), 'dark'], ['logo-horizontal-dark', t('Logo'), t('For light backgrounds'), 'light'],
      ['wordmark-white', t('Wordmark'), t('For dark backgrounds'), 'dark'], ['wordmark-dark', t('Wordmark'), t('For light backgrounds'), 'light'],
      ['mark', t('Mark'), t('For light or dark backgrounds'), 'dark'],
    ];
    const colors = [['#34F2E8', t('Gradient top')], ['#377BB6', t('Gradient bottom')], ['#020509', t('Ink')], ['#F4F8FC', t('Frost')]];
    const rules = [t('Write the name as one word: RecompStore.'), t('Use the badge and logos as they are. Don\'t recolor, stretch or redraw them.'),
      t('Give them room, and use the light versions on light backgrounds.'), t('Link the badge to your app\'s page on RecompStore, so players land in the right place.')];
    view.innerHTML = `
      <article class="page press-page">
        <header class="get-hero glass">
          <img class="get-logo" src="/assets/logo-512.png" alt="" width="120" height="120">
          <div>
            <h1>${t('Press kit')}</h1>
            <p class="lead">${t('Badges, logos and words for telling people where to get your app.')}</p>
            <div class="cta-row"><a class="btn primary" href="${A}recompstore-press-kit.zip" download>${t('Download everything (.zip)')}</a><a class="btn ghost" href="/submit">${t('Submit an app')}</a></div>
          </div>
        </header>
        <section class="glass card-pad press-badge" id="badge">
          <h2>${t('Get it on RecompStore badge')}</h2>
          <div class="press-pick">
            <label>${t('Your app')}<select data-app><option value="">${t('The store itself')}</option>${apps.map((a) => `<option value="${esc(a.id)}"${app && app.id === a.id ? ' selected' : ''}>${esc(a.name)}</option>`).join('')}</select></label>
            <div class="chips filter" role="group" aria-label="${t('Badge')}"><button type="button" class="tag on" data-theme="dark">${t('Dark')}</button><button type="button" class="tag" data-theme="light">${t('Light')}</button></div>
          </div>
          <div class="press-preview" data-preview></div>
          <div data-snips></div>
        </section>
        <section class="press-logos">
          <h2>${t('Logos')}</h2>
          <div class="press-grid">${logos.map(([f, name, note, bg]) => `
            <figure class="glass press-logo ${bg}"><div class="press-art"><img src="${A}${f}.svg" alt="${esc(name)}" loading="lazy"></div>
              <figcaption><strong>${esc(name)}</strong><span>${esc(note)}</span><span class="press-files"><a href="${A}${f}.svg" download>SVG</a><a href="${A}${f}.png" download>PNG</a></span></figcaption></figure>`).join('')}
            <figure class="glass press-logo dark"><div class="press-art"><img src="${A}icon-1024.png" alt="${esc(t('App icon'))}" loading="lazy" class="press-icon"></div>
              <figcaption><strong>${t('App icon')}</strong><span>1024 × 1024</span><span class="press-files"><a href="${A}icon-1024.png" download>PNG</a></span></figcaption></figure>
          </div>
        </section>
        <section class="split">
          <div>
            <div class="glass card-pad"><h2>${t('Colors')}</h2><div class="press-colors">${colors.map(([hex, name]) => `
              <button type="button" class="press-color" data-hex="${hex}"><span class="sw" style="background:${hex}"></span><strong>${esc(name)}</strong><code>${hex}</code></button>`).join('')}</div>
              <p class="note">${t('The logo\'s gradient runs from top to bottom, #34F2E8 to #377BB6.')}</p></div>
            <div class="glass card-pad press-words"><h2>${t('Words you can use')}</h2><div data-words></div></div>
          </div>
          <aside>
            <div class="glass card-pad"><span class="kicker">${t('Using the name and logo')}</span><ul class="press-rules">${rules.map((r) => `<li>${esc(r)}</li>`).join('')}</ul></div>
            <div class="glass card-pad"><span class="kicker">${t('Get your app on RecompStore')}</span><p>${t('Send it in with the form. Check that your title ID is free before you release.')}</p>
              <div class="cta-row"><a class="btn primary" href="/submit">${t('Submit an app')}</a><a class="btn ghost" href="/ids">${t('Title IDs')}</a></div></div>
            <div class="glass card-pad" id="news"><span class="kicker">${t('News for developers')}</span><p>${t('Get an email when there\'s something new for developers, like this press kit.')}</p>
              <form class="form press-news" novalidate>
                <label>${t('Email')}<input name="email" type="email" maxlength="190" autocomplete="email" required placeholder="you@example.com"></label>
                <label class="hp" aria-hidden="true">Leave this empty<input name="website" tabindex="-1" autocomplete="off"></label>
                <div class="form-row"><button class="btn primary" type="submit">${t('Sign up')}</button><span class="status" role="status"></span></div>
              </form></div>
          </aside>
        </section>
      </article>`;
    setBackdrop(null);
    setAccent(null);
    const block = (id, label, text) => `<div class="press-snip"><span class="kicker">${esc(label)}</span><span class="cmd-row"><code class="cmd" id="${id}">${esc(text)}</code><button class="copy" type="button" data-copy="#${id}">${t('Copy')}</button></span></div>`;
    const draw = () => {
      const url = app ? `${K}/game/${encodeURIComponent(app.id)}` : `${K}/`;
      const svg = `${K}${A}badge-${theme}.svg`, png = `${K}${A}badge-${theme}.png`;
      view.querySelector('[data-preview]').className = 'press-preview ' + (theme === 'light' ? 'on-dark' : 'on-light');
      view.querySelector('[data-preview]').innerHTML = `<a href="${esc(url)}"><img src="${A}badge-${theme}.svg" alt="Get it on RecompStore" height="56"></a>`;
      view.querySelector('[data-snips]').innerHTML = block('snip-md', t('For your README (Markdown)'), `[![Get it on RecompStore](${svg})](${url})`)
        + block('snip-html', 'HTML', `<a href="${url}"><img src="${svg}" alt="Get it on RecompStore" height="56"></a>`)
        + block('snip-bb', t('For forums (BBCode)'), `[url=${url}][img]${png}[/img][/url]`);
      view.querySelector('[data-words]').innerHTML = block('word-short', t('About RecompStore'), t('RecompStore is a free homebrew app store for jailbroken PS5. It installs and updates games, emulators, apps and tools right from the console.'))
        + (app ? block('word-app', t('About your app'), `${tf('{name} is on RecompStore, the free homebrew app store for jailbroken PS5. Install it and keep it up to date right from your console:', { name: app.name })} ${url}`) : '');
    };
    draw();
    view.querySelector('[data-app]').addEventListener('change', (e) => {
      app = apps.find((a) => a.id === e.target.value) || null;
      const q = new URLSearchParams(location.search);
      if (app) q.set('app', app.id); else q.delete('app');
      history.replaceState(history.state, '', '/press' + (q.toString() ? '?' + q : ''));
      draw();
    });
    view.querySelectorAll('[data-theme]').forEach((b) => b.addEventListener('click', () => {
      theme = b.dataset.theme;
      view.querySelectorAll('[data-theme]').forEach((x) => x.classList.toggle('on', x === b));
      draw();
    }));
    // From an app's page: straight to its badge.
    if (app) requestAnimationFrame(() => { const el = document.getElementById('badge'); if (el) window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 90, behavior: 'instant' }); });
    // (2.7.1) The developer list: a confirmation email first.
    const news = view.querySelector('.press-news');
    news.addEventListener('submit', (e) => {
      e.preventDefault();
      const st = news.querySelector('.status'), btn = news.querySelector('button');
      const email = news.email.value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { st.textContent = t('Type your email address.'); st.className = 'status bad'; news.email.focus(); return; }
      btn.disabled = true; st.textContent = t('Sending…'); st.className = 'status';
      fetch('/api/subscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, website: news.website.value }) })
        .then((r) => r.json().catch(() => ({ ok: false })))
        .then((res) => {
          if (res.ok) { news.innerHTML = `<p class="status ok">${res.state === 'there' ? t('You\'re already on the list.') : t('Check your email: there\'s a link in it to confirm.')}</p>`; return; }
          st.textContent = res.error || t('That didn\'t send. Try again in a moment.'); st.className = 'status bad'; btn.disabled = false;
        })
        .catch(() => { st.textContent = t('That didn\'t send. Check your connection and try again.'); st.className = 'status bad'; btn.disabled = false; });
    });
    view.querySelectorAll('[data-hex]').forEach((b) => b.addEventListener('click', () => {
      navigator.clipboard && navigator.clipboard.writeText(b.dataset.hex).then(() => { const c = b.querySelector('code'); c.textContent = t('Copied'); setTimeout(() => (c.textContent = b.dataset.hex), 1200); });
    }));
  }

  // ---- (2.7.2) For developers: what a developer account is, and signing in with GitHub ---------
  const ghMark = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" stroke="none" d="M12 1.5a10.5 10.5 0 0 0-3.3 20.5c.5.1.7-.2.7-.5v-1.8c-2.9.6-3.5-1.4-3.5-1.4-.5-1.2-1.2-1.5-1.2-1.5-1-.7.1-.7.1-.7 1 .1 1.6 1.1 1.6 1.1.9 1.6 2.5 1.1 3.1.9.1-.7.4-1.1.7-1.4-2.3-.3-4.8-1.2-4.8-5.2 0-1.1.4-2.1 1.1-2.8-.1-.3-.5-1.4.1-2.8 0 0 .9-.3 2.9 1.1a10 10 0 0 1 5.3 0c2-1.4 2.9-1.1 2.9-1.1.6 1.4.2 2.5.1 2.8.7.7 1.1 1.7 1.1 2.8 0 4-2.4 4.9-4.8 5.2.4.3.7 1 .7 2v2.9c0 .3.2.6.7.5A10.5 10.5 0 0 0 12 1.5z"/></svg>';
  function developersPage() {
    const q = new URLSearchParams(location.search);
    const invite = (q.get('invite') || '').replace(/[^A-Za-z0-9]/g, '');
    const notes = { no: t('You didn\'t finish signing in with GitHub.'), err: t('Signing in with GitHub didn\'t work. Try again.'), again: t('Signing in with GitHub didn\'t work. Try again.'), off: t('Developer accounts aren\'t open yet. Check back soon.') };
    const reset = /^[0-9a-f]{64}$/.test(q.get('reset') || '') ? q.get('reset') : '';
    const note = notes[q.get('gh')] || (q.get('mail') === 'old' ? t('That link has run out. Sign in, or ask for a new one.') : '');
    const jump = q.has('signin') || q.has('signup') || !!reset;
    const wantSignup = q.has('signup');
    if (q.get('gh') || q.has('signin') || q.has('signup') || q.get('mail') || reset) history.replaceState(history.state, '', '/developers' + (invite ? '?invite=' + invite : ''));
    const signIn = '/api/dev.php?a=login' + (invite ? '&invite=' + encodeURIComponent(invite) : '');
    const perks = [
      ['★', t('A verified developer page'), t('Your apps, your links and a Verified developer badge, on a page you look after yourself.')],
      ['↗', t('Your numbers'), t('How many PS5s have each of your apps, new installs this week, GitHub downloads and likes.')],
      ['✉', t('Your players\' logs'), t('The logs players send from RecompStore when something goes wrong with your app, with what they say happened.')],
      ['♥', t('Support from players'), t('Your Ko-fi, Patreon or PayPal as a Support button on every one of your app pages.')],
      ['β', t('Betas for your apps'), t('Test new builds with codes you hand out in your own Discord, straight from your GitHub releases.')],
      ['✎', t('Your pages, kept up to date'), t('Change your app pages, send in new apps and get a title ID of your own, all from one place.')],
    ];
    view.innerHTML = `
      <article class="page devs-page">
        <header class="get-hero glass">
          <div>
            <span class="kicker">${t('For developers')}</span>
            <h1>${t('Your apps, your page, your players')}</h1>
            <p class="lead">${t('A developer account on RecompStore gives you a verified page, your numbers, your players\' logs and betas for your apps. It\'s free, and we approve every account by hand.')}</p>
            <div data-dev-hello></div>
          </div>
          <div class="glass dev-auth" id="signin" data-dev-cta>
            ${note ? `<p class="status bad">${note}</p>` : ''}
            ${reset ? `<form class="form dev-auth-form" data-auth="reset" novalidate><h2>${t('Set a new password')}</h2>
                <label>${t('New password')}<input name="password" type="password" autocomplete="new-password" minlength="10" maxlength="200" required><small>${t('At least 10 characters.')}</small></label>
                <button class="btn primary" type="submit">${t('Save and sign in')}</button><span class="status" role="status"></span></form>`
            : `<h2>${t('Developer sign in')}</h2>
            <a class="btn primary" href="${signIn}">${ghMark}<span>${t('Sign in with GitHub')}</span></a>
            <p class="dev-auth-tip">${t('Best if your apps are on GitHub: we find them for you.')}</p>
            <div class="dev-auth-or"><span>${t('or with email')}</span></div>
            <div class="dev-auth-tabs" role="tablist"><button type="button" class="on" data-auth-tab="signin">${t('Sign in')}</button><button type="button" data-auth-tab="signup">${t('Create an account')}</button></div>
            <form class="form dev-auth-form" data-auth="signin" novalidate>
              <label>${t('Email')}<input name="email" type="email" autocomplete="email" maxlength="190" required></label>
              <label>${t('Password')}<input name="password" type="password" autocomplete="current-password" maxlength="200" required></label>
              <button class="btn" type="submit">${t('Sign in')}</button>
              <button class="linkish" type="button" data-forgot>${t('Forgot your password?')}</button><span class="status" role="status"></span></form>
            <form class="form dev-auth-form" data-auth="signup" hidden novalidate>
              <label>${t('Your developer name')}<input name="name" autocomplete="nickname" maxlength="80" required></label>
              <label>${t('Email')}<input name="email" type="email" autocomplete="email" maxlength="190" required></label>
              <label>${t('Password')}<input name="password" type="password" autocomplete="new-password" minlength="10" maxlength="200" required><small>${t('At least 10 characters. It\'s stored scrambled, so nobody (us included) can read it.')}</small></label>
              <button class="btn" type="submit">${t('Create an account')}</button><span class="status" role="status"></span></form>`}
          </div>
        </header>
        <section class="dev-perks">${perks.map(([i, h, p]) => `<div class="glass card-pad"><span class="perk-ico" aria-hidden="true">${i}</span><h2>${h}</h2><p>${p}</p></div>`).join('')}</section>
        <section class="split">
          <div class="glass card-pad"><h2>${t('How it works')}</h2><ol class="dev-steps">
            <li>${t('Sign in with GitHub (your apps are found from your repositories), or make an account with your email.')}</li>
            <li>${t('Ask for a developer account, or follow the invite we emailed you.')}</li>
            <li>${t('We check it\'s really you and approve it, usually within a day.')}</li>
            <li>${t('Your hub opens: your page, your numbers, your logs and your betas.')}</li></ol></div>
          <aside class="glass card-pad"><span class="kicker">${t('What signing in shares')}</span>
            <ul class="press-rules">
              <li>${t('Only your public GitHub profile: your name, username and picture.')}</li>
              <li>${t('No access to your repositories, code or private data, and no password. GitHub shows you this before you agree.')}</li>
              <li>${t('With an email account: your email, which is never shown, and a password stored scrambled so nobody can read it.')}</li>
              <li>${t('You can sign out any time, and ask us to close your account.')}</li>
            </ul></aside>
        </section>
      </article>`;
    setBackdrop(null);
    setAccent(null);
    const page = view.querySelector('.devs-page');
    if (invite) fetch('/api/dev.php?a=invite&code=' + encodeURIComponent(invite), { cache: 'no-store' }).then((r) => r.json()).catch(() => null).then((j) => {
      if (!page.isConnected || !j) return;
      const box = page.querySelector('[data-dev-hello]');
      if (j.ok && !j.used) box.innerHTML = `<p class="dev-invited">${j.name ? tf('{name}, you\'re invited. Sign in with GitHub, or create an account with your email, to set up your developer account.', { name: esc(j.name) }) : t('You\'re invited. Sign in with GitHub, or create an account with your email, to set up your developer account.')}</p>`;
      else if (j.ok && j.used) box.innerHTML = `<p class="muted">${t('This invite has been used. Sign in with the same account to open your hub.')}</p>`;
      // (2.7.3) Invited: the Create an account form ready with the name and email the invite went to.
      if (j.ok && !j.used) {
        const tab = page.querySelector('[data-auth-tab="signup"]'), f = page.querySelector('[data-auth="signup"]');
        if (tab && f) { tab.click(); if (j.name && !f.name.value) f.name.value = j.name; if (j.email && !f.email.value) f.email.value = j.email; }
      }
    });
    // (2.7.3) From the Submit page: Create an account, with the name and email they just used.
    if (wantSignup) setTimeout(() => {
      const tab = page.querySelector('[data-auth-tab="signup"]'), f = page.querySelector('[data-auth="signup"]');
      if (!tab || !f) return;
      tab.click();
      let pre = null;
      try { pre = JSON.parse(sessionStorage.getItem('rs_signup') || 'null'); sessionStorage.removeItem('rs_signup'); } catch (e) { pre = null; }
      if (pre && pre.name && !f.name.value) f.name.value = String(pre.name).slice(0, 80);
      if (pre && pre.email && !f.email.value) f.email.value = String(pre.email).slice(0, 190);
    }, 0);
    fetch('/api/dev.php?a=me', { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null).then((j) => {
      if (!j || !j.account) return;
      devHubButton(j.account);
      if (!page.isConnected) return;
      page.querySelector('[data-dev-cta]').innerHTML = `<h2>${tf('Welcome back, {name}', { name: esc(j.account.name || j.account.login) })}</h2><a class="btn primary" href="/hub">${t('Open your developer hub')}</a>`;
    });
    // (2.7.3) Email and password, next to GitHub.
    const post = (a, data) => fetch('/api/dev.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ a }, data)) })
      .then((r) => r.json()).catch(() => ({ ok: false, error: t('That didn\'t go through. Check your connection and try again.') }));
    const auth = page.querySelector('.dev-auth');
    auth.querySelectorAll('[data-auth-tab]').forEach((b) => b.addEventListener('click', () => {
      auth.querySelectorAll('[data-auth-tab]').forEach((x) => x.classList.toggle('on', x === b));
      auth.querySelectorAll('[data-auth]').forEach((f) => { f.hidden = f.dataset.auth !== b.dataset.authTab; });
      const first = auth.querySelector(`[data-auth="${b.dataset.authTab}"] input`); if (first) first.focus();
    }));
    const tell = (f, ok, text) => { const st = f.querySelector('.status'); st.textContent = text; st.className = 'status ' + (ok ? 'ok' : 'bad'); };
    auth.querySelectorAll('[data-auth]').forEach((f) => f.addEventListener('submit', (e) => {
      e.preventDefault();
      const kind = f.dataset.auth, btn = f.querySelector('button[type="submit"]');
      const data = kind === 'reset' ? { t: reset, password: f.password.value } : kind === 'signup'
        ? { name: f.name.value.trim(), email: f.email.value.trim(), password: f.password.value, invite } : { email: f.email.value.trim(), password: f.password.value };
      btn.disabled = true;
      post(kind, data).then((res) => {
        btn.disabled = false;
        if (!res.ok) { tell(f, false, res.error || t('That didn\'t go through.')); return; }
        if (kind === 'signup') { f.innerHTML = `<h2>${t('Check your email')}</h2><p>${tf('We sent a link to {email}. Open it to finish setting up your account.', { email: `<strong>${esc(res.sent || data.email)}</strong>` })}</p>`; return; }
        location.href = '/hub';
      });
    }));
    const forgot = auth.querySelector('[data-forgot]');
    if (forgot) forgot.addEventListener('click', () => {
      const f = forgot.closest('form');
      if (!f.email.value.trim()) { tell(f, false, t('Type your email above first.')); f.email.focus(); return; }
      post('forgot', { email: f.email.value.trim() }).then((res) => tell(f, res.ok, res.ok ? t('If there\'s an account for that email, a link to set a new password is on its way.') : (res.error || t('That didn\'t go through.'))));
    });
    if (jump) { auth.scrollIntoView({ block: 'center' }); const first = auth.querySelector('input'); if (first) first.focus({ preventScroll: true }); }
  }

  // ---- (2.7.2) The developer hub ------------------------------------------------------------------
  function hubPage() {
    view.innerHTML = `<article class="page hub-page"><p class="muted">${t('Loading…')}</p></article>`;
    setBackdrop(null);
    setAccent(null);
    const page = view.querySelector('.hub-page');
    const call = (a, data) => fetch('/api/dev.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ a }, data || {})) })
      .then((r) => r.json()).catch(() => ({ ok: false, error: t('That didn\'t go through. Check your connection and try again.') }));
    const say = (el, res, okText) => { el.textContent = res.ok ? (res.message || okText || t('Saved.')) : (res.error || t('That didn\'t go through.')); el.className = 'status ' + (res.ok ? 'ok' : 'bad'); };
    const num = (n) => Number(n || 0).toLocaleString(lang);
    const q = new URLSearchParams(location.search);
    const dcNote = q.get('dc') === 'ok' ? t('Discord is linked.') : q.get('dc') === 'no' ? t('You didn\'t finish linking Discord.')
      : q.get('gh') === 'linked' ? t('GitHub is linked. Your apps are found from your repositories now.') : q.get('gh') === 'taken' ? t('That GitHub account already has its own developer account. Sign in with GitHub instead.') : '';
    const dcBad = q.get('dc') === 'no' || q.get('gh') === 'taken';
    if (q.get('dc') || q.get('gh')) history.replaceState(history.state, '', '/hub');
    let data = null;
    const load = () => fetch('/api/dev.php?a=me', { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null).then((j) => {
      if (!page.isConnected) return;
      if (!j || !j.ok) { page.innerHTML = `<p class="status bad">${t('The developer hub isn\'t available right now. Try again later.')}</p>`; return; }
      if (!j.account) { devHubButton(null); history.replaceState(null, '', '/developers'); developersPage(); return; }
      devHubButton(j.account);
      data = j; draw();
    });
    const head = (acc) => {
      const verified = acc.status === 'approved' && data.profile && data.profile.verified;
      const pic = acc.avatar || (data.profile && data.profile.avatar);
      return `
      <header class="glass hub-head">
        <span class="hub-pic">${pic ? `<img src="${esc(pic)}" alt="" width="72" height="72">` : `<span>${esc(String(acc.name || acc.email || '?').slice(0, 1).toUpperCase())}</span>`}</span>
        <div class="hub-who"><span class="kicker">${t('Developer hub')}</span><h1><bdi>${esc(acc.name || acc.login)}</bdi>${verified ? tick() : ''}</h1>
          <span class="muted">${acc.github ? `github.com/${esc(acc.login)}` : esc(acc.email)}${verified ? ` <span class="verified-pill">${tick()}${t('Verified developer')}</span>` : ''}</span></div>
        <div class="hub-head-btns">${data.profile ? `<a class="btn" href="/dev/${encodeURIComponent(data.profile.slug)}">${t('Your page')} ${SVG.arrow}</a>` : ''}<button class="linkish" type="button" data-out>${t('Sign out')}</button></div>
      </header>
      ${dcNote ? `<p class="status ${dcBad ? 'bad' : 'ok'}">${dcNote}</p>` : ''}
      ${!acc.github && acc.ghReady ? `<section class="glass hub-link-gh">${ghMark}<div><strong>${t('Link your GitHub')}</strong><span>${t('So we can find your apps from your repositories. We only see your public profile.')}</span></div><a class="btn" href="/api/dev.php?a=login&link=1">${t('Link GitHub')}</a></section>` : ''}`;
    };
    // A small line chart of new installs a day (last 30 days).
    const spark = (days) => {
      if (!Array.isArray(days) || !days.length) return '';
      const max = Math.max(1, ...days), w = 300, h = 56, step = w / (days.length - 1 || 1);
      const pts = days.map((v, i) => `${(i * step).toFixed(1)},${(h - 4 - (v / max) * (h - 10)).toFixed(1)}`);
      const total = days.reduce((x, y) => x + y, 0);
      return `<figure class="hub-spark"><svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" role="img" aria-label="${tf('{n} new installs in the last 30 days', { n: num(total) })}">
        <path d="M0,${h} L${pts.join(' L')} L${w},${h} Z" class="area"/><polyline points="${pts.join(' ')}" class="line"/></svg>
        <figcaption><span>${t('New installs, last 30 days')}</span><strong>${num(total)}</strong></figcaption></figure>`;
    };
    const appsList = () => data.apps.length ? `<ul class="hub-found">${data.apps.map((a) => `<li>${a.icon ? `<img src="${esc(a.icon)}" alt="" width="36" height="36">` : ''}<span>${esc(a.name)}${a.beta ? ` <span class="tag">${t('Beta')}</span>` : ''}</span></li>`).join('')}</ul>`
      : `<p class="muted">${t('None of your GitHub repositories are in RecompStore yet. That\'s fine: you can send one in once you\'re approved.')}</p>`;
    const draw = () => {
      const acc = data.account;
      if (acc.status !== 'approved') {
        const pending = acc.status === 'pending';
        page.innerHTML = head(acc) + `
          <section class="glass card-pad hub-wait">
            ${pending ? `<h2>${t('Thanks! You\'re on the list')}</h2><p>${t('We\'ll look at your account soon and email you when your hub is open. Usually within a day.')}</p>`
              : `<h2>${acc.status === 'rejected' ? t('Ask again') : t('Apply for a developer account')}</h2><p>${t('Tell us who you are and what you\'re working on. Everything here is needed, and we read every one.')}</p>
              <form class="form hub-ask" novalidate>
                <div class="two">
                  <label>${t('Name')}<input name="name" maxlength="80" value="${esc(acc.name)}" autocomplete="name" required><small>${t('Your name, or the name you make things under.')}</small></label>
                  <label>${t('Email')}<input name="email" type="email" maxlength="190" value="${esc(acc.email)}" autocomplete="email" required><small>${t('Only for news about your account. Never shown on the site.')}</small></label>
                </div>
                <div class="two">
                  <div class="hub-ask-gh"><span class="hub-ask-lbl">${t('GitHub')}</span>${acc.github
                    ? `<span class="hub-ask-ok">${ghMark}<a href="https://github.com/${esc(acc.login)}" target="_blank" rel="noopener">github.com/${esc(acc.login)}</a><span class="tag">${t('Linked')}</span></span>`
                    : `<a class="btn" href="/api/dev.php?a=login&link=1">${ghMark}${t('Link GitHub')}</a><small>${t('Needed to apply. We only see your public profile.')}</small>`}</div>
                  <label>${t('Discord')} <span class="opt">${t('optional')}</span><input name="discord" maxlength="64" placeholder="${t('Your username')}"></label>
                </div>
                <fieldset class="hub-ask-plat"><legend>${t('What do you make apps for?')}</legend>
                  <label class="check"><input type="radio" name="platform" value="ps5" required> PS5</label>
                  <label class="check"><input type="radio" name="platform" value="pc"> PC</label>
                  <label class="check"><input type="radio" name="platform" value="both"> ${t('Both')}</label>
                </fieldset>
                <label>${t('What are you working on?')}<textarea name="work" rows="5" maxlength="2000" required placeholder="${t('Your projects, what they do, how far along they are, and what you\'ve made before.')}"></textarea><small>${t('A few sentences at least.')}</small></label>
                <label>${t('Links to your work')}<textarea name="links" rows="3" maxlength="1000" required placeholder="${t('Repos, releases, videos or posts, one per line')}"></textarea><small>${t('At least one link.')}</small></label>
                <div class="form-row"><button class="btn primary" type="submit"${acc.github ? '' : ' disabled'}>${t('Send my application')}</button><span class="status" role="status">${acc.github ? '' : t('Link your GitHub first.')}</span></div>
              </form>`}
            <h3>${t('Your apps we found')}</h3>${appsList()}
          </section>`;
        const f = page.querySelector('.hub-ask');
        if (f) f.addEventListener('submit', (e) => { e.preventDefault(); const st = f.querySelector('.status'); const p = f.querySelector('[name=platform]:checked'); call('request', { name: f.name.value.trim(), email: f.email.value.trim(), platform: p ? p.value : '', work: f.work.value, links: f.links.value, discord: f.discord.value.trim() }).then((res) => { if (res.ok) load(); else say(st, res); }); });
        wireOut();
        return;
      }
      const tabs = [['apps', t('Your apps')], ['pc', t('PC recomps')], ['page', t('Your page')], ['betas', t('Betas')], ['logs', t('Logs')], ['ids', t('Title IDs')]];
      const tot = data.apps.reduce((o, a) => { const st = a.stats || {}; ['installs', 'week', 'downloads', 'likes'].forEach((k) => { o[k] += Number(st[k] || 0); }); return o; }, { installs: 0, week: 0, downloads: 0, likes: 0 });
      page.innerHTML = head(acc) + `
        <dl class="hub-totals">
          <div><dt>${t('Apps')}</dt><dd>${num(data.apps.length)}</dd></div><div><dt>${t('On PS5s now')}</dt><dd>${num(tot.installs)}</dd></div>
          <div><dt>${t('New this week')}</dt><dd>${num(tot.week)}</dd></div><div><dt>${t('GitHub downloads')}</dt><dd>${num(tot.downloads)}</dd></div><div><dt>${t('Likes')}</dt><dd>${num(tot.likes)}</dd></div></dl>
        <div class="hub-bar"><nav class="hub-tabs" role="tablist">${tabs.map(([k, n], i) => `<button type="button" role="tab" aria-selected="${i === 0}" class="${i === 0 ? 'on' : ''}" data-tab="${k}">${n}</button>`).join('')}</nav>
          <a class="btn primary hub-submit" href="/submit">+ ${t('Submit an app')}</a></div>
        <div data-pane></div>`;
      wireOut();
      page.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { page.querySelectorAll('[data-tab]').forEach((x) => { x.classList.toggle('on', x === b); x.setAttribute('aria-selected', String(x === b)); }); show(b.dataset.tab); }));
      show('apps');
    };
    const wireOut = () => { const o = page.querySelector('[data-out]'); if (o) o.addEventListener('click', () => call('out').then(() => { devHubButton(null); location.href = '/developers'; })); };
    const pane = () => page.querySelector('[data-pane]');
    const show = (tab) => ({ apps: showApps, pc: showPc, page: showPage, betas: showBetas, logs: showLogs, ids: showIds })[tab]();
    // (2.7.3) Title IDs: a free one, or one they picked, held for them while their app is on its way.
    const showIds = () => {
      pane().innerHTML = `<p class="muted">${t('Loading…')}</p>`;
      const paint = (j, note) => {
        if (!pane()) return;
        const open = j.ids.filter((x) => !x.used).length;
        pane().innerHTML = `
          <section class="glass card-pad hub-ids">
            <h2>${t('Your title IDs')}</h2>
            <p class="muted">${tf('Every PS5 app needs a title ID no other app uses. Get one here and it\'s yours: it shows as reserved on the {link}, and nobody else gets it. One that no app comes out with in 6 months goes back, unless you keep it.', { link: `<a href="/ids">${t('Title ID lookup')}</a>` })}</p>
            ${j.ids.length ? `<ul class="hub-id-list">${j.ids.map((x) => `<li><code>${esc(x.id)}</code>
              <span>${x.used ? `<span class="tag live">${t('In use')}</span>` : tf('Held until {date}', { date: esc(new Date(x.until + 'T12:00:00Z').toLocaleDateString(lang, { dateStyle: 'medium' })) })}</span>
              <span class="hub-id-btns">${x.used ? '' : `<button type="button" class="linkish" data-copy="${esc(x.id)}">${t('Copy')}</button><button type="button" class="linkish" data-act="id-renew" data-id="${esc(x.id)}">${t('Keep it longer')}</button><button type="button" class="linkish" data-act="id-release" data-id="${esc(x.id)}">${t('Let it go')}</button>`}</span></li>`).join('')}</ul>`
              : `<p>${t('No title IDs yet.')}</p>`}
            <div class="hub-id-new">
              <button type="button" class="btn primary" data-act="id-new"${open >= j.max ? ' disabled' : ''}>${t('Get a free title ID')}</button>
              <form class="hub-id-pick" novalidate><input name="id" maxlength="9" placeholder="PPSA12345" class="mono" spellcheck="false" autocomplete="off" aria-label="${t('A title ID you picked')}"><button class="btn" type="submit"${open >= j.max ? ' disabled' : ''}>${t('Reserve this one')}</button></form>
            </div>
            <p class="status" role="status">${note ? esc(note) : open >= j.max ? tf('That\'s {n} waiting: put an app out with one, or let one go, to get another.', { n: j.max }) : ''}</p>
          </section>`;
        const st = pane().querySelector('.status');
        const act = (a, id) => call(a, id ? { id } : {}).then((res) => { if (res.ok) paint({ ids: res.ids, max: j.max }, res.message); else say(st, res); });
        pane().querySelectorAll('[data-act]').forEach((b) => b.addEventListener('click', () => act(b.dataset.act, b.dataset.id)));
        pane().querySelectorAll('[data-copy]').forEach((b) => b.addEventListener('click', () => { navigator.clipboard && navigator.clipboard.writeText(b.dataset.copy); b.textContent = t('Copied'); }));
        const f = pane().querySelector('.hub-id-pick');
        f.addEventListener('submit', (e) => { e.preventDefault(); act('id-reserve', f.id.value.trim().toUpperCase()); });
        if (note) { st.className = 'status ok'; }
      };
      fetch('/api/dev.php?a=ids', { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null).then((j) => {
        if (!j || !j.ok) { pane().innerHTML = `<p class="status bad">${t('That didn\'t go through. Check your connection and try again.')}</p>`; return; }
        paint(j, '');
      });
    };

    // Your apps: numbers, and changes to their pages
    const FIELDS = [['blurb', t('One-line summary'), 160], ['description', t('Description'), 6000], ['news', t('What\'s new'), 2000], ['needs', t('What players need'), 160], ['credits', t('Credits'), 1000], ['video', t('YouTube video'), 200]];
    // (2.7.3) Verified developers change their pages themselves: pictures, screenshots, words.
    const PICS = [['icon', t('Icon'), t('Square, 512×512 or bigger.')], ['art', t('Backdrop'), t('16:9, 3840×2160 works best.')], ['logo', t('Logo'), t('Optional. A PNG with a transparent background.')]];
    const PIC_FIELD = { icon: 'icon_file', art: 'hero_file', logo: 'logo_file' };
    const editForm = (a) => `<details class="hub-edit"><summary>${t('Edit its page')}</summary>
      <form class="form hub-change" data-app="${esc(a.id)}" data-edit novalidate>
        <p class="muted">${t('Your changes go live on the store and the site with the next update, usually within a day.')}</p>
        <div class="hub-pics">${PICS.map(([k, n, hint]) => `<label class="hub-pic-pick hub-pic-${k}"><span class="hub-pic-box">${a[k] ? `<img src="${esc(a[k])}" alt="">` : `<span class="muted">${t('None yet')}</span>`}</span>
          <strong>${n}</strong><small>${hint}</small><input type="file" name="${PIC_FIELD[k]}" accept="image/png,image/jpeg,image/webp"></label>`).join('')}</div>
        <fieldset class="hub-shots"><legend>${t('Screenshots')}</legend>
          <div class="hub-shot-grid">${(a.screens || []).map((s, i) => `<label class="hub-shot"><img src="${esc(s)}" alt="" loading="lazy"><span><input type="checkbox" name="shot_remove[]" value="${i}"> ${t('Remove')}</span></label>`).join('') || `<p class="muted">${t('No screenshots yet.')}</p>`}</div>
          <label>${t('Add screenshots')}<input type="file" name="shot_files[]" accept="image/png,image/jpeg,image/webp" multiple><small>${t('16:9 works best. Up to 12 in all.')}</small></label>
        </fieldset>
        ${FIELDS.map(([k, n, max]) => `<label>${n}${max > 300 ? `<textarea name="${k}" rows="${k === 'description' ? 7 : 3}" maxlength="${max}">${esc(a.fields[k] || '')}</textarea>` : `<input name="${k}" maxlength="${max}" value="${esc(k === 'video' ? (a.videoUrl || '') : (a.fields[k] || ''))}"${k === 'video' ? ' placeholder="https://www.youtube.com/watch?v=…"' : ''}>`}</label>`).join('')}
        <div class="form-row"><button class="btn primary" type="submit">${t('Save changes')}</button><span class="status" role="status"></span></div>
      </form></details>`;
    const wireEdit = (f) => {
      const a = data.apps.find((x) => x.id === f.dataset.app);
      f.querySelectorAll('.hub-pic-pick input[type=file]').forEach((inp) => inp.addEventListener('change', () => {
        const box = inp.closest('label').querySelector('.hub-pic-box');
        if (inp.files[0]) box.innerHTML = `<img src="${URL.createObjectURL(inp.files[0])}" alt="">`;
      }));
      f.addEventListener('submit', (e) => {
        e.preventDefault();
        const st = f.querySelector('.status'), btn = f.querySelector('button[type=submit]');
        const fd = new FormData();
        fd.append('a', 'edit'); fd.append('app', a.id);
        FIELDS.forEach(([k]) => fd.append(`fields[${k}]`, f[k].value));
        Object.values(PIC_FIELD).forEach((n) => { if (f[n].files[0]) fd.append(n, f[n].files[0]); });
        f.querySelectorAll('[name="shot_remove[]"]:checked').forEach((c) => fd.append('shot_remove[]', c.value));
        Array.from(f.querySelector('[name="shot_files[]"]').files).forEach((x) => fd.append('shot_files[]', x));
        btn.disabled = true; st.textContent = t('Saving…'); st.className = 'status';
        fetch('/api/dev.php', { method: 'POST', credentials: 'same-origin', body: fd }).then((r) => r.json())
          .catch(() => ({ ok: false, error: t('That didn\'t go through. Check your connection and try again.') }))
          .then((res) => {
            btn.disabled = false;
            if (res.ok) {
              Object.assign(a, { icon: res.icon, art: res.art, logo: res.logo, screens: res.screens, fields: res.fields, videoUrl: res.fields.video ? 'https://www.youtube.com/watch?v=' + res.fields.video : '' });
              const card = f.closest('.hub-app');
              const box = document.createElement('div'); box.innerHTML = editForm(a);
              const fresh = box.firstElementChild; fresh.open = true;
              f.closest('details').replaceWith(fresh);
              const nf = fresh.querySelector('form'); wireEdit(nf); say(nf.querySelector('.status'), res);
              const art = card.querySelector('.hub-app-art'); if (art && a.art) art.style.backgroundImage = `url('${cssUrl(a.art)}')`;
              const ic = card.querySelector('.hub-app-head img'); if (ic && a.icon) ic.src = a.icon;
            } else say(st, res);
          });
      });
    };
    // (2.9) AI use: the developer says whether AI tools helped make it, and what for.
    const AI_USES = [['code', t('Code')], ['art', t('Art')], ['text', t('Writing')], ['audio', t('Voice or music')]];
    const aiSummary = (ai) => ai && ai.v === 'yes' ? t('AI-assisted') + ' · ' + ai.uses.map((u) => (AI_USES.find(([k]) => k === u) || [u, u])[1]).join(', ')
      : ai && ai.v === 'no' ? t('Made without AI') : t('Not said yet');
    const aiForm = (a, key) => `<details class="hub-ai"><summary>${t('AI use')}: <span data-ai-sum>${esc(aiSummary(a.ai))}</span></summary>
      <form class="form hub-ai-form" data-ai-app="${esc(key)}" novalidate>
        <p class="muted">${t('Did AI tools help make it? If they did, its page says AI-assisted, and people who hide AI-assisted recomps won\'t see it. Tell us honestly: we also look at its GitHub.')}</p>
        <div class="ai-yn"><label class="check"><input type="radio" name="ai" value="no"${a.ai && a.ai.v === 'no' ? ' checked' : ''}> ${t('No')}</label><label class="check"><input type="radio" name="ai" value="yes"${a.ai && a.ai.v === 'yes' ? ' checked' : ''}> ${t('Yes')}</label></div>
        <div class="ai-yes"${a.ai && a.ai.v === 'yes' ? '' : ' hidden'}>
          <div class="ai-uses"><span class="muted">${t('What for?')}</span>${AI_USES.map(([k, n]) => `<label class="check"><input type="checkbox" name="uses" value="${k}"${a.ai && a.ai.v === 'yes' ? (a.ai.uses.includes(k) ? ' checked' : '') : (k === 'code' ? ' checked' : '')}> ${n}</label>`).join('')}</div>
          <label>${t('In a few words')} <span class="opt">${t('optional')}</span><input name="note" maxlength="200" value="${esc(a.ai && a.ai.note || '')}" placeholder="${t('Claude helped write the renderer')}"></label>
        </div>
        <div class="form-row"><button class="btn primary" type="submit">${t('Save')}</button><span class="status" role="status"></span></div>
      </form></details>`;
    const wireAi = (root, findApp) => root.querySelectorAll('.hub-ai-form').forEach((f) => {
      const sync = () => { f.querySelector('.ai-yes').hidden = !(f.querySelector('input[name=ai]:checked') || {}).value || f.querySelector('input[name=ai]:checked').value !== 'yes'; };
      f.addEventListener('change', sync);
      f.addEventListener('submit', (e) => {
        e.preventDefault();
        const v = (f.querySelector('input[name=ai]:checked') || {}).value || '';
        const st = f.querySelector('.status');
        if (!v) { st.textContent = t('Pick yes or no.'); st.className = 'status bad'; return; }
        call('ai', { app: f.dataset.aiApp, ai: v, uses: [...f.querySelectorAll('input[name=uses]:checked')].map((x) => x.value), note: f.note.value }).then((res) => {
          say(st, res);
          const a = findApp(f.dataset.aiApp);
          if (res.ok && a) { a.ai = res.ai; f.closest('details').querySelector('[data-ai-sum]').textContent = aiSummary(res.ai); }
        });
      });
    });
    const showApps = () => {
      const waiting = data.changes.filter((c) => c.status === 'pending');
      pane().innerHTML = `
        ${data.apps.length ? `<div class="hub-apps">${data.apps.map((a) => `
          <section class="glass hub-app">
            <div class="hub-app-art"${a.art ? ` style="background-image:url('${cssUrl(a.art)}')"` : ''}></div>
            <div class="hub-app-body">
            <div class="hub-app-head">${a.icon ? `<img src="${esc(a.icon)}" alt="" width="64" height="64">` : `<span class="ico mono">${esc(a.name.slice(0, 2))}</span>`}<div><h2>${esc(a.name)}${a.beta ? ` <span class="tag">${t('Beta')}</span>` : ''}</h2><span class="muted">${esc(a.version || '')}${a.soon ? '' : ` · <a href="/game/${encodeURIComponent(a.id)}">${t('Its page')}</a>`}</span></div></div>
            ${a.stats ? spark(a.stats.days) : ''}
            ${a.stats ? `<dl class="hub-stats">
              <div><dt>${t('On PS5s now')}</dt><dd>${num(a.stats.installs)}</dd></div><div><dt>${t('New this week')}</dt><dd>${num(a.stats.week)}</dd></div>
              <div><dt>${t('GitHub downloads')}</dt><dd>${num(a.stats.downloads)}</dd></div><div><dt>${t('Likes')}</dt><dd>${num(a.stats.likes)}</dd></div></dl>` : ''}
            ${data.canEdit ? editForm(a) : `<details><summary>${t('Suggest changes to its page')}</summary>
              <form class="form hub-change" data-app="${esc(a.id)}" novalidate>
                ${FIELDS.map(([k, n, max]) => `<label>${n}${max > 300 ? `<textarea name="${k}" rows="${k === 'description' ? 7 : 3}" maxlength="${max}">${esc(a.fields[k] || '')}</textarea>` : `<input name="${k}" maxlength="${max}" value="${esc(a.fields[k] || '')}">`}</label>`).join('')}
                <label>${t('A note for us')} <span class="opt">${t('optional')}</span><input name="message" maxlength="1000"></label>
                <div class="form-row"><button class="btn primary" type="submit">${t('Send for review')}</button><span class="status" role="status"></span></div>
              </form></details>`}
            </div>
          </section>`).join('')}</div>`
        : `<section class="glass card-pad"><p>${t('None of your GitHub repositories are in RecompStore yet.')}</p><a class="btn primary" href="/submit">${t('Submit an app')}</a></section>`}
        ${waiting.length ? `<p class="muted">${tn(waiting.length, '1 change waiting for review.', '{n} changes waiting for review.')}</p>` : ''}
        ${(data.submissions || []).length ? `<section class="glass card-pad hub-subs"><h2>${t('Apps you sent in')}</h2><ul>${data.submissions.map((s) => `<li><span><strong>${esc(s.name)}</strong> <span class="muted">${esc(s.repo)}</span></span>
          <span class="tag ${s.state}">${s.state === 'live' ? t('In the store') : s.state === 'waiting' ? t('Waiting for review') : t('Looked at')}</span></li>`).join('')}</ul>
          <a class="btn" href="/submit">+ ${t('Send in another')}</a></section>` : ''}`;
      pane().querySelectorAll('.hub-change[data-edit]').forEach(wireEdit);
      pane().querySelectorAll('.hub-change:not([data-edit])').forEach((f) => f.addEventListener('submit', (e) => {
        e.preventDefault();
        const fields = {};
        FIELDS.forEach(([k]) => { fields[k] = f[k].value; });
        call('change', { app: f.dataset.app, fields, message: f.message.value }).then((res) => { say(f.querySelector('.status'), res); if (res.ok) data.changes.unshift({ status: 'pending' }); });
      }));
    };

    // (2.8) Their PC recomps: downloads, what players say, and the words on each page.
    const PC_FIELDS = [['blurb', t('One-line summary'), 160], ['description', t('Description'), 8000], ['features', t("What's in it"), 1200, t('One per line')],
      ['media', t('Around the web'), 5000, t('YouTube, Reddit or X links, one per line')], ['needs', t('What players need'), 200], ['credits', t('Credits'), 1000]];
    const pcFieldsHtml = (a) => PC_FIELDS.map(([k, n, max, hint]) => `<label>${n}${max > 300 ? `<textarea name="${k}" rows="${k === 'description' ? 7 : 4}" maxlength="${max}">${esc((a.fields || {})[k] || '')}</textarea>`
      : `<input name="${k}" maxlength="${max}" value="${esc((a.fields || {})[k] || '')}">`}${hint ? `<small>${hint}</small>` : ''}</label>`).join('');
    const showPc = () => {
      const list = data.pcApps || [];
      pane().innerHTML = list.length ? `<div class="hub-apps">${list.map((a) => `
          <section class="glass hub-app hub-pc">
            <div class="hub-app-art"${a.art ? ` style="background-image:url('${cssUrl(a.art)}')"` : ''}></div>
            <div class="hub-app-body">
            <div class="hub-app-head">${a.cover ? `<img class="hub-pc-cover" src="${esc(a.cover)}" alt="" width="48" height="72">` : `<span class="ico mono">${esc(a.name.slice(0, 2))}</span>`}
              <div><h2>${esc(a.name)}</h2><span class="muted">${a.game && a.game !== a.name ? esc(a.game) + ' · ' : ''}${esc(a.version || '')} · <a href="/pc/game/${encodeURIComponent(a.id)}">${t('Its page')}</a></span></div></div>
            <dl class="hub-stats">
              <div><dt>${t('GitHub downloads')}</dt><dd>${num(a.stats.downloads)}</dd></div><div><dt>${t('Plays great')}</dt><dd>${num(a.stats.runs)}</dd></div>
              <div><dt>${t('Has problems')}</dt><dd>${num(a.stats.issues)}</dd></div><div><dt>${t("Won't run")}</dt><dd>${num(a.stats.broken)}</dd></div></dl>
            <details><summary>${data.canEdit ? t('Edit its page') : t('Suggest changes to its page')}</summary>
              <form class="form hub-change" data-pc="${esc(a.id)}" novalidate>
                ${data.canEdit ? `<p class="muted">${t('Your changes go on its page right away.')}</p>` : ''}
                ${pcFieldsHtml(a)}
                ${data.canEdit ? '' : `<label>${t('A note for us')} <span class="opt">${t('optional')}</span><input name="message" maxlength="1000"></label>`}
                <div class="form-row"><button class="btn primary" type="submit">${data.canEdit ? t('Save changes') : t('Send for review')}</button><span class="status" role="status"></span></div>
              </form></details>
            ${aiForm(a, 'pc:' + a.id)}
            </div>
          </section>`).join('')}</div>`
        : `<section class="glass card-pad"><p>${t('None of your GitHub repositories are on RecompLauncher yet.')}</p><a class="btn primary" href="/submit">${t('Submit an app')}</a></section>`;
      wireAi(pane(), (k) => list.find((x) => 'pc:' + x.id === k));
      pane().querySelectorAll('form[data-pc]').forEach((f) => f.addEventListener('submit', (e) => {
        e.preventDefault();
        const a = list.find((x) => x.id === f.dataset.pc), st = f.querySelector('.status'), btn = f.querySelector('button[type=submit]');
        const fields = {};
        PC_FIELDS.forEach(([k]) => { fields[k] = f[k].value; });
        btn.disabled = true; st.textContent = t('Saving…'); st.className = 'status';
        (data.canEdit ? call('pc-edit', { app: a.id, fields }) : call('change', { app: 'pc:' + a.id, fields, message: f.message.value })).then((res) => {
          btn.disabled = false;
          say(st, res);
          if (res.ok && res.fields) a.fields = res.fields;
          if (res.ok && !data.canEdit) data.changes.unshift({ status: 'pending' });
        });
      }));
    };

    // Your page: name, about, links; Discord
    const showPage = () => {
      const p = data.profile || { name: data.account.name, about: '', links: {} };
      const kinds = Object.entries(data.linkKinds).filter(([k]) => k !== 'github');
      pane().innerHTML = `
        <div class="split">
          <form class="glass form hub-profile" novalidate>
            <label>${t('Name')}<input name="name" maxlength="80" value="${esc(p.name)}" required></label>
            <label>${t('About')} <span class="opt">${t('optional')}</span><textarea name="about" rows="3" maxlength="400" placeholder="${t('What you make, in a sentence or two')}">${esc(p.about)}</textarea></label>
            <h3>${t('Links')}</h3>
            <p class="form-note">${t('The first of Ko-fi, Patreon, PayPal or Support you fill in becomes a Support button on your app pages.')}</p>
            <div class="two">${kinds.map(([k, n]) => `<label>${esc(k === 'support' ? t('Other support link') : k === 'site' ? t('Website') : n)}<input name="l_${k}" maxlength="300" placeholder="https://" value="${esc((p.links || {})[k] || '')}" inputmode="url"></label>`).join('')}</div>
            <div class="form-row"><button class="btn primary" type="submit">${t('Save')}</button><span class="status" role="status"></span></div>
          </form>
          <aside>
            <div class="glass card-pad hub-weekly"><span class="kicker">${t('Weekly email')}</span>
              <p>${t('Every Monday: your installs, new installs and the logs players sent, for each of your apps.')}</p>
              <label class="check"><input type="checkbox" data-weekly${data.account.weekly ? ' checked' : ''}> ${t('Send me the weekly email')}</label><span class="status" role="status"></span></div>
            <div class="glass card-pad"><span class="kicker">Discord</span>
              ${data.account.discord ? `<p>${tf('Linked as @{name}.', { name: esc(data.account.discord) })}</p>` : `<p>${t('Link your Discord to get the Developer role in the RecompStore server.')}</p>`}
              ${data.discordReady ? `<a class="btn" href="/api/discord.php?game=hub">${SVG.discord}<span>${data.account.discord ? t('Link again') : t('Link Discord')}</span></a>` : ''}
              ${data.discordInvite ? `<p><a href="${esc(data.discordInvite)}" target="_blank" rel="noopener">${t('Join the Discord')}</a></p>` : ''}</div>
            ${data.profile && data.profile.verified ? (() => {
              const url = `https://recompstore.com/dev/${encodeURIComponent(data.profile.slug)}`, img = 'https://recompstore.com/assets/press/verified-dark.svg';
              return `<div class="glass card-pad hub-badge"><span class="kicker">${t('Verified badge for your README')}</span>
                <a href="${esc(url)}" target="_blank" rel="noopener"><img src="/assets/press/verified-dark.svg" alt="Verified developer on RecompStore" height="48"></a>
                <p>${t('Shows players your apps come from a verified developer, and links to your page.')}</p>
                <div class="press-snip"><span class="kicker">Markdown</span><span class="cmd-row"><code class="cmd" id="hub-badge-md">${esc(`[![Verified developer on RecompStore](${img})](${url})`)}</code><button class="copy" type="button" data-copy="#hub-badge-md">${t('Copy')}</button></span></div>
                <p class="form-note">${tf('A light version, and the Get it on RecompStore badge for each app, are in the {kit}.', { kit: `<a href="/press">${t('Press kit')}</a>` })}</p></div>`;
            })() : `<div class="glass card-pad"><span class="kicker">${t('Badge for your README')}</span><p>${t('Point players to your apps with the Get it on RecompStore badge.')}</p><a class="btn" href="/press">${t('Press kit')}</a></div>`}
          </aside>
        </div>`;
      const wk = pane().querySelector('[data-weekly]');
      wk.addEventListener('change', () => call('weekly', { on: wk.checked }).then((res) => { say(wk.closest('div').querySelector('.status'), res); if (res.ok) data.account.weekly = wk.checked; }));
      const f = pane().querySelector('.hub-profile');
      f.addEventListener('submit', (e) => {
        e.preventDefault();
        const links = {};
        kinds.forEach(([k]) => { const v = f['l_' + k].value.trim(); if (v) links[k] = v; });
        call('profile', { name: f.name.value, about: f.about.value, links }).then((res) => { say(f.querySelector('.status'), res); if (res.ok && data.profile) Object.assign(data.profile, { name: f.name.value, about: f.about.value, links }); });
      });
    };

    // Betas: the build from a GitHub release, codes, and the Betas page
    const showBetas = () => {
      pane().innerHTML = `<p class="muted">${t('Loading…')}</p>`;
      fetch('/api/dev.php?a=betas', { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null).then((j) => {
        if (!page.isConnected || !pane()) return;
        if (!j || !j.ok) { pane().innerHTML = `<p class="status bad">${t('That didn\'t go through. Check your connection and try again.')}</p>`; return; }
        const intro = `<p class="muted hub-beta-intro">${t('A beta is for an app that isn\'t out for everyone yet. Want one for a new app? Send it in with Submit an app and say it\'s a beta in the notes, and we\'ll set it up.')}</p>`;
        if (!j.betas.length) { pane().innerHTML = `<section class="glass card-pad"><h2>${t('No betas yet')}</h2>${intro}<a class="btn primary" href="/submit">${t('Submit an app')}</a></section>`; return; }
        pane().innerHTML = intro + j.betas.map((b) => `
          <section class="glass card-pad hub-beta" data-beta="${esc(b.id)}">
            <div class="hub-app-head">${b.icon ? `<img src="${esc(b.icon)}" alt="" width="56" height="56">` : ''}<div><h2>${esc(b.name)}</h2>
              <span class="muted">${b.build ? `${esc(b.build.version)} · ${esc(b.build.size)} · ${b.build.signed ? t('Out to testers') : t('Waiting for RecompStore to sign it')}` : t('No build yet')}</span></div></div>
            <h3>${t('Build')}</h3>
            ${b.releases.length ? `<form class="form hub-build" novalidate>
              <div class="two"><label>${t('Release')}<select name="tag">${b.releases.map((r) => `<option value="${esc(r.tag)}">${esc(r.name)}${r.pre ? ' · ' + t('pre-release') : ''} · ${esc(r.date)}</option>`).join('')}</select></label>
              <label>${t('File')}<select name="asset"></select></label>
              <label>${t('Version testers see')}<input name="version" maxlength="40" placeholder="1.0.0-beta1"></label></div>
              <label>${t('What to test')} <span class="opt">${t('optional')}</span><textarea name="notes" rows="3" maxlength="4000">${esc(b.notes)}</textarea></label>
              <div class="form-row"><button class="btn primary" type="submit">${t('Use this build')}</button><span class="status" role="status"></span></div></form>`
              : `<p class="muted">${tf('Put a .zip on a release (a pre-release is best) at github.com/{repo}, then come back.', { repo: esc(b.repo) })}</p>`}
            <h3>${t('Codes')}</h3>
            <form class="form hub-codes" novalidate><div class="two"><label>${t('How many')}<input name="count" type="number" min="1" max="50" value="5"></label><label>${t('Note')} <span class="opt">${t('optional')}</span><input name="note" maxlength="100" placeholder="${t('Who they\'re for')}"></label></div>
              <div class="form-row"><button class="btn" type="submit">${t('Make codes')}</button><span class="status" role="status"></span></div></form>
            <div data-new-codes></div>
            ${b.codes.length ? `<div class="hub-codes-list"><table><thead><tr><th>${t('Code')}</th><th>${t('Note')}</th><th>${t('Status')}</th><th></th></tr></thead><tbody>${b.codes.map((c) => `<tr><td class="mono">${esc(c.code)}</td><td>${esc(c.note.replace(/^Dev @[^:]+:? ?/, ''))}</td><td>${c.off ? t('Cancelled') : c.used ? tf('Used {date}', { date: esc(c.when) }) : t('Not used yet')}</td><td>${!c.off ? `<button class="linkish" type="button" data-cancel="${esc(c.code)}">${t('Cancel')}</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : ''}
            <h3>${t('On recompstore.com/beta')}</h3>
            <form class="form hub-bpage" novalidate>
              <label class="check"><input type="checkbox" name="public"${b.public ? ' checked' : ''}> ${t('Show this beta on the Betas page')}</label>
              <label class="check"><input type="checkbox" name="discord"${b.discord ? ' checked' : ''}> ${t('Let testers get a code there by signing in with Discord')}</label>
              <label style="max-width:240px">${t('Codes that way, at most')}<input name="limit" type="number" min="0" max="10000" value="${b.limit}"><small>${tf('0 for no limit. {n} given out so far.', { n: b.discordTaken })}</small></label>
              <div class="form-row"><button class="btn" type="submit">${t('Save')}</button><span class="status" role="status"></span></div></form>
          </section>`).join('');
        pane().querySelectorAll('.hub-beta').forEach((box) => {
          const b = j.betas.find((x) => x.id === box.dataset.beta);
          const bf = box.querySelector('.hub-build');
          if (bf) {
            const fill = () => { const r = b.releases.find((x) => x.tag === bf.tag.value); bf.asset.innerHTML = (r ? r.assets : []).map((x) => `<option value="${esc(x.name)}">${esc(x.name)} (${Math.max(1, Math.round(x.size / 1048576))} MB)</option>`).join(''); if (!bf.version.value && r) bf.version.placeholder = r.tag.replace(/^v/i, ''); };
            bf.tag.addEventListener('change', fill); fill();
            bf.addEventListener('submit', (e) => { e.preventDefault(); const st = bf.querySelector('.status'), btn = bf.querySelector('button'); btn.disabled = true; st.textContent = t('Getting the file from GitHub… this can take a minute.'); st.className = 'status';
              call('beta-build', { app: b.id, tag: bf.tag.value, asset: bf.asset.value, version: bf.version.value, notes: bf.notes.value }).then((res) => { btn.disabled = false; say(st, res); }); });
          }
          const cf = box.querySelector('.hub-codes');
          cf.addEventListener('submit', (e) => { e.preventDefault(); const st = cf.querySelector('.status');
            call('beta-codes', { app: b.id, count: +cf.count.value, note: cf.note.value }).then((res) => {
              if (!res.ok) { say(st, res); return; }
              st.textContent = ''; const id = 'codes-' + b.id.replace(/[^\w-]/g, '');
              box.querySelector('[data-new-codes]').innerHTML = `<div class="press-snip"><span class="kicker">${tn(res.codes.length, '1 new code', '{n} new codes')}</span><span class="cmd-row"><code class="cmd codes-out" id="${id}">${res.codes.map(esc).join('\n')}</code><button class="copy" type="button" data-copy="#${id}">${t('Copy')}</button></span><p class="form-note">${t('Each code unlocks the beta on one PS5: RecompStore, Settings, Redeem a code.')}</p></div>`;
            }); });
          box.querySelectorAll('[data-cancel]').forEach((x) => x.addEventListener('click', () => { if (!confirm(tf('Cancel {code}? The PS5 that used it loses the beta.', { code: x.dataset.cancel }))) return; call('beta-cancel', { app: b.id, code: x.dataset.cancel }).then((res) => { if (res.ok) x.closest('tr').children[2].textContent = t('Cancelled'), x.remove(); }); }));
          const pf = box.querySelector('.hub-bpage');
          pf.addEventListener('submit', (e) => { e.preventDefault(); call('beta-page', { app: b.id, public: pf.public.checked, discord: pf.discord.checked, limit: +pf.limit.value }).then((res) => say(pf.querySelector('.status'), res)); });
        });
      });
    };

    // Logs players sent for their apps
    const CATS = { bug: t('Bug'), crash: t('Crash'), install: t('Won\'t install'), slow: t('Slow'), other: t('Other') };
    const showLogs = () => {
      pane().innerHTML = `<p class="muted">${t('Loading…')}</p>`;
      fetch('/api/dev.php?a=logs', { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null).then((j) => {
        if (!page.isConnected || !pane()) return;
        if (!j || !j.ok) { pane().innerHTML = `<p class="status bad">${t('That didn\'t go through. Check your connection and try again.')}</p>`; return; }
        const name = (id) => (data.apps.find((a) => a.id === id) || { name: id }).name;
        const kb = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
        const when = (at) => new Date(at).toLocaleString(lang, { dateStyle: 'medium', timeStyle: 'short' });
        if (!j.logs.length) { pane().innerHTML = `<section class="glass card-pad"><p>${t('No logs yet. When players send logs for your apps from RecompStore, they show up here.')}</p></section>`; return; }
        // (2.7.3) The same problem from many players, once: grouped by what the logs say went wrong.
        const groups = new Map();
        j.logs.forEach((l) => {
          const k = l.app + '|' + (l.sig || '');
          if (!groups.has(k)) groups.set(k, { app: l.app, sig: l.sig || '', logs: [], versions: new Set() });
          const g = groups.get(k); g.logs.push(l); if (l.version) g.versions.add(l.version);
        });
        const top = [...groups.values()].filter((g) => g.sig).sort((x, y) => y.logs.length - x.logs.length).slice(0, 8);
        const apps = [...new Set(j.logs.map((l) => l.app))];
        let filter = { app: '', sig: null };
        const logCard = (l) => `
          <section class="glass card-pad hub-log">
            <div class="hub-log-head"><strong>${esc(name(l.app))}</strong>${l.category ? `<span class="tag">${esc(CATS[l.category] || l.category)}</span>` : ''}<span class="muted">${l.version ? esc(l.version) + ' · ' : ''}${esc(when(l.at))}</span></div>
            ${l.sig ? `<p class="hub-sig"><code dir="ltr">${esc(l.sig)}</code></p>` : ''}
            ${l.message ? `<p dir="auto">${esc(l.message)}</p>` : ''}${l.what ? `<p dir="auto"><strong>${t('What happened:')}</strong> ${esc(l.what)}</p>` : ''}${l.before ? `<p dir="auto"><strong>${t('Right before:')}</strong> ${esc(l.before)}</p>` : ''}
            <ul class="hub-files">${l.files.map((f) => `<li><a href="/api/dev.php?a=file&amp;id=${f.id}" target="_blank" rel="noopener">${esc(f.name)}</a> <span class="muted">${kb(f.size)}</span> · <a href="/api/dev.php?a=file&amp;id=${f.id}&amp;dl=1">${t('Download')}</a></li>`).join('') || `<li class="muted">${t('No files came with it.')}</li>`}</ul>
          </section>`;
        pane().innerHTML = `
          <div class="hub-logs-bar">
            <p class="muted">${t('Logs players sent from RecompStore for your apps. Their console and contact details aren\'t included.')}</p>
            <div class="hub-logs-acts">
              ${apps.length > 1 ? `<select data-log-app aria-label="${t('App')}"><option value="">${t('All your apps')}</option>${apps.map((a) => `<option value="${esc(a)}">${esc(name(a))}</option>`).join('')}</select>` : ''}
              <a class="btn" data-log-zip href="/api/dev.php?a=zip" download>${t('Download all logs')}</a>
            </div>
          </div>
          ${top.length ? `<section class="glass card-pad hub-problems"><h2>${t('Most reported problems')}</h2><p class="muted">${t('Logs that end with the same error, from every player who sent one. Press one to see just those.')}</p>
            <ol>${top.map((g, i) => `<li><button type="button" class="linkish" data-sig="${i}"><span class="n">${num(g.logs.length)}×</span><span><code dir="ltr">${esc(g.sig)}</code><small>${esc(name(g.app))}${g.versions.size ? ' · ' + esc([...g.versions].slice(0, 4).join(', ')) : ''} · ${t('last')} ${esc(when(g.logs[0].at))}</small></span></button></li>`).join('')}</ol></section>` : ''}
          <p class="hub-filter-note" data-filter-note hidden></p>
          <div data-log-list></div>`;
        const list = pane().querySelector('[data-log-list]'), note = pane().querySelector('[data-filter-note]'), zip = pane().querySelector('[data-log-zip]');
        const draw = () => {
          const shown = j.logs.filter((l) => (!filter.app || l.app === filter.app) && (filter.sig === null || ((l.app + '|' + (l.sig || '')) === filter.sig)));
          list.innerHTML = shown.map(logCard).join('') || `<p class="muted">${t('No logs for that app.')}</p>`;
          note.hidden = filter.sig === null;
          if (filter.sig !== null) { note.innerHTML = `${tf('Showing {n} logs with the same problem.', { n: num(shown.length) })} <button type="button" class="linkish" data-clear>${t('Show all')}</button>`; note.querySelector('[data-clear]').addEventListener('click', () => { filter.sig = null; draw(); }); }
          zip.href = '/api/dev.php?a=zip' + (filter.app ? '&app=' + encodeURIComponent(filter.app) : '');
          zip.textContent = filter.app ? t('Download its logs') : t('Download all logs');
        };
        const sel = pane().querySelector('[data-log-app]');
        if (sel) sel.addEventListener('change', () => { filter = { app: sel.value, sig: null }; draw(); });
        pane().querySelectorAll('[data-sig]').forEach((b) => b.addEventListener('click', () => { const g = top[+b.dataset.sig]; filter = { app: '', sig: g.app + '|' + g.sig }; if (sel) sel.value = ''; draw(); note.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }));
        draw();
      });
    };
    load();
  }

  // ---- (2.7.1) Betas: what's in testing and on the way, and a code through Discord ----------------
  function betaPage() {
    view.innerHTML = `
      <article class="page beta-page">
        <header class="page-head"><span class="kicker">${t('Betas')}</span><h1>${t('Test what\'s next')}</h1>
          <p class="lead">${t('Try new games and apps before everyone else. Sign in with Discord to get a code, then unlock the beta in RecompStore.')}</p></header>
        <div data-dc-note></div>
        <div class="glass card-pad beta-me" data-me><p class="muted">${t('Loading…')}</p></div>
        <div class="beta-grid" data-betas></div>
        <section class="glass card-pad beta-how"><h2>${t('How it works')}</h2><ol>
          <li>${t('Sign in with Discord. You need to be in the RecompStore Discord server.')}</li>
          <li>${t('Get a code for an open beta. Each code works on one PS5.')}</li>
          <li>${t('On your PS5, open RecompStore, go to Settings and choose Redeem a code. If this phone is linked to your PS5, you can unlock it from here.')}</li>
        </ol></section>
      </article>`;
    setBackdrop(null);
    setAccent(null);
    const page = view.querySelector('.beta-page');
    const q = new URLSearchParams(location.search);
    const notes = { no: t('You didn\'t finish signing in with Discord.'), err: t('Signing in with Discord didn\'t work. Try again.'),
      again: t('Signing in with Discord didn\'t work. Try again.'), off: t('Signing in with Discord isn\'t open yet. Codes are handed out on Discord for now.') };
    if (notes[q.get('dc')]) view.querySelector('[data-dc-note]').innerHTML = `<p class="status bad beta-note">${notes[q.get('dc')]}</p>`;
    const jumpTo = q.get('game') || '';
    if (q.get('dc') || jumpTo) history.replaceState(history.state, '', '/beta');
    const signIn = (id) => '/api/discord.php' + (id ? '?game=' + encodeURIComponent(id) : '');
    const statusTag = (b) => ({
      open: `<span class="tag on">${t('Open')}</span>${b.left !== null ? ` <span class="muted">${tn(b.left, '1 spot left', '{n} spots left')}</span>` : ''}`,
      full: `<span class="tag">${t('Full')}</span>`, closed: `<span class="tag">${t('Codes on Discord')}</span>`, soon: `<span class="tag soon">${t('Coming soon')}</span>`,
    })[b.status];
    let data = null;
    const codeHTML = (b) => `
      <div class="beta-code"><span class="kicker">${t('Your code')}</span>
        <span class="cmd-row"><code class="cmd big" id="code-${esc(b.id)}">${esc(b.code)}</code><button class="copy" type="button" data-copy="#code-${esc(b.id)}">${t('Copy')}</button></span>
        ${me && me.linked ? `<div class="form-row"><button class="btn primary" type="button" data-unlock="${esc(b.id)}">${t('Unlock on my PS5')}</button><span class="status" role="status"></span></div>`
          : `<p class="form-note">${t('On your PS5: RecompStore, Settings, Redeem a code.')} <a href="/link">${t('Or link this phone to your PS5')}</a></p>`}
      </div>`;
    const actionHTML = (b) => {
      if (b.code) return codeHTML(b);
      const d = data.discord, u = data.me;
      if (b.status === 'soon') return `<p class="muted">${t('Not open yet. Watch the Discord to hear when it starts.')}</p>`;
      if (b.status === 'full') return `<p class="muted">${t('All the spots are taken. Watch the Discord for more.')}</p>`;
      if (b.status === 'closed' || !d.ready) return `<p class="muted">${t('Codes for this beta are handed out on Discord.')}</p>${d.invite ? `<a class="btn" href="${esc(d.invite)}" target="_blank" rel="noopener">${t('Open the Discord')}</a>` : ''}`;
      if (!u) return `<a class="btn primary" href="${signIn(b.id)}">${SVG.discord}<span>${t('Sign in with Discord to get a code')}</span></a>`;
      if (u.can === 'join') return `<p class="muted">${t('Join the RecompStore Discord first, then sign in again.')}</p>`;
      if (u.can === 'role') return `<p class="muted">${t('Codes are for testers with the beta role on Discord. Ask in the server.')}</p>`;
      return `<div class="form-row"><button class="btn primary" type="button" data-claim="${esc(b.id)}">${t('Get my code')}</button><span class="status" role="status"></span></div>`;
    };
    const drawMe = () => {
      const d = data.discord, u = data.me, box = page.querySelector('[data-me]');
      if (!d.ready) { box.innerHTML = `<p>${t('Codes are handed out on Discord for now.')}</p>${d.invite ? `<a class="btn" href="${esc(d.invite)}" target="_blank" rel="noopener">${SVG.discord}<span>${t('Open the Discord')}</span></a>` : ''}`; return; }
      if (!u) { box.innerHTML = `<p>${t('Sign in with Discord to see your codes and get new ones.')}</p><a class="btn primary" href="${signIn('')}">${SVG.discord}<span>${t('Sign in with Discord')}</span></a>`; return; }
      box.innerHTML = `<div class="beta-who">${u.avatar ? `<img src="${esc(u.avatar)}" alt="" width="44" height="44">` : ''}<div><strong>${esc(u.name)}</strong><span class="muted">${t('Signed in with Discord')}</span></div>
          <button class="linkish" type="button" data-dc-out>${t('Sign out')}</button></div>
        ${u.joined ? `<p class="status ok">${t('You\'re in the RecompStore Discord now. Welcome!')}</p>` : ''}
        ${u.can === 'join' ? `<p class="status bad">${t('You\'re not in the RecompStore Discord yet. Join it, then sign in again.')}</p><div class="cta-row">${d.invite ? `<a class="btn primary" href="${esc(d.invite)}" target="_blank" rel="noopener">${t('Join the Discord')}</a>` : ''}<a class="btn" href="${signIn('')}">${t('Sign in again')}</a></div>` : ''}`;
      const out = box.querySelector('[data-dc-out]');
      if (out) out.addEventListener('click', () => fetch('/api/discord.php', { method: 'POST' }).then(() => load()));
    };
    const drawBetas = () => {
      const list = data.betas, box = page.querySelector('[data-betas]');
      if (!list.length) { box.innerHTML = `<p class="lead">${t('No betas right now. Check back soon.')}</p>`; return; }
      box.innerHTML = list.map((b) => `
        <section class="glass beta-card ${b.status}" id="beta-${esc(b.id)}">
          <div class="beta-art"${b.hero ? ` style="background-image:url('${cssUrl(b.hero)}')"` : ''}>${b.icon ? `<img src="${esc(b.icon)}" alt="" width="88" height="88" loading="lazy">` : ''}</div>
          <div class="beta-body">
            <div class="beta-tags">${statusTag(b)}${b.version ? ` <span class="tag">${esc(b.version)}</span>` : ''}</div>
            <h2 dir="auto">${esc(b.name)}</h2>
            ${b.blurb ? `<p dir="auto">${esc(b.blurb)}</p>` : ''}
            ${b.notes ? `<details><summary>${t('What\'s new in this build')}</summary><p dir="auto" class="beta-notes">${esc(b.notes)}</p></details>` : ''}
            <div data-act>${actionHTML(b)}</div>
          </div>
        </section>`).join('');
      const el = jumpTo && document.getElementById('beta-' + jumpTo);
      if (el) el.scrollIntoView({ block: 'center' });
    };
    const load = () => fetch('/api/betas.php', { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null).then((j) => {
      if (!page.isConnected) return; // left the page meanwhile
      if (!j || !j.ok) { view.querySelector('[data-me]').innerHTML = `<p class="status bad">${t('Betas can\'t be shown right now. Try again later.')}</p>`; return; }
      data = j; drawMe(); drawBetas();
    });
    page.addEventListener('click', (e) => {
      const c = e.target.closest('[data-claim]');
      if (c) {
        const st = c.parentNode.querySelector('.status');
        c.disabled = true; st.textContent = t('Checking…'); st.className = 'status';
        fetch('/api/betas.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ game: c.dataset.claim }) })
          .then((r) => r.json()).catch(() => ({ ok: false, error: t('That didn\'t go through. Check your connection and try again.') }))
          .then((res) => {
            if (res.ok) { const b = data.betas.find((x) => x.id === c.dataset.claim); b.code = res.code; c.closest('[data-act]').innerHTML = codeHTML(b); return; }
            if (res.why === 'signin') { location.href = signIn(c.dataset.claim); return; }
            c.disabled = false; st.textContent = res.error; st.className = 'status bad';
          });
        return;
      }
      const u = e.target.closest('[data-unlock]');
      if (u) {
        const b = data.betas.find((x) => x.id === u.dataset.unlock), st = u.parentNode.querySelector('.status');
        u.disabled = true; st.textContent = t('Sending…'); st.className = 'status';
        api('redeem', { code: b.code }).then((res) => { u.disabled = false; st.textContent = res.ok ? res.message : res.error; st.className = res.ok ? 'status ok' : 'status bad'; });
      }
    });
    (me ? Promise.resolve() : refreshMe()).then(load);
  }

  // ---- (2.6) Coming soon: everything on its way, on one page -------------------------------------
  function soonListPage() {
    const list = cat.soon || [];
    view.innerHTML = `
      <article class="page">
        <header class="page-head"><span class="kicker">${t('Coming soon')}</span><h1>${t('On the way to RecompStore')}</h1></header>
        ${list.length ? `<div class="grid">${list.map((a) => tileHTML(a, true)).join('')}</div>` : `<p class="lead">${t('Nothing here yet.')}</p>`}
      </article>`;
    setBackdrop(list.find((a) => art(a)) || null);
    setAccent(list[0] || null);
  }

  // ---- (2.0) /r/<code>: a bug report the store sent, finished here ----------------------------
  function reportPage(code) {
    const c = code6(code);
    view.innerHTML = `
      <article class="form-page report-page">
        <span class="kicker">${t('Bug report')}</span>
        <h1>${t('Finish your report')}</h1>
        <div data-r><p class="muted">${t('Loading…')}</p></div>
      </article>`;
    setBackdrop(null);
    const box = view.querySelector('[data-r]');
    fetch('/api/report.php?a=info&code=' + encodeURIComponent(c), { cache: 'no-store' })
      .then((r) => r.json()).catch(() => ({ ok: false, error: t('recompstore.com didn\'t answer. Try again in a moment.') }))
      .then((info) => {
        if (!info.ok) { box.innerHTML = `<p class="lead">${esc(info.error)}</p><a class="btn" href="/report">${t('Report a bug another way')}</a>`; return; }
        if (info.finished) { box.innerHTML = `<p class="lead">${t('This report is finished already. Thanks for helping!')}</p><a class="btn" href="/">${t('Back to all games')}</a>`; return; }
        const g = byId(info.app.id);
        box.innerHTML = `
          <p class="lead">${tf('Your logs from {app} are in. Tell us what happened, and add pictures if they help.', { app: `<strong>${esc(info.app.name)}</strong>${info.version ? ' ' + esc(info.version) : ''}` })}</p>
          <form class="glass form" novalidate>
            <div class="report-about">${g ? iconHTML(g, 56) : ''}<span>${esc(info.app.name)}<span class="muted">${info.version ? tf('Version {version}', { version: esc(info.version) }) + ' · ' : ''}${tf('sent {when}', { when: esc(ago(info.sent)) })}</span></span></div>
            <label>${t('What happened?')}<textarea name="what" rows="6" maxlength="5000" required placeholder="${t('What you expected, and what happened instead.')}"></textarea></label>
            <label>${t('What were you doing right before?')} <span class="opt">${t('optional')}</span><textarea name="before" rows="3" maxlength="5000" placeholder="${t('The menu, the level, the button you pressed…')}"></textarea></label>
            <label>${t('Pictures')} <span class="opt">${t('optional, up to 4')}</span><input name="pictures[]" type="file" accept="image/png,image/jpeg,image/webp" multiple class="pics-input"></label>
            <div class="pics-preview"></div>
            <label>${t('How can we reach you?')} <span class="opt">${t('optional')}</span><input name="contact" maxlength="200" placeholder="${t('Email or Discord name, if you\'d like a reply')}"></label>
            <div class="form-row"><button class="btn primary" type="submit">${t('Send the report')}</button><span class="status" role="status"></span></div>
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
          st.textContent = pics.files.length > 4 ? t('Four pictures at most: only the first four go.') : ''; st.className = 'status';
        });
        f.addEventListener('submit', (e) => {
          e.preventDefault();
          const fv = (n) => f.querySelector(`[name="${n}"]`);
          if (fv('what').value.trim().length < 10) { st.textContent = t('Tell us a little more about what happened.'); st.className = 'status bad'; fv('what').focus(); return; }
          const files = [...pics.files].slice(0, 4);
          if (files.some((x) => x.size > 8 * 1048576)) { st.textContent = t('Each picture can be 8 MB at most.'); st.className = 'status bad'; return; }
          if (files.some((x) => !/^image\/(png|jpeg|webp)$/.test(x.type))) { st.textContent = t('Only pictures can be sent: PNG, JPEG or WebP.'); st.className = 'status bad'; return; }
          const fd = new FormData();
          fd.append('a', 'finish'); fd.append('code', c);
          fd.append('what', fv('what').value); fd.append('before', fv('before').value); fd.append('contact', fv('contact').value);
          files.forEach((x) => fd.append('pictures[]', x, x.name));
          const b = f.querySelector('button');
          b.disabled = true; st.textContent = files.length ? t('Sending the report and pictures…') : t('Sending…'); st.className = 'status';
          fetch('/api/report.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then((r) => r.json()).catch(() => ({ ok: false, error: t('That didn\'t send. Check your connection and try again.') }))
            .then((res) => {
              if (!res.ok) { b.disabled = false; st.textContent = res.error; st.className = 'status bad'; return; }
              f.innerHTML = `<div class="sent"><strong>${t('Thanks, the report is in.')}</strong><p>${esc(res.message)}</p><a class="btn" href="/">${t('Back to all games')}</a></div>`;
            });
        });
      });
  }

  function notFound() {
    view.innerHTML = `<section class="intro"><h1>${t('That page isn\'t here.')}</h1><p><a href="/">${t('Back to all games')}</a></p></section>`;
    setBackdrop(null);
  }

  // ---- Router ---------------------------------------------------------------------
  // The site's pages are #/about, #/contact...; a game's page is its own link, /game/<ID>, so the
  // address bar always has the link that shows the game's card when it's shared.
  // (2.6) The site's own pages also have plain addresses (recompstore.com/games, /how, /get...), which
  // the server answers with the page's title and links for search engines; here they're the same page.
  const PATHS = ['/games', '/emulators', '/apps', '/tools', '/how', '/get', '/about', '/contact', '/submit', '/report', '/ids', '/soon', '/link', '/my-ps5', '/press', '/beta', '/developers', '/hub', '/dmca', '/pc', '/pc/all', '/pc/how', '/pc/top', '/pc/new', '/pc/consoles', '/pc/launcher', '/pc/settings', '/pc/action', '/pc/adventure', '/pc/platformers', '/pc/racing', '/pc/fighting', '/pc/shooters', '/pc/sports', '/pc/rpg', '/pc/puzzle'];
  const pathNow = () => location.pathname.replace(/(.)\/+$/, '$1');
  const pathPage = () => !(location.hash.length > 1) && (PATHS.includes(pathNow()) || /^\/dev\/[a-z0-9-]{1,40}$/.test(pathNow()) || /^\/pc\/game\/[a-z0-9-]{1,64}$/.test(pathNow()));
  function current() {
    if (LAUNCHER) { const h = pcIn(pathNow()); return PC_PATH_RE.test(h) ? h : '/pc'; }
    if (location.hash.length > 1) return location.hash.slice(1);
    if (pathPage()) return pathNow();
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
  // (2.6) The tab's title, the same as the server's (page.php, share.php) for each page.
  const PAGE_TITLES = {
    '/games': N_('PS5 homebrew games'), '/emulators': N_('PS5 emulators'), '/apps': N_('PS5 homebrew apps'), '/tools': N_('PS5 homebrew tools'),
    '/how': N_('How it works'), '/get': N_('How to install RecompStore on a jailbroken PS5'), '/about': N_('About'), '/contact': N_('Contact'), '/dmca': 'Copyright & DMCA', '/submit': N_('Submit to RecompStore'),
    '/report': N_('Report a bug'), '/ids': N_('Title IDs'), '/press': N_('Press kit'), '/beta': N_('Betas'), '/developers': N_('For developers'), '/hub': N_('Developer hub'), '/soon': N_('Coming soon'), '/link': N_('Link your PS5'), '/my-ps5': N_('My PS5'),
  };
  // (2.7.1) An app's title says what it is on PS5, as the server's does (share.php), so search
  // engines that run this script see the same title.
  const APP_HEADS = { games: N_('{name} — PS5 homebrew game'), emulators: N_('{name} — PS5 emulator'), apps: N_('{name} — PS5 homebrew app'), tools: N_('{name} — PS5 homebrew tool') };
  function pageTitle(h, here, m, ms, dv) {
    const app = m ? byId(decodeURIComponent(m[1])) : null;
    if (app && app.name) return `${tf(APP_HEADS[app.category] || APP_HEADS.games, { name: app.name })} · RecompStore`;
    const soon = ms ? soonById(decodeURIComponent(ms[1])) : null;
    if (soon && soon.name) return `${tf('{name} — coming soon to PS5', { name: soon.name })} · RecompStore`;
    const name = dv ? (devBySlug(dv[1]) || {}).name : here === 'home' ? '' : PAGE_TITLES[h] && view.querySelector('h1') ? t(PAGE_TITLES[h]) : '';
    if (name) return `${name} · RecompStore`;
    return here === 'home' ? t('RecompStore — PS5 homebrew and jailbreak app store') : 'RecompStore';
  }
  // (2.6) Where you were on each page: how far down, and how far along each row. Kept with the page's
  // entry in the browser's history, so Back brings you to the same spot.
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  const rowSpots = () => [...view.querySelectorAll('.rowscroll')].map((r) => Math.round(r.scrollLeft));
  function saveSpot() {
    try { history.replaceState(Object.assign({}, history.state, { y: Math.round(window.scrollY), rows: rowSpots() }), ''); } catch (e) { /* only a nicety */ }
  }
  let spotTimer = 0;
  const spotLater = () => { clearTimeout(spotTimer); spotTimer = setTimeout(saveSpot, 180); };
  window.addEventListener('scroll', spotLater, { passive: true });
  view.addEventListener('scroll', spotLater, { passive: true, capture: true });
  let drawn = false;
  // quiet: the same page again, in another language (not counted, not scrolled). spot: where to
  // put the page back to (Back and Forward).
  function route(quiet, spot) {
    clearInterval(slideTimer);
    stopPageTimers();
    document.querySelectorAll('dialog.lightbox[open]').forEach((d) => d.close());
    closeSearch();
    closeDrawer();
    let h = current();
    // A Coming soon game that's out now (or the other way round): its old link opens its page.
    const kind = h.match(/^\/(game|soon)\/(.+)$/);
    if (kind) {
      const id = decodeURIComponent(kind[2]);
      if (kind[1] === 'soon' && !soonById(id) && byId(id)) h = '/game/' + kind[2];
      else if (kind[1] === 'game' && !byId(id) && soonById(id)) h = '/soon/' + kind[2];
    }
    if (!quiet) countVisit(h);
    const m = h.match(/^\/game\/(.+)$/);
    const ms = h.match(/^\/soon\/(.+)$/);
    const own = m ? byId(decodeURIComponent(m[1])) && `/game/${m[1]}` : ms ? soonById(decodeURIComponent(ms[1])) && `/soon/${ms[1]}` : '';
    const coded = h.match(/^\/(l|r)\/([A-Za-z0-9-]{4,12})$/);
    const dv = h.match(/^\/dev\/([a-z0-9-]{1,40})$/); // (2.6) a developer's page
    const pcm = PC_PATH_RE.test(h); // (2.8) the PC side: RecompLauncher
    setPlatform(pcm ? 'pc' : 'ps5');
    // The address bar gets the page's own address: /game/<ID>, /games, /how, /dev/<name>...; the rest
    // stay #/... on the home page's address (and ?lang= stays).
    const v2only = ['/games', '/emulators', '/apps', '/tools', '/ids'].includes(h) && !v2on(); // shown as the home page
    const clean = own || (dv || pcm || (PATHS.includes(h) && !v2only) ? h : h === '/' || v2only ? '/' : '');
    const shown = LAUNCHER && clean ? pcOut(clean) : clean;
    if (shown && location.pathname + location.hash !== shown) history.replaceState(history.state, '', shown + location.search);
    else if (!clean && !coded && location.pathname !== '/') history.replaceState(history.state, '', '/' + location.search + location.hash);
    routed = location.href;
    const isCat = ['/games', '/emulators', '/apps', '/tools'].includes(h) && v2on();
    const draw = () => {
    if (pcm) pcRoute(h);
    else if (m) game(decodeURIComponent(m[1]));
    else if (ms) soonPage(decodeURIComponent(ms[1]));
    else if (h === '/soon') soonListPage();
    else if (h === '/get') getPage();
    else if (h === '/how') howPage();
    else if (h === '/about') about();
    else if (h === '/report') formPage('bug');
    else if (h === '/submit') submitPage();
    else if (h === '/contact') contactPage();
    else if (h === '/dmca') dmcaPage();
    else if (h === '/press') pressPage();
    else if (h === '/beta') betaPage();
    else if (h === '/developers') developersPage();
    else if (h === '/hub') hubPage();
    else if (h === '/ids' && v2on()) idsPage();
    else if (coded && coded[1] === 'l') linkClaimPage(coded[2]);
    else if (coded) reportPage(coded[2]);
    else if (h === '/link') linkPage();
    else if (isCat) categoryPage(h.slice(1));
    else if (h === '/my-ps5') myPage();
    else if (dv) devPage(dv[1]);
    else home();
    // Which part of the menu this page is in.
    const here = pcm ? (h === '/pc' ? 'pc' : h === '/pc/how' ? 'pc-how' : (h === '/pc/all' || PC_CAT_RE.test(h)) ? 'pc-all' : ['/pc/top', '/pc/new', '/pc/consoles', '/pc/soon'].includes(h) ? 'pc-' + h.slice(4) : '') : isCat ? h.slice(1) : h === '/how' ? 'how' : ['/link', '/my-ps5'].includes(h) || (coded && coded[1] === 'l') ? 'my'
      : view.querySelector('.intro-words') ? 'home' : '';
    document.querySelectorAll('[data-nav]').forEach((n) => { if (n.dataset.nav === here) n.setAttribute('aria-current', 'page'); else n.removeAttribute('aria-current'); });
    document.querySelectorAll('a.get').forEach((n) => n.classList.toggle('on', h === '/get'));
    document.querySelectorAll('a.submit-btn').forEach((n) => n.classList.toggle('on', h === '/submit'));
    if (pcm) document.title = pcTitle || 'RecompLauncher';
    else document.title = pageTitle(h, here, m, ms, dv);
    enhanceRows(view);
    if (spot && typeof spot.y === 'number') {
      const rows = view.querySelectorAll('.rowscroll');
      (spot.rows || []).forEach((x, i) => { if (rows[i] && x) rows[i].scrollLeft = x; });
      window.scrollTo({ top: spot.y, behavior: 'instant' });
    } else if (!quiet) window.scrollTo({ top: 0, behavior: 'instant' });
    watchSections();
    };
    // The page changes with a quick crossfade where the browser can (the header stays put); else a
    // short slide in.
    const smooth = drawn && !quiet && !reduced && typeof document.startViewTransition === 'function';
    drawn = true;
    if (smooth) { try { document.startViewTransition(draw); return; } catch (e) { /* draw it plainly */ } }
    draw();
    if (!quiet) { view.classList.remove('enter'); void view.offsetWidth; view.classList.add('enter'); }
  }

  // (2.6) Links to the site's own pages (/games, /game/<ID>...) open here, without loading the page again.
  const ownPath = (p) => LAUNCHER ? PC_PATH_RE.test(pcIn(p)) : p === '/' || PATHS.includes(p) || /^\/(game|soon)\/[A-Za-z0-9_-]+$/.test(p) || /^\/dev\/[a-z0-9-]{1,40}$/.test(p) || /^\/pc\/game\/[a-z0-9-]{1,64}$/.test(p);
  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest('a[href]');
    if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download')) return;
    const url = new URL(a.getAttribute('href'), location.href);
    const p = url.pathname.replace(/(.)\/+$/, '$1');
    if (url.origin !== location.origin || url.hash || !ownPath(p) || !cat) return;
    e.preventDefault();
    saveSpot();
    const q = new URLSearchParams(url.search);
    const keepLang = new URLSearchParams(location.search).get('lang');
    if (!url.search && keepLang) q.set('lang', keepLang);
    const target = p + (q.toString() ? '?' + q : '');
    if (target !== location.pathname + location.search || location.hash) history.pushState(null, '', target);
    route();
  });
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-copy]');
    if (!b) return;
    const el = document.querySelector(b.dataset.copy);
    navigator.clipboard && navigator.clipboard.writeText(el.textContent.trim()).then(() => { b.textContent = t('Copied'); setTimeout(() => (b.textContent = t('Copy')), 1500); });
  });

  // (2.0) At most a moment for the charts and this browser's link: the page doesn't wait longer.
  const atMost = (p, ms) => Promise.race([p, new Promise((ok) => setTimeout(() => ok(null), ms))]);
  const chartsLoad = atMost(fetch('/api/charts.php').then((r) => (r.ok ? r.json() : null)).catch(() => null), 2500);
  const meLoad = atMost(refreshMe(), 2000);
  // Only the admin's own browser gets a yes here (its login cookie only goes to /admin).
  const adminLoad = atMost(fetch('/admin/me.php', { cache: 'no-store', credentials: 'same-origin' }).then((r) => (r.ok ? r.json() : null)).catch(() => null), 2000);
  // (2.7.2) Developers, kept up to date between catalogs (their pages, Support buttons).
  const devsLoad = atMost(fetch('/developers.json', { cache: 'no-cache' }).then((r) => (r.ok ? r.json() : null)).catch(() => null), 1500);
  // (2.9) A developer who's signed in gets their hub one tap away, in the header and the menu. Only
  // browsers that have signed in before ask (a note in this browser), so everyone else skips the call.
  function devHubButton(acc) {
    document.querySelectorAll('.hub-btn').forEach((b) => {
      b.hidden = !acc;
      const old = b.querySelector('img.hub-av');
      const ic = b.querySelector('svg');
    if (acc && acc.avatar && !old && ic) {
      // The picture stands in for the icon; if it can't load, the icon comes back.
      ic.insertAdjacentHTML('beforebegin', `<img class="hub-av" src="${esc(acc.avatar)}" alt="" width="26" height="26">`);
      ic.style.display = 'none';
      b.querySelector('img.hub-av').addEventListener('error', (e) => { e.target.remove(); ic.style.display = ''; }, { once: true });
    }
      if (acc) b.title = acc.name || acc.login || '';
    });
    try { if (acc) localStorage.setItem('rs-dev', '1'); else localStorage.removeItem('rs-dev'); } catch (e) { /* only a shortcut */ }
    if (typeof fitHeader === 'function') requestAnimationFrame(fitHeader);
  }
  let devHint = false;
  try { devHint = localStorage.getItem('rs-dev') === '1'; } catch (e) { /* no note: no button */ }
  if (devHint && !LAUNCHER) fetch('/api/dev.php?a=me', { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null).then((j) => devHubButton(j && j.account ? j.account : null));
  // (2.8) The PC store's header from the start on its pages, and its catalog on the way.
  if (LAUNCHER || /^\/pc(\/|$)/.test(location.pathname)) setPlatform('pc');
  fetch('/catalog.json', { cache: 'no-cache' })
    .then((r) => { if (!r.ok) throw new Error(r.status); return r.json(); })
    .then((c) => Promise.all([c, chartsLoad, meLoad, adminLoad, langLoad, devsLoad]))
    .then(([c, ch, , ad, , dv]) => {
      if (dv && Array.isArray(dv.developers)) {
        c.developers = dv.developers;
        const owner = {};
        dv.developers.forEach((d) => (d.apps || []).forEach((id) => { owner[id] = d.slug; }));
        ['apps', 'newerStoreApps', 'v2Apps'].forEach((k) => (c[k] || []).forEach((a) => { if (owner[a.id]) a.dev = owner[a.id]; }));
      }
      charts = ch && ch.ok ? ch : null;
      siteV2 = !!(c.site && c.site.v2 === true);
      admin = !!(ad && ad.admin === true);
      // Games that need RecompStore 1.0.3 or newer are listed apart (older stores don't read them),
      // and (2.0) what only 2.0 lists, apart again.
      raw = c;
      cat = Object.assign({}, c);
      setLists();
      if (admin && !siteV2) {
        const rib = document.createElement('a');
        rib.className = 'preview-ribbon';
        rib.href = '/admin/settings.php#site-v2';
        rib.innerHTML = '<strong>Preview</strong> Only you see 2.0';
        rib.title = 'Visitors see the site without 2.0. Change it in the admin\'s Settings.';
        document.body.appendChild(rib);
      }
      // The parts of the page around the view that the catalog fills in; again after a language change.
      const around = () => setLists(); // the menu and footer, in the new language too
      around();
      relang = () => { around(); route(true); };
      // A link or Back can bring both events for one move: the page is drawn once.
      const moved = () => { if (location.href !== routed) route(false, history.state); };
      window.addEventListener('hashchange', moved);
      window.addEventListener('popstate', moved);
      route(false, history.state); // a reload comes back to the same spot
    })
    .catch(() => { view.innerHTML = `<section class="intro"><h1>${t('The catalog didn\'t load.')}</h1><p>${t('Refresh the page in a moment.')}</p></section>`; });
})();

/* (3.0) The 2.0 design loaded: the page keeps it. */
window.RS_NEW_OK = true;
