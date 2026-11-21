@php
    use App\Support\Money;
    use Carbon\CarbonImmutable;

    $money = fn ($v) => Money::of((string) $v)->format();
    $label = fn (array $o) => app()->getLocale() === 'ar' ? $o['label_ar'] : $o['label_en'];
    $mult = fn ($m) => $m === null ? '—' : '×'.rtrim(rtrim(number_format($m, 1), '0'), '.');
    $look = [
        'strong' => ['check', 'badge-success'], 'fair' => ['check', 'badge-info'], 'weak' => ['alert', 'badge-danger'],
        'no_cost' => ['gift', 'badge-neutral'], 'not_enough' => ['clock', 'badge-neutral'],
    ];
    $pill = fn (bool $on) => 'inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition '.($on
        ? 'border-ink-900 bg-ink-900 text-ivory dark:border-brass-500 dark:bg-brass-500 dark:text-ink-950'
        : 'border-sand-300 bg-white hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800');
    $period = CarbonImmutable::parse($from)->locale(app()->getLocale())->translatedFormat('d M Y').' – '.CarbonImmutable::parse($to)->locale(app()->getLocale())->translatedFormat('d M Y');
    $kindLabel = fn (string $k) => __('offer_return.kind_'.$k);
@endphp
<div>
    <x-admin.page-header :title="__('offer_return.q')" :eyebrow="__('nav.marketing')" :description="__('offer_return.hint')">
        <x-slot:actions>
            @if ($canSettings)<button type="button" wire:click="$set('showSettings', true)" class="btn-ghost min-h-11" data-testid="open-settings"><x-icon name="sliders" class="size-4" />{{ __('offer_return.settings') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- The answer, first: across every discount of the period, how much came back for each 1 QAR given away. --}}
    <section class="card card-pad mb-6" aria-labelledby="or-hero" data-testid="hero" data-verdict="{{ $overall['verdict'] }}">
        <p id="or-hero" class="text-sm text-ink-500 dark:text-ink-400">{{ __('offer_return.hero_label', ['period' => $period]) }}</p>
        @if ($overall['verdict'] === 'not_enough')
            <p class="mt-1 font-display text-3xl font-semibold">{{ __('offer_return.not_enough') }}</p>
            <p class="mt-1 max-w-2xl text-sm text-ink-600 dark:text-ink-300">{{ trans_choice('offer_return.not_enough_hint', $overall['orders'], ['count' => $overall['orders'], 'min' => $minOrders]) }}</p>
        @elseif ($overall['verdict'] === 'no_cost')
            <p class="mt-1 font-display text-3xl font-semibold">{{ __('offer_return.no_cost') }}</p>
            <p class="mt-1 max-w-2xl text-sm text-ink-600 dark:text-ink-300">{{ __('offer_return.no_cost_hint') }}</p>
        @else
            <p class="mt-1 font-display text-5xl font-semibold tracking-tight tabular-nums" dir="ltr" data-testid="hero-multiple">{{ $mult($overall['multiple']) }}</p>
            <p class="mt-1 max-w-2xl text-sm text-ink-700 dark:text-sand-200">{{ __('offer_return.hero_sentence', ['amount' => rtrim(rtrim(number_format($overall['multiple'], 1), '0'), '.'), 'discount' => $money($overall['discount']), 'net' => $money($overall['booked']), 'orders' => number_format($overall['orders'])]) }}</p>
            <p class="mt-2"><span class="{{ $look[$overall['verdict']][1] }}"><x-icon :name="$look[$overall['verdict']][0]" class="size-3.5" />{{ __('offer_return.verdict_'.$overall['verdict']) }}</span></p>
        @endif
    </section>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <input type="date" wire:model.live="from" max="{{ $to }}" class="input w-auto min-h-11" aria-label="{{ __('sales_admin.from_date') }}" data-testid="from">
        <span class="text-ink-400" aria-hidden="true">–</span>
        <input type="date" wire:model.live="to" min="{{ $from }}" class="input w-auto min-h-11" aria-label="{{ __('sales_admin.to_date') }}" data-testid="to">
        @foreach (['30d', '90d', '12m'] as $p)<button type="button" wire:click="preset('{{ $p }}')" class="btn-ghost btn-sm min-h-11">{{ __('offer_return.preset_'.$p) }}</button>@endforeach
        <span wire:loading.delay wire:target="from, to, preset"><x-icon name="refresh" class="size-4 animate-spin text-brass-500" /></span>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="{{ __('offer_return.kinds') }}">
        @foreach ($kinds as $k)
            <button type="button" wire:click="$set('kind', '{{ $k }}')" aria-pressed="{{ $kind === $k ? 'true' : 'false' }}" class="{{ $pill($kind === $k) }}" data-kind="{{ $k }}">{{ $kindLabel($k) }}@if ($k !== 'all')<span class="tabular-nums opacity-70">{{ $counts[$k] ?? 0 }}</span>@endif</button>
        @endforeach
    </div>

    <div class="card @container" wire:loading.class="opacity-60" wire:target="from, to, preset, kind">
        @if ($offers === [])
            <x-admin.empty :title="__('offer_return.empty')" :description="__('offer_return.empty_hint')" icon="percent" data-empty="offers" />
        @else
            <div class="table-wrap hidden @[860px]:block">
                <table class="table [&_td]:px-3 [&_th]:px-3">
                    <thead><tr>
                        <th>{{ __('offer_return.col_offer') }}</th><th class="!text-end">{{ __('offer_return.col_orders') }}</th><th class="!text-end">{{ __('offer_return.col_net') }}</th>
                        <th class="!text-end">{{ __('offer_return.col_discount') }}</th><th class="!text-end">{{ __('offer_return.col_return') }}</th><th>{{ __('offer_return.col_customers') }}</th><th>{{ __('offer_return.col_verdict') }}</th><th class="w-12"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($offers as $o)
                        <tr wire:key="o-{{ $o['key'] }}" wire:click="open('{{ $o['key'] }}')" class="cursor-pointer" data-offer="{{ $o['key'] }}">
                            <td><span class="block font-medium text-ink-900 dark:text-ivory" @if ($o['kind'] === 'coupon') dir="ltr" @endif>{{ $label($o) }}</span><span class="text-xs text-ink-500">{{ $kindLabel($o['kind']) }}</span></td>
                            <td class="text-end tabular-nums">{{ number_format($o['orders']) }}</td>
                            <td class="text-end"><x-admin.money :value="$o['booked']" class="font-medium" /></td>
                            <td class="text-end"><x-admin.money :value="$o['discount']" /></td>
                            <td class="text-end tabular-nums" dir="ltr">{{ $o['verdict'] === 'not_enough' ? '—' : $mult($o['multiple']) }}</td>
                            <td class="whitespace-nowrap text-sm">{{ __('offer_return.new_returning', ['new' => $o['new_customers'], 'returning' => $o['returning_customers']]) }}</td>
                            <td><span class="{{ $look[$o['verdict']][1] }}"><x-icon :name="$look[$o['verdict']][0]" class="size-3.5" />{{ __('offer_return.verdict_'.$o['verdict']) }}</span></td>
                            <td class="!ps-0"><span class="btn-ghost btn-sm pointer-events-none" aria-hidden="true"><x-icon name="chevron-right" class="size-4 rtl-flip" /></span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <ul class="divide-y divide-sand-100 @[860px]:hidden dark:divide-ink-800">
                @foreach ($offers as $o)
                    <li wire:key="oc-{{ $o['key'] }}"><button type="button" wire:click="open('{{ $o['key'] }}')" class="block min-h-11 w-full px-4 py-3 text-start">
                        <span class="flex items-start justify-between gap-3"><span class="min-w-0 truncate font-medium" @if ($o['kind'] === 'coupon') dir="ltr" @endif>{{ $label($o) }}</span><span class="{{ $look[$o['verdict']][1] }} shrink-0"><x-icon :name="$look[$o['verdict']][0]" class="size-3.5" />{{ __('offer_return.verdict_'.$o['verdict']) }}</span></span>
                        <span class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-ink-600 dark:text-ink-300"><span>{{ $kindLabel($o['kind']) }}</span><span>{{ trans_choice('offer_return.orders_n', $o['orders'], ['count' => $o['orders']]) }}</span><x-admin.money :value="$o['booked']" /><span dir="ltr">{{ $o['verdict'] === 'not_enough' ? '—' : $mult($o['multiple']) }}</span></span>
                    </button></li>
                @endforeach
            </ul>
        @endif
    </div>
    <p class="mt-4 max-w-4xl text-xs leading-relaxed text-ink-500">{{ __('offer_return.definitions', ['min' => $minOrders, 'days' => $campaignDays, 'strong' => \App\Domain\Reports\Services\OfferReturn::STRONG_AT, 'fair' => \App\Domain\Reports\Services\OfferReturn::FAIR_AT]) }}</p>

    {{-- One offer, with its sum spelled out: this is the answer to "what does this number mean". --}}
    <x-admin.dialog wire:model.live="showOffer" max-width="max-w-xl" :title="$open ? $label($open) : ''">
        @if ($open)
            @php [$icon, $badge] = $look[$open['verdict']]; @endphp
            <div class="mb-4 flex flex-wrap items-center gap-2"><span class="{{ $badge }}"><x-icon :name="$icon" class="size-3.5" />{{ __('offer_return.verdict_'.$open['verdict']) }}</span><span class="text-xs text-ink-500">{{ $kindLabel($open['kind']) }} · {{ $period }}</span></div>
            <p class="mb-4 text-sm text-ink-700 dark:text-sand-200" data-testid="offer-sentence">
                @if ($open['verdict'] === 'not_enough'){{ trans_choice('offer_return.not_enough_offer', $open['orders'], ['count' => $open['orders'], 'min' => $minOrders]) }}
                @elseif ($open['verdict'] === 'no_cost'){{ __('offer_return.no_cost_offer') }}
                @else{{ __('offer_return.hero_sentence', ['amount' => rtrim(rtrim(number_format($open['multiple'], 1), '0'), '.'), 'discount' => $money($open['discount']), 'net' => $money($open['booked']), 'orders' => number_format($open['orders'])]) }}@endif
            </p>
            <dl class="divide-y divide-sand-100 rounded-xl border border-sand-100 px-4 dark:divide-ink-800 dark:border-ink-800">
                @foreach ([
                    [__('offer_return.d_orders'), number_format($open['orders']), __('offer_return.d_orders_note')],
                    [__('offer_return.d_before'), $money($open['before_discount']), __('offer_return.d_before_note')],
                    [__('offer_return.d_discount'), $money($open['discount']).($open['discount_rate'] !== null ? ' ('.rtrim(rtrim(number_format($open['discount_rate'], 1), '0'), '.').'%)' : ''), __('offer_return.d_discount_note')],
                    [__('offer_return.d_net'), $money($open['booked']), __('offer_return.d_net_note')],
                    [__('offer_return.d_return'), $open['verdict'] === 'not_enough' ? '—' : $mult($open['multiple']), __('offer_return.d_return_note')],
                    [__('offer_return.d_new'), number_format($open['new_customers']), __('offer_return.d_new_note')],
                    [__('offer_return.d_returning'), number_format($open['returning_customers']), __('offer_return.d_returning_note')],
                ] as [$term, $value, $note])
                    <div class="py-3 text-sm"><div class="flex items-baseline justify-between gap-4"><dt class="font-medium">{{ $term }}</dt><dd class="font-medium tabular-nums" dir="ltr">{{ $value }}</dd></div><p class="mt-0.5 text-xs text-ink-500">{{ $note }}</p></div>
                @endforeach
            </dl>
            <p class="mt-4 rounded-xl bg-sand-50 p-3 text-xs leading-relaxed text-ink-600 dark:bg-ink-800 dark:text-ink-300">{{ __('offer_return.caveat') }}</p>
            @if ($open['kind'] === 'campaign')<p class="mt-2 text-xs text-ink-500">{{ __('offer_return.campaign_note', ['days' => $campaignDays]) }}</p>@endif
            <div class="mt-5 flex flex-wrap gap-2">
                @php $manage = ['coupon' => 'admin.promotions.index', 'promotion' => 'admin.promotions.index', 'journey' => 'admin.journeys.index', 'campaign' => 'admin.campaigns.index'][$open['kind']]; @endphp
                <a href="{{ route($manage) }}" wire:navigate class="btn-secondary min-h-11"><x-icon name="external" class="size-4 rtl-flip" />{{ __('offer_return.manage_'.$open['kind']) }}</a>
            </div>
        @endif
    </x-admin.dialog>

    @if ($canSettings)
        <x-admin.dialog wire:model="showSettings" max-width="max-w-lg" :title="__('offer_return.settings')" :description="__('offer_return.settings_hint')">
            <form wire:submit="saveSettings" class="space-y-5">
                <x-admin.field :label="__('offer_return.min_orders_label')" for="settingsForm.min_orders" :hint="__('offer_return.min_orders_hint')" required><input id="settingsForm.min_orders" type="number" min="1" max="1000" wire:model="settingsForm.min_orders" class="input tabular-nums sm:max-w-[10rem]" dir="ltr" inputmode="numeric"></x-admin.field>
                <x-admin.field :label="__('offer_return.campaign_days_label')" for="settingsForm.campaign_days" :hint="__('offer_return.campaign_days_hint')" required><input id="settingsForm.campaign_days" type="number" min="1" max="60" wire:model="settingsForm.campaign_days" class="input tabular-nums sm:max-w-[10rem]" dir="ltr" inputmode="numeric"></x-admin.field>
                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                    <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                    <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="saveSettings">{{ __('admin.save_changes') }}</button>
                </div>
            </form>
        </x-admin.dialog>
    @endif
</div>
