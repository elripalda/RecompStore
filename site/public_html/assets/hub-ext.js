// RecompStore / RecompLauncher (3.0): more for the developer hub, kept in its own file next to
// site.js. It waits for site.js to draw the hub (or the For developers page) and adds to it:
//  - what they make, PC, PS5 or both: asked once (a pop-up), on the sign-in card and in the request
//    form, and used to show the hub's tabs that matter to them;
//  - claiming their recomps and apps, at the top of Your apps and PC recomps;
//  - a Press kit tab: their own badges, ready to copy;
//  - a Messages tab: their conversation with the team, and the Lounge every developer shares.
// Every English text goes through t(); assets/i18n/hub/<code>.json has the translations.
(() => {
  'use strict';
  // (4.8) The hub is recomplauncher.com/dev on RecompLauncher's address, recompstore.com/hub on RecompStore's.
  const HUB = /(^|\.)recomplauncher\./i.test(location.hostname) ? '/dev' : '/hub';
  const VER = ((document.currentScript && document.currentScript.src.match(/[?&]v=([\w.-]+)/)) || [])[1] || '';
  let dict = {};
  const dicts = {};
  // One load per language, shared by everyone who asks; a failed one is tried again next time.
  const loadDict = async () => {
    const l = document.documentElement.lang || 'en';
    if (l === 'en') { dict = {}; return; }
    if (!dicts[l]) dicts[l] = fetch(`/assets/i18n/hub/${l}.json${VER ? '?v=' + VER : ''}`).then((r) => (r.ok ? r.json() : null)).catch(() => null);
    const d = await dicts[l];
    if (!d) delete dicts[l];
    if ((document.documentElement.lang || 'en') === l) dict = d || {};
  };
  const t = (s) => (Object.prototype.hasOwnProperty.call(dict, s) && dict[s] ? dict[s] : s);
  const tf = (s, v) => t(s).replace(/\{(\w+)\}/g, (m, k) => (v && k in v ? String(v[k]) : m));
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fail = () => ({ ok: false, error: t('That didn\'t go through. Check your connection and try again.') });
  const get = (a, q) => fetch('/api/dev.php?' + new URLSearchParams(Object.assign({ a }, q || {})), { cache: 'no-store', credentials: 'same-origin' }).then((r) => r.json()).catch(() => null);
  const post = (a, data) => fetch('/api/dev.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ a }, data || {})) })
    .then((r) => r.json()).catch(fail);
  const say = (el, res, okText) => { if (!el) return; el.textContent = res.ok ? (res.message ? t(res.message) : okText || t('Saved.')) : (res.error ? t(res.error) : t('That didn\'t go through.')); el.className = 'status ' + (res.ok ? 'ok' : 'bad'); };
  const lang = () => document.documentElement.lang || 'en';
  const when = (iso) => { try { return new Date(iso).toLocaleString(lang(), { dateStyle: 'medium', timeStyle: 'short' }); } catch (e) { return iso; } };
  // Links in a message, clickable; the rest as text.
  const linkify = (s) => esc(s).replace(/(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>').replace(/\n/g, '<br>');
  const SAVED = 'rs-dev-platforms';
  const readSaved = () => { try { return (localStorage.getItem(SAVED) || '').split(',').filter((x) => x === 'pc' || x === 'ps5'); } catch (e) { return []; } };
  const keep = (list) => { try { if (list.length) localStorage.setItem(SAVED, list.join(',')); else localStorage.removeItem(SAVED); } catch (e) { /* only a convenience */ } };
  const ICON = {
    pc: '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>',
    ps5: '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.5 9h11a3.5 3.5 0 0 1 3.4 4.3l-.8 3.4a2.2 2.2 0 0 1-3.9.8L14.6 15H9.4l-1.6 2.5a2.2 2.2 0 0 1-3.9-.8l-.8-3.4A3.5 3.5 0 0 1 6.5 9z"/><path d="M8 11.5v2M7 12.5h2"/></svg>',
    check: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
  };
  const PLAT = { pc: () => t('PC recomps'), ps5: () => t('PS5 apps') };

  // ---- What they make ------------------------------------------------------------------------------
  // The choice, as two cards to tick (one or both).
  const platPicker = (chosen, name) => `<div class="hx-plats" role="group" aria-label="${esc(t('What do you make?'))}">
    <label class="hx-plat"><input type="checkbox" name="${name}" value="pc"${chosen.includes('pc') ? ' checked' : ''}><span class="hx-plat-card">${ICON.pc}<strong>${esc(t('PC recomps'))}</strong><small>${esc(t('For RecompLauncher, on Windows, Linux and Mac'))}</small><i class="hx-tick">${ICON.check}</i></span></label>
    <label class="hx-plat"><input type="checkbox" name="${name}" value="ps5"${chosen.includes('ps5') ? ' checked' : ''}><span class="hx-plat-card">${ICON.ps5}<strong>${esc(t('PS5 apps'))}</strong><small>${esc(t('For RecompStore, on jailbroken PS5s'))}</small><i class="hx-tick">${ICON.check}</i></span></label>
  </div>`;
  const picked = (root, name) => [...root.querySelectorAll(`input[name="${name}"]:checked`)].map((x) => x.value);

  // The pop-up: shown once, until they pick. Resolves with what they picked (or [] if they close it).
  const askPlatforms = (chosen) => new Promise((resolve) => {
    const d = document.createElement('dialog');
    d.className = 'hx-modal glass';
    d.setAttribute('aria-labelledby', 'hx-modal-h');
    d.innerHTML = `<form method="dialog" class="hx-modal-in">
        <span class="kicker">${esc(t('Developer hub'))}</span>
        <h2 id="hx-modal-h">${esc(t('What are you making?'))}</h2>
        <p class="muted">${esc(t('Pick one or both. Your hub shows what matters for each, and you can change it any time.'))}</p>
        ${platPicker(chosen, 'hx-m')}
        <div class="hx-modal-row"><span class="status" role="status"></span><button class="btn primary" type="submit" value="save">${esc(t('Continue'))}</button></div>
      </form>`;
    document.body.append(d);
    const f = d.querySelector('form'), st = d.querySelector('.status'), btn = d.querySelector('button[type=submit]');
    const sync = () => { btn.disabled = !picked(f, 'hx-m').length; };
    f.addEventListener('change', sync);
    sync();
    let done = false;
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      const list = picked(f, 'hx-m');
      if (!list.length) return;
      btn.disabled = true;
      post('platforms', { pc: list.includes('pc'), ps5: list.includes('ps5') }).then((res) => {
        btn.disabled = false;
        if (!res.ok) { say(st, res); return; }
        keep(res.platforms);
        done = true;
        d.close();
        resolve(res.platforms);
      });
    });
    d.addEventListener('close', () => { d.remove(); if (!done) resolve([]); });
    d.showModal();
  });

  // ---- The For developers page: the choice on the sign-in card, kept for the hub ------------------
  const devsPage = (page) => {
    const auth = page.querySelector('.dev-auth');
    if (!auth || auth.dataset.hx) return;
    auth.dataset.hx = '1';
    const first = auth.querySelector('a.btn.primary[href*="a=login"]');
    if (!first) return;
    const box = document.createElement('div');
    box.className = 'hx-auth-plats';
    box.innerHTML = `<span class="hx-auth-ask">${esc(t('What do you make?'))}</span>${platPicker(readSaved(), 'hx-a')}`;
    first.before(box);
    box.addEventListener('change', () => keep(picked(box, 'hx-a')));
  };

  // ---- The hub -------------------------------------------------------------------------------------
  const S = { me: null, plats: [], tab: null, poll: 0, deep: null, doc: '', room: 'dm', docs: null };

  const headPills = (page) => {
    const who = page.querySelector('.hub-who .muted');
    if (!who) return;
    let box = who.querySelector('.hx-pills');
    if (!box) { box = document.createElement('span'); box.className = 'hx-pills'; who.append(box); }
    box.innerHTML = S.plats.map((p) => `<span class="tag">${esc(p === 'pc' ? 'PC' : 'PS5')}</span>`).join('')
      + `<button type="button" class="linkish" data-hx-plats>${esc(S.plats.length ? t('Change') : t('PC or PS5?'))}</button>`;
    box.querySelector('[data-hx-plats]').addEventListener('click', () => askPlatforms(S.plats).then((list) => { if (list.length) { S.plats = list; headPills(page); shapeTabs(page); } }));
  };

  const SITE_PS5 = ['apps', 'betas', 'logs', 'ids'];
  const unreadCount = () => { const u = (S.me && S.me.account && S.me.account.unread) || {}; return Number(u.dm || 0) + Number(u.lounge || 0); };

  // The tabs that matter: PS5 ones for PS5 developers, PC recomps for PC developers, everyone's
  // Your page, plus Press kit and Messages.
  const shapeTabs = (page) => {
    const bar = page.querySelector('.hub-tabs');
    if (!bar) return;
    const pc = S.plats.includes('pc'), ps5 = S.plats.includes('ps5'), any = S.plats.length > 0;
    const hasPs5Apps = (S.me.apps || []).length > 0, hasPc = (S.me.pcApps || []).length > 0;
    bar.querySelectorAll('[data-tab]').forEach((b) => {
      const k = b.dataset.tab;
      b.hidden = any && ((SITE_PS5.includes(k) && !ps5 && !hasPs5Apps) || (k === 'pc' && !pc && !hasPc));
    });
    if (!bar.querySelector('[data-xtab]')) {
      bar.insertAdjacentHTML('beforeend', `<button type="button" role="tab" aria-selected="false" data-xtab="press">${esc(t('Press kit'))}</button>`
        + `<button type="button" role="tab" aria-selected="false" data-xtab="docs">${esc(t('Docs'))}</button>`
        + `<button type="button" role="tab" aria-selected="false" data-xtab="msgs">${esc(t('Messages'))}<span class="hx-dot" hidden></span></button>`);
      bar.querySelectorAll('[data-xtab]').forEach((b) => b.addEventListener('click', () => openX(page, b.dataset.xtab)));
      // Their own tabs: ours go off, theirs draws.
      bar.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => { S.tab = b.dataset.tab; offX(bar); setUrl(''); }));
    }
    const dot = bar.querySelector('[data-xtab="msgs"] .hx-dot');
    const n = unreadCount();
    dot.hidden = !n;
    dot.textContent = !n ? "" : n > 99 ? '99+' : String(n);
    // A PC developer's hub opens on their recomps, and sends new ones in as a PC game.
    const sub = page.querySelector('.hub-submit');
    if (sub) {
      if ((pc && !ps5) || HUB === '/dev') { sub.href = '/submit?platform=pc'; sub.textContent = '+ ' + t('Submit a game'); } else { sub.href = '/submit'; sub.textContent = '+ ' + t('Submit an app'); }
    }
    const totals = page.querySelector('.hub-totals');
    if (totals) {
      const pcOnly = pc && !ps5 && !hasPs5Apps;
      if (pcOnly && !totals.dataset.hx) {
        totals.dataset.hx = '1';
        const sum = (k) => (S.me.pcApps || []).reduce((n2, a) => n2 + Number((a.stats || {})[k] || 0), 0);
        const num = (v) => Number(v || 0).toLocaleString(lang());
        totals.innerHTML = `<div><dt>${esc(t('Recomps'))}</dt><dd>${num((S.me.pcApps || []).length)}</dd></div><div><dt>${esc(t('GitHub downloads'))}</dt><dd>${num(sum('downloads'))}</dd></div>`
          + `<div><dt>${esc(t('GitHub stars'))}</dt><dd>${num(sum('stars'))}</dd></div><div><dt>${esc(t('Plays great'))}</dt><dd>${num(sum('runs'))}</dd></div><div><dt>${esc(t('Has problems'))}</dt><dd>${num(sum('issues') + sum('broken'))}</dd></div>`;
      }
    }
    const on = bar.querySelector('[data-tab].on');
    if (on && on.hidden) { const first = [...bar.querySelectorAll('[data-tab]')].find((b) => !b.hidden); if (first) first.click(); }
  };
  const offX = (bar) => bar.querySelectorAll('[data-xtab]').forEach((x) => { x.classList.remove('on'); x.setAttribute('aria-selected', 'false'); });
  // The address shows the tab you're on (and the doc), so a link to it works.
  const setUrl = (q) => { try { history.replaceState(history.state, '', HUB + (q ? '?' + q : '')); } catch (e) { /* only for links */ } };
  const openX = (page, which, arg) => {
    const bar = page.querySelector('.hub-tabs');
    bar.querySelectorAll('[data-tab],[data-xtab]').forEach((x) => { const on = x.dataset.xtab === which; x.classList.toggle('on', on); x.setAttribute('aria-selected', String(on)); });
    S.tab = which;
    const btn = bar.querySelector(`[data-xtab="${which}"]`);
    if (btn && bar.scrollWidth > bar.clientWidth) btn.scrollIntoView({ inline: 'center', block: 'nearest' });
    const pane = page.querySelector('[data-pane]');
    if (which === 'press') { setUrl('tab=press'); pressPane(pane); }
    else if (which === 'docs') docsPane(pane, arg || S.doc || '');
    else { setUrl('tab=messages'); msgsPane(pane, arg || S.room || 'dm'); }
  };

  // ---- Claiming ------------------------------------------------------------------------------------
  // At the top of Your apps (PS5) and PC recomps: theirs by GitHub to claim in one click, a search
  // for anything else, and the claims they've made.
  const claimCard = (pane, pc) => {
    if (!S.me || !S.me.claims || pane.querySelector('.hx-claims')) return;
    const c = S.me.claims;
    const ready = c.ready.filter((x) => x.pc === pc), mine = c.claims.filter((x) => x.pc === pc);
    const box = document.createElement('section');
    box.className = 'glass card-pad hx-claims';
    const pic = (x) => x.pic ? `<img src="${esc(x.pic)}" alt="" width="${pc ? 32 : 40}" height="${pc ? 48 : 40}" class="${pc ? 'hx-cover' : 'hx-icon'}">` : '<span class="hx-nopic"></span>';
    const row = (x, act) => `<li>${pic(x)}<span class="hx-claim-name"><strong>${esc(x.name)}</strong>${x.repo ? `<small class="muted">${x.host === 'gitlab' ? 'gitlab.com' : 'github.com'}/${esc(x.repo)}</small>` : ''}</span>${act}</li>`;
    const stateTag = (s) => s === 'approved' ? `<span class="tag good">${esc(t('On your page'))}</span>` : s === 'rejected' ? `<span class="tag warn">${esc(t('Not confirmed'))}</span>` : `<span class="tag info">${esc(t('Waiting for review'))}</span>`;
    box.innerHTML = `
      <div class="hx-claims-head"><div><h2>${esc(pc ? t('Claim your recomps') : t('Claim your apps'))}</h2>
        <p class="muted">${esc(pc ? t('Claimed recomps carry your name and link to your developer page. Ones on your GitHub are yours straight away; anything else, we check first.') : t('Claimed apps carry your name and link to your developer page. Ones on your GitHub are yours straight away; anything else, we check first.'))}</p></div></div>
      ${ready.length ? `<ul class="hx-claim-list">${ready.map((x) => row(x, `<button type="button" class="btn primary" data-claim="${esc(x.key)}">${esc(t('Claim'))}</button>`)).join('')}</ul>`
        : `<p class="hx-claim-none">${ICON.check}<span>${esc(pc ? t('Every one of your recomps on GitHub is on your page.') : t('Every one of your apps on GitHub is on your page.'))}</span></p>`}
      <details class="hx-claim-more"><summary>${esc(t('Made one that isn\'t on your GitHub? Find it'))}</summary>
        <form class="hx-claim-find" novalidate><input name="q" maxlength="80" autocomplete="off" placeholder="${esc(pc ? t('Recomp or game name') : t('App name'))}" aria-label="${esc(t('Search'))}"><button class="btn" type="submit">${esc(t('Search'))}</button></form>
        <ul class="hx-claim-list" data-found></ul>
      </details>
      ${mine.length ? `<h3>${esc(t('Your claims'))}</h3><ul class="hx-claim-list hx-claim-mine">${mine.map((x) => row(x, stateTag(x.status))).join('')}</ul>` : ''}
      <p class="status" role="status"></p>`;
    pane.prepend(box);
    const st = box.querySelector('.status');
    const claim = (key, note, btn) => {
      if (btn) btn.disabled = true;
      post('claim', { app: key, note: note || '' }).then((res) => {
        say(st, res);
        if (res.ok && res.instant) { say(st, res); setTimeout(() => location.reload(), 1400); return; }
        if (res.ok && res.claims) { S.me.claims = res.claims; box.remove(); claimCard(pane, pc); say(pane.querySelector('.hx-claims .status'), res); }
        else if (btn) btn.disabled = false;
      });
    };
    box.querySelectorAll('[data-claim]').forEach((b) => b.addEventListener('click', () => claim(b.dataset.claim, '', b)));
    const find = box.querySelector('.hx-claim-find'), out = box.querySelector('[data-found]');
    find.addEventListener('submit', (e) => {
      e.preventDefault();
      out.innerHTML = `<li class="muted">${esc(t('Looking…'))}</li>`;
      get('claim-find', { q: find.q.value.trim() }).then((j) => {
        const list = ((j && j.found) || []).filter((x) => x.pc === pc);
        out.innerHTML = list.length ? list.map((x) => row(x, `<span class="hx-claim-ask"><input maxlength="300" placeholder="${esc(t('How it\'s yours (a link helps)'))}" aria-label="${esc(t('How it\'s yours'))}"><button type="button" class="btn" data-claim-ask="${esc(x.key)}">${esc(t('Claim'))}</button></span>`)).join('')
          : `<li class="muted">${esc(t('Nothing found by that name.'))}</li>`;
        out.querySelectorAll('[data-claim-ask]').forEach((b) => b.addEventListener('click', () => claim(b.dataset.claimAsk, b.previousElementSibling.value.trim(), b)));
      });
    });
  };

  // ---- Press kit -----------------------------------------------------------------------------------
  const snip = (id, label, text) => `<div class="press-snip"><span class="kicker">${esc(label)}</span><span class="cmd-row"><code class="cmd" id="${id}">${esc(text)}</code><button class="copy" type="button" data-copy="#${id}">${esc(t('Copy'))}</button></span></div>`;
  const pressPane = (pane) => {
    const kits = [];
    const me = S.me;
    const ps5Apps = (me.apps || []).filter((a) => !a.soon), pcApps = me.pcApps || [];
    if (ps5Apps.length || S.plats.includes('ps5')) kits.push({ key: 'ps5', title: t('Get it on RecompStore'), K: 'https://recompstore.com', A: '/assets/press/', alt: 'Get it on RecompStore', apps: ps5Apps, store: 'RecompStore' });
    if (pcApps.length || S.plats.includes('pc')) kits.push({ key: 'pc', title: t('Get it on RecompLauncher'), K: 'https://recomplauncher.com', A: '/assets/press/launcher/', alt: 'Get it on RecompLauncher', apps: pcApps, store: 'RecompLauncher' });
    if (!kits.length) kits.push({ key: 'ps5', title: t('Get it on RecompStore'), K: 'https://recompstore.com', A: '/assets/press/', alt: 'Get it on RecompStore', apps: [], store: 'RecompStore' });
    const prof = me.profile;
    pane.innerHTML = `
      <section class="hx-press-intro glass card-pad"><div><h2>${esc(t('Your press kit'))}</h2>
        <p class="muted">${esc(t('Badges for your README, your releases and your website, made for your apps and ready to copy. Each one links straight to its page, so players land in the right place.'))}</p></div>
        <div class="hx-press-dl">${kits.map((k) => `<a class="btn" href="${k.key === 'pc' ? 'https://recomplauncher.com/assets/press/launcher/recomplauncher-press-kit.zip' : 'https://recompstore.com/assets/press/recompstore-press-kit.zip'}" download>${esc(tf('{store} press kit (.zip)', { store: k.store }))}</a>`).join('')}</div></section>
      <div class="hx-press-grid${kits.length > 1 ? ' two' : ''}">${kits.map((k) => `
        <section class="glass card-pad hx-press-kit" data-kit="${k.key}">
          <span class="kicker">${esc(k.key === 'pc' ? 'PC' : 'PS5')}</span><h2>${esc(k.title)}</h2>
          <div class="press-pick">
            <label>${esc(t('For'))}<select data-app>${k.apps.map((a) => `<option value="${esc(a.id)}">${esc(a.name)}</option>`).join('')}<option value="">${esc(k.store)}</option></select></label>
            <div class="chips filter" role="group" aria-label="${esc(t('Badge'))}"><button type="button" class="tag on" data-theme="dark">${esc(t('Dark'))}</button><button type="button" class="tag" data-theme="light">${esc(t('Light'))}</button></div>
          </div>
          <div class="press-preview" data-preview></div>
          <div data-snips></div>
        </section>`).join('')}</div>
      <div class="hx-press-foot${prof && prof.verified ? ' two' : ''}">
      ${prof && prof.verified ? (() => {
        const url = `https://recompstore.com/dev/${encodeURIComponent(prof.slug)}`, img = 'https://recompstore.com/assets/press/verified-dark.svg';
        return `<section class="glass card-pad hx-press-verified"><div class="hx-press-v-art"><img src="/assets/press/verified-dark.svg" alt="Verified developer on RecompStore" height="48"></div>
          <div><h2>${esc(t('Verified developer badge'))}</h2><p class="muted">${esc(t('Shows players your work comes from a verified developer, and links to your page.'))}</p>
          ${snip('hx-v-md', t('For your README (Markdown)'), `[![Verified developer on RecompStore](${img})](${url})`)}</div></section>`;
      })() : ''}
      <section class="glass card-pad hx-press-rules"><span class="kicker">${esc(t('Using the badges'))}</span><ul class="press-rules">
        <li>${esc(t('Use them as they are. Don\'t recolor, stretch or redraw them.'))}</li>
        <li>${esc(t('Give them room, and use the light versions on dark backgrounds.'))}</li>
        <li>${esc(t('Write the names as one word: RecompStore and RecompLauncher.'))}</li>
        <li>${tf('Logos, colors and more are on the press pages: {ps5} and {pc}.', { ps5: '<a href="https://recompstore.com/press" target="_blank" rel="noopener">recompstore.com/press</a>', pc: '<a href="https://recomplauncher.com/press" target="_blank" rel="noopener">recomplauncher.com/press</a>' })}</li></ul></section></div>`;
    kits.forEach((k) => {
      const box = pane.querySelector(`[data-kit="${k.key}"]`);
      let theme = 'dark';
      const draw = () => {
        const id = box.querySelector('[data-app]').value;
        const app = k.apps.find((a) => a.id === id);
        const url = app ? `${k.K}/game/${encodeURIComponent(app.id)}` : `${k.K}/`;
        const svg = `${k.K}${k.A}badge-${theme}.svg`, png = `${k.K}${k.A}badge-${theme}.png`;
        const prev = box.querySelector('[data-preview]');
        prev.className = 'press-preview ' + (theme === 'light' ? 'on-dark' : 'on-light');
        prev.innerHTML = `<a href="${esc(url)}" target="_blank" rel="noopener"><img src="${esc(k.A)}badge-${theme}.svg" alt="${esc(k.alt)}" height="56"></a>`;
        box.querySelector('[data-snips]').innerHTML = snip(`hx-${k.key}-md`, t('For your README (Markdown)'), `[![${k.alt}](${svg})](${url})`)
          + snip(`hx-${k.key}-html`, 'HTML', `<a href="${url}"><img src="${svg}" alt="${k.alt}" height="56"></a>`)
          + snip(`hx-${k.key}-bb`, t('For forums (BBCode)'), `[url=${url}][img]${png}[/img][/url]`);
      };
      box.querySelector('[data-app]').addEventListener('change', draw);
      box.querySelectorAll('[data-theme]').forEach((b) => b.addEventListener('click', () => { theme = b.dataset.theme; box.querySelectorAll('[data-theme]').forEach((x) => x.classList.toggle('on', x === b)); draw(); }));
      draw();
    });
  };

  // ---- Docs ----------------------------------------------------------------------------------------
  // Guides for approved developers, the ones for what they make: a list, then each one with its
  // contents down the side, ready to download as Markdown or save as a PDF.
  const AUD = { pc: () => t('RecompLauncher · PC'), ps5: () => t('RecompStore · PS5'), all: () => t('For everyone') };
  let mdLoad = null;
  const needMd = () => {
    if (window.RSmd) return Promise.resolve();
    if (!mdLoad) mdLoad = new Promise((ok, no) => { const sc = document.createElement('script'); sc.src = '/assets/hub-md.js' + (VER ? '?v=' + VER : ''); sc.onload = ok; sc.onerror = () => { mdLoad = null; no(); }; document.head.append(sc); });
    return mdLoad;
  };
  const saveFile = (name, text) => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([text], { type: 'text/markdown;charset=utf-8' }));
    a.download = name;
    document.body.append(a);
    a.click();
    setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
  };
  const asMd = (d) => `# ${d.title}\n\n${d.summary ? '> ' + d.summary + '\n\n' : ''}${String(d.body || '').trim()}\n`;
  const day = (iso) => { try { return new Date(iso + 'T12:00:00Z').toLocaleDateString(lang(), { dateStyle: 'medium' }); } catch (e) { return iso; } };
  const docsPane = async (pane, slug) => {
    clearInterval(S.poll);
    pane.innerHTML = `<section class="hx-docs"><p class="muted">${esc(t('Loading…'))}</p></section>`;
    const box = pane.querySelector('.hx-docs');
    let j;
    try { [j] = await Promise.all([get('docs'), needMd()]); } catch (e) { j = null; }
    if (!box.isConnected) return;
    if (!j || !j.ok) { box.innerHTML = `<p class="status bad">${esc(fail().error)}</p>`; return; }
    S.docs = j.docs;
    if (slug && j.docs.some((d) => d.slug === slug)) docRead(box, slug);
    else docList(box);
  };
  const docList = (box) => {
    S.doc = '';
    setUrl('tab=docs');
    const docs = S.docs;
    const groups = ['pc', 'ps5', 'all'].map((k) => [k, docs.filter((d) => d.audience === k)]).filter(([, l]) => l.length);
    box.innerHTML = `
      <section class="glass card-pad hx-docs-intro">
        <div><span class="kicker">${esc(t('Developer docs'))}</span><h2>${esc(t('Build it, ship it, look after it'))}</h2>
          <p class="muted">${esc(t('Guides for what you make, in the order to read them. Download any of them as Markdown to keep or to give your AI assistant, or save one as a PDF.'))}</p></div>
        ${docs.length ? `<button type="button" class="btn" data-all-md>${esc(t('Download all (.md)'))}</button>` : ''}
      </section>
      ${groups.length ? groups.map(([k, list]) => `
        <section class="glass card-pad hx-doc-group">
          <h3><span class="tag ${k === 'ps5' ? 'info' : k === 'pc' ? 'good' : ''}">${esc(k === 'all' ? t('Everyone') : k === 'pc' ? 'PC' : 'PS5')}</span>${esc(AUD[k]())}</h3>
          <ol class="hx-doc-list">${list.map((d, i) => `
            <li><a href="${HUB}?tab=docs&amp;doc=${encodeURIComponent(d.slug)}" data-doc="${esc(d.slug)}">
              <span class="hx-doc-n">${i + 1}</span>
              <span class="hx-doc-words"><strong>${esc(d.title)}</strong>${d.summary ? `<small>${esc(d.summary)}</small>` : ''}</span>
              <span class="hx-doc-min">${esc(tf('{n} min read', { n: d.minutes }))}</span>
            </a></li>`).join('')}</ol>
        </section>`).join('') : `<section class="glass card-pad"><p class="muted">${esc(t('No docs for you yet. They show up here when they\'re ready.'))}</p></section>`}`;
    box.querySelectorAll('[data-doc]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); docRead(box, a.dataset.doc); }));
    const all = box.querySelector('[data-all-md]');
    if (all) all.addEventListener('click', async () => {
      all.disabled = true;
      const full = await Promise.all(docs.map((d) => get('doc', { slug: d.slug }).then((r) => (r && r.ok ? r.doc : null))));
      saveFile('developer-docs.md', full.filter(Boolean).map(asMd).join('\n---\n\n'));
      all.disabled = false;
    });
    window.scrollTo({ top: Math.max(0, box.getBoundingClientRect().top + window.scrollY - 120), behavior: 'smooth' });
  };
  const docRead = async (box, slug) => {
    const j = await get('doc', { slug });
    if (!box.isConnected) return;
    if (!j || !j.ok) { docList(box); return; }
    const d = j.doc;
    S.doc = slug;
    setUrl('tab=docs&doc=' + encodeURIComponent(slug));
    const r = window.RSmd(d.body, { idPrefix: 'd-' });
    const same = S.docs.filter((x) => x.audience === d.audience);
    const at = S.docs.findIndex((x) => x.slug === slug);
    const prev = S.docs[at - 1], next = S.docs[at + 1];
    const meta = S.docs[at] || {};
    box.innerHTML = `
      <div class="hx-doc-wrap">
        <aside class="hx-doc-side">
          <button type="button" class="linkish hx-doc-back" data-back>← ${esc(t('All docs'))}</button>
          <span class="hx-side-h">${esc(AUD[d.audience]())}</span>
          <ol class="hx-doc-nav">${same.map((x, i) => `<li><a href="${HUB}?tab=docs&amp;doc=${encodeURIComponent(x.slug)}" data-doc="${esc(x.slug)}"${x.slug === slug ? ' aria-current="page"' : ''}><span>${i + 1}</span>${esc(x.title)}</a></li>`).join('')}</ol>
          ${r.toc.length > 1 ? `<span class="hx-side-h">${esc(t('On this page'))}</span><ul class="hx-doc-toc">${r.toc.map((h) => `<li class="l${h.level}"><a href="#d-${esc(h.id)}" data-toc="d-${esc(h.id)}">${esc(h.text)}</a></li>`).join('')}</ul>` : ''}
        </aside>
        <article class="glass hx-doc-article">
          <header class="hx-doc-head">
            <span class="kicker">${esc(AUD[d.audience]())}</span>
            <h2>${esc(d.title)}</h2>
            ${d.summary ? `<p class="hx-doc-sum">${esc(d.summary)}</p>` : ''}
            <div class="hx-doc-meta"><span class="muted">${esc(tf('Updated {date}', { date: day(d.updated) }))}${meta.minutes ? ' · ' + esc(tf('{n} min read', { n: meta.minutes })) : ''}</span>
              <span class="hx-doc-acts"><button type="button" class="btn" data-md>${esc(t('Download .md'))}</button><button type="button" class="btn" data-pdf>${esc(t('Save as PDF'))}</button><button type="button" class="btn" data-link>${esc(t('Copy link'))}</button></span></div>
          </header>
          <div class="md hx-md">${r.html}</div>
          <nav class="hx-doc-pager">
            ${prev ? `<a href="${HUB}?tab=docs&amp;doc=${encodeURIComponent(prev.slug)}" data-doc="${esc(prev.slug)}" class="prev"><small>${esc(t('Previous'))}</small><strong>${esc(prev.title)}</strong></a>` : '<span></span>'}
            ${next ? `<a href="${HUB}?tab=docs&amp;doc=${encodeURIComponent(next.slug)}" data-doc="${esc(next.slug)}" class="next"><small>${esc(t('Next'))}</small><strong>${esc(next.title)}</strong></a>` : '<span></span>'}
          </nav>
        </article>
      </div>`;
    box.querySelector('[data-back]').addEventListener('click', () => docList(box));
    box.querySelectorAll('[data-doc]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); docRead(box, a.dataset.doc); }));
    box.querySelectorAll('[data-toc]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); const h = document.getElementById(a.dataset.toc); if (h) h.scrollIntoView({ behavior: 'smooth', block: 'start' }); }));
    // Code blocks get a Copy button.
    box.querySelectorAll('.hx-md pre').forEach((pre) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'copy hx-code-copy';
      b.textContent = t('Copy');
      b.addEventListener('click', () => { navigator.clipboard && navigator.clipboard.writeText(pre.querySelector('code').textContent).then(() => { b.textContent = t('Copied'); setTimeout(() => { b.textContent = t('Copy'); }, 1500); }); });
      pre.append(b);
    });
    box.querySelector('[data-md]').addEventListener('click', () => saveFile(slug + '.md', asMd(d)));
    box.querySelector('[data-pdf]').addEventListener('click', () => {
      // The browser's own Save as PDF, with only the doc on the page.
      document.body.classList.add('hx-print');
      const done = () => { document.body.classList.remove('hx-print'); window.removeEventListener('afterprint', done); };
      window.addEventListener('afterprint', done);
      window.print();
      setTimeout(done, 1500);
    });
    const lb = box.querySelector('[data-link]');
    lb.addEventListener('click', () => { navigator.clipboard && navigator.clipboard.writeText(`${location.origin}${HUB}?tab=docs&doc=${encodeURIComponent(slug)}`).then(() => { lb.textContent = t('Copied'); setTimeout(() => { lb.textContent = t('Copy link'); }, 1500); }); });
    window.scrollTo({ top: Math.max(0, box.getBoundingClientRect().top + window.scrollY - 120), behavior: 'smooth' });
  };

  // ---- Messages ------------------------------------------------------------------------------------
  // A conversation with the team, and the Lounge's channels like a Discord server: General and
  // Showcase for everyone, PC recomps and PS5 apps for the developers who make them.
  const ROOMS = {
    general: { name: 'general', about: () => t('Every approved developer can read this. Share builds, tools, tips and links.') },
    showcase: { name: 'showcase', about: () => t('Show what you\'re working on: builds, screenshots and videos.') },
    pc: { name: 'pc-recomps', about: () => t('For developers making PC recomps.') },
    ps5: { name: 'ps5-apps', about: () => t('For developers making PS5 apps.') },
  };
  const myRooms = () => {
    const p = S.plats;
    return Object.keys(ROOMS).filter((r) => !['pc', 'ps5'].includes(r) || !p.length || p.includes(r));
  };
  const roomUnread = (r) => { const u = (S.me.account.unread || {}); return r === 'dm' ? Number(u.dm || 0) : Number((u.rooms || {})[r] || 0); };
  const bubble = (m) => `<div class="hx-msg${m.mine ? ' mine' : ''}${m.team ? ' team' : ''}">
      ${m.mine ? '' : `<span class="hx-msg-pic">${m.pic ? `<img src="${esc(m.pic)}" alt="" width="34" height="34">` : `<span>${esc(String(m.who || '?').slice(0, 1).toUpperCase())}</span>`}</span>`}
      <div class="hx-msg-body"><span class="hx-msg-who">${m.mine ? esc(t('You')) : (m.slug ? `<a href="/dev/${encodeURIComponent(m.slug)}">${esc(m.who)}</a>` : esc(m.who))}${m.team ? ` <span class="tag info">${esc(t('Team'))}</span>` : ''}</span>
        <p>${linkify(m.body)}</p><span class="hx-msg-when">${esc(when(m.at))}${m.mine && m.lounge ? ` · <button type="button" class="linkish" data-del="${m.id}">${esc(t('Delete'))}</button>` : ''}</span></div></div>`;
  const msgsPane = (pane, room) => {
    clearInterval(S.poll);
    const approved = S.me.account.status === 'approved';
    if (!approved || (room !== 'dm' && !myRooms().includes(room))) room = 'dm';
    if (approved) S.room = room;
    const dm = room === 'dm';
    const chan = (r) => `<button type="button" class="hx-chan${r === room ? ' on' : ''}" data-room="${r}"${r === room ? ' aria-current="true"' : ''}>${r === 'dm' ? `<span class="hx-chan-ico">${ICON.check.replace('M5 12.5l4.5 4.5L19 7.5', 'M4 6h16v10H8l-4 4z')}</span>${esc(t('The RecompStore team'))}` : `<span class="hx-hash">#</span>${esc(ROOMS[r].name)}`}<span class="hx-dot"${roomUnread(r) ? '' : ' hidden'}>${roomUnread(r) || ''}</span></button>`;
    pane.innerHTML = `
      <section class="glass hx-msgs${approved ? ' hx-chat' : ''}">
        ${approved ? `<aside class="hx-chans">
          <span class="hx-side-h">${esc(t('Direct'))}</span>${chan('dm')}
          <span class="hx-side-h">${esc(t('Lounge'))}</span>${myRooms().map(chan).join('')}
        </aside>` : ''}
        <div class="hx-chat-main">
          <div class="hx-msgs-top">
            <h2>${dm ? esc(t('The RecompStore team')) : `<span class="hx-hash">#</span>${esc(ROOMS[room].name)}`}</h2>
            <p class="muted">${esc(dm ? t('Questions, ideas, anything about your apps: just between you and us.') : ROOMS[room].about())}</p>
          </div>
          <div class="hx-flow" aria-live="polite"><p class="muted">${esc(t('Loading…'))}</p></div>
          <form class="hx-send" novalidate>
            <textarea name="body" rows="3" maxlength="4000" placeholder="${esc(dm ? t('Write to the RecompStore team…') : tf('Write in #{room}…', { room: ROOMS[room].name }))}" aria-label="${esc(t('Message'))}"></textarea>
            <div class="hx-send-row"><span class="status" role="status"></span><button class="btn primary" type="submit">${esc(dm ? t('Send') : t('Post'))}</button></div>
          </form>
        </div>
      </section>`;
    const flow = pane.querySelector('.hx-flow'), f = pane.querySelector('.hx-send'), st = f.querySelector('.status');
    const paint = (list) => {
      if (!flow.isConnected) return;
      const atEnd = flow.scrollHeight - flow.scrollTop - flow.clientHeight < 40;
      flow.innerHTML = list.length ? list.map((m) => bubble(Object.assign({ lounge: !dm }, m))).join('')
        : `<p class="muted hx-empty">${esc(dm ? t('No messages yet. Say hello: we read every one.') : t('Nothing here yet. Start the conversation.'))}</p>`;
      if (atEnd || !flow.dataset.seen) flow.scrollTop = flow.scrollHeight;
      flow.dataset.seen = '1';
      flow.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', () => post('lounge-delete', { id: Number(b.dataset.del), room }).then((res) => { if (res.ok) paint(res.posts); else say(st, res); })));
    };
    const seen = () => {
      const u = S.me.account.unread || (S.me.account.unread = {});
      if (dm) u.dm = 0;
      else { u.rooms = Object.assign({}, u.rooms || {}); u.rooms[room] = 0; u.lounge = Object.values(u.rooms).reduce((a, b) => a + Number(b || 0), 0); }
      const b = pane.querySelector(`.hx-chan[data-room="${room}"] .hx-dot`);
      if (b) { b.hidden = true; b.textContent = ''; }
      document.querySelectorAll('.hub-page').forEach((p) => shapeTabs(p));
    };
    const load = () => get(dm ? 'msgs' : 'lounge', dm ? {} : { room }).then((j) => {
      if (!j || !j.ok) { if (!flow.dataset.seen) flow.innerHTML = `<p class="status bad">${esc(fail().error)}</p>`; return; }
      paint(dm ? j.msgs : j.posts);
      seen();
    });
    load();
    S.poll = setInterval(() => { if (!flow.isConnected) { clearInterval(S.poll); return; } if (!document.hidden) load(); }, 30000);
    pane.querySelectorAll('[data-room]').forEach((b) => b.addEventListener('click', () => { if (approved) setUrl('tab=messages' + (b.dataset.room !== 'dm' ? '&room=' + b.dataset.room : '')); msgsPane(pane, b.dataset.room); }));
    f.body.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) f.requestSubmit(); });
    f.addEventListener('submit', (e) => {
      e.preventDefault();
      const body = f.body.value.trim();
      if (!body) { f.body.focus(); return; }
      const btn = f.querySelector('button[type=submit]');
      btn.disabled = true;
      post(dm ? 'msg' : 'lounge-post', dm ? { body } : { body, room }).then((res) => {
        btn.disabled = false;
        if (!res.ok) { say(st, res); return; }
        f.body.value = '';
        st.textContent = '';
        paint(dm ? res.msgs : res.posts);
        flow.scrollTop = flow.scrollHeight;
      });
    });
  };
  // ---- Waiting for approval: the choice in the request form, and messages while they wait ---------
  const pending = (page) => {
    const wait = page.querySelector('.hub-wait');
    if (!wait || wait.dataset.hx) return;
    wait.dataset.hx = '1';
    const ask = wait.querySelector('.hub-ask');
    if (ask) {
      const row = ask.querySelector('.form-row');
      const box = document.createElement('div');
      box.className = 'hx-ask-plats';
      box.innerHTML = `<span class="hx-auth-ask">${esc(t('What do you make?'))}</span>${platPicker(S.plats, 'hx-r')}`;
      row.before(box);
      // Sent with the request (their handler sends the rest).
      ask.addEventListener('submit', () => {
        const list = picked(box, 'hx-r');
        if (list.length) { keep(list); post('platforms', { pc: list.includes('pc'), ps5: list.includes('ps5') }); }
      }, true);
    }
    const holder = document.createElement('div');
    holder.className = 'hx-wait-msgs';
    wait.after(holder);
    msgsPane(holder, 'dm');
  };

  // ---- Wiring --------------------------------------------------------------------------------------
  // site.js redraws the hub now and then (after a request, a save, a tab change), so everything here
  // is added again whenever it's missing.
  const firstTab = (page) => {
    const want = S.deep;
    S.deep = null;
    // (3.9) A link to the reports (from the email about new ones) opens on them.
    if (want === 'reports') {
      const b = page.querySelector('[data-tab="reports"]');
      if (b) { if (!b.classList.contains('on')) b.click(); return; }
    }
    if (want === 'messages' || want === 'press' || want === 'docs') {
      const q = new URLSearchParams(location.search);
      openX(page, want === 'press' ? 'press' : want === 'docs' ? 'docs' : 'msgs', want === 'docs' ? (q.get('doc') || '') : (q.get('room') || ''));
      return;
    }
    if (S.plats.includes('pc') && !S.plats.includes('ps5') && !(S.me.apps || []).length) {
      const b = page.querySelector('[data-tab="pc"]');
      if (b && !b.classList.contains('on')) b.click();
    }
  };
  const refresh = (page) => {
    if (!S.me) return;
    const who = page.querySelector('.hub-who .muted');
    if (who && !who.querySelector('.hx-pills')) headPills(page);
    const bar = page.querySelector('.hub-tabs');
    if (bar && !bar.dataset.hx) { bar.dataset.hx = '1'; shapeTabs(page); firstTab(page); }
    else if (bar) decorate(page);
    const wait = page.querySelector('.hub-wait');
    if (wait && !wait.dataset.hx) pending(page);
  };
  const hub = async (page) => {
    if (page.dataset.hxBusy) return;
    page.dataset.hxBusy = '1';
    await loadDict();
    const me = await get('me');
    if (!me || !me.account || !page.isConnected) { delete page.dataset.hxBusy; return; }
    S.me = me;
    S.plats = me.account.platforms || [];
    S.deep = new URLSearchParams(location.search).get('tab');
    if (!S.plats.length) {
      const saved = readSaved();
      if (saved.length) { const res = await post('platforms', { pc: saved.includes('pc'), ps5: saved.includes('ps5') }); if (res.ok) S.plats = res.platforms; }
    }
    page.dataset.hx = '1';
    refresh(page);
    if (!S.plats.length) askPlatforms([]).then((list) => {
      if (!list.length) return;
      S.plats = list;
      headPills(page);
      shapeTabs(page);
      page.querySelectorAll('.hx-ask-plats input').forEach((x) => { x.checked = list.includes(x.value); });
    });
  };
  // Claims go at the top of Your apps and PC recomps whenever site.js draws them.
  const decorate = (page) => {
    const bar = page.querySelector('.hub-tabs');
    const pane = page.querySelector('[data-pane]');
    if (!bar || !pane || !S.me) return;
    const x = bar.querySelector('[data-xtab].on');
    if (x) {
      // A tab of site.js's that was still loading can land in the pane after ours opened.
      if (!pane.querySelector({ press: '.hx-press-intro', docs: '.hx-docs', msgs: '.hx-msgs' }[x.dataset.xtab])) openX(page, x.dataset.xtab);
      return;
    }
    const on = bar.querySelector('[data-tab].on');
    if (!on) return;
    if (on.dataset.tab === 'apps' && pane.querySelector('.hub-apps, section')) claimCard(pane, false);
    if (on.dataset.tab === 'pc' && pane.querySelector('.hub-apps, section')) claimCard(pane, true);
  };
  const scan = () => {
    document.querySelectorAll('.devs-page').forEach((p) => { if (!p.querySelector('.dev-auth[data-hx]')) loadDict().then(() => devsPage(p)); });
    document.querySelectorAll('.hub-page').forEach((p) => {
      if (!p.dataset.hx && !p.dataset.hxBusy && (p.querySelector('.hub-bar') || p.querySelector('.hub-wait'))) hub(p);
      else if (p.dataset.hx) refresh(p);
    });
  };
  let queued = false;
  new MutationObserver(() => { if (queued) return; queued = true; requestAnimationFrame(() => { queued = false; scan(); }); }).observe(document.body, { childList: true, subtree: true });
  if (document.readyState !== 'loading') scan(); else document.addEventListener('DOMContentLoaded', scan);
})();
