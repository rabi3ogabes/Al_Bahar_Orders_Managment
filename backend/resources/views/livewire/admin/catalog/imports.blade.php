<div>
    <x-admin.page-header :title="__('nav.imports')" :eyebrow="__('nav.catalog')" :description="__('catalog_admin.imports_hint')">
        <x-slot:actions>
            <button type="button" wire:click="template" class="btn-secondary"><x-icon name="file-text" class="size-4" />{{ __('catalog_admin.download_template') }}</button>
            @if ($canExport)<button type="button" wire:click="export" class="btn-secondary" wire:loading.attr="disabled" wire:target="export"><x-icon name="download" class="size-4" />{{ __('catalog_admin.export_xlsx') }}</button>@endif
        </x-slot:actions>
    </x-admin.page-header>

    <div class="grid gap-6 [&>*]:min-w-0 lg:grid-cols-3">
        <div class="space-y-6">
            @if ($canImport)
                <form wire:submit="import" class="card card-pad space-y-4">
                    <h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.new_import') }}</h2>
                    <label x-data="{ drag: false }" @dragover.prevent="drag = true" @dragleave.prevent="drag = false" @drop="drag = false"
                           class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition"
                           :class="drag ? 'border-brass-400 bg-brass-50/60 dark:bg-brass-500/10' : 'border-sand-300 bg-sand-50/50 hover:border-brass-300 dark:border-ink-600 dark:bg-ink-800/30'">
                        <span class="grid size-12 place-items-center rounded-2xl bg-white text-brass-600 shadow-sm dark:bg-ink-800 dark:text-brass-300"><x-icon name="upload" class="size-5" /></span>
                        @if ($file)
                            <span class="mt-3 max-w-full truncate text-sm font-medium" dir="ltr">{{ $file->getClientOriginalName() }}</span>
                            <span class="text-xs text-ink-500 tabular-nums">{{ number_format($file->getSize() / 1024, 0) }} KB</span>
                        @else
                            <span class="mt-3 text-sm font-medium">{{ __('catalog_admin.drop_file') }}</span>
                            <span class="mt-1 text-xs text-ink-500">{{ __('catalog_admin.file_types') }}</span>
                        @endif
                        <input type="file" wire:model="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="sr-only">
                    </label>
                    <div wire:loading wire:target="file" class="text-xs text-ink-500">{{ __('admin.loading') }}</div>
                    @error('file')<p class="field-error">{{ $message }}</p>@enderror
                    <button class="btn-primary w-full" @disabled(! $file) wire:loading.attr="disabled" wire:target="import,file">
                        <span wire:loading.remove wire:target="import"><x-icon name="upload" class="inline size-4" /> {{ __('catalog_admin.start_import') }}</span>
                        <span wire:loading wire:target="import">{{ __('catalog_admin.importing') }}</span>
                    </button>
                </form>
            @endif
            <div class="card card-pad">
                <h3 class="font-display text-lg font-semibold">{{ __('catalog_admin.columns') }}</h3>
                <p class="mt-1 text-xs text-ink-500">{{ __('catalog_admin.columns_hint') }}</p>
                <div class="mt-4 flex flex-wrap gap-1.5" dir="ltr">
                    @foreach ($columns as $col)<span class="rounded-md px-2 py-1 font-mono text-[0.7rem] {{ in_array($col, ['sku', 'name_en', 'name_ar', 'price']) ? 'bg-brass-50 font-semibold text-brass-700 dark:bg-brass-500/15 dark:text-brass-300' : 'bg-sand-100 text-ink-600 dark:bg-ink-800 dark:text-sand-200' }}">{{ $col }}</span>@endforeach
                </div>
            </div>
        </div>

        <div class="min-w-0 space-y-6 lg:col-span-2">
            @if ($current)
                <div class="card overflow-hidden" wire:key="report-{{ $current->id }}">
                    <div class="flex flex-col gap-3 p-5 sm:flex-row sm:items-start sm:justify-between sm:p-6">
                        <div class="min-w-0">
                            <p class="eyebrow mb-1">{{ __('catalog_admin.import_report') }}</p>
                            <h2 class="truncate font-display text-xl font-semibold" dir="ltr">{{ $current->original_name }}</h2>
                            <p class="mt-1 text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($current->created_at) }}</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <x-admin.status :value="$current->status" />
                            @if ($current->errors)<button type="button" wire:click="errorReport('{{ $current->id }}')" class="btn-secondary btn-sm"><x-icon name="download" class="size-3.5" />{{ __('catalog_admin.download_errors') }}</button>@endif
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-px border-y border-sand-100 bg-sand-100 sm:grid-cols-4 dark:border-ink-800 dark:bg-ink-800">
                        @foreach (['total_rows' => ['catalog_admin.rows_total', 'text-ink-900 dark:text-ivory'], 'created_rows' => ['catalog_admin.rows_created', 'text-emerald-soft'], 'updated_rows' => ['catalog_admin.rows_updated', 'text-sky-soft dark:text-sky-300'], 'failed_rows' => ['catalog_admin.rows_failed', 'text-ruby-soft']] as $col => [$label, $tone])
                            <div class="bg-white p-4 dark:bg-ink-900"><p class="text-xs text-ink-500">{{ __($label) }}</p><p class="mt-1 font-display text-2xl font-semibold tabular-nums {{ $tone }}">{{ $current->{$col} }}</p></div>
                        @endforeach
                    </div>
                    @if ($current->errors)
                        <div class="table-wrap max-h-[28rem] overflow-y-auto">
                            <table class="table">
                                <thead class="sticky top-0"><tr><th class="w-20">{{ __('catalog_admin.row') }}</th><th>SKU</th><th>{{ __('catalog_admin.field') }}</th><th>{{ __('catalog_admin.problem') }}</th></tr></thead>
                                <tbody>
                                @foreach ($current->errors as $i => $e)
                                    <tr wire:key="err-{{ $i }}">
                                        <td class="tabular-nums text-ink-500">{{ $e['row'] ?? '—' }}</td>
                                        <td class="font-mono text-xs" dir="ltr">{{ $e['sku'] ?? '—' }}</td>
                                        <td class="font-mono text-xs text-ink-500" dir="ltr">{{ $e['field'] ?? '—' }}</td>
                                        <td class="text-ruby-soft">{{ $e['message'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="flex items-center gap-3 p-5 text-sm text-emerald-soft sm:p-6"><x-icon name="check" class="size-4" />{{ __('catalog_admin.no_errors') }}</div>
                    @endif
                </div>
            @endif

            <div class="card overflow-hidden">
                <div class="p-5 sm:p-6"><h2 class="font-display text-xl font-semibold">{{ __('catalog_admin.import_history') }}</h2></div>
                @if ($imports->isEmpty())
                    <x-admin.empty :title="__('catalog_admin.no_imports')" :description="__('catalog_admin.no_imports_hint')" icon="upload" class="!py-10" />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>{{ __('catalog_admin.file') }}</th><th>{{ __('catalog_admin.by') }}</th><th class="!text-end">{{ __('catalog_admin.rows_created') }}</th><th class="!text-end">{{ __('catalog_admin.rows_updated') }}</th><th class="!text-end">{{ __('catalog_admin.rows_failed') }}</th><th>{{ __('admin.status') }}</th></tr></thead>
                            <tbody>
                            @foreach ($imports as $imp)
                                <tr wire:key="imp-{{ $imp->id }}" wire:click="$set('selected', '{{ $imp->id }}')" class="cursor-pointer {{ $selected === $imp->id ? 'bg-sand-50 dark:bg-ink-800/50' : '' }}">
                                    <td><span class="block max-w-[14rem] truncate font-medium" dir="ltr">{{ $imp->original_name }}</span><span class="block text-xs text-ink-500 tabular-nums">{{ \App\Support\BusinessTime::display($imp->created_at) }}</span></td>
                                    <td class="text-sm text-ink-600">{{ $users[$imp->user_id] ?? '—' }}</td>
                                    <td class="text-end tabular-nums">{{ $imp->created_rows }}</td>
                                    <td class="text-end tabular-nums">{{ $imp->updated_rows }}</td>
                                    <td class="text-end tabular-nums {{ $imp->failed_rows ? 'font-semibold text-ruby-soft' : '' }}">{{ $imp->failed_rows }}</td>
                                    <td><x-admin.status :value="$imp->status" /></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($imports->hasPages())<div class="border-t border-sand-100 p-4 dark:border-ink-800">{{ $imports->links('components.admin.pagination') }}</div>@endif
                @endif
            </div>
        </div>
    </div>
</div>
