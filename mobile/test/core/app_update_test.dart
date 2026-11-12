import 'dart:async';

import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:albahar_customer/core/update/app_update.dart';
import 'package:albahar_customer/l10n/generated/app_localizations.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';

Widget _host(Widget child, {String lang = 'en'}) => MaterialApp(
  locale: Locale(lang),
  supportedLocales: const [Locale('en'), Locale('ar')],
  localizationsDelegates: const [
    AppLocalizations.delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  theme: AppTheme.light(Locale(lang)),
  home: child,
);

void main() {
  setUp(() {
    updateRequired.value = null;
    AppIdentity.app = 'driver';
    AppIdentity.build = 12;
  });

  test('every request carries the app, platform and build', () {
    final opts = RequestOptions(path: '/x');
    AppIdentityInterceptor().onRequest(opts, RequestInterceptorHandler());
    expect(opts.headers['X-App'], 'driver');
    expect(opts.headers['X-App-Build'], 12);
    expect(opts.headers['X-App-Platform'], anyOf('android', 'ios'));
  });

  test('an unknown build sends no build header, so the server never blocks it', () {
    AppIdentity.build = 0;
    final opts = RequestOptions(path: '/x');
    AppIdentityInterceptor().onRequest(opts, RequestInterceptorHandler());
    expect(opts.headers.containsKey('X-App-Build'), isFalse);
  });

  test('HTTP 426 raises the forced-update screen with the server message', () {
    final req = RequestOptions(path: '/x');
    final err = DioException(
      requestOptions: req,
      response: Response(
        requestOptions: req,
        statusCode: 426,
        data: {
          'code': 'update_required',
          'update': {
            'status': 'required',
            'latest_version': '1.4.0',
            'store_url': 'https://play.google.com/x',
            'message': {'en': 'Please update', 'ar': 'يرجى التحديث'},
          },
        },
      ),
    );
    // The interceptor also forwards the error; that completes the handler's future with the error, which we do not need here.
    runZonedGuarded(() => AppIdentityInterceptor().onError(err, ErrorInterceptorHandler()), (_, _) {});
    expect(updateRequired.value?.required, isTrue);
    expect(updateRequired.value?.latestVersion, '1.4.0');
    expect(updateRequired.value?.messageAr, 'يرجى التحديث');
  });

  testWidgets('the forced-update screen shows the message in the app language and cannot be dismissed', (tester) async {
    const info = UpdateInfo(
      status: 'required',
      latestVersion: '1.4.0',
      storeUrl: 'https://play.google.com/x',
      messageEn: 'Please update now',
      messageAr: 'يرجى التحديث الآن',
    );
    await tester.pumpWidget(_host(const ForceUpdateScreen(info: info)));
    expect(find.text('Update required'), findsOneWidget);
    expect(find.text('Please update now'), findsOneWidget);
    expect(find.textContaining('1.4.0'), findsOneWidget);

    await tester.pumpWidget(_host(const ForceUpdateScreen(info: info), lang: 'ar'));
    expect(find.text('التحديث مطلوب'), findsOneWidget);
    expect(find.text('يرجى التحديث الآن'), findsOneWidget);
  });

  testWidgets('without a store link the update button is disabled instead of doing nothing silently', (tester) async {
    await tester.pumpWidget(_host(const ForceUpdateScreen(info: UpdateInfo(status: 'required'))));
    final button = tester.widget<FilledButton>(find.byType(FilledButton));
    expect(button.onPressed, isNull);
    expect(find.text('A new version is available. Please update to continue.'), findsOneWidget);
  });
}
