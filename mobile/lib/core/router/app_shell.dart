import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// Bottom navigation on phones, navigation rail on tablets / wide screens.
class AppShell extends ConsumerWidget {
  const AppShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final cartCount = ref.watch(cartCountProvider);
    final wide = MediaQuery.sizeOf(context).width >= 840;

    Widget cartIcon(bool selected) => Badge.count(
      count: cartCount,
      isLabelVisible: cartCount > 0,
      backgroundColor: context.palette.brass,
      child: Icon(selected ? Icons.shopping_bag_rounded : Icons.shopping_bag_outlined),
    );

    final destinations = <({Widget icon, Widget selected, String label})>[
      (icon: const Icon(Icons.home_outlined), selected: const Icon(Icons.home_rounded), label: l10n.navHome),
      (icon: const Icon(Icons.grid_view_outlined), selected: const Icon(Icons.grid_view_rounded), label: l10n.navCategories),
      (icon: const Icon(Icons.gavel_outlined), selected: const Icon(Icons.gavel_rounded), label: l10n.navAuctions),
      (icon: cartIcon(false), selected: cartIcon(true), label: l10n.navCart),
      (icon: const Icon(Icons.person_outline_rounded), selected: const Icon(Icons.person_rounded), label: l10n.navAccount),
    ];

    void onSelect(int index) {
      Haptics.tap();
      shell.goBranch(index, initialLocation: index == shell.currentIndex);
    }

    final body = Column(
      children: [
        const OfflineBanner(),
        Expanded(child: shell),
      ],
    );

    if (wide) {
      return Scaffold(
        body: SafeArea(
          child: Row(
            children: [
              NavigationRail(
                selectedIndex: shell.currentIndex,
                onDestinationSelected: onSelect,
                labelType: NavigationRailLabelType.all,
                leading: Padding(
                  padding: const EdgeInsets.symmetric(vertical: AppSpace.md),
                  child: Text(l10n.appName, style: context.text.titleLarge?.copyWith(color: context.palette.brass)),
                ),
                destinations: [
                  for (final d in destinations)
                    NavigationRailDestination(icon: d.icon, selectedIcon: d.selected, label: Text(d.label)),
                ],
              ),
              VerticalDivider(width: 1, color: context.palette.line),
              Expanded(child: body),
            ],
          ),
        ),
      );
    }

    return Scaffold(
      body: body,
      bottomNavigationBar: DecoratedBox(
        decoration: BoxDecoration(border: Border(top: BorderSide(color: context.palette.line))),
        child: NavigationBar(
          selectedIndex: shell.currentIndex,
          onDestinationSelected: onSelect,
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          destinations: [
            for (final d in destinations)
              NavigationDestination(icon: d.icon, selectedIcon: d.selected, label: d.label, tooltip: d.label),
          ],
        ),
      ),
    );
  }
}

class NotFoundScreen extends StatelessWidget {
  const NotFoundScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(),
    body: EmptyState(
      icon: Icons.explore_off_outlined,
      title: context.l10n.notFoundTitle,
      message: context.l10n.errorNotFound,
      actionLabel: context.l10n.navHome,
      onAction: () => context.go('/home'),
    ),
  );
}
