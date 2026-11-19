/**
 * Looking a sentence up: the page's language, then English, and never the raw key. A key nobody wrote (a reason code a newer server started to send,
 * a typo) must not reach a customer as `pay.reason.something`: it becomes the nearest plain sentence, and the miss is reported to whoever is developing.
 */
export type Dict = Record<string, string>;

/** A friendlier sentence for a family of keys, tried before the general one. */
const FAMILY_FALLBACK: [prefix: string, key: string][] = [
  ['pay.reason.', 'pay.reason.unavailable'],
];
const GENERIC = 'error.generic';

export function translate(dicts: Record<'en' | 'ar', Dict>, locale: 'en' | 'ar', key: string, vars?: Record<string, string | number>, onMissing?: (key: string) => void): string {
  let s = dicts[locale][key] ?? dicts.en[key];
  if (s === undefined) {
    onMissing?.(key);
    const family = FAMILY_FALLBACK.find(([p]) => key.startsWith(p))?.[1];
    s = (family && (dicts[locale][family] ?? dicts.en[family])) || dicts[locale][GENERIC] || dicts.en[GENERIC] || '';
  }
  if (vars) for (const [k, v] of Object.entries(vars)) s = s.replaceAll(`{${k}}`, String(v));
  return s;
}
