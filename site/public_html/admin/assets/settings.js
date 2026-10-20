// Recomp Store admin (2.2): Settings' Store tabs order (up and down), What's new's preview and the
// ShadowMountPlus download's wait.
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
  // (2.2) What's new: the preview, drawn as the store's sheet. Lines starting with "- " are bullets,
  // a blank line starts a new paragraph, a single line break stays one.
  const wn = document.querySelector('[data-wn]');
  if (wn) {
    const esc = (t) => t.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const ver = wn.querySelector('[data-wn-version]'), title = wn.querySelector('[data-wn-title]'), body = wn.querySelector('[data-wn-body]');
    const count = wn.querySelector('[data-wn-count]'), out = document.querySelector('[data-wn-preview]');
    const blocks = (text) => {
      const html = [];
      let para = [], list = [];
      const flush = () => {
        if (para.length) html.push(`<p>${para.map(esc).join('<br>')}</p>`);
        if (list.length) html.push(`<ul>${list.map((x) => `<li>${esc(x)}</li>`).join('')}</ul>`);
        para = []; list = [];
      };
      text.replace(/\r\n?/g, '\n').split('\n').forEach((raw) => {
        const line = raw.replace(/\s+$/, '');
        if (!line.trim()) { flush(); return; }
        if (/^- /.test(line)) { if (para.length) flush(); list.push(line.slice(2)); return; }
        if (list.length) flush();
        para.push(line);
      });
      flush();
      return html.join('');
    };
    const draw = () => {
      const v = ver.value.trim() || '…';
      title.placeholder = "What's new in " + (ver.value.trim() || '2.2.2');
      out.innerHTML = `<span class="wn-kick">Recomp Store ${esc(v)}</span><h3>${esc(title.value.trim() || "What's new in " + v)}</h3>${blocks(body.value) || '<p class="muted">Nothing written yet.</p>'}`;
      count.textContent = body.value.length.toLocaleString();
    };
    [ver, title, body].forEach((el) => el.addEventListener('input', draw));
    draw();
  }
  document.querySelectorAll('[data-smp-pin]').forEach((f) => f.addEventListener('submit', () => {
    document.querySelectorAll('[data-smp-pin] button').forEach((b) => { b.disabled = true; });
    const b = f.querySelector('button');
    b.textContent = 'Downloading…';
  }));
})();
