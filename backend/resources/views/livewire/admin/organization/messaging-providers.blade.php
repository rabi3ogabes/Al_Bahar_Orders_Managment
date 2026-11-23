@php
    $statusClass = ['delivered' => 'badge-success', 'sent' => 'badge-success', 'queued' => 'badge-neutral', 'failed' => 'badge-danger'];
@endphp
<div class="mx-auto max-w-3xl">
    <x-admin.page-header :title="__('messaging_providers.title')" :eyebrow="__('nav.organization')" :description="__('messaging_providers.hint')" />

    {{-- Where things stand, in one look. --}}
    <section class="mb-6 grid gap-3 sm:grid-cols-2" aria-label="{{ __('messaging_providers.status') }}">
        @foreach (['sms' => [$smsReady, $smsProvider, $smsEnv], 'whatsapp' => [$whatsappReady, $whatsappProvider, $whatsappMeta]] as $channel => [$ready, $chosen, $server])
            <div class="card card-pad">
                <p class="text-sm text-ink-500">{{ __('messaging_providers.channel_'.$channel) }}</p>
                <p class="mt-1 flex items-center gap-2 font-display text-lg font-semibold">
                    @if ($ready)<span class="badge-success">{{ __('messaging_providers.connected') }}</span> {{ __('messaging_providers.p.'.$chosen) }}
                    @elseif ($server)<span class="badge-success">{{ __('messaging_providers.connected') }}</span> {{ __('messaging_providers.via_server') }}
                    @else<span class="badge-warning">{{ __('messaging_providers.not_connected') }}</span>@endif
                </p>
            </div>
        @endforeach
    </section>

    {{-- The big risk, in red, for the owner. --}}
    <section class="card card-pad mb-6 {{ $codes['active'] ? 'border-ruby-soft/60' : '' }}" aria-labelledby="codes-title">
        <h2 id="codes-title" class="font-display text-xl font-semibold">{{ __('messaging_providers.codes_title') }}</h2>
        @if ($codes['active'])
            <p class="mt-2 rounded-xl bg-ruby-soft/10 p-3 text-sm font-medium text-ruby-soft" role="alert">{{ __('messaging_providers.codes_on_warning') }}</p>
            <p class="mt-3 text-sm text-ink-600 dark:text-ink-300">{{ $codes['proven'] ? __('messaging_providers.codes_ready') : __('messaging_providers.codes_not_ready') }}</p>
            @if ($isOwner && $canEdit)
                <button type="button" wire:click="askCode('off')" class="btn-danger mt-3 min-h-11" @disabled(! $codes['canOff'])><x-icon name="shield" class="size-4" />{{ __('messaging_providers.codes_turn_off') }}</button>
            @else
                <p class="mt-3 text-sm text-ink-500">{{ __('messaging_providers.codes_owner_only') }}</p>
            @endif
        @elseif ($codes['switchedOff'])
            <p class="mt-2 text-sm text-ink-600 dark:text-ink-300"><span class="badge-success">{{ __('messaging_providers.codes_off') }}</span> {{ __('messaging_providers.codes_off_hint') }}</p>
            @if ($isOwner && $canEdit && $codes['envSet'])<button type="button" wire:click="askCode('on')" class="btn-ghost mt-2 min-h-11 text-ruby-soft">{{ __('messaging_providers.codes_turn_on') }}</button>@endif
        @else
            <p class="mt-2 text-sm text-ink-600 dark:text-ink-300"><span class="badge-success">{{ __('messaging_providers.codes_off') }}</span> {{ $codes['smsEnv'] ? __('messaging_providers.codes_off_server') : __('messaging_providers.codes_never_set') }}</p>
        @endif
    </section>

    <form wire:submit="save" class="card card-pad mb-6 space-y-5" autocomplete="off">
        <div class="grid gap-4 sm:grid-cols-2">
            <x-admin.field :label="__('messaging_providers.channel_sms')" for="smsProvider">
                <select id="smsProvider" wire:model.live="smsProvider" class="input" @disabled(! $canEdit)>
                    <option value="none">{{ __('messaging_providers.p.none') }}</option>
                    @foreach ($presets as $key => $preset)@if (in_array('sms', $preset->channels(), true))<option value="{{ $key }}">{{ __('messaging_providers.p.'.$key) }}</option>@endif @endforeach
                </select>
            </x-admin.field>
            <x-admin.field :label="__('messaging_providers.channel_whatsapp')" for="whatsappProvider" :hint="$whatsappMeta && $whatsappProvider === 'none' ? __('messaging_providers.whatsapp_server_hint') : null">
                <select id="whatsappProvider" wire:model.live="whatsappProvider" class="input" @disabled(! $canEdit)>
                    <option value="none">{{ __('messaging_providers.p.none') }}</option>
                    <option value="twilio">{{ __('messaging_providers.p.twilio') }}</option>
                </select>
            </x-admin.field>
        </div>

        @foreach ($shown as $provider => $list)
            <fieldset class="space-y-4 rounded-2xl border border-sand-200 p-4 dark:border-ink-700" wire:key="prov-{{ $provider }}">
                <legend class="px-2 font-display text-lg font-semibold">{{ __('messaging_providers.p.'.$provider) }}</legend>
                @if ($presets[$provider]->consoleUrl() !== '')
                    <p class="text-sm text-ink-500">{{ __('messaging_providers.where_to_find') }} <a href="{{ $presets[$provider]->consoleUrl() }}" target="_blank" rel="noopener" class="text-brass-600 underline-offset-2 hover:underline">{{ $presets[$provider]->consoleUrl() }}</a></p>
                @endif
                <p class="text-sm text-ink-500">{{ __('messaging_providers.menu.'.$provider) }}</p>
                @foreach ($list as $f)
                    <x-admin.field :label="__('messaging_providers.f.'.$provider.'.'.$f['key'])" for="f-{{ $provider }}-{{ $f['key'] }}" :hint="__('messaging_providers.h.'.$provider.'.'.$f['key']).' '.__('messaging_providers.example', ['example' => $f['example']])">
                        <input id="f-{{ $provider }}-{{ $f['key'] }}" wire:model="fields.{{ $provider }}.{{ $f['key'] }}" class="input {{ $f['secret'] ? '' : 'font-mono' }}" dir="ltr" type="{{ $f['secret'] ? 'password' : 'text' }}"
                               autocomplete="off" placeholder="{{ $f['secret'] && in_array($f['key'], $saved[$provider] ?? [], true) ? __('messaging_providers.secret_saved') : $f['example'] }}" @disabled(! $canEdit)>
                        @error("fields.{$provider}.{$f['key']}")<p class="field-error mt-1 text-sm text-ruby-soft" role="alert">{{ $message }}</p>@enderror
                    </x-admin.field>
                @endforeach
            </fieldset>
        @endforeach

        @if ($canEdit)<button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="save">{{ __('admin.save') }}</button>@endif
    </form>

    <section class="card card-pad mb-6" aria-labelledby="test-title">
        <h2 id="test-title" class="font-display text-xl font-semibold">{{ __('messaging_providers.test_title') }}</h2>
        <p class="mt-1 text-sm text-ink-500">{{ __('messaging_providers.test_hint') }}</p>
        @if ($canEdit)
            <form wire:submit="sendTest" class="mt-4 grid gap-3 sm:grid-cols-[10rem_1fr_auto] sm:items-start">
                <select wire:model="testChannel" class="input" aria-label="{{ __('messaging_providers.channel') }}">
                    <option value="sms">{{ __('messaging_providers.channel_sms') }}</option>
                    <option value="whatsapp">{{ __('messaging_providers.channel_whatsapp') }}</option>
                </select>
                <div>
                    <input wire:model="testNumber" class="input" dir="ltr" inputmode="tel" placeholder="+974 5555 0123" aria-label="{{ __('messaging_providers.test_number') }}">
                    @error('testNumber')<p class="mt-1 text-sm text-ruby-soft" role="alert">{{ $message }}</p>@enderror
                </div>
                <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="sendTest">{{ __('messaging_providers.send_test') }}</button>
            </form>
        @endif
        <ul class="mt-4 divide-y divide-sand-100 dark:divide-ink-800">
            @forelse ($events as $e)
                <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center" wire:key="ev-{{ $e->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="{{ $statusClass[$e->status] ?? 'badge-neutral' }}">{{ __('messaging_providers.s.'.$e->status) }}</span>
                            <span class="font-medium">{{ __('messaging_providers.purpose.'.$e->purpose) }}</span>
                            <span class="text-ink-500">{{ __('messaging_providers.channel_'.$e->channel) }} · {{ __('messaging_providers.p.'.$e->provider) }} · <span dir="ltr">{{ $e->recipient }}</span></span>
                        </p>
                        <p class="mt-0.5 text-xs text-ink-500">{{ \App\Support\BusinessTime::display($e->created_at) }}
                            @if ($e->error_code) · <span class="text-ruby-soft" dir="auto">{{ $e->error_code }}@if ($e->error_detail): {{ $e->error_detail }}@endif</span>@endif
                            @if ($e->confirmed_at) · {{ __('messaging_providers.received') }}@endif</p>
                    </div>
                    @if ($e->purpose === 'test' && $canEdit)
                        <div class="flex flex-wrap gap-2">
                            @if ($e->status !== 'delivered' && $e->status !== 'failed')<button type="button" wire:click="refreshStatus('{{ $e->id }}')" class="btn-secondary btn-sm min-h-11">{{ __('messaging_providers.refresh') }}</button>@endif
                            @if (! $e->confirmed_at && $e->status !== 'failed')<button type="button" wire:click="confirmReceived('{{ $e->id }}')" class="btn-primary btn-sm min-h-11"><x-icon name="check" class="size-4" />{{ __('messaging_providers.i_got_it') }}</button>@endif
                        </div>
                    @endif
                </li>
            @empty
                <li class="py-4 text-sm text-ink-500">{{ __('messaging_providers.no_events') }}</li>
            @endforelse
        </ul>
    </section>

    <x-admin.dialog variant="modal" wire:model="showCode" :title="$codeAction === 'off' ? __('messaging_providers.codes_turn_off') : __('messaging_providers.codes_turn_on')" max-width="max-w-md">
        <form wire:submit="switchTestCodes" class="space-y-4">
            <p class="text-sm text-ink-600 dark:text-ink-300">{{ $codeAction === 'off' ? __('messaging_providers.codes_off_body') : __('messaging_providers.codes_on_body') }}</p>
            @if ($needsCode)
                <x-admin.field :label="__('org_admin.sec_code')" for="mp-code" required>
                    <input id="mp-code" wire:model="code" class="input font-mono" dir="ltr" inputmode="numeric" autocomplete="one-time-code" maxlength="32">
                    @error('code')<p class="mt-1 text-sm text-ruby-soft" role="alert">{{ $message }}</p>@enderror
                </x-admin.field>
            @endif
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary min-h-11" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="{{ $codeAction === 'off' ? 'btn-danger' : 'btn-primary' }} min-h-11" wire:loading.attr="disabled" wire:target="switchTestCodes">{{ __('org_admin.sec_confirm') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
