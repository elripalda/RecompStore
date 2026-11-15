'use strict';
// A game's own media: the cartridge, card or disc its console used, drawn as the real object with
// the game on it. The launcher and the website's media editor both draw with this file (the site keeps a copy), so what the
// editor shows is what players see.
//
// Every kind sits on the same 600 x 420 canvas, so they all come out the same size. A shell is
// moulded plastic lit from the top left: the face, a thin edge under it for thickness, the label
// recess, the grooves and arrows of the moulding, and the label under a laminate. A disc is the
// printed side of a real disc: the print, the clear hub, the mirror band and the hole, with the
// light on it staying put while it spins.
//
// What goes on the label comes from the game's media design (the catalog's "media" field, made in
// the website's editor):
//   art    'cover' | 'hero' | 'url' | 'scan'   what fills the label ('scan': a photo of the real
//          disc face or cartridge label, used whole)
//   src    the image for 'url' and 'scan'
//   style  'image' | 'blur' | 'color'          (a disc defaults to 'color')
//   crop   { x, y, z, w, h }                   where to cut the image: x and y from 0 (left, top)
//          to 1 (right, bottom) of what overflows, z zoom (1 fills the label), w and h its size
//   color  '#rrggbb'                           the print colour for 'color'
//   logo   { on, x, y, s, a }                  the logo's centre (0..1 across the label box), its
//          width as a share of the box's, and its aspect (width / height)
//   kind   overrides the console's own media kind
// A game without a design gets sensible defaults.

