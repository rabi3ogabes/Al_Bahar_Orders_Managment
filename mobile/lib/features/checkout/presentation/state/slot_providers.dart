import 'package:albahar_customer/features/branches/data/branch_repository.dart';
import 'package:albahar_customer/features/checkout/domain/slot_selection.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "Now" for the time-slot rules (cutoffs, today in Qatar). Replaced in tests.
final slotClockProvider = Provider<DateTime Function()>((_) => DateTime.now);

/// The first day the items of this checkout can be scheduled on (their lead time, from the quote's `earliest_date`); null: today. The time lists
/// start there, so the customer is never offered a day the shop cannot keep.
class SlotEarliestDate extends Notifier<String?> {
  @override
  String? build() => null;

  void set(String? date) {
    if (date != state) state = date;
  }
}

final slotEarliestDateProvider = NotifierProvider<SlotEarliestDate, String?>(SlotEarliestDate.new);

/// The free delivery times of the next [kSlotLookAheadDays] days (Qatar), in ONE request: what the checkout picks the earliest from.
/// A failure is rethrown so the UI can offer a retry.
final deliverySlotWindowProvider = FutureProvider.autoDispose.family<SlotWindow, String>((ref, branchId) async {
  final (:from, :to) = slotWindowDatesFrom(ref.read(slotClockProvider)(), ref.watch(slotEarliestDateProvider));
  if (from.compareTo(to) > 0) return SlotWindow(from: from, to: to, days: const []);
  final days = await ref
      .watch(branchRepositoryProvider)
      .deliverySlotDays(branchId, from: from, to: to, onlyAvailable: true);
  return SlotWindow(from: from, to: to, days: days);
});

/// Same for store pickup.
final pickupSlotWindowProvider = FutureProvider.autoDispose.family<SlotWindow, String>((ref, branchId) async {
  final (:from, :to) = slotWindowDatesFrom(ref.read(slotClockProvider)(), ref.watch(slotEarliestDateProvider));
  if (from.compareTo(to) > 0) return SlotWindow(from: from, to: to, days: const []);
  final days = await ref
      .watch(branchRepositoryProvider)
      .pickupSlotDays(branchId, from: from, to: to, onlyAvailable: true);
  return SlotWindow(from: from, to: to, days: days);
});
