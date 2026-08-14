# Orbit — read API

The three endpoints the screens are built from. **This file is the contract**:
the globe home, the route detail, the price calendar and the watchlist are all
built against these shapes, and every field below has a feature test behind it
(`tests/Feature/WatchlistApiTest`, `RouteDetailApiTest`, `RouteCalendarApiTest`).

Writes — the watchlist toggle, adding a route, the settings switches — are PR9
and are not here yet.

---

## Conventions

**Auth.** Every endpoint is `auth:sanctum` in cookie/session mode. There are no
tokens: sign in with `POST /login`, and the browser's own httpOnly session
cookie authenticates everything afterwards. `resources/js/lib/http.js` is
already configured for it (`withCredentials`, `withXSRFToken`) — use it and
nothing else. A guest gets **401** with `{"message": "Unauthenticated."}`, never
a redirect.

**Envelope.** Everything is wrapped in `data`. List endpoints add `meta`; the
calendar adds a `meta` of its own. Do not unwrap in the store and re-wrap in the
component — read `response.data.data`.

**Money is euros, as a JSON number.** Cents are an internal unit and never cross
this boundary. A whole number of euros comes back as an integer (`58`), a fare
with cents as a two-decimal number (`57.45`). JavaScript sees a `Number` either
way; round for display as the design does.

**Dates are `YYYY-MM-DD` strings**, in the owner's timezone
(`Europe/Amsterdam`). There are two different date axes in this API and mixing
them is the easiest mistake to make here:

| axis | where | means |
| --- | --- | --- |
| **observation date** | `history[].date`, `trackingDays` | the day *we looked* |
| **departure date** | `calendar days[].date`, `cheapest.date` | the day *you fly* |

**Null means "not known yet", never zero.** A route added this morning has
`price.current: null`, `stats: null` and `score: 0` with `confident: false`.
Render that as the design's "tracking N days" note, not as a €0 fare or a
damning gauge.

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
  "trackingDays": 60
}
```

| field | notes |
| --- | --- |
| `code` | `AMS-OPO`. The URL key, and the only id the client needs. |
| `origin` / `destination` | `lat`/`lng` are the AIRPORT's, for the globe's great-circle arc. `countryCode` is what the design's flag swatches key off. |
| `price.current` | The cheapest fare in the next ~90 days, as of the last poll. Same number as the last point of `sparkline`. **`null`** before the first poll. |
| `price.usual` | The route's median price from the statistics provider. **`null`** when it has none. |
| `price.pctBelow` | Whole percent under `usual`; **negative when above it** ("14% above usual" is `-14`). `null` when either half is missing. |
| `score` | 0–100. See "How the score works" below. |
| `tier` | `insane` (≥80) / `great` (≥65) / `good` (≥50) / `none`. What the alert sensitivities in PR11 fire on. |
| `confident` | `false` means the score is a placeholder — no prices and no statistics yet. **Branch on this**, not on `score === 0`. |
| `verdict.label` | The sentence: spotlight card, route-detail header. |
| `verdict.short` | The single word the watchlist pill has room for: `Good` / `Falling` / `Normal` / `Wait`. |
| `verdict.tone` | `good` \| `info` \| `normal` \| `warn`. **The only thing to switch colours on** — maps onto the token pairs in `resources/css/tokens.css`. Do not derive a colour from `label`. |
| `sparkline` | Up to 14 daily prices, **oldest first**, one per day we polled. Often fewer, and `[]` for a new route. Draw whatever arrives. |
| `trackingDays` | Calendar days since the first observation we actually hold, inclusive. `0` when there are none. This is the number for the "tracking N days" note (`< 14` is the design's threshold). |

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
    "cheapest": { "date": "2026-09-15", "price": 44 },
    "bookingUrl": "https://www.skyscanner.nl/transport/flights/ams/opo/260915/"
  }
}
```

