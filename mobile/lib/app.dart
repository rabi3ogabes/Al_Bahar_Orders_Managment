import 'dart:async';

import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/theme/brand_theme.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:albahar_customer/features/notifications/data/notifications_repository.dart';
import 'package:albahar_customer/features/notifications/presentation/state/notifications_controller.dart';
import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final scaffoldMessengerKey = GlobalKey<ScaffoldMessengerState>();

class AlBaharApp extends ConsumerStatefulWidget {
  const AlBaharApp({super.key, this.watchConnectivity = true});

  /// Disabled in widget tests (no platform channel).
  final bool watchConnectivity;

  @override
  ConsumerState<AlBaharApp> createState() => _AlBaharAppState();
}

class _AlBaharAppState extends ConsumerState<AlBaharApp> with WidgetsBindingObserver {
  StreamSubscription<List<ConnectivityResult>>? _connectivity;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _wirePush();
    if (widget.watchConnectivity) {
      try {
        _connectivity = Connectivity().onConnectivityChanged.listen((results) {
          final online = !results.contains(ConnectivityResult.none) || results.length > 1;
          final wasOffline = !ref.read(onlineProvider);
          ref.read(onlineProvider.notifier).set(online);
          if (online && wasOffline) ref.read(pusherClientProvider).reconnectNow();
        }, onError: (Object _) {});
      } catch (_) {}
    }
  }

  void _wirePush() {
    final push = ref.read(pushServiceProvider);
    final router = ref.read(routerProvider);
    push.onNavigate = (location) {
      // While the splash is showing, hand the location to it instead.
      final current = router.routerDelegate.currentConfiguration.uri.path;
      if (current == '/splash' || current.isEmpty) {
        ref.read(launchLocationProvider.notifier).set(location);
      } else {
        unawaited(router.push(location));
      }
    };
    push.onOpened = (id) {
      if (ref.read(isAuthenticatedProvider)) {
        unawaited(ref.read(notificationsRepositoryProvider).markRead(id).catchError((Object _) {}));
      }
    };
    final pending = push.takePendingLocation();
    if (pending != null) ref.read(launchLocationProvider.notifier).set(pending);
    if (ref.read(isAuthenticatedProvider)) unawaited(push.register().catchError((Object _) {}));
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(ref.read(unreadCountProvider.notifier).refresh());
      ref.read(pusherClientProvider).reconnectNow();
      unawaited(ref.read(brandThemeProvider.notifier).refresh());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_connectivity?.cancel());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(routerProvider);
    final localeCode = ref.watch(localeCodeProvider);
    final locale = Locale(localeCode);
    final themeMode = ref.watch(themeModeProvider);
    final config = ref.watch(appConfigProvider);

    // Register push on sign-in; surface session expiry.
    ref.listen(sessionControllerProvider, (prev, next) {
      if (next.isAuthenticated && !(prev?.isAuthenticated ?? false)) {
        unawaited(ref.read(pushServiceProvider).register().catchError((Object _) {}));
      }
      if (!next.isAuthenticated && next.expired && (prev?.isAuthenticated ?? false)) {
        final l10n = lookupAppLocalizations(locale);
        scaffoldMessengerKey.currentState?.showSnackBar(SnackBar(content: Text(l10n.errorSessionExpired)));
      }
    });

    return MaterialApp.router(
      title: config.displayName,
      debugShowCheckedModeBanner: false,
      routerConfig: router,
      scaffoldMessengerKey: scaffoldMessengerKey,
      locale: locale,
      supportedLocales: supportedLocales,
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      theme: AppTheme.light(locale, brand: ref.watch(brandThemeProvider)),
      darkTheme: AppTheme.dark(locale),
      themeMode: themeMode,
      builder: (context, child) {
        // Cap extreme text scaling to keep layouts usable while still
        // honouring accessibility settings (up to 1.6×).
        final mq = MediaQuery.of(context);
        return MediaQuery(
          data: mq.copyWith(textScaler: mq.textScaler.clamp(minScaleFactor: 0.9, maxScaleFactor: 1.6)),
          child: child ?? const SizedBox.shrink(),
        );
      },
    );
  }
}
