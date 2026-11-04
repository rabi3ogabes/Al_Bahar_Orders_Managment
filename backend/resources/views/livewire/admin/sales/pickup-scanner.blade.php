@php
    $messages = [
        'invalid_token' => ['ban', __('sales_admin.scan_invalid_token')],
        'expired' => ['clock', __('sales_admin.scan_expired')],
        'already_used' => ['check', __('sales_admin.scan_already_used')],
        'wrong_branch' => ['store', __('sales_admin.scan_wrong_branch')],
        'not_ready' => ['package', __('sales_admin.scan_not_ready')],
    ];
@endphp
<div class="mx-auto max-w-5xl">
    <a href="{{ route('admin.pickup.index') }}" wire:navigate class="mb-4 inline-flex items-center gap-1.5 text-sm text-ink-500 hover:text-brass-600"><x-icon name="chevron-left" class="size-4 rtl-flip" />{{ __('nav.pickup') }}</a>
    <x-admin.page-header :title="__('sales_admin.scanner_title')" :description="__('sales_admin.scanner_hint')">
        <x-slot:actions>
            @if ($branches->count() > 1)
                <select wire:model.live="branchId" class="input sm:w-60" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('sales_admin.choose_counter_branch') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->code }} · {{ $b->localized('name') }}</option>@endforeach
                </select>
            @elseif ($branches->count() === 1)
                <span class="badge-brass"><x-icon name="store" class="size-3.5" />{{ $branches->first()->localized('name') }}</span>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    @if (! $r)
        <div class="grid gap-6 lg:grid-cols-5 [&>*]:min-w-0">
            {{-- Camera --}}
            <div class="card overflow-hidden lg:col-span-3"
                 x-data="{
                    scanner: null, running: false, error: null, busy: false,
                    async start() {
                        this.error = null;
                        try {
                            const Html5Qrcode = await window.loadQrScanner();
                            this.scanner ??= new Html5Qrcode('qr-reader', { verbose: false });
                            await this.scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: (w, h) => { const s = Math.floor(Math.min(w, h) * 0.7); return { width: s, height: s }; } },
                                (text) => this.found(text), () => {});
                            this.running = true;
                        } catch (e) { this.running = false; this.error = @js(__('sales_admin.camera_unavailable')); }
                    },
                    async stop() { try { if (this.scanner && this.running) await this.scanner.stop(); } catch (e) {} this.running = false; },
                    async found(text) {
                        if (this.busy) return;
                        this.busy = true;
                        await this.stop();
                        if (navigator.vibrate) navigator.vibrate(60);
                        $wire.code = text;
                        await $wire.verify();
                        this.busy = false;
                    },
                 }"
                 x-init="document.addEventListener('livewire:navigating', () => stop(), { once: true })"
