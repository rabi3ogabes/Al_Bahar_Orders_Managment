@php
    $rtl = in_array(app()->getLocale(), config('platform.rtl_locales', ['ar']), true);
    $titles = [403 => 'errors.page_403_title', 404 => 'errors.page_404_title', 419 => 'errors.page_419_title', 429 => 'errors.page_429_title', 500 => 'errors.page_500_title', 503 => 'errors.page_503_title'];
    $bodies = [403 => 'errors.page_403_body', 404 => 'errors.page_404_body', 419 => 'errors.page_419_body', 429 => 'errors.page_429_body', 500 => 'errors.page_500_body', 503 => 'errors.page_503_body'];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}" class="h-full">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title>{{ $code }} · {{ __($titles[$code]) }}</title>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css'])
    @endif
</head>
<body class="grid h-full place-items-center bg-ivory px-6 text-ink-900">
    <main class="max-w-md text-center">
        <p class="font-display text-8xl font-semibold text-brass-500">{{ $code }}</p>
        <h1 class="mt-4 font-display text-3xl font-semibold">{{ __($titles[$code]) }}</h1>
        <p class="mt-3 text-sm leading-relaxed text-ink-500">{{ __($bodies[$code]) }}</p>
        <div class="mt-8 flex justify-center gap-3">
            <a href="{{ url()->previous() !== url()->current() ? url()->previous() : url('/admin') }}" class="btn-secondary">{{ __('errors.go_back') }}</a>
            <a href="{{ url('/admin') }}" class="btn-primary">{{ __('errors.go_dashboard') }}</a>
        </div>
    </main>
</body>
</html>
