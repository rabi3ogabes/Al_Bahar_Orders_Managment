<div class="mx-auto max-w-5xl">
    <x-admin.page-header :title="__('admin.profile')" :eyebrow="__('admin.account')" :description="__('admin.profile_hint')" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="card card-pad lg:col-span-1">
            <div class="flex items-center gap-4">
                <div class="grid size-14 place-items-center rounded-2xl bg-ink-900 font-display text-2xl text-ivory dark:bg-brass-500 dark:text-ink-950">{{ mb_substr($user->name, 0, 1) }}</div>
                <div class="min-w-0"><div class="truncate font-medium">{{ $user->name }}</div><div class="truncate text-sm text-ink-500" dir="ltr">{{ $user->email }}</div></div>
            </div>
            <dl class="mt-6 space-y-4 text-sm">
                <div><dt class="text-ink-500">{{ __('admin.roles') }}</dt>
                    <dd class="mt-1.5 flex flex-wrap gap-1.5">
                        @if ($user->isSuperAdmin())<span class="badge-brass">{{ __('admin.super_admin') }}</span>@endif
                        @foreach ($user->roles as $role)<span class="badge-neutral">{{ $role->localized('name') }}</span>@endforeach
                    </dd></div>
                <div><dt class="text-ink-500">{{ __('admin.branches') }}</dt>
                    <dd class="mt-1.5 flex flex-wrap gap-1.5">@forelse ($user->branches as $b)<span class="badge-neutral">{{ $b->localized('name') }}</span>@empty<span class="text-ink-400">{{ __('admin.company_wide') }}</span>@endforelse</dd></div>
                <div><dt class="text-ink-500">{{ __('admin.last_login') }}</dt><dd class="mt-1">{{ \App\Support\BusinessTime::display($user->last_login_at) ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="space-y-6 lg:col-span-2">
            <form wire:submit="saveProfile" class="card card-pad space-y-5">
                <h2 class="font-display text-xl font-semibold">{{ __('admin.personal_details') }}</h2>
                <div class="grid gap-5 sm:grid-cols-2">
                    <x-admin.field :label="__('admin.name')" for="name" required><input id="name" wire:model="name" class="input"></x-admin.field>
                    <x-admin.field :label="__('admin.language')" for="locale">
                        <select id="locale" wire:model="locale" class="input"><option value="en">English</option><option value="ar">العربية</option></select>
                    </x-admin.field>
                </div>
                <div class="flex justify-end"><button class="btn-primary">{{ __('admin.save_changes') }}</button></div>
            </form>

            <form wire:submit="changePassword" class="card card-pad space-y-5">
                <h2 class="font-display text-xl font-semibold">{{ __('admin.change_password') }}</h2>
                <div class="grid gap-5 sm:grid-cols-3">
                    <x-admin.field :label="__('admin.current_password')" for="current_password"><input id="current_password" type="password" wire:model="current_password" class="input" autocomplete="current-password"></x-admin.field>
                    <x-admin.field :label="__('admin.new_password')" for="password" :hint="__('admin.password_policy')"><input id="password" type="password" wire:model="password" class="input" autocomplete="new-password"></x-admin.field>
                    <x-admin.field :label="__('admin.confirm_password')" for="password_confirmation"><input id="password_confirmation" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password"></x-admin.field>
                </div>
                <div class="flex justify-end"><button class="btn-secondary">{{ __('admin.update_password') }}</button></div>
            </form>

            <div class="card card-pad">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="font-display text-xl font-semibold">{{ __('admin.two_factor') }}</h2>
                        <p class="mt-1 text-sm text-ink-500">{{ __('admin.two_factor_description') }}</p>
                    </div>
                    @if ($user->hasTwoFactorEnabled())<span class="badge-success">{{ __('admin.enabled') }}</span>@else<span class="badge-warning">{{ __('admin.not_enabled') }}</span>@endif
                </div>

                @if ($recoveryCodes)
                    <div class="mt-6 rounded-xl border border-brass-300/60 bg-brass-50 p-5 dark:bg-brass-500/10">
                        <p class="text-sm font-medium text-ink-800 dark:text-sand-100">{{ __('admin.recovery_codes_hint') }}</p>
                        <div class="mt-3 grid grid-cols-2 gap-2 font-mono text-sm" dir="ltr">@foreach ($recoveryCodes as $c)<span>{{ $c }}</span>@endforeach</div>
                    </div>
                @endif

                @if (! $user->hasTwoFactorEnabled())
                    @if ($pendingSecret)
                        <div class="mt-6 grid items-center gap-6 sm:grid-cols-[auto_1fr]">
                            <div class="rounded-xl bg-white p-3 [&_svg]:size-40">{!! $qr !!}</div>
                            <form wire:submit="confirmTwoFactor" class="space-y-4">
                                <p class="text-sm text-ink-600">{{ __('admin.scan_qr') }}</p>
                                <p class="font-mono text-xs break-all text-ink-500" dir="ltr">{{ $pendingSecret }}</p>
                                <x-admin.field :label="__('admin.authentication_code')" for="confirmCode"><input id="confirmCode" wire:model="confirmCode" inputmode="numeric" class="input font-mono tracking-widest" dir="ltr" maxlength="6"></x-admin.field>
                                <button class="btn-primary">{{ __('admin.confirm_enable') }}</button>
                            </form>
                        </div>
                    @else
                        <button wire:click="startTwoFactor" class="btn-brass mt-6"><x-icon name="shield" class="size-4" />{{ __('admin.enable_two_factor') }}</button>
                    @endif
                @else
                    <form wire:submit="disableTwoFactor" class="mt-6 flex flex-col gap-3 sm:flex-row sm:items-end">
                        <x-admin.field :label="__('admin.current_password')" for="tfa_pw" error="current_password" class="flex-1"><input id="tfa_pw" type="password" wire:model="current_password" class="input"></x-admin.field>
                        <button class="btn-danger" wire:confirm="{{ __('admin.confirm_disable_2fa') }}">{{ __('admin.disable') }}</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
