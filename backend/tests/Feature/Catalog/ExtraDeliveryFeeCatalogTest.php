<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogImportService;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Catalog\ProductEditor;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
 * The extra delivery fee of a product (docs/api/CHECKOUT_FEES_V2.md section 3): the product editor, the admin API, the public API,
 * the catalogue import and export. How the fee is charged at checkout is in CheckoutFeesV2Test.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
    $this->product = Product::factory()->create(['sku' => 'BOX-1', 'price' => '500.00']);
});

function xfeeCsv(string $csv)
{
    return test()->postJson('/api/v1/admin/catalog/products/import', ['file' => UploadedFile::fake()->createWithContent('catalog.csv', $csv)])->assertCreated();
}

/* ───────────────────────── the product editor ───────────────────────── */

it('shows the Delivery card with the money field and the two choices, in English and in Arabic', function () {
    $this->actingAs($this->admin);
    Livewire::test(ProductEditor::class, ['product' => $this->product])->set('tab', 'specs')
        ->assertSee('Extra delivery fee (QAR)')->assertSee('For every unit')->assertSee('Once, whatever the quantity')
        ->assertSee('Only delivery orders pay it, never pickup.')
        ->assertSet('form.extra_delivery_fee', '0.00')->assertSet('form.extra_delivery_fee_mode', 'per_unit');

    app()->setLocale('ar');
    Livewire::test(ProductEditor::class, ['product' => $this->product])->set('tab', 'specs')
        ->assertSee('رسوم توصيل إضافية (ر.ق)')->assertSee('عن كل قطعة')->assertSee('مرة واحدة مهما كانت الكمية');
});

it('saves the fee and its mode with the rest of the tab and audits the change', function () {
    $this->actingAs($this->admin);
    Livewire::test(ProductEditor::class, ['product' => $this->product])->set('tab', 'specs')
        ->set('form.extra_delivery_fee', '75.5')->set('form.extra_delivery_fee_mode', 'per_line')->call('saveSpecs')->assertHasNoErrors();

    $p = $this->product->fresh();
    $log = AuditLog::where('action', 'catalog.product_updated')->where('auditable_id', $p->id)->latest('id')->firstOrFail();
    expect($p->extra_delivery_fee)->toBe('75.50')->and($p->extra_delivery_fee_mode)->toBe('per_line')
        ->and($log->old_values['extra_delivery_fee'])->toBe('0.00')->and($log->new_values['extra_delivery_fee'])->toBe('75.50')
        ->and($log->old_values['extra_delivery_fee_mode'])->toBe('per_unit')->and($log->new_values['extra_delivery_fee_mode'])->toBe('per_line');
});

it('treats an empty box as no fee and accepts the limits', function (string $typed, string $stored) {
    $this->actingAs($this->admin);
    $this->product->update(['extra_delivery_fee' => '20.00']);
    Livewire::test(ProductEditor::class, ['product' => $this->product->fresh()])->set('tab', 'specs')->set('form.extra_delivery_fee', $typed)->call('saveSpecs')->assertHasNoErrors();
    expect($this->product->fresh()->extra_delivery_fee)->toBe($stored);
})->with([
    'empty' => ['', '0.00'],
    'spaces' => ['  ', '0.00'],
    'zero' => ['0', '0.00'],
    'whole number' => ['50', '50.00'],
    'one decimal' => ['12.5', '12.50'],
    'the maximum' => ['100000', '100000.00'],
]);

it('refuses a bad fee or mode with a plain message and saves nothing', function (string $field, string $value) {
    $this->actingAs($this->admin);
    $this->product->update(['extra_delivery_fee' => '20.00', 'extra_delivery_fee_mode' => 'per_line']);
    Livewire::test(ProductEditor::class, ['product' => $this->product->fresh()])->set('tab', 'specs')->set("form.{$field}", $value)->call('saveSpecs')->assertHasErrors("form.{$field}");

    $p = $this->product->fresh();
    expect($p->extra_delivery_fee)->toBe('20.00')->and($p->extra_delivery_fee_mode)->toBe('per_line');
})->with([
    'negative' => ['extra_delivery_fee', '-1'],
    'above the maximum' => ['extra_delivery_fee', '100000.01'],
    'three decimals' => ['extra_delivery_fee', '1.234'],
    'text' => ['extra_delivery_fee', 'abc'],
    'exponent' => ['extra_delivery_fee', '1e3'],
    'unknown mode' => ['extra_delivery_fee_mode', 'weekly'],
    'empty mode' => ['extra_delivery_fee_mode', ''],
]);

