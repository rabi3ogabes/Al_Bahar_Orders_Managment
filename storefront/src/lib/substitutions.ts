import type { Money } from './types';

/** Substitution consent: "We do not have X. Replace it with Y?" The customer accepts, or has the line removed and refunded; the server settles it by the shop's fallback when time runs out. */
export type SubstitutionStatus = 'waiting' | 'accepted' | 'declined' | 'expired_removed' | 'expired_kept' | 'cancelled';
export interface SubstitutionLine { name: string; variant_name: string | null; quantity: number; image_url: string | null; total: Money }
export interface SubstitutionRequest {
  id: string; status: SubstitutionStatus | string
  original: SubstitutionLine | null; replacement: SubstitutionLine | null
  /** What the customer pays more (a negative amount: less) than for the line they ordered. Never above zero. */
  price_difference: Money
  /** What happens when time runs out: `remove` (taken out and refunded) or `keep` (the replacement stays at no extra cost). */
  fallback: 'remove' | 'keep'
  asked_at: string; expires_at: string; answered_at: string | null; can_answer: boolean
}

export const isWaiting = (r: { status: string }) => r.status === 'waiting';

/** The questions still waiting for the customer, soonest to run out first. */
export const waitingOf = (rs: SubstitutionRequest[] | undefined): SubstitutionRequest[] =>
  (rs ?? []).filter(isWaiting).sort((a, b) => Date.parse(a.expires_at) - Date.parse(b.expires_at));

/** "same price" when it costs the same, "{amount} less" when it costs less (it can never cost more). */
export const priceNoteKey = (diff: Money): 'subst.price_same' | 'subst.price_less' => (parseFloat(diff.amount) < 0 ? 'subst.price_less' : 'subst.price_same');

/** Whole minutes left (rounded up), never below zero. The deadline counts the branch's opening hours, so this is the wall-clock time to the stored deadline. */
export function minutesLeft(expiresAt: string, now: number): number {
  const ms = Date.parse(expiresAt) - now;
  return Number.isFinite(ms) ? Math.max(0, Math.ceil(ms / 60000)) : 0;
}

/** The sentence about how a question ended (status), or null while it waits. A status a newer server adds reads as nothing. */
export function outcomeKey(status: string): string | null {
  return ({ accepted: 'subst.outcome.accepted', declined: 'subst.outcome.declined', expired_removed: 'subst.outcome.expired_removed', expired_kept: 'subst.outcome.expired_kept', cancelled: 'subst.outcome.cancelled' } as Record<string, string>)[status] ?? null;
}

/** What to say when answering failed (the server's own words are for the log; the customer reads these). */
export function answerFailureKey(status: number, code: string): string {
  if (status === 401) return 'subst.err_signin';
  if (status === 404) return 'subst.err_gone';
  if (status === 409 && code === 'substitution_already_answered') return 'subst.err_answered';
  if (status === 409 && code === 'substitution_expired') return 'subst.err_expired';
  if (status === 409) return 'subst.err_closed';
  if (status === 429) return 'subst.err_busy';
  return 'error.generic';
}
