// Recomp Store admin (2.3): the Games list's tick boxes and the bar that does several games at
// once (admin/inc/bulk.php). The bar shows while any game is ticked; Apply asks once.
(function () {
  'use strict';
  const bar = document.querySelector('[data-bulk-bar]');
  if (!bar) return;
  const picks = () => [...document.querySelectorAll('[data-bulk-pick]')];
  const count = bar.querySelector('[data-bulk-count]');
  const op = bar.querySelector('select[name="op"]');
  const update = () => {
    const n = picks().filter((p) => p.checked).length;
    bar.hidden = n === 0;
    count.textContent = n + ' selected';
    picks().forEach((p) => p.closest('article')?.classList.toggle('bulk-on', p.checked));
  };
  document.addEventListener('change', (e) => { if (e.target.matches('[data-bulk-pick]')) update(); });
  bar.querySelector('[data-bulk-all]').addEventListener('click', () => {
    picks().forEach((p) => { if (!p.closest('article')?.hidden) p.checked = true; });
    update();
  });
  bar.querySelector('[data-bulk-none]').addEventListener('click', () => { picks().forEach((p) => (p.checked = false)); update(); });
  bar.addEventListener('submit', (e) => {
    const n = picks().filter((p) => p.checked).length;
    if (!op.value) { e.preventDefault(); op.focus(); return; }
    const what = op.options[op.selectedIndex].text;
    if (!window.confirm(what + ': ' + n + (n === 1 ? ' game' : ' games') + '?\n\nEach one is saved; nothing is published until you press Publish.')) e.preventDefault();
  });
  // Ticks the browser kept after Back.
  update();
})();
