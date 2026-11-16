<?php

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Services\AuctionService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\BannerLinks;
use App\Domain\Cms\Services\HomeContentPurger;
use App\Domain\Cms\Services\HomeLayout;
use App\Domain\Cms\Services\HomepageService;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserRole;
use App\Livewire\Admin\Content\BannerIndex;
use App\Support\DomainException;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

// Home sliders: the order of the home sections and of the slides, the contract of GET /api/v1/home, links, and the dashboard page.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(config('platform.media.disk'));
    $this->marketing = staffWithRole('marketing_manager');
});

function section(string $type, array $extra = []): HomepageSection
{
    return HomepageSection::create($extra + ['type' => $type, 'layout' => 'carousel', 'status' => 'published', 'sort_order' => HomepageSection::count() + 1,
        'title_en' => $type === 'banners' ? null : ucfirst($type), 'settings' => $type === 'banners' ? ['slider' => []] : null]);
}

function slide(HomepageSection $slider, array $extra = []): Banner
{
    return Banner::create($extra + ['homepage_section_id' => $slider->id, 'placement' => 'home_hero', 'image_en_path' => 'https://cdn.example/'.uniqid().'.jpg', 'status' => 'published',
        'sort_order' => Banner::where('homepage_section_id', $slider->id)->count() + 1]);
}

function order(): array
{
    return HomepageSection::orderBy('sort_order')->orderBy('created_at')->orderBy('id')->pluck('id')->all();
}

function slidesOf(HomepageSection $slider): array
{
    return Banner::where('homepage_section_id', $slider->id)->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->pluck('id')->all();
}

function auctionIn(string $status): Auction
{
    $a = app(AuctionService::class)->save(staffWithRole('auction_manager'), ['type' => 'scheduled', 'requires_registration' => true, 'translations' => ['en' => ['title' => 'Heritage Majlis'], 'ar' => ['title' => 'مجلس']]]);
    $a->forceFill(['status' => $status])->save();

    return $a;
}

function photo(string $name = 'a.jpg', int $w = 1600, int $h = 900): UploadedFile
{
    return UploadedFile::fake()->image($name, $w, $h);
}

/* ───────────────────────── ordering (HomeLayout) ───────────────────────── */

it('moves a section one step at a time among all home sections and renumbers from 1', function () {
    $a = section('categories');
    $b = section('banners');
    $c = section('new_arrivals');
    HomepageSection::query()->update(['sort_order' => 0]); // old data: every row tied, ordered by creation
    $layout = app(HomeLayout::class);

    $moved = $layout->moveSection($b->id, 1);
    expect($moved['before'])->toBe([$a->id, $b->id, $c->id])->and($moved['after'])->toBe([$a->id, $c->id, $b->id])->and(order())->toBe([$a->id, $c->id, $b->id])
        ->and(HomepageSection::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3]);

    expect($layout->moveSection($b->id, 1)['after'])->toBe([$a->id, $c->id, $b->id]); // already last: unchanged
    $layout->moveSection($b->id, -1);
    $layout->moveSection($b->id, -1);
    expect(order())->toBe([$b->id, $a->id, $c->id])->and($layout->moveSection($b->id, -1)['after'])->toBe([$b->id, $a->id, $c->id]);
    expect(fn () => $layout->moveSection('00000000-0000-4000-8000-000000000000', 1))->toThrow(DomainException::class);
});

it('adds a section at the top, after another one or at the bottom', function () {
    $a = section('categories');
    $b = section('new_arrivals');
    $layout = app(HomeLayout::class);
    $base = ['type' => 'banners', 'layout' => 'carousel', 'status' => 'published'];

    $top = $layout->addSection($base, 'top');
    $mid = $layout->addSection($base, 'after:'.$a->id);
    $end = $layout->addSection($base, 'bottom');
    $unknown = $layout->addSection($base, 'after:'.'00000000-0000-4000-8000-000000000000'); // a stale id lands at the bottom, never errors
    expect(order())->toBe([$top->id, $a->id, $mid->id, $b->id, $end->id, $unknown->id])->and(HomepageSection::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3, 4, 5, 6]);
});

it('puts a dragged section at a position and restores an earlier order for Undo', function () {
    [$a, $b, $c, $d] = [section('categories'), section('banners'), section('new_arrivals'), section('brands')];
    $layout = app(HomeLayout::class);
    $layout->placeSection($d->id, 0);
    expect(order())->toBe([$d->id, $a->id, $b->id, $c->id]);
    $layout->placeSection($d->id, 99); // beyond the end: last
    expect(order())->toBe([$a->id, $b->id, $c->id, $d->id]);

    $moved = $layout->moveSection($c->id, -2);
    $e = section('promotions'); // added while the Undo was pending
    $layout->restoreSectionOrder($moved['before']);
    expect(order())->toBe([$a->id, $b->id, $c->id, $d->id, $e->id]);
});

it('orders slides inside their slider only and renumbers them', function () {
    $one = section('banners');
    $two = section('banners');
    [$a, $b, $c] = [slide($one), slide($one), slide($one)];
    $other = slide($two);
    Banner::query()->update(['sort_order' => 0]);
    $layout = app(HomeLayout::class);

    $layout->moveSlide($c->id, -1);
    expect(slidesOf($one))->toBe([$a->id, $c->id, $b->id])->and(slidesOf($two))->toBe([$other->id]);
    expect($layout->moveSlide($a->id, -1)['after'])->toBe([$a->id, $c->id, $b->id])->and(Banner::where('homepage_section_id', $one->id)->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3]);
    $layout->placeSlide($b->id, 0);
    expect(slidesOf($one))->toBe([$b->id, $a->id, $c->id]);
    $layout->restoreSlideOrder($one->id, 'home_hero', [$a->id, $b->id, $c->id]);
    expect(slidesOf($one))->toBe([$a->id, $b->id, $c->id]);
});

it('moves a slide to another slider at its end and refuses a slider that is full', function () {
    $one = section('banners');
    $two = section('banners');
    $s = slide($one);
    $t = slide($two);
    app(HomeLayout::class)->relocateSlide($s, $two->id, 'home_hero');
    expect(slidesOf($two))->toBe([$t->id, $s->id])->and(slidesOf($one))->toBe([]);

    foreach (range(1, 18) as $i) {
        slide($two);
    }
    expect(Banner::where('homepage_section_id', $two->id)->count())->toBe(20);
    expect(fn () => app(HomeLayout::class)->addSlide(['image_en_path' => 'x.jpg'], $two->id, 'home_hero'))->toThrow(DomainException::class, 'up to 20 slides');
    $stray = slide($one);
    expect(fn () => app(HomeLayout::class)->relocateSlide($stray, $two->id, 'home_hero'))->toThrow(DomainException::class);
});

/* ───────────────────────── the API: GET /api/v1/home ───────────────────────── */

