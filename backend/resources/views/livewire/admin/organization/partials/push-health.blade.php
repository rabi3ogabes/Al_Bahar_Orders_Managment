{{-- Push health: one card per phone app (what is saved, what the phones report, what to fix first), then what the phones themselves report. --}}
@php
    use App\Domain\Notifications\Services\PushHealth;
    use App\Support\BusinessTime;
    $levelTone = ['ok' => 'badge-success', 'warn' => 'badge-warning', 'fail' => 'badge-danger', 'off' => 'badge-neutral'];
    $levelIcon = ['ok' => 'check', 'warn' => 'alert', 'fail' => 'alert', 'off' => 'info'];
    $levelText = ['ok' => 'text-emerald-soft dark:text-emerald-300', 'warn' => 'text-amber-soft dark:text-amber-300', 'fail' => 'text-ruby-soft', 'off' => 'text-ink-500'];
    $codeText = fn (string $code, string $part) => \Illuminate\Support\Facades\Lang::has("push_health.codes.$code.$part") ? __("push_health.codes.$code.$part") : __("push_health.codes.unknown.$part");
    $anyDuplicate = collect($health)->contains(fn ($h) => collect($h['checks'])->contains(fn ($c) => $c['key'] === 'unique' && $c['level'] === 'fail'));
@endphp

