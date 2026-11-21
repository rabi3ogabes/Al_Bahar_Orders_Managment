<?php

require_once __DIR__.'/AdjustmentFixtures.php';
require_once __DIR__.'/../Operations/OpsSupport.php';

use App\Domain\Inventory\Enums\MovementType;
use App\Domain\Inventory\Services\InventoryLedger;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Services\NotificationRules;
use App\Domain\Operations\Services\AttentionFeed;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderItem;
use App\Domain\Orders\Models\OrderRevision;
use App\Domain\Orders\Models\OrderStatusHistory;
use App\Domain\Orders\Models\SubstitutionRequest;
use App\Domain\Orders\Services\InvoiceService;
use App\Domain\Orders\Services\SubstitutionClock;
use App\Domain\Orders\Services\SubstitutionService;
use App\Domain\Payments\Models\Refund;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Sales\OrderIndex;
use App\Livewire\Admin\Sales\OrderShow;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
| Substitution consent: the branch replaces an item with another product (or a dearer one), the customer accepts it or has the line removed and refunded,
| and a question nobody answers is settled by the shop's fallback. The replacement is in the order while the question is open and never costs more.
*/

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));   // midday in Qatar: the shop is open, quiet hours are far away
});

/** The branch replaces the sofas (line 1) with another product (600); the customer is signed in again. */
function replaceSofas(object $a, string $price = '600.00', array $extra = []): array
{
    [$p, $v] = substituteFor($a->w, $price);
    actingAsStaff($a->mgr);
    $r = postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 2]], 'out_of_stock', $extra)->assertOk();
    actingAsCustomer($a->customer);

    return [$r, $p, $v, SubstitutionRequest::where('order_id', $a->order->id)->latest('asked_at')->first()];
}

function answerUrl(object $a, SubstitutionRequest $r): string
{
    return "/api/v1/orders/{$a->order->id}/substitutions/{$r->id}";
}

// ─── Asking ─────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('asks the customer when a line is replaced by another product, with the replacement already in the order and never dearer', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);

    expect($q)->not->toBeNull()->and($q->status)->toBe('waiting')->and($q->fallback)->toBe('remove')->and($q->response_minutes)->toBe(30)
        ->and($q->deadline_at->equalTo(qatar('2026-11-19 12:30')))->toBeTrue()
        ->and((float) $q->replacement_total)->toBeLessThanOrEqual((float) $q->original_total)->and($q->original_total)->toBe('2000.00')->and($q->replacement_total)->toBe('1200.00');
    $d = $this->getJson("/api/v1/orders/{$a->order->id}")->assertOk()->json('data');
    $s = $d['substitution_requests'][0];
    expect($d['waiting_for_customer'])->toBeTrue()->and($s['status'])->toBe('waiting')->and($s['can_answer'])->toBeTrue()->and($s['fallback'])->toBe('remove')
        ->and($s['price_difference']['amount'])->toBe('-800.00')->and($s['original']['quantity'])->toBe(2)->and($s['replacement']['quantity'])->toBe(2)
        ->and($s['expires_at'])->toStartWith('2026-11-19T12:30');
    expect(Order::find($a->order->id)->grand_total)->toBeLessThan($a->order->grand_total);   // already cheaper: the customer cannot be charged more
});

it('does not ask for a plain removal or reduction', function () {
    $a = adjustmentWorld();
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'reduce', 'new_quantity' => 1], ['order_item_id' => $a->l2->id, 'action' => 'remove']])->assertOk();

    expect(SubstitutionRequest::count())->toBe(0);
});

it('does not ask when consent is switched off in the settings, and the replacement still goes in', function () {
    app(Settings::class)->set('substitutions.consent_enabled', false);
    $a = adjustmentWorld();
    replaceSofas($a);

    expect(SubstitutionRequest::count())->toBe(0)->and(OrderItem::where('order_id', $a->order->id)->where('adjustment_kind', 'replacement')->count())->toBe(1);
});

it('does not ask for the same product at the same price (another size or colour)', function () {
    $a = adjustmentWorld();
    $variant = $a->w->p1->variants()->first()->replicate();
    $variant->forceFill(['sku' => 'SAME-PRODUCT-2', 'is_default' => false])->save();
    app(InventoryLedger::class)->apply($a->w->a->id, $variant->id, MovementType::OpeningBalance, 5);
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $variant->id, 'quantity' => 2]])->assertOk();

    expect(SubstitutionRequest::where('order_id', $a->order->id)->count())->toBe(0);
});

