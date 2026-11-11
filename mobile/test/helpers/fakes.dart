import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:albahar_customer/core/config/app_config.dart';
import 'package:albahar_customer/core/l10n/l10n.dart';
import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/core/push/push_service.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/core/theme/app_theme.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

const testConfig = AppConfig(
  flavor: Flavor.dev,
  apiBaseUrl: 'https://api.test/api/v1',
  reverbAppKey: 'key',
  reverbHost: 'ws.test',
  reverbPort: 443,
  reverbUseTls: true,
  pushEnabled: false,
  mapTileUrlTemplate: 'https://tiles.test/{z}/{x}/{y}.png',
);

/// A recorded HTTP request.
class RecordedRequest {
  RecordedRequest(this.options);
  final RequestOptions options;

  String get method => options.method;
  String get path => options.uri.path.replaceFirst('/api/v1', '');
  Map<String, dynamic> get query =>
      options.uri.queryParametersAll.map((k, v) => MapEntry(k, v.length == 1 ? v.first : v));
  Map<String, dynamic> get headers => options.headers;
  dynamic get body => options.data;
  String? header(String name) {
    for (final e in options.headers.entries) {
      if (e.key.toLowerCase() == name.toLowerCase()) return e.value?.toString();
    }
    return null;
  }

  @override
  String toString() => '$method $path';
}

class FakeReply {
  const FakeReply(this.status, [this.body, this.headers = const {}]);
  final int status;
  final Object? body;
  final Map<String, List<String>> headers;
}

typedef FakeHandler = FutureOr<FakeReply> Function(RecordedRequest request);

/// Minimal programmable [HttpClientAdapter] that records every request.
class FakeAdapter implements HttpClientAdapter {
  FakeAdapter([FakeHandler? handler]) : handler = handler ?? ((_) => const FakeReply(404, {'code': 'not_found'}));

  FakeHandler handler;
  final requests = <RecordedRequest>[];

  /// Throw a connection error for matching requests (offline simulation).
  bool Function(RecordedRequest r)? failConnection;

