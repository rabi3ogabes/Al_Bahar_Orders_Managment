{{-- A branch's open/closed and status badges, in the page header of the full page (the side window shows them in its own top bar). --}}
@unless ($isNew)
    @if ($status['is_open'])<span class="badge-success">{{ __('branches.open_now') }}</span>@else<span class="badge-neutral">{{ __('branches.closed_reason.'.$status['reason']) }}</span>@endif
    <x-admin.status :value="$branch->status" />
@endunless
