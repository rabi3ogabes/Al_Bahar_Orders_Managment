import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/app_text_field.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/checkout/data/checkout_repository.dart';
import 'package:albahar_customer/features/checkout/presentation/payment_flow.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:share_plus/share_plus.dart';
import 'package:url_launcher/url_launcher.dart';

class OrderDetailScreen extends ConsumerWidget {
  const OrderDetailScreen({super.key, required this.orderId, this.justPlaced = false});
  final String orderId;
  final bool justPlaced;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final order = ref.watch(orderDetailProvider(orderId));
    return Scaffold(
      appBar: AppBar(
        title: Text(order.value != null ? l10n.orderNumber(order.value!.number) : l10n.ordersTitle),
        leading: justPlaced
            ? IconButton(
                tooltip: l10n.close,
                icon: const Icon(Icons.close_rounded),
                onPressed: () => context.go('/home'),
              )
            : null,
      ),
      body: order.when(
        skipLoadingOnRefresh: true,
        loading: () => const ListSkeleton(count: 4, leading: false),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(orderDetailProvider(orderId))),
        data: (o) => RefreshIndicator(
          onRefresh: () async {
            ref.invalidate(orderTimelineProvider(orderId));
            ref.invalidate(orderDetailProvider(orderId));
            await ref.read(orderDetailProvider(orderId).future);
          },
          child: ListView(
            padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.xxl),
            children: [
              ContentWidth(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (justPlaced) _PlacedBanner(number: o.number),
                    _Header(order: o),
                    const Gap(AppSpace.lg),
                    for (final f in o.fulfillments) ...[
                      _FulfillmentCard(order: o, fulfillment: f),
                      const Gap(AppSpace.sm),
                    ],
                    const Gap(AppSpace.md),
                    _Timeline(orderId: o.id),
                    const Gap(AppSpace.lg),
                    if (o.fulfillments.isEmpty) ...[
                      Text(l10n.orderItems, style: context.text.titleLarge),
                      for (final i in o.items) _ItemRow(item: i),
                    ],
                    if (o.address != null) ...[_AddressBlock(address: o.address!), const Gap(AppSpace.lg)],
                    if (o.totals != null) _Totals(order: o),
                    const Gap(AppSpace.lg),
                    _Actions(order: o),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _PlacedBanner extends StatelessWidget {
  const _PlacedBanner({required this.number});
  final String number;

  @override
  Widget build(BuildContext context) => TweenAnimationBuilder<double>(
    tween: Tween(begin: 0.92, end: 1),
    duration: AppDurations.slow,
    curve: Curves.easeOutBack,
    builder: (_, v, child) => Transform.scale(scale: v, child: child),
    child: Container(
      margin: const EdgeInsets.only(bottom: AppSpace.lg),
      padding: const EdgeInsets.symmetric(horizontal: AppSpace.lg, vertical: AppSpace.xl),
      decoration: BoxDecoration(color: context.palette.brassTint, borderRadius: AppRadius.cardAll),
      child: Column(
        children: [
          // Check in a navy disc with two soft halo rings.
          Container(
            width: 112,
            height: 112,
            alignment: Alignment.center,
            decoration: BoxDecoration(shape: BoxShape.circle, color: AppPalette.navy.withValues(alpha: 0.06)),
            child: Container(
              width: 82,
              height: 82,
              alignment: Alignment.center,
              decoration: BoxDecoration(shape: BoxShape.circle, color: AppPalette.navy.withValues(alpha: 0.09)),
              child: Container(
                width: 56,
                height: 56,
                decoration: const BoxDecoration(shape: BoxShape.circle, color: AppPalette.navy),
                child: const Icon(Icons.check_rounded, size: 32, color: Colors.white),
              ),
            ),
          ),
          const Gap(AppSpace.sm),
          Text(context.l10n.orderPlaced, style: context.text.headlineMedium),
          const Gap(AppSpace.xxs),
          Text(
            context.l10n.orderPlacedMessage(number),
            textAlign: TextAlign.center,
            style: context.text.bodyMedium?.copyWith(color: context.palette.muted),
          ),
        ],
      ),
    ),
  );
}

class _Header extends StatelessWidget {
  const _Header({required this.order});
  final Order order;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            StatusChip(label: order.statusLabel ?? order.status, tone: toneForStatus(order.status)),
            const Gap(AppSpace.xs),
            if (order.paymentStatus != null)
              StatusChip(
                label: _paymentLabel(l10n, order.paymentStatus!),
                tone: toneForStatus(order.paymentStatus),
                dense: true,
              ),
          ],
        ),
        const Gap(AppSpace.xs),
        Text(l10n.placedOn(QatarTime.dateTime(order.placedAt, context.localeCode)), style: context.text.bodySmall),
      ],
    );
  }
}

