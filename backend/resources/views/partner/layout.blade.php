@php
    $brand = config('platform.brand.name_en');
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="referrer" content="no-referrer">
    <title>{{ $brand }} · Partners</title>
    <script>(function(){try{var s=localStorage.getItem('theme');if(s==='dark'||(!s&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark');var l=localStorage.getItem('partner_lang');if(l==='ar'){document.documentElement.lang='ar';document.documentElement.dir='rtl'}}catch(e){}})();</script>
    @vite(['resources/css/app.css', 'resources/js/partner.js'])
</head>
<body class="min-h-full bg-sand-50 text-ink-900 dark:bg-ink-950 dark:text-sand-100" data-token="{{ $token }}" data-today="{{ $today }}" data-brand="{{ $brand }}">
@yield('body')
</body>
</html>
