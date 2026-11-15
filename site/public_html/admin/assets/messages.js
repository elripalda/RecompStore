// (4.12) Messages: send without reloading (with files), new messages as they come in, the New message
// search, and a soft ping when a developer writes. Works on the Developers and Lounge views of messages.php.
(() => {
  'use strict';
  const mx = document.querySelector('[data-mx]'), lounge = document.querySelector('[data-lounge]');
  const flow = document.querySelector('[data-flow]');
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const MAX = 15 * 1024 * 1024, OK = /\.(png|jpe?g|gif|webp|mp4|webm|mov|txt|log|json|ini|toml|cfg|zip|7z)$/i;
  const atEnd = () => !flow || flow.scrollHeight - flow.scrollTop - flow.clientHeight < 80;
  const toEnd = () => { if (flow) flow.scrollTop = flow.scrollHeight; };
  toEnd();
  // Pictures load after the first scroll: keep the newest message in view while they do.
  if (flow) flow.querySelectorAll('img').forEach((i) => i.addEventListener('load', () => { if (!flow.dataset.moved) toEnd(); }, { once: true }));
  if (flow) flow.addEventListener('scroll', () => { flow.dataset.moved = atEnd() ? '' : '1'; });

  // A short, quiet two-note ping (no file to load).
  let ac = null;
  const ping = () => {
    try {
      ac = ac || new (window.AudioContext || window.webkitAudioContext)();
      [[880, 0], [1320, 0.09]].forEach(([f, d]) => {
        const o = ac.createOscillator(), g = ac.createGain();
        o.type = 'sine'; o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, ac.currentTime + d);
        g.gain.exponentialRampToValueAtTime(0.08, ac.currentTime + d + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime + d + 0.25);
        o.connect(g).connect(ac.destination); o.start(ac.currentTime + d); o.stop(ac.currentTime + d + 0.3);
      });
    } catch (e) { /* no sound is fine */ }
  };
  const baseTitle = document.title;
  const setTitle = (n) => { document.title = (n > 0 ? '(' + n + ') ' : '') + baseTitle; };

  // ---- The composer: grows as you type, Enter sends, files as chips ------------------------------
  const form = document.querySelector('[data-send-form]');
  if (form) {
    const ta = form.querySelector('[data-grow]'), fileIn = form.querySelector('[data-file]'), chips = form.querySelector('[data-chips]'), st = form.querySelector('[data-status]');
    let files = [];
    const grow = () => { ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 220) + 'px'; };
    ta.addEventListener('input', grow);
    const say = (t, bad) => { st.textContent = t || ''; st.className = 'mx-status' + (bad ? ' bad' : ''); };
    const drawChips = () => {
      chips.hidden = !files.length;
      chips.innerHTML = files.map((f, i) => `<span class="mx-chip">${f.type.startsWith('image/') ? `<img src="${URL.createObjectURL(f)}" alt="">` : '<i aria-hidden="true">📎</i>'}<span>${esc(f.name)}</span><small>${(f.size / 1048576).toFixed(1)} MB</small><button type="button" data-unchip="${i}" aria-label="Remove">×</button></span>`).join('');
    };
    const add = (list) => {
      for (const f of list) {
        if (!OK.test(f.name)) { say(f.name + ': only pictures, short videos, logs and text files, or ZIP and 7z.', true); continue; }
        if (f.size > MAX) { say(f.name + ' is over 15 MB.', true); continue; }
        if (files.length >= 4) { say('Up to 4 files a message.', true); break; }
        files.push(f);
      }
      drawChips();
    };
    form.querySelector('[data-attach]').addEventListener('click', () => fileIn.click());
    fileIn.addEventListener('change', () => { add(fileIn.files); fileIn.value = ''; });
    chips.addEventListener('click', (e) => { const b = e.target.closest('[data-unchip]'); if (b) { files.splice(Number(b.dataset.unchip), 1); drawChips(); } });
    // Paste a screenshot, or drop files on the conversation.
    ta.addEventListener('paste', (e) => { const fl = [...(e.clipboardData ? e.clipboardData.files : [])]; if (fl.length) { e.preventDefault(); add(fl); } });
    const zone = form.closest('.mx-main, .dc-main');
    if (zone) {
      zone.addEventListener('dragover', (e) => { if (e.dataTransfer && [...e.dataTransfer.types].includes('Files')) { e.preventDefault(); zone.classList.add('drop'); } });
      zone.addEventListener('dragleave', (e) => { if (!zone.contains(e.relatedTarget)) zone.classList.remove('drop'); });
      zone.addEventListener('drop', (e) => { if (e.dataTransfer && e.dataTransfer.files.length) { e.preventDefault(); zone.classList.remove('drop'); add(e.dataTransfer.files); } });
    }
    ta.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); form.requestSubmit(); } });
    const pick = form.querySelector('[data-doc-pick]');
    if (pick) pick.addEventListener('change', () => { if (pick.value) { ta.value = (ta.value.trim() ? ta.value.replace(/\s*$/, '') + '\n' : '') + pick.value; grow(); ta.focus(); } pick.value = ''; });
    form.addEventListener('submit', (e) => {
      e.preventDefault();
      if (!ta.value.trim() && !files.length) { ta.focus(); return; }
      const fd = new FormData(form);
      fd.delete('files[]');
      files.forEach((f) => fd.append('files[]', f, f.name));
      fd.append('ajax', '1');
      const btn = form.querySelector('[data-send]');
      btn.disabled = true;
      say(files.length ? 'Sending ' + files.length + ' file' + (files.length === 1 ? '' : 's') + '…' : 'Sending…');
      fetch(location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' }).then((r) => r.json()).catch(() => ({ ok: false, error: 'That didn\'t go through. Check your connection and try again.' })).then((j) => {
        btn.disabled = false;
        if (!j.ok) { say(j.error, true); return; }
        ta.value = ''; grow(); files = []; drawChips();
        const em = form.querySelector('input[name=email]'); if (em) em.checked = false;
        say(j.msg && /email/i.test(j.msg) ? j.msg : '');
        flow.dataset.moved = '';
        poll(true);
      });
    });
    setTimeout(() => { if (!mx || mx.dataset.dev !== '0') ta.focus({ preventScroll: true }); }, 50);
  }

  // ---- Live: the list and the conversation, every few seconds while the tab is open ---------------
  let sig = '', lastIn = 0, unread = null, busy = false;
  const params = () => {
    const q = new URLSearchParams(location.search);
    q.set('poll', '1'); q.set('sig', sig);
    if (!document.hidden) q.set('vis', '1');
    if (mx && mx.dataset.dev !== '0') q.set('dev', mx.dataset.dev);
    return q;
  };
  const poll = (force) => {
    if (busy || (!mx && !lounge)) return;
    if (document.hidden && !force) return;
    busy = true;
    fetch(location.pathname + '?' + params(), { credentials: 'same-origin', cache: 'no-store' }).then((r) => r.json()).then((j) => {
      if (!j || !j.ok) return;
      if (typeof j.unread === 'number') {
        if (unread !== null && j.unread > unread) ping();
        unread = j.unread; setTitle(document.hidden ? j.unread : 0);
      }
      if (j.same) return;
      sig = j.sig;
      if (mx) {
        const list = document.querySelector('[data-list]');
        if (list && typeof j.list === 'string') { const top = list.scrollTop; list.innerHTML = j.list; list.scrollTop = top; filter(); }
        // A new message in the open conversation pings too (it's read at once, so unread doesn't grow).
        if (j.lastIn && lastIn && j.lastIn > lastIn && !document.hidden) ping();
        if (j.lastIn) lastIn = j.lastIn;
      }
      if (flow && typeof j.flow === 'string' && j.flow) {
        const end = atEnd() || !flow.dataset.moved;
        flow.innerHTML = j.flow;
        if (end) { toEnd(); flow.querySelectorAll('img').forEach((i) => i.addEventListener('load', toEnd, { once: true })); }
      }
    }).catch(() => {}).then(() => { busy = false; });
  };
  if (mx || lounge) {
    poll(true);
    setInterval(poll, 5000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { setTitle(0); poll(true); } });
  }

  // ---- Search the list, and New message --------------------------------------------------------
  const find = document.querySelector('[data-find]');
  const filter = () => {
    if (!find) return;
    const q = find.value.trim().toLowerCase();
    document.querySelectorAll('[data-list] .mx-row').forEach((r) => { r.hidden = !!q && !(r.dataset.q || '').includes(q); });
  };
  if (find) find.addEventListener('input', filter);
  const pop = document.querySelector('[data-newpop]');
  if (pop) {
    let everyone = [];
    try { everyone = JSON.parse(document.querySelector('[data-everyone]').textContent) || []; } catch (e) { everyone = []; }
    const nf = pop.querySelector('[data-new-find]'), nl = pop.querySelector('[data-new-list]');
    const draw = () => {
      const q = nf.value.trim().toLowerCase();
      const hits = everyone.filter((p) => !q || (p.name + ' ' + p.login).toLowerCase().includes(q)).slice(0, 40);
      nl.innerHTML = hits.length ? hits.map((p) => `<a class="mx-row" href="messages.php?dev=${p.id}">${p.pic ? `<img class="mx-av" src="${esc(p.pic)}" alt="" width="34" height="34">` : `<span class="mx-av mx-letter">${esc((p.name || '?').slice(0, 1).toUpperCase())}</span>`}<span class="mx-row-main"><span class="mx-row-top"><b>${esc(p.name)}</b></span><span class="mx-row-prev">${esc(p.login ? '@' + p.login : '')}${p.note ? ' · ' + esc(p.note) : ''}</span></span></a>`).join('')
        : '<p class="mx-empty">Nobody by that name has a developer account.</p>';
    };
    document.querySelector('[data-new]').addEventListener('click', () => { pop.hidden = !pop.hidden; if (!pop.hidden) { nf.value = ''; draw(); nf.focus(); } });
    pop.querySelector('[data-new-close]').addEventListener('click', () => { pop.hidden = true; });
    nf.addEventListener('input', draw);
    nf.addEventListener('keydown', (e) => { if (e.key === 'Enter') { const a = nl.querySelector('a'); if (a) location.href = a.href; } if (e.key === 'Escape') pop.hidden = true; });
  }
})();
