import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:albahar_customer/features/deals/presentation/state/deals_providers.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import 'deals_fixtures.dart';

void main() {
  // The footer's dot watches the app's lifecycle, so it needs the widgets binding even in a provider-only test.
  TestWidgetsFlutterBinding.ensureInitialized();
  late DateTime phone;

  Future<TestHarness> start(FakeHandler handler, {List<Override> extra = const []}) =>
      TestHarness.create(handler: handler, overrides: [dealDeviceNowProvider.overrideWithValue(() => phone), ...extra]);

  setUp(() => phone = dealsNow);

  group('GET /deals', () {
    test('asks for the products of each deal, and only the home deals when told so', () async {
      final h = await start(
        routes({
          'GET /deals': (_) => ok(feedJson(live: [dealJson()])),
        }),
      );
      final repo = h.read(dealsRepositoryProvider);
      final all = await repo.feed();
      expect(all.live, hasLength(1));
      final home = await repo.feed(home: true, limitProducts: 1);
      expect(home.live, hasLength(1));
      final calls = h.adapter.where('GET', '/deals');
      expect(calls[0].query, {'limit_products': '8'}, reason: 'no home flag unless asked');
      expect(calls[1].query, {'home': '1', 'limit_products': '1'});
      expect((await repo.feed(limitProducts: 99)).live, hasLength(1));
      expect(
        h.adapter.where('GET', '/deals').last.query['limit_products'],
        '12',
        reason: 'the contract allows 1 to 12',
      );
    });

    test('passes the branch the customer shops at, like the other catalogue calls', () async {
      final h = await start(routes({'GET /deals': (_) => ok(feedJson())}));
      final repo = h.read(dealsRepositoryProvider);
      await repo.feed(home: true, limitProducts: 1, branchId: 'br2');
      await repo.feed(branchId: 'br2');
      final calls = h.adapter.where('GET', '/deals');
      expect(calls[0].query, {'home': '1', 'limit_products': '1', 'branch_id': 'br2'});
      expect(calls[1].query, {'limit_products': '8', 'branch_id': 'br2'});
    });

    test('learns how far the phone clock is from the server clock', () async {
      phone = dealsNow.subtract(const Duration(minutes: 7));
      final h = await start(routes({'GET /deals': (_) => ok(feedJson())}));
      expect(h.read(dealSkewProvider), isNull, reason: 'nothing is known before the server speaks');
      final feed = await h.read(dealsRepositoryProvider).feed();
      expect(feed.skew, const Duration(minutes: 7));
      expect(h.read(dealSkewProvider), const Duration(minutes: 7));
    });

    test('a server time at the top of the answer is read too; none leaves the skew alone', () async {
      phone = dealsNow.subtract(const Duration(minutes: 2));
      final h = await start(
        routes({
          'GET /deals': (_) => FakeReply(200, {
            'data': {'live': <Object?>[], 'upcoming': <Object?>[]},
            'server_time': iso(dealsNow),
          }),
        }),
      );
      await h.read(dealsRepositoryProvider).feed();
      expect(h.read(dealSkewProvider), const Duration(minutes: 2));

      final h2 = await start(
        routes({
          'GET /deals': (_) => ok({'live': <Object?>[], 'upcoming': <Object?>[]}),
        }),
      );
      await h2.read(dealsRepositoryProvider).feed();
      expect(h2.read(dealSkewProvider), isNull);
    });

    test('a failure is a plain ApiException, and a garbage answer is an empty feed', () async {
      final h = await start(routes({'GET /deals': (_) => error(500, 'server_error')}));
      await expectLater(h.read(dealsRepositoryProvider).feed(), throwsA(isA<ApiException>()));
      final h2 = await start(routes({'GET /deals': (_) => ok('nonsense')}));
      expect((await h2.read(dealsRepositoryProvider).feed()).isEmpty, isTrue);
    });
  });

  group('GET /deals/{id}', () {
    test('reads the deal and a page of products; page 2 is asked for with ?page=2', () async {
      final h = await start(
        routes({
          'GET /deals/d1': (r) => ok({
            ...dealJson(products: <Object?>[]),
            'server_time': iso(dealsNow),
            'products': {
              'data': [dealProductJson('p${r.query['page'] ?? 1}')],
              'meta': {'current_page': int.parse('${r.query['page'] ?? 1}'), 'last_page': 3},
            },
          }),
        }),
      );
      final repo = h.read(dealsRepositoryProvider);
      final one = await repo.detail('d1');
      expect(one.deal.products.single.id, 'p1');
      expect(one.hasMore, isTrue);
      final two = await repo.detail('d1', page: 2);
      expect(two.currentPage, 2);
      expect(two.deal.products.single.id, 'p2');
      final calls = h.adapter.where('GET', '/deals/d1');
      expect(calls[0].query, isEmpty);
      expect(calls[1].query, {'page': '2'});
    });

    test('a deal page asks for the branch too, with the page number when it is not the first', () async {
      final h = await start(routes({'GET /deals/d1': (_) => ok(dealJson())}));
      final repo = h.read(dealsRepositoryProvider);
      await repo.detail('d1', branchId: 'br2');
      await repo.detail('d1', page: 3, branchId: 'br2');
      final calls = h.adapter.where('GET', '/deals/d1');
      expect(calls[0].query, {'branch_id': 'br2'});
      expect(calls[1].query, {'page': '3', 'branch_id': 'br2'});
    });

    test('an ended deal is a 404 the screen can word', () async {
      final h = await start(routes({'GET /deals/d1': (_) => error(404, 'deal_not_found')}));
      await expectLater(
        h.read(dealsRepositoryProvider).detail('d1'),
        throwsA(
          isA<ApiException>()
              .having((e) => e.kind, 'kind', ApiErrorKind.notFound)
              .having((e) => e.code, 'code', 'deal_not_found'),
        ),
      );
    });

    test('an id with odd characters cannot change the address', () async {
      final h = await start(routes({'GET /deals/a%2Fb': (_) => ok(dealJson(id: 'a/b'))}));
      final page = await h.read(dealsRepositoryProvider).detail('a/b');
      expect(page.deal.id, 'a/b');
    });
  });

  group('the footer\'s live dot', () {
    test('survives the footer leaving the tree for a moment (the keyboard opening): no new request', () async {
      final h = await start(
        routes({
          'GET /deals': (_) => ok(feedJson(live: [dealJson()])),
        }),
        extra: [footerHasDealsProvider.overrideWithValue(true)],
      );
      final first = h.container.listen(liveDealsProvider, (_, _) {});
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(h.read(liveDealsProvider), isTrue);
      final asked = h.adapter.where('GET', '/deals').length;
      expect(asked, 1);
      // The footer is dropped (keyboard open): its listener goes away; it comes back when the keyboard closes.
      first.close();
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(h.container.exists(liveDealsProvider), isTrue, reason: 'kept alive while the footer has a Deals item');
      h.container.listen(liveDealsProvider, (_, _) {});
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(h.read(liveDealsProvider), isTrue, reason: 'the dot did not flicker');
      expect(h.adapter.where('GET', '/deals').length, asked, reason: 'no new request');
    });

    test('with no Deals item in the footer it costs nothing: no request, no timer, nothing kept', () async {
      final h = await start(
        routes({
          'GET /deals': (_) => ok(feedJson(live: [dealJson()])),
        }),
        extra: [footerHasDealsProvider.overrideWithValue(false)],
      );
      final sub = h.container.listen(liveDealsProvider, (_, _) {});
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(h.read(liveDealsProvider), isFalse);
      expect(h.adapter.where('GET', '/deals'), isEmpty);
      sub.close();
      await Future<void>.delayed(const Duration(milliseconds: 50));
      expect(h.container.exists(liveDealsProvider), isFalse);
    });
  });
}
