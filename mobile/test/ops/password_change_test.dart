import 'dart:async';

import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/ops_account.dart';
import 'package:albahar_customer/ops/ops_account_screens.dart';
import 'package:albahar_customer/ops/ops_app.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/ops_session.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import 'ops_test_kit.dart';

const _temp = 'Temp-Passw0rd-1';
const _mine = 'Brand-New-Passw0rd';
const _flagKey = 'ops.must_change_password';

/// The staff API as the backend behaves (`BranchEmployeeAccessTest`, `EnsureStaff`): while the account holds a temporary password
/// every `/admin/*` call but `me` and `me/password` answers `403 password_change_required`; choosing a password clears it.
class _Server {
  _Server({this.mustChange = true, this.permissions = const ['orders.view'], this.driver = false});

  bool mustChange;
  List<String> permissions;
  bool driver;
  String password = _temp;

  /// Lets a test refuse or delay `PUT /admin/me/password` (return null to let the server decide).
  FutureOr<FakeReply?> Function(RecordedRequest r)? onChange;

  FakeHandler get handler => (r) async {
    switch ('${r.method} ${r.path}') {
      case 'POST /admin/auth/login':
        return ok({
          'status': 'authenticated',
          'user': {'id': 'u1'},
          'tokens': tokens('A1', 'R1'),
          'must_change_password': mustChange,
        });
      case 'GET /admin/me':
        return ok({
          'is_super_admin': false,
          'must_change_password': mustChange,
          'permissions': permissions,
          'user': {'id': 'u1'},
        });
      case 'PUT /admin/me/password':
        final custom = await onChange?.call(r);
        if (custom != null) return custom;
        final body = r.body as Map;
        if (body['current_password'] != password) {
          return error(
            422,
            'invalid_password',
            message: 'The provided password is incorrect.',
            errors: {
              'current_password': ['The provided password is incorrect.'],
            },
          );
        }
        password = body['password'] as String;
        mustChange = false;
        return ok({'updated': true, 'must_change_password': false});
      case 'POST /auth/logout':
        return ok({'logged_out': true});
    }
    if (r.path.startsWith('/admin/') && mustChange) {
      return error(403, 'password_change_required', message: 'Choose your own password before you continue.');
    }
    switch ('${r.method} ${r.path}') {
      case 'GET /admin/dispatch/driver':
        return driver
            ? ok({'id': 'u1', 'name': 'Omar', 'on_duty': false, 'open_jobs': 0, 'branches': []})
            : error(403, 'driver_not_eligible', message: 'not a driver');
      case 'GET /admin/dispatch/driver/jobs':
      case 'GET /admin/dispatch/queue':
      case 'GET /admin/dispatch/drivers':
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
      case 'PUT /admin/dispatch/driver/duty':
        return ok({'on_duty': false});
      case 'POST /admin/devices':
      case 'DELETE /admin/devices':
        return ok({'ok': true});
    }
    return error(404, 'not_found');
  };

  List<String> calls(TestHarness h) => [
    for (final r in h.adapter.requests)
      if (r.path != '/app-config') '${r.method} ${r.path}',
  ];
}

List<Override> _overrides(OpsRole role) => [
  opsRoleProvider.overrideWithValue(role),
  pushDevicesPathProvider.overrideWithValue('/admin/devices'),
  driverEventsProvider.overrideWith((ref) => const Stream.empty()),
  staffEventsProvider.overrideWith((ref) => const Stream.empty()),
  staffMovesProvider.overrideWith((ref) => const Stream<Map<String, dynamic>>.empty()),
  driverTrackerProvider.overrideWithValue(FakeTracker(OpsRepository(ApiClient(Dio())))),
];

Future<TestHarness> _app(
  WidgetTester tester,
  _Server server, {
  OpsRole role = OpsRole.staff,
  bool savedSession = false,
  bool rememberedFlag = false,
  Size size = const Size(390, 844),
}) async {
  final h = await TestHarness.create(
    handler: server.handler,
    tokens: savedSession ? AuthTokens.fromJson(tokens('A1', 'R1')) : null,
    prefs: {'locale': 'en', 'onboarding_done': true, if (rememberedFlag) _flagKey: true},
    overrides: _overrides(role),
  );
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(UncontrolledProviderScope(container: h.container, child: const OpsApp()));
  await settle(tester);
  return h;
}

Finder _field(String label) => find.widgetWithText(TextField, label);

Finder _fieldText(String label, String text) => find.descendant(of: _field(label), matching: find.text(text));

Future<void> _signIn(WidgetTester tester, {String who = 'new.hire@albahar.qa', String password = _temp}) async {
  await tester.enterText(_field('Email or mobile number'), who);
  await tester.enterText(_field('Password'), password);
  await tester.tap(find.widgetWithText(FilledButton, 'Sign in'));
  await settle(tester);
}

Future<void> _fill(WidgetTester tester, {String current = _temp, String password = _mine, String? repeat}) async {
  await tester.enterText(_field('Temporary password'), current);
  await tester.enterText(_field('New password'), password);
  await tester.enterText(_field('Repeat new password'), repeat ?? password);
  await tester.pump();
}

Future<void> _save(WidgetTester tester) async {
  await tester.ensureVisible(find.byType(FilledButton)); // on a short phone the button is below the fold
  await tester.tap(find.byType(FilledButton));
  await settle(tester);
}

bool _saveEnabled(WidgetTester tester) => tester.widget<FilledButton>(find.byType(FilledButton)).onPressed != null;

/// How many of the checklist's rules show a tick.
int _ticks(WidgetTester tester) => find.byIcon(Icons.check_circle_rounded).evaluate().length;

/// A stand-in for the session, so the screen alone can be checked (every call is recorded, every refusal can be switched on).
class _FakeSession extends OpsSession {
  _FakeSession(this.auth);
  final OpsAuth auth;
  final changes = <({String current, String password, String confirmation})>[];
  Object? failWith;
  Completer<void>? gate;
  int signOuts = 0;

  /// How many times the screen asked the server whether the password still has to be chosen.
  int rechecks = 0;

  @override
  OpsAuth build() => auth;

  @override
  Future<void> recheck() async => rechecks++;

  @override
  Future<void> changePassword({required String current, required String password, required String confirmation}) async {
    changes.add((current: current, password: password, confirmation: confirmation));
    await gate?.future;
    if (failWith != null) throw failWith!;
  }

  @override
  Future<void> signOut() async => signOuts++;
}

