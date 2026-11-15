import 'dart:async';
import 'dart:ui' show Tristate;

import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:flutter/services.dart' show LogicalKeyboardKey;
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

/// The slide inside a card of the given [width], like a step button on a queue card.
Widget _host(
  Widget slide, {
  double width = 300,
  Locale locale = const Locale('en'),
  bool dark = false,
  bool reduceMotion = false,
  VoidCallback? onCardTap,
}) => MaterialApp(
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
  builder: (context, child) => MediaQuery(
    data: MediaQuery.of(context).copyWith(disableAnimations: reduceMotion),
    child: child!,
  ),
  home: Scaffold(
    body: Center(
      child: SizedBox(
        width: width,
        child: Card(
          child: InkWell(
            onTap: onCardTap,
            child: Padding(padding: const EdgeInsets.all(8), child: slide),
          ),
        ),
      ),
    ),
  ),
);

final _thumb = find.byKey(slideThumbKey);

/// The thumb's centre, to measure how far it moved.
double _thumbX(WidgetTester t) => t.getCenter(_thumb).dx;

Future<void> _settle(WidgetTester t) async {
  await t.pump();
  await t.pump(const Duration(milliseconds: 400));
  await t.pump(const Duration(milliseconds: 800));
}

void main() {
  group('sliding', () {
    testWidgets('pulling the thumb past the threshold and letting go confirms once', (t) async {
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide to accept order', onConfirmed: () => fired++)));
      expect(find.text('Slide to accept order'), findsOneWidget);
      // 300 wide card, 16 padding: the track is 284 wide, so the thumb travels 224.
      await t.drag(_thumb, const Offset(200, 0));
      await t.pump(const Duration(milliseconds: 20));
      expect(fired, 1);
      await _settle(t);
      expect(fired, 1, reason: 'once, however long it lingers');
    });

    testWidgets('a short pull springs back and confirms nothing', (t) async {
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide to accept order', onConfirmed: () => fired++)));
      final start = _thumbX(t);
      await t.drag(_thumb, const Offset(90, 0));
      await _settle(t);
      expect(fired, 0);
      expect(_thumbX(t), closeTo(start, 0.5), reason: 'the thumb is back at the start');
      // And it still works afterwards.
      await t.drag(_thumb, const Offset(220, 0));
      await _settle(t);
      expect(fired, 1);
    });

    testWidgets('just under the threshold springs back, just over confirms', (t) async {
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide', onConfirmed: () => fired++)));
      // Track 284, thumb slot 60 -> range 224; 85 % is 190 px of thumb travel.
      await t.drag(_thumb, const Offset(180, 0));
      await _settle(t);
      expect(fired, 0);
      await t.drag(_thumb, const Offset(196, 0));
      await _settle(t);
      expect(fired, 1);
    });

    testWidgets('a long drag past the end is clamped and still fires only once', (t) async {
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide', onConfirmed: () => fired++)));
      final g = await t.startGesture(t.getCenter(_thumb));
      for (var i = 0; i < 12; i++) {
        await g.moveBy(const Offset(40, 0));
        await t.pump(const Duration(milliseconds: 16));
      }
      await g.up();
      await _settle(t);
      expect(fired, 1);
    });

    testWidgets('a second slide during the first step does nothing; the control is ready again afterwards', (t) async {
      final gate = Completer<void>();
      var fired = 0;
      await t.pumpWidget(
        _host(
          SlideToConfirm(
            label: 'Slide',
            busyLabel: 'Updating…',
            onConfirmed: () async {
              fired++;
              await gate.future;
            },
          ),
        ),
      );
      await t.drag(_thumb, const Offset(220, 0));
      await t.pump();
      await t.pump(const Duration(milliseconds: 600));
      expect(fired, 1);
      expect(find.text('Updating…'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      // The thumb is at the end now: dragging it again fires nothing.
      await t.drag(_thumb, const Offset(-100, 0), warnIfMissed: false);
      await t.pump(const Duration(milliseconds: 300));
      expect(fired, 1);
      gate.complete();
      await _settle(t);
      expect(find.text('Slide'), findsOneWidget);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      await t.drag(_thumb, const Offset(220, 0));
      await _settle(t);
      expect(fired, 2);
    });

    testWidgets('a step that throws is reported and the control comes back', (t) async {
      await t.pumpWidget(
        _host(
          SlideToConfirm(
            label: 'Slide',
            onConfirmed: () async {
              throw StateError('boom');
            },
          ),
        ),
      );
      await t.drag(_thumb, const Offset(220, 0));
      await _settle(t);
      final error = t.takeException();
      expect(error, isA<StateError>());
      expect(find.text('Slide'), findsOneWidget);
    });
  });

  group('direction', () {
    testWidgets('in Arabic the thumb starts on the right and a pull to the left confirms', (t) async {
      var fired = 0;
      await t.pumpWidget(
        _host(
          SlideToConfirm(label: 'اسحب لقبول الطلب', onConfirmed: () => fired++),
          locale: const Locale('ar'),
        ),
      );
      expect(_thumbX(t), greaterThan(t.getCenter(find.byType(SlideToConfirm)).dx), reason: 'starts on the right');
      // A pull to the right (the wrong way) goes nowhere.
      await t.drag(_thumb, const Offset(200, 0));
      await _settle(t);
      expect(fired, 0);
      await t.drag(_thumb, const Offset(-200, 0));
      await _settle(t);
      expect(fired, 1);
    });

    testWidgets('in English the thumb starts on the left and a pull to the left does nothing', (t) async {
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide', onConfirmed: () => fired++)));
      expect(_thumbX(t), lessThan(t.getCenter(find.byType(SlideToConfirm)).dx));
      await t.drag(_thumb, const Offset(-200, 0));
      await _settle(t);
      expect(fired, 0);
    });
  });

  group('disabled and loading', () {
    testWidgets('disabled: nothing can be started, not even by a screen reader', (t) async {
      final handle = t.ensureSemantics();
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide', enabled: false, onConfirmed: () => fired++)));
      await t.drag(_thumb, const Offset(220, 0));
      await _settle(t);
      expect(fired, 0);
      final node = t.getSemantics(find.byType(SlideToConfirm));
      expect(node.flagsCollection.isEnabled, isNot(Tristate.isTrue));
      expect(node.getSemanticsData().hasAction(SemanticsAction.tap), isFalse);
      handle.dispose();
    });

    testWidgets('loading: shows progress and starts nothing; returns to the start when the caller is done', (t) async {
      var fired = 0;
      var loading = true;
      late StateSetter set;
      await t.pumpWidget(
        _host(
          StatefulBuilder(
            builder: (c, setState) {
              set = setState;
              return SlideToConfirm(label: 'Slide', loading: loading, onConfirmed: () => fired++);
            },
          ),
        ),
      );
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      await t.drag(_thumb, const Offset(220, 0));
      await _settle(t);
      expect(fired, 0);
      set(() => loading = false);
      await _settle(t);
      expect(find.byType(CircularProgressIndicator), findsNothing);
      await t.drag(_thumb, const Offset(220, 0));
      await _settle(t);
      expect(fired, 1);
    });

    testWidgets('a caller that shows its own progress: the slide waits for it, then comes back', (t) async {
      var loading = false;
      var fired = 0;
      late StateSetter set;
      await t.pumpWidget(
        _host(
          StatefulBuilder(
            builder: (c, setState) {
              set = setState;
              return SlideToConfirm(
                label: 'Slide',
                loading: loading,
                busyLabel: 'Updating…',
                onConfirmed: () async {
                  fired++;
                  set(() => loading = true);
                  await Future<void>.delayed(const Duration(milliseconds: 50));
                  set(() => loading = false);
                },
              );
            },
          ),
        ),
      );
      await t.drag(_thumb, const Offset(220, 0));
      await t.pump(const Duration(milliseconds: 20));
      expect(find.text('Updating…'), findsOneWidget);
      await _settle(t);
      expect(fired, 1);
      expect(find.text('Slide'), findsOneWidget);
    });
  });

  group('scrolling and taps', () {
    testWidgets('a tap on the track nudges the thumb and never reaches the card behind it', (t) async {
      var cardTaps = 0;
      var fired = 0;
      await t.pumpWidget(
        _host(
          SlideToConfirm(label: 'Slide', onConfirmed: () => fired++),
          onCardTap: () => cardTaps++,
        ),
      );
      await t.tapAt(t.getCenter(find.text('Slide')));
      await t.pump(const Duration(milliseconds: 120));
      expect(cardTaps, 0);
      expect(fired, 0);
      expect(_thumbX(t), greaterThan(40), reason: 'it moved a little, to show the way');
      await _settle(t);
      // A tap on the card itself outside the slide still works.
      await t.tapAt(t.getTopLeft(find.byType(Card)) + const Offset(10, 6));
      expect(cardTaps, 1);
    });

    testWidgets('a vertical swipe that starts on the thumb scrolls the list, the slide stays put', (t) async {
      var fired = 0;
      final controller = ScrollController();
      await t.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ListView(
              controller: controller,
              children: [
                const SizedBox(height: 120),
                Padding(
                  padding: const EdgeInsets.all(16),
                  child: SlideToConfirm(label: 'Slide', onConfirmed: () => fired++),
                ),
                const SizedBox(height: 1200),
              ],
            ),
          ),
        ),
      );
      final start = _thumbX(t);
      await t.drag(_thumb, const Offset(0, -80));
      await t.pump();
      expect(controller.offset, greaterThan(40), reason: 'the list scrolled');
      expect(_thumbX(t), closeTo(start, 0.5));
      expect(fired, 0);
    });
  });

  group('screen readers and keyboards', () {
    testWidgets('one button named after the step; a double tap takes it', (t) async {
      final handle = t.ensureSemantics();
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide to accept order', onConfirmed: () => fired++)));
      final node = t.getSemantics(find.byType(SlideToConfirm));
      expect(node.label, 'Slide to accept order');
      expect(node.flagsCollection.isButton, isTrue);
      expect(node.getSemanticsData().hasAction(SemanticsAction.tap), isTrue);
      t.semantics.tap(find.semantics.byLabel('Slide to accept order'));
      await _settle(t);
      expect(fired, 1);
      handle.dispose();
    });

    testWidgets('Enter on the focused control takes the step', (t) async {
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide', onConfirmed: () => fired++)));
      Focus.of(t.element(_thumb)).requestFocus();
      await t.pump();
      await t.sendKeyEvent(LogicalKeyboardKey.enter);
      await _settle(t);
      expect(fired, 1);
    });
  });

  group('looks', () {
    testWidgets('the track is at least 56 high and the thumb at least 48 wide, also at large text', (t) async {
      await t.pumpWidget(_host(const SlideToConfirm(label: 'Slide to confirm delivery', onConfirmed: _noop)));
      expect(t.getSize(find.byType(SlideToConfirm)).height, greaterThanOrEqualTo(56));
      expect(t.getSize(_thumb).width, greaterThanOrEqualTo(48));
      expect(t.getSize(_thumb).height, greaterThanOrEqualTo(48));
    });

    testWidgets('a long label in a narrow card wraps instead of overflowing, in both directions', (t) async {
      for (final locale in const [Locale('en'), Locale('ar')]) {
        await t.pumpWidget(
          _host(
            const SlideToConfirm(label: 'Slide to confirm that the delivery was handed over', onConfirmed: _noop),
            width: 232,
            locale: locale,
          ),
        );
        await t.pump();
        expect(t.takeException(), isNull);
      }
    });

    testWidgets('reduced motion: no spring and no hint animation, but it still works', (t) async {
      var fired = 0;
      await t.pumpWidget(_host(SlideToConfirm(label: 'Slide', onConfirmed: () => fired++), reduceMotion: true));
      expect(t.hasRunningAnimations, isFalse, reason: 'nothing animates by itself');
      final start = _thumbX(t);
      await t.drag(_thumb, const Offset(90, 0));
      await t.pump();
      expect(_thumbX(t), closeTo(start, 0.5), reason: 'back at once');
      await t.drag(_thumb, const Offset(220, 0));
      await t.pump();
      expect(fired, 1);
    });

    testWidgets('the hint animation runs a few times and then rests (it never loops forever)', (t) async {
      await t.pumpWidget(_host(const SlideToConfirm(label: 'Slide', onConfirmed: _noop)));
      expect(t.hasRunningAnimations, isTrue);
      await t.pump(const Duration(seconds: 6));
      expect(t.hasRunningAnimations, isFalse);
    });
  });
}

void _noop() {}
