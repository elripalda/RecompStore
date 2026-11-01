// RecompStore / RecompLauncher (3.1): Markdown for the developer docs, as safe HTML. Shared by the
// hub (hub-ext.js) and the admin's Docs page. Everything is escaped first; only the Markdown below
// turns into tags: headings, paragraphs, lists (nested), tables, code blocks, quotes, rules, and
// inline code, bold, italics and links.
(() => {
  'use strict';
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const slug = (s) => String(s).toLowerCase().replace(/<[^>]+>/g, '').replace(/&[a-z#0-9]+;/g, '').replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '') || 'section';
  // Links on the site (one slash) or off it (https), anchors and email; nothing else.
  const safeUrl = (u) => /^(https?:\/\/|\/(?![\/\\])|#|mailto:)/i.test(u) ? u : '#';

  // Inline Markdown. Code, links and bare addresses are set aside first (as numbered holes no input
  // can contain), so nothing after them can reach inside.
  const inline = (raw, keep) => {
    const own = !keep;
    keep = keep || [];
    const hold = (html) => `\u0000${keep.push(html) - 1}\u0000`;
    let s = own ? String(raw).replace(/\u0000/g, '') : String(raw);
    s = s.replace(/`([^`]+)`/g, (m, c) => hold(`<code>${esc(c)}</code>`));
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, t, u) => hold(`<a href="${esc(safeUrl(u))}"${/^https?:/i.test(u) ? ' target="_blank" rel="noopener noreferrer"' : ''}>${inline(t, keep)}</a>`));
    s = esc(s);
    s = s.replace(/(^|[\s(])(https?:\/\/[^\s<\u0000]+[^\s<\u0000.,;:!?)\]'"])/g, (m, pre, u) => pre + hold(`<a href="${u}" target="_blank" rel="noopener noreferrer">${u}</a>`));
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[^*\w])\*([^*\s][^*]*?)\*(?!\w)/g, '$1<em>$2</em>');
    s = s.replace(/\\([\\`*_{}[\]()#+\-.!~|])/g, '$1');
    if (!own) return s;
    for (let n = 0; n < 8 && s.includes('\u0000'); n++) s = s.replace(/\u0000(\d+)\u0000/g, (m, i) => keep[Number(i)] || '');
    return s.replace(/\u0000/g, '');
  };

  // A table row's cells; a | inside `code` stays in its cell.
  const cells = (line) => {
    const t = line.trim().replace(/^\|/, '').replace(/\|$/, '');
    const out = [];
    let cur = '', code = false;
    for (let i = 0; i < t.length; i++) {
      const c = t[i];
      if (c === '\\' && t[i + 1] === '|') { cur += '|'; i++; continue; }
      if (c === '`') code = !code;
      if (c === '|' && !code) { out.push(cur.trim()); cur = ''; continue; }
      cur += c;
    }
    out.push(cur.trim());
    return out;
  };

  // A list from its lines: items by indent, deeper lines become the item's own list.
  const list = (lines) => {
    const first = lines[0].match(/^(\s*)([-*+]|\d+[.)])\s/);
    const base = first[1].length, ordered = /\d/.test(first[2]);
    const items = [];
    for (const l of lines) {
      const m = l.match(/^(\s*)([-*+]|\d+[.)])\s+(.*)$/);
      if (m && m[1].length <= base) items.push({ text: m[3], sub: [] });
      else if (items.length) {
        if (m || items[items.length - 1].sub.length) items[items.length - 1].sub.push(l);
        else items[items.length - 1].text += ' ' + l.trim();
      }
    }
    const tag = ordered ? 'ol' : 'ul';
    return `<${tag}>${items.map((it) => `<li>${inline(it.text)}${it.sub.length ? list(it.sub) : ''}</li>`).join('')}</${tag}>`;
  };

  const render = (md, opts) => {
    const toc = [];
    const used = {};
    const L = String(md || '').replace(/\u0000/g, '').replace(/\r\n?/g, '\n').split('\n');
    const out = [];
    let i = 0;
    const isList = (l) => /^\s*([-*+]|\d+[.)])\s+/.test(l);
    while (i < L.length) {
      const l = L[i];
      if (!l.trim()) { i++; continue; }
      const fence = l.match(/^\s*```\s*([\w+-]*)\s*$/);
      if (fence) {
        const code = [];
        i++;
        while (i < L.length && !/^\s*```\s*$/.test(L[i])) code.push(L[i++]);
        i++;
        out.push(`<pre class="md-code"${fence[1] ? ` data-lang="${esc(fence[1])}"` : ''}><code>${esc(code.join('\n'))}</code></pre>`);
        continue;
      }
      const h = l.match(/^(#{1,4})\s+(.*?)\s*#*\s*$/);
      if (h) {
        const level = Math.min(4, Math.max(2, h[1].length));
        const html = inline(h[2]);
        let id = slug(html);
        used[id] = (used[id] || 0) + 1;
        if (used[id] > 1) id += '-' + used[id];
        if (level <= 3) toc.push({ id, level, text: html.replace(/<[^>]+>/g, '').replace(/&(amp|lt|gt|quot|#39);/g, (m, e) => ({ amp: '&', lt: '<', gt: '>', quot: '"', '#39': "'" }[e])) });
        out.push(`<h${level} id="${(opts && opts.idPrefix) || ''}${id}">${html}</h${level}>`);
        i++;
        continue;
      }
      if (/^\s*([-*_])(\s*\1){2,}\s*$/.test(l)) { out.push('<hr>'); i++; continue; }
      if (/^\s*\|.*\|\s*$/.test(l) && i + 1 < L.length && /^\s*\|?\s*:?-{2,}/.test(L[i + 1])) {
        const head = cells(l);
        i += 2;
        const rows = [];
        while (i < L.length && /^\s*\|.*\|\s*$/.test(L[i])) rows.push(cells(L[i++]));
        out.push(`<div class="md-table"><table><thead><tr>${head.map((c) => `<th>${inline(c)}</th>`).join('')}</tr></thead><tbody>${rows.map((r) => `<tr>${r.map((c) => `<td>${inline(c)}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
        continue;
      }
      if (/^\s*>/.test(l)) {
        const q = [];
        while (i < L.length && /^\s*>/.test(L[i])) q.push(L[i++].replace(/^\s*>\s?/, ''));
        out.push(`<blockquote>${render(q.join('\n'), opts).html}</blockquote>`);
        continue;
      }
      if (isList(l)) {
        const ls = [];
        const kind = (x) => { const m = x.match(/^(\s*)([-*+]|\d+[.)])\s/); return m ? [m[1].length, /\d/.test(m[2])] : null; };
        const [base0, ord0] = kind(l);
        while (i < L.length && (ls.length === 0 || !(kind(L[i]) && kind(L[i])[0] <= base0 && kind(L[i])[1] !== ord0)) && (isList(L[i]) || (L[i].trim() && /^\s+/.test(L[i])) || (!L[i].trim() && i + 1 < L.length && (isList(L[i + 1]) || /^\s+\S/.test(L[i + 1]))))) {
          if (L[i].trim()) ls.push(L[i]);
          i++;
        }
        out.push(list(ls));
        continue;
      }
      const p = [];
      while (i < L.length && L[i].trim() && !/^\s*(```|#{1,4}\s|>|\|)/.test(L[i]) && !isList(L[i])) p.push(L[i++].trim());
      if (!p.length) { out.push(`<p>${inline(L[i++])}</p>`); continue; }
      out.push(`<p>${inline(p.join(' '))}</p>`);
    }
    return { html: out.join('\n'), toc };
  };

  window.RSmd = render;
})();
