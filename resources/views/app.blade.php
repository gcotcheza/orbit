{{--
    The whole server-rendered surface of Orbit.

    Every screen is a Vue view mounted into #app by resources/js/app.js, so this
    file is asked for by every path the catch-all in routes/web.php matches —
    including /login. It must therefore contain nothing that depends on WHICH
    path was asked for and nothing that depends on WHO is asking: the same bytes
    are served to a guest and to the signed-in owner, and GET /api/me is what
    tells the client which of the two it is.

    NO INLINE SCRIPT ANYWHERE IN THIS FILE. deploy/nginx/flights-ghiecode.conf
    ships `script-src 'self'` (Content-Security-Policy-Report-Only today, to be
    promoted), and the usual anti-flash trick — a two-line inline script that
    reads the stored theme before first paint — is exactly what that directive
    blocks. The cost is that a user who chose the LIGHT theme may see one frame
    of the dark background while the module bundle boots; the fix, if it ever
    matters, is to mirror the choice into a cookie this template can read, not
    to punch a hole in the CSP.
--}}
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">

    {{-- `viewport-fit=cover` so the app paints under the notch and the home
         indicator; the tab bar pays that back with env(safe-area-inset-bottom). --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    {{-- Read by resources/js/lib/http.js. The XSRF-TOKEN cookie is the primary
         mechanism (see that file); this is what makes the very first POST of a
         session work without a round trip to /sanctum/csrf-cookie first. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- The dark theme's --bg. Rewritten by the theme store when the user
         switches, so the browser chrome follows the app rather than lagging a
         theme behind. --}}
    <meta name="theme-color" content="#0a0f1e">

    {{-- Tells the UA which form controls and scrollbars to draw, and which
         background to paint before any of our CSS has arrived. Dark first,
         because dark is the default. --}}
    <meta name="color-scheme" content="dark light">

    <meta name="robots" content="noindex, nofollow">

    <title>Orbit</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>
