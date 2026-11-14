/* RecompStore admin (4.10): PC recomps > Emergency backup. The games list: search, filter, sort, pick a batch. */
(function () {
  'use strict';
  var el = document.getElementById('bk-data');
  if (!el) return;
  var D = JSON.parse(el.textContent);
  var list = D.list, OS = D.os, DOWN = D.down, TAKE = D.takedown;
  var OS_SHORT = { windows: 'Win', linux: 'Linux', macos: 'Mac' };
  var picked = {};
  var shown = 60;
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var size = function (b) { return !b ? '—' : b >= 1e9 ? (b / 1e9).toFixed(1) + ' GB' : Math.max(1, Math.round(b / 1e6)) + ' MB'; };
  var thumb = function (u) {
    var m = /\/media\/([A-Za-z0-9][A-Za-z0-9._-]{0,150}\.(?:jpe?g|png|webp))$/i.exec(u || '');
    return m ? 'https://recomplauncher.com/api/thumb.php?f=' + encodeURIComponent(m[1]) + '&w=160' : u;
  };
  var ago = function (s) {
    if (!s) return '';
    var t = Date.parse(s.replace(' ', 'T') + 'Z'), d = (Date.now() - t) / 1000;
    if (!t) return '';
    if (d < 3600) return Math.max(1, Math.round(d / 60)) + ' min ago';
    if (d < 86400) return Math.round(d / 3600) + ' h ago';
    return Math.round(d / 86400) + ' d ago';
  };

  // Where each game stands, for the filter and the pill.
  function state(x) {
    var r = x.r, down = DOWN.indexOf(x.w) >= 0;
    var has = r && r.have && r.have.length;
    if (r && r.a) return { k: 'on', t: 'On its backup', c: 'on', d: (r.srv ? r.srv + (r.srv === 1 ? ' download' : ' downloads') : 'switched') + (x.w === 'ok' || !x.w ? ' · repo is up again' : '') };
    if (r && r.st === 'working') return { k: 'tracked', t: 'Copying', c: 'run', d: '' };
    if (r && r.st === 'error') return { k: 'err', t: 'Failed', c: 'bad', d: r.e + (has ? ' · older copy kept (' + r.v + ')' : '') };
    if (has && r.t && r.v !== x.lv && x.lv) return { k: 'old', t: 'Update waiting', c: 'warn', d: 'has ' + r.v + ', live is ' + x.lv };
    if (has) return { k: 'ok', t: 'Backed up', c: 'ok', d: (r.v ? r.v + ' · ' : '') + ago(r.at) + (r.t ? '' : ' · not tracked') };
    if (r && r.t) return { k: 'tracked', t: 'In line', c: 'wait', d: r.p === 'auto' ? 'picked automatically' : '' };
    return { k: 'none', t: down ? 'Repo down, no copy' : 'Not backed up', c: down ? 'bad' : '', d: '' };
  }

  function matches(x, f, q) {
    if (q && (x.n + ' ' + x.id).toLowerCase().indexOf(q) < 0) return false;
    var s = state(x).k;
    if (f === 'all') return true;
    if (f === 'tracked') return !!(x.r && x.r.t);
    if (f === 'down') return DOWN.indexOf(x.w) >= 0;
    if (f === 'ok') return s === 'ok' || s === 'old' || s === 'on';
    return s === f;
  }

  function view() {
    var q = $('bk-q').value.trim().toLowerCase(), f = $('bk-f').value, s = $('bk-s').value;
    var v = list.filter(function (x) { return matches(x, f, q); });
    v.sort(function (a, b) {
      if (s === 'az') return a.n.localeCompare(b.n);
      if (s === 'big') return (b.r && b.r.b || b.sz) - (a.r && a.r.b || a.sz);
      if (s === 'new') return String(b.r && b.r.at || '').localeCompare(String(a.r && a.r.at || ''));
      return b.pl - a.pl || a.n.localeCompare(b.n);
    });
    return v;
  }

  function row(x) {
    var st = state(x), r = x.r;
    var sys = Object.keys(OS).map(function (k) {
      var has = x.av.indexOf(k) >= 0, kept = r && r.have && r.have.indexOf(k) >= 0, want = r && r.t && r.os.indexOf(k) >= 0;
      return '<span class="bk-os' + (kept ? ' kept' : want && has ? ' want' : '') + (has ? '' : ' none') + '" title="' + esc(OS[k]) + (has ? (kept ? ': backed up' : want ? ': will be backed up' : '') : ': no download') + '">' + OS_SHORT[k] + '</span>';
    }).join('');
    var act = '';
    if (r && r.a) act = '<button type="button" class="small" data-one="deactivate">Back to the repo</button>';
    else if (r && r.have && r.have.length && TAKE.indexOf(x.w) < 0 && DOWN.indexOf(x.w) >= 0) act = '<button type="button" class="small primary" data-one="activate" data-confirm="Click again to switch">Use backup</button>';
    return '<div class="bk-tr' + (picked[x.id] ? ' is-picked' : '') + '" role="row" data-id="' + esc(x.id) + '">'
      + '<label class="bk-c-ck"><input type="checkbox" data-pick' + (picked[x.id] ? ' checked' : '') + '></label>'
      + '<span class="bk-game">' + (x.ic ? '<img src="' + esc(thumb(x.ic)) + '" alt="" loading="lazy" width="36" height="36">' : '<i class="bk-noic"></i>')
      + '<span><b>' + esc(x.n) + '</b><small>' + esc(x.id) + (x.pl ? ' · ' + x.pl.toLocaleString() + ' players' : '') + (x.hid ? ' · hidden' : '') + '</small></span></span>'
      + '<span class="bk-sys">' + sys + '</span>'
      + '<span class="bk-size">' + size(r && r.b || x.sz) + '</span>'
      + '<span class="bk-st"><span class="bk-pill ' + st.c + '">' + esc(st.t) + '</span>' + (st.d ? '<small title="' + esc(st.d) + '">' + esc(st.d) + '</small>' : '') + '</span>'
      + '<span class="bk-act">' + act + '</span></div>';
  }

  function draw() {
    var v = view();
    $('bk-rows').innerHTML = v.slice(0, shown).map(row).join('') || '<p class="muted bk-empty">Nothing here.</p>';
    $('bk-more').hidden = v.length <= shown;
    $('bk-count').textContent = v.length.toLocaleString() + (v.length === 1 ? ' game' : ' games');
    var all = $('bk-all'), vis = v.slice(0, shown);
    all.checked = vis.length > 0 && vis.every(function (x) { return picked[x.id]; });
    bulk();
  }

  function bulk() {
    var ids = Object.keys(picked);
    $('bk-bulk').hidden = !ids.length;
    $('bk-sel').textContent = ids.length + ' picked';
    $('bk-ids').value = ids.join(',');
  }

  // Two clicks instead of a dialog.
  function confirmed(b) {
    var c = b.getAttribute('data-confirm');
    if (!c || b.hasAttribute('data-armed')) return true;
    b.setAttribute('data-armed', b.textContent);
    b.textContent = c;
    setTimeout(function () { if (b.isConnected && b.hasAttribute('data-armed')) { b.textContent = b.getAttribute('data-armed'); b.removeAttribute('data-armed'); } }, 4000);
    return false;
  }

  ['bk-q', 'bk-f', 'bk-s'].forEach(function (id) { $(id).addEventListener('input', function () { shown = 60; draw(); }); });
  $('bk-more').addEventListener('click', function () { shown += 120; draw(); });
  $('bk-all').addEventListener('change', function () {
    var on = this.checked;
    view().slice(0, shown).forEach(function (x) { if (on) picked[x.id] = 1; else delete picked[x.id]; });
    draw();
  });
  $('bk-clear').addEventListener('click', function () { picked = {}; draw(); });
  $('bk-rows').addEventListener('change', function (e) {
    var t = e.target;
    if (!t.hasAttribute('data-pick')) return;
    var id = t.closest('[data-id]').getAttribute('data-id');
    if (t.checked) picked[id] = 1; else delete picked[id];
    t.closest('.bk-tr').classList.toggle('is-picked', t.checked);
    bulk();
  });
  $('bk-rows').addEventListener('click', function (e) {
    var b = e.target.closest('[data-one]');
    if (!b || !confirmed(b)) return;
    var f = $('bk-one');
    f.elements.action.value = b.getAttribute('data-one');
    f.elements.ids.value = b.closest('[data-id]').getAttribute('data-id');
    f.submit();
  });
  $('bk-bulk').addEventListener('click', function (e) {
    var b = e.target.closest('button[data-confirm]');
    if (b && !confirmed(b)) e.preventDefault();
  });
  draw();
})();
