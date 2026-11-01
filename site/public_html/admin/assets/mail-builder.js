// RecompStore admin (2.7.2): the email builder on the Mail page. The email is a list of blocks kept
// as JSON in the form (blocks); each block has its own little editor, and the preview on the right
// is the server's own rendering of it (mail.php, action=preview), so it's exactly what gets sent.
(() => {
  const form = document.querySelector('[data-mb]');
  if (!form) return;
  const json = form.querySelector('[data-mb-json]');
  const list = form.querySelector('[data-mb-blocks]');
  const frame = form.querySelector('[data-mb-preview]');
  const templates = JSON.parse(form.querySelector('[data-mb-templates]').textContent);
  const apps = JSON.parse(form.querySelector('[data-mb-apps]').textContent);
  let blocks = [];
  try { blocks = JSON.parse(json.value) || []; } catch (e) { blocks = []; }

  const NAMES = { heading: 'Heading', text: 'Text', button: 'Button', app: 'App', apps: 'Latest apps', image: 'Picture', divider: 'Line', spacer: 'Space' };
  const FRESH = {
    heading: { t: 'heading', text: '', size: 'big' }, text: { t: 'text', text: '' }, button: { t: 'button', label: '', url: 'https://recompstore.com/', style: 'main' },
    app: { t: 'app', id: apps[0] ? apps[0].id : '', big: true, note: '' }, apps: { t: 'apps', mode: 'new', count: 3, title: 'New in the store' },
    image: { t: 'image', url: '', alt: '', link: '' }, divider: { t: 'divider' }, spacer: { t: 'spacer' },
  };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const field = (i, k, label, v, o = {}) => o.area
    ? `<label>${label}<textarea data-i="${i}" data-k="${k}" rows="${o.rows || 4}">${esc(v)}</textarea>${o.small ? `<small>${o.small}</small>` : ''}</label>`
    : `<label>${label}<input data-i="${i}" data-k="${k}" value="${esc(v)}"${o.ph ? ` placeholder="${esc(o.ph)}"` : ''}>${o.small ? `<small>${o.small}</small>` : ''}</label>`;
  const select = (i, k, label, v, opts) => `<label>${label}<select data-i="${i}" data-k="${k}">${opts.map(([val, n]) => `<option value="${esc(val)}"${String(v) === String(val) ? ' selected' : ''}>${esc(n)}</option>`).join('')}</select></label>`;
  const check = (i, k, label, v) => `<label class="check"><input type="checkbox" data-i="${i}" data-k="${k}"${v ? ' checked' : ''}> ${label}</label>`;

  const editor = (b, i) => {
    switch (b.t) {
      case 'heading': return field(i, 'text', 'Heading', b.text) + select(i, 'size', 'Size', b.size, [['big', 'Big'], ['small', 'Small']]);
      case 'text': return field(i, 'text', 'Text', b.text, { area: true, rows: 6, small: 'A blank line starts a new paragraph. **bold**, links work on their own, and lines that start with "- " make a list.' });
      case 'button': return field(i, 'label', 'Button text', b.label) + field(i, 'url', 'Link', b.url, { ph: 'https://… or {invite}' }) + select(i, 'style', 'Look', b.style, [['main', 'Bright'], ['plain', 'Outline']]);
      case 'app': return select(i, 'id', 'App', b.id, apps.map((a) => [a.id, a.name])) + check(i, 'big', 'With its backdrop', b.big) + field(i, 'note', 'A line about it (optional)', b.note);
      case 'apps': return select(i, 'mode', 'Which', b.mode, [['new', 'Newest in the store'], ['updated', 'Just updated'], ['top', 'Most installed']]) + select(i, 'count', 'How many', b.count, [1, 2, 3, 4, 5, 6, 8].map((n) => [n, n])) + field(i, 'title', 'Title (optional)', b.title) + '<small class="muted">Filled in when it\'s sent, so it\'s always current.</small>';
      case 'image': return field(i, 'url', 'Picture address', b.url, { ph: 'https://recompstore.com/media/…' }) + field(i, 'alt', 'Describe it', b.alt) + field(i, 'link', 'Link (optional)', b.link, { ph: 'https://' });
      default: return '<small class="muted">No settings.</small>';
    }
  };
  const draw = () => {
    list.innerHTML = blocks.map((b, i) => `
      <div class="mb-block" data-block="${i}">
        <div class="mb-head"><strong>${NAMES[b.t] || b.t}</strong>
          <span class="mb-tools"><button type="button" data-move="-1" data-at="${i}" title="Up"${i === 0 ? ' disabled' : ''}>↑</button><button type="button" data-move="1" data-at="${i}" title="Down"${i === blocks.length - 1 ? ' disabled' : ''}>↓</button><button type="button" data-del="${i}" title="Remove" class="danger">✕</button></span></div>
        <div class="mb-fields">${editor(b, i)}</div>
      </div>`).join('') || '<p class="muted">Empty. Add a block below or pick a template.</p>';
    sync();
  };
  let timer = 0;
  const sync = () => {
    json.value = JSON.stringify(blocks);
    clearTimeout(timer);
    timer = setTimeout(preview, 350);
  };
  const preview = () => {
    const fd = new FormData();
    fd.append('action', 'preview'); fd.append('csrf', form.querySelector('[name="csrf"]').value); fd.append('blocks', json.value);
    fetch('mail.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then((r) => r.text()).then((html) => { frame.srcdoc = html; }).catch(() => {});
  };
  list.addEventListener('input', (e) => {
    const el = e.target;
    if (el.dataset.i === undefined) return;
    const b = blocks[+el.dataset.i];
    b[el.dataset.k] = el.type === 'checkbox' ? el.checked : el.dataset.k === 'count' ? +el.value : el.value;
    sync();
  });
  list.addEventListener('click', (e) => {
    const m = e.target.closest('[data-move]');
    if (m) { const i = +m.dataset.at, j = i + +m.dataset.move; [blocks[i], blocks[j]] = [blocks[j], blocks[i]]; draw(); return; }
    const d = e.target.closest('[data-del]');
    if (d) { blocks.splice(+d.dataset.del, 1); draw(); }
  });
  form.querySelectorAll('[data-mb-add]').forEach((b) => b.addEventListener('click', () => {
    blocks.push(JSON.parse(JSON.stringify(FRESH[b.dataset.mbAdd])));
    draw();
    const last = list.lastElementChild;
    if (last) { last.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); const f = last.querySelector('input,textarea'); if (f) f.focus(); }
  }));
  form.querySelector('[data-mb-template]').addEventListener('change', (e) => {
    const t = templates[e.target.value];
    if (!t) return;
    if (blocks.length && !confirm('Replace what\'s in the email now with the "' + t.name + '" template?')) { e.target.value = ''; return; }
    blocks = JSON.parse(JSON.stringify(t.blocks));
    if (t.subject) form.querySelector('[name="subject"]').value = t.subject;
    e.target.value = '';
    draw();
  });
  // Who it goes to
  const pick = form.querySelector('[data-mb-pick]');
  form.querySelectorAll('[name="audience"]').forEach((r) => r.addEventListener('change', () => { pick.hidden = form.querySelector('[name="audience"]:checked').value !== 'pick'; }));
  form.querySelector('[data-mb-send]').addEventListener('click', (e) => {
    const picked = form.querySelector('[name="audience"]:checked').value === 'pick';
    const n = picked ? form.querySelectorAll('[name="only[]"]:checked').length : null;
    if (picked && !n) { e.preventDefault(); alert('Tick the people it goes to first.'); return; }
    // (2.7.3) Sent this already today? Say so, and only send again on a clear yes.
    const btn = form.querySelector('[data-mb-send]');
    let recent = [];
    try { recent = JSON.parse(btn.dataset.recent || '[]'); } catch (x) { recent = []; }
    const subj = form.querySelector('[name="subject"]').value.trim();
    const same = recent.find((r) => r.subject.trim() === subj);
    const again = form.querySelector('[data-mb-again]');
    if (same) {
      if (!confirm(`You already sent "${subj}" today at ${same.when} (${same.sent} of ${same.total}).\n\nSend it to them AGAIN?`)) { e.preventDefault(); return; }
      if (again) again.value = '1';
      return;
    }
    if (!confirm(picked ? `Send this to the ${n} ${n === 1 ? 'person' : 'people'} you ticked?` : 'Send this to everyone on the developer list?')) e.preventDefault();
  });
  draw();
})();
