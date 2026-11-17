import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_line_text.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

/// Everything an order shows: status, shipments with their items, the live timeline, address and totals, then
/// [actions]. One body for the full page (`/orders/:id`, deep links) and for the side window opened from lists.
class OrderSections extends StatelessWidget {
  const OrderSections({super.key, required this.order, required this.actions, this.justPlaced = false});
  final Order order;
  final Widget actions;
  final bool justPlaced;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final o = order;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (justPlaced) _PlacedBanner(number: o.number),
        _Header(order: o),
        const Gap(AppSpace.lg),
        // The branch changed what is in the order: say so first, in calm words, with what changed and the new total.
        if (o.wasAdjusted) ...[OrderUpdatedBanner(order: o), const Gap(AppSpace.xs)],
        for (final f in o.fulfillments) ...[_FulfillmentCard(order: o, fulfillment: f), const Gap(AppSpace.sm)],
        const Gap(AppSpace.md),
        _Timeline(orderId: o.id),
        const Gap(AppSpace.lg),
        if (o.fulfillments.isEmpty) ...[
          Text(l10n.orderItems, style: context.text.titleLarge),
          for (final i in o.items) _ItemRow(item: i, order: o),
        ],
        if (o.address != null) ...[_AddressBlock(address: o.address!), const Gap(AppSpace.lg)],
        if (o.totals != null) _Totals(order: o),
        const Gap(AppSpace.lg),
        actions,
      ],
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
            decoration: BoxDecoration(shape: BoxShape.circle, color: context.colors.primary.withValues(alpha: 0.06)),
            child: Container(
              width: 82,
              height: 82,
              alignment: Alignment.center,
              decoration: BoxDecoration(shape: BoxShape.circle, color: context.colors.primary.withValues(alpha: 0.09)),
              child: Container(
                width: 56,
                height: 56,
                decoration: BoxDecoration(shape: BoxShape.circle, color: context.colors.primary),
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
        Wrap(
          spacing: AppSpace.xs,
          runSpacing: AppSpace.xxs,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            StatusChip(label: order.statusLabel ?? order.status, tone: toneForStatus(order.status)),
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
              const Gap(AppSpace.xs),
              Flexible(
                child: StatusChip(label: f.statusLabel ?? f.status, tone: toneForStatus(f.status), dense: true),
              ),
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
          for (final i in shown) _ItemRow(item: i, order: order),
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
  const _ItemRow({required this.item, required this.order});
  final OrderItem item;
  final Order order;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final gone = item.isGone;
    final a = item.adjustment;
    // What the branch did to this line: a removed or swapped line stays in the list, struck through, with nothing to pay.
    final replacedBy = gone ? order.replacedByFor(item) : null;
    final note = !gone
        ? null
        : (replacedBy != null ? l10n.orderItemReplacedBy(replacedBy) : l10n.orderItemNotAvailable);
    return InkWell(
      onTap: item.productId == null ? null : () => context.push('/products/${item.productId}'),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: AppSpace.xs),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Opacity(
              opacity: gone ? 0.45 : 1,
              child: AppNetworkImage(url: item.imageUrl, width: 52, height: 60, borderRadius: BorderRadius.circular(8)),
            ),
            const Gap(AppSpace.sm),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    item.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: context.text.bodyMedium?.copyWith(
                      decoration: gone ? TextDecoration.lineThrough : null,
                      color: gone ? p.muted : null,
                    ),
                  ),
                  if (note != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        note,
                        style: context.text.bodySmall?.copyWith(
                          color: replacedBy != null ? p.info : p.warning,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    )
                  else
                    Text(
                      [if (item.variantName != null) item.variantName!, '× ${item.quantity}'].join(' · '),
                      style: context.text.bodySmall,
                    ),
                  // The deal the line was sold under: how many units got the deal price and what each saved.
                  if (item.deal != null && !gone)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        dealOrderLineText(l10n, item.deal!, item.quantity),
                        style: context.text.bodySmall?.copyWith(color: p.brassDeep, fontWeight: FontWeight.w600),
                      ),
                    ),
                  if (item.isReduced && a?.originalQuantity != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(
                        l10n.orderItemReducedFrom(a!.originalQuantity!),
                        style: context.text.bodySmall?.copyWith(color: p.warning, fontWeight: FontWeight.w600),
                      ),
                    ),
                  if (item.isReplacement)
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpace.xxs),
                      child: Wrap(
                        spacing: AppSpace.xs,
                        runSpacing: 2,
                        crossAxisAlignment: WrapCrossAlignment.center,
                        children: [
                          StatusChip(label: l10n.orderItemReplacement, tone: StatusTone.info, dense: true),
                          if (a?.replacesName != null)
                            Text(l10n.orderItemReplaces(a!.replacesName!), style: context.text.bodySmall),
                        ],
                      ),
                    ),
                ],
              ),
            ),
            if (item.lineTotal != null && !gone) Text(item.lineTotal!.formatted, style: context.text.titleSmall),
          ],
        ),
      ),
    );
  }
}

