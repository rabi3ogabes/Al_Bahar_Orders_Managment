<div>
    <div class="mb-6 grid size-12 place-items-center rounded-2xl bg-brass-50 text-brass-600 dark:bg-brass-500/15 dark:text-brass-300"><x-icon name="shield" /></div>
    <h2 class="font-display text-4xl font-semibold tracking-tight text-ink-900 dark:text-ivory">{{ __('admin.two_factor') }}</h2>
    <p class="mt-3 text-sm text-ink-500 dark:text-ink-400">{{ __('admin.two_factor_hint') }}</p>
    <form wire:submit="verify" class="mt-8 space-y-5">
        <x-admin.field :label="__('admin.authentication_code')" for="code">
            <input id="code" wire:model="code" inputmode="numeric" autocomplete="one-time-code" autofocus class="input text-center font-mono text-2xl tracking-[0.5em] @error('code') input-error @enderror" dir="ltr" maxlength="32">
        </x-admin.field>
        <button class="btn-primary w-full py-3" wire:loading.attr="disabled">{{ __('admin.verify') }}</button>
    </form>
    <form method="POST" action="{{ route('admin.logout') }}" class="mt-6">@csrf<button class="text-sm text-ink-500 underline-offset-4 hover:underline">{{ __('admin.use_another_account') }}</button></form>
</div>
