import 'dart:async';
import 'dart:math' as math;

import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:albahar_customer/ops/staff/staff_clock_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_groups.dart';
import 'package:albahar_customer/ops/staff/staff_panels.dart';
import 'package:albahar_customer/ops/staff/staff_pickup.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_rights.dart';
import 'package:albahar_customer/ops/staff/staff_scan.dart';
import 'package:albahar_customer/ops/staff/staff_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

export 'package:albahar_customer/ops/staff/staff_rights.dart';

/// Keeps a tab's page (and its scroll position) alive while another tab is open.
class StaffKeepAlive extends StatefulWidget {
  const StaffKeepAlive({super.key, required this.child});
  final Widget child;

  @override
  State<StaffKeepAlive> createState() => _StaffKeepAliveState();
}

class _StaffKeepAliveState extends State<StaffKeepAlive> with AutomaticKeepAliveClientMixin {
  @override
  bool get wantKeepAlive => true;

  @override
  Widget build(BuildContext context) {
    super.build(context);
    return widget.child;
  }
}

// ── To do ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/// The "To do" tab: new, preparing and ready orders in ONE list, grouped by stage under headers that stay in view, with a row of
/// filters (All, New, Preparing, Ready) that narrows it to one stage. A person who sees pickups has a Delivery | Pickup switch at the top:
/// the same groups and filters then list the branch's pickup orders.
class StaffTodoPage extends ConsumerWidget {
  const StaffTodoPage({super.key, required this.rights});
  final StaffRights rights;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final canPickup = ref.watch(staffPickupEnabledProvider);
    final pickup = canPickup && ref.watch(staffOrdersModeProvider) == StaffOrdersMode.pickup;
    final rows = ref.watch(pickup ? staffPickupViewProvider : staffTodoViewProvider);
    final filter = ref.watch(staffTodoFilterProvider);
    final total = rows.total(kTodoStages);
    final groups = groupsOf(rows, kTodoStages, only: filter);
    // When the last order is done there is nothing left to narrow: the next order that arrives must not be hidden by a stage chosen earlier.
    ref.listen(
      (pickup ? staffPickupViewProvider : staffTodoViewProvider).select((r) => r.ready && r.total(kTodoStages) == 0),
      (_, empty) {
        if (empty) ref.read(staffTodoFilterProvider.notifier).reset();
      },
    );
    final lanes = pickup ? const [kPickupLane] : kTodoStages;
    return StageList(
      rows: rows,
      groups: groups,
      rights: rights,
      pickup: pickup,
      top: canPickup || (rows.ready && total > 0)
          ? Column(
              children: [
                if (canPickup) _ModeSwitch(pickup: pickup, rights: rights),
                if (rows.ready && total > 0)
                  _FilterStrip(
                    rows: rows,
                    selected: filter,
                    pickup: pickup,
                    onAll: () => ref.read(staffTodoFilterProvider.notifier).reset(),
                    onStage: (s) => ref.read(staffTodoFilterProvider.notifier).toggle(s),
                  ),
              ],
            )
          : null,
      empty: total == 0
          ? (pickup
                ? EmptyState(
                    icon: Icons.storefront_rounded,
                    title: t.noPickupOrders,
                    message: t.noPickupOrdersHint,
                    compact: true,
                  )
                : EmptyState(
                    icon: Icons.task_alt_rounded,
                    title: t.allCaughtUp,
                    message: t.allCaughtUpHint,
                    compact: true,
                  ))
          : EmptyState(icon: Icons.filter_list_off_rounded, title: t.nothingInStep, compact: true),
      onMore: (stage) => ref.read(staffLaneDepthProvider(pickup ? kPickupLane : stage).notifier).more(),
      onRefresh: () async {
        ref.invalidate(staffOverviewProvider);
        ref.invalidate(staffAttentionProvider);
        for (final s in lanes) {
          ref.invalidate(staffQueueProvider(s));
        }
        await Future.wait([for (final s in lanes) ref.read(staffQueueProvider(s).future)]);
      },
      onRetry: () {
        for (final s in lanes) {
          ref.invalidate(staffQueueProvider(s));
        }
      },
    );
  }
}