it('forbids saving the fee without the right to edit the catalog', function () {
    $branch = Branch::factory()->create();
    $this->actingAs(staffWithRole('branch_manager', $branch));
    Livewire::test(ProductEditor::class, ['product' => $this->product])->set('tab', 'specs')->set('form.extra_delivery_fee', '99')->call('saveSpecs')->assertForbidden();
    expect($this->product->fresh()->extra_delivery_fee)->toBe('0.00');
});

it('copies the fee to a duplicated product and keeps it when other fields change', function () {
    $this->product->update(['extra_delivery_fee' => '40.00', 'extra_delivery_fee_mode' => 'per_line']);
    $service = app(ProductService::class);

    $copy = $service->duplicate($this->product->fresh());
    $service->update($this->product->fresh(), ['status' => 'inactive']);
    expect($copy->fresh()->extra_delivery_fee)->toBe('40.00')->and($copy->fresh()->extra_delivery_fee_mode)->toBe('per_line')
        ->and($this->product->fresh()->extra_delivery_fee)->toBe('40.00')->and($this->product->fresh()->extra_delivery_fee_mode)->toBe('per_line');
});

/* ───────────────────────── the admin API ───────────────────────── */

it('creates, shows, lists and updates the fee through the admin API', function () {
    actingAsStaff(staffWithRole('admin'));
    $payload = ['sku' => 'API-BOX', 'price' => '300.00', 'translations' => ['en' => ['name' => 'Big box'], 'ar' => ['name' => 'صندوق كبير']],
        'extra_delivery_fee' => '75.50', 'extra_delivery_fee_mode' => 'per_line'];

    $created = $this->postJson('/api/v1/admin/catalog/products', $payload)->assertCreated()
        ->assertJsonPath('data.extra_delivery_fee', '75.50')->assertJsonPath('data.extra_delivery_fee_mode', 'per_line');
    $id = $created->json('data.id');
    $this->getJson("/api/v1/admin/catalog/products/{$id}")->assertOk()->assertJsonPath('data.extra_delivery_fee', '75.50')->assertJsonPath('data.extra_delivery_fee_mode', 'per_line');
    $row = collect($this->getJson('/api/v1/admin/catalog/products?search=API-BOX')->assertOk()->json('data'))->firstWhere('id', $id);
    expect($row['extra_delivery_fee'])->toBe('75.50')->and($row['extra_delivery_fee_mode'])->toBe('per_line');

    $this->patchJson("/api/v1/admin/catalog/products/{$id}", ['extra_delivery_fee' => 10, 'extra_delivery_fee_mode' => 'per_unit', 'reason' => 'cheaper'])->assertOk()
        ->assertJsonPath('data.extra_delivery_fee', '10.00')->assertJsonPath('data.extra_delivery_fee_mode', 'per_unit');

    // A product made without the fields gets no fee.
    $plain = $this->postJson('/api/v1/admin/catalog/products', ['sku' => 'API-PLAIN', 'price' => '10.00', 'translations' => ['en' => ['name' => 'Plain'], 'ar' => ['name' => 'عادي']]])->assertCreated();
    expect($plain->json('data.extra_delivery_fee'))->toBe('0.00')->and($plain->json('data.extra_delivery_fee_mode'))->toBe('per_unit');
});

