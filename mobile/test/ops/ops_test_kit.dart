import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
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

  List<String> perms = const ['*'];
  StaffOverview overviewData = StaffOverview.fromJson({
    'branch': {'id': 'b1', 'name': 'Al Markhiya Main Branch', 'dispatch_mode': 'manual'},
    'new': 1,
    'preparing': 0,
    'ready': 1,
    'out_for_delivery': 0,
    'waiting_for_driver': 1,
    'drivers_on_duty': 1,
    'drivers_total': 2,
  });
  Map<String, List<QueueOrder>> queues = {
    'new': [order(id: 'f0', status: 'pending', number: 'AB100001')],
    'ready': [order(id: 'f1', status: 'ready', number: 'AB100009')],
  };
  Object? queueError;
  Object? overviewError;
  List<BranchDriver> branchDrivers = const [
    BranchDriver(id: 'u1', name: 'Omar', phone: '+97455123456', onDuty: true, openJobs: 0),
    BranchDriver(id: 'u2', name: 'Salem', phone: null, onDuty: false, openJobs: 2),
  ];
  Object? driversError;
  List<FleetDriver> fleetList = [];
  String? fleetBranch;
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

  static DriverJob job({String id = 'd1', String status = 'assigned', String cod = '303.00', bool proof = true}) =>
      DriverJob.fromJson({
        'id': id,
        'status': status,
        'order_number': 'AB100008',
        'customer_name': 'Noura',
        'customer_phone': '+97455123456',
        'address': {'zone_number': '24', 'street_number': '817', 'building_number': '12', 'latitude': 25.3, 'longitude': 51.5},
        'payment_method': 'cod',
        'cod_amount': cod,
        'requires_proof': proof,
        'branch': {'id': 'b1', 'name': 'Al Markhiya'},
        'scheduled_for': '2026-11-14T12:00:00Z',
      });

  static QueueOrder order({String id = 'f1', String status = 'ready', String number = 'AB100009', String? driver}) =>
      QueueOrder.fromJson({
        'fulfillment_id': id,
        'status': status,
        'number': number,
        'customer_name': 'Hamad',
        'items_count': 3,
        'payment_method': 'cod',
        'grand_total': '120.00',
        'address': {'zone_number': '24', 'street_number': '9'},
        'delivery': driver == null ? null : {'status': 'assigned', 'driver': {'id': 'u1', 'name': driver}},
      });

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
  Future<void> deliver(String id, {String? recipient, File? proof, double? codAmount}) async {
    calls.add('deliver:$id');
    delivered.add((id: id, recipient: recipient, proof: proof, cod: codAmount));
    await deliverGate?.future;
    if (deliverError != null) throw deliverError!;
  }

  @override
  Future<void> fail(String id, String reason) async => calls.add('fail:$id:$reason');

  @override
  Future<void> returnToBranch(String id) async => calls.add('return:$id');

  final beats = <({double lat, double lng})>[];

  @override
  Future<void> heartbeat(double lat, double lng, {double? speedKmh, int? heading, int? accuracy}) async {
    beats.add((lat: lat, lng: lng));
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
  Future<void> start({required String title, required String body, void Function(Object error)? onError}) async {
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
  OpsRole role = OpsRole.driver,
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
    staffEventsProvider.overrideWith((ref) => const Stream.empty()),
    staffMovesProvider.overrideWith((ref) => const Stream<Map<String, dynamic>>.empty()),
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
}) => ApiException(
  kind: kind,
  statusCode: status,
  code: code,
  message: message,
  errors: errors,
  retryAfter: retryAfter,
);

ApiException conflict([String message = 'Already moved']) =>
    apiError(ApiErrorKind.conflict, 409, code: 'invalid_state_transition', message: message);
