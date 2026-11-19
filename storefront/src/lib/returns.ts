/** Returns: where a request stands, how many of a line can still be sent back, and what to tell the customer when the server says no. */
export interface ReturnRequestInfo {
  id: string; number: string
  /** requested | approved | rejected | received | refunded | closed (another word from a newer server is read as "other"). */
  status: string; status_label?: string
  requested_at: string; decided_at?: string | null
  /** What the team wrote when it decided (shown only when there is one). */
  decision_note?: string | null
}

export const RETURN_STATUSES = ['requested', 'approved', 'rejected', 'received', 'refunded', 'closed'] as const;

/** The key of the sentence that tells the customer where their request stands. */
export const returnStatusKey = (status: string) => `return.status.${(RETURN_STATUSES as readonly string[]).includes(status) ? status : 'other'}`;

/** 'info' while it waits on us, 'warning' when it was refused, 'success' once it has moved on. */
export const returnTone = (status: string): 'info' | 'warning' | 'success' => (status === 'rejected' ? 'warning' : ['received', 'refunded', 'closed'].includes(status) ? 'success' : 'info');

/** How many pieces of a line can still be asked back. The server says (it knows what was handed over and what is already in a request); an older server leaves it out. */
export const returnableOf = (i: { quantity: number; quantity_cancelled?: number; quantity_returned?: number; returnable_quantity?: number }) =>
  typeof i.returnable_quantity === 'number' ? Math.max(0, i.returnable_quantity) : Math.max(0, i.quantity - (i.quantity_cancelled ?? 0) - (i.quantity_returned ?? 0));

/**
 * What to say when sending a return request failed. The server's own wording for these is short ("These items can't be returned."), so the page
 * explains the likely reasons and the way out in its own plain sentences.
 */
export function returnFailureKey(status: number, code: string, errors: Record<string, string[]>): string {
  if (status === 403) return 'return.err_closed';
  if (status === 401) return 'return.err_signin';
  if (status === 422 && errors && Object.keys(errors).length) {
    if (errors.items || Object.keys(errors).some((k) => k.startsWith('items.'))) return 'return.err_items';
    if (errors.reason_code) return 'return.err_reason';
    return 'error.generic';
  }
  if (status === 422 || code === 'http_error') return 'return.err_not_allowed';
  if (status === 429) return 'return.err_busy';
  return 'error.generic';
}
