<div>
    <x-admin.page-header :title="__('nav.rooms')" :eyebrow="__('nav.content')" :description="__('rooms_admin.hint')">
        <x-slot:actions>@if ($canCreate)<button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" />{{ __('rooms_admin.new') }}</button>@endif</x-slot:actions>
    </x-admin.page-header>

    <div class="card">
        @if ($rooms->isEmpty())
            <x-admin.empty :title="__('rooms_admin.empty')" :description="__('rooms_admin.empty_hint')" icon="home">
                @if ($canCreate)<button type="button" wire:click="create" class="btn-brass"><x-icon name="plus" class="size-4" />{{ __('rooms_admin.new') }}</button>@endif
            </x-admin.empty>
        @else
            <ul class="divide-y divide-sand-100 dark:divide-ink-800">
                @foreach ($rooms as $r)
                    @php $count = $counts[$r->id] ?? 0; $img = \App\Livewire\Admin\Content\RoomIndex::mediaUrl($r->image_path); @endphp
                    <li class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:px-6" wire:key="room-{{ $r->id }}">
                        <div class="flex min-w-0 flex-1 items-center gap-4">
                            <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-2xl border border-sand-100 bg-sand-50 dark:border-ink-700 dark:bg-ink-800">
                                @if ($img)<img src="{{ $img }}" alt="" class="size-full object-cover" loading="lazy">@else<x-icon name="home" class="size-6 text-ink-400" />@endif
                            </span>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-ink-900 dark:text-ivory">{{ $r->title_en }}</span><span class="text-ink-500" dir="rtl" lang="ar">{{ $r->title_ar }}</span>
                                    @unless ($r->is_active)<span class="badge-neutral">{{ __('rooms_admin.off') }}</span>@endunless
                                    @if ($r->is_active && $count === 0)<span class="badge-warning" title="{{ __('rooms_admin.hidden_hint') }}">{{ __('rooms_admin.no_products') }}</span>@endif
                                </div>
                                <p class="mt-0.5 truncate text-xs text-ink-500"><span class="font-mono" dir="ltr">{{ $r->slug }}</span> · {{ trans_choice('rooms_admin.n_products', $count, ['n' => number_format($count)]) }} · {{ trans_choice('rooms_admin.n_categories', $r->categories->count(), ['n' => $r->categories->count()]) }}@if ($r->tags) · <span dir="ltr">{{ implode(', ', $r->tags) }}</span>@endif</p>
                            </div>
                        </div>
                        @if ($canEdit || $canDelete)
                            <div class="flex items-center justify-end gap-1">
                                @if ($canEdit)
                                    <button type="button" role="switch" aria-checked="{{ $r->is_active ? 'true' : 'false' }}" wire:click="toggle('{{ $r->id }}')" wire:loading.attr="disabled" wire:target="toggle" aria-label="{{ __('rooms_admin.show') }}: {{ $r->title_en }}"
                                            class="inline-flex min-h-11 items-center px-1"><span class="relative inline-flex h-7 w-12 shrink-0 items-center rounded-full p-0.5 transition {{ $r->is_active ? 'bg-emerald-soft' : 'bg-sand-300 dark:bg-ink-700' }}"><span class="size-6 rounded-full bg-white shadow transition-transform {{ $r->is_active ? 'ltr:translate-x-5 rtl:-translate-x-5' : '' }}"></span></span></button>
                                    <button type="button" wire:click="move('{{ $r->id }}', -1)" @disabled($loop->first) class="btn-ghost btn-sm" aria-label="{{ __('rooms_admin.move_up') }}"><x-icon name="chevron-down" class="size-4 rotate-180" /></button>
                                    <button type="button" wire:click="move('{{ $r->id }}', 1)" @disabled($loop->last) class="btn-ghost btn-sm" aria-label="{{ __('rooms_admin.move_down') }}"><x-icon name="chevron-down" class="size-4" /></button>
                                    <button type="button" wire:click="edit('{{ $r->id }}')" class="btn-ghost btn-sm" aria-label="{{ __('admin.edit') }}"><x-icon name="edit" class="size-4" /></button>
                                @endif
                                @if ($canDelete)<button type="button" wire:click="delete('{{ $r->id }}')" wire:confirm="{{ __('rooms_admin.confirm_delete') }}" class="btn-ghost btn-sm text-ruby-soft" aria-label="{{ __('admin.delete') }}"><x-icon name="trash" class="size-4" /></button>@endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-admin.dialog wire:model="showEditor" :title="$editingId ? __('rooms_admin.edit') : __('rooms_admin.new')" max-width="max-w-3xl">
        <form wire:submit="save" class="space-y-5">
            <div class="grid gap-5 sm:grid-cols-[10rem_1fr]">
                <div>
                    <x-admin.image-input model="image" :file="$image" :current="$existingImage" :label="__('rooms_admin.image')" :hint="__('rooms_admin.image_hint')" aspect="aspect-[4/5]" :maxKb="4096" />
                    @if ($existingImage && $editingId)<button type="button" wire:click="removeImage" class="btn-ghost btn-sm mt-2 text-ruby-soft"><x-icon name="trash" class="size-4" />{{ __('rooms_admin.remove_image') }}</button>@endif
                </div>
                <div class="space-y-5">
                    <x-admin.field :label="__('rooms_admin.title_en')" for="form.title_en" required><input id="form.title_en" wire:model.blur="form.title_en" class="input" dir="ltr" maxlength="80"></x-admin.field>
                    <x-admin.field :label="__('rooms_admin.title_ar')" for="form.title_ar" required><input id="form.title_ar" wire:model="form.title_ar" class="input" dir="rtl" lang="ar" maxlength="80"></x-admin.field>
                    <x-admin.field :label="__('rooms_admin.slug')" for="form.slug" required :hint="__('rooms_admin.slug_hint')"><input id="form.slug" wire:model="form.slug" class="input font-mono" dir="ltr" maxlength="60"></x-admin.field>
                </div>
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <x-admin.field :label="__('rooms_admin.description_en')" for="form.description_en" :hint="__('rooms_admin.description_hint')"><textarea id="form.description_en" wire:model="form.description_en" rows="3" class="input" dir="ltr" maxlength="400"></textarea></x-admin.field>
                <x-admin.field :label="__('rooms_admin.description_ar')" for="form.description_ar"><textarea id="form.description_ar" wire:model="form.description_ar" rows="3" class="input" dir="rtl" lang="ar" maxlength="400"></textarea></x-admin.field>
            </div>
            <fieldset class="rounded-2xl border border-sand-200 p-4 dark:border-ink-700">
                <legend class="px-2 text-sm font-semibold">{{ __('rooms_admin.belongs') }}</legend>
                <p class="mb-3 text-xs text-ink-500">{{ __('rooms_admin.belongs_hint') }}</p>
                <div class="max-h-52 space-y-1 overflow-y-auto pe-1">
                    @foreach ($categories as $c)
                        <label class="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 text-sm hover:bg-sand-50 dark:hover:bg-ink-800" style="padding-inline-start: {{ 0.5 + $c->depth * 1.25 }}rem" wire:key="rc-{{ $c->id }}">
                            <input type="checkbox" wire:model="form.category_ids" value="{{ $c->id }}" class="checkbox">{{ $c->trans('name') }}
                        </label>
                    @endforeach
                </div>
                @error('form.category_ids.*')<p class="field-error">{{ $message }}</p>@enderror
                <x-admin.field class="mt-4" :label="__('rooms_admin.tags')" for="form.tags" :hint="__('rooms_admin.tags_hint')"><input id="form.tags" wire:model="form.tags" class="input" dir="ltr" placeholder="dining, tableware" maxlength="600"></x-admin.field>
            </fieldset>
            <x-admin.toggle :label="__('rooms_admin.show')" :hint="__('rooms_admin.show_hint')" wire:model="form.is_active" />
            <div class="flex flex-col-reverse gap-2 border-t border-sand-100 pt-5 sm:flex-row sm:justify-end dark:border-ink-800">
                <button type="button" class="btn-secondary" @click="requestClose()">{{ __('admin.cancel') }}</button>
                <button class="btn-primary" wire:loading.attr="disabled" wire:target="save,image"><x-icon name="check" class="size-4" />{{ __('admin.save') }}</button>
            </div>
        </form>
    </x-admin.dialog>
</div>
