<?php

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\CatalogImport;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Catalog\Models\ProductPriceHistory;
use App\Domain\Catalog\Models\ProductVariantBranchSetting;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Identity\Models\User;
use App\Livewire\Admin\Catalog\AttributeIndex;
use App\Livewire\Admin\Catalog\BrandIndex;
use App\Livewire\Admin\Catalog\CategoryIndex;
use App\Livewire\Admin\Catalog\ImportIndex;
use App\Livewire\Admin\Catalog\ProductEditor;
use App\Livewire\Admin\Catalog\ProductIndex;
use App\Livewire\Admin\Catalog\ProductMedia;
use App\Livewire\Admin\Catalog\ProductPricing;
use App\Livewire\Admin\Catalog\ProductVariants;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    [$this->wb, $this->prl] = Branch::factory()->count(2)->create();
    $this->admin = User::factory()->superAdmin()->create();
    $this->manager = staffWithRole('branch_manager', $this->wb);
    $this->product = Product::factory()->create(['sku' => 'OSLO-1', 'price' => '1000.00']);
});

it('renders the catalog screens for a super admin', function (string $route, bool $withProduct = false) {
    $this->actingAs($this->admin)->get(route($route, $withProduct ? $this->product : []))->assertOk();
})->with([
    ['admin.products.index'], ['admin.products.create'], ['admin.products.edit', true], ['admin.categories.index'],
    ['admin.brands.index'], ['admin.attributes.index'], ['admin.imports.index'],
]);

it('renders every product editor tab', function (string $tab) {
    $this->actingAs($this->admin)->get(route('admin.products.edit', ['product' => $this->product, 'tab' => $tab]))->assertOk();
})->with(['content', 'pricing', 'variants', 'media', 'specs', 'history']);

it('forbids catalog screens without catalog permissions', function (string $route) {
    $this->actingAs(staffWithRole('delivery_employee', $this->wb))->get(route($route))->assertForbidden();
})->with(['admin.products.index', 'admin.categories.index', 'admin.brands.index', 'admin.attributes.index', 'admin.imports.index']);

it('lets branch staff browse but not change central catalog content', function () {
    $this->actingAs($this->manager);
    $this->get(route('admin.products.edit', $this->product))->assertOk();
    $this->get(route('admin.products.create'))->assertForbidden();
    Livewire::test(ProductEditor::class, ['product' => $this->product])->set('tr.en.name', 'Hacked')->call('saveContent')->assertForbidden();
    Livewire::test(ProductIndex::class)->set('selected', [$this->product->id])->set('bulk.status', 'archived')->call('applyBulk')->assertForbidden();
});

it('creates a product and saves bilingual content via ProductService', function () {
    $this->actingAs($this->admin);
    Livewire::test(ProductEditor::class)
        ->set('form.sku', 'MILA-CHAIR')->set('form.price', '850')->set('form.status', 'active')
        ->set('tr.en.name', 'Mila chair')->set('tr.ar.name', 'كرسي ميلا')
        ->call('create')->assertHasNoErrors()->assertRedirect();
    $p = Product::where('sku', 'MILA-CHAIR')->firstOrFail();
    expect($p->price)->toBe('850.00')->and($p->variants()->count())->toBe(1)->and($p->trans('name', 'ar'))->toBe('كرسي ميلا');

    Livewire::test(ProductEditor::class, ['product' => $p])
        ->set('tr.en.description', '<p>Hello</p><script>alert(1)</script>')->set('tagsInput', 'oak, linen ,oak')
        ->call('saveContent')->assertHasNoErrors();
    $p->refresh();
    expect($p->trans('description', 'en'))->not->toContain('<script>')->and($p->tags)->toBe(['oak', 'linen']);
});

it('searches, filters and bulk-updates products', function () {
    $other = Product::factory()->create(['sku' => 'TABLE-9']);
    $brand = Brand::factory()->create();
    $this->actingAs($this->admin);
    Livewire::test(ProductIndex::class)->set('search', 'TABLE-9')->assertSee('TABLE-9')->assertDontSee('OSLO-1');
    Livewire::test(ProductIndex::class)->set('selected', [$this->product->id, $other->id])->set('bulk.brand_id', $brand->id)->call('applyBulk')->assertHasNoErrors();
    expect(Product::where('brand_id', $brand->id)->count())->toBe(2);
    Livewire::test(ProductIndex::class)->set('filterBrand', $brand->id)->assertSee('TABLE-9');
});

