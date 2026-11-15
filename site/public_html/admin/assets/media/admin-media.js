'use strict';
// (4.11) Mounts the media editor (a PC listing's page), Auto-fill (the PC list's Easy Setup filter) and
// Cover Labels (Settings) on the admin's pages, with media-api.php behind them. The editor files
// beside this one are the launcher's, unchanged; this is only the wiring.
(() => {
  const api = 'media-api.php';
  const err = async (r) => { let m = 'HTTP ' + r.status; try { const j = await r.json(); if (j && j.error) m = j.error; } catch {} return new Error(m); };
  const post = async (a, csrf, body) => {
    const isForm = body instanceof FormData;
    const r = await fetch(api + '?a=' + a, { method: 'POST', credentials: 'same-origin', headers: Object.assign({ 'X-CSRF': csrf }, isForm ? {} : { 'Content-Type': 'application/json' }), body: isForm ? body : JSON.stringify(body) });
    if (!r.ok) throw await err(r);
    return r.json();
  };
  const data = (el) => { try { return JSON.parse(el.dataset.media || '{}'); } catch { return {}; } };

  // A PC listing's Media section.
  const edEl = document.getElementById('media-editor');
  if (edEl && window.MediaEditor) {
    const d = data(edEl), csrf = edEl.dataset.csrf;
    MediaEditor.mount(edEl, {
      console: d.console, title: d.title, art: d.art || {}, design: d.design || null,
      api: {
        upload: async (file, kind) => { const f = new FormData(); f.append('listing', d.id); f.append('kind', kind); f.append('file', file); return (await post('upload', csrf, f)).url; },
        steamgrid: async (shape) => { const r = await fetch(api + '?a=sgdb&listing=' + encodeURIComponent(d.id) + '&shape=' + encodeURIComponent(shape), { credentials: 'same-origin' }); if (!r.ok) throw await err(r); return r.json(); },
        save: async (design) => post('save', csrf, { listing: d.id, media: design }),
      },
    });
  }

  // Auto-fill Media for Easy Setup games.
  const af = document.getElementById('media-autofill');
  if (af && window.MediaEditor) {
    const d = data(af), csrf = af.dataset.csrf;
    const go = af.querySelector('[data-af-go]'), stop = af.querySelector('[data-af-stop]'), redo = af.querySelector('[data-af-redo]');
    const bar = af.querySelector('[data-af-bar]'), note = af.querySelector('[data-af-note]');
    let stopped = false;
    go.addEventListener('click', async () => {
      const games = (d.games || []).slice();
      if (!games.length) return;
      stopped = false; go.disabled = true; redo.disabled = true; stop.hidden = false;
      let made = 0, failed = 0, kept = 0;
      note.textContent = 'Starting…';
      await MediaEditor.autoAll(games, async (id, design) => {
        await post('save', csrf, { listing: id, media: design, publish: false });
        const g = (d.games || []).find((x) => x.id === id); if (g) g.design = design;
      }, (done, total, id, result) => {
        if (result === 'done') made++; else if (result === 'kept') kept++; else failed++;
        bar.style.width = Math.round((done / total) * 100) + '%';
        note.textContent = `${done} of ${total} · ${made} made · ${kept} kept · ${failed} failed${result && result.startsWith('failed') ? ' (' + id + ': ' + result.slice(8) + ')' : ''}`;
        if (stopped) games.length = 0; // autoAll walks this list: emptying it stops after this game
      }, { redo: redo.checked });
      note.textContent += ' · writing the catalog…';
      try { await post('publish', csrf, {}); note.textContent = note.textContent.replace(' · writing the catalog…', '') + (stopped ? ' · stopped.' : ' · done.') + ' The catalog is written.'; }
      catch (e) { note.textContent += ' The catalog didn\'t write: ' + e.message; }
      go.disabled = false; redo.disabled = false; stop.hidden = true;
    });
    stop.addEventListener('click', () => { stopped = true; note.textContent += ' · stopping…'; });
  }

  // Cover Labels (Settings, owner only).
  const cl = document.getElementById('cover-labels');
  if (cl && window.CoverLabels) {
    const d = data(cl), csrf = cl.dataset.csrf;
    CoverLabels.mount(cl, {
      consoles: d.consoles || [], labels: d.labels || {},
      api: {
        upload: async (file, consoleId) => { const f = new FormData(); f.append('console', consoleId); f.append('file', file); return (await post('labels-upload', csrf, f)).url; },
        save: async (labels) => post('labels-save', csrf, { labels }),
      },
    });
  }
})();
