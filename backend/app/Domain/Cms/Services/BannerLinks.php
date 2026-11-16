<?php

namespace App\Domain\Cms\Services;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\CmsPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Where a slide leads. One rule for the dashboard (validation, friendly summary, "broken link" chip) and the API
 * (a link whose target is gone is served as no link, never as an error):
 *
 *   category → category id · page → CMS page slug · product / brand / auction → their id
 *   url → an https:// address (anything else, javascript: and http: included, is refused) · search → the search text
 */
final class BannerLinks
{
    public const MAX_SEARCH = 120;

    public const MAX_URL = 1000;

    /** The dashboard icon of each link type. */
    public const ICONS = ['category' => 'layers', 'page' => 'file-text', 'product' => 'package', 'brand' => 'crown', 'auction' => 'gavel', 'url' => 'globe', 'search' => 'search'];

    /**
     * A link as the form gives it, cleaned: `['type' => …, 'value' => …]`, or null when it is "no link" or the pair is not acceptable.
     *
     * @return array{type: string, value: string}|null
     */
    public static function normalize(?string $type, ?string $value): ?array
    {
        $type = trim((string) $type);
        $value = trim((string) $value);
        if ($type === '' || $value === '' || ! in_array($type, Banner::LINK_TYPES, true)) {
            return null;
        }

        return match ($type) {
            'category', 'product', 'brand', 'auction' => Str::isUuid($value) ? ['type' => $type, 'value' => strtolower($value)] : null,
            'page' => preg_match('/^[a-z0-9][a-z0-9-]{0,95}$/', strtolower($value)) === 1 ? ['type' => $type, 'value' => strtolower($value)] : null,
            'url' => self::isHttpsUrl($value) ? ['type' => $type, 'value' => $value] : null,
            'search' => ['type' => $type, 'value' => mb_substr(preg_replace('/\s+/u', ' ', $value) ?? $value, 0, self::MAX_SEARCH)],
        };
    }

    /** Only a complete, public https:// address: no other scheme (javascript:, http:, data:, ftp:), no user-info, a real host. */
    public static function isHttpsUrl(string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_URL || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return false;
        }
        $parts = parse_url($value);

        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https' && ($parts['host'] ?? '') !== '' && ! isset($parts['user'], $parts['pass'])
            && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * What the API serves for each slide: `id => {type, value}` for a working link, `id => null` for no link or a broken one.
     * One query per link type for the whole list, never one per slide.
     *
     * @param  Collection<int, Banner>  $banners
     * @return array<string, array{type: string, value: string}|null>
     */
    public function forApi(Collection $banners): array
    {
        $found = $this->targets($banners);
        $out = [];
        foreach ($banners as $b) {
            $link = self::normalize($b->link_type, $b->link_value);
            $out[$b->id] = $link && $this->exists($link, $found) ? $link : null;
        }

        return $out;
    }

    /**
     * What the dashboard shows for each slide: `id => ['type', 'value', 'label', 'sub', 'ok']`, or null for "no link".
     * `ok` is false (and the label says why) when the target is gone, hidden or the stored pair is not acceptable.
     *
     * @param  Collection<int, Banner>  $banners
     * @return array<string, array{type: string, value: string, label: string, sub: ?string, ok: bool}|null>
     */
    public function describeMany(Collection $banners): array
    {
        $found = $this->targets($banners);
        $out = [];
        foreach ($banners as $b) {
            if (! $b->link_type) {
                $out[$b->id] = null;

                continue;
            }
            $link = self::normalize($b->link_type, $b->link_value);
            $label = $link ? ($found[$link['type']][$link['value']] ?? null) : null;
            $out[$b->id] = [
                'type' => (string) $b->link_type, 'value' => (string) $b->link_value,
                'label' => $label['label'] ?? (string) $b->link_value, 'sub' => $label['sub'] ?? null,
                'ok' => $link !== null && $label !== null,
            ];
        }

        return $out;
    }

