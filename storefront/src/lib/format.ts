import type { Money } from './types';

export const toNum = (m?: Money | string | null) => (m ? parseFloat(typeof m === 'string' ? m : m.amount) : 0);
export function money(amount: number, currency = 'QAR', locale = 'en'): string {
  const n = new Intl.NumberFormat(locale === 'ar' ? 'ar-QA-u-nu-latn' : 'en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount);
  return locale === 'ar' ? `${n} ${currency === 'QAR' ? 'ر.ق' : currency}` : `${currency} ${n}`;
}
export const fmtMoney = (m: Money | null | undefined, locale = 'en') => (m ? money(parseFloat(m.amount), m.currency, locale) : '—');

/** Whole-number money for large prices on cards ("QAR 3,990"). */
export function moneyShort(m: Money, locale = 'en'): string {
  const v = parseFloat(m.amount);
  const n = new Intl.NumberFormat(locale === 'ar' ? 'ar-QA-u-nu-latn' : 'en-GB', { minimumFractionDigits: v % 1 ? 2 : 0, maximumFractionDigits: 2 }).format(v);
  return locale === 'ar' ? `${n} ${m.currency === 'QAR' ? 'ر.ق' : m.currency}` : `${m.currency} ${n}`;
}

export const cx = (...c: (string | false | null | undefined)[]) => c.filter(Boolean).join(' ');


/** A tel: link for Qatar numbers. */
export const telHref = (p?: string | null) => (p ? `tel:${p.replace(/[^+\d]/g, '')}` : undefined);

export const mapsHref = (lat?: number | null, lng?: number | null, label?: string) =>
  lat && lng ? `https://www.google.com/maps/search/?api=1&query=${lat},${lng}${label ? `(${encodeURIComponent(label)})` : ''}` : undefined;

export function isoDate(d: Date): string {
  const y = d.getFullYear(); const m = String(d.getMonth() + 1).padStart(2, '0'); const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${dd}`;
}
export const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };

/** Arabic-Indic and Persian digits typed on an Arabic keyboard become 0-9, so numbers and codes validate on the server. */
export const latinDigits = (v: string) => v.replace(/[\u0660-\u0669]/g, (c) => String(c.charCodeAt(0) - 0x660)).replace(/[\u06f0-\u06f9]/g, (c) => String(c.charCodeAt(0) - 0x6f0));