String _paymentLabel(AppLocalizations l10n, String status) => switch (status) {
  'paid' || 'authorized' => l10n.paymentStatusPaid,
  'failed' || 'expired' || 'cancelled' => l10n.paymentStatusFailed,
  'refunded' || 'partially_refunded' => l10n.paymentStatusRefunded,
  _ => l10n.paymentStatusPending,
};

class _FulfillmentCard extends StatelessWidget {
  const _FulfillmentCard({required this.order, required this.fulfillment});
  final Order order;
  final Fulfillment fulfillment;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final f = fulfillment;
    final locale = context.localeCode;
    final items = order.itemsFor(f);
    final shown = items.isEmpty && order.fulfillments.length == 1 ? order.items : items;
    final slot = f.slot;
    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Row(
            children: [
              Icon(
                f.type == 'pickup' ? Icons.storefront_outlined : Icons.local_shipping_outlined,
                color: context.palette.brass,
              ),
              const Gap(AppSpace.sm),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(l10n.shipment(f.number), style: context.text.titleSmall),
                    Text(l10n.fulfilledFrom(f.branch.name), style: context.text.bodySmall),
                  ],
                ),
              ),
              StatusChip(label: f.statusLabel ?? f.status, tone: toneForStatus(f.status), dense: true),
            ],
          ),
          if (slot != null && slot['date'] != null)
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xs),
              child: Text(
                f.type == 'delivery'
                    ? l10n.deliverySlotSummary(
                        QatarTime.slotDay(asString(slot['date']), locale),
                        QatarTime.clock(asStringOrNull(slot['starts_at']), locale),
                        QatarTime.clock(asStringOrNull(slot['ends_at']), locale),
                      )
                    : l10n.slotLabel(
                        QatarTime.slotDay(asString(slot['date']), locale),
                        QatarTime.clock(asStringOrNull(slot['starts_at']), locale),
                        QatarTime.clock(asStringOrNull(slot['ends_at']), locale),
                      ),
                style: context.text.bodySmall,
              ),
            ),
          for (final i in shown) _ItemRow(item: i),
          if (f.delivery != null) _DeliveryTracking(info: f.delivery!),
          if (f.isPickupReady) ...[
            const Gap(AppSpace.sm),
            AppButton.accent(
              label: l10n.pickupPass,
              icon: Icons.qr_code_2_rounded,
              onPressed: () => context.push('/orders/${order.id}/pickup/${f.id}'),
            ),
          ],
          if (f.type == 'pickup' && f.branch.latitude != null)
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                icon: const Icon(Icons.directions_outlined, size: 18),
                label: Text(l10n.directions),
                onPressed: () => launchUrl(
                  Uri.parse(
                    'https://www.google.com/maps/dir/?api=1&destination=${f.branch.latitude},${f.branch.longitude}',
                  ),
                  mode: LaunchMode.externalApplication,
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _DeliveryTracking extends StatelessWidget {
  const _DeliveryTracking({required this.info});
  final DeliveryInfo info;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final locale = context.localeCode;
    return Container(
      margin: const EdgeInsets.only(top: AppSpace.sm),
      padding: const EdgeInsets.all(AppSpace.sm),
      decoration: BoxDecoration(color: context.palette.surfaceMuted, borderRadius: BorderRadius.circular(12)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(l10n.trackDelivery, style: context.text.titleSmall),
          if (info.eta != null) SummaryRow(label: l10n.eta, value: QatarTime.dateTime(info.eta, locale)),
          if (info.provider != null) SummaryRow(label: l10n.deliveryProvider, value: info.provider!),
          if (info.trackingNumber != null) SummaryRow(label: l10n.trackingNumber, value: info.trackingNumber!),
          if (info.driverName != null)
            Row(
              children: [
                Expanded(
                  child: SummaryRow(label: l10n.driver, value: info.driverName!),
                ),
                if (info.driverPhone != null)
                  IconButton(
                    tooltip: l10n.call,
                    icon: const Icon(Icons.call_outlined),
                    onPressed: () => launchUrl(Uri(scheme: 'tel', path: info.driverPhone)),
                  ),
              ],
            ),
          if (info.trackingUrl != null)
            TextButton(
              onPressed: () => launchUrl(Uri.parse(info.trackingUrl!), mode: LaunchMode.inAppBrowserView),
              child: Text(l10n.trackDelivery),
            ),
        ],
      ),
    );
  }
}

