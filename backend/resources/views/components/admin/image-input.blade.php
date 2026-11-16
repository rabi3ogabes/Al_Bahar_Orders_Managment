@props(['model', 'file' => null, 'current' => null, 'label' => null, 'hint' => null, 'aspect' => 'aspect-square', 'class' => '', 'maxKb' => null, 'ratio' => null])
{{--
    Image upload with live preview. $file = the Livewire temporary upload, $current = existing URL.
    Drag a picture onto it or click to choose. With $maxKb it also checks the type (JPG, PNG, WebP) and the size in the browser before anything is uploaded;
    the server checks again. $ratio (e.g. "16 / 9") overrides the $aspect class with an exact CSS aspect-ratio.
--}}
<div class="{{ $class }}">
    @if ($label)<span class="field-label">{{ $label }}</span>@endif
    <label class="group relative flex cursor-pointer flex-col items-center justify-center overflow-hidden rounded-xl border border-dashed border-sand-300 bg-sand-50/60 text-center transition hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800/40 {{ $ratio ? '' : $aspect }}"
           @if ($ratio) style="aspect-ratio: {{ $ratio }}" @endif
           :class="over ? 'border-brass-500 bg-brass-50 ring-2 ring-brass-500/30 dark:bg-brass-500/10' : ''"
           x-data="{
               uploading: false, progress: 0, over: false, problem: '',
               maxBytes: {{ $maxKb ? (int) $maxKb * 1024 : 'null' }},
               ok(file) {
                   this.problem = '';
                   if (! file) { return true; }
                   if (! ['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) { this.problem = @js(__('banners_admin.image_type_bad')); return false; }
                   if (this.maxBytes && file.size > this.maxBytes) { this.problem = @js(__('banners_admin.image_too_big', ['mb' => $maxKb ? round($maxKb / 1024, 1) : 0])); return false; }
                   return true;
               },
               take(files) {
                   if (! files || ! files.length || ! this.ok(files[0])) { return; }
                   const dt = new DataTransfer(); dt.items.add(files[0]);
                   this.$refs.input.files = dt.files;
                   this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
               },
           }"
           x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-progress="progress = $event.detail.progress"
           x-on:dragover.prevent="over = true" x-on:dragleave.prevent="over = false" x-on:drop.prevent="over = false; take($event.dataTransfer.files)"
           x-on:change.capture="if ($event.target === $refs.input && ! ok($event.target.files[0])) { $event.stopPropagation(); $event.target.value = ''; }">
        @php $preview = $file && method_exists($file, 'isPreviewable') && $file->isPreviewable() ? $file->temporaryUrl() : $current; @endphp
        @if ($preview)
            <img src="{{ $preview }}" alt="" class="absolute inset-0 size-full object-cover">
            <span class="absolute inset-x-0 bottom-0 bg-ink-950/60 py-1.5 text-xs text-ivory opacity-0 transition group-hover:opacity-100 group-focus-within:opacity-100">{{ __('catalog_admin.replace_image') }}</span>
        @else
            <x-icon name="image" class="size-6 text-ink-400" />
            <span class="mt-2 px-3 text-xs text-ink-500">{{ $maxKb ? __('banners_admin.drop_or_choose') : __('catalog_admin.choose_image') }}</span>
        @endif
        <div x-show="uploading" x-cloak class="absolute inset-x-3 bottom-3 h-1.5 overflow-hidden rounded-full bg-white/70"><div class="h-full bg-brass-500 transition-all" :style="`width: ${progress}%`"></div></div>
        <input type="file" x-ref="input" wire:model="{{ $model }}" accept="image/jpeg,image/png,image/webp" class="sr-only">
        <p x-show="problem" x-cloak x-text="problem" role="alert" class="absolute inset-x-2 top-2 rounded-lg bg-ruby-soft px-2 py-1 text-xs font-medium text-white"></p>
    </label>
    @error($model)<p class="field-error" role="alert">{{ $message }}</p>@enderror
    @if ($hint)<p class="field-hint">{{ $hint }}</p>@endif
</div>
