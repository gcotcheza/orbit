# Orbit — the domain rulebook

Every rule Orbit applies, with the number it applies, the config key that number
lives in, and where the code is. This is the authoritative description: where
this file and `docs/PLAN.md` disagree, PLAN is history and this is the app.
Where this file and the code disagree, the code is right and this file is a bug.

Response shapes are **not** here — that is [`API.md`](API.md), and it is
cross-linked rather than restated. Money is stored and reasoned about in **euro
cents** everywhere inside the app; only the API boundary converts to euros.

---

## 1. Watchlist and routes are different things

A **route** is a fact about the world: an ordered pair of airports, `AMS-LIS`.
A **watchlist item** is this account's relationship to one — active or paused,
in the owner's own order.

| | value | code |
| --- | --- | --- |
| route key | `ORIGIN-DEST`, upper-case IATA | `App\Models\Route::codeFor()` |
| watchlist row | `user_id`, `route_id`, `active`, `position` | `App\Models\WatchlistItem` |
| add | find-**or-create** the route, then create the row at the end of the order | `WatchlistItemController::store()` |
| pause | `active=false`; row, history and position all stay | `…::update()` |
| unwatch | deletes **only** the watchlist row | `…::destroy()` |

**Rows survive unwatching, and that is the point.** Every observation under a
route was a real morning's fare that cost a provider call to gather. Dropping
`AMS-LIS` in September and adding it back next spring hands the owner back the
history they already paid for, rather than starting from nothing — so
`store()` is a `firstOrCreate` on the route and a plain `create` on the row.
Routes also arrive without anybody watching them, by **two** paths:
`SweepRuleFares` creates the pairs a rule is about, and `POST /api/routes/lookup`
creates the pair somebody typed into the box. Nothing shows either until it is
asked for, and the route-detail screen is deliberately *not* scoped to the
watchlist.

**Adding is asynchronous. Looking up is not.** `POST /api/watchlist` queues
`PollRoutePrices` and `RefreshRouteStats` and answers before either has run, so
a brand-new row is `confident: false` with no prices — see §8.
`POST /api/routes/lookup` cannot do that: nobody is coming back tomorrow to see
what a route they were curious about costs, so it runs the same two jobs
**synchronously** and answers with the prices. That is the whole difference
between the two writes, and it is why the lookup is the one endpoint in the app
where a single tap can spend six or seven metered provider calls — hence
`orbit.lookup.fresh_for_hours` (a pair is not re-fetched inside it, even if the
answer was "no fares") and the `route-lookup` throttle.

**A lookup is not a commitment, in either direction.** It writes no watchlist
row, and it is refused for nothing that `POST /api/watchlist` would refuse
except "you are already watching this" — looking at a route you watch is an
ordinary thing to do. A pair the rule sweep priced this morning is answered out
of the fares the sweep already paid for.

**A route can be priced without being watched, and that is the lookup path.**
`POST /api/routes/lookup` finds-or-creates the route and, when Orbit holds no
fares fetched inside `orbit.lookup.fresh_for_hours` (24), asks the provider
**inside the request** — `App\Application\Routes\FareFreshness`, six or seven
metered calls, two or three seconds while somebody waits. It is the one place in
the app where a person's tap spends provider calls directly, which is why it is
a POST, why it is throttled (`route-lookup`), and why the "we already asked and
got nothing" answer is remembered in the cache for the same window: an empty
answer writes no rows, so the calendar alone would read as stale forever and
re-fetch on every view. **It touches no watchlist row** — a route is a fact
about the world, a watchlist item is this account's relationship to one, so
bringing the first into existence to look at it commits to nothing. Nothing
lists these routes and the morning poll never visits them, which is exactly why
the route detail refreshes a *stale and unwatched* route and never a watched
one: a watched route with old fares is a broken poll, not a call to make from
somebody's phone.

**Both ends are open; the rule engine's origins are not.** Since the search
screen (2026-08-16) `RoutePairRequest` validates the origin exactly as it
validates the destination — `exists:airports,iata`, any row in the table — so
`POST /api/routes/lookup` and `POST /api/watchlist` take `BCN-PMI` from
somebody who is already in Barcelona. Asking what a pair costs is a question,
and the old `Rule::in(config('orbit.origins'))` was the only thing making it
unaskable.

`config('orbit.origins')` is unchanged and still `['AMS', 'EIN', 'DUS']`. What
it means now is narrower and load-bearing: **the origins a deal *rule* may fire
from**, read directly by `RuleMatches`, `SweepRuleFares` and `RuleVocabulary`,
and therefore the width of the nightly sweep (§11, "The cap is the point"). The
distinction is between a pair somebody typed once and a standing question Orbit
answers on its own every night — only the second has a budget, and none of
those three ever went through a FormRequest, so widening the request widened no
sweep. What a form *offers* is a third decision again: the search screen writes
the three home airports out as quick chips and its boxes take any of the 3,270.

### The two tiers of "somewhere Orbit knows"

Since **world flights** those two lists are very different sizes, and which one
a feature reads is a decision rather than an accident.

| | rows | table | seeded by | what it is for |
| --- | --- | --- | --- | --- |
| **Tier 1 — the world** | 3,270 | `airports` | `WorldAirportSeeder`, from `database/seeders/data/world_airports.csv` | **look-up and watch, at both ends.** `exists:airports,iata`, `GET /api/airports?q=` |
| **Tier 2 — the curated set** | 184 | `destinations` (+ their `airports` rows) | `DestinationSeeder`, from `european_destinations.php` and `world_destinations.php` | **rules.** vibes, month-by-month warmth, `GET /api/destinations` |

**Tier 1 is a third-party snapshot and carries no opinion.** It is every
airport OurAirports lists as having scheduled service and an IATA code
(public domain; the filter and the retrieval date are in
`world_airports.README.md`). It has a name, a city, a country and a coordinate,
and nothing else — nobody has decided what Ouagadougou is *for*.

**Tier 2 is editorial, and the rule engine reads only it.** "Cheap weekend
somewhere sunny in February" is answered by filtering `vibes` and reading
`warmth` (§11), and both of those are judgements a person made. A rule that
could fire on all 3,270 would be a rule fired on rows nobody has ever looked
at — so `RuleMatches` and `SweepRuleFares` walk `destinations` and never
`airports`. Growing tier 1 does not grow the rule sweep's budget by a single
poll.

**The curated row wins where the two overlap.** 187 of the 3,270 codes (184
destinations and the three origins) are in both, and `WorldAirportSeeder` skips
every one of them: the snapshot calls `JFK` "John F. Kennedy International
Airport" and files Sydney's city as "Sydney (Mascot)", and one of the
disagreements is a correction — Dakar is `DSS`, not the `DKR` the snapshot still
marks as served. `tests/Feature/SeedersTest` asserts the two sets stay disjoint.

**What the owner sees.** Each of the search screen's two boxes paints the
curated matches instantly from memory and adds the world matches under a
divider 250 ms later (`resources/js/stores/airports.js`). Both halves are
watchable and both land on the same route-detail screen; only the curated half
can ever be *suggested by a rule*.

---

## 2. What a fare is

| | value | where |
| --- | --- | --- |
| direction | **one-way**, always | `TravelpayoutsPriceProvider` |
| currency | EUR, cents internally | `config/orbit.php` → `travelpayouts` |
| granularity | one price per **departure date** | `App\Application\Ports\PriceProvider` |
| source | Travelpayouts `/v2/prices/month-matrix`, one call per calendar month | `TravelpayoutsPriceProvider::PATH` |

Every price in this app is a one-way fare for a single departure date. The deal
score, the alert threshold and the €80 in a rule are all one-way numbers, and
always have been — the fake provider's were too. A round-trip fare pinned to a
departure date is really a fare for a *pair* of dates with the second one
hidden, which is not what a calendar cell means.

**Round trips are a second table and a second port, not a change to this one.**
A long-haul one-way reads 58–69% of the return fare on the same route, so the
two numbers can never be derived from each other and must never share a column.
Everything about them — the endpoint, the coverage, the model and why it is not
polled on a schedule yet — is §15.