class _ItemRow extends StatelessWidget {
  const _ItemRow({required this.item});
  final OrderItem item;

  @override
  Widget build(BuildContext context) => InkWell(
    onTap: item.productId == null ? null : () => context.push('/products/${item.productId}'),
    child: Padding(
      padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
      child: Row(
        children: [
          AppNetworkImage(url: item.imageUrl, width: 52, height: 60, borderRadius: BorderRadius.circular(8)),
          const Gap(AppSpace.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(item.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: context.text.bodyMedium),
                Text(
                  [if (item.variantName != null) item.variantName!, '× ${item.quantity}'].join(' · '),
                  style: context.text.bodySmall,
                ),
              ],
            ),
          ),
          if (item.lineTotal != null) Text(item.lineTotal!.formatted, style: context.text.titleSmall),
        ],
      ),
    ),
  );
}

class _Timeline extends ConsumerWidget {
  const _Timeline({required this.orderId});
  final String orderId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final timeline = ref.watch(orderTimelineProvider(orderId));
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(l10n.orderTimeline, style: context.text.titleLarge),
        const Gap(AppSpace.sm),
        timeline.when(
          loading: () => const Skeleton(height: 120, radius: AppRadius.card),
          error: (e, _) =>
              ErrorState(error: e, compact: true, onRetry: () => ref.invalidate(orderTimelineProvider(orderId))),
          data: (entries) => Column(
            children: [
              for (var i = 0; i < entries.length; i++)
                _TimelineRow(entry: entries[i], first: i == 0, last: i == entries.length - 1),
            ],
          ),
        ),
      ],
    );
  }
}

/// One step of the vertical tracker: done steps are filled navy with a tick, the current (last) step carries a
/// soft halo and a tinted row, a failure/cancel step is red.
class _TimelineRow extends StatelessWidget {
  const _TimelineRow({required this.entry, required this.first, required this.last});
  final TimelineEntry entry;
  final bool first;
  final bool last;

