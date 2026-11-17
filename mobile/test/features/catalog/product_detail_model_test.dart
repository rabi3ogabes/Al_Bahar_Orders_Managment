import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';

ProductDetail _parse(Map<String, dynamic> extra) => ProductDetail.fromJson({...productCard('p1'), ...extra});

void main() {
  group('extra delivery fee', () {
    test('a money object keeps the amount and the text the server formatted', () {
      final p = _parse({
        'extra_delivery_fee': {'amount': '50.00', 'currency': 'QAR', 'formatted': '50.00 ر.ق'},
        'extra_delivery_fee_mode': 'per_line',
      });
      expect(p.extraDeliveryFee?.amount, '50.00');
      expect(p.extraDeliveryFee?.formatted, '50.00 ر.ق');
      expect(p.extraDeliveryFeeMode, extraDeliveryPerLine);
      expect(p.hasExtraDeliveryFee, isTrue);
    });

    test('a bare decimal (string or number) becomes QAR with two places, in the currency of the price', () {
      expect(_parse({'extra_delivery_fee': '35.5'}).extraDeliveryFee?.formatted, 'QAR 35.50');
      expect(_parse({'extra_delivery_fee': 12}).extraDeliveryFee?.formatted, 'QAR 12.00');
      expect(_parse({'extra_delivery_fee': ' 7.25 '}).extraDeliveryFee?.amount, '7.25');
      final usd = _parse({
        'extra_delivery_fee': '9',
        'price': {
          'regular': {'amount': '5.00', 'currency': 'USD', 'formatted': '\$5.00'},
          'final': {'amount': '5.00', 'currency': 'USD', 'formatted': '\$5.00'},
        },
      });
      expect(usd.extraDeliveryFee?.formatted, 'USD 9.00');
    });

    test('missing, zero, negative or unreadable means no fee (null)', () {
      for (final raw in <Object?>[
        null,
        '',
        '  ',
        '0',
        '0.00',
        0,
        -3,
        '-1.50',
        'free',
        'NaN',
        'Infinity',
        true,
        <Object?>[],
        {'amount': '0.00'},
        {'amount': 'abc'},
        {'amount': '-4'},
      ]) {
        final p = _parse({'extra_delivery_fee': raw});
        expect(p.extraDeliveryFee, isNull, reason: '$raw');
        expect(p.hasExtraDeliveryFee, isFalse, reason: '$raw');
      }
      expect(_parse({}).extraDeliveryFee, isNull);
    });

    test('the mode is per_unit unless it says per_line', () {
      expect(_parse({'extra_delivery_fee': '5'}).extraDeliveryFeeMode, extraDeliveryPerUnit);
      expect(
        _parse({'extra_delivery_fee': '5', 'extra_delivery_fee_mode': 'per_unit'}).extraDeliveryFeeMode,
        'per_unit',
      );
      expect(
        _parse({'extra_delivery_fee': '5', 'extra_delivery_fee_mode': 'per_line'}).extraDeliveryFeeMode,
        'per_line',
      );
      expect(_parse({'extra_delivery_fee': '5', 'extra_delivery_fee_mode': 'weekly'}).extraDeliveryFeeMode, 'per_unit');
      expect(_parse({'extra_delivery_fee': '5', 'extra_delivery_fee_mode': 7}).extraDeliveryFeeMode, 'per_unit');
      expect(_parse({'extra_delivery_fee': '5', 'extra_delivery_fee_mode': null}).extraDeliveryFeeMode, 'per_unit');
    });
  });

  group('related and upsell lists', () {
    test('each product once, and never the product itself (their card images fly with a unique tag)', () {
      final p = _parse({
        'related': [productCard('r1'), productCard('r2'), productCard('r1'), productCard('p1')],
        'upsell': [productCard('u1'), productCard('u1'), productCard('p1')],
      });
      expect(p.related.map((e) => e.id), ['r1', 'r2']);
      expect(p.upsell.map((e) => e.id), ['u1']);
    });

    test('missing lists are empty', () {
      final p = _parse({});
      expect(p.related, isEmpty);
      expect(p.upsell, isEmpty);
    });
  });

  test('the photos of a variant come first, and a variant photo that is not in the list is added', () {
    final p = _parse({
      'images': [
        {'url': 'https://i/a.jpg', 'variant_id': 'v1'},
        {'url': 'https://i/b.jpg', 'variant_id': 'v2'},
        {'url': 'https://i/c.jpg'},
      ],
      'variants': [
        {'id': 'v1', 'attribute_value_ids': <String>[]},
        {'id': 'v2', 'attribute_value_ids': <String>[]},
        {'id': 'v3', 'attribute_value_ids': <String>[], 'image_url': 'https://i/d.jpg'},
      ],
    });
    expect(p.imagesFor(p.variantById('v2')).map((i) => i.url), [
      'https://i/b.jpg',
      'https://i/a.jpg',
      'https://i/c.jpg',
    ]);
    expect(p.imagesFor(p.variantById('v3')).map((i) => i.url).first, 'https://i/d.jpg');
    expect(p.imagesFor(null), hasLength(3));
  });
}
