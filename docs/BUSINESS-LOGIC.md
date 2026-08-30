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
the home airports out as quick chips — hardcoded to match config — and its boxes
take any of the 3,270.

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
Travelpayouts allows ~200 requests an hour per IP, thirteen routes are watched:

| when | what | requests |
| --- | --- | --- |
| 06:10 daily | poll, 9 × ≤7 months | 63 |
| 06:40 daily | rule sweep, 30 × ≤4 months | 120 |
| | **the ordinary morning's clock hour** | **183** |
| 04:10 Saturday | far poll, 9 × ≤12 months | 108 |
| 04:40 daily | returns poll, 9 × 1 call | 9 |
| | **the 04:00 hour on a Saturday** | **117** |

So the eleven months cost **nothing in the worst hour**. What breaches first is
the ordinary morning, at **twelve watched routes** (7 × 12 + 120 = 204); the far
run has room to sixteen. `tests/Unit/Infrastructure/TravelpayoutsPriceProviderTest`
asserts both halves.

`orbit:poll-fares` is a fan-out: it queues one `PollRoutePrices` per actively
watched route, delayed by `index × stagger`, so thirteen routes trickle over
thirty-six minutes rather than arriving as a burst against a per-minute rate
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

**The row is a near-window minimum, whatever that morning's run actually
fetched.** `PollRoutePrices` bounds the day's observation to
`poll.window_days` even on the weekly `--far` run, which reaches eleven
months. Taken over the depth of the fetch instead, a Saturday's row would be
the cheapest fare in the next *eleven* months — lower on most routes for no
reason but how deep the run looked — and the series would dip every Saturday
and recover every Sunday. The trend component would read that sawtooth as a
fall and a recovery, and the percentile would score an ordinary Saturday as
the cheapest morning of the month. The near-window filter compares `'Y-m-d'`
strings rather than `DateTimeImmutable`s, because the near edge is midnight in
the owner's timezone while a provider's departure date carries whatever zone
the adapter built it in — two instants for the same calendar day can be hours
apart. If a run somehow fetched nothing inside the near window at all, no row
is written: yesterday's row is a better answer than an invented one.

**The write is an `upsert` with the date as a bare `'Y-m-d'` string, and
`updateOrCreate` is the trap it avoids.** `updateOrCreate` runs the value
through the model's date cast on the way *in* but not on the way to the
`WHERE` clause, so the lookup compares `'2026-08-14'` against a stored
`'2026-08-14 00:00:00'`. Postgres coerces both to its `date` column and never
notices; SQLite, which the test suite runs on, stores the string it is given,
the two do not match, and every poll inserts a duplicate that hits the unique
index. `observed_on` is stamped in the **owner's** timezone rather than UTC
for the same class of reason: the poll runs at 06:10 Amsterdam, where both
zones agree, but a retry landing at 00:30 local is still yesterday in UTC and
would overwrite yesterday's observation with today's price.

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
disproportionately the seasonal peaks — Christmas, Easter, school-holiday weeks — pooled in, those
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

### Implementation notes

`AlertNotification` implements `ShouldQueue` — without it, `->delay()` for
quiet hours silently becomes decoration. `AlertCandidate::$alertIds` must stay
`protected`, never `private` — Laravel's `SerializesModels` reflects on
parent-class properties and silently drops private ones on the way to the
queue, surfacing as a fatal deep inside the delivery listener. **This is a
DO-NOT-REMOVE landmine.** `DigestBuilder` reads only the same classes screens
read, so the mail can never disagree with what a tap-through shows; routes
with no observation are skipped (not scored 0), rules with 0 matches are
omitted rather than shown as "0 matches", and `week()` reads the stored
payload rather than re-deriving it. An unknown alert-notice type throws rather
than being dropped — an alert that silently goes nowhere is the worst failure
this app has, because everything still looks like it's working.
`AlertEvaluation` reads the quiet window and the cooldown ledger **once per
run**, not per route — the window cannot move while a run is in flight, and a
per-route ledger read would be thirty round trips inside a job meant to be
short. Its log line carries `routes_too_new` and none of the other three held
reasons, because that one explains a *morning* rather than a route: a
watchlist filled in yesterday sends nothing today, and `route_alerts: 0` on
its own reads like a broken poller. The freshness guard is asked about the
fare the mail **points at** — the cheapest calendar fare — not the observation
the score came from, the same split `DealSummary::forRoute()` makes: what the
reader clicks is what has to be real. The profile button's `#account` entrance
scrolls only once the settings have settled (`ready` or `failed`) — `idle` and
`loading` are both "the four cards above it have not rendered", and scrolling
then lands the reader in the middle of quiet hours a moment later.

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

**The eyebrow on a chip is the server's word.** "From", "Max price", "Trip
length" arrive on the chip and the client only upper-cases them, because the
category names a criteria field the back end owns — a hard-coded list in the
component would be a second vocabulary to keep in step, and the failure would
be a chip labelled for a field that no longer exists. The chip itself is not
clickable either: only the × is, so there is exactly one thing for a keyboard
to reach, and its label says which chip it removes rather than "Remove".

**One direction only** — criteria in, chips out, criteria back. Both adapters
answer with a `RuleCriteria` and hand it to `ParsedRule::of()`; nothing builds
chips by hand. That is what guarantees the chips on screen and the rule that
gets saved can never describe different trips.

**Removing a chip re-derives the criteria from what is left** rather than
re-reading edited text, so taking "From EIN" off leaves every other chip exactly
where it was and Reset is the same parse again. Unknown removed-ids are ignored,
because the client holds its removed list across re-parses of a sentence
somebody is still typing.

**A chip's `id` is stable across parses of the same sentence** — it is the
kind plus the value, never a position — because the client holds a list of
removed ids across a re-parse it did not ask for (every keystroke re-parses,
500ms behind). An index-based id would silently start removing a different
chip the moment somebody edited a word earlier in the sentence.

**A chip's × is never disabled, and a removal never waits.** `Create.vue`
debounces typing by 500 ms, but disabling the × for that window — or for the
POST that follows it — is what makes a removal fail: the button is live when
the finger goes down and inert when it comes up, and the browser then fires no
`click` at all. So removing a chip cancels the pending wait and asks
immediately, with the text exactly as it stands; a second removal a moment
later does the same, and the store keeps only the newest answer
(`stores/rules.js`). Removals are safe mid-parse because the server drops them
by chip id rather than by position, so the reading a removal is issued against
does not have to be the one that comes back.

**"Create rule" is the one thing that waits.** It is disabled while the
textarea differs from the text the reading on screen is of, or while a parse is
in flight — a rule saved against a sentence the owner has already moved past is
a rule they never described. Nothing else on the screen is gated on it.

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
the winter" finally has an answer beyond the Canaries. Two honest strains on
the 1–5 scale: a tropical **wet** season is rated 4 rather than 5 (the
thermometer says beach and the afternoon does not), and a Gulf summer is 5
because "beach" is the hottest thing this vocabulary can say — a ceiling, not
a recommendation.

**`world_destinations.php` is tier 2 of two, and the distinction is the whole feature.** Tier 1 (`world_airports.csv`, seeded by `WorldAirportSeeder`) is 3,270 airports with coordinates and a name and nothing else, since nobody has sat down and decided what Ouagadougou is *for*; this file is the places the rule engine may actually send somebody, matched against `vibes`/`warmth` and never against the raw airports table, because a rule that could fire on all 3,270 would be a rule fired on rows nobody ever looked at. **One airport per city, deliberately** — Tokyo is HND, not also NRT; New York is JFK, not also EWR and LGA — because both codes stay watchable through tier 1, but a curated list with two Tokyos in it matches every Tokyo rule twice and spends the sweep budget on the same city. Where this disagrees with the OurAirports snapshot, this wins, and `WorldAirportSeeder` is written so it does: some disagreements are editorial (a boarding-pass row has no room for "John F. Kennedy International Airport" or "Sydney (Mascot)"), one is a correction (Dakar is DSS, not the DKR the snapshot still marks served, because that airport closed in 2017). Four climate profiles are reused from `european_destinations.php` by name rather than redefined — `continental` (New York, Seoul), `nordic` (Calgary, Sapporo), `oceanic` (Vancouver, Seattle), `north-africa` (Cairo) — and the seeder refuses to start if a shared name means two different things in the two files.

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

**The count is a floor until every candidate has a price, published as
`matches.partial`.** Measured on the running app: the create screen said "2
trips match this right now" and the rule it saved reported 32 a minute later
— neither number was wrong (the second is what `SweepRuleFares` found once it
ran), but the first was stated as a total, so the app read as having been
mistaken about the thing it exists to answer, at the exact moment somebody was
deciding whether to save the rule. `partial` is that gap, published: true while
some candidate route in the rule's fan-out has never been priced, false once
every candidate has an answer — regardless of whether any of them matched. A
candidate that has been priced and does not match is not pending; pending is
about missing information, not missing matches.

### Implementation notes

`RuleCriteria::from()` must survive JSON an older version of the class wrote —
every field is validated and silently dropped if unreadable, because a rule
that throws on load is a screen that can't be opened at all. `RuleVocabulary`
is built once in `AppServiceProvider` and injected, since `App\Domain` calls no
`config()` itself — which is also why the regex parser is a unit test rather
than a feature test. `RuleMatches` runs four queries for any rule
(destinations, routes, fares, watchlist), each memoised per-instance and
per-user rather than shared, so two users in one request can never
cross-contaminate; it creates nothing itself — an unpriced route just has fewer
matches until `SweepRuleFares` runs. `AnthropicRuleTextParser` checks `isset()`,
not `?->`, on `stopDetails` — it's uninitialised, not null, when absent, which
the null-safe operator doesn't catch. Rule lookups are always scoped to the
authenticated user, never merely filtered by id.

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
while being right. Skyscanner survives as the second opinion, because a second
opinion is worth having — but it is a **button**, not a line of text: the two
hand-offs are a pair on one line, Skyscanner outlined on the left as the check
and Aviasales accented on the right at roughly six-tenths of the width, and the
width is the reader's basis for choosing. It shipped as a 12px centred text link
under the button, on the argument that "two buttons is a choice the reader has no
basis for making"; the owner used it and disagreed, which settles it — on a phone
that line did not read as pressable at all. `Components/calendar/DaySheet.vue`
draws the same pair, so the two screens agree. The full layout and copy reasoning
is in §36 ("Frontend — views and components").

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

**A deal-alert mail carries the primary hand-off alone, never the pair.** A mail
is the one place a reader cannot see two links and choose — so it links only
Aviasales, the search Orbit's fares actually came out of.

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
| **04:40 daily** | `orbit:poll-returns` | round trips, **one** request per watched route because one call covers the whole horizon — 9 today. In the 04:00 hour and not the 06:00 one, which is already at 183 of ~200 (§15). **04:40 and not 04:20** because Saturday's far poll is still queueing its staggered fan-out until 04:34, and two fan-outs interleaving is what the stagger exists to prevent |
| **05:20 daily** | `orbit:discover` | in the 20-minute gap between the returns poll's fan-out tail (ends ~05:04) and Monday's stats refresh (05:40) — sequential rather than overlapping in the otherwise-empty 05:00 hour. Safe to schedule daily, unlike a fan-out that could send mail: nothing in Discovery interrupts anybody (§16) |
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

**`--confirm` is required rather than Laravel's own `$this->confirm()`**,
because this command runs over `docker compose exec -T`, where stdin is not a
terminal and an interactive prompt would hang or silently proceed on EOF.
Deletes are by model class, not table name — a renamed table takes the command
with it rather than silently truncating nothing.

---

## 15. Return trips (foundation)

> **Status: groundwork, accumulating.** A port, two adapters, the `return_fares`
> table and a command to fill it — **now polled daily at 04:40**, so the history
> is building. **Nothing reads the table yet**: the screens, the statistics and
> the rule matching are later PRs in this milestone.

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

### The budget, and the hour it bought

**One request per watched route per run** — 9 today — because one call covers
the whole horizon, against 7 or 12 for the one-way calendar. At W routes it is W
requests, flat, so returns polling never becomes the binding constraint.

`orbit:poll-returns` runs **daily at 04:40** (§13). The 06:00 hour is at 183 of
~200 and 9 more would leave 8 requests of headroom, so the run goes in the
**04:00** hour beside the far poll instead: 108 + 9 = 117 on a Saturday, 9 on
every other morning. **04:40 rather than 04:20** is the per-minute limit rather
than the hourly one — the far poll is still queueing its nine staggered jobs
until 04:34, and starting inside that window would hand the provider two bursts
in the same minutes.

**It was scheduled before the table had a reader, which was not the plan.** The
foundation PR left `routes/console.php` untouched on the argument that morning
calls filling a table nothing draws are a standing cost for no benefit, and said
the PR adding the first reader would add the entry. In the event the poll was
run daily anyway, by a cron **outside this repository**, because a fortnight of
accumulated real fares is worth considerably more to the PR that draws them than
an empty table and a fake — and that history only accumulates in real time. The
calls were being spent either way; what the outside runner added was somewhere
for the accumulation to stop silently. Moving the clock into the deployed stack
cost nothing and removed that. `php artisan orbit:poll-returns --now` still
fills the table by hand.

### What later PRs add

- statistics and a deal score for return trips (the analogue of §6 and §7 — note
  that a "current price" for returns has to be *defined* before it can be
  computed, and this PR deliberately writes no observation row)
- the screens that read the table, which must be built for the sparsity above
- `tripLengthNights` finally **matching** rather than only being parsed and shown
  (§11 and `docs/API.md`) — the fact it filters on now exists
- alerts on round-trip fares, which have to reckon with the seven-day-deep cache

### Implementation notes