/// Delivery | Pickup, each with its number, and (on the Pickup side) a big "Scan pass" for the counter. The choice is kept while the home screen is open.
class _ModeSwitch extends ConsumerWidget {
  const _ModeSwitch({required this.pickup, required this.rights});
  final bool pickup;
  final StaffRights rights;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final o = ref.watch(staffOverviewViewProvider).value;
    final deliveries = o == null ? null : o.fresh + o.preparing + o.ready;
    final pickups = o == null ? null : o.pickupNew + o.pickupPreparing + o.pickupReady;
    String label(String name, int? n) => n == null ? name : '$name · $n';
    final scheme = context.colors;
    final palette = context.palette;
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          SegmentedButton<StaffOrdersMode>(
            key: const ValueKey('orders-mode'),
            showSelectedIcon: false,
            expandedInsets: EdgeInsets.zero,
            style: SegmentedButton.styleFrom(
              minimumSize: const Size(0, kMinTouchTarget),
              backgroundColor: scheme.surface,
              foregroundColor: palette.ink,
              selectedBackgroundColor: scheme.primary,
              selectedForegroundColor: scheme.onPrimary,
              side: BorderSide(color: palette.line),
              tapTargetSize: MaterialTapTargetSize.padded,
            ),
            segments: [
              ButtonSegment(
                value: StaffOrdersMode.delivery,
                icon: const Icon(Icons.local_shipping_outlined),
                label: Semantics(
                  label: deliveries == null ? t.modeDelivery : t.modeLabel(t.modeDelivery, deliveries),
                  excludeSemantics: true,
                  child: Text(label(t.modeDelivery, deliveries), maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
              ),
              ButtonSegment(
                value: StaffOrdersMode.pickup,
                icon: const Icon(Icons.storefront_rounded),
                label: Semantics(
                  label: pickups == null ? t.modePickup : t.modeLabel(t.modePickup, pickups),
                  excludeSemantics: true,
                  child: Text(label(t.modePickup, pickups), maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
              ),
            ],
            selected: {pickup ? StaffOrdersMode.pickup : StaffOrdersMode.delivery},
            onSelectionChanged: (s) => ref.read(staffOrdersModeProvider.notifier).choose(s.first),
          ),
          if (pickup && rights.canPickupView) ...[
            const Gap(AppSpace.xs),
            FilledButton.tonalIcon(
              key: const ValueKey('pickup-scan-top'),
              onPressed: () => unawaited(showStaffScan(context)),
              icon: const Icon(Icons.qr_code_scanner_rounded, size: 20),
              label: Text(t.scanPass),
            ),
          ],
        ],
      ),
    );
  }
}

/// All · New · Preparing · Ready, each with its number: four equal buttons that never wrap or scroll. A tap narrows the list to that
/// stage, a second tap on it (or a tap on All) shows everything again.
class _FilterStrip extends StatelessWidget {
  const _FilterStrip({
    required this.rows,
    required this.selected,
    required this.onAll,
    required this.onStage,
    this.pickup = false,
  });
  final StageRows rows;
  final String? selected;
  final bool pickup;
  final VoidCallback onAll;
  final ValueChanged<String> onStage;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final total = rows.total(kTodoStages);
    // A stage with a next page has more than it shows: its number says "50+" rather than a count that is too low.
    final choices = <({String? stage, String label, int count, bool more})>[
      (stage: null, label: t.fleetFilterAll, count: total, more: kTodoStages.any(rows.more.contains)),
      for (final s in kTodoStages)
        (stage: s, label: _stageTitle(t, s, pickup: pickup), count: rows.count(s), more: rows.more.contains(s)),
    ];
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.xs),
      child: Row(
        children: [
          for (var i = 0; i < choices.length; i++) ...[
            if (i > 0) const Gap(6),
            Expanded(
              child: _FilterSegment(
                key: ValueKey('todo-filter-${choices[i].stage ?? 'all'}'),
                label: choices[i].label,
                count: choices[i].count,
                more: choices[i].more,
                selected: selected == choices[i].stage,
                // New orders stand out while they wait, so a narrowed list can never hide that something new came in.
                lit: choices[i].stage == 'new' && choices[i].count > 0,
                speech: choices[i].more
                    ? '${t.stageCountLabel(choices[i].label, choices[i].count)} ${t.andMore}'
                    : t.stageCountLabel(choices[i].label, choices[i].count),
                onTap: () => choices[i].stage == null ? onAll() : onStage(choices[i].stage!),
              ),
            ),
          ],
        ],
      ),
    );
  }
}

