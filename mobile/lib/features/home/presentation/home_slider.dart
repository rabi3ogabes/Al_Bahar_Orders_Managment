import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/design_tokens.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/home/presentation/home_link_opener.dart';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Gives the picture of a slide for an address. The app uses the disk-cached network image; a test brings its own pictures.
typedef SlideImageBuilder = ImageProvider Function(String url);

final homeSliderImageProvider = Provider<SlideImageBuilder>((ref) => CachedNetworkImageProvider.new);

/// One slider of the home, as the dashboard set it up (`banners` section): its slides, aspect ratio, autoplay, dots, titles and
/// corners.
///
/// - The height is known from the width before any picture arrives, so nothing moves while the pictures load.
/// - Several slides loop without end in both directions; the next picture is fetched before it is needed.
/// - Autoplay waits while a finger or the mouse is on the slider, while the app is in the background, while the screen is not
///   showing (another tab, another page on top, scrolled away) and while a screen reader is on. With "reduce motion" the slide
///   changes at once instead of gliding.
/// - A slide with a link is a button; a slide without one is a picture: no ripple, no pointer, not announced as a button.
class HomeSlider extends ConsumerStatefulWidget {
  const HomeSlider({super.key, required this.section});

  final BannersSection section;

  /// The widest a slider grows (tablets, the web): beyond it the slider stays centred.
  static const maxWidth = 1000.0;

  /// Space between two slides while they peek in from the sides.
  static const slideGap = 10.0;

  /// More slides than this are counted ("3 / 12") instead of drawn as dots.
  static const maxDots = 8;

  @override
  ConsumerState<HomeSlider> createState() => _HomeSliderState();
}

class _HomeSliderState extends ConsumerState<HomeSlider> with WidgetsBindingObserver {
  /// Pages before the first slide, so a swipe backwards works from the start. A multiple of the slide count: the first slide shows.
  static const _loopPages = 1000;
  static const _glide = Duration(milliseconds: 450);

  PageController? _controller;
  final _index = ValueNotifier<int>(0);
  Timer? _timer;
  int _generation = 0;
  int _page = 0;
  int? _cacheWidth;
  Size _window = Size.infinite;
  bool _firstPrecacheDone = false;
  final _precached = <String>{};

  bool _touching = false;
  bool _hovering = false;
  bool _foreground = true;
  bool _showing = true;
  bool _screenReader = false;
  bool _reduceMotion = false;

