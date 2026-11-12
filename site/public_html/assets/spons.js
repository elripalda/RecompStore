// RecompLauncher (4.8): sponsor spots. Asks once what's booked; with nothing booked it does nothing at
// all. Spots: side rails in the empty space beside the page (wide screens only), a banner under the
// featured games / above All Recomps, and a box beside a game page's download box.
(function () {
  'use strict';
  if (!/(^|\.)recomplauncher\./i.test(location.hostname) && location.pathname.indexOf('/pc') !== 0) return;
  var KEY = 'rl-spons', live = null, seen = {}, styled = false;
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var get = function (slot) { for (var i = 0; i < live.length; i++) if (live[i].slot === slot) return live[i]; return null; };
  var tile = function (s, cls) {
    return '<a class="sp ' + cls + '" href="/api/spons.php?go=' + s.id + '" target="_blank" rel="sponsored noopener" data-sp="' + s.id + '">'
      + '<img src="' + esc(s.img) + '" alt="' + esc(s.alt) + '" loading="lazy" decoding="async"><span class="sp-tag">' + (s.house ? 'From us' : 'Sponsored') + '</span></a>';
  };
  function style() {
    if (styled) return; styled = true;
    var css = '.sp{position:relative;display:block;border-radius:14px;overflow:hidden;border:1px solid rgba(244,248,252,.1);background:rgba(244,248,252,.04);transition:border-color .15s}'
      + '.sp:hover{border-color:rgba(244,248,252,.28)}.sp img{display:block;width:100%;height:auto}'
      + '.sp-tag{position:absolute;right:8px;bottom:8px;font:600 10px/1 Figtree,system-ui,sans-serif;letter-spacing:.04em;text-transform:uppercase;color:rgba(244,248,252,.8);background:rgba(11,18,32,.72);padding:4px 6px;border-radius:5px}'
      + '.sp-rail{position:fixed;top:96px;z-index:5;display:none}.sp-rail.on{display:block}'
      + '.sp-banner{margin:28px 0 8px}.sp-banner img{max-height:180px;object-fit:cover}.sp-game{margin-top:16px}.sp-game img{max-height:500px;object-fit:cover}.sp-rail img{max-height:calc(100vh - 140px);object-fit:cover}';
    var el = document.createElement('style'); el.textContent = css; document.head.appendChild(el);
  }
  // Views: once per spot per page, sent together.
  var queue = [], timer = 0;
  function count(id) {
    if (seen[id]) return; seen[id] = 1; queue.push(id);
    clearTimeout(timer);
    timer = setTimeout(function () {
      var u = '/api/spons.php?seen=' + queue.join(','); queue = [];
      if (navigator.sendBeacon) navigator.sendBeacon(u); else fetch(u, { method: 'POST', keepalive: true });
    }, 800);
  }
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { count(+e.target.getAttribute('data-sp')); io.unobserve(e.target); } });
  }, { threshold: 0.5 }) : null;
  function watch(el) { if (io) io.observe(el); else count(+el.getAttribute('data-sp')); }

  // Side rails: only where the gap beside the page fits one.
  var rails = null;
  function placeRails() {
    var s = get('rails'), view = document.getElementById('view');
    if (!s || !view) return;
    if (!rails) {
      style();
      rails = [0, 1].map(function () { var d = document.createElement('div'); d.className = 'sp-rail'; d.innerHTML = tile(s, ''); document.body.appendChild(d); return d; });
    }
    var inner = view.querySelector('article, .page') || view, r = inner.getBoundingClientRect();
    var gap = Math.min(r.left, window.innerWidth - r.right) - 48;
    var w = Math.min(300, Math.floor(gap));
    rails.forEach(function (d, i) {
      if (w < 160 || window.innerHeight < 520) { d.classList.remove('on'); return; }
      d.style.width = w + 'px';
      d.style.left = (i === 0 ? Math.round(r.left - 24 - w) : Math.round(r.right + 24)) + 'px';
      if (!d.classList.contains('on')) { d.classList.add('on'); watch(d.firstChild); }
    });
  }
  // The banner and the game page box go into the page each time it's drawn.
  function placeInPage() {
    var view = document.getElementById('view');
    if (!view) return;
    var b = get('banner');
    if (b && !view.querySelector('.sp-banner')) {
      var after = view.querySelector('.pc-slider') || view.querySelector('.pc-all .pc-filters');
      if (after) { style(); after.insertAdjacentHTML('afterend', '<div class="sp-banner">' + tile(b, '') + '</div>'); watch(view.querySelector('.sp-banner .sp')); }
    }
    var g = get('game');
    if (g && !view.querySelector('.sp-game')) {
      var panel = view.querySelector('.pc-side > .panel');
      if (panel) { style(); panel.insertAdjacentHTML('afterend', '<div class="sp-game">' + tile(g, '') + '</div>'); watch(view.querySelector('.sp-game .sp')); }
    }
  }
  function start() {
    if (!live.length) return;
    var view = document.getElementById('view'), t = 0;
    var run = function () { clearTimeout(t); t = setTimeout(function () { seen = {}; placeInPage(); placeRails(); }, 120); };
    run();
    if (view) new MutationObserver(run).observe(view, { childList: true });
    window.addEventListener('resize', function () { requestAnimationFrame(placeRails); });
  }
  // What's booked: kept for 2 minutes in this tab so moving between pages asks nothing.
  try { var c = JSON.parse(sessionStorage.getItem(KEY) || 'null'); if (c && Date.now() - c.t < 120000) live = c.s; } catch (e) { /* no storage */ }
  if (live) { start(); return; }
  fetch('/api/spons.php', { credentials: 'omit' }).then(function (r) { return r.json(); }).then(function (j) {
    live = Array.isArray(j.s) ? j.s : [];
    try { sessionStorage.setItem(KEY, JSON.stringify({ t: Date.now(), s: live })); } catch (e) { /* no storage */ }
    start();
  }).catch(function () { /* no sponsors this time */ });
})();
