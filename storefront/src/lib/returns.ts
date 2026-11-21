import type { Money } from './types';

/** Returns and damage claims: where a claim stands, what to show on its timeline, how many of a line can still be reported, and what to tell the customer when the server says no. */
export interface ReturnRequestInfo {
  id: string; number: string
  /** requested | approved | rejected | received | refunded | closed (another word from a newer server is read as "other"). */
  status: string; status_label?: string
  requested_at: string; decided_at?: string | null
  /** What the team wrote when it decided (shown only when there is one). */
  decision_note?: string | null
}

/** One step of a claim's timeline, as the server sends it (the words are this app's own, see `claimStepKey`). */
export interface ClaimStep { key: string; state: 'done' | 'current' | 'upcoming' | 'stopped'; at: string | null; detail: string | null }
export interface ClaimPhoto { id: string; /** Relative to /api/v1: the file is private, so it is fetched with the customer's sign-in, never linked. */ path: string; at: string | null }
export interface ReturnClaim extends ReturnRequestInfo {
  order_id: string; kind: string; reason_code: string; reason_label: string; evidence_required: boolean; note: string | null
  items: { order_item_id: string; quantity: number; name: string; variant_name: string | null; image_url: string | null }[]
  photos: ClaimPhoto[]; max_photos: number; messages: { text: string; at: string }[]
  can_add_evidence: boolean; can_cancel: boolean
  method: 'pickup' | 'branch' | 'none' | null; method_note: string | null
  branch: { id: string; name: string; phone: string | null } | null
  refund: { status: string; amount: Money; method: string; processed_at: string | null } | null
  timeline: ClaimStep[]
}

export const RETURN_STATUSES = ['requested', 'under_review', 'approved', 'rejected', 'received', 'refunded', 'closed', 'cancelled'] as const;

/** What the customer can report, in the order the form shows them. A damaged or wrong item cannot be judged without a picture. */
export const CLAIM_TYPES = [
  { code: 'damaged', photo: true }, { code: 'wrong_item', photo: true }, { code: 'missing_item', photo: false }, { code: 'quality', photo: false }, { code: 'changed_mind', photo: false },
] as const;
export type ClaimType = (typeof CLAIM_TYPES)[number]['code'];
export const claimNeedsPhoto = (code: string) => CLAIM_TYPES.some((c) => c.code === code && c.photo);

export const MAX_CLAIM_PHOTOS = 5;
export const MAX_CLAIM_NOTE = 500;
export const MAX_PHOTO_BYTES = 8 * 1024 * 1024;
export const PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'] as const;

/** Why this set of pictures cannot be sent (the key of the sentence), or null when they can. `already` = pictures the claim has. */
export function photoProblem(files: { type: string; size: number }[], already = 0): string | null {
  if (files.some((f) => !(PHOTO_MIMES as readonly string[]).includes(f.type))) return 'return.err_photo_type';
  if (files.some((f) => f.size > MAX_PHOTO_BYTES)) return 'return.err_photo_size';
  if (already + files.length > MAX_CLAIM_PHOTOS) return 'return.err_photo_count';
  return null;
}

const STEP_KEYS = ['received', 'under_review', 'decision', 'approved', 'rejected', 'handover', 'pickup', 'branch', 'item_received', 'refunded', 'cancelled'];
/** The key of the label of a timeline step (a step a newer server adds reads as a plain "update"). */
export const claimStepKey = (key: string) => `return.step.${STEP_KEYS.includes(key) ? key : 'other'}`;

/** The key of "how the refund goes back" (`original` = the way they paid). */
export const refundHowKey = (method: string) => `return.refund.${['original', 'manual_cash', 'manual_bank_transfer'].includes(method) ? method : 'other'}`;

/** A claim the team has not decided: the customer may still add photos or a note, and cancel it. */
export const claimIsOpen = (c: { status: string }) => c.status === 'requested' || c.status === 'under_review';

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
  if (status === 409 && code === 'claim_not_editable') return 'return.err_locked';
  if (status === 422 && errors && Object.keys(errors).length) {
    if (errors.photos || Object.keys(errors).some((k) => k.startsWith('photos.'))) return 'return.err_photos';
    if (errors.reason) return 'return.err_note';
    if (errors.items || Object.keys(errors).some((k) => k.startsWith('items.'))) return 'return.err_items';
    if (errors.reason_code) return 'return.err_reason';
    return 'error.generic';
  }
  if (status === 422 || code === 'http_error') return 'return.err_not_allowed';
  if (status === 429) return 'return.err_busy';
  return 'error.generic';
}