  List<HomeBanner> get _banners => widget.section.banners;
  SliderSettings get _settings => widget.section.slider;
  int get _count => _banners.length;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _page = _startPage();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final showing = TickerMode.valuesOf(context).enabled;
    final screenReader = MediaQuery.accessibleNavigationOf(context);
    final changed = showing != _showing || screenReader != _screenReader;
    _showing = showing;
    _screenReader = screenReader;
    _reduceMotion = MediaQuery.disableAnimationsOf(context);
    if (changed || _timer == null) _schedule();
  }

  @override
  void didUpdateWidget(HomeSlider oldWidget) {
    super.didUpdateWidget(oldWidget);
    final before = oldWidget.section.banners;
    final sameSlides =
        before.length == _count &&
        [for (var i = 0; i < _count; i++) before[i].id == _banners[i].id].every((same) => same);
    if (!sameSlides) {
      // A refreshed feed with other slides starts again from the first one.
      _generation++;
      _page = _startPage();
      _index.value = 0;
      _precached.clear();
      _firstPrecacheDone = false;
      _dropController();
      _schedule();
      return;
    }
    // A parent that only rebuilds must not restart the wait: only a change of the autoplay settings does.
    final was = oldWidget.section.slider;
    if (was.autoplay != _settings.autoplay || was.intervalSeconds != _settings.intervalSeconds) _schedule();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    _schedule();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _controller?.dispose();
    _index.dispose();
    super.dispose();
  }

  int _startPage() => _count * _loopPages;

  /// Lets the page controller go once the page that used it is gone (a different width needs a different peek; other slides need a
  /// new start).
  void _dropController() {
    final old = _controller;
    _controller = null;
    if (old != null) WidgetsBinding.instance.addPostFrameCallback((_) => old.dispose());
  }

  PageController _controllerFor(double fraction) {
    final current = _controller;
    if (current != null && (current.viewportFraction - fraction).abs() < 0.0005) return current;
    _dropController();
    return _controller = PageController(initialPage: _page, viewportFraction: fraction);
  }

  // ── Autoplay ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

  bool get _mayAutoplay =>
      _settings.autoplay && _count > 1 && _foreground && _showing && !_touching && !_hovering && !_screenReader;

  /// (Re)starts the wait for the next slide: a whole interval after anything that happened (a swipe, a release, coming back).
  void _schedule() {
    _timer?.cancel();
    _timer = null;
    if (!mounted || !_mayAutoplay) return;
    _timer = Timer(Duration(seconds: _settings.intervalSeconds), _advance);
  }

  void _advance() {
    _timer = null;
    if (!mounted || !_mayAutoplay) return; // paused: coming back (or letting go) starts the wait again
    final controller = _controller;
    if (controller != null && controller.hasClients && _onScreen()) {
      final next = _page + 1;
      if (_reduceMotion) {
        controller.jumpToPage(next);
      } else {
        unawaited(controller.animateToPage(next, duration: _glide, curve: Curves.easeInOutCubic));
      }
    }
    // Not ready or scrolled out of sight: do not move, look again after another interval.
    _schedule();
  }

  /// Whether any part of the slider is inside the window (not scrolled far away).
  bool _onScreen() {
    final box = context.findRenderObject();
    if (box is! RenderBox || !box.attached || !box.hasSize) return false;
    final rect = MatrixUtils.transformRect(box.getTransformTo(null), Offset.zero & box.size);
    return rect.overlaps(Offset.zero & _window);
  }

  void _hold({bool? touching, bool? hovering}) {
    _touching = touching ?? _touching;
    _hovering = hovering ?? _hovering;
    _schedule();
  }

  void _onPageChanged(int page) {
    _page = page;
    _index.value = page % _count;
    _precache(page);
    _schedule();
  }

  /// Fetches the picture of the slide after [page] so it is there when the slider gets to it.
  void _precache(int page) {
    final width = _cacheWidth;
    if (width == null || _count < 2 || !mounted) return;
    final url = _banners[(page + 1) % _count].imageUrl;
    if (url == null || url.isEmpty || !_precached.add(url)) return;
    try {
      final provider = ResizeImage.resizeIfNeeded(width, null, ref.read(homeSliderImageProvider)(url));
      unawaited(precacheImage(provider, context, onError: (_, _) => _precached.remove(url)));
    } catch (_) {
      _precached.remove(url);
    }
  }

  // ── Layout ────────────────────────────────────────────────────────────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    if (_count == 0) return const SizedBox.shrink();
    final section = widget.section;
    final settings = section.slider;
    _window = MediaQuery.sizeOf(context);
    final design = context.design;
    final rounded = settings.rounded;
    final radius = rounded ? design.cardBorderRadius : BorderRadius.zero;
    final gutter = rounded ? design.gutter : 0.0;
    final title = section.title;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (title != null && title.trim().isNotEmpty) SectionHeader(title: title, subtitle: section.subtitle),
        Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: HomeSlider.maxWidth),
            child: LayoutBuilder(
              builder: (context, constraints) {
                final width = constraints.maxWidth;
                final slideWidth = width - 2 * gutter;
                final height = slideWidth / settings.aspect.ratio;
                final multiple = _count > 1;
                final dots = multiple && settings.dots;
                _cacheWidth = (slideWidth * MediaQuery.devicePixelRatioOf(context)).round();
                if (!multiple) {
                  return Padding(
                    padding: EdgeInsets.symmetric(horizontal: gutter),
                    child: SizedBox(
                      height: height,
                      child: _SlideCard(
                        banner: _banners.first,
                        position: 1,
                        total: 1,
                        settings: settings,
                        radius: radius,
                      ),
                    ),
                  );
                }
                // Peeking neighbours need pages a little wider than the slide; a flat slider fills the width.
                final gap = rounded ? HomeSlider.slideGap : 0.0;
                final fraction = rounded ? (slideWidth + gap) / width : 1.0;
                if (!_firstPrecacheDone) {
                  _firstPrecacheDone = true;
                  WidgetsBinding.instance.addPostFrameCallback((_) => _precache(_page));
                }
                return MouseRegion(
                  onEnter: (_) => _hold(hovering: true),
                  onExit: (_) => _hold(hovering: false),
                  child: Listener(
                    onPointerDown: (_) => _hold(touching: true),
                    onPointerUp: (_) => _hold(touching: false),
                    onPointerCancel: (_) => _hold(touching: false),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        SizedBox(
                          height: height,
                          child: PageView.builder(
                            key: ValueKey('pager-$_generation-${fraction.toStringAsFixed(3)}'),
                            controller: _controllerFor(fraction),
                            onPageChanged: _onPageChanged,
                            itemBuilder: (context, page) {
                              final i = page % _count;
                              return Padding(
                                padding: EdgeInsets.symmetric(horizontal: gap / 2),
                                child: _SlideCard(
                                  banner: _banners[i],
                                  position: i + 1,
                                  total: _count,
                                  settings: settings,
                                  radius: radius,
                                ),
                              );
                            },
                          ),
                        ),
                        if (dots)
                          Padding(
                            padding: const EdgeInsets.only(top: AppSpace.sm),
                            child: _Dots(count: _count, index: _index, reduceMotion: _reduceMotion),
                          ),
                      ],
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ],
    );
  }
}

