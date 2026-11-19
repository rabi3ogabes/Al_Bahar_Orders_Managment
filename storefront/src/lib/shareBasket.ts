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