| field | notes |
| --- | --- |
| `history` | Up to 60 daily observations, **oldest first**. This is the line chart; `sparkline` is its last fortnight. Days we did not poll are simply absent — plot by date, not by index. |
| `stats` | The dashed "usual price" reference line, and the five-number summary the score is built from. **`null`** when the provider has none; draw the chart without a reference rather than with one at zero. |
| `advice` | The tinted callout. `title` always equals `verdict.label` and `tone` always equals `verdict.tone` — they are generated together so the prose and the gauge cannot disagree. |
| `cheapest` | The cheapest **departure date** still on offer, ties broken to the earliest. `null` before the first poll. |
| `bookingUrl` | Skyscanner deep link, aimed at `cheapest.date`. Falls back to the route without a date (`…/ams/opo/`) when there are no fares. Always present. |

`code` is constrained to `[A-Z]{3}-[A-Z]{3}` at the router: **upper case, with
the hyphen**. `ams-lis` does not match and is a 404, not a redirect.

Not scoped to the watchlist — any known route has a detail screen.

**404**: `{"message": "Unknown route."}`

---

## `GET /api/routes/{code}/calendar?month=YYYY-MM`

One month of the heatmap (`design/README.md` §3). `month` is optional and
defaults to the current one.

```json
{
  "data": {
    "days": [
      { "date": "2026-09-01", "price": 76, "verdict": "pricey" },
      { "date": "2026-09-02", "price": 75, "verdict": "pricey" },
      { "date": "2026-09-15", "price": 44, "verdict": "cheap" }
    ],
    "min": 44,
    "max": 88,
    "cheapest": { "date": "2026-09-15", "price": 44 }
  },
  "meta": { "code": "AMS-OPO", "month": "2026-09" }
}
```

| field | notes |
| --- | --- |
| `days` | Ordered by date. **Days with no fare are absent**, not null-priced — lay the grid out from `date`, never from the array index. |
| `days[].verdict` | `cheap` \| `mid` \| `pricey`, already computed against this month's own range using the design's thresholds (cheap ≤ low + 28% of the range, pricey ≥ 66%). Use it for the bottom sheet's pill; do not recompute. |
| `min` / `max` | This month's bounds — the legend gradient's two labels, and the range to interpolate the five-stop heat scale across. `null` for an empty month. |
| `cheapest` | The "★ Cheapest this month" banner. `null` for an empty month. |

**Empty months are a 200, not a 404.** The poll window is about three months, so
paging past it is normal: `days: []`, `min`/`max`/`cheapest` all `null`. Draw an
empty grid.

**422** when `month` is not `YYYY-MM` with a month of 01–12, with Laravel's
standard `{"message": …, "errors": {"month": […]}}`.

**404**: `{"message": "Unknown route."}`

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

`verdict` follows from the score and the trend:

| condition | label | short | tone |
| --- | --- | --- | --- |
| score ≥ 65, falling | Cheap & still falling | Falling | `info` |
| score ≥ 65, steady | Good price — book | Good | `good` |
| score ≥ 50, falling | Falling — worth watching | Falling | `info` |
| score ≥ 50, steady | Around normal | Normal | `normal` |
| score < 50, above usual | Above usual — wait | Wait | `warn` |
| score < 50, otherwise | Around normal | Normal | `normal` |
| no data | Not enough data yet | Normal | `normal` |

---

## Where the numbers come from

Until the Travelpayouts and Amadeus keys exist (`docs/PLAN.md`), both providers
are **deterministic fakes** — `ORBIT_PRICE_PROVIDER=fake`. That is a production
adapter, not a test double: the same route shows the same prices on every
deploy, so a screen can be developed against a stable €44 and a test can assert
one. Swapping in the real providers changes two `.env` variables and no response
shape.

Fares are refreshed by `orbit:poll-fares` at 06:10 Europe/Amsterdam and the
statistics by `orbit:refresh-stats` on Monday at 05:40 (`routes/console.php`).
Both can be run by hand:

```
docker compose exec app php artisan orbit:poll-fares --now
docker compose exec app php artisan orbit:refresh-stats --now
```
