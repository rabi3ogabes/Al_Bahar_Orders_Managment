import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/branches/presentation/state/branch_providers.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/driver/driver_tracker.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/ops_widgets.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';

import '../helpers/fakes.dart' show testConfig;
import '../helpers/map_fakes.dart';

/// A programmable stand-in for [OpsRepository]: every call is recorded, every failure can be switched on.
class FakeOps implements OpsRepository {
  final calls = <String>[];
  int jobsLoads = 0;
  int queueLoads = 0;

  DriverMe me = const DriverMe(id: 'u1', name: 'Omar', onDuty: true, openJobs: 1, branches: ['Al Markhiya']);
  List<DriverJob> jobList = [job()];
  Object? jobsError;
  Object? stepError;
  Object? deliverError;
  Object? advanceError;
  Object? describeError;
  Object? createError;
  Object? uploadError;
  Object? studioError;
  Object? categoriesError;
  Completer<void>? deliverGate;
  Completer<void>? advanceGate;
  Completer<void>? uploadGate;
  final failUploads = <String>{};
  Completer<void>? stepGate;
  Completer<void>? dutyGate;
  Completer<List<DriverJob>>? jobsGate;
  final delivered = <({String id, String? recipient, File? proof, double? cod})>[];

  /// What the driver's steps sent besides what [delivered] records: where he stood and the key of the retry (see driver_context_test.dart).
  final deliveredExtras = <({double? lat, double? lng, double? accuracy, String? key})>[];
  final failures = <({String id, String reason, String? notes, File? photo, double? lat, double? lng, String? key})>[];
  Object? failError;
  Completer<void>? failGate;

  List<String> perms = const ['*'];
  StaffOverview overviewData = StaffOverview.fromJson({
    'branch': {'id': 'b1', 'name': 'Al Markhiya Main Branch', 'dispatch_mode': 'manual'},
    'new': 1,
    'preparing': 0,
    'ready': 1,
    'out_for_delivery': 0,
    'failed': 1,
    'waiting_for_driver': 1,
    'drivers_on_duty': 1,
    'drivers_total': 2,
  });
  Map<String, List<QueueOrder>> queues = {
    'new': [order(id: 'f0', status: 'pending', number: 'AB100001')],
    'ready': [order(id: 'f1', status: 'ready', number: 'AB100009')],
    'failed': [order(id: 'f2', status: 'failed', number: 'AB100010', driver: 'Omar')],
  };
  Object? queueError;

  /// Holds one stage's reload until completed, so a test can see the moment when some lists are newer than others.
  final queueGates = <String, Completer<void>>{};
  Object? overviewError;
  List<BranchDriver> branchDrivers = const [
    BranchDriver(id: 'u1', name: 'Omar', phone: '+97455123456', onDuty: true, openJobs: 0),
    BranchDriver(id: 'u2', name: 'Salem', phone: null, onDuty: false, openJobs: 2),
  ];
  Object? driversError;
  List<FleetDriver> fleetList = [];
  String? fleetBranch;
  int fleetLoads = 0;
  Object? fleetError;
  bool ai = true;
  List<ShopCategory> cats = const [ShopCategory(id: 'c1', name: 'Living › Sofas')];
  ProductDraftText draft = const ProductDraftText(
    shortEn: 'Short',
    descriptionEn: '<p>Long</p>',
    shortAr: 'قصير',
    descriptionAr: '<p>طويل</p>',
    nameEn: 'Linen Throw',
    nameAr: 'غطاء كتان',
  );
  var createdProducts = 0;

