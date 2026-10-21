// Recomp Store admin (2.3): jump links down the left of Settings, in groups (Security, Signer,
// Store, Discover, Betas, Integrations, Website). Only finds the cards that are there and links to
// them; nothing on the page works any differently. A card it doesn't know goes under Other. On a
// phone the links fold into "Jump to" at the top.
(function () {
  'use strict';
  const main = document.querySelector('main');
  const head = main && main.querySelector(':scope > .head-row');
  if (!head) return;
  const cards = [...main.querySelectorAll(':scope > section.card')];
  if (cards.length < 3) return;
  const title = (c) => ((c.querySelector('h2') || {}).textContent || '').replace(/\s+/g, ' ').trim();
  // Cards without an id of their own get one, found by what's in them.
  const guess = [
    ['general', (c) => c.querySelector('input[name="store_version"], input[name="site_url"]')],
    ['title-ids', (c) => /^Title IDs/i.test(title(c))],
    ['github', (c) => /^GitHub/i.test(title(c))],
    ['blocked', (c) => /^Blocked sources/i.test(title(c))],
    ['password', (c) => /password/i.test(title(c))],
  ];
  cards.forEach((c) => {
    if (c.id) return;
    for (const [id, test] of guess) if (!document.getElementById(id) && test(c)) { c.id = id; return; }
  });
  const GROUPS = [
    ['Security', [['twostep', 'Two-step login'], ['password', 'Admin password']]],
    ['Signer', [['signer', 'Signer']]],
    ['Store', [['general', 'Release and links'], ['tabs', 'Store tabs'], ['smp', 'ShadowMountPlus'], ['title-ids', 'Title IDs taken'], ['blocked', 'Blocked sources']]],
    ['Discover', [['whats-new', 'What\'s new'], ['discover.php', 'Discover page ↗']]],
    ['Betas', [['testers', 'Testers'], ['r2', 'Cloudflare R2']]],
    ['Integrations', [['github', 'GitHub'], ['discord', 'Discord posts']]],
    ['Website', [['site-v2', 'Recomp Store 2.0 on the site']]],
  ];
  const known = new Set();
  GROUPS.forEach(([, items]) => items.forEach(([id]) => known.add(id)));
  const other = cards.filter((c) => c.id && !known.has(c.id) || !c.id).map((c, i) => {
    if (!c.id) c.id = 'card-' + (i + 1);
    return [c.id, title(c).replace(/\s+(On|Off)$/, '') || 'Untitled'];
  });
  if (other.length) GROUPS.push(['Other', other]);

  const css = document.createElement('style');
  css.textContent = `
.set-layout{display:grid;grid-template-columns:200px minmax(0,1fr);gap:0 28px;align-items:start}
.set-nav{position:sticky;top:96px;margin:0 0 20px;max-height:calc(100vh - 120px);overflow:auto;padding:4px 2px 4px 0}
.set-nav summary{display:none}
.set-nav .groups{display:grid;gap:12px}
.set-nav h3{font:700 11.5px Figtree,sans-serif;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin:0 0 4px}
.set-nav a{display:block;padding:5px 10px;border-radius:10px;color:var(--frost);text-decoration:none;font-size:14.5px;opacity:.85}
.set-nav a:hover{background:rgba(244,248,252,.07);opacity:1}
.set-nav a[aria-current]{background:rgba(53,214,244,.14);color:var(--cyan);opacity:1;font-weight:600}
.set-cards{min-width:0}
.set-cards > section.card{scroll-margin-top:var(--set-top,96px)}
@media (max-width:999px){
  .set-layout{grid-template-columns:minmax(0,1fr)}
  .set-nav{position:static;max-height:none;overflow:visible;border:1px solid var(--line);border-radius:16px;padding:0 14px;background:var(--panel)}
  .set-nav summary{display:block;margin:0;padding:12px 0;font-weight:600;color:var(--frost)}
  .set-nav[open]{padding-bottom:12px}
  .set-nav .groups{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
}`;
  document.head.appendChild(css);

  const layout = document.createElement('div');
  layout.className = 'set-layout';
  const nav = document.createElement('details');
  nav.className = 'set-nav';
  nav.setAttribute('aria-label', 'Settings');
  const sum = document.createElement('summary');
  sum.textContent = 'Jump to';
  nav.appendChild(sum);
  const groups = document.createElement('div');
  groups.className = 'groups';
  nav.appendChild(groups);
  const links = [];
  GROUPS.forEach(([name, items]) => {
    const here = items.filter(([id]) => id.endsWith('.php') || document.getElementById(id));
    if (!here.length || here.every(([id]) => id.endsWith('.php'))) return;
    const box = document.createElement('div');
    const h = document.createElement('h3');
    h.textContent = name;
    box.appendChild(h);
    here.forEach(([id, label]) => {
      const a = document.createElement('a');
      a.href = id.endsWith('.php') ? id : '#' + id;
      a.textContent = label;
      if (!id.endsWith('.php')) { a.dataset.card = id; links.push(a); }
      box.appendChild(a);
    });
    groups.appendChild(box);
  });
  const right = document.createElement('div');
  right.className = 'set-cards';
  // Everything after the title moves into the right column, in order (scripts stay where they are).
  let n = head.nextElementSibling;
  const move = [];
  while (n) { if (n.tagName !== 'SCRIPT') move.push(n); n = n.nextElementSibling; }
  head.after(layout);
  layout.appendChild(nav);
  layout.appendChild(right);
  move.forEach((m) => right.appendChild(m));

  const wide = window.matchMedia('(min-width: 1000px)');
  // A jump lands just under the page's header (taller on a phone, where its links wrap).
  const bar = document.querySelector('header.bar');
  const fit = () => {
    nav.open = wide.matches;
    const top = (bar && getComputedStyle(bar).position === 'sticky' ? bar.offsetHeight : 0) + 16;
    document.documentElement.style.setProperty('--set-top', top + 'px');
  };
  fit();
  wide.addEventListener('change', fit);
  nav.addEventListener('click', (e) => { if (e.target.closest('a[data-card]') && !wide.matches) nav.open = false; });

  // The card on screen is the one marked.
  const mark = (id) => links.forEach((a) => (a.dataset.card === id ? a.setAttribute('aria-current', 'true') : a.removeAttribute('aria-current')));
  if ('IntersectionObserver' in window) {
    const seen = new Map();
    const io = new IntersectionObserver((es) => {
      es.forEach((e) => seen.set(e.target.id, e.isIntersecting ? e.boundingClientRect.top : null));
      const vis = [...seen].filter(([, t]) => t !== null).sort((a, b) => a[1] - b[1]);
      if (vis.length) mark(vis[0][0]);
    }, { rootMargin: '-90px 0px -55% 0px' });
    links.forEach((a) => { const c = document.getElementById(a.dataset.card); if (c) io.observe(c); });
  }
  // A link to a card (settings.php#discord) lands on it in its new place.
  if (location.hash) {
    const c = document.getElementById(location.hash.slice(1));
    if (c) { mark(c.id); c.scrollIntoView(); }
  }
})();
