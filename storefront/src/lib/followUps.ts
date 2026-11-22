/** Delivery follow-ups on the order (docs/guides/DELIVERY_FOLLOW_UPS.md): new times after a failed delivery, the proof of delivery and the assembly answer. The server decides everything that matters. */
export interface OfferedTime { slot_id: string; date: string; starts_at: string; ends_at: string; available: boolean; label: string }

export interface SlotOffer {
  id: string
  /** `open` (the customer can choose), `expired` (the old "we will call you" applies) or `chosen` (the new time is booked). */
  status: 'open' | 'expired' | 'chosen' | string
  source: string
  expires_at: string
  can_choose: boolean
  options: OfferedTime[]
  chosen: (OfferedTime & { at: string | null }) | null
}

/** When it was delivered, who received it and a short-lived private link to the photo. Never a position, the driver's name or phone. */
export interface DeliveryProof { delivered_at: string | null; received_by: string | null; photo: { url: string; expires_at: string } | null }

/** The driver's answer for paid assembly: `pending`, `done` or `not_done` (with the reason in plain words). */
export interface AssemblyAnswer { status: 'pending' | 'done' | 'not_done' | string; done_at: string | null; reason_label: string | null }

export type OfferView = 'choose' | 'all_taken' | 'expired' | 'chosen'

/** What the offer card draws, or null for nothing (no offer, or a status a newer server invented). */
export function offerView(o: SlotOffer | null | undefined): OfferView | null {
  if (!o) return null;
  if (o.status === 'chosen' && o.chosen) return 'chosen';
  if (o.status === 'expired') return 'expired';
  if (o.status !== 'open') return null;
  return o.can_choose && o.options.some((t) => t.available) ? 'choose' : 'all_taken';
}

/** The key of one time (the same slot on the same day): what the radio group remembers. */
export const timeKey = (t: Pick<OfferedTime, 'slot_id' | 'date'>) => `${t.slot_id}|${t.date}`;

/** The time to send for the chosen key, only while it is still free. */
export function pickedTime(o: SlotOffer, key: string | null): OfferedTime | null {
  return key ? o.options.find((t) => timeKey(t) === key && t.available) ?? null : null;
}

/** What to say when choosing failed (the server's own words are for the log; the customer reads these). */
export function pickFailureKey(status: number, code: string): string {
  if (status === 401) return 'offer.err_signin';
  if (status === 404) return 'offer.err_gone';
  if (code === 'offer_expired') return 'offer.expired';
  if (code === 'offer_already_chosen') return 'offer.err_chosen';
  if (code === 'offer_closed') return 'offer.err_closed';
  if (code === 'slot_full' || code === 'slot_unavailable') return 'offer.err_taken';
  if (status === 429) return 'offer.err_busy';
  return 'error.generic';
}

/** "Delivered on {when}" with or without "received by {name}". */
export const proofKey = (p: DeliveryProof) => (p.received_by ? 'proof.delivered_by' : 'proof.delivered');

/** Does the assembly line say anything? Not while the driver has not answered. */
export const assemblyShown = (a: AssemblyAnswer | null | undefined) => !!a && (a.status === 'done' || a.status === 'not_done');
