@props(['picker', 'baseline' => []])
{{--
    "What can they use?": one-tap presets plus a plain-words checklist, grouped. Ticking a feature also ticks what it needs; unticking one
    unticks what needs it. Features the viewer does not hold themselves are shown locked (nobody grants what they do not have).
    The ticks live in the Livewire property `features` (entangled, sent with the form's Save): a checkbox group waits for Save.
    The optional `footer` slot sits inside the same Alpine scope, so a Save button there can read `dirty` (disabled until something changed).
    The server re-checks everything (BranchEmployeeService), so this widget is convenience only.
--}}
@php
    $presetMap = collect($picker['presets'])->mapWithKeys(fn ($p) => [$p['id'] => $p['features']])->all();
@endphp
<div x-data="{
        on: $wire.entangle('features'),
        base: @js(array_values($baseline)),
        all: @js($picker['allIds']),
        needs: @js($picker['needs']),
        presets: @js($presetMap),
        allowed: @js(array_values($picker['grantable'])),
        count: @js(__('branch_access.n_selected', ['n' => ':n', 'total' => ':total'])),
        can(id) { return this.allowed.includes(id); },
        has(id) { return this.on.includes(id); },
        closure(ids) {
            const out = new Set(ids); const queue = [...ids];
            while (queue.length) { const id = queue.pop(); for (const n of (this.needs[id] || [])) { if (!out.has(n)) { out.add(n); queue.push(n); } } }
            return [...out];
        },
        set(next) {
            this.on = this.all.filter((id) => next.includes(id));
            this.$el.dispatchEvent(new Event('input', { bubbles: true }));
        },
        toggle(id) {
            if (!this.can(id)) { return; }
            if (!this.has(id)) { this.set(this.closure([...this.on, id])); return; }
            const drop = new Set([id]); let grew = true;
            while (grew) { grew = false; for (const f of this.all) { if (!drop.has(f) && (this.needs[f] || []).some((n) => drop.has(n))) { drop.add(f); grew = true; } } }
            this.set(this.on.filter((f) => !drop.has(f) || !this.can(f)));
        },
        applyPreset(key) {
            const want = this.closure(this.presets[key]).filter((f) => this.can(f));
            this.set([...new Set([...want, ...this.on.filter((f) => !this.can(f))])]);
        },
        clearAll() { this.set(this.on.filter((f) => !this.can(f))); },
        get preset() {
            const cur = [...this.on].sort().join(',');
            return Object.keys(this.presets).find((k) => [...this.closure(this.presets[k])].sort().join(',') === cur) || '';
        },
        get dirty() { return [...this.on].sort().join(',') !== [...this.base].sort().join(','); },
        get label() { return this.count.replace(':n', this.on.length).replace(':total', this.all.length); },
     }"
     {{ $attributes->merge(['class' => 'space-y-5']) }}>

    <div>
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div><p class="field-label !mb-0.5">{{ __('branch_access.start_from') }}</p><p class="text-xs text-ink-500">{{ __('branch_access.start_from_hint') }}</p></div>
            <span class="badge-brass tabular-nums" aria-live="polite" x-text="label"></span>
        </div>
        <div class="mt-3 flex flex-wrap gap-2" role="group" aria-label="{{ __('branch_access.start_from') }}">
            @foreach ($picker['presets'] as $p)
                <button type="button" @click="applyPreset('{{ $p['id'] }}')" :aria-pressed="preset === '{{ $p['id'] }}'" title="{{ $p['hint'] }}"
                        class="inline-flex min-h-11 items-center gap-2 rounded-full border px-4 text-sm font-medium transition focus-visible:outline-2 focus-visible:outline-offset-2"
                        :class="preset === '{{ $p['id'] }}' ? 'border-brass-500 bg-brass-50 text-brass-700 dark:bg-brass-500/15 dark:text-brass-300' : 'border-sand-300 bg-white text-ink-700 hover:border-brass-400 dark:border-ink-600 dark:bg-ink-800 dark:text-sand-100'">
                    <x-icon name="check" class="size-3.5" x-show="preset === '{{ $p['id'] }}'" x-cloak />{{ $p['label'] }}
                </button>
            @endforeach
            <button type="button" @click="clearAll()" x-show="on.some((f) => can(f))" x-cloak class="inline-flex min-h-11 items-center rounded-full px-3 text-sm text-ink-500 hover:text-ruby-soft focus-visible:outline-2 focus-visible:outline-offset-2">{{ __('branch_access.clear_all') }}</button>
        </div>
        <p class="mt-2 text-xs text-ink-500" x-show="preset === '' && on.length" x-cloak>{{ __('branch_access.custom_hint') }}</p>
    </div>

    @if (count($picker['grantable']) < count($picker['allIds']))
        <p class="flex items-start gap-2 rounded-xl bg-sand-50 p-3 text-xs leading-relaxed text-ink-600 dark:bg-ink-800 dark:text-ink-300"><x-icon name="lock" class="mt-0.5 size-3.5 shrink-0" />{{ __('branch_access.locked_note') }}</p>
    @endif

    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($picker['groups'] as $group)
            <fieldset class="min-w-0 rounded-2xl border border-sand-200/70 p-3 dark:border-ink-700" wire:key="group-{{ $group['id'] }}">
                <legend class="px-1.5 text-xs font-semibold uppercase tracking-wider text-ink-500 dark:text-ink-400 rtl:tracking-normal">{{ $group['label'] }}</legend>
                <div class="space-y-0.5">
                    @foreach ($group['features'] as $f)
                        <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl px-2.5 py-2 transition hover:bg-sand-50 has-[:checked]:bg-brass-50/60 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 dark:hover:bg-ink-800 dark:has-[:checked]:bg-brass-500/10">
                            <input type="checkbox" class="checkbox mt-0.5 size-5 shrink-0" :checked="has('{{ $f['id'] }}')" :disabled="!can('{{ $f['id'] }}')" @change="toggle('{{ $f['id'] }}')" aria-describedby="fh-{{ $f['id'] }}">
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-ink-900 dark:text-ivory">{{ $f['label'] }}</span>
                                <span id="fh-{{ $f['id'] }}" class="block text-xs leading-relaxed text-ink-500">{{ $f['hint'] }}@if ($f['needs']) <span class="text-ink-400">· {{ __('branch_access.needs', ['list' => implode('، ', $f['needs'])]) }}</span>@endif</span>
                            </span>
                            <span class="mt-1 shrink-0 text-ink-400" x-show="!can('{{ $f['id'] }}')" x-cloak title="{{ __('branch_access.not_yours') }}"><x-icon name="lock" class="size-3.5" /></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        @endforeach
    </div>
    @error('features')<p class="field-error" role="alert">{{ $message }}</p>@enderror

    @isset($footer){{ $footer }}@endisset
</div>
