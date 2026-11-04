import 'dart:typed_data';

import 'package:dio/dio.dart';

import '../config/app_config.dart';
import '../models/paginated.dart';
import '../storage/token_storage.dart';
import '../utils/json.dart';
import 'api_exception.dart';
import 'auth_interceptor.dart';
import 'interceptors.dart';

/// Thin, typed wrapper around [Dio] that unwraps the `{data: ...}` envelope
/// and converts every failure into an [ApiException].
class ApiClient {
  ApiClient(this.dio);

  /// Builds the production client with the full interceptor chain.
  factory ApiClient.create({
    required AppConfig config,
    required TokenStore tokenStore,
    required String Function() localeCode,
    required Future<void> Function() onSessionExpired,
    HttpClientAdapter? adapter,
  }) {
    BaseOptions base() => BaseOptions(
      baseUrl: config.apiBaseUrl,
      connectTimeout: config.connectTimeout,
      receiveTimeout: config.receiveTimeout,
      sendTimeout: config.receiveTimeout,
      headers: {'Accept': 'application/json'},
      contentType: Headers.jsonContentType,
      responseType: ResponseType.json,
    );

    final dio = Dio(base());
    final refreshDio = Dio(base());
    if (adapter != null) {
      dio.httpClientAdapter = adapter;
      refreshDio.httpClientAdapter = adapter;
    }
    for (final d in [dio, refreshDio]) {
      d.interceptors.addAll([LocaleInterceptor(localeCode), RequestIdInterceptor()]);
    }
    dio.interceptors.add(
      AuthInterceptor(
        tokenStore: tokenStore,
        dio: dio,
        refreshDio: refreshDio,
        onSessionExpired: onSessionExpired,
      ),
    );
    if (config.httpLoggingEnabled) {
      dio.interceptors.add(RedactingLogInterceptor());
      refreshDio.interceptors.add(RedactingLogInterceptor());
    }
    return ApiClient(dio);
  }

  final Dio dio;

  AuthInterceptor? get authInterceptor => dio.interceptors.whereType<AuthInterceptor>().firstOrNull;

  /// GET returning the unwrapped `data` value (map or list).
  Future<dynamic> get(String path, {Map<String, dynamic>? query, Options? options}) =>
      _guard(() => dio.get<dynamic>(path, queryParameters: _clean(query), options: options));

  /// GET a single resource: `{data: {...}}`.
  Future<T> getObject<T>(String path, T Function(JsonMap) fromJson, {Map<String, dynamic>? query}) async =>
      fromJson(asMap(await get(path, query: query)));

  /// GET a non-paginated list `{data: [...]}`.
  Future<List<T>> getList<T>(String path, T Function(JsonMap) fromJson, {Map<String, dynamic>? query}) async {
    final data = await get(path, query: query);
    return mapList(data, fromJson);
  }

  /// GET a paginated collection. Also tolerates plain arrays.
  Future<Paginated<T>> getPage<T>(
    String path,
    T Function(JsonMap) fromJson, {
    Map<String, dynamic>? query,
  }) async {
    final body = await _guardRaw(() => dio.get<dynamic>(path, queryParameters: _clean(query)));
    if (body is Map && body['data'] is List) {
      return Paginated.fromJson(asMap(body), fromJson);
    }
    final items = mapList(body is Map ? body['data'] : body, fromJson);
    return Paginated(items: items, currentPage: 1, lastPage: 1, perPage: items.length, total: items.length);
  }

  /// Returns the raw decoded body (used when `meta` is needed).
  Future<dynamic> getRaw(String path, {Map<String, dynamic>? query}) =>
      _guardRaw(() => dio.get<dynamic>(path, queryParameters: _clean(query)));

  Future<dynamic> post(String path, {Object? data, Map<String, dynamic>? query, Options? options}) =>
      _guard(() => dio.post<dynamic>(path, data: data, queryParameters: _clean(query), options: options));

  Future<dynamic> put(String path, {Object? data, Options? options}) =>
      _guard(() => dio.put<dynamic>(path, data: data, options: options));

  Future<dynamic> patch(String path, {Object? data, Options? options}) =>
      _guard(() => dio.patch<dynamic>(path, data: data, options: options));

  Future<dynamic> delete(String path, {Object? data, Options? options}) =>
      _guard(() => dio.delete<dynamic>(path, data: data, options: options));

  /// Downloads binary content (e.g. invoice PDF).
  Future<Uint8List> getBytes(String path, {String accept = 'application/pdf'}) async {
    try {
      final response = await dio.get<List<int>>(
        path,
        options: Options(responseType: ResponseType.bytes, headers: {'Accept': accept}),
      );
      return Uint8List.fromList(response.data ?? const []);
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  Future<dynamic> _guard(Future<Response<dynamic>> Function() call) async {
    final body = await _guardRaw(call);
    if (body is Map && body.containsKey('data')) return body['data'];
    return body;
  }

  Future<dynamic> _guardRaw(Future<Response<dynamic>> Function() call) async {
    try {
      final response = await call();
      return response.data;
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  static Map<String, dynamic>? _clean(Map<String, dynamic>? query) {
    if (query == null) return null;
    final out = <String, dynamic>{};
    query.forEach((key, value) {
      if (value == null) return;
      if (value is String && value.isEmpty) return;
      if (value is Iterable) {
        if (value.isEmpty) return;
        // Laravel array syntax: key[]=a&key[]=b
        out[key.endsWith('[]') ? key : '$key[]'] = value.map((e) => e.toString()).toList();
        return;
      }
      if (value is bool) {
        out[key] = value ? '1' : '0';
        return;
      }
      out[key] = value;
    });
    return out;
  }
}
