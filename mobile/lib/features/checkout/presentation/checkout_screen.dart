import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/data/branch_repository.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/checkout/domain/alternate_phone.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/delivery_slot_section.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_flow.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:albahar_customer/features/profile/presentation/addresses_screen.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final pickupBranchesProvider = FutureProvider.autoDispose<List<Branch>>((ref) async {
  ref.watch(localeCodeProvider);
  return (await ref.watch(branchRepositoryProvider).list(pickup: true)).items;
});

class CheckoutScreen extends ConsumerStatefulWidget {
  const CheckoutScreen({super.key});

  @override
  ConsumerState<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends ConsumerState<CheckoutScreen> {
  final _notes = TextEditingController();

  @override
  void initState() {
    super.initState();
    Future.microtask(() async {
      // Ensure a default address is known, then quote.
      final controller = ref.read(checkoutControllerProvider.notifier);
      final state = ref.read(checkoutControllerProvider);
      if (state.options.addressId == null) {
        final list = await ref.read(addressesProvider.future).catchError((Object _) => <Address>[]);
        if (!mounted) return;
        final def = list.where((a) => a.isDefault).firstOrNull ?? list.firstOrNull;
        if (def != null) {
          await controller.update((o) => o.copyWith(addressId: () => def.id));
          return;
        }
      }
      await controller.refreshQuote();
    });
  }

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  Future<void> _place() async {
    final controller = ref.read(checkoutControllerProvider.notifier);
    controller.setNotes(_notes.text);
    final placed = await controller.placeOrder();
    if (!mounted) return;
    final state = ref.read(checkoutControllerProvider);
    if (placed == null) {
      if (state.quoteChanged) {
        Haptics.warning();
        await _showQuoteChanged(state);
      } else if (state.placeError != null) {
        showErrorSnack(context, state.placeError!);
      }
      return;
    }
    Haptics.success();
    await continueAfterOrder(context, ref, placed);
  }

  Future<void> _showQuoteChanged(CheckoutState state) async {
    final l10n = context.l10n;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(l10n.quoteChangedTitle),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(l10n.quoteChangedMessage),
            const Gap(AppSpace.md),
            if (state.previousTotal != null)
              Text(
                l10n.previousTotal(state.previousTotal!.formatted),
                style: ctx.text.bodySmall?.copyWith(decoration: TextDecoration.lineThrough),
              ),
            if (state.quote != null)
              Text('${l10n.grandTotal}: ${state.quote!.totals.grandTotal.formatted}', style: ctx.text.titleLarge),
            for (final w in state.quote?.warnings ?? const <QuoteIssue>[])
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('• ${w.message}', style: ctx.text.bodySmall),
              ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: Text(l10n.reviewAndConfirm)),
          FilledButton(
            onPressed: (state.quote?.canPlaceOrder ?? false) ? () => Navigator.of(ctx).pop(true) : null,
            child: Text(l10n.placeOrder),
          ),
        ],
      ),
    );
    ref.read(checkoutControllerProvider.notifier).acknowledgeQuoteChange();
    if (confirmed == true) await _place();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final state = ref.watch(checkoutControllerProvider);
    final controller = ref.read(checkoutControllerProvider.notifier);
    final o = state.options;
    final quote = state.quote;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.checkoutTitle)),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.xxl),
        children: [
          ContentWidth(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                CheckoutSteps(current: 1, labels: [l10n.cartTitle, l10n.delivery, l10n.paymentMethod]),
                const Gap(AppSpace.md),
                _FulfillmentToggle(
                  selected: o.fulfillmentType,
                  onChanged: (t) {
                    Haptics.tap();
                    controller.update((x) => x.copyWith(fulfillmentType: t));
                  },
                ),
                const Gap(AppSpace.md),
                if (o.fulfillmentType == FulfillmentType.delivery) ...[
                  _AddressSection(
                    addressId: o.addressId,
                    onChanged: (id) => controller.update((x) => x.copyWith(addressId: () => id)),
                  ),
                  const Gap(AppSpace.md),
                  _AlternatePhoneSection(
                    input: state.alternate,
                    mainPhone: state.mainPhone,
                    onText: controller.setAlternateText,
                    onDeclined: controller.setAlternateDeclined,
                  ),
                ] else
                  _PickupSection(state: state, controller: controller),
                if (o.fulfillmentType == FulfillmentType.delivery &&
                    quote != null &&
                    quote.slotRequired &&
                    quote.singleFulfillment != null) ...[
                  const Gap(AppSpace.lg),
                  DeliverySlotSection(
                    key: ValueKey('slots-${quote.singleFulfillment!.branch.id}'),
                    branchId: quote.singleFulfillment!.branch.id,
                    selectedSlotId: o.deliverySlotId,
                    selectedDate: o.deliveryDate,
                    unavailable: state.slotUnavailable,
                    slotRequired: quote.issue('slot_required') != null,
                    auto: state.slotAuto,
                    onSwitch: (ref.watch(pickupBranchesProvider).value?.isNotEmpty ?? false)
                        ? () => controller.update((x) => x.copyWith(fulfillmentType: FulfillmentType.pickup))
                        : null,
                    onSelect: (slot, date) =>
                        controller.selectDeliverySlot(slot, branchId: quote.singleFulfillment!.branch.id, date: date),
                  ),
                ],
                if (quote != null && (quote.isSplit || quote.fulfillments.length > 1)) ...[
                  const Gap(AppSpace.lg),
                  _SplitPlan(quote: quote),
                ],
                const Gap(AppSpace.lg),
                _StepTitle(l10n.paymentMethod),
                _PaymentMethods(
                  quote: quote,
                  selected: o.paymentMethod,
                  onChanged: (m) => controller.update((x) => x.copyWith(paymentMethod: () => m)),
                ),
                if (quote?.loyalty != null && quote!.loyalty!.balance > 0) ...[
                  const Gap(AppSpace.lg),
                  _LoyaltySection(
                    loyalty: quote.loyalty!,
                    points: o.loyaltyPoints,
                    onChanged: (p) => controller.update((x) => x.copyWith(loyaltyPoints: p)),
                  ),
                ],
                const Gap(AppSpace.lg),
                _StepTitle(l10n.orderSummary),
                _QuoteSummary(state: state, onRetry: controller.refreshQuote),
                const Gap(AppSpace.md),
                // The server decides whether the choice exists: the admin setting is on AND more than one active branch holds
                // part of this cart. Otherwise the switch would only confuse.
                if (quote?.splitChoice ?? false) ...[
                  SwitchListTile(
                    key: const ValueKey('allow-split-switch'),
                    contentPadding: EdgeInsets.zero,
                    value: o.allowSplit,
                    onChanged: (v) => controller.update((x) => x.copyWith(allowSplit: v)),
                    title: Text(l10n.allowSplit),
                    subtitle: Text(l10n.allowSplitHint),
                  ),
                  const Gap(AppSpace.sm),
                ],
                AppTextField(
                  controller: _notes,
                  label: '${l10n.customerNotes} (${l10n.optional})',
                  maxLines: 3,
                  minLines: 1,
                  maxLength: 500,
                ),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: _PlaceOrderBar(state: state, onPlace: _place),
    );
  }
}

