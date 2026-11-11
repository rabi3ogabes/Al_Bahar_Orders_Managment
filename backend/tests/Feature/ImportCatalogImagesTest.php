<?php

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CategoryService;
use App\Domain\Catalog\Services\ProductService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function pngBytes(): string
{
    $im = imagecreatetruecolor(900, 900);
    imagefill($im, 0, 0, imagecolorallocate($im, 200, 160, 90));
    ob_start();
    imagepng($im);

    return (string) ob_get_clean();
}

function makeProduct(string $sku): Product
{
    $cat = app(CategoryService::class)->create(['slug' => 'cat-'.strtolower($sku), 'translations' => ['en' => ['name' => 'Cat'], 'ar' => ['name' => 'فئة']]]);

    return app(ProductService::class)->create([
        'sku' => $sku, 'price' => '10.00', 'status' => 'active', 'category_id' => $cat->id, 'variants' => [],
        'translations' => ['en' => ['name' => 'Soft Tissues', 'short_description' => 'x', 'description' => '<p>x</p>'], 'ar' => ['name' => 'مناديل', 'short_description' => 'x', 'description' => '<p>x</p>']],
    ]);
}

it('imports pictures from a manifest through the media service and skips products that already have them', function () {
    Storage::fake('public');
    config(['platform.media.disk' => 'public']);
    Http::fake(['https://img.example/ok.png' => Http::response(pngBytes(), 200, ['Content-Type' => 'image/png']), 'https://img.example/missing.png' => Http::response('', 404)]);
    $product = makeProduct('AM-1');
    $manifest = 'database/data/_test_images.json';
    file_put_contents(base_path($manifest), json_encode(['AM-1' => ['https://img.example/ok.png', 'https://img.example/missing.png'], 'AM-404' => ['https://img.example/ok.png'], 'AM-2' => []]));

    $this->artisan('catalog:import-images', ['manifest' => $manifest, '--delay' => 0])->assertSuccessful();

    $images = $product->images()->get();
    expect($images)->toHaveCount(1)
        ->and($images[0]->path)->toEndWith('.webp')
        ->and($images[0]->alt_en)->toBe('Soft Tissues')
        ->and($images[0]->alt_ar)->toBe('مناديل');
    Storage::disk('public')->assertExists($images[0]->path);

    // Second run changes nothing (idempotent).
    $this->artisan('catalog:import-images', ['manifest' => $manifest, '--delay' => 0])->assertSuccessful();
    expect($product->images()->count())->toBe(1);
    @unlink(base_path($manifest));
});

it('refuses non-https and non-image downloads', function () {
    Storage::fake('public');
    config(['platform.media.disk' => 'public']);
    Http::fake(['https://img.example/page.html' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'text/html'])]);
    $product = makeProduct('AM-3');
    $manifest = 'database/data/_test_images2.json';
    file_put_contents(base_path($manifest), json_encode(['AM-3' => ['http://img.example/a.png', 'file:///etc/passwd', 'https://img.example/page.html']]));

    $this->artisan('catalog:import-images', ['manifest' => $manifest, '--delay' => 0])->assertSuccessful();

    expect($product->images()->count())->toBe(0);
    @unlink(base_path($manifest));
});
