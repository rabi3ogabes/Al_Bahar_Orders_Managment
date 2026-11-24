@php
    use App\Support\BusinessTime;
    use App\Support\Money;
    use Carbon\CarbonImmutable;

    $locale = app()->getLocale();
    $rtl = in_array($locale, config('platform.rtl_locales', ['ar']), true);
    $money = fn ($v) => Money::of((string) $v)->format();
    $day = CarbonImmutable::parse($date)->locale($locale)->translatedFormat('l d M Y');
    $brand = $locale === 'ar' ? config('platform.brand.name_ar') : config('platform.brand.name_en');
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('daily_close.print_title', ['date' => $day]) }}</title>
<style>
    * { box-sizing: border-box; }
    html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { margin: 0; background: #ece7df; color: #14161c; font: 13px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans", "Noto Sans Arabic", Arial, sans-serif; }
    .bar { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #ddd4c4; padding: 10px 16px; display: flex; gap: 10px; align-items: center; justify-content: space-between; }
    .btn { min-height: 44px; display: inline-flex; align-items: center; padding: 0 18px; border-radius: 10px; border: 1px solid #1a1d26; background: #1a1d26; color: #fff; font-size: 14px; font-weight: 600; cursor: pointer; }
    .btn:focus-visible { outline: 3px solid #b08d57; outline-offset: 2px; }
    .page { width: 210mm; min-height: 297mm; margin: 16px auto; background: #fff; padding: 14mm 14mm 12mm; box-shadow: 0 4px 20px rgba(0,0,0,.12); page-break-after: always; }
    h1 { font-size: 20px; margin: 0; } h2 { font-size: 14px; margin: 14px 0 4px; border-bottom: 2px solid #1a1d26; padding-bottom: 3px; }
    .meta { color: #5f6575; font-size: 12px; margin-top: 2px; }
    .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; border-bottom: 1px solid #ddd4c4; padding-bottom: 8px; }
    .big { font-size: 24px; font-weight: 700; direction: ltr; unicode-bidi: isolate; }
    table { width: 100%; border-collapse: collapse; } td { padding: 4px 0; border-bottom: 1px solid #eee7d8; vertical-align: top; }
    td.v { text-align: end; font-variant-numeric: tabular-nums; direction: ltr; unicode-bidi: isolate; white-space: nowrap; font-weight: 600; } td.warn { color: #9a4a00; }
    .help { color: #6b7280; font-size: 11px; margin: 2px 0 0; }
    .state { display: inline-block; border: 1px solid #1a1d26; border-radius: 99px; padding: 1px 10px; font-weight: 600; font-size: 12px; }
    .sign { display: flex; gap: 24px; margin-top: 22px; } .sign div { flex: 1; border-top: 1px solid #14161c; padding-top: 4px; font-size: 11px; color: #5f6575; min-height: 40px; }
    .note { margin-top: 10px; padding: 8px 10px; background: #faf6ee; border-radius: 8px; }
    @media print { body { background: #fff; } .bar { display: none; } .page { margin: 0; box-shadow: none; width: auto; min-height: 0; padding: 0; } @page { size: A4; margin: 12mm; } }
</style>
</head>
<body>
<div class="bar">
    <strong>{{ __('daily_close.print_title', ['date' => $day]) }}</strong>
    <button type="button" class="btn" onclick="window.print()">{{ __('daily_close.print') }}</button>
</div>
@foreach ($board as $b)
    @php
        $n = $b['numbers'];
        $word = ['closed' => __('daily_close.state_closed'), 'ready' => __('daily_close.state_ready'), 'in_progress' => __('daily_close.state_open')][$b['state']];
        $mix = $n['payment_mix'];
        $sections = [
            'orders' => [
                [__('daily_close.l_placed'), number_format($n['orders']['placed'])], [__('daily_close.l_delivered'), number_format($n['orders']['delivered'])],
                [__('daily_close.l_cancelled'), number_format($n['orders']['cancelled'])], [__('daily_close.l_returned'), number_format($n['orders']['returned'])],
                [__('daily_close.l_open'), number_format($n['open']['open'])], [__('daily_close.l_never_accepted'), number_format($n['open']['never_accepted']), $n['open']['never_accepted'] > 0],
            ],
            'sales' => [
                [__('daily_close.l_sales'), $money($n['sales']['total'])], [__('daily_close.l_basket'), $money($n['sales']['average'])],
                [__('daily_close.l_mix_cod'), $money($mix['cod']['amount']).' · '.$mix['cod']['orders']], [__('daily_close.l_mix_sadad'), $money($mix['sadad']['amount']).' · '.$mix['sadad']['orders']],
                [__('daily_close.l_mix_pickup'), $money($mix['pay_at_pickup']['amount']).' · '.$mix['pay_at_pickup']['orders']],
            ],
            'cash' => [
                [__('daily_close.l_cash_collected'), $money($n['cash']['collected'])], [__('daily_close.l_cash_handed_in'), $money($n['cash']['handed_in'])],
                [__('daily_close.l_cash_with'), $money($n['cash']['with_collectors']), ! Money::of($n['cash']['with_collectors'])->isZero()],
                [__('daily_close.l_cash_out_total'), $money($n['cash']['still_out_total'])],
                [__('daily_close.l_cash_variance'), $money($n['cash']['variance']), ! Money::of($n['cash']['variance'])->isZero()],
                [__('daily_close.l_cash_open_variances'), number_format($n['cash']['open_variances']), $n['cash']['open_variances'] > 0],
            ],
            'problems' => [[__('daily_close.l_refunds'), $money($n['refunds']['amount']).' · '.$n['refunds']['count']], [__('daily_close.l_failed'), number_format($n['failed_deliveries'])]],
            'assembly' => [
                [__('daily_close.l_assembly_net'), isset($n['assembly']) ? $money($n['assembly']['net']) : '—'], [__('daily_close.l_assembly_jobs'), isset($n['assembly']) ? number_format($n['assembly']['jobs']) : '—'],
                [__('daily_close.l_assembly_not_done'), isset($n['assembly']) ? number_format($n['assembly']['not_done']) : '—', ($n['assembly']['not_done'] ?? 0) > 0],
            ],
            'speed' => [
                [__('daily_close.l_accept'), $n['speed']['accept_minutes'] === null ? __('daily_close.none') : __('daily_close.minutes', ['n' => $n['speed']['accept_minutes']])],
                [__('daily_close.l_prepare'), $n['speed']['prepare_minutes'] === null ? __('daily_close.none') : __('daily_close.minutes', ['n' => $n['speed']['prepare_minutes']])],
            ],
            'stock' => [[__('daily_close.l_stock_out'), number_format($n['stock']['out'])], [__('daily_close.l_stock_low'), number_format($n['stock']['low'])]],
        ];
    @endphp
    <section class="page">
        <div class="head">
            <div>
                <h1>{{ $brand }} · {{ __('daily_close.title') }}</h1>
                <p class="meta">{{ $b['branch']->localized('name') }} ({{ $b['branch']->code }}) · {{ $day }}</p>
                <p class="meta"><span class="state">{{ $word }}</span>
                    @if ($b['state'] === 'closed') {{ __('daily_close.closed_by', ['name' => $b['close']->closer?->name ?? '—', 'at' => BusinessTime::display($b['close']->closed_at, 'd M Y, H:i')]) }}@endif</p>
            </div>
            <div class="big">{{ $money($n['sales']['total']) }}</div>
        </div>
        @foreach ($sections as $key => $lines)
            <h2>{{ __('daily_close.s_'.$key) }}</h2>
            <table>@foreach ($lines as $line)<tr><td>{{ $line[0] }}</td><td class="v {{ ($line[2] ?? false) ? 'warn' : '' }}">{{ ($line[2] ?? false) ? '! ' : '' }}{{ $line[1] }}</td></tr>@endforeach</table>
            <p class="help">{{ __('daily_close.h_'.$key) }}</p>
        @endforeach
        @if ($b['state'] === 'closed')<div class="note"><strong>{{ __('daily_close.note') }}:</strong> {{ $b['close']->note ?: __('daily_close.no_note') }}</div>@endif
        <div class="sign"><div>{{ __('daily_close.sign_manager') }}</div><div>{{ __('daily_close.sign_owner') }}</div></div>
        <p class="help" style="margin-top:12px">{{ __('daily_close.printed', ['by' => $printedBy, 'at' => BusinessTime::display($printedAt, 'd M Y, H:i')]) }}</p>
    </section>
@endforeach
</body>
</html>
