@props(['old' => [], 'new' => [], 'metadata' => []])
{{-- Side-by-side before/after viewer for audit entries. --}}
@php
    $old = is_array($old) ? $old : [];
    $new = is_array($new) ? $new : [];
    $keys = array_values(array_unique(array_merge(array_keys($old), array_keys($new))));
    $fmt = fn ($v) => $v === null ? 'null' : (is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
@endphp
<div {{ $attributes->merge(['class' => 'space-y-4']) }}>
    @if ($keys)
        <div class="overflow-hidden rounded-xl border border-sand-200/70 dark:border-ink-700">
            <div class="grid grid-cols-[minmax(6rem,10rem)_1fr_1fr] bg-sand-50/80 text-xs font-semibold text-ink-500 dark:bg-ink-800/60">
                <div class="px-3 py-2">{{ __('org_admin.audit_field') }}</div><div class="px-3 py-2">{{ __('org_admin.audit_before') }}</div><div class="px-3 py-2">{{ __('org_admin.audit_after') }}</div>
            </div>
            @foreach ($keys as $k)
                @php $o = array_key_exists($k, $old) ? $fmt($old[$k]) : null; $n = array_key_exists($k, $new) ? $fmt($new[$k]) : null; @endphp
                <div class="grid grid-cols-[minmax(6rem,10rem)_1fr_1fr] border-t border-sand-100 text-xs dark:border-ink-800">
                    <div class="break-all px-3 py-2 font-mono font-medium text-ink-700 dark:text-sand-200" dir="ltr">{{ $k }}</div>
                    <pre class="overflow-x-auto whitespace-pre-wrap break-all px-3 py-2 font-mono {{ $o !== $n ? 'bg-ruby-soft/5 text-ruby-soft' : 'text-ink-500' }}" dir="ltr">{{ $o ?? '—' }}</pre>
                    <pre class="overflow-x-auto whitespace-pre-wrap break-all px-3 py-2 font-mono {{ $o !== $n ? 'bg-emerald-soft/5 text-emerald-soft dark:text-emerald-300' : 'text-ink-500' }}" dir="ltr">{{ $n ?? '—' }}</pre>
                </div>
            @endforeach
        </div>
    @endif
    @if ($metadata)
        <div>
            <p class="mb-1.5 text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('org_admin.audit_metadata') }}</p>
            <pre class="overflow-x-auto rounded-xl bg-sand-50 p-3 font-mono text-xs text-ink-700 dark:bg-ink-800 dark:text-sand-200" dir="ltr">{{ json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif
    @if (! $keys && ! $metadata)<p class="text-sm text-ink-500">{{ __('org_admin.audit_no_values') }}</p>@endif
</div>
