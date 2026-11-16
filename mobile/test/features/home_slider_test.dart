// The home slider: what the dashboard configures (aspect, autoplay, interval, dots, titles, corners, links) and how the slider behaves
// in the hand (swipe, hold, loop, right-to-left, reduced motion, screen reader, not showing).
import 'package:albahar_customer/core/widgets/common.dart' show SectionHeader;
import 'package:albahar_customer/core/widgets/skeleton.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/home/presentation/home_slider.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/home_slider_fakes.dart';

const _glide = Duration(milliseconds: 600);

/// Which of the slides A, B, C is the one in front (its title is on screen).
String? _front(WidgetTester t, {double width = 390}) {
  for (final name in ['A', 'B', 'C', 'X', 'Y']) {
    final title = find.text('Slide $name');
    if (title.evaluate().isEmpty) continue;
    final x = t.getCenter(title.first).dx;
    if (x > 0 && x < width) return name;
  }
  return null;
}

Future<OpenedLinks> _pump(
  WidgetTester t,
  BannersSection section, {
  Locale locale = const Locale('en'),
  bool dark = false,
  double width = 390,
  double textScale = 1,
  bool disableAnimations = false,
  bool screenReader = false,
  SlideImageBuilder? pictures,
  List<String>? asked,
}) async {
  useScreen(t, width: width);
  final links = OpenedLinks();
  await t.pumpWidget(
    sliderHost(
      HomeSlider(section: section),
      pictures: pictures ?? await fakePictures(t, asked: asked),
      links: links,
      locale: locale,
      dark: dark,
      textScale: textScale,
      disableAnimations: disableAnimations,
      screenReader: screenReader,
    ),
  );
  await t.pump();
  await t.pump(const Duration(milliseconds: 50));
  return links;
}

Future<void> _wait(WidgetTester t, Duration d) async {
  // Time passes in frames, so timers and glides run as they would on a phone.
  var left = d;
  while (left > Duration.zero) {
    final step = left > const Duration(milliseconds: 250) ? const Duration(milliseconds: 250) : left;
    await t.pump(step);
    left -= step;
  }
}

