{{--
    The banner: the mark, the wordmark and the flight path, on the app's own
    background. public/mail/header.svg is the drawing; this points at the PNG
    it is rasterised into.

    THE URL IS ABSOLUTE AND HARD-CODED, which is the one place in this app that
    is true of. A mail is not a page: it is a copy of a document that sits in
    somebody's archive for years and fetches its pictures from wherever it was
    told to at the moment it was written. `asset()` would resolve to whatever
    APP_URL happened to be in the environment that rendered it — http://localhost
    in a test, a staging host in staging — and a mail sent from staging that
    reaches a real inbox would have a permanently broken banner in it. The
    picture is the same picture in every environment, and production is the one
    host that is guaranteed to still be serving it next year.

    IT IS ALSO A LINK, so a reader whose client blocks images still has
    somewhere to tap; and `bgcolor` on the cell means a blocked image leaves the
    navy strip behind rather than a white gap, with the alt text set as the
    wordmark it is standing in for.
--}}
@props(['url'])
<tr>
<td class="header" bgcolor="#0a0f1e" align="center">
<a href="{{ $url }}" style="display: block; text-decoration: none;" target="_blank" rel="noopener">
<img src="https://flights.ghiecode.io/mail/header.png" class="banner" width="600" height="120" alt="{{ trim($slot) }}" />
</a>
</td>
</tr>
