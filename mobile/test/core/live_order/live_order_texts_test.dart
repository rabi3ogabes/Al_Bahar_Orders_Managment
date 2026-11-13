import 'dart:ui' show Locale;

import 'package:albahar_customer/core/live_order/live_order.dart';
import 'package:albahar_customer/core/live_order/live_order_texts.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

/// intl writes "9:20\u202fPM" (narrow no-break space); compare with a plain space.
String plain(String? s) => (s ?? '').replaceAll('\u202f', ' ');

LiveOrder order(Map<String, dynamic> data) => LiveOrder.tryParse({'order_id': 'o1', 'number': 'A-100', ...data})!;

// 21:20 and 21:35 in Doha (UTC+3) are 18:20 and 18:35 UTC.
final _from = DateTime.utc(2026, 11, 13, 18, 20);
final _to = DateTime.utc(2026, 11, 13, 18, 35);

void main() {
  setUpAll(() => Future.wait([initializeDateFormatting('ar'), initializeDateFormatting('en')]));

  group('English', () {
    final t = LiveOrderTexts.forLocale('en');

    test('status line per phase', () {
      expect(t.status(order({'phase': 'placed'})), 'Your order has been placed');
      expect(t.status(order({'phase': 'preparing'})), 'We are preparing your order');
      expect(t.status(order({'phase': 'on_the_way'})), 'Your order is on the way');
      expect(t.status(order({'phase': 'ready'})), 'Your order is ready for pickup');
      expect(t.status(order({'phase': 'delivered'})), 'Your order has been delivered');
      expect(t.status(order({'phase': 'collected'})), 'Thanks for picking up your order');
      expect(t.status(order({'phase': 'cancelled'})), 'Your order was cancelled');
      expect(t.status(order({'phase': 'failed'})), 'We could not deliver your order');
    });

    test('headline: the estimate as a window in Doha time', () {
      final o = order({'phase': 'placed'}).copyWith(etaFrom: _from, etaTo: _to);
      expect(plain(t.etaWindow(o)), 'Arrives at 9:20 PM – 9:35 PM');
      expect(plain(t.headline(o)), 'Arrives at 9:20 PM – 9:35 PM');
    });

    test('headline: one estimate, or two that read the same, is a single time', () {
      expect(plain(t.etaWindow(order({'phase': 'placed'}).copyWith(etaTo: _to))), 'Arrives at 9:35 PM');
      expect(plain(t.etaWindow(order({'phase': 'placed'}).copyWith(etaFrom: _from))), 'Arrives at 9:20 PM');
      expect(
        plain(
          t.etaWindow(order({'phase': 'placed'}).copyWith(etaFrom: _to, etaTo: _to.add(const Duration(seconds: 20)))),
        ),
        'Arrives at 9:35 PM',
      );
      expect(t.etaWindow(order({'phase': 'placed'})), isNull);
    });

    test('headline: a window given backwards is put in order', () {
      expect(
        plain(t.etaWindow(order({'phase': 'placed'}).copyWith(etaFrom: _to, etaTo: _from))),
        'Arrives at 9:20 PM – 9:35 PM',
      );
    });

    test(
      'headline: the server headline wins, then its window, then the one worked out here, then the order number',
      () {
        // The server's title is the finished headline; eta_label is only the window.
        expect(
          t.headline(order({'phase': 'preparing', 'title': 'Arrives at 6:35pm-6:55pm', 'eta_label': '6:35pm-6:55pm'})),
          'Arrives at 6:35pm-6:55pm',
        );
        expect(
          t.headline(order({'phase': 'preparing', 'eta_label': 'Arrives at 9:20pm-9:35pm'}).copyWith(etaTo: _to)),
          'Arrives at 9:20pm-9:35pm',
        );
        expect(plain(t.headline(order({'phase': 'preparing'}).copyWith(etaTo: _to))), 'Arrives at 9:35 PM');
        expect(t.headline(order({'phase': 'preparing', 'title': 'Order #A-100'})), 'Order #A-100');
        expect(t.headline(order({'phase': 'preparing'})), 'Order #A-100');
      },
    );

    test('headline of a finished order says when it finished', () {
      expect(
        plain(t.headline(order({'phase': 'delivered', 'updated_at': '2026-11-13T18:31:00Z', 'eta_label': 'ignored'}))),
        'Delivered at 9:31 PM',
      );
      expect(
        plain(t.headline(order({'phase': 'collected', 'updated_at': '2026-11-13T18:31:00Z'}))),
        'Collected at 9:31 PM',
      );
      expect(plain(t.headline(order({'phase': 'delivered'}), now: _to)), 'Delivered at 9:35 PM');
      expect(t.headline(order({'phase': 'cancelled'})), 'Order cancelled');
      expect(t.headline(order({'phase': 'failed'})), 'Delivery failed');
    });

    test('the status line prefers the server body', () {
      expect(
        t.subtitle(order({'phase': 'preparing', 'body': 'Order #A-100 is being prepared'})),
        'Order #A-100 is being prepared',
      );
      expect(t.subtitle(order({'phase': 'preparing'})), 'We are preparing your order');
    });

    test('localise fills only what is missing', () {
      final filled = t.localise(order({'phase': 'on_the_way', 'body': 'From the server'}).copyWith(etaTo: _to));
      expect(plain(filled.headline), 'Arrives at 9:35 PM');
      expect(filled.subtitle, 'From the server');
      final kept = t.localise(order({'phase': 'on_the_way'}).copyWith(headline: 'Mine', subtitle: 'Mine too'));
      expect(kept.headline, 'Mine');
      expect(kept.subtitle, 'Mine too');
    });

    test('stage names', () {
      expect(t.stage(order({'phase': 'preparing'})), 'Preparing');
      expect(t.stage(order({'phase': 'ready'})), 'Ready');
      expect(t.stage(order({'phase': 'collected'})), 'Collected');
    });
  });

  group('Arabic', () {
    final t = LiveOrderTexts.forLocale('ar');

    test('status lines', () {
      expect(t.status(order({'phase': 'placed'})), 'تم تقديم طلبك');
      expect(t.status(order({'phase': 'on_the_way'})), 'طلبك في الطريق إليك');
      expect(t.status(order({'phase': 'failed'})), 'تعذّر توصيل طلبك');
    });

    test('the estimate uses the Arabic day period and, like the rest of the app, Western digits', () {
      final o = order({'phase': 'placed'}).copyWith(etaFrom: _from, etaTo: _to);
      final label = plain(t.etaWindow(o));
      expect(label, startsWith('الوصول بين'));
      expect(label, contains('9:20'));
      expect(label, contains('9:35'));
      expect(label, contains('م'));
      expect(plain(t.etaWindow(order({'phase': 'placed'}).copyWith(etaTo: _to))), startsWith('الوصول الساعة'));
    });

    test('Arabic-Indic digits are an option', () {
      final indic = LiveOrderTexts.forLocale('ar', arabicDigits: true);
      final o = order({'phase': 'placed'}).copyWith(etaFrom: _from, etaTo: _to);
      expect(plain(indic.etaWindow(o)), contains('٩:٢٠'));
      expect(plain(indic.etaWindow(o)), contains('٩:٣٥'));
      expect(indic.headline(order({'phase': 'delivered', 'updated_at': '2026-11-13T18:31:00Z'})), contains('٩:٣١'));
      // The option only concerns Arabic.
      expect(plain(LiveOrderTexts.forLocale('en', arabicDigits: true).time(_from)), '9:20 PM');
    });

    test('finished and numbered headlines', () {
      expect(
        t.headline(order({'phase': 'delivered', 'updated_at': '2026-11-13T18:31:00Z'})),
        startsWith('تم التوصيل الساعة'),
      );
      expect(t.headline(order({'phase': 'preparing'})), 'الطلب رقم A-100');
      expect(t.headline(order({'phase': 'cancelled'})), 'تم إلغاء الطلب');
    });
  });

  test('a time label never throws, even for a locale whose date formats are not loaded', () {
    final unloaded = LiveOrderTexts(lookupAppLocalizations(const Locale('en')), 'fr');
    expect(unloaded.time(_from), '21:20');
  });
}
