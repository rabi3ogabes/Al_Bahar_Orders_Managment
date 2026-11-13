import 'dart:async';

import 'package:albahar_customer/core/storage/token_storage.dart';

import '../helpers/fakes.dart';

/// What the fake backend answers for the screen matrix.
enum ApiMode { data, empty, error, loading, offline }

Map<String, dynamic> userJson({bool hasPassword = true}) => {
  'id': 'u1',
  'type': 'customer',
  'name': 'Noor Al Thani',
  'phone': '+97455123456',
  'email': 'noor@example.com',
  'phone_verified': true,
  'locale': 'en',
  'has_password': hasPassword,
  'marketing_consent': true,
  'referral_code': 'AB12CD',
  'gender': 'female',
  'date_of_birth': '1990-05-01',
};

Map<String, dynamic> addressJson(String id, {bool isDefault = false}) => {
  'id': id,
  'label': 'Home',
  'recipient_name': 'Noor Al Thani',
  'recipient_phone': '+97455123456',
  'delivery_zone': {'id': 'z1', 'name': 'West Bay'},
  'zone_number': '66',
  'street_number': '850',
  'building_number': '12',
  'unit_number': '4',
  'area': 'West Bay',
  'city': 'Doha',
  'landmark': 'Next to the blue gate',
  'instructions': 'Call on arrival',
  'is_default': isDefault,
};

Map<String, dynamic> cartJson() => {
  'id': 'c1',
  'branch_id': 'br1',
  'fulfillment_type': 'delivery',
  'address_id': 'a1',
  'coupon_code': null,
  'items': [
    {
      'id': 'i1',
      'variant_id': 'v1',
      'product': productCard('p1'),
      'quantity': 2,
      'unit_price': price('100.00'),
      'line_total': money('200.00'),
    },
  ],
  'items_count': 2,
  'subtotal': money('200.00'),
};

Map<String, dynamic> quoteJson() => {
  'quote_hash': 'h1',
  'fulfillments': [
    {
      'branch': {'id': 'br1', 'name': 'West Bay'},
      'type': 'delivery',
      'items': [],
      'delivery_fee': money('25.00'),
      'slot_required': false,
      'eta_minutes': 120,
    },
  ],
  'is_split': false,
  'lines': [],
  'totals': {'subtotal': money('200.00'), 'delivery_fee': money('25.00'), 'grand_total': money('225.00')},
  'payment_methods': [
    {'code': 'sadad', 'available': true},
    {'code': 'cod', 'available': true},
  ],
  'loyalty': {'balance': 1000, 'max_redeemable_points': 300, 'points_to_earn': 10},
  'warnings': [],
  'errors': [],
  'can_place_order': true,
};

Map<String, dynamic> orderSummary(String id, String status) => {
  'id': id,
  'number': 'AB26$id',
  'status': status,
  'status_label': status.replaceAll('_', ' '),
  'payment_status': 'paid',
  'payment_method': 'sadad',
  'fulfillment_type': 'delivery',
  'items_count': 3,
  'grand_total': money('2450.00'),
  'placed_at': '2026-11-02T15:20:00+03:00',
  'thumbnail_url': null,
};

Map<String, dynamic> orderDetail(String id, {String status = 'processing'}) => {
  ...orderSummary(id, status),
  'totals': {'subtotal': money('2400.00'), 'delivery_fee': money('50.00'), 'grand_total': money('2450.00')},
  'address': addressJson('a1'),
  'items': [
    {
      'id': 'oi1',
      'product_id': 'p1',
      'variant_id': 'v1',
      'sku': 'SKU-1',
      'name': 'Nordic oak dining table with a very long product name that wraps',
      'variant_name': 'Oak / 180cm',
      'image_url': null,
      'quantity': 2,
      'unit_price': money('1200.00'),
      'line_total': money('2400.00'),
      'fulfillment_id': 'f1',
    },
  ],
  'fulfillments': [
    {
      'id': 'f1',
      'number': 'AB26$id-1',
      'branch': {'id': 'br1', 'name': 'West Bay'},
      'type': 'delivery',
      'status': status,
      'status_label': status,
    },
  ],
  'can_cancel': true,
  'can_pay': false,
  'can_return': true,
  'can_review': true,
};

