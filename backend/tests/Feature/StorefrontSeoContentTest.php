<?php

use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\CmsPage;
use Illuminate\Support\Facades\File;

/*
 * Roadmap 6.7, server side: the first HTML of every public page already carries what a reader without JavaScript needs (the page's real text in a
 * <noscript> copy), the Arabic address of each page (`?lang=ar`) with hreflang links in both directions, a language-correct <html lang dir>,
 * social tags, and honest status codes. The shell here is a throw-away file, as in StorefrontSeoTest.
 */

beforeEach(function () {
    $this->publicDir = sys_get_temp_dir().'/albahar-seoc-'.getmypid().'-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->publicDir);
    config(['platform.storefront_shell' => $this->publicDir.'/index.html', 'app.url' => 'https://shop.example']);
    file_put_contents($this->publicDir.'/index.html', '<!doctype html><html lang="en" dir="ltr"><head><meta charset="UTF-8" /><meta name="description" content="Base description" /><title>Al Bahar Home &amp; Living</title></head><body><div id="root"></div></body></html>');
});

afterEach(fn () => File::deleteDirectory($this->publicDir));

/** The text inside the page's <noscript> copy. */
function noscriptOf(string $html): string
{
    preg_match('#<noscript>(.*?)</noscript>#s', $html, $m);

    return $m[1] ?? '';
}

function sofa(array $en = [], array $ar = []): Product
{
    $p = Product::factory()->create(['price' => '4500.00', 'brand_id' => Brand::factory()->create()->id]);
    $p->translations()->where('locale', 'en')->update(['name' => 'Oslo Sofa', 'slug' => 'oslo-sofa', 'short_description' => 'A deep, soft three seater.', 'description' => '<p>Grey linen.</p><script>alert(1)</script>', ...$en]);
    $p->translations()->where('locale', 'ar')->update(['name' => 'كنبة أوسلو', 'slug' => 'كنبة-أوسلو', 'short_description' => 'كنبة ثلاثية عميقة وناعمة.', ...$ar]);

    return $p->fresh();
}

it('writes the product text, price and availability into the first HTML, matching the structured data', function () {
    $p = sofa();
    $html = $this->get('/products/oslo-sofa')->assertOk()->getContent();
    $copy = noscriptOf($html);

    expect($copy)->toContain('<h1>Oslo Sofa</h1>')->toContain('A deep, soft three seater.')->toContain('QAR 4,500.00')->toContain('<dt>Price</dt>')
        ->toContain('<dt>Brand</dt>')->toContain($p->sku)->toContain('Grey linen.')
        ->not->toContain('<script')->not->toContain('alert(1)');
    preg_match('#"@type":"Product".*?"price":"([^"]+)"#', $html, $price);
    expect($price[1])->toBe('4500.00')->and($copy)->toContain('4,500.00');
});

it('answers the Arabic address of a product in Arabic: lang, dir, title, canonical, hreflang and the plain copy', function () {
    sofa();
    $html = $this->get('/products/oslo-sofa?lang=ar')->assertOk()->getContent();

    expect($html)->toContain('<html lang="ar" dir="rtl">')->toContain('<title>كنبة أوسلو · البحر للمنزل والمعيشة</title>')
        ->toContain('<link rel="canonical" href="https://shop.example/products/oslo-sofa?lang=ar" />')
        ->toContain('<link rel="alternate" hreflang="en" href="https://shop.example/products/oslo-sofa" />')
        ->toContain('<link rel="alternate" hreflang="ar" href="https://shop.example/products/oslo-sofa?lang=ar" />')
        ->toContain('<link rel="alternate" hreflang="x-default" href="https://shop.example/products/oslo-sofa" />')
        ->toContain('property="og:locale" content="ar_QA"')->toContain('property="og:site_name" content="البحر للمنزل والمعيشة"')
        ->toContain('name="twitter:title" content="كنبة أوسلو · البحر للمنزل والمعيشة"');
    expect(noscriptOf($html))->toContain('<h1>كنبة أوسلو</h1>')->toContain('كنبة ثلاثية عميقة وناعمة.')->toContain('<dt>السعر</dt>');
});

it('keeps English as the default and links both languages from the English page', function () {
    sofa();
    $html = $this->get('/products/oslo-sofa')->getContent();

    expect($html)->toContain('<html lang="en" dir="ltr">')->toContain('property="og:locale" content="en_QA"')
        ->toContain('<link rel="canonical" href="https://shop.example/products/oslo-sofa" />')
        ->toContain('hreflang="ar" href="https://shop.example/products/oslo-sofa?lang=ar"');
    // an unknown language value is English, never an error
    expect($this->get('/products/oslo-sofa?lang=zz')->assertOk()->getContent())->toContain('<html lang="en" dir="ltr">');
});

