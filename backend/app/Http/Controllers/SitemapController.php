<?php

namespace App\Http\Controllers;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\CmsPage;
use App\Support\StorefrontSeo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** /sitemap.xml (every public address worth indexing) and /robots.txt (what to keep out, and where the sitemap is). */
class SitemapController extends Controller
{
    public function sitemap(StorefrontSeo $seo): Response
    {
        $xml = Cache::remember('storefront:sitemap', (int) config('platform.seo.sitemap_seconds', 600), fn () => $this->xml($seo->base()));

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }

    public function robots(StorefrontSeo $seo): Response
    {
        $lines = ['User-agent: *', 'Disallow: /admin', 'Disallow: /api/', 'Disallow: /partner/', 'Disallow: /cart', 'Disallow: /checkout', 'Disallow: /account',
            'Disallow: /orders/', 'Disallow: /payments/', 'Disallow: /*?q=', 'Allow: /', '', 'Sitemap: '.$seo->base().'/sitemap.xml'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    private function xml(string $base): string
    {
        $urls = [['/', null, 'daily', '1.0'], ['/shop', null, 'daily', '0.9'], ['/showrooms', null, 'monthly', '0.5'], ['/auctions', null, 'daily', '0.4']];
        $rows = [];
        foreach ($urls as [$path, $mod, $freq, $prio]) {
            $rows[] = $this->url($base.$path, $mod, $freq, $prio);
        }
        Category::active()->orderBy('sort_order')->get(['slug', 'updated_at'])->each(function (Category $c) use (&$rows, $base) {
            $rows[] = $this->url($base.'/shop?category='.rawurlencode($c->slug), $c->updated_at, 'weekly', '0.8');
        });
        Product::active()->with(['translations', 'images'])->orderBy('created_at')->chunk(500, function ($products) use (&$rows, $base) {
            foreach ($products as $p) {
                $slug = $p->trans('slug');
                if ($slug === null || $slug === '') {
                    continue;
                }
                $image = $p->images->firstWhere('product_variant_id', null) ?? $p->images->first();
                $rows[] = $this->url($base.'/products/'.rawurlencode($slug), $p->updated_at, 'weekly', '0.7', $image ? $image->url() : null);
            }
        });
        CmsPage::where('status', 'published')->orderBy('slug')->get(['slug', 'updated_at'])->each(function (CmsPage $p) use (&$rows, $base) {
            $rows[] = $this->url($base.'/pages/'.rawurlencode($p->slug), $p->updated_at, 'yearly', '0.3');
        });

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'."\n"
            .implode("\n", $rows)."\n</urlset>\n";
    }

    private function url(string $loc, mixed $modified, string $freq, string $priority, ?string $image = null): string
    {
        $x = '  <url><loc>'.e($loc).'</loc>';
        if ($modified !== null) {
            $x .= '<lastmod>'.e($modified->toAtomString()).'</lastmod>';
        }
        $x .= '<changefreq>'.$freq.'</changefreq><priority>'.$priority.'</priority>';
        if ($image !== null) {
            $x .= '<image:image><image:loc>'.e(str_starts_with($image, 'http') ? $image : Storage::disk(config('platform.media.disk'))->url($image)).'</image:loc></image:image>';
        }

        return $x.'</url>';
    }
}