it('validates the fee strictly in the admin API', function (array $bad, string $field) {
    actingAsStaff(staffWithRole('admin'));
    $this->patchJson("/api/v1/admin/catalog/products/{$this->product->id}", $bad)->assertStatus(422)->assertJsonValidationErrors($field);
    $this->postJson('/api/v1/admin/catalog/products', ['sku' => 'API-BAD', 'price' => '10.00', 'translations' => ['en' => ['name' => 'Bad'], 'ar' => ['name' => 'سيء']]] + $bad)
        ->assertStatus(422)->assertJsonValidationErrors($field);
    expect(Product::where('sku', 'API-BAD')->exists())->toBeFalse()->and($this->product->fresh()->extra_delivery_fee)->toBe('0.00');
})->with([
    'negative' => [['extra_delivery_fee' => -1], 'extra_delivery_fee'],
    'above the maximum' => [['extra_delivery_fee' => 100000.01], 'extra_delivery_fee'],
    'three decimals' => [['extra_delivery_fee' => '1.234'], 'extra_delivery_fee'],
    'text' => [['extra_delivery_fee' => 'free'], 'extra_delivery_fee'],
    'null' => [['extra_delivery_fee' => null], 'extra_delivery_fee'],
    'unknown mode' => [['extra_delivery_fee_mode' => 'weekly'], 'extra_delivery_fee_mode'],
    'null mode' => [['extra_delivery_fee_mode' => null], 'extra_delivery_fee_mode'],
]);

it('refuses a fee the database would also refuse, whoever calls the service', function () {
    $service = app(ProductService::class);
    foreach ([['extra_delivery_fee' => '-5.00'], ['extra_delivery_fee' => '100000.01'], ['extra_delivery_fee_mode' => 'weekly']] as $bad) {
        expect(fn () => $service->update($this->product->fresh(), $bad))->toThrow(DomainException::class);
    }
    expect($this->product->fresh()->extra_delivery_fee)->toBe('0.00');
});

/* ───────────────────────── the public API ───────────────────────── */

it('gives every product card and the product page the fee as string money and its mode', function () {
    $fee = Product::factory()->create(['extra_delivery_fee' => '50.00', 'extra_delivery_fee_mode' => 'per_line']);

    $detail = $this->getJson("/api/v1/products/{$fee->id}")->assertOk();
    expect($detail->json('data.extra_delivery_fee'))->toBe('50.00')->and($detail->json('data.extra_delivery_fee_mode'))->toBe('per_line');

    $list = collect($this->getJson('/api/v1/products')->assertOk()->json('data'))->keyBy('id');
    expect($list[$fee->id]['extra_delivery_fee'])->toBe('50.00')->and($list[$fee->id]['extra_delivery_fee_mode'])->toBe('per_line')
        ->and($list[$this->product->id]['extra_delivery_fee'])->toBe('0.00')->and($list[$this->product->id]['extra_delivery_fee_mode'])->toBe('per_unit');
});

/* ───────────────────────── import and export ───────────────────────── */

it('imports the fee and its mode, with a row error for each bad value', function () {
    actingAsStaff(staffWithRole('admin'));
    $csv = "sku,name_en,name_ar,price,extra_delivery_fee,extra_delivery_fee_mode\n"
        ."IMP-A,Box A,صندوق أ,100,60,per_line\n"
        ."IMP-B,Box B,صندوق ب,100,12.5,PER_UNIT\n"
        ."IMP-C,Box C,صندوق ج,100,,\n"
        ."IMP-D,Box D,صندوق د,100,-5,per_unit\n"
        ."IMP-E,Box E,صندوق ه,100,100000.01,per_unit\n"
        ."IMP-F,Box F,صندوق و,100,1.234,per_unit\n"
        ."IMP-G,Box G,صندوق ز,100,10,monthly\n";

    $res = xfeeCsv($csv);
    $fee = fn (string $sku) => Product::where('sku', $sku)->first(['extra_delivery_fee', 'extra_delivery_fee_mode'])?->only(['extra_delivery_fee', 'extra_delivery_fee_mode']);
    expect($res->json('data.created_rows'))->toBe(3)->and($res->json('data.failed_rows'))->toBe(4)
        ->and(collect($res->json('data.errors'))->pluck('field')->unique()->sort()->values()->all())->toBe(['extra_delivery_fee', 'extra_delivery_fee_mode'])
        ->and($fee('IMP-A'))->toBe(['extra_delivery_fee' => '60.00', 'extra_delivery_fee_mode' => 'per_line'])
        ->and($fee('IMP-B'))->toBe(['extra_delivery_fee' => '12.50', 'extra_delivery_fee_mode' => 'per_unit'])
        ->and($fee('IMP-C'))->toBe(['extra_delivery_fee' => '0.00', 'extra_delivery_fee_mode' => 'per_unit'])
        ->and(Product::whereIn('sku', ['IMP-D', 'IMP-E', 'IMP-F', 'IMP-G'])->exists())->toBeFalse();
});

