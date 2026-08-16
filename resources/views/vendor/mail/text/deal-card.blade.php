{{--
    The same trip as html/deal-card.blade.php, as sentences.

    THE PLAIN-TEXT PART IS NOT A FALLBACK, it is the version a screen reader and
    a watch read, and Illuminate\Mail\Markdown::renderText() renders THIS file
    for the same `<x-mail::deal-card>` tag the HTML render turns into a card.
    That is the whole reason the card is a component rather than markup written
    into resources/views/mail/: without a text twin, `strip_tags` would flatten
    the card into a run of numbers with no words between them.

    @var \App\Application\Alerts\DealSummary $deal
--}}
@props(['deal', 'hero' => false, 'eyebrow' => null])
{{ $deal->journey() }} ({{ $deal->pair() }}) — {{ $deal->price() }}
@if ($deal->percentUnderUsual !== null && $deal->percentUnderUsual > 0)
That is {{ $deal->percentUnderUsual }}% below its usual {{ $deal->usual() }}.
@elseif ($deal->percentUnderUsual !== null && $deal->percentUnderUsual < 0)
That is {{ abs($deal->percentUnderUsual) }}% above its usual {{ $deal->usual() }}.
@elseif ($deal->usualCents !== null)
Its usual price is {{ $deal->usual() }}.
@endif
@if ($deal->departureDate !== null)
Leaving {{ $deal->departureDay() }}.
@endif
@if ($deal->score !== null && $deal->score > 0)
@php($score = 'Orbit scores it '.$deal->score.'/100'.($deal->verdict === null ? '' : ' — '.$deal->verdict))
{{ $score }}.
@elseif ($deal->verdict !== null)
{{ $deal->verdict }}.
@endif

