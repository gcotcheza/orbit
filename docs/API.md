# Orbit — API

The eighteen endpoints the screens are built from — six reads, eleven writes and
one account action.
**This file is the contract**: the globe home, the route detail, the price
calendar, the watchlist, the alerts screen and the rule creator are all built
against these shapes, and every field below has a feature test behind it
(`tests/Feature/WatchlistApiTest`, `RouteDetailApiTest`, `RouteCalendarApiTest`,
`WatchlistWritesTest`, `RouteLookupTest`, `LivePriceCheckTest`,
`SettingsApiTest`, `RulesApiTest`, `AlertsApiTest`, `DestinationsApiTest`,
`PasswordChangeTest`).

One of the eighteen has no screen yet: `GET /api/alerts` is the alert ledger,
and the alerts screen stays settings-only for now.

---

## Conventions

**Auth.** Every endpoint is `auth:sanctum` in cookie/session mode — bar
`PUT /api/profile/password`, which is behind the plain session guard because it
rotates the session it runs in. There are no tokens either way: sign in with
`POST /login`, and the browser's own httpOnly session cookie authenticates
everything afterwards. `resources/js/lib/http.js` is
already configured for it (`withCredentials`, `withXSRFToken`) — use it and
nothing else. A guest gets **401** with `{"message": "Unauthenticated."}`, never
a redirect.

**Writes are CSRF-protected**, because they run in the `web` middleware group
along with everything else here (`routes/web.php` explains why this app has no
`routes/api.php`). `http.js` sends `X-XSRF-TOKEN` on every request, so this
costs a caller that uses it nothing — and a caller that does not gets **419**.

**Validation failures are Laravel's standard 422**:
`{"message": …, "errors": {"field": ["A sentence a person can act on."]}}`.
The messages are written per rule and are meant to be shown as they arrive; the
add-route form has two fields and no room for "The given data was invalid."

**Envelope.** Everything is wrapped in `data`. List endpoints add `meta`; the
calendar adds a `meta` of its own. Do not unwrap in the store and re-wrap in the
component — read `response.data.data`.

**Money is euros, as a JSON number.** Cents are an internal unit and never cross
this boundary. A whole number of euros comes back as an integer (`58`), a fare
with cents as a two-decimal number (`57.45`). JavaScript sees a `Number` either
way; round for display as the design does.

**Dates are `YYYY-MM-DD` strings**, in the owner's timezone
(`Europe/Amsterdam`). There are three different date axes in this API and mixing
them is the easiest mistake to make here:

| axis | where | means |
| --- | --- | --- |
| **observation date** | `history[].date`, `trackingDays` | the day *we looked* |
| **departure date** | `calendar days[].date`, `cheapest.date` | the day *you fly* |
| **found-at** | `days[].foundAt`, `cheapest.foundAt` | the moment *the price was found* |

The third one is newer than the other two and is the only one that is a
**timestamp** rather than a day — `meta.fares.fetchedAt` is the same shape. It
exists because Orbit's fares come from a cache of other people's searches: a
price fetched at 06:10 this morning may have been *found* four days ago, and
until the app said so it was showing €36 for a date whose live cheapest was
€56. **`fetchedAt` is not a stand-in for it** — that is when Orbit asked, which
says nothing about how old the answer is, and treating the two as one is the bug
this axis was added to fix.

**Null means "not known yet", never zero.** A route added this morning has
`price.current: null`, `stats: null` and `score: 0` with `confident: false`.
Render that as the design's "tracking N days" note, not as a €0 fare or a
damning gauge. The same `confident: false` state holds for the route's first
week even once prices arrive — see "The day-1 floor".

---

## Shared: the route summary

`GET /api/watchlist` returns a list of these; `GET /api/routes/{code}` returns
one with more fields on it. Identical either way, so a component can take either.

```json
{
  "code": "AMS-OPO",
  "origin":      { "iata": "AMS", "city": "Amsterdam", "country": "Netherlands", "countryCode": "NL", "lat": 52.3105, "lng": 4.7683 },
  "destination": { "iata": "OPO", "city": "Porto",     "country": "Portugal",    "countryCode": "PT", "lat": 41.2481, "lng": -8.6814 },
  "price":   { "current": 44, "usual": 93, "pctBelow": 53 },
  "score":   100,
  "tier":    "insane",
  "confident": true,
  "verdict": { "label": "Cheap & still falling", "short": "Falling", "tone": "info" },
  "sparkline": [46, 46, 45, 45, 45, 45, 44, 44, 44, 44, 44, 44, 44, 44],
  "trackingDays": 60,
  "cheapest": { "date": "2026-09-15", "price": 44 }
}
```

