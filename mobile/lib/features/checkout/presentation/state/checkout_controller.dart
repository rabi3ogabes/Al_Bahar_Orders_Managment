import 'dart:async';

import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/router/navigation_lock.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/checkout/data/checkout_repository.dart';
import 'package:albahar_customer/features/checkout/domain/alternate_phone.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/domain/slot_selection.dart';
import 'package:albahar_customer/features/checkout/presentation/state/slot_providers.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

@immutable
class CheckoutState {
  const CheckoutState({
    required this.options,
    this.quote,
    this.quoting = false,
    this.quoteError,
    this.placing = false,
    this.placeError,
    this.quoteChanged = false,
    this.dealChanged = false,
    this.previousTotal,
    this.notes = '',
    this.slotUnavailable = false,
    this.slotBranchId,
    this.slotAuto = false,
    this.alternate = const AlternatePhoneInput(),
    this.alternateEnabled = true,
    this.mainPhone,
  });

  final CheckoutOptions options;
  final Quote? quote;
  final bool quoting;
  final Object? quoteError;
  final bool placing;
  final Object? placeError;

  /// True after a 409 `quote_changed`: the UI must show the new totals and
  /// require an explicit re-confirmation.
  final bool quoteChanged;

  /// True after a 409 `deal_changed` (a deal ended, ran out or hit a limit while the customer was paying): the quote on screen is
  /// the new one and a calm banner says so. Nothing is placed again until the customer confirms.
  final bool dealChanged;
  final Money? previousTotal;
  final String notes;

  /// The server rejected the chosen delivery slot (`slot_unavailable`); the
  /// selection was cleared and stays flagged until a new slot is picked.
  final bool slotUnavailable;

  /// Branch the chosen delivery slot belongs to (a slot is only valid there).
  final String? slotBranchId;

  /// The chosen time (delivery or pickup) was picked by the app as the earliest available, not by the customer.
  final bool slotAuto;

  /// The customer's answer about a second number for the driver (delivery orders only).
  final AlternatePhoneInput alternate;

  /// The dashboard's "Alternative phone number" switch. Off: the question is not asked, never holds the order back and nothing
  /// about the number is sent, whatever was typed before.
  final bool alternateEnabled;

  /// The account's main number, so the second one can be refused when it is the same.
  final String? mainPhone;

  /// A single-branch delivery whose branch publishes slots needs one chosen.
  bool get slotMissing =>
      options.fulfillmentType == FulfillmentType.delivery &&
      (quote?.slotRequired ?? false) &&
      options.deliverySlotId == null;

  /// Client-side precondition check only; the server decides via
  /// `can_place_order`.
  String? get missingRequirement {
    if (options.fulfillmentType == FulfillmentType.delivery && options.addressId == null) return 'address';
    if (options.fulfillmentType == FulfillmentType.pickup && options.branchId == null) return 'branch';
    if (alternateEnabled &&
        options.fulfillmentType == FulfillmentType.delivery &&
        !alternate.isDone(mainPhone: mainPhone)) {
      return 'alternate';
    }
    if (slotMissing) return 'slot';
    if (options.paymentMethod == null) return 'payment';
    return null;
  }

  /// The totals on screen belong to the choices made (a refresh that failed leaves the last good quote on screen, but it is no longer
  /// the quote of what the customer chose, so it never places an order).
  bool get canPlace =>
      quote != null && quote!.canPlaceOrder && !quoting && !placing && quoteError == null && missingRequirement == null;

