import 'dart:async';

import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/scan/data/scan_repository.dart';
import 'package:albahar_customer/features/scan/domain/scan_models.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Why a scan did not end in "added". Each one is shown with plain words and one clear action.
enum ScanProblemKind {
  /// The code names no product we sell. Action: search by name.
  notFound,

  /// Found, but no unit is available. Action: keep scanning.
  outOfStock,

  /// Found, but not sold at the shopper's branch. Action: keep scanning.
  unavailable,

  /// Found, and the cart already holds the most that is available. Action: keep scanning.
  limitReached,

  /// No connection; nothing was added. Action: try again.
  offline,

  /// The product comes in several options: the option window opens instead of guessing. Action: choose.
  needsChoice,

  /// A guest cannot add to a cart. Action: sign in.
  signInRequired,

  /// Anything else the server said (cart rules, rate limit...), in its own words.
  failed,
}

@immutable
class ScanProblem {
  const ScanProblem(this.kind, {this.product, this.code, this.error, this.max});

  final ScanProblemKind kind;

  /// The product, when the code was recognised.
  final ProductSummary? product;

  /// What was scanned (for "Try again").
  final String? code;

  /// The error behind [ScanProblemKind.failed] / [ScanProblemKind.offline].
  final Object? error;

  /// The most that can be in the cart ([ScanProblemKind.limitReached]).
  final int? max;
}

/// What the last scan put in the cart: shown as the result card. The live quantity is read from the cart itself.
@immutable
class ScanEntry {
  const ScanEntry({
    required this.id,
    required this.product,
    required this.variantId,
    required this.variantName,
    required this.price,
    required this.previousQuantity,
    required this.maxQuantity,
  });

  /// Grows with every scan, so the same product scanned twice shows (and announces) a fresh card.
  final int id;
  final ProductSummary product;
  final String variantId;
  final String? variantName;
  final EffectivePrice? price;

  /// The quantity in the cart before this scan: Undo goes back to it.
  final int previousQuantity;

  /// The most the cart line may reach (stock and the per-line cart limit).
  final int maxQuantity;
}

@immutable
class ScanState {
  const ScanState({this.looking = false, this.entry, this.problem, this.added = 0});

  /// A lookup is on its way.
  final bool looking;
  final ScanEntry? entry;
  final ScanProblem? problem;

  /// Units put in the cart by scanning in this visit (Undo takes one back).
  final int added;

  ScanState withLooking(bool value) => ScanState(looking: value, entry: entry, problem: problem, added: added);
}

/// Where "now" comes from: tests move it, the app uses the real clock.
final scanClockProvider = Provider<DateTime Function()>((ref) => DateTime.now);

/// The scanner's brain, free of widgets and cameras (so it is tested without either).
///
/// - The same code is ignored for [cooldown] after it was last *seen*: holding the phone on one barcode adds one unit, not ten.
///   Moving away for two seconds and scanning again is a deliberate second scan and adds another unit.
/// - One lookup at a time: while one is running, other codes are dropped (the camera sees them again a moment later).
/// - A found product is added with the cart's own optimistic logic ([CartController.addItem]): the badge moves at once, a variant
///   already in the cart gets a higher quantity (never a second line) and the cart goes back if the server refuses.
class ScanController extends Notifier<ScanState> {
  static const cooldown = Duration(seconds: 2);

  final _seen = <String, DateTime>{};
  bool _busy = false;
  int _seq = 0;

  @override
  ScanState build() => const ScanState();

  /// A code from the camera ([manual] false) or from the typing window ([manual] true, which is always deliberate).
  Future<void> submit(String raw, {bool manual = false}) async {
    final code = raw.trim();
    if (code.isEmpty) return;
    final now = ref.read(scanClockProvider)();
    final last = _seen[code];
    _seen[code] = now;
    _seen.removeWhere((_, t) => now.difference(t) > const Duration(seconds: 10));
    if (!manual && last != null && now.difference(last) < cooldown) return;
    if (_busy) return;

    _busy = true;
    // The previous card stays until the answer arrives (no flicker); the frame shows "looking" meanwhile.
    state = state.withLooking(true);
    try {
      await _lookupAndAdd(code);
    } finally {
      _busy = false;
      if (ref.mounted) state = state.withLooking(false);
    }
  }

  /// "Try again" after an offline answer.
  Future<void> retry() {
    final code = state.problem?.code;
    return code == null ? Future.value() : submit(code, manual: true);
  }

