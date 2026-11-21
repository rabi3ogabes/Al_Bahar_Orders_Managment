@Timeout(Duration(minutes: 5))
library;

// Return and damage claims in the customer app: the claim read from the server, its timeline (every step, both languages, large text), the
// form (a damaged item needs a photo, the photos and the submit key go with the claim), adding photos or a note while it waits, cancelling,
// and "You can report a problem until <date>".

import 'dart:typed_data';

import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/router/app_router.dart';
import 'package:albahar_customer/core/widgets/app_button.dart';
import 'package:albahar_customer/features/orders/domain/order_models.dart';
import 'package:albahar_customer/features/orders/presentation/return_errors.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/claim_parts.dart';
import 'package:albahar_customer/features/orders/presentation/widgets/claim_photo_source.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';

import '../../helpers/fakes.dart';
import '../../qa/customer_fixtures.dart';
import '../../qa/side_windows_test.dart' show ar, backend, boot, en, settle, teardown;
import '../../qa/test_fonts.dart';

final _png = Uint8List.fromList(const [
  0x89,
  0x50,
  0x4E,
  0x47,
  0x0D,
  0x0A,
  0x1A,
  0x0A,
  0x00,
  0x00,
  0x00,
  0x0D,
  0x49,
  0x48,
  0x44,
  0x52,
  0x00,
  0x00,
  0x00,
  0x01,
  0x00,
  0x00,
  0x00,
  0x01,
  0x08,
  0x06,
  0x00,
  0x00,
  0x00,
  0x1F,
  0x15,
  0xC4,
  0x89,
  0x00,
  0x00,
  0x00,
  0x0D,
  0x49,
  0x44,
  0x41,
  0x54,
  0x78,
  0x9C,
  0x63,
  0xF8,
  0xFF,
  0xFF,
  0x3F,
  0x00,
  0x05,
  0xFE,
  0x02,
  0xFE,
  0xA7,
  0x35,
  0x81,
  0x84,
  0x00,
  0x00,
  0x00,
  0x00,
  0x49,
  0x45,
  0x4E,
  0x44,
  0xAE,
  0x42,
  0x60,
  0x82,
]);

/// The phone's camera and gallery, replaced by canned pictures.
class _FakeSource extends ClaimPhotoSource {
  _FakeSource();
  int taken = 0;

  @override
  Future<List<PickedPhoto>> camera() async => [
    PickedPhoto(name: 'damage-${++taken}.png', bytes: _png, mimeType: 'image/png'),
  ];

  @override
  Future<List<PickedPhoto>> gallery(int limit) async => [
    for (var i = 0; i < limit.clamp(0, 2); i++)
      PickedPhoto(name: 'gallery-${++taken}.png', bytes: _png, mimeType: 'image/png'),
  ];
}

Map<String, dynamic> _claim(
  String status, {
  List<Map<String, dynamic>>? timeline,
  List<Map<String, dynamic>> photos = const [],
  bool editable = false,
  String? method,
  Map<String, dynamic>? refund,
  String? note,
  String? decisionNote,
}) => {
  'id': 'rr1',
  'number': 'RT26001',
  'status': status,
  'status_label': status,
  'kind': 'damaged',
  'requested_at': '2026-11-03T10:00:00+03:00',
  'decided_at': const ['requested', 'under_review', 'cancelled'].contains(status) ? null : '2026-11-04T09:00:00+03:00',
  'decision_note': decisionNote,
  'note': note,
  'items': [
    {'order_item_id': 'i1', 'quantity': 1, 'name': 'Oslo Sofa'},
  ],
  'photos': photos,
  'messages': <Map<String, dynamic>>[],
  'can_add_evidence': editable,
  'can_cancel': editable,
  'method': method,
  'method_note': method == 'pickup' ? 'Tomorrow 4 to 6 pm' : null,
  'branch': {'id': 'br1', 'name': 'West Bay', 'phone': '+97444000001'},
  'refund': refund,
  'timeline': timeline ?? const [],
};

Map<String, dynamic> _step(String key, String state, {String? at, String? detail}) => {
  'key': key,
  'state': state,
  'at': at,
  'detail': detail,
};

