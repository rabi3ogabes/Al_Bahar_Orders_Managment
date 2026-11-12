import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart' show testConfig;

class _FakeOps implements OpsRepository {
  final calls = <String>[];

  @override
  Future<DriverMe> driver() async => const DriverMe(name: 'Omar', onDuty: true, openJobs: 1, branches: ['Al Markhiya']);

  @override
  Future<List<DriverJob>> jobs({bool history = false}) async => history
      ? const []
      : [
          DriverJob.fromJson({
            'id': 'd1',
            'status': 'assigned',
            'order_number': 'AB100008',
            'customer_name': 'Noura',
            'customer_phone': '+97455123456',
            'address': {'zone_number': '24', 'street_number': '817', 'building_number': '12'},
            'payment_method': 'cod',
            'cod_amount': '303.00',
            'branch': {'id': 'b1', 'name': 'Al Markhiya'},
          }),
        ];

  @override
  Future<void> jobStep(String id, String action, {double? lat, double? lng}) async => calls.add('$action:$id');

  @override
  Future<StaffOverview> overview({String? branchId}) async => StaffOverview.fromJson({
    'branch': {'id': 'b1', 'name': 'Al Markhiya Main Branch', 'dispatch_mode': 'manual'},
    'new': 0,
    'preparing': 0,
    'ready': 1,
    'out_for_delivery': 0,
    'waiting_for_driver': 1,
    'drivers_on_duty': 1,
    'drivers_total': 2,
  });

  @override
  Future<List<QueueOrder>> queue(String stage, {String? branchId}) async => stage == 'ready'
      ? [
          QueueOrder.fromJson({
            'fulfillment_id': 'f1',
            'status': 'ready',
            'number': 'AB100009',
            'customer_name': 'Hamad',
            'items_count': 3,
            'payment_method': 'cod',
            'grand_total': '120.00',
            'address': {'zone_number': '24'},
          }),
        ]
      : const [];

  @override
  Future<List<BranchDriver>> drivers({String? branchId}) async => const [
    BranchDriver(id: 'u1', name: 'Omar', phone: null, onDuty: true, openJobs: 0),
  ];

  @override
  Future<List<FleetDriver>> fleet({String? branchId}) async => [
    FleetDriver.fromJson({
      'id': 'u1',
      'name': 'Omar',
      'phone': '+97455123456',
      'on_duty': true,
      'lat': 25.28,
      'lng': 51.52,
      'last_seen_at': DateTime.now().toIso8601String(),
      'open_jobs': 1,
      'job': {'order_number': 'AB100008', 'status': 'out_for_delivery'},
    }),
    FleetDriver.fromJson({'id': 'u2', 'name': 'Salem', 'on_duty': true, 'lat': null, 'lng': null, 'open_jobs': 0}),
  ];

  @override
  Future<List<String>> permissions() async => const ['*'];

  @override
  Future<List<ShopCategory>> categories() async => const [ShopCategory(id: 'c1', name: 'Living › Sofas')];

  @override
  Future<bool> aiReady() async => true;

  @override
  Future<ProductDraftText> describe({String? titleEn, String? titleAr, String? categoryId}) async {
    calls.add('describe:$titleEn');
    return const ProductDraftText(
      shortEn: 'Short',
      descriptionEn: '<p>Long</p>',
      shortAr: 'قصير',
      descriptionAr: '<p>طويل</p>',
    );
  }

  @override
  Future<void> assign(String fulfillmentId, String driverId) async => calls.add('assign:$fulfillmentId:$driverId');

  @override
  Future<void> advance(String fulfillmentId, String status) async => calls.add('advance:$fulfillmentId:$status');

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError(invocation.memberName.toString());
}

Widget _host(Widget home, _FakeOps repo) => ProviderScope(
  overrides: [
    opsRepositoryProvider.overrideWithValue(repo),
    // No socket in tests: real-time pings are covered by the backend tests.
    driverEventsProvider.overrideWith((ref) => const Stream.empty()),
    staffEventsProvider.overrideWith((ref) => const Stream.empty()),
  ],
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
    home: home,
  ),
);

