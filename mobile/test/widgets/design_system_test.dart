import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/theme/tokens.dart';
import 'package:albahar_customer/core/utils/phone.dart';
import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/core/widgets/common.dart';
import 'package:albahar_customer/core/widgets/countdown.dart';
import 'package:albahar_customer/core/widgets/price_tag.dart';
import 'package:albahar_customer/core/widgets/product_card.dart';
import 'package:albahar_customer/core/widgets/rating.dart';
import 'package:albahar_customer/core/widgets/state_views.dart';
import 'package:albahar_customer/features/catalog/domain/catalog_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

const _ar = Locale('ar');

EffectivePrice _sale() => EffectivePrice.fromJson(price('900.00', regular: '1000.00'));

void main() {
  group('PriceTag', () {
    testWidgets('sale shows final, struck regular and discount badge', (t) async {
      await t.pumpWidget(wrapApp(PriceTag(price: _sale())));
      expect(find.text('QAR 900.00'), findsOneWidget);
      final regular = t.widget<Text>(find.text('QAR 1000.00'));
      expect(regular.style!.decoration, TextDecoration.lineThrough);
      expect(find.text('-10%'), findsOneWidget);
    });

    testWidgets('regular price only when not on sale', (t) async {
      await t.pumpWidget(wrapApp(PriceTag(price: EffectivePrice.fromJson(price('250.00')))));
      expect(find.text('QAR 250.00'), findsOneWidget);
      expect(find.byType(DiscountBadge), findsNothing);
    });

    testWidgets('renders server-formatted Arabic money untouched in RTL', (t) async {
      const ar = Money(amount: '1250.50', currency: 'QAR', formatted: '1,250.50 ر.ق');
      await t.pumpWidget(wrapApp(const MoneyText(ar), locale: _ar));
      expect(find.text('1,250.50 ر.ق'), findsOneWidget);
      expect(Directionality.of(t.element(find.byType(MoneyText))), TextDirection.rtl);
    });
  });

  group('QuantityStepper', () {
    testWidgets('increments, decrements and respects bounds', (t) async {
      var value = 1;
      await t.pumpWidget(
        wrapApp(
          StatefulBuilder(
            builder: (context, setState) =>
                QuantityStepper(value: value, max: 2, onChanged: (v) => setState(() => value = v)),
          ),
        ),
      );
      await t.tap(find.bySemanticsLabel('Decrease quantity'));
      await t.pump();
      expect(value, 1, reason: 'min reached');
      await t.tap(find.bySemanticsLabel('Increase quantity'));
      await t.pump();
      expect(value, 2);
      await t.tap(find.bySemanticsLabel('Increase quantity'));
      await t.pump();
      expect(value, 2, reason: 'max reached');
      expect(t.getSize(find.byType(InkResponse).first).height, greaterThanOrEqualTo(kMinTouchTarget));
    });

    testWidgets('RTL: increase button sits on the left', (t) async {
      await t.pumpWidget(wrapApp(QuantityStepper(value: 1, onChanged: (_) {}), locale: _ar));
      final inc = t.getCenter(find.bySemanticsLabel('زيادة الكمية'));
      final dec = t.getCenter(find.bySemanticsLabel('إنقاص الكمية'));
      expect(inc.dx, lessThan(dec.dx));
    });
  });

  group('RatingStars & RatingInput', () {
    testWidgets('shows half star and value, mirrored row in RTL', (t) async {
      await t.pumpWidget(wrapApp(const RatingStars(average: 3.5, count: 12), locale: _ar));
      expect(find.byIcon(Icons.star_rounded), findsNWidgets(3));
      expect(find.byIcon(Icons.star_half_rounded), findsOneWidget);
      expect(find.text('3.5 (12)'), findsOneWidget);
      final firstStar = t.getCenter(find.byIcon(Icons.star_rounded).first);
      final label = t.getCenter(find.text('3.5 (12)'));
      expect(firstStar.dx, greaterThan(label.dx), reason: 'stars start from the right in RTL');
      expect(Icons.star_half_rounded.matchTextDirection, isTrue);
    });

    testWidgets('input reports taps', (t) async {
      var rating = 0;
      await t.pumpWidget(
        wrapApp(
          StatefulBuilder(
            builder: (c, s) => RatingInput(value: rating, onChanged: (v) => s(() => rating = v)),
          ),
        ),
      );
      await t.tap(find.bySemanticsLabel('4 stars'));
      await t.pump();
      expect(rating, 4);
    });
  });

  group('AppButton', () {
    testWidgets('loading disables taps and shows progress', (t) async {
      var taps = 0;
      await t.pumpWidget(wrapApp(AppButton(label: 'Pay', loading: true, onPressed: () => taps++)));
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      await t.tap(find.byType(AppButton));
      expect(taps, 0);
    });

    testWidgets('meets the minimum touch target', (t) async {
      await t.pumpWidget(
        wrapApp(
          Center(
            child: AppButton(label: 'Go', expand: false, onPressed: () {}),
          ),
        ),
      );
      expect(t.getSize(find.byType(FilledButton)).height, greaterThanOrEqualTo(kMinTouchTarget));
    });
  });

  group('State views', () {
    testWidgets('offline error shows localized message and retry', (t) async {
      var retried = false;
      await t.pumpWidget(
        wrapApp(
          ErrorState(
            error: const ApiException(kind: ApiErrorKind.offline),
            onRetry: () => retried = true,
          ),
          locale: _ar,
        ),
      );
      expect(find.text(lookupAppLocalizations(_ar).errorOffline), findsOneWidget);
      await t.tap(find.text(lookupAppLocalizations(_ar).retry));
      expect(retried, isTrue);
    });

    testWidgets('server error shows request reference for support', (t) async {
      await t.pumpWidget(
        wrapApp(
          const ErrorState(
            error: ApiException(kind: ApiErrorKind.server, requestId: 'abc123'),
          ),
        ),
      );
      expect(find.textContaining('abc123'), findsOneWidget);
    });

    testWidgets('empty state with action', (t) async {
      var tapped = false;
      await t.pumpWidget(
        wrapApp(EmptyState(icon: Icons.inbox, title: 'Empty', actionLabel: 'Shop', onAction: () => tapped = true)),
      );
      await t.tap(find.text('Shop'));
      expect(tapped, isTrue);
    });
  });

  group('ProductCardView', () {
    final product = ProductSummary.fromJson({
      ...productCard('1', amount: '900.00'),
      'price': price('900.00', regular: '1000.00'),
      'badges': ['sale'],
    });

    testWidgets('shows brand, name, price and sale badge', (t) async {
      await t.pumpWidget(
        wrapApp(
          SizedBox(
            width: 180,
            child: ProductCardView(product: product, onTap: () {}),
          ),
        ),
      );
      await t.pump();
      expect(find.text('Nordic'), findsOneWidget);
      expect(find.text('Product 1'), findsOneWidget);
      expect(find.text('QAR 900.00'), findsOneWidget);
      expect(find.text('-10%'), findsOneWidget);
    });

    testWidgets('RTL places the badge at the top-right', (t) async {
      await t.pumpWidget(
        wrapApp(
          Center(
            child: SizedBox(width: 200, child: ProductCardView(product: product)),
          ),
          locale: _ar,
        ),
      );
      await t.pump();
      final card = t.getRect(find.byType(ProductCardView));
      final badge = t.getCenter(find.text('-10٪'));
      expect(badge.dx, greaterThan(card.center.dx));
    });
  });

  group('Misc widgets & utils', () {
    testWidgets('status chip and section header render', (t) async {
      await t.pumpWidget(
        wrapApp(
          Column(
            children: [
              const StatusChip(label: 'Ready', tone: StatusTone.success),
              SectionHeader(title: 'New', onAction: () {}),
            ],
          ),
        ),
      );
      expect(find.text('Ready'), findsOneWidget);
      expect(find.text('See all'), findsOneWidget);
      expect(toneForStatus('pickup_ready'), StatusTone.accent);
      expect(toneForStatus('cancelled'), StatusTone.danger);
    });

    testWidgets('countdown uses server offset', (t) async {
      final target = DateTime.now().add(const Duration(minutes: 10));
      await t.pumpWidget(wrapApp(CountdownText(target: target, serverOffset: const Duration(minutes: 5))));
      final text = t.widget<Text>(find.byType(Text)).data!;
      expect(text, anyOf(startsWith('04:'), startsWith('05:')));
    });

    test('Qatar phone normalisation (incl. Arabic digits)', () {
      expect(QatarPhone.toE164('5512 3456'), '+97455123456');
      expect(QatarPhone.toE164('+974 5512 3456'), '+97455123456');
      expect(QatarPhone.toE164('٥٥١٢٣٤٥٦'), '+97455123456');
      expect(QatarPhone.toE164('12345678'), isNull);
      expect(QatarPhone.display('+97455123456'), '+974 5512 3456');
    });

    test('Qatar time is UTC+3 regardless of device zone', () {
      final utc = DateTime.utc(2026, 11, 4, 21, 30);
      final q = QatarTime.toQatar(utc);
      expect(q.hour, 0);
      expect(q.day, 5);
      expect(QatarTime.isoDate(QatarTime.today(utc)), '2026-11-05');
      expect(formatCountdown(const Duration(days: 1, hours: 2, minutes: 3, seconds: 4)), '1d 02:03:04');
    });
  });
}
