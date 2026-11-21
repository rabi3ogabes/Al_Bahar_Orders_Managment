<?php

require_once __DIR__.'/PrintSupport.php';

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Printing\PrintKind;
use App\Domain\Orders\Printing\PrintParts;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;

/*
| Printing several parts in one PDF (docs/guides/PICKING_LIST_AND_DELIVERY_NOTE.md): everything waiting to be prepared, the deliveries of a day and a
| driver, or parts named by id. A page for every part, only the user's own branches, nothing logged for a refused or empty request.
*/

beforeEach(function () {
    $this->travelTo(qatar('2026-11-19 12:00'));
});

// ─── Everything waiting to be prepared ─────────────────────────────────────────────────────────────────────────────────────────

it('prints every part waiting to be prepared in one PDF with a page for each, and leaves out what is not waiting', function () {
    $a = printWorld();                                  // part 1: preparing
    [, $accepted] = anotherPreparedOrder($a);            // part 2: preparing
    $pending = printPlaced($a);                          // part 3: pending (new)
    $ready = printPlaced($a);
    printWalk($a, $ready, ['accepted', 'preparing', 'ready']);                 // ready: not waiting any more
    $cancelled = printPlaced($a);
    printWalk($a, $cancelled, ['cancelled']);                                 // cancelled: nothing to prepare

    $r = printBatch($a->mgr, 'picking')->assertOk();
    $text = printText($r->getContent());

    expect($r->headers->get('content-disposition'))->toContain('picking-lists-')->toContain('3-parts.pdf')->and($r->headers->get('x-printed-parts'))->toBe('3')
        ->and(printPages($r->getContent()))->toBe(3);
    foreach ([$a->f, $accepted, $pending] as $part) {
        expect(printHas($text, $part->number))->toBeTrue();
    }
    foreach ([$ready, $cancelled] as $part) {
        expect(printHas($text, $part->number))->toBeFalse();
    }
    expect(printAuditCount($a->f, PrintKind::Picking))->toBe(1)->and(printAuditCount($ready))->toBe(0)->and(printAuditCount($cancelled))->toBe(0);
});

it('does not list an online order that is not paid yet', function () {
    opsSadad();
    $a = printWorld();
    $unpaid = printPlaced($a, 'sadad');     // pending_payment: the customer has not paid

    $r = printBatch($a->mgr, 'picking')->assertOk();

    expect(Order::find($unpaid->order_id)->status->value)->toBe('pending_payment')->and(printHas($r->getContent(), $unpaid->number))->toBeFalse()->and(printHas($r->getContent(), $a->f->number))->toBeTrue();
});

it('orders the pages by the day the customer chose, then by when the order came in', function () {
    $a = printWorld();
    [, $second] = anotherPreparedOrder($a);
    $third = printPlaced($a);
    $a->f->update(['slot_date' => '2026-11-22']);
    $second->update(['slot_date' => '2026-11-20']);
    // $third has no date: after the dated parts

    $text = printText(printBatch($a->mgr, 'picking')->assertOk()->getContent());

    expect(strpos($text, $second->number))->toBeLessThan(strpos($text, $a->f->number))->and(strpos($text, $a->f->number))->toBeLessThan(strpos($text, $third->number));
});

it('narrows to delivery or pickup parts when the screen asks for one kind', function () {
    $a = printWorld();
    actingAsCustomer($a->customer);
    test()->postJson('/api/v1/cart/items', ['variant_id' => $a->w->v1->id, 'quantity' => 1]);
    $pickup = Order::findOrFail(quoteAndPlace(['fulfillment_type' => 'pickup', 'branch_id' => $a->w->a->id, 'payment_method' => 'pay_at_pickup'])->assertCreated()->json('data.order.id'))->fulfillments->first();

    $onlyPickup = printText(printBatch($a->mgr, 'picking', ['type' => 'pickup'])->assertOk()->getContent());
    $onlyDelivery = printText(printBatch($a->mgr, 'picking', ['type' => 'delivery'])->assertOk()->getContent());
    $both = printText(printBatch($a->mgr, 'picking')->assertOk()->getContent());

    expect(printHas($onlyPickup, $pickup->number))->toBeTrue()->and(printHas($onlyPickup, $a->f->number))->toBeFalse()
        ->and(printHas($onlyDelivery, $a->f->number))->toBeTrue()->and(printHas($onlyDelivery, $pickup->number))->toBeFalse()
        ->and(printHas($both, $a->f->number))->toBeTrue()->and(printHas($both, $pickup->number))->toBeTrue();
});

