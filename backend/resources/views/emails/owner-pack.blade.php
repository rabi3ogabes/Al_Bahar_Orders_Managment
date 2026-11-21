@php
    // E-mail clients ignore most CSS: every style is inline, the layout is tables, colours always travel with a word or an arrow.
    $tone = ['good' => '#1f7a4d', 'bad' => '#b3261e', 'flat' => '#6b7280', 'none' => '#6b7280'];
    $start = $pack['dir'] === 'rtl' ? 'right' : 'left';
    $end = $pack['dir'] === 'rtl' ? 'left' : 'right';
@endphp
<!DOCTYPE html>
<html lang="{{ $pack['lang'] }}" dir="{{ $pack['dir'] }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>{{ $pack['title'] }}</title></head>
<body style="margin:0;padding:0;background:#f4efe6;font-family:-apple-system,'Segoe UI',Roboto,'Noto Sans','Noto Sans Arabic',Arial,sans-serif;color:#1a1d26;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4efe6;"><tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="width:100%;max-width:640px;background:#ffffff;border-radius:16px;overflow:hidden;">
    <tr><td style="background:#1a1d26;padding:28px 28px 24px;text-align:{{ $start }};">
        <div style="font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#d8b97a;">{{ $pack['period'] }}</div>
        <div style="margin-top:6px;font-size:26px;line-height:1.25;font-weight:700;color:#f8f4ec;">{{ $pack['title'] }}</div>
        <div style="margin-top:6px;font-size:13px;color:#b5b9c3;">{{ $pack['versus'] }}</div>
    </td></tr>

    <tr><td style="padding:24px 28px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
            @foreach (array_chunk($pack['headline'], 2) as $pair)
                <tr>
                    @foreach ($pair as $h)
                        <td width="50%" valign="top" style="padding:0 0 12px;text-align:{{ $start }};">
                            <div style="background:#faf6ee;border-radius:12px;padding:14px 16px;margin:0 {{ $loop->first ? '6px' : '0' }} 0 {{ $loop->first ? '0' : '6px' }};">
                                <div style="font-size:12px;color:#5f6575;">{{ $h['label'] }}</div>
                                <div style="margin-top:4px;font-size:22px;font-weight:700;direction:ltr;text-align:{{ $start }};">{{ $h['value'] }}</div>
                                @if ($h['change'])<div style="margin-top:2px;font-size:12px;font-weight:600;color:{{ $tone[$h['tone']] }};direction:ltr;text-align:{{ $start }};">{{ $h['change'] }}</div>@endif
                            </div>
                        </td>
                    @endforeach
                    @if (count($pair) === 1)<td width="50%"></td>@endif
                </tr>
            @endforeach
        </table>
    </td></tr>

    <tr><td style="padding:8px 28px 8px;">
        <div style="font-size:16px;font-weight:700;margin-bottom:8px;text-align:{{ $start }};">{{ __('owner_pack.branches_title', [], $pack['lang']) }}</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:12px;">
            <tr>
                <th style="padding:8px 6px;text-align:{{ $start }};border-bottom:2px solid #1a1d26;"></th>
                @foreach ($pack['columns'] as $col)<th style="padding:8px 6px;text-align:{{ $end }};border-bottom:2px solid #1a1d26;font-weight:600;color:#5f6575;">{{ $col['label'] }}</th>@endforeach
            </tr>
            @foreach ($pack['rows'] as $row)
                <tr>
                    <td style="padding:8px 6px;border-bottom:1px solid #eadfcc;text-align:{{ $start }};{{ $row['total'] ? 'font-weight:700;' : '' }}">{{ $row['name'] }}</td>
                    @foreach ($row['cells'] as $cell)
                        <td style="padding:8px 6px;border-bottom:1px solid #eadfcc;text-align:{{ $end }};direction:ltr;{{ $row['total'] ? 'font-weight:700;' : '' }}">
                            {{ $cell['value'] }}@if ($cell['change'])<div style="font-size:11px;font-weight:600;color:{{ $tone[$cell['tone']] }};">{{ $cell['change'] }}</div>@endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </td></tr>

    @foreach ([['top_units_title', 'top_units'], ['top_revenue_title', 'top_revenue']] as [$titleKey, $listKey])
        <tr><td style="padding:16px 28px 0;">
            <div style="font-size:16px;font-weight:700;margin-bottom:8px;text-align:{{ $start }};">{{ $pack[$titleKey] }}</div>
            @if ($pack[$listKey] === [])
                <div style="font-size:13px;color:#5f6575;text-align:{{ $start }};">{{ $pack['top_empty'] }}</div>
            @else
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-size:13px;">
                    @foreach ($pack[$listKey] as $item)
                        <tr>
                            <td style="padding:7px 6px;border-bottom:1px solid #eadfcc;width:28px;color:#8a7a55;font-weight:700;text-align:{{ $start }};">{{ $item['rank'] }}</td>
                            <td style="padding:7px 6px;border-bottom:1px solid #eadfcc;text-align:{{ $start }};">{{ $item['name'] }}</td>
                            <td style="padding:7px 6px;border-bottom:1px solid #eadfcc;text-align:{{ $end }};direction:ltr;white-space:nowrap;">{{ $item['units'] }}</td>
                            <td style="padding:7px 6px;border-bottom:1px solid #eadfcc;text-align:{{ $end }};direction:ltr;white-space:nowrap;">{{ $item['revenue'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </td></tr>
    @endforeach

    <tr><td style="padding:20px 28px 8px;">
        <div style="background:#faf6ee;border-radius:12px;padding:16px 18px;text-align:{{ $start }};">
            <div style="font-size:16px;font-weight:700;margin-bottom:8px;">{{ $pack['attention_title'] }}</div>
            @if ($pack['attention'] === [])
                <div style="font-size:13px;color:#1f7a4d;font-weight:600;">{{ $pack['attention_clear'] }}</div>
            @else
                <ul style="margin:0;padding-{{ $start }}:18px;font-size:13px;line-height:1.6;">
                    @foreach ($pack['attention'] as $line)<li>{{ $line }}</li>@endforeach
                </ul>
            @endif
        </div>
    </td></tr>

    <tr><td style="padding:16px 28px 28px;font-size:11px;line-height:1.6;color:#6b7280;text-align:{{ $start }};">
        @foreach ($pack['definitions'] as $line)<div>{{ $line }}</div>@endforeach
        <div style="margin-top:10px;">{{ $pack['footer'] }}</div>
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
