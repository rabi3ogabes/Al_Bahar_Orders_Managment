import 'package:albahar_customer/core/realtime/live_sync.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';

/// The words of the three connection states, in the language of the screen it sits on.
@immutable
class LiveLinkLabels {
  const LiveLinkLabels({required this.live, required this.reconnecting, required this.offline});
  final String live;
  final String reconnecting;
  final String offline;
}

/// A small, quiet pill that says whether the screen is following the server live: a green dot and "Live", an amber one and
/// "Reconnecting…", or a grey one and "Offline". It sits in an app bar or beside a heading, never over content, and changes colour
/// softly (no flashing). Read aloud as a status by screen readers.
class LiveLinkBadge extends StatelessWidget {
  const LiveLinkBadge({super.key, required this.link, required this.labels, this.showLabel = true});

  final LiveLink link;
  final LiveLinkLabels labels;

  /// Only the dot (for a very narrow bar); the words are still read aloud and shown as a tooltip.
  final bool showLabel;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final (Color color, String text) = switch (link) {
      LiveLink.live => (palette.success, labels.live),
      LiveLink.reconnecting => (palette.warning, labels.reconnecting),
      LiveLink.offline => (palette.muted, labels.offline),
    };
    final dot = AnimatedContainer(
      duration: AppDurations.medium,
      width: 8,
      height: 8,
      decoration: BoxDecoration(color: color, shape: BoxShape.circle),
    );
    return Semantics(
      liveRegion: true,
      label: text,
      excludeSemantics: true,
      child: Tooltip(
        message: text,
        child: Container(
          constraints: const BoxConstraints(minHeight: 24),
          padding: const EdgeInsetsDirectional.symmetric(horizontal: AppSpace.xs, vertical: 2),
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.10),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(color: color.withValues(alpha: 0.28)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              dot,
              if (showLabel) ...[
                const SizedBox(width: 6),
                AnimatedDefaultTextStyle(
                  duration: AppDurations.medium,
                  style: (Theme.of(context).textTheme.labelSmall ?? const TextStyle()).copyWith(
                    color: color,
                    fontWeight: FontWeight.w700,
                  ),
                  child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