it('prints nothing and logs nothing when no part is waiting', function () {
    $a = printWorld();
    printWalk($a, $a->f, ['ready']);

    printBatch($a->mgr, 'picking')->assertNotFound();

    expect(printAuditTotal())->toBe(0);
});

it('prints a single waiting part as a one-page PDF named after the part', function () {
    $a = printWorld();

    $r = printBatch($a->mgr, 'picking')->assertOk();

    expect(printPages($r->getContent()))->toBe(1)->and($r->headers->get('content-disposition'))->toContain('picking-list-'.$a->f->number.'.pdf');
});

// ─── Branch scope ──────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('only ever covers the user\'s own branches', function () {
    $s = opsSplitOrder();

    $a = printText(printBatch($s->mgrA, 'picking')->assertOk()->getContent());
    $b = printText(printBatch($s->mgrB, 'picking')->assertOk()->getContent());
    $admin = printText(printBatch(User::factory()->superAdmin()->create(), 'picking')->assertOk()->getContent());

    expect(printHas($a, $s->fa->number))->toBeTrue()->and(printHas($a, $s->fb->number))->toBeFalse()
        ->and(printHas($b, $s->fb->number))->toBeTrue()->and(printHas($b, $s->fa->number))->toBeFalse()
        ->and(printHas($admin, $s->fa->number))->toBeTrue()->and(printHas($admin, $s->fb->number))->toBeTrue();
    expect(printAuditCount($s->fa))->toBe(2)->and(printAuditCount($s->fb))->toBe(2); // mgrA + admin; mgrB + admin
});

it('lets the branch filter narrow the batch but never reach a branch the user may not see', function () {
    $s = opsSplitOrder();
    $admin = User::factory()->superAdmin()->create();

    $onlyB = printBatch($admin, 'picking', ['branch' => $s->w->b->id])->assertOk();
    expect(printPages($onlyB->getContent()))->toBe(1)->and(printAuditCount($s->fb))->toBe(1)->and(printAuditCount($s->fa))->toBe(0);

    printBatch($s->mgrA, 'picking', ['branch' => $s->w->b->id])->assertNotFound();   // not their branch: nothing, not "everything"
    printBatch($s->mgrA, 'picking', ['branch' => 'not-a-uuid'])->assertStatus(302);   // refused input: back to the page it came from
    expect(printAuditCount($s->fb))->toBe(1)->and(printAuditCount($s->fa))->toBe(0);   // only the admin's print, and only of branch B
});

it('refuses staff without order access, guests and customers at the door', function () {
    $s = opsSplitOrder();
    $this->seed(RolesAndPermissionsSeeder::class);
    $noOrders = staffWithRole('inventory_manager', $s->w->a);

    printBatch($noOrders, 'picking')->assertForbidden();
    printBatch($noOrders, 'note')->assertForbidden();
    expect(printAuditTotal())->toBe(0);
});

it('refuses the whole request when one named part belongs to another branch, and logs none of them', function () {
    $s = opsSplitOrder();

    printBatch($s->mgrA, 'picking', ['parts' => $s->fa->id.','.$s->fb->id])->assertForbidden();
    printBatch($s->mgrA, 'note', ['parts' => $s->fb->id])->assertForbidden();

    expect(printAuditTotal())->toBe(0);
});

