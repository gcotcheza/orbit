{{--
    One trip, stacked.

    THIS IS THE THING THAT REPLACED THE TABLE. A fare has five facts on it —
    where, how much, how that compares, when it flies, what Orbit thinks — and
    the four-column table those used to be wrapped every cell into three lines
    on a phone, which is where every one of these mails is read. Stacked, each
    fact gets its own line and the price gets the size it deserves, and nothing
    has to fit into a column width chosen for a different route.

    EVERY LINE IS CONDITIONAL ON THE DATA BEING THERE, and that is not defensive
    coding, it is the difference between the two kinds of deal this component
    draws: a watched route has a usual price and a score, a rule match has a
    date and neither (App\Application\Alerts\DealSummary::forMatch explains why
    inventing the missing ones would be a lie and a query per route). A row that
    said "0% below usual" because the statistics were not there would be worse
    than one that said nothing.

    NO BLANK LINES IN THIS FILE, AND NOTHING INDENTED FOUR SPACES. What this
    renders into is handed to CommonMark by html/layout.blade.php: a blank line
    ends the HTML block and everything after it is re-parsed as Markdown, and
    four spaces of indent is a code fence. Blade's own directives are safe on
    their own line — PHP eats the newline after `?>` — so the conditionals below
    cost nothing.

    @var \App\Application\Alerts\DealSummary $deal
--}}
@props(['deal', 'hero' => false, 'eyebrow' => null])
<table class="card-wrap" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="card-gap">
<table class="card{{ $hero ? ' card-hero' : '' }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="card-cell">
@if ($eyebrow !== null)
<p class="eyebrow">{{ $eyebrow }}</p>
@endif
<p class="journey">{{ $deal->journey() }}</p>
<p class="pair">{{ $deal->pair() }}</p>
<p class="price{{ $hero ? '' : ' price-small' }}">{{ $deal->price() }}</p>
@if ($deal->percentUnderUsual !== null && $deal->percentUnderUsual > 0)
<div class="pill-row"><table class="pill" cellpadding="0" cellspacing="0" role="presentation"><tr><td class="pill-cell pill-good">{{ $deal->percentUnderUsual }}% below usual {{ $deal->usual() }}</td></tr></table></div>
@elseif ($deal->percentUnderUsual !== null && $deal->percentUnderUsual < 0)
<div class="pill-row"><table class="pill" cellpadding="0" cellspacing="0" role="presentation"><tr><td class="pill-cell pill-warn">{{ abs($deal->percentUnderUsual) }}% above usual {{ $deal->usual() }}</td></tr></table></div>
@elseif ($deal->usualCents !== null)
<p class="usual">Its usual price is {{ $deal->usual() }}.</p>
@endif
@if ($deal->departureDate !== null)
<p class="when">Leaving {{ $deal->departureDay() }}</p>
@endif
@if ($deal->score !== null && $deal->score > 0)
@php
    /* Assembled here rather than as `…/100@if(…)`: Blade's directive pattern
       starts `\B@`, so an @if immediately after a digit is not a directive at
       all — but the @endif that closes it follows a `}` and is, which turns the
       next @elseif in the file into a parse error a long way from the cause. */
    $score = 'Orbit scores it '.$deal->score.'/100'.($deal->verdict === null ? '' : ' — '.$deal->verdict);
@endphp
<div class="meter-row"><table class="meter" width="100%" cellpadding="0" cellspacing="0" role="presentation"><tr><td class="meter-track"><table width="{{ $deal->score }}%" cellpadding="0" cellspacing="0" role="presentation"><tr><td class="meter-fill">&nbsp;</td></tr></table></td></tr></table></div>
<p class="meter-label">{{ $score }}</p>
@elseif ($deal->verdict !== null)
<div class="pill-row"><table class="pill" cellpadding="0" cellspacing="0" role="presentation"><tr><td class="pill-cell pill-quiet">{{ $deal->verdict }}</td></tr></table></div>
@endif
</td>
</tr>
</table>
</td>
</tr>
</table>
