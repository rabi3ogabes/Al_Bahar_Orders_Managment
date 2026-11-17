import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/contrast.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/features/deals/domain/deal_countdown.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_card_data.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_timer.dart';
import 'package:flutter/material.dart';

/// The deal widget: one widget for the five styles the admin can pick (`classic`, `bold`, `minimal`, `luxe`, `ribbon`),
/// the three shapes and the three countdown looks. It draws only what the server's design says and takes its colours
/// from `design.colors` (never invented here).
///
/// The countdown reads the screen's shared clock ([DealClockScope]); with none above it, it shows the moment it was built.
/// A screen reader hears one label that changes once a minute.
class DealWidget extends StatelessWidget {
  const DealWidget({super.key, required this.data, this.onTap, this.compact = false, this.showButton = true});

  final DealCardData data;

  /// Makes the whole widget one button (the Deals list, the home strip). Null on a page that is already the deal.
  final VoidCallback? onTap;

  /// A smaller widget for lists and the home strip: no tagline, no button.
  final bool compact;

  /// Draws the "Shop the deal" button (never on a compact widget). Off on the product page, where the shopper is already there.
  final bool showButton;

  @override
  Widget build(BuildContext context) {
    final v = _DealView.of(context, data, compact: compact, tappable: onTap != null, showButton: showButton);
    final d = data.design;
    final radius = BorderRadius.circular(v.cardRadius);
    final dark = Theme.of(context).brightness == Brightness.dark;

    Widget content = switch (d.style) {
      DealStyle.classic => _Classic(v),
      DealStyle.bold => _Bold(v),
      DealStyle.minimal => _Minimal(v),
      DealStyle.luxe => _Luxe(v),
      DealStyle.ribbon => _Ribbon(v),
    };
    if (onTap != null) {
      // The footer and the screens sit above their own Material: the ripple needs one right here.
      content = Material(
        type: MaterialType.transparency,
        child: InkWell(onTap: onTap, borderRadius: radius, child: content),
      );
    }

    final luxe = d.style == DealStyle.luxe;
    final minimal = d.style == DealStyle.minimal;
    final bg = v.colors.background;
    final box = DecoratedBox(
      decoration: BoxDecoration(
        color: bg,
        gradient: luxe
            ? LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [Color.lerp(bg, Colors.white, 0.07)!, bg, Color.lerp(bg, Colors.black, 0.22)!],
              )
            : null,
        borderRadius: radius,
        border: luxe
            ? Border.all(color: v.colors.accent.withValues(alpha: 0.7))
            : (minimal ? Border.all(color: v.colors.text.withValues(alpha: 0.22)) : null),
        boxShadow: dark || minimal
            ? null
            : [BoxShadow(color: bg.withValues(alpha: 0.28), blurRadius: 20, offset: const Offset(0, 8))],
      ),
      child: ClipRRect(
        borderRadius: radius,
        child: Stack(
          children: [
            if (d.imageUrl != null) ...[
              Positioned.fill(child: AppNetworkImage(url: d.imageUrl)),
              // The picture sits behind the words: the widget's own background, strong enough to read on any photo.
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: AlignmentDirectional.centerStart,
                      end: AlignmentDirectional.centerEnd,
                      colors: [bg.withValues(alpha: 0.94), bg.withValues(alpha: 0.7)],
                    ),
                  ),
                ),
              ),
            ],
            if (luxe)
              Positioned.fill(
                child: Padding(
                  padding: const EdgeInsets.all(5),
                  child: DecoratedBox(
                    decoration: BoxDecoration(
                      borderRadius: BorderRadius.circular((v.cardRadius - 5).clamp(2, 40)),
                      border: Border.all(color: v.colors.accent.withValues(alpha: 0.28)),
                    ),
                  ),
                ),
              ),
            content,
          ],
        ),
      ),
    );

    return Semantics(
      container: true,
      button: onTap != null,
      label: v.spoken,
      onTap: onTap,
      excludeSemantics: true,
      child: box,
    );
  }
}

/// Everything the five styles share, worked out once per build from the data, the clock and the theme.
class _DealView {
  _DealView._({
    required this.data,
    required this.l10n,
    required this.lang,
    required this.colors,
    required this.phase,
    required this.secondsLeft,
    required this.headline,
    required this.cta,
    required this.compact,
    required this.tappable,
    required this.urgent,
  });

