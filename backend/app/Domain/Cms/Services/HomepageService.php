<?php

namespace App\Domain\Cms\Services;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Services\AuctionPresenter;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Cms\Models\Banner;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Marketing\Models\Promotion;
use App\Support\BusinessTime;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the admin-managed homepage: published, in-window sections in admin order. Product sections are priced for
 * the customer's branch. Nothing is hard-coded — an empty CMS yields an empty home.
 */
class HomepageService
{
    public function __construct(private readonly CatalogPresenter $catalog, private readonly AuctionPresenter $auctions) {}

    public function render(?Branch $branch, string $platform = 'mobile'): array
    {
        $now = now();
        $sections = HomepageSection::with('items')->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('sort_order')->get();

        return ['sections' => $sections->map(fn (HomepageSection $s) => $this->section($s, $branch, $platform))->filter(fn ($s) => $s['items'] !== [])->values()->all()];
    }

    private function section(HomepageSection $s, ?Branch $branch, string $platform): array
    {
        $ar = app()->getLocale() === 'ar';
        $limit = max(1, min(50, (int) $s->item_limit));
        $ids = $s->items->pluck('item_id')->all();
        $products = fn ($q) => $this->catalog->cards($q->active()->visibleInBranch($branch?->id)->with(['translations', 'brand', 'images', 'variants' => fn ($v) => $v->where('status', 'active')])->limit($limit)->get(), $branch);
        $inWindow = fn ($q) => $q->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));

        $items = match ($s->type) {
            'banners' => $inWindow(Banner::where('status', 'published')->whereIn('platform', ['all', $platform])
                ->when($ids, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->where('placement', $s->settings['placement'] ?? 'home_hero')))
                ->orderBy('sort_order')->limit($limit)->get()->map(fn ($b) => [
                    'id' => $b->id, 'title' => $ar ? ($b->title_ar ?: $b->title_en) : $b->title_en,
                    'subtitle' => $ar ? ($b->subtitle_ar ?: $b->subtitle_en) : $b->subtitle_en,
                    'image_url' => $this->url($ar ? ($b->image_ar_path ?: $b->image_en_path) : $b->image_en_path),
                    'link' => $b->link_type ? ['type' => $b->link_type, 'value' => $b->link_value] : null,
                ])->all(),
            'categories' => Category::active()->with('translations')->when($ids, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->whereNull('parent_id'))
                ->orderBy('sort_order')->limit($limit)->get()->map(fn ($c) => ['id' => $c->id, 'slug' => $c->slug, 'name' => $c->trans('name'), 'image_url' => $this->url($c->image_path), 'icon_url' => $this->url($c->icon_path)])->all(),
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

        return ['id' => $s->id, 'type' => $s->type, 'layout' => $s->layout, 'title' => $ar ? ($s->title_ar ?: $s->title_en) : $s->title_en,
            'subtitle' => $ar ? ($s->subtitle_ar ?: $s->subtitle_en) : $s->subtitle_en, 'items' => array_values($items)];
    }

    private function url(?string $path): ?string
    {
        return $path ? (str_starts_with($path, 'http') ? $path : Storage::disk(config('platform.media.disk'))->url($path)) : null;
    }
}
