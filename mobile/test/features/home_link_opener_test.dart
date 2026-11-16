// Opening what a banner points to: every link type through the app's own router, an https address in the browser, a link that cannot
// open (or fails) without a crash, and a deleted place showing the normal "not found" page.
import 'package:albahar_customer/core/providers.dart' show supportedLocales;
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/catalog/presentation/category_screen.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/home/presentation/home_slider.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/fakes.dart';
import '../helpers/home_slider_fakes.dart';

HomeLink _link(String type, String value) => HomeLink(type: type, value: value);

/// Runs [body] with a live [BuildContext] under the app's localisation.
Future<void> _withContext(WidgetTester t, Future<void> Function(BuildContext context) body) async {
  late BuildContext captured;
  await t.pumpWidget(
    MaterialApp(
      supportedLocales: const [Locale('en'), Locale('ar')],
      localizationsDelegates: const [
        AppLocalizations.delegate,
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      home: Scaffold(
        body: Builder(
          builder: (context) {
            captured = context;
            return const SizedBox.shrink();
          },
        ),
      ),
    ),
  );
  await body(captured);
  await t.pump();
}

void main() {
  group('the opener', () {
    testWidgets('each in-app type is handed to the router with the right location', (t) async {
      final links = OpenedLinks();
      await _withContext(t, (context) async {
        for (final link in [
          _link('category', 'c-1'),
          _link('page', 'terms'),
          _link('product', 'p-1'),
          _link('brand', 'b-1'),
          _link('auction', 'a-1'),
          _link('search', 'sofa bed'),
        ]) {
          await links.opener.open(context, link);
        }
      });
      expect(links.locations, [
        '/categories/c-1',
        '/pages/terms',
        '/products/p-1',
        '/products?brand_id=b-1',
        '/auctions/a-1',
        '/search?q=sofa+bed',
      ]);
      expect(links.launched, isEmpty);
    });

    testWidgets('an https address opens in the browser, and only through the browser', (t) async {
      final links = OpenedLinks();
      await _withContext(t, (context) async {
        await links.opener.open(context, _link('url', 'https://albahar.qa/eid?x=1'));
      });
      expect(links.launched, [Uri.parse('https://albahar.qa/eid?x=1')]);
      expect(links.locations, isEmpty);
      expect(find.text('We couldn’t open this link.'), findsNothing);
    });

    testWidgets(
      'an http address, a script, a phone number and a bare word are refused: nothing opens, nothing is said',
      (t) async {
        final links = OpenedLinks();
        await _withContext(t, (context) async {
          for (final bad in [
            'http://albahar.qa',
            'javascript:alert(1)',
            'tel:+97455123456',
            'albahar.qa',
            '',
            'https://',
          ]) {
            await links.opener.open(context, _link('url', bad));
          }
        });
        expect(links.launched, isEmpty);
        expect(links.locations, isEmpty);
        expect(find.byType(SnackBar), findsNothing);
      },
    );

    testWidgets('no link, an unknown type or an empty value do nothing and never throw', (t) async {
      final links = OpenedLinks();
      await _withContext(t, (context) async {
        await links.opener.open(context, null);
        await links.opener.open(context, _link('teleport', 'x'));
        await links.opener.open(context, _link('category', ''));
        await links.opener.open(context, _link('', ''));
      });
      expect(links.launched, isEmpty);
      expect(links.locations, isEmpty);
      expect(t.takeException(), isNull);
    });

    testWidgets('a browser that cannot be started says so in a small message', (t) async {
      final links = OpenedLinks()..launchResult = false;
      await _withContext(t, (context) async {
        await links.opener.open(context, _link('url', 'https://albahar.qa'));
      });
      expect(find.text('We couldn’t open this link.'), findsOneWidget);
    });

    testWidgets('a browser that throws says the same, and does not crash', (t) async {
      final links = OpenedLinks()..launchError = StateError('no browser');
      await _withContext(t, (context) async {
        await links.opener.open(context, _link('url', 'https://albahar.qa'));
      });
      expect(find.text('We couldn’t open this link.'), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('a destination that fails to open does not crash the home', (t) async {
      final links = OpenedLinks()..locationError = StateError('route exploded');
      await _withContext(t, (context) async {
        await links.opener.open(context, _link('category', 'c-1'));
      });
      expect(t.takeException(), isNull);
    });

    testWidgets('the message is in Arabic for an Arabic customer', (t) async {
      final links = OpenedLinks()..launchResult = false;
      late BuildContext captured;
      await t.pumpWidget(
        MaterialApp(
          locale: const Locale('ar'),
          supportedLocales: const [Locale('en'), Locale('ar')],
          localizationsDelegates: const [
            AppLocalizations.delegate,
            GlobalMaterialLocalizations.delegate,
            GlobalWidgetsLocalizations.delegate,
            GlobalCupertinoLocalizations.delegate,
          ],
          home: Scaffold(
            body: Builder(
              builder: (context) {
                captured = context;
                return const SizedBox.shrink();
              },
            ),
          ),
        ),
      );
      await links.opener.open(captured, _link('url', 'https://albahar.qa'));
      await t.pump();
      expect(find.text('تعذّر فتح هذا الرابط.'), findsOneWidget);
    });
  });

  group('tapping slides in the home, through the real router', () {
    final types = <(String, String, String, String)>[
      ('category', 'c-1', '/categories/c-1', 'category c-1'),
      ('product', 'p-1', '/products/p-1', 'product p-1'),
      ('auction', 'a-1', '/auctions/a-1', 'auction a-1'),
      ('brand', 'b-1', '/products?brand_id=b-1', 'products {brand_id: b-1}'),
      ('search', 'sofa bed', '/search?q=sofa+bed', 'search sofa bed'),
    ];

    Future<GoRouter> pumpRouter(WidgetTester t, TestHarness h, Widget home) async {
      t.view.physicalSize = const Size(390, 2400);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final pictures = await fakePictures(t);
      final router = GoRouter(
        routes: [
          GoRoute(
            path: '/',
            builder: (_, _) => Scaffold(body: SingleChildScrollView(child: home)),
          ),
          GoRoute(
            path: '/categories/:id',
            builder: (_, s) => Scaffold(body: Text('category ${s.pathParameters['id']}')),
          ),
          GoRoute(
            path: '/products/:id',
            builder: (_, s) => Scaffold(body: Text('product ${s.pathParameters['id']}')),
          ),
          GoRoute(
            path: '/products',
            builder: (_, s) => Scaffold(body: Text('products ${s.uri.queryParameters}')),
          ),
          GoRoute(
            path: '/auctions/:id',
            builder: (_, s) => Scaffold(body: Text('auction ${s.pathParameters['id']}')),
          ),
          GoRoute(
            path: '/search',
            builder: (_, s) => Scaffold(body: Text('search ${s.uri.queryParameters['q']}')),
          ),
        ],
      );
      addTearDown(router.dispose);
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: ProviderScope(
            overrides: [homeSliderImageProvider.overrideWithValue(pictures)],
            child: MaterialApp.router(
              supportedLocales: supportedLocales,
              localizationsDelegates: const [
                AppLocalizations.delegate,
                GlobalMaterialLocalizations.delegate,
                GlobalWidgetsLocalizations.delegate,
                GlobalCupertinoLocalizations.delegate,
              ],
              theme: AppTheme.light(const Locale('en')),
              routerConfig: router,
            ),
          ),
        ),
      );
      await t.pump();
      return router;
    }

    for (final (type, value, location, shown) in types) {
      testWidgets('a $type slide opens $location', (t) async {
        final h = await TestHarness.create();
        final router = await pumpRouter(
          t,
          h,
          HomeSlider(
            section: sliderSection([
              slideJson('s', title: 'Slide $type', link: {'type': type, 'value': value}),
            ]),
          ),
        );
        // The slide's ripple lies over its words, so the tap lands on the slide itself.
        await t.tap(find.text('Slide $type'), warnIfMissed: false);
        await t.pump();
        await t.pump(const Duration(milliseconds: 500));
        expect(router.canPop(), isTrue, reason: 'the page was pushed over the home');
        expect(find.text(shown), findsOneWidget);
      });
    }

    testWidgets('a page slide opens the page in the app\'s side window, read from the server', (t) async {
      final h = await TestHarness.create(
        handler: routes({
          'GET /pages/terms': (_) => ok({
            'slug': 'terms',
            'title': 'Terms of use',
            'body': '<p>Be kind.</p>',
            'updated_at': '2026-11-01T00:00:00Z',
          }),
        }),
      );
      await pumpRouter(
        t,
        h,
        HomeSlider(
          section: sliderSection([
            slideJson('s', title: 'Slide page', link: {'type': 'page', 'value': 'terms'}),
          ]),
        ),
      );
      await t.tap(find.text('Slide page'), warnIfMissed: false);
      await t.pump();
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(milliseconds: 600));
      expect(h.adapter.where('GET', '/pages/terms'), hasLength(1));
      expect(find.text('Terms of use'), findsWidgets);
    });

    testWidgets('a page with an Arabic slug is asked for by its real name, not the encoded one', (t) async {
      final h = await TestHarness.create(
        handler: routes({
          'GET /pages/عيد': (_) => ok({'slug': 'عيد', 'title': 'العيد', 'body': '<p>x</p>'}),
        }),
      );
      await pumpRouter(
        t,
        h,
        HomeSlider(
          section: sliderSection([
            slideJson('s', title: 'Slide ar', link: {'type': 'page', 'value': 'عيد'}),
          ]),
        ),
      );
      await t.tap(find.text('Slide ar'), warnIfMissed: false);
      await t.pump();
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(milliseconds: 600));
      expect(
        h.adapter.requests
            .where((r) => r.method == 'GET' && r.path.startsWith('/pages'))
            .map((r) => Uri.decodeFull(r.path)),
        ['/pages/عيد'],
      );
    });

    testWidgets('a slide without a link, and one with an http address, open nothing', (t) async {
      final h = await TestHarness.create();
      final router = await pumpRouter(
        t,
        h,
        Column(
          children: [
            HomeSlider(
              section: sliderSection([slideJson('a', title: 'Slide none')], id: 'one'),
            ),
            HomeSlider(
              section: sliderSection([
                slideJson('b', title: 'Slide http', link: {'type': 'url', 'value': 'http://albahar.qa'}),
              ], id: 'two'),
            ),
          ],
        ),
      );
      await t.tap(find.text('Slide none'), warnIfMissed: false);
      await t.tap(find.text('Slide http'), warnIfMissed: false);
      await t.pump(const Duration(milliseconds: 500));
      expect(router.canPop(), isFalse, reason: 'nothing was pushed over the home');
    });
  });

  group('a link to something that was deleted', () {
    testWidgets('a deleted category shows the normal "not found" page, not an empty list', (t) async {
      final h = await TestHarness.create(
        handler: routes({'GET /categories/gone': (_) => error(404, 'not_found'), 'GET /categories': (_) => ok([])}),
      );
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: MaterialApp(
            supportedLocales: supportedLocales,
            localizationsDelegates: const [
              AppLocalizations.delegate,
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            theme: AppTheme.light(const Locale('en')),
            home: const CategoryScreen(categoryId: 'gone'),
          ),
        ),
      );
      for (var i = 0; i < 8; i++) {
        await t.pump(const Duration(milliseconds: 100));
      }
      expect(find.text('Not found'), findsOneWidget);
      expect(find.text('Try again'), findsOneWidget);
    });
  });
}
