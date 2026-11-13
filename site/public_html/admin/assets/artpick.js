// RecompStore admin (3.4): the art picker. Each art slot (cover, backdrop, logo, icon) on the PC and
// PS5 editors gets a "Find art" button (and on the PC page, clicking the picture opens it too). It
// searches the game across SteamGridDB, IGDB, TheGamesDB, Libretro and Steam, shows every picture
// with its size, and lets you frame the one you pick. The framed picture goes into the slot's own
// file box, so Save keeps it exactly as an upload would.
(function () {
  'use strict';
  const API = 'art-api.php';
  // (4.9) The Submissions drawer opens it too: window.rsArtPick.open(kind, form) for a form that
  // arrives after the page has loaded.
  let form = document.querySelector('form:not([data-panel]) input[name="icon_file"], form:not([data-panel]) input[name="hero_file"], form:not([data-panel]) input[name="cover_file"]')?.form || null;
  const pageForm = form;
  if (!form && !document.querySelector('[data-artpick-page]')) return;
  let PC = !form || !!form.querySelector('input[name="cover_file"]');
  let SLOTS = {};
  const makeSlots = () => ({
    cover: { name: 'Cover', input: 'cover_file', ratio: 2 / 3, out: [600, 900], fmt: 'image/jpeg' },
    hero: { name: 'Backdrop', input: 'hero_file', ratio: PC ? 0 : 16 / 9, out: [3840, 0], fmt: 'image/jpeg', choices: PC ? [['Whole picture', 0], ['16:9', 16 / 9], ['Wide banner', 1920 / 620]] : [['16:9', 16 / 9], ['Whole picture', 0]] },
    logo: { name: 'Logo', input: 'logo_file', ratio: 0, out: [1600, 800], fmt: 'image/png', trim: true },
    icon: { name: 'Icon', input: 'icon_file', ratio: 1, out: [512, 512], fmt: 'image/png' },
  });
  SLOTS = makeSlots();
  let kinds = form ? Object.keys(SLOTS).filter((k) => form.querySelector(`input[name="${SLOTS[k].input}"]`)) : [];
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = { get: (k) => { try { return localStorage.getItem('rs-art-' + k); } catch (e) { return null; } }, set: (k, v) => { try { localStorage.setItem('rs-art-' + k, v); } catch (e) { /* fine */ } } };
  const getJSON = (q) => fetch(API + '?' + new URLSearchParams(q), { credentials: 'same-origin' }).then((r) => r.json()).catch(() => ({ ok: false, error: 'The admin didn\'t answer. Try again.' }));

  let sources = null;
  const sourcesReady = getJSON({ a: 'sources' }).then((j) => { sources = j.ok ? j : { sources: {} }; return sources; });

  // ---- Buttons on the page ----------------------------------------------------------------------
  if (pageForm) kinds.forEach((k) => {
    const input = form.querySelector(`input[name="${SLOTS[k].input}"]`);
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'ap-open';
    b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/></svg>Find art';
    b.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); open(k); });
    input.insertAdjacentElement('afterend', b);
    const box = input.closest('label')?.querySelector('.pc-art-box');
    if (box) {
      box.classList.add('ap-clickable');
      box.title = 'Find art for the ' + SLOTS[k].name.toLowerCase();
      box.addEventListener('click', (e) => { e.preventDefault(); open(k); });
    }
  });

  const gameName = () => ((form.querySelector('[name="game"]') || {}).value || '').trim() || ((form.querySelector('[name="name"]') || {}).value || '').trim();
  const origin = () => ((form.querySelector('[name="origin"]') || {}).value || '');

  // ---- The window -------------------------------------------------------------------------------
  const d = document.createElement('dialog');
  d.className = 'ap';
  d.innerHTML = `
    <div class="ap-head">
      <h2>Find art</h2>
      <div class="ap-kinds" role="tablist" data-kinds></div>
      <button type="button" class="ap-local" data-local><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M7.5 8.5 12 4l4.5 4.5M5 15v4h14v-4"/></svg>From your computer</button>
      <button type="button" class="ap-x" data-close aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    </div>
    <form class="ap-search" data-search>
      <input type="search" name="q" placeholder="The game's name" autocomplete="off" aria-label="Game name">
      <button class="primary">Search</button>
    </form>
    <div class="ap-srcs" data-srcs></div>
    <div class="ap-body" data-browse>
      <div class="ap-games"><h3>Matches</h3><ul data-games></ul></div>
      <div class="ap-pics"><div class="ap-pics-head"><h3 data-pics-title>Pictures</h3><span class="muted" data-pics-note></span></div><div class="ap-grid" data-grid></div></div>
    </div>
    <div class="ap-adjust" data-adjust hidden>
      <div class="ap-stage" data-stage><img data-big alt="" draggable="false"><div class="ap-frame" data-frame></div></div>
      <div class="ap-side">
        <dl class="ap-facts" data-facts></dl>
        <label data-aspect-wrap>Shape<select data-aspect></select></label>
        <label data-zoom-wrap>Zoom <input type="range" min="100" max="400" value="100" data-zoom></label>
        <p class="ap-warn" data-warn hidden></p>
        <p class="muted small">Drag the picture to frame it. What's inside the box is what's saved.</p>
        <div class="ap-btns"><button type="button" data-back>Back</button><button type="button" class="primary" data-use>Use this picture</button></div>
      </div>
    </div>`;
  document.body.appendChild(d);
  const $ = (s) => d.querySelector(s);
  const st = { kind: 'cover', src: '', q: '', games: [], game: null, cache: {} };

  d.addEventListener('click', (e) => { if (e.target === d) d.close(); });
  $('[data-close]').addEventListener('click', () => d.close());
  function paintKindTabs() {
    $('[data-kinds]').innerHTML = kinds.map((k) => `<button type="button" role="tab" data-kind="${k}">${SLOTS[k].name}</button>`).join('');
    d.querySelectorAll('[data-kind]').forEach((b) => b.addEventListener('click', () => { st.kind = b.dataset.kind; paintKinds(); showAdjust(false); loadPics(); }));
  }
  paintKindTabs();
  // Upload one instead: the slot's own file box, as before.
  $('[data-local]').addEventListener('click', () => {
    const input = form && form.querySelector(`input[name="${SLOTS[st.kind].input}"]`);
    d.close();
    if (input) input.click();
  });
  $('[data-search]').addEventListener('submit', (e) => { e.preventDefault(); st.q = $('[data-search]').q.value.trim(); search(); });
  $('[data-back]').addEventListener('click', () => showAdjust(false));

  function paintKinds() {
    d.querySelectorAll('[data-kind]').forEach((b) => b.setAttribute('aria-selected', String(b.dataset.kind === st.kind)));
    $('[data-pics-title]').textContent = SLOTS[st.kind].name + ' pictures';
  }

  function paintSources() {
    const S = sources.sources || {};
    $('[data-srcs]').innerHTML = Object.keys(S).map((id) => S[id].on
      ? `<button type="button" data-src="${id}" aria-pressed="${id === st.src}">${esc(S[id].name)}</button>`
      : `<a class="ap-src-off" href="settings.php#art" target="_blank" title="${esc(S[id].why)}">${esc(S[id].name)} <em>Set up</em></a>`).join('')
      + (sources.tgdbLeft != null ? `<span class="muted ap-left">TheGamesDB: ${Number(sources.tgdbLeft).toLocaleString()} searches left this month</span>` : '');
    d.querySelectorAll('[data-src]').forEach((b) => b.addEventListener('click', () => { st.src = b.dataset.src; store.set('src', st.src); paintSources(); search(); }));
  }

  async function open(kind) {
    st.kind = kind;
    paintKinds();
    showAdjust(false);
    if (!d.open) d.showModal();
    await sourcesReady;
    const on = Object.keys(sources.sources || {}).filter((id) => sources.sources[id].on);
    if (!on.length) { $('[data-games]').innerHTML = '<li class="muted">No art sources are set up.</li>'; return; }
    if (!on.includes(st.src)) st.src = on.includes(store.get('src')) ? store.get('src') : on[0];
    paintSources();
    const name = gameName();
    if (!st.games.length || st.q !== name && !st.touched) { st.q = name; $('[data-search]').q.value = name; search(); }
    else loadPics();
  }
  $('[data-search]').q.addEventListener('input', () => { st.touched = true; });

  async function search() {
    const list = $('[data-games]');
    st.games = []; st.game = null;
    $('[data-grid]').innerHTML = '';
    if (!st.q) { list.innerHTML = '<li class="muted">Type the game\'s name.</li>'; return; }
    list.innerHTML = '<li class="muted">Searching…</li>';
    const j = await getJSON({ a: 'search', src: st.src, q: st.q, origin: origin() });
    if (!j.ok) { list.innerHTML = `<li class="ap-err">${esc(j.error)}</li>`; return; }
    st.games = (j.games || []).filter((g) => g.id);
    if (!st.games.length) {
      list.innerHTML = (j.games || []).length ? `<li class="muted">${esc(j.games[0].name)}</li>` : '<li class="muted">No matches. Try a shorter or exact name.</li>';
      return;
    }
    list.innerHTML = st.games.map((g, i) => `<li><button type="button" data-g="${i}"><strong>${esc(g.name)}</strong><small>${esc([g.year, g.platform, g.note].filter(Boolean).join(' · ') || ' ')}</small></button></li>`).join('');
    list.querySelectorAll('[data-g]').forEach((b) => b.addEventListener('click', () => pickGame(+b.dataset.g)));
    pickGame(0);
  }

  function pickGame(i) {
    st.game = st.games[i];
    d.querySelectorAll('[data-g]').forEach((b) => b.setAttribute('aria-current', String(+b.dataset.g === i)));
    loadPics();
  }

  // Green: the right shape and big enough for the slot. Amber: it'll be cropped hard or look soft.
  function fit(w, h) {
    const s = SLOTS[st.kind];
    if (!w || !h) return ['', ''];
    const r = w / h, want = s.ratio || (st.kind === 'hero' ? 16 / 9 : 0);
    const shapeOk = !want || Math.abs(r - want) / want < 0.06;
    const minW = st.kind === 'hero' ? 1920 : st.kind === 'logo' ? 600 : s.out[0];
    const bigOk = w >= minW * 0.95;
    if (shapeOk && bigOk) return ['good', 'Right shape and size'];
    return ['meh', [shapeOk ? '' : 'Different shape, will be cropped', bigOk ? '' : 'Smaller than ' + minW + ' wide'].filter(Boolean).join('. ')];
  }

  async function loadPics() {
    const grid = $('[data-grid]');
    const note = $('[data-pics-note]');
    if (!st.game) { grid.innerHTML = ''; note.textContent = ''; return; }
    const key = [st.src, st.game.id, st.kind].join('|');
    grid.innerHTML = '<p class="muted">Loading…</p>';
    note.textContent = '';
    const j = st.cache[key] || await getJSON({ a: 'images', src: st.src, id: st.game.id, kind: st.kind });
    if (j.ok) st.cache[key] = j;
    if (key !== [st.src, st.game && st.game.id, st.kind].join('|')) return;
    if (!j.ok) { grid.innerHTML = `<p class="ap-err">${esc(j.error)}</p>`; return; }
    const pics = j.images || [];
    if (!pics.length) { grid.innerHTML = `<p class="muted">${esc(sources.sources[st.src].name)} has no ${SLOTS[st.kind].name.toLowerCase()} pictures for this one. Try another match or source.</p>`; return; }
    note.textContent = pics.length + (pics.length === 1 ? ' picture' : ' pictures');
    grid.classList.toggle('logos', st.kind === 'logo');
    grid.innerHTML = pics.map((p, i) => {
      const [cls, why] = fit(p.w, p.h);
      return `<button type="button" class="ap-pic ${st.kind}" data-p="${i}" title="${esc(why)}">
        <span class="ap-img"><img src="${esc(p.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer"></span>
        <span class="ap-res ${cls}" data-res>${p.w && p.h ? p.w + '×' + p.h : '…'}</span>
        <span class="ap-cap">${esc(p.label || '')}</span></button>`;
    }).join('');
    grid.querySelectorAll('[data-p]').forEach((b) => {
      const p = pics[+b.dataset.p];
      const im = b.querySelector('img');
      im.addEventListener('error', () => { b.remove(); note.textContent = grid.querySelectorAll('[data-p]').length + ' pictures'; }, { once: true });
      // Sizes the source didn't say: read from the picture once it's here (full-size ones only).
      if (!(p.w && p.h)) im.addEventListener('load', () => {
        if (p.thumb === p.url) { p.w = im.naturalWidth; p.h = im.naturalHeight; }
        const r = b.querySelector('[data-res]'); const [cls] = fit(p.w, p.h);
        r.textContent = p.w ? p.w + '×' + p.h : 'Size on pick'; r.className = 'ap-res ' + cls;
      }, { once: true });
      b.addEventListener('click', () => adjust(p));
    });
  }

  // ---- Framing ----------------------------------------------------------------------------------
  const big = $('[data-big]'), stage = $('[data-stage]'), frame = $('[data-frame]'), zoomIn = $('[data-zoom]'), aspectSel = $('[data-aspect]');
  const fr = { pic: null, w: 0, h: 0, ratio: 0, s: 1, minS: 1, x: 0, y: 0, fx: 0, fy: 0, fw: 0, fh: 0 };

  function showAdjust(on) {
    $('[data-adjust]').hidden = !on;
    $('[data-browse]').hidden = on;
    $('[data-search]').hidden = on;
    $('[data-srcs]').hidden = on;
  }

  function adjust(p) {
    const s = SLOTS[st.kind];
    fr.pic = p;
    showAdjust(true);
    $('[data-warn]').hidden = true;
    $('[data-facts]').innerHTML = '<dt>Loading the full picture…</dt>';
    $('[data-use]').disabled = true;
    const choices = s.choices || [[s.ratio ? (s.ratio === 1 ? 'Square' : '2:3') : 'Whole picture', s.ratio]];
    aspectSel.innerHTML = choices.map(([n, r]) => `<option value="${r}">${esc(n)}</option>`).join('');
    aspectSel.value = String(s.ratio);
    $('[data-aspect-wrap]').hidden = choices.length < 2;
    big.onload = () => {
      fr.w = big.naturalWidth; fr.h = big.naturalHeight;
      setRatio(+aspectSel.value);
      $('[data-use]').disabled = false;
    };
    big.onerror = () => { $('[data-facts]').innerHTML = '<dt class="ap-err">That picture couldn\'t be loaded. Pick another.</dt>'; };
    big.src = API + '?a=img&u=' + encodeURIComponent(p.url);
  }
  aspectSel.addEventListener('change', () => setRatio(+aspectSel.value));

  function setRatio(r) {
    fr.ratio = r;
    const SW = stage.clientWidth, SH = stage.clientHeight, pad = 24;
    const rr = r || fr.w / fr.h;
    let fw = SW - pad * 2, fh = fw / rr;
    if (fh > SH - pad * 2) { fh = SH - pad * 2; fw = fh * rr; }
    Object.assign(fr, { fw, fh, fx: (SW - fw) / 2, fy: (SH - fh) / 2 });
    Object.assign(frame.style, { left: fr.fx + 'px', top: fr.fy + 'px', width: fw + 'px', height: fh + 'px' });
    fr.minS = Math.max(fw / fr.w, fh / fr.h);
    fr.s = fr.minS;
    fr.x = fr.fx + (fw - fr.w * fr.s) / 2;
    fr.y = fr.fy + (fh - fr.h * fr.s) / 2;
    zoomIn.value = 100;
    $('[data-zoom-wrap]').hidden = !r;
    stage.classList.toggle('free', !r);
    paint();
  }

  function clamp() {
    fr.x = Math.min(fr.fx, Math.max(fr.fx + fr.fw - fr.w * fr.s, fr.x));
    fr.y = Math.min(fr.fy, Math.max(fr.fy + fr.fh - fr.h * fr.s, fr.y));
  }

  function crop() {
    return { sx: (fr.fx - fr.x) / fr.s, sy: (fr.fy - fr.y) / fr.s, sw: fr.fw / fr.s, sh: fr.fh / fr.s };
  }

  function outSize(c) {
    const s = SLOTS[st.kind];
    if (st.kind === 'cover' || st.kind === 'icon') return [s.out[0], s.out[1]];
    let w = c.sw, h = c.sh;
    const k = Math.min(1, s.out[0] / w, s.out[1] ? s.out[1] / h : 1);
    return [Math.round(w * k), Math.round(h * k)];
  }

  function paint() {
    clamp();
    Object.assign(big.style, { width: fr.w * fr.s + 'px', height: fr.h * fr.s + 'px', transform: `translate(${fr.x}px, ${fr.y}px)` });
    const c = crop(), [ow, oh] = outSize(c);
    const src = sources.sources[st.src].name;
    $('[data-facts]').innerHTML = `<dt>Source</dt><dd>${esc(src)}${fr.pic.label ? ' · ' + esc(fr.pic.label) : ''}</dd>
      <dt>Picture</dt><dd>${fr.w}×${fr.h}</dd><dt>Framed</dt><dd>${Math.round(c.sw)}×${Math.round(c.sh)}</dd><dt>Saved as</dt><dd>${ow}×${oh}</dd>`;
    const soft = c.sw < ow * 0.9;
    const w = $('[data-warn]');
    w.hidden = !soft;
    if (soft) w.textContent = 'The framed part is smaller than what\'s saved, so it\'ll be stretched and may look soft.';
  }

  zoomIn.addEventListener('input', () => {
    const cx = fr.fx + fr.fw / 2, cy = fr.fy + fr.fh / 2;
    const ix = (cx - fr.x) / fr.s, iy = (cy - fr.y) / fr.s;
    fr.s = fr.minS * (+zoomIn.value / 100);
    fr.x = cx - ix * fr.s; fr.y = cy - iy * fr.s;
    paint();
  });
  stage.addEventListener('wheel', (e) => {
    if (!fr.ratio) return;
    e.preventDefault();
    zoomIn.value = Math.max(100, Math.min(400, +zoomIn.value - Math.sign(e.deltaY) * 10));
    zoomIn.dispatchEvent(new Event('input'));
  }, { passive: false });
  let drag = null;
  stage.addEventListener('pointerdown', (e) => { if (!fr.ratio) return; drag = { x: e.clientX - fr.x, y: e.clientY - fr.y }; stage.setPointerCapture(e.pointerId); });
  stage.addEventListener('pointermove', (e) => { if (!drag) return; fr.x = e.clientX - drag.x; fr.y = e.clientY - drag.y; paint(); });
  ['pointerup', 'pointercancel'].forEach((ev) => stage.addEventListener(ev, () => { drag = null; }));
  window.addEventListener('resize', () => { if (d.open && !$('[data-adjust]').hidden && fr.w) setRatio(fr.ratio); });

  // A logo's empty, see-through edges are trimmed so it sits where it's placed.
  function trimmed(canvas) {
    const ctx = canvas.getContext('2d');
    const { width: w, height: h } = canvas;
    const px = ctx.getImageData(0, 0, w, h).data;
    let x0 = w, y0 = h, x1 = -1, y1 = -1;
    for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) if (px[(y * w + x) * 4 + 3] > 8) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    if (x1 < 0 || (x0 === 0 && y0 === 0 && x1 === w - 1 && y1 === h - 1)) return canvas;
    const c = document.createElement('canvas');
    c.width = x1 - x0 + 1; c.height = y1 - y0 + 1;
    c.getContext('2d').drawImage(canvas, x0, y0, c.width, c.height, 0, 0, c.width, c.height);
    return c;
  }

  $('[data-use]').addEventListener('click', async () => {
    const s = SLOTS[st.kind];
    const c = crop(), [ow, oh] = outSize(c);
    let cv = document.createElement('canvas');
    cv.width = ow; cv.height = oh;
    const ctx = cv.getContext('2d');
    ctx.imageSmoothingQuality = 'high';
    if (s.fmt === 'image/jpeg') { ctx.fillStyle = '#000'; ctx.fillRect(0, 0, ow, oh); }
    ctx.drawImage(big, c.sx, c.sy, c.sw, c.sh, 0, 0, ow, oh);
    if (s.trim) cv = trimmed(cv);
    const blob = await new Promise((res) => cv.toBlob(res, s.fmt, 0.92));
    if (!blob) return;
    const ext = s.fmt === 'image/png' ? 'png' : 'jpg';
    const file = new File([blob], `${st.kind}-${st.src}.${ext}`, { type: s.fmt });
    const input = form.querySelector(`input[name="${s.input}"]`);
    const dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    input.dispatchEvent(new Event('change', { bubbles: true }));
    const url = URL.createObjectURL(file);
    const box = input.closest('label')?.querySelector('.pc-art-box');
    if (box) box.innerHTML = `<img src="${url}" alt="">`;
    const openBtn = input.parentElement.querySelector('.ap-open');
    let tag = input.parentElement.querySelector('.ap-picked');
    if (!tag && openBtn) { tag = document.createElement('small'); tag.className = 'ap-picked'; openBtn.insertAdjacentElement('afterend', tag); }
    if (tag) tag.textContent = `Picked from ${sources.sources[st.src].name}, ${cv.width}×${cv.height}. Save to keep it.`;
    form.dispatchEvent(new CustomEvent('artpicked', { bubbles: true, detail: { kind: st.kind, src: sources.sources[st.src].name, w: cv.width, h: cv.height } }));
    d.close();
  });
  // For forms that show up later (the Submissions drawer).
  window.rsArtPick = {
    open(kind, f) {
      if (f && f !== form) {
        form = f;
        PC = !!form.querySelector('input[name="cover_file"]');
        SLOTS = makeSlots();
        kinds = Object.keys(SLOTS).filter((k) => form.querySelector(`input[name="${SLOTS[k].input}"]`));
        paintKindTabs();
        st.games = []; st.game = null; st.touched = false; st.cache = {};
      }
      if (!form || !SLOTS[kind]) return false;
      open(kind);
      return true;
    },
  };
})();
