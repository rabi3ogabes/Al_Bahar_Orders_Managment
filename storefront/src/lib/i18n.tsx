import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { setApiLocale } from './api';
import { en } from '../locales/en';
import { ar } from '../locales/ar';

export type Locale = 'en' | 'ar';
const dicts: Record<Locale, Record<string, string>> = { en, ar };
const KEY = 'albahar.locale';

function initial(): Locale {
  try {
    const s = localStorage.getItem(KEY);
    if (s === 'ar' || s === 'en') return s;
  } catch { /* ignore */ }
  return navigator.language?.toLowerCase().startsWith('ar') ? 'ar' : 'en';
}

interface I18n {
  locale: Locale; dir: 'ltr' | 'rtl'; setLocale: (l: Locale) => void
  t: (key: string, vars?: Record<string, string | number>) => string
  date: (iso: string | Date, opts?: Intl.DateTimeFormatOptions) => string
  num: (n: number) => string
}
const Ctx = createContext<I18n>(null as unknown as I18n);
export const useI18n = () => useContext(Ctx);

export function I18nProvider({ children }: { children: ReactNode }) {
  const [locale, setLoc] = useState<Locale>(initial);
  const dir = locale === 'ar' ? 'rtl' : 'ltr';

  useEffect(() => {
    document.documentElement.lang = locale;
    document.documentElement.dir = dir;
    setApiLocale(locale);
    try { localStorage.setItem(KEY, locale); } catch { /* ignore */ }
  }, [locale, dir]);
  // The API locale must be right before the first request fires.
  setApiLocale(locale);

  const t = useCallback((key: string, vars?: Record<string, string | number>) => {
    let s = dicts[locale][key] ?? dicts.en[key] ?? key;
    if (vars) for (const [k, v] of Object.entries(vars)) s = s.replaceAll(`{${k}}`, String(v));
    return s;
  }, [locale]);

  const value = useMemo<I18n>(() => ({
    locale, dir, t, setLocale: setLoc,
    date: (iso, opts) => new Intl.DateTimeFormat(locale === 'ar' ? 'ar-QA-u-nu-latn' : 'en-GB', opts ?? { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Qatar' }).format(typeof iso === 'string' ? new Date(iso) : iso),
    num: (n) => new Intl.NumberFormat(locale === 'ar' ? 'ar-QA-u-nu-latn' : 'en-GB').format(n),
  }), [locale, dir, t]);

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}
