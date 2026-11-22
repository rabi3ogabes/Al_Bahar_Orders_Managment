import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/ops/driver/driver_home.dart';
import 'package:albahar_customer/ops/driver/driver_models.dart';
import 'package:albahar_customer/ops/driver/driver_strings.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_status_style.dart';
import 'package:albahar_customer/ops/ops_strings.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

/// "Assembly done" on the driver's job card: one tap for done, "not done" with a reason, a note and a photo, and a delivery that cannot be confirmed
/// before the answer (docs/guides/DELIVERY_FOLLOW_UPS.md).

const _phone = Variant('360x800 en', Size(360, 800), kEn);
const _arabic = Variant('360x800 ar', Size(360, 800), kAr);

Map<String, dynamic> _assembly({String status = 'pending', bool required = true, String? reason, String? note}) => {
  'ordered': true,
  'status': status,
  'required': required,
  'reason': reason,
  'reason_label': reason,
  'note': note,
};

DriverJob _job({String status = 'out_for_delivery', Map<String, dynamic>? assembly}) =>
    FakeOps.job(status: status, cod: '0', extra: {'assembly_ordered': true, 'assembly': ?assembly});

Future<void> _open(WidgetTester tester, FakeOps repo, {Variant variant = _phone, bool slide = false}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(
    opsHost(
      const DriverHome(),
      repo,
      variant: variant,
      overrides: [if (slide) opsStatusStyleProvider.overrideWith(() => _Slide())],
    ),
  );
  await settle(tester);
  await tester.tap(find.text('#AB100008').first);
  await settle(tester);
}

class _Slide extends OpsStatusStyleController {
  @override
  OpsStatusStyle build() => OpsStatusStyle.slide;
}

Future<void> _tap(WidgetTester tester, String key) async {
  final f = find.byKey(ValueKey(key)).last;
  await tester.ensureVisible(f);
  await tester.pump();
  await tester.tap(f);
  await settle(tester);
}

