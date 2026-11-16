import 'dart:math' as math;

import 'package:albahar_customer/core/theme/page_backdrop_config.dart';
import 'package:albahar_customer/core/theme/qatar_art_paths.dart';
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

/// One built-in artwork, ready to paint: the outline (closed polygons, fill them non-zero) in a [width] x [height] drawing
/// box. A [tile] artwork is one cell of a repeating pattern; the others are scenes that stand on the bottom edge.
///
/// The outlines are built once for the whole app, on first use, and then shared by every painter.
@immutable
class BackdropArt {
  const BackdropArt._({
    required this.key,
    required this.width,
    required this.height,
    required this.tile,
    required this.path,
  });

  static final Map<String, BackdropArt> _built = {};

  /// The artwork for a `scene` key; the Doha skyline for a key that is not one of [PageBackdropConfig.scenes].
  static BackdropArt of(String scene) {
    final key = kQatarArt.containsKey(scene) ? scene : PageBackdropConfig.defaultScene;
    return _built.putIfAbsent(key, () => _build(key));
  }

  static BackdropArt _build(String key) {
    final data = kQatarArt[key];
    if (data == null) {
      return BackdropArt._(
        key: PageBackdropConfig.defaultScene,
        width: kSkylineWidth,
        height: kSkylineHeight,
        tile: false,
        path: buildQatarSkylinePath(),
      );
    }
    return BackdropArt._(key: key, width: data.width, height: data.height, tile: data.tile, path: data.toPath());
  }

  final String key;
  final double width;
  final double height;
  final bool tile;

  /// Shared and never changed: draw it, do not edit it.
  final Path path;
}

/// The built-in artwork of the page background, filled in one colour at one opacity.
///
/// A scene stands on the bottom edge, as wide as the canvas but never narrower than [minWidth] (on a phone the middle of
/// the drawing shows and both ends are cropped), keeps the shape of its drawing (1600:340), and is centred.
///
/// A tile scene (`arabesque`) is one cell repeated over the whole canvas: [tileSize] logical pixels square, from the
/// top-left corner, each cell clipped to its own box so that neighbours join. A cell is the cached outline drawn once per
/// tile: for the 40 to 220 tiles of a tablet that is a few hundred small polygons, drawn only when the picture repaints,
/// which is when the colour, the opacity, the scene or the size changes (the layer is a repaint boundary, so scrolling or
/// animating pages above it never repaints it). Recording a picture of one cell instead would only move the same
/// tessellation work, and would need a cache keyed by colour and opacity that someone has to dispose.
///
/// It paints nothing but the artwork and allocates nothing but one [Paint] per repaint.
class SkylinePainter extends CustomPainter {
  const SkylinePainter({required this.tint, required this.opacity, this.scene = PageBackdropConfig.defaultScene});

  /// A scene never gets narrower than this many logical pixels.
  static const double minWidth = 760;

  /// The side of one cell of a tile scene, in logical pixels (the web mask is 168px as well).
  static const double tileSize = 168;

  final Color tint;
  final double opacity;

  /// A key of [PageBackdropConfig.scenes]; the Doha skyline for any other.
  final String scene;

  /// Where the drawing lands on a canvas of [size]: the box (in canvas coordinates) the scene's drawing is scaled into.
  /// A tile scene has no such box: it covers the whole canvas.
  static Rect boxFor(Size size, {String scene = PageBackdropConfig.defaultScene}) {
    final art = BackdropArt.of(scene);
    if (art.tile) return Offset.zero & size;
    final width = math.max(size.width, minWidth);
    final scale = width / art.width;
    final height = art.height * scale;
    return Rect.fromLTWH((size.width - width) / 2, size.height - height, width, height);
  }

  /// How many cells (columns, rows) it takes to cover a canvas of [size]; the last column and row are cut by the canvas.
  static (int, int) tilesFor(Size size) => ((size.width / tileSize).ceil(), (size.height / tileSize).ceil());

  @override
  void paint(Canvas canvas, Size size) {
    if (size.isEmpty) return;
    final art = BackdropArt.of(scene);
    final paint = Paint()..color = tint.withValues(alpha: opacity);
    canvas
      ..save()
      ..clipRect(Offset.zero & size);
    if (art.tile) {
      _paintTiles(canvas, size, art, paint);
    } else {
      final box = boxFor(size, scene: scene);
      canvas
        ..translate(box.left, box.top)
        ..scale(box.width / art.width)
        ..drawPath(art.path, paint);
    }
    canvas.restore();
  }