it('serves a slider exactly as the contract says, in the admin order, with its settings and working links', function () {
    $cat = Category::factory()->create();
    $page = CmsPage::create(['slug' => 'about-us', 'status' => 'published']);
    $page->translations()->create(['locale' => 'en', 'title' => 'About us', 'body' => '<p>x</p>']);
    $product = Product::factory()->create();
    $brand = Brand::factory()->create();
    $auction = auctionIn('scheduled');

    $first = section('categories', ['sort_order' => 1]);
    $slider = section('banners', ['sort_order' => 2, 'title_en' => 'Eid offers', 'settings' => ['name' => 'secret internal name', 'slider' => ['interval' => 7, 'aspect' => '21:9', 'autoplay' => false]]]);
    $last = section('new_arrivals', ['sort_order' => 3]);
    $links = [
        ['no link', null, null],
        ['category', 'category', $cat->id], ['page', 'page', 'about-us'], ['product', 'product', $product->id], ['brand', 'brand', $brand->id], ['auction', 'auction', $auction->id],
        ['url', 'url', 'https://example.com/sale?x=1'], ['search', 'search', 'sofa sale'],
    ];
    foreach ($links as $i => [$title, $type, $value]) {
        slide($slider, ['title_en' => $title, 'title_ar' => 'ع '.$title, 'link_type' => $type, 'link_value' => $value, 'image_ar_path' => $i === 1 ? 'https://cdn.example/ar.jpg' : null, 'image_en_path' => 'https://cdn.example/en-'.$i.'.jpg']);
    }

    $res = $this->getJson('/api/v1/home')->assertOk();
    $sections = collect($res->json('data.sections'));
    expect($sections->pluck('id')->all())->toContain($slider->id);
    $s = $sections->firstWhere('id', $slider->id);
    expect($s)->toMatchArray(['type' => 'banners', 'layout' => 'carousel', 'title' => 'Eid offers', 'subtitle' => null])
        ->and($s['slider'])->toBe(['autoplay' => false, 'interval' => 7, 'aspect' => '21:9', 'dots' => true, 'show_titles' => true, 'rounded' => true])
        ->and(array_keys($s['items'][0]))->toBe(['id', 'title', 'subtitle', 'image_url', 'link'])
        ->and(json_encode($s))->not->toContain('secret internal name');
    $items = collect($s['items']);
    expect($items->pluck('title')->all())->toBe(array_column($links, 0))
        ->and($items[0]['link'])->toBeNull()
        ->and($items[1]['link'])->toBe(['type' => 'category', 'value' => $cat->id])->and($items[2]['link'])->toBe(['type' => 'page', 'value' => 'about-us'])
        ->and($items[3]['link'])->toBe(['type' => 'product', 'value' => $product->id])->and($items[4]['link'])->toBe(['type' => 'brand', 'value' => $brand->id])
        ->and($items[5]['link'])->toBe(['type' => 'auction', 'value' => $auction->id])->and($items[6]['link'])->toBe(['type' => 'url', 'value' => 'https://example.com/sale?x=1'])
        ->and($items[7]['link'])->toBe(['type' => 'search', 'value' => 'sofa sale'])
        ->and($items[1]['image_url'])->toBe('https://cdn.example/en-1.jpg');

    // Arabic: the Arabic picture and title, falling back to English.
    $ar = collect(collect($this->getJson('/api/v1/home', ['Accept-Language' => 'ar'])->json('data.sections'))->firstWhere('id', $slider->id)['items']);
    expect($ar[1]['image_url'])->toBe('https://cdn.example/ar.jpg')->and($ar[2]['image_url'])->toBe('https://cdn.example/en-2.jpg')->and($ar[0]['title'])->toBe('ع no link');
    expect($first->id)->not->toBe($last->id);
});

it('serves the home sections in the order the admin chose, a slider after the second section', function () {
    $product = Product::factory()->create(['is_featured' => true, 'is_new_arrival' => true]);
    $a = section('featured_products', ['title_en' => 'Featured']);
    $b = section('new_arrivals', ['title_en' => 'New']);
    $slider = section('banners');
    slide($slider);
    $layout = app(HomeLayout::class);
    $layout->moveSection($slider->id, -1);
    expect(collect($this->getJson('/api/v1/home')->json('data.sections'))->pluck('id')->all())->toBe([$a->id, $slider->id, $b->id]);
    $layout->moveSection($slider->id, -1);
    expect(collect($this->getJson('/api/v1/home')->json('data.sections'))->pluck('id')->all())->toBe([$slider->id, $a->id, $b->id]);
    expect($product->id)->not->toBeEmpty();
});

it('serves a link only while its target works, and never anything but a clean https address', function () {
    $slider = section('banners');
    $live = Category::factory()->create();
    $hidden = Category::factory()->create(['status' => 'inactive']);
    $gone = Category::factory()->create();
    $gone->delete();
    $draftPage = CmsPage::create(['slug' => 'draft-page', 'status' => 'draft']);
    $inactiveProduct = Product::factory()->create(['status' => 'draft']);
    $draftAuction = auctionIn('draft');
    $cases = [
        'ok' => ['category', $live->id], 'hidden category' => ['category', $hidden->id], 'deleted category' => ['category', $gone->id],
        'draft page' => ['page', $draftPage->slug], 'missing page' => ['page', 'nope'], 'inactive product' => ['product', $inactiveProduct->id], 'draft auction' => ['auction', $draftAuction->id],
        'not a uuid' => ['category', 'not-a-uuid'], 'js url' => ['url', 'javascript:alert(1)'], 'http url' => ['url', 'http://example.com'], 'data url' => ['url', 'data:text/html;base64,AAAA'],
        'protocol relative' => ['url', '//example.com'], 'user info' => ['url', 'https://user:pw@example.com'], 'space' => ['url', 'https://exa mple.com'], 'empty search' => ['search', '   '],
        'unknown type' => ['telegram', 'x'], 'type without value' => ['category', ''],
    ];
    foreach ($cases as $title => [$type, $value]) {
        slide($slider, ['title_en' => $title, 'link_type' => $type, 'link_value' => $value]);
    }
    $items = collect($this->getJson('/api/v1/home')->assertOk()->json('data.sections.0.items'))->keyBy('title');
    expect($items)->toHaveCount(count($cases))->and($items['ok']['link'])->toBe(['type' => 'category', 'value' => $live->id]);
    foreach (array_keys($cases) as $title) {
        if ($title !== 'ok') {
            expect($items[$title]['link'])->toBeNull($title)->and($items[$title]['image_url'])->not->toBeNull(); // the slide itself stays, just without a link
        }
    }
});

