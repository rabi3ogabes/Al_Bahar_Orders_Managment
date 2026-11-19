<?php

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Fulfillment\Models\DeliveryAttempt;
use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Operations\Services\DeliveryProofs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Proof of delivery with the driver's position, and the failed-delivery kit: reasons, a required note, a photo of the door. */
beforeEach(fn () => Storage::fake('local'));

function theRoad(): object
{
    $x = deliveryAt('out_for_delivery');
    $x->url = "/api/v1/admin/deliveries/{$x->delivery->id}";
    $x->cod = ['tender' => 'cash', 'amount' => (string) $x->delivery->cod_amount];

    return $x;
}

function deliverBody(object $x, array $extra = []): array
{
    return ['recipient_name' => 'Noor', 'proof' => UploadedFile::fake()->image('door.jpg'), 'cod' => $x->cod] + $extra;
}

// ── Proof with a position ─────────────────────────────────────────────────────────────────────────────────────────────

it('keeps where the driver stood with the proof photo', function () {
    $x = theRoad();

    $this->postJson($x->url.'/deliver', deliverBody($x, ['latitude' => 25.30123, 'longitude' => 51.49876, 'accuracy' => 7]))->assertOk()->assertJsonPath('data.status', 'delivered');

    $proof = DeliveryProof::where('delivery_id', $x->delivery->id)->firstOrFail();
    expect($proof->type)->toBe('photo')->and($proof->latitude)->toBe(25.30123)->and($proof->longitude)->toBe(51.49876)->and($proof->accuracy_m)->toBe(7)
        ->and($proof->recipient_name)->toBe('Noor');
    $history = DB::table('delivery_status_history')->where('delivery_id', $x->delivery->id)->where('to_status', 'delivered')->first();
    expect((float) $history->latitude)->toBe(25.30123);
});

it('delivers just as well without a position: GPS is never needed', function () {
    $x = theRoad();

    $this->postJson($x->url.'/deliver', deliverBody($x))->assertOk()->assertJsonPath('data.status', 'delivered');

    $proof = DeliveryProof::where('delivery_id', $x->delivery->id)->firstOrFail();
    expect($proof->latitude)->toBeNull()->and($proof->longitude)->toBeNull()->and($proof->accuracy_m)->toBeNull();
});

it('refuses a position that cannot exist, and leaves the delivery on the road', function (array $gps) {
    $x = theRoad();

    $this->postJson($x->url.'/deliver', deliverBody($x, $gps))->assertStatus(422);

    expect($x->delivery->fresh()->status->value)->toBe('out_for_delivery')->and(DeliveryProof::where('delivery_id', $x->delivery->id)->count())->toBe(0);
})->with([
    'latitude above 90' => [['latitude' => 90.5, 'longitude' => 51.5]],
    'latitude below -90' => [['latitude' => -91, 'longitude' => 51.5]],
    'longitude above 180' => [['latitude' => 25.3, 'longitude' => 180.2]],
    'longitude below -180' => [['latitude' => 25.3, 'longitude' => -181]],
    'a latitude with no longitude' => [['latitude' => 25.3]],
    'a longitude with no latitude' => [['longitude' => 51.5]],
    'a negative accuracy' => [['latitude' => 25.3, 'longitude' => 51.5, 'accuracy' => -4]],
    'text instead of numbers' => [['latitude' => 'here', 'longitude' => 'there']],
]);

it('ignores the "no fix" position 0,0 instead of storing it', function () {
    $x = theRoad();

    $this->postJson($x->url.'/deliver', deliverBody($x, ['latitude' => 0, 'longitude' => 0]))->assertOk();

    expect(DeliveryProof::where('delivery_id', $x->delivery->id)->value('latitude'))->toBeNull();
});

it('keeps the position even when the delivery needs no photo', function () {
    $x = theRoad();
    Delivery::whereKey($x->delivery->id)->update(['requires_proof' => false]);

    $this->postJson($x->url.'/deliver', ['recipient_name' => 'Noor', 'cod' => $x->cod, 'latitude' => 25.3, 'longitude' => 51.5])->assertOk();

    $row = DeliveryProof::where('delivery_id', $x->delivery->id)->firstOrFail();
    expect($row->type)->toBe('location')->and($row->path)->toBeNull()->and($row->latitude)->toBe(25.3);
});

