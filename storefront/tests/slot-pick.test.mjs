import test from 'node:test';
import assert from 'node:assert/strict';
import { addIsoDays, decideSlot, earliestBookableSlot, hasFreePlace, isSlotBookable, openDates, qatarToday, slotStartMs, slotWindowDates } from '../src/lib/slotPick.ts';

// The rule that chooses the earliest bookable delivery / pickup time (the same as the app's slot_selection.dart).
// Qatar is UTC+3 all year: "00:10 in Doha" is "21:10 UTC the day before", so the cases around midnight prove the business day is Qatar's, not the browser's.

const slot = (id, from, o = {}) => ({ id, date: '', starts_at: from, ends_at: `${String(Number(from.slice(0, 2)) + 2).padStart(2, '0')}:00`, capacity: 8, available: o.available ?? 4, is_full: (o.available ?? 4) === 0, bookable_until: o.until ?? null });
const day = (date, slots) => ({ date, available: slots.reduce((a, s) => a + s.available, 0), slots });
const at = (iso) => Date.parse(iso);
const morning = at('2026-11-15T07:00:00Z'); // 10:00 in Doha on 15 Nov
const win = { from: '2026-11-15', to: '2026-11-28' };

test('today and the window follow Qatar, not the browser', () => {
  assert.equal(qatarToday(at('2026-11-14T21:10:00Z')), '2026-11-15');
  assert.equal(qatarToday(at('2026-11-14T20:59:59Z')), '2026-11-14');
  assert.deepEqual(slotWindowDates(at('2026-11-14T21:10:00Z')), { from: '2026-11-15', to: '2026-11-28' });
  assert.equal(addIsoDays('2026-11-30', 1), '2026-12-01');
  assert.equal(addIsoDays('2026-12-31', 1), '2027-01-01');
});

test('a slot start is a Doha wall-clock time', () => {
  assert.equal(slotStartMs('2026-11-15', '16:00'), at('2026-11-15T13:00:00Z'));
  assert.equal(slotStartMs('2026-11-15', '00:30'), at('2026-11-14T21:30:00Z'));
  assert.equal(slotStartMs('nonsense', '16:00'), 0);
});

test('isSlotBookable: a free place, and the cutoff is the last moment', () => {
  assert.equal(isSlotBookable(slot('a', '16:00', { available: 0 }), '2026-11-16', morning), false);
  assert.equal(isSlotBookable(slot('a', '16:00'), '2026-11-16', morning), true);
  const s = slot('a', '16:00', { until: '2026-11-15T15:00:00+03:00' });
  assert.equal(isSlotBookable(s, '2026-11-15', at('2026-11-15T11:59:59Z')), true);
  assert.equal(isSlotBookable(s, '2026-11-15', at('2026-11-15T12:00:00Z')), true, '15:00:00 Doha is still open');
  assert.equal(isSlotBookable(s, '2026-11-15', at('2026-11-15T12:00:01Z')), false);
});

test('isSlotBookable: an older server with no cutoff only ever offers times that have not started', () => {
  const s = slot('a', '16:00');
  assert.equal(isSlotBookable(s, '2026-11-15', at('2026-11-15T12:59:00Z')), true);
  assert.equal(isSlotBookable(s, '2026-11-15', at('2026-11-15T13:00:00Z')), false);
  assert.equal(isSlotBookable(s, '2026-11-14', morning), false);
});

test('earliest: the soonest day, then the earliest start, in whatever order the server listed them', () => {
  const days = [day('2026-11-17', [slot('c', '10:00')]), day('2026-11-16', [slot('b2', '18:00'), slot('b1', '16:00')])];
  const pick = earliestBookableSlot(days, morning);
  assert.deepEqual([pick.date, pick.slot.id], ['2026-11-16', 'b1']);
});

test('earliest: skips full times and times past their cutoff; moves to the next day', () => {
  const today = day('2026-11-15', [slot('gone', '11:00', { until: '2026-11-15T09:30:00+03:00' }), slot('full', '12:00', { available: 0 }), slot('ok', '16:00', { until: '2026-11-15T15:00:00+03:00' })]);
  assert.equal(earliestBookableSlot([today, day('2026-11-16', [slot('t', '09:00')])], morning).slot.id, 'ok');
  const none = day('2026-11-15', [slot('gone', '11:00', { until: '2026-11-15T09:30:00+03:00' })]);
  const pick = earliestBookableSlot([none, day('2026-11-16', [slot('t', '09:00')])], morning);
  assert.deepEqual([pick.date, pick.slot.id], ['2026-11-16', 't']);
  assert.equal(earliestBookableSlot([], morning), null);
  assert.equal(earliestBookableSlot([day('2026-11-16', [slot('f', '10:00', { available: 0 })])], morning), null);
});

