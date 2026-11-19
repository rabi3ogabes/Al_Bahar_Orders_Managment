/** Splits a sentence with `{name}` slots into its text and slot pieces, so a slot can hold a link or a bold word in any word order (Arabic puts them elsewhere). */
export type TemplatePart = string | { slot: string };

export function splitTemplate(template: string): TemplatePart[] {
  const parts: TemplatePart[] = [];
  let rest = template;
  for (;;) {
    const m = /\{([a-zA-Z_]+)\}/.exec(rest);
    if (!m) { if (rest) parts.push(rest); return parts; }
    if (m.index > 0) parts.push(rest.slice(0, m.index));
    parts.push({ slot: m[1] });
    rest = rest.slice(m.index + m[0].length);
  }
}
