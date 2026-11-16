// Home slide banners: reading the feed (slider settings, typed links), the order of sliders among the other sections in both layouts,
// and the real home showing two sliders where the dashboard put them.
import 'package:albahar_customer/app.dart';
import 'package:albahar_customer/core/widgets/common.dart' show SectionHeader;
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/home/presentation/home_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import '../helpers/home_slider_fakes.dart';

HomeLink _link(String type, String value) => HomeLink.fromJson({'type': type, 'value': value});

void main() {
  group('slider settings', () {
    test('every key is optional: an older feed, a null or a wrong type uses the defaults', () {
      for (final json in <Object?>[null, 'oops', 7, <String>[], <String, Object>{}]) {
        final s = SliderSettings.fromJson(json);
        expect(s.autoplay, isTrue, reason: '$json');
        expect(s.intervalSeconds, 5);
        expect(s.aspect, SliderAspect.wide);
        expect(s.dots, isTrue);
        expect(s.showTitles, isTrue);
        expect(s.rounded, isTrue);
      }
      expect(SliderSettings.fromJson(null), const SliderSettings());
    });

    test('each key is read: autoplay, interval, aspect, dots, titles, corners', () {
      final s = SliderSettings.fromJson({
        'autoplay': false,
        'interval': 9,
        'aspect': '21:9',
        'dots': false,
        'show_titles': false,
        'rounded': false,
      });
      expect(s.autoplay, isFalse);
      expect(s.intervalSeconds, 9);
      expect(s.aspect, SliderAspect.cinema);
      expect(s.dots, isFalse);
      expect(s.showTitles, isFalse);
      expect(s.rounded, isFalse);
    });

    test('the interval is held between 3 and 15 seconds, and read from numbers or text', () {
      expect(SliderSettings.fromJson({'interval': 0}).intervalSeconds, 3);
      expect(SliderSettings.fromJson({'interval': 2}).intervalSeconds, 3);
      expect(SliderSettings.fromJson({'interval': 3}).intervalSeconds, 3);
      expect(SliderSettings.fromJson({'interval': 15}).intervalSeconds, 15);
      expect(SliderSettings.fromJson({'interval': 99}).intervalSeconds, 15);
      expect(SliderSettings.fromJson({'interval': '8'}).intervalSeconds, 8);
      expect(SliderSettings.fromJson({'interval': 6.9}).intervalSeconds, 6);
      expect(SliderSettings.fromJson({'interval': 'soon'}).intervalSeconds, 5);
      expect(SliderSettings.fromJson({'interval': null}).intervalSeconds, 5);
    });

    test('the five aspects and their shapes; anything else is 16:9', () {
      expect(
        {for (final a in SliderAspect.values) a.code: a.ratio},
        {'16:9': 16 / 9, '21:9': 21 / 9, '3:1': 3.0, '4:3': 4 / 3, '1:1': 1.0},
      );
      for (final a in SliderAspect.values) {
        expect(SliderSettings.fromJson({'aspect': a.code}).aspect, a);
      }
      for (final odd in <Object?>['5:1', '', null, 169, 'wide', '2:1', '9:16']) {
        expect(SliderAspect.fromCode(odd), SliderAspect.wide, reason: '$odd');
      }
      expect(SliderAspect.fromCode(' 3:1 '), SliderAspect.banner);
      expect(SliderAspect.fromCode('3/1'), SliderAspect.banner);
    });

    test('two settings with the same values are equal', () {
      expect(
        SliderSettings.fromJson({'aspect': '1:1', 'interval': 7}),
        SliderSettings.fromJson({'aspect': '1:1', 'interval': 7}),
      );
      expect(SliderSettings.fromJson({'aspect': '1:1'}), isNot(SliderSettings.fromJson({'aspect': '4:3'})));
      expect(SliderSettings.fromJson({'dots': false}).hashCode, isNot(const SliderSettings().hashCode));
    });
  });

  group('links', () {
    test('every type the dashboard offers opens the right place', () {
      expect(_link('category', 'c-1').location, '/categories/c-1');
      expect(_link('page', 'terms').location, '/pages/terms');
      expect(_link('product', 'p-1').location, '/products/p-1');
      expect(_link('brand', 'b-1').location, '/products?brand_id=b-1');
      expect(_link('auction', 'a-1').location, '/auctions/a-1');
      expect(_link('search', 'sofa bed').location, '/search?q=sofa+bed');
      expect(_link('url', 'https://albahar.qa/eid').location, isNull, reason: 'an address is not an in-app place');
      expect(_link('url', 'https://albahar.qa/eid').externalUri, Uri.parse('https://albahar.qa/eid'));
    });

    test('the older types promotions use still open', () {
      expect(_link('lot', 'l-1').location, '/auctions/lots/l-1');
      expect(_link('collection', 'featured=1').location, '/products?featured=1');
    });

    test('a value with unusual characters is encoded, not broken', () {
      expect(_link('page', 'عيد الفطر').location, '/pages/${Uri.encodeComponent('عيد الفطر')}');
      expect(_link('search', 'a&b=c').location, '/search?q=a%26b%3Dc');
      expect(_link('category', 'a/b').location, '/categories/a%2Fb');
    });

    test('the type is read without regard to case and spaces', () {
      expect(_link(' Category ', ' c-1 ').location, '/categories/c-1');
      expect(_link('URL', ' https://albahar.qa ').externalUri, Uri.parse('https://albahar.qa'));
    });

    test('only an https address can open: not http, javascript, tel, a bare word or nothing', () {
      expect(_link('url', 'https://albahar.qa').isOpenable, isTrue);
      for (final bad in [
        'http://albahar.qa',
        'javascript:alert(1)',
        'tel:+97455123456',
        'mailto:hi@albahar.qa',
        'file:///etc/passwd',
        'albahar.qa',
        '//albahar.qa',
        'https://',
        'https:///path',
        'ftp://albahar.qa',
        '',
        '   ',
      ]) {
        expect(_link('url', bad).isOpenable, isFalse, reason: bad);
        expect(_link('url', bad).externalUri, isNull, reason: bad);
      }
    });

    test('an unknown type or an empty value cannot open', () {
      expect(_link('teleport', 'x').isOpenable, isFalse);
      expect(_link('', 'x').isOpenable, isFalse);
      for (final type in ['category', 'page', 'product', 'brand', 'auction', 'search']) {
        expect(_link(type, '').isOpenable, isFalse, reason: type);
        expect(_link(type, '  ').isOpenable, isFalse, reason: type);
        expect(_link(type, 'v').isOpenable, isTrue, reason: type);
      }
    });

    test('a slide is tappable only when it has a link that can open', () {
      HomeBanner banner(Object? link) => HomeBanner.fromJson({'id': 'b', 'link': link});
      expect(banner(null).link, isNull);
      expect(banner(null).isTappable, isFalse);
      expect(banner('oops').isTappable, isFalse);
      expect(banner({'type': 'category', 'value': 'c'}).isTappable, isTrue);
      expect(banner({'type': 'url', 'value': 'http://x.qa'}).isTappable, isFalse);
      expect(banner({'type': 'nope', 'value': 'c'}).isTappable, isFalse);
      expect(banner({'type': 'category'}).isTappable, isFalse);
    });
  });

  group('the slider section', () {
    test('reads its slides, words, links and settings', () {
      final section =
          HomeSection.fromJson({
                'id': 'sec-1',
                'type': 'banners',
                'layout': 'carousel',
                'title': 'Eid offers',
                'subtitle': 'Until Friday',
                'slider': {
                  'autoplay': false,
                  'interval': 8,
                  'aspect': '3:1',
                  'dots': true,
                  'show_titles': false,
                  'rounded': true,
                },
                'items': [
                  {
                    'id': 'b1',
                    'title': ' Sofas ',
                    'subtitle': null,
                    'image_url': 'https://cdn.test/1.jpg',
                    'link': {'type': 'category', 'value': 'c1'},
                  },
                  {'id': 'b2', 'title': null, 'subtitle': '', 'image_url': 'https://cdn.test/2.jpg', 'link': null},
                ],
              })!
              as BannersSection;
      expect(section.id, 'sec-1');
      expect(section.title, 'Eid offers');
      expect(section.subtitle, 'Until Friday');
      expect(section.slider.aspect, SliderAspect.banner);
      expect(section.slider.autoplay, isFalse);
      expect(section.slider.intervalSeconds, 8);
      expect(section.slider.showTitles, isFalse);
      expect(section.banners.map((b) => b.id), ['b1', 'b2']);
      expect(section.banners.first.title, 'Sofas');
      expect(section.banners.first.hasText, isTrue);
      expect(section.banners.first.link!.location, '/categories/c1');
      expect(section.banners.last.title, isNull);
      expect(section.banners.last.subtitle, isNull, reason: 'an empty subtitle is none');
      expect(section.banners.last.hasText, isFalse);
      expect(section.banners.last.link, isNull);
    });

    test('a feed from an older server (no slider key) uses the defaults', () {
      final section = HomeSection.fromJson(sliderJson(threeSlides(), withSettings: false))! as BannersSection;
      expect(section.slider, const SliderSettings());
    });

    test('a slider with no slides is not drawn', () {
      expect(HomeSection.fromJson(sliderJson(const [])), isNull);
      expect(HomeSection.fromJson({'id': 'x', 'type': 'banners'}), isNull);
    });

    test('a slide that is not an object is skipped, not fatal', () {
      final section =
          HomeSection.fromJson({
                'id': 'x',
                'type': 'banners',
                'items': [
                  'oops',
                  null,
                  7,
                  {'id': 'ok', 'image_url': 'https://cdn.test/ok.jpg'},
                ],
              })!
              as BannersSection;
      expect(section.banners.map((b) => b.id), ['ok']);
    });

    test('several sliders keep the order the server sent them in, among the other sections', () {
      final feed = HomeFeed.fromJson({
        'sections': [
          sliderJson(threeSlides(), id: 'slider-1'),
          {
            'id': 'cats',
            'type': 'categories',
            'items': [
              {'id': 'c1', 'name': 'Living', 'slug': 'living'},
            ],
          },
          sliderJson(threeSlides(), id: 'slider-2'),
          {
            'id': 'best',
            'type': 'best_sellers',
            'items': [productCard('1')],
          },
          sliderJson(threeSlides(), id: 'slider-3'),
        ],
      });
      expect(feed.sections.map((s) => s.id), ['slider-1', 'cats', 'slider-2', 'best', 'slider-3']);
      expect(feed.sections.whereType<BannersSection>(), hasLength(3));
    });
  });

  group('the order of the fresh home', () {
    HomeSection slider(String id) => HomeSection.fromJson(sliderJson(threeSlides(), id: id))!;
    HomeSection categories() => HomeSection.fromJson({
      'id': 'cats',
      'type': 'categories',
      'items': [
        {'id': 'c1', 'name': 'Living', 'slug': 'living'},
      ],
    })!;
    HomeSection products(String id, String type) => HomeSection.fromJson({
      'id': id,
      'type': type,
      'items': [productCard('1')],
    })!;

    test('without a slider the sections are ranked as before', () {
      final sections = [products('new', 'new_arrivals'), categories(), products('best', 'best_sellers')];
      expect(freshSectionOrder(sections).map((s) => s.id), ['cats', 'best', 'new']);
    });

    test('a single slider that comes first stays first', () {
      final sections = [slider('s1'), products('new', 'new_arrivals'), categories()];
      expect(freshSectionOrder(sections).map((s) => s.id), ['s1', 'cats', 'new']);
    });

    test('a slider follows the section it was placed after, wherever that section is moved to', () {
      final sections = [
        slider('s1'),
        products('new', 'new_arrivals'),
        slider('s2'),
        products('best', 'best_sellers'),
        categories(),
        slider('s3'),
      ];
      // Others are ranked: categories, best sellers, new arrivals. s2 followed new arrivals; s3 followed the categories.
      expect(freshSectionOrder(sections).map((s) => s.id), ['s1', 'cats', 's3', 'best', 'new', 's2']);
    });

    test('sliders placed one after another stay in that order', () {
      final sections = [categories(), slider('s1'), slider('s2'), products('best', 'best_sellers')];
      expect(freshSectionOrder(sections).map((s) => s.id), ['cats', 's1', 's2', 'best']);
    });

    test('several sliders at the very top stay in their order', () {
      final sections = [slider('s1'), slider('s2'), categories()];
      expect(freshSectionOrder(sections).map((s) => s.id), ['s1', 's2', 'cats']);
    });

    test('nothing is added or removed', () {
      final sections = [slider('s1'), categories(), slider('s2'), products('best', 'best_sellers'), slider('s3')];
      expect(freshSectionOrder(sections).map((s) => s.id).toSet(), sections.map((s) => s.id).toSet());
      expect(freshSectionOrder(sections), hasLength(sections.length));
      expect(freshSectionOrder(const []), isEmpty);
    });
  });

  group('the home screen', () {
    Map<String, Object?> theme({required bool fresh}) => {
      'key': 'default',
      'kind': 'base',
      'layout': fresh ? 'fresh' : 'classic',
      'tokens': fresh
          ? {
              'radius': 'xl',
              'card': 'soft',
              'header': 'brand',
              'nav': 'center_action',
              'background': 'gradient',
              'density': 'comfortable',
            }
          : null,
      'footer': null,
      'design': {'key': 'fresh', 'name_en': 'Fresh', 'name_ar': 'منعش', 'name': 'Fresh'},
      'colors': {
        'primary': '#8A1538',
        'primary_soft': '#A3294E',
        'accent': '#B3254F',
        'background': '#FCF9FA',
        'surface': '#FFFFFF',
        'ink': '#2A1A20',
      },
    };

    // The dashboard's order: slider 1, "Fresh in", slider 2, "Top picks", "Rooms", slider 3.
    Map<String, dynamic> feed() => {
      'sections': [
        sliderJson([
          slideJson('a1', title: 'Slide ONE', image: ''),
          slideJson('a2', title: 'Slide ONE b', image: ''),
        ], id: 'slider-1'),
        {
          'id': 'new',
          'type': 'new_arrivals',
          'title': 'Fresh in',
          'items': [productCard('1')],
        },
        sliderJson([slideJson('b1', title: 'Slide TWO', image: '')], id: 'slider-2'),
        {
          'id': 'best',
          'type': 'best_sellers',
          'title': 'Top picks',
          'items': [productCard('2')],
        },
        {
          'id': 'cats',
          'type': 'categories',
          'title': 'Rooms',
          'items': [
            {'id': 'c1', 'name': 'Living', 'slug': 'living'},
          ],
        },
        sliderJson([slideJson('c1', title: 'Slide THREE', image: '')], id: 'slider-3'),
      ],
    };

    Future<void> boot(WidgetTester t, {required bool fresh, String locale = 'en'}) async {
      await t.binding.setSurfaceSize(const Size(390, 4200));
      addTearDown(() => t.binding.setSurfaceSize(null));
      final h = await TestHarness.create(
        prefs: {'locale': locale, 'onboarding_done': true},
        handler: routes({
          'GET /theme': (_) => ok(theme(fresh: fresh)),
          'GET /home': (_) => ok(feed()),
          'GET /categories': (_) => ok([]),
        }),
      );
      await t.pumpWidget(
        UncontrolledProviderScope(container: h.container, child: const AlBaharApp(watchConnectivity: false)),
      );
      await t.pump(const Duration(milliseconds: 1600));
      await t.pump(const Duration(milliseconds: 500));
    }

    List<String> order(WidgetTester t, List<String> labels) {
      final tops = <String, double>{
        for (final l in labels)
          if (find.text(l).evaluate().isNotEmpty) l: t.getTopLeft(find.text(l).first).dy,
      };
      final known = tops.keys.toList()..sort((a, b) => tops[a]!.compareTo(tops[b]!));
      return known;
    }

    Future<void> teardown(WidgetTester t) async {
      await t.pumpWidget(const SizedBox.shrink());
      await t.pump(const Duration(seconds: 10));
    }

    const labels = ['Slide ONE', 'Slide TWO', 'Slide THREE', 'Fresh in', 'Top picks', 'Rooms'];

    testWidgets('classic: each slider sits where the dashboard put it among the other sections', (t) async {
      await boot(t, fresh: false);
      expect(t.takeException(), isNull);
      expect(find.byType(HomeSectionView), findsNWidgets(6));
      expect(order(t, labels), ['Slide ONE', 'Fresh in', 'Slide TWO', 'Top picks', 'Rooms', 'Slide THREE']);
      await teardown(t);
      expect(t.takeException(), isNull);
    });

    testWidgets('fresh: the other sections are ranked, and every slider stays right after the section it followed', (
      t,
    ) async {
      await boot(t, fresh: true);
      expect(t.takeException(), isNull);
      expect(find.byType(HomeSectionView), findsNWidgets(6));
      // Ranked: Rooms, Top picks, Fresh in. Slider 2 followed Fresh in; slider 3 followed Rooms; slider 1 came first.
      expect(order(t, labels), ['Slide ONE', 'Rooms', 'Slide THREE', 'Top picks', 'Fresh in', 'Slide TWO']);
      await teardown(t);
      expect(t.takeException(), isNull);
    });

    testWidgets('Arabic: the sliders are in the same places and the page lays out without overflow', (t) async {
      await boot(t, fresh: false, locale: 'ar');
      expect(t.takeException(), isNull);
      expect(find.byType(HomeSectionView), findsNWidgets(6));
      await teardown(t);
    });

    testWidgets('the section headers are only for sections that have a title', (t) async {
      await boot(t, fresh: false);
      // "Fresh in", "Top picks" and "Rooms" have headers; none of the three untitled sliders adds one.
      expect(find.byType(SectionHeader), findsNWidgets(3));
      await teardown(t);
    });
  });
}
