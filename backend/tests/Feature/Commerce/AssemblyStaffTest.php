<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Order;
use App\Domain\Settings\Services\Settings;
use App\Livewire\Admin\Catalog\ProductEditor;
use App\Livewire\Admin\Organization\SettingsIndex;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Operations/OpsSupport.php';

/*
 * The owner's and the team's side of paid assembly and the date promise (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md): the two product boxes,
 * the four dashboard switches, and what the driver and the branch see on a line that bought assembly. The money itself is in AssemblyTest.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
    $this->product = Product::factory()->create(['sku' => 'SOFA-1', 'price' => '1000.00']);
});

function assemblyEditor(Product $p)
{
    return Livewire::test(ProductEditor::class, ['product' => $p])->set('tab', 'specs');
}

/* ───────────────────────── the product editor ───────────────────────── */

it('shows the assembly card and the lead time box with their hints, in English and in Arabic', function () {
    $this->actingAs($this->admin);
    assemblyEditor($this->product)->assertSee('Assembly and installation')->assertSee('Assembly fee per unit (QAR)')->assertSee('Extra lead time (days)')
        ->assertSet('form.assembly_fee', '')->assertSet('form.lead_time_days', 0);

    app()->setLocale('ar');
    assemblyEditor($this->product)->assertSee(__('catalog_admin.assembly_title'))->assertSee(__('catalog_admin.lead_time'))
        ->assertDontSee('catalog_admin.assembly_title');
    expect(__('catalog_admin.assembly_title'))->not->toBe('Assembly and installation');
});

it('saves the assembly fee and the lead time, marks the product as offering the service, and audits the change', function () {
    $this->actingAs($this->admin);
    assemblyEditor($this->product)->set('form.assembly_fee', '150')->set('form.lead_time_days', 5)->call('saveSpecs')->assertHasNoErrors();

    $p = $this->product->fresh();
    $log = AuditLog::where('action', 'catalog.product_updated')->where('auditable_id', $p->id)->latest('id')->firstOrFail();
    expect($p->assembly_fee)->toBe('150.00')->and($p->lead_time_days)->toBe(5)->and((bool) $p->assembly_service_available)->toBeTrue()
        ->and($log->new_values['assembly_fee'])->toBe('150.00')->and($log->new_values['lead_time_days'])->toBe(5);
});

it('treats an empty or zero fee box as "not offered" and accepts the limits', function (string $typed, ?string $stored) {
    $this->actingAs($this->admin);
    $this->product->update(['assembly_fee' => '80.00']);
    assemblyEditor($this->product->fresh())->set('form.assembly_fee', $typed)->call('saveSpecs')->assertHasNoErrors();

    expect($this->product->fresh()->assembly_fee)->toBe($stored);
})->with([
    'empty' => ['', null],
    'spaces' => ['  ', null],
    'zero' => ['0', null],
    'whole number' => ['120', '120.00'],
    'one decimal' => ['12.5', '12.50'],
]);

it('refuses a bad fee or lead time with a plain message and saves nothing', function (string $field, string $value) {
    $this->actingAs($this->admin);
    $this->product->update(['assembly_fee' => '80.00', 'lead_time_days' => 3]);
    assemblyEditor($this->product->fresh())->set("form.{$field}", $value)->call('saveSpecs')->assertHasErrors("form.{$field}");

    $p = $this->product->fresh();
    expect($p->assembly_fee)->toBe('80.00')->and($p->lead_time_days)->toBe(3);
})->with([
    'negative fee' => ['assembly_fee', '-1'],
    'three decimals' => ['assembly_fee', '1.234'],
    'letters in the fee' => ['assembly_fee', 'abc'],
    'negative days' => ['lead_time_days', '-1'],
    'too many days' => ['lead_time_days', '61'],
    'a fraction of a day' => ['lead_time_days', '1.5'],
]);

it('takes the same two fields through the admin API and audits them', function () {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/admin/catalog/products/{$this->product->id}", ['assembly_fee' => '99.5', 'lead_time_days' => 7, 'reason' => 'made to order'])->assertOk();

    $p = $this->product->fresh();
    expect($p->assembly_fee)->toBe('99.50')->and($p->lead_time_days)->toBe(7);

    $this->patchJson("/api/v1/admin/catalog/products/{$this->product->id}", ['assembly_fee' => null, 'reason' => 'not any more'])->assertOk();
    expect($this->product->fresh()->assembly_fee)->toBeNull();

    foreach ([['assembly_fee' => '-5'], ['assembly_fee' => '1.999'], ['lead_time_days' => 61], ['lead_time_days' => -1]] as $bad) {
        $this->patchJson("/api/v1/admin/catalog/products/{$this->product->id}", $bad + ['reason' => 'x'])->assertStatus(422);
    }
});

