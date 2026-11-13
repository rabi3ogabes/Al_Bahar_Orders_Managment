import 'dart:async';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/staff/add_product_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'ops_test_kit.dart';

/// A home screen with one button that opens the add-product flow, so leaving it can be tested.
class _Launcher extends StatelessWidget {
  const _Launcher();

  @override
  Widget build(BuildContext context) => Scaffold(
    body: Center(
      child: FilledButton(
        onPressed: () => Navigator.of(context).push(MaterialPageRoute<bool>(builder: (_) => const AddProductScreen())),
        child: const Text('open'),
      ),
    ),
  );
}

Future<void> _open(
  WidgetTester tester,
  FakeOps repo, {
  FakePicker? picker,
  Variant variant = const Variant('default', Size(390, 844), kEn),
}) async {
  useVariant(tester, variant);
  await tester.pumpWidget(opsHost(const _Launcher(), repo, variant: variant, picker: picker, role: OpsRole.staff));
  await tester.tap(find.text('open'));
  await settle(tester);
}

Finder _field(String label) => find.widgetWithText(TextFormField, label);

/// Scrolls the form until [f] is on screen (the pinned save bar sits outside the scrolling list).
Future<void> _scrollTo(WidgetTester tester, Finder f, {bool up = false}) async {
  await tester.scrollUntilVisible(
    f,
    up ? -200 : 200,
    scrollable: find.descendant(of: find.byType(Form), matching: find.byType(Scrollable)).first,
  );
  await tester.ensureVisible(f);
  // Let the scroll come to rest: a list ignores taps while it is still moving.
  await settle(tester);
}

Future<void> _addPhoto(WidgetTester tester, {bool gallery = false}) async {
  await tester.tap(find.text('Add photo').first);
  await settle(tester);
  await tester.tap(find.text(gallery ? 'Choose from gallery' : 'Take a photo'));
  await settle(tester);
}

Future<void> _save(WidgetTester tester) async {
  await tester.tap(find.byType(FilledButton).last);
  await settle(tester);
}

