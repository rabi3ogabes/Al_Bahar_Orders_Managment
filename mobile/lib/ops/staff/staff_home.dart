import 'package:albahar_customer/core/realtime/live_link_badge.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/ops/ops_account_screens.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:albahar_customer/ops/staff/staff_orders.dart';
import 'package:albahar_customer/ops/staff/staff_providers.dart';
import 'package:albahar_customer/ops/staff/staff_tabs.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

export 'package:albahar_customer/ops/staff/staff_providers.dart';

/// Branch staff app: the day's delivery orders, one-tap order steps, and (when the branch is on manual dispatch) assigning a driver to
/// a ready order. Three tabs at most, all in view at once: To do (new, preparing and ready orders in one list, grouped by stage),
/// On the way (failed deliveries first, then the ones out) and Drivers (list and live map). Several employees can use it at once;
/// every screen reloads often.
class StaffHome extends ConsumerStatefulWidget {
  const StaffHome({super.key});

  @override
  ConsumerState<StaffHome> createState() => _StaffHomeState();
}

class _StaffHomeState extends ConsumerState<StaffHome> {
  // The screen follows the server through ONE orchestrator (staffLiveProvider): a message moves the order at once, a burst is confirmed
  // by one read, a reconnect / return to the foreground / return of the network reads everything, and a poll runs only while the socket is down.

  /// The refresh button: read everything now.
  void _reload() {
    ref.invalidate(staffOverviewProvider);
    for (final s in kStaffStages) {
      ref.invalidate(staffQueueProvider(s));
    }
    ref.invalidate(staffDriversProvider);
    ref.invalidate(staffFleetProvider);
  }

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    final permsAsync = ref.watch(staffPermissionsProvider);
    final perms = permsAsync.value ?? const <String>[];
    // An account that cannot see orders (the manager did not tick it, or took it away later) gets a plain explanation instead of
    // error messages. The order screens below are never built for it, so none of their requests are sent.
    if (permsAsync.hasValue && !hasPermission(perms, 'orders.view')) {
      return OpsNoAccessScreen(
        checking: permsAsync.isLoading,
        onCheckAgain: () => ref.invalidate(staffPermissionsProvider),
      );
    }
    final overview = ref.watch(staffOverviewViewProvider);
    final link = ref.watch(staffLiveProvider.select((s) => s.link));
    final o = overview.value;
    final canAddProduct = hasPermission(perms, 'catalog.create');
    final canSeeDrivers = hasPermission(perms, 'deliveries.view');
    final canEdit = hasPermission(perms, 'orders.edit');
    final canAssign = hasPermission(perms, 'deliveries.assign');
    final canReturn = hasPermission(perms, 'deliveries.edit');
    // A brand-new order (its first message says "pending"): one alert, however many times the message repeats.
    ref.listen(staffLiveProvider.select((s) => s.patches), (prev, next) {
      for (final p in next.values) {
        if (p.status == 'pending' && prev?[p.fulfillmentId] == null) {
          Haptics.success();
          showInfoSnack(context, t.newOrderAlert(p.number));
        }
      }
    });
    // The filter and the Drivers view are kept while this screen is open, even while their tab is not showing.
    ref
      ..listen(staffTodoFilterProvider, (_, _) {})
      ..listen(staffDriversViewProvider, (_, _) {});
    final tabs = staffTabsFor(canSeeDrivers: canSeeDrivers);
    final rights = StaffRights(
      autoDispatch: o?.autoDispatch ?? false,
      canEdit: canEdit,
      canAssign: canAssign,
      canReturn: canReturn,
    );
    return DefaultTabController(
      key: ValueKey(tabs.length),
      length: tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: Row(
            children: [
              const OpsEmblem(role: OpsRole.staff, size: 34),
              const Gap(AppSpace.xs),
              Flexible(child: Text(o?.branchName ?? t.staffApp, overflow: TextOverflow.ellipsis)),
            ],
          ),
          actions: [
            // A phone's app bar is narrow: the colour dot alone (the words are its tooltip and its screen-reader label); a tablet shows them.
            LiveLinkBadge(link: link, labels: t.liveLabels, showLabel: MediaQuery.sizeOf(context).width >= 600),
            const Gap(AppSpace.xxs),
            if (canAddProduct)
              IconButton(
                tooltip: t.addProduct,
                icon: const Icon(Icons.add_photo_alternate_rounded),
                onPressed: () => Navigator.of(context)
                    .push(MaterialPageRoute<bool>(fullscreenDialog: true, builder: (_) => const AddProductScreen())),
              ),
            IconButton(tooltip: t.refresh, icon: const Icon(Icons.refresh_rounded), onPressed: _reload),
            const OpsAccountMenu(),
          ],
          bottom: staffTabBar(context, tabs, o),
        ),
        body: overview.hasError && o == null
            ? ErrorState(error: overview.error!, onRetry: () => ref.invalidate(staffOverviewProvider))
            : Column(
                children: [
                  if (o != null) _OverviewBar(o: o, showOnDuty: !canSeeDrivers),
                  Expanded(
                    child: TabBarView(
                      children: [
                        for (final tab in tabs)
                          switch (tab) {
                            // The two order lists keep their place while the other tab is open; the Drivers page does not (its map polls).
                            StaffTab.todo => StaffKeepAlive(child: StaffTodoPage(rights: rights)),
                            StaffTab.way => StaffKeepAlive(child: StaffWayPage(rights: rights)),
                            StaffTab.drivers => const StaffDriversPage(),
                          },
                      ],
                    ),
                  ),
                ],
              ),
      ),
    );
  }
}

