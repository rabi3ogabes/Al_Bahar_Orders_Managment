<?php

namespace App\Livewire\Admin\Marketing;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Marketing\Models\Coupon;
use App\Domain\Marketing\Models\CustomerGroup;
use App\Domain\Marketing\Models\Promotion;
use App\Livewire\Admin\Concerns\WithAdminTable;
use App\Support\BusinessTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Promotions (automatic or coupon-based, stacking/priority, windows, usage limits, scope by categories/products/branches) and
 * their coupon codes. Discount maths stays in PromotionEngine; this screen only manages the configuration.
 */
class PromotionIndex extends Component
{
    use WithAdminTable;

    public const TYPES = ['percentage', 'fixed', 'free_delivery', 'bogo', 'bundle', 'flash_sale'];

    public const SCOPES = ['order', 'categories', 'products'];

    /** A promotion can name this many products; larger ranges belong in categories. */
    public const MAX_PRODUCTS = 200;

    #[Url(except: 'promotions')]
    public string $tab = 'promotions';

    #[Url(except: '')]
    public string $filterState = '';

    public bool $showPromo = false;

    public bool $showCoupon = false;

    #[Locked]
    public ?string $editingId = null;

    public array $p = [];

    public array $categoryIds = [];

    /** Products a "specific products" promotion applies to (ids; validated on save). */
    public array $productIds = [];

    public string $productSearch = '';

    public string $skuPaste = '';

    public array $branchIds = [];

    public array $c = [];

    public function mount(): void
    {
        abort_unless($this->can('marketing.view'), 403);
    }

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    private static function local($v): string
    {
        return $v ? BusinessTime::local($v)->format('Y-m-d\TH:i') : '';
    }

    private static function instant(?string $v): ?CarbonImmutable
    {
        return $v ? CarbonImmutable::parse($v, BusinessTime::zone())->utc() : null;
    }

    public function createPromotion(): void
    {
        abort_unless($this->can('marketing.create'), 403);
        $this->editingId = null;
        $this->p = ['name_en' => '', 'name_ar' => '', 'description_en' => '', 'description_ar' => '', 'type' => 'percentage', 'value' => '', 'scope' => 'order',
            'min_basket_amount' => '', 'max_discount_amount' => '', 'buy_quantity' => '', 'get_quantity' => '', 'get_discount_percent' => '100',
            'first_order_only' => false, 'customer_group_id' => '', 'requires_coupon' => true, 'is_stackable' => false, 'priority' => 100,
            'usage_limit' => '', 'per_customer_limit' => '', 'starts_at' => '', 'ends_at' => '', 'is_active' => true, 'applies_to_auctions' => false];
        $this->categoryIds = [];
        $this->productIds = [];
        $this->productSearch = $this->skuPaste = '';
        $this->branchIds = [];
        $this->resetValidation();
        $this->showPromo = true;
    }

    public function editPromotion(string $id): void
    {
        abort_unless($this->can('marketing.edit'), 403);
        $promo = Promotion::with(['categories:id', 'branches:id', 'products:id'])->findOrFail($id);
        $this->editingId = $promo->id;
        $this->p = collect($promo->only(['name_en', 'name_ar', 'description_en', 'description_ar', 'type', 'value', 'scope', 'min_basket_amount', 'max_discount_amount',
            'buy_quantity', 'get_quantity', 'get_discount_percent', 'first_order_only', 'customer_group_id', 'requires_coupon', 'is_stackable', 'priority',
            'usage_limit', 'per_customer_limit', 'is_active', 'applies_to_auctions']))->map(fn ($v) => $v ?? '')->all()
            + ['starts_at' => self::local($promo->starts_at), 'ends_at' => self::local($promo->ends_at)];
        $this->categoryIds = $promo->categories->pluck('id')->all();
        $this->productIds = $promo->products->pluck('id')->all();
        $this->productSearch = $this->skuPaste = '';
        $this->branchIds = $promo->branches->pluck('id')->all();
        $this->resetValidation();
        $this->showPromo = true;
    }