**The calendar-endpoint trap.** Travelpayouts' sibling `/v1/prices/calendar`
looks like the right endpoint and is not: measured against the live API on
2026-08-15 it answers **round-trip** prices even with `return_date` omitted
(AMS-LIS €252–391 against month-matrix's €80–159 for the same days) and it
ignores the month it is asked for. It would not fail — it would silently make
every route look expensive and every alert threshold wrong. A third endpoint,
`/v2/prices/latest`, is the last 48 hours of finds across a period rather than
a price per departure date; it validated the token, it does not fill a
calendar.

**Patchy coverage is normal, not a fault.** Travelpayouts serves a cache of
fares other people's searches already found: 41–87% of the window across the six
seeded routes when this was measured, and thinner the further out it looks. A date with no fare is **absent**,
never zero-priced, and every screen has always handled a gap.

**The currency is checked, not assumed.** The response envelope's `currency`
field is verified before any arithmetic, because the failure it guards against —
the API answering in roubles, its documented default — is silent, and "€92"
that is really ₽92 is a fare Orbit would shout about. `value` is whole units, so
cents are a multiplication.

**One month may fail and the poll still counts.** The near window is seven
month-matrix calls and the weekly far run is twelve; the adapter tolerates one
failing, because six months of calendar is worth more than none. That tolerance
is exactly why stale cells are pruned by age rather than by absence from a
response — see §4.

### A fare has an age, and the app says what it is

| | value | where |
| --- | --- | --- |
| when Orbit asked | `calendar_fares.fetched_at` | stamped by `PollRoutePrices` |
| when the price was found | `calendar_fares.found_at`, nullable | the provider's own `found_at`, carried on `DatedFare::$foundAt` |
| shown as | "Seen 3 hours ago" / "Seen 4 days ago" | `resources/js/lib/format.js` → `seenLabel()` |

**These are two different facts and the app used to publish only the first.**
Travelpayouts does not quote fares, it serves a cache of results other people's
searches produced, so the morning poll can stamp `fetched_at` at 06:10 today and
be handed a price last seen on Tuesday. Every screen read `fetched_at` and
implied the number was live. The owner caught it twice: **€36 shown where
Skyscanner's live cheapest was €56**, and **€29 against a real €68**. Nothing had
miscalculated — both figures were true when they were found, days earlier — and
nothing on the screen said so.

So `found_at` now travels from the adapter to the screen, and every price says
how old it is: the **day sheet** prints it under every fare, the **route
detail** prints it beside the cheapest-departure line only once the fare is over
24 hours old (under that it is the ordinary state of a route polled this
morning, and a line nobody needs teaches people to skip it).

**Null is "not known" and renders as nothing.** Rows written before the column
existed have no find time and it is not recoverable; `fetched_at` is emphatically
**not** a substitute, because using it would state precisely the false thing this
column was added to stop stating. Under an hour reads as "just now" rather than a
count of minutes — nothing here moves on that scale, and false precision is its
own kind of lie. The fake provider stamps every fare with the current clock, so a
sandbox exercises the visible path rather than the silent one.

**Neither booking link is a promise.** Both hand-offs sit under one line —
*"Prices come from recent searches — the booking site shows live availability"* —
which replaced a disclaimer about Orbit not selling tickets. That answered a
question nobody was asking; this answers the one a reader standing in front of a
possibly-stale fare actually has. See §12.

---

## 3. Two date axes, and mixing them is the easiest mistake here

| axis | fields | means |
| --- | --- | --- |
| **observation date** | `route_price_history.observed_on`, `trackingDays`, `history[].date`, the sparkline | the day *we looked* |
| **departure date** | `calendar_fares.departure_date`, `days[].date`, `cheapest.date` | the day *you fly* |

Both are `YYYY-MM-DD` in `config('orbit.timezone')` (`Europe/Amsterdam`).
Storage is UTC everywhere else in the app; these two are resolved in the owner's
zone because "today's fare" and "which day this cell stands for" are statements
about a person's calendar. A poll retried at 00:30 local is still *yesterday* in
UTC, and would overwrite yesterday's observation — which is why
`PollRoutePrices` resolves the date through the configured timezone and not
through `now()`.

---

## 4. Polling

| what | value | config key | code |
| --- | --- | --- | --- |
| near window | 181 days ahead (≈182 dates, today inclusive) | `orbit.poll.window_days` | `App\Jobs\PollRoutePrices` |
| maintained horizon | 334 days ahead (≈335 dates) | `orbit.poll.horizon_days` | ditto |
| far refresh | weekly, Saturday 04:10 | `orbit.poll.far_refresh_weekday` | `routes/console.php` |
| stagger | 3 minutes between per-route jobs | `orbit.poll.stagger_minutes` | `App\Console\Commands\PollFares` |
| stale-cell prune | 3 days without a refetch (near), 17 (far) | `orbit.poll.stale_after_days`, `…far_stale_after_days` | `PollRoutePrices` |
| scope | routes with an **active** watchlist row | — | `Route::onWatchlist()` |

**Two horizons, two speeds, and the distinction is load-bearing.** The *near
window* is what a poll fetches every morning and **the definition of "the
current price"** — the cheapest fare in the next six months, which is what the
observation, the sparkline, every deal score and every alert threshold are built
on. The *maintained horizon* is how far the calendar screen can page: eleven
months, the airline booking edge, refreshed by one extra run a week. Widening
the near window makes every route look cheaper; widening the horizon moves no
number in the app at all. That is why they are two keys.

**Why those exact numbers.** Travelpayouts bills one request per calendar
*month* a window touches, so cost steps up at a month boundary rather than at a
day. Brute-forced over every start date in a four-year span: 181 days never
touches more than 7 months (183 reaches an 8th) and 334 never more than 12 (335
reaches a 13th). Each is the widest window that never pays for a month it does
not need.

**The request budget**, which is what put the far run in its own clock hour —
Travelpayouts allows ~200 requests an hour per IP, nine routes are watched:

| when | what | requests |
| --- | --- | --- |
| 06:10 daily | poll, 9 × ≤7 months | 63 |
| 06:40 daily | rule sweep, 30 × ≤4 months | 120 |
| | **the ordinary morning's clock hour** | **183** |
| 04:10 Saturday | far poll, 9 × ≤12 months | 108, alone in that hour |

So the eleven months cost **nothing in the worst hour**. What breaches first is
the ordinary morning, at **twelve watched routes** (7 × 12 + 120 = 204); the far
run has room to sixteen. `tests/Unit/Infrastructure/TravelpayoutsPriceProviderTest`
asserts both halves.

`orbit:poll-fares` is a fan-out: it queues one `PollRoutePrices` per actively
watched route, delayed by `index × stagger`, so nine routes trickle over
twenty-four minutes rather than arriving as a burst against a per-minute rate
limit. Nothing that talks to a rate-limited third party runs inside the scheduler
process. `orbit:poll-fares --far` is the same fan-out asking for the whole
horizon; the depth is always in the job's payload and never decided from the day
of the week inside the job, so a retry fetches what it was queued for and a
synchronous lookup can never be surprised by twelve provider calls.

**One provider call, two writes.** Each job upserts everything it asked for into
`calendar_fares` *and* one row into `route_price_history` — that morning's
cheapest fare anywhere in the **near** window, whatever depth the run went to.
Splitting them would double the provider calls for the same data.

**The observation is always a near-window minimum**, and that bound is what
keeps the weekly far run out of the history. Taken over whatever a run fetched,
Saturday's row would be the cheapest fare in the next *eleven* months — lower on
most routes for no reason but the depth of the fetch — and the series would saw
up and down every week, with the trend component of the score reading it as a
fall and a recovery.

**Idempotent per day.** Both writes are upserts keyed on a date, so a retry, a
manual run or a re-seeded deploy overwrites the day's figures instead of adding
a second point and bending the trend.

**Four deletions, and they are not the same deletion:**

1. **Departures that have gone by** are removed on every successful poll —
   otherwise the table grows a permanent tail of flights nobody can take, and
   the "cheapest this month" banner would happily point at one.
2. **Departures past the maintained horizon** are removed on every successful
   poll too — bounded by `poll.horizon_days`, **never** by the near window.
   Rows can only get out there by the horizon shrinking, and this clause is
   also the one the eleven-month calendar turns on: bounded by the near window
   instead, every far cell would be deleted by the next ordinary morning, six
   days out of seven.
3. **Future dates that have stopped being quoted** are removed once they are
   `stale_after_days` old — in **two passes**, because the two tranches are
   polled at two speeds. Three days is "two missed mornings plus a day"; a far
   cell is seven days old before anything asks about it again, so months 7–11
   get `far_stale_after_days` (17 = two missed weekly refreshes plus the same
   cushion). An upsert only ever writes the dates the provider named this
   morning, so a date that had a fare last week and none now would keep that
   fare forever, with nothing in the API marking it. It would colour a heatmap
   cell, be eligible as the "cheapest departure" a booking link points at, and
   be matched against by a deal rule — which is this app mailing somebody about
   a flight that cannot be booked, the one thing it must never do.
4. **Nothing at all** when the provider answers with an empty list. The job
   returns before every deletion, so a provider that is down erases nothing.

**Three days, and by staleness rather than by absence.** The poll is daily and
the deletion is one-way, so two consecutive failed mornings — or a date simply
missing from the cache for a day — must not cost the calendar a cell it would
have got back. And because the adapter deliberately tolerates one of its seven
(or twelve) monthly calls failing, the job cannot tell "that month is empty
today" from "that month's request 500'd"; deleting every unnamed date would blank
a seventh of the calendar every time Travelpayouts hiccuped — and, on the far
tranche under the daily rule, a whole month every Saturday.

**Deleted, not filtered.** Four places read this table and each would otherwise
have to remember the same clause forever.

---

## 5. Price history

| what | value | config key | code |
| --- | --- | --- | --- |
| table | `route_price_history`, one row per route per day | — | `App\Models\PriceObservation` |
| the number | the cheapest fare anywhere in that morning's window | — | `PollRoutePrices` |
| stamped | `observed_on`, a bare date in the owner's timezone | `orbit.timezone` | ditto |
| uniqueness | `(route_id, observed_on)` | — | migration `…_create_route_price_history_table` |
| sparkline | last 14 points, oldest first | `orbit.history.sparkline_days` | `RouteSnapshots` |
| detail chart | last 60 points | `orbit.history.chart_days` | ditto |
| fake backfill | 60 days | `orbit.history.backfill_days` | `FakeHistorySeeder` |

This is the one table nobody can sell us. A statistics provider says what a
route usually costs; only accruing observations say which way it is moving right
now, which is the whole difference between "book it" and "wait another week".

`PriceHistory::lastDays()` slices by **calendar** days back from the newest
point, not by number of points — counting points would quietly reach further
back than asked the first time the poller misses a run, mixing a month-old price
into a "last week" trend.

`trackingDays` is calendar days since the **first observation Orbit actually
holds**, inclusive — not since the route was added. A route polled once today is
"tracking 1 day". Both ends are parsed in the owner's timezone, or the
difference comes back with a fraction on it.

---

## 6. Statistics — what a route "usually" costs

`ORBIT_STATS_PROVIDER=self` · `App\Infrastructure\Pricing\SelfStatsProvider` ·
refreshed by `orbit:refresh-stats` into `route_price_stats`.

**There is no third-party statistics adapter and there will not be one.**
Amadeus' price-analysis endpoint was the plan; their Self-Service API was
decommissioned on 2026-07-17 and nothing else sells the quartiles of a route's
fares. Orbit computes its own from the two tables the poller already fills.

Two horizons, both of them real fares this app fetched:

| horizon | source | available | its median means |
| --- | --- | --- | --- |
| **cross-sectional** | the ~182 `calendar_fares` of the **near** window | from the **first** poll | what a typical departure date on this route costs right now |
| **longitudinal** | `route_price_history`, one row per morning | takes weeks | what this route's cheapest fare has actually been, morning after morning |

The longitudinal view is the better comparison once it exists, because the fare
being scored **is** one of those rows (`RouteSnapshots` reads the latest
observation as "the current price") — a percentile against past mornings
compares today's best against every other day's best, like for like, where the
cross-sectional view compares a best against a typical and therefore reads a
little cheap.

**The blend is one line of arithmetic, applied per knot:**

```
w    = min(1, observations / maturity_observations)
knot = round((1 - w) · cross_sectional + w · longitudinal)
```

for each of `min`, `p25`, `median`, `p75`, `max`.

| what | value | config key |
| --- | --- | --- |
| maturity | 30 observations | `orbit.selfstats.maturity_observations` |
| longitudinal reach | 365 days back | `orbit.selfstats.history_days` |
| cross-sectional reach | 181 days forward | `orbit.selfstats.cross_section_days` |

**The cross-section stops at the near window, not at the calendar's edge.**
`calendar_fares` runs eleven months deep (§4) and "usual" must not, for two
reasons that are one reason. What survives out at nine and ten months is not a
random sample: the provider's cache thins with distance, so what is left is
disproportionately Christmas, Easter and the school holidays — pooled in, those
peaks lift the upper knots and every route quietly scores as a better deal than
it is, against the input the score weights at 60%. And the fare *being* scored is
the minimum of the **near** window, so the distribution it is compared against
has to be drawn from the same days: like against like, which is the argument the
longitudinal half rests on too. The near window's 181 is written out a second
time under `selfstats` rather than referenced, because the two are different
decisions — a budget for what to fetch daily, and a claim about which departures
are comparable — and `tests/Feature/SelfStatsProviderTest` asserts they agree.

A convex combination of two non-decreasing five-number summaries is
non-decreasing, and `round()` is monotone — so the result can never violate
`PriceStats`' ordering invariant, the failure that would otherwise score
expensive fares well, silently, forever. Every intermediate value is a euro
figure somebody can check by hand, which matters for the one input the deal
score weights at 60%.

Linear and capped, not a curve and not a step: a curve would be a claim about
how quickly a month of mornings becomes representative, and a step at the
threshold would move a route's usual price — and every score hanging off it — by
whatever the two views happened to disagree by that morning.

**What "usual" honestly means, by phase.** Days 1–29 it is *the going rate
across the next six months*; from day 30 it is *what the cheapest fare on this
route has actually been, morning after morning*. At day 15 it is honestly half
of each.

**Null is a real answer.** A route with no calendar fares and no history gets
`null`, not an invented distribution. `RefreshRouteStats` then leaves any
existing row alone (an outage is not evidence that statistics stopped existing;
a month-old usual price scores far better than none) and `DealScorer`
renormalises over what remains. A route the provider has stopped covering — no
window, some history — is answered by the longitudinal half alone rather than
blended down toward a window that does not exist.

**`PriceStats` itself** uses nearest-rank percentiles over whole-cent fares that
were really quoted, and interpolates piecewise-linearly between the five knots
when asked where a price sits. A fully degenerate summary answers 0.5 for its
own price, because "exactly usual" is the only defensible reading.

---

## 7. The deal score

`App\Domain\Pricing\DealScorer` — pure PHP, zero framework imports, everything
handed in as arguments. Its numbers arrive as an
`App\Domain\Pricing\ScoringPolicy` built once by `AppServiceProvider`.

| component | weight | config key | what it knows |
| --- | --- | --- | --- |
| percentile | 60 | `orbit.score.weights.percentile` | where the fare sits in this route's own distribution |
| trend | 25 | `orbit.score.weights.trend` | which way our own last 30 days are moving |
| absolute | 15 | `orbit.score.weights.absolute` | how close to the route's own floor it is |

Percentile is the bulk of the answer because it is the only component that knows
what *other* prices on this route look like: €71 is a bargain to Reykjavík and a
rip-off to Düsseldorf. Trend is deliberately the smaller weight — a falling
price is a reason to hesitate, not a reason to call something a deal. Absolute
exists because the percentile component saturates near the bottom, and something
still has to separate "as cheap as this route has ever been" from "merely
cheap".

**Renormalisation, not penalty.** A component that cannot be computed is dropped
and the remaining weights are divided by their own sum — a route with no history
is scored on the two that do not need it rather than docked 25 points for being
new. If *nothing* is computable the answer is `noOpinion()`.

**Trend.**

| what | value | config key |
| --- | --- | --- |
| window | 30 days | `orbit.score.trend_days` |
| saturation | 0.005 (0.5%/day) | `orbit.score.trend_saturation_per_day` |

`PriceHistory::dailyDrift()` is the **least-squares** slope in cents per day
divided by the mean price — a fraction of the fare per day, negative when
falling. Least squares rather than first-versus-last, which is wrong in the one
case that matters: a fare that has slid for a month and ticked up €2 yesterday
would read as "rising". Normalised by the mean so "half a percent a day" means
the same on a €40 route as on a €400 one. The component maps 50 to flat and
saturates linearly at ±0.5%/day (0 rising, 100 falling); a curve would be a
claim about how fares behave that nothing here can support. `null` — and so no
component — when there are fewer than two points or they all landed on one date.

**Absolute.** `(median − current) / (median − min)`, clamped to 0–100. The half
of the distribution above the median is all zero on purpose: this component
grades bargains, and the percentile component is already grading everything
else. It answers **null** — not 0, not 100 — when min and median coincide,
because a route that has never been cheaper than usual gives "how close to its
floor is this" nothing to measure.

**Tiers.**

| tier | score | config key |
| --- | --- | --- |
| `insane` | ≥ 80 | `orbit.score.tiers.insane` |
| `great` | ≥ 65 | `orbit.score.tiers.great` |
| `good` | ≥ 50 | `orbit.score.tiers.good` |
| `none` | below 50 | — |

The tiers live under `score` and not under `alerts` because the tier is part of
the score's meaning and the API publishes it. The alert sensitivities *name* a
tier rather than a number, so retuning a tier retunes the sensitivity with it.

### The day-1 floor

**A route with fewer than `orbit.alerts.min_tracking_days` (7) mornings of its
own prices is not scored at all.** `DealScorer::score()` asks this first and
returns `noOpinion()` — `score: 0`, `tier: none`, `confident: false`, verdict
"Not enough data yet" — before computing anything.

| what | value | config key | code |
| --- | --- | --- | --- |
| floor | 7 daily observations, **inclusive** (7 passes, 6 does not) | `orbit.alerts.min_tracking_days` | `ScoringPolicy::$minTrackingDays` |

This is not caution for its own sake. With `ORBIT_STATS_PROVIDER=self` the
statistics are computed from the fares Orbit has already fetched, so on a
route's first morning the current fare **is** its minimum, its median and its
maximum: the percentile component says 100, the absolute component says 100,
and every route on a freshly filled watchlist comes back
`score: 100, tier: insane, confident: true, "Good price — book"` on the strength
of one number each. That is not a sale, it is a summary of a single number — and
it arrives on the one morning the owner is most likely to decide this app cries
wolf.

**Seven days** is a week of mornings: enough for a spread to exist and for the
trend to have a direction, short enough that a route added on a Monday can be
alerted about before the next one. It is a smaller, harder claim than
`selfstats.maturity_observations` (30), which is about when an *estimate* is
good; this one is about when Orbit may have an opinion at all.

**One number, read twice.** `AppServiceProvider` hands the same
`orbit.alerts.min_tracking_days` to `ScoringPolicy` and to `AlertPolicy`. It
lives under `alerts` because that is where the consequence people feel is — an
unwanted mail at 06:55 — and it is shared because a screen showing "Good price —
book" about a route the alert engine considers too young to mention would be
Orbit disagreeing with itself in public.

`noOpinion()` is a **distinct method**, not `score(0, null, empty)`: a price of
zero is the cheapest fare imaginable and would score 100. "We do not know" and
"it is free" must not share a code path.

---

## 8. What `confident` means

`confident: false` means **Orbit is not expressing an opinion**. It always
travels with `score: 0`, `tier: "none"` and the verdict "Not enough data yet".
Three states produce it:

| state | why |
| --- | --- |
| no price at all | `RouteSnapshots` calls `noOpinion()` when there is no observation |
| fewer than 7 mornings | the day-1 floor, §7 |
| no computable component | no statistics *and* no usable trend |

Clients **branch on `confident`, never on `score === 0`** — 0 is also a legal
score for a genuinely terrible fare. `price.current`, `sparkline` and
`trackingDays` stay real throughout: they are observations, not opinions. The
design's answer to this state is the "tracking N days" note (`< 14` days is the
design's threshold for showing it), not a gauge that reads as a damning verdict.

`confident: true` requires a real computed score — see `DealScore`.

---

## 9. Verdicts

`Verdict` carries three things and the client derives none of them: `label`
(the sentence on the spotlight card and route detail), `short` (the single word
the watchlist pill has room for), and `tone` (**the only thing to switch colours
on**, mapping onto the token pairs in `resources/css/tokens.css`).

"Falling" means `drift ≤ −0.2 × trend_saturation_per_day`, i.e. −0.1%/day at
the current setting — tied to the saturation so that turning the trend
sensitivity up in config does not leave the *word* meaning something different
from the number beside it.

| condition | label | short | tone |
| --- | --- | --- | --- |
| score ≥ 65, falling | Cheap & still falling | Falling | `info` |
| score ≥ 65, steady | Good price — book | Good | `good` |
| score ≥ 50, falling | Falling — worth watching | Falling | `info` |
| score ≥ 50, steady | Around normal | Normal | `normal` |
| score < 50, above usual | Above usual — wait | Wait | `warn` |
| score < 50, otherwise | Around normal | Normal | `normal` |
| no data, or under the day-1 floor | Not enough data yet | Normal | `normal` |

`Advice` — the route detail's tinted callout — is generated in the same class
from the same numbers, so the prose and the gauge cannot disagree. A card
reading "a clear bargain" next to a 31 is the kind of thing that costs a user
their trust in the whole app. With no statistics it says so explicitly, because
a trend-only read must not imply the fare is cheap.

**The gauge uses a different colour scale from the tier**, on purpose. The tier
is the alerting threshold (80/65/50); the detail ring is `design/README.md` §2's
`≥80 --good`, `≥60 --info`, `≥45 --warn`, else `--bad`, computed **on the
client**. The API deliberately sends no colour.

---

## 10. Alerts

`App\Domain\Alerts\AlertPolicy` decides; `App\Application\Alerts\AlertEvaluation`
wires it up; `App\Infrastructure\Notify\MailDealNotifier` delivers. The policy is
pure PHP: candidate, threshold, what was last said, and what time it is all
arrive as arguments, so a decision about whether to send mail is checkable on
paper at an hour chosen by the test rather than by the clock.

### Sensitivity → tier

| level | name | tier | fires at | config key |
| --- | --- | --- | --- | --- |
| 0 | Relaxed (**default**) | `insane` | ≥ 80 | `orbit.alerts.sensitivities.0` |
| 1 | Balanced | `great` | ≥ 65 | `…sensitivities.1` |
| 2 | Eager | `good` | ≥ 50 | `…sensitivities.2` |

Each level names a **tier**, never a number: the number lives once, in
`orbit.score.tiers`, and is the same one the API publishes as a route's `tier`,
so "Relaxed" and the "insane" badge can never come to mean different scores.
Stored as `user_settings.sensitivity` (0–2) and read through
`UserSettings::minimumScore()`. The blurb under the segmented control is config
too, with the threshold as its one `%d` — copy that quotes a number this file
owns has no business being hard-coded in a template.

### The four rules, in order

The order is load-bearing:

| # | rule | decision when it holds |
| --- | --- | --- |
| 0 | **maturity** — a watched route needs ≥ 7 daily observations | `immature-data` |
| 1 | **threshold** — the score reaches the account's sensitivity | `below-threshold` |
| 2 | **freshness** — the fare is stale *and* the flight leaves soon | `stale-fare` |
| 3 | **cooldown** — 24h per route per kind per rule | `cooling-down` |
| 4 | **further drop** — ≥ 5% cheaper than the last alerted price | `superseded-by-drop` |

`AlertDecision` is an enum and the **case is the reason**. "Nothing was sent
this morning" is the hardest state this app has to explain to itself — the score
may have been a point short, the route may be too young, the fare may be too old
to stand behind, the same route may have been announced yesterday, or there may
have been nothing at all — and a bare `false` would collapse five very different
mornings into one. `fires()` is true for `fired` and `superseded-by-drop`.

Maturity is answered **before** the threshold so the reason is right: a route
held there is a route Orbit has not learned anything about, and "below
threshold" would read as "we looked and it was ordinary".

### The freshness guard

| what | value | config key |
| --- | --- | --- |
| fare too old at | **> 2 days** since `found_at` | `orbit.alerts.max_fare_age_days` |
| departure counts as near at | **≤ 3 weeks** away | `orbit.alerts.near_departure_weeks` |
| held as | `stale-fare` | `App\Domain\Alerts\AlertPolicy::isStale()` |

**Both halves are required, and the `AND` is the whole rule.** Age alone is not
a reason to stay quiet and neither is an imminent departure.

| fare found | departure | decision |
| --- | --- | --- |
| > 2 days ago | ≤ 3 weeks away | **held** — `stale-fare` |
| ≤ 2 days ago | ≤ 3 weeks away | fires |
| > 2 days ago | > 3 weeks away | fires |
| ≤ 2 days ago | > 3 weeks away | fires |
| **`null` (unknown)** | any | **fires** — treated as fresh |

*Why age.* Prices come from a cache (§2), so a fare can be days old. On a screen
that is a stale number with a line under it saying so; in a **mail** there is no
such line and no such choice — it is Orbit waking somebody at seven about a
flight that is not for sale, and they find out at the payment step.

*Why near-departure.* Fares close to departure move fast and mostly one way, so a
four-day-old quote for a flight three weeks out is often gone. A fare for next
April sits still for weeks and the same four days say nothing about it. Holding
on age alone would silence precisely the alerts most likely to be **true**, about
the trips somebody has the most time to act on.

*Why `null` is fresh.* Null means "Orbit does not know how old this is", not
"this is old". Reading it as stale looks cautious and fails in the direction that
breaks the product: on the morning this shipped every row was null, so it would
have switched the alert system off silently until the poller had rewritten the
whole calendar. It would also turn an absence into a claim, which this app
refuses to do everywhere else (a missing fare is absent, not €0). The exposure
shrinks with every poll.

**This gate applies to rule matches too — the §"route_deal vs rule_match"
asymmetry stops here**, and deliberately. Everything else in that table is about
whether Orbit knows enough to hold an *opinion*, which a rule does not need. This
one is about whether the **fare exists**. A rule match names one departure at one
price with a booking link under it exactly as a route deal does, and its fares
are if anything the stalest in the app, because they come from the speculative
sweep over routes nobody watches.

### route_deal vs rule_match — the asymmetry

`AlertCandidate` has two named constructors and the difference is exactly which
fields are null:

| | `watchedRoute()` | `ruleMatch()` |
| --- | --- | --- |
| `score` | the route's deal score | `null` |
| `trackingDays` | the route's observations | `null` |
| gated by maturity | **yes** | **no** |
| gated by sensitivity | **yes** | **no** |
| gated by **freshness** | **yes** | **yes** — see below |
| grouping | one mail per route | one mail per **rule**, however many routes |

**Rules stay ungated, and it matters most here.** A rule's threshold is a
maximum price the owner wrote down: "under €80" is arithmetic against a number a
person chose, and it is exactly as true on a route's first morning as on its
hundredth. A deal *score* is an inference from a distribution, and on the first
morning the distribution is one price wide. Gating rules on how long Orbit has
watched a route would silence the feature on precisely the fares it exists to
find — rules are how the owner asks about routes nobody is watching. The
matching engine has already applied the cap before the pipeline sees the fare
(`RuleMatches`), so re-checking it here would be second-guessing the rule.

One mail per rule because "somewhere sunny under €80" is a question about a
category, and on the morning a sale starts it answers eleven times at once —
eleven mails is how somebody learns to filter this app into a folder they never
open.

### Cooldown

| what | value | config key |
| --- | --- | --- |
| window | 24 hours | `orbit.alerts.cooldown_hours` |
| override | a further ≥ 5% drop | `orbit.alerts.further_drop_percent` |
| key | `type \| route_id \| rule_id` | `AlertLedger::key()` |

A fare that sits at 95 for a week is one piece of news; a person mailed about it
seven times stops opening the mail, at which point the eighth — about a route
they would have booked — is not read either. The cooldown protects the one
message that matters.

The drop beats it because a still-falling fare is the one thing worth
interrupting for: "€44, 53% below usual" yesterday and €38 today is the morning
somebody actually books. The comparison is **integer arithmetic** —
`price × 100 ≤ last × (100 − 5)` — because a fare landing exactly on the
threshold is what every test in the world is written against, and a float
comparison would go the right way on some prices and the wrong way on others.

The cooldown is checked in whole seconds, **inclusive**: a run at 06:55 every
morning is 86,400 seconds after the last one to the second, and an exclusive
comparison would suppress every second day depending on how long the queue took.

Two different rules matching the same route are **two** cooldowns — each rule is
a separate question the owner asked, and suppressing one because the other fired
yesterday reads exactly like the new rule not working.

### Quiet hours

| what | value | where |
| --- | --- | --- |
| default | on, 22:00–08:00 | migration `…_create_user_settings_table` |
| stored as | wall-clock `time` in `orbit.timezone` | `user_settings.quiet_start/quiet_end` |
| arithmetic | minutes since local midnight, no dates | `App\Domain\Alerts\QuietHours` |
| conversion to an instant | once | `App\Application\Alerts\DeliveryWindow` |

**Alerts are decided during quiet hours and delivered after them.** The ledger
records `triggered_at` at 06:55 and the notification is delayed to the end of
the window, so a cooldown measures from when the deal was found rather than from
when somebody woke up. `triggered_at` and `delivered_at` are therefore two
different facts, and `GET /api/alerts` publishes both.

The window usually crosses midnight, which is the whole difficulty: the test is
`≥ start OR < end`, and a naive `AND` is not merely wrong at 03:00 — it is wrong
in the direction of sending mail at three in the morning. The **end is
exclusive** (08:00 is not quiet; it is when the held mail goes out). A
zero-length window (`start === end`) covers nothing, because "22:00 to 22:00" is
somebody who has not finished setting it up. `DeliveryWindow` answers with the
*instant* the window opens, not a duration — a duration computed at 06:55 and
used by a worker that picked the job up at 07:10 would deliver at 08:15 — and it
computes that instant as a wall clock, because on the two nights a year the
clocks move, ten hours after 22:00 is not what "until eight" means.

### The weekly digest

`orbit:digest`, Sunday 09:00. It is the **opposite** of an alert and is built
that way: it ignores the cooldown, the sensitivity and every rule that decides
whether to interrupt somebody. A route suppressed all week because it was
announced on Monday still belongs in Sunday's mail, because the mail is not an
interruption — its job is to make a quiet week legible.

| what | value | config key |
| --- | --- | --- |
| "this week" | 7 days of *delivered* alerts | `orbit.alerts.digest_days` |
| deals listed per section | 6 | `orbit.alerts.mail_deals` |
| skipped when | the digest would be empty, or `weekly_digest` is off | — |

**Not sent when there is nothing in it.** A weekly mail that arrives empty is a
weekly reminder to unsubscribe from the one that will eventually matter — and
the emptiness check runs *before* the ledger row is written, so a skipped digest
does not leave a row claiming one was triggered. The `weekly_digest` flag is
checked twice on purpose: in the job (do not spend a Sunday morning building
something nobody will read) and in the mail adapter (the channel keeping its
promise never to mail an account that asked it not to). Both read the same
single flag.

Its week callout comes from the **stored payload** of delivered alerts, not
re-derived: a fare that has since gone back up is still what was flagged, and
recomputing would quietly turn the week's history into a second copy of the
week's present. Routes are ordered best-score-first (a mail is read top-down
once), ties broken on price so the order is stable from one Sunday to the next.
Quiet hours apply to it too — 09:00 is outside the default window, but an owner
whose quiet hours run to ten gets it at ten.