>
                <div class="relative aspect-[4/3] bg-ink-950">
                    <div id="qr-reader" wire:ignore class="absolute inset-0 [&_video]:!h-full [&_video]:!w-full [&_video]:object-cover"></div>
                    <div x-show="!running" class="absolute inset-0 grid place-items-center p-6 text-center">
                        <div>
                            <span class="mx-auto mb-4 grid size-16 place-items-center rounded-2xl bg-white/10 text-brass-300"><x-icon name="qr" class="size-7" /></span>
                            <p class="text-sm text-ink-300">{{ __('sales_admin.camera_hint') }}</p>
                            <p x-show="error" x-text="error" x-cloak class="mt-2 text-xs text-red-300"></p>
                            <button type="button" @click="start()" @disabled(! $branchId) class="btn-brass mt-5"><x-icon name="camera" class="size-4" />{{ __('sales_admin.start_camera') }}</button>
                            @unless ($branchId)<p class="mt-3 text-xs text-ink-400">{{ __('sales_admin.choose_branch_first') }}</p>@endunless
                        </div>
                    </div>
                    <button type="button" x-show="running" x-cloak @click="stop()" class="btn-secondary btn-sm absolute bottom-3 end-3"><x-icon name="stop" class="size-3.5" />{{ __('sales_admin.stop_camera') }}</button>
                </div>
            </div>

            {{-- Manual + result --}}
            <div class="space-y-6 lg:col-span-2">
                <form wire:submit="verify" class="card card-pad">
                    <h2 class="font-display text-xl font-semibold">{{ __('sales_admin.manual_code') }}</h2>
                    <p class="mt-1 text-sm text-ink-500">{{ __('sales_admin.manual_code_hint') }}</p>
                    <div class="mt-4 flex gap-2">
                        <input wire:model="code" class="input flex-1 font-mono" dir="ltr" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="ABPK1.…" aria-label="{{ __('sales_admin.manual_code') }}">
                        <button class="btn-primary" @disabled(! $branchId) wire:loading.attr="disabled" wire:target="verify"><x-icon name="search" class="size-4" /><span class="sr-only sm:not-sr-only">{{ __('admin.verify') }}</span></button>
                    </div>
                    @error('code')<p class="field-error">{{ $message }}</p>@enderror
                    @error('branchId')<p class="field-error">{{ $message }}</p>@enderror
                </form>

                <div wire:loading.flex wire:target="verify" class="card card-pad items-center gap-3 text-sm text-ink-500"><x-icon name="refresh" class="size-4 animate-spin text-brass-500" />{{ __('sales_admin.verifying') }}</div>
                @if ($result && $result !== 'success')
                    <div wire:loading.remove wire:target="verify" class="card border-ruby-soft/30 bg-ruby-soft/5 p-5" role="alert">
                        <div class="flex items-start gap-3">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-ruby-soft/15 text-ruby-soft"><x-icon :name="$messages[$result][0] ?? 'alert'" class="size-5" /></span>
                            <div class="min-w-0">
                                <p class="font-semibold text-ruby-soft">{{ __('sales_admin.scan_rejected') }}</p>
                                <p class="mt-1 text-sm text-ink-700 dark:text-sand-100">{{ $messages[$result][1] ?? $result }}</p>
                            </div>
                        </div>
                    </div>
                @endif
                <p class="text-xs leading-relaxed text-ink-500"><x-icon name="shield" class="me-1 inline size-3.5" />{{ __('sales_admin.scan_security_note') }}</p>
            </div>
        </div>
    @else
        {{-- Handover --}}
        <div class="grid gap-6 lg:grid-cols-3 [&>*]:min-w-0">
            <form wire:submit="confirmHandover" class="card overflow-hidden lg:col-span-2">
                <div class="flex items-start gap-3 border-b border-sand-100 bg-emerald-soft/5 p-5 sm:p-6 dark:border-ink-800">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-soft/15 text-emerald-soft"><x-icon name="check" class="size-5" /></span>
                    <div class="min-w-0">
                        <p class="font-semibold text-emerald-soft">{{ $completed ? __('sales_admin.handover_recorded') : __('sales_admin.pass_verified') }}</p>
                        <p class="mt-0.5 text-sm text-ink-600 dark:text-sand-200"><span class="font-mono" dir="ltr">{{ $order->number }}</span> · {{ $order->customer_name }}</p>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>{{ __('sales_admin.item') }}</th><th class="!text-end">{{ __('sales_admin.outstanding') }}</th><th class="w-32">{{ __('sales_admin.hand_over_now') }}</th></tr></thead>
                        <tbody>
                        @foreach ($r->fulfillment->items as $it)
                            @php $out = max(0, $it->activeQuantity() - $it->quantity_handed_over); @endphp
                            <tr wire:key="ho-{{ $it->id }}">
                                <td class="max-w-[16rem]"><span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $it->name() }}</span><span class="text-xs text-ink-500"><span class="font-mono" dir="ltr">{{ $it->sku }}</span>@if ($it->variantName()) · {{ $it->variantName() }}@endif</span></td>
                                <td class="text-end tabular-nums">{{ $out }}@if ($it->quantity_handed_over)<span class="block text-xs text-emerald-soft">{{ __('sales_admin.n_already', ['n' => $it->quantity_handed_over]) }}</span>@endif</td>
                                <td>
                                    @if ($out > 0 && ! $completed)
                                        <div class="flex items-center gap-1" x-data>
                                            <button type="button" class="btn-ghost btn-sm px-2" @click="$wire.set('handover.{{ $it->id }}', Math.max(0, ($wire.handover['{{ $it->id }}'] ?? 0) - 1))" aria-label="−"><x-icon name="minus" class="size-3.5" /></button>
                                            <input type="number" min="0" max="{{ $out }}" wire:model.live="handover.{{ $it->id }}" class="input w-16 px-2 py-1.5 text-center tabular-nums" aria-label="{{ __('sales_admin.hand_over_now') }}">
                                            <button type="button" class="btn-ghost btn-sm px-2" @click="$wire.set('handover.{{ $it->id }}', Math.min({{ $out }}, ($wire.handover['{{ $it->id }}'] ?? 0) + 1))" aria-label="+"><x-icon name="plus" class="size-3.5" /></button>
                                        </div>
                                    @else
                                        <span class="text-xs text-ink-400">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @error('handover')<p class="field-error px-6">{{ $message }}</p>@enderror

                @unless ($completed)
                    <div class="grid gap-4 border-t border-sand-100 p-5 sm:grid-cols-2 sm:p-6 dark:border-ink-800 [&>*]:min-w-0">
                        <x-admin.field :label="__('sales_admin.collector_name')" for="collectorName" required><input id="collectorName" wire:model="collectorName" class="input" autocomplete="off"></x-admin.field>
                        @if ($alternateAllowed)
                            <div class="sm:pt-7"><x-admin.toggle :label="__('sales_admin.alternate_collector')" :hint="__('sales_admin.alternate_collector_hint')" wire:model="alternate" /></div>
                        @endif
                    </div>
                    @if ($needsPayment)
                        <div class="border-t border-sand-100 bg-amber-soft/5 p-5 sm:p-6 dark:border-ink-800">
                            <h3 class="flex items-center gap-2 font-semibold text-amber-soft"><x-icon name="cash" class="size-4" />{{ __('sales_admin.collect_payment', ['amount' => $due->format()]) }}</h3>
                            <div class="mt-4 grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                                <x-admin.field :label="__('sales_admin.tender')" for="collection.tender">
                                    <select id="collection.tender" wire:model="collection.tender" class="input"><option value="cash">{{ __('sales_admin.tender_cash') }}</option><option value="card_terminal">{{ __('sales_admin.tender_card') }}</option></select>
                                </x-admin.field>
                                <x-admin.field :label="__('sales_admin.amount_received')" for="collection.amount"><input id="collection.amount" type="number" step="0.01" min="0" wire:model="collection.amount" class="input tabular-nums" dir="ltr"></x-admin.field>
                                <x-admin.field :label="__('sales_admin.receipt_ref')" for="collection.receipt_reference"><input id="collection.receipt_reference" wire:model="collection.receipt_reference" class="input" dir="ltr" placeholder="POS-…"></x-admin.field>
                            </div>
                        </div>
                    @elseif ($awaitingOnline)
                        <p class="border-t border-sand-100 bg-ruby-soft/5 p-5 text-sm text-ruby-soft sm:px-6 dark:border-ink-800"><x-icon name="alert" class="me-1 inline size-4" />{{ __('sales_admin.online_payment_pending') }}</p>
                    @endif
                @endunless

                <div class="flex flex-col-reverse gap-2 border-t border-sand-100 p-4 sm:flex-row sm:justify-end sm:px-6 dark:border-ink-800">
                    <button type="button" wire:click="resetScan" class="btn-secondary"><x-icon name="qr" class="size-4" />{{ __('sales_admin.scan_next') }}</button>
                    @unless ($completed)
                        <button class="btn-primary" wire:loading.attr="disabled" wire:target="confirmHandover" @disabled($awaitingOnline)><x-icon name="check" class="size-4" />{{ __('sales_admin.confirm_handover') }}</button>
                    @endunless
                </div>
            </form>

            <aside class="card card-pad h-fit space-y-4 text-sm">
                <h2 class="font-display text-xl font-semibold">{{ __('admin.order') }}</h2>
                <dl class="space-y-2.5">
                    <div class="flex justify-between gap-3"><dt class="text-ink-500">{{ __('admin.status') }}</dt><dd><x-admin.status :value="$r->status" /></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-500">{{ __('admin.payment') }}</dt><dd class="text-end">{{ __('status.'.$order->payment_method) }}<br><x-admin.status :value="$order->payment_status->value" /></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-500">{{ __('admin.total') }}</dt><dd><x-admin.money :value="$order->grand_total" class="font-semibold" /></dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-ink-500">{{ __('sales_admin.pass_valid_until') }}</dt><dd class="tabular-nums">{{ \App\Support\BusinessTime::display($r->token_expires_at) }}</dd></div>
                </dl>
                <a href="{{ route('admin.orders.show', $order) }}" wire:navigate class="btn-ghost btn-sm w-full">{{ __('sales_admin.open_order') }}</a>
            </aside>
        </div>
    @endif
</div>
