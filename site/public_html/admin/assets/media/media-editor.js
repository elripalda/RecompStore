'use strict';
// The website admin's media editor: a game's cartridge or disc, live, with everything that goes on
// its label. Needs media-art.js + media-art.css (MediaArt), media-auto.js (MediaAuto) and media-editor.css.
//
//   const ed = MediaEditor.mount(el, {
//     console: 'n64',                        // the listing's console (origin)
//     title: 'Ship of Harkinian',            // shown in the header
//     art: { cover, hero, logo },            // the listing's own images (URLs on this site)
//     design: {...} | null,                  // the saved design, if any
//     api: {
//       upload(file, kind) -> Promise<url>,  // kind: 'label' | 'scan'; stores it with the game
//       steamgrid(shape) -> Promise<[{ url, thumb, w, h, author }]>   // shape: 'tall' | 'square' | 'wide'
//       save(design | null) -> Promise       // null clears it (back to automatic)
//     },
//   });
//   ed.design()   the design as it stands; ed.destroy()
//
// MediaEditor.autoAll(games, save, progress) fills designs for many games at once:
//   games: [{ id, console, art, design }]; save(id, design) -> Promise; progress(done, total, id).
//   Games that already have a design are left alone unless { redo: true } is passed as the 4th argument.

const MediaEditor = (() => {
  const h = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const round4 = (v) => Math.round(v * 1e4) / 1e4;

  function mount(el, opt) {
    const kind0 = MediaArt.kindOf(opt.console);
    const art = Object.assign({}, opt.art || {});
    let d = JSON.parse(JSON.stringify(opt.design || {}));
    let saved = JSON.stringify(opt.design || null);
    let look = 'dark', state = '';
    let dims = {};
    const kind = () => (d.kind && MediaArt.known(d.kind) ? d.kind : kind0);

    el.classList.add('mde');
    el.innerHTML = `
      <div class="mde-stage">
        <div class="mde-top"><b>${h(opt.title || 'Media')}</b><span class="mde-dirty" hidden>Unsaved</span>
          <div class="mde-seg" data-g="look"><button data-v="dark" class="on">Dark</button><button data-v="oled">OLED</button><button data-v="light">Light</button></div>
          <div class="mde-seg" data-g="state"><button data-v="" class="on">Still</button><button data-v="is-busy">Working</button><button data-v="is-in">Arrive</button></div></div>
        <div class="mde-view"><div class="mde-art"></div><svg class="mde-over" viewBox="0 0 600 420"></svg></div>
        <p class="mde-hint">Drag the picture to move it. Scroll to zoom. Drag the logo to move it, and its corner to resize it.</p>
      </div>
      <div class="mde-side">
        <label class="mde-f"><span>Media</span><select data-k="kind"><option value="">${h(MediaArt.KINDS.find((k) => k.id === kind0)?.name || 'Disc')} (console default)</option>${MediaArt.KINDS.map((k) => `<option value="${k.id}">${h(k.name)}</option>`).join('')}</select></label>
        <div class="mde-f"><span>Label art</span><div class="mde-seg" data-g="art"><button data-v="cover">Cover</button><button data-v="hero">Backdrop</button><button data-v="url">Image</button><button data-v="scan">Scan</button></div></div>
        <div class="mde-f mde-src" hidden><span>Image</span><div class="mde-row"><button class="mde-b" data-act="upload">Upload</button><button class="mde-b" data-act="sgdb">SteamGridDB</button><input type="file" accept="image/png,image/jpeg,image/webp" hidden></div><input class="mde-url" placeholder="or paste an image link" spellcheck="false"></div>
        <div class="mde-f mde-stylef"><span>Style</span><div class="mde-seg" data-g="style"><button data-v="image">Image</button><button data-v="blur">Blur</button><button data-v="color">Color</button></div></div>
        <div class="mde-f mde-colorf"><span>Color</span><div class="mde-row"><input type="color" data-k="color"><button class="mde-b" data-act="autocolor">Auto</button><span class="mde-sw"></span></div></div>
        <div class="mde-f mde-cropf"><span>Picture</span>
          <label class="mde-sl">Zoom<input type="range" min="1" max="4" step=".01" data-k="crop.z"></label>
          <label class="mde-sl">Across<input type="range" min="0" max="1" step=".001" data-k="crop.x"></label>
          <label class="mde-sl">Down<input type="range" min="0" max="1" step=".001" data-k="crop.y"></label>
          <button class="mde-b mde-sm" data-act="autocrop">Auto Fit</button></div>
        <div class="mde-f mde-logof"><span>Logo</span><label class="mde-tg"><input type="checkbox" data-k="logo.on"> Show the logo</label>
          <label class="mde-sl">Size<input type="range" min=".1" max="1" step=".001" data-k="logo.s"></label>
          <label class="mde-sl">Across<input type="range" min="0" max="1" step=".001" data-k="logo.x"></label>
          <label class="mde-sl">Down<input type="range" min="0" max="1" step=".001" data-k="logo.y"></label>
          <button class="mde-b mde-sm" data-act="autologo">Auto Place</button></div>
        <div class="mde-foot"><button class="mde-b" data-act="auto">Auto Everything</button><button class="mde-b" data-act="reset">Back to Automatic</button><button class="mde-b mde-main" data-act="save">Save</button></div>
        <p class="mde-msg" role="status"></p>
      </div>
      <div class="mde-pick" hidden><div class="mde-pick-in"><div class="mde-top"><b>SteamGridDB</b><div class="mde-seg" data-g="shape"><button data-v="tall" class="on">Tall</button><button data-v="square">Square</button><button data-v="wide">Wide</button></div><button class="mde-b" data-act="pickclose">Close</button></div><div class="mde-grid"></div></div></div>`;
    const $ = (s) => el.querySelector(s);
    const $$ = (s) => [...el.querySelectorAll(s)];
    const msg = (t, bad) => { const m = $('.mde-msg'); m.textContent = t || ''; m.classList.toggle('bad', !!bad); };

    // ---- the image sizes the crop needs ----
    async function dimsOf(src) {
      if (!src) return null;
      if (dims[src]) return dims[src];
      const im = await MediaAuto.load(src).catch(() => null);
      return (dims[src] = im ? [im.naturalWidth, im.naturalHeight] : null);
    }
    const srcNow = () => (d.art === 'url' || d.art === 'scan' ? d.src : d.art === 'hero' ? (art.hero || art.cover) : (art.cover || art.hero));
    async function sizeCrop() {
      const wh = await dimsOf(srcNow());
      d.crop = Object.assign({ x: .5, y: .5, z: 1 }, d.crop || {});
      if (wh) { d.crop.w = wh[0]; d.crop.h = wh[1]; }
    }
    async function logoAspect() {
      if (!art.logo) return;
      const wh = await dimsOf(art.logo);
      if (wh) d.logo = Object.assign({}, d.logo || {}, { a: round4(wh[0] / wh[1]) });
    }

    // ---- drawing ----
    function full() { return MediaArt.designFor(kind(), { design: d }); }
    function draw() {
      const k = kind(), g = MediaArt.geometry(k), f = full();
      $('.mde-art').innerHTML = MediaArt.render(k, { cover: art.cover, hero: art.hero, logo: art.logo, design: Object.assign({}, d, { kind: undefined }) });
      const md = $('.mde-art .md');
      if (state) md.classList.add(state);
      el.dataset.look = look;
      // The handles: the label's outline, and the logo's box.
      const box = f.art === 'scan' ? g.scanBox : g.box;
      let o = `<path d="${g.outline}" class="mde-lab" fill-rule="evenodd"/>`;
      if (art.logo && f.logo.on && f.art !== 'scan') {
        const lb = logoRect(box, f.logo);
        o += `<rect class="mde-logo" x="${lb[0]}" y="${lb[1]}" width="${lb[2]}" height="${lb[3]}"/><rect class="mde-hand" x="${lb[0] + lb[2] - 7}" y="${lb[1] + lb[3] - 7}" width="14" height="14" rx="3"/>`;
      }
      $('.mde-over').innerHTML = o;
      // The controls.
      $('select[data-k="kind"]').value = d.kind || '';
      seg('art', f.art); seg('style', f.style);
      $('.mde-src').hidden = !(f.art === 'url' || f.art === 'scan');
      $('.mde-url').value = d.src || '';
      $('.mde-stylef').hidden = f.art === 'scan';
      $('.mde-colorf').hidden = !(f.style === 'color' || g.round);
      $('input[data-k="color"]').value = /^#[0-9a-f]{6}$/i.test(d.color || '') ? d.color : '#2c3c5c';
      $('.mde-cropf').hidden = f.style === 'color' && f.art !== 'scan';
      const c = Object.assign({ x: .5, y: .5, z: 1 }, d.crop || {});
      $('input[data-k="crop.z"]').value = c.z; $('input[data-k="crop.x"]').value = c.x; $('input[data-k="crop.y"]').value = c.y;
      $('.mde-logof').hidden = !art.logo || f.art === 'scan';
      $('input[data-k="logo.on"]').checked = !!f.logo.on;
      $('input[data-k="logo.s"]').value = f.logo.s; $('input[data-k="logo.x"]').value = f.logo.x; $('input[data-k="logo.y"]').value = f.logo.y;
      $('.mde-dirty').hidden = JSON.stringify(clean()) === saved;
    }
    function logoRect([X, Y, W, H], lg) {
      const a = lg.a > 0 ? lg.a : 2;
      let w = W * lg.s, hh = w / a;
      if (hh > H * .95) { hh = H * .95; w = hh * a; }
      return [X + W * lg.x - w / 2, Y + H * lg.y - hh / 2, w, hh];
    }
    function seg(g, v) { $$(`.mde-side [data-g="${g}"] button`).forEach((b) => b.classList.toggle('on', b.dataset.v === v)); }
    // The design as saved: only what was chosen (defaults stay automatic).
    function clean() {
      const o = JSON.parse(JSON.stringify(d));
      if (!o.kind) delete o.kind;
      if (o.src === '') delete o.src;
      o.v = 1;
      return o;
    }

    // ---- controls ----
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('button');
      if (!b || !el.contains(b)) return;
      const g = b.parentElement.dataset.g;
      if (g === 'look') { look = b.dataset.v; $$('[data-g="look"] button').forEach((x) => x.classList.toggle('on', x === b)); return draw(); }
      if (g === 'state') { state = b.dataset.v; $$('[data-g="state"] button').forEach((x) => x.classList.toggle('on', x === b)); return draw(); }
      if (g === 'shape') { $$('[data-g="shape"] button').forEach((x) => x.classList.toggle('on', x === b)); return loadGrid(b.dataset.v); }
      if (g === 'art') { d.art = b.dataset.v; d.crop = undefined; await sizeCrop(); await autoCrop(); return draw(); }
      if (g === 'style') { d.style = b.dataset.v; return draw(); }
      const act = b.dataset.act;
      if (act === 'upload') return $('.mde-src input[type=file]').click();
      if (act === 'sgdb') { $('.mde-pick').hidden = false; return loadGrid($('[data-g="shape"] .on').dataset.v); }
      if (act === 'pickclose') { $('.mde-pick').hidden = true; return; }
      if (act === 'autocolor') { busy(true); try { const a = await MediaAuto.design(kind(), art, { art: d.art, src: d.src }); d.color = a.color; } finally { busy(false); } return draw(); }
      if (act === 'autocrop') { await sizeCrop(); await autoCrop(); return draw(); }
      if (act === 'autologo') { await logoAspect(); const a = await MediaAuto.design(kind(), art, { art: d.art, src: d.src, style: d.style }); d.logo = Object.assign({}, a.logo, { on: d.logo?.on ?? a.logo?.on }); return draw(); }
      if (act === 'auto') { busy(true); try { d = Object.assign(await MediaAuto.design(kind(), art), d.kind ? { kind: d.kind } : {}); } finally { busy(false); } return draw(); }
      if (act === 'reset') { d = {}; return draw(); }
      if (act === 'save') {
        busy(true);
        try { const out = Object.keys(d).length ? clean() : null; await opt.api.save(out); saved = JSON.stringify(out); msg('Saved. Players get it with the next catalog refresh.'); }
        catch (err) { msg('Couldn\'t save: ' + (err.message || err), true); }
        finally { busy(false); }
        return draw();
      }
      const pick = b.closest('.mde-grid') && b.dataset.url;
      if (pick) {
        $('.mde-pick').hidden = true;
        d.art = d.art === 'scan' ? 'scan' : 'url'; d.src = b.dataset.url; d.style = 'image'; d.crop = undefined;
        await sizeCrop(); await autoCrop(); return draw();
      }
    });
    async function autoCrop() {
      const a = await MediaAuto.design(kind(), art, { art: d.art, src: d.src }).catch(() => null);
      if (a && a.crop) d.crop = a.crop;
    }
    function busy(on) { el.classList.toggle('mde-busy', on); }
    el.addEventListener('input', (e) => {
      const k = e.target.dataset.k;
      if (!k) return;
      if (k === 'kind') { d.kind = e.target.value || undefined; return draw(); }
      if (k === 'color') { d.color = e.target.value; d.style = d.style || 'color'; return draw(); }
      const [a, b] = k.split('.');
      if (a === 'logo') d.logo = Object.assign({}, full().logo, d.logo || {});
      if (a === 'crop') d.crop = Object.assign({ x: .5, y: .5, z: 1 }, d.crop || {});
      d[a][b] = e.target.type === 'checkbox' ? e.target.checked : round4(+e.target.value);
      draw();
    });
    $('.mde-url').addEventListener('change', async (e) => {
      const v = e.target.value.trim();
      if (!v) return;
      d.src = v; if (d.art !== 'scan') d.art = 'url'; d.crop = undefined;
      await sizeCrop(); await autoCrop(); draw();
    });
    $('.mde-src input[type=file]').addEventListener('change', async (e) => {
      const f = e.target.files[0]; e.target.value = '';
      if (!f) return;
      busy(true); msg('Uploading…');
      try {
        d.src = await opt.api.upload(f, d.art === 'scan' ? 'scan' : 'label');
        if (d.art !== 'scan') d.art = 'url';
        d.style = 'image'; d.crop = undefined;
        await sizeCrop(); await autoCrop(); msg('');
      } catch (err) { msg('Upload failed: ' + (err.message || err), true); }
      finally { busy(false); }
      draw();
    });
    async function loadGrid(shape) {
      const grid = $('.mde-grid');
      grid.innerHTML = '<p class="mde-msg">Loading…</p>';
      try {
        const list = await opt.api.steamgrid(shape);
        grid.dataset.shape = shape;
        grid.innerHTML = list.length ? list.map((x) => `<button type="button" data-url="${h(x.url)}" title="${h(x.author || '')}"><img src="${h(x.thumb || x.url)}" loading="lazy" alt=""></button>`).join('') : '<p class="mde-msg">Nothing in this shape yet.</p>';
      } catch (err) { grid.innerHTML = `<p class="mde-msg bad">${h(err.message || err)}</p>`; }
    }

    // ---- dragging on the preview ----
    const over = $('.mde-over');
    let drag = null;
    const pt = (e) => { const r = over.getBoundingClientRect(); return [(e.clientX - r.left) * 600 / r.width, (e.clientY - r.top) * 420 / r.height]; };
    over.addEventListener('pointerdown', async (e) => {
      const f = full(), g = MediaArt.geometry(kind()), box = f.art === 'scan' ? g.scanBox : g.box;
      const [x, y] = pt(e);
      const t = e.target.classList;
      if (t.contains('mde-hand')) drag = { m: 'size', x, y, lg: Object.assign({}, f.logo), box };
      else if (t.contains('mde-logo')) drag = { m: 'logo', x, y, lg: Object.assign({}, f.logo), box };
      else if (f.style !== 'color' || f.art === 'scan') {
        await sizeCrop();
        const c = d.crop, [, , W, H] = box;
        const sc = c.w ? Math.max(W / c.w, H / c.h) * c.z : c.z;
        drag = { m: 'pan', x, y, c: Object.assign({}, c), ow: c.w ? c.w * sc - W : W * (c.z - 1), oh: c.h ? c.h * sc - H : H * (c.z - 1) };
      }
      if (drag) { over.setPointerCapture(e.pointerId); e.preventDefault(); }
    });
    over.addEventListener('pointermove', (e) => {
      if (!drag) return;
      const [x, y] = pt(e), dx = x - drag.x, dy = y - drag.y, [, , W, H] = drag.box || [0, 0, 1, 1];
      if (drag.m === 'pan') {
        d.crop = Object.assign({}, drag.c, { x: round4(drag.ow > 0 ? clamp(drag.c.x - dx / drag.ow, 0, 1) : .5), y: round4(drag.oh > 0 ? clamp(drag.c.y - dy / drag.oh, 0, 1) : .5) });
      } else if (drag.m === 'logo') {
        d.logo = Object.assign({}, drag.lg, { x: round4(clamp(drag.lg.x + dx / W, 0, 1)), y: round4(clamp(drag.lg.y + dy / H, 0, 1)) });
      } else if (drag.m === 'size') {
        d.logo = Object.assign({}, drag.lg, { s: round4(clamp(drag.lg.s + (2 * dx) / W, .08, 1.2)) });
      }
      draw();
    });
    const end = () => { drag = null; };
    over.addEventListener('pointerup', end); over.addEventListener('pointercancel', end);
    over.addEventListener('wheel', async (e) => {
      const f = full();
      if (f.style === 'color' && f.art !== 'scan') return;
      e.preventDefault();
      await sizeCrop();
      d.crop.z = round4(clamp(d.crop.z * Math.pow(1.0015, -e.deltaY), 1, 4));
      draw();
    }, { passive: false });

    // ---- start ----
    (async () => {
      await logoAspect();
      if (!opt.design) { busy(true); try { d = await MediaAuto.design(kind0, art); } catch { d = {}; } finally { busy(false); } msg('Automatic design. Change anything, then Save.'); }
      else await sizeCrop();
      draw();
    })();
    draw();
    return { design: () => (Object.keys(d).length ? clean() : null), destroy: () => { el.innerHTML = ''; el.classList.remove('mde'); } };
  }

  async function autoAll(games, save, progress, o = {}) {
    let n = 0;
    for (const g of games) {
      n++;
      if (g.design && !o.redo) { progress && progress(n, games.length, g.id, 'kept'); continue; }
      try {
        const d = await MediaAuto.design(MediaArt.kindOf(g.console), g.art || {});
        await save(g.id, d);
        progress && progress(n, games.length, g.id, 'done');
      } catch (e) { progress && progress(n, games.length, g.id, 'failed: ' + (e.message || e)); }
    }
  }

  return { mount, autoAll };
})();
if (typeof module !== 'undefined') module.exports = MediaEditor;
