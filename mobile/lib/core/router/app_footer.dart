import 'dart:ui' show ImageFilter;

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/router/footer_config.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// The paths of the built-in five-tab footer: Home, Categories, Auctions, Cart, Account.
final footerPaths = [for (final k in FooterConfig.fallback.keys) footerDestinations[k]!.path];

/// Screens that cover the whole window without the footer: the first-run flow and sign-in.
const _noFooterPrefixes = ['/splash', '/language', '/onboarding', '/login'];

bool showsFooter(String path) => path != '/' && !_noFooterPrefixes.any((p) => path == p || path.startsWith('$p/'));

/// Which footer tab a page belongs to, so the right one stays highlighted on every page (a product page sits under
/// Categories, the order list under Account...). [keys] is the dashboard's item list; the default is the built-in footer.
int footerIndexFor(String path, [List<String>? keys]) => footerIndexForKeys(path, keys ?? FooterConfig.fallback.keys);

/// The footer the dashboard configured, or the built-in one while nothing valid has arrived.
final footerConfigProvider = Provider<FooterConfig>(
  (ref) => ref.watch(brandThemeProvider.select((b) => b.footer)) ?? FooterConfig.fallback,
);

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
        AppFooter(path: path, onGo: _go),
      ],
    );
  }

  void _go(String route) {
    Haptics.tap();
    widget.router.go(route);
  }
}

/// The bottom menu. Reads the dashboard's footer config (design, items, labels, colour) and draws it; with no valid
/// config it shows the built-in five-tab Material bar.
class AppFooter extends ConsumerWidget {
  const AppFooter({super.key, required this.path, required this.onGo});

  /// The page on screen, to light the right item.
  final String path;

  /// Called with the route of the tapped item.
  final ValueChanged<String> onGo;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = ref.watch(footerConfigProvider);
    final l10n = context.l10n;
    final lang = Localizations.localeOf(context).languageCode;
    final count = ref.watch(cartCountProvider);
    final selected = footerIndexForKeys(path, config.keys);
    final accent = config.accent ?? Theme.of(context).colorScheme.primary;

    final views = <_FooterItemView>[
      for (final e in config.items)
        _FooterItemView(
          icon: e.icons.$1,
          iconOn: e.icons.$2,
          label: e.labelFor(l10n, lang),
          badge: e.key == 'cart' && config.cartBadge ? count : 0,
          route: e.destination.path,
        ),
    ];
    void select(int i) => onGo(views[i].route);

    return switch (config.design) {
      FooterDesign.classic => _ClassicBar(
        items: views,
        selected: selected,
        onSelect: select,
        showLabels: config.showLabels,
        accent: config.accent,
      ),
      FooterDesign.floating => _FloatingBar(
        items: views,
        selected: selected,
        onSelect: select,
        showLabels: config.showLabels,
        accent: accent,
      ),
      FooterDesign.minimal => _MinimalBar(items: views, selected: selected, onSelect: select, accent: accent),
      FooterDesign.bubble => _BubbleBar(
        items: views,
        selected: selected,
        onSelect: select,
        showLabels: config.showLabels,
        accent: accent,
      ),
      FooterDesign.glass => _GlassBar(
        items: views,
        selected: selected,
        onSelect: select,
        showLabels: config.showLabels,
        accent: accent,
      ),
    };
  }
}

@immutable
class _FooterItemView {
  const _FooterItemView({
    required this.icon,
    required this.iconOn,
    required this.label,
    required this.badge,
    required this.route,
  });

  final IconData icon;
  final IconData iconOn;
  final String label;
  final int badge;
  final String route;
}

Duration _motion(BuildContext context) => MediaQuery.disableAnimationsOf(context) ? Duration.zero : AppDurations.medium;

/// Icon with the cart count on it when there is one.
/// A null [color] lets the surrounding bar (the Material one) colour the icon itself.
Widget _iconWithBadge(BuildContext context, _FooterItemView v, bool on, Color? color, {double size = 24}) {
  final icon = Icon(on ? v.iconOn : v.icon, color: color, size: color == null ? null : size);
  if (v.badge <= 0) return icon;
  return Badge.count(count: v.badge, backgroundColor: context.palette.brass, child: icon);
}

