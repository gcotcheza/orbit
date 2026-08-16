@props(['deals'])
@foreach ($deals as $deal)
<x-mail::deal-card :deal="$deal" />
@endforeach
