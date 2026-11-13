@Timeout(Duration(minutes: 5))
library;

import 'dart:async';

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import 'customer_fixtures.dart';
import 'test_fonts.dart';

AppLocalizations get en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get ar => lookupAppLocalizations(const Locale('ar'));

/// Boots the real app (390x844, optional text scale) on top of [handler].
Future<TestHarness> boot(
  WidgetTester t,
  FakeHandler handler, {
  bool signedIn = false,
  String locale = 'en',
  double textScale = 1.0,
}) async {
  t.view.physicalSize = const Size(390, 844);
  t.view.devicePixelRatio = 1;
  t.platformDispatcher.textScaleFactorTestValue = textScale;
  addTearDown(t.view.reset);
  addTearDown(t.platformDispatcher.clearAllTestValues);
  final h = await TestHarness.create(
    tokens: signedIn ? signedInTokens : null,
    prefs: {'locale': locale, 'onboarding_done': true},
    handler: handler,
  );
  await t.pumpWidget(
    UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
  );
  await t.pump(const Duration(milliseconds: 1700));
  await t.pump(const Duration(milliseconds: 400));
  return h;
}

Future<void> go(WidgetTester t, TestHarness h, String route) async {
  h.read(routerProvider).go(route);
  await settle(t);
}

Future<void> settle(WidgetTester t, [int frames = 4]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 150));
  }
}

Finder field(String label) => find.widgetWithText(TextFormField, label);

Future<void> type(WidgetTester t, String label, String text) async {
  await t.enterText(field(label), text);
  await t.pump();
}

Future<void> tapText(WidgetTester t, String text, {bool last = false}) async {
  final target = last ? find.text(text).last : find.text(text).first;
  await t.ensureVisible(target);
  await t.pump();
  await t.tap(target);
  await settle(t);
}

Future<void> teardown(WidgetTester t) async {
  await t.pumpWidget(const SizedBox.shrink());
  await t.pump(const Duration(seconds: 10));
}

Map<String, dynamic> authenticated() => {'status': 'authenticated', 'user': userJson(), 'tokens': tokens('A2', 'R2')};

