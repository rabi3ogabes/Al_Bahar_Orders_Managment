<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Payment status · حالة الدفع</title>
<style>body{margin:0;display:grid;place-items:center;min-height:100vh;background:#faf7f2;color:#14161c;font-family:-apple-system,system-ui,sans-serif;text-align:center}a{display:inline-block;margin-top:18px;padding:12px 22px;border-radius:12px;background:#14161c;color:#faf7f2;text-decoration:none}</style></head>
<body><div>
<p><b>We are confirming your payment with SADAD.</b><br>This usually takes a few seconds.</p>
<p dir="rtl"><b>نقوم بتأكيد عملية الدفع مع سداد.</b><br>يستغرق ذلك عادةً بضع ثوانٍ.</p>
<a href="{{ $deepLink }}">Return to the app · العودة إلى التطبيق</a>
</div><script>setTimeout(function(){location.href=@json($deepLink)},800)</script></body></html>
