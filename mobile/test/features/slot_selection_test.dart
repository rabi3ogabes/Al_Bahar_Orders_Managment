// The rule that chooses the earliest bookable delivery / pickup time. Pure: no network, no widgets.
// Qatar is UTC+3 all year, so "00:10 in Doha" is "21:10 UTC the day before" — the cases around midnight prove the
// business day (not the phone's) is what counts.
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/checkout/domain/slot_selection.dart';
import 'package:flutter_test/flutter_test.dart';

BranchSlot slot(
  String id,
  String from, {
  int available = 4,
  String? until,
  String? to,
}) => BranchSlot(
  id: id,
  date: '',
  startsAt: from,
  endsAt: to ?? '${(int.parse(from.substring(0, 2)) + 2).toString().padLeft(2, '0')}:00',
  available: available,
  isFull: available == 0,
  capacity: 8,
  bookableUntil: until == null ? null : DateTime.parse(until),
);

SlotDay day(String date, List<BranchSlot> slots) =>
    SlotDay(date: date, available: slots.fold(0, (a, s) => a + s.available), slots: slots);

SlotWindow window(List<SlotDay> days, {String from = '2026-11-15', String to = '2026-11-28'}) =>
    SlotWindow(from: from, to: to, days: days);

/// 15 Nov 2026, 10:00 in Doha.
final morning = DateTime.utc(2026, 11, 15, 7, 0);

