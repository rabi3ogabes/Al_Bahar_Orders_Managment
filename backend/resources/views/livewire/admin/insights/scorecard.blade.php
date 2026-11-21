@php
    use App\Domain\Reports\Services\ScorecardBranchNumbers;
    use App\Domain\Reports\Services\ScorecardCauses;
    use App\Domain\Reports\Services\ScorecardDriverNumbers;
    use App\Domain\Reports\Services\ScorecardFormat;
    use App\Domain\Reports\Services\ScorecardStats;
    use App\Support\BusinessTime;
    use App\Support\Money;
    use Carbon\CarbonImmutable;

    $locale = app()->getLocale();
    $date = fn (string $d, string $format = 'j M Y') => CarbonImmutable::parse($d)->locale($locale)->translatedFormat($format);
    $span = fn (string $a, string $b) => $date($a, substr($a, 0, 4) === substr($b, 0, 4) ? 'j M' : 'j M Y').' – '.$date($b, 'j M Y');
    $money = fn ($v) => Money::of((string) $v)->format();
    $metricOf = ScorecardFormat::asMetric(...);
    $pill = fn (bool $on) => 'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition '.($on
        ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950'
        : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800');
    $side = fn (string $reason) => ScorecardCauses::sideOf($reason);
    $reasonLine = fn (array $reasons, callable $label) => collect($reasons)->map(fn ($n, $code) => $label((string) $code).' · '.$n)->implode(' · ');
    $nameOf = fn (array $b) => $locale === 'ar' ? $b['name_ar'] : $b['name_en'];
@endphp
<div>
    <x-admin.page-header :title="$tab === 'drivers' ? __('scorecard.q_drivers') : __('scorecard.q_branches')" :eyebrow="__('nav.insights')" :description="__('scorecard.hint')">
        <x-slot:actions>
            <button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh" class="btn-ghost min-h-11" data-testid="refresh"><x-icon name="refresh" class="size-4" />{{ __('scorecard.refresh') }}</button>
            @if ($canExport)
                <button type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export" class="btn-secondary min-h-11" data-testid="export"><x-icon name="download" class="size-4" />{{ __('scorecard.export') }}</button>
            @endif
            @if ($canSettings)
                <button type="button" wire:click="$set('showSettings', true)" class="btn-ghost min-h-11" data-testid="open-settings"><x-icon name="sliders" class="size-4" />{{ __('scorecard.settings') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- Which period, which branch: the choice lives in the address, so Back and sharing work. --}}
    <div class="mb-3 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('scorecard.period') }}">
        @foreach (['7', '30', '90'] as $r)
            <button type="button" wire:click="setRange('{{ $r }}')" aria-pressed="{{ $range === $r ? 'true' : 'false' }}" class="{{ $pill($range === $r) }}" data-range="{{ $r }}">{{ __('scorecard.last_'.$r) }}</button>
        @endforeach
        <button type="button" wire:click="setRange('custom')" aria-pressed="{{ $range === 'custom' ? 'true' : 'false' }}" class="{{ $pill($range === 'custom') }}" data-range="custom">{{ __('scorecard.custom') }}</button>
        @if ($range === 'custom')
            <label class="inline-flex items-center gap-2"><span class="text-sm text-ink-600 dark:text-ink-300">{{ __('scorecard.from') }}</span>
                <input type="date" wire:model.live.debounce.400ms="from" max="{{ $today }}" class="input w-auto min-h-11" data-testid="from"></label>
            <label class="inline-flex items-center gap-2"><span class="text-sm text-ink-600 dark:text-ink-300">{{ __('scorecard.to') }}</span>
                <input type="date" wire:model.live.debounce.400ms="to" max="{{ $today }}" class="input w-auto min-h-11" data-testid="to"></label>
        @endif
        @if ($branchOptions->count() > 1)
            <select wire:model.live="branch" class="input min-h-11 sm:w-56" aria-label="{{ __('admin.branch') }}" data-testid="branch"><option value="">{{ __('scorecard.all_my_branches') }}</option>@foreach ($branchOptions as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
        @endif
        <span wire:loading.delay wire:target="setRange, from, to, branch, tab, refresh"><x-icon name="refresh" class="size-4 animate-spin text-brass-500" /></span>
    </div>
    <p class="mb-5 text-xs leading-relaxed text-ink-500 dark:text-ink-400" data-testid="period-note">
        <span class="font-medium text-ink-700 dark:text-sand-200" dir="ltr">{{ $span($period['from'], $period['to']) }}</span>
        · {{ __('scorecard.compared', ['range' => $span($period['previous_from'], $period['previous_to']), 'days' => $period['days']]) }}
        {{ $period['includes_today'] ? __('scorecard.includes_today') : __('scorecard.complete_days') }}
        @if ($notice)<span class="mt-1 block text-amber-soft dark:text-amber-300" role="status" data-testid="notice">{{ $notice }}</span>@endif
    </p>

    <x-admin.tabs :tabs="['branches' => ['store', __('scorecard.tab_branches')], 'drivers' => ['truck', __('scorecard.tab_drivers')]]" :current="$tab" aria-label="{{ __('scorecard.tabs') }}" />

    <div wire:loading.class="opacity-60" wire:target="setRange, from, to, branch, tab, refresh, sortBy">
        @if ($error)
            <div class="card" data-testid="error"><x-admin.empty :title="__('scorecard.error_title')" :description="__('scorecard.error_hint')" icon="alert">
                <button type="button" wire:click="$refresh" class="btn-primary min-h-11">{{ __('scorecard.retry') }}</button></x-admin.empty></div>
        @elseif ($branchOptions->isEmpty() || $data === null || ($tab === 'branches' && $data['rows'] === []))
            <div class="card"><x-admin.empty :title="__('scorecard.empty_title')" :description="__('scorecard.empty_hint')" icon="store" data-empty="branches" /></div>
        @elseif ($tab === 'branches')
            @php $head = $data['total'] ?? $data['rows'][0]; @endphp
            {{-- The answer first: all the branches the viewer sees, together (or the one branch). --}}
            <section class="card card-pad mb-6" aria-labelledby="sc-head" data-testid="head">
                <h2 id="sc-head" class="mb-4 font-display text-lg font-semibold">{{ $data['total'] !== null ? __('scorecard.together', ['count' => count($data['rows'])]) : __('scorecard.one_branch') }}</h2>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-5 lg:grid-cols-4">
                    <x-admin.score-metric :label="__('scorecard.m_orders')" :metric="$metricOf($head['orders'])" kind="count" :min="$data['min_sample']" big :note="__('scorecard.tip_orders')" />
                    <x-admin.score-metric :label="__('scorecard.m_accept').' · '.__('scorecard.median')" :metric="$head['accept']['median']" kind="minutes" :min="$data['min_sample']" big :note="__('scorecard.tip_accept')" />
                    <x-admin.score-metric :label="__('scorecard.m_on_time')" :metric="$head['delivery']['on_time']" kind="percent" :min="$data['min_sample']" big :note="__('scorecard.tip_on_time')"
                        :of="$head['delivery']['promised'] > 0 ? trans_choice('scorecard.deliveries_n', $head['delivery']['promised']) : null" />
                    <div class="min-w-0" data-testid="head-cash">
                        <dt class="text-sm text-ink-600 dark:text-ink-300">{{ __('scorecard.m_cash_net') }}</dt>
                        <dd class="mt-0.5"><span class="font-display text-3xl font-semibold tabular-nums text-ink-900 sm:text-4xl dark:text-ivory" dir="ltr">{{ $money($head['cash']['net']) }}</span>
                            <span class="block text-xs text-ink-500 dark:text-ink-400">{{ __('scorecard.variances_of', ['n' => $head['cash']['variances'], 'total' => $head['cash']['handins']]) }}</span></dd>
                        <p class="mt-1 text-xs leading-snug text-ink-500 dark:text-ink-400">{{ __('scorecard.tip_cash_net') }}</p>
                    </div>
                </dl>
            </section>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
                @foreach ($data['rows'] as $b)
                    <section class="card card-pad flex flex-col" wire:key="sc-b-{{ $b['id'] }}" aria-labelledby="sc-b-{{ $b['id'] }}" data-branch="{{ $b['code'] }}">
                        <div class="min-w-0">
                            <h2 id="sc-b-{{ $b['id'] }}" class="truncate font-display text-xl font-semibold">{{ $nameOf($b) }}</h2>
                            <p class="font-mono text-xs text-ink-500" dir="ltr">{{ $b['code'] }}</p>
                        </div>
                        <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-5">
                            <x-admin.score-metric :label="__('scorecard.m_orders')" :metric="$metricOf($b['orders'])" kind="count" :min="$data['min_sample']" data-metric="orders" :tip="__('scorecard.tip_orders')" />
                            <x-admin.score-metric :label="__('scorecard.m_on_time')" :metric="$b['delivery']['on_time']" kind="percent" :min="$data['min_sample']" data-metric="on_time" :tip="__('scorecard.tip_on_time')"
                                :of="$b['delivery']['promised'] > 0 ? trans_choice('scorecard.deliveries_n', $b['delivery']['promised']) : null" />
                            <x-admin.score-metric :label="__('scorecard.m_accept').' · '.__('scorecard.median')" :metric="$b['accept']['median']" kind="minutes" :min="$data['min_sample']" data-metric="accept" :tip="__('scorecard.tip_accept')" />
                            <x-admin.score-metric :label="__('scorecard.m_prepare').' · '.__('scorecard.median')" :metric="$b['prepare']['median']" kind="minutes" :min="$data['min_sample']" data-metric="prepare" :tip="__('scorecard.tip_prepare')" />
                        </dl>
                        <p class="mt-4 flex-1 text-sm text-ink-600 dark:text-ink-300" data-testid="problems">
                            {{ __('scorecard.headline_problems', ['failed' => $b['failed']['count'], 'cancelled' => $b['cancel']['count']]) }}
                            @if (! Money::of($b['cash']['net'])->isZero())<span class="block">{{ __('scorecard.headline_cash', ['amount' => $money($b['cash']['net'])]) }}</span>@endif
                        </p>
                        <button type="button" wire:click="openBranch('{{ $b['id'] }}')" class="btn-secondary mt-4 min-h-11 w-full justify-center" data-testid="open-{{ $b['code'] }}">{{ __('scorecard.all_numbers') }}</button>
                    </section>
                @endforeach
            </div>
        @else
            @php $head = $data['total']; @endphp
            @if ($data['rows'] === [])
                <div class="card"><x-admin.empty :title="__('scorecard.no_drivers_title')" :description="__('scorecard.no_drivers_hint')" icon="truck" data-empty="drivers" /></div>
            @else
                <section class="card card-pad mb-6" aria-labelledby="sc-head" data-testid="head">
                    <h2 id="sc-head" class="mb-4 font-display text-lg font-semibold">{{ __('scorecard.all_drivers') }}</h2>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-5 lg:grid-cols-4">
                        <x-admin.score-metric :label="__('scorecard.d_delivered')" :metric="$metricOf($head['delivered'])" kind="count" :min="$data['min_sample']" big :note="__('scorecard.tip_d_delivered')" />
                        <x-admin.score-metric :label="__('scorecard.d_on_time')" :metric="$head['on_time']" kind="percent" :min="$data['min_sample']" big :note="__('scorecard.tip_on_time')" :of="$head['on_time']['promised'] > 0 ? trans_choice('scorecard.deliveries_n', $head['on_time']['promised']) : null" />
                        <x-admin.score-metric :label="__('scorecard.d_failed')" :metric="$head['failed']['rate']" kind="percent" :min="$data['min_sample']" big :note="__('scorecard.tip_d_failed')" />
                        <div class="min-w-0" data-testid="head-cash">
                            <dt class="text-sm text-ink-600 dark:text-ink-300">{{ __('scorecard.d_cash_held') }}</dt>
                            <dd class="mt-0.5"><span class="font-display text-3xl font-semibold tabular-nums text-ink-900 sm:text-4xl dark:text-ivory" dir="ltr">{{ $money($head['cash']['amount']) }}</span>
                                <span class="block text-xs text-ink-500 dark:text-ink-400">{{ trans_choice('scorecard.collections_n', $head['cash']['held']) }}@if ($head['cash']['oldest_minutes'] !== null) · {{ __('scorecard.oldest_age', ['age' => ScorecardFormat::minutes($head['cash']['oldest_minutes'])]) }}@endif</span></dd>
                            <p class="mt-1 text-xs leading-snug text-ink-500 dark:text-ink-400">{{ __('scorecard.tip_d_cash_held') }}</p>
                        </div>
                    </dl>
                </section>

                @php
                    $cols = ['name' => __('scorecard.col_driver'), 'delivered' => __('scorecard.col_delivered'), 'on_time' => __('scorecard.col_on_time'), 'trip' => __('scorecard.col_trip'),
                        'failed' => __('scorecard.col_failed'), 'jobs' => __('scorecard.col_jobs'), 'cash' => __('scorecard.col_cash'), 'variance' => __('scorecard.col_variance')];
                    $th = fn (string $key, string $extra = '') => '<th class="'.$extra.'" aria-sort="'.($sort === $key ? ($dir === 'desc' ? 'descending' : 'ascending') : 'none').'">'
                        .'<button type="button" wire:click="sortBy(\''.$key.'\')" class="inline-flex min-h-11 items-center gap-1 font-semibold" title="'.e(__('scorecard.sort_by', ['column' => $cols[$key]])).'" data-sort="'.$key.'">'
                        .e($cols[$key]).($sort === $key ? '<span aria-hidden="true">'.($dir === 'desc' ? '▼' : '▲').'</span>' : '').'</button></th>';
                @endphp
                <div class="card @container" data-testid="drivers">
                    <div class="table-wrap hidden @[900px]:block">
                        <table class="table [&_td]:px-3 [&_th]:px-3">
                            <thead><tr>
                                {!! $th('name') !!}{!! $th('delivered') !!}{!! $th('on_time') !!}{!! $th('trip') !!}{!! $th('failed') !!}{!! $th('jobs') !!}{!! $th('cash') !!}{!! $th('variance') !!}
                            </tr></thead>
                            <tbody>
                            @foreach ($data['rows'] as $d)
                                <tr wire:key="sc-d-{{ $d['id'] }}" data-driver="{{ $d['id'] }}">
                                    <td><button type="button" wire:click="openDriver('{{ $d['id'] }}')" class="min-h-11 text-start font-medium text-ink-900 hover:text-brass-600 dark:text-ivory" data-testid="open-driver">{{ $d['name'] }}</button></td>
                                    <td class="tabular-nums" dir="ltr">{{ $d['delivered']['value'] }}@php $c = ScorecardFormat::change('count', (float) $d['delivered']['change']); @endphp @if ($c)<span class="ms-1 text-xs text-ink-500" data-change="{{ $c['direction'] }}"><span aria-hidden="true">{{ $c['arrow'] }}</span><span class="sr-only">{{ $c['words'] }}</span></span>@endif</td>
                                    <td><dl><x-admin.score-metric :label="$cols['on_time']" :metric="$d['on_time']" kind="percent" :min="$data['min_sample']" class="[&>dt]:sr-only [&_[data-testid=value]]:!text-base" /></dl></td>
                                    <td><dl><x-admin.score-metric :label="$cols['trip']" :metric="$d['trip']['median']" kind="minutes" :min="$data['min_sample']" class="[&>dt]:sr-only [&_[data-testid=value]]:!text-base" /></dl></td>
                                    <td><dl><x-admin.score-metric :label="$cols['failed']" :metric="$d['failed']['rate']" kind="percent" :min="$data['min_sample']" :of="$d['failed']['count'] > 0 ? trans_choice('scorecard.failed_n', $d['failed']['count']) : null" class="[&>dt]:sr-only [&_[data-testid=value]]:!text-base" /></dl></td>
                                    <td class="whitespace-nowrap text-sm"><span class="block">{{ trans_choice('scorecard.jobs_back_n', $d['jobs']['declined']) }}</span><span class="block text-xs text-ink-500">{{ trans_choice('scorecard.jobs_silent_n', $d['jobs']['timeouts']) }}</span></td>
                                    <td class="whitespace-nowrap text-sm"><span dir="ltr" class="font-medium tabular-nums">{{ $money($d['cash']['amount']) }}</span>
                                        @if ($d['cash']['oldest_minutes'] !== null)<span class="block text-xs text-ink-500">{{ ScorecardFormat::minutes($d['cash']['oldest_minutes']) }}@if ($d['cash']['over_limit']) · <x-icon name="clock" class="inline size-3.5 align-[-2px]" />{{ __('scorecard.over_limit', ['hours' => $d['cash']['limit_hours']]) }}@endif</span>@endif</td>
                                    <td class="whitespace-nowrap text-sm tabular-nums" dir="ltr">{{ $money($d['cash']['net']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <ul class="divide-y divide-sand-100 @[900px]:hidden dark:divide-ink-800">
                        @foreach ($data['rows'] as $d)
                            <li wire:key="sc-m-{{ $d['id'] }}" class="px-4 py-4">
                                <button type="button" wire:click="openDriver('{{ $d['id'] }}')" class="flex min-h-11 w-full items-center justify-between gap-3 text-start" data-testid="open-driver">
                                    <span class="font-display text-lg font-semibold">{{ $d['name'] }}</span><x-icon name="chevron-right" class="size-4 rtl-flip" />
                                </button>
                                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-4">
                                    <x-admin.score-metric :label="$cols['delivered']" :metric="$metricOf($d['delivered'])" kind="count" :min="$data['min_sample']" />
                                    <x-admin.score-metric :label="$cols['on_time']" :metric="$d['on_time']" kind="percent" :min="$data['min_sample']" />
                                    <x-admin.score-metric :label="$cols['trip']" :metric="$d['trip']['median']" kind="minutes" :min="$data['min_sample']" />
                                    <x-admin.score-metric :label="$cols['failed']" :metric="$d['failed']['rate']" kind="percent" :min="$data['min_sample']" />
                                </dl>
                                <p class="mt-3 text-xs text-ink-500">{{ $cols['cash'] }}: <span dir="ltr">{{ $money($d['cash']['amount']) }}</span>@if ($d['cash']['over_limit']) · {{ __('scorecard.over_limit', ['hours' => $d['cash']['limit_hours']]) }}@endif</p>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif

        @if (! $error && $branchOptions->isNotEmpty() && $data !== null)
            <section class="mt-8 rounded-2xl bg-sand-50 p-5 text-sm text-ink-600 dark:bg-ink-800 dark:text-ink-300" aria-labelledby="sc-read">
                <h2 id="sc-read" class="font-display text-base font-semibold text-ink-900 dark:text-ivory">{{ __('scorecard.read_title') }}</h2>
                <ul class="mt-2 list-disc space-y-1 ps-5">
                    <li>{{ __('scorecard.read_1', ['min' => $data['min_sample']]) }}</li><li>{{ __('scorecard.read_2') }}</li><li>{{ __('scorecard.read_3') }}</li><li>{{ __('scorecard.read_4') }}</li>
                </ul>
                <p class="mt-3 text-xs text-ink-500" data-testid="updated">{{ __('scorecard.updated', ['time' => BusinessTime::display($data['generated_at'], 'H:i')]) }}@if (! $canAll) · {{ __('scorecard.branch_only') }}@endif</p>
            </section>
        @endif
    </div>

    {{-- One branch, every number, each with the line that says how it is measured. --}}
    <x-admin.dialog wire:model.live="showBranch" :title="$branchCard ? $nameOf($branchCard) : ''" max-width="max-w-2xl">
        @if ($branchCard)
            @php $b = $branchCard; $min = $data['min_sample']; @endphp
            <p class="mb-5 text-sm text-ink-500" dir="ltr">{{ $span($period['from'], $period['to']) }}</p>
            <section class="mb-6" aria-labelledby="sc-s-speed">
                <h3 id="sc-s-speed" class="mb-2 font-display text-lg font-semibold">{{ __('scorecard.s_speed') }}</h3>
                <dl class="grid gap-5 rounded-xl border border-sand-100 p-4 sm:grid-cols-2 dark:border-ink-800">
                    <x-admin.score-metric :label="__('scorecard.m_orders')" :metric="$metricOf($b['orders'])" kind="count" :min="$min" :note="__('scorecard.n_orders')" class="sm:col-span-2" />
                    <x-admin.score-metric :label="__('scorecard.m_accept').' · '.__('scorecard.median')" :metric="$b['accept']['median']" kind="minutes" :min="$min" :of="trans_choice('scorecard.readings_n', $b['accept']['n'])" />
                    <x-admin.score-metric :label="__('scorecard.m_accept').' · '.__('scorecard.p90')" :metric="$b['accept']['p90']" kind="minutes" :min="$min" />
                    <p class="text-xs leading-snug text-ink-500 sm:col-span-2">{{ __('scorecard.n_accept') }}</p>
                    <x-admin.score-metric :label="__('scorecard.m_prepare').' · '.__('scorecard.median')" :metric="$b['prepare']['median']" kind="minutes" :min="$min" :of="trans_choice('scorecard.readings_n', $b['prepare']['n'])" />
                    <x-admin.score-metric :label="__('scorecard.m_prepare').' · '.__('scorecard.p90')" :metric="$b['prepare']['p90']" kind="minutes" :min="$min" />
                    <p class="text-xs leading-snug text-ink-500 sm:col-span-2">{{ __('scorecard.n_prepare') }}@if ($b['accept']['capped']) {{ __('scorecard.capped', ['n' => number_format(ScorecardBranchNumbers::TIMING_CAP)]) }}@endif</p>
                </dl>
            </section>

            <section class="mb-6" aria-labelledby="sc-s-ontime">
                <h3 id="sc-s-ontime" class="mb-2 font-display text-lg font-semibold">{{ __('scorecard.s_ontime') }}</h3>
                <dl class="grid gap-5 rounded-xl border border-sand-100 p-4 dark:border-ink-800">
                    <div>
                        <x-admin.score-metric :label="__('scorecard.m_on_time')" :metric="$b['delivery']['on_time']" kind="percent" :min="$min" :of="trans_choice('scorecard.deliveries_n', $b['delivery']['promised'])" :note="__('scorecard.n_on_time')" />
                        <p class="mt-1 text-xs text-ink-500" data-testid="moved">{{ trans_choice('scorecard.moved', $b['delivery']['moved'], ['count' => $b['delivery']['moved']]) }}@if ($b['delivery']['no_promise'] > 0) · {{ trans_choice('scorecard.no_promise', $b['delivery']['no_promise'], ['count' => $b['delivery']['no_promise']]) }}@endif</p>
                    </div>
                    <div>
                        <x-admin.score-metric :label="__('scorecard.m_pickup')" :metric="$b['pickup']['in_time']" kind="percent" :min="$min" :of="__('scorecard.pickup_detail', ['collected' => $b['pickup']['collected'], 'expired' => $b['pickup']['expired']])" :note="__('scorecard.n_pickup', ['hours' => $b['pickup']['ttl_hours']])" />
                    </div>
                </dl>
            </section>

            <section class="mb-6" aria-labelledby="sc-s-problems">
                <h3 id="sc-s-problems" class="mb-2 font-display text-lg font-semibold">{{ __('scorecard.s_problems') }}</h3>
                <dl class="grid gap-5 rounded-xl border border-sand-100 p-4 dark:border-ink-800">
                    <x-admin.score-metric :label="__('scorecard.m_failed')" :metric="$b['failed']['rate']" kind="percent" :min="$min" :of="trans_choice('scorecard.failed_n', $b['failed']['count']).' · '.__('scorecard.vs', ['value' => $b['failed']['previous_count']])" :note="__('scorecard.n_failed')" />
                    <ul class="space-y-1 text-sm" aria-label="{{ __('scorecard.m_failed') }}" data-testid="fail-reasons">
                        @forelse ($b['failed']['reasons'] as $code => $n)
                            <li class="flex items-baseline justify-between gap-3"><span>{{ ScorecardFormat::failReason((string) $code) }} <span class="text-xs text-ink-500">· {{ __('scorecard.side_'.$side((string) $code)) }}</span></span><span class="tabular-nums" dir="ltr">{{ $n }}</span></li>
                        @empty
                            <li class="text-ink-500">{{ __('scorecard.reasons_none') }}</li>
                        @endforelse
                    </ul>
                    <p class="text-xs text-ink-500" data-testid="fail-sides">{{ __('scorecard.side_customer') }}: {{ $b['failed']['customer'] }} · {{ __('scorecard.side_team') }}: {{ $b['failed']['team'] }} · {{ __('scorecard.side_other') }}: {{ $b['failed']['other'] }}</p>
                    <x-admin.score-metric :label="__('scorecard.m_team_failed')" :metric="$b['failed']['team_rate']" kind="percent" :min="$min" :note="__('scorecard.n_team_failed')" />
                    <div>
                        <x-admin.score-metric :label="__('scorecard.m_cancel')" :metric="$b['cancel']['rate']" kind="percent" :min="$min" :of="trans_choice('scorecard.cancelled_n', $b['cancel']['count']).' · '.__('scorecard.vs', ['value' => $b['cancel']['previous_count']])" :note="__('scorecard.n_cancel')" />
                        <ul class="mt-2 space-y-1 text-sm" aria-label="{{ __('scorecard.m_cancel') }}" data-testid="cancel-reasons">
                            @forelse ($b['cancel']['reasons'] as $code => $n)
                                <li class="flex items-baseline justify-between gap-3"><span>{{ ScorecardFormat::cancelReason((string) $code) }}</span><span class="tabular-nums" dir="ltr">{{ $n }}</span></li>
                            @empty
                                <li class="text-ink-500">{{ __('scorecard.reasons_none') }}</li>
                            @endforelse
                        </ul>
                    </div>
                </dl>
            </section>

            <section class="mb-6" aria-labelledby="sc-s-claims">
                <h3 id="sc-s-claims" class="mb-2 font-display text-lg font-semibold">{{ __('scorecard.s_claims') }}</h3>
                <dl class="grid gap-5 rounded-xl border border-sand-100 p-4 dark:border-ink-800">
                    <div>
                        <x-admin.score-metric :label="__('scorecard.m_claims')" :metric="$b['claims']['rate']" kind="percent" :min="$min" :of="trans_choice('scorecard.claims_n', $b['claims']['count']).' · '.__('scorecard.vs', ['value' => $b['claims']['previous_count']])" :note="__('scorecard.n_claims')" />
                        @if ($b['claims']['reasons'])<p class="mt-1 text-xs text-ink-500" data-testid="claim-reasons">{{ $reasonLine($b['claims']['reasons'], ScorecardFormat::claimReason(...)) }}</p>@endif
                    </div>
                    <x-admin.score-metric :label="__('scorecard.m_subs')" :metric="$b['substitutions']['rate']" kind="percent" :min="$min" :of="trans_choice('scorecard.subs_n', $b['substitutions']['count']).' · '.__('scorecard.vs', ['value' => $b['substitutions']['previous_count']])" :note="__('scorecard.n_subs')" />
                </dl>
            </section>

            <section class="mb-2" aria-labelledby="sc-s-cash">
                <h3 id="sc-s-cash" class="mb-2 font-display text-lg font-semibold">{{ __('scorecard.s_cash') }}</h3>
                @php $cash = $b['cash']; @endphp
                <dl class="divide-y divide-sand-100 rounded-xl border border-sand-100 px-4 dark:divide-ink-800 dark:border-ink-800" data-testid="cash">
                    @foreach ([
                        ['m_cash_net', $money($cash['net']), 'n_cash_net', null, $money($cash['previous_net'])],
                        ['m_cash_abs', $money($cash['abs']), 'n_cash_abs', null, $money($cash['previous_abs'])],
                        ['m_variances', __('scorecard.variances_of', ['n' => $cash['variances'], 'total' => $cash['handins']]), 'n_variances', null, __('scorecard.variances_of', ['n' => $cash['previous_variances'], 'total' => $cash['previous_handins']])],
                        ['m_open_variances', (string) $cash['open_variances'], 'n_open_variances', $cash['open_variances'] > 0, null],
                        ['m_cash_late', $cash['late'] === 0 ? __('scorecard.nothing_held') : __('scorecard.cash_late_value', ['amount' => $money($cash['late_amount']), 'count' => trans_choice('scorecard.collections_n', $cash['late'])]).($cash['oldest_minutes'] !== null ? ' · '.__('scorecard.oldest_age', ['age' => ScorecardFormat::minutes($cash['oldest_minutes'])]) : ''), 'n_cash_late', $cash['late'] > 0, null],
                    ] as [$label, $value, $note, $warn, $before])
                        <div class="py-3"><div class="flex items-baseline justify-between gap-4 text-sm"><dt class="text-ink-700 dark:text-sand-200">{{ __('scorecard.'.$label) }}</dt>
                            <dd class="text-end font-medium tabular-nums {{ $warn ? 'text-amber-soft dark:text-amber-300' : 'text-ink-900 dark:text-ivory' }}" dir="ltr">@if ($warn)<x-icon name="alert" class="me-1 inline size-3.5 align-[-2px]" />@endif{{ $value }}@if ($before !== null)<span class="block text-xs font-normal text-ink-500">{{ __('scorecard.vs', ['value' => $before]) }}</span>@endif</dd></div>
                            <p class="mt-1 text-xs leading-snug text-ink-500">{{ __('scorecard.'.$note, ['hours' => $cash['limit_hours']]) }}</p></div>
                    @endforeach
                </dl>
            </section>
        @endif
    </x-admin.dialog>

    {{-- One driver: every number, and the orders behind each one. --}}
    <x-admin.dialog wire:model.live="showDriver" :title="$driverDetail ? $driverDetail['driver']['name'] : ''" max-width="max-w-3xl">
        @if ($driverDetail)
            @php $d = $driverDetail['driver']; $min = $driverDetail['min_sample']; $cash = $d['cash']; @endphp
            <p class="mb-5 text-sm text-ink-500" dir="ltr">{{ $span($period['from'], $period['to']) }}</p>
            <dl class="mb-6 grid gap-5 rounded-xl border border-sand-100 p-4 sm:grid-cols-2 dark:border-ink-800" data-testid="driver-numbers">
                <x-admin.score-metric :label="__('scorecard.d_delivered')" :metric="$metricOf($d['delivered'])" kind="count" :min="$min" :note="__('scorecard.n_d_delivered')" />
                <x-admin.score-metric :label="__('scorecard.d_on_time')" :metric="$d['on_time']" kind="percent" :min="$min" :of="trans_choice('scorecard.deliveries_n', $d['on_time']['promised'])" :note="__('scorecard.n_d_on_time')" />
                <x-admin.score-metric :label="__('scorecard.d_trip').' · '.__('scorecard.median')" :metric="$d['trip']['median']" kind="minutes" :min="$min" :of="trans_choice('scorecard.deliveries_n', $d['trip']['n'])" :note="__('scorecard.n_d_trip')" />
                <x-admin.score-metric :label="__('scorecard.d_trip').' · '.__('scorecard.p90')" :metric="$d['trip']['p90']" kind="minutes" :min="$min" />
                <x-admin.score-metric :label="__('scorecard.d_trip_avg')" :metric="$d['trip']['average']" kind="minutes" :min="$min" class="sm:col-span-2" />
                <div class="sm:col-span-2">
                    <x-admin.score-metric :label="__('scorecard.d_failed')" :metric="$d['failed']['rate']" kind="percent" :min="$min" :of="trans_choice('scorecard.failed_n', $d['failed']['count']).' · '.__('scorecard.vs', ['value' => $d['failed']['previous_count']])" :note="__('scorecard.n_d_failed')" />
                    <ul class="mt-2 space-y-1 text-sm" aria-label="{{ __('scorecard.d_failed') }}" data-testid="driver-fail-reasons">
                        @forelse ($d['failed']['reasons'] as $code => $n)
                            <li class="flex items-baseline justify-between gap-3"><span>{{ ScorecardFormat::failReason((string) $code) }} <span class="text-xs text-ink-500">· {{ __('scorecard.side_'.$side((string) $code)) }}</span></span><span class="tabular-nums" dir="ltr">{{ $n }}</span></li>
                        @empty
                            <li class="text-ink-500">{{ __('scorecard.reasons_none') }}</li>
                        @endforelse
                    </ul>
                    <p class="mt-1 text-xs text-ink-500">{{ __('scorecard.side_customer') }}: {{ $d['failed']['customer'] }} · {{ __('scorecard.side_team') }}: {{ $d['failed']['team'] }} · {{ __('scorecard.side_other') }}: {{ $d['failed']['other'] }}</p>
                </div>
                <div class="sm:col-span-2">
                    <x-admin.score-metric :label="__('scorecard.d_jobs')" :metric="$d['jobs']['rate']" kind="percent" :min="$min" :of="trans_choice('scorecard.jobs_n', $d['jobs']['offered'])" :note="__('scorecard.n_d_jobs')" />
                    <p class="mt-1 text-xs text-ink-500" data-testid="jobs-detail">{{ __('scorecard.jobs_detail', ['declined' => $d['jobs']['declined'], 'timeouts' => $d['jobs']['timeouts'], 'off' => $d['jobs']['off_duty']]) }} · {{ __('scorecard.vs', ['value' => trans_choice('scorecard.jobs_back_n', $d['jobs']['previous_declined']).', '.trans_choice('scorecard.jobs_silent_n', $d['jobs']['previous_timeouts'])]) }}</p>
                </div>
                <div class="sm:col-span-2" data-testid="driver-cash">
                    <dt class="text-sm text-ink-600 dark:text-ink-300">{{ __('scorecard.d_cash_held') }}</dt>
                    <dd class="mt-0.5"><span class="font-display text-2xl font-semibold tabular-nums" dir="ltr">{{ $money($cash['amount']) }}</span>
                        <span class="block text-xs text-ink-500">{{ trans_choice('scorecard.collections_n', $cash['held']) }}@if ($cash['oldest_minutes'] !== null) · {{ __('scorecard.oldest_age', ['age' => ScorecardFormat::minutes($cash['oldest_minutes'])]) }}@endif @if ($cash['over_limit']) · {{ __('scorecard.over_limit', ['hours' => $cash['limit_hours']]) }}@endif</span></dd>
                    <p class="mt-1 text-xs text-ink-500">{{ __('scorecard.n_d_cash_held') }}</p>
                </div>
                <div class="sm:col-span-2" data-testid="driver-variance">
                    <dt class="text-sm text-ink-600 dark:text-ink-300">{{ __('scorecard.d_cash_variance') }}</dt>
                    <dd class="mt-0.5"><span class="font-display text-2xl font-semibold tabular-nums" dir="ltr">{{ $money($cash['net']) }}</span>
                        <span class="block text-xs text-ink-500">{{ __('scorecard.variances_of', ['n' => $cash['variances'], 'total' => $cash['handins']]) }} · {{ __('scorecard.vs', ['value' => $money($cash['previous_net'])]) }}</span></dd>
                    <p class="mt-1 text-xs text-ink-500">{{ __('scorecard.n_d_cash_variance') }}</p>
                </div>
            </dl>

            <section aria-labelledby="sc-ledger">
                <h3 id="sc-ledger" class="font-display text-lg font-semibold">{{ __('scorecard.orders_behind') }}</h3>
                <p class="mb-2 text-xs text-ink-500">{{ __('scorecard.orders_behind_hint') }}</p>
                @if ($driverDetail['ledger'] === [])
                    <p class="rounded-xl bg-sand-50 p-4 text-sm text-ink-600 dark:bg-ink-800 dark:text-ink-300" data-testid="ledger-empty">{{ __('scorecard.ledger_empty') }}</p>
                @else
                    <ul class="divide-y divide-sand-100 rounded-xl border border-sand-100 px-4 dark:divide-ink-800 dark:border-ink-800" data-testid="ledger">
                        @foreach ($driverDetail['ledger'] as $r)
                            @php
                                $text = match ($r['result']) {
                                    'on_time' => __('scorecard.ledger.on_time'),
                                    'late' => trans_choice('scorecard.ledger.late', (int) $r['days_late'], ['count' => (int) $r['days_late']]),
                                    'no_promise' => __('scorecard.ledger.no_promise'),
                                    'failed' => __('scorecard.ledger.failed', ['reason' => ScorecardFormat::failReason((string) $r['reason'])]),
                                    'declined' => __('scorecard.ledger.declined', ['reason' => ScorecardFormat::declineReason($r['reason'])]),
                                    'timeout' => __('scorecard.ledger.timeout'),
                                    'off_duty' => __('scorecard.ledger.off_duty'),
                                    'held' => __('scorecard.ledger.held', ['amount' => $money($r['amount'])]),
                                    'balanced' => __('scorecard.ledger.balanced'),
                                    default => __('scorecard.ledger.difference', ['amount' => $money($r['amount'])]),
                                };
                            @endphp
                            <li class="flex flex-wrap items-baseline gap-x-4 gap-y-1 py-2.5 text-sm" data-kind="{{ $r['kind'] }}" data-result="{{ $r['result'] }}">
                                <span class="w-28 shrink-0 text-xs text-ink-500" dir="ltr">{{ BusinessTime::display($r['at'], 'j M, H:i') }}</span>
                                <span class="w-32 shrink-0 font-mono text-xs" dir="ltr">{{ $r['order'] ?? $r['ref'] ?? '—' }} · {{ $r['branch'] }}</span>
                                <span class="min-w-0 flex-1">{{ $text }}@if ($r['minutes'] !== null) <span class="text-xs text-ink-500">· {{ __('scorecard.ledger.trip', ['n' => $r['minutes']]) }}</span>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($driverDetail['truncated'])<p class="mt-2 text-xs text-ink-500">{{ __('scorecard.ledger_truncated', ['n' => ScorecardDriverNumbers::LEDGER_CAP]) }}</p>@endif
                @endif
            </section>
        @endif
    </x-admin.dialog>

    @if ($canSettings)
        <x-admin.dialog wire:model="showSettings" max-width="max-w-lg" :title="__('scorecard.settings_title')" :description="__('scorecard.settings_hint')">
            <form wire:submit="saveSettings" class="space-y-5">
                <x-admin.field :label="__('scorecard.min_label')" for="minSample" :hint="__('scorecard.min_hint', ['min' => $minAllowed, 'max' => $maxAllowed]).' '.__('scorecard.min_default', ['n' => $minDefault])" required>
                    <input id="minSample" type="number" inputmode="numeric" min="{{ $minAllowed }}" max="{{ $maxAllowed }}" step="1" wire:model="minSample" class="input w-32" data-testid="min-sample">
                </x-admin.field>
                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                    <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                    <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="saveSettings" data-testid="save-settings"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
                </div>
            </form>
        </x-admin.dialog>
    @endif
</div>