Map<String, dynamic> _order({
  Map<String, dynamic>? claim,
  bool canReturn = true,
  int returnable = 2,
  String? until = '2026-11-18T09:00:00+03:00',
}) => {
  ...orderDetail('o1', status: 'completed'),
  'items': [
    {
      'id': 'i1',
      'product_id': 'p1',
      'variant_id': 'v1',
      'sku': 'SKU-1',
      'name': 'Oslo Sofa',
      'quantity': 2,
      'unit_price': money('100.00'),
      'line_total': money('200.00'),
      'fulfillment_id': 'f1',
      'returnable_quantity': returnable,
    },
  ],
  'fulfillments': [
    {
      'id': 'f1',
      'number': 'AB-1',
      'branch': {'id': 'br1', 'name': 'West Bay', 'phone': '+97444000001'},
      'type': 'delivery',
      'status': 'delivered',
      'status_label': 'delivered',
    },
  ],
  'can_return': canReturn,
  'can_review': false,
  'can_cancel': false,
  'return_request': ?claim,
  'returns': [?claim],
  'return_window': {'days': 14, 'until': until},
};

class _Shop {
  _Shop(this.order);
  Map<String, dynamic> order;
  final posts = <RecordedRequest>[];
  FakeReply Function(RecordedRequest)? reply;

  FakeHandler get handler => backend(
    extra: (r) {
      if (r.method == 'GET' && r.path == '/orders/o1') return ok(order);
      if (r.method == 'GET' && r.path == '/orders/o1/timeline') return ok(const []);
      if (r.method == 'GET' && r.path.startsWith('/returns/rr1/photos/')) {
        return FakeReply(200, _png, const {
          'content-type': ['image/png'],
        });
      }
      if (r.method == 'POST' && (r.path == '/orders/o1/returns' || r.path.startsWith('/returns/rr1/'))) {
        posts.add(r);
        return reply?.call(r) ?? ok(_claim('requested'), status: r.path == '/orders/o1/returns' ? 201 : 200);
      }
      return null;
    },
  );
}

Future<TestHarness> _open(
  WidgetTester t,
  _Shop shop, {
  String locale = 'en',
  _FakeSource? source,
  double scale = 1,
}) async {
  if (scale != 1) {
    t.platformDispatcher.textScaleFactorTestValue = scale;
    addTearDown(t.platformDispatcher.clearTextScaleFactorTestValue);
  }
  final h = await boot(
    t,
    shop.handler,
    locale: locale,
    height: 1900,
    overrides: [claimPhotoSourceProvider.overrideWithValue(source ?? _FakeSource())],
  );
  h.read(routerProvider).go('/orders/o1');
  await settle(t, 10);
  return h;
}

Finder _button(String label) => find.widgetWithText(AppButton, label);

Future<void> _tapKey(WidgetTester t, String key) async {
  final f = find.byKey(ValueKey(key));
  await t.ensureVisible(f);
  await t.pump();
  await t.tap(f);
  await settle(t);
}

Future<void> _openForm(WidgetTester t) async {
  final target = _button(en.requestReturn);
  await t.ensureVisible(target);
  await t.pump();
  await t.tap(target);
  await settle(t);
}

Map<String, String> _fields(RecordedRequest r) => {for (final e in (r.body as FormData).fields) e.key: e.value};

