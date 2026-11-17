@php
    /*
     * Bilingual (English / Arabic) invoice for dompdf. dompdf cannot join Arabic letters, so every Arabic string goes through ArabicShaper.
     * The order's language decides which language leads (item names, the thank-you line); labels always show both.
     */
    $o = $s['order'];
    $leadAr = ($locale ?? 'en') === 'ar';
    // A revision after the first means the branch changed what is in the order (an item was short) and the invoice was issued again: same number, revision N.
    $revision = (int) ($invoice->revision ?? 1);
    $revised = $revision > 1;
    $changes = $s['changes'] ?? [];
    $lang = $leadAr ? 'ar' : 'en';
    $m = fn ($v) => \App\Support\Money::of((string) $v)->format('en');
    $ar = fn (?string $t) => $t !== null && $t !== '' ? \App\Support\ArabicShaper::shape($t) : '';
    // A label in both languages: English on the left, Arabic (shaped) muted beside it.
    $lab = fn (string $en, string $arText) => e($en).' <span class="arl">'.e($ar($arText)).'</span>';
    $when = fn ($t) => \App\Support\BusinessTime::local($t)?->format('d M Y · H:i');
    $methods = ['cod' => ['Cash on delivery', 'الدفع عند الاستلام'], 'sadad' => ['SADAD online payment', 'الدفع الإلكتروني سداد'], 'pay_at_pickup' => ['Pay at pickup', 'الدفع عند الاستلام من الفرع']];
    $statuses = ['paid' => ['PAID', 'مدفوعة'], 'pending' => ['PAYMENT DUE', 'بانتظار الدفع'], 'processing' => ['PAYMENT PROCESSING', 'جارٍ الدفع'], 'authorized' => ['PAYMENT AUTHORISED', 'تم تفويض الدفع'],
        'partially_refunded' => ['PARTLY REFUNDED', 'مستردة جزئياً'], 'refunded' => ['REFUNDED', 'مستردة'], 'failed' => ['PAYMENT FAILED', 'فشل الدفع']];
    $method = $methods[$o['payment_method'] ?? ''] ?? [ucwords(str_replace('_', ' ', (string) ($o['payment_method'] ?? '—'))), ''];
    $status = $statuses[(string) $o['payment_status']] ?? [strtoupper((string) $o['payment_status']), ''];
    $paid = (string) $o['payment_status'] === 'paid';
    $type = ($o['fulfillment_type'] ?? '') === 'pickup' ? ['Pickup', 'استلام من الفرع'] : ['Delivery', 'توصيل'];
    $a = $o['address_snapshot'] ?? [];
    $address = collect([$a['unit_number'] ?? null ? 'Unit '.$a['unit_number'] : null, $a['building_number'] ?? null ? 'Bldg '.$a['building_number'] : null,
        $a['street_number'] ?? null ? 'St '.$a['street_number'] : null, isset($a['zone_number']) ? 'Zone '.$a['zone_number'] : null, $a['area'] ?? null])->filter()->implode(', ');
    $phone = trim((string) app(\App\Domain\Settings\Services\Settings::class)->get('contact.support_phone', ''));
    $email = trim((string) app(\App\Domain\Settings\Services\Settings::class)->get('contact.support_email', ''));
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<style>
    @page { margin: 0; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { color: #14161c; font-size: 10.5px; margin: 0; }
    .brand { background: #14161c; color: #faf7f2; padding: 24px 32px 20px; border-bottom: 4px solid #b08d57; }
    .brand td { border: 0; padding: 0; vertical-align: middle; }
    .brand h1 { margin: 0; font-size: 21px; letter-spacing: .6px; }
    .brand .sub { color: #d9c49a; font-size: 10px; letter-spacing: 1.6px; margin-top: 5px; }
    .brand .nameAr { font-size: 19px; text-align: right; }
    .brand .doc { text-align: right; color: #d9c49a; font-size: 10px; margin-top: 6px; letter-spacing: 1px; }
    .wrap { padding: 22px 32px 28px; }
    table { width: 100%; border-collapse: collapse; }
    .arl { color: #7a6a4d; font-size: 9.5px; letter-spacing: 0; text-transform: none; font-weight: normal; }
    .muted { color: #5f6575; }
    .tag { font-size: 8.5px; letter-spacing: 1.2px; text-transform: uppercase; color: #8a7a5f; margin-bottom: 3px; }
    .meta td { border: 0; padding: 0 0 12px; vertical-align: top; width: 50%; }
    .meta .v { font-size: 12px; font-weight: bold; }
    .stamp { display: inline-block; border: 1.5px solid #b08d57; color: #8a6a2f; padding: 3px 10px; font-size: 10px; font-weight: bold; letter-spacing: 1px; }
    .stamp.ok { border-color: #2f7d4f; color: #2f7d4f; }
    .items { margin-top: 6px; }
    .items th { text-align: left; font-size: 8.5px; letter-spacing: .8px; text-transform: uppercase; color: #5f6575; border-bottom: 1.5px solid #14161c; padding: 7px 6px; vertical-align: bottom; }
    .items th .arl { text-transform: none; letter-spacing: 0; display: block; }
    .items td { padding: 8px 6px; border-bottom: 1px solid #efe7da; vertical-align: top; }
    .r { text-align: right; }
    .name { font-weight: bold; font-size: 11px; }
    .totals { width: 52%; margin-left: 48%; margin-top: 14px; }
    .totals td { padding: 4px 6px; border: 0; }
    .totals .grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #14161c; padding-top: 8px; }
    .note { margin-top: 26px; border-top: 1px solid #e2d6c3; padding-top: 12px; }
    .note td { border: 0; vertical-align: top; width: 50%; padding: 0; }
    .foot { margin-top: 18px; color: #5f6575; font-size: 9px; }
    .rev { display: inline-block; background: #b08d57; color: #14161c; padding: 2px 9px; font-size: 9.5px; font-weight: bold; letter-spacing: 1px; margin-top: 7px; }
    .changes { margin-top: 20px; border: 1px solid #e2d6c3; border-left: 4px solid #b08d57; background: #fbf8f2; padding: 11px 14px 6px; }
    .changes .rv { margin-bottom: 9px; }
    .changes .rv table td { border: 0; padding: 1px 0; vertical-align: top; }
    .changes .head { font-weight: bold; font-size: 10.5px; }
    .changes ul { margin: 4px 0 3px 0; padding: 0 0 0 14px; }
    .changes li { margin-bottom: 2px; }
    .small { font-size: 9px; }
    .minus { color: #8f2f2f; }
</style></head>
<body>
<div class="brand"><table><tr>
    <td><h1>{{ $s['company']['name_en'] }}</h1><div class="sub">@if ($revised)REVISED INVOICE &nbsp;·&nbsp; revision {{ $revision }} &nbsp;·&nbsp; <span style="letter-spacing:0">{{ $ar('فاتورة معدّلة') }}</span>@else INVOICE &nbsp;·&nbsp; <span style="letter-spacing:0">{{ $ar('فاتورة') }}</span>@endif</div></td>
    <td><div class="nameAr">{{ $ar($s['company']['name_ar']) }}</div><div class="doc">{{ $invoice->number }}@if ($revised) &nbsp;·&nbsp; REV {{ $revision }}@endif</div></td>
</tr></table></div>

<div class="wrap">
    <table class="meta"><tr>
        <td>
            <div class="tag">{!! $lab('Invoice no.', 'رقم الفاتورة') !!}</div><div class="v">{{ $invoice->number }}</div>
            @if ($revised)<div class="rev">{{ 'REVISION '.$revision }} &nbsp;{{ $ar('التعديل '.$revision) }}</div>@endif
        </td>
        <td>
            <div class="tag">{!! $lab('Billed to', 'فاتورة إلى') !!}</div><div class="v">{{ $ar($o['customer_name']) ?: $o['customer_name'] }}</div>
        </td>
    </tr><tr>
        <td>
            <div class="tag">{!! $lab('Issued', 'تاريخ الإصدار') !!}</div><div>{{ $when($invoice->issued_at) }} <span class="muted">(Asia/Qatar)</span></div>
            <div class="tag" style="margin-top:9px">{!! $lab('Order', 'الطلب') !!}</div><div>{{ $o['number'] }} &nbsp;·&nbsp; {{ $when($o['placed_at']) }}</div>
            <div class="tag" style="margin-top:9px">{!! $lab($type[0], $type[1]) !!}</div>
            @foreach ($s['branches'] as $b)<div>{{ $b['number'] }} — {{ $leadAr && $b['name_ar'] ? $ar($b['name_ar']) : $b['name_en'] }}</div>@endforeach
        </td>
        <td>
            @if ($o['customer_phone'])<div>{{ $o['customer_phone'] }}</div>@endif
            @if ($o['customer_email'])<div>{{ $o['customer_email'] }}</div>@endif
            @if ($address !== '')<div style="margin-top:4px">{{ $a['recipient_name'] ?? '' }}@if (! empty($a['recipient_name']))<br>@endif{{ $address }}</div>@endif
            <div class="tag" style="margin-top:9px">{!! $lab('Payment', 'الدفع') !!}</div>
            <div>{{ $method[0] }} @if ($method[1])<span class="arl">{{ $ar($method[1]) }}</span>@endif</div>
            <div style="margin-top:5px"><span class="stamp {{ $paid ? 'ok' : '' }}">{{ $status[0] }}@if ($status[1]) &nbsp;<span class="arl" style="color:inherit;font-size:10px">{{ $ar($status[1]) }}</span>@endif</span></div>
        </td>
    </tr></table>

    <table class="items">
        <thead><tr>
            <th>{{ 'Item' }}<span class="arl">{{ $ar('الصنف') }}</span></th>
            <th>SKU<span class="arl">{{ $ar('الرمز') }}</span></th>
            <th class="r">Qty<span class="arl">{{ $ar('الكمية') }}</span></th>
            <th class="r">Unit<span class="arl">{{ $ar('السعر') }}</span></th>
            <th class="r">Discount<span class="arl">{{ $ar('الخصم') }}</span></th>
            <th class="r">Total<span class="arl">{{ $ar('المجموع') }}</span></th>
        </tr></thead>
        <tbody>
        @foreach ($s['items'] as $i)
            @php
                $primary = $leadAr && $i['name_ar'] ? $ar($i['name_ar']) : $i['name_en'];
                $second = $leadAr ? $i['name_en'] : $ar($i['name_ar']);
                $variant = $leadAr && ($i['variant_name_ar'] ?? null) ? $ar($i['variant_name_ar']) : ($i['variant_name_en'] ?? null);
            @endphp
            <tr>
                @php
                    $kind = $i['adjustment_kind'] ?? null;
                    $was = $kind === 'reduced' || $kind === 'replacement' ? (int) ($i['original_quantity'] ?? 0) : 0;
                    $replacesName = $leadAr ? ($i['replaces_name_ar'] ?? null) : ($i['replaces_name_en'] ?? null);
                @endphp
                <td><div class="name">{{ $primary }}</div>@if ($variant)<div class="muted">{{ $variant }}</div>@endif @if ($second && $second !== $primary)<div class="muted">{{ $second }}</div>@endif
                    @if ($kind === 'reduced' && $was > (int) $i['quantity'])<div class="small" style="color:#8a6a2f">Reduced from {{ $was }} <span class="arl">{{ $ar('كان '.$was) }}</span></div>@endif
                    @if ($kind === 'replacement' && $replacesName)<div class="small" style="color:#8a6a2f">Replaces: {{ $leadAr ? $ar($replacesName) : $replacesName }} <span class="arl">{{ $ar('بديل') }}</span></div>@endif</td>
                <td>{{ $i['sku'] }}</td><td class="r">{{ $i['quantity'] }}</td><td class="r">{{ $m($i['unit_final_price']) }}</td>
                <td class="r">{{ (float) $i['discount_amount'] > 0 ? $m($i['discount_amount']) : '—' }}</td><td class="r"><b>{{ $m($i['line_total']) }}</b></td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>{!! $lab('Subtotal', 'المجموع الفرعي') !!}</td><td class="r">{{ $m($o['subtotal']) }}</td></tr>
        @foreach ($s['discounts'] ?? [] as $d)
            @if ((float) ($d['discount_amount'] ?? 0) > 0)<tr><td>{{ $leadAr && ! empty($d['label_ar']) ? $ar($d['label_ar']) : ($d['label_en'] ?: $d['code']) }} <span class="muted">{{ $d['code'] }}</span></td><td class="r">−{{ $m($d['discount_amount']) }}</td></tr>@endif
        @endforeach
        @if ((float) $o['loyalty_discount'] > 0)<tr><td>{!! $lab('Loyalty points', 'نقاط الولاء') !!}</td><td class="r">−{{ $m($o['loyalty_discount']) }}</td></tr>@endif
        <tr><td>{!! $lab('Delivery', 'التوصيل') !!}</td><td class="r">{{ (float) $o['delivery_fee_total'] > 0 ? $m($o['delivery_fee_total']) : 'Free' }}</td></tr>
        @if ((float) ($s['extra_delivery_fee'] ?? 0) > 0)
            <tr><td class="muted small" style="padding-left:16px">{!! $lab('of which extra delivery fee for bulky items', 'منها رسوم توصيل إضافية للقطع الكبيرة') !!}</td><td class="r muted small">{{ $m($s['extra_delivery_fee']) }}</td></tr>
        @endif
        @if ((float) $o['service_fee_total'] > 0)<tr><td>{!! $lab('Service fees', 'رسوم الخدمة') !!}</td><td class="r">{{ $m($o['service_fee_total']) }}</td></tr>@endif
        <tr><td>{!! $lab('Tax ('.($o['tax_snapshot']['rate_percent'] ?? 0).'%)', 'الضريبة') !!}</td><td class="r">{{ $m($o['tax_total']) }}</td></tr>
        <tr class="grand"><td>{!! $lab('Total', 'الإجمالي') !!}</td><td class="r">{{ $m($o['grand_total']) }}</td></tr>
        @if ($changes && isset($changes[0]['before_total']))<tr><td class="muted small">{!! $lab('Total before the changes', 'الإجمالي قبل التعديلات') !!}</td><td class="r muted small" style="text-decoration: line-through">{{ $m($changes[0]['before_total']) }}</td></tr>@endif
        @if ((float) $o['refunded_total'] > 0)<tr><td>{!! $lab('Refunded', 'المبلغ المسترد') !!}</td><td class="r">−{{ $m($o['refunded_total']) }}</td></tr>@endif
    </table>

    @if ($changes)
        <div class="changes">
            <div class="tag" style="margin-bottom:7px">{!! $lab('Order changes', 'تعديلات الطلب') !!}</div>
            @foreach ($changes as $c)
                @php
                    $branchName = $leadAr && ($c['branch_name_ar'] ?? null) ? $ar($c['branch_name_ar']) : ($c['branch_name_en'] ?? '');
                    $reason = (string) __('adjustments.reason.'.$c['reason_code'], [], $lang);
                @endphp
                <div class="rv">
                    <table><tr>
                        <td class="head">{{ 'Revision '.$c['number'] }} <span class="arl">{{ $ar('التعديل '.$c['number']) }}</span> &nbsp;·&nbsp; {{ $when($c['at'] ?? null) }}@if ($branchName !== '') &nbsp;·&nbsp; {{ $branchName }}@endif</td>
                    </tr></table>
                    <ul>
                        @foreach ($c['lines'] as $l)
                            @php $sentence = \App\Domain\Orders\Services\AdjustmentText::fromArray($l, $lang); @endphp
                            <li>{{ $leadAr ? $ar($sentence) : $sentence }}</li>
                        @endforeach
                    </ul>
                    <div class="small muted">{!! $lab('Reason', 'السبب') !!}: {{ $leadAr ? $ar($reason) : $reason }}@if (! empty($c['note'])) &nbsp;·&nbsp; “{{ $leadAr ? $ar($c['note']) : $c['note'] }}”@endif</div>
                    <div class="small" style="margin-top:2px">{!! $lab('Total', 'الإجمالي') !!}: {{ $m($c['before_total']) }} &rarr; <b>{{ $m($c['after_total']) }}</b> <span class="minus">({{ $m($c['difference']) }})</span></div>
                </div>
            @endforeach
        </div>
    @endif

    <table class="note"><tr>
        <td>Thank you for shopping with {{ $s['company']['name_en'] }}.<br>@if ($phone || $email)<span class="muted">{{ trim($phone.($phone && $email ? '  ·  ' : '').$email) }}</span>@endif</td>
        <td class="r">{{ $ar('شكراً لتسوقكم من '.$s['company']['name_ar']) }}</td>
    </tr></table>
    <div class="foot">{{ $invoice->number }} · {{ $o['number'] }} · {{ $o['currency'] ?? 'QAR' }}</div>
</div>
</body></html>
