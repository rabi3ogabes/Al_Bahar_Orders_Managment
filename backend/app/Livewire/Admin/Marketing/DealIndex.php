<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Catalog\Models\Product;
use App\Domain\Deals\Models\Deal;
use App\Domain\Deals\Models\DealProduct;
use App\Domain\Deals\Models\DealRedemption;
use App\Domain\Deals\Services\DealService;
use App\Domain\Deals\Support\DealDesign;
use App\Domain\Deals\Support\DealPricing;
use App\Domain\Pricing\Services\PriceResolver;
use App\Livewire\Admin\Concerns\StoresCatalogMedia;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use App\Support\DomainException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Marketing > Deals: countdown offers with a limited quantity (docs/api/DEALS.md section 7, docs/guides/DEALS.md).
 *
 * The list shows every deal with its phase, a live countdown and what has been sold; pause / resume save at once, "End now" and Delete ask first.
 * The editor opens in a side window with six parts (basics, products, price rule, limits, look with a live preview, show on home and active).
 * All the rules live in DealService; this screen only collects the input and shows the answer in plain words.
 */
class DealIndex extends Component
{
    use StoresCatalogMedia, WithAdminTable, WithFileUploads;

    /** The filters of the list. */
    public const FILTERS = ['all', 'live', 'scheduled', 'paused', 'ended'];

    #[Url(except: 'all')]
    public string $filterState = 'all';

    /** `?edit=<id>` keeps an open editor in the address (reload and sharing work, Back closes it). */
    #[Url(except: '')]
    public string $edit = '';

    public bool $showEditor = false;

    #[Locked]
    public ?string $editingId = null;

    /** @var array<string, mixed> */
    public array $form = [];

    /** @var array<string, mixed> the look (DealDesign) */
    public array $design = [];

    /** @var list<array<string, mixed>> the products of the deal with their own price, quantity and limits */
    public array $products = [];

    public string $productSearch = '';

    /** A picture chosen for the widget background (stored when the deal is saved). */
    public $image = null;

    /** A fingerprint of the editor as it was opened: Save stays off until something differs. */
    public string $baseline = '';

    #[Locked]
    public ?string $confirmingEnd = null;

    #[Locked]
    public ?string $confirmingDelete = null;

    /** The two decisions ("End now", "Delete") open as centred windows. */
    public bool $showEnd = false;

    public bool $showDelete = false;

    public function mount(): void
    {
        abort_unless($this->can('marketing.view'), 403);
        if (! in_array($this->filterState, self::FILTERS, true)) {
            $this->filterState = 'all';
        }
        if ($this->edit !== '' && Str::isUuid($this->edit) && $this->can('marketing.edit')) {
            $this->openEditor($this->edit);
        } else {
            $this->edit = '';
        }
    }

    public function updatedShowEditor(bool $open): void
    {
        if (! $open) {
            $this->edit = '';
            $this->image = null;
        }
    }

    // ─── Editor ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function create(): void
    {
        abort_unless($this->can('marketing.create'), 403);
        $this->editingId = null;
        $this->form = ['name_en' => '', 'name_ar' => '', 'tagline_en' => '', 'tagline_ar' => '', 'starts_at' => '', 'ends_at' => '', 'discount_type' => Deal::PERCENT, 'discount_value' => '30',
            'limit_per_order' => '', 'limit_per_customer' => '', 'show_on_home' => true, 'is_active' => true];
        $this->design = DealDesign::defaults();
        $this->products = [];
        $this->productSearch = '';
        $this->image = null;
        $this->resetValidation();
        $this->baseline = $this->fingerprint();
        $this->edit = '';
        $this->showEditor = true;
    }

