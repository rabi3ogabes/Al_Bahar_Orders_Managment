{{-- iPhone live activity: the Apple push key that lets the server update the order on the lock screen and in the Dynamic Island. Same pattern as the Firebase form above: sealed write-only key, Save, Test. --}}
@php
    $apnsKeyOnServer = $apns['key'];
    $apnsShowKeyInput = ! $apnsKeyOnServer || $replacingApnsKey;
    $apnsTone = fn (bool $ok) => $ok ? 'bg-emerald-soft/10 text-emerald-soft dark:text-emerald-300' : 'bg-amber-soft/10 text-amber-soft dark:text-amber-300';
@endphp
<section class="card overflow-hidden" aria-labelledby="push-apns" wire:key="push-apns">
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-sand-100 p-5 sm:p-6 dark:border-ink-800">
        <div class="min-w-0">
            <h2 id="push-apns" class="font-display text-xl font-semibold">{{ __('push_apns.title') }}</h2>
            <p class="mt-1 text-sm text-ink-500">{{ __('push_apns.hint') }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <span class="text-sm font-medium {{ $apnsEnabled ? 'text-emerald-soft dark:text-emerald-300' : 'text-ink-500' }}" aria-live="polite">{{ $apnsEnabled ? __('push_admin.state_on') : __('push_admin.state_off') }}</span>
            <button type="button" role="switch" aria-checked="{{ $apnsEnabled ? 'true' : 'false' }}" aria-label="{{ __('push_apns.master') }}" wire:click="toggleApns" wire:loading.attr="disabled" wire:target="toggleApns" @disabled(! $canEdit)
                    class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-full disabled:cursor-not-allowed disabled:opacity-50">
                <span class="relative inline-flex h-7 w-12 items-center rounded-full transition {{ $apnsEnabled ? 'bg-emerald-soft' : 'bg-sand-300 dark:bg-ink-600' }}">
                    <span class="inline-block size-5 rounded-full bg-white shadow transition {{ $apnsEnabled ? 'translate-x-6 rtl:-translate-x-6' : 'translate-x-1 rtl:-translate-x-1' }}"></span>
                </span>
            </button>
        </div>
    </div>

    <div class="grid gap-3 p-5 sm:grid-cols-3 sm:p-6">
        <div class="rounded-xl border border-sand-100 p-3 dark:border-ink-800">
            <div class="flex items-center gap-2 text-sm font-medium"><span class="grid size-6 place-items-center rounded-full {{ $apnsTone($apns['ready']) }}"><x-icon :name="$apns['ready'] ? 'check' : 'alert'" class="size-3.5" /></span>{{ __('push_apns.status.key') }}</div>
            <p class="mt-2 text-sm text-ink-500">
                @if ($apns['ready'])<span dir="ltr">{{ $apns['key_id'] }}</span> · <span dir="ltr">{{ $apns['team_id'] }}</span>@else{{ __('push_apns.status.missing') }}@endif
            </p>
        </div>
        <div class="rounded-xl border border-sand-100 p-3 dark:border-ink-800">
            <div class="flex items-center gap-2 text-sm font-medium"><span class="grid size-6 place-items-center rounded-full {{ $apnsTone($apns['enabled'] && $apns['ready']) }}"><x-icon :name="$apns['enabled'] && $apns['ready'] ? 'check' : 'alert'" class="size-3.5" /></span>{{ __('push_apns.status.sending') }}</div>
            <p class="mt-2 text-sm text-ink-500">{{ $apns['enabled'] && $apns['ready'] ? __('push_apns.status.on', ['bundle' => $apns['bundle_id']]) : __('push_apns.status.off') }}</p>
        </div>
        <div class="rounded-xl border border-sand-100 p-3 dark:border-ink-800">
            <div class="flex items-center gap-2 text-sm font-medium"><x-icon name="phone" class="size-4 text-ink-500" />{{ __('push_apns.status.activities') }}</div>
            <p class="mt-2 text-sm text-ink-500"><span class="font-semibold tabular-nums text-ink-700 dark:text-ink-200">{{ number_format($apnsActivities) }}</span> {{ __('push_apns.status.running') }} · <span class="font-semibold tabular-nums text-ink-700 dark:text-ink-200">{{ number_format($apnsStarters) }}</span> {{ __('push_apns.status.starters') }}</p>
        </div>
    </div>

    <details class="group border-t border-sand-100 dark:border-ink-800" @if (! $apns['ready']) open @endif>
        <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 p-5 sm:px-6">
            <span><span class="block font-display text-lg font-semibold">{{ __('push_apns.steps_title') }}</span><span class="mt-0.5 block text-sm text-ink-500">{{ __('push_apns.steps_hint') }}</span></span>
            <x-icon name="chevron-down" class="size-5 shrink-0 text-ink-400 transition group-open:rotate-180" />
        </summary>
        <ol class="space-y-4 border-t border-sand-100 p-5 sm:px-6 dark:border-ink-800">
            @foreach (__('push_apns.steps') as $n => $step)
                <li class="flex gap-4">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brass-50 text-sm font-semibold text-brass-700 dark:bg-brass-500/15 dark:text-brass-300">{{ $n }}</span>
                    <div class="min-w-0"><h3 class="font-medium text-ink-900 dark:text-ivory">{{ $step['title'] }}</h3><p class="mt-0.5 text-sm leading-relaxed text-ink-500">{{ $step['body'] }}</p></div>
                </li>
            @endforeach
            <li class="flex items-start gap-3 rounded-xl bg-amber-soft/10 p-3 text-sm text-ink-700 dark:text-sand-100"><x-icon name="lock" class="mt-0.5 size-4 shrink-0 text-amber-soft" />{{ __('push_apns.keep_private') }}</li>
        </ol>
    </details>

    <form wire:submit="saveApns" autocomplete="off" class="border-t border-sand-100 dark:border-ink-800">
        <fieldset class="grid gap-5 p-5 sm:grid-cols-2 sm:p-6" @disabled(! $canEdit)>
            @foreach ([['apnsTeamId', 'team_id'], ['apnsKeyId', 'key_id'], ['apnsBundleId', 'bundle_id']] as [$prop, $lang])
                <x-admin.field :label="__('push_apns.field.'.$lang)" :for="$prop" :hint="__('push_apns.hint_field.'.$lang)">
                    <input id="{{ $prop }}" wire:model.live.debounce.700ms="{{ $prop }}" class="input font-mono text-[0.8125rem] @error($prop) input-error @enderror" dir="ltr" spellcheck="false" autocapitalize="off" autocomplete="off"
                           placeholder="{{ __('push_apns.example.'.$lang) }}" @if ($errors->has($prop)) aria-invalid="true" @endif>
                </x-admin.field>
            @endforeach
            <x-admin.field :label="__('push_apns.field.environment')" for="apnsEnvironment" :hint="__('push_apns.hint_field.environment')">
                <select id="apnsEnvironment" wire:model.live="apnsEnvironment" class="input @error('apnsEnvironment') input-error @enderror">
                    @foreach (\App\Domain\Notifications\Services\ApnsConfig::ENVIRONMENTS as $env)
                        <option value="{{ $env }}">{{ __('push_apns.environment.'.$env) }}</option>
                    @endforeach
                </select>
            </x-admin.field>

            {{-- The .p8 key: write-only. Once saved it is only ever shown as "configured" with its Key ID. --}}
            <div class="sm:col-span-2" x-data>
                <span class="field-label">{{ __('push_apns.field.key') }}</span>
                @if ($apnsKeyOnServer && ! $replacingApnsKey)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sand-200 bg-sand-50/60 p-3 dark:border-ink-700 dark:bg-ink-800/40">
                        <div class="flex min-w-0 items-center gap-2 text-sm"><span class="grid size-6 shrink-0 place-items-center rounded-full {{ $apnsTone(true) }}"><x-icon name="check" class="size-3.5" /></span><span class="font-medium">{{ __('push_apns.key_ok') }}</span><span class="truncate text-ink-500" dir="ltr">{{ $apns['key_id'] }}</span></div>
                        @if ($canEdit)
                            <div class="flex gap-2">
                                <button type="button" class="btn-secondary min-h-11" wire:click="$set('replacingApnsKey', true)">{{ __('push_apns.key_replace') }}</button>
                                <button type="button" class="btn-ghost min-h-11 text-ruby-soft" wire:click="$set('confirmRemoveApnsKey', true)">{{ __('push_apns.key_remove') }}</button>
                            </div>
                        @endif
                    </div>
                @else
                    <textarea id="apnsKeyText" wire:model.live.debounce.600ms="apnsKeyText" rows="5" dir="ltr" spellcheck="false" autocomplete="off" autocapitalize="off"
                              class="input font-mono text-xs @error('apnsKeyText') input-error @enderror" placeholder="{{ __('push_apns.example.key') }}" @if ($errors->has('apnsKeyText')) aria-invalid="true" @endif></textarea>
                    <div class="mt-2 flex flex-wrap items-center gap-3">
                        <label class="btn-secondary min-h-11 cursor-pointer focus-within:ring-2 focus-within:ring-brass-500/40">
                            <x-icon name="upload" class="size-4" />{{ __('push_apns.key_upload') }}
                            <input type="file" accept=".p8,text/plain" class="sr-only"
                                   @change="const f = $event.target.files[0]; if (f) { f.text().then(t => $wire.set('apnsKeyText', t)); } $event.target.value = ''">
                        </label>
                        <span class="text-sm text-ink-500">{{ __('push_apns.key_or_paste') }}</span>
                        @if ($replacingApnsKey)<button type="button" class="btn-ghost min-h-11" wire:click="$set('replacingApnsKey', false)">{{ __('push_apns.key_cancel_replace') }}</button>@endif
                    </div>
                    <p class="field-hint">{{ __('push_apns.key_new_hint') }}</p>
                    @error('apnsKeyText')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                @endif
            </div>
        </fieldset>

        @if ($canEdit)
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-sand-100 bg-sand-50/50 px-5 py-4 sm:px-6 dark:border-ink-800 dark:bg-ink-800/30">
                <p class="text-xs text-ink-500">{{ __('org_admin.changes_audited') }}</p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-secondary min-h-11" wire:click="testApns" wire:loading.attr="disabled" wire:target="testApns" @disabled($apnsDirty || ! $apns['ready']) @if ($apnsDirty && $apns['ready']) title="{{ __('push_admin.save_first') }}" @endif>
                        <x-icon name="zap" class="size-4" /><span wire:loading.remove wire:target="testApns">{{ __('push_apns.test') }}</span><span wire:loading wire:target="testApns">{{ __('push_admin.testing') }}</span>
                    </button>
                    <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="saveApns" @disabled(! $apnsDirty)>
                        <x-icon name="check" class="size-4" /><span wire:loading.remove wire:target="saveApns">{{ __('push_admin.save') }}</span><span wire:loading wire:target="saveApns">{{ __('push_admin.saving') }}</span>
                    </button>
                </div>
            </div>
        @else
            <p class="border-t border-sand-100 px-5 py-4 text-sm text-ink-500 sm:px-6 dark:border-ink-800">{{ __('push_admin.edit_denied') }}</p>
        @endif

        @if ($apnsConnection)
            <div class="border-t border-sand-100 px-5 py-4 sm:px-6 dark:border-ink-800" role="status">
                <div class="flex items-start gap-3 text-sm {{ $apnsConnection['ok'] ? 'text-emerald-soft dark:text-emerald-300' : 'text-ruby-soft' }}">
                    <x-icon :name="$apnsConnection['ok'] ? 'check' : 'alert'" class="mt-0.5 size-4 shrink-0" />
                    <div class="min-w-0"><p class="font-medium">{{ $apnsConnection['ok'] ? __('push_apns.conn_ok') : __('push_apns.conn.'.$apnsConnection['code']) }}</p>
                        @if (! $apnsConnection['ok'] && $apnsConnection['detail'])<p class="mt-1 break-words text-xs text-ink-500" dir="ltr">{{ __('push_apns.conn_detail') }}: {{ $apnsConnection['detail'] }}</p>@endif</div>
                </div>
            </div>
        @endif
    </form>

    <x-admin.dialog variant="modal" wire:model="confirmRemoveApnsKey" :title="__('push_apns.key_remove_q')" max-width="max-w-md">
        <p class="text-sm text-ink-600 dark:text-ink-300">{{ __('push_apns.key_remove_body') }}</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn-secondary min-h-11" @click="requestClose()" x-init="$nextTick(() => $el.focus())">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary min-h-11 !bg-ruby-soft" wire:click="removeApnsKey">{{ __('push_apns.key_remove_yes') }}</button>
        </div>
    </x-admin.dialog>
</section>
