<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Events\DriverJobUpdated;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryAttempt;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Fulfillment\Models\DeliveryStatusHistory;
use App\Domain\Fulfillment\Services\DeliveryService;
use App\Domain\Payments\Models\CashCollection;
use App\Livewire\Admin\Sales\DeliveryIndex;
use App\Support\DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/../Orders/OpsFixtures.php';

/** The statuses a delivery went through, oldest first, as "from>to" ("-" when it had none). */
function opsHistory(Delivery $d): array
{
    return DeliveryStatusHistory::where('delivery_id', $d->id)->orderBy('id')->get()->map(fn ($h) => ($h->from_status ?? '-').'>'.$h->to_status)->all();
}

/** A delivery walked to out_for_delivery by its driver; the session is left as the driver. */
function opsOnTheRoad(): object
{
    $d = opsAssignedDelivery('accepted');
    actingAsStaff($d->driver);
    foreach (['pickup', 'out-for-delivery'] as $step) {
        test()->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/{$step}")->assertOk();
    }

    return $d;
}

// ── Bug 5a: (re)assigning a driver ────────────────────────────────────────────────────────────────────────────────────────────

it('re-assigns an assigned job: one history row, one audit row, the old driver is told it is gone and the new one gets it', function () {
    $d = opsAssignedDelivery();
    $second = driverFor($d->w->a);
    opsPhone($d->driver, 'old', 'en');
    $pushes = opsFakeFcm();
    Event::fake([DriverJobUpdated::class]);

    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$d->f->id}/assign", ['driver_id' => $second->id])->assertOk()
        ->assertJsonPath('data.status', 'assigned')->assertJsonPath('data.driver.id', $second->id);

    $delivery = $d->delivery->fresh();
    expect($delivery->driver_id)->toBe($second->id)->and(opsHistory($delivery))->toBe(['->pending', 'pending>assigned', 'assigned>assigned'])
        ->and(DeliveryStatusHistory::where('delivery_id', $delivery->id)->orderByDesc('id')->value('note'))->toContain('reassigned to '.$second->name);
    $audit = AuditLog::where('action', 'delivery.assigned')->where('auditable_id', $delivery->id)->orderByDesc('id')->firstOrFail();
    expect($audit->old_values['driver_id'])->toBe($d->driver->id)->and($audit->new_values['driver_id'])->toBe($second->id)->and($audit->actor_id)->toBe($d->mgr->id);

    $heard = collect(Event::dispatched(DriverJobUpdated::class))->map(fn ($a) => $a[0])->keyBy('driverId');
    expect($heard[$second->id]->broadcastWith())->toMatchArray(['assigned' => true, 'status' => 'assigned'])
        ->and($heard[$d->driver->id]->broadcastWith())->toMatchArray(['assigned' => false, 'status' => 'unassigned']);
    expect($pushes)->toHaveCount(1)->and($pushes[0][0])->toBe('old')->and($pushes[0][2])->toContain($d->order->number)->toContain('another driver');
});

it('re-assigns a job the first driver had already accepted, through the pool (accepted > pending > assigned)', function () {
    $d = opsAssignedDelivery('accepted');
    $second = driverFor($d->w->a);

    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$d->f->id}/assign", ['driver_id' => $second->id])->assertOk()->assertJsonPath('data.status', 'assigned');
    $delivery = $d->delivery->fresh();
    expect($delivery->driver_id)->toBe($second->id)->and(opsHistory($delivery))->toBe(['->pending', 'pending>assigned', 'assigned>accepted', 'accepted>pending', 'pending>assigned']);

    // The new driver can go on with it; the old one cannot.
    actingAsStaff($second);
    $this->postJson("/api/v1/admin/deliveries/{$delivery->id}/accept")->assertOk()->assertJsonPath('data.status', 'accepted');
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$delivery->id}/pickup")->assertForbidden();
});