it('does not cache one language into the other', function () {
    sofa();
    $this->get('/products/oslo-sofa?lang=ar')->assertOk();

    expect($this->get('/products/oslo-sofa')->getContent())->toContain('<title>Oslo Sofa · Al Bahar Home &amp; Living</title>')
        ->and($this->get('/products/oslo-sofa?lang=ar')->getContent())->toContain('كنبة أوسلو');
});

it('lists the products of a category and its Arabic name in the plain copy and the structured data', function () {
    $cat = Category::factory()->create(['slug' => 'sofas']);
    $cat->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'Sofas', 'description' => '<p>Seats for everyone.</p>']);
    $cat->translations()->updateOrCreate(['locale' => 'ar'], ['name' => 'الكنب', 'description' => '<p>مقاعد للجميع.</p>']);
    sofa()->update(['category_id' => $cat->id]);

    $en = $this->get('/shop?category=sofas')->assertOk()->getContent();
    $ar = $this->get('/shop?category=sofas&lang=ar')->assertOk()->getContent();

    expect(noscriptOf($en))->toContain('<h1>Sofas</h1>')->toContain('Seats for everyone.')->toContain('href="https://shop.example/products/oslo-sofa">Oslo Sofa</a> — QAR 4,500.00')
        ->and(noscriptOf($ar))->toContain('<h1>الكنب</h1>')->toContain('مقاعد للجميع.')->toContain('href="https://shop.example/products/oslo-sofa?lang=ar">كنبة أوسلو</a>')
        ->and($ar)->toContain('<title>الكنب · البحر للمنزل والمعيشة</title>')->toContain('"inLanguage":"ar"');
});

it('puts the home page content, categories and organisation data in the first HTML', function () {
    Category::factory()->create(['slug' => 'bath'])->translations()->updateOrCreate(['locale' => 'en'], ['name' => 'Bath']);
    sofa();
    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('<title>Al Bahar Home &amp; Living | Furniture and household essentials in Qatar</title>')->not->toContain('Base description')
        ->and(noscriptOf($html))->toContain('<h1>Al Bahar Home &amp; Living</h1>')->toContain('>Bath</a>')->toContain('>Oslo Sofa</a>')
        ->and($html)->toContain('"logo":"https://shop.example/store/icons/icon-512.png"');
    expect($this->get('/?lang=ar')->getContent())->toContain('<title>البحر للمنزل والمعيشة | أثاث ومستلزمات منزلية في قطر</title>');
});

it('puts a published page\'s text in the first HTML, without scripts, handlers or unsafe links', function () {
    $page = CmsPage::create(['slug' => 'about', 'status' => 'published', 'published_at' => now()]);
    $page->translations()->create(['locale' => 'en', 'title' => 'About us', 'body' => '<h2>Who we are</h2><p onclick="steal()">We sell <a href="javascript:alert(1)" onclick="x()">home goods</a> and <a href="/shop" class="x">more</a>.</p><script>alert(1)</script><iframe src="https://evil.example"></iframe>']);
    $page->translations()->create(['locale' => 'ar', 'title' => 'من نحن', 'body' => '<p>نبيع مستلزمات المنزل.</p>']);

    $en = noscriptOf($this->get('/pages/about')->assertOk()->getContent());
    $ar = $this->get('/pages/about?lang=ar')->assertOk()->getContent();

    expect($en)->toContain('<h1>About us</h1>')->toContain('<h2>Who we are</h2>')->toContain('<a href="/shop">more</a>')->toContain('<a>home goods</a>')
        ->not->toContain('onclick')->not->toContain('javascript:')->not->toContain('<script')->not->toContain('<iframe')
        ->and(noscriptOf($ar))->toContain('<h1>من نحن</h1>')->toContain('نبيع مستلزمات المنزل.')
        ->and($ar)->toContain('<title>من نحن · البحر للمنزل والمعيشة</title>');
});

it('falls back to English for a page that has no Arabic text yet', function () {
    $page = CmsPage::create(['slug' => 'terms', 'status' => 'published', 'published_at' => now()]);
    $page->translations()->create(['locale' => 'en', 'title' => 'Terms', 'body' => '<p>Only English.</p>']);

    expect(noscriptOf($this->get('/pages/terms?lang=ar')->assertOk()->getContent()))->toContain('<h1>Terms</h1>')->toContain('Only English.');
});

