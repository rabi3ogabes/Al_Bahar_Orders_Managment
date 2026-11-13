<?php

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\CmsPage;
use Illuminate\Support\Facades\File;

/*
 * What search engines and link previews get for each storefront address: the shell's <head> is rewritten per page from the real
 * catalogue, a missing product or page answers 404, private pages ask not to be indexed, and /sitemap.xml and /robots.txt exist.
 */

beforeEach(function () {
    $this->shell = public_path('store/index.html');
    $this->hadShell = is_file($this->shell);
    $this->backup = $this->hadShell ? file_get_contents($this->shell) : null;
    File::ensureDirectoryExists(dirname($this->shell));
    file_put_contents($this->shell, '<!doctype html><html><head><meta charset="UTF-8" /><meta name="description" content="Base description" /><title>Al Bahar Home &amp; Living</title></head><body><div id="root"></div></body></html>');
    config(['app.url' => 'https://shop.example']);
});

afterEach(function () {
    $this->hadShell ? file_put_contents($this->shell, $this->backup) : File::delete($this->shell);
});

/** All JSON-LD blocks of a page, decoded. */
function ldBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn ($j) => json_decode($j, true, flags: JSON_THROW_ON_ERROR), $m[1]);
}

function seoProduct(array $attrs = [], array $en = []): Product
{
    $p = Product::factory()->create(['price' => '129.00'] + $attrs);
    $p->translations()->where('locale', 'en')->update(['name' => 'Linen Sofa "Oslo"', 'slug' => 'linen-sofa-oslo', 'short_description' => 'Soft & strong <b>linen</b> sofa.', ...$en]);

    return $p->fresh();
}

it('writes the product title, description, canonical link, social tags and structured data into the page', function () {
    $brand = Brand::factory()->create();
    $p = seoProduct(['brand_id' => $brand->id]);
    $p->images()->create(['disk' => 'public', 'path' => 'products/x/photo.webp', 'variants' => [], 'width' => 1024, 'height' => 1024, 'sort_order' => 1]);

    $html = $this->get('/products/linen-sofa-oslo')->assertOk()->getContent();

    expect($html)->toContain('<title>Linen Sofa &quot;Oslo&quot; · Al Bahar Home &amp; Living</title>')
        ->and($html)->toContain('<meta name="description" content="Soft &amp; strong linen sofa." />')
        ->and($html)->toContain('<link rel="canonical" href="https://shop.example/products/linen-sofa-oslo" />')
        ->and($html)->toContain('property="og:type" content="product"')
        ->and($html)->toContain('property="og:image" content="')->toContain('products/x/photo.webp')
        ->and($html)->toContain('name="twitter:card" content="summary_large_image"')
        ->and($html)->toContain('property="product:price:amount" content="129.00"')
        ->and($html)->not->toContain('noindex')
        ->and(substr_count($html, '<title>'))->toBe(1)->and(substr_count($html, 'name="description"'))->toBe(1);
    [$product, $crumbs] = ldBlocks($html);
    expect($product['@type'])->toBe('Product')->and($product['name'])->toBe('Linen Sofa "Oslo"')->and($product['sku'])->toBe($p->sku)
        ->and($product['brand']['name'])->toBe($brand->localized('name'))
        ->and($product['offers'])->toMatchArray(['priceCurrency' => 'QAR', 'price' => '129.00', 'url' => 'https://shop.example/products/linen-sofa-oslo'])
        ->and($product['offers']['availability'])->toBe('https://schema.org/OutOfStock')   // nothing is in stock in this test
        ->and($product)->not->toHaveKey('aggregateRating')                                      // no reviews, so no rating is claimed
        ->and($crumbs['@type'])->toBe('BreadcrumbList')->and(end($crumbs['itemListElement'])['name'])->toBe('Linen Sofa "Oslo"');
});

it('describes a rating only when real reviews exist', function () {
    seoProduct(['rating_average' => 4.5, 'rating_count' => 12]);

    $product = ldBlocks($this->get('/products/linen-sofa-oslo')->getContent())[0];

    expect($product['aggregateRating'])->toMatchArray(['ratingValue' => '4.5', 'reviewCount' => 12]);
});

it('shortens a long default title so it still fits a search result', function () {
    seoProduct([], ['name' => 'Stainless Steel Cookware Set, Five Pieces With Glass Lids']);

    $html = $this->get('/products/linen-sofa-oslo')->getContent();

    expect($html)->toContain('<title>Stainless Steel Cookware Set, Five Pieces With Glass Lids · Al Bahar</title>');
});

it('uses the editor\'s own search title and description when they are set', function () {
    seoProduct([], ['seo_title' => 'Oslo sofa, linen, three seats', 'seo_description' => 'Our best-selling sofa.']);

    $html = $this->get('/products/linen-sofa-oslo')->getContent();

    expect($html)->toContain('<title>Oslo sofa, linen, three seats</title>')->toContain('content="Our best-selling sofa."');
});

it('answers 404 and noindex for a product that does not exist, is a draft or is archived', function () {
    Product::factory()->draft()->create()->translations()->where('locale', 'en')->update(['slug' => 'secret-draft']);

    foreach (['/products/nope', '/products/secret-draft'] as $path) {
        $r = $this->get($path)->assertNotFound();
        expect($r->getContent())->toContain('noindex')->not->toContain('og:type" content="product"');
    }
});