/// Sand track with a navy selected pill (Delivery / Pickup).
class _FulfillmentToggle extends StatelessWidget {
  const _FulfillmentToggle({required this.selected, required this.onChanged});
  final FulfillmentType selected;
  final ValueChanged<FulfillmentType> onChanged;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    Widget seg(FulfillmentType t, String label, IconData icon) {
      final on = selected == t;
      return Expanded(
        child: Semantics(
          button: true,
          selected: on,
          label: label,
          child: GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTap: () => onChanged(t),
            child: AnimatedContainer(
              duration: AppDurations.medium,
              height: kMinTouchTarget,
              decoration: BoxDecoration(
                color: on ? context.colors.primary : Colors.transparent,
                borderRadius: BorderRadius.circular(999),
              ),
              alignment: Alignment.center,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(icon, size: 18, color: on ? context.colors.onPrimary : context.palette.muted),
                  const Gap(AppSpace.xs),
                  Flexible(
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: context.text.labelLarge?.copyWith(
                        color: on ? context.colors.onPrimary : context.palette.muted,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      );
    }

    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: context.palette.line, borderRadius: BorderRadius.circular(999)),
      child: Row(
        children: [
          seg(FulfillmentType.delivery, l10n.delivery, Icons.local_shipping_outlined),
          seg(FulfillmentType.pickup, l10n.pickup, Icons.storefront_outlined),
        ],
      ),
    );
  }
}

class _StepTitle extends StatelessWidget {
  const _StepTitle(this.text);
  final String text;
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: AppSpace.sm),
    child: Semantics(header: true, child: Text(text, style: context.text.titleLarge)),
  );
}