  CheckoutState copyWith({
    CheckoutOptions? options,
    Quote? Function()? quote,
    bool? quoting,
    Object? Function()? quoteError,
    bool? placing,
    Object? Function()? placeError,
    bool? quoteChanged,
    bool? dealChanged,
    Money? Function()? previousTotal,
    String? notes,
    bool? slotUnavailable,
    String? Function()? slotBranchId,
    bool? slotAuto,
    AlternatePhoneInput? alternate,
    bool? alternateEnabled,
    String? Function()? mainPhone,
  }) => CheckoutState(
    options: options ?? this.options,
    quote: quote != null ? quote() : this.quote,
    quoting: quoting ?? this.quoting,
    quoteError: quoteError != null ? quoteError() : this.quoteError,
    placing: placing ?? this.placing,
    placeError: placeError != null ? placeError() : this.placeError,
    quoteChanged: quoteChanged ?? this.quoteChanged,
    dealChanged: dealChanged ?? this.dealChanged,
    previousTotal: previousTotal != null ? previousTotal() : this.previousTotal,
    notes: notes ?? this.notes,
    slotUnavailable: slotUnavailable ?? this.slotUnavailable,
    slotBranchId: slotBranchId != null ? slotBranchId() : this.slotBranchId,
    slotAuto: slotAuto ?? this.slotAuto,
    alternate: alternate ?? this.alternate,
    alternateEnabled: alternateEnabled ?? this.alternateEnabled,
    mainPhone: mainPhone != null ? mainPhone() : this.mainPhone,
  );
}

/// Drives the checkout: options → server quote → place order.
class CheckoutController extends Notifier<CheckoutState> {
  int _quoteSeq = 0;
  final Map<String, String> _idempotencyKeys = {};

  /// Times the app chose a slot since the customer (or a change of address, branch or fulfilment type) last decided: a guard so
  /// a server that keeps refusing the earliest time can never make the screen loop. The customer picks by hand after that.
  int _autoPicks = 0;
  static const _maxAutoPicks = 4;

  /// Times (`slotId|date`) the server refused for the current address / branch / fulfilment type, though its lists still show them:
  /// never chosen again for the customer, so the next earliest one is tried instead.
  final Set<String> _refused = {};

  @override
  CheckoutState build() {
    final cart = ref.read(cartControllerProvider).value;
    final address = ref.read(defaultAddressProvider);
    final branchId = ref.read(preferredBranchIdProvider);
    final type = FulfillmentType.parse(cart?.fulfillmentType) ?? FulfillmentType.delivery;
    final user = ref.read(sessionControllerProvider).user;
    // The saved second number starts the field; the account may only be known a moment later (then it is filled in once, untouched).
    ref.listen(sessionControllerProvider.select((s) => s.user), (_, user) {
      if (user == null) return;
      final untouched = state.alternate == const AlternatePhoneInput();
      state = state.copyWith(
        mainPhone: () => user.phone,
        alternate: untouched ? AlternatePhoneInput.fromSaved(user.alternatePhone) : null,
      );
    });
    // The dashboard can switch the question off (or on) while the screen is open.
    ref.listen(alternatePhoneEnabledProvider, (_, enabled) {
      if (enabled == state.alternateEnabled) return;
      state = state.copyWith(alternateEnabled: enabled);
      // An `alternate_phone_*` answer of the last quote must not keep holding the order back (or be missing) now the question changed.
      if (state.quote != null) unawaited(refreshQuote());
    });
    return CheckoutState(
      options: CheckoutOptions(
        fulfillmentType: type,
        addressId: cart?.addressId ?? address?.id,
        branchId: cart?.branchId ?? branchId,
        couponCode: cart?.couponCode,
      ),
      alternate: AlternatePhoneInput.fromSaved(user?.alternatePhone),
      alternateEnabled: ref.read(alternatePhoneEnabledProvider),
      mainPhone: user?.phone,
    );
  }

  CheckoutRepository get _repo => ref.read(checkoutRepositoryProvider);

  /// The customer's second number, sent with delivery requests once answered (pickup never asks, and nothing is sent while the
  /// dashboard has the question switched off).
  JsonMap get _alternateFields => state.alternateEnabled && state.options.fulfillmentType == FulfillmentType.delivery
      ? state.alternate.requestFields(mainPhone: state.mainPhone)
      : const {};

