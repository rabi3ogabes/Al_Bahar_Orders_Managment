<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $locale === 'ar' ? 'rtl' : 'ltr' }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>{{ $locale === 'ar' ? 'جارٍ التحويل إلى سداد' : 'Redirecting to SADAD' }}</title>
<style>body{margin:0;display:grid;place-items:center;min-height:100vh;background:#faf7f2;color:#14161c;font-family:-apple-system,system-ui,sans-serif}.c{text-align:center;padding:24px}.s{width:42px;height:42px;border:3px solid #e2d6c3;border-top-color:#b08d57;border-radius:50%;animation:r 1s linear infinite;margin:0 auto 18px}@keyframes r{to{transform:rotate(360deg)}}button{margin-top:16px;padding:12px 22px;border:0;border-radius:12px;background:#14161c;color:#faf7f2;font-size:15px}</style></head>
<body><div class="c"><div class="s"></div>
<p>{{ $locale === 'ar' ? 'جارٍ تحويلك بأمان إلى صفحة الدفع من سداد…' : 'Taking you securely to SADAD payment…' }}</p>
<form id="f" method="POST" action="{{ $form['action'] }}">
@foreach ($form['fields'] as $k => $v)
    @if (is_array($v))
        @foreach ($v as $i => $row)@foreach ($row as $rk => $rv)<input type="hidden" name="{{ $k }}[{{ $i }}][{{ $rk }}]" value="{{ $rv }}">@endforeach @endforeach
    @else<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
@endforeach
<noscript><button type="submit">{{ $locale === 'ar' ? 'متابعة' : 'Continue' }}</button></noscript>
</form></div><script>document.getElementById('f').submit()</script></body></html>
