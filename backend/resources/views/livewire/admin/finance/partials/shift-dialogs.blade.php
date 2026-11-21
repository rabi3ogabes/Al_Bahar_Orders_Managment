{{-- The bodies of the shift windows (open, close, review, done) of the cash page. $dialog says which; $shiftDialog holds what that window needs. --}}
@php
    $d = $shiftDialog;
    $money = fn ($v) => \App\Support\Money::of((string) $v)->format();
@endphp
@if ($d === null)
    <p class="text-sm text-ink-500">{{ __('cash_shift.gone') }}</p>
@elseif ($dialog === 'shift_open')
    <div class="space-y-1 rounded-xl bg-sand-50 p-4 text-sm dark:bg-ink-800">
        <p class="font-medium"><bdi>{{ $d['branch']?->localized('name') }}</bdi> <span class="font-mono text-xs text-ink-500" dir="ltr">{{ $d['branch']?->code }}</span></p>
        @if ($d['previous'])
            <p class="text-ink-500">{{ __('cash_shift.previous_close', ['z' => $d['previous']->zLabel(), 'amount' => $money($d['previous']->closing_total)]) }}</p>
        @endif
        @if ($d['gapCount'] > 0)
            <p class="text-amber-soft dark:text-amber-300" data-gap-note>{{ trans_choice('cash_shift.gap_note', $d['gapCount'], ['count' => $d['gapCount'], 'amount' => $d['gapTotal']->format()]) }}</p>
        @endif
    </div>
    <div>
        <p class="field-label">{{ __('cash_shift.float_count') }}</p>
        <x-admin.cash-count :count="$count" :total="$d['count']['total'] ?? null" />
        <p class="field-hint">{{ __('cash_shift.float_hint') }}</p>
    </div>
@elseif ($dialog === 'shift_close' && isset($d['figures']))
    @php $f = $d['figures']; $c = $d['count']; $v = $d['variance']; @endphp
    <div class="rounded-xl bg-sand-50 p-4 text-sm dark:bg-ink-800" data-expected-breakdown>
        <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('cash_shift.expected_title') }}</p>
        <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 [&_dd]:text-end [&_dd]:tabular-nums">
            <dt>{{ __('cash_shift.l_float') }}</dt><dd dir="ltr">{{ $f['float']->format() }}</dd>
            <dt>+ {{ trans_choice('cash_shift.l_received_n', $f['handins'], ['count' => $f['handins']]) }}</dt><dd dir="ltr">{{ $f['received']->format() }}</dd>
            @if ($f['handins'] > 0)
                <dt class="ps-4 text-xs text-ink-500">{{ __('cash_shift.src_cod') }}</dt><dd class="text-xs text-ink-500" dir="ltr">{{ $f['received_cod']->format() }}</dd>
                <dt class="ps-4 text-xs text-ink-500">{{ __('cash_shift.src_counter') }}</dt><dd class="text-xs text-ink-500" dir="ltr">{{ $f['received_counter']->format() }}</dd>
                @if (! $f['received_difference']->isZero())
                    <dt class="ps-4 text-xs text-ink-500">{{ __('cash_shift.src_difference') }}</dt><dd class="text-xs text-ink-500" dir="ltr">{{ $f['received_difference']->format() }}</dd>
                @endif
            @endif
            <dt>− {{ trans_choice('cash_shift.l_banked_n', $f['deposits_count'], ['count' => $f['deposits_count']]) }}</dt><dd dir="ltr">{{ $f['deposits']->format() }}</dd>
            <dt>− {{ trans_choice('cash_shift.l_refunds_n', $f['refunds_count'], ['count' => $f['refunds_count']]) }}</dt><dd dir="ltr">{{ $f['refunds']->format() }}</dd>
            <dt class="border-t border-sand-200 pt-2 font-semibold dark:border-ink-700">{{ __('cash_shift.expected_cash') }}</dt>
            <dd class="border-t border-sand-200 pt-2 font-display text-lg font-semibold dark:border-ink-700" dir="ltr" data-expected>{{ $f['expected']->format() }}</dd>
        </dl>
        @if ($f['own_count'] > 0)
            <p class="mt-3 text-xs text-amber-soft dark:text-amber-300">{{ trans_choice('cash_shift.own_cash_note', $f['own_count'], ['count' => $f['own_count'], 'amount' => $f['own_amount']->format()]) }}</p>
        @endif
    </div>
    <div>
        <p class="field-label">{{ __('cash_shift.end_count') }}</p>
        <x-admin.cash-count :count="$count" :total="$c['total'] ?? null" />
    </div>
    @if ($v !== null)
        <div class="rounded-xl border p-4 text-sm {{ $v->isZero() ? 'border-emerald-soft/30' : ($d['needsReview'] ? 'border-ruby-soft/40' : 'border-amber-soft/40') }}" aria-live="polite" data-variance-box>
            <div class="flex items-center justify-between"><span class="text-ink-500">{{ __('finance_admin.variance') }}</span>
                @if ($v->isZero())<span class="font-medium text-emerald-soft">{{ __('finance_admin.balanced') }}</span>
                @else<span class="font-display text-lg font-semibold tabular-nums {{ $v->isNegative() ? 'text-ruby-soft' : 'text-amber-soft' }}" dir="ltr" data-variance>{{ $v->format() }}</span>@endif
            </div>
            @if ($d['needsReview'])
                <p class="mt-2 text-xs text-ruby-soft">{{ __('cash_shift.needs_review_hint', ['amount' => $shiftRules['review']->format()]) }}</p>
            @elseif ($d['needsNote'])
                <p class="mt-2 text-xs text-amber-soft">{{ __('cash_shift.needs_note_hint', ['amount' => $shiftRules['note']->format()]) }}</p>
            @endif
        </div>
    @endif
    <x-admin.field :label="__('cash_shift.reason')" for="form.note" :required="$d['needsNote']" :hint="__('cash_shift.reason_hint', ['amount' => $shiftRules['note']->format()])"><textarea id="form.note" wire:model="form.note" rows="2" class="input"></textarea></x-admin.field>
