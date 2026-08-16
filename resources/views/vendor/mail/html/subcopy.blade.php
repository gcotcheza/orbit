{{--
    Why this arrived, quoting the actual numbers out of config('orbit.alerts.*').
    Ruled off from the mail above it and set at the size of a footnote, because
    that is what it is: nobody opens an Orbit mail to read it, and everybody
    who wonders why they got one needs it to be exactly here.
--}}
<table class="subcopy" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="subcopy-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
</td>
</tr>
</table>
