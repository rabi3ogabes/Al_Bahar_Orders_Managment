// Where a home slide leads. Pure (no imports) so it runs in plain node tests (tests/banner-link.test.mjs).
// The server already refuses everything that is not safe, but the store checks again: a link is only ever followed when it is one of the known kinds
// with a usable value, and a web address only when it is a complete https:// one (never javascript:, http:, data: or a relative path).

export interface BannerLink { type: string; value: string }

export type BannerTarget = { kind: 'internal'; to: string } | { kind: 'external'; href: string };

/** True for a complete, public https address without user-info or control characters. */
export function isHttpsUrl(value: string): boolean {
  if (!value || value.length > 1000 || /[\u0000- \u007f]/.test(value)) return false;
  try {
    const u = new URL(value);
    return u.protocol === 'https:' && !!u.hostname && !u.username && !u.password;
  } catch { return false; }
}

/** The store route (or outside address) a slide opens, or null when the slide has no link or one this store does not understand: the slide then is not clickable. */
export function bannerTarget(link: BannerLink | null | undefined): BannerTarget | null {
  const value = typeof link?.value === 'string' ? link.value.trim() : '';
  if (!link || !value) return null;
  const e = encodeURIComponent(value);
  switch (link.type) {
    case 'category': return { kind: 'internal', to: `/shop?category=${e}` };
    case 'brand': return { kind: 'internal', to: `/shop?brand=${e}` };
    case 'product': return { kind: 'internal', to: `/products/${e}` };
    case 'auction': return { kind: 'internal', to: `/auctions/${e}` };
    case 'page': return { kind: 'internal', to: `/pages/${e}` };
    case 'search': return { kind: 'internal', to: `/shop?q=${e}` };
    case 'url': return isHttpsUrl(value) ? { kind: 'external', href: value } : null;
    default: return null;
  }
}
