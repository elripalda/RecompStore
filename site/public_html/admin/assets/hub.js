// (4.8) The Submissions hub: the drawer, the buttons (answered as JSON, no page reload), the board's
// filters, its checks worked out after it's drawn, and a quiet refresh while anything is running.
(function () {
  'use strict';
  var root = document.querySelector('.sh');
  if (!root) return;
  var csrf = root.getAttribute('data-csrf'), store = root.getAttribute('data-store'), tab = root.getAttribute('data-tab');
  var drawer = document.querySelector('[data-drawer]'), body = drawer.querySelector('[data-drawer-body]');
  var scrim = document.querySelector('[data-scrim]'), toastEl = document.querySelector('[data-toast]');
  var boardEl = root.querySelector('[data-board]');
  var openKey = null, toastT = null, filt = 'all', q = '';
  // (4.8) Bulk select: drafts picked on the board (kept across the board's quiet refreshes).
  var selecting = false, picked = {};
  var selbar = document.querySelector('[data-selbar]');
  function selDraw() {
    if (!boardEl) return;
    var n = 0;
    boardEl.querySelectorAll('.sh-card[data-item^="job:"]').forEach(function (c) {
      var on = !!picked[c.getAttribute('data-item')];
      c.classList.toggle('picked', on);
      c.setAttribute('aria-pressed', selecting ? String(on) : 'false');
    });
    Object.keys(picked).forEach(function (k) { if (boardEl.querySelector('.sh-card[data-item="' + k + '"]')) n++; else delete picked[k]; });
    root.classList.toggle('selecting', selecting);
    if (selbar) { selbar.hidden = !selecting; selbar.querySelector('[data-seln]').textContent = n + ' selected'; selbar.querySelectorAll('[data-bulk]').forEach(function (b) { b.disabled = !n; }); }
  }
  function selToggle(on) { selecting = on; if (!on) picked = {}; if (on && openKey) close(); selDraw(); }
  var base = 'hub.php?store=' + encodeURIComponent(store);

  function toast(msg, err) {
    if (!msg) return;
    toastEl.textContent = msg;
    toastEl.className = 'sh-toast' + (err ? ' err' : '');
    toastEl.hidden = false;
    clearTimeout(toastT);
    toastT = setTimeout(function () { toastEl.hidden = true; }, err ? 7000 : 4500);
  }
  function send(fd) {
    fd.append('csrf', csrf);
    fd.append('store', store);
    return fetch(base + '&tab=' + tab, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Hub': '1' } })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, msg: 'The admin didn\'t answer. Check your connection and try again.' }; });
  }
  // (4.9) Sections that fold (the "you're live" emails, repo watch, hidden listings) stay the way they
  // were left, in this browser, across reloads and the board's quiet refreshes.
  function keepFolds(scope) {
    (scope || document).querySelectorAll('details[data-keep]').forEach(function (d) {
      var k = 'hub-fold-' + d.getAttribute('data-keep');
      try { var v = localStorage.getItem(k); if (v !== null) d.open = v === '1'; } catch (e) {}
      if (d.hasAttribute('data-kept')) return;
      d.setAttribute('data-kept', '');
      d.addEventListener('toggle', function () { try { localStorage.setItem(k, d.open ? '1' : '0'); } catch (e) {} });
    });
  }
  function done(j) {
    toast(j.msg || (j.ok ? 'Done.' : 'That didn\'t work.'), !j.ok);
    if (!j.ok) return;
    // (4.8) Repo watch: the strip on top of the board, drawn again.
    var wEl = root.querySelector('[data-watch]');
    if (typeof j.watch === 'string' && wEl && tab === 'board') { wEl.innerHTML = j.watch; keepFolds(wEl); }
    else if (typeof j.watch === 'string' && tab === 'watch') { setTimeout(function () { location.reload(); }, 600); return; }
    if (tab !== 'board' && j.reload !== false && !j.open) { setTimeout(function () { location.reload(); }, 600); return; }
    if (j.open) open(j.open);
    else if (j.close) close();
    else if (j.refresh && openKey) open(openKey, { keep: true });
    if (j.reload !== false) refreshBoard();
  }

  // ---- The drawer ----
  function markOn() {
    document.querySelectorAll('.sh-card.on').forEach(function (c) { c.classList.remove('on'); });
    if (!openKey) return;
    var c = document.querySelector('.sh-card[data-item="' + openKey + '"]');
    if (c) c.classList.add('on');
  }
  function open(key, o) {
    o = o || {};
    openKey = key;
    drawer.hidden = false; scrim.hidden = false;
    drawer.classList.add('loading');
    document.documentElement.style.overflow = 'hidden';
    markOn();
    try { history.replaceState(null, '', '#' + key); } catch (e) {}
    var top = drawer.scrollTop;
    return fetch(base + '&item=' + encodeURIComponent(key) + (o.fresh ? '&fresh=1' : ''), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.text(); })
      .then(function (h) {
        if (openKey !== key) return;
        body.innerHTML = h;
        drawer.classList.remove('loading');
        drawer.scrollTop = o.keep ? top : 0;
      })
      .catch(function () { drawer.classList.remove('loading'); toast('Couldn\'t open it. Try again.', true); });
  }
  function close() {
    openKey = null;
    drawer.hidden = true; scrim.hidden = true;
    body.innerHTML = '';
    document.documentElement.style.overflow = '';
    markOn();
    try { history.replaceState(null, '', location.pathname + location.search); } catch (e) {}
  }
  scrim.addEventListener('click', close);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && openKey) close(); else if (e.key === 'Escape' && selecting) selToggle(false); });

  // ---- The board ----
  function applyFilter() {
    if (!boardEl) return;
    boardEl.querySelectorAll('.sh-card').forEach(function (c) {
      var s = c.getAttribute('data-s');
      var ok = (!q || (c.getAttribute('data-q') || '').indexOf(q) !== -1)
        && (filt === 'all' || (filt === 'ver' && c.getAttribute('data-v') === '1') || (filt === 'look' && (s === 'warn' || s === 'bad')) || (filt === 'flag' && c.getAttribute('data-f') === '1'));
      c.hidden = !ok;
    });
  }
  function refreshBoard() {
    if (!boardEl) return Promise.resolve();
    return fetch(base + '&board=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      boardEl.innerHTML = j.html;
      keepFolds(boardEl); applyFilter(); markOn(); pre(); selDraw();
      document.querySelectorAll('.sh-store a').forEach(function (a) {
        var s = (a.getAttribute('href').match(/store=(\w+)/) || [])[1], n = j.counts ? j.counts[s] : 0, c = a.querySelector('.count');
        if (n && !c) { c = document.createElement('span'); c.className = 'count'; a.appendChild(document.createTextNode(' ')); a.appendChild(c); }
        if (c) { if (n) c.textContent = n; else c.remove(); }
      });
    }).catch(function () {});
  }
  // Each submission's checks, a few at a time, after the board is up.
  var preBusy = false;
  function pre() {
    if (preBusy || !boardEl) return;
    var cards = Array.prototype.slice.call(boardEl.querySelectorAll('.sh-card[data-pre]'), 0, 6);
    if (!cards.length) return;
    preBusy = true;
    var ids = cards.map(function (c) { return c.getAttribute('data-pre'); });
    cards.forEach(function (c) { c.removeAttribute('data-pre'); });
    fetch(base + '&pre=1&ids=' + ids.join(','), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      Object.keys(j.res || {}).forEach(function (id) {
        var c = boardEl.querySelector('.sh-card[data-item="sub:' + id + '"]'), n = j.res[id].note;
        if (!c || !n) return;
        var el = c.querySelector('.sh-note');
        el.className = 'sh-note sh-' + n[0];
        el.innerHTML = (n[0] === 'run' ? '<svg class="sh-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><circle cx="12" cy="12" r="8" stroke-opacity=".25"/><path d="M12 4a8 8 0 0 1 8 8"/></svg>' : '<i></i>') + '<span></span>';
        el.querySelector('span').textContent = n[1];
        c.setAttribute('data-s', n[0]);
      });
      applyFilter();
    }).catch(function () {}).then(function () { preBusy = false; pre(); });
  }
  var find = root.querySelector('[data-find]');
  if (find) find.addEventListener('input', function () { q = find.value.trim().toLowerCase(); applyFilter(); });
  root.querySelectorAll('[data-filt]').forEach(function (b) {
    b.addEventListener('click', function () {
      filt = b.getAttribute('data-filt');
      root.querySelectorAll('[data-filt]').forEach(function (x) { x.classList.toggle('on', x === b); });
      applyFilter();
    });
  });
  var findRows = root.querySelector('[data-find-rows]');
  if (findRows) findRows.addEventListener('input', function () {
    var v = findRows.value.trim().toLowerCase();
    root.querySelectorAll('.sh-hrow, .sh-drow').forEach(function (r) { r.hidden = !!v && (r.getAttribute('data-q') || '').indexOf(v) === -1; });
  });
  var addBox = root.querySelector('[data-addbox]');
  if (addBox && root.querySelector('[data-open-add]')) {
    root.querySelector('[data-open-add]').addEventListener('click', function () { addBox.hidden = !addBox.hidden; if (!addBox.hidden) addBox.querySelector('textarea').focus(); });
    addBox.querySelector('[data-close-add]').addEventListener('click', function () { addBox.hidden = true; });
    addBox.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(addBox); fd.append('action', 'link');
      send(fd).then(function (j) { if (j.ok) { addBox.hidden = true; addBox.reset(); } done(j); });
    });
  }

  // ---- Buttons and forms, on the page and in the drawer ----
  function confirmFirst(b) {
    var c = b.getAttribute('data-confirm');
    if (!c || b.hasAttribute('data-confirming')) return false;
    b.setAttribute('data-confirming', b.textContent);
    b.textContent = c;
    setTimeout(function () { if (b.hasAttribute('data-confirming')) { b.textContent = b.getAttribute('data-confirming'); b.removeAttribute('data-confirming'); } }, 4000);
    return true;
  }
  function busy(el, on) { el.querySelectorAll('button').forEach(function (b) { if (on) { b.dataset.wasDisabled = b.disabled ? '1' : ''; b.disabled = true; } else if (!b.dataset.wasDisabled) b.disabled = false; }); }
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.closest('[data-select-toggle]')) { selToggle(!selecting); return; }
    var selCol = t.closest('[data-select-col]');
    if (selCol && boardEl) { boardEl.querySelectorAll('.sh-col-' + selCol.getAttribute('data-select-col') + ' .sh-card[data-item^="job:"]').forEach(function (c) { if (!c.hidden) picked[c.getAttribute('data-item')] = true; }); selDraw(); return; }
    var bulk = t.closest('[data-bulk]');
    if (bulk && !bulk.disabled) {
      if (confirmFirst(bulk)) return;
      bulk.removeAttribute('data-confirming');
      var bfd = new FormData();
      bfd.append('action', 'bulk'); bfd.append('do', bulk.getAttribute('data-bulk')); bfd.append('items', Object.keys(picked).join(','));
      busy(selbar, true);
      send(bfd).then(function (j) { busy(selbar, false); if (j.ok) picked = {}; done(j); selDraw(); });
      return;
    }
    var card = t.closest('.sh-card[data-item], .sh-hrow[data-item]');
    if (card && selecting && card.matches('.sh-card[data-item^="job:"]')) { var k = card.getAttribute('data-item'); if (picked[k]) delete picked[k]; else picked[k] = true; selDraw(); return; }
    if (card && selecting) { toast('Only drafts can be picked. Submissions are handled one by one.'); return; }
    if (card) { open(card.getAttribute('data-item')); return; }
    var oi = t.closest('[data-open-item]');
    if (oi) {
      var st = oi.getAttribute('data-store');
      if (st && st !== store) { location.href = 'hub.php?store=' + st + '#' + oi.getAttribute('data-open-item'); return; }
      open(oi.getAttribute('data-open-item')); return;
    }
    if (t.closest('[data-close]')) { close(); return; }
    var wm = t.closest('[data-watch-move]');
    if (wm) {
      var wf = wm.closest('.sh-wrow').querySelector('[data-form="watch_move"]');
      wf.hidden = !wf.hidden;
      if (!wf.hidden) wf.querySelector('input[name=to]').focus();
      return;
    }
    var clamp = t.closest('[data-clamp]');
    if (clamp) { clamp.classList.remove('clamp'); return; }
    if (t.closest('[data-recheck]')) { open(openKey, { fresh: true, keep: true }); toast('Checking it again…'); return; }
    var op = t.closest('[data-open-panel]');
    if (op) {
      var name = op.getAttribute('data-open-panel');
      body.querySelectorAll('.sh-panel').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
      var more = op.closest('details'); if (more) more.open = false;
      var panel = body.querySelector('[data-panel="' + name + '"]');
      // Email or hub message: the one asked for is ticked (both can still be).
      var via = op.getAttribute('data-via');
      if (panel && via) panel.querySelectorAll('input[name^="via_"]').forEach(function (cb) { if (!cb.disabled) cb.checked = cb.name === 'via_' + via; });
      if (panel) { panel.scrollIntoView({ behavior: 'smooth', block: 'start' }); var f = panel.querySelector('input:not([type=hidden]):not([disabled]), textarea'); if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, 250); }
      return;
    }
    if (t.closest('[data-panel-close]')) { t.closest('.sh-panel').hidden = true; return; }
    var cp = t.closest('[data-copy-body]');
    if (cp) {
      var cpf = cp.closest('form'), cps = cpf.querySelector('[name=subject]'), cpt = cpf.querySelector('textarea[name=body]');
      var cpText = (cps && cps.value ? cps.value + '\n\n' : '') + cpt.value;
      (navigator.clipboard ? navigator.clipboard.writeText(cpText) : Promise.reject()).then(function () { toast('Copied.'); }, function () { cpt.select(); toast('Press Ctrl+C to copy it.'); });
      return;
    }
    var chip = t.closest('[data-chip]');
    if (chip) {
      var form = chip.closest('form'), txt = chip.getAttribute('data-chip'), reason = form.querySelector('[name=reason]'), ta = form.querySelector('textarea[name=body]');
      if (chip.closest('[data-to-reason]')) { reason.value = txt; return; }
      if (reason) reason.value = reason.value.trim() ? reason.value.trim() + '; ' + txt : txt;
      if (ta) {
        var v = ta.value, i = v.search(/^- *$/m);
        ta.value = i === -1 ? v.replace(/\s*$/, '') + '\n- ' + txt + '\n' : v.slice(0, i) + '- ' + txt + '\n' + v.slice(i);
      }
      chip.classList.add('used');
      return;
    }
    var mode = t.closest('[data-mode]');
    if (mode) {
      var cf = mode.closest('form'), m = mode.getAttribute('data-mode'), area = cf.querySelector('textarea');
      cf.querySelectorAll('[data-mode]').forEach(function (b) { b.classList.toggle('on', b === mode); });
      cf.setAttribute('data-mode-now', m);
      area.placeholder = area.getAttribute('data-ph-' + m) || '';
      cf.querySelector('[data-send]').textContent = m === 'note' ? 'Save note' : 'Send message';
      cf.querySelectorAll('[data-only]').forEach(function (x) { x.hidden = x.getAttribute('data-only') !== m; });
      area.focus();
      return;
    }
    var act = t.closest('[data-act]');
    if (act && !act.disabled) {
      e.preventDefault();
      if (confirmFirst(act)) return;
      act.removeAttribute('data-confirming');
      var fd = new FormData();
      fd.append('action', act.getAttribute('data-act'));
      var holder = act.closest('[data-item]');
      fd.append('item', holder ? holder.getAttribute('data-item') : (openKey || ''));
      ['req', 'to', 'repo', 'flag'].forEach(function (k) { if (act.hasAttribute('data-' + k)) fd.append(k, act.getAttribute('data-' + k)); });
      var scope = act.closest('.sh-d-foot, .sh-rowc-acts, .sh-row') || act.parentNode;
      busy(scope, true);
      var more2 = act.closest('details'); if (more2) more2.open = false;
      send(fd).then(function (j) { busy(scope, false); done(j); });
    }
  });
  // (4.9) Edit its page: a picture picked to replace one shows straight away (saved with Save).
  // Clicking a picture opens the art picker (SteamGridDB, IGDB and the rest); it has "From your
  // computer" for an upload. A click made by the picker itself goes through to the file box.
  document.addEventListener('click', function (e) {
    var tile = e.target.closest && e.target.closest('.sh-edit .sh-pic');
    if (!tile || !window.rsArtPick || e.target.matches('input[type=file]')) return;
    var inp = tile.querySelector('input[type=file][data-pic]');
    if (!inp) return;
    var kind = inp.name.replace('_file', '');
    if (window.rsArtPick.open(kind, inp.form)) e.preventDefault();
  });
  document.addEventListener('change', function (e) {
    var inp = e.target;
    if (!inp.matches || !inp.matches('input[type=file][data-pic]') || !inp.files || !inp.files[0]) return;
    var box = inp.closest('.sh-pic').querySelector('.sh-pic-img');
    var img = box.querySelector('img');
    if (!img) { box.innerHTML = ''; img = document.createElement('img'); img.alt = ''; box.appendChild(img); }
    img.src = URL.createObjectURL(inp.files[0]);
    inp.closest('.sh-pic').classList.add('changed');
  });
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (f.hasAttribute('data-addbox')) return;
    var action = f.getAttribute('data-form') || (f.hasAttribute('data-compose') ? (f.getAttribute('data-mode-now') || 'note') : '');
    if (!action) return;
    e.preventDefault();
    var fd = new FormData(f);
    if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value);
    fd.append('action', action);
    if (!fd.get('item')) fd.append('item', openKey || '');
    if (fd.get('body') && /^(changes|decline)$/.test(action)) fd.set('body', String(fd.get('body')).replace(/^- *\n/gm, '').replace(/\n{3,}/g, '\n\n'));
    if (action === 'note') fd.delete('email');
    busy(f, true);
    send(fd).then(function (j) { busy(f, false); if (j.ok && f.hasAttribute('data-compose')) f.querySelector('textarea').value = ''; done(j); });
  });

  // ---- Keep it current while things run (only while the tab is visible) ----
  var sig = null;
  setInterval(function () {
    if (document.hidden || tab !== 'board') return;
    fetch('hub.php?poll=1', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (sig === null) { sig = j.sig; return; }
      if (j.sig === sig) return;
      sig = j.sig;
      refreshBoard();
      // An open draft that's still running shows its new steps (unless something's being typed).
      if (openKey && /^job:|^sub:/.test(openKey) && !body.querySelector('.sh-panel:not([hidden])') && !(document.activeElement && /TEXTAREA|INPUT/.test(document.activeElement.tagName))) open(openKey, { keep: true });
    }).catch(function () {});
  }, 6000);

  keepFolds(document);
  pre();
  var h = decodeURIComponent(location.hash.slice(1));
  if (/^(sub|job|live):\d+$/.test(h)) open(h);
})();
