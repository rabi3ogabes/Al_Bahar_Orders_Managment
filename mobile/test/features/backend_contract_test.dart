// Parsing / request tests against JSON copied from what the Laravel backend
// actually produces (controllers & presenters under backend/app). Money is
// always `App\Support\Money::jsonSerialize()` = {amount, currency, formatted}.
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/router/deep_links.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/auctions/domain/auction_models.dart';
import 'package:albahar_customer/features/cart/data/cart_repository.dart';
import 'package:albahar_customer/features/catalog/data/catalog_repository.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:albahar_customer/features/support/data/support_repository.dart';
import 'package:albahar_customer/features/wishlist/data/wishlist_repository.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

/// `Money::jsonSerialize()` in en (`QAR 1,250.50`) or ar (`1,250.50 ر.ق`).
Map<String, dynamic> m(String amount, {bool ar = false}) {
  final parts = amount.split('.');
  final whole = parts.first.replaceAllMapped(RegExp(r'\B(?=(\d{3})+(?!\d))'), (_) => ',');
  final value = '$whole.${parts.last}';
  return {'amount': amount, 'currency': 'QAR', 'formatted': ar ? '$value ر.ق' : 'QAR $value'};
}

/// `AuctionPresenter::lot()` for a signed-in viewer.
Map<String, dynamic> backendLot({String id = '9d5c1f3e-lot1', String? myStatus = 'leading'}) => {
  'id': id,
  'auction_id': '9d5c1f3e-auc1',
  'lot_number': 4,
  'title': 'Antique brass lantern',
  'description': 'Hand-made, 1950s',
  'image_url': 'https://cdn.albahar.qa/lots/lantern.jpg',
  'quantity': 1,
  'status': 'open',
  'starting_price': m('500.00'),
  'current_price': m('1250.00'),
  'min_next_bid': m('1300.00'),
  'min_increment': m('50.00'),
  'buy_now_price': null,
  'reserve_met': true,
  'bid_count': 7,
  'last_sequence': 12,
  'starts_at': '2026-11-04T18:00:00+03:00',
  'ends_at': '2026-11-04T21:00:00+03:00',
  'extension_count': 0,
  'server_time': '2026-11-04T19:12:44+03:00',
  'my_status': myStatus,
  'my_max_auto_bid': null,
  'is_watching': true,
};

