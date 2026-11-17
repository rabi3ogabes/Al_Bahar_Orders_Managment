import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Width (in device pixels) a photo is decoded at: the screen width times [scale], inside sane limits, so a 4000 px
/// upload never sits in memory at full size. Photos stay sharp on the screen they are shown on.
int galleryCacheWidth(BuildContext context, {double scale = 1, int max = 1600}) {
  final pixels = MediaQuery.sizeOf(context).width * MediaQuery.devicePixelRatioOf(context) * scale;
  return pixels.round().clamp(240, max).toInt();
}

/// Past this many photos the page dots turn into a "3 / 12" counter.
const int kMaxGalleryDots = 7;

/// The product photos: a swipeable pager with page dots (or a counter) over the picture, a soft scrim at the top so the
/// status bar and the round buttons stay readable on any photo, and a tap that opens the full-screen zoom viewer.
///
/// It is the background of the page's header, so the page can scroll over it. The first photo carries the Hero tag of the
/// list card, so the list → detail transition still flies. Choosing another colour or size (a different set of photos)
/// starts again from the first photo, with no jump of the layout (the header keeps its height).
class ProductGallery extends StatelessWidget {
  const ProductGallery({super.key, required this.images, required this.heroTag, this.bottomInset = 0});

  final List<ImageRef> images;
  final String heroTag;

  /// Height at the bottom that the page sheet will cover; the page dots stay above it.
  final double bottomInset;

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: [
        if (images.isEmpty)
          const AppNetworkImage(url: null)
        else
          _Pages(
            // A new set of photos (another colour or size) is a new pager: it starts on its first photo.
            key: ValueKey(Object.hashAll(images.map((i) => i.url))),
            images: images,
            heroTag: heroTag,
            bottomInset: bottomInset,
          ),
        const Positioned.fill(child: IgnorePointer(child: ProductHeaderScrim())),
      ],
    );
  }
}

/// A dark-to-clear wash over the top of the photo: white status bar icons and the round buttons stay readable on a white
/// photo, in the light and the dark theme alike. It is soft enough to leave a dark photo unchanged.
class ProductHeaderScrim extends StatelessWidget {
  const ProductHeaderScrim({super.key});

  @override
  Widget build(BuildContext context) {
    final top = MediaQuery.paddingOf(context).top;
    return Align(
      alignment: Alignment.topCenter,
      child: SizedBox(
        height: top + kToolbarHeight + AppSpace.lg,
        width: double.infinity,
        child: const DecoratedBox(
          decoration: BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: [Color(0x59000000), Color(0x00000000)],
            ),
          ),
        ),
      ),
    );
  }
}

class _Pages extends StatefulWidget {
  const _Pages({super.key, required this.images, required this.heroTag, required this.bottomInset});
  final List<ImageRef> images;
  final String heroTag;
  final double bottomInset;

  @override
  State<_Pages> createState() => _PagesState();
}

class _PagesState extends State<_Pages> {
  final _page = PageController();
  final _index = ValueNotifier<int>(0);

  @override
  void dispose() {
    _page.dispose();
    _index.dispose();
    super.dispose();
  }

  void _openViewer(int initial) {
    Navigator.of(context).push(
      PageRouteBuilder<void>(
        opaque: false,
        barrierColor: Colors.black,
        pageBuilder: (_, _, _) => ProductZoomViewer(images: widget.images, initial: initial),
        transitionsBuilder: (_, a, _, child) => FadeTransition(opacity: a, child: child),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final images = widget.images;
    final cacheWidth = galleryCacheWidth(context);
    return Stack(
      fit: StackFit.expand,
      children: [
        PageView.builder(
          controller: _page,
          itemCount: images.length,
          onPageChanged: (i) => _index.value = i,
          itemBuilder: (context, i) {
            final image = AppNetworkImage(
              url: images[i].url,
              memCacheWidth: cacheWidth,
              semanticLabel: images[i].alt ?? context.l10n.imageOf(i + 1, images.length),
            );
            return Semantics(
              onTapHint: context.l10n.viewImageFullScreen,
              child: GestureDetector(
                behavior: HitTestBehavior.opaque,
                onTap: () => _openViewer(i),
                child: i == 0 ? Hero(tag: widget.heroTag, child: image) : image,
              ),
            );
          },
        ),
        if (images.length > 1)
          PositionedDirectional(
            bottom: widget.bottomInset + AppSpace.sm,
            start: 0,
            end: 0,
            child: IgnorePointer(
              child: Center(
                child: ValueListenableBuilder<int>(
                  valueListenable: _index,
                  builder: (_, index, _) => PagerPill(index: index, count: images.length),
                ),
              ),
            ),
          ),
      ],
    );
  }
}

/// Page dots in a small dark pill (readable on any photo), or "3 / 12" past [kMaxGalleryDots] photos.
class PagerPill extends StatelessWidget {
  const PagerPill({super.key, required this.index, required this.count});
  final int index;
  final int count;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: context.l10n.imageOf(index + 1, count),
      excludeSemantics: true,
      child: DecoratedBox(
        decoration: BoxDecoration(color: const Color(0x66000000), borderRadius: BorderRadius.circular(AppRadius.pill)),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
          child: count > kMaxGalleryDots
              ? Text(
                  '${index + 1} / $count',
                  textDirection: TextDirection.ltr,
                  style: context.text.labelSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w600),
                )
              : Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    for (var i = 0; i < count; i++)
                      AnimatedContainer(
                        duration: AppDurations.medium,
                        curve: Curves.easeOut,
                        margin: const EdgeInsets.symmetric(horizontal: 2.5),
                        width: i == index ? 16 : 6,
                        height: 6,
                        decoration: BoxDecoration(
                          color: i == index ? Colors.white : Colors.white.withValues(alpha: 0.6),
                          borderRadius: BorderRadius.circular(3),
                        ),
                      ),
                  ],
                ),
        ),
      ),
    );
  }
}

