/** A message a customer can send to a family member from the checkout ("help me choose / pay for this"): the items, with links, and the total. */
export interface ShareLine { name: string; variantName?: string | null; qty: number; slug?: string | null }

export interface ShareStrings {
  /** The opening sentence, already in the customer's language. */
  intro: string
  /** "Total: QAR 8,200.00" */
  total: string
}

/** `origin` is the store's address (https://albahar.qa), so each piece links to its page. */
export function basketShareText(lines: ShareLine[], strings: ShareStrings, origin: string): string {
  const rows = lines.slice(0, 15).map((l) => {
    const what = `• ${l.name}${l.variantName ? ` (${l.variantName})` : ''} × ${l.qty}`;
    return l.slug ? `${what}\n  ${origin}/products/${l.slug}` : what;
  });
  const more = lines.length > 15 ? [`… +${lines.length - 15}`] : [];
  return [strings.intro, '', ...rows, ...more, '', strings.total].join('\n');
}

/** The WhatsApp link that opens a chat picker with the text filled in (no phone number: the customer picks who). */
export const whatsappShareHref = (text: string) => `https://wa.me/?text=${encodeURIComponent(text)}`;

/** The plain link to a product page: the store's address and the product's own address, nothing else (no query, no tracking, nothing about the person sharing). */
export const productShareUrl = (origin: string, slug: string): string => `${origin.replace(/\/+$/, '')}/products/${encodeURIComponent(slug)}`;

export interface ShareProduct { name: string; variantName?: string | null; /** The price as shown, already formatted ("QAR 1,250.00"); left out when empty. */ price?: string | null }

/**
 * A short, friendly message to send one product to someone on WhatsApp: the opening line, the name (with its option), the price and the link.
 * The link comes last on its own line, so WhatsApp shows the product card (picture, title) from the page's preview tags.
 */
export function productShareText(p: ShareProduct, strings: { intro: string; price: string | null }, url: string): string {
  const name = p.variantName ? `${p.name} (${p.variantName})` : p.name;
  return [strings.intro, '', `*${name}*`, ...(p.price && strings.price ? [strings.price] : []), '', url].join('\n');
}
