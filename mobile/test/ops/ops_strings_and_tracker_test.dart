import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/ops/driver/driver_tracker.dart';
import 'package:albahar_customer/ops/ops_input.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';

import 'ops_test_kit.dart';

Position _fix(double lat, double lng, {DateTime? at}) => Position(
  latitude: lat,
  longitude: lng,
  timestamp: at ?? DateTime.now(),
  accuracy: 8,
  altitude: 0,
  altitudeAccuracy: 0,
  heading: 90,
  headingAccuracy: 0,
  speed: 5,
  speedAccuracy: 0,
);

void main() {
  group('texts', () {
    final source = File('lib/ops/ops_strings.dart').readAsStringSync();
    // Every `_t('English', 'Arabic')` pair, single- or multi-line.
    final pairs = RegExp(r"_t\(\s*'((?:[^'\\]|\\.)*)',\s*'((?:[^'\\]|\\.)*)',?\s*\)", dotAll: true)
        .allMatches(source)
        .map((m) => (en: m[1]!, ar: m[2]!))
        .toList();

    test('every text has an English and an Arabic version', () {
      expect(pairs.length, greaterThan(150));
      final arabic = RegExp(r'[؀-ۿ]');
      for (final p in pairs) {
        if (p.en == 'English' || p.en == 'العربية') continue; // the language switch shows the other language's name
        expect(p.en, isNotEmpty);
        expect(arabic.hasMatch(p.ar), isTrue, reason: 'no Arabic in the Arabic text of "${p.en}"');
        expect(arabic.hasMatch(p.en), isFalse, reason: 'Arabic in the English text "${p.en}"');
      }
    });

    test('placeholders match between the two languages', () {
      for (final p in pairs) {
        final a = RegExp(r'\$\{?[a-zA-Z_.()]+\}?').allMatches(p.en).map((m) => m[0]).toList();
        final b = RegExp(r'\$\{?[a-zA-Z_.()]+\}?').allMatches(p.ar).map((m) => m[0]).toList();
        expect(a.toSet(), b.toSet(), reason: 'placeholders differ for "${p.en}"');
      }
    });

    test('screens never hard-code English words any more (address words and the currency come from the strings)', () {
      for (final f in Directory('lib/ops').listSync(recursive: true).whereType<File>()) {
        if (f.path.endsWith('ops_strings.dart') || f.path.endsWith('ops_models.dart')) continue;
        final text = f.readAsStringSync();
        expect(text.contains("'QAR "), isFalse, reason: '${f.path} hard-codes the currency');
        expect(text.contains("'Zone "), isFalse, reason: '${f.path} hard-codes an address word');
      }
    });

    test('address, money and time-ago follow the language', () {
      final a = OpsAddress.fromJson({'zone_number': '24', 'street_number': '817', 'building_number': '12', 'unit_number': '3'});
      expect(const OpsStrings('en').address(a), 'Zone 24 · Street 817 · Bldg 12 · Unit 3');
      expect(const OpsStrings('ar').address(a), 'المنطقة 24 · الشارع 817 · المبنى 12 · الوحدة 3');
      expect(OpsAddress.fromJson({'label': 'Home'}).line, 'Home');
      expect(const OpsStrings('ar').money('5.00'), '5.00 ر.ق');
      expect(const OpsStrings('en').seenAgo(const Duration(seconds: 10)), 'just now');
      expect(const OpsStrings('ar').seenAgo(const Duration(minutes: 5)), contains('5'));
    });

    test('every reason and status the app can show has a label in both languages', () {
      for (final code in const ['en', 'ar']) {
        final t = OpsStrings(code);
        for (final r in ['customer_unreachable', 'customer_absent', 'wrong_address', 'refused', 'damaged', 'access_denied', 'other']) {
          expect(t.reasonLabel(r), isNotEmpty);
        }
        for (final s in ['assigned', 'accepted', 'picked_up', 'out_for_delivery', 'delivered', 'failed', 'rescheduled', 'returned', 'cancelled', 'pending']) {
          expect(t.statusLabel(s), isNot(s), reason: '$code has no label for $s');
        }
      }
    });
  });

  group('amount input', () {
    test('Arabic and Persian digits and decimal marks become plain digits', () {
      expect(normalizeDigits('٣٢٠٫٥٠'), '320.50');
      expect(normalizeDigits('۱۲۳'), '123');
      expect(normalizeDigits('12,5'), '12.5');
    });

    test('parseAmount accepts money and nothing else', () {
      expect(parseAmount(' 303.00 '), 303.0);
      expect(parseAmount('٣٠٣'), 303.0);
      expect(parseAmount(''), isNull);
      expect(parseAmount('1e5'), isNull);
      expect(parseAmount('-5'), isNull);
      expect(parseAmount('12.345'), isNull);
      expect(parseAmount('..'), isNull);
    });

    test('the formatter keeps digits, one dot and two decimals', () {
      const f = AmountInputFormatter();
      TextEditingValue run(String old, String typed) => f.formatEditUpdate(
        TextEditingValue(text: old),
        TextEditingValue(text: typed, selection: TextSelection.collapsed(offset: typed.length)),
      );
      expect(run('', 'a1b2').text, '12');
      expect(run('12.5', '12.55').text, '12.55');
      expect(run('12.55', '12.555').text, '12.55');
      expect(run('1', '1.2.').text, '1');
      expect(run('', '٣٫٥').text, '3.5');
    });
  });

  group('location tracker', () {
    // The tracker reads time through an injected clock, so the tests move time by hand.
    var clock = DateTime(2026, 11, 13, 12);
    DateTime now() => clock;
    Future<void> tick(WidgetTester tester, Duration d) async {
      clock = clock.add(d);
      await tester.pump(d);
    }

    setUp(() => clock = DateTime(2026, 11, 13, 12));

    testWidgets('sends a fix at most every 8 s, repeats a standing driver every minute, and stops cleanly', (tester) async {
      final repo = FakeOps();
      final fixes = StreamController<Position>();
      addTearDown(() => unawaited(fixes.close()));
      final tracker = DriverTracker(repo, positions: (_) => fixes.stream, now: now);
      await tracker.start(title: 't', body: 'b');
      expect(tracker.running, isTrue);

      fixes.add(_fix(25.28, 51.52, at: clock));
      await tester.pump();
      expect(repo.beats, hasLength(1));
      await tick(tester, const Duration(seconds: 2));
      fixes.add(_fix(25.281, 51.521, at: clock)); // two seconds later: held back by the gap
      await tester.pump();
      expect(repo.beats, hasLength(1));
      await tick(tester, const Duration(seconds: 7));
      fixes.add(_fix(25.282, 51.522, at: clock));
      await tester.pump();
      expect(repo.beats, hasLength(2));

      // The driver stands still: nothing arrives, but dispatch must keep hearing from the phone.
      await tick(tester, const Duration(seconds: 61));
      expect(repo.beats.length, 3);
      expect(repo.beats.last.lat, 25.282);

      await tracker.stop();
      expect(tracker.running, isFalse);
      final before = repo.beats.length;
      await tick(tester, const Duration(seconds: 130));
      expect(repo.beats.length, before, reason: 'no timer left running after stop');
    });

    testWidgets('an offline heartbeat is dropped quietly; a broken stream is reported', (tester) async {
      final repo = FakeOps()..beatError = apiError(ApiErrorKind.offline, 0);
      final fixes = StreamController<Position>();
      addTearDown(() => unawaited(fixes.close()));
      Object? broken;
      final tracker = DriverTracker(repo, positions: (_) => fixes.stream, now: now);
      await tracker.start(title: 't', body: 'b', onError: (e) => broken = e);
      fixes.add(_fix(25.28, 51.52, at: clock));
      await tester.pump();
      expect(repo.beats, hasLength(1));
      expect(tester.takeException(), isNull);
      fixes.addError(const PermissionDeniedException('revoked'));
      await tester.pump();
      expect(broken, isA<PermissionDeniedException>());
      await tracker.stop();
    });

    testWidgets('an old fix is not repeated as if it were fresh', (tester) async {
      final repo = FakeOps();
      final fixes = StreamController<Position>();
      addTearDown(() => unawaited(fixes.close()));
      final tracker = DriverTracker(repo, positions: (_) => fixes.stream, now: now);
      await tracker.start(title: 't', body: 'b');
      fixes.add(_fix(25.28, 51.52, at: clock));
      await tester.pump();
      repo.beats.clear();
      await tick(tester, const Duration(seconds: 130));
      expect(repo.beats, isNotEmpty); // a recent fix is repeated while standing still
      repo.beats.clear();
      await tick(tester, const Duration(seconds: 700)); // past the 10 minute limit
      await tick(tester, const Duration(seconds: 130));
      expect(repo.beats, isEmpty, reason: 'after a long GPS blackout the old position must not look fresh');
      await tracker.stop();
    });
  });
}
