{{--
    A list of trips as cards — what one standing rule found this morning.

    CARDS AND NOT ROWS HERE, unlike the digest, because this list is the whole
    mail: the reader opened it to see the matches, and there are at most
    config('orbit.alerts.mail_deals') of them. The digest lists the same shape
    of thing as rows because there it is one section of five.

    @var list<\App\Application\Alerts\DealSummary> $deals
--}}
@props(['deals'])
@foreach ($deals as $deal)
<x-mail::deal-card :deal="$deal" />
@endforeach