it('duplicates and archives products', function () {
    $this->actingAs($this->admin);
    Livewire::test(ProductIndex::class)->call('duplicate', $this->product->id)->assertRedirect();
    expect(Product::where('sku', 'like', 'OSLO-1-COPY-%')->where('status', 'draft')->exists())->toBeTrue();
    Livewire::test(ProductIndex::class)->call('archive', $this->product->id);
    expect($this->product->refresh()->status)->toBe('archived');
});

it('overrides branch prices only at branches the user manages, with reason recorded', function () {
    $variant = $this->product->variants()->first();
    $this->actingAs($this->manager);
    Livewire::test(ProductPricing::class, ['product' => $this->product])
        ->call('open', 'variant_branch', $this->wb->id, $variant->id)
        ->set('editor.price', '899.50')->set('editor.reason', 'Showroom clearance')->call('save')->assertHasNoErrors();
    expect(ProductVariantBranchSetting::where('branch_id', $this->wb->id)->value('price'))->toBe('899.50');
    expect(ProductPriceHistory::where('branch_id', $this->wb->id)->value('reason'))->toBe('Showroom clearance');

    // Reason is mandatory.
    Livewire::test(ProductPricing::class, ['product' => $this->product])
        ->call('open', 'product_branch', $this->wb->id)->set('editor.price', '700')->set('editor.reason', '')->call('save')->assertHasErrors('editor.reason');

    // Another branch, or the global price, is off-limits.
    Livewire::test(ProductPricing::class, ['product' => $this->product])->call('open', 'product_branch', $this->prl->id)->assertForbidden();
    Livewire::test(ProductPricing::class, ['product' => $this->product])->call('open', 'product')->assertForbidden();
    Livewire::test(ProductPricing::class, ['product' => $this->product])->call('toggleBranch', $this->prl->id)->assertForbidden();
    // Tampering with the open editor's branch id is re-authorized on save.
    Livewire::test(ProductPricing::class, ['product' => $this->product])
        ->call('open', 'product_branch', $this->wb->id)->set('editor.branch_id', $this->prl->id)->set('editor.price', '1')->set('editor.reason', 'xxx')->call('save')->assertForbidden();
    expect(ProductBranchSetting::where('branch_id', $this->prl->id)->exists())->toBeFalse();
});

it('shows the effective price matrix and toggles branch availability', function () {
    $this->actingAs($this->admin);
    $c = Livewire::test(ProductPricing::class, ['product' => $this->product]);
    $matrix = $c->viewData('matrix');
    expect($matrix[$this->wb->id][$this->product->variants()->first()->id]['price']->final()->format('en'))->toBe('QAR 1,000.00');
    $c->call('toggleBranch', $this->wb->id);
    expect(ProductBranchSetting::where('branch_id', $this->wb->id)->value('is_enabled'))->toBeFalse();
    $matrix = Livewire::test(ProductPricing::class, ['product' => $this->product])->viewData('matrix');
    expect($matrix[$this->wb->id][$this->product->variants()->first()->id]['available'])->toBeFalse();
});

it('builds variants from attribute combinations', function () {
    $color = ProductAttribute::create(['code' => 'color', 'name_en' => 'Colour', 'name_ar' => 'اللون', 'type' => 'color']);
    $oak = $color->values()->create(['code' => 'oak', 'value_en' => 'Oak', 'value_ar' => 'بلوط', 'color_hex' => '#b08d57']);
    $walnut = $color->values()->create(['code' => 'walnut', 'value_en' => 'Walnut', 'value_ar' => 'جوز', 'color_hex' => '#5b3a29']);
    $this->actingAs($this->admin);
    Livewire::test(ProductVariants::class, ['product' => $this->product])
        ->call('togglePick', $color->id, $oak->id)->call('togglePick', $color->id, $walnut->id)
        ->call('generate')->assertCount('generated', 2)->call('createGenerated')->assertHasNoErrors();
    $variants = $this->product->variants()->with('attributeValues')->get();
    expect($variants)->toHaveCount(2)->and($variants->pluck('sku')->all())->toContain('OSLO-1-WALNUT');
    expect($variants->firstWhere('sku', 'OSLO-1')->attributeValues->pluck('code')->all())->toBe(['oak']);
});

