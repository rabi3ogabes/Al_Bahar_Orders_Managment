import 'package:albahar_customer/core/utils/qatar_time.dart';
import 'package:albahar_customer/core/widgets/side_panel.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/driver/driver_models.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

/// The driver's job card: what, where and when first, then "Before you go" (only what is true), the customer's note, who to call, the parcel.

const _phone = Variant('360x800 en', Size(360, 800), kEn);

/// A job with the new fields of the server, over the plain test job.
DriverJob ctxJob({String status = 'assigned', String cod = '303.00', Map<String, dynamic> extra = const {}, Object? items}) =>
    FakeOps.job(status: status, cod: cod, items: items, extra: extra);

Future<void> _open(WidgetTester tester, FakeOps repo, {Variant variant = _phone}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const DriverHome(), repo, variant: variant));
  await settle(tester);
  await tester.tap(find.text('#AB100008').first);
  await settle(tester);
}

Finder get _window => find.byType(SidePanelLayout);

void main() {
  group('Before you go: only what is true for this parcel', () {
    testWidgets('a plain household order says nothing about people, assembly or size', (tester) async {
      await _open(tester, FakeOps()..jobList = [ctxJob(cod: '0')]);
      expect(find.text('Before you go'), findsNothing);
      expect(find.text('Needs two people'), findsNothing);
      expect(find.text('Assembly requested'), findsNothing);
      expect(find.text('Oversized'), findsNothing);
    });

    testWidgets('cash on delivery is a chip with the amount, and nothing else is added to it', (tester) async {
      await _open(tester, FakeOps()..jobList = [ctxJob()]);
      expect(find.text('Before you go'), findsOneWidget);
      expect(find.byKey(const ValueKey('chip-cash')), findsOneWidget);
      expect(find.text('Cash on delivery QAR 303.00'), findsOneWidget);
      expect(find.byKey(const ValueKey('chip-two-people')), findsNothing);
    });

    testWidgets('two people, assembly and oversized show when the server says so', (tester) async {
      final job = ctxJob(extra: {'needs_two_people': true, 'requires_assembly': true, 'is_oversized': true});
      await _open(tester, FakeOps()..jobList = [job]);
      expect(find.byKey(const ValueKey('chip-two-people')), findsOneWidget);
      expect(find.byKey(const ValueKey('chip-assembly')), findsOneWidget);
      expect(find.byKey(const ValueKey('chip-oversized')), findsOneWidget);
      expect(find.text('Needs two people'), findsWidgets); // also on the card behind the window
    });

    testWidgets('in Arabic the chips are Arabic', (tester) async {
      final job = ctxJob(extra: {'needs_two_people': true});
      await _open(tester, FakeOps()..jobList = [job], variant: const Variant('360x800 ar', Size(360, 800), kAr));
      expect(find.text('قبل أن تنطلق'), findsOneWidget);
      expect(find.text('يحتاج شخصين'), findsWidgets);
      expect(find.text('الدفع عند الاستلام 303.00 ر.ق'), findsOneWidget);
    });
  });

  group('the customer\'s note and the gate code', () {
    testWidgets('are in one highlighted box with their own titles', (tester) async {
      final job = ctxJob(extra: {'instructions': 'Gate code 4471, call from downstairs', 'customer_notes': 'Please ring twice'});
      await _open(tester, FakeOps()..jobList = [job]);
      expect(find.byKey(const ValueKey('job-notes')), findsOneWidget);
      expect(find.text('Gate code 4471, call from downstairs'), findsOneWidget);
      expect(find.text('Please ring twice'), findsOneWidget);
      expect(find.text('Instructions for the door'), findsOneWidget);
      expect(find.text('Note from the customer'), findsWidgets);
    });

    testWidgets('no note, no box', (tester) async {
      await _open(tester, FakeOps()..jobList = [ctxJob()]);
      expect(find.byKey(const ValueKey('job-notes')), findsNothing);
      expect(find.text('Note from the customer'), findsNothing);
    });

    testWidgets('the landmark is under the address', (tester) async {
      await _open(tester, FakeOps()..jobList = [ctxJob(extra: {'landmark': 'Behind the blue pharmacy'})]);
      expect(find.text('Landmark: Behind the blue pharmacy'), findsOneWidget);
    });
  });

  group('who to call', () {
    testWidgets('the person at the door and the account holder are two clear choices when they differ', (tester) async {
      final job = ctxJob(
        extra: {
          'recipient': {'name': 'Mariam', 'phone': '+97455000002', 'is_account_holder': false},
        },
      );
      await _open(tester, FakeOps()..jobList = [job]);
      expect(find.byKey(const ValueKey('call-recipient')), findsOneWidget);
      expect(find.byKey(const ValueKey('call-account-holder')), findsOneWidget);
      expect(find.text('Call recipient'), findsOneWidget);
      expect(find.text('Call account holder'), findsOneWidget);
      expect(find.text('Mariam'), findsWidgets);
      expect(find.text('+97455000002'), findsWidgets);
      expect(find.text('+97455123456'), findsWidgets);
    });

    testWidgets('with one number there is just the Call tile', (tester) async {
      await _open(tester, FakeOps()..jobList = [ctxJob()]);
      expect(find.byKey(const ValueKey('call-recipient')), findsNothing);
      expect(find.byKey(const ValueKey('call-account-holder')), findsNothing);
      expect(find.byKey(const ValueKey('quick-call')), findsOneWidget);
    });

    testWidgets('a recipient who is the account holder is not offered twice', (tester) async {
      final job = ctxJob(
        extra: {
          'recipient': {'name': 'Noura', 'phone': '+97455123456', 'is_account_holder': true},
        },
      );
      await _open(tester, FakeOps()..jobList = [job]);
      expect(find.byKey(const ValueKey('call-recipient')), findsNothing);
    });
  });

  group('when', () {
    testWidgets('the window the customer chose, with a start and an end', (tester) async {
      final start = DateTime.now().toUtc();
      final end = start.add(const Duration(hours: 2));
      final job = ctxJob(
        extra: {
          'slot': {'start': start.toIso8601String(), 'end': end.toIso8601String()},
        },
      );
      await _open(tester, FakeOps()..jobList = [job]);
      final text = 'Today · ${QatarTime.time(start, 'en')} – ${QatarTime.time(end, 'en')}';
      expect(find.text(text), findsWidgets);
    });

    testWidgets('without a slot the scheduled time is shown as before', (tester) async {
      await _open(tester, FakeOps()..jobList = [ctxJob()]);
      expect(find.textContaining('Scheduled:'), findsWidgets);
    });
  });

  group('the parcel', () {
    final items = [
      {'id': 'i1', 'name': 'Oslo sofa', 'variant_name': 'Grey', 'sku': 'S1', 'quantity': 2, 'weight_kg': 32.5, 'dimensions_cm': {'length': 210, 'width': 90, 'height': 85}, 'is_oversized': true, 'requires_assembly': false},
      {'id': 'i2', 'name': 'Linen cushion', 'variant_name': null, 'sku': 'C1', 'quantity': 4},
    ];

    testWidgets('weight and size sit under the name, and the total weight closes the list', (tester) async {
      final job = ctxJob(items: items, extra: {'total_weight_kg': 65, 'needs_two_people': true, 'is_oversized': true});
      await _open(tester, FakeOps()..jobList = [job]);
      expect(find.text('32.5 kg  ·  210 × 90 × 85 cm'), findsOneWidget);
      expect(find.text('Total weight about 65 kg'), findsOneWidget);
      expect(find.text('Oversized'), findsWidgets);
      // A line the catalogue knows nothing about shows nothing extra.
      expect(find.text('Linen cushion'), findsOneWidget);
      expect(find.text('× 4'), findsOneWidget);
    });

    testWidgets('an older server that sends none of it still shows the items', (tester) async {
      final plain = [
        {'id': 'i1', 'name': 'Oslo sofa', 'quantity': 2},
      ];
      await _open(tester, FakeOps()..jobList = [FakeOps.job(items: plain)]);
      expect(find.text('Oslo sofa'), findsOneWidget);
      expect(find.textContaining('kg'), findsNothing);
    });
  });

  group('reading the server\'s answer', () {
    test('a job from an older server has an empty context', () {
      final c = FakeOps.job().context;
      expect(c.hasNotes, isFalse);
      expect(c.hasHeadsUp, isFalse);
      expect(c.arrivedAt, isNull);
      expect(c.recipient, isNull);
      expect(c.itemFacts, isEmpty);
    });

    test('a full context is read, with strings that are empty or blank left out', () {
      final c = DriverJobContext.fromJson({
        'instructions': 'Gate 4471',
        'customer_notes': '',
        'landmark': null,
        'recipient': {'name': 'Mariam', 'phone': '+97455000002', 'is_account_holder': false},
        'slot': {'start': '2026-11-24T14:00:00+03:00', 'end': '2026-11-24T16:00:00+03:00'},
        'total_weight_kg': '12.5',
        'needs_two_people': true,
        'arrived_at': '2026-11-24T14:30:00+03:00',
        'items': [
          {'id': 'a', 'weight_kg': 12.5, 'dimensions_cm': {'length': 1.2, 'width': 80, 'height': 40}, 'is_oversized': false, 'requires_assembly': true},
          {'id': 'b'},
        ],
      });
      expect(c.instructions, 'Gate 4471');
      expect(c.customerNotes, isNull);
      expect(c.recipient!.isAccountHolder, isFalse);
      expect(c.slotStart!.isBefore(c.slotEnd!), isTrue);
      expect(c.totalWeightKg, 12.5);
      expect(c.arrivedAt, isNotNull);
      expect(c.itemFacts.keys, ['a']); // a line with no facts is not kept
      expect(c.itemFacts['a']!.hasSize, isTrue);
      expect(c.itemFacts['a']!.requiresAssembly, isTrue);
    });

    test('the next step on the road is "arrived" first, then the delivery form', () {
      expect(FakeOps.job(status: 'out_for_delivery').nextAction, 'arrived');
      expect(FakeOps.job(status: 'out_for_delivery', extra: {'arrived_at': '2026-11-24T14:30:00+03:00'}).nextAction, 'deliver');
      expect(FakeOps.job(status: 'picked_up').nextAction, 'out-for-delivery');
    });

    test('only a job that was given and not accepted can be handed back', () {
      expect(FakeOps.job(status: 'assigned').canDecline, isTrue);
      for (final s in ['accepted', 'picked_up', 'out_for_delivery', 'failed']) {
        expect(FakeOps.job(status: s).canDecline, isFalse, reason: s);
      }
    });
  });

  group('layout: every phone, language, text size and theme', () {
    for (final v in variants) {
      testWidgets('the whole job card lays out without overflow: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        final job = ctxJob(
          extra: {
            'instructions': 'Gate code 4471, call from downstairs, second floor, the door on the left',
            'customer_notes': 'Please ring twice and do not knock, the baby is sleeping',
            'landmark': 'Behind the blue pharmacy next to the roundabout',
            'recipient': {'name': 'Mariam Al Kuwari', 'phone': '+97455000002', 'is_account_holder': false},
            'needs_two_people': true,
            'requires_assembly': true,
            'is_oversized': true,
            'total_weight_kg': 65,
          },
          items: [
            {'id': 'i1', 'name': 'Oslo corner sofa with a very long name that wraps over two lines', 'variant_name': 'Grey linen', 'quantity': 2, 'weight_kg': 32.5, 'dimensions_cm': {'length': 210, 'width': 90, 'height': 85}, 'is_oversized': true, 'requires_assembly': true},
          ],
        );
        await _open(tester, FakeOps()..jobList = [job], variant: v);
        expect(tester.takeException(), isNull);
        expect(_window, findsOneWidget);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }
  });
}
