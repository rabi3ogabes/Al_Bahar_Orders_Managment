/// Translates external links (custom scheme, universal links, push payloads)
/// into in-app router locations.
///
/// Supported (API contract §10):
///   albahar://products/{id}            https://albahar.qa/app/products/{id}
///   albahar://orders/{id}              …/app/orders/{id}
///   albahar://auctions/{id}            …/app/auctions/{id}
///   albahar://auctions/lots/{lotId}    …/app/auctions/lots/{lotId}
///   albahar://categories/{id}          …/app/categories/{id}
///   albahar://payments/{id}/return     …/app/payments/{id}/return
///   albahar://support/{ticketId}       …/app/support/{ticketId}
library;

abstract final class DeepLinks {
  static const scheme = 'albahar';
  static const webHost = 'albahar.qa';
  static const webPrefix = '/app';

  static final _patterns = <RegExp>[
    RegExp(r'^/products/[^/]+$'),
    RegExp(r'^/products$'),
    RegExp(r'^/orders/[^/]+$'),
    RegExp(r'^/orders$'),
    RegExp(r'^/auctions/lots/[^/]+$'),
    RegExp(r'^/auctions/[^/]+$'),
    RegExp(r'^/auctions$'),
    RegExp(r'^/categories/[^/]+$'),
    RegExp(r'^/categories$'),
    RegExp(r'^/payments/[^/]+/return$'),
    RegExp(r'^/support/[^/]+$'),
    RegExp(r'^/support$'),
    RegExp(r'^/notifications$'),
    RegExp(r'^/wishlist$'),
    RegExp(r'^/cart$'),
    RegExp(r'^/search$'),
    RegExp(r'^/home$'),
    RegExp(r'^/pages/[^/]+$'),
  ];

  /// Returns the in-app location for [uri], or null if it is not a
  /// recognised app link. Plain in-app paths (`/products/1`) pass through.
  static String? toLocation(Uri uri) {
    String path;
    if (uri.scheme == scheme) {
      // albahar://products/123 → host "products", path "/123"
      path = '/${uri.host}${uri.path}';
    } else if ((uri.scheme == 'https' || uri.scheme == 'http') && (uri.host == webHost || uri.host == 'www.$webHost')) {
      if (!uri.path.startsWith(webPrefix)) return null;
      path = uri.path.substring(webPrefix.length);
    } else if (uri.scheme.isEmpty) {
      path = uri.path.startsWith(webPrefix) ? uri.path.substring(webPrefix.length) : uri.path;
    } else {
      return null;
    }
    path = _normalisePath(path);
    if (path == '/' || path.isEmpty) return '/home';
    if (!_patterns.any((p) => p.hasMatch(path))) return null;
    final query = uri.query.isEmpty ? '' : '?${uri.query}';
    return '$path$query';
  }

  static String? fromString(String? link) {
    if (link == null || link.trim().isEmpty) return null;
    final uri = Uri.tryParse(link.trim());
    return uri == null ? null : toLocation(uri);
  }

  static String _normalisePath(String path) {
    var p = path.replaceAll(RegExp(r'/{2,}'), '/');
    if (p.length > 1 && p.endsWith('/')) p = p.substring(0, p.length - 1);
    if (!p.startsWith('/')) p = '/$p';
    return p;
  }

  /// Public share URL for a product (universal link form).
  static String productShareUrl(String idOrSlug) => 'https://$webHost$webPrefix/products/$idOrSlug';
}

/// Maps an FCM `data` payload (`{event, deep_link, notification_id, …}`)
/// to a router location. Falls back to event-specific ids, then to the
/// notifications centre so a tap always lands somewhere meaningful.
String notificationLocation(Map<String, dynamic> data) {
  final fromLink = DeepLinks.fromString(data['deep_link']?.toString());
  if (fromLink != null) return fromLink;

  final event = data['event']?.toString() ?? '';
  String? id(String key) {
    final v = data[key]?.toString();
    return (v == null || v.isEmpty) ? null : v;
  }

  if (event.startsWith('order.') && id('order_id') != null) return '/orders/${id('order_id')}';
  if (event.startsWith('payment.') && id('order_id') != null) return '/orders/${id('order_id')}';
  if (event.startsWith('auction.') || event.startsWith('lot.')) {
    if (id('lot_id') != null) return '/auctions/lots/${id('lot_id')}';
    if (id('auction_id') != null) return '/auctions/${id('auction_id')}';
  }
  if (event.startsWith('support.') && id('ticket_id') != null) return '/support/${id('ticket_id')}';
  if (event.startsWith('product.') && id('product_id') != null) return '/products/${id('product_id')}';
  return '/notifications';
}

/// Notification preference category for an event name.
String notificationCategory(String? event) {
  final e = event ?? '';
  if (e.startsWith('marketing.') || e.startsWith('promotion.') || e.startsWith('campaign.')) return 'marketing';
  if (e.startsWith('auction.') || e.startsWith('lot.')) return 'auction';
  return 'transactional';
}