Future<_FakeSession> _pumpScreen(
  WidgetTester tester,
  Widget screen, {
  Variant variant = const Variant('default', Size(390, 844), kEn),
  OpsAuth auth = OpsAuth.passwordChange,
  OpsRole role = OpsRole.staff,
}) async {
  final session = _FakeSession(auth);
  useVariant(tester, variant);
  await tester.pumpWidget(
    opsHost(
      screen,
      FakeOps(),
      variant: variant,
      role: role,
      overrides: [opsSessionProvider.overrideWith(() => session)],
    ),
  );
  await settle(tester);
  return session;
}

ApiException _refused(Map<String, List<String>> errors, {String code = 'validation_failed'}) =>
    apiError(ApiErrorKind.validation, 422, code: code, message: 'The given data was invalid.', errors: errors);

void main() {
  group('what the server says is read', () {
    test('the sign-in answer carries must_change_password (missing means no)', () {
      final yes = OpsLogin.fromJson({'tokens': tokens('A', 'R'), 'must_change_password': true});
      final no = OpsLogin.fromJson({'tokens': tokens('A', 'R'), 'must_change_password': false});
      final old = OpsLogin.fromJson({'tokens': tokens('A', 'R')});
      expect([yes.mustChangePassword, no.mustChangePassword, old.mustChangePassword], [true, false, false]);
      expect(yes.tokens.accessToken, 'A');
    });

    test('me carries must_change_password and the permissions (a super admin holds everything)', () {
      final me = OpsAccount.fromJson({
        'is_super_admin': false,
        'must_change_password': true,
        'permissions': ['orders.view'],
      });
      expect(me.mustChangePassword, isTrue);
      expect(me.permissions, ['orders.view']);
      expect(OpsAccount.fromJson({'is_super_admin': true, 'permissions': <String>[]}).permissions, ['*']);
      expect(OpsAccount.fromJson({'permissions': <String>[]}).mustChangePassword, isFalse);
    });

    test('the sign-in call sends the login name as typed and reads the flag', () async {
      final server = _Server();
      final h = await TestHarness.create(handler: server.handler, overrides: _overrides(OpsRole.staff));
      final login = await h.read(opsRepositoryProvider).login('+974 5512 3456', _temp);
      expect(login.mustChangePassword, isTrue);
      expect(h.adapter.where('POST', '/admin/auth/login').single.body['email'], '+974 5512 3456');
    });

    test('the password change is PUT /admin/me/password with the three fields the server validates', () async {
      final server = _Server();
      final h = await TestHarness.create(
        handler: server.handler,
        tokens: AuthTokens.fromJson(tokens('A1', 'R1')),
        overrides: _overrides(OpsRole.staff),
      );
      await h.read(opsRepositoryProvider).changePassword(current: _temp, password: _mine, confirmation: _mine);
      final put = h.adapter.where('PUT', '/admin/me/password').single;
      expect(put.body, {'current_password': _temp, 'password': _mine, 'password_confirmation': _mine});
      expect(put.header('authorization'), 'Bearer A1');
    });
  });

  group('the rules of a new password (Laravel: min 12, mixed case, a number, different)', () {
    PasswordRules rules(String password, {String current = _temp, String? repeat}) =>
        PasswordRules.check(current: current, password: password, repeat: repeat ?? password);

    test('twelve characters is the lowest length', () {
      expect(rules('Abcdefghij1').longEnough, isFalse);
      expect(rules('Abcdefghij12').longEnough, isTrue);
      expect(rules('').longEnough, isFalse);
    });

    test('both an upper and a lower case letter are needed (Arabic has no case)', () {
      expect(rules('abcdefghijk1').mixedCase, isFalse);
      expect(rules('ABCDEFGHIJK1').mixedCase, isFalse);
      expect(rules('Abcdefghijk1').mixedCase, isTrue);
      expect(rules('كلمةالمرور١٢٣٤').mixedCase, isFalse);
      expect(rules('Éclair-du-matin').mixedCase, isTrue);
    });

    test('a number is needed (any script)', () {
      expect(rules('Abcdefghijkl').hasNumber, isFalse);
      expect(rules('Abcdefghijk7').hasNumber, isTrue);
      expect(rules('Abcdefghijk٧').hasNumber, isTrue);
    });

    test('it must differ from the temporary password', () {
      expect(rules(_temp).differs, isFalse);
      expect(rules(_mine).differs, isTrue);
      expect(rules('x', current: '').differs, isTrue);
      expect(rules('').differs, isFalse);
    });

    test('valid needs every rule and the repeat; strong leaves the repeat out', () {
      expect(rules(_mine).valid, isTrue);
      expect(rules(_mine, repeat: 'other').valid, isFalse);
      expect(rules(_mine, repeat: 'other').strong, isTrue);
      expect(rules('short1A').valid, isFalse);
      expect(rules(_temp).valid, isFalse);
      expect(rules('').matches, isFalse);
    });

    test('a long password counts characters, not bytes (an emoji is one)', () {
      expect(rules('Aa1😀😀😀😀😀😀😀😀😀').longEnough, isTrue);
      expect(rules('Aa1😀😀😀😀😀😀😀😀').longEnough, isFalse);
    });
  });

  group('the login name', () {
    test('an e-mail is only trimmed (never lower-cased)', () {
      expect(normalizeLoginName('  Omar.K@AlBahar.qa '), 'Omar.K@AlBahar.qa');
    });

    test('a number keeps its plus sign and its digits; Arabic-Indic digits become ordinary ones', () {
      expect(normalizeLoginName('+97455123456'), '+97455123456');
      expect(normalizeLoginName('55123456'), '55123456');
      expect(normalizeLoginName('٥٥١٢٣٤٥٦'), '55123456');
      expect(normalizeLoginName('+٩٧٤ ۵۵۱۲۳۴۵۶'), '+974 55123456');
    });
  });

  group('every staff call can say "choose your own password"', () {
    ApiClient client(FakeHandler handler, void Function() onRequired) {
      final c = ApiClient.create(
        config: testConfig,
        tokenStore: MemoryTokenStore(AuthTokens.fromJson(tokens('A1', 'R1'))),
        localeCode: () => 'en',
        onSessionExpired: () async {},
        adapter: FakeAdapter(handler),
      );
      c.dio.interceptors.add(PasswordChangeInterceptor(onRequired));
      return c;
    }

    test('a 403 with that code is reported, and still reaches the caller as an error', () async {
      var told = 0;
      final api = client(
        (_) => error(403, kPasswordChangeRequired, message: 'Choose your own password.'),
        () => told++,
      );
      await expectLater(
        api.get('/admin/orders'),
        throwsA(isA<ApiException>().having((e) => e.code, 'code', kPasswordChangeRequired)),
      );
      expect(told, 1);
    });

    test('any other refusal is not mistaken for it', () async {
      var told = 0;
      for (final reply in [
        error(403, 'forbidden'),
        error(401, kPasswordChangeRequired),
        error(422, 'validation_failed'),
      ]) {
        final api = client((_) => reply, () => told++);
        await expectLater(api.get('/admin/orders'), throwsA(isA<ApiException>()));
      }
      expect(told, 0);
    });

    test('the session listens once, however often it is built, and stops when it is gone', () async {
      final h = await TestHarness.create(handler: _Server().handler, overrides: _overrides(OpsRole.staff));
      int listeners() => h.read(apiClientProvider).dio.interceptors.whereType<PasswordChangeInterceptor>().length;
      h.read(opsSessionProvider);
      expect(listeners(), 1);
      h.container.invalidate(opsSessionProvider);
      h.read(opsSessionProvider);
      expect(listeners(), 1);
    });
  });

  group('the session', () {
    test('a temporary password: tokens kept, the password step is next, the account is not looked at yet', () async {
      final server = _Server(driver: true);
      final h = await TestHarness.create(handler: server.handler, overrides: _overrides(OpsRole.driver));
      final outcome = await h.read(opsSessionProvider.notifier).signIn('new.hire@albahar.qa', _temp);
      expect(outcome, OpsSignIn.passwordChangeRequired);
      expect(h.read(opsSessionProvider), OpsAuth.passwordChange);
      expect(h.tokenStore.cached?.accessToken, 'A1');
      // The driver check (`/admin/dispatch/driver`) is a staff call: it would only be refused.
      expect(server.calls(h), ['POST /admin/auth/login']);
      expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isTrue);
    });

    test('an ordinary sign-in goes straight in and forgets any old flag', () async {
      final server = _Server(mustChange: false);
      final h = await TestHarness.create(
        handler: server.handler,
        prefs: {'locale': 'en', _flagKey: true},
        overrides: _overrides(OpsRole.staff),
      );
      expect(await h.read(opsSessionProvider.notifier).signIn('omar@albahar.qa', _temp), OpsSignIn.success);
      expect(h.read(opsSessionProvider), OpsAuth.signedIn);
      expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isNull);
    });

    test('a mobile number goes to the server as ordinary digits', () async {
      final h = await TestHarness.create(
        handler: _Server(mustChange: false).handler,
        overrides: _overrides(OpsRole.staff),
      );
      await h.read(opsSessionProvider.notifier).signIn('٥٥١٢٣٤٥٦', _temp);
      expect(h.adapter.where('POST', '/admin/auth/login').single.body['email'], '55123456');
    });

    test('a flag raised between sign-in and the account look-up is handled like one at sign-in', () async {
      var first = true;
      final server = _Server(mustChange: false);
      final h = await TestHarness.create(
        handler: (r) async {
          if (r.path == '/admin/me' && first) {
            first = false;
            return error(403, kPasswordChangeRequired);
          }
          return server.handler(r);
        },
        overrides: _overrides(OpsRole.staff),
      );
      expect(
        await h.read(opsSessionProvider.notifier).signIn('omar@albahar.qa', _temp),
        OpsSignIn.passwordChangeRequired,
      );
      expect(h.read(opsSessionProvider), OpsAuth.passwordChange);
      expect(h.tokenStore.cached, isNotNull);
    });

    test('a refused password keeps the person on the password step and hands the field messages back', () async {
      final server = _Server();
      final h = await TestHarness.create(handler: server.handler, overrides: _overrides(OpsRole.staff));
      final session = h.read(opsSessionProvider.notifier);
      await session.signIn('new.hire@albahar.qa', _temp);
      await expectLater(
        session.changePassword(current: 'wrong', password: _mine, confirmation: _mine),
        throwsA(
          isA<ApiException>()
              .having((e) => e.code, 'code', 'invalid_password')
              .having((e) => e.fieldError('current_password'), 'field', isNotNull),
        ),
      );
      expect(h.read(opsSessionProvider), OpsAuth.passwordChange);
      expect(h.tokenStore.cached, isNotNull);
    });

    test('after the new password the same tokens go on working and the app opens', () async {
      final server = _Server();
      final h = await TestHarness.create(handler: server.handler, overrides: _overrides(OpsRole.staff));
      final session = h.read(opsSessionProvider.notifier);
      await session.signIn('new.hire@albahar.qa', _temp);
      await session.changePassword(current: _temp, password: _mine, confirmation: _mine);
      expect(h.read(opsSessionProvider), OpsAuth.signedIn);
      expect(h.tokenStore.cached?.accessToken, 'A1');
      expect(h.tokenStore.cached?.refreshToken, 'R1');
      expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isNull);
      expect(server.calls(h), ['POST /admin/auth/login', 'PUT /admin/me/password', 'GET /admin/me']);
    });

    test('if the server ever answers with a new token pair, it replaces the stored one', () async {
      final server = _Server();
      server.onChange = (_) {
        server.mustChange = false;
        return ok({'updated': true, 'must_change_password': false, 'tokens': tokens('A2', 'R2')});
      };
      final h = await TestHarness.create(handler: server.handler, overrides: _overrides(OpsRole.staff));
      final session = h.read(opsSessionProvider.notifier);
      await session.signIn('new.hire@albahar.qa', _temp);
      await session.changePassword(current: _temp, password: _mine, confirmation: _mine);
      expect(h.tokenStore.cached?.accessToken, 'A2');
      expect(h.tokenStore.cached?.refreshToken, 'R2');
      expect(h.adapter.where('GET', '/admin/me').single.header('authorization'), 'Bearer A2');
      expect(h.read(opsSessionProvider), OpsAuth.signedIn);
    });

    test('an account that is not for this app is let go after its password is chosen, with the reason kept', () async {
      final server = _Server(permissions: const ['catalog.view']);
      final h = await TestHarness.create(handler: server.handler, overrides: _overrides(OpsRole.staff));
      final session = h.read(opsSessionProvider.notifier);
      await session.signIn('new.hire@albahar.qa', _temp);
      await session.changePassword(current: _temp, password: _mine, confirmation: _mine);
      expect(h.read(opsSessionProvider), OpsAuth.signedOut);
      expect(session.refusal, OpsSignIn.notEligible);
      expect(h.tokenStore.cached, isNull);
      expect(h.adapter.where('POST', '/auth/logout'), hasLength(1));
      await session.signIn('new.hire@albahar.qa', _mine);
      expect(session.refusal, isNull);
    });

    test('a look-up that fails right after the password was saved does not undo it', () async {
      final server = _Server();
      final h = await TestHarness.create(handler: server.handler, overrides: _overrides(OpsRole.staff));
      final session = h.read(opsSessionProvider.notifier);
      await session.signIn('new.hire@albahar.qa', _temp);
      h.adapter.failConnection = (r) => r.path == '/admin/me';
      await session.changePassword(current: _temp, password: _mine, confirmation: _mine);
      expect(h.read(opsSessionProvider), OpsAuth.signedIn);
      expect(h.tokenStore.cached, isNotNull);
    });

    test('the server session ending while the password is pending signs out and says why', () async {
      final h = await TestHarness.create(handler: _Server().handler, overrides: _overrides(OpsRole.staff));
      final session = h.read(opsSessionProvider.notifier);
      await session.signIn('new.hire@albahar.qa', _temp);
      h.read(sessionExpirySignalProvider).fire();
      expect(h.read(opsSessionProvider), OpsAuth.signedOut);
      expect(session.expired, isTrue);
      expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isNull);
    });

    test('recheck does nothing unless the password screen is showing', () async {
      final server = _Server(mustChange: false);
      final h = await TestHarness.create(
        handler: server.handler,
        tokens: AuthTokens.fromJson(tokens('A1', 'R1')),
        overrides: _overrides(OpsRole.staff),
      );
      await h.read(opsSessionProvider.notifier).recheck();
      expect(server.calls(h), isEmpty);
    });
  });

  group('first sign-in in the branch app', () {
    testWidgets('the person is taken to "Choose your own password"; nothing else is asked of the server', (
      tester,
    ) async {
      final server = _Server();
      final h = await _app(tester, server);
      expect(find.byType(OpsLoginScreen), findsOneWidget);
      await _signIn(tester);
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(find.byType(OpsLoginScreen), findsNothing);
      expect(find.byType(StaffHome), findsNothing);
      expect(find.text('Choose your own password'), findsOneWidget);
      expect(find.text('Your manager gave you a temporary password. Choose your own to continue.'), findsOneWidget);
      expect(h.tokenStore.cached?.accessToken, 'A1');
      // No order list, no push registration, not even the account look-up: all of it would be refused.
      expect(server.calls(h), ['POST /admin/auth/login']);
    });

    testWidgets('choosing a password opens the app: the same tokens, a confirmation, the remembered flag gone', (
      tester,
    ) async {
      final server = _Server();
      final h = await _app(tester, server);
      await _signIn(tester);
      await _fill(tester);
      await _save(tester);
      expect(find.byType(OpsPasswordScreen), findsNothing);
      expect(find.byType(StaffHome), findsOneWidget);
      expect(find.text('Password updated'), findsOneWidget);
      expect(h.read(opsSessionProvider), OpsAuth.signedIn);
      expect(h.tokenStore.cached?.accessToken, 'A1');
      expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isNull);
      expect(h.adapter.where('PUT', '/admin/me/password').single.body, {
        'current_password': _temp,
        'password': _mine,
        'password_confirmation': _mine,
      });
      // The orders are read only after the password step.
      final calls = server.calls(h);
      expect(calls.indexOf('PUT /admin/me/password'), lessThan(calls.indexOf('GET /admin/dispatch/overview')));
      await tester.pump(const Duration(seconds: 5));
    });

    testWidgets('a mobile number works the same (the server reads it)', (tester) async {
      final server = _Server();
      final h = await _app(tester, server);
      await _signIn(tester, who: '+97455123456');
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(h.adapter.where('POST', '/admin/auth/login').single.body['email'], '+97455123456');
    });

    testWidgets('a driver: the driver check waits for the new password, then the driver app opens', (tester) async {
      final server = _Server(driver: true);
      final h = await _app(tester, server, role: OpsRole.driver);
      await _signIn(tester);
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(server.calls(h), isNot(contains('GET /admin/dispatch/driver')));
      await _fill(tester);
      await _save(tester);
      expect(find.byType(DriverHome), findsOneWidget);
      expect(server.calls(h), contains('GET /admin/dispatch/driver'));
      await tester.pump(const Duration(seconds: 5));
    });

    testWidgets('an account that turns out not to be a driver says so on the sign-in screen after the password step', (
      tester,
    ) async {
      final server = _Server(driver: false);
      final h = await _app(tester, server, role: OpsRole.driver);
      await _signIn(tester);
      await _fill(tester);
      await _save(tester);
      expect(find.byType(OpsLoginScreen), findsOneWidget);
      expect(find.text('This account is not set up as a delivery driver.'), findsOneWidget);
      expect(h.tokenStore.cached, isNull);
    });

    testWidgets(
      'a staff account without orders access says so, plainly, on the sign-in screen after the password step',
      (tester) async {
        final server = _Server(permissions: const ['catalog.view']);
        final h = await _app(tester, server);
        await _signIn(tester);
        await _fill(tester);
        await _save(tester);
        expect(find.byType(OpsLoginScreen), findsOneWidget);
        expect(
          find.text('Your account has no orders access in this app. Use the dashboard on a tablet or computer.'),
          findsOneWidget,
        );
        expect(h.tokenStore.cached, isNull);
        expect(h.adapter.where('POST', '/auth/logout'), hasLength(1));
      },
    );
  });

  group('the guard', () {
    testWidgets('the back button cannot leave the screen, and sign out is the only way out', (tester) async {
      final server = _Server();
      final h = await _app(tester, server);
      await _signIn(tester);
      await tester.binding.handlePopRoute();
      await settle(tester);
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(h.read(opsSessionProvider), OpsAuth.passwordChange);
      await tester.tap(find.text('Sign out'));
      await settle(tester);
      expect(find.byType(OpsLoginScreen), findsOneWidget);
      expect(h.tokenStore.cached, isNull);
      expect(h.adapter.where('POST', '/auth/logout'), hasLength(1));
      expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isNull);
      // Signing out is the person's own doing: no "your session ended".
      expect(find.text('Your session ended. Please sign in again.'), findsNothing);
    });

    testWidgets(
      'a refused call on a saved session (403 password_change_required) puts the screen up, whatever was open',
      (tester) async {
        final server = _Server(mustChange: false, permissions: const ['*']);
        final h = await _app(tester, server, savedSession: true);
        expect(find.byType(StaffHome), findsOneWidget);
        await tester.tap(find.byTooltip('Add product'));
        await settle(tester);
        expect(find.byType(AddProductScreen), findsOneWidget);

        server.mustChange = true; // an administrator reset the password while the app was open
        unawaited(h.read(opsRepositoryProvider).overview().then<void>((_) {}, onError: (Object _) {}));
        await settle(tester);
        await tester.pump(const Duration(seconds: 1)); // the open screen finishes closing
        expect(find.byType(AddProductScreen), findsNothing);
        expect(find.byType(StaffHome), findsNothing);
        expect(find.byType(OpsPasswordScreen), findsOneWidget);
        expect(h.read(opsSessionProvider), OpsAuth.passwordChange);
        expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isTrue);
      },
    );

    testWidgets('a saved session whose first call is refused lands on the screen, not on an error', (tester) async {
      final server = _Server(permissions: const ['*']);
      await _app(tester, server, savedSession: true);
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(find.byType(StaffHome), findsNothing);
      expect(find.text('Choose your own password before you continue.'), findsNothing);
    });

    testWidgets('the next launch opens the screen at once (the phone remembers), then asks the server', (tester) async {
      final server = _Server();
      final h = await _app(tester, server, savedSession: true, rememberedFlag: true);
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(server.calls(h), ['GET /admin/me']);
      expect(h.read(opsSessionProvider), OpsAuth.passwordChange);
      expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isTrue);
    });

    testWidgets('a password chosen elsewhere (the dashboard) lets the person straight in on the next launch', (
      tester,
    ) async {
      final server = _Server(mustChange: false);
      final h = await _app(tester, server, savedSession: true, rememberedFlag: true);
      expect(find.byType(OpsPasswordScreen), findsNothing);
      expect(find.byType(StaffHome), findsOneWidget);
      expect(h.read(sharedPreferencesProvider).getBool(_flagKey), isNull);
    });

    testWidgets('no network on that launch: the screen stays, and nothing breaks', (tester) async {
      final server = _Server();
      final h = await TestHarness.create(
        handler: server.handler,
        tokens: AuthTokens.fromJson(tokens('A1', 'R1')),
        prefs: {'locale': 'en', 'onboarding_done': true, _flagKey: true},
        overrides: _overrides(OpsRole.staff),
      );
      h.adapter.failConnection = (r) => r.path == '/admin/me';
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(UncontrolledProviderScope(container: h.container, child: const OpsApp()));
      await settle(tester);
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });

  group('the screen', () {
    testWidgets('the checklist ticks each rule as it is met, and Save waits for all of them and the repeat', (
      tester,
    ) async {
      await _pumpScreen(tester, const OpsPasswordScreen());
      expect(find.text('Your new password needs'), findsOneWidget);
      expect(_ticks(tester), 0);
      expect(_saveEnabled(tester), isFalse);

      await tester.enterText(_field('New password'), 'abcdefghijkl');
      await tester.pump();
      expect(_ticks(tester), 2); // long enough, different (no temporary password typed yet)
      await tester.enterText(_field('New password'), 'Abcdefghijkl');
      await tester.pump();
      expect(_ticks(tester), 3);
      await tester.enterText(_field('New password'), 'Abcdefghijk1');
      await tester.pump();
      expect(_ticks(tester), 4);

      await tester.enterText(_field('Temporary password'), _temp);
      await tester.enterText(_field('Repeat new password'), 'Abcdefghijk1');
      await tester.pump();
      expect(_saveEnabled(tester), isTrue);

      await tester.enterText(_field('New password'), _temp); // the same as the temporary one: the last rule drops
      await tester.enterText(_field('Repeat new password'), _temp);
      await tester.pump();
      expect(_ticks(tester), 3);
      expect(_saveEnabled(tester), isFalse);
    });

    testWidgets('each rule is read out as checked or not (screen readers)', (tester) async {
      final handle = tester.ensureSemantics();
      await _pumpScreen(tester, const OpsPasswordScreen());
      await tester.enterText(_field('New password'), 'Abcdefghijk1');
      await tester.pump();
      for (final rule in ['At least 12 characters', 'An upper case and a lower case letter', 'A number']) {
        expect(
          tester.getSemantics(find.text(rule)),
          matchesSemantics(label: rule, hasCheckedState: true, isChecked: true),
        );
      }
      handle.dispose();
    });

    testWidgets('the repeat field complains only once it can no longer match, and clears when it does', (tester) async {
      await _pumpScreen(tester, const OpsPasswordScreen());
      await tester.enterText(_field('New password'), _mine);
      await tester.enterText(_field('Repeat new password'), 'Brand-New');
      await tester.pump();
      expect(find.text('Passwords do not match.'), findsNothing); // still typing it
      await tester.enterText(_field('Repeat new password'), 'Brand-Old');
      await tester.pump();
      expect(_fieldText('Repeat new password', 'Passwords do not match.'), findsOneWidget);
      await tester.enterText(_field('Repeat new password'), _mine);
      await tester.pump();
      expect(find.text('Passwords do not match.'), findsNothing);
    });

    testWidgets('Save sends what was typed, shows progress, and a second tap or "done" sends nothing more', (
      tester,
    ) async {
      final session = await _pumpScreen(tester, const OpsPasswordScreen());
      session.gate = Completer<void>();
      await _fill(tester);
      await tester.tap(find.byType(FilledButton));
      await tester.pump();
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
      expect(_saveEnabled(tester), isFalse);
      await tester.tap(find.byType(FilledButton), warnIfMissed: false);
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pump(const Duration(milliseconds: 100));
      expect(session.changes, hasLength(1));
      expect(session.changes.single, (current: _temp, password: _mine, confirmation: _mine));
      // Locked while it works: no typing, no signing out.
      expect(tester.widget<TextField>(_field('New password')).enabled, isFalse);
      final signOut = find.ancestor(of: find.text('Sign out'), matching: find.bySubtype<TextButton>());
      expect(tester.widget<TextButton>(signOut).onPressed, isNull);
      session.gate!.complete();
      await settle(tester);
    });

    testWidgets('"done" on the keyboard saves too, once everything is valid', (tester) async {
      final session = await _pumpScreen(tester, const OpsPasswordScreen());
      await _fill(tester);
      await tester.showKeyboard(_field('Repeat new password'));
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await settle(tester);
      expect(session.changes, hasLength(1));
    });

    testWidgets('"done" does nothing while something is missing', (tester) async {
      final session = await _pumpScreen(tester, const OpsPasswordScreen());
      await _fill(tester, password: 'short1A');
      await tester.showKeyboard(_field('Repeat new password'));
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await settle(tester);
      expect(session.changes, isEmpty);
    });

    testWidgets('each field shows and hides on its own, with its own name for screen readers', (tester) async {
      await _pumpScreen(tester, const OpsPasswordScreen());
      for (final f in ['Temporary password', 'New password', 'Repeat new password']) {
        expect(tester.widget<TextField>(_field(f)).obscureText, isTrue);
      }
      await tester.tap(find.byTooltip('Show new password'));
      await tester.pump();
      expect(tester.widget<TextField>(_field('New password')).obscureText, isFalse);
      expect(tester.widget<TextField>(_field('Temporary password')).obscureText, isTrue);
      expect(tester.widget<TextField>(_field('Repeat new password')).obscureText, isTrue);
      expect(find.byTooltip('Hide new password'), findsOneWidget);
      expect(find.byTooltip('Show temporary password'), findsOneWidget);
      expect(find.byTooltip('Show repeated password'), findsOneWidget);
      await tester.tap(find.byTooltip('Hide new password'));
      await tester.pump();
      expect(tester.widget<TextField>(_field('New password')).obscureText, isTrue);
    });

    testWidgets('the fields are ready for password managers and never correct what is typed', (tester) async {
      await _pumpScreen(tester, const OpsPasswordScreen());
      final current = tester.widget<TextField>(_field('Temporary password'));
      final fresh = tester.widget<TextField>(_field('New password'));
      final repeat = tester.widget<TextField>(_field('Repeat new password'));
      expect(current.autofillHints, [AutofillHints.password]);
      expect(fresh.autofillHints, [AutofillHints.newPassword]);
      expect(repeat.autofillHints, [AutofillHints.newPassword]);
      for (final f in [current, fresh, repeat]) {
        expect(f.autocorrect, isFalse);
        expect(f.enableSuggestions, isFalse);
        expect(f.obscureText, isTrue);
      }
      expect(current.textInputAction, TextInputAction.next);
      expect(repeat.textInputAction, TextInputAction.done);
    });

    testWidgets('the sign-out link is there, and signs out', (tester) async {
      final session = await _pumpScreen(tester, const OpsPasswordScreen());
      await tester.tap(find.text('Sign out'));
      await settle(tester);
      expect(session.signOuts, 1);
    });

    group('what the server refuses is shown next to the field, in plain words', () {
      testWidgets(
        'a wrong temporary password: one clear sentence under that field, the form kept, that field focused',
        (tester) async {
          final session = await _pumpScreen(tester, const OpsPasswordScreen());
          session.failWith = _refused({
            'current_password': ['The provided password is incorrect.'],
          }, code: 'invalid_password');
          await _fill(tester);
          await _save(tester);
          expect(
            _fieldText(
              'Temporary password',
              'That is not the temporary password. Check the one your manager gave you.',
            ),
            findsOneWidget,
          );
          expect(find.text('The provided password is incorrect.'), findsNothing);
          expect(tester.widget<TextField>(_field('Temporary password')).controller?.text, _temp);
          expect(tester.widget<TextField>(_field('New password')).controller?.text, _mine);
          expect(tester.widget<TextField>(_field('Temporary password')).focusNode?.hasFocus, isTrue);
          expect(_saveEnabled(tester), isTrue); // may try again
          // Typing in the field takes its message away.
          await tester.enterText(_field('Temporary password'), '${_temp}2');
          await tester.pump();
          expect(find.textContaining('That is not the temporary password'), findsNothing);
        },
      );

      testWidgets('a refused new password: the server\'s sentence sits under the new-password field', (tester) async {
        final session = await _pumpScreen(tester, const OpsPasswordScreen());
        session.failWith = _refused({
          'password': ['The password field must be at least 12 characters.'],
        });
        await _fill(tester);
        await _save(tester);
        expect(_fieldText('New password', 'The password field must be at least 12 characters.'), findsOneWidget);
        expect(tester.widget<TextField>(_field('New password')).focusNode?.hasFocus, isTrue);
      });

      testWidgets('a confirmation the server did not accept sits under the repeat field', (tester) async {
        final session = await _pumpScreen(tester, const OpsPasswordScreen());
        session.failWith = _refused({
          'password_confirmation': ['The password confirmation does not match.'],
        });
        await _fill(tester);
        await _save(tester);
        expect(_fieldText('Repeat new password', 'The password confirmation does not match.'), findsOneWidget);
      });

      testWidgets('several fields at once are all shown', (tester) async {
        final session = await _pumpScreen(tester, const OpsPasswordScreen());
        session.failWith = _refused({
          'current_password': ['The provided password is incorrect.'],
          'password': ['The password field and current password must be different.'],
        });
        await _fill(tester);
        await _save(tester);
        expect(_fieldText('Temporary password', 'The provided password is incorrect.'), findsOneWidget);
        expect(
          _fieldText('New password', 'The password field and current password must be different.'),
          findsOneWidget,
        );
      });

      testWidgets('a refusal about no field in particular is shown above the button', (tester) async {
        final session = await _pumpScreen(tester, const OpsPasswordScreen());
        session.failWith = _refused({});
        await _fill(tester);
        await _save(tester);
        expect(find.text('The given data was invalid.'), findsOneWidget);
        expect(_saveEnabled(tester), isTrue);
      });

      testWidgets('no network: a plain sentence, nothing typed is lost, trying again works', (tester) async {
        final session = await _pumpScreen(tester, const OpsPasswordScreen());
        session.failWith = apiError(ApiErrorKind.offline, 0);
        await _fill(tester);
        await _save(tester);
        expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
        expect(tester.widget<TextField>(_field('New password')).controller?.text, _mine);
        expect(_saveEnabled(tester), isTrue);
        session.failWith = null;
        await _save(tester);
        expect(session.changes, hasLength(2));
        expect(find.text('You appear to be offline. Check your connection and try again.'), findsNothing);
      });

      for (final failure in [
        apiError(ApiErrorKind.offline, 0),
        apiError(ApiErrorKind.timeout, 0),
        apiError(ApiErrorKind.server, 502),
        apiError(ApiErrorKind.rateLimited, 429, retryAfter: const Duration(seconds: 5)),
      ]) {
        testWidgets(
          '${failure.kind}: the server is asked once whether the change went through (the answer may be lost)',
          (tester) async {
            final session = await _pumpScreen(tester, const OpsPasswordScreen());
            session.failWith = failure;
            await _fill(tester);
            await _save(tester);
            expect(session.rechecks, 1);
            await _save(tester);
            expect(session.rechecks, 2, reason: 'once per failed try, never in a loop');
          },
        );
      }

      testWidgets('a refusal of what was typed (a wrong or weak password) never asks: the server answered', (
        tester,
      ) async {
        final session = await _pumpScreen(tester, const OpsPasswordScreen());
        session.failWith = _refused({
          'current_password': ['The provided password is incorrect.'],
        }, code: 'invalid_password');
        await _fill(tester);
        await _save(tester);
        session.failWith = _refused({
          'password': ['The password must be at least 12 characters.'],
        });
        await _save(tester);
        expect(session.rechecks, 0);
      });

      testWidgets('too many tries: the wait is said', (tester) async {
        final session = await _pumpScreen(tester, const OpsPasswordScreen());
        session.failWith = apiError(ApiErrorKind.rateLimited, 429, retryAfter: const Duration(seconds: 45));
        await _fill(tester);
        await _save(tester);
        expect(find.textContaining('45'), findsOneWidget);
      });

      testWidgets('a server problem is not blamed on the password', (tester) async {
        final session = await _pumpScreen(tester, const OpsPasswordScreen());
        session.failWith = apiError(ApiErrorKind.server, 500);
        await _fill(tester);
        await _save(tester);
        expect(find.text('Our service is having a moment. Please try again shortly.'), findsOneWidget);
        expect(find.textContaining('That is not the temporary password'), findsNothing);
      });
    });

    testWidgets('the same refusals through the real session: the wrong temporary password is not a sign-out', (
      tester,
    ) async {
      final server = _Server();
      final h = await _app(tester, server);
      await _signIn(tester);
      await _fill(tester, current: 'not-my-temp-1');
      await _save(tester);
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(
        _fieldText('Temporary password', 'That is not the temporary password. Check the one your manager gave you.'),
        findsOneWidget,
      );
      expect(h.tokenStore.cached, isNotNull);
      expect(server.mustChange, isTrue);
      await _fill(tester);
      await _save(tester);
      expect(find.byType(StaffHome), findsOneWidget);
      await tester.pump(const Duration(seconds: 5));
    });

    testWidgets('no network on the real call: the person stays, the form is kept, and can try again', (tester) async {
      final server = _Server();
      final h = await _app(tester, server);
      await _signIn(tester);
      await _fill(tester);
      h.adapter.failConnection = (r) => r.method == 'PUT';
      await _save(tester);
      expect(find.text('You appear to be offline. Check your connection and try again.'), findsOneWidget);
      expect(h.read(opsSessionProvider), OpsAuth.passwordChange);
      h.adapter.failConnection = null;
      await _save(tester);
      expect(find.byType(StaffHome), findsOneWidget);
      await tester.pump(const Duration(seconds: 5));
    });

    testWidgets('the change reached the server but its answer was lost: the screen asks again and the app opens', (
      tester,
    ) async {
      final server = _Server();
      // The server saves the new password and clears the flag, then the connection breaks before the phone hears it.
      server.onChange = (r) {
        server.password = (r.body as Map)['password'] as String;
        server.mustChange = false;
        return error(502, 'bad_gateway');
      };
      final h = await _app(tester, server);
      await _signIn(tester);
      await _fill(tester);
      await _save(tester);
      expect(find.byType(StaffHome), findsOneWidget, reason: 'retrying would be refused for the old password for ever');
      expect(h.read(opsSessionProvider), OpsAuth.signedIn);
      expect(h.adapter.where('PUT', '/admin/me/password'), hasLength(1));
      await tester.pump(const Duration(seconds: 5));
    });

    testWidgets('a lost answer while the flag is still set: the person stays and can try again', (tester) async {
      final server = _Server();
      server.onChange = (r) => error(502, 'bad_gateway');
      final h = await _app(tester, server);
      await _signIn(tester);
      await _fill(tester);
      await _save(tester);
      expect(find.byType(OpsPasswordScreen), findsOneWidget);
      expect(find.text('Our service is having a moment. Please try again shortly.'), findsOneWidget);
      expect(h.read(opsSessionProvider), OpsAuth.passwordChange);
      await tester.pump(const Duration(seconds: 5));
    });

    testWidgets('a double tap on the real screen sends one PUT', (tester) async {
      final server = _Server();
      final gate = Completer<void>();
      server.onChange = (_) async {
        await gate.future;
        return null;
      };
      final h = await _app(tester, server);
      await _signIn(tester);
      await _fill(tester);
      await tester.tap(find.byType(FilledButton));
      await tester.pump();
      await tester.tap(find.byType(FilledButton), warnIfMissed: false);
      await tester.testTextInput.receiveAction(TextInputAction.done);
      await tester.pump(const Duration(milliseconds: 100));
      expect(h.adapter.where('PUT', '/admin/me/password'), hasLength(1));
      gate.complete();
      await settle(tester);
      expect(find.byType(StaffHome), findsOneWidget);
      await tester.pump(const Duration(seconds: 5));
    });
  });

  group('the login screen takes an e-mail or a mobile number', () {
    testWidgets('the label says so; the keyboard has the digits and the plus sign; nothing is changed as it is typed', (
      tester,
    ) async {
      await _app(tester, _Server());
      final field = tester.widget<TextField>(_field('Email or mobile number'));
      expect(field.keyboardType, TextInputType.emailAddress);
      expect(field.autofillHints, containsAll([AutofillHints.username, AutofillHints.email]));
      expect(field.autocorrect, isFalse);
      expect(field.enableSuggestions, isFalse);
      await tester.enterText(_field('Email or mobile number'), 'Omar.K@AlBahar.qa');
      expect(tester.widget<TextField>(_field('Email or mobile number')).controller?.text, 'Omar.K@AlBahar.qa');
      await tester.enterText(_field('Email or mobile number'), '+974 5512 3456');
      expect(tester.widget<TextField>(_field('Email or mobile number')).controller?.text, '+97455123456');
    });

    testWidgets('an e-mail goes to the server exactly as typed (only trimmed)', (tester) async {
      final server = _Server(mustChange: false);
      final h = await _app(tester, server);
      await _signIn(tester, who: 'Omar.K@AlBahar.qa');
      expect(h.adapter.where('POST', '/admin/auth/login').single.body['email'], 'Omar.K@AlBahar.qa');
    });

    testWidgets('Arabic digits are sent as ordinary digits', (tester) async {
      final server = _Server(mustChange: false);
      final h = await _app(tester, server);
      await _signIn(tester, who: '٥٥١٢٣٤٥٦');
      expect(h.adapter.where('POST', '/admin/auth/login').single.body['email'], '55123456');
    });

    testWidgets('a refused number shows the phone message of the server', (tester) async {
      final h = await TestHarness.create(
        handler: (r) => r.path == '/admin/auth/login'
            ? error(
                422,
                'validation_failed',
                message: 'The given data was invalid.',
                errors: {
                  'phone': ['That mobile number is not valid.'],
                },
              )
            : error(404, 'not_found'),
        overrides: _overrides(OpsRole.staff),
      );
      tester.view.physicalSize = const Size(390, 844);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(UncontrolledProviderScope(container: h.container, child: const OpsApp()));
      await settle(tester);
      await _signIn(tester, who: '12345');
      expect(find.text('That mobile number is not valid.'), findsOneWidget);
    });
  });

  group('an account with no orders access', () {
    testWidgets('a saved session gets a plain message and a Sign out button, not the order screens', (tester) async {
      final server = _Server(mustChange: false, permissions: const ['catalog.view']);
      final h = await _app(tester, server, savedSession: true);
      expect(find.byType(OpsNoAccessScreen), findsOneWidget);
      expect(find.byType(StaffHome), findsOneWidget); // the screen is what StaffHome shows
      expect(find.text('No access to orders'), findsOneWidget);
      expect(
        find.text('Your account has no orders access in this app. Use the dashboard on a tablet or computer.'),
        findsOneWidget,
      );
      expect(find.byType(TabBar), findsNothing); // none of the order screens is built
      await tester.tap(find.text('Sign out'));
      await settle(tester);
      expect(find.byType(OpsLoginScreen), findsOneWidget);
      expect(h.tokenStore.cached, isNull);
      expect(h.adapter.where('POST', '/auth/logout'), hasLength(1));
    });

    testWidgets('"Check again" reads the account again, and the orders open once the manager has granted access', (
      tester,
    ) async {
      final server = _Server(mustChange: false, permissions: const ['catalog.view']);
      final h = await _app(tester, server, savedSession: true);
      expect(find.byType(OpsNoAccessScreen), findsOneWidget);
      server.permissions = const ['orders.view'];
      await tester.tap(find.text('Check again'));
      await settle(tester);
      expect(h.adapter.where('GET', '/admin/me'), hasLength(2));
      expect(find.byType(OpsNoAccessScreen), findsNothing);
      expect(find.text('Main'), findsOneWidget);
    });

    testWidgets('"Check again" while the manager has not changed anything keeps the message', (tester) async {
      final server = _Server(mustChange: false, permissions: const ['catalog.view']);
      await _app(tester, server, savedSession: true);
      await tester.tap(find.text('Check again'));
      await settle(tester);
      expect(find.byType(OpsNoAccessScreen), findsOneWidget);
    });
  });

  group('every phone, language, text size and theme', () {
    const phone320 = Size(320, 568);
    final matrix = <Variant>[
      const Variant('320 en', phone320, kEn),
      const Variant('320 ar', phone320, kAr),
      const Variant('320 en 2x text', phone320, kEn, scale: 2),
      const Variant('320 ar 2x text dark', phone320, kAr, scale: 2, dark: true),
      const Variant('320 en dark', phone320, kEn, dark: true),
      const Variant('360 ar 1.3x', Size(360, 640), kAr, scale: 1.3),
      const Variant('768 en tablet', Size(768, 1024), kEn),
    ];

    for (final v in matrix) {
      testWidgets('"Choose your own password" lays out without overflow, with errors showing: ${v.name}', (
        tester,
      ) async {
        final handle = tester.ensureSemantics();
        final session = await _pumpScreen(tester, const OpsPasswordScreen(), variant: v);
        expect(tester.takeException(), isNull);
        final title = v.rtl ? 'اختر كلمة مرورك الخاصة' : 'Choose your own password';
        expect(find.text(title), findsOneWidget);
        expect(
          Directionality.of(tester.element(find.byType(OpsPasswordScreen))),
          v.rtl ? TextDirection.rtl : TextDirection.ltr,
        );

        // Everything at once: the checklist ticked, a message under each field, and a banner.
        final fields = find.byType(TextField);
        await tester.enterText(fields.at(0), _temp);
        await tester.enterText(fields.at(1), _mine);
        await tester.enterText(fields.at(2), _mine);
        await tester.pump();
        session.failWith = _refused({
          'current_password': ['The provided password is incorrect, please check it carefully and type it again.'],
          'password': ['The password field must contain at least one uppercase and one lowercase letter.'],
          'password_confirmation': ['The password confirmation does not match the new password you typed above.'],
        });
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await settle(tester);
        expect(tester.takeException(), isNull);
        session.failWith = apiError(ApiErrorKind.offline, 0);
        await tester.enterText(fields.at(0), '${_temp}x');
        await tester.pump();
        await tester.ensureVisible(find.byType(FilledButton));
        await tester.tap(find.byType(FilledButton));
        await settle(tester);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });

      testWidgets('the "no orders access" screen lays out without overflow: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _pumpScreen(
          tester,
          OpsNoAccessScreen(onCheckAgain: () {}),
          variant: v,
          auth: OpsAuth.signedIn,
        );
        expect(tester.takeException(), isNull);
        expect(find.text(v.rtl ? 'لا توجد صلاحية للطلبات' : 'No access to orders'), findsOneWidget);
        expect(find.text(v.rtl ? 'تحقق مرة أخرى' : 'Check again'), findsOneWidget);
        expect(find.text(v.rtl ? 'تسجيل الخروج' : 'Sign out'), findsOneWidget);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        handle.dispose();
      });
    }

    for (final v in [matrix[1], matrix[2], matrix[3]]) {
      testWidgets('the sign-in screen with its new label lays out without overflow: ${v.name}', (tester) async {
        final h = await TestHarness.create(
          handler: _Server().handler,
          prefs: {'locale': v.locale.languageCode, 'onboarding_done': true},
          overrides: _overrides(OpsRole.staff),
        );
        tester.view.physicalSize = v.size;
        tester.view.devicePixelRatio = 1;
        tester.platformDispatcher.textScaleFactorTestValue = v.scale;
        addTearDown(tester.view.reset);
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        await tester.pumpWidget(UncontrolledProviderScope(container: h.container, child: const OpsApp()));
        await settle(tester);
        expect(tester.takeException(), isNull);
        expect(find.text(v.rtl ? 'البريد الإلكتروني أو رقم الجوال' : 'Email or mobile number'), findsOneWidget);
      });
    }

    testWidgets('the whole forced flow in Arabic: sign in, choose a password, the app opens', (tester) async {
      final server = _Server();
      final h = await TestHarness.create(
        handler: server.handler,
        prefs: {'locale': 'ar', 'onboarding_done': true},
        overrides: _overrides(OpsRole.staff),
      );
      tester.view.physicalSize = const Size(320, 568);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(UncontrolledProviderScope(container: h.container, child: const OpsApp()));
      await settle(tester);
      await tester.enterText(_field('البريد الإلكتروني أو رقم الجوال'), 'new.hire@albahar.qa');
      await tester.enterText(_field('كلمة المرور'), _temp);
      await tester.tap(find.byType(FilledButton));
      await settle(tester);
      expect(find.text('اختر كلمة مرورك الخاصة'), findsOneWidget);
      expect(find.text('أعطاك مديرك كلمة مرور مؤقتة. اختر كلمة مرورك الخاصة للمتابعة.'), findsOneWidget);
      await tester.enterText(_field('كلمة المرور المؤقتة'), _temp);
      await tester.enterText(_field('كلمة المرور الجديدة'), _mine);
      await tester.enterText(_field('أعد كتابة كلمة المرور الجديدة'), _mine);
      await tester.pump();
      await tester.ensureVisible(find.byType(FilledButton));
      await tester.tap(find.byType(FilledButton));
      await settle(tester);
      expect(find.byType(StaffHome), findsOneWidget);
      expect(tester.takeException(), isNull);
      await tester.pump(const Duration(seconds: 5));
    });
  });
}
