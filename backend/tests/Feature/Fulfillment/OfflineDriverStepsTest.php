<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryAttempt;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Fulfillment\Services\DeliveryStepReplay;
use App\Domain\Orders\Models\Order;
use App\Domain\Orders\Models\OrderFulfillment;
use App\Domain\Payments\Models\CashCollection;
use App\Domain\Reports\Services\Metrics\Deliveries;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Operations/OpsSupport.php';

/*
| The driver app's outbox (docs/guides/OFFLINE_DRIVER_AND_STAFF_APPS.md): a step pressed with no signal is sent later, perhaps several times, with the
| key made when the driver pressed (Idempotency-Key) and the time he pressed (acted_at). The second send changes nothing; the times shown everywhere
| are the driver's; an app that sends neither works exactly as before.
*/
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(qatar('2026-11-19 12:00'));
});

/** A delivery walked to $status by its driver (the session is left as the driver), with its step URL and the cash due. */
function offJob(string $status = 'out_for_delivery'): object
{
    $x = deliveryAt($status);
    $x->url = "/api/v1/admin/deliveries/{$x->delivery->id}";
    $x->cod = ['tender' => 'cash', 'amount' => (string) $x->delivery->cod_amount];
    $x->part = OrderFulfillment::findOrFail($x->delivery->order_fulfillment_id);
    $image = UploadedFile::fake()->image('door.jpg', 24, 24);
    $x->photo = (string) file_get_contents((string) $image->getRealPath());

    return $x;
}

/** The same photo every time it is sent (an outbox retry sends the copy it kept). */
function offPhoto(object $x, string $name = 'door.jpg'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $x->photo);
}

function offKey(string $suffix = 'a'): array
{
    return ['Idempotency-Key' => 'outbox-0001-'.$suffix.str_repeat('x', 8)];
}

function offIso(CarbonImmutable $at): string
{
    return $at->toIso8601String();
}

// ── Sent twice, recorded once ─────────────────────────────────────────────────────────────────────────────────────────

it('records a delivery with photo and cash once when the outbox sends it twice, and answers the second send with the first answer', function () {
    $x = offJob();
    $send = fn () => $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod]);

    $first = $send()->assertOk()->assertJsonPath('data.status', 'delivered');
    $first->assertHeaderMissing('Idempotent-Replayed');
    $second = $send()->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.status', 'delivered');

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(DeliveryProof::where('delivery_id', $x->delivery->id)->count())->toBe(1)
        ->and(DeliveryAttempt::where('delivery_id', $x->delivery->id)->count())->toBe(1)
        ->and(CashCollection::where('order_fulfillment_id', $x->part->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'delivery.delivered')->count())->toBe(1)
        ->and(DB::table('idempotency_keys')->where('scope', DeliveryStepReplay::SCOPE)->count())->toBe(1);
});

it('records a failed attempt once even when the branch moved the job on before the lost answer was sent again', function () {
    $x = offJob();
    $body = fn () => ['reason_code' => 'customer_absent', 'photo' => offPhoto($x, 'door-closed.jpg'), 'latitude' => 25.3, 'longitude' => 51.5];
    $this->withHeaders(offKey())->postJson($x->url.'/fail', $body())->assertOk()->assertJsonPath('data.status', 'failed');
    // The branch reschedules the job; the driver's phone never got the first answer and sends the same failure again.
    actingAsStaff($x->mgr);
    $this->postJson($x->url.'/reschedule', ['scheduled_for' => '2026-11-20 10:00'])->assertOk();
    actingAsStaff($x->driver);

    $this->withHeaders(offKey())->postJson($x->url.'/fail', $body())->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.status', 'failed');

    expect(DeliveryAttempt::where('delivery_id', $x->delivery->id)->where('result', 'failed')->count())->toBe(1)
        ->and(DeliveryProof::where('delivery_id', $x->delivery->id)->where('type', 'failed')->count())->toBe(1)
        ->and(CashCollection::where('order_fulfillment_id', $x->part->id)->where('status', 'failed')->count())->toBe(1)
        ->and($x->delivery->fresh()->status->value)->toBe('rescheduled');
});

it('keeps one assembly photo when the same answer with its photo is sent twice', function () {
    $x = offJob();
    DB::table('order_items')->where('order_id', $x->orderId)->update(['assembly_fee' => '150.00', 'assembly_total' => '150.00']);
    $send = fn () => $this->withHeaders(offKey())->postJson($x->url.'/assembly', ['status' => 'done', 'photo' => offPhoto($x, 'assembled.jpg')]);

    $send()->assertOk();
    $send()->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(DeliveryProof::where('delivery_id', $x->delivery->id)->where('type', 'assembly')->count())->toBe(1)
        ->and(AuditLog::where('action', 'delivery.assembly_answered')->count())->toBe(1);
});

