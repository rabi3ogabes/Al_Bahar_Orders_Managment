@Timeout(Duration(minutes: 5))
library;

import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:ui' as ui;

import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/onboarding/presentation/art/intro_art_window.dart';
import 'package:albahar_customer/features/onboarding/presentation/intro_page.dart';
import 'package:albahar_customer/features/onboarding/presentation/onboarding_screens.dart';
import 'package:albahar_customer/features/public_config/domain/intro_slide_text.dart';
import 'package:albahar_customer/features/public_config/domain/public_config.dart';
import 'package:albahar_customer/features/public_config/presentation/state/public_config_state.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import '../qa/test_fonts.dart';

AppLocalizations get en => lookupAppLocalizations(const Locale('en'));
AppLocalizations get ar => lookupAppLocalizations(const Locale('ar'));

/// `{title: {en, ar}, body: {en, ar}}` as the server sends one slide.
Map<String, dynamic> slide({String? te, String? ta, String? be, String? ba}) => {
  'title': {'en': te, 'ar': ta},
  'body': {'en': be, 'ar': ba},
};

Map<String, dynamic> config(List<Object?> slides) => {
  'designed_by': {'en': '', 'ar': ''},
  'intro': {'slides': slides},
  'returns_enabled': true,
};

FakeHandler serving(Map<String, dynamic> body) => routes({'GET /app-config': (_) => ok(body)});

const customEn = ['Hala, welcome home', 'Everything for your majlis', 'Bid live with us'];
const customEnBody = [
  'Furniture and décor chosen for Qatar homes.',
  'Pick a time that suits you, or collect from a branch.',
  'Live auctions and collections for the majlis.',
];
const customAr = ['حيّاكم الله في بيتكم', 'كل شي لمجلسكم', 'زايدوا معنا'];
const customArBody = ['أثاث وديكور لبيوت قطر.', 'اختاروا الوقت اللي يناسبكم.', 'مزادات مباشرة وتشكيلات للمجلس.'];

final fullCustom = config([
  for (var i = 0; i < 3; i++) slide(te: customEn[i], ta: customAr[i], be: customEnBody[i], ba: customArBody[i]),
]);

/// What the three intro pages say when the dashboard has nothing: the app's own words.
String defaultTitle(String locale, int i) {
  final l = locale == 'ar' ? ar : en;
  return [l.onboardingTitle1, l.onboardingTitle2, l.onboardingTitle3][i];
}

String defaultBody(String locale, int i) {
  final l = locale == 'ar' ? ar : en;
  return [l.onboardingBody1, l.onboardingBody2, l.onboardingBody3][i];
}

Future<void> settle(WidgetTester t, [int frames = 8]) async {
  for (var i = 0; i < frames; i++) {
    await t.pump(const Duration(milliseconds: 120));
  }
}

/// The intro on its own (no router), the way the first launch shows it.
Future<TestHarness> pumpIntro(
  WidgetTester t, {
  Size size = const Size(390, 844),
  String locale = 'en',
  bool dark = false,
  double text = 1,
  Map<String, Object> prefs = const {},
  FakeHandler? handler,
  bool reduceMotion = false,
  Key? boundary,
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  t.platformDispatcher.textScaleFactorTestValue = text;
  addTearDown(() {
    t.view.reset();
    t.platformDispatcher.clearAllTestValues();
  });
  final h = await TestHarness.create(
    prefs: {'locale': locale, ...prefs},
    handler: handler ?? (_) => error(503, 'down'),
  );
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
            builder: (context, child) => MediaQuery(
              data: MediaQuery.of(context).copyWith(disableAnimations: reduceMotion),
              child: RepaintBoundary(key: boundary, child: child),
            ),
            home: const OnboardingScreen(),
          );
        },
      ),
    ),
  );
  await t.pump();
  return h;
}

Future<void> next(WidgetTester t, String label) async {
  await t.tap(find.text(label));
  await settle(t);
}

/// The scrollable of the page that is showing.
ScrollableState pageScroll(WidgetTester t) =>
    t.state<ScrollableState>(find.descendant(of: find.byType(IntroPage), matching: find.byType(Scrollable)).first);