void main() {
  group('autoplay', () {
    testWidgets('moves to the next slide after the interval, and not before', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      expect(_front(t), 'A');
      await _wait(t, const Duration(milliseconds: 4900));
      expect(_front(t), 'A');
      await _wait(t, const Duration(milliseconds: 100));
      await _wait(t, _glide);
      expect(_front(t), 'B');
    });

    testWidgets('follows the interval the dashboard set: 3 and 10 seconds', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'interval': 3}));
      await _wait(t, const Duration(milliseconds: 2900));
      expect(_front(t), 'A');
      await _wait(t, const Duration(milliseconds: 100) + _glide);
      expect(_front(t), 'B');

      await t.pumpWidget(const SizedBox.shrink());
      await _pump(t, sliderSection(threeSlides(), settings: {'interval': 10}));
      await _wait(t, const Duration(seconds: 9, milliseconds: 900));
      expect(_front(t), 'A');
      await _wait(t, const Duration(milliseconds: 100) + _glide);
      expect(_front(t), 'B');
    });

    testWidgets('an interval outside 3 to 15 is held to it, a missing one is 5', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'interval': 1}));
      await _wait(t, const Duration(milliseconds: 2900));
      expect(_front(t), 'A', reason: '1 second is raised to 3');
      await _wait(t, const Duration(milliseconds: 100) + _glide);
      expect(_front(t), 'B');
    });

    testWidgets('autoplay off: the slides stay until swiped', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'autoplay': false}));
      await _wait(t, const Duration(minutes: 1));
      expect(_front(t), 'A');
    });

    testWidgets('loops without end: A, B, C and back to A', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      final seen = <String?>[];
      for (var i = 0; i < 4; i++) {
        await _wait(t, const Duration(seconds: 5) + _glide);
        seen.add(_front(t));
      }
      expect(seen, ['B', 'C', 'A', 'B']);
    });

    testWidgets('a parent that keeps rebuilding does not keep restarting the wait', (t) async {
      final section = sliderSection(threeSlides());
      useScreen(t);
      final pictures = await fakePictures(t);
      final tick = ValueNotifier<int>(0);
      addTearDown(tick.dispose);
      await t.pumpWidget(
        sliderHost(
          ValueListenableBuilder<int>(
            valueListenable: tick,
            builder: (context, n, _) => HomeSlider(section: section),
          ),
          pictures: pictures,
        ),
      );
      await t.pump();
      for (var i = 1; i <= 2; i++) {
        await _wait(t, const Duration(seconds: 2));
        tick.value = i;
        await t.pump();
      }
      await _wait(t, const Duration(milliseconds: 1000) + _glide);
      expect(_front(t), 'B', reason: 'five seconds after the start, whatever the parent did meanwhile');
    });

    testWidgets('a slider with one slide has no autoplay, no dots and nothing to swipe', (t) async {
      await _pump(t, sliderSection([threeSlides().first]));
      expect(find.byType(PageView), findsNothing);
      expect(find.byKey(const ValueKey('slider-dots')), findsNothing);
      await _wait(t, const Duration(minutes: 1));
      expect(find.text('Slide A'), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('nothing is left running when the slider goes away', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      await t.pumpWidget(const SizedBox.shrink());
      await _wait(t, const Duration(minutes: 1));
      expect(t.takeException(), isNull);
    });
  });

  group('waiting while the person is busy or the slider is out of sight', () {
    testWidgets('a finger on the slider holds it, and letting go waits a whole interval again', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      final hold = await t.startGesture(t.getCenter(find.byType(PageView)));
      await _wait(t, const Duration(seconds: 30));
      expect(_front(t), 'A');
      await hold.up();
      await _wait(t, const Duration(milliseconds: 4900));
      expect(_front(t), 'A');
      await _wait(t, const Duration(milliseconds: 100) + _glide);
      expect(_front(t), 'B');
    });

    testWidgets('a mouse over the slider holds it, and leaving lets it go on', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      final mouse = await t.createGesture(kind: PointerDeviceKind.mouse);
      await mouse.addPointer(location: const Offset(1, 1));
      addTearDown(mouse.removePointer);
      await mouse.moveTo(t.getCenter(find.byType(PageView)));
      await _wait(t, const Duration(seconds: 30));
      expect(_front(t), 'A');
      await mouse.moveTo(const Offset(1, 600));
      await _wait(t, const Duration(seconds: 5) + _glide);
      expect(_front(t), 'B');
    });

    testWidgets('the app in the background does not turn slides, and coming back starts the wait again', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      final binding = t.binding;
      binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await _wait(t, const Duration(minutes: 1));
      expect(_front(t), 'A');
      binding.handleAppLifecycleStateChanged(AppLifecycleState.hidden);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.inactive);
      binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await _wait(t, const Duration(milliseconds: 4900));
      expect(_front(t), 'A');
      await _wait(t, const Duration(milliseconds: 100) + _glide);
      expect(_front(t), 'B');
    });

    testWidgets('a page that is not showing (another tab, a page on top) does not turn slides', (t) async {
      useScreen(t);
      final pictures = await fakePictures(t);
      final showing = ValueNotifier<bool>(false);
      addTearDown(showing.dispose);
      final section = sliderSection(threeSlides());
      await t.pumpWidget(
        sliderHost(
          ValueListenableBuilder<bool>(
            valueListenable: showing,
            builder: (context, on, _) => TickerMode(
              enabled: on,
              child: HomeSlider(section: section),
            ),
          ),
          pictures: pictures,
        ),
      );
      await t.pump();
      await _wait(t, const Duration(minutes: 1));
      showing.value = true;
      await t.pump();
      await _wait(t, const Duration(seconds: 2));
      expect(_front(t), 'A', reason: 'it did not move while hidden, and does not rush to catch up when shown again');
      await _wait(t, const Duration(seconds: 3) + _glide);
      expect(_front(t), 'B');
    });

    testWidgets('a screen reader keeps the slides still (nothing moves under its focus)', (t) async {
      await _pump(t, sliderSection(threeSlides()), screenReader: true);
      await _wait(t, const Duration(minutes: 1));
      expect(_front(t), 'A');
    });

    testWidgets('scrolled far out of the window it does not move, and moves once it is back in view', (t) async {
      useScreen(t);
      final pictures = await fakePictures(t);
      await t.pumpWidget(
        sliderHost(
          Column(
            children: [
              const SizedBox(height: 3000),
              HomeSlider(section: sliderSection(threeSlides())),
            ],
          ),
          pictures: pictures,
        ),
      );
      await t.pump();
      await _wait(t, const Duration(minutes: 1));
      await t.drag(find.byType(SingleChildScrollView), const Offset(0, -3000));
      await t.pump();
      expect(_front(t), 'A', reason: 'it stayed on the first slide while out of sight');
      await _wait(t, const Duration(seconds: 5) + _glide);
      expect(_front(t), 'B');
    });
  });

  group('swiping', () {
    testWidgets('swipe to the next slide, and back; the dots follow', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'autoplay': false}));
      await t.fling(find.byType(PageView), const Offset(-300, 0), 1200);
      await _wait(t, _glide);
      expect(_front(t), 'B');
      await t.fling(find.byType(PageView), const Offset(300, 0), 1200);
      await _wait(t, _glide);
      expect(_front(t), 'A');
    });

    testWidgets('swiping back from the first slide goes to the last (it loops both ways)', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'autoplay': false}));
      await t.fling(find.byType(PageView), const Offset(300, 0), 1200);
      await _wait(t, _glide);
      expect(_front(t), 'C');
    });

    testWidgets('after a swipe the next automatic change comes a whole interval later', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      await _wait(t, const Duration(seconds: 3));
      await t.fling(find.byType(PageView), const Offset(-300, 0), 1200);
      await _wait(t, _glide);
      expect(_front(t), 'B');
      await _wait(t, const Duration(seconds: 4));
      expect(_front(t), 'B');
      await _wait(t, const Duration(seconds: 1) + _glide);
      expect(_front(t), 'C');
    });

    testWidgets('the dots show where it is: the longer one is the current slide', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'autoplay': false}));
      List<double> widths() => [for (final c in t.widgetList<AnimatedContainer>(_dots)) c.constraints!.maxWidth];
      expect(widths(), [18, 6, 6]);
      await t.fling(find.byType(PageView), const Offset(-300, 0), 1200);
      await _wait(t, _glide);
      expect(widths(), [6, 18, 6]);
    });
  });

  group('right to left', () {
    testWidgets('the first slide is on the right, the next one comes from the left, and the dots mirror', (t) async {
      await _pump(t, sliderSection(_arabic()), locale: const Locale('ar'));
      final a = t.getCenter(find.text('الشريحة أ'));
      final b = t.getCenter(find.text('الشريحة ب'));
      expect(a.dx, lessThan(390));
      expect(b.dx, lessThan(a.dx), reason: 'the second slide waits on the left');
      final dots = t.widgetList<AnimatedContainer>(_dots).toList();
      final first = t.getCenter(find.byWidget(dots.first));
      final last = t.getCenter(find.byWidget(dots.last));
      expect(first.dx, greaterThan(last.dx), reason: 'the first dot is on the right');
    });

    testWidgets('autoplay and the swipe go the Arabic way: next is to the left', (t) async {
      await _pump(t, sliderSection(_arabic()), locale: const Locale('ar'));
      await _wait(t, const Duration(seconds: 5) + _glide);
      expect(
        t.getCenter(find.text('الشريحة ب')).dx,
        inInclusiveRange(0, 390),
        reason: 'the second slide is now in front',
      );

      // Swiping to the right brings the next slide (the way a page turns in Arabic).
      await t.fling(find.byType(PageView), const Offset(300, 0), 1200);
      await _wait(t, _glide);
      expect(t.getCenter(find.text('الشريحة ج')).dx, inInclusiveRange(0, 390));
    });

    testWidgets('the title is on the right edge of the slide, the arrow on the left', (t) async {
      await _pump(t, sliderSection(_arabic()), locale: const Locale('ar'), width: 390);
      final title = t.getTopRight(find.text('الشريحة أ'));
      expect(title.dx, greaterThan(300));
      final arrows = [
        for (final e in find.byIcon(Icons.arrow_forward_rounded).evaluate())
          t.getCenter(find.byElementPredicate((x) => x == e)),
      ];
      final onScreen = arrows.where((a) => a.dx > 0 && a.dx < 390);
      expect(onScreen, hasLength(1));
      expect(onScreen.single.dx, lessThan(100));
    });
  });

  group('reduced motion', () {
    testWidgets('the slide changes at once instead of gliding', (t) async {
      await _pump(t, sliderSection(threeSlides()), disableAnimations: true);
      final x = t.getTopLeft(find.text('Slide A')).dx;
      await _wait(t, const Duration(seconds: 5));
      await t.pump(const Duration(milliseconds: 16));
      expect(_front(t), 'B');
      expect(t.getTopLeft(find.text('Slide B')).dx, x, reason: 'already in place, no glide to wait for');
    });

    testWidgets('with motion on, the same change is a glide (not yet in place a moment after)', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      final x = t.getTopLeft(find.text('Slide A')).dx;
      await _wait(t, const Duration(seconds: 5));
      await t.pump(const Duration(milliseconds: 100));
      expect(t.getTopLeft(find.text('Slide B')).dx, greaterThan(x + 20));
      await _wait(t, _glide);
      expect(t.getTopLeft(find.text('Slide B')).dx, x);
    });

    testWidgets('the dots change without animating, and glide when motion is on', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'autoplay': false}), disableAnimations: true);
      expect(t.widgetList<AnimatedContainer>(_dots).map((c) => c.duration).toSet(), {Duration.zero});
      await _pump(t, sliderSection(threeSlides(), settings: {'autoplay': false}));
      expect(t.widgetList<AnimatedContainer>(_dots).every((c) => c.duration > Duration.zero), isTrue);
    });
  });

  group('size and look from the dashboard settings', () {
    for (final (code, ratio) in [('16:9', 16 / 9), ('21:9', 21 / 9), ('3:1', 3.0), ('4:3', 4 / 3), ('1:1', 1.0)]) {
      testWidgets('$code: the slide has that shape before its picture arrives, and the page does not move after', (
        t,
      ) async {
        final section = sliderSection(threeSlides(), settings: {'aspect': code});
        await _pump(t, section, pictures: (_) => const NeverLoadingPicture());
        final slide = find.descendant(of: find.byType(HomeSlider), matching: find.byType(ClipRRect)).first;
        final before = t.getSize(slide);
        expect(before.width, closeTo(350, 0.01), reason: '390 wide, a 20 gutter on each side');
        expect(before.height, closeTo(350 / ratio, 0.01));
        expect(find.byType(Skeleton), findsWidgets, reason: 'a calm placeholder while loading');
        final total = t.getSize(find.byType(HomeSlider));

        // The picture arrives: nothing moves.
        await _pump(t, section);
        expect(t.getSize(find.descendant(of: find.byType(HomeSlider), matching: find.byType(ClipRRect)).first), before);
        expect(t.getSize(find.byType(HomeSlider)), total);
        expect(find.byType(Skeleton), findsNothing);
      });
    }

    testWidgets('a single slide has the same shape, and no peeking neighbours', (t) async {
      await _pump(
        t,
        sliderSection([threeSlides().first], settings: {'aspect': '4:3'}),
        pictures: (_) => const NeverLoadingPicture(),
      );
      final slide = find.descendant(of: find.byType(HomeSlider), matching: find.byType(ClipRRect)).first;
      expect(t.getSize(slide), const Size(350, 350 * 3 / 4));
      expect(t.getTopLeft(slide).dx, 20);
    });

    testWidgets('an aspect the app does not know is 16:9, and a feed without any slider settings uses every default', (
      t,
    ) async {
      final section = HomeSection.fromJson(sliderJson(threeSlides(), withSettings: false))! as BannersSection;
      await _pump(t, section);
      final slide = find.descendant(of: find.byType(HomeSlider), matching: find.byType(ClipRRect)).first;
      expect(t.getSize(slide).height, closeTo(350 / (16 / 9), 0.01));
      expect(find.byKey(const ValueKey('slider-dots')), findsOneWidget, reason: 'dots on');
      expect(find.text('Slide A'), findsOneWidget, reason: 'titles on');
      final clip = t.widget<ClipRRect>(slide);
      expect(clip.borderRadius, isNot(BorderRadius.zero), reason: 'rounded');
      await _wait(t, const Duration(seconds: 5) + _glide);
      expect(_front(t), 'B', reason: 'autoplay every 5 seconds');
    });

    testWidgets('rounded off: edge to edge, square corners, no peeking', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'rounded': false}));
      final slide = find.descendant(of: find.byType(HomeSlider), matching: find.byType(ClipRRect)).first;
      expect(t.getSize(slide).width, 390);
      expect(t.widget<ClipRRect>(slide).borderRadius, BorderRadius.zero);
      expect(t.getTopLeft(slide).dx, 0);
    });

    testWidgets('dots off: none are drawn and no space is kept for them', (t) async {
      await _pump(t, sliderSection(threeSlides(), settings: {'dots': false}));
      expect(find.byKey(const ValueKey('slider-dots')), findsNothing);
      expect(t.getSize(find.byType(HomeSlider)).height, closeTo(350 / (16 / 9), 0.01));
    });

    testWidgets('titles off: the picture shows alone, though a screen reader still gets the words', (t) async {
      final handle = t.ensureSemantics();
      await _pump(t, sliderSection(threeSlides(), settings: {'show_titles': false}));
      expect(find.text('Slide A'), findsNothing);
      expect(find.text('About A'), findsNothing);
      expect(find.byIcon(Icons.arrow_forward_rounded), findsNothing);
      expect(find.bySemanticsLabel('Banner 1 of 3. Slide A. About A'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('titles on: title and subtitle over the picture; a slide with no words shows none', (t) async {
      await _pump(t, sliderSection([slideJson('a', title: 'Only a title', link: null), slideJson('b', link: null)]));
      expect(find.text('Only a title'), findsOneWidget);
    });

    testWidgets('many slides are counted instead of drawn as dots', (t) async {
      final many = [for (var i = 0; i < 12; i++) slideJson('s$i', title: 'Slide $i', link: null)];
      await _pump(t, sliderSection(many, settings: {'autoplay': false}));
      expect(find.byKey(const ValueKey('slider-dots')), findsNothing);
      expect(find.text('1 / 12'), findsOneWidget);
      await t.fling(find.byType(PageView), const Offset(-300, 0), 1200);
      await _wait(t, _glide);
      expect(find.text('2 / 12'), findsOneWidget);
    });

    testWidgets('a section title and subtitle sit above the slider', (t) async {
      await _pump(t, sliderSection(threeSlides(), title: 'Eid offers', subtitle: 'Until Friday'));
      expect(find.text('Eid offers'), findsOneWidget);
      expect(find.text('Until Friday'), findsOneWidget);
      expect(t.getTopLeft(find.text('Eid offers')).dy, lessThan(t.getTopLeft(find.byType(PageView)).dy));
    });

    testWidgets('no section title: no header', (t) async {
      await _pump(t, sliderSection(threeSlides()));
      expect(find.byType(SectionHeader), findsNothing);
    });

    testWidgets('a long title and subtitle are shortened, never overflowing, at a large text size', (t) async {
      await _pump(
        t,
        sliderSection(
          [
            slideJson(
              'a',
              title: 'A very long title about the whole autumn collection for every room of the home ' * 2,
              subtitle: 'And a subtitle ' * 12,
              link: {'type': 'search', 'value': 'x'},
            ),
            slideJson('b', title: 'B'),
          ],
          settings: {'aspect': '3:1'},
        ),
        width: 320,
        textScale: 2,
      );
      expect(t.takeException(), isNull);
    });
  });

  group('pictures', () {
    testWidgets('a picture that cannot load shows the on-brand backdrop, with the words kept', (t) async {
      await _pump(t, sliderSection(threeSlides()), pictures: (_) => const BrokenPicture());
      await t.pump(const Duration(milliseconds: 100));
      expect(find.byIcon(Icons.image_not_supported_outlined), findsWidgets);
      expect(find.text('Slide A'), findsOneWidget);
      expect(t.takeException(), isNull);
    });

    testWidgets('a slide without a picture address shows the backdrop', (t) async {
      await _pump(
        t,
        sliderSection([
          {...slideJson('a', title: 'Slide A'), 'image_url': null},
          slideJson('b', title: 'Slide B'),
        ]),
      );
      expect(find.text('Slide A'), findsOneWidget);
      expect(find.byType(Image), findsWidgets);
    });

    testWidgets('the picture of the next slide is fetched before it is shown', (t) async {
      final asked = <String>[];
      await _pump(t, sliderSection(threeSlides(), settings: {'rounded': false}), asked: asked);
      expect(asked, contains('https://cdn.test/slide-A.jpg'));
      expect(asked, contains('https://cdn.test/slide-B.jpg'), reason: 'ahead of its turn');
      expect(asked, isNot(contains('https://cdn.test/slide-C.jpg')));
      await _wait(t, const Duration(seconds: 5) + _glide);
      expect(asked, contains('https://cdn.test/slide-C.jpg'), reason: 'fetched while B is in front');
    });
  });

  group('a new feed', () {
    testWidgets('other slides start again from the first; the same slides keep their place', (t) async {
      useScreen(t);
      final pictures = await fakePictures(t);
      final feed = ValueNotifier<BannersSection>(sliderSection(threeSlides(), settings: {'autoplay': false}));
      addTearDown(feed.dispose);
      await t.pumpWidget(
        sliderHost(
          ValueListenableBuilder<BannersSection>(
            valueListenable: feed,
            builder: (context, s, _) => HomeSlider(section: s),
          ),
          pictures: pictures,
        ),
      );
      await t.pump();
      await t.fling(find.byType(PageView), const Offset(-300, 0), 1200);
      await _wait(t, _glide);
      expect(_front(t), 'B');

      // The same slides, a new title: it stays on the second one.
      feed.value = sliderSection(
        [
          for (final s in threeSlides()) {...s, 'title': 'Slide ${(s['id']! as String).substring(6)}'},
        ],
        settings: {'autoplay': false},
      );
      await t.pump();
      await t.pump(const Duration(milliseconds: 50));
      expect(_front(t), 'B');

      feed.value = sliderSection(
        [slideJson('x1', title: 'Slide X'), slideJson('y1', title: 'Slide Y')],
        settings: {'autoplay': false},
      );
      await t.pump();
      await t.pump(const Duration(milliseconds: 50));
      expect(_front(t), 'X');
      expect(t.takeException(), isNull);
    });
  });

  group('taps and what is announced', () {
    testWidgets('a slide with a link opens it; a slide without one does nothing', (t) async {
      final links = await _pump(
        t,
        sliderSection(
          [
            slideJson('a', title: 'Slide A', link: {'type': 'category', 'value': 'c1'}),
            slideJson('b', title: 'Slide B', link: null),
          ],
          settings: {'autoplay': false},
        ),
      );
      await t.tap(find.text('Slide A'), warnIfMissed: false);
      await t.pump();
      expect(links.locations, ['/categories/c1']);

      await t.fling(find.byType(PageView), const Offset(-300, 0), 1200);
      await _wait(t, _glide);
      links.locations.clear();
      await t.tap(find.text('Slide B'), warnIfMissed: false);
      await t.pump();
      expect(links.locations, isEmpty);
      expect(links.launched, isEmpty);
    });

    testWidgets('only a slide with a link has a ripple and a pointer', (t) async {
      await _pump(
        t,
        sliderSection(
          [
            slideJson('a', title: 'Slide A', link: {'type': 'category', 'value': 'c1'}),
            slideJson('b', title: 'Slide B', link: null),
          ],
          settings: {'autoplay': false},
        ),
      );
      // Slide A is in front; B waits on the side. Exactly one ripple area exists: A's.
      expect(find.descendant(of: find.byType(HomeSlider), matching: find.byType(InkWell)), findsOneWidget);

      final mouse = await t.createGesture(kind: PointerDeviceKind.mouse);
      await mouse.addPointer(location: const Offset(1, 1));
      addTearDown(mouse.removePointer);
      await mouse.moveTo(t.getCenter(find.text('Slide A')));
      await t.pump();
      expect(t.binding.mouseTracker.debugDeviceActiveCursor(1), SystemMouseCursors.click);

      await t.fling(find.byType(PageView), const Offset(-300, 0), 1200);
      await _wait(t, _glide);
      await mouse.moveTo(t.getCenter(find.text('Slide B')));
      await t.pump();
      expect(t.binding.mouseTracker.debugDeviceActiveCursor(1), SystemMouseCursors.basic);
    });

    testWidgets('a link that cannot open makes the slide an ordinary picture', (t) async {
      final links = await _pump(
        t,
        sliderSection(
          [
            slideJson('a', title: 'Slide A', link: {'type': 'url', 'value': 'http://example.com'}),
            slideJson('b', title: 'Slide B', link: {'type': 'teleport', 'value': 'x'}),
          ],
          settings: {'autoplay': false},
        ),
      );
      expect(find.descendant(of: find.byType(HomeSlider), matching: find.byType(InkWell)), findsNothing);
      await t.tap(find.text('Slide A'), warnIfMissed: false);
      await t.pump();
      expect(links.locations, isEmpty);
      expect(links.launched, isEmpty);
    });

    testWidgets('announced as "Banner n of m" with its words; a link makes it a button, a picture alone is not one', (
      t,
    ) async {
      final handle = t.ensureSemantics();
      await _pump(
        t,
        sliderSection(
          [
            slideJson('a', title: 'Slide A', subtitle: 'About A', link: {'type': 'category', 'value': 'c1'}),
            slideJson('b', title: 'Slide B', subtitle: 'About B', link: null),
            slideJson('c', title: 'Slide C', link: null),
          ],
          settings: {'autoplay': false},
        ),
      );
      final linked = t.getSemantics(find.bySemanticsLabel('Banner 1 of 3. Slide A. About A')).getSemanticsData();
      expect(linked.flagsCollection.isButton, isTrue);
      expect(linked.hasAction(SemanticsAction.tap), isTrue);

      final plain = t.getSemantics(find.bySemanticsLabel('Banner 2 of 3. Slide B. About B')).getSemanticsData();
      expect(plain.flagsCollection.isButton, isFalse);
      expect(plain.hasAction(SemanticsAction.tap), isFalse);
      expect(plain.flagsCollection.isImage, isTrue);
      handle.dispose();
    });

    testWidgets('the announcement is in Arabic for an Arabic customer', (t) async {
      final handle = t.ensureSemantics();
      await _pump(t, sliderSection(_arabic()), locale: const Locale('ar'));
      expect(find.bySemanticsLabel('إعلان 1 من 3. الشريحة أ'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('a lone slide with no words is still announced, and the tap targets are labelled', (t) async {
      final handle = t.ensureSemantics();
      await _pump(
        t,
        sliderSection([
          slideJson('a', link: {'type': 'page', 'value': 'terms'}),
        ]),
      );
      expect(find.bySemanticsLabel('Banner 1 of 1'), findsOneWidget);
      await expectLater(t, meetsGuideline(labeledTapTargetGuideline));
      await expectLater(t, meetsGuideline(androidTapTargetGuideline));
      handle.dispose();
    });

    testWidgets('a lone slide with words is announced by them, without "1 of 1"', (t) async {
      final handle = t.ensureSemantics();
      await _pump(t, sliderSection([threeSlides().first]));
      expect(find.bySemanticsLabel('Slide A. About A'), findsOneWidget);
      handle.dispose();
    });
  });

  group('every phone, language, text size and theme', () {
    for (final (name, width, locale, scale, dark) in [
      ('320 en', 320.0, const Locale('en'), 1.0, false),
      ('390 ar dark', 390.0, const Locale('ar'), 1.0, true),
      ('390 en 1.6x', 390.0, const Locale('en'), 1.6, false),
      ('768 ar', 768.0, const Locale('ar'), 1.0, false),
      ('1400 en dark', 1400.0, const Locale('en'), 1.0, true),
    ]) {
      testWidgets('fits without overflow: $name', (t) async {
        for (final aspect in ['16:9', '21:9', '3:1', '4:3', '1:1']) {
          await _pump(
            t,
            sliderSection(locale.languageCode == 'ar' ? _arabic() : threeSlides(), settings: {'aspect': aspect}),
            width: width,
            locale: locale,
            dark: dark,
            textScale: scale,
          );
          expect(t.takeException(), isNull, reason: '$name $aspect');
          // Never wider than the cap, and centred beyond it.
          expect(t.getSize(find.byType(PageView)).width, lessThanOrEqualTo(HomeSlider.maxWidth));
        }
      });
    }
  });
}

Finder get _dots =>
    find.descendant(of: find.byKey(const ValueKey('slider-dots')), matching: find.byType(AnimatedContainer));

List<Map<String, dynamic>> _arabic() => [
  for (final (id, name) in [('slide-A', 'أ'), ('slide-B', 'ب'), ('slide-C', 'ج')])
    slideJson(id, title: 'الشريحة $name', link: {'type': 'category', 'value': 'c-$id'}),
];
