import 'package:albahar_customer/features/deals/domain/deal_countdown.dart';
import 'package:flutter_test/flutter_test.dart';

const _all = [DealUnit.days, DealUnit.hours, DealUnit.minutes, DealUnit.seconds];

List<int> _values(int seconds, List<DealUnit> units) => [for (final p in splitDealTime(seconds, units)) p.value];

void main() {
  group('splitDealTime', () {
    test('splits into days, hours, minutes and seconds', () {
      expect(splitDealTime(93234, _all), const [
        DealPart(DealUnit.days, 1),
        DealPart(DealUnit.hours, 1),
        DealPart(DealUnit.minutes, 53),
        DealPart(DealUnit.seconds, 54),
      ]);
      expect(_values(2 * 86400 + 3 * 3600 + 4 * 60 + 5, _all), [2, 3, 4, 5]);
    });

    test('the 24 hour rule: days are hidden while less than a day is left, shown at exactly one day', () {
      expect(splitDealTime(86399, _all).map((p) => p.unit), [DealUnit.hours, DealUnit.minutes, DealUnit.seconds]);
      expect(_values(86399, _all), [23, 59, 59]);
      expect(splitDealTime(86400, _all).first, const DealPart(DealUnit.days, 1));
      expect(_values(86400, _all), [1, 0, 0, 0]);
    });

    test('the biggest shown unit absorbs the bigger ones that are not shown', () {
      expect(_values(3 * 86400 + 2 * 3600 + 5 * 60 + 9, [DealUnit.hours, DealUnit.minutes, DealUnit.seconds]), [
        74,
        5,
        9,
      ]);
      expect(_values(2 * 3600 + 5 * 60 + 9, [DealUnit.minutes, DealUnit.seconds]), [125, 9]);
      expect(_values(125, [DealUnit.seconds, DealUnit.minutes]), [
        2,
        5,
      ], reason: 'the order of the list does not matter');
    });

    test('a unit that is not shown hands its seconds to the next smaller one shown', () {
      expect(_values(2 * 3600 + 5 * 60 + 9, [DealUnit.hours, DealUnit.seconds]), [2, 309]);
    });

    test('never negative, never an empty list', () {
      expect(_values(-50, _all), [0, 0, 0]);
      expect(_values(0, _all), [0, 0, 0]);
      expect(splitDealTime(100, const []), hasLength(3));
      expect(splitDealTime(50, const [DealUnit.days, DealUnit.hours]).map((p) => p.unit), [
        DealUnit.hours,
      ], reason: 'days go, one unit stays');
      expect(splitDealTime(50, const [DealUnit.days]).map((p) => p.unit), [
        DealUnit.days,
      ], reason: 'the only unit is never hidden');
    });

    test('duplicates are counted once', () {
      expect(splitDealTime(61, const [DealUnit.minutes, DealUnit.minutes, DealUnit.seconds]).length, 2);
    });
  });

  group('dealSecondsLeft', () {
    final target = DateTime.utc(2026, 11, 17, 12);

    test('counts whole seconds, rounded up, and reads zero only when the moment has come', () {
      expect(dealSecondsLeft(target, target.subtract(const Duration(seconds: 90))), 90);
      expect(dealSecondsLeft(target, target.subtract(const Duration(milliseconds: 1500))), 2);
      expect(dealSecondsLeft(target, target.subtract(const Duration(microseconds: 1))), 1);
      expect(dealSecondsLeft(target, target), 0);
    });

    test('never negative once the time is over', () {
      expect(dealSecondsLeft(target, target.add(const Duration(hours: 5))), 0);
    });

    test('null without a time', () => expect(dealSecondsLeft(null, target), isNull));
  });

  group('server clock skew', () {
    test('a phone that is behind adds the skew and sees the server time', () {
      final server = DateTime.utc(2026, 11, 17, 9, 12);
      final phone = server.subtract(const Duration(minutes: 7));
      final skew = dealServerSkew(server, phone);
      expect(skew, const Duration(minutes: 7));
      expect(phone.add(skew), server);
      final ends = server.add(const Duration(hours: 1));
      expect(dealSecondsLeft(ends, phone.add(skew)), 3600, reason: 'the phone clock never changes the countdown');
      expect(dealSecondsLeft(ends, phone), 3600 + 7 * 60, reason: 'without the skew it would be wrong');
    });

    test('a phone that is ahead takes the skew off', () {
      final server = DateTime.utc(2026, 11, 17, 9, 12);
      final phone = server.add(const Duration(hours: 2));
      expect(dealServerSkew(server, phone), const Duration(hours: -2));
    });

    test('no server time means no skew', () => expect(dealServerSkew(null, DateTime.utc(2026)), Duration.zero));
  });

  group('urgency', () {
    test('urgent below the limit, not at it, never at zero or when off', () {
      expect(dealIsUrgent(3599, 60), isTrue);
      expect(dealIsUrgent(3600, 60), isFalse);
      expect(dealIsUrgent(1, 60), isTrue);
      expect(dealIsUrgent(0, 60), isFalse);
      expect(dealIsUrgent(10, 0), isFalse);
      expect(dealIsUrgent(null, 60), isFalse);
    });
  });

  group('spoken parts', () {
    test('whole days, hours and minutes; seconds are left out', () {
      final p = dealSpokenParts(2 * 86400 + 3 * 3600 + 4 * 60 + 59);
      expect((p.days, p.hours, p.minutes), (2, 3, 4));
      expect(dealSpokenParts(59).minutes, 0);
      expect(dealSpokenParts(-5).days, 0);
    });
  });

  group('DealUnit.parse', () {
    test('reads the contract letters in any case and ignores the rest', () {
      expect(DealUnit.parse('d'), DealUnit.days);
      expect(DealUnit.parse(' H '), DealUnit.hours);
      expect(DealUnit.parse('x'), isNull);
      expect(DealUnit.parse(3), isNull);
      expect(DealUnit.parse(null), isNull);
    });
  });
}
