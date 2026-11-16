import 'dart:math' as math;

import 'package:albahar_customer/core/theme/page_backdrop_config.dart';
import 'package:albahar_customer/core/theme/qatar_skyline_paths.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';

export 'package:albahar_customer/core/theme/page_backdrop_config.dart';

/// Turns the address of an uploaded backdrop picture into an image. Tests hand in a fake.
typedef BackdropImageBuilder = ImageProvider Function(String url);

/// The cached network image the rest of the app uses for remote pictures.
ImageProvider defaultBackdropImage(String url) => CachedNetworkImageProvider(url);

/// The built-in Doha skyline, filled in one colour. It stands on the bottom edge, as wide as the canvas but never narrower
/// than [minWidth] (on a phone the middle of the skyline shows and both ends are cropped), and keeps the 1600:340 shape
/// of the drawing. Static: it only repaints when the colour or the opacity change.
class SkylinePainter extends CustomPainter {
  const SkylinePainter({required this.tint, required this.opacity});

  /// The skyline never gets narrower than this many logical pixels.
  static const double minWidth = 760;

  /// The outline, built once for the whole app.
  static final Path _outline = buildQatarSkylinePath();

  final Color tint;
  final double opacity;

  /// Where the drawing lands on a canvas of [size]: the box (in canvas coordinates) the 1600 x 340 drawing is scaled into.
  static Rect boxFor(Size size) {
    final width = math.max(size.width, minWidth);
    final scale = width / kSkylineWidth;
    final height = kSkylineHeight * scale;
    return Rect.fromLTWH((size.width - width) / 2, size.height - height, width, height);
  }

  @override
  void paint(Canvas canvas, Size size) {
    if (size.isEmpty) return;
    final box = boxFor(size);
    canvas
      ..save()
      ..clipRect(Offset.zero & size)
      ..translate(box.left, box.top)
      ..scale(box.width / kSkylineWidth)
      ..drawPath(_outline, Paint()..color = tint.withValues(alpha: opacity))
      ..restore();
  }

  @override
  bool shouldRepaint(SkylinePainter old) => old.tint != tint || old.opacity != opacity;
}

/// The picture only (no page colour): the skyline, or the uploaded image at its opacity. It ignores pointers, is hidden
/// from screen readers, never animates, and draws nothing while the image loads or when it fails.
class PageBackdropLayer extends StatelessWidget {
  const PageBackdropLayer({super.key, required this.config, this.imageBuilder = defaultBackdropImage});

  final PageBackdropConfig config;
  final BackdropImageBuilder imageBuilder;

  @override
  Widget build(BuildContext context) {
    final url = config.imageUrl;
    final Widget picture;
    if (config.style == BackdropStyle.skyline) {
      picture = CustomPaint(
        painter: SkylinePainter(tint: config.tint ?? Theme.of(context).colorScheme.primary, opacity: config.opacity),
        child: const SizedBox.expand(),
      );
    } else if (config.style == BackdropStyle.image && url != null) {
      final bottom = config.fit == BackdropFit.bottom;
      picture = SizedBox.expand(
        child: Image(
          image: imageBuilder(url),
          // fitWidth keeps the full width at the natural height and, for a very tall picture, crops the top.
          fit: bottom ? BoxFit.fitWidth : BoxFit.cover,
          alignment: bottom ? Alignment.bottomCenter : Alignment.center,
          opacity: AlwaysStoppedAnimation<double>(config.opacity),
          filterQuality: FilterQuality.medium,
          gaplessPlayback: true,
          excludeFromSemantics: true,
          errorBuilder: (_, _, _) => const SizedBox.shrink(),
        ),
      );
    } else {
      return const SizedBox.shrink();
    }
    return ExcludeSemantics(
      child: IgnorePointer(child: RepaintBoundary(child: picture)),
    );
  }
}

/// What the nearest [PageBackdrop] above draws, so a page that paints its own wash (the fresh gradient) can put the
/// picture on top of it. Null below a page without a backdrop.
class PageBackdropScope extends InheritedWidget {
  const PageBackdropScope({super.key, required this.config, required this.imageBuilder, required super.child});

  final PageBackdropConfig config;
  final BackdropImageBuilder imageBuilder;

