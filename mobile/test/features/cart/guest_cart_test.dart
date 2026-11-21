// A signed-out customer's cart, the pure rules: one line per variant, caps, assembly only where it is offered, totals in both languages, the
// shop's changes read again (price, stock, a product that is gone, an assembly offer that ended), and folding the cart into the account on
// sign-in without ever doubling anything. Plus the words and shapes this feature adds: share text, the promise block, the quote's new fields.
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/domain/guest_cart.dart';
import 'package:albahar_customer/features/cart/domain/guest_merge.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/domain/share_text.dart';
import 'package:albahar_customer/features/checkout/domain/checkout_models.dart';
import 'package:albahar_customer/features/checkout/domain/delivery_promise.dart';
import 'package:albahar_customer/features/checkout/domain/slot_selection.dart';
import 'package:flutter_test/flutter_test.dart';

Map<String, dynamic> _money(String v) => {'amount': v, 'currency': 'QAR', 'formatted': 'QAR $v'};
Map<String, dynamic> _price(String v) => {'regular': _money(v), 'final': _money(v), 'sale': null};

ProductSummary _product({
  String id = 'p1',
  String name = 'Oslo sofa',
  String? fee = '100.00',
  String price = '1000.00',
}) => ProductSummary.fromJson({
  'id': id,
  'slug': 'oslo-sofa',
  'sku': 'SOF-1',
  'name': name,
  'price': _price(price),
  'in_stock': true,
  'assembly_fee': fee,
});

GuestCartRow _row({
  String variant = 'v1',
  int qty = 1,
  String? fee = '100.00',
  bool assembly = false,
  String price = '1000.00',
  int? available,
}) {
  final row = GuestCartRow.of(
    variantId: variant,
    product: _product(fee: fee, price: price),
    quantity: qty,
    unitPrice: EffectivePrice.fromJson(_price(price)),
    assembly: assembly,
  );
  return available == null ? row : row.copyWith(availableQuantity: available);
}

ProductDetail _detail({
  String id = 'p1',
  String? fee = '100.00',
  String price = '1000.00',
  bool inStock = true,
  int? available = 5,
  bool hasVariant = true,
}) => ProductDetail.fromJson({
  'id': id,
  'name': 'Oslo sofa',
  'price': _price(price),
  'assembly_fee': fee,
  'variants': [
    if (hasVariant)
      {
        'id': 'v1',
        'name': 'Grey',
        'price': _price(price),
        'available_in_branch': true,
        'in_stock': inStock,
        'available_quantity': available,
      },
  ],
  'default_variant_id': 'v1',
});

CartItem _line(
  String variant,
  int qty, {
  String price = '1000.00',
  Map<String, dynamic>? assembly,
  String? issue,
  int? available,
}) => CartItem.fromJson({
  'id': 'i-$variant',
  'variant_id': variant,
  'product': {'id': 'p-$variant', 'name': 'Item $variant', 'price': _price(price)},
  'quantity': qty,
  'unit_price': _price(price),
  'line_total': _money('0.00'),
  'issue': issue,
  'available_quantity': available,
  'assembly': assembly,
});

Cart _cart(List<CartItem> items) => Cart(id: 'c', items: items, itemsCount: items.length, subtotal: Money.zero);