  factory _DealView.of(
    BuildContext context,
    DealCardData data, {
    required bool compact,
    required bool tappable,
    required bool showButton,
  }) {
    final l10n = context.l10n;
    final lang = context.localeCode;
    final d = data.design;
    final now = DealClockScope.nowOf(context);
    final phase = data.phaseAt(now);
    final counting = phase == DealPhase.live || phase == DealPhase.scheduled;
    final left = counting ? dealSecondsLeft(phase == DealPhase.scheduled ? data.startsAt : data.endsAt, now) : null;
    final raw = d.colors ?? DealPalette.fromTheme(context);
    // The accent is used as text and as a bar on the background: keep it readable even when the admin's pick is faint.
    final colors = DealColors(
      background: raw.background,
      text: raw.text,
      accent: Contrast.ensure(raw.accent, raw.background, min: 3),
    );
    final live = phase == DealPhase.live;
    return _DealView._(
      data: data,
      l10n: l10n,
      lang: lang,
      colors: colors,
      phase: phase,
      secondsLeft: left,
      // "Ends in" with no end time to follow would only mislead: such a deal has no headline.
      headline: left == null && (phase == DealPhase.live || phase == DealPhase.scheduled)
          ? ''
          : (phase == DealPhase.scheduled ? l10n.dealStartsIn : (d.headlineFor(lang) ?? l10n.dealEndsIn)),
      cta: live && !compact && showButton ? (d.ctaFor(lang) ?? l10n.dealShopCta) : null,
      compact: compact,
      tappable: tappable,
      urgent: live && dealIsUrgent(left, d.urgencyMinutes),
    );
  }

  final DealCardData data;
  final AppLocalizations l10n;
  final String lang;
  final DealColors colors;
  final DealPhase phase;
  final int? secondsLeft;
  final String headline;

  /// The button words, or null when there is no button (compact, or the deal is not on sale).
  final String? cta;
  final bool compact;
  final bool tappable;
  final bool urgent;

  DealDesign get design => data.design;
  bool get counting => secondsLeft != null;
  bool get live => phase == DealPhase.live;
  Color get onAccent => Contrast.onColor(colors.accent);

  double get cardRadius => switch (design.shape) {
    DealShape.rounded => 20,
    DealShape.sharp => 4,
    DealShape.pill => compact ? 28 : 36,
  };

  double get innerRadius => switch (design.shape) {
    DealShape.rounded => 10,
    DealShape.sharp => 2,
    DealShape.pill => 999,
  };

  EdgeInsets get padding {
    final h = design.shape == DealShape.pill ? 22.0 : 16.0;
    return EdgeInsets.fromLTRB(h, compact ? 12 : 16, h, compact ? 12 : 16);
  }

  int? get percent => design.percentBadge ? data.percent : null;

  /// The one label a screen reader hears: it changes once a minute (the seconds are never spoken).
  String get spoken {
    final parts = <String>[data.name];
    if (counting && secondsLeft != null) {
      parts.add('$headline ${dealSpokenTime(l10n, secondsLeft!)}');
    } else if (phase == DealPhase.soldOut) {
      parts.add(l10n.dealSoldOutNotice);
    } else if (phase != DealPhase.live && phase != DealPhase.scheduled) {
      parts.add(l10n.dealEnded);
    }
    if (percent != null) parts.add(l10n.dealOffSpoken(percent!));
    if (live && design.stockBar && data.remaining != null) parts.add(l10n.onlyLeft(data.remaining!));
    return parts.join('. ');
  }

  TextStyle? title(BuildContext context, {bool display = false}) {
    final base = compact ? context.text.titleMedium : context.text.titleLarge;
    return base?.copyWith(
      color: colors.text,
      fontWeight: FontWeight.w700,
      fontFamily: display && lang != 'ar' ? AppFonts.display : base.fontFamily,
    );
  }

  TextStyle? small(BuildContext context, {double alpha = 0.82, bool bold = false}) =>
      context.text.labelMedium?.copyWith(
        color: colors.text.withValues(alpha: alpha),
        fontWeight: bold ? FontWeight.w700 : null,
        letterSpacing: 0,
      );