it('moves a job on once for a pickup sent twice', function () {
    $x = offJob('accepted');
    $send = fn () => $this->withHeaders(offKey())->postJson($x->url.'/pickup');

    $send()->assertOk()->assertJsonPath('data.status', 'picked_up');
    $history = DB::table('delivery_status_history')->where('delivery_id', $x->delivery->id)->count();
    $orderHistory = DB::table('order_status_history')->where('order_id', $x->orderId)->count();
    $send()->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect(DB::table('delivery_status_history')->where('delivery_id', $x->delivery->id)->count())->toBe($history)
        ->and(DB::table('order_status_history')->where('order_id', $x->orderId)->count())->toBe($orderHistory);
});

it('keeps nothing for a refused step, so the driver can correct it and send it again with the same key', function () {
    $x = offJob();

    $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'cod' => $x->cod])->assertStatus(422)->assertJsonPath('code', 'delivery_proof_required');
    expect(DB::table('idempotency_keys')->count())->toBe(0);

    $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod])->assertOk()
        ->assertHeaderMissing('Idempotent-Replayed')->assertJsonPath('data.status', 'delivered');
});

it('refuses the same key with different details from the outbox, and changes nothing', function () {
    $x = offJob();
    $at = offIso(now()->toImmutable()->subMinutes(5));
    $this->withHeaders(offKey())->postJson($x->url.'/fail', ['reason_code' => 'customer_absent', 'acted_at' => $at])->assertOk();

    $this->withHeaders(offKey())->postJson($x->url.'/fail', ['reason_code' => 'refused', 'acted_at' => $at])->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_conflict')->assertJsonPath('message', 'This update was already sent with different details. Nothing was changed.');

    expect($x->delivery->fresh()->failure_reason)->toBe('customer_absent')->and(DeliveryAttempt::where('delivery_id', $x->delivery->id)->count())->toBe(1);
});

it('says the conflict in Arabic to a driver who reads Arabic', function () {
    $x = offJob();
    $at = offIso(now()->toImmutable()->subMinutes(5));
    $this->withHeaders(offKey())->postJson($x->url.'/fail', ['reason_code' => 'customer_absent', 'acted_at' => $at])->assertOk();

    $this->withHeaders(offKey() + ['Accept-Language' => 'ar'])->postJson($x->url.'/fail', ['reason_code' => 'refused', 'acted_at' => $at])->assertStatus(422)
        ->assertJsonPath('message', 'أُرسل هذا التحديث من قبل بتفاصيل مختلفة. لم يتغيّر شيء.');
});

it('lets an older app (one key per form, no acted_at) retry with edited details under the rules it always had', function () {
    $x = offJob();
    $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod])->assertOk();

    // The driver changed the name after a lost answer and pressed again: as before, the delivered job comes back and nothing is recorded twice.
    $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor A.', 'proof' => offPhoto($x), 'cod' => $x->cod])->assertOk()
        ->assertHeaderMissing('Idempotent-Replayed')->assertJsonPath('data.status', 'delivered');

    expect(DeliveryProof::where('delivery_id', $x->delivery->id)->count())->toBe(1)->and(DeliveryAttempt::where('delivery_id', $x->delivery->id)->count())->toBe(1);
});

it('never hands one driver\'s kept answer to another person who sends the same key', function () {
    $x = offJob();
    $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod])->assertOk();
    actingAsStaff(driverFor($x->w->a));

    $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod])->assertStatus(403)
        ->assertJsonPath('code', 'delivery_not_yours');
});

it('works exactly as before for an app that sends no key and no time', function () {
    $x = offJob();

    $this->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod])->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    $this->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod])->assertOk(); // the old state rule: delivered stays delivered

    $d = $x->delivery->fresh();
    expect($d->delivered_at->equalTo(now()))->toBeTrue()
        ->and(DB::table('idempotency_keys')->count())->toBe(0)
        ->and(AuditLog::where('action', 'delivery.step_sent_late')->count())->toBe(0)
        ->and(DeliveryProof::where('delivery_id', $d->id)->count())->toBe(1);
});

it('refuses a malformed key like every other keyed call', function () {
    $x = offJob('accepted');

    $this->withHeaders(['Idempotency-Key' => 'short'])->postJson($x->url.'/pickup')->assertStatus(400)->assertJsonPath('code', 'idempotency_key_required');
    expect($x->delivery->fresh()->status->value)->toBe('accepted');
});

// ── The time the driver pressed ───────────────────────────────────────────────────────────────────────────────────────

