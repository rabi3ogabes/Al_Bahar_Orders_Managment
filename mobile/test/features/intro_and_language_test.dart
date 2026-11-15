@Timeout(Duration(minutes: 5))
library;

import 'dart:async';

import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/widgets/language_switch.dart';
import 'package:albahar_customer/features/auth/presentation/auth_screens.dart';
import 'package:albahar_customer/features/onboarding/presentation/art/intro_art_window.dart';
import 'package:albahar_customer/features/onboarding/presentation/designed_by_line.dart';
import 'package:albahar_customer/features/onboarding/presentation/intro_page.dart';
import 'package:albahar_customer/features/onboarding/presentation/onboarding_screens.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import '../qa/customer_fixtures.dart';
import '../qa/test_fonts.dart';

AppLocalizations get en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get ar => lookupAppLocalizations(const Locale('ar'));

final _config = ok({
  'designed_by': {'en': 'Designed by Athar Qatar', 'ar': 'تصميم أثر قطر'},
  'returns_enabled': true,
});

FakeHandler _backend() => (r) {
  if (r.method == 'GET' && r.path == '/app-config') return _config;
  if (r.method == 'POST' && r.path == '/auth/otp/request') {
    return ok({'sent': true, 'expires_in': 300, 'resend_in': 60});
  }
  if (r.method == 'PATCH' && r.path == '/me') return ok(userJson());
  return customerBackend(ApiMode.data)(r);
};

/// The real app, first launch: no language chosen, intro not seen.
Future<TestHarness> boot(
  WidgetTester t, {
  Size size = const Size(390, 844),
  Map<String, Object> prefs = const {},
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final h = await TestHarness.create(prefs: prefs, handler: _backend());
  await t.pumpWidget(
    UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
  );
  await t.pump(const Duration(milliseconds: 1700));
  await settle(t);
  return h;
}

Future<void> settle(WidgetTester t, [int frames = 8]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 120));
  }
}

/// The page the app is on. A pushed page is the last match; `currentConfiguration.uri` keeps the page under it.
String path(TestHarness h) => h.read(routerProvider).routerDelegate.currentConfiguration.matches.last.matchedLocation;

Future<void> teardown(WidgetTester t) async {
  await t.pumpWidget(const SizedBox.shrink());
  await t.pump(const Duration(seconds: 10));
}

TextDirection directionOf(WidgetTester t, Finder f) => Directionality.of(t.element(f.first));

Future<void> switchLanguage(WidgetTester t) async {
  await t.tap(find.byType(LanguageSwitch));
  await settle(t);
}