it('prints named parts in the order given and refuses unknown, malformed and too many ids', function () {
    $a = printWorld();
    [, $second] = anotherPreparedOrder($a);
    $text = printText(printBatch($a->mgr, 'picking', ['parts' => $second->id.','.$a->f->id])->assertOk()->getContent());

    expect(strpos($text, $second->number))->toBeLessThan(strpos($text, $a->f->number));

    printBatch($a->mgr, 'picking', ['parts' => $a->f->id.','.Str::uuid()])->assertNotFound();
    printBatch($a->mgr, 'picking', ['parts' => 'abc,def'])->assertNotFound();
    $many = implode(',', array_map(fn () => (string) Str::uuid(), range(1, PrintParts::MAX_PARTS + 1)));
    printBatch($a->mgr, 'picking', ['parts' => $many])->assertStatus(422);
    expect(printAuditCount($a->f))->toBe(1); // only the first request printed
});

it('logs one entry per part with the size of the request, and stamps the second batch as a reprint', function () {
    $a = printWorld();
    [, $second] = anotherPreparedOrder($a);
    printBatch($a->mgr, 'picking')->assertOk();
    $again = printText(printBatch($a->mgr, 'picking')->assertOk()->getContent());

    $entries = AuditLog::whereIn('auditable_id', [$a->f->id, $second->id])->whereIn('action', PrintKind::auditActions())->orderBy('id')->get();
    expect($entries)->toHaveCount(4)->and($entries->pluck('metadata.parts_in_request')->unique()->all())->toBe([2])->and($entries->pluck('metadata.copy')->sort()->values()->all())->toBe([1, 1, 2, 2])
        ->and(printHas($again, 'Reprint'))->toBeTrue();
});

// ─── The deliveries of a day ────────────────────────────────────────────────────────────────────────────────────────────────────

it('prints the delivery notes of one driver for a day, in the order he meets them', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    $mine = driverFor($w->a);
    $other = driverFor($w->a);
    $late = printDelivery($w, $mgr, $mine);
    $early = printDelivery($w, $mgr, $mine);
    $theirs = printDelivery($w, $mgr, $other);
    $nobody = printDelivery($w, $mgr);
    $late->update(['scheduled_for' => qatar('2026-11-19 16:00')]);
    $early->update(['scheduled_for' => qatar('2026-11-19 10:00')]);
    $theirs->update(['scheduled_for' => qatar('2026-11-19 11:00')]);
    $nobody->update(['scheduled_for' => qatar('2026-11-19 11:30')]);

    $r = printBatch($mgr, 'note', ['driver' => $mine->id])->assertOk();
    $text = printText($r->getContent());
    $number = fn (Delivery $d) => $d->fulfillment->number;

    expect(printPages($r->getContent()))->toBe(2)->and($r->headers->get('content-disposition'))->toContain('delivery-notes-')
        ->and(strpos($text, $number($early)))->toBeLessThan(strpos($text, $number($late)))
        ->and(printHas($text, $number($theirs)))->toBeFalse()->and(printHas($text, $number($nobody)))->toBeFalse();

    $everyone = printText(printBatch($mgr, 'note')->assertOk()->getContent());
    $unassigned = printText(printBatch($mgr, 'note', ['driver' => 'none'])->assertOk()->getContent());
    expect(printHas($everyone, $number($theirs)))->toBeTrue()->and(printHas($everyone, $number($nobody)))->toBeTrue()->and(printPages(printBatch($mgr, 'note')->getContent()))->toBe(4)
        ->and(printHas($unassigned, $number($nobody)))->toBeTrue()->and(printHas($unassigned, $number($early)))->toBeFalse();
});

