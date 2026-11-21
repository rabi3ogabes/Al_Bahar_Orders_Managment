<?php

require_once __DIR__.'/AdjustmentFixtures.php';

use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationLog;
use App\Domain\Notifications\Models\NotificationPreference;
use App\Domain\Notifications\Services\NotificationCatalog;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Settings\Services\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** What the customer is told and shown: the notification, the order page and the order list. */

// ─── The notification ───────────────────────────────────────────────────────────────────────────────────────────────────

it('registers the event as a transactional in-app + push message that the live card does not already cover', function () {
    expect(NotificationCatalog::EVENTS['order.items_changed'])->toBe(['transactional', ['push', 'in_app']])
        ->and(NotificationCatalog::LIVE_CARD_EVENTS)->not->toContain('order.items_changed')->and(NotificationCatalog::category('order.items_changed'))->toBe('transactional');
});

it('tells the customer in-app, in their language, with the changes, the reason and the new total', function (string $locale, array $expects) {
    app(Settings::class)->set('substitutions.consent_enabled', false);   // these tests are about the change itself; the question to the customer has its own tests
    $a = adjustmentWorld(['locale' => $locale]);
    [, $v] = substituteFor($a->w, '600.00');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]], 'out_of_stock')->assertOk()
        ->assertJsonPath('data.customer_notified', true);

    $n = Notification::where('user_id', $a->customer->id)->where('event', 'order.items_changed')->sole();
    expect($n->category)->toBe('transactional')->and($n->title)->toBe($expects['title'])->and($n->data['deep_link'])->toBe('albahar://orders/'.$a->order->id)->and($n->data['reference'])->toBe($a->order->id);
    foreach ($expects['body'] as $piece) {
        expect($n->body)->toContain($piece);
    }
    expect($n->body)->toContain($locale === 'ar' ? $a->l1->name_ar : $a->l1->name_en)->and($n->body)->not->toContain('{{');
})->with([
    'english' => ['en', ['title' => 'Your order was updated', 'body' => ['was updated', '2 → 1', 'replaced with', 'Reason: Out of stock', 'New total: QAR 1,275.00']]],
    'arabic' => ['ar', ['title' => 'تم تحديث طلبك', 'body' => ['تم تحديث الطلب', 'من 2 إلى 1', 'تم استبدال', 'السبب: نفد من المخزون', 'الإجمالي الجديد: 1,275.00 ر.ق']]],
]);

it('queues a push that carries both languages, so each phone gets the language of its own app', function () {
    $a = adjustmentWorld();
    Queue::fake();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();

    expect(NotificationLog::where('user_id', $a->customer->id)->where('event', 'order.items_changed')->where('channel', 'push')->count())->toBe(1);
    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) use ($a) {
        return $job->texts !== null && $job->deepLink === 'albahar://orders/'.$a->order->id && str_contains($job->texts['en']['body'], 'New total: QAR 1,275.00') && $job->texts['en']['subject'] === 'Your order was updated'
            && str_contains($job->texts['ar']['body'], 'الإجمالي الجديد') && $job->texts['ar']['subject'] === 'تم تحديث طلبك' && $job->notificationId !== null;
    });
});

it('sends nothing for a preview, and one message per adjustment', function () {
    $a = adjustmentWorld(['qty' => [3, 1]]);
    Queue::fake();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2]], 'out_of_stock', ['preview' => true])->assertOk();
    expect(Notification::where('event', 'order.items_changed')->count())->toBe(0)->and(NotificationLog::where('event', 'order.items_changed')->count())->toBe(0);
    Queue::assertNothingPushed();

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2]])->assertOk();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2]])->assertOk()->assertJsonPath('data.replayed', true); // a repeat: the same answer, no second message
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'remove']], 'damaged')->assertOk();
    expect(Notification::where('event', 'order.items_changed')->count())->toBe(2);
});

it('says what happens to the money: a refund of the difference for a paid order, the cancellation when nothing is left', function () {
    $a = adjustmentWorld(['payment' => 'sadad']);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    expect(Notification::where('event', 'order.items_changed')->first()->body)->toContain('New total: QAR 1,265.00')->toContain('A refund of QAR 1,000.00 for the difference has been requested.');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove'], ['order_item_id' => $a->l2->id, 'action' => 'remove']], 'quality')->assertOk();
    $last = Notification::where('event', 'order.items_changed')->latest('id')->first();
    expect($last->body)->toContain('Your order has been cancelled and any payment will be refunded.')->not->toContain('New total');
});

