<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Room;
use App\Domain\Cms\Models\HomepageSection;
use App\Domain\Cms\Services\HomeLayout;
use App\Domain\Cms\Services\HomepageService;
use App\Domain\Settings\Services\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "Shop by room": which products belong to a room, which rooms a shopper sees, and the tiles of the home page and the shop filter.
 *
 * A product is in a room when it is in one of the room's categories (their sub-categories too) or carries one of its tags (a tag is compared without regard
 * to upper or lower case). A room with no product the shopper can see at their branch is left out, so an empty room never leads to an empty page. The tiles
 * are remembered for two minutes per language and branch; any change to a room forgets them at once, and so does the home feed cache.
 */
final class RoomService
{
    private const REVISION_KEY = 'rooms:revision';

    private const TILE_SECONDS = 120;

    /** Every cached copy of the tiles (all languages and branches) is dropped at once: the cache key carries this revision. The home feed is dropped with it. */
    public static function flush(): void
    {
        Cache::forever(self::REVISION_KEY, (string) Str::uuid());
        HomepageService::flush();
    }

    public static function revision(): string
    {
        return (string) Cache::get(self::REVISION_KEY, '0');
    }

    /**
     * Puts the "Shop by room" section on the home page, once: right after the categories section (or at the bottom when there is none), published, so the
     * tiles are on the page without a visit to the home page builder. It is done once only (a setting remembers it): an owner who removes the section keeps it removed.
     * Called by the scheduler (`rooms:ensure-home`) and when someone who may edit the content opens Content > Rooms.
     *
     * @return bool whether the section was added now
     */
    public function ensureHomeSection(): bool
    {
        $settings = app(Settings::class);
        if ($settings->get('rooms.home_section_seeded', false)) {
            return false;
        }

        return Cache::lock('rooms:home-section', 10)->block(5, function () use ($settings) {
            if ($settings->get('rooms.home_section_seeded', false)) {
                return false;
            }
            $added = false;
            if (! HomepageSection::withTrashed()->where('type', 'rooms')->exists()) {
                $categories = HomepageSection::where('type', 'categories')->orderBy('sort_order')->orderBy('created_at')->first();
                if ($categories !== null && $categories->title_en === 'Shop by room' && ($categories->title_ar === null || $categories->title_ar === 'تسوّق حسب الغرفة')) {
                    // The seeded categories section used to be called "Shop by room"; with real rooms on the page that name would appear twice, so (only while it still has the shipped title) it becomes what it is.
                    $categories->update(['title_en' => 'Shop by category', 'title_ar' => 'تسوّق حسب الفئة']);
                }
                $after = $categories?->id;
                app(HomeLayout::class)->addSection(['type' => 'rooms', 'layout' => 'grid', 'title_en' => 'Shop by room', 'title_ar' => 'تسوّق حسب الغرفة', 'item_limit' => 8, 'status' => 'published'],
                    $after ? 'after:'.$after : 'bottom');
                $added = true;
            }
            $settings->set('rooms.home_section_seeded', true);

            return $added;
        });
    }

    /** The room a shopper can open by its address word, or null when there is none (or it is switched off). */
    public function find(string $slug): ?Room
    {
        return Room::active()->where('slug', $slug)->with('categories:id,path')->first();
    }

    /**
     * Narrows a products query to a room. An unknown or switched-off room matches nothing (a link to a deleted room shows an empty shop, never the whole catalogue).
     *
     * @param  Builder<Product>  $q
     */
    public function scope(Builder $q, string $slug): void
    {
        $room = $this->find($slug);
        if ($room === null) {
            $q->whereRaw('1 = 0');

            return;
        }
        $this->scopeTo($q, $room);
    }

    /** @param  Builder<Product>  $q */
    public function scopeTo(Builder $q, Room $room): void
    {
        $roots = $room->categories;
        $categoryIds = $roots->isEmpty() ? collect() : Category::query()->where(function ($w) use ($roots) {
            foreach ($roots as $c) {
                $w->orWhere('path', 'like', $c->path.$c->id.'/%');
            }
        })->pluck('id')->merge($roots->pluck('id'))->unique()->values();
        $tags = collect($room->tags ?? [])->map(fn ($t) => mb_strtolower(trim((string) $t)))->filter()->unique()->values();

        $q->where(function ($w) use ($categoryIds, $tags) {
            if ($categoryIds->isNotEmpty()) {
                $w->orWhereIn('products.category_id', $categoryIds->all());
            }
            if ($tags->isNotEmpty()) {
                $w->orWhereRaw("exists (select 1 from jsonb_array_elements_text(case when jsonb_typeof(products.tags) = 'array' then products.tags else '[]'::jsonb end) as t(tag) where lower(t.tag) = any(?::text[]))",
                    ['{'.$tags->map(fn (string $t) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $t).'"')->implode(',').'}']);
            }
            if ($categoryIds->isEmpty() && $tags->isEmpty()) {
                $w->whereRaw('1 = 0'); // a room with nothing assigned to it has no products
            }
        });
    }

    /**
     * How many products the shopper can see in a room at a branch (active, sold there, with an active variant).
     *
     * @param  ?string  $branchId  null = no branch chosen: every active product counts
     */
    public function productCount(Room $room, ?string $branchId): int
    {
        $q = Product::query()->active()->visibleInBranch($branchId)
            ->whereExists(fn ($s) => $s->from('product_variants as av')->whereColumn('av.product_id', 'products.id')->where('av.status', 'active'));
        $this->scopeTo($q, $room);

        return $q->count();
    }

    /**
     * The rooms a shopper sees, in the owner's order, each with its picture and how many products it holds. Rooms with no product are not in it.
     *
     * @return list<array{id: string, slug: string, name: string, description: ?string, image_url: ?string, thumb_url: ?string, products_count: int, link: array{type: string, value: string}}>
     */
    public function tiles(?string $branchId, ?int $limit = null): array
    {
        $key = 'rooms:tiles:'.self::revision().':'.app()->getLocale().':'.($branchId ?? 'all');
        $all = Cache::remember($key, self::TILE_SECONDS, fn () => $this->build($branchId));

        return $limit === null ? $all : array_slice($all, 0, max(1, $limit));
    }

    /** @return list<array<string, mixed>> */
    private function build(?string $branchId): array
    {
        $rooms = Room::active()->with('categories:id,path,image_path')->orderBy('sort_order')->orderBy('title_en')->get();
        $tiles = [];
        foreach ($rooms as $room) {
            $count = $this->productCount($room, $branchId);
            if ($count === 0) {
                continue;
            }
            // No picture of its own: the picture of its first category stands in (every category of the store has artwork; it has no small copy).
            $path = $room->image_path ?: $room->categories->pluck('image_path')->filter()->first();
            $tiles[] = ['id' => $room->id, 'slug' => $room->slug, 'name' => $room->title(), 'description' => $room->description(), 'image_url' => $this->url($path),
                'thumb_url' => $room->image_path ? $this->url(RoomImages::thumbPath($room->image_path)) : null, 'products_count' => $count, 'link' => ['type' => 'room', 'value' => $room->slug]];
        }

        return $tiles;
    }

    private function url(?string $path): ?string
    {
        return $path ? (str_starts_with($path, 'http') ? $path : Storage::disk(config('platform.media.disk'))->url($path)) : null;
    }

    /** @return Collection<int, Room> every room, active or not, in order (the dashboard list) */
    public function all(): Collection
    {
        return Room::query()->with('categories:id')->orderBy('sort_order')->orderBy('title_en')->get();
    }
}
