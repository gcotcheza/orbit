# Orbit — the browser gate

```bash
scripts/e2e.sh            # the whole thing: build, seed, drive, destroy
```

Run it **as root**, not as `sudo -u orbit`. It needs `/var/run/docker.sock`,
which is a group membership `orbit` does not have; nothing it does writes to the
checkout as root, because every container it starts runs as `115:119`.

First run pulls a ~2 GB image. Every run after that is about **90 seconds**.

---

## Why this exists

`scripts/check.sh` is seven checks and not one of them has ever seen a screen.
Pint reads style, `composer audit` and `npm audit` read the lockfiles, PHPStan
reads types, ESLint reads the source, Vitest runs the front end's pure functions
in jsdom, PHPUnit exercises the back end through HTTP. All seven are green on an
app whose globe renders as a black circle, whose calendar renders 31 identical
grey squares, and whose login screen answers every password with a spinner that
never stops.

jsdom is the specific gap. It has no layout engine and no rasteriser: every
bounding box is zero, `elementFromPoint` means nothing, CSS animations do not
run and the cascade is not computed. So the three things this harness found on
its first pass — a caption drawn underneath an opaque card, a paused row whose
dimming is overridden by an animation's fill mode, an input that keeps the
characters it claims to strip — are all invisible to every test that existed
before it, and all three are *rendering* faults in code whose logic is correct.

All three were fixed by the follow-ups PR, and each left an ordinary assertion
behind in the spec that found it. That is the return on this harness stated as
plainly as it can be: three defects on a screen that five other checks called
green, in code none of them could have been written to see.

The rule this implements: **an agent must be able to verify a screen in a real
browser.** Not "assert the component mounted". Look at it.

---

## What it does, in order

`scripts/e2e.sh`:

1. **Generates `.env.e2e`** if there is not one — a fresh `APP_KEY`, a throwaway
   database password, and a seeded login (`e2e@orbit.test`) that exists on no
   other box. Gitignored. `--fresh-env` regenerates it.
2. **Checks the prerequisites** it cannot make: `vendor/`, `node_modules/`,
   `public/build/`. Each is built only if missing, because this script is meant
   to be run twenty times an afternoon.
3. **Brings up the `orbit-e2e` stack** on `127.0.0.1:3185` and waits for it to
   be healthy.
4. **Migrates and seeds** — the account, 3,270 airports, six watched routes, and
   sixty mornings of price history replayed through the ordinary poller
   (`FakeHistorySeeder`). This is why the charts have curves and the calendar
   has colours.
5. **Runs Playwright** inside the official image, on the host network.
6. **`down -v`** — containers, network and the in-RAM database, gone. `--keep`
   skips this and leaves the app up on 3185 for poking at by hand;
   `scripts/e2e.sh --down` tears it down afterwards.

Anything after `--` goes to `playwright test`:

```bash
scripts/e2e.sh -- specs/globe.spec.js          # one spec
scripts/e2e.sh -- --update-snapshots=changed   # re-baseline
scripts/e2e.sh --keep -- --grep "heat map"     # one test, stack left up
```

---

## The sandbox

`docker-compose.e2e.yml`, project name **`orbit-e2e`**, four services.

| | production (`docker-compose.yml`) | sandbox (`docker-compose.e2e.yml`) |
| --- | --- | --- |
| compose project | `orbit` | `orbit-e2e` |
| published port | `127.0.0.1:3085` | `127.0.0.1:3185` |
| services | app, horizon, scheduler, web, postgres, redis | app, web, postgres, redis |
| queue | redis + Horizon | `sync` — jobs run inside the request |
| postgres | named volume | **tmpfs**, capped at 256 MB |
| redis | appendonly, named volume | `--save ''`, no volume |
| env file | `.env` | `.env.e2e` (generated, gitignored) |
| session cookie | `Secure` | **not** `Secure` — see below |
| uid, `no-new-privileges`, `cap_drop`, read-only web | | **identical** |

Four separate things keep it off the live stack, and the fourth is the one that
matters when somebody is tired:

1. A different **compose project name** — which is what `up`, `down`, `exec` and
   `down -v` resolve containers, networks *and volumes* through.
2. A different **port**. Both stacks are up at once, routinely; the live site
   does not go down so that a test can run.
3. Its own **env file**, with credentials that are generated rather than
   borrowed.
4. **`ORBIT_E2E` is a required compose variable.** `docker compose -f
   docker-compose.e2e.yml up` without `--env-file .env.e2e` fails at
   interpolation instead of quietly starting the sandbox against production's
   `.env`.

**Hardening is copied, not relaxed.** Non-root uid, `no-new-privileges`,
`cap_drop: ALL`, the read-only nginx sidecar. A sandbox that is easier to run
than the thing it stands in for stops predicting it.

### Three deliberate divergences

- **`QUEUE_CONNECTION=sync`**, which is why there is no `horizon` and no
  `scheduler`. A test that taps "create rule" and then waits for a worker to get
  round to the sweep is a test that fails on a busy afternoon. Running the job
  inside the request makes the assertion the button's own.
