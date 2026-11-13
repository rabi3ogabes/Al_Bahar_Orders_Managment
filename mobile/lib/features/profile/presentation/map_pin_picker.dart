import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/al_bahar_map.dart';
import 'package:albahar_customer/core/map/map_geo.dart';
import 'package:albahar_customer/core/map/map_layers.dart';
import 'package:albahar_customer/core/map/map_markers.dart';
import 'package:albahar_customer/core/map/map_providers.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/haptics.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:latlong2/latlong.dart';

/// Full-screen map for dropping the delivery pin: the pin stays in the middle of the screen and the map moves under it
/// (the pattern people know from ride and delivery apps), lifting a little while the map is dragged. "My location" asks
/// for permission only when tapped and explains each refusal; a spot outside Qatar cannot be confirmed.
class MapPinPicker extends ConsumerStatefulWidget {
  const MapPinPicker({super.key, this.initial});
  final LatLng? initial;

  @override
  ConsumerState<MapPinPicker> createState() => _MapPinPickerState();
}

class _MapPinPickerState extends ConsumerState<MapPinPicker> {
  final _map = AlBaharMapController();
  late final ValueNotifier<LatLng> _center = ValueNotifier(widget.initial ?? ref.read(mapConfigProvider).center);
  final _dragging = ValueNotifier<bool>(false);
  Timer? _settle;
  bool _locating = false;
  LatLng? _me;

  @override
  void dispose() {
    _settle?.cancel();
    _center.dispose();
    _dragging.dispose();
    _map.dispose();
    super.dispose();
  }

  void _moved(MapCamera camera, bool gesture) {
    if (camera.center != _center.value) _center.value = camera.center;
    if (!gesture) return;
    if (!_dragging.value) _dragging.value = true;
    _settle?.cancel();
    // flutter_map has no "finger lifted" callback for flings: a short quiet spell means the pin has landed.
    _settle = Timer(const Duration(milliseconds: 220), () {
      if (mounted) _dragging.value = false;
    });
  }

