@props(['text', 'chips' => [], 'label' => null])
@if ($label !== null)
{{ $label }}: “{{ $text }}”
@else
“{{ $text }}”
@endif
@if ($chips !== [])
{{ implode(' · ', $chips) }}
@endif

