import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/branches/domain/branch_models.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/features/cart/domain/cart_models.dart';
import 'package:albahar_customer/features/cart/presentation/state/cart_controller.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/catalog_widgets.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/quick_add_stepper.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/state/order_providers.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/active_order_card.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

ProductSummary _product({String? variantId = 'v1', bool hasVariants = false, bool inStock = true}) =>
    ProductSummary.fromJson({
      'id': 'p1',
      'name': 'Dishwashing liquid',
      'variant_id': variantId,
      'has_variants': hasVariants,
      'in_stock': inStock,
      'price': {
        'regular': {'amount': '45.00', 'currency': 'QAR', 'formatted': 'QAR 45.00'},
        'final': {'amount': '45.00', 'currency': 'QAR', 'formatted': 'QAR 45.00'},
      },
    });

class _FakeCart extends CartController {
  _FakeCart(this.initialQty, this.calls);
  final int initialQty;
  final List<String> calls;

  @override
  Future<Cart> build() async => Cart(
    id: 'c1',
    itemsCount: initialQty,
    subtotal: Cart.empty.subtotal,
    items: [
      if (initialQty > 0)
        CartItem(id: 'i1', variantId: 'v1', product: _product(), quantity: initialQty, availableQuantity: 5),
    ],
  );

  @override
  Future<Cart> addItem(String variantId, {int quantity = 1}) async {
    calls.add('add:$variantId:$quantity');
    return state.requireValue;
  }

  @override
  Future<void> updateQuantity(String itemId, int quantity) async => calls.add('qty:$itemId:$quantity');
}

class _NoBranch extends PreferredBranchController {
  @override
  BranchRef? build() => null;
}

Widget _host(Widget child, List<Override> overrides) => ProviderScope(
  key: UniqueKey(),
  overrides: overrides,
  child: MaterialApp(
    locale: const Locale('en'),
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: const [
      AppLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    theme: AppTheme.light(const Locale('en')),
    home: Scaffold(body: Center(child: child)),
  ),
);

void main() {
  testWidgets('a product not in the bag shows a round + that adds it', (tester) async {
    final calls = <String>[];
    await tester.pumpWidget(
      _host(QuickAddStepper(product: _product()), [cartControllerProvider.overrideWith(() => _FakeCart(0, calls))]),
    );
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.add_rounded), findsOneWidget);
    expect(find.byIcon(Icons.remove_rounded), findsNothing);
  });

  testWidgets('a product in the bag shows − 2 + and changes the quantity', (tester) async {
    final calls = <String>[];
    await tester.pumpWidget(
      _host(QuickAddStepper(product: _product()), [cartControllerProvider.overrideWith(() => _FakeCart(2, calls))]),
    );
    await tester.pumpAndSettle();
    expect(find.text('2'), findsOneWidget);
    await tester.tap(find.byIcon(Icons.add_rounded));
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.remove_rounded));
    await tester.pumpAndSettle();
    expect(calls, ['qty:i1:3', 'qty:i1:1']);
  });

  testWidgets('the + stops at the stock limit and one item shows a bin instead of −', (tester) async {
    final calls = <String>[];
    await tester.pumpWidget(
      _host(QuickAddStepper(product: _product()), [cartControllerProvider.overrideWith(() => _FakeCart(5, calls))]),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.byIcon(Icons.add_rounded));
    await tester.pumpAndSettle();
    expect(calls, isEmpty); // 5 of 5 in stock
    await tester.pumpWidget(
      _host(QuickAddStepper(product: _product()), [cartControllerProvider.overrideWith(() => _FakeCart(1, calls))]),
    );
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.delete_outline_rounded), findsOneWidget);
  });

  testWidgets('out-of-stock products have no add control', (tester) async {
    await tester.pumpWidget(
      _host(QuickAddStepper(product: _product(inStock: false)), [
        cartControllerProvider.overrideWith(() => _FakeCart(0, [])),
      ]),
    );
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.add_rounded), findsNothing);
  });

  testWidgets('the branch dropdown is hidden when there is only one branch', (tester) async {
    await tester.pumpWidget(_host(const BranchPill(), [multipleBranchesProvider.overrideWithValue(false)]));
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.expand_more_rounded), findsNothing);
    await tester.pumpWidget(
      _host(const BranchPill(), [
        multipleBranchesProvider.overrideWithValue(true),
        preferredBranchProvider.overrideWith(_NoBranch.new),
      ]),
    );
    await tester.pumpAndSettle();
    expect(find.byIcon(Icons.expand_more_rounded), findsOneWidget);
  });

  testWidgets('the live order card shows the step the order is on', (tester) async {
    OrderSummary order(String status, String type) =>
        OrderSummary.fromJson({'id': 'o1', 'number': 'AB100008', 'status': status, 'fulfillment_type': type});
    await tester.pumpWidget(
      _host(const ActiveOrderCard(), [
        activeOrdersProvider.overrideWith((ref) async => [order('out_for_delivery', 'delivery')]),
      ]),
    );
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
    expect(find.text('Your order is on the way'), findsOneWidget);
    expect(find.text('Order AB100008'), findsOneWidget);
    expect(find.text('On the way'), findsOneWidget);
    expect(orderStep(order('confirmed', 'delivery')), 0);
    expect(orderStep(order('processing', 'pickup')), 1);
    expect(orderStep(order('ready_for_pickup', 'pickup')), 2);
    expect(orderStep(order('completed', 'delivery')), 3);
  });

  testWidgets('no card when no order is in progress', (tester) async {
    await tester.pumpWidget(
      _host(const ActiveOrderCard(), [activeOrdersProvider.overrideWith((ref) async => const <OrderSummary>[])]),
    );
    await tester.pumpAndSettle();
    expect(find.text('Order AB100008'), findsNothing);
  });
}