  @override
  Widget build(BuildContext context) {
    final tone = toneForStatus(entry.status);
    final failed = tone == StatusTone.danger;
    final fill = failed ? context.palette.danger : context.colors.primary;
    final onFill = failed ? Colors.white : context.colors.onPrimary;
    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SizedBox(
            width: 32,
            child: Column(
              children: [
                Expanded(child: Container(width: 2, color: first ? Colors.transparent : fill)),
                Container(
                  width: 24,
                  height: 24,
                  decoration: BoxDecoration(
                    color: fill,
                    shape: BoxShape.circle,
                    boxShadow: last ? [BoxShadow(color: fill.withValues(alpha: 0.18), spreadRadius: 5)] : null,
                  ),
                  child: Icon(failed ? Icons.close_rounded : Icons.check_rounded, size: 14, color: onFill),
                ),
                Expanded(child: Container(width: 2, color: last ? Colors.transparent : fill)),
              ],
            ),
          ),
          const Gap(AppSpace.xs),
          Expanded(
            child: Container(
              margin: const EdgeInsets.symmetric(vertical: 2),
              padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
              decoration: BoxDecoration(
                color: last ? AppPalette.tint : Colors.transparent,
                borderRadius: AppRadius.imageAll,
              ),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(entry.label, style: context.text.titleSmall),
                  Text(QatarTime.dateTime(entry.at, context.localeCode), style: context.text.bodySmall),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _AddressBlock extends StatelessWidget {
  const _AddressBlock({required this.address});
  final JsonMap address;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final parts = [
      if (address['zone_number'] != null) '${l10n.zoneNumber} ${address['zone_number']}',
      if (address['street_number'] != null) '${l10n.streetNumber} ${address['street_number']}',
      if (address['building_number'] != null) '${l10n.buildingNumber} ${address['building_number']}',
      if (address['unit_number'] != null) '${l10n.unitNumber} ${address['unit_number']}',
    ].join(', ');
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(l10n.shippingTo, style: context.text.titleLarge),
        const Gap(AppSpace.xs),
        Text(asString(address['recipient_name']), style: context.text.titleSmall),
        if (parts.isNotEmpty) Text(parts, style: context.text.bodyMedium),
        if (address['area'] != null) Text(asString(address['area']), style: context.text.bodySmall),
      ],
    );
  }
}

class _Totals extends StatelessWidget {
  const _Totals({required this.order});
  final Order order;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final t = order.totals!;
    Widget? row(String label, Money? m, {bool negative = false}) =>
        (m == null || m.isZero) ? null : SummaryRow(label: label, value: negative ? '− ${m.formatted}' : m.formatted);
    return SurfaceCard(
      child: Column(
        children: [
          SummaryRow(label: l10n.subtotal, value: t.subtotal.formatted),
          ?row(l10n.discount, t.discount, negative: true),
          ?row(l10n.deliveryFee, t.deliveryFee),
          ?row(l10n.serviceFee, t.serviceFee),
          ?row(l10n.codFee, t.codFee),
          ?row(l10n.tax, t.tax),
          ?row(l10n.loyaltyDiscount, t.loyaltyDiscount, negative: true),
          Divider(color: context.palette.line),
          SummaryRow(label: l10n.grandTotal, value: t.grandTotal.formatted, emphasize: true),
        ],
      ),
    );
  }
}

class _Actions extends ConsumerStatefulWidget {
  const _Actions({required this.order});
  final Order order;

  @override
  ConsumerState<_Actions> createState() => _ActionsState();
}

class _ActionsState extends ConsumerState<_Actions> {
  String? _busy;
  String? _payKey;