it('cannot be broken out of by a product name that contains markup', function () {
    seoProduct([], ['name' => 'Sofa </script><script>alert(1)</script> & "co"', 'short_description' => 'x </title><script>alert(2)</script>']);

    $html = $this->get('/products/linen-sofa-oslo')->getContent();

    expect($html)->not->toContain('<script>alert')->not->toContain('</script><script>');
    foreach (ldBlocks($html) as $block) {
        expect($block)->toBeArray();
    }
});

it('describes a category and keeps the shop page canonical', function () {
    $cat = Category::factory()->create(['slug' => 'bedding']);
    $cat->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'Bedding & Linens', 'description' => '<p>Soft sheets and duvets.</p>']);

    $html = $this->get('/shop?category=bedding&sort=price')->assertOk()->getContent();

    expect($html)->toContain('<title>Bedding &amp; Linens · Al Bahar Home &amp; Living</title>')->toContain('content="Soft sheets and duvets."')
        ->toContain('<link rel="canonical" href="https://shop.example/shop?category=bedding" />');
    expect($this->get('/shop?category=unknown')->assertOk()->getContent())->toContain('rel="canonical" href="https://shop.example/shop"');
    expect($this->get('/shop?q=towel')->getContent())->toContain('content="noindex,follow"');
});

it('describes published information pages and 404s unknown or unpublished ones', function () {
    $page = CmsPage::create(['slug' => 'about', 'status' => 'published', 'published_at' => now()]);
    $page->translations()->create(['locale' => 'en', 'title' => 'About Al Bahar', 'body' => '<p>We bring everyday home essentials to Qatar.</p>']);
    $draft = CmsPage::create(['slug' => 'soon', 'status' => 'draft']);
    $draft->translations()->create(['locale' => 'en', 'title' => 'Soon', 'body' => '<p>x</p>']);

    $html = $this->get('/pages/about')->assertOk()->getContent();

    expect($html)->toContain('<title>About Al Bahar · Al Bahar Home &amp; Living</title>')->toContain('We bring everyday home essentials to Qatar.');
    $this->get('/pages/soon')->assertNotFound();
    $this->get('/pages/missing')->assertNotFound();
});

it('gives the home page its organisation and search box data, and keeps the shell title', function () {
    $html = $this->get('/')->assertOk()->getContent();
    $types = array_column(ldBlocks($html), '@type');

    expect($types)->toBe(['Organization', 'WebSite'])->and($html)->toContain('<title>Al Bahar Home &amp; Living</title>')->toContain('content="Base description"');
    expect(ldBlocks($html)[1]['potentialAction']['target']['urlTemplate'])->toBe('https://shop.example/shop?q={search_term_string}');
});

it('asks search engines to stay out of the cart, checkout, account and order pages, and answers 404 for unknown addresses', function () {
    foreach (['/cart', '/checkout', '/account/orders', '/orders/abc', '/login'] as $path) {
        expect($this->get($path)->assertOk()->getContent())->toContain('<meta name="robots" content="noindex,nofollow" />')->not->toContain('og:title');
    }
    $this->get('/something/else')->assertNotFound();
    $this->get('/auctions/lots/abc')->assertOk();
});

it('lists every public address in the sitemap and leaves out drafts and archived products', function () {
    seoProduct();
    Product::factory()->draft()->create()->translations()->where('locale', 'en')->update(['slug' => 'a-draft']);
    Product::factory()->create(['status' => 'archived'])->translations()->where('locale', 'en')->update(['slug' => 'archived-one']);
    Category::factory()->create(['slug' => 'bath'])->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'Bath']);
    $p = CmsPage::create(['slug' => 'terms', 'status' => 'published', 'published_at' => now()]);
    $p->translations()->create(['locale' => 'en', 'title' => 'Terms', 'body' => '<p>t</p>']);

    $r = $this->get('/sitemap.xml')->assertOk();
    $xml = simplexml_load_string($r->getContent());
    $locs = array_map('strval', iterator_to_array($xml->url->loc ?? [], false));
    $all = array_map(fn ($u) => (string) $u->loc, iterator_to_array($xml->url, false));

    expect($r->headers->get('Content-Type'))->toContain('application/xml')
        ->and($all)->toContain('https://shop.example/', 'https://shop.example/shop', 'https://shop.example/shop?category=bath', 'https://shop.example/products/linen-sofa-oslo', 'https://shop.example/pages/terms')
        ->and($all)->not->toContain('https://shop.example/products/a-draft')->not->toContain('https://shop.example/products/archived-one')
        ->and($locs)->toBeArray();
});

it('serves robots.txt with the private areas and the sitemap address', function () {
    $r = $this->get('/robots.txt')->assertOk();

    expect($r->headers->get('Content-Type'))->toContain('text/plain')
        ->and($r->getContent())->toContain('Disallow: /admin')->toContain('Disallow: /checkout')->toContain('Disallow: /account')->toContain('Sitemap: https://shop.example/sitemap.xml');
});
