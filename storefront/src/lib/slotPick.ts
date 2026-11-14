import type { Slot, SlotDay } from './types';

/**
 * Which delivery / pickup time the checkout should have selected. The same rule as the customer app (mobile/lib/features/checkout/domain/slot_selection.dart):
 * the earliest time that can still be booked is chosen for the customer, a still-bookable choice is never replaced, a stale one is.
 * Pure, so it is tested alone (tests/slot-pick.test.mjs). Qatar is UTC+3 all year and the business day is Qatar's, not the browser's.
 */

/** How many days ahead (today included) the checkout looks for the earliest bookable time. */
export const LOOK_AHEAD_DAYS = 14;
const QATAR_OFFSET_MS = 3 * 3_600_000;
const DAY_MS = 86_400_000;

const pad = (n: number) => String(n).padStart(2, '0');
const isoOf = (d: Date) => `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())}`;

/** Today in Qatar as YYYY-MM-DD at the instant `nowMs` (just after midnight there it is already the new day, whatever the browser says). */
export function qatarToday(nowMs: number): string {
  return isoOf(new Date(nowMs + QATAR_OFFSET_MS));
}

/** `iso` plus `n` calendar days. */
export function addIsoDays(iso: string, n: number): string {
  const [y, m, d] = iso.split('-').map(Number);
  return isoOf(new Date(Date.UTC(y, m - 1, d) + n * DAY_MS));
}

/** The dates to ask the server for: today (Qatar) through `days - 1` days later. */
export function slotWindowDates(nowMs: number, days = LOOK_AHEAD_DAYS): { from: string; to: string } {
  const from = qatarToday(nowMs);
  return { from, to: addIsoDays(from, days - 1) };
}

/** The moment a slot starts: `date` is a Qatar calendar day and `hhmm` a Qatar wall-clock time ("16:00"). */
export function slotStartMs(date: string, hhmm: string): number {
  const [y, m, d] = date.split('-').map(Number);
  const [h, min] = hhmm.split(':').map(Number);
  if (![y, m, d, h].every(Number.isFinite)) return 0;
  return Date.UTC(y, m - 1, d, h, Number.isFinite(min) ? min : 0) - QATAR_OFFSET_MS;
}

/** A free place on the server's list. */
export const hasFreePlace = (s: Slot) => !s.is_full && s.available > 0;

/**
 * Whether `slot` on `date` can still be booked at `nowMs`: the server called it free AND its cutoff has not passed since.
 * The server counts a slot as gone once now is later than its start minus the cutoff, so exactly at the cutoff it is still open.
 */
export function isSlotBookable(slot: Slot, date: string, nowMs: number): boolean {
  if (!hasFreePlace(slot)) return false;
  if (slot.bookable_until) {
    const until = Date.parse(slot.bookable_until);
    if (Number.isFinite(until)) return nowMs <= until;
  }
  // An older server does not say when a slot closes: at least never offer one that has already started.
  return nowMs < slotStartMs(date, slot.starts_at);
}

export interface SlotChoice { date: string; slot: Slot }

/** Days that still have a free place, in order. */
export const openDates = (days: SlotDay[]): string[] => days.filter((d) => d.slots.some(hasFreePlace)).map((d) => d.date).sort();

/** The soonest time that can still be booked: the earliest day first, the earliest start on it. Null when nothing is open. Days before today in Qatar are ignored. */
export function earliestBookableSlot(days: SlotDay[], nowMs: number): SlotChoice | null {
  const today = qatarToday(nowMs);
  const ordered = days.filter((d) => d.date >= today).sort((a, b) => a.date.localeCompare(b.date));
  for (const day of ordered) {
    const slots = [...day.slots].sort((a, b) => a.starts_at.localeCompare(b.starts_at));
    const slot = slots.find((s) => isSlotBookable(s, day.date, nowMs));
    if (slot) return { date: day.date, slot };
  }
  return null;
}

export type SlotPlan = 'keep' | 'choose' | 'none';
export interface SlotDecision { plan: SlotPlan; choice?: SlotChoice; /** A time WAS chosen but can no longer be booked: tell the customer it was replaced. */ replacedStale: boolean }

/**
 * What to do with the selection once the free times of `days` (covering `from`…`to`) are known at `nowMs`:
 *  - a selection that can still be booked is kept, whoever made it (a customer's own pick is never replaced by an earlier time that opens up);
 *  - a date outside the window cannot be judged from it: kept, the server decides;
 *  - none, or a stale one: the earliest bookable time is chosen, or `none` when nothing is open.
 */
export function decideSlot(o: { days: SlotDay[]; from: string; to: string; selectedId: string | null; selectedDate: string | null; nowMs: number }): SlotDecision {
  const today = qatarToday(o.nowMs);
  const hasSelection = !!o.selectedId && !!o.selectedDate;
  if (hasSelection) {
    const date = o.selectedDate!;
    const inWindow = date >= o.from && date <= o.to;
    if (!inWindow && date >= today) return { plan: 'keep', replacedStale: false };
    const slot = o.days.find((d) => d.date === date)?.slots.find((s) => s.id === o.selectedId);
    if (slot && isSlotBookable(slot, date, o.nowMs)) return { plan: 'keep', replacedStale: false };
  }
  const next = earliestBookableSlot(o.days, o.nowMs);
  return next ? { plan: 'choose', choice: next, replacedStale: hasSelection } : { plan: 'none', replacedStale: hasSelection };
}
