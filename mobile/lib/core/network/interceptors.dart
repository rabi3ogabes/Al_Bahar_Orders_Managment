import 'dart:convert';
import 'dart:developer' as developer;

import 'package:dio/dio.dart';
import 'package:uuid/uuid.dart';

const _uuid = Uuid();

/// Request option `extra` flags understood by the interceptors.
abstract final class RequestFlags {
  /// Do not attach the bearer token / never attempt a refresh.
  static const skipAuth = 'skip_auth';

  /// Set internally when a request is retried after a token refresh.
  static const retried = 'auth_retried';
}

/// Sends `Accept-Language` from the current app locale on every request.
class LocaleInterceptor extends Interceptor {
  LocaleInterceptor(this.localeCode);

  final String Function() localeCode;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers['Accept-Language'] = localeCode();
    handler.next(options);
  }
}

/// Adds a unique `X-Request-ID` (kept across retries of the same request).
class RequestIdInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    options.headers.putIfAbsent('X-Request-ID', () => _uuid.v4());
    handler.next(options);
  }
}

/// Idempotency helper for the endpoints that require `Idempotency-Key`.
abstract final class Idempotency {
  static const header = 'Idempotency-Key';

  static String newKey() => _uuid.v4();

  static Options options(String key, [Options? base]) =>
      (base ?? Options()).copyWith(headers: {...?base?.headers, header: key});
}

/// Keys whose values must never appear in logs.
const sensitiveKeys = <String>{
  'authorization',
  'access_token',
  'refresh_token',
  'registration_token',
  'password',
  'password_confirmation',
  'current_password',
  'code',
  'otp',
  'debug_code',
  'push_token',
  'qr_payload',
  'token',
  'auth',
  'signature',
};

/// Deep-redacts sensitive values from maps / lists / JSON strings.
Object? redact(Object? value) {
  if (value is Map) {
    return value.map((key, v) {
      final k = key.toString();
      if (sensitiveKeys.contains(k.toLowerCase())) return MapEntry(k, '***');
      return MapEntry(k, redact(v));
    });
  }
  if (value is List) return value.map(redact).toList();
  if (value is String) {
    final trimmed = value.trimLeft();
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
      try {
        return jsonEncode(redact(jsonDecode(value)));
      } catch (_) {
        return value;
      }
    }
    // Never echo signed URLs' query strings.
    return value.replaceAll(RegExp(r'signature=[^&\s]+'), 'signature=***');
  }
  return value;
}

/// Debug-only HTTP logger that redacts tokens, OTPs and passwords.
class RedactingLogInterceptor extends Interceptor {
  RedactingLogInterceptor({void Function(String message)? sink})
    : _sink = sink ?? ((m) => developer.log(m, name: 'http'));

  final void Function(String) _sink;

  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    _sink(
      '--> ${options.method} ${redact(options.uri.toString())}\n'
      'headers: ${redact(options.headers)}\n'
      'body: ${options.data is FormData ? '<multipart>' : redact(options.data)}',
    );
    handler.next(options);
  }

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    final data = response.data;
    _sink(
      '<-- ${response.statusCode} ${response.requestOptions.method} ${redact(response.requestOptions.uri.toString())}\n'
      'body: ${data is List<int> ? '<${data.length} bytes>' : redact(data)}',
    );
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    _sink(
      '<-- ERROR ${err.response?.statusCode ?? err.type.name} ${err.requestOptions.method} '
      '${redact(err.requestOptions.uri.toString())}\nbody: ${redact(err.response?.data)}',
    );
    handler.next(err);
  }
}
