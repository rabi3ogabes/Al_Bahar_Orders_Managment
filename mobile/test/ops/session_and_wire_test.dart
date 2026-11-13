import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import 'ops_test_kit.dart';

Map<String, dynamic> _loginOk() => {
  'status': 'authenticated',
  'user': {'id': 'u1'},
  'tokens': tokens('A1', 'R1'),
};

FakeHandler _api({
  FakeReply Function(RecordedRequest r)? login,
  List<String> permissions = const ['orders.view'],
  bool driver = false,
  Duration? loginDelay,
}) => (r) async {
  switch ('${r.method} ${r.path}') {
    case 'POST /admin/auth/login':
      if (loginDelay != null) await Future<void>.delayed(loginDelay);
      return login?.call(r) ?? ok(_loginOk());
    case 'GET /admin/me':
      return ok({'is_super_admin': false, 'permissions': permissions, 'user': {'id': 'u1'}});
    case 'GET /admin/dispatch/driver':
      return driver
          ? ok({'id': 'u1', 'name': 'Omar', 'on_duty': false, 'open_jobs': 0, 'branches': []})
          : error(403, 'driver_not_eligible', message: 'not a driver');
    case 'GET /admin/dispatch/driver/jobs':
      return ok([]);
    case 'GET /admin/dispatch/overview':
      return ok({
        'branch': {'id': 'b1', 'name': 'Main', 'dispatch_mode': 'manual'},
        'new': 0,
        'preparing': 0,
        'ready': 0,
        'out_for_delivery': 0,
        'waiting_for_driver': 0,
        'drivers_on_duty': 0,
        'drivers_total': 0,
      });
    case 'GET /admin/dispatch/queue':
      return ok([]);
    case 'GET /admin/dispatch/drivers':
      return ok([]);
    case 'POST /auth/logout':
      return ok({'logged_out': true});
    case 'PUT /admin/dispatch/driver/duty':
      return ok({'on_duty': false});
    case 'POST /admin/devices':
    case 'DELETE /admin/devices':
      return ok({'ok': true});
  }
  return error(404, 'not_found');
};

Future<TestHarness> _harness(WidgetTester tester, OpsRole role, FakeHandler handler, {AuthTokens? signedIn}) async {
  final h = await TestHarness.create(
    handler: handler,
    tokens: signedIn,
    overrides: [
      opsRoleProvider.overrideWithValue(role),
      pushDevicesPathProvider.overrideWithValue('/admin/devices'),
      driverEventsProvider.overrideWith((ref) => const Stream.empty()),
      staffEventsProvider.overrideWith((ref) => const Stream.empty()),
      staffMovesProvider.overrideWith((ref) => const Stream<Map<String, dynamic>>.empty()),
      driverTrackerProvider.overrideWithValue(FakeTracker(OpsRepository(ApiClient(Dio())))),
    ],
  );
  tester.view.physicalSize = const Size(390, 844);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(UncontrolledProviderScope(container: h.container, child: const OpsApp()));
  await settle(tester);
  return h;
}

Future<void> _fillAndSubmit(WidgetTester tester, {String email = '  omar@albahar.qa ', String password = 'secret123'}) async {
  await tester.enterText(find.widgetWithText(TextField, 'Email'), email);
  await tester.enterText(find.widgetWithText(TextField, 'Password'), password);
  await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
  await settle(tester);
}