  Future<void> _lookupAndAdd(String code) async {
    if (code.length > ScanRepository.maxCodeLength) return _problem(ScanProblem(ScanProblemKind.notFound, code: code));
    final ScanLookup found;
    try {
      found = await ref.read(scanRepositoryProvider).byCode(code, branchId: ref.read(preferredBranchIdProvider));
    } on ApiException catch (e) {
      if (!ref.mounted) return;
      if (e.kind == ApiErrorKind.notFound || e.code == 'product_not_found') {
        return _problem(ScanProblem(ScanProblemKind.notFound, code: code));
      }
      return _problem(
        ScanProblem(e.isNetwork ? ScanProblemKind.offline : ScanProblemKind.failed, code: code, error: e),
      );
    }
    if (!ref.mounted) return;

    final product = found.product;
    if (found.needsChoice) return _problem(ScanProblem(ScanProblemKind.needsChoice, product: product, code: code));
    if (!found.purchasable) return _problem(ScanProblem(ScanProblemKind.unavailable, product: product, code: code));
    if (!found.canAdd) return _problem(ScanProblem(ScanProblemKind.outOfStock, product: product, code: code));
    if (!ref.read(isAuthenticatedProvider)) {
      return _problem(ScanProblem(ScanProblemKind.signInRequired, product: product, code: code));
    }

    final variantId = found.variantId!;
    final max = found.maxQuantity!;
    final cart = ref.read(cartControllerProvider.notifier);
    try {
      // The cart must be known first: "already in the cart" decides between a new line and a higher quantity.
      final before = await ref.read(cartControllerProvider.future);
      if (!ref.mounted) return;
      final inCart = before.items.where((i) => i.variantId == variantId).firstOrNull?.quantity ?? 0;
      if (inCart + 1 > max) {
        return _problem(ScanProblem(ScanProblemKind.limitReached, product: product, code: code, max: max));
      }

      await cart.addItem(variantId, product: product, unitPrice: found.price, variantName: found.variantName);
      if (!ref.mounted) return;
      Haptics.success();
      state = ScanState(
        looking: true,
        added: state.added + 1,
        entry: ScanEntry(
          id: ++_seq,
          product: product,
          variantId: variantId,
          variantName: found.variantName,
          price: found.price,
          previousQuantity: inCart,
          maxQuantity: max,
        ),
      );
    } on ApiException catch (e) {
      if (!ref.mounted) return;
      return _problem(
        ScanProblem(
          e.isNetwork ? ScanProblemKind.offline : ScanProblemKind.failed,
          product: product,
          code: code,
          error: e,
        ),
      );
    }
  }

  void _problem(ScanProblem problem) {
    if (!ref.mounted) return;
    problem.kind == ScanProblemKind.needsChoice ? Haptics.tap() : Haptics.warning();
    state = ScanState(looking: state.looking, problem: problem, added: state.added);
  }

  /// "Keep scanning": hides the card; the camera never stopped.
  void dismiss() => state = ScanState(added: state.added, looking: state.looking);

  /// The option window was closed (or the choice was made elsewhere): the card of a choice is no longer needed.
  void clearChoice() {
    if (state.problem?.kind == ScanProblemKind.needsChoice) dismiss();
  }

  /// Takes this scan back: the line returns to the quantity it had before (and goes away when that was none).
  /// Returns false when nothing could be undone (the line is still being added, or the server refused).
  Future<bool> undo() async {
    final entry = state.entry;
    if (entry == null) return false;
    final line = _lineOf(entry.variantId);
    if (line == null || ref.read(cartBusyItemsProvider).contains(line.id)) return false;
    state = ScanState(added: (state.added - 1).clamp(0, 1 << 30));
    final cart = ref.read(cartControllerProvider.notifier);
    try {
      await (entry.previousQuantity <= 0
          ? cart.removeItem(line.id)
          : cart.updateQuantity(line.id, entry.previousQuantity));
      return true;
    } on ApiException catch (e) {
      if (ref.mounted) _problem(ScanProblem(ScanProblemKind.failed, product: entry.product, error: e));
      return false;
    }
  }

  CartItem? _lineOf(String variantId) =>
      ref.read(cartControllerProvider).value?.items.where((i) => i.variantId == variantId).firstOrNull;
}

final scanControllerProvider = NotifierProvider.autoDispose<ScanController, ScanState>(ScanController.new);
