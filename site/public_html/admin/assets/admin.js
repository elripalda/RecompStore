// Recomp Store admin: live previews on the game form.
(function () {
  const form = document.querySelector('form.edit');
  if (!form) return;

  // Show only the fields for the chosen download source.
  const source = form.querySelector('[data-source]');
  const syncSource = () => {
    const gh = source.value === 'github';
    form.querySelectorAll('[data-gh]').forEach((el) => (el.hidden = !gh));
    form.querySelectorAll('[data-url]').forEach((el) => (el.hidden = gh));
  };
  source.addEventListener('change', syncSource);
  syncSource();

  const setup = form.querySelector('[data-setup]');
  const syncSetup = () => (form.querySelector('[data-setup-fields]').hidden = !setup.checked);
  setup.addEventListener('change', syncSetup);
  syncSetup();

  // Name in the banner preview follows the field.
  const nameIn = form.querySelector('input[name="name"]');
  const nameOut = form.querySelector('[data-name]');
  nameIn.addEventListener('input', () => (nameOut.textContent = nameIn.value || 'Game name'));

  // Picked files preview before saving.
  form.querySelectorAll('input[type=file][data-preview]').forEach((input) => {
    input.addEventListener('change', () => {
      const f = input.files && input.files[0];
      if (!f) return;
      const img = form.querySelector(input.dataset.preview === 'icon' ? '[data-icon]' : '[data-hero]');
      img.src = URL.createObjectURL(f);
      img.hidden = false;
      apply();
    });
  });

  // Banner framing: object-position for left/right and up/down, scale for zoom.
  const hero = form.querySelector('[data-hero]');
  const frame = form.querySelector('[data-frame] .window');
  const r = (n) => form.querySelector(`input[name="${n}"]`);
  const zoom = r('zoom'), fx = r('focus_x'), fy = r('focus_y');
  function apply() {
    const x = +fx.value, y = +fy.value, z = +zoom.value / 100;
    hero.style.objectPosition = `${x}% ${y}%`;
    hero.style.transformOrigin = `${x}% ${y}%`;
    hero.style.transform = `scale(${z})`;
    form.querySelector('[data-out="zoom"]').textContent = zoom.value + '%';
    form.querySelector('[data-out="focus_x"]').textContent = fx.value + '%';
    form.querySelector('[data-out="focus_y"]').textContent = fy.value + '%';
  }
  form.querySelectorAll('[data-range]').forEach((el) => el.addEventListener('input', apply));
  apply();

  // Drag the picture: moving right shows more of the left side, and so on.
  let drag = null;
  frame.addEventListener('pointerdown', (e) => {
    drag = { x: e.clientX, y: e.clientY, fx: +fx.value, fy: +fy.value };
    frame.setPointerCapture(e.pointerId);
    frame.classList.add('dragging');
  });
  frame.addEventListener('pointermove', (e) => {
    if (!drag) return;
    const rect = frame.getBoundingClientRect();
    const k = 100 / (+zoom.value / 100);
    fx.value = Math.round(Math.max(0, Math.min(100, drag.fx - ((e.clientX - drag.x) / rect.width) * k)));
    fy.value = Math.round(Math.max(0, Math.min(100, drag.fy - ((e.clientY - drag.y) / rect.height) * k)));
    apply();
  });
  const end = () => { drag = null; frame.classList.remove('dragging'); };
  frame.addEventListener('pointerup', end);
  frame.addEventListener('pointercancel', end);
  frame.addEventListener('wheel', (e) => {
    e.preventDefault();
    zoom.value = Math.max(100, Math.min(250, +zoom.value - Math.sign(e.deltaY) * 5));
    apply();
  }, { passive: false });
})();

