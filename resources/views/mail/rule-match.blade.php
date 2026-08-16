{{--
    One rule's new matches — every one of them, in one mail.

    THE RULE IS QUOTED BACK, both as the sentence that was typed and as the
    chips it was actually reduced to. Those are two different facts (see
    database/migrations' deal_rules table) and the second is the one that
    explains a match somebody did not expect: a rule whose "From EIN" chip was
    removed matches Düsseldorf, and the sentence alone would not say so.

    THE TABLE IS GONE. It was Trip | Departs | Price, and on a phone the first
    column wrapped inside itself while the third had room to spare; the matches
    are stacked cards now, one per fare, which is the same three facts with the
    price given the size it earns. The list is still capped and the rest are
    still counted — thirty rows is not a mail anybody reads to the end of, in
    any layout.

    @var \App\Application\Alerts\RuleMatchNotice $notice
    @var list<\App\Application\Alerts\DealSummary> $deals
    @var int $more
--}}
@php
    $preheader = count($notice->deals) === 1
        ? 'New since Orbit last wrote to you about it.'
        : count($notice->deals).' new since Orbit last wrote to you, cheapest first.';
@endphp
<x-mail::message :preheader="$preheader">
# {{ count($notice->deals) === 1 ? 'A new match' : count($notice->deals).' new matches' }}

<x-mail::rule-quote :text="$notice->ruleText" :chips="$notice->chips" label="Your rule" />

<x-mail::deal-cards :deals="$deals" />

@if ($more > 0)
…and {{ $more }} more at or under your cap. Open Orbit to see the rest.
@endif

<x-mail::button :url="$notice->cheapest->bookingUrl">
See {{ $notice->cheapest->pair() }} at {{ $notice->cheapest->price() }}
</x-mail::button>

<x-slot:subcopy>
These are new since Orbit last wrote to you about them. A route stays quiet for {{ config('orbit.alerts.cooldown_hours') }} hours after it is mentioned, unless its fare falls another {{ config('orbit.alerts.further_drop_percent') }}%. Pause the rule on the Alerts screen to stop these.
</x-slot:subcopy>
</x-mail::message>