`NightsBand` is a type, not two nullable ints, so a reversed or negative pair
is caught once at construction; it counts nights, not days (an off-by-one
silently answers the neighbouring question). A duration-band query scope was
deliberately not written yet — writing `whereBetween('nights', ...)` before a
screen needs it would mean guessing the shape rather than letting the PR with a
screen decide. A thin route (23 entries over 14 dates) must not log a warning —
sparse is the normal state of a real round-trip route — and the returns adapter
keeps its own cache warning key so a failed calendar poll can't silently
swallow the returns warning for fifteen minutes. `FakeFareModel` is
deliberately shared by both fake adapters (`FakePriceProvider` and
`FakeStatsProvider`, and by extension `FakeReturnProvider`'s two legs) so
one-way and return numbers agree with each other rather than drawing from
different distributions; `FakeReturnProvider` is deliberately sparse (unlike
its dense one-way sibling), matching the measured 7.7–33.5% real coverage, and
always stamped fresh (`foundAt: now`) even though real returns are routinely
days old — the one thing this fake deliberately flatters, to keep the
freshness feature visible in every screenshot. The round-trip test suite is
fixture-only, recorded from the live API on three routes spanning the range
(AMS-LIS well-covered, AMS-JFK the long-haul this milestone exists for, EIN-BCN
genuinely thin); `Http::preventStrayRequests()` makes "no network" a rule, not
an intention. Neither `PriceProvider` nor `ReturnTripProvider` takes an `asOf`
parameter — no real API lets you ask about the past, so a test that needs an
earlier state moves the clock (`FakeHistorySeeder`) instead of asking the port
for one.

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
| **1. sweep + score** | 3 requests | ~1,177 fares from the three configured origins, ranked to a shortlist of 5 (absolute) plus 3 (relative). Arithmetic only. |
| **2a. own window** | 8 × ≤7 requests | Each finalist's own near window, through the existing `PriceProvider`. Is this remarkable *on its own route*? Every window fetched is also **remembered** as a baseline. |
| **2b. Google** | ≤5 searches | Does a company that is not Travelpayouts agree? **Shared across both lanes, absolute first.** Skipped entirely without a key or quota. |

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

### Two lanes, because there are two kinds of steal

| lane | the claim | how it is judged |
| --- | --- | --- |
| **absolute** | "€18 to Vilnius is a steal, *period*." | €/km against every fare in the sweep. |
| **relative** | "€60 to Dublin is a steal *for Dublin*." | Against what that route itself usually costs. |

The second lane exists because the first one **structurally cannot see short
hops**. AMS-DUB at €30 over 750 km scores 40.0 m€/km against a 30 m€/km floor —
at that floor Dublin would have to be €22. No fare a person would call cheap
clears a ratio rule on a short route, and "somewhere you would not have thought
to look" was never supposed to mean "somewhere far away".

**⚠ The free version of this does not work, and the measurements are why.** The
obvious design is a distance-band baseline: bucket the day's candidates by
distance, take each band's median, call a fare 40% under its band a relative
find. It costs nothing, and on the recorded 2026-08-16 sweep it fails three ways:

1. **A sweep is a floor, not a price list.** `/v2/prices/latest` returns *one*
   cheapest cached entry per destination — the maximum rows for any
   origin-destination pair in the recorded fixtures is **1**. A sweep therefore
   holds no distribution for any single route and can express no notion of what
   Dublin usually costs. The 500–1000 km band median is **€29**, where the retail
   intuition says €120: different populations, not different estimates.
2. **AMS-DUB scores −3.4% against it.** Dublin at €30 is the *median* fare for
   its distance. The example the lane was asked for fails the rule written to
   catch it. It "passes" only at 750 km buckets, where its band holds six rows
   and five are sub-400 km train hops (Maastricht €186/170 km, Eindhoven
   €138/104 km) — a band-boundary artifact, not a signal.
3. **Within a band, distance is ~constant**, so ranking by `1 − price/median` is
   ranking by price is ranking by €/km. The band lane's top qualifiers were
   Tangier, Marrakesh, Pescara, Vilnius and Tirana — the absolute lane's
   shortlist, exactly. Not a second kind of deal: the first kind, respelled, for
   three extra fetches a night.

### The flywheel: lane B gets smarter every day it runs

The honest baseline for "usual on this route" is the route's **own window
median** — the number `savings_cents` is already measured against, and the one
DUS-AGP's €78 came from. It costs a request. So the lane spends its budget
*learning* baselines and then reads them for free:

| | what it does | what it costs |
| --- | --- | --- |
| **known routes first** | a remembered median says this fare is ≥40% under its own usual → spend a fetch confirming it | this is the product |
| **exploration fills up** | leftover slots go to routes Orbit knows nothing about, in a deterministic daily rotation | the fetch answers "what does this usually cost", and **the answer is kept** |

Every window the job fetches becomes a `discovery_baselines` row — **including
the absolute lane's five**, which are just as unwatched and unpriced, so a run
learns up to **eight** routes rather than three. A route that surfaces nothing
still leaves behind what it costs; a lane that only learned from its successes
would learn almost nothing.

An explored route surfaces **only if it passes the same verification** an
absolute finalist does. Most will not, and that is correct rather than
disappointing — the fetch already bought the more valuable of the two answers.

**On day one this lane is all exploration and surfaces almost nothing.** That is
the honest shape of it, and it is written down so nobody tunes it away: the first
run has no baselines, and the first relative card cannot appear until a route
Orbit measured turns up cheap on a later morning.

Two guards keep a remembered number honest. A baseline over fewer than **10
priced days** is not a usual price (provider coverage on obscure pairs really
does come back that thin), and one measured more than **30 days** ago is a
yardstick from another season. Either failure means the route is treated as
**unknown** — so it goes back into exploration and gets re-measured, rather than
being disqualified forever.

**The baselines are discovery's own table, deliberately not `calendar_fares`.**
That one is keyed to `routes`, and minting route rows nightly would break three
things at once: §1's notion of "pairs this app knows about", the 201 from
`POST /api/routes/lookup` that "look before you watch" rests on, and — the
serious one — `RuleMatches` reads `calendar_fares` for any route a rule names,
and a rule match feeds §10, **which sends mail**. Discovery never interrupts
anybody, and a table it writes into silently becoming an alert input is exactly
the coupling that sentence forbids.

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

3 sweep + (5 + 3) × ≤7 verification = **59 Travelpayouts requests**, plus **≤5
SerpAPI searches** out of 250 a month. Scheduled into the empty **05:00** hour:
in the 06:00 hour it would be 242 of ~200 and well over the limit. The worst hour
of the week is unchanged by this feature, second lane included.

**The second lane added 21 requests and zero SerpAPI spend.** The Google
allowance is the same ≤5 a night it was before — the two lanes *share* it rather
than each having one, absolute taking priority, so a run that exhausts quota
degrades the new feature rather than the shipped one. And the 21 requests buy two
things: the fares the lane surfaces, and the baselines it remembers.

Full table in `config/orbit.php`'s `poll` section; the SerpAPI guardrails are in
its `serpapi` section and the lane's own numbers are in `discovery.lanes`.

### Implementation notes

`DiscoverDeals` ranks all three origins in one job rather than fanning out per
route; it's an idempotent upsert keyed on `(code, departure_date)`, `$tries = 1`,
with a generous 900s timeout since nothing is waiting on a 05:20 queue job. The
Google budget is checked once, up front, before any of it is spent. Baselines
are written before the verification gate runs, not after, so a candidate that
fails verification has still told Orbit what its route costs. The
cross-sectional gate applies unchanged to both lanes; an empty window
disqualifies a relative candidate (its whole claim rests on the fetched window)
but not an absolute one (whose claim rests on the sweep alone, which coverage
gaps don't undermine). The sweep runs through a fourth fare port,
`OriginSweepProvider`, the first with no destination in its signature — it
answers "surprise me," a question that can't be asked about a specific pair —
and the swept fares it returns are deliberately unverified and untouched:
sorting or filtering them would be making the verification stage's decisions
with less information. `confirmed`, `google_verdict` and `RelativePick`'s
reason are all stored, never recomputed, so a retuned rule can't rewrite what a
card already claimed; `lane` and `google_verdict` are both in the upsert's
update list so a route can lose its "absolute" badge or a verdict. Both
`store()` and `rememberBaseline()` convert to UTC before every upsert —
`upsert()` skips the model's casts, so an unconverted local Carbon is read back
as UTC and drifts two hours fresh. `DiscoveryController` is a pure,
parameterless read behind `auth:sanctum`, on the owner's clock. In the sandbox,
`FakeSweepProvider` reads the real `airports` table (capped at
`MAX_SWEEP_KM = 4000` since `FakeFareModel` is distance-blind) and
`SALE_IN_HUNDREDTHS` (22%) is tuned to fill the shortlist, not to match the real
funnel's ~4.9% pass rate.

The exploration rotation is **deterministic on purpose**:
`RelativeLaneSelector` orders the unknown pool by a `crc32` hash of the day
and the route code, never `rand()`. A shuffle would make the lane untestable
(a feature test could assert only that *something* was explored) and would
make two runs on the same morning disagree, which matters because the job is
idempotent by design and a hand-run of `orbit:discover` is meant to reproduce
the scheduled one. The **day** is in the seed so the rotation moves — hashing
the route alone would explore the same three routes every morning forever, a
flywheel turning three cogs — and it is the **owner's** day, already resolved
to `orbit.timezone` by `DiscoverDeals`, so a 05:20 Amsterdam run seeds on the
date the owner would call today. The route code breaks a hash collision, since
32 bits over a few dozen candidates is unlikely but not impossible and the
order still has to be total. **One destination appears only once across both
lanes and across origins** — Málaga turned up in both the DUS and EIN sweeps
on 2026-08-16, and spending an absolute slot *and* a relative slot on the same
city pays twice to name one place.

The relative lane's `maxFareCents` is **deliberately above the absolute lane's
€120**: a relative find is by construction *not* remarkable per kilometre —
that is what makes it this lane's — so a ceiling tuned for €/km outliers would
reject the whole population, and €120 leaves no room for mid-haul routes whose
usual is genuinely high. €150 is still a ceiling and is there on purpose,
because a percentage is scale-free: a €400 long-haul at half its €800 usual is
a real discount but a different product, a trip somebody plans around rather
than a fare they book on a Tuesday. Its `minSavingsCents` is the same €15 as
`DiscoveryPolicy`'s but is passed in separately rather than read off that
object, so the two can be retuned apart. Its `minDiscount = 0.40` sits below
both measured cases with margin (DUS-AGP at 62.8%, the owner's Dublin ask at
50%) and comfortably clear of the 20–30% an ordinary route's window spans
between its cheap Tuesdays and its median — the thing this must never fire on.
Three picks is the only number in the class that costs anything (≤21 requests)
and it adds no Google search at all: `serpapi.max_per_run` stays at five,
shared across both lanes, absolute first.

---

## 17. A cheap fare that may already be gone, and the way to check

**The fare that bought this section.** DUS→VCE, on the route detail at **€36** —
*"Seen 3 days ago"*, usual €62. Tapping through to Aviasales, the live direct was
about **$150** and there was nothing within sight of €36. Nothing had
miscalculated: Orbit's fares are Travelpayouts' cache of other people's searches
(§2), ultra-cheap fares die in hours, and the app had faithfully reprinted one
that was already gone.

That is the **third** time this app has written that sentence down — €36 against
a live €56, €29 against a Skyscanner €68, and now this. Each previous fix was to
**say more**: a freshness line, a second booking link, an age on the card. All of
it is small print under a number in 42-point type. So this one does two other
things.

### The demotion — two conditions, and it needs both

A fare is drawn **quieter, with a plain label**, when

* it was **found more than `live_check.stale_after_hours` ago** (48 h), **and**
* it is **at least `live_check.under_usual_percent` below usual** (20%).

*Age alone would demote half the app* — a four-day-old fare at its usual price is
the ordinary state of a quiet route and disappoints nobody. *Cheapness alone
would demote the feature* — a fare 40% under usual, found an hour ago, is exactly
what Orbit is for. It is the **combination** that is the trap: cheap enough to be
the reason somebody opened the screen, old enough to be the first kind of fare to
disappear.

The rule is the **server's**, published as `data.cheapest.mayBeGone`
(`docs/API.md`) and applied in `App\Application\Routes\RouteSnapshot`. The screen
styles on the flag and does not recompute it, so retuning the config retunes the
screen. **A null `found_at` is never demoted** — "we do not know how old this is"
is not evidence of staleness, the same reading `AlertPolicy` takes.

48 hours is `alerts.max_fare_age_days`' two days arrived at from the same fact
(the poll is daily, so one missed morning must not demote everything) and is
deliberately **longer** than the 24 hours at which the screen starts printing
"Seen …" at all: the line comes first and quietly, the demotion a day later.
**20%** is where a fare stops being ordinary variation and starts being the
point — at 10% under usual nobody clears a weekend, at 20% they look twice, and
the fare that started this was 42% below.

The judgement reads the **cheapest departure**, not `price.current`: they are the
same number by contract (`docs/API.md`) but only one of them carries a
`found_at`, and a demotion drawn from an age belongs to the fare that has the
age. The screen draws the pill, the callout and the number they sit over from
that same fare, so one claim is never made about two fares.

### The check — one button, one search, and four guardrails

`POST /api/routes/{code}/live-price` asks **Google Flights, live, via SerpAPI**
about the exact departure the screen is showing, and swaps the headline for what
it gets. Orbit's own figure stays on the page as context — *"Orbit's cached fare
€36, seen 3 days ago"* — because the disagreement is the answer.

It is the **only place in Orbit where a person's tap spends the SerpAPI budget**,
which is 250 searches a **month** on a free plan. So:

| guardrail | where |
| --- | --- |
| **Quota read before every spend**, from the free `account.json`, failing closed | `GoogleFlightsCheck::available()` |
| **Nothing spent at or below the 50-search reserve** — refused with a sentence | the same method, `orbit.serpapi.reserve` |
| **Cooldown**: the same route and date is served from the stored row for 6 h, free | `App\Application\Routes\LivePriceChecks` |
| **User-initiated only**: authenticated, CSRF, throttled 3/min and 10/h; nothing schedules it | `routes/web.php`, the `live-check` limiter |

⚠ **The reserve is the only enforced floor.** "Roughly fifty live checks a
month" (250 − 50 reserve − up to 5 a night for discovery) is arithmetic, not a
property of the system: nothing counts taps against a monthly figure, discovery
may spend less, and a busy month reaches the reserve early. The limiter is not
the rationing either — 10 an hour would clear the month by Tuesday; it is there
for a client stuck in a retry loop.

### The callout stops recommending a fare the page doubts

`advice` is generated with the score so the prose and the gauge cannot disagree.
Two states override it, **server-side**, in
`App\Http\Resources\RouteDetailResource`:

* `cheapest.mayBeGone` — "€36 is 42% under its usual €62 — a solid time to lock
  it in" is the single loudest wrong sentence this app can print, and it is
  printed under a fare the same document has just demoted. It becomes *"Cheap,
  but it may be gone"*, tone `warn`.
* a fresh live check whose `lowest` is at least
  `live_check.contradiction_percent` (**10%**) **above the cached fare** — the
  callout would otherwise still be reasoning about a number Google has just
  contradicted. It becomes *"Google cannot find this fare"*, tone `warn`. A
  *gap* and not a strict `>`, because €76.50 against €77 is rounding and "treat
  the cached fare as gone" is far too strong a sentence for it; inside the gap
  Google has corroborated the fare and the ordinary advice stands.

When the fare may be gone and **Google has already been asked and was silent**,
the callout says so instead of telling the reader to check a price they have just
paid to check.

`verdict` is left alone in both: the gauge is about the price level, the callout
is about whether to act on it. **The client renders the sentence and reads
`advice.tone` for the booking hand-off; it composes no qualification of its
own** — a claim assembled in a Vue component is one the server cannot be held to
and one a future alert would not repeat.

### Asked and silent, or never asked at all

Three outcomes, and only the first two cost money
(`App\Domain\Discovery\GoogleAnswer`):

| outcome | billed | row written | what the screen says |
| --- | --- | --- | --- |
| Google answered with `price_insights` | yes | yes | the live price takes the headline |
| Google answered without it (thin route) | yes | yes | "Google had no live price for this date" |
| SerpAPI timed out, refused, or answered something that is not a finished euro search | **no** | **no** | "could not reach Google — nothing was spent", and the button stays |

⚠ The third case is the one that was wrong: recording it would have served
"Google had no opinion" for six hours off a search that never happened, and
blocked the retry that would have worked. A body is only read as an answer when
`search_parameters.currency` is `EUR` **and** `search_metadata.status` is
`Success` — a price in dollars would be silently wrong in the reassuring
direction, and a search that did not finish is not a market.

### Two taps at once

Two requests can both pass the cooldown and the quota, both spend a search, and
then collide on `live_price_checks`' unique `(route_id, departure_date)`. The
loser catches the constraint violation, re-reads the winner's row and serves it:
both taps see one number, and **a paid answer is never returned as a 500**. The
loser's own answer is discarded rather than overwriting — both are equally fresh,
and one row per route and date is what every reader of this table expects.

⚠ **That catch requires there to be no open transaction around it.** Postgres
marks a transaction as aborted the moment a statement raises `23505`, so a
`LivePriceChecks::store()` called inside one would fail on the re-read instead of
recovering. The endpoint runs it outside any transaction and must go on doing
so; wrapping the write would need a savepoint (`DB::transaction()` nested), not a
plain `beginTransaction`.

`departure_date` is stored as a **bare `Y-m-d`** by a mutator on
`App\Models\LivePriceCheck` rather than by a date cast. The cast writes
`Y-m-d H:i:s`, which Postgres coerces into the `date` column and SQLite keeps
verbatim — so an exact-match lookup on the unique index finds the row on
production and not on the database the test suite runs on.

### Why a table rather than a cache entry

The answer cost a metered search, and `cache:clear` is a routine deploy step: it
must not be able to make somebody's phone spend a search it already spent. One
row per route and departure date, overwritten rather than logged — every reader
wants the most recent answer for that flight, and the departure date is in the
key because a check of the 12th says nothing about the 19th. The obvious next
reader is alert verification, which does not exist yet and is not pretended to.

---

## 18. Why `config/orbit.php` is a real file, not scattered `env()` calls

`php artisan config:cache` compiles every config file into one array; after that, `env()` returns `null` everywhere, because the `.env` is no longer loaded. A seeder or provider that reads `env()` directly therefore works in development and silently falls back to a default on a cached production deploy. Config files are the one place `env()` stays safe — which is exactly what Larastan's `noEnvCallsOutsideOfConfig` rule enforces.

`config/orbit.php` starts small — the one account — and is where the fare providers, the deal score's weights, and the alert thresholds land as they arrive.

## 19. The single account (`seed`)

Read by `Database\Seeders\SingleUserSeeder`, which runs on every deploy and is idempotent. A `null` password means "generate one and print it once"; supplying one is a deliberate rotation. An **empty** `.env` value is treated as `null`, because `SEED_USER_PASSWORD=` in a file is somebody not setting it rather than somebody asking for an empty password.

## 20. The clock the owner lives on (`timezone`)

Storage and `config('app.timezone')` stay UTC — the only sane thing to persist — but everything a **person** reads is local: "today's" fare observation, the day a calendar cell stands for, and the hour the scheduler polls at. Reading `orbit.timezone` in `routes/console.php` is what makes "06:10" mean 06:10 in Amsterdam in both halves of the year rather than 08:10 in July and 07:10 in January.

## 21. Fare providers — four switches (`providers`)

Three ports (`PriceProvider`, `PriceStatsProvider`, `ReturnTripProvider`), chosen by name in config and bound in `AppServiceProvider`. Each has two adapters: one-way prices are `fake` or `travelpayouts`, statistics are `fake` or `self`, round trips are `fake` or `travelpayouts`.

**One-way and round-trip are two switches, not one**, even though both real adapters talk to Travelpayouts. They read different endpoints with different coverage and different failure modes, and the round-trip half is the newer and thinner of the two — so a box must be able to run real one-way fares (which every score and alert depends on) while the return-trip table is still being filled by the fake, and vice versa. One key would have made turning returns on a change to the deal score.

**There is no third-party statistics adapter and there will not be one.** The plan was Amadeus' price-analysis endpoint; their Self-Service API was decommissioned on 2026-07-17 and nothing else sells the quartiles of a route's fares. `self` computes them out of Orbit's own two tables instead (§23) — which is why the deal score now runs end to end on data this app collected itself.

**`fake` is still the default, and that is a separate decision from the adapter existing.** It is not a test double: `docs/PLAN.md` ships the app before the provider keys exist, so the fake is what production actually runs until somebody flips this. It is deterministic per route, so the same route shows the same prices on every deploy and a feature test can assert real numbers.

**Flipping it is not only one line.** Every fare in the database was written by whichever adapter was in force at the time and no row records which — so a real price landing in a table full of simulated ones makes the 30-day trend, the "usually €120", and the next alert quietly wrong. `php artisan orbit:reset-history --confirm` is the other half of the switch.

**The origin sweep — a fourth switch rather than a flag on `price`** (`providers.sweep`), even though the real adapter is the same vendor and endpoint as `returns` (`/v2/prices/latest`, destination left off, `one_way` flipped). It is its own switch for the reason `returns` got one: the discovery funnel is the newest and least proven thing in the app, it is the only one that can spend a SerpAPI quota, and a box must be able to turn it off without touching the fares every score and alert depend on.

**It defaults to whatever `price` is, and that is the important half.** The other three keys default to `fake` independently, because each fills its own table — a fake return fare sits in `return_fares` next to nothing it can contradict. A sweep is different: its candidates are scored against the fares the price provider fetched, and its cards sit on a screen next to routes the price provider priced. A box running real fares with a fake sweep would put invented routes at invented prices on a strip headed "Orbit found these on its own," each verified against a real calendar it has nothing to do with — the exact failure this feature was built to prevent, arrived at by leaving one variable unset. So the sweep follows the fares by default and can still be pinned either way — `ORBIT_SWEEP_PROVIDER=fake` on a box with real prices is then a deliberate act rather than an omission. Flipping `ORBIT_PRICE_PROVIDER` also turns on ~38 provider requests a night at 05:20 (already in the §27 budget table).

## 22. Travelpayouts — the real fares (`travelpayouts`)

Read by `AppServiceProvider` when `providers.price` is `travelpayouts`, and by nothing else. The default stays `fake`, so none of this is consulted until somebody sets that variable on a box that has a token.

**The endpoint is `/v2/prices/month-matrix`**, one call per calendar month the poll window touches — seven for the standard 181 days, six on the few mornings a window that starts on the 1st closes inside the sixth month, and twelve for the weekly 334-day run. That per-month billing is why `poll.window_days`, `poll.horizon_days`, and `rules.sweep_horizon_days` are the odd numbers they are.

Measured against the live API on 2026-08-15, of three candidate endpoints:

- `/v2/prices/month-matrix` — **one-way** (every one of 433 recorded entries came back with an empty `return_date`), one entry per departure date, scoped to the month asked for. This is the one used.
- `/v1/prices/calendar` — round-trip despite `return_date` being omitted (AMS-LIS: €252-391 against month-matrix's €80-159 for the same days), and it ignores the month it is given, answering with scattered dates up to ten months out. Wrong shape and wrong number.
- `/v2/prices/latest` — the last 48 hours of finds across a period, not a price per departure date. It is what validated the token, not what fills a calendar.

**One-way is the right number** because `docs/PLAN.md`'s calendar cell is "what it costs to fly out on this day," and a round-trip fare pinned to a departure date is really a fare for a pair of dates with the second one hidden. The deal score, the alert threshold, and a rule's price are all one-way prices, and always have been.

**The token goes in a header, not the query string** (both work; verified) — a URL is the one part of an HTTP request that gets written to an access log, a proxy trace, and an exception report by default.

**One request, three adapters, and `found_at` in either notation.** The request this section describes and the four guards on its answer — unreachable, refused, a body that is not a JSON object, an envelope in the wrong currency — are written once, in `App\Infrastructure\Concerns\TravelpayoutsEnvelope`, and composed into the calendar, return-trip (§15) and origin-sweep (§16) adapters; each one supplies only its path, its query, and the four sentences it logs. That seam accepts a `found_at` **with or without the trailing `Z`** (`2026-08-14T13:51:45Z` and `2026-08-14T13:51:45` are the same UTC instant, and the two endpoints differ only in the notation they send). The calendar adapter used to accept the suffixed form alone, so a matrix answer arriving in the other notation would have quietly dropped the age off every fare on the calendar — and an unknown age is exactly what `alerts.max_fare_age_days` (§10) has to reason about.

**Timeouts are short and the retry is single.** Nobody is waiting on this — it's a queued job at 06:10 — but the poll is seven calls per watched route in a stagger, and a provider that has stopped answering should fail the morning rather than occupy a worker until Horizon's timeout kills it mid-upsert.

**No currency key.** Every price in this app is euro cents, from the migration to the alert mail, so the request asks for EUR in the adapter and refuses a response whose envelope says anything else. A configurable currency would be a promise the rest of the app does not keep.

**The affiliate marker** (`travelpayouts.marker`) is deliberately **not** sent by the data adapter — it identifies whose link a booking came from, and the fare API has no use for it. It spent its whole life unused until `App\Application\Routes\BookingLink` started appending it to the Aviasales hand-off (§32) — not for revenue reasons, but because Orbit quotes Aviasales' cached fares while sending people to Skyscanner, and the attribution came along with that fix. Unset is fine and is the default: the link works without it, only the credit is lost.

`connect_timeout` (5s) is strict — a host that won't complete a handshake in five seconds is down; `timeout` (15s) is generous because the answer is served from Travelpayouts' cache and is occasionally slow. `retries` is 1, half a second apart: the data is a cache read, so a second attempt costs almost nothing and covers the single dropped connection that would otherwise leave a month of the calendar empty for a day — a third retry would just be a slower way to find out the API is down. `warn_every_minutes` (15) throttles the "provider is failing" log line: one morning's poll is seven calls per watched route, so an outage is fifty-odd identical lines, times the rule sweep — a log that repeats itself is a log nobody greps.

## 23. Self-computed statistics — the blend (`selfstats`)

Read by `AppServiceProvider` when `providers.stats` is `self`. `App\Infrastructure\Pricing\SelfStatsProvider` is the adapter.

Two horizons, both real fares this app fetched:

- **Cross-sectional** — the fares for the ~182 departure dates in the near window (`cross_section_days`). Available from the first poll, which is what makes a deal score possible the day a route is added. Its median answers "what does a typical departure date on this route cost right now," over six months of departures since `poll.window_days` widened.
- **Longitudinal** — `route_price_history`, one row per morning, each the cheapest fare anywhere in that morning's window. It takes weeks to mean anything and is the better comparison once it does: the fare being scored *is* one of these rows (`RouteSnapshots` reads the latest observation as "the current price"), so a percentile against them compares today's best against every other day's best — like for like.

The blend is one line of arithmetic, deliberately:

```
w    = min(1, observations / maturity_observations)
knot = round((1 - w) * cross_sectional + w * longitudinal)
```

applied to each of the five knots (min, p25, median, p75, max) separately. A convex combination of two non-decreasing five-number summaries is non-decreasing, so the result cannot violate `PriceStats`' ordering invariant, and every intermediate value is a euro figure somebody can read rather than the output of a model.

`maturity_observations = 30` is a month of polling — the point at which the longitudinal view stands entirely on its own. Below it the two are mixed in proportion to how much history exists: at 15 days the route's usual price is half "what a typical departure costs" and half "what a typical morning's best fare was."

`history_days = 365` caps how far back the longitudinal pool reaches. A year is where "usual" stops being a fact about this route and starts being a fact about a market that has moved on — and it keeps the pool bounded at 365 rows per route.

`cross_section_days = 181` caps how far forward the other pool reaches, because `calendar_fares` now runs eleven months deep (`poll.horizon_days`) while "usual" must not. Months 7-11 out are sparse — the provider's cache thins with distance, so what survives is disproportionately the dates people actually search (Christmas, Easter, school holidays). Pooling them with the near six months does not widen the distribution evenly; it drags the upper knots up with peak-season fares, and every route quietly becomes a better deal than it is. It is also the honest comparison: the fare being scored is the cheapest in the near window (`PollRoutePrices` writes exactly one observation a morning from those 181 days), so the distribution scored against has to be drawn from the same 181 days. The number is written out rather than referenced against `poll.window_days` — they are different decisions that happen to agree (a fetch budget vs. a statistical claim) — and `tests/Feature/SelfStatsProviderTest` is the drift guard.

Neither horizon is ever invented: a route with no calendar fares and no history gets `null`, the port's real answer, and `DealScorer` renormalises its weights over what is left.

## 24. Where the owner flies from (`origins`)

The owner's home airports — whatever is within a sensible drive of them; three in this deployment's reference data. It used to be two things at once: the only origins a person could **type**, and the only origins a **rule** could fire from. On 2026-08-16 the first half went away and the second did not — the asymmetry is the decision.

It is no longer a validation list: `App\Http\Requests\RoutePairRequest` accepts any row in `airports` at both ends now, so `POST /api/watchlist` and `POST /api/routes/lookup` will price BCN-PMI for somebody already in Barcelona.

It is still the rule engine's origins, and that is a budget: a deal rule is a standing question Orbit answers every night (`RuleMatches`, `SweepRuleFares` walk `origins × destinations`, each cell a metered provider call — §11, "The cap is the point"). Three origins is 3 × 184; a fourth is another 184 polls a night nobody asked for by name. `RuleVocabulary` reads this too, and none of the three go through a `FormRequest` — widening the lookup request widened nothing here.

They are also the search screen's quick chips (presentation, not a rule): `Search.vue` writes them out hardcoded (AMS, EIN and DUS, matching config — a third copy of the fact) so the ordinary case is one tap, and the box beside them takes any of the 3,270.

The same three are flagged `is_origin` by `DestinationSeeder`, from `database/seeders/data/european_destinations.php`. Two lists of one fact is a drift waiting to happen, so `tests/Feature/SeedersTest` asserts they agree — the seeder's list is the one that carries the coordinates.

## 25. The deal score (`score`)

Read once by `AppServiceProvider` into `App\Domain\Pricing\ScoringPolicy`, because the scorer is pure PHP and calls no framework function, including `config()`.

`weights` is `docs/PLAN.md`'s locked split and is renormalised over whatever is computable — a route with no history yet is scored on the two components that don't need it rather than being punished 25 points for being new.

`trend_saturation_per_day` (0.005) is the fractional price change per day at which the trend component pins to 0 or 100 — half a percent a day, ~14% over the 30-day window, which is a trend a person would call "clearly falling" rather than noise.

`tiers` are the alert sensitivity levels §26 fires on. They live here rather than in the alert config because the tier is part of the score's meaning, and the API publishes it.

## 26. Alerts — the rule book (`alerts`)

The numbers below are `App\Domain\Alerts\AlertPolicy`'s whole rule book, read once by `AppServiceProvider` — the same arrangement `ScoringPolicy` has. `sensitivities` is the three positions of the segmented control on the alerts screen (`design/README.md` §6), stored as `user_settings.sensitivity`. Each level names a **tier** rather than a number — the number lives once, in `score.tiers`, and is the same one the API publishes as a route's `tier`, so "Relaxed" and the "insane" badge can never mean different scores. `blurb` is the sentence under the control, filled in by `SettingsController`, and lives here rather than in the Vue component for the same reason: the copy quotes a number this file owns.

**`min_tracking_days = 7`** — how many real daily observations a route needs before its deal score is allowed to interrupt somebody, and before the score is published as anything but "no opinion yet." This is the day-1 honesty rule with teeth: `ORBIT_STATS_PROVIDER=self` computes statistics from fares Orbit itself fetched, so on the first morning the "usual price" *is* today's price — the current fare is the minimum, median, and maximum of a distribution one observation wide. Every score component then agrees the fare is as cheap as it's ever been, and every route on the watchlist scores 100/insane/confident at `trackingDays: 1`. Left alone, 06:55 the next morning is eight "insane deal" mails about nothing — on the day the owner is most likely to decide this app cries wolf. Seven days is a week of mornings: enough for a spread to exist and for the trend to have a direction, short enough that a Monday-added route can still be alerted about before the next one. It is not a claim that seven observations make a good estimate (`selfstats.maturity_observations` is where that claim lives) — it's the smaller question: below it, Orbit says nothing rather than something it cannot support. Read by two pure values through `AppServiceProvider`: `AlertPolicy`, which answers `immature-data` instead of firing, and `ScoringPolicy`, which is what makes `confident: false` mean what `docs/API.md` says it means — one number, so a screen and a mail can't hold two different opinions about the same morning.

**`max_fare_age_days = 2` and `near_departure_weeks = 3`** — one rule, and neither does anything alone: an alert is held only when its fare was found more than 2 days ago **and** the flight leaves within 3 weeks. Fares come from Travelpayouts, a cache of other people's searches (§2), so `found_at` can be days behind `fetched_at`. The owner caught the consequence twice: €36 on a date whose live cheapest was €56, and €29 against a real €68 — in a mail that is Orbit waking somebody up about a flight not for sale, the single worst thing this app can do. Fares near departure move fast and in one direction (seats sell, cheap classes go), so a four-day-old quote for a flight three weeks out is often gone; far-out fares barely move for weeks, so the same four-day-old price for next April is still worth saying — holding it would silence the alerts most likely to be true, on exactly the routes somebody has time to act on. Two days, because the poll is daily: one missed morning must not make every fare unalertable, and by the third day a near-departure price is old enough that Orbit is guessing. Three weeks is where "book it this week" turns into "keep an eye on it" for a European short-haul. A **null `found_at` is treated as fresh** (see `AlertPolicy`) — it means "we do not know how old this is," the state of every row written before the column existed, and silencing alerts on not-knowing would have turned the whole alert system off the morning this shipped.

**`cooldown_hours = 24`** — how long one route stays quiet after Orbit has mentioned it, per kind of alert. A fare that sits at 95 for a week is one piece of news, and a person mailed about it seven times stops opening the mail — at which point the eighth, about a route they would have booked, goes unread too.

**`further_drop_percent = 5`** — what beats the cooldown: a price that has fallen a further 5% since the last alert is new information rather than a repeat ("€44, 53% below usual" yesterday, €38 today is the morning somebody actually books). Without this, the cooldown would turn the one thing worth interrupting for — a fare still falling — into a day of silence.

**`mail_deals = 6`** — how many trips one mail spells out. A rule matching thirty routes is a mail nobody scrolls to the end of, so the cheapest few are listed and the rest counted ("and 24 more"). Every one is still written to the ledger, because the cooldown's promise is that a mentioned route stays quiet — and the mail did mention them, in aggregate. It is the same handful the create screen's match banner shows (`rules.sample`), separate on purpose: one is what fits on a phone screen next to a textarea, this is what fits in an inbox.

**`digest_days = 7`** — what "this week" means in the Sunday digest's callout, the window it counts already-sent alerts over. Seven days rather than "since the last digest," so a digest that failed to send once doesn't produce a fortnight of deals the following week under a heading that says week.

## 27. Polling — eleven months, at two speeds (`poll`)

Two horizons answer two different questions:

- **`window_days` (181, ~6 months)** — the **near window**: what a poll fetches on an ordinary morning, and the definition of "the current price" — the cheapest fare in the next six months. It's the pool the daily observation is taken from (`PollRoutePrices`), the pool `selfstats` summarises, and therefore the number every deal score, alert threshold, and sparkline is built on.
- **`horizon_days` (334, ~11 months)** — how far the app **maintains a calendar**: the far edge of the heat map, the month the arrows stop at, and the line past which `PollRoutePrices` deletes cells as unmaintained. Refreshed **weekly** rather than daily.

Widening the near window makes every route look cheaper; widening the horizon does not move a single score — which is the whole reason they are two keys, and why the far months could be added at all.

**Eleven months because that's the booking edge.** Airlines load schedules roughly eleven months out, so a twelfth month reliably answers with nothing. The owner asked to see past the six ("extend it"): a summer holiday is booked in February, a Christmas flight in January, and neither was reachable from a six-month calendar.

**334, not 335** — the same arithmetic 181 is. Travelpayouts answers a calendar month at a time, so a window costs one request per month it touches and the cost steps up at a month boundary rather than a day. Brute-forced over every start date in a four-year span: 181 days never reaches more than 7 months (183 reaches an 8th); 334 days never reaches more than 12 months (335 reaches a 13th, on the 31st of a long month, ~5 mornings a year). 334 is the widest horizon that never pays for a month it does not need.

**`far_refresh_weekday = 6` (Saturday)** — `Schedule::weeklyOn()` reads 0 as Sunday, 6 as Saturday. `routes/console.php` runs `orbit:poll-fares --far` on that morning, polling the whole 334 days for every watched route; the daily 06:10 poll still fetches the near window every morning including that one. Weekly is what the far months are worth: a fare eleven months out moves on the timescale an airline reprices a fare bucket, not this morning's cache churn, and nothing downstream reads those cells except a person paging the calendar — daily would be 45 more requests every day of the year for the same number. Saturday, because the far months are what somebody browses at a weekend (holiday planning, not a commute) — refreshed going into the weekend. And it's an extra fetch, not a deeper one: the far run re-fetches the seven near months it shares with the daily poll, deliberately, because the alternative (fetching only a slice) would mean a morning's observation means something different depending on which run wrote it.

**The budget, which is the real constraint** — Travelpayouts allows ~200 requests an hour per IP; the whole table (thirteen watched routes, today's watchlist):

| when | what | cost |
| --- | --- | --- |
| 06:10 ordinary morning | the poll, 13 watched × ≤7 months | 91 |
| 06:40 ordinary morning | the sweep, 30 capped × ≤4 months (§34) | 120 |
| **total, 06:00 hour** | | **183 of ~200** |
| 04:10 far morning (weekly) | far poll, 13 watched × ≤12 months | 156 |
| 05:20 daily (discovery) | origin sweep 3×1 + lane A 5×≤7 + lane B 3×≤7 | 3 + 35 + 21 = **59 of ~200** |

Lane B's 21 requests buy two things: the fares it surfaces, and the baselines it remembers — a lane-B fetch that produces no card still writes a `discovery_baselines` row (§30). Discovery spends no SerpAPI beyond the ≤5 searches shared across both lanes, absolute taking priority. Monday's 05:40 `orbit:refresh-stats` shares the 05:00 hour and costs nothing here — `self` reads Orbit's own tables. The far morning's three runs (04:10, 05:20, 06:10+06:40) sit in three separate clock hours, none above 183 — the eleven months cost nothing in the worst hour of the week, which is why the far run is a separate schedule entry rather than a deeper Wednesday poll (9×12+120 = 228 would have been over the limit).

Where it breaks, so nobody has to rediscover it: at W watched routes the 06:00 hour costs 7W + 120 and the far hour costs 12W. The ordinary morning is the binding constraint and breaches ~200 at W = 12 (204), while the far run has room to W = 16 (192). The twelfth watched route is what needs a plan — a wider stagger or moving the sweep — not the far horizon.

Round trips are not in either sum: `orbit:poll-returns` is one request per watched route (§28) because `/v2/prices/latest` answers the whole horizon at once. It runs daily at 04:40, the 04:00 hour, before the far poll fills it too on Saturdays (108 → 117, still under 200); every other morning it's 9 of ~200.

`stagger_minutes = 3` spaces the per-route jobs so thirteen routes' worth of provider calls don't arrive as a burst — the real APIs are rate-limited per minute too. Thirteen routes at three minutes is a 36-minute fan-out, which is what keeps each morning inside one clock hour.

`stale_after_days = 3` / `far_stale_after_days = 17` — how long a calendar cell may go unrepriced before a successful poll deletes it. A future date that stops being quoted (the provider's cache is patchy) is otherwise upserted once and kept forever, because an upsert only writes the dates the provider named — nothing in the API marks a cell stale. The row would go on claiming to be today's price on the heat map, in the booking link, and in the fares a deal rule matches against, which is how this app would mail somebody about a flight that can't be booked. Three days: the poll is daily and the deletion one-way, so two consecutive failed mornings (or one missing date) don't lose a cell that would have come back. Seventeen days is the same sentence on the far tranche's own (weekly) clock — two missed far refreshes (7+7) plus the same three-day cushion — which is why `PollRoutePrices` runs the staleness delete as two passes.

## 28. Round trips — going and coming back (`returns`)

Read by `App\Jobs\PollReturnFares`, `TravelpayoutsReturnProvider`, and `FakeReturnProvider`. `return_fares` is the table; `ReturnTripProvider` is the port.

Every price in this app is a one-way fare — right for the EU budget carriers Orbit was built around, wrong for anything long-haul (nobody flies to New York one way). Measured on 2026-08-16, cheapest one-way vs. cheapest round-trip on the same route: AMS-LIS €80 vs €134 (60%), AMS-JFK €334 vs €484 (69%), AMS-BKK €272 vs €472 (58%) — a long-haul one-way is roughly two thirds of a return, not half, so "AMS-JFK from €334" was never a lie about the arithmetic, only about the trip.

**Nothing reads this table yet, but it is polled daily at 04:40.** The foundation PR of the return-trip milestone shipped a port, two adapters, a table, and `orbit:poll-returns` to fill it by hand, with `routes/console.php` deliberately untouched until a screen reads the table. The schedule entry arrived first anyway: the poll was already being run every morning by a cron outside this repository, and a fortnight of accumulated real fares is worth more to the PR that draws them than an empty table.

**The budget is the cheapest thing in this file.** `/v2/prices/latest` with `period_type=year` answers the whole horizon in one request (recorded AMS-LIS ran from the call date to 2027-06-18), where the one-way calendar is billed per calendar month. So: one request per watched route per run — 9 today, W in general, flat. Returns polling never becomes the binding constraint (see §27). Worked out before the schedule entry existed: the 06:00 hour would go to 192 of ~200 (too tight); the 04:00 far-poll hour has room, going to 117 on a Saturday and 9 on every other morning — hence 04:40 daily. There's still no key for it here, because a schedule belongs in `routes/console.php`, where "the returns poll runs at 04:40" is one readable line — and 04:40 not 04:20, because Saturday's far poll is still queueing its staggered fan-out until 04:34.

`window_days = 334` is `poll.horizon_days`'s number, written out rather than referenced — the same arrangement `selfstats.cross_section_days` has, for the same reason (different decisions that happen to agree), and `tests/Feature/ReturnFaresPollTest` is the drift guard. It's a retention bound, not a request parameter (unique in this file): the provider answers for a year whatever it's asked, so this decides what's **kept** — the adapter drops the spill past it and `PollReturnFares` deletes anything that gets past that.

`stale_after_days = 3` is one number where the one-way calendar needs two, because this table is fetched whole in a single request — every row is always exactly as fresh as every other. Same sentence as its one-way twin (two missed runs plus a day).

`max_nights = 60` is a sanity ceiling, not a product decision — the longest real stay recorded was 56 nights (AMS-BKK), 60 leaves headroom without letting a corrupt row claim a nine-month trip.

`limit = 1000` is sent on every request, and its absence is a silent 91% data loss: the API's default is 30 records — AMS-BKK returned 338 entries with `limit=1000` and exactly 30 without it, with no error and no truncation marker. 1000 is the documented maximum and no measured route came close, which is also why the adapter doesn't paginate.

**Duration bands** (`durations`) are `[min, max]` nights, both ends inclusive — how a person reads "6 to 8 nights," and how `RuleCriteria::$tripLengthNights` has been documented since the rules engine shipped. They're fitted to measured stay-length histograms from 2026-08-16 rather than chosen for tidiness (AMS-LIS mass at 2-7, peak 4; EIN-BCN mass at 0-5; AMS-JFK mass at 6-8 and 14; AMS-BKK mass at 14-28, peak 14, tail to 56): `[2,3]` a long weekend (the only band with a name in the app — the regex parser turns "weekend" into exactly this pair); `[6,8]` a week, the one band with real mass on every route measured; `[13,15]` a fortnight, where AMS-JFK's second peak sits and AMS-BKK's mass begins; `[21,28]` three to four weeks, added for long-haul alone — AMS-BKK carries more entries between 21 and 28 nights (72) than AMS-LIS carries in total. Adding a band is cheap and costs no requests (`trip_duration` on the API is silently ignored, verified byte-identical with and without it, so the adapter fetches everything and filters locally) — what it costs is fake data: `FakeReturnProvider` prices only the lengths the bands cover.

## 29. Looking a route up before watching it (`lookup`)

`POST /api/routes/lookup` prices a pair the owner hasn't committed to yet: it finds-or-creates the route and, when Orbit has no recent fares for it, asks the provider right there — inside the request, while somebody waits — rather than queueing a poll they'd have to come back for. This is the one path in the app where a tap costs provider calls directly, which is what `fresh_for_hours` exists to bound.

`fresh_for_hours = 24` is the whole freshness rule, deliberately doing two things: (1) a route is **fresh** when it has a calendar fare fetched inside this window (`FareFreshness`) — fresh routes are served from the database and cost nothing; (2) having **asked** the provider is remembered for the same window, keyed on the route code — otherwise a pair Travelpayouts has no fares for (an empty answer is a real answer) would be re-fetched on every view, since rule 1 would say "stale" forever. Twenty-four hours because the poll is daily: a watched route's fares are at most a morning old, so the same number makes a looked-up route worth as much as a watched one, and any shorter would mean a route looked up twice in an evening gets fetched twice for figures that cannot have moved.

What one miss costs, and why the endpoint is throttled at all (`route-lookup` in `AppServiceProvider`): a fetch is the same full `poll.window_days` window a watched route gets, billed one request per calendar month it touches — six or seven provider calls, out of the ~200/hour the token allows. The limiter's hourly ceiling is set from that multiplication.

The near window, deliberately not the eleven-month horizon, for two reasons: (1) somebody is waiting — the fetch is synchronous and sequential, so twelve months is twelve round trips instead of seven, against a 15s read timeout with one retry; (2) the arithmetic above would halve — twelve calls a miss is 240 in the hourly ceiling rather than 140, so the limiter would have to drop to ten an hour and a long evening of browsing would start being refused. What that costs: a looked-up-but-not-watched route has no fares in months 7-11; the lookup screens don't draw a calendar, so nothing shows the gap, and the first `--far` run after it's watched fills it in.

## 30. Discovery — the funnel's numbers (`discovery`)

Read once by `AppServiceProvider` into `DiscoveryPolicy`. `orbit:discover` is the command, `DiscoverDeals` is the work, `discoveries` is the table, and §16 is the rulebook. The problem this solves is not "find cheap fares," it's "surprise me" — the watchlist answers what the owner already thought of, a rule answers a sentence they wrote down, this answers neither.

**Every default here was read off one real measurement**: an origin sweep of the three configured origins against the live API on 2026-08-16 — AMS 562 entries/destinations (€30-€7,230), DUS 419 (€16-€1,545), EIN 196 (€16-€1,495); 1,177 rows, of which 1,086 named a destination Orbit holds coordinates for (the other 45 are metropolitan codes, dropped — see `OriginSweepProvider`). Ranked by €/km with the cheap rules applied, the top of that day's answer was DUS-RAK Marrakesh €27 (10.8 m€/km), DUS-TNG Tangier €23 (11.5), EIN-VNO Vilnius €18 (13.1), EIN-TIA Tirana €21 (13.5), DUS-PSR Pescara €16 (14.1), DUS-AGP Málaga €29 (15.6) — the feature working. Ordered by price alone it would have been Brussels/Cologne/Maastricht every day; ordered by €/km with no price ceiling it would have been Singapore/Manila/Bangkok, genuinely remarkable fares that aren't what this screen promises. Both a ceiling and a ratio floor are needed.

**The budget is why the funnel has two stages**: the sweep (3 origins × 1 request = 3) is absurdly cheap; verification is not (lane A 5 finalists × ≤7 months = 35, lane B 3 × ≤7 = 21 — 59 Travelpayouts total, ≤5 SerpAPI shared across both lanes). Scheduled at 05:20, a clock hour nothing else uses (full table in §27). Anything that moved work from verification into the sweep would be making claims on the sweep's word — see `DiscoverDeals` for the two occasions this app has already done that and why it must not happen again.

Individual thresholds:

- **`min_kilometres = 400`** — how far is far enough to be a trip. 59 of 1,086 scored candidates were under 500 km, 31 under 300 (Brussels, Cologne, Maastricht, Eindhoven — trains). Not a ratio guard (a short hop already ranks badly per km); it's what a discovery *is*.
- **`max_price_eur = 120`** — the ceiling, and the reason €/km isn't the whole rule. €287 to Singapore scores 27.3 m€/km, a real bargain, and is not this feature — the promise is a fare seen on a Tuesday and booked on the Tuesday. 206 of 524 fresh, far-enough candidates were under €120 on 2026-08-16.
- **`max_eur_per_km = 0.030`** — the ranking floor, in euros per km (30 millieuros/km). 53 of 1,086 candidates cleared it while also being under €120, far enough, and fresh enough. It's a floor, not the sort: the list is ordered by this number and cut at `shortlist` — the floor stops a week with no deals in it from promoting the least mediocre thing available.
- **`max_found_age_days = 3`** — how old a swept price may be. A sweep is seven days of other people's searches piled up (394/1,086 rows under 2 days old, 542 under 3, 1,082 under 7). One day more generous than `alerts.max_fare_age_days`: that number governs waking somebody up, this one governs a card that *prints the age beside the price* — Orbit may show something slightly stale as long as it says so; it may not mail about it, which is why v1 of discovery doesn't alert at all (§16). A fare that won't say how old it is counts as too old — the opposite of what `AlertPolicy` does with the same fact (see `DealCandidate::ageInDays()`).
- **`shortlist = 5`** — how many candidates are verified, the only number in this section that costs anything: each finalist is 6-7 Travelpayouts requests plus at most one SerpAPI search.
- **`max_percentile = 10`** — how deep in its own window a finalist must sit. DUS-AGP's €29 was cheaper than all 23 fares in its October window (0th percentile, month median €78) — what "insanely cheap" is supposed to mean, a claim nothing before this stage could support. Ten, to catch the candidate that looked good against the world and is ordinary on its own route.
- **`min_absolute_savings_eur = 15`** — the smallest gap worth the word "find," against the finalist's own window median (DUS-AGP: €29 vs €78 is €49 of daylight). Guards the thin route: a window that runs €38-€44 can put a fare in its own bottom tenth while saving nobody anything — both rules must pass.
- **`expires_after_hours = 36`** — a discovery is deliberately not history (see the `discoveries` migration). The run is daily, so 36 hours is "until tomorrow's run replaces it, plus half a day of slack": a failed run leaves yesterday's set standing until the afternoon, and a set two runs old is always gone.
- **`max_rows = 12`** — the ceiling on the whole table. At five rows a run and a 36-hour life, at most ten are ever alive at once; `DiscoverDeals` prunes to this on every run, in the screen's own order.
- **`verify_window_days = 181`** — the near window, written out for the same drift-guard reason as `selfstats.cross_section_days` and `returns.window_days`; `tests/Feature/DiscoveryRunTest` asserts all three agree.

**The second lane** (`discovery.lanes.relative`) — "cheap *for this route*" rather than "cheap, period." Everything above ranks a fare against every other fare in the sweep, by what a kilometre buys; that's one kind of deal. AMS-DUB at €30 over 750 km scores 40.0 m€/km and is rejected by the 30 m€/km floor at any price a person would call cheap — a short hop cannot win a ratio argument.

**The cheap version of a second lane doesn't work**, and the 2026-08-16 measurements are why it's built the expensive way instead of as a free distance-band baseline (bucket candidates by distance, take each band's median, call 40%-under-band a relative find): (1) a sweep is a floor, not a price list — `/v2/prices/latest` returns one cheapest cached entry per destination, so there's no distribution for any single route in a sweep to compare against (the 500-1000km band median was €29 where retail intuition says €120 — different populations, not different estimates); (2) AMS-DUB scores −3.4% against a band baseline, since €30 *is* the median fare for its distance — the example the lane was asked for fails the rule written to catch it; (3) within a band, distance is ~constant, so ranking by `1 − price/median` is ranking by price is ranking by €/km — the band lane's top qualifiers were Tangier, Marrakesh, Pescara, Vilnius, Tirana: the absolute lane's shortlist, respelled, for three extra fetches a night.

The honest baseline is the route's own window median — what `savings_cents` is already measured against. So this lane spends its budget **learning** baselines and then reads them for free: known routes first (a remembered median says the fare is rare → spend a fetch confirming it), exploration fills the leftover slots (routes Orbit knows nothing about, deterministic daily rotation, the fetch answers "what does this usually cost" and the answer is kept). An explored route surfaces only if it passes the same verification an absolute finalist does; most won't, but the fetch has still paid for itself by leaving a baseline behind. On day one the lane is all exploration and surfaces almost nothing — the honest shape of it, written down so nobody tunes it away; it gets smarter every day it runs. Baselines live in `discovery_baselines`, deliberately not `calendar_fares` (which is keyed to `routes` — minting route rows nightly would break the watchlist's notion of a known pair, and would feed the rule engine, which sends mail; §16 says discovery never interrupts anybody).

Relative-lane keys: `max_price_eur = 150` is above the absolute lane's €120 on purpose — a relative find is by construction not remarkable per km, so an €/km-tuned ceiling would reject the whole population, but it's still a ceiling (a €400 long-haul at half its €800 usual is a real discount on a different product — a trip somebody plans, not a Tuesday-see-Tuesday-book fare). `min_discount = 0.40` sits below both real cases with margin (DUS-AGP measured 62.8%, the owner's Dublin ask was 50%) and stays clear of an ordinary good day — a route's window routinely spans 20-30% between its cheap Tuesdays and its median. `min_baseline_days = 10` is how many priced dates a baseline needs before it may be believed — a median over four dates is four numbers, and ten is where a single peak-season fare stops moving the answer enough to change a verdict; a thin baseline is treated as **absent**, not bad — the route drops back into exploration and gets re-measured. `max_baseline_age_days = 30` is how long a baseline stays admissible — routes reprice when a carrier arrives or a season turns; every verified finalist re-measures its own baseline on the spot, so an active route never ages out, and shorter would send the rotation back to routes it already knows, spinning the flywheel without ever widening. `shortlist = 3` is the only number here that costs anything (≤21 Travelpayouts requests, taking the 05:20 run to ≤59 of ~200) and adds no Google searches — `serpapi.max_per_run` stays shared, absolute first.

## 31. SerpAPI — asking Google whether we're telling the truth (`serpapi`)

Read by `AppServiceProvider` into `App\Infrastructure\Verify\GoogleFlightsCheck`, and nothing else. `key` defaults to `null` and the whole feature degrades to "skip the check" without one.

Every other number in the discovery funnel descends from Travelpayouts — the sweep, the window it's scored against, the calendar behind that. A cache that's wrong is therefore a funnel confidently wrong all the way to a badge saying "verified." Google is the only thing in this app that can disagree, which is what makes five searches a night worth spending.

**What the check actually asks, and why it isn't the obvious question.** The obvious rule — "our candidate is below Google's typical price range, so Google agrees it's cheap" — confirms everything. Three real finalists put to Google on 2026-08-16: DUS-AGP Travelpayouts €29 vs Google's own cheapest €70 (typical range €55-175); DUS-RAK €27 vs €168 (€100-200); EIN-VNO €18 vs €30 (€20-245). All three are under their typical-range low and would have been stamped "verified low by Google" — while Google, same airports, same date, could not find a seat at anything like the price (Marrakesh €27 against a real €168 market is a six-fold discrepancy). The badge would have been Orbit putting Google's name on its own stale cache entry. So the verdict reads **Google's market**, not our number: `price_level` is "low," or Google's own `lowest_price` is at or under its typical-range low. On those three finalists that confirms nothing — the correct answer — and they're shown as "great find," unverified, with the age printed next to them. `GoogleVerdict` carries the full argument.

**The guardrails are not negotiable.** The key is a free plan: 250 searches a month (measured 2026-08-16 — 249 left, 250/hour rate limit), a budget that can't be reasoned about per clock hour the way Travelpayouts' can. So: (1) no key, no calls, and no key is the default; (2) the quota is read before any search, from `account.json`, which SerpAPI doesn't bill for; (3) below `reserve` remaining, nothing is spent at all; (4) at most `max_per_run` searches in one run, whatever the quota says. A skipped check is not an error — every one of those paths, plus a timeout, a 429, and a route Google has no opinion about, leaves the candidate with no verdict, shown honestly as an unverified "great find." What must never happen is a badge without a check behind it.

`key` is `null` by default and the feature is *built* for its absence — unlike `TRAVELPAYOUTS_TOKEN`, whose adapter refuses to resolve without it (a box configured for real fares and given none is a deploy mistake), a missing SerpAPI key is an ordinary, supported state: the discovery screen works, it just can't say "verified."

`reserve = 50`, out of 250 a month, reserved for something that doesn't exist yet: discovery is a screen the owner chooses to open, and the obvious next use for a second opinion is verifying an alert **before** it's sent — a feature that can wake somebody at 06:55 — so a nightly job that ate the month's last search would silently take that option away. The less important feature yields.

`max_per_run = 5` is the per-run cap, deliberately the same number as `discovery.shortlist` but a separate decision — that one is how many candidates are worth verifying, this one is how much of a monthly allowance one night may spend. A bug that turned verification into a sweep would clear the month in one night; this stands in front of it. At five a night against 250 a month: a 30-day month is 150 searches, leaving 100 above the 50 reserve.

`connect_timeout = 5`, `timeout = 20` (seconds) — shorter than the Travelpayouts read timeout because nothing depends on this answer: a check that times out is a check that was skipped, and the run carries on with one fewer badge.

**What is left of the month is printed on the alerts screen**, in the "This app" card, from the same free `account.json` probe — `GET/PUT /api/settings` carries it as `meta.googleChecks`, cached for **ten minutes and failures included**, so a slow SerpAPI delays one settings load in ten minutes rather than every one, and a probe that fails leaves the row honest ("Unknown right now") instead of failing the request.

`settings_timeout = 3` (seconds) is that probe's deadline **when a screen is waiting**, against the 20 that `available()` keeps for the nightly run. Same request, same free endpoint, different question: a background job may wait twenty seconds for a better answer, and a person opening a settings screen may not be made to. Three seconds is long enough for a healthy SerpAPI (the recorded probe answers well inside one) and short enough that a sick one is a barely-noticed pause rather than a screen that looks broken. `available()` is deliberately untouched by it — failing closed there is about *money*, and a quota read that gives up early would spend nothing at all.

## 32. Booking — deep links, not an API (`booking`)

There is no booking API and there will not be one — `docs/PLAN.md` settles on deep links into somebody else's search.

**Two of them now, and Aviasales is first.** Orbit showed DUS→AGP at €29 on a date whose cheapest on Skyscanner was €68 — the arithmetic was fine, but fares come from Travelpayouts (Aviasales' cache), and the app then handed the reader to Skyscanner: a different meta-search, a different set of agencies, no reason at all to be holding the fare Orbit quoted. Quoting one shop's price and pointing at another's till is a way to look wrong while being right. The primary hand-off is now the site the price came from; Skyscanner stays as the quiet second opinion.

The Aviasales params shape — `{ORIGIN}{DDMM}{DEST}{passengers}`, upper case, day before month — is Travelpayouts' documented format, verified against the live site rather than remembered. `BookingLink` carries the evidence and the traps.

Only the hosts live in config. The path shapes are `BookingLink`'s, because they're a format rather than a setting — an `.env` that could half-change one of them is a way to ship a link that 404s. `aviasales_base` carries no path, deliberately: Aviasales has two entry points off the same host (`/search/PARAMS` for a dated search, `/?params=` for the pre-filled form a route with no fares yet lands on), and `BookingLink` picks between them.

## 33. Reading a rule written in English — the NLP config (`nlp`)

`design/README.md` §4 is a textarea, not a form: the owner types "cheap weekend somewhere sunny in spring, leaving Friday from any NL airport, under €80" and the app turns it into removable chips. Two adapters answer `RuleTextParser`, chosen by name exactly like the fare providers.

`parser` defaults to the one that works without a key: `docs/PLAN.md`'s pending-owner-actions list still has "dedicated Anthropic API key" on it, so the regex adapter is what production runs today, written to be good enough to ship rather than as a stub. The moment a key lands in `.env` the anthropic adapter takes over with no other change — and it composes the regex one as its fallback, so a refusal or truncated answer is a slightly dumber parse rather than a 500 on the create screen. `ORBIT_NLP_PARSER` overrides both, so a box with a key can still be pinned to the deterministic parser for a demo or a bisect.

`model` defaults to Haiku, not by accident: the job is one short sentence in and one small JSON document out, the schema does the structural work, and the whole thing has to answer inside a 500ms debounce while somebody is still typing. A larger model would buy nothing the schema doesn't already guarantee and would spend the latency budget the screen is built around.

`max_tokens = 1024` is generous for the handful of fields asked for, and it's a ceiling, not a target — the adapter treats a max-tokens stop as a failure and falls back, because a truncated JSON document isn't a small problem, it's unparseable.

`connect_timeout`, `timeout`, `max_retries` are the PSR-18 client's, not the SDK's — the SDK's own `timeout` option is advisory (its source never reads it), so only the transporter handed to it (see `AppServiceProvider`) will actually stop a hung request; a parse that hangs is a create screen that never says anything. One retry, from the SDK, covers 429/5xx/connection errors — the caller is a person typing, and a second attempt is the most a keystroke can wait for.

`origin_aliases` is what a person calls the three airports — the codes come from `origins` (§24) and aren't repeated here; these are the words somebody types instead of the code, a different fact. `tests/Feature/SeedersTest` asserts every value is one of `origins` and that city names agree with the seeder's — the same drift guard the origins list carries. Phrases like "any nl airport" are handled by the parser as "all of them" rather than an alias, because they name the set.

`vibe_words` is the nine-word vibe vocabulary. The keys are exactly the vocabulary in `database/seeders/data/european_destinations.php` (that file's header explains why the set is closed; `SeedersTest` asserts the keys agree). The values are the open half — what somebody might actually type. Adding a synonym is safe; adding a **key** is not, since no destination carries it and the rule would match nothing. Longest phrases first within a vibe, so "city break" isn't eaten by "city" — `RegexRuleTextParser` relies on the ordering.

`vibe_labels` is the chip drawn for each vibe (`design/README.md` §4 shows "☀ Sunny"). It lives here rather than in the Vue component for the same reason the sensitivity blurbs do: the label names a vocabulary this file owns.

## 34. Matching a rule against the world (`rules`)

`warm_at = 4` is the `destinations.warmth` rating (1 "pack a coat" to 5 "beach") a place must reach to count as somewhere a `warm_vibes` rule would send you. Checked against the **best** month in the rule's date window rather than every month, because a person flies on one date: "somewhere sunny in spring" is satisfied by a place warm by May, and demanding March be warm too would leave the Canaries and nothing else. The gate only runs when the rule asks for one of `warm_vibes` **and** names a window — a rule that just says "somewhere sunny" is already answered by the `sunny` tag; a climate check with no window would invent a season the person didn't ask about.

`sweep_cap = 30` is how many origin×destination pairs one rule may queue. A rule with no vibe at all is 3 origins × every curated destination (184 today, and only ever growing as `database/seeders/data/` is edited) — a rate limit spent on a sentence somebody typed and may delete a minute later. The cap keeps the best-fitting thirty and logs the rest — see `SweepRuleFares` for what "best" means.

`sweep_horizon_days = 89` is how far ahead those speculative polls look, deliberately shorter than `poll.window_days`. The budget: the daily poll is ≤63 requests, one rule sweep is 30 capped routes × ≤4 months = ≤120, and 06:10/06:40 land in the same clock hour (≤183 total; full table in §27). The weekly far run is not in that sum on purpose — scheduled into the 04:00 hour precisely so it never shares an hour with the sweep. Sweeping six months deep would be 30×7 = 210 requests for one rule, over the hourly limit on its own. 89, not 90, for the reason `poll.window_days` is 181 not 183 — a 90-day window reaches a fifth calendar month on three mornings a year, 89 never does. It is otherwise exactly the horizon every poll in this app had before the window widened: the sweep still costs what it always cost. A rule whose date window names a month beyond 89 days still **matches** on any route Orbit already holds fares for (`RuleMatches` reads the calendar, not the provider, and a watched route's calendar runs eleven months deep) — what it doesn't get is speculative fares for that month on routes nobody watches, which fill in as the calendar rolls toward it. The sweep did not move when the horizon did — 30×12 = 360 requests for one rule is not close to affordable, and a rule is still a guess where the watchlist is what somebody asked to be told about.

`sample = 6` — the design's match banner shows a handful, not a list (§4).

## 35. The installed app (`pwa`)

What "Add to Home Screen" reads, and the browser chrome colour the shell declares. Both live here because the manifest is generated by `ManifestController` and the meta tag is written by `resources/views/app.blade.php` — a colour written twice is a colour that eventually disagrees with itself, the status bar one shade off the app behind it, months later.

The colours are `design/README.md`'s dark `--bg`. Dark is the default theme (`<html data-theme="dark">`), so it's also what an install and cold launch should look like. A user who has chosen light gets their theme the moment the bundle boots and rewrites the meta tag; the manifest cannot follow that and should not try — a `background_color` that flickers per user is worse than one that matches the icon beside it.

No `env()` here, deliberately: none of this varies by environment — staging and production are the same app with the same name and icon, and an installed PWA that renames itself between deploys is one the OS treats as a different app.

### Implementation notes

The precache list is generated at request time from the live Vite manifest,
not a static file, so the worker and the page can never disagree about what a
build actually produced; `globe.gl` and lazy-loaded views are excluded
(reached through dynamic, not static, imports) since precaching 1.9MB of globe
on first install would spend mobile data nobody asked to spend yet, and fonts
precache in woff2 only. `service-worker.js` is served verbatim by
`ServiceWorkerController`, not bundled by Vite — a content-hashed filename
could never be updated, since the browser looks for the same URL it
registered — and it's public (no auth), since the login screen registers it
too and a redirect-to-login served as JavaScript would install a login page as
a worker. Install uses `Promise.allSettled`, not `.all`, so one 404 in the
precache list can't abort the whole install; `cache: 'reload'` bypasses the
HTTP cache during install; activate uses `skipWaiting` + `clients.claim()`
rather than waiting for tabs to close, since a home-screen app's tab can stay
open for weeks. Writes are never intercepted — there's no offline queue, and a
replayed PATCH would be worse than a failed one. `pwa.js`'s core assertion is
negative: nothing reloads unless the button was pressed — `controllerchange`
fires on its own once a new worker claims the page, and an auto-reload on it
would throw someone off the screen they were reading, minutes after a deploy
they had no part in. A fresh checkout with no build, or an unparseable
manifest, serves the static shell rather than erroring.

**The three PWA routes are registered with no middleware group at all, and
that is what keeps them session-free.** `bootstrap/app.php` loads
`routes/pwa.php` from the `then:` callback, outside both the `web` and `api`
groups; global middleware (`TrustProxies`, `TrustHosts`) still applies,
because that is framework-wide rather than group-scoped.
`SESSION_DRIVER=database`, and a browser revalidates `/sw.js` on **every
navigation** — inside the `web` group each of those revalidations would start
a session, write a `sessions` row for a visitor who is not one, and answer
with a `Set-Cookie`. A response carrying a `Set-Cookie` is one Cloudflare will
not hold, so the manifest and the offline page would stop being edge-cacheable
as well. None of the three reads a session, a CSRF token or a user, so none of
them needs the group, and none of them exposes anything: an app name, two
colours, a list of build filenames the HTML already links to, and a page of
static prose. `tests/Feature/PwaShellTest` asserts they still carry no cookie,
because that is exactly the kind of thing a later `->middleware('web')` undoes
silently.

---

## 36. Notes carried over from the comment-slimdown pass

The comment-slimming pass across `app/`, `resources/js/`, `tests/`, `e2e/` and
`database/migrations/` moved several hundred long in-code rationale comments
out of the source and into this file. Most were folded into §1–§35 — either
merged into a section that already covered the same ground, or added as an
"Implementation notes" paragraph at the end of the section they best fit
(Discovery → §16, Alerts → §10, Rules → §11, Return trips → §15, the installed
app → §35, the daily timetable → §13, providers and switches → §14, deal
score/statistics/what-a-fare-is → §2/§6/§7). What remains below has no better
home: it is implementation-level detail — HTTP layer conventions, frontend
component contracts, store/DI wiring, test rationale — that doesn't belong to
any one of the numbered business-logic sections above. Every one of these
notes replaced a `// Why: docs/BUSINESS-LOGIC.md §36.` pointer left at its
original site.

### Console commands, jobs and scheduling

**`RetainBuilds`** keeps assets via a per-build ledger, not file mtime — an
unchanged chunk keeps its old name and timestamp across builds, so mtime-based
pruning would delete a file the current page still needs. Default `--keep=3`
balances phone-cache staleness (a phone that missed three deploys is still
worth rescuing, a fourth is not) against build count. A missing manifest warns
rather than fails, since the deploy runs this right after `npm run build` and
a build failure already reported itself. Ledger snapshots use microsecond
precision deliberately — two deploys can land in the same second, and
second-resolution sorting would tie and let retention drop the wrong build.
Scoped to `assets/` only, since `public/build` also holds `manifest.json` and
the ledger itself; a kept file also keeps its `.map` (Vite omits maps from the
manifest, so a manifest-only prune would delete every sourcemap on its first
post-deploy run).

**`PollRoutePrices::$windowDays` must keep its `?? config(...)` fallback — a
DO-NOT-REMOVE landmine.** Redis holds `serialize($job)`, and a payload written
before this property existed carries no `windowDays` at all, leaving the
promoted property UNINITIALISED rather than null (a constructor default is a
parameter default, not a property one). Reading it directly throws "must not
be accessed before initialization" in a worker, on the deploy, for every poll
queued in the seconds before it; `?? config(...)` works because `isset()` on
an uninitialised typed property is false rather than an error.

**`DeploymentInvariantsTest`** unit-tests `docker-compose.yml` itself, because
a stray capability, an exposed port or a drifted uid break nothing at runtime
— they're found by someone reading the file, and the cost of the test (thirty
lines) is much less than the cost of the miss. **`ScheduleTest`**'s whole
premise is that a wrong schedule is invisible: nothing errors, prices just
look a day old, and a missing timezone drifts an hour twice a year unnoticed
by anyone.

### HTTP layer — requests, resources and routes

- **`RouteSnapshots` is four queries for any number of routes, and caches nothing.** The routes with airports and statistics eager-loaded; the observations inside the chart window for every route at once; one `MIN(observed_on)` per route (because "tracking N days" has to look past the chart window, or a route watched since March would claim 60 days); and one cheapest calendar fare per route for the booking link. The watchlist screen asks for six routes and gets four queries where accessors on the model would give it twenty-five. Nothing is cached because the inputs are two small indexed tables and the scoring is arithmetic on them — the read is cheaper than the invalidation, and a cached score is a second place the truth lives, always the one that is wrong after a stats refresh.
- **`cheapestPerRoute()` is bounded to `orbit.poll.window_days`, and that bound is an API contract.** `docs/API.md` defines `cheapest` as "the day `price.current` is for", and `price.current` is the near-window minimum (§5). Since `calendar_fares` now runs eleven months deep, an unbounded `MIN` would publish a cheaper March fare as `cheapest` and leave a card printing "€120" beside "cheapest departure €78" — two numbers the API says are one, with a booking link aimed at a date the score was never computed from. The far months belong to the calendar screen alone. It is a correlated subquery in raw SQL rather than loading 180 rows per route to take one: neither Postgres' `DISTINCT ON` nor a window function is portable to the SQLite the test suite runs on, and its single binding is a date this app computed from config and the clock, bound rather than interpolated regardless. Both halves compare `< the day after the edge` rather than `<= the edge`, because this table is written two ways — `PollRoutePrices` upserts a bare `'Y-m-d'` while anything going through the model's cast writes `'Y-m-d H:i:s'` — and `<=` silently drops the window's last day on SQLite and not on Postgres. Ties keep the first row, which the ordering makes the earliest date.
- **`RouteCalendarController` answers a month at a time**; an out-of-window month returns an empty grid (`days: []`), not a 404, so paging can walk past the horizon honestly. `month` is validated by regex shape rather than a Carbon parse, because Carbon accepts things like "+3 days" that aren't months. `RouteCalendarResource` publishes `min`/`max` for the legend and `foundAt` per day, not per month — the provider mixes fares found an hour ago with ones found last week.
- **Booking links are sent as *templates*, not per-day URLs** — the day sheet books the date the user tapped, and only the client knows which date that is; named tokens (`{ddmm}`, `{yymmdd}`) keep site-specific date-format knowledge server-side.
- **`UpdateSettingsRequest` is `PUT` with every field `required`**, not `PATCH` — a boolean's absence and its `false` value are indistinguishable once optional, and the failure mode is a quiet-hours toggle that can be switched on but never off. `quietStart`/`quietEnd` are required even when quiet hours are off, so turning the feature back on restores what was chosen rather than resetting to defaults. API keys are camelCase (the API's vocabulary), never the database's snake_case. `sensitivity` validates against the configured levels, not `between:0,2`, so a fourth sensitivity level is one config entry away.
- **`DestinationController` sends the whole 184-row list in one request**, not a `?q=` search — it's a few kilobytes from two checked-in files, so a suggestion appears on the keystroke instead of after a round trip. The 3,086 other airports are deliberately excluded (no vibes, no warmth); what counts as a destination is the `destinations` table, not `airports` — the three origins are airports with no destinations row, so Amsterdam never suggests itself as a destination.
- **`SearchAirportsRequest`'s 2-character floor is a cost decision**: one letter matches roughly a third of 3,270 airports, and ten arbitrary rows back is worse than no suggestions, bought with a round trip. The 60-character ceiling just needs to outrun the longest city name. `AirportController`'s search is a `?q=` query (unlike the destinations endpoint's whole-list send) because 3,270 rows is ~200KB nobody should download before typing; ten rows returned, merged client-side with the curated list's eight, with a deterministic tie-break (`orderByRaw()`, proven injection-safe by its `literal-string` return type) since a ranked query with a LIMIT has to be total or the panel reshuffles on re-render.
- **The route-lookup throttle's two limits (6/min, 20/hour) are the fare-provider budget divided up**, not a round number: one miss costs ~7 provider requests, and the ordinary 06:00 hour already claims ≤176 of the ~200/hour ceiling. `POST /routes/lookup` is a write (not a `?refresh` flag on the GET) and takes no `{code}` in the path — it can create a route row and spend six or seven metered provider calls, which a browser prefetch or link preview could trigger unprompted on a GET; the pair arrives in the body so this and `POST /watchlist` validate through the same `RoutePairRequest`.
- **`RoutePairRequest`'s origin is no longer restricted to the configured origins** (removed 2026-08-16, after a day of real use produced 32 lookups and 0 rules) — both ends are now plain `exists:airports,iata`. `config('orbit.origins')` is deliberately untouched and MUST stay that way — it still bounds the nightly rule sweep's budget, and widening this request must never widen a sweep. Input is upper-cased before any rule runs (`prepareForValidation`) so the exists check and the stored row agree. `AddWatchedRouteRequest` refuses a duplicate watch; `LookupRouteRequest` refuses nothing.
- **`UpdatePasswordRequest`: `current_password` is the actual security gate, not the session** — a stolen/borrowed phone is signed in too; a session cookie proves device possession, not secret knowledge. `current_password:web` names the guard explicitly rather than trusting whichever guard middleware last set. `different:current_password` is what makes this a CHANGE — without it, resubmitting the same password reports success while rotating nothing.
- **`WatchlistItemController` is deliberately separate from the read-only `WatchlistController`** — the read is the app's tuned launch request; these are one-row taps, and keeping them apart means neither grows the other's concerns. `store()` finds-or-creates (a route is a fact about the world, not a possession) and queues its poll/stats jobs rather than running them synchronously; `destroy()` preserves the route and its history — only the watchlist row goes.
- **`ManifestController` is a route, not a static file** — nginx's stock mime types have no `.webmanifest` entry (its fallback is a download); as a bonus, the name/colours come from `config/orbit.php`, the same source the meta tag reads. No `?source=pwa` tracking param, since vue-router would have to carry or strip it forever. `orientation: any` since 2026-08-24 (Ghie's call, phase 4 of docs/DESKTOP-LAYOUT-PLAN.md): it was `portrait` because the design's camera choreography assumed it, and the master-detail frame is the thing that made a sideways iPad worth having. A phone that rotates gets the phone layout, which the `min-height: 600px` half of the breakpoint guarantees.
- **`/login` is declared as a named route purely for its name**, even though it serves the same SPA shell the catch-all would — Laravel's guest-redirect middleware resolves `route('login')` eagerly, before it decides whether the response should be JSON, so an app with no route named `login` turns every unauthenticated request into a 500 instead of a 401.
- **`POST /login` and `PUT /api/profile/password` both answer JSON, never a redirect** — the caller is `fetch()` from a page that must not navigate, and a 302 would be followed and handed back as the shell's HTML with a 200. `bootstrap/app.php`'s `shouldRenderJsonWhen` makes this the default for the whole `api/*` prefix (plus any caller whose Accept header explicitly asks for JSON) rather than a per-route decision, since an unauthenticated exception anywhere under it must never come back as a redirect fetch() silently follows into a 200-that-looks-like-success.
- **`GET /api/me` uses `auth:sanctum` in the `web` group specifically** — Sanctum's guard tries the session guard before looking for a token, so a first-party cookie authenticates with no token anywhere, and being in the `web` group boots the session unconditionally rather than via Sanctum's Origin/Referer heuristic.
- **All seven read endpoints live in `routes/web.php`, not a separate `routes/api.php`** — this app has no `api.php`, for the same session-boots-unconditionally reason as `/api/me`; the `/api/` path prefix is kept purely because that's what triggers JSON exception rendering and what the SPA catch-all refuses to swallow.
- **Route codes are constrained to `[A-Z]{3}-[A-Z]{3}` at the router**, on both the read and the `PATCH /watchlist/{code}` write — without it every misspelling reaches the controller and returns a 404 from a database round trip, and a path segment like `../../something` is a routing question rather than a pattern violation.
- **`GET /alerts` has no screen reading it yet, deliberately** — the endpoint exists because the mail pipeline is otherwise invisible from outside the database and needs somewhere to answer "did it fire, and did it go out."
- **`GET /discoveries` is a read and deliberately NOT throttled**, unlike everything else upstream of it in the discovery feature — by the time anyone GETs it, it's one indexed query over ~10 precomputed rows; the expensive work already happened at 05:20.
- **The write-endpoint group sits in the `web` group specifically because that's what gives it CSRF protection** — this app has no `routes/api.php` at all in part because Laravel's `api` group has no CSRF middleware, and these endpoints are called by a browser carrying a session cookie, exactly the case CSRF exists for.
- **`PUT /settings` (not PATCH) sends the whole preferences object every time** — see `UpdateSettingsRequest` above for why an optional boolean would be a switch that can't be turned off.
- **`POST /rules/parse` is a POST that writes nothing, deliberately** — a GET would put the owner's free-text rule sentence in every access log and browser history between the phone and the server. It's the only throttled route in this file besides login, since the create screen calls it on every keystroke's 500ms debounce.
- **`PATCH /rules/{id}` keys on a numeric database id, not a natural key** — a rule has no code to look up by (two rules can be the identical sentence with different chips removed), so this is the one place in the API keyed on a raw id.

- **A watched route may now start anywhere** (2026-08-16), and that is a poll every morning for a pair the owner cannot necessarily fly. It is deliberate and the owner's to decide: watching is an explicit act on a route somebody has just been shown the price of, the watchlist is six rows long, and a list that refused BCN-PMI would refuse the one route somebody actually wanted to follow. What is **not** widened is the rule engine, which watches on its own — `config('orbit.origins')` still bounds the nightly sweep's budget.

### Auth and security

- **`LoginController` has no registration, reset or verification** — Orbit has exactly one seeded user, and a route that doesn't exist can't be misconfigured.
- **Failed-login timing is deliberately equalised**: an unknown email still pays for a bcrypt hash (against a value nobody knows), because `Auth::attempt()` otherwise reaches bcrypt only for a known user, and that gap is a measurable enumeration oracle on a single-account app. Deliberately not asserted by a test — a timing assertion on a shared VPS measures the neighbours.
- **The SPA catch-all route excludes `api`, `up` and `horizon` by negative lookahead**, so the server never turns a real 404, the health check or the queue dashboard into 200-of-HTML.
- **The Horizon dashboard token: EMPTY MEANS DENY.** An unset `HORIZON_DASHBOARD_TOKEN` must never be read as "no secret required" — the token guards a tunnelled look at `127.0.0.1:3085`, not public access. This is a security-intent landmine.
- **`PasswordController`: the session is ROTATED, not ended**, so a phone mid-tap doesn't get kicked off; `logoutOtherDevices()` re-hashes the password and must run BEFORE the re-login below it, since the recaller cookie the re-login queues must carry the FINAL hash — otherwise this device would sign itself out via its own password change. Answers 200 with a body (not 204) so the screen can render "Password changed" without inferring from a status code, but deliberately not the user object — one refactor away from a hash leak.
- **`AuthenticateSession` middleware must be registered instead of the framework's stock version** — Sanctum rewrites the default guard mid-request, so the stock version would silently check the wrong session's password hash on a password change. The comparison hash is copied (not recomputed) from the sanctum-keyed session slot to the web-keyed one.
- **Every rate limiter in `AppServiceProvider` is keyed on account, not IP, except login** — one account, several devices, one phone whose IP changes mid-sentence. Login is the exception (email AND IP, 5/min) since it's the app's entire brute-force surface with no account to key on yet, and the email is lower-cased first so `Ghie@`/`ghie@` aren't two buckets. The password-change throttle uses the same 5/min number for the same underlying guess, keyed on the account. The airport-search throttle (60/min) is unusually generous on purpose — it guards a client bug (a debounce that stopped debouncing), not a cost.
- **Sanctum's bearer-token authentication is deliberately closed at the guard** (`Sanctum::getAccessTokenFromRequestUsing` returns null) — Orbit issues no API tokens, but Sanctum's guard falls through to token auth whenever a request carries an `Authorization` header, reading `personal_access_tokens`, a table this app never migrates. Left open, any request with a stray bearer header would turn a 401 into a 500 from a missing relation. This is a DO-NOT-REMOVE security landmine, not decoration.
- **`bootstrap/app.php`'s trusted-proxy list is RFC1918, not `at: '*'` and not today's exact bridge subnet.** The request crosses Cloudflare then nginx then the compose sidecar before reaching php-fpm, so without *some* trust every generated URL would be `http://` and every secure cookie dropped — but trusting `*` also trusts whatever `X-Forwarded-For`/`X-Forwarded-Host` a client sends, which is the same finding health-tracker's security audit fixed (a login throttle keyed on IP that a guesser defeats by varying the header, and `getHost()` an attacker controls). The protection needs both halves: `deploy/nginx/flights-ghiecode.conf` resets the forwarding headers instead of appending, and this list only trusts a PRIVATE address to relay them. The range is the RFC1918 `/12`, not the compose bridge's exact subnet, because Docker reassigns that subnet on every `docker compose down && up` — pinning today's value is a config that silently breaks HTTPS the day the network is recreated. Loopback is deliberately absent: nothing reaches php-fpm over `127.0.0.1`, so a request that somehow originates on the host itself still can't dictate its own client IP.
- **Trusted hosts are anchored regexes, not bare strings** — Symfony wraps every pattern as `{...}i` and runs `preg_match`, so an unescaped, unanchored `flights.ghiecode.io` would also match `flights-ghiecode.io.attacker.example` (the dots are wildcards, there's no `^`/`$`). `subdomains: false` because Orbit answers to exactly one production name; the middleware is inert under `local` and the test runner (Laravel's own guard) so a feature test still reaches the app on `localhost`.
- **`statefulApi()` is registered even though the SPA's own JSON calls avoid the `api` group entirely** — it prepends `EnsureFrontendRequestsAreStateful`, which decides whether to boot a session by matching Origin/Referer against `config('sanctum.stateful')`, a heuristic that's correct in a browser and silently false for anything that doesn't send those headers. `/api/me` and the rest live in `routes/web.php`'s `web` group instead, where the session is unconditional — see the HTTP layer note above. It's set anyway because an `api` route file is the obvious place for the next person to add an endpoint, and Sanctum's default for one is token-only.
- **`AuthenticateSession` is explicitly reordered ahead of `auth` (`AuthenticatesRequests`) via `prependToPriorityList`**, which the router does not do by default — middleware execution order is `Kernel::$middlewarePriority`, not registration order, and the stock priority list runs `auth` three places before it. The session key it reads is `password_hash_` + whatever the default guard is *at that moment*; running first is what makes every route read `password_hash_web` rather than `password_hash_sanctum` on some of them, once `auth:sanctum` has rewritten the default guard mid-request (health-tracker's own rationale for the same placement — a session this middleware kills should be dead before anything downstream builds a response from its user). It still lands after `ValidateCsrfToken`, which isn't in the priority list and keeps its written position, so a request with no CSRF token is refused before this ever runs.
- **The SPA catch-all is demoted to a `fallback()` route from the router's `then:` callback**, which runs after `routes/web.php` has fully loaded — both the live route collection and the compiled `route:cache` matcher try every other route before any fallback, so this is what lets `/manifest.webmanifest`, `/sw.js` and `/offline` be claimed by `routes/pwa.php` instead of being served the SPA's HTML shell at 200. The failure without it is silent: `navigator.serviceWorker.register()` rejects with a MIME-type error nobody sees, and the OS installs a bookmark where it expected a manifest. Route name lookups are refreshed first because the framework doesn't build them until `booted`, which is after all routes load — without the refresh, `getByName('spa')` is null at this point. `tests/Feature/PwaShellTest` asserts all three routes reach their controllers rather than the shell, so a regression here fails loudly.

### Frontend — globe, tour and geography

- **`globeScene.js` isolates every `globe.gl` call**; nothing else in the app knows the library exists, which keeps a future renderer swap invisible to the screen above it. Textures are vendored locally (`public/globe/`), never from a CDN, because the CSP's `img-src 'self'` would block one. No colour is hard-coded — every one is read live from `tokens.css` so the light theme can reach it.
- **`resize()` ignores a zero-size box** — a camera with a 0/0 aspect ratio produces a matrix full of NaN that never recovers when the element comes back.
- **`tour.js`'s camera sequence is data (`flightSequence()`), not nested `setTimeout` callbacks** — the obvious nested-callback shape can't be tested, inspected, or reasoned about without running it.
- **`geo.js` is pure geometry with zero DOM/canvas knowledge**, which is what lets its numbers (bearing, altitude, great-circle path) be unit-tested in isolation. Bearing is recomputed per animation frame from the CURRENT path segment, not once from the endpoints, since it changes continuously along a great circle. The camera's path midpoint is the true midpoint of the great-circle path, not the naive average of the two lat/lngs — the average is wrong by hemispheres for any route crossing the antimeridian. Longitude interpolates through the shortest delta, not a naive lerp — 179.6°→−179.7° is 0.7° of flying, not 359.3°, or the camera jumps to the far side of the planet for one frame.
- **`geo.test.js`'s expected bearing values are quoted, not recomputed** — a test that runs the same formula twice only proves the machine is deterministic. The great-circle midpoint sits north of the naive average by design (the same effect that routes a transatlantic flight over Greenland).
- **`tour.test.js` pins the absolute millisecond of every step**, not the relative delays `design/README.md` §1 states them as ("after ~1.3s", "after another 2.5s") — the numbers a reader trusts are the sums, and a sum is exactly what nobody recomputes when one delay is nudged. Pinning the absolute moments turns a delay edit into a deliberate change to a number here, rather than into a flight that starts before the camera has finished diving.
- **`RouteRail`'s selected chip auto-scrolls with `inline: 'center'` and `block: 'nearest'`** — without `block: 'nearest'`, the browser is entitled to scroll the whole page vertically to reveal the chip, dragging the globe off the top of the screen every eleven seconds.
- **`e2e/specs/theme.spec.js` reads a real painted CSS property (`color` on `<body>`), not just a resolved custom property** — a custom property resolves whether or not anything actually uses it, so only a painted property proves the theme swap reached the live document. `background-color` is deliberately excluded, since the light theme's `--bg-grad` is a gradient and the shorthand reads transparent in exactly one theme. Tab labels are asserted separately from Home specifically because they were pinned to a test that first rasterises a 1.4MB globe on a software renderer, so on a loaded CI box the label assertion silently never ran.
- **`GlobeStage.test.js` replaces `globe.gl` and the browser clock with fakes** so timing bugs are inspectable (the geometry itself is covered by `lib/geo.test.js` and `lib/tour.test.js`). jsdom lacks `matchMedia`/`ResizeObserver`, both legitimately used by this component, so they're stubbed in `beforeEach` rather than in the component. `vi.mock` is hoisted above imports, so its factory runs before consts defined above it exist — references to them are deferred inside functions because of it. Visibility state is remembered for `afterEach`, since jsdom's `document` outlives the test and a stage left mounted keeps answering `visibilitychange` for every test after it.

- **There is deliberately no camera bias in `GlobeStage`, and that was measured rather than assumed.** The UX pass filed "the spotlight card covers the arc's destination" against a *mid-flight* screenshot. Aiming the camera a few degrees off-subject is the wrong fix at all three steps: **fit** needs nothing (at `fitAltitude` 2.4 the planet is ~205px across in a 390px canvas, its lower limb ~48px clear of the card, both endpoints on screen); **dive** cannot be fixed by aiming (at `diveAltitude` 0.42 a European destination is off the *bottom edge* entirely, and bringing it back means flying higher, i.e. a different screen); and **fly** would break something worse — `PlaneGlyph.vue` is pinned at the exact centre of the stage *because* the camera points at the plane, and offsetting it detaches the aeroplane from its arc by an amount that varies with altitude (0.20 to 0.71 across one flight) and so cannot be cancelled with a constant. The flight *ends* with the destination at the canvas centre, ~150px above the card, where it sits for the whole dwell — the moment the card underneath is there to be read. The finding was real as a photograph and not as a defect.
- **The globe caption's offset is arithmetic, not a z-index.** `design/README.md` §1 asks for a caption pinned to the bottom of a 360px stage *and* a card that climbs 30px over that same edge, opaque, at `z-index: 4` — so every pixel of the caption was painted underneath the card: in the DOM, in the accessibility tree, and visible to nobody. Only a browser could see that; jsdom has no layout engine, so every existing test was green. The design's 6px stays as the caption's own breathing room and the card's overlap plus a gap is added on top. Raising the z-index instead would put the text *on* the card's rounded top edge, over the route code. The caption also carries a **scrim rather than a halo**, on the span and not the `<p>` (the paragraph spans the whole stage, so painting it there would be a full-width band across the planet): a halo tints only the pixels around each glyph, and the failure is that there is no known background at all — the text is over a photograph the palette does not choose. `.stage__caption` carries `pointer-events: none` for a second reason beyond staying out of the card's way: a drag that starts over the caption still has to reach the globe's rotate controls underneath it, and `elementFromPoint` answers a hit test with whatever is actually hittable there — the globe, not the (unhittable) caption text — which is why the regression test asserts the hit landed *somewhere in the stage* rather than on the caption element itself.
- **The stage collapses on a phone turned sideways** (`max-height` under a landscape media query, which keeps the rule off laptops — every desktop window is "landscape", and 560px of viewport height is a phone on its side and nothing else). The installed app is locked to portrait, so this is only ever a browser tab, which is how somebody who has not installed it yet looks at the app; the stage is the only thing that can give up height without losing information, since a smaller picture of one route is the same route. `globeScene.js` sizes the renderer from the element's own box through a `ResizeObserver`, so nothing else has to know the rule exists.

### Frontend — the desktop layout's foundations (phase 0)

- **Four rules clamp the app to a phone-shaped column, and they now read one token.** `.app-shell`, `.tab-bar`, `.sheet` and `.toast` take their `max-width` from `--shell-max`, which is `var(--app-width)` and nothing else today: the indirection exists so a later breakpoint retargets one value instead of four rules in four files, and because it resolves to the same 430px the phone renders identically by construction. `--rail-width` and `--master-width` are the frame's numbers from `docs/DESKTOP-LAYOUT-PLAN.md`, parked in `tokens.css` ahead of the phase that uses them. The two breakpoints (768px, 1024px) are deliberately NOT tokens: a custom property cannot be used in a `@media` query at all, so a `--bp-tablet` would be a value that looks authoritative and is silently ignored by every media query that reads it — the literals are written out, and this sentence is where the number is explained. `GlobeStage` sizes the renderer from its container rather than from a number in the stylesheet: a `ResizeObserver` on `.stage__globe` calls `globeScene.resize()` (which is `globe.gl`'s own `width()`/`height()`), coalesced into one animation frame so that dragging a window edge is one resize per frame instead of one per observation. The stage's 360px and the phone-landscape `40vh` rule stay exactly as they were. The guard that makes the rest of the plan safe is `e2e/specs/phone-baselines.spec.js`: every screen, both themes, at 390x844 with reduced motion, compared at `maxDiffPixels: 0` against committed images, with the fares masked and the WebGL canvas hidden (`visibility: hidden` through a `stylePath`, so the chip and caption drawn over it stay in the picture) so that what is being compared is the layout. Those images are committable at all because the sandbox clock is frozen on both sides — `E2E_FIXED_NOW` reaches `Date::setTestNow()` through `SandboxClockServiceProvider`, guarded by `ORBIT_E2E` rather than by `APP_ENV` because the sandbox runs as production on purpose, and every page starts at the same instant via `page.clock.install()` + `resume()` (docs/E2E.md "A frozen clock").

### Frontend — the desktop frame and the landing page (phase 1)

- **One composable decides the layout, and its breakpoints carry a height as well as a width.** `useLayout()` (`lib/layout.js`) reports `phone | tablet | desktop` from two `matchMedia` queries and is safe where there is no `window` and no `matchMedia` at all — jsdom has neither, and "assume the phone" is the one safe default, since the phone is the layout that needs no media query to be right. The queries are `(min-width: 768px) and (min-height: 600px)` and the same with 1024px, and **the height half is not decoration**: a handset on its side is 844x390, which is wider than 768px and is still a phone, and 390px of height leaves the frame's panes no room to be panes — the browser gate caught exactly that, as a detail pane collapsed to zero height in `globe.spec.js`'s landscape test, which is now the regression that holds the number. Both halves are written out as literals in `lib/layout.js` **and** in every `@media` rule that implements the same decision — **13 occurrences across 10 files**, which is the whole list: the 768px pair in `app.css`, `Views/Home.vue` and `Components/UpdateToast.vue`; the 1024px pair in `app.css` (the shared master/pane frame), `Views/{Home,Calendar,Alerts,Create,Search,Watchlist}.vue` and `Components/watch/WatchRow.vue`; and both of them in `lib/layout.js`. A custom property cannot be read by `matchMedia` any more than by `@media`, so they must be edited together or the JS and the CSS will disagree about which layout is on screen. Moving the shared frame into `app.css` collapsed four copies of its *rules* into one and left the four queries where they were — every one of those screens still has wide rules of its own — so the literal count went **up** by one, from 12 to 13, while the duplicated declarations went from four copies to none (docs/DECISIONS.md: the-screen-shell-is-global). `IconRail` replaces `TabBar` by `v-if` rather than by a media query, so exactly one of them is ever in the DOM, and both answer to the same accessible name (`Primary`) so a spec's `tab()` helper works at any width. `--shell-max` is retargeted to `none` on `.app-shell--rail` and **not** on `:root`: a screen with no rail — the route detail, the login — keeps the phone column at any width, which is what "the other screens are unchanged this phase" means. The toast follows the frame the same way (`.app-shell--rail .toast`); the day sheet needs nothing, because it is teleported to `<body>` and so never inherits the frame's token at all. `meta.wide` on the home route is what says a screen owns the frame; every other tabbed screen gets `app-shell__main--column`, which centres its existing phone layout in what the rail leaves until phases 2-3 give it a pane.
- **The route detail was split so that one component draws it in both places.** `Components/route/RouteDetailPanel.vue` is everything the screen showed below its back bar, taking the pair as a `code` prop; `Views/RouteDetail.vue` is now the back bar and that panel, and `goBack()` moved to `lib/back.js` because two callers need it (the bar, and the "no such route" button inside the panel). **The panel has no wrapper element** — it renders as a fragment, so the phone's DOM is the same DOM it was, which is what lets the 0-pixel phone baselines prove the split rather than merely survive it. The landing page's selected route lives in the URL as `?route=AMS-LIS` on `/`, written with `router.replace` so picking a row is not a navigation and `Home`'s `<KeepAlive>` (and the globe with it) is untouched; a code that is not on the watchlist falls back to the tour's own route, because rows, globe and panel disagreeing is worse than a link that quietly opens the default. The wide branch does **not** listen for the globe's `advance`: the pane shows the route that was chosen, and a tour moving off it every eleven seconds would argue with the panel below and rewrite the URL — and refetch a route — while nobody was touching anything. The globe takes **45% of the pane (never under 280px)** rather than "whatever the detail does not need": the detail here is the phone's single column, which always wants more height than the pane has, so there is no leftover to give it and the panel scrolls under a globe of a fixed share instead. The design canvas's two-column detail pane, which is what would make leftover height mean anything, is phases 2-3. Three consequences of the panel living inside a kept-alive screen are handled where they arise: it **refetches on `onActivated`**, because `App.vue`'s `KEPT_ALIVE` invariant is "a cached screen caches stale data" and this pane is the first cached thing in the app holding fares — the refetch is `load({ quiet: true })`, which skips the skeleton so the fares already on screen stay readable while it runs, and it is inert on the phone, where the detail screen is not cached and the hook never fires; it takes an **`embedded` prop** that removes the "Go back" button from the "no such route" state, since inside the landing pane "back" would leave a page nobody navigated away from; and Home **corrects the query** when a link names a route nobody is watching, because falling back to the tour's route while the address bar goes on naming another one is the URL lying about what is on screen. The wide header carries **no profile button** — the rail already has the account link at these widths, and two links of the same name to the same place is one too many.

### Frontend — the calendar, the watch list and the two-column detail (phase 2)

- **Both wide screens are the phone's own template, widened by wrappers that are not there.** `Views/Calendar.vue` and `Views/Watchlist.vue` each grew four nested `<div>`s — a master, a pane, and the columns inside it — that carry `display: contents` at every width below 1024px and become the frame's boxes above it. An element with `display: contents` generates **no box at all**: its children lay out as though it were not in the DOM, margins collapse through it exactly as before, and the phone therefore renders the layout it always did while one template serves both. The alternative was Phase 1's `v-if`/`v-else` pair, which is what the landing page has, and it would have meant a second copy of the calendar's four states and of the whole deal-rules section — the duplication phase 2 exists to *remove*. What moves rather than merely re-nesting is small and explicit: the calendar's `MonthNav` is drawn in the master head below 1024 and in the pane above it (two `v-if`s, never both), the chip strip is swapped for `Components/RouteRows.vue`, and the watch screen's "Rules · N" jump chip is dropped at the width where the rules are a column already in view. `meta.wide` grew a second value to say all this: `true` still means "owns the frame from 768px" (only the landing), and `'desktop'` means "owns it from 1024, phone column below", because a 768px window has no room for a 352px master pane *plus* two content columns and the honest fallback is the centred phone layout the rail already leaves. Read that as a promise about hardware: these two screens change on an iPad in **landscape** and on a desktop window, and an iPad in portrait (820px) is untouched by phase 2.
- **`RouteRows` needs no media query, because the component is the guard.** The plan's rule is that every desktop rule lives behind a breakpoint *or* inside a component only the frame mounts; the master pane's list is the second kind, so its styles are plain. **Its ARIA is a `kind` prop rather than one answer for three screens**, because the three screens do different things with a press: on the landing and the calendar the pane beside the list is replaced outright, which is `role="tablist"`/`role="tab"`/`aria-selected`; on the watch list nothing is swapped — every pass is already on screen and the chosen one moves to the head of them — which is a group of toggles, so those rows are plain buttons with `aria-pressed`, the same pattern the calendar's chip strip uses on a phone. Claiming a tab list where no panel changes sends a screen reader looking for a `tabpanel` that does not exist. A paused route dims at `--dim-paused`, the one token the chips, the deal rules and the boarding passes all read, and stays selectable, and a route with no fare prints an em dash — never €0, which would be a lie about a price rather than an absence of one.
- **The chosen pass leads in the DOM, not by `order`.** `Views/Watchlist.vue` builds a `passes` list with the selected route moved to the front for the wide branch only; the phone gets the store's list untouched. A `.is-selected { order: -1 }` would have been one line of CSS and would have handed a keyboard the passes in one sequence and the eye another, which is the classic flexbox `order` defect. What CSS still does is `grid-column: 1 / -1`, because spanning is a visual fact and not a reading order. The grid itself is `repeat(auto-fill, minmax(240px, 1fr))` rather than a hard two columns, and `.screen__body` **wraps**: two shrinking flex columns squeeze a pass to about 170px at 1024, and `.pass` hides its own overflow, so the failure is the IATA codes and city names being silently cut off with no sideways scroll for a guard to notice. Wrapping drops the rules under the passes below 1260px instead, and `e2e/specs/layout-screens.spec.js` measures `scrollWidth > clientWidth` on every code and city at 1024x600 so that the clipping cannot come back.

- **The docked day panel is the sheet's body without the sheet's promises.** `DaySheet` takes a `docked` prop that removes the backdrop, drops `position: fixed`, and reports `role="region"` instead of `role="dialog"` + `aria-modal="true"`. Those two attributes are a claim that everything behind the element is inert, and a card sitting in a pane beside a live month grid makes no such claim — announcing one would send a screen reader hunting for a way out of something that never trapped it. Escape still clears the selection, because a keyboard should be able to put the panel away whether or not it was ever modal — and closing it **puts focus back on the day cell that opened it**, which the screen remembers as the `document.activeElement` at the moment of the pick rather than by re-deriving which cell it was. A detached element's `focus()` is a no-op, which is exactly the right behaviour when the month changed underneath. Below 1024px the sheet is teleported to `<body>` exactly as before, and for the original reason: the calendar's root carries a transform, which would otherwise be the containing block for anything `fixed` inside it.
- **The landing detail's two columns are a 2x2 grid, and the reason is the phone's reading order.** The phone reads head → price → chart → advice → booking; the design canvas's left column is head/price/advice and its right is chart/booking. One DOM order cannot produce both with two column elements, and reordering the DOM is precisely the phone regression the whole plan is gated on — so `RouteDetailPanel` wraps its body in **four** `display: contents` groups (summary, chart, advice, booking) which `Views/Home.vue` places into grid columns 1, 2, 1, 2. Auto-placement then fills two rows in source order, and the shorter group in each row leaves a gap beneath it; that is the same gap the artboard's `space-between` columns draw, so it is the design's own shape rather than a defect of the mechanism. Everything that is not one of those groups — the skeleton, "no such route", the failure state — spans both columns, because a two-column grid holding one element would put a column of nothing beside it. With the detail finally short enough to have leftover height, the globe stops taking **45% of the pane** and takes `flex: 1 1 0` with a 280px floor instead: the pane's height minus the detail's natural height, which is what "bigger screen, bigger globe" was supposed to mean all along. The panel is `flex: 0 1 auto` and keeps `overflow-y: auto`, and the `1` is load-bearing: the frame's own floor is a 600px-tall window, where a 280px globe and a ~385px detail do not both fit, and with `0 0 auto` the overflow would escape upwards to `.app-shell__main` and scroll the master pane and the globe off the screen together. Shrinking instead means the detail scrolls under a globe that stays exactly where it is, which is asserted at 1024x600.
- **The calendar's cells stay square and the card is what sizes them.** The artboard sets `aspect-ratio: auto` on a filled grid so the month always reaches the bottom of the pane; the plan says square, and the plan wins — a calendar cell that is taller than it is wide stops reading as a date. So `MonthGrid` is untouched and the *card* is capped at 560px (the artboard's own width), which is the only number that decides how big a cell is. The day panel takes what is left, and a pane too narrow for both wraps it under the grid rather than squeezing a 39px cell out of the month — **which puts the dock threshold at 1264px**, not at the 1024 the plan's one-line summary implies: the pane is the window minus the 76px rail, the 352px master and 56px of padding, and 560 + 20 + a 200px panel needs 780 of it. Docking from 1024 was costed and rejected at 37px per cell, under the phone's own 48. Between 1024 and 1263 the panel is a full-width card under the month, and `e2e/specs/layout-screens.spec.js` asserts both sides of that line.

### Frontend — search, the new rule screen and alerts (phase 3)

- **The last three tabbed screens are the same `display: contents` trick phase 2 established, and all three are `meta.wide: 'desktop'`.** `Views/Search.vue`, `Views/Create.vue` and `Views/Alerts.vue` each grew a `__master`/`__pane` pair (and, on create and alerts, one more wrapper inside the pane) that generates no box below 1024px and becomes the frame's boxes above it. Nothing about the phone's DOM order moved, which is what keeps its baselines at zero. `'desktop'` rather than `true` for the same reason the calendar and the watch list took it: a 352px master pane plus a readable content column does not fit in a 768px window, so an iPad in portrait keeps the centred phone layout and only landscape and desktop windows get the frame.
- **`Components/rules/DealRules.vue` exists because two screens now need the same list, and it owns its own data.** The watch list carried the deal rules section inline; the create screen's master pane is that same list, so it was extracted rather than copied. The component loads the rules itself (`onMounted(rules.load)`), owns the busy-id set and both writes it makes — pausing a rule, and promoting one of its matches into the watch list store via `adopt` — so `Views/Watchlist.vue` lost about sixty lines and `Views/Create.vue` gained one tag. **The class names did not change, and the CSS for them moved with the markup**, including verbatim copies of `.screen__notice`, `.screen__state` and `.screen__retry`: a scoped stylesheet does not reach inside a child component, and renaming those three to `rules__*` would have been precisely the DOM change the extraction was supposed to avoid. What deliberately stayed behind is `.rules` itself — the 26px margin, the 18px padding and the hairline that separate the section from the routes above it are `Watchlist.vue`'s rule, applied to the child's root because **a child component's root element carries its parent's scope id**; the create screen mounts the same component with no such rule and therefore gets no hairline, which is what a master pane wants.
- **`stores/rules.js` grew a second error ref, because one screen now hosts two independent failures.** It had a single `error` for the live parse of the sentence being typed *and* for everything about the saved list. That was survivable while only the watch list drew the list; it stopped being survivable the moment `DealRules` started issuing its own `GET /api/rules` on `/create`, because a failed list load, a failed pause and a failed match-promotion all printed under the create screen's textarea — a sentence about a control on the other side of the screen — and `parse()` and `load()` cleared each other's messages on the way past. So `error` is now the compose screen's alone (`parse`, `create`) and `listError` is the list's alone (`load`, `toggle`, `remove`, `watch`). The rule this encodes: **the control that failed owns the sentence about it**, which means `DealRules` always draws `listError` in whichever pane it was mounted in, and never needs a prop to be told to keep quiet. An earlier `notice` prop did exactly that suppression and was deleted with the duplicate it was hiding.
- **`DealRules` takes a `newRule` prop, and `/create` passes `false`.** The "+ New rule" link is the only door to the create screen from the watch list; on the create screen itself it is a link to the page you are already on, which is a control that does nothing. Same component, one boolean, and the watch list is untouched.
- **Search's look-up answers in the pane, and its way back is a button rather than the clear flow.** `lookUp()` branches on `isDesktop`: a phone still pushes `/route/:id` (the tab bar is the way back there, as it always was), and the frame writes the pair into a `looked` ref that the pane renders with `RouteDetailPanel embedded` — no navigation, so the form, the rail and the URL are all still the ones that were there. The obvious "way back" would have been the field's own ✕, except that only the **origin** box has one; the destination deliberately does not. So the pane carries a single back control whose label is `Deals from your airports` — the heading it returns to, which is copy the screen already had rather than copy invented for the frame. A watcher on `[origin, destination]` also clears `looked`, because a pane holding the pair the form has moved past is a stale answer to a question nobody is asking any more. The finds grid is `repeat(auto-fill, minmax(300px, 1fr))` and not a hard two columns: at the frame's own floor the pane is about 540px, which is one readable find card rather than two clipped ones.
- **The create screen's head moved out of its two branches into the master pane.** The screen shows one head while a rule is being written and a different one once it is saved, and both used to live inside their own `<template>` branch. The master pane is shared by both states, so the pair became a `HEADS` constant and a computed — the same two strings, rendered from one place. The pane's content column is capped at 680px and left-aligned rather than centred: a sentence somebody is writing is prose, and prose does not want an 800px measure, but centring it would unmoor it from the master pane it belongs to.
- **Alerts has a section list and deliberately no scroll-spy.** The plan asked for one; two columns make it impossible to write honestly. CHANNELS and SENSITIVITY start on the same line, and so do TIMING and ACCOUNT, so a scroll offset does not identify a section and an observer would have flipped between two equally-true answers at every position. The list is therefore driven by clicks, plus the `#account` hash which now lights ACCOUNT as well as scrolling to it. What is lit is a computed rather than the raw click: it falls back to the first section **on the page**, so while the gated three are still loading the list lights ACCOUNT rather than pointing at a CHANNELS row it is not drawing. At 1280x832 every card fits without the pane scrolling at all, so the list reads as a map more than as a lift — which is what the artboard's own note predicted when it said the section list "promises navigation the screen no longer needs". The five sections gained ids (`#channels`, `#sensitivity`, `#timing`, `#this-app`, beside the `#account` the landing head has always linked to), and three of them are gated behind `isReady`, so a screen still fetching its settings lists only the two cards it is actually drawing.
- **`input[type="time"]` has a floor, and a card that hides its overflow turns that into silent clipping.** The quiet-hours *From*/*Until* pair was `display: flex` with `flex: 1` fields, which is correct on a 430px phone column and wrong in a 260px pane column: a time input will not shrink below the UA's own minimum (about 124px in Chromium), so at 1024-1084px — where the alerts pane's column is under roughly 290px — the second box was simply cut off by `.card { overflow: hidden }`, with `documentElement.scrollWidth === innerWidth` still perfectly true and the sideways-scroll guard therefore blind to it. The fix is `flex-wrap: wrap` and `flex: 1 1 120px`, so the pair stacks where it will not fit; on the phone both boxes come out the same width they always did (basis 0 and basis 120 split identical free space between two identical items), which is why this lives in the base rule rather than behind a breakpoint, and why the phone baselines are the proof. **This is the second instance of the same class of defect** — `.pass` clipping its IATA codes at 1024x600 was the first — so `e2e/specs/layout-screens.spec.js` now runs the same `scrollWidth > clientWidth` sweep over `.card *` that it runs over the boarding passes.
- **The alert cards' two columns are five `grid-column` placements, not two column elements** — the same mechanism, and the same reason, as the landing detail's 2x2 in phase 2. The phone reads channels → sensitivity → timing → account → this app; the design canvas's left column is the 1st and 3rd of those and its right column is the 2nd, 4th and 5th. One DOM order cannot feed two column elements that arrangement, and reordering the DOM is the phone regression the whole plan is gated on, so each `h2` + `card` pair is wrapped in a `display: contents` `.set` which the wide branch places into column 1, 2, 1, 2, 2 with `align-items: start`. The `.section` label's 22px top margin is replaced by the grid's own 20px gap in the wide branch, or every column would start a label's margin below the pane's padding.

### Frontend — the dark pass, the keyboard and the wide baselines (phase 4)

- **The dark pass was a measurement, and it found two faults that are the frame's own.** A sweep over every element carrying its own text — compositing the background stack through its alphas, multiplying the cumulative `opacity` into the foreground, and comparing the result against WCAG AA — ran on all six wide screens at 1280x832 and 1024x600 in both themes. `RouteRows` dims the destination city to `opacity: .66` and the fare to `.78`, which is correct against `--card` and wrong against the **accent fill of the selected row**: `--on-solid` on `--accent` is 3.38:1 before any dimming, and 0.66 of it measured **2.32:1**. The dimming is therefore released on `.route-row--active` only, which takes both to the same 3.38:1 the row's own IATA pair already prints at — the value the tab bar's active chip, the booking CTA and the search quick-chip all carry. **`--accent` and `--on-solid` were deliberately not touched**: they are the palette, the phone reads them too, and a token edit here would be a redesign disguised as an accessibility fix. The second is `.seclist__item--active`, the alerts master pane's current section, which the design canvas draws as `background: var(--card)` with `box-shadow: var(--shadow)` — in the dark theme that is `--card` on `--panel` at **1.13:1** beneath a black shadow, so the marker had no shape at all and only the `--accent-ink` text carried it. It now takes the card recipe's own `1px solid var(--line)` edge as an **inset** ring, so no box moves and the light theme keeps the shadow that was working for it. Everything else the sweep flagged is drawn identically on the phone — `.cell__day` and `.cell__price` over the calendar's heat scale, the discovery strip's `Unverified` badge — and is therefore a palette question inside the nineteen baselines rather than a frame question; the two disabled search buttons under 3:1 are correct, since WCAG 1.4.3 exempts an inactive control.
- **The master rows are a tab list with manual activation, and that is a decision about the network.** `RouteRows` keeps a single tab stop (`tabindex="0"` on the focused row, `-1` on the rest, clamped so a shorter list cannot leave the stop on a row that no longer exists) and moves it with Left/Right/Up/Down, wrapping, plus Home and End. What it does **not** do is select on arrow: automatic activation is the commoner reading of the ARIA pattern, and here every selection refetches a route and moves the globe, so walking a six-route list would fire six requests nobody asked for. Enter and Space are the `<button>`'s own and are what chooses. The focus is moved **by code**, not by index — Vue does not promise a `v-for` ref array is in the source array's order, and the rows already carry `data-code`. `kind="group"` (the watch list) is exempt from all of it: nothing swaps for those, so they stay ordinary buttons that Tab reaches one by one. The list reports `aria-orientation="vertical"`, since a `tablist` is horizontal unless it says otherwise.
- **The panel moves the focus; the screen decides whether it should.** `RouteDetailPanel` takes an `autofocus` prop and watches its own **heading element** rather than the fetch that produces it — the loaded, checking, not-found and failed states have four different `h1`s, and the one that renders is the one worth being sent to; a `tabindex="-1"` on each is what makes it focusable and changes nothing about how any of them draws (the reset in `app.css` zeroes `h1`'s margins, so the checking sentence is the same box it was as a `<p>`). **The checking heading is the one a discovery needs**: a found route has no `routes` row, so the read 404s into a look-up and the panel spends several seconds in that state — with no heading there the focus went to `<body>`, the pressed card having been unmounted. That branch therefore drops its own `role="status"` whenever `autofocus` is on: the focus landing on the heading already speaks it, and Search's pane region is speaking beside it. `focus({ preventScroll: true })`, because a pane that scrolls itself while handing over the focus has moved for a reason nobody can see. **Only search passes it.** On the landing page the pane swaps from a row inside a tab list, and moving the focus out of that list would break the arrow keys somebody had just used to get there — so the row keeps it, which is what a tab list promises. Search also carries a `role="status"` region that is **mounted with the frame rather than with the answer**: a live region that arrives with its text already in it announces nothing, so the element exists for as long as the pane does and only its text changes, between `Deals from your airports` (the heading it returns to, which is copy the screen already had) and `Showing AMS → LIS`. That one leading word is the only string this phase invented.
- **A find is a button in the frame and a link everywhere else.** `DiscoveryCard` renders `<component :is>` — `RouterLink` on the phone, a `<button>` at 1024 and up — because in the pane **nothing navigates**: the URL does not change, exactly as phase 3's look-up does not, and an `href` naming a page the card never opens is a lie the browser repeats in the status bar. Search's "Open it" line makes the same swap for the same reason. Both write into the `looked` ref the look-up already used, so the pane, the way back and the announcement are one mechanism with three doors rather than three. The phone keeps the anchor it always had, which is what keeps its `discover` baseline at zero.
- **`.pass__flight` is `nowrap` in the wide grid on the strength of five pixels.** The watch pane's grid is `repeat(auto-fill, minmax(240px, 1fr))` inside a 540px column, which is two 263px cards at every window from 1024 up — there is no other card width. Measured there, the eyebrow's icon, its gap and the natural width of `Flight watch · FW###` leave **5.0px** before the widest verdict pill (`Falling`) on four of the six passes. It does not wrap in the gate's Chromium, and it is five pixels from wrapping: a fallback display font, another rasteriser, or one longer verdict label is the whole margin. So the eyebrow gets `min-width: 0` and the line gets an ellipsis, inside a `(min-width: 1024px) and (min-height: 600px)` query in `WatchRow.vue` — the component is shared with the phone, so the breakpoint is the guard rather than a `:deep()` from the screen.
- **`DealRules` says one sentence where the box that writes rules is on screen.** A `compact` prop, passed by `/create` alongside the `newRule: false` it already passed, renders the blurb's own first sentence instead of the full explanation — which on that screen explains what the textarea beside it is visibly for. The watch list's paragraph is left **byte-identical** rather than factored into a constant shared by both: its rendering is inside the phone's `watch` baseline, and six duplicated words are cheaper than discovering that Vue's whitespace condensing and a template literal disagree by a pixel. The same call phase 3 made when it copied `.screen__notice` verbatim rather than renaming it.
- **The manifest allows landscape from 2026-08-24, and the breakpoint's height half is why that is safe.** `orientation` is `any`. An installed iPad rotating is the entire point of this plan; an installed **phone** rotating is 844x390, which is wider than 768 and still a phone — and `(min-height: 600px)` in both `lib/layout.js` and every `@media` rule is what puts it back in the phone layout rather than in a frame with no room for panes. The decision is Ghie's; the guarantee is the number.

### Frontend — views and components

- **A `var()` inside an SVG presentation attribute is the trap this branch avoids everywhere.** `stroke="var(--good)"` and `fill="var(--good)"` are *presentation attributes*, and browsers disagree about whether one may carry a CSS value: the ones that say no paint the ring, the glyph or the star black. So every tokenised SVG colour in this app is set from a style block instead — `AdviceCallout`, `DealScoreGauge`, `PriceHistoryChart` and the calendar's star all do it for this one reason.
- **The deal-score ring prints "/100", because the scale is part of the number.** A ring reading 65 with "DEAL SCORE" under it is a figure with no units — 65 out of 100, out of 10, out of five stars, or a rank among the routes on the list are all readings a person actually offered, and an arc does not settle it, since a battery meter is an arc too. The `aria-label` had said "out of 100" from the start; this is the sighted half of the same sentence, and it is dropped when there is no score, because "/100" under a dash puts units on a number that is not there. The caption must stay on one line — "DEAL" over "SCORE /100" reads as two labels.
- **The discovery strip renders only when there is something on it**: no skeleton, no "loading deals…", and no empty state, all three deliberate. A skeleton reserves space on every visit for a section that is frequently and legitimately empty (a box with no sweep provider, a week where nothing cleared the thresholds), and reserving space is a promise — the form must not move under somebody's thumb while a background fetch lands, the same reflow argument the suggestion panels are built around. An empty state would be the wrong apology: "No deals today" implies something failed, and nothing did — every threshold in `orbit.discovery` is a floor rather than a quota precisely so "nothing was remarkable this week" is a possible answer, whose honest rendering is silence.
- **`UpdateToast` is a sibling of `<main>`, not something inside it.** It is fixed to the viewport and lives as long as the app does, so rendering it inside the `RouterView` would tie an announcement about the whole app to whichever screen happened to be mounted — and would put a node inside the `<KeepAlive>` that caches the globe.
- **The day sheet mirrors `BookingCta`'s pair rather than sharing it** — same shape, same order, same "See this fare" copy and the same merged disclaimer, because they are one decision on two screens and the owner should not have to learn it twice; what the two have in common is the *sentence*, not the layout (that component is a full-width 54px button, this is half of a compact pair). Its colour swatch says what it is *of* — where this day's fare sits between the cheapest and dearest day of **this** month, on the grid's own ramp — because without the grid in front of you that is unguessable, and the UX pass read it as decoration; the square stays `aria-hidden`, since the caption is a restatement of the verdict pill, which says it in words already.
- **The day sheet is `Teleport`ed to the body, and not for tidiness.** The calendar screen's root carries a `rise-in` transform, and an element with a transform is the containing block for its fixed-position descendants — so a sheet rendered in place would be pinned to the scrolling column rather than to the viewport for as long as that animation is live.

- **`BookingCta` is an anchor, not a button** — it leaves the app, so it must be long-pressable, copyable and openable in a new tab, and announced as a link. `rel="noopener"` is not optional on a `target="_blank"` (without it the opened page gets a live `window.opener` handle back), and **`noreferrer` is deliberately absent**: the Aviasales link is an affiliate one, and stripping the referrer is how that attribution disappears. The second opinion is a **button, not a line of text** — it shipped as a 12px centred text link on the argument that "two buttons is a choice the reader has no basis for making", and the owner used it and disagreed, which settles it: on a phone that line did not read as pressable at all. So they are a pair and not equals — Skyscanner outlined on the left, Aviasales accented on the right at roughly six-tenths of the width, and the width *is* the reader's basis for choosing. Both go quiet when the advice is a warning: with two controls on the line, leaving the accent on one would keep the page arguing with itself. The labels **wrap rather than truncate or shrink** (ellipsis hides which site it is; smaller type is the fine-print defect being fixed; cutting the verbs loses "this leaves the app"), and the disclaimer is one merged line rather than two, because two greyed-out sentences under a button is the shape of small print and small print is not read. It is word for word the day sheet's, duplicated rather than shared because what the two have in common is the sentence, not the layout.
- **The calendar screen opens on the month the route's cheapest departure is in**, clamped into the window the arrows can reach. It used to open on today, always — which the poll window only half covers, since the days before today are gone — while the actual cheapest day sat two taps away, unmentioned; the banner said "cheapest *this* month" and nothing said which month to be in. The arrows span eleven steps and twelve grids because 334 days can never touch more than twelve calendar months (brute-forced over every start date in a four-year span). The far months are legitimately thin and sometimes empty for three separate reasons, none of them a bug — a window opening early in a month closes inside the twelfth, the provider's cache thins with distance, and months 7–11 are refreshed only weekly — so the screen says "No fares seen for this month yet" rather than stopping short to guarantee a full grid.
- **`Watchlist.vue`'s add-route machinery moved to `Search.vue` on 2026-08-16**, leaving this screen to just the list; undo is a real re-add write (not a held request), which is honest because removing a route never deletes its history — a re-added route comes back with its price history intact.
- **`AirportField.vue`'s two-tier suggestion panel (curated, then world) exists because the curated 184 rows answer instantly from memory and are the only ones the rule engine can match**; the `open` flag is owned by the parent form, not the field, because focus/blur races with the suggestion panel made the "Look up" button unpressable while a panel was open. A "did-you-mean" guess only appears when nothing matched and the world search isn't still in flight, and only searches the curated list. The value watcher fires on the normalised (stripped, not upper-cased) value rather than gating on `open`, since paste/autofill/`fill()` can deliver a value in one event that beats an `if (open)` guard. The suggestion panel is in the document flow, not floating, because Orbit's 430px column would otherwise let a floating panel sit directly on the buttons beneath it. `isKnownCode` reads the normalised value, not what the field shows — lower case IS a code there, which is the whole point of keeping the two strings apart. The digit-stripping watcher had to widen when the box started taking place names, and still keeps whatever case was typed (a box that answered "Lisbon" with "LISBON" read as a complaint about what had just been typed). Enter takes the world suggestion on the first press, not the second — before world flights, `isKnownCode` only knew the curated 184, so a code like JFK (not in it) fell through and "took" the box's own text as a suggestion, needing a second Enter to actually send. `clearLabel` both names the ✕ and turns it on, because a clear button nobody can name is one a screen reader announces as just "button"; the To box passes no label since an empty To means "nothing chosen yet." The other box's own airport is excluded from suggestions — a route from a place to itself isn't a route — and `clear()` is exposed so the search screen's home pills can empty the field without a `world.clear()` of their own, since writing '' already cancels the debounce below `MIN_QUERY`.
- **`Create.vue`'s textarea is seeded with a worked example** so the feature isn't invisible before anyone guesses what to type; removing a chip re-parses the same sentence rather than editing the text, so the words on screen are always exactly what was typed.
- **`Alerts.vue`'s Account card is the one section not about alerts** — it's here because this is the only settings surface the tab bar reaches; the screen scroll-to-`#account` waits for settings to finish loading rather than using the router's `scrollBehavior`, which fires before the request settles.
- **`WatchRow.vue`'s switch and bin are structurally outside the `<a>` link** (asserted as DOM structure, not just behaviour) so a tap can never both toggle the route and navigate away. It renders a day-1 route honestly (no data, not a guess); "Paused" outranks the tracking-days note in the stub, deliberately.
- **`TabBar.vue`'s centre button became a search icon on 2026-08-16** on the evidence that the first day of real use produced 32 look-ups and 0 rules; the search tab has no stray `aria-label` because its visible text is now its accessible name.
- **`PriceHistoryChart.vue` is hand-drawn SVG, plotted by real elapsed date** (not evenly spaced by index) — spacing evenly would draw a 4-day poll gap as one day and flatten every trend across an outage.
- **`ChangePassword.vue` shows server error text verbatim**, never restated client-side, and has no strength meter — the twelve-character rule is the server's, and a client-side opinion could disagree with the only one that counts.
- **`SpotlightCard.vue` is a real `<a>` link, not a button with `router.push`** — for long-press, new-tab and status-bar affordances. Its day-1 state ("No fare yet") is not treated as an error.
- **`MonthCalendar`'s cheap/mid/pricey verdict is computed server-side**, using the month's own low/high (not the route's yearly stats) — two implementations of the same rule would eventually disagree, and the one that disagreed silently would be the one on the phone.
- **`Calendar.vue`: which month it opens on is the watched route's cheapest-departure month**, clamped into the eleven-month arrow window and never in the past — it used to always open on the current month, so a route's actual cheapest day could sit several taps away, unmentioned.
- **`Home.vue`'s component `name` is load-bearing** — `App.vue`'s `<KeepAlive>` matches on it to avoid rebuilding the WebGL scene every time someone glances at another tab and comes back. The greeting deliberately uses the phone's local clock, not the owner's configured timezone — a greeting talks to whoever is holding the phone; everything with a real date uses the server's timezone. Day one is still this app's screen: with an empty watchlist the globe draws with NOTHING ON IT rather than not being drawn at all, so the empty state is the same screen as every other day, not a different one.
- **`MatchBanner.vue` uses info tone (not "good"), shimmers rather than emptying during a re-parse**, phrases a growing count as a floor ("2 trips match ... so far"), and drops "cheapest €34" from that phrasing — a superlative over a set still being assembled is worse to have said than nothing.
- **`DiscoveryCard.vue` is a sibling of `SpotlightCard.vue`, deliberately not a copy** — a discovery has no history so it shows its working (age, percentile, savings) instead of a percentage. Badge label/verified come from the server, never composed client-side. `.find__from` stays route-pair-only (e2e reads it to navigate); the lane tag is a sibling element, never appended text.
- **`boardingPass.js`'s flag swatch and flight number are pure derived functions, never sent by the server** — neither is real data, and both must be pure so the card doesn't visibly change between renders. Flags are CSS gradients, not images or emoji.
- **`resources/js/Components/calendar/month.js` computes everything in UTC deliberately** — the API's dates are bare `YYYY-MM-DD` with no zone, and `new Date('2026-06-01')` parses as UTC midnight then answers `getDay()` in the viewer's own timezone, shifting the whole grid a column for anyone west of London. Locale is pinned to en-US to match the signed-off design. The grid is built from the calendar and filled from the API, never the reverse — `days` arrives with missing dates simply absent, so indexing into it by position would misalign every date after a gap.
- **`resources/js/router/index.js` uses `createWebHistory`, not hashes**, so every route gets a real, shareable URL and a PWA launch target; `routes/web.php` answers every non-API path with the same shell. `meta.layout` (`'tabs'` or `'bare'`) is a string, not a swappable layout component, since swapping components would remount the tree and drop the globe's `<KeepAlive>` cache. `meta.guestOnly` mirrors the server's auth split and is opt-in: a route without it needs a session, so screens are private by default. The catch-all redirect goes to home rather than an error page, since there's no content at an unknown URL to apologise for.
- **`RouteSummaryResource` is one shape read by three screens** (spotlight card, watchlist row, detail header), so `price` can't mean something different on one of them. Nulls in `price` are real answers ("not known yet"), not zeroes; a screen that renders them as €0 or 0% is stating something false.

- **The centre tab is Search, and it replaced a `+` that wrote a deal rule.** On the first day of real use the owner made thirty-two look-ups and wrote zero rules — through a form folded behind a small `+` in the watch screen's header, offering three origins, second on the third screen. The most used feature was the hardest to reach and the least used one had the biggest button. Rules did not go away: `/create` is unchanged and is reached from the rules section of the watch screen, where the rules already live. The **From** box takes any of the 3,270 airports, exactly as **To** does, because `RoutePairRequest` stopped restricting the origin on the day this screen was drawn — "what does Barcelona to Palermo cost while I am already in Barcelona" is an ordinary question. The three home airports stay one tap as **quick chips, not a closed list** (nine flights in ten leave from AMS, EIN or DUS), written out in the client rather than fetched, because they are presentation now rather than validation and so have nothing left to disagree with.
- **The pills and the box are two values, and that separation is the fix.** They used to be one — tapping DUS wrote "DUS" into the From box — and the box paid for it: a field arriving prefilled with three capitals and no placeholder is a *read-out*, showing an answer rather than inviting a question, and the empty To box beside it proved the point by contrast. So `home` is the lit pill and is the origin whenever the box is empty, `from` is "somewhere else" and starts empty, and the box **never** mirrors the pill. One rule settles the state no single value could express: **text wins while there is text, pills win on tap** — typing unlights the pills, tapping a pill empties the box, and one computed `origin` is the only place either fact is read, so a screen showing an unlit pill beside typed text cannot send the pill.
- **"Look up" does not touch the network from this screen.** It is a navigation, and a screen that sat spinning for three seconds before it changed would be the app freezing on the page you are leaving. The screen being opened has to handle a route with no fares anyway (a bookmark, a shared link, a lookup made a month ago), so the fetch lives there — one path rather than two. The stated cost: a well-formed code with no airport behind it ("ZZZ") is refused on the detail screen rather than in the form. The **panel-open flag lives on the form, not in the fields**, because a field can only ask "did focus leave me" and the buttons are not in it — a field closing its own panel on `focusout` would move the buttons out from under the pointer between mousedown and mouseup, and no click would ever be produced. **"Add to watch" stays on this screen** rather than pushing to the detail: a route added a second ago has no polls, no history and no opinion.
- **The discovery strip is below the search form, and not on the home globe.** The globe tours the watchlist — what the owner already thought of — and crowding a €27 Marrakesh into it would be two answers to two different questions on one canvas; a person on the search screen is already asking "where could I go". It sits *below* the form because the form is what the tab is for and what muscle memory reaches for, and a strip that pushed the boxes down the screen would be the app deciding it knows better than the person who tapped Search. Six cards, in the server's order.
- **`stores/destinations.js` fetches once and filters in the browser**, because 184 rows of four short strings is a few kilobytes and the list changes when somebody edits a file in the repository, not while an app is open — a `?q=` endpoint here would put a round trip between a letter and the suggestion it should produce. The ranking is **exported as a pure function** rather than living in the component, since it is the part with opinions in it ("bil" means Bilbao before Bilbao's country) and opinions are worth testing without mounting anything. Its typo fallback uses **edit distance ≤ 2 against city names only**: two is one transposition plus one slip, which is what a thumb produces, three starts matching genuinely different places, and codes and countries are deliberately not fuzzed — a three-letter code is two edits from dozens of others, and "Did you mean Spain?" for a mistyped code is a guess with nothing behind it. It runs only when the ordinary search found nothing, so a query with real matches never has a guess mixed in.

### `resources/js/Views/RouteDetail.vue`

- **This is the screen that proves null is not zero** — a route added this morning comes back with `price.current: null`, `stats: null`, `score: 0`, `confident: false`; drawing that as a €0 fare against a €0 usual with a damning red gauge would be the app inventing a deal. Every block on the screen asks whether its own field exists and says "not yet" rather than drawing a confident nothing.
- **Three distinct dates are never allowed to substitute for each other**: `history[].date` is when Orbit LOOKED, `cheapest.date` is when you'd FLY, `cheapest.foundAt` is when the price was actually FOUND — a third date, since Orbit's fares are a cache of other people's searches and the headline number can be days old (the origin story of "€36 shown against a live €56"). The headline `departure` is sourced only from `cheapest.date`, never derived from `history[].date`.
- **This screen owns an on-demand fetch for a route Orbit has never priced**, since "look before you watch" can land here with a pair that has no route row at all: on a 404 or stale fares it POSTs a lookup and adopts the answer. Three rules bound it: a watched route is never refreshed from here (its staleness is a poll to fix, not a screen's job); the wait is bounded by its own timeout (25s); and what's already on screen survives a failed refresh rather than being replaced by an error page.
- **The route code is case-normalised locally, in the display layer**, rather than round-tripping a pasted link's wrong-case code to the server for an inevitable 404.
- **"Seen…" only appears once the cheapest fare is over 24 hours old** — this screen already has a headline, a gauge, a chart, a callout and a button, and a "Seen 2 hours ago" on a route polled this morning would just teach the reader to skip the line where it matters. The threshold matches `orbit.lookup.fresh_for_hours`, though the two are separate decisions that happen to agree.
- **`justWatched` is a separate flag from `meta.watched`** — a route that was already watched when the screen opened says nothing about it; this flag answers a button pressed a second ago, and a button that vanishes silently is a button people press twice.
- **`pctBelow`'s sign is flipped in the caption sentence** (−14 means 14% ABOVE usual) so the text reads naturally. The percentage is silent under a live (Google-checked) headline, since the caption is Orbit's opinion of Orbit's own fare and would otherwise read as an opinion about Google's number.
- **`confident: false` suppresses the percentage comparison, not the usual-price line** — with under a week of a route's own statistics, the current price literally IS the median, so stating "36% below its usual €99" would be placeholder arithmetic read out as a finding. The usual price stays, since it's a fact; only the derived comparison is dropped.
- **An on-demand refresh only fires when a route is BOTH stale AND unwatched** — a watchlist route's staleness is a broken poll to fix, not something worth spending a provider call on from someone's phone.
- **A 404 from the read triggers an on-demand lookup rather than an error state** — no route row is the ordinary state of a pair someone just typed into the search screen.
- **The lookup-failure message's branch order is a deliberate judgement**: a 422 always means "no such route," however it arrived; any other failure, when a price is already on screen, leaves that price alone and reports the refresh as failed rather than replacing readable week-old fares with an error page.
- **`watchRoute()` reuses the exact same store write the add form makes**, which is what keeps the globe's tour and the watch list in step with a route added from this screen.
- **`goBack()` checks vue-router's `history.state.back` before calling `router.back()`** — without the check, someone who opened a shared link straight into a route detail page would be walked out of the app entirely.
- **The "checking" state is visually distinct from the ordinary loading skeleton** — what's actually happening is a fare provider being asked six or seven questions across six months, worth saying out loud (`role="status"`) rather than looking like a broken pulsing page.
- **`confident` is passed to the advice callout for its glyph only, never its words** — the sentence already says "not enough data yet" explicitly, and a tick glyph beside it used to say the opposite.
- **The booking CTA's button variant must never add conditions of its own beyond the server's tone** — a callout saying "wait" over a glowing "book" button is the page arguing with itself, and the button wins that argument by being louder.

### Stores and client libraries

- **`format.js` used to be three files.** `Components/route/format.js`, `Components/calendar/format.js` and `Components/globe/format.js` each carried their own copy of "print a fare", because the screens were written in parallel worktrees that could not create a shared file without colliding; each said so and each pointed at this pass. Fares are **rounded, not truncated** (€57.45 is nearer €57; a fare reading a euro cheaper than it is, is a small lie about a price, and a 45-cent overnight move is not a move), and **null in, null out** — callers disagree about what "no fare" looks like (an em dash on the rail, "No fare yet" on the spotlight card, a sentence on the detail) and the only thing none of them may print is €0. Ages read "just now" under an hour (nothing on these screens moves on a scale where forty minutes matters, and minute precision would make the commonest state the noisiest line), switch from hours to days at exactly 24 — the poll's own period — and a *future* timestamp reads as "just now", because clock skew is a fact of life and "in 2 hours" under a price is a bug report.
- **`stores/airports.js` is a composable, not a Pinia store** — it was a store for exactly as long as the search screen had one input box; a singleton would have the From field repainting itself with whatever was typed into To. The world half of the typeahead is a network search, so everything worth testing is a race (debounce, abort, and the sequence guard, which catches the one case debounce and abort both miss: a request already on the wire when replaced can still resolve after the fact). Curated results always sort before world results in the merged typeahead.
- **`stores/auth.js`'s `resolved` flag distinguishes "don't know yet" from "guest"** — routing on an unresolved session before `/api/me` answers would flash the login screen at someone who's actually signed in.
- **`stores/settings.js` updates are optimistic and honestly reverted** — a failed `PUT` puts the old value back and says why; a silent revert is worse than no optimism, because the switch appears to work and then appears to have been forgotten.
- **`motion.js` centralises reduced-motion handling because `scroll-behavior` CSS doesn't reach a JS-driven `scrollIntoView({behavior: 'smooth'})`** — the JS argument takes precedence over the stylesheet, so the media query has to be asked again in JavaScript.
- **`heat.js` is a plain module, not a Vue component**, so the grid, legend and day sheet all get the same colour for the same fare by calling the same function; its colour ramp is literal (not from `tokens.css`) because it's a data-visualisation scale that must mean the same thing in both themes.
- **`stores/discoveries.js` and `stores/watchlist.js` deliberately have no getters** — each screen's own slice of the list is one line of `computed` where it's needed. `discoveries` starts at status `'idle'` (unlike `watchlist`, which starts `'loading'`) since the deals strip is optional. Refreshes are deliberately not deduped for either store, and rows are never cleared before a refresh, so the screen doesn't visibly jump/rebuild on every revisit.
- **`stores/watchlist.js` folds together three screens (globe, watch screen, calendar) that each fetched `/api/watchlist` independently in parallel branches** — three copies that could disagree the moment one of them wrote (pausing a route on the watch screen left the globe still touring it until reload). `add()` throws rather than setting a store-wide `error`, since the add form phrases its own 422 next to the field that caused it.
- **`stores/rules.js` shares one store for the create screen's live parse and the watch screen's rule list** because a newly created rule has to appear in a list the other screen may already be holding. The 500ms debounce lives in `Create.vue` (a textarea concern); the store only refuses stale out-of-order parse responses. `watch()` reuses the existing add-route watchlist endpoint and returns the new row so the list can splice it in without a re-fetch.
- **`lib/format.js`'s `seenLabel` is the single sentence that shows a fare's age everywhere in the app**, so its failure modes are all quiet ones. A null `foundAt` must never be shown as "just now" — a fabricated claim, worse than showing nothing. Under an hour reads as "just now" rather than a minute count. The hours/days boundary sits at exactly 24 hours, the poll's own period. Day counts round down, not up. A future timestamp (clock skew) reads as "just now," not a negative age.
- **`lib/http.js` is the one HTTP client all requests go through**, so "does the server know who I am" has exactly one answer. Cookie authentication only, no tokens — the session cookie is httpOnly and unreadable from JS, so there's nothing in localStorage to steal. `withXSRFToken` re-reads the XSRF cookie per-request rather than a page-load meta tag, since logging in rotates the session and its token. The 401 interceptor is the one place a dead session redirects to login; `/api/me` and `/login` themselves are exempted, since a 401 from either is an expected answer.

### Database and config

- **`DatabaseSeeder` runs on every deploy, so everything it calls has to be idempotent**, and its call order is not just dependency: `DestinationSeeder` before `WorldAirportSeeder` is what makes "the curated row wins" true by construction, and `DiscoverySeeder` runs last because it scores candidates against the airports table and verifies its shortlist through the price provider — it needs both the world import and reachable fares, and is a no-op unless a FAKE sweep provider is configured. **`route_price_history` holds no fiction**: a deal score is a judgement about real money, so what fills it is not a fixture but the ordinary poller running against whichever adapter `config/orbit.php` selects; `FakeHistorySeeder` refuses outright to run once that adapter is a real one.
- **`WorldAirportSeeder`'s snapshot is committed, not downloaded** — a seeder that runs on every deploy must not depend on somebody else's CDN (licence and filter details in `database/seeders/data/world_airports.README.md`). Curated rows are skipped outright rather than upserted with the snapshot's values, which is also why this runs AFTER `DestinationSeeder`: skipping is cheap, but importing the world first would mean correcting it afterwards, and the order that needs no correction is the one that can't drift. `is_origin` is touched by neither the insert values nor the update column list, so no snapshot refresh can ever add a fourth origin or unset one of the three — that fact is a person's, not an airport's. Every CSV row is checked rather than trusted, and the checks are for the NEXT refresh, not this one: an OurAirports export that grew a column or carried a four-letter code would otherwise reach Postgres as a bare `value too long for type character(3)` halfway through a deploy's seed, a message that says nothing about which file or line.
- **`WatchlistSeeder`'s six demo routes are the design's own set** (design/README.md §1, "6 routes orbiting"), replaced the moment the owner adds their own — nothing else in the app treats them specially. Routes are facts and watchlist rows are choices, seeded differently on purpose: the route is `updateOrCreate`d, since "AMS-LIS is Amsterdam to Lisbon" cannot become wrong, but the watchlist row is `firstOrCreate`d, since `active` is the owner's own toggle and a deploy that silently un-paused every route they'd paused would be the app arguing with its user.
- **`FakeHistorySeeder` is allowed to exist despite the day-1 honesty rule because that rule is about REAL providers** — a Travelpayouts price on 3 June is a fact recorded or not, and no arithmetic recreates it, but `FakeFareModel` is a pure function of (route, departure date, observation date), so "what would we have recorded on 3 June" has one exact answer and computing it is reconstruction, not invention; `trackingDays` still derives from the earliest row actually present, so nothing downstream is told a different story than the database holds. It refuses to run against a real provider, which is what keeps that distinction from eroding the day a real key lands in `.env`. It replays the past by moving the application clock and running the ordinary poller sixty times per route, never by writing rows directly — a second implementation of "what does a day's observation contain" is a second thing to keep in step with `PollRoutePrices`, and the copy only the seeder uses is the one nobody notices going stale. It also polls today unconditionally, separately from the backfill, which is what makes it safe to leave in a deploy script: a stack down for a week comes back with current fares whether or not it already has history.
- **`DiscoverySeeder`'s guard checks which price-provider adapter is bound, not "am I in a test"** — that is the fact that decides whether a run costs money. An unguarded seeder would spend ~38 metered Travelpayouts requests and 2% of a monthly SerpAPI allowance on every deploy, on top of whatever the 06:00 poll hour is already doing, with no budget written down for it anywhere; on a real sweep provider it does nothing, and the 05:20 schedule (which the budget table in `config/orbit.php` does account for) fills the table instead. It runs the ordinary `DiscoverDeals` job synchronously rather than hand-writing a `discoveries` row, so a threshold that quietly stopped admitting anything fails on a sandbox rather than screenshotting a card no version of the funnel actually produced. **The relative lane needs baselines pre-warmed or it is invisible on a fresh box**: that lane reads remembered per-route medians a real box builds up over a fortnight of nightly exploration, so an un-warmed sandbox surfaces nothing from it — the honest first-run shape, but not one the browser gate can screenshot. The seeder measures forty routes' baselines (~a fortnight's worth) through the same `PriceProvider` port and window width the job itself uses, never a fabricated number — a hand-written "€110 usual" next to a €60 fare would be the same shape-not-feature trap the no-hand-written-row rule above already avoids. The candidate pool is ordered by route code and capped, so the same forty routes are measured on this box, in CI and after a fresh `docker compose down -v` — the determinism rule every fake in this app follows, and what lets the browser gate assert a relative card is on screen at all.
- **`airports.iata` is the real key and the surrogate id is a convenience**: the code is what the URL carries (`/route/AMS-LIS`), what the provider APIs speak, and what the design prints on the boarding-pass rows. It is unique so a second "AMS" cannot be created by a careless seeder run and quietly split a route's history in two. `lat`/`lng` are **doubles, not decimals** — they are read straight into the globe's camera and great-circle maths, where they are floats anyway, and a decimal column would only mean Eloquent handing the client a string JavaScript has to parse back.
- **Neither `discoveries` nor `discovery_baselines` carries a `user_id`**, unlike `alerts` and `watchlist_items` — what a route usually costs, and the fact that it is cheap this morning, are facts about the world rather than about an account's relationship to it. `discovery_baselines` also stays one number per route rather than growing into a `routes`-keyed window (the migration says what breaks if it does).
- **What the fare-table migrations decided.** `routes.code` ("AMS-LIS") is denormalised from the two airport ids on purpose — it is what the SPA's URLs carry, what the provider adapters are asked about, and what makes a log line readable without resolving two joins — and its unique index doubles as the guard against the same pair being inserted twice. `routes` has **no `active` column**: whether a route is watched belongs to `watchlist_items`, since rules surface routes nobody has ever watched and those still need a code and a history. `watchlist_items` is **user-scoped even though there is one user** (the column is the difference between "add a second account" being a migration and being a rewrite of every query), `active` is a pause that keeps the history already gathered, and `position` is what "the owner's order" means at all. `calendar_fares` keeps **one row per (route, departure_date), overwritten every poll** — Orbit does not keep the history of what next June looked like in April, which would be ninety new rows per route per day for a chart nobody drew — and `fetched_at` is a column on the row rather than `updated_at` because it is a fact about the *fare*, which the UI shows, not about the row. `route_price_stats` writes its five columns **in the order they sort**, and `PriceStats` refuses to be constructed out of order: a p25 above the median would make the score reward expensive fares, silently, forever. `return_fares` stores `nights` and derives `return_date` (one fact, one place; `nights` is also the query axis and indexes as a plain integer) and types it unsigned so a negative stay is refused by the column while a same-day return, which is a real fare, is not.
- **`destinations` is a separate table from `airports`** because geography and editorial "vibe" judgement change for different reasons and at different rates; `vibes` is a JSON array (not a tags table + pivot) because nothing joins on it and the vocabulary is closed to nine words.
- **`warmth` is a 1-5 rating, not a temperature** — the app answers "is it sunny in spring," and putting that judgement in degrees would just move the same editorial call into a query.
- **`user_settings` is a separate table from `users`**, keeping Laravel's own authentication table narrow; it enforces one row per account via a unique constraint on `user_id`, and its defaults live in the migration alone. Quiet hours are stored as wall-clock local time — the one deliberate exception to "everything else is UTC." The row is created on first read (`UserSettings::for()`), not by a seeder, so an account that never opened the settings screen still has settings to read; both settings actions answer with the same body, which is what makes the screen's optimistic switches safe.
- **`config/mail.php`'s `markdown` block is three separate landmines**: without `paths`, `Illuminate\Mail\Markdown` resolves the `mail::` namespace to the framework's own copy in `vendor/` and silently ignores every file in `resources/views/vendor/mail` (the symptom is Laravel's stock grey-box layout in somebody's inbox, not an error); `theme` names `resources/views/vendor/mail/html/themes/orbit.css`, which the reader never receives — it is inlined onto the markup by `TijsVerkoyen\CssToInlineStyles` and discarded, and that file's own header explains why media queries and nested comments both fail silently inside it; and there is no `env()` in the block on purpose, because staging and production must send identical mail or the design was only ever reviewed in one of them.
- **`config/logging.php`'s `mail` channel is the one channel Orbit added**, and its `level` is a literal rather than `env('LOG_LEVEL')` on purpose: it is the fix for the swallowed staged rollout above — a channel whose only records are DEBUG must not take its floor from the variable set for the application log. It writes to its own file (`storage/logs/mail.log`, the path the deploy runbook tails) because one send is a full MIME message tens of lines long and interleaving those with the app's errors makes both unreadable. It is reached through `MAIL_LOG_CHANNEL`, `config/mail.php`'s `log` mailer.

### Tests, seeders and fixtures

- **Every factory's `definition()` returns `array<model-property<T>, mixed>`, not `array<string, mixed>`** — Larastan's parent declares the narrower type, so the stock signature is a widened return PHPStan flags, and the narrow one turns a typo in an attribute name into a static-analysis error instead of a factory that silently seeds nothing.
- **`PasswordChangeTest::asANewProcessWould()` resets state php-fpm resets between requests but a test process shares** — session, resolved guards, and the DEFAULT GUARD NAME. `auth:sanctum` calls `Auth::shouldUse('sanctum')` on authentication, writing into `auth.defaults.guard` in the shared config repository; left alone, the next request's `current_password` rule asks Sanctum's guard to validate a password, which it cannot do, and a correct password is refused. Production reads that key off disk fresh every request and never sees it.
- **`Tests\TestCase::setUp()` calls `Http::preventStrayRequests()` for every test in the suite, not just the ones that seemed to need it.** A deployed `.env` once leaked into the runner, `ORBIT_PRICE_PROVIDER=travelpayouts` made the container hand the real fare adapter to `PollersTest`, and a gate run went out and billed a real API to prove the app worked. `.env.testing` pins the fakes; this closes the door behind it — a test that legitimately exercises an adapter fakes its endpoint (`Http::fake([...])`) and says so. It does not cover the Anthropic SDK, which carries its own PSR-18 client and never touches Laravel's HTTP factory; that path is closed by `ORBIT_NLP_PARSER=regex` in `.env.testing` instead, and `AnthropicRuleParserTest` injects a fake transporter rather than a key.
- **`Tests\Support\RecordingLogger` exists instead of `Log::spy()`** because Mockery's spy can only assert "received at least once" — it cannot say "exactly once across nine failed requests," the assertion `TravelpayoutsPriceProviderTest` needs for a rate-limited warning. A recorded list of what was said is a better fixture than a mock's expectation grammar.
- **`Tests\Support\SpyPriceProvider` exists instead of `FakePriceProvider`** because the fake is a deterministic model of a fare market (answers every day, plausible prices) and cannot answer what `RouteLookupTest` needs: how many times the provider was called. A lookup that silently re-fetched a route priced an hour ago looks identical through the fake and is six or seven metered requests a day per curious tap in production; the counter is the point, the fares are whatever makes the assertion readable.
- **`AuthenticationTest` checks the absent multi-user routes against the route table, not against a 404** — `routes/web.php` answers every unclaimed GET with the SPA shell, so `GET /register` is a 200 regardless; asserting nothing is *registered* at that path fails immediately and names the route if a starter kit or refactor ever adds one, where a status-code check would only notice once the response happened to change. The POST half is 405, not 404, for the same reason: the catch-all claims the URI for GET, so the router refuses the verb rather than the path.
- **`RulesApiTest` and `WatchlistWritesTest` fake the queue for the whole file** — creating a rule or adding a route dispatches a sweep/poll job, and under the test runner's `sync` connection that would run inline and price routes through the fake provider before the response is even asserted on, testing the fake provider rather than the endpoint. It also matches production structurally: the job goes to Redis and the response is written first, before a worker ever picks it up.
- **`SingleUserSeederTest` cares about idempotence above all** — this seeder runs on every deploy, and getting the "already exists" path wrong silently rotates the owner's password during a release, discovered only as a locked-out login.
- **`SeedersTest` asserts three separate drift guards**: config origins vs. the seeder's `is_origin` flags, the NLP parser's vibe vocabulary vs. the seeder's actual tags, and every origin alias resolving to a real origin — each a silent-forever bug if it drifts. Its snapshot-shape test exists for the NEXT `world_airports.php` refresh (`world_airports.README.md`), so a bad refresh fails there by name instead of mid-production `db:seed`. `the_fake_history_seeder_backfills_and_then_leaves_it_alone` asserts the clock against a captured `$frozen` value rather than a literal date (which would rot), because `FakeHistorySeeder`'s `finally` block restores the test clock rather than clearing it — `Date::hasTestNow()` stays true afterwards, and this is what proves no backfill date leaked into "now".
- **The curated and world-imported airport seeders write disjoint sets on purpose** — the import never overwrites a curated row (Amsterdam's proper name vs. OurAirports' "Schiphol"), so `is_origin` can never be silently added or removed by a snapshot refresh.
- **`DestinationSeeder`'s two data files are an editorial split, not a technical one** — `european_destinations.php` is the short-haul list the app was built around, `world_destinations.php` the long-haul tranche world flights added; same shape, argued with separately, kept apart so a single 300-row file isn't one nobody reads before editing. A climate profile name may be reused across the two files but not redefined with different ratings — `continental` means the same twelve numbers everywhere, and a silent second definition would give one word two meanings. The seeder is idempotent and non-destructive: it updates facts and creates what's missing on every deploy, and never deletes, since an airport that leaves the list still has routes and price history hanging off it.
- **`BuildsRouteData::trackedSince()` writes exactly one old observation, not a series** — a multi-day fixture would hand the scorer a computable trend and move every test's score in ways nobody could explain on paper. `BuildsAlertData` does the same for alert-scoring fixtures.
- **`BuildsRuleData` fixtures use a handful of destinations, never the seeder's 77** — rule-matching tests ask whether a rule finds the right routes, a question best asked of places whose vibe and climate a reader can hold in their head ("FAO is sunny and warm, OSL is cold"), not "the eleventh of the med-south group."
- **`AlertPipelineTest` freezes the clock at 06:55 UTC (08:55 Amsterdam), five minutes after the default quiet window ends** — the ordinary tests run at that boundary rather than in the middle of the afternoon, where a timezone bug would hide. `brandNewRoute()` deliberately gives its fixtures the FULL set of statistics, so each one really does score 94 before the maturity gate holds it — nothing in the day-1 tests depends on degenerate day-1 statistics also happening to produce a low score.
- **`RunsCommands::runCommand()` exists because `$this->artisan()` is typed `PendingCommand|int`** — it answers an int only when console output is mocked, so the assertion helpers live on one side of the union and every call site would otherwise have to narrow it itself. Narrowed once, here.
- **`SpaShellTest` runs `withoutVite()`** because these tests are about routing, and requiring a built bundle would fail a fresh checkout for an unrelated reason.
- **`ResetHistoryTest`'s two negatives are the point**: the command must not run without `--confirm`, and must not touch anything the owner decided (watchlist, rules, alert ledger) — the ledger especially, since wiping it would let the next poll re-announce every deal already mailed.
- **`e2e/specs/live-price.spec.js` intercepts the app's own API for 3 of 4 tests** because the sandbox structurally cannot hold the states under test (nothing is old enough to demote, there's no SerpAPI key and must never be one) — the endpoint itself is proven against recorded fixtures in `LivePriceCheckTest`; the browser only needs to prove it *draws* the documents.
- **`e2e/specs/search.spec.js`'s NRN (Weeze) journey is deliberately an airport with no `is_origin` flag** — the honest test of "any airport can now be looked up," going through the server three times (search, 404, lookup) to prove the watchlist count doesn't quietly grow.
- **`e2e/specs/detail.spec.js` asserts the freshness line's *absence*** on purpose — the sandbox's fares are all fresh, so "no line" is what proves the staleness threshold exists rather than always firing.
- **`AirportSearchTest`: the seeded tests at the bottom matter most** — everything above proves the SQL against a handful of factory rows; the seeded ones prove that typing "Tokyo" against the real 3,270-row snapshot finds Tokyo (specifically Haneda, not Narita). `%`/`_` LIKE wildcards are both escaped, the one injection-shaped thing a search endpoint has.
- **`AnthropicRuleParserTest` is driven through a mock PSR-18 transporter, not a mock of the SDK's `messages->create()`** — the cases worth testing (a refusal, a truncation) are things the SDK itself deserialises, so mocking the SDK's own client would only assert the mock returns what it was told to.
- **`BookingLinkTest` is worth its own file because every failure here is silent**: a swapped day/month opens a perfectly working link that searches the wrong date; mismatched IATA casing searches a different place entirely (Travelpayouts' own docs: `ROc1` is Romania business class, `ROC1` is Rochester economy); none of these is a 500 and none would appear in a log. The two sites' casings are opposite and both matter (Skyscanner lower-case path, Aviasales upper-case and case-sensitive params).
- **`DestinationsApiTest`: 184 curated destinations and 187 airports (184 + 3 origins) are deliberately different numbers**, not a red-test bump — keyed off the `destinations` table rather than `is_origin`, to keep curated places, origins, and unrelated OurAirports rows from being conflated.
- **`MailLogChannelTest` exists because a staged rollout (`MAIL_MAILER=log`) was silently swallowing mail**: Symfony's log transport writes at DEBUG, while production's default channel floors at `LOG_LEVEL=info`, so every "sent" alert was rendered, handed to Monolog, and dropped with no error anywhere.
- **`MailRenderTest` exists despite mail being "judged by looking"** because the markdown mailer has silent failure modes that all render valid-looking HTML with the wrong content: `mail.markdown.paths` unset silently swaps in Laravel's stock layout; a theme file's own comments can delete the media-query rule below them; a banner resolved via `asset()` bakes in whatever `APP_URL` was at send time.
- **`PwaShellTest`: the three PWA-critical routes (manifest, service worker, offline page) break in three ways, none of them loud** — shadowed by the SPA catch-all (registered before them); sessioned (a Set-Cookie per navigation kills edge caching); or simply wrong about the current build. Cache-Control is asserted directive-by-directive, since Symfony reorders the header string.
- **`RuleSweepTest`: the assertions are mostly about budget** — a vibe-less rule sweeps 231 provider calls uncapped, the difference between a feature and an outage. Uses `handle()` directly rather than `dispatchSync()` under `Queue::fake()`, since a faked queue records a sync dispatch without running it.
- **`TravelpayoutsPollTest` pins `orbit.poll.window_days` to 90, not production's six months** — its four fixture files are a recording of four real calendar months of AMS-LIS on 2026-08-15, and the numbers it asserts (79 covered days, €80 cheapest) are facts about that recording; asking a six-month window for months nobody recorded would fail `Http::preventStrayRequests()`, correctly, since the alternative is inventing fares and asserting on them. How wide the window is belongs to `PollersTest` and the budget assertion in `TravelpayoutsPriceProviderTest`. Its re-poll test builds both HTTP responses into one `Http::sequence()` rather than two separate `Http::fake()` calls — a second `fake()` for a URL that already has a stub does not replace it, so a "next morning" response would silently be the previous one.
- **`WorldFaresTest` exists separately from `TravelpayoutsPollTest`** specifically to check the world-airport claim against the real API before shipping it. Travelpayouts answers AMS-JFK with destination `"NYC"` (a city code) — the adapter must read only `depart_date`/`value`/`actual` from an entry and never trust the echoed origin/destination.
- **`CandidateScorerTest` and `RelativeLaneSelectorTest` use real rows from the actual 2026-08-16 sweep, not invented numbers** — the config thresholds were chosen by looking at this exact data. Defaults are written out rather than read from config, since a test reading the file it's checking could never catch a drifted default (`DiscoveryRunTest` is the feature-level guard that config and these hard-coded values still agree).
- **`RegexRuleTextParserTest`'s first test is the literal contract**: design/README.md's example sentence must produce its six documented chips exactly, since this app ships that sentence pre-typed into the textarea. It loads the real `config/orbit.php` vocabulary, not a test-only one.

### Dependency injection wiring (`AppServiceProvider`)

- **The fare-port bindings' "unknown name throws" rule is §14's; the AppServiceProvider-specific detail is the split**: round trips and the origin sweep are each bound as their own switch, even though their real adapters share a vendor/endpoint with another port, because their coverage and failure modes differ and each must be able to fail or be disabled independently.
- **`GoogleFlightsCheck` is bound directly, not chosen by config name**, unlike the fare ports — there's nothing to choose between (SerpAPI is the one thing that exists), so the switch that matters is simply whether the key is present. It's bound fresh, not as a singleton, since it holds no state between calls. An empty `SERPAPI_KEY` string is read as unset, the same convention `seed.password` uses.
- **Discovery's funnel numbers convert euros to cents at this config boundary**, since everything below HTTP is integer cents; `max_eur_per_km` is the one to double-check (×100 turns €/km into cents/km). The relative lane's `min_discount` is its own value, not folded into `DiscoveryPolicy` (two products, not one lane), and needs no conversion — it's a fraction (0.40 = 40%), not a euro figure. The €15 minimum-savings floor is shared between the absolute and relative lanes by being passed the same config value, not independently declared.
- **`minTrackingDays` is passed to both `DealScorer`/`ScoringPolicy` and `AlertPolicy` from the same config key on purpose** — "young enough that we won't mail about it" and "young enough that we won't put a verdict on it" have to be one decision, or the screen and the alert engine could disagree about the same morning. `DealScorer`, `ScoringPolicy`, `AlertPolicy` and `SelfStatsProvider` are all handed scalars read once here rather than calling `config()` themselves, the same boundary as `RuleVocabulary` (§11) — it's what lets `App\Domain` stay pure PHP and a test set the immature end of a blend directly rather than seeding a year of history.
- **`DealNotifier` is bound directly (not by config name), like `GoogleFlightsCheck`** — mail isn't one of two ways to send an alert, it's the only one that exists yet.
- **The real Travelpayouts adapters (price, returns, sweep) each throw from their own constructor when the token is missing** — a box configured for `travelpayouts` with no token must fail loudly at the first poll. They share the vendor's connection settings but keep their own behaviour settings separate, since duplicating the connection half would mean a token rotation that half-worked. The origin-sweep adapter's `limit` deliberately reads `orbit.returns.limit`, not a separate `discovery.limit` key — it's the same parameter on the same endpoint whose undocumented default (30) once silently discarded 91% of a route's fares, and a second copy is a second place to drift.
- **The Anthropic-backed rule parser is given an explicit PSR-18 transporter with its own timeout**, rather than left to `php-http/discovery` — the SDK's own `timeout` option is advisory and its source never reads it, so only an explicitly-supplied client's timeout actually stops a hung request from leaving the create screen on a spinner forever. `http_errors => false` lets the SDK read the HTTP status itself and turn it into a typed exception, rather than Guzzle throwing first and replacing the API's own error text with a stack trace.
- **`NotificationSent` (not the hand-off to the queue) is what stamps `delivered_at`**, registered explicitly in `boot()` rather than relying on Laravel's listener-directory auto-discovery, since this app has no `app/Listeners` directory for that convention to find.
- **The rule-parser throttle (20/min) exists before it's needed** — today's regex parser could take any volume, but the day an Anthropic key lands in `.env` the same route becomes a metered request per keystroke, and nobody will remember to add a limiter that day.

### Build tooling (ESLint, Vite)

- **`eslint.config.js` shipped before there was any Vue code**, matching every `.js`/`.vue` file from day one so PR4's first file was linted the moment it was written — a blank white screen on a phone is the worst failure mode this app has, and it never shows up in `php artisan test` or the nginx log. There's deliberately no Prettier: the same one-enforcer-not-two reasoning the PHP side uses for Pint, since a formatter and a linter that both have opinions about line breaks eventually disagree. `design/**` is excluded because `design/support.js` is the prototype's own runtime, committed verbatim as a reference and never loaded by this app — linting it produced 37 findings in code that isn't ours to reformat. `e2e/artifacts/**` is excluded because a `scripts/e2e.sh` run leaves Playwright's own HTML report behind, which ships its own minified trace-viewer bundle — linting it is 8,414 findings about code neither written nor fixable here, i.e. a gate red exactly when the browser gate has run and green otherwise; `e2e/specs`, `e2e/fixtures.js` and `e2e/playwright.config.js` are ours and stay linted.
- **`caughtErrors: 'none'` on `no-unused-vars`** exists because a PWA's storage helper survives a browser that refuses IndexedDB via `catch (e) { /* ignore */ }`, an unused caught error used on purpose. `no-console` allows `warn`/`error`/`info` only — all three are reporting levels for a fault the app decided not to throw on (a globe texture that failed to load still needs a trace), never leftovers; a bare `console.log` ships to a phone and stays there.
- **`vue/html-indent` MUST stay on for the autofixes around it to be safe** — `vue/first-attribute-linebreak` and `vue/html-closing-bracket-newline` both fix by inserting a line break and ask this rule where it starts; with it off they still fire and still fix, landing at column zero (a `class=` and a bare `>` hard against the left margin). This is a DO-NOT-TURN-OFF landmine, not a style preference. `vue/max-attributes-per-line` and `vue/singleline-html-element-content-newline` are off because Orbit's UI is largely small labelled numbers (`<span>{{ price }}</span>`) and both rules would triple a card's vertical size for no gain in clarity; `vue/html-self-closing` is off because it's a taste question that only matters when it churns a diff.
- **`vue/require-default-prop` is off for `resources/js/Views/**` only** — a route-level view is mounted by vue-router and nothing else, so its props come from the route's params and the store rather than from a parent with its own defaults, and a client-side default there is a second source of truth that can only disagree with the first; the rule stays on for `resources/js/Components`, where a component genuinely is mounted from several call sites. `vue/multi-word-component-names` is off there too — a view's file name IS its route (`Watchlist.vue` ↔ `/watchlist`), matching the tab bar's own names, and renaming to satisfy a rule about custom-element collisions (which can't happen for a component never written as a tag) would break that mapping for nothing.
- **`vite.config.js` sets `emptyOutDir: false` because Vite's default (wipe the output directory on every build) is a dead-page problem for this app, not a stale-asset one**: a page left open across a deploy is already running its entry chunk and looks fine right up to the first lazy import — every screen is one, including the 1.9MB globe — so the chunk 404s and the tab bar silently stops working; a document served from any cache naming a deleted entry chunk is a script tag pointing at a 404, a blank screen behind a 200. Keeping old files costs a few hundred kilobytes per build and lets a briefly-stale reference resolve instead of dying; `build:retain` (03:10 daily, see the scheduling table) keeps the newest three builds from its own ledger and deletes the rest, so a forgotten deploy step is a day of extra chunks rather than a full disk. The build runs in a container, never on the host, writing `public/build/` through the bind mount as uid 115 — the same uid php-fpm and the nginx sidecar read it as. The `vue` alias is pinned to the ESM-bundler-with-compiler build explicitly, not left to the package's `exports` map, so every import of `vue` (ours, Pinia's, vue-router's) resolves to ONE copy — two copies in a bundle is the classic "injection not found" fault, invisible until a store is read from a component the other copy created. `transformAssetUrls` disables Vue's `src=""` rewrite for root-relative paths, since anything under `public/` is served by nginx as-is and has no build entry to resolve to. Vitest's `include`/`exclude` name `resources/js/**/*.test.js` and exclude `vendor/**` explicitly, because Vitest's default sweep treats the whole project (including `vendor/`) as fair game, and a PHP package that happens to ship a JS library with its own test suite (`anthropic-ai/sdk` pulls in `standard-webhooks`) would otherwise fail `npm run test:js` on a dependency this app never installed.

---

## Where the rules live

| concern | code |
| --- | --- |
| deal score, verdict, advice | `app/Domain/Pricing/` |
| statistics arithmetic | `app/Domain/Pricing/PriceStats.php`, `app/Infrastructure/Pricing/SelfStatsProvider.php` |
| alert decisions, quiet hours | `app/Domain/Alerts/` |
| rule matching, month windows, chips | `app/Domain/Rules/` |
| discovery thresholds, ranking, Google verdict | `app/Domain/Discovery/` |
| the live price check and its cooldown | `app/Application/Routes/LivePriceChecks.php` |
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