it('lets only a team member who can edit products change them', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer)->patchJson("/api/v1/admin/catalog/products/{$this->product->id}", ['assembly_fee' => '10', 'reason' => 'x'])->assertForbidden();
    expect($this->product->fresh()->assembly_fee)->toBeNull();
});

/* ───────────────────────── the dashboard switches ───────────────────────── */

it('lists the four switches under Settings, Checkout, with their words', function () {
    $this->actingAs($this->admin);
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')
        ->assertSee('Show the delivery date before payment')->assertSee('Same-day order cut-off (Qatar time)')
        ->assertSee('Offer paid assembly and installation')->assertSee('Extra days when assembly is chosen')
        ->assertSet('values.checkout.checkout__promise_enabled', true)->assertSet('values.checkout.checkout__same_day_cutoff', '15:00')
        ->assertSet('values.checkout.checkout__assembly_enabled', true)->assertSet('values.checkout.checkout__assembly_extra_days', 0);
});

it('saves the switches and the platform follows them at once', function () {
    $this->actingAs($this->admin);
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')
        ->set('values.checkout.checkout__promise_enabled', false)->set('values.checkout.checkout__same_day_cutoff', '13:30')
        ->set('values.checkout.checkout__assembly_enabled', false)->set('values.checkout.checkout__assembly_extra_days', 2)
        ->call('save')->assertHasNoErrors();

    $s = app(Settings::class);
    expect($s->get('checkout.promise_enabled'))->toBeFalse()->and($s->get('checkout.same_day_cutoff'))->toBe('13:30')
        ->and($s->get('checkout.assembly_enabled'))->toBeFalse()->and((int) $s->get('checkout.assembly_extra_days'))->toBe(2);
});

it('refuses a cut-off that is not a 24-hour time and extra days outside 0 to 30', function (string $key, string|int $value) {
    $this->actingAs($this->admin);
    Livewire::test(SettingsIndex::class)->set('tab', 'checkout')->set("values.checkout.{$key}", $value)->call('save')->assertHasErrors();
})->with([
    'am/pm' => ['checkout__same_day_cutoff', '3 pm'],
    'no minutes' => ['checkout__same_day_cutoff', '15'],
    'hour 24' => ['checkout__same_day_cutoff', '24:00'],
    'empty' => ['checkout__same_day_cutoff', ''],
    'negative days' => ['checkout__assembly_extra_days', -1],
    'too many days' => ['checkout__assembly_extra_days', 31],
]);

/* ───────────────────────── what the driver and the branch see ───────────────────────── */

/** A delivery job for a driver whose one line did or did not buy assembly. */
function assemblyJob(bool $bought, bool $productNeedsIt = false): object
{
    $x = deliveryAt('assigned');
    $line = Order::findOrFail($x->orderId)->fulfillments()->firstOrFail()->items()->firstOrFail();
    DB::table('products')->where('id', $line->product_id)->update(['requires_assembly' => $productNeedsIt]);
    DB::table('order_items')->where('id', $line->id)->update($bought ? ['assembly_fee' => '100.00', 'assembly_total' => '100.00'] : ['assembly_fee' => null, 'assembly_total' => '0.00']);
    $x->line = $line;

    return $x;
}

it('tells the driver a line bought assembly, even when the product is not flagged as needing it', function () {
    $x = assemblyJob(bought: true);

    $job = test()->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data.0');

    expect($job['assembly_ordered'])->toBeTrue()->and($job['requires_assembly'])->toBeTrue()
        ->and($job['items'][0]['assembly_ordered'])->toBeTrue()->and($job['items'][0]['requires_assembly'])->toBeTrue()
        ->and($job['items'][0])->not->toHaveKeys(['unit_price', 'line_total', 'assembly_fee', 'assembly_total']);
});

it('keeps "assembly ordered" false for a plain line', function () {
    assemblyJob(bought: false);

    $job = test()->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data.0');

    expect($job['assembly_ordered'])->toBeFalse()->and($job['requires_assembly'])->toBeFalse();
});

it('keeps "assembly ordered" apart from a product that is only flagged as needing assembly', function () {
    assemblyJob(bought: false, productNeedsIt: true);

    $job = test()->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data.0');

    expect($job['requires_assembly'])->toBeTrue()->and($job['assembly_ordered'])->toBeFalse();
});

it('does not tell the driver about assembly on a unit that was cancelled', function () {
    $x = assemblyJob(bought: true);
    DB::table('order_items')->where('id', $x->line->id)->update(['quantity_cancelled' => $x->line->quantity]);

    $job = test()->getJson('/api/v1/admin/dispatch/driver/jobs')->assertOk()->json('data.0');

    expect($job['items'])->toBe([])->and($job['assembly_ordered'])->toBeFalse();
});
