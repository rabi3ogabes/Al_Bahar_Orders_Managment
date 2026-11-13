@php $prio = ['urgent' => 'badge-danger', 'high' => 'badge-warning', 'normal' => 'badge-neutral', 'low' => 'badge-neutral']; @endphp
<div>
    <x-admin.page-header :title="__('nav.support')" :eyebrow="__('nav.marketing')" :description="__('marketing_admin.support_hint')" />

    <div class="card">
        <div class="space-y-3 border-b border-sand-100 p-4 dark:border-ink-800">
            <x-admin.search :placeholder="__('marketing_admin.support_search')" />
            <div class="flex flex-wrap gap-2">
                <select wire:model.live="filterStatus" class="input flex-1 py-2 text-sm" aria-label="{{ __('admin.status') }}">
                    <option value="open">{{ __('marketing_admin.open_tickets') }}</option><option value="">{{ __('admin.all_statuses') }}</option>
                    @foreach (['open', 'pending_staff', 'pending_customer', 'resolved', 'closed'] as $st)<option value="{{ $st }}">{{ __('status.'.$st) }}</option>@endforeach
                </select>
                <select wire:model.live="filterPriority" class="input flex-1 py-2 text-sm" aria-label="{{ __('marketing_admin.priority') }}">
                    <option value="">{{ __('marketing_admin.any_priority') }}</option>
                    @foreach (['urgent', 'high', 'normal', 'low'] as $p)<option value="{{ $p }}">{{ __('marketing_admin.prio_'.$p) }}</option>@endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="mine" class="checkbox">{{ __('marketing_admin.assigned_to_me') }}</label>
        </div>
        @if ($tickets->isEmpty())
            <x-admin.empty :title="__('marketing_admin.tickets_empty')" :description="__('marketing_admin.tickets_empty_hint')" icon="life-buoy" />
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($tickets as $tk)
                    <li wire:key="tk-{{ $tk->id }}">
                        <button type="button" wire:click="open('{{ $tk->id }}')" class="block w-full px-4 py-3.5 text-start transition hover:bg-sand-50 dark:hover:bg-ink-800/50 {{ $t?->id === $tk->id ? 'bg-brass-50/70 shadow-[inset_3px_0_0_var(--color-brass-500)] rtl:shadow-[inset_-3px_0_0_var(--color-brass-500)] dark:bg-brass-500/10' : '' }}">
                            <div class="flex items-center gap-2"><span class="font-mono text-xs text-ink-500" dir="ltr">{{ $tk->number }}</span><span class="{{ $prio[$tk->priority] ?? 'badge-neutral' }} !text-[0.65rem]">{{ __('marketing_admin.prio_'.$tk->priority) }}</span><x-admin.status :value="$tk->status" class="ms-auto !text-[0.65rem]" /></div>
                            <p class="mt-1 truncate text-sm font-medium text-ink-900 dark:text-ivory" dir="auto">{{ $tk->subject }}</p>
                            <p class="mt-0.5 truncate text-xs text-ink-500">{{ $tk->user?->name }} · {{ __('marketing_admin.cat_'.$tk->category) }} · {{ $tk->updated_at?->locale(app()->getLocale())->diffForHumans() }}@if ($tk->assignee) · <x-icon name="user" class="inline size-3" /> {{ $tk->assignee->name }}@endif</p>
                        </button>
                    </li>
                @endforeach
            </ul>
            @if ($tickets->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $tickets->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>

    {{-- Ticket window: the queue stays in view; the conversation scrolls and the reply box is pinned under it. --}}
    <x-admin.dialog wire:model.live="showThread" :title="$t?->subject ?? ''" :description="$t?->number" max-width="max-w-2xl">
        @if ($t)
            <div class="mb-5 space-y-3 border-b border-sand-100 pb-5 dark:border-ink-800">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <p class="mt-1 text-sm text-ink-500">{{ $t->user?->name }} · {{ __('marketing_admin.cat_'.$t->category) }}@if ($order) · <a href="{{ route('admin.orders.show', $order) }}" wire:navigate class="font-mono hover:text-brass-600" dir="ltr">{{ $order->number }}</a>@endif</p>
                </div>
                <x-admin.status :value="$t->status" class="self-start" />
            </div>
            @if ($canAssign || $canReply)
                <div class="mt-4 grid gap-2 sm:grid-cols-3 [&>*]:min-w-0">
                    @if ($canAssign)
                        <select wire:change="assign('assignee', $event.target.value)" class="input py-2 text-sm" aria-label="{{ __('marketing_admin.assignee') }}">
                            <option value="">{{ __('marketing_admin.unassigned') }}</option>
                            @foreach ($agents as $ag)<option value="{{ $ag->id }}" @selected($t->assigned_to === $ag->id)>{{ $ag->name }}</option>@endforeach
                        </select>
                        <select wire:change="assign('priority', $event.target.value)" class="input py-2 text-sm" aria-label="{{ __('marketing_admin.priority') }}">
                            @foreach (['urgent', 'high', 'normal', 'low'] as $p)<option value="{{ $p }}" @selected($t->priority === $p)>{{ __('marketing_admin.prio_'.$p) }}</option>@endforeach
                        </select>
                    @endif
                    @if ($canReply)
                        <div class="flex gap-2">
                            @if (in_array($t->status, ['resolved', 'closed'], true))
                                <button type="button" wire:click="setStatus('open')" class="btn-secondary btn-sm flex-1">{{ __('marketing_admin.reopen') }}</button>
                            @else
                                <button type="button" wire:click="setStatus('resolved')" class="btn-secondary btn-sm flex-1"><x-icon name="check" class="size-4" />{{ __('marketing_admin.resolve') }}</button>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
            <ol class="space-y-4 pb-4" x-init="$nextTick(() => { const b = $el.closest('.drawer-body'); if (b) { b.scrollTop = b.scrollHeight; } })" wire:key="thread-{{ $t->id }}-{{ $t->messages->count() }}">
            @foreach ($t->messages as $m)
                @php $staff = $m->author_type === 'staff'; @endphp
                <li class="flex {{ $staff ? 'justify-end' : 'justify-start' }}" wire:key="m-{{ $m->id }}">
                    <div class="max-w-[85%] rounded-2xl px-4 py-3 text-sm {{ $m->is_internal ? 'border border-dashed border-amber-soft/50 bg-amber-soft/5' : ($staff ? 'bg-ink-900 text-ivory dark:bg-brass-500/20 dark:text-sand-100' : 'bg-white shadow-sm dark:bg-ink-800') }}">
                        <p class="mb-1 flex items-center gap-2 text-[0.7rem] {{ $staff && ! $m->is_internal ? 'text-ink-300' : 'text-ink-500' }}">
                            <span class="font-semibold">{{ $m->author?->name ?? __('sales_admin.system') }}</span>
                            @if ($m->is_internal)<span class="badge-warning !px-1.5 !py-0 !text-[0.6rem]"><x-icon name="lock" class="size-2.5" />{{ __('marketing_admin.internal_note') }}</span>@endif
                            <span class="tabular-nums">{{ \App\Support\BusinessTime::display($m->created_at, 'd M H:i') }}</span>
                        </p>
                        <p class="whitespace-pre-line" dir="auto">{{ $m->body }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
        @endif
        @if ($t && $canReply)
            <x-slot:footer>
                <form wire:submit="send" class="space-y-3">
                    <textarea wire:model="reply" rows="3" autofocus class="input {{ $internal ? 'border-amber-soft/60 bg-amber-soft/5' : '' }}" dir="auto" placeholder="{{ $internal ? __('marketing_admin.internal_ph') : __('marketing_admin.reply_ph') }}" aria-label="{{ __('marketing_admin.reply') }}"></textarea>
                    @error('reply')<p class="field-error">{{ $message }}</p>@enderror
                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="internal" class="checkbox">{{ __('marketing_admin.internal_note') }}</label>
                        <select wire:model="replyStatus" class="input py-2 text-sm sm:ms-auto sm:w-52" aria-label="{{ __('marketing_admin.then_set_status') }}">
                            <option value="">{{ __('marketing_admin.keep_status_auto') }}</option>
                            @foreach (['pending_customer', 'resolved', 'closed'] as $st)<option value="{{ $st }}">{{ __('marketing_admin.then').' '.__('status.'.$st) }}</option>@endforeach
                        </select>
                        <button class="btn-primary" wire:loading.attr="disabled" wire:target="send"><x-icon name="message" class="size-4" />{{ $internal ? __('marketing_admin.add_note') : __('marketing_admin.send_reply') }}</button>
                    </div>
                </form>
            </x-slot:footer>
        @endif
    </x-admin.dialog>
</div>