    public function savePromotion(AuditLogger $audit): void
    {
        abort_unless($this->can($this->editingId ? 'marketing.edit' : 'marketing.create'), 403);
        $type = $this->p['type'] ?? '';
        $data = $this->validate([
            'p.name_en' => 'required|string|max:255', 'p.name_ar' => 'required|string|max:255',
            'p.description_en' => 'nullable|string|max:2000', 'p.description_ar' => 'nullable|string|max:2000',
            'p.type' => ['required', Rule::in(self::TYPES)],
            'p.value' => array_filter([$type === 'free_delivery' ? 'nullable' : 'required', 'numeric', 'min:0', in_array($type, ['percentage', 'flash_sale'], true) ? 'max:100' : 'max:1000000']),
            'p.scope' => ['required', Rule::in(self::SCOPES)],
            'p.min_basket_amount' => 'nullable|numeric|min:0', 'p.max_discount_amount' => 'nullable|numeric|min:0',
            'p.buy_quantity' => (in_array($type, ['bogo', 'bundle'], true) ? 'required' : 'nullable').'|integer|min:1|max:100',
            'p.get_quantity' => ($type === 'bogo' ? 'required' : 'nullable').'|integer|min:1|max:100',
            'p.get_discount_percent' => 'nullable|numeric|min:0|max:100',
            'p.first_order_only' => 'boolean', 'p.customer_group_id' => 'nullable|uuid|exists:customer_groups,id',
            'p.requires_coupon' => 'boolean', 'p.is_stackable' => 'boolean', 'p.priority' => 'required|integer|min:0|max:10000',
            'p.usage_limit' => 'nullable|integer|min:1', 'p.per_customer_limit' => 'nullable|integer|min:1',
            'p.starts_at' => 'nullable|date', 'p.ends_at' => 'nullable|date|after:p.starts_at',
            'p.is_active' => 'boolean', 'p.applies_to_auctions' => 'boolean',
            'categoryIds' => 'array', 'categoryIds.*' => 'uuid|exists:categories,id',
            'productIds' => 'array|max:'.self::MAX_PRODUCTS, 'productIds.*' => 'uuid|exists:products,id', 'branchIds' => 'array', 'branchIds.*' => 'uuid|exists:branches,id',
        ], [], ['p.name_en' => __('marketing_admin.name_en'), 'p.name_ar' => __('marketing_admin.name_ar'), 'p.value' => __('marketing_admin.value'), 'p.ends_at' => __('marketing_admin.ends_at'),
            'p.buy_quantity' => __('marketing_admin.buy_qty'), 'p.get_quantity' => __('marketing_admin.get_qty')]);
        $attrs = collect($data['p'])->map(fn ($v) => $v === '' ? null : $v)->all();
        $attrs['starts_at'] = self::instant($data['p']['starts_at'] ?: null);
        $attrs['ends_at'] = self::instant($data['p']['ends_at'] ?: null);
        $attrs['value'] = $type === 'free_delivery' ? ($attrs['value'] ?? 0) : $attrs['value'];
        if ($data['p']['scope'] === 'categories' && ! $data['categoryIds']) {
            $this->addError('categoryIds', __('marketing_admin.pick_categories'));

            return;
        }
        if ($data['p']['scope'] === 'products' && ! $data['productIds']) {
            $this->addError('productIds', __('marketing_admin.pick_products'));

            return;
        }
        DB::transaction(function () use ($attrs, $data, $audit) {
            $promo = $this->editingId ? Promotion::findOrFail($this->editingId) : new Promotion(['created_by' => auth()->id()]);
            $old = $promo->exists ? $promo->only(array_keys($attrs)) : [];
            $promo->fill($attrs)->save();
            $promo->categories()->sync($data['p']['scope'] === 'categories' ? $data['categoryIds'] : []);
            $promo->products()->sync($data['p']['scope'] === 'products' ? array_values(array_unique($data['productIds'])) : []);
            $promo->branches()->sync($data['branchIds']);
            $audit->log($old ? 'marketing.promotion_updated' : 'marketing.promotion_created', $promo, $old, $promo->getChanges() ?: $attrs, ['categories' => $data['categoryIds'], 'products' => $data['p']['scope'] === 'products' ? $data['productIds'] : [], 'branches' => $data['branchIds']]);
        });
        $this->showPromo = false;
        $this->toast(__('admin.saved'));
    }

