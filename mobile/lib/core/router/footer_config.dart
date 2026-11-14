import 'package:albahar_customer/core/utils/json.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';

/// The six looks of the bottom menu. Chosen in the dashboard (Appearance > App footer). [centerAction] keeps the middle
/// item as a raised round button (the "fresh" design); the server spells it `center_action`.
enum FooterDesign {
  classic,
  floating,
  minimal,
  bubble,
  glass,
  centerAction;

  /// The key the server uses for this design.
  String get wire => this == centerAction ? 'center_action' : name;
}

/// One place a footer item can lead to. The server only sends the [key]; the route, the pages that belong to it and the
/// default icon and word live here.
@immutable
class FooterDestination {
  const FooterDestination({
    required this.key,
    required this.path,
    required this.prefixes,
    required this.icon,
    required this.iconOn,
    required this.label,
    this.parent,
  });

  final String key;
  final String path;

  /// Every page under these prefixes keeps this item lit.
  final List<String> prefixes;
  final IconData icon;
  final IconData iconOn;
  final String Function(AppLocalizations l10n) label;

  /// Where a page counts when this destination is not in the footer (an order page sits under Account).
  final String? parent;

  bool owns(String routePath) => prefixes.any((p) => routePath == p || routePath.startsWith('$p/'));
}

/// Fixed allow-list, same keys as the server (App\Domain\Cms\Services\AppFooterService::ITEMS).
final Map<String, FooterDestination> footerDestinations = {
  for (final d in <FooterDestination>[
    FooterDestination(
      key: 'home',
      path: '/home',
      prefixes: ['/home'],
      icon: Icons.home_outlined,
      iconOn: Icons.home_rounded,
      label: (l) => l.navHome,
    ),
    FooterDestination(
      key: 'categories',
      path: '/categories',
      prefixes: ['/categories', '/products'],
      icon: Icons.grid_view_outlined,
      iconOn: Icons.grid_view_rounded,
      label: (l) => l.navCategories,
    ),
    FooterDestination(
      key: 'auctions',
      path: '/auctions',
      prefixes: ['/auctions', '/my-auctions'],
      icon: Icons.gavel_outlined,
      iconOn: Icons.gavel_rounded,
      label: (l) => l.navAuctions,
    ),
    FooterDestination(
      key: 'cart',
      path: '/cart',
      prefixes: ['/cart', '/checkout', '/payments'],
      icon: Icons.shopping_bag_outlined,
      iconOn: Icons.shopping_bag_rounded,
      label: (l) => l.navCart,
    ),
    FooterDestination(
      key: 'account',
      path: '/account',
      prefixes: ['/account', '/profile', '/addresses', '/preferences', '/loyalty', '/invite', '/settings'],
      icon: Icons.person_outline_rounded,
      iconOn: Icons.person_rounded,
      label: (l) => l.navAccount,
    ),
    FooterDestination(
      key: 'orders',
      path: '/orders',
      prefixes: ['/orders'],
      icon: Icons.receipt_long_outlined,
      iconOn: Icons.receipt_long_rounded,
      label: (l) => l.navOrders,
      parent: 'account',
    ),
    FooterDestination(
      key: 'wishlist',
      path: '/wishlist',
      prefixes: ['/wishlist'],
      icon: Icons.favorite_border_rounded,
      iconOn: Icons.favorite_rounded,
      label: (l) => l.navWishlist,
      parent: 'account',
    ),
    FooterDestination(
      key: 'search',
      path: '/search',
      prefixes: ['/search'],
      icon: Icons.search_rounded,
      iconOn: Icons.search_rounded,
      label: (l) => l.navSearch,
      parent: 'categories',
    ),
    FooterDestination(
      key: 'scan',
      path: '/scan',
      prefixes: ['/scan'],
      icon: Icons.qr_code_scanner_rounded,
      iconOn: Icons.qr_code_scanner_rounded,
      label: (l) => l.navScan,
      parent: 'cart',
    ),
    FooterDestination(
      key: 'support',
      path: '/support',
      prefixes: ['/support'],
      icon: Icons.support_agent_outlined,
      iconOn: Icons.support_agent_rounded,
      label: (l) => l.navSupport,
      parent: 'account',
    ),
    FooterDestination(
      key: 'notifications',
      path: '/notifications',
      prefixes: ['/notifications'],
      icon: Icons.notifications_none_rounded,
      iconOn: Icons.notifications_rounded,
      label: (l) => l.navNotifications,
      parent: 'account',
    ),
  ])
    d.key: d,
};

/// Icon keys the dashboard can pick (outlined, filled), same list as the server.
const Map<String, (IconData, IconData)> footerIcons = {
  'home': (Icons.home_outlined, Icons.home_rounded),
  'grid': (Icons.grid_view_outlined, Icons.grid_view_rounded),
  'gavel': (Icons.gavel_outlined, Icons.gavel_rounded),
  'bag': (Icons.shopping_bag_outlined, Icons.shopping_bag_rounded),
  'person': (Icons.person_outline_rounded, Icons.person_rounded),
  'receipt': (Icons.receipt_long_outlined, Icons.receipt_long_rounded),
  'heart': (Icons.favorite_border_rounded, Icons.favorite_rounded),
  'search': (Icons.search_rounded, Icons.search_rounded),
  'headset': (Icons.support_agent_outlined, Icons.support_agent_rounded),
  'bell': (Icons.notifications_none_rounded, Icons.notifications_rounded),
  'tag': (Icons.sell_outlined, Icons.sell_rounded),
  'star': (Icons.star_outline_rounded, Icons.star_rounded),
  'store': (Icons.storefront_outlined, Icons.storefront_rounded),
  'gift': (Icons.card_giftcard_outlined, Icons.card_giftcard_rounded),
  'qr': (Icons.qr_code_scanner_rounded, Icons.qr_code_scanner_rounded),
};

