<div>
    <x-admin.page-header :title="__('nav.campaigns')" :eyebrow="__('nav.marketing')" :description="__('marketing_admin.campaigns_hint')">
        <x-slot:actions>@if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('marketing_admin.new_campaign') }}</button>@endif</x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('marketing_admin.campaigns_search')" />
            <select wire:model.live="filterStatus" class="input sm:w-44" aria-label="{{ __('admin.status') }}">
                <option value="">{{ __('admin.all_statuses') }}</option>
                @foreach (['draft', 'scheduled', 'sending', 'sent', 'cancelled'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
            </select>
            <div class="flex items-center gap-1 text-xs" role="group" aria-label="{{ __('marketing_admin.test_language') }}">
                <span class="text-ink-500">{{ __('marketing_admin.test_language') }}</span>
                @foreach (['en' => 'EN', 'ar' => 'ع'] as $l => $label)<button type="button" wire:click="$set('previewLocale', '{{ $l }}')" class="rounded-lg px-2 py-1 font-medium {{ $previewLocale === $l ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'text-ink-600 hover:bg-sand-100 dark:text-ink-300 dark:hover:bg-ink-800' }}">{{ $label }}</button>@endforeach
            </div>
        </x-admin.toolbar>
        @if ($campaigns->isEmpty())
            <x-admin.empty :title="__('marketing_admin.campaigns_empty')" :description="__('marketing_admin.campaigns_empty_hint')" icon="megaphone" />
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($campaigns as $c)
                    <li class="flex flex-col gap-3 p-4 lg:flex-row lg:items-center sm:px-6" wire:key="cm-{{ $c->id }}">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2"><span class="font-medium text-ink-900 dark:text-ivory">{{ $c->name }}</span><x-admin.status :value="$c->status" />
                                @foreach ($c->channels ?? [] as $ch)<span class="badge-neutral">{{ __('content_admin.ch_'.$ch) }}</span>@endforeach</div>
                            <p class="mt-1 truncate text-sm text-ink-500">{{ app()->getLocale() === 'ar' ? $c->title_ar : $c->title_en }}</p>
                            <p class="text-xs text-ink-500 tabular-nums">
                                @if ($c->status === 'sent'){{ __('marketing_admin.sent_to', ['count' => number_format($c->recipients_count), 'at' => \App\Support\BusinessTime::display($c->sent_at)]) }}
                                @elseif ($c->status === 'scheduled'){{ __('marketing_admin.scheduled_for', ['at' => \App\Support\BusinessTime::display($c->scheduled_at)]) }}
                                @else{{ __('admin.updated') }} {{ \App\Support\BusinessTime::display($c->updated_at) }}@endif
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if (in_array($c->status, ['draft', 'scheduled'], true))
                                <button type="button" wire:click="sendTest('{{ $c->id }}')" class="btn-ghost btn-sm"><x-icon name="bell" class="size-4" />{{ __('marketing_admin.send_test') }}</button>
                                @if ($canEdit)<button type="button" wire:click="edit('{{ $c->id }}')" class="btn-secondary btn-sm"><x-icon name="edit" class="size-4" />{{ __('admin.edit') }}</button>@endif
                                @if ($canEdit)<button type="button" wire:click="cancel('{{ $c->id }}')" wire:confirm="{{ __('marketing_admin.confirm_cancel_campaign') }}" class="btn-ghost btn-sm text-ruby-soft">{{ __('admin.cancel') }}</button>@endif
                                @if ($canSend)<button type="button" wire:click="sendNow('{{ $c->id }}')" wire:confirm="{{ __('marketing_admin.confirm_send') }}" class="btn-primary btn-sm"><x-icon name="megaphone" class="size-4" />{{ __('marketing_admin.send_now') }}</button>@endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($campaigns->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $campaigns->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('marketing_admin.edit_campaign') : __('marketing_admin.new_campaign')" max-width="max-w-5xl">
        <form wire:submit="save" class="grid gap-6 lg:grid-cols-[1fr_18rem] [&>*]:min-w-0">
            <div class="space-y-5">
                <x-admin.field :label="__('marketing_admin.campaign_name')" for="form.name" required :hint="__('marketing_admin.internal_only')"><input id="form.name" wire:model="form.name" class="input"></x-admin.field>
                <div>
                    <span class="field-label">{{ __('marketing_admin.channels') }}</span>
                    <div class="flex flex-wrap gap-2">@foreach (\App\Livewire\Admin\Marketing\CampaignIndex::CHANNELS as $ch)<label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm {{ in_array($ch, $form['channels'] ?? [], true) ? 'border-brass-500 bg-brass-50 dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}"><input type="checkbox" wire:model.live="form.channels" value="{{ $ch }}" class="checkbox">{{ __('content_admin.ch_'.$ch) }}</label>@endforeach</div>
                    @error('form.channels')<p class="field-error">{{ $message }}</p>@enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2 [&>*]:min-w-0">
                    <x-admin.field :label="__('marketing_admin.title_en')" for="form.title_en" required><input id="form.title_en" wire:model.live.debounce.400ms="form.title_en" class="input" dir="ltr"></x-admin.field>
                    <x-admin.field :label="__('marketing_admin.title_ar')" for="form.title_ar" required><input id="form.title_ar" wire:model.live.debounce.400ms="form.title_ar" class="input" dir="rtl"></x-admin.field>
                    <x-admin.field :label="__('marketing_admin.body_en')" for="form.body_en" required><textarea id="form.body_en" wire:model.live.debounce.400ms="form.body_en" rows="3" class="input" dir="ltr"></textarea></x-admin.field>
                    <x-admin.field :label="__('marketing_admin.body_ar')" for="form.body_ar" required><textarea id="form.body_ar" wire:model.live.debounce.400ms="form.body_ar" rows="3" class="input" dir="rtl"></textarea></x-admin.field>
                </div>
                <x-admin.field :label="__('marketing_admin.deep_link')" for="form.deep_link" :hint="__('marketing_admin.deep_link_hint')"><input id="form.deep_link" wire:model="form.deep_link" class="input font-mono text-xs" dir="ltr" placeholder="albahar://products/…"></x-admin.field>

                <fieldset class="rounded-2xl border border-sand-200 p-4 dark:border-ink-700">
                    <legend class="px-2 text-sm font-semibold">{{ __('marketing_admin.audience') }}</legend>
                    <div class="space-y-4">
                        <div><span class="field-label">{{ __('marketing_admin.bought_from') }}</span>
                            <div class="flex flex-wrap gap-2">@foreach ($branches as $b)<label class="inline-flex items-center gap-1.5 rounded-full border border-sand-200 px-3 py-1 text-sm dark:border-ink-700"><input type="checkbox" wire:model.live="form.branch_ids" value="{{ $b->id }}" class="checkbox">{{ $b->localized('name') }}</label>@endforeach</div></div>
                        @if ($groups->isNotEmpty())
                            <div><span class="field-label">{{ __('marketing_admin.customer_groups') }}</span>
                                <div class="flex flex-wrap gap-2">@foreach ($groups as $g)<label class="inline-flex items-center gap-1.5 rounded-full border border-sand-200 px-3 py-1 text-sm dark:border-ink-700"><input type="checkbox" wire:model.live="form.customer_group_ids" value="{{ $g->id }}" class="checkbox">{{ app()->getLocale() === 'ar' ? $g->name_ar : $g->name_en }}</label>@endforeach</div></div>
                        @endif
                        <div class="grid gap-4 sm:grid-cols-3 [&>*]:min-w-0">
                            <x-admin.field :label="__('marketing_admin.purchased_since')" for="form.purchased_since"><input id="form.purchased_since" type="date" wire:model.live="form.purchased_since" class="input"></x-admin.field>
                            <x-admin.field :label="__('marketing_admin.min_orders')" for="form.min_orders"><input id="form.min_orders" type="number" min="1" wire:model.live.debounce.400ms="form.min_orders" class="input tabular-nums"></x-admin.field>
                            <div class="sm:pt-7"><label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="form.auction_participants" class="checkbox">{{ __('marketing_admin.auction_bidders') }}</label></div>
                        </div>
                    </div>
                </fieldset>

                <div>
                    <span class="field-label">{{ __('marketing_admin.delivery') }}</span>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach (['draft' => 'file-text', 'later' => 'calendar', 'now' => 'megaphone'] as $w => $icon)
                            @if ($w !== 'now' || $canSend)
                                <label class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm {{ ($form['when'] ?? '') === $w ? 'border-brass-500 bg-brass-50 font-medium dark:bg-brass-500/10' : 'border-sand-200 dark:border-ink-700' }}"><input type="radio" wire:model.live="form.when" value="{{ $w }}" class="sr-only"><x-icon :name="$icon" class="size-4 text-brass-600" />{{ __('marketing_admin.when_'.$w) }}</label>
                            @endif
                        @endforeach
                    </div>
                    @if (($form['when'] ?? '') === 'later')<x-admin.field :label="__('marketing_admin.send_at')" for="form.scheduled_at" class="mt-3" required><input id="form.scheduled_at" type="datetime-local" wire:model="form.scheduled_at" class="input sm:w-64"></x-admin.field>@endif
                </div>
            </div>

            <aside class="space-y-4">
                <div class="rounded-2xl bg-ink-900 p-4 text-ivory">
                    <p class="text-xs uppercase tracking-wider text-ink-400">{{ __('marketing_admin.estimated_reach') }}</p>
                    <p class="mt-1 font-display text-3xl font-semibold tabular-nums" wire:loading.class="opacity-50">{{ number_format($reach ?? 0) }}</p>
                    <p class="mt-1 text-xs text-ink-400">{{ __('marketing_admin.reach_hint') }}</p>
                </div>
                <div x-data="{ l: 'en' }">
                    <div class="mb-2 flex items-center justify-between"><span class="text-xs font-semibold uppercase tracking-wider text-ink-500">{{ __('marketing_admin.preview') }}</span>
                        <div class="flex rounded-lg border border-sand-200 p-0.5 text-xs dark:border-ink-700"><button type="button" @click="l = 'en'" :class="l === 'en' && 'bg-sand-100 dark:bg-ink-800'" class="rounded px-2 py-0.5">EN</button><button type="button" @click="l = 'ar'" :class="l === 'ar' && 'bg-sand-100 dark:bg-ink-800'" class="rounded px-2 py-0.5">ع</button></div></div>
                    <div class="rounded-[2rem] border-4 border-ink-900 bg-gradient-to-b from-sand-100 to-sand-200 p-3 pt-8 dark:from-ink-800 dark:to-ink-900">
                        @foreach (['en' => 'ltr', 'ar' => 'rtl'] as $l => $dir)
                            <div x-show="l === '{{ $l }}'" @if ($l === 'ar') x-cloak @endif dir="{{ $dir }}" class="rounded-2xl bg-white/90 p-3 shadow-lg backdrop-blur dark:bg-ink-950/90">
                                <div class="flex items-center gap-2 text-[0.65rem] text-ink-500"><span class="grid size-4 place-items-center rounded bg-brass-500 text-[0.5rem] font-bold text-ink-950">A</span>{{ $l === 'ar' ? config('platform.brand.name_ar') : config('platform.brand.name_en') }} · {{ $l === 'ar' ? 'الآن' : 'now' }}</div>
                                <p class="mt-1.5 text-sm font-semibold text-ink-900 dark:text-ivory">{{ ($form['title_'.$l] ?? '') ?: '…' }}</p>
                                <p class="mt-0.5 line-clamp-4 text-xs text-ink-600 dark:text-sand-200">{{ ($form['body_'.$l] ?? '') ?: '…' }}</p>
                            </div>
                        @endforeach
                        <div class="h-40"></div>
                    </div>
                </div>
                @if ($editingId)<button type="button" wire:click="sendTest('{{ $editingId }}')" class="btn-secondary w-full"><x-icon name="bell" class="size-4" />{{ __('marketing_admin.send_test_to_me') }}</button>@endif
            </aside>

            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end lg:col-span-2 dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save" @if (($form['when'] ?? '') === 'now') wire:confirm="{{ __('marketing_admin.confirm_send') }}" @endif><x-icon name="check" class="size-4" />{{ __('marketing_admin.save_'.($form['when'] ?? 'draft')) }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
