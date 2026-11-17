// The Deals module inside the real app: the footer's middle button and its live dot, the Deals list, a deal's page and the
// home strip, against a fake server.
import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/theme/page_backdrop.dart';
import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:albahar_customer/features/deals/presentation/state/deals_providers.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_widget.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import 'deals_fixtures.dart';

Map<String, Object?> _footer({String center = 'deals', List<String>? keys}) => {
  'design': 'classic',
  'show_labels': true,
  'cart_badge': true,
  'accent': null,
  'center': center,
  'items': [
    for (final k in keys ?? ['home', 'categories', 'deals', 'cart', 'account'])
      {'key': k, 'enabled': true, 'icon': null, 'label_en': null, 'label_ar': null},
  ],
};

Map<String, Object?> _theme(Object? footer, {Object? backdrop}) => {
  'key': 'default',
  'kind': 'base',
  'footer': footer,
  'backdrop': backdrop,
  'colors': {
    'primary': '#8A1538',
    'primary_soft': '#A3294E',
    'accent': '#B3254F',
    'background': '#FCF9FA',
    'surface': '#FFFFFF',
    'ink': '#2A1A20',
  },
};

class _Phone {
  DateTime now = dealsNow;
}

/// The fake server: the deals answers can be swapped between requests.
class _Server {
  _Server({this.footer, this.backdrop, Object? full, Object? home, this.homeSections = const []})
    : full = full ?? feedJson(live: [dealJson()]),
      home = home ?? feedJson(live: [dealJson()]);

  final Object? footer;
  final Object? backdrop;
  Object? full;
  Object? home;
  final List<Object?> homeSections;
  Object? detail;
  FakeReply? detailError;
  FakeReply? fullError;
  FakeReply? homeError;
  final requests = <RecordedRequest>[];

  int get fullRequests => requests.where((r) => r.path == '/deals' && r.query['home'] != '1').length;
  int get homeRequests => requests.where((r) => r.path == '/deals' && r.query['home'] == '1').length;

  FakeHandler get handler => routes({
    'GET /theme': (_) => ok(_theme(footer, backdrop: backdrop)),
    'GET /home': (_) => ok({'sections': homeSections}),
    'GET /categories': (_) => ok([]),
    'GET /deals': (r) {
      requests.add(r);
      if (r.query['home'] == '1') return homeError ?? ok(home);
      return fullError ?? ok(full);
    },
    'GET /deals/d1': (r) {
      requests.add(r);
      return detailError ??
          ok(
            detail ??
                {
                  ...dealJson(products: <Object?>[]),
                  'server_time': iso(dealsNow),
                  'products': {
                    'data': [dealProductJson('p1'), dealProductJson('p2')],
                    'meta': {'current_page': 1, 'last_page': 1, 'total': 2},
                  },
                },
          );
    },
  });
}

Future<ProviderContainer> _boot(
  WidgetTester t,
  _Server server,
  _Phone phone, {
  String locale = 'en',
  double width = 390,
}) async {
  await t.binding.setSurfaceSize(Size(width, 844));
  addTearDown(() => t.binding.setSurfaceSize(null));
  final h = await TestHarness.create(
    prefs: {'locale': locale, 'onboarding_done': true},
    handler: server.handler,
    overrides: [dealDeviceNowProvider.overrideWithValue(() => phone.now)],
  );
  await t.pumpWidget(
    UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
  );
  await t.pump(const Duration(milliseconds: 1600));
  await t.pump(const Duration(milliseconds: 500));
  await t.pump(const Duration(milliseconds: 100));
  return h.container;
}

Future<void> _goto(WidgetTester t, ProviderContainer c, String path) async {
  c.read(routerProvider).go(path);
  await t.pump(const Duration(milliseconds: 600));
  await t.pump(const Duration(milliseconds: 600));
}

