{{--
    The same three things at the bottom of every Orbit mail, and in this order:
    a way in, where to change what arrives, and who sent it.

    THE WAY IN IS AN OUTLINE AND NOT A SLAB. Every mail already has one solid
    accent button in it, aimed at the fare it was sent about; a second identical
    one twenty pixels lower would make the reader choose between two things that
    look equally urgent, one of which is "go and look at the app generally". Same
    accent, drawn as an outline, on the page ground rather than on the sheet.

    IT SITS OFF THE SHEET on purpose — the white card ends, and this is what is
    printed underneath it. A mail that fades out at the bottom edge of its own
    content reads as though it was cut off.
--}}
<table class="footer" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="footer-cell" align="center">
<table class="button" align="center" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="button-cell button-ghost" align="center">
<a href="{{ config('app.url') }}" class="button-link button-ghost-link" target="_blank" rel="noopener">Open Orbit</a>
</td>
</tr>
</table>
<p class="footer-note">{!! $slot !!}</p>
<p class="brand">Orbit — it watches the routes you care about.</p>
</td>
</tr>
</table>
