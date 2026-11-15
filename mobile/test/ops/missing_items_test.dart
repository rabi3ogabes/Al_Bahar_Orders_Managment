import 'package:albahar_customer/core/network/api_exception.dart';
import 'package:albahar_customer/core/storage/token_storage.dart';
import 'package:albahar_customer/ops/ops_models.dart';
import 'package:albahar_customer/ops/ops_repository.dart';
import 'package:albahar_customer/ops/staff/adjust_draft.dart';
import 'package:albahar_customer/ops/staff/adjust_flow.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fakes.dart';
import 'ops_test_kit.dart';

// ── Models ───────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/// The answer example of docs/api/ORDER_ADJUSTMENTS.md, as the server sends it.
Map<String, dynamic> _contractAnswer() => {
  'applied': true,
  'preview': false,
  'revision': {'id': 'rev-1', 'number': 1},
  'lines': [
    {
      'order_item_id': 'oi1',
      'action': 'reduce',
      'name': 'Oslo sofa',
      'variant_name': 'Grey',
      'from_quantity': 2,
      'to_quantity': 1,
      'replacement': null,
    },
    {
      'order_item_id': 'oi2',
      'action': 'replace',
      'name': 'Oslo lamp',
      'from_quantity': 1,
      'to_quantity': 0,
      'replacement': {
        'variant_id': 'v9',
        'name': 'Oslo lamp (large)',
        'variant_name': null,
        'quantity': 1,
        'unit_price': {'amount': '250.00', 'currency': 'QAR'},
      },
    },
  ],
  'before': {
    'subtotal': {'amount': '2250.00', 'currency': 'QAR'},
    'discount_total': {'amount': '0.00', 'currency': 'QAR'},
    'tax_total': {'amount': '0.00', 'currency': 'QAR'},
    'grand_total': {'amount': '2275.00', 'currency': 'QAR'},
  },
  'after': {
    'subtotal': {'amount': '1250.00', 'currency': 'QAR'},
    'discount_total': {'amount': '0.00', 'currency': 'QAR'},
    'tax_total': {'amount': '0.00', 'currency': 'QAR'},
    'grand_total': {'amount': '1275.00', 'currency': 'QAR'},
  },
  'difference': {'amount': '-1000.00', 'currency': 'QAR'},
  'refund': {
    'amount': {'amount': '1000.00', 'currency': 'QAR'},
    'needs_approval': true,
  },
  'fulfillment_cancelled': false,
  'order_cancelled': false,
  'customer_notified': true,
};

