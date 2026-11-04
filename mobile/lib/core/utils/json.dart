/// Lenient JSON readers. The API contract is authoritative, but the client
/// must never crash on a missing optional field or an int-vs-string mismatch.
library;

typedef JsonMap = Map<String, dynamic>;

JsonMap asMap(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return value.map((k, v) => MapEntry(k.toString(), v));
  return <String, dynamic>{};
}

JsonMap? asMapOrNull(Object? value) => value is Map ? asMap(value) : null;

List<Object?> asList(Object? value) => value is List ? value.cast<Object?>() : const <Object?>[];

List<T> mapList<T>(Object? value, T Function(JsonMap json) fromJson) =>
    asList(value).whereType<Map<dynamic, dynamic>>().map((e) => fromJson(asMap(e))).toList();

String asString(Object? value, [String fallback = '']) {
  if (value == null) return fallback;
  if (value is String) return value;
  return value.toString();
}

String? asStringOrNull(Object? value) {
  if (value == null) return null;
  final s = value is String ? value : value.toString();
  return s.isEmpty ? null : s;
}

int asInt(Object? value, [int fallback = 0]) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  if (value is String) return int.tryParse(value) ?? double.tryParse(value)?.toInt() ?? fallback;
  return fallback;
}

int? asIntOrNull(Object? value) => value == null ? null : asInt(value);

double asDouble(Object? value, [double fallback = 0]) {
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value) ?? fallback;
  return fallback;
}

double? asDoubleOrNull(Object? value) {
  if (value == null) return null;
  if (value is num) return value.toDouble();
  if (value is String) return double.tryParse(value);
  return null;
}

bool asBool(Object? value, [bool fallback = false]) {
  if (value is bool) return value;
  if (value is num) return value != 0;
  if (value is String) return value == 'true' || value == '1';
  return fallback;
}

bool? asBoolOrNull(Object? value) => value == null ? null : asBool(value);

DateTime? asDate(Object? value) {
  if (value is String && value.isNotEmpty) return DateTime.tryParse(value);
  return null;
}

List<String> asStringList(Object? value) => asList(value).where((e) => e != null).map((e) => e.toString()).toList();