void main() {
  group('the rows of a guest cart', () {
    test('the same variant added again grows the line, never a second one, and is capped', () {
      var rows = GuestCart.add(const [], _row(qty: 2), 2);
      rows = GuestCart.add(rows, _row(qty: 1), 1);
      expect(rows, hasLength(1));
      expect(rows.single.quantity, 3);
      rows = GuestCart.add(rows, _row(variant: 'v2'), 1);
      expect(rows.map((r) => r.variantId), ['v1', 'v2']);
      expect(GuestCart.add(rows, _row(), 99).first.quantity, guestCartMaxQuantity);
      expect(GuestCart.add(const [], _row(), 99).single.quantity, guestCartMaxQuantity);
      expect(GuestCart.add(rows, _row(), 0), same(rows), reason: 'nothing to add');
    });

    test('the stock the shop last reported is a cap too', () {
      final rows = GuestCart.add([_row(qty: 2, available: 3)], _row(available: 3), 5);
      expect(rows.single.quantity, 3);
      expect(GuestCart.setQuantity(rows, 'v1', 9).single.quantity, 3);
    });

    test('a quantity of zero removes the line; nothing is changed in place', () {
      final start = [_row(qty: 3), _row(variant: 'v2')];
      final next = GuestCart.setQuantity(start, 'v1', 0);
      expect(next.map((r) => r.variantId), ['v2']);
      expect(start, hasLength(2));
      expect(GuestCart.remove(start, 'v2').map((r) => r.variantId), ['v1']);
    });

    test('assembly can be ticked only where the product offers it, and adding more keeps the choice', () {
      var rows = [_row(qty: 2, fee: '100.00'), _row(variant: 'plain', fee: null)];
      rows = GuestCart.setAssembly(rows, 'plain', true);
      expect(rows.last.assembly, isFalse);
      rows = GuestCart.setAssembly(rows, 'v1', true);
      expect(rows.first.assembly, isTrue);
      rows = GuestCart.add(rows, _row(qty: 1), 1); // added again without the tick: the choice stays
      expect(rows.first.assembly, isTrue);
      expect(rows.first.quantity, 3);
      final turnedOn = GuestCart.add([_row()], _row(assembly: true), 1);
      expect(turnedOn.single.assembly, isTrue, reason: 'ticking it on a later add turns it on');
      expect(_row(fee: null, assembly: true).assembly, isFalse, reason: 'a product with no fee cannot start with it');
    });

    test('rows survive a round trip through storage, and a damaged row does not take the cart down', () {
      final row = _row(qty: 3, assembly: true, available: 4).copyWith(previousPrice: Money.fromJson(_money('900.00')));
      final back = GuestCartRow.fromJson(row.toJson());
      expect(back.variantId, 'v1');
      expect(back.quantity, 3);
      expect(back.assembly, isTrue);
      expect(back.assemblyFee!.amount, '100.00');
      expect(back.unit.amount, '1000.00');
      expect(back.previousPrice!.amount, '900.00');
      expect(back.availableQuantity, 4);
      expect(back.name, 'Oslo sofa');
      final odd = GuestCartRow.fromJson({'variant_id': 'v9', 'quantity': 'many'});
      expect(odd.quantity, 1, reason: 'unreadable counts read as one, never crash');
    });
  });

  group('the cart the screens draw', () {
    test('lines, subtotal and assembly total, written like the shop writes money (English)', () {
      final cart = GuestCart.toCart(
        [_row(qty: 3, assembly: true), _row(variant: 'v2', price: '250.50')],
        locale: 'en',
        branchId: 'br1',
      );
      expect(cart.itemsCount, 4);
      expect(cart.subtotal.amount, '3250.50');
      expect(cart.subtotal.formatted, 'QAR 3,250.50');
      expect(cart.assemblyTotal!.amount, '300.00');
      expect(cart.assemblyTotal!.formatted, 'QAR 300.00');
      expect(cart.branchId, 'br1');
      final sofa = cart.items.first;
      expect(sofa.id, 'guest-v1');
      expect(sofa.lineTotal!.formatted, 'QAR 3,000.00');
      expect(sofa.assembly!.selected, isTrue);
      expect(sofa.assembly!.amount!.formatted, 'QAR 300.00');
      expect(cart.items.last.assembly!.selected, isFalse);
    });

    test('the same cart in Arabic puts the currency after the number', () {
      final cart = GuestCart.toCart([
        _row(qty: 1250),
      ], locale: 'ar'); // 1250 is capped to the limit: use a big price instead
      expect(cart.items.single.quantity, guestCartMaxQuantity);
      final one = GuestCart.toCart([_row(price: '1250.50')], locale: 'ar');
      expect(one.subtotal.formatted, '1,250.50 ر.ق');
      expect(moneyOfMinor(-550, locale: 'en').formatted, 'QAR -5.50');
      expect(moneyOfMinor(5, locale: 'en').amount, '0.05');
      expect(moneyOfMinor(123456789, locale: 'en').formatted, 'QAR 1,234,567.89');
    });

    test('an awkward fee has no rounding to argue about: 3 x 33.33 is 99.99', () {
      final cart = GuestCart.toCart([_row(qty: 3, fee: '33.33', assembly: true)], locale: 'en');
      expect(cart.assemblyTotal!.amount, '99.99');
    });

    test('a line that cannot be bought is shown but not counted, and an empty cart is empty', () {
      final cart = GuestCart.toCart([_row(), _row(variant: 'v2').copyWith(issue: 'inactive')], locale: 'en');
      expect(cart.subtotal.amount, '1000.00');
      expect(cart.hasIssues, isTrue);
      expect(cart.canCheckout, isFalse);
      expect(GuestCart.toCart(const [], locale: 'en').isEmpty, isTrue);
      expect(GuestCart.toCart(const [], locale: 'en').assemblyTotal, isNull);
    });
  });

  group('the shop changed something behind the cart', () {
    test('a new price is said once and used from then on', () {
      final r = GuestCart.refresh([_row(price: '1000.00')], {'p1': _detail(price: '950.00')});
      expect(r.rows.single.unit.amount, '950.00');
      expect(r.rows.single.previousPrice!.amount, '1000.00');
      expect(r.changes.single.kind, GuestChangeKind.priceChanged);
      expect(r.changes.single.from!.amount, '1000.00');
      expect(r.changes.single.to!.amount, '950.00');
      // Read again with the same price: nothing to say and the old note goes.
      final again = GuestCart.refresh(r.rows, {'p1': _detail(price: '950.00')});
      expect(again.changes, isEmpty);
      expect(again.rows.single.previousPrice, isNull);
    });

    test('fewer on the shelf than wanted brings the line down and says so', () {
      final r = GuestCart.refresh([_row(qty: 5)], {'p1': _detail(available: 2)});
      expect(r.rows.single.quantity, 2);
      expect(r.rows.single.availableQuantity, 2);
      expect(r.changes.single.kind, GuestChangeKind.reduced);
      expect(r.changes.single.available, 2);
    });

    test('out of stock, or gone from the shop, marks the line unavailable once', () {
      final out = GuestCart.refresh([_row()], {'p1': _detail(inStock: false)});
      expect(out.rows.single.issue, 'inactive');
      expect(out.changes.single.kind, GuestChangeKind.unavailable);
      expect(GuestCart.refresh(out.rows, {'p1': _detail(inStock: false)}).changes, isEmpty, reason: 'told once');
      final gone = GuestCart.refresh([_row()], {'p1': _detail(hasVariant: false)});
      expect(gone.rows.single.issue, 'inactive');
      // Back in stock: the line is buyable again.
      expect(GuestCart.refresh(out.rows, {'p1': _detail()}).rows.single.issue, isNull);
    });

    test('an assembly offer that ended loses the tick; a changed fee is simply the new fee', () {
      final ended = GuestCart.refresh([_row(assembly: true)], {'p1': _detail(fee: null)});
      expect(ended.rows.single.assembly, isFalse);
      expect(ended.rows.single.assemblyFee, isNull);
      expect(ended.changes.single.kind, GuestChangeKind.assemblyRemoved);
      final cheaper = GuestCart.refresh([_row(assembly: true)], {'p1': _detail(fee: '80.00')});
      expect(cheaper.rows.single.assemblyFee!.amount, '80.00');
      expect(cheaper.rows.single.assembly, isTrue);
      expect(cheaper.changes, isEmpty);
    });

    test('a page that could not be read leaves its lines alone', () {
      final rows = [_row(qty: 2)];
      final r = GuestCart.refresh(rows, const {});
      expect(r.rows.single.quantity, 2);
      expect(r.changes, isEmpty);
    });
  });

  group('folding the guest cart into the account on sign-in', () {
    test(
      'a variant the account does not have is added; one it has is topped up to the larger quantity, never doubled',
      () {
        final account = _cart([_line('v1', 2)]);
        final steps = GuestMerge.plan(account, [
          _row(qty: 3),
          _row(variant: 'v2', qty: 1),
          _row(variant: 'v3', qty: 1),
        ]);
        expect(steps.map((s) => (s.variantId, s.addQuantity)).toList(), [('v1', 1), ('v2', 1), ('v3', 1)]);
        expect(
          GuestMerge.plan(_cart([_line('v1', 5)]), [_row(qty: 3)]),
          isEmpty,
          reason: 'the account already holds more',
        );
        expect(
          GuestMerge.plan(_cart([_line('v1', 3)]), [_row(qty: 3)]),
          isEmpty,
          reason: 'signing in twice adds nothing',
        );
      },
    );

    test('assembly stays ticked if either side ticked it', () {
      final offered = {'selected': false, 'fee_each': _money('100.00')};
      final ticked = {'selected': true, 'fee_each': _money('100.00')};
      // New line: the add carries the tick.
      expect(GuestMerge.plan(_cart(const []), [_row(assembly: true)]).single.assembly, isTrue);
      // The account has the line, unticked, with enough units: only the tick is sent, to the account's own line.
      final patch = GuestMerge.plan(_cart([_line('v1', 3, assembly: offered)]), [_row(qty: 3, assembly: true)]).single;
      expect(patch.addQuantity, 0);
      expect(patch.assemblyItemId, 'i-v1');
      // Already ticked there: nothing to do. A guest without the tick never unticks the account's.
      expect(GuestMerge.plan(_cart([_line('v1', 3, assembly: ticked)]), [_row(qty: 3, assembly: true)]), isEmpty);
      expect(GuestMerge.plan(_cart([_line('v1', 3, assembly: ticked)]), [_row(qty: 3)]), isEmpty);
    });

    test('what the server could not take as the guest left it is reported, line by line', () {
      final rows = [
        _row(qty: 5, price: '1000.00'),
        _row(variant: 'v2'),
        _row(variant: 'v3'),
        _row(variant: 'v4', assembly: true),
        _row(variant: 'v5'),
      ];
      final merged = _cart([
        _line('v1', 2, available: 2), // only 2 could be kept
        _line('v3', 1, issue: 'inactive'), // gone
        _line('v4', 1), // the product no longer offers assembly (the line carries none)
        _line('v5', 1, price: '900.00'), // the price changed
      ]);
      final changes = GuestMerge.report(merged, rows, refused: {'v2'});
      final byName = {for (final c in changes) '${c.kind.name}:${c.name}': c};
      expect(
        byName.keys,
        containsAll([
          'reduced:Oslo sofa',
          'unavailable:Oslo sofa',
          'assemblyRemoved:Oslo sofa',
          'priceChanged:Oslo sofa',
        ]),
      );
      expect(changes.where((c) => c.kind == GuestChangeKind.reduced).single.available, 2);
      expect(changes.where((c) => c.kind == GuestChangeKind.priceChanged).single.to!.amount, '900.00');
      expect(
        changes.where((c) => c.kind == GuestChangeKind.unavailable),
        hasLength(2),
        reason: 'the refused one and the one that is gone',
      );
      expect(
        GuestMerge.report(_cart([_line('v1', 1)]), [_row()]),
        isEmpty,
        reason: 'everything as left: nothing to say',
      );
    });
  });

  group('the words of a share', () {
    test('a product: intro, name with its option, price, and the plain link last', () {
      final text = productShareText(
        name: 'Oslo 3-Seater Sofa',
        variantName: 'Grey',
        intro: 'Take a look:',
        priceLine: 'Price: QAR 4,500.00',
        url: productShareUrl('https://albahar.qa/', 'oslo-3-seater-sofa'),
      );
      expect(
        text,
        'Take a look:\n\n*Oslo 3-Seater Sofa (Grey)*\nPrice: QAR 4,500.00\n\nhttps://albahar.qa/products/oslo-3-seater-sofa',
      );
    });

    test('Arabic names and links come through the WhatsApp link whole, and the link carries no tracking', () {
      final url = productShareUrl('https://albahar.qa', 'كنب-أوسلو');
      expect(url, 'https://albahar.qa/products/${Uri.encodeComponent('كنب-أوسلو')}');
      expect(url.contains('?') || url.contains('#'), isFalse);
      final text = productShareText(name: 'كنب أوسلو', intro: 'شاهد هذا:', url: url);
      expect(text.startsWith('شاهد هذا:\n\n*كنب أوسلو*\n\n'), isTrue);
      final uri = whatsAppShareUri(text);
      expect(uri.host, 'wa.me');
      expect(uri.queryParameters['text'], text);
    });

    test('a basket lists the pieces with their links, keeps the first fifteen, and ends with the total', () {
      final lines = [
        for (var i = 1; i <= 17; i++)
          ShareLine(name: 'Item $i', quantity: i, slug: i.isEven ? null : 's$i', variantName: i == 3 ? 'Brass' : null),
      ];
      final text = basketShareText(lines, intro: 'Hello', total: 'Total: QAR 10.00', origin: 'https://albahar.qa');
      expect(text.startsWith('Hello\n\n• Item 1 × 1\n  https://albahar.qa/products/s1\n• Item 2 × 2\n'), isTrue);
      expect(text, contains('• Item 3 (Brass) × 3'));
      expect(text, contains('• Item 15 × 15'));
      expect(text, isNot(contains('Item 16')));
      expect(text, contains('… +2'));
      expect(text.endsWith('Total: QAR 10.00'), isTrue);
    });
  });

  group('what the server sends about dates and assembly', () {
    test('the promise: a date, the split rows, the address hint, and "we will confirm"', () {
      final p = BasketPromise.fromJson({
        'enabled': true,
        'fulfillment_type': 'delivery',
        'status': 'scheduled',
        'message': 'All delivered by Tue 24 Nov',
        'detail': null,
        'all_by': {'date': '2026-11-24', 'label': 'Tue 24 Nov'},
        'is_split': true,
        'location_known': false,
        'parcels': [
          {
            'branch': {'id': 'a', 'name': 'West Bay'},
            'type': 'delivery',
            'status': 'scheduled',
            'message': 'Delivery today',
            'date': '2026-11-23',
          },
          {
            'branch': {'id': 'b', 'name': 'Al Wakra'},
            'type': 'delivery',
            'status': 'unconfirmed',
            'message': 'We will confirm your delivery date',
            'detail': 'Not in stock right now.',
          },
        ],
      });
      expect(p.visible, isTrue);
      expect(p.scheduled, isTrue);
      expect(p.showsParcels, isTrue);
      expect(p.needsAddress, isTrue);
      expect(p.allByLabel, 'Tue 24 Nov');
      expect(p.parcels.map((x) => x.scheduled), [true, false]);
      expect(p.parcels.last.detail, 'Not in stock right now.');

      final unconfirmed = BasketPromise.fromJson({
        'enabled': true,
        'fulfillment_type': 'pickup',
        'status': 'unconfirmed',
        'message': 'We will confirm your pick-up time',
        'location_known': true,
      });
      expect(unconfirmed.visible, isTrue);
      expect(unconfirmed.scheduled, isFalse);
      expect(unconfirmed.needsAddress, isFalse);
    });

    test('switched off, empty, or unreadable: nothing is shown', () {
      expect(BasketPromise.fromJson({'enabled': false, 'status': 'disabled', 'message': null}).visible, isFalse);
      expect(BasketPromise.fromJson({'enabled': true, 'status': 'scheduled', 'message': ''}).visible, isFalse);
      expect(BasketPromise.fromJson(const {}).visible, isFalse);
      expect(BasketPromise.maybe(null), isNull);
      expect(
        PromiseStatus.parse('something new'),
        PromiseStatus.unconfirmed,
        reason: 'a status this app does not know never reads as scheduled',
      );
    });

    test('the quote carries assembly (totals and lines), the promise and the first day items can be scheduled on', () {
      final q = Quote.fromJson({
        'quote_hash': 'h',
        'fulfillments': [
          {
            'branch': {'id': 'b', 'name': 'West Bay'},
            'type': 'delivery',
            'items': <Object>[],
            'earliest_date': '2026-11-26',
          },
        ],
        'lines': [
          {
            'variant_id': 'v1',
            'name': 'Sofa',
            'quantity': 3,
            'line_total': _money('3000.00'),
            'assembly': {'fee_each': _money('100.00'), 'amount': _money('300.00')},
          },
          {'variant_id': 'v2', 'name': 'Lamp', 'quantity': 1, 'line_total': _money('250.00'), 'assembly': null},
        ],
        'totals': {'subtotal': _money('3250.00'), 'assembly_fee': _money('300.00'), 'grand_total': _money('3565.00')},
        'promise': {
          'enabled': true,
          'fulfillment_type': 'delivery',
          'status': 'scheduled',
          'message': 'Delivery Thu 26 Nov',
        },
      });
      expect(q.totals.assemblyFee!.amount, '300.00');
      expect(q.lines.first.assemblyAmount!.amount, '300.00');
      expect(q.lines.last.assemblyAmount, isNull);
      expect(q.fulfillments.single.earliestDate, '2026-11-26');
      expect(q.promise!.message, 'Delivery Thu 26 Nov');
      // An older server sends none of it.
      final old = Quote.fromJson({
        'quote_hash': 'h',
        'totals': {'subtotal': _money('10.00'), 'grand_total': _money('10.00')},
      });
      expect(old.totals.assemblyFee, isNull);
      expect(old.promise, isNull);
    });

    test('a product and a cart line carry the assembly offer; an older server sends none', () {
      expect(_product(fee: '75.00').assemblyFee!.amount, '75.00');
      expect(_product(fee: '0.00').assemblyFee, isNull, reason: 'a zero fee is "not offered"');
      expect(_product(fee: null).assemblyFee, isNull);
      expect(ProductSummary.fromJson({'id': 'x', 'name': 'x', 'assembly_fee': 75}).assemblyFee!.amount, '75.00');
      final cart = Cart.fromJson({
        'id': 'c',
        'items': [
          {
            'id': 'i',
            'variant_id': 'v',
            'product': {'id': 'p', 'name': 'Sofa'},
            'quantity': 2,
            'assembly': {'selected': true, 'fee_each': _money('100.00'), 'amount': _money('200.00')},
          },
          {
            'id': 'j',
            'variant_id': 'w',
            'product': {'id': 'q', 'name': 'Lamp'},
            'quantity': 1,
            'assembly': null,
          },
        ],
        'subtotal': _money('2250.00'),
        'assembly_total': _money('200.00'),
      });
      expect(cart.assemblyTotal!.amount, '200.00');
      expect(cart.items.first.assembly!.selected, isTrue);
      expect(cart.items.first.assembly!.amount!.amount, '200.00');
      expect(cart.items.last.assembly, isNull);
      // A new quantity: the line keeps its choice and fee, the amount waits for the server.
      final more = cart.items.first.withQuantity(3);
      expect(more.assembly!.selected, isTrue);
      expect(more.assembly!.amount, isNull);
      expect(
        Cart.fromJson({'id': 'c', 'items': <Object>[], 'assembly_total': _money('0.00')}).assemblyTotal,
        isNull,
        reason: 'zero is none',
      );
    });
  });

  group('the time lists start where the items can be ready', () {
    final now = DateTime.utc(2026, 11, 23, 9); // 12:00 in Qatar, Monday

    test('no lead time: today through two weeks', () {
      expect(slotWindowDatesFrom(now, null), (from: '2026-11-23', to: '2026-12-06'));
    });

    test('made-to-order items start later, and an earlier date changes nothing', () {
      expect(slotWindowDatesFrom(now, '2026-11-26'), (from: '2026-11-26', to: '2026-12-09'));
      expect(slotWindowDatesFrom(now, '2026-11-20').from, '2026-11-23');
    });

    test('never past what the server offers (today + 29 days), and a lead time beyond it leaves nothing', () {
      expect(slotWindowDatesFrom(now, '2026-12-20'), (from: '2026-12-20', to: '2026-12-22'));
      final far = slotWindowDatesFrom(now, '2027-02-01');
      expect(far.from.compareTo(far.to) > 0, isTrue);
    });
  });
}
