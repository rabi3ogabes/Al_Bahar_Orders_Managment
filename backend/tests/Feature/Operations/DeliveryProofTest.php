<?php

use App\Domain\Fulfillment\Models\Delivery;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Livewire\Admin\Operations\DeliveryProofWindow;
use App\Livewire\Admin\Sales\OrderShow;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/OpsSupport.php';

/** The proof of delivery: a private photo, shown only to staff who may see that delivery's branch, with the facts around it. */
beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->travelTo(qatar('2026-11-19 15:00'));
    $this->a = opsBranch(attributes: ['code' => 'AAA']);
    $this->b = opsBranch(attributes: ['code' => 'BBB']);
    $this->mgrA = opsStaff('branch_manager', $this->a);
    $this->mgrB = opsStaff('branch_manager', $this->b);
    $this->driver = driverFor($this->a);
    $ids = opsOrder($this->a, [], ['status' => 'delivered', 'type' => 'delivery'], opsAgo(300));
    $this->ids = $ids;
    $this->delivery = opsDelivery($ids['part'], $this->a, 'delivered', ['driver_id' => $this->driver->id, 'delivered_at' => opsUtc(now()), 'cod_amount' => '100.00']);
});

/** Stores a real image the way DeliveryService does and records it. */
function opsProof(string $deliveryId, array $attributes = [], string $name = 'photo.jpg'): string
{
    $path = Storage::disk('local')->putFileAs('delivery-proofs/'.$deliveryId, UploadedFile::fake()->image($name, 40, 30), Str::random(8).'-'.$name);
    $id = (string) Str::uuid();
    DB::table('delivery_proofs')->insert(array_merge(['id' => $id, 'delivery_id' => $deliveryId, 'type' => 'photo', 'disk' => 'local', 'path' => $path, 'recipient_name' => 'Mariam',
        'captured_at' => opsUtc(now()), 'created_at' => now(), 'updated_at' => now()], $attributes));

    return $id;
}

function opsProofUrl(string $deliveryId, string $proofId): string
{
    return route('admin.deliveries.proof', [$deliveryId, $proofId]);
}

// ── The private route ────────────────────────────────────────────────────────────────────────────────────────────

it('streams the photo to staff of the delivery\'s branch, as a private image', function () {
    $proof = opsProof($this->delivery);
    $response = $this->actingAs($this->mgrA)->get(opsProofUrl($this->delivery, $proof))->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/jpeg')->and($response->headers->get('Cache-Control'))->toContain('private')->and($response->headers->get('Cache-Control'))->not->toContain('public')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')->and($response->headers->get('Content-Disposition'))->toContain('inline')->toContain('proof-'.$proof);
    // the type comes from the stored file, not from its name
    $png = opsProof($this->delivery, [], 'shot.png');
    expect($this->actingAs($this->mgrA)->get(opsProofUrl($this->delivery, $png))->assertOk()->headers->get('Content-Type'))->toBe('image/png');
    // the company-wide roles may see it too
    $this->actingAs(User::factory()->superAdmin()->create())->get(opsProofUrl($this->delivery, $proof))->assertOk();
    $this->actingAs(opsStaff('auditor'))->get(opsProofUrl($this->delivery, $proof))->assertOk();
});

it('answers 404 to everyone who may not see that delivery, and sends a guest to sign in', function () {
    $proof = opsProof($this->delivery);
    $url = opsProofUrl($this->delivery, $proof);
    $this->actingAs($this->mgrB)->get($url)->assertNotFound();                                              // another branch
    $this->actingAs(opsStaff('sales_employee', $this->a))->get($url)->assertNotFound();                      // right branch, no deliveries.view
    $this->actingAs(opsStaffWith($this->a, ['orders.view', 'pickup.view']))->get($url)->assertNotFound();
    $this->actingAs(User::factory()->create())->get($url)->assertRedirect();   // a customer is not staff
    auth()->logout();
    $this->get($url)->assertRedirect(route('admin.login'));
    // staff of the branch with the right permission is let in; the driver of the branch too (deliveries.view)
    $this->actingAs($this->driver)->get($url)->assertOk();
});

it('answers 404 for ids that do not belong together, unknown ids and a listing', function () {
    $proof = opsProof($this->delivery);
    $otherIds = opsOrder($this->a, [], ['status' => 'delivered'], opsAgo(300));
    $other = opsDelivery($otherIds['part'], $this->a, 'delivered');
    $this->actingAs($this->mgrA);
    $this->get(opsProofUrl($other, $proof))->assertNotFound();                                              // proof of another delivery
    $this->get(opsProofUrl($this->delivery, (string) Str::uuid()))->assertNotFound();
    $this->get(opsProofUrl((string) Str::uuid(), $proof))->assertNotFound();
    $this->get('/admin/deliveries/not-a-uuid/proofs/'.$proof)->assertNotFound();
    $this->get('/admin/deliveries/'.$this->delivery.'/proofs/not-a-uuid')->assertNotFound();
    $this->get('/admin/deliveries/'.$this->delivery.'/proofs')->assertNotFound();                           // no directory listing
    $this->get('/admin/deliveries/'.$this->delivery.'/proofs/')->assertNotFound();
});