  static DriverJob job({
    String id = 'd1',
    String status = 'assigned',
    String cod = '303.00',
    bool proof = true,
    Object? items,
    String? alternate,
    Map<String, dynamic>? extra,
  }) => DriverJob.fromJson({
    'id': id,
    'status': status,
    'order_number': 'AB100008',
    'customer_name': 'Noura',
    'customer_phone': '+97455123456',
    'alternate_phone': ?alternate,
    'address': {
      'zone_number': '24',
      'street_number': '817',
      'building_number': '12',
      'latitude': 25.3,
      'longitude': 51.5,
    },
    'payment_method': 'cod',
    'cod_amount': cod,
    'requires_proof': proof,
    'branch': {'id': 'b1', 'name': 'Al Markhiya'},
    'scheduled_for': '2026-11-14T12:00:00Z',
    'items': ?items,
    ...?extra,
  });

  static QueueOrder order({
    String id = 'f1',
    String status = 'ready',
    String number = 'AB100009',
    String? driver,
    Object? items,
    String? alternate,
  }) => QueueOrder.fromJson({
    'fulfillment_id': id,
    'status': status,
    'number': number,
    'customer_name': 'Hamad',
    'alternate_phone': ?alternate,
    'items_count': 3,
    'payment_method': 'cod',
    'grand_total': '120.00',
    'address': {'zone_number': '24', 'street_number': '9'},
    'delivery': driver == null
        ? null
        : {
            'id': 'dl-$id',
            'status': status == 'failed' ? 'failed' : 'assigned',
            'driver': {'id': 'u1', 'name': driver},
          },
    'items': ?items,
  });

  /// What `GET /app-config` says about how a status is changed (buttons or slide), and how often it was asked.
  OpsStatusStyle style = OpsStatusStyle.buttons;
  Object? styleError;
  int styleLoads = 0;

  /// What `GET /app-config` says about the "Alternative phone number" switch (on until the dashboard turns it off).
  bool alternatePhoneEnabled = true;

  @override
  Future<OpsAppConfig> appConfig() async {
    styleLoads++;
    if (styleError != null) throw styleError!;
    return OpsAppConfig(statusStyle: style, alternatePhoneEnabled: alternatePhoneEnabled);
  }

  @override
  Future<OpsStatusStyle> statusStyle() async => (await appConfig()).statusStyle;

  // Missing items ("Missing an item?"): the parcel's lines, what the branch has to offer instead, and what the server answers.
  Map<String, dynamic> adjustContextBody = adjustContextJson();
  Object? adjustContextError;
  int adjustContextLoads = 0;
  List<Map<String, dynamic>> substituteBodies = [
    substituteJson('s1', 'Oslo lamp (large)', '300.00', 3),
    substituteJson('s2', 'Brass lamp', '200.00', 1),
  ];
  Object? substitutesError;
  final substituteSearches = <String>[];
  Completer<void>? substitutesGate;
  Object? adjustError;
  Object? previewError;
  Completer<void>? adjustGate;
  Map<String, dynamic>? adjustAnswer;
  final adjustRequests =
      <({String fulfillmentId, List<Map<String, dynamic>> changes, String reason, String? note, bool preview})>[];

  @override
  Future<AdjustContext> adjustContext(String fulfillmentId) async {
    adjustContextLoads++;
    if (adjustContextError != null) throw adjustContextError!;
    return AdjustContext.fromJson(adjustContextBody);
  }

  @override
  Future<List<Substitute>> substitutes(String fulfillmentId, {String search = '', int limit = 20}) async {
    substituteSearches.add(search);
    await substitutesGate?.future;
    if (substitutesError != null) throw substitutesError!;
    final q = search.toLowerCase();
    return [
      for (final b in substituteBodies)
        if (q.isEmpty || '${b['name']}'.toLowerCase().contains(q)) Substitute.fromJson(b),
    ];
  }

  @override
  Future<AdjustResult> adjust(
    String fulfillmentId, {
    required List<AdjustChange> changes,
    required String reasonCode,
    String? note,
    bool preview = false,
  }) async {
    final sent = [for (final c in changes) c.toJson()];
    adjustRequests.add((fulfillmentId: fulfillmentId, changes: sent, reason: reasonCode, note: note, preview: preview));
    if (preview) {
      if (previewError != null) throw previewError!;
    } else {
      await adjustGate?.future;
      if (adjustError != null) throw adjustError!;
    }
    return AdjustResult.fromJson(adjustAnswer ?? adjustResultJson(adjustContextBody, sent, preview: preview));
  }

