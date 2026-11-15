'use strict';
// The website admin's Cover Labels: one label strip per console (a PNG, like the coloured band at
// the top of a real game box), laid over the top of covers in the launcher when the player turns
// on Console Labels on Covers. Saved as the catalog's top-level "labels":
//   { "<console>": { "src": "<png url>", "s": 1, "y": 0, "a": 6.67, "h": 0.1, "push": false } }
//   s: width as a share of the cover's (1 = edge to edge); y: nudge down as a share of its height;
//   a: the PNG's width / height; h: its height as a share of a 2:3 cover's (worked out here, so the
//   launcher can move its corner badges under it); push: the cover starts under the label instead
//   of lying beneath it.
// The launcher draws them exactly as coverLabel() below does.
//
//   CoverLabels.mount(el, {
//     consoles: [{ id: 'x360', name: 'Xbox 360', samples: ['cover url', ...] }],
//     labels: {...},                                  // what's saved now
//     api: { upload(file, consoleId) -> Promise<url>, save(labels) -> Promise },
//   });

const CoverLabels = (() => {
  const h = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  // The label over a cover, as the launcher draws it. Put it inside the cover's positioned box.
  const coverLabel = (l) => (l && l.src ? `<img class="cvl" src="${h(l.src)}" alt="" draggable="false" style="--cvl-s:${+l.s || 1};--cvl-y:${+l.y || 0}">` : '');
  // The cover's own class and style for a label, as the launcher does it.
  const coverClass = (l) => (l && l.src ? ` cvl-on${l.push ? ' cvl-push' : ''}" style="--cvl-h:${+l.h || .11};--cvl-top:${Math.max(0, (+l.h || .11) + (+l.y || 0))}` : '');
  const heightOf = (l) => (l.a > 0 ? Math.round(((l.s || 1) * (2 / 3) / l.a) * 1e4) / 1e4 : .11);

  function mount(el, opt) {
    let labels = JSON.parse(JSON.stringify(opt.labels || {}));
    let saved = JSON.stringify(labels);
    let cur = (opt.consoles[0] || {}).id;
    el.classList.add('cle');
    const one = () => opt.consoles.find((c) => c.id === cur) || {};
    function draw() {
      const c = one(), l = labels[cur] || {};
      el.innerHTML = `
        <div class="cle-list">${opt.consoles.map((x) => `<button type="button" data-c="${h(x.id)}" class="${x.id === cur ? 'on' : ''}"><span class="cle-th">${x.samples && x.samples[0] ? `<img src="${h(x.samples[0])}" alt="">` : ''}${coverLabel(labels[x.id])}</span><span>${h(x.name)}</span>${labels[x.id] && labels[x.id].src ? '<i>Set</i>' : ''}</button>`).join('')}</div>
        <div class="cle-main">
          <div class="cle-top"><b>${h(c.name || '')}</b><span class="cle-dirty"${JSON.stringify(labels) === saved ? ' hidden' : ''}>Unsaved</span></div>
          <div class="cle-prev">${(c.samples && c.samples.length ? c.samples.slice(0, 3) : ['']).map((s) => `<div class="cle-cover${coverClass(l)}">${s ? `<img src="${h(s)}" alt="">` : '<div class="cle-empty">No sample cover</div>'}${coverLabel(l)}<span class="cle-badge">Installed</span><div class="cle-guide"></div></div>`).join('')}</div>
          <div class="cle-ctl">
            <div class="cle-row"><button type="button" class="mde-b" data-act="up">${l.src ? 'Replace PNG' : 'Upload PNG'}</button>${l.src ? '<button type="button" class="mde-b" data-act="rm">Remove</button>' : ''}<input type="file" accept="image/png,image/webp" hidden></div>
            <label class="mde-sl">Width<input type="range" min=".5" max="1.1" step=".005" data-k="s" value="${+l.s || 1}"${l.src ? '' : ' disabled'}></label>
            <label class="mde-sl">Nudge<input type="range" min="-.05" max=".12" step=".001" data-k="y" value="${+l.y || 0}"${l.src ? '' : ' disabled'}></label>
            <label class="mde-tg"><input type="checkbox" data-k="push"${l.push ? ' checked' : ''}${l.src ? '' : ' disabled'}> Push Cover Down (the art starts under the label instead of behind it)</label>
            <p class="cle-tip">The dashed lines mark the top 12% of the cover, where most labels sit. Use a PNG at least 1200 px wide, with see-through areas where the cover should show.</p>
            <div class="cle-row"><button type="button" class="mde-b mde-main" data-act="save">Save Labels</button><span class="cle-msg" role="status"></span></div>
          </div>
        </div>`;
    }
    el.addEventListener('click', async (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      if (b.dataset.c) { cur = b.dataset.c; return draw(); }
      if (b.dataset.act === 'up') return el.querySelector('input[type=file]').click();
      if (b.dataset.act === 'rm') { delete labels[cur]; return draw(); }
      if (b.dataset.act === 'save') {
        const m = el.querySelector('.cle-msg');
        try { await opt.api.save(labels); saved = JSON.stringify(labels); draw(); el.querySelector('.cle-msg').textContent = 'Saved. Players get it with the next catalog refresh.'; }
        catch (err) { m.textContent = 'Couldn\'t save: ' + (err.message || err); }
      }
    });
    el.addEventListener('change', async (e) => {
      if (e.target.type !== 'file') return;
      const f = e.target.files[0]; e.target.value = '';
      if (!f) return;
      try {
        const src = await opt.api.upload(f, cur);
        const a = await new Promise((ok) => { const im = new Image(); im.onload = () => ok(im.naturalWidth / im.naturalHeight); im.onerror = () => ok(0); im.src = src; });
        const l = labels[cur] = Object.assign({ s: 1, y: 0 }, labels[cur] || {}, { src, a: Math.round(a * 1e4) / 1e4 });
        l.h = heightOf(l); draw();
      }
      catch (err) { el.querySelector('.cle-msg').textContent = 'Upload failed: ' + (err.message || err); }
    });
    el.addEventListener('input', (e) => {
      const k = e.target.dataset.k, l = labels[cur];
      if (!k || !l) return;
      if (k === 'push') { l.push = e.target.checked; return draw(); }
      l[k] = Math.round(+e.target.value * 1000) / 1000;
      l.h = heightOf(l);
      el.querySelectorAll('.cle-cover .cvl, .cle-list .on .cvl').forEach((im) => im.style.setProperty('--cvl-' + k, l[k]));
      el.querySelectorAll('.cle-cover').forEach((c) => { c.style.setProperty('--cvl-h', l.h); c.style.setProperty('--cvl-top', Math.max(0, l.h + (l.y || 0))); });
      el.querySelector('.cle-dirty').hidden = JSON.stringify(labels) === saved;
    });
    draw();
    // Labels saved without their size get measured, so the height the launcher reads is right.
    for (const [id, l] of Object.entries(labels)) {
      if (!l || !l.src || l.a > 0) continue;
      const im = new Image();
      im.onload = () => { l.a = Math.round((im.naturalWidth / im.naturalHeight) * 1e4) / 1e4; l.h = heightOf(l); if (id === cur) draw(); };
      im.src = l.src;
    }
    return { labels: () => labels };
  }
  return { mount, coverLabel, coverClass };
})();
if (typeof module !== 'undefined') module.exports = CoverLabels;
