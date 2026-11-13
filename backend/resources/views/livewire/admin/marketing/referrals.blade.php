@php
    $sides = ['referrer' => ['balance', 'coupon'], 'friend' => ['none', 'balance', 'coupon']];
    $flagLabel = fn ($f) => __('referrals_admin.flag_'.$f);
@endphp
<div>
    <x-admin.page-header :title="__('referrals_admin.title')" :eyebrow="__('nav.marketing')" :description="__('referrals_admin.hint')" />

    {{-- On/off acts at once (no Save) --}}
    <div class="card mb-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-display text-xl font-semibold text-ink-900 dark:text-ivory">{{ __('referrals_admin.programme') }}</h2>
                <span class="{{ $enabled ? 'badge-success' : 'badge-neutral' }}">{{ $enabled ? __('referrals_admin.running') : __('referrals_admin.paused') }}</span>
            </div>
            <p class="mt-1 text-sm text-ink-500">{{ __('referrals_admin.programme_hint') }}</p>
        </div>
        <button type="button" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" wire:click="toggle" @disabled(! $canEdit) wire:loading.attr="disabled" wire:target="toggle"
                class="relative inline-flex h-11 w-[4.5rem] shrink-0 items-center rounded-full p-1 transition {{ $enabled ? 'bg-emerald-soft' : 'bg-sand-300 dark:bg-ink-700' }}" aria-label="{{ __('referrals_admin.programme') }}">
            <span class="size-9 rounded-full bg-white shadow transition-transform {{ $enabled ? 'ltr:translate-x-7 rtl:-translate-x-7' : '' }}"></span>
        </button>
    </div>

    <div class="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-6 [&>*]:min-w-0">
        <x-admin.stat :label="__('referrals_admin.st_referrers')" :value="number_format($stats['referrers'])" icon="users" />
        <x-admin.stat :label="__('referrals_admin.st_signed_up')" :value="number_format($stats['signed_up'])" icon="plus" />
        <x-admin.stat :label="__('referrals_admin.st_pending')" :value="number_format($stats['pending'])" icon="clock" />
        <x-admin.stat :label="__('referrals_admin.st_rewarded')" :value="number_format($stats['rewarded'])" icon="check" tone="brass" />
        <x-admin.stat :label="__('referrals_admin.st_rejected')" :value="number_format($stats['rejected'])" icon="x" />
        <x-admin.stat :label="__('referrals_admin.st_value')" :value="$stats['value']->format()" icon="gift" tone="brass" :hint="__('referrals_admin.st_value_hint')" />
    </div>

    <x-admin.tabs :tabs="['program' => ['settings', __('referrals_admin.tab_program')], 'referrals' => ['users', __('referrals_admin.tab_list')]]" :current="$tab" />

    @if ($tab === 'program')
        <div class="grid gap-6 xl:grid-cols-[1fr_22rem] [&>*]:min-w-0">
            <form wire:submit="saveRewards" class="card card-pad space-y-6">
                <div><h3 class="font-display text-lg font-semibold">{{ __('referrals_admin.rewards') }}</h3><p class="mt-1 text-sm text-ink-500">{{ __('referrals_admin.rewards_hint') }}</p></div>
                @if ($loyaltyOff)<p class="rounded-xl bg-amber-soft/10 p-3 text-sm" role="note">{{ __('referrals_admin.loyalty_off') }}</p>@endif

                @foreach ($sides as $side => $types)
                    <fieldset class="space-y-3 rounded-2xl border border-sand-100 p-4 dark:border-ink-800" @disabled(! $canEdit)>
                        <legend class="px-1 text-sm font-semibold">{{ __('referrals_admin.side_'.$side) }}</legend>
                        <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('referrals_admin.side_'.$side) }}">
                            @foreach ($types as $t)
                                <label class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border border-sand-200 px-3 text-sm dark:border-ink-700"><input type="radio" class="radio" wire:model.live="cfg.{{ $side }}_type" value="{{ $t }}">{{ __('referrals_admin.type_'.$t) }}</label>
                            @endforeach
                        </div>
                        @if (($cfg[$side.'_type'] ?? '') === 'balance')
                            <x-admin.field :label="__('referrals_admin.f_amount')" for="cfg.{{ $side }}_amount" :hint="__('referrals_admin.amount_hint', ['v' => $perPoint])"><input id="cfg.{{ $side }}_amount" type="number" step="0.5" min="0" wire:model.live.debounce.400ms="cfg.{{ $side }}_amount" class="input tabular-nums sm:w-48" dir="ltr"></x-admin.field>
                        @elseif (($cfg[$side.'_type'] ?? '') === 'coupon')
                            <div class="grid gap-3 sm:grid-cols-3 [&>*]:min-w-0">
                                <x-admin.field :label="__('referrals_admin.f_promotion')" for="cfg.{{ $side }}_promotion_id" class="sm:col-span-3"><select id="cfg.{{ $side }}_promotion_id" wire:model.live="cfg.{{ $side }}_promotion_id" class="input"><option value="">{{ __('referrals_admin.pick_promotion') }}</option>@foreach ($promotions as $p)<option value="{{ $p->id }}">{{ app()->getLocale() === 'ar' ? $p->name_ar : $p->name_en }}</option>@endforeach</select></x-admin.field>
                                <x-admin.field :label="__('referrals_admin.f_prefix')" for="cfg.{{ $side }}_prefix"><input id="cfg.{{ $side }}_prefix" wire:model="cfg.{{ $side }}_prefix" class="input" dir="ltr" maxlength="12"></x-admin.field>
                                <x-admin.field :label="__('referrals_admin.f_valid_hours')" for="cfg.{{ $side }}_valid_hours"><input id="cfg.{{ $side }}_valid_hours" type="number" min="1" wire:model="cfg.{{ $side }}_valid_hours" class="input tabular-nums" dir="ltr"></x-admin.field>
                            </div>
                        @endif
                    </fieldset>
                @endforeach

                <fieldset class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0" @disabled(! $canEdit)>
                    <x-admin.field :label="__('referrals_admin.f_min_order_amount')" for="cfg.min_order_amount" :hint="__('referrals_admin.min_hint')"><input id="cfg.min_order_amount" type="number" step="0.5" min="0" wire:model.live.debounce.400ms="cfg.min_order_amount" class="input tabular-nums" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('referrals_admin.f_validity_days')" for="cfg.validity_days" :hint="__('referrals_admin.validity_hint')"><input id="cfg.validity_days" type="number" min="1" wire:model="cfg.validity_days" class="input tabular-nums" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('referrals_admin.f_max_rewards_per_referrer')" for="cfg.max_rewards_per_referrer" :hint="__('referrals_admin.cap_hint')"><input id="cfg.max_rewards_per_referrer" type="number" min="0" wire:model="cfg.max_rewards_per_referrer" class="input tabular-nums" dir="ltr"></x-admin.field>
                </fieldset>

                <div class="rounded-xl bg-sand-50 p-4 text-sm text-ink-600 dark:bg-ink-800/60 dark:text-sand-200" aria-live="polite"><span class="font-semibold">{{ __('referrals_admin.example') }}:</span> {{ $example }}</div>
                @if ($canEdit)<div class="flex justify-end"><button class="btn-primary" @disabled(! $rewardsDirty) wire:loading.attr="disabled" wire:target="saveRewards"><x-icon name="check" class="size-4" />{{ __('admin.save_changes') }}</button></div>@endif
            </form>

            <form wire:submit="saveShare" class="card card-pad space-y-4" x-data="{ l: 'en' }">
                <div><h3 class="font-display text-lg font-semibold">{{ __('referrals_admin.share') }}</h3><p class="mt-1 text-sm text-ink-500">{{ __('referrals_admin.share_hint') }}</p></div>
                <fieldset class="space-y-4" @disabled(! $canEdit)>
                    <x-admin.field :label="__('referrals_admin.f_share_link')" for="share.share_link" :hint="__('referrals_admin.link_hint')"><input id="share.share_link" type="url" wire:model.live.debounce.400ms="share.share_link" class="input" dir="ltr" placeholder="https://"></x-admin.field>
                    <x-admin.field :label="__('referrals_admin.f_share_message_en')" for="share.share_message_en"><textarea id="share.share_message_en" rows="3" wire:model.live.debounce.400ms="share.share_message_en" class="input" dir="ltr"></textarea></x-admin.field>
                    <x-admin.field :label="__('referrals_admin.f_share_message_ar')" for="share.share_message_ar"><textarea id="share.share_message_ar" rows="3" wire:model.live.debounce.400ms="share.share_message_ar" class="input" dir="rtl"></textarea></x-admin.field>
                    <p class="text-xs text-ink-500">{{ __('referrals_admin.placeholders') }}</p>
                </fieldset>
                @php $svc = app(\App\Domain\Marketing\Services\ReferralService::class); $vars = ['code' => 'AB1234', 'link' => trim($share['share_link'] ?? '') ?: config('app.url'), 'reward' => '…']; @endphp
                <div>
                    <div class="mb-2 flex items-center justify-between"><span class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('marketing_admin.preview') }}</span>
                        <div class="flex rounded-lg border border-sand-200 p-0.5 text-xs dark:border-ink-700"><button type="button" @click="l = 'en'" :class="l === 'en' && 'bg-sand-100 dark:bg-ink-800'" class="min-h-11 rounded px-3">EN</button><button type="button" @click="l = 'ar'" :class="l === 'ar' && 'bg-sand-100 dark:bg-ink-800'" class="min-h-11 rounded px-3">ع</button></div></div>
                    <div class="rounded-[2rem] border-4 border-ink-900 bg-gradient-to-b from-sand-100 to-sand-200 p-3 pt-8 dark:from-ink-800 dark:to-ink-900">
                        @foreach (['en' => 'ltr', 'ar' => 'rtl'] as $l => $dir)
                            <div x-show="l === '{{ $l }}'" @if ($l === 'ar') x-cloak @endif dir="{{ $dir }}" class="rounded-2xl rounded-ee-sm bg-[#d9fdd3] p-3 text-xs text-ink-900 shadow dark:bg-emerald-900/40 dark:text-ivory"><p class="whitespace-pre-line break-words">{{ $svc->fillMessage($share['share_message_'.$l] ?? '', $vars) ?: '…' }}</p></div>
                        @endforeach
                        <div class="h-8"></div>
                    </div>
                </div>
                @if ($canEdit)<div class="flex justify-end"><button class="btn-primary" @disabled(! $shareDirty) wire:loading.attr="disabled" wire:target="saveShare"><x-icon name="check" class="size-4" />{{ __('admin.save_changes') }}</button></div>@endif
            </form>
        </div>
    @else
        <div class="card">
            <x-admin.toolbar>
                <x-admin.search :placeholder="__('referrals_admin.search')" />
                <select wire:model.live="filterStatus" class="input sm:w-44" aria-label="{{ __('admin.status') }}"><option value="">{{ __('referrals_admin.all_statuses') }}</option>@foreach (['pending', 'rewarded', 'rejected'] as $st)<option value="{{ $st }}">{{ __('referrals_admin.status_'.$st) }}</option>@endforeach</select>
            </x-admin.toolbar>
            @if ($rows->isEmpty())
                <x-admin.empty :title="__('referrals_admin.empty')" :description="__('referrals_admin.empty_hint')" icon="users" />
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('marketing_admin.referrer') }}</th><th>{{ __('marketing_admin.referee') }}</th><th>{{ __('admin.status') }}</th><th>{{ __('referrals_admin.order') }}</th><th>{{ __('referrals_admin.reward_given') }}</th><th>{{ __('admin.date') }}</th><th></th></tr></thead>
                    <tbody>@foreach ($rows as $r)
                        <tr wire:key="rf-{{ $r->id }}">
                            <td><a href="{{ route('admin.customers.show', $r->referrer_id) }}" wire:navigate class="font-medium hover:text-brass-600">{{ $r->referrer?->name ?? '—' }}</a><span class="block font-mono text-xs text-ink-500" dir="ltr">{{ $r->code }}</span></td>
                            <td><a href="{{ route('admin.customers.show', $r->referee_id) }}" wire:navigate class="hover:text-brass-600">{{ $r->referee?->name ?? '—' }}</a>
                                @foreach ($r->fraud_flags ?? [] as $f)<span class="badge-warning ms-1" title="{{ __('referrals_admin.flag_hint') }}">{{ $flagLabel($f) }}</span>@endforeach</td>
                            <td><x-admin.status :value="$r->status" />@if ($r->rejection_reason)<span class="mt-1 block text-xs text-ink-500">{{ $r->rejected_by ? $r->rejection_reason : (\Illuminate\Support\Facades\Lang::has('referrals_admin.reason_'.$r->rejection_reason) ? __('referrals_admin.reason_'.$r->rejection_reason) : $r->rejection_reason) }}</span>@endif</td>
                            <td>@if ($r->qualifyingOrder)<a href="{{ route('admin.orders.show', $r->qualifying_order_id) }}" wire:navigate class="font-mono text-xs hover:text-brass-600" dir="ltr">{{ $r->qualifyingOrder->number }}</a>@else — @endif</td>
                            <td class="text-xs">@foreach (['referrer', 'referee'] as $c)@if ($r->{$c.'_reward_type'} === 'balance')<span class="block">{{ __('referrals_admin.who_'.$c) }}: {{ \App\Support\Money::of($r->{$c.'_reward_value'})->format() }}</span>@elseif ($r->{$c.'_reward_type'} === 'coupon')<span class="block font-mono" dir="ltr">{{ __('referrals_admin.who_'.$c) }}: {{ $r->{$c.'_coupon_code'} }}</span>@endif @endforeach</td>
                            <td class="text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($r->created_at) }}</td>
                            <td class="text-end">@if ($canEdit && $r->status === 'pending')<button type="button" wire:click="askReject('{{ $r->id }}')" class="btn-ghost btn-sm text-ruby-soft">{{ __('referrals_admin.reject') }}</button>@endif</td>
                        </tr>
                    @endforeach</tbody>
                </table></div>
                @if ($rows->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $rows->links('components.admin.pagination') }}</div>@endif
            @endif
        </div>
    @endif

    <x-admin.dialog wire:model="showReject" :title="__('referrals_admin.reject_title')" :description="__('referrals_admin.reject_body')" max-width="max-w-md">
        <form wire:submit="reject" class="space-y-4">
            <x-admin.field :label="__('admin.reason')" for="rejectReason" required><textarea id="rejectReason" rows="3" wire:model="rejectReason" class="input" maxlength="250"></textarea></x-admin.field>
            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button type="button" class="btn-secondary" @click="open = false">{{ __('admin.cancel') }}</button><button class="btn-danger" wire:loading.attr="disabled" wire:target="reject">{{ __('referrals_admin.reject') }}</button></div>
        </form>
    </x-admin.dialog>
</div>