Map<String, dynamic> productDetail(String id) => {
  ...productCard(id),
  'variants': [
    {
      'id': 'v1',
      'name': 'Oak',
      'attribute_value_ids': <String>[],
      'price': price('100.00'),
      'available_in_branch': true,
      'in_stock': true,
    },
  ],
  'default_variant_id': 'v1',
  'description': '<p>Solid oak, hand finished.</p>',
};

String _soon() => DateTime.now().toUtc().add(const Duration(days: 2, hours: 3, minutes: 12)).toIso8601String();

Map<String, dynamic> lotJson() => {
  'id': 'lot1',
  'auction_id': 'auc1',
  'lot_number': 4,
  'title': 'Antique brass lantern',
  'description': 'Hand-made, 1950s',
  'image_url': null,
  'quantity': 1,
  'status': 'open',
  'starting_price': money('500.00'),
  'current_price': money('1250.00'),
  'min_next_bid': money('1300.00'),
  'min_increment': money('50.00'),
  'reserve_met': true,
  'bid_count': 7,
  'last_sequence': 12,
  'starts_at': '2026-11-01T18:00:00+03:00',
  'ends_at': _soon(),
  'extension_count': 0,
  'server_time': DateTime.now().toUtc().toIso8601String(),
  'my_status': 'leading',
  'my_max_auto_bid': null,
  'is_watching': true,
};

Map<String, dynamic> auctionJson(String id) => {
  'id': id,
  'title': 'Autumn heritage auction',
  'description': 'Rare pieces',
  'status': 'live',
  'starts_at': '2026-11-01T18:00:00+03:00',
  'ends_at': _soon(),
  'image_url': null,
  'lots_count': 1,
  'lots': [lotJson()],
  'registered': true,
};

Map<String, dynamic> branchJson(String id) => {
  'id': id,
  'name': 'West Bay',
  'address': 'Al Corniche St',
  'area': 'West Bay',
  'phone': '+97444000000',
  'latitude': 25.32,
  'longitude': 51.52,
  'offers_delivery': true,
  'offers_pickup': true,
  'is_open': true,
};

final signedInTokens = AuthTokens.fromJson(tokens('A', 'R'));

Map<String, dynamic> _home() => {
  'sections': [
    {
      'id': 's1',
      'type': 'banners',
      'items': [
        {'id': 'b1', 'title': 'The Autumn Edit', 'subtitle': 'New season', 'image_url': null},
      ],
    },
    {
      'id': 's3',
      'type': 'new_arrivals',
      'title': 'New in',
      'items': [productCard('1'), productCard('2')],
    },
  ],
};

/// A backend for the screen matrix. [mode] decides what GETs return;
/// writes always succeed in `data` mode.
FakeHandler customerBackend(ApiMode mode, {bool stress = false, bool arabic = false}) {
  final inner = _customerBackend(mode);
  if (!stress) return inner;
  return (RecordedRequest r) async {
    final reply = await inner(r);
    if (r.path == '/me') return reply;
    return FakeReply(reply.status, _stress(reply.body, arabic), reply.headers);
  };
}

const _stressKeys = {
  'name',
  'title',
  'subject',
  'body',
  'label',
  'address',
  'landmark',
  'description',
  'recipient_name',
  'variant_name',
  'status_label',
  'brand',
  'message',
  'area',
};

/// Replaces display strings with very long ones so layouts are tested at their limit.
Object? _stress(Object? value, bool arabic) {
  final filler = arabic
      ? 'نص طويل جداً لاختبار حدود التخطيط في الشاشات الصغيرة مع تكبير الخط والتفاف الأسطر'
      : 'Extraordinarily long text that keeps going to test wrapping and ellipsis on small screens';
  if (value is Map) {
    return value.map((k, v) {
      if (_stressKeys.contains(k) && v is String && v.isNotEmpty) return MapEntry(k, '$v $filler');
      return MapEntry(k, _stress(v, arabic));
    });
  }
  if (value is List) return value.map((e) => _stress(e, arabic)).toList();
  return value;
}

