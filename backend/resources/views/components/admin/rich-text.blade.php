@props(['id', 'dir' => 'ltr', 'rows' => 8])
{{-- Lightweight HTML editor: a textarea with a formatting toolbar. Output is sanitised server-side by ProductService. --}}
<div x-data="{
        wrap(open, close) {
            const el = this.$refs.ta, s = el.selectionStart, e = el.selectionEnd, v = el.value;
            el.value = v.slice(0, s) + open + v.slice(s, e) + close + v.slice(e);
            el.dispatchEvent(new Event('input'));
            el.focus(); el.setSelectionRange(s + open.length, e + open.length);
        },
        list() {
            const el = this.$refs.ta, s = el.selectionStart, e = el.selectionEnd, v = el.value;
            const items = (v.slice(s, e) || 'Item').split(/\n+/).map(l => '  <li>' + l.trim() + '</li>').join('\n');
            el.value = v.slice(0, s) + '<ul>\n' + items + '\n</ul>' + v.slice(e);
            el.dispatchEvent(new Event('input')); el.focus();
        }
    }" class="overflow-hidden rounded-xl border border-sand-300 bg-white shadow-sm focus-within:border-brass-500 focus-within:ring-2 focus-within:ring-brass-500/25 dark:border-ink-600 dark:bg-ink-800">
    <div class="flex items-center gap-0.5 border-b border-sand-200 bg-sand-50/70 px-1.5 py-1 dark:border-ink-700 dark:bg-ink-900/40" dir="ltr" role="toolbar">
        <button type="button" @click="wrap('<strong>', '</strong>')" class="rounded-md px-2 py-1 text-xs font-bold text-ink-600 hover:bg-white dark:text-sand-200 dark:hover:bg-ink-700" title="Bold">B</button>
        <button type="button" @click="wrap('<em>', '</em>')" class="rounded-md px-2 py-1 text-xs italic text-ink-600 hover:bg-white dark:text-sand-200 dark:hover:bg-ink-700" title="Italic">I</button>
        <button type="button" @click="wrap('<h3>', '</h3>')" class="rounded-md px-2 py-1 text-xs font-semibold text-ink-600 hover:bg-white dark:text-sand-200 dark:hover:bg-ink-700" title="Heading">H</button>
        <button type="button" @click="wrap('<p>', '</p>')" class="rounded-md px-2 py-1 text-xs text-ink-600 hover:bg-white dark:text-sand-200 dark:hover:bg-ink-700" title="Paragraph">¶</button>
        <button type="button" @click="list()" class="rounded-md px-2 py-1 text-xs text-ink-600 hover:bg-white dark:text-sand-200 dark:hover:bg-ink-700" title="List">• —</button>
    </div>
    <textarea x-ref="ta" id="{{ $id }}" rows="{{ $rows }}" dir="{{ $dir }}" {{ $attributes->merge(['class' => 'block w-full resize-y border-0 bg-transparent px-3.5 py-2.5 font-mono text-[0.8rem] leading-relaxed text-ink-900 focus:ring-0 dark:text-sand-100']) }}></textarea>
</div>
