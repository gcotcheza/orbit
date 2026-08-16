{{--
    One watched route, cheap enough to interrupt somebody about.

    THE FIRST LINE IS THE WHOLE MAIL. It is read in a notification shade, and
    everything under it exists for the moment somebody decides to open it: what
    it costs, what it usually costs, when it flies, and one tap to the search.

    THE NUMBERS ARE A CARD AND NOT A PARAGRAPH, which is the only real change
    here. The facts are the same facts and in the same order — price, how that
    compares, when it flies, what Orbit makes of it — but a fare is a thing you
    look at rather than a sentence you read, and it is looked at on a phone.
    The sentences those facts used to be are still sent: they are what
    vendor/mail/text/deal-card.blade.php renders for the plain-text part.

    THE FOOTER SAYS WHY THIS ARRIVED AND QUOTES THE ACTUAL NUMBERS from
    config('orbit.alerts.*'). "Roughly one a day" typed into a template is a
    sentence that goes quietly wrong the day the cooldown is retuned — the same
    reason the sensitivity blurbs live in config rather than in the Vue
    component that shows them.

    @var \App\Application\Alerts\DealSummary $deal
--}}
@php
    $preheader = $deal->departureDate === null
        ? $deal->journey()
        : $deal->journey().', leaving '.$deal->departureDay();
@endphp
<x-mail::message :preheader="$preheader">
# A route you watch just got cheap

<x-mail::deal-card :deal="$deal" hero eyebrow="On your watchlist" />

<x-mail::button :url="$deal->bookingUrl">
See {{ $deal->pair() }} fares
</x-mail::button>

<x-slot:subcopy>
You are getting this because **{{ $deal->routeCode }}** is on your watchlist and its deal score reached the sensitivity you set. Orbit mentions a route at most once every {{ config('orbit.alerts.cooldown_hours') }} hours — sooner only if the fare falls another {{ config('orbit.alerts.further_drop_percent') }}%.
</x-slot:subcopy>
</x-mail::message>