it('keeps the in-app message even when the customer turned push off', function () {
    $a = adjustmentWorld();
    NotificationPreference::create(['user_id' => $a->customer->id, 'channel' => 'push', 'category' => 'transactional', 'is_enabled' => false]);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.customer_notified', true);
    expect(Notification::where('event', 'order.items_changed')->count())->toBe(1)->and(NotificationLog::where('event', 'order.items_changed')->where('channel', 'push')->count())->toBe(0);
});

it('does not undo the adjustment when the notification cannot be created, and tells the staff member', function () {
    $a = adjustmentWorld();
    // a notification template the admin broke on purpose cannot happen, but a failing database write for it must not roll the order back: simulate with a closing trigger
    DB::unprepared("CREATE OR REPLACE FUNCTION fail_notification() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'notification table is down'; END; \$\$ LANGUAGE plpgsql");
    DB::unprepared('CREATE TRIGGER fail_notification BEFORE INSERT ON notifications FOR EACH ROW EXECUTE FUNCTION fail_notification()');

    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk()->assertJsonPath('data.customer_notified', false);
    expect($a->order->fresh()->grand_total)->toBe('1275.00')->and(OrderRevision::count())->toBe(1);
    DB::unprepared('DROP TRIGGER fail_notification ON notifications');
});

// ─── The order page ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('shows the customer what changed on the order, line by line, in the contract\'s shape', function () {
    $a = adjustmentWorld(['coupon' => ['percentage', '10']]);
    [$p, $v] = substituteFor($a->w, '600.00');
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 1]], 'out_of_stock',
        ['note' => 'Only one left on the shelf'])->assertOk();

    actingAsCustomer($a->customer);
    $d = $this->getJson("/api/v1/orders/{$a->order->id}")->assertOk()->json('data');
    expect($d['was_adjusted'])->toBeTrue()->and($d['items'])->toHaveCount(3)->and(collect($d['items'])->pluck('id')->all())->toBe([$a->l1->id, $a->l2->id, OrderItem::where('replaces_order_item_id', $a->l2->id)->value('id')]);
    [$sofa, $lamp, $new] = $d['items'];
    expect($sofa['quantity'])->toBe(1)->and($sofa['adjustment'])->toMatchArray(['kind' => 'reduced', 'original_quantity' => 2, 'original_name' => $a->l1->name_en, 'reason_code' => 'out_of_stock', 'reason_label' => 'Out of stock'])
        ->and($sofa['line_total']['amount'])->toBe('900.00')
        // swapped out: stays, struck through, quantity 0
        ->and($lamp['quantity'])->toBe(0)->and($lamp['adjustment'])->toMatchArray(['kind' => 'replaced', 'original_quantity' => 1, 'original_name' => $a->l2->name_en, 'reason_code' => 'out_of_stock', 'replaced_by_name' => $p->trans('name')])
        ->and($lamp['line_total']['amount'])->toBe('0.00')
        ->and($new['quantity'])->toBe(1)->and($new['adjustment'])->toMatchArray(['kind' => 'replacement', 'original_name' => $a->l2->name_en, 'replaces_name' => $a->l2->name_en, 'reason_code' => 'out_of_stock'])
        ->and($new['name'])->toBe($p->trans('name'))->and($new['unit_price']['amount'])->toBe('250.00'); // 600 now, but never more than the 250 it replaces

    expect($d['revisions'])->toHaveCount(1);
    $rev = $d['revisions'][0];
    expect($rev)->toMatchArray(['number' => 1, 'reason_code' => 'out_of_stock', 'reason_label' => 'Out of stock', 'note' => 'Only one left on the shelf', 'order_cancelled' => false])
        ->and($rev['at'])->toBeString()->and($rev['branch'])->toBe($a->w->a->name_en)->and($rev['lines'])->toHaveCount(2)
        ->and($rev['lines'][0])->toMatchArray(['order_item_id' => $a->l1->id, 'action' => 'reduce', 'from_quantity' => 2, 'to_quantity' => 1, 'replacement' => null])
        ->and($rev['lines'][0]['text'])->toBe($a->l1->name_en.': 2 → 1')
        ->and($rev['lines'][1]['replacement'])->toMatchArray(['quantity' => 1, 'name' => $p->trans('name'), 'variant_id' => $v->id])->and($rev['lines'][1]['replacement']['unit_price']['amount'])->toBe('250.00')
        ->and($rev['difference']['amount'])->toBe('-900.00')->and($rev['total_before']['amount'])->toBe('2050.00')->and($rev['total_after']['amount'])->toBe('1150.00');
    expect($d['totals']['grand_total']['amount'])->toBe('1150.00');
});

