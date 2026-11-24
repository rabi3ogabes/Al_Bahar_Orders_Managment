import { useEffect, useState } from 'react';
import { useI18n } from './i18n';
import { pageTitle } from './seoCore';

export function useDebounce<T>(value: T, ms = 300): T {
  const [v, setV] = useState(value);
  useEffect(() => { const t = setTimeout(() => setV(value), ms); return () => clearTimeout(t); }, [value, ms]);
  return v;
}

export function useDocTitle(title?: string | null) {
  const { locale } = useI18n();
  useEffect(() => { document.title = pageTitle(title, locale); }, [title, locale]);
}

export function useScrollTop(dep?: unknown) {
  useEffect(() => { window.scrollTo({ top: 0, behavior: 'instant' as ScrollBehavior }); }, [dep]);
}

export function useMedia(query: string): boolean {
  const [m, setM] = useState(() => window.matchMedia(query).matches);
  useEffect(() => {
    const mq = window.matchMedia(query);
    const fn = () => setM(mq.matches);
    mq.addEventListener('change', fn);
    return () => mq.removeEventListener('change', fn);
  }, [query]);
  return m;
}

/** Re-render every `ms` (auction countdowns). */
export function useTick(ms = 1000) {
  const [, set] = useState(0);
  useEffect(() => { const t = setInterval(() => set((x) => x + 1), ms); return () => clearInterval(t); }, [ms]);
}
