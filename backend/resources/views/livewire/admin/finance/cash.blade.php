@php
    $tabs = [
        'collections' => ['cash', __('finance_admin.tab_collections')],
        'outstanding' => ['user', __('finance_admin.tab_outstanding'), $totals->not_handed_in > 0 ? '•' : null],
        'handovers' => ['scale', __('finance_admin.tab_handovers'), $openVariances ?: null],
    ];
    $money = fn ($v) => \App\Support\Money::of((string) $v)->format();
@endphp
<div>
    <x-admin.page-header :title="__('nav.cash')" :eyebrow="__('nav.finance')" :description="__('finance_admin.cash_hint')" />

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4 [&>*]:min-w-0">
        <x-admin.stat :label="__('finance_admin.collected')" :value="$money($totals->collected)" icon="wallet" tone="brass" :hint="__('finance_admin.cash_split', ['cash' => $money($totals->cash), 'card' => $money($totals->card)])" />
        <x-admin.stat :label="__('finance_admin.held_by_collectors')" :value="$money($totals->not_handed_in)" icon="user" :hint="$totals->failed ? trans_choice('finance_admin.n_failed_collections', (int) $totals->failed, ['count' => (int) $totals->failed]) : null" />
        <x-admin.stat :label="__('finance_admin.in_drawer')" :value="$money($drawer)" icon="cash" :hint="__('finance_admin.in_drawer_hint')" />
        <x-admin.stat :label="__('finance_admin.variances_to_review')" :value="$openVariances" icon="alert" />
    </div>

    <x-admin.tabs :tabs="$tabs" :current="$tab" model="tab" />

    @if ($tab === 'outstanding')
        <div class="card">
            <div class="p-5"><p class="text-sm text-ink-500">{{ __('finance_admin.outstanding_hint') }}</p></div>
            @if ($outstanding->isEmpty())
                <x-admin.empty :title="__('finance_admin.outstanding_empty')" :description="__('finance_admin.outstanding_empty_hint')" icon="check" class="border-t border-sand-100 dark:border-ink-800" />
            @else
                <div class="table-wrap border-t border-sand-100 dark:border-ink-800"><table class="table">
                    <thead><tr><th>{{ __('finance_admin.collector') }}</th><th>{{ __('admin.branch') }}</th><th class="!text-end">{{ __('finance_admin.collections') }}</th><th class="!text-end">{{ __('finance_admin.amount_held') }}</th><th>{{ __('finance_admin.holding_since') }}</th><th></th></tr></thead>
                    <tbody>@foreach ($outstanding as $o)
                        @php $own = $o->collected_by === auth()->id(); $stale = \Carbon\CarbonImmutable::parse($o->oldest)->lt(now()->subDay()); @endphp
                        <tr wire:key="out-{{ $o->branch_id }}-{{ $o->collected_by }}">
                            <td class="text-sm font-medium">{{ $collectors[$o->collected_by] ?? '—' }}@if ($own) <span class="text-xs text-ink-500">({{ __('finance_admin.you') }})</span>@endif</td>
                            <td class="font-mono text-sm" dir="ltr">{{ $branchCodes[$o->branch_id] ?? '—' }}</td>
                            <td class="text-end tabular-nums">{{ $o->n }}</td>
                            <td class="text-end font-medium"><x-admin.money :value="$o->total" /></td>
                            <td class="text-xs tabular-nums {{ $stale ? 'text-ruby-soft' : 'text-ink-500' }}">{{ \App\Support\BusinessTime::display($o->oldest) }}@if ($stale) · {{ __('finance_admin.overdue') }}@endif</td>
                            <td class="text-end">
                                @if ($own)
                                    <span class="text-xs text-ink-500">{{ __('finance_admin.ask_colleague') }}</span>
                                @elseif ($this->can('payments.edit', $o->branch_id))
                                    <button type="button" wire:click="openHandIn('{{ $o->collected_by }}', '{{ $o->branch_id }}')" class="btn-primary btn-sm"><x-icon name="cash" class="size-4" />{{ __('finance_admin.receive_cash') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
            @endif
        </div>
    @elseif ($tab === 'handovers')
        <div class="card">
            <x-admin.toolbar>
                <x-admin.search :placeholder="__('finance_admin.handover_search')" />
                <select wire:model.live="filterHandover" class="input sm:w-48" aria-label="{{ __('admin.status') }}">
                    <option value="">{{ __('admin.all_statuses') }}</option>
                    <option value="in_drawer">{{ __('finance_admin.in_drawer') }}</option>
                    <option value="variance">{{ __('finance_admin.variances_to_review') }}</option>
                </select>
                @if ($branches->count() > 1)
                    <select wire:model.live="branch" class="input sm:w-36" aria-label="{{ __('admin.branch') }}"><option value="">{{ __('admin.all_branches') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }}</option>@endforeach</select>
                @endif
            </x-admin.toolbar>
            @if ($handoverRows->isEmpty())
                <x-admin.empty :title="__('finance_admin.handovers_empty')" :description="__('finance_admin.handovers_empty_hint')" icon="scale" />
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th class="w-8"></th><th>{{ __('finance_admin.handover') }}</th><th>{{ __('finance_admin.collector') }}</th><th class="!text-end">{{ __('finance_admin.expected') }}</th><th class="!text-end">{{ __('finance_admin.counted') }}</th><th>{{ __('finance_admin.variance') }}</th><th>{{ __('admin.status') }}</th><th></th></tr></thead>
                    <tbody>@foreach ($handoverRows as $h)
                        @php
                            $inDrawer = $h->deposited_at === null;
                            $canBank = $inDrawer && $this->can('payments.edit', $h->branch_id);
                            $involved = in_array(auth()->id(), [$h->collector_id, $h->received_by], true);
                        @endphp
                        <tr wire:key="ho-{{ $h->id }}">
                            <td>@if ($canBank)<input type="checkbox" wire:model.live="selected" value="{{ $h->id }}" class="rounded border-sand-300 text-brass-600 focus:ring-brass-500" aria-label="{{ __('finance_admin.select_for_deposit') }}">@endif</td>
                            <td><span class="font-mono text-sm" dir="ltr">{{ $h->number }}</span><span class="block text-xs text-ink-500 tabular-nums">{{ $h->branch?->code }} · {{ \App\Support\BusinessTime::display($h->created_at) }}</span></td>
                            <td class="text-sm"><bdi>{{ $h->collector?->name }}</bdi><span class="block text-xs text-ink-500">{{ __('finance_admin.received_by', ['name' => $h->receiver?->name]) }}</span></td>
                            <td class="text-end"><x-admin.money :value="$h->expected_amount" /></td>
                            <td class="text-end font-medium"><x-admin.money :value="$h->counted_amount" /></td>
                            <td>
                                @if ($h->status === 'balanced')
                                    <span class="text-xs text-emerald-soft">{{ __('finance_admin.balanced') }}</span>
                                @else
                                    <span class="text-sm font-medium tabular-nums {{ $h->status === 'short' ? 'text-ruby-soft' : 'text-amber-soft' }}" dir="ltr">{{ $money($h->variance) }}</span>
                                    <span class="block text-xs {{ $h->variance_status === 'open' ? 'text-amber-soft' : 'text-emerald-soft' }}">{{ __('finance_admin.variance_'.$h->variance_status) }}</span>
                                    @if ($h->note)<span class="block max-w-52 truncate text-xs text-ink-500" title="{{ $h->note }}">{{ $h->note }}</span>@endif
                                    @if ($h->variance_resolution)<span class="block max-w-52 truncate text-xs text-ink-500" title="{{ $h->variance_resolution }}">✓ {{ $h->variance_resolution }}</span>@endif
                                @endif
                            </td>
                            <td>
                                @if ($inDrawer)<x-admin.status value="in_drawer" />@else<x-admin.status value="deposited" /><span class="mt-1 block font-mono text-[0.7rem] text-ink-500" dir="ltr">{{ $h->deposit_reference }}</span>@endif
                            </td>
                            <td class="text-end">
                                @if ($h->variance_status === 'open' && ! $involved && $this->can('payments.override', $h->branch_id))
                                    <button type="button" wire:click="openResolve('{{ $h->id }}')" class="btn-secondary btn-sm">{{ __('finance_admin.review') }}</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
                @if ($handoverRows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $handoverRows->links('components.admin.pagination') }}</div>@endif
            @endif
            @if ($selected)
                <div class="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 border-t border-sand-200 bg-white/95 p-4 backdrop-blur dark:border-ink-700 dark:bg-ink-900/95">
                    <p class="text-sm"><span class="font-medium">{{ trans_choice('finance_admin.n_selected', count($selected), ['count' => count($selected)]) }}</span> · <span class="tabular-nums">{{ $selectedTotal?->format() }}</span></p>
                    <button type="button" wire:click="openDeposit" class="btn-primary"><x-icon name="building" class="size-4" />{{ __('finance_admin.make_deposit') }}</button>
                </div>
            @endif
        </div>
    @else
    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('finance_admin.cash_search')" />
            <select wire:model.live="filterContext" class="input sm:w-44" aria-label="{{ __('finance_admin.context') }}">
                <option value="">{{ __('finance_admin.all_contexts') }}</option>
                <option value="cod">{{ __('status.cod') }}</option><option value="pay_at_pickup">{{ __('status.pay_at_pickup') }}</option>
            </select>
            <select wire:model.live="filterTender" class="input sm:w-36" aria-label="{{ __('sales_admin.tender') }}">
                <option value="">{{ __('finance_admin.all_tenders') }}</option>
                <option value="cash">{{ __('sales_admin.tender_cash') }}</option><option value="card_terminal">{{ __('sales_admin.tender_card') }}</option>
            </select>
            <select wire:model.live="filterStatus" class="input sm:w-36" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['collected', 'partial', 'failed'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input sm:w-36" aria-label="{{ __('admin.branch') }}"><option value="">{{ __('admin.all_branches') }}</option>@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }}</option>@endforeach</select>
            @endif
            <div class="flex items-center gap-2">
                <input type="date" wire:model.live="filterFrom" class="input w-auto" aria-label="{{ __('sales_admin.from_date') }}">
                <span class="text-ink-400">–</span>
                <input type="date" wire:model.live="filterTo" class="input w-auto" aria-label="{{ __('sales_admin.to_date') }}">
            </div>
        </x-admin.toolbar>
        @if ($rows->isEmpty())
            <x-admin.empty :title="__('finance_admin.cash_empty')" :description="__('finance_admin.cash_empty_hint')" icon="cash" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('admin.date') }}</th><th>{{ __('admin.order') }}</th><th>{{ __('finance_admin.collector') }}</th><th>{{ __('finance_admin.context') }}</th><th class="!text-end">{{ __('finance_admin.due') }}</th><th class="!text-end">{{ __('finance_admin.collected') }}</th><th>{{ __('admin.status') }}</th></tr></thead>
                <tbody>@foreach ($rows as $c)
                    <tr wire:key="cc-{{ $c->id }}">
                        <td class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($c->collected_at) }}</td>
                        <td>@if ($c->payment?->order)<x-admin.order-peek-link :id="$c->payment->order_id" :number="$c->payment->order->number" :can="$this->can('orders.view', $c->branch_id)" class="text-sm" />@endif<span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $c->branch?->code }}@if ($c->receipt_reference) · {{ $c->receipt_reference }}@endif</span></td>
                        <td class="text-sm">{{ $c->collector?->name }}</td>
                        <td><span class="text-sm">{{ __('status.'.$c->context) }}</span><span class="block text-xs text-ink-500">{{ __('sales_admin.tender_'.($c->tender === 'card_terminal' ? 'card' : 'cash')) }}</span></td>
                        <td class="text-end"><x-admin.money :value="$c->amount_due" /></td>
                        <td class="text-end font-medium"><x-admin.money :value="$c->amount_collected" /></td>
                        <td><x-admin.status :value="$c->status" />@if ($c->failure_reason)<span class="mt-1 block text-xs text-ruby-soft">{{ \Illuminate\Support\Str::headline($c->failure_reason) }}</span>@endif
                            @if ($c->tender === 'cash' && $c->status !== 'failed')<span class="mt-1 block text-[0.7rem] {{ $c->handed_in_at ? 'text-emerald-soft' : 'text-amber-soft' }}">{{ $c->handed_in_at ? __('finance_admin.handed_in') : __('finance_admin.not_handed_in') }}</span>@endif</td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
    @endif

    @include('livewire.admin.sales.partials.order-peek')

    <x-admin.dialog wire:model="showDialog" :title="match ($dialog) { 'handin' => __('finance_admin.receive_cash'), 'resolve' => __('finance_admin.review_variance'), 'deposit' => __('finance_admin.make_deposit'), default => '' }"
                    :description="match ($dialog) { 'handin' => __('finance_admin.handin_hint'), 'resolve' => __('finance_admin.resolve_hint'), 'deposit' => __('finance_admin.deposit_hint'), default => null }">
        <form wire:submit="confirmDialog" class="space-y-5">
            @if ($dialog === 'handin' && $dialogExpected)
                @php
                    $counted = trim((string) ($form['counted'] ?? ''));
                    $diff = is_numeric($counted) ? \App\Support\Money::of($counted)->minus($dialogExpected) : null;
                @endphp
                <div class="rounded-xl bg-sand-50 p-4 text-sm dark:bg-ink-800">
                    <div class="flex items-center justify-between"><span class="text-ink-500">{{ __('finance_admin.expected') }}</span><span class="font-display text-lg font-semibold tabular-nums">{{ $dialogExpected->format() }}</span></div>
                    @if ($diff && ! $diff->isZero())
                        <div class="mt-2 flex items-center justify-between border-t border-sand-200 pt-2 dark:border-ink-700"><span class="text-ink-500">{{ __('finance_admin.variance') }}</span><span class="font-medium tabular-nums {{ $diff->isNegative() ? 'text-ruby-soft' : 'text-amber-soft' }}" dir="ltr">{{ $diff->format() }}</span></div>
                    @elseif ($diff)
                        <p class="mt-2 text-xs text-emerald-soft">{{ __('finance_admin.balanced') }}</p>
                    @endif
                </div>
                <x-admin.field :label="__('finance_admin.counted')" for="form.counted" required :hint="__('finance_admin.counted_hint')"><input id="form.counted" type="number" step="0.01" min="0" wire:model.live.debounce.300ms="form.counted" class="input tabular-nums" dir="ltr" autofocus></x-admin.field>
                <x-admin.field :label="__('admin.notes')" for="form.note" :required="$diff && ! $diff->isZero()" :hint="__('finance_admin.variance_note_hint')"><textarea id="form.note" wire:model="form.note" rows="2" class="input"></textarea></x-admin.field>
            @elseif ($dialog === 'resolve' && $dialogHandover)
                <div class="rounded-xl bg-sand-50 p-4 text-sm dark:bg-ink-800">
                    <p class="font-mono" dir="ltr">{{ $dialogHandover->number }}</p>
                    <p class="mt-1 text-ink-500"><bdi>{{ $dialogHandover->collector?->name }}</bdi> · {{ __('finance_admin.received_by', ['name' => $dialogHandover->receiver?->name]) }}</p>
                    <p class="mt-2 tabular-nums">{{ $money($dialogHandover->expected_amount) }} → {{ $money($dialogHandover->counted_amount) }} (<span dir="ltr">{{ $money($dialogHandover->variance) }}</span>)</p>
                    @if ($dialogHandover->note)<p class="mt-2 text-xs text-ink-500">“{{ $dialogHandover->note }}”</p>@endif
                </div>
                <x-admin.field :label="__('finance_admin.resolution')" for="form.resolution" required><textarea id="form.resolution" wire:model="form.resolution" rows="3" class="input"></textarea></x-admin.field>
            @elseif ($dialog === 'deposit')
                <div class="rounded-xl bg-sand-50 p-4 text-sm dark:bg-ink-800"><div class="flex items-center justify-between"><span class="text-ink-500">{{ trans_choice('finance_admin.n_selected', count($selected), ['count' => count($selected)]) }}</span><span class="font-display text-lg font-semibold tabular-nums">{{ $selectedTotal?->format() }}</span></div></div>
                <x-admin.field :label="__('finance_admin.bank_reference')" for="form.reference" required :hint="__('finance_admin.bank_reference_hint')"><input id="form.reference" wire:model="form.reference" class="input" dir="ltr" autofocus></x-admin.field>
            @endif
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.back') }}</button>
                <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="confirmDialog">{{ match ($dialog) { 'handin' => __('finance_admin.receive_cash'), 'resolve' => __('finance_admin.review_variance'), 'deposit' => __('finance_admin.make_deposit'), default => __('admin.confirm') } }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