  /// The icon of the design, in a disc of the accent colour.
  Widget iconDisc({double size = 36}) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(color: colors.accent, shape: BoxShape.circle),
    child: Icon(dealIconData(design.icon), color: onAccent, size: size * 0.58),
  );

  /// The "-30%" pill (null when the design leaves it out or the rule is not a percentage).
  Widget? percentBadge({Color? background, Color? foreground, bool outlined = false, double fontSize = 14}) {
    final p = percent;
    if (p == null) return null;
    final bg = background ?? colors.accent;
    final fg = foreground ?? onAccent;
    return Container(
      padding: EdgeInsets.symmetric(horizontal: fontSize * 0.7, vertical: fontSize * 0.3),
      decoration: BoxDecoration(
        color: outlined ? Colors.transparent : bg,
        borderRadius: BorderRadius.circular(design.shape == DealShape.sharp ? 3 : 999),
        border: outlined ? Border.all(color: colors.accent) : null,
      ),
      child: Text(
        l10n.discountOff(p),
        textDirection: TextDirection.ltr,
        maxLines: 1,
        style: TextStyle(
          color: outlined ? colors.accent : fg,
          fontSize: fontSize,
          fontWeight: FontWeight.w800,
          height: 1.1,
        ),
      ),
    );
  }

  Widget timer({
    DealTimerSize? size,
    bool display = false,
    AlignmentGeometry align = AlignmentDirectional.centerStart,
  }) {
    final parts = splitDealTime(secondsLeft ?? 0, design.units);
    return DealTimerView(
      parts: parts,
      look: design.look,
      shape: design.shape,
      text: colors.text,
      accent: colors.accent,
      urgent: urgent,
      size: size ?? (compact ? DealTimerSize.small : DealTimerSize.medium),
      secondsLeft: secondsLeft,
      display: display,
      align: align,
    );
  }

  /// What stands where the countdown would be when the deal is not counting: sold out, or over.
  Widget status(BuildContext context, {AlignmentGeometry align = AlignmentDirectional.centerStart}) {
    final soldOut = phase == DealPhase.soldOut;
    return Align(
      alignment: align,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(
          color: colors.text.withValues(alpha: 0.14),
          borderRadius: BorderRadius.circular(innerRadius.clamp(2, 999)),
        ),
        child: Text(soldOut ? l10n.dealSoldOutNotice : l10n.dealEnded, style: small(context, alpha: 1, bold: true)),
      ),
    );
  }

  /// "Only 12 left" and a bar that drains as the stock sells. Null when the design or the deal has no stock to show.
  Widget? stock(BuildContext context, {double height = 6}) {
    final left = data.remaining;
    if (!design.stockBar || !live || left == null) return null;
    final fraction = data.remainingPercent == null ? null : data.remainingPercent! / 100;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          l10n.onlyLeft(left),
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: small(context, alpha: 0.95, bold: true),
        ),
        if (fraction != null) ...[
          const SizedBox(height: 5),
          ClipRRect(
            borderRadius: BorderRadius.circular(height),
            child: Stack(
              children: [
                Container(height: height, color: colors.text.withValues(alpha: 0.2)),
                FractionallySizedBox(
                  widthFactor: fraction.clamp(0.04, 1.0),
                  child: Container(height: height, color: colors.accent),
                ),
              ],
            ),
          ),
        ],
      ],
    );
  }

  /// The "Shop the deal" button: drawn only, the whole widget is the tap target.
  Widget? ctaButton(BuildContext context, {bool outlined = false, bool stretch = false}) {
    final text = cta;
    if (text == null) return null;
    final radius = BorderRadius.circular(
      design.shape == DealShape.pill ? 999 : (design.shape == DealShape.sharp ? 3 : 12),
    );
    final fg = outlined ? colors.accent : onAccent;
    final button = Container(
      constraints: const BoxConstraints(minHeight: 44),
      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
      decoration: BoxDecoration(
        color: outlined ? Colors.transparent : colors.accent,
        borderRadius: radius,
        border: outlined ? Border.all(color: colors.accent, width: 1.4) : null,
      ),
      child: Row(
        mainAxisSize: stretch ? MainAxisSize.max : MainAxisSize.min,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Flexible(
            child: Text(
              text,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: context.text.labelLarge?.copyWith(color: fg, fontWeight: FontWeight.w700),
            ),
          ),
          const SizedBox(width: 6),
          Icon(
            Directionality.of(context) == TextDirection.rtl ? Icons.arrow_back_rounded : Icons.arrow_forward_rounded,
            color: fg,
            size: 18,
          ),
        ],
      ),
    );
    return Align(alignment: stretch ? Alignment.center : AlignmentDirectional.centerStart, child: button);
  }

  Widget? tagline(BuildContext context, {TextAlign align = TextAlign.start}) {
    final t = data.tagline;
    if (compact || t == null || t.isEmpty) return null;
    return Text(t, textAlign: align, maxLines: 2, overflow: TextOverflow.ellipsis, style: small(context, alpha: 0.88));
  }

  /// The countdown, or the word for why there is none (sold out, over). Null for a live or upcoming deal with no time to count to:
  /// nothing is drawn then, never a false "ended".
  Widget? clockOrStatus(
    BuildContext context, {
    DealTimerSize? size,
    bool display = false,
    AlignmentGeometry align = AlignmentDirectional.centerStart,
  }) {
    if (counting) return timer(size: size, display: display, align: align);
    if (phase == DealPhase.live || phase == DealPhase.scheduled) return null;
    return status(context, align: align);
  }
}

