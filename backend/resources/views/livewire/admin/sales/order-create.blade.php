<div class="mx-auto max-w-[96rem]" x-data="{ sheet: false }" @docked-cart-open.window="sheet = true" @keydown.escape.window="sheet = false">
    <a href="{{ route('admin.orders.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.orders') }}</a>
    <x-admin.page-header :title="__('neworder_admin.page_title')" :eyebrow="__('nav.sales')" :description="__('neworder_admin.page_hint')" />

    <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_26rem] xl:grid-cols-[minmax(0,1fr)_29rem]">
        {{-- Product browser --}}
        <section aria-label="{{ __('neworder_admin.browse_products') }}" class="min-w-0">
            <div class="card mb-4 space-y-3 p-3 sm:p-4">
                <x-admin.search :placeholder="__('neworder_admin.search_products')" class="!max-w-none" />
                <div class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none]" role="group" aria-label="{{ __('neworder_admin.categories') }}">
                    <button type="button" wire:click="$set('category', '')" aria-pressed="{{ $category === '' ? 'true' : 'false' }}" class="min-h-11 shrink-0 rounded-full border px-4 text-sm font-medium transition {{ $category === '' ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950' : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800' }}">{{ __('neworder_admin.all') }}</button>
                    @foreach ($categories as $c)
                        <button type="button" wire:key="cat-{{ $c->id }}" wire:click="$set('category', '{{ $c->id }}')" aria-pressed="{{ $category === $c->id ? 'true' : 'false' }}" class="min-h-11 shrink-0 rounded-full border px-4 text-sm font-medium transition {{ $category === $c->id ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950' : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800' }}">{{ $c->trans('name') }}</button>
                    @endforeach
                </div>
                <p class="text-xs text-ink-500" aria-live="polite">
                    @if ($branch){{ __('neworder_admin.stock_at', ['branch' => $branch->localized('name')]) }}@else{{ __('neworder_admin.stock_all') }}@endif
                    · {{ trans_choice('neworder_admin.products_shown', $cards->count(), ['count' => $cards->count()]) }}
                </p>
            </div>

            <div wire:loading.class="opacity-60" wire:target="search,category,more" class="transition-opacity">
                @if ($cards->isEmpty())
                    <div class="card"><x-admin.empty :title="__('neworder_admin.no_products')" :description="__('neworder_admin.no_products_hint')" icon="search" /></div>
                @else
                    <ul class="grid gap-3 sm:grid-cols-2 min-[1800px]:grid-cols-3">
                        @foreach ($cards as $c)
                            @php $p = $c['product']; $one = $c['variants']->first(); @endphp
                            <li wire:key="pc-{{ $p->id }}" x-data="{ qty: 1, pick: false }" class="card flex flex-col overflow-hidden">
                                <div class="flex gap-3 p-3">
                                    <span class="grid size-20 shrink-0 place-items-center overflow-hidden rounded-xl bg-sand-100 text-ink-400 dark:bg-ink-800">
                                        @if ($c['image'])<img src="{{ $c['image']->url(320) }}" alt="" class="size-full object-cover" loading="lazy">@else<x-icon name="sofa" class="size-6" />@endif
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="line-clamp-2 text-sm font-medium text-ink-900 dark:text-ivory">{{ $p->trans('name') }}</p>
                                        <p class="font-mono text-[0.7rem] text-ink-500" dir="ltr">{{ $p->sku }}</p>
                                        <p class="mt-1 text-sm font-semibold">@if ($c['from']){{ $c['multi'] ? __('neworder_admin.from') : '' }} <x-admin.money :value="$c['from']->toDecimal()" />@else<span class="text-ink-400">—</span>@endif</p>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @if (! $c['orderable'])<span class="badge-danger !text-[0.65rem]">{{ $c['from'] ? __('neworder_admin.out_of_stock') : __('neworder_admin.not_at_branch') }}</span>
                                            @elseif ($c['stock'] <= 3)<span class="badge-warning !text-[0.65rem]">{{ __('neworder_admin.low_stock', ['count' => $c['stock']]) }}</span>
                                            @else<span class="badge-success !text-[0.65rem]">{{ __('neworder_admin.in_stock', ['count' => min($c['stock'], 99)]) }}</span>@endif
                                            @if ($c['in_cart'])<span class="badge-brass !text-[0.65rem]">{{ __('neworder_admin.in_cart', ['count' => $c['in_cart']]) }}</span>@endif
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-auto border-t border-sand-100 p-3 dark:border-ink-800">
                                    @if (! $c['multi'])
                                        <div class="flex items-center gap-2">
                                            <div class="inline-flex items-center rounded-xl border border-sand-300 dark:border-ink-600" role="group" aria-label="{{ __('neworder_admin.quantity') }}">
                                                <button type="button" @click="qty = Math.max(1, qty - 1)" class="grid size-11 place-items-center rounded-s-xl hover:bg-sand-100 dark:hover:bg-ink-800" aria-label="{{ __('neworder_admin.decrease') }}"><x-icon name="minus" class="size-4" /></button>
                                                <input type="number" x-model.number="qty" min="1" max="{{ config('platform.checkout.max_quantity_per_line') }}" class="h-11 w-12 border-0 bg-transparent p-0 text-center text-sm tabular-nums focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none" aria-label="{{ __('neworder_admin.quantity') }}">
                                                <button type="button" @click="qty = qty + 1" class="grid size-11 place-items-center rounded-e-xl hover:bg-sand-100 dark:hover:bg-ink-800" aria-label="{{ __('neworder_admin.increase') }}"><x-icon name="plus" class="size-4" /></button>
                                            </div>
                                            <button type="button" @disabled(! $c['orderable']) @click="$wire.add('{{ $one['id'] }}', qty); qty = 1" class="btn-primary min-h-11 flex-1"><x-icon name="plus" class="size-4" />{{ __('neworder_admin.add') }}</button>
                                        </div>
                                    @else
                                        <button type="button" @disabled(! $c['orderable']) @click="pick = ! pick" :aria-expanded="pick" class="btn-primary min-h-11 w-full"><x-icon name="layers" class="size-4" />{{ __('neworder_admin.choose_option', ['count' => $c['variants']->count()]) }}</button>
                                        <ul x-show="pick" x-cloak x-transition class="mt-3 space-y-2">
                                            @foreach ($c['variants'] as $v)
                                                <li wire:key="v-{{ $v['id'] }}" class="flex items-center gap-2 rounded-xl border border-sand-200 p-2 dark:border-ink-700">
                                                    <span class="min-w-0 flex-1"><span class="block truncate text-sm">{{ $v['name'] }}</span>
                                                        <span class="text-xs text-ink-500">@if ($v['price'])<x-admin.money :value="$v['price']->toDecimal()" /> · @endif{{ $v['ok'] ? __('neworder_admin.in_stock', ['count' => min($v['stock'], 99)]) : ($v['price'] ? __('neworder_admin.out_of_stock') : __('neworder_admin.not_at_branch')) }}@if ($v['in_cart']) · {{ __('neworder_admin.in_cart', ['count' => $v['in_cart']]) }}@endif</span></span>
                                                    <button type="button" @disabled(! $v['ok']) @click="$wire.add('{{ $v['id'] }}', 1)" class="btn-secondary size-11 shrink-0 !p-0" aria-label="{{ __('neworder_admin.add_named', ['name' => $v['name']]) }}"><x-icon name="plus" class="size-4" /></button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($hasMore)<div class="mt-4 text-center"><button type="button" wire:click="more" class="btn-secondary min-h-11" wire:loading.attr="disabled" wire:target="more">{{ __('neworder_admin.load_more') }}</button></div>@endif
                @endif
            </div>
        </section>

        {{-- Docked cart: sticky column on desktops, bottom sheet on phones/tablets --}}
        <div x-show="sheet" x-cloak x-transition.opacity class="fixed inset-0 z-40 bg-ink-950/50 lg:hidden" @click="sheet = false"></div>
        <aside :class="sheet ? 'max-lg:translate-y-0' : 'max-lg:translate-y-full'" aria-label="{{ __('neworder_admin.panel_title') }}"
               class="max-lg:fixed max-lg:inset-x-0 max-lg:bottom-0 max-lg:z-50 max-lg:max-h-[90dvh] max-lg:rounded-t-3xl max-lg:transition-transform max-lg:duration-300 lg:sticky lg:top-24 lg:max-h-[calc(100dvh-8rem)] flex flex-col overflow-hidden rounded-[var(--radius-card)] border border-sand-200/70 bg-ivory shadow-[var(--shadow-card)] dark:border-ink-700 dark:bg-ink-950">
            <livewire:admin.sales.order-cart mode="docked" :step="$step" />
        </aside>
    </div>

    {{-- Phones: the cart bar opens the sheet --}}
    <div class="fixed inset-x-0 bottom-0 z-30 p-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] lg:hidden" x-show="! sheet">
        <button type="button" @click="sheet = true" class="btn-primary min-h-12 w-full justify-between shadow-[var(--shadow-lift)]" aria-haspopup="dialog">
            <span class="inline-flex items-center gap-2"><x-icon name="cart" class="size-5" />{{ __('neworder_admin.panel_title') }}</span>
            <span class="rounded-full bg-white/15 px-2.5 text-sm tabular-nums">{{ trans_choice('neworder_admin.units', $units, ['count' => $units]) }}</span>
        </button>
    </div>
</div>
