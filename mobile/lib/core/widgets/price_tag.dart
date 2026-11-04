import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../l10n/l10n.dart';
import '../models/money.dart';
import '../theme/tokens.dart';

enum PriceTagSize { small, medium, large }

/// Renders a server [EffectivePrice]: final price, struck-through regular
/// price and discount badge when on sale. Never computes prices.
class PriceTag extends StatelessWidget {
  const PriceTag({super.key, required this.price, this.size = PriceTagSize.medium, this.showBadge = true});

  final EffectivePrice price;
  final PriceTagSize size;
  final bool showBadge;

  @override
  Widget build(BuildContext context) {
    final text = context.text;
    final palette = context.palette;
    final main = switch (size) {
      PriceTagSize.small => text.titleSmall,
      PriceTagSize.medium => text.titleMedium,
      PriceTagSize.large => text.headlineSmall?.copyWith(fontWeight: FontWeight.w600),
    };
    final onSale = price.isOnSale;
    final semantic = onSale
        ? '${price.finalPrice.formatted}, ${price.regular.formatted}'
        : price.finalPrice.formatted;

    return Semantics(
      label: semantic,
      excludeSemantics: true,
      child: Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: AppSpace.xs,
        runSpacing: 2,
        children: [
          Text(
            price.finalPrice.formatted,
            style: main?.copyWith(color: onSale ? palette.danger : null),
          ),
          if (onSale)
            Text(
              price.regular.formatted,
              style: (size == PriceTagSize.large ? text.bodyMedium : text.bodySmall)?.copyWith(
                decoration: TextDecoration.lineThrough,
                color: palette.muted,
              ),
            ),
          if (onSale && showBadge && (price.discountPercent ?? 0) > 0)
            DiscountBadge(percent: price.discountPercent!),
        ],
      ),
    );
  }
}

class DiscountBadge extends StatelessWidget {
  const DiscountBadge({super.key, required this.percent});
  final int percent;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsetsDirectional.symmetric(horizontal: 6, vertical: 2),
    decoration: BoxDecoration(
      color: context.palette.danger.withValues(alpha: 0.1),
      borderRadius: BorderRadius.circular(6),
    ),
    child: Text(
      context.l10n.discountOff(percent),
      style: context.text.labelSmall?.copyWith(color: context.palette.danger, letterSpacing: 0),
    ),
  );
}

/// Plain server-formatted money.
class MoneyText extends StatelessWidget {
  const MoneyText(this.money, {super.key, this.style, this.emphasize = false});
  final Money money;
  final TextStyle? style;
  final bool emphasize;

  @override
  Widget build(BuildContext context) => Text(
    money.formatted,
    style: (style ?? context.text.bodyMedium)?.copyWith(fontWeight: emphasize ? FontWeight.w700 : null),
  );
}

/// Animated counter for live values (e.g. current bid). The animation is
/// purely cosmetic; once settled it shows the server's [Money.formatted].
class AnimatedMoney extends StatefulWidget {
  const AnimatedMoney({super.key, required this.money, this.style, this.duration = const Duration(milliseconds: 650)});

  final Money money;
  final TextStyle? style;
  final Duration duration;

  @override
  State<AnimatedMoney> createState() => _AnimatedMoneyState();
}

class _AnimatedMoneyState extends State<AnimatedMoney> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(vsync: this, duration: widget.duration);
  late double _from = widget.money.displayValue;
  late double _to = widget.money.displayValue;
  bool _flash = false;

  @override
  void didUpdateWidget(covariant AnimatedMoney oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.money.amount != widget.money.amount) {
      _from = _current;
      _to = widget.money.displayValue;
      _flash = _to > _from;
      _controller.forward(from: 0).whenComplete(() {
        if (mounted) setState(() => _flash = false);
      });
    }
  }

  double get _current => _from + (_to - _from) * Curves.easeOutCubic.transform(_controller.value);

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final style = widget.style ?? context.text.headlineMedium;
    return AnimatedBuilder(
      animation: _controller,
      builder: (context, _) {
        final settled = !_controller.isAnimating;
        final text = settled ? widget.money.formatted : _interim(context);
        return AnimatedDefaultTextStyle(
          duration: AppDurations.medium,
          style: style!.copyWith(color: _flash ? context.palette.success : style.color),
          child: Semantics(
            liveRegion: true,
            label: widget.money.formatted,
            excludeSemantics: true,
            child: Text(text),
          ),
        );
      },
    );
  }

  String _interim(BuildContext context) {
    final f = NumberFormat.decimalPatternDigits(locale: context.localeCode, decimalDigits: 0).format(_current.round());
    // Keep currency position consistent with the server format.
    final formatted = widget.money.formatted;
    final digits = RegExp(r'[\d٠-٩][\d٠-٩,٬.٫]*').firstMatch(formatted);
    if (digits == null) return '${widget.money.currency} $f';
    return formatted.replaceRange(digits.start, digits.end, f);
  }
}