  /// Fetches a fresh quote for the current options. Stale responses (from
  /// superseded option changes) are discarded. If the server's answer makes
  /// the chosen payment method / points invalid, options are corrected and
  /// re-quoted once so the displayed totals always match what will be sent.
  Future<void> refreshQuote({bool allowCorrection = true}) async {
    final seq = ++_quoteSeq;
    final quotedWith = state.options;
    state = state.copyWith(quoting: true, quoteError: () => null);
    try {
      final quote = await _repo.quote(quotedWith, extra: _alternateFields);
      if (!ref.mounted || seq != _quoteSeq) return;
      var corrected = _correct(quotedWith, quote);
      var rejected = false;
      if (corrected.deliverySlotId != null) {
        final fulfillment = quote.singleFulfillment;
        if (quote.issue('slot_unavailable') != null) {
          // Invalid or full slot: drop it and refetch what is really free.
          rejected = true;
          _refused.add('${corrected.deliverySlotId}|${corrected.deliveryDate}');
          corrected = _withoutSlot(corrected);
          _refetchSlots();
        } else if (fulfillment == null ||
            fulfillment.type != 'delivery' ||
            (state.slotBranchId != null && fulfillment.branch.id != state.slotBranchId)) {
          // Split order (slot ignored) or the branch changed: the slot no longer applies.
          corrected = _withoutSlot(corrected);
        }
      } else if (corrected.pickupSlotId != null && quote.issue('slot_unavailable') != null) {
        rejected = true;
        _refused.add('${corrected.pickupSlotId}|${corrected.pickupDate}');
        corrected = _withoutPickupSlot(corrected);
        _refetchSlots();
      }
      final noSlot = corrected.deliverySlotId == null && corrected.pickupSlotId == null;
      // Items that need more days (made to order, assembly) start the time lists later; the lists follow the quote.
      ref.read(slotEarliestDateProvider.notifier).set(quote.fulfillments.firstOrNull?.earliestDate);
      state = state.copyWith(
        quote: () => quote,
        quoting: false,
        options: corrected,
        slotUnavailable: rejected ? true : null,
        slotBranchId: corrected.deliverySlotId == null ? () => null : null,
        slotAuto: noSlot ? false : null,
      );
      if (allowCorrection && corrected != quotedWith) await refreshQuote(allowCorrection: false);
    } catch (e) {
      if (!ref.mounted || seq != _quoteSeq) return;
      state = state.copyWith(quoting: false, quoteError: () => e);
    }
  }

  /// A time the server refused (or one that filled up): what is really free has to be fetched again.
  void _refetchSlots() {
    ref.invalidate(deliverySlotsProvider);
    ref.invalidate(pickupSlotsProvider);
    ref.invalidate(deliverySlotWindowProvider);
    ref.invalidate(pickupSlotWindowProvider);
  }

  static CheckoutOptions _withoutSlot(CheckoutOptions o) =>
      o.copyWith(deliverySlotId: () => null, deliveryDate: () => null);

  static CheckoutOptions _withoutPickupSlot(CheckoutOptions o) =>
      o.copyWith(pickupSlotId: () => null, pickupDate: () => null);

  static CheckoutOptions _correct(CheckoutOptions options, Quote quote) {
    var next = options;
    final pm = next.paymentMethod;
    if (pm != null && !(quote.option(pm)?.available ?? false)) {
      next = next.copyWith(paymentMethod: () => null);
    }
    if (next.paymentMethod == null) {
      final first = quote.paymentMethods.where((p) => p.available && p.method != null).firstOrNull;
      if (first != null) next = next.copyWith(paymentMethod: () => first.method);
    }
    final max = quote.loyalty?.maxRedeemablePoints;
    if (max != null && next.loyaltyPoints > max) next = next.copyWith(loyaltyPoints: max);
    return next;
  }

  Future<void> update(CheckoutOptions Function(CheckoutOptions o) change) async {
    final before = state.options;
    var next = change(before);
    if (next == before) return;
    // A time slot belongs to one branch: a new address, branch or fulfilment type invalidates it (the quote will name the right
    // branch) and the earliest time is chosen again for the new context.
    final context =
        next.addressId != before.addressId ||
        next.branchId != before.branchId ||
        next.fulfillmentType != before.fulfillmentType;
    final slotChanged = next.deliverySlotId != before.deliverySlotId || next.pickupSlotId != before.pickupSlotId;
    if (context && !slotChanged) next = _withoutPickupSlot(_withoutSlot(next));
    if (context) {
      _autoPicks = 0;
      _refused.clear();
    }
    state = state.copyWith(
      options: next,
      quoteChanged: false,
      dealChanged: false,
      previousTotal: () => null,
      slotUnavailable: context ? false : null,
      slotBranchId: next.deliverySlotId == null ? () => null : null,
      slotAuto: context && !slotChanged ? false : null,
    );
    await refreshQuote();
  }