### The ledger

`alerts` rows, written by `AlertLedger::record()`.

- **Only what fired is written down.** Below-threshold, cooling-down and
  immature-data all leave no row — the ledger is what Orbit *said*, which is why
  the cooldown can be read straight out of it. A row for a decision nobody was
  told about would start a cooldown on a route the owner never heard of.
- **Every new match of a rule is written**, even though the mail spells out only
  `mail_deals` (6) of them and counts the rest ("and 24 more"). The cooldown's
  promise is that a route Orbit has *mentioned* stays quiet, and the mail did
  mention them, in aggregate — recording only the six with their own line would
  mail the seventh tomorrow as though it were new.
- **`triggered_at` is the decision, `delivered_at` is the transport.**
  `delivered_at` stays null until a channel confirms, is stamped once, and stays
  null permanently when the channel is switched off — which is the honest
  record, and exactly what somebody who has just switched the mails back on
  wants to see.
- **The cooldown read is one query and only the cooldown window**, so it stays a
  constant size on a table that grows forever. The ledger and the policy read
  the same config key for that window, so lengthening the cooldown lengthens
  the query with it.
- **The digest is in the ledger and suppresses nothing** — its `price_cents` is
  null and `recentFor()` skips it.
- **Payload is frozen history.** A row from March quotes March's price and
  March's percentage under usual; `GET /api/alerts` answers "what did Orbit
  say", not "what is true now". A rule match keeps the rule's text and chips
  even after the rule is deleted (`deal_rule_id` is `nullOnDelete`).
