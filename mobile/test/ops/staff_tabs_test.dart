import 'dart:async';
import 'dart:io';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/realtime/pusher_client.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:albahar_customer/ops/slide_to_confirm.dart';
import 'package:albahar_customer/ops/staff/fleet_map_tab.dart';
import 'package:albahar_customer/ops/staff/staff_groups.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:albahar_customer/ops/staff/staff_tabs.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

/// The staff home has THREE tabs at most, all in view at once: To do (new + preparing + ready in one list), On the way (failed
/// deliveries first) and Drivers (list | map). These tests pin that design: what the tabs say, how the one list groups and filters,
/// how it follows real-time messages, and that nothing scrolls sideways at the smallest phone with large text.

const _phone = Variant('390 en', Size(390, 844), kEn);
const _tall = Variant('390x1600', Size(390, 1600), kEn);
const _small = Variant('320x568 en', Size(320, 568), kEn);
const _smallLarge = Variant('320x568 en 1.3x', Size(320, 568), kEn, scale: 1.3);
const _smallLargeAr = Variant('320x568 ar 1.3x', Size(320, 568), kAr, scale: 1.3);
const _smallLargeArDark = Variant('320x568 ar 1.3x dark', Size(320, 568), kAr, scale: 1.3, dark: true);

StaffOverview _overview({
  int fresh = 0,
  int preparing = 0,
  int ready = 0,
  int out = 0,
  int failed = 0,
  int waiting = 0,
  int onDuty = 1,
  int drivers = 2,
  bool auto = false,
}) => StaffOverview.fromJson({
  'branch': {'id': 'b1', 'name': 'Al Markhiya Main Branch', 'dispatch_mode': auto ? 'auto' : 'manual'},
  'new': fresh,
  'preparing': preparing,
  'ready': ready,
  'out_for_delivery': out,
  'failed': failed,
  'waiting_for_driver': waiting,
  'drivers_on_duty': onDuty,
  'drivers_total': drivers,
});

QueueOrder _order(String id, String status, String number, {String? driver}) =>
    FakeOps.order(id: id, status: status, number: number, driver: driver);

/// A busy branch: 2 new, 3 preparing, 1 ready, 2 out with a driver, 1 failed.
FakeOps _branch({List<String> perms = const ['*'], OpsStatusStyle style = OpsStatusStyle.buttons}) => FakeOps()
  ..perms = perms
  ..style = style
  ..overviewData = _overview(fresh: 2, preparing: 3, ready: 1, out: 2, failed: 1, waiting: 1)
  ..queues = {
    'new': [_order('n1', 'pending', 'AB300001'), _order('n2', 'pending', 'AB300002')],
    'preparing': [
      _order('p1', 'accepted', 'AB300003'),
      _order('p2', 'preparing', 'AB300004'),
      _order('p3', 'preparing', 'AB300005'),
    ],
    'ready': [_order('r1', 'ready', 'AB300006')],
    'out': [
      _order('o1', 'out_for_delivery', 'AB300007', driver: 'Omar'),
      _order('o2', 'dispatched', 'AB300008', driver: 'Omar'),
    ],
    'failed': [_order('x1', 'failed', 'AB300009', driver: 'Omar')],
  };

class _Pumped {
  _Pumped(this.container, this.events);
  final ProviderContainer container;
  final StreamController<PusherEvent> events;

  /// What the branch channel says about one order's part (the message the dashboard and the other phones cause).
  Future<void> say(WidgetTester tester, String fulfillmentId, String status, {int seq = 50, String number = ''}) async {
    events.add(
      PusherEvent(
        channel: 'private-branches.b1',
        event: 'dispatch.updated',
        data: {
          'fulfillment_id': fulfillmentId,
          'fulfillment_type': 'delivery',
          'fulfillment_status': status,
          'kind': 'order',
          'seq': seq,
          'number': number,
          'order_id': 'o-$fulfillmentId',
        },
      ),
    );
    await settle(tester);
  }
}

Future<_Pumped> _pump(WidgetTester tester, FakeOps repo, {Variant variant = _phone}) async {
  useVariant(tester, variant);
  final events = StreamController<PusherEvent>.broadcast();
  addTearDown(events.close);
  await tester.pumpWidget(
    opsHost(const StaffHome(), repo, variant: variant, role: OpsRole.staff, staffEvents: events.stream),
  );
  await settle(tester);
  return _Pumped(ProviderScope.containerOf(tester.element(find.byType(StaffHome))), events);
}

Finder get _scroll => find.descendant(of: find.byType(CustomScrollView), matching: find.byType(Scrollable)).first;

