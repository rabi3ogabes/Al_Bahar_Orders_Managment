import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/auctions/presentation/lot_widgets.dart';
import 'package:albahar_customer/features/branches/presentation/branches_screen.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_flow.dart';
import 'package:albahar_customer/features/profile/presentation/addresses_screen.dart';
import 'package:albahar_customer/features/profile/presentation/state/profile_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Checkout for a won lot: fulfilment + payment. Totals are computed by the
/// server and shown on the resulting order.
class WinCheckoutScreen extends ConsumerStatefulWidget {
  const WinCheckoutScreen({super.key, required this.winId, this.extra});
  final String winId;
  final Object? extra;

  @override
  ConsumerState<WinCheckoutScreen> createState() => _WinCheckoutScreenState();
}

class _WinCheckoutScreenState extends ConsumerState<WinCheckoutScreen> {
  FulfillmentType _type = FulfillmentType.delivery;
  PaymentMethod _method = PaymentMethod.sadad;
  String? _addressId;
  bool _busy = false;
  final String _key = Idempotency.newKey();

  Future<void> _submit() async {
    final l10n = context.l10n;
    final branchId = ref.read(preferredBranchIdProvider);
    if (_type == FulfillmentType.delivery && _addressId == null) {
      showInfoSnack(context, l10n.selectAddressFirst);
      return;
    }
    if (_type == FulfillmentType.pickup && branchId == null) {
      showInfoSnack(context, l10n.selectBranchFirst);
      return;
    }
    setState(() => _busy = true);
    try {
      final placed = await ref
          .read(auctionRepositoryProvider)
          .checkoutWin(
            widget.winId,
            fulfillmentType: _type,
            addressId: _type == FulfillmentType.delivery ? _addressId : null,
            branchId: _type == FulfillmentType.pickup ? branchId : null,
            paymentMethod: _method,
            idempotencyKey: _key,
          );
      Haptics.success();
      if (mounted) await continueAfterOrder(context, ref, placed);
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final win = widget.extra is AuctionWin ? widget.extra! as AuctionWin : null;
    final addresses = ref.watch(addressesProvider).value ?? const [];
    _addressId ??= addresses.where((a) => a.isDefault).firstOrNull?.id ?? addresses.firstOrNull?.id;
    final branch = ref.watch(preferredBranchProvider);
    final methods = _type == FulfillmentType.pickup
        ? const [PaymentMethod.sadad, PaymentMethod.payAtPickup]
        : const [PaymentMethod.sadad, PaymentMethod.cod];
    if (!methods.contains(_method)) _method = PaymentMethod.sadad;

    return Scaffold(
      appBar: AppBar(title: Text(l10n.winCheckoutTitle)),
      body: ListView(
        padding: const EdgeInsets.all(AppSpace.gutter),
        children: [
          ContentWidth(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (win != null) ...[
                  LotCard(lot: win.lot.copyWith(myStatus: 'won')),
                  const Gap(AppSpace.sm),
                  SummaryRow(label: l10n.winningBid, value: win.amount.formatted, emphasize: true),
                  const Gap(AppSpace.lg),
                ],
                Text(l10n.fulfillmentMethod, style: context.text.titleLarge),
                const Gap(AppSpace.sm),
                SegmentedButton<FulfillmentType>(
                  segments: [
                    ButtonSegment(value: FulfillmentType.delivery, label: Text(l10n.delivery)),
                    ButtonSegment(value: FulfillmentType.pickup, label: Text(l10n.pickup)),
                  ],
                  selected: {_type},
                  onSelectionChanged: (s) => setState(() => _type = s.first),
                ),
                const Gap(AppSpace.md),
                if (_type == FulfillmentType.delivery)
                  SurfaceCard(
                    onTap: () async {
                      final id = await showAddressPicker(context);
                      if (id != null && mounted) setState(() => _addressId = id);
                    },
                    child: Row(
                      children: [
                        const Icon(Icons.location_on_outlined),
                        const Gap(AppSpace.sm),
                        Expanded(
                          child: Text(
                            addresses.where((a) => a.id == _addressId).firstOrNull?.label ??
                                addresses.where((a) => a.id == _addressId).firstOrNull?.recipientName ??
                                l10n.selectAddressFirst,
                          ),
                        ),
                        Text(l10n.change, style: context.text.labelLarge),
                      ],
                    ),
                  )
                else
                  SurfaceCard(
                    onTap: () => unawaited(showBranchPicker(context)),
                    child: Row(
                      children: [
                        const Icon(Icons.storefront_outlined),
                        const Gap(AppSpace.sm),
                        Expanded(child: Text(branch?.name ?? l10n.selectBranchFirst)),
                        Text(l10n.change, style: context.text.labelLarge),
                      ],
                    ),
                  ),
                const Gap(AppSpace.lg),
                Text(l10n.paymentMethod, style: context.text.titleLarge),
                RadioGroup<PaymentMethod>(
                  groupValue: _method,
                  onChanged: (m) => setState(() => _method = m ?? _method),
                  child: Column(
                    children: [
                      for (final m in methods)
                        RadioListTile<PaymentMethod>(
                          value: m,
                          contentPadding: EdgeInsets.zero,
                          title: Text(switch (m) {
                            PaymentMethod.sadad => l10n.paySadad,
                            PaymentMethod.cod => l10n.payCod,
                            PaymentMethod.payAtPickup => l10n.payAtPickup,
                          }),
                        ),
                    ],
                  ),
                ),
                const Gap(AppSpace.xl),
                AppButton(label: l10n.placeOrder, loading: _busy, onPressed: _submit),
                const Gap(AppSpace.xs),
                Text(l10n.totalsCalculatedAtCheckout, style: context.text.bodySmall, textAlign: TextAlign.center),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