- **`SESSION_SECURE_COOKIE=false`**, the one security-relevant setting that
  differs. A `Secure` cookie is not *stored at all* by a browser on a plain-http
  origin, so with production's `true` every request after the login POST arrives
  as a guest — the suite would fail for a reason that has nothing to do with the
  app. (This is the same trap the deploy runbook documents for `curl -c/-b`.)
  The sandbox is http because it is a loopback port with no certificate; TLS
  belongs to the host nginx, which is not in this stack.
- **`ORBIT_NLP_PARSER=regex`, pinned.** `config/orbit.php` switches to the
  Anthropic adapter the moment an `ANTHROPIC_API_KEY` exists. A suite must not
  spend metered tokens, and `rules.spec.js` asserts exact chip text — which a
  model is entitled to phrase differently this morning.

---

## The hostname trick

The browser is bent, not the app.

`bootstrap/app.php` trusts exactly one host, `^flights\.ghiecode\.io$`, and
answers **400** to anything else. That is correct production behaviour and it is
also the thing that makes a browser pointed at `127.0.0.1` untestable.

The harness does not add `localhost` to the allowlist. Instead Chromium is
launched with

```
--host-resolver-rules=MAP flights.ghiecode.io 127.0.0.1
```

and the base URL is `http://flights.ghiecode.io:3185`. Symfony's trusted-host
check runs against `getHost()`, which strips the port, so the allowlist matches
as written. **No application code changed for this** — `bootstrap/app.php`,
`config/orbit.php` and the Sanctum configuration are exercised exactly as
production has them, which is the whole point: a trusted-host list that is only
ever tested by a variant of itself is a list nobody has tested.

Two more pieces make it hold:

- **`docker run --add-host flights.ghiecode.io:127.0.0.1`.** Playwright's
  `request` fixture is a *Node* HTTP client, not the browser, so the Chromium
  flag does not apply to it — `pwa.spec.js` would resolve the name through real
  DNS. `/etc/hosts` covers that path.
- **The port is the backstop.** Even if both mappings failed, `flights.ghiecode.io:3185`
  reaches nothing: production is `:443` behind Cloudflare, the origin publishes
  `127.0.0.1:3085` on loopback only, and 3185 is not one of the ports Cloudflare
  proxies. A mis-resolution is a connection refused, never a test that quietly
  ran against the live site.

---

## SwiftShader, and what may therefore be asserted

This box has no GPU and no display. Chromium is launched with

```
--use-angle=swiftshader --enable-unsafe-swiftshader
```

so WebGL is rasterised on the CPU. The second flag is not optional: from Chrome
137 a page asking for a WebGL context on a software renderer is refused without
it, and Orbit's own `hasWebgl()` probe would then *correctly* decide the browser
cannot draw a globe and render the flat fallback. The suite would pass, having
tested the fallback.

**The rule: assert correctness, never timing.** No spec measures a frame rate, a
duration or an animation's progress. `waitForGlobe()` polls until the canvas has
drawn something and gives it up to 60 seconds to do so; a slow box is a slow
run, not a red one.

### Reading a WebGL canvas

`canvas.toDataURL()` **does not work** and fails in the direction that matters:
three.js creates its context without `preserveDrawingBuffer`, so the buffer is
discarded the moment the compositor has read it, and a call from a test — which
necessarily runs between frames — returns a fully transparent image of the right
size. A test built on it passes on a broken globe.

`sampleCanvas()` screenshots the canvas's box instead (which is what a person
sees), decodes the PNG back inside the page with a 2D canvas, and returns a
5-bit-per-channel histogram: how many distinct colours, and what fraction of the
region is *not* the single commonest one. A photographic earth measures in the
thousands of colours; a black planet, a failed texture or a lost context
measures in single digits. There is nothing in between to tune a tolerance for.

`waitForGlobe()` polls `sampleCanvas()` rather than waiting for the earth
texture's network response, because the texture is 1.4 MB with a week-long
`Cache-Control` (docker/web/nginx.conf) — the second screen in a run that
visits Home twice never requests it again, and a helper built on
`waitForResponse` would hang there forever.

---

## The console guard

Every spec fails on a dirty console. This is an `auto` fixture in
`e2e/fixtures.js`, so it applies whether a spec asks for it or not.

- **`pageerror`** — an uncaught exception or unhandled rejection. Never
  waivable. A Vue component that throws during render leaves the previous screen
  on the page and paints nothing new, so the screen still has a header, a tab bar
  and a background: the screenshot looks plausible and "is the heading visible"
  passes. The exception is the only evidence.
- **`console` at error level** — the app's own `console.error(...)` calls, and
  Chromium's own "Failed to load resource" line for a 4xx/5xx.

The second kind is sometimes correct behaviour, and a spec waives it one regex
at a time, next to the assertion that makes it true:

| spec | waived | why |
| --- | --- | --- |
| `auth.setup`, `login` | `401` | a guest's `/api/me` boot probe is *supposed* to be a 401 (`routes/web.php`) |
| `login` | `422` | a refused password |
| `detail` | `404` | asking for a route that is not tracked |
| `watchlist` | `422`, `Could not add a route` | the only waived **app-level** `console.error` in the suite: `Watchlist.vue` writes the refusal into the form *and* logs it, and the test is about the refusal |