- **`AlertType`** is a backed enum — `route_deal`, `rule_match`,
  `weekly_digest` — because the value is a column, a cooldown key, an API field
  and a `match()` arm in the mail adapter, and four places agreeing on the
  spelling by convention is three places to get it wrong.

### Channels

Mail is the only channel today. `email_alerts` gates the deal alerts,
`weekly_digest` gates the Sunday mail; `push_alerts` is stored and ignored,
because nothing subscribes a device to web push yet. **The settings gate lives
in the adapter, not in the evaluation** — `email_alerts` is a fact about mail —
so a push adapter is a second class implementing the same one-method port,
reading its own switch, and the code that decides *what* to say does not change
at all. Whether a mail leaves the box is `MAIL_MAILER`.

---

## 11. Rules written in English

### Parsing

`App\Application\Ports\RuleTextParser`, adapter chosen by
`config('orbit.nlp.parser')`:

| adapter | when | code |
| --- | --- | --- |
| `regex` | the default, and what production runs today | `RegexRuleTextParser` |
| `anthropic` | selected automatically once `ANTHROPIC_API_KEY` exists | `AnthropicRuleTextParser` |

`ORBIT_NLP_PARSER` overrides both, which is how a box with a key can still be
pinned to the deterministic parser for a demo, a bisect or the test suite.

