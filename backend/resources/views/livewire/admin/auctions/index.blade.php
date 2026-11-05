<div>
    <x-admin.page-header :title="__('nav.auctions')" :eyebrow="__('auction_admin.eyebrow')" :description="__('auction_admin.index_hint')">
        <x-slot:actions>
            @if ($canCreate)<a href="{{ route('admin.auctions.create') }}" wire:navigate class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('auction_admin.new_auction') }}</a>@endif
        </x-slot:actions>
    </x-admin.page-header>

    @if ($liveLots->isNotEmpty())
        <section class="mb-6">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-ink-700 dark:text-sand-200"><span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-ruby-soft opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-ruby-soft"></span></span>{{ __('auction_admin.live_now') }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
                @foreach ($liveLots as $l)
                    <a href="{{ route('admin.auctions.console', $l) }}" wire:navigate class="card group flex items-center gap-4 p-4 transition hover:border-brass-400" wire:key="ll-{{ $l->id }}">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-ink-900 font-display text-lg font-semibold text-brass-300 dark:bg-brass-500/15">{{ $l->lot_number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $l->localized('title') }}</span>
                            <span class="block truncate text-xs text-ink-500">{{ $l->auction->trans('title') }} · {{ trans_choice('auction_admin.n_bids', $l->bid_count, ['count' => $l->bid_count]) }}</span>
                        </span>
                        <span class="text-end"><x-admin.money :value="$l->current_price ?? $l->starting_price" class="block text-sm font-semibold" /><x-admin.status :value="$l->status" class="mt-1" /></span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('auction_admin.search')" />
            <select wire:model.live="filterStatus" class="input sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['draft', 'scheduled', 'live', 'paused', 'closed', 'cancelled'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            <select wire:model.live="filterType" class="input sm:w-40" aria-label="{{ __('auction_admin.type') }}">
                <option value="">{{ __('auction_admin.all_types') }}</option>
                <option value="scheduled">{{ __('auction_admin.type_scheduled') }}</option><option value="live">{{ __('auction_admin.type_live') }}</option>
            </select>
            <x-slot:meta>{{ trans_choice('auction_admin.auctions_count', $auctions->total(), ['count' => $auctions->total()]) }}</x-slot:meta>
        </x-admin.toolbar>
        @if ($auctions->isEmpty())
            <x-admin.empty :title="__('auction_admin.empty')" :description="__('auction_admin.empty_hint')" icon="gavel">
                @if ($canCreate)<a href="{{ route('admin.auctions.create') }}" wire:navigate class="btn-primary btn-sm"><x-icon name="plus" class="size-4" />{{ __('auction_admin.new_auction') }}</a>@endif
            </x-admin.empty>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('auction_admin.auction') }}</th><th>{{ __('auction_admin.type') }}</th><th>{{ __('auction_admin.window') }}</th><th class="!text-end">{{ __('auction_admin.lots') }}</th><th class="!text-end">{{ __('auction_admin.bids') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($auctions as $a)
                    <tr wire:key="a-{{ $a->id }}">
                        <td class="max-w-[18rem]"><a href="{{ route('admin.auctions.edit', $a) }}" wire:navigate class="block truncate font-medium text-ink-900 hover:text-brass-600 dark:text-ivory">{{ $a->trans('title') ?: '—' }}</a><span class="font-mono text-xs text-ink-500" dir="ltr">{{ $a->reference }}</span></td>
                        <td><span class="badge-neutral">{{ __('auction_admin.type_'.$a->type) }}</span></td>
                        <td class="text-xs text-ink-500 tabular-nums">@if ($a->starts_at){{ \App\Support\BusinessTime::display($a->starts_at) }}<br>→ {{ \App\Support\BusinessTime::display($a->ends_at) }}@else — @endif</td>
                        <td class="text-end tabular-nums">{{ $a->lots_count }}@if ($a->open_lots_count)<span class="ms-1 text-xs text-ruby-soft">({{ $a->open_lots_count }} {{ __('auction_admin.open') }})</span>@endif</td>
                        <td class="text-end tabular-nums">{{ (int) $a->bids_total }}</td>
                        <td><x-admin.status :value="$a->status" /></td>
                        <td><a href="{{ route('admin.auctions.edit', $a) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($auctions->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $auctions->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
</div>
