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
  _searchAndBuyAgainModels();
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

void _searchAndBuyAgainModels() {
  test('search suggestions and buy-again items parse the API shape', () {
    final s = SearchSuggestions.fromJson({
      'query': 'lavendr',
      'products': [],
      'categories': [
        {'id': 'c1', 'slug': 'dish', 'name': 'Dishwashing'},
      ],
      'brands': [],
      'did_you_mean': 'lavender',
    });
    expect(s.didYouMean, 'lavender');
    expect(s.isEmpty, isFalse);
    final b = BuyAgainItem.fromJson({
      'product': {'id': 'p1', 'name': 'Towel'},
      'variant_id': 'v1',
      'last_quantity': 3,
      'times_ordered': 2,
    });
    expect([b.variantId, b.lastQuantity, b.timesOrdered], ['v1', 3, 2]);
  });
}