| setting | value | config key |
| --- | --- | --- |
| model | `claude-haiku-4-5-20251001` | `orbit.nlp.model` |
| max tokens | 1024 | `orbit.nlp.max_tokens` |
| timeouts | 5s connect / 30s total, 1 retry | `orbit.nlp.connect_timeout`, `…timeout`, `…max_retries` |

Haiku, and not by accident: one short sentence in, one small JSON document out,
the schema does the structural work, and the whole thing has to answer inside a
500 ms debounce while somebody is still typing. The schema is enforced
server-side, so the first text block **is** the JSON — no prose to strip. The
owner's sentence goes in its own content block and the instruction closes the
message, which is also the prompt-injection defence: untrusted text never
becomes part of the instruction and the final word is ours. `stop_reason` is
checked before `content` is read, because a refusal is a perfectly successful
200 with an empty content array.

**Every failure falls back to the regex parser** — refusal, truncation,
unreadable answer, unreachable API. There is no useful error to show between two
keystrokes, so a bad afternoon at a third party costs a slightly less clever
parse and nothing else. The difference is visible only in the log.

The regex parser is not a stub: six independent readers each look for the one
thing they know about and say nothing when they do not find it, which is why
garbage input produces `ParsedRule::nothing()` rather than a wrong rule. Order
matters only inside a reader — a month *range* must be tried before a single
month, or "march to may" reads as "march".

### The chip model

A parse is chips, and chips are the rule. `ChipKind` has six cases, each named
after the criteria field it folds back into:

| chip | criteria field | label example |
| --- | --- | --- |
| `origin` | `origins[]` | From · AMS |
| `max_price` | `maxPriceCents` | Max price · €80 |
| `trip_length` | `tripLengthNights` | Trip length · 2–3 nights |
| `depart` | `departDows[]` | Depart · Fridays |
| `date_window` | `dateWindow` | Date window · Mar – May |
| `vibe` | `vibes[]` | Vibe · ☀ Sunny |

**One direction only** — criteria in, chips out, criteria back. Both adapters
answer with a `RuleCriteria` and hand it to `ParsedRule::of()`; nothing builds
chips by hand. That is what guarantees the chips on screen and the rule that
gets saved can never describe different trips.

**Removing a chip re-derives the criteria from what is left** rather than
re-reading edited text, so taking "From EIN" off leaves every other chip exactly
where it was and Reset is the same parse again. Unknown removed-ids are ignored,
because the client holds its removed list across re-parses of a sentence
somebody is still typing.

**A stored rule's chips are rebuilt from its criteria, never from its text**
(`RuleViews`). Re-parsing `raw_text` would put back every chip the owner
removed and make the correction look like it never happened. The alert mail
quotes those rebuilt chips for the same reason.

### Criteria semantics

Every field means **"no opinion"** when absent, never "no results": empty
`origins` is all three airports, empty `vibes` is anywhere Orbit knows, null
`maxPriceCents` is any price. That is what makes chip removal *widen* a rule.
`RuleCriteria::from()` validates every field on the way in and silently drops
anything malformed — a rule with one unreadable field is a slightly wider rule,
and a rule that throws on load is a screen that cannot be opened at all.

The one exception: a **completely empty** criteria matches nothing
(`RuleMatches::for()` returns none), because that is what an untouched textarea
produces and "6 trips match this right now" under a box nobody has typed in is a
claim about a sentence that does not exist. `RuleController` refuses to store
one, so no saved rule can reach that branch.

**Trip length is parsed, shown and stored — and not matched on.** The price
provider answers with one-way fares per departure date, so the app does not hold
the return leg a "2–3 nights" filter would need. The chip stays because the
sentence really does say it, and dropping it would make the create screen
misread somebody's English; it starts filtering the day the provider grows
return fares, with nothing else changing.

### Month windows

`MonthWindow` stores **two month numbers, not two dates**. A rule is a standing
alert — "somewhere sunny in spring" is a sentence about every spring, not about
spring 2027 — so a window stored as dates would quietly expire on the exact
anniversary it was written for. Wrapping is normal: winter is 12 → 2.

`resolve($today)` turns it into the next real span:

- **the start may be in the past**, deliberately. Asked in April, "spring" is
  the spring we are standing in; answering with next March would hide every fare
  on offer. Only a window that has *ended* rolls forward.
- **the search starts a year back** (`offset = −1`). This only matters for a
  wrapping window: asked on 10 January, "winter" began last December, and a
  search starting at the current year would answer with next December — leaving
  a January rule matching nothing for eleven months. For a non-wrapping window
  the extra year has already ended and the loop moves straight past it.

The label is `"Mar – May"`, or just `"Jun"` for a single month, with the
design's spaced en dash to the character.

### Matching

`RuleMatcher::rank()` picks the destinations, `RuleMatcher::cheapest()` picks
the fare. Both are pure, and the split is what lets `SweepRuleFares` ask the
first question about routes that have no fares yet.

**Two filters and a sort:**

1. **Vibe** — no vibes asked for means anywhere Orbit knows, which is the right
   answer to "anywhere under €50".
2. **Climate**, and only when the rule asks for a warm vibe **and** names a
   window.
3. **Sort** — most matching vibes, then warmest, then IATA code. Total and
   deterministic, so the same rule sweeps the same places every morning rather
   than a different thirty each time.

| what | value | config key |
| --- | --- | --- |
| warm enough | warmth ≥ 4 ("shorts") | `orbit.rules.warm_at` |
| which vibes trigger the gate | `sunny`, `beach` | `orbit.rules.warm_vibes` |

**The best month in the window, not every month.** A person flies on one date:
"somewhere sunny in spring" is satisfied by a place that is warm by May, and
demanding March be warm too would leave the Canaries and nothing else. A rule
with no window skips the gate entirely — "somewhere sunny" is already answered
by the `sunny` tag, and a climate check with no window would be inventing a
season nobody asked about.

A fare must clear the price ceiling, the weekday and the resolved window. Ties
keep the **earlier** date — fares arrive ordered by departure, and the sooner of
two equally cheap flights is the one to show.

### The vibe vocabulary is closed

Nine words — `sunny`, `beach`, `city`, `culture`, `food`, `islands`, `nature`,
`party`, `ski` — and they are the keys of `orbit.nlp.vibe_words`, of
`orbit.nlp.vibe_labels`, and of the vibes in **both** curated data files
(`database/seeders/data/european_destinations.php` and `world_destinations.php`).
`tests/Feature/SeedersTest` asserts the three agree. World flights added a
hundred and seven destinations and **not one new vibe**: an open vocabulary is
one the parser can never be complete against, so Bangkok is `city food party
culture` and Queenstown is `ski nature`, in the same nine words Faro uses.

