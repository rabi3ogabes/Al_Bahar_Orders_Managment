import 'dart:async';

import 'package:dio/dio.dart';

import '../storage/token_storage.dart';
import '../utils/json.dart';
import 'interceptors.dart';

/// Attaches the bearer token and transparently refreshes it on 401.
///
/// * Single-flight: concurrent 401s share one `POST /auth/refresh` call.
/// * The refresh token rotates; the new pair is persisted before retrying.
/// * Each request is retried at most once; if refresh fails the session is
///   cleared and [onSessionExpired] is invoked (router redirects to sign-in).
class AuthInterceptor extends Interceptor {
  AuthInterceptor({
    required this.tokenStore,
    required this.dio,
    required this.refreshDio,
    required this.onSessionExpired,
    this.refreshPath = '/auth/refresh',
  });

  final TokenStore tokenStore;

  /// The main client, used to replay the original request.
  final Dio dio;

  /// A bare client without this interceptor (avoids refresh loops).
  final Dio refreshDio;

  final FutureOr<void> Function() onSessionExpired;
  final String refreshPath;

  Future<AuthTokens?>? _inflight;

  /// Number of refresh calls actually sent (exposed for tests/diagnostics).
  int refreshCount = 0;

  @override
  Future<void> onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    if (options.extra[RequestFlags.skipAuth] != true) {
      final tokens = tokenStore.cached ?? await tokenStore.read();
      if (tokens != null && tokens.accessToken.isNotEmpty) {
        options.headers['Authorization'] = 'Bearer ${tokens.accessToken}';
      }
    }
    handler.next(options);
  }

  @override
  Future<void> onError(DioException err, ErrorInterceptorHandler handler) async {
    final options = err.requestOptions;
    final isUnauthorized = err.response?.statusCode == 401;
    if (!isUnauthorized ||
        options.extra[RequestFlags.skipAuth] == true ||
        options.extra[RequestFlags.retried] == true) {
      return handler.next(err);
    }

    final current = tokenStore.cached ?? await tokenStore.read();
    if (current == null || current.refreshToken.isEmpty) {
      return handler.next(err);
    }

    final sentToken = _bearerOf(options);
    AuthTokens? fresh;
    if (sentToken != null && sentToken != current.accessToken) {
      // Another request already refreshed while this one was in flight.
      fresh = current;
    } else {
      fresh = await refresh();
    }

    if (fresh == null) {
      return handler.next(err);
    }

    try {
      // A multipart body is a one-shot stream: replay it from a fresh copy.
      final body = options.data;
      final retryOptions = options.copyWith(
        data: body is FormData ? body.clone() : body,
        headers: {...options.headers, 'Authorization': 'Bearer ${fresh.accessToken}'},
        extra: {...options.extra, RequestFlags.retried: true},
      );
      final response = await dio.fetch<dynamic>(retryOptions);
      return handler.resolve(response);
    } on DioException catch (e) {
      return handler.next(e);
    }
  }

  /// Refreshes the token pair once for all concurrent callers.
  Future<AuthTokens?> refresh() => _inflight ??= _doRefresh().whenComplete(() => _inflight = null);

  Future<AuthTokens?> _doRefresh() async {
    final current = tokenStore.cached ?? await tokenStore.read();
    if (current == null) return null;
    refreshCount++;
    try {
      final response = await refreshDio.post<dynamic>(
        refreshPath,
        data: {'refresh_token': current.refreshToken},
        options: Options(extra: {RequestFlags.skipAuth: true}),
      );
      final data = asMap(asMap(response.data)['data']);
      final tokens = AuthTokens.fromJson(asMap(data['tokens'] ?? data));
      if (tokens.accessToken.isEmpty) throw StateError('empty token');
      await tokenStore.write(tokens);
      return tokens;
    } on DioException catch (e) {
      final status = e.response?.statusCode;
      // Only a definitive rejection ends the session; offline keeps it.
      if (status == 401 || status == 403 || status == 422) {
        await tokenStore.clear();
        await onSessionExpired();
      }
      return null;
    } catch (_) {
      await tokenStore.clear();
      await onSessionExpired();
      return null;
    }
  }

  String? _bearerOf(RequestOptions options) {
    final header = options.headers['Authorization'];
    if (header is String && header.startsWith('Bearer ')) return header.substring(7);
    return null;
  }
}
