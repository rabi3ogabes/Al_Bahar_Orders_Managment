<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
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
