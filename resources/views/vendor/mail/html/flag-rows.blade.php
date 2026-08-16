{{--
    What Orbit already said this week, straight out of the ledger.

    THESE ARE FROZEN HEADLINES and are printed exactly as they were sent —
    App\Application\Alerts\DealSummary::headline(), stored in `alerts.payload`
    the morning it fired. Nothing here re-derives them against today's
    calendar, so a fare that has since gone back up still reads as what was
    flagged; see docs/BUSINESS-LOGIC.md §10, "The ledger".

    ONE LINE EACH, marked with the arc's gold. It is the only place in an Orbit
    mail where that gold is used as ink rather than as a shape, and it is used
    on a bullet, which is a shape with a character code.

    @var list<\App\Application\Alerts\DealSummary> $deals
--}}
@props(['deals'])
<table class="flags" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($deals as $deal)
<tr>
<td class="flag-dot">&#9679;</td>
<td class="flag-text">{{ $deal->headline() }}</td>
</tr>
@endforeach
</table>
