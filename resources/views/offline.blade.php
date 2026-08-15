{{--
    The page the service worker serves when a navigation cannot reach the
    network. resources/js/service-worker.js precaches it by URL, so this is the
    one document in the app that has to render from a cache that may be days
    old.

    IT DEPENDS ON NOTHING. No @vite, no bundle, no font file, no image request
    that could itself be the thing that is offline: the mark is inlined as an
    SVG, the type is the platform's own UI font, and the styles are in the
    document. That is not a shortcut — a fallback page with an external
    dependency is a fallback page that fails in exactly the conditions it exists
    for. The one exception would be @vite, and it is the worst of them: its URLs
    carry the build's content hashes, so this page would break for anybody
    holding a copy from the previous deploy.

    THE STYLE BLOCK IS INLINE and the CSP allows it: deploy/nginx/
    flights-ghiecode.conf ships `style-src 'self' 'unsafe-inline'` while
    `script-src` is `'self'` alone. There is no script here and there must not
    be one.

    THE COLOURS ARE design/README.md's dark theme, written out rather than
    pulled from resources/css/app.css for the reason above. They are six
    literals on a page that says one sentence.
--}}
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="{{ config('orbit.pwa.theme_color') }}">
    <meta name="color-scheme" content="dark">
    <meta name="robots" content="noindex, nofollow">

    <title>Offline · Orbit</title>

    <style>
        :root {
            --bg: #0a0f1e;
            --card: #161f33;
            --ink: #eef2fc;
            --ink2: #bac6df;
            --muted: #7e8aa6;
            --line: #28324a;
            --accent: #5e84ff;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;

            /* design/README.md's app background, both radials. */
            background:
                radial-gradient(125% 58% at 50% -14%, rgba(94, 132, 255, .22) 0%, rgba(94, 132, 255, 0) 58%),
                radial-gradient(90% 55% at 108% 112%, rgba(60, 192, 242, .14) 0%, rgba(60, 192, 242, 0) 60%),
                var(--bg);
            color: var(--ink);

            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
            text-align: center;
        }

        .panel {
            max-width: 340px;
            padding: 28px 22px 24px;
            border: 1px solid var(--line);
            border-radius: 22px;
            background: var(--card);
            box-shadow: 0 1px 2px rgba(0, 0, 0, .38), 0 14px 34px rgba(0, 0, 0, .45);
        }

        .mark {
            width: 64px;
            height: 64px;
            margin: 0 auto 18px;
            display: block;
            border-radius: 18px;
        }

        h1 {
            margin: 0 0 10px;
            font-size: 23px;
            font-weight: 700;
            letter-spacing: -.02em;
        }

        p {
            margin: 0;
            font-size: 13.5px;
            line-height: 1.55;
            color: var(--ink2);
        }

        .note {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--line);
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .15em;
            text-transform: uppercase;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <main class="panel">
        {{-- public/icon.svg, inlined rather than linked: see the note at the
             top of this file. The one difference is the corner radius — the
             file is full-bleed because an OS applies its own mask, and here
             there is no OS to do it. --}}
        <svg class="mark" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Orbit">
            <rect width="512" height="512" rx="140" fill="#0a0f1e"/>
            <ellipse cx="256" cy="256" rx="196" ry="76" transform="rotate(-25 256 256)"
                     fill="none" stroke="#ffd166" stroke-width="22"/>
            <circle cx="256" cy="256" r="108" fill="#5e84ff"/>
            <circle cx="383" cy="149" r="24" fill="#ffd166" stroke="#0a0f1e" stroke-width="12"/>
        </svg>

        <h1>You&rsquo;re offline</h1>

        <p>Orbit will reconnect as soon as your connection does. Nothing is lost &mdash; fares keep being tracked on the server, and any alert waiting for you will still be there.</p>

        <p class="note">Waiting for a network</p>
    </main>
</body>
</html>
