import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import 'deals_fixtures.dart';

void main() {
  late DateTime phone;

  Future<TestHarness> start(FakeHandler handler) =>
      TestHarness.create(handler: handler, overrides: [dealDeviceNowProvider.overrideWithValue(() => phone)]);

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
      expect(h.read(dealSkewProvider), Duration.zero);
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
      expect(h2.read(dealSkewProvider), Duration.zero);
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
}
