@php
    use App\Domain\Payments\Services\CashShiftService;
    use App\Support\BusinessTime;
    use App\Support\Money;

    /** The Z-report of one closed cash shift. Staff names and amounts only: nothing here names a customer. */
    $locale = app()->getLocale();
    $rtl = in_array($locale, config('platform.rtl_locales', ['ar']), true);
    $money = fn ($v) => Money::of((string) $v)->format();
    $shift = $report['shift'];
    $s = $report['summary'];
    $brand = $locale === 'ar' ? config('platform.brand.name_ar') : config('platform.brand.name_en');
    $at = fn ($v) => BusinessTime::display($v, 'd M Y, H:i');
    $minutes = (int) $shift->opened_at->diffInMinutes($shift->closed_at);
    $variance = $report['variance'];
    $other = $locale === 'ar' ? 'en' : 'ar';
    $title = __('cash_shift.z.print_title', ['z' => $report['z'], 'branch' => $shift->branch?->code]);
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }}</title>
<style>
    * { box-sizing: border-box; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; background: #ece7df; color: #14161c; font: 13px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Arabic", Arial, sans-serif; }
    .bar { position: sticky; top: 0; z-index: 2; background: #fff; border-bottom: 1px solid #ddd4c4; padding: 10px 16px; display: flex; gap: 10px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
    .bar .acts { display: flex; gap: 8px; align-items: center; }
    .btn { min-height: 44px; display: inline-flex; align-items: center; padding: 0 18px; border-radius: 10px; border: 1px solid #1a1d26; background: #1a1d26; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; }
    .btn.alt { background: #fff; color: #1a1d26; }
    .btn:focus-visible { outline: 3px solid #b08d57; outline-offset: 2px; }
    .page { position: relative; width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 14mm 14mm 12mm; box-shadow: 0 4px 20px rgba(0,0,0,.12); overflow: hidden; }
    h1 { font-size: 20px; margin: 0; } h2 { font-size: 14px; margin: 16px 0 4px; border-bottom: 2px solid #1a1d26; padding-bottom: 3px; }
    .meta { color: #5f6575; font-size: 12px; margin: 2px 0 0; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 1px solid #ddd4c4; padding-bottom: 8px; }
    .z { font-size: 34px; font-weight: 800; direction: ltr; unicode-bidi: isolate; line-height: 1; text-align: end; }
    .stamp { display: inline-block; margin-top: 6px; border: 2px solid #1a1d26; border-radius: 6px; padding: 1px 10px; font-weight: 800; font-size: 12px; letter-spacing: .08em; }
    .stamp.copy { border-color: #9a4a00; color: #9a4a00; }
    .mark { position: absolute; inset: 0; display: grid; place-items: center; pointer-events: none; z-index: 0; }
    .mark span { font-size: 150px; font-weight: 900; color: rgba(154,74,0,.09); transform: rotate(-28deg); letter-spacing: .1em; }
    .page > *:not(.mark) { position: relative; z-index: 1; }
    table { width: 100%; border-collapse: collapse; } td, th { padding: 4px 0; border-bottom: 1px solid #eee7d8; vertical-align: top; text-align: start; }
    th { font-size: 11px; color: #5f6575; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
    td.v, th.v { text-align: end; font-variant-numeric: tabular-nums; direction: ltr; unicode-bidi: isolate; white-space: nowrap; }
    td.v { font-weight: 600; } td.sub { padding-inline-start: 14px; color: #5f6575; font-size: 12px; } td.sub.v { font-weight: 500; }
    tr.total td { border-top: 2px solid #1a1d26; border-bottom: 0; font-size: 15px; font-weight: 800; padding-top: 6px; }
    .warn { color: #9a4a00; } .bad { color: #b3261e; } .ok { color: #1b6b3a; }
    .help { color: #6b7280; font-size: 11px; margin: 3px 0 0; }
    .note { margin-top: 8px; padding: 8px 10px; background: #faf6ee; border-radius: 8px; }
    .two { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
    .sign { display: flex; gap: 24px; margin-top: 26px; } .sign div { flex: 1; border-top: 1px solid #14161c; padding-top: 4px; font-size: 11px; color: #5f6575; min-height: 44px; }
    @media (max-width: 800px) { .page { width: auto; min-height: 0; margin: 0; padding: 14px; box-shadow: none; } .two { grid-template-columns: 1fr; } .z { font-size: 26px; } }
    @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0; } @page { size: A4; margin: 12mm; } }
</style>
</head>
<body>
<div class="bar">
    <strong>{{ $title }}</strong>
    <div class="acts">
        <a class="btn alt" href="{{ request()->fullUrlWithQuery(['lang' => $other]) }}" hreflang="{{ $other }}" lang="{{ $other }}">{{ $other === 'ar' ? 'العربية' : 'English' }}</a>
        <button type="button" class="btn" onclick="window.print()">{{ __('cash_shift.z.print') }}</button>
    </div>
</div>
<main class="page">
    @if ($copy)<div class="mark" aria-hidden="true"><span>{{ __('cash_shift.z.copy') }}</span></div>@endif
    <div class="head">
        <div>
            <h1>{{ $brand }} · {{ __('cash_shift.z.title') }}</h1>
            <p class="meta">{{ $shift->branch?->localized('name') }} ({{ $shift->branch?->code }})</p>
            <p class="meta">{{ __('cash_shift.z.period', ['from' => $at($shift->opened_at), 'to' => $at($shift->closed_at)]) }} · {{ __('cash_shift.z.duration', ['h' => intdiv($minutes, 60), 'm' => str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT)]) }}</p>
            <p class="meta">{{ __('cash_shift.z.cashier', ['name' => $shift->opener?->name]) }}@if ($shift->closed_by !== $shift->opened_by) · {{ __('cash_shift.z.closed_by', ['name' => $shift->closer?->name]) }}@endif</p>
        </div>
        <div>
            <div class="z">{{ $report['z'] }}</div>
            <div style="text-align:end"><span class="stamp {{ $copy ? 'copy' : '' }}">{{ $copy ? __('cash_shift.z.copy_n', ['n' => $copyNumber]) : __('cash_shift.z.original') }}</span></div>
        </div>
    </div>

    <h2>{{ __('cash_shift.z.s_cash') }}</h2>
    <table>
        <tr><td>{{ __('cash_shift.l_float') }}</td><td class="v">{{ $money($s['float'] ?? $report['float']->toDecimal()) }}</td></tr>
        <tr><td>+ {{ trans_choice('cash_shift.l_received_n', (int) ($s['received']['handins'] ?? 0), ['count' => (int) ($s['received']['handins'] ?? 0)]) }}</td><td class="v">{{ $money($s['received']['total'] ?? 0) }}</td></tr>
        <tr><td class="sub">{{ __('cash_shift.src_cod') }}</td><td class="v sub">{{ $money($s['received']['cod'] ?? 0) }}</td></tr>
        <tr><td class="sub">{{ __('cash_shift.src_counter') }}</td><td class="v sub">{{ $money($s['received']['counter'] ?? 0) }}</td></tr>
        @if (! Money::of((string) ($s['received']['difference'] ?? '0'))->isZero())
            <tr><td class="sub">{{ __('cash_shift.src_difference') }}</td><td class="v sub">{{ $money($s['received']['difference']) }}</td></tr>
        @endif
        <tr><td>− {{ trans_choice('cash_shift.l_banked_n', (int) ($s['deposits']['count'] ?? 0), ['count' => (int) ($s['deposits']['count'] ?? 0)]) }}</td><td class="v">{{ $money($s['deposits']['amount'] ?? 0) }}</td></tr>
        <tr><td>− {{ trans_choice('cash_shift.l_refunds_n', (int) ($s['refunds']['count'] ?? 0), ['count' => (int) ($s['refunds']['count'] ?? 0)]) }}</td><td class="v">{{ $money($s['refunds']['amount'] ?? 0) }}</td></tr>
        <tr><td>− {{ trans_choice('petty_cash.l_payouts_n', (int) ($s['payouts']['count'] ?? 0), ['count' => (int) ($s['payouts']['count'] ?? 0)]) }}</td><td class="v">{{ $money($s['payouts']['amount'] ?? 0) }}</td></tr>
        <tr class="total"><td>{{ __('cash_shift.expected_cash') }}</td><td class="v">{{ $money($report['expected']->toDecimal()) }}</td></tr>
        <tr class="total"><td>{{ __('cash_shift.z.counted') }}</td><td class="v">{{ $money($report['counted']->toDecimal()) }}</td></tr>
        <tr class="total"><td>{{ __('finance_admin.variance') }}</td>
            <td class="v {{ $variance->isZero() ? 'ok' : ($variance->isNegative() ? 'bad' : 'warn') }}">{{ $variance->isZero() ? __('finance_admin.balanced') : $variance->format() }}</td></tr>
    </table>
    <p class="help">{{ __('cash_shift.z.h_cash') }}</p>

    <h2>{{ __('cash_shift.z.s_handins') }}</h2>
    @if ($report['handins']->isEmpty())
        <p class="help">{{ __('cash_shift.z.none') }}</p>
    @else
        <table>
            <tr><th>{{ __('finance_admin.handover') }}</th><th>{{ __('cash_shift.z.from') }}</th><th class="v">{{ __('finance_admin.collections') }}</th><th class="v">{{ __('finance_admin.counted') }}</th><th class="v">{{ __('finance_admin.variance') }}</th></tr>
            @foreach ($report['handins'] as $h)
                <tr>
                    <td dir="ltr" style="text-align:start">{{ $h->number }}</td><td><bdi>{{ $h->collector?->name }}</bdi></td>
                    <td class="v">{{ $h->collections_count }}</td><td class="v">{{ $money($h->counted_amount) }}</td>
                    <td class="v {{ $h->status === 'balanced' ? 'ok' : ($h->status === 'short' ? 'bad' : 'warn') }}">{{ $h->status === 'balanced' ? __('finance_admin.balanced') : $money($h->variance) }}</td>
                </tr>
            @endforeach
        </table>
        @if (! $report['listed_all'])<p class="help">{{ __('cash_shift.z.list_cut', ['n' => \App\Domain\Payments\Services\CashShiftReport::LIST_LIMIT]) }}</p>@endif
    @endif

    <h2>{{ __('cash_shift.z.s_deposits') }}</h2>
    @if ($report['deposits'] === [])
        <p class="help">{{ __('cash_shift.z.none') }}</p>
    @else
        <table>
            <tr><th>{{ __('cash_shift.z.reference') }}</th><th class="v">{{ __('cash_shift.z.handins') }}</th><th class="v">{{ __('finance_admin.amount') }}</th></tr>
            @foreach ($report['deposits'] as $d)
                <tr><td dir="ltr" style="text-align:start">{{ $d['reference'] }}</td><td class="v">{{ $d['handins'] }}</td><td class="v">{{ $d['amount']->format() }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('petty_cash.z.s_payouts') }}</h2>
    @if ($report['payouts']->isEmpty())
        <p class="help">{{ __('petty_cash.z.none') }}</p>
    @else
        <table data-payouts>
            <tr><th>{{ __('petty_cash.z.time') }}</th><th>{{ __('petty_cash.z.reason') }}</th><th>{{ __('petty_cash.z.paid_by') }}</th><th>{{ __('petty_cash.z.approved_by') }}</th><th class="v">{{ __('petty_cash.z.amount') }}</th></tr>
            @foreach ($report['payouts'] as $p)
                <tr @if ($p->isVoided()) style="color:#8a8f9c" @endif>
                    <td class="v" style="text-align:start">{{ BusinessTime::display($p->created_at, 'd M, H:i') }}</td>
                    <td>{{ __('petty_cash.reasons.'.$p->reason_code) }}@if ($p->note) · {{ $p->note }}@endif
                        @if ($p->isVoided())<br><strong>{{ __('petty_cash.z.reversed') }}</strong> · {{ __('petty_cash.z.reversed_note', ['name' => $p->voider?->name, 'reason' => $p->void_reason]) }}@endif</td>
                    <td><bdi>{{ $p->payer?->name }}</bdi></td><td><bdi>{{ $p->approver?->name ?? '—' }}</bdi></td>
                    <td class="v" @if ($p->isVoided()) style="text-decoration:line-through" @endif>{{ $money($p->amount) }}</td>
                </tr>
            @endforeach
            <tr class="total"><td colspan="4">{{ __('petty_cash.z.total') }}</td><td class="v">{{ $money($s['payouts']['amount'] ?? 0) }}</td></tr>
        </table>
        @if (! $report['payouts_listed_all'])<p class="help">{{ __('petty_cash.z.list_cut', ['n' => \App\Domain\Payments\Services\CashShiftReport::LIST_LIMIT]) }}</p>@endif
    @endif

    <h2>{{ __('cash_shift.z.s_count') }}</h2>
    <table>
        <tr><th>{{ __('cash_shift.z.note_value') }}</th><th class="v">{{ __('cash_shift.z.opening') }}</th><th class="v">{{ __('cash_shift.z.closing') }}</th><th class="v">{{ __('finance_admin.amount') }}</th></tr>
        @foreach (CashShiftService::NOTES as $value)
            @php $o = (int) ($report['opening']['notes'][$value] ?? 0); $c = (int) ($report['closing']['notes'][$value] ?? 0); @endphp
            <tr><td dir="ltr" style="text-align:start">{{ __('cash_shift.note_label', ['value' => $value]) }}</td><td class="v">{{ $o }}</td><td class="v">{{ $c }}</td><td class="v">{{ Money::ofMinor($value * 100 * $c)->format() }}</td></tr>
        @endforeach
        <tr><td>{{ __('cash_shift.coins') }}</td><td class="v">{{ $report['opening']['coins']->format() }}</td><td class="v">{{ $report['closing']['coins']->format() }}</td><td class="v">{{ $report['closing']['coins']->format() }}</td></tr>
        <tr class="total"><td>{{ __('cash_shift.count_total') }}</td><td class="v">{{ $report['opening']['total']->format() }}</td><td class="v"></td><td class="v">{{ $report['closing']['total']->format() }}</td></tr>
    </table>

    <h2>{{ __('cash_shift.z.s_reasons') }}</h2>
    <div class="note">
        <p style="margin:0"><strong>{{ __('cash_shift.reason') }}:</strong> {{ $shift->variance_note ?: __('cash_shift.z.no_reason') }}</p>
        @if ($shift->variance_status === 'open')
            <p style="margin:6px 0 0" class="warn"><strong>{{ __('cash_shift.z.review_waiting') }}</strong></p>
        @elseif ($shift->variance_status === 'resolved')
            <p style="margin:6px 0 0"><strong>{{ __('cash_shift.z.reviewed_by', ['name' => $shift->reviewer?->name, 'at' => $at($shift->variance_resolved_at)]) }}:</strong> {{ $shift->variance_resolution }}</p>
        @endif
    </div>

    <div class="sign"><div>{{ __('cash_shift.z.sign_cashier') }} · <bdi>{{ $shift->closer?->name }}</bdi></div><div>{{ __('cash_shift.z.sign_reviewer') }}</div></div>
    <p class="help" style="margin-top:12px">{{ __('cash_shift.z.printed', ['by' => $printedBy, 'at' => $at($printedAt)]) }} · {{ __('cash_shift.z.privacy') }}</p>
</main>
</body>
</html>
