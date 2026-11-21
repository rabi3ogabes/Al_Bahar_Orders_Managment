<?php

use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\File;

/*
 * "Share on WhatsApp" (docs/guides/DELIVERY_PROMISE_ASSEMBLY_AND_SHARING.md): the shared link is a plain product address, and WhatsApp (and every other
 * messenger) builds its preview card from the tags the server writes into the page's <head>, because it never runs the storefront's JavaScript.
 * These tests keep that card right: title, description, one absolute picture, the same address everywhere, and nothing added by a tracking query.
 */

beforeEach(function () {
    $this->publicDir = sys_get_temp_dir().'/albahar-share-'.getmypid().'-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->publicDir);
    config(['platform.storefront_shell' => $this->publicDir.'/index.html', 'app.url' => 'https://shop.example']);
    file_put_contents($this->publicDir.'/index.html', '<!doctype html><html><head><meta charset="UTF-8" /><meta name="description" content="Base description" /><title>Al Bahar Home &amp; Living</title></head><body><div id="root"></div></body></html>');
});

afterEach(fn () => File::deleteDirectory($this->publicDir));

function shareProduct(bool $withImage = true): Product
{
    $p = Product::factory()->create(['price' => '4500.00']);
    $p->translations()->where('locale', 'en')->update(['name' => 'Oslo 3-Seater Sofa', 'slug' => 'oslo-3-seater-sofa', 'short_description' => 'A deep, soft three seater in grey linen.']);
    if ($withImage) {
        $p->images()->create(['disk' => 'public', 'path' => 'products/oslo/photo.webp', 'variants' => [], 'width' => 1024, 'height' => 1024, 'sort_order' => 1]);
    }

    return $p->fresh();
}

/** The content of a meta tag by its property or name. */
function metaOf(string $html, string $key): ?string
{
    return preg_match('#<meta (?:property|name)="'.preg_quote($key, '#').'" content="([^"]*)"#', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : null;
}

it('gives a shared product link a full preview card: title, description, a picture and its address', function () {
    shareProduct();

    $html = $this->get('/products/oslo-3-seater-sofa')->assertOk()->getContent();

    expect(metaOf($html, 'og:title'))->toContain('Oslo 3-Seater Sofa')
        ->and(metaOf($html, 'og:description'))->toBe('A deep, soft three seater in grey linen.')
        ->and(metaOf($html, 'og:url'))->toBe('https://shop.example/products/oslo-3-seater-sofa')
        ->and(metaOf($html, 'og:type'))->toBe('product')
        ->and(metaOf($html, 'og:site_name'))->toBe('Al Bahar Home & Living')
        ->and(metaOf($html, 'twitter:card'))->toBe('summary_large_image')
        ->and(metaOf($html, 'og:image'))->toStartWith('http')->toContain('products/oslo/photo.webp')
        ->and(substr_count($html, 'property="og:image"'))->toBe(1);
});

it('keeps the same address in the card whatever tracking the link was opened with', function () {
    shareProduct();

    $plain = $this->get('/products/oslo-3-seater-sofa')->getContent();
    $tracked = $this->get('/products/oslo-3-seater-sofa?utm_source=whatsapp&fbclid=abc&ref=someone')->assertOk()->getContent();

    foreach ([$plain, $tracked] as $html) {
        expect(metaOf($html, 'og:url'))->toBe('https://shop.example/products/oslo-3-seater-sofa')
            ->and($html)->toContain('<link rel="canonical" href="https://shop.example/products/oslo-3-seater-sofa" />')
            ->and($html)->not->toContain('utm_source')->not->toContain('fbclid');
    }
});

it('falls back to a small card when the product has no picture, and never invents one', function () {
    shareProduct(withImage: false);

    $html = $this->get('/products/oslo-3-seater-sofa')->getContent();

    expect(metaOf($html, 'og:image'))->toBeNull()->and(metaOf($html, 'twitter:card'))->toBe('summary')->and(metaOf($html, 'og:title'))->toContain('Oslo 3-Seater Sofa');
});

it('does not build a preview card for a product that is not for sale (draft or missing)', function () {
    $p = shareProduct();
    $p->update(['status' => 'draft']);

    $html = $this->get('/products/oslo-3-seater-sofa')->assertNotFound()->getContent();

    expect($html)->toContain('noindex')->and(metaOf($html, 'og:image'))->toBeNull();
});
