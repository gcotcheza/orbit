{{--
    One rule's new matches — every one of them, in one mail.

    THE RULE IS QUOTED BACK, both as the sentence that was typed and as the
    chips it was actually reduced to. Those are two different facts (see
    database/migrations' deal_rules table) and the second is the one that
    explains a match somebody did not expect: a rule whose "From EIN" chip was
    removed matches Düsseldorf, and the sentence alone would not say so.

    THE TABLE IS CAPPED AND THE REST ARE COUNTED. Thirty rows is not a mail
    anybody reads to the end of.

    @var \App\Application\Alerts\RuleMatchNotice $notice
    @var list<\App\Application\Alerts\DealSummary> $deals
    @var int $more
--}}
@component('mail::message')
# {{ count($notice->deals) === 1 ? 'A new match' : count($notice->deals).' new matches' }}

Your rule: *“{{ $notice->ruleText }}”*

@if ($notice->chips !== [])
**{{ implode(' · ', $notice->chips) }}**
@endif

@component('mail::table')
| Trip | Departs | Price |
| :--- | :--- | ---: |
@foreach ($deals as $deal)
| {{ $deal->journey() }} | {{ $deal->departureDay() }} | **{{ $deal->price() }}** |
@endforeach
@endcomponent

@if ($more > 0)
…and {{ $more }} more at or under your cap. Open Orbit to see the rest.
@endif

@component('mail::button', ['url' => $notice->cheapest->bookingUrl])
See {{ $notice->cheapest->pair() }} at {{ $notice->cheapest->price() }}
@endcomponent

@component('mail::subcopy')
These are new since Orbit last wrote to you about them. A route stays quiet for {{ config('orbit.alerts.cooldown_hours') }} hours after it is mentioned, unless its fare falls another {{ config('orbit.alerts.further_drop_percent') }}%. Pause the rule on the Alerts screen to stop these.
@endcomponent
@endcomponent
