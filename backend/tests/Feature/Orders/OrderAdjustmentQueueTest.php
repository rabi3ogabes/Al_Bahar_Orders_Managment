<?php

require_once __DIR__.'/AdjustmentFixtures.php';

use App\Domain\Orders\Models\OrderItem;

/** The staff app's queue (GET /admin/dispatch/queue) shows the parcel as it is now: only the lines still to be prepared, with the id the adjustments API takes. */
function queueRow(object $a, string $stage = 'preparing'): array
{
    $rows = test()->getJson('/api/v1/admin/dispatch/queue?branch_id='.$a->w->a->id.'&stage='.$stage)->assertOk()->json('data');

    return collect($rows)->firstWhere('fulfillment_id', $a->f->id);
}

it('lists only the lines still active, each with its order_item_id and what the branch did to it', function () {
    $a = adjustmentWorld(['qty' => [3, 1]]);
    [, $v] = substituteFor($a->w, '100.00');
    $row = queueRow($a);
    expect($row['items'])->toHaveCount(2)->and($row['items_count'])->toBe(4)->and($row['was_adjusted'])->toBeFalse()->and($row['adjustment_count'])->toBe(0)->and($row['can_adjust'])->toBeTrue()
        ->and(collect($row['items'])->pluck('order_item_id')->all())->toEqualCanonicalizing([$a->l1->id, $a->l2->id])->and($row['items'][0]['adjustment'])->toBeNull();

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]])->assertOk();
    $row = queueRow($a);
    $new = OrderItem::where('replaces_order_item_id', $a->l2->id)->first();
    $byId = collect($row['items'])->keyBy('order_item_id');

    // the swapped-out lamp is gone from the list, its replacement is in; the sofas are fewer and say they were reduced
    expect($row['items'])->toHaveCount(2)->and($byId->keys()->all())->toEqualCanonicalizing([$a->l1->id, $new->id])->and($byId->has($a->l2->id))->toBeFalse()
        ->and($byId[$a->l1->id])->toMatchArray(['id' => $a->l1->id, 'quantity' => 2, 'sku' => $a->l1->sku])->and($byId[$a->l1->id]['adjustment'])->toBe(['kind' => 'reduced', 'original_quantity' => 3])
        ->and($byId[$new->id])->toMatchArray(['quantity' => 1, 'sku' => $v->sku])->and($byId[$new->id]['adjustment'])->toBe(['kind' => 'replacement', 'original_quantity' => 1])
        ->and($row['items_count'])->toBe(3)->and($row['was_adjusted'])->toBeTrue()->and($row['adjustment_count'])->toBe(1)->and($row['grand_total'])->toBe($a->order->fresh()->grand_total);

    // a removed line leaves the list; once the parcel is ready it can no longer be adjusted
    postAdjust($a, [['order_item_id' => $new->id, 'action' => 'remove']], 'damaged')->assertOk();
    expect(queueRow($a)['items'])->toHaveCount(1);
    $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'ready'])->assertOk();
    $ready = queueRow($a, 'ready');
    expect($ready['can_adjust'])->toBeFalse()->and($ready['items'])->toHaveCount(1)->and($ready['adjustment_count'])->toBe(2);
});

it('keeps the context endpoint complete: every active line with its price and marker', function () {
    $a = adjustmentWorld();
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk();
    $items = $this->getJson(adjustUrl($a, 'adjustments/context'))->assertOk()->json('data.items');
    expect($items)->toHaveCount(1)->and($items[0])->toHaveKeys(['order_item_id', 'product_id', 'variant_id', 'sku', 'name', 'variant_name', 'image_url', 'quantity', 'unit_price', 'line_total', 'adjustment']);
});