  List<RecordedRequest> where(String method, String path) =>
      requests.where((r) => r.method == method && r.path == path).toList();

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    final req = RecordedRequest(options);
    requests.add(req);
    if (failConnection?.call(req) ?? false) {
      throw DioException.connectionError(requestOptions: options, reason: 'offline');
    }
    final reply = await handler(req);
    final bytes = reply.body is Uint8List ? reply.body! as Uint8List : utf8.encode(jsonEncode(reply.body));
    return ResponseBody.fromBytes(
      bytes,
      reply.status,
      headers: {
        Headers.contentTypeHeader: [reply.body is Uint8List ? 'application/pdf' : 'application/json'],
        ...reply.headers,
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

/// Router-style handler: `'GET /cart' → reply`.
FakeHandler routes(Map<String, FutureOr<FakeReply> Function(RecordedRequest r)> table) => (r) {
  final key = '${r.method} ${r.path}';
  final h = table[key];
  if (h == null) return const FakeReply(404, {'message': 'not mocked', 'code': 'not_found'});
  return h(r);
};

FakeReply ok(Object? data, {int status = 200}) => FakeReply(status, {'data': data});

FakeReply page(List<Object?> items, {int current = 1, int last = 1, Map<String, dynamic> meta = const {}}) =>
    FakeReply(200, {
      'data': items,
      'links': {'next': current < last ? 'next' : null},
      'meta': {'current_page': current, 'last_page': last, 'per_page': 20, 'total': items.length, ...meta},
    });

FakeReply error(
  int status,
  String code, {
  String? message,
  Map<String, dynamic>? errors,
  Map<String, dynamic>? context,
}) => FakeReply(status, {
  'message': message ?? code,
  'code': code,
  'errors': ?errors,
  'context': ?context,
  'request_id': 'req-1',
});

Map<String, dynamic> money(String amount) => {'amount': amount, 'currency': 'QAR', 'formatted': 'QAR $amount'};

Map<String, dynamic> price(String finalAmount, {String? regular}) => {
  'regular': money(regular ?? finalAmount),
  'sale': regular != null ? money(finalAmount) : null,
  'final': money(finalAmount),
  'discount_percent': regular != null ? 10 : null,
  'source': 'product_branch',
};

Map<String, dynamic> productCard(String id, {String amount = '100.00'}) => {
  'id': id,
  'slug': 'p-$id',
  'sku': 'SKU-$id',
  'name': 'Product $id',
  'brand': {'id': 'b1', 'name': 'Nordic'},
  'image': null,
  'price': price(amount),
  'rating': {'average': 4.5, 'count': 3},
  'badges': ['new'],
  'in_stock': true,
  'has_variants': false,
};

Map<String, dynamic> tokens(String access, String refresh) => {
  'access_token': access,
  'refresh_token': refresh,
  'token_type': 'Bearer',
  'expires_in': 900,
  'session_id': 's1',
};

/// Everything needed for provider-level tests.
class TestHarness {
  TestHarness._(this.container, this.adapter, this.tokenStore);

  final ProviderContainer container;
  final FakeAdapter adapter;
  final MemoryTokenStore tokenStore;

  static Future<TestHarness> create({
    FakeHandler? handler,
    AuthTokens? tokens,
    Map<String, Object> prefs = const {'locale': 'en', 'onboarding_done': true},
    List<Override> overrides = const [],
  }) async {
    SharedPreferences.setMockInitialValues(prefs);
    final sp = await SharedPreferences.getInstance();
    final adapter = FakeAdapter(handler);
    final store = MemoryTokenStore(tokens);
    late final ProviderContainer container;
    container = ProviderContainer(
      overrides: [
        appConfigProvider.overrideWithValue(testConfig),
        sharedPreferencesProvider.overrideWithValue(sp),
        tokenStoreProvider.overrideWithValue(store),
        pushMessagingProvider.overrideWithValue(NoopPushMessaging()),
        localNotifierProvider.overrideWithValue(FakeLocalNotifier()),
        apiClientProvider.overrideWith((ref) {
          final expiry = ref.watch(sessionExpirySignalProvider);
          return ApiClient.create(
            config: testConfig,
            tokenStore: store,
            localeCode: () => ref.read(localeCodeProvider),
            onSessionExpired: () async => expiry.fire(),
            adapter: adapter,
          );
        }),
        ...overrides,
      ],
      retry: (_, _) => null,
    );
    addTearDown(container.dispose);
    return TestHarness._(container, adapter, store);
  }

  T read<T>(ProviderListenable<T> p) => container.read(p);
}

class FakeLocalNotifier implements LocalNotifier {
  final shown = <PushMessage>[];
  final progress = <Map<String, dynamic>>[];
  String? launch;
  void Function(String? payload)? onTap;

  @override
  Future<void> initialize(void Function(String? payload) onTap) async => this.onTap = onTap;

  @override
  Future<String?> launchPayload() async => launch;

  @override
  Future<void> show(PushMessage message) async => shown.add(message);

  @override
  Future<void> showProgress(Map<String, dynamic> data) async => progress.add(data);
}

/// Wraps a widget with theme + localisation for widget tests.
Widget wrapApp(Widget child, {Locale locale = const Locale('en'), bool dark = false}) => MaterialApp(
  locale: locale,
  supportedLocales: supportedLocales,
  localizationsDelegates: const [
    AppLocalizations.delegate,
    GlobalMaterialLocalizations.delegate,
    GlobalWidgetsLocalizations.delegate,
    GlobalCupertinoLocalizations.delegate,
  ],
  theme: AppTheme.light(locale),
  darkTheme: AppTheme.dark(locale),
  themeMode: dark ? ThemeMode.dark : ThemeMode.light,
  home: Scaffold(body: child),
);