/// One switched-on item of the footer.
@immutable
class FooterEntry {
  const FooterEntry(this.key, {this.icon, this.labelEn, this.labelAr});

  final String key;
  final String? icon;
  final String? labelEn;
  final String? labelAr;

  FooterDestination get destination => footerDestinations[key]!;

  (IconData, IconData) get icons => (footerIcons[icon] ?? (destination.icon, destination.iconOn));

  /// The dashboard's own word for this language, else the app's translated one.
  String labelFor(AppLocalizations l10n, String languageCode) {
    final custom = switch (languageCode) {
      'ar' => labelAr,
      'en' => labelEn,
      _ => null,
    };
    return (custom != null && custom.trim().isNotEmpty) ? custom.trim() : destination.label(l10n);
  }

  @override
  bool operator ==(Object other) =>
      other is FooterEntry &&
      other.key == key &&
      other.icon == icon &&
      other.labelEn == labelEn &&
      other.labelAr == labelAr;

  @override
  int get hashCode => Object.hash(key, icon, labelEn, labelAr);
}

/// The bottom menu as the dashboard configured it. [items] holds only the switched-on entries, in order.
@immutable
class FooterConfig {
  const FooterConfig({
    required this.design,
    required this.items,
    this.showLabels = true,
    this.cartBadge = true,
    this.accent,
  });

  /// The original five-tab menu: what the app shows before the server answers, offline, and when the config is invalid.
  static const fallback = FooterConfig(
    design: FooterDesign.classic,
    items: [
      FooterEntry('home'),
      FooterEntry('categories'),
      FooterEntry('auctions'),
      FooterEntry('cart'),
      FooterEntry('account'),
    ],
  );

  /// The menu the "fresh" design uses until the dashboard customises the footer: Shop is the raised middle button.
  static const centerAction = FooterConfig(
    design: FooterDesign.centerAction,
    items: [
      FooterEntry('home'),
      FooterEntry('auctions'),
      FooterEntry('categories'),
      FooterEntry('cart'),
      FooterEntry('account'),
    ],
  );

  static const _hex = r'^#[0-9a-fA-F]{6}$';

  /// Returns null when the payload cannot be trusted (unknown design, fewer than 3 items, no Home...): the app then
  /// keeps the built-in footer rather than showing a broken one.
  static FooterConfig? fromJson(Object? raw) {
    if (raw is! Map) return null;
    final j = asMap(raw);
    final design = FooterDesign.values.where((d) => d.wire == j['design']).firstOrNull;
    if (design == null) return null;
    final list = j['items'];
    if (list is! List || list.length > 5) return null;
    final seen = <String>{};
    final items = <FooterEntry>[];
    for (final e in list) {
      if (e is! Map) return null;
      final m = asMap(e);
      final key = m['key'];
      if (key is! String || !footerDestinations.containsKey(key) || !seen.add(key)) return null;
      if (m['enabled'] == false) continue;
      final icon = m['icon'];
      items.add(
        FooterEntry(
          key,
          icon: icon is String && footerIcons.containsKey(icon) ? icon : null,
          labelEn: asStringOrNull(m['label_en']),
          labelAr: asStringOrNull(m['label_ar']),
        ),
      );
    }
    if (items.length < 3 || !seen.contains('home') || !items.any((i) => i.key == 'home')) return null;
    final accent = j['accent'];
    return FooterConfig(
      design: design,
      items: List.unmodifiable(items),
      showLabels: j['show_labels'] != false,
      cartBadge: j['cart_badge'] != false,
      accent: accent is String && RegExp(_hex).hasMatch(accent)
          ? Color(int.parse('FF${accent.substring(1)}', radix: 16))
          : null,
    );
  }

  final FooterDesign design;
  final List<FooterEntry> items;
  final bool showLabels;
  final bool cartBadge;

  /// Null means "follow the current look's main colour".
  final Color? accent;

  List<String> get keys => [for (final i in items) i.key];

  @override
  bool operator ==(Object other) =>
      other is FooterConfig &&
      other.design == design &&
      other.showLabels == showLabels &&
      other.cartBadge == cartBadge &&
      other.accent == accent &&
      other.items.length == items.length &&
      [for (var i = 0; i < items.length; i++) other.items[i] == items[i]].every((e) => e);

  @override
  int get hashCode => Object.hash(design, showLabels, cartBadge, accent, Object.hashAll(items));
}

/// Which item of [keys] a page belongs to. A page whose own item is not in the footer counts under its parent (an order
/// page under Account, search under Shop); anything else falls back to Home.
int footerIndexForKeys(String path, List<String> keys) {
  final owner = footerDestinations.values.where((d) => d.owns(path)).firstOrNull;
  var key = owner?.key;
  while (key != null && !keys.contains(key)) {
    key = footerDestinations[key]?.parent;
  }
  final i = key == null ? -1 : keys.indexOf(key);
  if (i >= 0) return i;
  final home = keys.indexOf('home');
  return home < 0 ? 0 : home;
}