it('does not count a repeated deliver twice, GPS or not', function () {
    $x = theRoad();
    $call = fn () => $this->postJson($x->url.'/deliver', deliverBody($x, ['latitude' => 25.3, 'longitude' => 51.5]));

    $call()->assertOk();
    $call()->assertOk();

    expect(DeliveryProof::where('delivery_id', $x->delivery->id)->count())->toBe(1);
});

// ── The dashboard viewer ──────────────────────────────────────────────────────────────────────────────────────────────

function viewerFor(object $x): array
{
    return app(DeliveryProofs::class)->for($x->delivery->fresh(), $x->mgr);
}

it('shows the position saved with the proof before the driver\'s trail', function () {
    $x = theRoad();
    $this->postJson($x->url.'/deliver', deliverBody($x, ['latitude' => 25.30123, 'longitude' => 51.49876, 'accuracy' => 9]))->assertOk();
    DB::table('driver_locations')->insert(['driver_id' => $x->driver->id, 'delivery_id' => $x->delivery->id, 'lat' => 25.9, 'lng' => 51.9, 'recorded_at' => now()]);

    $gps = viewerFor($x)['gps'];

    expect($gps['lat'])->toBe(25.30123)->and($gps['lng'])->toBe(51.49876)->and($gps['accuracy_m'])->toBe(9)->and($gps['map_url'])->toContain('25.30123,51.49876');
    actingAsStaff($x->mgr);
    $html = Blade::render('<x-admin.sales.delivery-proof :delivery="$delivery" />', ['delivery' => $x->delivery->fresh()]);
    expect($html)->toContain('25.30123, 51.49876')->toContain('about 9 m')->not->toContain('25.90000');
});

it('falls back to the driver\'s trail for a delivery made without a position, and says "Not recorded" when there is nothing', function () {
    $x = theRoad();
    $this->postJson($x->url.'/deliver', deliverBody($x))->assertOk();
    actingAsStaff($x->mgr);
    $card = fn () => Blade::render('<x-admin.sales.delivery-proof :delivery="$delivery" />', ['delivery' => $x->delivery->fresh()]);

    DB::table('delivery_status_history')->where('delivery_id', $x->delivery->id)->update(['latitude' => null, 'longitude' => null]);
    DB::table('driver_locations')->where('delivery_id', $x->delivery->id)->delete();
    expect($card())->toContain('Not recorded');

    DB::table('driver_locations')->insert(['driver_id' => $x->driver->id, 'delivery_id' => $x->delivery->id, 'lat' => 25.286106, 'lng' => 51.534817, 'recorded_at' => now()->subMinute()]);
    expect($card())->toContain('25.28611, 51.53482')->not->toContain('Not recorded');
});

// ── Could not deliver ─────────────────────────────────────────────────────────────────────────────────────────────────

it('takes every reason the driver can choose, damaged included', function (string $reason, ?string $note) {
    $x = theRoad();

    $this->postJson($x->url.'/fail', ['reason_code' => $reason] + ($note !== null ? ['notes' => $note] : []))->assertOk()->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.failure_reason', $reason);

    expect(DeliveryAttempt::where('delivery_id', $x->delivery->id)->value('notes'))->toBe($note);
})->with([
    ['customer_unreachable', null], ['customer_absent', null], ['wrong_address', null], ['refused', null], ['access_denied', null],
    ['damaged', 'The box was crushed on the van floor'], ['other', 'The lift is out of order'],
]);

it('needs a note for "other" and for "damaged"', function (string $reason, array $note) {
    $x = theRoad();

    $this->postJson($x->url.'/fail', ['reason_code' => $reason] + $note)->assertStatus(422)->assertJsonValidationErrors('notes');

    expect($x->delivery->fresh()->status->value)->toBe('out_for_delivery')->and(DeliveryAttempt::where('delivery_id', $x->delivery->id)->count())->toBe(0);
})->with([
    'other with none' => ['other', []],
    'other with spaces' => ['other', ['notes' => '    ']],
    'damaged with none' => ['damaged', []],
    'damaged with empty' => ['damaged', ['notes' => '']],
]);

it('refuses a note that is too long for the record', function () {
    $x = theRoad();

    $this->postJson($x->url.'/fail', ['reason_code' => 'other', 'notes' => str_repeat('a', 501)])->assertStatus(422)->assertJsonValidationErrors('notes');
});

