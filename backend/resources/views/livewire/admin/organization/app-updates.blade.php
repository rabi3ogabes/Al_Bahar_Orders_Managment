<div>
    <x-admin.page-header :title="__('app_updates_admin.title')" :eyebrow="__('nav.organization')" :description="__('app_updates_admin.hint')" />

    <div class="mb-6 rounded-2xl bg-brass-500/10 px-5 py-4 text-sm text-ink-700 dark:text-sand-100">
        <p class="font-medium">{{ __('app_updates_admin.how_title') }}</p>
        <ol class="mt-1 list-decimal space-y-0.5 ps-5 text-ink-600 dark:text-ink-300">
            <li>{{ __('app_updates_admin.how_1') }}</li><li>{{ __('app_updates_admin.how_2') }}</li><li>{{ __('app_updates_admin.how_3') }}</li>
        </ol>
    </div>

    @foreach ($releases as $app => $rows)
        <section class="mb-8" aria-labelledby="app-{{ $app }}">
            <h2 id="app-{{ $app }}" class="mb-3 flex items-center gap-2 font-display text-xl font-semibold"><x-icon :name="$app === 'customer' ? 'bag' : ($app === 'driver' ? 'truck' : 'store')" class="size-5 text-brass-500" />{{ __('app_updates_admin.apps.'.$app) }}</h2>
            <div class="grid gap-4 xl:grid-cols-2 [&>*]:min-w-0">
                @foreach ($rows as $r)
                    @php $f = "forms.{$r->id}"; @endphp
                    <form wire:submit="save('{{ $r->id }}')" wire:key="rel-{{ $r->id }}" class="card card-pad space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="flex items-center gap-2 font-medium"><x-icon :name="$r->platform === 'ios' ? 'apple' : 'android'" class="size-4 text-ink-500" />{{ __('app_updates_admin.platforms.'.$r->platform) }}</p>
                            @if ($r->min_build > 0)
                                <span class="badge-danger">{{ __('app_updates_admin.forcing', ['build' => $r->min_build]) }}</span>
                            @elseif ($r->latest_build > 0)
                                <span class="badge-success">{{ __('app_updates_admin.optional') }}</span>
                            @else
                                <span class="badge-neutral">{{ __('app_updates_admin.not_set') }}</span>
                            @endif
                        </div>
                        <div class="grid gap-4 sm:grid-cols-3">
                            <x-admin.field :label="__('app_updates_admin.latest_version')" :for="$f.'.latest_version'"><input wire:model="{{ $f }}.latest_version" class="input" dir="ltr" placeholder="1.0.0" @disabled(! $canEdit)></x-admin.field>
                            <x-admin.field :label="__('app_updates_admin.latest_build')" :for="$f.'.latest_build'" :hint="__('app_updates_admin.latest_build_hint')"><input wire:model="{{ $f }}.latest_build" class="input" dir="ltr" inputmode="numeric" @disabled(! $canEdit)></x-admin.field>
                            <x-admin.field :label="__('app_updates_admin.min_build')" :for="$f.'.min_build'" :hint="__('app_updates_admin.min_build_hint')"><input wire:model="{{ $f }}.min_build" class="input" dir="ltr" inputmode="numeric" @disabled(! $canEdit)></x-admin.field>
                        </div>
                        <x-admin.field :label="__('app_updates_admin.store_url')" :for="$f.'.store_url'" :hint="$r->platform === 'ios' ? __('app_updates_admin.store_hint_ios') : __('app_updates_admin.store_hint_android')"><input wire:model="{{ $f }}.store_url" class="input" dir="ltr" placeholder="https://" @disabled(! $canEdit)></x-admin.field>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-admin.field :label="__('app_updates_admin.message_en')" :for="$f.'.message_en'"><textarea wire:model="{{ $f }}.message_en" rows="2" class="input" maxlength="400" placeholder="{{ __('app_updates_admin.message_ph_en') }}" @disabled(! $canEdit)></textarea></x-admin.field>
                            <x-admin.field :label="__('app_updates_admin.message_ar')" :for="$f.'.message_ar'"><textarea wire:model="{{ $f }}.message_ar" rows="2" class="input" dir="rtl" lang="ar" maxlength="400" placeholder="{{ __('app_updates_admin.message_ph_ar') }}" @disabled(! $canEdit)></textarea></x-admin.field>
                        </div>
                        @if ($canEdit)
                            <div class="flex flex-wrap items-center gap-2 border-t border-sand-100 pt-4 dark:border-ink-800">
                                <button class="btn-secondary min-h-11" wire:loading.attr="disabled" wire:target="save('{{ $r->id }}')">{{ __('app_updates_admin.save') }}</button>
                                @if ($r->min_build > 0)
                                    <button type="button" wire:click="stop('{{ $r->id }}')" class="btn-ghost min-h-11">{{ __('app_updates_admin.stop') }}</button>
                                @else
                                    <button type="button" wire:click="askForce('{{ $r->id }}')" class="btn-primary min-h-11 !bg-ruby-soft"><x-icon name="alert" class="size-4" />{{ __('app_updates_admin.force') }}</button>
                                @endif
                                @if ($r->forced_at)<span class="ms-auto text-xs text-ink-500">{{ __('app_updates_admin.forced_at', ['at' => \App\Support\BusinessTime::display($r->forced_at)]) }}</span>@endif
                            </div>
                        @endif
                    </form>
                @endforeach
            </div>
        </section>
    @endforeach

    <x-admin.dialog variant="modal" wire:model="confirmForce" :title="__('app_updates_admin.force_q')" max-width="max-w-md">
        @if ($forcing)
            <p class="text-sm text-ink-600 dark:text-ink-300">{{ __('app_updates_admin.force_body', ['app' => __('app_updates_admin.apps.'.$forcing->app), 'platform' => __('app_updates_admin.platforms.'.$forcing->platform), 'build' => $forcing->latest_build]) }}</p>
        @endif
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn-secondary min-h-11" @click="requestClose()" x-init="$nextTick(() => $el.focus())">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary min-h-11 !bg-ruby-soft" wire:click="force">{{ __('app_updates_admin.force_yes') }}</button>
        </div>
    </x-admin.dialog>
</div>
