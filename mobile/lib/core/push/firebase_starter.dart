import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart';
import 'package:package_info_plus/package_info_plus.dart';

import 'firebase_options_source.dart';

/// Starts the default Firebase app, in this order:
///  1. the native config files the build carries (`google-services.json` / `GoogleService-Info.plist`), so a build that
///     has them keeps working exactly as before;
///  2. the options the server hands out (dashboard → Push), on Android and iPhone only (the web app has its own flow).
/// [start] answers false when neither works: push is unavailable and the caller tries again later.
class FirebaseStarter {
  FirebaseStarter({
    required this.options,
    TargetPlatform? platform,
    bool Function()? isStarted,
    Future<void> Function()? fromNativeFiles,
    Future<void> Function(FirebaseOptions options)? fromOptions,
    Future<String?> Function()? bundleId,
  }) : _platform = platform ?? defaultTargetPlatform,
       _isStarted = isStarted ?? (() => Firebase.apps.isNotEmpty),
       _fromNativeFiles = fromNativeFiles ?? (() async => await Firebase.initializeApp()),
       _fromOptions = fromOptions ?? ((o) async => await Firebase.initializeApp(options: o)),
       _bundleId = bundleId ?? _packageName;

  final FirebaseOptionsSource options;
  final TargetPlatform _platform;
  final bool Function() _isStarted;
  final Future<void> Function() _fromNativeFiles;
  final Future<void> Function(FirebaseOptions options) _fromOptions;
  final Future<String?> Function() _bundleId;

  bool get _serverOptionsAllowed => !kIsWeb && (_platform == TargetPlatform.android || _platform == TargetPlatform.iOS);

  Future<bool> start() async {
    if (_isStarted()) return true;
    try {
      await _fromNativeFiles();
      return true;
    } catch (e) {
      debugPrint('Firebase: no native config files (${e.runtimeType})');
    }
    if (!_serverOptionsAllowed) return false;

    final loaded = await options.load(iosBundleId: _platform == TargetPlatform.iOS ? await _bundleId() : null);
    if (loaded == null) return false;
    try {
      await _fromOptions(loaded);
      return true;
    } catch (e) {
      debugPrint('Firebase: the options from the server did not start it (${e.runtimeType})');
      return false;
    }
  }

  static Future<String?> _packageName() async {
    try {
      return (await PackageInfo.fromPlatform()).packageName;
    } catch (_) {
      return null;
    }
  }
}
