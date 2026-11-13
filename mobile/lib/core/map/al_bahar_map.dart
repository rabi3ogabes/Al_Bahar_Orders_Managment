import 'dart:async';
import 'dart:math' as math;

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/map_attribution.dart';
import 'package:albahar_customer/core/map/map_controller.dart';
import 'package:albahar_customer/core/map/map_controls.dart';
import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

export 'package:albahar_customer/core/map/map_controller.dart' show AlBaharMapController;
export 'package:albahar_customer/core/map/map_controls.dart' show MapControlsConfig;

/// Light OpenStreetMap tiles turned into a calm night map when no dark tile style is configured: colours are inverted
/// and hue-rotated so water stays blue and roads stay readable, then dimmed a little.
const ColorFilter kNightTileFilter = ColorFilter.matrix(<double>[
  0.5166, -1.287, -0.1296, 0, 230, //
  -0.3834, -0.387, -0.1296, 0, 230, //
  -0.3834, -1.287, 0.7704, 0, 230, //
  0, 0, 0, 1, 0, //
]);

/// The one map every screen of the Al Bahar apps uses: server-configured tiles that follow the light and dark theme,
/// a cached disk tile store, soft failure handling, a visible credit line and (optionally) round controls. Put markers,
/// routes and circles in [children]; they are flutter_map layers drawn above the tiles.
class AlBaharMap extends ConsumerStatefulWidget {
  const AlBaharMap({
    super.key,
    this.controller,
    this.children = const [],
    this.overlays = const [],
    this.initialCenter,
    this.initialZoom,
    this.initialFit = const [],
    this.interactive = true,
    this.rotatable = false,
    this.controls = const MapControlsConfig(),
    this.topInset = 0,
    this.bottomInset = 0,
    this.onTap,
    this.onMapReady,
    this.onPositionChanged,
    this.onUserGesture,
    this.onMyLocation,
    this.locating = false,
    this.extraControls = const [],
    this.semanticsLabel,
    this.showAttribution = true,
    this.maxFitZoom = 17,
  });

  /// Optional; the screen owns and disposes it. Without one the map keeps its own.
  final AlBaharMapController? controller;

  /// flutter_map layers (`MarkerLayer`, `PolylineLayer`, `CircleLayer`, or any widget that reads the map camera).
  final List<Widget> children;

  /// Plain widgets stacked above the map (chips, banners); position them yourself, mind [bottomInset].
  final List<Widget> overlays;

  /// Defaults to the server's centre and zoom.
  final LatLng? initialCenter;
  final double? initialZoom;

  /// Points to frame on the first layout (needs no frame callback); more than zero wins over [initialCenter].
  final List<LatLng> initialFit;
  final bool interactive;

  /// Allows two-finger rotation; the compass button appears while the map is turned.
  final bool rotatable;
  final MapControlsConfig controls;

  /// Height at the top and bottom that something else (a header, a bottom sheet) covers. Fits keep markers clear of it,
  /// and the controls and credit line sit above it.
  final double topInset;
  final double bottomInset;
  final void Function(LatLng point)? onTap;
  final VoidCallback? onMapReady;
  final void Function(MapCamera camera, bool hasGesture)? onPositionChanged;

  /// The person moved the map with a finger (not the app); used to stop following.
  final VoidCallback? onUserGesture;
  final VoidCallback? onMyLocation;
  final bool locating;
  final List<Widget> extraControls;
  final String? semanticsLabel;
  final bool showAttribution;
  final double maxFitZoom;

  @override
  ConsumerState<AlBaharMap> createState() => _AlBaharMapState();
}

Widget _nightTile(BuildContext context, Widget tile, TileImage image) =>
    ColorFiltered(colorFilter: kNightTileFilter, child: tile);

class _AlBaharMapState extends ConsumerState<AlBaharMap> with SingleTickerProviderStateMixin implements AlBaharMapHost {
  late final AlBaharMapController _own = AlBaharMapController();
  AlBaharMapController get _c => widget.controller ?? _own;