/// One slide: the picture, and over it (when the dashboard wants them) the title and subtitle on a soft dark fade.
class _SlideCard extends ConsumerWidget {
  const _SlideCard({
    required this.banner,
    required this.position,
    required this.total,
    required this.settings,
    required this.radius,
  });

  final HomeBanner banner;
  final int position;
  final int total;
  final SliderSettings settings;
  final BorderRadius radius;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final l10n = context.l10n;
    final tappable = banner.isTappable;
    final title = banner.title ?? '';
    final subtitle = banner.subtitle ?? '';
    final label = [
      if (total > 1 || (title.isEmpty && subtitle.isEmpty)) l10n.homeBannerPosition(position, total),
      if (title.isNotEmpty) title,
      if (subtitle.isNotEmpty) subtitle,
    ].join('. ');
    final face = ClipRRect(
      borderRadius: radius,
      child: Stack(
        fit: StackFit.expand,
        children: [
          _SlideFace(banner: banner, showText: settings.showTitles && banner.hasText),
          // The ripple lies over the picture (under it, an opaque picture would hide it). Only a slide with a link has one.
          if (tappable)
            Positioned.fill(
              child: Material(
                type: MaterialType.transparency,
                child: InkWell(
                  mouseCursor: SystemMouseCursors.click,
                  onTap: () => unawaited(openHomeLink(context, banner.link)),
                ),
              ),
            ),
        ],
      ),
    );
    // Announced as one thing: "Banner 2 of 5. Title". A link makes it a button; a picture alone is an image, never a button.
    return Semantics(
      container: true,
      excludeSemantics: true,
      button: tappable,
      image: !tappable,
      label: label,
      onTap: tappable ? () => unawaited(openHomeLink(context, banner.link)) : null,
      child: face,
    );
  }
}

/// The picture of a slide with its calm placeholder, its on-brand error and (optionally) its words.
class _SlideFace extends ConsumerWidget {
  const _SlideFace({required this.banner, required this.showText});

  final HomeBanner banner;
  final bool showText;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final url = banner.imageUrl;
    return LayoutBuilder(
      builder: (context, constraints) {
        final width = constraints.maxWidth;
        final height = constraints.maxHeight;
        final Widget picture;
        if (url == null || url.isEmpty) {
          picture = const _BrandBackdrop();
        } else {
          final dpr = MediaQuery.devicePixelRatioOf(context);
          final provider = ResizeImage.resizeIfNeeded(
            (width * dpr).round(),
            null,
            ref.watch(homeSliderImageProvider)(url),
          );
          final reduce = MediaQuery.disableAnimationsOf(context);
          picture = Image(
            image: provider,
            fit: BoxFit.cover,
            width: width,
            height: height,
            gaplessPlayback: true,
            excludeFromSemantics: true,
            frameBuilder: (context, child, frame, loadedSync) {
              if (loadedSync) return child;
              return Stack(
                fit: StackFit.expand,
                children: [
                  if (frame == null) const Skeleton(radius: 0),
                  AnimatedOpacity(
                    opacity: frame == null ? 0 : 1,
                    duration: reduce ? Duration.zero : AppDurations.medium,
                    child: child,
                  ),
                ],
              );
            },
            errorBuilder: (context, error, stackTrace) => const _BrandBackdrop(broken: true),
          );
        }
        if (!showText) return picture;
        return Stack(
          fit: StackFit.expand,
          children: [
            picture,
            _SlideText(banner: banner, height: height, arrow: banner.isTappable, scrim: url != null && url.isNotEmpty),
          ],
        );
      },
    );
  }
}

/// The brand's own backdrop: shown when a slide has no picture, while a picture cannot be loaded, and never as a grey box.
class _BrandBackdrop extends StatelessWidget {
  const _BrandBackdrop({this.broken = false});

  final bool broken;

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [context.colors.primary, palette.primarySoft],
        ),
      ),
      child: Stack(
        fit: StackFit.expand,
        children: [
          PositionedDirectional(
            end: -40,
            top: -40,
            child: Container(
              width: 170,
              height: 170,
              decoration: BoxDecoration(shape: BoxShape.circle, color: palette.brass.withValues(alpha: 0.28)),
            ),
          ),
          PositionedDirectional(
            end: 30,
            bottom: -60,
            child: Container(
              width: 120,
              height: 120,
              decoration: BoxDecoration(shape: BoxShape.circle, color: Colors.white.withValues(alpha: 0.08)),
            ),
          ),
          if (broken)
            Center(
              child: Icon(Icons.image_not_supported_outlined, size: 30, color: Colors.white.withValues(alpha: 0.55)),
            ),
        ],
      ),
    );
  }
}

