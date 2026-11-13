@php
    $tabIcons = ['general' => 'sparkles', 'checkout' => 'cart', 'loyalty' => 'gift', 'auctions' => 'gavel', 'notifications' => 'bell', 'integrations' => 'key'];
    $tabs = collect($schema)->keys()->mapWithKeys(fn ($g) => [$g => [$tabIcons[$g], __('org_admin.settings_tab.'.$g)]])->all();
@endphp
<div class="mx-auto max-w-5xl">
    <x-admin.page-header :title="__('nav.settings')" :eyebrow="__('nav.organization')" :description="__('org_admin.settings_hint')" />
    <x-admin.tabs :tabs="$tabs" :current="$tab" />

    <form wire:submit="save" class="card overflow-hidden" wire:key="settings-{{ $tab }}">
        <div class="border-b border-sand-100 p-5 sm:p-6 dark:border-ink-800">
            <h2 class="font-display text-xl font-semibold">{{ __('org_admin.settings_tab.'.$tab) }}</h2>
            <p class="mt-1 text-sm text-ink-500">{{ __('org_admin.settings_tab_hint.'.$tab) }}</p>
        </div>
        <fieldset class="divide-y divide-sand-100 dark:divide-ink-800" @disabled(! $canEdit)>
            @foreach ($schema[$tab] as $key => $def)
                @php $f = \App\Livewire\Admin\Organization\SettingsIndex::field($key); $path = "values.{$tab}.{$f}"; $default = config('platform.'.$key); @endphp
                <div class="grid gap-3 px-5 py-5 sm:grid-cols-5 sm:items-center sm:gap-6 sm:px-6" wire:key="s-{{ $f }}">
                    <div class="sm:col-span-2">
                        <label for="{{ $f }}" class="text-sm font-medium text-ink-800 dark:text-sand-100">{{ __('org_admin.setting.'.$f) }}</label>
                        @if (\Illuminate\Support\Facades\Lang::has('org_admin.setting_hint.'.$f))<p class="mt-0.5 text-xs leading-relaxed text-ink-500">{{ __('org_admin.setting_hint.'.$f) }}</p>@endif
                    </div>
                    <div class="sm:col-span-3">
                        @switch($def['type'])
                            @case('bool')
                                <label class="inline-flex items-center gap-3 text-sm"><input id="{{ $f }}" type="checkbox" wire:model="{{ $path }}" class="checkbox">{{ __('admin.enabled') }}</label>
                                @break
                            @case('select')
                                <select id="{{ $f }}" wire:model="{{ $path }}" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:max-w-xs">
                                    @foreach ($def['options'] as $o)<option value="{{ $o }}">{{ __('org_admin.option.'.$o) }}</option>@endforeach
                                </select>
                                @break
                            @case('secret')
                                <div class="space-y-2">
                                    <div class="flex items-center gap-2 text-xs text-ink-500">
                                        <x-icon name="lock" class="size-3.5" />
                                        @if ($masked[$f])<span class="font-mono" dir="ltr">{{ $masked[$f] }}</span>@else<span>{{ __('org_admin.secret_not_set') }}</span>@endif
                                    </div>
                                    <input id="{{ $f }}" type="password" wire:model="secrets.{{ $f }}" class="input font-mono sm:max-w-md" dir="ltr" autocomplete="new-password" placeholder="{{ __('org_admin.secret_placeholder') }}">
                                </div>
                                @php $path = 'secrets.'.$f; @endphp
                                @break
                            @default
                                <div class="relative sm:max-w-xs" @if (in_array($def['type'], ['money', 'int', 'percent'])) dir="ltr" @endif>
                                    @if ($def['type'] === 'money')<span class="pointer-events-none absolute top-1/2 start-3.5 -translate-y-1/2 text-xs text-ink-400">QAR</span>@endif
                                    <input id="{{ $f }}" wire:model="{{ $path }}" class="input {{ $def['type'] === 'money' ? 'ps-12' : '' }} {{ isset($def['suffix']) || $def['type'] === 'percent' ? 'pe-16' : '' }} {{ in_array($def['type'], ['money', 'int', 'percent']) ? 'tabular-nums' : '' }}"
                                           @if (in_array($def['type'], ['money', 'int', 'percent'])) inputmode="decimal" dir="ltr" @elseif (isset($def['dir'])) dir="{{ $def['dir'] }}" @endif>
                                    @if (isset($def['suffix']) || $def['type'] === 'percent')<span class="pointer-events-none absolute top-1/2 end-3.5 -translate-y-1/2 text-xs text-ink-400">{{ $def['type'] === 'percent' ? '%' : __('org_admin.unit.'.$def['suffix']) }}</span>@endif
                                </div>
                        @endswitch
                        @error($path)<p class="field-error" role="alert">{{ $message }}</p>@enderror
                        @if ($def['type'] !== 'secret' && $default !== null && ! is_array($default))
                            <p class="mt-1.5 text-xs text-ink-400">{{ __('org_admin.default_value', ['value' => is_bool($default) ? ($default ? __('admin.yes') : __('admin.no')) : $default]) }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </fieldset>
        @if ($canEdit)
            <div class="flex items-center justify-between gap-4 border-t border-sand-100 bg-sand-50/50 px-5 py-4 sm:px-6 dark:border-ink-800 dark:bg-ink-800/30">
                <p class="text-xs text-ink-500">{{ __('org_admin.changes_audited') }}</p>
                <div class="flex items-center gap-2">
                    @if ($tab === 'integrations')
                        <button type="button" class="btn-secondary" wire:click="testTiles" wire:loading.attr="disabled" wire:target="testTiles"><x-icon name="map" class="size-4" />{{ __('org_admin.map_test.button') }}</button>
                    @endif
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save"><x-icon name="check" class="size-4" />{{ __('admin.save_changes') }}</button>
                </div>
            </div>
        @endif
    </form>
</div>