@elseif ($dialog === 'shift_review' && isset($d['shift']))
    @php $s = $d['shift']; @endphp
    <div class="rounded-xl bg-sand-50 p-4 text-sm dark:bg-ink-800">
        <p class="font-mono" dir="ltr">{{ $s->zLabel() }} · {{ $s->branch?->code }}</p>
        <p class="mt-1 text-ink-500"><bdi>{{ $s->opener?->name }}</bdi> · {{ __('cash_shift.closed_by', ['name' => $s->closer?->name]) }}</p>
        <p class="mt-2 tabular-nums"><span dir="ltr">{{ $money($s->expected_total) }} → {{ $money($s->closing_total) }} ({{ $money($s->variance) }})</span></p>
        @if ($s->variance_note)<p class="mt-2 text-xs text-ink-500">“{{ $s->variance_note }}”</p>@endif
    </div>
    <x-admin.field :label="__('finance_admin.resolution')" for="form.resolution" required :hint="__('cash_shift.review_hint')"><textarea id="form.resolution" wire:model="form.resolution" rows="3" class="input"></textarea></x-admin.field>
@elseif ($dialog === 'shift_done' && isset($d['shift']))
    @php $s = $d['shift']; $v = \App\Support\Money::of((string) $s->variance); @endphp
    <div class="flex flex-col items-center gap-3 py-4 text-center" data-shift-done>
        <span class="grid size-14 place-items-center rounded-2xl bg-emerald-soft/10 text-emerald-soft"><x-icon name="check" class="size-6" /></span>
        <p class="font-display text-2xl font-semibold" dir="ltr">{{ $s->zLabel() }}</p>
        <p class="text-sm text-ink-500">{{ __('cash_shift.done_hint') }}</p>
        <p class="tabular-nums text-sm" dir="ltr">{{ $money($s->expected_total) }} → {{ $money($s->closing_total) }}</p>
        @if ($v->isZero())
            <p class="text-sm text-emerald-soft">{{ __('finance_admin.balanced') }}</p>
        @else
            <p class="font-medium tabular-nums {{ $v->isNegative() ? 'text-ruby-soft' : 'text-amber-soft' }}" dir="ltr">{{ $v->format() }}</p>
            @if ($s->hasOpenVariance())<p class="text-xs text-amber-soft">{{ __('cash_shift.done_review') }}</p>@endif
        @endif
    </div>
@endif