it('uploads, reorders and deletes media through MediaService', function () {
    Storage::fake('public');
    $this->actingAs($this->admin);
    $c = Livewire::test(ProductMedia::class, ['product' => $this->product])
        ->set('uploads', [UploadedFile::fake()->image('a.jpg', 800, 600), UploadedFile::fake()->image('b.png', 400, 400)])->assertHasNoErrors();
    $images = $this->product->images()->get();
    expect($images)->toHaveCount(2);
    $c->call('sortImage', $images[1]->id, 0);
    expect($this->product->images()->first()->id)->toBe($images[1]->id);
    $c->set("meta.{$images[0]->id}.alt_ar", 'كنبة')->call('saveMeta', $images[0]->id);
    expect($images[0]->refresh()->alt_ar)->toBe('كنبة');
    $c->call('delete', $images[0]->id);
    expect($this->product->images()->count())->toBe(1);
});

it('manages categories, ordering and re-parenting via CategoryService', function () {
    $svc = app(CategoryService::class);
    $living = $svc->create(['translations' => ['en' => ['name' => 'Living'], 'ar' => ['name' => 'المعيشة']]]);
    $dining = $svc->create(['translations' => ['en' => ['name' => 'Dining'], 'ar' => ['name' => 'الطعام']], 'sort_order' => 1]);
    $this->actingAs($this->admin);
    Livewire::test(CategoryIndex::class)->call('create', $living->id)->set('form.name_en', 'Sofas')->set('form.name_ar', 'الكنب')->call('save')->assertHasNoErrors();
    $sofas = Category::where('slug', 'sofas')->firstOrFail();
    expect($sofas->parent_id)->toBe($living->id)->and($sofas->depth)->toBe(1);

    Livewire::test(CategoryIndex::class)->call('edit', $sofas->id)->set('form.parent_id', $dining->id)->call('save')->assertHasNoErrors();
    expect($sofas->refresh()->path)->toBe('/'.$dining->id.'/');
    // Cannot move a category under its own child.
    Livewire::test(CategoryIndex::class)->call('edit', $dining->id)->set('form.parent_id', $sofas->id)->call('save')->assertDispatched('toast', type: 'error');

    Livewire::test(CategoryIndex::class)->call('move', $dining->id, -1);
    expect($dining->refresh()->sort_order)->toBe(0);
    Livewire::test(CategoryIndex::class)->call('delete', $dining->id)->assertDispatched('toast', type: 'error');
});

it('manages brands and attribute values with swatches', function () {
    Storage::fake('public');
    $this->actingAs($this->admin);
    Livewire::test(BrandIndex::class)->call('create')->set('form.name_en', 'Nordic Home')->set('form.name_ar', 'نورديك هوم')
        ->set('logo', UploadedFile::fake()->image('logo.png'))->call('save')->assertHasNoErrors();
    $brand = Brand::where('slug', 'nordic-home')->firstOrFail();
    Storage::disk('public')->assertExists($brand->logo_path);
    expect(AuditLog::where('action', 'catalog.brand_created')->exists())->toBeTrue();

    $c = Livewire::test(AttributeIndex::class)->call('create')->set('form.code', 'finish')->set('form.name_en', 'Finish')->set('form.name_ar', 'التشطيب')->set('form.type', 'color')->call('save')->assertHasNoErrors();
    $c->set('valueForm.value_en', 'Matte black')->set('valueForm.value_ar', 'أسود مطفي')->set('valueForm.color_hex', 'bad')->call('saveValue')->assertHasErrors('valueForm.color_hex');
    $c->set('valueForm.color_hex', '#111111')->call('saveValue')->assertHasNoErrors();
    expect(ProductAttribute::where('code', 'finish')->first()->values()->first()->code)->toBe('matte_black');
});

it('imports a CSV with a per-row error report', function () {
    $this->actingAs($this->admin);
    $csv = "sku,name_en,name_ar,price,status\nNEW-1,Lamp,مصباح,120.00,active\nOSLO-1,Oslo updated,أوسلو,990.00,active\nBAD SKU,,x,abc,\n";
    $file = UploadedFile::fake()->createWithContent('products.csv', $csv);
    Livewire::test(ImportIndex::class)->set('file', $file)->call('import')->assertHasNoErrors()->assertSee('products.csv');
    $import = CatalogImport::latest()->firstOrFail();
    expect($import->created_rows)->toBe(1)->and($import->updated_rows)->toBe(1)->and($import->failed_rows)->toBe(1);
    Livewire::test(ImportIndex::class)->call('template')->assertFileDownloaded('albahar-catalog-template.csv');
    Livewire::test(ImportIndex::class)->call('export')->assertFileDownloaded();
    expect(AuditLog::where('action', 'catalog.exported')->exists())->toBeTrue();
});