class _FilterSegment extends StatelessWidget {
  const _FilterSegment({
    super.key,
    required this.label,
    required this.count,
    required this.selected,
    required this.lit,
    required this.speech,
    required this.onTap,
    this.more = false,
  });
  final String label;
  final int count;
  final bool more;
  final bool selected;
  final bool lit;
  final String speech;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = context.colors;
    final palette = context.palette;
    final fg = selected ? scheme.onPrimary : palette.ink;
    final shape = RoundedRectangleBorder(
      borderRadius: BorderRadius.circular(14),
      side: BorderSide(color: selected || lit ? scheme.primary : palette.line, width: lit && !selected ? 1.5 : 1),
    );
    return Semantics(
      button: true,
      selected: selected,
      label: speech,
      excludeSemantics: true,
      child: Material(
        color: selected ? scheme.primary : scheme.surface,
        shape: shape,
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          customBorder: shape,
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: 56),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 2, vertical: 6),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Text(
                    more ? '$count+' : '$count',
                    maxLines: 1,
                    style: context.text.titleMedium?.copyWith(
                      color: selected ? fg : (lit ? scheme.primary : fg),
                      fontWeight: FontWeight.w700,
                      height: 1.15,
                    ),
                  ),
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      label,
                      maxLines: 1,
                      style: context.text.labelMedium?.copyWith(
                        color: selected ? fg : palette.muted,
                        letterSpacing: 0,
                        height: 1.2,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

// ── On the way ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

/// The "On the way" tab: a failed delivery at the very top under a red "Needs attention" header (so nothing that failed is missed),
/// then the deliveries that are out with a driver.
class StaffWayPage extends ConsumerWidget {
  const StaffWayPage({super.key, required this.rights});
  final StaffRights rights;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final rows = ref.watch(staffWayViewProvider);
    final groups = groupsOf(rows, kWayStages);
    return StageList(
      rows: rows,
      groups: groups,
      rights: rights,
      // The tab already says "On the way": its own header only separates the deliveries from the failed ones above them.
      bareStages: {if (groups.length == 1) 'out'},
      empty: EmptyState(icon: Icons.inbox_outlined, title: t.noOrders, compact: true),
      onMore: (stage) => ref.read(staffLaneDepthProvider(stage).notifier).more(),
      onRefresh: () async {
        ref.invalidate(staffOverviewProvider);
        for (final s in kWayStages) {
          ref.invalidate(staffQueueProvider(s));
        }
        await Future.wait([for (final s in kWayStages) ref.read(staffQueueProvider(s).future)]);
      },
      onRetry: () {
        for (final s in kWayStages) {
          ref.invalidate(staffQueueProvider(s));
        }
      },
    );
  }
}

// ── The grouped list both order tabs use ───────────────────────────────────────────────────────────────────────────────────

/// A pull-to-refresh list of order cards grouped by stage. It keeps the last good rows (with a [StaleBanner]) when a refresh fails,
/// shows a retry state when there is nothing to show, and stays pullable when empty. Each group has a header that stays at the top
/// while its orders scroll by and is pushed out by the next group's. A group whose lane has more pages ends in "Load more".
class StageList extends StatelessWidget {
  const StageList({
    super.key,
    required this.rows,
    required this.groups,
    required this.rights,
    required this.empty,
    required this.onRefresh,
    required this.onRetry,
    this.onMore,
    this.top,
    this.bareStages = const {},
    this.pickup = false,
  });

  final StageRows rows;
  final List<StageGroup> groups;
  final StaffRights rights;

  /// Drawn above the first group (the filters); scrolls away with the list.
  final Widget? top;

  /// What shows when there is no group to draw.
  final Widget empty;

  /// Groups drawn without a header.
  final Set<String> bareStages;

  /// The groups are pickup orders (their "ready" stage reads "Ready for pickup", and their one list has one "Load more" at its end).
  final bool pickup;
  final Future<void> Function() onRefresh;
  final VoidCallback onRetry;

