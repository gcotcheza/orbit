<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
{{--
    LIGHT AND DARK, WHERE LARAVEL'S DEFAULT SAYS LIGHT ONLY.

    Declaring only `light` is a request to be left alone that Apple Mail
    honours and Gmail ignores — Gmail's Android and iOS apps invert a light
    design by luminance whatever the mail says, so "opt out" is not one of the
    available outcomes. What IS available is choosing between somebody else's
    inversion and one we drew: declaring both hands Apple Mail and Outlook the
    prefers-color-scheme block below, which is the app's own dark palette, and
    leaves Gmail inverting a design whose colours were picked to survive it
    (nothing pure white, nothing pure black — see themes/orbit.css).
--}}
<meta name="color-scheme" content="light dark" />
<meta name="supported-color-schemes" content="light dark" />
<style>
:root {
color-scheme: light dark;
supported-color-schemes: light dark;
}

/* -------------------------------------------------------------------------
   THIS BLOCK IS THE ONLY CSS IN AN ORBIT MAIL THAT REACHES THE READER AS CSS.
   Everything in themes/orbit.css has been written onto style attributes by the
   time this is read, and an inline style beats a stylesheet — which is why
   every declaration here is !important, and why nothing here is a rule that
   could have been inlined instead.
   ------------------------------------------------------------------------- */

@media only screen and (max-width: 620px) {
.content, .footer { width: 100% !important; }
.wrapper-cell { padding: 12px 8px 24px 8px !important; }
.sheet { border-radius: 0 0 14px 14px !important; padding: 24px 18px 28px 18px !important; }
.header { border-radius: 14px 14px 0 0 !important; }
.banner { border-radius: 14px 14px 0 0 !important; }
.footer-cell { padding: 22px 18px 6px 18px !important; }
h1 { font-size: 22px !important; }
.journey { font-size: 19px !important; }
.price { font-size: 34px !important; }
/* AFTER .price AND NOT BEFORE IT: both are !important and equally specific, so
   the later rule wins — and a card in a list would otherwise get a BIGGER
   price on a phone than on a desktop, because the inline 28px it was given
   loses to any !important at all. */
.price-small { font-size: 26px !important; }
.card-cell { padding: 16px 16px 18px 16px !important; }
}

/* The app's own dark palette (resources/css/tokens.css, :root), which is where
   this design started before it was flattened to a light base for the clients
   that have no dark mode to honour. */
@media (prefers-color-scheme: dark) {
body, .wrapper { background-color: #070b16 !important; }
.header { background-color: #0a0f1e !important; }
.sheet { background-color: #111829 !important; }
.card { background-color: #161f33 !important; border-color: #28324a !important; }
.card-hero { background-color: #1b2540 !important; border-color: #374766 !important; }
h1, h3, strong, .journey, .row-title, .flag-text, .quote-text { color: #eef2fc !important; }
p, li, .lead, .when, .subcopy-cell strong { color: #bac6df !important; }
h2, .pair, .usual, .quiet, .row-sub, .row-note, .meter-label, .quote-chips, .subcopy-cell p, .footer-note, .brand { color: #8b97b3 !important; }
h2 { border-top-color: #28324a !important; }
.row-cell { border-bottom-color: #28324a !important; }
.subcopy-cell { border-top-color: #28324a !important; }
.price, .row-price { color: #9fb6ff !important; }
.eyebrow, .flag-dot { color: #ffd166 !important; }
.pill-good { background-color: #12332f !important; color: #6ff0d4 !important; }
.pill-warn { background-color: #3a1a22 !important; color: #ff9fac !important; }
.pill-quiet { background-color: #1f2940 !important; color: #b0bcd8 !important; }
.pill-accent { background-color: #1d2947 !important; color: #9fb6ff !important; }
.meter-track { background-color: #26314a !important; }
.meter-fill { background-color: #5e84ff !important; }
.quote-cell { background-color: #141d30 !important; border-left-color: #5e84ff !important; }
.button-primary { background-color: #5e84ff !important; }
.button-ghost { background-color: #070b16 !important; border-color: #2c3a5c !important; }
.button-ghost-link, a { color: #9fb6ff !important; }
.button-primary-link { color: #ffffff !important; }
}
</style>
{!! $head ?? '' !!}
</head>
<body>
@isset($preheader)
{{--
    WHAT THE INBOX LIST SHOWS AFTER THE SUBJECT. Without it a client takes the
    first text in the body, which here is the banner's alt text, and every
    Orbit mail previews as the word "Orbit". The trailing invisible characters
    stop the client filling the rest of the line with whatever comes next.
--}}
<div class="preheader" style="display: none; max-height: 0; max-width: 0; overflow: hidden; mso-hide: all; font-size: 1px; line-height: 1px; color: #fbfbfe; opacity: 0;">{{ $preheader }}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;</div>
@endisset

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#e9ebf7">
<tr>
<td class="wrapper-cell" align="center">

<table class="content" align="center" width="600" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}
<tr>
<td class="sheet" bgcolor="#fbfbfe">
{!! Illuminate\Mail\Markdown::parse($slot) !!}
{!! $subcopy ?? '' !!}
</td>
</tr>
</table>

{!! $footer ?? '' !!}

</td>
</tr>
</table>
</body>
</html>
