{{-- The Shift panel: one card per branch the viewer may see: is its drawer open, what should be in it now, and the one button that fits (Close / Open). --}}
@if ($shiftBranches->isNotEmpty())
    <section class="mb-6" aria-labelledby="shift-panel-title" data-shift-panel>
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="shift-panel-title" class="font-display text-lg font-semibold text-ink-900 dark:text-ivory">{{ __('cash_shift.panel_title') }}</h2>
            <p class="text-sm text-ink-500">{{ __('cash_shift.panel_hint') }}</p>
        </div>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
            @foreach ($shiftBranches as $b)
                @php
                    $shift = $openShifts->get($b->id);
                    $last = $lastShifts->get($b->id);
                    $f = $shift ? ($shiftFigures[$shift->id] ?? null) : null;
                    $canEdit = $this->can('payments.edit', $b->id);
                    $minutes = $shift ? max(0, (int) $shift->opened_at->diffInMinutes(now())) : 0;
                    $tooLong = $shift && $minutes >= $shiftRules['maxHours'] * 60;
                @endphp
                <article class="card card-pad flex flex-col gap-4" wire:key="shift-{{ $b->id }}" data-shift-branch="{{ $b->code }}" data-shift-state="{{ $shift ? 'open' : 'shut' }}">
                    <header class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-ink-900 dark:text-ivory"><bdi>{{ $b->localized('name') }}</bdi></p>
                            <p class="font-mono text-xs text-ink-500" dir="ltr">{{ $b->code }}</p>
                        </div>
                        @if ($shift)
                            <span class="{{ $tooLong ? 'badge-warning' : 'badge-success' }}"><span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ $tooLong ? __('cash_shift.open_too_long') : __('cash_shift.state_open') }}</span>
                        @else
                            <span class="badge-neutral"><span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ __('cash_shift.state_shut') }}</span>
                        @endif
                    </header>

                    @if ($shift && $f)
                        <div>
                            <p class="text-sm text-ink-500">{{ __('cash_shift.expected_now') }}</p>
                            <p class="font-display text-3xl font-semibold tabular-nums text-ink-900 dark:text-ivory" dir="ltr" data-expected-now>{{ $f['expected']->format() }}</p>
                            <p class="mt-1 text-xs text-ink-500">{{ __('cash_shift.open_since', ['time' => \App\Support\BusinessTime::display($shift->opened_at, 'H:i'), 'age' => \App\Domain\Operations\Services\OpsClock::label($minutes), 'name' => (string) $shift->opener?->name]) }}</p>
                        </div>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs [&_dd]:text-end [&_dd]:tabular-nums [&_dt]:text-ink-500">
                            <dt>{{ __('cash_shift.l_float') }}</dt><dd dir="ltr">{{ $f['float']->format() }}</dd>
                            <dt>{{ trans_choice('cash_shift.l_received_n', $f['handins'], ['count' => $f['handins']]) }}</dt><dd dir="ltr">{{ $f['received']->format() }}</dd>
                            <dt>{{ trans_choice('cash_shift.l_banked_n', $f['deposits_count'], ['count' => $f['deposits_count']]) }}</dt><dd dir="ltr">{{ $f['deposits']->isZero() ? '—' : '− '.$f['deposits']->format() }}</dd>
                            <dt>{{ trans_choice('cash_shift.l_refunds_n', $f['refunds_count'], ['count' => $f['refunds_count']]) }}</dt><dd dir="ltr">{{ $f['refunds']->isZero() ? '—' : '− '.$f['refunds']->format() }}</dd>
                        </dl>
                        @if ($f['own_count'] > 0)
                            <p class="rounded-xl bg-amber-soft/10 px-3 py-2 text-xs text-amber-soft dark:text-amber-300">{{ trans_choice('cash_shift.own_cash_note', $f['own_count'], ['count' => $f['own_count'], 'amount' => $f['own_amount']->format()]) }}</p>
                        @endif
                        @if ($canEdit)
                            <button type="button" wire:click="closeShiftDialog('{{ $shift->id }}')" class="btn-primary mt-auto min-h-11" data-action="close-shift"><x-icon name="scale" class="size-4" />{{ __('cash_shift.close_shift') }}</button>
                        @endif
                    @else
                        <div>
                            @if ($last)
                                <p class="text-sm text-ink-500">{{ __('cash_shift.last_closed', ['z' => $last->zLabel(), 'at' => \App\Support\BusinessTime::display($last->closed_at)]) }}</p>
                                <p class="mt-1 text-sm">
                                    @if (\App\Support\Money::of((string) $last->variance)->isZero())
                                        <span class="text-emerald-soft">{{ __('finance_admin.balanced') }}</span>
                                    @else
                                        <span class="text-ink-500">{{ __('finance_admin.variance') }}</span>
                                        <span class="font-medium tabular-nums {{ \App\Support\Money::of((string) $last->variance)->isNegative() ? 'text-ruby-soft' : 'text-amber-soft' }}" dir="ltr">{{ \App\Support\Money::of((string) $last->variance)->format() }}</span>
                                        @if ($last->hasOpenVariance())<span class="text-xs text-amber-soft">· {{ __('finance_admin.variance_open') }}</span>@endif
                                    @endif
                                </p>
                            @else
                                <p class="text-sm text-ink-500">{{ __('cash_shift.no_shift_yet') }}</p>
                            @endif
                            @if ($shiftRules['blocks'])
                                <p class="mt-2 text-xs text-amber-soft dark:text-amber-300">{{ __('cash_shift.blocks_note') }}</p>
                            @endif
                        </div>
                        @if ($canEdit)
                            <button type="button" wire:click="openShiftDialog('{{ $b->id }}')" class="btn-primary mt-auto min-h-11" data-action="open-shift"><x-icon name="cash" class="size-4" />{{ __('cash_shift.open_shift') }}</button>
                        @endif
                    @endif
                </article>
            @endforeach
        </div>
    </section>
@endif
