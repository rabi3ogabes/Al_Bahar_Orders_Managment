/** Admin-authored product/CMS HTML: keep formatting, drop anything executable. */
const ALLOWED = new Set(['P', 'BR', 'UL', 'OL', 'LI', 'STRONG', 'B', 'EM', 'I', 'U', 'H2', 'H3', 'H4', 'A', 'SPAN', 'DIV', 'BLOCKQUOTE', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'IMG', 'HR', 'FIGURE', 'FIGCAPTION']);
/** Elements whose text content is code or markup, never prose: dropped together with their content. */
const DROP = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'TEMPLATE', 'NOSCRIPT', 'SVG', 'MATH', 'FORM', 'TEXTAREA', 'SELECT', 'TITLE', 'NOEMBED', 'NOFRAMES']);
/** Browsers ignore tabs, newlines and other control characters inside a URL scheme ("java\tscript:"), so strip them before checking. */
const SAFE_URL = /^(?:https?:|mailto:|tel:|\/(?!\/)|#|[^:/?#]*(?:[/?#]|$))/i;
const cleanUrl = (u: string) => u.replace(/[^\x21-\x7e]/g, '');

export function sanitizeHtml(html: string): string {
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  const walk = (node: Element) => {
    for (const child of Array.from(node.children)) {
      if (DROP.has(child.tagName.toUpperCase())) { child.remove(); continue; }
      if (!ALLOWED.has(child.tagName)) {
        // Unknown wrapper: keep its (sanitised) content in its place.
        walk(child);
        child.replaceWith(...Array.from(child.childNodes));
        continue;
      }
      for (const attr of Array.from(child.attributes)) {
        const n = attr.name.toLowerCase();
        const keep = (child.tagName === 'A' && (n === 'href' || n === 'title')) || (child.tagName === 'IMG' && (n === 'src' || n === 'alt')) || n === 'colspan' || n === 'rowspan';
        if (!keep) child.removeAttribute(attr.name);
      }
      const attrName = child.hasAttribute('href') ? 'href' : child.hasAttribute('src') ? 'src' : null;
      if (attrName && !SAFE_URL.test(cleanUrl(child.getAttribute(attrName) ?? ''))) child.removeAttribute(attrName);
      if (child.tagName === 'A') { child.setAttribute('rel', 'noopener noreferrer'); if (/^https?:/i.test(cleanUrl(child.getAttribute('href') ?? ''))) child.setAttribute('target', '_blank'); }
      walk(child);
    }
  };
  walk(doc.body);
  return doc.body.innerHTML;
}
