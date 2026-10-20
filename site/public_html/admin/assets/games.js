// Recomp Store admin: the Games list's order. Drag a game by its handle (mouse, pen or touch),
// or use the arrows (and the arrow keys, Home and End on a focused handle); nothing is saved
// until Save order, which sends the whole list.
(function () {
  const list = document.querySelector('[data-order]');
  const bar = document.querySelector('[data-order-bar]');
  if (!list || !bar) return;
  const rows = () => [...list.querySelectorAll('article[data-id]')];
  const order = () => rows().map((r) => r.dataset.id);
  const start = order();
  const dirty = () => order().join('\n') !== start.join('\n');

  // Arrows disabled at the ends, and the Save bar while the order differs from the saved one.
  const sync = () => {
    const all = rows();
    all.forEach((r, i) => {
      r.querySelectorAll('[data-move="top"],[data-move="up"]').forEach((b) => (b.disabled = i === 0));
      r.querySelectorAll('[data-move="down"]').forEach((b) => (b.disabled = i === all.length - 1));
    });
    bar.hidden = !dirty();
  };

  // A short slide from where a row was to where it is now (FLIP), for the arrow moves.
  const slide = (moved) => {
    const before = new Map(rows().map((r) => [r, r.getBoundingClientRect().top]));
    moved();
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    rows().forEach((r) => {
      const dy = before.get(r) - r.getBoundingClientRect().top;
      if (!dy) return;
      r.style.transition = 'none';
      r.style.transform = `translateY(${dy}px)`;
      requestAnimationFrame(() => {
        r.style.transition = 'transform .18s ease';
        r.style.transform = '';
      });
    });
  };
  const move = (row, where) => {
    const all = rows();
    const i = all.indexOf(row);
    if (where === 'top' && i > 0) slide(() => list.prepend(row));
    else if (where === 'bottom' && i < all.length - 1) slide(() => list.append(row));
    else if (where === 'up' && i > 0) slide(() => list.insertBefore(row, all[i - 1]));
    else if (where === 'down' && i < all.length - 1) slide(() => list.insertBefore(row, all[i + 1].nextSibling));
    sync();
  };

  list.addEventListener('click', (e) => {
    const b = e.target.closest('[data-move]');
    if (!b) return;
    move(b.closest('article[data-id]'), b.dataset.move);
    if (!b.disabled) b.focus();
    else b.closest('article').querySelector('[data-grip]')?.focus();
  });
  list.addEventListener('keydown', (e) => {
    const grip = e.target.closest('[data-grip]');
    if (!grip) return;
    const where = { ArrowUp: 'up', ArrowDown: 'down', Home: 'top', End: 'bottom' }[e.key];
    if (!where) return;
    e.preventDefault();
    move(grip.closest('article[data-id]'), where);
    grip.focus();
  });

  // Dragging: the row follows the pointer, and the others make room as it passes their middle.
  let drag = null;
  const place = () => {
    if (!drag) return;
    const { row, y } = drag;
    const others = rows().filter((r) => r !== row);
    let before = null;
    for (const r of others) {
      const b = r.getBoundingClientRect();
      if (y < b.top + b.height / 2) { before = r; break; }
    }
    if (before !== row.nextElementSibling || (!before && list.lastElementChild !== row)) {
      const tops = new Map(others.map((r) => [r, r.getBoundingClientRect().top]));
      list.insertBefore(row, before);
      others.forEach((r) => {
        const dy = tops.get(r) - r.getBoundingClientRect().top;
        if (!dy) return;
        r.style.transition = 'none';
        r.style.transform = `translateY(${dy}px)`;
        requestAnimationFrame(() => { r.style.transition = 'transform .15s ease'; r.style.transform = ''; });
      });
    }
    // Where the row would sit, then the pointer's offset from there.
    row.style.transform = '';
    const natural = row.getBoundingClientRect().top;
    row.style.transform = `translateY(${y - drag.grab - natural}px)`;
  };
  const tick = () => {
    if (!drag) return;
    if (!drag.moved) { drag.raf = requestAnimationFrame(tick); return; } /* a press alone scrolls nothing */
    // Near the window's top or bottom edge, scroll so a game can go all the way up or down.
    const edge = 90;
    const v = drag.y < edge ? -Math.ceil((edge - drag.y) / 6) : drag.y > innerHeight - edge ? Math.ceil((drag.y - innerHeight + edge) / 6) : 0;
    if (v) { window.scrollBy(0, v); place(); }
    drag.raf = requestAnimationFrame(tick);
  };
  list.addEventListener('pointerdown', (e) => {
    const grip = e.target.closest('[data-grip]');
    if (!grip || e.button !== 0 || drag) return;
    e.preventDefault();
    const row = grip.closest('article[data-id]');
    const b = row.getBoundingClientRect();
    drag = { row, grip, id: e.pointerId, y: e.clientY, y0: e.clientY, grab: e.clientY - b.top, raf: 0, moved: false };
    grip.setPointerCapture(e.pointerId);
    row.classList.add('dragging');
    document.body.classList.add('dragging');
    drag.raf = requestAnimationFrame(tick);
  });
  // On the window, not the list: moving the row in the page ends the handle's pointer capture,
  // so the pointer's events then go wherever it is (the header, while scrolling up).
  window.addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    drag.y = e.clientY;
    if (!drag.moved && Math.abs(drag.y - drag.y0) < 4) return; /* a few pixels of wobble isn't a drag */
    drag.moved = true;
    place();
  });
  const end = (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const { row, grip, raf } = drag;
    drag = null;
    cancelAnimationFrame(raf);
    row.style.transition = 'transform .15s ease';
    row.style.transform = '';
    row.classList.remove('dragging');
    document.body.classList.remove('dragging');
    if (grip.hasPointerCapture(e.pointerId)) grip.releasePointerCapture(e.pointerId);
    grip.focus({ preventScroll: true });
    sync();
  };
  window.addEventListener('pointerup', end);
  window.addEventListener('pointercancel', end);

  bar.querySelector('[data-order-undo]').addEventListener('click', () => {
    slide(() => start.forEach((id) => list.append(list.querySelector(`article[data-id="${CSS.escape(id)}"]`))));
    sync();
  });
  bar.addEventListener('submit', () => {
    bar.querySelector('input[name="order"]').value = JSON.stringify(order());
    window.removeEventListener('beforeunload', warn);
  });
  // Leaving with the new order unsaved: the browser asks first. Publish says what it will send.
  const warn = (e) => { if (dirty()) { e.preventDefault(); e.returnValue = ''; } };
  window.addEventListener('beforeunload', warn);
  const publish = document.querySelector('[data-publish-games]');
  if (publish)
    publish.addEventListener('submit', (e) => {
      if (dirty() && !confirm('The new order isn\'t saved. Publish with the order as it was saved?')) e.preventDefault();
      else window.removeEventListener('beforeunload', warn);
    });
  sync();
})();

// (2.1) Find: shows only the games whose name, ID or author has what's typed. The order isn't
// touched (Save order still sends every game, hidden or not).
(function () {
  const box = document.querySelector('[data-find]');
  const none = document.querySelector('[data-find-none]');
  if (!box) return;
  box.addEventListener('input', () => {
    const q = box.value.trim().toLowerCase();
    let shown = 0;
    document.querySelectorAll('article[data-find-text]').forEach((r) => {
      const hit = q === '' || r.dataset.findText.includes(q);
      r.hidden = !hit;
      if (hit) shown++;
    });
    if (none) none.hidden = shown > 0;
  });
})();