The **values** of `vibe_words` are the open half: what somebody might actually
type. Adding a synonym is safe; adding a **key** is not, because no destination
carries it and the rule would match nothing. Longest phrases first within a
vibe, so "city break" is not eaten by "city".

The seed data is **184 destinations** (77 European, 107 long-haul) and 3
origins, each destination carrying a climate profile expanded into twelve
monthly warmth ratings (1 "pack a coat" to 5 "beach"). It is a checked-in file
rather than an API because nobody sells "is Faro sunny in March" in a usable
form and the answer does not change.

**Half the world's climate profiles are upside down, which is new.** Cape Town,
Sydney and Buenos Aires are 5 in January and 2 in July, so "somewhere warm in
the winter" finally has an answer beyond the Canaries. Two honest strains on the
1–5 scale are documented where they live, in `world_destinations.php`: a
tropical **wet** season is rated 4 rather than 5 (the thermometer says beach and
the afternoon does not), and a Gulf summer is 5 because "beach" is the hottest
thing this vocabulary can say — a ceiling, not a recommendation.

**The 3,270 airports in tier 1 are not in any of this.** They have no
`destinations` row, so no vibe, no warmth, and no rule can ever match them (§1).
They are watchable and priceable; they are not suggestible.

### Sweeping

`orbit:sweep-rules` → `App\Jobs\SweepRuleFares`, one per active rule.

| what | value | config key |
| --- | --- | --- |
| cap | 30 origin×destination pairs per rule per run | `orbit.rules.sweep_cap` |
| sample shown on the create screen | 6 | `orbit.rules.sample` |

A rule names routes nobody has ever watched, and the daily poll only visits the
watchlist — without this job a new rule matches nothing on the day it is
written, which reads as a broken feature rather than an empty cupboard. So
creating a rule queues a sweep, and the schedule runs it again every morning
after the watchlist poll.

**The cap is the point.** A rule with no vibe at all is 3 origins × 184
destinations = 552 provider calls, spent on a sentence somebody may delete a
minute later. The cap keeps the best-fitting thirty — "best" is the matcher's
ranking — and logs what it dropped. **World flights more than doubled the
number that cap protects against and did not change the cap**, which is exactly
why it was written as a budget rather than as a percentage: a sweep still costs
at most thirty routes × four months however many places the rule could have
meant.

**Already-priced-today routes are skipped *before* the cap is applied**, not
after: the cap is a budget for provider calls, and spending it on routes the
06:10 poll already fetched would mean a rule overlapping the watchlist never
reaches its own tail. This is also why the sweep runs *after* the poll.

**The routes a sweep creates are the ones a lookup lands on for free.** A rule
that swept `AMS-CTG` this morning left a route row and four months of calendar
behind it; opening that pair from the search screen costs nothing, because
`FareFreshness` finds fares younger than `orbit.lookup.fresh_for_hours` and
fetches nothing. The two features pay into the same table — see §1 for why the
lookup is synchronous and the watchlist add is not.

It creates `routes` rows for pairs nobody is watching, which is fine — a route
is a fact about the world. Nothing shows them until a rule matches one, and
promoting a match to the watchlist hands over the history the sweep already paid
for. There is no "add this match" endpoint: `POST /api/watchlist` with the two
codes reuses the route.

**Match counts are not capped.** `RuleMatchSummary::count()` is every match, and
the banner's number has to be the truth about the rule — a rule matching sixty
routes is one somebody should probably tighten, and a "6" capped by the sample
size would hide exactly that. What is capped is the *sample* the create screen
shows (`rules.sample`, 6) and the number of deals a mail spells out
(`alerts.mail_deals`, 6, plus "and N more"). In practice the sweep cap bounds
how many routes a *given* rule ever gets fares for, but a rule can and does
match more than 30 when the watchlist or another rule has already priced routes
it ranks — the cap limits provider calls, not matches.

---

## 12. Booking

**Orbit does not sell flights and is not going to.** A hand-off is a deep link
into somebody else's search — no API, no key, no agreement. There are **two**,
and which one is first is a correctness matter rather than a preference.

| what | value | config key | code |
| --- | --- | --- | --- |
| **primary** | `https://www.aviasales.com` | `orbit.booking.aviasales_base` | `App\Application\Routes\BookingLink` |
| params | `{ORIGIN}{DDMM}{DEST}{passengers}`, **upper-case IATA, day before month** | — | ditto |
| dated form | `/search/AMS1509OPO1` | — | ditto |
| undated form | `/?params=AMSOPO1` — the pre-filled search box | — | ditto |
| marker | appended as `?marker=` when set | `orbit.travelpayouts.marker` | ditto |
| **secondary** | `https://www.skyscanner.nl/transport/flights` | `orbit.booking.skyscanner_base` | ditto |
| path | `/{origin}/{dest}/{yymmdd}/`, lower-case IATA | — | ditto |
| undated form | `/{origin}/{dest}/` = "show me the whole month" | — | ditto |

**Aviasales is primary because that is where the price came from.** Fares reach
Orbit through Travelpayouts, which is Aviasales' cache; the app quoted those
fares and then handed the reader to Skyscanner, a different meta-search with a
different set of agencies and no reason to be holding the same fare. It showed
DUS→AGP at **€29** where Skyscanner's cheapest for that date was **€68**.
Quoting one shop's price and pointing at another's till is a way to look wrong
while being right. Skyscanner survives as a quiet "Compare on Skyscanner" text
link, because a second opinion is worth having.

**One passenger, economy, one-way.** The trailing `1` is the passenger count and
is mandatory — the link does not work without it — and economy is the *absence*
of a class letter. One-way matches the fare: every price in this app is one-way
(§2), and a round-trip hand-off would open a search whose cheapest result cannot
be the number the user just tapped. The params string is **case-sensitive** in a
way that fails silently: `PAR1607ROc1` is Romania in business class,
`PAR1607ROC1` is Rochester airport in economy.

The undated forms are the right fallback for a route with no fares yet — for
Aviasales that is the pre-filled search box rather than a results page, because
there is no day to show results for. The route detail sends resolved
`booking.aviasales` / `booking.skyscanner` for the cheapest departure; the
calendar sends `meta.booking` with the same two links, each with a **date-shaped
hole named after its format** (`{ddmm}`, `{yymmdd}`), because the day sheet books
**whichever** day was tapped and only the client knows which. Named holes rather
than one `{date}` keep the client from having to know which URL belongs to which
site. Both are always present, including for an empty month: they are facts about
the route, not about the fares.

**`TRAVELPAYOUTS_MARKER` is finally used.** It spent its whole life read-but-unsent
against a comment saying it was there for "the day those links move to Aviasales".
That day arrived for a reason that had nothing to do with money — the price
consistency above — and the attribution comes along with the fix. It is appended
to the Aviasales hand-off and to nothing else: the data API has no use for it, so
no request in `App\Infrastructure\Pricing` carries it, and Skyscanner has never
been monetised. Unset is fine and is the default; what is lost is the credit, not
the destination.

**Neither link promises a seat.** Both sit under one line — *"Prices come from
recent searches — the booking site shows live availability"* — which merged the
old "we don't sell tickets" disclaimer rather than stacking beside it. See §2.

---

## 13. The daily timetable

Every time is **Europe/Amsterdam**, from `config('orbit.timezone')`, in
`routes/console.php`. Storage is UTC and always will be — but "06:10" is a
statement about the owner's morning, and without the timezone it would drift an
hour twice a year and poll at 08:10 through the summer, after they have already
looked at their phone. Every entry is `withoutOverlapping()`.

| when | command | why that time |
| --- | --- | --- |
| **06:10 daily** | `orbit:poll-fares` | before the owner is awake, after the airlines' overnight fare loads have settled. Fans out per-route jobs at a 3-minute stagger, each asking for the **near** window |
| **Sat 04:10** | `orbit:poll-fares --far` | the same fan-out asking for the whole **eleven-month** horizon — twelve provider calls a route where the daily poll costs seven. In its own clock hour because 9 × 12 beside the sweep's 120 would be 228 against a ~200/hour limit; Saturday because eleven months out is holiday planning. It does **not** replace that day's 06:10 poll, and cannot: both write the same near-window observation, idempotently |
| **06:40 daily** | `orbit:sweep-rules` | **after** the poll, so the sweep's capped budget is not spent re-fetching routes the watchlist just priced. Half an hour is comfortable room for six staggered polls |
| **06:55 daily** | `orbit:alerts` | **last**. It talks to no provider — every fare it reads was written by the two runs above. Running it first would not fail; it would mail this morning's verdict on yesterday's prices, every day, invisibly |
| **Mon 05:40** | `orbit:refresh-stats` | ahead of that morning's poll, so the week's scores are read against the week's statistics. Weekly because the answer is monthly: a route's usual price is built from months of fares, and the score is deliberately most sensitive to it — an argument for it being stable, not fresh |
| **Sun 09:00** | `orbit:digest` | later than the weekday runs on purpose. Everything else is scheduled to be finished before the owner is awake; this one is meant to be read over coffee, and it is the only mail Orbit sends that nothing crossed a threshold to earn |
| **03:10 daily** | `build:retain` | the quietest hour, nowhere near the morning's runs. Not a fan-out — a manifest read and a handful of unlinks. On the schedule because `emptyOutDir: false` turns a forgotten deploy step from "the pruning did not happen" into "the disk fills up" |

The three morning commands are **fan-outs**: they decide *when* and queue the
work onto Redis for Horizon, because nothing that talks to a rate-limited third
party should run inside the process that keeps the clock. They are commands
rather than `Schedule::job()` per route because `routes/console.php` is loaded on
every artisan invocation — including `migrate` against an empty database — so a
query there would run before the tables it reads exist. That also makes each one
runnable by hand:

```bash
docker compose exec app php artisan orbit:poll-fares --now
docker compose exec app php artisan orbit:poll-fares --far --now   # months 7-11
docker compose exec app php artisan orbit:sweep-rules --now
docker compose exec app php artisan orbit:alerts --now
docker compose exec app php artisan orbit:refresh-stats --now
docker compose exec app php artisan orbit:digest --now
```

