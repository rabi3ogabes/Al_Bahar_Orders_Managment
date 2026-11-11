import 'package:flutter/material.dart';

import '../theme/tokens.dart';

/// Shimmering placeholder block. Respects reduced-motion settings.
class Skeleton extends StatefulWidget {
  const Skeleton({super.key, this.width, this.height = 14, this.radius = 8, this.shape = BoxShape.rectangle});

  const Skeleton.circle({super.key, required double size})
    : width = size,
      height = size,
      radius = 0,
      shape = BoxShape.circle;

  final double? width;
  final double height;
  final double radius;
  final BoxShape shape;

  @override
  State<Skeleton> createState() => _SkeletonState();
}

class _SkeletonState extends State<Skeleton> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1300),
  );

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final reduce = MediaQuery.maybeDisableAnimationsOf(context) ?? false;
    if (reduce) {
      _controller.stop();
    } else if (!_controller.isAnimating) {
      _controller.repeat();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    return ExcludeSemantics(
      child: AnimatedBuilder(
        animation: _controller,
        builder: (context, _) {
          final t = _controller.value;
          return Container(
            width: widget.width,
            height: widget.height,
            decoration: BoxDecoration(
              shape: widget.shape,
              borderRadius: widget.shape == BoxShape.circle ? null : BorderRadius.circular(widget.radius),
              gradient: LinearGradient(
                begin: Alignment(-1.5 + 3 * t, 0),
                end: Alignment(-0.5 + 3 * t, 0),
                colors: [palette.skeletonBase, palette.skeletonHighlight, palette.skeletonBase],
              ),
            ),
          );
        },
      ),
    );
  }
}

/// Placeholder matching [ProductCardView] proportions.
class ProductCardSkeleton extends StatelessWidget {
  const ProductCardSkeleton({super.key});

  @override
  Widget build(BuildContext context) => const Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      AspectRatio(
        aspectRatio: 4 / 5,
        child: Skeleton(radius: AppRadius.card),
      ),
      Gap(AppSpace.sm),
      Skeleton(width: 70, height: 10),
      Gap(AppSpace.xs),
      Skeleton(height: 14),
      Gap(AppSpace.xs),
      Skeleton(width: 90, height: 14),
    ],
  );
}

/// Generic list placeholder.
class ListSkeleton extends StatelessWidget {
  const ListSkeleton({super.key, this.count = 6, this.leading = true});
  final int count;
  final bool leading;

  @override
  Widget build(BuildContext context) => ListView.separated(
    physics: const NeverScrollableScrollPhysics(),
    padding: const EdgeInsets.all(AppSpace.gutter),
    itemCount: count,
    separatorBuilder: (_, _) => const Gap(AppSpace.md),
    itemBuilder: (_, _) => Row(
      children: [
        if (leading) ...[const Skeleton(width: 64, height: 64, radius: 12), const Gap(AppSpace.md)],
        const Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Skeleton(height: 14),
              Gap(AppSpace.xs),
              Skeleton(width: 140, height: 12),
              Gap(AppSpace.xs),
              Skeleton(width: 90, height: 12),
            ],
          ),
        ),
      ],
    ),
  );
}

/// Grid placeholder for product listings.
class ProductGridSkeleton extends StatelessWidget {
  const ProductGridSkeleton({super.key, this.count = 6, this.shrinkWrap = false});
  final int count;
  final bool shrinkWrap;

  @override
  Widget build(BuildContext context) => LayoutBuilder(
    builder: (context, c) => GridView.builder(
      shrinkWrap: shrinkWrap,
      physics: const NeverScrollableScrollPhysics(),
      padding: const EdgeInsets.all(AppSpace.gutter),
      gridDelegate: productGridDelegate(c.maxWidth, textScale: MediaQuery.textScalerOf(context).scale(1)),
      itemCount: count,
      itemBuilder: (_, _) => const ProductCardSkeleton(),
    ),
  );
}

/// Responsive column count for product grids (phones → tablets → desktop/web).
int productGridColumns(double width) {
  if (width >= 1200) return 5;
  if (width >= 900) return 4;
  if (width >= 600) return 3;
  return 2;
}

SliverGridDelegate productGridDelegate(
  double width, {
  double textScale = 1,
  double horizontalPadding = AppSpace.gutter * 2,
}) {
  final cols = productGridColumns(width);
  const spacing = AppSpace.md;
  final tile = (width - horizontalPadding - spacing * (cols - 1)) / cols;
  // Image is 4:5; text block (brand, 2-line name, price, rating) scales with text.
  final extent = tile * 5 / 4 + 112 * textScale.clamp(1.0, 1.6);
  return SliverGridDelegateWithFixedCrossAxisCount(
    crossAxisCount: cols,
    mainAxisSpacing: AppSpace.lg,
    crossAxisSpacing: spacing,
    mainAxisExtent: extent,
  );
}
