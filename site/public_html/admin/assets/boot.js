// (4.3) The boot profile editor on a recomp's edit page: the JSON in the textarea stays what's saved,
// this edits it field by field and keeps anything it doesn't show (ids, regions, sliders...) as it is.
// The server checks it the same way it does on Save (pc.php?action=boot_check).
(() => {
  const root = document.querySelector('[data-boot-ed]');
  if (!root) return;
  const ta = root.querySelector('textarea[name=boot]');
  const formEl = root.querySelector('[data-boot-form]');
  const probsEl = root.querySelector('[data-boot-probs]');
  const usesEl = root.querySelector('[data-boot-uses]');
  const sumEl = root.querySelector('[data-boot-sum]');
  const builtin = root.dataset.builtin ? JSON.parse(root.dataset.builtin) : null;
  const csrf = root.dataset.csrf || '';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  const isObj = (v) => v && typeof v === 'object' && !Array.isArray(v);
  let b = null;
  let bad = false;
  const open = new Set();

  const get = (path) => path.split('.').reduce((o, k) => (isObj(o) ? o[k] : undefined), b);
  const set = (path, v) => {
    const ks = path.split('.');
    let o = b;
    ks.slice(0, -1).forEach((k) => { if (!isObj(o[k])) o[k] = {}; o = o[k]; });
    const last = ks[ks.length - 1];
    if (v === '' || v === false || v == null || (Array.isArray(v) && !v.length)) delete o[last]; else o[last] = v;
    // An emptied object goes too (installer: {} after then_start is cleared), except files.
    if (ks.length > 1 && ks[0] !== 'files') { const parent = get(ks.slice(0, -1).join('.')); if (isObj(parent) && !Object.keys(parent).length) delete b[ks[0]]; }
  };

  const HOW = [['self', 'The game asks for it itself'], ['folder', 'Easy Setup: into a folder of the game'], ['flag', 'Easy Setup: on the command line'], ['env', 'Easy Setup: in an environment variable'], ['setup', 'Easy Setup: to its own setup program'], ['none', 'Needs no copy'], ['unknown', 'Not worked out yet']];
  const EASY = ['folder', 'flag', 'env', 'setup'];
  const how = () => String(get('files.how') || '');
  const F = {
    start: [
      { k: 'kind', l: 'Kind', t: 'select', o: [['', '—'], 'portable', 'installer', 'single-exe', 'not-a-game', 'source', 'unknown'] },
      { k: 'start', l: 'Program to start', t: 'text', ph: 'bin/Game.exe', h: 'Its path in the release.' },
      { k: 'start_is', l: 'That program is', t: 'select', o: [['', '—'], ['game', 'The game'], ['launcher', 'Its own launcher'], ['script', 'A script']] },
      { k: 'start_linux', l: 'Linux program', t: 'text', ph: 'build/game', h: 'Only when it isn\'t the same as above.' },
      { k: 'start_macos', l: 'Mac program', t: 'text', ph: 'Game.app/Contents/MacOS/Game' },
      { k: 'args', l: 'Starts with', t: 'text', ph: '--fullscreen', h: 'Arguments on every start.' },
      { k: 'setup', l: 'Runs once first', t: 'text', ph: 'setup.exe', h: 'A program in the release that runs before the first play.' },
      { k: 'installer.then_start', l: 'After the installer, start', t: 'text', ph: 'Game.exe', show: () => get('kind') === 'installer', h: 'Its path in the folder it installs to.' },
      { k: 'console', l: 'Asks questions in a text window', t: 'check' },
    ],
    copy: [
      { k: 'files.how', l: 'Where the player\'s copy goes', t: 'select', o: HOW },
      { k: 'files.accepts', l: 'What they pick', t: 'select', o: [['', '—'], ['file', 'A file (ROM)'], ['iso', 'A disc image'], ['folder', 'A folder'], ['folder or iso', 'A folder or a disc image'], ['folder or file', 'A folder or a file']], show: () => how() !== 'none' },
      { k: 'files.what', l: 'What the player reads', t: 'text', wide: true, ph: 'Your Xbox 360 disc, as an ISO or extracted', show: () => how() !== 'none' },
      { k: 'files.folder', l: 'Into this folder', t: 'text', ph: '.', h: '"." for the top of the game\'s folder.', show: () => how() === 'folder' },
      { k: 'files.flag', l: 'After this argument', t: 'text', ph: '--game_data_root', h: 'Empty: the copy goes first on the command line.', show: () => how() === 'flag' },
      { k: 'files.env', l: 'Variable', t: 'text', ph: 'GAME_DATA', show: () => how() === 'env' },
      { k: 'files.contains', l: 'It has to contain', t: 'list', ph: 'default.xex, Media', h: 'Names the launcher checks for, comma between.', show: () => how() !== 'none' },
      { k: 'files.extract', l: 'Unpack a disc image as', t: 'select', o: [['', 'Worked out (or not at all)'], 'xdvdfs', 'iso9660'], show: () => how() === 'folder' || how() === 'flag' },
    ],
    notes: [
      { k: 'player_note', l: 'Note for players', t: 'area', wide: true, ph: 'The first start builds the game from your copy (about 5 minutes).', h: 'Shown on the game\'s page before they play.' },
      { k: 'multiplayer', l: 'Multiplayer', t: 'select', o: [['', 'None'], ['local', 'Local'], ['online', 'Online'], ['both', 'Local and online']] },
      { k: 'requires', l: 'Launcher features it needs', t: 'list', ph: 'files.folder, settings.toml', h: 'A launcher without one of them skips this profile\'s extras (Uses below lists what it uses).' },
      { k: 'min', l: 'Needs launcher version', t: 'text', ph: '0.90.0-beta.27' },
    ],
  };

  const field = (f) => {
    const v = get(f.k);
    const id = 'bf-' + f.k.replace(/\W/g, '-');
    const attrs = `id="${id}" data-k="${esc(f.k)}" data-t="${f.t}"`;
    let input;
    if (f.t === 'select') {
      const opts = f.o.map((o) => (Array.isArray(o) ? o : [o, o]));
      if (v != null && v !== '' && !opts.some((o) => o[0] === String(v))) opts.push([String(v), String(v) + ' (as written)']);
      input = `<select ${attrs}>${opts.map(([val, lab]) => `<option value="${esc(val)}"${String(v ?? '') === val ? ' selected' : ''}>${esc(lab)}</option>`).join('')}</select>`;
    } else if (f.t === 'check') {
      return `<label class="check" data-show-k="${esc(f.k)}"><input type="checkbox" ${attrs}${v ? ' checked' : ''}> ${esc(f.l)}</label>`;
    } else if (f.t === 'area') {
      input = `<textarea rows="2" ${attrs} placeholder="${esc(f.ph || '')}">${esc(v || '')}</textarea>`;
    } else {
      const val = f.t === 'list' ? (Array.isArray(v) ? v.join(', ') : v || '') : v ?? '';
      input = `<input ${attrs} class="mono" value="${esc(val)}" placeholder="${esc(f.ph || '')}" spellcheck="false">`;
    }
    return `<label class="${f.wide ? 'wide' : ''}" data-show-k="${esc(f.k)}">${esc(f.l)}${input}${f.h ? `<small>${esc(f.h)}</small>` : ''}</label>`;
  };
  const allFields = () => [...F.start, ...F.copy, ...F.notes];
  const vis = () => {
    allFields().forEach((f) => { const el = formEl.querySelector(`[data-show-k="${CSS.escape(f.k)}"]`); if (el) el.hidden = f.show ? !f.show() : false; });
    const e = formEl.querySelector('[data-easy]');
    if (e) e.innerHTML = EASY.includes(how()) ? '<span class="pill boot-easy">Easy Setup</span>' : '<span class="pill">No Easy Setup</span>';
  };

  // Settings: {config: [{id, format, file: [{path}]}], settings: [...]}
  const S = () => (isObj(b.settings) ? b.settings : null);
  const kv = (o) => Object.entries(o || {}).map(([k, v]) => `${k}=${v}`).join('\n');
  const parseKv = (s) => { const o = {}; String(s).split('\n').forEach((line) => { const i = line.indexOf('='); if (i > 0) o[line.slice(0, i).trim()] = line.slice(i + 1).trim(); }); return o; };
  const settingsHtml = () => {
    const s = S();
    if (!s) return `<p class="muted">No game settings. <button type="button" class="boot-mini" data-x="set-start">Add game settings</button></p>`;
    const cfg = Array.isArray(s.config) ? s.config : [];
    const list = Array.isArray(s.settings) ? s.settings : [];
    const ins = cfg.map((c) => String(c.id || 'main')).concat('args');
    const cfgRows = cfg.map((c, i) => {
      const files = Array.isArray(c.file) ? c.file : [];
      return `<div class="boot-ch" data-cfg="${i}"><input value="${esc(c.id || 'main')}" data-c="id" class="mono" placeholder="main" title="Its id: settings say which file they're in by this"><span style="display:flex;gap:6px"><select data-c="format">${['toml', 'ini', 'json'].map((f) => `<option${c.format === f ? ' selected' : ''}>${f}</option>`).join('')}</select><input value="${esc((files[0] || {}).path || '')}" data-c="path" class="mono" style="flex:1" placeholder="{install}/config.toml" spellcheck="false"></span><button type="button" class="boot-mini" data-x="cfg-del" data-i="${i}" title="Remove this file">✕</button></div>${files.length > 1 ? `<small>${files.length - 1} more place${files.length > 2 ? 's' : ''} it can be (edit as JSON).</small>` : ''}`;
    }).join('');
    const setRows = list.map((st, i) => {
      const isOpen = open.has(i);
      const special = st.slider ? 'slider' : st.bindings ? 'key bindings' : '';
      const n = (st.choices || []).length;
      const where = st.in || 'main';
      let body = '';
      if (isOpen) {
        const choices = (st.choices || []).map((ch, ci) => `<input value="${esc(ch.label || '')}" data-ch="${ci}" data-cf="label" placeholder="Label"><textarea rows="${Math.max(1, Object.keys(ch.set || {}).length || (ch.args || []).length)}" class="mono" data-ch="${ci}" data-cf="${where === 'args' ? 'args' : 'set'}" placeholder="${where === 'args' ? '--windowed (one per line)' : 'Section/key=value (one per line)'}" spellcheck="false">${esc(where === 'args' ? (ch.args || []).join('\n') : kv(ch.set))}</textarea><button type="button" class="boot-mini" data-x="ch-del" data-i="${i}" data-ci="${ci}">✕</button>`).join('');
        body = `<div class="boot-grid">
          <label>Label<input value="${esc(st.label || '')}" data-s="label"></label>
          <label>Id<input value="${esc(st.id || '')}" data-s="id" class="mono"></label>
          <label>In<select data-s="in">${ins.map((x) => `<option value="${esc(x)}"${where === x ? ' selected' : ''}>${x === 'args' ? 'The command line' : 'File: ' + esc(x)}</option>`).join('')}${ins.includes(where) ? '' : `<option selected>${esc(where)}</option>`}</select></label>
          <label>Heading<input value="${esc(st.group || '')}" data-s="group" placeholder="Graphics"></label>
          <label class="wide">Help<input value="${esc(st.help || '')}" data-s="help" placeholder="One short line under it"></label>
          <label class="check"><input type="checkbox" data-s="advanced"${st.advanced ? ' checked' : ''}> Only under Show Advanced Options</label>
        </div>
        ${special ? `<p class="muted">This one is a ${special}: edit it as JSON.</p>` : `<div class="boot-ch boot-ch-head"><span>Choice</span><span>${where === 'args' ? 'Arguments' : 'Sets'}</span><span></span></div><div class="boot-ch">${choices}</div><p><button type="button" class="boot-mini" data-x="ch-add" data-i="${i}">Add a choice</button></p>
        ${where === 'args' ? '' : `<label style="display:flex;flex-direction:column;gap:4px;font-size:13px">The game's own values, before the player picks<textarea rows="2" class="mono" data-s="defaults" spellcheck="false" placeholder="Section/key=value">${esc(kv(st.defaults))}</textarea><small>What the game uses when its file doesn't say yet, so the right choice shows.</small></label>`}`}`;
      }
      return `<div class="boot-set" data-set="${i}"><div class="boot-set-head"><b>${esc(st.label || st.id || 'Setting')}</b><span class="muted">${special || n + ' choice' + (n === 1 ? '' : 's')} · ${where === 'args' ? 'command line' : esc(where)}</span>
        <button type="button" data-x="set-up" data-i="${i}" title="Move up"${i ? '' : ' disabled'}>↑</button><button type="button" data-x="set-down" data-i="${i}" title="Move down"${i < list.length - 1 ? '' : ' disabled'}>↓</button><button type="button" data-x="set-open" data-i="${i}">${isOpen ? 'Done' : 'Edit'}</button><button type="button" data-x="set-del" data-i="${i}" title="Remove">✕</button></div>${body}</div>`;
    }).join('');
    return `<h5 class="muted" style="margin:0 0 6px;font-size:12px">Settings files</h5>${cfgRows || '<p class="muted">None yet.</p>'}<p><button type="button" class="boot-mini" data-x="cfg-add">Add a settings file</button> <small>Paths start with {install}/, {documents}/, {local}/, {config}/ or {home}/.</small></p>
      <h5 class="muted" style="margin:12px 0 6px;font-size:12px">Settings players see (${list.length})</h5>${setRows}<p><button type="button" class="boot-mini" data-x="set-add">Add a setting</button></p>`;
  };

  const render = () => {
    if (bad) { formEl.innerHTML = '<p class="bad-text">The JSON below isn\'t valid, so the fields are off until it is.</p>'; return; }
    if (!b || !Object.keys(b).length) {
      formEl.innerHTML = `<p class="muted">No boot profile: the launcher works out how to start it${builtin ? ', from the profile it has built in' : ''}.</p><p>${builtin ? '<button type="button" class="primary boot-mini" data-x="from-builtin">Start from the launcher\'s built-in profile</button> ' : ''}<button type="button" class="boot-mini" data-x="new">Start a new profile</button></p>`;
      return;
    }
    formEl.innerHTML = `<div class="boot-sec"><h4>Start</h4><div class="boot-grid">${F.start.map(field).join('')}</div></div>
      <div class="boot-sec"><h4>The player's copy <span data-easy></span></h4><div class="boot-grid">${F.copy.map(field).join('')}</div></div>
      <div class="boot-sec"><h4>Game settings</h4>${settingsHtml()}</div>
      <div class="boot-sec"><h4>For players and launchers</h4><div class="boot-grid">${F.notes.map(field).join('')}</div></div>`;
    vis();
  };

  let timer = 0;
  const check = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      if (bad) { probsEl.innerHTML = '<li class="err">Not valid JSON.</li>'; return; }
      if (!ta.value.trim()) { probsEl.innerHTML = ''; usesEl.innerHTML = ''; if (sumEl) sumEl.textContent = ''; return; }
      try {
        const fd = new FormData(); fd.append('csrf', csrf); fd.append('action', 'boot_check'); fd.append('boot', ta.value);
        const r = await (await fetch('pc.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json();
        probsEl.innerHTML = (r.problems || []).length ? r.problems.map(([t, m]) => `<li class="${t}">${t === 'err' ? 'Fix: ' : ''}${esc(m)}</li>`).join('') : '<li class="ok">Looks right to the launcher.</li>';
        usesEl.innerHTML = (r.uses || []).length ? 'Uses: ' + r.uses.map((u) => `<code>${esc(u)}</code>`).join('') : '';
        if (sumEl) sumEl.textContent = r.summary || '';
      } catch (e) { /* the check is a help, not a gate: Save checks again */ }
    }, 450);
  };
  const write = () => { ta.value = b && Object.keys(b).length ? JSON.stringify(b, null, 4) : ''; check(); };
  const parse = () => {
    const t = ta.value.trim();
    if (!t) { b = {}; bad = false; return; }
    try { const v = JSON.parse(t); if (!isObj(v)) throw 0; b = v; bad = false; } catch (e) { bad = true; }
  };

  formEl.addEventListener('input', (e) => {
    const el = e.target;
    if (el.dataset.k) {
      const f = allFields().find((x) => x.k === el.dataset.k);
      let v = el.type === 'checkbox' ? el.checked : el.value;
      if (f && f.t === 'list') v = String(v).split(',').map((x) => x.trim()).filter(Boolean);
      set(el.dataset.k, v);
      vis(); write(); return;
    }
    const s = S();
    const cfgRow = el.closest('[data-cfg]');
    if (cfgRow && s) {
      const c = s.config[+cfgRow.dataset.cfg];
      if (el.dataset.c === 'path') { c.file = Array.isArray(c.file) && c.file.length ? c.file : [{}]; c.file[0].path = el.value; } else c[el.dataset.c] = el.value;
      write(); return;
    }
    const setEl = el.closest('[data-set]');
    if (setEl && s) {
      const st = s.settings[+setEl.dataset.set];
      if (el.dataset.ch != null) {
        const ch = st.choices[+el.dataset.ch];
        if (el.dataset.cf === 'label') ch.label = el.value;
        else if (el.dataset.cf === 'args') { ch.args = el.value.split('\n').map((x) => x.trim()).filter(Boolean); delete ch.set; }
        else { ch.set = parseKv(el.value); delete ch.args; }
      } else if (el.dataset.s) {
        const k = el.dataset.s;
        if (k === 'advanced') { if (el.checked) st.advanced = true; else delete st.advanced; }
        else if (k === 'defaults') { const d = parseKv(el.value); if (Object.keys(d).length) st.defaults = d; else delete st.defaults; }
        else if (el.value === '' && k !== 'label' && k !== 'id') delete st[k]; else st[k] = el.value;
        if (k === 'label' || k === 'in') { const head = setEl.querySelector('.boot-set-head b'); if (head && k === 'label') head.textContent = el.value || st.id; if (k === 'in') { write(); render(); return; } }
      }
      write();
    }
  });
  formEl.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-x]');
    if (!btn) return;
    e.preventDefault();
    const x = btn.dataset.x; const i = +btn.dataset.i;
    if (x === 'from-builtin') b = JSON.parse(JSON.stringify(builtin));
    else if (x === 'new') b = { kind: 'portable', start: '', start_is: 'game', files: { how: 'self', accepts: 'file', what: '' } };
    else {
      if (x === 'set-start') b.settings = { config: [{ id: 'main', format: 'toml', file: [{ path: '{install}/config.toml' }] }], settings: [] };
      const s = S();
      if (s) {
        s.config = Array.isArray(s.config) ? s.config : [];
        s.settings = Array.isArray(s.settings) ? s.settings : [];
        if (x === 'cfg-add') s.config.push({ id: s.config.length ? 'file' + (s.config.length + 1) : 'main', format: 'toml', file: [{ path: '{install}/' }] });
        if (x === 'cfg-del' && confirm('Remove this settings file? Settings in it stop showing.')) s.config.splice(i, 1);
        if (x === 'set-add') { s.settings.push({ id: 'setting' + (s.settings.length + 1), label: 'New setting', in: (s.config[0] || {}).id || 'main', choices: [{ label: 'Off', set: {} }, { label: 'On', set: {} }] }); open.add(s.settings.length - 1); }
        if (x === 'set-del' && confirm('Remove "' + (s.settings[i].label || s.settings[i].id) + '"?')) { s.settings.splice(i, 1); open.clear(); }
        if (x === 'set-open') { if (open.has(i)) open.delete(i); else open.add(i); }
        if ((x === 'set-up' && i > 0) || (x === 'set-down' && i < s.settings.length - 1)) { const j = x === 'set-up' ? i - 1 : i + 1; [s.settings[i], s.settings[j]] = [s.settings[j], s.settings[i]]; open.clear(); }
        if (x === 'ch-add') { s.settings[i].choices = s.settings[i].choices || []; s.settings[i].choices.push({ label: '', set: {} }); }
        if (x === 'ch-del') s.settings[i].choices.splice(+btn.dataset.ci, 1);
        if (!s.config.length && !s.settings.length && x !== 'set-start') delete b.settings;
      }
    }
    write(); render();
  });

  ta.addEventListener('input', () => { parse(); render(); check(); });
  parse(); render(); check();
})();