  /// The customer chose [slot] of [branchId] (the branch named by the current quote). Never replaced by an earlier time later.
  Future<void> selectDeliverySlot(
    DeliverySlot slot, {
    required String branchId,
    required String date,
    bool auto = false,
  }) async {
    if (!auto) _autoPicks = 0;
    state = state.copyWith(slotUnavailable: auto ? null : false, slotBranchId: () => branchId, slotAuto: auto);
    await update((o) => o.copyWith(deliverySlotId: () => slot.id, deliveryDate: () => date));
  }

  /// The customer chose a pickup [slot] (the branch is the one in the options).
  Future<void> selectPickupSlot(PickupSlot slot, {required String date, bool auto = false}) async {
    if (!auto) _autoPicks = 0;
    state = state.copyWith(slotUnavailable: auto ? null : false, slotAuto: auto);
    await update((o) => o.copyWith(pickupSlotId: () => slot.id, pickupDate: () => date));
  }

  /// Brings the selected time in line with what [window] says is free right now ([SlotWindow] of delivery or pickup, per [kind]):
  /// chooses the earliest bookable time when none is chosen, replaces one that has become unavailable (the screen then says so),
  /// and leaves a still-bookable choice alone, whoever made it. Safe to call as often as the screen likes.
  Future<void> reconcileSlots({
    required FulfillmentType kind,
    required String branchId,
    required SlotWindow window,
    DateTime? now,
  }) async {
    if (!ref.mounted) return;
    final o = state.options;
    if (o.fulfillmentType != kind) return;
    final delivery = kind == FulfillmentType.delivery;
    if (!delivery && o.branchId != branchId) return;
    final id = delivery ? o.deliverySlotId : o.pickupSlotId;
    final date = delivery ? o.deliveryDate : o.pickupDate;
    final decision = decideSlot(
      window: _withoutRefused(window),
      selectedSlotId: id,
      selectedDate: date,
      now: now ?? ref.read(slotClockProvider)(),
    );
    switch (decision.plan) {
      case SlotPlan.keep:
        return;
      case SlotPlan.none:
        if (id == null) return;
        state = state.copyWith(slotUnavailable: decision.replacedStale ? true : null, slotAuto: false);
        await update((x) => delivery ? _withoutSlot(x) : _withoutPickupSlot(x));
      case SlotPlan.choose:
        if (_autoPicks >= _maxAutoPicks) return;
        _autoPicks++;
        final pick = decision.choice!;
        if (decision.replacedStale) state = state.copyWith(slotUnavailable: true);
        if (delivery) {
          await selectDeliverySlot(pick.slot, branchId: branchId, date: pick.date, auto: true);
        } else {
          await selectPickupSlot(pick.slot, date: pick.date, auto: true);
        }
    }
  }

  /// [window] without the times the server has refused for this context.
  SlotWindow _withoutRefused(SlotWindow window) => _refused.isEmpty
      ? window
      : SlotWindow(
          from: window.from,
          to: window.to,
          days: [
            for (final d in window.days)
              SlotDay(
                date: d.date,
                available: d.available,
                slots: [
                  for (final s in d.slots)
                    if (!_refused.contains('${s.id}|${d.date}')) s,
                ],
              ),
          ],
        );

  void setNotes(String notes) => state = state.copyWith(notes: notes);

  /// The customer is typing the second number: no new quote is needed (it never changes the price).
  void setAlternateText(String text) => state = state.copyWith(alternate: state.alternate.typed(text));

  /// "I don't have another number": ticking empties the field, typing unticks.
  void setAlternateDeclined(bool declined) => state = state.copyWith(alternate: state.alternate.decline(declined));

  /// Clears the "totals changed" banner once the customer has reviewed it.
  void acknowledgeQuoteChange() => state = state.copyWith(quoteChanged: false, previousTotal: () => null);

