import 'dart:async';

import 'package:albahar_customer/core/network/api_client.dart';
import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/network/interceptors.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';

ApiClient _client(FakeAdapter adapter, TokenStore store, {Future<void> Function()? onExpired, String locale = 'ar'}) =>
    ApiClient.create(
      config: testConfig,
      tokenStore: store,
      localeCode: () => locale,
      onSessionExpired: onExpired ?? () async {},
      adapter: adapter,
    );

void main() {
  group('ApiException mapping', () {
    Future<ApiException> call(FakeReply reply) async {
      final adapter = FakeAdapter((_) => reply);
      final api = _client(adapter, MemoryTokenStore());
      try {
        await api.get('/x');
        fail('expected ApiException');
      } on ApiException catch (e) {
        return e;
      }
    }

    test('422 validation envelope with field errors', () async {
      final e = await call(
        error(
          422,
          'validation_failed',
          errors: {
            'phone': ['Invalid phone'],
          },
        ),
      );
      expect(e.kind, ApiErrorKind.validation);
      expect(e.code, 'validation_failed');
      expect(e.fieldError('phone'), 'Invalid phone');
      expect(e.requestId, 'req-1');
    });

    test('status codes map to kinds', () async {
      expect((await call(error(403, 'forbidden'))).kind, ApiErrorKind.forbidden);
      expect((await call(error(404, 'not_found'))).kind, ApiErrorKind.notFound);
      expect((await call(error(409, 'quote_changed'))).kind, ApiErrorKind.conflict);
      expect((await call(error(500, 'server_error'))).kind, ApiErrorKind.server);
      expect((await call(error(503, 'server_error'))).isRetryable, isTrue);
    });

    test('429 honours context.seconds and Retry-After', () async {
      final a = await call(error(429, 'otp_cooldown', context: {'seconds': 42}));
      expect(a.kind, ApiErrorKind.rateLimited);
      expect(a.retryAfter, const Duration(seconds: 42));
      final b = await call(
        const FakeReply(
          429,
          {'code': 'too_many_requests', 'message': 'slow down'},
          {
            'retry-after': ['7'],
          },
        ),
      );
      expect(b.retryAfter, const Duration(seconds: 7));
    });

    test('connection errors map to offline; timeouts to timeout', () async {
      final adapter = FakeAdapter((_) => ok({}))..failConnection = (_) => true;
      final api = _client(adapter, MemoryTokenStore());
      await expectLater(
        api.get('/x'),
        throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.offline)),
      );

      final timeout = ApiException.fromDio(
        DioException.receiveTimeout(timeout: Duration.zero, requestOptions: RequestOptions()),
      );
      expect(timeout.kind, ApiErrorKind.timeout);
      expect(timeout.isNetwork, isTrue);
    });
  });

  group('request headers', () {
    test('Accept-Language, X-Request-ID and bearer token are sent', () async {
      final adapter = FakeAdapter((_) => ok({}));
      final api = _client(adapter, MemoryTokenStore(AuthTokens.fromJson(tokens('A1', 'R1'))), locale: 'ar');
      await api.get('/me');
      final r = adapter.requests.single;
      expect(r.header('Accept-Language'), 'ar');
      expect(r.header('X-Request-ID'), isNotEmpty);
      expect(r.header('Authorization'), 'Bearer A1');
    });

    test('skipAuth requests carry no token', () async {
      final adapter = FakeAdapter((_) => ok({}));
      final api = _client(adapter, MemoryTokenStore(AuthTokens.fromJson(tokens('A1', 'R1'))));
      await api.post('/auth/otp/request', options: Options(extra: {RequestFlags.skipAuth: true}));
      expect(adapter.requests.single.header('Authorization'), isNull);
    });

    test('Idempotency helper sets the header', () {
      final o = Idempotency.options('k-1');
      expect(o.headers![Idempotency.header], 'k-1');
      expect(Idempotency.newKey(), isNot(Idempotency.newKey()));
    });

    test('array and bool query params use Laravel syntax', () async {
      final adapter = FakeAdapter((_) => ok([]));
      final api = _client(adapter, MemoryTokenStore());
      await api.get(
        '/products',
        query: {
          'brand_id': ['b1', 'b2'],
          'in_stock': true,
          'q': '',
          'x': null,
        },
      );
      final q = adapter.requests.single.options.uri.queryParametersAll;
      expect(q['brand_id[]'], ['b1', 'b2']);
      expect(q['in_stock'], ['1']);
      expect(q.containsKey('q'), isFalse);
      expect(q.containsKey('x'), isFalse);
    });
  });

  group('AuthInterceptor single-flight refresh', () {
    test('concurrent 401s trigger exactly one refresh and all requests are retried', () async {
      final store = MemoryTokenStore(AuthTokens.fromJson(tokens('old', 'R1')));
      final refreshGate = Completer<void>();
      final adapter = FakeAdapter();
      adapter.handler = (r) async {
        if (r.path == '/auth/refresh') {
          await refreshGate.future;
          expect(r.body, {'refresh_token': 'R1'});
          return ok({'tokens': tokens('new', 'R2')});
        }
        if (r.header('Authorization') == 'Bearer new') return ok({'path': r.path});
        return error(401, 'unauthenticated');
      };
      final api = _client(adapter, store);

      final futures = [api.get('/a'), api.get('/b'), api.get('/c')];
      // Let all three hit 401 before the refresh resolves.
      await Future<void>.delayed(const Duration(milliseconds: 20));
      refreshGate.complete();
      final results = await Future.wait(futures);

      expect(results.map((r) => (r as Map)['path']), ['/a', '/b', '/c']);
      expect(adapter.where('POST', '/auth/refresh'), hasLength(1));
      expect(api.authInterceptor!.refreshCount, 1);
      expect(store.cached!.accessToken, 'new');
      expect(store.cached!.refreshToken, 'R2', reason: 'refresh token rotates');
    });

    test('failed refresh clears tokens and signals session expiry once', () async {
      final store = MemoryTokenStore(AuthTokens.fromJson(tokens('old', 'R1')));
      var expired = 0;
      final adapter = FakeAdapter(
        (r) => r.path == '/auth/refresh' ? error(401, 'refresh_invalid') : error(401, 'unauthenticated'),
      );
      final api = _client(adapter, store, onExpired: () async => expired++);

      final results = await Future.wait([
        api.get('/a').then<Object?>((_) => null, onError: (Object e) => e),
        api.get('/b').then<Object?>((_) => null, onError: (Object e) => e),
      ]);
      expect(results, everyElement(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.unauthorized)));
      expect(adapter.where('POST', '/auth/refresh'), hasLength(1));
      expect(expired, 1);
      expect(store.cached, isNull);
    });

    test('a request is retried at most once', () async {
      final store = MemoryTokenStore(AuthTokens.fromJson(tokens('old', 'R1')));
      final adapter = FakeAdapter(
        (r) => r.path == '/auth/refresh' ? ok({'tokens': tokens('new', 'R2')}) : error(401, 'unauthenticated'),
      );
      final api = _client(adapter, store);
      await expectLater(api.get('/a'), throwsA(isA<ApiException>()));
      expect(adapter.where('GET', '/a'), hasLength(2));
      expect(adapter.where('POST', '/auth/refresh'), hasLength(1));
    });

    test('offline during refresh keeps the session', () async {
      final store = MemoryTokenStore(AuthTokens.fromJson(tokens('old', 'R1')));
      var expired = 0;
      final adapter = FakeAdapter((r) => error(401, 'unauthenticated'))
        ..failConnection = (r) => r.path == '/auth/refresh';
      final api = _client(adapter, store, onExpired: () async => expired++);
      await expectLater(api.get('/a'), throwsA(isA<ApiException>()));
      expect(expired, 0);
      expect(store.cached, isNotNull);
    });
  });

  group('redaction', () {
    test('tokens, OTPs and passwords never reach logs', () {
      final out = redact({
        'phone': '+97455123456',
        'code': '909090',
        'password': 'secret',
        'tokens': {'access_token': 'abc', 'refresh_token': 'def'},
        'nested': [
          {'push_token': 'fcm'},
        ],
      });
      final s = out.toString();
      for (final secret in ['909090', 'secret', 'abc', 'def', 'fcm']) {
        expect(s.contains(secret), isFalse, reason: secret);
      }
      expect(s.contains('+97455123456'), isTrue);
      expect(redact('https://x/pay?signature=abc&x=1'), 'https://x/pay?signature=***&x=1');
      expect(redact({'Authorization': 'Bearer abc'}).toString(), isNot(contains('abc')));
    });

    test('log interceptor output is redacted', () async {
      final lines = <String>[];
      final adapter = FakeAdapter((_) => ok({'tokens': tokens('ACCESS', 'REFRESH')}));
      final dio = Dio(BaseOptions(baseUrl: testConfig.apiBaseUrl))
        ..httpClientAdapter = adapter
        ..interceptors.add(RedactingLogInterceptor(sink: lines.add));
      await dio.post<dynamic>(
        '/auth/otp/verify',
        data: {'phone': '+974', 'code': '999111'},
        options: Options(headers: {'Authorization': 'Bearer ACCESS'}),
      );
      final all = lines.join('\n');
      expect(all, isNot(contains('999111')));
      expect(all, isNot(contains('ACCESS')));
      expect(all, isNot(contains('REFRESH')));
    });
  });
}
