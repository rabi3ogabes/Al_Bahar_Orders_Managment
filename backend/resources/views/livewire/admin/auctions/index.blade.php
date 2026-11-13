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
                    <tr wire:key="a-{{ $a->id }}" wire:click="openView('{{ $a->id }}')" tabindex="-1" class="cursor-pointer focus:outline-none">
                        <td class="max-w-[18rem]"><a href="{{ route('admin.auctions.index', ['view' => $a->id]) }}" wire:click.prevent.stop="openView('{{ $a->id }}')" class="block truncate font-medium text-ink-900 hover:text-brass-600 dark:text-ivory">{{ $a->trans('title') ?: '—' }}</a><span class="font-mono text-xs text-ink-500" dir="ltr">{{ $a->reference }}</span></td>
                        <td><span class="badge-neutral">{{ __('auction_admin.type_'.$a->type) }}</span></td>
                        <td class="text-xs text-ink-500 tabular-nums">@if ($a->starts_at){{ \App\Support\BusinessTime::display($a->starts_at) }}<br>– {{ \App\Support\BusinessTime::display($a->ends_at) }}@else — @endif</td>
                        <td class="text-end tabular-nums">{{ $a->lots_count }}@if ($a->open_lots_count)<span class="ms-1 text-xs text-ruby-soft">({{ $a->open_lots_count }} {{ __('auction_admin.open') }})</span>@endif</td>
                        <td class="text-end tabular-nums">{{ (int) $a->bids_total }}</td>
                        <td><x-admin.status :value="$a->status" /></td>
                        <td><a href="{{ route('admin.auctions.edit', $a) }}" wire:navigate @click.stop class="btn-ghost btn-sm max-md:min-h-11" aria-label="{{ __('drawer_sales.edit_auction') }}" title="{{ __('drawer_sales.edit_auction') }}"><x-icon name="edit" class="size-4" /></a></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($auctions->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $auctions->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    {{-- Quick view: the auction at a glance without leaving the catalogue. Editing (a large multi-section form) and the live console stay pages. --}}
    @php
        $v = $this->viewed;
        $money = fn ($x) => \App\Support\Money::of($x ?? '0')->format();
        $vLive = $v && in_array($v->status, ['live', 'paused'], true);
    @endphp
    <x-admin.dialog wire:model="showView" :title="$v ? ($v->trans('title') ?: $v->reference) : null" :description="$v ? $v->reference : null" max-width="max-w-2xl">
        @if ($v)
            <x-admin.drawer-bar :url="route('admin.auctions.edit', $v)">
                <x-admin.status :value="$v->status" />
                <span class="badge-neutral">{{ __('auction_admin.type_'.$v->type) }}</span>
                @if ($v->published_at)<span class="text-xs">{{ __('auction_admin.published_at', ['at' => \App\Support\BusinessTime::display($v->published_at)]) }}</span>@endif
            </x-admin.drawer-bar>
            <div class="space-y-5">
                <dl class="card grid grid-cols-2 gap-x-4 gap-y-4 p-5 text-sm [&>*]:min-w-0">
                    <div class="col-span-2"><dt class="text-xs text-ink-500">{{ __('drawer_sales.window') }}</dt><dd class="tabular-nums">@if ($v->starts_at){{ \App\Support\BusinessTime::display($v->starts_at) }} – {{ \App\Support\BusinessTime::display($v->ends_at) }}@else — @endif</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ __('auction_admin.lots') }}</dt><dd class="tabular-nums">{{ $v->lots->count() }}</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ __('auction_admin.bids') }}</dt><dd class="tabular-nums">{{ (int) $v->lots->sum('bid_count') }}</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ __('auction_admin.deposit') }}</dt><dd>{{ (float) $v->deposit_amount > 0 ? $money($v->deposit_amount) : __('drawer_sales.no_deposit') }}</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ __('auction_admin.payment_hours') }}</dt><dd class="tabular-nums">{{ $v->winner_payment_hours }}</dd></div>
                    <div class="col-span-2 flex flex-wrap gap-1.5">
                        @foreach ([['requires_registration', $v->requires_registration], ['pickup_allowed', $v->pickup_allowed], ['delivery_allowed', $v->delivery_allowed], ['cod_allowed', $v->cod_allowed]] as [$k, $on])
                            <span class="{{ $on ? 'badge-success' : 'badge-neutral' }}"><x-icon :name="$on ? 'check' : 'minus'" class="size-3" />{{ __('auction_admin.'.$k) }}</span>
                        @endforeach
                    </div>
                    <div class="col-span-2"><dt class="text-xs text-ink-500">{{ __('drawer_sales.branches_eligible') }}</dt><dd>{{ $v->branches->isEmpty() ? __('drawer_sales.all_branches') : $v->branches->map(fn ($b) => $b->localized('name'))->implode(' · ') }}</dd></div>
                </dl>

                <section class="card overflow-hidden">
                    <h3 class="p-5 font-display text-lg font-semibold">{{ __('drawer_sales.lots_title') }}</h3>
                    @if ($v->lots->isEmpty())
                        <p class="border-t border-sand-100 p-5 text-sm text-ink-500 dark:border-ink-800">{{ __('drawer_sales.no_lots') }}</p>
                    @else
                        <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                            @foreach ($v->lots as $l)
                                <li class="flex items-center gap-3 px-4 py-3" wire:key="vl-{{ $l->id }}">
                                    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-sand-100 font-display font-semibold text-ink-700 dark:bg-ink-800 dark:text-sand-100">{{ $l->lot_number }}</span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-ink-900 dark:text-ivory">{{ $l->localized('title') }}</span>
                                        <span class="block text-xs text-ink-500"><x-admin.money :value="$l->current_price ?? $l->starting_price" /> · {{ trans_choice('auction_admin.n_bids', $l->bid_count, ['count' => $l->bid_count]) }}</span>
                                    </span>
                                    <x-admin.status :value="$l->status" class="shrink-0 !text-[0.65rem]" />
                                    <a href="{{ route('admin.auctions.console', $l) }}" wire:navigate class="btn-ghost -me-2 size-11 shrink-0 p-0" aria-label="{{ __('auction_admin.open_console') }}" title="{{ __('auction_admin.open_console') }}"><x-icon name="play" class="size-4" /></a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <a href="{{ route('admin.auctions.edit', $v) }}" wire:navigate class="btn-secondary min-h-11"><x-icon name="edit" class="size-4" />{{ __('drawer_sales.edit_auction') }}</a>
                    @if ($v->status === 'draft' && $canPublish)
                        <button type="button" wire:click="publish('{{ $v->id }}')" wire:confirm="{{ __('auction_admin.confirm_publish') }}" wire:loading.attr="disabled" class="btn-brass min-h-11"><x-icon name="sparkles" class="size-4" />{{ __('auction_admin.publish') }}</button>
                    @endif
                </div>
            </x-slot:footer>
        @endif
    </x-admin.dialog>
</div>
