@php
    // One look per urgency: a tile colour and a shape, so the group is never told apart by colour alone.
    $look = [
        'red' => ['alert', 'bg-ruby-soft/10 text-ruby-soft dark:text-red-300'],
        'amber' => ['clock', 'bg-amber-soft/10 text-amber-soft dark:text-amber-300'],
        'ok' => ['check', 'bg-emerald-soft/10 text-emerald-soft dark:text-emerald-300'],
    ];
    $pill = fn (bool $on) => 'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition '.($on
        ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950'
        : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800');
    $dial = fn (?string $phone) => $phone ? preg_replace('/[^+\d]/', '', $phone) : null;
    $emptyTitle = $scope === 'mine' ? __('ops_live.page.empty_mine') : ($branchKnown ? __('ops_live.page.empty_branch') : __('ops_live.page.empty_title'));
@endphp
<div wire:poll.20s.visible x-data x-on:ops-order-live.window.debounce.800ms="$wire.$refresh()">
    <x-admin.page-header :title="__('ops_live.page.title')" :eyebrow="__('ops_live.page.eyebrow')" :description="__('ops_live.page.description')">
        <x-slot:actions>
            <span class="inline-flex items-center gap-2 text-xs text-ink-500 dark:text-ink-400"><span class="size-2 animate-pulse rounded-full bg-emerald-soft motion-reduce:animate-none" aria-hidden="true"></span>{{ __('ops_live.page.live') }}</span>
            <button type="button" wire:click="$refresh" class="btn-ghost btn-sm min-h-11" aria-label="{{ __('ops_live.page.refresh') }}" title="{{ __('ops_live.page.refresh') }}"><x-icon name="refresh" class="size-4" wire:loading.class="animate-spin" wire:target="$refresh" /></button>
        </x-slot:actions>
    </x-admin.page-header>

    {{-- How many, by urgency: the same three states as the chips, each with its own shape and a word. --}}
    <div class="mb-5 flex flex-wrap items-center gap-2" aria-live="polite">
        @foreach (['red' => 'summary_late', 'amber' => 'summary_amber', 'ok' => 'summary_ok'] as $state => $key)
            <span class="inline-flex min-h-9 items-center gap-2 rounded-full px-3.5 text-sm font-medium tabular-nums {{ $look[$state][1] }}" data-summary="{{ $state }}">
                <x-icon :name="$look[$state][0]" class="size-4" />{{ trans_choice('ops_live.page.'.$key, $counts[$state], ['count' => $counts[$state]]) }}
            </span>
        @endforeach
    </div>

    {{-- Filters: everything, only what is assigned to me, and one chip per branch for people who work in several. --}}
    <div class="mb-5 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('ops_live.page.filters') }}">
        <button type="button" wire:click="setScope('all')" aria-pressed="{{ $scope === 'all' ? 'true' : 'false' }}" class="{{ $pill($scope === 'all') }}">{{ __('ops_live.page.all') }}</button>
        <button type="button" wire:click="setScope('mine')" aria-pressed="{{ $scope === 'mine' ? 'true' : 'false' }}" title="{{ __('ops_live.page.mine_hint') }}" class="{{ $pill($scope === 'mine') }}"><x-icon name="user" class="size-4" />{{ __('ops_live.page.mine') }}</button>
        @if (count($list->branches) > 1)
            <span class="mx-1 hidden h-6 w-px bg-sand-300 sm:block dark:bg-ink-600" aria-hidden="true"></span>
            @foreach ($list->branches as $b)
                <button type="button" wire:click="setBranch('{{ $b['id'] }}')" wire:key="chip-{{ $b['id'] }}" aria-pressed="{{ $branch === $b['id'] ? 'true' : 'false' }}" title="{{ $b['name'] }}" class="{{ $pill($branch === $b['id']) }}">
                    <x-icon name="store" class="size-4" /><span dir="ltr" class="font-mono">{{ $b['code'] }}</span>
                </button>
            @endforeach
        @endif
    </div>

    {{-- Loading while a filter is applied: the list keeps its place, a skeleton in the final layout shows that something is coming. --}}
    <div wire:loading.delay wire:target="setScope, setBranch" class="card mb-5 divide-y divide-sand-100 dark:divide-ink-800" aria-hidden="true">
        @foreach (range(1, 3) as $n)
            <div class="flex items-center gap-4 p-4 sm:px-6">
                <span class="size-10 shrink-0 animate-pulse rounded-xl bg-sand-100 dark:bg-ink-800"></span>
                <span class="flex-1 space-y-2"><span class="block h-3.5 w-1/3 animate-pulse rounded bg-sand-100 dark:bg-ink-800"></span><span class="block h-3 w-2/3 animate-pulse rounded bg-sand-100 dark:bg-ink-800"></span></span>
                <span class="h-8 w-24 animate-pulse rounded-full bg-sand-100 dark:bg-ink-800"></span>
            </div>
        @endforeach
    </div>

    <div wire:loading.class="opacity-50" wire:target="setScope, setBranch">
        @if ($list->items->isEmpty())
            <div class="card"><x-admin.empty :title="$emptyTitle" :description="__('ops_live.page.empty_hint')" icon="check" data-empty="clear" /></div>
        @else
            @foreach ($groups as $state => $items)
                @continue($items->isEmpty())
                <section class="card mb-5 overflow-hidden" aria-labelledby="att-g-{{ $state }}" data-group="{{ $state }}">
                    <header class="flex items-center gap-3 border-b border-sand-100 px-4 py-4 sm:px-6 dark:border-ink-800">
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl {{ $look[$state][1] }}"><x-icon :name="$look[$state][0]" class="size-[1.15rem]" /></span>
                        <div class="min-w-0">
                            <h2 id="att-g-{{ $state }}" class="font-display text-lg font-semibold leading-tight">{{ __('ops_live.group.'.$state.'.title') }} <span class="ms-1 text-sm font-normal tabular-nums text-ink-500">{{ $items->count() }}</span></h2>
                            <p class="text-xs text-ink-500 dark:text-ink-400">{{ __('ops_live.group.'.$state.'.hint') }}</p>
                        </div>
                    </header>
                    <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                        @foreach ($items as $item)
                            @php $a = $item->action; $tel = $dial($item->call['phone'] ?? null); @endphp
                            <li class="flex flex-col gap-3 p-4 sm:px-6 lg:flex-row lg:items-center" wire:key="att-{{ $item->id }}" data-category="{{ $item->category }}">
                                <div class="flex min-w-0 flex-1 items-start gap-3">
                                    <span class="mt-0.5 grid size-10 shrink-0 place-items-center rounded-xl bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon :name="$item->icon" class="size-[1.15rem]" /></span>
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                            @if ($item->subjectIsNumber)<span class="font-mono font-medium text-ink-900 dark:text-ivory" dir="ltr">{{ $item->subject }}</span>@else<span class="font-medium text-ink-900 dark:text-ivory">{{ $item->subject }}</span>@endif
                                            <span class="text-sm text-ink-600 dark:text-sand-200">{{ $item->label }}</span>
                                            @if ($item->branchCode)<span class="badge-neutral font-mono" dir="ltr" title="{{ __('admin.branch') }}">{{ $item->branchCode }}</span>@endif
                                        </div>
                                        @if ($item->detail !== '')<p class="mt-0.5 truncate text-sm text-ink-500 dark:text-ink-400">{{ $item->detail }}</p>@endif
                                        @if ($item->note)<p class="mt-1 text-xs {{ $state === 'ok' ? 'text-ink-500 dark:text-ink-400' : 'text-amber-soft dark:text-amber-300' }}">{{ $item->note }}</p>@endif
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                                    @if ($item->age)
                                        <x-admin.ops.age-chip :state="$item->age->state" :label="$item->age->label"
                                            :title="__('ops_live.chip.title', ['state' => __('ops_live.state.'.$item->age->state), 'age' => $item->age->label]).($item->age->paused ? ' · '.__('ops_live.chip.paused') : '').($item->age->wallMinutes !== $item->age->ageMinutes ? ' · '.__('ops_live.chip.real', ['age' => \App\Domain\Operations\Services\OpsClock::label($item->age->wallMinutes)]) : '')" />
                                    @endif
                                    @if ($tel)
                                        <a href="tel:{{ $tel }}" class="btn-ghost btn-sm min-h-11 min-w-11" aria-label="{{ $item->call['label'] }} ({{ $item->call['phone'] }})" title="{{ $item->call['label'] }} · {{ $item->call['phone'] }}"><x-icon name="phone" class="size-4" /></a>
                                    @endif
                                    @php $btn = $state === 'ok' ? 'btn-secondary' : 'btn-primary'; @endphp
                                    @if ($a['type'] === 'peek')
                                        <button type="button" wire:click="peekOrder('{{ $a['order'] }}')" class="{{ $btn }} btn-sm max-md:min-h-11">{{ $a['label'] }}</button>
                                    @elseif ($a['type'] === 'reissue')
                                        <button type="button" wire:click="reissuePass('{{ $a['fulfillment'] }}')" wire:loading.attr="disabled" wire:target="reissuePass('{{ $a['fulfillment'] }}')" class="{{ $btn }} btn-sm max-md:min-h-11"><x-icon name="qr" class="size-4" />{{ $a['label'] }}</button>
                                    @else
                                        <a href="{{ $a['url'] }}" wire:navigate class="{{ $btn }} btn-sm max-md:min-h-11">{{ $a['label'] }}<x-icon name="arrow-right" class="size-4 rtl-flip" /></a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
            @if ($list->truncated)<p class="px-1 text-xs text-ink-500">{{ __('ops_live.page.truncated') }}</p>@endif
        @endif
    </div>

    @include('livewire.admin.sales.partials.order-peek')
</div>
