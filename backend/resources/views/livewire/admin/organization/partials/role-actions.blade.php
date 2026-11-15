{{-- A role's badges and its Delete button: in the page header on the full page; in the side window the badges are in the window's top bar and only Delete stays here. --}}
@if (! $embedded)
    @unless ($isNew)
        @if ($role->is_system)<span class="badge-neutral"><x-icon name="lock" class="size-3" />{{ __('org_admin.system_role') }}</span>@endif
        <span class="{{ $role->scope === 'global' ? 'badge-info' : 'badge-neutral' }}">{{ __('org_admin.scope_'.$role->scope) }}</span>
    @endunless
@endif
@if ($canDelete)
    <button type="button" wire:click="delete" wire:confirm="{{ __('org_admin.confirm_delete_role') }}" class="btn-ghost text-ruby-soft"><x-icon name="trash" class="size-4" />{{ __('admin.delete') }}</button>
@endif