---

## Baselines vs artifacts

Two different things, and conflating them is how a screenshot suite becomes a
suite everybody re-baselines without looking.

**`e2e/baselines/` — committed, compared, fifty-one files.** Eighteen are **the
phone baselines** (below): every screen, both themes, at `maxDiffPixels: 0`.
Thirty-two are **the wide baselines** (below that): eight screens, both themes,
in each of the two wide projects, at the same tolerance. The fifty-first is
`offline`, which is a static page the app serves with no bundle behind it and
has no equivalent in either set.

There used to be a second regime — `login` and `settings-dark`, photographed by
`login.spec.js` and `theme.spec.js`. Both are gone: `login-dark`/`login-light`
and `alerts-dark`/`alerts-light` are the same two screens at the same viewport
with no masks on them, so the old pair was a second promise about the same
pixels. Those two tests kept everything else they asserted — the guest screen
renders, the palette really swaps — and now only leave an artefact behind.

**`e2e/artifacts/screens/` — gitignored, compared to nothing, seventeen files.**
The globe, the calendar, the watchlist, the route detail, the create screen.
These exist to be *looked at*. They are not baselined because their content is
seeded fake data and a WebGL render: the calendar's colours depend on the
month's own min/max, which moves with the calendar date; the globe's pixels
depend on where the auto-tour's camera happens to be and on SwiftShader's
rasterisation. A baseline over any of that is a test that fails on a Tuesday.

Baseline files are stamped with the platform (`login-linux.png`) because a
baseline is a promise about a specific renderer. A run on a developer's macOS
Chromium writes its own rather than failing against one it was never going to
match.

Re-baseline deliberately, and look at the diff:

```bash
scripts/e2e.sh -- --update-snapshots=changed                          # all of them
scripts/e2e.sh -- --update-snapshots=changed specs/phone-baselines.spec.js
scripts/e2e.sh -- --update-snapshots=changed --project=desktop --project=tablet specs/wide-baselines.spec.js
git diff --stat e2e/baselines
```

**Spell the mode, or the spec becomes the mode.** `--update-snapshots` takes an
*optional* argument, so the bare flag followed by a path is parsed as
`--update-snapshots=specs/phone-baselines.spec.js` and Playwright exits with
`argument … is invalid. Allowed choices are all, changed, missing, none.` before
it starts a browser. `=changed` is also the mode you want: it rewrites only the
files whose pixels actually moved, so `git diff` lists the deliberate set and
nothing else.

**Re-record the narrowest set that covers the change.** The flag on its own
covers all fifty-one, which is how a phone regression gets quietly blessed by
somebody who was re-recording the desktop. Name the spec — and, for the wide
set, the projects, since those two files only run there. Everything after `--`
goes to `playwright test`, so `--project` can be repeated.

### The phone baselines

`phone-baselines.spec.js` photographs **every screen in both themes** at the
suite's own 390x844 — home, calendar, route detail, watch, search, the discovery
strip, create, alerts, login — and compares each one at **`maxDiffPixels: 0`**.
It exists for `docs/DESKTOP-LAYOUT-PLAN.md`: every phase of that plan adds CSS
above 768px, and "the phone is untouched" has to be a measurement rather than a
promise. `reducedMotion: 'reduce'` is set for the whole file, which also holds
the globe's camera still.

What makes a 0-pixel tolerance possible on screens full of fares is the **mask
list** at the top of the spec. A masked element is not removed — Playwright
paints a flat box at its own position and size — so the geometry of every price,
gauge and day cell is still compared; only its content is not. Masked: the live
dot, the greeting (it reads the hour), the spotlight's money column and
sparkline, the verdict pills, the rail's prices and tone dots, the calendar's
month subtitle, priced and empty day cells, legend and cheapest banner, the
route detail's price lines, gauge dial, chart, "usual" figure and advice text,
the watch stubs' figures and tracking notes, and the discovery cards' money,
evidence, badge and "seen" lines. Blank calendar cells are deliberately *not*
masked, so the month's own shape is still compared.

**The globe is hidden rather than masked** (`e2e/baseline.css`, passed as
`stylePath`). A mask over `.stage__globe` is a 390x360 box, and the chip, the
hint, the caption and the top 30px of the spotlight card are drawn over that
same area — masking would swallow all four. `visibility: hidden` on the canvas
keeps its box and lets the overlays through; that a globe was drawn at all is
`globe.spec.js`'s assertion, and `waitForGlobe()` runs in this spec too before
the shutter.

**The route detail baseline is `AMS-OPO`**, not `AMS-LIS`, because
`live-price.spec.js` runs earlier and leaves a cached live answer on AMS-LIS
that would be in the picture.

Masks stay even though the clock is now frozen (below) and the content is
therefore deterministic. They are not a tolerance: this gate exists to catch a
*layout* regression from the desktop work, and no amount of CSS above 768px can
change a fare. Masking the fares means a deliberate change to the seeder does
not send eighteen images red for a reason that has nothing to do with the phone.

### The wide baselines