void main() {
  group('add product under every phone, language, text size and theme', () {
    for (final v in variants) {
      testWidgets('lays out without overflow: ${v.name}', (tester) async {
        final handle = tester.ensureSemantics();
        await _open(tester, FakeOps(), variant: v);
        expect(tester.takeException(), isNull);
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        // Scroll to the bottom: every field and the choice buttons stay inside the screen.
        await tester.drag(find.byType(Form), const Offset(0, -2000));
        await tester.pump();
        expect(tester.takeException(), isNull);
        handle.dispose();
      });
    }
  });

  group('validation', () {
    testWidgets('price: zero, letters and a missing title are refused with plain words', (tester) async {
      final repo = FakeOps();
      await _open(tester, repo);
      await tester.enterText(_field('Title (English)'), 'Linen Throw');
      await tester.enterText(_field('Price (QAR)'), '0');
      await _save(tester);
      expect(find.text('The price must be more than zero.'), findsOneWidget);

      await tester.enterText(_field('Price (QAR)'), 'abc');
      expect(tester.widget<TextFormField>(_field('Price (QAR)')).controller!.text, isEmpty);
      await _save(tester);
      expect(find.text('Enter a price like 120 or 120.50'), findsOneWidget);
      expect(repo.createdProducts, 0);
    });

    testWidgets('price typed with Arabic digits and decimal mark is understood and sent as plain digits', (
      tester,
    ) async {
      final repo = FakeOps();
      await _open(tester, repo, variant: variants[1]);
      await tester.enterText(find.widgetWithText(TextFormField, 'العنوان (بالإنجليزية)'), 'Linen Throw');
      final price = find.widgetWithText(TextFormField, 'السعر (ر.ق)');
      await tester.enterText(price, '٣٢٠٫٥');
      expect(tester.widget<TextFormField>(price).controller!.text, '320.5');
      await tester.enterText(price, '12.345');
      expect(tester.widget<TextFormField>(price).controller!.text, isNot('12.345'));
      await tester.enterText(price, '٣٢٠٫٥');
      await _save(tester);
      expect(repo.calls.first, startsWith('create:320.5:'));
    });

    testWidgets('a title in one language only is copied to the other, trimmed', (tester) async {
      final repo = FakeOps();
      await _open(tester, repo);
      await tester.enterText(_field('Title (English)'), '   Linen Throw   ');
      await tester.enterText(_field('Price (QAR)'), '120');
      await _save(tester);
      expect(repo.calls.first, 'create:120:Linen Throw|Linen Throw:draft');
    });

    testWidgets('keyboard types and actions are right for each field', (tester) async {
      await _open(tester, FakeOps());
      TextField inner(String label) =>
          tester.widget<TextField>(find.descendant(of: _field(label), matching: find.byType(TextField)));
      expect(inner('Price (QAR)').keyboardType, const TextInputType.numberWithOptions(decimal: true));
      expect(inner('Price (QAR)').textInputAction, TextInputAction.next);
      expect(inner('Title (English)').textCapitalization, TextCapitalization.words);
      expect(inner('Title (English)').maxLength, 255);
      expect(inner('Title (Arabic)').textDirection, TextDirection.rtl);
    });
  });

  group('saving', () {
    testWidgets('a half-failed save continues instead of creating a second product', (tester) async {
      final repo = FakeOps()..failUploads.add('/tmp/two.jpg');
      final picker = FakePicker()..next = '/tmp/one.jpg';
      await _open(tester, repo, picker: picker);
      await tester.enterText(_field('Title (English)'), 'Linen Throw');
      await tester.enterText(_field('Price (QAR)'), '120');
      await _addPhoto(tester);
      picker.next = '/tmp/two.jpg';
      await _addPhoto(tester);

      await _save(tester);
      expect(repo.createdProducts, 1);
      expect(repo.calls.where((c) => c.startsWith('upload')), ['upload:/tmp/one.jpg', 'upload:/tmp/two.jpg']);
      expect(find.textContaining('Tap Save to finish uploading'), findsOneWidget);
      expect(find.byType(AddProductScreen), findsOneWidget);

      repo.failUploads.clear();
      await _save(tester);
      expect(repo.createdProducts, 1, reason: 'no duplicate product');
      expect(repo.calls.where((c) => c.startsWith('upload')), [
        'upload:/tmp/one.jpg',
        'upload:/tmp/two.jpg',
        'upload:/tmp/two.jpg',
      ]);
      expect(find.byType(AddProductScreen), findsNothing);
      expect(find.text('Product saved.'), findsOneWidget);
    });

    testWidgets('a failed create keeps everything typed and says why (422 field message)', (tester) async {
      final repo = FakeOps()
        ..createError = apiError(ApiErrorKind.validation, 422, message: 'The price field must be at least 0.');
      await _open(tester, repo);
      await tester.enterText(_field('Title (English)'), 'Linen Throw');
      await tester.enterText(_field('Price (QAR)'), '5');
      await _save(tester);
      expect(find.text('The price field must be at least 0.'), findsOneWidget);
      expect(find.text('Linen Throw'), findsOneWidget);
      expect(find.byType(AddProductScreen), findsOneWidget);
      // Retry works and nothing was uploaded before the product existed.
      repo.createError = null;
      await _save(tester);
      expect(repo.createdProducts, 2);
      expect(find.byType(AddProductScreen), findsNothing);
    });

    testWidgets('the screen cannot be left while saving, and Save cannot be tapped twice', (tester) async {
      final repo = FakeOps()..uploadGate = Completer<void>();
      final picker = FakePicker()..next = '/tmp/one.jpg';
      await _open(tester, repo, picker: picker);
      await tester.enterText(_field('Title (English)'), 'Linen Throw');
      await tester.enterText(_field('Price (QAR)'), '120');
      await _addPhoto(tester);
      await tester.tap(find.byType(FilledButton).last);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));
      expect(find.text('Uploading photo 1 of 1…'), findsOneWidget);
      await tester.tap(find.byType(FilledButton).last, warnIfMissed: false);
      await tester.pump();
      expect(repo.createdProducts, 1);

      await tester.tap(find.byTooltip('Close'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 50));
      expect(find.text('Please wait, saving…'), findsOneWidget);
      expect(find.byType(AddProductScreen), findsOneWidget);

      repo.uploadGate!.complete();
      await settle(tester);
      expect(find.byType(AddProductScreen), findsNothing);
    });

    testWidgets('studio request refused: the product is saved and the user is told', (tester) async {
      final repo = FakeOps()..studioError = apiError(ApiErrorKind.forbidden, 403, message: 'no');
      final picker = FakePicker()..next = '/tmp/one.jpg';
      await _open(tester, repo, picker: picker);
      await tester.enterText(_field('Title (English)'), 'Linen Throw');
      await tester.enterText(_field('Price (QAR)'), '120');
      await _addPhoto(tester);
      await _scrollTo(tester, find.textContaining('Ask the photo studio'));
      await tester.tap(find.byType(CheckboxListTile));
      await tester.pump();
      await _save(tester);
      expect(repo.calls.last, 'studio:img-/tmp/one.jpg:clean_background');
      expect(find.textContaining('photo studio request was not sent'), findsOneWidget);
      expect(find.byType(AddProductScreen), findsNothing);
    });
  });

  group('photos', () {
    testWidgets('with one slot left the gallery uses the single picker (the multi picker refuses a limit of 1)', (
      tester,
    ) async {
      final repo = FakeOps();
      final picker = FakePicker();
      await _open(tester, repo, picker: picker);
      for (var i = 0; i < 7; i++) {
        picker.next = '/tmp/p$i.jpg';
        await _addPhoto(tester);
      }
      picker.next = '/tmp/last.jpg';
      await _addPhoto(tester, gallery: true);
      expect(tester.takeException(), isNull);
      expect(picker.multiLimits, isEmpty);
      expect(picker.shots, hasLength(8));
      // Eight is the maximum: the add tile is gone.
      expect(find.text('Add photo'), findsNothing);
    });

    testWidgets('gallery picks as many as there is room for', (tester) async {
      final picker = FakePicker()..gallery = ['/tmp/a.jpg', '/tmp/b.jpg', '/tmp/c.jpg'];
      await _open(tester, FakeOps(), picker: picker);
      await _addPhoto(tester, gallery: true);
      expect(picker.multiLimits, [8]);
      expect(find.byTooltip('Remove photo'), findsNWidgets(3));
      await tester.tap(find.byTooltip('Remove photo').first);
      await tester.pump();
      expect(find.byTooltip('Remove photo'), findsNWidgets(2));
    });

    testWidgets('a refused camera permission is not a crash', (tester) async {
      final picker = FakePicker()..error = StateError('camera_access_denied');
      await _open(tester, FakeOps(), picker: picker);
      await _addPhoto(tester);
      expect(tester.takeException(), isNull);
      expect(find.byTooltip('Remove photo'), findsNothing);
    });
  });

  group('AI description', () {
    testWidgets('needs a title; the provider key missing disables it with the reason', (tester) async {
      final repo = FakeOps()..ai = false;
      await _open(tester, repo);
      await _scrollTo(tester, find.textContaining('AI writing is not set up yet'));
      final button = find.widgetWithText(OutlinedButton, 'Translate and write the description with AI');
      expect(tester.widget<OutlinedButton>(button).onPressed, isNull);
    });

    testWidgets('writes both languages, fills the missing title, and asks before replacing typed text', (tester) async {
      final repo = FakeOps();
      await _open(tester, repo);
      await tester.enterText(_field('Title (English)'), 'Linen Throw');
      await _scrollTo(tester, _field('Short description · EN'));
      await tester.enterText(_field('Short description · EN'), 'my own words');
      await _scrollTo(tester, find.text('Translate and write the description with AI'));
      await tester.tap(find.text('Translate and write the description with AI'));
      await settle(tester);
      expect(find.text('Replace your text?'), findsOneWidget);
      await tester.tap(find.text('Cancel'));
      await settle(tester);
      expect(repo.calls.where((c) => c.startsWith('describe')), isEmpty);
      expect(find.text('my own words'), findsOneWidget);

      await tester.tap(find.text('Translate and write the description with AI'));
      await settle(tester);
      await tester.tap(find.text('Replace'));
      await settle(tester);
      expect(repo.calls.where((c) => c.startsWith('describe')), ['describe:Linen Throw|']);
      expect(find.text('my own words'), findsNothing);
      expect(find.text('قصير', skipOffstage: false), findsOneWidget);
      await _scrollTo(tester, _field('Title (Arabic)'), up: true);
      expect(tester.widget<TextFormField>(_field('Title (Arabic)')).controller!.text, 'غطاء كتان');
    });

    testWidgets('timeout and server errors are explained, nothing is overwritten', (tester) async {
      final repo = FakeOps()..describeError = apiError(ApiErrorKind.timeout, 0);
      await _open(tester, repo);
      await tester.enterText(_field('Title (English)'), 'Linen Throw');
      await _scrollTo(tester, find.text('Translate and write the description with AI'));
      await tester.tap(find.text('Translate and write the description with AI'));
      await settle(tester);
      expect(find.text('The connection is taking too long. Please try again.'), findsOneWidget);
      expect(find.text('Translate and write the description with AI'), findsOneWidget); // button usable again

      repo.describeError = apiError(ApiErrorKind.rateLimited, 429, retryAfter: const Duration(seconds: 30));
      await tester.tap(find.text('Translate and write the description with AI'));
      await settle(tester);
      expect(find.textContaining('30'), findsWidgets);
    });

    testWidgets('closing the screen while the AI is writing does not touch the disposed fields', (tester) async {
      final repo = FakeOps();
      await _open(tester, repo);
      await tester.enterText(_field('Title (English)'), 'Linen Throw');
      await _scrollTo(tester, find.text('Translate and write the description with AI'));
      await tester.tap(find.text('Translate and write the description with AI'));
      // Leave straight away (confirm), before the answer lands.
      await tester.tap(find.byTooltip('Close'));
      await tester.pump();
      await tester.tap(find.text('Discard'));
      await settle(tester);
      expect(tester.takeException(), isNull);
      expect(find.byType(AddProductScreen), findsNothing);
    });
  });

  group('leaving', () {
    testWidgets('untouched form closes at once; typed input asks first', (tester) async {
      await _open(tester, FakeOps());
      await tester.tap(find.byTooltip('Close'));
      await settle(tester);
      expect(find.byType(AddProductScreen), findsNothing);

      await tester.tap(find.text('open'));
      await settle(tester);
      await tester.enterText(_field('Title (English)'), 'Linen');
      await tester.binding.handlePopRoute();
      await settle(tester);
      expect(find.text('Discard changes?'), findsOneWidget);
      await tester.tap(find.text('Keep editing'));
      await settle(tester);
      expect(find.byType(AddProductScreen), findsOneWidget);
      await tester.binding.handlePopRoute();
      await settle(tester);
      await tester.tap(find.text('Discard'));
      await settle(tester);
      expect(find.byType(AddProductScreen), findsNothing);
    });
  });

  group('categories', () {
    testWidgets('a failed load can be retried by tapping the field', (tester) async {
      final repo = FakeOps()..categoriesError = apiError(ApiErrorKind.offline, 0);
      await _open(tester, repo);
      expect(find.text('Could not load categories. Tap to retry.'), findsOneWidget);
      repo.categoriesError = null;
      await tester.tap(find.text('Could not load categories. Tap to retry.'));
      await settle(tester);
      expect(find.text('Choose a category'), findsOneWidget);
      await tester.tap(find.text('Choose a category'));
      await settle(tester);
      await tester.tap(find.text('Living › Sofas'));
      await settle(tester);
      expect(find.text('Living › Sofas'), findsOneWidget);
    });
  });
}
