<div>
    <p class="eyebrow mb-3">{{ __('admin.staff_access') }}</p>
    <h2 class="font-display text-4xl font-semibold tracking-tight text-ink-900 dark:text-ivory">{{ __('admin.welcome_back') }}</h2>
    <p class="mt-3 text-sm text-ink-500 dark:text-ink-400">{{ __('admin.sign_in_hint') }}</p>

    <form wire:submit="login" class="mt-10 space-y-5" novalidate>
        <x-admin.field :label="__('admin.email')" for="email" required>
            <input id="email" type="email" wire:model="email" autocomplete="username" autofocus class="input @error('email') input-error @enderror" dir="ltr">
        </x-admin.field>
        <x-admin.field :label="__('admin.password')" for="password" required>
            <div x-data="{ show: false }" class="relative">
                <input id="password" :type="show ? 'text' : 'password'" wire:model="password" autocomplete="current-password" class="input pe-11 @error('password') input-error @enderror" dir="ltr">
                <button type="button" @click="show = !show" class="absolute inset-y-0 end-0 grid w-11 place-items-center text-ink-400 hover:text-ink-700" :aria-label="show ? '{{ __('admin.hide_password') }}' : '{{ __('admin.show_password') }}'"><x-icon name="eye" class="size-4" /></button>
            </div>
        </x-admin.field>
        <label class="flex items-center gap-2.5 text-sm text-ink-600 dark:text-ink-300">
            <input type="checkbox" wire:model="remember" class="checkbox"> {{ __('admin.remember_me') }}
        </label>
        <button type="submit" class="btn-primary w-full py-3" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">{{ __('admin.sign_in') }}</span>
            <span wire:loading wire:target="login" class="inline-flex items-center gap-2"><x-icon name="refresh" class="size-4 animate-spin" />{{ __('admin.signing_in') }}</span>
        </button>
    </form>
    <p class="mt-10 flex items-center gap-2 text-xs text-ink-400"><x-icon name="lock" class="size-3.5" />{{ __('admin.secure_notice') }}</p>
</div>
