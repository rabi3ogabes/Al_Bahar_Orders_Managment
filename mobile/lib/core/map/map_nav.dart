import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:url_launcher/url_launcher.dart';

/// The turn-by-turn apps a driver can hand the trip to.
enum NavApp {
  googleMaps('Google Maps'),
  appleMaps('Apple Maps'),
  waze('Waze');

  const NavApp(this.title);

  /// Brand names are not translated.
  final String title;
}

/// One way to start navigation: the app's own link (opens the app straight away) and a web link that every phone can
/// open (the app if it is installed, the browser if not).
@immutable
class NavChoice {
  const NavChoice({required this.app, required this.native, required this.web});
  final NavApp app;
  final Uri? native;
  final Uri web;
}

abstract final class MapNavigation {
  static const _lastKey = 'nav_last_app';

  /// Every app that makes sense on [platform] for a trip to [lat],[lng], in the order they are offered.
  static List<NavChoice> choices(double lat, double lng, {required TargetPlatform platform}) {
    final ll = '$lat,$lng';
    final ios = platform == TargetPlatform.iOS || platform == TargetPlatform.macOS;
    return [
      NavChoice(
        app: NavApp.googleMaps,
        native: ios
            ? Uri.parse('comgooglemaps://?daddr=$ll&directionsmode=driving')
            : Uri.parse('google.navigation:q=$ll&mode=d'),
        web: Uri.https('www.google.com', '/maps/dir/', {'api': '1', 'destination': ll, 'travelmode': 'driving'}),
      ),
      if (ios) NavChoice(app: NavApp.appleMaps, native: null, web: Uri.https('maps.apple.com', '/', {'daddr': ll, 'dirflg': 'd'})),
      NavChoice(
        app: NavApp.waze,
        native: Uri.parse('waze://?ll=$ll&navigate=yes'),
        web: Uri.https('waze.com', '/ul', {'ll': ll, 'navigate': 'yes'}),
      ),
    ];
  }

  /// The choices a phone can really use: an app whose own link cannot be opened is dropped (it is not installed), except
  /// the ones that have a universal web link that works on every phone (Google Maps, Apple Maps on iOS).
  static Future<List<NavChoice>> available(
    List<NavChoice> all, {
    required Future<bool> Function(Uri uri) canLaunch,
  }) async {
    final out = <NavChoice>[];
    for (final c in all) {
      if (c.app == NavApp.googleMaps || c.app == NavApp.appleMaps) {
        out.add(c);
        continue;
      }
      var installed = false;
      try {
        installed = c.native != null && await canLaunch(c.native!);
      } catch (_) {}
      if (installed) out.add(c);
    }
    return out;
  }

  /// Puts the app used last first.
  static List<NavChoice> ordered(List<NavChoice> choices, NavApp? last) {
    if (last == null) return choices;
    return [...choices.where((c) => c.app == last), ...choices.where((c) => c.app != last)];
  }

  /// Opens [choice]: the app's own link first, then the web link. False when neither could be opened.
  static Future<bool> launch(NavChoice choice, {required Future<bool> Function(Uri uri) open}) async {
    for (final uri in [?choice.native, choice.web]) {
      try {
        if (await open(uri)) return true;
      } catch (_) {}
    }
    return false;
  }

  static Future<NavApp?> lastUsed() async {
    try {
      final name = (await SharedPreferences.getInstance()).getString(_lastKey);
      return NavApp.values.where((a) => a.name == name).firstOrNull;
    } catch (_) {
      return null;
    }
  }

  static Future<void> remember(NavApp app) async {
    try {
      await (await SharedPreferences.getInstance()).setString(_lastKey, app.name);
    } catch (_) {}
  }
}

Future<bool> _open(Uri uri) => launchUrl(uri, mode: LaunchMode.externalApplication);

/// Starts navigation to a point. One installed app starts at once; several show a short chooser (last used first).
/// Returns false when nothing on the phone could open the trip, so the caller can say so.
Future<bool> startNavigation(
  BuildContext context, {
  required double lat,
  required double lng,
  TargetPlatform? platform,
  Future<bool> Function(Uri uri)? canLaunch,
  Future<bool> Function(Uri uri)? open,
}) async {
  final launcher = open ?? _open;
  final all = MapNavigation.choices(lat, lng, platform: platform ?? defaultTargetPlatform);
  final usable = await MapNavigation.available(all, canLaunch: canLaunch ?? canLaunchUrl);
  if (!context.mounted) return false;
  final options = MapNavigation.ordered(usable, await MapNavigation.lastUsed());
  if (!context.mounted) return false;
  NavChoice? pick;
  if (options.length == 1) {
    pick = options.first;
  } else if (options.isNotEmpty) {
    pick = await showModalBottomSheet<NavChoice>(
      context: context,
      showDragHandle: true,
      useSafeArea: true,
      builder: (ctx) => _NavSheet(options: options),
    );
  }
  if (pick == null) return true; // the driver closed the chooser: nothing failed
  final ok = await MapNavigation.launch(pick, open: launcher);
  if (ok) await MapNavigation.remember(pick.app);
  return ok;
}

class _NavSheet extends StatelessWidget {
  const _NavSheet({required this.options});
  final List<NavChoice> options;

  @override
  Widget build(BuildContext context) {
    final l10n = context.l10n;
    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpace.gutter, 0, AppSpace.gutter, AppSpace.xs),
            child: Text(l10n.mapNavigateWith, style: Theme.of(context).textTheme.titleMedium),
          ),
          for (final o in options)
            ListTile(
              minTileHeight: 56,
              leading: const Icon(Icons.navigation_rounded),
              title: Text(o.app.title),
              onTap: () => Navigator.pop(context, o),
            ),
        ],
      ),
    );
  }
}
