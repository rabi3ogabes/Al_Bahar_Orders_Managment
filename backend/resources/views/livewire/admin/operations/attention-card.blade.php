<div wire:poll.30s.visible x-data x-on:ops-order-live.window.debounce.1000ms="$wire.$refresh()">
    @if ($list !== null)
        <section class="card card-pad" aria-labelledby="attention-card-title" data-attention-card>
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="eyebrow">{{ __('ops_live.page.eyebrow') }}</p>
                    <h2 id="attention-card-title" class="mt-1 font-display text-2xl font-semibold">{{ __('ops_live.card.title') }}</h2>
                </div>
                @if ($counts['total'] > 0)
                    <span class="grid min-w-9 place-items-center rounded-full px-2.5 py-1 text-sm font-semibold tabular-nums {{ $counts['red'] > 0 ? 'bg-ruby-soft/10 text-ruby-soft dark:text-red-300' : ($counts['amber'] > 0 ? 'bg-amber-soft/10 text-amber-soft dark:text-amber-300' : 'bg-emerald-soft/10 text-emerald-soft dark:text-emerald-300') }}">{{ $counts['total'] }}</span>
                @endif
            </div>

            @if ($top->isEmpty())
                <div class="mt-6 flex items-center gap-3 rounded-2xl bg-emerald-soft/10 p-4 text-sm text-emerald-soft dark:text-emerald-300">
                    <x-icon name="check" class="size-5 shrink-0" /><span>{{ __('ops_live.card.clear') }}</span>
                </div>
            @else
                <ul class="mt-5 divide-y divide-sand-100 dark:divide-ink-800">
                    @foreach ($top as $item)
                        <li wire:key="card-{{ $item->id }}">
                            <a href="{{ route('admin.operations.attention') }}" wire:navigate class="group flex items-center gap-3 py-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200"><x-icon :name="$item->icon" class="size-4" /></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-medium text-ink-900 dark:text-ivory">@if ($item->subjectIsNumber)<span class="font-mono" dir="ltr">{{ $item->subject }}</span>@else{{ $item->subject }}@endif<span class="font-normal text-ink-500"> · {{ $item->label }}</span></span>
                                    @if ($item->branchCode)<span class="block truncate text-xs text-ink-500" dir="ltr">{{ $item->branchCode }}</span>@endif
                                </span>
                                @if ($item->age)<x-admin.ops.age-chip :state="$item->age->state" :label="$item->age->label" />@endif
                                <x-icon name="chevron-right" class="size-4 shrink-0 text-ink-300 transition group-hover:text-brass-500 rtl-flip" />
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-3 flex items-center justify-between gap-3">
                    @if ($counts['total'] > $top->count())<span class="text-xs text-ink-500">{{ trans_choice('ops_live.card.more', $counts['total'] - $top->count(), ['count' => $counts['total'] - $top->count()]) }}</span>@else<span></span>@endif
                    <a href="{{ route('admin.operations.attention') }}" wire:navigate class="btn-ghost btn-sm min-h-11">{{ __('ops_live.card.see_all') }}<x-icon name="arrow-right" class="size-4 rtl-flip" /></a>
                </div>
            @endif
        </section>
    @endif
</div>