it('asks when the replacement\'s own price is above what was ordered, though the customer still pays no more', function () {
    $a = adjustmentWorld();
    [, $v] = substituteFor($a->w, '1500.00');
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $a->l1->id, 'action' => 'replace', 'variant_id' => $v->id, 'quantity' => 2]])->assertOk();

    $q = SubstitutionRequest::sole();
    expect($q->replacement_total)->toBe($q->original_total)->and((float) $q->replacement_total - (float) $q->original_total)->toBe(0.0);
});

it('tells the customer in their language and in place of the plain "order updated", and the controls can switch it off', function (string $locale, string $title, string $needle) {
    $a = adjustmentWorld(['locale' => $locale]);
    replaceSofas($a);

    $n = Notification::where('user_id', $a->customer->id)->where('event', 'order.substitution_offer')->sole();
    expect($n->title)->toBe($title)->and($n->body)->toContain($needle)->and($n->body)->toContain($a->order->number)->and($n->body)->not->toContain('{{')
        ->and($n->data['deep_link'])->toBe('albahar://orders/'.$a->order->id)
        ->and(Notification::where('event', 'order.items_changed')->count())->toBe(0);
})->with([
    'english' => ['en', 'Please choose a replacement', 'within 30 minutes'],
    'arabic' => ['ar', 'يرجى اختيار البديل', '30 دقيقة'],
]);

it('falls back to the plain "order updated" when the question message is switched off in the controls', function () {
    $a = adjustmentWorld();
    app(NotificationRules::class)->set('order.substitution_offer', false);
    replaceSofas($a);

    expect(Notification::where('event', 'order.substitution_offer')->count())->toBe(0)->and(Notification::where('event', 'order.items_changed')->count())->toBe(1)
        ->and(SubstitutionRequest::count())->toBe(1);
});

// ─── The answer ─────────────────────────────────────────────────────────────────────────────────────────────────────────

it('keeps the replacement when the customer accepts, once, and refuses the opposite answer afterwards', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);

    $this->postJson(answerUrl($a, $q), ['answer' => 'accept'])->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.order.waiting_for_customer', false);
    $this->postJson(answerUrl($a, $q), ['answer' => 'accept'])->assertOk()->assertJsonPath('data.replayed', true);   // a second tap
    $this->postJson(answerUrl($a, $q), ['answer' => 'decline'])->assertStatus(409)->assertJsonPath('code', 'substitution_already_answered');

    $q = $q->fresh();
    expect($q->status)->toBe('accepted')->and($q->answered_source)->toBe('customer')
        ->and(OrderItem::find($q->replacement_order_item_id)->quantity)->toBe(2)
        ->and(OrderRevision::where('order_id', $a->order->id)->count())->toBe(1)
        ->and(OrderStatusHistory::where('order_id', $a->order->id)->where('kind', 'substitution')->pluck('to_status')->all())->toBe(['substitution_asked', 'substitution_accepted']);
});

it('takes the replacement out and refunds what was paid when the customer says no, through the normal adjustment', function () {
    $a = adjustmentWorld(['payment' => 'sadad']);
    [, $p, , $q] = replaceSofas($a);

    $this->postJson(answerUrl($a, $q), ['answer' => 'decline'])->assertOk()->assertJsonPath('data.status', 'declined');
    $this->postJson(answerUrl($a, $q), ['answer' => 'decline'])->assertOk()->assertJsonPath('data.replayed', true);

    $q = $q->fresh();
    $rev = OrderRevision::find($q->resolution_revision_id);
    expect($q->status)->toBe('declined')->and($rev->reason_code)->toBe('substitute_declined')->and($rev->actor_id)->toBe($a->customer->id)
        ->and(OrderItem::find($q->replacement_order_item_id)->quantity)->toBe(0)->and($rev->refund_id)->not->toBeNull()
        ->and(Refund::where('order_id', $a->order->id)->where('reason_code', 'order_adjustment')->count())->toBeGreaterThan(0)
        ->and(Notification::where('event', 'order.items_changed')->where('user_id', $a->customer->id)->latest('created_at')->first()->body)->toContain('You did not accept the replacement');
});

