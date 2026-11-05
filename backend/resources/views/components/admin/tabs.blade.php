@props(['tabs', 'current', 'model' => 'tab'])
{{-- $tabs: [key => [icon, label, badge?]] --}}
<nav {{ $attributes->merge(['class' => 'mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-sand-200/70 bg-white p-1 [scrollbar-width:none] dark:border-ink-700 dark:bg-ink-900']) }} role="tablist"
     x-data x-init="$nextTick(() => { const t = $el.querySelector('[aria-selected=true]'); if (t && $el.scrollWidth > $el.clientWidth) $el.scrollLeft += t.getBoundingClientRect().left - $el.getBoundingClientRect().left - 16 })">
    @foreach ($tabs as $key => $t)
        <button type="button" wire:click="$set('{{ $model }}', '{{ $key }}')" role="tab" aria-selected="{{ $current === $key ? 'true' : 'false' }}"
                class="flex shrink-0 items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transition {{ $current === $key ? 'bg-ink-900 text-ivory shadow dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600 hover:bg-sand-50 dark:text-ink-300 dark:hover:bg-ink-800' }}">
            <x-icon :name="$t[0]" class="size-4" />{{ $t[1] }}
            @if (isset($t[2]) && $t[2] !== null && $t[2] !== '')<span class="rounded-full px-1.5 text-[0.7rem] tabular-nums {{ $current === $key ? 'bg-white/15' : 'bg-sand-100 dark:bg-ink-800' }}">{{ $t[2] }}</span>@endif
        </button>
    @endforeach
</nav>
