/// The words of "Share on WhatsApp" and the system share sheet (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md). Pure, so they are tested alone
/// (`test/features/cart/guest_cart_test.dart`, group "the words of a share"). The link is always a plain address (the store and the product's own page): no query, no tracking, nothing
/// about the person who shares.
library;

/// One line of a shared basket.
class ShareLine {
  const ShareLine({required this.name, required this.quantity, this.variantName, this.slug});
  final String name;
  final int quantity;
  final String? variantName;
  final String? slug;
}

/// `https://albahar.qa/products/oslo-sofa`: [origin] is the store's address (a trailing slash is fine), [slug] the product's own address.
String productShareUrl(String origin, String slug) =>
    '${origin.replaceAll(RegExp(r'/+$'), '')}/products/${Uri.encodeComponent(slug)}';

/// One product: the opening line, the name (with its option) in bold, the price and the link last, so the chat shows the product's picture card.
String productShareText({
  required String name,
  required String intro,
  required String url,
  String? variantName,
  String? priceLine,
}) {
  final title = variantName != null && variantName.isNotEmpty ? '$name ($variantName)' : name;
  return [intro, '', '*$title*', if (priceLine != null && priceLine.isNotEmpty) priceLine, '', url].join('\n');
}

/// A basket: each piece with its quantity and its link (the first fifteen), then the total.
String basketShareText(List<ShareLine> lines, {required String intro, required String total, required String origin}) {
  final rows = <String>[
    for (final l in lines.take(15))
      () {
        final what =
            '• ${l.name}${l.variantName != null && l.variantName!.isNotEmpty ? ' (${l.variantName})' : ''} × ${l.quantity}';
        return l.slug == null || l.slug!.isEmpty ? what : '$what\n  ${productShareUrl(origin, l.slug!)}';
      }(),
  ];
  final more = lines.length > 15 ? ['… +${lines.length - 15}'] : const <String>[];
  return [intro, '', ...rows, ...more, '', total].join('\n');
}

/// The link that opens WhatsApp's chat picker with [text] filled in (no phone number: the customer picks who).
Uri whatsAppShareUri(String text) => Uri.parse('https://wa.me/?text=${Uri.encodeComponent(text)}');
