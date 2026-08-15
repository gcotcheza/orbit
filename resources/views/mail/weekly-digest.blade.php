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

    @var \App\Application\Alerts\DigestNotice $digest
--}}
@component('mail::message')
# Your week in fares

@if ($digest->week !== [])
Orbit flagged **{{ count($digest->week) }} {{ count($digest->week) === 1 ? 'deal' : 'deals' }}** in the last {{ config('orbit.alerts.digest_days') }} days:

@foreach ($digest->week as $deal)
- **{{ $deal->headline() }}**
@endforeach
@endif

@if ($digest->routes !== [])
## Your watchlist

@component('mail::table')
| Route | Now | Usual | Score | |
| :--- | ---: | ---: | ---: | :--- |
@foreach ($digest->routes as $deal)
| {{ $deal->journey() }} | **{{ $deal->price() }}** | {{ $deal->usual() ?: '—' }} | {{ $deal->score ?? '—' }} | {{ $deal->verdict }} |
@endforeach
@endcomponent
@endif

@if ($digest->rules !== [])
## Your rules

@foreach ($digest->rules as $rule)
*“{{ $rule->text }}”* — **{{ $rule->matches }}** {{ $rule->matches === 1 ? 'trip matches' : 'trips match' }} right now:

@foreach ($rule->deals as $deal)
- {{ $deal->journey() }} — **{{ $deal->price() }}**@if ($deal->departureDate !== null), {{ $deal->departureDay() }}@endif

@endforeach
@endforeach
@endif

@php($cheapest = $digest->cheapest())
@if ($cheapest !== null)
@component('mail::button', ['url' => $cheapest->bookingUrl])
Cheapest right now: {{ $cheapest->pair() }} {{ $cheapest->price() }}
@endcomponent
@endif

@component('mail::subcopy')
This is your weekly summary, sent every Sunday morning. Turn it off under Weekly digest on the Alerts screen — the deal alerts themselves are a separate switch and stay on.
@endcomponent
@endcomponent