class _AddressSection extends ConsumerWidget {
  const _AddressSection({required this.addressId, required this.onChanged});
  final String? addressId;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final addresses = ref.watch(addressesProvider);
    final address = addresses.value?.where((a) => a.id == addressId).firstOrNull;

    Future<void> pick() async {
      final id = await showAddressPicker(context);
      if (id != null) onChanged(id);
    }

    if (addresses.isLoading && address == null) return const Skeleton(height: 88, radius: AppRadius.card);
    if (address == null) {
      return SurfaceCard(
        onTap: pick,
        child: Row(
          children: [
            Icon(Icons.add_location_alt_outlined, color: context.palette.brass),
            const Gap(AppSpace.sm),
            Expanded(child: Text(l10n.selectAddressFirst, style: context.text.titleSmall)),
            const Icon(Icons.chevron_right_rounded),
          ],
        ),
      );
    }
    return SurfaceCard(
      onTap: pick,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(Icons.location_on_outlined, color: context.palette.brass),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(address.label ?? address.recipientName, style: context.text.titleSmall),
                Text(
                  address.summary(
                    zone: l10n.zoneNumber,
                    street: l10n.streetNumber,
                    building: l10n.buildingNumber,
                    unit: l10n.unitNumber,
                    separator: context.localeCode == 'ar' ? '، ' : ', ',
                  ),
                  style: context.text.bodySmall,
                ),
                Text(address.recipientPhone, style: context.text.bodySmall, textDirection: TextDirection.ltr),
              ],
            ),
          ),
          TextButton(onPressed: pick, child: Text(l10n.change)),
        ],
      ),
    );
  }
}

/// The customer's second number for the driver: prefilled from the profile; otherwise they type one or tick "I don't have another
/// number". The place-order button waits for one of the two, and the bar below says so before they ever press it.
class _AlternatePhoneSection extends StatefulWidget {
  const _AlternatePhoneSection({
    required this.input,
    required this.mainPhone,
    required this.onText,
    required this.onDeclined,
  });
  final AlternatePhoneInput input;
  final String? mainPhone;
  final ValueChanged<String> onText;
  final ValueChanged<bool> onDeclined;

  @override
  State<_AlternatePhoneSection> createState() => _AlternatePhoneSectionState();
}

class _AlternatePhoneSectionState extends State<_AlternatePhoneSection> {
  late final _text = TextEditingController(text: widget.input.text);
  final _focus = FocusNode();
  bool _blurred = false;

  @override
  void initState() {
    super.initState();
    _focus.addListener(() {
      if (!_focus.hasFocus && _text.text.trim().isNotEmpty && !_blurred) setState(() => _blurred = true);
    });
  }