  late final TileProvider _tileProvider;
  late final AnimationController _anim = AnimationController(vsync: this);
  LatLng? _animFrom;
  LatLng? _animTo;
  double _zoomFrom = 0;
  double _zoomTo = 0;
  Timer? _noteTimer;
  int _tileErrors = 0;
  bool _noteShown = false;
  bool _note = false;

  @override
  EdgeInsets get fitPadding => EdgeInsets.fromLTRB(48, 48 + widget.topInset, 48, 48 + widget.bottomInset);

  @override
  void initState() {
    super.initState();
    _tileProvider = ref.read(mapTileProviderFactoryProvider)(mapUserAgent());
    _c.attach(this);
    // Ask the server for the latest tile settings (once per app start); the saved copy is already on screen.
    unawaited(
      Future<void>.microtask(() {
        if (mounted) return ref.read(mapConfigProvider.notifier).ensureFresh();
      }),
    );
    _anim.addListener(_onAnimTick);
  }

  @override
  void didUpdateWidget(AlBaharMap old) {
    super.didUpdateWidget(old);
    if (old.controller != widget.controller) {
      (old.controller ?? _own).detach(this);
      _c.attach(this);
    }
    if (old.bottomInset != widget.bottomInset || old.topInset != widget.topInset) {
      // A sheet grew or shrank: keep the framed markers in the visible part unless the person is exploring.
      _c.refitIfUntouched();
    }
  }

  @override
  void dispose() {
    _noteTimer?.cancel();
    _anim.dispose();
    _c.detach(this);
    if (widget.controller == null) _own.dispose();
    super.dispose();
  }

  @override
  void animateCamera(LatLng center, double zoom) {
    final cam = _c.raw.camera;
    if (MediaQuery.disableAnimationsOf(context) || (cam.center == center && cam.zoom == zoom)) {
      stopCameraAnimation();
      _c.raw.move(center, zoom);
      return;
    }
    _animFrom = cam.center;
    _animTo = center;
    _zoomFrom = cam.zoom;
    _zoomTo = zoom;
    // A long hop is quicker than a short one would suggest; a short one never takes under a third of a second.
    final far = MapGeo.distanceMeters(cam.center, center);
    final ms = (350 + math.min(550, far / 40)).round();
    _anim
      ..stop()
      ..duration = Duration(milliseconds: ms);
    _anim.forward(from: 0);
  }

  @override
  void stopCameraAnimation() {
    if (_anim.isAnimating) _anim.stop();
  }

  void _onAnimTick() {
    final from = _animFrom;
    final to = _animTo;
    if (from == null || to == null || !_c.ready) return;
    final t = Curves.easeInOutCubic.transform(_anim.value);
    _c.raw.move(MapGeo.lerpLatLng(from, to, t), _zoomFrom + (_zoomTo - _zoomFrom) * t);
  }

  void _onMapEvent(MapEvent e) {
    final r = e.camera.rotation;
    if (_c.rotation.value != r) _c.rotation.value = r;
    const programmatic = {
      MapEventSource.mapController,
      MapEventSource.fitCamera,
      MapEventSource.custom,
      MapEventSource.nonRotatedSizeChange,
      MapEventSource.interactiveFlagsChanged,
    };
    if (!programmatic.contains(e.source)) {
      stopCameraAnimation();
      _c.userGesture();
      widget.onUserGesture?.call();
    }
  }