/// Stacks [children] with [gap] between them, leaving out the nulls.
List<Widget> _spaced(List<Widget?> children, double gap) {
  final shown = children.whereType<Widget>().toList();
  return [
    for (var i = 0; i < shown.length; i++) ...[if (i > 0) SizedBox(height: gap), shown[i]],
  ];
}

/// Classic: a solid card. Icon and headline on top with the percent badge, the name, the countdown in boxes, the stock bar and the button.
class _Classic extends StatelessWidget {
  const _Classic(this.v);
  final _DealView v;

  @override
  Widget build(BuildContext context) {
    final badge = v.percentBadge();
    return Padding(
      padding: v.padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: _spaced([
          Row(
            children: [
              v.iconDisc(size: v.compact ? 32 : 40),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (v.headline.isNotEmpty)
                      Text(v.headline, maxLines: 1, overflow: TextOverflow.ellipsis, style: v.small(context)),
                    Text(v.data.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: v.title(context)),
                  ],
                ),
              ),
              if (badge != null) ...[const SizedBox(width: 10), badge],
            ],
          ),
          v.tagline(context),
          v.clockOrStatus(context),
          v.stock(context),
          v.ctaButton(context),
        ], 12),
      ),
    );
  }
}

/// Bold: a big accent block on the start side with the percent (or the icon), the headline in capitals, the name and large digits.
class _Bold extends StatelessWidget {
  const _Bold(this.v);
  final _DealView v;

  @override
  Widget build(BuildContext context) {
    final p = v.percent;
    final block = Container(
      width: v.compact ? 76 : 96,
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
      decoration: BoxDecoration(
        color: v.colors.accent,
        borderRadius: BorderRadius.circular(v.innerRadius.clamp(2, 22)),
      ),
      // The block is as tall as the text beside it, its content in the middle.
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(dealIconData(v.design.icon), color: v.onAccent, size: v.compact ? 20 : 26),
          if (p != null)
            FittedBox(
              fit: BoxFit.scaleDown,
              child: Text(
                v.l10n.discountOff(p),
                textDirection: TextDirection.ltr,
                style: TextStyle(
                  color: v.onAccent,
                  fontSize: v.compact ? 24 : 32,
                  fontWeight: FontWeight.w900,
                  height: 1.15,
                ),
              ),
            ),
        ],
      ),
    );
    return Padding(
      padding: v.padding,
      child: IntrinsicHeight(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            block,
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                mainAxisSize: MainAxisSize.min,
                children: _spaced([
                  if (v.headline.isNotEmpty)
                    Text(
                      v.headline.toUpperCase(),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: v
                          .small(context, alpha: 1, bold: true)
                          ?.copyWith(color: v.colors.accent, letterSpacing: v.lang == 'ar' ? 0 : 1.2),
                    ),
                  Text(
                    v.data.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: v.title(context)?.copyWith(fontSize: v.compact ? 17 : 21, fontWeight: FontWeight.w800),
                  ),
                  v.tagline(context),
                  v.clockOrStatus(context, size: v.compact ? DealTimerSize.small : DealTimerSize.large),
                  v.stock(context),
                  v.ctaButton(context),
                ], 8),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Minimal: no heavy fill, a hairline frame, one calm row with the countdown inline.
class _Minimal extends StatelessWidget {
  const _Minimal(this.v);
  final _DealView v;

  @override
  Widget build(BuildContext context) {
    final badge = v.percentBadge(outlined: true, fontSize: 13);
    return Padding(
      padding: v.padding,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: _spaced([
          Row(
            children: [
              Icon(dealIconData(v.design.icon), color: v.colors.accent, size: 20),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  v.data.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: v.title(context)?.copyWith(fontSize: 16),
                ),
              ),
              if (badge != null) ...[const SizedBox(width: 8), badge],
            ],
          ),
          v.tagline(context),
          if (v.clockOrStatus(context, size: DealTimerSize.small) case final clock?)
            Row(
              children: [
                if (v.headline.isNotEmpty) ...[Text(v.headline, style: v.small(context)), const SizedBox(width: 10)],
                Expanded(child: clock),
              ],
            ),
          v.stock(context, height: 4),
          v.ctaButton(context, outlined: true),
        ], 10),
      ),
    );
  }
}

