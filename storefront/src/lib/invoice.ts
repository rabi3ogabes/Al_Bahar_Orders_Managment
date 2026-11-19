/** Why the invoice did not come, in words the person can act on. `e` is what the API layer threw (an ApiError: `status`, `code`, `message`). */
export function invoiceFailure(e: unknown, t: (key: string) => string): string {
  const x = e as { status?: unknown; code?: unknown; message?: unknown } | null;
  if (!x || typeof x.status !== 'number') return t('order.invoice_unavailable');
  const message = typeof x.message === 'string' ? x.message : '';
  if (x.status === 401) return t('order.invoice_signin');
  if (x.status === 404) return t('order.invoice_missing');
  if (x.status === 409) return message || t('order.invoice_unavailable'); // the server says why (for example payment is not done yet), in the page's language
  if ((x.status === 0 || x.code === 'session_refresh_unavailable') && message) return message; // no connection / a busy moment: already a plain sentence
  if (x.status === 429 || x.status >= 500) return t('order.invoice_busy');
  return t('order.invoice_unavailable');
}