void main() {
  testWidgets('driver sees an assigned delivery with the cash to collect and accepts it', (tester) async {
    final repo = _FakeOps();
    await tester.pumpWidget(_host(const DriverHome(), repo));
    await tester.pumpAndSettle();

    expect(find.text('#AB100008'), findsOneWidget);
    expect(find.textContaining('QAR 303.00'), findsOneWidget);
    expect(find.text('On duty'), findsOneWidget);
    await tester.tap(find.text('Accept'));
    await tester.pumpAndSettle();
    expect(repo.calls, ['accept:d1']);
  });

  testWidgets('branch employee assigns a driver to a ready order on a manual-dispatch branch', (tester) async {
    final repo = _FakeOps();
    await tester.pumpWidget(_host(const StaffHome(), repo));
    await tester.pumpAndSettle();
    expect(find.text('Al Markhiya Main Branch'), findsOneWidget);

    await tester.tap(find.textContaining('Ready'));
    await tester.pumpAndSettle();
    expect(find.text('#AB100009'), findsOneWidget);
    await tester.tap(find.text('Assign driver'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Omar').last);
    await tester.pumpAndSettle();
    expect(repo.calls, ['assign:f1:u1']);
  });

  test('driver job picks the next step by status', () {
    DriverJob job(String s) => DriverJob.fromJson({
      'id': 'x',
      'status': s,
      'order_number': '1',
      'customer_name': 'n',
      'payment_method': 'cod',
    });
    expect(
      [
        job('assigned'),
        job('accepted'),
        job('picked_up'),
        job('out_for_delivery'),
        job('delivered'),
      ].map((j) => j.nextAction),
      ['accept', 'pickup', 'out-for-delivery', 'deliver', null],
    );
    expect(File('lib/main_driver.dart').existsSync() && File('lib/main_staff.dart').existsSync(), isTrue);
  });

  testWidgets('add product: needs a title and a price, Claude fills the descriptions, leaving asks first', (
    tester,
  ) async {
    final repo = _FakeOps();
    await tester.pumpWidget(_host(const AddProductScreen(), repo));
    await tester.pumpAndSettle();

    // Save with nothing typed → inline errors, nothing sent.
    await tester.tap(find.text('Save product'));
    await tester.pumpAndSettle();
    expect(find.text('Required'), findsOneWidget);
    expect(find.text('Enter a price like 120 or 120.50'), findsOneWidget);

    // AI writing needs a title first.
    await tester.scrollUntilVisible(
      find.text('Translate and write the description with AI'),
      300,
      scrollable: find.descendant(of: find.byType(Form), matching: find.byType(Scrollable)).first,
    );
    await tester.tap(find.text('Translate and write the description with AI'));
    await tester.pumpAndSettle();
    expect(find.text('Write a title first.'), findsOneWidget);
    expect(repo.calls, isEmpty);

    await tester.scrollUntilVisible(
      find.widgetWithText(TextFormField, 'Title (English)'),
      -300,
      scrollable: find.descendant(of: find.byType(Form), matching: find.byType(Scrollable)).first,
    );
    await tester.enterText(find.widgetWithText(TextFormField, 'Title (English)'), 'Linen Throw Sand');
    await tester.scrollUntilVisible(
      find.text('Translate and write the description with AI'),
      300,
      scrollable: find.descendant(of: find.byType(Form), matching: find.byType(Scrollable)).first,
    );
    await tester.tap(find.text('Translate and write the description with AI'));
    await tester.pumpAndSettle();
    expect(repo.calls, ['describe:Linen Throw Sand']);
    expect(find.text('<p>Long</p>', skipOffstage: false), findsOneWidget);

    // Typed input → the exit asks "Leave without saving?", Keep editing stays.
    await tester.tap(find.byTooltip('Close'));
    await tester.pumpAndSettle();
    expect(find.text('Leave without saving?'), findsOneWidget);
    await tester.tap(find.text('Keep editing'));
    await tester.pumpAndSettle();
    expect(find.text('Leave without saving?'), findsNothing);
  });

  testWidgets(
    'live map: lists every driver with their job, pins the ones with a position and moves a pin on a live event',
    (tester) async {
      final repo = _FakeOps();
      final events = StreamController<Map<String, dynamic>>.broadcast();
      addTearDown(events.close);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [opsRepositoryProvider.overrideWithValue(repo), appConfigProvider.overrideWithValue(testConfig)],
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
            home: Scaffold(body: FleetMapTab(events: events.stream)),
          ),
        ),
      );
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));

      expect(find.text('Omar'), findsWidgets);
      expect(find.textContaining('Order AB100008 · On the way'), findsOneWidget);
      expect(find.textContaining('No location yet'), findsOneWidget); // Salem has not shared a position
      final pins = find.descendant(of: find.byType(MarkerLayer), matching: find.byIcon(Icons.two_wheeler_rounded));
      expect(pins, findsOneWidget); // Omar is pinned; Salem is listed but has no position

      // A live position for Salem pins him without a refresh.
      events.add({'driver_id': 'u2', 'lat': 25.2805, 'lng': 51.5205});
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));
      expect(pins, findsNWidgets(2));
    },
  );
}