/// What the tabs do not already say, in one compact strip: the orders waiting for a driver (lit while any wait), the drivers on duty
/// when this account has no Drivers tab to show them, and how drivers get their orders. The numbers of the stages and of the
/// deliveries out are on the tabs.
class _OverviewBar extends StatelessWidget {
  const _OverviewBar({required this.o, required this.showOnDuty});
  final StaffOverview o;
  final bool showOnDuty;

  @override
  Widget build(BuildContext context) {
    final t = OpsStrings.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, 0),
      child: Column(
        children: [
          IntrinsicHeight(
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                _StatPill(
                  icon: Icons.hourglass_top_rounded,
                  label: t.waitingForDriver,
                  value: o.waitingForDriver,
                  highlight: o.waitingForDriver > 0,
                ),
                if (showOnDuty) ...[
                  const Gap(AppSpace.xs),
                  _StatPill(icon: Icons.two_wheeler_rounded, label: t.driversOnDuty, value: o.driversOnDuty),
                ],
              ],
            ),
          ),
          const Gap(AppSpace.xs),
          Row(
            children: [
              Icon(
                o.autoDispatch ? Icons.auto_mode_rounded : Icons.touch_app_outlined,
                size: 16,
                color: context.palette.muted,
              ),
              const Gap(AppSpace.xxs),
              Expanded(child: Text(o.autoDispatch ? t.autoDispatch : t.manualDispatch, style: context.text.bodySmall)),
            ],
          ),
        ],
      ),
    );
  }
}

/// A small number with its words beside it, one line high (two lines when the text is large). Lit in the same amber as the "Waiting
/// for a driver" mark on an order card while it is more than zero, calm otherwise.
class _StatPill extends StatelessWidget {
  const _StatPill({required this.icon, required this.label, required this.value, this.highlight = false});
  final IconData icon;
  final String label;
  final int value;
  final bool highlight;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final bg = highlight
        ? (dark ? palette.warning.withValues(alpha: 0.16) : AppPalette.warningTint)
        : context.colors.surface;
    return Expanded(
      child: MergeSemantics(
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: bg,
            borderRadius: AppRadius.inputAll,
            border: Border.all(color: highlight ? Colors.transparent : palette.line),
          ),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
            child: Row(
              children: [
                Icon(icon, size: 18, color: highlight ? palette.warning : palette.muted),
                const Gap(AppSpace.xs),
                Text(
                  '$value',
                  style: context.text.titleMedium?.copyWith(color: palette.ink, fontWeight: FontWeight.w700),
                ),
                const Gap(AppSpace.xs),
                Expanded(
                  child: Text(
                    label,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: context.text.bodySmall?.copyWith(color: palette.ink),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
