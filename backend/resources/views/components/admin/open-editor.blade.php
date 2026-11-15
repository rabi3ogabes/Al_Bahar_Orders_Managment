@props(['id', 'href', 'create' => false])
{{--
    A link that opens a record in the side window beside the list (the list uses WithRecordEditor) and still is a real link to the full page:
    a plain click opens the window, ctrl/cmd/shift-click, a long-press "open in new tab" and no-JavaScript visits go to the page itself.
    Pass `create` for the "new record" link.
--}}
<a href="{{ $href }}" {{ $attributes }}
   @click="if (! ($event.ctrlKey || $event.metaKey || $event.shiftKey || $event.altKey || $event.button > 0)) { $event.preventDefault(); $wire.{{ $create ? 'openCreate()' : 'openEditor('.\Illuminate\Support\Js::from($id).')' }} }">{{ $slot }}</a>
