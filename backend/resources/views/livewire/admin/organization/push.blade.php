@php
    $hasKey = $status['server'];
    $fromEnv = $status['source'] === 'env';
    $keyOnDashboard = $status['source'] === 'dashboard';
    $showKeyInput = ! $keyOnDashboard || $replacingKey;
    $webReady = $status['web'];
    $tone = fn (bool $ok) => $ok ? 'bg-emerald-soft/10 text-emerald-soft dark:text-emerald-300' : 'bg-amber-soft/10 text-amber-soft dark:text-amber-300';
@endphp
<div class="mx-auto max-w-5xl space-y-6">
    <x-admin.page-header :title="__('push_admin.title')" :eyebrow="__('nav.organization')" :description="__('push_admin.hint')" />

    {{-- 1 · Master switch: acts at once, never mixed with the form below --}}
    <section class="card card-pad flex items-center justify-between gap-4" aria-labelledby="push-master">
        <div class="min-w-0">
            <h2 id="push-master" class="font-display text-xl font-semibold">{{ __('push_admin.master') }}</h2>
            <p class="mt-1 text-sm text-ink-500">{{ __('push_admin.master_hint') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <span class="text-sm font-medium {{ $enabled ? 'text-emerald-soft dark:text-emerald-300' : 'text-ink-500' }}" aria-live="polite">{{ $enabled ? __('push_admin.state_on') : __('push_admin.state_off') }}</span>
            <button type="button" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" aria-label="{{ __('push_admin.master') }}" wire:click="toggleEnabled" wire:loading.attr="disabled" wire:target="toggleEnabled" @disabled(! $canEdit)
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-full disabled:cursor-not-allowed disabled:opacity-50">
                <span class="relative inline-flex h-7 w-12 items-center rounded-full transition {{ $enabled ? 'bg-emerald-soft' : 'bg-sand-300 dark:bg-ink-600' }}">
                    <span class="inline-block size-5 rounded-full bg-white shadow transition {{ $enabled ? 'translate-x-6 rtl:-translate-x-6' : 'translate-x-1 rtl:-translate-x-1' }}"></span>
                </span>
            </button>
        </div>
    </section>

    {{-- 2 · Setup status --}}
    <section aria-labelledby="push-status">
        <h2 id="push-status" class="sr-only">{{ __('push_admin.status_title') }}</h2>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div class="card card-pad">
                <div class="flex items-center gap-2 text-sm font-medium"><span class="grid size-6 place-items-center rounded-full {{ $tone($hasKey) }}"><x-icon :name="$hasKey ? 'check' : 'alert'" class="size-3.5" /></span>{{ __('push_admin.status.server') }}</div>
                <p class="mt-2 text-sm {{ $hasKey ? 'text-ink-600 dark:text-ink-300' : 'text-ink-500' }}">
                    @if ($hasKey)<span class="break-all" dir="ltr">{{ $status['client_email'] }}</span>@if ($fromEnv)<span class="mt-1 block text-xs text-ink-500">{{ __('push_admin.status.from_env') }}</span>@endif
                    @else{{ __('push_admin.status.missing') }}@endif
                </p>
            </div>
            <div class="card card-pad">
                <div class="flex items-center gap-2 text-sm font-medium"><span class="grid size-6 place-items-center rounded-full {{ $tone($webReady) }}"><x-icon :name="$webReady ? 'check' : 'alert'" class="size-3.5" /></span>{{ __('push_admin.status.web') }}</div>
                <p class="mt-2 text-sm text-ink-500">{{ $webReady ? __('push_admin.status.ok_web') : __('push_admin.status.missing_web') }}</p>
            </div>
            <div class="card card-pad">
                <div class="flex items-center gap-2 text-sm font-medium"><span class="grid size-6 place-items-center rounded-full {{ $tone($status['vapid']) }}"><x-icon :name="$status['vapid'] ? 'check' : 'alert'" class="size-3.5" /></span>{{ __('push_admin.status.vapid') }}</div>
                <p class="mt-2 text-sm text-ink-500">{{ $status['vapid'] ? __('push_admin.status.ok_vapid') : __('push_admin.status.missing_vapid') }}</p>
            </div>
            <div class="card card-pad">
                <div class="flex items-center gap-2 text-sm font-medium"><x-icon name="phone" class="size-4 text-ink-500" />{{ __('push_admin.status.devices') }}</div>
                <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-ink-600 dark:text-ink-300">
                    @foreach (['web', 'android', 'ios'] as $p)<li><span class="font-semibold tabular-nums">{{ number_format($counts[$p] ?? 0) }}</span> <span class="text-ink-500">{{ __('push_admin.status.platform.'.$p) }}</span></li>@endforeach
                </ul>
            </div>
            <div class="card card-pad">
                <div class="flex items-center gap-2 text-sm font-medium"><x-icon name="check" class="size-4 text-ink-500" />{{ __('push_admin.status.last_ok') }}</div>
                <p class="mt-2 text-sm text-ink-500">{{ $lastOk ? \App\Support\BusinessTime::display($lastOk) : __('push_admin.status.none_yet') }}</p>
            </div>
            <div class="card card-pad">
                <div class="flex items-center gap-2 text-sm font-medium"><x-icon name="alert" class="size-4 text-ink-500" />{{ __('push_admin.status.last_error') }}</div>
                @if ($lastError)
                    <p class="mt-2 text-sm text-ruby-soft">{{ \Illuminate\Support\Str::limit((string) $lastError->error, 120) ?: '—' }}</p>
                    <p class="mt-0.5 text-xs text-ink-500">{{ \App\Support\BusinessTime::display($lastError->updated_at) }}</p>
                @else
                    <p class="mt-2 text-sm text-ink-500">{{ __('push_admin.status.none') }}</p>
                @endif
            </div>
        </div>
    </section>

    {{-- 3 · Guided steps --}}
    <details class="card group" @if (! $allReady) open @endif>
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 p-5 sm:px-6">
            <span><span class="block font-display text-xl font-semibold">{{ __('push_admin.steps_title') }}</span><span class="mt-0.5 block text-sm text-ink-500">{{ __('push_admin.steps_hint') }}</span></span>
            <x-icon name="chevron-down" class="size-5 shrink-0 text-ink-400 transition group-open:rotate-180" />
        </summary>
        <ol class="space-y-4 border-t border-sand-100 p-5 sm:px-6 dark:border-ink-800">
            @foreach (__('push_admin.steps') as $n => $step)
                <li class="flex gap-4">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brass-50 text-sm font-semibold text-brass-700 dark:bg-brass-500/15 dark:text-brass-300">{{ $n }}</span>
                    <div class="min-w-0"><h3 class="font-medium text-ink-900 dark:text-ivory">{{ $step['title'] }}</h3><p class="mt-0.5 text-sm leading-relaxed text-ink-500">{{ $step['body'] }}</p></div>
                </li>
            @endforeach
            <li class="flex items-start gap-3 rounded-xl bg-amber-soft/10 p-3 text-sm text-ink-700 dark:text-sand-100"><x-icon name="lock" class="mt-0.5 size-4 shrink-0 text-amber-soft" />{{ __('push_admin.keep_private') }}</li>
        </ol>
    </details>

    {{-- 4 · Firebase form (deferred: waits for Save) --}}
    <form wire:submit="save" class="card overflow-hidden" autocomplete="off">
        <div class="border-b border-sand-100 p-5 sm:p-6 dark:border-ink-800">
            <h2 class="font-display text-xl font-semibold">{{ __('push_admin.form_title') }}</h2>
            <p class="mt-1 text-sm text-ink-500">{{ __('push_admin.form_hint') }}</p>
        </div>
        <fieldset class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6" @disabled(! $canEdit)>
            @foreach ([['projectId', 'project_id', false], ['apiKey', 'api_key', false], ['authDomain', 'auth_domain', false], ['senderId', 'sender_id', false], ['appId', 'app_id', false], ['vapidKey', 'vapid_key', true]] as [$prop, $lang, $wide])
                <x-admin.field :label="__('push_admin.field.'.$lang)" :for="$prop" :hint="__('push_admin.hint_field.'.$lang)" class="{{ $wide ? 'sm:col-span-2' : '' }}">
                    <input id="{{ $prop }}" wire:model.live.debounce.700ms="{{ $prop }}" class="input font-mono text-[0.8125rem] @error($prop) input-error @enderror" dir="ltr" spellcheck="false" autocapitalize="off" autocomplete="off"
                           placeholder="{{ __('push_admin.example.'.$lang) }}" @if ($errors->has($prop)) aria-invalid="true" @endif>
                </x-admin.field>
            @endforeach

            {{-- Server key: write-only. Once saved it is only ever shown as "configured" with its service-account e-mail. --}}
            <div class="sm:col-span-2" x-data>
                <span class="field-label">{{ __('push_admin.field.service_account') }}</span>
                @if ($keyOnDashboard && ! $replacingKey)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sand-200 bg-sand-50/60 p-3 dark:border-ink-700 dark:bg-ink-800/40">
                        <div class="flex min-w-0 items-center gap-2 text-sm"><span class="grid size-6 shrink-0 place-items-center rounded-full {{ $tone(true) }}"><x-icon name="check" class="size-3.5" /></span><span class="font-medium">{{ __('push_admin.key_ok') }}</span><span class="truncate text-ink-500" dir="ltr">{{ $status['client_email'] }}</span></div>
                        @if ($canEdit)
                            <div class="flex gap-2">
                                <button type="button" class="btn-secondary min-h-11" wire:click="$set('replacingKey', true)">{{ __('push_admin.key_replace') }}</button>
                                <button type="button" class="btn-ghost min-h-11 text-ruby-soft" wire:click="$set('confirmRemoveKey', true)">{{ __('push_admin.key_remove') }}</button>
                            </div>
                        @endif
                    </div>
                @else
                    @if ($fromEnv)<p class="mb-2 flex items-start gap-2 text-sm text-ink-500"><x-icon name="info" class="mt-0.5 size-4 shrink-0" />{{ __('push_admin.key_from_env') }}</p>@endif
                    <textarea id="serviceAccountJson" wire:model.live.debounce.600ms="serviceAccountJson" rows="6" dir="ltr" spellcheck="false" autocomplete="off" autocapitalize="off"
                              class="input font-mono text-xs @error('serviceAccountJson') input-error @enderror" placeholder="{{ __('push_admin.example.service_account') }}" @if ($errors->has('serviceAccountJson')) aria-invalid="true" @endif></textarea>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <label class="btn-secondary min-h-11 cursor-pointer focus-within:ring-2 focus-within:ring-brass-500/40">
                            <x-icon name="upload" class="size-4" />{{ __('push_admin.key_upload') }}
                            <input type="file" accept="application/json,.json" class="sr-only"
                                   @change="const f = $event.target.files[0]; if (f) { f.text().then(t => $wire.set('serviceAccountJson', t)); } $event.target.value = ''">
                        </label>
                        <span class="text-sm text-ink-500">{{ __('push_admin.key_or_paste') }}</span>
                        @if ($replacingKey)<button type="button" class="btn-ghost min-h-11" wire:click="$set('replacingKey', false)">{{ __('push_admin.key_cancel_replace') }}</button>@endif
                    </div>
                    <p class="field-hint">{{ __('push_admin.key_new_hint') }}</p>
                    @error('serviceAccountJson')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @endif
            </div>
        </fieldset>

        @if ($canEdit)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-sand-100 bg-sand-50/50 px-5 py-4 sm:px-6 dark:border-ink-800 dark:bg-ink-800/30">
                <p class="text-xs text-ink-500">{{ __('org_admin.changes_audited') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-secondary min-h-11" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection" @disabled($dirty || ! $hasKey) @if ($dirty && $hasKey) title="{{ __('push_admin.save_first') }}" @endif>
                        <x-icon name="zap" class="size-4" /><span wire:loading.remove wire:target="testConnection">{{ __('push_admin.test_connection') }}</span><span wire:loading wire:target="testConnection">{{ __('push_admin.testing') }}</span>
                    </button>
                    <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="save" @disabled(! $dirty)>
                        <x-icon name="check" class="size-4" /><span wire:loading.remove wire:target="save">{{ __('push_admin.save') }}</span><span wire:loading wire:target="save">{{ __('push_admin.saving') }}</span>
                    </button>
                </div>
            </div>
        @else
            <p class="border-t border-sand-100 px-5 py-4 text-sm text-ink-500 sm:px-6 dark:border-ink-800">{{ __('push_admin.edit_denied') }}</p>
        @endif

        @if ($connection)
            <div class="border-t border-sand-100 px-5 py-4 sm:px-6 dark:border-ink-800" role="status">
                <div class="flex items-start gap-3 text-sm {{ $connection['ok'] ? 'text-emerald-soft dark:text-emerald-300' : 'text-ruby-soft' }}">
                    <x-icon :name="$connection['ok'] ? 'check' : 'alert'" class="mt-0.5 size-4 shrink-0" />
                    <div class="min-w-0"><p class="font-medium">{{ $connection['ok'] ? __('push_admin.conn_ok') : __('push_admin.conn.'.$connection['code']) }}</p>
                        @if (! $connection['ok'] && $connection['detail'])<p class="mt-1 break-words text-xs text-ink-500" dir="ltr">{{ __('push_admin.conn_detail') }}: {{ $connection['detail'] }}</p>@endif</div>
                </div>
            </div>
        @endif
    </form>

    {{-- 5 · This browser --}}
    <section class="card card-pad" aria-labelledby="push-browser" x-data="pushBrowser">
        <div wire:key="push-boot" x-ref="boot" data-boot="{{ json_encode($boot) }}" hidden></div>
        <h2 id="push-browser" class="font-display text-xl font-semibold">{{ __('push_admin.browser_title') }}</h2>
        <p class="mt-1 text-sm text-ink-500">{{ __('push_admin.browser_hint') }}</p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            @if ($boot['config'])
                <button type="button" class="btn-primary min-h-11" @click="enable()" :disabled="state === 'busy' || support !== 'ok'">
                    <x-icon name="bell-ring" class="size-4" /><span x-show="state !== 'busy'">{{ __('push_admin.enable_browser') }}</span><span x-show="state === 'busy'" x-cloak>{{ __('push_admin.enabling') }}</span>
                </button>
            @else
                <button type="button" class="btn-primary min-h-11" disabled><x-icon name="bell-ring" class="size-4" />{{ __('push_admin.enable_browser') }}</button>
            @endif
            @if ($canEdit)
                <button type="button" class="btn-secondary min-h-11" wire:click="sendTest" wire:loading.attr="disabled" wire:target="sendTest" @disabled(! $boot['config'] || $mine->isEmpty())>
                    <x-icon name="zap" class="size-4" /><span wire:loading.remove wire:target="sendTest">{{ __('push_admin.send_test') }}</span><span wire:loading wire:target="sendTest">{{ __('push_admin.sending') }}</span>
                </button>
            @endif
            <span class="text-sm text-ink-500" x-show="permission !== 'unsupported'" x-text="permissionLabels[permission] ?? ''"></span>
        </div>
        @if (! $boot['config'])<p class="mt-3 text-sm text-ink-500">{{ __('push_admin.browser_err.no_config') }}</p>@endif
        <p class="mt-3 text-sm text-ruby-soft" role="alert" x-show="error" x-text="error" x-cloak></p>
        <p class="mt-3 text-sm text-ruby-soft" x-show="support !== 'ok' && ! error" x-text="supportText" x-cloak></p>

        @if ($testResults)
            <div class="mt-5" role="status">
                <h3 class="text-sm font-semibold">{{ __('push_admin.test.results') }}</h3>
                <ul class="mt-2 divide-y divide-sand-100 rounded-xl border border-sand-100 dark:divide-ink-800 dark:border-ink-800">
                    @foreach ($testResults as $r)
                        <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="min-w-0 truncate">{{ $r['label'] }}</span>
                            <span class="shrink-0 {{ $r['outcome'] === 'sent' ? 'badge-success' : ($r['outcome'] === 'removed' ? 'badge-warning' : 'badge-danger') }}">{{ __('push_admin.test.outcome.'.$r['outcome']) }}@if ($r['detail']) · <span dir="ltr">{{ $r['detail'] }}</span>@endif</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-6">
            <h3 class="text-sm font-semibold">{{ __('push_admin.devices_title') }}</h3>
            @forelse ($mine as $d)
                <div class="flex items-center justify-between gap-3 border-b border-sand-100 py-2 text-sm last:border-0 dark:border-ink-800" wire:key="dev-{{ $d->id }}">
                    <div class="min-w-0">
                        <span class="block truncate font-medium">{{ $d->platform === 'web' ? ($d->browser ?: __('push_admin.device_kind.web')) : __('push_admin.device_kind.'.$d->platform) }}@if ($d->app) <span class="font-normal text-ink-500">· {{ __('push_admin.app.'.$d->app) }}</span>@endif</span>
                        <span class="block text-xs text-ink-500">{{ __('push_admin.device_seen', ['when' => $d->last_seen_at ? \App\Support\BusinessTime::display($d->last_seen_at) : __('push_admin.last_never')]) }}</span>
                    </div>
                    <button type="button" class="btn-ghost size-11 shrink-0 p-0" wire:click="removeDevice('{{ $d->id }}')" aria-label="{{ __('push_admin.device_remove') }}" title="{{ __('push_admin.device_remove') }}"><x-icon name="trash" class="size-4" /></button>
                </div>
            @empty
                <p class="mt-2 text-sm text-ink-500">{{ __('push_admin.devices_empty') }}</p>
            @endforelse
        </div>

        <div class="mt-6 space-y-3 rounded-xl bg-sand-50 p-4 text-sm leading-relaxed text-ink-600 dark:bg-ink-800/50 dark:text-ink-300">
            <div class="flex gap-3"><x-icon name="apple" class="mt-0.5 size-4 shrink-0" /><div><p class="font-medium text-ink-800 dark:text-sand-100">{{ __('push_admin.ios_title') }}</p><p class="mt-0.5">{{ __('push_admin.ios_body') }}</p></div></div>
            <div class="flex gap-3"><x-icon name="android" class="mt-0.5 size-4 shrink-0" /><p>{{ __('push_admin.android_note') }}</p></div>
        </div>
    </section>

    <x-admin.dialog variant="modal" wire:model="confirmRemoveKey" :title="__('push_admin.key_remove_q')" max-width="max-w-md">
        <p class="text-sm text-ink-600 dark:text-ink-300">{{ __('push_admin.key_remove_body') }}</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn-secondary min-h-11" @click="requestClose()" x-init="$nextTick(() => $el.focus())">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary min-h-11 !bg-ruby-soft" wire:click="removeServiceAccount">{{ __('push_admin.key_remove_yes') }}</button>
        </div>
    </x-admin.dialog>
</div>

@script
<script>
    // Static x-data (a changing value would make Livewire rebuild the component). Start-up data rides on the hidden x-ref="boot" element and is read on each use.
    Alpine.data('pushBrowser', () => {
        let mod = null;
        return {
            state: 'idle', error: '', support: 'ok', permission: 'default', supportText: '', permissionLabels: {},
            boot() { return JSON.parse(this.$refs.boot.dataset.boot); },
            async init() {
                const labels = @js(__('push_admin.permission'));
                this.permissionLabels = labels;
                this.permission = typeof Notification === 'undefined' ? 'unsupported' : Notification.permission;
                mod = await window.loadPushAdmin();
                this.support = mod.supportState({ userAgent: navigator.userAgent, platform: navigator.platform, maxTouchPoints: navigator.maxTouchPoints,
                    standalone: window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true,
                    hasServiceWorker: 'serviceWorker' in navigator, hasPush: 'PushManager' in window, hasNotification: typeof Notification !== 'undefined' });
                if (this.support !== 'ok') this.supportText = this.boot().text[this.support] ?? '';
                const config = this.boot().config;
                if (config && this.support === 'ok') {
                    // A message that arrives while this page is open is shown as a toast (the browser only draws notifications for hidden pages).
                    mod.listen({ config, onMessage: (m) => window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: [m.title, m.body].filter(Boolean).join(': ') } })) }).catch(() => {});
                }
            },
            async enable() {
                const boot = this.boot();
                this.state = 'busy';
                this.error = '';
                try {
                    mod ??= await window.loadPushAdmin();
                    await mod.enable({ config: boot.config, endpoint: boot.endpoint, csrf: document.querySelector('meta[name=csrf-token]')?.content ?? '' });
                    this.state = 'ok';
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: boot.done } }));
                    this.$wire.$refresh();
                } catch (e) {
                    this.state = 'error';
                    console.warn('Push: enabling this browser failed', e); // the exact reason, for anyone with the console open
                    this.error = boot.text[mod?.errorCode(e) ?? 'unknown'] ?? boot.text.unknown;
                } finally {
                    this.permission = typeof Notification === 'undefined' ? 'unsupported' : Notification.permission;
                    if (this.state === 'busy') this.state = 'idle';
                }
            },
        };
    });
</script>
@endscript
