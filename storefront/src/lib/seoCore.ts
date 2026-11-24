/**
 * Page titles and the language a web address asks for. The server writes the first title and the hreflang links (App\Support\StorefrontSeo);
 * this keeps the browser tab title right while the shopper moves around the single-page app, in the shopper's language.
 */
export type Lang = 'en' | 'ar';

export const SITE_NAME: Record<Lang, string> = { en: 'Al Bahar Home & Living', ar: 'البحر للمنزل والمعيشة' };

/** "Sofas · Al Bahar Home & Living", or just the site name when a page has no title of its own. */
export function pageTitle(title: string | null | undefined, lang: Lang): string {
  return title ? `${title} · ${SITE_NAME[lang]}` : SITE_NAME[lang];
}

/** `?lang=ar` (the Arabic address of a page, as listed in the sitemap and the hreflang links); null when the address does not ask for a language. */
export function langFromSearch(search: string): Lang | null {
  const v = new URLSearchParams(search).get('lang');
  return v === 'ar' || v === 'en' ? v : null;
}

/**
 * Heading levels of admin-written text placed under a page's own heading: the first is at most `first` and none jumps more than one level deeper
 * than the one before it, so a page whose body starts with an h3 still reads h1, h2, h3 for screen readers and search engines.
 */
export function fixHeadingLevels(levels: number[], first = 2): number[] {
  const open: { given: number; level: number }[] = [];
  return levels.map((n) => {
    while (open.length && open[open.length - 1].given >= n) open.pop();
    const level = open.length ? open[open.length - 1].level + 1 : first;
    open.push({ given: n, level });
    return level;
  });
}
