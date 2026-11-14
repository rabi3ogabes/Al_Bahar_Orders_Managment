import 'package:albahar_customer/features/branches/data/branch_repository.dart';
import 'package:albahar_customer/features/checkout/domain/slot_selection.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// "Now" for the time-slot rules (cutoffs, today in Qatar). Replaced in tests.
final slotClockProvider = Provider<DateTime Function()>((_) => DateTime.now);

/// The free delivery times of the next [kSlotLookAheadDays] days (Qatar), in ONE request: what the checkout picks the earliest from.
/// A failure is rethrown so the UI can offer a retry.
final deliverySlotWindowProvider = FutureProvider.autoDispose.family<SlotWindow, String>((ref, branchId) async {
  final (:from, :to) = slotWindowDates(ref.read(slotClockProvider)());
  final days = await ref
      .watch(branchRepositoryProvider)
      .deliverySlotDays(branchId, from: from, to: to, onlyAvailable: true);
  return SlotWindow(from: from, to: to, days: days);
});

/// Same for store pickup.
final pickupSlotWindowProvider = FutureProvider.autoDispose.family<SlotWindow, String>((ref, branchId) async {
  final (:from, :to) = slotWindowDates(ref.read(slotClockProvider)());
  final days = await ref.watch(branchRepositoryProvider).pickupSlotDays(branchId, from: from, to: to, onlyAvailable: true);
  return SlotWindow(from: from, to: to, days: days);
});
