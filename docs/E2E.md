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

`scripts/check.sh` is five checks and not one of them has ever seen a screen.
Pint reads style, PHPStan reads types, ESLint reads the source, Vitest runs the
front end's pure functions in jsdom, PHPUnit exercises the back end through
HTTP. All five are green on an app whose globe renders as a black circle,
whose calendar renders 31 identical grey squares, and whose login screen answers
every password with a spinner that never stops.

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
scripts/e2e.sh -- --update-snapshots           # re-baseline
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

**`e2e/baselines/` — committed, compared.** Two kinds. Three of them —
`login`, `settings-dark`, `offline` — are static screens: no fares on them, no
canvas, no clock, and a pixel difference is a real change to a control or a
token. The rest are **the phone baselines** (below), which are the same idea
applied to screens that are not static, by masking the parts that are not.

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
scripts/e2e.sh -- --update-snapshots                          # all of them
scripts/e2e.sh -- --update-snapshots specs/phone-baselines.spec.js
git diff --stat e2e/baselines
```

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

Two consequences worth knowing before re-recording:

- **The route detail baseline is `AMS-OPO`**, not `AMS-LIS`, because
  `live-price.spec.js` runs earlier and leaves a cached live answer on AMS-LIS
  that would be in the picture.
- **The images are a promise about a day as well as a renderer.** The fake
  provider prices a departure date as at an observation date, so the seeded
  world moves with the calendar: a card's height changes when a route stops
  being below its usual price, and the calendar's grid changes shape at a month
  boundary. Re-record when a run reports differences that are all inside the
  masked regions' geometry, and read `git diff --stat e2e/baselines` before
  committing.

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
| `theme.spec.js` | the palette really swaps and survives a reload; both themes of Home photographed |
| `phone-baselines.spec.js` | every screen, both themes, at `maxDiffPixels: 0` — the phone-regression guard |
| `layout-smoke.spec.js` | tablet and desktop only: the shell renders, the nav is there, nothing scrolls sideways |

### The projects

| project | viewport | runs |
| --- | --- | --- |
| `setup` | — | `auth.setup.js`, once, for the session everything else reuses |
| `chromium` | 390x844, DPR 1 | every spec except `layout-smoke.spec.js` — the phone, and the only project that photographs anything |
| `tablet` | 820x1180, DPR 2 | `layout-smoke.spec.js` only — an iPad in portrait |
| `desktop` | 1280x832, DPR 1 | `layout-smoke.spec.js` only, no touch |

`tablet` and `desktop` are deliberately one small spec each: they assert that
the signed-in shell renders, that the primary navigation is on it, and that
`documentElement.scrollWidth` equals `innerWidth` on the five tabbed screens.
Their assertions grow with each phase of `docs/DESKTOP-LAYOUT-PLAN.md` — phase 1
replaces the tab-bar assertion with the icon rail's, phase 4 gives them
baselines of their own. Until then a wide window is checked, not photographed,
and the suite grows by seconds rather than by minutes.

Run one of them on its own with the `--` pass-through:

```bash
scripts/e2e.sh -- --project=desktop
```

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
| the globe times out in `waitForGlobe` | the earth texture 404'd, or `--enable-unsafe-swiftshader` stopped being accepted by a newer Chromium. Check `e2e/artifacts/report/index.html`. |
| a baseline fails after a legitimate change | `scripts/e2e.sh -- --update-snapshots`, then read `git diff --stat e2e/baselines` before committing. |
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
