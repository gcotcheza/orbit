{{--
    The rule, quoted back twice: as the sentence somebody typed, and as the
    chips it was actually reduced to.

    THOSE ARE TWO DIFFERENT FACTS and the second is the one that explains a
    match nobody expected — a rule whose "From EIN" chip was removed matches
    Düsseldorf, and the sentence alone would never say so. The chips come from
    App\Application\Rules\RuleViews, rebuilt from the stored criteria and never
    by re-parsing the text.

    `$label` IS SET WHERE THE RULE IS THE SUBJECT OF THE MAIL and left off where
    it is one of several — "Your rule" above each of four blocks in a Sunday
    digest is a word repeated until it stops being read.

    @var string $text
    @var list<string> $chips
    @var string|null $label
--}}
@props(['text', 'chips' => [], 'label' => null])
<table class="quote" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="quote-cell">
@if ($label !== null)
<p class="eyebrow">{{ $label }}</p>
@endif
<p class="quote-text">&ldquo;{{ $text }}&rdquo;</p>
@if ($chips !== [])
<p class="quote-chips">{{ implode(' · ', $chips) }}</p>
@endif
</td>
</tr>
</table>
