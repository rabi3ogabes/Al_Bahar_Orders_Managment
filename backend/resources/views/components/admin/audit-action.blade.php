@props(['action'])
{{-- Human label for an audit action key ("inventory.adjustment_posted"), falling back to a readable form of the key. --}}
@php $key = 'org_admin.act.'.str_replace('.', '__', $action); @endphp
<span {{ $attributes }}>{{ \Illuminate\Support\Facades\Lang::has($key) ? __($key) : \Illuminate\Support\Str::of($action)->replace(['.', '_'], ' ')->ucfirst() }}</span>
