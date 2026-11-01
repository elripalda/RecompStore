// (3.1) Inbox: messages you've seen fold to one line (click to open), new ones stay open, and a
// link to one (#m123) opens it.
(() => {
  const list = document.querySelector('section.list');
  if (!list) return;
  const items = [...list.querySelectorAll(':scope > .msg')];
  if (!items.length) return;
  const target = location.hash.replace('#', '');
  items.forEach((m) => {
    const head = m.querySelector(':scope > header');
    if (!head) return;
    const src = ['.sub .body', '.phone-report .body', ':scope > .body:not(.muted)', 'dd'].map((q) => m.querySelector(q)).find((x) => x && x.textContent.trim());
    const text = (src ? src.textContent : '').replace(/\s+/g, ' ').trim();
    if (text) { const p = document.createElement('p'); p.className = 'msg-peek'; p.textContent = text.slice(0, 220); head.after(p); }
    if (!m.classList.contains('unread') && m.id !== target) m.classList.add('folded');
    head.addEventListener('click', (e) => { if (e.target.closest('a, button, input, select, textarea')) return; m.classList.toggle('folded'); });
  });
  const bar = document.createElement('div');
  bar.className = 'inbox-tools';
  bar.innerHTML = `<span class="muted">${items.length} here · new ones are open, click any to open or fold it</span><span class="btns"><button type="button" data-all="open">Open all</button><button type="button" data-all="fold">Fold all</button></span>`;
  list.before(bar);
  bar.querySelectorAll('[data-all]').forEach((b) => b.addEventListener('click', () => items.forEach((m) => m.classList.toggle('folded', b.dataset.all === 'fold'))));
})();

// (3.3) Pick some, a whole day, or the whole page. (3.7) Then mark them done, open them again,
// delete them, or (logs) download them as one zip.
(() => {
  const form = document.getElementById('logs-pick');
  if (!form) return;
  const boxes = [...document.querySelectorAll('input.log-pick')];
  const all = form.querySelector('[data-pick-all]');
  const count = form.querySelector('[data-pick-count]');
  const gos = [...form.querySelectorAll('[data-pick-go]')];
  const days = [...document.querySelectorAll('[data-pick-day]')];
  const ofDay = (d) => boxes.filter((b) => b.closest('.msg') && b.closest('.msg').dataset.day === d);
  const tri = (box, set) => { const n = set.filter((b) => b.checked).length; box.checked = n === set.length && n > 0; box.indeterminate = n > 0 && n < set.length; };
  const sync = () => {
    const picked = boxes.filter((b) => b.checked);
    count.textContent = picked.length ? `${picked.length} picked` : 'None picked';
    gos.forEach((g) => { g.disabled = !picked.length; });
    tri(all, boxes);
    days.forEach((d) => tri(d, ofDay(d.dataset.pickDay)));
    form.ids.value = picked.map((b) => b.value).join(',');
  };
  boxes.forEach((b) => b.addEventListener('change', sync));
  all.addEventListener('change', () => { boxes.forEach((b) => { b.checked = all.checked; }); sync(); });
  days.forEach((d) => d.addEventListener('change', () => { ofDay(d.dataset.pickDay).forEach((b) => { b.checked = d.checked; }); sync(); }));
  sync();
})();
