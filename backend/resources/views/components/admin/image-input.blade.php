@props(['model', 'file' => null, 'current' => null, 'label' => null, 'hint' => null, 'aspect' => 'aspect-square', 'class' => ''])
{{-- Image upload with live preview. $file = the Livewire temporary upload, $current = existing URL. --}}
<div class="{{ $class }}">
    @if ($label)<span class="field-label">{{ $label }}</span>@endif
    <label class="group relative flex cursor-pointer flex-col items-center justify-center overflow-hidden rounded-xl border border-dashed border-sand-300 bg-sand-50/60 text-center transition hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800/40 {{ $aspect }}"
           x-data="{ uploading: false, progress: 0 }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress">
        @php $preview = $file && method_exists($file, 'isPreviewable') && $file->isPreviewable() ? $file->temporaryUrl() : $current; @endphp
        @if ($preview)
            <img src="{{ $preview }}" alt="" class="absolute inset-0 size-full object-cover">
            <span class="absolute inset-x-0 bottom-0 bg-ink-950/60 py-1.5 text-xs text-ivory opacity-0 transition group-hover:opacity-100">{{ __('catalog_admin.replace_image') }}</span>
        @else
            <x-icon name="image" class="size-6 text-ink-400" />
            <span class="mt-2 px-3 text-xs text-ink-500">{{ __('catalog_admin.choose_image') }}</span>
        @endif
        <div x-show="uploading" x-cloak class="absolute inset-x-3 bottom-3 h-1.5 overflow-hidden rounded-full bg-white/70"><div class="h-full bg-brass-500 transition-all" :style="`width: ${progress}%`"></div></div>
        <input type="file" wire:model="{{ $model }}" accept="image/jpeg,image/png,image/webp" class="sr-only">
    </label>
    @error($model)<p class="field-error" role="alert">{{ $message }}</p>@enderror
    @if ($hint)<p class="field-hint">{{ $hint }}</p>@endif
</div>