/// "Your order was updated by the branch": why (in plain words, with the branch's note), what changed line by line, the new total and
/// a word that the invoice was updated too. One block per correction, newest first; the total is the order's own, from the server.
class OrderUpdatedBanner extends StatelessWidget {
  const OrderUpdatedBanner({super.key, required this.order});
  final Order order;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final revisions = order.revisions.reversed.toList();
    final total = order.totals?.grandTotal ?? revisions.firstOrNull?.totalAfter;
    final before = revisions.isEmpty ? null : revisions.last.totalBefore;
    final cancelled = revisions.any((r) => r.orderCancelled);
    return Semantics(
      container: true,
      child: Container(
        margin: const EdgeInsets.only(bottom: AppSpace.sm),
        padding: const EdgeInsets.all(AppSpace.md),
        decoration: BoxDecoration(
          color: p.tint,
          borderRadius: AppRadius.cardAll,
          border: Border.all(color: p.brass.withValues(alpha: 0.28)),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(shape: BoxShape.circle, color: p.brass.withValues(alpha: 0.16)),
                  child: Icon(Icons.edit_note_rounded, size: 22, color: p.brassDeep),
                ),
                const Gap(AppSpace.sm),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Semantics(
                      header: true,
                      child: Text(
                        l10n.orderUpdatedTitle,
                        style: context.text.titleSmall?.copyWith(fontWeight: FontWeight.w700),
                      ),
                    ),
                  ),
                ),
              ],
            ),
            if (revisions.isEmpty)
              ..._changesFromItems(context)
            else
              for (var n = 0; n < revisions.length; n++)
                _RevisionBlock(revision: revisions[n], showHeader: revisions.length > 1, first: n == 0),
            if (cancelled)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.sm),
                child: Text(
                  l10n.orderUpdatedCancelled,
                  style: context.text.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                ),
              ),
            if (total != null) ...[
              const Gap(AppSpace.sm),
              Divider(color: p.brass.withValues(alpha: 0.25)),
              const Gap(AppSpace.xxs),
              Row(
                children: [
                  Expanded(child: Text(l10n.orderUpdatedNewTotal, style: context.text.bodyMedium)),
                  Text(total.formatted, style: context.text.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
                ],
              ),
              if (before != null && before.amount != total.amount)
                Align(
                  alignment: AlignmentDirectional.centerEnd,
                  child: Text(
                    l10n.orderUpdatedBefore(before.formatted),
                    style: context.text.bodySmall?.copyWith(color: p.muted),
                  ),
                ),
            ],
            if (order.hasInvoice)
              Padding(
                padding: const EdgeInsets.only(top: AppSpace.sm),
                child: Row(
                  children: [
                    Icon(Icons.picture_as_pdf_outlined, size: 18, color: p.brassDeep),
                    const Gap(AppSpace.xs),
                    Expanded(child: Text(l10n.orderUpdatedInvoice, style: context.text.bodySmall)),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// An older server sends no revisions: what changed is read from the lines themselves.
  List<Widget> _changesFromItems(BuildContext context) {
    final l10n = context.l10n;
    final lines = <String>[
      for (final i in order.items)
        if (i.adjustment != null && i.adjustment!.kind != 'replacement')
          switch (i.adjustment!.kind) {
            'reduced' => l10n.orderUpdatedLineReduced(i.name, i.adjustment!.originalQuantity ?? i.quantity, i.quantity),
            'replaced' => switch (order.replacedByFor(i)) {
              final String r => l10n.orderUpdatedLineReplaced(i.name, r),
              _ => l10n.orderUpdatedLineReplacedPlain(i.name),
            },
            _ => l10n.orderUpdatedLineRemoved(i.name),
          },
    ];
    final code = order.items.map((i) => i.adjustment?.reasonCode).whereType<String>().firstOrNull;
    return [
      if (code != null)
        Padding(
          padding: const EdgeInsets.only(top: AppSpace.sm),
          child: Text(_reasonText(l10n, code, null), style: context.text.bodyMedium),
        ),
      if (lines.isNotEmpty) ..._changeList(context, [for (final t in lines) (Icons.swap_horiz_rounded, t)]),
    ];
  }
}

/// One correction inside the banner: its number and time (only when there are several), why, the branch's note, and each change.
class _RevisionBlock extends StatelessWidget {
  const _RevisionBlock({required this.revision, required this.showHeader, required this.first});
  final OrderRevision revision;
  final bool showHeader;
  final bool first;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final p = context.palette;
    final r = revision;
    final reason = r.reasonCode == null && r.reasonLabel == null
        ? null
        : _reasonText(l10n, r.reasonCode, r.reasonLabel);
    return Padding(
      padding: EdgeInsets.only(top: first ? AppSpace.sm : AppSpace.md),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          if (showHeader)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpace.xxs),
              child: Text(
                [
                  l10n.orderUpdatedRevision(r.number),
                  if (r.at != null) QatarTime.dateTime(r.at, context.localeCode),
                ].join(' · '),
                style: context.text.labelLarge?.copyWith(color: p.brassDeep),
              ),
            ),
          if (reason != null) Text(reason, style: context.text.bodyMedium),
          if (r.note != null)
            Padding(
              padding: const EdgeInsets.only(top: AppSpace.xxs),
              child: Text(
                l10n.orderUpdatedNote(r.note!),
                style: context.text.bodyMedium?.copyWith(fontStyle: FontStyle.italic, color: p.muted),
              ),
            ),
          if (r.lines.isNotEmpty)
            ..._changeList(context, [for (final l in r.lines) (_iconFor(l.action), _lineText(l10n, l))]),
        ],
      ),
    );
  }
}

