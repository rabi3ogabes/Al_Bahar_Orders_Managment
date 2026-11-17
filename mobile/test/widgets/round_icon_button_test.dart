// The round icon buttons (the product page's back and share buttons among them): the disc may be 40 dp, the button is the whole 48 dp box.
import 'dart:ui' show Tristate;

import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/features/catalog/presentation/widgets/product_header.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

Widget _host(Widget child) => MaterialApp(
  theme: AppTheme.light(const Locale('en')),
  home: Scaffold(body: Center(child: child)),
);

void main() {
  group('RoundIconButton', () {
    testWidgets('a tap on the margin round a 40 dp disc still counts: the button is 48 x 48', (tester) async {
      var taps = 0;
      await tester.pumpWidget(
        _host(RoundIconButton(icon: Icons.close, tooltip: 'Close', size: 40, onPressed: () => taps++)),
      );
      final box = tester.getRect(find.byType(RoundIconButton));
      expect(box.size, const Size(48, 48));
      // The ring between the disc (radius 20) and the edge of the box (24).
      await tester.tapAt(box.center + const Offset(22, 0));
      await tester.tapAt(box.center + const Offset(0, -22));
      await tester.tapAt(box.center + const Offset(-22, 0));
      await tester.tapAt(box.center + const Offset(0, 22));
      expect(taps, 4);
    });

    testWidgets('a tap on the disc counts once, not twice', (tester) async {
      var taps = 0;
      await tester.pumpWidget(
        _host(RoundIconButton(icon: Icons.close, tooltip: 'Close', size: 40, onPressed: () => taps++)),
      );
      await tester.tap(find.byIcon(Icons.close));
      expect(taps, 1);
    });

    testWidgets('outside the 48 dp box nothing happens', (tester) async {
      var taps = 0;
      await tester.pumpWidget(
        _host(RoundIconButton(icon: Icons.close, tooltip: 'Close', size: 40, onPressed: () => taps++)),
      );
      final box = tester.getRect(find.byType(RoundIconButton));
      await tester.tapAt(box.center + const Offset(26, 0));
      expect(taps, 0);
    });

    testWidgets('a button with no action does nothing and says so to a screen reader', (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(_host(const RoundIconButton(icon: Icons.close, tooltip: 'Close', onPressed: null)));
      await tester.tapAt(tester.getCenter(find.byType(RoundIconButton)) + const Offset(22, 0));
      final node = tester.getSemantics(find.bySemanticsLabel('Close'));
      expect(node.label, 'Close');
      expect(node.flagsCollection.isButton, isTrue);
      expect(node.flagsCollection.isEnabled, Tristate.isFalse);
      handle.dispose();
    });

    testWidgets('a screen reader hears it once: a button named by its tooltip', (tester) async {
      final handle = tester.ensureSemantics();
      await tester.pumpWidget(_host(RoundIconButton(icon: Icons.close, tooltip: 'Close', onPressed: () {})));
      expect(find.bySemanticsLabel('Close'), findsOneWidget);
      final node = tester.getSemantics(find.bySemanticsLabel('Close'));
      expect(node.flagsCollection.isButton, isTrue);
      expect(node.flagsCollection.isEnabled, Tristate.isTrue);
      handle.dispose();
    });
  });

  group('the product page header buttons', () {
    testWidgets('back, share: the whole 48 dp box answers, not just the 40 dp disc', (tester) async {
      var taps = 0;
      await tester.pumpWidget(
        _host(ProductHeaderButton(icon: Icons.arrow_back_rounded, tooltip: 'Back', onPressed: () => taps++)),
      );
      final box = tester.getRect(find.byType(RoundIconButton));
      expect(box.size, const Size(48, 48));
      await tester.tapAt(box.center + const Offset(22, 0));
      expect(taps, 1);
    });
  });
}