/// Scrolls the open list until [target] is built and on screen.
Future<void> _reveal(WidgetTester tester, Finder target) async {
  if (target.evaluate().isEmpty) {
    await tester.scrollUntilVisible(target, 150, scrollable: _scroll, maxScrolls: 80);
  }
  await tester.ensureVisible(target);
  await tester.pump();
}

Future<void> _openTab(WidgetTester tester, int index) async {
  await tester.tap(find.byType(Tab).at(index));
  await settle(tester);
}

/// The headers on screen, top to bottom, as (title, count).
List<(String, String)> _headers(WidgetTester tester) => [
  for (final h in find.byType(SliverPersistentHeader).evaluate())
    () {
      final texts = find
          .descendant(of: find.byWidget(h.widget), matching: find.byType(Text))
          .evaluate()
          .map((e) => (e.widget as Text).data ?? '')
          .toList();
      return (texts.first, texts.last);
    }(),
];

/// The order numbers of the cards on screen, top to bottom.
List<String> _cards(WidgetTester tester) => [
  for (final e in find.textContaining(RegExp(r'^#AB\d+$')).evaluate()) (e.widget as Text).data!.substring(1),
];

Finder _chip(String key) => find.byKey(ValueKey('todo-filter-$key'));

String _chipCount(WidgetTester tester, String key) =>
    (find.descendant(of: _chip(key), matching: find.byType(Text)).evaluate().first.widget as Text).data!;

int _badge(WidgetTester tester, int tab) => tester
    .widget<StaffCountBadge>(find.descendant(of: find.byType(Tab).at(tab), matching: find.byType(StaffCountBadge)))
    .count;

StaffBadgeTone _tone(WidgetTester tester, int tab) => tester
    .widget<StaffCountBadge>(find.descendant(of: find.byType(Tab).at(tab), matching: find.byType(StaffCountBadge)))
    .tone;

