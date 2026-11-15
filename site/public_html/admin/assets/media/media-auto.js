'use strict';
// Smart defaults for a game's media design, worked out from its pictures in the browser: where to
// cut the cover so its title stays in, the backdrop colour that stands out (for discs), how wide
// the logo is and how light. The website's media editor runs this for its Auto button and for
// "Auto-fill every Easy Setup game"; the result is saved as the game's design, so the launcher never
// has to look at the pictures itself.
//
// Needs MediaArt (media-art.js). Images must be readable by a canvas (same site, or served with CORS).

const MediaAuto = (() => {
  function load(src) {
    return new Promise((ok, no) => {
      if (!src) return ok(null);
      const im = new Image();
      im.crossOrigin = 'anonymous';
      im.onload = () => ok(im);
      im.onerror = () => no(new Error('image failed: ' + src));
      im.src = src;
    });
  }
  function pixels(im, w) {
    const h = Math.max(1, Math.round(im.naturalHeight * w / im.naturalWidth));
    const c = document.createElement('canvas'); c.width = w; c.height = h;
    const x = c.getContext('2d', { willReadFrequently: true });
    x.drawImage(im, 0, 0, w, h);
    return { w, h, d: x.getImageData(0, 0, w, h).data };
  }
  // How busy each row and column is: edges, plus a little for colour. Lettering is the busiest thing
  // on a cover, so this finds titles.
  function profile(im) {
    const { w, h, d } = pixels(im, 160);
    const L = new Float32Array(w * h), S = new Float32Array(w * h);
    for (let i = 0; i < w * h; i++) {
      const r = d[i * 4], g = d[i * 4 + 1], b = d[i * 4 + 2];
      const mx = Math.max(r, g, b), mn = Math.min(r, g, b);
      L[i] = .299 * r + .587 * g + .114 * b; S[i] = mx ? (mx - mn) / mx : 0;
    }
    const rows = new Float32Array(h), cols = new Float32Array(w);
    for (let y = 1; y < h - 1; y++) for (let x = 1; x < w - 1; x++) {
      const i = y * w + x;
      const e = (Math.abs(L[i + 1] - L[i - 1]) + Math.abs(L[i + w] - L[i - w])) / 255 + .25 * S[i];
      rows[y] += e; cols[x] += e;
    }
    const norm = (a) => { const m = Math.max(...a) || 1; return Array.from(a, (v) => v / m); };
    return { rows: norm(rows), cols: norm(cols) };
  }
  // The window of a profile holding the most, leaning a little towards the middle.
  function bestCut(prof, keep) {
    if (!prof || !prof.length || keep >= .999) return .5;
    const n = prof.length, k = Math.max(1, Math.round(n * keep));
    const sum = [0]; for (const v of prof) sum.push(sum[sum.length - 1] + v);
    let best = -1, at = 0;
    for (let i = 0; i + k <= n; i++) {
      const sc = (sum[i + k] - sum[i]) * (1 - .35 * Math.abs((i + k / 2) / n - .5));
      if (sc > best) { best = sc; at = i; }
    }
    return n - k ? at / (n - k) : .5;
  }
  // A cover's title sits at its top or its bottom: keep the busier end, unless neither stands out.
  function titleEnd(prof, keep) {
    if (!prof || !prof.length || keep >= .999) return .5;
    const n = prof.length, k = Math.max(1, Math.round(n * .28));
    let top = 0, bot = 0;
    for (let i = 0; i < k; i++) { top += prof[i]; bot += prof[n - 1 - i]; }
    if (top > bot * 1.12) return 0;
    if (bot > top * 1.12) return 1;
    return bestCut(prof, keep);
  }
  // The colour that stands out: common enough to be the picture's own, saturated, and neither near
  // black nor near white. Gives back [colour, score].
  function accent(im) {
    if (!im) return null;
    const { w, h, d } = pixels(im, 120);
    const bins = new Map();
    for (let i = 0; i < w * h; i++) {
      const r = d[i * 4] / 255, g = d[i * 4 + 1] / 255, b = d[i * 4 + 2] / 255;
      const key = Math.round(r * 7) * 64 + Math.round(g * 7) * 8 + Math.round(b * 7);
      const v = bins.get(key) || [0, 0, 0, 0]; v[0] += r; v[1] += g; v[2] += b; v[3]++; bins.set(key, v);
    }
    let best = null, score = -1;
    for (const [, v] of bins) {
      const r = v[0] / v[3], g = v[1] / v[3], b = v[2] / v[3];
      const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2;
      const s = mx === mn ? 0 : (mx - mn) / (1 - Math.abs(2 * l - 1));
      if (s < .3 || l < .18 || l > .78) continue;
      const sc = Math.sqrt(v[3] / (w * h)) * s;
      if (sc > score) { score = sc; best = '#' + [r, g, b].map((c) => Math.round(c * 255).toString(16).padStart(2, '0')).join(''); }
    }
    return best ? [best, score] : null;
  }
  // How light a logo is (0..1), counting only the pixels it draws.
  function lightness(im) {
    const { w, h, d } = pixels(im, 160);
    let t = 0, a = 0;
    for (let i = 0; i < w * h; i++) { const al = d[i * 4 + 3] / 255; t += al * (.299 * d[i * 4] + .587 * d[i * 4 + 1] + .114 * d[i * 4 + 2]) / 255; a += al; }
    return a ? t / a : .5;
  }
  // The calmest third across a picture, for a logo set on a banner: .28 left, .5 middle, .72 right.
  function calmSide(cols) {
    if (!cols || !cols.length) return .5;
    const n = cols.length, part = (a, b) => { let t = 0; for (let i = Math.floor(a * n); i < Math.ceil(b * n); i++) t += cols[i] || 0; return t / Math.max(1, Math.ceil(b * n) - Math.floor(a * n)); };
    return [[.28, part(.05, .5)], [.5, part(.25, .75) * .85], [.72, part(.5, .95)]].sort((u, v) => u[1] - v[1])[0][0];
  }

  // A full design for a game. art: { cover, hero, logo } image URLs; keep: fields to leave as they are.
  async function design(kind, art, keep = {}) {
    const [cover, hero, logo] = await Promise.all([load(art.cover).catch(() => null), load(art.hero).catch(() => null), load(art.logo).catch(() => null)]);
    const g = MediaArt.geometry(kind);
    const base = MediaArt.designFor(kind, { design: { art: keep.art, src: keep.src, style: keep.style } });
    const d = { v: 1, art: base.art, style: base.style };
    if (keep.src) d.src = keep.src;
    const pic = d.art === 'hero' ? (hero || cover) : d.art === 'cover' ? (cover || hero) : await load(keep.src).catch(() => null);
    if (pic) {
      const [, , W, H] = d.art === 'scan' ? g.scanBox : g.box;
      const iw = pic.naturalWidth, ih = pic.naturalHeight, sc = Math.max(W / iw, H / ih);
      const pr = profile(pic);
      const tall = d.art === 'cover' || (d.art === 'url' && ih > iw);
      d.crop = { x: bestCut(pr.cols, W / (iw * sc)), y: tall ? titleEnd(pr.rows, H / (ih * sc)) : bestCut(pr.rows, H / (ih * sc)), z: 1, w: iw, h: ih };
      d._cols = pr.cols;
    }
    const a1 = accent(hero), a2 = accent(cover);
    const pick = [a1, a2].filter(Boolean).sort((u, v) => v[1] - u[1])[0];
    const ll = logo ? lightness(logo) : .55;
    d.color = MediaArt.printColour(pick ? pick[0] : '#2c3c5c', ll);
    if (logo) {
      const a = logo.naturalWidth / logo.naturalHeight;
      const lg = MediaArt.designFor(kind, { design: { art: d.art, style: d.style, logo: { a } } }).logo;
      if (!g.round && g.mode === 'banner' && d._cols) {
        lg.x = calmSide(d._cols); lg.y = .5; lg.s = Math.min(.5, .74 * a * (g.box[3] / g.box[2]));
      }
      d.logo = { on: lg.on, x: +lg.x.toFixed(4), y: +lg.y.toFixed(4), s: +lg.s.toFixed(4), a: +a.toFixed(4) };
    }
    delete d._cols;
    return Object.assign(d, keep);
  }

  return { design, profile, accent, lightness, bestCut, titleEnd, load };
})();
if (typeof module !== 'undefined') module.exports = MediaAuto;
