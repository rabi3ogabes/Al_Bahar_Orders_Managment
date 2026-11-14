import 'dart:ui' show ImageFilter;

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/router/footer_config.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/core/theme/contrast.dart';
import 'package:albahar_customer/core/theme/design_tokens.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

/// The paths of the built-in five-tab footer: Home, Categories, Auctions, Cart, Account.
final footerPaths = [for (final k in FooterConfig.fallback.keys) footerDestinations[k]!.path];

/// Screens that cover the whole window without the footer: the first-run flow, sign-in and the full-screen barcode scanner.
const _noFooterPrefixes = ['/splash', '/language', '/onboarding', '/login', '/scan'];

bool showsFooter(String path) => path != '/' && !_noFooterPrefixes.any((p) => path == p || path.startsWith('$p/'));

/// Which footer tab a page belongs to, so the right one stays highlighted on every page (a product page sits under
/// Categories, the order list under Account...). [keys] is the dashboard's item list; the default is the built-in footer.
int footerIndexFor(String path, [List<String>? keys]) => footerIndexForKeys(path, keys ?? FooterConfig.fallback.keys);

/// The footer the dashboard configured, or the built-in one while nothing valid has arrived.
/// When the chosen design asks for the centre-action menu (`tokens.nav`) and the dashboard has not customised the footer
/// (nothing sent, or just the built-in default), the centre-action footer is used. An explicit footer choice always wins.
final footerConfigProvider = Provider<FooterConfig>((ref) {
  final footer = ref.watch(brandThemeProvider.select((b) => b.footer));
  final nav = ref.watch(brandThemeProvider.select((b) => b.design.nav));
  final customised = footer != null && footer != FooterConfig.fallback;
  if (!customised && nav == DesignNav.centerAction) return FooterConfig.centerAction;
  return footer ?? FooterConfig.fallback;
});

/// Height of the flat part of the centre-action bar (without the system inset) and how far the round button rises above it.
const double kCenterBarHeight = 64;
const double kCenterButtonRise = 28;

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
    // The pages must not add the system bottom inset a second time: the footer already sits on it.
    final page = MediaQuery(data: mq.removePadding(removeBottom: true), child: widget.child);
    return Consumer(
      builder: (context, ref, _) {
        if (ref.watch(footerConfigProvider).design != FooterDesign.centerAction) {
          return Column(
            children: [
              Expanded(child: page),
              AppFooter(path: path, onGo: _go),
            ],
          );
        }
        // The raised round button rises above the bar, over the page: a Stack keeps it tappable.
        final flat = kCenterBarHeight + mq.padding.bottom;
        return Stack(
          children: [
            Positioned.fill(bottom: flat, child: page),
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: AppFooter(path: path, onGo: _go),
            ),
          ],
        );
      },
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
          // The raised middle button of the centre-action bar shows a plus for Shop unless the dashboard picked an icon.
          icon: e.icons.$1,
          iconOn: e.icons.$2,
          raisedIcon: e.icon == null && e.key == 'categories' ? Icons.add_rounded : null,
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
      FooterDesign.centerAction => _CenterActionBar(
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
    this.raisedIcon,
  });

  final IconData icon;
  final IconData iconOn;
  final String label;
  final int badge;
  final String route;

  /// Icon for the raised centre button, when it should differ from [iconOn].
  final IconData? raisedIcon;
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

/// 6. Centre action: five slots, the middle one a raised round button in the main colour, two labelled items each side.
/// The bar is `kCenterBarHeight` tall (plus the system inset) with `kCenterButtonRise` of free space above it for the
/// button, which [FooterScope] lets overlap the page.
class _CenterActionBar extends StatelessWidget {
  const _CenterActionBar({
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

  static const double _button = 56;
  static const double _ring = 4;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final muted = scheme.onSurfaceVariant;
    // Active text must stay AA on the bar surface whatever colour the dashboard picked.
    final activeInk = Contrast.ensure(accent, scheme.surface);
    final onAccent = Contrast.onColor(accent);
    final centre = items.length ~/ 2;
    final raised = items[centre];
    final inset = MediaQuery.paddingOf(context).bottom;
    final radius = context.design.isFresh ? 28.0 : 24.0;

    Widget slot(int i) => Expanded(
      child: _Tap(
        item: items[i],
        selected: i == selected,
        onTap: () => onSelect(i),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              _iconWithBadge(context, items[i], i == selected, i == selected ? activeInk : muted),
              if (showLabels)
                Text(
                  items[i].label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                    color: i == selected ? activeInk : muted,
                    fontWeight: i == selected ? FontWeight.w700 : FontWeight.w500,
                  ),
                ),
            ],
          ),
        ),
      ),
    );

    final spoken = raised.badge > 0 ? '${raised.label}, ${raised.badge}' : raised.label;
    final icon = Icon(raised.raisedIcon ?? raised.iconOn, color: onAccent, size: 30);
    return SizedBox(
      height: kCenterButtonRise + kCenterBarHeight + inset,
      child: Stack(
        children: [
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            height: kCenterBarHeight + inset,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: scheme.surface,
                borderRadius: BorderRadius.vertical(top: Radius.circular(radius)),
                border: dark ? Border(top: BorderSide(color: context.palette.line)) : null,
                boxShadow: dark
                    ? null
                    : [BoxShadow(color: accent.withValues(alpha: 0.14), blurRadius: 24, offset: const Offset(0, -6))],
              ),
              child: Padding(
                padding: EdgeInsets.only(bottom: inset),
                child: Row(
                  children: [
                    for (var i = 0; i < items.length; i++)
                      if (i == centre) const Expanded(child: SizedBox(height: kMinTouchTarget)) else slot(i),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            top: 0,
            left: 0,
            right: 0,
            child: Center(
              child: Semantics(
                button: true,
                selected: centre == selected,
                label: spoken,
                excludeSemantics: true,
                onTap: () => onSelect(centre),
                child: Container(
                  width: _button + _ring * 2,
                  height: _button + _ring * 2,
                  padding: const EdgeInsets.all(_ring),
                  decoration: BoxDecoration(color: scheme.surface, shape: BoxShape.circle),
                  // Own Material: the footer sits above the app's navigator.
                  child: Material(
                    color: accent,
                    shape: const CircleBorder(),
                    elevation: 0,
                    child: InkWell(
                      customBorder: const CircleBorder(),
                      onTap: () => onSelect(centre),
                      child: Center(
                        child: raised.badge > 0
                            ? Badge.count(count: raised.badge, backgroundColor: context.palette.brass, child: icon)
                            : icon,
                      ),
                    ),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
