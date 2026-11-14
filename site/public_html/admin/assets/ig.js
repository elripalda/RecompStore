// RecompStore admin (4.8): Sponsors > Instant Gaming. (4.9) The add/edit form: search a game (every
// version of it gets the card), paste its Instant Gaming page and Read it (name, platform, price, stock),
// and see the link with the partner code. Find it opens Instant Gaming's search for the game.
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
  var app = form.elements.app_id, title = form.elements.title, find = form.querySelector('[data-ig-find]'), hint = form.querySelector('[data-ig-hint]');
  var igr = form.getAttribute('data-igr') || 'recomp', min = parseInt(form.getAttribute('data-tagmin'), 10) || 0, tagOn = form.getAttribute('data-tagon') === '1';
  var q = form.querySelector('[data-ig-q]'), list = form.querySelector('[data-ig-results]'), chosen = form.querySelector('[data-ig-chosen]');
  var byKey = {};
  games.forEach(function (g) { byKey[g.k] = g; });
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }

  function findHref() {
    var g = byKey[app.value], s = (g ? g.n : '') || title.value || '';
    if (find) find.href = 'https://www.instant-gaming.com/en/search/?q=' + encodeURIComponent(s.trim()) + '&igr=' + encodeURIComponent(igr);
  }
  function norm(s) { return String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' ').trim(); }
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

  // Read it: what the Instant Gaming page says, straight into the form.
  var readBtn = form.querySelector('[data-ig-read]'), out = form.querySelector('[data-ig-read-out]'), auto = form.querySelector('[data-ig-auto]');
  function money() { var on = auto && auto.checked; form.querySelectorAll('[data-ig-money]').forEach(function (m) { m.readOnly = on; }); form.elements.currency.disabled = false; }
  if (auto) { auto.addEventListener('change', money); money(); }
  function read() {
    var url = form.elements.url.value.trim();
    if (!url) return;
    out.className = 'span2 ig-read-out'; out.textContent = 'Reading the page…';
    readBtn.disabled = true;
    var fd = new FormData();
    fd.append('action', 'read'); fd.append('url', url); fd.append('csrf', form.elements.csrf.value);
    fetch('instant-gaming.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (x) {
      readBtn.disabled = false;
      if (!x.ok) { out.className = 'span2 ig-read-out warn'; out.textContent = x.why; return; }
      if (!title.value.trim() || title.dataset.auto === '1') { title.value = x.name; title.dataset.auto = '1'; }
      if (!form.elements.platform.value.trim() || form.elements.platform.dataset.auto === '1') { form.elements.platform.value = x.platform; form.elements.platform.dataset.auto = '1'; }
      form.elements.region.value = x.region || '';
      form.elements.price.value = x.price ? x.price.toFixed(2) : '';
      form.elements.old_price.value = x.old ? x.old.toFixed(2) : '';
      form.elements.currency.value = x.currency;
      out.textContent = '';
      out.appendChild(document.createTextNode(x.name + (x.platform ? ' · ' + x.platform : '') + (x.region ? ' · ' + x.region : '') + ' · ' + (x.stock ? 'in stock' : 'out of stock (the card stays hidden until it\'s back)') + '. Clicks go to '));
      var a = el('a', 'mono', x.link); a.href = x.link; a.target = '_blank'; a.rel = 'noopener'; out.appendChild(a);
      if (x.region && !/united states|usa|north america|global|worldwide/i.test(x.region)) { out.appendChild(el('br')); out.appendChild(el('span', 'warn', 'This key is for ' + x.region + '. Check it works where your players are, or pick that game\'s other region on Instant Gaming.')); }
      say();
    }).catch(function () { readBtn.disabled = false; out.className = 'span2 ig-read-out warn'; out.textContent = 'Couldn\'t reach the site. Try again.'; });
  }
  if (readBtn) readBtn.addEventListener('click', read);
  form.elements.url.addEventListener('change', read);
  title.addEventListener('input', function () { title.dataset.auto = ''; });
  form.elements.platform.addEventListener('input', function () { form.elements.platform.dataset.auto = ''; });

  function num(v) { v = String(v || '').replace(',', '.').trim(); var n = parseFloat(v); return isFinite(n) && n > 0 ? n : 0; }
  function say() {
    var p = num(form.elements.price.value), o = num(form.elements.old_price.value), mode = form.elements.tag.value;
    hint.textContent = '';
    hint.className = 'span2 ig-hint';
    if (!p) { hint.textContent = 'No price: the button will say See price.'; return; }
    if (!o || o <= p) { hint.textContent = 'No discount: the card shows just the price.'; return; }
    var pct = Math.round((o - p) / o * 100);
    if (!tagOn) { hint.textContent = pct + '% off. Discount tags are off for every card, so it shows just the price.'; return; }
    if (mode === '2') { hint.textContent = pct + '% off. Its tag is set to never show.'; return; }
    if (mode === '1' && pct < min) {
      hint.className = 'span2 ig-hint warn';
      hint.textContent = 'Only ' + pct + '% off, and the tag is set to always show. A tag this small can look like noise. ';
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'small'; b.textContent = 'Use the rule instead';
      b.addEventListener('click', function () { form.elements.tag.value = '0'; say(); });
      hint.appendChild(b);
      return;
    }
    hint.textContent = pct + '% off. ' + (mode === '1' || pct >= min ? 'The tag and the old price show.' : 'Under ' + min + '%, so the tag and the old price stay hidden.');
  }
  ['price', 'old_price', 'tag'].forEach(function (n) { form.elements[n].addEventListener('input', say); form.elements[n].addEventListener('change', say); });
  say();
})();