  @override
  void dispose() {
    _text.dispose();
    _focus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final input = widget.input;
    // The saved number arrives, or the tick empties the field, from outside: follow it unless the customer is typing.
    if (_text.text != input.text && !_focus.hasFocus) _text.text = input.text;
    final status = input.status(mainPhone: widget.mainPhone);
    final digits = QatarPhone.normalizeDigits(_text.text).replaceAll('+', '');
    // Never a surprise: a half-typed number is not an error until they leave the field or have typed a whole number.
    final showError = (status == AlternatePhoneStatus.invalid && (_blurred || digits.length >= 8)) ||
        status == AlternatePhoneStatus.sameAsMain;
    final error = switch (status) {
      AlternatePhoneStatus.sameAsMain => l10n.alternatePhoneSame,
      AlternatePhoneStatus.invalid when showError => l10n.alternatePhoneInvalid,
      _ => null,
    };
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        AppTextField(
          key: const ValueKey('alternate-phone-field'),
          controller: _text,
          focusNode: _focus,
          label: l10n.alternatePhoneLabel,
          hint: l10n.phoneHint,
          prefix: '+974  ',
          forceLtr: true,
          enabled: !input.declined,
          keyboardType: TextInputType.phone,
          textInputAction: TextInputAction.done,
          autofillHints: const [AutofillHints.telephoneNumberNational],
          inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9٠-٩۰-۹+ ]')), LengthLimitingTextInputFormatter(16)],
          helper: status == AlternatePhoneStatus.valid ? l10n.alternatePhoneSavedNote : l10n.alternatePhoneHelper,
          errorText: error,
          onChanged: (v) {
            if (_blurred && v.trim().isEmpty) setState(() => _blurred = false);
            widget.onText(v);
          },
        ),
        CheckboxListTile(
          key: const ValueKey('alternate-phone-none'),
          contentPadding: EdgeInsets.zero,
          controlAffinity: ListTileControlAffinity.leading,
          visualDensity: VisualDensity.compact,
          value: input.declined,
          onChanged: (v) {
            Haptics.tap();
            widget.onDeclined(v ?? false);
            if (v ?? false) _focus.unfocus();
          },
          title: Text(l10n.alternatePhoneNone),
        ),
      ],
    );
  }
}

/// Store pickup: the showroom, then the earliest free pickup time chosen for the customer (one tap to change it).
class _PickupSection extends ConsumerWidget {
  const _PickupSection({required this.state, required this.controller});
  final CheckoutState state;
  final CheckoutController controller;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final branches = ref.watch(pickupBranchesProvider);
    final o = state.options;
    final branchId = o.branchId;
    final branch = branches.value?.where((b) => b.id == branchId).firstOrNull;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SurfaceCard(
          onTap: () async {
            final list = branches.value ?? const <Branch>[];
            final chosen = await showSidePanel<Branch>(
              context: context,
              title: l10n.pickupBranch,
              builder: (ctx) => RadioGroup<String>(
                groupValue: branchId,
                onChanged: (id) => Navigator.of(ctx).pop(list.firstWhere((b) => b.id == id)),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    for (final b in list)
                      RadioListTile<String>(
                        value: b.id,
                        title: Text(b.name),
                        subtitle: b.area == null ? null : Text(b.area!),
                      ),
                  ],
                ),
              ),
            );
            // A new showroom has its own times: the old one is dropped and the earliest free one chosen again.
            if (chosen != null) await controller.update((x) => x.copyWith(branchId: () => chosen.id));
          },
          child: Row(
            children: [
              Icon(Icons.storefront_outlined, color: context.palette.brass),
              const Gap(AppSpace.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(l10n.pickupBranch, style: context.text.bodySmall),
                    Text(branch?.name ?? l10n.selectBranchFirst, style: context.text.titleSmall),
                  ],
                ),
              ),
              const Icon(Icons.expand_more_rounded),
            ],
          ),
        ),
        if (branchId != null) ...[
          const Gap(AppSpace.md),
          PickupSlotSection(
            key: ValueKey('pickup-slots-$branchId'),
            branchId: branchId,
            selectedSlotId: o.pickupSlotId,
            selectedDate: o.pickupDate,
            unavailable: state.slotUnavailable,
            auto: state.slotAuto,
            onSwitch: () => controller.update((x) => x.copyWith(fulfillmentType: FulfillmentType.delivery)),
            onSelect: (slot, date) => controller.selectPickupSlot(slot, date: date),
          ),
        ],
      ],
    );
  }
}

class _SplitPlan extends StatelessWidget {
  const _SplitPlan({required this.quote});
  final Quote quote;