it('records a delivery sent late at the time the driver pressed, everywhere it is shown, and keeps the arrival time in the audit log', function () {
    $this->travelTo(qatar('2026-11-19 08:30')); // the driver took the job in the morning
    $x = offJob();
    $this->travelTo(qatar('2026-11-19 12:00'));
    $pressed = now()->toImmutable()->subMinutes(40);

    $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod, 'acted_at' => offIso($pressed)])->assertOk();

    $d = $x->delivery->fresh();
    $part = $x->part->fresh();
    expect($d->delivered_at->equalTo($pressed))->toBeTrue()
        ->and(DeliveryAttempt::where('delivery_id', $d->id)->firstOrFail()->attempted_at->equalTo($pressed))->toBeTrue()
        ->and(DeliveryProof::where('delivery_id', $d->id)->firstOrFail()->captured_at->equalTo($pressed))->toBeTrue()
        ->and(CashCollection::where('order_fulfillment_id', $part->id)->firstOrFail()->collected_at->equalTo($pressed))->toBeTrue()
        ->and($part->delivered_at->equalTo($pressed))->toBeTrue()
        ->and(Order::findOrFail($x->orderId)->completed_at->equalTo($pressed))->toBeTrue();
    $history = DB::table('delivery_status_history')->where('delivery_id', $d->id)->where('to_status', 'delivered')->value('created_at');
    expect(CarbonImmutable::parse($history)->equalTo($pressed))->toBeTrue();
    $audit = AuditLog::where('action', 'delivery.step_sent_late')->firstOrFail();
    expect($audit->metadata['acted_at'])->toBe($pressed->setTimezone('Asia/Qatar')->toIso8601String())
        ->and($audit->metadata['received_at'])->toBe(qatar('2026-11-19 12:00')->toIso8601String())
        ->and($audit->new_values['step'])->toBe('deliver')
        ->and($audit->created_at->equalTo(now()))->toBeTrue();

    // The customer's proof line and order page say the driver's time too.
    actingAsCustomer($x->customer);
    $this->getJson("/api/v1/orders/{$x->orderId}")->assertOk()->assertJsonPath('data.fulfillments.0.timestamps.delivered_at', $pressed->setTimezone('Asia/Qatar')->toIso8601String());
});

it('records a pickup sent late at the time the driver pressed, on the job and on the parcel', function () {
    $this->travelTo(qatar('2026-11-19 08:30')); // the driver took the job in the morning
    $x = offJob('accepted');
    $this->travelTo(qatar('2026-11-19 12:00'));
    $pressed = now()->toImmutable()->subMinutes(25);

    $this->postJson($x->url.'/pickup', ['acted_at' => offIso($pressed)])->assertOk();

    expect($x->delivery->fresh()->picked_up_at->equalTo($pressed))->toBeTrue()->and($x->part->fresh()->dispatched_at->equalTo($pressed))->toBeTrue();
});

it('records a failed attempt sent late at the time the driver pressed', function () {
    $this->travelTo(qatar('2026-11-19 08:30')); // the driver took the job in the morning
    $x = offJob();
    $this->travelTo(qatar('2026-11-19 12:00'));
    $pressed = now()->toImmutable()->subHours(3);

    $this->postJson($x->url.'/fail', ['reason_code' => 'customer_unreachable', 'acted_at' => offIso($pressed)])->assertOk();

    $d = $x->delivery->fresh();
    expect($d->failed_at->equalTo($pressed))->toBeTrue()
        ->and(DeliveryAttempt::where('delivery_id', $d->id)->firstOrFail()->attempted_at->equalTo($pressed))->toBeTrue()
        ->and(CashCollection::where('order_fulfillment_id', $x->part->id)->where('status', 'failed')->firstOrFail()->collected_at->equalTo($pressed))->toBeTrue();
});

it('never records a step before the step that came before it (a phone clock a little slow)', function () {
    $x = offJob(); // out for delivery at 12:00
    $before = now()->toImmutable()->subMinutes(10);

    $this->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod, 'acted_at' => offIso($before)])->assertOk();

    $d = $x->delivery->fresh();
    expect($d->delivered_at->equalTo($d->out_for_delivery_at))->toBeTrue()->and($d->delivered_at->equalTo(qatar('2026-11-19 12:00')))->toBeTrue();
});

it('takes a time a little ahead of the server as now, and refuses one far ahead, too old or unreadable', function (string $at, ?string $code) {
    $x = offJob('accepted');
    $res = $this->postJson($x->url.'/pickup', ['acted_at' => $at]);

    if ($code === null) {
        $res->assertOk();
        expect($x->delivery->fresh()->picked_up_at->equalTo(now()))->toBeTrue();

        return;
    }
    $res->assertStatus(422)->assertJsonPath('code', $code);
    expect($x->delivery->fresh()->status->value)->toBe('accepted')->and($x->delivery->fresh()->picked_up_at)->toBeNull();
})->with([
    'a minute ahead (clock a little fast)' => ['2026-11-19T12:01:00+03:00', null],
    'two minutes ahead, the edge' => ['2026-11-19T09:02:00Z', null],
    'three minutes ahead' => ['2026-11-19T12:03:01+03:00', 'acted_at_in_future'],
    'just under a day old' => ['2026-11-18T12:00:30+03:00', null],
    'a day and a minute old' => ['2026-11-18T11:59:00+03:00', 'acted_at_too_old'],
    'no offset (a local time is ambiguous)' => ['2026-11-19T11:50:00', 'acted_at_invalid'],
    'not a time' => ['yesterday', 'acted_at_invalid'],
]);