  Future<void> _run(String key, Future<void> Function() action) async {
    setState(() => _busy = key);
    try {
      await action();
    } catch (e) {
      if (mounted) showErrorSnack(context, e);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  void _refresh() {
    ref.invalidate(orderDetailProvider(widget.order.id));
    ref.invalidate(orderTimelineProvider(widget.order.id));
  }

  Future<void> _cancel() async {
    final l10n = context.l10n;
    final result = await showAppBottomSheet<({String code, String? reason})>(
      context: context,
      title: l10n.cancelOrderTitle,
      builder: (_) => const _CancelSheet(),
    );
    if (result == null) return;
    await _run('cancel', () async {
      final status = await ref
          .read(orderRepositoryProvider)
          .cancel(widget.order.id, reasonCode: result.code, reason: result.reason);
      if (!mounted) return;
      Haptics.success();
      showInfoSnack(context, status == 'approved' ? l10n.cancelApproved : l10n.cancelRequested);
      _refresh();
    });
  }

  Future<void> _reorder() => _run('reorder', () async {
    final l10n = context.l10n;
    final result = await ref.read(orderRepositoryProvider).reorder(widget.order.id);
    ref.read(cartControllerProvider.notifier).replace(result.cart);
    if (!mounted) return;
    Haptics.success();
    final msg = result.skipped.isEmpty
        ? l10n.reorderDone
        : '${l10n.reorderDone} ${l10n.reorderSkipped(result.skipped.length)}';
    showInfoSnack(
      context,
      msg,
      action: SnackBarAction(label: l10n.viewCart, onPressed: () => context.go('/cart')),
    );
  });

  Future<void> _invoice() => _run('invoice', () async {
    final bytes = await ref.read(orderRepositoryProvider).invoice(widget.order.id);
    final name = 'AlBahar-${widget.order.number}.pdf';
    await SharePlus.instance.share(
      ShareParams(
        files: [XFile.fromData(bytes, mimeType: 'application/pdf', name: name)],
        fileNameOverrides: [name],
      ),
    );
  });

  Future<void> _pay() => _run('pay', () async {
    _payKey ??= Idempotency.newKey();
    final action = await ref.read(checkoutRepositoryProvider).retryPayment(widget.order.id, idempotencyKey: _payKey!);
    _payKey = null;
    if (action.requiresRedirect) await openSecurePayment(action);
    if (!mounted) return;
    unawaited(
      context.push('/payments/${action.paymentId}/return?order_id=${widget.order.id}&number=${widget.order.number}'),
    );
  });

  Future<void> _return() async {
    final l10n = context.l10n;
    final result = await showAppBottomSheet<({List<({String orderItemId, int quantity})> items, String code})>(
      context: context,
      title: l10n.requestReturn,
      builder: (_) => _ReturnSheet(order: widget.order),
    );
    if (result == null || result.items.isEmpty) return;
    await _run('return', () async {
      await ref
          .read(orderRepositoryProvider)
          .requestReturn(widget.order.id, items: result.items, reasonCode: result.code);
      if (!mounted) return;
      showInfoSnack(context, l10n.returnRequested);
      _refresh();
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final o = widget.order;
    final firstProduct = o.items.where((i) => i.productId != null).firstOrNull;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (o.canPay) ...[
          AppButton.accent(
            label: l10n.payNow,
            icon: Icons.credit_card_rounded,
            loading: _busy == 'pay',
            onPressed: _pay,
          ),
          const Gap(AppSpace.sm),
        ],
        AppButton.secondary(
          label: l10n.reorder,
          icon: Icons.replay_rounded,
          loading: _busy == 'reorder',
          onPressed: _reorder,
        ),
        const Gap(AppSpace.sm),
        AppButton.secondary(
          label: l10n.downloadInvoice,
          icon: Icons.picture_as_pdf_outlined,
          loading: _busy == 'invoice',
          onPressed: _invoice,
        ),
        if (o.canReturn) ...[
          const Gap(AppSpace.sm),
          AppButton.secondary(
            label: l10n.requestReturn,
            icon: Icons.assignment_return_outlined,
            loading: _busy == 'return',
            onPressed: _return,
          ),
        ],
        if (o.canReview && firstProduct != null) ...[
          const Gap(AppSpace.sm),
          AppButton.secondary(
            label: l10n.writeReview,
            icon: Icons.rate_review_outlined,
            onPressed: () => context.push(
              '/products/${firstProduct.productId}/reviews/new?name=${Uri.encodeComponent(firstProduct.name)}',
            ),
          ),
        ],
        const Gap(AppSpace.sm),
        AppButton.ghost(
          label: l10n.contactSupport,
          icon: Icons.support_agent_outlined,
          expand: true,
          onPressed: () => context.push('/support/new?order_id=${o.id}'),
        ),
        if (o.canCancel) ...[
          const Gap(AppSpace.sm),
          TextButton(
            style: TextButton.styleFrom(foregroundColor: context.palette.danger),
            onPressed: _busy == null ? _cancel : null,
            child: Text(l10n.cancelOrder),
          ),
        ],
      ],
    );
  }
}

class _CancelSheet extends StatefulWidget {
  const _CancelSheet();
  @override
  State<_CancelSheet> createState() => _CancelSheetState();
}

class _CancelSheetState extends State<_CancelSheet> {
  String _code = cancelReasonCodes.first;
  final _details = TextEditingController();

  @override
  void dispose() {
    _details.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    String label(String code) => switch (code) {
      'changed_mind' => l10n.cancelReasonChangedMind,
      'ordered_by_mistake' => l10n.cancelReasonMistake,
      'delivery_too_long' => l10n.cancelReasonDelay,
      'found_better_price' => l10n.cancelReasonPrice,
      _ => l10n.cancelReasonOther,
    };
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        RadioGroup<String>(
          groupValue: _code,
          onChanged: (v) => setState(() => _code = v ?? _code),
          child: Column(
            children: [
              for (final c in cancelReasonCodes)
                RadioListTile<String>(value: c, title: Text(label(c)), contentPadding: EdgeInsets.zero),
            ],
          ),
        ),
        AppTextField(controller: _details, label: l10n.cancelDetails, maxLines: 3, minLines: 1),
        const Gap(AppSpace.lg),
        AppButton(
          label: l10n.cancelOrder,
          variant: AppButtonVariant.destructive,
          onPressed: () => Navigator.of(context).pop((code: _code, reason: _details.text.trim())),
        ),
        const Gap(AppSpace.xs),
        AppButton.ghost(label: l10n.keepOrder, expand: true, onPressed: () => Navigator.of(context).pop()),
      ],
    );
  }
}