/// One tappable item: at least 48 x 48, announced as a selected button with its word (and the cart count).
class _Tap extends StatelessWidget {
  const _Tap({required this.item, required this.selected, required this.onTap, required this.child});

  final _FooterItemView item;
  final bool selected;
  final VoidCallback onTap;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final spoken = item.badge > 0 ? '${item.label}, ${item.badge}' : item.label;
    return Semantics(
      button: true,
      selected: selected,
      label: spoken,
      excludeSemantics: true,
      onTap: onTap,
      // The footer sits above the app's navigator, so it brings its own (invisible) Material for the tap ripple.
      child: Material(
        type: MaterialType.transparency,
        child: InkWell(
          onTap: onTap,
          customBorder: const StadiumBorder(),
          child: ConstrainedBox(
            constraints: const BoxConstraints(minHeight: kMinTouchTarget, minWidth: kMinTouchTarget),
            child: Center(child: child),
          ),
        ),
      ),
    );
  }
}

/// Icon over a one-line word: shared by the floating and glass bars.
class _IconLabelRow extends StatelessWidget {
  const _IconLabelRow({
    required this.items,
    required this.selected,
    required this.onSelect,
    required this.showLabels,
    required this.accent,
  });

  final List<_FooterItemView> items;
  final int selected;
  final ValueChanged<int> onSelect;
  final bool showLabels;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    return Row(
      children: [
        for (var i = 0; i < items.length; i++)
          Expanded(
            child: _Tap(
              item: items[i],
              selected: i == selected,
              onTap: () => onSelect(i),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    _iconWithBadge(context, items[i], i == selected, i == selected ? accent : muted),
                    if (showLabels)
                      Text(
                        items[i].label,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: Theme.of(context).textTheme.labelSmall?.copyWith(
                          color: i == selected ? accent : muted,
                          fontWeight: i == selected ? FontWeight.w700 : FontWeight.w500,
                        ),
                      ),
                  ],
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// 1. Classic: the flat Material bar the app always had.
class _ClassicBar extends StatelessWidget {
  const _ClassicBar({
    required this.items,
    required this.selected,
    required this.onSelect,
    required this.showLabels,
    required this.accent,
  });

  final List<_FooterItemView> items;
  final int selected;
  final ValueChanged<int> onSelect;
  final bool showLabels;
  final Color? accent;

  @override
  Widget build(BuildContext context) {
    final bar = NavigationBar(
      selectedIndex: selected,
      onDestinationSelected: onSelect,
      labelBehavior: showLabels
          ? NavigationDestinationLabelBehavior.alwaysShow
          : NavigationDestinationLabelBehavior.alwaysHide,
      destinations: [
        for (final v in items)
          NavigationDestination(
            icon: _iconWithBadge(context, v, false, null),
            selectedIcon: _iconWithBadge(context, v, true, null),
            label: v.label,
            tooltip: '',
          ),
      ],
    );
    final themed = accent == null
        ? bar
        : NavigationBarTheme(
            data: NavigationBarTheme.of(context).copyWith(
              indicatorColor: accent!.withValues(alpha: 0.16),
              iconTheme: WidgetStateProperty.resolveWith(
                (states) => IconThemeData(color: states.contains(WidgetState.selected) ? accent : null),
              ),
              labelTextStyle: WidgetStateProperty.resolveWith(
                (states) => Theme.of(context).textTheme.labelMedium?.copyWith(
                  color: states.contains(WidgetState.selected) ? accent : null,
                  fontWeight: states.contains(WidgetState.selected) ? FontWeight.w700 : FontWeight.w500,
                ),
              ),
            ),
            child: bar,
          );
    return DecoratedBox(
      decoration: BoxDecoration(
        border: Border(top: BorderSide(color: context.palette.line)),
      ),
      child: themed,
    );
  }
}

/// 2. Floating: a rounded bar with a margin and a shadow, hovering above the page.
class _FloatingBar extends StatelessWidget {
  const _FloatingBar({
    required this.items,
    required this.selected,
    required this.onSelect,
    required this.showLabels,
    required this.accent,
  });

  final List<_FooterItemView> items;
  final int selected;
  final ValueChanged<int> onSelect;
  final bool showLabels;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return SafeArea(
      top: false,
      minimum: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsetsDirectional.fromSTEB(16, 6, 16, 4),
        child: Material(
          color: scheme.surface,
          elevation: 8,
          shadowColor: Colors.black45,
          borderRadius: BorderRadius.circular(32),
          clipBehavior: Clip.antiAlias,
          child: _IconLabelRow(
            items: items,
            selected: selected,
            onSelect: onSelect,
            showLabels: showLabels,
            accent: accent,
          ),
        ),
      ),
    );
  }
}

/// 3. Minimal: icons only. The open page shows a dot under its icon.
class _MinimalBar extends StatelessWidget {
  const _MinimalBar({required this.items, required this.selected, required this.onSelect, required this.accent});

  final List<_FooterItemView> items;
  final int selected;
  final ValueChanged<int> onSelect;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    final d = _motion(context);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        border: Border(top: BorderSide(color: context.palette.line)),
      ),
      child: SafeArea(
        top: false,
        child: SizedBox(
          height: 56,
          child: Row(
            children: [
              for (var i = 0; i < items.length; i++)
                Expanded(
                  child: _Tap(
                    item: items[i],
                    selected: i == selected,
                    onTap: () => onSelect(i),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        _iconWithBadge(context, items[i], i == selected, i == selected ? accent : muted),
                        const SizedBox(height: 4),
                        AnimatedContainer(
                          duration: d,
                          width: i == selected ? 5 : 0,
                          height: i == selected ? 5 : 0,
                          decoration: BoxDecoration(color: accent, shape: BoxShape.circle),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

/// 4. Bubble: the open page grows into a filled pill that carries its word.
class _BubbleBar extends StatelessWidget {
  const _BubbleBar({
    required this.items,
    required this.selected,
    required this.onSelect,
    required this.showLabels,
    required this.accent,
  });

  final List<_FooterItemView> items;
  final int selected;
  final ValueChanged<int> onSelect;
  final bool showLabels;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    final onAccent = ThemeData.estimateBrightnessForColor(accent) == Brightness.dark ? Colors.white : Colors.black87;
    final d = _motion(context);
    return DecoratedBox(
      decoration: BoxDecoration(
        color: Theme.of(context).colorScheme.surface,
        border: Border(top: BorderSide(color: context.palette.line)),
      ),
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceAround,
            children: [
              for (var i = 0; i < items.length; i++)
                i == selected
                    ? Flexible(
                        child: _Tap(
                          item: items[i],
                          selected: true,
                          onTap: () => onSelect(i),
                          child: AnimatedContainer(
                            duration: d,
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                            decoration: BoxDecoration(color: accent, borderRadius: BorderRadius.circular(999)),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                _iconWithBadge(context, items[i], true, onAccent),
                                if (showLabels) ...[
                                  const SizedBox(width: 6),
                                  Flexible(
                                    child: Text(
                                      items[i].label,
                                      maxLines: 1,
                                      overflow: TextOverflow.ellipsis,
                                      style: Theme.of(context).textTheme.labelLarge
                                          ?.copyWith(color: onAccent, fontWeight: FontWeight.w700),
                                    ),
                                  ),
                                ],
                              ],
                            ),
                          ),
                        ),
                      )
                    : _Tap(
                        item: items[i],
                        selected: false,
                        onTap: () => onSelect(i),
                        child: _iconWithBadge(context, items[i], false, muted),
                      ),
            ],
          ),
        ),
      ),
    );
  }
}

/// 5. Glass: a frosted, see-through bar.
class _GlassBar extends StatelessWidget {
  const _GlassBar({
    required this.items,
    required this.selected,
    required this.onSelect,
    required this.showLabels,
    required this.accent,
  });

  final List<_FooterItemView> items;
  final int selected;
  final ValueChanged<int> onSelect;
  final bool showLabels;
  final Color accent;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    return ClipRect(
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
        child: DecoratedBox(
          decoration: BoxDecoration(
            color: scheme.surface.withValues(alpha: dark ? 0.55 : 0.62),
            border: Border(
              top: BorderSide(color: (dark ? Colors.white : Colors.white).withValues(alpha: dark ? 0.12 : 0.7)),
            ),
          ),
          child: SafeArea(
            top: false,
            child: _IconLabelRow(
              items: items,
              selected: selected,
              onSelect: onSelect,
              showLabels: showLabels,
              accent: accent,
            ),
          ),
        ),
      ),
    );
  }
}