/// Luxe: a gradient card in a double gold frame, serif type, everything centred, an outlined button.
class _Luxe extends StatelessWidget {
  const _Luxe(this.v);
  final _DealView v;

  @override
  Widget build(BuildContext context) {
    final accent = v.colors.accent;
    Widget line() => Container(width: 26, height: 1, color: accent.withValues(alpha: 0.7));
    final badge = v.percentBadge(outlined: true, fontSize: 13);
    return Padding(
      padding: v.padding.add(const EdgeInsets.symmetric(horizontal: 6, vertical: 4)),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: _spaced([
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              line(),
              const SizedBox(width: 8),
              Icon(dealIconData(v.design.icon), color: accent, size: 16),
              const SizedBox(width: 8),
              if (v.headline.isNotEmpty) ...[
                Flexible(
                  child: Text(
                    v.headline.toUpperCase(),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: v
                        .small(context, alpha: 1, bold: true)
                        ?.copyWith(color: accent, letterSpacing: v.lang == 'ar' ? 0 : 2.2),
                  ),
                ),
                const SizedBox(width: 8),
              ],
              line(),
            ],
          ),
          Text(
            v.data.name,
            textAlign: TextAlign.center,
            maxLines: 2,
            overflow: TextOverflow.ellipsis,
            style: v.title(context, display: true)?.copyWith(fontSize: v.compact ? 20 : 24),
          ),
          v.tagline(context, align: TextAlign.center),
          v.clockOrStatus(context, display: true, align: Alignment.center),
          if (badge != null) Align(child: badge),
          v.stock(context, height: 3),
          v.ctaButton(context, outlined: true, stretch: true),
        ], 10),
      ),
    );
  }
}

/// Ribbon: an accent band across the top with an angled end (icon, headline and the percent on it), the rest underneath.
class _Ribbon extends StatelessWidget {
  const _Ribbon(this.v);
  final _DealView v;

  @override
  Widget build(BuildContext context) {
    final rtl = Directionality.of(context) == TextDirection.rtl;
    final p = v.percent;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        ClipPath(
          clipper: _RibbonClipper(rtl: rtl),
          child: Container(
            color: v.colors.accent,
            padding: EdgeInsetsDirectional.fromSTEB(v.padding.left, 9, v.padding.right + 14, 9),
            child: Row(
              children: [
                Icon(dealIconData(v.design.icon), color: v.onAccent, size: 20),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    v.headline,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: context.text.labelLarge?.copyWith(
                      color: v.onAccent,
                      fontWeight: FontWeight.w800,
                      letterSpacing: 0,
                    ),
                  ),
                ),
                if (p != null)
                  Text(
                    v.l10n.discountOff(p),
                    textDirection: TextDirection.ltr,
                    style: TextStyle(color: v.onAccent, fontSize: 18, fontWeight: FontWeight.w900),
                  ),
              ],
            ),
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(v.padding.left, 12, v.padding.right, v.padding.bottom),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            mainAxisSize: MainAxisSize.min,
            children: _spaced([
              Text(v.data.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: v.title(context)),
              v.tagline(context),
              v.clockOrStatus(context),
              v.stock(context),
              v.ctaButton(context),
            ], 10),
          ),
        ),
      ],
    );
  }
}

/// The band with a V cut into its end: the end side is the right in English and the left in Arabic.
class _RibbonClipper extends CustomClipper<Path> {
  const _RibbonClipper({required this.rtl});

  final bool rtl;

  @override
  Path getClip(Size size) {
    const notch = 12.0;
    final w = size.width;
    final h = size.height;
    final path = Path();
    if (rtl) {
      path
        ..moveTo(w, 0)
        ..lineTo(notch, 0)
        ..lineTo(0, h / 2)
        ..lineTo(notch, h)
        ..lineTo(w, h);
    } else {
      path
        ..moveTo(0, 0)
        ..lineTo(w - notch, 0)
        ..lineTo(w, h / 2)
        ..lineTo(w - notch, h)
        ..lineTo(0, h);
    }
    return path..close();
  }

  @override
  bool shouldReclip(covariant _RibbonClipper oldClipper) => oldClipper.rtl != rtl;
}