IconData _iconFor(String action) => switch (action) {
  'reduce' => Icons.remove_circle_outline_rounded,
  'replace' => Icons.swap_horiz_rounded,
  _ => Icons.remove_shopping_cart_outlined,
};

/// The change as a sentence: the server's own words when it sent them (the same sentence as the notification and the invoice), else
/// one built here.
String _lineText(AppLocalizations l10n, OrderRevisionLine l) {
  if (l.text != null) return l.text!;
  return switch (l.action) {
    'reduce' => l10n.orderUpdatedLineReduced(l.fullName, l.fromQuantity, l.toQuantity),
    'replace' =>
      l.replacementFullName == null
          ? l10n.orderUpdatedLineReplacedPlain(l.fullName)
          : l10n.orderUpdatedLineReplaced(l.fullName, l.replacementFullName!),
    _ => l10n.orderUpdatedLineRemoved(l.fullName),
  };
}

/// "What changed" and one row per change: a small icon and the sentence.
List<Widget> _changeList(BuildContext context, List<(IconData, String)> lines) {
  final p = context.palette;
  return [
    Padding(
      padding: const EdgeInsets.only(top: AppSpace.sm, bottom: AppSpace.xxs),
      child: Text(context.l10n.orderUpdatedWhatChanged, style: context.text.labelLarge?.copyWith(color: p.muted)),
    ),
    for (final (icon, text) in lines)
      Padding(
        padding: const EdgeInsets.symmetric(vertical: 3),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Padding(
              padding: const EdgeInsets.only(top: 2),
              child: Icon(icon, size: 18, color: p.brassDeep),
            ),
            const Gap(AppSpace.xs),
            Expanded(child: Text(text, style: context.text.bodyMedium)),
          ],
        ),
      ),
  ];
}

/// The reason in plain words: ours for the reasons we know, the server's own label for any other.
String _reasonText(AppLocalizations l10n, String? code, String? label) => switch (code) {
  'out_of_stock' => l10n.orderUpdatedReasonOutOfStock,
  'not_found' => l10n.orderUpdatedReasonNotFound,
  'damaged' => l10n.orderUpdatedReasonDamaged,
  'quality' => l10n.orderUpdatedReasonQuality,
  'other' => l10n.orderUpdatedReasonOther,
  _ => label ?? l10n.orderUpdatedReasonOther,
};

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
    // The branch changed what is in the order: its own mark (a pencil on the brand's warm colour), not a tick.
    final changed = entry.kind == 'items_changed';
    final failed = !changed && tone == StatusTone.danger;
    final fill = failed ? context.palette.danger : (changed ? context.palette.brass : context.colors.primary);
    final onFill = failed ? Colors.white : (changed ? Colors.white : context.colors.onPrimary);
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
                  child: Icon(
                    failed ? Icons.close_rounded : (changed ? Icons.edit_rounded : Icons.check_rounded),
                    size: 14,
                    color: onFill,
                  ),
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
                color: last ? context.palette.tint : Colors.transparent,
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
    final hasDeal = t.dealSavings != null && !t.dealSavings!.isZero;
    Widget? row(String label, Money? m, {bool negative = false}) =>
        (m == null || m.isZero) ? null : SummaryRow(label: label, value: negative ? '− ${m.formatted}' : m.formatted);
    return SurfaceCard(
      child: Column(
        children: [
          SummaryRow(label: l10n.subtotal, value: t.subtotal.formatted),
          // With deals: one "Deal savings" row, then what else was taken off one by one: a promotion, a coupon and a free-delivery coupon.
          // The free-delivery one stays in the list on purpose: `totals.discount` counts the delivery fee it waived and the delivery line
          // below is the full fee, so leaving it out would make the rows add up to more than the total.
          if (hasDeal) ...[
            ?row(l10n.dealSavingsRow, t.dealSavings, negative: true),
            for (final d in order.discounts)
              if (!d.isDeal && !d.amount.isZero)
                SummaryRow(
                  label: d.code != null ? '${d.label} (${d.code})' : d.label,
                  value: '− ${d.amount.formatted}',
                ),
          ] else
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