/// Title and subtitle over the bottom of a slide, with a fade so they read on any picture, and (for a slide that opens something)
/// a small round arrow. The text is sized to the slide: a low banner shows a single line, a tall one up to two.
class _SlideText extends StatelessWidget {
  const _SlideText({required this.banner, required this.height, required this.arrow, required this.scrim});

  final HomeBanner banner;
  final double height;
  final bool arrow;

  /// The fade under the words: only over a real picture (the brand backdrop is dark already).
  final bool scrim;

  @override
  Widget build(BuildContext context) {
    final title = banner.title ?? '';
    final subtitle = banner.subtitle ?? '';
    final low = height < 100;
    final compact = height < 150;
    final pad = compact ? AppSpace.sm : AppSpace.md;
    final text = context.text;
    final titleStyle = (height >= 200 ? text.headlineSmall : (compact ? text.titleMedium : text.titleLarge))?.copyWith(
      color: Colors.white,
      fontWeight: FontWeight.w700,
      height: 1.2,
      letterSpacing: 0,
    );
    final subtitleStyle = (compact ? text.bodySmall : text.bodyMedium)?.copyWith(
      color: Colors.white.withValues(alpha: 0.92),
      height: 1.25,
    );
    final badge = arrow && !low;
    // Words on a picture keep a readable size: they follow the phone's text size, but never beyond a little larger.
    final scaler = MediaQuery.textScalerOf(context).clamp(maxScaleFactor: 1.2);
    return MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: scaler),
      child: Stack(
        fit: StackFit.expand,
        children: [
          if (scrim)
            DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    Colors.black.withValues(alpha: 0),
                    Colors.black.withValues(alpha: 0.26),
                    Colors.black.withValues(alpha: 0.7),
                  ],
                  stops: const [0.3, 0.66, 1],
                ),
              ),
            ),
          PositionedDirectional(
            start: pad + 2,
            end: pad + 2 + (badge ? 40 : 0),
            bottom: pad,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (title.isNotEmpty)
                  Text(title, maxLines: compact ? 1 : 2, overflow: TextOverflow.ellipsis, style: titleStyle),
                if (subtitle.isNotEmpty && !low) ...[
                  if (title.isNotEmpty) const SizedBox(height: 2),
                  Text(subtitle, maxLines: compact ? 1 : 2, overflow: TextOverflow.ellipsis, style: subtitleStyle),
                ],
              ],
            ),
          ),
          if (badge)
            PositionedDirectional(
              end: pad + 2,
              bottom: pad,
              child: Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.94), shape: BoxShape.circle),
                child: Icon(Icons.arrow_forward_rounded, size: 18, color: context.colors.primary),
              ),
            ),
        ],
      ),
    );
  }
}

/// The dots under the slider: the current one is a longer pill. Many slides are counted instead ("3 / 12").
class _Dots extends StatelessWidget {
  const _Dots({required this.count, required this.index, required this.reduceMotion});

  final int count;
  final ValueNotifier<int> index;
  final bool reduceMotion;

  @override
  Widget build(BuildContext context) {
    final duration = reduceMotion ? Duration.zero : AppDurations.medium;
    return ExcludeSemantics(
      child: RepaintBoundary(
        child: SizedBox(
          height: 14,
          child: ValueListenableBuilder<int>(
            valueListenable: index,
            builder: (context, current, _) {
              if (count > HomeSlider.maxDots) {
                return Center(
                  child: Directionality(
                    textDirection: TextDirection.ltr,
                    child: Text(
                      '${current + 1} / $count',
                      style: context.text.labelSmall?.copyWith(color: context.palette.muted, letterSpacing: 0),
                    ),
                  ),
                );
              }
              return Row(
                key: const ValueKey('slider-dots'),
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  for (var i = 0; i < count; i++)
                    AnimatedContainer(
                      duration: duration,
                      curve: Curves.easeOutCubic,
                      margin: const EdgeInsets.symmetric(horizontal: 3),
                      width: i == current ? 18 : 6,
                      height: 6,
                      decoration: BoxDecoration(
                        color: i == current ? context.colors.primary : context.palette.line,
                        borderRadius: BorderRadius.circular(3),
                      ),
                    ),
                ],
              );
            },
          ),
        ),
      ),
    );
  }
}