| field | notes |
| --- | --- |
| `code` | `AMS-OPO`. The URL key, and the only id the client needs. |
| `origin` / `destination` | `lat`/`lng` are the AIRPORT's, for the globe's great-circle arc. `countryCode` is what the design's flag swatches key off. |
| `price.current` | The cheapest fare in the next ~6 months (`orbit.poll.window_days`, 181 days), as of the last poll. **Six and not eleven, deliberately**: the calendar runs to `orbit.poll.horizon_days` but the observation this comes from is always taken over the near window, so the number means the same thing on the one morning a week the poller fetches further. Same number as the last point of `sparkline`. **`null`** before the first poll. |
| `price.usual` | The route's median price from the statistics provider. **`null`** when it has none. |
| `price.pctBelow` | Whole percent under `usual`; **negative when above it** ("14% above usual" is `-14`). `null` when either half is missing. |
| `score` | 0–100. See "How the score works" below. |
| `tier` | `insane` (≥80) / `great` (≥65) / `good` (≥50) / `none`. What the alert sensitivities in PR11 fire on. |
| `confident` | `false` means **Orbit is not expressing an opinion**: `score: 0`, `tier: "none"` and the "Not enough data yet" verdict. It is false with no prices and no statistics, *and* for the first `orbit.alerts.min_tracking_days` (7) mornings of a route's life — see "The day-1 floor" below. **Branch on this**, not on `score === 0`. |
| `verdict.label` | The sentence: spotlight card, route-detail header. |
| `verdict.short` | The single word the watchlist pill has room for: `Good` / `Falling` / `Normal` / `Wait`, and `New` when `confident` is false. `New` and `Normal` are deliberately different words: "we have not learned this route yet" and "we looked, and it is ordinary" are different answers, and they sit next to each other on the watchlist. |
| `verdict.tone` | `good` \| `info` \| `normal` \| `warn`. **The only thing to switch colours on** — maps onto the token pairs in `resources/css/tokens.css`. Do not derive a colour from `label`. |
| `sparkline` | Up to 14 daily prices, **oldest first**, one per day we polled. Often fewer, and `[]` for a new route. Draw whatever arrives. |
| `trackingDays` | Calendar days since the first observation we actually hold, inclusive. `0` when there are none. This is the number for the "tracking N days" note (`< 14` is the design's threshold). |
| `cheapest` | **The day `price.current` is for**: the cheapest **departure date** still on offer *inside the near window* (`orbit.poll.window_days`), ties broken to the earliest. The bound is what keeps it the same number as `price.current` now that the calendar runs eleven months deep — a cheaper fare out in month nine belongs to the calendar screen, not to a summary the deal score was computed from. `null` before the first poll — and null is not today, so a screen with no date prints no date. This is a *departure* date, the other axis; never render it as an observation date. It was on the detail alone until the UX pass, which is why three screens were printing a fare nobody could act on. On the **summary** it is `{date, price}`; the route detail adds `foundAt` to it. |

### The score's gauge colour

`tier` is the alerting threshold; the detail screen's ring uses a **different**
scale, from `design/README.md` §2 — `≥80 --good`, `≥60 --info`, `≥45 --warn`,
else `--bad`. Compute that from `score` on the client; the API deliberately does
not send a colour.

---

## `GET /api/watchlist`

Everything the owner is watching, **in their own order** (`watchlist_items.position`).
Feeds both the globe home — arcs, route rail and spotlight card, with no
follow-up request per route — and the watchlist screen.

Paused routes are **included**, with `active: false`. Do not filter them out;
the toggle is drawn from them.

```json
{
  "data": [
    {
      "code": "AMS-LIS",
      "origin": { "iata": "AMS", "city": "Amsterdam", "country": "Netherlands", "countryCode": "NL", "lat": 52.3105, "lng": 4.7683 },
      "destination": { "iata": "LIS", "city": "Lisbon", "country": "Portugal", "countryCode": "PT", "lat": 38.7742, "lng": -9.1342 },
      "price": { "current": 74, "usual": 111, "pctBelow": 33 },
      "score": 65,
      "tier": "great",
      "confident": true,
      "verdict": { "label": "Good price — book", "short": "Good", "tone": "good" },
      "sparkline": [65, 66, 66, 67, 68, 69, 69, 70, 71, 71, 72, 73, 73, 74],
      "trackingDays": 60,
      "active": true
    }
  ],
  "meta": { "count": 6, "active": 6 }
}
```

`meta.count` is every row, `meta.active` only the switched-on ones — the "6
routes orbiting" chip should use whichever the globe is actually touring.

---

## `GET /api/routes/{code}`

The route detail screen (`design/README.md` §2). The summary above, plus:

```json
{
  "data": {
    "…": "every field of the summary",

    "history": [
      { "date": "2026-06-17", "price": 70 },
      { "date": "2026-06-18", "price": 70 },
      { "date": "2026-08-15", "price": 44 }
    ],
    "stats": { "min": 46, "p25": 79, "median": 93, "p75": 108, "max": 149 },
    "advice": {
      "title": "Cheap & still falling",
      "body": "€44 against a usual €93, and still sliding — waiting a few days could pay off.",
      "tone": "info"
    },
    "cheapest": {
      "date": "2026-09-15",
      "price": 44,
      "foundAt": "2026-08-11T22:04:13+02:00",
      "mayBeGone": false
    },
    "booking": {
      "aviasales": "https://www.aviasales.com/search/AMS1509OPO1?marker=123456",
      "skyscanner": "https://www.skyscanner.nl/transport/flights/ams/opo/260915/"
    }
  }
}
```

| field | notes |
| --- | --- |
| `history` | Up to 60 daily observations, **oldest first**. This is the line chart; `sparkline` is its last fortnight. Days we did not poll are simply absent — plot by date, not by index. |
| `stats` | The dashed "usual price" reference line, and the five-number summary the score is built from. **`null`** when the provider has none; draw the chart without a reference rather than with one at zero. |
| `advice` | The tinted callout. `title` equals `verdict.label` and `tone` equals `verdict.tone` — generated together, so the prose and the gauge cannot disagree — **except in the two states where the same document doubts its own headline**: when `cheapest.mayBeGone` is true, and when a fresh `meta.liveCheck.lowest` is **dearer** than `cheapest.price`. Then the callout is replaced and `tone` is `warn` while `verdict` is unchanged, because the gauge is still about the price level and the callout is about whether to act on it. **The client renders `advice` and must not compose its own qualification**; the booking hand-off reads `advice.tone` alone. |
| `cheapest.foundAt` | **Detail only** — the summary's `cheapest` carries `date` and `price` alone. When the cheapest fare was *found*, same semantics and same null rule as the calendar's `days[].foundAt`. The detail screen prints "Seen 4 days ago" beside the departure line **only past 24 h**: under that it is the ordinary state of a route polled this morning, and a line nobody needs teaches people to skip the place the important version appears. The three summary-only screens have no room for it and do not get it. |
| `cheapest.mayBeGone` | **Detail only, and the one JUDGEMENT in `data`.** `true` when the cheapest fare was found more than `orbit.live_check.stale_after_hours` (48 h) ago **and** is at least `orbit.live_check.under_usual_percent` (20%) below usual — the combination that put DUS→VCE on screen at €36, "seen 3 days ago", against a live market of about $150. The client **demotes the headline** and labels it ("Seen 3 days ago — may be gone") instead of drawing the app's most confident number over a fare that has probably sold. Both halves are required: age alone is the ordinary state of a quiet route, cheapness alone is what this app is for. **`false` whenever `foundAt` is null** — not-knowing is never demoted. Do not recompute it in a client: the thresholds are the server's. |
| `booking.aviasales` | **The primary hand-off**, aimed at `cheapest.date`. Falls back to Aviasales' *pre-filled search form* (`/?params=AMSOPO1`) when there are no fares — there is no day to show results for, so the reader gets the search box with the route already in it. Carries the affiliate marker when the box has one. Always present. |
| `booking.skyscanner` | The secondary "compare" link, same date. Falls back to the route without a date (`…/ams/opo/`). No marker — this one has never been monetised. Always present. |

**Aviasales is first because that is where the price came from.** Fares reach
Orbit through Travelpayouts, which is Aviasales' cache; the app used to quote
those fares and hand the reader to Skyscanner, a different meta-search with a
different set of agencies. It showed DUS→AGP at €29 against a Skyscanner
cheapest of €68 for the same date — nothing had miscalculated, the two sites
simply never had the same fare. Skyscanner stays as a quiet second opinion.
Neither link is a promise a seat exists: read `foundAt` for how old the number
is, and expect the booking site to be the one holding live availability.

…and a `meta` of two facts about the **asking** rather than about the route:

```json
{
  "data": { "…": "…" },
  "meta": {
    "watched": false,
    "liveCheck": null,
    "fares": { "fetchedAt": "2026-08-15T06:12:07+02:00", "fresh": true }
  }
}
```

| field | notes |
| --- | --- |
| `meta.watched` | Whether **this account** has the route on its watchlist. Draws the "Watch this route" strip on the detail screen; a route that is already watched gets no strip at all, and that screen is unchanged from what it always was. |
| `meta.liveCheck` | What **Google** said when somebody last pressed "Check live price" for `cheapest.date` — **`null`** when nobody has, or when the last answer is older than `orbit.live_check.cooldown_hours` (6 h). Its shape is below, under `POST /api/routes/{code}/live-price`. `null` is what the client draws the button from. |
| `meta.fares.fetchedAt` | When the provider was last asked about this route — the newest `calendar_fares.fetched_at`. **`null`** when Orbit has never got a fare for it. The **only timestamp in this API** (every other date is a bare `YYYY-MM-DD` because it names a day); it is ISO-8601 with the offset, in the owner's timezone. |
| `meta.fares.fresh` | `fetchedAt` is inside `orbit.lookup.fresh_for_hours` (24). **The client's rule is "not fresh AND not watched → ask for a lookup"**: a watched route is polled every morning, so stale fares on one are a broken poll rather than provider calls to spend from somebody's phone. |

`code` is constrained to `[A-Z]{3}-[A-Z]{3}` at the router: **upper case, with
the hyphen**. `ams-lis` does not match and is a 404, not a redirect.

Not scoped to the watchlist — any known route has a detail screen.

**404**: `{"message": "Unknown route."}` — and since "look before you watch",
that is a question rather than a dead end: it is what a pair Orbit has no route
row for answers, and `POST /api/routes/lookup` below is what the screen asks
next.

---

## `GET /api/routes/{code}/calendar?month=YYYY-MM`

One month of the heatmap (`design/README.md` §3). `month` is optional and
defaults to the current one.

```json
{
  "data": {
    "days": [
      { "date": "2026-09-01", "price": 76, "verdict": "pricey", "foundAt": "2026-08-15T06:11:52+02:00" },
      { "date": "2026-09-02", "price": 75, "verdict": "pricey", "foundAt": "2026-08-11T22:04:13+02:00" },
      { "date": "2026-09-15", "price": 44, "verdict": "cheap", "foundAt": null }
    ],
    "min": 44,
    "max": 88,
    "cheapest": { "date": "2026-09-15", "price": 44 }
  },
  "meta": {
    "code": "AMS-OPO",
    "month": "2026-09",
    "booking": {
      "aviasales": "https://www.aviasales.com/search/AMS{ddmm}OPO1?marker=123456",
      "skyscanner": "https://www.skyscanner.nl/transport/flights/ams/opo/{yymmdd}/"
    }
  }
}
```

| field | notes |
| --- | --- |
| `days` | Ordered by date. **Days with no fare are absent**, not null-priced — lay the grid out from `date`, never from the array index. |
| `days[].verdict` | `cheap` \| `mid` \| `pricey`, already computed against this month's own range using the design's thresholds (cheap ≤ low + 28% of the range, pricey ≥ 66%). Use it for the bottom sheet's pill; do not recompute. |
| `days[].foundAt` | **When this price was found — not when Orbit fetched it.** A third date axis (§3): the provider serves a cache of other people's searches, so one grid legitimately mixes a fare found an hour ago with one found last Thursday. ISO-8601 with the offset, in the owner's timezone. **`null` means "not known"** — an older row, or a provider that does not say — and **must render as nothing at all**, never as "just now". |
| `min` / `max` | This month's bounds — the legend gradient's two labels, and the range to interpolate the five-stop heat scale across. `null` for an empty month. |
| `cheapest` | The "★ Cheapest this month" banner. `null` for an empty month. |
| `meta.booking` | The day sheet's two hand-offs, for **whichever** day was tapped, each with a date-shaped hole. **Always present**, including for an empty month — it is a fact about the route, not about the fares. |
| `meta.booking.aviasales` | **The primary link.** Substitute `{ddmm}` with the tapped departure date as **day-then-month, two digits each** (`2026-09-15` → `1509`). Carries the affiliate marker when the box has one. |
| `meta.booking.skyscanner` | The secondary "compare" link. Substitute `{yymmdd}` with the same date as **`yymmdd`** (`2026-09-15` → `260915`). |

**Why the holes are named after date formats.** The two sites want the parts of
a date in different orders and different lengths, so a single `{date}` token
would force the client to know which URL belonged to which site. `{ddmm}` and
`{yymmdd}` keep that knowledge on the server: fill whichever holes a string has
and stay ignorant of who is on the other end. Do not build either URL
client-side — the hosts, the path shapes, the casing and the marker are
`config/orbit.php` and `App\Application\Routes\BookingLink`'s.

**Empty months are a 200, not a 404.** Orbit maintains about eleven months of
calendar (`orbit.poll.horizon_days`, 334 days), so paging past it is normal:
`days: []`, `min`/`max`/`cheapest` all `null`. Draw an empty grid. **An empty
month inside the horizon is normal too** — months 7 to 11 are refreshed once a
week (`orbit.poll.far_refresh_weekday`), the provider's cache thins with
distance, and a route looked up but not watched is only ever priced six months
out. The calendar screen offers this month and eleven more.

**422** when `month` is not `YYYY-MM` with a month of 01–12, with Laravel's
standard `{"message": …, "errors": {"month": […]}}`.

**404**: `{"message": "Unknown route."}`

---

## `POST /api/routes/lookup`

**Look before you watch.** Prices a city pair the owner has not committed to —
the search screen's "Look up" button, and the endpoint that screen exists for.
It began life on the watch screen's add expander (`design/README.md` §5), whose
only action used to be a commitment.

```json
{ "origin": "AMS", "destination": "MAD" }
```

Same two fields as `POST /api/watchlist`, same normalisation, same five
messages — both take their pair from `App\Http\Requests\RoutePairRequest`. The
one rule it does **not** carry is "you are already watching AMS-LIS": looking at
a route is not adding it.

**201** when this request created the route row, **200** when the pair was
already known (watched before and dropped, or swept up by a rule). The body is
**exactly `GET /api/routes/{code}`'s** — `data` plus the same `meta` — so the
detail screen adopts the answer instead of re-fetching.

### What it does, in order

1. **Finds or creates the route.** A route is a fact about the world; a
   watchlist row is this account's relationship to one
   (`docs/BUSINESS-LOGIC.md` §1). This makes the first and **never** the second
   — `WatchlistItem` is untouched on every path, which is the whole point of the
   endpoint.
2. **Fetches fares, inside the request**, when `meta.fares.fresh` would
   otherwise be false: the same `PollRoutePrices` + `RefreshRouteStats` the add
   queues, run synchronously. That is where the one to three seconds go, and why
   the screen says "Checking current fares…" while it waits. Queueing them
   instead would answer with an empty route and no moment at which to look
   again.
3. **Answers with the route as it now stands** — including
   `price.current: null` when the provider had nothing, which is a real answer
   and not a failure.

### What it costs, and the throttle

One miss fetches the **full six-month poll window** — the same one a watched
route gets, so a looked-up fare and a watched one are the same number about the
same months — and Travelpayouts bills **one request per calendar month it
touches**: **six or seven per lookup**, out of the ~200 an hour the token
allows.

| limit | provider requests |
| --- | --- |
| 6 a minute | ≈ 42 |
| 20 an hour | ≈ 140 |

Both apply (`route-lookup` in `App\Providers\AppServiceProvider`), keyed on the
account. Over either: **429**, and the screen says the throttle refused it
rather than blaming the connection.

**Fresh routes cost nothing.** A route with a calendar fare fetched inside
`orbit.lookup.fresh_for_hours` (24) is served from the database with no provider
call at all — and having *asked* is remembered for the same window, so a pair
Travelpayouts has no fares for (an empty answer writes no rows) is not
re-fetched on every view.

**422**: the same table as `POST /api/watchlist` below, minus the
already-watching row. The client shows the sentence — it names which half of the
pair is the problem, which is all the detail screen has to go on when somebody
typed a code Orbit has never heard of.

**A GET would have been wrong.** It creates a row and it can spend money, and a
GET that does either is one a browser prefetch, a link preview or a retry will
eventually do on somebody's behalf. That is also why it is not a `?refresh=1` on
the read above it.

---

## `POST /api/routes/{code}/live-price`

**Go and ask Google.** Orbit's fares are Travelpayouts' cache of other people's
searches, so the headline on a route detail can be a price nobody can buy —
DUS→VCE at €36, seen three days earlier, against a live market of about $150.
This is the "Check live price" button: one live Google Flights search, through
SerpAPI, about the exact departure the screen is showing.

**No body, and no date.** The date checked is the `cheapest.date` in the
document the screen is drawing, read from the same snapshot. A client-supplied
date could ask about a different flight than the one under it — this app's oldest
mistake with a "checked live" label on top — and would be a way to spend the
month one date at a time.

**200** answers **the whole detail document again**, exactly like the lookup, with
`meta.liveCheck` filled in. The client adopts it and the headline swaps; nothing
new has to be parsed.

```json
{
  "data": { "…": "the detail document, unchanged" },
  "meta": {
    "watched": true,
    "liveCheck": {
      "date": "2026-09-15",
      "lowest": 150,
      "typicalLow": 90,
      "typicalHigh": 260,
      "level": "typical",
      "checkedAt": "2026-08-19T18:04:11+02:00"
    },
    "fares": { "…": "…" }
  }
}
```

| field | notes |
| --- | --- |
| `lowest` | The cheapest seat **Google itself** could find, in euros. **`null` when Google had no opinion** — it publishes `price_insights` only where it has enough history, and thin routes routinely come back without it. Null confirms nothing: the client keeps the cached fare, exactly as demoted as it was, and says Google had nothing to add. It is **never** filled in from Orbit's own price. |
| `typicalLow` · `typicalHigh` | Google's own typical band, or `null`. A second "usual" beside Orbit's, from a market Orbit cannot see. |
| `level` | Google's word — `low`, `typical`, `high` — or `null`. |
| `checkedAt` | When **Orbit asked**. ISO-8601 with the offset, in the owner's timezone; the client reads it as "checked just now". The cooldown is measured from it. |

**What it costs, and the four guardrails.** The SerpAPI key is a free plan:
**250 searches a month**. What is *enforced* is the 50-search reserve — nothing
is spent at or below it — and nothing else counts live checks against a monthly
figure. A back-of-envelope projection (250 − 50 reserve − up to 5 a night for
discovery) leaves *roughly* 50 taps a month, but that is an estimate and not a
property of the system: discovery may spend less, and nothing stops a month of
taps from reaching the reserve early. **The reserve is the floor; the projection
is arithmetic.** So one tap is at most one search, and:

* **the cooldown first** — a check for this route and date inside
  `orbit.live_check.cooldown_hours` (6) is served from the stored row and costs
  **nothing**, whether it is a second tap or a second visit;
* **then the quota**, read from SerpAPI's free `account.json` before any search,
  failing closed on anything unreadable;
* **and the reserve** — at or below `orbit.serpapi.reserve` (50) remaining,
  nothing is spent at all;
* **user-initiated only** — authenticated, CSRF, and throttled **3 a minute /
  10 an hour** (`live-check` in `App\Providers\AppServiceProvider`), keyed on the
  account. Nothing schedules this and nothing takes a list.

**Two 503s, and they are different facts.** Both leave the cached price exactly
where it was, and neither is a 200 with an empty answer — the screen must be able
to tell "Google says €150" from "nobody asked Google".

* `{"message": "Orbit is holding its remaining live checks in reserve."}` — the
  budget said no, or this box has no `SERPAPI_KEY` (the default state of the
  app). Nothing was asked and nothing will be until the quota moves.
* `{"message": "Orbit could not reach Google just now. Nothing was spent — try
  again in a moment."}` — SerpAPI timed out, refused, or answered something that
  was not a finished search in euros. **No search was billed, so no row is
  written, no cooldown starts, and the button stays**: an immediate retry is
  honest here and is not in the case above.

**A row is written only for a search SerpAPI actually billed** — including one
where Google had no opinion (`lowest: null`), which is a real answer and must not
be re-bought every six hours.

**409**: `{"message": "Orbit has no fare for this route to check."}` — a route
with no fares in the window has no departure to ask about. Not a bad request, not
a missing route: a question with no subject.

**429** over the throttle, **404** for an unknown route, **401** for a guest.

---

## `POST /api/watchlist`

Start watching a city pair — the search screen's "Add to watch", and the
"Watch this route" button on a route detail. The second of the two things to do
with a pair, and the one that commits.

```json
{ "origin": "AMS", "destination": "LIS" }
```

Both are IATA codes and both are **upper-cased and trimmed before validation**,
so `" lis "` is accepted — the form may send what was typed.

**201** with the new row in exactly the shape `GET /api/watchlist` returns,
`active: true` and at the end of the owner's order:

```json
{
  "data": {
    "code": "AMS-LIS",
    "origin": { "iata": "AMS", "…": "…" },
    "destination": { "iata": "LIS", "…": "…" },
    "price": { "current": null, "usual": null, "pctBelow": null },
    "score": 0,
    "tier": "none",
    "confident": false,
    "verdict": { "label": "Not enough data yet", "short": "Normal", "tone": "normal" },
    "sparkline": [],
    "trackingDays": 0,
    "active": true
  }
}
```

**`confident: false` on a brand-new route is correct, not a failure.** The
first poll and the first statistics refresh are **queued**, not run inside the
request — the response is written before either has started. Render the row's
"no opinion yet" state and let the next load fill it in. A pair that Orbit
already has a route for (watched before and dropped, or surfaced by a rule)
comes back with its existing history immediately.

**422**, one message per rule:

| when | field | message |
| --- | --- | --- |
| no such airport | `origin` / `destination` | Orbit does not know that airport yet. / …an airport with that code. |
| not three letters | either | An airport code is three letters, like LIS. |
| both ends the same | `destination` | A route needs two different airports. |
| already on the watchlist | `destination` | You are already watching AMS-LIS. |

**Both ends may be any code in the `airports` table, which since world flights
is every scheduled airport on Earth** — 3,270 of them, from the OurAirports
snapshot in `database/seeders/data/world_airports.csv`. That is broader than
what the search screen's curated list *offers* — see `GET /api/destinations`
and `GET /api/airports` below.

⚠ **The origin used to be closed and is not any more** (2026-08-16, the search
screen). There was a fifth message here — `Orbit only tracks departures from
AMS, EIN or DUS.` — and a `Rule::in(config('orbit.origins'))` behind it. A
client that special-cases that sentence can drop the branch; nothing else about
either endpoint changed, and there is no migration.

**`config('orbit.origins')` still exists and still means something**, just not
this: it is the three origins a deal *rule* may fire from, and therefore the
size of the nightly sweep (`docs/BUSINESS-LOGIC.md` §1 and §11). A pair typed
into a box is one question; a rule is a standing one Orbit answers on its own
every night, and only the second has a budget.

---

## `PATCH /api/watchlist/{code}`

The design's iOS switch (§5). Pause a route or start it again.

```json
{ "active": false }
```

**200** with the row, in the same shape as everywhere else — take what comes
back rather than keeping the optimistic value:

```json
{ "data": { "code": "AMS-LIS", "…": "…", "active": false } }
```

`active` is **required**; an empty body is `422`, not a no-op
(`{"errors": {"active": ["Say whether the route should be on or off."]}}`).

A pause stops the polling and the alerts and keeps everything else — the row,
its position, and every observation already gathered.

**404** `{"message": "Not watching that route."}` when the code is not on
*this* account's watchlist. `code` is constrained to `[A-Z]{3}-[A-Z]{3}` at the
router, so `ams-lis` is a 404 too.

---

## `DELETE /api/watchlist/{code}`

Stop watching. **204**, no body.

**The route and its price history survive.** Only the watchlist row goes —
every observation under it was a real morning's fare and adding the pair back
next spring picks up where it left off. The route detail screen is not scoped
to the watchlist, so `/api/routes/AMS-LIS` still answers afterwards.

**404** as above.

---

## `GET /api/destinations`

Everywhere Orbit knows how to fly **to** — the add-route form's typeahead
(`design/README.md` §5's destination box, which assumed the person filling it
in already knew that Bilbao is `BIO`).

**200**, alphabetical by city:

```json
{
  "data": [
    { "iata": "ALC", "city": "Alicante", "country": "Spain", "countryCode": "ES" },
    { "iata": "BIO", "city": "Bilbao", "country": "Spain", "countryCode": "ES" },
    { "…": "…" }
  ],
  "meta": { "count": 184 }
}
```

**A hundred and eighty-four rows, and the whole list every time.** There is no
`?q=`: the list comes from two checked-in files
(`database/seeders/data/european_destinations.php` and `world_destinations.php`),
it is a few kilobytes, and it changes on a deploy rather than during a session.
The client fetches it once when the form opens and filters in the browser, so a
suggestion appears on the keystroke instead of a round trip later.
`Cache-Control: private, max-age=3600` — private because the response is behind
a session, an hour because that is already longer than it can go stale.

**This is the CURATED list, not the airport table.** Since world flights the
`airports` table holds 3,270 rows; the 184 here are the ones a person wrote down,
with vibes and month-by-month warmth attached, and they are the only ones the
rule engine can ever match. Everywhere else is searched through
`GET /api/airports?q=` below. See `docs/BUSINESS-LOGIC.md` §1 for why the two
tiers exist.

**The three origins are not in it.** `AMS`, `EIN` and `DUS` are airports with no
row in `destinations`, which is what makes them departures rather than places to
go, and a dropdown that offered Amsterdam under "From: AMS" would be offering a
route to itself.

**This is not the validation list.** `POST /api/watchlist` still accepts any
code in `airports` — see its `destination.exists` rule — and deliberately: what
a form offers and what the API accepts are two decisions, and narrowing the
second to match a dropdown would break a code somebody typed from memory. Since
world flights that gap is the whole feature rather than a nicety: the curated
list is 184 places, and the API accepts 3,270 at either end.

---

## `GET /api/airports?q=`

Everywhere Orbit can **price** — the other half of the add-route form's
typeahead, and the endpoint that makes "look up JFK" possible.

**Query parameters**

| name | required | notes |
| --- | --- | --- |
| `q` | yes | 2–60 characters, trimmed. Matched against city, airport name, IATA code and country. |

**200**, best match first, at most ten rows:

```json
{
  "data": [
    { "iata": "JFK", "city": "New York", "country": "United States", "countryCode": "US" },
    { "iata": "LGA", "city": "New York", "country": "United States", "countryCode": "US" }
  ],
  "meta": { "count": 2, "query": "new york" }
}
```

**The same four fields `GET /api/destinations` returns**, deliberately: the two
answers are merged into one panel, and a suggestion that arrived from here must
be indistinguishable in shape from one that arrived from there. Which tier a row
belongs to is knowable from the curated list the client already holds, so it is
not a field.

**The ranking**, which is the one the browser applies to the curated list:

1. an exact IATA code — `jfk` is the airport, never a substring of something else;
2. a city that starts with it — `new` is New York before Newark's airport name;
3. an airport name that starts with it — `suvarna` finds `BKK`;
4. a country that starts with it — `indo` finds Bali;
5. anything that merely contains it,

then alphabetically by city, then by code, so the answer is total and a
re-render cannot reshuffle it.

**422** when `q` is missing (`Say what to look for.`) or shorter than two
characters (`Two letters is the shortest thing worth searching for.`). One letter
matches about a third of the table and the ten rows it would return are ten
arbitrary ones.

**Throttled: 60/minute**, keyed on the account — `throttle:airport-search`. It
guards against a debounce that stopped debouncing rather than against a cost;
the client asks at most once per 250 ms of typing.

`Cache-Control: private, max-age=300`, so a backspace is free.

**The origins ARE in this answer**, unlike in `GET /api/destinations`, and the
difference is deliberate. That endpoint answers "where can I fly to" and must
never offer Amsterdam; this one answers "which airport is that", and `DUS-AMS`
is a pair `POST /api/routes/lookup` accepts — an airport search that hid it
would disagree with the API it exists to help somebody use. Since the search
screen it is also the **From** box's only source: the three home airports are
quick chips, and everything else somebody might be departing from is here.

**Each box drops whatever the other one holds**, which is the precise version
of "never suggest a route from a place to itself" — client-side, in
`resources/js/Components/search/AirportField.vue`, because it is a fact about
the form's two fields and not about the airport table.

**Accents are not folded.** The browser folds `Málaga` to `malaga` before
searching the curated list; Postgres would need the `unaccent` extension, which
this database does not install for one typeahead. Every accented city in the
curated set is therefore already answered instantly, client-side.

---

## `GET /api/settings` · `PUT /api/settings`

The alerts screen (`design/README.md` §6). Both verbs answer the same body, so
the screen can PUT and render the response without a follow-up GET.

```json
{
  "data": {
    "emailAlerts": true,
    "pushAlerts": false,
    "weeklyDigest": true,
    "quietHours": true,
    "quietStart": "22:00",
    "quietEnd": "08:00",
    "sensitivity": 0
  },
  "meta": {
    "sensitivities": [
      { "level": 0, "name": "Relaxed",  "minimumScore": 80, "blurb": "Only the truly insane deals — score 80 and up. Rare, and worth clearing a weekend for." },
      { "level": 1, "name": "Balanced", "minimumScore": 65, "blurb": "Anything Orbit rates a great deal — score 65 and up. A handful a month." },
      { "level": 2, "name": "Eager",    "minimumScore": 50, "blurb": "Every fare scoring 50 or better. More to look at, and more that turns out to be ordinary." }
    ],
    "googleChecks": { "left": 199, "reserve": 50, "checkedAt": "2026-08-20T09:14:02+02:00" }
  }
}
```

| field | notes |
| --- | --- |
| `emailAlerts` / `pushAlerts` | Delivery channels. Push does nothing until the PWA has a subscription (PR12); the switch is still stored. |
| `weeklyDigest` | The Sunday 09:00 round-up. |
| `quietHours` | Whether the window below defers delivery. |
| `quietStart` / `quietEnd` | `HH:MM`, **wall clock in `Europe/Amsterdam`** — the one thing this app stores as local time. Kept even while `quietHours` is `false`, so switching it back on restores the window somebody chose. |
| `sensitivity` | `0` \| `1` \| `2`. What it *means* is `meta.sensitivities`. |

**`data` is exactly the writable set and `meta` is exactly the derived set.**
A client can PUT back the `data` object it was handed, unchanged, and that is
the intended flow. `meta.sensitivities` is built from `config/orbit.php` —
`minimumScore` is the same tier number a route's `tier` field is computed
against, so the level you pick and the badge you see cannot disagree. The
`blurb` quotes it; do not re-write that sentence in the component.

`meta.googleChecks` is the SerpAPI month behind "Check live price"
(BUSINESS-LOGIC §31), for the alerts screen's "This app" card. `left` is
`total_searches_left` from the free `account.json` probe; `reserve` is the
config number held back. The probe is **cached for ten minutes, its failures
included** — otherwise a SerpAPI having a bad day would stall every settings
load rather than one in ten minutes. `left` is `null` both when no key is
configured and when the probe could not be read, and **`checkedAt` is what
tells those apart**: it is when Orbit last *asked*, so `null` there means
nobody could ask, while a timestamp next to a `null` `left` means the ask
failed. Nothing here is writable, and a failed probe never fails the request.

**The row is created on first read**, with the defaults above. There is no
"settings not set up yet" state to handle.

### `PUT` takes the whole object

Every field is `required`. It is a PUT and not a PATCH on purpose: once a
boolean is optional, "absent" and "false" are the same request, and the failure
mode is a switch that can be turned on and never off.

**200** with the same body. **422** per rule:

| when | message |
| --- | --- |
| a switch is not a boolean | Laravel's default |
| `quietStart` is not `HH:MM` | Quiet hours start at a time like 22:00. |
| `quietEnd` is not `HH:MM` | Quiet hours end at a time like 08:00. |
| `sensitivity` is not a listed level | Pick one of the three sensitivity levels. |

`date_format:H:i` is the time rule, so `24:00`, `22:60`, `9:00` and `22:00:00`
are all rejected; `00:00` and `23:59` are fine.

---

## How the score works

`app/Domain/Pricing/DealScorer.php`, weights in `config/orbit.php`. Three
components, each 0–100:

- **60% percentile** — where the fare sits in the route's own price distribution
  (`stats`). The bulk of the answer, because it is the only part that knows what
  *this* route normally costs.
- **25% trend** — the least-squares slope of our last 30 observations, as a
  fraction of the fare per day. 50 is flat; ±0.5%/day saturates it. This is what
  separates "cheap, book it" from "cheap and still falling, wait".
- **15% absolute** — how close to the route's own floor the fare is. 100 at
  `stats.min`, 0 at `stats.median` and above.

**Missing inputs shrink the question, not the answer.** A route with no history
is scored on the other two with the weights renormalised — not docked 25 points.
A route with nothing at all returns `score: 0, tier: "none", confident: false`.

### The day-1 floor

**A route Orbit has watched for fewer than `orbit.alerts.min_tracking_days` (7)
mornings is not scored at all.** It answers exactly like a route with no data:
`score: 0`, `tier: "none"`, `confident: false`,
`verdict: "Not enough data yet"` — while `price.current`, `sparkline` and
`trackingDays` stay real, because those are observations rather than opinions.

This is not caution for its own sake. `ORBIT_STATS_PROVIDER=self` computes the
statistics from the fares Orbit has already fetched, so on a route's first
morning the current fare **is** its minimum, its median and its maximum: the
percentile component says 100, the absolute component says 100, and the API
would report `score: 100, tier: "insane", confident: true, "Good price — book"`
for every route on the watchlist, on the strength of one number each.

The same floor gates alerts (`AlertPolicy` answers `immature-data` and sends
nothing), from the same config key, so a screen can never recommend booking a
fare the alert engine considers too young to mention. **Rule matches are not
gated by it** — a fare at or below a maximum price the owner wrote down is true
on day one.

Clients need no new branch: this is the `confident: false` state they already
render as the "tracking N days" note.

`verdict` follows from the score and the trend:

| condition | label | short | tone |
| --- | --- | --- | --- |
| score ≥ 65, falling | Cheap & still falling | Falling | `info` |
| score ≥ 65, steady | Good price — book | Good | `good` |
| score ≥ 50, falling | Falling — worth watching | Falling | `info` |
| score ≥ 50, steady | Around normal | Normal | `normal` |
| score < 50, above usual | Above usual — wait | Wait | `warn` |
| score < 50, otherwise | Around normal | Normal | `normal` |
| no data, or under the day-1 floor | Not enough data yet | Normal | `normal` |

---

## Where the numbers come from

By default both providers are **deterministic fakes** —
`ORBIT_PRICE_PROVIDER=fake`. That is a production adapter, not a test double:
the same route shows the same prices on every deploy, so a screen can be
developed against a stable €44 and a test can assert one.

`ORBIT_PRICE_PROVIDER=travelpayouts` swaps in real one-way fares from
Travelpayouts' `/v2/prices/month-matrix` (`App\Infrastructure\Pricing\
TravelpayoutsPriceProvider`). **No response shape changes**, but two things a
screen already handles stop being theoretical:

- **The calendar has real holes in it.** Travelpayouts serves cached fares from
  other people's searches, and on the six seeded routes 41–87% of the next 90
  days had a price. A day with no fare is **absent**, exactly as documented
  above — never `0`.
- **`price.current` can be `null` for a route with no cached fares at all**,
  which the fake provider could never produce.
- **A calendar day can lose its price again.** A departure date that drops out
  of the provider's cache is kept for a grace period (`poll.stale_after_days`,
  three days) in case it comes back, and is then deleted by the next successful
  poll rather than left standing as a fare nobody can book. Two reads of the
  same month a week apart can therefore differ by more than a price — which the
  contract above already allows, since a day with no fare is simply absent.