`wide-baselines.spec.js` is the phone set's other half, and it runs in **both**
wide projects: `desktop` (1280x832) and `tablet` (820x1180). It photographs eight
screens in both themes — home, calendar, route detail, watch, search, create,
alerts, login — with the same discipline the phone set uses: the frozen clock
that is on by default, `reducedMotion: 'reduce'`, `animations: 'disabled'`, the
globe hidden through `e2e/baseline.css`, masks over every fare, age and verdict,
and `maxDiffPixels: 0`. Thirty-two images, about 3.8 MB.

Three things about it are worth knowing before re-recording one:

- **The project is in the file name** (`wide-desktop-home-dark-linux.png`).
  `snapshotPathTemplate` is built from `{arg}` and the platform, with no project
  in it, so two projects photographing "home" would otherwise write over each
  other's image and the second one to run would always pass.
- **The images are CSS pixels, not device pixels.** `toHaveScreenshot` defaults
  to `scale: 'css'`, so the tablet project's `deviceScaleFactor: 2` costs nothing
  here: the file is 820x1180 and the comparison surface is the same size as the
  layout it is about. That is why an iPad-shaped baseline weighs what a desktop
  one does.
- **Two of the eight screens have no wide layout, and that is the point.** The
  route detail and the login are `bare` screens: `--shell-max` is retargeted on
  `.app-shell--rail`, not on `:root`, so they stay a 430px column at any width
  (docs/BUSINESS-LOGIC.md §36). A picture of that column at 1280px is what would
  go red the day somebody moved the token to `:root`.

The masks are the phone spec's, plus two additions the frame creates: the master
pane's rows, which the phone has no equivalent of — both their fare
(`.route-row__price`) and their verdict dot (`.route-row__dot`), whose colour is
the seeded fare's opinion and moves with it — and, on the landing page, the whole
route-detail mask list, since the frame draws the panel in the pane beside the
globe.

### A frozen clock

**The sandbox does not run at the current time. It runs at 2026-08-23T09:00:00+02:00,
every day, on both sides of the browser.** Without this, a committed baseline
would be a promise about a date: the fake provider prices a departure date *as
at* an observation date, so the seeded world moves with the calendar — a
spotlight card loses a line when a route stops being below its usual price, a
verdict tone changes a border colour, and the month grid changes shape at a
month boundary. Every one of those is a red gate on a Tuesday for no reason.

| side | how |
| --- | --- |
| app | `E2E_FIXED_NOW` in `.env.e2e` → `config('orbit.e2e.fixed_now')` → `Date::setTestNow()` in `SandboxClockServiceProvider::boot()` |
| browser | the shared `page` fixture in `e2e/fixtures.js` runs `clock.install({ time })` + `clock.resume()` for **every** spec; `phone-baselines.spec.js` adds `timezoneId: 'Europe/Amsterdam'` on top |

`scripts/e2e.sh` owns the value (`E2E_FIXED_NOW` near the top of the script) and
writes it into the generated `.env.e2e`; `e2e/fixtures.js` reads it back out, so
the two sides cannot drift apart.

**The browser's clock starts at the instant and then ticks; it is not pinned.**
`clock.setFixedTime()` is the obvious call and it is the wrong one: a `Date.now()`
that never moves stops **globe.gl applying its own size**, so the canvas keeps
its construction default (390x844) instead of following `.stage__globe`, and
`globe.spec.js`'s landscape test measures a canvas 688px taller than the box it
lives in. `install({ time })` + `resume()` starts the page at `E2E_FIXED_NOW`
and lets it advance in real time, which is all the agreement the app needs — a
run lasts minutes, and every relative label on screen is bucketed in hours.

