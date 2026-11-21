<div>
    <x-admin.page-header :title="__('nav.stocktaking')" :eyebrow="__('nav.inventory')" :description="__('inventory_admin.stocktaking_hint')">
        <x-slot:actions>
            @if ($branches->isNotEmpty())<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('inventory_admin.start_count') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>
    @if ($waiting['count'] > 0)
        <div class="card mb-6 flex flex-col gap-3 border-amber-soft/30 bg-amber-soft/5 p-5 sm:flex-row sm:items-center sm:justify-between" data-banner="waiting" role="status">
            <p class="flex items-center gap-3 text-sm font-medium"><x-icon name="clock" class="size-5 text-amber-soft" />{{ trans_choice('stock_planning.st.banner_waiting', $waiting['count'], ['count' => $waiting['count'], 'value' => $waiting['unknown'] ? __('stock_planning.value_unknown') : $waiting['value']->format()]) }}</p>
            <button type="button" wire:click="$set('filterStatus', 'submitted')" class="btn-secondary min-h-11">{{ __('stock_planning.st.waiting_for_approval') }}</button>
        </div>
    @endif
    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('inventory_admin.number_search')" />
            <select wire:model.live="filterStatus" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['counting', 'submitted', 'posted', 'cancelled'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
        </x-admin.toolbar>
        @if ($sessions->isEmpty())
            <x-admin.empty :title="__('inventory_admin.no_sessions')" :description="__('inventory_admin.no_sessions_hint')" icon="clipboard" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('inventory_admin.session') }}</th><th>{{ __('admin.branch') }}</th><th>{{ __('inventory_admin.progress') }}</th><th>{{ __('inventory_admin.started_by') }}</th><th>{{ __('stock_planning.st.differences') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($sessions as $ses)
                    @php $pct = $ses->items_count ? round($ses->counted_count * 100 / $ses->items_count) : 0; @endphp
                    <tr wire:key="ss-{{ $ses->id }}">
                        <td><a href="{{ route('admin.stocktaking.index', ['view' => $ses->id]) }}" wire:click.prevent="openView('{{ $ses->id }}')" class="font-mono font-medium hover:text-brass-600" dir="ltr">{{ $ses->number }}</a><span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($ses->created_at) }}</span></td>
                        <td>{{ $ses->branch->localized('name') }}</td>
                        <td><div class="flex min-w-[10rem] items-center gap-3"><div class="h-1.5 w-24 overflow-hidden rounded-full bg-sand-100 dark:bg-ink-800"><div class="h-full rounded-full bg-brass-500" style="width: {{ $pct }}%"></div></div><span class="text-xs text-ink-500 tabular-nums">{{ $ses->counted_count }} / {{ $ses->items_count }}</span></div></td>
                        <td class="text-sm text-ink-600">{{ $people[$ses->started_by] ?? '—' }}</td>
                        <td class="text-sm tabular-nums">
                            @if ($ses->variance_lines !== null)
                                {{ trans_choice('stock_planning.st.differences_n', $ses->variance_lines, ['count' => $ses->variance_lines]) }}
                                <span class="block text-xs text-ink-500">{{ $ses->variance_value !== null ? \App\Support\Money::of((string) $ses->variance_value)->format() : __('stock_planning.value_unknown') }}</span>
                            @else<span class="text-ink-400">—</span>@endif
                        </td>
                        <td><x-admin.status :value="$ses->status" />@if ($ses->status === 'submitted')<span class="mt-1 block text-xs text-amber-soft">{{ __('stock_planning.st.waiting_for_approval') }}</span>@endif</td>
                        <td><a href="{{ route('admin.stocktaking.index', ['view' => $ses->id]) }}" wire:click.prevent="openView('{{ $ses->id }}')" class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($sessions->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $sessions->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="__('inventory_admin.start_count')" :description="__('inventory_admin.start_count_hint')">
        <form wire:submit="start" class="space-y-5">
            <x-admin.field :label="__('admin.branch')" for="form.branch_id" required>
                <select id="form.branch_id" wire:model="form.branch_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach</select>
            </x-admin.field>
            <x-admin.field :label="__('inventory_admin.limit_to_category')" for="form.category_id" :hint="__('inventory_admin.limit_to_category_hint')">
                <select id="form.category_id" wire:model="form.category_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">{{ __('inventory_admin.whole_branch') }}</option>@foreach ($categories as $c)<option value="{{ $c->id }}">{{ str_repeat('— ', $c->depth) }}{{ $c->trans('name') }}</option>@endforeach</select>
            </x-admin.field>
            <x-admin.field :label="__('admin.notes')" for="form.notes"><textarea id="form.notes" wire:model="form.notes" rows="2" class="input"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="start"><x-icon name="play" class="size-4" />{{ __('inventory_admin.start_count') }}</button>
            </div>
        </form>
    </x-admin.dialog>
    {{-- Count summary window. The scan-and-count screen (barcode focus, wide table, paging) stays a page: the window's primary action opens it. --}}
    <x-admin.dialog wire:model.live="showView" :title="$viewSession?->number ?? ''" :description="$viewSession?->branch?->localized('name')" max-width="max-w-xl">
        @if ($viewSession)
            @php $lines = (int) $viewStats->lines; $counted = (int) $viewStats->counted; $pct = $lines ? round($counted * 100 / $lines) : 0; @endphp
            <div class="space-y-5">
                <div class="flex flex-wrap items-center gap-2 text-sm text-ink-500">
                    <x-admin.status :value="$viewSession->status" />
                    <span>{{ __('inventory_admin.started_by') }}: {{ \App\Domain\Identity\Models\User::whereKey($viewSession->started_by)->value('name') ?? '—' }}</span>
                    <span class="tabular-nums">{{ \App\Support\BusinessTime::display($viewSession->created_at) }}</span>
                </div>
                @if ($viewSession->notes)<p class="text-sm text-ink-600 dark:text-sand-200">{{ $viewSession->notes }}</p>@endif
                @if ($viewSession->status === 'submitted')
                    <p class="flex items-start gap-2 rounded-xl bg-amber-soft/5 p-3 text-sm text-ink-700 dark:text-sand-100" data-window="waiting"><x-icon name="clock" class="mt-0.5 size-4 shrink-0 text-amber-soft" />{{ __('stock_planning.st.window_waiting', ['lines' => number_format((int) $viewSession->variance_lines), 'value' => $viewSession->variance_value !== null ? \App\Support\Money::of((string) $viewSession->variance_value)->format() : __('stock_planning.value_unknown')]) }}</p>
                @elseif ($viewSession->status === 'counting' && $viewSession->returned_reason)
                    <p class="flex items-start gap-2 rounded-xl bg-ruby-soft/5 p-3 text-sm text-ink-700 dark:text-sand-100" data-window="returned"><x-icon name="refresh" class="mt-0.5 size-4 shrink-0 text-ruby-soft" />{{ $viewSession->returned_reason }}</p>
                @endif
                <div>
                    <div class="mb-1.5 flex items-center justify-between text-sm"><span class="font-medium">{{ __('inventory_admin.progress') }}</span><span class="tabular-nums text-ink-500">{{ $counted }} / {{ $lines }} · {{ $pct }}%</span></div>
                    <div class="h-2 overflow-hidden rounded-full bg-sand-100 dark:bg-ink-800" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100"><div class="h-full rounded-full bg-brass-500" style="width: {{ $pct }}%"></div></div>
                </div>
                <dl class="grid grid-cols-3 gap-3 text-center [&>div]:min-w-0 [&>div]:rounded-xl [&>div]:border [&>div]:border-sand-200/70 [&>div]:p-3 dark:[&>div]:border-ink-700">
                    <div><dt class="text-xs text-ink-500">{{ __('inventory_admin.uncounted') }}</dt><dd class="font-display text-xl font-semibold tabular-nums">{{ number_format($lines - $counted) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ __('inventory_admin.lines_with_variance') }}</dt><dd class="font-display text-xl font-semibold tabular-nums">{{ number_format((int) $viewStats->variances) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ __('inventory_admin.net_variance') }}</dt><dd class="font-display text-xl font-semibold tabular-nums" dir="ltr">{{ ($viewStats->net > 0 ? '+' : '').number_format((int) $viewStats->net) }}</dd></div>
                </dl>
            </div>
            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button type="button" class="btn-secondary min-h-11" @click="requestClose()">{{ __('admin.close') }}</button>
                    <a href="{{ route('admin.stocktaking.show', $viewSession) }}" wire:navigate class="btn-primary min-h-11"><x-icon name="clipboard" class="size-4" />{{ $viewCanCount ? __('drawer_admin.continue_count') : __('drawer_admin.open_count_screen') }}</a>
                </div>
            </x-slot:footer>
        @endif
    </x-admin.dialog>
</div>