void main() {
  group('the dates asked for', () {
    test('start from today in Qatar, not on the phone', () {
      // Phone clock in UTC: still 14 Nov at 21:10, but it is 00:10 on 15 Nov in Doha.
      final w = slotWindowDates(DateTime.utc(2026, 11, 14, 21, 10));
      expect(w.from, '2026-11-15');
      expect(w.to, '2026-11-28');
      // One minute before midnight in Doha is still the 14th.
      expect(slotWindowDates(DateTime.utc(2026, 11, 14, 20, 59, 59)).from, '2026-11-14');
      expect(slotWindowDates(DateTime.utc(2026, 11, 14, 21, 0, 0)).from, '2026-11-15');
    });

    test('a slot start is a Doha wall-clock time', () {
      expect(slotStartInstant('2026-11-15', '16:00'), DateTime.utc(2026, 11, 15, 13, 0));
      expect(slotStartInstant('2026-11-15', '00:30'), DateTime.utc(2026, 11, 14, 21, 30));
      expect(slotStartInstant('nonsense', '16:00').millisecondsSinceEpoch, 0);
    });
  });

  group('isSlotBookable', () {
    test('needs a free place', () {
      expect(isSlotBookable(slot('a', '16:00', available: 0), '2026-11-16', morning), isFalse);
      expect(isSlotBookable(slot('a', '16:00'), '2026-11-16', morning), isTrue);
    });

    test('the cutoff is the last moment: exactly at it is still open, one second later is not', () {
      final s = slot('a', '16:00', until: '2026-11-15T15:00:00+03:00');
      expect(isSlotBookable(s, '2026-11-15', DateTime.utc(2026, 11, 15, 11, 59, 59)), isTrue);
      expect(isSlotBookable(s, '2026-11-15', DateTime.utc(2026, 11, 15, 12, 0, 0)), isTrue, reason: '15:00:00 Doha');
      expect(isSlotBookable(s, '2026-11-15', DateTime.utc(2026, 11, 15, 12, 0, 1)), isFalse);
    });

    test('an older server that sends no cutoff: only times that have not started', () {
      final s = slot('a', '16:00');
      expect(isSlotBookable(s, '2026-11-15', DateTime.utc(2026, 11, 15, 12, 59)), isTrue);
      expect(isSlotBookable(s, '2026-11-15', DateTime.utc(2026, 11, 15, 13, 0)), isFalse);
      expect(isSlotBookable(s, '2026-11-14', DateTime.utc(2026, 11, 15, 7, 0)), isFalse, reason: 'yesterday');
    });
  });

  group('earliestBookableSlot', () {
    test('the soonest day, then the earliest start, whatever the order the server listed them in', () {
      final days = [
        day('2026-11-17', [slot('c', '10:00')]),
        day('2026-11-16', [slot('b2', '18:00'), slot('b1', '16:00')]),
      ];
      final pick = earliestBookableSlot(days, now: morning)!;
      expect(pick.date, '2026-11-16');
      expect(pick.slot.id, 'b1');
    });

    test('skips full times and times whose cutoff has passed, taking the next one', () {
      final days = [
        day('2026-11-15', [
          slot('gone', '11:00', until: '2026-11-15T09:30:00+03:00'), // cutoff 09:30 < now 10:00
          slot('full', '12:00', available: 0),
          slot('ok', '16:00', until: '2026-11-15T15:00:00+03:00'),
        ]),
        day('2026-11-16', [slot('tomorrow', '09:00')]),
      ];
      expect(earliestBookableSlot(days, now: morning)!.slot.id, 'ok');
    });

    test('moves on to the next day when nothing is left today', () {
      final days = [
        day('2026-11-15', [slot('gone', '11:00', until: '2026-11-15T09:30:00+03:00')]),
        day('2026-11-16', [slot('tomorrow', '09:00')]),
      ];
      final pick = earliestBookableSlot(days, now: morning)!;
      expect((pick.date, pick.slot.id), ('2026-11-16', 'tomorrow'));
    });

    test('null when nothing can be booked, or there are no days at all', () {
      expect(earliestBookableSlot(const [], now: morning), isNull);
      expect(earliestBookableSlot([day('2026-11-16', [slot('f', '10:00', available: 0)])], now: morning), isNull);
    });

    test('just after midnight in Doha yesterday is never offered, although the phone still shows it as today', () {
      final lateOnThe14thUtc = DateTime.utc(2026, 11, 14, 21, 10); // 00:10 on the 15th in Doha
      final days = [
        day('2026-11-14', [slot('yesterday', '23:00', until: '2026-11-14T22:00:00+03:00')]),
        day('2026-11-15', [slot('today', '08:00', until: '2026-11-15T07:00:00+03:00')]),
      ];
      expect(earliestBookableSlot(days, now: lateOnThe14thUtc)!.slot.id, 'today');
      // Even a list that still claimed yesterday had room (an old cache) is dropped by the date alone.
      final stale = [
        day('2026-11-14', [slot('yesterday', '23:00')]),
      ];
      expect(earliestBookableSlot(stale, now: lateOnThe14thUtc), isNull);
    });

    test('just before midnight in Doha today still counts, and an hour later tomorrow is the first day', () {
      final days = [
        day('2026-11-15', [slot('late', '23:30', until: '2026-11-15T23:59:59+03:00')]),
        day('2026-11-16', [slot('early', '08:00')]),
      ];
      expect(earliestBookableSlot(days, now: DateTime.utc(2026, 11, 15, 20, 59, 30))!.slot.id, 'late'); // 23:59:30
      expect(earliestBookableSlot(days, now: DateTime.utc(2026, 11, 15, 21, 0, 30))!.slot.id, 'early'); // 00:00:30
    });
  });

  group('decideSlot', () {
    final days = [
      day('2026-11-16', [slot('first', '09:00'), slot('second', '16:00')]),
      day('2026-11-17', [slot('third', '09:00')]),
    ];

    SlotDecision decide({String? id, String? date, SlotWindow? w, DateTime? now}) =>
        decideSlot(window: w ?? window(days), selectedSlotId: id, selectedDate: date, now: now ?? morning);

    test('nothing chosen yet: choose the earliest', () {
      final d = decide();
      expect(d.plan, SlotPlan.choose);
      expect(d.choice!.slot.id, 'first');
      expect(d.choice!.date, '2026-11-16');
      expect(d.replacedStale, isFalse);
    });

    test('a choice that can still be booked is kept, even when an earlier time exists (never overridden)', () {
      final d = decide(id: 'second', date: '2026-11-16');
      expect(d.plan, SlotPlan.keep);
      expect(decide(id: 'third', date: '2026-11-17').plan, SlotPlan.keep);
    });

    test('a choice that has become full is replaced by the earliest, and the screen is told it was replaced', () {
      final full = window([
        day('2026-11-16', [slot('first', '09:00', available: 0), slot('second', '16:00')]),
      ]);
      final d = decide(id: 'first', date: '2026-11-16', w: full);
      expect(d.plan, SlotPlan.choose);
      expect(d.choice!.slot.id, 'second');
      expect(d.replacedStale, isTrue);
    });

    test('a choice whose cutoff passed while the screen was open is replaced', () {
      final w = window([
        day('2026-11-15', [slot('soon', '12:00', until: '2026-11-15T11:00:00+03:00'), slot('later', '18:00')]),
      ]);
      final before = decide(id: 'soon', date: '2026-11-15', w: w, now: DateTime.utc(2026, 11, 15, 7, 59));
      expect(before.plan, SlotPlan.keep, reason: '10:59 Doha, cutoff 11:00');
      final after = decide(id: 'soon', date: '2026-11-15', w: w, now: DateTime.utc(2026, 11, 15, 8, 1));
      expect(after.plan, SlotPlan.choose);
      expect(after.choice!.slot.id, 'later');
      expect(after.replacedStale, isTrue);
    });

    test('a choice that is no longer listed (its whole day filled up) is replaced', () {
      final d = decide(id: 'ghost', date: '2026-11-16', w: window([day('2026-11-17', [slot('third', '09:00')])]));
      expect((d.plan, d.choice!.slot.id, d.replacedStale), (SlotPlan.choose, 'third', true));
    });

    test('a date beyond the window cannot be judged from it: kept (the server has the last word)', () {
      final d = decide(id: 'far', date: '2026-12-05');
      expect(d.plan, SlotPlan.keep);
    });

    test('a past date is never kept', () {
      final d = decide(id: 'old', date: '2026-11-10');
      expect(d.plan, SlotPlan.choose);
      expect(d.replacedStale, isTrue);
    });

    test('nothing open: none (and "replaced" only if something had been chosen)', () {
      final empty = window(const []);
      expect(decide(w: empty).plan, SlotPlan.none);
      expect(decide(w: empty).replacedStale, isFalse);
      final gone = decide(id: 'first', date: '2026-11-16', w: empty);
      expect((gone.plan, gone.replacedStale), (SlotPlan.none, true));
    });

    test('just after midnight in Doha the new day is the window start and yesterday is not offered', () {
      final lateUtc = DateTime.utc(2026, 11, 14, 21, 10); // 00:10 on the 15th in Doha
      final w = SlotWindow(
        from: '2026-11-15',
        to: '2026-11-28',
        days: [
          day('2026-11-15', [slot('midnight-slot', '09:00', until: '2026-11-15T08:00:00+03:00')]),
        ],
      );
      final d = decide(w: w, now: lateUtc);
      expect((d.plan, d.choice!.date), (SlotPlan.choose, '2026-11-15'));
    });
  });

  group('SlotWindow', () {
    test('openDates lists only days with a free place, in order', () {
      final w = window([
        day('2026-11-18', [slot('a', '09:00')]),
        day('2026-11-16', [slot('b', '09:00', available: 0)]),
        day('2026-11-17', [slot('c', '09:00')]),
      ]);
      expect(w.openDates, ['2026-11-17', '2026-11-18']);
    });
  });
}