it('lets the customer say no when the replacement is the only thing left in the order: the order is cancelled and refunded', function () {
    $a = adjustmentWorld(['payment' => 'sadad', 'qty' => [2, 0]]);
    [, , , $q] = replaceSofas($a);

    // Taking the last line away ends the order, which is a manager's decision for the branch, but here it is the customer's own answer (or the clock's).
    $this->postJson(answerUrl($a, $q), ['answer' => 'decline'])->assertOk()->assertJsonPath('data.status', 'declined');

    $q = $q->fresh();
    $rev = OrderRevision::find($q->resolution_revision_id);
    expect($q->status)->toBe('declined')->and($rev->reason_code)->toBe('substitute_declined')->and($rev->actor_id)->toBe($a->customer->id)
        ->and(OrderItem::find($q->replacement_order_item_id)->quantity)->toBe(0)->and($rev->refund_id)->not->toBeNull()
        ->and(Order::find($a->order->id)->status->value)->toBe('cancelled');
});

it('lets only the order\'s owner answer, and answers another order\'s question with not found', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);

    actingAsCustomer();
    $this->postJson(answerUrl($a, $q), ['answer' => 'accept'])->assertNotFound();
    $this->postJson(answerUrl($a, $q), ['answer' => 'maybe'])->assertStatus(422);
    actingAsCustomer($a->customer);
    $this->postJson("/api/v1/orders/{$a->order->id}/substitutions/".Str::uuid(), ['answer' => 'accept'])->assertNotFound();
    $this->postJson(answerUrl($a, $q), [])->assertStatus(422);
    expect($q->fresh()->status)->toBe('waiting');
});

it('refuses an answer after the time ran out and settles the question by the fallback then', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    $this->travelTo(qatar('2026-11-19 12:31'));

    $this->postJson(answerUrl($a, $q), ['answer' => 'accept'])->assertStatus(409)->assertJsonPath('code', 'substitution_expired');
    expect($q->fresh()->status)->toBe('expired_removed')->and(OrderItem::find($q->replacement_order_item_id)->quantity)->toBe(0);
});

it('does not charge more than the customer agreed to: a line that grew since is not accepted', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    OrderItem::whereKey($q->replacement_order_item_id)->update(['line_total' => '1500.00']);

    $this->postJson(answerUrl($a, $q), ['answer' => 'accept'])->assertStatus(409)->assertJsonPath('code', 'substitution_closed');
    expect($q->fresh()->status)->toBe('cancelled');
});

// ─── Running out of time ────────────────────────────────────────────────────────────────────────────────────────────────

it('settles an unanswered question once its time is up: the default removes the line and refunds', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    $svc = app(SubstitutionService::class);

    expect($svc->expireDue())->toBe(0);                                    // not yet
    $this->travelTo(qatar('2026-11-19 12:31'));
    expect($svc->expireDue())->toBe(1)->and($svc->expireDue())->toBe(0);    // once
    $q = $q->fresh();
    expect($q->status)->toBe('expired_removed')->and(OrderRevision::find($q->resolution_revision_id)->reason_code)->toBe('substitute_expired')
        ->and(OrderRevision::find($q->resolution_revision_id)->actor_id)->toBeNull()->and(OrderItem::find($q->replacement_order_item_id)->quantity)->toBe(0);
});

it('keeps the replacement at no extra cost when the shop\'s fallback is "keep", and tells the customer', function () {
    app(Settings::class)->set('substitutions.fallback', 'keep');
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    $this->travelTo(qatar('2026-11-19 13:05'));
    app(SubstitutionService::class)->expireDue();

    $q = $q->fresh();
    expect($q->fallback)->toBe('keep')->and($q->status)->toBe('expired_kept')->and(OrderItem::find($q->replacement_order_item_id)->quantity)->toBe(2)
        ->and(Notification::where('event', 'order.substitution_kept')->where('user_id', $a->customer->id)->count())->toBe(1);
});