it('works from the dispatch board\'s "Reassign" button on an accepted job', function () {
    $d = opsAssignedDelivery('accepted');
    $second = driverFor($d->w->a);
    $this->actingAs($d->mgr);
    Livewire::test(DeliveryIndex::class)->set('lane', 'scheduled')->call('open', 'assign', $d->delivery->id)->set('form.driver_id', $second->id)->call('confirmDialog')->assertHasNoErrors();
    expect($d->delivery->fresh()->driver_id)->toBe($second->id)->and($d->delivery->fresh()->status->value)->toBe('assigned');
});

it('does nothing and tells nobody when the same driver is chosen again', function () {
    $d = opsAssignedDelivery();
    $pushes = opsFakeFcm();
    opsPhone($d->driver, 'drv', 'en');
    $audits = AuditLog::where('action', 'delivery.assigned')->count();

    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$d->f->id}/assign", ['driver_id' => $d->driver->id])->assertOk()->assertJsonPath('data.status', 'assigned');
    expect(opsHistory($d->delivery->fresh()))->toBe(['->pending', 'pending>assigned'])->and(AuditLog::where('action', 'delivery.assigned')->count())->toBe($audits)->and($pushes)->toHaveCount(0);
});

it('never re-assigns a job whose goods are on the road, and leaves the driver as he was', function () {
    $d = opsOnTheRoad();
    $second = driverFor($d->w->a);
    actingAsStaff($d->mgr);
    foreach ([['picked_up', 'dispatched'], ['out_for_delivery', 'out_for_delivery']] as $i => [$deliveryStatus]) {
        if ($i === 1) {
            actingAsStaff($d->driver);
            $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/out-for-delivery")->assertOk();
            actingAsStaff($d->mgr);
        }
        $this->postJson("/api/v1/admin/dispatch/fulfillments/{$d->f->id}/assign", ['driver_id' => $second->id])->assertStatus(409)->assertJsonPath('code', 'invalid_state_transition');
        $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/assign", ['driver_id' => $second->id])->assertStatus(409)->assertJsonPath('code', 'invalid_state_transition');
        expect($d->delivery->fresh()->driver_id)->toBe($d->driver->id)->and($d->delivery->fresh()->status->value)->not->toBe('assigned');
    }
});

it('checks the new driver before changing anything', function () {
    $d = opsAssignedDelivery();
    $outsider = driverFor($d->w->b); // a driver of the other branch
    $before = opsHistory($d->delivery);
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$d->f->id}/assign", ['driver_id' => $outsider->id])->assertStatus(422)->assertJsonPath('code', 'driver_not_eligible');
    expect($d->delivery->fresh()->driver_id)->toBe($d->driver->id)->and(opsHistory($d->delivery->fresh()))->toBe($before);
});

it('keeps drivers and other branches out of the assignment', function () {
    $d = opsAssignedDelivery();
    $second = driverFor($d->w->a);
    actingAsStaff($d->driver); // a driver has deliveries.edit but not deliveries.assign
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$d->f->id}/assign", ['driver_id' => $second->id])->assertForbidden();
    actingAsStaff(staffWithRole('order_manager', $d->w->b));
    $this->postJson("/api/v1/admin/dispatch/fulfillments/{$d->f->id}/assign", ['driver_id' => $second->id])->assertForbidden();
    expect($d->delivery->fresh()->driver_id)->toBe($d->driver->id);
});

it('refuses to assign a driver to a delivery that was cancelled', function () {
    $d = opsAssignedDelivery();
    app(DeliveryService::class)->cancel($d->delivery, $d->mgr, 'not needed');
    $second = driverFor($d->w->a);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/assign", ['driver_id' => $second->id])->assertStatus(409)->assertJsonPath('code', 'delivery_cancelled');
    expect($d->delivery->fresh()->driver_id)->toBe($d->driver->id);
});

// ── Bug 5b: a repeated call changes nothing ───────────────────────────────────────────────────────────────────────────────────