  /// Opens the next page of the lane a group belongs to.
  final ValueChanged<String>? onMore;

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        if (rows.stale) StaleBanner(onRetry: onRetry),
        Expanded(
          child: RefreshIndicator(
            onRefresh: onRefresh,
            child: rows.failed
                ? ErrorState(error: rows.error!, onRetry: onRetry)
                : rows.loading
                ? const Center(child: CircularProgressIndicator())
                : _scroll(context),
          ),
        ),
      ],
    );
  }

  Widget _scroll(BuildContext context) {
    final extent = _headerExtent(context);
    return CustomScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      slivers: [
        ?(top == null ? null : SliverToBoxAdapter(child: top)),
        if (groups.isEmpty)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.only(top: AppSpace.xl),
              child: empty,
            ),
          )
        else
          for (var i = 0; i < groups.length; i++) _groupSliver(groups[i], extent, last: i == groups.length - 1),
        const SliverToBoxAdapter(child: Gap(AppSpace.gutter)),
      ],
    );
  }

  Widget _groupSliver(StageGroup g, double extent, {required bool last}) {
    final bare = bareStages.contains(g.stage);
    final index = {for (var i = 0; i < g.orders.length; i++) _cardKey(g.orders[i]): i};
    // Pickups come in one list: its next page is offered once, at the very end. Deliveries have a list per stage.
    final more = rows.more.contains(g.stage) && (!pickup || last);
    return SliverMainAxisGroup(
      key: ValueKey('group-${g.stage}'),
      slivers: [
        if (!bare)
          SliverPersistentHeader(
            pinned: true,
            delegate: _GroupHeaderDelegate(
              extent: extent,
              stage: g.stage,
              count: g.orders.length,
              pickup: pickup,
              more: rows.more.contains(g.stage),
            ),
          ),
        SliverPadding(
          padding: EdgeInsets.fromLTRB(
            AppSpace.gutter,
            bare ? AppSpace.sm : AppSpace.xxs,
            AppSpace.gutter,
            AppSpace.xs,
          ),
          sliver: SliverList.separated(
            itemCount: g.orders.length,
            // An order that changes place inside its group keeps its card (a slide or a request in progress is not lost).
            findItemIndexCallback: (key) => key is ValueKey<String> ? index[key] : null,
            separatorBuilder: (_, _) => const Gap(AppSpace.sm),
            itemBuilder: (_, i) => _OrderCard(key: _cardKey(g.orders[i]), order: g.orders[i], rights: rights),
          ),
        ),
        if (more)
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(AppSpace.gutter, 0, AppSpace.gutter, AppSpace.sm),
              child: _LoadMore(
                key: ValueKey('more-${g.stage}'),
                loading: rows.loadingMore.contains(g.stage),
                onPressed: onMore == null ? null : () => onMore!(g.stage),
              ),
            ),
          ),
      ],
    );
  }

  static ValueKey<String> _cardKey(QueueOrder o) => ValueKey('${o.fulfillmentId}-${o.status}-${o.driverId}');

  /// A header is one line of its title (or its count) at the reader's text size, never less than a comfortable touch row.
  static double _headerExtent(BuildContext context) {
    final scaler = MediaQuery.textScalerOf(context);
    final content = math.max(scaler.scale(14) * 1.35, scaler.scale(13) * 1.2 + 12);
    return math.max(44, (content + 20).ceilToDouble());
  }
}

/// "Load more" at the end of a long stage: a button that shows progress while the next page is read and cannot be tapped twice.
class _LoadMore extends StatelessWidget {
  const _LoadMore({super.key, required this.loading, required this.onPressed});
  final bool loading;
  final VoidCallback? onPressed;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return OutlinedButton(
      onPressed: loading ? null : onPressed,
      child: loading
          ? Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)),
                const Gap(AppSpace.xs),
                Text(t.loadingMore),
              ],
            )
          : Text(t.loadMore),
    );
  }
}

String _stageTitle(OpsStrings t, String stage, {bool pickup = false}) => switch (stage) {
  'new' => t.stageNew,
  'preparing' => t.stagePreparing,
  'ready' => pickup ? t.stagePickupReady : t.stageReady,
  'out' => t.stageOut,
  _ => t.needsAttention,
};

/// The header of a group: its icon, its name and how many orders are under it. The failed group is red.
class _GroupHeaderDelegate extends SliverPersistentHeaderDelegate {
  const _GroupHeaderDelegate({
    required this.extent,
    required this.stage,
    required this.count,
    this.pickup = false,
    this.more = false,
  });
  final double extent;
  final String stage;
  final int count;
  final bool pickup;

  /// The stage has a next page: its number is "at least".
  final bool more;

  @override
  double get minExtent => extent;

  @override
  double get maxExtent => extent;

  @override
  bool shouldRebuild(_GroupHeaderDelegate old) =>
      old.extent != extent || old.stage != stage || old.count != count || old.pickup != pickup || old.more != more;

