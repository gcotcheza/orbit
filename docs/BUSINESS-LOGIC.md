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
Routes also arrive without anybody watching them: `SweepRuleFares` creates the
pairs a rule is about. Nothing shows them until a rule matches one, and the
route-detail screen is deliberately *not* scoped to the watchlist.

**Adding is asynchronous.** `POST /api/watchlist` queues `PollRoutePrices` and
`RefreshRouteStats` and answers before either has run, so a brand-new row is
`confident: false` with no prices — see §8.

**Origins are closed, destinations are not.** `config('orbit.origins')` is
`['AMS', 'EIN', 'DUS']` and `AddWatchedRouteRequest` accepts nothing else as an
origin: a fare from Málaga is not a flight this person can take. The
destination may be any row in `airports`. What the *form offers* is narrower
still — `GET /api/destinations` returns the 77 places that have a `destinations`
row — and that is deliberately not the validation list, because a code somebody
types from memory should still work.

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
fares other people's searches already found: 41–87% of the 90-day window across
the six seeded routes when this was measured. A date with no fare is **absent**,
never zero-priced, and every screen has always handled a gap.

**The currency is checked, not assumed.** The response envelope's `currency`
field is verified before any arithmetic, because the failure it guards against —
the API answering in roubles, its documented default — is silent, and "€92"
that is really ₽92 is a fare Orbit would shout about. `value` is whole units, so
cents are a multiplication.

**One month may fail and the poll still counts.** The 90-day window is four
month-matrix calls; the adapter tolerates one failing, because three months of
calendar is worth more than none. That tolerance is exactly why stale cells are
pruned by age rather than by absence from a response — see §4.

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
| window | 90 days ahead (≈91 dates, today inclusive) | `orbit.poll.window_days` | `App\Jobs\PollRoutePrices` |
| stagger | 3 minutes between per-route jobs | `orbit.poll.stagger_minutes` | `App\Console\Commands\PollFares` |
| stale-cell prune | 3 days without a refetch | `orbit.poll.stale_after_days` | `PollRoutePrices` |
| scope | routes with an **active** watchlist row | — | `Route::onWatchlist()` |

`orbit:poll-fares` is a fan-out: it queues one `PollRoutePrices` per actively
watched route, delayed by `index × stagger`, so six routes trickle over fifteen
minutes rather than arriving as a burst against a per-minute rate limit. Nothing
that talks to a rate-limited third party runs inside the scheduler process.

**One provider call, two writes.** Each job upserts the whole window into
`calendar_fares` *and* one row into `route_price_history` — that morning's
cheapest fare anywhere in the window. Splitting them would double the provider
calls for the same data.

**Idempotent per day.** Both writes are upserts keyed on a date, so a retry, a
manual run or a re-seeded deploy overwrites the day's figures instead of adding
a second point and bending the trend.

**Three deletions, and they are not the same deletion:**

1. **Departures that have gone by** are removed on every successful poll —
   otherwise the table grows a permanent tail of flights nobody can take, and
   the "cheapest this month" banner would happily point at one.
2. **Future dates that have stopped being quoted** are removed once they are
   `stale_after_days` old. An upsert only ever writes the dates the provider
   named this morning, so a date that had a fare last week and none now would
   keep that fare forever, with nothing in the API marking it. It would colour a
   heatmap cell, be eligible as the "cheapest departure" a booking link points
   at, and be matched against by a deal rule — which is this app mailing
   somebody about a flight that cannot be booked, the one thing it must never
   do.
3. **Nothing at all** when the provider answers with an empty list. The job
   returns before both deletions, so a provider that is down erases nothing.

**Three days, and by staleness rather than by absence.** The poll is daily and
the deletion is one-way, so two consecutive failed mornings — or a date simply
missing from the cache for a day — must not cost the calendar a cell it would
have got back. And because the adapter deliberately tolerates one of its four
monthly calls failing, the job cannot tell "that month is empty today" from
"that month's request 500'd"; deleting every unnamed date would blank a quarter
of the calendar every time Travelpayouts hiccuped.

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
| **cross-sectional** | the ~91 `calendar_fares` of the current window | from the **first** poll | what a typical departure date on this route costs right now |
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
| longitudinal reach | 365 days | `orbit.selfstats.history_days` |

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
across the next three months*; from day 30 it is *what the cheapest fare on this
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
| 2 | **cooldown** — 24h per route per kind per rule | `cooling-down` |
| 3 | **further drop** — ≥ 5% cheaper than the last alerted price | `superseded-by-drop` |

`AlertDecision` is an enum and the **case is the reason**. "Nothing was sent
this morning" is the hardest state this app has to explain to itself — the score
may have been a point short, the route may be too young, the same route may have
been announced yesterday, or there may have been nothing at all — and a bare
`false` would collapse four very different mornings into one. `fires()` is true
for `fired` and `superseded-by-drop`.

Maturity is answered **before** the threshold so the reason is right: a route
held there is a route Orbit has not learned anything about, and "below
threshold" would read as "we looked and it was ordinary".

### route_deal vs rule_match — the asymmetry

`AlertCandidate` has two named constructors and the difference is exactly which
fields are null:

