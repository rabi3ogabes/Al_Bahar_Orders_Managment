<?php

use App\Domain\Fulfillment\Models\DeliveryProof;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

require_once __DIR__.'/../Operations/OpsSupport.php';

/*
| The customer's own view of the proof of delivery (docs/guides/DELIVERY_FOLLOW_UPS.md): when it was delivered, who received it and the photo, privately:
| only the order's customer, a short-lived signed link, never the position or the driver's phone, never cached publicly.
*/
beforeEach(function () {
    Storage::fake('local');
    $this->travelTo(qatar('2026-11-19 12:00'));
});

/** A delivery that the driver finished with a photo and a name; the session is left as the customer. */
function fuDelivered(array $gps = ['latitude' => 25.30123, 'longitude' => 51.49876, 'accuracy' => 7]): object
{
    $x = deliveryAt('out_for_delivery');
    $url = "/api/v1/admin/deliveries/{$x->delivery->id}";
    test()->postJson($url.'/deliver', ['recipient_name' => 'Mariam', 'proof' => UploadedFile::fake()->image('door.jpg'), 'cod' => ['tender' => 'cash', 'amount' => (string) $x->delivery->cod_amount]] + $gps)->assertOk();
    actingAsCustomer($x->customer);

    return $x;
}

function fuProofCard(object $x): array
{
    return test()->getJson("/api/v1/orders/{$x->orderId}")->assertOk()->json('data.fulfillments.0.proof');
}

it('shows the customer when it was delivered, who received it and a private photo link, and nothing about where the driver stood', function () {
    $x = fuDelivered();

    $proof = fuProofCard($x);

    expect($proof)->toHaveKeys(['delivered_at', 'received_by', 'photo'])->and($proof['received_by'])->toBe('Mariam')->and($proof['delivered_at'])->toStartWith('2026-11-19T12:00')
        ->and($proof['photo']['url'])->toContain('/delivery-proof/')->toContain('signature=')->and($proof['photo']['expires_at'])->toStartWith('2026-11-19T12:10');
    $json = json_encode(test()->getJson("/api/v1/orders/{$x->orderId}")->json('data'));
    expect($json)->not->toContain('25.30123')->not->toContain('51.49876')->not->toContain('latitude_proof')->not->toContain('accuracy');
    // the driver's phone is not on a delivered order
    expect(test()->getJson("/api/v1/orders/{$x->orderId}")->json('data.fulfillments.0.delivery.driver_phone'))->toBeNull();
});

it('streams the photo through the signed link, private and never cached by a shared cache', function () {
    $x = fuDelivered();
    $url = fuProofCard($x)['photo']['url'];

    $r = $this->get($url)->assertOk();

    expect($r->headers->get('Content-Type'))->toBe('image/jpeg')->and($r->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')->not->toContain('public')
        ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('refuses a link that was changed, that has run out, or that belongs to another order', function () {
    $x = fuDelivered();
    $url = fuProofCard($x)['photo']['url'];

    $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=deadbeef', $url))->assertForbidden();
    $this->get(str_replace('/orders/'.$x->orderId, '/orders/'.Str::uuid(), $url))->assertForbidden();
    // a link signed for another order of the same proof id: the proof does not belong to it
    $other = URL::temporarySignedRoute('api.v1.orders.proof-photo', now()->addMinutes(10), ['id' => Str::uuid()->toString(), 'proof' => DeliveryProof::value('id')]);
    $this->get($other)->assertNotFound();
    $this->travelTo(qatar('2026-11-19 12:11'));
    $this->get($url)->assertForbidden();
    $this->get(route('api.v1.orders.proof-photo', ['id' => $x->orderId, 'proof' => DeliveryProof::value('id')]))->assertForbidden(); // no signature at all
});

it('shows another customer nothing: the order itself is not found', function () {
    $x = fuDelivered();
    $url = fuProofCard($x)['photo']['url'];
    actingAsCustomer(User::factory()->create());

    $this->getJson("/api/v1/orders/{$x->orderId}")->assertNotFound();
    $this->getJson("/api/v1/orders/{$x->orderId}/timeline")->assertNotFound();
    expect($url)->not->toBeEmpty();
});

it('hides the photo but keeps the time and the name when the switch is off, and the old link stops working', function () {
    $x = fuDelivered();
    $url = fuProofCard($x)['photo']['url'];
    app(Settings::class)->set('delivery.proof_for_customer', false);

    $proof = fuProofCard($x);
    expect($proof['photo'])->toBeNull()->and($proof['received_by'])->toBe('Mariam')->and($proof['delivered_at'])->not->toBeNull();
    $this->get($url)->assertNotFound();
});

it('shows only the time and the name for an order delivered without a photo', function () {
    $x = fuDelivered();
    DB::table('delivery_proofs')->where('delivery_id', $x->delivery->id)->update(['disk' => null, 'path' => null]);

    $proof = fuProofCard($x);
    expect($proof['photo'])->toBeNull()->and($proof['received_by'])->toBe('Mariam')->and($proof['delivered_at'])->not->toBeNull();
});

it('falls back to the name written in the delivery history for an order delivered before proofs kept it', function () {
    $x = fuDelivered();
    DB::table('delivery_proofs')->where('delivery_id', $x->delivery->id)->delete();

    $proof = fuProofCard($x);
    expect($proof['received_by'])->toBe('Mariam')->and($proof['photo'])->toBeNull();
});

it('has no proof before the delivery', function () {
    $x = deliveryAt('out_for_delivery');
    actingAsCustomer($x->customer);

    expect($this->getJson("/api/v1/orders/{$x->orderId}")->json('data.fulfillments.0.proof'))->toBeNull();
});

it('never serves the photo of a failed attempt, of the assembly or a courier link', function () {
    $x = fuDelivered();
    $real = DeliveryProof::where('delivery_id', $x->delivery->id)->firstOrFail();
    foreach (['failed', 'assembly', 'provider', 'location'] as $type) {
        $path = Storage::disk('local')->putFileAs('delivery-proofs/'.$x->delivery->id, UploadedFile::fake()->image('x.jpg'), Str::random(6).'.jpg');
        $row = DeliveryProof::create(['delivery_id' => $x->delivery->id, 'type' => $type, 'disk' => 'local', 'path' => $path, 'captured_at' => now()->addMinute()]);
        $this->get(URL::temporarySignedRoute('api.v1.orders.proof-photo', now()->addMinutes(5), ['id' => $x->orderId, 'proof' => $row->id]))->assertNotFound();
        // and the order page still points at the real photo, not the later one
        expect(fuProofCard($x)['photo']['url'])->toContain($real->id);
    }
});

it('refuses a path that the system did not write', function () {
    $x = fuDelivered();
    $real = DeliveryProof::where('delivery_id', $x->delivery->id)->firstOrFail();
    $real->update(['path' => '../../.env']);

    $this->get(URL::temporarySignedRoute('api.v1.orders.proof-photo', now()->addMinutes(5), ['id' => $x->orderId, 'proof' => $real->id]))->assertNotFound();
});

it('gives no link for a courier delivery whose proof is only a link', function () {
    $x = fuDelivered();
    DB::table('delivery_proofs')->where('delivery_id', $x->delivery->id)->update(['type' => 'provider', 'disk' => null, 'path' => 'https://courier.example/proof/1']);

    expect(fuProofCard($x)['photo'])->toBeNull();
});
