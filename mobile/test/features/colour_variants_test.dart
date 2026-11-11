import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, Object?> _product({required bool twoColours}) => {
  'id': 'p1',
  'name': 'Towel',
  'attributes': [
    {
      'code': 'color',
      'name': 'Colour',
      'type': 'color',
      'values': [
        {'id': 'c1', 'code': 'red', 'name': 'Red', 'color_hex': '#8A1538'},
        if (twoColours) {'id': 'c2', 'code': 'white', 'name': 'White', 'color_hex': '#FFFFFF'},
      ],
    },
  ],
  'variants': [
    {
      'id': 'v1',
      'name': 'Red',
      'attribute_value_ids': ['c1'],
      'in_stock': true,
    },
    if (twoColours)
      {
        'id': 'v2',
        'name': 'White',
        'attribute_value_ids': ['c2'],
        'in_stock': false,
      },
  ],
};

void main() {
  test('a product sold in several colours exposes its colour attribute and one variant per colour', () {
    final p = ProductDetail.fromJson(_product(twoColours: true));
    expect(p.colourAttribute?.code, 'color');
    expect(p.resolveVariant({'color': 'c1'})?.id, 'v1');
    expect(p.resolveVariant({'color': 'c2'})?.purchasable, isFalse);
  });

  test('a single colour keeps the normal flow (no per-colour quantities)', () {
    expect(ProductDetail.fromJson(_product(twoColours: false)).colourAttribute, isNull);
  });
}