// Logo placement: the store's banner (1728x400) and game page (1920x1080), drawn at the
// store's own sizes and scaled to fit, so what you set here is what the PS5 shows.
(function () {
  const card = document.querySelector('[data-logo-card]');
  if (!card) return;
  const form = card.closest('form');
  const editors = card.querySelector('[data-logo-editors]');
  const thumb = card.querySelector('[data-logo-img]');
  const info = card.querySelector('[data-page-text]').dataset;
  const field = (n) => form.querySelector(`[name="${n}"]`);
  const heroImg = form.querySelector('[data-hero]');
  const esc = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let logoSrc = thumb.getAttribute('src') || '';

  const stages = {};
  card.querySelectorAll('[data-stage]').forEach((wrap) => {
    const kind = wrap.dataset.stage, W = +wrap.dataset.w, H = +wrap.dataset.h;
    const stage = document.createElement('div');
    stage.className = 'stage';
    stage.style.width = W + 'px';
    stage.style.height = H + 'px';
    if (kind === 'banner') {
      stage.innerHTML = `
        <img class="bg" data-bg alt="" style="filter:blur(40px) saturate(1.4) brightness(.5);transform:scale(1.2)">
        <div class="pic" style="left:600px"><img data-pic alt=""></div>
        <div class="glass"></div>
        <span class="kick" style="left:64px">FEATURED · NEW ON RECOMP STORE</span>
        <img class="logo" data-logo alt="" draggable="false">
        <div class="txt" data-blurb style="left:64px;width:488px;font:400 20px/29px Figtree,sans-serif;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden">${esc(info.blurb)}</div>`;
    } else {
      stage.innerHTML = `
        <img class="bg" data-pic alt="">
        <div class="shade"></div>
        <div style="position:absolute;left:96px;top:190px;display:flex;gap:12px">
          <span class="chip ${info.kind === 'original' ? 'cyan' : ''}" style="position:static">${info.kind === 'original' ? 'Recomp Store' : 'By ' + esc(info.author)}</span>
          <span class="chip" style="position:static">${esc(info.id || 'PPSA00000')}</span>
          <span class="chip" style="position:static">Needs your ${esc(info.needs || 'own game')}</span>
        </div>
        <img class="logo" data-logo alt="" draggable="false">
        <div class="txt" data-desc style="left:96px;width:860px;font:400 25px/37.5px Figtree,sans-serif;color:#E1E8F1;display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden">${esc(info.desc)}</div>
        <div class="panel" style="left:1184px;top:190px;width:640px;height:672px"></div>`;
    }
    wrap.appendChild(stage);
    stages[kind] = { wrap, stage, W, H, k: 1, p: kind === 'banner' ? 'b' : 'p' };
  });

  function fitLogo(img, boxW, boxH, scale) {
    const nw = img.naturalWidth || 4, nh = img.naturalHeight || 1;
    const bw = boxW * scale / 100, bh = boxH * scale / 100, aspect = nw / nh;
    let w = bw, h = bw / aspect;
    if (h > bh) { h = bh; w = h * aspect; }
    return { w, h };
  }

  function layout() {
    for (const kind in stages) {
      const st = stages[kind], s = st.stage, p = st.p;
      st.k = st.wrap.clientWidth / st.W;
      s.style.transform = `scale(${st.k})`;
      st.wrap.style.height = st.H * st.k + 'px';
      const hero = heroImg && !heroImg.hidden ? heroImg.src : '';
      s.querySelectorAll('[data-bg],[data-pic]').forEach((im) => { if (hero && im.src !== hero) im.src = hero; });
      const logo = s.querySelector('[data-logo]');
      if (logoSrc && logo.getAttribute('src') !== logoSrc) logo.src = logoSrc;
      const x = +field(`logo_${p}x`).value, y = +field(`logo_${p}y`).value, sc = +field(`logo_${p}s`).value;
      if (kind === 'banner') {
        const fx = field('focus_x'), fy = field('focus_y'), z = field('zoom');
        const pic = s.querySelector('[data-pic]');
        if (fx && fy && z) {
          pic.style.objectPosition = `${fx.value}% ${fy.value}%`;
          pic.style.transformOrigin = `${fx.value}% ${fy.value}%`;
          pic.style.transform = `scale(${+z.value / 100})`;
        }
        const { w, h } = fitLogo(logo, 488, 140, sc);
        const blurb = s.querySelector('[data-blurb]');
        const bh = blurb.offsetHeight;
        const total = 18 + 14 + h + 14 + bh;
        const ty = (400 - total) / 2;
        s.querySelector('.kick').style.top = ty + 'px';
        Object.assign(logo.style, { left: 64 + x + 'px', top: ty + 32 + y + 'px', width: w + 'px', height: h + 'px' });
        blurb.style.top = ty + 32 + h + 14 + 'px';
        card.querySelector('[data-out="logo_bxy"]').textContent = `${x}, ${y}`;
      } else {
        const fy = field('focus_y');
        const pic = s.querySelector('[data-pic]');
        if (fy) pic.style.objectPosition = `50% ${fy.value}%`;
        const { w, h } = fitLogo(logo, 820, 230, sc);
        Object.assign(logo.style, { left: 96 + x + 'px', top: 250 + y + 'px', width: w + 'px', height: h + 'px' });
        s.querySelector('[data-desc]').style.top = 250 + h + 26 + 'px';
        card.querySelector('[data-out="logo_pxy"]').textContent = `${x}, ${y}`;
      }
      card.querySelector(`[data-out="logo_${p}s"]`).textContent = sc + '%';
    }
  }

  // Drag a logo to move it (in the store's pixels).
  for (const kind in stages) {
    const st = stages[kind], logo = st.stage.querySelector('[data-logo]');
    let drag = null;
    logo.addEventListener('load', layout);
    logo.addEventListener('pointerdown', (e) => {
      drag = { x: e.clientX, y: e.clientY, ox: +field(`logo_${st.p}x`).value, oy: +field(`logo_${st.p}y`).value };
      logo.setPointerCapture(e.pointerId);
      logo.classList.add('dragging');
      e.preventDefault();
    });
    logo.addEventListener('pointermove', (e) => {
      if (!drag) return;
      const lim = st.p === 'b' ? [1200, 600] : [1200, 600];
      field(`logo_${st.p}x`).value = Math.round(Math.max(-lim[0], Math.min(lim[0], drag.ox + (e.clientX - drag.x) / st.k)));
      field(`logo_${st.p}y`).value = Math.round(Math.max(-lim[1], Math.min(lim[1], drag.oy + (e.clientY - drag.y) / st.k)));
      layout();
    });
    const end = () => { drag = null; logo.classList.remove('dragging'); };
    logo.addEventListener('pointerup', end);
    logo.addEventListener('pointercancel', end);
  }

  card.querySelectorAll('[data-logo-range]').forEach((el) => el.addEventListener('input', layout));
  card.querySelectorAll('[data-logo-reset]').forEach((b) => b.addEventListener('click', () => {
    const p = b.dataset.logoReset;
    field(`logo_${p}x`).value = 0; field(`logo_${p}y`).value = 0; field(`logo_${p}s`).value = 100;
    layout();
  }));
  // A newly picked logo shows straight away.
  card.querySelector('input[name="logo_file"]').addEventListener('change', (e) => {
    const f = e.target.files && e.target.files[0];
    if (!f) return;
    logoSrc = URL.createObjectURL(f);
    thumb.src = logoSrc;
    thumb.hidden = false;
    editors.hidden = false;
    layout();
  });
  // The backdrop's framing and picture feed the previews too.
  form.querySelectorAll('[data-range], input[name="hero_file"]').forEach((el) => el.addEventListener('input', () => setTimeout(layout, 0)));
  form.querySelectorAll('input[name="hero_file"]').forEach((el) => el.addEventListener('change', () => setTimeout(layout, 50)));
  if (heroImg) heroImg.addEventListener('load', layout);
  window.addEventListener('resize', layout);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(layout);
  layout();
})();
