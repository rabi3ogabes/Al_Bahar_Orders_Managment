// The small screens this feature adds, drawn in English and Arabic (right to left), light and dark, at text scale 1.3 and on a 320 px phone:
// the assembly tick, the delivery promise block, the "your cart changed" note and the WhatsApp share button.
import 'package:albahar_customer/core/models/money.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/cart/domain/guest_cart.dart';
import 'package:albahar_customer/features/cart/presentation/assembly_choice.dart';
import 'package:albahar_customer/features/cart/presentation/guest_cart_notice_banner.dart';
import 'package:albahar_customer/features/cart/presentation/state/guest_cart_notice.dart';
import 'package:albahar_customer/features/catalog/presentation/share_actions.dart';
import 'package:albahar_customer/features/catalog/presentation/state/catalog_providers.dart';
import 'package:albahar_customer/features/checkout/domain/delivery_promise.dart';
import 'package:albahar_customer/features/checkout/presentation/promise_note.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:share_plus/share_plus.dart';

import '../../qa/test_fonts.dart';

Money _m(String v) => Money(amount: v, currency: 'QAR', formatted: 'QAR $v');

Widget _host(
  Widget child, {
  Locale locale = const Locale('en'),
  bool dark = false,
  double scale = 1,
  double width = 390,
  List<Override> overrides = const [],
  ProviderContainer? container,
}) {
  final app = MaterialApp(
    locale: locale,
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: const [
      AppLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    theme: dark ? AppTheme.dark(locale) : AppTheme.light(locale),
    builder: (context, app) => MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(scale), size: Size(width, 800)),
      child: app!,
    ),
    home: Scaffold(
      body: Center(
        child: SizedBox(
          width: width,
          child: SingleChildScrollView(
            child: Padding(padding: const EdgeInsets.all(16), child: child),
          ),
        ),
      ),
    ),
  );
  return container == null
      ? ProviderScope(overrides: overrides, child: app)
      : UncontrolledProviderScope(container: container, child: app);
}

BasketPromise _scheduled({bool split = false, bool needsAddress = false, String? detail}) => BasketPromise.fromJson({
  'enabled': true,
  'fulfillment_type': 'delivery',
  'status': 'scheduled',
  'message': split ? 'All delivered by Tue 24 Nov' : 'Delivery today before 10 pm',
  'detail': detail,
  'is_split': split,
  'location_known': !needsAddress,
  'parcels': [
    {
      'branch': {'id': 'a', 'name': 'West Bay'},
      'type': 'delivery',
      'status': 'scheduled',
      'message': 'Delivery today',
    },
    if (split)
      {
        'branch': {'id': 'b', 'name': 'Al Wakra'},
        'type': 'delivery',
        'status': 'unconfirmed',
        'message': 'x',
      },
  ],
});

