<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Catalog\ProductEditor;
use App\Livewire\Admin\Catalog\ProductIndex;
use App\Livewire\Admin\Catalog\ProductVariants;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->superAdmin()->create();
});

function labelNamed(string $en, string $ar, array $attributes = []): Product
{
    $p = Product::factory()->create($attributes);
    $p->translations()->where('locale', 'en')->first()->update(['name' => $en]);
    $p->translations()->where('locale', 'ar')->first()->update(['name' => $ar]);

    return $p;
}

function labelsUrl(array $products, array $query = []): string
{
    return route('admin.products.labels', ['ids' => collect($products)->pluck('id')->implode(',')] + $query);
}

describe('the printable label sheet', function () {
    it('prints name, price and a Code 128 barcode of the barcode, one label per product', function () {
        $p = labelNamed('Milano Velvet Sofa', 'كنبة ميلانو', ['barcode' => '6291234567890', 'price' => '2499.00']);

        $html = $this->actingAs($this->admin)->get(labelsUrl([$p]))->assertOk()->getContent();

        expect($html)->toContain('Milano Velvet Sofa')->toContain('QAR 2,499.00')->toContain('6291234567890')
            ->toContain('<svg')->toContain('role="img"')->toContain('aria-label="6291234567890"')
            ->toContain('size: A4')->toContain('@media print')->toContain('window.print()');
        expect(substr_count($html, 'class="label"'))->toBe(1);
    });

    it('prints the SKU when there is no barcode', function () {
        $p = labelNamed('Oslo Sofa', 'كنبة أوسلو', ['sku' => 'OSLO-1', 'barcode' => null]);

        $html = $this->actingAs($this->admin)->get(labelsUrl([$p]))->assertOk()->getContent();

        expect($html)->toContain('aria-label="OSLO-1"')->toContain('>OSLO-1<');
    });

    it('prints one label per active variant, each with its own code and price, and the option name', function () {
        $p = labelNamed('Wardrobe', 'خزانة', ['sku' => 'WARD', 'barcode' => '6290000000001', 'price' => '900.00'])->load('variants');
        $p->update(['has_variants' => true]);
        $p->variants()->delete();
        $a = $p->variants()->create(['sku' => 'WARD-OAK', 'barcode' => '6290000000018', 'name_en' => 'Oak', 'name_ar' => 'بلوط', 'is_default' => true, 'status' => 'active', 'sort_order' => 1]);
        $b = $p->variants()->create(['sku' => 'WARD-WAL', 'name_en' => 'Walnut', 'name_ar' => 'جوز', 'price' => '1100.00', 'status' => 'active', 'sort_order' => 2]);
        $p->variants()->create(['sku' => 'WARD-OFF', 'name_en' => 'Retired', 'status' => 'inactive', 'sort_order' => 3]);

        $html = $this->actingAs($this->admin)->get(labelsUrl([$p->fresh()]))->assertOk()->getContent();

        expect(substr_count($html, 'class="label"'))->toBe(2)
            ->and($html)->toContain('aria-label="6290000000018"')->toContain('aria-label="WARD-WAL"')
            ->and($html)->toContain('>Oak<')->toContain('>Walnut<')->toContain('QAR 900.00')->toContain('QAR 1,100.00')
            ->and($html)->not->toContain('Retired')->not->toContain('aria-label="6290000000001"');
    });

    it('uses the product barcode for a product with a single variant', function () {
        $p = labelNamed('Cushion', 'وسادة', ['sku' => 'CUSH', 'barcode' => '6290000000100']);

        $html = $this->actingAs($this->admin)->get(labelsUrl([$p]))->assertOk()->getContent();

        expect($html)->toContain('aria-label="6290000000100"');
    });

    it('works in Arabic: right to left, the Arabic name and price', function () {
        $p = labelNamed('Milano Velvet Sofa', 'كنبة ميلانو', ['barcode' => '6291234567890', 'price' => '2499.00']);

        $html = $this->actingAs($this->admin)->withSession(['locale' => 'ar'])->get(labelsUrl([$p], ['lang' => 'ar']))->assertOk()->getContent();

        expect($html)->toContain('كنبة ميلانو')->toContain('2,499.00 ر.ق')->not->toContain('>Milano Velvet Sofa<');
        // the language option works whatever the dashboard language is
        $both = $this->actingAs($this->admin)->get(labelsUrl([$p], ['lang' => 'both']))->getContent();
        expect($both)->toContain('Milano Velvet Sofa')->toContain('كنبة ميلانو');
    });

    it('follows the dashboard language and sets the page direction', function () {
        $p = labelNamed('Milano Velvet Sofa', 'كنبة ميلانو');
        $this->actingAs($this->admin)->get(labelsUrl([$p]))->assertOk()->assertSee('dir="ltr"', false)->assertSee('Milano Velvet Sofa');
        $this->actingAs($this->admin)->withSession(['locale' => 'ar'])->get(labelsUrl([$p]))->assertOk()
            ->assertSee('dir="rtl"', false)->assertSee('lang="ar"', false)->assertSee('كنبة ميلانو');
    });

    it('repeats labels by copies and fills sheets by layout', function () {
        $products = [labelNamed('A', 'ا', ['barcode' => '1000000000001']), labelNamed('B', 'ب', ['barcode' => '1000000000002'])];

        $html = $this->actingAs($this->admin)->get(labelsUrl($products, ['copies' => 3, 'layout' => 'large']))->assertOk()->getContent();

        expect(substr_count($html, 'class="label"'))->toBe(6)->and(substr_count($html, 'class="page"'))->toBe(1);
        // 24 per medium sheet: 30 copies of one product is two sheets
        $many = $this->actingAs($this->admin)->get(labelsUrl([$products[0]], ['copies' => 20, 'layout' => 'large']))->getContent();
        expect(substr_count($many, 'class="page"'))->toBe(2)->and(substr_count($many, 'class="label"'))->toBe(20);
    });

    it('can hide the price', function () {
        $p = labelNamed('Milano', 'ميلانو', ['price' => '2499.00']);

        $html = $this->actingAs($this->admin)->get(labelsUrl([$p], ['price' => '0']))->assertOk()->getContent();

        expect($html)->not->toContain('QAR 2,499.00');
    });

    it('keeps the order of the products as picked', function () {
        $a = labelNamed('Alpha', 'ا', ['barcode' => '1000000000001']);
        $b = labelNamed('Bravo', 'ب', ['barcode' => '1000000000002']);

        $html = $this->actingAs($this->admin)->get(labelsUrl([$b, $a]))->getContent();

        expect(strpos($html, 'Bravo'))->toBeLessThan(strpos($html, 'Alpha'));
    });

    it('prints only the code when it holds characters a barcode cannot, and says so', function () {
        $p = labelNamed('Odd', 'غريب', ['barcode' => null, 'sku' => 'ODD-1']);
        $p->variants()->update(['sku' => 'ODD-ÉÉ']);

        $html = $this->actingAs($this->admin)->get(labelsUrl([$p->fresh()]))->assertOk()->getContent();

        expect($html)->toContain(__('catalog_admin.labels_no_barcode_short'))->toContain('ODD-ÉÉ')->toContain('role="alert"');
    });

    it('warns when a long code makes the bars too thin for the label size', function () {
        $p = labelNamed('Long', 'طويل', ['barcode' => 'LONG-CODE-WITH-MANY-CHARS-0123']);

        $small = $this->actingAs($this->admin)->get(labelsUrl([$p], ['layout' => 'small']))->getContent();
        $large = $this->actingAs($this->admin)->get(labelsUrl([$p], ['layout' => 'large']))->getContent();

        expect($small)->toContain('is long for this label size')->and($large)->not->toContain('is long for this label size');
    });

    it('never shows markup from a product name', function () {
        $p = labelNamed('<script>alert(1)</script> Sofa', 'x');

        $html = $this->actingAs($this->admin)->get(labelsUrl([$p]))->assertOk()->getContent();

        expect($html)->not->toContain('<script>alert(1)')->toContain('&lt;script&gt;alert(1)&lt;/script&gt; Sofa');
    });

    it('is closed to guests and to staff without catalog access, and rejects bad input', function () {
        $p = labelNamed('A', 'ا');

        $this->get(labelsUrl([$p]))->assertRedirect();
        $this->actingAs(staffWithRole('delivery_employee'))->get(labelsUrl([$p]))->assertForbidden();

        $this->actingAs($this->admin);
        $this->get(route('admin.products.labels'))->assertStatus(302); // no ids: validation, back to where you were
        $this->get(route('admin.products.labels', ['ids' => 'not-a-uuid']))->assertNotFound();
        $this->get(route('admin.products.labels', ['ids' => (string) Str::uuid()]))->assertNotFound();
        $this->get(labelsUrl([$p], ['copies' => 0]))->assertStatus(302);
        $this->get(labelsUrl([$p], ['copies' => 21]))->assertStatus(302);
        $this->get(labelsUrl([$p], ['layout' => 'huge']))->assertStatus(302);
        $ids = collect(range(1, 101))->map(fn () => (string) Str::uuid())->implode(',');
        $this->get(route('admin.products.labels', ['ids' => $ids]))->assertStatus(422);
    });

    it('lets a branch manager with catalog.view print labels', function () {
        $p = labelNamed('A', 'ا');

        $this->actingAs(staffWithRole('branch_manager', Branch::factory()->create()))->get(labelsUrl([$p]))->assertOk();
    });
});

