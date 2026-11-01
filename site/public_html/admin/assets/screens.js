// RecompStore admin (2.6): a game's screenshots, dragged into order (or moved with the arrows)
// and removed; the order goes back in a hidden field. New files are counted against the limit.
(function () {
  'use strict';
  const list = document.querySelector('[data-shots]');
  if (!list) return;
  const order = document.querySelector('[data-shots-order]');
  const empty = document.querySelector('[data-shots-empty]');
  const files = document.querySelector('[data-shot-files]');
  const count = document.querySelector('[data-shot-count]');
  const max = Number(list.dataset.max) || 8;
  const items = () => [...list.children];

  function sync() {
    order.value = JSON.stringify(items().map((li) => li.dataset.shotUrl));
    items().forEach((li, i) => {
      li.querySelector('img').alt = 'Screenshot ' + (i + 1);
      li.querySelector('[data-shot-move="-1"]').disabled = i === 0;
      li.querySelector('[data-shot-move="1"]').disabled = i === items().length - 1;
    });
    if (empty) empty.hidden = items().length > 0;
    checkFiles();
  }
  function checkFiles() {
    if (!files || !count) return;
    const room = max - items().length, picked = files.files ? files.files.length : 0;
    count.textContent = picked > room ? `Only ${room} more fit: remove some, or pick fewer.` : picked ? `${picked} to add.` : `${room} more fit.`;
    count.classList.toggle('warn', picked > room);
  }

  list.addEventListener('click', (e) => {
    const li = e.target.closest('li');
    if (!li) return;
    const mv = e.target.closest('[data-shot-move]');
    if (mv) {
      const d = Number(mv.dataset.shotMove), all = items(), i = all.indexOf(li);
      if (d < 0 && i > 0) list.insertBefore(li, all[i - 1]);
      if (d > 0 && i < all.length - 1) list.insertBefore(li, all[i + 1].nextSibling);
      sync();
      const again = li.querySelector(`[data-shot-move="${d}"]`);
      (again && !again.disabled ? again : li.querySelector('[data-shot-remove]')).focus();
    } else if (e.target.closest('[data-shot-remove]')) {
      const next = li.nextElementSibling || li.previousElementSibling;
      li.remove();
      sync();
      if (next) next.querySelector('[data-shot-remove]').focus();
    }
  });

  // Dragging with a mouse: the picked one moves to where it's dropped.
  let dragged = null;
  list.addEventListener('dragstart', (e) => {
    dragged = e.target.closest('li');
    if (!dragged) return;
    dragged.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragged.dataset.shotUrl);
  });
  list.addEventListener('dragover', (e) => {
    if (!dragged) return;
    e.preventDefault();
    const over = e.target.closest('li');
    if (!over || over === dragged) return;
    const r = over.getBoundingClientRect();
    const after = e.clientX > r.left + r.width / 2;
    list.insertBefore(dragged, after ? over.nextSibling : over);
  });
  list.addEventListener('drop', (e) => { if (dragged) e.preventDefault(); });
  list.addEventListener('dragend', () => {
    if (dragged) dragged.classList.remove('dragging');
    dragged = null;
    sync();
  });
  if (files) files.addEventListener('change', checkFiles);
  sync();
})();
