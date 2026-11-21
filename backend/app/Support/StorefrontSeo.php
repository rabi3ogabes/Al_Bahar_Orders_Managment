<?php

namespace App\Support;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Room;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\RoomService;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Support\DealPricing;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * What a search engine or a link preview should see for a storefront URL. The storefront is a single-page app, so the HTML shell is the
 * same for every address; this builds the page-specific title, description, canonical link, robots rule, social tags and structured data
 * (schema.org JSON-LD) from the real catalogue, in English (the shell's language), and tells the controller to answer 404 for a product or
 * page that does not exist. Nothing here is invented: prices, stock, brand and ratings come from the database, and a rating is only
 * described when real reviews exist.
 */
class StorefrontSeo
{
    public const SUFFIX = 'Al Bahar Home & Living';

    private const PRIVATE = '#^(cart|checkout|login|register|account|orders|payments|partner)(/|$)#';

    public function __construct(private readonly CatalogPresenter $presenter) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{status: int, title: ?string, description: ?string, canonical: ?string, robots: ?string, type: string, image: ?string, jsonld: list<array<string, mixed>>, product: ?array<string, string>}
     */
    public function for(string $path, array $query = []): array
    {
        $path = trim($path, '/');
        $key = 'storefront:seo:'.md5($path.'|'.json_encode(array_intersect_key($query, array_flip(['category', 'room', 'q', 'brand', 'min', 'max', 'sale', 'new', 'stock']))));

        // Deals are never remembered: a deal ends, sells out or is paused at any moment, and its page must say noindex the moment it does.
        $remember = $path !== 'deals' && ! str_starts_with($path, 'deals/');
        $cached = $remember ? Cache::get($key) : null;
        if (is_array($cached)) {
            return $cached;
        }
        $meta = $this->build($path, $query);
        if ($remember && $meta['status'] === 200) { // a missing product may be created a minute later: only found pages are remembered
            Cache::put($key, $meta, (int) config('platform.seo.cache_seconds', 300));
        }

        return $meta;
    }

    public function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** @return array{status: int, title: ?string, description: ?string, canonical: ?string, robots: ?string, type: string, image: ?string, jsonld: list<array<string, mixed>>, product: ?array<string, string>} */
    private function build(string $path, array $query): array
    {
        $locale = App::getLocale();
        App::setLocale('en');
        try {
            return match (true) {
                $path === '' => $this->home(),
                $path === 'shop' => $this->shop($query),
                (bool) preg_match('#^products/([^/]+)$#', $path, $m) => $this->product(rawurldecode($m[1])),
                (bool) preg_match('#^pages/([^/]+)$#', $path, $m) => $this->page(rawurldecode($m[1])),
                $path === 'showrooms' => $this->plain('Showrooms', 'Find an Al Bahar showroom: address, opening hours and directions.', '/showrooms'),
                $path === 'deals' => $this->deals(),
                (bool) preg_match('#^deals/([^/]+)$#', $path, $m) => $this->deal(rawurldecode($m[1])),
                $path === 'auctions' => $this->plain('Auctions', 'Bid on home and living pieces in Al Bahar auctions.', '/auctions'),
                (bool) preg_match(self::PRIVATE, $path) => $this->hidden(),
                str_starts_with($path, 'auctions/') => $this->plain(null, null, '/'.$path),
                default => $this->result(['status' => 404, 'title' => 'Not found · '.self::SUFFIX, 'robots' => 'noindex,follow']),
            };
        } finally {
            App::setLocale($locale);
        }
    }

    /** @return array<string, mixed> */
    private function result(array $over = []): array
    {
        return $over + ['status' => 200, 'title' => null, 'description' => null, 'canonical' => null, 'robots' => null, 'type' => 'website', 'image' => null, 'jsonld' => [], 'product' => null];
    }

    private function home(): array
    {
        $base = $this->base();

        return $this->result([
            'canonical' => $base.'/',
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => self::SUFFIX, 'url' => $base.'/'],
                ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => self::SUFFIX, 'url' => $base.'/',
                    'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $base.'/shop?q={search_term_string}'], 'query-input' => 'required name=search_term_string']],
            ],
        ]);
    }

    private function plain(?string $title, ?string $description, string $path): array
    {
        return $this->result(['title' => $title ? $title.' · '.self::SUFFIX : null, 'description' => $description, 'canonical' => $this->base().$path]);
    }

    private function hidden(): array
    {
        return $this->result(['robots' => 'noindex,nofollow']);
    }

    private function shop(array $query): array
    {
        $base = $this->base();
        $slug = is_string($query['category'] ?? null) ? $query['category'] : null;
        $category = $slug ? Category::active()->with('translations')->where('slug', $slug)->first() : null;
        $searching = is_string($query['q'] ?? null) && $query['q'] !== '';
        $room = $category === null && is_string($query['room'] ?? null) && $query['room'] !== '' ? app(RoomService::class)->find($query['room']) : null;

        if ($room !== null) {
            return $this->roomPage($room, $searching);
        }
        if ($category === null) {
            return $this->result([
                'title' => 'Shop · '.self::SUFFIX, 'description' => 'Browse household essentials from Al Bahar: kitchen, cleaning, bath, storage, fragrance, bedding and decor.',
                'canonical' => $base.'/shop', 'robots' => $searching ? 'noindex,follow' : null,
            ]);
        }
        $url = $base.'/shop?category='.rawurlencode($category->slug);
        $name = (string) $category->trans('name');
        $image = $category->image_path ? $this->storageUrl($category->image_path) : null;

        return $this->result([
            'title' => $name.' · '.self::SUFFIX, 'description' => $this->excerpt((string) $category->trans('description')) ?: 'Shop '.$name.' at '.self::SUFFIX.'.',
            'canonical' => $url, 'robots' => $searching ? 'noindex,follow' : null, 'image' => $image,
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $name, 'url' => $url, 'description' => $this->excerpt((string) $category->trans('description')) ?: null],
                $this->breadcrumbs([['Home', $base.'/'], ['Shop', $base.'/shop'], [$name, $url]]),
            ],
        ]);
    }

    /** `/shop?room=bedroom`: the page of a room, with its own title, description and picture (the room's texts are the owner's; nothing is invented). */
    private function roomPage(Room $room, bool $searching): array
    {
        $base = $this->base();
        $url = $base.'/shop?room='.rawurlencode($room->slug);
        $name = $room->title('en');
        $description = $this->excerpt((string) $room->description('en')) ?: 'Shop for the '.mb_strtolower($name).' at '.self::SUFFIX.'.';
        $path = $room->image_path ?: $room->categories()->whereNotNull('image_path')->value('image_path');

        return $this->result([
            'title' => $name.' · '.self::SUFFIX, 'description' => $description, 'canonical' => $url, 'robots' => $searching ? 'noindex,follow' : null, 'image' => $path ? $this->storageUrl($path) : null,
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $name, 'url' => $url, 'description' => $description],
                $this->breadcrumbs([['Home', $base.'/'], ['Shop', $base.'/shop'], [$name, $url]]),
            ],
        ]);
    }

    private function product(string $idOrSlug): array
    {
        $product = Product::active()
            ->with(['translations', 'brand', 'category.translations', 'images', 'variants' => fn ($q) => $q->where('status', 'active')])
            ->where(fn ($q) => $q->whereKey(Str::isUuid($idOrSlug) ? $idOrSlug : null)->orWhereHas('translations', fn ($t) => $t->where('slug', $idOrSlug)))
            ->first();
        if ($product === null) {
            return $this->result(['status' => 404, 'title' => 'Not found · '.self::SUFFIX, 'robots' => 'noindex,follow']);
        }
        $card = $this->presenter->cards(collect([$product]), null)[0];
        $base = $this->base();
        $url = $base.'/products/'.$product->trans('slug');
        $name = (string) $product->trans('name');
        $description = $this->excerpt((string) ($product->trans('seo_description') ?: $product->trans('short_description') ?: $product->trans('description')));
        $images = $product->images->map(fn ($i) => $i->url())->values()->all();
        $final = $card['price']['final'] ?? null;
        $category = $product->category;

        $ld = ['@context' => 'https://schema.org', '@type' => 'Product', 'name' => $name, 'sku' => $product->sku, 'url' => $url];
        if ($description !== '') {
            $ld['description'] = $description;
        }
        if ($images !== []) {
            $ld['image'] = $images;
        }
        if ($product->brand) {
            $ld['brand'] = ['@type' => 'Brand', 'name' => (string) $product->brand->localized('name')];
        }
        if ($final !== null) {
            $ld['offers'] = ['@type' => 'Offer', 'url' => $url, 'priceCurrency' => $final['currency'], 'price' => $final['amount'], 'itemCondition' => 'https://schema.org/NewCondition',
                'availability' => 'https://schema.org/'.($card['in_stock'] ? 'InStock' : 'OutOfStock'), 'seller' => ['@type' => 'Organization', 'name' => self::SUFFIX]];
        }
        if ((int) $card['rating']['count'] > 0) {
            $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (string) $card['rating']['average'], 'reviewCount' => (int) $card['rating']['count']];
        }
        $crumbs = [['Home', $base.'/'], ['Shop', $base.'/shop']];
        if ($category) {
            $crumbs[] = [(string) $category->trans('name'), $base.'/shop?category='.rawurlencode($category->slug)];
        }
        $crumbs[] = [$name, $url];

        return $this->result([
            'title' => (string) ($product->trans('seo_title') ?: $this->defaultTitle($name)), 'description' => $description ?: null, 'canonical' => $url,
            'type' => 'product', 'image' => $images[0] ?? null, 'jsonld' => [$ld, $this->breadcrumbs($crumbs)],
            'product' => $final ? ['amount' => (string) $final['amount'], 'currency' => (string) $final['currency'], 'availability' => $card['in_stock'] ? 'in stock' : 'out of stock'] : null,
        ]);
    }

    /** /deals: the countdown deals page, titled and described in English and Arabic (one address serves both languages). */
    private function deals(): array
    {
        $base = $this->base();
        $name = __('deals.seo_list_title', [], 'en').' | '.__('deals.seo_list_title', [], 'ar');
        $description = __('deals.seo_list_description', [], 'en').' '.__('deals.seo_list_description', [], 'ar');

        return $this->result([
            'title' => $name.' · '.self::SUFFIX, 'description' => $description, 'canonical' => $base.'/deals',
            'jsonld' => [$this->collection($base.'/deals', [__('deals.seo_list_title', [], 'en'), __('deals.seo_list_title', [], 'ar')], [__('deals.seo_list_description', [], 'en'), __('deals.seo_list_description', [], 'ar')]),
                $this->breadcrumbs([['Home', $base.'/'], [__('deals.seo_list_title', [], 'en'), $base.'/deals']])],
        ]);
    }

    /**
     * /deals/{id}: a deal that is running (or about to) is indexable with its own bilingual title, description and structured data; an ended or paused one
     * is still answered (the page explains it) but asks not to be indexed; an address that is no deal at all answers 404.
     */
    private function deal(string $id): array
    {
        $deal = Str::isUuid($id) ? Deal::with(['products' => fn ($q) => $q->select('id', 'deal_id', 'product_id', 'quantity_total', 'quantity_sold')->with('product:id,status')])->find($id) : null;
        if ($deal === null) {
            return $this->result(['status' => 404, 'title' => 'Not found · '.self::SUFFIX, 'robots' => 'noindex,follow']);
        }
        $base = $this->base();
        $url = $base.'/deals/'.$deal->id;
        $en = (string) $deal->name_en;
        $ar = (string) ($deal->name_ar ?: $deal->name_en);
        if (! in_array($deal->phase(), [Deal::LIVE, Deal::SOLD_OUT, Deal::SCHEDULED], true)) {
            return $this->result(['title' => __('deals.seo_deal_ended', [], 'en').' | '.__('deals.seo_deal_ended', [], 'ar').' · '.self::SUFFIX,
                'description' => __('deals.seo_deal_ended_description', [], 'en').' '.__('deals.seo_deal_ended_description', [], 'ar'), 'canonical' => $url, 'robots' => 'noindex,follow']);
        }
        $count = $deal->products->filter(fn (DealProduct $p) => $p->product?->status === 'active')->count();
        $discount = fn (string $locale) => match ($deal->discount_type) {
            Deal::PERCENT => __('deals.seo_off_percent', ['value' => DealPricing::plain($deal->discount_value)], $locale),
            Deal::AMOUNT_OFF => __('deals.seo_off_amount', ['value' => DealPricing::plain($deal->discount_value)], $locale),
            default => __('deals.seo_off_fixed', [], $locale),
        };
        $text = fn (string $locale, ?string $tagline) => $this->excerpt((string) ($tagline ?: __('deals.seo_deal_description', [
            'discount' => $discount($locale), 'count' => trans_choice('deals.seo_products', $count, ['count' => $count], $locale),
            'end' => (string) BusinessTime::local($deal->ends_at)?->locale($locale)->translatedFormat('d M Y, H:i'),
        ], $locale)));
        $descriptions = [$text('en', $deal->tagline_en), $text('ar', $deal->tagline_ar)];
        $title = $en.' | '.$ar.' · '.self::SUFFIX;
        $title = mb_strlen($title) <= 70 ? $title : (mb_strlen($en.' | '.$ar) <= 70 ? $en.' | '.$ar : $this->defaultTitle($en));
        $image = ($deal->design['image_path'] ?? null) ? $this->storageUrl((string) $deal->design['image_path']) : null;

        return $this->result([
            'title' => $title, 'description' => implode(' ', $descriptions), 'canonical' => $url, 'image' => $image,
            'jsonld' => [$this->collection($url, [$en, $ar], $descriptions), $this->breadcrumbs([['Home', $base.'/'], [__('deals.seo_list_title', [], 'en'), $base.'/deals'], [$en, $url]])],
        ]);
    }

    /**
     * A schema.org CollectionPage whose name and description carry both languages.
     *
     * @param  array{0: string, 1: string}  $names  English, Arabic
     * @param  array{0: string, 1: string}  $descriptions  English, Arabic
     * @return array<string, mixed>
     */
    private function collection(string $url, array $names, array $descriptions): array
    {
        $both = fn (array $v) => [['@language' => 'en', '@value' => $v[0]], ['@language' => 'ar', '@value' => $v[1]]];

        return ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $both($names), 'description' => $both($descriptions), 'url' => $url, 'inLanguage' => ['en', 'ar']];
    }

    private function page(string $slug): array
    {
        $page = CmsPage::with('translations')->where('slug', $slug)->where('status', 'published')->first();
        $t = $page?->translations->firstWhere('locale', 'en');
        if ($page === null || $t === null) {
            return $this->result(['status' => 404, 'title' => 'Not found · '.self::SUFFIX, 'robots' => 'noindex,follow']);
        }
        $url = $this->base().'/pages/'.$page->slug;

        return $this->result([
            'title' => (string) ($t->seo_title ?: $t->title.' · '.self::SUFFIX), 'description' => ($t->seo_description ?: $this->excerpt((string) $t->body)) ?: null, 'canonical' => $url,
            'jsonld' => [$this->breadcrumbs([['Home', $this->base().'/'], [(string) $t->title, $url]])],
        ]);
    }

    /** "Name · Al Bahar Home & Living", or "Name · Al Bahar" when that would run past what a search result shows (about 60 characters). */
    private function defaultTitle(string $name): string
    {
        $title = $name.' · '.self::SUFFIX;

        return mb_strlen($title) > 60 ? $name.' · Al Bahar' : $title;
    }

    /** @param list<array{0: string, 1: string}> $items */
    private function breadcrumbs(array $items): array
    {
        return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_map(
            fn (array $c, int $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1]], $items, array_keys($items))];
    }

    /** Plain text of some HTML, cut at a word near 155 characters. */
    private function excerpt(string $html): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), 155, '…', preserveWords: true);
    }

    private function storageUrl(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : Storage::disk(config('platform.media.disk'))->url($path);
    }
}