it('filters slides by platform and schedule, and leaves out sliders with nothing to show', function () {
    $slider = section('banners');
    $empty = section('banners');
    slide($empty, ['status' => 'draft']);
    slide($empty, ['starts_at' => now()->addDay()]);
    slide($empty, ['ends_at' => now()->subDay()]);
    foreach (['all' => 'a', 'mobile' => 'm', 'web' => 'w'] as $platform => $title) {
        slide($slider, ['platform' => $platform, 'title_en' => $title]);
    }
    slide($slider, ['title_en' => 'soon', 'starts_at' => now()->addHour()]);
    slide($slider, ['title_en' => 'over', 'ends_at' => now()->subHour()]);
    slide($slider, ['title_en' => 'hidden', 'status' => 'draft']);

    $titles = fn (string $q) => collect($this->getJson('/api/v1/home'.$q)->json('data.sections'))->flatMap(fn ($s) => collect($s['items'])->pluck('title'))->all();
    expect($titles(''))->toBe(['a', 'm'])->and($titles('?platform=mobile'))->toBe(['a', 'm'])->and($titles('?platform=web'))->toBe(['a', 'w']);
    expect(collect($this->getJson('/api/v1/home')->json('data.sections'))->pluck('id')->all())->toBe([$slider->id]); // the other slider has no live slide

    $this->travel(2)->hours();
    expect($titles('?platform=web'))->toBe(['a', 'w', 'soon']);
    $this->getJson('/api/v1/home?platform=tv')->assertStatus(422);
    $draft = section('banners', ['status' => 'draft']);
    slide($draft);
    expect(collect($this->getJson('/api/v1/home')->json('data.sections'))->pluck('id')->all())->not->toContain($draft->id);
});

it('always sends the slider settings, with defaults and in range, whatever is stored', function () {
    $slider = section('banners', ['settings' => ['slider' => ['interval' => 99, 'aspect' => '7:1', 'dots' => 'no', 'autoplay' => null]]]);
    slide($slider);
    expect($this->getJson('/api/v1/home')->json('data.sections.0.slider'))->toBe(['autoplay' => true, 'interval' => 15, 'aspect' => '16:9', 'dots' => false, 'show_titles' => true, 'rounded' => true]);
    $slider->update(['settings' => null]);
    expect($this->getJson('/api/v1/home')->json('data.sections.0.slider'))->toBe(['autoplay' => true, 'interval' => 5, 'aspect' => '16:9', 'dots' => true, 'show_titles' => true, 'rounded' => true]);
    $slider->update(['settings' => ['slider' => ['interval' => 1]]]);
    expect($this->getJson('/api/v1/home')->json('data.sections.0.slider.interval'))->toBe(3);
});

it('is live at once after every dashboard change: the cached feed is dropped, and the alias answers too', function () {
    $slider = section('banners');
    $a = slide($slider, ['title_en' => 'one']);
    $titles = fn (string $path = '/api/v1/home') => collect($this->getJson($path)->json('data.sections.0.items'))->pluck('title')->all();
    expect($titles())->toBe(['one'])->and($titles('/api/v1/homepage'))->toBe(['one']);

    $b = slide($slider, ['title_en' => 'two']);
    expect($titles())->toBe(['one', 'two']);
    $b->update(['title_en' => 'TWO']);
    expect($titles())->toBe(['one', 'TWO']);
    app(HomeLayout::class)->moveSlide($b->id, -1);
    expect($titles())->toBe(['TWO', 'one']);
    $a->update(['status' => 'draft']);
    expect($titles())->toBe(['TWO']);
    $b->delete();
    expect($this->getJson('/api/v1/home')->json('data.sections'))->toBe([]);
    $b->restore();
    expect($titles())->toBe(['TWO']);
    $slider->update(['status' => 'draft']);
    expect($this->getJson('/api/v1/home')->json('data.sections'))->toBe([]);
    $slider->update(['status' => 'published']);
    $slider->delete();
    expect($this->getJson('/api/v1/home')->json('data.sections'))->toBe([]);
    $slider->restore();
    expect($titles())->toBe(['TWO']);
    HomepageService::flush(); // the public hook other code can call
    expect($titles())->toBe(['TWO']);
});

it('answers the home feed with a fixed number of queries however many sliders and slides there are', function () {
    $cat = Category::factory()->create();
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(HomepageService::class)->render(null, 'web');
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $one = section('banners');
    foreach (['category' => $cat->id, 'url' => 'https://x.test/a', 'search' => 'q', 'page' => 'p'] as $type => $value) {
        slide($one, ['link_type' => $type, 'link_value' => $value]);
    }
    $small = $count();
    foreach (range(1, 4) as $i) {
        $s = section('banners');
        foreach (range(1, 6) as $j) {
            slide($s, ['link_type' => ['category', 'url', 'search', 'page'][$j % 4], 'link_value' => ['category' => $cat->id, 'url' => 'https://x.test/'.$j, 'search' => 'q'.$j, 'page' => 'p'.$j][['category', 'url', 'search', 'page'][$j % 4]]]);
        }
    }
    expect($count())->toBe($small);
});

/* ───────────────────────── links ───────────────────────── */

it('accepts only a complete public https address, a real slug, a uuid or search words', function (string $type, string $value, bool $ok) {
    expect(BannerLinks::normalize($type, $value) !== null)->toBe($ok);
})->with([
    ['url', 'https://example.com', true], ['url', 'https://example.com/a?b=c#d', true], ['url', 'HTTPS://Example.com', true],
    ['url', 'http://example.com', false], ['url', 'javascript:alert(1)', false], ['url', 'JaVaScRiPt:alert(1)', false], ['url', 'data:text/html,hi', false], ['url', 'file:///etc/passwd', false],
    ['url', 'ftp://example.com', false], ['url', '//example.com', false], ['url', 'https://', false], ['url', 'https:///x', false], ['url', 'https://u:p@example.com', false],
    ['url', "https://example.com/\nx", false], ['url', 'https://exa mple.com', false], ['url', '', false],
    ['page', 'about-us', true], ['page', 'About-Us', true], ['page', '../etc', false], ['page', 'a b', false], ['page', '', false],
    ['category', '0194d2f4-8f3e-7000-8000-000000000001', true], ['category', 'sofas', false], ['product', "1' or '1'='1", false], ['brand', 'x', false], ['auction', '123', false],
    ['search', 'sofa sale', true], ['search', '   ', false], ['telegram', 'x', false], ['', 'x', false],
]);

it('limits a search link to 120 characters, one space between words', function () {
    $link = BannerLinks::normalize('search', "  two\n\nwords   ".str_repeat('ا', 300));
    expect(mb_strlen($link['value']))->toBe(120)->and($link['value'])->toStartWith('two words ');
});

/* ───────────────────────── the page ───────────────────────── */

