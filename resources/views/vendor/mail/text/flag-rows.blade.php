@props(['deals'])
@foreach ($deals as $deal)
- {{ $deal->headline() }}
@endforeach