    public function addProduct(string $id): void
    {
        abort_unless($this->can('marketing.edit') || $this->can('marketing.create'), 403);
        if (! Str::isUuid($id) || in_array($id, $this->productIds, true)) {
            return;
        }
        if (count($this->productIds) >= self::MAX_PRODUCTS) {
            $this->toast(__('marketing_admin.max_products', ['n' => self::MAX_PRODUCTS]), 'warning');

            return;
        }
        if (Product::whereKey($id)->where('status', '!=', 'archived')->exists()) {
            $this->productIds[] = $id;
            $this->resetErrorBag('productIds');
        }
        $this->productSearch = '';
    }

    public function removeProduct(string $id): void
    {
        $this->productIds = array_values(array_diff($this->productIds, [$id]));
    }

    /** Add many products at once from a pasted list of product or variant SKUs (one per line or comma-separated). */
    public function addBySkus(): void
    {
        abort_unless($this->can('marketing.edit') || $this->can('marketing.create'), 403);
        $skus = collect(preg_split('/[\s,;]+/', trim($this->skuPaste)))->filter()->map(fn ($v) => mb_strtoupper($v))->unique()->values();
        if ($skus->isEmpty()) {
            return;
        }
        $found = Product::query()->where('status', '!=', 'archived')
            ->where(fn ($q) => $q->whereIn(DB::raw('upper(sku)'), $skus->all())->orWhereHas('variants', fn ($v) => $v->whereIn(DB::raw('upper(sku)'), $skus->all())))
            ->with('variants:id,product_id,sku')->get(['id', 'sku']);
        $matched = $found->flatMap(fn ($p) => array_merge([mb_strtoupper($p->sku)], $p->variants->pluck('sku')->map(fn ($v) => mb_strtoupper($v))->all()));
        $missing = $skus->reject(fn ($k) => $matched->contains($k))->values();

        $before = count($this->productIds);
        $this->productIds = array_values(array_unique(array_merge($this->productIds, $found->pluck('id')->all())));
        if (count($this->productIds) > self::MAX_PRODUCTS) {
            $this->productIds = array_slice($this->productIds, 0, self::MAX_PRODUCTS);
            $this->toast(__('marketing_admin.max_products', ['n' => self::MAX_PRODUCTS]), 'warning');
        }
        $this->resetErrorBag('productIds');
        $this->toast(__('marketing_admin.skus_added', ['n' => count($this->productIds) - $before]).($missing->isNotEmpty() ? ' '.__('marketing_admin.skus_missing', ['list' => $missing->take(5)->implode(', ').($missing->count() > 5 ? '…' : '')]) : ''), $missing->isNotEmpty() ? 'warning' : 'success');
        $this->skuPaste = '';
    }

    public function toggle(string $model, string $id, AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.edit') && in_array($model, ['promotion', 'coupon'], true), 403);
        $row = $model === 'promotion' ? Promotion::findOrFail($id) : Coupon::findOrFail($id);
        $row->update(['is_active' => ! $row->is_active]);
        $audit->log('marketing.'.$model.'_'.($row->is_active ? 'activated' : 'deactivated'), $row, ['is_active' => ! $row->is_active], ['is_active' => $row->is_active]);
        $this->toast($row->is_active ? __('marketing_admin.activated') : __('marketing_admin.deactivated'));
    }

    public function createCoupon(?string $promotionId = null): void
    {
        abort_unless($this->can('marketing.create'), 403);
        $this->editingId = null;
        $this->c = ['promotion_id' => $promotionId ?? '', 'code' => '', 'usage_limit' => '', 'per_customer_limit' => '1', 'starts_at' => '', 'ends_at' => '', 'is_active' => true, 'count' => 1, 'prefix' => ''];
        $this->resetValidation();
        $this->showCoupon = true;
    }

    public function generateCode(): void
    {
        $this->c['code'] = strtoupper(($this->c['prefix'] ?? '') ?: 'AB').'-'.strtoupper(Str::random(6));
    }