  @override
  Future<DriverMe> driver() async => me;

  @override
  Future<bool> setDuty(bool onDuty, {double? lat, double? lng}) async {
    calls.add('duty:$onDuty');
    await dutyGate?.future;
    me = DriverMe(id: me.id, name: me.name, onDuty: onDuty, openJobs: me.openJobs, branches: me.branches);
    return onDuty;
  }

  @override
  Future<List<DriverJob>> jobs({bool history = false}) async {
    jobsLoads++;
    if (jobsGate != null) return jobsGate!.future;
    if (jobsError != null) throw jobsError!;
    return history ? const [] : jobList;
  }

  @override
  Future<void> jobStep(String id, String action, {double? lat, double? lng}) async {
    calls.add('$action:$id');
    await stepGate?.future;
    if (stepError != null) throw stepError!;
  }

  @override
  Future<void> deliver(
    String id, {
    String? recipient,
    File? proof,
    double? codAmount,
    double? lat,
    double? lng,
    double? accuracy,
    String? idempotencyKey,
  }) async {
    calls.add('deliver:$id');
    delivered.add((id: id, recipient: recipient, proof: proof, cod: codAmount));
    deliveredExtras.add((lat: lat, lng: lng, accuracy: accuracy, key: idempotencyKey));
    await deliverGate?.future;
    if (deliverError != null) throw deliverError!;
  }

  @override
  Future<void> fail(
    String id,
    String reason, {
    String? notes,
    File? photo,
    double? lat,
    double? lng,
    double? accuracy,
    String? idempotencyKey,
  }) async {
    calls.add('fail:$id:$reason');
    failures.add((id: id, reason: reason, notes: notes, photo: photo, lat: lat, lng: lng, key: idempotencyKey));
    await failGate?.future;
    if (failError != null) throw failError!;
  }

  @override
  Future<void> returnToBranch(String id) async => calls.add('return:$id');

  final beats = <({double lat, double lng, double? speedKmh, int? heading, int? accuracy, DateTime? recordedAt})>[];

  @override
  Future<void> heartbeat(
    double lat,
    double lng, {
    double? speedKmh,
    int? heading,
    int? accuracy,
    DateTime? recordedAt,
  }) async {
    beats.add((lat: lat, lng: lng, speedKmh: speedKmh, heading: heading, accuracy: accuracy, recordedAt: recordedAt));
    if (beatError != null) throw beatError!;
  }

  Object? beatError;

  @override
  Future<List<String>> permissions() async => perms;

  @override
  Future<StaffOverview> overview({String? branchId}) async {
    if (overviewError != null) throw overviewError!;
    return overviewData;
  }

  @override
  Future<List<QueueOrder>> queue(String stage, {String? branchId}) async {
    queueLoads++;
    await queueGates[stage]?.future;
    if (queueError != null) throw queueError!;
    return queues[stage] ?? const [];
  }

  @override
  Future<List<BranchDriver>> drivers({String? branchId}) async {
    if (driversError != null) throw driversError!;
    return branchDrivers;
  }

  @override
  Future<List<FleetDriver>> fleet({String? branchId}) async {
    fleetBranch = branchId;
    fleetLoads++;
    if (fleetError != null) throw fleetError!;
    return fleetList;
  }

  @override
  Future<void> assign(String fulfillmentId, String driverId) async => calls.add('assign:$fulfillmentId:$driverId');

  @override
  Future<void> advance(String fulfillmentId, String status) async {
    calls.add('advance:$fulfillmentId:$status');
    await advanceGate?.future;
    if (advanceError != null) throw advanceError!;
  }