  void _say(String message, {bool settings = false}) {
    final l10n = context.l10n;
    final messenger = ScaffoldMessenger.of(context);
    messenger
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          action: settings
              ? SnackBarAction(
                  label: l10n.openSettings,
                  onPressed: () => unawaited(ref.read(locationServiceProvider).openSettings()),
                )
              : null,
        ),
      );
  }

  Future<void> _locate() async {
    if (_locating) return;
    final l10n = context.l10n;
    setState(() => _locating = true);
    final location = ref.read(locationServiceProvider);
    try {
      final access = await location.ensurePermission();
      if (!mounted) return;
      switch (access) {
        case LocationAccess.granted:
          final p = await location.currentPosition();
          if (!mounted) return;
          if (p == null || !MapGeo.isFinitePair(p.lat, p.lng)) {
            _say(l10n.pinLocateFailed);
          } else {
            final here = LatLng(p.lat, p.lng);
            setState(() => _me = here);
            _map.flyTo(here, zoom: 17);
            Haptics.tap();
          }
        case LocationAccess.denied:
          _say(l10n.locationDeniedMessage);
        case LocationAccess.deniedForever:
          _say(l10n.pinLocateForever, settings: true);
        case LocationAccess.serviceDisabled:
          _say(l10n.pinLocateServiceOff, settings: true);
      }
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    final settings = ref.watch(mapConfigProvider);
    return Scaffold(
      appBar: AppBar(title: Text(l10n.pinOnMap)),
      body: Column(
        children: [
          Expanded(
            child: Stack(
              children: [
                AlBaharMap(
                  controller: _map,
                  initialCenter: widget.initial ?? settings.center,
                  initialZoom: widget.initial != null ? 17 : 12,
                  onPositionChanged: _moved,
                  controls: const MapControlsConfig(recenter: false, myLocation: true),
                  onMyLocation: _locate,
                  locating: _locating,
                  semanticsLabel: l10n.pinCenterSemantics,
                  children: [if (_me != null) ...myLocationLayers(context, at: _me!, label: l10n.mapYouAreHere)],
                ),
                Positioned.fill(
                  child: IgnorePointer(
                    child: ValueListenableBuilder<bool>(
                      valueListenable: _dragging,
                      builder: (context, lifted, _) => _CentrePin(lifted: lifted, label: l10n.pinSet),
                    ),
                  ),
                ),
                PositionedDirectional(
                  top: AppSpace.sm,
                  start: AppSpace.md,
                  end: AppSpace.md,
                  child: IgnorePointer(
                    child: Material(
                      color: context.colors.surface.withValues(alpha: 0.94),
                      borderRadius: AppRadius.inputAll,
                      elevation: 2,
                      child: Padding(
                        padding: const EdgeInsets.all(AppSpace.sm),
                        child: Text(l10n.moveMapToPin, textAlign: TextAlign.center, style: context.text.bodySmall),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
          ValueListenableBuilder<LatLng>(
            valueListenable: _center,
            builder: (context, c, _) {
              final inside = MapGeo.isInQatar(c.latitude, c.longitude);
              return Material(
                color: context.colors.surface,
                elevation: 8,
                child: SafeArea(
                  top: false,
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(AppSpace.gutter, AppSpace.sm, AppSpace.gutter, AppSpace.sm),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (!inside)
                          Padding(
                            padding: const EdgeInsets.only(bottom: AppSpace.xs),
                            child: Semantics(
                              liveRegion: true,
                              child: Row(
                                children: [
                                  Icon(Icons.error_outline_rounded, size: 18, color: context.palette.danger),
                                  const Gap(AppSpace.xs),
                                  Expanded(
                                    child: Text(
                                      l10n.pinOutsideQatar,
                                      style: context.text.bodySmall?.copyWith(color: context.palette.danger),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        AppButton(
                          label: l10n.confirmLocation,
                          onPressed: inside ? () => Navigator.of(context).pop(_center.value) : null,
                        ),
                      ],
                    ),
                  ),
                ),
              );
            },
          ),
        ],
      ),
    );
  }
}

/// The pin fixed in the middle of the map: its tip is the exact centre. While the map moves it rises a few pixels and a
/// small ring marks the spot underneath, so the picked point is never hidden by the pin itself.
class _CentrePin extends StatelessWidget {
  const _CentrePin({required this.lifted, required this.label});
  final bool lifted;
  final String label;

  @override
  Widget build(BuildContext context) {
    final reduce = MediaQuery.disableAnimationsOf(context);
    final duration = reduce ? Duration.zero : AppDurations.fast;
    final scheme = Theme.of(context).colorScheme;
    return Stack(
      alignment: Alignment.center,
      children: [
        // The exact spot.
        AnimatedContainer(
          duration: duration,
          width: lifted ? 14 : 8,
          height: lifted ? 14 : 8,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: scheme.primary.withValues(alpha: lifted ? 0.18 : 0.5),
            border: Border.all(color: scheme.primary, width: 1.5),
          ),
        ),
        AnimatedAlign(
          duration: duration,
          alignment: Alignment.center,
          child: AnimatedContainer(
            duration: duration,
            transform: Matrix4.translationValues(0, -(MapPin.height / 2) - (lifted ? 10 : 0), 0),
            child: ExcludeSemantics(
              child: MapPin(kind: PinKind.destination, semanticsLabel: label),
            ),
          ),
        ),
      ],
    );
  }
}

/// A small, non-interactive map showing the saved pin (inside the address form).
class PinPreview extends StatelessWidget {
  const PinPreview({super.key, required this.pin, required this.label});
  final LatLng pin;
  final String label;

  @override
  Widget build(BuildContext context) => AlBaharMap(
    initialCenter: pin,
    initialZoom: 16,
    interactive: false,
    controls: MapControlsConfig.none,
    semanticsLabel: label,
    children: [
      MarkerLayer(
        markers: [pinMarker(pin, kind: PinKind.destination, label: label)],
      ),
    ],
  );
}
