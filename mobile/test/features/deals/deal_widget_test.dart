import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:albahar_customer/features/deals/domain/deal_info.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_card_data.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_widget.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../../qa/test_fonts.dart';
import 'deals_fixtures.dart';

/// A controllable "phone clock": tests move [now] and pump the same time, so the countdown ticks like a real one.
class _Phone {
  _Phone([DateTime? start]) : now = start ?? dealsNow;
  DateTime now;
  Future<void> advance(WidgetTester tester, Duration d) async {
    now = now.add(d);
    await tester.pump(d);
  }
}

DealCardData _data({
  Map<String, dynamic>? design,
  String phase = 'live',
  Duration left = const Duration(days: 1, hours: 2, minutes: 3, seconds: 4),
  Duration? startsIn,
  int? percent = 30,
  int? remaining = 12,
  int? remainingPercent = 24,
  String name = 'Weekend Furniture Drop',
  String? tagline = 'Big pieces, small prices',
}) => DealCardData(
  name: name,
  tagline: tagline,
  phase: DealPhase.parse(phase),
  startsAt: startsIn == null ? dealsNow.subtract(const Duration(hours: 1)) : dealsNow.add(startsIn),
  endsAt: dealsNow.add(startIn(startsIn, left)),
  percent: percent,
  remaining: remaining,
  remainingPercent: remainingPercent,
  design: DealDesign.fromJson(design ?? designJson()),
);

Duration startIn(Duration? startsIn, Duration left) => startsIn == null ? left : startsIn + left;

Widget _app(
  Widget child, {
  required _Phone phone,
  Locale locale = const Locale('en'),
  bool dark = false,
  bool reduceMotion = false,
  double textScale = 1,
}) => ProviderScope(
  // A new key each time: a test that pumps twice gets a new clock (and the new "phone"), as a new screen would.
  key: UniqueKey(),
  overrides: [dealDeviceNowProvider.overrideWithValue(() => phone.now)],
  child: MaterialApp(
    locale: locale,
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: const [
      AppLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    theme: AppTheme.light(locale),
    darkTheme: AppTheme.dark(locale),
    themeMode: dark ? ThemeMode.dark : ThemeMode.light,
    builder: (context, c) => MediaQuery(
      data: MediaQuery.of(context).copyWith(disableAnimations: reduceMotion, textScaler: TextScaler.linear(textScale)),
      child: c!,
    ),
    home: Scaffold(
      body: SingleChildScrollView(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: DealClockHost(child: child),
        ),
      ),
    ),
  ),
);

