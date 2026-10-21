// Recomp Store admin: the Analytics, App stats and Dashboard pages. Day-by-day line charts and
// bar charts (2.3): one y-axis, a legend when there are two series, a crosshair or a highlighted
// bar that snaps to a day or a release, and a tooltip with every series, the same by keyboard (arrow
// keys) as by pointer. Colors validated on the admin's dark cards: cyan #1fa3c4 and orange #d95926.
// Also (2.3): tables that sort by any column, the Apps table's find box and category filter, and
// GitHub's numbers asked for again in the background when they're an hour old.
(function () {
  'use strict';
  const DATA = window.RS_CHARTS || {};
  const COLORS = ['#1fa3c4', '#d95926'];
  const SURFACE = '#172031';
  const NS = 'http://www.w3.org/2000/svg';
  const el = (tag, attrs, parent) => {
    const n = document.createElementNS(NS, tag);
    for (const k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  };
  const compact = (v) => (v >= 1e6 ? +(v / 1e6).toFixed(1) + 'M' : v >= 1e3 ? +(v / 1e3).toFixed(v >= 1e4 ? 0 : 1) + 'K' : String(v));
  // The top of the axis: four equal whole-number steps (1, 2, 2.5, 3 or 5 times a power of ten).
  const niceMax = (m) => {
    const raw = Math.max(1, m / 4);
    const p = Math.pow(10, Math.floor(Math.log10(raw)));
    for (const k of [1, 2, 2.5, 3, 5, 10]) if (k * p >= raw && Number.isInteger(k * p)) return Math.max(4, k * p * 4);
    return 40 * p;
  };
  const dayLabel = (s, long, years) => new Date(s + 'T12:00:00Z').toLocaleDateString(undefined,
    long ? { weekday: 'short', month: 'short', day: 'numeric', year: years ? 'numeric' : undefined, timeZone: 'UTC' }
      : years ? { month: 'short', year: 'numeric', timeZone: 'UTC' } : { month: 'short', day: 'numeric', timeZone: 'UTC' });

  function legend(box, series) {
    if (series.length < 2) return;
    const lg = document.createElement('div');
    lg.className = 'legend';
    series.forEach((s, i) => {
      const item = document.createElement('span');
      const key = document.createElement('i');
      key.style.background = COLORS[i % COLORS.length];
      item.appendChild(key);
      item.appendChild(document.createTextNode(s.name));
      lg.appendChild(item);
    });
    box.appendChild(lg);
  }

  function tooltip(box) {
    const tip = document.createElement('div');
    tip.className = 'tip';
    tip.hidden = true;
    box.appendChild(tip);
    return (head, series, at, x, W, top) => {
      tip.textContent = '';
      const h = document.createElement('span');
      h.className = 'tip-day';
      h.textContent = head;
      tip.appendChild(h);
      series.forEach((s, k) => {
        const row = document.createElement('span');
        row.className = 'tip-row';
        const key = document.createElement('i');
        key.style.background = COLORS[k % COLORS.length];
        const val = document.createElement('strong');
        val.textContent = (s.values[at] || 0).toLocaleString();
        row.appendChild(key); row.appendChild(val); row.appendChild(document.createTextNode(' ' + (series.length > 1 ? s.name : s.name.toLowerCase())));
        tip.appendChild(row);
      });
      tip.hidden = head === null;
      if (head === null) return;
      const tw = tip.offsetWidth;
      tip.style.left = Math.min(W - tw - 4, Math.max(4, x + 12 + tw > W ? x - tw - 12 : x + 12)) + 'px';
      tip.style.top = top + 'px';
    };
  }

  function keys(box, n, get, show) {
    box.onkeydown = (e) => {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' && e.key !== 'Home' && e.key !== 'End') return;
      e.preventDefault();
      const at = get();
      if (e.key === 'Home') show(0);
      else if (e.key === 'End') show(n - 1);
      else show((at < 0 ? n - 1 : at) + (e.key === 'ArrowRight' ? 1 : -1));
    };
  }

  function lines(box, chart) {
    box.textContent = '';
    const series = chart.series || [];
    const days = chart.days || [];
    if (!series.length || !days.length) return;
    const years = days.length > 200;
    legend(box, series);
    const W = Math.max(280, box.clientWidth), H = 240;
    const m = { l: 44, r: series.length > 1 ? 124 : 56, t: 14, b: 30 };
    if (W < 420) m.r = series.length > 1 ? 80 : 44;
    const pw = W - m.l - m.r, ph = H - m.t - m.b;
    const max = niceMax(Math.max(1, ...series.flatMap((s) => s.values)));
    const x = (i) => m.l + (days.length === 1 ? pw / 2 : (i * pw) / (days.length - 1));
    const y = (v) => m.t + ph - (v / max) * ph;
    const svg = el('svg', { width: W, height: H, viewBox: `0 0 ${W} ${H}`, 'aria-hidden': 'true' }, box);
    for (let k = 0; k <= 4; k++) {
      const v = (max * k) / 4, yy = Math.round(y(v)) + 0.5;
      el('line', { x1: m.l, x2: W - m.r, y1: yy, y2: yy, class: k === 0 ? 'base' : 'grid' }, svg);
      el('text', { x: m.l - 8, y: yy + 4, 'text-anchor': 'end', class: 'tick' }, svg).textContent = compact(v);
    }
    // X labels: evenly spaced, at least 90px apart (so about six on a wide card, two or three on a phone).
    const room = years ? 100 : 90;
    const every = Math.max(1, Math.ceil(days.length / Math.max(1, Math.floor(pw / room))));
    days.forEach((d, i) => {
      if (i % every !== 0 && i !== days.length - 1) return;
      if (i !== days.length - 1 && x(days.length - 1) - x(i) < room * 0.8) return;
      el('text', { x: x(i), y: H - 8, 'text-anchor': i === 0 ? 'start' : i === days.length - 1 ? 'end' : 'middle', class: 'tick' }, svg).textContent = dayLabel(d, false, years);
    });
    // The lines (a light wash under a single one), and each line's last value at its end.
    series.forEach((s, i) => {
      const c = COLORS[i % COLORS.length];
      const pts = s.values.map((v, k) => `${x(k).toFixed(1)},${y(v).toFixed(1)}`);
      if (series.length === 1) el('path', { d: `M${x(0)},${y(0)} L${pts.join(' L')} L${x(days.length - 1)},${y(0)} Z`, fill: c, 'fill-opacity': 0.1, stroke: 'none' }, svg);
      el('polyline', { points: pts.join(' '), fill: 'none', stroke: c, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);
      el('circle', { cx: x(days.length - 1), cy: y(s.values[s.values.length - 1]), r: 4, fill: c, stroke: SURFACE, 'stroke-width': 2 }, svg);
    });
    // End labels: value (and name, for two series, when there's room), nudged only if they'd touch.
    const ends = series.map((s, i) => ({ i, v: s.values[s.values.length - 1], yy: y(s.values[s.values.length - 1]) })).sort((a, b) => a.yy - b.yy);
    for (let k = 1; k < ends.length; k++) if (ends[k].yy - ends[k - 1].yy < 16) ends[k].yy = ends[k - 1].yy + 16;
    ends.forEach((e) => {
      const t = el('text', { x: x(days.length - 1) + 10, y: e.yy + 4, class: 'end' }, svg);
      t.textContent = compact(e.v) + (series.length > 1 && W >= 420 ? ' ' + series[e.i].name.toLowerCase() : '');
    });
    const hair = el('line', { y1: m.t, y2: m.t + ph, class: 'hair', visibility: 'hidden' }, svg);
    const dots = series.map((s, i) => el('circle', { r: 4, fill: COLORS[i % COLORS.length], stroke: SURFACE, 'stroke-width': 2, visibility: 'hidden' }, svg));
    const tip = tooltip(box);
    let at = -1;
    const show = (i) => {
      at = Math.max(0, Math.min(days.length - 1, i));
      const xx = x(at);
      hair.setAttribute('x1', xx); hair.setAttribute('x2', xx); hair.setAttribute('visibility', 'visible');
      dots.forEach((d, k) => { d.setAttribute('cx', xx); d.setAttribute('cy', y(series[k].values[at])); d.setAttribute('visibility', 'visible'); });
      tip(dayLabel(days[at], true, years), series, at, xx, W, m.t + (series.length > 1 ? 34 : 0));
    };
    const hide = () => { at = -1; hair.setAttribute('visibility', 'hidden'); dots.forEach((d) => d.setAttribute('visibility', 'hidden')); tip(null, [], 0, 0, W, 0); };
    svg.addEventListener('pointermove', (e) => {
      const r = svg.getBoundingClientRect();
      const px = ((e.clientX - r.left) / r.width) * W;
      show(days.length === 1 ? 0 : Math.round(((px - m.l) / pw) * (days.length - 1)));
    });
    svg.addEventListener('pointerleave', hide);
    keys(box, days.length, () => at, show);
    box.onblur = hide;
  }

  // Bars: one per label (a release, a version), two side by side for two series.
  function bars(box, chart) {
    box.textContent = '';
    const series = chart.series || [];
    const labels = chart.labels || [];
    if (!series.length || !labels.length) return;
    legend(box, series);
    const W = Math.max(280, box.clientWidth), H = 250;
    const m = { l: 44, r: 8, t: 22, b: 30 };
    const pw = W - m.l - m.r, ph = H - m.t - m.b;
    const max = niceMax(Math.max(1, ...series.flatMap((s) => s.values)));
    const gw = pw / labels.length;
    const bw = Math.max(3, Math.min(36, (gw * 0.72) / series.length));
    const gap = series.length > 1 ? 2 : 0;
    const y = (v) => m.t + ph - (v / max) * ph;
    const gx = (i) => m.l + gw * i + gw / 2; // a group's middle
    const svg = el('svg', { width: W, height: H, viewBox: `0 0 ${W} ${H}`, 'aria-hidden': 'true' }, box);
    const band = el('rect', { y: m.t, height: ph, width: gw, class: 'band', visibility: 'hidden' }, svg);
    for (let k = 0; k <= 4; k++) {
      const v = (max * k) / 4, yy = Math.round(y(v)) + 0.5;
      el('line', { x1: m.l, x2: W - m.r, y1: yy, y2: yy, class: k === 0 ? 'base' : 'grid' }, svg);
      el('text', { x: m.l - 8, y: yy + 4, 'text-anchor': 'end', class: 'tick' }, svg).textContent = compact(v);
    }
    const longest = Math.max(...labels.map((l) => String(l).length));
    const every = Math.max(1, Math.ceil((longest * 7 + 8) / gw));
    labels.forEach((l, i) => {
      if (i % every !== 0 && i !== labels.length - 1) return;
      if (i !== labels.length - 1 && labels.length - 1 - i < every) return;
      el('text', { x: gx(i), y: H - 8, 'text-anchor': 'middle', class: 'tick' }, svg).textContent = l;
    });
    const room = bw >= 22 && labels.length <= 14;
    const left = (i, k) => gx(i) - (series.length * bw + (series.length - 1) * gap) / 2 + k * (bw + gap);
    series.forEach((s, k) => {
      s.values.forEach((v, i) => {
        const top = y(v), hgt = Math.max(v > 0 ? 1 : 0, m.t + ph - top);
        if (hgt > 0) el('path', { d: barPath(left(i, k), m.t + ph - hgt, bw, hgt), fill: COLORS[k % COLORS.length] }, svg);
        if (room) el('text', { x: left(i, k) + bw / 2, y: m.t + ph - hgt - 6, 'text-anchor': 'middle', class: 'val' }, svg).textContent = compact(v);
      });
    });
    const tip = tooltip(box);
    let at = -1;
    const show = (i) => {
      at = Math.max(0, Math.min(labels.length - 1, i));
      band.setAttribute('x', m.l + gw * at); band.setAttribute('visibility', 'visible');
      tip(String(labels[at]), series, at, gx(at) + gw / 4, W, m.t + (series.length > 1 ? 34 : 0));
    };
    const hide = () => { at = -1; band.setAttribute('visibility', 'hidden'); tip(null, [], 0, 0, W, 0); };
    svg.addEventListener('pointermove', (e) => {
      const r = svg.getBoundingClientRect();
      const px = ((e.clientX - r.left) / r.width) * W;
      if (px < m.l || px > W - m.r) return hide();
      show(Math.floor((px - m.l) / gw));
    });
    svg.addEventListener('pointerleave', hide);
    keys(box, labels.length, () => at, show);
    box.onblur = hide;
  }
  // A bar with only its top corners rounded.
  function barPath(x, y, w, h) {
    const r = Math.min(3, w / 2, h);
    return `M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + w - r} Q${x + w},${y} ${x + w},${y + r} V${y + h} Z`;
  }

  const boxes = [...document.querySelectorAll('[data-chart]')];
  const all = () => boxes.forEach((b) => {
    const c = DATA[b.dataset.chart];
    if (c) (c.type === 'bars' ? bars : lines)(b, c);
  });
  all();
  let t = 0;
  let lastW = window.innerWidth;
  window.addEventListener('resize', () => {
    if (window.innerWidth === lastW) return;
    lastW = window.innerWidth;
    clearTimeout(t); t = setTimeout(all, 120);
  });
  // A chart inside a closed <details> has no width until it's opened.
  document.querySelectorAll('details').forEach((d) => d.addEventListener('toggle', () => { if (d.open) d.querySelectorAll('[data-chart]').forEach((b) => { const c = DATA[b.dataset.chart]; if (c) (c.type === 'bars' ? bars : lines)(b, c); }); }));

  // ---- Tables: sort by any column ------------------------------------------------------------
  document.querySelectorAll('table[data-sortable]').forEach((table) => {
    const head = table.tHead && table.tHead.rows[table.tHead.rows.length - 1]; // under any group headings
    if (!head) return;
    [...head.cells].forEach((th, i) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'th-sort';
      b.textContent = th.textContent;
      th.textContent = '';
      th.appendChild(b);
      th.setAttribute('aria-sort', 'none');
      b.addEventListener('click', () => {
        const num = th.classList.contains('n');
        const was = th.getAttribute('aria-sort');
        const dir = was === 'none' ? (num ? 'descending' : 'ascending') : was === 'descending' ? 'ascending' : 'descending';
        [...head.cells].forEach((c) => c.setAttribute('aria-sort', 'none'));
        th.setAttribute('aria-sort', dir);
        const key = (tr) => {
          const td = tr.cells[i];
          if (!td) return num ? -Infinity : '';
          if (num) return td.dataset.s !== undefined ? parseFloat(td.dataset.s) : -Infinity;
          return td.textContent.trim().toLowerCase();
        };
        const body = table.tBodies[0];
        const rows = [...body.rows];
        rows.sort((a, z) => {
          const p = key(a), q = key(z);
          const c = num ? p - q : String(p).localeCompare(String(q));
          return dir === 'ascending' ? c : -c;
        });
        rows.forEach((r) => body.appendChild(r));
      });
    });
  });

  // ---- The Apps table: find and category -------------------------------------------------------
  const find = document.querySelector('[data-an-find]');
  const cat = document.querySelector('[data-an-cat]');
  if (find || cat) {
    const table = document.getElementById((find || cat).dataset.anFind || (find || cat).dataset.anCat);
    const count = document.querySelector('[data-an-count]');
    const none = document.querySelector('[data-an-none]');
    const apply = () => {
      const q = find ? find.value.trim().toLowerCase() : '';
      const c = cat ? cat.value : '';
      let n = 0, total = 0;
      if (table) [...table.tBodies[0].rows].forEach((r) => {
        total++;
        const show = (!q || (r.dataset.search || '').includes(q)) && (!c || r.dataset.cat === c);
        r.hidden = !show;
        if (show) n++;
      });
      if (count) count.textContent = (n === total ? n : n + ' of ' + total) + (total === 1 ? ' app' : ' apps');
      if (none) none.hidden = n > 0;
    };
    if (find) find.addEventListener('input', apply);
    if (cat) cat.addEventListener('change', apply);
    apply();
  }

  // ---- GitHub's numbers: an hour old or older, so asked for again, after the page is up ----------
  const asof = document.querySelector('[data-gh-refresh]');
  if (asof) {
    const note = document.createElement('span');
    note.className = 'an-gh-note';
    note.textContent = ' Asking GitHub for newer ones…';
    asof.appendChild(note);
    let got = 0;
    const ask = (round) => fetch(asof.dataset.ghRefresh, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then((r) => r.json())
      .then((j) => {
        got += (j && j.refreshed) || 0;
        // A few repos a call; more while GitHub keeps answering.
        if (j && j.ok && j.left > 0 && j.refreshed > 0 && round < 8) return ask(round + 1);
        note.textContent = ' ';
        if (got > 0) {
          const a = document.createElement('a');
          a.href = location.href;
          a.textContent = 'Newer numbers are in. Reload';
          note.appendChild(a);
        }
      })
      .catch(() => { note.textContent = ''; });
    ask(1);
  }
})();