    /** Friendly description of one link (used by the editor while the admin is choosing). */
    public function describe(?string $type, ?string $value): ?array
    {
        if (! $type) {
            return null;
        }
        $b = new Banner(['link_type' => $type, 'link_value' => $value]);
        $b->id = 'one';

        return $this->describeMany(collect([$b]))['one'];
    }

    /** @param array{type: string, value: string} $link */
    private function exists(array $link, array $found): bool
    {
        return isset($found[$link['type']][$link['value']]);
    }

    /**
     * For every type present: the targets that exist and are visible to customers, keyed by the link value.
     *
     * @param  Collection<int, Banner>  $banners
     * @return array<string, array<string, array{label: string, sub: ?string}>>
     */
    private function targets(Collection $banners): array
    {
        $values = [];
        foreach ($banners as $b) {
            $link = self::normalize($b->link_type, $b->link_value);
            if ($link) {
                $values[$link['type']][$link['value']] = true;
            }
        }
        $keys = fn (string $type): array => array_keys($values[$type] ?? []);
        $found = [];

        if ($ids = $keys('category')) {
            $cats = Category::active()->with('translations')->whereIn('id', $ids)->get();
            $names = $this->categoryNames($cats);
            foreach ($cats as $c) {
                $found['category'][$c->id] = ['label' => (string) $c->trans('name'), 'sub' => $names[$c->id] ?? null];
            }
        }
        if ($slugs = $keys('page')) {
            foreach (CmsPage::with('translations')->where('status', 'published')->whereIn('slug', $slugs)->get() as $p) {
                $t = $p->translations->firstWhere('locale', app()->getLocale()) ?? $p->translations->firstWhere('locale', 'en') ?? $p->translations->first();
                $found['page'][$p->slug] = ['label' => (string) ($t?->title ?: $p->slug), 'sub' => '/'.$p->slug];
            }
        }
        if ($ids = $keys('product')) {
            foreach (Product::active()->with('translations')->whereIn('id', $ids)->get() as $p) {
                $found['product'][$p->id] = ['label' => (string) $p->trans('name'), 'sub' => $p->sku];
            }
        }
        if ($ids = $keys('brand')) {
            foreach (Brand::where('status', 'active')->whereIn('id', $ids)->get() as $b) {
                $found['brand'][$b->id] = ['label' => (string) $b->localized('name'), 'sub' => null];
            }
        }
        if ($ids = $keys('auction')) {
            foreach (Auction::with('translations')->where('status', '!=', 'draft')->whereIn('id', $ids)->get() as $a) {
                $found['auction'][$a->id] = ['label' => (string) $a->trans('title'), 'sub' => $a->reference];
            }
        }
        foreach ($keys('url') as $url) {
            $host = (string) parse_url($url, PHP_URL_HOST);
            $found['url'][$url] = ['label' => $host, 'sub' => Str::limit(substr($url, strpos($url, $host) + strlen($host)) ?: '/', 60)];
        }
        foreach ($keys('search') as $text) {
            $found['search'][$text] = ['label' => $text, 'sub' => null];
        }

        return $found;
    }

    /**
     * "Living room › Sofas" for each category, from its stored ancestor path, with one extra query for all the ancestors.
     *
     * @param  Collection<int, Category>  $cats
     * @return array<string, ?string>
     */
    private function categoryNames(Collection $cats): array
    {
        $ancestorIds = $cats->flatMap(fn (Category $c) => array_filter(explode('/', (string) $c->path)))->unique()->values()->all();
        $ancestors = $ancestorIds ? Category::with('translations')->whereIn('id', $ancestorIds)->get()->keyBy('id') : collect();
        $out = [];
        foreach ($cats as $c) {
            $trail = collect(array_filter(explode('/', (string) $c->path)))->map(fn ($id) => $ancestors->get($id)?->trans('name'))->filter()->push($c->trans('name'));
            $out[$c->id] = $trail->count() > 1 ? $trail->implode(' › ') : null;
        }

        return $out;
    }
}