void main() {
  setUpAll(() async {
    await loadAppFonts();
    await initializeDateFormatting('en');
    await initializeDateFormatting('ar');
  });

  group('reading a claim', () {
    test('the steps, the photos, the way back and the refund are read; a claim from an older server still reads', () {
      final c = ReturnRequestInfo.maybe(
        _claim(
          'approved',
          method: 'pickup',
          note: '  Cracked leg  ',
          photos: [
            {'id': 'p1', 'path': 'returns/rr1/photos/p1'},
          ],
          refund: {'status': 'requested', 'amount': money('150.00'), 'method': 'manual_cash'},
          timeline: [
            _step('received', 'done'),
            _step('approved', 'done', detail: 'Sorry'),
            _step('pickup', 'done'),
            _step('refunded', 'current'),
          ],
        ),
      )!;
      expect(c.steps.map((s) => s.key), ['received', 'approved', 'pickup', 'refunded']);
      expect(c.steps[1].detail, 'Sorry');
      expect(c.steps.last.isCurrent, isTrue);
      expect(c.photos.single.path, 'returns/rr1/photos/p1');
      expect(c.note, 'Cracked leg');
      expect(c.method, 'pickup');
      expect(c.methodNote, 'Tomorrow 4 to 6 pm');
      expect(c.branchName, 'West Bay');
      expect(c.refund!.amount.formatted, 'QAR 150.00');
      expect(c.refund!.isProcessed, isFalse);
      expect(c.itemsText, '1 × Oslo Sofa');
      expect(c.isOpen, isTrue);
      expect(c.isEditable, isFalse);

      final old = ReturnRequestInfo.maybe({
        'id': 'x',
        'number': 'RT1',
        'status': 'requested',
        'requested_at': '2026-11-03T10:00:00+03:00',
      })!;
      expect(old.steps, isEmpty);
      expect(old.photos, isEmpty);
      expect(old.isEditable, isTrue);

      final o = Order.fromJson(_order(claim: _claim('requested')));
      expect(o.returns.single.number, 'RT26001');
      expect(o.returnRequest!.number, 'RT26001');
      expect(o.returnWindowUntil, isNotNull);
      expect(Order.fromJson(_order(until: null)).returnWindowUntil, isNull);
    });

    test('a damaged or wrong item needs a photo; the pictures are checked before sending', () {
      expect([for (final c in claimTypes) claimNeedsPhoto(c)], [true, true, false, false, false]);
      PickedPhoto p(int bytes, [String? mime = 'image/jpeg']) =>
          PickedPhoto(name: 'a.jpg', bytes: Uint8List(bytes), mimeType: mime);
      expect(photoProblem([p(10), p(10, 'image/png'), p(10, 'image/webp')]), isNull);
      expect(photoProblem([p(10, 'image/svg+xml')]), 'type');
      expect(photoProblem([p(10, 'image/heic')]), 'type');
      expect(photoProblem([p(maxClaimPhotoBytes + 1)]), 'size');
      expect(photoProblem([p(maxClaimPhotoBytes)]), isNull);
      expect(photoProblem([for (var i = 0; i < 5; i++) p(1)]), isNull);
      expect(photoProblem([for (var i = 0; i < 6; i++) p(1)]), 'count');
      expect(photoProblem([p(1), p(1)], already: 4), 'count');
    });

    test('every step, refund way and message has words in both languages', () {
      for (final l10n in [en, ar]) {
        for (final k in [
          'received',
          'under_review',
          'decision',
          'approved',
          'rejected',
          'handover',
          'pickup',
          'branch',
          'item_received',
          'refunded',
          'cancelled',
          'brand_new',
        ]) {
          expect(claimStepLabel(l10n, k), isNotEmpty, reason: k);
        }
        for (final m in ['original', 'manual_cash', 'manual_bank_transfer', 'other']) {
          expect(claimRefundHow(l10n, m), isNotEmpty, reason: m);
        }
      }
      expect(claimStepLabel(en, 'brand_new'), en.claimStepOther);
    });

    test('the new refusals are put in plain words', () {
      ApiException failure(ApiErrorKind k, {Map<String, List<String>> errors = const {}, String? code}) =>
          ApiException(kind: k, errors: errors, code: code);
      expect(
        returnFailureMessage(
          en,
          failure(
            ApiErrorKind.validation,
            errors: {
              'photos': ['x'],
            },
          ),
        ),
        en.claimErrPhotos,
      );
      expect(
        returnFailureMessage(
          en,
          failure(
            ApiErrorKind.validation,
            errors: {
              'photos.0': ['x'],
            },
          ),
        ),
        en.claimErrPhotos,
      );
      expect(
        returnFailureMessage(
          ar,
          failure(
            ApiErrorKind.validation,
            errors: {
              'reason': ['x'],
            },
          ),
        ),
        ar.claimErrNote,
      );
      expect(returnFailureMessage(en, failure(ApiErrorKind.conflict, code: 'claim_not_editable')), en.claimErrLocked);
    });
  });

  group('the timeline', () {
    final steps = [
      _step('received', 'done', at: '2026-11-03T10:00:00+03:00'),
      _step('under_review', 'done', at: '2026-11-03T11:00:00+03:00'),
      _step('approved', 'done', at: '2026-11-04T09:00:00+03:00', detail: 'We are sorry about that.'),
      _step('pickup', 'done', detail: 'Tomorrow 4 to 6 pm'),
      _step('item_received', 'current'),
      _step('refunded', 'upcoming'),
    ];

    testWidgets('shows every step with its state, date and the team\'s words', (t) async {
      await _open(
        t,
        _Shop(
          _order(
            claim: _claim('approved', method: 'pickup', decisionNote: 'We are sorry about that.', timeline: steps),
            canReturn: false,
          ),
        ),
      );
      expect(find.byKey(const ValueKey('claim-timeline')), findsOneWidget);
      for (final s in ['received', 'under_review', 'approved', 'pickup', 'item_received', 'refunded']) {
        expect(find.byKey(ValueKey('claim-step-$s')), findsOneWidget, reason: s);
      }
      expect(find.textContaining(en.claimStepReceived), findsOneWidget);
      expect(find.text('We are sorry about that.'), findsOneWidget);
      expect(find.text('Tomorrow 4 to 6 pm'), findsOneWidget);
      expect(find.textContaining('Nov 3, 2026'), findsWidgets);
      expect(_button(en.requestReturn), findsNothing, reason: 'nothing left to report on this order');
      await teardown(t);
    });

    testWidgets('is in Arabic, mirrored, and survives large text', (t) async {
      await _open(
        t,
        _Shop(
          _order(
            claim: _claim('approved', method: 'pickup', timeline: steps),
            canReturn: false,
          ),
        ),
        locale: 'ar',
        scale: 1.3,
      );
      expect(find.textContaining(ar.claimStepReceived), findsOneWidget);
      expect(find.textContaining(ar.claimStepPickup), findsOneWidget);
      // The dot column is on the reading side: in Arabic the dot is to the right of its text.
      final dot = t.getTopLeft(find.byKey(const ValueKey('claim-step-received')));
      final text = t.getTopLeft(find.textContaining(ar.claimStepReceived));
      expect(dot.dx, greaterThan(0));
      expect(t.takeException(), isNull);
      expect(text.dx, lessThan(t.getTopRight(find.byKey(const ValueKey('claim-step-received'))).dx));
      await teardown(t);
    });

    testWidgets('a rejection ends it with the team\'s reply; the refund step says how much and how', (t) async {
      final rejected = [
        _step('received', 'done'),
        _step('under_review', 'done'),
        _step('rejected', 'stopped', detail: 'A used sofa cannot be taken back.'),
      ];
      await _open(
        t,
        _Shop(
          _order(
            claim: _claim('rejected', timeline: rejected, decisionNote: 'A used sofa cannot be taken back.'),
            canReturn: false,
          ),
        ),
      );
      expect(find.text('A used sofa cannot be taken back.'), findsOneWidget);
      expect(find.text(en.returnStatusRejectedHelp), findsOneWidget);
      await teardown(t);

      final refunded = [_step('received', 'done'), _step('approved', 'done'), _step('refunded', 'current')];
      await _open(
        t,
        _Shop(
          _order(
            claim: _claim(
              'refunded',
              method: 'none',
              refund: {'status': 'requested', 'amount': money('150.00'), 'method': 'original'},
              timeline: refunded,
            ),
            canReturn: false,
          ),
        ),
      );
      expect(find.text(en.claimStepRefundWait('QAR 150.00', en.claimRefundOriginal)), findsOneWidget);
      await teardown(t);
    });
  });

  group('the form', () {
    testWidgets('a damaged item needs a photo before it can be sent; the photos and the submit key go with the claim', (
      t,
    ) async {
      final shop = _Shop(_order());
      final source = _FakeSource();
      await _open(t, shop, source: source);
      await _openForm(t);
      await _tapKey(t, 'claim-type-damaged');
      await t.tap(
        find
            .descendant(of: find.byKey(const ValueKey('return-line-i1')), matching: find.byIcon(Icons.add_rounded))
            .first,
      );
      await t.pump();
      final submit = find.byKey(const ValueKey('claim-submit'));
      expect(t.widget<AppButton>(submit).onPressed, isNull, reason: 'damage cannot be judged without a photo');
      expect(find.text(en.claimPhotosRequired), findsOneWidget);

      await _tapKey(t, 'claim-photo-camera');
      expect(t.widget<AppButton>(submit).onPressed, isNotNull);
      expect(find.byKey(const ValueKey('picked-photo-0')), findsOneWidget);
      await _tapKey(t, 'claim-photo-gallery');
      expect(find.byKey(const ValueKey('picked-photo-2')), findsOneWidget);
      expect(find.text(en.claimPhotoCount(3, 5)), findsOneWidget);

      await t.tap(find.byTooltip(en.claimPhotoRemove).first);
      await t.pump();
      expect(find.byKey(const ValueKey('picked-photo-2')), findsNothing);

      await t.tap(submit);
      await settle(t, 10);
      expect(shop.posts, hasLength(1));
      final f = _fields(shop.posts.single);
      expect(f['reason_code'], 'damaged');
      expect(f['items[0][order_item_id]'], 'i1');
      expect(f['items[0][quantity]'], '1');
      expect((shop.posts.single.body as FormData).files, hasLength(2));
      expect((shop.posts.single.header('Idempotency-Key'))?.length, greaterThan(10));
      expect(find.text(en.returnRequested), findsOneWidget);
      await teardown(t);
    });

    testWidgets('a missing item or a change of mind goes without a photo; a refusal about the photos is explained', (
      t,
    ) async {
      final shop = _Shop(_order())
        ..reply = (r) => error(
          422,
          'validation_failed',
          errors: {
            'photos': ['x'],
          },
        );
      await _open(t, shop);
      await _openForm(t);
      await _tapKey(t, 'claim-type-missing_item');
      await t.tap(
        find
            .descendant(of: find.byKey(const ValueKey('return-line-i1')), matching: find.byIcon(Icons.add_rounded))
            .first,
      );
      await t.pump();
      expect(find.text(en.claimPhotosOptional), findsOneWidget);
      expect(t.widget<AppButton>(find.byKey(const ValueKey('claim-submit'))).onPressed, isNotNull);
      await t.tap(find.byKey(const ValueKey('claim-submit')));
      await settle(t, 10);
      expect(_fields(shop.posts.single)['reason_code'], 'missing_item');
      expect(find.text(en.claimErrPhotos), findsOneWidget);
      await teardown(t);
    });

    testWidgets('says until when a problem can be reported, in Arabic too, and the form survives large text', (
      t,
    ) async {
      await _open(t, _Shop(_order()), locale: 'ar', scale: 1.3);
      expect(find.textContaining('حتى'), findsWidgets, reason: 'the window note on the order');
      await t.tap(_button(ar.requestReturn));
      await settle(t, 8);
      expect(find.byKey(const ValueKey('claim-window-note')), findsWidgets);
      expect(find.text(ar.claimTypeDamaged), findsOneWidget);
      expect(t.takeException(), isNull);
      await teardown(t);
    });
  });

  group('while it waits for the team', () {
    final waiting = _claim(
      'requested',
      editable: true,
      photos: [
        {'id': 'p1', 'path': 'returns/rr1/photos/p1'},
      ],
      timeline: [_step('received', 'current'), _step('under_review', 'upcoming'), _step('decision', 'upcoming')],
    );

    testWidgets('shows the photos it sent, and adds a note', (t) async {
      final shop = _Shop(_order(claim: waiting, canReturn: false));
      await _open(t, shop);
      expect(find.byKey(const ValueKey('claim-photo-p1')), findsOneWidget);
      await _tapKey(t, 'claim-add');
      expect(find.text(en.claimAddHint), findsOneWidget);
      expect(
        t.widget<AppButton>(find.byKey(const ValueKey('claim-add-submit'))).onPressed,
        isNull,
        reason: 'nothing to send yet',
      );
      await t.enterText(find.byType(TextField).last, 'The back is cracked too');
      await t.pump();
      await t.tap(find.byKey(const ValueKey('claim-add-submit')));
      await settle(t, 10);
      expect(shop.posts.single.path, '/returns/rr1/evidence');
      expect(_fields(shop.posts.single), {'reason': 'The back is cracked too'});
      expect(find.text(en.claimAdded), findsOneWidget);
      await teardown(t);
    });

    testWidgets('can be cancelled after a confirmation, and a decided claim offers neither', (t) async {
      final shop = _Shop(_order(claim: waiting, canReturn: false));
      await _open(t, shop);
      await _tapKey(t, 'claim-cancel');
      expect(find.text(en.claimCancelConfirm), findsOneWidget);
      await t.tap(find.text(en.claimCancel).last);
      await settle(t, 10);
      expect(shop.posts.single.path, '/returns/rr1/cancel');
      await teardown(t);

      await _open(
        t,
        _Shop(
          _order(
            claim: _claim('approved', method: 'branch', timeline: [_step('received', 'done')]),
            canReturn: false,
          ),
        ),
      );
      expect(find.byKey(const ValueKey('claim-add')), findsNothing);
      expect(find.byKey(const ValueKey('claim-cancel')), findsNothing);
      await teardown(t);
    });

    testWidgets('a claim the team has already looked at refuses the change with a plain sentence', (t) async {
      final shop = _Shop(_order(claim: waiting, canReturn: false))..reply = (r) => error(409, 'claim_not_editable');
      await _open(t, shop);
      await _tapKey(t, 'claim-add');
      await t.enterText(find.byType(TextField).last, 'late');
      await t.pump();
      await t.tap(find.byKey(const ValueKey('claim-add-submit')));
      await settle(t, 10);
      expect(find.text(en.claimErrLocked), findsOneWidget);
      await teardown(t);
    });
  });
}
