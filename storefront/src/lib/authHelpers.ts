import { latinDigits } from './format';

/** Qatar numbers: accept "55220011", "+974 5522 0011" or "97455220011". */
export function normalizePhone(raw: string): string {
  const plus = raw.trim().startsWith('+');
  const d = latinDigits(raw).replace(/\D/g, '');
  if (plus) return `+${d}`;
  if (d.startsWith('974') && d.length > 8) return `+${d}`;
  if (d.startsWith('00')) return `+${d.slice(2)}`;
  return `+974${d}`;
}

/** Shows a stored number without the +974 that the field already prints. */
export const localPhone = (p: string) => latinDigits(p).replace(/\D/g, '').replace(/^(\+|00)?974(?=\d{8}$)/, '');

const REF_KEY = 'albahar.ref';
/** A friend's invite link (?ref=CODE) is remembered for this tab until sign-up. */
export function captureReferral() {
  try { const c = new URLSearchParams(window.location.search).get('ref'); if (c && /^[A-Za-z0-9-]{3,16}$/.test(c)) sessionStorage.setItem(REF_KEY, c.toUpperCase()); } catch { /* private mode */ }
}
export const storedReferral = () => { try { return sessionStorage.getItem(REF_KEY) ?? ''; } catch { return ''; } };

