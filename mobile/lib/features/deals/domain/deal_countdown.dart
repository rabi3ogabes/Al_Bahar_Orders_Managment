import 'package:flutter/foundation.dart';

/// One unit of the countdown (`timer.units` of a deal's design: `d`, `h`, `m`, `s`).
enum DealUnit {
  days('d', 86400),
  hours('h', 3600),
  minutes('m', 60),
  seconds('s', 1);

  const DealUnit(this.wire, this.inSeconds);

  /// The letter the server uses.
  final String wire;

  /// How many seconds one of this unit is.
  final int inSeconds;

  static DealUnit? parse(Object? raw) {
    if (raw is! String) return null;
    final key = raw.trim().toLowerCase();
    return values.where((u) => u.wire == key).firstOrNull;
  }
}

/// One shown part of a countdown: the unit and how many of it are left.
@immutable
class DealPart {
  const DealPart(this.unit, this.value);

  final DealUnit unit;
  final int value;

  @override
  bool operator ==(Object other) => other is DealPart && other.unit == unit && other.value == value;

  @override
  int get hashCode => Object.hash(unit, value);

  @override
  String toString() => '$value${unit.wire}';
}

/// Whole seconds from [serverNow] to [target]: never negative, and rounded up, so the countdown reads zero only when the
/// moment has really come. Null when there is no [target].
int? dealSecondsLeft(DateTime? target, DateTime serverNow) {
  if (target == null) return null;
  final micros = target.difference(serverNow).inMicroseconds;
  if (micros <= 0) return 0;
  return (micros + 999999) ~/ 1000000;
}

/// The clock offset between the server and this phone (`server_time - device time`): add it to the device time to get the
/// server's time. Zero when the server sent no time.
Duration dealServerSkew(DateTime? serverTime, DateTime deviceNow) =>
    serverTime == null ? Duration.zero : serverTime.difference(deviceNow);

/// Splits [totalSeconds] into the parts of [units] (any subset, any order).
///
/// * The biggest shown unit takes everything above it: with `h:m:s` only, three days left reads `72h`.
/// * A unit that is not shown hands its seconds to the next smaller one that is shown.
/// * The 24 hour rule: days are hidden while less than a day is left (when the deal lists `d`), so the clock never starts
///   with an empty `00d`. At least one unit always stays.
/// * Never negative, never more than 59 in a unit that has a bigger unit shown before it.
List<DealPart> splitDealTime(int totalSeconds, List<DealUnit> units) {
  var left = totalSeconds < 0 ? 0 : totalSeconds;
  var shown = [...units.toSet()]..sort((a, b) => b.inSeconds.compareTo(a.inSeconds));
  if (shown.isEmpty) shown = [DealUnit.hours, DealUnit.minutes, DealUnit.seconds];
  if (left < DealUnit.days.inSeconds && shown.length > 1) shown = [...shown.where((u) => u != DealUnit.days)];
  final parts = <DealPart>[];
  for (final u in shown) {
    final value = left ~/ u.inSeconds;
    left -= value * u.inSeconds;
    parts.add(DealPart(u, value));
  }
  return parts;
}

/// The last stretch of a deal: below [urgencyMinutes] (0 turns it off) and not yet over.
bool dealIsUrgent(int? secondsLeft, int urgencyMinutes) =>
    urgencyMinutes > 0 && secondsLeft != null && secondsLeft > 0 && secondsLeft < urgencyMinutes * 60;

/// The countdown as the screen reader hears it: whole days, hours and minutes (seconds are left out on purpose, so the
/// spoken label changes once a minute, not once a second).
({int days, int hours, int minutes}) dealSpokenParts(int secondsLeft) {
  final s = secondsLeft < 0 ? 0 : secondsLeft;
  return (days: s ~/ 86400, hours: (s % 86400) ~/ 3600, minutes: (s % 3600) ~/ 60);
}
