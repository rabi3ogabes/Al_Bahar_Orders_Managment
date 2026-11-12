@extends('partner.layout')
@section('body')
<main class="grid min-h-screen place-items-center px-4 py-10">
    <form id="unlock" class="card w-full max-w-sm space-y-5 p-7" autocomplete="off" novalidate>
        <div class="flex items-center gap-3">
            <div class="grid size-11 place-items-center rounded-xl bg-gradient-to-br from-brass-300 to-brass-600 font-display text-2xl font-semibold text-ink-950" aria-hidden="true">A</div>
            <div>
                <h1 class="font-display text-2xl font-semibold leading-tight" data-i18n="lock_title">Partner statistics</h1>
                <p class="text-sm text-ink-500" data-i18n="lock_hint">Enter the password you were given.</p>
            </div>
        </div>
        <div>
            <label for="pw" class="mb-1.5 block text-sm font-medium" data-i18n="password">Password</label>
            <input id="pw" name="password" type="password" class="input" required autofocus autocomplete="current-password" aria-describedby="pw-err">
            <p id="pw-err" class="mt-2 hidden text-sm text-red-600" role="alert"></p>
        </div>
        <button id="go" class="btn-primary w-full justify-center" type="submit"><span data-i18n="open">Open statistics</span></button>
        <button id="lang" type="button" class="mx-auto block text-xs text-ink-500 underline-offset-2 hover:underline">العربية</button>
    </form>
</main>
@endsection
