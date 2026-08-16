@props(['preheader' => null])
<x-mail::layout :preheader="$preheader">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
Change what Orbit sends you on the <a href="{{ rtrim(config('app.url'), '/') }}/alerts">Alerts screen</a>.
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
