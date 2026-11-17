<?php

use App\Domain\Deals\Services\DealService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

require_once __DIR__.'/DealFixtures.php';

beforeEach(function () {
    $this->w = commerceWorld();
    $this->publicDir = sys_get_temp_dir().'/albahar-public-'.getmypid().'-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->publicDir);
    config(['platform.storefront_shell' => $this->publicDir.'/index.html', 'app.url' => 'https://shop.example']);
    file_put_contents($this->publicDir.'/index.html', '<!doctype html><html><head><meta charset="UTF-8" /><meta name="description" content="Base description" /><title>Al Bahar Home &amp; Living</title></head><body><div id="root"></div></body></html>');
    app('auth')->forgetGuards();
});

afterEach(function () {
    File::deleteDirectory($this->publicDir);
});

function dealSeoBlocks(string $html): array
{
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

    return array_map(fn ($j) => json_decode($j, true, flags: JSON_THROW_ON_ERROR), $m[1]);
}

it('titles and describes the deals page in English and Arabic, with structured data for both', function () {
    $html = $this->get('/deals')->assertOk()->getContent();

    expect($html)->toContain('<title>Deals | العروض · Al Bahar Home &amp; Living</title>')
        ->and($html)->toContain('Limited-time deals with a live countdown')->toContain('عروض لفترة محدودة بعدّاد تنازلي')
        ->and($html)->toContain('<link rel="canonical" href="https://shop.example/deals" />')->not->toContain('name="robots"');
    [$page, $crumbs] = dealSeoBlocks($html);
    expect($page['@type'])->toBe('CollectionPage')->and($page['name'])->toBe([['@language' => 'en', '@value' => 'Deals'], ['@language' => 'ar', '@value' => 'العروض']])
        ->and($page['url'])->toBe('https://shop.example/deals')->and($crumbs['@type'])->toBe('BreadcrumbList');
});

it('titles a running deal with both names and describes it with both taglines', function () {
    $deal = makeDeal(['name_en' => 'Weekend Furniture Drop', 'name_ar' => 'عرض الأثاث', 'tagline_en' => 'Big pieces, small prices', 'tagline_ar' => 'قطع كبيرة وأسعار صغيرة']);

    $html = $this->get('/deals/'.$deal->id)->assertOk()->getContent();

    expect($html)->toContain('<title>Weekend Furniture Drop | عرض الأثاث · Al Bahar Home &amp; Living</title>')
        ->and($html)->toContain('content="Big pieces, small prices قطع كبيرة وأسعار صغيرة"')
        ->and($html)->toContain('<link rel="canonical" href="https://shop.example/deals/'.$deal->id.'" />')->not->toContain('name="robots"')
        ->and($html)->toContain('property="og:title"');
    [$page, $crumbs] = dealSeoBlocks($html);
    expect($page['name'])->toBe([['@language' => 'en', '@value' => 'Weekend Furniture Drop'], ['@language' => 'ar', '@value' => 'عرض الأثاث']])
        ->and($page['description'][1])->toBe(['@language' => 'ar', '@value' => 'قطع كبيرة وأسعار صغيرة'])
        ->and(collect($crumbs['itemListElement'])->pluck('name')->all())->toBe(['Home', 'Deals', 'Weekend Furniture Drop']);
});

it('writes a description from the deal itself when there is no tagline', function () {
    $deal = makeDeal(['tagline_en' => null, 'tagline_ar' => null, 'ends_at' => now()->addDay()]);

    $html = $this->get('/deals/'.$deal->id)->assertOk()->getContent();

    expect($html)->toContain('30% off on 1 product, with a live countdown and a limited quantity. Ends')->toContain('خصم 30% على منتج واحد، مع عدّاد تنازلي وكمية محدودة. ينتهي');
});

it('asks search engines not to index an ended or paused deal, at once, and still answers the page', function () {
    $deal = makeDeal();
    expect($this->get('/deals/'.$deal->id)->getContent())->not->toContain('noindex'); // warm: nothing about deals may be remembered

    app(DealService::class)->setActive(dealAdmin(), $deal, false);
    $paused = $this->get('/deals/'.$deal->id)->assertOk()->getContent();
    expect($paused)->toContain('<meta name="robots" content="noindex,follow" />')->toContain('Deal ended | انتهى العرض');

    app(DealService::class)->setActive(dealAdmin(), $deal->fresh(), true);
    expect($this->get('/deals/'.$deal->id)->getContent())->not->toContain('noindex');
    app(DealService::class)->end(dealAdmin(), $deal->fresh());
    expect($this->get('/deals/'.$deal->id)->assertOk()->getContent())->toContain('content="noindex,follow"');
});

it('keeps a scheduled deal indexable and answers 404 for no deal at all', function () {
    $later = makeDeal(['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(3)]);

    expect($this->get('/deals/'.$later->id)->assertOk()->getContent())->not->toContain('noindex');
    $this->get('/deals/'.(string) Str::uuid())->assertNotFound();
    $this->get('/deals/not-a-deal')->assertNotFound();
});

it('lists /deals and the live deals in the sitemap, never a paused, ended or scheduled one, and refreshes it when a deal changes', function () {
    $empty = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($empty)->not->toContain('/deals');

    $live = makeDeal(['name_en' => 'Live']);
    $paused = makeDeal(['name_en' => 'Paused', 'is_active' => false], [$this->w->p2->id => 4]);
    $later = makeDeal(['name_en' => 'Later', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)], [dealProduct('70.00')->id => 4]);
    $xml = $this->get('/sitemap.xml')->getContent();

    expect($xml)->toContain('<loc>https://shop.example/deals</loc>')->toContain('<loc>https://shop.example/deals/'.$live->id.'</loc>')
        ->not->toContain($paused->id)->not->toContain($later->id);

    app(DealService::class)->end(dealAdmin(), $live);
    expect($this->get('/sitemap.xml')->getContent())->not->toContain('/deals');
});

it('never lets the deals API be cached, and reads the clock again on every request', function () {
    $deal = makeDeal();

    foreach (['/api/v1/deals', '/api/v1/deals/'.$deal->id] as $url) {
        $res = $this->getJson($url)->assertOk();
        expect($res->headers->get('Cache-Control'))->toContain('no-store')->toContain('max-age=0')->toContain('private')
            ->and($res->headers->get('Vary'))->toContain('Authorization')->toContain('Accept-Language');
    }
    $first = $this->getJson('/api/v1/deals')->json('data');
    $this->travel(95)->seconds();
    $second = $this->getJson('/api/v1/deals')->json('data');

    expect(Carbon::parse($first['server_time'])->diffInSeconds(Carbon::parse($second['server_time'])))->toBe(95.0)
        ->and($first['live'][0]['seconds_left'] - $second['live'][0]['seconds_left'])->toBe(95);
    // The two clocks in one response agree: the countdown and server_time come from the same moment.
    $one = $this->getJson('/api/v1/deals/'.$deal->id)->json('data');
    expect(Carbon::parse($one['server_time'])->diffInSeconds(Carbon::parse($one['ends_at'])))->toBe((float) $one['seconds_left']);
    Cache::flush();
});
