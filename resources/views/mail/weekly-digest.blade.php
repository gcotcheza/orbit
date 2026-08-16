{{--
    Sunday morning. Everything at once, and nothing urgent.

    THE ONE MAIL THAT REPEATS ITSELF ON PURPOSE. Every other alert is
    suppressed by the cooldown once it has been said; this one lists routes that
    have been quiet all week, because its job is to make a week with no alerts
    legible rather than silent.

    EACH SECTION DISAPPEARS WHEN IT IS EMPTY rather than saying "none". An
    account with no rules should not be told every Sunday that it has no rules;
    App\Jobs\SendWeeklyDigest drops the whole mail when every section would be
    empty.

    THE WATCHLIST IS ROWS AND NOT A FIVE-COLUMN TABLE. Route | Now | Usual |
    Score | verdict is a spreadsheet, and on the phone this is read on every one
    of those columns was about forty pixels wide — "Amsterdam" wrapped after
    "Amster", and a route Orbit has no opinion about yet produced a row of four
    em-dashes that read as a rendering failure rather than as an answer. Now the
    name and its qualifiers are one block that may wrap and the price is one
    that may not, and the no-opinion state is its own quiet sentence, which is
    the one it already had: "Not enough data yet".

    @var \App\Application\Alerts\DigestNotice $digest
--}}
<x-mail::message preheader="Where every route you watch stands, and what your rules are finding.">
# Your week in fares

@if ($digest->week !== [])
Orbit flagged **{{ count($digest->week) }} {{ count($digest->week) === 1 ? 'deal' : 'deals' }}** in the last {{ config('orbit.alerts.digest_days') }} days:

<x-mail::flag-rows :deals="$digest->week" />

@endif
@if ($digest->routes !== [])
## Your watchlist

<x-mail::deal-rows :deals="$digest->routes" />

@endif
@if ($digest->rules !== [])
## Your rules

@foreach ($digest->rules as $rule)
<x-mail::rule-quote :text="$rule->text" />

**{{ $rule->matches }}** {{ $rule->matches === 1 ? 'trip matches' : 'trips match' }} right now:

<x-mail::deal-rows :deals="$rule->deals" />

@endforeach
@endif
@php($cheapest = $digest->cheapest())
@if ($cheapest !== null)
<x-mail::button :url="$cheapest->bookingUrl">
Cheapest right now: {{ $cheapest->pair() }} {{ $cheapest->price() }}
</x-mail::button>
@endif

<x-slot:subcopy>
This is your weekly summary, sent every Sunday morning. Turn it off under Weekly digest on the Alerts screen — the deal alerts themselves are a separate switch and stay on.
</x-slot:subcopy>
</x-mail::message>