void main() {
  setUpAll(loadAppFonts);

  group('the assembly tick', () {
    testWidgets('unticked: the offer, the fee for one and the note; ticking calls back with true', (t) async {
      bool? changed;
      await t.pumpWidget(_host(AssemblyChoice(fee: _m('75.00'), selected: false, onChanged: (v) => changed = v)));
      expect(find.textContaining('Add assembly and installation', findRichText: true), findsOneWidget);
      expect(find.textContaining('+QAR 75.00 each', findRichText: true), findsOneWidget);
      expect(find.text('Done at your door. For delivery orders only.'), findsOneWidget);
      await t.tap(find.byType(CheckboxListTile));
      expect(changed, isTrue);
      expect(t.takeException(), isNull);
    });

    testWidgets('ticked: what the line adds is shown, and the tick can be taken off', (t) async {
      bool? changed;
      await t.pumpWidget(
        _host(AssemblyChoice(fee: _m('75.00'), selected: true, total: _m('225.00'), onChanged: (v) => changed = v)),
      );
      expect(find.text('QAR 225.00 for this item'), findsOneWidget);
      expect(tile(t).value, isTrue);
      await t.tap(find.byType(CheckboxListTile));
      expect(changed, isFalse);
    });

    testWidgets('while a change is on its way it cannot be tapped again', (t) async {
      await t.pumpWidget(_host(AssemblyChoice(fee: _m('75.00'), selected: false, onChanged: null)));
      expect(tile(t).onChanged, isNull);
    });

    testWidgets('the tap target is at least 48 dp tall, also at text scale 1.3 on a 320 px phone', (t) async {
      await t.pumpWidget(
        _host(AssemblyChoice(fee: _m('75.00'), selected: false, onChanged: (_) {}), scale: 1.3, width: 320),
      );
      expect(t.getSize(find.byType(CheckboxListTile)).height, greaterThanOrEqualTo(48));
      expect(t.takeException(), isNull, reason: 'no overflow');
    });

    testWidgets('Arabic, right to left: the words are Arabic and nothing overflows (light and dark)', (t) async {
      for (final dark in [false, true]) {
        await t.pumpWidget(
          _host(
            AssemblyChoice(fee: _m('75.00'), selected: true, total: _m('150.00'), onChanged: (_) {}),
            locale: const Locale('ar'),
            dark: dark,
            scale: 1.3,
            width: 320,
          ),
        );
        expect(find.textContaining('إضافة التركيب والتجهيز', findRichText: true), findsOneWidget);
        expect(find.textContaining('للقطعة', findRichText: true), findsOneWidget);
        expect(Directionality.of(t.element(find.byType(AssemblyChoice))), TextDirection.rtl);
        expect(t.takeException(), isNull);
      }
    });
  });

  group('the delivery promise block', () {
    testWidgets('a date, with its small print', (t) async {
      await t.pumpWidget(_host(PromiseNote(promise: _scheduled(detail: 'if you order by 3 pm'))));
      expect(find.text('Delivery today before 10 pm'), findsOneWidget);
      expect(find.text('if you order by 3 pm'), findsOneWidget);
      expect(find.text('The exact date depends on your address.'), findsNothing);
    });

    testWidgets('without an address it says the date depends on it', (t) async {
      await t.pumpWidget(_host(PromiseNote(promise: _scheduled(needsAddress: true))));
      expect(find.text('The exact date depends on your address.'), findsOneWidget);
    });

    testWidgets('a split order shows one row per branch and says "we will confirm" for the part with no date', (
      t,
    ) async {
      await t.pumpWidget(_host(PromiseNote(promise: _scheduled(split: true))));
      expect(find.text('All delivered by Tue 24 Nov'), findsOneWidget);
      expect(find.text('West Bay'), findsOneWidget);
      expect(find.text('Delivery today'), findsOneWidget);
      expect(find.text('Al Wakra'), findsOneWidget);
      expect(find.text('We will confirm your delivery date'), findsOneWidget);
    });

    testWidgets('a date that cannot be promised is calm, never an error, and still shows why', (t) async {
      final p = BasketPromise.fromJson({
        'enabled': true,
        'fulfillment_type': 'delivery',
        'status': 'unconfirmed',
        'message': 'We will confirm your delivery date',
        'detail': 'Not in stock right now.',
        'location_known': true,
      });
      await t.pumpWidget(_host(PromiseNote(promise: p)));
      expect(find.text('We will confirm your delivery date'), findsOneWidget);
      expect(find.text('Not in stock right now.'), findsOneWidget);
      expect(find.byIcon(Icons.event_available_outlined), findsOneWidget);
    });

    testWidgets('nothing while it is switched off or there is no answer; a placeholder while one is on its way', (
      t,
    ) async {
      await t.pumpWidget(_host(const PromiseNote(promise: null)));
      expect(find.byKey(const ValueKey('promise-note')), findsNothing);
      await t.pumpWidget(_host(PromiseNote(promise: BasketPromise.fromJson({'enabled': false, 'status': 'disabled'}))));
      expect(find.byKey(const ValueKey('promise-note')), findsNothing);
      await t.pumpWidget(_host(const PromiseNote(promise: null, loading: true)));
      expect(find.byType(Container), findsWidgets);
      expect(find.byKey(const ValueKey('promise-note')), findsNothing);
    });

    testWidgets('Arabic and dark, at text scale 1.3 on a 320 px phone: no overflow', (t) async {
      for (final dark in [false, true]) {
        await t.pumpWidget(
          _host(
            PromiseNote(promise: _scheduled(split: true, needsAddress: true, detail: 'إذا طلبت قبل 3 م')),
            locale: const Locale('ar'),
            dark: dark,
            scale: 1.3,
            width: 320,
          ),
        );
        expect(find.byKey(const ValueKey('promise-note')), findsOneWidget);
        expect(Directionality.of(t.element(find.byKey(const ValueKey('promise-note')))), TextDirection.rtl);
        expect(t.takeException(), isNull);
      }
    });
  });

  group('your cart changed', () {
    testWidgets('each change is said in plain words, once, and OK clears it', (t) async {
      final container = ProviderContainer();
      addTearDown(container.dispose);
      container.read(guestCartNoticeProvider.notifier).report([
        GuestCartChange(GuestChangeKind.priceChanged, 'Oslo sofa', from: _m('1000.00'), to: _m('950.00')),
        const GuestCartChange(GuestChangeKind.reduced, 'Oak stool', available: 2),
        const GuestCartChange(GuestChangeKind.unavailable, 'Brass lamp'),
        const GuestCartChange(GuestChangeKind.assemblyRemoved, 'Corner sofa'),
      ]);
      await t.pumpWidget(_host(const GuestCartNoticeBanner(), container: container));
      await t.pump();

      expect(find.text('Your cart changed'), findsOneWidget);
      expect(find.text('The price of Oslo sofa changed from QAR 1000.00 to QAR 950.00.'), findsOneWidget);
      expect(find.text('Only 2 of Oak stool are available now, so the quantity was lowered.'), findsOneWidget);
      expect(find.text('Brass lamp is no longer available.'), findsOneWidget);
      expect(find.text('Assembly is no longer offered for Corner sofa.'), findsOneWidget);

      await t.tap(find.text('OK'));
      await t.pump();
      expect(container.read(guestCartNoticeProvider), isEmpty);
      expect(find.byKey(const ValueKey('guest-cart-notice')), findsNothing);
    });

    testWidgets('nothing changed: nothing shown', (t) async {
      await t.pumpWidget(_host(const GuestCartNoticeBanner()));
      expect(find.byKey(const ValueKey('guest-cart-notice')), findsNothing);
    });

    testWidgets('Arabic: right to left, no overflow at text scale 1.3', (t) async {
      final container = ProviderContainer();
      addTearDown(container.dispose);
      container.read(guestCartNoticeProvider.notifier).report([
        const GuestCartChange(GuestChangeKind.reduced, 'كنب أوسلو', available: 2),
      ]);
      await t.pumpWidget(
        _host(const GuestCartNoticeBanner(), locale: const Locale('ar'), scale: 1.3, width: 320, container: container),
      );
      await t.pump();
      expect(find.text('تغيّرت سلتك'), findsOneWidget);
      expect(find.text('المتوفر الآن 2 فقط من كنب أوسلو، لذلك خُفضت الكمية.'), findsOneWidget);
      expect(t.takeException(), isNull);
    });
  });

  group('Share on WhatsApp', () {
    testWidgets('opens WhatsApp\'s share link with the whole text, no phone number in it', (t) async {
      final opened = <Uri>[];
      await t.pumpWidget(
        _host(
          const WhatsAppShareButton(text: 'Take a look:\n\n*Oslo sofa*\n\nhttps://albahar.qa/products/oslo-sofa'),
          overrides: [
            externalLinkProvider.overrideWithValue((uri) async {
              opened.add(uri);
              return true;
            }),
          ],
        ),
      );
      expect(find.text('Share on WhatsApp'), findsOneWidget);
      await t.tap(find.byKey(const ValueKey('share-whatsapp')));
      await t.pump();
      expect(opened.single.host, 'wa.me');
      expect(opened.single.path, '/');
      expect(
        opened.single.queryParameters['text'],
        'Take a look:\n\n*Oslo sofa*\n\nhttps://albahar.qa/products/oslo-sofa',
      );
      expect(opened.single.queryParameters.keys, ['text']);
    });

    testWidgets(
      'when WhatsApp cannot be opened the customer is told and the system share sheet opens with the same text',
      (t) async {
        final shared = <ShareParams>[];
        await t.pumpWidget(
          _host(
            const WhatsAppShareButton(text: 'Hello', subject: 'Oslo sofa'),
            overrides: [
              externalLinkProvider.overrideWithValue((uri) async => false),
              shareActionProvider.overrideWithValue((params) async => shared.add(params)),
            ],
          ),
        );
        await t.tap(find.byKey(const ValueKey('share-whatsapp')));
        await t.pump();
        await t.pump();
        expect(find.text('WhatsApp could not be opened. Pick another way to share.'), findsOneWidget);
        expect(shared.single.text, 'Hello');
        expect(shared.single.subject, 'Oslo sofa');
      },
    );

    testWidgets('a launcher that throws is the same as one that fails', (t) async {
      final shared = <ShareParams>[];
      await t.pumpWidget(
        _host(
          const WhatsAppShareButton(text: 'Hello'),
          overrides: [
            externalLinkProvider.overrideWithValue((uri) async => throw StateError('no app')),
            shareActionProvider.overrideWithValue((params) async => shared.add(params)),
          ],
        ),
      );
      await t.tap(find.byKey(const ValueKey('share-whatsapp')));
      await t.pump();
      await t.pump();
      expect(shared, hasLength(1));
    });

    testWidgets('the button is 48 dp tall, reads in Arabic, and fits at text scale 1.3', (t) async {
      await t.pumpWidget(
        _host(const WhatsAppShareButton(text: 'x'), locale: const Locale('ar'), scale: 1.3, width: 320),
      );
      expect(find.text('مشاركة عبر واتساب'), findsOneWidget);
      expect(t.getSize(find.byKey(const ValueKey('share-whatsapp'))).height, greaterThanOrEqualTo(48));
      expect(t.takeException(), isNull);
    });
  });
}

/// The checkbox row inside the assembly tick.
CheckboxListTile tile(WidgetTester t) => t.widget<CheckboxListTile>(find.byType(CheckboxListTile));