  String _eta(BuildContext context, int minutes) {
    final l10n = context.l10n;
    if (minutes >= 60) return l10n.hoursShort((minutes / 60).ceil());
    return l10n.minutesShort(minutes);
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Container(
      padding: const EdgeInsets.all(AppSpace.md),
      decoration: BoxDecoration(color: context.palette.brassTint, borderRadius: AppRadius.cardAll),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(Icons.call_split_rounded, color: context.palette.brass),
              const Gap(AppSpace.sm),
              Expanded(
                child: Text(l10n.splitFulfillmentTitle(quote.fulfillments.length), style: context.text.titleMedium),
              ),
            ],
          ),
          const Gap(AppSpace.xxs),
          Text(l10n.splitFulfillmentMessage, style: context.text.bodySmall),
          for (final f in quote.fulfillments) ...[
            const Gap(AppSpace.sm),
            SurfaceCard(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Icon(f.type == 'pickup' ? Icons.storefront_outlined : Icons.local_shipping_outlined, size: 18),
                      const Gap(AppSpace.xs),
                      Expanded(child: Text(l10n.fulfilledFrom(f.branch.name), style: context.text.titleSmall)),
                      if (f.etaMinutes != null)
                        Text(l10n.etaMinutes(_eta(context, f.etaMinutes!)), style: context.text.bodySmall),
                    ],
                  ),
                  for (final i in f.items)
                    Text(
                      '${i.quantity} × ${i.name}',
                      style: context.text.bodySmall,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                    ),
                  if (f.deliveryFee != null)
                    SummaryRow(
                      label: l10n.deliveryFee,
                      value: f.deliveryFee!.isZero ? l10n.free : f.deliveryFee!.formatted,
                    ),
                ],
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _PaymentMethods extends StatelessWidget {
  const _PaymentMethods({required this.quote, required this.selected, required this.onChanged});
  final Quote? quote;
  final PaymentMethod? selected;
  final ValueChanged<PaymentMethod> onChanged;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    if (quote == null) return const Skeleton(height: 120, radius: AppRadius.card);
    String label(PaymentMethod m) => switch (m) {
      PaymentMethod.sadad => l10n.paySadad,
      PaymentMethod.cod => l10n.payCod,
      PaymentMethod.payAtPickup => l10n.payAtPickup,
    };
    IconData icon(PaymentMethod m) => switch (m) {
      PaymentMethod.sadad => Icons.credit_card_rounded,
      PaymentMethod.cod => Icons.payments_outlined,
      PaymentMethod.payAtPickup => Icons.point_of_sale_outlined,
    };
    String reason(String? r) => r == 'exceeds_cod_limit' ? l10n.paymentReasonCodLimit : l10n.paymentUnavailable;

    return RadioGroup<PaymentMethod>(
      groupValue: selected,
      onChanged: (m) {
        if (m == null) return;
        Haptics.tap();
        onChanged(m);
      },
      child: Column(
        children: [
          for (final opt in quote!.paymentMethods)
            if (opt.method != null)
              Padding(
                padding: const EdgeInsets.only(bottom: AppSpace.xs),
                child: Material(
                  color: context.colors.surface,
                  shape: RoundedRectangleBorder(
                    borderRadius: AppRadius.inputAll,
                    side: BorderSide(
                      color: selected == opt.method ? context.colors.primary : Colors.transparent,
                      width: 1.5,
                    ),
                  ),
                  clipBehavior: Clip.antiAlias,
                  child: RadioListTile<PaymentMethod>(
                    value: opt.method!,
                    enabled: opt.available,
                    shape: const RoundedRectangleBorder(borderRadius: AppRadius.inputAll),
                    secondary: Icon(icon(opt.method!), color: context.colors.primary),
                    title: Text(label(opt.method!)),
                    subtitle: opt.available ? null : Text(reason(opt.reason), style: context.text.bodySmall),
                  ),
                ),
              ),
        ],
      ),
    );
  }
}

class _LoyaltySection extends StatefulWidget {
  const _LoyaltySection({required this.loyalty, required this.points, required this.onChanged});
  final QuoteLoyalty loyalty;
  final int points;
  final ValueChanged<int> onChanged;

  @override
  State<_LoyaltySection> createState() => _LoyaltySectionState();
}

class _LoyaltySectionState extends State<_LoyaltySection> {
  late double _value = widget.points.toDouble();