/// Moves the fake phone clock and the test clock together, one second at a time like the real one.
Future<void> _tick(WidgetTester t, _Phone phone, Duration d) async {
  for (var left = d; left > Duration.zero; left -= const Duration(seconds: 1)) {
    final step = left < const Duration(seconds: 1) ? left : const Duration(seconds: 1);
    phone.now = phone.now.add(step);
    await t.pump(step);
  }
}

/// Removes the app and lets the container go, so the footer's once-a-minute timer does not outlive the test.
Future<void> _teardown(WidgetTester t, ProviderContainer c) async {
  await t.pumpWidget(const SizedBox.shrink());
  await t.pump(const Duration(seconds: 10));
  c.dispose();
}

Finder _button(String word) => find.bySemanticsLabel(RegExp('^$word'));

void main() {
  group('the footer middle button', () {
    testWidgets('Deals as the raised middle button: the dot shows while a deal is live, and a tap opens the list', (
      t,
    ) async {
      final server = _Server(footer: _footer());
      final phone = _Phone();
      final c = await _boot(t, server, phone);
      expect(server.homeRequests, greaterThanOrEqualTo(1), reason: 'the footer asked once for live deals');
      expect(c.read(liveDealsProvider), isTrue);
      expect(_button('Deals, On sale now'), findsOneWidget);
      expect(t.getCenter(_button('Deals')).dx, closeTo(195, 0.6));

      await t.tap(_button('Deals'));
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(milliseconds: 600));
      expect(find.byType(DealWidget), findsOneWidget);
      expect(find.text('Weekend Furniture Drop'), findsOneWidget);
      expect(t.takeException(), isNull);
      await _teardown(t, c);
    });

    testWidgets('no live deal: no dot', (t) async {
      final server = _Server(
        footer: _footer(),
        home: feedJson(live: [dealJson(phase: 'sold_out')]),
      );
      final c = await _boot(t, server, _Phone());
      expect(c.read(liveDealsProvider), isFalse, reason: 'sold out is not on sale');
      expect(_button('Deals, On sale now'), findsNothing);
      expect(_button('Deals'), findsOneWidget);
      await _teardown(t, c);
    });

    testWidgets('it asks again once a minute while the app is open, and not at all in the background', (t) async {
      final server = _Server(footer: _footer(), home: feedJson());
      final c = await _boot(t, server, _Phone());
      expect(c.read(liveDealsProvider), isFalse);
      final first = server.homeRequests;
      server.home = feedJson(live: [dealJson()]);
      await t.pump(const Duration(seconds: 61));
      expect(server.homeRequests, first + 1);
      expect(c.read(liveDealsProvider), isTrue, reason: 'a deal went live since the last look');

      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      server.home = feedJson();
      await t.pump(const Duration(minutes: 5));
      expect(server.homeRequests, first + 1, reason: 'nothing is asked while the app is in the background');
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pump(const Duration(milliseconds: 100));
      expect(server.homeRequests, first + 2, reason: 'it looks again the moment the app is back');
      expect(c.read(liveDealsProvider), isFalse);
      await _teardown(t, c);
    });

    testWidgets('a failed look keeps the dot as it was', (t) async {
      final server = _Server(footer: _footer());
      final c = await _boot(t, server, _Phone());
      expect(c.read(liveDealsProvider), isTrue);
      server.homeError = error(500, 'server_error');
      await t.pump(const Duration(seconds: 61));
      expect(c.read(liveDealsProvider), isTrue, reason: 'the look failed: the dot stays as it was');
      await _teardown(t, c);
    });

    testWidgets('no Deals button in the footer: no request and no dot', (t) async {
      final server = _Server(
        footer: _footer(center: 'none', keys: ['home', 'categories', 'cart', 'account']),
      );
      final c = await _boot(t, server, _Phone());
      await t.pump(const Duration(minutes: 3));
      expect(server.homeRequests, 0);
      expect(server.fullRequests, 0);
      expect(c.read(liveDealsProvider), isFalse);
      await _teardown(t, c);
    });

    testWidgets('an older server (no centre, no Deals item) leaves the footer as it was', (t) async {
      final footer = _footer(center: 'none', keys: ['home', 'categories', 'auctions', 'cart', 'account'])
        ..remove('center');
      final server = _Server(footer: footer);
      final c = await _boot(t, server, _Phone());
      expect(find.byType(NavigationBar), findsOneWidget);
      expect(server.homeRequests, 0);
      await _teardown(t, c);
    });
  });

  group('the Deals screen', () {
    testWidgets('shows the live deals with their products, and the upcoming ones with a "starts in" countdown', (
      t,
    ) async {
      final server = _Server(
        footer: _footer(),
        full: feedJson(
          live: [dealJson(productsTotal: 18)],
          upcoming: [
            dealJson(
              id: 'u1',
              name: 'Friday Flash',
              phase: 'scheduled',
              startsAt: dealsNow.add(const Duration(hours: 3)),
              endsAt: dealsNow.add(const Duration(hours: 9)),
              products: <Object?>[],
            ),
          ],
        ),
      );
      final c = await _boot(t, server, _Phone());
      await _goto(t, c, '/deals');
      expect(find.byType(DealWidget), findsNWidgets(2));
      expect(find.text('On sale now'), findsOneWidget, reason: 'the headers show when there are two groups');
      expect(find.text('Upcoming'), findsOneWidget);
      expect(find.text('Weekend Furniture Drop'), findsOneWidget);
      expect(find.text('Friday Flash'), findsOneWidget);
      expect(find.text('Starts in'), findsOneWidget);
      expect(find.text('Product p1'), findsOneWidget);
      expect(find.text('QAR 70.00'), findsWidgets, reason: 'the products show their deal price');
      expect(find.text('-30%'), findsWidgets);
      expect(find.text('See all 18 products'), findsOneWidget);
      expect(find.byType(DealClockHost), findsOneWidget, reason: 'one clock for the whole screen');
      expect(t.takeException(), isNull);
      await _teardown(t, c);
    });

    testWidgets('Arabic and 320 dp: right to left, no overflow', (t) async {
      final server = _Server(
        footer: _footer(),
        full: feedJson(live: [dealJson(name: 'عرض نهاية الأسبوع')]),
      );
      final c = await _boot(t, server, _Phone(), locale: 'ar', width: 320);
      await _goto(t, c, '/deals');
      expect(find.text('عرض نهاية الأسبوع'), findsOneWidget);
      expect(find.text('تسوق العرض'), findsOneWidget);
      expect(t.takeException(), isNull);
      await _teardown(t, c);
    });

    testWidgets('the dashboard\'s page background stays behind the Deals screens (list and a deal\'s page)', (t) async {
      final server = _Server(
        footer: _footer(),
        backdrop: {'style': 'skyline', 'scene': 'doha', 'fit': 'bottom', 'opacity': 0.2},
      );
      final c = await _boot(t, server, _Phone());
      expect(find.byType(PageBackdrop), findsWidgets, reason: 'the picture is on');
      await _goto(t, c, '/deals');
      expect(find.byType(DealWidget), findsOneWidget);
      expect(find.byType(PageBackdrop), findsWidgets);
      expect(
        t.widget<Scaffold>(find.byType(Scaffold).last).backgroundColor,
        isNull,
        reason: 'the scaffold does not paint over the picture',
      );
      await _goto(t, c, '/deals/d1');
      expect(find.byType(PageBackdrop), findsWidgets);
      expect(t.takeException(), isNull);
      await _teardown(t, c);
    });

    testWidgets('empty: a calm message and a way to the shop', (t) async {
      final server = _Server(footer: _footer(), full: feedJson());
      final c = await _boot(t, server, _Phone());
      await _goto(t, c, '/deals');
      expect(find.text('No deals right now'), findsOneWidget);
      await t.tap(find.text('Browse the shop'));
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(milliseconds: 600));
      expect(find.text('No deals right now'), findsNothing);
      await _teardown(t, c);
    });

    testWidgets('an error offers a retry that works', (t) async {
      final server = _Server(footer: _footer());
      server.fullError = error(500, 'server_error');
      final c = await _boot(t, server, _Phone());
      await _goto(t, c, '/deals');
      expect(find.byType(DealWidget), findsNothing);
      expect(find.text('Try again'), findsOneWidget);
      server.fullError = null;
      await t.tap(find.text('Try again'));
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(milliseconds: 600));
      expect(find.byType(DealWidget), findsOneWidget);
      await _teardown(t, c);
    });

    testWidgets('when a deal runs out the list moves it out and asks the server again', (t) async {
      final phone = _Phone();
      final server = _Server(
        footer: _footer(),
        full: feedJson(
          live: [
            dealJson(id: 'a', name: 'Ending soon', endsAt: dealsNow.add(const Duration(seconds: 4))),
            dealJson(id: 'b', name: 'Later'),
          ],
        ),
      );
      final c = await _boot(t, server, phone);
      await _goto(t, c, '/deals');
      expect(find.text('Ending soon'), findsOneWidget);
      final before = server.fullRequests;
      server.full = feedJson(
        live: [dealJson(id: 'b', name: 'Later')],
      );
      await _tick(t, phone, const Duration(seconds: 3));
      expect(find.text('Ending soon'), findsOneWidget, reason: 'not yet');
      await _tick(t, phone, const Duration(seconds: 2));
      await t.pump(const Duration(milliseconds: 100));
      expect(find.text('Ending soon'), findsNothing, reason: 'gone at the moment it ends');
      await t.pump(const Duration(milliseconds: 300));
      expect(server.fullRequests, greaterThan(before), reason: 'refetched');
      expect(find.text('Later'), findsOneWidget);
      expect(t.takeException(), isNull);
      await _teardown(t, c);
    });

    testWidgets('an upcoming deal moves to "on sale" when it starts', (t) async {
      final phone = _Phone();
      final soon = dealJson(
        id: 'u1',
        name: 'Starting',
        phase: 'scheduled',
        startsAt: dealsNow.add(const Duration(seconds: 3)),
        endsAt: dealsNow.add(const Duration(hours: 2)),
        products: <Object?>[],
      );
      final server = _Server(
        footer: _footer(),
        full: feedJson(upcoming: [soon]),
      );
      final c = await _boot(t, server, phone);
      await _goto(t, c, '/deals');
      expect(find.text('Starts in'), findsOneWidget);
      server.full = feedJson(
        live: [dealJson(id: 'u1', name: 'Starting', products: <Object?>[])],
      );
      await _tick(t, phone, const Duration(seconds: 4));
      await t.pump(const Duration(milliseconds: 400));
      expect(find.text('Starts in'), findsNothing);
      expect(find.text('Ends in'), findsOneWidget);
      await _teardown(t, c);
    });

    testWidgets('pull to refresh asks again', (t) async {
      final server = _Server(footer: _footer());
      final c = await _boot(t, server, _Phone());
      await _goto(t, c, '/deals');
      final before = server.fullRequests;
      expect(find.byType(RefreshIndicator), findsOneWidget);
      await t.drag(find.byType(DealWidget).first, const Offset(0, 300));
      await t.pump(const Duration(milliseconds: 200));
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(seconds: 1));
      expect(server.fullRequests, before + 1);
      await _teardown(t, c);
    });
  });

  group('a deal\'s page', () {
    testWidgets('the deal widget, its limits and every product with the deal price', (t) async {
      final server = _Server(footer: _footer());
      final c = await _boot(t, server, _Phone());
      await _goto(t, c, '/deals/d1');
      expect(find.byType(DealWidget), findsOneWidget);
      expect(find.text('Limit 2 per order · Limit 3 per customer'), findsOneWidget);
      expect(
        find.text('Shop the deal'),
        findsNothing,
        reason: 'a button that does nothing is not drawn on the deal\'s own page',
      );
      expect(find.text('Product p1'), findsOneWidget);
      expect(find.text('Product p2'), findsOneWidget);
      expect(find.text('QAR 70.00'), findsWidgets);
      expect(t.takeException(), isNull);
      await _teardown(t, c);
    });

    testWidgets('an ended deal says so and leads back to the current ones', (t) async {
      final server = _Server(footer: _footer());
      server.detailError = error(404, 'deal_not_found');
      final c = await _boot(t, server, _Phone());
      await _goto(t, c, '/deals/d1');
      expect(find.text('This deal has ended'), findsOneWidget);
      await t.tap(find.text('See current deals'));
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(milliseconds: 600));
      expect(find.text('This deal has ended'), findsNothing);
      expect(find.byType(DealWidget), findsOneWidget);
      await _teardown(t, c);
    });

    testWidgets('a deal with no products yet says so', (t) async {
      final server = _Server(footer: _footer());
      server.detail = {
        ...dealJson(products: <Object?>[]),
        'products': {
          'data': <Object?>[],
          'meta': {'current_page': 1, 'last_page': 1},
        },
      };
      final c = await _boot(t, server, _Phone());
      await _goto(t, c, '/deals/d1');
      expect(find.text('The products of this deal will appear here.'), findsOneWidget);
      await _teardown(t, c);
    });

    testWidgets('a deal that ends while it is open asks the server and then says it ended', (t) async {
      final phone = _Phone();
      final server = _Server(footer: _footer());
      server.detail = {
        ...dealJson(endsAt: dealsNow.add(const Duration(seconds: 3)), products: <Object?>[]),
        'products': {
          'data': [dealProductJson('p1')],
          'meta': {'current_page': 1, 'last_page': 1},
        },
      };
      final c = await _boot(t, server, phone);
      await _goto(t, c, '/deals/d1');
      expect(find.byType(DealWidget), findsOneWidget);
      server.detailError = error(404, 'deal_not_found');
      await _tick(t, phone, const Duration(seconds: 4));
      await t.pump(const Duration(milliseconds: 500));
      await t.pump(const Duration(milliseconds: 500));
      expect(find.text('This deal has ended'), findsOneWidget);
      await _teardown(t, c);
    });
  });

  group('the home strip', () {
    final section = {
      'id': 's3',
      'type': 'best_sellers',
      'title': 'Top picks',
      'items': [productCard('1')],
    };

    testWidgets('a deal on the home page shows with its countdown and opens its page', (t) async {
      final server = _Server(footer: null, homeSections: [section]);
      final c = await _boot(t, server, _Phone());
      expect(find.byType(DealWidget), findsOneWidget);
      expect(find.text('Deals'), findsWidgets);
      await t.tap(find.byType(DealWidget));
      await t.pump(const Duration(milliseconds: 600));
      await t.pump(const Duration(milliseconds: 600));
      expect(find.text('Limit 2 per order · Limit 3 per customer'), findsOneWidget, reason: 'the deal page opened');
      await _teardown(t, c);
    });

    testWidgets('several deals scroll sideways; none shows nothing at all', (t) async {
      final many = _Server(
        footer: null,
        homeSections: [section],
        home: feedJson(
          live: [
            dealJson(id: 'a', name: 'One'),
            dealJson(id: 'b', name: 'Two'),
          ],
        ),
      );
      final c = await _boot(t, many, _Phone());
      expect(find.byType(DealWidget), findsNWidgets(2));
      expect(t.takeException(), isNull);
      await _teardown(t, c);

      final none = _Server(footer: null, homeSections: [section], home: feedJson());
      final c2 = await _boot(t, none, _Phone());
      expect(find.byType(DealWidget), findsNothing);
      expect(find.text('Top picks'), findsOneWidget);
      await _teardown(t, c2);
    });

    testWidgets('a failing deals request leaves Home untouched', (t) async {
      final server = _Server(footer: null, homeSections: [section]);
      server.homeError = error(500, 'server_error');
      final c = await _boot(t, server, _Phone());
      expect(find.byType(DealWidget), findsNothing);
      expect(find.text('Top picks'), findsOneWidget);
      expect(t.takeException(), isNull);
      await _teardown(t, c);
    });
  });
}