  @override
  Future<List<ShopCategory>> categories() async {
    if (categoriesError != null) throw categoriesError!;
    return cats;
  }

  @override
  Future<bool> aiReady() async => ai;

  @override
  Future<ProductDraftText> describe({String? titleEn, String? titleAr, String? categoryId}) async {
    calls.add('describe:$titleEn|$titleAr');
    if (describeError != null) throw describeError!;
    return draft;
  }

  @override
  Future<String> createProduct({
    required String sku,
    required String price,
    required String titleEn,
    required String titleAr,
    required ProductDraftText text,
    String? categoryId,
    required bool publish,
  }) async {
    calls.add('create:$price:$titleEn|$titleAr:${publish ? 'active' : 'draft'}');
    createdProducts++;
    if (createError != null) throw createError!;
    return 'p1';
  }

  @override
  Future<String> uploadProductImage(String productId, File photo) async {
    calls.add('upload:${photo.path}');
    await uploadGate?.future;
    if (uploadError != null || failUploads.contains(photo.path)) throw uploadError ?? apiError(ApiErrorKind.offline, 0);
    return 'img-${photo.path}';
  }

  @override
  Future<void> askPhotoStudio(String productId, String imageId, String kind) async {
    calls.add('studio:$imageId:$kind');
    if (studioError != null) throw studioError!;
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError(invocation.memberName.toString());
}

class FakeLocation extends LocationService {
  FakeLocation({this.access = LocationAccess.granted});
  LocationAccess access;
  int settingsOpened = 0;

  @override
  Future<LocationAccess> ensurePermission() async => access;

  @override
  Future<({double lat, double lng})?> currentPosition() async => (lat: 25.28, lng: 51.52);

  @override
  Future<void> openSettings() async => settingsOpened++;
}

class FakeTracker extends DriverTracker {
  FakeTracker(super.repo);
  bool isRunning = false;
  int starts = 0;
  int stops = 0;

  @override
  bool get running => isRunning;

  @override
  Future<void> start({
    required String title,
    required String body,
    String? channelName,
    void Function(Object error)? onError,
  }) async {
    isRunning = true;
    starts++;
  }

  @override
  Future<void> stop() async {
    isRunning = false;
    stops++;
  }
}

/// Stands in for the camera and the gallery.
class FakePicker implements ImagePicker {
  final shots = <String>[];
  final multiLimits = <int?>[];
  Object? error;
  String next = '/tmp/ops-test-photo.jpg';
  List<String> gallery = const [];

  @override
  Future<XFile?> pickImage({
    required ImageSource source,
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    CameraDevice preferredCameraDevice = CameraDevice.rear,
    bool requestFullMetadata = true,
  }) async {
    if (error != null) throw error!;
    shots.add('single');
    return XFile(next);
  }

  @override
  Future<List<XFile>> pickMultiImage({
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    int? limit,
    bool requestFullMetadata = true,
  }) async {
    multiLimits.add(limit);
    if (limit != null && limit < 2) throw ArgumentError.value(limit, 'limit', 'cannot be lower than 2');
    return [for (final g in gallery) XFile(g)];
  }

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError(invocation.memberName.toString());
}

/// A screen size and language to run a widget under.
class Variant {
  const Variant(this.name, this.size, this.locale, {this.scale = 1.0, this.dark = false});
  final String name;
  final Size size;
  final Locale locale;
  final double scale;
  final bool dark;

  bool get rtl => locale.languageCode == 'ar';

