import 'package:dio/dio.dart';

import '../utils/json.dart';

enum ApiErrorKind {
  unauthorized,
  forbidden,
  notFound,
  conflict,
  validation,
  rateLimited,
  server,
  timeout,
  offline,
  cancelled,
  unknown,
}

/// Typed representation of the API error envelope
/// `{message, code, errors, context, request_id}` plus transport failures.
class ApiException implements Exception {
  const ApiException({
    required this.kind,
    this.statusCode,
    this.code,
    this.message,
    this.errors = const {},
    this.context = const {},
    this.requestId,
    this.retryAfter,
  });

  factory ApiException.fromDio(DioException e) {
    final inner = e.error;
    if (inner is ApiException) return inner;
    switch (e.type) {
      case DioExceptionType.connectionTimeout:
      case DioExceptionType.sendTimeout:
      case DioExceptionType.receiveTimeout:
        return const ApiException(kind: ApiErrorKind.timeout);
      case DioExceptionType.connectionError:
        return const ApiException(kind: ApiErrorKind.offline);
      case DioExceptionType.cancel:
        return const ApiException(kind: ApiErrorKind.cancelled);
      case DioExceptionType.badCertificate:
        return const ApiException(kind: ApiErrorKind.unknown, code: 'bad_certificate');
      case DioExceptionType.badResponse:
      case DioExceptionType.unknown:
        final response = e.response;
        if (response == null) {
          if (inner != null && inner.runtimeType.toString().contains('SocketException')) return const ApiException(kind: ApiErrorKind.offline);
          return ApiException(kind: ApiErrorKind.unknown, message: e.message);
        }
        return ApiException.fromResponse(response);
    }
  }

  factory ApiException.fromResponse(Response<dynamic> response) {
    final status = response.statusCode ?? 0;
    final body = asMap(response.data);
    final errors = <String, List<String>>{};
    asMap(body['errors']).forEach((key, value) {
      errors[key] = value is List ? value.map((e) => e.toString()).toList() : [value.toString()];
    });
    final context = asMap(body['context']);
    Duration? retryAfter;
    final header = response.headers.value('retry-after');
    final headerSeconds = header == null ? null : int.tryParse(header.trim());
    final contextSeconds = asIntOrNull(context['seconds'] ?? context['retry_after']);
    final seconds = contextSeconds ?? headerSeconds;
    if (seconds != null) retryAfter = Duration(seconds: seconds);

    return ApiException(
      kind: kindForStatus(status),
      statusCode: status,
      code: asStringOrNull(body['code']),
      message: asStringOrNull(body['message']),
      errors: errors,
      context: context,
      requestId: asStringOrNull(body['request_id']) ?? response.headers.value('x-request-id'),
      retryAfter: retryAfter,
    );
  }

  static ApiErrorKind kindForStatus(int status) => switch (status) {
    401 => ApiErrorKind.unauthorized,
    403 => ApiErrorKind.forbidden,
    404 => ApiErrorKind.notFound,
    409 => ApiErrorKind.conflict,
    422 => ApiErrorKind.validation,
    429 => ApiErrorKind.rateLimited,
    >= 500 => ApiErrorKind.server,
    _ => ApiErrorKind.unknown,
  };

  final ApiErrorKind kind;
  final int? statusCode;
  final String? code;
  final String? message;
  final Map<String, List<String>> errors;
  final JsonMap context;
  final String? requestId;
  final Duration? retryAfter;

  bool get isNetwork => kind == ApiErrorKind.offline || kind == ApiErrorKind.timeout;
  bool get isRetryable => isNetwork || kind == ApiErrorKind.server || kind == ApiErrorKind.rateLimited;

  /// First validation message for [field], if any.
  String? fieldError(String field) {
    final list = errors[field];
    return (list == null || list.isEmpty) ? null : list.first;
  }

  @override
  String toString() => 'ApiException(${kind.name}, status: $statusCode, code: $code, requestId: $requestId)';
}
