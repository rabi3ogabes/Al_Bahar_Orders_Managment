@php
    $money = ['gross', 'discounts', 'net', 'cost_value', 'retail_value', 'collected', 'not_handed_in', 'amount', 'outstanding', 'captured_amount', 'refunded_amount', 'winning_bid', 'value'];
    $moneyMetrics = ['gross_sales', 'discounts', 'cancelled', 'refunds', 'net_sales', 'average_order_value', 'average_lifetime_value'];
    $label = fn ($k) => \Illuminate\Support\Facades\Lang::has('content_admin.col_'.$k) ? __('content_admin.col_'.$k) : \Illuminate\Support\Str::headline($k);
@endphp
<div>
    <x-admin.page-header :title="__('nav.reports')" :eyebrow="__('nav.insights')" :description="__('content_admin.reports_hint')">
        <x-slot:actions>
            @if ($canExport)
                <a href="{{ route('admin.exports.index') }}" wire:navigate class="btn-ghost"><x-icon name="download" class="size-4" />{{ __('nav.exports') }}</a>
                <button type="button" wire:click="export" @disabled(! $result) wire:loading.attr="disabled" wire:target="export" class="btn-primary"><x-icon name="upload" class="size-4" />{{ __('content_admin.export_csv') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 lg:grid-cols-[15rem_1fr] [&>*]:min-w-0">
        <nav class="card p-2 lg:self-start" aria-label="{{ __('content_admin.report') }}">
            <label class="block p-2 lg:hidden"><span class="sr-only">{{ __('content_admin.report') }}</span>
                <select wire:model.live="report" class="input">@foreach ($groups as $g => $keys)<optgroup label="{{ __('content_admin.rg_'.$g) }}">@foreach ($keys as $k)<option value="{{ $k }}">{{ __('content_admin.r_'.$k) }}</option>@endforeach</optgroup>@endforeach</select>
            </label>
            <div class="hidden lg:block">
                @foreach ($groups as $g => $keys)
                    <p class="px-3 pb-1 pt-3 text-[0.65rem] font-semibold uppercase tracking-wider text-ink-500">{{ __('content_admin.rg_'.$g) }}</p>
                    @foreach ($keys as $k)
                        <button type="button" wire:click="$set('report', '{{ $k }}')" class="block w-full truncate rounded-lg px-3 py-2 text-start text-sm transition {{ $report === $k ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-700 hover:bg-sand-50 dark:text-sand-200 dark:hover:bg-ink-800' }}">{{ __('content_admin.r_'.$k) }}</button>
                    @endforeach
                @endforeach
            </div>
        </nav>

        <div class="min-w-0 space-y-6">
            <div class="card">
                <x-admin.toolbar>
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="date" wire:model.live="from" class="input w-auto" aria-label="{{ __('sales_admin.from_date') }}">
                        <span class="text-ink-400">–</span>
                        <input type="date" wire:model.live="to" class="input w-auto" aria-label="{{ __('sales_admin.to_date') }}">
                    </div>
                    <div class="flex gap-1">@foreach (['today', '7d', 'mtd', '30d'] as $p)<button type="button" wire:click="preset('{{ $p }}')" class="btn-ghost btn-sm">{{ __('content_admin.preset_'.$p) }}</button>@endforeach</div>
                    @if ($branches->count() > 1)
                        <select wire:model.live="branch" class="input sm:w-48" aria-label="{{ __('admin.branch') }}"><option value="">{{ __('admin.all_my_branches') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
                    @endif
                </x-admin.toolbar>
                <div class="p-5 sm:p-6">
                    <h2 class="font-display text-2xl font-semibold">{{ $report ? __('content_admin.r_'.$report) : '—' }}</h2>
                    <p class="mt-1 text-sm text-ink-500">{{ $report ? __('content_admin.rd_'.$report) : '' }}</p>
                    @if ($result && ! empty($result['summary']))
                        <dl class="mt-5 flex flex-wrap gap-3">
                            @foreach ($result['summary'] as $k => $v)
                                @if (is_scalar($v))
                                    <div class="rounded-xl bg-sand-50 px-4 py-2.5 dark:bg-ink-800/60"><dt class="text-xs text-ink-500">{{ $label($k) }}</dt><dd class="font-display text-xl font-semibold tabular-nums">@if (in_array($k, ['net_sales', 'net', 'cost_value', 'retail_value', 'collected', 'total', 'captured'], true))<x-admin.money :value="(string) $v" />@else{{ is_numeric($v) ? number_format((float) $v, floor((float) $v) == (float) $v ? 0 : 1) : $v }}@endif</dd></div>
                                @endif
                            @endforeach
                        </dl>
                    @endif
                </div>
            </div>

            @if ($chart)
                <div class="card card-pad" wire:key="chart-{{ $report }}-{{ $from }}-{{ $to }}-{{ $branch }}"
                     x-data="{ c: null, init() { const d = @js($chart); const dark = document.documentElement.classList.contains('dark'); const rtl = document.documentElement.dir === 'rtl';
                        this.c = new window.Chart(this.$refs.cv, { type: 'bar', data: { labels: d.labels, datasets: [{ label: @js($label($chart['metric'])), data: d.values, backgroundColor: '#b08d57', borderRadius: 6, maxBarThickness: 42 }] },
                        options: { responsive: true, maintainAspectRatio: false, indexAxis: d.labels.length > 6 ? 'y' : 'x', plugins: { legend: { display: false } },
                            scales: { x: { reverse: rtl, ticks: { color: dark ? '#b5b9c3' : '#5f6575' }, grid: { color: dark ? '#2a2e3a' : '#efe7da' } }, y: { position: rtl ? 'right' : 'left', ticks: { color: dark ? '#b5b9c3' : '#5f6575' }, grid: { display: false } } } } }); },
                        destroy() { this.c?.destroy(); } }">
                    <div class="h-72"><canvas x-ref="cv" role="img" aria-label="{{ __('content_admin.r_'.$report) }}"></canvas></div>
                </div>
            @endif

            <div class="card overflow-hidden" wire:loading.class="opacity-60">
                @if ($error)
                    <x-admin.empty :title="__('content_admin.report_unavailable')" :description="$error" icon="lock" />
                @elseif (! $result || empty($result['rows']))
                    <x-admin.empty :title="__('content_admin.report_empty')" :description="__('content_admin.report_empty_hint')" icon="chart" />
                @else
                    <div class="table-wrap max-h-[36rem] overflow-y-auto"><table class="table">
                        <thead class="sticky top-0 z-10"><tr>@foreach ($result['columns'] as $col)<th class="{{ in_array($col, $money, true) || is_numeric($result['rows'][0][$col] ?? null) ? '!text-end' : '' }}">{{ $label($col) }}</th>@endforeach</tr></thead>
                        <tbody>@foreach (array_slice($result['rows'], 0, 500) as $i => $row)
                            <tr wire:key="rr-{{ $i }}">@foreach ($result['columns'] as $col)
                                @php $v = $row[$col] ?? null; @endphp
                                <td class="{{ in_array($col, $money, true) || is_numeric($v) ? 'text-end tabular-nums' : '' }}">
                                    @if ($col === 'metric'){{ $label($v) }}
                                    @elseif ($col === 'value' && in_array($row['metric'] ?? '', $moneyMetrics, true))<x-admin.money :value="(string) $v" />
                                    @elseif (in_array($col, $money, true) && $col !== 'value' && is_numeric($v))<x-admin.money :value="(string) $v" />
                                    @elseif (in_array($col, ['status', 'check', 'state', 'winner_status'], true) && $v)<x-admin.status :value="(string) $v" />
                                    @elseif (in_array($col, ['payment_method'], true) && $v){{ __('status.'.$v) }}
                                    @elseif (is_array($v)){{ json_encode($v) }}
                                    @else<span @if (in_array($col, ['sku', 'id', 'number', 'reference', 'provider_reference'], true)) class="font-mono text-xs" dir="ltr" @endif>{{ is_numeric($v) && ! in_array($col, ['sku', 'number', 'reference', 'lot_number'], true) ? number_format((float) $v, floor((float) $v) == (float) $v ? 0 : 1) : ($v ?? '—') }}</span>@endif
                                </td>
                            @endforeach</tr>
                        @endforeach</tbody>
                    </table></div>
                    @if (count($result['rows']) > 500)<p class="border-t border-sand-100 p-3 text-center text-xs text-ink-500 dark:border-ink-800">{{ __('content_admin.rows_truncated', ['n' => count($result['rows'])]) }}</p>@endif
                @endif
            </div>
        </div>
    </div>
</div>