  @override
  String toString() => name;
}

const kEn = Locale('en');
const kAr = Locale('ar');

/// The matrix every screen is checked under: two phones, both directions, normal and large text, dark mode.
const variants = <Variant>[
  Variant('360x640 en', Size(360, 640), kEn),
  Variant('360x640 ar', Size(360, 640), kAr),
  Variant('360x640 en 1.3x', Size(360, 640), kEn, scale: 1.3),
  Variant('360x640 ar 1.3x dark', Size(360, 640), kAr, scale: 1.3, dark: true),
  Variant('390x844 en', Size(390, 844), kEn),
  Variant('390x844 ar', Size(390, 844), kAr),
  Variant('768x1024 en tablet', Size(768, 1024), kEn),
];

Widget opsHost(
  Widget home,
  FakeOps repo, {
  Variant variant = const Variant('default', Size(390, 844), kEn),
  FakeLocation? location,
  FakePicker? picker,
  FakeTracker? tracker,
  FakeNavigation? navigation,
  OpsRole role = OpsRole.driver,
  Stream<PusherEvent>? staffEvents,
  List<Override> overrides = const [],
}) => ProviderScope(
  // Like the real app: a failed load is shown, not silently retried by the container.
  retry: (_, _) => null,
  overrides: [
    opsRoleProvider.overrideWithValue(role),
    opsRepositoryProvider.overrideWithValue(repo),
    appConfigProvider.overrideWithValue(testConfig),
    locationServiceProvider.overrideWithValue(location ?? FakeLocation()),
    opsImagePickerProvider.overrideWithValue(picker ?? FakePicker()),
    driverTrackerProvider.overrideWithValue(tracker ?? FakeTracker(repo)),
    // No socket in tests: real-time pings are covered by the backend tests.
    driverEventsProvider.overrideWith((ref) => const Stream.empty()),
    staffEventsProvider.overrideWith((ref) => staffEvents ?? const Stream.empty()),
    staffMovesProvider.overrideWith((ref) => const Stream<Map<String, dynamic>>.empty()),
    ...mapTestOverrides(navigation: navigation),
    ...overrides,
  ],
  child: MaterialApp(
    locale: variant.locale,
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: const [
      AppLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    theme: AppTheme.light(variant.locale),
    darkTheme: AppTheme.dark(variant.locale),
    themeMode: variant.dark ? ThemeMode.dark : ThemeMode.light,
    builder: (context, child) => MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(variant.scale)),
      child: child!,
    ),
    home: home,
  ),
);

/// Sizes the test window like a phone (1 logical pixel = 1 physical pixel).
void useVariant(WidgetTester tester, Variant v) {
  tester.view.physicalSize = v.size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

Future<void> settle(WidgetTester tester) async {
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 100));
  await tester.pump(const Duration(milliseconds: 400));
}

ApiException apiError(
  ApiErrorKind kind,
  int status, {
  String? code,
  String? message,
  Map<String, List<String>> errors = const {},
  Duration? retryAfter,
}) =>
    ApiException(kind: kind, statusCode: status, code: code, message: message, errors: errors, retryAfter: retryAfter);

ApiException conflict([String message = 'Already moved']) =>
    apiError(ApiErrorKind.conflict, 409, code: 'invalid_state_transition', message: message);

Map<String, dynamic> _m(String amount) => {'amount': amount, 'currency': 'QAR'};

/// `GET …/adjustments/context` of a parcel of three lines: 2 sofas, 1 lamp, 4 cushions.
Map<String, dynamic> adjustContextJson({
  bool canAdjust = true,
  String? blocked,
  String payment = 'cod',
  List<Map<String, dynamic>>? items,
}) => {
  'can_adjust': canAdjust,
  'blocked_reason': blocked ?? (canAdjust ? null : 'status'),
  'reasons': ['out_of_stock', 'not_found', 'damaged', 'quality', 'other'],
  'items':
      items ??
      [
        adjustItemJson('i1', 'Oslo sofa', 2, '1000.00', variant: 'Grey'),
        adjustItemJson('i2', 'Oslo lamp', 1, '250.00'),
        adjustItemJson('i3', 'Linen cushion', 4, '50.00', variant: 'Sand'),
      ],
  'totals': {'grand_total': _m('2475.00'), 'payment_method': payment, 'payment_status': 'pending'},
};

