@php
    $o = $s['order'];
    $m = fn ($v) => \App\Support\Money::of((string) $v)->format('en');
    $ar = fn (?string $t) => $t ? \App\Support\ArabicShaper::shape($t) : '';
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<style>
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { color: #14161c; font-size: 11px; margin: 0; }
    .brand { background: #14161c; color: #faf7f2; padding: 22px 28px; }
    .brand h1 { margin: 0; font-size: 20px; letter-spacing: .5px; }
    .brand .ar { float: right; font-size: 16px; }
    .gold { color: #b08d57; }
    .wrap { padding: 22px 28px; }
    table { width: 100%; border-collapse: collapse; }
    th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #5f6575; border-bottom: 1px solid #e2d6c3; padding: 8px 6px; }
    td { padding: 8px 6px; border-bottom: 1px solid #efe7da; vertical-align: top; }
    .r { text-align: right; } .muted { color: #5f6575; } .tot td { border: 0; padding: 4px 6px; } .grand td { font-size: 14px; font-weight: bold; border-top: 2px solid #14161c; }
    .meta td { border: 0; padding: 2px 0; }
</style></head>
<body>
<div class="brand"><span class="ar">{{ $ar($s['company']['name_ar']) }}</span><h1>{{ $s['company']['name_en'] }}</h1><div class="gold">TAX INVOICE · {{ $ar('فاتورة') }}</div></div>
<div class="wrap">
    <table class="meta"><tr>
        <td style="width:50%">
            <div class="muted">Invoice</div><div><b>{{ $invoice->number }}</b></div>
            <div class="muted" style="margin-top:6px">Order</div><div>{{ $o['number'] }} · {{ \App\Support\BusinessTime::local($o['placed_at'])?->format('d M Y H:i') }}</div>
            <div class="muted" style="margin-top:6px">Payment</div><div>{{ strtoupper(str_replace('_', ' ', $o['payment_method'])) }} — {{ $o['payment_status'] }}</div>
        </td>
        <td style="width:50%" class="r">
            <div class="muted">Billed to</div><div><b>{{ $o['customer_name'] }}</b></div><div>{{ $o['customer_phone'] }}</div><div>{{ $o['customer_email'] }}</div>
            @if (! empty($o['address_snapshot']['recipient_name']))
                <div style="margin-top:6px">{{ collect([$o['address_snapshot']['unit_number'] ?? null, $o['address_snapshot']['building_number'] ?? null, $o['address_snapshot']['street_number'] ?? null, isset($o['address_snapshot']['zone_number']) ? 'Zone '.$o['address_snapshot']['zone_number'] : null, $o['address_snapshot']['area'] ?? null])->filter()->implode(', ') }}</div>
            @endif
            <div class="muted" style="margin-top:6px">Fulfilled by</div>
            @foreach ($s['branches'] as $b)<div>{{ $b['number'] }} — {{ $b['name_en'] }}</div>@endforeach
        </td>
    </tr></table>
    <table style="margin-top:18px">
        <thead><tr><th>Item</th><th>SKU</th><th class="r">Qty</th><th class="r">Unit</th><th class="r">Discount</th><th class="r">Total</th></tr></thead>
        <tbody>
        @foreach ($s['items'] as $i)
            <tr><td><b>{{ $i['name_en'] }}</b>@if($i['variant_name_en'])<div class="muted">{{ $i['variant_name_en'] }}</div>@endif<div class="muted">{{ $ar($i['name_ar']) }}</div></td>
                <td>{{ $i['sku'] }}</td><td class="r">{{ $i['quantity'] }}</td><td class="r">{{ $m($i['unit_final_price']) }}</td><td class="r">{{ $m($i['discount_amount']) }}</td><td class="r">{{ $m($i['line_total']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    <table style="margin-top:14px; width: 45%; margin-left: 55%">
        <tr class="tot"><td>Subtotal</td><td class="r">{{ $m($o['subtotal']) }}</td></tr>
        @if ((float) $o['discount_total'] > 0)<tr class="tot"><td>Discounts</td><td class="r">−{{ $m($o['discount_total']) }}</td></tr>@endif
        <tr class="tot"><td>Delivery</td><td class="r">{{ $m($o['delivery_fee_total']) }}</td></tr>
        @if ((float) $o['service_fee_total'] > 0)<tr class="tot"><td>Service fees</td><td class="r">{{ $m($o['service_fee_total']) }}</td></tr>@endif
        @if ((float) $o['loyalty_discount'] > 0)<tr class="tot"><td>Loyalty points</td><td class="r">−{{ $m($o['loyalty_discount']) }}</td></tr>@endif
        <tr class="tot"><td>Tax ({{ $o['tax_snapshot']['rate_percent'] ?? 0 }}%)</td><td class="r">{{ $m($o['tax_total']) }}</td></tr>
        <tr class="tot grand"><td>Total</td><td class="r">{{ $m($o['grand_total']) }}</td></tr>
        @if ((float) $o['refunded_total'] > 0)<tr class="tot"><td>Refunded</td><td class="r">−{{ $m($o['refunded_total']) }}</td></tr>@endif
    </table>
    <p class="muted" style="margin-top:30px">Issued {{ \App\Support\BusinessTime::local($invoice->issued_at)?->format('d M Y H:i') }} (Asia/Qatar). Thank you for shopping with us.</p>
</div>
</body></html>
