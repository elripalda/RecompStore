// Recomp Store admin (2.2): Settings' Store tabs order (up and down) and the ShadowMountPlus
// download's wait.
(function () {
  'use strict';
  const list = document.querySelector('[data-tabs] .tab-order');
  if (list) {
    const sync = () => {
      const rows = [...list.querySelectorAll('[data-tab]')];
      rows.forEach((li, i) => {
        li.querySelector('[data-tab-move="-1"]').disabled = i === 0;
        li.querySelector('[data-tab-move="1"]').disabled = i === rows.length - 1;
      });
    };
    list.addEventListener('click', (e) => {
      const b = e.target.closest('[data-tab-move]');
      if (!b) return;
      const li = b.closest('[data-tab]');
      if (+b.dataset.tabMove < 0 && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
      else if (+b.dataset.tabMove > 0 && li.nextElementSibling) list.insertBefore(li.nextElementSibling, li);
      sync();
      b.focus();
    });
    sync();
  }
  document.querySelectorAll('[data-smp-pin]').forEach((f) => f.addEventListener('submit', () => {
    document.querySelectorAll('[data-smp-pin] button').forEach((b) => { b.disabled = true; });
    const b = f.querySelector('button');
    b.textContent = 'Downloading…';
  }));
})();