it('shows the home page map with every section, the sliders opened up and the other places', function () {
    $cats = section('categories', ['title_en' => 'Shop by room']);
    $slider = section('banners', ['settings' => ['name' => 'Eid campaign', 'slider' => ['aspect' => '3:1']]]);
    slide($slider, ['title_en' => 'Big sale', 'link_type' => 'search', 'link_value' => 'sale']);
    slide($slider, ['title_en' => 'Broken one', 'link_type' => 'page', 'link_value' => 'deleted-page', 'status' => 'draft']);
    Banner::create(['placement' => 'category_top', 'image_en_path' => 'https://cdn.example/c.jpg', 'title_en' => 'Category banner', 'status' => 'published']);
    Banner::create(['placement' => 'home_hero', 'image_en_path' => 'https://cdn.example/o.jpg', 'title_en' => 'Orphan', 'status' => 'published']);

    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->assertOk()
        ->assertSee('Eid campaign')->assertSee('Shop by room')->assertSee('Big sale')->assertSee('Broken one')->assertSee('Broken link')->assertSee('Category banner')->assertSee('Orphan')
        ->assertSee('Old banners not in any slider')->assertSee('Last on the home page')->assertSee('Add a slide')->assertSee('Add banner');
    $this->get(route('admin.banners.index'))->assertOk()->assertSee('Home banners');
    app()->setLocale('ar');
    Livewire::test(BannerIndex::class)->assertOk()->assertSee('عارض الشرائح', false)->assertSee('Big sale');
    expect($cats->id)->not->toBe($slider->id);
});

it('builds the page with a fixed number of queries however many sliders and slides exist', function () {
    $this->actingAs($this->marketing);
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(BannerIndex::class)->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };
    $cat = Category::factory()->create();
    $one = section('banners');
    foreach (['category' => $cat->id, 'url' => 'https://x.test/a', 'search' => 'q', 'page' => 'p'] as $type => $value) {
        slide($one, ['link_type' => $type, 'link_value' => $value]);
    }
    $count(); // the first request also warms the permission cache
    $small = $count();
    foreach (range(1, 4) as $i) {
        $s = section('banners');
        section('brands');
        foreach (range(1, 6) as $j) {
            slide($s, ['link_type' => ['category', 'url', 'search', 'page'][$j % 4], 'link_value' => ['category' => $cat->id, 'url' => 'https://x.test/'.$j, 'search' => 'q'.$j, 'page' => 'p'.$j][['category', 'url', 'search', 'page'][$j % 4]]]);
        }
    }
    expect($count())->toBe($small);
});

it('adds a slider where the admin wants it, with its look, and records it', function () {
    $a = section('categories');
    $b = section('new_arrivals');
    $this->actingAs($this->marketing);
    $c = Livewire::test(BannerIndex::class)->call('createSlider')->assertSet('slider.at', 'top')->assertSet('slider.aspect', '16:9')->assertSet('slider.interval', 5)
        ->set('slider.name', 'Eid offers')->set('slider.aspect', '21:9')->set('slider.interval', 8)->set('slider.autoplay', false)->set('slider.dots', false)->set('slider.show_titles', false)->set('slider.rounded', false)
        ->set('slider.title_en', 'Eid')->set('slider.title_ar', 'العيد')->set('slider.at', 'after:'.$a->id)->call('saveSlider')->assertHasNoErrors();
    $s = HomepageSection::where('type', 'banners')->firstOrFail();
    expect(order())->toBe([$a->id, $s->id, $b->id])->and($s->slider())->toBe(['autoplay' => false, 'interval' => 8, 'aspect' => '21:9', 'dots' => false, 'show_titles' => false, 'rounded' => false])
        ->and($s->adminName())->toBe('Eid offers')->and($s->layout)->toBe('carousel')->and($s->status)->toBe('published')->and($s->title_ar)->toBe('العيد');
    expect(AuditLog::where('action', 'cms.slider_created')->count())->toBe(1);

    $c->call('createSlider')->assertSet('slider.at', 'bottom')->set('slider.at', 'top')->call('saveSlider');
    expect(order()[0])->not->toBe($s->id)->and(HomepageSection::where('type', 'banners')->count())->toBe(2);
});

it('refuses a slider setting that is not allowed, and a position that does not exist', function () {
    $this->actingAs($this->marketing);
    $c = Livewire::test(BannerIndex::class)->call('createSlider')
        ->set('slider.aspect', '7:1')->set('slider.interval', 2)->set('slider.at', 'after:'.'00000000-0000-4000-8000-000000000000')->set('slider.status', 'live')->set('slider.ends_at', '2020-01-01T10:00')->set('slider.starts_at', '2020-01-02T10:00')
        ->call('saveSlider')->assertHasErrors(['slider.aspect', 'slider.interval', 'slider.at', 'slider.status', 'slider.ends_at']);
    $c->set('slider.aspect', '4:3')->set('slider.interval', 16)->call('saveSlider')->assertHasErrors(['slider.interval']);
    $c->set('slider.interval', 15)->set('slider.at', 'bottom')->set('slider.status', 'published')->set('slider.starts_at', '')->set('slider.ends_at', '')->call('saveSlider')->assertHasNoErrors();
    expect(HomepageSection::where('type', 'banners')->count())->toBe(1);
});

it('edits a slider without touching its slides or its place, and hides and shows it', function () {
    $a = section('categories');
    $s = section('banners', ['settings' => ['name' => 'Old', 'slider' => [], 'legacy_placement' => 'home_hero']]);
    $slide = slide($s);
    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->call('editSlider', $s->id)->assertSet('slider.name', 'Old')->set('slider.name', 'New name')->set('slider.aspect', '1:1')->call('saveSlider')->assertHasNoErrors()
        ->call('toggleSlider', $s->id);
    $s->refresh();
    expect($s->adminName())->toBe('New name')->and($s->slider()['aspect'])->toBe('1:1')->and($s->settings['legacy_placement'])->toBe('home_hero')->and($s->status)->toBe('draft')
        ->and(order())->toBe([$a->id, $s->id])->and(slidesOf($s))->toBe([$slide->id]);
    expect(AuditLog::where('action', 'cms.slider_updated')->exists())->toBeTrue()->and(AuditLog::where('action', 'cms.slider_hidden')->exists())->toBeTrue();
    // a section that is not a slider can never be opened here
    Livewire::test(BannerIndex::class)->call('editSlider', $a->id)->assertDispatched('toast', fn ($n, $p) => ($p['type'] ?? null) === 'error')->assertSet('showSlider', false);
});

it('moves a slider up and down among the other sections and undoes the move', function () {
    [$a, $b] = [section('categories'), section('new_arrivals')];
    $s = section('banners');
    $this->actingAs($this->marketing);
    $c = Livewire::test(BannerIndex::class);
    $c->call('moveSlider', $s->id, -1)->assertDispatched('toast', fn ($name, $params) => ($params['undoEvent'] ?? null) === 'slider-order-undo' && $params['undoParams']['order'] === [$a->id, $b->id, $s->id]);
    expect(order())->toBe([$a->id, $s->id, $b->id]);
    $c->call('moveSlider', $s->id, -1)->call('moveSlider', $s->id, -1); // second one is a no-op at the top and says nothing
    expect(order())->toBe([$s->id, $a->id, $b->id]);
    $c->dispatch('slider-order-undo', order: [$a->id, $b->id, $s->id]);
    expect(order())->toBe([$a->id, $b->id, $s->id]);
    $c->call('moveSlider', $s->id, 1); // already last
    expect(order())->toBe([$a->id, $b->id, $s->id])->and(AuditLog::where('action', 'cms.slider_moved')->count())->toBeGreaterThanOrEqual(3);
});

