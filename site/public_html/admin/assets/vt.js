// (3.8) Security: "Check a link". Sends the link, then asks again every few seconds until every
// file has its VirusTotal answer, and draws the rundown.
(function () {
  'use strict';
  var form = document.querySelector('[data-vt-check]');
  var out = document.querySelector('[data-vt-result]');
  if (!form || !out) return;
  var timer = null;
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var size = function (b) { return !b ? '' : b >= 1073741824 ? (b / 1073741824).toFixed(1) + ' GB' : b >= 1048576 ? Math.round(b / 1048576) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; };
  var ago = function (t) { if (!t) return ''; var d = (Date.now() - new Date(t).getTime()) / 86400000; return d < 1 ? 'today' : d < 2 ? 'yesterday' : Math.round(d) + ' days ago'; };
  var badge = function (r) {
    var labels = { 'new': 'In line', upload: 'Sending to VirusTotal', analyzing: 'Scanning', big: 'Too big to send (650 MB max)', error: 'Couldn\'t check' };
    if (r.status !== 'done') return '<span class="vt-badge grey"><span class="vt-dot">' + (r.status === 'big' || r.status === 'error' ? '–' : '<i class="vt-spin"></i>') + '</span>' + esc(labels[r.status] || r.status) + '</span>';
    var n = r.malicious + r.suspicious;
    var t = r.verdict === 'green' ? 'Clean · 0 of ' + r.engines : n + ' of ' + r.engines + ' engines flagged';
    var i = { green: '✓', yellow: '!', red: '✕' }[r.verdict] || '…';
    return '<a class="vt-badge ' + r.verdict + '" href="' + esc(r.link) + '" target="_blank" rel="noopener"><span class="vt-dot">' + i + '</span>' + esc(t) + '</a>';
  };
  var overall = function (rows, done) {
    if (!rows.length) return ['grey', 'No downloads in this release'];
    if (!done) return ['grey', 'Checking ' + rows.length + (rows.length === 1 ? ' file…' : ' files…')];
    if (rows.some(function (r) { return r.verdict === 'red'; })) return ['red', 'Flagged: look at the report before adding it'];
    if (rows.some(function (r) { return r.verdict === 'yellow'; })) return ['yellow', 'Mostly clean: a few engines flag it (common for unsigned programs)'];
    if (rows.some(function (r) { return r.status !== 'done'; })) return ['grey', 'Some files couldn\'t be checked'];
    return ['green', 'Clean'];
  };
  var draw = function (j) {
    var info = j.info || {};
    var o = overall(j.rows || [], j.done);
    var notes = [];
    (j.where || []).forEach(function (w) { notes.push('<span class="vt-note ok">Already listed · ' + esc(w) + '</span>'); });
    if (j.repo && !(j.where || []).length) notes.push('<span class="vt-note">Not in either store yet</span>');
    if ((j.flags || []).indexOf('gamefiles') >= 0) notes.push('<span class="vt-note warn">Has a file that looks like game data</span>');
    if ((j.flags || []).indexOf('huge') >= 0) notes.push('<span class="vt-note warn">Has a file over 3 GB</span>');
    if (j.pre) notes.push('<span class="vt-note warn">Newest release is a pre-release</span>');
    if (info.archived) notes.push('<span class="vt-note warn">Repo is archived</span>');
    var facts = j.repo ? [['Release', esc(j.tag || 'none') + (j.when ? ' · ' + ago(j.when) : '')], ['Stars', esc(info.stars != null ? info.stars : '?')],
      ['Releases', esc(j.releases || 0)], ['Owner', esc(info.owner || j.repo.split('/')[0])]] : [];
    out.innerHTML = '<div class="vt-sum"><div><h3>' + esc(j.repo || (j.files || [])[0] || 'File') + '</h3><small>' + esc(info.description || '') + '</small></div>' +
      '<span class="vt-big ' + o[0] + '">' + (j.done ? '' : '<i class="vt-spin"></i>') + esc(o[1]) + '</span></div>' +
      (facts.length ? '<dl class="vt-facts">' + facts.map(function (f) { return '<div><dt>' + f[0] + '</dt><dd>' + f[1] + '</dd></div>'; }).join('') + '</dl>' : '') +
      (notes.length ? '<div class="vt-notes">' + notes.join('') + '</div>' : '') +
      '<div class="vt-list">' + (j.rows || []).map(function (r) {
        return '<div class="vt-row ' + (r.status === 'done' ? r.verdict : 'grey') + '"><div class="vt-what"><strong>' + esc(r.name) + '</strong><small>' + esc(size(r.size)) + '</small>' +
          (r.flagged_by ? '<small class="vt-engines">' + esc(r.flagged_by) + '</small>' : '') + (r.err && r.status !== 'done' ? '<small class="vt-err">' + esc(r.err) + '</small>' : '') +
          '</div><div class="vt-src"></div><div class="vt-res">' + badge(r) + '</div><div class="vt-acts"></div></div>';
      }).join('') + '</div>';
    out.hidden = false;
  };
  var poll = function (j, n) {
    clearTimeout(timer);
    if (j.done || n > 60) return;
    timer = setTimeout(function () {
      var q = new URLSearchParams({ a: 'poll', repo: j.repo || '', tag: j.tag || '' });
      (j.files || []).forEach(function (f) { q.append('f[]', f); });
      fetch('security.php?' + q, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (k) {
        j.rows = k.rows; j.done = k.done; draw(j); poll(j, n + 1);
      }).catch(function () { poll(j, n + 1); });
    }, n < 6 ? 5000 : 15000);
  };
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var url = form.url.value.trim();
    if (!url) return;
    clearTimeout(timer);
    out.hidden = false;
    out.innerHTML = '<div class="vt-sum"><div><h3>Looking it up…</h3><small>' + esc(url) + '</small></div><span class="vt-big grey"><i class="vt-spin"></i>Reading GitHub</span></div>';
    fetch('security.php?a=check&url=' + encodeURIComponent(url), { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { out.innerHTML = '<p class="vt-empty">' + esc(j.error || 'That didn\'t work.') + '</p>'; return; }
      draw(j);
      poll(j, 0);
    }).catch(function () { out.innerHTML = '<p class="vt-empty">The server didn\'t answer. Try again in a moment.</p>'; });
  });
})();
