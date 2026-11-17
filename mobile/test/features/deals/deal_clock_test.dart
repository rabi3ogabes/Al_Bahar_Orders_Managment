// The shared clock: it ticks only while somebody can see it, keeps the phone's clock out of phase decisions until the server's time is
// known, and asks for fresh data (with growing pauses) when a deal's moment has passed.
import 'package:albahar_customer/features/deals/data/deals_repository.dart';
import 'package:albahar_customer/features/deals/presentation/widgets/deal_clock.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'deals_fixtures.dart';

DealClock _clock(WidgetTester t) => DealClockScope.peek(t.element(find.byKey(const ValueKey('probe'))))!;

Widget _host({required DateTime Function() phone, bool covered = false, bool active = true, bool calibrated = true}) =>
    ProviderScope(
      key: UniqueKey(),
      overrides: [dealDeviceNowProvider.overrideWithValue(phone), if (calibrated) calibratedClock],
      child: MaterialApp(
        home: TickerMode(
          enabled: !covered,
          child: DealClockHost(
            active: active,
            child: const SizedBox(key: ValueKey('probe')),
          ),
        ),
      ),
    );

void main() {
  group('the clock ticks only while it can be seen', () {
    testWidgets('on top of the screen and in the foreground it ticks', (t) async {
      await t.pumpWidget(_host(phone: () => dealsNow));
      expect(_clock(t).running, isTrue);
    });

    testWidgets(
      'a covered screen (its tickers are off: another page is on top) stops the timer and resumes it when uncovered',
      (t) async {
        var covered = true;
        late StateSetter set;
        await t.pumpWidget(
          StatefulBuilder(
            builder: (context, setState) {
              set = setState;
              return ProviderScope(
                overrides: [dealDeviceNowProvider.overrideWithValue(() => dealsNow)],
                child: MaterialApp(
                  home: TickerMode(
                    enabled: !covered,
                    child: const DealClockHost(child: SizedBox(key: ValueKey('probe'))),
                  ),
                ),
              );
            },
          ),
        );
        expect(_clock(t).running, isFalse, reason: 'covered: no timer for nobody');
        set(() => covered = false);
        await t.pump();
        expect(_clock(t).running, isTrue, reason: 'back on top: it ticks again');
        set(() => covered = true);
        await t.pump();
        expect(_clock(t).running, isFalse);
      },
    );

    testWidgets('a page that says it needs no clock never ticks, whatever else', (t) async {
      await t.pumpWidget(_host(phone: () => dealsNow, active: false));
      expect(_clock(t).running, isFalse);
    });

    testWidgets('the app in the background stops it; a covered screen stays stopped when the app comes back', (
      t,
    ) async {
      await t.pumpWidget(_host(phone: () => dealsNow, covered: true));
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      t.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await t.pump();
      expect(_clock(t).running, isFalse, reason: 'resuming the app must not wake a screen nobody sees');
    });
  });

  group('the phone clock decides nothing until the server\'s time is known', () {
    testWidgets(
      'uncalibrated: the countdown uses the phone, trustedNow is null; calibrated: both are the corrected server time',
      (t) async {
        await t.pumpWidget(_host(phone: () => dealsNow, calibrated: false));
        expect(_clock(t).skew, isNull);
        expect(_clock(t).trustedNow(), isNull);
        expect(_clock(t).now(), dealsNow);
        await t.pumpWidget(_host(phone: () => dealsNow));
        expect(_clock(t).skew, Duration.zero);
        expect(_clock(t).trustedNow(), dealsNow);
      },
    );

    testWidgets('hearing the server\'s time later calibrates the running clock', (t) async {
      await t.pumpWidget(_host(phone: () => dealsNow, calibrated: false));
      final container = ProviderScope.containerOf(t.element(find.byKey(const ValueKey('probe'))));
      container.read(dealSkewProvider.notifier).set(const Duration(minutes: 5));
      await t.pump();
      expect(_clock(t).trustedNow(), dealsNow.add(const Duration(minutes: 5)));
    });
  });

  group('DealDueWatcher', () {
    testWidgets('fires once when the moment passes, then again with doubling pauses (15 s, 30 s, 60 s...) capped', (
      t,
    ) async {
      var now = dealsNow;
      var fired = 0;
      await t.pumpWidget(
        ProviderScope(
          overrides: [dealDeviceNowProvider.overrideWithValue(() => now)],
          child: MaterialApp(
            home: DealClockHost(
              child: DealDueWatcher(
                dueAt: dealsNow.add(const Duration(seconds: 5)),
                onDue: () => fired++,
                maxRetry: const Duration(seconds: 60),
              ),
            ),
          ),
        ),
      );
      Future<void> advance(int seconds) async {
        for (var i = 0; i < seconds; i++) {
          now = now.add(const Duration(seconds: 1));
          await t.pump(const Duration(seconds: 1));
        }
      }

      // The moment is at t = 5 s; it fires at t = 5, then after 15 s (t = 20), 30 s (t = 50), 60 s (t = 110, the cap) and 60 s (t = 170).
      await advance(4);
      expect(fired, 0, reason: 'not yet');
      await advance(2);
      expect(fired, 1, reason: 'due');
      await advance(13);
      expect(fired, 1, reason: 'the first pause is 15 s');
      await advance(2);
      expect(fired, 2);
      await advance(28);
      expect(fired, 2, reason: 'the second pause is 30 s');
      await advance(2);
      expect(fired, 3);
      await advance(58);
      expect(fired, 3, reason: 'the third is 60 s (the cap)');
      await advance(2);
      expect(fired, 4);
      await advance(58);
      expect(fired, 4, reason: 'never longer than the cap');
      await advance(2);
      expect(fired, 5);
    });

    testWidgets('a new moment starts again at the short pause', (t) async {
      var now = dealsNow;
      var fired = 0;
      DateTime due = dealsNow.add(const Duration(seconds: 1));
      late StateSetter set;
      await t.pumpWidget(
        StatefulBuilder(
          builder: (context, setState) {
            set = setState;
            return ProviderScope(
              overrides: [dealDeviceNowProvider.overrideWithValue(() => now)],
              child: MaterialApp(
                home: DealClockHost(
                  child: DealDueWatcher(dueAt: due, onDue: () => fired++),
                ),
              ),
            );
          },
        ),
      );
      now = now.add(const Duration(seconds: 2));
      await t.pump(const Duration(seconds: 2));
      expect(fired, 1);
      set(() => due = dealsNow.add(const Duration(seconds: 2)));
      await t.pump(const Duration(seconds: 1));
      expect(fired, 2, reason: 'another moment, already past: asked at once');
    });
  });
}
