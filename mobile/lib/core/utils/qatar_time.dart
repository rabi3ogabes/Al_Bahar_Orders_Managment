import 'package:intl/intl.dart';

/// Asia/Qatar is UTC+03:00 all year round (no daylight saving), so a fixed
/// offset is exact and avoids shipping the full tz database.
abstract final class QatarTime {
  static const offset = Duration(hours: 3);

  /// Converts any instant into a wall-clock [DateTime] in Asia/Qatar.
  /// The returned value is a "naive" UTC DateTime whose fields read as Doha time.
  static DateTime toQatar(DateTime instant) => instant.toUtc().add(offset);

  static String date(DateTime? instant, String locale) =>
      instant == null ? '' : DateFormat.yMMMd(locale).format(toQatar(instant));

  static String dateTime(DateTime? instant, String locale) =>
      instant == null ? '' : DateFormat.yMMMd(locale).add_jm().format(toQatar(instant));

  static String time(DateTime? instant, String locale) =>
      instant == null ? '' : DateFormat.jm(locale).format(toQatar(instant));

  static String weekdayDate(DateTime? instant, String locale) =>
      instant == null ? '' : DateFormat.MMMEd(locale).format(toQatar(instant));

  /// Formats a server "HH:mm" branch time ("09:00") for display.
  static String clock(String? hhmm, String locale) {
    if (hhmm == null || hhmm.isEmpty) return '';
    final parts = hhmm.split(':');
    final h = int.tryParse(parts.first) ?? 0;
    final m = parts.length > 1 ? int.tryParse(parts[1]) ?? 0 : 0;
    return DateFormat.jm(locale).format(DateTime.utc(2000, 1, 1, h, m));
  }

  /// Today's date in Qatar as `YYYY-MM-DD` (for slot queries).
  static String isoDate(DateTime qatarDay) =>
      '${qatarDay.year.toString().padLeft(4, '0')}-${qatarDay.month.toString().padLeft(2, '0')}-${qatarDay.day.toString().padLeft(2, '0')}';

  static DateTime today([DateTime? now]) {
    final q = toQatar(now ?? DateTime.now());
    return DateTime.utc(q.year, q.month, q.day);
  }
}

/// Formats a remaining duration as `1d 02:03:04` / `02:03:04` / `03:04`.
String formatCountdown(Duration d, {String Function(int days)? daysLabel}) {
  if (d.isNegative) return '00:00';
  String two(int n) => n.toString().padLeft(2, '0');
  final days = d.inDays;
  final h = d.inHours.remainder(24);
  final m = d.inMinutes.remainder(60);
  final s = d.inSeconds.remainder(60);
  if (days > 0) return '${daysLabel?.call(days) ?? '${days}d'} ${two(h)}:${two(m)}:${two(s)}';
  if (d.inHours > 0) return '${two(h)}:${two(m)}:${two(s)}';
  return '${two(m)}:${two(s)}';
}