it('keeps the fee of an existing product when the file has no such columns or empty cells, and changes it when asked', function () {
    actingAsStaff(staffWithRole('admin'));
    $this->product->update(['extra_delivery_fee' => '45.00', 'extra_delivery_fee_mode' => 'per_line']);

    // An older file (before the columns existed): the fee stays.
    xfeeCsv("sku,name_en,name_ar,price\nBOX-1,Box renamed,صندوق,520\n")->assertJsonPath('data.updated_rows', 1);
    expect($this->product->fresh()->extra_delivery_fee)->toBe('45.00')->and($this->product->fresh()->extra_delivery_fee_mode)->toBe('per_line')->and($this->product->fresh()->price)->toBe('520.00');

    // Empty cells: the fee stays.
    xfeeCsv("sku,name_en,name_ar,price,extra_delivery_fee,extra_delivery_fee_mode\nBOX-1,Box renamed,صندوق,520,,\n")->assertJsonPath('data.updated_rows', 1);
    expect($this->product->fresh()->extra_delivery_fee)->toBe('45.00')->and($this->product->fresh()->extra_delivery_fee_mode)->toBe('per_line');

    // Only the mode: the money stays. A 0 is a real answer: no fee.
    xfeeCsv("sku,name_en,name_ar,price,extra_delivery_fee_mode\nBOX-1,Box renamed,صندوق,520,per_unit\n");
    expect($this->product->fresh()->extra_delivery_fee)->toBe('45.00')->and($this->product->fresh()->extra_delivery_fee_mode)->toBe('per_unit');
    xfeeCsv("sku,name_en,name_ar,price,extra_delivery_fee\nBOX-1,Box renamed,صندوق,520,0\n");
    expect($this->product->fresh()->extra_delivery_fee)->toBe('0.00');
});

it('exports the two columns and an exported file imports back to the same fees', function () {
    actingAsStaff(staffWithRole('admin'));
    expect(CatalogImportService::COLUMNS)->toContain('extra_delivery_fee', 'extra_delivery_fee_mode');
    $this->product->update(['extra_delivery_fee' => '75.50', 'extra_delivery_fee_mode' => 'per_line']);
    $other = Product::factory()->create(['sku' => 'BOX-2', 'price' => '80.00', 'extra_delivery_fee' => '9.99', 'extra_delivery_fee_mode' => 'per_unit']);

    $file = tempnam(sys_get_temp_dir(), 'catalog').'.xlsx';
    expect(app(CatalogImportService::class)->export($file))->toBe(2);
    $this->product->update(['extra_delivery_fee' => '0.00', 'extra_delivery_fee_mode' => 'per_unit']);
    $other->update(['extra_delivery_fee' => '1.00']);

    $res = $this->postJson('/api/v1/admin/catalog/products/import', ['file' => UploadedFile::fake()->createWithContent('catalog.xlsx', (string) file_get_contents($file))])->assertCreated();
    @unlink($file);
    expect($res->json('data.failed_rows'))->toBe(0)->and($res->json('data.updated_rows'))->toBe(2)
        ->and($this->product->fresh()->only(['extra_delivery_fee', 'extra_delivery_fee_mode']))->toBe(['extra_delivery_fee' => '75.50', 'extra_delivery_fee_mode' => 'per_line'])
        ->and($other->fresh()->only(['extra_delivery_fee', 'extra_delivery_fee_mode']))->toBe(['extra_delivery_fee' => '9.99', 'extra_delivery_fee_mode' => 'per_unit']);
});
