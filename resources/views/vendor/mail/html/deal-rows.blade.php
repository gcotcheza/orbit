{{--
    The calm list: a route, what it costs, and what Orbit makes of it.

    TWO CELLS, WHERE THE DIGEST USED TO HAVE FIVE. The old table was
    Route | Now | Usual | Score | verdict, and on a phone every one of those
    columns was about forty pixels wide: "Amsterdam" wrapped after "Amster",
    and a route with no statistics yet produced a row of four em-dashes that
    looked like a rendering failure rather than like an answer.

    THE SPLIT IS BY WHETHER A THING MAY WRAP. Names wrap and go on the left;
    numbers must not and go on the right, stacked — the price, then what the
    route usually costs directly under it, so "price vs usual" is a comparison
    the eye makes rather than one two columns apart. Everything left is at most
    two short pieces: the route code, and whichever ONE of the verdict or the
    departure day this deal has (a watched route has an opinion, a rule match
    has a date — App\Application\Alerts\DealSummary::forMatch explains why it
    can never have both).

    THE DEPARTURE DAY OF A WATCHED ROUTE IS DELIBERATELY NOT HERE. The digest
    says where things stand; which single day next quarter is cheapest is what
    the route screen and the deal alert are for, and printing it here cost the
    row a second line for a fact nobody is acting on this Sunday.

    SCORE ZERO IS NOT A SCORE, so it is not printed. App\Domain\Pricing\
    DealScorer returns 0 with `confident: false` for a route inside its first
    config('orbit.alerts.min_tracking_days') mornings, exactly so that nothing
    downstream mistakes "no opinion" for "terrible" — and that route's verdict
    already says "Not enough data yet" in words, on the left, on one line.

    @var list<\App\Application\Alerts\DealSummary> $deals
--}}
@props(['deals'])
<table class="rows" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($deals as $deal)
@php
    $note = $deal->verdict ?? ($deal->departureDate === null ? null : $deal->departureDay());
    $sub = $note === null ? $deal->pair() : $deal->pair().' · '.$note;
    $figures = [];
    if ($deal->usual() !== '') {
        $figures[] = 'usually '.$deal->usual();
    }
    if ($deal->score !== null && $deal->score > 0) {
        $figures[] = $deal->score.'/100';
    }
    $last = $loop->last ? ' row-last' : '';
@endphp
<tr>
<td class="row-cell{{ $last }}">
<p class="row-title">{{ $deal->journey() }}</p>
<p class="row-sub">{{ $sub }}</p>
</td>
<td class="row-cell row-side{{ $last }}">
<p class="row-price">{{ $deal->price() }}</p>
@if ($figures !== [])
<p class="row-note">{{ implode(' · ', $figures) }}</p>
@endif
</td>
</tr>
@endforeach
</table>
