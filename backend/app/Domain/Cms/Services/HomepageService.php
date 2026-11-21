<?php

namespace App\Domain\Cms\Services;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Services\AuctionPresenter;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\RoomService;
use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Marketing\Models\Promotion;
use App\Support\BusinessTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders the admin-managed homepage: published, in-window sections in admin order. Product sections are priced for
 * the customer's branch. Nothing is hard-coded — an empty CMS yields an empty home.
 */
class HomepageService
{
    /** A slider never serves more slides than this, whatever the section's item limit says (the dashboard allows far fewer). */
    private const MAX_SLIDES = 50;

    private const REVISION_KEY = 'home:revision';

    public function __construct(private readonly CatalogPresenter $catalog, private readonly AuctionPresenter $auctions, private readonly BannerLinks $links, private readonly RoomService $rooms) {}

    /**
     * Drops every cached copy of the home feed (all languages, branches and platforms) at once: the cache key carries this revision.
     * Called by the Banner and HomepageSection models on every save, delete and restore, so a dashboard change is live immediately.
     */
    public static function flush(): void
    {
        Cache::forever(self::REVISION_KEY, (string) Str::uuid());
    }

    public static function revision(): string
    {
        return (string) Cache::get(self::REVISION_KEY, '0');
    }

    public function render(?Branch $branch, string $platform = 'mobile'): array
    {
        $now = now();
        $sections = HomepageSection::with('items')->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->get();
        $slides = $this->slides($sections->where('type', 'banners'), $platform);

        return ['sections' => $sections->map(fn (HomepageSection $s) => $this->section($s, $branch, $slides[$s->id] ?? []))->filter(fn ($s) => $s['items'] !== [])->values()->all()];
    }

    /**
     * The slides of every slider, in one query: published, inside their window, for this platform, with an image and a link that still works.
     *
     * @param  Collection<int, HomepageSection>  $sliders
     * @return array<string, list<array<string, mixed>>> slider id => slides in admin order
     */
    private function slides(Collection $sliders, string $platform): array
    {
        if ($sliders->isEmpty()) {
            return [];
        }
        $ar = app()->getLocale() === 'ar';
        $rows = Banner::live()->whereIn('homepage_section_id', $sliders->pluck('id'))->whereIn('platform', ['all', $platform])->whereNotNull('image_en_path')
            ->orderBy('sort_order')->orderBy('created_at')->orderBy('id')->get();
        $links = $this->links->forApi($rows);

        return $rows->groupBy('homepage_section_id')->map(fn (Collection $group) => $group->take(self::MAX_SLIDES)->map(fn (Banner $b) => [
            'id' => $b->id, 'title' => $ar ? ($b->title_ar ?: $b->title_en) : $b->title_en,
            'subtitle' => $ar ? ($b->subtitle_ar ?: $b->subtitle_en) : $b->subtitle_en,
            'image_url' => $this->url($ar ? ($b->image_ar_path ?: $b->image_en_path) : $b->image_en_path),
            'link' => $links[$b->id] ?? null,
        ])->values()->all())->all();
    }

    /** @param  list<array<string, mixed>>  $slides  the already filtered slides when this section is a slider */
    private function section(HomepageSection $s, ?Branch $branch, array $slides = []): array
    {
        $ar = app()->getLocale() === 'ar';
        $limit = max(1, min(50, (int) $s->item_limit));
        $ids = $s->items->pluck('item_id')->all();
        $products = fn ($q) => $this->catalog->cards($q->active()->visibleInBranch($branch?->id)->with(['translations', 'brand', 'images', 'variants' => fn ($v) => $v->where('status', 'active')])->limit($limit)->get(), $branch);

        $items = match ($s->type) {
            'banners' => $slides,
            'categories' => Category::active()->with('translations')->when($ids, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->whereNull('parent_id'))
                ->orderBy('sort_order')->limit($limit)->get()->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->trans('name'), 'image_url' => $this->url($c->image_path), 'icon_url' => $this->url($c->icon_path)])->all(),
            // "Shop by room": the rooms that have products here (the tiles; a room with nothing in it is left out), see RoomService.
            'rooms' => $this->rooms->tiles($branch?->id, $limit),
            'featured_products' => $products(Product::query()->when($ids, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->where('is_featured', true))),
            'new_arrivals' => $products(Product::query()->where('is_new_arrival', true)->orderByDesc('published_at')),
            'best_sellers' => $products(Product::query()->where(fn ($q) => $q->where('is_bestseller', true)->orWhere('sales_count', '>', 0))->orderByDesc('sales_count')),
            'recommended' => $products(Product::query()->orderByDesc('rating_average')->orderByDesc('sales_count')),
            'product_collection' => $ids ? $products(Product::query()->whereIn('id', $ids)) : [],
            'brands' => Brand::where('status', 'active')->when($ids, fn ($q) => $q->whereIn('id', $ids))->orderBy('sort_order')->limit($limit)->get()
                ->map(fn ($b) => ['id' => $b->id, 'slug' => $b->slug, 'name' => $b->localized('name'), 'logo_url' => $this->url($b->logo_path)])->all(),
            'promotions' => Promotion::where('is_active', true)->where('requires_coupon', false)->when($ids, fn ($q) => $q->whereIn('id', $ids))
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->limit($limit)->get()
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->localized('name'), 'description' => $p->localized('description'),
                    'badge' => in_array($p->type, ['percentage', 'flash_sale'], true) ? '-'.rtrim(rtrim((string) $p->value, '0'), '.').'%' : null, 'ends_at' => BusinessTime::iso($p->ends_at)])->all(),
            'upcoming_auctions' => Auction::with(['translations', 'lots'])->where('status', 'scheduled')->whereDoesntHave('lots', fn ($q) => $q->where('status', 'open'))->orderBy('starts_at')->limit($limit)->get()->map(fn ($a) => $this->auctions->card($a))->all(),
            'live_auctions' => Auction::with(['translations', 'lots'])->where(fn ($q) => $q->where('status', 'live')->orWhereHas('lots', fn ($l) => $l->where('status', 'open')))->limit($limit)->get()->map(fn ($a) => $this->auctions->card($a))->all(),
            default => [],
        };

        $out = ['id' => $s->id, 'type' => $s->type, 'layout' => $s->isSlider() ? 'carousel' : $s->layout, 'title' => $ar ? ($s->title_ar ?: $s->title_en) : $s->title_en,
            'subtitle' => $ar ? ($s->subtitle_ar ?: $s->subtitle_en) : $s->subtitle_en];
        if ($s->isSlider()) {
            $out['slider'] = $s->slider();
        }

        return $out + ['items' => array_values($items)];
    }

    private function url(?string $path): ?string
    {
        return $path ? (str_starts_with($path, 'http') ? $path : Storage::disk(config('platform.media.disk'))->url($path)) : null;
    }
}