    public function editDeal(string $id): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $this->openEditor($id);
        $this->edit = $this->editingId ?? '';
    }

    private function openEditor(string $id): void
    {
        $deal = Deal::with(['products.product.translations'])->find($id);
        if ($deal === null) {
            $this->edit = '';

            return;
        }
        $this->editingId = $deal->id;
        $this->form = ['name_en' => $deal->name_en, 'name_ar' => $deal->name_ar, 'tagline_en' => (string) $deal->tagline_en, 'tagline_ar' => (string) $deal->tagline_ar,
            'starts_at' => self::local($deal->starts_at), 'ends_at' => self::local($deal->ends_at), 'discount_type' => $deal->discount_type,
            'discount_value' => DealPricing::plain($deal->discount_value), 'limit_per_order' => (string) ($deal->limit_per_order ?? ''), 'limit_per_customer' => (string) ($deal->limit_per_customer ?? ''),
            'show_on_home' => $deal->show_on_home, 'is_active' => $deal->is_active];
        try {
            $this->design = DealDesign::validate($deal->design);
        } catch (ValidationException) {
            $this->design = DealDesign::defaults();
        }
        $this->products = $deal->products->map(fn (DealProduct $p) => [
            'product_id' => $p->product_id, 'name' => (string) ($p->product?->trans('name') ?: $p->product?->sku), 'sku' => (string) $p->product?->sku,
            'deal_price' => $p->deal_price === null ? '' : (string) $p->deal_price, 'quantity_total' => (string) ($p->quantity_total ?? ''), 'limit_per_order' => (string) ($p->limit_per_order ?? ''),
            'limit_per_customer' => (string) ($p->limit_per_customer ?? ''), 'sold' => $p->quantity_sold,
        ])->all();
        $this->productSearch = '';
        $this->image = null;
        $this->resetValidation();
        $this->baseline = $this->fingerprint();
        $this->showEditor = true;
    }

    /** The end time as a quick choice: counted from the start (when it is set and still ahead) or from now, in the business time zone. */
    public function setEnd(string $preset): void
    {
        $from = ($this->form['starts_at'] ?? '') !== '' ? CarbonImmutable::parse($this->form['starts_at'], BusinessTime::zone()) : BusinessTime::now();
        $from = $from->greaterThan(BusinessTime::now()) ? $from : BusinessTime::now();
        $end = match ($preset) {
            '1h' => $from->addHour(), '6h' => $from->addHours(6), '24h' => $from->addDay(), '3d' => $from->addDays(3),
            'today' => $from->endOfDay()->startOfMinute(),
            default => null,
        };
        if ($end !== null) {
            $this->form['ends_at'] = $end->format('Y-m-d\TH:i');
            $this->resetErrorBag('form.ends_at');
        }
    }

    public function addProduct(string $id): void
    {
        abort_unless($this->can('marketing.edit') || $this->can('marketing.create'), 403);
        if (! Str::isUuid($id) || collect($this->products)->contains('product_id', $id)) {
            $this->productSearch = '';

            return;
        }
        if (count($this->products) >= DealService::MAX_PRODUCTS) {
            $this->toast(__('deals_admin.max_products', ['n' => DealService::MAX_PRODUCTS]), 'warning');

            return;
        }
        $p = Product::with('translations')->where('status', '!=', 'archived')->find($id);
        if ($p !== null) {
            $this->products[] = ['product_id' => $p->id, 'name' => (string) ($p->trans('name') ?: $p->sku), 'sku' => (string) $p->sku, 'deal_price' => '',
                'quantity_total' => '', 'limit_per_order' => '', 'limit_per_customer' => '', 'sold' => 0];
            $this->resetErrorBag('products');
        }
        $this->productSearch = '';
    }

    public function removeProduct(int $index): void
    {
        if (isset($this->products[$index])) {
            unset($this->products[$index]);
            $this->products = array_values($this->products);
        }
    }

    public function removeImage(): void
    {
        $this->image = null;
        $this->design['image_path'] = null;
    }

    public function save(DealService $deals): void
    {
        abort_unless($this->can($this->editingId ? 'marketing.edit' : 'marketing.create'), 403);
        $this->resetValidation();
        $this->validate(['image' => self::imageRule(4096)]);
        $stored = null;
        $design = $this->design;
        if ($this->image) {
            $stored = $this->storeMedia($this->image, 'deals');
            $design['image_path'] = $stored;
        }

        try {
            $deal = $deals->save(auth()->user(), $this->editingId ? Deal::findOrFail($this->editingId) : null, $this->payload($design));
        } catch (ValidationException $e) {
            $this->deleteMedia($stored);
            foreach ($e->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError(str_starts_with($key, 'products') || str_starts_with($key, 'design') ? $key : 'form.'.$key, $message);
                }
            }

            return;
        } catch (DomainException $e) {
            $this->deleteMedia($stored);
            $this->addError($e->errorCode === 'deal_product_overlap' ? 'products' : 'form.ends_at', $e->getMessage());

            return;
        }
        $this->showEditor = false;
        $this->edit = '';
        $this->image = null;
        $this->toast($this->editingId ? __('deals_admin.saved') : __('deals_admin.created', ['name' => $deal->localized('name')]));
    }

    // ─── Row actions ────────────────────────────────────────────────────────────────────────────────────────────────

    /** Pause or resume: saved at once. A resume that clashes with another running deal says which one. */
    public function toggle(string $id, DealService $deals): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $deal = Deal::findOrFail($id);
        $this->attempt(function () use ($deals, $deal) {
            $now = $deals->setActive(auth()->user(), $deal, ! $deal->is_active);
            $this->toast($now->is_active ? __('deals_admin.resumed') : __('deals_admin.paused'));
        });
    }

    public function askEnd(string $id): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $this->confirmingEnd = Deal::findOrFail($id)->id;
        $this->showEnd = true;
    }

    public function endNow(DealService $deals): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $deal = $this->confirmingEnd ? Deal::find($this->confirmingEnd) : null;
        $this->confirmingEnd = null;
        $this->showEnd = false;
        if ($deal !== null) {
            $this->attempt(fn () => $deals->end(auth()->user(), $deal), __('deals_admin.ended'));
        }
    }

    public function duplicate(string $id, DealService $deals): void
    {
        abort_unless($this->can('marketing.create'), 403);
        $copy = $this->attempt(fn () => $deals->duplicate(auth()->user(), Deal::findOrFail($id)));
        if ($copy instanceof Deal) {
            $this->toast(__('deals_admin.duplicated'));
            $this->editDeal($copy->id);
        }
    }

    public function askDelete(string $id): void
    {
        abort_unless($this->can('marketing.delete'), 403);
        $this->confirmingDelete = Deal::findOrFail($id)->id;
        $this->showDelete = true;
    }

    public function deleteDeal(DealService $deals): void
    {
        abort_unless($this->can('marketing.delete'), 403);
        $deal = $this->confirmingDelete ? Deal::find($this->confirmingDelete) : null;
        $this->confirmingDelete = null;
        $this->showDelete = false;
        if ($deal !== null) {
            $this->attempt(fn () => $deals->delete(auth()->user(), $deal), __('deals_admin.deleted'));
        }
    }

    // ─── Render ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function render(PriceResolver $prices)
    {
        $s = trim($this->search);
        $now = now();
        $deals = Deal::query()
            ->addSelect(['orders_count' => DealRedemption::query()->selectRaw('count(distinct order_id)')->whereColumn('deal_id', 'deals.id')->whereNull('released_at'),
                'savings_given' => DealRedemption::query()->selectRaw('coalesce(sum(units * unit_savings), 0)')->whereColumn('deal_id', 'deals.id')->whereNull('released_at'),
                'any_orders' => DealRedemption::query()->selectRaw('count(*)')->whereColumn('deal_id', 'deals.id')])
            ->with(['products' => fn ($q) => $q->select('id', 'deal_id', 'product_id', 'quantity_total', 'quantity_sold', 'limit_per_order', 'limit_per_customer')->with('product:id,status')])->withCount('products')
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('name_en', 'ilike', "%{$s}%")->orWhere('name_ar', 'ilike', "%{$s}%")))
            ->when($this->filterState === 'live', fn ($q) => $q->running($now))
            ->when($this->filterState === 'scheduled', fn ($q) => $q->open($now)->where('starts_at', '>', $now))
            ->when($this->filterState === 'paused', fn ($q) => $q->where('is_active', false)->whereNull('ended_at')->where('ends_at', '>', $now))
            ->when($this->filterState === 'ended', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('ended_at')->orWhere('ends_at', '<=', $now)))
            ->orderByRaw('case when ended_at is not null or ends_at <= ? then 1 else 0 end', [$now])->orderBy('ends_at')->orderBy('id')
            ->paginate($this->perPage);

        return view('livewire.admin.marketing.deals', [
            'rows' => $deals,
            'now' => $now,
            'canCreate' => $this->can('marketing.create'), 'canEdit' => $this->can('marketing.edit'), 'canDelete' => $this->can('marketing.delete'),
            'editor' => $this->showEditor ? $this->editorData($prices) : null,
        ])->title(__('nav.deals'));
    }

    /**
     * Everything the editor window needs while it is open: the product search, the "customer pays" example, the resolved look for the preview.
     *
     * @return array<string, mixed>
     */
    private function editorData(PriceResolver $prices): array
    {
        $term = trim($this->productSearch);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $picked = collect($this->products)->pluck('product_id')->filter(fn ($id) => is_string($id) && Str::isUuid($id))->all();
        $matches = mb_strlen($term) >= 2
            ? Product::query()->with('translations')->where('status', '!=', 'archived')->whereNotIn('id', $picked)
                ->where(fn ($w) => $w->where('sku', 'ilike', $like)->orWhereHas('translations', fn ($t) => $t->where('name', 'ilike', $like))->orWhereHas('variants', fn ($v) => $v->where('sku', 'ilike', $like)))
                ->orderBy('sku')->limit(8)->get()
            : collect();

        $brand = DealDesign::brandPrimary();
        $palettes = ['brand' => DealDesign::colors(['palette' => 'brand'], $brand)] + DealDesign::PRESETS + ['custom' => $this->customColors()];
        // The preview only ever draws a VALID look (it is printed into the page): while a custom colour is half typed it shows the brand colours instead.
        try {
            $resolved = DealDesign::validate($this->design);
        } catch (ValidationException) {
            try {
                $resolved = DealDesign::validate(['palette' => 'brand'] + $this->design);
            } catch (ValidationException) {
                $resolved = DealDesign::validate(null);
            }
        }
        $colors = $palettes[$resolved['palette'] ?? 'brand'] ?? $palettes['brand'];
        $path = $resolved['image_path'] ?? null;
        $image = $this->image instanceof TemporaryUploadedFile && $this->image->isPreviewable() ? $this->image->temporaryUrl() : self::mediaUrl($path);

        return [
            'matches' => $matches, 'palettes' => $palettes, 'colors' => $colors, 'look' => $resolved, 'image_url' => $image, 'no_limit_rows' => $this->rowsWithoutBuyerLimit(),
            // The warning follows what is typed, not the safe look the preview falls back to while a custom colour is unreadable.
            'example' => $this->example($prices), 'warning' => DealDesign::contrastWarning(['palette' => in_array($this->design['palette'] ?? null, DealDesign::PALETTES, true) ? $this->design['palette'] : 'brand', 'colors' => $this->customColors()]),
            'dirty' => $this->fingerprint() !== $this->baseline,
        ];
    }

    /**
     * Rows that have a quantity but nothing limits how much one customer can take (no limit per order or per account on the product or the deal): the editor warns,
     * because one account could take every unit, cancel and do it again.
     *
     * @return list<int>
     */
    private function rowsWithoutBuyerLimit(): array
    {
        $dealLimit = filled($this->form['limit_per_order'] ?? null) || filled($this->form['limit_per_customer'] ?? null);

        return array_keys(array_filter($this->products, fn (array $r) => filled($r['quantity_total'] ?? null) && ! $dealLimit && blank($r['limit_per_order'] ?? null) && blank($r['limit_per_customer'] ?? null)));
    }

    /** The first product's price and what it costs at the deal price, from the rule as typed so far ("customer pays"). @return array<string, mixed>|null */
    private function example(PriceResolver $prices): ?array
    {
        $first = $this->products[0] ?? null;
        $product = $first ? Product::with(['variants' => fn ($v) => $v->where('status', 'active')])->find($first['product_id']) : null;
        $variant = $product?->variants->sortBy('price')->first();
        if ($product === null || $variant === null) {
            return null;
        }
        $variant->setRelation('product', $product);
        $normal = $prices->price($variant, null)->final();
        $type = (string) ($this->form['discount_type'] ?? Deal::PERCENT);
        $value = is_numeric($this->form['discount_value'] ?? null) ? (string) $this->form['discount_value'] : '0';
        $deal = new Deal(['discount_type' => in_array($type, Deal::DISCOUNT_TYPES, true) ? $type : Deal::PERCENT, 'discount_value' => $type === Deal::FIXED_PRICE ? '0' : $value]);
        $own = $first['deal_price'] ?? '';
        $row = new DealProduct(['deal_price' => is_numeric($own) && (float) $own > 0 ? Money::of((string) $own)->toDecimal() : null]);
        try {
            $price = DealPricing::unitPrice($deal, $row, $normal);
        } catch (\Throwable) {
            $price = null;
        }

        return ['name' => $first['name'], 'normal' => $normal, 'deal' => $price, 'percent' => $price ? DealPricing::percentOff($normal, $price) : 0];
    }

    /** @return array{background: string, text: string, accent: string} */
    private function customColors(): array
    {
        $c = $this->design['colors'] ?? [];
        $ok = fn ($v, $fallback) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : $fallback;
        $d = DealDesign::defaults()['colors'];

        return ['background' => $ok($c['background'] ?? null, $d['background']), 'text' => $ok($c['text'] ?? null, $d['text']), 'accent' => $ok($c['accent'] ?? null, $d['accent'])];
    }

    /** @return array<string, mixed> what DealService::save() takes, from the editor as it is now */
    private function payload(array $design): array
    {
        $f = $this->form;

        return [
            'name_en' => $f['name_en'] ?? '', 'name_ar' => $f['name_ar'] ?? '', 'tagline_en' => $f['tagline_en'] ?? null, 'tagline_ar' => $f['tagline_ar'] ?? null,
            'is_active' => (bool) ($f['is_active'] ?? false), 'show_on_home' => (bool) ($f['show_on_home'] ?? false),
            'starts_at' => ($f['starts_at'] ?? '') !== '' ? $f['starts_at'] : null, 'ends_at' => $f['ends_at'] ?? null,
            'discount_type' => $f['discount_type'] ?? null, 'discount_value' => ($f['discount_type'] ?? '') === Deal::FIXED_PRICE ? 0 : ($f['discount_value'] ?? null),
            'limit_per_order' => $f['limit_per_order'] ?? null, 'limit_per_customer' => $f['limit_per_customer'] ?? null,
            'design' => $design,
            'products' => array_map(fn (array $r) => ['product_id' => $r['product_id'] ?? null, 'deal_price' => $r['deal_price'] ?? null, 'quantity_total' => $r['quantity_total'] ?? null,
                'limit_per_order' => $r['limit_per_order'] ?? null, 'limit_per_customer' => $r['limit_per_customer'] ?? null], $this->products),
        ];
    }

    private function fingerprint(): string
    {
        return md5(json_encode([$this->form, $this->design, array_map(fn (array $r) => array_diff_key($r, ['name' => 1, 'sku' => 1, 'sold' => 1]), $this->products), $this->image ? 'new-image' : '']));
    }

    private static function local(mixed $v): string
    {
        return $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
    }
}
