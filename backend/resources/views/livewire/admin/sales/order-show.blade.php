@php
    $addr = $o->address_snapshot ?? [];
    $stepMeta = [
        'accepted' => ['check', __('sales_admin.act_accept')],
        'preparing' => ['package', __('sales_admin.act_prepare')],
        'ready' => ['boxes', __('sales_admin.act_ready')],
    ];
@endphp
<div class="mx-auto max-w-7xl">
    <a href="{{ route('admin.orders.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.orders') }}</a>

    <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div class="min-w-0">
            <p class="eyebrow mb-2">{{ __('admin.order') }}</p>
            <h1 class="font-mono text-2xl font-semibold tracking-tight text-ink-900 sm:text-3xl dark:text-ivory" dir="ltr">{{ $o->number }}</h1>
            <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-ink-500">
                <x-admin.status :value="$o->status->value" />
                <x-admin.status :value="$o->payment_status->value" />
                <span class="badge-neutral">{{ __('status.'.$o->payment_method) }}</span>
                <span class="tabular-nums">{{ __('sales_admin.placed_at', ['at' => \App\Support\BusinessTime::display($o->placed_at)]) }}</span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.orders.invoice', $o) }}" class="btn-secondary" target="_blank" rel="noopener"><x-icon name="printer" class="size-4" />{{ __('sales_admin.invoice_pdf') }}</a>
            @if ($cancellable)
                <button type="button" wire:click="open('cancel_order')" class="btn-ghost text-ruby-soft"><x-icon name="ban" class="size-4" />{{ __('sales_admin.cancel_order') }}</button>
            @endif
        </div>
    </div>

    @if ($pendingCancellation)
        <div class="card mb-6 border-amber-soft/30 bg-amber-soft/5 p-5 sm:p-6">
            <div class="flex flex-col gap-4 md:flex-row md:items-start">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-soft/15 text-amber-soft"><x-icon name="alert" class="size-5" /></span>
                <div class="min-w-0 flex-1">
                    <h2 class="font-display text-xl font-semibold">{{ __('sales_admin.cancellation_requested_title') }}</h2>
                    <p class="mt-1 text-sm text-ink-600 dark:text-sand-200">{{ __('sales_admin.cancellation_requested_by', ['name' => $pendingCancellation->requester?->name ?? '—', 'at' => \App\Support\BusinessTime::display($pendingCancellation->created_at)]) }}</p>
                    <p class="mt-2 text-sm"><span class="badge-warning">{{ \Illuminate\Support\Str::headline($pendingCancellation->reason_code) }}</span> @if ($pendingCancellation->reason)<span class="ms-1 text-ink-700 dark:text-sand-100">“{{ $pendingCancellation->reason }}”</span>@endif</p>
                    @if ($canDecide)
                        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                            <input wire:model="form.note" class="input flex-1" placeholder="{{ __('sales_admin.decision_note_ph') }}" aria-label="{{ __('admin.notes') }}">
                            <div class="flex gap-2">
                                <button type="button" wire:click="decideCancellation('{{ $pendingCancellation->id }}', false)" class="btn-secondary">{{ __('sales_admin.keep_order') }}</button>
                                <button type="button" wire:click="decideCancellation('{{ $pendingCancellation->id }}', true)" wire:confirm="{{ __('sales_admin.confirm_approve_cancellation') }}" class="btn-danger">{{ __('sales_admin.approve_cancellation') }}</button>
                            </div>
                        </div>
                    @else
                        <p class="mt-3 text-xs text-ink-500">{{ __('sales_admin.cancellation_needs_approver') }}</p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3 [&>*]:min-w-0">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            {{-- Fulfillments --}}
            @foreach ($o->fulfillments as $f)
                @php $act = $actions[$f->id]; $d = $act['delivery']; @endphp
                <section class="card overflow-hidden" wire:key="f-{{ $f->id }}">
                    <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="grid size-9 place-items-center rounded-xl bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon :name="$f->type === 'pickup' ? 'store' : 'truck'" class="size-4" /></span>
                                <div class="min-w-0">
                                    <h2 class="truncate font-display text-xl font-semibold">{{ $f->branch->localized('name') }}</h2>
                                    <p class="text-xs text-ink-500"><span class="font-mono" dir="ltr">{{ $f->number }}</span> · {{ __('sales_admin.type_'.$f->type) }}@if ($f->slot_date) · {{ \App\Support\BusinessTime::display($f->slot_date, 'd M') }}@endif</p>
                                </div>
                            </div>
                        </div>
                        <x-admin.status :value="$f->status->value" class="self-start" />
                    </div>
                    <div class="table-wrap border-t border-sand-100 dark:border-ink-800">
                        <table class="table">
                            <thead><tr><th>{{ __('sales_admin.item') }}</th><th class="!text-end">{{ __('admin.quantity') }}</th><th class="!text-end">{{ __('sales_admin.handed_over') }}</th><th class="!text-end">{{ __('sales_admin.unit_price') }}</th><th class="!text-end">{{ __('admin.total') }}</th></tr></thead>
                            <tbody>
                            @foreach ($f->items as $it)
                                <tr wire:key="it-{{ $it->id }}">
                                    <td class="max-w-[18rem]">
                                        <span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $it->name() }}</span>
                                        <span class="flex flex-wrap items-center gap-x-2 text-xs text-ink-500"><span class="font-mono" dir="ltr">{{ $it->sku }}</span>@if ($it->variantName())<span>· {{ $it->variantName() }}</span>@endif
                                            @if ($it->quantity_cancelled)<span class="text-ruby-soft">· {{ __('sales_admin.n_cancelled', ['n' => $it->quantity_cancelled]) }}</span>@endif
                                            @if ($it->quantity_returned)<span class="text-amber-soft">· {{ __('sales_admin.n_returned', ['n' => $it->quantity_returned]) }}</span>@endif
                                        </span>
                                    </td>
                                    <td class="text-end tabular-nums">{{ $it->quantity }}</td>
                                    <td class="text-end tabular-nums {{ $it->quantity_handed_over ? 'text-emerald-soft' : 'text-ink-400' }}">{{ $it->quantity_handed_over ?: '—' }}</td>
                                    <td class="text-end"><x-admin.money :value="$it->unit_final_price" /></td>
                                    <td class="text-end font-medium"><x-admin.money :value="$it->line_total" /></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($d)
                        <div class="flex flex-wrap items-center gap-3 border-t border-sand-100 bg-sand-50/50 px-5 py-3 text-sm sm:px-6 dark:border-ink-800 dark:bg-ink-800/30">
                            <x-icon name="truck" class="size-4 text-brass-500" />
                            <span class="font-mono text-xs" dir="ltr">{{ $d->tracking_number }}</span>
                            <x-admin.status :value="$d->status->value" />
                            <span class="text-ink-500">{{ $d->mode === 'provider' ? ($d->provider?->name ?? __('sales_admin.provider')) : ($d->driver?->name ?? __('sales_admin.unassigned')) }}</span>
                            @if ($d->scheduled_for)<span class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($d->scheduled_for) }}</span>@endif
                            @if ($this->can('deliveries.view', $f->branch_id))<a href="{{ route('admin.deliveries.index', ['search' => $d->tracking_number]) }}" wire:navigate class="ms-auto text-xs font-medium text-brass-600 hover:underline">{{ __('sales_admin.open_dispatch') }}</a>@endif
                        </div>
                    @endif
                    @if ($f->type === 'pickup' && $f->pickup && in_array($f->pickup->status, ['ready', 'partially_collected'], true))
                        <div class="flex flex-wrap items-center gap-3 border-t border-sand-100 bg-sky-soft/5 px-5 py-3 text-sm sm:px-6 dark:border-ink-800">
                            <x-icon name="qr" class="size-4 text-sky-soft" />
                            <span>{{ __('sales_admin.pass_active_until', ['at' => \App\Support\BusinessTime::display($f->pickup->token_expires_at)]) }}</span>
                            @if ($act['scan'])<a href="{{ route('admin.pickup.scan', ['branch' => $f->branch_id]) }}" wire:navigate class="ms-auto text-xs font-medium text-brass-600 hover:underline">{{ __('sales_admin.open_scanner') }}</a>@endif
                        </div>
                    @endif

                    @if ($act['steps'] || $act['cancel'] || $act['return'])
                        <div class="flex flex-col gap-2 border-t border-sand-100 p-4 sm:flex-row sm:flex-wrap sm:items-center sm:px-6 dark:border-ink-800">
                            @if ($act['cancel'])<button type="button" wire:click="open('cancel_fulfillment', '{{ $f->id }}')" class="btn-ghost text-ruby-soft sm:me-auto"><x-icon name="x" class="size-4" />{{ __('sales_admin.cancel_branch_part') }}</button>@endif
                            @if ($act['return'])<button type="button" wire:click="open('return_fulfillment', '{{ $f->id }}')" class="btn-ghost sm:me-auto"><x-icon name="refund" class="size-4" />{{ __('sales_admin.mark_returned') }}</button>@endif
                            @foreach ($act['steps'] as $s)
                                @if ($s['action'] === 'transition')
                                    <button type="button" wire:click="advance('{{ $f->id }}', '{{ $s['to'] }}')" wire:loading.attr="disabled" wire:target="advance" class="{{ $loop->last ? 'btn-primary' : 'btn-secondary' }}"><x-icon :name="$stepMeta[$s['to']][0]" class="size-4" />{{ $stepMeta[$s['to']][1] }}</button>
                                @elseif ($s['action'] === 'pickup_ready')
                                    <button type="button" wire:click="markPickupReady('{{ $f->id }}')" wire:loading.attr="disabled" wire:target="markPickupReady" class="btn-primary"><x-icon name="qr" class="size-4" />{{ __('sales_admin.act_pickup_ready') }}</button>
                                @elseif ($s['action'] === 'delivery')
                                    <button type="button" wire:click="open('delivery', '{{ $f->id }}')" class="btn-primary"><x-icon name="truck" class="size-4" />{{ __('sales_admin.act_arrange_delivery') }}</button>
                                @endif
                            @endforeach
                        </div>
                    @elseif ($o->status->value === 'pending_payment' && ! $f->status->isTerminal())
                        <p class="border-t border-sand-100 px-5 py-3 text-xs text-ink-500 sm:px-6 dark:border-ink-800"><x-icon name="clock" class="me-1 inline size-3.5" />{{ __('sales_admin.awaiting_payment_hint') }}</p>
                    @endif
                </section>
            @endforeach

            {{-- Payments --}}
            <section class="card overflow-hidden">
                <div class="flex items-center justify-between p-5 sm:p-6">
                    <h2 class="font-display text-xl font-semibold">{{ __('nav.payments') }}</h2>
                </div>
                @if ($payments->isEmpty())
                    <p class="border-t border-sand-100 px-6 py-8 text-center text-sm text-ink-500 dark:border-ink-800">{{ __('sales_admin.no_payments') }}</p>
                @else
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($payments as $p)
                            <li class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:px-6" wire:key="p-{{ $p->id }}">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium">{{ __('status.'.$p->method) }}</span>
                                        <x-admin.status :value="$p->status->value" />
                                        <span class="text-xs text-ink-500">{{ __('sales_admin.attempt_n', ['n' => $p->attempt]) }}</span>
                                        @if (! empty($p->metadata['late_capture']))<span class="badge-danger">{{ __('finance_admin.flag_late_capture') }}</span>@endif
                                    </div>
                                    <p class="mt-1 text-xs text-ink-500">
                                        {{ __('sales_admin.captured') }} <x-admin.money :value="$p->captured_amount" />
                                        @if ((float) $p->refunded_amount > 0) · {{ __('sales_admin.refunded') }} <x-admin.money :value="$p->refunded_amount" />@endif
                                        @foreach ($p->collections as $c) · {{ __('sales_admin.collected_by', ['name' => $c->collector?->name ?? '—']) }} ({{ __('status.'.$c->status) }})@endforeach
                                    </p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <x-admin.money :value="$p->amount" class="font-semibold" />
                                    @if ($canPayments)<a href="{{ route('admin.payments.show', $p) }}" wire:navigate class="btn-ghost btn-sm" aria-label="{{ __('admin.details') }}"><x-icon name="chevron-right" class="size-4 rtl-flip" /></a>@endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Returns --}}
            @if ($returns->isNotEmpty())
                <section class="card overflow-hidden">
                    <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('nav.returns') }}</h2></div>
                    <ul class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                        @foreach ($returns as $rr)
                            <li class="flex items-center gap-3 px-5 py-3.5 sm:px-6" wire:key="rr-{{ $rr->id }}">
                                <span class="font-mono text-sm" dir="ltr">{{ $rr->number }}</span>
                                <x-admin.status :value="$rr->status" />
                                <span class="truncate text-sm text-ink-500">{{ __('sales_admin.reason_'.$rr->reason_code) }}</span>
                                <a href="{{ route('admin.returns.index', ['search' => $rr->number]) }}" wire:navigate class="ms-auto text-xs font-medium text-brass-600 hover:underline">{{ __('sales_admin.review_return') }}</a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Internal notes --}}
            <section class="card card-pad">
                <h2 class="mb-4 font-display text-xl font-semibold">{{ __('sales_admin.internal_notes') }}</h2>
                <form wire:submit="addNote" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div class="flex-1">
                        <textarea wire:model="note" rows="2" class="input" placeholder="{{ __('sales_admin.note_ph') }}" aria-label="{{ __('sales_admin.internal_notes') }}"></textarea>
                        @error('note')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="addNote"><x-icon name="message" class="size-4" />{{ __('sales_admin.add_note') }}</button>
                </form>
                @if ($o->notes->isNotEmpty())
                    <ul class="mt-5 space-y-3">
                        @foreach ($o->notes->sortByDesc('created_at') as $n)
                            <li class="rounded-xl bg-sand-50 p-3.5 dark:bg-ink-800/60" wire:key="n-{{ $n->id }}">
                                <p class="whitespace-pre-line text-sm text-ink-800 dark:text-sand-100">{{ $n->body }}</p>
                                <p class="mt-1.5 text-xs text-ink-500">{{ $n->author?->name }} · <span class="tabular-nums">{{ \App\Support\BusinessTime::display($n->created_at) }}</span></p>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <aside class="min-w-0 space-y-6">
            <section class="card card-pad">
                <div class="mb-4 flex items-center justify-between gap-2">
                    <h2 class="font-display text-xl font-semibold">{{ __('admin.customer') }}</h2>
                    @if ($canCustomer)<a href="{{ route('admin.customers.show', $o->user_id) }}" wire:navigate class="text-xs font-medium text-brass-600 hover:underline">{{ __('sales_admin.view_profile') }}</a>@endif
                </div>
                <p class="font-medium text-ink-900 dark:text-ivory">{{ $o->customer_name }}</p>
                <div class="mt-2 space-y-1.5 text-sm text-ink-600 dark:text-sand-200">
                    @if ($o->customer_phone)<a href="tel:{{ $o->customer_phone }}" class="flex items-center gap-2 hover:text-brass-600"><x-icon name="phone" class="size-4 text-ink-400" /><span dir="ltr">{{ $o->customer_phone }}</span></a>@endif
                    @if ($o->customer_email)<p class="flex items-center gap-2"><x-icon name="mail" class="size-4 shrink-0 text-ink-400" /><span class="truncate">{{ $o->customer_email }}</span></p>@endif
                    @if ($addr)
                        <p class="flex items-start gap-2"><x-icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-ink-400" />
                            <span>{{ collect([__('sales_admin.zone_n', ['n' => $addr['zone_number'] ?? '—']), __('sales_admin.street_n', ['n' => $addr['street_number'] ?? '—']), __('sales_admin.building_n', ['n' => $addr['building_number'] ?? '—']), $addr['unit_number'] ?? null, $addr['area'] ?? null])->filter()->implode(', ') }}@if (! empty($addr['landmark']))<span class="block text-xs text-ink-500">{{ $addr['landmark'] }}</span>@endif</span></p>
                    @endif
                </div>
                @if ($o->customer_notes)<p class="mt-4 rounded-xl bg-brass-50 p-3 text-sm text-brass-700 dark:bg-brass-500/10 dark:text-brass-300"><x-icon name="message" class="me-1 inline size-3.5" />{{ $o->customer_notes }}</p>@endif
            </section>

            <section class="card card-pad">
                <h2 class="mb-4 font-display text-xl font-semibold">{{ __('sales_admin.summary') }}</h2>
                <dl class="space-y-2 text-sm">
                    @foreach ([['subtotal', 'subtotal'], ['discount', 'discount_total'], ['delivery_fee', 'delivery_fee_total'], ['service_fee', 'service_fee_total'], ['loyalty', 'loyalty_discount'], ['tax', 'tax_total']] as [$k, $field])
                        @if ($k === 'subtotal' || (float) $o->{$field} > 0)
                            <div class="flex justify-between gap-4"><dt class="text-ink-500">{{ __('sales_admin.sum_'.$k) }}</dt><dd>@if (in_array($k, ['discount', 'loyalty'], true))−@endif<x-admin.money :value="$o->{$field}" /></dd></div>
                        @endif
                    @endforeach
                    @foreach ($o->coupons as $c)<div class="flex justify-between gap-4 text-xs"><dt class="text-ink-500">{{ app()->getLocale() === 'ar' ? $c->label_ar : $c->label_en }} @if ($c->code)<span class="font-mono" dir="ltr">({{ $c->code }})</span>@endif</dt><dd>−<x-admin.money :value="$c->discount_amount" /></dd></div>@endforeach
                    <div class="flex justify-between gap-4 border-t border-sand-100 pt-3 text-base font-semibold dark:border-ink-800"><dt>{{ __('admin.total') }}</dt><dd><x-admin.money :value="$o->grand_total" /></dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-ink-500">{{ __('sales_admin.sum_paid') }}</dt><dd class="text-emerald-soft"><x-admin.money :value="$o->paid_total" /></dd></div>
                    @if ((float) $o->refunded_total > 0)<div class="flex justify-between gap-4"><dt class="text-ink-500">{{ __('sales_admin.refunded') }}</dt><dd class="text-ruby-soft"><x-admin.money :value="$o->refunded_total" /></dd></div>@endif
                </dl>
            </section>

            <section class="card card-pad">
                <h2 class="mb-5 font-display text-xl font-semibold">{{ __('sales_admin.timeline') }}</h2>
                @if ($timeline)
                    <ol class="relative space-y-4">
                        @foreach ($timeline as $h)
                            <li class="relative flex gap-3">
                                @if (! $loop->last)<span class="absolute top-6 bottom-[-1rem] start-[0.6875rem] w-px bg-sand-200 dark:bg-ink-700" aria-hidden="true"></span>@endif
                                <span class="relative z-10 mt-0.5 grid size-6 shrink-0 place-items-center rounded-full {{ $h['state'] === 'failed' ? 'bg-ruby-soft/15 text-ruby-soft' : ($loop->first ? 'bg-brass-500 text-ink-950' : 'bg-sand-100 text-ink-500 dark:bg-ink-800') }}"><span class="size-1.5 rounded-full bg-current"></span></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-ink-900 dark:text-ivory">{{ $h['label'] }} @if ($h['internal'])<span class="badge-neutral ms-1 !text-[0.65rem]">{{ __('sales_admin.internal') }}</span>@endif</p>
                                    @if ($h['reason'])<p class="text-xs text-ink-600 dark:text-sand-200">{{ $h['reason'] }}</p>@endif
                                    <p class="text-xs text-ink-500 tabular-nums">{{ $h['at'] }}@if ($h['by']) · {{ $h['by'] }}@endif</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @else
                    <p class="text-sm text-ink-500">{{ __('sales_admin.no_history') }}</p>
                @endif
            </section>
        </aside>
    </div>

    <x-admin.dialog wire:model="showDialog" :title="match ($dialog) { 'cancel_order' => __('sales_admin.cancel_order'), 'cancel_fulfillment' => __('sales_admin.cancel_branch_part'), 'return_fulfillment' => __('sales_admin.mark_returned'), 'delivery' => __('sales_admin.act_arrange_delivery'), default => '' }"
                    :description="match ($dialog) { 'cancel_order' => __('sales_admin.cancel_order_hint'), 'cancel_fulfillment' => __('sales_admin.cancel_branch_hint'), 'return_fulfillment' => __('sales_admin.return_fulfillment_hint'), 'delivery' => __('sales_admin.arrange_delivery_hint'), default => null }">
        <form wire:submit="confirmDialog" class="space-y-5">
            @if ($dialog === 'delivery')
                <div class="grid grid-cols-2 gap-2" role="radiogroup">
                    @foreach (['internal' => ['user', __('sales_admin.mode_internal')], 'provider' => ['truck', __('sales_admin.mode_provider')]] as $m => [$icon, $label])
                        <label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm transition {{ ($form['mode'] ?? '') === $m ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}">
                            <input type="radio" wire:model.live="form.mode" value="{{ $m }}" class="sr-only"><x-icon :name="$icon" class="size-4 text-brass-600" />{{ $label }}
                        </label>
                    @endforeach
                </div>
                @if (($form['mode'] ?? '') === 'provider')
                    <x-admin.field :label="__('sales_admin.provider')" for="form.provider_id" required>
                        <select id="form.provider_id" wire:model="form.provider_id" class="input"><option value="">—</option>@foreach ($providers as $pv)<option value="{{ $pv->id }}">{{ $pv->name }}</option>@endforeach</select>
                    </x-admin.field>
                    @if ($providers->isEmpty())<p class="text-xs text-ink-500">{{ __('sales_admin.no_providers') }}</p>@endif
                @else
                    <x-admin.field :label="__('sales_admin.driver')" for="form.driver_id" :hint="__('sales_admin.driver_optional')">
                        <select id="form.driver_id" wire:model="form.driver_id" class="input"><option value="">{{ __('sales_admin.assign_later') }}</option>@foreach ($drivers as $dr)<option value="{{ $dr->id }}">{{ $dr->name }}</option>@endforeach</select>
                    </x-admin.field>
                @endif
                <x-admin.field :label="__('sales_admin.scheduled_for')" for="form.scheduled_for"><input id="form.scheduled_for" type="datetime-local" wire:model="form.scheduled_for" class="input"></x-admin.field>
            @elseif ($dialog)
                <x-admin.field :label="__('sales_admin.reason_code')" for="form.reason_code" required>
                    <select id="form.reason_code" wire:model="form.reason_code" class="input">
                        @foreach (['customer_request', 'out_of_stock', 'payment_issue', 'fraud_suspected', 'duplicate_order', 'damaged', 'other'] as $rc)<option value="{{ $rc }}">{{ __('sales_admin.rc_'.$rc) }}</option>@endforeach
                    </select>
                </x-admin.field>
                <x-admin.field :label="__('admin.reason')" for="form.reason" required><textarea id="form.reason" wire:model="form.reason" rows="3" class="input" placeholder="{{ __('sales_admin.reason_ph') }}"></textarea></x-admin.field>
                @if ($dialog === 'cancel_order' && $o->payment_status->isSettled())<p class="rounded-xl bg-sky-soft/5 p-3 text-xs text-sky-soft dark:text-sky-300"><x-icon name="info" class="me-1 inline size-3.5" />{{ __('sales_admin.cancel_refund_notice') }}</p>@endif
            @endif
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.back') }}</button>
                <button class="{{ in_array($dialog, ['cancel_order', 'cancel_fulfillment'], true) ? 'btn-danger' : 'btn-primary' }}" wire:loading.attr="disabled" wire:target="confirmDialog">{{ __('admin.confirm') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