void main() {
  setUpAll(loadAppFonts);

  group('first launch', () {
    testWidgets('has no language page: the splash goes straight to the intro, in the device language', (t) async {
      final h = await boot(t);
      expect(path(h), '/onboarding');
      expect(find.text(en.onboardingTitle1), findsOneWidget);
      expect(find.text(en.languageArabic), findsOneWidget, reason: 'the switch offers the other language');
      expect(h.read(appPreferencesProvider).localeCode, isNull, reason: 'nothing is chosen until the customer taps');
      await teardown(t);
    });

    testWidgets('a customer who chose Arabic before gets the intro in Arabic, right to left', (t) async {
      await boot(t, prefs: {'locale': 'ar'});
      expect(find.text(ar.onboardingTitle1), findsOneWidget);
      expect(directionOf(t, find.text(ar.onboardingTitle1)), TextDirection.rtl);
      expect(find.text(ar.languageEnglish), findsOneWidget);
      await teardown(t);
    });

    testWidgets('shows the "Designed by" line from the dashboard under the button, in the current language', (t) async {
      await boot(t);
      expect(find.text('Designed by Athar Qatar'), findsOneWidget);
      final button = t.getRect(find.byType(FilledButton));
      final line = t.getRect(find.byType(DesignedByLine));
      expect(line.top, greaterThanOrEqualTo(button.bottom));
      await switchLanguage(t);
      expect(find.text('Designed by Athar Qatar'), findsNothing);
      expect(find.text('تصميم أثر قطر'), findsOneWidget);
      await teardown(t);
    });

    testWidgets('the intro still works when the server does not answer: no line, no error, no delay', (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(prefs: const {}, handler: (_) => error(503, 'unavailable'));
      await t.pumpWidget(
        UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
      );
      await t.pump(const Duration(milliseconds: 1700));
      await settle(t);
      expect(path(h), '/onboarding');
      expect(find.text(en.onboardingTitle1), findsOneWidget);
      expect(find.textContaining('Designed by'), findsNothing);
      expect(t.takeException(), isNull);
      await teardown(t);
    });
  });

  group('language switch in the intro', () {
    testWidgets(
      'switches the whole page in place: words, direction, the line, the switch itself; and it is remembered',
      (t) async {
        final h = await boot(t);
        expect(directionOf(t, find.text(en.onboardingTitle1)), TextDirection.ltr);

        await switchLanguage(t);

        expect(find.text(ar.onboardingTitle1), findsOneWidget);
        expect(find.text(en.onboardingTitle1), findsNothing);
        expect(directionOf(t, find.text(ar.onboardingTitle1)), TextDirection.rtl);
        expect(find.text(ar.next), findsOneWidget);
        expect(find.text(ar.skip), findsOneWidget);
        expect(find.text(ar.languageEnglish), findsOneWidget, reason: 'now it offers English, written in English');
        expect(find.text(ar.languageArabic), findsNothing);
        expect(path(h), '/onboarding', reason: 'no navigation: the same page');
        expect(h.read(appPreferencesProvider).localeCode, 'ar');
        expect(h.read(localeCodeProvider), 'ar');

        await switchLanguage(t);
        expect(find.text(en.onboardingTitle1), findsOneWidget);
        expect(directionOf(t, find.text(en.onboardingTitle1)), TextDirection.ltr);
        expect(h.read(appPreferencesProvider).localeCode, 'en');
        await teardown(t);
      },
    );

    testWidgets('keeps the page the customer is on', (t) async {
      await boot(t);
      await t.tap(find.text(en.next));
      await settle(t);
      expect(find.text(en.onboardingTitle2), findsOneWidget);

      await switchLanguage(t);

      expect(find.text(ar.onboardingTitle2), findsOneWidget, reason: 'still the second page');
      await t.tap(find.text(ar.next));
      await settle(t);
      expect(find.text(ar.onboardingTitle3), findsOneWidget);
      expect(find.text(ar.getStarted), findsOneWidget);
      await teardown(t);
    });

    testWidgets('the switch is a labelled 44 px button, opposite the Skip button', (t) async {
      await boot(t);
      final pill = t.getRect(find.byType(LanguageSwitch));
      final skip = t.getRect(find.text(en.skip));
      expect(pill.height, greaterThanOrEqualTo(44));
      expect(pill.width, greaterThanOrEqualTo(44));
      expect(pill.left, lessThan(skip.left), reason: 'at the start in English; Skip is at the end');
      expect(pill.overlaps(skip), isFalse);
      expect(find.bySemanticsLabel(en.switchLanguageTo('العربية')), findsOneWidget);

      await switchLanguage(t);
      final rtlPill = t.getRect(find.byType(LanguageSwitch));
      final rtlSkip = t.getRect(find.text(ar.skip));
      expect(rtlPill.left, greaterThan(rtlSkip.left), reason: 'mirrored: at the start is now the right');
      expect(find.bySemanticsLabel(ar.switchLanguageTo('English')), findsOneWidget);
      await teardown(t);
    });

    testWidgets('Skip and Get started carry on exactly as before', (t) async {
      final h = await boot(t);
      await t.tap(find.text(en.skip));
      await settle(t);
      expect(path(h), '/home');
      expect(h.read(appPreferencesProvider).onboardingDone, isTrue);
      await teardown(t);

      final h2 = await boot(t);
      await t.tap(find.text(en.next));
      await settle(t);
      await t.tap(find.text(en.next));
      await settle(t);
      await t.tap(find.text(en.getStarted));
      await settle(t);
      expect(path(h2), '/login');
      expect(find.text(en.sendCode), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a deep link opened before the intro returns to where it pointed', (t) async {
      final h = await boot(t);
      h.read(routerProvider).go('/products/p1');
      await settle(t);
      expect(path(h), '/onboarding');
      await t.tap(find.text(en.skip));
      await settle(t);
      expect(path(h), '/products/p1');
      await teardown(t);
    });
  });

  group('language switch before signing in', () {
    Future<TestHarness> atLogin(WidgetTester t) async {
      final h = await boot(t, prefs: {'onboarding_done': true});
      h.read(routerProvider).go('/login');
      await settle(t);
      return h;
    }

    testWidgets('the phone screen switches language without losing the typed number', (t) async {
      final h = await atLogin(t);
      expect(find.text(en.loginTitle), findsOneWidget);
      await t.enterText(find.byType(TextField).first, '55123456');
      await t.pump();

      await switchLanguage(t);

      expect(find.text(ar.loginTitle), findsOneWidget);
      expect(find.text(ar.sendCode), findsOneWidget);
      expect(directionOf(t, find.text(ar.loginTitle)), TextDirection.rtl);
      expect(find.text('55123456'), findsOneWidget, reason: 'what was typed stays');
      expect(path(h), '/login');
      expect(h.read(appPreferencesProvider).localeCode, 'ar');
      await teardown(t);
    });

    testWidgets('an error message is not left behind in the old language', (t) async {
      await atLogin(t);
      await t.enterText(find.byType(TextField).first, '123');
      await t.tap(find.text(en.sendCode));
      await settle(t);
      expect(find.text(en.phoneInvalid), findsOneWidget);

      await switchLanguage(t);

      expect(find.text(en.phoneInvalid), findsNothing);
      expect(find.text(ar.phoneInvalid), findsNothing, reason: 'cleared; the next tap on Send code explains again');
      await t.tap(find.text(ar.sendCode));
      await settle(t);
      expect(find.text(ar.phoneInvalid), findsOneWidget);
      await teardown(t);
    });

    testWidgets('the code step keeps its step, its countdown and the typed code', (t) async {
      final h = await atLogin(t);
      await t.enterText(find.byType(TextField).first, '55123456');
      await t.tap(find.text(en.sendCode));
      await settle(t);
      expect(path(h), '/login/otp');
      await t.enterText(find.byType(TextField).first, '12');
      await t.pump();

      await switchLanguage(t);

      expect(path(h), '/login/otp');
      expect(find.text(ar.otpTitle), findsOneWidget);
      expect(find.text('12'), findsOneWidget);
      expect(find.byType(LanguageSwitch), findsOneWidget);
      await teardown(t);
    });

    testWidgets('every screen of the sign-in flow carries the switch', (t) async {
      final h = await atLogin(t);
      for (final route in ['/login/password', '/login/reset']) {
        unawaited(h.read(routerProvider).push(route));
        await settle(t);
        expect(find.byType(LanguageSwitch), findsOneWidget, reason: route);
        h.read(routerProvider).pop();
        await settle(t);
      }
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 10));
    });

    testWidgets('the registration form keeps what was typed and re-words its field errors', (t) async {
      final h = await atLogin(t);
      h
          .read(routerProvider)
          .go(
            '/login/register',
            extra: const RegisterArgs(phone: '+97455123456', registrationToken: 'rt'),
          );
      await settle(t);
      await t.tap(find.text(en.createAccount));
      await settle(t);
      expect(find.text(en.fieldRequired), findsOneWidget);
      await t.enterText(find.byType(TextField).at(1), 'noor@example.com');
      await t.pump();

      await switchLanguage(t);

      expect(find.text('noor@example.com'), findsOneWidget);
      expect(find.text(en.fieldRequired), findsNothing, reason: 'no field error is left in English');
      expect(find.text(ar.fieldRequired), findsOneWidget, reason: 'the same error, now in Arabic');
      expect(find.text(ar.createAccount), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a signed-in customer who switches has the profile follow', (t) async {
      final h = await TestHarness.create(tokens: signedInTokens, handler: _backend());
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: Consumer(
            builder: (context, ref, _) {
              final locale = Locale(ref.watch(localeCodeProvider));
              return MaterialApp(
                locale: locale,
                supportedLocales: supportedLocales,
                localizationsDelegates: const [
                  AppLocalizations.delegate,
                  GlobalMaterialLocalizations.delegate,
                  GlobalWidgetsLocalizations.delegate,
                  GlobalCupertinoLocalizations.delegate,
                ],
                theme: AppTheme.light(locale),
                home: const Scaffold(body: Center(child: LanguageSwitch())),
              );
            },
          ),
        ),
      );
      await t.tap(find.byType(LanguageSwitch));
      await settle(t);
      final patch = h.adapter.where('PATCH', '/me').single;
      expect(patch.body, {'locale': 'ar'});
      expect(h.read(appPreferencesProvider).localeCode, 'ar');
      await teardown(t);
    });
  });

  group('the Settings language selector keeps working', () {
    testWidgets('choosing a language there switches the app', (t) async {
      final h = await boot(t, prefs: {'onboarding_done': true, 'locale': 'en'});
      h.read(routerProvider).go('/settings');
      await settle(t);
      await t.tap(find.text('العربية'));
      await settle(t);
      expect(h.read(appPreferencesProvider).localeCode, 'ar');
      expect(find.text(ar.settingsTitle), findsOneWidget);
      await teardown(t);
    });
  });

  group('reduced motion', () {
    Widget app(ValueNotifier<Locale> locale, {required bool reduce}) => ValueListenableBuilder<Locale>(
      valueListenable: locale,
      builder: (context, value, _) => MaterialApp(
        locale: value,
        supportedLocales: supportedLocales,
        localizationsDelegates: const [
          AppLocalizations.delegate,
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        builder: (context, child) => MediaQuery(
          data: MediaQuery.of(context).copyWith(disableAnimations: reduce),
          child: child!,
        ),
        home: const Scaffold(body: LanguageTransition(child: Text('hello'))),
      ),
    );

    double opacity(WidgetTester t) => t
        .widget<FadeTransition>(
          find.descendant(of: find.byType(LanguageTransition), matching: find.byType(FadeTransition)),
        )
        .opacity
        .value;

    testWidgets('the content fades in after a switch, and keeps the same widgets', (t) async {
      final locale = ValueNotifier(const Locale('en'));
      await t.pumpWidget(app(locale, reduce: false));
      expect(opacity(t), 1);
      final before = t.element(find.text('hello'));

      locale.value = const Locale('ar');
      await t.pump();
      await t.pump(const Duration(milliseconds: 60));
      expect(opacity(t), lessThan(1));
      expect(t.element(find.text('hello')), same(before), reason: 'mounted once: state is never lost');
      await t.pump(const Duration(milliseconds: 600));
      expect(opacity(t), 1);
    });

    testWidgets('with reduced motion the switch is instant', (t) async {
      final locale = ValueNotifier(const Locale('en'));
      await t.pumpWidget(app(locale, reduce: true));
      locale.value = const Locale('ar');
      await t.pump();
      await t.pump(const Duration(milliseconds: 20));
      expect(opacity(t), 1);
    });
  });

  group('the intro lays out everywhere', () {
    final sizes = {
      'small phone 320x568': const Size(320, 568),
      'phone 390x844': const Size(390, 844),
      'tablet 768x1024': const Size(768, 1024),
      'desktop 1280x800': const Size(1280, 800),
      'phone landscape 844x390': const Size(844, 390),
    };

    Future<void> pumpIntro(
      WidgetTester t,
      Size size, {
      String locale = 'en',
      bool dark = false,
      double text = 1,
    }) async {
      t.view.physicalSize = size;
      t.view.devicePixelRatio = 1;
      t.platformDispatcher.textScaleFactorTestValue = text;
      addTearDown(() {
        t.view.reset();
        t.platformDispatcher.clearAllTestValues();
      });
      final h = await TestHarness.create(prefs: {'locale': locale}, handler: _backend());
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: Consumer(
            builder: (context, ref, _) {
              final l = Locale(ref.watch(localeCodeProvider));
              return MaterialApp(
                locale: l,
                supportedLocales: supportedLocales,
                localizationsDelegates: const [
                  AppLocalizations.delegate,
                  GlobalMaterialLocalizations.delegate,
                  GlobalWidgetsLocalizations.delegate,
                  GlobalCupertinoLocalizations.delegate,
                ],
                theme: AppTheme.light(l),
                darkTheme: AppTheme.dark(l),
                themeMode: dark ? ThemeMode.dark : ThemeMode.light,
                home: const OnboardingScreen(),
              );
            },
          ),
        ),
      );
      await settle(t);
    }

    for (final e in sizes.entries) {
      for (final locale in ['en', 'ar']) {
        for (final dark in [false, true]) {
          testWidgets('${e.key}, $locale, ${dark ? 'dark' : 'light'}: all three pages, no overflow, nothing overlaps', (
            t,
          ) async {
            await pumpIntro(t, e.value, locale: locale, dark: dark);
            final l10n = locale == 'ar' ? ar : en;
            final size = e.value;
            for (var page = 0; page < 3; page++) {
              expect(t.takeException(), isNull, reason: 'page ${page + 1}');
              final button = t.getRect(find.byType(FilledButton));
              final line = t.getRect(find.byType(DesignedByLine));
              final pill = t.getRect(find.byType(LanguageSwitch));
              final skip = t.getRect(find.text(l10n.skip));
              expect(button.height, greaterThanOrEqualTo(48));
              expect(button.bottom, lessThanOrEqualTo(size.height));
              expect(line.top, greaterThanOrEqualTo(button.bottom));
              expect(find.text(locale == 'ar' ? 'تصميم أثر قطر' : 'Designed by Athar Qatar'), findsOneWidget);
              expect(pill.overlaps(skip), isFalse);
              expect(pill.left, greaterThanOrEqualTo(0));
              expect(pill.right, lessThanOrEqualTo(size.width));
              expect(skip.right, lessThanOrEqualTo(size.width));
              if (page < 2) {
                await t.tap(find.text(l10n.next));
                await settle(t);
              }
            }
            expect(find.text(l10n.getStarted), findsOneWidget);
            await teardown(t);
          });
        }
      }
    }

    for (final locale in ['en', 'ar']) {
      testWidgets('a small phone with the largest text size ($locale) scrolls instead of overflowing', (t) async {
        await pumpIntro(t, const Size(320, 568), locale: locale, text: 1.6);
        final l10n = locale == 'ar' ? ar : en;
        for (var page = 0; page < 3; page++) {
          expect(t.takeException(), isNull);
          expect(t.getRect(find.byType(FilledButton)).bottom, lessThanOrEqualTo(568));
          if (page < 2) {
            await t.tap(find.text(l10n.next));
            await settle(t);
          }
        }
        await teardown(t);
      });
    }
  });

  group('swiping the intro', () {
    Future<void> pumpIntro(WidgetTester t, {required bool reduceMotion}) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(prefs: {'locale': 'en'}, handler: _backend());
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: MaterialApp(
            locale: const Locale('en'),
            supportedLocales: supportedLocales,
            localizationsDelegates: const [
              AppLocalizations.delegate,
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            theme: AppTheme.light(const Locale('en')),
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(context).copyWith(disableAnimations: reduceMotion),
              child: child!,
            ),
            home: const OnboardingScreen(),
          ),
        ),
      );
      await settle(t);
    }

    /// The sideways offset of every sheet of the first picture.
    List<double> sheetShifts(WidgetTester t) => t
        .widgetList<Transform>(find.descendant(of: find.byType(IntroArtWindow).first, matching: find.byType(Transform)))
        .map((w) => w.transform.getTranslation().x)
        .toList();

    testWidgets('the sheets of the picture slide against each other, and settle back when the swipe ends', (t) async {
      await pumpIntro(t, reduceMotion: false);
      expect(sheetShifts(t).every((x) => x == 0), isTrue, reason: 'at rest nothing is shifted');

      final gesture = await t.startGesture(t.getCenter(find.byType(PageView)));
      await gesture.moveBy(const Offset(-150, 0));
      await t.pump();
      final shifts = sheetShifts(t);
      expect(shifts.length, greaterThan(2), reason: 'several sheets move');
      expect(shifts.every((x) => x > 0), isTrue, reason: 'they lag behind the page, which moved to the left');
      expect(shifts.toSet().length, greaterThan(2), reason: 'each at its own speed: that is the depth');
      expect(t.takeException(), isNull);

      await gesture.up();
      await settle(t, 12);
      expect(find.text(en.onboardingTitle1), findsOneWidget, reason: 'a short swipe springs back to the same page');
      expect(sheetShifts(t).every((x) => x == 0), isTrue, reason: 'and the sheets are back in place');
      await teardown(t);
    });

    testWidgets('with reduced motion the sheets stay still and the words do not fade', (t) async {
      await pumpIntro(t, reduceMotion: true);
      final gesture = await t.startGesture(t.getCenter(find.byType(PageView)));
      await gesture.moveBy(const Offset(-150, 0));
      await t.pump();
      expect(
        find.descendant(of: find.byType(IntroArtWindow).first, matching: find.byType(AnimatedBuilder)),
        findsNothing,
      );
      expect(sheetShifts(t).every((x) => x == 0), isTrue);
      expect(find.descendant(of: find.byType(IntroPage).first, matching: find.byType(Opacity)), findsNothing);
      await gesture.up();
      await settle(t, 12);
      expect(find.text(en.onboardingTitle1), findsOneWidget);
      await teardown(t);
    });

    testWidgets('mirrors in Arabic: the sheets lag the other way', (t) async {
      t.view.physicalSize = const Size(390, 844);
      t.view.devicePixelRatio = 1;
      addTearDown(t.view.reset);
      final h = await TestHarness.create(prefs: {'locale': 'ar'}, handler: _backend());
      await t.pumpWidget(
        UncontrolledProviderScope(
          container: h.container,
          child: MaterialApp(
            locale: const Locale('ar'),
            supportedLocales: supportedLocales,
            localizationsDelegates: const [
              AppLocalizations.delegate,
              GlobalMaterialLocalizations.delegate,
              GlobalWidgetsLocalizations.delegate,
              GlobalCupertinoLocalizations.delegate,
            ],
            theme: AppTheme.light(const Locale('ar')),
            home: const OnboardingScreen(),
          ),
        ),
      );
      await settle(t);
      final gesture = await t.startGesture(t.getCenter(find.byType(PageView)));
      await gesture.moveBy(const Offset(150, 0));
      await t.pump();
      expect(
        sheetShifts(t).every((x) => x < 0),
        isTrue,
        reason: 'next is to the left in Arabic, so the sheets lag to the left',
      );
      await gesture.up();
      await settle(t, 12);
      expect(find.text(ar.onboardingTitle1), findsOneWidget);
      await teardown(t);
    });
  });
}
