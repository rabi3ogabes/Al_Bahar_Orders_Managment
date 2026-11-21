@php
    /*
     * Picking list for dompdf: one page (or more, for a long part) per branch part, the lines in walking order (bin by bin). The customer's language
     * leads, labels carry both languages. Arabic goes through PrintText, one line at a time (dompdf cannot join or wrap Arabic itself).
     */
    use App\Domain\Orders\Printing\PrintText as T;
    $small = $paper === 'a5';
    $L = fn (string $key, string $lead, array $p = []) => T::label($key, $lead, $p);
    $block = fn (?string $text, int $width, string $class = '') => collect(T::lines($text, $width))
        ->map(fn (array $ln) => '<div class="'.trim(($ln['rtl'] ? 'rtl ' : '').$class).'">'.e($ln['text']).'</div>')->implode('');
    $nameWidth = $small ? 30 : 46;
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ __('print_docs.doc.picking_title', [], 'en') }}</title>
@include('documents.partials.print-style')
</head>
<body>
@foreach ($sheets as $s)
    @php
        $lead = $s['lead'];
        $leadAr = $lead === 'ar';
        $branchName = $leadAr && $s['branch']['name_ar'] ? T::shape($s['branch']['name_ar']) : $s['branch']['name_en'];
        $isPickup = $s['type'] === 'pickup';
        $when = $s['when'];
    @endphp
    <div @class(['break' => ! $loop->first])>
        <table class="head"><tr>
            <td style="width:60%">
                <div class="brand">{{ config('platform.brand.name_en') }} · {{ T::shape(config('platform.brand.name_ar')) }}</div>
                <div class="title">{!! $L('picking_title', $lead) !!}</div>
            </td>
            <td class="r bar">
                @if ($s['barcode'])<img src="{{ $s['barcode'] }}" alt="{{ $s['number'] }}">@endif
                <div class="code mono">{{ $s['number'] }}</div>
            </td>
        </tr></table>
        <div class="rule"></div>

        <table class="head" style="margin-bottom:8px"><tr>
            <td><span class="number mono">{{ $s['number'] }}</span>
                @if ($s['part_count'] > 1)<span class="muted small"> &nbsp;{!! $L('part_of', $lead, ['n' => $s['part_index'], 'm' => $s['part_count']]) !!}</span>@endif</td>
            <td class="r">@if ($s['copy'] > 1)<span class="stamp">{!! $L('reprint', $lead) !!} · {{ $s['copy'] }}</span>@endif</td>
        </tr></table>

        <table class="meta"><tr>
            <td><div class="tag">{!! $L('branch', $lead) !!}</div><div class="v">{{ $s['branch']['code'] }} · {{ $branchName }}</div></td>
            <td><div class="tag">{!! $L($isPickup ? 'pickup_time' : 'delivery_time', $lead) !!}</div>
                <div class="v">@if ($when){{ $when['date'] }}@if ($when['from']) · {{ $when['from'] }}@if ($when['to'])–{{ $when['to'] }}@endif @endif @else {!! $L('time_not_set', $lead) !!} @endif</div></td>
        </tr><tr>
            <td><div class="tag">{!! $L('order', $lead) !!}</div><div class="v mono">{{ $s['order_number'] }}</div></td>
            <td><div class="tag">{!! $L('placed', $lead) !!}</div><div class="v">{{ $s['placed_at'] }}</div></td>
        </tr></table>

        <div class="badges">
            <span class="badge">{!! $L($isPickup ? 'type_pickup' : 'type_delivery', $lead) !!}</span>
            @if ($s['assembly'])<span class="badge warn">{!! $L('assembly_requested', $lead) !!}</span>@endif
            @if ($s['two_people'])<span class="badge warn">{!! $L('two_people', $lead) !!}</span>@endif
            @if ($s['waiting_customer'])<span class="badge amber">{!! $L('waiting_customer', $lead) !!}</span>@endif
        </div>

        @if ($s['waiting_customer'])
            <div class="box" style="border-color:#9a6b13"><div class="small">{!! $L('waiting_hint', $lead) !!}</div></div>
        @endif
        @if ($s['customer_notes'] || $s['staff_notes'])
            <div class="box">
                <div class="tag">{!! $L('notes', $lead) !!}</div>
                @if ($s['customer_notes'])<div class="small muted">{!! $L('customer_note', $lead) !!}</div>{!! $block($s['customer_notes'], $small ? 58 : 92) !!}@endif
                @foreach ($s['staff_notes'] as $note)<div class="small muted" style="margin-top:3px">{!! $L('branch_note', $lead) !!}</div>{!! $block($note, $small ? 58 : 92) !!}@endforeach
            </div>
        @endif
        @if ($s['other_parts'])
            <div class="small muted" style="margin-bottom:6px">{!! $L('other_parts', $lead) !!}:
                @foreach ($s['other_parts'] as $o)<span class="mono">{{ $o['number'] }}</span> ({{ $leadAr && $o['branch_ar'] ? T::shape($o['branch_ar']) : $o['branch_en'] }})@if (! $loop->last), @endif @endforeach</div>
        @endif

        <table class="lines">
            <thead>
                <tr><th colspan="5" class="small mono" style="border-bottom:0;padding-bottom:0;color:#14161c">{{ $s['number'] }} · {{ __('print_docs.doc.picking_title', [], 'en') }}</th></tr>
                <tr>
                    <th style="width:22px">{!! $L('picked', $lead) !!}</th>
                    <th style="width:{{ $small ? '60px' : '78px' }}">{!! $L('bin', $lead) !!}</th>
                    <th>{!! $L('item', $lead) !!}</th>
                    <th style="width:{{ $small ? '62px' : '84px' }}">{!! $L('sku', $lead) !!}</th>
                    <th class="r" style="width:38px">{!! $L('qty', $lead) !!}</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($s['lines'] as $l)
                @php
                    $primary = $leadAr && $l['name_ar'] ? $l['name_ar'] : $l['name_en'];
                    $second = $leadAr ? $l['name_en'] : $l['name_ar'];
                    $variant = $leadAr && $l['variant_ar'] ? $l['variant_ar'] : $l['variant_en'];
                    $replaces = $leadAr && $l['replaces_ar'] ? $l['replaces_ar'] : $l['replaces_en'];
                @endphp
                <tr>
                    <td><div class="tick"></div></td>
                    <td>@if ($l['bin'])<span class="bin">{{ $l['bin'] }}</span>@else<span class="muted small">{!! $L('no_bin', $lead) !!}</span>@endif</td>
                    <td>
                        {!! $block($primary, $nameWidth, 'name') !!}
                        @if ($variant){!! $block($variant, $nameWidth, 'muted') !!}@endif
                        @if ($second && $second !== $primary){!! $block($second, $nameWidth, 'muted small') !!}@endif
                        @if ($l['assembly'] || $l['oversized'])
                            <div class="small" style="margin-top:2px">@if ($l['assembly'])<b>{!! $L('assembly_requested', $lead) !!}</b>@endif @if ($l['oversized'])<b>{!! $L('oversized', $lead) !!}</b>@endif</div>
                        @endif
                        @if ($l['reduced_from'])<div class="small muted">{!! $L('reduced_from', $lead, ['n' => $l['reduced_from']]) !!}</div>@endif
                        @if ($l['waiting'])
                            <div class="small" style="margin-top:2px;color:#7a5410"><b>{!! $L('waiting_line', $lead, ['time' => $l['waiting_until'] ?? '']) !!}</b></div>
                            @if ($replaces)<div class="small muted">{!! $L('instead_of', $lead) !!}: {!! $block($replaces, $nameWidth, 'small') !!}</div>@endif
                        @endif
                    </td>
                    <td class="mono small">{{ $l['sku'] }}</td>
                    <td class="qty">{{ $l['qty'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="c muted" style="padding:16px">{!! $L('no_lines', $lead) !!}</td></tr>
            @endforelse
            </tbody>
        </table>

        <table style="margin-top:6px"><tr>
            <td class="small muted">{!! $L('lines', $lead) !!}: <b>{{ count($s['lines']) }}</b> &nbsp;·&nbsp; {!! $L('units', $lead) !!}: <b>{{ $s['units'] }}</b>@if ($s['weight_kg'] !== null) &nbsp;·&nbsp; {!! $L('weight', $lead) !!}: <b>{{ $s['weight_kg'] }} kg</b>@endif</td>
            <td class="r small" style="color:#7a5410">@if ($s['missing_bins'] > 0){!! $L('no_bin_count', $lead, ['n' => $s['missing_bins']]) !!}@endif</td>
        </tr></table>

        <table class="sig keep" style="margin-top:{{ $small ? '14px' : '20px' }}"><tr>
            <td style="width:38%"><div class="line"></div><div class="small muted">{!! $L('picked_by', $lead) !!}</div></td>
            <td style="width:38%"><div class="line"></div><div class="small muted">{!! $L('checked_by', $lead) !!}</div></td>
            <td><div class="line"></div><div class="small muted">{!! $L('time', $lead) !!}</div></td>
        </tr></table>

        <div class="foot">{{ __('print_docs.doc.printed_by', ['name' => $s['printed_by'] ?? '—'], 'en') }} · {{ $s['printed_at'] }} · {{ __('print_docs.doc.copy', ['n' => $s['copy']], 'en') }}</div>
    </div>
@endforeach
</body></html>
