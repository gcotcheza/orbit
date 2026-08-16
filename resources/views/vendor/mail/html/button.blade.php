{{--
    THE PADDING IS ON THE CELL AND NOT ON THE <a>. Outlook's Word engine drops
    padding from inline elements, which turns the usual "bulletproof button"
    into a line of underlined text in the one client nobody can upgrade. A table
    cell takes padding everywhere, so the cell is the button and the anchor is
    just the label inside it. The radius is lost in Word and nowhere else, and a
    square button is not a broken one.

    `$color` names a pair of classes in themes/orbit.css — `primary` (a solid
    accent slab: the fare this mail was sent about) and `ghost` (an outline on
    the page ground: the footer's way into the app).
--}}
@props([
    'url',
    'color' => 'primary',
    'align' => 'center',
])
<table class="action" align="{{ $align }}" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="{{ $align }}">
<table class="button" align="{{ $align }}" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="button-cell button-{{ $color }}" align="center">
<a href="{{ $url }}" class="button-link button-{{ $color }}-link" target="_blank" rel="noopener">{!! $slot !!}</a>
</td>
</tr>
</table>
</td>
</tr>
</table>
