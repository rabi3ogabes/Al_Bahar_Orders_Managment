<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Cms\Models\CmsPage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Writes the information pages (About, Contact, FAQ, Returns, Terms, Privacy, Auction terms) from database/data/albahar_pages.json and
 * publishes them, exactly as the dashboard's "Publish" button does: the text is cleaned by the same allow-list, kept as the draft and as
 * the published body, and the change is written to the audit log. Safe to run again: it overwrites the same pages.
 */
class PublishPages extends Command
{
    protected $signature = 'cms:publish-pages
        {spec=database/data/albahar_pages.json : the pages (slug, English and Arabic title, body and search description)}
        {--only= : only this page slug}
        {--dry-run : list what would be published and change nothing}';

    protected $description = 'Write and publish the store information pages';

    public function handle(ProductService $html, AuditLogger $audit): int
    {
        $given = (string) $this->argument('spec');
        $path = str_starts_with($given, '/') ? $given : base_path($given);
        if (! is_file($path)) {
            $this->error("Spec not found: {$path}");

            return self::FAILURE;
        }
        $pages = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $only = (string) $this->option('only');
        $pages = array_values(array_filter($pages, fn (array $p) => $only === '' || $p['slug'] === $only));
        foreach ($pages as $p) {
            foreach (['en', 'ar'] as $l) {
                if (empty($p[$l]['title']) || trim((string) ($p[$l]['body'] ?? '')) === '') {
                    $this->error("{$p['slug']}: the {$l} title and body are required.");

                    return self::FAILURE;
                }
            }
        }
        foreach ($pages as $p) {
            $this->line(($this->option('dry-run') ? 'would publish ' : 'publishing ').$p['slug']);
        }
        if ($this->option('dry-run') || $pages === []) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($pages, $html, $audit) {
            foreach ($pages as $p) {
                $page = CmsPage::firstOrNew(['slug' => $p['slug']]);
                $page->fill(['status' => 'published', 'published_at' => now()])->save();
                foreach (['en', 'ar'] as $l) {
                    $clean = (string) $html->sanitizeHtml($p[$l]['body']);
                    $page->translations()->updateOrCreate(['locale' => $l], [
                        'title' => $p[$l]['title'], 'body' => $clean, 'draft_body' => $clean,
                        'seo_title' => $p[$l]['seo_title'] ?? null, 'seo_description' => $p[$l]['seo_description'] ?? null,
                    ]);
                }
                $audit->log('cms.page_published', $page, [], ['slug' => $page->slug, 'status' => 'published']);
            }
        });
        Cache::flush();
        $this->info('Published '.count($pages).' pages.');

        return self::SUCCESS;
    }
}
