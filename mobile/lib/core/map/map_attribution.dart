import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// A link inside the attribution text.
class AttributionLink {
  const AttributionLink(this.label, this.url);
  final String label;
  final Uri url;
}

/// The map provider's credit line, cleaned for display: tags removed, entities decoded, links listed on their own.
class MapAttribution {
  const MapAttribution(this.text, this.links);

  static const _osmCopyright = 'https://www.openstreetmap.org/copyright';

  /// Accepts plain text (`© OpenStreetMap contributors`) or the HTML many tile providers send
  /// (`&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors`).
  factory MapAttribution.parse(String raw) {
    final links = <AttributionLink>[];
    for (final m in RegExp(
      r'''<a\s[^>]*href=["']([^"']+)["'][^>]*>(.*?)</a>''',
      caseSensitive: false,
      dotAll: true,
    ).allMatches(raw)) {
      final uri = Uri.tryParse(m[1]!.trim());
      final label = _plain(m[2]!);
      if (uri != null && uri.scheme == 'https' && uri.host.isNotEmpty && label.isNotEmpty) {
        links.add(AttributionLink(label, uri));
      }
    }
    final text = _plain(raw);
    // The OpenStreetMap licence asks for a way to reach its copyright page: add it when the text names OSM.
    if (links.isEmpty && text.toLowerCase().contains('openstreetmap')) {
      links.add(AttributionLink('OpenStreetMap', Uri.parse(_osmCopyright)));
    }
    return MapAttribution(text.isEmpty ? '© OpenStreetMap contributors' : text, links);
  }

  static String _plain(String html) => html
      .replaceAll(RegExp(r'<[^>]*>'), '')
      .replaceAll('&copy;', '©')
      .replaceAll('&amp;', '&')
      .replaceAll('&nbsp;', ' ')
      .replaceAll(RegExp(r'\s+'), ' ')
      .trim();

  final String text;
  final List<AttributionLink> links;
}

/// The credit line, always visible in a corner of the map and tappable for the full text and links.
class MapAttributionChip extends StatelessWidget {
  const MapAttributionChip({super.key, required this.attribution});
  final String attribution;

  @override
  Widget build(BuildContext context) {
    final parsed = MapAttribution.parse(attribution);
    final l10n = context.l10n;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final bg = (dark ? const Color(0xFF221A1E) : Colors.white).withValues(alpha: 0.88);
    final fg = dark ? const Color(0xFFD9D2D5) : const Color(0xFF4F3B43);
    return Semantics(
      button: true,
      label: '${l10n.mapAttributionTitle}: ${parsed.text}',
      excludeSemantics: true,
      onTap: () => _open(context, parsed),
      child: InkWell(
        onTap: () => _open(context, parsed),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: kMinTouchTarget, maxWidth: MediaQuery.sizeOf(context).width * 0.62),
          child: Center(
            widthFactor: 1,
            child: DecoratedBox(
              decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(AppRadius.pill)),
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                child: MediaQuery.withClampedTextScaling(
                  maxScaleFactor: 1.1,
                  child: Text(
                    parsed.text,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: Theme.of(context).textTheme.labelSmall?.copyWith(color: fg, fontSize: 10.5),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _open(BuildContext context, MapAttribution parsed) {
    final l10n = context.l10n;
    return showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(AppSpace.gutter, 0, AppSpace.gutter, AppSpace.md),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(l10n.mapAttributionTitle, style: Theme.of(ctx).textTheme.titleMedium),
              const Gap(AppSpace.xs),
              Text(parsed.text, style: Theme.of(ctx).textTheme.bodyMedium),
              const Gap(AppSpace.xs),
              for (final link in parsed.links)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  minTileHeight: 52,
                  leading: const Icon(Icons.open_in_new_rounded),
                  title: Text(l10n.mapOpenLink(link.label)),
                  onTap: () async {
                    try {
                      await launchUrl(link.url, mode: LaunchMode.externalApplication);
                    } catch (_) {
                      // No browser on this phone: nothing to do, the text above already carries the credit.
                    }
                  },
                ),
            ],
          ),
        ),
      ),
    );
  }
}