it('answers a real 404 in both languages for a missing product, page, deal, auction or lot', function () {
    foreach (['/products/nope', '/pages/nope', '/deals/'.fake()->uuid(), '/auctions/'.fake()->uuid(), '/auctions/lots/'.fake()->uuid(), '/auctions/lots/abc'] as $path) {
        foreach (['', '?lang=ar'] as $lang) {
            $r = $this->get($path.$lang)->assertNotFound();
            expect($r->getContent())->toContain('noindex')->not->toContain('hreflang')->not->toContain('og:title');
        }
    }
    expect($this->get('/products/nope?lang=ar')->getContent())->toContain('<title>غير موجود · البحر للمنزل والمعيشة</title>')->toContain('<html lang="ar" dir="rtl">');
});

it('serves a published auction and its lot, and answers 404 for a draft auction', function () {
    $w = auctionWorld();
    $draft = app(AuctionService::class)->save($w->admin, ['type' => 'scheduled', 'translations' => ['en' => ['title' => 'Draft one'], 'ar' => ['title' => 'مسودة']]]);

    $this->get('/auctions/'.$w->auction->id)->assertOk();
    $this->get('/auctions/lots/'.$w->lot->id)->assertOk();
    $this->get('/auctions/'.$draft->id)->assertNotFound();
});

it('keeps private pages out of the index and without a plain copy or alternates', function () {
    foreach (['/cart', '/checkout', '/account/orders', '/login'] as $path) {
        $html = $this->get($path.'?lang=ar')->assertOk()->getContent();
        expect($html)->toContain('noindex,nofollow')->not->toContain('<noscript>')->not->toContain('hreflang');
    }
});

it('lists each public address in both languages in the sitemap, with hreflang, and leaves deals as one address', function () {
    sofa();
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('xmlns:xhtml="http://www.w3.org/1999/xhtml"')
        ->toContain('<loc>https://shop.example/products/oslo-sofa</loc>')->toContain('<loc>https://shop.example/products/oslo-sofa?lang=ar</loc>')
        ->toContain('<xhtml:link rel="alternate" hreflang="ar" href="https://shop.example/products/oslo-sofa?lang=ar"/>')
        ->toContain('<xhtml:link rel="alternate" hreflang="x-default" href="https://shop.example/"/>');
    expect(simplexml_load_string($xml))->not->toBeFalse();
    expect(substr_count($xml, 'lang=ar</loc>'))->toBeGreaterThan(2)->and($xml)->not->toContain('/deals?lang=ar');
});

it('serves robots.txt that welcomes crawlers (AI ones included) and names the sitemap on the production domain', function () {
    $txt = $this->get('/robots.txt')->assertOk()->getContent();

    expect($txt)->toContain('User-agent: *')->toContain('Allow: /')->toContain('Sitemap: https://shop.example/sitemap.xml')
        ->not->toContain('GPTBot')->not->toContain('Disallow: /products')->not->toContain('Disallow: /shop')->not->toContain('Disallow: /pages');
});

it('preloads the page\'s own code and the first-paint fonts from the build manifest, and adds nothing without one', function () {
    sofa();
    $manifest = [
        'index.html' => ['file' => 'assets/index-AAA.js', 'isEntry' => true],
        'src/pages/Product.tsx' => ['file' => 'assets/Product-BBB.js', 'imports' => ['index.html', '_store-CCC.js']],
        '_store-CCC.js' => ['file' => 'assets/store-CCC.js', 'imports' => ['index.html']],
        'src/pages/Cart.tsx' => ['file' => 'assets/Cart-DDD.js', 'imports' => ['index.html', '_store-CCC.js']],
        'node_modules/@fontsource/inter/files/inter-latin-400-normal.woff2' => ['file' => 'assets/inter-latin-400-normal-EEE.woff2'],
        'node_modules/@fontsource/ibm-plex-sans-arabic/files/ibm-plex-sans-arabic-arabic-400-normal.woff2' => ['file' => 'assets/plex-400-FFF.woff2'],
    ];

    expect($this->get('/products/oslo-sofa')->getContent())->not->toContain('modulepreload')->not->toContain('as="font"');

    File::ensureDirectoryExists($this->publicDir.'/.vite');
    file_put_contents($this->publicDir.'/.vite/manifest.json', json_encode($manifest));
    $product = $this->get('/products/oslo-sofa')->getContent();
    $arabic = $this->get('/?lang=ar')->getContent();

    expect($product)->toContain('<link rel="modulepreload" crossorigin href="/store/assets/Product-BBB.js" />')->toContain('href="/store/assets/store-CCC.js"')
        ->not->toContain('index-AAA.js')->not->toContain('Cart-DDD.js')->toContain('<link rel="preload" as="font" type="font/woff2" crossorigin href="/store/assets/inter-latin-400-normal-EEE.woff2" />')
        ->not->toContain('plex-400-FFF')
        ->and($arabic)->toContain('/store/assets/plex-400-FFF.woff2')->not->toContain('modulepreload')->not->toContain('inter-latin-400');
});
