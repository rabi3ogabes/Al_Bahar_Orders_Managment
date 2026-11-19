import type { Money } from './types';

/**
 * Why a payment method is not offered, as a kind sentence. The server names the reason (`payment_methods[].reason`); this turns it into
 * the key of a sentence and the numbers that go in it. Every reason the quote can send is here (see QuoteService::paymentMethods), plus the
 * older `exceeds_cod_limit`; a reason from a newer server falls back to "Not available for this order." and never shows its code.
 */
export interface PayOption {
  code: 'sadad' | 'cod' | 'pay_at_pickup'
  available: boolean
  reason: string | null
  /** Cash on delivery only, when the reason is a limit: the strictest lowest / highest basket total the showroom(s) accept. */
  limits?: { min?: Money | null; max?: Money | null } | null
}

export const PAY_REASONS = ['temporarily_unavailable', 'delivery_only', 'pickup_only', 'not_offered_in_area', 'not_offered_at_branch', 'outside_cod_limits', 'exceeds_cod_limit'] as const;

export interface PayReasonText { key: string; vars: Record<string, string> }

/**
 * The sentence for an unavailable option. `total` is the basket total the limits were checked against (the grand total of the quote).
 * `fmt` formats a Money in the page's language.
 */
export function payReason(opt: Pick<PayOption, 'reason' | 'limits'>, total: Money | null | undefined, fmt: (m: Money) => string): PayReasonText {
  const reason = opt.reason ?? 'unavailable';
  if (reason === 'outside_cod_limits' || reason === 'exceeds_cod_limit') {
    const amount = total ? parseFloat(total.amount) : NaN;
    const max = opt.limits?.max ?? null;
    const min = opt.limits?.min ?? null;
    if (total && max && amount > parseFloat(max.amount)) return { key: 'pay.reason.cod_max', vars: { max: fmt(max), total: fmt(total) } };
    if (total && min && amount < parseFloat(min.amount)) return { key: 'pay.reason.cod_min', vars: { min: fmt(min), total: fmt(total) } };
    return { key: 'pay.reason.outside_cod_limits', vars: {} };
  }
  return { key: `pay.reason.${reason}`, vars: {} };
}

/** True when the customer has nothing to pay with: the quote is there and every method is switched off. */
export const nothingToPayWith = (methods: Pick<PayOption, 'available'>[] | undefined): boolean => !!methods && methods.length > 0 && methods.every((m) => !m.available);
