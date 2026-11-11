/** Admin-authored product/CMS HTML: keep formatting, drop anything executable. */
const ALLOWED = new Set(['P', 'BR', 'UL', 'OL', 'LI', 'STRONG', 'B', 'EM', 'I', 'U', 'H2', 'H3', 'H4', 'A', 'SPAN', 'DIV', 'BLOCKQUOTE', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'IMG', 'HR', 'FIGURE', 'FIGCAPTION']);
export function sanitizeHtml(html: string): string {
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  const walk = (node: Element) => {
    for (const child of Array.from(node.children)) {
      if (!ALLOWED.has(child.tagName)) { child.replaceWith(...Array.from(child.childNodes)); continue; }
      for (const attr of Array.from(child.attributes)) {
        const n = attr.name.toLowerCase();
        const keep = (child.tagName === 'A' && (n === 'href' || n === 'title')) || (child.tagName === 'IMG' && (n === 'src' || n === 'alt')) || n === 'colspan' || n === 'rowspan';
        if (!keep) child.removeAttribute(attr.name);
      }
      const url = child.getAttribute('href') ?? child.getAttribute('src');
      if (url && /^\s*(javascript|data|vbscript):/i.test(url)) child.removeAttribute(child.hasAttribute('href') ? 'href' : 'src');
      if (child.tagName === 'A') { child.setAttribute('rel', 'noopener noreferrer'); if (/^https?:/i.test(child.getAttribute('href') ?? '')) child.setAttribute('target', '_blank'); }
      walk(child);
    }
  };
  walk(doc.body);
  return doc.body.innerHTML;
}
