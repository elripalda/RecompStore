// Recomp Store admin (2.3.0): the Collections page. Each collection is a card: its name, a line about
// it, and its apps in order (added from a list, moved up and down, taken out).
(function () {
  'use strict';
  const S = window.RS_COLLECTIONS;
  const listEl = document.querySelector('[data-colls]');
  if (!S || !listEl) return;
  const saveForm = document.querySelector('[data-save]');
  const note = saveForm.querySelector('[data-save-note]');
  const publishForm = document.querySelector('[data-publish]');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const games = S.games || [];
  const byId = (id) => games.find((g) => g.id === id);
  let colls = JSON.parse(JSON.stringify(S.collections || []));
  let dirty = false;

  function changed(relist) {
    dirty = true;
    note.textContent = 'Changes not saved yet. Save, then Publish.';
    note.classList.add('warn');
    if (relist) render();
  }

  function render() {
    listEl.innerHTML = colls.length ? '' : '<p class="muted">No collections yet. Make one below.</p>';
    colls.forEach((c, i) => {
      c.ids = (c.ids || []).filter((id) => byId(id));
      const card = document.createElement('section');
      card.className = 'card sec';
      const addable = games.filter((g) => !c.ids.includes(g.id));
      card.innerHTML = `
        <div class="sec-head">
          <span class="tag cyan">Collection</span>
          <span class="sec-title">${esc(c.title)}</span>
          <span class="sec-tools">
            <button type="button" class="icon-btn" data-move="-1" aria-label="Move up" ${i === 0 ? 'disabled' : ''}>↑</button>
            <button type="button" class="icon-btn" data-move="1" aria-label="Move down" ${i === colls.length - 1 ? 'disabled' : ''}>↓</button>
            <button type="button" class="icon-btn danger" data-remove aria-label="Remove">✕</button>
          </span>
        </div>
        <label><span>Name</span><input data-title maxlength="80" value="${esc(c.title)}" placeholder="Racing night"></label>
        <label><span>A line about it <span class="muted">(optional)</span></span><input data-blurb maxlength="300" value="${esc(c.blurb || '')}" placeholder="Everything with wheels."></label>
        <ol class="picked">${c.ids.map((id, k) => { const g = byId(id); return `
          <li>${g.icon ? `<img src="${esc(g.icon)}" alt="">` : `<span class="blank">${esc(g.name.slice(0, 2))}</span>`}<span>${esc(g.name)}${g.testers ? ' <span class="muted">(testers only)</span>' : ''}</span>
            <button type="button" class="icon-btn" data-gmove="${k},-1" ${k === 0 ? 'disabled' : ''} aria-label="Earlier">↑</button>
            <button type="button" class="icon-btn" data-gmove="${k},1" ${k === c.ids.length - 1 ? 'disabled' : ''} aria-label="Later">↓</button>
            <button type="button" class="icon-btn" data-gremove="${k}" aria-label="Take out">✕</button></li>`; }).join('')}</ol>
        ${c.ids.length ? '' : '<p class="muted small-note">No apps yet.</p>'}
        ${addable.length ? `<select data-gadd><option value="">Add an app…</option>${addable.map((g) => `<option value="${esc(g.id)}">${esc(g.name)}${g.testers ? ' (testers only)' : ''}</option>`).join('')}</select>` : ''}
        ${c.id ? `<p class="muted small-note">Its id: <code>${esc(c.id)}</code> (stays the same when you rename it).</p>` : ''}`;
      listEl.appendChild(card);
      card.querySelector('[data-title]').addEventListener('input', (e) => { c.title = e.target.value; card.querySelector('.sec-title').textContent = c.title; changed(false); });
      card.querySelector('[data-blurb]').addEventListener('input', (e) => { c.blurb = e.target.value; changed(false); });
      card.querySelectorAll('[data-move]').forEach((b) => b.addEventListener('click', () => {
        const j = i + +b.dataset.move;
        [colls[i], colls[j]] = [colls[j], colls[i]];
        changed(true);
      }));
      card.querySelector('[data-remove]').addEventListener('click', () => {
        if (c.ids.length && !confirm(`Remove "${c.title || 'this collection'}"? A Discover section showing it is left out until you pick another.`)) return;
        colls.splice(i, 1);
        changed(true);
      });
      card.querySelectorAll('[data-gmove]').forEach((b) => b.addEventListener('click', () => {
        const [k, d] = b.dataset.gmove.split(',').map(Number);
        [c.ids[k], c.ids[k + d]] = [c.ids[k + d], c.ids[k]];
        changed(true);
      }));
      card.querySelectorAll('[data-gremove]').forEach((b) => b.addEventListener('click', () => { c.ids.splice(+b.dataset.gremove, 1); changed(true); }));
      const add = card.querySelector('[data-gadd]');
      if (add) add.addEventListener('change', () => { if (add.value) { c.ids.push(add.value); changed(true); } });
    });
  }

  document.querySelector('[data-add-coll]').addEventListener('click', () => {
    colls.push({ id: '', title: 'New collection', blurb: '', ids: [] });
    changed(true);
    const last = listEl.lastElementChild;
    if (last) { last.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); const t = last.querySelector('[data-title]'); if (t) { t.focus(); t.select(); } }
  });
  saveForm.addEventListener('submit', (e) => {
    if (colls.some((c) => !c.title.trim())) { e.preventDefault(); alert('Give every collection a name.'); return; }
    saveForm.querySelector('[name="collections"]').value = JSON.stringify(colls);
    dirty = false;
  });
  if (publishForm) publishForm.addEventListener('submit', (e) => {
    if (dirty && !confirm('Collections has changes you haven\'t saved. Publish without them?')) e.preventDefault();
  });
  window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  render();
})();
