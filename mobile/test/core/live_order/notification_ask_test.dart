import 'dart:async';

import 'package:albahar_customer/core/live_order/live_order_controller.dart';
import 'package:albahar_customer/core/live_order/live_order_platform.dart';
import 'package:albahar_customer/core/live_order/notification_ask.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/storage/app_preferences.dart';
import 'package:albahar_customer/features/auth/presentation/state/session_controller.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../helpers/fakes.dart';
import 'live_order_fakes.dart';

final _now = DateTime.utc(2026, 11, 16, 9);

/// Whether somebody is signed in, driven by the test.
class _Signed extends Notifier<bool> {
  _Signed(this.initial);
  final bool initial;

  @override
  bool build() => initial;

  void set(bool value) => state = value;
}

final _signedIn = NotifierProvider<_Signed, bool>(() => _Signed(true));

/// A phone that counts how often the app looked at its notification permission.
class _Phone extends FakePlatform {
  int reads = 0;

  @override
  Future<LiveOrderPermission> permission() {
    reads++;
    return super.permission();
  }
}

class _Rig {
  _Rig(this.prefs, {required bool signedIn}) : _signedInAtStart = signedIn {
    controller = LiveOrderController(platform: phone, api: FakeLiveOrderApi(), prefs: prefs, localeCode: () => 'en');
  }

  final AppPreferences prefs;
  final bool _signedInAtStart;
  final phone = _Phone();
  late final LiveOrderController controller;
  DateTime now = _now;
  int answered = 0;

  static Future<_Rig> create({bool signedIn = true}) async {
    SharedPreferences.setMockInitialValues({});
    return _Rig(AppPreferences(await SharedPreferences.getInstance()), signedIn: signedIn);
  }

  Widget app({
    Locale locale = const Locale('en'),
    Duration delay = const Duration(seconds: 3),
    int maxDeferrals = 10,
    bool Function(BuildContext)? isFront,
    Widget Function(Widget ask)? around,
  }) {
    final ask = NotificationAsk(
      delay: delay,
      maxDeferrals: maxDeferrals,
      now: () => now,
      isFront: isFront ?? isFrontPage,
      onAnswered: () async => answered++,
      child: const Text('home'),
    );
    return ProviderScope(
      overrides: [
        _signedIn.overrideWith(() => _Signed(_signedInAtStart)),
        isAuthenticatedProvider.overrideWith((ref) => ref.watch(_signedIn)),
        liveOrderPlatformProvider.overrideWithValue(phone),
        appPreferencesProvider.overrideWithValue(prefs),
        liveOrderControllerProvider.overrideWithValue(controller),
      ],
      child: wrapApp(around == null ? ask : around(ask), locale: locale),
    );
  }

  /// Signs in or out from the test.
  void signIn(WidgetTester tester, bool value) =>
      ProviderScope.containerOf(tester.element(find.text('home'))).read(_signedIn.notifier).set(value);
}

const _title = 'Stay updated on your orders and offers';

Future<void> wait(WidgetTester tester, int seconds) async {
  await tester.pump(Duration(seconds: seconds));
  await tester.pumpAndSettle();
}