class _ReturnSheet extends StatefulWidget {
  const _ReturnSheet({required this.order});
  final Order order;
  @override
  State<_ReturnSheet> createState() => _ReturnSheetState();
}

class _ReturnSheetState extends State<_ReturnSheet> {
  final _selected = <String, int>{};
  String _code = returnReasonCodes.first;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    String label(String c) => switch (c) {
      'damaged' => l10n.returnReasonDamaged,
      'wrong_item' => l10n.returnReasonWrong,
      'not_as_described' => l10n.returnReasonNotAsDescribed,
      'changed_mind' => l10n.cancelReasonChangedMind,
      _ => l10n.cancelReasonOther,
    };
    return Column(
      mainAxisSize: MainAxisSize.min,
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(l10n.selectItemsToReturn, style: context.text.titleSmall),
        for (final i in widget.order.items)
          CheckboxListTile(
            contentPadding: EdgeInsets.zero,
            value: _selected.containsKey(i.id),
            onChanged: (v) => setState(() => v == true ? _selected[i.id] = i.quantity : _selected.remove(i.id)),
            title: Text(i.name, maxLines: 1, overflow: TextOverflow.ellipsis),
            subtitle: Text('× ${i.quantity}'),
          ),
        const Gap(AppSpace.sm),
        DropdownButtonFormField<String>(
          initialValue: _code,
          decoration: InputDecoration(labelText: l10n.cancelReason),
          items: [for (final c in returnReasonCodes) DropdownMenuItem(value: c, child: Text(label(c)))],
          onChanged: (v) => setState(() => _code = v ?? _code),
        ),
        const Gap(AppSpace.lg),
        AppButton(
          label: l10n.requestReturn,
          onPressed: _selected.isEmpty
              ? null
              : () => Navigator.of(context).pop((
                  items: [for (final e in _selected.entries) (orderItemId: e.key, quantity: e.value)],
                  code: _code,
                )),
        ),
      ],
    );
  }
}