it('gives an untouched order the same payload as before, plus the empty markers', function () {
    $a = adjustmentWorld();
    actingAsCustomer($a->customer);
    $d = $this->getJson("/api/v1/orders/{$a->order->id}")->assertOk()->json('data');
    expect($d['was_adjusted'])->toBeFalse()->and($d['revisions'])->toBe([])->and($d['items'])->toHaveCount(2)->and($d['items'][0]['adjustment'])->toBeNull()->and($d['items'][1]['adjustment'])->toBeNull();
});

it('speaks Arabic in the order payload when the app does', function () {
    $a = adjustmentWorld(['locale' => 'ar']);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]], 'not_found')->assertOk();
    actingAsCustomer($a->customer);
    $d = $this->getJson("/api/v1/orders/{$a->order->id}", ['Accept-Language' => 'ar'])->assertOk()->json('data');
    expect($d['items'][0]['adjustment']['reason_label'])->toBe('لم يُعثر عليه في الفرع')->and($d['revisions'][0]['reason_label'])->toBe('لم يُعثر عليه في الفرع')
        ->and($d['revisions'][0]['lines'][0]['text'])->toContain('من 2 إلى 1')->and($d['revisions'][0]['branch'])->toBe($a->w->a->name_ar);
});

it('lists the adjusted flag on the order list and the timeline entry, without a query per order', function () {
    $a = adjustmentWorld();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();
    actingAsCustomer($a->customer);
    $list = $this->getJson('/api/v1/orders')->assertOk()->json('data');
    expect($list[0]['was_adjusted'])->toBeTrue()->and($list[0]['items_count'])->toBe(2)->and($list[0]['grand_total']['amount'])->toBe('1275.00');

    $timeline = $this->getJson("/api/v1/orders/{$a->order->id}/timeline")->assertOk()->json('data');
    $entry = collect($timeline)->firstWhere('kind', 'items_changed');
    expect($entry)->not->toBeNull()->and($entry['status'])->toBe('items_changed')->and($entry['label'])->toBe('Items updated')->and($entry['fulfillment_id'])->toBe($a->f->id);
});

it('keeps the order list and the order page free of per-item and per-order queries', function () {
    $a = adjustmentWorld(['qty' => [3, 2]]);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 2]])->assertOk();
    $queries = function (string $url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    actingAsCustomer($a->customer);
    $oneList = $queries('/api/v1/orders');
    $oneDetail = $queries("/api/v1/orders/{$a->order->id}");

    // more orders (each adjusted), more revisions, more lines: the same number of queries
    [$o2, $f2] = anotherPreparedOrder($a, 2, 2);
    [$o3, $f3] = anotherPreparedOrder($a, 1, 3);
    foreach ([[$o2, $f2], [$o3, $f3]] as [$o, $f]) {
        $items = OrderItem::where('order_id', $o->id)->orderBy('id')->get();
        $aa = (object) ['f' => $f];
        postAdjust($aa, [['order_item_id' => $items[0]->id, 'action' => 'remove']])->assertOk();
        postAdjust($aa, [['order_item_id' => $items[1]->id, 'action' => 'reduce', 'new_quantity' => 1]], 'damaged')->assertOk();
    }
    postAdjust($a, [['order_item_id' => $a->l2->id, 'action' => 'reduce', 'new_quantity' => 1]], 'quality')->assertOk();
    actingAsCustomer($a->customer);
    expect($queries('/api/v1/orders'))->toBe($oneList)->and($queries("/api/v1/orders/{$o3->id}"))->toBe($oneDetail)->and($queries("/api/v1/orders/{$a->order->id}"))->toBe($oneDetail);
    expect($this->getJson('/api/v1/orders')->json('data.*.was_adjusted'))->toBe([true, true, true]);
});

it('lets the customer add back only what they received when they order again', function () {
    $a = adjustmentWorld();
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'remove']])->assertOk();
    actingAsCustomer($a->customer);
    $r = $this->postJson("/api/v1/orders/{$a->order->id}/reorder")->assertOk();
    expect(collect($r->json('data.cart.items'))->pluck('variant_id')->all())->toBe([$a->w->v2->id])->and($r->json('data.skipped'))->toBe([]);
});