void main() {
  group('the contract, parsed', () {
    test('the context: whether it can be corrected, the reasons, the lines and what is already corrected', () {
      final c = AdjustContext.fromJson({
        'can_adjust': true,
        'blocked_reason': null,
        'reasons': ['out_of_stock', 'not_found', 'damaged', 'quality', 'other'],
        'items': [
          {
            'order_item_id': 'uuid-1',
            'product_id': 'uuid-p',
            'variant_id': 'uuid-v',
            'sku': 'SKU-1',
            'name': 'Oslo sofa',
            'variant_name': 'Grey',
            'image_url': null,
            'quantity': 2,
            'unit_price': {'amount': '1000.00', 'currency': 'QAR'},
            'line_total': {'amount': '2000.00', 'currency': 'QAR'},
            'adjustment': {
              'kind': 'reduced',
              'original_quantity': 3,
              'original_name': 'Oslo sofa',
              'reason_code': 'damaged',
            },
          },
        ],
        'totals': {
          'grand_total': {'amount': '2275.00', 'currency': 'QAR'},
          'payment_method': 'cod',
          'payment_status': 'pending',
        },
      });
      expect(c.canAdjust, isTrue);
      expect(c.blockedReason, isNull);
      expect(c.reasons, ['out_of_stock', 'not_found', 'damaged', 'quality', 'other']);
      expect(c.isCash, isTrue);
      expect(c.grandTotal.amount, '2275.00');
      final i = c.items.single;
      expect(i.orderItemId, 'uuid-1');
      expect(i.variantName, 'Grey');
      expect(i.imageUrl, isNull);
      expect(i.quantity, 2);
      expect(i.unitPrice.amount, '1000.00');
      expect(i.lineTotal.amount, '2000.00');
      expect(i.mark?.kind, 'reduced');
      expect(i.mark?.originalQuantity, 3);
      expect(i.canChange, isTrue);
    });

    test('blocked: the reason is kept; a missing list of reasons falls back to the contract ones', () {
      final c = AdjustContext.fromJson({'can_adjust': false, 'blocked_reason': 'status', 'items': <Object>[]});
      expect(c.canAdjust, isFalse);
      expect(c.blockedReason, 'status');
      expect(c.reasons, AdjustContext.defaultReasons);
      expect(c.items, isEmpty);
      expect(c.isCash, isFalse);
    });

    test('garbage in the context is read as nothing, never a crash', () {
      final c = AdjustContext.fromJson({'can_adjust': 'yes?', 'items': 'nope', 'totals': 5});
      expect(c.canAdjust, isFalse);
      expect(c.items, isEmpty);
      expect(c.grandTotal.amount, '0.00');
    });

    test('a line an earlier correction emptied cannot be changed again', () {
      final i = AdjustItem.fromJson(adjustItemJson('x', 'Gone', 0, '10.00'));
      expect(i.canChange, isFalse);
      expect(AdjustDraft.of([i]).items, isEmpty);
    });

    test('a replacement candidate', () {
      final s = Substitute.fromJson({
        'variant_id': 'v1',
        'product_id': 'p1',
        'name': 'Oslo lamp (large)',
        'variant_name': 'Brass',
        'sku': 'L-1',
        'image_url': 'https://x/y.jpg',
        'unit_price': {'amount': '250.00', 'currency': 'QAR'},
        'available': '3',
      });
      expect(s.variantId, 'v1');
      expect(s.unitPrice.value, 250);
      expect(s.available, 3);
      expect(s.imageUrl, 'https://x/y.jpg');
    });

    test('the answer of the contract: lines, totals, difference, refund to approve, flags', () {
      final r = AdjustResult.fromJson(_contractAnswer());
      expect(r.applied, isTrue);
      expect(r.preview, isFalse);
      expect(r.revisionNumber, 1);
      expect(r.lines, hasLength(2));
      expect(r.lines.first.action, 'reduce');
      expect(r.lines.first.fromQuantity, 2);
      expect(r.lines.first.toQuantity, 1);
      expect(r.lines.first.variantName, 'Grey');
      expect(r.lines.first.replacement, isNull);
      expect(r.lines.last.replacement?.name, 'Oslo lamp (large)');
      expect(r.lines.last.replacement?.quantity, 1);
      expect(r.before.grandTotal.amount, '2275.00');
      expect(r.after.grandTotal.amount, '1275.00');
      expect(r.difference.amount, '-1000.00');
      expect(r.difference.unsigned, '1000.00');
      expect(r.refund?.amount, '1000.00');
      expect(r.refundNeedsApproval, isTrue);
      expect(r.fulfillmentCancelled, isFalse);
      expect(r.orderCancelled, isFalse);
      expect(r.customerNotified, isTrue);
    });

    test('a cash order has no refund; a missing answer reads as nothing changed', () {
      final r = AdjustResult.fromJson({..._contractAnswer(), 'refund': null});
      expect(r.refund, isNull);
      expect(r.refundNeedsApproval, isFalse);
      final empty = AdjustResult.fromJson(const {});
      expect(empty.applied, isFalse);
      expect(empty.lines, isEmpty);
      expect(empty.difference.isZero, isTrue);
    });

    test('money: an amount without a minus sign, and zero', () {
      expect(const OpsMoney('-25.00').unsigned, '25.00');
      expect(const OpsMoney('25.00').unsigned, '25.00');
      expect(const OpsMoney('0.00').isZero, isTrue);
      expect(OpsMoney.from('junk').amount, '0.00');
    });
  });

  group('the request bodies are exactly the contract', () {
    Future<(TestHarness, OpsRepository)> repo([FakeHandler? handler]) async {
      final h = await TestHarness.create(
        handler: handler ?? (_) => ok(_contractAnswer()),
        tokens: const AuthTokens(accessToken: 'A', refreshToken: 'R'),
      );
      return (h, h.read(opsRepositoryProvider));
    }

    test('changes: reduce, remove, replace', () {
      expect(const AdjustReduce('a', 1).toJson(), {'order_item_id': 'a', 'action': 'reduce', 'new_quantity': 1});
      expect(const AdjustRemove('b').toJson(), {'order_item_id': 'b', 'action': 'remove'});
      expect(AdjustReplace('c', Substitute.fromJson(substituteJson('v7', 'Lamp', '10.00', 3)), 2).toJson(), {
        'order_item_id': 'c',
        'action': 'replace',
        'variant_id': 'v7',
        'quantity': 2,
      });
    });

    test('POST /admin/fulfillments/{id}/adjustments with changes, reason_code, note and preview', () async {
      final (h, r) = await repo();
      await r.adjust(
        'f1',
        changes: [
          const AdjustReduce('a', 1),
          const AdjustRemove('b'),
          AdjustReplace('c', Substitute.fromJson(substituteJson('v7', 'Lamp', '10.00', 3)), 2),
        ],
        reasonCode: 'out_of_stock',
        note: '  Only one left on the shelf  ',
      );
      final req = h.adapter.where('POST', '/admin/fulfillments/f1/adjustments').single;
      expect(req.body, {
        'changes': [
          {'order_item_id': 'a', 'action': 'reduce', 'new_quantity': 1},
          {'order_item_id': 'b', 'action': 'remove'},
          {'order_item_id': 'c', 'action': 'replace', 'variant_id': 'v7', 'quantity': 2},
        ],
        'reason_code': 'out_of_stock',
        'note': 'Only one left on the shelf',
        'preview': false,
      });
    });

    test('the preview sends preview: true; a blank note is left out', () async {
      final (h, r) = await repo();
      await r.adjust('f1', changes: const [AdjustRemove('b')], reasonCode: 'damaged', note: '   ', preview: true);
      await r.adjust('f1', changes: const [AdjustRemove('b')], reasonCode: 'damaged', preview: true);
      for (final req in h.adapter.where('POST', '/admin/fulfillments/f1/adjustments')) {
        expect(req.body, {
          'changes': [
            {'order_item_id': 'b', 'action': 'remove'},
          ],
          'reason_code': 'damaged',
          'preview': true,
        });
      }
    });

    test('GET …/adjustments/context', () async {
      final (h, r) = await repo((_) => ok(adjustContextJson()));
      final c = await r.adjustContext('f1');
      expect(h.adapter.where('GET', '/admin/fulfillments/f1/adjustments/context'), hasLength(1));
      expect(c.items, hasLength(3));
    });

    test('GET …/substitutes?search=&limit=20: a blank search is left out, a typed one is trimmed', () async {
      final (h, r) = await repo((_) => ok([substituteJson('s1', 'Lamp', '10.00', 2)]));
      final all = await r.substitutes('f1');
      await r.substitutes('f1', search: '  lamp ');
      final reqs = h.adapter.where('GET', '/admin/fulfillments/f1/substitutes');
      expect(reqs.first.query, {'limit': '20'});
      expect(reqs.last.query, {'search': 'lamp', 'limit': '20'});
      expect(all.single.name, 'Lamp');
    });

    test('a refused correction reaches the caller with its code', () async {
      final (_, r) = await repo((_) => error(409, 'adjustment_not_allowed', message: 'No longer'));
      await expectLater(
        r.adjust('f1', changes: const [AdjustRemove('b')], reasonCode: 'damaged'),
        throwsA(isA<ApiException>().having((e) => e.code, 'code', 'adjustment_not_allowed')),
      );
    });
  });

  group('the codes the server refuses with', () {
    ApiException e({
      String? code,
      Map<String, List<String>> errors = const {},
      Map<String, dynamic> context = const {},
    }) => ApiException(kind: ApiErrorKind.validation, statusCode: 422, code: code, errors: errors, context: context);

    test('read from code, then the context, then the field errors', () {
      expect(adjustErrorCode(e(code: 'no_change')), 'no_change');
      expect(adjustErrorCode(e(code: 'validation_failed', context: {'code': 'quantity_exceeds'})), 'quantity_exceeds');
      expect(
        adjustErrorCode(
          e(
            code: 'validation_failed',
            errors: {
              'changes': ['substitute_unavailable'],
            },
          ),
        ),
        'substitute_unavailable',
      );
      expect(
        adjustErrorCode(
          e(
            code: 'validation_failed',
            errors: {
              'changes.0.no_change': ['x'],
            },
          ),
        ),
        'no_change',
      );
      expect(adjustErrorCode(e(code: 'validation_failed')), isNull);
      expect(adjustErrorCode(StateError('x')), isNull);
    });

    test('which changes a field error points at', () {
      expect(
        adjustErrorPositions(
          e(
            errors: {
              'changes.2.variant_id': ['x'],
              'changes.0.quantity': ['y'],
            },
          ),
        ),
        {0, 2},
      );
      expect(
        adjustErrorPositions(
          e(
            errors: {
              'reason_code': ['x'],
            },
          ),
        ),
        isNull,
      );
    });
  });

  group('the draft', () {
    final items = [
      AdjustItem.fromJson(adjustItemJson('a', 'Sofa', 3, '100.00')),
      AdjustItem.fromJson(adjustItemJson('b', 'Lamp', 1, '50.00')),
      AdjustItem.fromJson(adjustItemJson('c', 'Rug', 2, '20.00')),
    ];
    final lamp = Substitute.fromJson(substituteJson('s', 'Brass lamp', '40.00', 2));

    test('starts empty; a change per line; changes follow the order of the lines, not of the taps', () {
      var d = AdjustDraft.of(items);
      expect(d.isEmpty, isTrue);
      d = d.remove(items[2]).reduce(items[0], 2);
      expect(d.count, 2);
      expect(d.changes.map((c) => c.orderItemId), ['a', 'c']);
    });

    test('reduce stays between 1 and one less than ordered; the same as ordered is no change; 0 removes', () {
      var d = AdjustDraft.of(items).reduce(items[0], 2);
      expect((d.of('a')! as AdjustReduce).newQuantity, 2);
      d = d.reduce(items[0], 3);
      expect(d.of('a'), isNull, reason: 'back to what was ordered = no change');
      d = d.reduce(items[0], 7);
      expect(d.of('a'), isNull);
      d = d.reduce(items[0], 0);
      expect(d.of('a'), isA<AdjustRemove>());
      d = d.reduce(items[0], -4);
      expect(d.of('a'), isA<AdjustRemove>());
    });

    test('replace: the quantity never exceeds what the branch has or what was ordered, and is at least 1', () {
      var d = AdjustDraft.of(items).replace(items[0], lamp, 9);
      expect((d.of('a')! as AdjustReplace).quantity, 2, reason: 'the branch has 2');
      d = d.replace(items[2], Substitute.fromJson(substituteJson('s', 'Big', '5.00', 50)), 9);
      expect((d.of('c')! as AdjustReplace).quantity, 2, reason: 'only 2 were ordered');
      d = d.replace(items[1], lamp, 0);
      expect((d.of('b')! as AdjustReplace).quantity, 1);
    });

    test('one change per line: a new choice replaces the old one; undo clears it', () {
      var d = AdjustDraft.of(items).reduce(items[0], 1).remove(items[0]);
      expect(d.count, 1);
      expect(d.of('a'), isA<AdjustRemove>());
      d = d.undo('a');
      expect(d.isEmpty, isTrue);
    });

    test('replacements can be taken back, all or by position in what was sent', () {
      var d = AdjustDraft.of(items).replace(items[0], lamp, 1).remove(items[1]).replace(items[2], lamp, 1);
      expect(d.withoutReplacements().changes.map((c) => c.orderItemId), ['b']);
      expect(d.withoutReplacements(positions: {2}).changes.map((c) => c.orderItemId), ['a', 'b']);
      d = d.withoutReplacements(positions: {1});
      expect(d.count, 3, reason: 'position 1 is a removal, not a replacement');
    });

    test('rebase over fresh lines drops what no longer fits', () {
      final d = AdjustDraft.of(items).reduce(items[0], 2).remove(items[1]).replace(items[2], lamp, 2);
      final fresh = [
        AdjustItem.fromJson(adjustItemJson('a', 'Sofa', 2, '100.00')),
        AdjustItem.fromJson(adjustItemJson('c', 'Rug', 1, '20.00')),
      ];
      final r = d.rebase(fresh);
      expect(r.of('a'), isNull, reason: 'now only 2 are left, so "2" is no change');
      expect(r.of('b'), isNull, reason: 'the line is gone');
      expect((r.of('c')! as AdjustReplace).quantity, 1);
    });
  });
}
