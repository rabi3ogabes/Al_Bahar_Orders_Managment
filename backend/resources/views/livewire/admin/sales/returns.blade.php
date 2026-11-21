<div>
    <x-admin.page-header :title="__('nav.returns')" :eyebrow="__('nav.sales')" :description="__('sales_admin.returns_hint')" />

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('sales_admin.returns_search')" />
            <select wire:model.live="filterStatus" class="input sm:w-48" aria-label="{{ __('admin.status') }}">
                <option value="open">{{ __('sales_admin.open_returns') }}</option>
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['requested', 'under_review', 'approved', 'rejected', 'received', 'refunded', 'closed', 'cancelled'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input sm:w-48" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <x-slot:meta>@if ($pending)<span class="badge-warning">{{ trans_choice('sales_admin.n_awaiting_decision', $pending, ['count' => $pending]) }}</span>@endif</x-slot:meta>
        </x-admin.toolbar>

        @if ($returns->isEmpty())
            <x-admin.empty :title="__('sales_admin.returns_empty')" :description="__('sales_admin.returns_empty_hint')" icon="refund" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('sales_admin.return') }}</th><th>{{ __('admin.order') }}</th><th>{{ __('admin.customer') }}</th><th>{{ __('admin.reason') }}</th><th class="!text-end">{{ __('sales_admin.lines') }}</th><th>{{ __('admin.status') }}</th><th class="w-12"></th></tr></thead>
                <tbody>@foreach ($returns as $rr)
                    <tr wire:key="rr-{{ $rr->id }}" class="cursor-pointer focus:outline-none" tabindex="-1" wire:click="select('{{ $rr->id }}')">
                        <td>
                            <a href="{{ route('admin.returns.index', ['view' => $rr->id]) }}" wire:click.prevent.stop="select('{{ $rr->id }}')" class="font-mono font-medium text-ink-900 hover:text-brass-600 dark:text-ivory" dir="ltr">{{ $rr->number }}</a>
                            <span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($rr->created_at) }}</span>
                        </td>
                        <td><x-admin.order-peek-link :id="$rr->order_id" :number="$rr->order->number" :can="$this->can('orders.view', $rr->branch_id)" class="text-sm" /></td>
                        <td class="max-w-[12rem] truncate">{{ $rr->order->customer_name }}</td>
                        <td><span class="badge-neutral">{{ __('returns.type.'.$rr->reason_code) }}</span>@if ($rr->photos_count)<span class="ms-1.5 inline-flex items-center gap-1 text-xs text-ink-500 tabular-nums" title="{{ trans_choice('returns_admin.photos_n', $rr->photos_count, ['count' => $rr->photos_count]) }}"><x-icon name="image" class="size-3.5" />{{ $rr->photos_count }}</span>@endif</td>
                        <td class="text-end tabular-nums">{{ collect($rr->items)->sum('quantity') }}</td>
                        <td><x-admin.status :value="$rr->status" /></td>
                        <td><span class="btn-ghost btn-sm pointer-events-none" aria-hidden="true"><x-icon name="chevron-right" class="size-4 rtl-flip" /></span></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            @if ($returns->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $returns->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    {{-- Claim side window: the facts (photos, what the customer wrote, the lines, the log) and the decision's inputs scroll in the body, the step's buttons are pinned in the footer. --}}
    @php
        $canApprove = $sel && $this->can('orders.approve', $sel->branch_id);
        $canEditRet = $sel && $this->can('orders.edit', $sel->branch_id);
        $canRefund = $sel && $this->can('refunds.create', $sel->branch_id);
        $undecided = $sel && in_array($sel->status, \App\Domain\Orders\Models\ReturnRequest::UNDECIDED, true);
        $goodsBack = $sel && in_array($sel->return_method, ['pickup', 'branch'], true);
        $refundNow = $sel && $canRefund && ($sel->status === 'received' || ($sel->status === 'approved' && $sel->return_method === 'none'));
        $showFooter = $sel && match (true) {
            $undecided => $canApprove || ($sel->status === 'requested' && $canEditRet),
            $sel->status === 'approved' => $canEditRet && $goodsBack || $refundNow,
            $sel->status === 'received' => $canApprove || $canRefund,
            $sel->status === 'refunded' => $canApprove,
            default => false,
        };
        $photoUrls = $sel ? $sel->photos->map(fn ($ph) => route('admin.returns.photo', [$sel->id, $ph->id]))->all() : [];
    @endphp
    <x-admin.dialog wire:model="showDetail" :title="$sel ? __('sales_admin.return').' '.$sel->number : ''" :description="$sel ? __('sales_admin.return_for', ['order' => $sel->order->number, 'name' => $sel->order->customer_name]) : null" max-width="max-w-2xl">
        @if ($sel)
            <x-admin.drawer-bar close="closeDetail">
                <x-admin.status :value="$sel->status" />
                <span class="badge-neutral">{{ __('returns.type.'.$sel->reason_code) }}</span>
                @if ($refund)<a href="{{ route('admin.refunds.index', ['search' => $refund->number]) }}" wire:navigate class="badge-info">{{ $refund->number }} · {{ __('status.'.$refund->status) }}</a>@endif
                <x-admin.order-peek-link :id="$sel->order_id" :number="$sel->order->number" :can="$this->can('orders.view', $sel->branch_id)" class="text-xs underline-offset-2 hover:underline" />
            </x-admin.drawer-bar>
            <div class="space-y-5">
                {{-- Photos: private files, shown through an authorised route. A tap opens a viewer you can zoom, step through and open at full size. --}}
                <section aria-labelledby="claim-photos">
                    <h3 id="claim-photos" class="text-sm font-semibold">{{ __('returns_admin.photos') }} <span class="font-normal text-ink-500">({{ count($photoUrls) }})</span></h3>
                    @if ($photoUrls === [])
                        <p class="mt-2 rounded-xl bg-sand-50 p-3 text-sm text-ink-500 dark:bg-ink-800/60">{{ __('returns_admin.photos_none') }}</p>
                    @else
                        <div x-data="{ at: null, zoom: false, urls: @js($photoUrls), total: {{ count($photoUrls) }},
                                       step(d) { this.at = (this.at + d + this.total) % this.total; this.zoom = false; } }"
                             @keydown.escape.window="at = null; zoom = false" @keydown.arrow-right.window="at !== null && step(1)" @keydown.arrow-left.window="at !== null && step(-1)">
                            <p class="mt-0.5 text-xs text-ink-500">{{ __('returns_admin.zoom_hint') }}</p>
                            <ul class="mt-2 grid grid-cols-3 gap-2 sm:grid-cols-5" wire:key="claim-photos-{{ $sel->id }}">
                                @foreach ($photoUrls as $n => $url)
                                    <li><button type="button" @click="at = {{ $n }}" class="block aspect-square w-full overflow-hidden rounded-xl border border-sand-200 bg-sand-50 focus-visible:outline-2 focus-visible:outline-brass-500 dark:border-ink-700" aria-label="{{ __('returns_admin.photo_n', ['n' => $n + 1, 'total' => count($photoUrls)]) }}">
                                        <img src="{{ $url }}" alt="{{ __('returns_admin.photo_alt', ['n' => $n + 1]) }}" loading="lazy" class="size-full object-cover"></button></li>
                                @endforeach
                            </ul>
                            <template x-teleport="body">
                                <div x-show="at !== null" x-cloak x-transition.opacity class="fixed inset-0 z-[90] flex flex-col bg-ink-950/90" role="dialog" aria-modal="true" aria-label="{{ __('returns_admin.photos') }}">
                                    <div class="flex items-center justify-between gap-3 p-3 text-ivory">
                                        <span class="text-sm tabular-nums" x-text="(at + 1) + ' / ' + total"></span>
                                        <div class="flex items-center gap-1">
                                            <button type="button" @click="zoom = ! zoom" class="btn-ghost min-h-11 min-w-11 text-ivory" :aria-label="zoom ? @js(__('returns_admin.zoom_out')) : @js(__('returns_admin.zoom_in'))"><x-icon name="search" class="size-5" /></button>
                                            <a :href="urls[at]" target="_blank" rel="noopener" class="btn-ghost min-h-11 text-ivory">{{ __('returns_admin.open_full') }}</a>
                                            <button type="button" @click="at = null; zoom = false" class="btn-ghost min-h-11 min-w-11 text-ivory" aria-label="{{ __('returns_admin.close') }}"><x-icon name="x" class="size-5" /></button>
                                        </div>
                                    </div>
                                    <div class="relative min-h-0 flex-1 overflow-auto p-3" @click.self="at = null; zoom = false">
                                        <template x-if="at !== null"><img :src="urls[at]" alt="" @click="zoom = ! zoom" class="mx-auto rounded-lg transition-all" :class="zoom ? 'max-w-none w-[220%] cursor-zoom-out' : 'max-h-full max-w-full cursor-zoom-in object-contain'"></template>
                                    </div>
                                    <template x-if="total > 1">
                                        <div class="flex items-center justify-center gap-3 p-3">
                                            <button type="button" @click="step(-1)" class="btn-secondary min-h-11 min-w-11" aria-label="{{ __('returns_admin.previous') }}"><x-icon name="chevron-right" class="size-5 ltr:rotate-180" /></button>
                                            <button type="button" @click="step(1)" class="btn-secondary min-h-11 min-w-11" aria-label="{{ __('returns_admin.next') }}"><x-icon name="chevron-right" class="size-5 rtl:rotate-180" /></button>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    @endif
                </section>

                @if ($sel->reason)
                    <section><h3 class="text-sm font-semibold">{{ __('returns_admin.customer_wrote') }}</h3><blockquote class="mt-2 rounded-xl bg-sand-50 p-3.5 text-sm text-ink-700 dark:bg-ink-800/60 dark:text-sand-100" dir="auto">“{{ $sel->reason }}”</blockquote></section>
                @endif
                @php $later = $sel->events->where('kind', 'evidence_added')->filter(fn ($e) => trim((string) $e->note) !== ''); @endphp
                @if ($later->isNotEmpty())
                    <section><h3 class="text-sm font-semibold">{{ __('returns_admin.customer_added') }}</h3>
                        <ul class="mt-2 space-y-2">@foreach ($later as $e)<li class="rounded-xl bg-sand-50 p-3 text-sm dark:bg-ink-800/60"><span dir="auto">“{{ $e->note }}”</span><span class="mt-1 block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($e->created_at) }}</span></li>@endforeach</ul></section>
                @endif

                {{-- The order's lines: what the claim is about, against what was delivered. --}}
                <section>
                    <h3 class="text-sm font-semibold">{{ __('returns_admin.order') }}</h3>
                    <div class="mt-2 overflow-hidden rounded-xl border border-sand-200/70 dark:border-ink-700"><div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('sales_admin.item') }}</th><th class="!text-end">{{ __('admin.quantity') }}</th><th class="!text-end">{{ __('sales_admin.unit_price') }}</th></tr></thead>
                        <tbody>@foreach ($sel->items as $line)
                            @php $it = $items[$line['order_item_id']] ?? null; @endphp
                            <tr><td>@if ($it)<span class="block font-medium">{{ $it->name() }}</span><span class="font-mono text-xs text-ink-500" dir="ltr">{{ $it->sku }}</span>@else — @endif</td>
                                <td class="text-end tabular-nums">{{ $it ? __('returns_admin.claimed_of', ['claimed' => $line['quantity'], 'handed' => $it->quantity_handed_over]) : $line['quantity'] }}</td><td class="text-end">@if ($it)<x-admin.money :value="$it->unit_final_price" />@endif</td></tr>
                        @endforeach</tbody>
                    </table></div></div>
                </section>

                @if ($sel->status === 'cancelled')
                    <p class="rounded-xl bg-sand-50 p-3 text-sm text-ink-600 dark:bg-ink-800/60 dark:text-sand-200">{{ __('returns_admin.cancelled_by_customer') }}</p>
                @endif
                @if ($sel->decision_note && ! $undecided)
                    <section><h3 class="text-sm font-semibold">{{ __('returns_admin.reply') }}</h3><p class="mt-2 rounded-xl bg-sand-50 p-3 text-sm dark:bg-ink-800/60" dir="auto">{{ $sel->decision_note }}</p></section>
                @endif
                @if ($sel->return_method && $sel->status !== 'rejected')
                    <p class="text-sm text-ink-600 dark:text-sand-200">{{ __('returns_admin.method_is', ['method' => __('returns.method.'.$sel->return_method)]) }}@if ($sel->method_note) · <span dir="auto">{{ $sel->method_note }}</span>@endif
                        @if ($sel->stock_decision) · {{ __('returns_admin.stock_result_'.$sel->stock_decision) }}@endif</p>
                @endif

                @if ($undecided && $canApprove)
                    <div class="space-y-4 border-t border-sand-100 pt-5 dark:border-ink-800">
                        <x-admin.field :label="__('returns_admin.reply')" for="note" :hint="__('returns_admin.reply_hint')"><textarea id="note" wire:model="note" rows="3" maxlength="500" class="input" dir="auto"></textarea></x-admin.field>
                        <fieldset>
                            <legend class="field-label">{{ __('returns_admin.method') }}</legend>
                            <div class="mt-1 grid gap-2 sm:grid-cols-3" role="radiogroup">
                                @foreach (['pickup', 'branch', 'none'] as $m)
                                    <label class="block cursor-pointer" wire:key="rm-{{ $m }}">
                                        <input type="radio" name="return-method" value="{{ $m }}" wire:model.live="method" class="peer sr-only">
                                        <span class="flex min-h-11 items-center rounded-xl border border-sand-200 p-3 text-sm transition peer-checked:border-brass-500 peer-checked:bg-brass-50 peer-focus-visible:outline-2 peer-focus-visible:outline-brass-500 dark:border-ink-700 dark:peer-checked:bg-brass-500/10">{{ __('returns_admin.method_'.$m) }}</span>
                                    </label>
                                @endforeach
                            </div>
                            @error('method')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        </fieldset>
                        @if ($method !== 'none')
                            <x-admin.field :label="__('returns_admin.method_note')" for="methodNote" :hint="__('returns_admin.method_note_hint')"><input id="methodNote" type="text" wire:model="methodNote" maxlength="300" class="input" dir="auto"></x-admin.field>
                        @endif
                    </div>
                @elseif ($sel->status === 'approved' && $canEditRet && $goodsBack)
                    <fieldset class="space-y-2 border-t border-sand-100 pt-5 dark:border-ink-800">
                        <legend class="field-label">{{ __('returns_admin.stock') }}</legend>
                        @if ($sel->isDefect())<p class="text-xs text-ink-500">{{ __('returns_admin.stock_defect_note') }}</p>@endif
                        <div class="grid gap-2 sm:grid-cols-2" role="radiogroup">
                            @foreach (['restock', 'write_off'] as $c)
                                <label class="block cursor-pointer" wire:key="sc-{{ $c }}">
                                    <input type="radio" name="stock-choice" value="{{ $c }}" wire:model.live="stockChoice" class="peer sr-only">
                                    <span class="block min-h-11 rounded-xl border border-sand-200 p-3 transition peer-checked:border-brass-500 peer-checked:bg-brass-50 peer-focus-visible:outline-2 peer-focus-visible:outline-brass-500 dark:border-ink-700 dark:peer-checked:bg-brass-500/10">
                                        <span class="block text-sm font-medium">{{ __('returns_admin.stock_'.$c) }}</span><span class="mt-0.5 block text-xs text-ink-500">{{ __('returns_admin.stock_'.$c.'_hint') }}</span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                @if ($refundNow)
                    <div class="space-y-3 border-t border-sand-100 pt-5 dark:border-ink-800">
                        <h3 class="font-semibold">{{ __('sales_admin.raise_refund') }}</h3>
                        @if ($sel->status === 'approved')<p class="text-xs text-ink-500">{{ __('returns_admin.refund_no_goods') }}</p>@endif
                        <div class="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                            <x-admin.field :label="__('finance_admin.amount')" for="refundAmount" required><input id="refundAmount" type="number" step="0.01" min="0.01" wire:model="refundAmount" class="input tabular-nums" dir="ltr"></x-admin.field>
                            <x-admin.field :label="__('finance_admin.method')" for="refundMethod">
                                <select id="refundMethod" wire:model="refundMethod" class="input">@foreach (['original', 'manual_cash', 'manual_bank_transfer'] as $m)<option value="{{ $m }}">{{ __('finance_admin.method_'.$m) }}</option>@endforeach</select>
                            </x-admin.field>
                        </div>
                        <p class="text-xs text-ink-500">{{ __('sales_admin.refund_dual_control_hint') }}</p>
                    </div>
                @endif

                {{-- The claim's own log: who did what, and what they wrote. --}}
                <section class="border-t border-sand-100 pt-5 dark:border-ink-800">
                    <h3 class="text-sm font-semibold">{{ __('returns_admin.log') }}</h3>
                    <ol class="mt-3 space-y-3">
                        @foreach ($sel->events as $e)
                            <li class="text-sm" wire:key="ev-{{ $e->id }}">
                                <span class="font-medium">{{ __('returns_admin.ev.'.$e->kind) }}</span>
                                <span class="text-xs text-ink-500 tabular-nums"> · {{ $e->actor_type === 'staff' ? ($actors[$e->actor_id] ?? '—') : __('returns_admin.actor_'.$e->actor_type) }} · {{ \App\Support\BusinessTime::display($e->created_at) }}</span>
                                @if ($e->note)<span class="mt-0.5 block text-ink-600 dark:text-sand-200" dir="auto">{{ $e->note }}</span>@endif
                            </li>
                        @endforeach
                    </ol>
                </section>
            </div>
        @endif
        @if ($showFooter)
            <x-slot:footer>
                <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    @if ($undecided)
                        @if ($sel->status === 'requested' && $canEditRet)<button type="button" wire:click="startReview" wire:loading.attr="disabled" class="btn-secondary min-h-11">{{ __('returns_admin.start_review') }}</button>@endif
                        @if ($canApprove)
                            <button type="button" wire:click="decide(false)" wire:loading.attr="disabled" class="btn-secondary min-h-11 text-ruby-soft">{{ __('admin.reject') }}</button>
                            <button type="button" wire:click="decide(true)" wire:loading.attr="disabled" class="btn-primary min-h-11"><x-icon name="check" class="size-4" />{{ __('sales_admin.approve_return') }}</button>
                        @endif
                    @elseif ($sel->status === 'approved')
                        @if ($canEditRet && $goodsBack)<button type="button" wire:click="receive" wire:confirm="{{ __('sales_admin.confirm_receive_return') }}" class="btn-primary min-h-11"><x-icon name="download" class="size-4" />{{ __('sales_admin.mark_received') }}</button>@endif
                        @if ($refundNow)<button type="button" wire:click="refund" wire:loading.attr="disabled" class="btn-primary min-h-11"><x-icon name="refund" class="size-4" />{{ __('sales_admin.raise_refund') }}</button>@endif
                    @elseif ($sel->status === 'received')
                        @if ($canApprove)<button type="button" wire:click="close" class="btn-secondary min-h-11">{{ __('sales_admin.close_without_refund') }}</button>@endif
                        @if ($canRefund)<button type="button" wire:click="refund" wire:loading.attr="disabled" class="btn-primary min-h-11"><x-icon name="refund" class="size-4" />{{ __('sales_admin.raise_refund') }}</button>@endif
                    @elseif ($sel->status === 'refunded')
                        <button type="button" wire:click="close" class="btn-primary min-h-11">{{ __('sales_admin.close_return') }}</button>
                    @endif
                </div>
            </x-slot:footer>
        @endif
    </x-admin.dialog>

    @include('livewire.admin.sales.partials.order-peek')
</div>