`ORBIT_STATS_PROVIDER=self` computes the statistics — `price.usual`,
`price.pctBelow` and 75 of the deal score's 100 points — **from Orbit's own
fares** (`App\Infrastructure\Pricing\SelfStatsProvider`). There is no
third-party alternative: Amadeus' price-analysis endpoint was the plan and their
Self-Service API was decommissioned on 2026-07-17.

Two horizons go into it, and which one dominates depends on how long the route
has been watched:

- **Cross-sectional** — the ~182 `calendar_fares` of the current poll window.
  Available from the **first** poll, which is what lets a route added this
  morning carry a score at all. Its median is *what a typical departure date on
  this route costs right now*.
- **Longitudinal** — the accruing daily history, one row per morning, each of
  them that morning's cheapest fare. It is the better comparison once it exists,
  because `price.current` **is** one of those rows.

They are blended linearly by how much history there is —
`w = min(1, observations / 30)`, then `round((1-w)·cross + w·long)` on each of
the five numbers — so a route is scored cross-sectionally on day 1, half and
half around day 15, and purely against its own past mornings from day 30.
`usual` therefore means *the going rate across the next six months* (never the
eleven the calendar holds — `orbit.selfstats.cross_section_days`) on a new
route and *what this route's cheapest fare has actually been* on a mature one.