/// Full-screen pinch-to-zoom gallery: pinch or double-tap to zoom, drag to look around, swipe sideways for the next
/// photo (swiping is locked while a photo is zoomed in, so panning never flips the page by accident).
class ProductZoomViewer extends StatefulWidget {
  const ProductZoomViewer({super.key, required this.images, required this.initial});
  final List<ImageRef> images;
  final int initial;

  @override
  State<ProductZoomViewer> createState() => _ProductZoomViewerState();
}

class _ProductZoomViewerState extends State<ProductZoomViewer> with SingleTickerProviderStateMixin {
  static const _doubleTapScale = 2.5;

  late final _page = PageController(initialPage: widget.initial);
  late int _index = widget.initial;
  final _controllers = <int, TransformationController>{};
  final _zoomed = ValueNotifier<bool>(false);
  late final _animation = AnimationController(vsync: this, duration: AppDurations.medium);
  VoidCallback? _tick;
  Offset _tapAt = Offset.zero;

  TransformationController _controllerFor(int i) => _controllers.putIfAbsent(i, () {
    final c = TransformationController();
    c.addListener(() {
      if (i == _index) _zoomed.value = c.value.getMaxScaleOnAxis() > 1.02;
    });
    return c;
  });

  @override
  void dispose() {
    _animation.dispose();
    _page.dispose();
    _zoomed.dispose();
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  void _onPage(int i) {
    // Leaving a photo puts it back to normal size.
    _controllers[_index]?.value = Matrix4.identity();
    setState(() => _index = i);
    _zoomed.value = false;
  }

  void _toggleZoom() {
    final controller = _controllerFor(_index);
    final from = controller.value.clone();
    final zoomedIn = from.getMaxScaleOnAxis() > 1.02;
    final target = zoomedIn
        ? Matrix4.identity()
        : (Matrix4.identity()
            ..translateByDouble(-_tapAt.dx * (_doubleTapScale - 1), -_tapAt.dy * (_doubleTapScale - 1), 0, 1)
            ..scaleByDouble(_doubleTapScale, _doubleTapScale, 1, 1));
    final tween = Matrix4Tween(begin: from, end: target);
    if (_tick != null) _animation.removeListener(_tick!);
    _tick = () => controller.value = tween.transform(Curves.easeOutCubic.transform(_animation.value));
    _animation
      ..addListener(_tick!)
      ..forward(from: 0);
  }

  @override
  Widget build(BuildContext context) {
    final images = widget.images;
    final cacheWidth = galleryCacheWidth(context, scale: 2, max: 2400);
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        systemOverlayStyle: SystemUiOverlayStyle.light,
        title: Text(
          '${_index + 1} / ${images.length}',
          textDirection: TextDirection.ltr,
          style: const TextStyle(color: Colors.white),
        ),
      ),
      body: ValueListenableBuilder<bool>(
        valueListenable: _zoomed,
        builder: (context, zoomed, _) => PageView.builder(
          controller: _page,
          physics: zoomed ? const NeverScrollableScrollPhysics() : const PageScrollPhysics(),
          itemCount: images.length,
          onPageChanged: _onPage,
          itemBuilder: (context, i) => GestureDetector(
            onDoubleTapDown: (d) => _tapAt = d.localPosition,
            onDoubleTap: _toggleZoom,
            child: InteractiveViewer(
              transformationController: _controllerFor(i),
              minScale: 1,
              maxScale: 5,
              child: Center(
                child: AppNetworkImage(
                  url: images[i].url,
                  fit: BoxFit.contain,
                  memCacheWidth: cacheWidth,
                  semanticLabel: images[i].alt ?? context.l10n.imageOf(i + 1, images.length),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