void main() {
  group('Money', () {
    test('parses Money::jsonSerialize in both locales', () {
      expect(Money.fromJson(m('1250.50')).formatted, 'QAR 1,250.50');
      final ar = Money.fromJson(m('1250.50', ar: true));
      expect(ar.amount, '1250.50');
      expect(ar.currency, 'QAR');
      expect(ar.formatted, '1,250.50 ر.ق');
    });
  });

  group('Catalog engagement', () {
    test('reviews: `author` is a first-name string, photos are URL strings', () async {
      final adapter = FakeAdapter(
        (_) => const FakeReply(200, {
          'data': [
            {
              'id': '9d5c-r1',
              'rating': 5,
              'title': 'Beautiful sofa',
              'body': 'Exactly as pictured.',
              'author': 'Noor',
              'verified_purchase': true,
              'created_at': '2026-10-30T12:00:00+03:00',
              'photos': ['https://cdn.albahar.qa/reviews/a.jpg'],
            },
          ],
          'meta': {'total': 1, 'current_page': 1, 'last_page': 1},
        }),
      );
      final page = await CatalogRepository(_client(adapter)).reviews('p1');
      final r = page.items.single;
      expect(r.authorName, 'Noor');
      expect(r.verifiedPurchase, isTrue);
      expect(r.photos.single.url, 'https://cdn.albahar.qa/reviews/a.jpg');
      expect(page.hasMore, isFalse);
    });

    test('questions: plain list with answered_at', () async {
      final adapter = FakeAdapter(
        (_) => ok([
          {
            'id': 'q1',
            'question': 'Is it washable?',
            'answer': 'Yes, the covers are.',
            'answered_at': '2026-10-01T10:00:00+03:00',
          },
          {'id': 'q2', 'question': 'Any other colours?', 'answer': null, 'answered_at': null},
        ]),
      );
      final page = await CatalogRepository(_client(adapter)).questions('p1');
      expect(page.items, hasLength(2));
      expect(page.items.first.answeredAt, isNotNull);
      expect(page.items.first.createdAt, page.items.first.answeredAt);
      expect(page.items.last.answer, isNull);
    });

    test('report reason is one the backend accepts', () async {
      final adapter = FakeAdapter((_) => ok({'reported': true}));
      await CatalogRepository(_client(adapter)).reportReview('r1', 'offensive');
      expect(adapter.requests.single.body, {'reason': 'offensive'});
    });

    test('wishlist is a plain list and rows without a product are skipped', () async {
      final adapter = FakeAdapter(
        (_) => ok([
          {'id': 'w1', 'product': productCard('p1'), 'variant_id': null, 'added_at': '2026-11-01T09:00:00+03:00'},
          {'id': 'w2', 'product': null, 'variant_id': null, 'added_at': '2026-11-01T09:00:00+03:00'},
        ]),
      );
      final list = await WishlistRepository(_client(adapter)).list();
      expect(list.map((e) => e.id), ['w1']);
      expect(list.single.product.id, 'p1');
    });
  });

  group('Support', () {
    test('ticket detail: messages use `from` (customer | staff)', () async {
      final adapter = FakeAdapter(
        (_) => ok({
          'id': 't1',
          'number': 'TK-260001',
          'subject': 'Late delivery',
          'category': 'delivery',
          'status': 'pending_customer',
          'messages': [
            {'id': 'm1', 'from': 'customer', 'body': 'Where is my order?', 'created_at': '2026-11-03T10:00:00+03:00'},
            {'id': 'm2', 'from': 'staff', 'body': 'It is on the way.', 'created_at': '2026-11-03T11:00:00+03:00'},
          ],
        }),
      );
      final t = await SupportRepository(_client(adapter)).ticket('t1');
      expect(t.messages.map((x) => x.fromCustomer), [true, false]);
      expect(t.status, 'pending_customer');
    });

    test('ticket list rows carry status_label and updated_at only', () async {
      final adapter = FakeAdapter(
        (_) => ok([
          {
            'id': 't1',
            'number': 'TK-260001',
            'subject': 'Late delivery',
            'category': 'delivery',
            'status': 'open',
            'status_label': 'Open',
            'updated_at': '2026-11-03T11:00:00+03:00',
          },
        ]),
      );
      final page = await SupportRepository(_client(adapter)).tickets();
      expect(page.items.single.statusLabel, 'Open');
      expect(page.items.single.updatedAt, isNotNull);
      expect(page.hasMore, isFalse);
    });

    test('reply returns the whole ticket → no message parsed, caller re-fetches', () async {
      final adapter = FakeAdapter(
        (_) => ok({
          'id': 't1',
          'number': 'TK-1',
          'subject': 's',
          'category': 'other',
          'status': 'pending_staff',
          'messages': [],
        }),
      );
      expect(await SupportRepository(_client(adapter)).reply('t1', 'Thanks'), isNull);
      expect(adapter.requests.single.body, {'body': 'Thanks'});
    });
  });

  group('Notifications', () {
    test('push category mirrors NotificationCatalog', () {
      expect(notificationCategory('stock.back_in_stock'), 'marketing');
      expect(notificationCategory('stock.price_drop'), 'marketing');
      expect(notificationCategory('cart.abandoned'), 'marketing');
      expect(notificationCategory('campaign'), 'marketing');
      expect(notificationCategory('auction.payment_reminder'), 'auction');
      expect(notificationCategory('support.reply'), 'transactional');
      expect(notificationCategory('refund.processed'), 'transactional');
    });
  });

  group('Auctions', () {
    test('auction card / detail: cover_image_url and deposit', () {
      final card = {
        'id': '9d5c1f3e-auc1',
        'reference': 'AUC-2611-0001',
        'type': 'live',
        'status': 'live',
        'title': 'Heritage evening',
        'cover_image_url': 'https://cdn.albahar.qa/auctions/cover.jpg',
        'starts_at': '2026-11-04T18:00:00+03:00',
        'ends_at': '2026-11-04T22:00:00+03:00',
        'lots_count': 12,
        'deposit': m('1000.00'),
      };
      final summary = AuctionSummary.fromJson(card);
      expect(summary.imageUrl, 'https://cdn.albahar.qa/auctions/cover.jpg');
      expect(summary.deposit!.formatted, 'QAR 1,000.00');
      expect(summary.reference, 'AUC-2611-0001');

      final detail = Auction.fromJson({
        ...card,
        'description': 'Rare pieces',
        'terms': '<p>All sales final.</p>',
        'requires_registration': true,
        'requires_verified_phone': true,
        'winner_payment_hours': 48,
        'cod_allowed': false,
        'pickup_allowed': true,
        'delivery_allowed': true,
        'my_registration': {'status': 'approved', 'paddle_number': 17},
        'lots': [backendLot()],
        'server_time': '2026-11-04T19:12:44+03:00',
      });
      expect(detail.depositRequired!.amount, '1000.00');
      expect(detail.registration!.paddleNumber, '17');
      expect(detail.canBid, isTrue);
      expect(detail.termsHtml, contains('All sales final'));
      expect(detail.lots.single.currentPrice!.formatted, 'QAR 1,250.00');
    });

    test('bid history: Arabic "أنت" marks own bids (no is_mine field)', () async {
      final adapter = FakeAdapter(
        (_) => ok([
          {
            'bid_number': 7,
            'amount': m('1250.00', ar: true),
            'type': 'manual',
            'bidder': 'أنت',
            'placed_at': '2026-11-04T19:10:00+03:00',
          },
          {
            'bid_number': 6,
            'amount': m('1200.00', ar: true),
            'type': 'auto',
            'bidder': 'المزايد 12',
            'placed_at': '2026-11-04T19:09:00+03:00',
          },
        ]),
      );
      final bids = await AuctionRepository(_client(adapter)).bids('l1');
      expect(bids.map((b) => b.isMine), [true, false]);
      expect(bids.first.amount.formatted, '1,250.00 ر.ق');
      expect(adapter.requests.single.query.containsKey('per_page'), isFalse);
    });

    test('bid response has no bidder → treated as own bid', () async {
      final adapter = FakeAdapter(
        (_) => ok({
          'bid': {
            'bid_number': 8,
            'amount': m('1300.00'),
            'status': 'accepted',
            'placed_at': '2026-11-04T19:13:00+03:00',
          },
          'lot': backendLot(),
          'replayed': false,
        }, status: 201),
      );
      final res = await AuctionRepository(_client(adapter)).placeBid('l1', '1300', idempotencyKey: 'k' * 36);
      expect(res.bid!.isMine, isTrue);
      expect(res.bid!.amount.amount, '1300.00');
      expect(res.lot.myStatus, 'leading');
    });

    test('my bids = list of Lot (one per lot bid on)', () async {
      final adapter = FakeAdapter((_) => ok([backendLot(myStatus: 'outbid')]));
      final page = await AuctionRepository(_client(adapter)).myBids();
      final b = page.items.single;
      expect(b.id, '9d5c1f3e-lot1');
      expect(b.lot.myStatus, 'outbid');
      expect(b.amount, isNull);
      expect(b.displayAmount.formatted, 'QAR 1,250.00');
      expect(page.hasMore, isFalse);
    });

    test('watchlist = list of Lot', () async {
      final adapter = FakeAdapter((_) => ok([backendLot()]));
      final page = await AuctionRepository(_client(adapter)).watchlist();
      expect(page.items.single.isWatching, isTrue);
    });

    test('wins: payment_due_at and pending_payment', () async {
      final due = DateTime.now().add(const Duration(hours: 20)).toIso8601String();
      final adapter = FakeAdapter(
        (_) => ok([
          {
            'id': 'w1',
            'status': 'pending_payment',
            'amount': m('1250.00'),
            'payment_due_at': due,
            'order_id': null,
            'lot': backendLot(myStatus: 'won'),
          },
          {
            'id': 'w2',
            'status': 'paid',
            'amount': m('800.00'),
            'payment_due_at': due,
            'order_id': 'o9',
            'lot': backendLot(myStatus: 'won'),
          },
          {
            'id': 'w3',
            'status': 'pending_confirmation',
            'amount': m('300.00'),
            'payment_due_at': null,
            'order_id': null,
            'lot': backendLot(myStatus: 'won'),
          },
        ]),
      );
      final wins = (await AuctionRepository(_client(adapter)).wins()).items;
      expect(wins.first.payBy, isNotNull);
      expect(wins.map((w) => w.needsCheckout), [true, false, false]);
      expect(wins[1].orderId, 'o9');
    });

    test('replayed events carry payload, not lot/bid', () async {
      final adapter = FakeAdapter(
        (_) => const FakeReply(200, {
          'data': [
            {
              'sequence': 14,
              'type': 'extended',
              'payload': {'ends_at': '2026-11-04T21:02:00+03:00', 'extension_count': 1},
              'at': '2026-11-04T20:58:01+03:00',
            },
            {
              'sequence': 13,
              'type': 'bid_accepted',
              'payload': {'bid_number': 8, 'amount': '1300.00', 'type': 'manual'},
              'at': '2026-11-04T20:58:00+03:00',
            },
          ],
          'meta': {'last_sequence': 14, 'server_time': '2026-11-04T20:58:05+03:00'},
        }),
      );
      final events = await AuctionRepository(_client(adapter)).events('l1', afterSequence: 12);
      expect(events.map((e) => (e.sequence, e.type)), [(13, 'bid.accepted'), (14, 'lot.extended')]);
      expect(events.every((e) => e.lot == null && e.bid == null), isTrue);
    });
  });

  group('Orders', () {
    Map<String, dynamic> summary(String id, String status) => {
      'id': id,
      'number': 'AB26${id.hashCode.abs() % 10000}',
      'status': status,
      'status_label': status,
      'payment_status': 'paid',
      'payment_method': 'sadad',
      'fulfillment_type': 'delivery',
      'items_count': 3,
      'grand_total': m('2450.00'),
      'placed_at': '2026-11-02T15:20:00+03:00',
      'thumbnail_url': 'https://cdn.albahar.qa/products/sofa-320.jpg',
    };

    test('summary uses thumbnail_url and payment_method', () {
      final o = OrderSummary.fromJson(summary('o1', 'processing'));
      expect(o.thumbnails, ['https://cdn.albahar.qa/products/sofa-320.jpg']);
      expect(o.paymentMethod, 'sadad');
      expect(o.grandTotal!.formatted, 'QAR 2,450.00');
      expect(o.itemsCount, 3);
    });

    test('single-status filter is sent as ?status=', () async {
      final adapter = FakeAdapter((_) => page([summary('o1', 'completed')]));
      await OrderRepository(_client(adapter)).list(statuses: OrderFilter.completed.statuses);
      expect(adapter.requests.single.query['status'], 'completed');
    });

    test('multi-status group is filtered client-side, scanning ahead past empty pages', () async {
      final adapter = FakeAdapter((r) {
        final p = int.parse(r.query['page'] as String);
        return switch (p) {
          1 => page([summary('o1', 'completed'), summary('o2', 'cancelled')], current: 1, last: 3),
          2 => page([summary('o3', 'completed')], current: 2, last: 3),
          _ => page([summary('o4', 'returned'), summary('o5', 'processing')], current: 3, last: 3),
        };
      });
      final repo = OrderRepository(_client(adapter));
      final cancelled = OrderFilter.cancelled.statuses;

      final first = await repo.list(statuses: cancelled);
      expect(first.items.map((o) => o.id), ['o2']);
      expect(adapter.requests.single.query.containsKey('status'), isFalse);
      expect(first.hasMore, isTrue);

      final next = await repo.list(statuses: cancelled, page: first.currentPage + 1);
      expect(next.items.map((o) => o.id), ['o4'], reason: 'page 2 had no match, so page 3 was read too');
      expect(next.currentPage, 3);
      expect(next.hasMore, isFalse);
    });
  });

  group('Cart coupon', () {
    test('invalid coupon is reported only as a quote warning', () async {
      final adapter = FakeAdapter(
        (_) => ok({
          'quote_hash': 'a' * 64,
          'currency': 'QAR',
          'fulfillment_type': 'pickup',
          'payment_method': null,
          'is_split': false,
          'fulfillments': <Object?>[],
          'lines': <Object?>[],
          'totals': {'subtotal': m('100.00'), 'grand_total': m('100.00')},
          'discounts': <Object?>[],
          'payment_methods': <Object?>[],
          'loyalty': {'balance': 0, 'max_redeemable_points': 0, 'points_applied': 0, 'points_to_earn': 1},
          'warnings': [
            {'code': 'coupon_expired', 'message': 'This code has expired.'},
          ],
          'errors': <Object?>[],
          'hints': <Object?>[],
          'can_place_order': false,
          'expires_at': null,
        }),
      );
      final issue = await CartRepository(_client(adapter)).couponIssue('EID25');
      expect(issue!.code, 'coupon_expired');
      expect(issue.message, 'This code has expired.');
      expect(adapter.requests.single.body, {'coupon_code': 'EID25'});
    });

    test('valid coupon → no issue', () {
      final q = Quote.fromJson({
        'quote_hash': 'b' * 64,
        'totals': {'subtotal': m('100.00'), 'grand_total': m('90.00')},
        'warnings': [
          {'code': 'price_changed', 'message': 'Price changed'},
        ],
        'errors': <Object?>[],
      });
      expect(q.couponIssue, isNull);
    });
  });
}

ApiClient _client(FakeAdapter adapter) {
  final dio = Dio(BaseOptions(baseUrl: 'https://api.test/api/v1'))..httpClientAdapter = adapter;
  return ApiClient(dio);
}