    public function saveCoupon(AuditLogger $audit): void
    {
        abort_unless($this->can('marketing.create'), 403);
        $this->c['code'] = strtoupper(trim((string) ($this->c['code'] ?? '')));
        $data = $this->validate([
            'c.promotion_id' => 'required|uuid|exists:promotions,id',
            'c.code' => ['required', 'string', 'min:3', 'max:48', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', Rule::unique('coupons', 'code')],
            'c.usage_limit' => 'nullable|integer|min:1', 'c.per_customer_limit' => 'nullable|integer|min:1',
            'c.starts_at' => 'nullable|date', 'c.ends_at' => 'nullable|date|after:c.starts_at', 'c.is_active' => 'boolean',
        ], ['c.code.regex' => __('marketing_admin.code_format')], ['c.code' => __('marketing_admin.code'), 'c.promotion_id' => __('marketing_admin.promotion')])['c'];
        $coupon = Coupon::create(['promotion_id' => $data['promotion_id'], 'code' => $data['code'], 'usage_limit' => $data['usage_limit'] ?: null,
            'per_customer_limit' => $data['per_customer_limit'] ?: null, 'is_active' => (bool) $data['is_active'],
            'starts_at' => self::instant($data['starts_at'] ?: null), 'ends_at' => self::instant($data['ends_at'] ?: null)]);
        $audit->log('marketing.coupon_created', $coupon, [], ['code' => $coupon->code, 'promotion_id' => $coupon->promotion_id]);
        $this->showCoupon = false;
        $this->toast(__('marketing_admin.coupon_created', ['code' => $coupon->code]));
    }

    /** Client-supplied ids can be anything until save-time validation; never feed unvalidated values to a uuid column. */
    private function validProductIds(): array
    {
        return array_values(array_filter($this->productIds, fn ($id) => is_string($id) && Str::isUuid($id)));
    }

    /** Products matching the picker search (max 8), excluding archived ones and those already chosen. */
    private function productMatches()
    {
        $term = trim($this->productSearch);
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return Product::query()->with('translations')->where('status', '!=', 'archived')->whereNotIn('id', $this->validProductIds())
            ->where(fn ($w) => $w->where('sku', 'ilike', $like)->orWhereHas('translations', fn ($t) => $t->where('name', 'ilike', $like))
                ->orWhereHas('variants', fn ($v) => $v->where('sku', 'ilike', $like)))
            ->orderBy('sku')->limit(8)->get();
    }

    public function render()
    {
        $s = trim($this->search);
        $now = now();
        $state = fn ($q) => match ($this->filterState) {
            'running' => $q->where('is_active', true)->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', $now))->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now)),
            'scheduled' => $q->where('is_active', true)->where('starts_at', '>', $now),
            'ended' => $q->where('ends_at', '<=', $now),
            'inactive' => $q->where('is_active', false),
            default => $q,
        };
        $rows = $this->tab === 'coupons'
            ? Coupon::query()->with('promotion:id,name_en,name_ar,type,value')->tap($state)->when($s, fn ($q) => $q->where('code', 'ilike', "%{$s}%"))->latest()->paginate($this->perPage)
            : Promotion::query()->withCount(['coupons', 'products'])->tap($state)->when($s, fn ($q) => $q->where(fn ($w) => $w->where('name_en', 'ilike', "%{$s}%")->orWhere('name_ar', 'ilike', "%{$s}%")))
                ->orderBy('priority')->latest()->paginate($this->perPage);

        return view('livewire.admin.marketing.promotions', [
            'rows' => $rows,
            'promotions' => $this->showCoupon ? Promotion::where('requires_coupon', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar']) : collect(),
            'categories' => $this->showPromo ? Category::with('translations')->orderBy('sort_order')->get() : collect(),
            'productMatches' => $this->showPromo && ($this->p['scope'] ?? '') === 'products' ? $this->productMatches() : collect(),
            'pickedProducts' => $this->showPromo && ($this->p['scope'] ?? '') === 'products' && $this->validProductIds()
                ? Product::with('translations')->whereIn('id', $this->validProductIds())->get()->sortBy(fn ($p) => array_search($p->id, $this->productIds, true))->values() : collect(),
            'branches' => $this->showPromo ? Branch::orderBy('sort_order')->get(['id', 'code', 'name_en', 'name_ar']) : collect(),
            'groups' => $this->showPromo ? CustomerGroup::orderBy('name_en')->get() : collect(),
            'canCreate' => $this->can('marketing.create'),
            'canEdit' => $this->can('marketing.edit'),
        ])->title(__('nav.promotions'));
    }
}