void main() {
  group('sign-in', () {
    testWidgets('empty fields are asked for, nothing is sent', (tester) async {
      final h = await _harness(tester, OpsRole.staff, _api());
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await settle(tester);
      expect(find.text('Enter your email and password.'), findsOneWidget);
      expect(h.adapter.requests, isEmpty);
    });

    testWidgets('a staff member signs in: email trimmed, the branch app opens, the token is kept', (tester) async {
      final h = await _harness(tester, OpsRole.staff, _api());
      await _fillAndSubmit(tester);
      final login = h.adapter.where('POST', '/admin/auth/login').single;
      expect(login.body['email'], 'omar@albahar.qa');
      expect(login.body['platform'], anyOf('android', 'ios'));
      expect(find.byType(StaffHome), findsOneWidget);
      expect(h.tokenStore.cached?.accessToken, 'A1');
    });

    testWidgets('a driver signs in to the driver app', (tester) async {
      await _harness(tester, OpsRole.driver, _api(driver: true));
      await _fillAndSubmit(tester);
      expect(find.byType(DriverHome), findsOneWidget);
    });

    testWidgets('a wrong password shows the server message and keeps the form', (tester) async {
      final h = await _harness(
        tester,
        OpsRole.staff,
        _api(login: (_) => error(401, 'invalid_credentials', message: 'The email or password is incorrect.')),
      );
      await _fillAndSubmit(tester);
      expect(find.text('The email or password is incorrect.'), findsOneWidget);
      expect(find.byType(StaffHome), findsNothing);
      expect(h.tokenStore.cached, isNull);
      expect(find.text('omar@albahar.qa'), findsOneWidget);
    });

    testWidgets('too many attempts (429): the wait is explained', (tester) async {
      await _harness(
        tester,
        OpsRole.staff,
        _api(login: (_) => error(429, 'too_many_requests', context: {'seconds': 45})),
      );
      await _fillAndSubmit(tester);
      expect(find.textContaining('45'), findsOneWidget);
    });

    testWidgets('offline: a plain message, not "Error"', (tester) async {
      final h = await _harness(tester, OpsRole.staff, _api());
      h.adapter.failConnection = (r) => r.path == '/admin/auth/login';
      await _fillAndSubmit(tester);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
    });

    testWidgets('422 shows the field message', (tester) async {
      await _harness(
        tester,
        OpsRole.staff,
        _api(
          login: (_) => error(
            422,
            'validation_failed',
            message: 'The given data was invalid.',
            errors: {'email': ['The email field must be a valid email address.']},
          ),
        ),
      );
      await _fillAndSubmit(tester, email: 'nope');
      expect(find.text('The email field must be a valid email address.'), findsOneWidget);
    });

    testWidgets('two-factor: the code field appears, the second attempt carries the code', (tester) async {
      var calls = 0;
      final h = await _harness(
        tester,
        OpsRole.staff,
        _api(
          login: (r) {
            calls++;
            return calls == 1
                ? error(401, 'two_factor_required', message: 'Enter the 6-digit code from your authenticator app.')
                : ok(_loginOk());
          },
        ),
      );
      await _fillAndSubmit(tester);
      expect(find.text('Verification code'), findsOneWidget);
      await tester.enterText(find.widgetWithText(TextField, 'Verification code'), '12a3456');
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await settle(tester);
      final second = h.adapter.where('POST', '/admin/auth/login').last;
      expect(second.body['two_factor_code'], '123456');
      expect(find.byType(StaffHome), findsOneWidget);
    });

    testWidgets('an account of the wrong kind is refused and its token is revoked', (tester) async {
      final h = await _harness(tester, OpsRole.driver, _api(driver: false));
      await _fillAndSubmit(tester);
      expect(find.text('This account is not set up as a delivery driver.'), findsOneWidget);
      expect(h.tokenStore.cached, isNull);
      expect(h.adapter.where('POST', '/auth/logout'), hasLength(1));
    });

    testWidgets('a staff account without order access is refused', (tester) async {
      final h = await _harness(tester, OpsRole.staff, _api(permissions: const ['catalog.view']));
      await _fillAndSubmit(tester);
      expect(find.text('This account cannot manage branch orders.'), findsOneWidget);
      expect(h.tokenStore.cached, isNull);
    });

    testWidgets('a double tap on Sign in sends one request', (tester) async {
      final h = await _harness(tester, OpsRole.staff, _api(loginDelay: const Duration(seconds: 2)));
      await tester.enterText(find.widgetWithText(TextField, 'Email'), 'omar@albahar.qa');
      await tester.enterText(find.widgetWithText(TextField, 'Password'), 'secret123');
      await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
      await tester.pump();
      await tester.tap(find.byType(FilledButton), warnIfMissed: false);
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pump(const Duration(milliseconds: 100));
      expect(h.adapter.where('POST', '/admin/auth/login'), hasLength(1));
      await tester.pump(const Duration(seconds: 3));
      await settle(tester);
    });

    testWidgets('fields are autofill-ready: username, password, correction off, password can be shown', (tester) async {
      await _harness(tester, OpsRole.staff, _api());
      final email = tester.widget<TextField>(find.widgetWithText(TextField, 'Email'));
      final password = tester.widget<TextField>(find.widgetWithText(TextField, 'Password'));
      expect(email.keyboardType, TextInputType.emailAddress);
      expect(email.autofillHints, contains(AutofillHints.username));
      expect(email.autocorrect, isFalse);
      expect(email.textInputAction, TextInputAction.next);
      expect(password.autofillHints, contains(AutofillHints.password));
      expect(password.obscureText, isTrue);
      expect(password.textInputAction, TextInputAction.done);
      await tester.tap(find.byTooltip('Show password'));
      await tester.pump();
      expect(tester.widget<TextField>(find.widgetWithText(TextField, 'Password')).obscureText, isFalse);
      expect(find.byTooltip('Hide password'), findsOneWidget);
    });

    testWidgets('Arabic login: right-to-left, Arabic labels, language switch back to English', (tester) async {
      await _harness(tester, OpsRole.staff, _api());
      await tester.tap(find.text('العربية'));
      await settle(tester);
      expect(find.text('تسجيل الدخول'), findsWidgets);
      expect(find.text('البريد الإلكتروني'), findsOneWidget);
      expect(Directionality.of(tester.element(find.byType(OpsLoginScreen))), TextDirection.rtl);
      await tester.tap(find.text('English'));
      await settle(tester);
      expect(find.text('Sign in'), findsWidgets);
    });
  });

  group('session', () {
    final signedIn = AuthTokens.fromJson(tokens('A1', 'R1'));

    testWidgets('the server ending the session closes every open screen and says why', (tester) async {
      final h = await _harness(tester, OpsRole.staff, _api(permissions: const ['*']), signedIn: signedIn);
      expect(find.byType(StaffHome), findsOneWidget);
      await tester.tap(find.byTooltip('Add product'));
      await settle(tester);
      expect(find.byType(AddProductScreen), findsOneWidget);

      h.read(sessionExpirySignalProvider).fire();
      await settle(tester);
      expect(find.byType(AddProductScreen), findsNothing);
      expect(find.byType(StaffHome), findsNothing);
      expect(find.byType(OpsLoginScreen), findsOneWidget);
      expect(find.text('Your session ended. Please sign in again.'), findsOneWidget);
    });

    testWidgets('sign out (staff): confirms, revokes the token on the server, clears the device', (tester) async {
      final h = await _harness(tester, OpsRole.staff, _api(), signedIn: signedIn);
      await tester.tap(find.byTooltip('Menu'));
      await settle(tester);
      await tester.tap(find.text('Sign out'));
      await settle(tester);
      expect(find.text('Sign out?'), findsOneWidget);
      await tester.tap(find.widgetWithText(TextButton, 'Sign out'));
      await settle(tester);
      expect(h.adapter.where('POST', '/auth/logout'), hasLength(1));
      expect(h.tokenStore.cached, isNull);
      expect(find.byType(OpsLoginScreen), findsOneWidget);
      expect(find.text('Your session ended. Please sign in again.'), findsNothing);
    });

    testWidgets('sign out (driver) goes off duty first, so no deliveries are sent to a signed-out phone', (tester) async {
      final h = await _harness(tester, OpsRole.driver, _api(driver: true), signedIn: signedIn);
      await tester.tap(find.byTooltip('Menu'));
      await settle(tester);
      await tester.tap(find.text('Sign out'));
      await settle(tester);
      expect(find.text('Sign out and go off duty?'), findsOneWidget);
      await tester.tap(find.widgetWithText(TextButton, 'Sign out'));
      await settle(tester);
      final order = h.adapter.requests.map((r) => '${r.method} ${r.path}').toList();
      expect(order, containsAllInOrder(['PUT /admin/dispatch/driver/duty', 'POST /auth/logout']));
      expect(find.byType(OpsLoginScreen), findsOneWidget);
    });

    testWidgets('cancelling the sign-out keeps the session', (tester) async {
      final h = await _harness(tester, OpsRole.staff, _api(), signedIn: signedIn);
      await tester.tap(find.byTooltip('Menu'));
      await settle(tester);
      await tester.tap(find.text('Sign out'));
      await settle(tester);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(h.tokenStore.cached, isNotNull);
      expect(find.byType(StaffHome), findsOneWidget);
    });

    testWidgets('the language switch is in the menu once signed in', (tester) async {
      await _harness(tester, OpsRole.staff, _api(), signedIn: signedIn);
      await tester.tap(find.byTooltip('Menu'));
      await settle(tester);
      await tester.tap(find.text('العربية'));
      await settle(tester);
      expect(find.text('جديدة · 0'), findsOneWidget);
    });
  });

  group('wire format against the backend contract', () {
    late Directory tmp;
    setUp(() => tmp = Directory.systemTemp.createTempSync('ops-wire'));
    tearDown(() => tmp.deleteSync(recursive: true));

    Future<(OpsRepository, FakeAdapter, MemoryTokenStore)> repo(FakeHandler handler) async {
      final h = await TestHarness.create(handler: handler, tokens: AuthTokens.fromJson(tokens('A1', 'R1')));
      return (OpsRepository(h.read(apiClientProvider)), h.adapter, h.tokenStore);
    }

    test('deliver: multipart with recipient, proof and cash fields as the controller validates them', () async {
      final (r, adapter, _) = await repo((_) => ok({}));
      final photo = File('${tmp.path}/p.jpg')..writeAsBytesSync([1, 2, 3]);
      await r.deliver('d1', recipient: 'Noura', proof: photo, codAmount: 303);
      final req = adapter.where('POST', '/admin/deliveries/d1/deliver').single;
      final form = req.body as FormData;
      expect(form.fields.map((f) => '${f.key}=${f.value}'), containsAll(['recipient_name=Noura', 'cod[tender]=cash', 'cod[amount]=303.00']));
      expect(form.files.single.key, 'proof');
      // A photo upload gets longer than a normal call (mobile networks), and is never retried by the client.
      expect(req.options.sendTimeout, const Duration(seconds: 120));
      expect(adapter.requests, hasLength(1));
    });

    test('deliver without cash sends no cod fields (the server ignores them for prepaid orders)', () async {
      final (r, adapter, _) = await repo((_) => ok({}));
      await r.deliver('d1');
      final form = adapter.requests.single.body as FormData;
      expect(form.fields.where((f) => f.key.startsWith('cod')), isEmpty);
    });

    test('a 401 on a photo upload refreshes the token and replays the multipart body once', () async {
      var first = true;
      final (r, adapter, store) = await repo((req) {
        if (req.path == '/auth/refresh') return ok({'tokens': tokens('A2', 'R2')});
        if (first) {
          first = false;
          return error(401, 'unauthenticated');
        }
        return ok({});
      });
      final photo = File('${tmp.path}/p.jpg')..writeAsBytesSync([1, 2, 3]);
      await r.deliver('d1', proof: photo);
      expect(adapter.where('POST', '/admin/deliveries/d1/deliver'), hasLength(2));
      expect(store.cached?.accessToken, 'A2');
    });

    test('fail sends one of the reason codes the server accepts', () async {
      final (r, adapter, _) = await repo((_) => ok({}));
      const accepted = ['customer_unreachable', 'customer_absent', 'wrong_address', 'refused', 'damaged', 'access_denied', 'other'];
      await r.fail('d1', 'wrong_address');
      expect(accepted, contains(adapter.requests.single.body['reason_code']));
    });

    test('driver steps and return use the delivery action routes', () async {
      final (r, adapter, _) = await repo((_) => ok({}));
      await r.jobStep('d1', 'out-for-delivery', lat: 25.3, lng: 51.5);
      await r.returnToBranch('d1');
      expect(adapter.requests.map((q) => '${q.method} ${q.path}'), [
        'POST /admin/deliveries/d1/out-for-delivery',
        'POST /admin/deliveries/d1/return',
      ]);
      expect(adapter.requests.first.body, {'latitude': 25.3, 'longitude': 51.5});
    });

    test('create product: server field names, status, translations; photos go one by one with their own timeout', () async {
      final (r, adapter, _) = await repo((req) => ok({'id': req.path.contains('images') ? 'img1' : 'p1'}));
      final id = await r.createProduct(
        sku: 'AB-1',
        price: '120.5',
        titleEn: 'Throw',
        titleAr: 'غطاء',
        text: const ProductDraftText(shortEn: 's', descriptionEn: '<p>d</p>', shortAr: 'ق', descriptionAr: '<p>و</p>'),
        categoryId: 'c1',
        publish: true,
      );
      expect(id, 'p1');
      final body = adapter.requests.single.body as Map<String, dynamic>;
      expect(body['status'], 'active');
      expect(body['price'], '120.5');
      expect(body['category_id'], 'c1');
      expect(body['translations']['ar']['name'], 'غطاء');
      final photo = File('${tmp.path}/p.jpg')..writeAsBytesSync([1]);
      expect(await r.uploadProductImage('p1', photo), 'img1');
      expect(adapter.requests.last.options.sendTimeout, const Duration(seconds: 120));
    });

    test('the AI writer may take longer than a normal call (the server asks Claude up to twice)', () async {
      final (r, adapter, _) = await repo((_) => ok({'name': {'en': 'a', 'ar': 'ب'}, 'en': {}, 'ar': {}}));
      await r.describe(titleEn: 'Throw');
      expect(adapter.requests.single.options.receiveTimeout, greaterThan(const Duration(seconds: 120)));
    });

    test('fleet and queue are asked for the branch on screen', () async {
      final (r, adapter, _) = await repo((_) => ok([]));
      await r.fleet(branchId: 'b1');
      await r.queue('ready', branchId: 'b1');
      expect(adapter.requests.map((q) => q.query['branch_id']), ['b1', 'b1']);
    });

    test('driver models read the server payloads', () {
      final job = FakeOps.job(status: 'failed');
      expect(job.nextAction, 'out-for-delivery');
      expect(job.canReturn, isTrue);
      expect(job.canFail, isFalse);
      expect(FakeOps.job(status: 'out_for_delivery').canFail, isTrue);
      expect(FakeOps.job(status: 'delivered').nextAction, isNull);
      expect(job.address.zone, '24');
    });
  });

  group('push registration', () {
    test('register before start finished still registers (cold start with a saved session)', () async {
      final h = await TestHarness.create(
        tokens: AuthTokens.fromJson(tokens('A1', 'R1')),
        handler: (r) => ok({'ok': true}),
      );
      final messaging = _SlowMessaging();
      final push = PushService(
        messaging: messaging,
        local: FakeLocalNotifier(),
        api: h.read(apiClientProvider),
        prefs: h.read(appPreferencesProvider),
        enabled: true,
        devicesPath: '/admin/devices',
      );
      final start = push.start();
      final register = push.register(); // called while start() is still initialising
      messaging.ready.complete();
      await Future.wait([start, register]);
      expect(h.adapter.where('POST', '/admin/devices').single.body['push_token'], 'tok-1');
      expect(messaging.starts, 1);
      // Registering again (a second sign-in) replaces the token-refresh listener instead of stacking another.
      await push.register();
      expect(messaging.refreshListeners, 1);
    });
  });
}

class _SlowMessaging implements PushMessaging {
  final ready = Completer<void>();
  int starts = 0;
  int refreshListeners = 0;

  @override
  Future<bool> initialize() async {
    starts++;
    await ready.future;
    return true;
  }

  @override
  Future<bool> requestPermission() async => true;

  @override
  Future<String?> getToken() async => 'tok-1';

  @override
  Stream<String> get onTokenRefresh => Stream<String>.multi((c) {
    refreshListeners++;
    c.onCancel = () => refreshListeners--;
  });

  @override
  Stream<PushMessage> get onForegroundMessage => const Stream.empty();

  @override
  Stream<PushMessage> get onMessageOpenedApp => const Stream.empty();

  @override
  Future<PushMessage?> getInitialMessage() async => null;
}
