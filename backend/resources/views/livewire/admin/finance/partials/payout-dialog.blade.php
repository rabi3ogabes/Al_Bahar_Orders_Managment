{{-- The "Pay out cash" window of an open shift: the form, then what was already paid out in this shift (with the reversal). $payoutDialog says what it needs. --}}
@php
    $d = $payoutDialog;
    $fmt = fn ($v) => \App\Support\Money::of((string) $v)->format();
@endphp
@if ($d === null)
    <p class="text-sm text-ink-500" data-payout-gone>{{ __('petty_cash.gone') }}</p>
@else
    <div class="rounded-xl bg-sand-50 p-4 text-sm dark:bg-ink-800" data-payout-drawer>
        <p class="font-medium"><bdi>{{ $d['shift']->branch?->localized('name') }}</bdi> <span class="font-mono text-xs text-ink-500" dir="ltr">{{ $d['shift']->branch?->code }}</span></p>
        <div class="mt-2 flex items-center justify-between"><span class="text-ink-500">{{ __('petty_cash.drawer_now') }}</span><span class="font-display text-lg font-semibold tabular-nums" dir="ltr" data-drawer-now>{{ $d['drawer']->format() }}</span></div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
        <x-admin.field :label="__('petty_cash.amount')" for="payout.amount" required>
            <input id="payout.amount" type="text" inputmode="decimal" autocomplete="off" wire:model.live.debounce.300ms="payout.amount" class="input tabular-nums" dir="ltr" autofocus data-payout-amount>
        </x-admin.field>
        <x-admin.field :label="__('petty_cash.reason')" for="payout.reason" required>
            <select id="payout.reason" wire:model.live="payout.reason" class="input" data-payout-reason>
                <option value="">{{ __('petty_cash.reason_pick') }}</option>
                @foreach (\App\Domain\Payments\Services\CashPayoutService::REASONS as $r)<option value="{{ $r }}">{{ __('petty_cash.reasons.'.$r) }}</option>@endforeach
            </select>
        </x-admin.field>
    </div>
    @if ($d['tooBig'])
        <p class="field-error" role="alert" data-payout-too-big>{{ __('petty_cash.too_big', ['drawer' => $d['drawer']->format()]) }}</p>
    @endif
    <x-admin.field :label="__('petty_cash.note')" for="payout.note" :required="($payout['reason'] ?? '') === 'other'" :hint="__('petty_cash.note_hint')">
        <textarea id="payout.note" wire:model="payout.note" rows="2" maxlength="300" class="input"></textarea>
    </x-admin.field>
    @if ($d['needsApprover'])
        <div class="space-y-2 rounded-xl border border-amber-soft/40 p-4" data-payout-approval>
            <p class="text-sm text-amber-soft dark:text-amber-300">{{ __('petty_cash.needs_approver', ['amount' => $d['limit']->format()]) }}</p>
            @if ($d['approvers']->isEmpty())
                <p class="text-xs text-ink-500">{{ __('petty_cash.approver_none') }}</p>
            @else
                <x-admin.field :label="__('petty_cash.approver')" for="payout.approver" required :hint="__('petty_cash.approver_hint')">
                    <select id="payout.approver" wire:model="payout.approver" class="input" data-payout-approver>
                        <option value="">{{ __('petty_cash.approver_pick') }}</option>
                        @foreach ($d['approvers'] as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach
                    </select>
                </x-admin.field>
            @endif
        </div>
    @endif
    <x-admin.field :label="__('petty_cash.receipt')" for="receipt" :hint="__('petty_cash.receipt_hint')">
        <input id="receipt" type="file" wire:model="receipt" accept="image/jpeg,image/png,image/webp" class="input min-h-11 py-2 text-sm">
        <span class="mt-1 block text-xs text-ink-500" wire:loading wire:target="receipt">…</span>
    </x-admin.field>

    <section aria-labelledby="payout-list-title" class="border-t border-sand-100 pt-4 dark:border-ink-800" data-payout-list>
        <h3 id="payout-list-title" class="mb-2 text-sm font-semibold">{{ __('petty_cash.list_title') }}</h3>
        @if ($d['list']->isEmpty())
            <p class="rounded-xl border border-dashed border-sand-300 p-3 text-center text-sm text-ink-500 dark:border-ink-700">{{ __('petty_cash.list_empty') }}</p>
        @else
            <ul class="divide-y divide-sand-100 rounded-2xl border border-sand-200/70 dark:divide-ink-800 dark:border-ink-700">
                @foreach ($d['list'] as $p)
                    @php $canVoid = ! $p->isVoided() && $this->can('payments.edit', $p->branch_id) && ($p->approved_by === null || $d['canVoidApproved']); @endphp
                    <li class="space-y-1 px-4 py-3" wire:key="payout-{{ $p->id }}" data-payout="{{ $p->id }}" @if ($p->isVoided()) data-voided @endif>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium {{ $p->isVoided() ? 'text-ink-400 line-through' : '' }}">{{ __('petty_cash.reasons.'.$p->reason_code) }}@if ($p->note)<span class="font-normal text-ink-500"> · {{ $p->note }}</span>@endif</p>
                                <p class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($p->created_at, 'H:i') }} · {{ __('petty_cash.paid_by', ['name' => $p->payer?->name]) }}@if ($p->approver) · {{ __('petty_cash.approved_by', ['name' => $p->approver->name]) }}@endif</p>
                            </div>
                            <x-admin.money :value="$p->amount" class="text-sm font-semibold {{ $p->isVoided() ? 'text-ink-400 line-through' : '' }}" />
                        </div>
                        @if ($p->isVoided())
                            <p class="text-xs text-ruby-soft"><span class="badge-neutral">{{ __('petty_cash.voided') }}</span> {{ __('petty_cash.voided_by', ['name' => $p->voider?->name, 'reason' => $p->void_reason]) }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($p->receipt_path)
                                <a href="{{ route('admin.cash.payouts.receipt', $p) }}" target="_blank" rel="noopener" class="btn-ghost btn-sm min-h-11" aria-label="{{ __('petty_cash.receipt_aria') }}"><x-icon name="image" class="size-4" />{{ __('petty_cash.receipt_open') }}</a>
                            @endif
                            @if ($canVoid && $voidId !== $p->id)
                                <button type="button" wire:click="startVoid('{{ $p->id }}')" class="btn-ghost btn-sm min-h-11 text-ruby-soft" aria-label="{{ __('petty_cash.void_aria') }}" data-action="void-payout">{{ __('petty_cash.void') }}</button>
                            @endif
                        </div>
                        @if ($voidId === $p->id)
                            <div class="space-y-2 rounded-xl bg-sand-50 p-3 dark:bg-ink-800" data-void-form>
                                <x-admin.field :label="__('petty_cash.void_reason')" for="voidReason" required>
                                    <input id="voidReason" type="text" wire:model="voidReason" wire:keydown.enter.prevent="voidPayout" maxlength="300" class="input" autofocus>
                                </x-admin.field>
                                <div class="flex flex-wrap gap-2">
                                    <button type="button" wire:click="voidPayout" wire:loading.attr="disabled" wire:target="voidPayout" class="btn-primary btn-sm min-h-11" data-action="confirm-void">{{ __('petty_cash.void_confirm') }}</button>
                                    <button type="button" wire:click="cancelVoid" class="btn-secondary btn-sm min-h-11">{{ __('petty_cash.void_keep') }}</button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