Fifteen minutes between the sweep and the alert run: a rule whose polls have not
landed yet costs a matching route one day's delay in being noticed, and never a
wrong alert — nothing here invents a fare it cannot see.

---

## 14. Providers and switches

Ports chosen by name in config and bound in `AppServiceProvider`. An **unknown
name throws at resolution** rather than falling back, because a box quietly
serving invented prices would send real alerts about fares that do not exist.

| port | env | values | adapter |
| --- | --- | --- | --- |
| `PriceProvider` | `ORBIT_PRICE_PROVIDER` | `fake` (default) \| `travelpayouts` | `FakePriceProvider` \| `TravelpayoutsPriceProvider` |
| `PriceStatsProvider` | `ORBIT_STATS_PROVIDER` | `fake` (default) \| `self` | `FakeStatsProvider` \| `SelfStatsProvider` |
| `ReturnTripProvider` | `ORBIT_RETURNS_PROVIDER` | `fake` (default) \| `travelpayouts` | `FakeReturnProvider` \| `TravelpayoutsReturnProvider` |
| `RuleTextParser` | `ORBIT_NLP_PARSER` / `ANTHROPIC_API_KEY` | `regex` (default) \| `anthropic` | `RegexRuleTextParser` \| `AnthropicRuleTextParser` |
| `DealNotifier` | — | mail | `MailDealNotifier` |

**One-way and round-trip are two switches even though both real adapters talk to
Travelpayouts.** They read different endpoints with different coverage and
different failure modes, and the round-trip half is much the thinner of the two
— so a box can run real one-way fares, which every deal score and alert depends
on, while `return_fares` is still being filled by the fake. One key would have
made turning returns on a change to the deal score. See §15.

**The fakes are production adapters, not test doubles.** The app ships before
the provider keys exist, so a fake is what production actually runs until
somebody flips the switch. They are deterministic per route, so the same route
shows the same prices on every deploy and a feature test can assert real
numbers. In the **test suite** and the **E2E sandbox** both fare providers are
pinned to `fake` and the parser to `regex` — see `.env.testing` and
`docker-compose.e2e.yml`. `tests/TestCase` additionally calls
`Http::preventStrayRequests()`, so a request nobody explicitly faked is a failed
assertion rather than a socket; the Anthropic SDK carries its own PSR-18 client
and is closed by the pinned parser instead.

**Travelpayouts refuses to resolve without a token** — a box configured for it
with no token is a mistake somebody made at deploy time and must find out about
immediately. Everything else (a 500, a timeout, a truncated body, a response in
the wrong currency) is Tuesday: no fares, and a line in the log, rate-limited to
one warning per 15 minutes so an outage is not 24 identical lines per morning.

| setting | value | config key |
| --- | --- | --- |
| connect / read timeout | 5s / 15s | `orbit.travelpayouts.connect_timeout`, `…timeout` |
| retries | 1, 500 ms apart | `orbit.travelpayouts.retries`, `…retry_delay_ms` |
| warning rate limit | every 15 minutes, globally | `orbit.travelpayouts.warn_every_minutes` |

⚠ **Flipping a provider is not only that line.** Every fare in the database was
written by whichever adapter was in force at the time and no row records which,
so a real price landing in a table full of simulated ones makes the 30-day
trend, the "usually €120" and the next alert quietly wrong. `ORBIT_STATS_PROVIDER=self`
is a summary of whatever is in the tables — pointing it at fake data produces a
real statistic about a simulation. Flip both, then:

```bash
php artisan orbit:reset-history --confirm
```

It reports the counts, then clears the three observation tables —
`route_price_history`, `calendar_fares`, `route_price_stats` — with row deletes
rather than `TRUNCATE` (DDL takes an `ACCESS EXCLUSIVE` lock on a live box while
Horizon may be mid-upsert, and these are thousands of rows, not millions). It
then calls the ordinary `orbit:refresh-stats` and `orbit:poll-fares` rather than
a private copy of what they do, statistics first because a deal score is a
percentile against them. Without `--confirm` it only reports.

The watchlist, rules, settings and alert ledger are untouched. Charts then
honestly say "tracking 1 day" — and, per §7, say nothing about the deal for a
week.

---

## 15. Return trips (foundation)

> **Status: groundwork.** A port, two adapters, the `return_fares` table and a
> command to fill it. **Nothing reads the table yet and nothing polls it on a
> schedule.** The screens, the statistics and the rule matching are later PRs in
> this milestone.

### Why one-way was never the whole truth

Every price in this app is a **one-way** fare (§2), which is the right number
for the EU budget carriers Orbit was built around and the wrong number for
anything long-haul: nobody flies to New York one way. Measured against the live
API on **2026-08-16**, cheapest one-way against cheapest round-trip on the same
route:

| route | cheapest one-way | cheapest return | one-way as a share of the return |
| --- | --- | --- | --- |
| AMS–LIS | €80 | €134 | 60% |
| AMS–JFK | €334 | €484 | **69%** |
| AMS–BKK | €272 | €472 | 58% |

A long-haul one-way is roughly **two thirds** of a return, not half of one. So
"AMS–JFK from €334" was never wrong about the arithmetic and was always wrong
about the trip.

### What the data actually is

**One endpoint answers, and the tempting one does not.** Measured the same day:

| endpoint | verdict |
| --- | --- |
| `/v2/prices/latest`, `one_way=false`, `period_type=year` | **This one.** One entry per `(depart_date, return_date)`, every one carrying `return_date` and `found_at`. One request covers the whole horizon. |
| `/v1/prices/calendar` with `length=7` | Round-trip and duration-aware, but returned **2 dates for the whole of November** and carries **no `found_at`**. |
| `/v2/prices/week-matrix` | Returned **one** entry, for a departure two days from the one asked for. |
| `/v2/prices/month-matrix` | One-way only — every recorded entry has an empty `return_date` (§2). |

**There is no duration grid, and the model does not pretend there is.** The
obvious shape — cheapest fare for every (departure date × stay length) — is not
something any endpoint answers. What exists is *the round-trip fares somebody
searched for in the last week*:

| route | entries | near-window departure dates covered | of the 182 |
| --- | --- | --- | --- |
| AMS–BKK | 338 | 61 | 33.5% |
| AMS–LIS | 119 | 50 | 27.5% |
| AMS–JFK | 56 | 27 | 14.8% |
| EIN–BCN | 23 | 14 | **7.7%** |

Against 41–87% coverage for one-way month-matrix. And of the dates that *do*
carry a fare, most carry exactly **one** stay length — 34 of 52 for AMS–LIS, 34
of 38 for AMS–JFK. **"The cheapest 7-night trip leaving on 3 November" usually
has no answer**, and every screen built on this table has to say so rather than
show a blank.

**Three parameters are load-bearing and one is a decoration:**

- `one_way=false` — the two settings return **disjoint caches**. AMS–LIS gave
  128 entries with an *empty* `return_date` at `true`, and 119 with a populated
  one at `false`.
- `limit=1000` — the default is **30**. AMS–BKK returned 338 with it and exactly
  30 without, no error and no marker to say the answer was truncated.
- `period_type=year` — the whole horizon in one request. `month` works, but
  November alone returned 5 of the 119 entries the year call already held.
- `trip_duration` — **silently ignored.** `trip_duration=7` produced a
  *byte-identical* body. Duration bands are therefore filtered in the adapter,
  and a narrow band costs exactly what a wide one does.

**Two gotchas worth the ink.** `found_at` on this endpoint has **no trailing
`Z`** (`2026-08-10T20:11:25`) where the matrix endpoints have one — both UTC,
only the notation differs, and an adapter that pinned one format would drop the
age off every round-trip fare in the app. And the API **normalises airports to
city codes**: ask for `JFK`, get `NYC` back in the echoed fields.

**Round-trip fares are structurally older than one-way ones.** This endpoint
serves the last **seven days** of finds — the recorded `found_at` range was
2026-08-09 to 2026-08-16 on all three routes, with only 45% of AMS–LIS entries
found on the day of the call. Anything that later alerts on these fares has to
reckon with `orbit.alerts.max_fare_age_days` being **2** (§10).

### The model

| | value | where |
| --- | --- | --- |
| grain | one row per (route, **departure date**, **nights**) | `return_fares` |
| direction | round trip, always | `TravelpayoutsReturnProvider` |
| stay length | `nights`, 0–60, **stored**; the return date is *derived* | `ReturnTrip::returnDate()` |
| currency | EUR, cents internally | as everywhere |
| age | `found_at` nullable, `fetched_at` stamped by the poll | `PollReturnFares` |
| horizon | 334 days, the same depth as the one-way calendar | `orbit.returns.window_days` |
| staleness | one clock, 3 days | `orbit.returns.stale_after_days` |

**`nights` is stored and `return_date` is not.** They are the same fact twice,
so exactly one may be a column. Nights wins because it is the axis every
question is asked along ("a week away", and `tripLengthNights` has held
`[min, max]` since the rules engine shipped), because it indexes as an ordinary
integer where a date subtraction is an expression index spelled differently on
Postgres and SQLite, and because deriving the date back is exact.

**Zero nights is legal**; a negative stay is corrupt and is refused by the
domain type, the adapter and the column alike.

**Retention is one rule where the one-way calendar needs two.** `calendar_fares`
is polled at two speeds and so carries two staleness clocks and a two-pass
prune. `return_fares` is fetched **whole, in one request**, so every row is
always exactly as fresh as every other and one clock is the honest description.
`PollReturnFares` deletes departures that have gone by, departures past the
maintained horizon, and rows the provider has stopped quoting — three deletes,
one clock, and all three only after a *successful* poll.

### Duration bands

`orbit.returns.durations`, `[min, max]` nights, **inclusive at both ends** —
fitted to the measured stay-length histograms rather than chosen for tidiness:

