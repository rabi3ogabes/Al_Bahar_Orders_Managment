@php
    $rtl = in_array($locale, config('platform.rtl_locales', ['ar']), true);
    // Per-size type scale: name, variant, code and price (points) and the barcode's share of the label height.
    $scale = ['small' => ['name' => 6.5, 'meta' => 5.2, 'code' => 5.2, 'price' => 7, 'bars' => '36%'],
              'medium' => ['name' => 9, 'meta' => 7, 'code' => 7, 'price' => 10.5, 'bars' => '40%'],
              'large' => ['name' => 14, 'meta' => 10, 'code' => 10, 'price' => 17, 'bars' => '40%']][$layout['key']];
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('catalog_admin.labels_title') }}</title>
<style>
    :root { --w: {{ $layout['w'] }}mm; --h: {{ $layout['h'] }}mm; --cols: {{ $layout['cols'] }}; --left: {{ $layout['left'] }}mm; --top: {{ $layout['top'] }}mm;
            --fs-name: {{ $scale['name'] }}pt; --fs-meta: {{ $scale['meta'] }}pt; --fs-code: {{ $scale['code'] }}pt; --fs-price: {{ $scale['price'] }}pt; --bars: {{ $scale['bars'] }}; }
    * { box-sizing: border-box; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; background: #e9e5e6; color: #14161c; font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Arabic", "Helvetica Neue", Arial, sans-serif; }

    /* ── Screen only: the toolbar and the sheet preview ─────────────────────────────── */
    .toolbar { position: sticky; top: 0; z-index: 5; background: #fff; border-bottom: 1px solid #ddd4d7; padding: 12px 16px; }
    .toolbar-inner { max-width: 1100px; margin: 0 auto; display: flex; flex-wrap: wrap; gap: 12px 20px; align-items: flex-end; }
    .toolbar h1 { font-size: 18px; margin: 0; flex: 1 1 100%; }
    .toolbar p { margin: 2px 0 0; font-size: 13px; color: #5f6575; font-weight: 400; }
    .toolbar form { display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: flex-end; flex: 1 1 auto; }
    .toolbar label { display: grid; gap: 4px; font-size: 12px; font-weight: 600; color: #4f3b43; }
    .toolbar .check { display: flex; align-items: center; gap: 6px; padding-bottom: 10px; }
    .toolbar select, .toolbar input[type=number] { min-height: 44px; border: 1px solid #cfc5c9; border-radius: 10px; padding: 0 10px; font-size: 14px; background: #fff; color: inherit; }
    .toolbar input[type=number] { width: 84px; }
    .btn { min-height: 44px; display: inline-flex; align-items: center; gap: 6px; padding: 0 16px; border-radius: 10px; border: 1px solid #cfc5c9; background: #fff; color: #14161c; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; }
    .btn-primary { background: #8a1538; border-color: #8a1538; color: #fff; }
    .btn:focus-visible, select:focus-visible, input:focus-visible { outline: 3px solid #e58aa5; outline-offset: 2px; }
    .actions { display: flex; gap: 8px; margin-inline-start: auto; }
    .notes { max-width: 1100px; margin: 10px auto 0; display: grid; gap: 6px; }
    .note { font-size: 13px; padding: 8px 12px; border-radius: 10px; background: #fcefd3; color: #6b4a07; }
    .note.info { background: #ecf3fb; color: #1b4a7c; }
    .sheets { padding: 20px 0 40px; display: grid; gap: 20px; justify-content: center; }
    .page { width: 210mm; height: 297mm; background: #fff; box-shadow: 0 6px 24px rgba(0,0,0,.14); padding: var(--top) var(--left); overflow: hidden; }
    .grid { display: grid; grid-template-columns: repeat(var(--cols), var(--w)); grid-auto-rows: var(--h); }
    .label { width: var(--w); height: var(--h); padding: 1.6mm 2mm; display: flex; flex-direction: column; gap: .6mm; overflow: hidden; outline: 1px dashed #d9cfd3; outline-offset: -1px; break-inside: avoid; }
    .name { font-size: var(--fs-name); font-weight: 700; line-height: 1.15; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; unicode-bidi: plaintext; }
    .name.other { font-weight: 500; font-size: calc(var(--fs-name) * .85); -webkit-line-clamp: 1; }
    .variant { font-size: var(--fs-meta); color: #3d4250; line-height: 1.1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; unicode-bidi: plaintext; }
    .bars { flex: 1 1 var(--bars); min-height: 0; margin-top: auto; }
    .bars svg { display: block; width: 100%; height: 100%; }
    .nobars { flex: 1 1 var(--bars); display: grid; place-items: center; font-size: var(--fs-meta); color: #8a1538; border: 1px dashed #c9b3ba; border-radius: 1mm; text-align: center; }
    .foot { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; line-height: 1.1; }
    .code { font-family: ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; font-size: var(--fs-code); letter-spacing: .02em; direction: ltr; unicode-bidi: isolate; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .price { font-size: var(--fs-price); font-weight: 800; white-space: nowrap; direction: ltr; unicode-bidi: isolate; }
    .empty { text-align: center; padding: 60px 16px; color: #5f6575; }
    .cut { display: none; }

    /* ── Print: only the sheets, exactly A4, no browser margins ───────────────────────── */
    @page { size: A4; margin: 0; }
    @media print {
        body { background: #fff; }
        .toolbar, .notes, .no-print { display: none !important; }
        .sheets { display: block; padding: 0; }
        .page { box-shadow: none; margin: 0; page-break-after: always; break-after: page; }
        .page:last-child { page-break-after: auto; break-after: auto; }
        .label { outline: none; }
    }
    @media (max-width: 800px) { .page { transform-origin: top center; zoom: .45; } }
</style>
</head>
<body>
<header class="toolbar no-print">
    <div class="toolbar-inner">
        <h1>{{ __('catalog_admin.labels_title') }}<p>{{ trans_choice('catalog_admin.labels_summary', $count, ['count' => $count, 'pages' => count($pages)]) }}</p></h1>
        <form method="get" action="{{ route('admin.products.labels') }}">
            <input type="hidden" name="ids" value="{{ $options['ids'] }}">
            <label>{{ __('catalog_admin.labels_copies') }}<input type="number" name="copies" min="1" max="{{ \App\Http\Controllers\Admin\ProductLabelController::MAX_COPIES }}" value="{{ $options['copies'] }}" inputmode="numeric"></label>
            <label>{{ __('catalog_admin.labels_size') }}
                <select name="layout">@foreach (array_keys(\App\Http\Controllers\Admin\ProductLabelController::LAYOUTS) as $k)<option value="{{ $k }}" @selected($options['layout'] === $k)>{{ __('catalog_admin.labels_size_'.$k, ['n' => \App\Http\Controllers\Admin\ProductLabelController::LAYOUTS[$k]['cols'] * \App\Http\Controllers\Admin\ProductLabelController::LAYOUTS[$k]['rows']]) }}</option>@endforeach</select></label>
            <label>{{ __('catalog_admin.labels_language') }}
                <select name="lang">@foreach (['auto', 'en', 'ar', 'both'] as $k)<option value="{{ $k }}" @selected($options['lang'] === $k)>{{ __('catalog_admin.labels_lang_'.$k) }}</option>@endforeach</select></label>
            <input type="hidden" name="price" value="0">
            <label class="check"><input type="checkbox" name="price" value="1" @checked($options['price'])>{{ __('catalog_admin.labels_show_price') }}</label>
            <button class="btn" type="submit">{{ __('catalog_admin.labels_update') }}</button>
        </form>
        <div class="actions">
            <a class="btn" href="{{ route('admin.products.index') }}">{{ __('catalog_admin.labels_back') }}</a>
            <button class="btn btn-primary" type="button" onclick="window.print()">{{ __('catalog_admin.labels_print') }}</button>
        </div>
    </div>
    <div class="notes">
        <div class="note info">{{ __('catalog_admin.labels_print_tip') }}</div>
        @if ($problems['no_barcode'])<div class="note" role="alert">{{ __('catalog_admin.labels_no_barcode', ['names' => implode(', ', $problems['no_barcode'])]) }}</div>@endif
        @if ($problems['cramped'])<div class="note" role="alert">{{ __('catalog_admin.labels_cramped', ['names' => implode(', ', $problems['cramped'])]) }}</div>@endif
    </div>
</header>

<main class="sheets" aria-label="{{ __('catalog_admin.labels_title') }}">
@forelse ($pages as $labels)
    <section class="page" aria-label="{{ __('catalog_admin.labels_page', ['n' => $loop->iteration, 'of' => count($pages)]) }}">
        <div class="grid">
            @foreach ($labels as $l)
                <div class="label">
                    <div class="name" dir="auto">{{ $l['name'] }}</div>
                    @if ($l['name_other'])<div class="name other" dir="auto">{{ $l['name_other'] }}</div>@endif
                    @if ($l['variant'])<div class="variant" dir="auto">{{ $l['variant'] }}</div>@endif
                    @if ($l['svg'])<div class="bars">{!! $l['svg'] !!}</div>@else<div class="nobars">{{ __('catalog_admin.labels_no_barcode_short') }}</div>@endif
                    <div class="foot"><span class="code" dir="ltr">{{ $l['code'] }}</span>@if ($l['price'])<span class="price">{{ $l['price'] }}</span>@endif</div>
                </div>
            @endforeach
        </div>
    </section>
@empty
    <p class="empty">{{ __('catalog_admin.labels_empty') }}</p>
@endforelse
</main>
</body>
</html>