void main() {
  setUpAll(loadAppFonts);

  group('Phone sign-in', () {
    testWidgets('an invalid number shows a message (EN and AR) and sends nothing', (t) async {
      for (final (locale, l10n) in [('en', en), ('ar', ar)]) {
        final h = await boot(t, routes({}), locale: locale);
        await go(t, h, '/login');
        await type(t, l10n.phoneLabel, '123');
        await tapText(t, l10n.sendCode);
        expect(find.text(l10n.phoneInvalid), findsOneWidget);
        expect(h.adapter.where('POST', '/auth/otp/request'), isEmpty);
        await teardown(t);
      }
    });

    testWidgets('spaces and Arabic digits normalise to +974 and a double submit sends one request', (t) async {
      final gate = Completer<FakeReply>();
      final h = await boot(t, routes({'POST /auth/otp/request': (_) => gate.future}));
      await go(t, h, '/login');
      await type(t, en.phoneLabel, '٥٥١٢ ٣٤٥٦');
      await t.tap(find.text(en.sendCode));
      await t.tap(find.text(en.sendCode));
      await t.pump();
      await t.testTextInput.receiveAction(TextInputAction.done);
      await settle(t, 2);
      expect(h.adapter.where('POST', '/auth/otp/request'), hasLength(1));
      expect(h.adapter.where('POST', '/auth/otp/request').single.body['phone'], '+97455123456');
      gate.complete(ok({'sent': true, 'resend_in': 60}));
      await settle(t);
      await teardown(t);
    });

    testWidgets('422 on the phone is shown under the field; 429 shows the wait', (t) async {
      var calls = 0;
      final h = await boot(
        t,
        routes({
          'POST /auth/otp/request': (_) => ++calls == 1
              ? error(
                  422,
                  'validation_failed',
                  errors: {
                    'phone': ['The phone is not supported.'],
                  },
                )
              : error(429, 'too_many_requests', context: {'seconds': 42}),
        }),
      );
      await go(t, h, '/login');
      await type(t, en.phoneLabel, '55123456');
      await tapText(t, en.sendCode);
      expect(find.text('The phone is not supported.'), findsOneWidget);
      await tapText(t, en.sendCode);
      expect(find.text(en.errorRateLimited(42)), findsOneWidget);
      await teardown(t);
    });

    testWidgets('offline shows the offline message on the form', (t) async {
      final h = await boot(t, routes({}));
      h.adapter.failConnection = (r) => r.path == '/auth/otp/request';
      await go(t, h, '/login');
      await type(t, en.phoneLabel, '55123456');
      await tapText(t, en.sendCode);
      expect(find.text(en.errorOffline), findsOneWidget);
      await teardown(t);
    });
  });

  group('OTP', () {
    Future<TestHarness> toOtp(WidgetTester t, FakeHandler verify, {int resendIn = 60}) async {
      final h = await boot(
        t,
        routes({
          'POST /auth/otp/request': (_) => ok({'sent': true, 'resend_in': resendIn}),
          'POST /auth/otp/verify': verify,
        }),
      );
      await go(t, h, '/login');
      await type(t, en.phoneLabel, '55123456');
      await tapText(t, en.sendCode);
      return h;
    }

    testWidgets('resend is locked until the timer ends, then asks for a new code', (t) async {
      final h = await toOtp(t, (_) => error(422, 'invalid_code'));
      expect(find.text(en.resendIn(60)), findsOneWidget);
      final locked = t.widget<TextButton>(find.widgetWithText(TextButton, en.resendIn(60)));
      expect(locked.onPressed, isNull);
      await t.pump(const Duration(seconds: 61));
      await t.tap(find.text(en.resendCode));
      await settle(t);
      expect(h.adapter.where('POST', '/auth/otp/request'), hasLength(2));
      expect(find.text(en.resendIn(60)), findsOneWidget, reason: 'the timer restarts');
      await teardown(t);
    });

    testWidgets('a wrong code is shown under the field; 6 digits verify automatically, once', (t) async {
      final h = await toOtp(
        t,
        (_) => error(
          422,
          'invalid_code',
          errors: {
            'code': ['That code is not right.'],
          },
        ),
      );
      await type(t, en.otpCodeLabel, '123456');
      await settle(t);
      expect(find.text('That code is not right.'), findsOneWidget);
      expect(h.adapter.where('POST', '/auth/otp/verify'), hasLength(1));
      expect(h.adapter.where('POST', '/auth/otp/verify').single.body['code'], '123456');
      await teardown(t);
    });

    testWidgets('Arabic digits are accepted and sent as ASCII', (t) async {
      final h = await toOtp(t, (_) => error(422, 'invalid_code'));
      await type(t, en.otpCodeLabel, '١٢٣٤٥٦');
      await settle(t);
      expect(h.adapter.where('POST', '/auth/otp/verify').single.body['code'], '123456');
      await teardown(t);
    });

    testWidgets('429 on verify starts the lockout countdown', (t) async {
      await toOtp(t, (_) => error(429, 'too_many_attempts', context: {'seconds': 30}));
      await type(t, en.otpCodeLabel, '123456');
      await settle(t);
      expect(find.text(en.resendIn(30)), findsOneWidget);
      await teardown(t);
    });
  });

  group('Registration', () {
    Future<TestHarness> toRegister(WidgetTester t, {FakeHandler? register}) async {
      final h = await boot(
        t,
        routes({
          'POST /auth/otp/request': (_) => ok({'sent': true, 'resend_in': 60}),
          'POST /auth/otp/verify': (_) =>
              ok({'status': 'registration_required', 'registration_token': 'rt', 'expires_in': 900}),
          'POST /auth/register': register ?? (_) => ok(authenticated(), status: 201),
          'GET /me/referral': (_) => ok({'enabled': false}),
        }),
      );
      await go(t, h, '/login');
      await type(t, en.phoneLabel, '55123456');
      await tapText(t, en.sendCode);
      await type(t, en.otpCodeLabel, '123456');
      await settle(t, 6);
      return h;
    }

    testWidgets('validation messages come first, nothing is sent', (t) async {
      final h = await toRegister(t);
      await tapText(t, en.createAccount);
      expect(find.text(en.fieldRequired), findsOneWidget);
      await type(t, en.nameLabel, '  ');
      await type(t, '${en.emailLabel} (${en.optional})', 'not-an-email');
      await type(t, '${en.passwordLabel} (${en.optional})', 'abcdefgh');
      await tapText(t, en.createAccount);
      expect(find.text(en.fieldRequired), findsOneWidget);
      expect(find.text(en.emailInvalid), findsOneWidget);
      expect(find.text(en.passwordTooShort), findsWidgets);
      expect(h.adapter.where('POST', '/auth/register'), isEmpty);
      await teardown(t);
    });

    testWidgets('values are trimmed, the password is confirmed, a double tap sends once', (t) async {
      final gate = Completer<FakeReply>();
      final h = await toRegister(t, register: (_) => gate.future);
      await type(t, en.nameLabel, '  Noor Al Thani ');
      await type(t, '${en.emailLabel} (${en.optional})', ' noor@example.com ');
      await type(t, '${en.passwordLabel} (${en.optional})', 'abcd1234');
      await t.tap(find.text(en.createAccount));
      await t.tap(find.text(en.createAccount));
      await settle(t, 2);
      final calls = h.adapter.where('POST', '/auth/register');
      expect(calls, hasLength(1));
      expect(calls.single.body['name'], 'Noor Al Thani');
      expect(calls.single.body['email'], 'noor@example.com');
      expect(calls.single.body['password_confirmation'], 'abcd1234');
      gate.complete(ok(authenticated(), status: 201));
      await settle(t, 6);
      await teardown(t);
    });

    testWidgets('422 on the email is shown next to the email field', (t) async {
      await toRegister(
        t,
        register: (_) => error(
          422,
          'validation_failed',
          errors: {
            'email': ['That email is already used.'],
          },
        ),
      );
      await type(t, en.nameLabel, 'Noor');
      await type(t, '${en.emailLabel} (${en.optional})', 'noor@example.com');
      await tapText(t, en.createAccount);
      expect(find.text('That email is already used.'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('back with typed text asks before discarding', (t) async {
      await toRegister(t);
      await type(t, en.nameLabel, 'Noor');
      await t.pageBack();
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsOneWidget);
      await tapText(t, en.keepEditing);
      expect(find.text(en.registerTitle), findsOneWidget);
      await t.pageBack();
      await settle(t);
      await tapText(t, en.discard);
      expect(find.text(en.registerTitle), findsNothing);
      await teardown(t);
    });
  });

  group('Password screens', () {
    testWidgets('password sign-in names the field that is wrong', (t) async {
      final h = await boot(t, routes({}));
      await go(t, h, '/login/password');
      await tapText(t, en.signIn);
      expect(find.text(en.phoneInvalid), findsOneWidget);
      expect(find.text(en.fieldRequired), findsOneWidget);
      await type(t, en.emailOrPhoneLabel, 'noor@example.com');
      await tapText(t, en.signIn);
      expect(find.text(en.phoneInvalid), findsNothing);
      expect(h.adapter.where('POST', '/auth/login'), isEmpty);
      await teardown(t);
    });

    testWidgets('reset: resend timer, change number and the password rules', (t) async {
      final h = await boot(
        t,
        routes({
          'POST /auth/otp/request': (_) => ok({'sent': true, 'resend_in': 45}),
        }),
      );
      await go(t, h, '/login/reset');
      await type(t, en.phoneLabel, '55123456');
      await tapText(t, en.sendResetCode);
      expect(find.text(en.resendIn(45)), findsOneWidget);
      expect(h.adapter.where('POST', '/auth/otp/request').single.body['purpose'], 'password_reset');
      await type(t, en.otpCodeLabel, '123456');
      await type(t, en.newPasswordLabel, 'onlyletters');
      await type(t, en.passwordConfirmLabel, 'onlyletters');
      await tapText(t, en.resetPassword);
      expect(find.text(en.passwordTooShort), findsWidgets);
      expect(h.adapter.where('POST', '/auth/password/reset'), isEmpty);
      await t.pump(const Duration(seconds: 46));
      expect(find.text(en.resendCode), findsOneWidget);
      await tapText(t, en.changeNumber);
      expect(find.text(en.sendResetCode), findsOneWidget);
      await teardown(t);
    });
  });

  group('Signed-in forms', () {
    FakeHandler backend([Map<String, FutureOr<FakeReply> Function(RecordedRequest)> extra = const {}]) {
      final fallback = customerBackend(ApiMode.data);
      return (r) {
        final custom = extra['${r.method} ${r.path}'];
        return custom != null ? custom(r) : fallback(r);
      };
    }

    Future<TestHarness> open(WidgetTester t, String route, FakeHandler handler, {String locale = 'en'}) async {
      final h = await boot(t, handler, signedIn: true, locale: locale);
      await go(t, h, '/home');
      unawaited(h.read(routerProvider).push(route));
      await settle(t, 6);
      return h;
    }

    testWidgets('profile: a blank name is refused, back asks first, save sends trimmed values and closes', (t) async {
      final h = await open(t, '/profile/edit', backend({'PATCH /me': (_) => ok(userJson())}));
      await t.enterText(field(en.nameLabel), '   ');
      await t.pump();
      await tapText(t, en.save);
      expect(find.text(en.fieldRequired), findsOneWidget);
      expect(h.adapter.where('PATCH', '/me'), isEmpty);

      await t.enterText(field(en.nameLabel), '  Mona Hassan ');
      await t.pump();
      await t.pageBack();
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsOneWidget);
      await tapText(t, en.keepEditing);
      await tapText(t, en.save);
      expect(h.adapter.where('PATCH', '/me').single.body['name'], 'Mona Hassan');
      expect(find.text(en.editProfile), findsNothing, reason: 'saved, so it closed without the discard prompt');
      await teardown(t);
    });

    testWidgets('profile: the password sheet explains a weak password instead of closing', (t) async {
      final h = await open(
        t,
        '/profile/edit',
        backend({
          'PUT /me/password': (_) => ok({'changed': true}),
        }),
      );
      await tapText(t, en.changePassword);
      await t.enterText(find.widgetWithText(TextFormField, en.currentPassword), 'old-pass-1');
      await t.enterText(find.widgetWithText(TextFormField, en.newPasswordLabel), 'short');
      await tapText(t, en.save, last: true);
      expect(find.text(en.passwordTooShort), findsWidgets);
      expect(find.text(en.currentPassword), findsWidgets, reason: 'the sheet is still open');
      expect(h.adapter.where('PUT', '/me/password'), isEmpty);
      await t.enterText(find.widgetWithText(TextFormField, en.newPasswordLabel), 'abcd1234');
      await tapText(t, en.save, last: true);
      final body = h.adapter.where('PUT', '/me/password').single.body;
      expect(body['current_password'], 'old-pass-1');
      expect(body['password_confirmation'], 'abcd1234');
      await teardown(t);
    });

    testWidgets('address: phone is checked, Arabic digits and spaces are cleaned, a pin outside Qatar is explained', (
      t,
    ) async {
      final h = await open(
        t,
        '/addresses/new',
        backend({
          'POST /me/addresses': (_) => error(
            422,
            'validation_failed',
            errors: {
              'latitude': ['The pin must be inside Qatar.'],
            },
          ),
        }),
      );
      await t.enterText(field(en.recipientPhone), '123');
      await tapText(t, en.saveAddress);
      expect(find.text(en.phoneInvalid), findsOneWidget);
      expect(h.adapter.where('POST', '/me/addresses'), isEmpty);

      await t.enterText(field(en.recipientPhone), '5512 3456');
      await t.enterText(field(en.zoneNumber), '٦٦');
      await t.enterText(field(en.streetNumber), '850');
      await t.enterText(field(en.buildingNumber), '12');
      await t.enterText(field(en.area), '  West Bay  ');
      await t.pump();
      await t.ensureVisible(find.text(en.saveAddress));
      await tapText(t, en.saveAddress);
      final body = h.adapter.where('POST', '/me/addresses').single.body;
      expect(body['zone_number'], '66');
      expect(body['recipient_phone'], '+97455123456');
      expect(body['area'], 'West Bay');
      expect(find.text('The pin must be inside Qatar.'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('address: leaving a changed form asks first, an untouched one closes', (t) async {
      final h = await open(t, '/addresses/new', backend());
      await t.pageBack();
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsNothing);
      expect(find.text(en.newAddress), findsNothing);
      unawaited(h.read(routerProvider).push('/addresses/new'));
      await settle(t, 6);
      await t.enterText(field('${en.landmark} (${en.optional})'), 'Blue gate');
      await t.pump();
      await t.pageBack();
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsOneWidget);
      await teardown(t);
    });

    testWidgets('review: needs a rating, asks before discarding, submits once', (t) async {
      final gate = Completer<FakeReply>();
      final h = await open(
        t,
        '/products/p1/reviews/new?name=Sofa',
        backend({'POST /products/p1/reviews': (_) => gate.future}),
      );
      await tapText(t, en.submitReview);
      expect(find.text(en.ratingRequired), findsOneWidget);
      await t.tap(find.bySemanticsLabel(en.starLabel(4)).first, warnIfMissed: false);
      await t.tap(find.byIcon(Icons.star_outline_rounded).first, warnIfMissed: false);
      await settle(t);
      await t.pageBack();
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsOneWidget);
      await tapText(t, en.keepEditing);
      await t.tap(find.text(en.submitReview));
      await t.tap(find.text(en.submitReview));
      await settle(t, 2);
      expect(h.adapter.where('POST', '/products/p1/reviews'), hasLength(1));
      gate.complete(ok({'id': 'r1'}, status: 201));
      await settle(t, 6);
      await teardown(t);
    });

    testWidgets('support ticket: both fields are required, values are trimmed, back asks first', (t) async {
      final h = await open(
        t,
        '/support/new',
        backend({
          'POST /me/support/tickets': (_) => ok({'id': 't1', 'number': 'T-1', 'status': 'open'}, status: 201),
        }),
      );
      await t.ensureVisible(find.text(en.send));
      await tapText(t, en.send);
      expect(find.text(en.fieldRequired), findsNWidgets(2));
      await t.enterText(field(en.ticketSubject), '  Late order ');
      await t.enterText(field(en.ticketMessage), ' Where is my sofa? ');
      await t.pump();
      await t.pageBack();
      await settle(t);
      expect(find.text(en.discardChangesTitle), findsOneWidget);
      await tapText(t, en.keepEditing);
      await t.ensureVisible(find.text(en.send));
      await tapText(t, en.send);
      final body = h.adapter.where('POST', '/me/support/tickets').single.body;
      expect(body['subject'], 'Late order');
      expect(body['message'], 'Where is my sofa?');
      await teardown(t);
    });

    testWidgets('cart coupon: a double submit applies once and the error clears while typing', (t) async {
      final gate = Completer<FakeReply>();
      final h = await boot(t, backend({'PUT /cart': (_) => gate.future}), signedIn: true);
      await go(t, h, '/cart');
      await t.enterText(field(en.couponCode), ' save10 ');
      await t.pump();
      await t.tap(find.text(en.applyCoupon));
      await t.tap(find.text(en.applyCoupon));
      await settle(t, 2);
      final puts = h.adapter.where('PUT', '/cart').where((r) => r.body.containsKey('coupon_code'));
      expect(puts, hasLength(1));
      expect(puts.single.body['coupon_code'], 'save10', reason: 'trimmed');
      gate.complete(ok(cartJson()));
      await settle(t, 4);
      await teardown(t);
    });

    testWidgets('product question: too short is explained in the sheet, a real question is sent trimmed', (t) async {
      final h = await open(
        t,
        '/products/p1',
        backend({
          'POST /products/p1/questions': (_) => ok({'id': 'q1'}, status: 201),
        }),
      );
      await t.scrollUntilVisible(find.text(en.askQuestion), 300, scrollable: find.byType(Scrollable).first);
      await tapText(t, en.askQuestion);
      await t.enterText(find.widgetWithText(TextFormField, en.askQuestion), 'abc');
      await tapText(t, en.send);
      expect(find.text(en.minLengthError('5')), findsOneWidget);
      expect(h.adapter.where('POST', '/products/p1/questions'), isEmpty);
      await t.enterText(find.widgetWithText(TextFormField, en.askQuestion), '  Is it washable?  ');
      await tapText(t, en.send);
      expect(h.adapter.where('POST', '/products/p1/questions').single.body['question'], 'Is it washable?');
      await teardown(t);
    });

    testWidgets('bid: Arabic digits work, a low bid is explained before any dialog, a double tap asks once', (t) async {
      final h = await open(
        t,
        '/auctions/lots/lot1',
        backend({
          'POST /auctions/lots/lot1/bids': (_) => ok({'lot': lotJson(), 'bid': null}, status: 201),
        }),
      );
      await t.scrollUntilVisible(find.text(en.placeBid), 300, scrollable: find.byType(Scrollable).first);
      await t.enterText(field(en.bidAmount), '١٠٠٠');
      await t.pump();
      expect(find.text('1000'), findsOneWidget, reason: 'converted to ASCII digits');
      await t.tap(find.text(en.placeBid).first);
      await settle(t);
      expect(find.text(en.confirmBidTitle), findsNothing);
      expect(find.textContaining('1300.00'), findsWidgets, reason: 'tells the minimum');
      await t.enterText(field(en.bidAmount), '1300.567');
      await t.pump();
      expect(find.text('1300.56'), findsOneWidget, reason: 'two decimals at most');
      await t.tap(find.text(en.placeBid).first);
      await t.tap(find.text(en.placeBid).first);
      await settle(t);
      expect(find.text(en.confirmBidTitle), findsOneWidget);
      await tapText(t, en.placeBid, last: true);
      expect(h.adapter.where('POST', '/auctions/lots/lot1/bids'), hasLength(1));
      await teardown(t);
    });
  });
}
