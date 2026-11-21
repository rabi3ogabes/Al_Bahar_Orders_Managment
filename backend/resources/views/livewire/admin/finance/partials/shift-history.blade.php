{{-- The Shifts tab: every shift of the branches the viewer may see, newest first; open ones and ones waiting for a second person are always listed. --}}
<div class="card">
    <x-admin.toolbar>
        <select wire:model.live="filterShift" class="input sm:w-48" aria-label="{{ __('admin.status') }}">
            <option value="">{{ __('admin.all_statuses') }}</option>
            <option value="open">{{ __('cash_shift.state_open') }}</option>
            <option value="closed">{{ __('cash_shift.state_closed') }}</option>
            <option value="review">{{ __('cash_shift.needs_review') }}</option>
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
    @if ($shiftHistory->isEmpty())
        <x-admin.empty :title="__('cash_shift.history_empty')" :description="__('cash_shift.history_empty_hint')" icon="scale" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr>
                <th>{{ __('cash_shift.z_report') }}</th><th>{{ __('cash_shift.cashier') }}</th><th>{{ __('cash_shift.opened_closed') }}</th>
                <th class="!text-end">{{ __('finance_admin.counted') }}</th>
                <th>{{ __('finance_admin.variance') }}</th><th></th>
            </tr></thead>
            <tbody>@foreach ($shiftHistory as $s)
                @php
                    $variance = $s->variance !== null ? \App\Support\Money::of((string) $s->variance) : null;
                    $involved = in_array(auth()->id(), [$s->opened_by, $s->closed_by], true);
                @endphp
                <tr wire:key="shift-row-{{ $s->id }}" data-shift="{{ $s->id }}">
                    <td>
                        @if ($s->isOpen())
                            <span class="badge-success"><span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ __('cash_shift.state_open') }}</span>
                        @else
                            <span class="font-mono text-sm font-medium" dir="ltr">{{ $s->zLabel() }}</span>
                        @endif
                        <span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $s->branch?->code }}</span>
                    </td>
                    <td class="text-sm"><bdi>{{ $s->opener?->name }}</bdi>@if ($s->closed_by && $s->closed_by !== $s->opened_by)<span class="block text-xs text-ink-500">{{ __('cash_shift.closed_by', ['name' => $s->closer?->name]) }}</span>@endif</td>
                    <td class="whitespace-nowrap text-xs tabular-nums text-ink-500">{{ \App\Support\BusinessTime::display($s->opened_at, 'd M, H:i') }}<span class="block">{{ $s->closed_at ? '→ '.\App\Support\BusinessTime::display($s->closed_at, 'd M, H:i') : __('cash_shift.still_open') }}</span></td>
                    <td class="text-end">
                        @if ($s->closing_total !== null)
                            <x-admin.money :value="$s->closing_total" class="font-medium" />
                            <span class="block whitespace-nowrap text-xs text-ink-500">{{ __('cash_shift.of_expected', ['amount' => \App\Support\Money::of((string) $s->expected_total)->format()]) }}</span>
                        @else
                            <span class="text-ink-400">—</span>
                        @endif
                    </td>
                    <td>
                        @if ($variance === null)
                            <span class="text-ink-400">—</span>
                        @elseif ($variance->isZero())
                            <span class="text-xs text-emerald-soft">{{ __('finance_admin.balanced') }}</span>
                        @else
                            <span class="text-sm font-medium tabular-nums {{ $variance->isNegative() ? 'text-ruby-soft' : 'text-amber-soft' }}" dir="ltr">{{ $variance->format() }}</span>
                            @if ($s->variance_status)<span class="block text-xs {{ $s->variance_status === 'open' ? 'text-amber-soft' : 'text-emerald-soft' }}">{{ __('finance_admin.variance_'.$s->variance_status) }}</span>@endif
                            @if ($s->variance_note)<span class="block max-w-52 truncate text-xs text-ink-500" title="{{ $s->variance_note }}">{{ $s->variance_note }}</span>@endif
                            @if ($s->variance_resolution)<span class="block max-w-52 truncate text-xs text-ink-500" title="{{ $s->variance_resolution }}">✓ {{ $s->variance_resolution }}</span>@endif
                        @endif
                    </td>
                    <td class="text-end">
                        <div class="flex flex-wrap items-center justify-end gap-2 whitespace-nowrap">
                            @if ($s->isOpen() && $this->can('payments.edit', $s->branch_id))
                                <button type="button" wire:click="closeShiftDialog('{{ $s->id }}')" class="btn-primary btn-sm">{{ __('cash_shift.close_shift') }}</button>
                            @endif
                            @if ($s->hasOpenVariance() && ! $involved && $this->can('payments.override', $s->branch_id))
                                <button type="button" wire:click="reviewShiftDialog('{{ $s->id }}')" class="btn-secondary btn-sm">{{ __('finance_admin.review') }}</button>
                            @endif
                            @if (! $s->isOpen())
                                <a href="{{ route('admin.cash.shifts.report', $s) }}" target="_blank" rel="noopener" class="btn-secondary btn-sm" aria-label="{{ __('cash_shift.open_z_report', ['z' => $s->zLabel()]) }}"><x-icon name="printer" class="size-4" />{{ __('cash_shift.z_report') }}</a>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach</tbody>
        </table></div>
        @if ($shiftHistory->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $shiftHistory->links('components.admin.pagination') }}</div>@endif
    @endif
</div>
