import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/domain/guest_cart.dart';
import 'package:flutter/foundation.dart';

/// One thing to do to the account's cart to fold a guest's cart into it.
@immutable
class MergeStep {
  const MergeStep({
    required this.variantId,
    required this.name,
    this.addQuantity = 0,
    this.assembly = false,
    this.assemblyItemId,
  });

  final String variantId;
  final String name;

  /// How many more units to add (0: the account already has at least as many).
  final int addQuantity;

  /// Tick assembly on this line.
  final bool assembly;

  /// The account's line to tick assembly on when nothing needs adding (null: the add carries the tick).
  final String? assemblyItemId;
}

/// Folding a signed-out customer's cart into the account cart right after they sign in (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md).
/// Pure, so the rules are tested alone (`test/features/cart/guest_cart_test.dart`):
///  - the same variant never becomes a second line: the line ends with the larger of the two quantities (what the guest had in the cart and what
///    the account already held), the same rule as the server's own `POST /cart/merge`, so signing in twice never doubles anything;
///  - assembly stays ticked if either side ticked it;
///  - the server applies its own limits when it adds (stock caps, deal limits per customer); [report] compares what came back with what the guest
///    wanted, so the customer is told plainly about everything that is not as they left it.
abstract final class GuestMerge {
  static List<MergeStep> plan(Cart account, List<GuestCartRow> rows) {
    final steps = <MergeStep>[];
    for (final r in rows) {
      final line = account.items.where((i) => i.variantId == r.variantId).firstOrNull;
      final add = line == null ? r.quantity : (r.quantity > line.quantity ? r.quantity - line.quantity : 0);
      final tick = r.assembly && r.assemblyFee != null && !(line?.assembly?.selected ?? false);
      final needsPatch = add == 0 && tick && line != null && line.assembly != null;
      if (add == 0 && !needsPatch) continue;
      steps.add(
        MergeStep(
          variantId: r.variantId,
          name: r.name,
          addQuantity: add,
          assembly: tick,
          assemblyItemId: needsPatch ? line.id : null,
        ),
      );
    }
    return steps;
  }

  /// What is not as the guest left it, from the account cart after the merge. [refused] are the variants the server would not take at all.
  static List<GuestCartChange> report(Cart merged, List<GuestCartRow> rows, {Set<String> refused = const {}}) {
    final changes = <GuestCartChange>[];
    for (final r in rows) {
      final line = merged.items.where((i) => i.variantId == r.variantId).firstOrNull;
      if (line == null || refused.contains(r.variantId)) {
        changes.add(GuestCartChange(GuestChangeKind.unavailable, r.name));
        continue;
      }
      if (line.hasIssue) {
        final left = line.availableQuantity;
        changes.add(
          left != null && left > 0
              ? GuestCartChange(GuestChangeKind.reduced, r.name, available: left)
              : GuestCartChange(GuestChangeKind.unavailable, r.name),
        );
        continue;
      }
      if (line.quantity < r.quantity) {
        changes.add(GuestCartChange(GuestChangeKind.reduced, r.name, available: line.quantity));
      }
      final now = line.unitPrice?.finalPrice;
      if (now != null && now.amount != r.unit.amount) {
        changes.add(GuestCartChange(GuestChangeKind.priceChanged, r.name, from: r.unit, to: now));
      }
      if (r.assembly && line.assembly == null) changes.add(GuestCartChange(GuestChangeKind.assemblyRemoved, r.name));
    }
    return changes;
  }
}
