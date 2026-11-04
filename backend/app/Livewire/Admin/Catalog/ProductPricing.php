<?php

namespace App\Livewire\Admin\Catalog;

use App\Domain\Branches\Models\Branch;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductBranchSetting;
use App\Domain\Catalog\Models\ProductVariantBranchSetting;
use App\Domain\Identity\Services\AccessControl;
use App\Domain\Pricing\Services\PriceResolver;
use App\Domain\Pricing\Services\PricingService;
use App\Livewire\Admin\Concerns\InteractsWithAdmin;
use App\Support\BusinessTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Component;

/**
 * Global, variant and per-branch pricing for one product. The matrix shows what customers will actually pay at each
 * branch (PriceResolver) and lets staff override price/availability at branches where they hold pricing.edit.
 */
class ProductPricing extends Component
{
    use InteractsWithAdmin;

    public Product $product;

    public bool $showEditor = false;

    /** level: product | variant | product_branch | variant_branch */
    public array $editor = [];

    public function mount(Product $product): void
    {
        abort_unless($this->can('pricing.view') || $this->can('catalog.view'), 403);
        $this->product = $product;
    }

    private function toLocalInput($value): string
    {
        return $value ? BusinessTime::local($value)->format('Y-m-d\TH:i') : '';
    }

    private function toUtc(?string $value): ?CarbonImmutable
    {
        return $value ? CarbonImmutable::parse($value, BusinessTime::zone())->utc() : null;
    }

    public function open(string $level, ?string $branchId = null, ?string $variantId = null): void
    {
        abort_unless(in_array($level, ['product', 'variant', 'product_branch', 'variant_branch'], true), 422);
        $this->authorizeLevel($level, $branchId);
        $row = match ($level) {
            'product' => $this->product,
            'variant' => $this->product->variants()->findOrFail($variantId),
            'product_branch' => ProductBranchSetting::where('product_id', $this->product->id)->where('branch_id', $branchId)->first(),
            'variant_branch' => ProductVariantBranchSetting::where('product_variant_id', $this->product->variants()->findOrFail($variantId)->id)->where('branch_id', $branchId)->first(),
        };
        $this->resetValidation();
        $this->editor = [
            'level' => $level, 'branch_id' => $branchId, 'variant_id' => $variantId,
            'price' => (string) ($row?->price ?? ''), 'sale_price' => (string) ($row?->sale_price ?? ''),
            'sale_starts_at' => $this->toLocalInput($row?->sale_starts_at), 'sale_ends_at' => $this->toLocalInput($row?->sale_ends_at),
            'reason' => '',
        ];
        $this->showEditor = true;
    }

    private function authorizeLevel(string $level, ?string $branchId): void
    {
        if (str_ends_with($level, '_branch')) {
            abort_unless($branchId && Branch::whereKey($branchId)->exists(), 404);
            $this->authorizeBranch('pricing.edit', $branchId);
        } else {
            abort_unless($this->canGlobally('pricing.edit'), 403);
        }
    }

