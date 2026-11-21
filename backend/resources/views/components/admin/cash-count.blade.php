@props(['count', 'total' => null, 'prefix' => 'count'])
{{--
    The cash count of a shift: how many notes of each value (QAR 500 down to 1) and the coins as one amount. The server adds it up
    ($total is the Money it worked out, null while a value is half typed); nobody types a total. Every row is a 44 px touch target.
    $count is the Livewire property holding ['notes' => [value => n], 'coins' => '12.50'], $prefix its name.
--}}
@php
    $row = fn (int $value) => \App\Support\Money::ofMinor($value * 100 * max(0, (int) ($count['notes'][$value] ?? 0)));
@endphp
<div {{ $attributes->merge(['class' => 'rounded-2xl border border-sand-200/70 dark:border-ink-700']) }} data-cash-count>
    <ul class="divide-y divide-sand-100 dark:divide-ink-800">
        @foreach (\App\Domain\Payments\Services\CashShiftService::NOTES as $value)
            <li class="flex min-h-14 items-center gap-3 px-4 py-1.5" wire:key="note-{{ $value }}">
                <label for="{{ $prefix }}-note-{{ $value }}" class="w-24 shrink-0 text-sm font-medium tabular-nums" dir="ltr">{{ __('cash_shift.note_label', ['value' => $value]) }}</label>
                <span class="text-ink-400" aria-hidden="true">×</span>
                <input id="{{ $prefix }}-note-{{ $value }}" type="number" inputmode="numeric" min="0" step="1" placeholder="0" dir="ltr"
                       wire:model.live.debounce.300ms="{{ $prefix }}.notes.{{ $value }}" class="input min-h-11 w-24 text-center tabular-nums">
                <span class="ms-auto text-sm tabular-nums text-ink-500" dir="ltr">{{ $row($value)->format() }}</span>
            </li>
        @endforeach
        <li class="flex min-h-14 items-center gap-3 px-4 py-1.5">
            <label for="{{ $prefix }}-coins" class="w-24 shrink-0 text-sm font-medium">{{ __('cash_shift.coins') }}</label>
            <span class="text-ink-400" aria-hidden="true">=</span>
            <input id="{{ $prefix }}-coins" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0.00" dir="ltr"
                   wire:model.live.debounce.300ms="{{ $prefix }}.coins" class="input min-h-11 w-28 text-center tabular-nums">
            <span class="ms-auto text-xs text-ink-500">{{ __('cash_shift.coins_hint') }}</span>
        </li>
    </ul>
    <div class="flex items-center justify-between gap-3 rounded-b-2xl bg-sand-50 px-4 py-3 dark:bg-ink-800" aria-live="polite">
        <span class="text-sm font-medium">{{ __('cash_shift.count_total') }}</span>
        <span class="font-display text-xl font-semibold tabular-nums" dir="ltr" data-count-total>{{ $total?->format() ?? '—' }}</span>
    </div>
</div>
@error($prefix)<p class="field-error" role="alert">{{ $message }}</p>@enderror
@if ($errors->first($prefix.'.notes.*') !== '')<p class="field-error" role="alert">{{ $errors->first($prefix.'.notes.*') }}</p>@endif
@error($prefix.'.coins')<p class="field-error" role="alert">{{ $message }}</p>@enderror