test('just after midnight in Doha yesterday is never offered, although the browser may still call it today', () => {
  const lateUtc = at('2026-11-14T21:10:00Z'); // 00:10 on the 15th in Doha
  const days = [day('2026-11-14', [slot('yesterday', '23:00', { until: '2026-11-14T22:00:00+03:00' })]), day('2026-11-15', [slot('today', '08:00', { until: '2026-11-15T07:00:00+03:00' })])];
  assert.equal(earliestBookableSlot(days, lateUtc).slot.id, 'today');
  assert.equal(earliestBookableSlot([day('2026-11-14', [slot('yesterday', '23:00')])], lateUtc), null, 'a stale list is dropped by the date alone');
});

test('just before midnight in Doha today still counts; half a minute after it tomorrow is the first day', () => {
  const days = [day('2026-11-15', [slot('late', '23:30', { until: '2026-11-15T23:59:59+03:00' })]), day('2026-11-16', [slot('early', '08:00')])];
  assert.equal(earliestBookableSlot(days, at('2026-11-15T20:59:30Z')).slot.id, 'late');
  assert.equal(earliestBookableSlot(days, at('2026-11-15T21:00:30Z')).slot.id, 'early');
});

const days = [day('2026-11-16', [slot('first', '09:00'), slot('second', '16:00')]), day('2026-11-17', [slot('third', '09:00')])];
const decide = (o) => decideSlot({ days, ...win, selectedId: null, selectedDate: null, nowMs: morning, ...o });

test('decide: nothing chosen yet → the earliest', () => {
  const d = decide({});
  assert.deepEqual([d.plan, d.choice.slot.id, d.choice.date, d.replacedStale], ['choose', 'first', '2026-11-16', false]);
});

test('decide: a choice that can still be booked is kept, even when an earlier time exists', () => {
  assert.equal(decide({ selectedId: 'second', selectedDate: '2026-11-16' }).plan, 'keep');
  assert.equal(decide({ selectedId: 'third', selectedDate: '2026-11-17' }).plan, 'keep');
});

test('decide: a choice that filled up, passed its cutoff, or vanished is replaced and flagged', () => {
  const full = [day('2026-11-16', [slot('first', '09:00', { available: 0 }), slot('second', '16:00')])];
  let d = decideSlot({ days: full, ...win, selectedId: 'first', selectedDate: '2026-11-16', nowMs: morning });
  assert.deepEqual([d.plan, d.choice.slot.id, d.replacedStale], ['choose', 'second', true]);

  const soon = [day('2026-11-15', [slot('soon', '12:00', { until: '2026-11-15T11:00:00+03:00' }), slot('later', '18:00')])];
  assert.equal(decideSlot({ days: soon, ...win, selectedId: 'soon', selectedDate: '2026-11-15', nowMs: at('2026-11-15T07:59:00Z') }).plan, 'keep');
  d = decideSlot({ days: soon, ...win, selectedId: 'soon', selectedDate: '2026-11-15', nowMs: at('2026-11-15T08:01:00Z') });
  assert.deepEqual([d.plan, d.choice.slot.id, d.replacedStale], ['choose', 'later', true]);

  d = decide({ selectedId: 'ghost', selectedDate: '2026-11-16', days: [day('2026-11-17', [slot('third', '09:00')])] });
  assert.deepEqual([d.plan, d.choice.slot.id, d.replacedStale], ['choose', 'third', true]);
});

test('decide: a date beyond the window cannot be judged from it (kept); a past date never is', () => {
  assert.equal(decide({ selectedId: 'far', selectedDate: '2026-12-05' }).plan, 'keep');
  const d = decide({ selectedId: 'old', selectedDate: '2026-11-10' });
  assert.deepEqual([d.plan, d.replacedStale], ['choose', true]);
});

test('decide: nothing open → none, "replaced" only if something had been chosen', () => {
  assert.deepEqual([decide({ days: [] }).plan, decide({ days: [] }).replacedStale], ['none', false]);
  const gone = decide({ days: [], selectedId: 'first', selectedDate: '2026-11-16' });
  assert.deepEqual([gone.plan, gone.replacedStale], ['none', true]);
});

test('openDates lists only days with a free place, in order; hasFreePlace needs both flags', () => {
  const list = [day('2026-11-18', [slot('a', '09:00')]), day('2026-11-16', [slot('b', '09:00', { available: 0 })]), day('2026-11-17', [slot('c', '09:00')])];
  assert.deepEqual(openDates(list), ['2026-11-17', '2026-11-18']);
  assert.equal(hasFreePlace({ ...slot('x', '09:00'), is_full: true }), false);
  assert.equal(hasFreePlace(slot('x', '09:00')), true);
});
