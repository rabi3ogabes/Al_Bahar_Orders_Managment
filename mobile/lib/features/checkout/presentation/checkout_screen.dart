import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/branches/data/branch_repository.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_flow.dart';
import 'package:albahar_customer/features/checkout/presentation/state/checkout_controller.dart';
import 'package:albahar_customer/features/profile/domain/profile_models.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

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
                _StepTitle(l10n.fulfillmentMethod),
                SegmentedButton<FulfillmentType>(
                  segments: [
                    ButtonSegment(
                      value: FulfillmentType.delivery,
                      label: Text(l10n.delivery),
                      icon: const Icon(Icons.local_shipping_outlined),
                    ),
                    ButtonSegment(
                      value: FulfillmentType.pickup,
                      label: Text(l10n.pickup),
                      icon: const Icon(Icons.storefront_outlined),
                    ),
                  ],
                  selected: {o.fulfillmentType},
                  onSelectionChanged: (s) {
                    Haptics.tap();
                    controller.update((x) => x.copyWith(fulfillmentType: s.first, pickupSlotId: () => null));
                  },
                ),
                const Gap(AppSpace.md),
                if (o.fulfillmentType == FulfillmentType.delivery)
                  _AddressSection(
                    addressId: o.addressId,
                    onChanged: (id) => controller.update((x) => x.copyWith(addressId: () => id)),
                  )
                else
                  _PickupSection(options: o, onChanged: controller.update),
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
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: o.allowSplit,
                  onChanged: (v) => controller.update((x) => x.copyWith(allowSplit: v)),
                  title: Text(l10n.allowSplit),
                  subtitle: Text(l10n.allowSplitHint),
                ),
                const Gap(AppSpace.sm),
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
      final id = await context.push<String>('/addresses?pick=1');
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

class _PickupSection extends ConsumerStatefulWidget {
  const _PickupSection({required this.options, required this.onChanged});
  final CheckoutOptions options;
  final Future<void> Function(CheckoutOptions Function(CheckoutOptions)) onChanged;

  @override
  ConsumerState<_PickupSection> createState() => _PickupSectionState();
}

class _PickupSectionState extends ConsumerState<_PickupSection> {
  late DateTime _day = DateTime.tryParse(widget.options.pickupDate ?? '') ?? QatarTime.today();

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final locale = context.localeCode;
    final branches = ref.watch(pickupBranchesProvider);
    final branchId = widget.options.branchId;
    final branch = branches.value?.where((b) => b.id == branchId).firstOrNull;
    final today = QatarTime.today();
    final days = [for (var i = 0; i < 7; i++) today.add(Duration(days: i))];
    final date = QatarTime.isoDate(_day);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        SurfaceCard(
          onTap: () async {
            final list = branches.value ?? const <Branch>[];
            final chosen = await showAppBottomSheet<Branch>(
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
            if (chosen != null) {
              await widget.onChanged((o) => o.copyWith(branchId: () => chosen.id, pickupSlotId: () => null));
            }
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
          Text(l10n.pickupSlot, style: context.text.titleSmall),
          const Gap(AppSpace.xs),
          SizedBox(
            height: 64,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: days.length,
              separatorBuilder: (_, _) => const Gap(AppSpace.xs),
              itemBuilder: (_, i) {
                final d = days[i];
                final selected = QatarTime.isoDate(d) == date;
                return ChoiceChip(
                  selected: selected,
                  label: Text(QatarTime.weekdayDate(d.subtract(QatarTime.offset), locale)),
                  onSelected: (_) {
                    setState(() => _day = d);
                    widget.onChanged(
                      (o) => o.copyWith(pickupDate: () => QatarTime.isoDate(d), pickupSlotId: () => null),
                    );
                  },
                );
              },
            ),
          ),
          ref
              .watch(pickupSlotsProvider((branchId: branchId, date: date)))
              .when(
                loading: () => const Skeleton(height: 48),
                error: (e, _) => ErrorState(error: e, compact: true),
                data: (slots) => slots.isEmpty
                    ? Padding(
                        padding: const EdgeInsets.all(AppSpace.sm),
                        child: Text(l10n.noSlots, style: context.text.bodySmall),
                      )
                    : Wrap(
                        spacing: AppSpace.xs,
                        runSpacing: AppSpace.xs,
                        children: [
                          for (final s in slots)
                            ChoiceChip(
                              selected: widget.options.pickupSlotId == s.id,
                              label: Text(
                                '${QatarTime.clock(s.startsAt, locale)}–${QatarTime.clock(s.endsAt, locale)}'
                                '${s.isFull ? ' · ${l10n.slotFull}' : (s.available <= 3 ? ' · ${l10n.slotRemaining(s.available)}' : '')}',
                              ),
                              onSelected: s.isFull
                                  ? null
                                  : (_) => widget.onChanged(
                                      (o) => o.copyWith(
                                        pickupSlotId: () => s.id,
                                        pickupDate: () => s.date.isEmpty ? date : s.date,
                                      ),
                                    ),
                            ),
                        ],
                      ),
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

    return Card(
      child: RadioGroup<PaymentMethod>(
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
                RadioListTile<PaymentMethod>(
                  value: opt.method!,
                  enabled: opt.available,
                  secondary: Icon(icon(opt.method!)),
                  title: Text(label(opt.method!)),
                  subtitle: opt.available ? null : Text(reason(opt.reason), style: context.text.bodySmall),
                ),
          ],
        ),
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
            for (final e in quote.errors)
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
    final hint = switch (missing) {
      'address' => l10n.selectAddressFirst,
      'branch' => l10n.selectBranchFirst,
      'payment' => l10n.selectPaymentFirst,
      _ => (state.quote != null && !state.quote!.canPlaceOrder) ? l10n.cannotPlaceOrder : null,
    };
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
                      textAlign: TextAlign.center,
                      style: context.text.bodySmall?.copyWith(color: context.palette.warning),
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
                      child: AppButton(
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