  @override
  void didUpdateWidget(covariant _LoyaltySection oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.points != widget.points) _value = widget.points.toDouble();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final max = widget.loyalty.maxRedeemablePoints;
    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SwitchListTile(
            contentPadding: EdgeInsets.zero,
            value: widget.points > 0,
            onChanged: max <= 0 ? null : (v) => widget.onChanged(v ? max : 0),
            title: Text(l10n.usePoints),
            subtitle: Text('${l10n.pointsBalance(widget.loyalty.balance)} · ${l10n.maxRedeemable(max)}'),
          ),
          if (widget.points > 0 && max > 0)
            Slider(
              value: _value.clamp(0, max.toDouble()),
              max: max.toDouble(),
              divisions: max > 100 ? 20 : max,
              label: l10n.pointsUnit(_value.round()),
              onChanged: (v) => setState(() => _value = v),
              onChangeEnd: (v) => widget.onChanged(v.round()),
            ),
          if (widget.loyalty.pointsToEarn > 0)
            Text(
              l10n.pointsToEarn(widget.loyalty.pointsToEarn),
              style: context.text.bodySmall?.copyWith(color: context.palette.success),
            ),
        ],
      ),
    );
  }
}

class _QuoteSummary extends StatelessWidget {
  const _QuoteSummary({required this.state, required this.onRetry});
  final CheckoutState state;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final quote = state.quote;
    if (quote == null) {
      if (state.quoteError != null) return ErrorState(error: state.quoteError, onRetry: onRetry, compact: true);
      return const Skeleton(height: 220, radius: AppRadius.card);
    }
    final t = quote.totals;
    Widget? fee(String label, Money? m, {bool negative = false, bool freeIfZero = false}) {
      if (m == null) return null;
      if (m.isZero && !freeIfZero) return null;
      return SummaryRow(
        label: label,
        value: m.isZero ? l10n.free : (negative ? '− ${m.formatted}' : m.formatted),
        valueColor: negative ? context.palette.success : null,
      );
    }

