<div class="mx-auto max-w-3xl">
    <x-admin.page-header :title="__('partner_admin.title')" :eyebrow="__('nav.insights')" :description="__('partner_admin.hint')">
        <x-slot:actions>@if ($canEdit)<button type="button" wire:click="openCreate" class="btn-primary min-h-11"><x-icon name="plus" class="size-4" />{{ __('partner_admin.add') }}</button>@endif</x-slot:actions>
    </x-admin.page-header>

    <section class="card mb-6" aria-labelledby="partners-title">
        <h2 id="partners-title" class="px-4 pt-4 font-display text-xl font-semibold sm:px-6">{{ __('partner_admin.partners') }}</h2>
        @if ($partners->isEmpty())
            <x-admin.empty :title="__('partner_admin.none')" :description="__('partner_admin.none_hint')" icon="users" />
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($partners as $p)
                    @php $status = $p->status(); @endphp
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:px-6" wire:key="partner-{{ $p->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium text-ink-900 dark:text-ivory">{{ $p->name }}</span>
                                <span class="{{ $status === 'active' ? 'badge-success' : ($status === 'locked' ? 'badge-warning' : 'badge-danger') }}">{{ __('partner_admin.status.'.$status) }}</span>
                            </div>
                            <p class="mt-0.5 text-sm text-ink-500">
                                {{ $p->all_branches ? __('partner_admin.all_branches') : ($p->branches->map(fn ($b) => $b->localized('name'))->join(', ') ?: __('partner_admin.no_branches')) }}
                                @if ($p->contact) · <span dir="auto">{{ $p->contact }}</span>@endif
                            </p>
                            <p class="mt-0.5 text-xs text-ink-500">
                                {{ $p->expires_at ? __('partner_admin.expires_on', ['date' => \App\Support\BusinessTime::display($p->expires_at, 'd M Y')]) : __('partner_admin.no_expiry') }}
                                · {{ $p->last_seen_at ? __('partner_admin.last_seen', ['at' => \App\Support\BusinessTime::display($p->last_seen_at)]) : __('partner_admin.never_seen') }}
                            </p>
                        </div>
                        @if ($canEdit)
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="openEdit('{{ $p->id }}')" class="btn-secondary btn-sm min-h-11">{{ __('admin.edit') }}</button>
                                <button type="button" wire:click="ask('regenerate', '{{ $p->id }}')" class="btn-secondary btn-sm min-h-11">{{ __('partner_admin.regenerate') }}</button>
                                @if ($status !== 'revoked')<button type="button" wire:click="ask('revoke', '{{ $p->id }}')" class="btn-ghost btn-sm min-h-11 text-ruby-soft">{{ __('partner_admin.revoke') }}</button>@endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card card-pad mb-6">
        <div class="mb-4 rounded-xl bg-amber-soft/10 p-3 text-sm text-ink-700 dark:text-sand-200" role="note">{{ __('partner_admin.legacy_banner') }}</div>
        <h2 class="mb-3 font-display text-xl font-semibold">{{ __('partner_admin.legacy_title') }}</h2>
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
                <span class="font-medium {{ in_array($r->event, ['failed', 'locked_out'], true) ? 'text-ruby-soft' : '' }}">{{ __('partner_admin.event.'.$r->event) }}</span>
                <span class="min-w-0 flex-1 truncate text-ink-500">{{ $r->partner_name ?? __('partner_admin.legacy_title') }}</span>
                <span class="truncate text-xs text-ink-500" dir="ltr">{{ $r->ip ?? substr((string) $r->ip_hash, 0, 8) }}</span>
                <time class="shrink-0 text-xs text-ink-500">{{ \App\Support\BusinessTime::display($r->created_at) }}</time>
            </div>
        @empty
            <p class="text-sm text-ink-500">{{ __('partner_admin.no_access') }}</p>
        @endforelse
    </section>

    <x-admin.dialog variant="modal" wire:model="confirmRotate" :title="__('partner_admin.rotate_q')" max-width="max-w-md">
        <p class="text-sm text-ink-600 dark:text-ink-300">{{ __('partner_admin.rotate_body') }}</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn-secondary min-h-11" @click="requestClose()" x-init="$nextTick(() => $el.focus())">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary min-h-11 !bg-ruby-soft" wire:click="rotate">{{ __('partner_admin.rotate_yes') }}</button>
        </div>
    </x-admin.dialog>
    {{-- Add / edit a partner: a form, so a side window. --}}
    <x-admin.dialog wire:model="showForm" variant="drawer" :title="$editing ? __('partner_admin.edit_partner') : __('partner_admin.add')" :description="__('partner_admin.form_hint')">
        <form wire:submit="savePartner" class="space-y-5" autocomplete="off">
            <x-admin.field :label="__('partner_admin.partner_name')" for="pname" required><input id="pname" wire:model="name" class="input" maxlength="120"></x-admin.field>
            <x-admin.field :label="__('partner_admin.contact')" for="pcontact" :hint="__('partner_admin.contact_hint')"><input id="pcontact" wire:model="contact" class="input" maxlength="191"></x-admin.field>
            <fieldset class="space-y-2">
                <legend class="mb-1 text-sm font-medium">{{ __('partner_admin.branches') }}</legend>
                <label class="flex min-h-11 items-center gap-3"><input type="checkbox" wire:model.live="allBranches" class="checkbox size-5"><span class="text-sm">{{ __('partner_admin.all_branches') }}</span></label>
                @unless ($allBranches)
                    @foreach ($branches as $b)
                        <label class="flex min-h-11 items-center gap-3" wire:key="pb-{{ $b->id }}"><input type="checkbox" wire:model="branchIds" value="{{ $b->id }}" class="checkbox size-5"><span class="text-sm">{{ $b->localized('name') }}</span></label>
                    @endforeach
                    @error('branchIds')<p class="text-sm text-ruby-soft" role="alert">{{ $message }}</p>@enderror
                @endunless
            </fieldset>
            <x-admin.field :label="__('partner_admin.expires')" for="pexp" :hint="__('partner_admin.expires_hint')"><input id="pexp" type="date" wire:model="expiresOn" class="input" dir="ltr"></x-admin.field>
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary min-h-11" @click="open = false">{{ __('admin.cancel') }}</button>
                <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="savePartner">{{ $editing ? __('admin.save') : __('partner_admin.create') }}</button>
            </div>
        </form>
    </x-admin.dialog>

    {{-- Regenerate / revoke: a decision, so a centred window. --}}
    <x-admin.dialog variant="modal" wire:model="showConfirm" :title="$confirmAction === 'revoke' ? __('partner_admin.revoke_q') : __('partner_admin.regenerate_q')" max-width="max-w-md">
        <p class="text-sm text-ink-600 dark:text-ink-300">{{ $confirmAction === 'revoke' ? __('partner_admin.revoke_body') : __('partner_admin.regenerate_body') }}</p>
        <div class="mt-6 flex justify-end gap-2">
            <button type="button" class="btn-secondary min-h-11" @click="requestClose()" x-init="$nextTick(() => $el.focus())">{{ __('admin.cancel') }}</button>
            <button type="button" class="btn-primary min-h-11 !bg-ruby-soft" wire:click="runConfirm" wire:loading.attr="disabled" wire:target="runConfirm">{{ $confirmAction === 'revoke' ? __('partner_admin.revoke') : __('partner_admin.regenerate') }}</button>
        </div>
    </x-admin.dialog>

    {{-- The link and the password, once. --}}
    @if ($reveal)
        <div class="fixed inset-0 z-50 grid place-items-center bg-black/50 p-4" role="dialog" aria-modal="true" aria-labelledby="reveal-title" x-data="{ c1: false, c2: false, async copy(t, k) { try { await navigator.clipboard.writeText(t); this[k] = true; setTimeout(() => this[k] = false, 2000) } catch (e) {} } }" @keydown.escape.window="$wire.closeReveal()">
            <div class="card card-pad w-full max-w-lg space-y-4">
                <h2 id="reveal-title" class="font-display text-xl font-semibold">{{ __('partner_admin.reveal_title', ['name' => $reveal['name']]) }}</h2>
                <p class="text-sm text-ink-600 dark:text-ink-300">{{ __('partner_admin.reveal_body') }}</p>
                <div>
                    <p class="mb-1 text-sm font-medium">{{ __('partner_admin.link') }}</p>
                    <div class="flex gap-2"><input readonly value="{{ $reveal['url'] }}" dir="ltr" class="input min-h-11 flex-1 font-mono text-xs" onfocus="this.select()" aria-label="{{ __('partner_admin.link') }}">
                        <button type="button" class="btn-secondary min-h-11" @click="copy(@js($reveal['url']), 'c1')"><x-icon name="copy" class="size-4" /><span x-text="c1 ? @js(__('partner_admin.copied')) : @js(__('partner_admin.copy'))"></span></button></div>
                </div>
                <div>
                    <p class="mb-1 text-sm font-medium">{{ __('partner_admin.password') }}</p>
                    <div class="flex gap-2"><input readonly value="{{ $reveal['password'] }}" dir="ltr" class="input min-h-11 flex-1 font-mono" onfocus="this.select()" aria-label="{{ __('partner_admin.password') }}">
                        <button type="button" class="btn-secondary min-h-11" @click="copy(@js($reveal['password']), 'c2')"><x-icon name="copy" class="size-4" /><span x-text="c2 ? @js(__('partner_admin.copied')) : @js(__('partner_admin.copy'))"></span></button></div>
                </div>
                <div class="flex justify-end"><button type="button" class="btn-primary min-h-11" wire:click="closeReveal">{{ __('partner_admin.done') }}</button></div>
            </div>
        </div>
    @endif
</div>