it('keeps a day to its own deliveries, carries earlier open ones into today, and leaves out finished ones', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    $driver = driverFor($w->a);
    $today = printDelivery($w, $mgr, $driver);
    $tomorrow = printDelivery($w, $mgr, $driver);
    $overdue = printDelivery($w, $mgr, $driver);
    $done = printDelivery($w, $mgr, $driver);
    $today->update(['scheduled_for' => qatar('2026-11-19 15:00')]);
    $tomorrow->update(['scheduled_for' => qatar('2026-11-20 15:00')]);
    $overdue->update(['scheduled_for' => qatar('2026-11-18 15:00')]);
    $done->update(['scheduled_for' => qatar('2026-11-19 09:00'), 'status' => 'delivered']);
    $number = fn (Delivery $d) => $d->fulfillment->number;

    $now = printText(printBatch($mgr, 'note', ['date' => '2026-11-19'])->assertOk()->getContent());
    $next = printText(printBatch($mgr, 'note', ['date' => '2026-11-20'])->assertOk()->getContent());

    expect(printHas($now, $number($today)))->toBeTrue()->and(printHas($now, $number($overdue)))->toBeTrue()->and(printHas($now, $number($tomorrow)))->toBeFalse()->and(printHas($now, $number($done)))->toBeFalse()
        ->and(printHas($next, $number($tomorrow)))->toBeTrue()->and(printHas($next, $number($today)))->toBeFalse()->and(printHas($next, $number($overdue)))->toBeFalse();
    printBatch($mgr, 'note', ['date' => '2026-12-30'])->assertNotFound();
    printBatch($mgr, 'note', ['date' => 'tomorrow'])->assertStatus(302);
});

it('never covers deliveries of another branch', function () {
    $w = commerceWorld();
    $mgrA = staffWithRole('order_manager', $w->a);
    $mgrB = staffWithRole('order_manager', $w->b);
    $d = printDelivery($w, $mgrA, driverFor($w->a));

    printBatch($mgrB, 'note')->assertNotFound();
    printBatch($mgrB, 'note', ['branch' => $w->a->id])->assertNotFound();
    $ok = printBatch($mgrA, 'note')->assertOk();

    expect(printHas($ok->getContent(), $d->fulfillment->number))->toBeTrue()->and(printAuditCount($d->order_fulfillment_id, PrintKind::Note))->toBe(1);
});

it('lists the drivers with open deliveries for the print window, with how many each has', function () {
    $w = commerceWorld();
    $mgr = staffWithRole('order_manager', $w->a);
    $one = driverFor($w->a);
    $two = driverFor($w->a);
    printDelivery($w, $mgr, $one);
    printDelivery($w, $mgr, $one);
    printDelivery($w, $mgr, $two);
    printDelivery($w, $mgr);

    $drivers = collect(app(PrintParts::class)->driversOn($mgr, '2026-11-19'));

    expect($drivers->firstWhere('id', $one->id)['count'])->toBe(2)->and($drivers->firstWhere('id', $two->id)['count'])->toBe(1)->and($drivers->firstWhere('id', 'none')['count'])->toBe(1)
        ->and($drivers->last()['id'])->toBe('none')                                  // "no driver yet" comes last
        ->and(app(PrintParts::class)->driversOn(staffWithRole('order_manager', $w->b), '2026-11-19'))->toBe([]);
});

// ─── Limits ─────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

it('cuts a batch at the page limit, oldest first', function () {
    $a = printWorld();
    $a->f->forceFill(['created_at' => now()->subDay()])->save();
    $part = $a->f->fresh();
    $order = $a->order->fresh();
    $max = PrintParts::MAX_PARTS;
    for ($i = 1; $i <= $max; $i++) {
        $o = $order->replicate();
        $o->fill(['number' => 'AB9'.str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'idempotency_key' => null])->save();
        $f = $part->replicate();
        $f->fill(['order_id' => $o->id, 'number' => $o->number.'-1', 'status' => 'accepted', 'created_at' => now()])->save();
        foreach ($part->items as $item) {
            $item->replicate()->fill(['order_id' => $o->id, 'order_fulfillment_id' => $f->id])->save();
        }
    }
    $user = User::factory()->superAdmin()->create();

    $parts = app(PrintParts::class)->waitingToPrepare($user);

    expect($parts)->toHaveCount($max)->and(app(PrintParts::class)->countWaiting($user))->toBe($max + 1);
    $started = microtime(true);
    $r = printBatch($user, 'picking')->assertOk();
    $seconds = microtime(true) - $started;

    expect($r->headers->get('x-printed-parts'))->toBe((string) $max)->and(printPages($r->getContent()))->toBe($max)->and(printHas($r->getContent(), $a->f->number))->toBeTrue()
        ->and($parts->first()->id)->toBe($a->f->id)->and($seconds)->toBeLessThan(60); // the oldest part is in, a newer one is cut
});