    return AnimatedOpacity(
      duration: AppDurations.fast,
      opacity: state.quoting ? 0.5 : 1,
      child: SurfaceCard(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final line in quote.lines)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
                child: Row(
                  children: [
                    AppNetworkImage(url: line.imageUrl, width: 48, height: 56, borderRadius: BorderRadius.circular(8)),
                    const Gap(AppSpace.sm),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(line.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: context.text.bodyMedium),
                          Text(
                            [if (line.variantName != null) line.variantName!, '× ${line.quantity}'].join(' · '),
                            style: context.text.bodySmall,
                          ),
                        ],
                      ),
                    ),
                    Text(line.lineTotal.formatted, style: context.text.titleSmall),
                  ],
                ),
              ),
            if ((state.options.fulfillmentType == FulfillmentType.pickup ? quote.pickupSlot : quote.deliverySlot)
                case final slot?) ...[
              Divider(color: context.palette.line, height: AppSpace.lg),
              Row(
                key: const ValueKey('delivery-slot-summary'),
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(Icons.schedule_rounded, size: 18, color: context.palette.brass),
                  const Gap(AppSpace.xs),
                  Expanded(
                    child: Text(
                      (state.options.fulfillmentType == FulfillmentType.pickup
                          ? l10n.pickupSlotSummary
                          : l10n.deliverySlotSummary)(
                        QatarTime.slotDay(slot.date, context.localeCode),
                        QatarTime.clock(slot.startsAt, context.localeCode),
                        QatarTime.clock(slot.endsAt, context.localeCode),
                      ),
                      style: context.text.bodyMedium,
                    ),
                  ),
                ],
              ),
            ],
            Divider(color: context.palette.line, height: AppSpace.lg),
            SummaryRow(label: l10n.subtotal, value: t.subtotal.formatted),
            for (final d in quote.discounts)
              SummaryRow(
                label: d.code != null ? '${d.label} (${d.code})' : d.label,
                value: '− ${d.amount.formatted}',
                valueColor: context.palette.success,
              ),
            if (quote.discounts.isEmpty) ?fee(l10n.discount, t.discount, negative: true),
            ?fee(
              l10n.deliveryFee,
              t.deliveryFee,
              freeIfZero: state.options.fulfillmentType == FulfillmentType.delivery,
            ),
            ?fee(l10n.serviceFee, t.serviceFee),
            ?fee(l10n.codFee, t.codFee),
            ?fee(l10n.tax, t.tax),
            ?fee(l10n.loyaltyDiscount, t.loyaltyDiscount, negative: true),
            Divider(color: context.palette.line, height: AppSpace.lg),
            SummaryRow(label: l10n.grandTotal, value: t.grandTotal.formatted, emphasize: true),
            for (final w in quote.warnings)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.xs),
                child: Text(w.message, style: context.text.bodySmall?.copyWith(color: context.palette.warning)),
              ),
            // Slot errors are shown inline in the delivery step.
            for (final e in quote.errors.where((e) => e.code != 'slot_required' && e.code != 'slot_unavailable'))
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.xs),
                child: Text(e.message, style: context.text.bodySmall?.copyWith(color: context.palette.danger)),
              ),
            if (state.quoteError != null)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.xs),
                child: Text(
                  describeError(l10n, state.quoteError),
                  style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _PlaceOrderBar extends StatelessWidget {
  const _PlaceOrderBar({required this.state, required this.onPlace});
  final CheckoutState state;
  final VoidCallback onPlace;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final missing = state.missingRequirement;
    final alternate = state.alternate.status(mainPhone: state.mainPhone);
    final hint = switch (missing) {
      'address' => l10n.selectAddressFirst,
      'branch' => l10n.selectBranchFirst,
      // Why the button waits, in plain words, before the customer ever presses it.
      'alternate' => switch (alternate) {
        AlternatePhoneStatus.sameAsMain => l10n.alternatePhoneSame,
        AlternatePhoneStatus.invalid => l10n.alternatePhoneInvalid,
        _ => l10n.alternatePhoneRequiredHint,
      },
      'slot' => l10n.deliverySlotRequiredHint,
      'payment' => l10n.selectPaymentFirst,
      _ => (state.quote != null && !state.quote!.canPlaceOrder) ? l10n.cannotPlaceOrder : null,
    };
    // Nothing is holding the button back: say which time the order is booked for, so what is about to be placed is plain.
    final locale = context.localeCode;
    final delivery = state.options.fulfillmentType == FulfillmentType.delivery;
    final slot = delivery ? state.quote?.deliverySlot : state.quote?.pickupSlot;
    final when = hint != null || slot == null
        ? null
        : '${QatarTime.slotDay(slot.date, locale)} · ${QatarTime.slotRange(slot.startsAt, slot.endsAt, locale)}';
    return Container(
      decoration: BoxDecoration(
        color: context.colors.surface,
        border: Border(top: BorderSide(color: context.palette.line)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.sm),
          child: ContentWidth(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (hint != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpace.xs),
                    child: Text(
                      hint,
                      key: const ValueKey('place-order-hint'),
                      textAlign: TextAlign.center,
                      style: context.text.bodySmall?.copyWith(color: context.palette.warning),
                    ),
                  )
                else if (when != null)
                  Padding(
                    padding: const EdgeInsets.only(bottom: AppSpace.xs),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(
                          delivery ? Icons.local_shipping_outlined : Icons.storefront_outlined,
                          size: 16,
                          color: context.palette.brass,
                        ),
                        const Gap(AppSpace.xs),
                        Flexible(
                          child: Text(
                            when,
                            key: const ValueKey('place-order-when'),
                            textAlign: TextAlign.center,
                            style: context.text.bodySmall,
                          ),
                        ),
                      ],
                    ),
                  ),
                Row(
                  children: [
                    Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(l10n.grandTotal, style: context.text.bodySmall),
                        AnimatedSwitcher(
                          duration: AppDurations.medium,
                          child: state.quoting
                              ? Text(l10n.calculating, key: const ValueKey('calc'), style: context.text.titleMedium)
                              : Text(
                                  state.quote?.totals.grandTotal.formatted ?? '—',
                                  key: ValueKey(state.quote?.quoteHash),
                                  style: context.text.titleLarge,
                                ),
                        ),
                      ],
                    ),
                    const Gap(AppSpace.md),
                    Expanded(
                      child: AppButton.accent(
                        label: l10n.placeOrder,
                        loading: state.placing,
                        onPressed: state.canPlace ? onPlace : null,
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