it('answers a repeated deliver with the same delivery: one attempt, one proof, one collection, one audit row', function () {
    Storage::fake('local');
    $d = opsOnTheRoad();
    $cod = ['tender' => 'cash', 'amount' => $d->order->grand_total];
    $call = fn () => $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/deliver", ['recipient_name' => 'Noor', 'proof' => UploadedFile::fake()->image('door.jpg'), 'cod' => $cod]);

    $call()->assertOk()->assertJsonPath('data.status', 'delivered');
    $call()->assertOk()->assertJsonPath('data.status', 'delivered');
    $call()->assertOk();

    $delivery = $d->delivery->fresh();
    expect($delivery->attempt_count)->toBe(1)->and(DeliveryAttempt::where('delivery_id', $delivery->id)->count())->toBe(1)->and(DeliveryProof::where('delivery_id', $delivery->id)->count())->toBe(1)
        ->and(CashCollection::where('order_fulfillment_id', $d->f->id)->count())->toBe(1)->and(AuditLog::where('action', 'delivery.delivered')->count())->toBe(1)
        ->and(DeliveryStatusHistory::where('delivery_id', $delivery->id)->where('to_status', 'delivered')->count())->toBe(1)
        ->and($d->order->fresh()->status->value)->toBe('completed')->and($d->order->fresh()->paid_total)->toBe($d->order->grand_total);
});

it('answers a repeated failure with the same reason without a second attempt or a second failed collection', function () {
    $d = opsOnTheRoad();
    $fail = fn (string $reason) => $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/fail", ['reason_code' => $reason, 'notes' => 'rang twice']);

    $fail('customer_absent')->assertOk()->assertJsonPath('data.status', 'failed');
    $fail('customer_absent')->assertOk()->assertJsonPath('data.status', 'failed');
    $delivery = $d->delivery->fresh();
    expect($delivery->attempt_count)->toBe(1)->and(DeliveryAttempt::where('delivery_id', $delivery->id)->count())->toBe(1)
        ->and(CashCollection::where('order_fulfillment_id', $d->f->id)->where('status', 'failed')->count())->toBe(1)
        ->and(DeliveryStatusHistory::where('delivery_id', $delivery->id)->where('to_status', 'failed')->count())->toBe(1);

    // Another reason for a delivery that already failed is not a retry: it is refused like any other step that is not allowed.
    $fail('wrong_address')->assertStatus(409)->assertJsonPath('code', 'invalid_state_transition');
    expect($delivery->fresh()->failure_reason)->toBe('customer_absent')->and($delivery->fresh()->attempt_count)->toBe(1);
});

it('repeats of accept, pick-up and out-for-delivery change nothing either', function () {
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);
    foreach (['accept', 'accept', 'pickup', 'pickup', 'out-for-delivery', 'out-for-delivery'] as $step) {
        $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/{$step}")->assertOk();
    }
    expect(opsHistory($d->delivery->fresh()))->toBe(['->pending', 'pending>assigned', 'assigned>accepted', 'accepted>picked_up', 'picked_up>out_for_delivery'])
        ->and(AuditLog::where('action', 'delivery.accepted')->count())->toBe(1);
});

it('does not let a wrong amount or a missing proof leave half a delivery behind', function () {
    Storage::fake('local');
    $d = opsOnTheRoad();
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/deliver", ['proof' => UploadedFile::fake()->image('door.jpg'), 'cod' => ['tender' => 'cash', 'amount' => '1.00']])->assertStatus(422);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/deliver", ['cod' => ['tender' => 'cash', 'amount' => $d->order->grand_total]])->assertStatus(422)->assertJsonPath('code', 'delivery_proof_required');
    $delivery = $d->delivery->fresh();
    expect($delivery->status->value)->toBe('out_for_delivery')->and(DeliveryAttempt::count())->toBe(0)->and(DeliveryProof::count())->toBe(0)->and(CashCollection::count())->toBe(0);
});

// ── Bug 5c: cancel and return are scoped to the job's driver or the branch's staff ─────────────────────────────────────