**A route with no fares and no history has no statistics at all.** The provider
answers null rather than inventing a distribution, `price.usual` and
`price.pctBelow` come back `null`, and the score is renormalised over the
components that remain (`confident` says so).

Fares are refreshed by `orbit:poll-fares` at 06:10 Europe/Amsterdam and the
statistics by `orbit:refresh-stats` on Monday at 05:40 (`routes/console.php`).
Both can be run by hand:

```
docker compose exec app php artisan orbit:poll-fares --now
docker compose exec app php artisan orbit:refresh-stats --now
```

---

# Deal rules

A rule is a trip described in English — "cheap weekend somewhere sunny in
spring, leaving Friday from any NL airport, under €80" — that Orbit reads into
criteria and then watches for (`design/README.md` §4). Rules and the watchlist
are **separate concepts** (`docs/PLAN.md`): a rule lists what it currently
matches and one tap promotes a match to the watchlist, but a rule never adds a
route on the owner's behalf.

## The shared shape: a reading

Every rules endpoint answers with these three fields, and a saved rule adds
four more on top. One shape, two screens.

```json
{
  "chips": [
    { "id": "origin:AMS",  "category": "From",        "label": "AMS" },
    { "id": "origin:EIN",  "category": "From",        "label": "EIN" },
    { "id": "origin:DUS",  "category": "From",        "label": "DUS" },
    { "id": "max_price",   "category": "Max price",   "label": "€80" },
    { "id": "trip_length", "category": "Trip length", "label": "2–3 nights" },
    { "id": "depart",      "category": "Depart",      "label": "Fridays" },
    { "id": "date_window", "category": "Date window", "label": "Mar – May" },
    { "id": "vibe:sunny",  "category": "Vibe",        "label": "☀ Sunny" }
  ],
  "criteria": {
    "origins": ["AMS", "EIN", "DUS"],
    "maxPriceCents": 8000,
    "tripLengthNights": [2, 3],
    "departDows": [5],
    "dateWindow": { "from": 3, "to": 5, "label": "Mar – May" },
    "vibes": ["sunny"]
  },
  "matches": {
    "count": 6,
    "partial": true,
    "cheapest": 34,
    "sample": [
      {
        "code": "AMS-FAO",
        "origin":      { "iata": "AMS", "city": "Amsterdam", "country": "Netherlands", "countryCode": "NL", "lat": 52.3105, "lng": 4.7683 },
        "destination": { "iata": "FAO", "city": "Faro",      "country": "Portugal",    "countryCode": "PT", "lat": 37.0144, "lng": -7.9659 },
        "cheapest": { "date": "2027-04-09", "price": 34 },
        "watched": false
      }
    ]
  }
}
```