| band | reading | why |
| --- | --- | --- |
| `[2, 3]` | a long weekend | the short-haul mode; the regex parser already turns "weekend" into exactly this pair |
| `[6, 8]` | a week | the one band with real mass on **every** route measured |
| `[13, 15]` | a fortnight | AMS–JFK's second peak, and where AMS–BKK's mass begins |
| `[21, 28]` | three to four weeks | long-haul only: AMS–BKK carries more entries in this band (72) than AMS–LIS carries in total |

Adding a band costs **no requests** (the API ignores `trip_duration`). What it
does cost is fake data — `FakeReturnProvider` prices only the lengths the bands
cover, so emptying the list leaves the fake with no fares at all.

### The budget, and why it is not scheduled

**One request per watched route per run** — 9 today — because one call covers
the whole horizon, against 7 or 12 for the one-way calendar. At W routes it is W
requests, flat, so returns polling never becomes the binding constraint.

It is nonetheless **not in `routes/console.php`**, because a schedule that spent
calls every morning filling a table with no readers is a standing cost for no
benefit. The PR that adds the first reader adds the entry, and the arithmetic is
already done: the 06:00 hour is at 183 of ~200 and 9 more would leave 8 requests
of headroom, so it belongs in the **04:00** hour next to the far poll (108 + 9 =
117). Until then `php artisan orbit:poll-returns` fills the table by hand — and
a fortnight of accumulated real fares is worth considerably more to the PR that
draws them than an empty table and a fake.

### What later PRs add

- statistics and a deal score for return trips (the analogue of §6 and §7 — note
  that a "current price" for returns has to be *defined* before it can be
  computed, and this PR deliberately writes no observation row)
- the screens that read the table, which must be built for the sparsity above
- `tripLengthNights` finally **matching** rather than only being parsed and shown
  (§11 and `docs/API.md`) — the fact it filters on now exists
- alerts on round-trip fares, which have to reckon with the seven-day-deep cache

---

## 16. Discovery — the routes you never thought to watch

**The question this answers is not "find cheap fares", it is "surprise me".**
The watchlist answers what the owner already thought of (§1); a rule answers a
sentence they wrote down (§11). This answers neither. It is the €29 Santorini
nobody would have known to ask about, and every decision below falls out of that.

`orbit:discover`, daily at **05:20**. `app/Jobs/DiscoverDeals.php` is the work,
`app/Domain/Discovery/` is the rulebook, `discoveries` is the table,
`GET /api/discoveries` is the read, and the search screen's "Deals from your
airports" strip is the only thing that draws it.

### ⚠ v1 surfaces. It never interrupts.

**Discovery sends nothing.** No mail, no notification, nothing written to
`alerts`, no interaction with §10 at all. It writes rows to a table that an
endpoint reads when somebody opens a screen.

That is a deliberate restriction and not an unfinished edge. A discovery is, by
construction, the three things that most disqualify a fare from being allowed to
wake somebody up:

- **nobody asked about it** — no watchlist row, no rule, no expressed interest;
- **it is the least verified data in the app** — a swept cache entry up to three
  days old, on a route Orbit has usually never polled;
- **it has no history at all**, so none of §7's score, §8's `confident` or §10's
  `min_tracking_days` can be computed for it. The entire day-1 honesty apparatus
  is inapplicable.

Alerting on a discovery is a **future decision**, and it would need its own
argument — most likely a much higher bar than the screen uses, and probably the
Google check being mandatory rather than best-effort. Nothing in this PR
prejudges it.

### The two-stage funnel

Sweeping is nearly free and verifying is not, and the split between them is the
whole design.

| stage | cost | what it does |
| --- | --- | --- |
| **1. sweep + score** | 3 requests | ~1,177 fares from the three home airports, ranked to a shortlist of 5. Arithmetic only. |
| **2a. own window** | 5 × ≤7 requests | Each finalist's own near window, through the existing `PriceProvider`. Is this remarkable *on its own route*? |
| **2b. Google** | ≤5 searches | Does a company that is not Travelpayouts agree? Skipped entirely without a key or quota. |

**Nothing reaches the screen on the sweep's word alone**, and that is the
lesson §2 was written to record. Orbit has shipped €36 for a date whose live
cheapest was €56, and DUS-AGP at €29 against a Skyscanner cheapest of €68. The
top five of a thousand cached rows under the words "insanely cheap" would be
that mistake automated and given a schedule.

### The sweep

One request per origin, with the **destination parameter simply absent** —
`/v2/prices/latest` answers for everywhere when you do not say where. Measured
2026-08-16: **AMS 562, DUS 419, EIN 196** distinct destinations, one entry each,
1,177 rows for three requests.

Two traps are load-bearing. `found_at` on this endpoint has **no trailing `Z`**
where the month-matrix endpoint has one — and since an unknown age is treated as
too old, a single wrong format string would make the feature permanently, silently
*empty* rather than wrong. And the provider's own `distance` field agreed with
haversine on 518 of 520 destinations and put **Brussels 5,951 km from Amsterdam**
(it is 158), which would have led the list every day; distance is computed from
`airports.lat`/`lng` instead (`app/Domain/Geo/Haversine.php`).

45 of the 1,177 rows named metropolitan codes (LON, MOW, MIL, BUE) with no
airport row. They are dropped: no coordinates means no honest €/km, and no route
code means a card that goes nowhere.

### Why €/km, and why it is not enough

Ranked by **price**, this screen is the nearest airports forever — Brussels,
Cologne, Maastricht. Ranked by **what a euro buys**, Marrakesh and Tangier come
top, which is the point.

But €/km alone puts Singapore (€287, 27.3 m€/km), Manila (€293) and Bangkok
(€271) in the same band as Málaga (€36, 19.1). Those are real bargains and none
of them is this feature: the promise is a fare you see on a Tuesday and book on
the Tuesday. So there is an **absolute ceiling** (€120) alongside the ratio, a
**distance floor** (400 km — under it you are describing a train), and a
**freshness rule** (3 days). All four are floors, never quotas: a week with
nothing in it produces an empty screen.

The top of the 2026-08-16 answer: Marrakesh €27 (10.8), Tangier €23 (11.5),
Vilnius €18 (13.1), Tirana €21 (13.5), Pescara €16 (14.1), Málaga €29 (15.6).

### What "verified" means, and why almost nothing is

A finalist must clear its **own window** first — bottom tenth by percentile
*and* at least €15 under that window's median. Both, because each is blind to
what the other catches: the percentile misses a route so flat that its bottom
tenth saves nobody anything, and the savings floor misses a route that is simply
cheap everywhere. DUS-AGP's €29 was cheaper than all 23 fares in its October
window, against a €78 median.

Then, if there is quota, Google. **The verdict reads Google's market, not our
price** — `price_level: "low"`, or Google's own `lowest_price` at or under its
typical-range low. The obvious rule ("our fare is under Google's typical range")
confirms everything:

| route | Travelpayouts | Google's own cheapest | level | typical |
| --- | --- | --- | --- | --- |
| DUS-AGP | €29 | €70 | typical | 55–175 |
| DUS-RAK | €27 | €168 | typical | 100–200 |
| EIN-VNO | €18 | €30 | typical | 20–245 |

All three are under their typical-range low. All three would have been stamped
"✓ verified low by Google" — while Google could not find a seat at anything like
the price. **The candidate's price is the number under suspicion**, and testing
it against Google's range asks the suspect to vouch for itself. Under the rule
Orbit actually uses, none of the three verify, and all three are shown honestly
as "great find" with the age printed beside them.

**A skipped check is not an error.** No key (the default), quota under the
50-search reserve, a run past its cap of 5, a timeout, or a route with no
`price_insights` all leave the candidate unverified and shown. What must never
happen is a badge without a check behind it.

### A discovery is ephemeral

It is the one table in this app that is deliberately **not history** (§5 is the
opposite). Nothing computes a statistic from these rows and a discovery from
last March would offer a flight that has left. They expire after 36 hours — one
run, plus half a day of slack so a failed run leaves yesterday's set standing
rather than blanking the screen — and every run prunes expired rows, departed
dates, superseded rows and anything past `max_rows`. Steady state is about ten
rows.

**No `route_id`.** A discovery is a route nobody watches and Orbit has usually
never priced; creating `routes` rows nightly would fill the table that §1 treats
as "pairs this app knows about" with five speculative rows a night. The airports
are foreign keys, the route is the `code` string, and tapping a card runs the
ordinary lookup flow — which creates the route row **at the moment somebody
shows interest**, which is what that endpoint has always meant.

### The budget

3 sweep + 5 × ≤7 verification = **38 Travelpayouts requests**, plus **≤5 SerpAPI
searches** out of 250 a month. Scheduled into the empty **05:00** hour: in the
06:00 hour it would be 221 of ~200 and over the limit. The worst hour of the
week is unchanged by this feature. Full table in `config/orbit.php`'s `poll`
section; the SerpAPI guardrails are in its `serpapi` section.

---

## Where the rules live

| concern | code |
| --- | --- |
| deal score, verdict, advice | `app/Domain/Pricing/` |
| statistics arithmetic | `app/Domain/Pricing/PriceStats.php`, `app/Infrastructure/Pricing/SelfStatsProvider.php` |
| alert decisions, quiet hours | `app/Domain/Alerts/` |
| rule matching, month windows, chips | `app/Domain/Rules/` |
| discovery thresholds, ranking, Google verdict | `app/Domain/Discovery/` |
| great-circle distance, server side | `app/Domain/Geo/Haversine.php` |
| assembling what the screens read | `app/Application/Routes/`, `app/Application/Rules/` |
| the alert pipeline | `app/Application/Alerts/` |
| the ports | `app/Application/Ports/` |
| every tunable number, with its reasoning | `config/orbit.php` |
| the schedule, with its reasoning | `routes/console.php` |
| destinations, vibes, climate | `database/seeders/data/european_destinations.php` |

The domain classes are pure PHP with zero framework imports and are unit-tested
under `tests/Unit/Domain/` — if a rule in this document is not obvious from the
code, that test is where it is pinned.