void main() {
  setUpAll(loadAppFonts);

  group('cleaning a text from the server', () {
    test('plain text passes, trimmed and folded to one line', () {
      expect(cleanIntroText('Hala', max: 40), 'Hala');
      expect(cleanIntroText('  Hala \n  home\t here  ', max: 40), 'Hala home here');
      expect(cleanIntroText('حيّاكم الله', max: 40), 'حيّاكم الله');
      expect(cleanIntroText('5 < 6 and 7 > 3', max: 40), '5 < 6 and 7 > 3');
    });

    test('anything that is not usable means "use the app text" (null), never an error', () {
      for (final bad in <Object?>[
        null,
        7,
        1.5,
        true,
        <Object?>[],
        {'a': 1},
        '',
        '   ',
        '\n\t',
        '<b></b>',
        '<br/> <p>',
        '<!-- x -->',
      ]) {
        expect(cleanIntroText(bad, max: 40), isNull, reason: '$bad');
      }
    });

    test('tags are removed and only their words remain', () {
      expect(cleanIntroText('<b>Hala</b> <i>home</i>', max: 40), 'Hala home');
      expect(cleanIntroText('<script>alert(1)</script>', max: 40), 'alert(1)');
      expect(cleanIntroText('<a href="https://x.test">Shop</a> now', max: 40), 'Shop now');
      expect(
        cleanIntroText('&lt;b&gt;stay&lt;/b&gt;', max: 40),
        '&lt;b&gt;stay&lt;/b&gt;',
        reason: 'entities are text, shown as written',
      );
    });

    test('control characters and direction overrides drop the whole text', () {
      for (final bad in [
        'bell\u0007',
        'nul\u0000',
        'esc\u001B[0m',
        'del\u007F',
        'c1\u0090',
        '\u202Ereversed',
        'iso\u2066late',
      ]) {
        expect(cleanIntroText(bad, max: 40), isNull, reason: bad.codeUnits.toString());
      }
    });

    test('too long text is cut to the limit by characters, so an emoji counts once like on the dashboard', () {
      expect(cleanIntroText('x' * 300, max: introTitleMax), 'x' * 40);
      expect(cleanIntroText('ب' * 500, max: introBodyMax)!.runes.length, 120);
      expect(cleanIntroText('🏠' * 60, max: introTitleMax)!.runes.length, 40);
      expect(
        cleanIntroText('${'a' * 39} ${'b' * 10}', max: 40),
        'a' * 39,
        reason: 'no trailing space is left after the cut',
      );
      expect(introTitleMax, 40);
      expect(introBodyMax, 120);
    });
  });

  group('IntroSlideText and PublicConfig', () {
    test('reads {title:{en,ar}, body:{en,ar}} and picks the language, never borrowing the other one', () {
      final s = IntroSlideText.fromJson(slide(te: 'T en', ta: 'T ar', be: 'B en', ba: null));
      expect((s.title('en'), s.title('ar')), ('T en', 'T ar'));
      expect(
        (s.body('en'), s.body('ar')),
        ('B en', null),
        reason: 'an empty Arabic text stays empty, not the English one',
      );
      expect(s.title('fr'), 'T en', reason: 'any other language reads as English');
    });

    test('anything else reads as nothing set', () {
      for (final raw in <Object?>[
        null,
        'oops',
        5,
        <Object?>[],
        {'title': 'flat'},
        {'title': null, 'body': 3},
      ]) {
        expect(IntroSlideText.fromJson(raw), IntroSlideText.none, reason: '$raw');
      }
    });

    test('PublicConfig keeps up to three slides in order; a missing or wrongly typed intro means none', () {
      final c = PublicConfig.fromJson(
        config([slide(te: 'one'), slide(te: 'two'), slide(te: 'three'), slide(te: 'four')]),
      );
      expect([for (final s in c.introSlides) s.titleEn], ['one', 'two', 'three']);
      expect(c.introSlide(1).titleEn, 'two');
      expect(c.introSlide(7), IntroSlideText.none);
      expect(c.introSlide(-1), IntroSlideText.none);

      for (final raw in <Object?>[
        null,
        'x',
        4,
        <Object?>[],
        {'slides': null},
        {'slides': 'nope'},
        {
          'slides': {'a': 1},
        },
      ]) {
        expect(PublicConfig.fromJson({'designed_by': {}, 'intro': raw}).introSlides, isEmpty, reason: '$raw');
      }
      expect(PublicConfig.fromJson(const {}).introSlides, isEmpty, reason: 'an older server');
      expect(
        PublicConfig.fromJson({
          'intro': {
            'slides': [null, 'x', slide(te: 'ok'), slide(te: 'a fourth one is never read')],
          },
        }).introSlides.map((s) => s.titleEn),
        [null, null, 'ok'],
      );
    });

    test('the cached copy reads back the same, and equality sees a changed word', () {
      final c = PublicConfig.fromJson(fullCustom);
      expect(PublicConfig.fromJson(c.toJson()), c);
      expect(PublicConfig.fromJson(c.toJson()).hashCode, c.hashCode);
      final changed = PublicConfig.fromJson(
        config([slide(te: 'Different'), slide(te: customEn[1]), slide(te: customEn[2])]),
      );
      expect(changed, isNot(c));
      expect(PublicConfig.empty, PublicConfig.fromJson(const {}));
    });

    test('hostile values are cleaned when read, so what is cached is clean too', () {
      final c = PublicConfig.fromJson(
        config([slide(te: '<b>Hala</b>', ta: 'ب' * 90, be: 'x' * 400, ba: 'bell\u0007')]),
      );
      final s = c.introSlide(0);
      expect((s.titleEn, s.titleAr!.runes.length, s.bodyEn!.length, s.bodyAr), ('Hala', 40, 120, null));
      expect(c.toJson()['intro'], {
        'slides': [s.toJson()],
      });
    });
  });

  group('the intro words', () {
    testWidgets('are the app\'s own when the server has none, in both languages', (t) async {
      for (final locale in ['en', 'ar']) {
        await pumpIntro(t, locale: locale, handler: serving(config([null, null, null])));
        await settle(t);
        for (var i = 0; i < 3; i++) {
          expect(find.text(defaultTitle(locale, i)), findsOneWidget, reason: '$locale title $i');
          expect(find.text(defaultBody(locale, i)), findsOneWidget, reason: '$locale body $i');
          if (i < 2) await next(t, (locale == 'ar' ? ar : en).next);
        }
        await t.pumpWidget(const SizedBox.shrink());
        await t.pump(const Duration(seconds: 5));
      }
    });

    testWidgets('the shipped defaults are the new easy Qatari words, inside the limits', (t) async {
      expect(en.onboardingTitle1, 'Hala! For homes across Qatar');
      expect(ar.onboardingTitle1.replaceAll('\u00A0', ' '), 'حيّاكم الله! لكل بيت في ديرتنا قطر');
      for (final l in [en, ar]) {
        for (final (title, body) in [
          (l.onboardingTitle1, l.onboardingBody1),
          (l.onboardingTitle2, l.onboardingBody2),
          (l.onboardingTitle3, l.onboardingBody3),
        ]) {
          expect(title.runes.length, inInclusiveRange(1, introTitleMax));
          expect(body.runes.length, inInclusiveRange(1, introBodyMax));
          // Plain text; a no-break space (which keeps the last two words of a title together) reads as a space.
          expect(cleanIntroText(title, max: introTitleMax), title.replaceAll('\u00A0', ' '), reason: 'plain text');
          expect(cleanIntroText(body, max: introBodyMax), body.replaceAll('\u00A0', ' '), reason: 'plain text');
        }
      }
    });

    testWidgets('show the dashboard\'s words for the current language, slide by slide', (t) async {
      await pumpIntro(t, handler: serving(fullCustom));
      await settle(t);
      for (var i = 0; i < 3; i++) {
        expect(find.text(customEn[i]), findsOneWidget);
        expect(find.text(customEnBody[i]), findsOneWidget);
        expect(find.text(defaultTitle('en', i)), findsNothing);
        if (i < 2) await next(t, en.next);
      }
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));

      await pumpIntro(t, locale: 'ar', handler: serving(fullCustom));
      await settle(t);
      expect(find.text(customAr[0]), findsOneWidget);
      expect(find.text(customArBody[0]), findsOneWidget);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('fall back to the app\'s text one text at a time, and one language at a time', (t) async {
      final partial = config([
        slide(te: 'Only an English title'),
        slide(ba: 'نص عربي فقط'),
        slide(te: '   ', ta: '<b></b>', be: 'bell\u0007', ba: null),
      ]);
      await pumpIntro(t, handler: serving(partial));
      await settle(t);
      expect(find.text('Only an English title'), findsOneWidget);
      expect(find.text(defaultBody('en', 0)), findsOneWidget, reason: 'the body is still the app\'s');
      await next(t, en.next);
      expect(
        find.text(defaultTitle('en', 1)),
        findsOneWidget,
        reason: 'slide 2 has Arabic only, English keeps its own words',
      );
      expect(find.text(defaultBody('en', 1)), findsOneWidget);
      await next(t, en.next);
      expect(
        find.text(defaultTitle('en', 2)),
        findsOneWidget,
        reason: 'blank, markup-only and control-character texts are not used',
      );
      expect(find.text(defaultBody('en', 2)), findsOneWidget);
      expect(t.takeException(), isNull);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('use the last answer kept on the phone on the very first frame, before the network answers', (t) async {
      final never = Completer<FakeReply>();
      await pumpIntro(
        t,
        prefs: {
          'public_config': jsonEncode(config([slide(te: 'Cached title', be: 'Cached text')])),
        },
        handler: (_) => never.future,
      );
      expect(find.text('Cached title'), findsOneWidget, reason: 'the first pumped frame already has it');
      expect(find.text('Cached text'), findsOneWidget);
      expect(find.text(en.onboardingTitle1), findsNothing);
      never.complete(ok(config([null, null, null])));
      await settle(t);
      expect(
        find.text(en.onboardingTitle1),
        findsOneWidget,
        reason: 'the server no longer has custom words: back to the app\'s',
      );
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('are the app\'s own when offline and nothing was ever kept; no error, no wait', (t) async {
      final h = await pumpIntro(t);
      h.adapter.failConnection = (_) => true;
      await settle(t);
      expect(find.text(en.onboardingTitle1), findsOneWidget);
      expect(find.text(en.onboardingBody1), findsOneWidget);
      expect(t.takeException(), isNull);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('survive a damaged copy on the phone and a nonsense answer from the server', (t) async {
      for (final cached in [
        '{"intro":"oops"}',
        '{"intro":{"slides":"oops"}}',
        '{"intro":{"slides":[1,"x",null,[],{"title":5,"body":[]}]}}',
        '{"intro":{"slides":[{"title":{"en":"<b>Safe</b>","ar":"${'ب' * 99}"},"body":{"en":"${'x' * 999}","ar":{"a":1}}}]}}',
      ]) {
        await pumpIntro(t, prefs: {'public_config': cached}, handler: (_) => const FakeReply(200, 'not json object'));
        await settle(t);
        expect(t.takeException(), isNull, reason: cached);
        expect(find.byType(IntroPage), findsOneWidget);
        await t.pumpWidget(const SizedBox.shrink());
        await t.pump(const Duration(seconds: 5));
      }
    });

    testWidgets('keep text that was hostile readable: tags gone, clamped, shown as plain text', (t) async {
      await pumpIntro(
        t,
        handler: serving(config([slide(te: '<b>Safe</b> <script>x</script>', be: 'y' * 400)])),
      );
      await settle(t);
      expect(find.text('Safe x'), findsOneWidget);
      expect(find.text('y' * 120), findsOneWidget);
      expect(find.textContaining('<'), findsNothing);
      expect(t.takeException(), isNull);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('change with the language at once, and the other language\'s words go', (t) async {
      await pumpIntro(t, handler: serving(fullCustom));
      await settle(t);
      expect(find.text(customEn[0]), findsOneWidget);

      await t.tap(find.text(en.languageArabic));
      await t.pump();
      await t.pump(const Duration(milliseconds: 20));
      expect(find.text(customAr[0]), findsOneWidget, reason: 'the new language is there on the next frames');
      expect(find.text(customEn[0]), findsNothing, reason: 'and the old one is not left fading beside it');
      expect(find.text(customArBody[0]), findsOneWidget);

      await settle(t);
      await t.tap(find.text(ar.languageEnglish));
      await t.pump();
      await t.pump(const Duration(milliseconds: 20));
      expect(find.text(customEn[0]), findsOneWidget);
      expect(find.text(customAr[0]), findsNothing);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('a language with no dashboard words switches to the app\'s own', (t) async {
      await pumpIntro(t, handler: serving(config([slide(te: 'Only English'), null, null])));
      await settle(t);
      expect(find.text('Only English'), findsOneWidget);
      await t.tap(find.text(en.languageArabic));
      await settle(t);
      expect(find.text(ar.onboardingTitle1), findsOneWidget);
      expect(find.text('Only English'), findsNothing);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });
  });

  group('words arriving while the first slide is showing', () {
    for (final locale in ['en', 'ar']) {
      testWidgets('($locale) cross-fade in place: both are there for a moment, then only the new; nothing else moves', (
        t,
      ) async {
        final answer = Completer<FakeReply>();
        await pumpIntro(t, locale: locale, handler: (_) => answer.future);
        await settle(t);
        final l = locale == 'ar' ? ar : en;
        expect(find.text(l.onboardingTitle1), findsOneWidget);

        final art = t.getRect(find.byType(IntroArtWindow));
        final button = t.getRect(find.byType(FilledButton));
        final titleTop = t.getTopLeft(find.text(l.onboardingTitle1)).dy;

        // A much longer text than the default: the biggest change in height there can be.
        final newTitle = locale == 'ar' ? '${'ب' * 8} عنوان طويل جداً لشاشة' : 'A noticeably longer title than before';
        final newBody = locale == 'ar'
            ? '${List.filled(12, 'كلمة').join(' ')} نهاية'
            : List.filled(15, 'word').join(' ');
        answer.complete(ok(config([slide(te: newTitle, ta: newTitle, be: newBody, ba: newBody), null, null])));
        await t.pump();
        await t.pump(const Duration(milliseconds: 150));
        expect(find.text(l.onboardingTitle1), findsOneWidget, reason: 'the old words are still fading out');
        expect(find.text(newTitle), findsOneWidget, reason: 'while the new ones fade in');
        expect(t.getRect(find.byType(IntroArtWindow)), art, reason: 'the picture does not move');
        expect(t.getRect(find.byType(FilledButton)), button);

        await settle(t);
        expect(find.text(l.onboardingTitle1), findsNothing);
        expect(find.text(newTitle), findsOneWidget);
        expect(find.text(newBody), findsOneWidget);
        expect(t.getRect(find.byType(IntroArtWindow)), art, reason: 'not even after the longer words settled');
        expect(t.getRect(find.byType(FilledButton)), button);
        expect(
          t.getTopLeft(find.text(newTitle)).dy,
          titleTop,
          reason: 'the title starts exactly where the old one did',
        );
        expect(t.takeException(), isNull);
        await t.pumpWidget(const SizedBox.shrink());
        await t.pump(const Duration(seconds: 5));
      });
    }

    testWidgets('a shorter text takes the same place too', (t) async {
      final answer = Completer<FakeReply>();
      await pumpIntro(t, handler: (_) => answer.future);
      await settle(t);
      final art = t.getRect(find.byType(IntroArtWindow));
      final titleTop = t.getTopLeft(find.text(en.onboardingTitle1)).dy;
      answer.complete(ok(config([slide(te: 'Hi', be: 'Short.'), null, null])));
      await settle(t);
      expect(find.text('Hi'), findsOneWidget);
      expect(t.getRect(find.byType(IntroArtWindow)), art);
      expect(t.getTopLeft(find.text('Hi')).dy, titleTop);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('with reduced motion the swap is instant', (t) async {
      final answer = Completer<FakeReply>();
      await pumpIntro(t, handler: (_) => answer.future, reduceMotion: true);
      await settle(t);
      answer.complete(ok(config([slide(te: 'New title', be: 'New text.'), null, null])));
      await t.pump();
      await t.pump(const Duration(milliseconds: 16));
      expect(find.text('New title'), findsOneWidget);
      expect(find.text(en.onboardingTitle1), findsNothing, reason: 'no overlap, no fade');
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('the same words arriving again change nothing at all', (t) async {
      final h = await pumpIntro(t, handler: serving(fullCustom));
      await settle(t);
      final before = t.getTopLeft(find.text(customEn[0]));
      unawaited(h.read(publicConfigProvider.notifier).refresh());
      await settle(t);
      expect(t.getTopLeft(find.text(customEn[0])), before);
      expect(find.text(customEn[0]), findsOneWidget);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });

    testWidgets('a page the customer already swiped to gets its words too', (t) async {
      final answer = Completer<FakeReply>();
      await pumpIntro(t, handler: (_) => answer.future);
      await settle(t);
      await next(t, en.next);
      expect(find.text(en.onboardingTitle2), findsOneWidget);
      answer.complete(ok(fullCustom));
      await settle(t);
      expect(find.text(customEn[1]), findsOneWidget);
      expect(find.text(en.onboardingTitle2), findsNothing);
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });
  });

  group('every slide fits at 320 dp with text scale 1.3', () {
    // The longest texts the dashboard lets through, in two shapes: wide letters with spaces, and one unbroken word.
    String fillTo(String word, int max) {
      final text = StringBuffer();
      while (text.length < max) {
        text.write(text.isEmpty ? word : ' $word');
      }
      return text.toString().substring(0, max).trimRight();
    }

    final longEn = config([
      for (final c in ['WWWW', 'MMMM', 'mmmm']) slide(te: fillTo(c, introTitleMax), be: fillTo(c, introBodyMax)),
    ]);
    final longAr = config([
      for (final c in ['عظيمة', 'مجلسكم', 'بيبي']) slide(ta: fillTo(c, introTitleMax), ba: fillTo(c, introBodyMax)),
    ]);
    final unbroken = config([
      for (var i = 0; i < 3; i++) slide(te: 'W' * 40, ta: 'ب' * 40, be: 'M' * 120, ba: 'ع' * 120),
    ]);

    for (final locale in ['en', 'ar']) {
      for (final dark in [false, true]) {
        final label = '$locale, ${dark ? 'dark' : 'light'}';

        for (final (name, cfg) in [
          ('the app\'s own text', config([null, null, null])),
          ('maximum-length text', locale == 'ar' ? longAr : longEn),
          ('maximum-length text without any space', unbroken),
        ]) {
          testWidgets('$label, $name: no overflow, nothing clipped, every word reachable', (t) async {
            const size = Size(320, 568);
            await pumpIntro(t, size: size, locale: locale, dark: dark, text: 1.3, handler: serving(cfg));
            await settle(t);
            final l = locale == 'ar' ? ar : en;
            for (var page = 0; page < 3; page++) {
              expect(t.takeException(), isNull, reason: 'page ${page + 1}');
              final scroll = pageScroll(t);
              final viewport = t.getRect(
                find.descendant(of: find.byType(IntroPage), matching: find.byType(Scrollable)).first,
              );
              final title = find.descendant(of: find.byType(IntroPage), matching: find.byType(Text)).at(0);
              final body = find.descendant(of: find.byType(IntroPage), matching: find.byType(Text)).at(1);

              // Sideways: never wider than the screen, never cut at the edge.
              for (final f in [title, body]) {
                final r = t.getRect(f);
                expect(r.left, greaterThanOrEqualTo(0), reason: 'page ${page + 1} text starts inside');
                expect(r.right, lessThanOrEqualTo(size.width), reason: 'page ${page + 1} text ends inside');
              }
              // Downwards: it scrolls when it must, and the end of the text can be brought fully into view.
              scroll.position.jumpTo(scroll.position.maxScrollExtent);
              await t.pump();
              expect(
                t.getRect(body).bottom,
                lessThanOrEqualTo(viewport.bottom + 0.5),
                reason: 'page ${page + 1} text end visible',
              );
              expect(t.getRect(title).top, greaterThanOrEqualTo(viewport.top - 0.5 - scroll.position.maxScrollExtent));
              expect(t.getRect(find.byType(FilledButton)).bottom, lessThanOrEqualTo(size.height));
              expect(t.takeException(), isNull);
              scroll.position.jumpTo(0);
              if (page < 2) await next(t, l.next);
            }
            await t.pumpWidget(const SizedBox.shrink());
            await t.pump(const Duration(seconds: 5));
          });
        }
      }
    }

    testWidgets(
      'the app\'s own text needs no scrolling on any phone from 320 x 568 up at text scale 1.3, in either language',
      (t) async {
        for (final size in const [Size(320, 568), Size(360, 640), Size(360, 740), Size(360, 800), Size(390, 844)]) {
          for (final locale in ['en', 'ar']) {
            await pumpIntro(t, size: size, locale: locale, text: 1.3, handler: serving(config([null, null, null])));
            await settle(t);
            for (var page = 0; page < 3; page++) {
              expect(
                pageScroll(t).position.maxScrollExtent,
                0,
                reason: '$locale ${size.width.toInt()}x${size.height.toInt()} page ${page + 1} fits without scrolling',
              );
              if (page < 2) await next(t, (locale == 'ar' ? ar : en).next);
            }
            await t.pumpWidget(const SizedBox.shrink());
            await t.pump(const Duration(seconds: 5));
          }
        }
      },
    );
  });

  group('for people who use a screen reader', () {
    testWidgets('the title is a heading, the text is read as written, and the picture keeps its description', (
      t,
    ) async {
      final handle = t.ensureSemantics();
      await pumpIntro(t, handler: serving(fullCustom));
      await settle(t);
      expect(t.getSemantics(find.text(customEn[0])).flagsCollection.isHeader, isTrue);
      expect(find.bySemanticsLabel(customEnBody[0]), findsOneWidget);
      expect(find.bySemanticsLabel(en.onboardingArtLabel1), findsOneWidget);
      handle.dispose();
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 5));
    });
  });

  // Not a test of behaviour: with INTRO_SHOTS=<folder> the three slides are drawn to PNG files so they can be looked at.
  final shots = Platform.environment['INTRO_SHOTS'];
  group('pictures of the slides', skip: shots == null ? 'set INTRO_SHOTS=<folder> to draw them' : false, () {
    for (final size in const [Size(1024, 768), Size(768, 1024)]) {
      testWidgets('a ${size.width.toInt()} x ${size.height.toInt()} window, first page, English', (t) async {
        final key = GlobalKey();
        await pumpIntro(t, size: size, handler: serving(fullCustom), boundary: key);
        await settle(t);
        await t.runAsync(() async {
          final box = t.renderObject<RenderRepaintBoundary>(find.byKey(key));
          final image = await box.toImage();
          final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
          await File('$shots/intro-wide-${size.width.toInt()}.png').writeAsBytes(bytes!.buffer.asUint8List());
        });
        await t.pumpWidget(const SizedBox.shrink());
        await t.pump(const Duration(seconds: 5));
      });
    }

    for (final locale in ['en', 'ar']) {
      for (final dark in [false, true]) {
        for (final custom in [false, true]) {
          testWidgets('$locale ${dark ? 'dark' : 'light'} ${custom ? 'dashboard words' : 'app words'}', (t) async {
            final key = GlobalKey();
            await pumpIntro(
              t,
              size: const Size(360, 740),
              locale: locale,
              dark: dark,
              handler: serving(custom ? fullCustom : config([null, null, null])),
              boundary: key,
            );
            await settle(t);
            for (var page = 0; page < 3; page++) {
              await t.runAsync(() async {
                final box = t.renderObject<RenderRepaintBoundary>(find.byKey(key));
                final image = await box.toImage(pixelRatio: 2);
                final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
                final name =
                    '$shots/intro-$locale-${dark ? 'dark' : 'light'}-${custom ? 'custom' : 'default'}-${page + 1}.png';
                await File(name).writeAsBytes(bytes!.buffer.asUint8List());
              });
              if (page < 2) await next(t, (locale == 'ar' ? ar : en).next);
            }
            await t.pumpWidget(const SizedBox.shrink());
            await t.pump(const Duration(seconds: 5));
          });
        }
      }
    }
  });
}
