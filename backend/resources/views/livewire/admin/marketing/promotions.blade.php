@php
    $tabs = ['promotions' => ['percent', __('marketing_admin.tab_promotions')], 'coupons' => ['tag', __('marketing_admin.tab_coupons')]];
    $badge = function ($promo) {
        return match ($promo->type) {
            'percentage', 'flash_sale' => rtrim(rtrim((string) $promo->value, '0'), '.').'%',
            'fixed' => \App\Support\Money::of($promo->value)->format(),
            'free_delivery' => __('marketing_admin.free_delivery'),
            'bogo' => __('marketing_admin.bogo_label', ['buy' => $promo->buy_quantity, 'get' => $promo->get_quantity]),
            default => __('marketing_admin.type_'.$promo->type),
        };
    };
    $window = fn ($r) => $r->starts_at || $r->ends_at ? trim(($r->starts_at ? \App\Support\BusinessTime::display($r->starts_at, 'd M') : '…').' – '.($r->ends_at ? \App\Support\BusinessTime::display($r->ends_at, 'd M Y') : '∞')) : __('marketing_admin.always');
    $stateOf = function ($r) {
        if (! $r->is_active) return 'inactive';
        if ($r->ends_at && $r->ends_at->isPast()) return 'expired';
        if ($r->starts_at && $r->starts_at->isFuture()) return 'scheduled';
        return 'active';
    };
