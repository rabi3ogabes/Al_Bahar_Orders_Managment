<?php

require_once __DIR__.'/AdjustmentFixtures.php';

use App\Domain\Orders\Models\OrderRevision;
use Illuminate\Support\Str;

/** The staff app reads the machine code from the top-level `code` of the JSON error envelope, the words from `message`, and field problems from `errors.<field>`. */
it('answers every contract error with its code at the top level, the right status and a translated message', function (string $code, int $status) {
    $a = adjustmentWorld();
    $id = $a->l1->id;
    $changes = match ($code) {
        'adjustment_not_allowed' => [['order_item_id' => $id, 'action' => 'remove']],
        'no_change' => [['order_item_id' => $id, 'action' => 'reduce', 'new_quantity' => 2]],
        'quantity_exceeds' => [['order_item_id' => $id, 'action' => 'reduce', 'new_quantity' => 3]],
        'duplicate_item' => [['order_item_id' => $id, 'action' => 'remove'], ['order_item_id' => $id, 'action' => 'reduce', 'new_quantity' => 1]],
        'substitute_unavailable' => [['order_item_id' => $id, 'action' => 'replace', 'variant_id' => (string) Str::uuid(), 'quantity' => 1]],
        'item_not_in_fulfillment' => [['order_item_id' => (string) Str::uuid(), 'action' => 'remove']],
    };
    if ($code === 'adjustment_not_allowed') {
        $a->f->forceFill(['status' => 'ready'])->save();
    }

    foreach (['en', 'ar'] as $locale) {
        $r = test()->postJson(adjustUrl($a), ['changes' => $changes, 'reason_code' => 'out_of_stock'], ['Accept-Language' => $locale])->assertStatus($status);
        $body = $r->json();
        $expected = __('errors.'.$code, [], $locale);
        expect($body['code'])->toBe($code)->and($body['message'])->toBe($expected)->and($expected)->not->toBe('errors.'.$code)->and($body['request_id'])->toBeString()->and($body)->not->toHaveKey('errors');
        if (! in_array($code, ['adjustment_not_allowed'], true)) {
            expect($body['context']['index'])->toBeInt();
        }
    }
    expect(OrderRevision::count())->toBe(0);
})->with([
    ['adjustment_not_allowed', 409], ['no_change', 422], ['quantity_exceeds', 422], ['duplicate_item', 422], ['substitute_unavailable', 422], ['item_not_in_fulfillment', 422],
]);

it('reports which change was refused, by position and item', function () {
    $a = adjustmentWorld();
    $other = (string) Str::uuid();
    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $other, 'action' => 'remove']])->assertStatus(422);
    expect($r->json('code'))->toBe('item_not_in_fulfillment')->and($r->json('context'))->toBe(['index' => 1, 'order_item_id' => $other]);
});

it('reports a malformed request under errors.<field> with the code validation_failed', function () {
    $a = adjustmentWorld();
    $r = $this->postJson(adjustUrl($a), ['changes' => [['order_item_id' => $a->l1->id, 'action' => 'replace']], 'reason_code' => 'because'])->assertStatus(422);
    expect($r->json('code'))->toBe('validation_failed')->and($r->json('message'))->toBe(__('errors.validation_failed'))
        ->and(array_keys($r->json('errors')))->toEqualCanonicalizing(['reason_code', 'changes.0.variant_id', 'changes.0.quantity']);
    $this->postJson(adjustUrl($a), ['reason_code' => 'other'])->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['changes']]);
});

it('keeps the permission and lookup errors in the same envelope', function () {
    $a = adjustmentWorld();
    actingAsStaff(staffWithRole('order_manager', $a->w->b));
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertStatus(403)->assertJsonPath('code', 'branch_forbidden')->assertJsonStructure(['message', 'code', 'request_id']);
    $this->postJson('/api/v1/admin/fulfillments/'.Str::uuid().'/adjustments', ['changes' => [['order_item_id' => $a->l1->id, 'action' => 'remove']], 'reason_code' => 'other'])
        ->assertStatus(404)->assertJsonPath('code', 'not_found');
    $this->postJson('/api/v1/admin/fulfillments/not-an-id/adjustments', [])->assertStatus(404)->assertJsonPath('code', 'not_found');
});