it('lets only the driver who has the job, or branch staff, cancel it, and writes an audit entry with the reason', function () {
    $d = opsAssignedDelivery();
    $otherDriver = driverFor($d->w->a);
    actingAsStaff($otherDriver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/cancel", ['reason' => 'nope'])->assertForbidden()->assertJsonPath('code', 'delivery_not_yours')
        ->assertJsonPath('message', __('ops_integrity.errors.delivery_not_yours'));
    actingAsStaff(staffWithRole('order_manager', $d->w->b));
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/cancel", ['reason' => 'nope'])->assertForbidden()->assertJsonPath('code', 'branch_forbidden');
    expect($d->delivery->fresh()->status->value)->toBe('assigned')->and(AuditLog::where('action', 'delivery.cancelled')->count())->toBe(0);

    actingAsStaff($d->mgr);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/cancel", ['reason' => 'customer moved the date'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    $audit = AuditLog::where('action', 'delivery.cancelled')->where('auditable_id', $d->delivery->id)->firstOrFail();
    expect($audit->actor_id)->toBe($d->mgr->id)->and($audit->metadata['reason'])->toBe('customer moved the date')->and($audit->old_values['status'])->toBe('assigned');
    // A repeat is not a second cancellation.
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/cancel", ['reason' => 'customer moved the date'])->assertOk();
    expect(AuditLog::where('action', 'delivery.cancelled')->count())->toBe(1);
});

it('lets the driver of the job cancel his own job', function () {
    $d = opsAssignedDelivery();
    actingAsStaff($d->driver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/cancel", ['reason' => 'vehicle broke down'])->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('lets only the driver who has the job, or branch staff, bring a failed delivery back', function () {
    $d = opsOnTheRoad();
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/fail", ['reason_code' => 'refused'])->assertOk();
    $otherDriver = driverFor($d->w->a);
    actingAsStaff($otherDriver);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/return")->assertForbidden();
    actingAsStaff(staffWithRole('order_manager', $d->w->b));
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/return")->assertForbidden();
    expect($d->delivery->fresh()->status->value)->toBe('failed')->and(stockOf($d->w->a, $d->w->v1)['on_hand'])->toBe(4);

    actingAsStaff($d->mgr);
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/return")->assertOk()->assertJsonPath('data.status', 'returned');
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/return")->assertOk()->assertJsonPath('data.status', 'returned'); // a repeat adds nothing
    expect(stockOf($d->w->a, $d->w->v1)['on_hand'])->toBe(5)->and(AuditLog::where('action', 'delivery.returned')->count())->toBe(1);
});

it('lets the driver of a failed delivery bring it back himself', function () {
    $d = opsOnTheRoad();
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/fail", ['reason_code' => 'refused'])->assertOk();
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/return")->assertOk()->assertJsonPath('data.status', 'returned');
});

// ── Bug 5d: reschedule is one step ───────────────────────────────────────────────────────────────────────────────────────────────

it('leaves the old time in place when the reschedule is refused', function () {
    $d = opsAssignedDelivery(); // assigned: it cannot be rescheduled (only a failed delivery can)
    $before = $d->delivery->fresh()->scheduled_for;
    $when = now()->addDays(3)->toIso8601String();
    expect(fn () => app(DeliveryService::class)->reschedule($d->delivery, $d->mgr, $when))->toThrow(DomainException::class);
    expect($d->delivery->fresh()->scheduled_for)->toEqual($before)->and(opsHistory($d->delivery->fresh()))->toBe(['->pending', 'pending>assigned']);
});

it('reschedules a failed delivery: the new time and the new status together', function () {
    $d = opsOnTheRoad();
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/fail", ['reason_code' => 'customer_absent'])->assertOk();
    actingAsStaff($d->mgr);
    $when = now()->addDays(2)->startOfHour();
    $this->postJson("/api/v1/admin/deliveries/{$d->delivery->id}/reschedule", ['scheduled_for' => $when->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'rescheduled');
    expect($d->delivery->fresh()->scheduled_for->toIso8601String())->toBe($when->utc()->toIso8601String());
});