@endphp
<div>
    <x-admin.page-header :title="__('nav.promotions')" :eyebrow="__('nav.marketing')" :description="__('marketing_admin.promotions_hint')">
        <x-slot:actions>
            @if ($canCreate)
                <button type="button" wire:click="createCoupon" class="btn-secondary"><x-icon name="tag" class="size-4" />{{ __('marketing_admin.new_coupon') }}</button>
                <button type="button" wire:click="createPromotion" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('marketing_admin.new_promotion') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.tabs :tabs="$tabs" :current="$tab" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="$tab === 'coupons' ? __('marketing_admin.coupons_search') : __('marketing_admin.promotions_search')" />
            <select wire:model.live="filterState" class="input sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['running', 'scheduled', 'ended', 'inactive'] as $st)<option value="{{ $st }}">{{ __('marketing_admin.state_'.$st) }}</option>@endforeach
            </select>
        </x-admin.toolbar>
        @if ($rows->isEmpty())
            <x-admin.empty :title="$tab === 'coupons' ? __('marketing_admin.coupons_empty') : __('marketing_admin.promotions_empty')" :description="__('marketing_admin.promotions_empty_hint')" :icon="$tab === 'coupons' ? 'tag' : 'percent'" />
        @elseif ($tab === 'promotions')
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('marketing_admin.promotion') }}</th><th>{{ __('marketing_admin.offer') }}</th><th>{{ __('marketing_admin.rules') }}</th><th>{{ __('marketing_admin.window') }}</th><th class="!text-end">{{ __('marketing_admin.usage') }}</th><th>{{ __('admin.status') }}</th><th class="w-24"></th></tr></thead>
                <tbody>@foreach ($rows as $r)
                    <tr wire:key="pr-{{ $r->id }}">
                        <td class="max-w-[16rem]"><span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $r->localized('name') }}</span><span class="text-xs text-ink-500">{{ $r->requires_coupon ? trans_choice('marketing_admin.n_codes', $r->coupons_count, ['count' => $r->coupons_count]) : __('marketing_admin.automatic') }}</span></td>
                        <td><span class="badge-brass" dir="auto">{{ $badge($r) }}</span><span class="mt-1 block text-xs text-ink-500">{{ __('marketing_admin.type_'.$r->type) }}</span></td>
                        <td class="text-xs">
                            <div class="flex max-w-[14rem] flex-wrap gap-1">
                                @if ($r->is_stackable)<span class="badge-info">{{ __('marketing_admin.stackable') }}</span>@else<span class="badge-neutral">{{ __('marketing_admin.exclusive') }}</span>@endif
                                <span class="badge-neutral" title="{{ __('marketing_admin.priority') }}">P{{ $r->priority }}</span>
                                @if ($r->first_order_only)<span class="badge-neutral">{{ __('marketing_admin.first_order') }}</span>@endif
                                @if ($r->min_basket_amount)<span class="badge-neutral">≥ <x-admin.money :value="$r->min_basket_amount" /></span>@endif
                                @if ($r->scope === 'categories')<span class="badge-neutral">{{ __('marketing_admin.scope_categories') }}</span>@endif
                                @if ($r->scope === 'products')<span class="badge-neutral" title="{{ __('marketing_admin.scope_products') }}"><x-icon name="tag" class="size-3" />{{ trans_choice('marketing_admin.scope_n_products', (int) $r->products_count, ['count' => (int) $r->products_count]) }}</span>@endif
                            </div>
                        </td>
                        <td class="text-xs text-ink-500 tabular-nums">{{ $window($r) }}</td>
                        <td class="whitespace-nowrap text-end tabular-nums">{{ $r->times_used }}@if ($r->usage_limit)<span class="text-ink-400"> / {{ $r->usage_limit }}</span>@endif</td>
                        <td><x-admin.status :value="$stateOf($r)" /></td>
                        <td>
                            <div class="flex justify-end gap-1">
                                @if ($canEdit)
                                    <button type="button" wire:click="toggle('promotion', '{{ $r->id }}')" class="btn-ghost btn-sm px-2" title="{{ $r->is_active ? __('marketing_admin.deactivate') : __('marketing_admin.activate') }}"><x-icon :name="$r->is_active ? 'pause' : 'play'" class="size-4" /></button>
                                    <button type="button" wire:click="editPromotion('{{ $r->id }}')" class="btn-ghost btn-sm px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
                                @endif
                                @if ($canCreate && $r->requires_coupon)<button type="button" wire:click="createCoupon('{{ $r->id }}')" class="btn-ghost btn-sm px-2" title="{{ __('marketing_admin.new_coupon') }}"><x-icon name="tag" class="size-4" /></button>@endif
                            </div>
                        </td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('marketing_admin.code') }}</th><th>{{ __('marketing_admin.promotion') }}</th><th>{{ __('marketing_admin.window') }}</th><th class="!text-end">{{ __('marketing_admin.usage') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($rows as $r)
                    <tr wire:key="cp-{{ $r->id }}">
                        <td><span class="rounded-lg border border-dashed border-brass-400 bg-brass-50 px-2 py-1 font-mono text-sm font-semibold text-brass-700 dark:bg-brass-500/10 dark:text-brass-300" dir="ltr">{{ $r->code }}</span></td>
                        <td class="max-w-[16rem] truncate">{{ $r->promotion?->localized('name') }} <span class="text-xs text-ink-500">· {{ $r->promotion ? $badge($r->promotion) : '' }}</span></td>
                        <td class="text-xs text-ink-500 tabular-nums">{{ $window($r) }}</td>
                        <td class="whitespace-nowrap text-end tabular-nums">{{ $r->times_used }}@if ($r->usage_limit)<span class="text-ink-400"> / {{ $r->usage_limit }}</span>@endif @if ($r->per_customer_limit)<span class="block text-xs text-ink-500">{{ __('marketing_admin.per_customer_n', ['n' => $r->per_customer_limit]) }}</span>@endif</td>
                        <td><x-admin.status :value="$stateOf($r)" /></td>
                        <td>@if ($canEdit)<button type="button" wire:click="toggle('coupon', '{{ $r->id }}')" class="btn-ghost btn-sm px-2" title="{{ $r->is_active ? __('marketing_admin.deactivate') : __('marketing_admin.activate') }}"><x-icon :name="$r->is_active ? 'pause' : 'play'" class="size-4" /></button>@endif</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
        @endif
        @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
    </div>

    <x-admin.dialog wire:model="showPromo" :title="$editingId ? __('marketing_admin.edit_promotion') : __('marketing_admin.new_promotion')" max-width="max-w-3xl">
        <form wire:submit="savePromotion" class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('marketing_admin.name_en')" for="p.name_en" required><input id="p.name_en" wire:model="p.name_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.name_ar')" for="p.name_ar" required><input id="p.name_ar" wire:model="p.name_ar" class="input" dir="rtl"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.description_en')" for="p.description_en"><textarea id="p.description_en" wire:model="p.description_en" rows="2" class="input" dir="ltr"></textarea></x-admin.field>
                <x-admin.field :label="__('marketing_admin.description_ar')" for="p.description_ar"><textarea id="p.description_ar" wire:model="p.description_ar" rows="2" class="input" dir="rtl"></textarea></x-admin.field>
            </div>
            <div>
                <span class="field-label">{{ __('marketing_admin.offer_type') }}</span>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach (\App\Livewire\Admin\Marketing\PromotionIndex::TYPES as $t)
                        <label class="cursor-pointer rounded-xl border px-3 py-2.5 text-sm transition {{ ($p['type'] ?? '') === $t ? 'border-brass-500 bg-brass-50 font-medium dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}"><input type="radio" wire:model.live="p.type" value="{{ $t }}" class="sr-only">{{ __('marketing_admin.type_'.$t) }}</label>
                    @endforeach
                </div>
            </div>
            <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                @if (($p['type'] ?? '') !== 'free_delivery')
                    <x-admin.field :label="in_array($p['type'] ?? '', ['percentage', 'flash_sale', 'bogo', 'bundle'], true) ? __('marketing_admin.value_percent') : __('marketing_admin.value_amount')" for="p.value" required><input id="p.value" type="number" step="0.01" min="0" wire:model="p.value" class="input tabular-nums" dir="ltr"></x-admin.field>
                @endif
                <x-admin.field :label="__('marketing_admin.min_basket')" for="p.min_basket_amount"><input id="p.min_basket_amount" type="number" step="0.01" min="0" wire:model="p.min_basket_amount" class="input tabular-nums" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.max_discount')" for="p.max_discount_amount"><input id="p.max_discount_amount" type="number" step="0.01" min="0" wire:model="p.max_discount_amount" class="input tabular-nums" dir="ltr"></x-admin.field>
                @if (in_array($p['type'] ?? '', ['bogo', 'bundle'], true))
                    <x-admin.field :label="__('marketing_admin.buy_qty')" for="p.buy_quantity" required><input id="p.buy_quantity" type="number" min="1" wire:model="p.buy_quantity" class="input tabular-nums"></x-admin.field>
                    @if (($p['type'] ?? '') === 'bogo')
                        <x-admin.field :label="__('marketing_admin.get_qty')" for="p.get_quantity" required><input id="p.get_quantity" type="number" min="1" wire:model="p.get_quantity" class="input tabular-nums"></x-admin.field>
                        <x-admin.field :label="__('marketing_admin.get_discount')" for="p.get_discount_percent" :hint="__('marketing_admin.get_discount_hint')"><input id="p.get_discount_percent" type="number" min="0" max="100" wire:model="p.get_discount_percent" class="input tabular-nums"></x-admin.field>
                    @endif
                @endif
            </div>
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('marketing_admin.starts_at')" for="p.starts_at"><input id="p.starts_at" type="datetime-local" wire:model="p.starts_at" class="input"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.ends_at')" for="p.ends_at"><input id="p.ends_at" type="datetime-local" wire:model="p.ends_at" class="input"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.usage_limit')" for="p.usage_limit" :hint="__('marketing_admin.blank_unlimited')"><input id="p.usage_limit" type="number" min="1" wire:model="p.usage_limit" class="input tabular-nums"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.per_customer_limit')" for="p.per_customer_limit" :hint="__('marketing_admin.blank_unlimited')"><input id="p.per_customer_limit" type="number" min="1" wire:model="p.per_customer_limit" class="input tabular-nums"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.priority')" for="p.priority" :hint="__('marketing_admin.priority_hint')" required><input id="p.priority" type="number" min="0" wire:model="p.priority" class="input tabular-nums"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.customer_group')" for="p.customer_group_id">
                    <select id="p.customer_group_id" wire:model="p.customer_group_id" class="input"><option value="">{{ __('marketing_admin.everyone') }}</option>@foreach ($groups as $g)<option value="{{ $g->id }}">{{ app()->getLocale() === 'ar' ? $g->name_ar : $g->name_en }}</option>@endforeach</select>
                </x-admin.field>
            </div>
            <div class="grid gap-2 sm:grid-cols-2">
                <x-admin.toggle :label="__('marketing_admin.requires_coupon')" :hint="__('marketing_admin.requires_coupon_hint')" wire:model="p.requires_coupon" />
                <x-admin.toggle :label="__('marketing_admin.stackable')" :hint="__('marketing_admin.stackable_hint')" wire:model="p.is_stackable" />
                <x-admin.toggle :label="__('marketing_admin.first_order')" wire:model="p.first_order_only" />
                <x-admin.toggle :label="__('marketing_admin.applies_to_auctions')" wire:model="p.applies_to_auctions" />
                <x-admin.toggle :label="__('marketing_admin.active')" wire:model="p.is_active" />
            </div>
            <div>
                <span class="field-label">{{ __('marketing_admin.scope') }}</span>
                <div class="mb-3 flex flex-wrap gap-2">
                    @foreach (\App\Livewire\Admin\Marketing\PromotionIndex::SCOPES as $sc)<label class="cursor-pointer rounded-full border px-3 py-1.5 text-sm {{ ($p['scope'] ?? '') === $sc ? 'border-brass-500 bg-brass-50 font-medium dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}"><input type="radio" wire:model.live="p.scope" value="{{ $sc }}" class="sr-only">{{ __('marketing_admin.scope_'.$sc) }}</label>@endforeach
                </div>
                @if (($p['scope'] ?? '') === 'categories')
                    <div class="flex max-h-40 flex-wrap gap-2 overflow-y-auto rounded-xl border border-sand-200 p-3 dark:border-ink-700">
                        @foreach ($categories as $cat)<label class="inline-flex items-center gap-1.5 text-sm"><input type="checkbox" wire:model="categoryIds" value="{{ $cat->id }}" class="checkbox">{{ $cat->trans('name') }}</label>@endforeach
                    </div>
                    @error('categoryIds')<p class="field-error">{{ $message }}</p>@enderror
                @elseif (($p['scope'] ?? '') === 'products')
                    <div class="space-y-3">
                        <div class="relative">
                            <input type="search" wire:model.live.debounce.250ms="productSearch" placeholder="{{ __('marketing_admin.search_products') }}" class="input" autocomplete="off" aria-label="{{ __('marketing_admin.search_products') }}">
                            @if (mb_strlen(trim($productSearch)) >= 2)
                                <ul class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-sand-200 bg-white p-1 shadow-lg dark:border-ink-700 dark:bg-ink-900">
                                    @forelse ($productMatches as $m)
                                        <li><button type="button" wire:click="addProduct('{{ $m->id }}')" wire:key="pm-{{ $m->id }}" class="flex w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-start text-sm hover:bg-sand-50 dark:hover:bg-ink-800">
                                            <span class="min-w-0 truncate">{{ $m->trans('name') }}</span><span class="shrink-0 font-mono text-xs text-ink-500" dir="ltr">{{ $m->sku }}</span>
                                        </button></li>
                                    @empty
                                        <li class="px-3 py-2 text-sm text-ink-500">{{ __('marketing_admin.no_product_results') }}</li>
                                    @endforelse
                                </ul>
                            @endif
                        </div>
                        <details class="rounded-xl border border-sand-200 p-3 text-sm dark:border-ink-700">
                            <summary class="cursor-pointer font-medium">{{ __('marketing_admin.paste_skus') }}</summary>
                            <p class="mt-2 text-xs text-ink-500">{{ __('marketing_admin.paste_skus_hint') }}</p>
                            <textarea wire:model="skuPaste" rows="3" class="input mt-2 font-mono text-xs" dir="ltr" aria-label="{{ __('marketing_admin.paste_skus') }}"></textarea>
                            <button type="button" wire:click="addBySkus" class="btn-secondary btn-sm mt-2">{{ __('marketing_admin.add_skus') }}</button>
                        </details>
                        <div>
                            <div class="mb-1.5 flex items-center justify-between text-xs text-ink-500">
                                <span>{{ trans_choice('marketing_admin.products_selected', count($productIds), ['count' => count($productIds), 'max' => \App\Livewire\Admin\Marketing\PromotionIndex::MAX_PRODUCTS]) }}</span>
                                @if ($productIds)<button type="button" wire:click="$set('productIds', [])" class="text-ruby-soft hover:underline">{{ __('marketing_admin.clear_all') }}</button>@endif
                            </div>
                            @if ($pickedProducts->isEmpty())
                                <p class="rounded-xl border border-dashed border-sand-300 p-3 text-center text-sm text-ink-500 dark:border-ink-700">{{ __('marketing_admin.no_products_yet') }}</p>
                            @else
                                <ul class="flex max-h-48 flex-wrap gap-1.5 overflow-y-auto rounded-xl border border-sand-200 p-2 dark:border-ink-700">
                                    @foreach ($pickedProducts as $pp)
                                        <li wire:key="pp-{{ $pp->id }}" class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-sand-100 py-1 ps-3 pe-1 text-xs dark:bg-ink-800">
                                            <span class="truncate">{{ $pp->trans('name') }}</span><span class="font-mono text-ink-500" dir="ltr">{{ $pp->sku }}</span>
                                            <button type="button" wire:click="removeProduct('{{ $pp->id }}')" class="grid size-5 place-items-center rounded-full hover:bg-sand-200 dark:hover:bg-ink-700" aria-label="{{ __('org_admin.remove') }}"><x-icon name="x" class="size-3" /></button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                    @error('productIds')<p class="field-error">{{ $message }}</p>@enderror
                @endif
            </div>
            <div>
                <span class="field-label">{{ __('marketing_admin.branches') }}</span>
                <div class="flex flex-wrap gap-2">@foreach ($branches as $b)<label class="inline-flex items-center gap-1.5 rounded-full border border-sand-200 px-3 py-1.5 text-sm dark:border-ink-700"><input type="checkbox" wire:model="branchIds" value="{{ $b->id }}" class="checkbox">{{ $b->localized('name') }}</label>@endforeach</div>
                <p class="field-hint">{{ __('marketing_admin.branches_hint') }}</p>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="savePromotion"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>

    <x-admin.dialog wire:model="showCoupon" :title="__('marketing_admin.new_coupon')" :description="__('marketing_admin.new_coupon_hint')">
        <form wire:submit="saveCoupon" class="space-y-5">
            <x-admin.field :label="__('marketing_admin.promotion')" for="c.promotion_id" required>
                <select id="c.promotion_id" wire:model="c.promotion_id" class="input"><option value="">—</option>@foreach ($promotions as $pr)<option value="{{ $pr->id }}">{{ $pr->localized('name') }}</option>@endforeach</select>
            </x-admin.field>
            @if ($promotions->isEmpty())<p class="text-xs text-ink-500">{{ __('marketing_admin.no_coupon_promotions') }}</p>@endif
            <x-admin.field :label="__('marketing_admin.code')" for="c.code" required :hint="__('marketing_admin.code_hint')">
                <div class="flex gap-2"><input id="c.code" wire:model="c.code" class="input flex-1 font-mono uppercase" dir="ltr" autocomplete="off"><button type="button" wire:click="generateCode" class="btn-secondary"><x-icon name="sparkles" class="size-4" />{{ __('marketing_admin.generate') }}</button></div>
            </x-admin.field>
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('marketing_admin.usage_limit')" for="c.usage_limit" :hint="__('marketing_admin.blank_unlimited')"><input id="c.usage_limit" type="number" min="1" wire:model="c.usage_limit" class="input tabular-nums"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.per_customer_limit')" for="c.per_customer_limit"><input id="c.per_customer_limit" type="number" min="1" wire:model="c.per_customer_limit" class="input tabular-nums"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.starts_at')" for="c.starts_at"><input id="c.starts_at" type="datetime-local" wire:model="c.starts_at" class="input"></x-admin.field>
                <x-admin.field :label="__('marketing_admin.ends_at')" for="c.ends_at"><input id="c.ends_at" type="datetime-local" wire:model="c.ends_at" class="input"></x-admin.field>
            </div>
            <x-admin.toggle :label="__('marketing_admin.active')" wire:model="c.is_active" />
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveCoupon"><x-icon name="check" class="size-4" />{{ __('marketing_admin.create_coupon') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
