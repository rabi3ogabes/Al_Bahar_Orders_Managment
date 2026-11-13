@php $full = $chosen->count() >= $max; @endphp
<div class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
    <div class="card overflow-hidden lg:col-span-2">
        <div class="flex flex-wrap items-start justify-between gap-3 border-b border-sand-100 p-5 sm:px-6 dark:border-ink-800">
            <div>
                <h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.upsell_title') }}</h2>
                <p class="mt-1 text-sm text-ink-500">{{ __('catalog_admin.upsell_hint') }}</p>
            </div>
            <span class="badge-neutral tabular-nums" aria-live="polite">{{ __('catalog_admin.upsell_count', ['count' => $chosen->count(), 'max' => $max]) }}</span>
        </div>

        @forelse ($chosen as $p)
            @php $img = $p->images->first(); $units = $stock[$p->id] ?? 0; @endphp
            <div class="flex items-center gap-3 border-b border-sand-100 p-4 sm:px-6 dark:border-ink-800 {{ $p->isActive() ? '' : 'opacity-60' }}" wire:key="up-{{ $p->id }}">
                <span class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-xl bg-sand-100 text-ink-400 dark:bg-ink-800">
                    @if ($img)<img src="{{ $img->url(320) }}" alt="" class="size-full object-cover" loading="lazy" width="56" height="56">@else<x-icon name="sofa" class="size-5" />@endif
                </span>
                <div class="min-w-0 flex-1">
                    <a href="{{ route('admin.products.edit', $p) }}" wire:navigate class="block truncate font-medium text-ink-900 hover:text-brass-600 dark:text-ivory">{{ $p->trans('name') ?? $p->sku }}</a>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-ink-500">
                        <span class="font-mono" dir="ltr">{{ $p->sku }}</span>
                        <span class="tabular-nums">{{ \App\Support\Money::of($p->price)->format() }}</span>
                        @if (! $p->isActive())
                            <span class="badge-warning !px-1.5 !py-0 text-[0.65rem]">{{ __('catalog_admin.upsell_hidden') }}</span>
                        @elseif ($units > 0)
                            <span class="badge-success !px-1.5 !py-0 text-[0.65rem]">{{ __('catalog_admin.upsell_in_stock', ['count' => $units]) }}</span>
                        @else
                            <span class="badge-danger !px-1.5 !py-0 text-[0.65rem]">{{ __('catalog_admin.upsell_out_of_stock') }}</span>
                        @endif
                    </p>
                </div>
                @if ($canEdit)
                    <div class="flex shrink-0 items-center">
                        <button type="button" wire:click="move('{{ $p->id }}', -1)" wire:loading.attr="disabled" class="btn-ghost size-11 !p-0" @disabled($loop->first) aria-label="{{ __('catalog_admin.move_up') }}" title="{{ __('catalog_admin.move_up') }}"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
                        <button type="button" wire:click="move('{{ $p->id }}', 1)" wire:loading.attr="disabled" class="btn-ghost size-11 !p-0" @disabled($loop->last) aria-label="{{ __('catalog_admin.move_down') }}" title="{{ __('catalog_admin.move_down') }}"><x-icon name="chevron-down" class="size-4" /></button>
                        <button type="button" wire:click="remove('{{ $p->id }}')" wire:loading.attr="disabled" class="btn-ghost size-11 !p-0 text-ruby-soft" aria-label="{{ __('org_admin.remove') }}: {{ $p->trans('name') ?? $p->sku }}" title="{{ __('org_admin.remove') }}"><x-icon name="trash" class="size-4" /></button>
                    </div>
                @endif
            </div>
        @empty
            <x-admin.empty :title="__('catalog_admin.upsell_empty')" :description="__('catalog_admin.upsell_empty_hint')" icon="layers" class="!py-10" />
        @endforelse
    </div>

    <div class="space-y-6">
        @if ($canEdit)
            <div class="card card-pad space-y-4 lg:sticky lg:top-24">
                <h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.upsell_add') }}</h2>
                <label class="relative block">
                    <span class="sr-only">{{ __('catalog_admin.upsell_search') }}</span>
                    <x-icon name="search" class="pointer-events-none absolute top-1/2 start-3.5 size-4 -translate-y-1/2 text-ink-400" />
                    <input type="search" wire:model.live.debounce.300ms="search" @disabled($full) placeholder="{{ $full ? __('catalog_admin.upsell_full', ['max' => $max]) : __('catalog_admin.upsell_search') }}" class="input min-h-11 ps-10" autocomplete="off">
                </label>

                <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl py-1.5">
                    <input type="checkbox" wire:model="mutual" class="checkbox mt-0.5 size-5" @disabled($full)>
                    <span class="text-sm"><span class="block font-medium text-ink-800 dark:text-sand-100">{{ __('catalog_admin.upsell_mutual') }}</span><span class="block text-xs text-ink-500">{{ __('catalog_admin.upsell_mutual_hint') }}</span></span>
                </label>

                @if (trim($search) !== '' && ! $full)
                    <div class="-mx-1" wire:loading.class="opacity-60" wire:target="search">
                        @forelse ($results as $p)
                            @php $img = $p->images->first(); $units = $stock[$p->id] ?? 0; @endphp
                            <button type="button" wire:click="add('{{ $p->id }}')" wire:key="cand-{{ $p->id }}" wire:loading.attr="disabled" wire:target="add" class="flex min-h-12 w-full items-center gap-3 rounded-xl p-1.5 text-start transition hover:bg-sand-50 dark:hover:bg-ink-800">
                                <span class="grid size-11 shrink-0 place-items-center overflow-hidden rounded-lg bg-sand-100 text-ink-400 dark:bg-ink-800">
                                    @if ($img)<img src="{{ $img->url(320) }}" alt="" class="size-full object-cover" loading="lazy" width="44" height="44">@else<x-icon name="sofa" class="size-4" />@endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-ink-900 dark:text-ivory">{{ $p->trans('name') ?? $p->sku }}</span>
                                    <span class="flex flex-wrap items-center gap-x-2 text-xs text-ink-500"><span class="font-mono" dir="ltr">{{ $p->sku }}</span><span class="tabular-nums">{{ \App\Support\Money::of($p->price)->format() }}</span>
                                        <span class="{{ $units > 0 ? 'text-emerald-soft' : 'text-ruby-soft' }}">{{ $units > 0 ? __('catalog_admin.upsell_in_stock', ['count' => $units]) : __('catalog_admin.upsell_out_of_stock') }}</span></span>
                                </span>
                                <x-icon name="plus" class="size-4 shrink-0 text-brass-600" />
                            </button>
                        @empty
                            <p class="px-2 py-3 text-sm text-ink-500">{{ __('catalog_admin.upsell_no_results') }}</p>
                        @endforelse
                    </div>
                @endif
            </div>
        @endif

        <div class="card card-pad">
            <p class="text-sm text-ink-600 dark:text-ink-300">{{ trans_choice('catalog_admin.upsell_suggested_by', $suggestedBy, ['count' => $suggestedBy]) }}</p>
        </div>
    </div>
</div>