void main() {
  group('when the customer is asked', () {
    testWidgets('about three seconds after the page appears, not before', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());

      await tester.pump(const Duration(milliseconds: 2900));
      expect(find.text(_title), findsNothing);
      expect(rig.phone.reads, 0, reason: 'nothing is even looked at before the delay');

      await tester.pump(const Duration(milliseconds: 200));
      await tester.pumpAndSettle();
      expect(find.text(_title), findsOneWidget);
      expect(find.text('Allow notifications'), findsOneWidget);
      expect(find.text('Not now'), findsOneWidget);
      expect(rig.prefs.livePermissionPrompt.count, 1);
    });

    testWidgets('the sheet talks about orders and offers, not about one order, in English and Arabic', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
      expect(find.textContaining('delivery news and offers'), findsOneWidget);
      expect(find.text('Follow your order live'), findsNothing);

      final arabic = await _Rig.create();
      arabic.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(arabic.app(locale: const Locale('ar')));
      await wait(tester, 3);
      expect(find.text('ابقَ على اطلاع بطلباتك وعروضك'), findsOneWidget);
      expect(find.text('السماح بالإشعارات'), findsOneWidget);
    });

    testWidgets('allow: the system is asked, and the server is told the answer', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);

      await tester.tap(find.text('Allow notifications'));
      await tester.pumpAndSettle();
      expect(rig.phone.requestCalls, 1);
      expect(rig.answered, 1);
      expect(find.text(_title), findsNothing);
    });

    testWidgets('blocked in the phone settings: the settings are offered, in the same sheet', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.blocked;
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);

      expect(find.text('Notifications are off'), findsOneWidget);
      expect(find.textContaining('Turn them on in settings to get order updates'), findsOneWidget);
      await tester.tap(find.text('Open settings'));
      await tester.pumpAndSettle();
      expect(rig.phone.openSettingsCalls, 1);
      expect(rig.phone.requestCalls, 0);
    });

    testWidgets('once while the page lives: "not now" and a long wait do not bring the sheet back', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);
      await tester.tap(find.text('Not now'));
      await tester.pumpAndSettle();
      expect(rig.phone.reads, 1);

      await wait(tester, 60);
      expect(find.text(_title), findsNothing);
      expect(rig.phone.reads, 1, reason: 'no second look, no second counter');
      expect(rig.phone.requestCalls, 0);
    });
  });

  group('the frequency rule stays the only counter', () {
    testWidgets('notifications already on: nothing is shown and nothing is counted', (tester) async {
      final rig = await _Rig.create();
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);
      expect(rig.phone.reads, 1);
      expect(find.text(_title), findsNothing);
      expect(rig.prefs.livePermissionPrompt.count, 0);
    });

    testWidgets('after a "not now" the sheet waits two weeks', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await rig.prefs.setLivePermissionPrompt(_now.subtract(const Duration(days: 2)), 1);
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);
      expect(rig.phone.reads, 1);
      expect(find.text(_title), findsNothing);
      expect(rig.prefs.livePermissionPrompt.count, 1);

      rig.now = _now.add(const Duration(days: 20));
      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
      expect(rig.prefs.livePermissionPrompt.count, 2);
    });

    testWidgets('three refusals are enough', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await rig.prefs.setLivePermissionPrompt(_now.subtract(const Duration(days: 90)), 3);
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);
      expect(find.text(_title), findsNothing);
    });

    testWidgets('signing out and in on the same page asks again only if the rule says so', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await wait(tester, 3);
      await tester.tap(find.text('Not now'));
      await tester.pumpAndSettle();

      rig.signIn(tester, false);
      await tester.pump();
      rig.signIn(tester, true);
      await tester.pump();
      await wait(tester, 3);
      expect(rig.phone.reads, 2, reason: 'looked again for the new session');
      expect(find.text(_title), findsNothing, reason: 'but the two-week rule holds');
    });
  });

  group('only for a signed-in customer', () {
    testWidgets('a guest is never asked', (tester) async {
      final rig = await _Rig.create(signedIn: false);
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await wait(tester, 30);
      expect(rig.phone.reads, 0);
      expect(find.text(_title), findsNothing);
    });

    testWidgets('signing in on the page starts the wait from then', (tester) async {
      final rig = await _Rig.create(signedIn: false);
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await wait(tester, 10);

      rig.signIn(tester, true);
      await tester.pump(const Duration(seconds: 2));
      expect(find.text(_title), findsNothing);
      await wait(tester, 1);
      expect(find.text(_title), findsOneWidget);
    });

    testWidgets('signing out before the delay cancels it', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await tester.pump(const Duration(seconds: 2));

      rig.signIn(tester, false);
      await wait(tester, 10);
      expect(rig.phone.reads, 0);
      expect(find.text(_title), findsNothing);
    });

    testWidgets('leaving the page before the delay leaves no timer behind and asks nothing', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await tester.pump(const Duration(seconds: 1));

      await tester.pumpWidget(const SizedBox.shrink());
      await tester.pump(const Duration(seconds: 10));
      expect(rig.phone.reads, 0);
    });
  });

  group('only when the page is in front', () {
    testWidgets('a moment that is not right is tried again, a little later', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      var front = false;
      await tester.pumpWidget(rig.app(isFront: (_) => front));
      await wait(tester, 3);
      expect(rig.phone.reads, 0);

      front = true;
      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
    });

    testWidgets('it gives up after a few tries, for as long as the page lives', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      var front = false;
      await tester.pumpWidget(rig.app(maxDeferrals: 2, isFront: (_) => front));
      await wait(tester, 30);

      front = true;
      await wait(tester, 30);
      expect(rig.phone.reads, 0);
      expect(find.text(_title), findsNothing);
    });

    testWidgets('a window that is open (another sheet or dialog) is never covered', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      await tester.pump(const Duration(seconds: 2));

      final context = tester.element(find.text('home'));
      unawaited(
        showDialog<void>(
          context: context,
          builder: (_) => const AlertDialog(title: Text('Another window')),
        ),
      );
      await tester.pumpAndSettle();
      await wait(tester, 2);
      expect(find.text('Another window'), findsOneWidget);
      expect(rig.phone.reads, 0);
      expect(find.text(_title), findsNothing);

      Navigator.of(context).pop();
      await tester.pumpAndSettle();
      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
    });

    testWidgets('inside a tab shell, a window on the app\'s own navigator counts too', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      late BuildContext outer;
      await tester.pumpWidget(
        rig.app(
          around: (ask) => Builder(
            builder: (context) {
              outer = context;
              return Navigator(onGenerateRoute: (_) => MaterialPageRoute<void>(builder: (_) => ask));
            },
          ),
        ),
      );
      unawaited(
        showDialog<void>(
          context: outer,
          builder: (_) => const AlertDialog(title: Text('Window on the root')),
        ),
      );
      await tester.pumpAndSettle();
      await wait(tester, 6);
      expect(find.text('Window on the root'), findsOneWidget);
      expect(rig.phone.reads, 0);

      Navigator.of(outer, rootNavigator: true).pop();
      await tester.pumpAndSettle();
      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
    });

    testWidgets('a page on another page of the stack waits until it is shown again', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      final context = tester.element(find.text('home'));
      unawaited(
        Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => const Scaffold(body: Text('detail')))),
      );
      await tester.pumpAndSettle();

      await wait(tester, 6);
      expect(find.text('detail'), findsOneWidget);
      expect(rig.phone.reads, 0);

      Navigator.of(context).pop();
      await tester.pumpAndSettle();
      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
    });

    testWidgets('a hidden tab is not asked', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      var visible = false;
      late StateSetter update;
      await tester.pumpWidget(
        rig.app(
          around: (ask) => StatefulBuilder(
            builder: (context, setState) {
              update = setState;
              return TickerMode(enabled: visible, child: ask);
            },
          ),
        ),
      );
      await wait(tester, 6);
      expect(rig.phone.reads, 0);

      update(() => visible = true);
      await tester.pump();
      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
    });

    testWidgets('the app in the background is not asked', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      await tester.pumpWidget(rig.app());
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      await tester.pump(const Duration(seconds: 6));
      expect(rig.phone.reads, 0);

      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
    });

    testWidgets('a window that opens while the permission is being read is not covered either', (tester) async {
      final rig = await _Rig.create();
      rig.phone.state = LiveOrderPermission.denied;
      var calls = 0;
      await tester.pumpWidget(
        // The first check (before the permission is read) passes; the second (right before the sheet) finds a window open.
        rig.app(isFront: (_) => ++calls != 2),
      );
      await wait(tester, 3);
      expect(rig.phone.reads, 1);
      expect(find.text(_title), findsNothing);
      expect(rig.prefs.livePermissionPrompt.count, 0, reason: 'a question that was not asked is not counted');

      await wait(tester, 3);
      expect(find.text(_title), findsOneWidget);
    });
  });
}