  @override
  Widget build(BuildContext context, double shrinkOffset, bool overlapsContent) {
    final t = OpsStrings.of(context);
    final palette = context.palette;
    final (icon, tone, tint) = switch (stage) {
      'new' => (Icons.notifications_active_rounded, StatusTone.accent, palette.brass),
      'preparing' => (Icons.inventory_2_outlined, StatusTone.info, palette.info),
      'ready' => (Icons.task_alt_rounded, StatusTone.success, palette.success),
      'out' => (Icons.local_shipping_outlined, StatusTone.info, palette.info),
      _ => (Icons.report_gmailerrorred_rounded, StatusTone.danger, palette.danger),
    };
    final title = _stageTitle(t, stage, pickup: pickup);
    // The failed group's whole header is red; the others keep plain text next to a tinted icon.
    final ink = tone == StatusTone.danger ? palette.danger : palette.ink;
    return Semantics(
      header: true,
      label: more ? '${t.stageCountLabel(title, count)} ${t.andMore}' : t.stageCountLabel(title, count),
      excludeSemantics: true,
      // The header is exactly [extent] tall (a pinned header that is smaller than it says breaks the scroll geometry).
      child: SizedBox.expand(
        child: DecoratedBox(
          decoration: BoxDecoration(
            // Opaque, so the cards that scroll under a header that stays in view never show through it.
            color: context.pageColor,
            border: Border(bottom: BorderSide(color: overlapsContent ? palette.line : Colors.transparent)),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpace.gutter),
            child: Row(
              children: [
                Icon(icon, size: 20, color: tint),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Text(
                    title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: context.text.titleSmall?.copyWith(color: ink, fontWeight: FontWeight.w700),
                  ),
                ),
                StatusChip(label: more ? '$count+' : '$count', tone: tone),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

// ── An order card: its age in the header (amber or red when late), the customer, the request to cancel, the next step ─────────

class _OrderCard extends ConsumerWidget {
  const _OrderCard({super.key, required this.order, required this.rights});
  final QueueOrder order;
  final StaffRights rights;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final o = order;
    final now = ref.watch(staffTickProvider);
    final clock = clockNow(o, now);
    final tint = clock == null ? null : clockHeaderColor(context, clock.state);
    final hasDriver = o.driverName != null;
    final pickup = o.isPickup;
    final expired = pickup && o.status == 'pickup_ready' && (o.pickup?.isExpired(now) ?? false);
    final request = o.cancellation;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        // The whole card opens the order in a window beside the queue: the tab and the scroll position stay.
        onTap: () => showOrderPanel(context, order: o, rights: rights),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // The header carries the age, and turns amber or red when the wait does.
            Container(
              key: ValueKey('card-header-${o.fulfillmentId}'),
              color: tint,
              padding: const EdgeInsets.fromLTRB(AppSpace.md, AppSpace.sm, AppSpace.md, AppSpace.sm),
              child: Row(
                children: [
                  Expanded(
                    child: Row(
                      children: [
                        Flexible(
                          child: Text('#${o.number}', style: context.text.titleMedium, overflow: TextOverflow.ellipsis),
                        ),
                        if (pickup) ...[
                          const Gap(AppSpace.xs),
                          Tooltip(
                            message: t.pickupOrderTag,
                            child: Icon(
                              Icons.storefront_rounded,
                              size: 18,
                              color: context.palette.muted,
                              semanticLabel: t.pickupOrderTag,
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const Gap(AppSpace.xs),
                  Flexible(child: OrderAgeChip(order: o)),
                ],
              ),
            ),
            Padding(
              padding: EdgeInsets.fromLTRB(AppSpace.md, tint == null ? 0 : AppSpace.xs, AppSpace.md, AppSpace.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      Expanded(
                        child: Text('${o.customerName} · ${t.items(o.itemsCount)}', style: context.text.bodyMedium),
                      ),
                      Text(
                        t.money(o.total),
                        style: context.text.titleSmall?.copyWith(
                          color: context.colors.primary,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                    ],
                  ),
                  if (!pickup) Text(t.address(o.address), style: context.text.bodySmall),
                  if (pickup && o.dueAmount > 0)
                    Padding(
                      padding: const EdgeInsets.only(top: 2),
                      child: Text(t.amountToCollect(t.money(o.amountDue)), style: context.text.bodySmall),
                    ),
                  if (request != null && request.open) ...[
                    const Gap(AppSpace.xs),
                    CancellationBanner(request: request, canDecide: rights.canDecideCancellation, compact: true),
                  ],
                  if (hasDriver)
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpace.xs),
                      child: StatusChip(
                        label: t.assignedTo(o.driverName!),
                        tone: StatusTone.info,
                        icon: Icons.two_wheeler_rounded,
                      ),
                    )
                  else if (o.status == 'ready' && !pickup)
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpace.xs),
                      child: StatusChip(
                        label: t.waitingForDriver,
                        tone: StatusTone.warning,
                        icon: Icons.hourglass_top_rounded,
                      ),
                    ),
                  if (o.status == 'failed')
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpace.xs),
                      child: StatusChip(
                        label: t.deliveryFailedTag,
                        tone: StatusTone.danger,
                        icon: Icons.report_gmailerrorred_rounded,
                      ),
                    ),
                  if (expired)
                    Padding(
                      padding: const EdgeInsets.only(top: AppSpace.xs),
                      child: StatusChip(label: t.passExpired, tone: StatusTone.danger, icon: Icons.timer_off_outlined),
                    ),
                  if (pickup)
                    PickupActionButtons(
                      key: ValueKey('steps-${o.fulfillmentId}-${o.status}'),
                      order: o,
                      rights: rights,
                      compact: true,
                    )
                  else
                    OrderActionButtons(
                      key: ValueKey('steps-${o.fulfillmentId}-${o.status}-${o.driverId}'),
                      order: o,
                      autoDispatch: rights.autoDispatch,
                      canEdit: rights.canEdit,
                      canAssign: rights.canAssign,
                      canReturn: rights.canReturn,
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// ── Drivers ────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/// The "Drivers" tab: one switch at the top (List | Map) between the drivers of the branch and where they are right now. The last
/// choice is kept while the app is open; only the view that is showing is built (the map polls and loads tiles only while it is open).
class StaffDriversPage extends ConsumerWidget {
  const StaffDriversPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final view = ref.watch(staffDriversViewProvider);
    final scheme = context.colors;
    final palette = context.palette;
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.xs),
          child: SegmentedButton<StaffDriversView>(
            showSelectedIcon: false,
            expandedInsets: EdgeInsets.zero,
            style: SegmentedButton.styleFrom(
              minimumSize: const Size(0, kMinTouchTarget),
              backgroundColor: scheme.surface,
              foregroundColor: palette.ink,
              selectedBackgroundColor: scheme.primary,
              selectedForegroundColor: scheme.onPrimary,
              side: BorderSide(color: palette.line),
              tapTargetSize: MaterialTapTargetSize.padded,
            ),
            segments: [
              ButtonSegment(
                value: StaffDriversView.list,
                icon: const Icon(Icons.format_list_bulleted_rounded),
                label: Text(t.listTab, maxLines: 1, overflow: TextOverflow.ellipsis),
              ),
              ButtonSegment(
                value: StaffDriversView.map,
                icon: const Icon(Icons.map_rounded),
                label: Text(t.mapTab, maxLines: 1, overflow: TextOverflow.ellipsis),
              ),
            ],
            selected: {view},
            onSelectionChanged: (s) => ref.read(staffDriversViewProvider.notifier).choose(s.first),
          ),
        ),
        Expanded(
          child: view == StaffDriversView.list
              ? const _DriverList()
              : FleetMapTab(events: ref.watch(staffMovesProvider)),
        ),
      ],
    );
  }
}

class _DriverList extends ConsumerWidget {
  const _DriverList();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final t = OpsStrings.of(context);
    final drivers = ref.watch(staffDriversProvider);
    return RefreshIndicator(
      onRefresh: () => ref.refresh(staffDriversProvider.future),
      child: drivers.when(
        skipLoadingOnRefresh: true,
        skipError: true,
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorState(error: e, onRetry: () => ref.invalidate(staffDriversProvider)),
        data: (list) => list.isEmpty
            ? ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                children: [
                  const Gap(AppSpace.xxl),
                  EmptyState(icon: Icons.two_wheeler_rounded, title: t.noDrivers, message: '', compact: true),
                ],
              )
            : ListView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.symmetric(vertical: AppSpace.sm),
                children: [
                  for (final d in list)
                    DriverTile(
                      driver: d,
                      onTap: () => showDriverPanel(context, driver: d),
                    ),
                ],
              ),
      ),
    );
  }
}
