import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/router/panel_links.dart' as panel_links;
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

/// Opens the place a banner or a promotion points to. A category, product, auction, brand, search or page opens through the app's
/// own router (a page in its side window, like every other page link); an `https://` address opens in the phone's browser. A link
/// that cannot open (an unknown type, an empty value, an address that is not `https://`) does nothing, and a browser that cannot
/// be started says so in a small message. Nothing here ever throws into the screen.
///
/// The two doors are fields so a test can watch them: the app uses [defaultOpenLocation] and [defaultLaunch].
class HomeLinkOpener {
  const HomeLinkOpener({this.openLocation = defaultOpenLocation, this.launch = defaultLaunch});

  final Future<void> Function(BuildContext context, String location) openLocation;
  final Future<bool> Function(Uri uri) launch;

  static Future<void> defaultOpenLocation(BuildContext context, String location) =>
      panel_links.openLocation(context, location);

  static Future<bool> defaultLaunch(Uri uri) => launchUrl(uri, mode: LaunchMode.externalApplication);

  Future<void> open(BuildContext context, HomeLink? link) async {
    if (link == null || !link.isOpenable) return;
    final uri = link.externalUri;
    if (link.isExternal) {
      if (uri == null) return;
      var opened = false;
      try {
        opened = await launch(uri);
      } catch (_) {
        opened = false;
      }
      if (!opened && context.mounted) showInfoSnack(context, context.l10n.homeLinkOpenFailed);
      return;
    }
    final location = link.location;
    if (location == null || !context.mounted) return;
    try {
      await openLocation(context, location);
    } catch (_) {
      // A destination that cannot open must never take the home down with it.
    }
  }
}

/// How links of the home open; replaced in tests.
final homeLinkOpenerProvider = Provider<HomeLinkOpener>((ref) => const HomeLinkOpener());

/// Opens [link] (a banner's or a promotion's). Null does nothing.
Future<void> openHomeLink(BuildContext context, HomeLink? link) async {
  if (link == null) return;
  final opener = ProviderScope.containerOf(context, listen: false).read(homeLinkOpenerProvider);
  await opener.open(context, link);
}
