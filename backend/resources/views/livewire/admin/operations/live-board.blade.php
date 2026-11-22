@php
    use App\Domain\Operations\Services\LiveBoard;

    // One look per lane: an icon and a tone, so a lane is known by its shape and its words, never by colour alone.
    $look = [
        'new' => ['bag', 'bg-sky-soft/10 text-sky-soft dark:text-sky-300'],
        'preparing' => ['package', 'bg-amber-soft/10 text-amber-soft dark:text-amber-300'],
        'ready' => ['check', 'bg-brass-50 text-brass-700 dark:bg-brass-500/15 dark:text-brass-300'],
        'out' => ['truck', 'bg-emerald-soft/10 text-emerald-soft dark:text-emerald-300'],
        'pickup' => ['qr', 'bg-sand-100 text-ink-700 dark:bg-ink-800 dark:text-sand-200'],
        'attention' => ['alert', 'bg-ruby-soft/10 text-ruby-soft dark:text-red-300'],
    ];
    $pill = fn (bool $on) => 'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition '.($on
        ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950'
        : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800');
    $multi = count($board->branches) > 1 && $branch === '';
    $names = collect($board->branches)->mapWithKeys(fn ($b) => [$b['id'] => $b['name']]);
    $codes = collect($board->branches)->mapWithKeys(fn ($b) => [$b['id'] => $b['code']]);
    $one = collect($board->branches)->firstWhere('id', $branch);
    $wallUrl = fn (bool $on) => route('admin.operations.board', array_filter(['branch' => $branch, 'wall' => $on ? 1 : null]));
    $boot = [
        'channels' => $channels,
        'poll' => ['live' => 30, 'down' => 10],
        'wall' => $wall,
        'text' => [
            'live' => __('ops_board.link.live'), 'reconnecting' => __('ops_board.link.reconnecting'), 'polling' => __('ops_board.link.polling', ['s' => ':s']), 'stale' => __('ops_board.link.stale'),
            'sound_on' => __('ops_board.sound_on'), 'sound_off' => __('ops_board.sound_off'), 'fullscreen' => __('ops_board.wall.fullscreen'), 'leave_fullscreen' => __('ops_board.wall.leave_fullscreen'),
        ],
    ];
    $tools = 'btn-secondary min-h-11';
@endphp
<div x-data="opsBoard(@js($boot))" x-on:ops-order-live.window="kick()" data-board data-rendered="{{ $renderedAt->getTimestamp() }}" class="{{ $wall ? 'flex min-h-[calc(100dvh-4rem)] flex-col' : '' }}">

    {{-- Title, how fresh the board is, and the wall display --}}
    @if ($wall)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-x-6 gap-y-3">
            <div class="flex min-w-0 items-center gap-4">
                <h1 class="font-display text-3xl font-semibold tracking-tight text-ink-900 dark:text-ivory">{{ __('ops_board.page.title') }}@if ($one) <span class="ms-2 text-ink-500 dark:text-ink-400" dir="auto">{{ $one['name'] }}</span>@endif</h1>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                @include('livewire.admin.operations.partials.board-summary', ['counts' => $counts, 'big' => true])
                <span class="font-display text-3xl font-semibold tabular-nums text-ink-900 dark:text-ivory" x-text="clock" dir="ltr" aria-hidden="true"></span>
                @include('livewire.admin.operations.partials.board-link', ['big' => true])
                @unless ($wallOnly)
                <button type="button" x-on:click="toggleSound()" x-bind:aria-pressed="sound ? 'true' : 'false'" x-bind:aria-label="sound ? text.sound_off : text.sound_on" x-bind:title="sound ? text.sound_off : text.sound_on" class="{{ $tools }} size-11 p-0">
                    <svg class="size-5 rtl-flip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9v6h4l5 4V5L8 9H4z"/><path x-show="sound" d="M16 9a4 4 0 0 1 0 6"/><path x-show="! sound" d="m17 9 4 6M21 9l-4 6"/></svg>
                </button>
                @endunless
                <button type="button" x-on:click="toggleFullscreen()" x-bind:aria-pressed="fullscreen ? 'true' : 'false'" x-bind:aria-label="fullscreen ? text.leave_fullscreen : text.fullscreen" x-bind:title="fullscreen ? text.leave_fullscreen : text.fullscreen" class="{{ $tools }} size-11 p-0">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
                </button>
                @if ($wallOnly)
                    {{-- The screen has no other page to go to: it signs out, and that is the only way out. --}}
                    <form method="POST" action="{{ route('admin.logout') }}" data-wall-signout>@csrf
                        <button class="{{ $tools }}"><x-icon name="logout" class="size-4 rtl-flip" />{{ __('wall_display.sign_out') }}</button>
                    </form>
                @else
                    <a href="{{ $wallUrl(false) }}" wire:navigate class="{{ $tools }}"><x-icon name="x" class="size-4" />{{ __('ops_board.wall.exit') }}</a>
                @endif
            </div>
        </div>
        <p class="sr-only">{{ __('ops_board.wall.read_only') }}</p>
    @else
        <x-admin.page-header :title="__('ops_board.page.title')" :eyebrow="__('ops_board.page.eyebrow')" class="max-sm:mb-4">
            <x-slot:actions>
                @include('livewire.admin.operations.partials.board-link', ['big' => false])
                <button type="button" x-on:click="kick(true)" class="btn-ghost btn-sm min-h-11" aria-label="{{ __('ops_board.refresh') }}" title="{{ __('ops_board.refresh') }}"><x-icon name="refresh" class="size-4" wire:loading.class="animate-spin" wire:target="$refresh" /></button>
                <a href="{{ $wallUrl(true) }}" wire:navigate class="btn-secondary min-h-11" title="{{ __('ops_board.wall.enter_hint') }}"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>{{ __('ops_board.wall.enter') }}</a>
            </x-slot:actions>
        </x-admin.page-header>

        <div class="mb-4 flex flex-wrap items-center gap-2" aria-live="polite">
            @include('livewire.admin.operations.partials.board-summary', ['counts' => $counts, 'big' => false])
        </div>
        @if (count($board->branches) > 1)
            {{-- Phones: one box instead of two rows of chips, so the lanes start higher up the first screen. --}}
            <label class="mb-3 block sm:hidden"><span class="sr-only">{{ __('ops_board.branch') }}</span>
                <select wire:model.live="branch" class="input min-h-11">
                    <option value="">{{ __('ops_board.branch_all') }}</option>
                    @foreach ($board->branches as $b)<option value="{{ $b['id'] }}" dir="auto">{{ $b['code'] }} · {{ $b['name'] }}</option>@endforeach
                </select>
            </label>
            <div class="mb-4 flex flex-wrap items-center gap-2 max-sm:hidden" role="group" aria-label="{{ __('ops_board.branch') }}">
                <button type="button" wire:click="setBranch('')" aria-pressed="{{ $branch === '' ? 'true' : 'false' }}" class="{{ $pill($branch === '') }}">{{ __('ops_board.branch_all') }}</button>
                @foreach ($board->branches as $b)
                    <button type="button" wire:click="setBranch('{{ $b['id'] }}')" wire:key="bchip-{{ $b['id'] }}" aria-pressed="{{ $branch === $b['id'] ? 'true' : 'false' }}" title="{{ $b['name'] }}" class="{{ $pill($branch === $b['id']) }}">
                        <x-icon name="store" class="size-4" /><span dir="ltr" class="font-mono">{{ $b['code'] }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Phones: jump to a lane. The lanes themselves slide sideways, one at a time. --}}
        <nav class="relative -mx-1 mb-3 flex gap-2 overflow-x-auto px-1 pb-1 sm:hidden" aria-label="{{ __('ops_board.jump') }}">
            @foreach ($board->lanes as $key => $lane)
                <button type="button" x-on:click="goTo('{{ $key }}')" class="inline-flex min-h-11 shrink-0 items-center gap-2 rounded-full border border-sand-300 bg-white px-3.5 text-sm font-medium dark:border-ink-600 dark:bg-ink-800">
                    <x-icon :name="$look[$key][0]" class="size-4" />{{ __('ops_board.lane.'.$key.'.title') }}<span class="tabular-nums text-ink-500 dark:text-ink-400">{{ $lane['total'] }}</span>@if ($lane['red'] > 0)<span class="badge-danger tabular-nums"><x-icon name="alert" class="size-3.5" />{{ $lane['red'] }}<span class="sr-only">{{ __('ops_live.state.red') }}</span></span>@endif
                </button>
            @endforeach
        </nav>
    @endif

    @if ($board->open === 0)
        <div class="mb-4 rounded-2xl border border-dashed border-sand-300 bg-white/60 px-5 py-6 text-center dark:border-ink-600 dark:bg-ink-900/40" role="status" data-empty="clear">
            <p class="font-display text-xl font-semibold text-ink-900 dark:text-ivory {{ $wall ? 'text-3xl' : '' }}">{{ __('ops_board.clear_title') }}</p>
            <p class="mt-1 text-sm text-ink-500 dark:text-ink-400 {{ $wall ? 'text-lg' : '' }}">{{ __('ops_board.clear_hint') }}</p>
        </div>
    @endif
    @if ($board->truncated)<p class="mb-3 px-1 text-xs text-amber-soft dark:text-amber-300">{{ __('ops_board.truncated') }}</p>@endif

    {{-- The lanes, left to right (right to left in Arabic) --}}
    <div x-ref="lanes" wire:loading.class="opacity-50" wire:target="setBranch, branch" class="relative flex gap-3 overflow-x-auto pb-3 [scrollbar-width:thin] max-sm:snap-x max-sm:snap-mandatory {{ $wall ? 'min-h-0 flex-1 items-stretch' : 'items-start' }}">
        @foreach ($board->lanes as $key => $lane)
            <section data-lane-box="{{ $key }}" aria-labelledby="lane-{{ $key }}" wire:key="lane-{{ $key }}"
                     class="flex flex-col rounded-2xl bg-sand-50/70 p-2.5 ring-1 ring-sand-200/70 max-sm:snap-start dark:bg-ink-900/40 dark:ring-ink-700 {{ $wall ? 'min-h-0 min-w-[13rem] flex-1' : 'w-[86vw] shrink-0 sm:w-72 xl:min-w-[15rem] xl:flex-1' }} {{ $key === 'attention' ? 'ring-ruby-soft/40' : '' }}">
                <header class="flex items-start gap-2.5 px-1.5 pb-2.5 pt-1">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl {{ $look[$key][1] }}"><x-icon :name="$look[$key][0]" class="size-[1.15rem]" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 id="lane-{{ $key }}" class="font-display font-semibold leading-tight {{ $wall ? 'text-xl' : 'text-lg' }}">{{ __('ops_board.lane.'.$key.'.title') }}
                            <span class="ms-1 font-sans font-normal tabular-nums text-ink-500 dark:text-ink-400 {{ $wall ? 'text-lg' : 'text-sm' }}" data-lane-count><span aria-hidden="true">{{ $lane['total'] }}</span><span class="sr-only">{{ trans_choice('ops_board.lane_count', $lane['total'], ['count' => $lane['total']]) }}</span></span></h2>
                        <p class="text-ink-500 dark:text-ink-400 {{ $wall ? 'text-sm' : 'text-xs' }}">{{ __('ops_board.lane.'.$key.'.hint') }}</p>
                    </div>
                    @if ($lane['red'] > 0 || $lane['amber'] > 0)
                        <span class="flex shrink-0 flex-col items-end gap-1">
                            @if ($lane['red'] > 0)<span class="badge-danger tabular-nums"><x-icon name="alert" class="size-3.5" />{{ $lane['red'] }}<span class="sr-only">{{ __('ops_live.state.red') }}</span></span>@endif
                            @if ($lane['amber'] > 0)<span class="badge-warning tabular-nums"><x-icon name="clock" class="size-3.5" />{{ $lane['amber'] }}<span class="sr-only">{{ __('ops_live.state.amber') }}</span></span>@endif
                        </span>
                    @endif
                </header>

                <div class="space-y-2.5 {{ $wall ? 'min-h-0 flex-1 overflow-y-auto pe-1' : '' }}">
                    @forelse ($lane['cards']->groupBy('branchId') as $branchId => $group)
                        @if ($multi)
                            <h3 class="flex items-center gap-2 px-1.5 pt-1 text-xs font-semibold uppercase tracking-wider text-ink-500 dark:text-ink-400 {{ $wall ? 'text-sm' : '' }}"><x-icon name="store" class="size-3.5" /><span class="font-mono" dir="ltr">{{ $codes[$branchId] ?? '' }}</span><span class="truncate normal-case tracking-normal" dir="auto">{{ $names[$branchId] ?? '' }}</span><span class="ms-auto tabular-nums">{{ $group->count() }}</span></h3>
                        @endif
                        @foreach ($group as $card)
                            <x-admin.ops.board-card :card="$card" :wall="$wall" />
                        @endforeach
                    @empty
                        <p class="rounded-xl border border-dashed border-sand-300 px-3 py-6 text-center text-sm text-ink-500 dark:border-ink-600 dark:text-ink-400 {{ $wall ? 'text-lg' : '' }}">{{ __('ops_board.empty.'.$key) }}</p>
                    @endforelse
                </div>

                @if ($lane['more'] > 0 && $wall)
                    <p class="mt-2 px-1.5 text-center text-lg text-ink-500 dark:text-ink-400">+{{ $lane['more'] }}</p>
                @elseif ($lane['more'] > 0 && ! in_array($key, $open, true))
                    <button type="button" wire:click="toggleLane('{{ $key }}')" class="btn-ghost mt-2 min-h-11 w-full">{{ __('ops_board.more', ['n' => $lane['more']]) }}</button>
                @elseif ($lane['more'] > 0)
                    <p class="mt-2 px-1.5 text-center text-xs text-ink-500 dark:text-ink-400">{{ __('ops_board.see_orders') }}</p>
                    <a href="{{ route('admin.orders.index') }}" wire:navigate class="btn-secondary mt-1 min-h-11 w-full">{{ __('nav.orders') }}<x-icon name="arrow-right" class="size-4 rtl-flip" /></a>
                @endif
                @if (! $wall && in_array($key, $open, true) && $lane['total'] > LiveBoard::SHOWN)
                    <button type="button" wire:click="toggleLane('{{ $key }}')" class="btn-ghost mt-1 min-h-11 w-full">{{ __('ops_board.less') }}</button>
                @endif
            </section>
        @endforeach
    </div>

    @unless ($wall)
        @include('livewire.admin.sales.partials.order-peek')
    @endunless
</div>