void main() {
  group('reading the answer', () {
    test('pending, done and not done, and whether the delivery must wait', () {
      final pending = DriverAssembly.maybe(_assembly())!;
      expect(pending.needsAnswer, isTrue);
      expect(DriverAssembly.maybe(_assembly(required: false))!.needsAnswer, isFalse);
      expect(DriverAssembly.maybe(_assembly(status: 'done'))!.isDone, isTrue);
      expect(DriverAssembly.maybe(_assembly(status: 'not_done', reason: 'no_time'))!.isNotDone, isTrue);
      expect(DriverAssembly.maybe(null), isNull);
      expect(_job(assembly: _assembly()).context.assembly, isNotNull);
      expect(FakeOps.job().context.assembly, isNull, reason: 'an older server, or nothing bought');
    });

    test('every reason and every sentence has words in both languages', () {
      for (final t in const [OpsStrings('en'), OpsStrings('ar')]) {
        for (final code in ['customer_not_ready', 'missing_parts', 'not_possible', 'no_time', 'other']) {
          expect(t.assemblyReason(code), isNotEmpty);
        }
        expect(t.assemblyDoneVerb, isNotEmpty);
        expect(t.assemblyAnswerFirst, isNotEmpty);
      }
      expect(const OpsStrings('en').assemblyReason('no_time'), isNot(const OpsStrings('ar').assemblyReason('no_time')));
    });
  });

  group('the strip on the job card', () {
    testWidgets('shows for a parcel with paid assembly that is with the driver, with a one-tap "Assembly done"', (
      tester,
    ) async {
      await _open(tester, FakeOps()..jobList = [_job(assembly: _assembly())]);
      expect(find.byKey(const ValueKey('assembly-strip')), findsWidgets);
      expect(find.byKey(const ValueKey('assembly-done')), findsWidgets);
      expect(find.text('Assembly and installation'), findsWidgets);
    });

    testWidgets('is not there before the parcel is picked up, or when nothing was bought', (tester) async {
      await _open(tester, FakeOps()..jobList = [_job(status: 'accepted', assembly: _assembly())]);
      expect(find.byKey(const ValueKey('assembly-strip')), findsNothing);
    });

    testWidgets('is not there when nothing was bought', (tester) async {
      await _open(tester, FakeOps()..jobList = [FakeOps.job(status: 'out_for_delivery', cod: '0')]);
      expect(find.byKey(const ValueKey('assembly-strip')), findsNothing);
    });

    testWidgets('one tap records "done" with a key, and says the branch knows', (tester) async {
      final repo = FakeOps()..jobList = [_job(assembly: _assembly())];
      await _open(tester, repo);
      await _tap(tester, 'assembly-done');
      expect(repo.assemblies, hasLength(1));
      expect(repo.assemblies.single.status, 'done');
      expect(repo.assemblies.single.key, isNotEmpty);
      expect(find.text('Saved. The branch knows.'), findsOneWidget);
    });

    testWidgets('the slide does the same when the dashboard asks for the slide', (tester) async {
      final repo = FakeOps()..jobList = [_job(assembly: _assembly())];
      await _open(tester, repo, slide: true);
      expect(find.byKey(const ValueKey('assembly-slide')), findsWidgets);
      expect(find.byKey(const ValueKey('assembly-done')), findsNothing);
    });

    testWidgets('"Not done" asks why: a reason is needed, "another reason" needs words, then it is saved', (
      tester,
    ) async {
      final repo = FakeOps()..jobList = [_job(assembly: _assembly())];
      await _open(tester, repo);
      await _tap(tester, 'assembly-not-done');
      expect(find.text('Why was it not done?'), findsOneWidget);
      await tester.tap(find.text('Save answer'));
      await settle(tester);
      expect(find.text('Choose done, or why it was not done.'), findsOneWidget);
      expect(repo.assemblies, isEmpty);

      await _tap(tester, 'assembly-reason-other');
      await tester.tap(find.text('Save answer'));
      await settle(tester);
      expect(repo.assemblies, isEmpty, reason: 'another reason needs a few words');

      await tester.enterText(find.byType(TextField).last, 'The wall cannot take the shelf');
      await tester.tap(find.text('Save answer'));
      await settle(tester);
      expect(repo.assemblies.single.status, 'not_done');
      expect(repo.assemblies.single.reason, 'other');
      expect(repo.assemblies.single.note, 'The wall cannot take the shelf');
    });

    testWidgets('the note and photo window can save "done" with a note', (tester) async {
      final repo = FakeOps()..jobList = [_job(assembly: _assembly())];
      await _open(tester, repo);
      await _tap(tester, 'assembly-details');
      await _tap(tester, 'assembly-choice-done');
      await tester.enterText(find.byType(TextField).last, 'Bed and wardrobe');
      await tester.tap(find.text('Save answer'));
      await settle(tester);
      expect(repo.assemblies.single.status, 'done');
      expect(repo.assemblies.single.note, 'Bed and wardrobe');
    });

    testWidgets('a refusal from the server is put in plain words and the window stays', (tester) async {
      final repo = FakeOps()
        ..jobList = [_job(assembly: _assembly())]
        ..assemblyError = apiError(ApiErrorKind.validation, 422, code: 'assembly_reason_required');
      await _open(tester, repo);
      await _tap(tester, 'assembly-not-done');
      await _tap(tester, 'assembly-reason-no_time');
      await tester.tap(find.text('Save answer'));
      await settle(tester);
      expect(find.text('Choose done, or why it was not done.'), findsOneWidget);
    });

    testWidgets('an answered strip says what was answered, with a way to change it', (tester) async {
      await _open(tester, FakeOps()..jobList = [_job(assembly: _assembly(status: 'done'))]);
      expect(find.text('Assembly done'), findsWidgets);
      expect(find.byKey(const ValueKey('assembly-change')), findsWidgets);
      expect(find.byKey(const ValueKey('assembly-done')), findsNothing);
    });

    testWidgets('a "not done" answer says why', (tester) async {
      await _open(
        tester,
        FakeOps()
          ..jobList = [
            _job(
              assembly: _assembly(status: 'not_done', reason: 'no_time'),
            ),
          ],
      );
      expect(find.textContaining('Assembly not done'), findsWidgets);
    });
  });

  group('the delivery waits for the answer', () {
    testWidgets('"Mark delivered" opens the answer window first when it is required, and delivers nothing yet', (
      tester,
    ) async {
      final repo = FakeOps()..jobList = [_job(assembly: _assembly())];
      await _open(tester, repo);
      await _tap(tester, 'skip-arrival');
      expect(find.text('Was the assembly done?'), findsOneWidget);
      expect(repo.delivered, isEmpty);
    });

    testWidgets('when the owner switched the rule off, "Mark delivered" goes straight to the delivery form', (
      tester,
    ) async {
      final repo = FakeOps()..jobList = [_job(assembly: _assembly(required: false))];
      await _open(tester, repo);
      await _tap(tester, 'skip-arrival');
      expect(find.text('Was the assembly done?'), findsNothing);
      expect(find.text('Confirm delivery'), findsWidgets);
    });
  });

  group('in Arabic', () {
    testWidgets('the strip and the reasons are Arabic', (tester) async {
      await _open(tester, FakeOps()..jobList = [_job(assembly: _assembly())], variant: _arabic);
      expect(find.text('تم التركيب'), findsWidgets);
      await _tap(tester, 'assembly-not-done');
      expect(find.text('لماذا لم يتم؟'), findsOneWidget);
      expect(find.text('كانت قطع أو أدوات ناقصة'), findsOneWidget);
    });
  });
}
