{{--
    html/deal-rows.blade.php's two columns, flattened into one line each — and
    the same rule about a route with no opinion: its verdict is printed, its
    score is not.

    @var list<\App\Application\Alerts\DealSummary> $deals
--}}
@props(['deals'])
@foreach ($deals as $deal)
@php
    $tail = [];
    if ($deal->usual() !== '') {
        $tail[] = 'usually '.$deal->usual();
    }
    if ($deal->departureDate !== null) {
        $tail[] = $deal->departureDay();
    }
    if ($deal->score !== null && $deal->score > 0) {
        $tail[] = $deal->score.'/100';
    }
    if ($deal->verdict !== null) {
        $tail[] = $deal->verdict;
    }
@endphp
- {{ $deal->journey() }} ({{ $deal->pair() }}) — {{ $deal->price() }}@if ($tail !== []) · {{ implode(' · ', $tail) }}@endif

@endforeach