it('never reads a path the system did not store: traversal, another delivery\'s folder, absolute paths, other disks, other file types', function (string $path, string $disk) {
    Storage::disk('local')->put('secret.txt', 'top secret');
    Storage::disk('local')->put('delivery-proofs/other/photo.jpg', UploadedFile::fake()->image('x.jpg')->getContent());
    $id = (string) Str::uuid();
    DB::table('delivery_proofs')->insert(['id' => $id, 'delivery_id' => $this->delivery, 'type' => 'photo', 'disk' => $disk, 'path' => str_replace('{d}', $this->delivery, $path),
        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $response = $this->actingAs($this->mgrA)->get(opsProofUrl($this->delivery, $id));
    $response->assertNotFound();
    expect($response->getContent())->not->toContain('top secret');
})->with([
    'up and out' => ['delivery-proofs/{d}/../../secret.txt', 'local'],
    'up from the root' => ['../secret.txt', 'local'],
    'encoded dots' => ['delivery-proofs/{d}/%2e%2e/secret.txt', 'local'],
    'backslashes' => ['delivery-proofs\\{d}\\..\\secret.txt', 'local'],
    'another delivery\'s folder' => ['delivery-proofs/other/photo.jpg', 'local'],
    'a subfolder' => ['delivery-proofs/{d}/sub/photo.jpg', 'local'],
    'absolute path' => ['/etc/passwd', 'local'],
    'a null byte' => ["delivery-proofs/{d}/photo.jpg\0.png", 'local'],
    'a disk that does not exist' => ['delivery-proofs/{d}/photo.jpg', 'nowhere'],
    'a file that is gone' => ['delivery-proofs/{d}/gone.jpg', 'local'],
]);

it('refuses a stored file that is not an image', function () {
    Storage::disk('local')->put('delivery-proofs/'.$this->delivery.'/notes.txt', 'hello');
    Storage::disk('local')->put('delivery-proofs/'.$this->delivery.'/page.html', '<script>alert(1)</script>');
    foreach (['notes.txt', 'page.html'] as $file) {
        $id = (string) Str::uuid();
        DB::table('delivery_proofs')->insert(['id' => $id, 'delivery_id' => $this->delivery, 'type' => 'photo', 'disk' => 'local', 'path' => 'delivery-proofs/'.$this->delivery.'/'.$file, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->mgrA)->get(opsProofUrl($this->delivery, $id))->assertNotFound();
    }
});

it('does not stream a courier\'s proof: it has no file here', function () {
    $id = (string) Str::uuid();
    DB::table('delivery_proofs')->insert(['id' => $id, 'delivery_id' => $this->delivery, 'type' => 'provider', 'path' => 'https://courier.example/proof/1', 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->mgrA)->get(opsProofUrl($this->delivery, $id))->assertNotFound();
});

// ── The card ─────────────────────────────────────────────────────────────────────────────────────────────────────

it('shows the photos, who received the order and when, the driver, where the driver was and the cash collected', function () {
    $one = opsProof($this->delivery);
    $two = opsProof($this->delivery, ['captured_at' => opsUtc(now()->addMinute()), 'recipient_name' => null]);
    DB::table('driver_locations')->insert([
        ['driver_id' => $this->driver->id, 'delivery_id' => $this->delivery, 'lat' => 25.2000, 'lng' => 51.5000, 'recorded_at' => opsUtc(now()->subMinutes(30))],
        ['driver_id' => $this->driver->id, 'delivery_id' => $this->delivery, 'lat' => 25.286106, 'lng' => 51.534817, 'recorded_at' => opsUtc(now()->subMinute())],
        ['driver_id' => $this->driver->id, 'delivery_id' => $this->delivery, 'lat' => 25.9999, 'lng' => 51.9999, 'recorded_at' => opsUtc(now()->addHour())],     // after the photo: not where the photo was taken
    ]);
    $collector = $this->driver;
    DB::table('cash_collections')->insert(['id' => (string) Str::uuid(), 'payment_id' => opsPayment($this->ids['order']), 'order_fulfillment_id' => $this->ids['part'], 'branch_id' => $this->a->id, 'collected_by' => $collector->id,
        'context' => 'cod', 'tender' => 'cash', 'amount_due' => '100.00', 'amount_collected' => '100.00', 'status' => 'collected', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

    $this->actingAs($this->mgrA);
    $html = Blade::render('<x-admin.sales.delivery-proof :delivery="$delivery" />', ['delivery' => Delivery::findOrFail($this->delivery)]);
    expect($html)->toContain(opsProofUrl($this->delivery, $one))->toContain(opsProofUrl($this->delivery, $two))->toContain('Delivery photo 1')->toContain('Delivery photo 2')
        ->toContain('Mariam')->toContain(e($this->driver->name))->toContain('15:00')
        ->toContain('25.28611, 51.53482')->toContain('https://www.google.com/maps/search/?api=1&amp;query=25.286106,51.534817')->toContain('Open in map')
        ->toContain('QAR 100.00 collected (cash)')->not->toContain('25.9999');
});

it('says so plainly when there is no photo, no position, or nothing to collect', function () {
    $this->actingAs($this->mgrA);
    $card = fn () => Blade::render('<x-admin.sales.delivery-proof :delivery="$delivery" />', ['delivery' => Delivery::findOrFail($this->delivery)]);
    DB::table('deliveries')->where('id', $this->delivery)->update(['cod_amount' => '0.00']);
    expect($card())->toContain('No proof has been saved for this delivery.')->toContain('The driver&#039;s position was not recorded.')->not->toContain('Cash on delivery');
    DB::table('deliveries')->where('id', $this->delivery)->update(['cod_amount' => '100.00']);
    expect($card())->toContain('QAR 100.00 to collect');
    // the position written with the "delivered" step is used when the driver's trail has nothing
    DB::table('delivery_status_history')->insert(['delivery_id' => $this->delivery, 'from_status' => 'out_for_delivery', 'to_status' => 'delivered', 'latitude' => 25.3, 'longitude' => 51.4, 'created_at' => now()]);
    expect($card())->toContain('25.30000, 51.40000');
    // a courier's proof is a link only, and only an https one
    DB::table('delivery_proofs')->insert([
        ['id' => (string) Str::uuid(), 'delivery_id' => $this->delivery, 'type' => 'provider', 'path' => 'https://courier.example/p/1', 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'delivery_id' => $this->delivery, 'type' => 'provider', 'path' => 'javascript:alert(1)', 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ]);
    expect($card())->toContain('https://courier.example/p/1')->toContain('Open the courier')->not->toContain('javascript:');
});

it('draws nothing for a person who may not see the delivery\'s branch', function () {
    opsProof($this->delivery);
    foreach ([$this->mgrB, opsStaff('sales_employee', $this->a)] as $user) {
        $this->actingAs($user);
        expect(trim(Blade::render('<x-admin.sales.delivery-proof :delivery="$delivery" />', ['delivery' => Delivery::findOrFail($this->delivery)])))->toBe('');
    }
});

it('renders in English and Arabic without a raw translation key', function (string $locale) {
    opsProof($this->delivery);
    app()->setLocale($locale);
    $this->actingAs($this->mgrA);
    $html = Blade::render('<x-admin.sales.delivery-proof :delivery="$delivery" />', ['delivery' => Delivery::findOrFail($this->delivery)]);
    expect($html)->toContain(__('ops_live.proof.received_by', [], $locale))->toContain(__('ops_live.proof.where', [], $locale))->not->toContain('ops_live.');
})->with(['en', 'ar']);

it('opens in a side window for staff of the branch only', function () {
    $proof = opsProof($this->delivery);
    Livewire::actingAs($this->mgrA)->test(DeliveryProofWindow::class)->dispatch('open-delivery-proof', delivery: $this->delivery)->assertSet('open', true)
        ->assertSee(opsProofUrl($this->delivery, $proof), escape: false)->assertSee('Mariam');
    Livewire::actingAs($this->mgrB)->test(DeliveryProofWindow::class)->dispatch('open-delivery-proof', delivery: $this->delivery)->assertSet('open', false)
        ->assertDispatched('toast', type: 'error', message: __('ops_live.proof.unavailable'));
    Livewire::actingAs($this->mgrA)->test(DeliveryProofWindow::class)->dispatch('open-delivery-proof', delivery: 'junk')->assertSet('open', false);
});

it('shows the proof on the dashboard order page once the delivery is done', function () {
    opsProof($this->delivery);
    $order = Order::findOrFail($this->ids['order']);

    $this->actingAs($this->mgrA);
    Livewire::test(OrderShow::class, ['order' => $order])->assertSeeHtml('data-delivery-proof="'.$this->delivery.'"');
});
