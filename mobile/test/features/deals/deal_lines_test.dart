// What a cart line, a quote line and a placed order line say about a deal (docs/api/DEALS.md section 5), and how the app words it.
import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/domain/deal_lines.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_line_text.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_product_strip.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../helpers/fakes.dart';
import 'deals_fixtures.dart';

AppLocalizations get _en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get _ar => lookupAppLocalizations(const Locale('ar'));

Map<String, dynamic> _quoteLineDeal({
  int deal = 2,
  int regular = 1,
  String? note = 'limit_per_order',
  String savings = '60.00',
}) => {
  'deal_id': 'd1',
  'name': 'Weekend Furniture Drop',
  'units_at_deal_price': deal,
  'units_at_regular_price': regular,
  'savings': money(savings),
  'ends_at': iso(dealsNow.add(const Duration(hours: 5))),
  'note': note,
};

void main() {
  group('DealLineUnits', () {
    test('reads the counts and the reason', () {
      final u = DealLineUnits.maybe(_quoteLineDeal())!;
      expect((u.unitsAtDealPrice, u.unitsAtRegularPrice, u.total), (2, 1, 3));
      expect(u.note, DealNote.limitPerOrder);
      expect(DealLineUnits.maybe({'units_at_deal_price': '1', 'units_at_regular_price': 0})!.total, 1);
    });

    test('no counts, negative counts or garbage mean "no words", never a crash', () {
      for (final raw in <Object?>[
        null,
        'x',
        3,
        <Object?>[],
        <String, dynamic>{},
        {'units_at_deal_price': 2},
        {'units_at_deal_price': -1, 'units_at_regular_price': 1},
      ]) {
        expect(DealLineUnits.maybe(raw), isNull, reason: '$raw');
      }
      expect(
        DealLineUnits.maybe({'units_at_deal_price': 1, 'units_at_regular_price': 1, 'note': 'martians'})!.note,
        isNull,
      );
      expect(DealNote.parse('SOLD_OUT'), DealNote.soldOut);
      expect(DealNote.parse(5), isNull);
    });
  });

  group('the words of a line', () {
    String? en(int deal, int regular, [DealNote? note]) =>
        dealLineText(_en, DealLineUnits(unitsAtDealPrice: deal, unitsAtRegularPrice: regular, note: note));

    test('"Deal price on 2 of 3 · limit 2 per order"', () {
      expect(en(2, 1, DealNote.limitPerOrder), 'Deal price on 2 of 3 · limit 2 per order');
    });

    test('the other reasons', () {
      expect(en(1, 2, DealNote.limitPerCustomer), 'Deal price on 1 of 3 · your account limit is reached');
      expect(en(1, 4, DealNote.soldOut), 'Deal price on 1 of 5 · deal sold out');
      expect(en(2, 1, DealNote.ended), 'Deal price on 2 of 3 · deal ended');
      expect(en(2, 1), 'Deal price on 2 of 3', reason: 'no reason given: none invented');
    });

    test('every unit at the deal price', () {
      expect(en(3, 0), 'Deal price on all 3');
      expect(en(1, 0), 'Deal price on all 1');
    });

    test('no unit at the deal price: only the reason, in plain words', () {
      expect(en(0, 2, DealNote.limitPerCustomer), 'You reached your limit for this deal');
      expect(en(0, 2, DealNote.soldOut), 'deal sold out');
      expect(en(0, 2, DealNote.ended), 'deal ended');
      expect(en(0, 2), isNull, reason: 'nothing to say');
    });

    test('Arabic', () {
      final ar = dealLineText(
        _ar,
        const DealLineUnits(unitsAtDealPrice: 2, unitsAtRegularPrice: 1, note: DealNote.limitPerOrder),
      );
      expect(ar, 'سعر العرض على 2 من 3 · الحد 2 لكل طلب');
      expect(
        dealLineText(
          _ar,
          const DealLineUnits(unitsAtDealPrice: 0, unitsAtRegularPrice: 2, note: DealNote.limitPerCustomer),
        ),
        'وصلت إلى الحد الأقصى لهذا العرض',
      );
    });

    test('a placed order line: the deal, the units and what each saved', () {
      final deal = OrderLineDeal.maybe({
        'deal_id': 'd1',
        'name': 'Weekend Furniture Drop',
        'units': 2,
        'unit_savings': money('30.00'),
      })!;
      expect(dealOrderLineText(_en, deal, 3), 'Weekend Furniture Drop · Deal price on 2 of 3 · saved QAR 30.00 each');
      expect(dealOrderLineText(_en, deal, 2), 'Weekend Furniture Drop · Deal price on all 2 · saved QAR 30.00 each');
      expect(dealOrderLineText(_ar, deal, 3), contains('سعر العرض على 2 من 3'));
      final free = OrderLineDeal.maybe({'deal_id': 'd1', 'name': 'X', 'units': 1, 'unit_savings': money('0.00')})!;
      expect(dealOrderLineText(_en, free, 1), 'X · Deal price on all 1');
    });
  });

  group('the quote and the order carry the deal', () {
    Map<String, dynamic> quoteLine({Object? deal}) => {
      'variant_id': 'v1',
      'name': 'Oak table',
      'quantity': 3,
      'line_total': money('240.00'),
      'deal': deal,
    };

    test('a quote line reads its deal; a line without one, or with garbage, has none', () {
      final line = QuoteLine.fromJson(quoteLine(deal: _quoteLineDeal()));
      expect(line.deal!.dealId, 'd1');
      expect(line.deal!.name, 'Weekend Furniture Drop');
      expect(line.deal!.units.unitsAtDealPrice, 2);
      expect(line.deal!.savings?.formatted, 'QAR 60.00');
      expect(line.deal!.endsAt, dealsNow.add(const Duration(hours: 5)));
      expect(QuoteLine.fromJson(quoteLine()).deal, isNull);
      expect(QuoteLine.fromJson(quoteLine(deal: 'x')).deal, isNull);
      expect(QuoteLine.fromJson(quoteLine(deal: {'deal_id': 'd1'})).deal, isNull, reason: 'no counts: no words');
    });

    test('totals.deal_savings, and a discount knows it is a deal', () {
      expect(
        OrderTotals.fromJson({'grand_total': money('1.00'), 'deal_savings': money('60.00')}).dealSavings?.formatted,
        'QAR 60.00',
      );
      expect(OrderTotals.fromJson({'grand_total': money('1.00')}).dealSavings, isNull, reason: 'an older server');
      expect(
        OrderTotals.fromJson({'grand_total': money('1.00'), 'deal_savings': '12.50'}).dealSavings?.amount,
        '12.50',
      );
      expect(QuoteDiscount.fromJson({'label': 'Weekend', 'amount': money('60.00'), 'source': 'deal'}).isDeal, isTrue);
      expect(
        QuoteDiscount.fromJson({'label': 'Autumn', 'amount': money('5.00'), 'source': 'promotion'}).isDeal,
        isFalse,
      );
      expect(QuoteDiscount.fromJson({'label': 'Autumn', 'amount': money('5.00')}).isDeal, isFalse);
    });

    test('a placed order line keeps the deal snapshot; one without a deal price has none', () {
      OrderItem item(Object? deal) => OrderItem.fromJson({'id': 'i1', 'name': 'Table', 'quantity': 3, 'deal': deal});
      final deal = item({'deal_id': 'd1', 'name': 'Weekend', 'units': 2, 'unit_savings': money('30.00')}).deal!;
      expect(deal.units, 2);
      expect(deal.unitSavings?.formatted, 'QAR 30.00');
      expect(item(null).deal, isNull);
      expect(item({'deal_id': 'd1', 'name': 'x', 'units': 0}).deal, isNull);
      expect(item('nope').deal, isNull);
    });

    test('an order keeps its discounts one by one', () {
      final o = Order.fromJson({
        'id': 'o1',
        'number': 'AB1',
        'status': 'placed',
        'items': <Object?>[],
        'fulfillments': <Object?>[],
        'discounts': [
          {'source': 'deal', 'label': 'Weekend', 'amount': money('60.00')},
          {'source': 'promotion', 'label': 'Autumn', 'amount': money('5.00')},
        ],
      });
      expect(o.discounts.map((d) => d.isDeal), [true, false]);
      expect(
        Order.fromJson({
          'id': 'o1',
          'number': 'AB1',
          'status': 'placed',
          'items': <Object?>[],
          'fulfillments': <Object?>[],
        }).discounts,
        isEmpty,
      );
    });
  });

  group('the cart line', () {
    Map<String, dynamic> line({Object? deal, Object? cardDeal}) => {
      'id': 'i1',
      'variant_id': 'v1',
      'product': {...productCard('p1'), 'deal': cardDeal},
      'quantity': 3,
      'unit_price': price('100.00'),
      'line_total': money('300.00'),
      'deal': deal,
    };

    test('the line\'s own deal carries the units; the product card\'s block is the fallback', () {
      final own = CartItem.fromJson(
        line(
          deal: {...dealBlockJson(), 'units_at_deal_price': 2, 'units_at_regular_price': 1, 'note': 'limit_per_order'},
        ),
      );
      expect(own.deal?.dealId, 'd1');
      expect(own.dealUnits?.unitsAtDealPrice, 2);
      expect(own.dealUnits?.note, DealNote.limitPerOrder);
      final viaCard = CartItem.fromJson(line(cardDeal: dealBlockJson()));
      expect(viaCard.deal?.dealId, 'd1');
      expect(viaCard.dealUnits, isNull, reason: 'no counts: the words wait for the server');
      final none = CartItem.fromJson(line());
      expect(none.deal, isNull);
      expect(none.dealUnits, isNull);
    });

    test('a new quantity drops the counts of the old one (the server words the new one)', () {
      final item = CartItem.fromJson(
        line(deal: {...dealBlockJson(), 'units_at_deal_price': 2, 'units_at_regular_price': 1}),
      );
      final more = item.withQuantity(5);
      expect(more.deal?.dealId, 'd1');
      expect(more.dealUnits, isNull);
    });

    test('the cart carries the savings of its deals', () {
      Cart cart(Object? v) =>
          Cart.fromJson({'id': 'c1', 'items': <Object?>[], 'subtotal': money('0.00'), 'deal_savings': v});
      expect(cart(money('60.00')).dealSavings?.formatted, 'QAR 60.00');
      expect(cart(null).dealSavings, isNull);
      expect(cart('x').dealSavings, isNull);
    });
  });

  group('a variant of a product in a deal', () {
    final block = DealInfo.maybe(dealBlockJson(dealPrice: '70.00', regular: '100.00'))!;
    ProductVariant variant(String id, {String? deal, String normal = '100.00'}) => ProductVariant.fromJson({
      'id': id,
      'name': id,
      'attribute_value_ids': <String>[],
      'price': price(normal),
      'deal_price': deal == null ? null : money(deal),
    });

    test('a variant row carries its own deal price', () {
      expect(variant('v1', deal: '70.00').dealPrice?.formatted, 'QAR 70.00');
      expect(variant('v1').dealPrice, isNull);
    });

    test('the cheapest variant reads as the block, another shows its own price (and no percent), one with no deal shows none', () {
      final small = variant('s', deal: '70.00');
      final large = variant('l', deal: '84.00', normal: '120.00');
      final other = variant('o', normal: '60.00');
      final all = [small, large, other];
      expect(dealForVariant(block, all, small), same(block));
      final forLarge = dealForVariant(block, all, large)!;
      expect(forLarge.dealPrice?.formatted, 'QAR 84.00');
      expect(forLarge.regularPrice?.formatted, 'QAR 120.00');
      expect(forLarge.percent, isNull);
      expect(forLarge.dealId, block.dealId);
      expect(forLarge.remaining, block.remaining);
      expect(dealForVariant(block, all, other), isNull, reason: 'its normal price is already below the deal');
    });

    test('an older server (no variant prices) and a product with no deal', () {
      final plain = [variant('a'), variant('b')];
      expect(dealForVariant(block, plain, plain.first), same(block));
      expect(dealForVariant(null, [variant('a', deal: '1.00')], null), isNull);
      expect(dealForVariant(block, [variant('a', deal: '70.00')], null), same(block));
    });
  });
}
