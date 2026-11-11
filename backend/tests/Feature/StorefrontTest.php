<?php

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Identity\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(function () {
    $this->shell = public_path('store/index.html');
    $this->hadShell = is_file($this->shell);
    $this->backup = $this->hadShell ? file_get_contents($this->shell) : null;
});

afterEach(function () {
    $this->hadShell ? file_put_contents($this->shell, $this->backup) : File::delete($this->shell);
});

it('serves the storefront shell for the web root and client-side routes', function () {
    File::ensureDirectoryExists(dirname($this->shell));
    file_put_contents($this->shell, '<!doctype html><title>Al Bahar storefront</title>');

    foreach (['/', '/shop', '/products/oslo-linen-sofa', '/checkout', '/account/orders', '/auctions/lots/abc', '/pages/about'] as $path) {
        $response = $this->get($path)->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-cache');
        expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
        expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toContain('Al Bahar storefront');
    }
});

it('never lets the storefront catch-all swallow the API, the admin or provider endpoints', function () {
    File::ensureDirectoryExists(dirname($this->shell));
    file_put_contents($this->shell, '<!doctype html><title>Al Bahar storefront</title>');

    $this->getJson('/api/v1/health')->assertJsonStructure(['status']);
    expect($this->get('/admin/login')->assertOk()->baseResponse)->not->toBeInstanceOf(BinaryFileResponse::class);
    expect($this->get('/payments/sadad/return')->assertOk()->baseResponse)->not->toBeInstanceOf(BinaryFileResponse::class);
    $this->get('/api/v1/does-not-exist')->assertNotFound();
});

it('falls back to the admin sign-in when no storefront build is present', function () {
    File::delete($this->shell);

    $this->get('/')->assertRedirect('/admin');
});

it('returns web shoppers to the storefront order page after an online payment', function () {
    $this->get('/payments/sadad/return')->assertOk()->assertSee('albahar.web_checkout', false)->assertSee('albahar://orders', false);
});

it('shares product links that open the storefront product page', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $product = Product::factory()->create();
    $detail = app(CatalogPresenter::class)->detail($product->fresh(), null);

    expect($detail['share_url'])->toEndWith('/products/'.$product->slug)->not->toContain('/app/');
})->skip(fn () => ! class_exists(Product::class) || ! method_exists(Product::class, 'factory'), 'no product factory');

it('loads the sample catalogue without creating any account, even in production', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->app['env'] = 'production';
    $users = User::count();

    app(DemoCatalogSeeder::class)->run();

    expect(Branch::where('code', 'WB')->exists())->toBeTrue()
        ->and(Product::count())->toBeGreaterThan(5)
        ->and(User::count())->toBe($users);

    // The regular demo seeder still refuses in production.
    app(DemoSeeder::class)->run();
    expect(User::count())->toBe($users);
});

it('ships a consistent bilingual market catalogue file', function () {
    $c = json_decode(file_get_contents(database_path('data/market_catalog.json')), true, flags: JSON_THROW_ON_ERROR);
    $cats = collect($c['categories'])->pluck('slug');
    $brands = collect($c['brands'])->pluck('slug');
    $skus = collect($c['products'])->pluck('sku');

    expect($c['products'])->not->toBeEmpty()
        ->and($skus->unique()->count())->toBe($skus->count())
        ->and($cats->unique()->count())->toBe($cats->count());

    foreach ($c['products'] as $p) {
        expect($cats)->toContain($p['category']);
        expect($p['brand'] === null || $brands->contains($p['brand']))->toBeTrue();
        expect($p['name_en'])->not->toBe('')->and($p['name_ar'])->not->toBe('')->and($p['desc_en'])->not->toBe('')->and($p['desc_ar'])->not->toBe('');
        expect($p['price'])->toBeGreaterThan(0);
        expect($p['sale_price'] === null || $p['sale_price'] < $p['price'])->toBeTrue();
    }
});