it('deletes a slider only after a confirmation, can bring it back, and purges old ones with their files', function () {
    $s = section('banners', ['settings' => ['name' => 'Gone soon', 'slider' => []]]);
    $slide = slide($s, ['image_en_path' => 'banners/en.jpg', 'image_ar_path' => 'banners/ar.jpg']);
    Storage::disk(config('platform.media.disk'))->put('banners/en.jpg', 'x');
    Storage::disk(config('platform.media.disk'))->put('banners/ar.jpg', 'x');
    $this->actingAs($this->marketing);
    $c = Livewire::test(BannerIndex::class)->call('askDeleteSlider', $s->id)->assertSet('showDeleteSlider', true);
    expect(HomepageSection::count())->toBe(1);
    $c->call('deleteSlider')->assertSet('showDeleteSlider', false)->assertDispatched('toast', fn ($n, $p) => ($p['undoEvent'] ?? null) === 'slider-restore');
    expect(HomepageSection::count())->toBe(0)->and(HomepageSection::onlyTrashed()->count())->toBe(1)->and($this->getJson('/api/v1/home')->json('data.sections'))->toBe([]);
    $c->dispatch('slider-restore', id: $s->id);
    expect(HomepageSection::count())->toBe(1)->and(slidesOf($s))->toBe([$slide->id]);

    $c->call('askDeleteSlider', $s->id)->call('deleteSlider');
    expect(app(HomeContentPurger::class)->purge())->toBe(['sliders' => 0, 'slides' => 0]); // too fresh
    $this->travel(8)->days();
    expect(app(HomeContentPurger::class)->purge())->toBe(['sliders' => 1, 'slides' => 1])->and(HomepageSection::withTrashed()->count())->toBe(0)->and(Banner::withTrashed()->count())->toBe(0);
    Storage::disk(config('platform.media.disk'))->assertMissing('banners/en.jpg');
    Storage::disk(config('platform.media.disk'))->assertMissing('banners/ar.jpg');
});

it('adds a slide with both pictures, a link and a schedule, and shows it live', function () {
    $s = section('banners');
    $cat = Category::factory()->create();
    $this->actingAs($this->marketing);
    $c = Livewire::test(BannerIndex::class)->call('createSlide', 'slider:'.$s->id)->assertSet('slide.status', 'published')->assertSet('slide.platform', 'all')
        ->call('saveSlide')->assertHasErrors(['imageEn'])
        ->set('imageEn', photo('en.jpg'))->set('imageAr', photo('ar.png'))->set('slide.title_en', 'Big sale')->set('slide.title_ar', 'تخفيضات')->set('slide.subtitle_en', 'Up to 50%')
        ->set('slide.link_type', 'category')->call('pickLink', $cat->id)->set('slide.starts_at', now()->subDay()->format('Y-m-d\TH:i'))->call('saveSlide')->assertHasNoErrors();
    $b = Banner::where('title_en', 'Big sale')->firstOrFail();
    expect($b->homepage_section_id)->toBe($s->id)->and($b->placement)->toBe('home_hero')->and($b->link_type)->toBe('category')->and($b->link_value)->toBe($cat->id)->and($b->status)->toBe('published')
        ->and($b->sort_order)->toBe(1)->and($b->image_ar_path)->not->toBeNull()->and($b->isLive())->toBeTrue();
    Storage::disk(config('platform.media.disk'))->assertExists($b->image_en_path);
    Storage::disk(config('platform.media.disk'))->assertExists($b->image_ar_path);
    $feed = $this->getJson('/api/v1/home')->json('data.sections.0.items.0');
    expect($feed['title'])->toBe('Big sale')->and($feed['link'])->toBe(['type' => 'category', 'value' => $cat->id])->and($feed['image_url'])->toContain('banners/');
    expect(AuditLog::where('action', 'cms.banner_created')->count())->toBe(1);

    $c->call('createSlide', 'slider:'.$s->id)->set('imageEn', photo('two.jpg'))->call('saveSlide')->assertHasNoErrors();
    expect(slidesOf($s))->toHaveCount(2)->and(Banner::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2]); // appended at the end
});

it('adds a slide with no link at all: the slide is not tappable', function () {
    $s = section('banners');
    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->call('createSlide', 'slider:'.$s->id)->set('imageEn', photo())->assertSet('slide.link_type', '')->call('saveSlide')->assertHasNoErrors();
    $b = Banner::firstOrFail();
    expect($b->link_type)->toBeNull()->and($b->link_value)->toBeNull()->and($this->getJson('/api/v1/home')->json('data.sections.0.items.0.link'))->toBeNull();
});

it('refuses links the dashboard must never save: other schemes, other kinds of value, targets that do not exist', function (string $type, string $value, string $field) {
    $s = section('banners');
    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->call('createSlide', 'slider:'.$s->id)->set('imageEn', photo())
        ->set('slide.link_type', $type)->set('slide.link_value', $value)->call('saveSlide')->assertHasErrors([$field]);
    expect(Banner::count())->toBe(0);
})->with([
    'javascript' => ['url', 'javascript:alert(document.cookie)', 'slide.link_value'], 'http' => ['url', 'http://example.com/offers', 'slide.link_value'], 'data' => ['url', 'data:text/html,<script>1</script>', 'slide.link_value'],
    'relative' => ['url', '/shop', 'slide.link_value'], 'category slug' => ['category', 'sofas', 'slide.link_value'], 'missing category' => ['category', '0194d2f4-8f3e-7000-8000-0000000000aa', 'slide.link_value'],
    'missing page' => ['page', 'not-a-page', 'slide.link_value'], 'sql' => ['product', "' or 1=1 --", 'slide.link_value'], 'empty value' => ['search', '', 'slide.link_value'],
    'unknown type' => ['telegram', 'x', 'slide.link_type'],
]);

it('keeps a slide whose link broke editable: only a changed link has to point somewhere real', function () {
    $s = section('banners');
    $b = slide($s, ['link_type' => 'page', 'link_value' => 'deleted-page', 'title_en' => 'Old']);
    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->call('editSlide', $b->id)->assertSee('This link no longer works')->set('slide.title_en', 'Renamed')->call('saveSlide')->assertHasNoErrors();
    expect($b->fresh()->title_en)->toBe('Renamed')->and($b->fresh()->link_value)->toBe('deleted-page');
    Livewire::test(BannerIndex::class)->call('editSlide', $b->id)->call('clearLink')->call('saveSlide')->assertHasNoErrors();
    expect($b->fresh()->link_type)->toBeNull();
});