**That is the exact reading of the design's own sentence** — the eight chips in
`design/screenshots/04-create-rule.png`, in that order. The create screen ships
with it pre-typed, and `tests/Unit/Infrastructure/RegexRuleTextParserTest`
asserts it chip for chip.

### Chips

| field | notes |
| --- | --- |
| `id` | **Stable across re-parses of the same sentence** — the kind plus the value, never a position. Send it back in `removed` to take the chip off. A client holds its removed ids while the owner keeps typing, so an id must not start meaning a different chip when a word earlier in the sentence changes. |
| `category` | The eyebrow: `From`, `Max price`, `Trip length`, `Depart`, `Date window`, `Vibe`. Sentence case; upper-casing is the stylesheet's job. |
| `label` | The value under it. Show it verbatim — the en dashes in `2–3 nights` and `Mar – May` and the `☀` on a vibe are the design's, and the vibe wording comes from `config/orbit.php` rather than from the client. |

Chips arrive **in the design's order**: where from, how much, how long, which
day, when, what for. There is one chip per origin and one per vibe, and at most
one of each of the other four.

The chip's own value is **not** published. Removing a chip is `POST
/api/rules/parse` again with its id in `removed`; the server folds what is left
back into criteria. Do not reimplement that fold in the client.

### Criteria

What the surviving chips add up to, and what gets stored.

| field | notes |
| --- | --- |
| `origins` | Subset of `AMS` / `EIN` / `DUS`. **`[]` means all three**, not none — every field here reads absence as "no preference". |
| `maxPriceCents` | **Cents, and the one exception to this API's euros rule.** It is a criterion rather than a fare: it is stored, compared against `calendar_fares.price_cents`, and never rendered as a price — the chip's `label` is what a screen shows. `null` for no ceiling. |
| `tripLengthNights` | `[min, max]`, or `null`. **Parsed, stored and shown — but not matched on.** The provider answers with the cheapest fare per *departure* date and no return leg, so Orbit does not hold the fact this would filter. The chip is still produced because the sentence really does say it; it starts filtering the day the provider grows return fares, with no shape change here. |
| `departDows` | ISO weekday numbers, 1 (Monday) to 7. `[]` means any day. |
| `dateWindow` | `{from, to}` month numbers with the label the chip shows, or `null`. **Months and not dates**: a rule is a standing alert, so "spring" means every spring — a window stored as two dates would expire on its own anniversary. `to < from` wraps the year (winter is `{from: 12, to: 2}`). |
| `vibes` | From the closed nine-word vocabulary in `config/orbit.php` (`beach`, `city`, `culture`, `food`, `islands`, `nature`, `party`, `ski`, `sunny`). `[]` means anywhere. |

### Matches

| field | notes |
| --- | --- |
| `count` | **Every** match, not the length of `sample` — the design's banner quotes it, and a rule matching sixty routes is a rule worth tightening. It is counted over the candidate routes Orbit **has already priced**; see `partial`. |
| `partial` | **`true` means `count` is a floor, not a total**, because some of the routes this rule is about have no fare yet (`SweepRuleFares` prices them after the rule is saved). Phrase the banner as *"At least 6 match so far — Orbit is still pricing the rest"* rather than as a total: the measured jump was **2 before saving and 32 a minute after**, which reads as the app having been wrong when it was only busy. `false` means every candidate has been priced and the number is final. **Do not quote `cheapest` while this is true** — it is a superlative over a set that is still being assembled. |
| `cheapest` | Euros, as everywhere else. `null` when nothing matched. |
| `sample` | Up to six, **cheapest first**, ties broken by route code. Both ends are the same airport object every other screen gets, so a rule row can draw a city name and a flag without a second request. |
| `sample[].cheapest` | The cheapest departure **that fits the rule** — not the route's cheapest fare. A rule about Fridays is answered by the cheapest Friday; quoting Tuesday's €38 would be a price nobody can book. |
| `sample[].watched` | Already on this account's watchlist. Branch the one-tap "watch" button on it, or it offers to add something that comes back 422. |

**An empty `matches` is normal, not an error.** A rule created ten seconds ago
matches nothing until `SweepRuleFares` has priced the routes it named — the
same day-1 honesty a new watchlist route has. Say so in words rather than
rendering "0 trips".

---

## `POST /api/rules/parse`

Read a sentence back. **Writes nothing and queues nothing.**

```json
{ "text": "cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80", "removed": ["origin:EIN"] }
```

**200** with `{"data": …}` in the reading shape above.

| field | notes |
| --- | --- |
| `text` | Required (the key must be present), may be empty, max 500 characters. An empty box is a **200 with no chips**, not a 422 — the create screen calls this on a 500 ms debounce while somebody types, including while they delete. |
| `removed` | Optional list of chip ids to leave out, max 50. **Unknown ids are ignored, deliberately**: a client holds this list across re-parses of a sentence still being edited, so an id the current text no longer produces is the ordinary case. |

**A POST that is a read**, because the sentence is 500 characters of free text
and a query string would put it in every access log and browser history between
here and the phone. It also takes exactly the body `POST /api/rules` takes, so
a screen can hand its last parse straight on.

**Throttled: 20 a minute, keyed on the account** — the only endpoint here that
is, bar login. It runs regexes today and becomes a metered third-party call the
day an Anthropic key lands in `.env`; a limiter added on that day would be a
limiter tuned in a hurry. **429** with Laravel's standard body over the limit.

**422** when `text` is missing (`Send the text to read, even if it is empty.`)
or longer than 500 characters.

---

## `GET /api/rules`

Every rule on this account, **newest first**, each with what it matches right
now. Paused rules are included with `active: false` — the switch that turns one
back on lives on the row that turned it off.

```json
{
  "data": [
    {
      "id": 3,
      "text": "cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80",
      "active": true,
      "createdAt": "2026-08-15T09:12:44+00:00",
      "chips": [ "…as above…" ],
      "criteria": { "…": "…" },
      "matches": { "…": "…" }
    }
  ],
  "meta": { "count": 2, "active": 1 }
}
```

| field | notes |
| --- | --- |
| `text` | What was typed. **Not derivable from the chips** — a rule whose chips read "From AMS · Max €80" could have been written a dozen ways, and this is the one the textarea should show. |
| `active` | Paused rules match nothing and are never swept. |
| `chips` | **Rebuilt from the stored criteria, never by re-parsing `text`.** The criteria are what the owner accepted after removing the chips they disagreed with; re-parsing would put every removed chip straight back. |

The match counts are computed per request rather than stored: "how many trips
match" is a fact about this morning's fares, and a cached count is a number
that is wrong from the next poll onwards.

---

## `POST /api/rules`

Save the rule currently on the create screen. Same body as `/parse`.

```json
{ "text": "cheap weekend somewhere sunny in spring, under €80", "removed": ["depart"] }
```

**201** with the row, in exactly the shape `GET /api/rules` returns.

**The sweep is queued, not run.** A new rule names routes Orbit has never
priced, so its honest match count on creation is often zero and fills in within
the hour — running thirty provider calls inside the request would put the tap
behind them.

**422** when nothing could be read out of the sentence, or when every chip was
removed:

```json
{ "message": "…", "errors": { "text": ["Orbit could not read a trip out of that. Try naming a price, a season, a day or what the trip is for."] } }
```

Empty criteria would mean "every fare from every airport to everywhere, at any
price" — not a deal tracker, a firehose. That is also exactly the state
somebody reaches by removing every chip, so the message names the way out.

---

## `PATCH /api/rules/{id}`

Pause a rule or start it again.

```json
{ "active": false }
```

**200** with the row. `active` is **required** — an empty body is a 422, not a
no-op (`Say whether the rule should be on or off.`), for the same reason the
watchlist toggle requires it: once a boolean is optional, "absent" and "false"
are the same request.

Resuming a rule re-queues its sweep; pausing queues nothing.

**404** `{"message": "No such rule."}` when the id is not on *this* account.
`id` is constrained to digits at the router, so `/api/rules/abc` is a 404 too.

---

## `DELETE /api/rules/{id}`

Drop a rule. **204**, no body.

**The routes it surfaced survive, and so do their fares.** Every one cost a
provider call, several may be on the watchlist by now, and a rule is a question
rather than a possession — deleting the question does not unask what it already
found out.

**404** as above.

---

## Promoting a match

There is no "add this match" endpoint. Use the existing `POST /api/watchlist`
with the match's two IATA codes; it reuses the route the rule created, so the
history the sweep already paid for comes with it. The response is a watchlist
row, so a screen holding that list can drop it straight in.

---

## How a rule is read

`config('orbit.nlp.parser')` picks the adapter behind
`App\Application\Ports\RuleTextParser`:

- **`regex`** — the default, and what production runs today. Deterministic, no
  network, no key. It reads prices, airports by code or city, "any NL airport",
  weekdays, weekends, trip lengths, seasons, months and month ranges, and the
  vibe vocabulary.
- **`anthropic`** — selected automatically when `ANTHROPIC_API_KEY` is set.
  Claude Haiku with a server-enforced JSON schema built from the same
  vocabulary, so the model cannot answer with an airport this app does not fly
  from or a vibe no destination carries.

**The two are layered, not alternatives.** The Anthropic adapter composes the
regex one and hands over whenever the model refuses, runs out of room, answers
unreadably or cannot be reached — so a bad afternoon at a third party costs a
less clever reading rather than a broken screen. **No response shape changes
either way**, and nothing above tells a client which one answered.

Rules are swept by `orbit:sweep-rules` at 06:40 Europe/Amsterdam, after the
06:10 fare poll — it skips any route the poll has already priced, which only
works in that order.

**A sweep is shallower than a poll.** The watchlist is priced six months ahead
every morning and eleven once a week (`orbit.poll.window_days`,
`orbit.poll.horizon_days`); a rule's speculative routes are priced three
(`orbit.rules.sweep_horizon_days`), because the provider bills per calendar
month and thirty routes × six months is more requests than it allows in an
hour — thirty × eleven is not close. A rule whose date window names a month
beyond that still matches on any route Orbit already holds fares for — matching
reads `calendar_fares`, and a watched route's calendar now runs eleven months —
but city pairs nobody watches are not priced that far out until the calendar
rolls toward the month.
This affects `matches.count`, never the shape of a response.

It can be run by hand:

```
docker compose exec app php artisan orbit:sweep-rules --now
```

---

## `GET /api/alerts`

Everything Orbit has decided to tell this account, **newest first**. The alert
ledger — what fired, what it said, and whether it actually went out.

```json
{
  "data": [
    {
      "id": 41,
      "type": "route_deal",
      "route": "AMS-OPO",
      "rule": null,
      "score": 94,
      "price": 44,
      "triggeredAt": "2026-08-15T04:55:00+00:00",
      "deliveredAt": "2026-08-15T06:00:00+00:00",
      "summary": "AMS→OPO €44 — 53% below usual"
    },
    {
      "id": 40,
      "type": "rule_match",
      "route": "AMS-FAO",
      "rule": { "id": 3, "text": "a beach weekend under €80", "chips": ["AMS", "€80", "🏖 Beach"] },
      "score": null,
      "price": 39,
      "triggeredAt": "2026-08-14T04:55:00+00:00",
      "deliveredAt": "2026-08-14T04:55:00+00:00",
      "summary": "AMS→FAO €39 — Fri 11 Sep"
    },
    {
      "id": 38,
      "type": "weekly_digest",
      "route": null,
      "rule": null,
      "score": null,
      "price": null,
      "triggeredAt": "2026-08-09T07:00:00+00:00",
      "deliveredAt": "2026-08-09T07:00:00+00:00",
      "summary": "Your week in fares — 3 deals Orbit flagged"
    }
  ],
  "meta": { "count": 3, "limit": 20, "total": 47 }
}
```

| field | notes |
| --- | --- |
| `type` | `route_deal` \| `rule_match` \| `weekly_digest`. |
| `route` | The route **code**, the same key every other endpoint uses. `null` on the digest, which is about no route in particular. |
| `rule` | `{id, text, chips}` on a rule match, `null` otherwise. **It survives the rule being deleted** — the row keeps what the rule said, and `id` then points at nothing. |
| `score` | 0–100 for a watched route. **`null` on a rule match**, which has no score: the rule's own maximum price was its threshold. |
| `price` | Euros, as everywhere else. `null` on the digest. |
| `triggeredAt` | When Orbit **decided**. This is what the cooldown measures from. |
| `deliveredAt` | When a channel actually took it. **`null` while quiet hours hold a mail** — and permanently null if that channel is switched off. |
| `summary` | The subject line that landed, stored at send time. Not re-rendered, so it still reads the way the mail did. |

**Everything except `id` and the timestamps is frozen history.** A row from
March quotes March's price and March's percentage under usual; it is not
recomputed against today's statistics, because the question this endpoint
answers is "what did Orbit say", not "what is true now".

| query | notes |
| --- | --- |
| `limit` | Optional, 1–50, default **20**. **422** above 50 (`Fifty rows is the most this endpoint returns at once.`) or if it is not a number. |

**A limit and not a page number.** The list is strictly newest-first and is read
as "what happened lately"; an offset into a table that grows at the top is a
page that shifts under the reader between two requests. `meta.total` is the
whole ledger, so a client can tell whether it is looking at everything.

---

## `GET /api/discoveries`

The routes **nobody is watching** that turned out to be absurdly cheap — the
search screen's "Deals from your airports" strip. Cheapest per kilometre first,
which is the ranking rather than a convenience.

```json
{
  "data": [
    {
      "code": "DUS-RAK",
      "lane": "absolute",
      "origin": { "iata": "DUS", "city": "Düsseldorf", "country": "Germany" },
      "destination": { "iata": "RAK", "city": "Marrakesh", "country": "Morocco" },
      "price": 27,
      "departureDate": "2026-08-21",
      "milliEurosPerKm": 10.8,
      "percentile": 0,
      "savings": 51,
      "foundAt": "2026-08-15T08:14:00+02:00",
      "verdict": {
        "verified": false,
        "label": "Unverified",
        "level": "typical",
        "googleLowest": 168,
        "typicalLow": 100,
        "typicalHigh": 200
      }
    },
    {
      "code": "AMS-DUB",
      "lane": "relative",
      "origin": { "iata": "AMS", "city": "Amsterdam", "country": "Netherlands" },
      "destination": { "iata": "DUB", "city": "Dublin", "country": "Ireland" },
      "price": 60,
      "departureDate": "2026-10-24",
      "milliEurosPerKm": 80.0,
      "percentile": 0,
      "savings": 39,
      "foundAt": "2026-08-15T20:56:00+02:00",
      "verdict": {
        "verified": false,
        "label": "Unverified",
        "level": null,
        "googleLowest": null,
        "typicalLow": null,
        "typicalHigh": null
      }
    }
  ],
  "meta": { "count": 2, "discoveredAt": "2026-08-16T05:20:00+02:00" }
}
```

| field | notes |
| --- | --- |
| `code` | The route code, and **the whole navigation contract** — tapping a discovery opens `/route/DUS-RAK`, which prices the pair through `POST /api/routes/lookup` and offers the watch button. This endpoint publishes no booking link and no watch action of its own. |
| `lane` | `"absolute"` or `"relative"` — **which claim this card is making.** `absolute` is "cheap, period", ranked on €/km against the whole sweep; `relative` is "cheap *for this route*", measured against what that route itself usually costs. The client draws an extra line ("Rare price for this route") on the relative ones and nothing extra on the absolute ones, because a relative find is by construction ordinary per kilometre and the reader would otherwise be right to wonder what it is doing on the strip. §16 has the argument, including why the free distance-band version of this lane does not work. **Treat an unrecognised value as `absolute`** — say less, never more. |
| `origin`, `destination` | The shared airport shape. Both ends, because the card says which of the three home airports it leaves from. |
| `price` | Euros, one-way, as everywhere else in this API. |
| `departureDate` | A bare `YYYY-MM-DD` — the day you would **fly**. |
| `milliEurosPerKm` | What a kilometre costs, ×1000 so it reads as `10.8` rather than `0.0108`. **This is the sort key**, and it is published so a client can explain the order rather than asking the reader to take it on faith. On a `relative` row it will look *bad* — 80.0 for the Dublin example above — and that is the point: it is exactly the number that disqualified the fare from the absolute lane. |
| `percentile` | Where this fare sat among every other departure date on the same route, 0–100, **`null` when the window could not be fetched**. 0 means it was the cheapest date on the route. |
| `savings` | Euros under the **median** of that same window. `null` alongside a null percentile. |
| `foundAt` | When the **provider** found the price — the third date (§3), an ISO timestamp with an offset. `null` renders as nothing at all and never as "fresh". |
| `verdict.verified` | Whether Google was asked **and agreed**. This is the badge. |
| `verdict.label` | The sentence to print. Server-owned, because it is a claim about a third party. |
| `verdict.googleLowest` | The cheapest seat **Google itself** could find. Published whether or not the verdict is confirmed — when it disagrees with `price`, that disagreement is the most useful thing on the card. |

**`verified` is rarer than it looks, and that is the feature.** It requires
Google's *own market* to be cheap — `price_level: "low"`, or Google's
`lowest_price` at or under its typical-range low — and **not** merely that
Orbit's number is below Google's typical range. Measured on 2026-08-16, three
candidates at €29, €27 and €18 were all under their typical-range low while
Google's own cheapest for the same flights was €70, €168 and €30. The obvious
rule would have stamped all three "verified"; this one stamps none. See
`config/orbit.php`'s `serpapi` section.

**A missing verdict is the ordinary case, not an error.** No `SERPAPI_KEY` (the
default), quota under the reserve, a run past its per-run cap, or a route Google
has no `price_insights` for all produce `verified: false` with null evidence.
Nothing is wrong; Orbit simply did not get a second opinion and says so.

**No parameters, and nothing to page.** The table's steady state is about ten
rows (`orbit.discovery.max_rows`), the order *is* the ranking, and a discovery
the reader does not want is one they scroll past.

**`data: []` is a real and common answer** — a box with no sweep provider, or a
week where nothing cleared the thresholds. Every threshold in
`orbit.discovery` is a floor rather than a quota, precisely so that "nothing was
remarkable" can happen; the client draws no section at all rather than an
apology.

| `meta` | notes |
| --- | --- |
| `count` | Rows in `data`. |
| `discoveredAt` | When this set was **found** (the 05:20 run), not when it was requested. `null` on an empty set. It is the only other timestamp in this API besides the route detail's `meta.fares.fetchedAt`. |

---

## How an alert is decided

Four scheduled commands, all **Europe/Amsterdam**, the three daily ones in an
order that is load-bearing:

| when | command | what |
| --- | --- | --- |
| 06:10 daily | `orbit:poll-fares` | fares for every actively watched route |
| 06:40 daily | `orbit:sweep-rules` | fares for the routes each active rule is about |
| **06:55 daily** | `orbit:alerts` | decides what is worth sending, and sends it |
| **Sunday 09:00** | `orbit:digest` | the weekly summary |

`orbit:alerts` talks to no provider — everything it reads was written by the two
runs before it. Running it first would not fail; it would mail this morning's
verdict on yesterday's prices, every day, invisibly.

**A watched route fires** when its deal score reaches the account's sensitivity
(`GET /api/settings` publishes the three levels and their thresholds: Relaxed
≥80, Balanced ≥65, Eager ≥50).

**A rule fires** on any fare it matches — the rule's own maximum price is its
threshold, applied by the matching engine before the alert pipeline sees it.
Every new match from one rule arrives as **one mail**, not one per route.

**The cooldown is 24 hours per route, per kind, per rule.** Inside it, the same
route says nothing — **unless the fare has fallen a further 5%**, which is new
information rather than a repeat. Two different rules matching the same route
are two cooldowns: each rule is a separate question the owner asked.

**Quiet hours defer delivery, not the decision.** A deal found inside the window
is written to the ledger at 06:55 with `deliveredAt: null` and the mail is
queued for the end of the window (08:00 by default) in the owner's timezone. The
cooldown therefore measures from when the deal was found, not from when somebody
woke up.

**The digest ignores all of that.** It repeats routes the cooldown has been
suppressing all week, because it is not an interruption — and it is not sent at
all when there would be nothing in it.

Both runs can be triggered by hand:

```
docker compose exec app php artisan orbit:alerts --now
docker compose exec app php artisan orbit:digest --now
```

**Mail is the only channel today.** `email_alerts` gates the deal alerts and
`weekly_digest` gates the Sunday mail (`PUT /api/settings`); `push_alerts` is
stored and ignored — the PWA shell has landed, but nothing subscribes a device
to web push yet, and the port is shaped so that adapter is an addition rather
than a change. Whether a mail leaves the box is `MAIL_MAILER` — `log` until
`ghiecode.io` is verified as a sending domain in Resend.

---

## The PWA routes

Three more paths exist on this origin, and they are the only ones here that are
**public and session-less** — no cookie, no CSRF, no user. They are registered
outside both middleware groups from `bootstrap/app.php`; `routes/pwa.php`
explains why, and `tests/Feature/PwaShellTest` holds them to it.

They are not part of the screens' contract and nothing in `resources/js` calls
them except the one-line registration in `resources/js/lib/pwa.js`. They are
listed for the same reason `/up` would be: they are server-owned paths, and the
SPA catch-all must never answer them.

| Route | Answers | Cache-Control |
| --- | --- | --- |
| `GET /manifest.webmanifest` | `application/manifest+json` — name, colours, the five icons. Values come from `config('orbit.pwa.*')`, which is also where the shell's `theme-color` meta is read from. | `public, max-age=3600` |
| `GET /sw.js` | `application/javascript` — `resources/js/service-worker.js` with the build's version and precache list substituted in from the Vite manifest (`App\Services\Pwa\BuildAssets`). Carries an ETag, so the browser's per-navigation update check is a 304, and `Service-Worker-Allowed: /`. | `no-cache, must-revalidate` |
| `GET /offline` | `text/html` — the static page the worker shows when a navigation cannot reach the network. No bundle, no fonts, no script. | `public, max-age=86400` |

**The worker never caches `/api/*`.** Every endpoint above it in this file
returns a fare, and a cached fare is a wrong number that looks like a right one.
It caches content-hashed `/build/` output, the earth textures under `/globe/`
and the icons — things whose URL is either their version or effectively
permanent — and nothing else.

---

# The account

One endpoint, and it is the only one in this file that is about the owner rather
than about fares. `tests/Feature/PasswordChangeTest` holds every line of it.

## `PUT /api/profile/password`

Change the password of the account that is signed in. The alerts screen's
Account card (`design/README.md` §6) is the only caller.

```json
{
  "current_password": "the one it has now",
  "password": "the new one, twelve characters or more",
  "password_confirmation": "the new one again"
}
```

**200** on success, and the body says only what changed:

```json
{ "data": { "changed": true } }
```

**snake_case, on the one endpoint that is not camelCase.** Two of the three
names belong to Laravel rather than to this API — `confirmed` looks for
`{field}_confirmation`, and the `current_password` rule reads best on the field
it guards — and they are also the names a browser's password manager keys on.
`App\Http\Requests\UpdatePasswordRequest` says so at more length.

**422** per rule, in the standard shape, keyed by the field so the form can put
each sentence under the box it is about:

| when | message |
| --- | --- |
| `current_password` is missing | Enter your current password. |
| `current_password` is wrong | That is not your current password. |
| `password` is missing | Choose a new password. |
| `password` is under 12 characters | Use at least 12 characters. |
| `password_confirmation` does not match | The new password and its confirmation do not match. |
| `password` is the current one again | That is already your password. Choose a different one. |

**A wrong current password is a 422 and never a 401.** The distinction is load
bearing: `resources/js/lib/http.js` reads a 401 as "the session ended" and routes
to the login screen, which would throw away a form somebody is halfway through
for a mistyped password.

**429 after five attempts a minute**, keyed on the account (`password-change` in
`AppServiceProvider`). It is the only authenticated route in this app that checks
a secret, so a session left open on an unattended phone must not be somewhere to
guess the current password at machine speed.

**The session survives, rotated.** The device that made the change stays signed
in — a password change that signs you out of the screen you made it on is a
security gesture that costs the thing it is for — but it gets a new session id
and a new CSRF token. The token rides back on the response's `XSRF-TOKEN` cookie
and `http.js` reads that cookie per request, so the open SPA carries on with no
reload. **Every remember-me cookie ever issued stops working**, on this device
and on any other: the recaller is checked against `remember_token`, not against
the password, so the column is cycled and this device alone is re-issued one.
A live session on another device is not evicted — that needs
`AuthenticateSession` in the `web` group, which this app does not register.

**Still no reset flow, and this is not one.** There is no `/register`, no
`/forgot-password`, no mail-borne token and no way in from the login screen;
`tests/Feature/AuthenticationTest` asserts each of those is unregistered. This
endpoint cannot be reached without a session AND the current password, so it is
a rotation the owner performs, not a recovery anybody can trigger.
