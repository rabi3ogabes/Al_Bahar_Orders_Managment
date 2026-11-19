@php
    $dot = fn (string $state) => match ($state) { 'ok' => 'bg-emerald-soft', 'running' => 'bg-sky-soft', 'never' => 'bg-amber-soft', default => 'bg-ruby-soft' };
@endphp
<section class="card card-pad mt-6" wire:poll.60s aria-labelledby="health-title" data-system-health>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="eyebrow">{{ __('admin.operations') }}</p>
            <h2 id="health-title" class="mt-1 font-display text-2xl font-semibold">{{ __('owner_numbers.health_title') }}</h2>
            <p class="mt-1 max-w-2xl text-xs text-ink-500">{{ __('owner_numbers.health_hint') }}</p>
        </div>
        <span class="{{ $h['ok'] ? 'badge-success' : 'badge-danger' }}" data-health-summary="{{ $h['ok'] ? 'ok' : 'attention' }}">
            <span class="size-1.5 rounded-full bg-current opacity-70"></span>{{ $h['ok'] ? __('owner_numbers.health_all_ok') : __('owner_numbers.health_attention') }}
        </span>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-3 [&>*]:min-w-0">
        <div class="lg:col-span-2">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('owner_numbers.health_scheduler') }}</h3>
            <ul class="mt-2 divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($h['tasks'] as $t)
                    <li class="flex items-start gap-3 py-2.5" data-task="{{ $t['task'] }}" data-state="{{ $t['state'] }}">
                        <span class="mt-1.5 size-2.5 shrink-0 rounded-full {{ $dot($t['state']) }}" aria-hidden="true"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm text-ink-800 dark:text-sand-100">{{ \Illuminate\Support\Facades\Lang::has('owner_numbers.task.'.$t['task']) ? __('owner_numbers.task.'.$t['task']) : \Illuminate\Support\Str::headline(str_replace([':', '-'], ' ', $t['task'])) }}</p>
                            <p class="font-mono text-[0.7rem] text-ink-400" dir="ltr">{{ $t['task'] }}</p>
                        </div>
                        <div class="text-end text-xs">
                            <p class="{{ in_array($t['state'], ['ok', 'running'], true) ? 'text-ink-500' : 'font-semibold text-ruby-soft' }}">{{ __('owner_numbers.health_state.'.$t['state']) }}</p>
                            <p class="text-ink-400">@if ($t['last_finished_at']){{ __('owner_numbers.health_last_run', ['when' => $t['last_finished_at']->locale(app()->getLocale())->diffForHumans()]) }}@else{{ __('owner_numbers.health_never') }}@endif</p>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <div>
            <h3 class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('owner_numbers.health_queue') }}</h3>
            @php $q = $h['queue']; @endphp
            <div class="mt-2 rounded-xl bg-sand-50 p-4 dark:bg-ink-800/60" data-queue-state="{{ $q['state'] }}">
                <p class="flex items-center gap-2 text-sm font-medium"><span class="size-2.5 rounded-full {{ in_array($q['state'], ['ok', 'inline'], true) ? 'bg-emerald-soft' : 'bg-ruby-soft' }}" aria-hidden="true"></span>
                    {{ __('owner_numbers.health_queue_state.'.$q['state'], ['max' => \App\Domain\Reports\Services\SystemHealth::QUEUE_BACKLOG]) }}</p>
                @if ($q['waiting'] !== null)
                    <p class="mt-3 font-display text-2xl font-semibold tabular-nums">{{ number_format($q['waiting']) }}</p>
                    <p class="text-xs text-ink-500">{{ __('owner_numbers.health_waiting', ['count' => number_format($q['waiting'])]) }}</p>
                @endif
                @if ($q['failed'] !== null)
                    <p class="mt-2 text-xs {{ $q['failed'] > 0 ? 'font-semibold text-ruby-soft' : 'text-ink-500' }}">{{ __('owner_numbers.health_failed_jobs', ['count' => number_format($q['failed'])]) }}</p>
                @endif
            </div>
        </div>
    </div>
</section>
