import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/json.dart';
import 'package:flutter/material.dart';

/// Which complete design the dashboard switched on. `classic` is the look the app always had.
enum DesignLayout { classic, fresh }

enum DesignRadius { md, lg, xl }

enum DesignCardStyle { flat, soft, outlined }

enum DesignHeader { standard, brand }

enum DesignNav { classic, centerAction }

enum DesignBackground { plain, gradient }

enum DesignDensity { comfortable, compact }

/// The small set of switches the dashboard sends under `tokens` (plus `layout`). Every shared widget reads them from the
/// theme (`context.design`), so each screen inherits the chosen look without knowing about it.
///
/// Parsing is defensive: a missing or unknown value falls back to the default of the chosen layout, never to a crash.
@immutable
class DesignTokens extends ThemeExtension<DesignTokens> {
  const DesignTokens({
    this.layout = DesignLayout.classic,
    this.radius = DesignRadius.lg,
    this.card = DesignCardStyle.soft,
    this.header = DesignHeader.standard,
    this.nav = DesignNav.classic,
    this.background = DesignBackground.plain,
    this.density = DesignDensity.comfortable,
  });

  /// What the app showed before designs existed; also what it uses while offline with nothing cached.
  static const classic = DesignTokens();

  /// Defaults for the 'fresh' layout when the server sends the layout but no (or partial) tokens.
  static const fresh = DesignTokens(
    layout: DesignLayout.fresh,
    radius: DesignRadius.xl,
    card: DesignCardStyle.soft,
    header: DesignHeader.brand,
    nav: DesignNav.centerAction,
    background: DesignBackground.gradient,
  );

  static T _pick<T extends Enum>(List<T> values, Object? raw, T fallback, {Map<String, T> alias = const {}}) {
    if (raw is! String) return fallback;
    final key = raw.trim().toLowerCase();
    return alias[key] ?? values.where((v) => v.name.toLowerCase() == key).firstOrNull ?? fallback;
  }

  /// `layout` is the payload's `layout` value, [tokens] its `tokens` map. Either may be missing or garbage.
  static DesignTokens fromJson(Object? layout, Object? tokens) {
    final chosen = _pick(DesignLayout.values, layout, DesignLayout.classic);
    final base = chosen == DesignLayout.fresh ? fresh : classic;
    final t = asMap(tokens);
    return DesignTokens(
      layout: chosen,
      radius: _pick(DesignRadius.values, t['radius'], base.radius),
      card: _pick(DesignCardStyle.values, t['card'], base.card),
      header: _pick(DesignHeader.values, t['header'], base.header),
      nav: _pick(DesignNav.values, t['nav'], base.nav, alias: const {'center_action': DesignNav.centerAction}),
      background: _pick(DesignBackground.values, t['background'], base.background),
      density: _pick(DesignDensity.values, t['density'], base.density),
    );
  }

  final DesignLayout layout;
  final DesignRadius radius;
  final DesignCardStyle card;
  final DesignHeader header;
  final DesignNav nav;
  final DesignBackground background;
  final DesignDensity density;

  bool get isFresh => layout == DesignLayout.fresh;
  bool get brandHeader => header == DesignHeader.brand;
  bool get gradientBackground => background == DesignBackground.gradient;
  bool get compact => density == DesignDensity.compact;

  /// Corner of a card: md 14, lg 20, xl 28.
  double get cardRadius => switch (radius) {
    DesignRadius.md => 14,
    DesignRadius.lg => 20,
    DesignRadius.xl => 28,
  };

  /// Corner of an input, a tile or an image inside a card.
  double get controlRadius => switch (radius) {
    DesignRadius.md => 12,
    DesignRadius.lg => 16,
    DesignRadius.xl => 20,
  };

  BorderRadius get cardBorderRadius => BorderRadius.circular(cardRadius);
  BorderRadius get controlBorderRadius => BorderRadius.circular(controlRadius);

  /// Primary buttons are full pills with a trailing arrow in the fresh design.
  bool get pillButtons => isFresh;

  /// Page gutter: a little tighter when compact.
  double get gutter => compact ? 16 : 20;

  /// Vertical rhythm multiplier for list spacing.
  double get spacing => compact ? 0.75 : 1;

  VisualDensity get visualDensity => compact ? VisualDensity.compact : VisualDensity.standard;

  @override
  DesignTokens copyWith({
    DesignLayout? layout,
    DesignRadius? radius,
    DesignCardStyle? card,
    DesignHeader? header,
    DesignNav? nav,
    DesignBackground? background,
    DesignDensity? density,
  }) => DesignTokens(
    layout: layout ?? this.layout,
    radius: radius ?? this.radius,
    card: card ?? this.card,
    header: header ?? this.header,
    nav: nav ?? this.nav,
    background: background ?? this.background,
    density: density ?? this.density,
  );

  /// Tokens are discrete switches: they flip at the half way point.
  @override
  DesignTokens lerp(ThemeExtension<DesignTokens>? other, double t) => other is DesignTokens && t >= 0.5 ? other : this;

  @override
  bool operator ==(Object other) =>
      other is DesignTokens &&
      other.layout == layout &&
      other.radius == radius &&
      other.card == card &&
      other.header == header &&
      other.nav == nav &&
      other.background == background &&
      other.density == density;

  @override
  int get hashCode => Object.hash(layout, radius, card, header, nav, background, density);
}

extension DesignContextX on BuildContext {
  /// The look chosen in the dashboard; the classic one when the theme carries none (plain `ThemeData`, tests).
  DesignTokens get design => Theme.of(this).extension<DesignTokens>() ?? DesignTokens.classic;
}

extension DesignCardX on BuildContext {
  /// Surface decoration for a hand-drawn card (product tiles and the like) in the current card style:
  /// soft = a shadow, outlined = a hairline, flat = neither. Dark mode always uses the hairline instead of a shadow.
  BoxDecoration cardDecoration({Color? color, double? radius}) {
    final d = design;
    final dark = Theme.of(this).brightness == Brightness.dark;
    final line = palette.line;
    return BoxDecoration(
      color: color ?? colors.surface,
      borderRadius: radius == null ? d.cardBorderRadius : BorderRadius.circular(radius),
      boxShadow: d.card == DesignCardStyle.soft && !dark
          ? (d.isFresh
                ? [BoxShadow(color: colors.primary.withValues(alpha: 0.10), blurRadius: 24, offset: const Offset(0, 8))]
                : kSoftShadow)
          : null,
      border: (d.card == DesignCardStyle.outlined || (dark && d.card == DesignCardStyle.soft))
          ? Border.all(color: line)
          : null,
    );
  }
}