it('refuses a reason that is not on the list', function () {
    $x = theRoad();

    $this->postJson($x->url.'/fail', ['reason_code' => 'felt_like_it'])->assertStatus(422)->assertJsonValidationErrors('reason_code');
});

it('keeps a photo of the door or the damage as evidence, in private storage, with where the driver was', function () {
    $x = theRoad();

    $this->postJson($x->url.'/fail', ['reason_code' => 'damaged', 'notes' => 'Corner broken', 'photo' => UploadedFile::fake()->image('damage.jpg'), 'latitude' => 25.3, 'longitude' => 51.5, 'accuracy' => 20])
        ->assertOk()->assertJsonPath('data.status', 'failed');

    $proof = DeliveryProof::where('delivery_id', $x->delivery->id)->firstOrFail();
    expect($proof->type)->toBe('failed')->and($proof->disk)->toBe('local')->and($proof->path)->toStartWith('delivery-proofs/'.$x->delivery->id.'/')
        ->and($proof->latitude)->toBe(25.3)->and($proof->accuracy_m)->toBe(20)->and($proof->captured_by)->toBe($x->driver->id);
    Storage::disk('local')->assertExists($proof->path);
});

it('fails without a photo or a position just as well', function () {
    $x = theRoad();

    $this->postJson($x->url.'/fail', ['reason_code' => 'customer_absent'])->assertOk();

    expect(DeliveryProof::where('delivery_id', $x->delivery->id)->count())->toBe(0);
});

it('refuses a file that is not a photo', function () {
    $x = theRoad();

    $this->postJson($x->url.'/fail', ['reason_code' => 'refused', 'photo' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')])->assertStatus(422)->assertJsonValidationErrors('photo');

    expect($x->delivery->fresh()->status->value)->toBe('out_for_delivery');
});

it('is safe to tap twice: one attempt, one photo, one history row', function () {
    $x = theRoad();
    $call = fn () => $this->postJson($x->url.'/fail', ['reason_code' => 'customer_absent', 'photo' => UploadedFile::fake()->image('door.jpg')]);

    $call()->assertOk();
    $call()->assertOk();

    expect(DeliveryAttempt::where('delivery_id', $x->delivery->id)->count())->toBe(1)->and(DeliveryProof::where('delivery_id', $x->delivery->id)->count())->toBe(1)
        ->and(DB::table('delivery_status_history')->where('delivery_id', $x->delivery->id)->where('to_status', 'failed')->count())->toBe(1);
});

it('does not take the photo of a failed attempt for proof that the order was handed over', function () {
    $x = theRoad();
    $this->postJson($x->url.'/fail', ['reason_code' => 'customer_absent', 'photo' => UploadedFile::fake()->image('door.jpg')])->assertOk();
    $this->postJson($x->url.'/out-for-delivery')->assertOk();

    $this->postJson($x->url.'/deliver', ['cod' => $x->cod])->assertStatus(422)->assertJsonPath('code', 'delivery_proof_required');

    $this->postJson($x->url.'/deliver', deliverBody($x))->assertOk()->assertJsonPath('data.status', 'delivered');
    expect(DeliveryProof::where('delivery_id', $x->delivery->id)->pluck('type')->sort()->values()->all())->toBe(['failed', 'photo']);
});

it('shows the photo of the failed attempt in the viewer, labelled, and takes the delivery\'s own position first', function () {
    $x = theRoad();
    $this->postJson($x->url.'/fail', ['reason_code' => 'customer_absent', 'photo' => UploadedFile::fake()->image('door.jpg'), 'latitude' => 25.3111, 'longitude' => 51.4111])->assertOk();

    $viewer = viewerFor($x);
    expect($viewer['photos'])->toHaveCount(1)->and($viewer['photos'][0]['kind'])->toBe('failed')->and($viewer['gps']['lat'])->toBe(25.3111);

    $this->postJson($x->url.'/out-for-delivery')->assertOk();
    $this->postJson($x->url.'/deliver', deliverBody($x, ['latitude' => 25.3999, 'longitude' => 51.4999]))->assertOk();
    $both = viewerFor($x);
    expect(collect($both['photos'])->pluck('kind')->all())->toBe(['failed', 'delivered'])->and($both['gps']['lat'])->toBe(25.3999);
});
