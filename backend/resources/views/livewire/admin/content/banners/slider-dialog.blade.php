@php use App\Domain\Cms\Support\SliderSettings; @endphp
<x-admin.dialog wire:model="showSlider" :title="$sliderId ? __('banners_admin.slider_settings') : __('banners_admin.add_slider')" :description="$sliderId ? null : __('banners_admin.add_slider_hint')" max-width="max-w-2xl">
    <form wire:submit="saveSlider" class="space-y-6" wire:key="slider-form-{{ $sliderId ?? 'new' }}">
        @unless ($sliderId)
            <x-admin.field :label="__('banners_admin.where')" for="slider.at" :hint="__('banners_admin.where_hint')">
                <select id="slider.at" wire:model="slider.at" class="input">
                    <option value="top">{{ __('banners_admin.pos_top') }}</option>
                    @foreach ($positions as $p)<option value="after:{{ $p['id'] }}">{{ __('banners_admin.pos_after', ['name' => $p['name']]) }}</option>@endforeach
                    <option value="bottom">{{ __('banners_admin.pos_bottom') }}</option>
                </select>
            </x-admin.field>
        @endunless

        <x-admin.field :label="__('banners_admin.slider_name')" for="slider.name" :hint="__('banners_admin.slider_name_hint')">
            <input id="slider.name" wire:model="slider.name" class="input" maxlength="80" placeholder="{{ __('banners_admin.slider_name_ph') }}">
        </x-admin.field>

        <fieldset>
            <legend class="field-label">{{ __('banners_admin.aspect') }}</legend>
            <div class="grid grid-cols-5 gap-2" role="radiogroup">
                @foreach (SliderSettings::ASPECTS as $a)
                    @php [$w, $h] = array_map('intval', explode(':', $a)); @endphp
                    <label class="relative cursor-pointer">
                        <input type="radio" wire:model.live="slider.aspect" value="{{ $a }}" class="peer sr-only">
                        <span class="flex flex-col items-center gap-1.5 rounded-xl border border-sand-200 p-2.5 text-center text-xs text-ink-600 transition peer-checked:border-brass-500 peer-checked:bg-brass-50 peer-checked:text-ink-900 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brass-500 dark:border-ink-700 dark:text-ink-300 dark:peer-checked:bg-brass-500/10 dark:peer-checked:text-ivory">
                            <span class="flex h-9 w-full items-center justify-center"><span class="rounded-[3px] border-2 border-current/60 bg-current/10" style="aspect-ratio: {{ $w }} / {{ $h }}; {{ $w >= $h ? 'width:100%;max-width:2.75rem' : 'height:100%' }}"></span></span>
                            <span class="font-medium tabular-nums">{{ $a }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @php [$rw, $rh] = SliderSettings::recommended($slider['aspect'] ?? '16:9'); @endphp
            <p class="field-hint">{{ __('banners_admin.aspect_hint', ['size' => $rw.' × '.$rh]) }}</p>
        </fieldset>

        <div class="space-y-3" x-data="{ auto: @js((bool) ($slider['autoplay'] ?? true)) }" @change="if ($event.target.name === 'autoplay') auto = $event.target.checked">
            <x-admin.toggle name="autoplay" wire:model="slider.autoplay" :label="__('banners_admin.autoplay')" :hint="__('banners_admin.autoplay_hint')" />
            <div x-show="auto" x-cloak class="rounded-xl border border-sand-100 px-4 py-3 dark:border-ink-800">
                <label for="slider.interval" class="flex items-center justify-between text-sm text-ink-800 dark:text-sand-100">{{ __('banners_admin.interval') }}<span class="tabular-nums text-ink-500">{{ __('banners_admin.seconds', ['n' => (int) ($slider['interval'] ?? 5)]) }}</span></label>
                <input id="slider.interval" type="range" min="{{ SliderSettings::MIN_INTERVAL }}" max="{{ SliderSettings::MAX_INTERVAL }}" step="1" wire:model.live.debounce.150ms="slider.interval" class="mt-2 w-full accent-[var(--color-brass-500)]">
                @error('slider.interval')<p class="field-error" role="alert">{{ $message }}</p>@enderror
            </div>
            <x-admin.toggle wire:model="slider.dots" :label="__('banners_admin.dots')" :hint="__('banners_admin.dots_hint')" />
            <x-admin.toggle wire:model="slider.show_titles" :label="__('banners_admin.show_titles')" :hint="__('banners_admin.show_titles_hint')" />
            <x-admin.toggle wire:model="slider.rounded" :label="__('banners_admin.rounded')" :hint="__('banners_admin.rounded_hint')" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
            <x-admin.field :label="__('banners_admin.heading_en')" for="slider.title_en" :hint="__('banners_admin.heading_hint')"><input id="slider.title_en" wire:model="slider.title_en" class="input" dir="ltr" maxlength="120"></x-admin.field>
            <x-admin.field :label="__('banners_admin.heading_ar')" for="slider.title_ar"><input id="slider.title_ar" wire:model="slider.title_ar" class="input" dir="rtl" maxlength="120"></x-admin.field>
        </div>

        <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
            <x-admin.field :label="__('admin.status')" for="slider.status"><select id="slider.status" wire:model="slider.status" class="input"><option value="published">{{ __('banners_admin.status_published') }}</option><option value="draft">{{ __('banners_admin.status_hidden') }}</option></select></x-admin.field>
            <x-admin.field :label="__('content_admin.starts_at')" for="slider.starts_at"><input id="slider.starts_at" type="datetime-local" wire:model="slider.starts_at" class="input"></x-admin.field>
            <x-admin.field :label="__('content_admin.ends_at')" for="slider.ends_at"><input id="slider.ends_at" type="datetime-local" wire:model="slider.ends_at" class="input"></x-admin.field>
        </div>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
            <button class="btn-primary" wire:loading.attr="disabled" wire:target="saveSlider"><x-icon name="check" class="size-4" />{{ $sliderId ? __('banners_admin.save_slider') : __('banners_admin.create_slider') }}</button>
        </div>
    </form>
</x-admin.dialog>
