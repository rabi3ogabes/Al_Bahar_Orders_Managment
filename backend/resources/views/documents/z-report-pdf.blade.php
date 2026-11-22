@php
    /*
     * The Z-report of one closed cash shift as a PDF file (dompdf: tables, no flex, no grid). Every label shows both languages, the one asked for first and the other
     * one muted beside it (the look of the invoice and the picking list), so one file reads the same for the cashier and for an Arabic-speaking owner or auditor.
     * Arabic goes through PrintText (dompdf cannot join Arabic letters). Staff names and amounts only: nothing here names a customer.
     */
    use App\Domain\Orders\Printing\PrintText as T;
    use App\Domain\Payments\Services\CashShiftReport;
    use App\Domain\Payments\Services\CashShiftService;
    use App\Support\BusinessTime;
    use App\Support\Money;

    $lead = $lead === 'ar' ? 'ar' : 'en';
    $other = $lead === 'ar' ? 'en' : 'ar';
    // The Arabic is put in visual order, which also turns the brackets round: swap them back so "(" still opens.
    $shape = fn (?string $t, string $loc) => $loc === 'ar' ? strtr(T::shape($t), ['(' => ')', ')' => '(']) : (string) $t;
    // One label in both languages. $n picks the plural form of a choice key.
    $L = function (string $key, array $p = [], ?int $n = null) use ($lead, $other, $shape): string {
        $say = fn (string $loc): string => $n === null ? __($key, $p, $loc) : trans_choice($key, $n, $p + ['count' => $n], $loc);

        return '<span class="l1">'.e($shape($say($lead), $lead)).'</span> <span class="l2">'.e($shape($say($other), $other)).'</span>';
    };
    $m = fn ($v) => Money::of((string) $v)->format('en');
    $at = fn ($v, string $f = 'd M Y, H:i') => BusinessTime::display($v, $f);
    $name = fn (?string $n) => e(T::shape($n));
    $shift = $report['shift'];
    $s = $report['summary'];
    $branchEn = (string) $shift->branch?->name_en;
    $branchAr = (string) ($shift->branch?->name_ar ?? '');
    $minutes = (int) $shift->opened_at->diffInMinutes($shift->closed_at);
    $variance = $report['variance'];
    $count = fn (string $a, string $b = 'count') => (int) ($s[$a][$b] ?? 0);
