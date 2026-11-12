// RecompStore admin (4.8): the search box, pinning, and the Overview's live numbers.
(function () {
  'use strict';
  var A = window.RS_ADM || {};
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var fmt = function (n) { return (n == null || isNaN(n)) ? '–' : Number(n).toLocaleString('en-US'); };
  var post = function (url, data) {
    var body = new URLSearchParams(); body.set('csrf', A.csrf || '');
    Object.keys(data).forEach(function (k) { body.set(k, data[k]); });
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  };

  // ---- Search -------------------------------------------------------------------------------
  var box = document.querySelector('[data-find]');
  if (box) {
    var inp = box.querySelector('input'), pop = box.querySelector('.find-pop'), timer = 0, seq = 0, sel = -1;
    var links = function () { return Array.prototype.slice.call(pop.querySelectorAll('a')); };
    var mark = function () { links().forEach(function (a, i) { a.classList.toggle('sel', i === sel); }); };
    var run = function () {
      var q = inp.value.trim(), my = ++seq;
      if (q.length < 2) { pop.hidden = true; return; }
      fetch('find.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        if (my !== seq) return;
        var h = '';
        (j.groups || []).forEach(function (g) {
          h += '<h4>' + esc(g.title) + '</h4>';
          g.items.forEach(function (it) { h += '<a href="' + esc(it.href) + '"><span>' + esc(it.label) + '</span><small>' + esc(it.note || '') + '</small></a>'; });
        });
        pop.innerHTML = h || '<p>Nothing matches “' + esc(q) + '”.</p>';
        pop.hidden = false; sel = -1;
      }).catch(function () {});
    };
    inp.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 180); });
    inp.addEventListener('focus', function () { if (pop.innerHTML && inp.value.trim().length > 1) pop.hidden = false; });
    inp.addEventListener('keydown', function (e) {
      var l = links();
      if (e.key === 'ArrowDown') { e.preventDefault(); sel = Math.min(l.length - 1, sel + 1); mark(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); sel = Math.max(0, sel - 1); mark(); }
      else if (e.key === 'Enter') { var a = l[sel >= 0 ? sel : 0]; if (a) { e.preventDefault(); location.href = a.href; } }
      else if (e.key === 'Escape') { pop.hidden = true; inp.blur(); }
    });
    document.addEventListener('click', function (e) { if (!box.contains(e.target)) pop.hidden = true; });
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) { e.preventDefault(); inp.focus(); inp.select(); }
    });
  }

  // ---- Pins ---------------------------------------------------------------------------------
  var pinBtn = document.querySelector('[data-pin-this]');
  var pins = A.pins || [];
  var paint = function () {
    if (!pinBtn) return;
    var on = pins.indexOf(pinBtn.getAttribute('data-href')) >= 0;
    pinBtn.classList.toggle('on', on);
    pinBtn.querySelector('span').textContent = on ? 'Pinned' : 'Pin';
    pinBtn.title = on ? 'Unpin this page' : 'Pin this page to the right column';
  };
  paint();
  if (pinBtn) pinBtn.addEventListener('click', function () {
    var href = pinBtn.getAttribute('data-href'), on = pins.indexOf(href) < 0;
    post('pins.php', { href: href, label: pinBtn.getAttribute('data-label'), on: on ? '1' : '' }).then(function (j) {
      if (j.ok) { pins = j.pins; paint(); location.reload(); }
    });
  });
  document.querySelectorAll('[data-unpin]').forEach(function (b) {
    b.addEventListener('click', function () {
      post('pins.php', { href: b.getAttribute('data-unpin'), on: '' }).then(function (j) { if (j.ok) b.closest('.pin-row').remove(); pins = j.pins || pins; paint(); });
    });
  });

  // ---- Overview -----------------------------------------------------------------------------
  var ov = document.getElementById('ov');
  if (!ov) return;
  var range = 30, last = null, tip = null;
  var set = function (k, v) { var el = ov.querySelector('[data-k="' + k + '"]'); if (el) el.innerHTML = v; };
  var vs = function (now, then, word) {
    if (then == null) return '&nbsp;';
    return fmt(then) + ' ' + word;
  };
  var lists = function (j) {
    ov.querySelectorAll('[data-list]').forEach(function (ul) {
      var rows = j[ul.getAttribute('data-list')];
      if (!rows) return;
      if (!rows.length) { ul.innerHTML = '<li class="ov-wait">Nothing yet.</li>'; return; }
      var max = Math.max.apply(null, rows.map(function (r) { return r.n; })) || 1, bars = ul.hasAttribute('data-bars');
      ul.innerHTML = rows.map(function (r) {
        var name = r.name.replace(/^NVIDIA GeForce /, '').replace(/\(TM\)|\(R\)/g, '');
        if (ul.getAttribute('data-list') === 'os') name = { windows: 'Windows', linux: 'Linux', macos: 'macOS' }[r.name] || r.name;
        return '<li><span title="' + esc(r.name) + '">' + esc(name) + '</span>' + (bars ? '<span class="ov-bar"><i style="width:' + Math.round(r.n / max * 100) + '%"></i></span>' : '') + '<em>' + fmt(r.n) + '</em></li>';
      }).join('');
    });
  };
  var chart = function () {
    var box = ov.querySelector('[data-chart]');
    if (!box || !last || !last.days) return;
    var d = last.days.slice(-range), max = 1;
    d.forEach(function (x) { max = Math.max(max, x.new, x.play, x.del); });
    box.innerHTML = d.map(function (x, i) {
      var h = function (n) { return Math.max(n ? 1 : 0, Math.round(n / max * 100)); };
      return '<div class="ov-day" data-i="' + i + '"><i class="c-new" style="height:' + h(x.new) + '%"></i><i class="c-play" style="height:' + h(x.play) + '%"></i><i class="c-del" style="height:' + h(x.del) + '%"></i></div>';
    }).join('');
    var md = function (s) { var p = s.split('-'); return ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][+p[1] - 1] + ' ' + (+p[2]); };
    ov.querySelector('[data-axis-a]').textContent = md(d[0].d);
    ov.querySelector('[data-axis-b]').textContent = md(d[d.length - 1].d) + ' (today)';
    var sum = function (k) { return d.reduce(function (a, x) { return a + x[k]; }, 0); };
    ov.querySelector('[data-sum]').textContent = range + ' days: ' + fmt(sum('new')) + ' new · ' + fmt(sum('del')) + ' deleted';
    box.querySelectorAll('.ov-day').forEach(function (el) {
      el.addEventListener('mousemove', function (e) {
        var x = d[+el.getAttribute('data-i')];
        if (!tip) { tip = document.createElement('div'); tip.className = 'ov-tip'; document.body.appendChild(tip); }
        tip.innerHTML = '<strong>' + md(x.d) + '</strong><br>' + fmt(x.new) + ' new accounts<br>' + fmt(x.play) + ' players<br>' + fmt(x.del) + ' deleted';
        tip.style.left = (e.clientX + 14) + 'px'; tip.style.top = (e.clientY + 14) + 'px'; tip.hidden = false;
      });
      el.addEventListener('mouseleave', function () { if (tip) tip.hidden = true; });
    });
  };
  ov.querySelectorAll('[data-range] button').forEach(function (b) {
    b.addEventListener('click', function () {
      range = +b.getAttribute('data-n');
      ov.querySelectorAll('[data-range] button').forEach(function (x) { x.classList.toggle('on', x === b); });
      chart();
    });
  });
  var liveEl = ov.querySelector('[data-live]');
  var tick = function () {
    if (document.hidden) return;
    fetch('live.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      last = j;
      set('open', fmt(j.open)); set('playing_s', j.playing != null ? fmt(j.playing) + ' playing a game' : '&nbsp;');
      set('acc_today', fmt(j.acc_today)); set('acc_yday_s', vs(j.acc_today, j.acc_yday, 'yesterday') + (j.del_today ? ' · ' + fmt(j.del_today) + ' deleted today' : ''));
      set('players_today', fmt(j.players_today)); set('players_yday_s', vs(j.players_today, j.players_yday, 'yesterday'));
      set('acc_total', fmt(j.acc_total)); set('acc_ok_s', j.acc_total ? Math.round(j.acc_ok / j.acc_total * 100) + '% verified · ' + fmt(j.seen_today) + ' seen today' : '&nbsp;');
      lists(j); chart();
      liveEl.classList.remove('stale'); liveEl.querySelector('span').textContent = 'Live · ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
    }).catch(function () { liveEl.classList.add('stale'); liveEl.querySelector('span').textContent = 'Reconnecting…'; });
  };
  tick();
  setInterval(tick, 10000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
  ov.querySelectorAll('[data-part]').forEach(function (sec) {
    fetch('dashboard.php?part=' + sec.getAttribute('data-part'), { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) { sec.innerHTML = h; })
      .catch(function () { sec.querySelector('.ov-wait').textContent = 'Couldn\'t load this. Refresh the page.'; });
  });
})();
