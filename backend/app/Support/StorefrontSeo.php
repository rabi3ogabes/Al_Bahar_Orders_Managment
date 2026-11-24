<?php

namespace App\Support;

use App\Domain\Auctions\Models\Auction;
use App\Domain\Auctions\Models\AuctionLot;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Room;
use App\Domain\Catalog\Services\CatalogPresenter;
use App\Domain\Catalog\Services\RoomService;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Support\DealPricing;
use Illuminate\Support\Collection;
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

    /** The language being built: 'en', or 'ar' for `?lang=ar` addresses. */
    private string $lang = 'en';

    private const PRIVATE = '#^(cart|checkout|login|register|account|orders|payments|partner)(/|$)#';

    public function __construct(private readonly CatalogPresenter $presenter) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{status: int, title: ?string, description: ?string, canonical: ?string, robots: ?string, type: string, image: ?string, jsonld: list<array<string, mixed>>, product: ?array<string, string>, locale: string, alternates: ?array<string, string>, content: ?string}
     */
    public function for(string $path, array $query = [], string $locale = 'en'): array
    {
        $path = trim($path, '/');
        $locale = $locale === 'ar' ? 'ar' : 'en';
        $key = 'storefront:seo:'.md5($path.'|'.$locale.'|'.json_encode(array_intersect_key($query, array_flip(['category', 'room', 'q', 'brand', 'min', 'max', 'sale', 'new', 'stock']))));

        // Deals are never remembered: a deal ends, sells out or is paused at any moment, and its page must say noindex the moment it does.
        $remember = $path !== 'deals' && ! str_starts_with($path, 'deals/');
        $cached = $remember ? Cache::get($key) : null;
        if (is_array($cached)) {
            return $cached;
        }
        $meta = $this->build($path, $query, $locale);
        if ($remember && $meta['status'] === 200) { // a missing product may be created a minute later: only found pages are remembered
            Cache::put($key, $meta, (int) config('platform.seo.cache_seconds', 300));
        }

        return $meta;
    }

    public function base(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** @return array{status: int, title: ?string, description: ?string, canonical: ?string, robots: ?string, type: string, image: ?string, jsonld: list<array<string, mixed>>, product: ?array<string, string>, locale: string, alternates: ?array<string, string>, content: ?string} */
    private function build(string $path, array $query, string $locale): array
    {
        $previous = App::getLocale();
        App::setLocale($locale);
        $this->lang = $locale;
        try {
            $meta = match (true) {
                $path === '' => $this->home(),
                $path === 'shop' => $this->shop($query),
                (bool) preg_match('#^products/([^/]+)$#', $path, $m) => $this->product(rawurldecode($m[1])),
                (bool) preg_match('#^pages/([^/]+)$#', $path, $m) => $this->page(rawurldecode($m[1])),
                $path === 'showrooms' => $this->plain(__('storefront_seo.showrooms'), __('storefront_seo.showrooms_description'), '/showrooms'),
                $path === 'deals' => $this->deals(),
                (bool) preg_match('#^deals/([^/]+)$#', $path, $m) => $this->deal(rawurldecode($m[1])),
                $path === 'auctions' => $this->plain(__('storefront_seo.auctions'), __('storefront_seo.auctions_description'), '/auctions'),
                (bool) preg_match(self::PRIVATE, $path) => $this->hidden(),
                (bool) preg_match('#^auctions/(?:lots/)?([^/]+)$#', $path, $m) => $this->auction($path, rawurldecode($m[1]), str_contains($path, '/lots/')),
                default => $this->notFound(),
            };
        } finally {
            App::setLocale($previous);
            $this->lang = 'en';
        }
        $meta['locale'] = $locale;
        $indexable = $meta['status'] === 200 && $meta['canonical'] !== null && ! str_contains((string) $meta['robots'], 'noindex') && $path !== 'deals' && ! str_starts_with($path, 'deals/');
        $meta['alternates'] = $indexable ? $this->alternates($meta['canonical']) : null;

        return $meta;
    }

    /** The two language addresses of a page (the Arabic one is the same address with `lang=ar`) plus the default, for hreflang links. */
    private function alternates(string $canonical): array
    {
        $plain = $this->withoutLang($canonical);

        return ['en' => $plain, 'ar' => $this->withLang($plain, 'ar'), 'x-default' => $plain];
    }

    private function withoutLang(string $url): string
    {
        return (string) preg_replace('/[?&]lang=ar$/', '', $url);
    }

    private function withLang(string $url, string $lang): string
    {
        return $lang === 'ar' ? $url.(str_contains($url, '?') ? '&' : '?').'lang=ar' : $url;
    }

    /** An address on this site in the language being built (Arabic addresses carry `lang=ar`). */
    private function url(string $path): string
    {
        return $this->withLang($this->base().$path, $this->lang);
    }

    private function site(): string
    {
        return __('storefront_seo.suffix');
    }

    private function notFound(): array
    {
        return $this->result(['status' => 404, 'title' => __('storefront_seo.not_found').' · '.$this->site(), 'robots' => 'noindex,follow',
            'content' => $this->content(__('storefront_seo.not_found'), '<p>'.e(__('storefront_seo.not_found_text')).'</p>')]);
    }

    /** /auctions/{id} and /auctions/lots/{id}: an auction (or lot) that is no public record answers 404; otherwise the page keeps the app's own title. */
    private function auction(string $path, string $id, bool $lot): array
    {
        $found = Str::isUuid($id) && ($lot
            ? AuctionLot::whereKey($id)->whereHas('auction', fn ($q) => $q->where('status', '!=', 'draft'))->exists()
            : Auction::whereKey($id)->where('status', '!=', 'draft')->exists());
        if (! $found) {
            return $this->notFound();
        }

        return $this->plain(null, null, '/'.$path);
    }

    /** @return array<string, mixed> */
    private function result(array $over = []): array
    {
        return $over + ['status' => 200, 'title' => null, 'description' => null, 'canonical' => null, 'robots' => null, 'type' => 'website', 'image' => null, 'jsonld' => [], 'product' => null, 'locale' => 'en', 'alternates' => null, 'content' => null];
    }

    private function home(): array
    {
        $base = $this->base();
        $logo = $base.'/store/icons/icon-512.png';
        $branch = Branch::active()->orderBy('created_at')->orderBy('id')->first(['phone', 'email']);
        $contact = $branch && ($branch->phone || $branch->email)
            ? ['@type' => 'ContactPoint', 'contactType' => 'customer service', 'telephone' => $branch->phone ?: null, 'email' => $branch->email ?: null, 'availableLanguage' => ['en', 'ar']] : null;
        $categories = Category::active()->with('translations')->whereNull('parent_id')->orderBy('sort_order')->orderBy('id')->limit(12)->get();
        $featured = Product::active()->with(['translations', 'brand', 'images', 'variants' => fn ($q) => $q->where('status', 'active')])->orderByDesc('created_at')->orderBy('id')->limit(12)->get();

        return $this->result([
            'title' => __('storefront_seo.home_title'), 'description' => __('storefront_seo.home_description'), 'canonical' => $this->url('/'),
            'content' => $this->content(__('storefront_seo.suffix'), '<p>'.e(__('storefront_seo.home_description')).'</p>'
                .$this->categoryLinks($categories).$this->productList(__('storefront_seo.featured'), $featured)),
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'Organization', 'name' => $this->site(), 'url' => $this->url('/'), 'logo' => $logo, 'contactPoint' => $contact],
                ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $this->site(), 'url' => $this->url('/'), 'inLanguage' => ['en', 'ar'],
                    'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $base.'/shop?q={search_term_string}'], 'query-input' => 'required name=search_term_string']],
            ],
        ]);
    }

    private function plain(?string $title, ?string $description, string $path): array
    {
        return $this->result(['title' => $title ? $title.' · '.$this->site() : null, 'description' => $description, 'canonical' => $this->url($path),
            'content' => $title ? $this->content($title, '<p>'.e((string) $description).'</p>') : null]);
    }

    private function hidden(): array
    {
        return $this->result(['robots' => 'noindex,nofollow']);
    }

    private function shop(array $query): array
    {
        $slug = is_string($query['category'] ?? null) ? $query['category'] : null;
        $category = $slug ? Category::active()->with('translations')->where('slug', $slug)->first() : null;
        $searching = is_string($query['q'] ?? null) && $query['q'] !== '';
        $room = $category === null && is_string($query['room'] ?? null) && $query['room'] !== '' ? app(RoomService::class)->find($query['room']) : null;

        if ($room !== null) {
            return $this->roomPage($room, $searching);
        }
        if ($category === null) {
            $categories = Category::active()->with('translations')->whereNull('parent_id')->orderBy('sort_order')->orderBy('id')->limit(30)->get();

            return $this->result([
                'title' => __('storefront_seo.shop').' · '.$this->site(), 'description' => __('storefront_seo.shop_description'),
                'canonical' => $this->url('/shop'), 'robots' => $searching ? 'noindex,follow' : null,
                'content' => $this->content(__('storefront_seo.shop'), '<p>'.e(__('storefront_seo.shop_description')).'</p>'.$this->categoryLinks($categories)),
                'jsonld' => [$this->breadcrumbs([[__('storefront_seo.home'), $this->url('/')], [__('storefront_seo.shop'), $this->url('/shop')]])],
            ]);
        }
        $url = $this->url('/shop?category='.rawurlencode($category->slug));
        $name = (string) $category->trans('name');
        $image = $category->image_path ? $this->storageUrl($category->image_path) : null;
        $description = $this->excerpt((string) $category->trans('description')) ?: __('storefront_seo.category_description', ['name' => $name, 'site' => $this->site()]);
        $products = Product::active()->with(['translations', 'brand', 'images', 'variants' => fn ($q) => $q->where('status', 'active')])
            ->whereIn('category_id', $category->descendantAndSelfIds())->orderBy('created_at', 'desc')->orderBy('id')->limit(24)->get();

        return $this->result([
            'title' => $name.' · '.$this->site(), 'description' => $description,
            'canonical' => $url, 'robots' => $searching ? 'noindex,follow' : null, 'image' => $image,
            'content' => $this->content($name, '<p>'.e($description).'</p>'.$this->productList(__('storefront_seo.products'), $products)),
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $name, 'url' => $url, 'description' => $this->excerpt((string) $category->trans('description')) ?: null, 'inLanguage' => $this->lang],
                $this->breadcrumbs([[__('storefront_seo.home'), $this->url('/')], [__('storefront_seo.shop'), $this->url('/shop')], [$name, $url]]),
            ],
        ]);
    }

    /** `/shop?room=bedroom`: the page of a room, with its own title, description and picture (the room's texts are the owner's; nothing is invented). */
    private function roomPage(Room $room, bool $searching): array
    {
        $url = $this->url('/shop?room='.rawurlencode($room->slug));
        $name = $room->title($this->lang);
        $description = $this->excerpt((string) $room->description($this->lang)) ?: __('storefront_seo.room_description', ['name' => mb_strtolower($name), 'site' => $this->site()]);
        $path = $room->image_path ?: $room->categories()->whereNotNull('image_path')->value('image_path');

        return $this->result([
            'title' => $name.' · '.$this->site(), 'description' => $description, 'canonical' => $url, 'robots' => $searching ? 'noindex,follow' : null, 'image' => $path ? $this->storageUrl($path) : null,
            'content' => $this->content($name, '<p>'.e($description).'</p>'),
            'jsonld' => [
                ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $name, 'url' => $url, 'description' => $description, 'inLanguage' => $this->lang],
                $this->breadcrumbs([[__('storefront_seo.home'), $this->url('/')], [__('storefront_seo.shop'), $this->url('/shop')], [$name, $url]]),
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
            return $this->notFound();
        }
        $card = $this->presenter->cards(collect([$product]), null)[0];
        $url = $this->url('/products/'.$product->trans('slug', 'en'));
        $name = (string) $product->trans('name');
        $description = $this->excerpt((string) ($product->trans('seo_description') ?: $product->trans('short_description') ?: $product->trans('description')));
        $images = $product->images->map(fn ($i) => $i->url())->values()->all();
        $final = $card['price']['final'] ?? null;
        $category = $product->category;
        $inStock = (bool) $card['in_stock'];

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
                'availability' => 'https://schema.org/'.($inStock ? 'InStock' : 'OutOfStock'), 'seller' => ['@type' => 'Organization', 'name' => $this->site()]];
        }
        if ((int) $card['rating']['count'] > 0) {
            $ld['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => (string) $card['rating']['average'], 'reviewCount' => (int) $card['rating']['count']];
        }
        $crumbs = [[__('storefront_seo.home'), $this->url('/')], [__('storefront_seo.shop'), $this->url('/shop')]];
        if ($category) {
            $crumbs[] = [(string) $category->trans('name'), $this->url('/shop?category='.rawurlencode($category->slug))];
        }
        $crumbs[] = [$name, $url];

        $facts = [];
        if ($final !== null) {
            $facts[__('storefront_seo.price')] = (string) ($final['formatted'] ?? $final['amount'].' '.$final['currency']);
            $facts[__('storefront_seo.availability')] = $inStock ? __('storefront_seo.in_stock') : __('storefront_seo.out_of_stock');
        }
        if ($product->brand) {
            $facts[__('storefront_seo.brand')] = (string) $product->brand->localized('name');
        }
        if ($product->sku) {
            $facts[__('storefront_seo.sku')] = (string) $product->sku;
        }
        $body = ($images !== [] ? '<p><img src="'.e($images[0]).'" alt="'.e($name).'" /></p>' : '').($description !== '' ? '<p>'.e($description).'</p>' : '').$this->facts($facts);
        $full = trim((string) $product->trans('description'));
        if ($full !== '') {
            $body .= $this->safeHtml($full);
        }

        return $this->result([
            'title' => (string) ($product->trans('seo_title') ?: $this->defaultTitle($name)), 'description' => $description ?: null, 'canonical' => $url,
            'type' => 'product', 'image' => $images[0] ?? null, 'jsonld' => [$ld, $this->breadcrumbs($crumbs)], 'content' => $this->content($name, $body),
            'product' => $final ? ['amount' => (string) $final['amount'], 'currency' => (string) $final['currency'], 'availability' => $inStock ? 'in stock' : 'out of stock'] : null,
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
            return $this->notFound();
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
        $t = $page === null ? null : ($page->translations->firstWhere('locale', $this->lang) ?? $page->translations->firstWhere('locale', 'en'));
        if ($page === null || $t === null) {
            return $this->notFound();
        }
        $url = $this->url('/pages/'.$page->slug);
        $description = ($t->seo_description ?: $this->excerpt((string) $t->body)) ?: null;
        $updated = $page->updated_at?->toIso8601String();

        return $this->result([
            'title' => (string) ($t->seo_title ?: $t->title.' · '.$this->site()), 'description' => $description, 'canonical' => $url,
            'content' => $this->content((string) $t->title, $this->safeHtml((string) $t->body).($page->updated_at ? '<p>'.e(__('storefront_seo.updated', ['date' => $page->updated_at->format('Y-m-d')])).'</p>' : '')),
            'jsonld' => [['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => (string) $t->title, 'url' => $url, 'description' => $description, 'inLanguage' => $this->lang, 'dateModified' => $updated],
                $this->breadcrumbs([[__('storefront_seo.home'), $this->url('/')], [(string) $t->title, $url]])],
        ]);
    }

    /** The plain-HTML copy of a page that is written into the shell (inside <noscript>): readers that do not run JavaScript still get the page's real content. */
    private function content(string $h1, string $body): string
    {
        return '<main><h1>'.e($h1).'</h1>'.$body.'<p><small>'.e(__('storefront_seo.javascript_note')).'</small></p></main>';
    }

    /** @param Collection<int, Category>|\Illuminate\Database\Eloquent\Collection<int, Category> $categories */
    private function categoryLinks($categories): string
    {
        if ($categories->isEmpty()) {
            return '';
        }

        return '<h2>'.e(__('storefront_seo.categories')).'</h2><ul>'.$categories->map(fn (Category $c) => '<li><a href="'
            .e($this->url('/shop?category='.rawurlencode($c->slug))).'">'.e((string) $c->trans('name')).'</a></li>')->implode('').'</ul>';
    }

    /** @param Collection<int, Product>|\Illuminate\Database\Eloquent\Collection<int, Product> $products */
    private function productList(string $heading, $products): string
    {
        if ($products->isEmpty()) {
            return '';
        }
        $cards = $this->presenter->cards($products->values(), null);
        $items = [];
        foreach ($products->values() as $i => $p) {
            $price = $cards[$i]['price']['final']['formatted'] ?? null;
            $items[] = '<li><a href="'.e($this->url('/products/'.$p->trans('slug', 'en'))).'">'.e((string) $p->trans('name')).'</a>'.($price ? ' — '.e((string) $price) : '').'</li>';
        }

        return '<h2>'.e($heading).'</h2><ul>'.implode('', $items).'</ul>';
    }

    /** @param array<string, string> $facts */
    private function facts(array $facts): string
    {
        if ($facts === []) {
            return '';
        }

        return '<dl>'.implode('', array_map(fn ($k, $v) => '<dt>'.e($k).'</dt><dd>'.e($v).'</dd>', array_keys($facts), $facts)).'</dl>';
    }

    /** Admin-written HTML reduced to headings, text, lists, tables and links: no attributes except a safe link address. */
    private function safeHtml(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|noscript|template)\b.*?</\1\s*>#is', '', $html);
        $text = strip_tags($html, '<p><br><ul><ol><li><h2><h3><h4><strong><b><em><i><a><table><thead><tbody><tr><th><td>');

        return (string) preg_replace_callback('#<(/?)([a-z0-9]+)([^>]*)>#i', function (array $m) {
            if ($m[1] === '/' || strtolower($m[2]) !== 'a') {
                return '<'.$m[1].strtolower($m[2]).'>';
            }
            $href = preg_match('#href\s*=\s*(["\'])(.*?)\1#i', $m[3], $h) ? html_entity_decode($h[2]) : '';

            return preg_match('#^(https?://|/|mailto:|tel:)#i', $href) ? '<a href="'.e($href).'">' : '<a>';
        }, $text);
    }

    /** "Name · Al Bahar Home & Living", or "Name · Al Bahar" when that would run past what a search result shows (about 60 characters). */
    private function defaultTitle(string $name): string
    {
        $title = $name.' · '.$this->site();

        return mb_strlen($title) > 60 ? $name.' · '.__('storefront_seo.suffix_short') : $title;
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
