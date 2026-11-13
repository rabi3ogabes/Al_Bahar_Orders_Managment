@props(['touches', 'order' => null])
{{-- Messages a customer received from journeys; with an order, the one credited with winning it is highlighted. --}}
@php
    $won = $order ? \App\Domain\Marketing\Models\JourneyRun::where('order_id', $order->id)->with(['attributedDelivery.step:id,label,position', 'journey:id,key'])->first() : null;
@endphp
@if ($won)
    @php $d = $won->attributedDelivery; $mins = $d?->sent_at ? $d->sent_at->diffInMinutes($won->converted_at) : null; @endphp
    <div class="mb-6 flex items-start gap-3 rounded-2xl border border-brass-300/60 bg-brass-50 p-4 dark:border-brass-500/30 dark:bg-brass-500/10" role="note">
        <x-icon name="gift" class="mt-0.5 size-5 shrink-0 text-brass-600" />
        <div class="min-w-0 text-sm">
            <p class="font-semibold text-ink-900 dark:text-ivory">{{ __('journeys_admin.won_title') }}</p>
            <p class="mt-0.5 text-ink-600 dark:text-ink-300">
                {{ __('journeys_admin.won_body', ['journey' => __('journeys_admin.name_'.$won->journey->key), 'channel' => $d ? __('content_admin.ch_'.$d->channel) : '', 'when' => $mins === null ? '' : ($mins >= 120 ? __('journeys_admin.rc_hours', ['n' => round($mins / 60, 1)]) : __('journeys_admin.rc_mins', ['n' => $mins]))]) }}
                @if ($d?->coupon_code)<span class="badge-brass font-mono">{{ $d->coupon_code }}</span>@endif
            </p>
        </div>
    </div>
@endif
@if (! $order && $touches->isNotEmpty())
    <section class="card mb-6 p-5" aria-label="{{ __('journeys_admin.touches_title') }}">
        <h3 class="mb-3 font-display text-lg font-semibold">{{ __('journeys_admin.touches_title') }}</h3>
        <ul class="divide-y divide-sand-100 text-sm dark:divide-ink-800">
            @foreach ($touches as $t)
                <li class="flex flex-wrap items-center gap-2 py-2"><span class="badge-neutral">{{ __('content_admin.ch_'.$t->channel) }}</span><span>{{ __('journeys_admin.name_'.($t->run?->journey?->key ?? 'abandoned_cart')) }} · {{ $t->step?->label ?: '#'.$t->step?->position }}</span>
                    @if ($t->coupon_code)<span class="badge-brass font-mono">{{ $t->coupon_code }}</span>@endif<span class="ms-auto tabular-nums text-ink-500">{{ \App\Support\BusinessTime::display($t->sent_at) }}</span></li>
            @endforeach
        </ul>
    </section>
@endif
