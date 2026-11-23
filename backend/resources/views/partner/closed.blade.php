@extends('partner.layout', ['token' => '', 'today' => ''])
@section('body')
<main class="grid min-h-screen place-items-center px-4 py-10">
    <section class="card w-full max-w-sm space-y-3 p-7 text-center" role="alert">
        <div class="mx-auto grid size-11 place-items-center rounded-xl bg-gradient-to-br from-brass-300 to-brass-600 font-display text-2xl font-semibold text-ink-950" aria-hidden="true">A</div>
        <h1 class="font-display text-2xl font-semibold leading-tight">This link is no longer active</h1>
        <p class="text-sm text-ink-500">Please ask {{ config('platform.brand.name_en') }} for a new link.</p>
        <hr class="border-sand-200 dark:border-ink-700">
        <h2 class="font-display text-xl font-semibold" dir="rtl" lang="ar">هذا الرابط لم يعد فعّالاً</h2>
        <p class="text-sm text-ink-500" dir="rtl" lang="ar">يرجى طلب رابط جديد من {{ config('platform.brand.name_ar') ?? config('platform.brand.name_en') }}.</p>
    </section>
</main>
@endsection
