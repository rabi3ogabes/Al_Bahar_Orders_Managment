import 'package:albahar_customer/core/l10n/l10n.dart';

/// Short, localised wording for distances and ages shown on and around maps.
abstract final class MapFormat {
  /// `350 m` under a kilometre (rounded to 10 m), `2.4 km` above.
  static String distance(AppLocalizations l10n, double meters) {
    final m = meters.isFinite && meters > 0 ? meters : 0;
    if (m < 995) return l10n.mapDistanceM((((m / 10).round()) * 10).clamp(10, 990));
    return l10n.mapDistanceKm((m / 1000).toStringAsFixed(1));
  }

  /// `just now`, `4 min ago`, `2 h ago`.
  static String ago(AppLocalizations l10n, Duration age) {
    if (age.inSeconds < 45) return l10n.mapJustNow;
    if (age.inMinutes < 60) return l10n.mapMinutesAgo(age.inMinutes < 1 ? 1 : age.inMinutes);
    return l10n.mapHoursAgo(age.inHours);
  }

  /// `Last seen 4 min ago`.
  static String lastSeen(AppLocalizations l10n, Duration age) => l10n.mapLastSeen(ago(l10n, age));
}