const MediaArt = (() => {
  const rr = (x, y, w, h, r) => `M ${x + r},${y} H ${x + w - r} Q ${x + w},${y} ${x + w},${y + r} V ${y + h - r} Q ${x + w},${y + h} ${x + w - r},${y + h} H ${x + r} Q ${x},${y + h} ${x},${y + h - r} V ${y + r} Q ${x},${y} ${x + r},${y} Z`;
  const circ = (r, cx = 300, cy = 210) => `M ${cx - r},${cy} a ${r},${r} 0 1 0 ${2 * r},0 a ${r},${r} 0 1 0 ${-2 * r},0 Z`;
  const ring = (r1, r0, cx = 300, cy = 210) => `${circ(r1, cx, cy)} M ${cx - r0},${cy} a ${r0},${r0} 0 1 1 ${2 * r0},0 a ${r0},${r0} 0 1 1 ${-2 * r0},0 Z`;
  const rep = (n, f) => Array.from({ length: n }, (_, i) => f(i));
  const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
  const f1 = (v) => (Math.round(v * 10) / 10).toString();

  // ---- the shapes (from the vector templates) ----
  // box: the label's bounding box. mode: what a label shows by default, the cover ('cover') or the
  // backdrop with the logo ('banner', for the wide labels).
  const SWITCH = {
    shape: rr(177, 28, 246, 364, 28), well: rr(189, 48, 222, 306, 16), label: rr(195, 54, 210, 294, 12), box: [195, 54, 210, 294],
    ridges: [rr(183, 34, 234, 352, 23)], deboss: ['M 262,48 V 45 Q 262,42 266,42 H 334 Q 338,42 338,45 V 48 Z'],
    arrow: 'M 281,364 H 319 L 300,379 Z', bottom: 392, mode: 'cover',
  };
  const SHAPES = {
    switch: { ...SWITCH, tone: ['#4a4d55', '#2a2c31', '#17181b'], name: 'Switch' },
    switch2: { ...SWITCH, tone: ['#ef3b46', '#c4111f', '#7c0812'], name: 'Switch 2' },
    vita: {
      shape: 'M 178,52 Q 300,4 422,52 Q 429,55 429,64 V 79 L 425,87 V 120 L 429,129 V 390 H 198 Q 181,374 173,354 V 129 L 177,120 V 87 L 173,79 V 64 Q 173,55 178,52 Z',
      well: 'M 199,72 Q 300,34 403,72 Q 412,75 412,89 V 340 Q 412,350 402,350 H 201 Q 190,350 190,339 V 89 Q 190,76 199,72 Z',
      label: 'M 205,80 Q 300,45 397,80 Q 403,82 403,91 V 333 Q 403,340 396,340 H 206 Q 199,340 199,333 V 91 Q 199,82 205,80 Z', box: [199, 62, 204, 278],
      grooves: ['M 180,345 Q 184,366 203,381 H 424'], arrow: 'M 268,357 H 332 L 300,378 Z',
      bottom: 390, mode: 'cover', tone: ['#3a3c42', '#18191c', '#0a0a0c'], gloss: 1.5, name: 'PS Vita',
    },
    nes: {
      shape: 'M 142,28 H 151 V 31 H 164 V 28 H 437 V 31 H 450 V 28 H 458 Q 462,28 462,32 V 324 Q 462,328 458,328 H 444 V 388 Q 444,392 440,392 H 160 Q 156,392 156,388 V 328 H 142 Q 138,328 138,324 V 32 Q 138,28 142,28 Z',
      well: 'M 262,28 H 428 V 270 Q 428,284 414,284 H 276 Q 262,284 262,270 Z', label: rr(268, 31, 152, 245, 3), box: [268, 31, 152, 245],
      grooves: ['M 188,29 V 391', 'M 257,29 V 391'], ribs: rep(32, (i) => `M 191,${37 + i * 10} H 254`), wells: [rr(192, 360, 60, 31, 5)],
      arrow: 'M 287,300 H 311 L 299,321 Z', bottom: 392, mode: 'cover', tone: ['#adafb4', '#8c8e93', '#5d5f63'], name: 'NES',
    },
    ds: {
      shape: 'M 151,28 H 452 Q 464,28 464,40 V 379 Q 464,392 451,392 H 166 Q 136,392 136,362 V 45 Q 136,28 151,28 Z',
      well: 'M 150,75 V 326 Q 150,335 156,341 L 171,358 Q 176,362 184,362 H 426 Q 441,362 441,347 V 72 Q 441,55 425,55 H 169 Q 150,55 150,75 Z',
      label: 'M 169,61 H 426 Q 435,61 435,70 V 346 Q 435,355 426,355 H 182 Q 177,355 173,351 L 158,335 Q 154,331 154,324 V 76 Q 154,61 169,61 Z', box: [154, 61, 281, 294],
      grooves: ['M 141,342 V 360 Q 141,385 166,385 H 450 Q 457,385 457,378 V 367'], arrow: 'M 285,368 H 315 L 300,382 Z',
      bottom: 392, mode: 'cover', tone: ['#5a5d64', '#3a3c42', '#1e1f23'], name: 'DS',
    },
    '3ds': {
      shape: 'M 143,28 H 471 Q 480,28 480,38 V 87 Q 480,94 473,94 H 461 V 380 Q 461,392 449,392 H 161 Q 132,392 132,363 V 42 Q 132,28 143,28 Z',
      well: 'M 145,75 V 326 Q 145,334 151,340 L 167,356 Q 174,362 184,362 H 422 Q 439,362 439,345 V 73 Q 439,55 421,55 H 163 Q 145,55 145,75 Z',
      label: 'M 163,62 H 421 Q 432,62 432,73 V 345 Q 432,355 422,355 H 182 Q 176,355 171,350 L 154,334 Q 150,330 150,323 V 75 Q 150,62 163,62 Z', box: [150, 62, 282, 293],
      grooves: ['M 137,342 V 362 Q 137,385 162,385 H 449 Q 454,385 454,379 V 344', 'M 132,341 H 138 M 455,344 H 461'],
      bottom: 392, mode: 'cover', tone: ['#f6f7f9', '#dcdfe4', '#a9adb5'], light: true, name: '3DS',
    },
    gba: {
      shape: 'M 45,52 H 555 Q 576,52 576,73 V 109 Q 576,115 570,115 H 560 V 357 Q 560,368 549,368 H 51 Q 40,368 40,357 V 115 H 30 Q 24,115 24,109 V 73 Q 24,52 45,52 Z',
      well: rr(91, 118, 418, 218, 19), label: rr(97, 124, 406, 206, 15), box: [97, 124, 406, 206],
      ridges: ['M 39,72 Q 300,49 561,72'], grooves: ['M 82,104 Q 300,46 518,104', 'M 49,117 V 355 M 551,117 V 355'],
      arrow: 'M 265,346 H 335 L 300,361 Z', bottom: 368, mode: 'banner', tone: ['#62656c', '#43454b', '#25262a'], name: 'Game Boy Advance',
    },
    gb: {
      shape: 'M 149,28 H 428 Q 435,28 435,35 V 45 H 451 Q 459,45 459,53 V 385 Q 459,392 452,392 H 148 Q 141,392 141,385 V 35 Q 141,28 149,28 Z',
      well: rr(173, 128, 254, 223, 10), label: rr(177, 132, 246, 215, 7), box: [177, 132, 246, 215],
      wells: [rr(176, 52, 248, 64, 31)], ribs: rep(6, (i) => `M 143,${59 + i * 9} H 175 M 425,${59 + i * 9} H 457`),
      grooves: ['M 141,176 H 148 V 386 M 459,176 H 452 V 386'], arrow: 'M 279,359 H 321 L 300,379 Z',
      bottom: 392, mode: 'cover', tone: ['#c4c6cb', '#a5a7ac', '#76787d'], light: true, name: 'Game Boy',
    },
    snes: {
      shape: 'M 124,35 H 476 Q 488,35 488,47 H 558 Q 576,47 576,65 V 367 Q 576,385 558,385 H 489 Q 487,389 480,389 H 475 V 385 H 125 V 389 H 120 Q 113,389 111,385 H 42 Q 24,385 24,367 V 65 Q 24,47 42,47 H 112 Q 112,35 124,35 Z',
      well: rr(127, 37, 346, 172, 17), label: rr(132, 41, 336, 162, 13), box: [132, 41, 336, 162],
      grooves: ['M 112,48 V 381 M 488,48 V 381', ...rep(5, (i) => `M 27,${110 + i * 56} H 109 M 491,${110 + i * 56} H 573`)],
      wells: ['M 132,385 V 274 Q 132,253 153,253 H 447 Q 468,253 468,274 V 385 Z'], seams: ['M 252,256 V 384 M 348,256 V 384'],
      screws: [[72, 360], [528, 360]], bottom: 389, mode: 'banner', tone: ['#d9dade', '#bfc1c5', '#8d8f94'], light: true, name: 'SNES',
    },
    n64: {
      shape: 'M 38,86 Q 78,67 136,49 Q 300,9 464,49 Q 522,67 562,86 Q 576,91 576,105 L 564,368 Q 562,392 538,392 H 62 Q 38,392 36,368 L 24,105 Q 24,91 38,86 Z',
      well: 'M 184,73 Q 300,51 416,73 C 432,76 440,88 440,107 V 340 Q 440,370 410,370 H 190 Q 160,370 160,340 V 107 C 160,88 168,76 184,73 Z',
      label: 'M 186,79 Q 300,57 414,79 C 427,82 434,91 434,107 V 340 Q 434,364 410,364 H 190 Q 166,364 166,340 V 107 C 166,91 173,82 186,79 Z', box: [166, 62, 268, 302],
      grooves: ['M 136,49 Q 133,54 134,67 L 141,391', 'M 464,49 Q 467,54 466,67 L 459,391', 'M 33,285 Q 47,295 63,300 Q 70,302 71,313 L 75,389', 'M 567,285 Q 553,295 537,300 Q 530,302 529,313 L 525,389'],
      ridges: ['M 39,93 Q 79,74 137,56 Q 300,16 463,56 Q 521,74 561,93'], bottom: 392, mode: 'cover', tone: ['#8e9096', '#6d6f75', '#46484c'], name: 'N64',
    },
    genesis: {
      shape: 'M 48,30 Q 30,30 30,42 V 378 Q 30,390 48,390 H 552 Q 570,390 570,378 V 42 Q 570,30 552,30 Z',
      well: 'M 99,30 V 341 Q 99,354 112,354 H 488 Q 501,354 501,341 V 30 Z', label: 'M 105,31 H 495 V 340 Q 495,347 488,347 H 112 Q 105,347 105,340 Z', box: [105, 31, 390, 316],
      grooves: ['M 49,36 V 383 M 551,36 V 383', 'M 35,379 Q 35,385 49,385 H 551 Q 565,385 565,379'], ribs: rep(9, (i) => `M 58,${120 + i * 16} H 90 M 510,${120 + i * 16} H 542`),
      bottom: 390, mode: 'cover', tone: ['#3a3b40', '#222327', '#0e0f11'], gloss: .7, name: 'Genesis',
    },
    umd: {
      shape: 'M 118,210 A 182,182 0 0 1 482,210 L 491,331 Q 493,354 472,365 Q 300,421 128,365 Q 107,354 109,331 Z',
      well: circ(176), umd: true, print: [74, 170], box: [130, 40, 340, 340],
      grooves: ['M 115,337 Q 115,352 131,358 Q 300,413 469,358 Q 485,352 485,337'],
      bottom: 400, mode: 'disc', tone: ['#fbfbfc', '#e9ebee', '#b9bdc5'], light: true, name: 'UMD',
    },
  };
  SHAPES.gbc = { ...SHAPES.gb, tone: ['#3b3d44', '#202126', '#0c0c0e'], light: false, name: 'Game Boy Color' };
  // Discs: outer radius, where the print starts and ends, the mirror band, the clear ring, the
  // metal hub and its stacking ring, and the band's tint.
  const DISCS = {
    bluray: { R: 182, print: [66, 177], band: [59, 65], clear: [44, 59], hub: [23, 44], stack: 34, tint: ['#9fc0e6', '#2f6fb8'], name: 'Blu-ray' },
    dvd: { R: 182, print: [66, 177], band: [59, 65], clear: [44, 59], hub: [23, 44], stack: 34, tint: ['#e9dcc0', '#9a8a6a'], name: 'DVD' },
    gc: { R: 122, print: [67, 118], band: [64, 67], clear: [39, 64], hub: [23, 39], stack: 34, tint: ['#cfc8f0', '#6c5fb0'], name: 'GameCube', nologo: true },
  };
  // Each console's media. Anything else is a DVD-sized disc.
  const ORIGIN = {
    switch: 'switch', switch2: 'switch2', vita: 'vita', psv: 'vita', ds: 'ds', '3ds': '3ds', gba: 'gba',
    gb: 'gb', gbc: 'gbc', nes: 'nes', snes: 'snes', n64: 'n64', genesis: 'genesis', md: 'genesis', s32x: 'genesis',
    psp: 'umd', gc: 'gc', ps3: 'bluray', ps4: 'bluray', ps5: 'bluray', wiiu: 'bluray', xboxone: 'bluray',
  };
  const known = (k) => !!(DISCS[k] || SHAPES[k]);
  const kindOf = (origin) => ORIGIN[origin] || 'dvd';
  const isDisc = (k) => !!DISCS[k];
  const isRound = (k) => !!DISCS[k] || k === 'umd';

  let N = 0;

  // ---- colour ----
  function hsl(hex) {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
    const mx = Math.max(r, g, b), mn = Math.min(r, g, b), l = (mx + mn) / 2, d = mx - mn;
    const s = d ? d / (1 - Math.abs(2 * l - 1)) : 0;
    const h = !d ? 0 : mx === r ? ((g - b) / d + 6) % 6 : mx === g ? (b - r) / d + 2 : (r - g) / d + 4;
    return [h * 60, s, l];
  }
  function hex([h, s, l]) {
    s = Math.max(0, Math.min(1, s)); l = Math.max(0, Math.min(1, l));
    const c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
    const [r, g, b] = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
    return '#' + [r, g, b].map((v) => Math.round((v + m) * 255).toString(16).padStart(2, '0')).join('');
  }
  const okHex = (c) => typeof c === 'string' && /^#[0-9a-f]{6}$/i.test(c);
  function mix(a, b, t) {
    const p = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16));
    const [x, y] = [p(a), p(b)];
    return '#' + x.map((v, i) => Math.round(v + (y[i] - v) * t).toString(16).padStart(2, '0')).join('');
  }
  // The print's light, middle and shadow tones from one colour.
  function tones(c) {
    const [h, s, l] = hsl(okHex(c) ? c : '#2c3c5c');
    return [hex([h, s, Math.min(.94, l + .11)]), hex([h, s, l]), hex([h, s * 1.05, Math.max(.05, l - .14)])];
  }
  // A backdrop colour made into a print colour: rich enough to read as ink, and far enough from the
  // logo's lightness (0..1) that the logo stands out on it.
  function printColour(c, logoLum = .55) {
    let [h, s, l] = hsl(okHex(c) ? c : '#2c3c5c');
    s = Math.min(.85, Math.max(s, .45));
    l = logoLum < .26 ? Math.max(l, .62) : Math.min(Math.max(l, .3), .42);
    return hex([h, s, l]);
  }

  // ---- placing pictures ----
  // An image cut to fill a box. crop: { x, y, z, w, h } (see the top of this file). Without its
  // size the image is centred.
  function placed(href, [X, Y, W, H], crop, extra = '') {
    const c = crop || {};
    const x = Math.min(1, Math.max(0, c.x ?? .5)), y = Math.min(1, Math.max(0, c.y ?? .5)), z = Math.max(1, +c.z || 1);
    if (!(c.w > 0 && c.h > 0)) {
      const w = W * z, h = H * z;
      return `<image href="${esc(href)}" x="${f1(X - (w - W) * x)}" y="${f1(Y - (h - H) * y)}" width="${f1(w)}" height="${f1(h)}" preserveAspectRatio="xMidYMid slice"${extra}/>`;
    }
    const sc = Math.max(W / c.w, H / c.h) * z, iw = c.w * sc, ih = c.h * sc;
    return `<image href="${esc(href)}" x="${f1(X - (iw - W) * x)}" y="${f1(Y - (ih - H) * y)}" width="${f1(iw)}" height="${f1(ih)}" preserveAspectRatio="none"${extra}/>`;
  }
  // The logo's box inside a label box: centred at (x, y), s of the box's width wide.
  function logoBox([X, Y, W, H], lg) {
    const a = lg.a > 0 ? lg.a : 2;
    let w = W * (lg.s || .7), h = w / a;
    if (h > H * .95) { h = H * .95; w = h * a; }
    return [X + W * (lg.x ?? .5) - w / 2, Y + H * (lg.y ?? .5) - h / 2, w, h];
  }
  // Where a disc's logo goes: the biggest box of its shape that fits in the top of the ring between
  // r0 and r1, centred in the room it leaves. Given back as a logo position on the print's box.
  function ringLogo(r0, r1, a, fill = .94) {
    const m = Math.max(4, (r1 - r0) * .08), R1 = r1 - m;
    const fits = (h) => (a * h / 2) ** 2 + (r0 + m + h) ** 2 <= R1 * R1;
    let lo = 0, hi = r1 - r0;
    for (let i = 0; i < 30; i++) { const mid = (lo + hi) / 2; if (fits(mid)) lo = mid; else hi = mid; }
    const h = lo * fill, w = a * h;
    const yb = ((210 - Math.sqrt(Math.max(0, R1 * R1 - (w / 2) ** 2)) + h) + (210 - r0 - m)) / 2;
    const D = 2 * r1;
    return { x: .5, y: (yb - h / 2 - (210 - r1)) / D, s: w / D, a };
  }

  // ---- the design, with defaults filled in ----
  function designFor(kind, art) {
    const d = Object.assign({}, art.design || {});
    const s = SHAPES[kind], disc = DISCS[kind];
    const round = isRound(kind);
    if ((d.art === 'url' || d.art === 'scan') && !d.src) delete d.art;
    if (!d.art) d.art = round || (s && s.mode === 'banner') ? 'hero' : 'cover';
    if (!d.style) d.style = round && d.art !== 'scan' ? 'color' : 'image';
    const lg = Object.assign({}, d.logo || {});
    if (lg.on == null) lg.on = d.art !== 'scan' && (round ? !(disc && disc.nologo) : d.style !== 'image' || d.art === 'hero');
    if (lg.x == null || lg.y == null || lg.s == null) {
      const a = lg.a > 0 ? lg.a : 2;
      if (round) Object.assign(lg, ringLogo((disc || s).print[0], (disc || s).print[1], a));
      else Object.assign(lg, { x: .5, y: .5, s: Math.min(.78, .62 * a * (s.box[3] / s.box[2])) });
    }
    d.logo = lg;
    return d;
  }

  // ---- shared defs ----
  function defs(id, dark) {
    return `<filter id="${id}-plastic" x="-3%" y="-3%" width="106%" height="106%" color-interpolation-filters="sRGB">
      <feGaussianBlur in="SourceAlpha" stdDeviation="2.6" result="a"/>
      <feDiffuseLighting in="a" surfaceScale="3" diffuseConstant="1" lighting-color="#fff" result="df"><feDistantLight azimuth="235" elevation="60"/></feDiffuseLighting>
      <feSpecularLighting in="a" surfaceScale="3" specularConstant=".9" specularExponent="26" lighting-color="#fff" result="sp"><feDistantLight azimuth="235" elevation="42"/></feSpecularLighting>
      <feComposite in="sp" in2="SourceAlpha" operator="in" result="sp2"/>
      <feComposite in="SourceGraphic" in2="df" operator="arithmetic" k1="1.15" result="lit"/>
      <feTurbulence type="fractalNoise" baseFrequency=".95" numOctaves="2" seed="4" result="n"/>
      <feColorMatrix in="n" type="matrix" values="1 0 0 0 0  1 0 0 0 0  1 0 0 0 0  0 0 0 0 1" result="ng"/>
      <feComposite in="lit" in2="ng" operator="arithmetic" k2="1" k3=".07" k4="-.035" result="gr"/>
      <feComposite in="gr" in2="sp2" operator="arithmetic" k2="1" k3="${dark ? .42 : .3}" result="out"/>
      <feComposite in="out" in2="SourceAlpha" operator="in"/>
    </filter>
    <filter id="${id}-well" x="-5%" y="-5%" width="110%" height="110%" color-interpolation-filters="sRGB">
      <feOffset in="SourceAlpha" dx="1" dy="3.2" result="o"/><feGaussianBlur in="o" stdDeviation="2.4" result="ob"/>
      <feComposite in="SourceAlpha" in2="ob" operator="out" result="sh"/>
      <feFlood flood-color="#000" flood-opacity="${dark ? .75 : .42}"/><feComposite in2="sh" operator="in" result="shc"/>
      <feOffset in="SourceAlpha" dx="-.5" dy="-1.6" result="o2"/><feGaussianBlur in="o2" stdDeviation=".9" result="o2b"/>
      <feComposite in="SourceAlpha" in2="o2b" operator="out" result="hl"/>
      <feFlood flood-color="#fff" flood-opacity="${dark ? .16 : .55}"/><feComposite in2="hl" operator="in" result="hlc"/>
      <feMerge><feMergeNode in="SourceGraphic"/><feMergeNode in="shc"/><feMergeNode in="hlc"/></feMerge>
    </filter>
    <filter id="${id}-lift" x="-6%" y="-6%" width="112%" height="114%"><feDropShadow dx="0" dy="1.6" stdDeviation="1.4" flood-color="#000" flood-opacity=".45"/></filter>
    <filter id="${id}-cast" x="-20%" y="-20%" width="140%" height="160%"><feGaussianBlur stdDeviation="13"/></filter>
    <filter id="${id}-blur" x="-10%" y="-10%" width="120%" height="120%" color-interpolation-filters="sRGB"><feGaussianBlur stdDeviation="6"/><feColorMatrix type="saturate" values="1.18"/></filter>
    <filter id="${id}-logo" x="-15%" y="-15%" width="130%" height="140%"><feDropShadow dx="0" dy="2" stdDeviation="2.6" flood-color="#000" flood-opacity=".6"/></filter>
    <filter id="${id}-soft" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="16"/></filter>
    <filter id="${id}-glow" x="-30%" y="-200%" width="160%" height="500%"><feGaussianBlur stdDeviation="14"/></filter>
    <linearGradient id="${id}-lam" x1="0" y1="0" x2=".85" y2="1"><stop stop-color="#fff" stop-opacity=".24"/><stop offset=".28" stop-color="#fff" stop-opacity=".05"/><stop offset=".5" stop-color="#fff" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".18"/></linearGradient>
    <linearGradient id="${id}-shim" x1="0" y1="0" x2="1" y2="0"><stop stop-color="#fff" stop-opacity="0"/><stop offset=".5" stop-color="#fff" stop-opacity=".22"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
    <linearGradient id="${id}-dimv" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#000" stop-opacity=".1"/><stop offset=".6" stop-color="#000" stop-opacity=".24"/><stop offset="1" stop-color="#000" stop-opacity=".5"/></linearGradient>`;
  }

  // What fills a label box (a shell's label, or a disc's print square): picture or colour, then the logo.
  function fillLabel(id, box, d, art, round) {
    const [X, Y, W, H] = box;
    const src = d.art === 'url' || d.art === 'scan' ? d.src : d.art === 'hero' ? (art.hero || art.cover) : (art.cover || art.hero);
    let out = '';
    if (d.style === 'color' || !src) {
      const [c0, c1, c2] = tones(d.color);
      out += `<defs><radialGradient id="${id}-pc" cx=".34" cy=".26" r=".9"><stop stop-color="${c0}"/><stop offset=".55" stop-color="${c1}"/><stop offset="1" stop-color="${c2}"/></radialGradient></defs>`;
      out += `<rect x="${X}" y="${Y}" width="${W}" height="${H}" fill="url(#${id}-pc)"/>`;
    } else {
      out += placed(src, box, d.crop, d.style === 'blur' ? ` filter="url(#${id}-blur)"` : '');
      if (d.style === 'blur') out += `<rect x="${X}" y="${Y}" width="${W}" height="${H}" fill="url(#${id}-dimv)"/>`;
    }
    if (art.logo && d.logo && d.logo.on) {
      const [lx, ly, lw, lh] = logoBox(box, d.logo);
      if (!round && d.style === 'image') {
        // A logo set over a picture gets a soft shade behind it, so it reads on any art.
        out += `<ellipse cx="${f1(lx + lw / 2)}" cy="${f1(ly + lh / 2)}" rx="${f1(lw * .7)}" ry="${f1(lh * .75)}" fill="#000" opacity=".4" filter="url(#${id}-soft)"/>`;
      }
      out += `<image href="${esc(art.logo)}" x="${f1(lx)}" y="${f1(ly)}" width="${f1(lw)}" height="${f1(lh)}" preserveAspectRatio="xMidYMid meet" filter="url(#${id}-logo)"/>`;
    }
    return out;
  }

  // A disc print's finish: the fine rings and the shade near the hub and the rim.
  function printFinish(id, r0, r1) {
    const rings = rep(Math.floor((r1 - r0) / 3), (i) => `<circle cx="300" cy="210" r="${r0 + i * 3 + 1.5}" stroke-opacity="${i % 2 ? .035 : .05}"/>`).join('');
    return `<defs><radialGradient id="${id}-pd" cx=".5" cy=".5" r=".5"><stop offset="${(r0 / r1 * .98).toFixed(3)}" stop-color="#000" stop-opacity=".28"/><stop offset="${((r0 + 14) / r1).toFixed(3)}" stop-color="#000" stop-opacity="0"/><stop offset=".88" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".3"/></radialGradient></defs>
      <g fill="none" stroke="#fff" stroke-width="1">${rings}</g><circle cx="300" cy="210" r="${r1}" fill="url(#${id}-pd)"/>`;
  }

  const svgOpen = (cls) => `<svg class="${cls}" viewBox="0 0 600 420" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">`;

  // ---- a shell ----
  function shell(kind, art) {
    const s = SHAPES[kind];
    const id = 'ma' + (++N);
    const d = designFor(kind, art);
    const [c0, c1, c2] = s.tone;
    const dark = !s.light;
    const groove = dark ? ['rgba(0,0,0,.62)', 'rgba(255,255,255,.11)'] : ['rgba(40,44,52,.34)', 'rgba(255,255,255,.75)'];
    const lines = (arr, w, raised) => (arr || []).map((p) => raised
      ? `<path d="${p}" stroke="${groove[1]}" stroke-width="${w}" transform="translate(0 -1)"/><path d="${p}" stroke="${groove[0]}" stroke-width="${w * .8}" transform="translate(0 1)" opacity=".7"/>`
      : `<path d="${p}" stroke="${groove[0]}" stroke-width="${w}"/><path d="${p}" stroke="${groove[1]}" stroke-width="${w * .85}" transform="translate(0 1.2)"/>`).join('');
    let g = `<defs>${defs(id, dark)}
      <linearGradient id="${id}-body" x1="0" y1="0" x2=".3" y2="1"><stop stop-color="${c0}"/><stop offset=".55" stop-color="${c1}"/><stop offset="1" stop-color="${mix(c1, c2, .45)}"/></linearGradient>
      <linearGradient id="${id}-edge" x1="0" y1="0" x2="1" y2="0"><stop stop-color="${c2}"/><stop offset=".5" stop-color="${mix(c1, c2, .5)}"/><stop offset="1" stop-color="${c2}"/></linearGradient>
      <linearGradient id="${id}-floor" x1="0" y1="0" x2="0" y2="1"><stop stop-color="${mix(c1, c2, .55)}"/><stop offset="1" stop-color="${mix(c1, c2, .3)}"/></linearGradient>
      <radialGradient id="${id}-sheen" cx=".22" cy=".08" r=".9"><stop stop-color="#fff" stop-opacity="${(dark ? .2 : .32) * (s.gloss || 1)}"/><stop offset=".45" stop-color="#fff" stop-opacity="0"/></radialGradient>
      <clipPath id="${id}-body-c"><path d="${s.shape}"/></clipPath>
      <clipPath id="${id}-label-c">${s.umd ? `<path d="${ring(s.print[1], s.print[0])}" clip-rule="evenodd"/>` : `<path d="${s.label}"/>`}</clipPath></defs>`;
    // Underneath: the working glow, the cast shadow, the shell's thickness.
    g += `<ellipse class="md-glow" cx="300" cy="${s.bottom + 2}" rx="190" ry="18" fill="#34F2E8" filter="url(#${id}-glow)"/>`;
    g += `<path class="md-cast" d="${s.shape}" fill="#000" transform="translate(6 16) scale(.98)" filter="url(#${id}-cast)"/>`;
    g += `<path d="${s.shape}" fill="url(#${id}-edge)" transform="translate(0 6)"/>`;
    // The face, its chamfer and its moulding.
    g += `<path d="${s.shape}" fill="url(#${id}-body)" filter="url(#${id}-plastic)"/>`;
    g += `<g clip-path="url(#${id}-body-c)" fill="none"><path d="${s.shape}" stroke="#fff" stroke-opacity="${dark ? .2 : .7}" stroke-width="3" transform="translate(.6 1.6)"/><path d="${s.shape}" stroke="#000" stroke-opacity="${dark ? .5 : .22}" stroke-width="3" transform="translate(-.4 -1.8)"/></g>`;
    g += `<g fill="none" stroke-linecap="round" stroke-linejoin="round">${lines(s.ridges, 1.6, true)}${lines(s.grooves, 1.8)}${lines(s.ribs, 1.6)}${lines(s.seams, 2)}</g>`;
    for (const w of s.wells || []) g += `<path d="${w}" fill="url(#${id}-floor)" filter="url(#${id}-well)"/>`;
    for (const p of s.deboss || []) g += `<path d="${p}" fill="url(#${id}-floor)" filter="url(#${id}-well)"/>`;
    for (const [x, y] of s.screws || []) g += `<circle cx="${x}" cy="${y}" r="8" fill="url(#${id}-floor)" filter="url(#${id}-well)"/><circle cx="${x}" cy="${y}" r="5.2" fill="${mix(c1, c2, .2)}" filter="url(#${id}-lift)"/><path d="M ${x - 3},${y - 3} L ${x + 3},${y + 3}" stroke="${groove[0]}" stroke-width="1.6" stroke-linecap="round"/>`;
    if (s.arrow) g += `<path d="${s.arrow}" fill="url(#${id}-floor)" filter="url(#${id}-well)"/><path class="md-arrow" d="${s.arrow}" fill="#34F2E8"/>`;
    g += `<path d="${s.shape}" fill="url(#${id}-sheen)"/>`;
    if (s.well) g += `<path d="${s.well}" fill="url(#${id}-floor)" filter="url(#${id}-well)"/>`;
    let spin = '', front = '';
    if (s.umd) {
      // The disc inside: its print turns while the white shell round it stays put.
      const [p0, p1] = s.print;
      g += `<path d="${circ(p1 + 1)}" fill="#000" opacity=".35" filter="url(#${id}-lift)"/>`;
      spin = `<g clip-path="url(#${id}-label-c)">${fillLabel(id, [300 - p1, 210 - p1, 2 * p1, 2 * p1], d, art, true)}${d.art === 'scan' ? '' : printFinish(id, p0, p1)}</g>
        <path d="M 300,${210 - p0 - 6} v -5" stroke="#fff" stroke-opacity=".35" stroke-width="1.4" stroke-linecap="round"/>`;
      // The hub: the shell's white plastic with UMD printed on it in black.
      const um = 'M 244,195 V 219 C 244,237 270,237 270,219 V 195 M 282,233 V 195 L 298,223 L 314,195 V 233 M 327,195 V 233 H 340 C 365,233 365,195 340,195 Z';
      front = `<path d="${ring(p1 + .6, p1 - .6)}" fill-rule="evenodd" fill="#000" opacity=".3"/>
        <circle cx="300" cy="210" r="${p0}" fill="url(#${id}-body)" filter="url(#${id}-plastic)"/><circle cx="300" cy="210" r="${p0}" fill="url(#${id}-sheen)"/>
        <path d="${ring(p0 + .6, p0 - .6)}" fill-rule="evenodd" fill="#000" opacity=".28"/>
        <path d="${um}" fill="none" stroke="#111214" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/>`;
      g += `<path d="${s.shape}" fill="none" stroke="rgba(60,66,78,.45)" stroke-width="1.2"/>`;
    } else {
      g += `<path d="${s.label}" fill="#000" filter="url(#${id}-lift)" opacity=".9"/>`;
      g += `<g clip-path="url(#${id}-label-c)">${fillLabel(id, s.box, d, art, false)}
        <rect x="${s.box[0]}" y="${s.box[1]}" width="${s.box[2]}" height="${s.box[3]}" fill="url(#${id}-lam)"/>
        <rect class="md-shimmer" x="${s.box[0] - s.box[2] * .6}" y="${s.box[1] - 20}" width="${s.box[2] * .5}" height="${s.box[3] + 40}" fill="url(#${id}-shim)"/></g>`;
      g += `<path d="${s.label}" fill="none" stroke="#000" stroke-opacity=".35" stroke-width="1"/>`;
      g += `<path d="${s.label}" fill="none" stroke="#fff" stroke-opacity=".14" stroke-width="1" transform="translate(0 .8)" clip-path="url(#${id}-label-c)"/>`;
      // The outline: a dark line at the very edge, so a white shell still reads on a white page.
      g += `<path d="${s.shape}" fill="none" stroke="${dark ? 'rgba(0,0,0,.6)' : 'rgba(60,66,78,.45)'}" stroke-width="1.2"/>`;
    }
    return { back: g, spin, front };
  }

  // ---- a disc ----
  function disc(kind, art) {
    const k = DISCS[kind];
    const id = 'ma' + (++N);
    const d = designFor(kind, art);
    const { R } = k;
    const [p0, p1] = k.print;
    let back = `<defs>${defs(id, true)}
      <linearGradient id="${id}-chrome" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#5d6a80"/><stop offset=".18" stop-color="#dfe7f0"/><stop offset=".34" stop-color="#7f8ea6"/><stop offset=".5" stop-color="#fbfdff"/><stop offset=".6" stop-color="#93a3ba"/><stop offset=".78" stop-color="#e2e8ee"/><stop offset="1" stop-color="#55617a"/></linearGradient>
      <linearGradient id="${id}-band" x1="0" y1="1" x2="1" y2="0"><stop stop-color="${k.tint[1]}"/><stop offset=".3" stop-color="${k.tint[0]}"/><stop offset=".48" stop-color="#f4ecc4"/><stop offset=".66" stop-color="#c9b9e6"/><stop offset=".84" stop-color="${k.tint[0]}"/><stop offset="1" stop-color="${k.tint[1]}"/></linearGradient>
      <radialGradient id="${id}-clear" cx=".5" cy=".5" r=".5"><stop offset="0" stop-color="#e8f0f8" stop-opacity=".1"/><stop offset="1" stop-color="#e8f0f8" stop-opacity=".32"/></radialGradient>
      <clipPath id="${id}-print-c"><path d="${ring(p1, p0)}" clip-rule="evenodd"/></clipPath>
      <clipPath id="${id}-face-c"><path d="${ring(R, k.hub[0])}" clip-rule="evenodd"/></clipPath></defs>`;
    back += `<ellipse class="md-glow" cx="300" cy="${210 + R + 2}" rx="${R}" ry="18" fill="#34F2E8" filter="url(#${id}-glow)"/>`;
    back += `<circle class="md-cast" cx="306" cy="${210 + 16}" r="${R * .97}" fill="#000" filter="url(#${id}-cast)"/>`;
    let spin = '';
    if (d.art === 'scan') {
      // A photo of the real disc: the whole face, with only the hole cut out.
      spin += `<g clip-path="url(#${id}-face-c)">${placed(d.src, [300 - R, 210 - R, 2 * R, 2 * R], d.crop)}</g>`;
    } else {
      spin += `<path d="${ring(R, p1)}" fill-rule="evenodd" fill="url(#${id}-chrome)" opacity=".85"/>`;
      spin += `<g clip-path="url(#${id}-print-c)">${fillLabel(id, [300 - p1, 210 - p1, 2 * p1, 2 * p1], d, art, true)}${printFinish(id, p0, p1)}</g>`;
      spin += `<path d="${ring(p1 + .6, p1 - .6)}" fill-rule="evenodd" fill="#000" opacity=".35"/>`;
      spin += `<path d="${ring(k.band[1], k.band[0])}" fill-rule="evenodd" fill="url(#${id}-band)"/>`;
      spin += `<path d="${ring(k.clear[1], k.clear[0])}" fill-rule="evenodd" fill="url(#${id}-clear)"/>`;
      spin += `<circle cx="300" cy="210" r="${(k.clear[0] + k.clear[1]) / 2}" fill="none" stroke="#fff" stroke-opacity=".35" stroke-width="1.2"/>`;
      spin += `<path d="${ring(k.hub[1], k.hub[0])}" fill-rule="evenodd" fill="url(#${id}-chrome)"/>`;
      spin += `<circle cx="300" cy="210" r="${k.stack}" fill="none" stroke="#000" stroke-opacity=".25" stroke-width="1"/><circle cx="300" cy="210" r="${k.stack + 1}" fill="none" stroke="#fff" stroke-opacity=".5" stroke-width="1"/>`;
      spin += `<path d="M 300,${210 - k.clear[1] + 3} v 5" stroke="#fff" stroke-opacity=".4" stroke-width="1.4" stroke-linecap="round"/>`;
    }
    spin += `<circle cx="300" cy="210" r="${k.hub[0] + .6}" fill="none" stroke="#000" stroke-opacity=".5" stroke-width="1.4"/>`;
    // The light on the disc, which stays still while it turns: soft beams across the face.
    const beam = (a0, a1, col, op) => {
      const r = R + 30, rad = (a) => (a - 90) * Math.PI / 180;
      return `<path d="M 300,210 L ${f1(300 + r * Math.cos(rad(a0)))},${f1(210 + r * Math.sin(rad(a0)))} A ${r},${r} 0 0 1 ${f1(300 + r * Math.cos(rad(a1)))},${f1(210 + r * Math.sin(rad(a1)))} Z" fill="${col}" opacity="${op}"/>`;
    };
    const front = `<g clip-path="url(#${id}-face-c)" style="mix-blend-mode:screen"><g filter="url(#${id}-soft)">${beam(-58, -28, '#fff', .3)}${beam(-28, -10, '#bfe6ff', .14)}${beam(122, 152, '#fff', .22)}${beam(152, 170, '#ffc9ee', .12)}</g></g>
      <path d="${ring(R, R - 2)}" fill-rule="evenodd" fill="#fff" opacity=".35"/><path d="${ring(R + .8, R - .4)}" fill-rule="evenodd" fill="#000" opacity=".35"/>`;
    return { back, spin, front };
  }

  // The media as HTML: up to three stacked layers (what's under, what turns, what's on top), so the
  // page can spin the middle one on its own, cheaply.
  function render(kind, art = {}) {
    let k = (art.design && known(art.design.kind) && art.design.kind) || kind;
    if (!known(k)) k = 'dvd';
    const L = DISCS[k] ? disc(k, art) : shell(k, art);
    const type = DISCS[k] ? 'md-disc' : k === 'umd' ? 'md-umd' : 'md-cart';
    return `<div class="md ${type} md-k-${k}">${svgOpen('md-l md-back')}${L.back}</svg>${L.spin ? `${svgOpen('md-l md-spin')}${L.spin}</svg>` : ''}${L.front ? `${svgOpen('md-l md-front')}${L.front}</svg>` : ''}</div>`;
  }

  // The empty outline of a kind's media, for while it waits for the player's files.
  function ghost(kind) {
    const k = known(kind) ? kind : 'dvd';
    const shape = DISCS[k] ? ring(DISCS[k].R, DISCS[k].hub[0]) : SHAPES[k].shape;
    return `<div class="md md-ghost md-k-${k}">${svgOpen('md-l')}<path d="${shape}" fill="currentColor" fill-opacity=".04" fill-rule="evenodd" stroke="currentColor" stroke-width="2.4" stroke-dasharray="8 8" stroke-linejoin="round"/></svg></div>`;
  }

  // Where the label is, for the editor: the box art is cut to, and the label's outline.
  function geometry(kind) {
    if (DISCS[kind]) { const k = DISCS[kind]; return { round: true, R: k.R, print: k.print, box: [300 - k.print[1], 210 - k.print[1], 2 * k.print[1], 2 * k.print[1]], scanBox: [300 - k.R, 210 - k.R, 2 * k.R, 2 * k.R], outline: ring(k.print[1], k.print[0]), nologo: !!k.nologo }; }
    const s = SHAPES[kind];
    if (s.umd) return { round: true, print: s.print, box: [300 - s.print[1], 210 - s.print[1], 2 * s.print[1], 2 * s.print[1]], scanBox: [300 - s.print[1], 210 - s.print[1], 2 * s.print[1], 2 * s.print[1]], outline: ring(s.print[1], s.print[0]) };
    return { round: false, box: s.box, scanBox: s.box, outline: s.label, mode: s.mode };
  }

  const KINDS = [...Object.keys(SHAPES), ...Object.keys(DISCS)].map((k) => ({ id: k, name: (SHAPES[k] || DISCS[k]).name }));
  return { render, ghost, geometry, kindOf, known, isDisc, isRound, designFor, printColour, ringLogo, KINDS, ORIGIN };
})();
if (typeof module !== 'undefined') module.exports = MediaArt;
