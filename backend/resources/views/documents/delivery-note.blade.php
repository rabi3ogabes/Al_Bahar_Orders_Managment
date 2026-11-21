@php
    /*
     * Delivery note for dompdf: one page per delivery part, goes with the goods. The customer's language leads, labels carry both languages. Prices
     * only when the order is cash on delivery (or the dashboard switch says so); the amount to collect is the biggest thing on the page.
     */
    use App\Domain\Orders\Printing\PrintText as T;
    use App\Support\Money;
    $small = $paper === 'a5';
    $L = fn (string $key, string $lead, array $p = []) => T::label($key, $lead, $p);
    $block = fn (?string $text, int $width, string $class = '') => collect(T::lines($text, $width))
        ->map(fn (array $ln) => '<div class="'.trim(($ln['rtl'] ? 'rtl ' : '').$class).'">'.e($ln['text']).'</div>')->implode('');
    $m = fn (?string $v) => Money::of((string) $v)->format('en');
    $n = fn (?string $v) => substr(Money::of((string) $v)->format('en'), 4); // the number alone: the column heading says QAR
    $wide = $small ? 62 : 96;
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ __('print_docs.doc.note_title', [], 'en') }}</title>
@include('documents.partials.print-style')
<style>
    .collect { border: 3px solid #14161c; padding: {{ $small ? '8px 10px' : '10px 14px' }}; margin: 0 0 9px; }
    .collect .amount { font-size: {{ $small ? '25px' : '32px' }}; font-weight: bold; letter-spacing: .3px; }
    .collect.ok { border: 1.5px solid #5f6575; }
    .collect.alert { border-color: #8f2f2f; }
    .who .nm { font-size: {{ $small ? '14px' : '17px' }}; font-weight: bold; }
    .sums td { padding: 2px 0 2px 12px; border: 0; white-space: nowrap; }
    .sums .tot td { border-top: 1px solid #14161c; padding-top: 4px; font-weight: bold; }
    .sign { margin-top: {{ $small ? '12px' : '18px' }}; }
</style>
</head>
<body>
@foreach ($sheets as $s)
    @php
        $lead = $s['lead'];
        $leadAr = $lead === 'ar';
        $when = $s['when'];
        $pay = $s['pay'];
        $r = $s['recipient'];
        $primaryName = fn (array $l) => $leadAr && $l['name_ar'] ? $l['name_ar'] : $l['name_en'];
        $secondName = fn (array $l) => $leadAr ? $l['name_en'] : $l['name_ar'];
        $colspan = $s['show_prices'] ? 4 : 2;
    @endphp
    <div @class(['break' => ! $loop->first])>
        <table class="head"><tr>
            <td style="width:60%">
                <div class="brand">{{ config('platform.brand.name_en') }} · {{ T::shape(config('platform.brand.name_ar')) }}</div>
                <div class="title">{!! $L('note_title', $lead) !!}</div>
            </td>
            <td class="r bar">
                @if ($s['barcode'])<img src="{{ $s['barcode'] }}" alt="{{ $s['number'] }}">@endif
                <div class="code mono">{{ $s['number'] }}</div>
            </td>
        </tr></table>
        <div class="rule"></div>

        <table class="head" style="margin-bottom:8px"><tr>
            <td><span class="number mono">{{ $s['number'] }}</span>
                <span class="muted small"> &nbsp;{!! $L('order', $lead) !!} <span class="mono">{{ $s['order_number'] }}</span>@if ($s['part_count'] > 1) · {!! $L('part_of', $lead, ['n' => $s['part_index'], 'm' => $s['part_count']]) !!}@endif</span></td>
            <td class="r">@if ($s['copy'] > 1)<span class="stamp">{!! $L('reprint', $lead) !!} · {{ $s['copy'] }}</span>@endif</td>
        </tr></table>

        <table class="meta who"><tr>
            <td style="width:58%">
                <div class="tag">{!! $L('deliver_to', $lead) !!}</div>
                <div class="nm">{{ T::shape($r['name']) }}</div>
                @if ($r['phone'])<div class="mono" dir="ltr">{{ $r['phone'] }}</div>@endif
                @if ($r['ordered_by'])<div class="small muted">{!! $L('ordered_by', $lead) !!}: {{ T::shape($r['ordered_by']) }}</div>@endif
            </td>
            <td>
                <div class="tag">{!! $L('delivery_time', $lead) !!}</div>
                <div class="v">@if ($when){{ $when['date'] }}@if ($when['from'])<br>{{ $when['from'] }}@if ($when['to'])–{{ $when['to'] }}@endif @endif @else {!! $L('time_not_set', $lead) !!} @endif</div>
            </td>
        </tr></table>

        <div class="box">
            <div class="tag">{!! $L('address', $lead) !!}</div>
            {!! $block($s['address'], $wide, 'v') !!}
            @if ($s['landmark'])<div class="small muted" style="margin-top:3px">{!! $L('landmark', $lead) !!}</div>{!! $block($s['landmark'], $wide) !!}@endif
            @if ($s['instructions'])<div class="small muted" style="margin-top:3px">{!! $L('instructions', $lead) !!}</div>{!! $block($s['instructions'], $wide, 'name') !!}@endif
            @if ($s['customer_notes'])<div class="small muted" style="margin-top:3px">{!! $L('customer_note', $lead) !!}</div>{!! $block($s['customer_notes'], $wide) !!}@endif
            @if ($s['map_url'])<div class="small" style="margin-top:4px"><span class="muted">{!! $L('map', $lead) !!}:</span> <a href="{{ $s['map_url'] }}" style="color:#14161c">{{ $s['map_url'] }}</a></div>@endif
        </div>

        @if ($s['assembly'] || $s['two_people'])
            <div class="badges">
                @if ($s['assembly'])<span class="badge warn">{!! $L('assembly_requested', $lead) !!}</span>@endif
                @if ($s['two_people'])<span class="badge warn">{!! $L('two_people', $lead) !!}</span>@endif
            </div>
        @endif

        <table class="lines">
            <thead><tr>
                <th>{!! $L('items', $lead) !!}</th>
                <th class="r" style="width:34px">{!! $L('qty', $lead) !!}</th>
                @if ($s['show_prices'])<th class="r" style="width:{{ $small ? '58px' : '74px' }}">{!! $L('unit_price', $lead) !!}<div class="l2">QAR</div></th><th class="r" style="width:{{ $small ? '64px' : '82px' }}">{!! $L('line_total', $lead) !!}<div class="l2">QAR</div></th>@endif
            </tr></thead>
            <tbody>
            @forelse ($s['lines'] as $l)
                @php $variant = $leadAr && $l['variant_ar'] ? $l['variant_ar'] : $l['variant_en']; $second = $secondName($l); @endphp
                <tr>
                    <td>
                        {!! $block($primaryName($l), $small ? 34 : 54, 'name') !!}
                        @if ($variant){!! $block($variant, $small ? 34 : 54, 'muted') !!}@endif
                        @if ($second && $second !== $primaryName($l)){!! $block($second, $small ? 34 : 54, 'muted small') !!}@endif
                        <div class="small muted mono">{{ $l['sku'] }}</div>
                        @if ($l['assembly'])<div class="small"><b>{!! $L('assembly_requested', $lead) !!}</b>@if ($l['assembly_total']) &nbsp;<span class="muted">{{ $m($l['assembly_total']) }}</span>@endif</div>@endif
                    </td>
                    <td class="qty">{{ $l['qty'] }}</td>
                    @if ($s['show_prices'])
                        <td class="r">{{ $n($l['unit']) }}</td>
                        <td class="r"><b>{{ $n($l['total']) }}</b></td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $colspan }}" class="c muted" style="padding:14px">{!! $L('no_lines', $lead) !!}</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="small muted" style="margin:4px 0 10px">{!! $L('units', $lead) !!}: <b>{{ $s['units'] }}</b></div>

        @switch ($pay['mode'])
            @case('cod')
                <div class="collect keep">
                    <table class="head"><tr>
                        <td><div class="tag" style="color:#14161c">{!! $L('collect', $lead) !!}</div><div class="amount">{{ $m($pay['collect']) }}</div></td>
                        <td class="r small">
                            <table class="sums" style="width:auto;margin-left:auto">
                                @if ($pay['share'])
                                    <tr><td colspan="2" class="r muted">{!! $L('parcel_share', $lead, ['n' => $pay['parcels']]) !!}</td></tr>
                                @endif
                                <tr><td class="muted">{!! $L('order_total', $lead) !!}</td><td class="r">{{ $m($pay['order_total']) }}</td></tr>
                                @if (Money::of($pay['paid'])->isPositive())<tr><td class="muted">{!! $L('already_paid', $lead) !!}</td><td class="r">{{ $m($pay['paid']) }}</td></tr>@endif
                            </table>
                        </td>
                    </tr></table>
                </div>
                @break
            @case('settled')
                <div class="collect ok keep"><b>{!! $L('settled', $lead) !!}</b></div>
                @break
            @case('paid')
                <div class="collect ok keep"><b>{!! $L('paid_online', $lead) !!}</b>@if ($s['show_prices']) <span class="muted small"> &nbsp;{!! $L('order_total', $lead) !!}: {{ $m($pay['order_total']) }}</span>@endif</div>
                @break
            @default
                <div class="collect alert keep"><b style="color:#8f2f2f">{!! $L('unpaid', $lead) !!}</b></div>
        @endswitch

        <table class="sig sign keep"><tr>
            <td style="width:40%"><div class="line"></div><div class="small muted">{!! $L('received_by', $lead) !!}</div></td>
            <td style="width:36%"><div class="line"></div><div class="small muted">{!! $L('signature', $lead) !!}</div></td>
            <td><div class="line"></div><div class="small muted">{!! $L('date', $lead) !!}</div></td>
        </tr></table>

        @if ($s['help_phone'])
            <div class="small" style="margin-top:10px">{!! $L('problem', $lead) !!} <b class="mono" dir="ltr">{{ $s['help_phone'] }}</b> <span class="muted">({{ $leadAr && $s['help_branch_ar'] ? T::shape($s['help_branch_ar']) : $s['help_branch_en'] }})</span></div>
        @endif
        <div class="foot">{{ __('print_docs.doc.printed_by', ['name' => $s['printed_by'] ?? '—'], 'en') }} · {{ $s['printed_at'] }} · {{ __('print_docs.doc.copy', ['n' => $s['copy']], 'en') }}</div>
    </div>
@endforeach
</body></html>
