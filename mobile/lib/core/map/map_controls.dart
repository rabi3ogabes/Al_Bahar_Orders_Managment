import 'dart:math' as math;

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/map/map_controller.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/material.dart';

/// Which round buttons the map shows in its corner.
@immutable
class MapControlsConfig {
  const MapControlsConfig({
    this.recenter = true,
    this.follow = false,
    this.compass = true,
    this.zoom = false,
    this.myLocation = false,
  });

  /// No buttons (small previews).
  static const none = MapControlsConfig(recenter: false, compass: false);

  /// "Show everything" (fit all markers).
  final bool recenter;

  /// Follow the live marker; a drag turns it off again.
  final bool follow;

  /// Appears only while the map is turned away from north.
  final bool compass;
  final bool zoom;

  /// Asks [AlBaharMap.onMyLocation] for the phone's position.
  final bool myLocation;

  bool get any => recenter || follow || compass || zoom || myLocation;
}

/// A column of 48 dp round buttons for the map's bottom-end corner (the left in right-to-left languages).
class MapControls extends StatelessWidget {
  const MapControls({
    super.key,
    required this.controller,
    required this.config,
    this.onMyLocation,
    this.locating = false,
    this.extras = const [],
  });

  final AlBaharMapController controller;
  final MapControlsConfig config;
  final VoidCallback? onMyLocation;
  final bool locating;
  final List<Widget> extras;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return ListenableBuilder(
      listenable: Listenable.merge([controller, controller.rotation]),
      builder: (context, _) {
        final turned = controller.rotation.value.abs() > 0.5;
        final buttons = <Widget>[
          if (config.compass && turned)
            MapControlButton(
              icon: Transform.rotate(
                angle: -controller.rotation.value * math.pi / 180,
                child: const Icon(Icons.explore_rounded),
              ),
              label: l10n.mapCompass,
              onPressed: controller.resetNorth,
            ),
          if (config.zoom) ...[
            MapControlButton(
              icon: const Icon(Icons.add_rounded),
              label: l10n.mapZoomIn,
              onPressed: () => controller.zoomBy(1),
            ),
            MapControlButton(
              icon: const Icon(Icons.remove_rounded),
              label: l10n.mapZoomOut,
              onPressed: () => controller.zoomBy(-1),
            ),
          ],
          if (config.myLocation)
            MapControlButton(
              icon: locating
                  ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.my_location_rounded),
              label: l10n.mapMyLocation,
              onPressed: locating ? null : onMyLocation,
            ),
          if (config.follow)
            MapControlButton(
              icon: Icon(controller.following ? Icons.navigation_rounded : Icons.navigation_outlined),
              label: l10n.mapFollow,
              selected: controller.following,
              onPressed: controller.toggleFollow,
            ),
          if (config.recenter)
            MapControlButton(
              icon: const Icon(Icons.center_focus_strong_rounded),
              label: l10n.mapRecenter,
              onPressed: controller.recenter,
            ),
          ...extras,
        ];
        return Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            for (var i = 0; i < buttons.length; i++) ...[if (i > 0) const Gap(AppSpace.xs), buttons[i]],
          ],
        );
      },
    );
  }
}

/// One 48 dp round map button with a screen-reader label, a tooltip where the screen has an Overlay, and a selected
/// state for toggles.
class MapControlButton extends StatelessWidget {
  const MapControlButton({
    super.key,
    required this.icon,
    required this.label,
    required this.onPressed,
    this.selected = false,
  });

  final Widget icon;
  final String label;
  final VoidCallback? onPressed;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final bg = selected ? scheme.primary : (dark ? const Color(0xFF2E2328) : Colors.white);
    final fg = selected ? scheme.onPrimary : (dark ? const Color(0xFFF1ECE3) : const Color(0xFF2A1A20));
    final button = Material(
      color: bg,
      shape: const CircleBorder(),
      elevation: 3,
      shadowColor: Colors.black45,
      child: InkWell(
        customBorder: const CircleBorder(),
        onTap: onPressed,
        child: SizedBox(
          width: kMinTouchTarget,
          height: kMinTouchTarget,
          child: IconTheme(
            data: IconThemeData(color: onPressed == null ? fg.withValues(alpha: 0.4) : fg, size: 24),
            child: Center(child: icon),
          ),
        ),
      ),
    );
    return Semantics(
      button: true,
      enabled: onPressed != null,
      selected: selected,
      label: label,
      excludeSemantics: true,
      onTap: onPressed,
      // A Tooltip needs an Overlay above it; a map shown outside a Navigator simply has no tooltip.
      child: Overlay.maybeOf(context) == null ? button : Tooltip(message: label, excludeFromSemantics: true, child: button),
    );
  }
}
