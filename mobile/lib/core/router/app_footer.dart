import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// The five footer destinations, in order: Home, Categories, Auctions, Cart, Account.
const footerPaths = ['/home', '/categories', '/auctions', '/cart', '/account'];

/// Screens that cover the whole window without the footer: the first-run flow and sign-in.
const _noFooterPrefixes = ['/splash', '/language', '/onboarding', '/login'];

bool showsFooter(String path) => path != '/' && !_noFooterPrefixes.any((p) => path == p || path.startsWith('$p/'));

/// Which footer tab a page belongs to, so the right one stays highlighted on every page (a product page sits under
/// Categories, the order list under Account…).
int footerIndexFor(String path) {
  bool under(String p) => path == p || path.startsWith('$p/');
  if (under('/categories') || under('/products') || under('/search')) return 1;
  if (under('/auctions') || under('/my-auctions')) return 2;
  if (under('/cart') || under('/checkout') || under('/payments')) return 3;
  if (under('/account') ||
      under('/orders') ||
      under('/wishlist') ||
      under('/profile') ||
      under('/addresses') ||
      under('/preferences') ||
      under('/loyalty') ||
      under('/support') ||
      under('/notifications') ||
      under('/settings')) {
    return 4;
  }
  return 0;
}

/// Puts the footer menu under EVERY page of the app (not only the five tab roots), on phones and wide windows alike.
/// It hides only on the first-run screens and sign-in, and while the keyboard is open so forms keep their room.
class FooterScope extends StatefulWidget {
  const FooterScope({super.key, required this.router, required this.child});

  final GoRouter router;
  final Widget child;

  @override
  State<FooterScope> createState() => _FooterScopeState();
}

class _FooterScopeState extends State<FooterScope> {
  @override
  void initState() {
    super.initState();
    widget.router.routerDelegate.addListener(_onRoute);
  }

  @override
  void didUpdateWidget(FooterScope old) {
    super.didUpdateWidget(old);
    if (old.router != widget.router) {
      old.router.routerDelegate.removeListener(_onRoute);
      widget.router.routerDelegate.addListener(_onRoute);
    }
  }

  @override
  void dispose() {
    widget.router.routerDelegate.removeListener(_onRoute);
    super.dispose();
  }

  /// The router announces a new page while it is still building the navigator: rebuild right after that frame.
  void _onRoute() {
    if (!mounted) return;
    if (SchedulerBinding.instance.schedulerPhase == SchedulerPhase.persistentCallbacks) {
      SchedulerBinding.instance.addPostFrameCallback((_) {
        if (mounted) setState(() {});
      });
    } else {
      setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    final path = widget.router.routerDelegate.currentConfiguration.uri.path;
    final mq = MediaQuery.of(context);
    if (!showsFooter(path) || mq.viewInsets.bottom > 0) return widget.child;
    return Column(
      children: [
        // The pages must not add the system bottom inset a second time: the footer already sits on it.
        Expanded(
          child: MediaQuery(data: mq.removePadding(removeBottom: true), child: widget.child),
        ),
        _Footer(selected: footerIndexFor(path), onSelect: _go),
      ],
    );
  }

  void _go(int index) {
    Haptics.tap();
    widget.router.go(footerPaths[index]);
  }
}

class _Footer extends ConsumerWidget {
  const _Footer({required this.selected, required this.onSelect});

  final int selected;
  final ValueChanged<int> onSelect;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final count = ref.watch(cartCountProvider);
    Widget cart(bool on) => Badge.count(
      count: count,
      isLabelVisible: count > 0,
      backgroundColor: context.palette.brass,
      child: Icon(on ? Icons.shopping_bag_rounded : Icons.shopping_bag_outlined),
    );
    final items = <({Widget icon, Widget on, String label})>[
      (icon: const Icon(Icons.home_outlined), on: const Icon(Icons.home_rounded), label: l10n.navHome),
      (icon: const Icon(Icons.grid_view_outlined), on: const Icon(Icons.grid_view_rounded), label: l10n.navCategories),
      (icon: const Icon(Icons.gavel_outlined), on: const Icon(Icons.gavel_rounded), label: l10n.navAuctions),
      (icon: cart(false), on: cart(true), label: l10n.navCart),
      (icon: const Icon(Icons.person_outline_rounded), on: const Icon(Icons.person_rounded), label: l10n.navAccount),
    ];
    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: context.palette.line)),
      ),
      child: NavigationBar(
        selectedIndex: selected,
        onDestinationSelected: onSelect,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        destinations: [
          for (final d in items) NavigationDestination(icon: d.icon, selectedIcon: d.on, label: d.label, tooltip: ''),
        ],
      ),
    );
  }
}
