{{-- Order page: each time the branch changed what is in the order while preparing it (docs/api/ORDER_ADJUSTMENTS.md). $o has revisions.lines, branch, actor, refund loaded. --}}
@php $locale = app()->getLocale(); @endphp
<section class="card overflow-hidden {{ $drawer ? 'order-1' : '' }}" aria-labelledby="rev-title">
    <div class="p-5 sm:p-6">
        <h2 id="rev-title" class="font-display text-xl font-semibold">{{ __('adjust_admin.history_title') }}</h2>
        <p class="mt-1 text-sm text-ink-500">{{ __('adjust_admin.history_hint') }}</p>
    </div>
    <ol class="divide-y divide-sand-100 border-t border-sand-100 dark:divide-ink-800 dark:border-ink-800">
        @foreach ($o->revisions->sortByDesc('number') as $r)
            <li class="space-y-3 px-5 py-4 sm:px-6" wire:key="rev-{{ $r->id }}">
                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="badge-brass">{{ __('adjust_admin.revision_n', ['n' => $r->number]) }}</span>
                    <span class="text-sm font-medium text-ink-900 dark:text-ivory">{{ $r->branch?->localized('name') }}</span>
                    <span class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($r->created_at) }}@if ($r->actor) · {{ __('adjust_admin.by', ['name' => $r->actor->name]) }}@endif</span>
                </div>

                <ul class="space-y-1.5">
                    @foreach ($r->lines as $l)
                        <li class="flex items-start gap-2 text-sm text-ink-800 dark:text-sand-100">
                            <x-icon :name="$l->action === 'replace' ? 'transfer' : ($l->action === 'remove' ? 'trash' : 'minus')" class="mt-0.5 size-4 shrink-0 {{ $l->action === 'reduce' ? 'text-amber-soft' : ($l->action === 'remove' ? 'text-ruby-soft' : 'text-brass-600') }}" />
                            <span class="min-w-0">{{ \App\Domain\Orders\Services\AdjustmentText::line($l, $locale) }}
                                @if ($l->stock_corrected > 0)<span class="block text-xs text-ink-500">{{ __('adjust_admin.stock_corrected', ['n' => $l->stock_corrected]) }}</span>@endif</span>
                        </li>
                    @endforeach
                </ul>

                <p class="text-sm"><span class="text-ink-500">{{ __('adjust_admin.reason_label') }}:</span> <span class="font-medium">{{ __('adjustments.reason.'.$r->reason_code) }}</span>
                    @if ($r->note)<span class="text-ink-500"> · {{ __('adjust_admin.note_label') }}:</span> “{{ $r->note }}”@endif</p>

                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-sm tabular-nums">
                    <span>{{ __('adjust_admin.total_change', ['from' => \App\Support\Money::of($r->before['grand_total'] ?? '0')->format(), 'to' => \App\Support\Money::of($r->after['grand_total'] ?? '0')->format()]) }}</span>
                    <x-admin.money :value="$r->difference" class="text-ruby-soft" />
                    @if ($r->refund)
                        <span class="inline-flex flex-wrap items-center gap-x-2 gap-y-1"><x-icon name="refund" class="size-3.5 shrink-0 text-sky-soft" /><span>{{ __('adjust_admin.refund_label') }}</span> <span class="whitespace-nowrap font-mono text-xs" dir="ltr">{{ $r->refund->number }}</span> <x-admin.money :value="$r->refund->amount" /> <x-admin.status :value="$r->refund->status" /></span>
                    @endif
                </div>

                @if ($r->loyalty_points_returned > 0 || $r->fulfillment_cancelled || $r->order_cancelled)
                    <div class="flex flex-wrap gap-2">
                        @if ($r->order_cancelled)<span class="badge-danger">{{ __('adjust_admin.order_cancelled') }}</span>
                        @elseif ($r->fulfillment_cancelled)<span class="badge-warning">{{ __('adjust_admin.part_cancelled') }}</span>@endif
                        @if ($r->loyalty_points_returned > 0)<span class="badge-info">{{ __('adjust_admin.points_back', ['n' => $r->loyalty_points_returned]) }}</span>@endif
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
</section>