  static PageBackdropScope? maybeOf(BuildContext context) => context.dependOnInheritedWidgetOfExactType<PageBackdropScope>();

  @override
  bool updateShouldNotify(PageBackdropScope oldWidget) =>
      config != oldWidget.config || imageBuilder != oldWidget.imageBuilder;
}

/// One page with the dashboard's background: the opaque page colour, the picture on it, then the page. Cards, sheets, bars
/// and the footer menu keep their own opaque surfaces, so text never sits on the picture.
///
/// It does not change the size or the constraints the page sees, and its own layer sits in a [RepaintBoundary], so the
/// picture is painted once however much the page above it repaints.
class PageBackdrop extends StatelessWidget {
  const PageBackdrop({super.key, required this.config, required this.child, this.imageBuilder = defaultBackdropImage});

  final PageBackdropConfig config;
  final BackdropImageBuilder imageBuilder;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return PageBackdropScope(
      config: config,
      imageBuilder: imageBuilder,
      child: ColoredBox(
        color: context.pageColor,
        child: Stack(
          fit: StackFit.passthrough,
          clipBehavior: Clip.none,
          children: [
            if (config.hasPicture)
              Positioned.fill(
                child: PageBackdropLayer(config: config, imageBuilder: imageBuilder),
              ),
            child,
          ],
        ),
      ),
    );
  }
}

/// A page transition that carries the backdrop with the page it moves: it wraps the route's child in a [PageBackdrop] and
/// hands it to the platform's usual transition. Every page then owns an opaque background, so a transition never shows
/// the previous page through a transparent one, and the picture slides, fades and zooms together with its page.
class BackdropPageTransitionsBuilder extends PageTransitionsBuilder {
  BackdropPageTransitionsBuilder({
    required this.platform,
    required this.config,
    required this.page,
    this.imageBuilder = defaultBackdropImage,
  }) : _inner = _transitionFor(platform, page);

  final TargetPlatform platform;
  final PageBackdropConfig config;

  /// The opaque page colour: also the colour behind the two pages while one slides over the other.
  final Color page;
  final BackdropImageBuilder imageBuilder;
  final PageTransitionsBuilder _inner;

  /// The transitions the app always used: Android 14 style on Android, the iOS slide on iOS, the zoom elsewhere.
  static PageTransitionsBuilder _transitionFor(TargetPlatform platform, Color page) => switch (platform) {
    TargetPlatform.android => FadeForwardsPageTransitionsBuilder(backgroundColor: page),
    TargetPlatform.iOS => const CupertinoPageTransitionsBuilder(),
    TargetPlatform.fuchsia ||
    TargetPlatform.linux ||
    TargetPlatform.macOS ||
    TargetPlatform.windows => ZoomPageTransitionsBuilder(backgroundColor: page),
  };

  @override
  DelegatedTransitionBuilder? get delegatedTransition => _inner.delegatedTransition;

  @override
  Duration get transitionDuration => _inner.transitionDuration;

  @override
  Duration get reverseTransitionDuration => _inner.reverseTransitionDuration;

  @override
  Widget buildTransitions<T>(
    PageRoute<T> route,
    BuildContext context,
    Animation<double> animation,
    Animation<double> secondaryAnimation,
    Widget child,
  ) => _inner.buildTransitions<T>(
    route,
    context,
    animation,
    secondaryAnimation,
    PageBackdrop(config: config, imageBuilder: imageBuilder, child: child),
  );

  @override
  bool operator ==(Object other) =>
      other is BackdropPageTransitionsBuilder &&
      other.platform == platform &&
      other.config == config &&
      other.page == page &&
      other.imageBuilder == imageBuilder;

  @override
  int get hashCode => Object.hash(platform, config, page, imageBuilder);
}

/// The page transitions of the customer app while the dashboard's page background is on: one backdrop-carrying builder
/// for every platform.
PageTransitionsTheme backdropPageTransitions({
  required PageBackdropConfig config,
  required Color page,
  BackdropImageBuilder imageBuilder = defaultBackdropImage,
}) => PageTransitionsTheme(
  builders: {
    for (final platform in TargetPlatform.values)
      platform: BackdropPageTransitionsBuilder(platform: platform, config: config, page: page, imageBuilder: imageBuilder),
  },
);
