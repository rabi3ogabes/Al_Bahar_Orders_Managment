<div class="mx-auto max-w-3xl">
    <x-admin.page-header :title="__('partner_admin.title')" :eyebrow="__('nav.insights')" :description="__('partner_admin.hint')" />

    <section class="card card-pad mb-6">
        <label class="flex cursor-pointer items-center justify-between gap-4">
            <span><span class="block font-medium text-ink-900 dark:text-ivory">{{ __('partner_admin.share') }}</span><span class="block text-sm text-ink-500">{{ __('partner_admin.share_hint') }}</span></span>
            <input type="checkbox" wire:model.live="enabled" @disabled(! $canEdit) role="switch" class="checkbox size-6 shrink-0" aria-label="{{ __('partner_admin.share') }}">
        </label>

        <div class="mt-5 border-t border-sand-100 pt-5 dark:border-ink-800" x-data="{ copied: false, async copy() { try { await navigator.clipboard.writeText(@js($url)); this.copied = true; setTimeout(() => this.copied = false, 2000) } catch (e) {} } }">
            <p class="mb-1.5 text-sm font-medium">{{ __('partner_admin.link') }}</p>
            @if ($url)
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input readonly value="{{ $url }}" dir="ltr" class="input min-h-11 flex-1 font-mono text-xs" onfocus="this.select()" aria-label="{{ __('partner_admin.link') }}">
                    <button type="button" class="btn-secondary min-h-11" @click="copy()"><x-icon name="copy" class="size-4" /><span x-text="copied ? @js(__('partner_admin.copied')) : @js(__('partner_admin.copy'))"></span></button>
                    <a href="{{ $url }}" target="_blank" rel="noopener" class="btn-secondary min-h-11"><x-icon name="external" class="size-4" />{{ __('partner_admin.open') }}</a>
                </div>
                @if ($canEdit)<button type="button" wire:click="$set('confirmRotate', true)" class="mt-3 text-sm text-ink-500 underline-offset-2 hover:underline">{{ __('partner_admin.rotate') }}</button>@endif
            @else
                <p class="text-sm text-ink-500">{{ __('partner_admin.no_link') }}</p>
                @if ($canEdit)<button type="button" wire:click="rotate" class="btn-secondary mt-2 min-h-11">{{ __('partner_admin.create_link') }}</button>@endif
            @endif
        </div>
    </section>

    @if ($canEdit)
    <form wire:submit="savePassword" class="card card-pad mb-6 space-y-4" autocomplete="off">
        <div>
            <h2 class="font-display text-xl font-semibold">{{ $hasPassword ? __('partner_admin.change_password') : __('partner_admin.set_password') }}</h2>
            <p class="text-sm text-ink-500">{{ __('partner_admin.password_hint') }}</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-admin.field :label="__('partner_admin.password')" for="password"><input id="password" type="password" wire:model="password" class="input" autocomplete="new-password" minlength="10"></x-admin.field>
            <x-admin.field :label="__('partner_admin.password_again')" for="password_confirmation"><input id="password_confirmation" type="password" wire:model="password_confirmation" class="input" autocomplete="new-password"></x-admin.field>
        </div>
        <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="savePassword" @disabled($password === '')>{{ __('partner_admin.save_password') }}</button>
    </form>
    @endif

    <section class="card card-pad">
        <h2 class="mb-3 font-display text-xl font-semibold">{{ __('partner_admin.recent') }}</h2>
        @forelse ($recent as $r)
            <div class="flex items-center justify-between gap-3 border-b border-sand-100 py-2 text-sm last:border-0 dark:border-ink-800">
                <span class="font-medium {{ $r->event === 'failed' ? 'text-ruby-soft' : '' }}">{{ __('partner_admin.event.'.$r->event) }}</span>
                <span class="truncate text-ink-500" dir="ltr">{{ $r->ip }}</span>
                <time class="shrink-0 text-xs text-ink-500">{{ \App\Support\BusinessTime::display($r->created_at) }}</time>
            </div>
        @empty
            <p class="text-sm text-ink-500">{{ __('partner_admin.no_access') }}</p>
        @endforelse
    </section>

    <x-admin.dialog variant="modal" wire:model="confirmRotate" :title="__('partner_admin.rotate_q')" max-width="max-w-md">
        <p class="text-sm text-ink-600 dark:text-ink-300">{{ __('partner_admin.rotate_body') }}</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn-secondary min-h-11" @click="open = false" x-init="$nextTick(() => $el.focus())">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary min-h-11 !bg-ruby-soft" wire:click="rotate">{{ __('partner_admin.rotate_yes') }}</button>
        </div>
    </x-admin.dialog>
</div>
