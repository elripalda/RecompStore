// RecompLauncher (3.1g): someone's beta invite link (/get?beta=<token>) turns the Get page's
// "Join the beta" box into their download. The link is remembered in this browser, so coming back
// to /get later still shows it. Everyone else sees the page as it always is.
(() => {
  const KEY = 'rl-beta-pass';
  let tok = '';
  try {
    const q = new URLSearchParams(location.search).get('beta') || '';
    if (/^[a-f0-9]{32}$/i.test(q)) { tok = q.toLowerCase(); localStorage.setItem(KEY, tok); }
    else tok = localStorage.getItem(KEY) || '';
  } catch (e) { /* no storage: the link still works this once */ }
  if (!/^[a-f0-9]{32}$/.test(tok)) return;
  // Clean address bar: the link stays in this browser, not in the URL.
  try { const u = new URL(location.href); if (u.searchParams.has('beta')) { u.searchParams.delete('beta'); history.replaceState(history.state, '', u.pathname + u.search + u.hash); } } catch (e) {}

  let pass = null;
  const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const css = `.beta-box.is-pass{border-color:rgba(52,242,232,.45);box-shadow:0 0 0 1px rgba(52,242,232,.18),0 18px 50px rgba(52,242,232,.10)}
.beta-box.is-pass .beta-pass-row{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin-top:12px}
.beta-box.is-pass .beta-pass-row .btn{min-width:0}
.beta-box.is-pass .beta-pass-note{margin:10px 0 0;font-size:13px;color:var(--muted,#a7b3c4)}
.beta-box.is-pass .beta-pass-sha{display:flex;align-items:flex-start;gap:8px;margin:10px 0 0;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:var(--muted,#a7b3c4);min-width:0}
.beta-box.is-pass .beta-pass-sha code{flex:0 1 auto;min-width:0;overflow-wrap:anywhere;word-break:break-all;font:inherit;color:inherit;background:none;padding:0}
.beta-box.is-pass .beta-pass-copy{flex:0 0 auto;font:600 11px/1 Figtree,system-ui,sans-serif;padding:5px 9px;border-radius:999px;border:1px solid var(--line,rgba(244,248,252,.16));background:rgba(244,248,252,.06);color:var(--frost,#F4F8FC);cursor:pointer}
.beta-box.is-pass .beta-pass-copy:hover{border-color:rgba(52,242,232,.5)}
.beta-box.is-pass .beta-pass-kicker{display:block;flex-basis:100%;font-size:12px;font-weight:700;letter-spacing:.18em;color:var(--cyan,#34F2E8);text-transform:uppercase}`;

  const apply = () => {
    if (!pass || !/^\/(pc\/launcher|get)\/?$/.test(location.pathname)) return;
    if (!document.getElementById('beta-pass-css')) { const s = document.createElement('style'); s.id = 'beta-pass-css'; s.textContent = css; document.head.appendChild(s); }
    // Checked by what's inside, not a mark: the site can redraw the box's contents after us.
    document.querySelectorAll('.beta-box').forEach((box) => {
      if (box.querySelector('.beta-pass-kicker')) return;
      box.classList.add('is-pass');
      const dl = '/api/beta-access.php?t=' + tok + '&dl=1';
      // The launcher's GitHub, where its releases live.
      const gh = `<a class="btn" href="${esc(pass.github || 'https://github.com/elripalda/RecompLauncher')}" target="_blank" rel="noopener">GitHub</a>`;
      box.innerHTML = `<div class="beta-box-head"><span class="beta-pass-kicker">Beta pass · ${esc(pass.name)}</span><b>You're in.</b><span>Your early access to RecompLauncher for Windows.</span></div>`
        + (pass.ready
          ? `<div class="beta-pass-row"><a class="btn primary" href="${dl}">Download for Windows${pass.version ? ' · ' + esc(pass.version) : ''}</a>${gh}</div>${pass.sha256 ? `<p class="beta-pass-sha"><code>SHA-256: ${esc(pass.sha256)}</code><button type="button" class="beta-pass-copy" data-sha="${esc(pass.sha256)}">Copy</button></p>` : ''}<p class="beta-pass-note">Windows may warn you because it’s a new app. Click More info, then Run anyway.</p><p class="beta-pass-note">${pass.size ? esc(pass.size) + ' · ' : ''}This page is just for you. Please don't share your link.</p>`
          : `<p class="beta-pass-note">Your download unlocks right here very soon. Keep your email: the same link will work.</p><div class="beta-pass-row">${gh}</div>`);
    });
    document.querySelectorAll('.beta-pass-copy:not([data-on])').forEach((b) => {
      b.dataset.on = '1';
      b.addEventListener('click', async () => {
        const v = b.dataset.sha || '';
        try { await navigator.clipboard.writeText(v); }
        catch (e) { const t = document.createElement('textarea'); t.value = v; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (x) {} t.remove(); }
        b.textContent = 'Copied'; setTimeout(() => { b.textContent = 'Copy'; }, 1600);
      });
    });
    document.querySelectorAll('.soon-badge').forEach((b) => { if (b.dataset.pass === pass.name && b.textContent.includes('Beta pass')) return; b.dataset.pass = pass.name; b.classList.add('is-pass'); b.innerHTML = '<i aria-hidden="true"></i>Beta pass · ' + esc(pass.name); });
  };

  fetch('/api/beta-access.php?t=' + tok, { cache: 'no-store' }).then((r) => r.json()).then((j) => {
    if (!j || !j.ok) { try { localStorage.removeItem(KEY); } catch (e) {} return; }
    pass = j;
    apply();
    // The site redraws pages as you move around: put it back each time the Get page comes up.
    new MutationObserver(apply).observe(document.body, { childList: true, subtree: true });
  }).catch(() => {});
})();
