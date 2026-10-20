// Recomp Store admin (2.1): the Analytics page's day-by-day charts. One y-axis, 2px lines, a legend
// for two series (with the last value at each line's end), a crosshair that snaps to a day and a
// tooltip with every series, the same by keyboard (arrow keys) as by pointer. Colors validated on
// the admin's dark cards: cyan #1fa3c4 and orange #d95926.
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
  const day = (s, long) => new Date(s + 'T12:00:00Z').toLocaleDateString(undefined, long ? { weekday: 'short', month: 'short', day: 'numeric' } : { month: 'short', day: 'numeric' });

  function draw(box, chart) {
    box.textContent = '';
    const series = chart.series || [];
    const days = chart.days || [];
    if (!series.length || !days.length) return;
    if (series.length > 1) {
      const legend = document.createElement('div');
      legend.className = 'legend';
      series.forEach((s, i) => {
        const item = document.createElement('span');
        const key = document.createElement('i');
        key.style.background = COLORS[i % COLORS.length];
        item.appendChild(key);
        item.appendChild(document.createTextNode(s.name));
        legend.appendChild(item);
      });
      box.appendChild(legend);
    }
    const W = Math.max(320, box.clientWidth), H = 240;
    const m = { l: 44, r: series.length > 1 ? 124 : 56, t: 14, b: 30 };
    const pw = W - m.l - m.r, ph = H - m.t - m.b;
    const max = niceMax(Math.max(1, ...series.flatMap((s) => s.values)));
    const x = (i) => m.l + (days.length === 1 ? pw / 2 : (i * pw) / (days.length - 1));
    const y = (v) => m.t + ph - (v / max) * ph;
    const svg = el('svg', { width: W, height: H, viewBox: `0 0 ${W} ${H}`, 'aria-hidden': 'true' }, box);
    // Grid and y ticks: hairlines, recessive.
    for (let k = 0; k <= 4; k++) {
      const v = (max * k) / 4, yy = Math.round(y(v)) + 0.5;
      el('line', { x1: m.l, x2: W - m.r, y1: yy, y2: yy, class: k === 0 ? 'base' : 'grid' }, svg);
      el('text', { x: m.l - 8, y: yy + 4, 'text-anchor': 'end', class: 'tick' }, svg).textContent = compact(v);
    }
    // X labels: about six, evenly spaced.
    const every = Math.max(1, Math.ceil(days.length / 6));
    days.forEach((d, i) => {
      if (i % every !== 0 && i !== days.length - 1) return;
      if (i !== days.length - 1 && days.length - 1 - i < every * 0.6) return;
      el('text', { x: x(i), y: H - 8, 'text-anchor': i === 0 ? 'start' : i === days.length - 1 ? 'end' : 'middle', class: 'tick' }, svg).textContent = day(d);
    });
    // The lines (a light wash under a single one), and each line's last value at its end.
    series.forEach((s, i) => {
      const c = COLORS[i % COLORS.length];
      const pts = s.values.map((v, k) => `${x(k).toFixed(1)},${y(v).toFixed(1)}`);
      if (series.length === 1) el('path', { d: `M${x(0)},${y(0)} L${pts.join(' L')} L${x(days.length - 1)},${y(0)} Z`, fill: c, 'fill-opacity': 0.1, stroke: 'none' }, svg);
      el('polyline', { points: pts.join(' '), fill: 'none', stroke: c, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);
      const last = s.values[s.values.length - 1];
      el('circle', { cx: x(days.length - 1), cy: y(last), r: 4, fill: c, stroke: SURFACE, 'stroke-width': 2 }, svg);
    });
    // End labels: value (and name, for two series), nudged only if they'd touch.
    const ends = series.map((s, i) => ({ i, v: s.values[s.values.length - 1], yy: y(s.values[s.values.length - 1]) })).sort((a, b) => a.yy - b.yy);
    for (let k = 1; k < ends.length; k++) if (ends[k].yy - ends[k - 1].yy < 16) ends[k].yy = ends[k - 1].yy + 16;
    ends.forEach((e) => {
      const t = el('text', { x: x(days.length - 1) + 10, y: e.yy + 4, class: 'end' }, svg);
      t.textContent = compact(e.v) + (series.length > 1 ? ' ' + series[e.i].name.toLowerCase() : '');
    });
    // Crosshair and tooltip.
    const hair = el('line', { y1: m.t, y2: m.t + ph, class: 'hair', visibility: 'hidden' }, svg);
    const dots = series.map((s, i) => el('circle', { r: 4, fill: COLORS[i % COLORS.length], stroke: SURFACE, 'stroke-width': 2, visibility: 'hidden' }, svg));
    const tip = document.createElement('div');
    tip.className = 'tip';
    tip.hidden = true;
    box.appendChild(tip);
    let at = -1;
    const show = (i) => {
      at = Math.max(0, Math.min(days.length - 1, i));
      const xx = x(at);
      hair.setAttribute('x1', xx); hair.setAttribute('x2', xx); hair.setAttribute('visibility', 'visible');
      dots.forEach((d, k) => { d.setAttribute('cx', xx); d.setAttribute('cy', y(series[k].values[at])); d.setAttribute('visibility', 'visible'); });
      tip.textContent = '';
      const head = document.createElement('span');
      head.className = 'tip-day';
      head.textContent = day(days[at], true);
      tip.appendChild(head);
      series.forEach((s, k) => {
        const row = document.createElement('span');
        row.className = 'tip-row';
        const key = document.createElement('i');
        key.style.background = COLORS[k % COLORS.length];
        const val = document.createElement('strong');
        val.textContent = s.values[at].toLocaleString();
        row.appendChild(key); row.appendChild(val); row.appendChild(document.createTextNode(' ' + s.name.toLowerCase()));
        tip.appendChild(row);
      });
      tip.hidden = false;
      const tw = tip.offsetWidth;
      tip.style.left = Math.min(W - tw - 4, Math.max(4, xx + 12 + tw > W ? xx - tw - 12 : xx + 12)) + 'px';
      tip.style.top = m.t + (series.length > 1 ? 34 : 0) + 'px';
    };
    const hide = () => { at = -1; hair.setAttribute('visibility', 'hidden'); dots.forEach((d) => d.setAttribute('visibility', 'hidden')); tip.hidden = true; };
    svg.addEventListener('pointermove', (e) => {
      const r = svg.getBoundingClientRect();
      const px = ((e.clientX - r.left) / r.width) * W;
      show(days.length === 1 ? 0 : Math.round(((px - m.l) / pw) * (days.length - 1)));
    });
    svg.addEventListener('pointerleave', hide);
    box.onkeydown = (e) => {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' && e.key !== 'Home' && e.key !== 'End') return;
      e.preventDefault();
      if (e.key === 'Home') show(0);
      else if (e.key === 'End') show(days.length - 1);
      else show((at < 0 ? days.length - 1 : at) + (e.key === 'ArrowRight' ? 1 : -1));
    };
    box.onblur = hide;
  }

  const boxes = [...document.querySelectorAll('[data-chart]')];
  const all = () => boxes.forEach((b) => DATA[b.dataset.chart] && draw(b, DATA[b.dataset.chart]));
  all();
  let t = 0;
  window.addEventListener('resize', () => { clearTimeout(t); t = setTimeout(all, 120); });
})();
