{{-- Customer chip / picker / quick-create (shared by cart, checkout and the empty state) --}}
<section aria-label="{{ __('neworder_admin.customer') }}">
    @if ($customer)
        <div class="flex items-center gap-3 rounded-2xl border border-sand-200 bg-white p-3 dark:border-ink-700 dark:bg-ink-900">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brass-100 font-semibold text-brass-700 dark:bg-brass-500/20 dark:text-brass-300">{{ mb_substr($customer->name, 0, 1) }}</span>
            <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">{{ $customer->name }}</span><span class="block text-xs text-ink-500" dir="ltr">{{ \App\Support\Phone::mask($customer->phone) }}</span></span>
            @if ($step !== 'done')<button type="button" wire:click="clearCustomer" class="btn-ghost min-h-11 px-3 text-xs">{{ __('neworder_admin.change') }}</button>@endif
        </div>
    @else
        <label class="block">
            <span class="field-label">{{ __('neworder_admin.customer') }}</span>
            <span class="relative block">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 start-3.5 size-4 -translate-y-1/2 text-ink-400" />
                <input type="search" wire:model.live.debounce.300ms="customerSearch" autocomplete="off" class="input ps-10" placeholder="{{ __('neworder_admin.customer_search') }}">
            </span>
        </label>
        @if (mb_strlen(trim($customerSearch)) >= 2)
            <ul class="mt-2 overflow-hidden rounded-xl border border-sand-200 bg-white dark:border-ink-700 dark:bg-ink-900" role="listbox" aria-label="{{ __('neworder_admin.customer') }}">
                @forelse ($results as $r)
                    <li wire:key="cust-{{ $r['id'] }}"><button type="button" wire:click="pickCustomer('{{ $r['id'] }}')" class="flex min-h-11 w-full items-center justify-between gap-3 px-3 py-2 text-start text-sm hover:bg-sand-50 dark:hover:bg-ink-800">
                        <span class="truncate font-medium">{{ $r['name'] }}</span><span class="shrink-0 text-xs text-ink-500" dir="ltr">{{ $r['phone'] }}</span></button></li>
                @empty
                    <li class="px-3 py-3 text-sm text-ink-500">{{ __('neworder_admin.no_customer_found') }}</li>
                @endforelse
            </ul>
        @endif
        <button type="button" wire:click="$toggle('showNewCustomer')" class="btn-ghost mt-2 min-h-11 w-full justify-start" aria-expanded="{{ $showNewCustomer ? 'true' : 'false' }}"><x-icon name="plus" class="size-4" />{{ __('neworder_admin.new_customer') }}</button>
        @if ($showNewCustomer)
            <form wire:submit="saveNewCustomer" class="mt-2 grid gap-3 rounded-2xl border border-sand-200 p-3 dark:border-ink-700">
                <label class="block"><span class="field-label">{{ __('neworder_admin.f_name') }}</span><input type="text" wire:model="newCustomer.name" class="input @error('name') input-error @enderror" maxlength="120" autocomplete="off">@error('name')<span class="mt-1 block text-xs text-ruby-soft">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="field-label">{{ __('neworder_admin.f_phone') }}</span><input type="tel" wire:model="newCustomer.phone" dir="ltr" inputmode="tel" class="input @error('phone') input-error @enderror" placeholder="5xxxxxxx" autocomplete="off">@error('phone')<span class="mt-1 block text-xs text-ruby-soft">{{ $message }}</span>@enderror</label>
                <label class="block"><span class="field-label">{{ __('neworder_admin.f_locale') }}</span>
                    <select wire:model="newCustomer.locale" class="input"><option value="ar">العربية</option><option value="en">English</option></select></label>
                <p class="text-xs text-ink-500">{{ __('neworder_admin.new_customer_hint') }}</p>
                <button type="submit" class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="saveNewCustomer">{{ __('neworder_admin.create_customer') }}</button>
            </form>
        @endif
    @endif
</section>
