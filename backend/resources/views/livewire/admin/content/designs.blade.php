@php
    $loc = app()->getLocale();
    $name = fn ($t) => $loc === 'ar' ? ($t->name_ar ?: $t->name_en) : $t->name_en;
@endphp
<div>
    <x-admin.page-header :title="__('design_admin.title')" :eyebrow="__('nav.content')" :description="__('design_admin.hint')">
        <x-slot:actions><a href="{{ route('admin.appearance.index') }}" class="btn-secondary min-h-11" wire:navigate><x-icon name="sparkles" class="size-4" />{{ __('nav.appearance') }}</a></x-slot:actions>
    </x-admin.page-header>

    @if ($eventLook)
        <div class="card mb-6 flex flex-wrap items-center gap-3 border-brass-300 bg-brass-50 p-4 dark:bg-ink-800" role="note">
            <x-icon name="sparkles" class="size-5 shrink-0 text-brass-600" />
            <p class="min-w-0 flex-1 text-sm text-ink-700 dark:text-sand-100">{{ __('design_admin.event_note', ['name' => $name($eventLook)]) }}</p>
            <a href="{{ route('admin.appearance.index') }}" class="btn-secondary min-h-11" wire:navigate>{{ __('design_admin.event_link') }}</a>
        </div>
    @endif

    <ul class="grid gap-4 md:grid-cols-2 [&>*]:min-w-0">
        @forelse ($designs as $d)
            <li class="card flex flex-col gap-4 overflow-hidden p-4 sm:p-5 {{ $d->is_default ? 'ring-2 ring-brass-500' : '' }}" wire:key="design-{{ $d->id }}">
                <div class="rounded-xl bg-sand-100 p-3 dark:bg-ink-900">
                    <x-admin.design-mock :theme="$d" />
                    <p class="mt-2 flex justify-between px-1 text-[11px] text-ink-500"><span>{{ __('design_admin.web') }}</span><span class="w-1/3 text-center">{{ __('design_admin.phone') }}</span></p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-display text-lg font-semibold text-ink-900 dark:text-ivory">{{ $name($d) }}</h2>
                    @if ($d->is_default)<span class="badge-success"><x-icon name="check" class="size-3" />{{ __('design_admin.current') }}</span>@endif
                </div>
                <div class="flex flex-wrap gap-1.5 text-xs">
                    @php $tk = \App\Domain\Cms\Models\BrandTheme::normalizeTokens($d->tokens); @endphp
                    <span class="badge-neutral">{{ __('design_admin.layout_'.$d->layout) }}</span>
                    <span class="badge-neutral">{{ __('design_admin.radius_'.$tk['radius']) }}</span>
                    <span class="badge-neutral">{{ __('design_admin.nav_'.$tk['nav']) }}</span>
                </div>
                <div class="flex gap-1.5" aria-hidden="true">@foreach (\App\Domain\Cms\Models\BrandTheme::COLOR_KEYS as $k)<i class="size-6 rounded-full ring-1 ring-black/10" style="background: {{ $d->colors[$k] }}"></i>@endforeach</div>
                <div class="mt-auto flex flex-wrap gap-2">
                    <a href="{{ $storeUrl }}/?preview_design={{ $d->key }}" target="_blank" rel="noopener" class="btn-secondary min-h-11"><x-icon name="eye" class="size-4" />{{ __('design_admin.preview') }}</a>
                    @if ($canEdit && ! $d->is_default)
                        <button type="button" wire:click="ask('{{ $d->id }}')" class="btn-primary min-h-11"><x-icon name="check" class="size-4" />{{ __('design_admin.use') }}</button>
                    @endif
                </div>
            </li>
        @empty
            <li class="card p-6 text-center text-ink-500 md:col-span-2">{{ __('design_admin.empty') }}</li>
        @endforelse
    </ul>

    <x-admin.dialog variant="modal" wire:model="showConfirm" :title="$pending ? __('design_admin.confirm_title', ['name' => $name($pending)]) : ''" max-width="max-w-md">
        <p class="text-sm text-ink-600 dark:text-sand-200">{{ __('design_admin.confirm_body') }}</p>
        <div class="mt-6 flex flex-wrap justify-end gap-2">
            <button type="button" class="btn-secondary min-h-11" @click="requestClose()">{{ __('admin.cancel') }}</button>
            <button type="button" wire:click="apply" wire:loading.attr="disabled" class="btn-primary min-h-11">{{ __('design_admin.switch') }}</button>
        </div>
    </x-admin.dialog>
</div>