  static void _paintTiles(Canvas canvas, Size size, BackdropArt art, Paint paint) {
    final (columns, rows) = tilesFor(size);
    const cell = Rect.fromLTWH(0, 0, tileSize, tileSize);
    final scaleX = tileSize / art.width;
    final scaleY = tileSize / art.height;
    for (var row = 0; row < rows; row++) {
      for (var column = 0; column < columns; column++) {
        // Move first, clip the whole-pixel cell, and only then scale: every cell lands on the same pixels, and with no
        // anti-aliasing on the clip two neighbours share their edge pixels exactly (no seam, no double-painted line).
        canvas
          ..save()
          ..translate(column * tileSize, row * tileSize)
          ..clipRect(cell, doAntiAlias: false)
          ..scale(scaleX, scaleY)
          ..drawPath(art.path, paint)
          ..restore();
      }
    }
  }

  @override
  bool shouldRepaint(SkylinePainter old) => old.tint != tint || old.opacity != opacity || old.scene != scene;
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
        painter: SkylinePainter(
          tint: config.tint ?? Theme.of(context).colorScheme.primary,
          opacity: config.opacity,
          scene: config.scene,
        ),
        child: const SizedBox.expand(),
      );
    } else if (config.style == BackdropStyle.image && url != null) {
      final bottom = config.fit == BackdropFit.bottom;
      // An upload can be megabytes of pixels and this is a quiet backdrop: decode it no wider than the screen needs.
      final screen = MediaQuery.maybeSizeOf(context);
      final ratio = MediaQuery.maybeDevicePixelRatioOf(context) ?? 1;
      final decodeWidth = screen == null
          ? 0
          : ((bottom ? screen.width : math.max(screen.width, screen.height)) * ratio).round();
      picture = SizedBox.expand(
        child: Image(
          image: ResizeImage.resizeIfNeeded(decodeWidth > 0 ? decodeWidth : null, null, imageBuilder(url)),
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

  static PageBackdropScope? maybeOf(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<PageBackdropScope>();

  @override
  bool updateShouldNotify(PageBackdropScope oldWidget) =>
      config != oldWidget.config || imageBuilder != oldWidget.imageBuilder;
}

/// The page colour, the picture on it, then the page above. Cards, sheets, bars and the footer menu keep their own opaque
/// surfaces, so text never sits on the picture.
///
/// The app shell puts one behind the whole navigator (its pages are transparent while the page background is on, and
/// there is nothing to see through to but this). A page that moves over another one with a real transition carries its own,
/// see [BackdropPageTransitionsBuilder].
///
/// It does not change the size or the constraints its child sees, and the picture sits in a [RepaintBoundary], so it is
/// painted once however much the page above it repaints.
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

/// The page background as the theme carries it: present while it changes anything in the mode in use (a picture, or a page
/// colour in light mode), absent otherwise. [AppTheme] puts it there, and the app shell reads it to put the backdrop behind
/// the pages, so the theme is the one place that decides.
@immutable
class PageBackdropTheme extends ThemeExtension<PageBackdropTheme> {
  const PageBackdropTheme(this.config);

  final PageBackdropConfig config;

  @override
  PageBackdropTheme copyWith({PageBackdropConfig? config}) => PageBackdropTheme(config ?? this.config);

  /// A discrete switch: it flips at the half way point.
  @override
  PageBackdropTheme lerp(ThemeExtension<PageBackdropTheme>? other, double t) =>
      other is PageBackdropTheme && t >= 0.5 ? other : this;

  @override
  bool operator ==(Object other) => other is PageBackdropTheme && other.config == config;

  @override
  int get hashCode => config.hashCode;
}

extension PageBackdropContextX on BuildContext {
  /// The page background to draw behind the pages, or null when the theme has none (the page looks as it always did).
  PageBackdropConfig? get pageBackdrop => Theme.of(this).extension<PageBackdropTheme>()?.config;
}

/// A page transition that carries the backdrop with the page it moves: it wraps the route's child in a [PageBackdrop] and
/// hands it to the platform's usual transition. A page that slides or fades over another one (a Material route such as the
/// map pin picker) then owns an opaque background, so the transition never shows the page below through a transparent
/// scaffold, and the picture moves together with its page.
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
      platform: BackdropPageTransitionsBuilder(
        platform: platform,
        config: config,
        page: page,
        imageBuilder: imageBuilder,
      ),
  },
);
