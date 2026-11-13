@php
    $img = $product->images->first();
    $onSale = $product->sale_price !== null && (! $product->sale_ends_at || $product->sale_ends_at->isFuture()) && (! $product->sale_starts_at || $product->sale_starts_at->isPast());
    $names = ['en' => $product->translations->firstWhere('locale', 'en')?->name, 'ar' => $product->translations->firstWhere('locale', 'ar')?->name];
@endphp
<div class="space-y-6" data-quick-view="{{ $product->id }}">
    <div class="relative aspect-[16/9] max-h-56 w-full overflow-hidden rounded-2xl bg-sand-100 dark:bg-ink-800">
        @if ($img)
            <img src="{{ $img->url(960) }}" alt="{{ $img->alt() }}" class="size-full object-cover" width="640" height="360">
        @else
            <span class="grid size-full place-items-center text-ink-400"><x-icon name="sofa" class="size-10" /></span>
        @endif
        <x-admin.status :value="$product->status" class="absolute start-3 top-3 shadow-sm" />
    </div>

    <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm [&>div]:min-w-0">
        <div><dt class="text-xs text-ink-500">{{ __('catalog_admin.f_name') }} (EN)</dt><dd class="font-medium" dir="ltr">{{ $names['en'] ?: '—' }}</dd></div>
        <div><dt class="text-xs text-ink-500">{{ __('catalog_admin.f_name') }} (AR)</dt><dd class="font-medium" dir="rtl">{{ $names['ar'] ?: '—' }}</dd></div>
        <div><dt class="text-xs text-ink-500">{{ __('catalog_admin.category') }}</dt><dd>{{ $product->category?->trans('name') ?? '—' }}</dd></div>
        <div><dt class="text-xs text-ink-500">{{ __('catalog_admin.brand') }}</dt><dd>{{ $product->brand?->localized('name') ?? '—' }}</dd></div>
        <div><dt class="text-xs text-ink-500">SKU</dt><dd class="font-mono text-xs" dir="ltr">{{ $product->sku }}</dd></div>
        <div>
            <dt class="text-xs text-ink-500">{{ __('catalog_admin.price') }}</dt>
            <dd class="tabular-nums">
                @if ($onSale)
                    <span class="font-semibold text-ruby-soft">{{ \App\Support\Money::of($product->sale_price)->format() }}</span>
                    <span class="ms-1 text-xs text-ink-400 line-through">{{ \App\Support\Money::of($product->price)->format() }}</span>
                @else
                    <span class="font-semibold">{{ \App\Support\Money::of($product->price)->format() }}</span>
                @endif
            </dd>
        </div>
    </dl>

    <section aria-labelledby="pq-switches" class="space-y-2">
        <h3 id="pq-switches" class="text-sm font-semibold text-ink-800 dark:text-sand-100">{{ __('drawer_admin.product_switches') }}</h3>
        @if ($product->status === 'archived')
            <p class="rounded-xl bg-sand-50 px-4 py-3 text-sm text-ink-600 dark:bg-ink-800/60 dark:text-sand-200">{{ __('drawer_admin.product_archived_note') }}</p>
        @else
            <x-admin.toggle :label="__('drawer_admin.visible_in_store')" :hint="__('drawer_admin.visible_in_store_hint')" wire:model.live="visible" data-no-dirty :disabled="! $canEdit" />
        @endif
        @foreach (\App\Livewire\Admin\Catalog\ProductQuickView::FLAGS as $flag)
            <x-admin.toggle :label="__('catalog_admin.flag.'.$flag)" wire:model.live="flags.{{ $flag }}" data-no-dirty :disabled="! $canEdit" wire:key="pq-{{ $flag }}" />
        @endforeach
        @unless ($canEdit)<p class="text-xs text-ink-500">{{ __('drawer_admin.view_only') }}</p>@endunless
    </section>

    @if ($stock['visible'])
        <section aria-labelledby="pq-stock" class="space-y-2">
            <h3 id="pq-stock" class="text-sm font-semibold text-ink-800 dark:text-sand-100">{{ __('drawer_admin.stock_by_branch') }}</h3>
            @if ($stock['rows']->isEmpty())
                <p class="rounded-xl bg-sand-50 px-4 py-3 text-sm text-ink-600 dark:bg-ink-800/60 dark:text-sand-200">{{ __('drawer_admin.no_stock_rows') }}</p>
            @else
                <ul class="divide-y divide-sand-100 rounded-xl border border-sand-200/70 dark:divide-ink-800 dark:border-ink-700">
                    @foreach ($stock['rows'] as $row)
                        <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm" wire:key="pq-stock-{{ $row['branch']?->id }}">
                            <span class="min-w-0 truncate">{{ $row['branch']?->localized('name') }} <span class="font-mono text-xs text-ink-400" dir="ltr">{{ $row['branch']?->code }}</span></span>
                            <span class="flex shrink-0 items-center gap-2">
                                @if ($row['low'])<span class="badge-warning !px-1.5 !py-0 text-[0.65rem]">{{ __('drawer_admin.low') }}</span>@endif
                                <span class="font-semibold tabular-nums {{ $row['available'] === 0 ? 'text-ruby-soft' : '' }}">{{ $row['available'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    <section class="grid gap-2 sm:grid-cols-2">
        <a href="{{ route('admin.products.edit', ['product' => $product, 'tab' => 'variants']) }}" wire:navigate class="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-sand-200/70 px-4 py-2.5 text-sm transition hover:border-brass-400 dark:border-ink-700">
            <span>{{ __('catalog_admin.variants') }}</span><span class="badge-neutral tabular-nums">{{ $variantCount }}</span>
        </a>
        <a href="{{ route('admin.products.edit', ['product' => $product, 'tab' => 'upsell']) }}" wire:navigate class="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-sand-200/70 px-4 py-2.5 text-sm transition hover:border-brass-400 dark:border-ink-700">
            <span>{{ __('drawer_admin.upsell_products') }}</span><span class="badge-neutral tabular-nums">{{ $upsellCount }}</span>
        </a>
    </section>
</div>
