// RecompStore admin (4.8): Sponsors > Instant Gaming. (4.9) The add/edit form: search a game (every
// version of it gets the card), paste its Instant Gaming pages (one per region) and Read them: each page's
// region, price, stock and the countries its key works in, and which countries the card will show in.
(function () {
  'use strict';
  var games = [];
  try { games = JSON.parse((document.getElementById('ig-games') || {}).textContent || '[]'); } catch (e) { games = []; }

  document.querySelectorAll('[data-ig-del]').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = b.form && b.form.querySelector('[data-ig-del-field]');
      if (f) f.value = b.getAttribute('data-ig-del');
    });
  });

  var form = document.getElementById('edit');
  if (!form) return;
  var app = form.elements.app_id, title = form.elements.title, plat = form.elements.platform, find = form.querySelector('[data-ig-find]');
  var igr = form.getAttribute('data-igr') || 'recomp';
  var q = form.querySelector('[data-ig-q]'), list = form.querySelector('[data-ig-results]'), chosen = form.querySelector('[data-ig-chosen]');
  var byKey = {};
  games.forEach(function (g) { byKey[g.k] = g; });
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  function findHref() {
    var g = byKey[app.value], s = (g ? g.n : '') || title.value || '';
    if (find) find.href = 'https://www.instant-gaming.com/en/search/?q=' + encodeURIComponent(s.trim()) + '&igr=' + encodeURIComponent(igr);
  }
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, ' ').trim(); }
  function pick(g) {
    app.value = g.k; q.value = g.n; list.hidden = true;
    chosen.textContent = '';
    chosen.appendChild(el('b', '', g.n));
    chosen.appendChild(el('span', 'muted small', g.l + (g.v.length > 1 ? ': ' + g.v.join(', ') : '')));
    chosen.hidden = false;
    findHref();
  }
  if (q && !q.readOnly) {
    q.addEventListener('input', function () {
      var t = norm(q.value);
      list.textContent = '';
      if (t.length < 2) { list.hidden = true; return; }
      var words = t.split(' ');
      var hits = games.filter(function (g) { var h = norm(g.n + ' ' + g.v.join(' ')); return words.every(function (w) { return h.indexOf(w) >= 0; }); }).slice(0, 12);
      if (!hits.length) { list.appendChild(el('li', 'muted', 'No game matches that.')); list.hidden = false; return; }
      hits.forEach(function (g) {
        var li = el('li');
        if (g.c) { var im = el('img'); im.src = g.c; im.alt = ''; im.loading = 'lazy'; li.appendChild(im); } else li.appendChild(el('span', 'ig-nocover'));
        var d = el('div'); d.appendChild(el('b', '', g.n)); d.appendChild(el('span', 'muted small', g.l)); li.appendChild(d);
        if (g.has) li.appendChild(el('span', 'pill', 'Has a card'));
        li.addEventListener('mousedown', function (e) { e.preventDefault(); pick(g); });
        list.appendChild(li);
      });
      list.hidden = false;
    });
    q.addEventListener('blur', function () { setTimeout(function () { list.hidden = true; }, 150); });
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); var f = list.querySelector('li img, li .ig-nocover'); if (f) f.parentNode.dispatchEvent(new MouseEvent('mousedown')); } });
  }
  form.addEventListener('submit', function (e) {
    if (!app.value) { e.preventDefault(); if (q) { q.focus(); q.setCustomValidity('Pick the game from the search.'); q.reportValidity(); q.setCustomValidity(''); } }
  });

  // Read them: each page, one after another, into a small table, then which countries are covered.
  var readBtn = form.querySelector('[data-ig-read]'), out = form.querySelector('[data-ig-read-out]'), urls = form.elements.urls;
  var csrf = (form.querySelector('input[name=csrf]') || {}).value || '';
  function readOne(u) {
    var fd = new FormData();
    fd.append('action', 'read'); fd.append('url', u); fd.append('csrf', csrf);
    return fetch('instant-gaming.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { ok: false, why: 'Couldn\'t reach the site. Try again.' }; });
  }
  function read() {
    var links = urls.value.split(/\s+/).map(function (s) { return s.trim(); }).filter(function (s) { return /^https:\/\/(www\.)?instant-gaming\.com\//i.test(s); });
    if (!links.length) return;
    out.textContent = 'Reading ' + links.length + ' page' + (links.length === 1 ? '' : 's') + '…';
    readBtn.disabled = true;
    var res = [], i = 0;
    (function next() {
      if (i >= links.length) { readBtn.disabled = false; show(links, res); return; }
      readOne(links[i]).then(function (x) { res.push(x); i++; next(); });
    })();
  }
  function show(links, res) {
    out.textContent = '';
    var tbl = el('table', 'ig-readt'), cov = {}, curs = {}, firstOk = null, bad = 0;
    res.forEach(function (x, n) {
      var tr = el('tr');
      if (!x.ok) { bad++; tr.appendChild(el('td', 'warn', links[n].replace(/^https:\/\/(www\.)?instant-gaming\.com\/[a-z]{2}\//, '') + ': ' + x.why)); tr.firstChild.colSpan = 4; tbl.appendChild(tr); return; }
      firstOk = firstOk || x;
      x.countries.forEach(function (c) { cov[c] = true; });
      (x.curs || []).forEach(function (c) { curs[c] = true; });
      tr.appendChild(el('td', '', x.region || 'Region not given'));
      tr.appendChild(el('td', '', x.countries.length + ' countr' + (x.countries.length === 1 ? 'y' : 'ies') + (x.unknown.length ? ' (' + x.unknown.length + ' names not placed: ' + x.unknown.join(', ') + ')' : '')));
      tr.appendChild(el('td', '', x.usd + (x.old_usd ? ' (was ' + x.old_usd + ')' : '')));
      tr.appendChild(el('td', x.stock ? '' : 'warn', x.stock ? 'In stock' : 'Out of stock'));
      if (!x.countries.length) tr.lastChild.textContent = 'Its page doesn\'t list countries: it can\'t be shown';
      tbl.appendChild(tr);
    });
    out.appendChild(tbl);
    if (firstOk) {
      if (!title.value.trim() || title.dataset.auto === '1') { title.value = firstOk.name; title.dataset.auto = '1'; }
      if (!plat.value.trim() || plat.dataset.auto === '1') { plat.value = firstOk.platform; plat.dataset.auto = '1'; }
      var n = Object.keys(cov).length, big = ['US', 'CA', 'GB', 'DE', 'FR', 'ES', 'IT', 'MX', 'BR', 'AU'];
      var miss = big.filter(function (c) { return !cov[c]; });
      var p = el('p', 'small');
      p.textContent = 'The card shows in ' + n + ' countr' + (n === 1 ? 'y' : 'ies') + ', in ' + Object.keys(curs).join(', ') + ', and nowhere else.' + (miss.length ? ' Not covered (add another region\'s page to cover them): ' + miss.join(', ') + '.' : '');
      out.appendChild(p);
      var a = el('p', 'small mono'); a.textContent = 'A click from the US goes to: ' + firstOk.link; out.appendChild(a);
    }
    if (bad) out.appendChild(el('p', 'small warn', 'Fix or remove the pages that couldn\'t be read before saving.'));
    // The worldwide choice: one option per page that was read.
    var ws = form.querySelector('[data-ig-world]');
    if (ws) {
      var keep = ws.value;
      while (ws.options.length > 1) ws.remove(1);
      res.forEach(function (x, n) {
        if (!x.ok) return;
        var o = el('option', '', 'On: everywhere else gets the ' + (x.region || 'this') + ' key (' + x.usd + ')');
        o.value = links[n];
        ws.appendChild(o);
      });
      ws.value = keep;
      if (ws.value !== keep) ws.value = '';
    }
  }
  if (readBtn) readBtn.addEventListener('click', read);
  urls.addEventListener('change', read);
  title.addEventListener('input', function () { title.dataset.auto = ''; });
  plat.addEventListener('input', function () { plat.dataset.auto = ''; });
})();
