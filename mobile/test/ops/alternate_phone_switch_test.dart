// The dashboard's "Alternative phone number" switch in the driver and staff apps: `GET /app-config` → `alternate_phone_enabled`
// (on by default, remembered on the phone, asked again whenever the app comes back). Off: no alternative number and no call button
// on a delivery card, a delivery window or a staff order. On: exactly as before.
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../helpers/fakes.dart';
import 'ops_test_kit.dart';

const _phone = Variant('390 en', Size(390, 844), kEn);
const _phoneAr = Variant('390 ar', Size(390, 844), kAr);

const _number = '+97455987654';
const _cacheKey = OpsAlternatePhoneEnabledController.cacheKey;

Future<SharedPreferences> _prefs([Map<String, Object> values = const {}]) async {
  SharedPreferences.setMockInitialValues(values);
  return SharedPreferences.getInstance();
}

void main() {
  group('the setting', () {
    test('on unless the server says it is off; a missing or wrongly typed value keeps it on', () {
      expect(const OpsAppConfig().alternatePhoneEnabled, isTrue);
      expect(OpsAppConfig.fromJson(const {}).alternatePhoneEnabled, isTrue);
      expect(OpsAppConfig.fromJson({'alternate_phone_enabled': true}).alternatePhoneEnabled, isTrue);
      expect(OpsAppConfig.fromJson({'alternate_phone_enabled': false}).alternatePhoneEnabled, isFalse);
      expect(OpsAppConfig.fromJson({'alternate_phone_enabled': 0}).alternatePhoneEnabled, isFalse);
      expect(OpsAppConfig.fromJson({'alternate_phone_enabled': 'false'}).alternatePhoneEnabled, isFalse);
      expect(OpsAppConfig.fromJson({'alternate_phone_enabled': null}).alternatePhoneEnabled, isTrue);
      expect(OpsAppConfig.fromJson({'alternate_phone_enabled': <String>[]}).alternatePhoneEnabled, isTrue);
    });

    test('is read together with the status style, from one public request', () async {
      final h = await TestHarness.create(
        handler: (_) => ok({
          'ops': {'status_style': 'slide'},
          'alternate_phone_enabled': false,
        }),
      );
      final config = await h.read(opsRepositoryProvider).appConfig();
      expect(config.statusStyle, OpsStatusStyle.slide);
      expect(config.alternatePhoneEnabled, isFalse);
      final request = h.adapter.where('GET', '/app-config').single;
      expect(request.header('Authorization'), isNull, reason: 'a public call: no token');
      // The older call still answers from the same request.
      expect(await h.read(opsRepositoryProvider).statusStyle(), OpsStatusStyle.slide);
    });

    test(
      'an answer that is not the dashboard JSON throws, so it can never switch the number off by accident',
      () async {
        final h = await TestHarness.create(handler: (_) => const FakeReply(200, 'welcome to the cafe wifi'));
        await expectLater(h.read(opsRepositoryProvider).appConfig(), throwsA(isA<FormatException>()));
      },
    );
  });

  group('the provider', () {
    Future<ProviderContainer> container(FakeOps repo, SharedPreferences prefs) async {
      final c = ProviderContainer(
        overrides: [opsRepositoryProvider.overrideWithValue(repo), sharedPreferencesProvider.overrideWithValue(prefs)],
        retry: (_, _) => null,
      );
      addTearDown(c.dispose);
      return c;
    }

    Future<void> settleAsync() async {
      await Future<void>.delayed(Duration.zero);
      await Future<void>.delayed(Duration.zero);
    }

    test('starts on when the phone knows nothing, and never throws', () async {
      final repo = FakeOps()..styleError = apiError(ApiErrorKind.offline, 0);
      final c = await container(repo, await _prefs());
      c.read(opsStatusStyleProvider);
      expect(c.read(opsAlternatePhoneEnabledProvider), isTrue);
      await settleAsync();
      expect(c.read(opsAlternatePhoneEnabledProvider), isTrue);
    });

    test('a phone without any storage (nothing overridden) starts on', () async {
      final c = ProviderContainer(
        overrides: [opsRepositoryProvider.overrideWithValue(FakeOps())],
        retry: (_, _) => null,
      );
      addTearDown(c.dispose);
      expect(c.read(opsAlternatePhoneEnabledProvider), isTrue);
      await c.read(opsAlternatePhoneEnabledProvider.notifier).set(false);
      expect(c.read(opsAlternatePhoneEnabledProvider), isFalse, reason: 'even when it cannot be remembered');
    });

    test('the server turning it off reaches the phone and is remembered for the next start', () async {
      final prefs = await _prefs();
      final repo = FakeOps()..alternatePhoneEnabled = false;
      final c = await container(repo, prefs);
      c.read(opsStatusStyleProvider);
      expect(c.read(opsAlternatePhoneEnabledProvider), isTrue, reason: 'on until the answer arrives');
      await settleAsync();
      expect(c.read(opsAlternatePhoneEnabledProvider), isFalse);
      expect(prefs.getBool(_cacheKey), isFalse);
      expect(repo.styleLoads, 1, reason: 'the status style and the switch come from one request');

      // The next start (a new container over the same storage) begins with it, before any answer.
      final second = await container(FakeOps()..styleError = apiError(ApiErrorKind.offline, 0), prefs);
      expect(second.read(opsAlternatePhoneEnabledProvider), isFalse);
    });

    test('offline: the last value the phone saw stays', () async {
      final repo = FakeOps()..styleError = apiError(ApiErrorKind.timeout, 0);
      final c = await container(repo, await _prefs({_cacheKey: false}));
      c.read(opsStatusStyleProvider);
      expect(c.read(opsAlternatePhoneEnabledProvider), isFalse);
      await settleAsync();
      expect(c.read(opsAlternatePhoneEnabledProvider), isFalse);
    });

    test('the dashboard switching it back on reaches a phone that had it off, whenever the app asks again', () async {
      final prefs = await _prefs({_cacheKey: false});
      final repo = FakeOps();
      final c = await container(repo, prefs);
      c.read(opsStatusStyleProvider);
      expect(c.read(opsAlternatePhoneEnabledProvider), isFalse);
      await settleAsync();
      expect(c.read(opsAlternatePhoneEnabledProvider), isTrue);
      expect(prefs.getBool(_cacheKey), isTrue);

      repo.alternatePhoneEnabled = false;
      await c.read(opsStatusStyleProvider.notifier).refresh();
      expect(c.read(opsAlternatePhoneEnabledProvider), isFalse);
      expect(repo.styleLoads, 2);
    });
  });

  group('the driver app', () {
    Future<ProviderContainer> pump(
      WidgetTester tester,
      FakeOps repo, {
      Variant variant = _phone,
      Map<String, Object> prefs = const {},
    }) async {
      useVariant(tester, variant);
      final sp = await _prefs(prefs);
      await tester.pumpWidget(
        opsHost(
          const DriverHome(),
          repo,
          variant: variant,
          overrides: [sharedPreferencesProvider.overrideWithValue(sp)],
        ),
      );
      await settle(tester);
      return ProviderScope.containerOf(tester.element(find.byType(DriverHome)));
    }

    Future<void> openDetails(WidgetTester tester) async {
      await tester.tap(find.text('#AB100008').first);
      await settle(tester);
    }

    testWidgets('on: the card has the alternative call button, and the window shows the number and its button', (
      tester,
    ) async {
      await pump(tester, FakeOps()..jobList = [FakeOps.job(alternate: _number)]);
      expect(find.byKey(const ValueKey('card-call-alternative')), findsOneWidget);
      await openDetails(tester);
      expect(find.byKey(const ValueKey('call-alternative')), findsOneWidget);
      expect(find.byKey(const ValueKey('alternative-number')), findsOneWidget);
      expect(find.text(_number), findsOneWidget);
      expect(find.text('Call alternative number'), findsOneWidget);
    });

    testWidgets('off: no alternative button on the card and nothing about it in the window', (tester) async {
      await pump(
        tester,
        FakeOps()
          ..jobList = [FakeOps.job(alternate: _number)]
          ..alternatePhoneEnabled = false,
        prefs: {_cacheKey: false},
      );
      expect(find.byKey(const ValueKey('card-call-alternative')), findsNothing);
      // The main Call button stays.
      expect(find.text('Call'), findsWidgets);
      await openDetails(tester);
      expect(find.byKey(const ValueKey('call-alternative')), findsNothing);
      expect(find.byKey(const ValueKey('alternative-number')), findsNothing);
      expect(find.text(_number), findsNothing);
      expect(find.text('Call alternative number'), findsNothing);
      expect(find.text('Alternative number'), findsNothing);
      // The customer's main number is untouched.
      expect(find.text('+97455123456'), findsOneWidget);
    });

    testWidgets('off in Arabic: no alternative words either', (tester) async {
      await pump(
        tester,
        FakeOps()
          ..jobList = [FakeOps.job(alternate: _number)]
          ..alternatePhoneEnabled = false,
        variant: _phoneAr,
        prefs: {_cacheKey: false},
      );
      expect(find.byKey(const ValueKey('card-call-alternative')), findsNothing);
      await openDetails(tester);
      expect(find.byKey(const ValueKey('call-alternative')), findsNothing);
      expect(find.text(_number), findsNothing);
    });

    testWidgets('a job without an alternative number shows none, on or off', (tester) async {
      await pump(tester, FakeOps());
      expect(find.byKey(const ValueKey('card-call-alternative')), findsNothing);
      await openDetails(tester);
      expect(find.byKey(const ValueKey('call-alternative')), findsNothing);
    });

    testWidgets('the dashboard switching it off while the window is open removes it at once', (tester) async {
      final repo = FakeOps()..jobList = [FakeOps.job(alternate: _number)];
      final c = await pump(tester, repo);
      await openDetails(tester);
      expect(find.byKey(const ValueKey('call-alternative')), findsOneWidget);

      repo.alternatePhoneEnabled = false;
      await c.read(opsStatusStyleProvider.notifier).refresh();
      await settle(tester);
      expect(find.byKey(const ValueKey('call-alternative')), findsNothing);
      expect(find.byKey(const ValueKey('card-call-alternative')), findsNothing);

      repo.alternatePhoneEnabled = true;
      await c.read(opsStatusStyleProvider.notifier).refresh();
      await settle(tester);
      expect(find.byKey(const ValueKey('call-alternative')), findsOneWidget);
      expect(find.byKey(const ValueKey('card-call-alternative')), findsOneWidget);
    });
  });

  group('the staff app', () {
    Future<void> pump(
      WidgetTester tester,
      FakeOps repo, {
      Variant variant = _phone,
      Map<String, Object> prefs = const {},
    }) async {
      useVariant(tester, variant);
      final sp = await _prefs(prefs);
      await tester.pumpWidget(
        opsHost(
          const StaffHome(),
          repo,
          variant: variant,
          role: OpsRole.staff,
          overrides: [sharedPreferencesProvider.overrideWithValue(sp)],
        ),
      );
      await settle(tester);
    }

    FakeOps repoWithAlternate() => FakeOps()
      ..queues = {
        'new': [FakeOps.order(id: 'f0', status: 'pending', number: 'AB100001', alternate: _number)],
        'ready': [],
        'failed': [],
      };

    Future<void> openOrder(WidgetTester tester) async {
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
    }

    testWidgets('on: the order window shows the alternative number and its call button', (tester) async {
      await pump(tester, repoWithAlternate());
      await openOrder(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.byKey(const ValueKey('call-alternative')), findsOneWidget);
      expect(find.text(_number), findsOneWidget);
    });

    testWidgets('off: the order window has no alternative number and no call button', (tester) async {
      await pump(tester, repoWithAlternate()..alternatePhoneEnabled = false, prefs: {_cacheKey: false});
      await openOrder(tester);
      expect(find.text('Order #AB100001'), findsOneWidget);
      expect(find.byKey(const ValueKey('call-alternative')), findsNothing);
      expect(find.text(_number), findsNothing);
      expect(find.text('Call alternative number'), findsNothing);
    });

    testWidgets('off in Arabic: nothing about the alternative number', (tester) async {
      await pump(
        tester,
        repoWithAlternate()..alternatePhoneEnabled = false,
        variant: _phoneAr,
        prefs: {_cacheKey: false},
      );
      await openOrder(tester);
      expect(find.byKey(const ValueKey('call-alternative')), findsNothing);
      expect(find.text(_number), findsNothing);
    });
  });
}