<section aria-labelledby="push-health" class="space-y-4" wire:key="push-health">
    <div>
        <h2 id="push-health" class="font-display text-xl font-semibold">{{ __('push_health.health.title') }}</h2>
        <p class="mt-1 text-sm text-ink-500">{{ __('push_health.health.hint') }}</p>
    </div>

    {{-- The server key: what the last "Test connection" said (the page's own button is in the Firebase form below) --}}
    @php($tested = is_array($lastConnection ?? null) ? $lastConnection : null)
    <p class="flex items-start gap-2 text-sm leading-relaxed {{ $tested === null ? 'text-ink-500' : ($tested['ok'] ? 'text-emerald-soft dark:text-emerald-300' : 'text-ruby-soft') }}">
        <x-icon :name="$tested !== null && $tested['ok'] ? 'check' : ($tested === null ? 'info' : 'alert')" class="mt-0.5 size-4 shrink-0" />
        <span class="min-w-0">
            @if ($tested === null){{ __('push_health.health.never_tested') }}
            @else{{ $tested['ok'] ? __('push_admin.conn_ok') : __('push_admin.conn.'.$tested['code']) }} <span class="text-ink-500">· {{ __('push_health.health.tested_at', ['when' => BusinessTime::display($tested['at'])]) }}</span>@endif
        </span>
    </p>

    @if ($anyDuplicate)
        <div class="flex items-start gap-3 rounded-2xl border border-ruby-soft/30 bg-ruby-soft/5 p-4 text-sm text-ink-700 dark:text-sand-100" role="alert">
            <x-icon name="alert" class="mt-0.5 size-5 shrink-0 text-ruby-soft" />
            <div class="min-w-0"><p class="font-semibold text-ruby-soft">{{ __('push_health.health.duplicate.title') }}</p><p class="mt-1 leading-relaxed">{{ __('push_health.health.duplicate.body') }}</p></div>
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($health as $h)
            <article class="card card-pad min-w-0" wire:key="health-{{ $h['platform'] }}-{{ $h['app'] }}" aria-labelledby="health-{{ $h['platform'] }}-{{ $h['app'] }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h3 id="health-{{ $h['platform'] }}-{{ $h['app'] }}" class="font-display text-lg font-semibold">{{ __('push_health.reports.app.'.$h['app']) }} <span class="font-sans text-sm font-normal text-ink-500">· {{ __('push_health.reports.platform.'.$h['platform']) }}</span></h3>
                        <p class="mt-0.5 break-all text-xs text-ink-500" dir="ltr">{{ $h['package'] }}</p>
                    </div>
                    <span class="{{ $levelTone[$h['level']] }} shrink-0"><x-icon :name="$levelIcon[$h['level']]" class="size-3.5" />{{ __('push_health.health.level.'.$h['level']) }}</span>
                </div>

                <dl class="mt-3 grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                    <dt class="text-ink-500">{{ __('push_health.health.app_id') }}</dt>
                    <dd class="break-all font-mono text-xs leading-relaxed text-ink-700 dark:text-sand-100" dir="ltr">{{ $h['app_id'] ?? __('push_health.health.not_saved') }}</dd>
                </dl>

                @if ($h['level'] !== 'off')
                    <ul class="mt-3 space-y-2">
                        @foreach ($h['checks'] as $c)
                            @continue($c['level'] === 'ok' && in_array($c['key'], ['server', 'project_number'], true))
                            <li class="flex items-start gap-2.5 text-sm" wire:key="chk-{{ $h['platform'] }}-{{ $h['app'] }}-{{ $c['key'] }}">
                                <span class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full {{ $c['level'] === 'ok' ? 'bg-emerald-soft/10' : ($c['level'] === 'warn' ? 'bg-amber-soft/10' : 'bg-ruby-soft/10') }} {{ $levelText[$c['level']] }}"><x-icon :name="$c['level'] === 'ok' ? 'check' : 'alert'" class="size-3" /></span>
                                <span class="min-w-0 leading-relaxed text-ink-600 dark:text-ink-300">{{ PushHealth::sentence($c) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    <p class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-500">
                        <span>{{ __('push_health.health.devices', ['n' => number_format($h['devices'])]) }}</span>
                        @if ($h['last_registered_at'])<span>{{ __('push_health.health.last_registered', ['when' => BusinessTime::display($h['last_registered_at'])]) }}</span>@endif
                    </p>
                @else
                    <p class="mt-3 text-sm text-ink-500">{{ __('push_admin.status.missing_ios') }}</p>
                @endif
            </article>
        @endforeach

        <article class="card card-pad min-w-0" aria-labelledby="health-web">
            @php($webOk = $status['web'] && $status['vapid'])
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h3 id="health-web" class="font-display text-lg font-semibold">{{ __('push_health.health.web.title') }}</h3>
                <span class="{{ $levelTone[$webOk ? 'ok' : 'warn'] }} shrink-0"><x-icon :name="$webOk ? 'check' : 'alert'" class="size-3.5" />{{ __('push_health.health.level.'.($webOk ? 'ok' : 'warn')) }}</span>
            </div>
            <p class="mt-3 text-sm text-ink-600 dark:text-ink-300">{{ $webOk ? __('push_health.health.web.ok') : __('push_health.health.web.fail') }}</p>
            <p class="mt-3 text-xs text-ink-500">{{ __('push_health.health.devices', ['n' => number_format($counts['web'] ?? 0)]) }}</p>
        </article>
    </div>

    <p class="flex items-start gap-2 text-xs leading-relaxed text-ink-500"><x-icon name="info" class="mt-0.5 size-3.5 shrink-0" />{{ __('push_health.health.shared_key') }}</p>
</section>

{{-- What the phones report --}}
<section class="card overflow-hidden" aria-labelledby="push-reports" wire:key="push-reports">
    <div class="border-b border-sand-100 p-5 sm:p-6 dark:border-ink-800">
        <h2 id="push-reports" class="font-display text-xl font-semibold">{{ __('push_health.reports.title') }}</h2>
        <p class="mt-1 text-sm text-ink-500">{{ __('push_health.reports.hint') }}</p>
    </div>

    <div class="grid gap-3 border-b border-sand-100 p-5 sm:grid-cols-3 sm:p-6 dark:border-ink-800">
        <h3 class="sr-only">{{ __('push_health.reports.registered_title') }}</h3>
        @foreach (['customer', 'driver', 'staff'] as $app)
            @php($rows = $registered[$app] ?? [])
            <div class="rounded-xl border border-sand-100 p-3 dark:border-ink-800">
                <p class="text-sm font-medium">{{ __('push_health.reports.app.'.$app) }}</p>
                @forelse ($rows as $platform => $r)
                    <p class="mt-1 text-sm text-ink-600 dark:text-ink-300"><span class="font-semibold tabular-nums text-emerald-soft dark:text-emerald-300">{{ number_format($r['phones']) }}</span> {{ __('push_health.reports.platform.'.$platform) }}<span class="block text-xs text-ink-500">{{ __('push_health.health.last_registered', ['when' => BusinessTime::display($r['last_at'])]) }}</span></p>
                @empty
                    <p class="mt-1 text-sm text-ink-500">{{ __('push_health.reports.registered_none') }}</p>
                @endforelse
            </div>
        @endforeach
    </div>

    @if ($problems === [])
        <div class="flex items-start gap-3 p-5 text-sm text-ink-500 sm:p-6">
            <x-icon :name="$registered === [] ? 'info' : 'check'" class="mt-0.5 size-4 shrink-0" />
            <div><p>{{ $registered === [] ? __('push_health.reports.none_at_all') : __('push_health.reports.empty') }}</p>@if ($registered === [])<p class="mt-1 text-xs">{{ __('push_health.health.open_to_report') }}</p>@endif</div>
        </div>
    @else
        <ul class="divide-y divide-sand-100 dark:divide-ink-800">
            @foreach ($problems as $p)
                <li class="p-5 sm:px-6" wire:key="prob-{{ $p['app'] }}-{{ $p['stage'] }}-{{ $p['code'] }}">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="badge-danger">{{ __('push_health.reports.app.'.$p['app']) }} · {{ __('push_health.reports.platform.'.$p['platform']) }}</span>
                        <span class="text-sm font-medium">{{ __('push_health.reports.stage.'.$p['stage']) }}</span>
                        <span class="font-mono text-xs text-ink-500" dir="ltr">{{ $p['code'] }}</span>
                    </div>
                    <p class="mt-1 flex flex-wrap gap-x-4 text-xs text-ink-500">
                        <span>{{ __('push_health.reports.phones', ['n' => number_format($p['phones'])]) }}</span>
                        <span>{{ __('push_health.reports.times', ['n' => number_format($p['reports'])]) }}</span>
                        <span>{{ __('push_health.reports.last_seen', ['when' => BusinessTime::display($p['last_seen_at'])]) }}</span>
                        @if ($p['app_version'])<span dir="ltr">{{ $p['app_version'] }}</span>@endif
                    </p>
                    <dl class="mt-3 space-y-2 text-sm leading-relaxed">
                        <div><dt class="font-medium text-ink-800 dark:text-sand-100">{{ __('push_health.reports.what') }}</dt><dd class="text-ink-600 dark:text-ink-300">{{ $codeText($p['code'], 'what') }}</dd></div>
                        <div><dt class="font-medium text-ink-800 dark:text-sand-100">{{ __('push_health.reports.fix') }}</dt><dd class="text-ink-600 dark:text-ink-300">{{ $codeText($p['code'], 'fix') }}</dd></div>
                        @if ($p['message'])<div><dt class="text-xs font-medium text-ink-500">{{ __('push_health.reports.said') }}</dt><dd class="mt-0.5 break-words rounded-lg bg-sand-50 px-2.5 py-1.5 font-mono text-xs text-ink-600 dark:bg-ink-800/60 dark:text-ink-300" dir="ltr">{{ $p['message'] }}</dd></div>@endif
                    </dl>
                </li>
            @endforeach
        </ul>
        <p class="border-t border-sand-100 px-5 py-3 text-xs text-ink-500 sm:px-6 dark:border-ink-800">{{ __('push_health.reports.resolved_note') }}</p>
    @endif
</section>