Map<String, dynamic> adjustItemJson(
  String id,
  String name,
  int qty,
  String unit, {
  String? variant,
  Object? adjustment,
}) => {
  'order_item_id': id,
  'product_id': 'p-$id',
  'variant_id': 'v-$id',
  'sku': 'SKU-$id',
  'name': name,
  'variant_name': variant,
  'image_url': null,
  'quantity': qty,
  'unit_price': _m(unit),
  'line_total': _m((double.parse(unit) * qty).toStringAsFixed(2)),
  'adjustment': adjustment,
};

Map<String, dynamic> substituteJson(String id, String name, String price, int available, {String? variant}) => {
  'variant_id': id,
  'product_id': 'p-$id',
  'name': name,
  'variant_name': variant,
  'sku': 'SKU-$id',
  'image_url': null,
  'unit_price': _m(price),
  'available': available,
};

/// What the server would answer to [changes] over [context] (a simple stand-in: the real maths is the server's).
Map<String, dynamic> adjustResultJson(
  Map<String, dynamic> context,
  List<Map<String, dynamic>> changes, {
  bool preview = false,
  String payment = 'cod',
  bool online = false,
}) {
  final items = {
    for (final i in (context['items'] as List).cast<Map<String, dynamic>>()) i['order_item_id'] as String: i,
  };
  final before = double.parse(((context['totals'] as Map)['grand_total'] as Map)['amount'] as String);
  var less = 0.0;
  final lines = <Map<String, dynamic>>[];
  var left = items.values.fold<int>(0, (n, i) => n + (i['quantity'] as int));
  for (final c in changes) {
    final item = items[c['order_item_id']]!;
    final qty = item['quantity'] as int;
    final unit = double.parse((item['unit_price'] as Map)['amount'] as String);
    switch (c['action']) {
      case 'reduce':
        final to = c['new_quantity'] as int;
        less += unit * (qty - to);
        left -= qty - to;
        lines.add({
          'order_item_id': c['order_item_id'],
          'action': 'reduce',
          'name': item['name'],
          'variant_name': item['variant_name'],
          'from_quantity': qty,
          'to_quantity': to,
          'replacement': null,
        });
      case 'remove':
        less += unit * qty;
        left -= qty;
        lines.add({
          'order_item_id': c['order_item_id'],
          'action': 'remove',
          'name': item['name'],
          'variant_name': item['variant_name'],
          'from_quantity': qty,
          'to_quantity': 0,
          'replacement': null,
        });
      default:
        left -= qty;
        lines.add({
          'order_item_id': c['order_item_id'],
          'action': 'replace',
          'name': item['name'],
          'variant_name': item['variant_name'],
          'from_quantity': qty,
          'to_quantity': 0,
          'replacement': {
            'variant_id': c['variant_id'],
            'name': 'Replacement',
            'variant_name': null,
            'quantity': c['quantity'],
            'unit_price': _m(unit.toStringAsFixed(2)),
          },
        });
    }
  }
  final after = before - less;
  Map<String, dynamic> totals(double grand) => {
    'subtotal': _m(grand.toStringAsFixed(2)),
    'discount_total': _m('0.00'),
    'tax_total': _m('0.00'),
    'grand_total': _m(grand.toStringAsFixed(2)),
  };
  return {
    'applied': !preview,
    'preview': preview,
    'revision': preview ? null : {'id': 'rev-1', 'number': 1},
    'lines': lines,
    'before': totals(before),
    'after': totals(after),
    'difference': _m((after - before).toStringAsFixed(2)),
    'refund': online && less > 0 ? {'amount': _m(less.toStringAsFixed(2)), 'needs_approval': true} : null,
    'fulfillment_cancelled': left <= 0,
    'order_cancelled': left <= 0,
    'customer_notified': !preview,
  };
}