FakeHandler _customerBackend(ApiMode mode) {
  return (RecordedRequest r) {
    final path = r.path;
    if (r.method == 'GET' && path == '/me') return ok(userJson());
    if (r.method == 'GET' && path == '/app-update') return ok({'update_required': false});
    if (r.method == 'GET' && path == '/theme') return ok({});
    switch (mode) {
      case ApiMode.loading:
        return Completer<FakeReply>().future;
      case ApiMode.error:
        return error(500, 'server_error');
      case ApiMode.offline:
        return error(503, 'unavailable');
      case ApiMode.empty:
        return ok(const []);
      case ApiMode.data:
        break;
    }
    if (r.method != 'GET') return ok({});
    final seg = path.split('/').where((s) => s.isNotEmpty).toList();
    return switch (path) {
      '/home' => ok(_home()),
      '/categories' => ok([
        {'id': 'c1', 'name': 'Living room', 'slug': 'living', 'image_url': null, 'children': []},
        {'id': 'c2', 'name': 'Bedroom', 'slug': 'bedroom', 'image_url': null, 'children': []},
      ]),
      '/products' || '/products/search' => page([productCard('1'), productCard('2'), productCard('3')]),
      '/products/p1' || '/products/1' => ok(productDetail('p1')),
      '/cart' => ok(cartJson()),
      '/me/addresses' => page([addressJson('a1', isDefault: true), addressJson('a2')]),
      '/me/addresses/a1' => ok(addressJson('a1', isDefault: true)),
      '/delivery-zones' => ok([
        {'id': 'z1', 'name': 'West Bay'},
        {'id': 'z2', 'name': 'Lusail'},
      ]),
      '/branches' => ok([branchJson('br1')]),
      '/branches/br1' => ok(branchJson('br1')),
      '/orders' => page([orderSummary('o1', 'processing'), orderSummary('o2', 'completed')]),
      '/orders/o1' => ok(orderDetail('o1')),
      '/me/wishlist' => ok([
        {'id': 'w1', 'product': productCard('p1'), 'variant_id': null, 'added_at': '2026-11-01T09:00:00+03:00'},
      ]),
      '/me/loyalty' => ok({
        'balance': 1200,
        'tier': 'Silver',
        'value': money('12.00'),
        'lifetime_earned': 3000,
        'transactions': [
          {
            'type': 'earn',
            'points': 100,
            'balance_after': 1200,
            'reason': 'Order AB26o1',
            'created_at': '2026-11-01T09:00:00+03:00',
          },
        ],
      }),
      '/me/referral' => ok({
        'enabled': true,
        'code': 'AB12CD',
        'share_url': 'https://albahar.qa/?ref=AB12CD',
        'share_message': 'Join with AB12CD',
        'referrer_reward': {'type': 'balance', 'text': 'Get QAR 25.00'},
        'referred_count': 1,
        'friends': [
          {'name': 'Salem', 'status': 'pending'},
        ],
      }),
      '/me/support/tickets' => page([
        {
          'id': 't1',
          'number': 'T-1',
          'subject': 'Where is my order?',
          'status': 'open',
          'category': 'order',
          'created_at': '2026-11-01T09:00:00+03:00',
        },
      ]),
      '/me/support/tickets/t1' => ok({
        'id': 't1',
        'number': 'T-1',
        'subject': 'Where is my order?',
        'status': 'open',
        'category': 'order',
        'created_at': '2026-11-01T09:00:00+03:00',
        'messages': [
          {'id': 'm1', 'from': 'customer', 'body': 'Hello, any news?', 'created_at': '2026-11-01T09:00:00+03:00'},
          {'id': 'm2', 'from': 'staff', 'body': 'It ships today.', 'created_at': '2026-11-01T10:00:00+03:00'},
        ],
      }),
      '/me/notifications' => page([
        {
          'id': 'n1',
          'event': 'order.shipped',
          'title': 'Your order is on its way',
          'body': 'Arriving today',
          'created_at': '2026-11-01T09:00:00+03:00',
          'read_at': null,
        },
      ]),
      '/auctions' => page([auctionJson('auc1')]),
      '/auctions/auc1' => ok(auctionJson('auc1')),
      '/auctions/lots/lot1' => ok(lotJson()),
      '/pages/terms' || '/pages/faq' => ok({'slug': 'terms', 'title': 'Terms', 'body': '<p>Hello</p>'}),
      _ => seg.isEmpty ? ok(const []) : ok(const []),
    };
  };
}
