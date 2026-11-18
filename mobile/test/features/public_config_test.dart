import 'dart:async';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/features/onboarding/presentation/designed_by_line.dart';
import 'package:albahar_customer/features/public_config/data/public_config_repository.dart';
import 'package:albahar_customer/features/public_config/domain/public_config.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

Map<String, dynamic> _config({String en = 'Designed by Athar Qatar', String ar = 'تصميم أثر قطر'}) => {
  'designed_by': {'en': en, 'ar': ar},
  'returns_enabled': true,
};

void main() {
  group('PublicConfig', () {
    test('the line is in the customer language', () {
      final c = PublicConfig.fromJson(_config());
      expect(c.designedBy('en'), 'Designed by Athar Qatar');
      expect(c.designedBy('ar'), 'تصميم أثر قطر');
    });

    test('an empty line falls back to the other language, and both empty hide it', () {
      expect(PublicConfig.fromJson(_config(ar: '')).designedBy('ar'), 'Designed by Athar Qatar');
      expect(PublicConfig.fromJson(_config(en: '  ')).designedBy('en'), 'تصميم أثر قطر');
      expect(PublicConfig.fromJson(_config(en: '', ar: '')).designedBy('en'), isNull);
      expect(PublicConfig.empty.designedBy('ar'), isNull);
    });

    test('a missing, null or wrongly typed payload means "show nothing", never an error', () {
      expect(PublicConfig.fromJson(const {}).designedBy('en'), isNull);
      expect(PublicConfig.fromJson({'designed_by': null}).designedBy('en'), isNull);
      expect(PublicConfig.fromJson({'designed_by': 'oops'}).designedBy('en'), isNull);
      expect(
        PublicConfig.fromJson({
          'designed_by': {'en': null, 'ar': 7},
        }).designedBy('ar'),
        '7',
      );
    });

    test('words are trimmed, and the cached copy reads back the same', () {
      final c = PublicConfig.fromJson(_config(en: '  Designed by X  '));
      expect(c.designedByEn, 'Designed by X');
      expect(PublicConfig.fromJson(c.toJson()), c);
    });
  });

  group('PublicConfigController', () {
    test('asks for GET /app-config without signing in, and keeps the answer for the next launch', () async {
      final h = await TestHarness.create(handler: routes({'GET /app-config': (_) => ok(_config())}));
      final sub = h.container.listen(publicConfigProvider, (_, _) {});
      addTearDown(sub.close);
      expect(h.read(publicConfigProvider), PublicConfig.empty, reason: 'nothing known yet, nothing shown');

      await pumpEventQueue();

      expect(h.read(publicConfigProvider).designedBy('en'), 'Designed by Athar Qatar');
      final request = h.adapter.where('GET', '/app-config').single;
      expect(request.header('Authorization'), isNull, reason: 'a public endpoint: no token is sent');
      expect(
        h.read(appPreferencesProvider).publicConfig,
        _config()
          ..remove('returns_enabled')
          ..['alternate_phone_enabled'] = true
          ..['intro'] = {'slides': <Object?>[]},
      );
    });

    test('the last good answer shows at once on the next launch, and a newer one replaces it', () async {
      final h = await TestHarness.create(
        prefs: {'public_config': '{"designed_by":{"en":"Designed by Old","ar":""}}'},
        handler: routes({'GET /app-config': (_) => ok(_config(en: 'Designed by New'))}),
      );
      final sub = h.container.listen(publicConfigProvider, (_, _) {});
      addTearDown(sub.close);
      expect(h.read(publicConfigProvider).designedBy('en'), 'Designed by Old', reason: 'instant, before any network');

      await pumpEventQueue();

      expect(h.read(publicConfigProvider).designedBy('en'), 'Designed by New');
      expect(h.read(appPreferencesProvider).publicConfig?['designed_by'], containsPair('en', 'Designed by New'));
    });

    for (final (name, failure) in <(String, FakeHandler)>[
      ('a server error', (_) => error(500, 'server_error')),
      ('an old server without the endpoint', (_) => error(404, 'not_found')),
      ('a payload that is not an object', (_) => const FakeReply(200, 'nope')),
    ]) {
      test('$name keeps the cached line and shows no error', () async {
        final h = await TestHarness.create(
          prefs: {'public_config': '{"designed_by":{"en":"Designed by Cached","ar":""}}'},
          handler: failure,
        );
        final sub = h.container.listen(publicConfigProvider, (_, _) {});
        addTearDown(sub.close);
        await pumpEventQueue();
        expect(h.read(publicConfigProvider).designedBy('en'), 'Designed by Cached');
        expect(h.read(publicConfigProvider), isA<PublicConfig>());
      });
    }

    test('offline keeps the cached line', () async {
      final h = await TestHarness.create(
        prefs: {'public_config': '{"designed_by":{"en":"Designed by Cached","ar":""}}'},
      );
      h.adapter.failConnection = (_) => true;
      final sub = h.container.listen(publicConfigProvider, (_, _) {});
      addTearDown(sub.close);
      await pumpEventQueue();
      expect(h.read(publicConfigProvider).designedBy('en'), 'Designed by Cached');
    });

    test('a slow server is given up on after the timeout, so the first screen never waits', () async {
      final h = await TestHarness.create(handler: (_) => Completer<FakeReply>().future);
      final repository = PublicConfigRepository(h.read(apiClientProvider), timeout: const Duration(milliseconds: 40));
      await expectLater(repository.load(), throwsA(isA<TimeoutException>()));
    });
  });

  group('DesignedByLine', () {
    Future<TestHarness> pump(
      WidgetTester t, {
      Map<String, Object> prefs = const {},
      FakeHandler? handler,
      Locale locale = const Locale('en'),
    }) async {
      final h = await TestHarness.create(prefs: {'locale': locale.languageCode, ...prefs}, handler: handler);
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: Consumer(builder: (context, ref, _) => wrapApp(const DesignedByLine(), locale: locale)),
        ),
      );
      await t.pump();
      await t.pump(const Duration(milliseconds: 600));
      return h;
    }

    testWidgets('shows the dashboard line in the customer language', (t) async {
      await pump(t, handler: routes({'GET /app-config': (_) => ok(_config())}));
      expect(find.text('Designed by Athar Qatar'), findsOneWidget);
    });

    testWidgets('shows the Arabic line for an Arabic customer', (t) async {
      await pump(t, locale: const Locale('ar'), handler: routes({'GET /app-config': (_) => ok(_config())}));
      expect(find.text('تصميم أثر قطر'), findsOneWidget);
    });

    testWidgets('falls back to the other language when its own line is empty', (t) async {
      await pump(
        t,
        locale: const Locale('ar'),
        handler: routes({'GET /app-config': (_) => ok(_config(ar: ''))}),
      );
      expect(find.text('Designed by Athar Qatar'), findsOneWidget);
    });

    testWidgets('shows nothing at all when both lines are empty, and never an error', (t) async {
      await pump(
        t,
        handler: routes({'GET /app-config': (_) => ok(_config(en: '', ar: ''))}),
      );
      expect(find.byType(Text), findsNothing);
      expect(t.takeException(), isNull);
    });

    testWidgets('shows nothing when the server cannot be reached and nothing was ever cached', (t) async {
      await pump(t, handler: (_) => error(503, 'unavailable'));
      expect(find.byType(Text), findsNothing);
    });

    testWidgets('shows the cached line straight away, before the server answers', (t) async {
      final answer = Completer<FakeReply>();
      final h = await TestHarness.create(
        prefs: {'public_config': '{"designed_by":{"en":"Designed by Cached","ar":""}}'},
        handler: (_) => answer.future,
      );
      await t.pumpWidget(UncontrolledProviderScope(container: h.container, child: wrapApp(const DesignedByLine())));
      await t.pump(const Duration(milliseconds: 600));
      expect(find.text('Designed by Cached'), findsOneWidget);
      answer.complete(ok(_config()));
      await t.pump();
      await t.pump(const Duration(milliseconds: 600));
      expect(find.text('Designed by Athar Qatar'), findsOneWidget);
    });
  });
}