    public function save(PricingService $pricing): void
    {
        $e = $this->editor;
        $this->authorizeLevel($e['level'], $e['branch_id']);
        $this->validate([
            'editor.price' => [$e['level'] === 'product' ? 'required' : 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'editor.sale_price' => ['nullable', 'numeric', 'min:0'],
            'editor.sale_starts_at' => ['nullable', 'date'],
            'editor.sale_ends_at' => ['nullable', 'date', 'after:editor.sale_starts_at'],
            'editor.reason' => ['required', 'string', 'min:3', 'max:255'],
        ], [], ['editor.price' => __('catalog_admin.price'), 'editor.sale_price' => __('catalog_admin.sale_price'),
            'editor.sale_ends_at' => __('catalog_admin.sale_ends'), 'editor.reason' => __('admin.reason')]);

        $price = $e['price'] !== '' ? Money::of((string) $e['price'])->toDecimal() : null;
        $sale = $e['sale_price'] !== '' ? Money::of((string) $e['sale_price'])->toDecimal() : null;
        $args = [$price, $sale, $this->toUtc($e['sale_starts_at']), $this->toUtc($e['sale_ends_at']), $e['reason']];
        $variant = $e['variant_id'] ? $this->product->variants()->findOrFail($e['variant_id'])->setRelation('product', $this->product) : null;

        $ok = $this->attempt(fn () => match ($e['level']) {
            'product' => $pricing->setProductPrice($this->product, ...$args),
            'variant' => $pricing->setVariantPrice($variant, ...$args),
            'product_branch' => $pricing->setProductBranchPrice($this->product, $e['branch_id'], ...$args),
            'variant_branch' => $pricing->setVariantBranchPrice($variant, $e['branch_id'], ...$args),
        }, __('catalog_admin.price_saved'));
        if ($ok) {
            $this->product->refresh();
            $this->showEditor = false;
        }
    }

    /** Remove an override at a branch level (or a variant's own price) so the next precedence level applies. */
    public function clear(PricingService $pricing): void
    {
        $e = $this->editor;
        abort_if($e['level'] === 'product', 422);
        $this->authorizeLevel($e['level'], $e['branch_id']);
        $this->validate(['editor.reason' => 'required|string|min:3|max:255'], [], ['editor.reason' => __('admin.reason')]);
        $variant = $e['variant_id'] ? $this->product->variants()->findOrFail($e['variant_id'])->setRelation('product', $this->product) : null;
        $args = [null, null, null, null, $e['reason']];
        $ok = $this->attempt(fn () => match ($e['level']) {
            'variant' => $pricing->setVariantPrice($variant, ...$args),
            'product_branch' => $pricing->setProductBranchPrice($this->product, $e['branch_id'], ...$args),
            'variant_branch' => $pricing->setVariantBranchPrice($variant, $e['branch_id'], ...$args),
        }, __('catalog_admin.override_cleared'));
        if ($ok) {
            $this->showEditor = false;
        }
    }

    public function toggleBranch(string $branchId, PricingService $pricing): void
    {
        $this->authorizeBranch('pricing.edit', $branchId);
        $row = ProductBranchSetting::where('product_id', $this->product->id)->where('branch_id', $branchId)->first();
        $enabled = $this->product->available_in_all_branches ? ($row === null || $row->is_enabled) : ($row?->is_enabled ?? false);
        $pricing->setBranchAvailability($this->product, $branchId, ! $enabled, $row?->is_visible ?? true);
        $this->toast(! $enabled ? __('catalog_admin.enabled_at_branch') : __('catalog_admin.disabled_at_branch'));
    }

    public function toggleVariantBranch(string $variantId, string $branchId, PricingService $pricing): void
    {
        $this->authorizeBranch('pricing.edit', $branchId);
        $variant = $this->product->variants()->findOrFail($variantId);
        $row = ProductVariantBranchSetting::where('product_variant_id', $variant->id)->where('branch_id', $branchId)->first();
        $pricing->setVariantBranchAvailability($variant, $branchId, ! ($row?->is_enabled ?? true));
        $this->toast(__('admin.saved'));
    }

    public function render()
    {
        $acl = app(AccessControl::class);
        $user = auth()->user();
        $scope = $acl->scope($user, 'pricing.view');
        if ($scope->isEmpty()) {
            $scope = $acl->scope($user, 'catalog.view');
        }
        $branches = Branch::query()->when(! $scope->all, fn ($q) => $q->whereIn('id', $scope->branchIds))->orderBy('sort_order')->get();
        $variants = $this->product->variants()->with('attributeValues')->get()->each(fn ($v) => $v->setRelation('product', $this->product));
        $productSettings = ProductBranchSetting::where('product_id', $this->product->id)->get()->keyBy('branch_id');
        $variantSettings = ProductVariantBranchSetting::whereIn('product_variant_id', $variants->pluck('id'))->get()->groupBy('branch_id');

        $resolver = new PriceResolver;
        $matrix = [];
        foreach ($branches as $b) {
            $resolver->prime($variants, $b->id);
            foreach ($variants as $v) {
                $avail = $resolver->availability($v, $b);
                $matrix[$b->id][$v->id] = [
                    'available' => $avail->purchasable, 'reason' => $avail->reason,
                    'price' => $resolver->price($v, $b->id),
                    'override' => ($variantSettings[$b->id] ?? collect())->firstWhere('product_variant_id', $v->id),
                ];
            }
        }
        $global = $variants->mapWithKeys(fn ($v) => [$v->id => $resolver->price($v, null)]);

        return view('livewire.admin.catalog.product-pricing', [
            'branches' => $branches,
            'variants' => $variants,
            'matrix' => $matrix,
            'global' => $global,
            'productSettings' => $productSettings,
            'canGlobal' => $this->canGlobally('pricing.edit'),
            'editableBranches' => $branches->filter(fn ($b) => $acl->allows($user, 'pricing.edit', $b->id))->pluck('id')->flip(),
        ]);
    }
}