  /// Clears the "a deal changed" banner.
  void acknowledgeDealChange() => state = state.copyWith(dealChanged: false, previousTotal: () => null);

  /// Places the order. Returns null when the quote changed (state then holds
  /// the new quote with `quoteChanged = true`) or on error (`placeError`).
  Future<PlacedOrder?> placeOrder() async {
    final quote = state.quote;
    if (quote == null || state.placing) return null;
    state = state.copyWith(placing: true, placeError: () => null, dealChanged: false);
    // While the order is on its way this controller must outlive its screen (an autoDispose one would die with it, and the cart
    // would never be cleared), and the footer menu is locked so nobody walks off the page that opens the payment.
    final alive = ref.keepAlive();
    ref.read(navigationLockProvider.notifier).set(true);
    // One key per confirmed quote: network retries of the same confirmation
    // reuse it (no duplicate orders); a changed quote gets a new key.
    final key = _idempotencyKeys.putIfAbsent(quote.quoteHash, Idempotency.newKey);
    try {
      final placed = await _repo.placeOrder(
        state.options,
        quoteHash: quote.quoteHash,
        idempotencyKey: key,
        customerNotes: state.notes,
        extra: _alternateFields,
      );
      // The order exists on the server now and the cart was converted into it: the cart is refreshed first, whatever became of the screen.
      ref.invalidate(cartControllerProvider);
      if (!ref.mounted) return placed;
      state = state.copyWith(placing: false);
      // The saved second number may have changed with the order.
      unawaited(ref.read(sessionControllerProvider.notifier).refreshUser());
      return placed;
    } on ApiException catch (e) {
      if (!ref.mounted) return null;
      if (e.statusCode == 409 && e.code == 'quote_changed') {
        final fresh = e.context['quote'];
        if (fresh is Map) {
          state = state.copyWith(
            placing: false,
            quote: () => Quote.fromJson(asMap(fresh)),
            quoteChanged: true,
            previousTotal: () => quote.totals.grandTotal,
          );
        } else {
          state = state.copyWith(placing: false, quoteChanged: true, previousTotal: () => quote.totals.grandTotal);
          await refreshQuote();
        }
        return null;
      }
      if (e.statusCode == 409 && e.code == 'deal_changed') {
        // A deal no longer covers the order as the customer saw it. The server sends the fresh quote; the screen shows it with a calm
        // banner and waits: the customer confirms again (a new quote has a new key, so nothing is ever placed twice).
        final fresh = e.context['quote'];
        state = state.copyWith(
          placing: false,
          quote: fresh is Map ? () => Quote.fromJson(asMap(fresh)) : null,
          dealChanged: true,
          previousTotal: () => quote.totals.grandTotal,
        );
        if (fresh is! Map) await refreshQuote();
        return null;
      }
      if (e.code == 'slot_unavailable') {
        // Someone took the slot meanwhile: clear it, refetch, re-quote (the earliest free time is then chosen again).
        final taken = state.options.deliverySlotId ?? state.options.pickupSlotId;
        final takenDate = state.options.deliverySlotId != null ? state.options.deliveryDate : state.options.pickupDate;
        if (taken != null) _refused.add('$taken|$takenDate');
        _refetchSlots();
        state = state.copyWith(
          placing: false,
          options: _withoutPickupSlot(_withoutSlot(state.options)),
          slotUnavailable: true,
          slotBranchId: () => null,
          slotAuto: false,
        );
        await refreshQuote();
        return null;
      }
      state = state.copyWith(placing: false, placeError: () => e);
      // The server refused for a reason the totals may show (stock, a limit, a coupon): look again so the screen says what is true now.
      if (e.statusCode == 409 || e.statusCode == 422) unawaited(refreshQuote());
      return null;
    } catch (e) {
      if (ref.mounted) state = state.copyWith(placing: false, placeError: () => e);
      return null;
    } finally {
      if (ref.mounted) ref.read(navigationLockProvider.notifier).set(false);
      alive.close();
    }
  }
}

final checkoutControllerProvider = NotifierProvider.autoDispose<CheckoutController, CheckoutState>(
  CheckoutController.new,
);
