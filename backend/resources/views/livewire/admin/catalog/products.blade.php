<div>
    <x-admin.page-header :title="__('nav.products')" :eyebrow="__('nav.catalog')" :description="__('catalog_admin.products_hint')">
        <x-slot:actions>
            @if ($this->can('catalog.create'))<a href="{{ route('admin.imports.index') }}" wire:navigate class="btn-secondary"><x-icon name="upload" class="size-4" />{{ __('nav.imports') }}</a>@endif
            @if ($canCreate)<a href="{{ route('admin.products.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('catalog_admin.new_product') }}</a>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('catalog_admin.products_search')" />
            <div x-data="{ more: false }" class="contents">
            <button type="button" @click="more = ! more" :aria-expanded="more" class="btn-secondary sm:hidden"><x-icon name="filter" class="size-4" />{{ __('admin.filter') }}@if ($filterStatus || $filterCategory || $filterBrand || $filterFlag)<span class="size-1.5 rounded-full bg-brass-500"></span>@endif</button>
            <div :class="more ? '!flex' : ''" class="hidden flex-col gap-3 sm:contents">
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-40" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('catalog_admin.all_but_archived') }}</option>
                @foreach (\App\Domain\Catalog\Models\Product::STATUSES as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            <select wire:model.live="filterCategory" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('catalog_admin.category') }}">
                <option value="">{{ __('catalog_admin.all_categories') }}</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}">{{ str_repeat('— ', $c->depth) }}{{ $c->trans('name') }}</option>@endforeach
            </select>
            <select wire:model.live="filterBrand" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-40" aria-label="{{ __('catalog_admin.brand') }}">
                <option value="">{{ __('catalog_admin.all_brands') }}</option>
                @foreach ($brands as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
            </select>
            <select wire:model.live="filterFlag" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-40" aria-label="{{ __('catalog_admin.flags') }}">
                <option value="">{{ __('catalog_admin.any_flag') }}</option>
                @foreach (['is_featured', 'is_new_arrival', 'is_bestseller', 'is_oversized', 'requires_assembly'] as $flag)<option value="{{ $flag }}">{{ __('catalog_admin.flag.'.$flag) }}</option>@endforeach
            </select>
            <select wire:model.live="sort" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-40" aria-label="{{ __('catalog_admin.sort') }}">
                <option value="updated">{{ __('catalog_admin.sort_updated') }}</option>
                <option value="relevance">{{ __('catalog_admin.sort_relevance') }}</option>
                <option value="sku">{{ __('catalog_admin.sort_sku') }}</option>
                <option value="price_asc">{{ __('catalog_admin.sort_price_asc') }}</option>
                <option value="price_desc">{{ __('catalog_admin.sort_price_desc') }}</option>
            </select>
            </div>
            </div>
            @if ($hasFilters)<button type="button" wire:click="clearFilters" class="btn-ghost btn-sm"><x-icon name="x" class="size-3.5" />{{ __('catalog_admin.clear') }}</button>@endif
            <x-slot:meta>{{ trans_choice('catalog_admin.products_count', $products->total(), ['count' => $products->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($canEdit && count($selected))
            <div class="flex flex-col gap-3 border-b border-brass-200/60 bg-brass-50/70 px-4 py-3 sm:flex-row sm:items-center dark:border-brass-500/20 dark:bg-brass-500/10" x-transition>
                <span class="text-sm font-medium text-ink-800 dark:text-sand-100">{{ trans_choice('catalog_admin.selected_count', count($selected), ['count' => count($selected)]) }}</span>
                <div class="flex flex-1 flex-col gap-2 sm:flex-row sm:flex-wrap">
                    <select wire:model="bulk.status" class="input pe-10 rtl:[background-position:left_0.5rem_center] py-2 sm:w-40" aria-label="{{ __('admin.status') }}"><option value="">{{ __('catalog_admin.set_status') }}</option>@foreach (\App\Domain\Catalog\Models\Product::STATUSES as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach</select>
                    <select wire:model="bulk.category_id" class="input pe-10 rtl:[background-position:left_0.5rem_center] py-2 sm:w-48" aria-label="{{ __('catalog_admin.category') }}"><option value="">{{ __('catalog_admin.set_category') }}</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ str_repeat('— ', $c->depth) }}{{ $c->trans('name') }}</option>@endforeach</select>
                    <select wire:model="bulk.brand_id" class="input pe-10 rtl:[background-position:left_0.5rem_center] py-2 sm:w-40" aria-label="{{ __('catalog_admin.brand') }}"><option value="">{{ __('catalog_admin.set_brand') }}</option>@foreach ($brands as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach</select>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="$set('selected', [])" class="btn-ghost btn-sm">{{ __('admin.cancel') }}</button>
                    <button type="button" wire:click="applyBulk" wire:confirm="{{ __('catalog_admin.confirm_bulk') }}" class="btn-primary btn-sm" wire:loading.attr="disabled" wire:target="applyBulk"><x-icon name="check" class="size-3.5" />{{ __('catalog_admin.apply') }}</button>
                </div>
            </div>
        @endif

        @if ($products->isEmpty())
            <x-admin.empty :title="$hasFilters ? __('catalog_admin.products_no_match') : __('catalog_admin.products_empty')" :description="$hasFilters ? __('catalog_admin.products_no_match_hint') : __('catalog_admin.products_empty_hint')" icon="sofa">
                @if ($canCreate && ! $hasFilters)<a href="{{ route('admin.products.create') }}" wire:navigate class="btn-brass"><x-icon name="plus" class="size-4" />{{ __('catalog_admin.new_product') }}</a>@endif
            </x-admin.empty>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr>
                        @if ($canEdit)<th class="w-10"><input type="checkbox" class="checkbox" aria-label="{{ __('catalog_admin.select_page') }}"
                            x-on:change="$wire.set('selected', $event.target.checked ? @js($products->pluck('id')) : [])" @checked(count($selected) && count($selected) >= $products->count())></th>@endif
                        <th>{{ __('catalog_admin.product') }}</th><th>{{ __('catalog_admin.category') }}</th><th class="!text-end">{{ __('catalog_admin.price') }}</th>
                        <th class="!text-end">{{ __('catalog_admin.variants') }}</th><th>{{ __('admin.status') }}</th><th>{{ __('admin.updated') }}</th><th class="w-32"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($products as $p)
                        @php $img = $p->images->first(); $onSale = $p->sale_price !== null && (! $p->sale_ends_at || $p->sale_ends_at->isFuture()) && (! $p->sale_starts_at || $p->sale_starts_at->isPast()); @endphp
                        <tr wire:key="p-{{ $p->id }}" class="{{ in_array($p->id, $selected, true) ? 'bg-brass-50/40 dark:bg-brass-500/5' : '' }}">
                            @if ($canEdit)<td><input type="checkbox" value="{{ $p->id }}" wire:model.live="selected" class="checkbox" aria-label="{{ $p->sku }}"></td>@endif
                            <td>
                                <a href="{{ route('admin.products.index', ['view' => $p->id]) }}" wire:click.prevent="openView('{{ $p->id }}')" class="group flex items-center gap-3" aria-label="{{ __('drawer_admin.quick_view_of', ['name' => $p->trans('name')]) }}">
                                    <span class="grid size-12 shrink-0 place-items-center overflow-hidden rounded-xl bg-sand-100 text-ink-400 dark:bg-ink-800">
                                        @if ($img)<img src="{{ $img->url(320) }}" alt="{{ $img->alt() }}" class="size-full object-cover" loading="lazy">@else<x-icon name="sofa" class="size-5" />@endif
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block max-w-xs truncate font-medium text-ink-900 group-hover:text-brass-600 dark:text-ivory">{{ $p->trans('name') }}</span>
                                        <span class="flex items-center gap-2 text-xs text-ink-500"><span class="font-mono" dir="ltr">{{ $p->sku }}</span>@if ($p->brand)<span>· {{ $p->brand->localized('name') }}</span>@endif</span>
                                        <span class="mt-1 flex flex-wrap gap-1">
                                            @if ($p->is_featured)<span class="badge-brass !px-1.5 !py-0 text-[0.65rem]">{{ __('catalog_admin.flag.is_featured') }}</span>@endif
                                            @if ($p->is_new_arrival)<span class="badge-info !px-1.5 !py-0 text-[0.65rem]">{{ __('catalog_admin.flag.is_new_arrival') }}</span>@endif
                                            @if ($p->is_bestseller)<span class="badge-success !px-1.5 !py-0 text-[0.65rem]">{{ __('catalog_admin.flag.is_bestseller') }}</span>@endif
                                        </span>
                                    </span>
                                </a>
                            </td>
                            <td class="text-sm text-ink-600">{{ $p->category?->trans('name') ?? '—' }}</td>
                            <td class="whitespace-nowrap text-end tabular-nums">
                                @if ($onSale)
                                    <span class="block font-medium text-ruby-soft">{{ \App\Support\Money::of($p->sale_price)->format() }}</span>
                                    <span class="block text-xs text-ink-400 line-through">{{ \App\Support\Money::of($p->price)->format() }}</span>
                                @else
                                    <span class="font-medium">{{ \App\Support\Money::of($p->price)->format() }}</span>
                                @endif
                            </td>
                            <td class="text-end tabular-nums text-ink-500">{{ $p->variants_count }}</td>
                            <td><x-admin.status :value="$p->status" /></td>
                            <td class="whitespace-nowrap text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($p->updated_at, 'd M Y') }}</td>
                            <td>
                                <div class="flex justify-end gap-0.5">
                                    @if ($canOrder && $p->status === 'active')<button type="button" wire:click="addToOrder('{{ $p->id }}')" wire:loading.attr="disabled" wire:target="addToOrder('{{ $p->id }}')" class="btn-ghost btn-sm px-2 text-brass-600" aria-label="{{ __('neworder_admin.add_to_order') }}" title="{{ __('neworder_admin.add_to_order') }}"><x-icon name="cart" class="size-4" /></button>@endif
                                    <a href="{{ route('admin.products.edit', $p) }}" wire:navigate class="btn-ghost btn-sm px-2" aria-label="{{ __('drawer_admin.open_full_editor') }}" title="{{ __('drawer_admin.open_full_editor') }}"><x-icon name="edit" class="size-4" /></a>
                                    @if ($canCreate)<button type="button" wire:click="duplicate('{{ $p->id }}')" wire:confirm="{{ __('catalog_admin.confirm_duplicate') }}" class="btn-ghost btn-sm px-2" aria-label="{{ __('catalog_admin.duplicate') }}" title="{{ __('catalog_admin.duplicate') }}"><x-icon name="copy" class="size-4" /></button>@endif
                                    @if ($canDelete && $p->status !== 'archived')<button type="button" wire:click="archive('{{ $p->id }}')" wire:confirm="{{ __('catalog_admin.confirm_archive') }}" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('catalog_admin.archive') }}" title="{{ __('catalog_admin.archive') }}"><x-icon name="ban" class="size-4" /></button>@endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($products->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $products->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
    {{-- Quick view: the row opens beside the list; the full editor (large, multi-tab) stays a page. --}}
    <x-admin.dialog wire:model.live="showView" :title="$viewProduct?->trans('name') ?? $viewProduct?->sku ?? ''" :description="$viewProduct ? __('drawer_admin.quick_view') : null" max-width="max-w-xl">
        @if ($viewProduct)
            <livewire:admin.catalog.product-quick-view :product-id="$viewProduct->id" :key="'pq-'.$viewProduct->id" />
        @endif
        @if ($viewProduct)
            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn-secondary min-h-11" @click="requestClose()">{{ __('admin.close') }}</button>
                    <a href="{{ route('admin.products.edit', $viewProduct) }}" wire:navigate class="btn-primary min-h-11"><x-icon name="edit" class="size-4" />{{ __('drawer_admin.open_full_editor') }}</a>
                </div>
            </x-slot:footer>
        @endif
    </x-admin.dialog>
</div>
