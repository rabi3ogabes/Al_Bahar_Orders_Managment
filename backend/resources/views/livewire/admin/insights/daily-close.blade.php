@php
    use App\Support\BusinessTime;
    use App\Support\Money;
    use Carbon\CarbonImmutable;

    $money = fn ($v) => Money::of((string) $v)->format();
    $day = CarbonImmutable::parse($date);
    $dayLong = $day->locale(app()->getLocale())->translatedFormat('l d M Y');
    $question = match (true) {
        $date === $today => __('daily_close.q_today'),
        $date === BusinessTime::now()->subDay()->toDateString() => __('daily_close.q_yesterday'),
        default => __('daily_close.q_date', ['date' => $day->locale(app()->getLocale())->translatedFormat('l d M')]),
    };
    // One look per state: an icon and a word, never colour alone.
    $look = [
        'closed' => ['check', 'badge-success', __('daily_close.state_closed')],
        'ready' => ['clock', 'badge-warning', __('daily_close.state_ready')],
        'in_progress' => ['timer', 'badge-neutral', __('daily_close.state_open')],
    ];
    $pill = fn (bool $on) => 'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition '.($on
        ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950'
        : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800');
    $flagText = fn (string $key, $value) => match ($key) {
        'cash_with_collectors' => __('daily_close.flag_cash', ['amount' => $money($value)]),
        default => trans_choice('daily_close.flag_'.$key, (int) $value, ['count' => (int) $value]),
    };
@endphp
<div>
    <x-admin.page-header :title="$question" :eyebrow="__('nav.insights')" :description="__('daily_close.hint')">
        <x-slot:actions>
            @if ($board !== [])
                <a href="{{ route('admin.daily-close.print', array_filter(['date' => $date, 'branch' => $branch])) }}" target="_blank" rel="noopener" class="btn-secondary min-h-11" data-testid="print"><x-icon name="printer" class="size-4" />{{ __('daily_close.print') }}</a>
            @endif
            @if ($canSettings)
                <button type="button" class="btn-ghost min-h-11" wire:click="$set('showSettings', true)" aria-label="{{ __('daily_close.settings') }}" title="{{ __('daily_close.settings') }}" data-testid="open-settings"><x-icon name="sliders" class="size-4" />{{ __('daily_close.settings') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Which day, which branch: the choice lives in the address, so Back and sharing work. --}}
    <div class="mb-5 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('daily_close.pick_day') }}">
        <button type="button" wire:click="pick('yesterday')" aria-pressed="{{ $date === BusinessTime::now()->subDay()->toDateString() ? 'true' : 'false' }}" class="{{ $pill($date === BusinessTime::now()->subDay()->toDateString()) }}">{{ __('daily_close.yesterday') }}</button>
        <button type="button" wire:click="pick('today')" aria-pressed="{{ $date === $today ? 'true' : 'false' }}" class="{{ $pill($date === $today) }}">{{ __('daily_close.today') }}</button>
        <label class="inline-flex items-center gap-2"><span class="sr-only">{{ __('daily_close.date') }}</span>
            <input type="date" wire:model.live="date" min="{{ $earliest }}" max="{{ $today }}" class="input w-auto min-h-11" data-testid="date"></label>
        @if ($branchOptions->count() > 1)
            <select wire:model.live="branch" class="input min-h-11 sm:w-56" aria-label="{{ __('admin.branch') }}" data-testid="branch"><option value="">{{ __('admin.all_my_branches') }}</option>@foreach ($branchOptions as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
        @endif
        <span wire:loading.delay wire:target="date, pick, branch"><x-icon name="refresh" class="size-4 animate-spin text-brass-500" /></span>
    </div>

    <div wire:loading.class="opacity-60" wire:target="date, pick, branch">
        @if ($board === [])
            <div class="card"><x-admin.empty :title="__('daily_close.empty_title')" :description="__('daily_close.empty_hint')" icon="store" data-empty="branches" /></div>
        @else
            {{-- The answer, first: what the day sold, and who closed it. --}}
            <section class="card card-pad mb-6" aria-labelledby="dc-hero" data-testid="close-hero">
                <p id="dc-hero" class="text-sm text-ink-500 dark:text-ink-400">{{ __('daily_close.hero_label', ['day' => $dayLong]) }}</p>
                <p class="mt-1 font-display text-4xl font-semibold tracking-tight tabular-nums text-ink-900 sm:text-5xl dark:text-ivory" dir="ltr" data-testid="hero-sales">{{ $sales->format() }}</p>
                <p class="mt-1 text-sm text-ink-600 dark:text-ink-300">{{ trans_choice('daily_close.hero_orders', $orders, ['count' => number_format($orders)]) }}</p>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <span class="{{ $tally['closed'] === $tally['total'] ? 'badge-success' : 'badge-warning' }}" data-testid="tally"><x-icon :name="$tally['closed'] === $tally['total'] ? 'check' : 'clock'" class="size-3.5" />{{ __('daily_close.tally', ['closed' => $tally['closed'], 'total' => $tally['total']]) }}</span>
                    @php $open = collect($board)->sum(fn ($b) => $b['flags'] === [] ? 0 : 1); @endphp
                    @if ($open > 0)<span class="badge-warning"><x-icon name="alert" class="size-3.5" />{{ trans_choice('daily_close.hero_open_points', $open, ['count' => $open]) }}</span>@endif
                </div>
            </section>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
                @foreach ($board as $b)
                    @php [$icon, $badge, $word] = $look[$b['state']]; $n = $b['numbers']; @endphp
                    <section class="card card-pad flex flex-col" wire:key="dc-{{ $b['branch']->id }}" aria-labelledby="dc-b-{{ $b['branch']->id }}" data-branch="{{ $b['branch']->code }}" data-state="{{ $b['state'] }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="dc-b-{{ $b['branch']->id }}" class="truncate font-display text-xl font-semibold">{{ $b['branch']->localized('name') }}</h2>
                                <p class="font-mono text-xs text-ink-500" dir="ltr">{{ $b['branch']->code }}</p>
                            </div>
                            <span class="{{ $badge }} shrink-0"><x-icon :name="$icon" class="size-3.5" />{{ $word }}</span>
                        </div>
                        <p class="mt-4 font-display text-3xl font-semibold tabular-nums" dir="ltr">{{ $money($n['sales']['total']) }}</p>
                        <p class="text-sm text-ink-500">{{ trans_choice('daily_close.card_orders', $n['sales']['orders'], ['count' => $n['sales']['orders'], 'delivered' => $n['orders']['delivered'], 'cancelled' => $n['orders']['cancelled']]) }}</p>
                        <ul class="mt-4 flex flex-1 flex-col gap-2 text-sm" aria-label="{{ __('daily_close.open_points') }}">
                            @forelse ($b['flags'] as $key => $value)
                                <li class="flex items-start gap-2 text-amber-soft dark:text-amber-300"><x-icon name="alert" class="mt-0.5 size-4 shrink-0" /><span class="text-ink-800 dark:text-sand-100">{{ $flagText($key, $value) }}</span></li>
                            @empty
                                <li class="flex items-start gap-2"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-soft" /><span class="text-ink-600 dark:text-ink-300">{{ __('daily_close.no_open_points') }}</span></li>
                            @endforelse
                        </ul>
                        @if ($b['state'] === 'closed')
                            <p class="mt-4 text-xs text-ink-500">{{ __('daily_close.closed_by', ['name' => $b['close']->closer?->name ?? '—', 'at' => BusinessTime::display($b['close']->closed_at, 'd M, H:i')]) }}</p>
                        @elseif ($b['state'] === 'in_progress')
                            <p class="mt-4 text-xs text-ink-500">{{ __('daily_close.closes_at', ['time' => BusinessTime::display($b['closes_at'], 'H:i')]) }}</p>
                        @endif
                        <button type="button" wire:click="openReview('{{ $b['branch']->id }}')" class="{{ $b['can_close'] ? 'btn-primary' : 'btn-secondary' }} mt-4 min-h-11 w-full justify-center" data-testid="review-{{ $b['branch']->code }}">
                            {{ $b['can_close'] ? __('daily_close.review_and_close') : __('daily_close.review') }}
                        </button>
                    </section>
                @endforeach
            </div>
        @endif
    </div>

    {{-- The day of one branch, in a side window: every figure, then the sign-off. --}}
    <x-admin.dialog wire:model.live="showReview" :title="$reviewed ? $reviewed['branch']->localized('name').' · '.$day->locale(app()->getLocale())->translatedFormat('d M Y') : ''" max-width="max-w-2xl">
        @if ($reviewed)
            @php $n = $reviewed['numbers']; [$icon, $badge, $word] = $look[$reviewed['state']]; $mix = $n['payment_mix']; @endphp
            <div class="mb-5 flex flex-wrap items-center gap-2" data-testid="review-state">
                <span class="{{ $badge }}"><x-icon :name="$icon" class="size-3.5" />{{ $word }}</span>
                @if ($reviewed['state'] !== 'closed')<span class="text-xs text-ink-500">{{ $reviewed['state'] === 'ready' ? __('daily_close.shut_since', ['time' => BusinessTime::display($reviewed['closes_at'], 'H:i')]) : __('daily_close.closes_at', ['time' => BusinessTime::display($reviewed['closes_at'], 'H:i')]) }}</span>@endif
                @if ($reviewed['state'] === 'closed')<span class="text-xs text-ink-500">{{ __('daily_close.frozen') }}</span>@endif
            </div>

            @foreach ([
                'orders' => [
                    [__('daily_close.l_placed'), number_format($n['orders']['placed']), null],
                    [__('daily_close.l_delivered'), number_format($n['orders']['delivered']), null],
                    [__('daily_close.l_cancelled'), number_format($n['orders']['cancelled']), null],
                    [__('daily_close.l_returned'), number_format($n['orders']['returned']), null],
                    [__('daily_close.l_open'), number_format($n['open']['open']), null],
                    [__('daily_close.l_never_accepted'), number_format($n['open']['never_accepted']), $n['open']['never_accepted'] > 0],
                ],
                'sales' => [
                    [__('daily_close.l_sales'), $money($n['sales']['total']), null],
                    [__('daily_close.l_basket'), $money($n['sales']['average']), null],
                    [__('daily_close.l_mix_cod'), $money($mix['cod']['amount']).' · '.trans_choice('daily_close.orders_n', $mix['cod']['orders'], ['count' => $mix['cod']['orders']]), null],
                    [__('daily_close.l_mix_sadad'), $money($mix['sadad']['amount']).' · '.trans_choice('daily_close.orders_n', $mix['sadad']['orders'], ['count' => $mix['sadad']['orders']]), null],
                    [__('daily_close.l_mix_pickup'), $money($mix['pay_at_pickup']['amount']).' · '.trans_choice('daily_close.orders_n', $mix['pay_at_pickup']['orders'], ['count' => $mix['pay_at_pickup']['orders']]), null],
                ],
                'cash' => [
                    [__('daily_close.l_cash_collected'), $money($n['cash']['collected']), null],
                    [__('daily_close.l_cash_handed_in'), $money($n['cash']['handed_in']), null],
                    [__('daily_close.l_cash_with'), $money($n['cash']['with_collectors']), ! Money::of($n['cash']['with_collectors'])->isZero()],
                    [__('daily_close.l_cash_out_total'), $money($n['cash']['still_out_total']), null],
                    [__('daily_close.l_cash_variance'), $money($n['cash']['variance']), ! Money::of($n['cash']['variance'])->isZero()],
                    [__('daily_close.l_cash_open_variances'), number_format($n['cash']['open_variances']), $n['cash']['open_variances'] > 0],
                ],
                'problems' => [
                    [__('daily_close.l_refunds'), $money($n['refunds']['amount']).' · '.trans_choice('daily_close.refunds_n', $n['refunds']['count'], ['count' => $n['refunds']['count']]), null],
                    [__('daily_close.l_failed'), number_format($n['failed_deliveries']), null],
                ],
                'speed' => [
                    [__('daily_close.l_accept'), $n['speed']['accept_minutes'] === null ? __('daily_close.none') : __('daily_close.minutes', ['n' => $n['speed']['accept_minutes']]).' · '.trans_choice('daily_close.orders_n', $n['speed']['accepted'], ['count' => $n['speed']['accepted']]), null],
                    [__('daily_close.l_prepare'), $n['speed']['prepare_minutes'] === null ? __('daily_close.none') : __('daily_close.minutes', ['n' => $n['speed']['prepare_minutes']]).' · '.trans_choice('daily_close.orders_n', $n['speed']['prepared'], ['count' => $n['speed']['prepared']]), null],
                ],
                'stock' => [
                    [__('daily_close.l_stock_out'), number_format($n['stock']['out']), null],
                    [__('daily_close.l_stock_low'), number_format($n['stock']['low']), null],
                ],
            ] as $section => $lines)
                <section class="mb-5" aria-labelledby="dc-s-{{ $section }}">
                    <h3 id="dc-s-{{ $section }}" class="mb-1 font-display text-lg font-semibold">{{ __('daily_close.s_'.$section) }}</h3>
                    <dl class="divide-y divide-sand-100 rounded-xl border border-sand-100 px-4 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($lines as [$label, $value, $warn])
                            <div class="flex items-baseline justify-between gap-4 py-2.5 text-sm"><dt class="text-ink-600 dark:text-ink-300">{{ $label }}</dt>
                                <dd class="text-end font-medium tabular-nums {{ $warn ? 'text-amber-soft dark:text-amber-300' : 'text-ink-900 dark:text-ivory' }}" dir="ltr">@if ($warn)<x-icon name="alert" class="me-1 inline size-3.5 align-[-2px]" />@endif{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    <p class="mt-1.5 text-xs text-ink-500">{{ __('daily_close.h_'.$section) }}</p>
                </section>
            @endforeach

            @if ($reviewed['state'] === 'closed')
                <div class="rounded-xl bg-sand-50 p-4 text-sm dark:bg-ink-800" data-testid="closed-note">
                    <p class="font-medium">{{ __('daily_close.closed_by', ['name' => $reviewed['close']->closer?->name ?? '—', 'at' => BusinessTime::display($reviewed['close']->closed_at, 'd M Y, H:i')]) }}</p>
                    <p class="mt-1 text-ink-600 dark:text-ink-300">{{ $reviewed['close']->note ?: __('daily_close.no_note') }}</p>
                </div>
                @if ($canSettings)
                    <div class="mt-4"><button type="button" class="btn-secondary min-h-11" wire:click="askReopen" data-testid="reopen"><x-icon name="refresh" class="size-4" />{{ __('daily_close.reopen') }}</button></div>
                @endif
            @elseif ($reviewed['can_close'])
                <form wire:submit="closeDay" class="space-y-4" data-testid="close-form">
                    <x-admin.field :label="__('daily_close.note')" for="note" :required="$reviewed['needs_note']" :hint="$reviewed['needs_note'] ? __('daily_close.note_needed') : __('daily_close.note_optional')">
                        <textarea id="note" wire:model="note" rows="3" maxlength="1000" class="input" placeholder="{{ __('daily_close.note_placeholder') }}"></textarea>
                    </x-admin.field>
                    <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                        <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                        <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="closeDay" data-testid="close-day"><x-icon name="check" class="size-4" />{{ __('daily_close.close_day') }}</button>
                    </div>
                </form>
            @else
                <p class="rounded-xl bg-sand-50 p-4 text-sm text-ink-600 dark:bg-ink-800 dark:text-ink-300" data-testid="cannot-close">
                    {{ $reviewed['state'] === 'in_progress' ? __('daily_close.cannot_yet', ['time' => BusinessTime::display($reviewed['closes_at'], 'H:i')]) : __('daily_close.cannot_manager') }}
                </p>
            @endif
        @endif
    </x-admin.dialog>

    <x-admin.dialog variant="modal" wire:model="showReopen" max-width="max-w-lg" :title="__('daily_close.reopen_q')" :description="__('daily_close.reopen_hint')">
        <form wire:submit="reopenDay" class="space-y-4">
            <x-admin.field :label="__('admin.reason')" for="reopenReason" required><textarea id="reopenReason" wire:model="reopenReason" rows="3" class="input"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                <button class="btn-danger min-h-11" wire:loading.attr="disabled" wire:target="reopenDay" data-testid="reopen-confirm">{{ __('daily_close.reopen') }}</button>
            </div>
        </form>
    </x-admin.dialog>

    @if ($canSettings)
        <x-admin.dialog wire:model="showSettings" max-width="max-w-lg" :title="__('daily_close.settings')" :description="__('daily_close.settings_hint')">
            <form wire:submit="saveSettings" class="space-y-5">
                <x-admin.field :label="__('daily_close.grace_label')" for="settingsForm.grace_minutes" :hint="__('daily_close.grace_hint')" required>
                    <input id="settingsForm.grace_minutes" type="number" min="0" max="240" wire:model="settingsForm.grace_minutes" class="input tabular-nums sm:max-w-[10rem]" dir="ltr" inputmode="numeric">
                </x-admin.field>
                <x-admin.field :label="__('daily_close.fallback_label')" for="settingsForm.fallback_time" :hint="__('daily_close.fallback_hint')" required>
                    <input id="settingsForm.fallback_time" type="time" wire:model="settingsForm.fallback_time" class="input sm:max-w-[10rem]" dir="ltr">
                </x-admin.field>
                <p class="text-xs text-ink-500">{{ __('daily_close.settings_default', ['grace' => \App\Domain\Reports\Services\DailyCloseService::DEFAULT_GRACE_MINUTES, 'time' => \App\Domain\Reports\Services\DailyCloseService::DEFAULT_FALLBACK_TIME]) }}</p>
                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                    <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                    <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="saveSettings">{{ __('admin.save_changes') }}</button>
                </div>
            </form>
        </x-admin.dialog>
    @endif
</div>
