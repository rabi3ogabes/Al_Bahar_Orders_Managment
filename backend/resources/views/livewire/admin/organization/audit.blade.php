<div>
    <x-admin.page-header :title="__('nav.audit')" :eyebrow="__('nav.insights')" :description="__('org_admin.audit_hint')">
        <x-slot:actions>
            @if ($canExport)
                <button type="button" wire:click="export" class="btn-secondary" wire:loading.attr="disabled" wire:target="export"><x-icon name="download" class="size-4" />{{ __('org_admin.export_csv') }}</button>
            @endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('org_admin.audit_search')" />
            <select wire:model.live="filterModule" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('org_admin.module') }}">
                <option value="">{{ __('org_admin.all_modules') }}</option>
                @foreach ($modules as $m)<option value="{{ $m }}">{{ __('org_admin.audit_module.'.$m) }}</option>@endforeach
            </select>
            @if ($branches->count() > 1)
                <select wire:model.live="branch" class="input pe-10 rtl:[background-position:left_0.5rem_center] sm:w-44" aria-label="{{ __('admin.branch') }}">
                    <option value="">{{ __('admin.all_my_branches') }}</option>
                    @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->localized('name') }}</option>@endforeach
                </select>
            @endif
            <input type="search" wire:model.live.debounce.400ms="filterActor" class="input sm:w-44" placeholder="{{ __('org_admin.actor') }}" aria-label="{{ __('org_admin.actor') }}">
            <div class="flex items-center gap-2">
                <input type="date" wire:model.live="from" class="input sm:w-40" aria-label="{{ __('org_admin.from') }}">
                <span class="text-ink-400">–</span>
                <input type="date" wire:model.live="to" class="input sm:w-40" aria-label="{{ __('org_admin.to') }}">
            </div>
            @if ($hasFilters)<button type="button" wire:click="clearFilters" class="btn-ghost btn-sm"><x-icon name="x" class="size-3.5" />{{ __('org_admin.clear_filters') }}</button>@endif
        </x-admin.toolbar>
        @if ($subject)
            <div class="flex items-center gap-2 border-b border-sand-100 bg-sand-50/60 px-4 py-2.5 text-xs text-ink-600 dark:border-ink-800 dark:bg-ink-800/40">
                <x-icon name="filter" class="size-3.5" />{{ __('org_admin.filtered_subject') }} <span class="font-mono" dir="ltr">{{ $subject }}</span>
            </div>
        @endif

        @if ($logs->isEmpty())
            <x-admin.empty :title="__('org_admin.audit_empty')" :description="__('org_admin.audit_empty_hint')" icon="history" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('admin.date') }}</th><th>{{ __('org_admin.actor') }}</th><th>{{ __('org_admin.action') }}</th><th>{{ __('org_admin.subject') }}</th><th>{{ __('admin.branch') }}</th><th class="w-10"></th></tr></thead>
                    <tbody>
                    @foreach ($logs as $log)
                        <tr wire:key="l-{{ $log->id }}" wire:click="toggle({{ $log->id }})" class="cursor-pointer {{ $expanded === $log->id ? 'bg-sand-50/80 dark:bg-ink-800/50' : '' }}">
                            <td class="whitespace-nowrap text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($log->created_at, 'd M Y, H:i:s') }}</td>
                            <td>
                                <span class="block truncate font-medium text-ink-900 dark:text-ivory">{{ $log->actor?->name ?? __('org_admin.system') }}</span>
                                @if ($log->ip)<span class="block font-mono text-[0.7rem] text-ink-400" dir="ltr">{{ $log->ip }}</span>@endif
                            </td>
                            <td>
                                <x-admin.audit-action :action="$log->action" class="block text-sm text-ink-800 dark:text-sand-100" />
                                <span class="block font-mono text-[0.7rem] text-ink-400" dir="ltr">{{ $log->action }}</span>
                            </td>
                            <td class="text-xs">@if ($log->auditable_type)<span class="badge-neutral">{{ $log->auditable_type }}</span>@if ($log->auditable_id)<span class="ms-1 font-mono text-ink-400" dir="ltr">{{ \Illuminate\Support\Str::limit($log->auditable_id, 8, '…') }}</span>@endif @else<span class="text-ink-400">—</span>@endif</td>
                            <td>@if ($log->branch_id)<span class="font-mono text-xs" dir="ltr">{{ $branchNames[$log->branch_id] ?? '…' }}</span>@else<span class="text-xs text-ink-400">{{ __('admin.company_wide') }}</span>@endif</td>
                            <td><x-icon name="chevron-down" class="size-4 text-ink-400 transition {{ $expanded === $log->id ? 'rotate-180' : '' }}" /></td>
                        </tr>
                        @if ($expanded === $log->id)
                            <tr wire:key="d-{{ $log->id }}" class="hover:!bg-transparent">
                                <td colspan="6" class="bg-sand-50/40 !py-5 dark:bg-ink-800/30">
                                    <div class="grid gap-5 lg:grid-cols-4">
                                        <dl class="space-y-3 text-xs lg:col-span-1">
                                            <div><dt class="text-ink-500">{{ __('org_admin.subject') }}</dt><dd class="mt-0.5 break-all font-mono" dir="ltr">{{ $log->auditable_type }} {{ $log->auditable_id }}</dd></div>
                                            <div><dt class="text-ink-500">{{ __('org_admin.actor') }}</dt><dd class="mt-0.5">{{ $log->actor?->name ?? __('org_admin.system') }} <span class="text-ink-400" dir="ltr">{{ $log->actor?->email }}</span></dd></div>
                                            @if ($log->request_id)<div><dt class="text-ink-500">{{ __('org_admin.request_id') }}</dt><dd class="mt-0.5 break-all font-mono" dir="ltr">{{ $log->request_id }}</dd></div>@endif
                                            @if ($log->user_agent)<div><dt class="text-ink-500">{{ __('org_admin.user_agent') }}</dt><dd class="mt-0.5 break-all text-ink-500" dir="ltr">{{ \Illuminate\Support\Str::limit($log->user_agent, 120) }}</dd></div>@endif
                                        </dl>
                                        <x-admin.json-diff :old="$log->old_values" :new="$log->new_values" :metadata="$log->metadata" class="lg:col-span-3" />
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($logs->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $logs->links('components.admin.pagination') }}</div>@endif
        @endif
    </div>
</div>
