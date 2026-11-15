@props([
    'editor',             {{-- the Livewire editor (the same one the full page uses) --}}
    'editing' => null,    {{-- id of the record whose window is open, or null --}}
    'record' => null,     {{-- that record's model (handed to the editor as it would be on its page) --}}
    'title' => null,      {{-- window title of an open record --}}
    'description' => null,
    'newTitle',           {{-- window title of the "new" window --}}
    'newDescription' => null,
    'fullUrl' => null,    {{-- the full page of the open record --}}
    'newUrl' => null,     {{-- the full page for a new record (null = the viewer may not create) --}}
    'creating' => false,
    'maxWidth' => 'max-w-5xl',
    'param',              {{-- the editor's own mount parameter name: employee | role | branch --}}
    'landing' => '',      {{-- the tab to open a just-created record on --}}
])
{{--
    The two side windows of an editable list: edit a row, or create a new record, beside the list. Each shows the SAME Livewire editor the
    full page uses, in its `embedded` layout, and offers "Open full page" for the page itself. See WithRecordEditor for the behaviour.
    The bar is placed OUTSIDE the nested component, so $wire is the list. The default slot holds the record's badges.
--}}
<x-admin.dialog wire:model.live="showEditor" :title="$title" :description="$description" :max-width="$maxWidth">
    @if ($editing && $record)
        <x-admin.drawer-bar :url="$fullUrl" close="closeEditor">@isset($badges){{ $badges }}@endisset</x-admin.drawer-bar>
        @livewire($editor, [$param => $record, 'embedded' => true] + ($landing !== '' ? ['tab' => $landing] : []), key('rec-edit-'.$editing))
    @endif
</x-admin.dialog>

@if ($newUrl)
    <x-admin.dialog wire:model.live="creating" :title="$newTitle" :description="$newDescription" :max-width="$maxWidth">
        @if ($creating)
            <x-admin.drawer-bar :url="$newUrl" close="closeCreate" />
            @livewire($editor, ['embedded' => true], key('rec-new'))
        @endif
    </x-admin.dialog>
@endif
