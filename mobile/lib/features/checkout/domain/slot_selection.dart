import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:flutter/foundation.dart';

/// How many days ahead (today included) the checkout looks for the earliest bookable time. One request covers all of them.
const int kSlotLookAheadDays = 14;

/// The dates of a span request and the slots found in them: what the checkout needs to choose and to check a time.
@immutable
class SlotWindow {
  const SlotWindow({required this.from, required this.to, required this.days});

  /// First and last `YYYY-MM-DD` (Qatar) the request covered.
  final String from;
  final String to;
  final List<SlotDay> days;

  /// Dates that still have a free place, in order.
  List<String> get openDates => [
    for (final d in days)
      if (d.hasSelectable) d.date,
  ]..sort();
}

/// A bookable (date, slot) pair.
@immutable
class SlotChoice {
  const SlotChoice(this.date, this.slot);
  final String date;
  final BranchSlot slot;

  @override
  bool operator ==(Object other) => other is SlotChoice && other.date == date && other.slot.id == slot.id;

  @override
  int get hashCode => Object.hash(date, slot.id);
}

/// The dates to ask the server for: today (Qatar) through [days] - 1 days later. The business day, not the phone's.
({String from, String to}) slotWindowDates(DateTime now, {int days = kSlotLookAheadDays}) {
  final today = QatarTime.today(now);
  return (from: QatarTime.isoDate(today), to: QatarTime.isoDate(today.add(Duration(days: days - 1))));
}

/// The moment a slot starts: [date] is a Qatar calendar day and [hhmm] a Qatar wall-clock time ("16:00"). Qatar is UTC+3 all year.
DateTime slotStartInstant(String date, String hhmm) {
  final day = QatarTime.parseDay(date);
  final parts = hhmm.split(':');
  final h = int.tryParse(parts.first) ?? 0;
  final m = parts.length > 1 ? int.tryParse(parts[1]) ?? 0 : 0;
  if (day == null) return DateTime.fromMillisecondsSinceEpoch(0, isUtc: true);
  return DateTime.utc(day.year, day.month, day.day, h, m).subtract(QatarTime.offset);
}

/// Whether [slot] on [date] can still be booked at [now]: the server called it free AND its cutoff has not passed since.
/// The server counts a slot as gone once now is later than its start minus the cutoff, so exactly at the cutoff it is still open.
bool isSlotBookable(BranchSlot slot, String date, DateTime now) {
  if (!slot.selectable) return false;
  final until = slot.bookableUntil;
  if (until != null) return !now.isAfter(until);
  // An older server does not say when a slot closes: at least never offer one that has already started.
  return now.isBefore(slotStartInstant(date, slot.startsAt));
}

/// The soonest time that can still be booked: the earliest day first, the earliest start on it. Null when nothing is open.
/// Days before today in Qatar are ignored, so a list fetched before midnight never offers yesterday.
SlotChoice? earliestBookableSlot(Iterable<SlotDay> days, {required DateTime now}) {
  final today = QatarTime.isoDate(QatarTime.today(now));
  final ordered = days.where((d) => d.date.compareTo(today) >= 0).toList()..sort((a, b) => a.date.compareTo(b.date));
  for (final day in ordered) {
    final slots = [...day.slots]..sort((a, b) => a.startsAt.compareTo(b.startsAt));
    for (final slot in slots) {
      if (isSlotBookable(slot, day.date, now)) return SlotChoice(day.date, slot);
    }
  }
  return null;
}

enum SlotPlan {
  /// The current selection is fine (or cannot be judged from this window): leave it alone.
  keep,

  /// Select [SlotDecision.choice].
  choose,

  /// Nothing can be booked: there must be no selection.
  none,
}

@immutable
class SlotDecision {
  const SlotDecision(this.plan, {this.choice, this.replacedStale = false});
  final SlotPlan plan;
  final SlotChoice? choice;

  /// A slot WAS selected but can no longer be booked (full, or its cutoff passed): the customer should be told it was replaced.
  final bool replacedStale;
}

/// What the checkout should do with the selection once it knows the free times in [window] at [now]:
///  - a selection that can still be booked is kept, whoever made it (a customer's own pick is never replaced by an earlier time that opens up);
///  - a date outside the window cannot be judged from it (the customer may have browsed further ahead): kept, the server decides;
///  - no selection, or one that is stale: the earliest bookable time is chosen, or none when nothing is open.
SlotDecision decideSlot({
  required SlotWindow window,
  required String? selectedSlotId,
  required String? selectedDate,
  required DateTime now,
}) {
  final today = QatarTime.isoDate(QatarTime.today(now));
  final hasSelection = selectedSlotId != null && selectedDate != null;
  if (hasSelection) {
    final inWindow = selectedDate.compareTo(window.from) >= 0 && selectedDate.compareTo(window.to) <= 0;
    if (!inWindow && selectedDate.compareTo(today) >= 0) return const SlotDecision(SlotPlan.keep);
    final day = window.days.where((d) => d.date == selectedDate).firstOrNull;
    final slot = day?.slots.where((s) => s.id == selectedSlotId).firstOrNull;
    if (slot != null && isSlotBookable(slot, selectedDate, now)) return const SlotDecision(SlotPlan.keep);
  }
  final next = earliestBookableSlot(window.days, now: now);
  if (next == null) return SlotDecision(SlotPlan.none, replacedStale: hasSelection);
  return SlotDecision(SlotPlan.choose, choice: next, replacedStale: hasSelection);
}