void main() {
  group('three tabs, all in view', () {
    testWidgets('with deliveries.view: To do, On the way, Drivers - each with its number', (tester) async {
      await _pump(tester, _branch());
      expect(find.byType(Tab), findsNWidgets(3));
      for (final (i, word) in const [(0, 'To do'), (1, 'On the way'), (2, 'Drivers')]) {
        expect(
          find.descendant(of: find.byType(Tab).at(i), matching: find.text(word)),
          findsOneWidget,
          reason: word,
        );
      }
      expect(_badge(tester, 0), 6, reason: 'new 2 + preparing 3 + ready 1');
      expect(_badge(tester, 1), 3, reason: 'out 2 + failed 1');
      expect(_badge(tester, 2), 1, reason: 'drivers on duty');
    });

    testWidgets('Arabic words', (tester) async {
      await _pump(tester, _branch(), variant: const Variant('390 ar', Size(390, 844), kAr));
      for (final (i, word) in const [(0, 'المطلوب'), (1, 'في الطريق'), (2, 'السائقون')]) {
        expect(
          find.descendant(of: find.byType(Tab).at(i), matching: find.text(word)),
          findsOneWidget,
          reason: word,
        );
      }
    });

    testWidgets('without deliveries.view only To do and On the way', (tester) async {
      await _pump(tester, _branch(perms: const ['orders.view', 'orders.edit']));
      expect(find.byType(Tab), findsNWidgets(2));
      expect(find.text('Drivers'), findsNothing);
      expect(find.descendant(of: find.byType(Tab).at(1), matching: find.text('On the way')), findsOneWidget);
    });

    testWidgets('the tabs never scroll: equal thirds of the width, nothing scrollable inside the bar', (tester) async {
      await _pump(tester, _branch(), variant: _small);
      final bar = tester.widget<TabBar>(find.byType(TabBar));
      expect(bar.isScrollable, isFalse);
      expect(find.descendant(of: find.byType(TabBar), matching: find.byType(Scrollable)), findsNothing);
      final centers = [for (var i = 0; i < 3; i++) tester.getCenter(find.byType(Tab).at(i)).dx];
      for (var i = 0; i < 3; i++) {
        expect(centers[i], closeTo(320 / 3 * (i + 0.5), 2), reason: 'tab $i is the middle of its third');
      }
    });

    test('the source never brings back a scrolling tab bar', () {
      for (final f in Directory('lib/ops/staff').listSync().whereType<File>().where((f) => f.path.endsWith('.dart'))) {
        final text = f.readAsStringSync();
        expect(RegExp(r'isScrollable:\s*true').hasMatch(text), isFalse, reason: '${f.path} makes the tabs scroll');
        expect(text.contains('TabAlignment'), isFalse, reason: '${f.path} aligns scrolling tabs');
      }
    });

    testWidgets('the tab bar is drawn only when there is something to choose between', (tester) async {
      PreferredSizeWidget? bar;
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) {
              bar = staffTabBar(context, const [StaffTab.todo], null);
              return const SizedBox();
            },
          ),
        ),
      );
      expect(bar, isNull);
    });

    test('the tabs an account gets', () {
      expect(staffTabsFor(canSeeDrivers: false), [StaffTab.todo, StaffTab.way]);
      expect(staffTabsFor(canSeeDrivers: true), [StaffTab.todo, StaffTab.way, StaffTab.drivers]);
    });

    test('numbers and tones of the tabs', () {
      final o = _overview(fresh: 2, preparing: 3, ready: 1, out: 2, failed: 1, onDuty: 4);
      expect(staffTabCount(StaffTab.todo, o), 6);
      expect(staffTabCount(StaffTab.way, o), 3);
      expect(staffTabCount(StaffTab.drivers, o), 4);
      expect(staffTabCount(StaffTab.todo, null), isNull);
      expect(staffTabTone(StaffTab.todo, o), StaffBadgeTone.attention);
      expect(staffTabTone(StaffTab.way, o), StaffBadgeTone.danger);
      expect(staffTabTone(StaffTab.drivers, o), StaffBadgeTone.quiet);
      final calm = _overview(preparing: 2, ready: 1, out: 1);
      expect(staffTabTone(StaffTab.todo, calm), StaffBadgeTone.quiet, reason: 'preparing and ready need no alarm');
      expect(staffTabTone(StaffTab.way, calm), StaffBadgeTone.quiet);
    });

    testWidgets('a screen reader hears the tab and its orders: "To do, 6 orders, 2 new"', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _branch());
      expect(find.bySemanticsLabel(RegExp('^To do, 6 orders, 2 new')), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('^On the way, 3 orders, 1 failed')), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('^Drivers, 1 on duty')), findsOneWidget);
      handle.dispose();
    });

    testWidgets('Arabic screen reader labels use the Arabic plural forms', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _branch(), variant: const Variant('390 ar', Size(390, 844), kAr));
      expect(find.bySemanticsLabel(RegExp('^المطلوب، 6 طلبات، منها 2 جديدة')), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp('^في الطريق، 3 طلبات، منها 1 تعذّر توصيلها')), findsOneWidget);
      handle.dispose();
    });

    test('order counts in words: English singular/plural and Arabic singular, dual, few, many', () {
      const en = OpsStrings('en');
      expect([en.orderCount(0), en.orderCount(1), en.orderCount(5)], ['0 orders', '1 order', '5 orders']);
      const ar = OpsStrings('ar');
      expect(
        [
          for (final n in [0, 1, 2, 3, 10, 11, 100]) ar.orderCount(n),
        ],
        ['لا طلبات', 'طلب واحد', 'طلبان', '3 طلبات', '10 طلبات', '11 طلباً', '100 طلباً'],
      );
    });
  });

  group('To do: one list, grouped by stage', () {
    testWidgets(
      'new, preparing and ready are on one list under their headers, with counts; stages that are empty are hidden',
      (tester) async {
        await _pump(tester, _branch(), variant: _tall);
        expect(_headers(tester), [('New', '2'), ('Preparing', '3'), ('Ready', '1')]);
        expect(find.byType(CustomScrollView), findsOneWidget);

        final repo = _branch()
          ..queues = {
            'new': [_order('n1', 'pending', 'AB300001')],
            'ready': [_order('r1', 'ready', 'AB300006')],
          };
        await tester.pumpWidget(const SizedBox.shrink());
        await _pump(tester, repo, variant: _tall);
        expect(_headers(tester), [('New', '1'), ('Ready', '1')], reason: 'no empty "Preparing" header');
      },
    );

    testWidgets(
      'every order of the three stages is there once, in stage order and oldest first; nothing from On the way',
      (tester) async {
        await _pump(tester, _branch(), variant: _tall);
        expect(_cards(tester), ['AB300001', 'AB300002', 'AB300003', 'AB300004', 'AB300005', 'AB300006']);
        expect(find.text('#AB300007'), findsNothing);
        expect(find.text('#AB300009'), findsNothing);
      },
    );

    testWidgets('a full queue (100 per stage, the most the API sends) loses nothing and repeats nothing', (
      tester,
    ) async {
      final repo = FakeOps()
        ..overviewData = _overview(fresh: 100, preparing: 100, ready: 100)
        ..queues = {
          'new': [for (var i = 0; i < 100; i++) _order('n$i', 'pending', 'AB1${i.toString().padLeft(5, '0')}')],
          'preparing': [for (var i = 0; i < 100; i++) _order('p$i', 'preparing', 'AB2${i.toString().padLeft(5, '0')}')],
          'ready': [for (var i = 0; i < 100; i++) _order('r$i', 'ready', 'AB3${i.toString().padLeft(5, '0')}')],
        };
      await _pump(tester, repo);
      expect(_chipCount(tester, 'all'), '300');
      expect(
        [
          for (final k in ['new', 'preparing', 'ready']) _chipCount(tester, k),
        ],
        ['100', '100', '100'],
      );
      expect(find.byType(Card).evaluate().length, lessThan(30), reason: 'the list builds only what is on screen');
      // All the way down: the very last ready order is reachable, and the list holds 300 distinct cards in total.
      for (var i = 0; i < 12 && find.text('#AB300099').evaluate().isEmpty; i++) {
        await tester.drag(_scroll, const Offset(0, -40000));
        await settle(tester);
      }
      expect(find.text('#AB300099'), findsOneWidget);
      final total = repo.queues.values.expand((q) => q).map((o) => o.number).toSet();
      expect(total.length, 300);
    });

    testWidgets(
      'filter chips: All, New, Preparing, Ready with their numbers; a tap filters, a second tap or All resets',
      (tester) async {
        await _pump(tester, _branch(), variant: _tall);
        expect(
          [
            for (final k in ['all', 'new', 'preparing', 'ready']) _chipCount(tester, k),
          ],
          ['6', '2', '3', '1'],
        );
        expect(_headers(tester).length, 3, reason: 'All by default');

        await tester.tap(_chip('preparing'));
        await settle(tester);
        expect(_headers(tester), [('Preparing', '3')]);
        expect(_cards(tester), ['AB300003', 'AB300004', 'AB300005']);

        await tester.tap(_chip('preparing'));
        await settle(tester);
        expect(_headers(tester).length, 3, reason: 'the second tap on the same chip shows everything again');

        await tester.tap(_chip('ready'));
        await settle(tester);
        expect(_headers(tester), [('Ready', '1')]);
        await tester.tap(_chip('new'));
        await settle(tester);
        expect(_headers(tester), [('New', '2')], reason: 'one stage at a time');
        await tester.tap(_chip('all'));
        await settle(tester);
        expect(_headers(tester).length, 3);
      },
    );

    testWidgets('the chosen filter survives a visit to another tab and a rebuild', (tester) async {
      final p = await _pump(tester, _branch());
      await tester.tap(_chip('ready'));
      await settle(tester);
      await _openTab(tester, 1);
      expect(find.byType(CustomScrollView).evaluate().length, greaterThan(0));
      await _openTab(tester, 0);
      expect(p.container.read(staffTodoFilterProvider), 'ready');
      expect(_headers(tester), [('Ready', '1')]);
      p.container.invalidate(staffOverviewProvider);
      await settle(tester);
      expect(_headers(tester), [('Ready', '1')]);
    });

    testWidgets('when the last order is done the filter goes back to All, so the next order is never hidden by it', (
      tester,
    ) async {
      final p = await _pump(tester, _branch());
      await tester.tap(_chip('ready'));
      await settle(tester);
      expect(p.container.read(staffTodoFilterProvider), 'ready');
      var seq = 70;
      for (final (id, number) in const [
        ('n1', 'AB300001'),
        ('n2', 'AB300002'),
        ('p1', 'AB300003'),
        ('p2', 'AB300004'),
        ('p3', 'AB300005'),
        ('r1', 'AB300006'),
      ]) {
        await p.say(tester, id, 'cancelled', seq: seq++, number: number);
      }
      expect(find.text('All caught up'), findsOneWidget);
      expect(p.container.read(staffTodoFilterProvider), isNull);
    });

    testWidgets('a chosen stage with no orders says so in plain words and the chips stay to leave it', (tester) async {
      final repo = _branch()..queues['ready'] = const [];
      await _pump(tester, repo);
      await tester.tap(_chip('ready'));
      await settle(tester);
      expect(find.text('Nothing in this step right now'), findsOneWidget);
      expect(_chip('all'), findsOneWidget);
      await tester.tap(_chip('all'));
      await settle(tester);
      expect(_headers(tester).length, 2);
    });

    testWidgets('no orders at all: a calm "All caught up", no filter row', (tester) async {
      await _pump(tester, _branch()..queues = {});
      expect(find.text('All caught up'), findsOneWidget);
      expect(_chip('all'), findsNothing);
    });

    testWidgets(
      'a message moves an order from one section to the next at once: lists, headers, chips and the tab number',
      (tester) async {
        final p = await _pump(tester, _branch(), variant: _tall);
        expect(_headers(tester), [('New', '2'), ('Preparing', '3'), ('Ready', '1')]);
        // A colleague accepts AB300001 on another phone.
        await p.say(tester, 'n1', 'accepted', number: 'AB300001');
        expect(_headers(tester), [('New', '1'), ('Preparing', '4'), ('Ready', '1')]);
        expect(_cards(tester), ['AB300002', 'AB300003', 'AB300004', 'AB300005', 'AB300001', 'AB300006']);
        expect(
          [
            for (final k in ['all', 'new', 'preparing', 'ready']) _chipCount(tester, k),
          ],
          ['6', '1', '4', '1'],
        );
        expect(_badge(tester, 0), 6, reason: 'the order is still to do');
        // ...and the last new order is taken too: its section disappears.
        await p.say(tester, 'n2', 'accepted', seq: 51, number: 'AB300002');
        expect(_headers(tester), [('Preparing', '5'), ('Ready', '1')]);
        expect(_tone(tester, 0), StaffBadgeTone.quiet, reason: 'nothing new is waiting any more');
        // A ready order leaves for the road: it is on the On the way tab now.
        await p.say(tester, 'r1', 'out_for_delivery', seq: 52, number: 'AB300006');
        expect(_headers(tester), [('Preparing', '5')]);
        expect(_badge(tester, 0), 5);
        expect(_badge(tester, 1), 4);
      },
    );

    testWidgets('a brand-new order: the same alert as before, the New section grows and the To do number lights up', (
      tester,
    ) async {
      final repo = _branch()
        ..overviewData = _overview(fresh: 0, preparing: 3, ready: 1, out: 2, failed: 1)
        ..queues['new'] = const [];
      final p = await _pump(tester, repo);
      expect(_tone(tester, 0), StaffBadgeTone.quiet);
      // The server has the order by the time the app reads again.
      repo.overviewData = _overview(fresh: 1, preparing: 3, ready: 1, out: 2, failed: 1);
      repo.queues['new'] = [_order('n9', 'pending', 'AB300099')];
      await p.say(tester, 'n9', 'pending', seq: 1, number: 'AB300099');
      await settle(tester);
      expect(find.text('New order #AB300099'), findsOneWidget);
      expect(_headers(tester).first, ('New', '1'));
      expect(_tone(tester, 0), StaffBadgeTone.attention);
      expect(_badge(tester, 0), 5);
    });

    testWidgets('pull to refresh reads all three stages again and the counts', (tester) async {
      final repo = _branch();
      await _pump(tester, repo);
      final before = repo.queueLoads;
      await tester.fling(find.text('#AB300001'), const Offset(0, 500), 1500);
      await settle(tester);
      await settle(tester);
      expect(repo.queueLoads - before, greaterThanOrEqualTo(3));
    });

    testWidgets('one stage failing to refresh keeps the rows with a notice; failing at the first read offers a retry', (
      tester,
    ) async {
      final repo = _branch();
      final p = await _pump(tester, repo, variant: _tall);
      repo.queueError = apiError(ApiErrorKind.offline, 0);
      p.container.invalidate(staffQueueProvider('ready'));
      await settle(tester);
      expect(find.text('Could not refresh. Showing the last list.'), findsOneWidget);
      expect(_headers(tester).length, 3, reason: 'the last good rows stay');
      expect(find.text('#AB300001'), findsOneWidget);

      await tester.pumpWidget(const SizedBox.shrink());
      final broken = _branch()..queueError = apiError(ApiErrorKind.offline, 0);
      await _pump(tester, broken);
      expect(find.text('Try again'), findsWidgets);
      expect(find.text('#AB300001'), findsNothing, reason: 'a half-filled list would look like "nothing waiting"');
      broken.queueError = null;
      await tester.tap(find.text('Try again').last);
      await settle(tester);
      expect(find.text('#AB300001'), findsOneWidget);
    });

    testWidgets('the slide to confirm works on any order of the one list, top or far down', (tester) async {
      final repo = _branch(style: OpsStatusStyle.slide);
      await _pump(tester, repo, variant: const Variant('360x640', Size(360, 640), kEn));
      // The first new order.
      await tester.drag(find.byKey(slideThumbKey).first, const Offset(260, 0));
      await settle(tester);
      expect(repo.calls, ['advance:n1:accepted']);
      // A preparing order lower down: scroll to its slide and slide it.
      await _reveal(tester, find.text('#AB300005'));
      final card = find.ancestor(of: find.text('#AB300005'), matching: find.byType(Card));
      final thumb = find.descendant(of: card, matching: find.byKey(slideThumbKey));
      expect(thumb, findsOneWidget);
      expect(find.descendant(of: card, matching: find.text('Slide to mark ready')), findsOneWidget);
      await tester.drag(thumb, const Offset(260, 0));
      await settle(tester);
      expect(repo.calls.last, 'advance:p3:ready');
    });

    testWidgets('the buttons work on the one list too (accept, assign a driver)', (tester) async {
      final repo = _branch();
      await _pump(tester, repo);
      await tester.tap(find.text('Accept order').first);
      await settle(tester);
      expect(repo.calls, ['advance:n1:accepted']);
      await _reveal(tester, find.text('Assign driver'));
      await tester.tap(find.text('Assign driver'));
      await settle(tester);
      await tester.tap(find.text('Omar').last);
      await settle(tester);
      expect(repo.calls.last, 'assign:r1:u1');
    });

    testWidgets('a header stays at the top while its orders scroll, and the next header takes its place', (
      tester,
    ) async {
      final repo = _branch()
        ..queues['new'] = [for (var i = 0; i < 8; i++) _order('n$i', 'pending', 'AB4${i.toString().padLeft(5, '0')}')];
      await _pump(tester, repo, variant: const Variant('360x640', Size(360, 640), kEn));
      final top = tester.getTopLeft(find.byType(CustomScrollView)).dy;
      Finder header(String title) =>
          find.descendant(of: find.byType(SliverPersistentHeader), matching: find.text(title));
      // A header is a sliver; its words sit in the middle of its 48 dp row at the very top of the list when it is pinned.
      double pinnedMiddle() => top + 24;
      expect(
        tester.getCenter(header('New')).dy,
        greaterThan(pinnedMiddle() + 30),
        reason: 'under the filters at first',
      );
      await tester.drag(_scroll, const Offset(0, -700));
      await settle(tester);
      expect(tester.getCenter(header('New')).dy, closeTo(pinnedMiddle(), 8), reason: 'pinned at the top');
      await tester.drag(_scroll, const Offset(0, -1100));
      await settle(tester);
      expect(header('New'), findsNothing, reason: 'its group has scrolled away and the next header took the place');
      expect(tester.getCenter(header('Preparing')).dy, closeTo(pinnedMiddle(), 8));
    });
  });

  group('On the way: failed first', () {
    testWidgets('a failed delivery is on top under a red "Needs attention" header, then the deliveries that are out', (
      tester,
    ) async {
      await _pump(tester, _branch(), variant: _tall);
      await _openTab(tester, 1);
      expect(_headers(tester), [('Needs attention', '1'), ('On the way', '2')]);
      expect(_cards(tester), ['AB300009', 'AB300007', 'AB300008']);
      expect(find.text('Delivery failed'), findsOneWidget);
      final danger = tester.widget<Text>(find.text('Needs attention'));
      expect(danger.style?.color, isNotNull);
      final top = tester.getTopLeft(find.text('Needs attention')).dy;
      expect(tester.getTopLeft(find.text('#AB300009')).dy, greaterThan(top));
      expect(tester.getTopLeft(find.text('#AB300009')).dy, lessThan(tester.getTopLeft(find.text('#AB300007')).dy));
    });

    testWidgets('the tab number turns red while a delivery has failed, and calm again when none has', (tester) async {
      final repo = _branch();
      final p = await _pump(tester, repo);
      expect(_tone(tester, 1), StaffBadgeTone.danger);
      // The failed delivery is brought back (it leaves the queue): red goes away.
      await p.say(tester, 'x1', 'returned', seq: 60, number: 'AB300009');
      expect(_tone(tester, 1), StaffBadgeTone.quiet);
      expect(_badge(tester, 1), 2);
      await _openTab(tester, 1);
      expect(_headers(tester), isEmpty, reason: 'with nothing failed, the tab itself says "On the way"');
      expect(find.text('Needs attention'), findsNothing);
      // Another delivery fails: red again, and the section is back on top.
      await p.say(tester, 'o1', 'failed', seq: 61, number: 'AB300007');
      expect(_tone(tester, 1), StaffBadgeTone.danger);
      expect(_headers(tester), [('Needs attention', '1'), ('On the way', '1')]);
    });

    testWidgets('only failed deliveries: just the red header; only deliveries out: no header at all', (tester) async {
      final repo = _branch()..queues['out'] = const [];
      await _pump(tester, repo, variant: _tall);
      await _openTab(tester, 1);
      expect(_headers(tester), [('Needs attention', '1')]);

      await tester.pumpWidget(const SizedBox.shrink());
      final calm = _branch()..queues['failed'] = const [];
      await _pump(tester, calm, variant: _tall);
      await _openTab(tester, 1);
      expect(_headers(tester), isEmpty);
      expect(_cards(tester), ['AB300007', 'AB300008']);
    });

    testWidgets('return to the branch stays on the failed card (with deliveries.edit)', (tester) async {
      final repo = _branch(perms: const ['orders.view', 'deliveries.edit']);
      await _pump(tester, repo);
      await _openTab(tester, 1);
      await tester.tap(find.text('Return to branch'));
      await settle(tester);
      await tester.tap(find.widgetWithText(FilledButton, 'Confirm return'));
      await settle(tester);
      expect(repo.calls, ['return:dl-x1']);
    });

    testWidgets('nothing on the road: the usual empty state', (tester) async {
      await _pump(
        tester,
        _branch()
          ..queues = {}
          ..overviewData = _overview(),
      );
      await _openTab(tester, 1);
      expect(find.text('Nothing here right now'), findsOneWidget);
      expect(_tone(tester, 1), StaffBadgeTone.quiet);
    });
  });

  group('Drivers: list and map in one tab', () {
    testWidgets('List is the first view; Live map shows the map; List brings the list back', (tester) async {
      final repo = _branch();
      await _pump(tester, repo);
      await _openTab(tester, 2);
      expect(find.byType(SegmentedButton<StaffDriversView>), findsOneWidget);
      expect(find.byTooltip('Call Omar'), findsOneWidget);
      expect(find.byType(FleetMapTab), findsNothing, reason: 'the map is not built (nor polling) while the list shows');
      await tester.tap(find.text('Live map'));
      await settle(tester);
      expect(find.byType(FleetMapTab), findsOneWidget);
      expect(find.byTooltip('Call Omar'), findsNothing);
      expect(repo.fleetLoads, greaterThan(0));
      await tester.tap(find.text('List'));
      await settle(tester);
      expect(find.byTooltip('Call Omar'), findsOneWidget);
      expect(find.byType(FleetMapTab), findsNothing);
    });

    testWidgets('the last choice is remembered while the app is open', (tester) async {
      final p = await _pump(tester, _branch());
      await _openTab(tester, 2);
      await tester.tap(find.text('Live map'));
      await settle(tester);
      await _openTab(tester, 0);
      expect(find.byType(FleetMapTab), findsNothing);
      await _openTab(tester, 2);
      expect(p.container.read(staffDriversViewProvider), StaffDriversView.map);
      expect(find.byType(FleetMapTab), findsOneWidget, reason: 'back on the map, where the employee left it');
    });

    testWidgets('both segments are easy targets (at least 48 dp tall) and say what they are', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _branch(), variant: _smallLarge);
      await _openTab(tester, 2);
      expect(find.text('List'), findsOneWidget);
      expect(find.text('Live map'), findsOneWidget);
      final bar = tester.getRect(find.byType(SegmentedButton<StaffDriversView>));
      expect(bar.height, greaterThanOrEqualTo(48));
      expect(bar.left, greaterThanOrEqualTo(0));
      expect(bar.right, lessThanOrEqualTo(320));
      expect(tester.takeException(), isNull);
      handle.dispose();
    });
  });

  group('the overview strip', () {
    testWidgets(
      'waiting for a driver stays (lit while any wait), the dispatch line stays; the numbers now on tabs are gone',
      (tester) async {
        await _pump(tester, _branch());
        expect(find.text('Waiting for a driver'), findsWidgets);
        expect(find.text('You assign drivers to ready orders.'), findsOneWidget);
        expect(find.text('Drivers on duty'), findsNothing, reason: 'the Drivers tab carries that number');
        // "On the way" is the tab's word, never a second count tile.
        expect(find.text('On the way'), findsOneWidget);
      },
    );

    testWidgets('an account without the Drivers tab still sees how many drivers are on duty', (tester) async {
      await _pump(tester, _branch(perms: const ['orders.view']));
      expect(find.text('Drivers on duty'), findsOneWidget);
    });

    testWidgets('auto dispatch says so', (tester) async {
      final repo = _branch()..overviewData = _overview(fresh: 1, auto: true);
      await _pump(tester, repo);
      expect(find.text('Auto dispatch: ready orders go to the least busy driver.'), findsOneWidget);
    });
  });

  group('every phone, language, text size and theme', () {
    for (final v in [_small, _smallLarge, _smallLargeAr, _smallLargeArDark]) {
      testWidgets('320 dp: nothing overflows or scrolls sideways, targets are big enough: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        final repo = _branch();
        await _pump(tester, repo, variant: v);
        expect(tester.takeException(), isNull);
        final w = v.size.width;
        // The tabs, the filters and the headers all sit inside the screen.
        for (var i = 0; i < 3; i++) {
          final r = tester.getRect(find.byType(Tab).at(i));
          expect(r.left, greaterThanOrEqualTo(0), reason: 'tab $i');
          expect(r.right, lessThanOrEqualTo(w), reason: 'tab $i');
        }
        for (final k in ['all', 'new', 'preparing', 'ready']) {
          final r = tester.getRect(_chip(k));
          expect(r.left, greaterThanOrEqualTo(0), reason: k);
          expect(r.right, lessThanOrEqualTo(w), reason: k);
          expect(r.height, greaterThanOrEqualTo(48), reason: k);
        }
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        // Down the whole list, then the other two tabs.
        await tester.drag(_scroll, const Offset(0, -3000));
        await settle(tester);
        expect(tester.takeException(), isNull);
        await _openTab(tester, 1);
        expect(tester.takeException(), isNull);
        expect(_headers(tester).first.$1, v.rtl ? 'تحتاج متابعة' : 'Needs attention');
        await _openTab(tester, 2);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await tester.tap(find.text(v.rtl ? 'الخريطة' : 'Live map'));
        await settle(tester);
        expect(tester.takeException(), isNull);
        handle.dispose();
      });
    }

    testWidgets('text at twice the normal size: the tabs and the filters still fit', (tester) async {
      await _pump(tester, _branch(), variant: const Variant('320x568 en 2x', Size(320, 568), kEn, scale: 2));
      // (The order card's own title row is older than this screen and tight at 2x; only the new parts are checked here.)
      tester.takeException();
      for (var i = 0; i < 3; i++) {
        expect(tester.getRect(find.byType(Tab).at(i)).right, lessThanOrEqualTo(320));
      }
      for (final k in ['all', 'new', 'preparing', 'ready']) {
        expect(tester.getRect(_chip(k)).right, lessThanOrEqualTo(320));
      }
    });
  });

  group('motion', () {
    testWidgets('a lit number pulses a couple of times, then rests', (tester) async {
      await _pump(tester, _branch());
      expect(tester.binding.transientCallbackCount, greaterThan(0), reason: 'the badge is pulsing');
      await tester.pump(const Duration(seconds: 3));
      expect(tester.binding.transientCallbackCount, 0, reason: 'never an endless animation');
    });

    testWidgets('with "reduce motion" it never moves', (tester) async {
      tester.platformDispatcher.accessibilityFeaturesTestValue = const FakeAccessibilityFeatures(
        disableAnimations: true,
      );
      addTearDown(tester.platformDispatcher.clearAccessibilityFeaturesTestValue);
      await _pump(tester, _branch());
      expect(_tone(tester, 0), StaffBadgeTone.attention, reason: 'still lit, by colour');
      expect(tester.binding.transientCallbackCount, 0);
    });
  });

  group('the rows of a tab (no phone needed)', () {
    QueueOrder o(String id, String status) => _order(id, status, 'AB$id');
    AsyncValue<List<QueueOrder>> data(List<QueueOrder> l) => AsyncData(l);

    test('stages keep their order, empty ones are left out, one can be chosen', () {
      final rows = StageRows(
        byStage: {
          'new': [o('1', 'pending')],
          'preparing': const [],
          'ready': [o('2', 'ready'), o('3', 'ready')],
        },
      );
      expect([for (final g in groupsOf(rows, kTodoStages)) (g.stage, g.orders.length)], [('new', 1), ('ready', 2)]);
      expect([for (final g in groupsOf(rows, kTodoStages, only: 'ready')) g.stage], ['ready']);
      expect(groupsOf(rows, kTodoStages, only: 'preparing'), isEmpty);
      expect(rows.total(kTodoStages), 3);
      expect(rows.of('out'), isEmpty);
    });

    test('the failed stage comes before the deliveries out', () {
      expect(kWayStages, ['failed', 'out']);
      expect(kTodoStages, ['new', 'preparing', 'ready']);
    });

    test('a tab is ready only when every stage answered; a failing stage keeps the last rows (stale)', () {
      final loading = combineStages({
        'new': data([o('1', 'pending')]),
        'ready': const AsyncLoading(),
      });
      expect(loading.loading, isTrue);
      expect(loading.ready, isFalse);

      final ok = combineStages({
        'new': data([o('1', 'pending')]),
        'ready': data([o('2', 'ready')]),
      });
      expect(ok.ready, isTrue);
      expect(ok.stale, isFalse);
      expect(ok.total(kTodoStages), 2);

      final failedFirst = combineStages({
        'new': data([o('1', 'pending')]),
        'ready': const AsyncError<List<QueueOrder>>('offline', StackTrace.empty),
      });
      expect(failedFirst.failed, isTrue);
      expect(failedFirst.byStage, isNull, reason: 'a half-filled list would pass for "nothing waiting"');
    });

    test('100 + 100 + 100 orders put side by side: no loss, no repeat', () {
      final rows = StageRows(
        byStage: {
          for (final (stage, status) in const [('new', 'pending'), ('preparing', 'preparing'), ('ready', 'ready')])
            stage: [for (var i = 0; i < 100; i++) o('$stage$i', status)],
        },
      );
      final ids = [
        for (final g in groupsOf(rows, kTodoStages))
          for (final x in g.orders) x.fulfillmentId,
      ];
      expect(ids.length, 300);
      expect(ids.toSet().length, 300);
    });
  });
}
