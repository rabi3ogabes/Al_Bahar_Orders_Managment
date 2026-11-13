@props(['url' => null, 'close' => 'closeView'])
{{--
    First row of a record side window (<x-admin.dialog> showing a list row's details).
    - Sends the close to the server for every way of closing (✕, Esc, scrim, Back), so `?view=` leaves the address bar. When the window is
      closed only because the page is being navigated away from (Open full page, a link inside), nothing is sent: the page is going anyway.
    - Offers "Open full page" for the record's own URL (kept for notifications, e-mails and sharing).
    The default slot holds the badges/status of the record. Place it OUTSIDE any nested Livewire component: $wire is the list.
--}}
<div x-data="{ leaving: false }"
     x-on:livewire:navigate.window="leaving = true; setTimeout(() => leaving = false, 3000)"
     x-init="$watch('open', (v) => { if (! v && ! leaving) { $wire.{{ $close }}(); } })"
     @class(['mb-5 flex flex-wrap items-center justify-between gap-x-3 gap-y-2', 'hidden' => ! $url && $slot->isEmpty()])>
    <div class="flex min-w-0 flex-wrap items-center gap-2 text-sm text-ink-500">{{ $slot }}</div>
    @if ($url)
        <a href="{{ $url }}" wire:navigate class="btn-ghost -me-2 min-h-11 shrink-0 text-ink-600 hover:text-brass-600 dark:text-ink-300"><x-icon name="external" class="size-4 rtl-flip" />{{ __('drawer_sales.open_full') }}</a>
    @endif
</div>