it('counts the branch\'s opening hours: a question asked just before closing runs into the next morning', function () {
    $a = adjustmentWorld();
    $clock = app(SubstitutionClock::class);

    $late = $clock->deadline($a->w->a->id, qatar('2026-11-19 21:50'), 30);   // the shop closes at 22:00
    expect($late->setTimezone('Asia/Qatar')->format('Y-m-d H:i'))->toBe('2026-11-20 14:20');   // 10 minutes tonight, 20 after it reopens on Friday at 14:00
    $day = $clock->deadline($a->w->a->id, qatar('2026-11-19 12:00'), 30);
    expect($day->setTimezone('Asia/Qatar')->format('H:i'))->toBe('12:30');
});

it('closes the question when the branch changes the replacement line again', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    actingAsStaff($a->mgr);
    postAdjust($a, [['order_item_id' => $q->replacement_order_item_id, 'action' => 'reduce', 'new_quantity' => 1]])->assertOk();

    expect($q->fresh()->status)->toBe('cancelled');
});

it('closes the question without touching the order when its parcel is no longer being prepared', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    $a->f->forceFill(['status' => 'cancelled'])->save();
    $this->travelTo(qatar('2026-11-19 12:40'));
    app(SubstitutionService::class)->expireDue();

    expect($q->fresh()->status)->toBe('cancelled')->and(OrderRevision::where('order_id', $a->order->id)->count())->toBe(1);
});

// ─── The branch waits, and can keep preparing ───────────────────────────────────────────────────────────────────────────

it('keeps the part from being marked ready while the customer has not answered, and lets it go afterwards', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    actingAsStaff($a->mgr);

    $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'ready'])->assertStatus(409)->assertJsonPath('code', 'substitution_pending');
    $row = collect($this->getJson('/api/v1/admin/dispatch/queue')->assertOk()->json('data'))->firstWhere('fulfillment_id', $a->f->id);
    expect($row['waiting_for_customer'])->toMatchArray(['count' => 1])->and($row['waiting_for_customer']['deadline_at'])->toStartWith('2026-11-19T12:30');

    actingAsCustomer($a->customer);
    $this->postJson(answerUrl($a, $q), ['answer' => 'accept'])->assertOk();
    actingAsStaff($a->mgr);
    $this->postJson("/api/v1/admin/fulfillments/{$a->f->id}/transition", ['status' => 'ready'])->assertOk();
    $row = collect($this->getJson('/api/v1/admin/dispatch/queue?stage=ready')->json('data'))->firstWhere('fulfillment_id', $a->f->id);
    expect($row['waiting_for_customer'] ?? null)->toBeNull();
});

it('shows "Waiting for the customer" with its clock on the order list and the order window, and in Needs attention near the deadline', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    $mgr = $a->mgr;
    $feed = fn () => app(AttentionFeed::class)->for($mgr)->items->where('category', 'substitution')->values();

    $this->actingAs($mgr);
    expect(Livewire::test(OrderIndex::class)->viewData('customerWaits')->all())->toHaveKey($a->order->id);
    expect($feed())->toHaveCount(0);                                         // just asked
    $this->travelTo(qatar('2026-11-19 12:16'));
    $rows = $feed();
    expect($rows)->toHaveCount(1)->and($rows[0]->state)->toBe('amber')->and($rows[0]->id)->toBe('substitution:'.$q->id)->and($rows[0]->call)->not->toBeNull();
    $this->travelTo(qatar('2026-11-19 12:31'));
    expect($feed()[0]->state)->toBe('red');

    Livewire::test(OrderShow::class, ['order' => $a->order->fresh()])->assertSee(__('substitutions.waiting'))->assertSee(__('substitutions.keep_preparing'));
});

it('records the answer on the revised invoice', function () {
    $a = adjustmentWorld();
    [, , , $q] = replaceSofas($a);
    $this->postJson(answerUrl($a, $q), ['answer' => 'accept'])->assertOk();

    $order = Order::find($a->order->id);
    $invoice = app(InvoiceService::class)->issue($order);
    expect($invoice->snapshot['answers'][0])->toMatchArray(['status' => 'accepted', 'original_en' => $a->l1->name_en]);
    $this->get("/api/v1/orders/{$a->order->id}/invoice")->assertOk()->assertHeader('Content-Type', 'application/pdf');   // the document builds with the answer on it
});