it('explains a step that is too old in plain words, in English and Arabic', function () {
    $x = offJob('accepted');
    $old = ['acted_at' => '2026-11-17T12:00:00+03:00'];

    $this->postJson($x->url.'/pickup', $old)->assertStatus(422)
        ->assertJsonPath('message', 'This update is more than 24 hours old, so it was not recorded. Call the branch so they can record it.');
    $this->withHeaders(['Accept-Language' => 'ar'])->postJson($x->url.'/pickup', $old)->assertStatus(422)
        ->assertJsonPath('message', 'مضى على هذا التحديث أكثر من 24 ساعة، لذلك لم يُسجَّل. اتصل بالفرع ليسجّلوه.');
});

it('answers a step that was recorded with its first answer even when its time has grown too old since', function () {
    $x = offJob('accepted');
    $body = ['acted_at' => offIso(now()->toImmutable()->subHours(23))];
    $this->withHeaders(offKey())->postJson($x->url.'/pickup', $body)->assertOk();
    $this->travel(2)->hours();

    $this->withHeaders(offKey())->postJson($x->url.'/pickup', $body)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
});

it('counts a delivery made before midnight on the promised day as on time, even when the phone sent it after midnight', function () {
    $this->travelTo(qatar('2026-11-19 21:30'));
    $x = offJob();
    OrderFulfillment::whereKey($x->part->id)->update(['slot_date' => '2026-11-19']);
    $this->travelTo(qatar('2026-11-20 00:10'));

    $this->withHeaders(offKey())->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'proof' => offPhoto($x), 'cod' => $x->cod, 'acted_at' => '2026-11-19T23:50:00+03:00'])->assertOk();

    $day = Deliveries::promises([$x->part->branch_id], qatar('2026-11-19 00:00'), qatar('2026-11-19 23:59:59'));
    expect($day[$x->part->branch_id])->toBe(['delivered' => 1, 'promised' => 1, 'on_time' => 1, 'moved' => 0])
        ->and(Deliveries::promises([$x->part->branch_id], qatar('2026-11-20 00:00'), qatar('2026-11-20 23:59:59')))->toBe([]);
});

it('gives the driver the branch\'s number with each job, for "Call the branch" when a step sent later is refused', function () {
    $x = offJob('accepted');
    $x->w->a->update(['phone' => '+97444001122']);

    $this->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->assertJsonPath('data.0.branch.phone', '+97444001122');
});

// ── Clean-up ──────────────────────────────────────────────────────────────────────────────────────────────────────────

it('forgets the keys of steps sent more than a week ago, and keeps the recent ones and other kinds of keys', function () {
    $x = offJob('accepted');
    $this->withHeaders(offKey())->postJson($x->url.'/pickup')->assertOk();
    DB::table('idempotency_keys')->insert(['id' => (string) Str::uuid7(), 'user_id' => $x->driver->id, 'scope' => DeliveryStepReplay::SCOPE, 'key' => 'old-step-key-0000000',
        'request_hash' => str_repeat('0', 64), 'created_at' => now()->subDays(8), 'updated_at' => now()->subDays(8)]);
    DB::table('idempotency_keys')->insert(['id' => (string) Str::uuid7(), 'user_id' => $x->driver->id, 'scope' => 'refund', 'key' => 'old-refund-key-00000',
        'request_hash' => str_repeat('0', 64), 'created_at' => now()->subDays(30), 'updated_at' => now()->subDays(30)]);

    Artisan::call('deliveries:prune-step-keys');
    Artisan::call('deliveries:prune-step-keys'); // a second run finds nothing more

    expect(DB::table('idempotency_keys')->orderBy('key')->pluck('key')->all())->toBe(['old-refund-key-00000', offKey()['Idempotency-Key']]);
});

it('leaves the dashboard\'s own delivery actions as they were: no key kept, no time read', function () {
    $x = offJob('accepted');
    actingAsStaff($x->mgr);

    $this->withHeaders(offKey())->postJson($x->url.'/cancel', ['reason' => 'Customer moved the date', 'acted_at' => 'not read here'])->assertOk()->assertJsonPath('data.status', 'cancelled');
    expect(DB::table('idempotency_keys')->count())->toBe(0)->and(AuditLog::where('action', 'delivery.step_sent_late')->count())->toBe(0);
});
