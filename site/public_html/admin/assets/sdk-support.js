// (4.8) SDK support: the table, drawn from the page's data 100 rows at a time, the picked recomp on
// the side, and the live progress while recomps are being checked. Filtering, sorting and paging
// only touch the rows on screen, so a catalog of thousands stays quick.
(function () {
  'use strict';
  var dataEl = document.getElementById('sdk-data');
  if (!dataEl) return;
  var D = JSON.parse(dataEl.textContent);
  var PER = 100;
  var STATE = { ships: ['Ships its own file', 'ok'], pr: ['Pull request open', 'blue'], no: ['Won\'t support it', 'warn'], locked: ['Pull requests closed', 'violet'], missing: ['No file yet', 'bad'], unknown: ['Couldn\'t check yet', ''] };
  var NOW = {
    json: ['Its file, in our catalog', 'The developer\'s recomplauncher.json is pasted into the catalog. Shipping it in the release keeps it up to date.'],
    profile: ['Our setup profile', 'Easy Setup works from a profile we wrote. The developer\'s own file would be better.'],
    steps: ['Setup steps only', 'Players follow written steps before the first play.'],
    none: ['Nothing yet', 'Players set it up by hand.']
  };
  var ORDER = { missing: 0, unknown: 1, pr: 2, locked: 3, no: 4, ships: 5 };

  var byId = {};
  var rows = D.rows;
  rows.forEach(function (r) { byId[r.id] = r; });
  var st = { state: '', readme: '', kind: '', q: '', sort: 'rank', page: 1, sel: '' };
  var picked = {};
  var since = D.now;
  var shown = [];

  var $ = function (id) { return document.getElementById(id); };
  var body = $('sdk-body'), side = $('sdk-side'), find = $('sdk-find'), readme = $('sdk-readme'), kind = $('sdk-kind'), sortSel = $('sdk-sort');
  var pager = $('sdk-pager'), pageT = $('sdk-page-t'), shownT = $('sdk-shown'), clearB = $('sdk-clear');
  var pickAll = $('sdk-pick-all'), pickBar = $('sdk-picked'), pickN = $('sdk-picked-n');
  var prog = $('sdk-prog'), progT = $('sdk-prog-t');
  var toast = $('sdk-toast'), toastT = 0;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fmt(n) { return Number(n || 0).toLocaleString(); }
  function label(r) {
    var s = STATE[r.state] || STATE.unknown;
    if (r.state === 'pr' && r.pr) return r.pr === 'merged' ? ['Pull request merged', 'ok'] : (r.pr === 'closed' ? ['Pull request closed', 'warn'] : ['Pull request open', 'blue']);
    if (r.state === 'unknown' && r.waiting) return ['Not checked yet', ''];
    return s;
  }
  function badgeNames(r) { return (r.badges || []).map(function (b) { return D.badgeNames[b] || b; }).join(', '); }
  function star(r) {
    if (r.badge) return '<span class="sdk-star" title="README has the ' + esc(badgeNames(r)) + ' badge">★</span>';
    if (r.link) return '<span class="sdk-star soft" title="No badge, but its README links to us">☆</span>';
    return '';
  }

  // ---- what the filters show ----
  try {
    var saved = JSON.parse(sessionStorage.getItem('sdk-view2') || 'null');
    if (saved) { st.state = saved.state || ''; st.readme = saved.readme || ''; st.kind = saved.kind || ''; st.sort = saved.sort || 'rank'; }
  } catch (e) { /* no saved view */ }
  function save() { try { sessionStorage.setItem('sdk-view2', JSON.stringify({ state: st.state, readme: st.readme, kind: st.kind, sort: st.sort })); } catch (e) { /* fine */ } }

  function matches(r, q) {
    if (st.state && r.state !== st.state) return false;
    if (st.kind && r.kind !== st.kind) return false;
    if (st.readme === 'badge' && !r.badge) return false;
    if (st.readme === 'link' && (r.badge || !r.link)) return false;
    if (st.readme === 'bare' && !r.bare) return false;
    if (q && (r.name + ' ' + r.game + ' ' + r.repo + ' ' + r.id).toLowerCase().indexOf(q) === -1) return false;
    return true;
  }
  function query() {
    var q = st.q.toLowerCase().trim();
    // A pasted GitHub link filters by its owner/name.
    var m = q.match(/^(?:https?:\/\/)?(?:www\.)?github\.com\/([\w.-]+\/[\w.-]+)/);
    if (m) q = m[1].replace(/\.git$/, '');
    return q;
  }
  function compute() {
    var q = query();
    shown = rows.filter(function (r) { return matches(r, q); });
    var dir = st.sort === 'name' ? 1 : 1;
    shown.sort(function (a, b) {
      if (st.sort === 'name') return a.name.localeCompare(b.name) * dir;
      if (st.sort === 'state') return (ORDER[a.state] - ORDER[b.state]) || (a.rank - b.rank);
      return a.rank - b.rank;
    });
    var pages = Math.max(1, Math.ceil(shown.length / PER));
    if (st.page > pages) st.page = pages;
  }

  // ---- drawing ----
  function rowHtml(r) {
    var l = label(r);
    var on = r.id === st.sel ? ' class="on"' : '';
    var ico = r.icon ? '<img src="' + esc(r.icon) + '" alt="" width="28" height="28" loading="lazy" decoding="async">' : '<span class="sdk-ico">' + esc(r.name.slice(0, 2)) + '</span>';
    return '<tr data-id="' + esc(r.id) + '"' + on + '>'
      + '<td class="c-pick"><input type="checkbox" class="sdk-pick" value="' + esc(r.id) + '"' + (picked[r.id] ? ' checked' : '') + ' aria-label="Pick ' + esc(r.name) + '"></td>'
      + '<td class="c-rank">' + r.rank + '</td>'
      + '<td><div class="sdk-who">' + ico + '<div class="sdk-name"><span class="sdk-title">' + star(r) + esc(r.name) + '</span><span class="muted small">' + esc(r.repo) + (r.kind === 'pc' ? ' · PC game' : '') + '</span></div></div></td>'
      + '<td><span class="pill ' + l[1] + '">' + esc(l[0]) + '</span>' + (r.bare ? '<span class="sdk-bare" title="Ask them to add the badge, or at least a link">No badge or link</span>' : '') + '</td>'
      + '<td class="c-num">' + fmt(r.dl) + '</td></tr>';
  }
  function draw() {
    var start = (st.page - 1) * PER, page = shown.slice(start, start + PER);
    body.innerHTML = page.length ? page.map(rowHtml).join('') : '<tr class="sdk-skel"><td colspan="5">Nothing matches. <a href="sdk-support.php?tab=lookup&amp;q=' + encodeURIComponent(st.q.trim()) + '">Look it up on GitHub</a></td></tr>';
    var filtered = shown.length < rows.length;
    shownT.textContent = filtered ? fmt(shown.length) + ' of ' + fmt(rows.length) + ' recomps' : fmt(rows.length) + ' recomps';
    clearB.hidden = !filtered;
    var pages = Math.ceil(shown.length / PER);
    pager.hidden = pages <= 1;
    pageT.textContent = shown.length ? fmt(start + 1) + '–' + fmt(start + page.length) + ' of ' + fmt(shown.length) : '';
    pager.querySelector('[data-page="-1"]').disabled = st.page <= 1;
    pager.querySelector('[data-page="1"]').disabled = st.page >= pages;
    document.querySelectorAll('.sdk-kpi').forEach(function (b) { var on = b.dataset.state === st.state; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    readme.value = st.readme; kind.value = st.kind; sortSel.value = st.sort;
    $('sdk-all-b').textContent = filtered ? 'Check shown again' : 'Check all again';
    $('sdk-all-b').title = filtered ? 'Checks the ' + fmt(shown.length) + ' recomps the filters show' : '';
    drawPicked();
  }
  function refresh(keepPage) { if (!keepPage) st.page = 1; compute(); draw(); }

  function drawPicked() {
    var ids = Object.keys(picked);
    pickBar.hidden = !ids.length;
    pickN.textContent = fmt(ids.length) + ' picked';
    var inView = shown.filter(function (r) { return picked[r.id]; }).length;
    pickAll.checked = shown.length > 0 && inView === shown.length;
    pickAll.indeterminate = inView > 0 && inView < shown.length;
  }

  // The picked recomp's panel: where it stands, then what to do next.
  function sideHtml(r) {
    var l = label(r), now = NOW[r.now] || NOW.none, mk = r.mark;
    var promptBtn = function (cls) { return '<button type="button" class="btn' + cls + '" data-prompt-repo="' + esc(r.repo) + '" data-prompt-url="' + esc(r.gh) + '" data-prompt-id="' + esc(r.id) + '" data-prompt-game="' + esc(r.game) + '">Copy agent prompt</button>'; };
    var badgePrBtn = function (cls) { return '<button type="button" class="btn' + cls + '" data-badgepr-repo="' + esc(r.repo) + '" data-badgepr-id="' + esc(r.id) + '" data-badgepr-game="' + esc(r.name) + '">Badge PR prompt</button>'; };
    var hasLauncherBadge = (r.badges || []).indexOf('launcher') !== -1;
    var main = '';
    if (r.state === 'ships') main = hasLauncherBadge ? '' : badgePrBtn(' primary');
    else if (r.state === 'pr' && mk && mk.url) main = '<a class="btn primary" href="' + esc(mk.url) + '" target="_blank" rel="noopener">Open the pull request ↗</a>';
    else if (r.state !== 'locked') main = promptBtn(' primary');
    var readmeTxt = r.badge ? '★ ' + badgeNames(r) + ' badge' : (r.link ? '☆ Links to us, no badge' : (r.badge === false ? 'No badge or link' : 'Not checked yet'));
    var ico = r.icon ? '<img src="' + esc(r.icon) + '" alt="" width="44" height="44">' : '<span class="sdk-ico big">' + esc(r.name.slice(0, 2)) + '</span>';
    // The buttons under the main one go two to a row; an odd last one takes the whole row.
    var more = ['<a class="btn" href="' + esc(r.gh) + '" target="_blank" rel="noopener">GitHub ↗</a>'];
    if (r.state !== 'ships') more.push('<button type="button" class="btn" data-badge-id="' + esc(r.id) + '">Copy badge</button>');
    if (r.state !== 'ships' && !hasLauncherBadge) more.push(badgePrBtn(''));
    if (r.state === 'pr') more.push(promptBtn(''));
    more.push('<button type="button" class="btn" data-act="recheck">Check again</button>');
    if (mk) more.push('<button type="button" class="btn" data-act="unmark">Clear the mark</button>');
    more.push('<a class="btn" href="pc.php?edit=' + encodeURIComponent(r.id) + '">Edit listing</a>');
    if (more.length % 2) more[more.length - 1] = more[more.length - 1].replace('class="btn"', 'class="btn wide"');
    var h = '<div class="sdk-side-who">' + ico + '<div><strong>' + esc(r.name) + '</strong><a class="muted small" href="' + esc(r.gh) + '" target="_blank" rel="noopener">' + esc(r.repo) + ' ↗</a></div></div>'
      + '<p><span class="pill ' + l[1] + '">' + esc(l[0]) + '</span></p>'
      + '<div class="kv"><span>Easy Setup now</span><span title="' + esc(now[1]) + '">' + esc(now[0]) + (r.dlss ? ' · DLSS' : '') + '</span></div>'
      + '<div class="kv"><span>README</span><span>' + esc(readmeTxt) + '</span></div>'
      + '<div class="kv"><span>Downloads</span><span>' + fmt(r.dl) + ' · #' + r.rank + '</span></div>'
      + (mk ? '<div class="kv"><span>Marked</span><span>' + esc(mk.who) + ', ' + esc(mk.at) + (r.state === 'no' && mk.url ? ' · <a href="' + esc(mk.url) + '" target="_blank" rel="noopener">where ↗</a>' : '') + '</span></div>' : '')
      + '<div class="sdk-side-acts">' + main + more.join('') + '</div>';
    if (!mk && r.state !== 'ships') {
      h += '<details class="sdk-marks"><summary>Mark it</summary>'
        + '<form data-act="mark_pr"><label>A pull request is open<input name="url" required placeholder="https://github.com/' + esc(r.repo) + '/pull/…" spellcheck="false"></label><button class="btn">Save</button></form>'
        + '<form data-act="mark_no"><label>The developer doesn\'t want it<input name="url" placeholder="Link where they said so (optional)" spellcheck="false"></label><button class="btn danger">Mark</button></form>'
        + '<form data-act="mark_locked"><p class="muted small">The repo takes no pull requests or issues, so nothing can be sent.</p><button class="btn">Mark: pull requests closed</button></form>'
        + '</details>';
    }
    return h;
  }
  function drawSide() {
    var r = byId[st.sel];
    if (!r) { side.innerHTML = '<h2>Pick a recomp</h2><p class="muted">Click a row to see where it stands and what to send its developer: the agent prompt, the badge, Check again and the marks.</p>'; return; }
    side.innerHTML = sideHtml(r);
  }
  function select(id) {
    st.sel = id;
    body.querySelectorAll('tr.on').forEach(function (tr) { tr.classList.remove('on'); });
    var tr = body.querySelector('tr[data-id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
    if (tr) tr.classList.add('on');
    drawSide();
    try { history.replaceState(null, '', id ? '?id=' + encodeURIComponent(id) : location.pathname); } catch (e) { /* fine */ }
  }

  // ---- the counts and progress ----
  function counts(c) {
    document.querySelectorAll('[data-n]').forEach(function (el) { if (c[el.dataset.n] !== undefined) el.textContent = fmt(c[el.dataset.n]); });
    if (!prog) return;
    if (c.todo > 0) { prog.hidden = false; progT.textContent = 'Checking ' + fmt(c.all - c.todo) + ' of ' + fmt(c.all); }
    else if (!prog.hidden) { progT.textContent = 'All ' + fmt(c.all) + ' checked'; setTimeout(function () { prog.hidden = true; }, 4000); }
  }
  function say(msg, err) {
    if (!msg) return;
    toast.textContent = msg; toast.className = 'sdk-toast' + (err ? ' err' : ''); toast.hidden = false;
    clearTimeout(toastT); toastT = setTimeout(function () { toast.hidden = true; }, err ? 7000 : 4000);
  }
  function putRow(r) {
    if (!r || !byId[r.id]) return;
    var i = rows.indexOf(byId[r.id]);
    rows[i] = r; byId[r.id] = r;
  }
  var polling = 0;
  function poll() {
    if (polling) return;
    polling = setTimeout(function tick() {
      fetch('sdk-support.php?progress=1&since=' + since, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (j) {
          (j.rows || []).forEach(putRow);
          if ((j.rows || []).length) { refresh(true); if (st.sel) drawSide(); }
          since = j.now - 1;
          counts(j.count);
          polling = j.count.todo > 0 ? setTimeout(tick, document.hidden ? 20000 : 6000) : 0;
        })
        .catch(function () { polling = setTimeout(tick, 20000); });
    }, 5000);
  }

  // ---- actions (no page reload) ----
  function post(fields, btn) {
    var fd = new FormData();
    fd.append('csrf', D.csrf); fd.append('ajax', '1');
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    var was = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = fields.action === 'recheck' ? 'Checking…' : 'Saving…'; }
    return fetch('sdk-support.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (res) { if (!res.ok) throw new Error(res.status === 400 ? 'This page expired. Reload it and try again.' : 'Something went wrong. Try again.'); return res.json(); })
      .then(function (j) {
        if (j.row) putRow(j.row);
        if (j.now) since = Math.min(since, j.now - 1);
        counts(j.count);
        refresh(true);
        if (st.sel) drawSide();
        say(j.msg, !j.ok);
        if (j.count.todo > 0) poll();
        return j;
      })
      .catch(function (err) { say(err.message, true); })
      .then(function (j) { if (btn && document.body.contains(btn)) { btn.disabled = false; btn.textContent = was; } return j; });
  }
  // Draft now goes through the page itself: the server starts the drafter and opens the PR queue.
  function draftNow(ids) {
    var f = document.createElement('form');
    f.method = 'post'; f.action = 'sdk-support.php'; f.hidden = true;
    [['csrf', D.csrf], ['action', 'prq_queue'], ['ids', ids.join(',')]].forEach(function (p) { var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i); });
    document.body.appendChild(f); f.submit();
  }

  // ---- events ----
  document.getElementById('sdk-kpis').addEventListener('click', function (e) {
    var b = e.target.closest('.sdk-kpi'); if (!b) return;
    st.state = st.state === b.dataset.state ? '' : b.dataset.state; save(); refresh();
  });
  var findT = 0;
  find.addEventListener('input', function () { clearTimeout(findT); findT = setTimeout(function () { st.q = find.value; refresh(); }, 120); });
  find.addEventListener('keydown', function (e) { if (e.key === 'Escape' && find.value) { e.preventDefault(); find.value = ''; st.q = ''; refresh(); } });
  readme.addEventListener('change', function () { st.readme = readme.value; save(); refresh(); });
  kind.addEventListener('change', function () { st.kind = kind.value; save(); refresh(); });
  sortSel.addEventListener('change', function () { st.sort = sortSel.value; save(); refresh(); });
  clearB.addEventListener('click', function () { st.state = ''; st.readme = ''; st.kind = ''; st.q = ''; find.value = ''; save(); refresh(); });
  pager.addEventListener('click', function (e) {
    var b = e.target.closest('[data-page]'); if (!b) return;
    st.page += Number(b.dataset.page); draw();
    document.querySelector('.sdk-scroll').scrollTop = 0;
  });
  body.addEventListener('click', function (e) {
    if (e.target.closest('a, input, button')) return;
    var tr = e.target.closest('tr[data-id]'); if (tr) select(tr.dataset.id);
  });
  body.addEventListener('change', function (e) {
    var c = e.target; if (!c.classList.contains('sdk-pick')) return;
    if (c.checked) picked[c.value] = 1; else delete picked[c.value];
    drawPicked();
  });
  pickAll.addEventListener('change', function () {
    shown.forEach(function (r) { if (pickAll.checked) picked[r.id] = 1; else delete picked[r.id]; });
    draw();
  });
  pickBar.addEventListener('click', function (e) {
    var b = e.target.closest('[data-bulk]'); if (!b) return;
    var ids = Object.keys(picked);
    if (b.dataset.bulk === 'clear') { picked = {}; draw(); return; }
    if (b.dataset.bulk === 'recheck') { post({ action: 'recheck_ids', ids: ids.join(',') }, b); return; }
    if (b.dataset.bulk === 'draft' && (ids.length <= 25 || confirm('Draft pull requests for ' + fmt(ids.length) + ' recomps? Nothing is sent until you click Send in the PR queue.'))) draftNow(ids);
  });
  $('sdk-all-b').addEventListener('click', function () {
    var filtered = shown.length < rows.length;
    post({ action: 'recheck_all', ids: filtered ? shown.map(function (r) { return r.id; }).join(',') : '' }, this);
  });
  side.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-act]'); if (!b || !st.sel) return;
    post({ action: b.dataset.act, id: st.sel }, b);
  });
  side.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-act]'); if (!f || !st.sel) return;
    e.preventDefault();
    var fields = { action: f.dataset.act, id: st.sel };
    if (f.url) fields.url = f.url.value;
    post(fields, f.querySelector('button'));
  });

  // ---- start ----
  var want = new URLSearchParams(location.search).get('id') || '';
  if (want && byId[want]) {
    // Opened from Look up or after an action: make sure its row is on screen.
    st.state = ''; st.readme = ''; st.kind = ''; st.q = '';
    compute();
    st.page = Math.floor(shown.indexOf(byId[want]) / PER) + 1;
    draw(); select(want);
    var tr = body.querySelector('tr.on'); if (tr) tr.scrollIntoView({ block: 'center' });
  } else {
    refresh();
  }
  if (D.count.todo > 0) poll();
})();