**On by default, because a spec should not have to remember.** Freezing only
the app is worse than not freezing at all: the seeder stamps a fare at the
frozen instant, a browser on the real clock subtracts the two, and
`calendar.spec.js`'s "Seen just now" becomes "Seen 1 hour ago" partway through
the morning — a spec that passes at 07:40 and fails at 08:05. `detail.spec.js`
carried the same fault more quietly (`expect('.price__seen').toHaveCount(0)` is
a freshness threshold read against the browser's clock). Every such assertion is
now stable by construction rather than by the author having remembered.

**One spec opts out**, through the `sandboxClock` test option:

```js
test.use({ sandboxClock: false })   // globe.spec.js
```

`clock.install()` replaces the page's timers with Playwright's, which is a
change to the machinery every animation runs on. `globe.spec.js` samples what
the tour actually drew, and with fake timers its colour histogram went marginal
— 156 distinct colours where it asserts 200 — because the camera was somewhere
else by the time the shutter went. Nothing in that file reads a clock, so it
takes the real one. **Opt out only for that reason, and say so on the line.** **Changing it invalidates every committed
baseline** — change it, re-record, and read the diff.

**A spec that needs a date must take it from `fixedNow`, never from node.**
Playwright's own process is not frozen — only the app and the browser are — so
`new Date()` in a spec is an hour or a month away from the world under test.
`live-price.spec.js` builds its "three days ago" fare and its "checked at" from
`fixedNow`; `calendar.spec.js` and `rules.spec.js` count months forward from it.
Import it from `e2e/fixtures.js`.

**The guard is `ORBIT_E2E`, not `APP_ENV`.** The sandbox deliberately runs as
`APP_ENV=production` so that the trusted-host list is exercised for real (see
"The hostname trick"), so an `APP_ENV` check would switch the freeze off in the
one place it is wanted. `ORBIT_E2E` is already the required compose variable
that keeps `docker-compose.e2e.yml` off the live `.env`, and production's `.env`
carries neither it nor `E2E_FIXED_NOW` — two independent locks.
`tests/Feature/SandboxClockTest.php` covers the honoured case, the
not-the-sandbox case, the no-instant case and a value that is not a datetime
(which throws rather than being quietly ignored).

**`SESSION_LIFETIME` is ten years in the sandbox because of this.** Laravel
stamps the session cookie and `XSRF-TOKEN` with `now + lifetime`, and `now` is
frozen in 2026: at the usual 120 minutes every cookie the sandbox issues would
already have expired against the browser's *real* clock on any later day, and
the whole suite would run as a logged-out guest with 419s on every write. The
ten years outlive the drift. Nothing else in the app reads that number.

Screenshots are taken with `animations: 'disabled'`, which finishes every
running animation and transition at its end state first. Without it the price
chart is caught a quarter through its 1.2s draw-on and the theme toggle is
caught mid-transition between two palettes — both of which look exactly like app
bugs and are not. Two were reported as such on this branch before the flag went
in.

---

## The specs

| file | what it is about |
| --- | --- |
| `auth.setup.js` | signs in once and saves the session for everything else |
| `login.spec.js` | wrong password → an error on the form; right password → the globe; the login baseline |
| `globe.spec.js` | the earth actually draws; the caption and card agree; a rail chip flies; the KeepAlive survives a tab switch |
| `detail.spec.js` | card → detail hand-off; price, gauge sweep, chart path, Skyscanner deep-link shape; an unknown code |
| `calendar.spec.js` | >20 priced cells with *different* heat colours; a day opens its sheet; a route chip redraws the month |
| `watchlist.spec.js` | pause → the server agrees on a second page load; a paused route leaves the tour; add/remove; refused input |
| `rules.spec.js` | the design's sentence → its exact eight chips, in order; removing one re-matches |
| `pwa.spec.js` | manifest / `sw.js` / `offline` content types and bodies |
| `settings.spec.js` | the "This app" card's Google-checks row — the sandbox is given no SerpAPI key, so the honest note is "Not configured" |
| `theme.spec.js` | the palette really swaps and survives a reload; both themes of Home photographed |
| `phone-baselines.spec.js` | every screen, both themes, at `maxDiffPixels: 0` — the phone-regression guard |
| `layout-smoke.spec.js` | tablet and desktop only: the icon rail replaces the tab bar, nothing scrolls sideways, and the landing page's master pane, `?route=` selection and globe height |
| `layout-screens.spec.js` | tablet and desktop only: the calendar, the watch list, search, the new-rule screen and alerts inside the frame, the landing detail's two columns, and the keyboard — the roving tab stop on the master rows, the focus ring, and the focus a swapped pane hands over |
| `wide-baselines.spec.js` | tablet and desktop only: every screen, both themes, at `maxDiffPixels: 0` — the frame's own regression guard |

### The projects

| project | viewport | runs |
| --- | --- | --- |
| `setup` | — | `auth.setup.js`, once, for the session everything else reuses |
| `chromium` | 390x844, DPR 1 | every spec except the two `layout-*` ones — the phone, and the only project that photographs anything |
| `tablet` | 820x1180, DPR 2 | the two `layout-*` specs and `wide-baselines.spec.js` — an iPad in portrait |
| `desktop` | 1280x832, DPR 1 | the same three, no touch |

`tablet` and `desktop` grew their assertions with each phase of
`docs/DESKTOP-LAYOUT-PLAN.md`, and phase 4 gave them baselines of their own
("The wide baselines", below). The phone project is still the only one that
photographs `offline`.

Both projects assert the frame: the icon rail is on the screen, the tab bar is
**gone** (two navigations offering the same five destinations is what a frame
failing to take over looks like), the rail carries all five, exactly one
account link is on the page (the landing head used to repeat the rail's), and
`documentElement.scrollWidth` equals `innerWidth` on the five tabbed screens.
The rest is split by width with `test.skip(({ viewport }) => …)`, because the
two widths are two different layouts rather than one layout at two sizes:

- **desktop only (≥1024)** — the master pane lists the six seeded routes in the
  seeder's own order and opens on the first; clicking `AMS-OPO` swaps the detail
  pane, lights that row and puts `?route=AMS-OPO` in the URL *without* leaving
  the screen (the master pane and the globe canvas are still there afterwards);
  `/?route=AMS-NAP` opens on that route; and the globe's canvas is over 280px
  tall and over 700px wide, which is the whole point of the phase — a bigger
  screen is a bigger globe rather than a 360px box in a 430px column.
- **tablet only (768–1023)** — no master rows at all, the chip strip carries the
  six routes instead, the detail panel is stacked under a globe that still gets
  its share of the pane, and a chip sets the same `?route=` query.

`layout-screens.spec.js` is phase 2's half of that, and it is where the frame
stops being a frame and starts being screens:

- **the calendar (≥1024)** — the six seeded routes as master rows and no chip
  strip; the month is five or six week rows (counted by distinct cell tops, since
  `buildMonthGrid` pads only the days *before* the 1st) and a cell is square to
  within a pixel; clicking a day draws `.sheet--docked` **to the right of the grid
  card** carrying that cell's own day number and price, with no backdrop, exactly
  one `.sheet` on the page and `role="region"` rather than a modal dialog.
- **the watch list (≥1024)** — exactly one `.pass.is-selected`, at least 1.8x the
  width of the others, which are two abreast on a shared row; the deal rules
  start to the right of it and the "Rules · N" jump chip is gone; clicking
  `AMS-NAP`'s row moves the lead; a route pauses from the pane's own switch,
  dims its master row and **is put back inside the same test**, since every spec
  drives one database.
- **the landing detail (≥1024)** — the chart and the booking pair start to the
  right of the price block and the chart's top is within 4px of the heading's, so
  the two columns really are columns; the globe's canvas clears both its 280px
  floor and 300px, and `stage + panel` heights sum exactly to the pane's, which
  is the leftover-height rule stated as arithmetic.
- **search (≥1024)** — the search card is inside `.screen__master` and the finds
  start to the right of it, two abreast on a shared row; a look-up fills the pane
  with `.detail__code` **without** changing the URL, without losing the rail and
  with `.finds` gone, and the `Deals from your airports` button puts them back.
- **the new rule screen (≥1024)** — `Deal rules` and at least one `.rule` in the
  master, the compose card starting to its right and no wider than 680px, the
  seeded sentence's eight chips, and a CTA that is enabled; removing a chip
  re-reads the sentence to seven without the master going anywhere.
- **alerts (≥1024)** — five `.seclist__item`s; `.set--sensitivity` starts right of
  `.set--channels`, `.set--timing` shares the left column's `x` and sits below
  channels, `.set--account` shares the right one's; clicking TIMING lights that
  row, sets `aria-current` and leaves its heading inside the pane's own box;
  `/alerts#account` lights ACCOUNT and lands on the card; and the weekly-digest
  switch flips, survives a reload and **is put back inside the same test**, since
  every spec drives one database.
- **the keyboard (≥1024, phase 4)** — a Tab journey from the top of the document reaches a rail
  item and then a master row, and each draws a real `:focus-visible` outline (read out of the
  cascade, which is the thing jsdom cannot compute); the routes list offers **one** tab stop and
  the arrows walk it, wrapping at both ends, with Home and End at the ends and the stop travelling
  with the focus; arrowing selects **nothing** and Enter does, which is manual activation stated as
  an assertion; and Escape on the docked day panel hands the focus back to the very element that
  opened it, compared by identity rather than by class.
- **a find opens in the pane (≥1024, phase 4)** — the discovery card is a `BUTTON`, pressing it
  leaves the URL, the rail and the form where they were, drops the finds and puts the panel's
  heading in the pane with the focus on it. `POST /api/routes/lookup` is **blocked** for that
  test: a discovery is a route this sandbox has never priced, and the row the panel would create
  is one no endpoint can remove again. The look-up test beside it uses the seeded `AMS-LIS` for
  exactly the same reason, and is what proves a real route renders there.
- **tablet (768–1023)** — `/calendar`, `/watch`, `/search`, `/create` and
  `/alerts` all still carry `app-shell__main--column` and no `.screen--wide`, with
  no master rows, no docked panel, no section list and no rules list: the phone
  layout, centred in what the rail leaves.

Each of those checks `documentElement.scrollWidth === innerWidth` again with the
pane actually full, because a docked panel, a six-pass grid and a two-column
finds grid are the things in these phases that could push a window sideways.

**A last describe resizes the desktop project to 1024x600 and runs six tests
there**, rather than adding a fifth project — the frame's own floor is one
viewport, not a whole suite, and `page.setViewportSize` re-fires the same
`matchMedia` listeners the composable is built on. What it is for:

- **the sideways guard has a blind spot, and this is it.** `.pass` hides its own
  overflow, so two flex columns shrinking together clip the IATA codes and city
  names off a boarding pass while `scrollWidth === innerWidth` stays perfectly
  true. The test reads `scrollWidth > clientWidth` on every `.end__code` and
  `.end__city` and requires the list of offenders to be empty; the layout answer
  is that `.screen__body` wraps, so the deal rules drop below the passes here.
- **the landing detail scrolls; the globe does not.** At 600px of height a 280px
  globe and the detail do not both fit. `.home__panel` must be the thing that
  scrolls, so the test scrolls it to the bottom and asserts `.home__stage` has the
  same `y` and height afterwards and the master rows are all still there — the
  failure it exists to catch is the overflow escaping to `.app-shell__main` and
  taking the rail's neighbour and the globe with it.
- **the day panel wraps under the month below 1264px**, and the cells stay square
  and larger than 48px while it does — that is the trade the plan's "docked as a
  side panel at ≥1024" turned into, and it is asserted rather than assumed.
- **the finds drop to one column rather than two half ones.** The pane is about
  540px here, and `repeat(auto-fill, minmax(300px, 1fr))` is what makes that one
  card instead of two clipped ones; the test asserts the second card is *below*
  the first, shares its `x`, and is still over 300px wide.
- **the new rule screen is still a master and a pane** — the compose card starts
  to the right of the rules, and the eight chips and an enabled CTA are still
  there, because 600px of height is where a pane would otherwise start eating the
  bottom of the form.
- **the alert cards stay two columns, and only the pane scrolls.** The test
  scrolls `.screen__pane` to the bottom and asserts the rail has not moved and the
  five section rows are still on the page — the same overflow-escaping-upwards
  failure the landing detail's test exists to catch. The rail assertion is made
  only when the scroll actually moved, since the cards may simply fit at this size.
- **and no settings card clips one of its own controls.** The second instance of
  the boarding-pass blind spot: `.card` hides its overflow, and an
  `input[type="time"]` will not shrink past the UA's minimum width, so a narrow
  column cut the quiet-hours *Until* box off in silence. The test sweeps `.card *`
  for `scrollWidth > clientWidth` and requires the list of offenders to be empty;
  the layout answer is that `.window` wraps.

**The landing page is why these two projects now take about 20 seconds.** They
draw the globe, and `waitForGlobe()` polls a SwiftShader render.

Run one of them on its own with the `--` pass-through:

```bash
scripts/e2e.sh -- --project=desktop
```

### A spec that writes must clean up, and prove that it did

The create-screen test in `layout-screens.spec.js` makes a real deal rule (nothing
seeds one) and removes it in a `finally`. Two things about that were wrong the
first time and are worth writing down, because the next spec that writes will hit
both:

- **`page.request` shares the browser's cookies and none of its headers.** These
  routes live in `routes/web.php` behind the `web` group, so CSRF is on; axios
  sends `X-XSRF-TOKEN` (plus `X-Requested-With` and `Accept: application/json`)
  from `lib/http.js`, and `page.request` sends none of it. A bare
  `page.request.delete('/api/rules/1')` answers **419** and deletes nothing. The
  `writeHeaders()` helper reads the `XSRF-TOKEN` cookie out of
  `page.context().cookies()`, URL-decodes it and sends the three headers; the same
  call then answers **204**. Every cleanup write asserts `response.ok()`, because a
  cleanup that quietly does nothing is worse than no cleanup — it looks green.
- **A DOM count cannot answer "is it gone".** The obvious check —
  `page.goto('/create')` then `expect(rows).toHaveCount(before)` — passes whether
  the cleanup worked or not, because an empty list and a list whose `GET /api/rules`
  has not landed yet are both zero `.rule` elements, and `toHaveCount` resolves on
  the first poll that matches. Waiting for `.rules` to be visible does not help
  either: the section draws its heading immediately and the rows arrive later. So
  the assertion is against the API — `ruleIds()` before and after — and the DOM
  count only appears inside the test where it is checking for a *non-zero* count,
  which is a claim a race cannot fake.

**`auth.setup.js` is not an optimisation.** `POST /login` is throttled 5/min on
`email|ip` and the throttle runs *before* validation. Eight specs each signing in
for themselves is eight attempts from one address inside a minute, and the fifth
onwards is a 429 — a suite that fails the way a brute-forcer does. Only
`login.spec.js` signs in for real, because signing in is what it is about.

**One worker, no parallelism, no retries.** Every spec drives the same database;
`watchlist.spec.js` pauses a route and puts it back. Two of those at once and
each is testing a world the other is editing. And a retry turns "this is flaky"
into "this passed", which is the one thing a browser gate must never do.

### `test.fail()` — defects written down as tests

**Nothing in the suite is marked `test.fail()` today, and a `✘` in a run is
therefore a failure.** The mechanism is documented here because it is how the
next one should be handled, and because the three it carried are the reason this
harness exists.

A `test.fail()` inverts the result: it **passes while the bug exists** and goes
red the day it is fixed, which is the reminder to delete it. It is not a skip —
the assertion runs every time — and it is the honest way for a harness to report
a defect in an app it has no business patching in the same branch.

The three it found on its first run, all fixed in the follow-ups PR, each with
an ordinary assertion left in its place:

| spec | defect | fix |
| --- | --- | --- |
| `globe.spec.js` | the globe caption was drawn entirely underneath the opaque spotlight card — `elementFromPoint` at its centre returned `.spotlight` | `--spotlight-overlap` (tokens.css): one number for the card's climb, which the caption now clears |
| `watchlist.spec.js` | a paused row was never dimmed: `.rise-in` was `animation: … both`, its final keyframe is `opacity: 1`, and an animated value beats `.is-paused { opacity: .58 }` in the cascade | `both` → `backwards`, so the entrance stops owning `opacity` when it ends |
| `watchlist.spec.js` | digits typed into the destination box stayed visible — `:value` + `@input` normalising `"12"` to `""` is not a change to a model that is already `""`, so Vue never re-rendered and the DOM kept them | `v-model` plus a normalising watcher: the raw assignment always re-renders, and the directive force-writes the element |

---

## Adding a spec

1. New file in `e2e/specs/`, `*.spec.js`.
2. `import { expect, shot, test } from '../fixtures.js'` — **not** from
   `@playwright/test`, or the console guard does not apply.
3. It starts signed in. Say `test.use({ storageState: { cookies: [], origins: [] } })`
   if it should not.
4. Select by role and by real text where you can. There are **no `data-testid`
   attributes in this app** and this harness deliberately did not add any — a
   test that can only find a button by a hook nobody looks at is a test that
   passes when the button is invisible.
5. End with `await shot(page, 'name')` if the screen is worth looking at.
6. Reach for `toHaveScreenshot` only if the screen is genuinely static.

Three traps this app has already hit — two in what a selector resolves to,
one in what a wait actually proves:

- **The tab bar.** `getByRole('link', { name: 'Alerts' })` is ambiguous on Home:
  the round profile button's aria-label is "Alerts and settings" and accessible
  names match on substring. Use the `tab(page, 'Alerts')` helper.
- **Live class selectors.** `.chip:not(.chip--active)` re-evaluates after the
  click and then resolves to a *different* element. Capture the text first and
  re-find by it.
- **A dated premise that expires.** `rules.spec.js`'s `monthWithNoFares()`
  needs a date window that matches nothing; it used to say "spring" on the
  argument that the fake provider's 90-day window couldn't reach March in
  August — true then, false the moment the calendar rolled far enough, and a
  test that goes red on a date rather than a change takes an afternoon to
  diagnose. It now asks the app: it walks `GET /api/routes/{code}/calendar`
  forward until a month comes back `days: []`, so the premise is stated
  directly rather than inferred from a constant that has already drifted once
  (`poll.window_days` widened from three months to six).
- **A date built from node's clock.** The app and the browser run at
  `E2E_FIXED_NOW`; the test runner does not. `new Date()` in a spec quietly
  disagrees with the screen by however far the real clock has moved, which is a
  spec that passes at 07:40 and fails at 08:05. Use `fixedNow`.
- **A measurement taken a frame too early.** `GlobeStage`'s `ResizeObserver`
  hands the renderer its new size on the next animation frame, so the canvas is
  one frame behind its element — and `shot(page, …)` resizes the viewport under
  both of them while it captures a full page. Poll for the two boxes *together*
  rather than reading them at two different moments.
- **A wait that is already over.** `toHaveCount(0)` on a screen that has not
  fetched anything yet passes in 5 ms, and `toHaveCount(8)` passes on the
  *previous* sentence's eight chips 400 ms before the debounce for yours has
  even fired — so the labels read afterwards are the old reading's, and the tap
  that follows lands in whatever the app does when the real answer arrives.
  Wait for a state only the thing under test can produce: the seeded reading
  first, then zero, then yours. Same rule for scroll positions — poll for
  `scrollY > 0` *together with* the element's box, after an element that only
  the completed fetch can render, or a short half-loaded page satisfies the
  assertion at `scrollY === 0`.

---

## Troubleshooting

| symptom | cause |
| --- | --- |
| `port 3185 is taken by something that is not the orbit-e2e stack` | a previous `--keep` run, or something else. `scripts/e2e.sh --down`. |
| `cannot talk to docker` | run it as root, not `sudo -u orbit`. |
| `EACCES … mkdir '/work/e2e/baselines'` | the checkout is root-owned. `chown -R orbit:orbit .` — this is the `git pull` as root trap the deploy runbook warns about. |
| `.env.e2e is missing` | run `scripts/e2e.sh`, which generates it. |
| `.env.e2e has no E2E_FIXED_NOW` | a file written before the frozen clock; `scripts/e2e.sh` regenerates it by itself. |
| the globe times out in `waitForGlobe` | the earth texture 404'd, or `--enable-unsafe-swiftshader` stopped being accepted by a newer Chromium. Check `e2e/artifacts/report/index.html`. |
| a baseline fails after a legitimate change | `scripts/e2e.sh -- --update-snapshots=changed`, then read `git diff --stat e2e/baselines` before committing. The `=changed` is not optional — see "Re-baseline deliberately". |
| `429` on login | the 5/min throttle. Wait a minute; a full run only spends three attempts. |

Failures leave a trace: `npx playwright show-trace e2e/artifacts/test-results/<test>/trace.zip`,
and an HTML report at `e2e/artifacts/report/index.html`.

---

## Versions move together

`@playwright/test` in `package.json` is pinned **exactly** (`1.62.1`, no caret)
and `scripts/e2e.sh` pins `mcr.microsoft.com/playwright:v1.62.1-noble`. The
browsers live in the image and the driver that speaks to them lives in
`node_modules`; Playwright refuses to run a driver against browsers it did not
ship with. Bumping one without the other is a suite that will not start.

`.npmrc` already carries `ignore-scripts=true`, which is what stops
`@playwright/test`'s transitive `playwright` postinstall from downloading a
second copy of the browsers into `node_modules` on every `npm ci` — including
inside `scripts/check.sh`'s alpine container, where they would not run anyway.
