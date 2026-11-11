@php
    use App\Livewire\Admin\Content\ThemeIndex;
    use App\Domain\Cms\Models\BrandTheme;
    $loc = app()->getLocale();
    $name = fn ($t) => $loc === 'ar' ? ($t->name_ar ?: $t->name_en) : $t->name_en;
    $why = $forced ? __('theme_admin.why_forced') : ($active && $active->kind === 'event' ? __('theme_admin.why_event') : __('theme_admin.why_default'));
    $c = fn ($key) => $form['colors'][$key] ?? '#8A1538';
@endphp
<div>
    <x-admin.page-header :title="__('nav.appearance')" :eyebrow="__('nav.content')" :description="__('theme_admin.hint')">
        <x-slot:actions>@if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('theme_admin.new') }}</button>@endif</x-slot:actions>
    </x-admin.page-header>

    @if ($active)
        <div class="card mb-6 overflow-hidden">
            <div class="flex flex-wrap items-center gap-4 p-4 sm:p-6">
                <span class="flex h-14 w-14 shrink-0 overflow-hidden rounded-2xl ring-1 ring-black/5">
                    @foreach (['primary', 'accent'] as $k)<span class="h-full w-1/2" style="background: {{ $active->colors[$k] }}"></span>@endforeach
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium uppercase tracking-wide text-ink-500">{{ __('theme_admin.showing_now') }}</p>
                    <p class="truncate font-display text-xl font-semibold text-ink-900 dark:text-ivory">{{ $name($active) }}</p>
                    <p class="text-sm text-ink-500">{{ $why }}</p>
                </div>
                @if ($forced && $canEdit)<button type="button" wire:click="backToSchedule" class="btn-secondary"><x-icon name="clock" class="size-4" />{{ __('theme_admin.back_to_schedule') }}</button>@endif
            </div>
        </div>
    @endif

    <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 [&>*]:min-w-0">
        @foreach ($themes as $t)
            @php
                $live = $active && $active->id === $t->id;
                $window = $t->recur_start ? \Illuminate\Support\Carbon::createFromFormat('m-d', $t->recur_start)->translatedFormat('j M').' – '.\Illuminate\Support\Carbon::createFromFormat('m-d', $t->recur_end)->translatedFormat('j M').' · '.__('theme_admin.every_year')
                    : (($t->starts_at || $t->ends_at) ? \App\Support\BusinessTime::display($t->starts_at, 'j M Y').' – '.\App\Support\BusinessTime::display($t->ends_at, 'j M Y') : null);
            @endphp
            <li class="card overflow-hidden {{ $live ? 'ring-2 ring-brass-500' : '' }}" wire:key="th-{{ $t->id }}">
                <div class="relative h-28" style="background: linear-gradient(135deg, {{ $t->colors['primary'] }}, {{ $t->colors['primary_soft'] }})">
                    @if ($t->hero_image_path)<img src="{{ ThemeIndex::mediaUrl($t->hero_image_path) }}" alt="" class="absolute inset-0 size-full object-cover opacity-80" loading="lazy">@endif
                    <div class="absolute inset-x-0 bottom-0 flex items-end justify-between p-3">
                        <span class="rounded-full px-3 py-1 text-xs font-semibold text-white" style="background: {{ $t->colors['accent'] }}">{{ $t->localized('banner_title') ?: $name($t) }}</span>
                        <span class="flex gap-1">@foreach (BrandTheme::COLOR_KEYS as $k)<i class="size-4 rounded-full ring-2 ring-white/80" style="background: {{ $t->colors[$k] }}"></i>@endforeach</span>
                    </div>
                    <span class="absolute top-2 start-2 flex gap-1">
                        @if ($live)<span class="badge-success bg-white/95">{{ __('theme_admin.showing_now') }}</span>@endif
                        @if ($t->is_default)<span class="badge-neutral bg-white/95">{{ __('theme_admin.default') }}</span>@endif
                        @if ($t->is_forced)<span class="badge-warning bg-white/95">{{ __('theme_admin.manual') }}</span>@endif
                        @if (! $t->is_enabled && ! $t->is_default)<span class="badge-neutral bg-white/95">{{ __('theme_admin.off') }}</span>@endif
                    </span>
                </div>
                <div class="p-4">
                    <p class="truncate font-medium text-ink-900 dark:text-ivory">{{ $name($t) }}</p>
                    <p class="mt-0.5 text-xs text-ink-500">{{ __('theme_admin.kind_'.$t->kind) }}@if ($window) · {{ $window }}@endif</p>
                    <div class="mt-3 flex flex-wrap items-center gap-1">
                        @if ($canEdit)
                            @if (! $live || ! $forced)<button type="button" wire:click="activate('{{ $t->id }}')" class="btn-primary btn-sm">{{ __('theme_admin.activate_now') }}</button>@endif
                            @if (! $t->is_default)
                                <button type="button" wire:click="toggle('{{ $t->id }}')" class="btn-ghost btn-sm">{{ $t->is_enabled ? __('theme_admin.turn_off') : __('theme_admin.turn_on') }}</button>
                                <button type="button" wire:click="makeDefault('{{ $t->id }}')" class="btn-ghost btn-sm">{{ __('theme_admin.make_default') }}</button>
                            @endif
                            <button type="button" wire:click="edit('{{ $t->id }}')" class="btn-ghost btn-sm ms-auto px-2" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
                        @endif
                        @if ($canDelete && ! $t->is_default && $t->kind !== 'base')<button type="button" wire:click="delete('{{ $t->id }}')" wire:confirm="{{ __('admin.confirm_delete') }}" class="btn-ghost btn-sm px-2 text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('theme_admin.edit') : __('theme_admin.new')" max-width="max-w-4xl">
        <form wire:submit="save" class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('theme_admin.name_en')" for="form.name_en"><input id="form.name_en" wire:model="form.name_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('theme_admin.name_ar')" for="form.name_ar"><input id="form.name_ar" wire:model="form.name_ar" class="input" dir="rtl"></x-admin.field>
            </div>

            <div class="grid gap-5 lg:grid-cols-[1fr_16rem]">
                <div>
                    <h3 class="mb-2 text-sm font-semibold">{{ __('theme_admin.colors') }}</h3>
                    <div class="grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
                        @foreach (BrandTheme::COLOR_KEYS as $k)
                            <label class="flex items-center gap-3 rounded-xl border border-sand-200/70 p-2.5 dark:border-ink-700">
                                <input type="color" wire:model.live.debounce.250ms="form.colors.{{ $k }}" class="size-10 shrink-0 cursor-pointer rounded-lg border-0 bg-transparent p-0" aria-label="{{ __('theme_admin.color_'.$k) }}">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">{{ __('theme_admin.color_'.$k) }}</span>
                                    <input wire:model.live.debounce.400ms="form.colors.{{ $k }}" class="w-full bg-transparent font-mono text-xs uppercase text-ink-500 outline-none" dir="ltr" maxlength="7">
                                </span>
                            </label>
                            @error('form.colors.'.$k)<p class="field-error sm:col-span-2" role="alert">{{ $message }}</p>@enderror
                        @endforeach
                    </div>
                </div>
                {{-- Live preview of a phone home screen --}}
                <div class="mx-auto w-56 overflow-hidden rounded-[28px] border border-sand-200 shadow-soft" style="background: {{ $c('background') }}">
                    <div class="px-4 pb-6 pt-5" style="background: linear-gradient(135deg, {{ $c('primary') }}, {{ $c('primary_soft') }})">
                        <p class="text-xs text-white/80">{{ __('theme_admin.preview_hello') }}</p>
                        <p class="text-lg font-bold text-white">Al Bahar</p>
                        @if (($form['banner_title_'.$loc] ?? '') || ($form['banner_title_en'] ?? ''))<p class="mt-2 rounded-xl bg-white/15 px-2 py-1.5 text-[11px] font-semibold text-white">{{ ($form['banner_title_'.$loc] ?? '') ?: $form['banner_title_en'] }}</p>@endif
                    </div>
                    <div class="-mt-3 space-y-2 px-3 pb-4">
                        <div class="rounded-2xl p-3 shadow-sm" style="background: {{ $c('surface') }}">
                            <div class="h-14 rounded-xl" style="background: {{ $c('background') }}"></div>
                            <p class="mt-2 text-xs font-semibold" style="color: {{ $c('ink') }}">{{ __('theme_admin.preview_product') }}</p>
                            <p class="text-xs font-bold" style="color: {{ $c('primary') }}">QAR 45.00</p>
                        </div>
                        <span class="flex h-9 items-center justify-center rounded-xl text-xs font-semibold text-white" style="background: {{ $c('primary') }}">{{ __('theme_admin.preview_button') }}</span>
                        <span class="flex h-9 items-center justify-center rounded-xl text-xs font-semibold text-white" style="background: {{ $c('accent') }}">{{ __('theme_admin.preview_accent') }}</span>
                    </div>
                </div>
            </div>

            <div>
                <h3 class="mb-2 text-sm font-semibold">{{ __('theme_admin.images') }}</h3>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 [&>*]:min-w-0">
                    <x-admin.image-input model="heroImage" :file="$heroImage" :current="ThemeIndex::mediaUrl($current?->hero_image_path)" :label="__('theme_admin.hero')" aspect="aspect-[4/3]" :hint="__('theme_admin.hero_hint')" />
                    <x-admin.image-input model="bannerImage" :file="$bannerImage" :current="ThemeIndex::mediaUrl($current?->banner_image_path)" :label="__('theme_admin.banner')" aspect="aspect-[16/7]" :hint="__('theme_admin.banner_hint')" />
                    <x-admin.image-input model="logoImage" :file="$logoImage" :current="ThemeIndex::mediaUrl($current?->logo_path)" :label="__('theme_admin.logo')" aspect="aspect-square" :hint="__('theme_admin.logo_hint')" />
                    <x-admin.image-input model="splashImage" :file="$splashImage" :current="ThemeIndex::mediaUrl($current?->splash_image_path)" :label="__('theme_admin.splash')" aspect="aspect-[9/16]" :hint="__('theme_admin.splash_hint')" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                <x-admin.field :label="__('theme_admin.banner_title_en')" for="form.banner_title_en"><input id="form.banner_title_en" wire:model.live.debounce.400ms="form.banner_title_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('theme_admin.banner_title_ar')" for="form.banner_title_ar"><input id="form.banner_title_ar" wire:model.live.debounce.400ms="form.banner_title_ar" class="input" dir="rtl"></x-admin.field>
                <x-admin.field :label="__('theme_admin.banner_subtitle_en')" for="form.banner_subtitle_en"><input id="form.banner_subtitle_en" wire:model="form.banner_subtitle_en" class="input" dir="ltr"></x-admin.field>
                <x-admin.field :label="__('theme_admin.banner_subtitle_ar')" for="form.banner_subtitle_ar"><input id="form.banner_subtitle_ar" wire:model="form.banner_subtitle_ar" class="input" dir="rtl"></x-admin.field>
            </div>

            <div class="rounded-2xl border border-sand-200/70 p-4 dark:border-ink-700">
                <h3 class="mb-3 text-sm font-semibold">{{ __('theme_admin.schedule') }}</h3>
                <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                    <x-admin.field :label="__('theme_admin.schedule_type')" for="form.schedule">
                        <select id="form.schedule" wire:model.live="form.schedule" class="input">
                            <option value="none">{{ __('theme_admin.schedule_none') }}</option>
                            <option value="yearly">{{ __('theme_admin.schedule_yearly') }}</option>
                            <option value="once">{{ __('theme_admin.schedule_once') }}</option>
                        </select>
                    </x-admin.field>
                    @if (($form['schedule'] ?? 'none') === 'yearly')
                        <x-admin.field :label="__('theme_admin.recur_start')" for="form.recur_start" :hint="__('theme_admin.recur_hint')"><input id="form.recur_start" wire:model="form.recur_start" class="input tabular-nums" placeholder="12-14" maxlength="5" dir="ltr"></x-admin.field>
                        <x-admin.field :label="__('theme_admin.recur_end')" for="form.recur_end"><input id="form.recur_end" wire:model="form.recur_end" class="input tabular-nums" placeholder="12-19" maxlength="5" dir="ltr"></x-admin.field>
                    @elseif (($form['schedule'] ?? 'none') === 'once')
                        <x-admin.field :label="__('theme_admin.starts_at')" for="form.starts_at"><input id="form.starts_at" type="datetime-local" wire:model="form.starts_at" class="input"></x-admin.field>
                        <x-admin.field :label="__('theme_admin.ends_at')" for="form.ends_at"><input id="form.ends_at" type="datetime-local" wire:model="form.ends_at" class="input"></x-admin.field>
                    @endif
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                    <x-admin.field :label="__('theme_admin.priority')" for="form.priority" :hint="__('theme_admin.priority_hint')"><input id="form.priority" type="number" min="1" max="1000" wire:model="form.priority" class="input tabular-nums"></x-admin.field>
                    <label class="flex items-center justify-between gap-4 rounded-xl border border-sand-100 px-4 py-3 dark:border-ink-800"><span class="text-sm">{{ __('theme_admin.takes_part') }}</span><input type="checkbox" wire:model="form.is_enabled" class="checkbox"></label>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save,heroImage,bannerImage,logoImage,splashImage"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