| | `watchedRoute()` | `ruleMatch()` |
| --- | --- | --- |
| `score` | the route's deal score | `null` |
| `trackingDays` | the route's observations | `null` |
| gated by maturity | **yes** | **no** |
| gated by sensitivity | **yes** | **no** |
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
`orbit.nlp.vibe_labels`, and of the vibes in
`database/seeders/data/european_destinations.php`. `tests/Feature/SeedersTest`
asserts the three agree.

The **values** of `vibe_words` are the open half: what somebody might actually
type. Adding a synonym is safe; adding a **key** is not, because no destination
carries it and the rule would match nothing. Longest phrases first within a
vibe, so "city break" is not eaten by "city".

The seed data is 77 destinations and 3 origins, each destination carrying a
climate profile expanded into twelve monthly warmth ratings (1 "pack a coat" to
5 "beach"). It is a checked-in file rather than an API because nobody sells "is
Faro sunny in March" in a usable form and the answer does not change.

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

**The cap is the point.** A rule with no vibe at all is 3 origins × 77
destinations = 231 provider calls, spent on a sentence somebody may delete a
minute later. The cap keeps the best-fitting thirty — "best" is the matcher's
ranking — and logs what it dropped.

**Already-priced-today routes are skipped *before* the cap is applied**, not
after: the cap is a budget for provider calls, and spending it on routes the
06:10 poll already fetched would mean a rule overlapping the watchlist never
reaches its own tail. This is also why the sweep runs *after* the poll.

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

**Orbit does not sell flights and is not going to.** "Book this" is a Skyscanner
deep link — no API, no key, no agreement — landing the user on the same search
that was scored.

| what | value | config key | code |
| --- | --- | --- | --- |
| base | `https://www.skyscanner.nl/transport/flights` | `orbit.booking.skyscanner_base` | `App\Application\Routes\BookingLink` |
| path | `/{origin}/{dest}/{yymmdd}/`, lower-case IATA | — | ditto |
| undated form | `/{origin}/{dest}/` = "show me the whole month" | — | ditto |

The undated form is the right fallback for a route with no fares yet. The route
detail sends a resolved `bookingUrl` for the cheapest departure; the calendar
sends `meta.bookingUrlTemplate` — the same link with `{date}` left as a hole —
because the day sheet books **whichever** day was tapped and only the client
knows which. Sending 31 URLs would repeat the same prefix down the month;
sending none would mean the client hard-coding the host, the path shape and the
lower-casing. The template is always present, including for an empty month: it
is a fact about the route, not about the fares.

**`TRAVELPAYOUTS_MARKER` is read and sent to nobody, by design.** It identifies
whose link a *booking* came from and the data API has no use for it — today
nothing Orbit sends anybody is monetised. It is read in exactly one place so
that the day those links move to Aviasales, the number already lives somewhere
obvious rather than needing a fresh hunt through a dashboard.

---

## 13. The daily timetable

Every time is **Europe/Amsterdam**, from `config('orbit.timezone')`, in
`routes/console.php`. Storage is UTC and always will be — but "06:10" is a
statement about the owner's morning, and without the timezone it would drift an
hour twice a year and poll at 08:10 through the summer, after they have already
looked at their phone. Every entry is `withoutOverlapping()`.

| when | command | why that time |
| --- | --- | --- |
| **06:10 daily** | `orbit:poll-fares` | before the owner is awake, after the airlines' overnight fare loads have settled. Fans out per-route jobs at a 3-minute stagger |
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

Two ports chosen by name in config and bound in `AppServiceProvider`. An
**unknown name throws at resolution** rather than falling back, because a box
quietly serving invented prices would send real alerts about fares that do not
exist.

| port | env | values | adapter |
| --- | --- | --- | --- |
| `PriceProvider` | `ORBIT_PRICE_PROVIDER` | `fake` (default) \| `travelpayouts` | `FakePriceProvider` \| `TravelpayoutsPriceProvider` |
| `PriceStatsProvider` | `ORBIT_STATS_PROVIDER` | `fake` (default) \| `self` | `FakeStatsProvider` \| `SelfStatsProvider` |
| `RuleTextParser` | `ORBIT_NLP_PARSER` / `ANTHROPIC_API_KEY` | `regex` (default) \| `anthropic` | `RegexRuleTextParser` \| `AnthropicRuleTextParser` |
| `DealNotifier` | — | mail | `MailDealNotifier` |

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

## Where the rules live

| concern | code |
| --- | --- |
| deal score, verdict, advice | `app/Domain/Pricing/` |
| statistics arithmetic | `app/Domain/Pricing/PriceStats.php`, `app/Infrastructure/Pricing/SelfStatsProvider.php` |
| alert decisions, quiet hours | `app/Domain/Alerts/` |
| rule matching, month windows, chips | `app/Domain/Rules/` |
| assembling what the screens read | `app/Application/Routes/`, `app/Application/Rules/` |
| the alert pipeline | `app/Application/Alerts/` |
| the ports | `app/Application/Ports/` |
| every tunable number, with its reasoning | `config/orbit.php` |
| the schedule, with its reasoning | `routes/console.php` |
| destinations, vibes, climate | `database/seeders/data/european_destinations.php` |

The domain classes are pure PHP with zero framework imports and are unit-tested
under `tests/Unit/Domain/` — if a rule in this document is not obvious from the
code, that test is where it is pinned.