it('checks the pictures on the server too: type, real content, size and shape', function () {
    $s = section('banners');
    $this->actingAs($this->marketing);
    $go = fn ($file) => Livewire::test(BannerIndex::class)->call('createSlide', 'slider:'.$s->id)->set('imageEn', $file)->call('saveSlide');
    $go(UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'))->assertHasErrors(['imageEn']);
    $go(UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'))->assertHasErrors(['imageEn']);
    $go(UploadedFile::fake()->createWithContent('lie.jpg', '<?php echo 1;'))->assertHasErrors(['imageEn']); // a script renamed to .jpg
    $go(UploadedFile::fake()->image('tiny.jpg', 200, 80))->assertHasErrors(['imageEn']); // below the minimum size
    $go(UploadedFile::fake()->image('huge.jpg', 1600, 900)->size(config('platform.media.max_image_kb') + 1))->assertHasErrors(['imageEn']);
    $go(photo('ok.webp'))->assertHasNoErrors();
    expect(Banner::count())->toBe(1);
});

it('escapes titles and never lets a title become markup', function () {
    $s = section('banners');
    slide($s, ['title_en' => '<script>alert("x")</script><img src=x onerror=alert(1)>', 'subtitle_en' => '"><svg onload=alert(1)>']);
    $this->actingAs($this->marketing);
    $html = Livewire::test(BannerIndex::class)->html();
    expect($html)->not->toContain('<script>alert("x")')->not->toContain('<img src=x onerror')->not->toContain('<svg onload')->and($html)->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;');
    expect(Livewire::test(BannerIndex::class)->call('editSlide', Banner::firstOrFail()->id)->html())->not->toContain('<img src=x onerror');
});

it('edits a slide, replaces its picture and removes the Arabic one', function () {
    $s = section('banners');
    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->call('createSlide', 'slider:'.$s->id)->set('imageEn', photo('en.jpg'))->set('imageAr', photo('ar.jpg'))->call('saveSlide');
    $b = Banner::firstOrFail();
    [$en, $ar] = [$b->image_en_path, $b->image_ar_path];
    $c = Livewire::test(BannerIndex::class)->call('editSlide', $b->id)->assertSet('slide.destination', 'slider:'.$s->id)->set('imageEn', photo('new.jpg'))->set('slide.platform', 'web')->call('saveSlide')->assertHasNoErrors();
    $b->refresh();
    expect($b->image_en_path)->not->toBe($en)->and($b->image_ar_path)->toBe($ar)->and($b->platform)->toBe('web');
    Storage::disk(config('platform.media.disk'))->assertMissing($en);
    Storage::disk(config('platform.media.disk'))->assertExists($b->image_en_path);

    $c->call('editSlide', $b->id)->set('dropImageAr', true)->call('saveSlide')->assertHasNoErrors();
    expect($b->fresh()->image_ar_path)->toBeNull();
    Storage::disk(config('platform.media.disk'))->assertMissing($ar);
});

it('moves a slide to another slider from its window, and into the other places', function () {
    [$one, $two] = [section('banners'), section('banners')];
    $a = slide($one);
    $b = slide($one);
    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->call('editSlide', $a->id)->set('slide.destination', 'slider:'.$two->id)->call('saveSlide')->assertHasNoErrors();
    expect(slidesOf($two))->toBe([$a->id])->and(slidesOf($one))->toBe([$b->id]);
    Livewire::test(BannerIndex::class)->call('editSlide', $a->id)->set('slide.destination', 'place:category_top')->call('saveSlide')->assertHasNoErrors();
    expect($a->fresh()->homepage_section_id)->toBeNull()->and($a->fresh()->placement)->toBe('category_top');
    Livewire::test(BannerIndex::class)->call('editSlide', $a->id)->set('slide.destination', 'place:nowhere')->call('saveSlide')->assertHasErrors(['slide.destination']);
    Livewire::test(BannerIndex::class)->call('editSlide', $a->id)->set('slide.destination', 'slider:'.section('categories')->id)->call('saveSlide')->assertHasErrors(['slide.destination']); // not a slider
});

it('orders slides with the arrows and by dragging, and can undo a move', function () {
    $s = section('banners');
    [$a, $b, $c] = [slide($s), slide($s), slide($s)];
    $this->actingAs($this->marketing);
    $page = Livewire::test(BannerIndex::class);
    $page->call('moveSlide', $c->id, -1)->assertDispatched('toast', fn ($n, $p) => ($p['undoEvent'] ?? null) === 'slide-order-undo');
    expect(slidesOf($s))->toBe([$a->id, $c->id, $b->id]);
    $page->dispatch('slide-order-undo', order: [$a->id, $b->id, $c->id], sectionId: $s->id, placement: 'home_hero');
    expect(slidesOf($s))->toBe([$a->id, $b->id, $c->id]);
    $page->call('reorderSlide', $a->id, 2);
    expect(slidesOf($s))->toBe([$b->id, $c->id, $a->id]);
    $page->call('moveSlide', $a->id, 1); // already last
    expect(slidesOf($s))->toBe([$b->id, $c->id, $a->id]);
    $page->call('moveSlide', '00000000-0000-4000-8000-000000000000', 1)->assertNotDispatched('toast-never');
});

it('hides, shows, duplicates and deletes slides, and restores a deleted one in place', function () {
    $s = section('banners');
    $disk = Storage::disk(config('platform.media.disk'));
    $disk->put('banners/a-en.jpg', 'en');
    $disk->put('banners/a-ar.jpg', 'ar');
    $a = slide($s, ['image_en_path' => 'banners/a-en.jpg', 'image_ar_path' => 'banners/a-ar.jpg', 'title_en' => 'A', 'link_type' => 'search', 'link_value' => 'sofa']);
    $b = slide($s, ['title_en' => 'B']);
    $this->actingAs($this->marketing);
    $page = Livewire::test(BannerIndex::class);

    $page->call('toggleSlide', $a->id);
    expect($a->fresh()->status)->toBe('draft');
    $page->call('toggleSlide', $a->id);
    expect($a->fresh()->status)->toBe('published');

    $page->call('duplicateSlide', $a->id);
    $copy = Banner::where('id', '!=', $a->id)->where('title_en', 'A')->firstOrFail();
    expect(slidesOf($s))->toBe([$a->id, $copy->id, $b->id])->and($copy->status)->toBe('draft')->and($copy->link_value)->toBe('sofa')
        ->and($copy->image_en_path)->not->toBe($a->image_en_path)->and($copy->image_ar_path)->not->toBe($a->image_ar_path);
    $disk->assertExists($copy->image_en_path);
    $disk->assertExists($copy->image_ar_path);

    $page->call('deleteSlide', $a->id)->assertDispatched('toast', fn ($n, $p) => ($p['undoEvent'] ?? null) === 'slide-restore');
    expect(Banner::find($a->id))->toBeNull()->and(Banner::onlyTrashed()->count())->toBe(1);
    $disk->assertExists('banners/a-en.jpg'); // files stay until the purge, so Undo works
    $page->dispatch('slide-restore', id: $a->id);
    expect(slidesOf($s))->toBe([$a->id, $copy->id, $b->id])->and(Banner::find($a->id)->title_en)->toBe('A');

    $page->call('deleteSlide', $a->id);
    $this->travel(8)->days();
    app(HomeContentPurger::class)->purge();
    $disk->assertMissing('banners/a-en.jpg');
    $disk->assertExists($copy->image_en_path); // the copy's own file is untouched
    expect(AuditLog::whereIn('action', ['cms.banner_duplicated', 'cms.banner_deleted', 'cms.banner_restored', 'cms.banner_published', 'cms.banner_hidden'])->count())->toBe(6);
});

it('says so instead of failing when a slide or slider is already gone (another admin, a double click)', function () {
    $s = section('banners');
    $a = slide($s);
    $ghost = '00000000-0000-4000-8000-000000000000';
    $this->actingAs($this->marketing);
    $isError = fn ($n, $p) => ($p['type'] ?? null) === 'error';
    $page = Livewire::test(BannerIndex::class);
    foreach ([['toggleSlide', [$ghost]], ['deleteSlide', [$ghost]], ['duplicateSlide', [$ghost]], ['editSlide', [$ghost]], ['moveSlide', [$ghost, 1]], ['reorderSlide', [$ghost, 0]],
        ['toggleSlider', [$ghost]], ['editSlider', [$ghost]], ['askDeleteSlider', [$ghost]], ['moveSlider', [$ghost, 1]]] as [$method, $args]) {
        $page->call($method, ...$args)->assertDispatched('toast', $isError);
    }
    // a double click on Delete: the second click finds nothing and only says so
    $page->call('deleteSlide', $a->id)->call('deleteSlide', $a->id)->assertDispatched('toast', $isError);
    expect(Banner::onlyTrashed()->count())->toBe(1);
    // a window left open on a slide that was deleted meanwhile closes instead of failing
    $b = slide($s);
    $page->call('editSlide', $b->id)->set('imageEn', photo());
    $b->delete();
    $page->call('saveSlide')->assertSet('showSlide', false)->assertDispatched('toast', $isError);
});

it('keeps a slider to 20 slides and says so', function () {
    $s = section('banners');
    foreach (range(1, 20) as $i) {
        slide($s);
    }
    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->assertSee('up to 20 slides')->call('createSlide', 'slider:'.$s->id)->set('imageEn', photo())->call('saveSlide')
        ->assertDispatched('toast', fn ($n, $p) => ($p['type'] ?? null) === 'error');
    expect(Banner::count())->toBe(20);
    Livewire::test(BannerIndex::class)->call('duplicateSlide', Banner::first()->id)->assertDispatched('toast', fn ($n, $p) => ($p['type'] ?? null) === 'error');
    expect(Banner::count())->toBe(20);
});

it('does not half-save a slide that cannot move into a full slider, and keeps no stray picture', function () {
    [$full, $other] = [section('banners'), section('banners')];
    foreach (range(1, 20) as $i) {
        slide($full);
    }
    $b = slide($other, ['image_en_path' => 'banners/keep.jpg', 'title_en' => 'Keep']);
    Storage::disk(config('platform.media.disk'))->put('banners/keep.jpg', 'x');
    $this->actingAs($this->marketing);
    Livewire::test(BannerIndex::class)->call('editSlide', $b->id)->set('slide.destination', 'slider:'.$full->id)->set('slide.title_en', 'Changed')->set('imageEn', photo())->call('saveSlide')
        ->assertDispatched('toast', fn ($n, $p) => ($p['type'] ?? null) === 'error');
    expect($b->fresh()->homepage_section_id)->toBe($other->id)->and($b->fresh()->title_en)->toBe('Keep')->and($b->fresh()->image_en_path)->toBe('banners/keep.jpg')
        ->and(Storage::disk(config('platform.media.disk'))->allFiles('banners'))->toBe(['banners/keep.jpg']);
});

it('lets staff manage banners of the other places the same way', function () {
    $this->actingAs($this->marketing);
    $c = Livewire::test(BannerIndex::class)->call('createSlide', 'place:auctions')->set('imageEn', photo('a.jpg', 1600, 700))->set('slide.title_en', 'Auction night')->call('saveSlide')->assertHasNoErrors()
        ->call('createSlide', 'place:auctions')->set('imageEn', photo('b.jpg', 1600, 700))->set('slide.title_en', 'Second')->call('saveSlide')->assertHasNoErrors();
    [$first, $second] = [Banner::where('title_en', 'Auction night')->first(), Banner::where('title_en', 'Second')->first()];
    expect($first->homepage_section_id)->toBeNull()->and($first->placement)->toBe('auctions')->and([$first->sort_order, $second->sort_order])->toBe([1, 2]);
    $c->call('moveSlide', $second->id, -1);
    expect(Banner::where('placement', 'auctions')->orderBy('sort_order')->pluck('title_en')->all())->toBe(['Second', 'Auction night']);
    expect($this->getJson('/api/v1/home')->json('data.sections'))->toBe([]); // never in the home feed
});

it('moves an old banner that no slider shows into a slider', function () {
    $s = section('banners');
    $old = Banner::create(['placement' => 'home_strip', 'image_en_path' => 'https://cdn.example/old.jpg', 'status' => 'published', 'title_en' => 'Old strip']);
    $this->actingAs($this->marketing);
    $c = Livewire::test(BannerIndex::class)->call('editSlide', $old->id)->assertSet('slide.destination', '')->call('saveSlide')->assertHasErrors(['slide.destination']);
    $c->set('slide.destination', 'slider:'.$s->id)->call('saveSlide')->assertHasNoErrors();
    expect($old->fresh()->homepage_section_id)->toBe($s->id)->and($old->fresh()->placement)->toBe('home_hero');
});

it('plays a preview of exactly what customers get now', function () {
    $s = section('banners', ['settings' => ['name' => 'Preview me', 'slider' => ['interval' => 9]]]);
    slide($s, ['title_en' => 'Visible one']);
    slide($s, ['title_en' => 'Hidden one', 'status' => 'draft']);
    slide($s, ['title_en' => 'Future one', 'starts_at' => now()->addWeek()]);
    $this->actingAs($this->marketing);
    $c = Livewire::test(BannerIndex::class)->call('openPreview', $s->id)->assertSet('showPreview', true)->assertSee('Visible one')->assertSee('2 more slides are hidden or scheduled')->assertSee('9 s');
    expect(collect($c->viewData('preview')['slides'])->pluck('title_en')->all())->toBe(['Visible one']);
});

it('shows read-only staff the map but none of the controls, and refuses every change they try', function () {
    $s = section('banners');
    $a = slide($s, ['title_en' => 'Look only']);
    $b = slide($s, ['title_en' => 'Second']);
    $this->actingAs(staffWithRole('auditor'));
    $page = Livewire::test(BannerIndex::class)->assertOk()->assertSee('Look only')->assertSee('Preview slider');
    foreach (['createSlider', 'createSlide(', 'moveSlider(', 'moveSlide(', 'toggleSlider(', 'toggleSlide(', 'editSlider(', 'editSlide(', 'duplicateSlide(', 'deleteSlide(', 'askDeleteSlider(', 'saveSlide', 'saveSlider', 'deleteSlider'] as $action) {
        $page->assertDontSeeHtml('wire:click="'.$action)->assertDontSeeHtml('wire:submit="'.$action);
    }
    $page->assertDontSeeHtml('wire:sort="');
    foreach ([['createSlider', []], ['createSlide', ['slider:'.$s->id]], ['editSlide', [$a->id]], ['editSlider', [$s->id]], ['saveSlider', []], ['saveSlide', []], ['moveSlider', [$s->id, -1]],
        ['moveSlide', [$b->id, -1]], ['reorderSlide', [$b->id, 0]], ['toggleSlide', [$a->id]], ['toggleSlider', [$s->id]], ['duplicateSlide', [$a->id]], ['deleteSlide', [$a->id]],
        ['askDeleteSlider', [$s->id]], ['deleteSlider', []], ['clearLink', []], ['pickLink', ['x']]] as [$method, $args]) {
        Livewire::test(BannerIndex::class)->call($method, ...$args)->assertForbidden();
    }
    expect(Banner::count())->toBe(2)->and(slidesOf($s))->toBe([$a->id, $b->id])->and($a->fresh()->status)->toBe('published')->and(HomepageSection::count())->toBe(1);
    // a user who may not even view the content never opens the page
    $this->actingAs(staffWithRole('delivery_employee', Branch::factory()->create()))->get(route('admin.banners.index'))->assertForbidden();
});

it('lets a creator add but not delete when the role has no delete permission', function () {
    $s = section('banners');
    $a = slide($s);
    // a staff member with view + edit only (no create, no delete)
    $editor = User::factory()->staff()->create();
    $role = Role::create(['key' => 'banner_editor', 'name_en' => 'Banner editor', 'name_ar' => 'محرر', 'scope' => 'global', 'is_system' => false]);
    foreach (['cms.view', 'cms.edit'] as $key) {
        $role->permissions()->attach(Permission::where('key', $key)->first());
    }
    UserRole::create(['user_id' => $editor->id, 'role_id' => $role->id]);
    $this->actingAs($editor);
    Livewire::test(BannerIndex::class)->call('toggleSlide', $a->id)->assertOk();
    Livewire::test(BannerIndex::class)->call('deleteSlide', $a->id)->assertForbidden();
    Livewire::test(BannerIndex::class)->call('createSlide', 'slider:'.$s->id)->assertForbidden();
    expect($a->fresh()->status)->toBe('draft')->and(Banner::count())->toBe(1);
});

/* ───────────────────────── moving legacy data ───────────────────────── */

it('moves existing banners into sliders so nothing disappears, and back again', function () {
    $migration = require base_path('database/migrations/2026_11_16_100000_add_slider_slides_to_banners.php');
    $migration->down(); // the old schema
    $row = fn (array $x) => $x + ['created_at' => now(), 'updated_at' => now()];
    $uuid = fn () => (string) Str::uuid();
    [$heroSection, $stripSection, $other] = [$uuid(), $uuid(), $uuid()];
    DB::table('homepage_sections')->insert([
        $row(['id' => $heroSection, 'type' => 'banners', 'layout' => 'hero', 'status' => 'published', 'sort_order' => 1, 'item_limit' => 12, 'settings' => json_encode(['placement' => 'home_hero'])]),
        $row(['id' => $stripSection, 'type' => 'banners', 'layout' => 'carousel', 'status' => 'published', 'sort_order' => 2, 'item_limit' => 12, 'settings' => json_encode(['placement' => 'home_strip'])]),
        $row(['id' => $other, 'type' => 'categories', 'layout' => 'carousel', 'status' => 'published', 'sort_order' => 3, 'item_limit' => 12, 'settings' => null]),
    ]);
    [$h1, $h2, $h3, $s1, $s2, $never, $cat] = array_map($uuid, range(1, 7));
    $banner = fn (string $id, string $placement, int $sort) => $row(['id' => $id, 'placement' => $placement, 'platform' => 'all', 'image_en_path' => "banners/{$id}.jpg", 'status' => 'published', 'sort_order' => $sort]);
    DB::table('banners')->insert([$banner($h1, 'home_hero', 5), $banner($h2, 'home_hero', 1), $banner($h3, 'home_hero', 3), $banner($s1, 'home_strip', 1), $banner($s2, 'home_strip', 2),
        $banner($never, 'home_strip', 9), $banner($cat, 'category_top', 1)]);
    // the strip section had a hand-picked list: s2 then s1 (the third strip banner was never picked)
    DB::table('homepage_section_items')->insert([
        $row(['id' => $uuid(), 'homepage_section_id' => $stripSection, 'item_type' => 'banner', 'item_id' => $s2, 'sort_order' => 0]),
        $row(['id' => $uuid(), 'homepage_section_id' => $stripSection, 'item_type' => 'banner', 'item_id' => $s1, 'sort_order' => 1]),
    ]);

    $migration->up();
    $slides = fn (string $id) => DB::table('banners')->where('homepage_section_id', $id)->orderBy('sort_order')->pluck('id')->all();
    expect($slides($heroSection))->toBe([$h2, $h3, $h1])             // every home_hero banner, in its old order, renumbered
        ->and($slides($stripSection))->toBe([$s2, $s1])               // the hand-picked list, in its picked order
        ->and(DB::table('banners')->where('id', $cat)->value('homepage_section_id'))->toBeNull(); // other places are untouched
    $sections = DB::table('homepage_sections')->where('type', 'banners')->orderBy('sort_order')->get();
    expect($sections)->toHaveCount(3);
    $hero = HomepageSection::find($heroSection);
    expect($hero->layout)->toBe('carousel')->and($hero->slider())->toBe(SliderDefaults())->and($hero->status)->toBe('published');
    // the banner nobody showed is kept, in a hidden slider of its own
    $kept = $sections->last();
    expect($kept->status)->toBe('draft')->and($slides($kept->id))->toBe([$never])->and(json_decode($kept->settings, true)['created_by_migration'])->toBeTrue();
    // and the feed shows what it showed before (hero banners in the first section)
    expect(collect($this->getJson('/api/v1/home')->json('data.sections'))->pluck('id')->all())->toBe([$heroSection, $stripSection]);

    (new ReflectionMethod($migration, 'moveBannersIntoSliders'))->invoke($migration); // running the data step again changes nothing
    expect($slides($heroSection))->toBe([$h2, $h3, $h1])->and(DB::table('homepage_sections')->where('type', 'banners')->count())->toBe(3);

    $migration->down();
    expect(Schema::hasColumn('banners', 'homepage_section_id'))->toBeFalse()->and(DB::table('homepage_sections')->where('type', 'banners')->count())->toBe(2)
        ->and(json_decode((string) DB::table('homepage_sections')->where('id', $heroSection)->value('settings'), true))->toBe(['placement' => 'home_hero'])
        ->and(DB::table('banners')->count())->toBe(7);
    $migration->up();
});

function SliderDefaults(): array
{
    return ['autoplay' => true, 'interval' => 5, 'aspect' => '16:9', 'dots' => true, 'show_titles' => true, 'rounded' => true];
}
