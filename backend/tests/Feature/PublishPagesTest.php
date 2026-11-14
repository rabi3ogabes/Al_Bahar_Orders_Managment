<?php

use App\Domain\Cms\Models\CmsPage;
use Illuminate\Support\Facades\DB;

/** The one-time publishing of the store information pages (cms:publish-pages). */
function pagesSpec(array $over = []): string
{
    $page = fn (string $slug) => ['slug' => $slug,
        'en' => ['title' => ucfirst($slug), 'body' => '<h2>Heading</h2><p>Text <a href="/shop">shop</a><script>alert(1)</script></p>', 'seo_description' => 'About '.$slug],
        'ar' => ['title' => 'صفحة '.$slug, 'body' => '<p>نص عربي</p>']];
    $path = tempnam(sys_get_temp_dir(), 'pages').'.json';
    file_put_contents($path, json_encode(array_merge([$page('about'), $page('terms')], $over), JSON_UNESCAPED_UNICODE));

    return $path;
}

it('publishes draft pages with cleaned text in both languages and a record in the audit log', function () {
    $draft = CmsPage::create(['slug' => 'about', 'status' => 'draft']);
    $draft->translations()->create(['locale' => 'en', 'title' => 'About us', 'body' => '<p>stub</p>']);

    $this->artisan('cms:publish-pages', ['spec' => pagesSpec()])->expectsOutputToContain('Published 2 pages')->assertSuccessful();

    $about = CmsPage::with('translations')->where('slug', 'about')->firstOrFail();
    $en = $about->translations->firstWhere('locale', 'en');
    expect($about->status)->toBe('published')->and($about->published_at)->not->toBeNull()
        ->and($about->translations)->toHaveCount(2)
        ->and($en->title)->toBe('About')->and($en->body)->toContain('<h2>Heading</h2>')->toContain('href="/shop"')->not->toContain('script')->not->toContain('alert')
        ->and($en->draft_body)->toBe($en->body)->and($en->seo_description)->toBe('About about')
        ->and($about->translations->firstWhere('locale', 'ar')->body)->toContain('نص عربي')
        ->and(CmsPage::where('status', 'published')->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('action', 'cms.page_published')->count())->toBe(2);
});

it('is safe to run twice and can publish one page only', function () {
    $this->artisan('cms:publish-pages', ['spec' => pagesSpec(), '--only' => 'terms'])->assertSuccessful();
    expect(CmsPage::pluck('slug')->all())->toBe(['terms']);

    $this->artisan('cms:publish-pages', ['spec' => pagesSpec()])->assertSuccessful();
    $this->artisan('cms:publish-pages', ['spec' => pagesSpec()])->assertSuccessful();

    expect(CmsPage::count())->toBe(2)->and(DB::table('cms_page_translations')->count())->toBe(4);
});

it('changes nothing on a dry run and refuses a page without both languages', function () {
    $this->artisan('cms:publish-pages', ['spec' => pagesSpec(), '--dry-run' => true])->expectsOutputToContain('would publish about')->assertSuccessful();
    expect(CmsPage::count())->toBe(0);

    $bad = pagesSpec([['slug' => 'faq', 'en' => ['title' => 'FAQ', 'body' => '<p>x</p>'], 'ar' => ['title' => 'أسئلة', 'body' => '']]]);
    $this->artisan('cms:publish-pages', ['spec' => $bad])->assertFailed();
    expect(CmsPage::count())->toBe(0);
});

it('has real content for every information page in the shipped spec, in both languages', function () {
    $pages = json_decode((string) file_get_contents(base_path('database/data/albahar_pages.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_column($pages, 'slug'))->toEqualCanonicalizing(['about', 'auction-terms', 'contact', 'faq', 'privacy', 'returns', 'terms']);
    foreach ($pages as $p) {
        expect(mb_strlen(strip_tags($p['en']['body'])))->toBeGreaterThan(300)->and(mb_strlen(strip_tags($p['ar']['body'])))->toBeGreaterThan(250);
        expect(strtolower($p['en']['body'].$p['ar']['body']))->not->toContain('marwa');
    }
    $this->artisan('cms:publish-pages')->assertSuccessful();
    expect(CmsPage::where('status', 'published')->count())->toBe(7);
});
