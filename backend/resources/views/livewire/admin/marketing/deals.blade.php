@use('App\Domain\Deals\Models\Deal')
@use('App\Domain\Deals\Support\DealDesign')
@use('App\Support\BusinessTime')
@php
    $badge = ['live' => 'badge-success', 'sold_out' => 'badge-warning', 'scheduled' => 'badge-info', 'paused' => 'badge-neutral', 'ended' => 'badge-danger'];
    $filters = collect(\App\Livewire\Admin\Marketing\DealIndex::FILTERS)->mapWithKeys(fn ($f) => [$f => ['flame', __('deals_admin.filter_'.$f)]])->all();
@endphp
<div x-data="{
        skew: Date.now() - {{ $now->getTimestampMs() }}, now: Date.now(), t: null,
        init() { this.t = setInterval(() => { this.now = Date.now(); }, 1000); },
        destroy() { clearInterval(this.t); },
        left(iso) { return Math.max(0, Math.floor((Date.parse(iso) - this.now + this.skew) / 1000)); },
        clock(iso) { let s = this.left(iso); const d = Math.floor(s / 86400); s %= 86400; const p = (n) => String(n).padStart(2, '0'); return (d > 0 ? d + @js(__('deals_admin.unit_d')) + ' ' : '') + p(Math.floor(s / 3600)) + ':' + p(Math.floor((s % 3600) / 60)) + ':' + p(s % 60); },
     }">
    <x-admin.page-header :title="__('nav.deals')" :eyebrow="__('nav.marketing')" :description="__('deals_admin.hint')">
        <x-slot:actions>
            @if ($canCreate)
                <button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('deals_admin.new') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.tabs :tabs="$filters" :current="$filterState" model="filterState" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('deals_admin.search')" />
        </x-admin.toolbar>

        @if ($rows->isEmpty())
            <x-admin.empty :title="__('deals_admin.empty')" :description="__('deals_admin.empty_hint')" icon="flame" />
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($rows as $r)
                    @php
                        $phase = $r->phase($now);
                        $limited = $r->products->every(fn ($p) => $p->quantity_total !== null);
                        $total = (int) $r->products->sum('quantity_total');
                        $sold = (int) $r->products->sum('quantity_sold');
                        $pct = $limited && $total > 0 ? min(100, (int) round($sold * 100 / $total)) : null;
                        $closed = $phase === Deal::ENDED;
                        $hasOrders = (int) $r->any_orders > 0;
                    @endphp
                    <li wire:key="deal-{{ $r->id }}" class="p-4 sm:p-5">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="truncate font-display text-lg font-semibold text-ink-900 dark:text-ivory">{{ $r->localized('name') }}</h2>
                                    <span class="{{ $badge[$phase] }}"><span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ __('deals_admin.phase_'.$phase) }}</span>
                                    @if ($r->show_on_home)<span class="badge-neutral" title="{{ __('deals_admin.on_home') }}"><x-icon name="home" class="size-3" />{{ __('deals_admin.on_home') }}</span>@endif
                                    @if ($r->lacksBuyerLimit() && $phase !== Deal::ENDED)<span class="badge-warning" title="{{ __('deals_admin.chip_no_limit_hint') }}" data-no-buyer-limit><x-icon name="alert" class="size-3" />{{ __('deals_admin.chip_no_limit') }}</span>@endif
                                </div>
                                <p class="mt-1 text-xs text-ink-500">
                                    {{ __('deals_admin.rule_'.$r->discount_type, ['value' => \App\Domain\Deals\Support\DealPricing::plain($r->discount_value)]) }}
                                    · {{ BusinessTime::display($r->starts_at ?? $r->created_at, 'd M, H:i') }} – {{ BusinessTime::display($r->ended_at ?? $r->ends_at, 'd M Y, H:i') }}
                                </p>
                            </div>
                            <div class="text-end" aria-live="off">
                                @if (in_array($phase, [Deal::LIVE, Deal::SOLD_OUT], true))
                                    <p class="text-[0.7rem] font-semibold uppercase tracking-wide text-ink-500">{{ __('deals_admin.ends_in') }}</p>
                                    <p class="font-display text-2xl font-semibold tabular-nums text-ink-900 dark:text-ivory" dir="ltr" x-text="clock('{{ $r->ends_at->toIso8601String() }}')">--:--:--</p>
                                @elseif ($phase === Deal::SCHEDULED)
                                    <p class="text-[0.7rem] font-semibold uppercase tracking-wide text-ink-500">{{ __('deals_admin.starts_in') }}</p>
                                    <p class="font-display text-2xl font-semibold tabular-nums text-ink-900 dark:text-ivory" dir="ltr" x-text="clock('{{ $r->starts_at?->toIso8601String() }}')">--:--:--</p>
                                @elseif ($phase === Deal::PAUSED)
                                    <p class="text-sm text-ink-500">{{ __('deals_admin.paused_note') }}</p>
                                @else
                                    <p class="text-sm text-ink-500">{{ __('deals_admin.ended_note', ['at' => BusinessTime::display($r->ended_at ?? $r->ends_at, 'd M, H:i')]) }}</p>
                                @endif
                            </div>
                        </div>

                        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                            <div><dt class="text-xs text-ink-500">{{ __('deals_admin.products') }}</dt><dd class="font-medium tabular-nums">{{ $r->products_count }}</dd></div>
                            <div class="col-span-2 sm:col-span-1">
                                <dt class="text-xs text-ink-500">{{ __('deals_admin.sold') }}</dt>
                                <dd class="font-medium tabular-nums">{{ $sold }}@if ($limited)<span class="text-ink-400"> / {{ $total }}</span>@endif</dd>
                                @if ($pct !== null)<div class="mt-1 h-1.5 overflow-hidden rounded-full bg-sand-100 dark:bg-ink-800" role="img" aria-label="{{ __('deals_admin.sold_percent', ['n' => $pct]) }}"><div class="h-full rounded-full bg-brass-500" style="width: {{ $pct }}%"></div></div>@endif
                            </div>
                            <div><dt class="text-xs text-ink-500">{{ __('deals_admin.orders') }}</dt><dd class="font-medium tabular-nums">{{ (int) $r->orders_count }}</dd></div>
                            <div><dt class="text-xs text-ink-500">{{ __('deals_admin.savings_given') }}</dt><dd class="font-medium tabular-nums"><x-admin.money :value="$r->savings_given ?? '0.00'" /></dd></div>
                        </dl>

                        <div class="mt-4 flex flex-wrap items-center gap-1 border-t border-sand-100 pt-3 dark:border-ink-800">
                            @if ($canEdit && ! $closed)
                                <label class="me-2 inline-flex items-center gap-1 text-sm text-ink-600 dark:text-ink-300">
                                    <x-admin.switch :on="$r->is_active" :label="$r->is_active ? __('deals_admin.pause') : __('deals_admin.resume')" wire:click="toggle('{{ $r->id }}')" wire:loading.attr="disabled" wire:target="toggle('{{ $r->id }}')" />
                                    {{ $r->is_active ? __('deals_admin.switched_on') : __('deals_admin.switched_off') }}
                                </label>
                            @endif
                            @if ($canEdit)
                                <button type="button" wire:click="editDeal('{{ $r->id }}')" class="btn-ghost btn-sm min-h-11"><x-icon name="edit" class="size-4" />{{ $closed ? __('deals_admin.view') : __('admin.edit') }}</button>
                            @endif
                            @if ($canCreate)
                                <button type="button" wire:click="duplicate('{{ $r->id }}')" wire:loading.attr="disabled" wire:target="duplicate('{{ $r->id }}')" class="btn-ghost btn-sm min-h-11"><x-icon name="copy" class="size-4" />{{ __('deals_admin.duplicate') }}</button>
                            @endif
                            @if ($canEdit && ! $closed)
                                <button type="button" wire:click="askEnd('{{ $r->id }}')" class="btn-ghost btn-sm min-h-11"><x-icon name="stop" class="size-4" />{{ __('deals_admin.end_now') }}</button>
                            @endif
                            @if ($canDelete && ! $hasOrders)
                                <button type="button" wire:click="askDelete('{{ $r->id }}')" class="btn-ghost btn-sm min-h-11 text-ruby-soft"><x-icon name="trash" class="size-4" />{{ __('admin.delete') }}</button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
    </div>

    {{-- ───────────── Editor (side window) ───────────── --}}
    <x-admin.dialog wire:model.live="showEditor" :title="$editingId ? __('deals_admin.edit_title') : __('deals_admin.new_title')" :description="__('deals_admin.editor_hint')" max-width="max-w-5xl">
        @if ($editor)
            @php
                $look = $editor['look'];
                $colors = $editor['colors'];
                $type = $form['discount_type'] ?? 'percent';
                $first = $products[0] ?? null;
                $remaining = $first && is_numeric($first['quantity_total'] ?? null) ? max(0, (int) $first['quantity_total'] - (int) ($first['sold'] ?? 0)) : null;
                $remainingPct = $remaining !== null && (int) $first['quantity_total'] > 0 ? (int) round($remaining * 100 / (int) $first['quantity_total']) : null;
                $ends = ($form['ends_at'] ?? '') !== '' ? \Carbon\CarbonImmutable::parse($form['ends_at'], BusinessTime::zone())->toIso8601String() : now()->addDay()->toIso8601String();
                $ex = $editor['example'];
                $locked = ! $canEdit && $editingId;
            @endphp
            <form wire:submit="save" class="space-y-8" data-deal-editor>
                {{-- 1 · Basics --}}
                <section aria-labelledby="d1" class="space-y-4">
                    <h3 id="d1" class="flex items-center gap-2 font-display text-lg font-semibold"><span class="grid size-7 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950">1</span>{{ __('deals_admin.s_basics') }}</h3>
                    <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                        <x-admin.field :label="__('deals_admin.name_en')" for="deal-name-en" error="form.name_en" required><input id="deal-name-en" wire:model="form.name_en" class="input" dir="ltr" maxlength="255" placeholder="Weekend Furniture Drop"></x-admin.field>
                        <x-admin.field :label="__('deals_admin.name_ar')" for="deal-name-ar" error="form.name_ar" required><input id="deal-name-ar" wire:model="form.name_ar" class="input" dir="rtl" maxlength="255"></x-admin.field>
                        <x-admin.field :label="__('deals_admin.tagline_en')" for="deal-tag-en" error="form.tagline_en"><input id="deal-tag-en" wire:model="form.tagline_en" class="input" dir="ltr" maxlength="255" placeholder="Big pieces, small prices"></x-admin.field>
                        <x-admin.field :label="__('deals_admin.tagline_ar')" for="deal-tag-ar" error="form.tagline_ar"><input id="deal-tag-ar" wire:model="form.tagline_ar" class="input" dir="rtl" maxlength="255"></x-admin.field>
                    </div>
                    <div class="rounded-2xl border border-sand-200 p-4 dark:border-ink-700">
                        <p class="field-label">{{ __('deals_admin.window') }} <span class="font-normal text-ink-500">({{ BusinessTime::zone() }})</span></p>
                        <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                            <x-admin.field :label="__('deals_admin.starts_at')" for="deal-start" error="form.starts_at" :hint="__('deals_admin.starts_hint')"><input id="deal-start" type="datetime-local" wire:model.live="form.starts_at" class="input"></x-admin.field>
                            <x-admin.field :label="__('deals_admin.ends_at')" for="deal-end" error="form.ends_at" required><input id="deal-end" type="datetime-local" wire:model.live="form.ends_at" class="input"></x-admin.field>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="{{ __('deals_admin.presets') }}" data-no-dirty>
                            @foreach (['1h' => '+1 h', '6h' => '+6 h', '24h' => '+24 h', '3d' => __('deals_admin.preset_3d'), 'today' => __('deals_admin.preset_today')] as $key => $text)
                                <button type="button" wire:click="setEnd('{{ $key }}')" class="btn-secondary btn-sm min-h-11">{{ $text }}</button>
                            @endforeach
                        </div>
                    </div>
                </section>

                {{-- 2 · Products --}}
                <section aria-labelledby="d2" class="space-y-4">
                    <h3 id="d2" class="flex items-center gap-2 font-display text-lg font-semibold"><span class="grid size-7 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950">2</span>{{ __('deals_admin.s_products') }}</h3>
                    <p class="text-sm text-ink-500">{{ __('deals_admin.products_hint') }}</p>
                    <div class="relative">
                        <input type="search" wire:model.live.debounce.250ms="productSearch" placeholder="{{ __('deals_admin.search_products') }}" class="input" autocomplete="off" aria-label="{{ __('deals_admin.search_products') }}">
                        @if (mb_strlen(trim($productSearch)) >= 2)
                            <ul class="absolute z-20 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-sand-200 bg-white p-1 shadow-lg dark:border-ink-700 dark:bg-ink-900">
                                @forelse ($editor['matches'] as $m)
                                    <li wire:key="dm-{{ $m->id }}"><button type="button" wire:click="addProduct('{{ $m->id }}')" class="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg px-3 py-2 text-start text-sm hover:bg-sand-50 dark:hover:bg-ink-800"><span class="min-w-0 truncate">{{ $m->trans('name') }}</span><span class="shrink-0 font-mono text-xs text-ink-500" dir="ltr">{{ $m->sku }}</span></button></li>
                                @empty
                                    <li class="px-3 py-2 text-sm text-ink-500">{{ __('deals_admin.no_results') }}</li>
                                @endforelse
                            </ul>
                        @endif
                    </div>
                    @error('products')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    @if (! $products)
                        <p class="rounded-xl border border-dashed border-sand-300 p-4 text-center text-sm text-ink-500 dark:border-ink-700">{{ __('deals_admin.no_products_yet') }}</p>
                    @else
                        <ul class="space-y-3">
                            @foreach ($products as $i => $row)
                                <li wire:key="dp-{{ $row['product_id'] }}" class="rounded-2xl border border-sand-200 p-3 dark:border-ink-700">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0"><p class="truncate text-sm font-semibold">{{ $row['name'] }}</p><p class="font-mono text-xs text-ink-500" dir="ltr">{{ $row['sku'] }}@if (($row['sold'] ?? 0) > 0) · <span class="font-sans">{{ __('deals_admin.already_sold', ['n' => $row['sold']]) }}</span>@endif</p></div>
                                        @if (($row['sold'] ?? 0) === 0)
                                            <button type="button" wire:click="removeProduct({{ $i }})" class="btn-ghost size-11 shrink-0 justify-center p-0 text-ruby-soft" aria-label="{{ __('deals_admin.remove') }}"><x-icon name="trash" class="size-4" /></button>
                                        @endif
                                    </div>
                                    <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 [&>*]:min-w-0">
                                        <x-admin.field :label="$type === 'fixed_price' ? __('deals_admin.deal_price') : __('deals_admin.own_price')" :for="'dp-price-'.$i" :error="'products.'.$i.'.deal_price'" :required="$type === 'fixed_price'">
                                            <input id="dp-price-{{ $i }}" type="number" step="0.01" min="0" inputmode="decimal" wire:model.live.debounce.400ms="products.{{ $i }}.deal_price" class="input tabular-nums" dir="ltr" placeholder="{{ $type === 'fixed_price' ? '0.00' : __('deals_admin.auto') }}">
                                        </x-admin.field>
                                        <x-admin.field :label="__('deals_admin.quantity')" :for="'dp-qty-'.$i" :error="'products.'.$i.'.quantity_total'">
                                            <input id="dp-qty-{{ $i }}" type="number" min="1" inputmode="numeric" wire:model.live.debounce.400ms="products.{{ $i }}.quantity_total" class="input tabular-nums" dir="ltr" placeholder="{{ __('deals_admin.unlimited') }}">
                                            @if (in_array($i, $editor['no_limit_rows'], true))<p class="mt-1 flex items-start gap-1 text-xs text-amber-700 dark:text-amber-300" role="note" data-no-buyer-limit><x-icon name="alert" class="mt-0.5 size-3 shrink-0" />{{ __('deals_admin.warn_no_limit_row') }}</p>@endif
                                        </x-admin.field>
                                        <x-admin.field :label="__('deals_admin.per_order')" :for="'dp-po-'.$i" :error="'products.'.$i.'.limit_per_order'">
                                            <input id="dp-po-{{ $i }}" type="number" min="1" inputmode="numeric" wire:model="products.{{ $i }}.limit_per_order" class="input tabular-nums" dir="ltr" placeholder="{{ __('deals_admin.same_as_deal') }}">
                                        </x-admin.field>
                                        <x-admin.field :label="__('deals_admin.per_customer')" :for="'dp-pc-'.$i" :error="'products.'.$i.'.limit_per_customer'">
                                            <input id="dp-pc-{{ $i }}" type="number" min="1" inputmode="numeric" wire:model="products.{{ $i }}.limit_per_customer" class="input tabular-nums" dir="ltr" placeholder="{{ __('deals_admin.same_as_deal') }}">
                                        </x-admin.field>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- 3 · Price rule --}}
                <section aria-labelledby="d3" class="space-y-4">
                    <h3 id="d3" class="flex items-center gap-2 font-display text-lg font-semibold"><span class="grid size-7 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950">3</span>{{ __('deals_admin.s_price') }}</h3>
                    <div class="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-labelledby="d3">
                        @foreach (Deal::DISCOUNT_TYPES as $t)
                            <label class="flex min-h-11 cursor-pointer items-start gap-2 rounded-xl border p-3 text-sm transition focus-within:ring-2 focus-within:ring-brass-500 {{ $type === $t ? 'border-brass-500 bg-brass-500/5 font-medium ring-1 ring-brass-500' : 'border-sand-200 dark:border-ink-700' }}">
                                <input type="radio" wire:model.live="form.discount_type" value="{{ $t }}" class="sr-only">
                                <span><span class="block">{{ __('deals_admin.type_'.$t) }}</span><span class="block text-xs font-normal text-ink-500">{{ __('deals_admin.type_'.$t.'_hint') }}</span></span>
                            </label>
                        @endforeach
                    </div>
                    @if ($type !== 'fixed_price')
                        <x-admin.field :label="$type === 'percent' ? __('deals_admin.value_percent') : __('deals_admin.value_amount')" for="deal-value" error="form.discount_value" required class="max-w-xs">
                            <input id="deal-value" type="number" step="0.01" min="0" inputmode="decimal" wire:model.live.debounce.400ms="form.discount_value" class="input tabular-nums" dir="ltr">
                        </x-admin.field>
                    @else
                        <p class="text-sm text-ink-500">{{ __('deals_admin.fixed_hint') }}</p>
                    @endif
                    <div class="rounded-2xl bg-sand-50 p-4 text-sm dark:bg-ink-800/60" role="status">
                        <p class="text-xs font-semibold uppercase tracking-wide text-ink-500">{{ __('deals_admin.customer_pays') }}</p>
                        @if ($ex && $ex['deal'])
                            <p class="mt-1 flex flex-wrap items-baseline gap-x-3 gap-y-1"><span class="truncate text-ink-600 dark:text-ink-300">{{ $ex['name'] }}</span>
                                <span class="text-ink-400 line-through tabular-nums" dir="ltr">{{ $ex['normal']->format() }}</span>
                                <span class="font-display text-xl font-semibold tabular-nums text-emerald-soft" dir="ltr">{{ $ex['deal']->format() }}</span>
                                <span class="badge-success">−{{ $ex['percent'] }}%</span></p>
                        @elseif ($ex)
                            <p class="mt-1 text-ink-500">{{ __('deals_admin.no_discount_yet', ['name' => $ex['name'], 'price' => $ex['normal']->format()]) }}</p>
                        @else
                            <p class="mt-1 text-ink-500">{{ __('deals_admin.add_product_first') }}</p>
                        @endif
                    </div>
                </section>

                {{-- 4 · Limits --}}
                <section aria-labelledby="d4" class="space-y-4">
                    <h3 id="d4" class="flex items-center gap-2 font-display text-lg font-semibold"><span class="grid size-7 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950">4</span>{{ __('deals_admin.s_limits') }}</h3>
                    <p class="text-sm text-ink-500">{{ __('deals_admin.limits_hint') }}</p>
                    @if ($editor['no_limit_rows'])<p class="flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200" role="alert" data-no-buyer-limit><x-icon name="alert" class="mt-0.5 size-4 shrink-0" />{{ trans_choice('deals_admin.warn_no_limit', count($editor['no_limit_rows']), ['n' => count($editor['no_limit_rows'])]) }}</p>@endif
                    <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                        <x-admin.field :label="__('deals_admin.per_order')" for="deal-lpo" error="form.limit_per_order" :hint="__('deals_admin.per_order_hint')"><input id="deal-lpo" type="number" min="1" inputmode="numeric" wire:model="form.limit_per_order" class="input tabular-nums" dir="ltr" placeholder="{{ __('deals_admin.no_limit') }}"></x-admin.field>
                        <x-admin.field :label="__('deals_admin.per_customer')" for="deal-lpc" error="form.limit_per_customer" :hint="__('deals_admin.per_customer_hint')"><input id="deal-lpc" type="number" min="1" inputmode="numeric" wire:model="form.limit_per_customer" class="input tabular-nums" dir="ltr" placeholder="{{ __('deals_admin.no_limit') }}"></x-admin.field>
                    </div>
                </section>

                {{-- 5 · Look, with the live widget --}}
                <section aria-labelledby="d5" class="space-y-4">
                    <h3 id="d5" class="flex items-center gap-2 font-display text-lg font-semibold"><span class="grid size-7 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950">5</span>{{ __('deals_admin.s_look') }}</h3>
                    <p class="text-sm text-ink-500">{{ __('deals_admin.look_hint') }}</p>
                    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_21rem] lg:items-start">
                        <div class="order-2 space-y-5 lg:order-1 [&>*]:min-w-0">
                            <fieldset>
                                <legend class="field-label">{{ __('deals_admin.style') }}</legend>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-5">
                                    @foreach (DealDesign::STYLES as $st)
                                        <label class="flex min-h-11 cursor-pointer items-center justify-center rounded-xl border px-2 py-2 text-center text-sm transition focus-within:ring-2 focus-within:ring-brass-500 {{ ($design['style'] ?? '') === $st ? 'border-brass-500 bg-brass-500/5 font-medium ring-1 ring-brass-500' : 'border-sand-200 dark:border-ink-700' }}"><input type="radio" wire:model.live="design.style" value="{{ $st }}" class="sr-only">{{ __('deals_admin.style_'.$st) }}</label>
                                    @endforeach
                                </div>
                            </fieldset>
                            <fieldset>
                                <legend class="field-label">{{ __('deals_admin.palette') }}</legend>
                                <div class="grid grid-cols-3 gap-2 sm:grid-cols-6">
                                    @foreach (DealDesign::PALETTES as $pal)
                                        @php $pc = $editor['palettes'][$pal]; @endphp
                                        <label class="flex min-h-11 cursor-pointer flex-col items-center gap-1 rounded-xl border p-2 text-xs transition focus-within:ring-2 focus-within:ring-brass-500 {{ ($design['palette'] ?? '') === $pal ? 'border-brass-500 ring-1 ring-brass-500' : 'border-sand-200 dark:border-ink-700' }}">
                                            <input type="radio" wire:model.live="design.palette" value="{{ $pal }}" class="sr-only">
                                            <span class="flex h-6 w-full overflow-hidden rounded-md ring-1 ring-black/10"><i class="flex-1" style="background: {{ $pc['background'] }}"></i><i class="w-1/4" style="background: {{ $pc['accent'] }}"></i><i class="w-1/4" style="background: {{ $pc['text'] }}"></i></span>
                                            {{ __('deals_admin.palette_'.$pal) }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                            @if (($design['palette'] ?? '') === 'custom')
                                <div class="grid gap-3 sm:grid-cols-3">
                                    @foreach (['background', 'text', 'accent'] as $ck)
                                        <x-admin.field :label="__('deals_admin.color_'.$ck)" :for="'deal-c-'.$ck" :error="'design.colors.'.$ck">
                                            <div class="flex items-center gap-2"><input id="deal-c-{{ $ck }}" type="color" wire:model.live.debounce.300ms="design.colors.{{ $ck }}" class="size-11 cursor-pointer rounded-lg border-0 bg-transparent p-0"><span class="font-mono text-xs uppercase text-ink-500" dir="ltr">{{ $design['colors'][$ck] ?? '' }}</span></div>
                                        </x-admin.field>
                                    @endforeach
                                </div>
                            @endif
                            @if ($editor['warning'])<p class="flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200" role="alert"><x-icon name="alert" class="mt-0.5 size-4 shrink-0" />{{ $editor['warning'] }}</p>@endif
                            @error('design.colors.text')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            @error('design.colors.accent')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                                <fieldset>
                                    <legend class="field-label">{{ __('deals_admin.shape') }}</legend>
                                    <div class="flex flex-wrap gap-2">@foreach (DealDesign::SHAPES as $sh)<label class="min-h-11 cursor-pointer rounded-full border px-4 py-2 text-sm focus-within:ring-2 focus-within:ring-brass-500 {{ ($design['shape'] ?? '') === $sh ? 'border-brass-500 bg-brass-500/5 font-medium ring-1 ring-brass-500' : 'border-sand-200 dark:border-ink-700' }}"><input type="radio" wire:model.live="design.shape" value="{{ $sh }}" class="sr-only">{{ __('deals_admin.shape_'.$sh) }}</label>@endforeach</div>
                                </fieldset>
                                <fieldset>
                                    <legend class="field-label">{{ __('deals_admin.timer_look') }}</legend>
                                    <div class="flex flex-wrap gap-2">@foreach (DealDesign::LOOKS as $lk)<label class="min-h-11 cursor-pointer rounded-full border px-4 py-2 text-sm focus-within:ring-2 focus-within:ring-brass-500 {{ ($design['timer']['look'] ?? '') === $lk ? 'border-brass-500 bg-brass-500/5 font-medium ring-1 ring-brass-500' : 'border-sand-200 dark:border-ink-700' }}"><input type="radio" wire:model.live="design.timer.look" value="{{ $lk }}" class="sr-only">{{ __('deals_admin.look_'.$lk) }}</label>@endforeach</div>
                                </fieldset>
                            </div>
                            <fieldset>
                                <legend class="field-label">{{ __('deals_admin.units') }}</legend>
                                <div class="flex flex-wrap gap-2">@foreach (DealDesign::UNITS as $u)<label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-full border border-sand-200 px-4 py-2 text-sm dark:border-ink-700"><input type="checkbox" wire:model.live="design.timer.units" value="{{ $u }}" class="checkbox">{{ __('deals_admin.unit_name_'.$u) }}</label>@endforeach</div>
                                <p class="field-hint">{{ __('deals_admin.units_hint') }}</p>
                                @error('design.timer.units')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                            </fieldset>
                            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                                <x-admin.field :label="__('deals_admin.urgency')" for="deal-urg" error="design.timer.urgency_minutes" :hint="__('deals_admin.urgency_hint')"><input id="deal-urg" type="number" min="0" max="{{ DealDesign::MAX_URGENCY_MINUTES }}" inputmode="numeric" wire:model.live.debounce.400ms="design.timer.urgency_minutes" class="input tabular-nums" dir="ltr"></x-admin.field>
                                <x-admin.field :label="__('deals_admin.icon')" for="deal-icon" error="design.icon">
                                    <select id="deal-icon" wire:model.live="design.icon" class="input">@foreach (DealDesign::ICONS as $ic)<option value="{{ $ic }}">{{ __('deals_admin.icon_'.$ic) }}</option>@endforeach</select>
                                </x-admin.field>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <x-admin.toggle :label="__('deals_admin.stock_bar')" :hint="__('deals_admin.stock_bar_hint')" wire:model.live="design.stock_bar" />
                                <x-admin.toggle :label="__('deals_admin.percent_badge')" :hint="__('deals_admin.percent_badge_hint')" wire:model.live="design.percent_badge" />
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                                <x-admin.field :label="__('deals_admin.headline_en')" for="deal-h-en" error="design.headline_en"><input id="deal-h-en" wire:model.live.debounce.400ms="design.headline_en" class="input" dir="ltr" maxlength="40"></x-admin.field>
                                <x-admin.field :label="__('deals_admin.headline_ar')" for="deal-h-ar" error="design.headline_ar"><input id="deal-h-ar" wire:model.live.debounce.400ms="design.headline_ar" class="input" dir="rtl" maxlength="40"></x-admin.field>
                                <x-admin.field :label="__('deals_admin.cta_en')" for="deal-c-en" error="design.cta_en"><input id="deal-c-en" wire:model.live.debounce.400ms="design.cta_en" class="input" dir="ltr" maxlength="30"></x-admin.field>
                                <x-admin.field :label="__('deals_admin.cta_ar')" for="deal-c-ar" error="design.cta_ar"><input id="deal-c-ar" wire:model.live.debounce.400ms="design.cta_ar" class="input" dir="rtl" maxlength="30"></x-admin.field>
                            </div>
                            <div class="max-w-sm">
                                <x-admin.image-input model="image" :file="$image" :current="$editor['image_url']" :label="__('deals_admin.image')" :hint="__('deals_admin.image_hint')" ratio="16 / 7" :maxKb="4096" />
                                @error('image')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                                @if ($editor['image_url'])<button type="button" wire:click="removeImage" class="btn-ghost btn-sm mt-2 min-h-11 text-ruby-soft"><x-icon name="trash" class="size-4" />{{ __('deals_admin.remove_image') }}</button>@endif
                            </div>
                        </div>
                        <div class="order-1 lg:order-2 lg:sticky lg:top-0" aria-labelledby="d5-preview">
                            <p id="d5-preview" class="mb-2 text-xs font-semibold uppercase tracking-wide text-ink-500">{{ __('deals_admin.preview') }}</p>
                            <x-deals.widget :look="$look" :colors="$colors" :name="['en' => $form['name_en'] ?? '', 'ar' => $form['name_ar'] ?? '']" :tagline="['en' => $form['tagline_en'] ?? '', 'ar' => $form['tagline_ar'] ?? '']"
                                :ends="$ends" :percent="$ex && $ex['deal'] ? $ex['percent'] : ($type === 'percent' ? \App\Domain\Deals\Support\DealPricing::plain($form['discount_value'] ?? 0) : null)"
                                :left="$remaining ?? 12" :left-percent="$remainingPct ?? 24" :price="$ex && $ex['deal'] ? ['deal' => $ex['deal']->format(), 'normal' => $ex['normal']->format()] : null" :image-url="$editor['image_url']" :skew="0" />
                            <p class="mt-2 text-xs text-ink-500">{{ __('deals_admin.preview_hint') }}</p>
                        </div>
                    </div>
                </section>

                {{-- 6 · Show on home and Active --}}
                <section aria-labelledby="d6" class="space-y-3">
                    <h3 id="d6" class="flex items-center gap-2 font-display text-lg font-semibold"><span class="grid size-7 place-items-center rounded-full bg-ink-900 text-xs text-ivory dark:bg-brass-500 dark:text-ink-950">6</span>{{ __('deals_admin.s_visibility') }}</h3>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <x-admin.toggle :label="__('deals_admin.show_on_home')" :hint="__('deals_admin.show_on_home_hint')" wire:model="form.show_on_home" />
                        <x-admin.toggle :label="__('deals_admin.active')" :hint="__('deals_admin.active_hint')" wire:model="form.is_active" />
                    </div>
                </section>

                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                    <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                    <button class="btn-primary" @disabled(! $editor['dirty']) wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ $editingId ? __('deals_admin.save_changes') : __('deals_admin.create') }}</button>
                </div>
            </form>
        @endif
    </x-admin.dialog>

    {{-- ───────────── Decisions ───────────── --}}
    <x-admin.dialog wire:model="showEnd" variant="modal" :title="__('deals_admin.end_q')" :description="__('deals_admin.end_body')" max-width="max-w-md">
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary" data-cancel @click="requestClose()">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary" wire:click="endNow" wire:loading.attr="disabled" wire:target="endNow">{{ __('deals_admin.end_confirm') }}</button>
        </div>
    </x-admin.dialog>
    <x-admin.dialog wire:model="showDelete" variant="modal" :title="__('deals_admin.delete_q')" :description="__('deals_admin.delete_body')" max-width="max-w-md">
        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary" data-cancel @click="requestClose()">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-danger" wire:click="deleteDeal" wire:loading.attr="disabled" wire:target="deleteDeal">{{ __('deals_admin.delete_confirm') }}</button>
        </div>
    </x-admin.dialog>
</div>