@endphp
<!DOCTYPE html>
<html lang="{{ $lead }}"><head><meta charset="utf-8"><title>{{ __('cash_shift.z.print_title', ['z' => $report['z'], 'branch' => $shift->branch?->code], 'en') }}</title>
<style>
    @page { margin: 12mm 12mm 16mm; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { color: #14161c; font-size: 10px; margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    td, th { vertical-align: top; }
    .l1 { font-weight: bold; }
    .l2 { color: #7a6a4d; font-size: 8.5px; font-weight: normal; }
    .muted { color: #5f6575; }
    .r { text-align: right; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; }
    .head td { border: 0; padding: 0; vertical-align: middle; }
    .brand { font-size: 8.5px; letter-spacing: 1.2px; text-transform: uppercase; color: #8a7a5f; }
    .title { font-size: 18px; font-weight: bold; margin-top: 2px; }
    .z { font-size: 30px; font-weight: bold; text-align: right; }
    .stamp { display: inline-block; border: 2px solid #14161c; padding: 1px 9px; font-size: 10px; font-weight: bold; letter-spacing: 1px; }
    .stamp.copy { border-color: #9a4a00; color: #9a4a00; }
    .rule { border-top: 3px solid #14161c; border-bottom: 1px solid #b08d57; height: 2px; margin: 7px 0 8px; }
    h2 { font-size: 11px; margin: 14px 0 3px; border-bottom: 2px solid #14161c; padding-bottom: 2px; }
    h2 .l2 { font-size: 8.5px; }
    .meta td { padding: 0 8px 3px 0; font-size: 10px; }
    .list th { text-align: left; font-size: 7.5px; letter-spacing: .6px; text-transform: uppercase; color: #5f6575; border-bottom: 1.2px solid #14161c; padding: 3px 4px; vertical-align: bottom; }
    .list td { padding: 3.5px 4px; border-bottom: 1px solid #e3dccb; }
    .v { text-align: right; white-space: nowrap; font-weight: bold; }
    .sub td { color: #5f6575; font-size: 9px; }
    .total td { border-top: 2px solid #14161c; border-bottom: 0; font-size: 11.5px; font-weight: bold; padding-top: 5px; }
    .bad { color: #b3261e; } .warn { color: #9a4a00; } .ok { color: #1b6b3a; }
    .keep { page-break-inside: avoid; }
    .help { color: #6b7280; font-size: 8.5px; margin: 3px 0 0; }
    .note { margin-top: 6px; padding: 6px 8px; border: 1px solid #d9d2c3; background: #faf6ee; }
    .gone td { color: #8a8f9c; }
    .sign td { border: 0; padding: 0 12px 0 0; vertical-align: bottom; width: 50%; }
    .sign .line { border-bottom: 1px solid #14161c; height: 36px; }
    .foot { font-size: 8px; color: #5f6575; border-top: 1px solid #d9d2c3; padding-top: 4px; margin-top: 12px; }
</style></head>
<body>
<table class="head"><tr>
    <td style="width:64%">
        <div class="brand">{{ config('platform.brand.name_en') }} · {{ T::shape((string) config('platform.brand.name_ar')) }}</div>
        <div class="title">{!! $L('cash_shift.z.title') !!}</div>
        <div class="muted" style="margin-top:3px">{{ $branchEn }}@if ($branchAr !== '') · {{ T::shape($branchAr) }}@endif <span class="mono">({{ $shift->branch?->code }})</span></div>
    </td>
    <td>
        <div class="z mono">{{ $report['z'] }}</div>
        <div class="r" style="margin-top:4px"><span class="stamp {{ $copy ? 'copy' : '' }}" data-stamp="{{ $copy ? 'copy' : 'original' }}">{!! $copy ? $L('cash_shift.z.copy_n', ['n' => $copyNumber]) : $L('cash_shift.z.original') !!}</span></div>
    </td>
</tr></table>
<div class="rule"></div>

<table class="meta">
    <tr><td style="width:24%">{!! $L('petty_cash.pdf.opened') !!}</td><td>{{ $at($shift->opened_at) }}</td></tr>
    <tr><td>{!! $L('petty_cash.pdf.closed') !!}</td><td>{{ $at($shift->closed_at) }} · {{ __('cash_shift.z.duration', ['h' => intdiv($minutes, 60), 'm' => str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT)], 'en') }}</td></tr>
    <tr><td>{!! $L('cash_shift.cashier') !!}</td><td>{!! $name($shift->opener?->name) !!}</td></tr>
    @if ($shift->closed_by !== $shift->opened_by)<tr><td>{!! $L('petty_cash.pdf.closed_by') !!}</td><td>{!! $name($shift->closer?->name) !!}</td></tr>@endif
</table>

<h2>{!! $L('cash_shift.z.s_cash') !!}</h2>
<table class="list keep">
    <tr><td>{!! $L('cash_shift.l_float') !!}</td><td class="v">{{ $m($s['float'] ?? $report['float']->toDecimal()) }}</td></tr>
    <tr><td>+ {!! $L('cash_shift.l_received_n', [], $count('received', 'handins')) !!}</td><td class="v">{{ $m($s['received']['total'] ?? 0) }}</td></tr>
    <tr class="sub"><td style="padding-left:14px">{!! $L('cash_shift.src_cod') !!}</td><td class="v">{{ $m($s['received']['cod'] ?? 0) }}</td></tr>
    <tr class="sub"><td style="padding-left:14px">{!! $L('cash_shift.src_counter') !!}</td><td class="v">{{ $m($s['received']['counter'] ?? 0) }}</td></tr>
    @if (! Money::of((string) ($s['received']['difference'] ?? '0'))->isZero())
        <tr class="sub"><td style="padding-left:14px">{!! $L('cash_shift.src_difference') !!}</td><td class="v">{{ $m($s['received']['difference']) }}</td></tr>
    @endif
    <tr><td>− {!! $L('cash_shift.l_banked_n', [], $count('deposits')) !!}</td><td class="v">{{ $m($s['deposits']['amount'] ?? 0) }}</td></tr>
    <tr><td>− {!! $L('cash_shift.l_refunds_n', [], $count('refunds')) !!}</td><td class="v">{{ $m($s['refunds']['amount'] ?? 0) }}</td></tr>
    <tr><td>− {!! $L('petty_cash.l_payouts_n', [], $count('payouts')) !!}</td><td class="v">{{ $m($s['payouts']['amount'] ?? 0) }}</td></tr>
    <tr class="total"><td>{!! $L('cash_shift.expected_cash') !!}</td><td class="v">{{ $m($report['expected']->toDecimal()) }}</td></tr>
    <tr class="total"><td>{!! $L('cash_shift.z.counted') !!}</td><td class="v">{{ $m($report['counted']->toDecimal()) }}</td></tr>
    <tr class="total"><td>{!! $L('finance_admin.variance') !!}</td>
        <td class="v {{ $variance->isZero() ? 'ok' : ($variance->isNegative() ? 'bad' : 'warn') }}">{{ $variance->isZero() ? __('finance_admin.balanced', [], 'en') : $m($variance->toDecimal()) }}</td></tr>
</table>
<div class="help">{!! $L('cash_shift.z.h_cash') !!}</div>

<h2>{!! $L('cash_shift.z.s_handins') !!}</h2>
@if ($report['handins']->isEmpty())
    <div class="help">{!! $L('cash_shift.z.none') !!}</div>
@else
    <table class="list">
        <tr><th>{{ __('finance_admin.handover', [], 'en') }}</th><th>{{ __('cash_shift.z.from', [], 'en') }}</th><th class="r">{{ __('finance_admin.collections', [], 'en') }}</th><th class="r">{{ __('finance_admin.counted', [], 'en') }}</th><th class="r">{{ __('finance_admin.variance', [], 'en') }}</th></tr>
        @foreach ($report['handins'] as $h)
            <tr>
                <td class="mono">{{ $h->number }}</td><td>{!! $name($h->collector?->name) !!}</td>
                <td class="r">{{ $h->collections_count }}</td><td class="v">{{ $m($h->counted_amount) }}</td>
                <td class="v {{ $h->status === 'balanced' ? 'ok' : ($h->status === 'short' ? 'bad' : 'warn') }}">{{ $h->status === 'balanced' ? __('finance_admin.balanced', [], 'en') : $m($h->variance) }}</td>
            </tr>
        @endforeach
    </table>
    @if (! $report['listed_all'])<div class="help">{{ __('cash_shift.z.list_cut', ['n' => CashShiftReport::LIST_LIMIT], 'en') }}</div>@endif
@endif

<h2>{!! $L('cash_shift.z.s_deposits') !!}</h2>
@if ($report['deposits'] === [])
    <div class="help">{!! $L('cash_shift.z.none') !!}</div>
@else
    <table class="list">
        <tr><th>{{ __('cash_shift.z.reference', [], 'en') }}</th><th class="r">{{ __('cash_shift.z.handins', [], 'en') }}</th><th class="r">{{ __('finance_admin.amount', [], 'en') }}</th></tr>
        @foreach ($report['deposits'] as $d)
            <tr><td class="mono">{{ $d['reference'] }}</td><td class="r">{{ $d['handins'] }}</td><td class="v">{{ $d['amount']->format('en') }}</td></tr>
        @endforeach
    </table>
@endif

<h2>{!! $L('petty_cash.z.s_payouts') !!}</h2>
@if ($report['payouts']->isEmpty())
    <div class="help" data-payouts-none>{!! $L('petty_cash.z.none') !!}</div>
@else
    <table class="list" data-payouts>
        <tr><th>{{ __('petty_cash.z.time', [], 'en') }}</th><th>{{ __('petty_cash.z.reason', [], 'en') }}</th><th>{{ __('petty_cash.z.paid_by', [], 'en') }}</th><th>{{ __('petty_cash.z.approved_by', [], 'en') }}</th><th class="r">{{ __('petty_cash.z.amount', [], 'en') }}</th></tr>
        @foreach ($report['payouts'] as $p)
            <tr @class(['gone' => $p->isVoided()])>
                <td>{{ $at($p->created_at, 'd M, H:i') }}</td>
                <td>{{ __('petty_cash.reasons.'.$p->reason_code, [], 'en') }} <span class="l2">{{ T::shape(__('petty_cash.reasons.'.$p->reason_code, [], 'ar')) }}</span>@if ($p->note)<br><span class="muted">{!! e(T::shape($p->note)) !!}</span>@endif
                    @if ($p->isVoided())<br><strong>{!! $L('petty_cash.z.reversed') !!}</strong> · {!! $name($p->voider?->name) !!}: {!! e(T::shape($p->void_reason)) !!}@endif</td>
                <td>{!! $name($p->payer?->name) !!}</td><td>{!! $p->approver ? $name($p->approver->name) : '—' !!}</td>
                <td class="v" @if ($p->isVoided()) style="text-decoration:line-through" @endif>{{ $m($p->amount) }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="4">{!! $L('petty_cash.z.total') !!}</td><td class="v">{{ $m($s['payouts']['amount'] ?? 0) }}</td></tr>
    </table>
    @if (! $report['payouts_listed_all'])<div class="help">{{ __('petty_cash.z.list_cut', ['n' => CashShiftReport::LIST_LIMIT], 'en') }}</div>@endif
@endif

<h2>{!! $L('cash_shift.z.s_count') !!}</h2>
<table class="list keep">
    <tr><th>{{ __('cash_shift.z.note_value', [], 'en') }}</th><th class="r">{{ __('cash_shift.z.opening', [], 'en') }}</th><th class="r">{{ __('cash_shift.z.closing', [], 'en') }}</th><th class="r">{{ __('finance_admin.amount', [], 'en') }}</th></tr>
    @foreach (CashShiftService::NOTES as $value)
        @php $o = (int) ($report['opening']['notes'][$value] ?? 0); $c = (int) ($report['closing']['notes'][$value] ?? 0); @endphp
        <tr><td>{{ __('cash_shift.note_label', ['value' => $value], 'en') }}</td><td class="r">{{ $o }}</td><td class="r">{{ $c }}</td><td class="v">{{ Money::ofMinor($value * 100 * $c)->format('en') }}</td></tr>
    @endforeach
    <tr><td>{{ __('cash_shift.coins', [], 'en') }}</td><td class="r">{{ $report['opening']['coins']->format('en') }}</td><td class="r">{{ $report['closing']['coins']->format('en') }}</td><td class="v">{{ $report['closing']['coins']->format('en') }}</td></tr>
    <tr class="total"><td>{{ __('cash_shift.count_total', [], 'en') }}</td><td class="r">{{ $report['opening']['total']->format('en') }}</td><td></td><td class="v">{{ $report['closing']['total']->format('en') }}</td></tr>
</table>

<h2>{!! $L('cash_shift.z.s_reasons') !!}</h2>
<div class="note">
    <div><strong>{!! $L('cash_shift.reason') !!}:</strong> {!! $shift->variance_note ? e(T::shape($shift->variance_note)) : $L('cash_shift.z.no_reason') !!}</div>
    @if ($shift->variance_status === 'open')
        <div class="warn" style="margin-top:4px"><strong>{!! $L('cash_shift.z.review_waiting') !!}</strong></div>
    @elseif ($shift->variance_status === 'resolved')
        <div style="margin-top:4px"><strong>{{ __('cash_shift.z.reviewed_by', ['name' => (string) $shift->reviewer?->name, 'at' => $at($shift->variance_resolved_at)], 'en') }}:</strong> {!! e(T::shape($shift->variance_resolution)) !!}</div>
    @endif
</div>

<table class="sign" style="margin-top:18px"><tr>
    <td><div class="line"></div><div class="muted" style="font-size:8.5px">{!! $L('cash_shift.z.sign_cashier') !!} · {!! $name($shift->closer?->name) !!}</div></td>
    <td><div class="line"></div><div class="muted" style="font-size:8.5px">{!! $L('cash_shift.z.sign_reviewer') !!}</div></td>
</tr></table>

<div class="foot">{!! $L('cash_shift.z.privacy') !!} · {{ __('petty_cash.pdf.printed', ['by' => $printedBy, 'at' => $at($printedAt)], 'en') }}</div>
</body></html>
