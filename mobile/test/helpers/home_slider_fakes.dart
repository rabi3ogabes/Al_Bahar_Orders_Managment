// Fakes for the home sliders: pictures that need no network, a feed section built the way the server sends it, and a host that
// gives a slider the app's theme, language and a controllable window.
import 'dart:async';
import 'dart:ui' as ui;

import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/features/home/domain/home_models.dart';
import 'package:albahar_customer/features/home/presentation/home_link_opener.dart';
import 'package:albahar_customer/features/home/presentation/home_slider.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

/// A picture that is there at once (no decoding, no network).
class FakePicture extends ImageProvider<FakePicture> {
  const FakePicture(this.image, this.url);
  final ui.Image image;
  final String url;

  @override
  Future<FakePicture> obtainKey(ImageConfiguration configuration) => SynchronousFuture<FakePicture>(this);

  @override
  ImageStreamCompleter loadImage(FakePicture key, ImageDecoderCallback decode) =>
      OneFrameImageStreamCompleter(SynchronousFuture(ImageInfo(image: image.clone())));

  @override
  bool operator ==(Object other) => other is FakePicture && other.url == url;

  @override
  int get hashCode => url.hashCode;
}

/// A picture that never arrives (a slow connection): the placeholder stays.
class NeverLoadingPicture extends ImageProvider<NeverLoadingPicture> {
  const NeverLoadingPicture();

  @override
  Future<NeverLoadingPicture> obtainKey(ImageConfiguration configuration) =>
      SynchronousFuture<NeverLoadingPicture>(this);

  @override
  ImageStreamCompleter loadImage(NeverLoadingPicture key, ImageDecoderCallback decode) =>
      OneFrameImageStreamCompleter(Completer<ImageInfo>().future);
}

/// A picture that fails (a 404, no connection): the on-brand backdrop shows.
class BrokenPicture extends ImageProvider<BrokenPicture> {
  const BrokenPicture();

  @override
  Future<BrokenPicture> obtainKey(ImageConfiguration configuration) => SynchronousFuture<BrokenPicture>(this);

  @override
  ImageStreamCompleter loadImage(BrokenPicture key, ImageDecoderCallback decode) =>
      OneFrameImageStreamCompleter(Future<ImageInfo>.error(Exception('404')));
}

/// Gives every address a ready picture, and notes which addresses were asked for (so a test can see the next slide being fetched
/// ahead of time).
Future<SlideImageBuilder> fakePictures(WidgetTester tester, {List<String>? asked}) async {
  late final ui.Image image;
  await tester.runAsync(() async {
    final recorder = ui.PictureRecorder();
    Canvas(recorder).drawRect(const Rect.fromLTWH(0, 0, 16, 9), Paint()..color = const Color(0xFFD9B99B));
    image = await recorder.endRecording().toImage(16, 9);
  });
  return (url) {
    asked?.add(url);
    return FakePicture(image, url);
  };
}

Map<String, dynamic> slideJson(
  String id, {
  String? title,
  String? subtitle,
  Map<String, dynamic>? link,
  String? image,
}) => {'id': id, 'title': title, 'subtitle': subtitle, 'image_url': image ?? 'https://cdn.test/$id.jpg', 'link': link};

Map<String, dynamic> sliderJson(
  List<Map<String, dynamic>> slides, {
  String id = 'sec1',
  String? title,
  String? subtitle,
  Map<String, dynamic>? settings,
  bool withSettings = true,
}) => {
  'id': id,
  'type': 'banners',
  'layout': 'carousel',
  'title': title,
  'subtitle': subtitle,
  if (withSettings)
    'slider': {
      'autoplay': true,
      'interval': 5,
      'aspect': '16:9',
      'dots': true,
      'show_titles': true,
      'rounded': true,
      ...?settings,
    },
  'items': slides,
};

BannersSection sliderSection(
  List<Map<String, dynamic>> slides, {
  String id = 'sec1',
  String? title,
  String? subtitle,
  Map<String, dynamic>? settings,
}) =>
    HomeSection.fromJson(sliderJson(slides, id: id, title: title, subtitle: subtitle, settings: settings))!
        as BannersSection;

/// Three titled slides A, B and C, each with a link unless [link] is false.
List<Map<String, dynamic>> threeSlides({bool link = true}) => [
  for (final (i, name) in ['A', 'B', 'C'].indexed)
    slideJson(
      'slide-$name',
      title: 'Slide $name',
      subtitle: 'About $name',
      link: link ? {'type': 'category', 'value': 'cat-${i + 1}'} : null,
    ),
];

/// A [HomeLinkOpener] that notes what it was asked to open instead of opening it.
class OpenedLinks {
  final locations = <String>[];
  final launched = <Uri>[];
  bool launchResult = true;
  Object? launchError;
  Object? locationError;

  HomeLinkOpener get opener => HomeLinkOpener(
    openLocation: (context, location) async {
      locations.add(location);
      if (locationError != null) throw locationError!;
    },
    launch: (uri) async {
      launched.add(uri);
      if (launchError != null) throw launchError!;
      return launchResult;
    },
  );
}

/// The app's theme and language around [child], with the sliders' pictures and link doors replaced.
Widget sliderHost(
  Widget child, {
  required SlideImageBuilder pictures,
  OpenedLinks? links,
  Locale locale = const Locale('en'),
  bool dark = false,
  double textScale = 1,
  bool disableAnimations = false,
  bool screenReader = false,
  List<Override> overrides = const [],
}) => ProviderScope(
  overrides: [
    homeSliderImageProvider.overrideWithValue(pictures),
    if (links != null) homeLinkOpenerProvider.overrideWithValue(links.opener),
    ...overrides,
  ],
  child: MaterialApp(
    locale: locale,
    supportedLocales: const [Locale('en'), Locale('ar')],
    localizationsDelegates: const [
      AppLocalizations.delegate,
      GlobalMaterialLocalizations.delegate,
      GlobalWidgetsLocalizations.delegate,
      GlobalCupertinoLocalizations.delegate,
    ],
    theme: AppTheme.light(locale),
    darkTheme: AppTheme.dark(locale),
    themeMode: dark ? ThemeMode.dark : ThemeMode.light,
    builder: (context, app) => MediaQuery(
      data: MediaQuery.of(context).copyWith(
        textScaler: TextScaler.linear(textScale),
        disableAnimations: disableAnimations,
        accessibleNavigation: screenReader,
      ),
      child: app!,
    ),
    home: Scaffold(body: SingleChildScrollView(child: child)),
  ),
);

void useScreen(WidgetTester tester, {double width = 390, double height = 844}) {
  tester.view.physicalSize = Size(width, height);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}
