import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/features/auctions/data/auction_repository.dart';
import 'package:albahar_customer/features/auth/data/auth_repository.dart';
import 'package:albahar_customer/features/auth/domain/auth_models.dart';
import 'package:albahar_customer/features/branches/data/branch_repository.dart';
import 'package:albahar_customer/features/cart/data/cart_repository.dart';
import 'package:albahar_customer/features/catalog/data/catalog_repository.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/checkout/data/checkout_repository.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/home/data/home_repository.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/orders/data/order_repository.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http_mock_adapter/http_mock_adapter.dart';

import '../helpers/fakes.dart';

void main() {
  late Dio dio;
  late DioAdapter mock;
  late ApiClient api;

  setUp(() {
    dio = Dio(BaseOptions(baseUrl: 'https://api.test/api/v1'));
    mock = DioAdapter(dio: dio);
    api = ApiClient(dio);
  });

  group('AuthRepository (http_mock_adapter)', () {
    test('verifyOtp → authenticated', () async {
      mock.onPost(
        '/auth/otp/verify',
        (s) => s.reply(200, {
          'data': {
            'status': 'authenticated',
            'user': {'id': 'u1', 'name': 'Noor Al-Thani', 'phone': '+97455123456', 'locale': 'ar'},
            'tokens': tokens('acc-secret-1', 'ref-secret-2'),
          },
        }),
        data: Matchers.any,
      );
      final r = await AuthRepository(api).verifyOtp('+97455123456', '123456');
      expect(r, isA<Authenticated>());
      final auth = r as Authenticated;
      expect(auth.user.firstName, 'Noor');
      expect(auth.tokens.accessToken, 'acc-secret-1');
      expect(auth.tokens.toString(), isNot(contains('secret')), reason: 'never print tokens');
    });

    test('verifyOtp → registration_required', () async {
      mock.onPost(
        '/auth/otp/verify',
        (s) => s.reply(200, {
          'data': {'status': 'registration_required', 'registration_token': 'rt', 'expires_in': 600},
        }),
        data: Matchers.any,
      );
      final r = await AuthRepository(api).verifyOtp('+97455123456', '123456');
      expect(r, isA<RegistrationRequired>().having((x) => x.registrationToken, 'token', 'rt'));
    });

    test('otp cooldown surfaces seconds', () async {
      mock.onPost(
        '/auth/otp/request',
        (s) => s.reply(429, {
          'message': 'Wait',
          'code': 'otp_cooldown',
          'context': {'seconds': 30},
        }),
        data: Matchers.any,
      );
      await expectLater(
        AuthRepository(api).requestOtp('+97455123456'),
        throwsA(isA<ApiException>().having((e) => e.retryAfter, 'retryAfter', const Duration(seconds: 30))),
      );
    });

    test('register body includes only provided optional fields', () {
      const req = RegistrationRequest(
        registrationToken: 'rt',
        name: 'Noor',
        email: '',
        password: 'pw123456',
        marketingConsent: true,
      );
      final body = req.toJson({'platform': 'ios'});
      expect(body['password_confirmation'], 'pw123456');
      expect(body.containsKey('email'), isFalse);
      expect(body['marketing_consent'], isTrue);
      expect(body['platform'], 'ios');
    });
  });

  group('CatalogRepository', () {
    test('products: filters, branch and paging', () async {
      final adapter = FakeAdapter((r) => page([productCard('1'), productCard('2')], last: 3));
      final repo = CatalogRepository(_client(adapter));
      final result = await repo.products(
        const ProductQuery(categoryId: 'c1', brandIds: {'b1'}, inStock: true, sort: ProductSort.priceAsc),
        branchId: 'br1',
        page: 2,
      );
      final q = adapter.requests.single.options.uri.queryParametersAll;
      expect(adapter.requests.single.path, '/products');
      expect(q['branch_id'], ['br1']);
      expect(q['category_id'], ['c1']);
      expect(q['brand_id[]'], ['b1']);
      expect(q['in_stock'], ['1']);
      expect(q['sort'], ['price']);
      expect(q['page'], ['2']);
      expect(result.items, hasLength(2));
      expect(result.hasMore, isTrue);
      expect(result.items.first.price!.finalPrice.formatted, 'QAR 100.00');
    });

    test('a text query uses /products/search', () async {
      final adapter = FakeAdapter((r) => page([]));
      await CatalogRepository(_client(adapter)).products(const ProductQuery(q: 'كنبة'));
      expect(adapter.requests.single.path, '/products/search');
      expect(adapter.requests.single.query['q'], 'كنبة');
    });

    test('product detail parses variants and resolves selection', () async {
      final adapter = FakeAdapter(
        (r) => ok({
          ...productCard('p1'),
          'attributes': [
            {
              'code': 'color',
              'name': 'Colour',
              'type': 'color',
              'values': [
                {'id': 'grey', 'code': 'grey', 'name': 'Grey', 'color_hex': '#888888'},
                {'id': 'sand', 'code': 'sand', 'name': 'Sand', 'color_hex': '#D8C3A5'},
              ],
            },
          ],
          'variants': [
            {
              'id': 'v1',
              'name': 'Grey',
              'attribute_value_ids': ['grey'],
              'price': price('1200.00'),
              'available_in_branch': true,
              'in_stock': true,
            },
            {
              'id': 'v2',
              'name': 'Sand',
              'attribute_value_ids': ['sand'],
              'price': price('1100.00', regular: '1300.00'),
              'available_in_branch': true,
              'in_stock': false,
            },
          ],
          'default_variant_id': 'v1',
          'availability': {'branch_id': 'br1', 'purchasable': true, 'reason': null},
          'dimensions': {'length': 220, 'width': 95, 'height': 80, 'unit': 'cm'},
          'warranty': {'duration_months': 24, 'provider': 'Al Bahar'},
          'related': [productCard('r1')],
        }),
      );
      final p = await CatalogRepository(_client(adapter)).product('p1', branchId: 'br1');
      expect(adapter.requests.single.query['branch_id'], 'br1');
      expect(p.defaultVariant!.id, 'v1');
      expect(p.resolveVariant({'color': 'sand'})!.id, 'v2');
      expect(p.resolveVariant({'color': 'sand'})!.price!.isOnSale, isTrue);
      expect(p.isValueSelectable({'color': 'grey'}, 'color', 'sand'), isTrue);
      expect(p.dimensions.length, 220);
      expect(p.warranty!.durationMonths, 24);
      expect(p.related.single.id, 'r1');
    });

    test('review submission uses multipart only with photos', () async {
      final adapter = FakeAdapter((r) => ok({'id': 'rv1'}, status: 201));
      final repo = CatalogRepository(_client(adapter));
      await repo.submitReview('p1', rating: 5, title: 'Lovely');
      expect(adapter.requests.last.body, {'rating': 5, 'title': 'Lovely'});
      await repo.submitReview(
        'p1',
        rating: 4,
        photos: [
          MultipartFile.fromBytes([1, 2, 3], filename: 'a.jpg'),
        ],
      );
      expect(adapter.requests.last.body, isA<FormData>());
      expect((adapter.requests.last.body as FormData).files.single.key, 'photos[]');
    });
  });

  group('HomeRepository', () {
    test('keeps server order and ignores unknown section types', () async {
      final adapter = FakeAdapter(
        (r) => ok({
          'sections': [
            {
              'id': 's1',
              'type': 'banners',
              'items': [
                {
                  'id': 'b',
                  'title': 'Sale',
                  'image_url': 'x',
                  'link': {'type': 'category', 'value': 'c1'},
                },
              ],
            },
            {
              'id': 's2',
              'type': 'hologram_carousel',
              'items': [1, 2],
            },
            {
              'id': 's3',
              'type': 'best_sellers',
              'title': 'Loved',
              'items': [productCard('1')],
            },
            {
              'id': 's4',
              'type': 'live_auctions',
              'items': [
                {'id': 'a1', 'title': 'Antiques', 'status': 'live'},
              ],
            },
            {'id': 's5', 'type': 'featured_products', 'items': []},
          ],
        }),
      );
      final feed = await HomeRepository(_client(adapter)).load(branchId: 'br1');
      expect(feed.sections.map((s) => s.id), ['s1', 's3', 's4']);
      expect((feed.sections.first as BannersSection).banners.single.link!.location, '/categories/c1');
      expect((feed.sections[1] as ProductsSection).listingQuery['bestseller'], '1');
      expect(adapter.requests.single.query['branch_id'], 'br1');
    });
  });

  group('Branch, cart, checkout, orders, auctions', () {
    test('branches nearby passes coordinates', () async {
      final adapter = FakeAdapter(
        (r) => page([
          {
            'id': 'b1',
            'name': 'West Bay',
            'distance_km': 2.4,
            'opening': {'is_open': true},
          },
        ]),
      );
      final list = await BranchRepository(_client(adapter)).nearby(lat: 25.3, lng: 51.5);
      expect(adapter.requests.single.query['lat'], '25.3');
      expect(list.single.distanceKm, 2.4);
      expect(list.single.opening!.isOpen, isTrue);
    });

    test('cart add sends only identifiers and quantities', () async {
      final adapter = FakeAdapter(
        (r) => ok({
          'id': 'c1',
          'items': [
            {
              'id': 'i1',
              'variant_id': 'v1',
              'product': productCard('p1'),
              'quantity': 2,
              'line_total': money('200.00'),
              'issue': null,
            },
          ],
          'items_count': 2,
          'subtotal': money('200.00'),
        }),
      );
      final cart = await CartRepository(_client(adapter)).addItem('v1', 2);
      expect(adapter.requests.single.body, {'variant_id': 'v1', 'quantity': 2});
      expect(cart!.subtotal.formatted, 'QAR 200.00');
      expect(cart.canCheckout, isTrue);
    });

    test('place order sends quote_hash and Idempotency-Key', () async {
      final adapter = FakeAdapter(
        (r) => ok({
          'order': {'id': 'o1', 'number': 'AB100123'},
          'payment': {
            'payment_id': 'pay1',
            'method': 'sadad',
            'status': 'pending',
            'action': 'redirect',
            'redirect_url': 'https://pay/x',
          },
        }, status: 201),
      );
      final placed = await CheckoutRepository(_client(adapter)).placeOrder(
        const CheckoutOptions(addressId: 'a1', paymentMethod: PaymentMethod.sadad),
        quoteHash: 'h1',
        idempotencyKey: 'key-1',
      );
      final r = adapter.requests.single;
      expect(r.header('Idempotency-Key'), 'key-1');
      expect(r.body['quote_hash'], 'h1');
      expect(r.body['payment_method'], 'sadad');
      expect(r.body['fulfillment_type'], 'delivery');
      expect(r.body.containsKey('pickup_slot_id'), isFalse);
      expect(placed.payment!.requiresRedirect, isTrue);
    });

    test('order list & detail parse', () async {
      final adapter = FakeAdapter(
        routes({
          'GET /orders': (_) => page([
            {
              'id': 'o1',
              'number': 'AB1',
              'status': 'processing',
              'totals': {'grand_total': money('50.00')},
            },
          ]),
          'GET /orders/o1/timeline': (_) => ok([
            {'kind': 'order', 'status': 'confirmed', 'label': 'Confirmed', 'at': '2026-11-04T18:30:00+03:00'},
          ]),
        }),
      );
      final repo = OrderRepository(_client(adapter));
      final list = await repo.list(status: 'processing');
      expect(list.items.single.grandTotal!.formatted, 'QAR 50.00');
      expect(adapter.requests.first.query['status'], 'processing');
      final tl = await repo.timeline('o1');
      expect(tl.single.at!.toUtc().hour, 15);
    });

    test('auction bid sends Idempotency-Key; events sorted by sequence', () async {
      final adapter = FakeAdapter(
        routes({
          'POST /auctions/lots/l1/bids': (_) => ok({
            'bid': {'bid_number': 9, 'amount': money('500.00'), 'bidder': 'You'},
            'lot': {
              'id': 'l1',
              'lot_number': 1,
              'title': 'Lamp',
              'status': 'open',
              'min_next_bid': money('550.00'),
              'last_sequence': 9,
            },
          }),
          'GET /auctions/lots/l1/events': (_) => ok([
            {'sequence': 12, 'type': 'bid.accepted'},
            {'sequence': 11, 'type': 'bid.accepted'},
          ]),
        }),
      );
      final repo = AuctionRepository(_client(adapter));
      final res = await repo.placeBid('l1', '500', idempotencyKey: 'bid-key');
      expect(adapter.requests.first.header('Idempotency-Key'), 'bid-key');
      expect(res.bid!.isMine, isTrue);
      expect(res.lot.minNextBid.amount, '550.00');
      final events = await repo.events('l1', afterSequence: 10);
      expect(events.map((e) => e.sequence), [11, 12]);
      expect(adapter.requests.last.query['after_sequence'], '10');
    });
  });
}

ApiClient _client(FakeAdapter adapter) {
  final dio = Dio(BaseOptions(baseUrl: 'https://api.test/api/v1'))..httpClientAdapter = adapter;
  return ApiClient(dio);
}
