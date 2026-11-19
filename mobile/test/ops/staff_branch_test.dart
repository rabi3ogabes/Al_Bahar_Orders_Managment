import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/providers.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/staff_home.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'ops_test_kit.dart';
import 'staff_test_kit.dart';

const _phone = Variant('390x1400 en', Size(390, 1400), kEn);

const _two = [
  {'id': 'b1', 'name': 'Markhiya'},
  {'id': 'b2', 'name': 'Wakra'},
];

FakeOps _repo({List<Map<String, String>> branches = _two}) {
  final repo = FakeOps()
    ..overviewData = staffOverview(
      name: 'Markhiya',
      branches: branches,
      can: const {'orders.approve': true, 'pickup.view': true},
    )
    ..queues = {
      'new': [staffOrder(id: 'f1', number: 'AB100001')],
    };
  if (branches.length > 1) {
    repo.overviewByBranch['b2'] = staffOverview(
      id: 'b2',
      name: 'Wakra',
      branches: branches,
      can: const {'orders.approve': false, 'pickup.view': true},
    );
  }
  return repo;
}

Future<ProviderContainer> _pump(WidgetTester tester, FakeOps repo, {List<Override> overrides = const []}) async {
  useVariant(tester, _phone);
  await tester.pumpWidget(opsHost(const StaffHome(), repo, variant: _phone, role: OpsRole.staff, overrides: overrides));
  await settle(tester);
  return ProviderScope.containerOf(tester.element(find.byType(StaffHome)));
}

Finder get _picker => find.byKey(const ValueKey('branch-picker'));

void main() {
  group('the branch picker', () {
    testWidgets('a person with one branch sees the name as before, no picker', (tester) async {
      await _pump(
        tester,
        _repo(
          branches: const [
            {'id': 'b1', 'name': 'Markhiya'},
          ],
        ),
      );
      expect(find.text('Markhiya'), findsOneWidget);
      expect(_picker, findsNothing);
    });

    testWidgets('an older server that does not list branches: no picker either', (tester) async {
      await _pump(tester, FakeOps());
      expect(_picker, findsNothing);
    });

    testWidgets('a manager of several has a chip in the header; it lists the branches with the one on screen ticked', (
      tester,
    ) async {
      await _pump(tester, _repo());
      expect(_picker, findsOneWidget);
      await tester.tap(_picker);
      await settle(tester);
      expect(find.text('Choose a branch'), findsOneWidget);
      expect(find.text('Wakra'), findsOneWidget);
      expect(
        find.descendant(of: find.byKey(const ValueKey('branch-b1')), matching: find.byIcon(Icons.check_rounded)),
        findsOneWidget,
      );
      expect(
        find.descendant(of: find.byKey(const ValueKey('branch-b2')), matching: find.byIcon(Icons.check_rounded)),
        findsNothing,
      );
    });

    testWidgets('the chip says its meaning to a screen reader', (tester) async {
      final handle = tester.ensureSemantics();
      await _pump(tester, _repo());
      expect(find.bySemanticsLabel('Branch: Markhiya. Tap to change.'), findsOneWidget);
      handle.dispose();
    });
  });

  group('choosing a branch', () {
    testWidgets('every read after it names the branch: counts, orders, the needs-attention list and the drivers', (
      tester,
    ) async {
      final repo = _repo();
      await _pump(tester, repo);
      expect(repo.queueBranches.toSet(), {null}, reason: 'before a choice the server picks the first branch');
      await tester.tap(_picker);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('branch-b2')));
      await settle(tester);
      await settle(tester);
      expect(repo.overviewBranches.last, 'b2');
      expect(repo.queueBranches.last, 'b2');
      expect(repo.attentionBranches.last, 'b2');
      expect(find.text('Wakra'), findsOneWidget);
      await tester.tap(find.byType(Tab).at(2));
      await settle(tester);
      expect(repo.driversBranches.last, 'b2');
    });

    testWidgets('nothing of the branch that was left stays on screen while the new one loads', (tester) async {
      final repo = _repo();
      await _pump(tester, repo);
      expect(find.text('#AB100001'), findsOneWidget);
      repo.queues = {
        'new': [staffOrder(id: 'w1', number: 'AB300001')],
      };
      await tester.tap(_picker);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('branch-b2')));
      await tester.pump();
      expect(find.text('#AB100001'), findsNothing);
      await settle(tester);
      await settle(tester);
      expect(find.text('#AB300001'), findsOneWidget);
    });

    testWidgets('what the person may do follows the branch: a manager here, not there', (tester) async {
      final repo = _repo();
      repo.queues = {
        'new': [staffOrder(id: 'f1', number: 'AB100001', cancellation: cancellationJson())],
      };
      await _pump(tester, repo);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(find.byKey(const ValueKey('cancellation-approve')), findsOneWidget);
      await tester.binding.handlePopRoute();
      await settle(tester);
      await tester.tap(_picker);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('branch-b2')));
      await settle(tester);
      await settle(tester);
      await tester.tap(find.text('#AB100001'));
      await settle(tester);
      expect(find.byKey(const ValueKey('cancellation-approve')), findsNothing);
      expect(find.text('Ask a manager to decide.'), findsWidgets);
    });

    testWidgets('the choice is remembered on the phone and used at the next start', (tester) async {
      final prefs = await memoryPrefs();
      final repo = _repo();
      final container = await _pump(tester, repo, overrides: [prefs]);
      await tester.tap(_picker);
      await settle(tester);
      await tester.tap(find.byKey(const ValueKey('branch-b2')));
      await settle(tester);
      expect(container.read(staffBranchChoiceProvider), 'b2');
      expect((await SharedPreferences.getInstance()).getString('staff_branch_id'), 'b2');

      await tester.pumpWidget(const SizedBox.shrink());
      final again = _repo();
      final prefs2 = sharedPreferencesProvider.overrideWithValue(await SharedPreferences.getInstance());
      await _pump(tester, again, overrides: [prefs2]);
      await settle(tester);
      expect(again.overviewBranches.first, 'b2');
      expect(find.text('Wakra'), findsOneWidget);
    });

    testWidgets('a saved choice the person no longer has is dropped and the first branch is shown', (tester) async {
      final prefs = await memoryPrefs({'staff_branch_id': 'b9'});
      final repo = _repo()..overviewFailForBranch.add('b9');
      final container = await _pump(tester, repo, overrides: [prefs]);
      await settle(tester);
      expect(container.read(staffBranchChoiceProvider), isNull);
      expect(repo.overviewBranches, ['b9', null]);
      expect(find.text('Markhiya'), findsOneWidget);
      expect(find.text('#AB100001'), findsOneWidget);
    });

    testWidgets('a saved choice that is not among the branches the server lists is dropped too', (tester) async {
      final prefs = await memoryPrefs({'staff_branch_id': 'b9'});
      final repo = _repo();
      repo.overviewByBranch['b9'] = staffOverview(id: 'b9', name: 'Gone', branches: _two);
      final container = await _pump(tester, repo, overrides: [prefs]);
      await settle(tester);
      expect(container.read(staffBranchChoiceProvider), isNull);
      expect(find.text('Markhiya'), findsOneWidget);
    });

    testWidgets('a branch that cannot be read says so with a way to try again (not a blank screen)', (tester) async {
      final repo = _repo()..overviewError = apiError(ApiErrorKind.offline, 0);
      final prefs = await memoryPrefs({'staff_branch_id': 'b2'});
      await _pump(tester, repo, overrides: [prefs]);
      expect(find.text('Try again'), findsOneWidget);
    });
  });
}
