@php $st = $session->status; $pct = $stats->lines ? round($stats->counted * 100 / $stats->lines) : 0; @endphp
<div class="mx-auto max-w-7xl"
     x-data="{ flash: null }"
     x-on:stocktake-focus.window="$nextTick(() => { const el = $event.detail.target === 'qty' ? $refs.qty : $refs.scan; el && (el.focus(), el.select && el.select()) })"
     x-on:stocktake-counted.window="flash = $event.detail.id; setTimeout(() => flash = null, 1400)">
    <a href="{{ route('admin.stocktaking.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.stocktaking') }}</a>
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p class="eyebrow mb-2">{{ $branch->localized('name') }}</p>
            <h1 class="font-mono text-2xl font-semibold tracking-tight sm:text-3xl" dir="ltr">{{ $session->number }}</h1>
            @if ($session->notes)<p class="mt-2 text-sm text-ink-500">{{ $session->notes }}</p>@endif
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-admin.status :value="$st" />
            @if ($st === 'counting' && $canCount)<button type="button" wire:click="submitSession" wire:confirm="{{ __('inventory_admin.confirm_submit_count') }}" class="btn-primary"><x-icon name="check" class="size-4" />{{ __('inventory_admin.finish_counting') }}</button>@endif
            @if ($st === 'submitted' && $approval['mayApprove'])
                <button type="button" wire:click="post" wire:confirm="{{ __('stock_planning.st.confirm_approve') }}" wire:loading.attr="disabled" wire:target="post" class="btn-primary min-h-11"><x-icon name="check" class="size-4" />{{ __('stock_planning.st.approve') }}</button>
                <button type="button" wire:click="$set('showReturn', true)" class="btn-secondary min-h-11"><x-icon name="refresh" class="size-4" />{{ __('stock_planning.st.send_back') }}</button>
            @endif
            @if (in_array($st, ['counting', 'submitted']) && $canApprove)<button type="button" wire:click="cancelSession" wire:confirm="{{ __('inventory_admin.confirm_cancel_count') }}" class="btn-ghost text-ruby-soft">{{ __('admin.cancel') }}</button>@endif
        </div>
    </div>

    @php $money = $approval['summary']['value']; @endphp
    @if ($st === 'submitted')
        <div class="card mb-6 border-amber-soft/30 bg-amber-soft/5 p-5 sm:p-6" data-banner="waiting" role="status">
            <div class="flex flex-col gap-3 md:flex-row md:items-start">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-soft/15 text-amber-soft"><x-icon name="clock" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="font-display text-xl font-semibold">{{ __('stock_planning.st.waiting_title') }}</h2>
                    <p class="mt-1 text-sm text-ink-600 dark:text-sand-200">{{ __('stock_planning.st.waiting_body', ['lines' => number_format($approval['summary']['lines']), 'units' => number_format($approval['summary']['units']),
                        'value' => $money ? $money->format() : __('stock_planning.value_unknown'), 'counters' => $approval['counters'] ? implode(', ', $approval['counters']) : __('stock_planning.st.counters_none')]) }}</p>
                    @if ($approval['iCounted'] && $canApprove)<p class="mt-2 text-sm font-medium text-amber-soft">{{ __('stock_planning.st.you_counted') }}</p>
                    @elseif (! $canApprove)<p class="mt-2 text-xs text-ink-500">{{ __('stock_planning.st.not_allowed') }}</p>@endif
                </div>
            </div>
        </div>
    @elseif ($st === 'counting' && $session->returned_reason)
        <div class="card mb-6 border-ruby-soft/30 bg-ruby-soft/5 p-5 sm:p-6" data-banner="returned" role="status">
            <div class="flex items-start gap-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-ruby-soft/15 text-ruby-soft"><x-icon name="refresh" class="size-5" /></span>
                <p class="min-w-0 text-sm text-ink-700 dark:text-sand-100">{{ __('stock_planning.st.returned_banner', ['by' => $approval['returnedBy'] ?? '—', 'at' => \App\Support\BusinessTime::display($session->returned_at), 'reason' => $session->returned_reason]) }}</p>
            </div>
        </div>
    @elseif ($st === 'posted')
        <p class="mb-6 flex items-start gap-2 text-sm text-ink-600 dark:text-sand-200" data-banner="posted"><x-icon name="check" class="mt-0.5 size-4 shrink-0 text-emerald-soft" />
            @if ($session->approval_mode === 'auto')
                {{ $approval['summary']['lines'] === 0 ? __('stock_planning.st.approved_none') : __('stock_planning.st.approved_auto') }}
            @elseif ($session->approval_mode === 'self')
                {{ __('stock_planning.st.approved_self', ['by' => $approval['approvedBy'] ?? '—']) }}
            @else
                {{ __('stock_planning.st.approved_by', ['by' => $approval['approvedBy'] ?? '—']) }}
            @endif
        </p>
    @endif

    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.stat :label="__('inventory_admin.progress')" :value="$pct.'%'" icon="clipboard" tone="brass" :hint="$stats->counted.' / '.$stats->lines" />
        <x-admin.stat :label="__('inventory_admin.uncounted')" :value="number_format($stats->lines - $stats->counted)" icon="clock" />
        <x-admin.stat :label="__('inventory_admin.lines_with_variance')" :value="number_format($stats->variances)" icon="alert" :hint="$stats->variances ? ($money ? $money->format() : __('stock_planning.value_unknown')) : null" />
        <x-admin.stat :label="__('inventory_admin.net_variance')" :value="($stats->net > 0 ? '+' : '').number_format($stats->net)" icon="scale" />
    </div>

    @if ($canCount)
        <div class="card mb-6 overflow-hidden">
            <div class="grid gap-4 p-5 sm:p-6 lg:grid-cols-[1fr_auto_1fr] lg:items-end">
                <form wire:submit="scanCode">
                    <label for="scan" class="field-label">{{ __('inventory_admin.scan_sku') }}</label>
                    <div class="relative" dir="ltr">
                        <x-icon name="qr" class="pointer-events-none absolute top-1/2 start-4 size-5 -translate-y-1/2 text-brass-500" />
                        <input id="scan" x-ref="scan" wire:model="code" autofocus autocomplete="off" dir="ltr" class="input py-3.5 ps-12 font-mono text-lg" placeholder="{{ __('inventory_admin.scan_placeholder') }}">
                    </div>
                </form>
                <x-icon name="arrow-right" class="hidden size-5 text-ink-300 lg:mb-4 lg:block rtl-flip" />
                @if ($perScan)
                    <div class="rounded-xl bg-brass-50/60 p-4 text-sm text-ink-700 dark:bg-brass-500/10 dark:text-sand-200"><x-icon name="zap" class="me-1 inline size-4 text-brass-600" />{{ __('inventory_admin.per_scan_active') }}</div>
                @else
                    <form wire:submit="submitCount">
                        <label for="qty" class="field-label">{{ __('inventory_admin.counted_quantity') }}</label>
                        <div class="flex gap-2">
                            <input id="qty" x-ref="qty" type="number" min="0" wire:model="countQty" class="input py-3.5 text-lg tabular-nums" @disabled(! $active) placeholder="0">
                            <button class="btn-primary shrink-0 px-5" @disabled(! $active)>{{ __('inventory_admin.record') }}</button>
                        </div>
                        @error('countQty')<p class="field-error">{{ $message }}</p>@enderror
                    </form>
                @endif
            </div>
            <div class="flex flex-col gap-3 border-t border-sand-100 bg-sand-50/50 px-5 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:border-ink-800 dark:bg-ink-800/30">
                <div class="min-w-0 text-sm">
                    @if ($active)
                        <div class="flex items-center gap-3"><x-admin.variant :variant="$active" /> @if ($activeExpected !== null)<span class="badge-neutral shrink-0 tabular-nums">{{ __('inventory_admin.expected_n', ['n' => $activeExpected]) }}</span>@else<span class="badge-warning shrink-0">{{ __('inventory_admin.not_in_session') }}</span>@endif</div>
                    @else
                        <span class="text-ink-500">{{ __('inventory_admin.scan_help') }}</span>
                    @endif
                </div>
                <label class="flex shrink-0 items-center gap-2 text-sm"><input type="checkbox" wire:model.live="perScan" class="checkbox">{{ __('inventory_admin.add_one_per_scan') }}</label>
            </div>
        </div>
    @endif

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.filter_sku')" />
            <select wire:model.live="filter" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.filter') }}">
                <option value="">{{ __('inventory_admin.all_lines') }}</option>
                <option value="uncounted">{{ __('inventory_admin.uncounted') }}</option>
                <option value="variance">{{ __('inventory_admin.lines_with_variance') }}</option>
            </select>
        </x-admin.toolbar>
        @if ($items->isEmpty())
            <x-admin.empty :title="__('inventory_admin.no_lines')" icon="clipboard" class="!py-10" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('catalog_admin.product') }}</th><th class="!text-end">{{ __('inventory_admin.expected') }}</th><th class="w-32 !text-end">{{ __('inventory_admin.counted') }}</th><th class="!text-end">{{ __('inventory_admin.variance') }}</th><th>{{ __('inventory_admin.counted_at') }}</th></tr></thead>
                <tbody>@foreach ($items as $it)
                    @php $var = $it->counted_quantity !== null ? $it->counted_quantity - $it->expected_quantity : null; @endphp
                    <tr wire:key="sti-{{ $it->id }}" :class="flash === '{{ $it->id }}' ? '!bg-emerald-soft/10' : ''" class="transition-colors duration-700">
                        <td><x-admin.variant :variant="$it->variant" /></td>
                        <td class="text-end tabular-nums text-ink-500">{{ $it->expected_quantity }}</td>
                        <td class="text-end">
                            @if ($canCount)
                                <input type="number" min="0" wire:model="counts.{{ $it->id }}" wire:change="setCount('{{ $it->id }}')" @keydown.enter.prevent="$event.target.blur()" class="input ms-auto w-24 py-1.5 text-end tabular-nums" aria-label="{{ __('inventory_admin.counted') }}">
                            @else
                                <span class="tabular-nums">{{ $it->counted_quantity ?? '—' }}</span>
                            @endif
                        </td>
                        <td class="text-end font-semibold tabular-nums" dir="ltr">
                            @if ($var === null)<span class="text-ink-300">—</span>@elseif ($var === 0)<span class="text-emerald-soft"><x-icon name="check" class="inline size-4" /></span>@else<span class="{{ $var > 0 ? 'text-sky-soft dark:text-sky-300' : 'text-ruby-soft' }}">{{ $var > 0 ? '+' : '' }}{{ $var }}</span>@endif
                        </td>
                        <td class="whitespace-nowrap text-xs text-ink-500 tabular-nums">{{ $it->counted_at ? \App\Support\BusinessTime::display($it->counted_at, 'H:i') : '' }}</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($items->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $items->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
    <x-admin.dialog wire:model="showReturn" variant="modal" max-width="max-w-lg" :title="__('stock_planning.st.send_back_title')" :description="__('stock_planning.st.send_back_hint')">
        <form wire:submit="sendBack" class="space-y-5">
            <x-admin.field :label="__('stock_planning.st.reason')" for="returnReason" required>
                <textarea id="returnReason" wire:model="returnReason" rows="3" maxlength="500" class="input" placeholder="{{ __('stock_planning.st.reason_ph') }}" autofocus></textarea>
            </x-admin.field>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="sendBack">{{ __('stock_planning.st.send_back_confirm') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