describe('from the products list', function () {
    it('offers "Print labels" for the selection and a print icon on every row', function () {
        $a = labelNamed('Alpha', 'ا', ['barcode' => '1000000000001']);
        $b = labelNamed('Bravo', 'ب', ['barcode' => '1000000000002']);

        $this->actingAs($this->admin);
        Livewire::test(ProductIndex::class)->assertSee(route('admin.products.labels', ['ids' => $a->id]), false)
            ->assertDontSee(__('catalog_admin.print_labels'))
            ->set('selected', [$a->id, $b->id])
            ->assertSee(__('catalog_admin.print_labels'))
            ->assertSee(e(route('admin.products.labels', ['ids' => $a->id.','.$b->id])), false);
    });
});

describe('the barcode field in the editors', function () {
    it('explains itself and is validated: letters and digits only', function (string $barcode, bool $valid) {
        $p = Product::factory()->create(['barcode' => null]);
        $this->actingAs($this->admin);

        $test = Livewire::test(ProductEditor::class, ['product' => $p])->assertSee(__('catalog_admin.barcode_hint'))
            ->set('form.barcode', $barcode)->call('saveContent');
        $valid ? $test->assertHasNoErrors('form.barcode') : $test->assertHasErrors('form.barcode');
    })->with([
        ['6291234567890', true], ['ABC-123', true], ['a.b_c-1', true], ['', true],
        ['has space', false], ['-leading', false], ['é6291', false], ['12/34', false], ['<b>1</b>', false], ["12\n34", false],
    ]);

    it('rejects a barcode that another product or variant already carries, whatever its case', function () {
        $other = Product::factory()->create(['barcode' => 'Taken-1']);
        $variantOwner = Product::factory()->create();
        $variantOwner->variants()->first()->update(['barcode' => 'VAR-77']);
        $mine = Product::factory()->create(['barcode' => null]);
        $this->actingAs($this->admin);

        foreach (['Taken-1', 'taken-1', 'TAKEN-1', 'var-77', 'VAR-77'] as $clash) {
            Livewire::test(ProductEditor::class, ['product' => $mine])->set('form.barcode', $clash)->call('saveContent')
                ->assertHasErrors('form.barcode');
        }
        // its own barcode is not a clash with itself
        Livewire::test(ProductEditor::class, ['product' => $other])->set('form.barcode', 'taken-1')->call('saveContent')->assertHasNoErrors('form.barcode');
        // and a free one saves
        Livewire::test(ProductEditor::class, ['product' => $mine])->set('form.barcode', 'FREE-1')->call('saveContent')->assertHasNoErrors('form.barcode');
        expect($mine->fresh()->barcode)->toBe('FREE-1');
    });

    it('validates a new product\'s barcode on create too', function () {
        Product::factory()->create(['barcode' => '6291111111111']);
        $this->actingAs($this->admin);

        Livewire::test(ProductEditor::class)->set('form.sku', 'NEW-1')->set('form.price', '10')->set('form.barcode', '6291111111111')
            ->set('tr.en.name', 'New')->set('tr.ar.name', 'جديد')->call('create')->assertHasErrors('form.barcode');
    });

    it('validates variant barcodes in the variant editor, but lets a variant carry its own product\'s barcode', function () {
        $p = Product::factory()->create(['barcode' => '6292222222222']);
        Product::factory()->create(['barcode' => '6293333333333']);
        $variant = $p->variants()->first();
        $this->actingAs($this->admin);

        $edit = fn (string $barcode) => Livewire::test(ProductVariants::class, ['product' => $p])->call('openEdit', $variant->id)
            ->set('edit.barcode', $barcode)->call('saveEdit');

        $edit('6293333333333')->assertHasErrors('edit.barcode');
        $edit('bad code')->assertHasErrors('edit.barcode');
        $edit('6292222222222')->assertHasNoErrors('edit.barcode');
        $edit('6294444444444')->assertHasNoErrors('edit.barcode');
        expect($variant->fresh()->barcode)->toBe('6294444444444');
    });

    it('is validated on the API too (format), and the service refuses a case-different duplicate', function () {
        actingAsStaff(staffWithRole('admin'));
        $payload = ['sku' => 'API-1', 'price' => '10.00', 'status' => 'active', 'barcode' => 'Api-Code-1',
            'translations' => ['en' => ['name' => 'Api one'], 'ar' => ['name' => 'واحد']]];

        $this->postJson('/api/v1/admin/catalog/products', array_replace($payload, ['barcode' => 'has space']))->assertStatus(422)->assertJsonValidationErrors('barcode');
        $this->postJson('/api/v1/admin/catalog/products', $payload)->assertCreated();
        $this->postJson('/api/v1/admin/catalog/products', array_replace($payload, ['sku' => 'API-2', 'barcode' => 'API-CODE-1']))
            ->assertStatus(422)->assertJsonPath('code', 'barcode_taken');
    });
});
