@php
    $nav = function ($a) {
        if (! empty($a['latitude']) && ! empty($a['longitude'])) {
            return 'https://www.google.com/maps/dir/?api=1&destination='.$a['latitude'].','.$a['longitude'];
        }
        $q = collect(['Zone '.($a['zone_number'] ?? ''), 'Street '.($a['street_number'] ?? ''), 'Building '.($a['building_number'] ?? ''), $a['area'] ?? null, 'Qatar'])->filter()->implode(', ');

        return 'https://www.google.com/maps/search/?api=1&query='.urlencode($q);
    };
    $steps = ['assigned' => 1, 'accepted' => 2, 'picked_up' => 3, 'out_for_delivery' => 4];
@endphp
<div wire:poll.60s>
    <div class="mb-5 grid grid-cols-3 gap-2 text-center">
        <div class="rounded-2xl bg-white p-3 shadow-[var(--shadow-card)] dark:bg-ink-900"><p class="font-display text-2xl font-semibold tabular-nums">{{ $active->count() }}</p><p class="text-[0.7rem] text-ink-500">{{ __('sales_admin.drv_to_do') }}</p></div>
        <div class="rounded-2xl bg-white p-3 shadow-[var(--shadow-card)] dark:bg-ink-900"><p class="font-display text-2xl font-semibold tabular-nums text-emerald-soft">{{ $done->where('status.value', 'delivered')->count() }}</p><p class="text-[0.7rem] text-ink-500">{{ __('sales_admin.drv_delivered') }}</p></div>
        <div class="rounded-2xl bg-white p-3 shadow-[var(--shadow-card)] dark:bg-ink-900"><p class="font-display text-lg font-semibold leading-8"><x-admin.money :value="number_format($codTotal, 2, '.', '')" /></p><p class="text-[0.7rem] text-ink-500">{{ __('sales_admin.drv_cash_today') }}</p></div>
    </div>

    @if ($cashToHandIn->isPositive())
        <div class="mb-4 flex items-center gap-3 rounded-2xl border border-amber-soft/40 bg-amber-soft/10 p-3 text-sm">
            <x-icon name="cash" class="size-5 shrink-0 text-amber-soft" />
            <p class="min-w-0 flex-1"><span class="font-semibold tabular-nums">{{ $cashToHandIn->format() }}</span> — {{ __('sales_admin.drv_cash_to_hand_in') }}</p>
        </div>
    @endif

    @if ($active->isEmpty())
        <div class="rounded-3xl bg-white shadow-[var(--shadow-card)] dark:bg-ink-900">
            <x-admin.empty :title="__('sales_admin.drv_empty')" :description="$later ? trans_choice('sales_admin.drv_later', $later, ['count' => $later]) : __('sales_admin.drv_empty_hint')" icon="truck" />
        </div>
    @endif

    <ol class="space-y-4">
        @foreach ($active as $d)
            @php
                $o = $d->fulfillment->order; $a = $o->address_snapshot ?? []; $st = $d->status->value;
                $codDue = $o->payment_method === 'cod' && ! $o->payment_status->isSettled();
                $units = $d->fulfillment->items->sum(fn ($i) => $i->quantity - $i->quantity_cancelled);
            @endphp
            <li class="overflow-hidden rounded-3xl bg-white shadow-[var(--shadow-card)] dark:bg-ink-900" wire:key="drv-{{ $d->id }}">
                <div class="p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-mono text-xs text-ink-500" dir="ltr">{{ $o->number }}</p>
                            <p class="truncate text-lg font-semibold text-ink-900 dark:text-ivory">{{ $o->customer_name }}</p>
                        </div>
                        <x-admin.status :value="$st" />
                    </div>
                    <div class="mt-3 flex gap-1" aria-hidden="true">
                        @for ($i = 1; $i <= 4; $i++)<span class="h-1 flex-1 rounded-full {{ ($steps[$st] ?? 0) >= $i ? 'bg-brass-500' : 'bg-sand-100 dark:bg-ink-800' }}"></span>@endfor
                    </div>
                    <p class="mt-3 flex items-start gap-2 text-sm text-ink-700 dark:text-sand-100"><x-icon name="map-pin" class="mt-0.5 size-4 shrink-0 text-brass-500" />
                        <span>{{ collect([__('sales_admin.zone_n', ['n' => $a['zone_number'] ?? '—']), __('sales_admin.street_n', ['n' => $a['street_number'] ?? '—']), __('sales_admin.building_n', ['n' => $a['building_number'] ?? '—']), $a['unit_number'] ?? null])->filter()->implode(' · ') }}
                            @if (! empty($a['landmark']))<span class="block text-xs text-ink-500">{{ $a['landmark'] }}</span>@endif
                            @if (! empty($a['instructions']))<span class="block text-xs text-ink-500">{{ $a['instructions'] }}</span>@endif
                        </span>
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <span class="badge-neutral"><x-icon name="package" class="size-3" />{{ trans_choice('sales_admin.units', $units, ['count' => $units]) }}</span>
                        <span class="badge-neutral"><x-icon name="store" class="size-3" />{{ $d->branch->localized('name') }}</span>
                        @if ($d->scheduled_for)<span class="badge-neutral tabular-nums"><x-icon name="clock" class="size-3" />{{ \App\Support\BusinessTime::display($d->scheduled_for, 'H:i') }}</span>@endif
                        @if ($codDue)<span class="badge-warning"><x-icon name="cash" class="size-3" />{{ __('sales_admin.collect_x', ['amount' => \App\Support\Money::of($o->grand_total)->minus(\App\Support\Money::of($o->paid_total))->format()]) }}</span>@else<span class="badge-success">{{ __('status.paid') }}</span>@endif
                    </div>
                    @if ($o->customer_notes)<p class="mt-3 rounded-xl bg-brass-50 p-2.5 text-xs text-brass-700 dark:bg-brass-500/10 dark:text-brass-300">{{ $o->customer_notes }}</p>@endif
                </div>
                <div class="grid grid-cols-2 border-t border-sand-100 dark:border-ink-800">
                    <a href="{{ $nav($a) }}" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 py-3.5 text-sm font-medium text-sky-soft hover:bg-sand-50 dark:text-sky-300 dark:hover:bg-ink-800"><x-icon name="map" class="size-4" />{{ __('sales_admin.navigate') }}</a>
                    <a href="tel:{{ $o->customer_phone }}" class="flex items-center justify-center gap-2 border-s border-sand-100 py-3.5 text-sm font-medium text-ink-700 hover:bg-sand-50 dark:border-ink-800 dark:text-sand-100 dark:hover:bg-ink-800"><x-icon name="phone" class="size-4" />{{ __('sales_admin.call') }}</a>
                </div>
                <div class="border-t border-sand-100 p-3 dark:border-ink-800">
                    @switch ($st)
                        @case('assigned')
                            <button type="button" wire:click="act('{{ $d->id }}', 'accept')" wire:loading.attr="disabled" class="btn-primary w-full py-3.5 text-base"><x-icon name="check" class="size-5" />{{ __('sales_admin.drv_accept') }}</button>
                            @break
                        @case('pending')
                        @case('accepted')
                            <button type="button" wire:click="act('{{ $d->id }}', 'pickup')" wire:loading.attr="disabled" class="btn-primary w-full py-3.5 text-base"><x-icon name="package" class="size-5" />{{ __('sales_admin.drv_picked_up') }}</button>
                            @break
                        @case('picked_up')
                        @case('rescheduled')
                        @case('failed')
                            <div class="grid grid-cols-[1fr_auto] gap-2">
                                <button type="button" wire:click="act('{{ $d->id }}', 'out')" wire:loading.attr="disabled" class="btn-primary py-3.5 text-base"><x-icon name="truck" class="size-5" />{{ __('sales_admin.drv_out') }}</button>
                                @if ($st === 'picked_up')<button type="button" wire:click="openSheet('{{ $d->id }}', 'deliver')" class="btn-secondary px-4" aria-label="{{ __('sales_admin.drv_delivered_btn') }}"><x-icon name="check" class="size-5" /></button>@endif
                            </div>
                            @break
                        @case('out_for_delivery')
                            <div class="grid grid-cols-[1fr_auto] gap-2">
                                <button type="button" wire:click="openSheet('{{ $d->id }}', 'deliver')" class="btn-brass py-3.5 text-base"><x-icon name="check" class="size-5" />{{ __('sales_admin.drv_delivered_btn') }}</button>
                                <button type="button" wire:click="openSheet('{{ $d->id }}', 'fail')" class="btn-secondary px-4 text-ruby-soft" aria-label="{{ __('sales_admin.mark_failed') }}"><x-icon name="alert" class="size-5" /></button>
                            </div>
                            @break
                    @endswitch
                </div>
            </li>
        @endforeach
    </ol>

    @if ($done->isNotEmpty())
        <h2 class="mb-3 mt-8 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('sales_admin.drv_completed_today') }}</h2>
        <ul class="divide-y divide-sand-100 overflow-hidden rounded-2xl bg-white shadow-[var(--shadow-card)] dark:divide-ink-800 dark:bg-ink-900">
            @foreach ($done as $d)
                <li class="flex items-center gap-3 px-4 py-3 text-sm" wire:key="dd-{{ $d->id }}">
                    <span class="min-w-0 flex-1 truncate">{{ $d->fulfillment->order->customer_name }}</span>
                    <span class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($d->updated_at, 'H:i') }}</span>
                    <x-admin.status :value="$d->status->value" />
                </li>
            @endforeach
        </ul>
    @endif

    {{-- Step sheet: the shared side window (a bottom sheet on the phone). Closing it any way clears the step. --}}
    @php $o = $current?->fulfillment->order; $codDue = $o && $o->payment_method === 'cod' && ! $o->payment_status->isSettled(); @endphp
    <x-admin.dialog wire:model="showSheet" max-width="max-w-lg" :title="$current ? ($sheet === 'deliver' ? __('sales_admin.drv_confirm_delivery') : __('sales_admin.mark_failed')) : null" :description="$current ? $o->number.' · '.$o->customer_name : null">
        <x-admin.drawer-bar close="closeSheet" />
        @if ($current && $sheet)
            <div>
                @if ($sheet === 'deliver')
                    <form wire:submit="deliver" class="space-y-4">
                        <x-admin.field :label="__('sales_admin.recipient')" for="recipient" required><input id="recipient" wire:model="recipient" class="input py-3" autocomplete="off"></x-admin.field>
                        <div>
                            <span class="field-label">{{ __('sales_admin.proof_photo') }}@if ($current->requires_proof)<span class="ms-0.5 text-ruby-soft">*</span>@endif</span>
                            <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-dashed border-sand-300 p-4 dark:border-ink-600" x-data="{ up: false }" x-on:livewire-upload-start="up = true" x-on:livewire-upload-finish="up = false" x-on:livewire-upload-error="up = false">
                                @if ($proof && method_exists($proof, 'isPreviewable') && $proof->isPreviewable())
                                    <img src="{{ $proof->temporaryUrl() }}" alt="" class="size-16 rounded-xl object-cover">
                                @else
                                    <span class="grid size-16 place-items-center rounded-xl bg-sand-100 text-brass-600 dark:bg-ink-800"><x-icon name="camera" class="size-6" /></span>
                                @endif
                                <span class="text-sm text-ink-600 dark:text-sand-200">{{ $proof ? __('sales_admin.retake_photo') : __('sales_admin.take_photo') }}<span x-show="up" x-cloak class="block text-xs text-brass-600">{{ __('admin.loading') }}</span></span>
                                <input type="file" accept="image/*" capture="environment" wire:model="proof" class="sr-only">
                            </label>
                            @error('proof')<p class="field-error">{{ $message }}</p>@enderror
                        </div>
                        @if ($codDue)
                            <div class="rounded-2xl bg-amber-soft/5 p-4">
                                <p class="mb-3 flex items-center gap-2 font-semibold text-amber-soft"><x-icon name="cash" class="size-4" />{{ __('sales_admin.collect_x', ['amount' => \App\Support\Money::of($o->grand_total)->minus(\App\Support\Money::of($o->paid_total))->format()]) }}</p>
                                <div class="grid grid-cols-2 gap-2 [&>*]:min-w-0">
                                    @foreach (['cash' => __('sales_admin.tender_cash'), 'card_terminal' => __('sales_admin.tender_card')] as $t => $label)
                                        <label class="flex cursor-pointer items-center justify-center gap-2 rounded-xl border py-3 text-sm font-medium {{ $cod['tender'] === $t ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}"><input type="radio" wire:model.live="cod.tender" value="{{ $t }}" class="sr-only">{{ $label }}</label>
                                    @endforeach
                                </div>
                                <div class="mt-3 grid grid-cols-2 gap-2 [&>*]:min-w-0">
                                    <x-admin.field :label="__('sales_admin.amount_received')" for="cod.amount"><input id="cod.amount" type="number" inputmode="decimal" step="0.01" min="0" wire:model="cod.amount" class="input py-3 tabular-nums" dir="ltr"></x-admin.field>
                                    <x-admin.field :label="__('sales_admin.receipt_ref')" for="cod.receipt_reference"><input id="cod.receipt_reference" wire:model="cod.receipt_reference" class="input py-3" dir="ltr"></x-admin.field>
                                </div>
                            </div>
                        @endif
                        <div class="grid grid-cols-[auto_1fr] gap-2 pt-2">
                            <button type="button" wire:click="closeSheet" class="btn-secondary py-3.5">{{ __('admin.back') }}</button>
                            <button class="btn-brass py-3.5 text-base" wire:loading.attr="disabled" wire:target="deliver,proof"><x-icon name="check" class="size-5" />{{ __('sales_admin.drv_confirm_delivery') }}</button>
                        </div>
                    </form>
                @else
                    <form wire:submit="fail" class="space-y-4">
                        <div class="grid gap-2" role="radiogroup" aria-label="{{ __('sales_admin.fail_reason') }}">
                            @foreach (\App\Livewire\Admin\Sales\DeliveryIndex::FAIL_REASONS as $rc)
                                <label class="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-sm {{ $failReason === $rc ? 'border-ruby-soft bg-ruby-soft/5 font-medium' : 'border-sand-200 dark:border-ink-700' }}">
                                    <input type="radio" wire:model.live="failReason" value="{{ $rc }}" class="text-ruby-soft focus:ring-ruby-soft/30">{{ __('sales_admin.fail_'.$rc) }}
                                </label>
                            @endforeach
                        </div>
                        <x-admin.field :label="__('admin.notes')" for="failNotes"><textarea id="failNotes" wire:model="failNotes" rows="2" class="input"></textarea></x-admin.field>
                        <div class="grid grid-cols-[auto_1fr] gap-2 pt-2">
                            <button type="button" wire:click="closeSheet" class="btn-secondary py-3.5">{{ __('admin.back') }}</button>
                            <button class="btn-danger py-3.5 text-base" wire:loading.attr="disabled" wire:target="fail">{{ __('sales_admin.mark_failed') }}</button>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    </x-admin.dialog>
</div>
