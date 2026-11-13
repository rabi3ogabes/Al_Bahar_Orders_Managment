@php
    $sev = fn ($s) => match ($s) { 'critical' => 'badge-danger', 'high' => 'badge-warning', 'medium' => 'badge-info', default => 'badge-neutral' };
@endphp
<div>
    <x-admin.page-header :title="__('issue_admin.title')" :eyebrow="__('nav.insights')" :description="__('issue_admin.hint')">
        <x-slot:actions><button type="button" wire:click="create" class="btn-primary min-h-11"><x-icon name="plus" class="size-4" />{{ __('issue_admin.report') }}</button></x-slot:actions>
    </x-admin.page-header>

    <div class="mb-5 grid gap-3 sm:grid-cols-2 [&>*]:min-w-0">
        <div class="card flex items-center gap-4 p-4"><span class="grid size-10 place-items-center rounded-xl bg-brass-500/10 text-brass-600"><x-icon name="alert" class="size-5" /></span><div><p class="font-display text-2xl font-semibold">{{ $counts['active'] }}</p><p class="text-xs text-ink-500">{{ __('issue_admin.open_count') }}</p></div></div>
        <div class="card flex items-center gap-4 p-4"><span class="grid size-10 place-items-center rounded-xl bg-ruby-soft/10 text-ruby-soft"><x-icon name="alert" class="size-5" /></span><div><p class="font-display text-2xl font-semibold">{{ $counts['critical'] }}</p><p class="text-xs text-ink-500">{{ __('issue_admin.urgent_count') }}</p></div></div>
    </div>

    <div class="card">
        <x-admin.toolbar>
            <x-admin.search :placeholder="__('issue_admin.search')" />
            <div class="flex overflow-hidden rounded-xl border border-sand-300 dark:border-ink-600" role="group" aria-label="{{ __('admin.status') }}">
                @foreach (['active', 'closed', 'all'] as $t)
                    <button type="button" wire:click="$set('tab', '{{ $t }}')" aria-pressed="{{ $tab === $t ? 'true' : 'false' }}" class="min-h-11 px-3.5 text-sm {{ $tab === $t ? 'bg-ink-900 text-ivory dark:bg-brass-500 dark:text-ink-950' : 'bg-white dark:bg-ink-800' }}">{{ __('issue_admin.tab_'.$t) }}</button>
                @endforeach
            </div>
            <select wire:model.live="kind" class="input sm:w-40" aria-label="{{ __('issue_admin.kind') }}"><option value="">{{ __('issue_admin.all_kinds') }}</option>@foreach (\App\Domain\Issues\Models\IssueReport::KINDS as $k)<option value="{{ $k }}">{{ __('issue_admin.kinds.'.$k) }}</option>@endforeach</select>
            <select wire:model.live="app" class="input sm:w-44" aria-label="{{ __('issue_admin.app') }}"><option value="">{{ __('issue_admin.all_apps') }}</option>@foreach (\App\Domain\Issues\Models\IssueReport::APPS as $a)<option value="{{ $a }}">{{ __('issue_admin.apps.'.$a) }}</option>@endforeach</select>
            <select wire:model.live="severity" class="input sm:w-40" aria-label="{{ __('issue_admin.severity') }}"><option value="">{{ __('issue_admin.all_severities') }}</option>@foreach (\App\Domain\Issues\Models\IssueReport::SEVERITIES as $v)<option value="{{ $v }}">{{ __('issue_admin.severities.'.$v) }}</option>@endforeach</select>
            <x-slot:meta>{{ $issues->total() }}</x-slot:meta>
        </x-admin.toolbar>

        @if ($issues->isEmpty())
            <x-admin.empty :title="__('issue_admin.empty')" :description="__('issue_admin.empty_hint')" icon="check" />
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('issue_admin.ref') }}</th><th>{{ __('issue_admin.title_field') }}</th><th>{{ __('issue_admin.app') }}</th><th>{{ __('issue_admin.severity') }}</th><th>{{ __('admin.status') }}</th><th>{{ __('issue_admin.reported') }}</th></tr></thead>
                <tbody>@foreach ($issues as $i)
                    <tr wire:key="i-{{ $i->id }}" wire:click="openView('{{ $i->id }}')" class="cursor-pointer" tabindex="0" role="button" aria-label="{{ $i->reference }} {{ $i->title }}" wire:keydown.enter="openView('{{ $i->id }}')">
                        <td class="font-mono text-xs" dir="ltr">{{ $i->reference }}</td>
                        <td><span class="font-medium">{{ $i->title }}</span><span class="block text-xs text-ink-500">{{ __('issue_admin.kinds.'.$i->kind) }}@if ($i->order) · <span dir="ltr">{{ $i->order->number }}</span>@endif</span></td>
                        <td class="text-sm">{{ __('issue_admin.apps.'.$i->app) }}</td>
                        <td><span class="{{ $sev($i->severity) }}">{{ __('issue_admin.severities.'.$i->severity) }}</span></td>
                        <td><x-admin.status :value="$i->status" /></td>
                        <td class="text-xs text-ink-500">{{ \App\Support\BusinessTime::display($i->created_at) }}<span class="block">{{ $i->reporter?->name }}</span></td>
                    </tr>
                @endforeach</tbody>
            </table></div>
            <div class="p-4">{{ $issues->links('components.admin.pagination') }}</div>
        @endif
    </div>

    {{-- Report form --}}
    <x-admin.dialog wire:model="showForm" :title="__('issue_admin.report')" :description="__('issue_admin.report_hint')" max-width="max-w-2xl">
        <form wire:submit="save" class="space-y-4">
            <div class="grid gap-4 sm:grid-cols-3">
                <x-admin.field :label="__('issue_admin.kind')" for="form.kind"><select wire:model="form.kind" class="input">@foreach (\App\Domain\Issues\Models\IssueReport::KINDS as $k)<option value="{{ $k }}">{{ __('issue_admin.kinds.'.$k) }}</option>@endforeach</select></x-admin.field>
                <x-admin.field :label="__('issue_admin.app')" for="form.app"><select wire:model="form.app" class="input">@foreach (\App\Domain\Issues\Models\IssueReport::APPS as $a)<option value="{{ $a }}">{{ __('issue_admin.apps.'.$a) }}</option>@endforeach</select></x-admin.field>
                <x-admin.field :label="__('issue_admin.severity')" for="form.severity"><select wire:model="form.severity" class="input">@foreach (\App\Domain\Issues\Models\IssueReport::SEVERITIES as $v)<option value="{{ $v }}">{{ __('issue_admin.severities.'.$v) }}</option>@endforeach</select></x-admin.field>
            </div>
            <x-admin.field :label="__('issue_admin.title_field')" for="form.title" required><input wire:model="form.title" class="input" maxlength="191" placeholder="{{ __('issue_admin.title_ph') }}" autofocus></x-admin.field>
            <x-admin.field :label="__('issue_admin.details')" for="form.details" :hint="__('issue_admin.details_hint')"><textarea wire:model="form.details" rows="4" class="input"></textarea></x-admin.field>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.field :label="__('issue_admin.order_number')" for="form.order_number"><input wire:model="form.order_number" class="input" dir="ltr" placeholder="AB100123"></x-admin.field>
                <x-admin.field :label="__('issue_admin.payment_ref')" for="form.payment_reference"><input wire:model="form.payment_reference" class="input" dir="ltr"></x-admin.field>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" class="btn-secondary min-h-11" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary min-h-11" wire:loading.attr="disabled" wire:target="save">{{ __('issue_admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>

    {{-- Detail window (the list stays in view; ?view=<id> makes it reloadable and shareable) --}}
    <x-admin.dialog wire:model.live="showView" :title="$detail?->title ?? ''" :description="$detail?->reference" max-width="max-w-xl">
        @if ($detail)
            <x-admin.drawer-bar>
                <span class="{{ $sev($detail->severity) }}">{{ __('issue_admin.severities.'.$detail->severity) }}</span><x-admin.status :value="$detail->status" />
            </x-admin.drawer-bar>
            <div class="space-y-5 pb-5 text-sm">
                <dl class="grid grid-cols-2 gap-3 [&>div]:min-w-0">
                    <div><dt class="text-xs text-ink-500">{{ __('issue_admin.kind') }}</dt><dd>{{ __('issue_admin.kinds.'.$detail->kind) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">{{ __('issue_admin.app') }}</dt><dd>{{ __('issue_admin.apps.'.$detail->app) }}</dd></div>
                    <div class="col-span-2"><dt class="text-xs text-ink-500">{{ __('issue_admin.reported') }}</dt><dd>{{ \App\Support\BusinessTime::display($detail->created_at) }} · {{ $detail->reporter?->name }}</dd></div>
                    <div class="col-span-2"><dt class="text-xs text-ink-500">{{ __('issue_admin.linked') }}</dt><dd dir="ltr" class="text-start">@if ($detail->order)<a class="hover:text-brass-600" wire:navigate href="{{ route('admin.orders.show', $detail->order_id) }}">{{ $detail->order->number }}</a>@else — @endif @if ($detail->payment)· <a class="hover:text-brass-600" wire:navigate href="{{ route('admin.payments.show', $detail->payment_id) }}">{{ $detail->payment->provider_reference ?: 'payment' }}</a>@endif</dd></div>
                </dl>
                @if ($detail->details)<div><p class="mb-1 text-xs text-ink-500">{{ __('issue_admin.details') }}</p><p class="whitespace-pre-line">{{ $detail->details }}</p></div>@endif
                @if ($canEdit)
                    <div>
                        <label for="resolution" class="field-label">{{ __('issue_admin.resolution') }}</label>
                        <textarea id="resolution" wire:model="resolution" rows="4" class="input" placeholder="{{ __('issue_admin.resolution_ph') }}"></textarea>
                    </div>
                @elseif ($detail->resolution)
                    <div><p class="mb-1 text-xs text-ink-500">{{ __('issue_admin.resolution') }}</p><p class="whitespace-pre-line">{{ $detail->resolution }}</p></div>
                @endif
                @if ($detail->resolved_at)<p class="text-xs text-ink-500">{{ __('issue_admin.closed_by', ['name' => $detail->resolver?->name, 'at' => \App\Support\BusinessTime::display($detail->resolved_at)]) }}</p>@endif
            </div>
        @endif
        @if ($detail && $canEdit)
            <x-slot:footer>
                <div class="flex flex-wrap justify-end gap-2">
                    @foreach ([['investigating', 'btn-secondary'], ['wont_fix', 'btn-ghost'], ['open', 'btn-ghost'], ['resolved', 'btn-primary']] as [$st, $cls])
                        @if ($detail->status !== $st)<button type="button" data-saves wire:click="setStatus('{{ $st }}')" wire:loading.attr="disabled" wire:target="setStatus" class="{{ $cls }} min-h-11">{{ __('issue_admin.do_'.$st) }}</button>@endif
                    @endforeach
                </div>
            </x-slot:footer>
        @endif
    </x-admin.dialog>
</div>
