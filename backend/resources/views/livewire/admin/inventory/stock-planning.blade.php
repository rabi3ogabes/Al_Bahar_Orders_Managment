@php
    $tabs = [
        'order' => ['clipboard', __('stock_planning.lane_order'), $counts['order']],
        'out' => ['ban', __('stock_planning.lane_out'), $counts['out']],
        'reorder' => ['refresh', __('stock_planning.lane_reorder'), $counts['reorder']],
        'low' => ['alert', __('stock_planning.lane_low'), $counts['low']],
        'slow' => ['clock', __('stock_planning.lane_slow'), $counts['slow']],
        'all' => ['boxes', __('stock_planning.lane_all'), $counts['all']],
    ];
    $orderLanes = ['order', 'out', 'reorder', 'low'];
@endphp
<div>
    <x-admin.page-header :title="__('stock_planning.title')" :eyebrow="__('nav.inventory')" :description="__('stock_planning.hint')">
        <x-slot:actions>
            @if ($canExport)<button type="button" wire:click="export" wire:loading.attr="disabled" wire:target="export" class="btn-secondary min-h-11"><x-icon name="download" class="size-4" />{{ __('stock_planning.export_csv') }}</button>@endif
            @if ($canSettings)<a href="{{ route('admin.settings.index', ['tab' => 'stock']) }}" wire:navigate class="btn-secondary min-h-11"><x-icon name="sliders" class="size-4" />{{ __('stock_planning.planning_rules') }}</a>@endif
        </x-slot:actions>
    </x-admin.page-header>

    {{-- The answer first: how many things need an order, how many are out and who is waiting for them. Each tile opens its list. --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" data-tiles="planning">
        <button type="button" wire:click="$set('lane', 'out')" class="h-full text-start" data-tile="out"><x-admin.stat class="h-full transition hover:border-brass-300" :label="__('stock_planning.lane_out')" :value="number_format($counts['out'])" icon="ban" tone="brass"
            :hint="trans_choice('stock_planning.customers_waiting_n', $counts['customers'], ['count' => number_format($counts['customers'])])" /></button>
        <button type="button" wire:click="$set('lane', 'reorder')" class="h-full text-start" data-tile="reorder"><x-admin.stat class="h-full transition hover:border-brass-300" :label="__('stock_planning.lane_reorder')" :value="number_format($counts['reorder'])" icon="refresh"
            :hint="__('stock_planning.reorder_hint')" /></button>
        <button type="button" wire:click="$set('lane', 'low')" class="h-full text-start" data-tile="low"><x-admin.stat class="h-full transition hover:border-brass-300" :label="__('stock_planning.lane_low')" :value="number_format($counts['low'])" icon="alert"
            :hint="__('stock_planning.low_hint', ['days' => $numbers['low_days']])" /></button>
        <button type="button" wire:click="$set('lane', 'slow')" class="h-full text-start" data-tile="slow"><x-admin.stat class="h-full transition hover:border-brass-300" :label="__('stock_planning.lane_slow')" :value="number_format($counts['slow'])" icon="clock"
            :hint="__('stock_planning.slow_hint', ['days' => $numbers['slow_days'], 'window' => $numbers['window_days']])" /></button>
    </div>

    <x-admin.tabs :tabs="$tabs" :current="$lane" model="lane" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('stock_planning.search')" />
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-48" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            @if (in_array($lane, $orderLanes, true) && $orderBranch && $canOrderAt->contains($orderBranch) && $rows->total() > 0)
                <button type="button" wire:click="openOrder" class="btn-primary min-h-11"><x-icon name="receipt" class="size-4" />{{ __('stock_planning.order_everything') }}</button>
            @endif
            <x-slot:meta>{{ trans_choice('inventory_admin.lines_count', $rows->total(), ['count' => $rows->total()]) }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($rows->isEmpty())
            <x-admin.empty :title="$search !== '' ? __('stock_planning.empty_search') : __('stock_planning.empty_'.$lane)" :description="$search !== '' ? __('stock_planning.empty_search_hint') : __('stock_planning.empty_'.$lane.'_hint')" :icon="$lane === 'order' ? 'check' : 'boxes'" />
        @else
            <div class="table-wrap" wire:loading.class="opacity-60" wire:target="lane,branch,search,gotoPage,nextPage,previousPage">
                <table class="table">
                    <thead><tr>
                        <th>{{ __('catalog_admin.product') }}</th>
                        <th class="!text-end">{{ __('stock_planning.col_available') }}</th>
                        <th class="!text-end">{{ __('stock_planning.col_selling') }}</th>
                        <th class="!text-end">{{ __('stock_planning.col_cover') }}</th>
                        <th class="!text-end">{{ __('stock_planning.col_reorder_at') }}</th>
                        <th>{{ __('stock_planning.col_on_order') }}</th>
                        <th class="!text-end">{{ __('stock_planning.col_suggested') }}</th>
                        <th class="w-24"></th>
                    </tr></thead>
                    <tbody>
                    @foreach ($rows as $r)
                        @php
                            $variant = $variants[$r->variantId] ?? null;
                            $branchRow = $branchNames[$r->branchId] ?? null;
                            $days = $r->daysOut($now);
                            $wish = $wishlists[$r->variantId] ?? 0;
                            $needsOrder = in_array($r->lane, ['out', 'low', 'reorder'], true);
                            $tone = ['out' => 'badge-danger', 'low' => 'badge-warning', 'reorder' => 'badge-warning', 'slow' => 'badge-info'][$r->lane] ?? 'badge-neutral';
                        @endphp
                        @continue(! $variant)
                        <tr wire:key="plan-{{ $r->id }}" data-lane="{{ $r->lane }}">
                            <td class="max-w-[22rem]">
                                <x-admin.variant :variant="$variant" />
                                <span class="mt-1.5 flex flex-wrap items-center gap-1.5 text-xs">
                                    <span class="badge-neutral font-mono" dir="ltr" title="{{ $branchRow?->localized('name') }}">{{ $branchRow?->code }}</span>
                                    @if ($r->lane !== 'ok')<span class="{{ $tone }}">{{ __('stock_planning.lane_'.$r->lane) }}</span>@endif
                                    @if ($r->isOut)
                                        <span class="text-ruby-soft">{{ $days > 0 ? trans_choice('stock_planning.out_for_n', $days, ['count' => $days]) : __('stock_planning.out_today') }}</span>
                                    @endif
                                    @if ($r->waiting > 0)<span class="font-medium text-brass-700 dark:text-brass-300"><x-icon name="bell" class="inline size-3.5" /> {{ trans_choice('stock_planning.customers_waiting_n', $r->waiting, ['count' => $r->waiting]) }}</span>@endif
                                    @if ($wish > 0)<span class="text-ink-500"><x-icon name="heart" class="inline size-3.5" /> {{ trans_choice('stock_planning.wishlists_n', $wish, ['count' => $wish]) }}</span>@endif
                                </span>
                                {{-- On a phone the table scrolls sideways: the three things that matter sit under the name. --}}
                                <span class="mt-1 block text-xs text-ink-500 tabular-nums md:hidden">{{ __('stock_planning.phone_line', ['available' => number_format($r->available), 'cover' => $r->coverDays === null ? '—' : number_format($r->coverDays, $r->coverDays < 10 ? 1 : 0), 'order' => $needsOrder ? number_format($r->suggested) : '—']) }}</span>
                            </td>
                            <td class="text-end">
                                <span @class(['font-semibold tabular-nums', 'text-ruby-soft' => $r->isOut, 'text-ink-900 dark:text-ivory' => ! $r->isOut])>{{ number_format($r->available) }}</span>
                                <span class="block text-xs text-ink-500 tabular-nums">{{ __('stock_planning.on_hand_n', ['n' => number_format($r->onHand)]) }}@if ($r->reserved) · {{ __('inventory_admin.reserved_n', ['n' => number_format($r->reserved)]) }}@endif</span>
                            </td>
                            <td class="text-end">
                                @if ($r->unitsSold === 0)
                                    <span class="text-xs text-ink-500">{{ __('stock_planning.no_sales', ['days' => $r->historyDays]) }}</span>
                                @else
                                    <span class="tabular-nums">{{ $r->dailyDemand < 0.1 ? '<0.1' : number_format($r->dailyDemand, $r->dailyDemand < 10 ? 1 : 0) }}</span><span class="text-xs text-ink-500"> {{ __('stock_planning.per_day') }}</span>
                                    <span class="block text-xs text-ink-500 tabular-nums">{{ trans_choice('stock_planning.sold_n', $r->unitsSold, ['count' => $r->unitsSold, 'days' => $r->historyDays]) }}</span>
                                @endif
                                @if ($r->littleHistory)<span class="badge-neutral mt-1" title="{{ __('stock_planning.new_hint') }}">{{ __('stock_planning.badge_new') }}</span>@endif
                                @if ($r->oneOffOrder)<span class="badge-neutral mt-1" title="{{ __('stock_planning.one_off_hint') }}">{{ __('stock_planning.badge_one_off') }}</span>@endif
                            </td>
                            <td class="text-end">
                                @if ($r->coverDays === null)
                                    <span class="text-ink-400">—</span>
                                @else
                                    <span @class(['font-semibold tabular-nums', 'text-ruby-soft' => $r->isLow, 'text-amber-soft' => ! $r->isLow && $r->isReorder])>{{ number_format($r->coverDays, $r->coverDays < 10 ? 1 : 0) }}</span><span class="text-xs text-ink-500"> {{ __('stock_planning.days_short') }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <span class="tabular-nums">{{ number_format($r->reorderPoint) }}</span>
                                <span class="block text-xs text-ink-500">{{ $r->reorderPointFixed ? __('stock_planning.set_by_hand') : __('stock_planning.lead_plus_safety', ['lead' => $r->leadDays, 'safety' => $r->safetyDays]) }}</span>
                            </td>
                            <td>
                                @if ($r->incoming > 0)
                                    <span class="font-medium tabular-nums">{{ number_format($r->incoming) }}</span>
                                    @if ($r->incomingDate)<span class="block text-xs text-ink-500 tabular-nums">{{ __('stock_planning.due', ['date' => \App\Support\BusinessTime::display($r->incomingDate, 'd M')]) }}</span>@endif
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($needsOrder && $r->suggested > 0)
                                    <span class="font-display text-lg font-semibold tabular-nums">{{ number_format($r->suggested) }}</span>
                                    @if ($r->suggestedFixed)<span class="block text-xs text-ink-500">{{ __('stock_planning.set_by_hand') }}</span>@endif
                                @elseif ($needsOrder && $r->incoming > 0)
                                    <span class="text-xs text-emerald-soft">{{ __('stock_planning.covered_by_order') }}</span>
                                @else
                                    <span class="text-ink-400">—</span>
                                @endif
                            </td>
                            <td>
                                <div class="flex justify-end gap-0.5">
                                    @if ($needsOrder && $r->suggested > 0 && $canOrderAt->contains($r->branchId))
                                        <button type="button" wire:click="openOrder('{{ $r->id }}')" class="btn-primary btn-sm min-h-11" aria-label="{{ __('stock_planning.order_n_of', ['n' => $r->suggested, 'name' => $variant->product?->trans('name')]) }}"><x-icon name="receipt" class="size-4" />{{ __('stock_planning.order') }}</button>
                                    @endif
                                    @if ($canEditAt->contains($r->branchId))<button type="button" wire:click="openRules('{{ $r->id }}')" class="btn-ghost btn-sm min-h-11 min-w-11 px-2" title="{{ __('stock_planning.rules_title') }}" aria-label="{{ __('stock_planning.rules_title') }}"><x-icon name="sliders" class="size-4" /></button>@endif
                                    <a href="{{ route('admin.inventory.movements', ['search' => $variant->sku, 'branch' => $r->branchId]) }}" wire:navigate class="btn-ghost btn-sm min-h-11 min-w-11 px-2" title="{{ __('nav.movements') }}" aria-label="{{ __('nav.movements') }}"><x-icon name="history" class="size-4" /></a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    {{-- What every number means, with the shop's own settings filled in. --}}
    <details class="card mt-6 p-5 sm:p-6" data-how>
        <summary class="cursor-pointer select-none font-display text-lg font-semibold">{{ __('stock_planning.how_title') }}</summary>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
            @foreach (['demand' => ['window' => $numbers['window_days'], 'min' => \App\Domain\Inventory\Services\StockPlanningSettings::MIN_BASIS_DAYS], 'cover' => [], 'reorder' => ['lead' => $numbers['lead_days'], 'safety' => $numbers['safety_days']],
                'suggest' => ['lead' => $numbers['lead_days'], 'safety' => $numbers['safety_days'], 'cycle' => $numbers['cycle_days']], 'lanes' => ['low' => $numbers['low_days'], 'slow' => $numbers['slow_days'], 'window' => $numbers['window_days']],
                'waiting' => []] as $key => $vars)
                <div><dt class="font-medium text-ink-900 dark:text-ivory">{{ __('stock_planning.how_'.$key.'_title') }}</dt><dd class="mt-1 text-ink-600 dark:text-sand-200">{{ __('stock_planning.how_'.$key, $vars) }}</dd></div>
            @endforeach
        </dl>
        @if ($canSettings)<p class="mt-4 text-xs text-ink-500">{{ __('stock_planning.how_change') }} <a href="{{ route('admin.settings.index', ['tab' => 'stock']) }}" wire:navigate class="font-medium text-brass-600 hover:underline">{{ __('stock_planning.planning_rules') }}</a></p>@endif
    </details>

    {{-- Rules of one item at one branch. --}}
    <x-admin.dialog wire:model="showRules" :title="__('stock_planning.rules_title')" :description="$planVariant ? ($planVariant->variant->product?->trans('name').' · '.$planVariant->branch->code) : __('stock_planning.rules_hint')">
        <form wire:submit="saveRules" class="space-y-5">
            <p class="text-sm text-ink-600 dark:text-sand-200">{{ __('stock_planning.rules_hint') }}</p>
            <div class="grid grid-cols-2 gap-4">
                <x-admin.field :label="__('stock_planning.rule_lead')" for="plan.lead_time_days" :hint="__('stock_planning.rule_lead_hint', ['n' => $numbers['lead_days']])"><input id="plan.lead_time_days" type="number" min="0" max="365" wire:model="plan.lead_time_days" class="input tabular-nums" placeholder="{{ $numbers['lead_days'] }}"></x-admin.field>
                <x-admin.field :label="__('stock_planning.rule_safety')" for="plan.safety_days" :hint="__('stock_planning.rule_safety_hint', ['n' => $numbers['safety_days']])"><input id="plan.safety_days" type="number" min="0" max="365" wire:model="plan.safety_days" class="input tabular-nums" placeholder="{{ $numbers['safety_days'] }}"></x-admin.field>
                <x-admin.field :label="__('stock_planning.rule_point')" for="plan.reorder_point" :hint="__('stock_planning.rule_point_hint')"><input id="plan.reorder_point" type="number" min="0" wire:model="plan.reorder_point" class="input tabular-nums" placeholder="{{ __('stock_planning.automatic') }}"></x-admin.field>
                <x-admin.field :label="__('stock_planning.rule_quantity')" for="plan.reorder_quantity" :hint="__('stock_planning.rule_quantity_hint')"><input id="plan.reorder_quantity" type="number" min="1" wire:model="plan.reorder_quantity" class="input tabular-nums" placeholder="{{ __('stock_planning.automatic') }}"></x-admin.field>
            </div>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary min-h-11" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="saveRules"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>

    {{-- The suggestion as a draft purchase order (the purchasing module does the rest: approval, receiving, cost). --}}
    <x-admin.dialog wire:model="showOrder" :title="__('stock_planning.order_title')" :description="__('stock_planning.order_hint')" max-width="max-w-2xl">
        <form wire:submit="createOrder" class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.field :label="__('inventory_admin.supplier')" for="draft.supplier_id" required>
                    <select id="draft.supplier_id" wire:model="draft.supplier_id" class="input pe-10 rtl:[background-position:left_0.5rem_center]"><option value="">—</option>@foreach ($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                </x-admin.field>
                <x-admin.field :label="__('stock_planning.expected_on')" for="draft.expected_at"><input id="draft.expected_at" type="date" wire:model="draft.expected_at" class="input"></x-admin.field>
            </div>
            @if ($suppliers->isEmpty())<p class="text-xs text-ink-500">{{ __('stock_planning.no_suppliers') }}</p>@endif
            <div class="overflow-hidden rounded-xl border border-sand-200/70 dark:border-ink-700">
                <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                    @foreach ($lines as $i => $l)
                        @php $lv = $orderVariants[$l['variant_id']] ?? null; @endphp
                        <li class="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center" wire:key="ol-{{ $l['variant_id'] }}">
                            <div class="min-w-0 flex-1">@if ($lv)<x-admin.variant :variant="$lv" />@endif</div>
                            <div class="flex items-center gap-2">
                                <input type="number" min="1" wire:model.live.debounce.400ms="lines.{{ $i }}.quantity" class="input w-24 text-end tabular-nums" aria-label="{{ __('admin.quantity') }}">
                                <input type="text" inputmode="decimal" dir="ltr" wire:model.live.debounce.400ms="lines.{{ $i }}.unit_cost" class="input w-28 text-end tabular-nums" aria-label="{{ __('inventory_admin.unit_cost') }}" placeholder="0.00">
                                <button type="button" wire:click="removeLine({{ $i }})" class="btn-ghost btn-sm min-h-11 min-w-11 px-2" aria-label="{{ __('stock_planning.remove_line') }}"><x-icon name="x" class="size-4" /></button>
                            </div>
                            @error('lines.'.$i.'.quantity')<p class="field-error">{{ $message }}</p>@enderror
                            @error('lines.'.$i.'.unit_cost')<p class="field-error">{{ $message }}</p>@enderror
                        </li>
                    @endforeach
                </ul>
                <div class="flex items-center justify-between border-t border-sand-100 bg-sand-50/50 px-4 py-3 text-sm dark:border-ink-800 dark:bg-ink-800/30"><span class="text-ink-500">{{ __('inventory_admin.subtotal') }}</span><span class="font-semibold tabular-nums">{{ $orderTotal->format() }}</span></div>
            </div>
            <x-admin.field :label="__('admin.notes')" for="draft.notes"><textarea id="draft.notes" wire:model="draft.notes" rows="2" class="input"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary min-h-11" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="createOrder"><x-icon name="receipt" class="size-4" />{{ __('stock_planning.create_draft') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