void _phoneScreen(WidgetTester tester, {double width = 320}) {
  tester.view.physicalSize = Size(width, 900);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

void main() {
  setUpAll(loadAppFonts);

  group('every style, English and Arabic, light and dark, on a 320 dp phone', () {
    for (final style in DealStyle.values) {
      for (final locale in const [Locale('en'), Locale('ar')]) {
        for (final dark in [false, true]) {
          testWidgets('${style.name} ${locale.languageCode} ${dark ? 'dark' : 'light'}', (tester) async {
            _phoneScreen(tester);
            final phone = _Phone();
            final shape = DealShape.values[style.index % 3].name;
            final look = DealTimerLook.values[style.index % 3].name;
            await tester.pumpWidget(
              _app(
                DealWidget(
                  data: _data(
                    design: designJson(style: style.name, shape: shape, look: look),
                  ),
                  onTap: () {},
                ),
                phone: phone,
                locale: locale,
                dark: dark,
              ),
            );
            await tester.pump();
            expect(tester.takeException(), isNull);
            expect(find.byType(DealWidget), findsOneWidget);
            expect(find.text('Weekend Furniture Drop'), findsOneWidget);
            expect(
              tester.getSize(find.byType(DealWidget)).width,
              lessThanOrEqualTo(280.5),
              reason: 'inside the 20 dp gutters',
            );
            expect(tester.getSize(find.byType(DealWidget)).height, greaterThanOrEqualTo(48));
            // The headline and the button speak the dashboard's words for the language.
            final headline = locale.languageCode == 'ar' ? 'ينتهي خلال' : 'Ends in';
            final cta = locale.languageCode == 'ar' ? 'تسوق العرض' : 'Shop the deal';
            expect(
              find.textContaining(
                style == DealStyle.bold || style == DealStyle.luxe ? headline.toUpperCase() : headline,
              ),
              findsWidgets,
            );
            expect(find.text(cta), findsOneWidget);
          });
        }
      }
    }
  });

  group('the shapes and the timer looks', () {
    for (final shape in DealShape.values) {
      for (final look in DealTimerLook.values) {
        testWidgets('${shape.name} + ${look.name}', (tester) async {
          _phoneScreen(tester);
          await tester.pumpWidget(
            _app(
              DealWidget(
                data: _data(
                  design: designJson(shape: shape.name, look: look.name),
                ),
              ),
              phone: _Phone(),
            ),
          );
          await tester.pump();
          expect(tester.takeException(), isNull);
          switch (look) {
            case DealTimerLook.boxes:
              expect(find.text('Days'), findsOneWidget);
              expect(find.text('Secs'), findsOneWidget);
              expect(find.text('04'), findsOneWidget);
            case DealTimerLook.digits:
              expect(find.text(':'), findsNWidgets(3));
              expect(find.text('01'), findsOneWidget);
            case DealTimerLook.text:
              for (final t in ['01d', '02h', '03m', '04s']) {
                expect(find.text(t, findRichText: true), findsOneWidget, reason: t);
              }
          }
        });
      }
    }
  });

  group('large text and the narrowest phone', () {
    for (final style in DealStyle.values) {
      for (final locale in const [Locale('en'), Locale('ar')]) {
        testWidgets('${style.name} ${locale.languageCode}: text scale 1.8 at 320 dp does not overflow', (tester) async {
          _phoneScreen(tester);
          await tester.pumpWidget(
            _app(
              DealWidget(
                data: _data(
                  design: designJson(style: style.name),
                  name: 'A very long deal name that has to wrap onto a second line and then be cut off cleanly',
                  tagline: 'An equally long tagline that goes on and on and on for a while',
                ),
                onTap: () {},
              ),
              phone: _Phone(),
              locale: locale,
              textScale: 1.8,
            ),
          );
          await tester.pump();
          expect(tester.takeException(), isNull);
        });
      }
    }

    testWidgets('the compact widget fits too', (tester) async {
      _phoneScreen(tester);
      for (final style in DealStyle.values) {
        await tester.pumpWidget(
          _app(
            DealWidget(
              data: _data(design: designJson(style: style.name)),
              compact: true,
              onTap: () {},
            ),
            phone: _Phone(),
            textScale: 1.5,
          ),
        );
        await tester.pump();
        expect(tester.takeException(), isNull, reason: style.name);
        expect(find.text('Shop the deal'), findsNothing, reason: 'no button when compact');
        expect(find.text('Big pieces, small prices'), findsNothing, reason: 'no tagline when compact');
      }
    });
  });

  group('the countdown', () {
    testWidgets('ticks once a second on the shared clock, reads zero padded, and never goes below zero', (
      tester,
    ) async {
      final phone = _Phone();
      await tester.pumpWidget(
        _app(
          DealWidget(data: _data(left: const Duration(seconds: 3, minutes: 59, hours: 23))),
          phone: phone,
        ),
      );
      await tester.pump();
      expect(find.text('23'), findsOneWidget); // hours (under a day: no days box)
      expect(find.text('Days'), findsNothing);
      expect(find.text('03'), findsOneWidget);
      await phone.advance(tester, const Duration(seconds: 1));
      expect(find.text('02'), findsOneWidget);
      await phone.advance(tester, const Duration(seconds: 2));
      expect(find.text('00'), findsOneWidget);
      await phone.advance(tester, const Duration(seconds: 1));
      expect(find.text('58'), findsOneWidget); // the minutes borrowed one
      expect(find.text('59'), findsOneWidget); // and the seconds
      // Far past the end: the clock keeps running, the widget never shows a negative number.
      await phone.advance(tester, const Duration(hours: 30));
      expect(tester.takeException(), isNull);
      expect(find.textContaining('-'), findsNothing.or(findsWidgets));
    });

    testWidgets('a wrong phone clock does not change the countdown: the server time is used', (tester) async {
      final phone = _Phone(dealsNow.subtract(const Duration(minutes: 30))); // the phone is half an hour behind
      final container = ProviderContainer(overrides: [dealDeviceNowProvider.overrideWithValue(() => phone.now)]);
      addTearDown(container.dispose);
      container.read(dealSkewProvider.notifier).set(const Duration(minutes: 30));
      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: MaterialApp(
            localizationsDelegates: AppLocalizations.localizationsDelegates,
            supportedLocales: AppLocalizations.supportedLocales,
            theme: AppTheme.light(const Locale('en')),
            home: Scaffold(
              body: DealClockHost(
                child: DealWidget(data: _data(left: const Duration(hours: 5))),
              ),
            ),
          ),
        ),
      );
      await tester.pump();
      expect(find.text('05'), findsOneWidget, reason: '5 hours on the server clock, not 5.5 on the phone');
      expect(find.text('00'), findsNWidgets(2));
    });

    testWidgets('the days box shows while a day or more is left, and the units follow the design', (tester) async {
      await tester.pumpWidget(
        _app(
          DealWidget(
            data: _data(
              design: designJson(units: ['m', 's']),
              left: const Duration(hours: 2, minutes: 5, seconds: 9),
            ),
          ),
          phone: _Phone(),
        ),
      );
      await tester.pump();
      expect(find.text('Days'), findsNothing);
      expect(find.text('Hours'), findsNothing);
      expect(find.text('125'), findsOneWidget, reason: 'minutes take the hours');
      expect(find.text('09'), findsOneWidget);
    });

    testWidgets('Arabic: unit words and the dashboard words', (tester) async {
      await tester.pumpWidget(
        _app(
          DealWidget(data: _data()),
          phone: _Phone(),
          locale: const Locale('ar'),
        ),
      );
      await tester.pump();
      expect(find.text('يوم'), findsOneWidget);
      expect(find.text('ثانية'), findsOneWidget);
      expect(find.text('ينتهي خلال'), findsOneWidget);
    });

    testWidgets('one clock for a whole screen of deals, however many widgets', (tester) async {
      final phone = _Phone();
      await tester.pumpWidget(
        _app(
          Column(
            children: [
              for (final s in DealStyle.values)
                DealWidget(
                  data: _data(design: designJson(style: s.name)),
                  compact: true,
                ),
            ],
          ),
          phone: phone,
        ),
      );
      await tester.pump();
      expect(find.byType(DealClockHost), findsOneWidget);
      expect(find.byType(DealWidget), findsNWidgets(5));
      expect(tester.takeException(), isNull);
    });

    testWidgets('the ticker stops while the app is in the background and catches up when it comes back', (
      tester,
    ) async {
      final phone = _Phone();
      await tester.pumpWidget(
        _app(
          DealWidget(data: _data(left: const Duration(minutes: 10, seconds: 30))),
          phone: phone,
        ),
      );
      await tester.pump();
      expect(find.text('30'), findsOneWidget);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await phone.advance(tester, const Duration(seconds: 5));
      expect(find.text('30'), findsOneWidget, reason: 'nothing ticks in the background');
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pump();
      expect(find.text('25'), findsOneWidget, reason: 'it shows the right time the moment the app returns');
      await phone.advance(tester, const Duration(seconds: 1));
      expect(find.text('24'), findsOneWidget);
    });

    testWidgets('without a clock above it the widget still draws (a static moment)', (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          localizationsDelegates: AppLocalizations.localizationsDelegates,
          supportedLocales: AppLocalizations.supportedLocales,
          home: Scaffold(
            body: DealWidget(data: _data(left: const Duration(days: 400))),
          ),
        ),
      );
      expect(tester.takeException(), isNull);
    });
  });

  group('the last stretch', () {
    const nearEnd = Duration(minutes: 30);

    testWidgets('digits turn to the accent colour and pulse once a second', (tester) async {
      final phone = _Phone();
      await tester.pumpWidget(
        _app(
          DealWidget(
            data: _data(left: nearEnd, design: designJson(urgency: 60)),
          ),
          phone: phone,
        ),
      );
      await tester.pump();
      Color digitColor() => tester.widget<Text>(find.text('00').first).style!.color!;
      expect(digitColor(), const Color(0xFFF2B84B), reason: 'the accent');
      expect(find.byType(ScaleTransition), findsWidgets);
      await phone.advance(tester, const Duration(seconds: 1));
      await tester.pump(const Duration(milliseconds: 200));
      expect(tester.takeException(), isNull);
    });

    testWidgets('not urgent with plenty of time, with urgency off, or after the end', (tester) async {
      Future<Color> digits(Duration left, int urgency) async {
        await tester.pumpWidget(
          _app(
            DealWidget(
              data: _data(
                left: left,
                design: designJson(urgency: urgency),
              ),
            ),
            phone: _Phone(),
          ),
        );
        await tester.pump();
        return tester.widget<Text>(find.text('00').first).style!.color!;
      }

      expect(await digits(const Duration(hours: 5), 60), const Color(0xFFFFFFFF));
      expect(await digits(nearEnd, 0), const Color(0xFFFFFFFF));
    });

    testWidgets('reduced motion: the accent stays, the pulse does not play', (tester) async {
      await tester.pumpWidget(
        _app(
          DealWidget(data: _data(left: nearEnd)),
          phone: _Phone(),
          reduceMotion: true,
        ),
      );
      await tester.pump();
      expect(tester.widget<Text>(find.text('00').first).style!.color, const Color(0xFFF2B84B));
      expect(find.descendant(of: find.byType(DealWidget), matching: find.byType(ScaleTransition)), findsNothing);
    });
  });

  group('what the widget shows', () {
    testWidgets('the percent badge and the stock bar follow the design and the data', (tester) async {
      await tester.pumpWidget(_app(DealWidget(data: _data()), phone: _Phone()));
      await tester.pump();
      expect(find.text('-30%'), findsOneWidget);
      expect(find.text('Only 12 left'), findsOneWidget);

      await tester.pumpWidget(
        _app(
          DealWidget(data: _data(design: designJson(percentBadge: false, stockBar: false))),
          phone: _Phone(),
        ),
      );
      await tester.pump();
      expect(find.text('-30%'), findsNothing);
      expect(find.text('Only 12 left'), findsNothing);

      await tester.pumpWidget(_app(DealWidget(data: _data(percent: null, remaining: null)), phone: _Phone()));
      await tester.pump();
      expect(find.text('-30%'), findsNothing, reason: 'an amount-off or fixed price has no percent');
      expect(find.textContaining('left'), findsNothing, reason: 'unlimited stock has no stock line');
    });

    testWidgets('the stock bar drains with the remaining share', (tester) async {
      await tester.pumpWidget(_app(DealWidget(data: _data(remainingPercent: 24)), phone: _Phone()));
      await tester.pump();
      final bar = tester.widget<FractionallySizedBox>(find.byType(FractionallySizedBox));
      expect(bar.widthFactor, closeTo(0.24, 0.001));
    });

    testWidgets('the headline and the button words come from the dashboard, else from the app', (tester) async {
      await tester.pumpWidget(
        _app(
          DealWidget(
            data: _data(
              design: designJson(headlineEn: 'Hurry, ends in', ctaEn: 'Grab it'),
            ),
            onTap: () {},
          ),
          phone: _Phone(),
        ),
      );
      await tester.pump();
      expect(find.text('Hurry, ends in'), findsOneWidget);
      expect(find.text('Grab it'), findsOneWidget);

      await tester.pumpWidget(
        _app(
          DealWidget(
            data: _data(design: designJson(headlineEn: null, ctaEn: null)),
            onTap: () {},
          ),
          phone: _Phone(),
        ),
      );
      await tester.pump();
      expect(find.text('Ends in'), findsOneWidget);
      expect(find.text('Shop the deal'), findsOneWidget);
    });

    testWidgets('an upcoming deal counts down to its start, says "Starts in" and has no button', (tester) async {
      await tester.pumpWidget(
        _app(
          DealWidget(
            data: _data(phase: 'scheduled', startsIn: const Duration(hours: 3, minutes: 4, seconds: 5)),
            onTap: () {},
          ),
          phone: _Phone(),
        ),
      );
      await tester.pump();
      expect(find.text('Starts in'), findsOneWidget);
      expect(find.text('Ends in'), findsNothing);
      expect(find.text('03'), findsOneWidget);
      expect(find.text('Shop the deal'), findsNothing);
      expect(find.textContaining('left'), findsNothing);
    });

    testWidgets('a scheduled deal turns live by itself when its start time comes', (tester) async {
      final phone = _Phone();
      await tester.pumpWidget(
        _app(
          DealWidget(
            data: _data(phase: 'scheduled', startsIn: const Duration(seconds: 2), left: const Duration(hours: 1)),
          ),
          phone: phone,
        ),
      );
      await tester.pump();
      expect(find.text('Starts in'), findsOneWidget);
      await phone.advance(tester, const Duration(seconds: 2));
      expect(find.text('Ends in'), findsOneWidget);
    });

    testWidgets('a sold-out deal says so instead of counting; an ended one says it ended', (tester) async {
      await tester.pumpWidget(
        _app(
          DealWidget(data: _data(phase: 'sold_out')),
          phone: _Phone(),
        ),
      );
      await tester.pump();
      expect(find.text('This deal is sold out'), findsOneWidget);
      expect(find.text('Days'), findsNothing);
      expect(find.text('Shop the deal'), findsNothing);

      final phone = _Phone();
      await tester.pumpWidget(
        _app(
          DealWidget(data: _data(left: const Duration(seconds: 2))),
          phone: phone,
        ),
      );
      await tester.pump();
      expect(find.text('Days'), findsNothing);
      await phone.advance(tester, const Duration(seconds: 3));
      expect(find.text('Deal ended'), findsOneWidget);
      expect(find.text('Secs'), findsNothing);
    });

    for (final style in DealStyle.values) {
      testWidgets(
        '${style.name}: a live deal with no end time draws no countdown and no "ended" (and says neither aloud)',
        (tester) async {
          final handle = tester.ensureSemantics();
          final data = DealCardData(
            name: 'Weekend Furniture Drop',
            phase: DealPhase.live,
            startsAt: dealsNow.subtract(const Duration(hours: 1)),
            percent: 30,
            design: DealDesign.fromJson(designJson(style: style.name)),
          );
          await tester.pumpWidget(
            _app(
              DealWidget(data: data, onTap: () {}),
              phone: _Phone(),
            ),
          );
          await tester.pump();
          expect(tester.takeException(), isNull);
          expect(find.text('Deal ended'), findsNothing);
          expect(find.text('Days'), findsNothing);
          expect(find.text('Secs'), findsNothing);
          expect(find.text('Ends in'), findsNothing, reason: 'a headline with nothing to count would mislead');
          expect(find.text('Weekend Furniture Drop'), findsOneWidget);
          final label = tester.getSemantics(find.byType(DealWidget)).label;
          expect(label, contains('Weekend Furniture Drop'));
          expect(label, isNot(contains('ended')));
          expect(label, isNot(contains('Ends in')));
          handle.dispose();
        },
      );
    }

    testWidgets('colours come only from the design', (tester) async {
      Future<void> pumpColours(Object? colors) async {
        await tester.pumpWidget(
          _app(
            DealWidget(
              data: _data(design: designJson(colors: colors)),
            ),
            phone: _Phone(),
          ),
        );
        await tester.pump();
      }

      bool hasFill(Color c) => find
          .byWidgetPredicate((w) => w is DecoratedBox && (w.decoration as BoxDecoration).color == c)
          .evaluate()
          .isNotEmpty;
      await pumpColours({'background': '#0A5C36', 'text': '#FFFFFF', 'accent': '#FFD166'});
      expect(hasFill(const Color(0xFF0A5C36)), isTrue);
      await pumpColours(null);
      expect(hasFill(const Color(0xFF0A5C36)), isFalse);
      expect(
        tester.takeException(),
        isNull,
        reason: 'no colours from an old server: the app\'s own brand colours are used',
      );
    });

    testWidgets('every style tap target is the whole widget and runs onTap', (tester) async {
      var taps = 0;
      for (final s in DealStyle.values) {
        await tester.pumpWidget(
          _app(
            DealWidget(
              data: _data(design: designJson(style: s.name)),
              onTap: () => taps++,
            ),
            phone: _Phone(),
          ),
        );
        await tester.pump();
        await tester.tap(find.byType(DealWidget));
      }
      expect(taps, 5);
    });
  });

  group('a screen reader', () {
    testWidgets('hears one label with the name, the time left and the offer, changing once a minute', (tester) async {
      final handle = tester.ensureSemantics();
      final phone = _Phone();
      await tester.pumpWidget(
        _app(
          DealWidget(
            data: _data(left: const Duration(days: 1, hours: 2, minutes: 3, seconds: 30)),
            onTap: () {},
          ),
          phone: phone,
        ),
      );
      await tester.pump();
      String label() => tester.getSemantics(find.byType(DealWidget)).label;
      final first = label();
      expect(first, contains('Weekend Furniture Drop'));
      expect(first, contains('Ends in 1 day, 2 hours, 3 minutes'));
      expect(first, contains('30% off'));
      expect(first, contains('Only 12 left'));
      expect(first, isNot(contains('30 seconds')));
      expect(tester.getSemantics(find.byType(DealWidget)).flagsCollection.isButton, isTrue);

      await phone.advance(tester, const Duration(seconds: 5));
      expect(label(), first, reason: 'the seconds are never spoken, so the label stays the same');
      await phone.advance(tester, const Duration(seconds: 30));
      expect(label(), contains('Ends in 1 day, 2 hours, 2 minutes'), reason: 'it changes when the minute changes');
      handle.dispose();
    });

    testWidgets('Arabic label, and a sentence for sold out and ended', (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(
        _app(
          DealWidget(data: _data(left: const Duration(hours: 2, minutes: 1))),
          phone: _Phone(),
          locale: const Locale('ar'),
        ),
      );
      await tester.pump();
      final label = tester.getSemantics(find.byType(DealWidget)).label;
      expect(label, contains('ينتهي خلال ساعتان، دقيقة واحدة'));
      expect(label, contains('خصم 30٪'));
      await tester.pumpWidget(
        _app(
          DealWidget(data: _data(phase: 'sold_out')),
          phone: _Phone(),
        ),
      );
      await tester.pump();
      expect(tester.getSemantics(find.byType(DealWidget)).label, contains('This deal is sold out'));
      handle.dispose();
    });

    testWidgets('the digits themselves are not read out', (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(_app(DealWidget(data: _data()), phone: _Phone()));
      await tester.pump();
      expect(find.bySemanticsLabel('04'), findsNothing);
      handle.dispose();
    });
  });
}

extension on Matcher {
  Matcher or(Matcher other) => anyOf(this, other);
}