  void _onTileError(TileImage tile, Object error, StackTrace? stack) {
    _tileErrors++;
    // One failed tile is normal on a moving connection; a handful in a row means the tile server cannot be reached.
    if (_noteShown || _tileErrors < 4) return;
    _noteShown = true;
    SchedulerBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      setState(() => _note = true);
      _noteTimer = Timer(const Duration(seconds: 7), () {
        if (mounted) setState(() => _note = false);
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final settings = ref.watch(mapConfigProvider);
    final tiles = settings.tiles;
    final theme = Theme.of(context);
    final brightness = theme.brightness;
    final dark = brightness == Brightness.dark;
    final url = tiles.urlFor(brightness);
    final sharp = tiles.retina && url.contains('{r}') && RetinaMode.isHighDensity(context);
    final flags = !widget.interactive
        ? InteractiveFlag.none
        : (widget.rotatable ? InteractiveFlag.all : InteractiveFlag.all & ~InteractiveFlag.rotate);
    final centre = widget.initialCenter ?? settings.center;
    final fitPoints = widget.initialFit;
    final CameraFit? initialFit = fitPoints.isEmpty
        ? null
        : CameraFit.coordinates(
            coordinates: fitPoints,
            padding: fitPadding,
            maxZoom: fitPoints.length == 1 ? math.min(widget.maxFitZoom, 16.0) : widget.maxFitZoom,
            minZoom: 3,
          );
    final background = dark ? const Color(0xFF241B20) : const Color(0xFFEFE8EB);

    final tileLayer = TileLayer(
      urlTemplate: url,
      subdomains: tiles.subdomains.isEmpty ? const ['a', 'b', 'c'] : tiles.subdomains,
      userAgentPackageName: mapUserAgentPackage(),
      tileProvider: _tileProvider,
      maxZoom: tiles.maxZoom.toDouble(),
      maxNativeZoom: tiles.maxZoom,
      retinaMode: sharp,
      tileBuilder: tiles.needsDarkFilter(brightness) ? _nightTile : null,
      errorTileCallback: _onTileError,
      evictErrorTileStrategy: EvictErrorTileStrategy.notVisibleRespectMargin,
    );
    final map = FlutterMap(
      mapController: _c.raw,
      options: MapOptions(
        initialCenter: centre,
        initialZoom: widget.initialZoom ?? settings.zoom,
        initialCameraFit: initialFit,
        minZoom: 3,
        maxZoom: tiles.maxZoom.toDouble(),
        backgroundColor: background,
        interactionOptions: InteractionOptions(flags: flags),
        onMapReady: () {
          _c.markReady();
          widget.onMapReady?.call();
        },
        onMapEvent: _onMapEvent,
        onTap: widget.onTap == null ? null : (_, point) => widget.onTap!(point),
        onPositionChanged: widget.onPositionChanged == null
            ? null
            : (camera, gesture) => widget.onPositionChanged!(camera, gesture),
      ),
      children: [tileLayer, ...widget.children],
    );

    final inset = widget.bottomInset;
    return Semantics(
      container: true,
      label: widget.semanticsLabel ?? l10n.mapSemantics,
      child: ClipRect(
        child: Stack(
          fit: StackFit.expand,
          children: [
            map,
            if (_note)
              PositionedDirectional(
                top: widget.topInset + AppSpace.xs,
                start: AppSpace.md,
                end: AppSpace.md,
                child: Align(
                  alignment: Alignment.topCenter,
                  child: _TilesNote(text: l10n.mapTilesUnavailable),
                ),
              ),
            ...widget.overlays,
            if (widget.showAttribution)
              PositionedDirectional(
                start: AppSpace.xs,
                bottom: inset,
                child: MapAttributionChip(attribution: tiles.attribution),
              ),
            if (widget.controls.any)
              PositionedDirectional(
                end: AppSpace.sm,
                bottom: inset + AppSpace.sm,
                child: MapControls(
                  controller: _c,
                  config: widget.controls,
                  onMyLocation: widget.onMyLocation,
                  locating: widget.locating,
                  extras: widget.extraControls,
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _TilesNote extends StatelessWidget {
  const _TilesNote({required this.text});
  final String text;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Semantics(
      liveRegion: true,
      label: text,
      excludeSemantics: true,
      child: Material(
        color: scheme.inverseSurface.withValues(alpha: 0.92),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        elevation: 2,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: AppSpace.sm, vertical: AppSpace.xs),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(Icons.cloud_off_rounded, size: 16, color: scheme.onInverseSurface),
              const Gap(AppSpace.xs),
              Flexible(
                child: Text(
                  text,
                  style: Theme.of(context).textTheme.labelMedium?.copyWith(color: scheme.onInverseSurface),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
