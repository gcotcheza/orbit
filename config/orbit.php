<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Orbit
|--------------------------------------------------------------------------
|
| Everything about THIS app rather than about the framework it runs on. It
| starts small — the one account — and is where the fare providers, the deal
| score's weights and the alert thresholds land as they arrive.
|
| WHY THIS FILE EXISTS AT ALL, rather than env() being read where it is
| needed: `php artisan config:cache` compiles every config file into one array
| and then env() returns NULL for everything, because the .env is no longer
| loaded. A seeder that reads env() directly therefore works perfectly in
| development and silently creates the DEFAULT user on a cached production
| deploy. Config files are the one place env() is safe, which is exactly what
| Larastan's `noEnvCallsOutsideOfConfig` rule is pointing at.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | The single account
    |--------------------------------------------------------------------------
    |
    | Read by Database\Seeders\SingleUserSeeder, which runs on every deploy and
    | is idempotent. A NULL password means "generate one and print it once";
    | supplying one is a deliberate rotation. An EMPTY .env value is null here,
    | because `SEED_USER_PASSWORD=` in a file is somebody not setting it rather
    | than somebody asking for an empty password.
    |
    */

    'seed' => [
        'email' => env('SEED_USER_EMAIL', 'ghie.cotcheza@gmail.com'),
        'name' => env('SEED_USER_NAME', 'Ghie'),
        'password' => env('SEED_USER_PASSWORD') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | The clock the owner lives on
    |--------------------------------------------------------------------------
    |
    | Storage and `config('app.timezone')` stay UTC — that is the only sane
    | thing to persist — but everything a PERSON reads is local: "today's"
    | fare observation, the day a calendar cell stands for, and the hour the
    | scheduler polls at. Reading this in routes/console.php is what makes
    | "06:10" mean 06:10 in Amsterdam in both halves of the year rather than
    | 08:10 in July and 07:10 in January.
    |
    */

    'timezone' => env('ORBIT_TIMEZONE', 'Europe/Amsterdam'),

    /*
    |--------------------------------------------------------------------------
    | Fare providers
    |--------------------------------------------------------------------------
    |
    | Two ports (App\Application\Ports\PriceProvider, PriceStatsProvider),
    | chosen by name here and bound in AppServiceProvider. Both have two
    | adapters now: prices are `fake` or `travelpayouts`, statistics are `fake`
    | or `self`.
    |
    | THERE IS NO THIRD-PARTY STATISTICS ADAPTER AND THERE WILL NOT BE ONE.
    | The plan was Amadeus' price-analysis endpoint; their Self-Service API was
    | decommissioned on 2026-07-17 and nothing else sells the quartiles of a
    | route's fares. `self` computes them out of Orbit's own two tables instead
    | — see the `selfstats` section below — which is why the deal score now runs
    | end to end on data this app collected itself.
    |
    | `fake` IS STILL THE DEFAULT, AND THAT IS A SEPARATE DECISION FROM THE
    | ADAPTER EXISTING. It is not a test double: docs/PLAN.md ships the app
    | before the provider keys exist, so the fake is what production actually
    | runs until somebody flips this. It is deterministic per route, so the same
    | route shows the same prices on every deploy and a feature test can assert
    | real numbers.
    |
    | FLIPPING IT IS NOT ONLY THIS LINE. Every fare in the database was written
    | by whichever adapter was in force at the time and no row records which —
    | so a real price landing in a table full of simulated ones makes the 30-day
    | trend, the "usually €120" and the next alert quietly wrong. `php artisan
    | orbit:reset-history --confirm` is the other half of the switch. See the
    | `travelpayouts` section below and .env.example.
    |
    */

    'providers' => [
        'price' => env('ORBIT_PRICE_PROVIDER', 'fake'),
        'stats' => env('ORBIT_STATS_PROVIDER', 'fake'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Travelpayouts — the real fares
    |--------------------------------------------------------------------------
    |
    | Read by App\Providers\AppServiceProvider when `providers.price` is
    | `travelpayouts`, and by nothing else. The default stays `fake`, so none of
    | this is consulted until somebody sets that variable on a box that has a
    | token.
    |
    | THE ENDPOINT IS `/v2/prices/month-matrix`, one call per calendar month the
    | poll window touches — seven for the standard 181 days, six on the few
    | mornings a window that starts on the 1st closes inside the sixth month, and
    | twelve for the weekly 334-day run. That per-MONTH billing is why
    | `poll.window_days`, `poll.horizon_days` and `rules.sweep_horizon_days` are
    | the odd numbers they are. It is the only one of the three candidates that
    | answers the port's actual question.
    | Measured against the live API on 2026-08-15:
    |
    |   - `/v2/prices/month-matrix` — ONE-WAY (every one of 433 recorded entries
    |     came back with an empty `return_date`), one entry per departure date,
    |     scoped to the month asked for. This one.
    |   - `/v1/prices/calendar` — round-TRIP despite `return_date` being omitted
    |     (AMS-LIS: €252-391 against month-matrix's €80-159 for the same days),
    |     and it ignores the month it is given, answering with scattered dates up
    |     to ten months out. Wrong shape and wrong number.
    |   - `/v2/prices/latest` — the last 48 hours of finds across a period, not a
    |     price per departure date. It is what validated the token, not what
    |     fills a calendar.
    |
    | ONE-WAY IS THE RIGHT NUMBER because docs/PLAN.md's calendar cell is "what
    | it costs to fly out on this day", and a round-trip fare pinned to a
    | departure date is really a fare for a PAIR of dates with the second one
    | hidden. The deal score, the alert threshold and the €80 in a rule are all
    | one-way prices, and always have been — the fake provider's were too.
    |
    | THE TOKEN GOES IN A HEADER, not in the query string (both work; verified).
    | A URL is the one part of an HTTP request that gets written to an access
    | log, a proxy trace and an exception report by default.
    |
    | TIMEOUTS ARE SHORT AND THE RETRY IS SINGLE. Nobody is waiting on this — it
    | is a queued job at 06:10 — but the poll is seven calls per watched route
    | in a stagger (sixty-odd of them across the current watchlist, and a hundred
    | on the far morning) and a provider that has stopped answering should fail
    | the morning rather than occupy a worker until Horizon's timeout kills it
    | mid-upsert.
    |
    | NO CURRENCY KEY. Every price in this app is euro cents, from the migration
    | to the alert mail, so the request asks for EUR in the adapter and REFUSES a
    | response whose envelope says anything else. A configurable currency would
    | be a promise the rest of the app does not keep.
    |
    */

    'travelpayouts' => [
        'base_url' => env('TRAVELPAYOUTS_BASE_URL', 'https://api.travelpayouts.com'),

        'token' => env('TRAVELPAYOUTS_TOKEN'),

        /*
         * THE AFFILIATE MARKER, WHICH THE DATA ADAPTER STILL DELIBERATELY DOES
         * NOT SEND. It identifies whose link a BOOKING came from and the fare
         * API has no use for it, so no request in App\Infrastructure\Pricing
         * carries it.
         *
         * IT IS FINALLY READ BY SOMETHING, THOUGH. This key spent its whole life
         * unused, with a comment saying it was here so that "the day those links
         * move to Aviasales" the number would already be in an obvious place.
         * That day arrived for a reason that had nothing to do with money —
         * Orbit quotes Aviasales' cached fares and was sending people to
         * Skyscanner, where those fares often are not — and the attribution
         * comes along with the fix. App\Application\Routes\BookingLink appends
         * it to the Aviasales hand-off and to nothing else.
         *
         * UNSET IS FINE AND IS THE DEFAULT. The link works without it; what is
         * lost is the credit, not the destination.
         */
        'marker' => env('TRAVELPAYOUTS_MARKER'),

        /*
         * Seconds. The read timeout is generous because the answer is served
         * from Travelpayouts' cache and is occasionally slow; the connect
         * timeout is not, because a host that will not complete a handshake in
         * five seconds is down.
         */
        'connect_timeout' => 5,
        'timeout' => 15,

        /*
         * ONE RETRY, half a second apart. The data is a cache read, so a second
         * attempt costs almost nothing and covers the single dropped connection
         * that would otherwise leave a month of the calendar empty for a day.
         * A third would just be a slower way to find out the API is down.
         */
        'retries' => 1,
        'retry_delay_ms' => 500,

        /*
         * HOW OFTEN A FAILING PROVIDER IS ALLOWED TO SAY SO. One morning's poll
         * is seven calls per watched route; an outage is therefore fifty-odd
         * identical log lines, times the rule sweep, and a log that repeats
         * itself is a log nobody greps. One warning per quarter of an hour is
         * enough to notice and few enough to read — the line says how many
         * minutes of silence follow it.
         */
        'warn_every_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-computed statistics — what a route usually costs, from our own data
    |--------------------------------------------------------------------------
    |
    | Read by App\Providers\AppServiceProvider when `providers.stats` is `self`,
    | and by nothing else. App\Infrastructure\Pricing\SelfStatsProvider is the
    | adapter; its docblock is the long version of everything below.
    |
    | THE TWO HORIZONS IT SUMMARISES, both of them real fares this app fetched:
    |
    |   CROSS-SECTIONAL — the fares for the ~182 departure dates in the NEAR
    |   window (`cross_section_days` below). Available from the FIRST poll, which
    |   is what makes a deal score possible on the day a route is added. Its
    |   median answers "what does a typical departure date on this route cost
    |   right now" — over SIX months of departures since `poll.window_days`
    |   widened, which is a broader question than the three months it used to
    |   summarise and a slightly different median.
    |
    |   LONGITUDINAL — `route_price_history`, one row per morning, each the
    |   cheapest fare anywhere in that morning's window. It takes weeks to mean
    |   anything and is the better comparison once it does: the fare being scored
    |   IS one of these rows (App\Application\Routes\RouteSnapshots reads the
    |   latest observation as "the current price"), so a percentile against them
    |   compares today's best against every other day's best — like for like.
    |
    | THE BLEND IS ONE LINE OF ARITHMETIC, deliberately:
    |
    |     w    = min(1, observations / maturity_observations)
    |     knot = round((1 - w) * cross_sectional + w * longitudinal)
    |
    | applied to each of the five knots (min, p25, median, p75, max) separately.
    | A convex combination of two non-decreasing five-number summaries is
    | non-decreasing, so the result cannot violate App\Domain\Pricing\PriceStats'
    | ordering invariant, and every intermediate value is a euro figure somebody
    | can read rather than the output of a model.
    |
    | MATURITY_OBSERVATIONS = 30 is a month of polling, and is the point at which
    | the longitudinal view stands entirely on its own. Below it the two are
    | mixed in proportion to how much history there is, which is the honest
    | reading: at 15 days the route's usual price is half "what a typical
    | departure costs" and half "what a typical morning's best fare was".
    |
    | HISTORY_DAYS caps how far back the longitudinal pool reaches. A year is
    | where "usual" stops being a fact about this route and starts being a fact
    | about a market that has moved on — and it also keeps the pool bounded at
    | 365 rows per route rather than growing for the life of the app.
    |
    | CROSS_SECTION_DAYS CAPS HOW FAR FORWARD THE OTHER POOL REACHES, and it
    | exists because `calendar_fares` now runs ELEVEN months deep
    | (`poll.horizon_days`) while "usual" must not.
    |
    |   WHAT THE FAR MONTHS WOULD DO TO IT. Months 7 to 11 out are sparse — the
    |   provider's cache thins with distance, so what survives out there is
    |   disproportionately the dates people actually search: Christmas, Easter,
    |   the school holidays. Pooling them with the near six months does not
    |   widen the distribution evenly, it drags the upper knots up with a
    |   handful of peak-season fares, and every route quietly becomes a better
    |   deal than it is. The 60%-weighted percentile of the deal score is the
    |   one input in this app that must not move for a reason nobody asked for.
    |
    |   IT IS ALSO THE HONEST COMPARISON. The fare being scored is the cheapest
    |   in the NEAR window (App\Jobs\PollRoutePrices writes exactly one
    |   observation a morning and takes it from those 181 days), so the
    |   distribution it is scored against has to be drawn from the same 181 days
    |   — like against like, which is the argument the longitudinal half is
    |   built on too.
    |
    |   181, WRITTEN OUT RATHER THAN REFERENCED. It is the near window's number
    |   and it must track it — tests/Feature/SelfStatsProviderTest asserts the
    |   two agree, which is the drift guard — but they are different decisions:
    |   `poll.window_days` is a budget for what to fetch daily, and this is a
    |   statistical claim about which departures are comparable. A box that
    |   narrows one has to think about the other.
    |
    | NEITHER HORIZON IS EVER INVENTED. A route with no calendar fares and no
    | history gets NULL, the port's real answer, and App\Domain\Pricing\
    | DealScorer renormalises its weights over what is left.
    |
    */

    'selfstats' => [
        'maturity_observations' => 30,
        'history_days' => 365,
        'cross_section_days' => 181,
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the owner flies from
    |--------------------------------------------------------------------------
    |
    | The three airports within a sensible drive.
    |
    | =========================================================================
    | WHAT THIS IS, SINCE THE SEARCH SCREEN — read this before widening it
    | =========================================================================
    | It used to be two things at once: the only origins a person could TYPE,
    | and the only origins a RULE could fire from. On 2026-08-16 the first half
    | went away and the second did not, and the asymmetry is the decision.
    |
    | IT IS NO LONGER A VALIDATION LIST. App\Http\Requests\RoutePairRequest
    | accepts any row in `airports` at BOTH ends now, so `POST /api/watchlist`
    | and `POST /api/routes/lookup` will price BCN-PMI for somebody who is
    | already in Barcelona. Asking what a pair costs is a question, and this
    | list was the only thing making it unaskable.
    |
    | IT IS STILL THE RULE ENGINE'S ORIGINS, AND THAT IS A BUDGET. A deal rule
    | is a standing question Orbit answers on its own every night:
    | App\Application\Rules\RuleMatches and App\Jobs\SweepRuleFares walk
    | `origins × destinations` and each cell is a metered provider call
    | (docs/BUSINESS-LOGIC.md §11, "The cap is the point"). Three origins is
    | 3 × 184; a fourth is another 184 polls a night that nobody asked for by
    | name. App\Domain\Rules\RuleVocabulary is what a sentence may name, and it
    | reads this too. All three read the config directly and none of them goes
    | through a FormRequest, which is why widening the request widened nothing
    | here.
    |
    | THEY ARE ALSO THE SEARCH SCREEN'S QUICK CHIPS, which is presentation
    | rather than a rule: resources/js/Views/Search.vue writes AMS, EIN and DUS
    | out so the ordinary case is one tap, and the box beside them takes any of
    | the 3,270.
    |
    | THE SAME THREE ARE FLAGGED `is_origin` BY DestinationSeeder, from
    | database/seeders/data/european_destinations.php. Two lists of one fact is
    | a drift waiting to happen, so tests/Feature/SeedersTest asserts they
    | agree — the seeder's list is the one that carries the coordinates.
    |
    */

    'origins' => ['AMS', 'EIN', 'DUS'],

    /*
    |--------------------------------------------------------------------------
    | The deal score
    |--------------------------------------------------------------------------
    |
    | Read once by AppServiceProvider into App\Domain\Pricing\ScoringPolicy and
    | handed to the scorer, because the scorer is pure PHP and calls no
    | framework function — including config().
    |
    | WEIGHTS are docs/PLAN.md's locked split and are RENORMALISED over
    | whatever is computable: a route with no history yet is scored on the two
    | components that do not need it rather than being punished 25 points for
    | being new.
    |
    | TREND_SATURATION_PER_DAY is the fractional price change per day at which
    | the trend component pins to 0 or 100. 0.005 is half a percent a day —
    | ~14% over the 30-day window — which is a trend a person would call
    | "clearly falling" rather than noise.
    |
    | TIERS are the alert sensitivity levels PR11 fires on. They are here and
    | not in the alert config because the tier is part of the score's meaning,
    | and the API publishes it.
    |
    */

    'score' => [
        'weights' => [
            'percentile' => 60,
            'trend' => 25,
            'absolute' => 15,
        ],

        'tiers' => [
            'insane' => 80,
            'great' => 65,
            'good' => 50,
        ],

        'trend_days' => 30,
        'trend_saturation_per_day' => 0.005,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | WHEN ORBIT IS ALLOWED TO INTERRUPT SOMEBODY. The four numbers below the
    | sensitivities are App\Domain\Alerts\AlertPolicy's whole rule book, read
    | once by App\Providers\AppServiceProvider and handed to it — the policy is
    | pure PHP and calls no config(), the same arrangement DealScorer has.
    |
    | `sensitivities` is the three positions of the segmented control on the
    | alerts screen
    | (design/README.md §6), stored as `user_settings.sensitivity` and read by
    | App\Models\UserSettings::minimumScore().
    |
    | EACH LEVEL NAMES A TIER RATHER THAN A NUMBER. The number lives once, in
    | `score.tiers` above, and is the same one the API publishes as a route's
    | `tier` — so "Relaxed" and the "insane" badge on a route can never come to
    | mean different scores. Retuning a tier retunes the sensitivity with it.
    |
    | `blurb` IS THE SENTENCE UNDER THE CONTROL and takes the threshold as its
    | one %d, filled in by App\Http\Controllers\SettingsController. It is here
    | and not in the Vue component for exactly that reason: the copy quotes a
    | number that this file owns, and a hard-coded "80+" in a template is a
    | sentence that goes quietly wrong the day the tier moves.
    |
    | INDEXED 0, 1, 2 — an ordered scale from quietest to loudest, and the keys
    | ARE the stored values. Adding a level means adding an entry here; the
    | request validates against these keys, so nothing else needs changing.
    |
    */

    'alerts' => [
        /*
         * HOW MANY REAL DAILY OBSERVATIONS A ROUTE NEEDS before its deal score
         * is allowed to interrupt somebody — and, for the same reason, before
         * the score is published as anything but "no opinion yet".
         *
         * THIS IS THE DAY-1 HONESTY RULE WITH TEETH. `ORBIT_STATS_PROVIDER=self`
         * computes a route's statistics from the fares Orbit itself has already
         * fetched, so on the first morning the "usual price" IS today's price:
         * the current fare is the minimum, the median and the maximum of a
         * distribution one observation wide. Every component of the score then
         * agrees that the fare is as cheap as this route has ever been, and
         * every route on the watchlist scores 100/insane/confident at
         * `trackingDays: 1`. That is not a sale, it is a summary of a single
         * number — and left alone, 06:55 the next morning is eight "insane
         * deal" mails about nothing, on the one day the owner is most likely to
         * decide this app cries wolf.
         *
         * SEVEN DAYS is a week of mornings: enough for a spread to exist and
         * for the trend component to have a direction, and short enough that a
         * route added on a Monday can still be alerted about before the next
         * one. It is not a claim that seven observations make a good estimate
         * — `selfstats.maturity_observations` above is where THAT claim lives,
         * and it blends the cross-sectional calendar in until 30 mornings have
         * accumulated. This number is the smaller, harder question: below it,
         * Orbit says nothing rather than something it cannot support.
         *
         * READ BY TWO PURE VALUES, both through App\Providers\AppServiceProvider:
         * App\Domain\Alerts\AlertPolicy, which answers `immature-data` instead
         * of firing, and App\Domain\Pricing\ScoringPolicy, which is what makes
         * `confident: false` mean what docs/API.md says it means. One number,
         * because a screen that showed "Good price — book" for a route the
         * alert engine considered too young to mention would be two different
         * opinions about the same morning.
         */
        'min_tracking_days' => 7,

        /*
         |----------------------------------------------------------------------
         | HOW OLD A FARE MAY BE BEFORE IT IS NOT WORTH INTERRUPTING SOMEBODY
         |----------------------------------------------------------------------
         |
         | THE TWO NUMBERS ARE ONE RULE and neither does anything alone: an
         | alert is HELD only when its fare was found more than
         | `max_fare_age_days` ago AND the flight leaves within
         | `near_departure_weeks`. App\Domain\Alerts\AlertPolicy answers
         | `stale-fare`.
         |
         | WHY AGE MATTERS AT ALL. Fares come from Travelpayouts, which serves a
         | CACHE of other people's searches (docs/BUSINESS-LOGIC.md §2), so
         | `calendar_fares.found_at` — when the price was actually seen — can be
         | days behind `fetched_at`, when Orbit asked. The owner caught the
         | consequence twice: €36 on a date whose live cheapest was €56, and €29
         | against a real €68. On a screen that is a stale number with a line
         | under it saying so. In a mail it is Orbit waking somebody up about a
         | flight that is not for sale, which is the single worst thing this app
         | can do.
         |
         | WHY DEPARTURE DATE IS THE OTHER HALF. Fares near departure move fast
         | and in one direction — seats sell, the cheap fare classes go, and a
         | four-day-old quote for a flight three weeks out is very often gone.
         | Far-out fares barely move for weeks at a time, so the same four-day-old
         | price for next April is still worth saying: holding it would silence
         | the alerts most likely to be TRUE, on exactly the routes somebody has
         | time to act on. The asymmetry is the point — this rule is aimed at the
         | combination, not at age.
         |
         | TWO DAYS, because the poll is daily: one missed morning must not make
         | every fare unalertable, and by the third day a near-departure price is
         | old enough that Orbit is guessing. THREE WEEKS is where "book it this
         | week" turns into "keep an eye on it" for a European short-haul.
         |
         | NULL `found_at` IS TREATED AS FRESH — see AlertPolicy for the defence.
         | It means "we do not know how old this is", which is the state of every
         | row written before the column existed, and a rule that silenced alerts
         | on not-knowing would have turned the whole alert system off on the
         | morning this shipped.
         */
        'max_fare_age_days' => 2,
        'near_departure_weeks' => 3,

        /*
         * HOW LONG ONE ROUTE STAYS QUIET after Orbit has mentioned it, per kind
         * of alert. A fare that sits at 95 for a week is one piece of news, and
         * a person mailed about it seven times stops opening the mail — at
         * which point the eighth, about a route they would have booked, is not
         * read either. docs/PLAN.md's number, read by App\Domain\Alerts\
         * AlertPolicy.
         */
        'cooldown_hours' => 24,

        /*
         * WHAT BEATS THE COOLDOWN. A price that has fallen a further 5% since
         * the last alert is new information rather than a repeat: "€44, 53%
         * below usual" yesterday and €38 today is the morning somebody
         * actually books. Without this the cooldown would turn the one thing
         * worth interrupting for — a fare still falling — into a day of
         * silence.
         */
        'further_drop_percent' => 5,

        /*
         * HOW MANY TRIPS ONE MAIL SPELLS OUT. A rule that matched thirty routes
         * is a mail nobody scrolls to the end of, so the cheapest few are
         * listed and the rest are counted ("and 24 more"). Every one of them is
         * still written to the ledger, because the cooldown's promise is that
         * a route Orbit has mentioned stays quiet — and the mail did mention
         * them, in aggregate.
         *
         * The same handful the create screen's match banner shows
         * (`rules.sample`), and separate from it on purpose: one is what fits
         * on a phone screen next to a textarea, this is what fits in an inbox.
         */
        'mail_deals' => 6,

        /*
         * WHAT "THIS WEEK" MEANS in the Sunday digest's callout — the window it
         * counts already-sent alerts over. Seven days rather than "since the
         * last digest", so a digest that failed to send once does not produce a
         * fortnight of deals the following week under a heading that says week.
         */
        'digest_days' => 7,

        'sensitivities' => [
            0 => [
                'name' => 'Relaxed',
                'tier' => 'insane',
                'blurb' => 'Only the truly insane deals — score %d and up. Rare, and worth clearing a weekend for.',
            ],
            1 => [
                'name' => 'Balanced',
                'tier' => 'great',
                'blurb' => 'Anything Orbit rates a great deal — score %d and up. A handful a month.',
            ],
            2 => [
                'name' => 'Eager',
                'tier' => 'good',
                'blurb' => 'Every fare scoring %d or better. More to look at, and more that turns out to be ordinary.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling — eleven months, at two speeds
    |--------------------------------------------------------------------------
    |
    | THERE ARE TWO HORIZONS HERE AND THEY ANSWER TWO DIFFERENT QUESTIONS. Every
    | number below hangs off which of the two it belongs to, so read this first:
    |
    |   WINDOW_DAYS (181, ~6 months) is THE NEAR WINDOW: what a poll fetches on
    |   an ordinary morning, and the definition of "the current price" — the
    |   cheapest fare in the next six months. It is the pool the daily
    |   observation is taken from (App\Jobs\PollRoutePrices), the pool
    |   `selfstats` summarises, and therefore the number every deal score, alert
    |   threshold and sparkline point in this app is built on.
    |
    |   HORIZON_DAYS (334, ~11 months) is HOW FAR THE APP MAINTAINS A CALENDAR:
    |   the far edge of the heat map, the month the arrows stop at, and the line
    |   past which PollRoutePrices deletes cells as unmaintained. It is refreshed
    |   WEEKLY rather than daily — see `far_refresh_weekday` below.
    |
    | Widening the near window makes every route look cheaper; widening the
    | horizon does not move a single score. That is the whole reason they are two
    | keys rather than one, and it is why the far months could be added at all.
    |
    | ELEVEN MONTHS BECAUSE THAT IS THE BOOKING EDGE. Airlines load schedules
    | roughly eleven months out, so a twelfth month is a request that reliably
    | answers with nothing. The owner asked to see past the six ("extend it"):
    | a summer holiday is booked in February and a Christmas flight in January,
    | and neither was reachable from a six-month calendar.
    |
    | 334 AND NOT 335, WHICH IS THE SAME ARITHMETIC 181 IS. Travelpayouts
    | answers a CALENDAR MONTH at a time (see the `travelpayouts` section), so a
    | window costs one request per month it touches and the cost steps up at a
    | month boundary rather than at a day. Brute-forced over every start date in
    | a four-year span:
    |
    |     181 days  →  never more than  7 months   (183 reaches an 8th)
    |     334 days  →  never more than 12 months   (335 reaches a 13th, on the
    |                                               31st of a long month, ~5
    |                                               mornings a year)
    |
    | A thirteenth request would buy one day of departures at an edge airlines
    | have not loaded yet, on exactly the mornings everything else is at its most
    | expensive. 334 is the widest horizon that never pays for a month it does
    | not need — the same sentence 181 has always carried.
    |
    | FAR_REFRESH_WEEKDAY IS THE SECOND SPEED, and it is a day of the week rather
    | than a period: 0 is Sunday, 6 is Saturday, as `Schedule::weeklyOn()` reads
    | it. routes/console.php runs `orbit:poll-fares --far` on that morning, which
    | polls the whole 334 days for every watched route; the daily 06:10 poll goes
    | on fetching the near window on all seven mornings including that one.
    |
    |   WEEKLY IS WHAT THE FAR MONTHS ARE WORTH. A fare eleven months out moves
    |   on the timescale an airline reprices a fare bucket, not on this
    |   morning's cache churn, and nothing downstream reads those cells except a
    |   person paging the calendar. Daily would be 45 more requests every day of
    |   the year for a number that is the same number.
    |
    |   SATURDAY because the far months are what somebody browses at a weekend —
    |   the eleven-month calendar is holiday planning, not a commute — so it is
    |   refreshed going INTO the weekend rather than in the middle of the week.
    |
    |   AND IT IS AN EXTRA FETCH, NOT A DEEPER ONE. The far run re-fetches the
    |   seven near months it shares with the daily poll (63 of its 108 requests,
    |   below), which is deliberate: the alternative is a job that fetches a
    |   SLICE of the calendar, and then a morning's observation would mean "the
    |   cheapest fare in the next six months" or "in months 7 to 11" depending on
    |   which run happened to write it. One job, one shape, always idempotent.
    |
    | THE BUDGET, WHICH IS THE REAL CONSTRAINT — Travelpayouts allows ~200
    | requests an hour per IP, and this is the whole table (nine watched routes,
    | which is the watchlist today):
    |
    |   AN ORDINARY MORNING, all of it inside the 06:00 clock hour:
    |
    |     06:10  the poll     9 watched × ≤7 months   =  63
    |     06:40  the sweep   30 capped  × ≤4 months   = 120   (`rules` below)
    |                                                   ---
    |                                                   183   of ~200
    |
    |   THE FAR MORNING, once a week, in the 04:00 hour with nothing else in it:
    |
    |     04:10  the far poll 9 watched × ≤12 months  = 108
    |
    |   That day's 06:00 hour is the same 183 as every other day. THE ELEVEN
    |   MONTHS COST NOTHING IN THE WORST HOUR, which is the reason the far run
    |   is a separate schedule entry at a separate time rather than a deeper
    |   Wednesday poll — 9 × 12 + 120 = 228 would have been over the limit.
    |
    |   WHERE IT BREAKS, so nobody has to rediscover it: at W watched routes the
    |   06:00 hour costs 7W + 120 and the far hour costs 12W. The ORDINARY
    |   morning is the binding constraint and breaches ~200 at W = 12 (204),
    |   while the far run has room to W = 16 (192). The twelfth watched route is
    |   what needs a plan — a wider stagger spilling the poll into the 07:00
    |   hour, or moving the sweep — and the far horizon is not what puts it
    |   there.
    |
    | STAGGER_MINUTES spaces the per-route jobs so nine routes' worth of provider
    | calls do not arrive as a burst — the real APIs are rate-limited per minute
    | as well as per hour. Nine routes at three minutes is a 24-minute fan-out,
    | which is what keeps each of the two mornings above inside one clock hour.
    |
    | STALE_AFTER_DAYS is how long a calendar cell may go without being repriced
    | before a successful poll of that route deletes it. A future departure date
    | that STOPS being quoted — Travelpayouts' cache is patchy and a day that
    | had a fare this morning may have none tomorrow — is otherwise upserted
    | once and then kept forever, because an upsert only ever writes the dates
    | the provider named. Nothing in the API marks a cell as stale
    | (App\Http\Resources\RouteCalendarResource sends a price, not a date), so
    | the row would go on claiming to be today's price on the heatmap, in the
    | "cheapest departure" booking link, and — worst of all — in the fares a
    | deal rule is matched against, which is how this app would come to mail
    | somebody about a flight that cannot be booked.
    |
    | THREE DAYS, because the poll is daily and the deletion is one-way: two
    | consecutive mornings may fail, or a date may simply be missing from the
    | provider's cache for a day, without the calendar losing a cell it would
    | have got back. See App\Jobs\PollRoutePrices for why this is a delete on a
    | SUCCESSFUL poll rather than a filter on every read — and for why the
    | staleness sweep is bounded by the window the poll it belongs to actually
    | ASKED for, now that a rule sweep asks for a shorter one than the daily
    | poll does (`rules.sweep_horizon_days`).
    |
    | FAR_STALE_AFTER_DAYS IS THE SAME RULE ON THE FAR TRANCHE'S OWN CLOCK. The
    | three days above are "two missed mornings plus a day", and applying them to
    | cells that are only ever refreshed on Saturdays would mean ONE failed month
    | request costing a month of the far calendar every single time: those cells
    | are seven days old by the time anything looks at them again, which is
    | already stale by the daily rule. SEVENTEEN DAYS is the identical sentence
    | at the weekly period — two missed far refreshes (7 + 7) plus the same
    | three-day cushion — and it is why PollRoutePrices runs the staleness delete
    | as two passes rather than one.
    |
    */

    'poll' => [
        'window_days' => 181,
        'horizon_days' => 334,
        'far_refresh_weekday' => 6,
        'stagger_minutes' => 3,
        'stale_after_days' => 3,
        'far_stale_after_days' => 17,
    ],

    /*
    |--------------------------------------------------------------------------
    | Looking a route up before watching it
    |--------------------------------------------------------------------------
    |
    | `POST /api/routes/lookup` (docs/API.md) prices a pair the owner has not
    | committed to yet: it finds-or-creates the route and, when Orbit has no
    | recent fares for it, asks the provider RIGHT THERE — inside the request,
    | while somebody waits — rather than queueing a poll they would have to come
    | back for. The daily poll is for routes on the watchlist; this is the one
    | path in the app where a person's tap costs provider calls directly, which
    | is what both numbers below exist to bound.
    |
    | FRESH_FOR_HOURS IS THE WHOLE FRESHNESS RULE, and it is deliberately one
    | number used for two things:
    |
    |   1. A route is FRESH when it has a calendar fare fetched inside this
    |      window (`App\Application\Routes\FareFreshness`). Fresh routes are
    |      served from the database and cost nothing.
    |   2. Having ASKED the provider is remembered for the same window, in the
    |      cache, keyed on the route code. That is what stops a pair
    |      Travelpayouts has no fares for — an empty answer is a real answer,
    |      see the adapter — from being re-fetched on every single view: no
    |      rows are written, so rule 1 would say "stale" forever.
    |
    | TWENTY-FOUR HOURS BECAUSE THE POLL IS DAILY. A watched route's fares are
    | at most a morning old, so the same number is what makes a looked-up route
    | worth as much as a watched one — and any shorter would mean a route looked
    | up twice in an evening being fetched twice for figures that cannot have
    | moved by a poll.
    |
    | WHAT ONE MISS COSTS, because it is the reason the endpoint is throttled at
    | all (`route-lookup` in App\Providers\AppServiceProvider): a fetch is the
    | same full `poll.window_days` window a watched route gets, and Travelpayouts
    | bills one request per calendar month that window touches — so SIX OR SEVEN
    | provider calls, out of the ~200 an hour the token allows. The limiter's
    | hourly ceiling is set from that multiplication.
    |
    | THE NEAR WINDOW, AND DELIBERATELY NOT THE ELEVEN-MONTH HORIZON. A watched
    | route's calendar runs to `poll.horizon_days` now; a looked-up one stops at
    | six months, for two reasons that both point the same way:
    |
    |   1. SOMEBODY IS WAITING. The fetch is synchronous (App\Application\Routes\
    |      FareFreshness) and the calls are sequential, so twelve months is
    |      twelve round trips in one request instead of seven — and the timeout
    |      budget behind it (15 s a read, one retry) is what the screen has to
    |      sit through when the provider is slow.
    |   2. THE ARITHMETIC ABOVE WOULD HALVE. Twelve calls a miss is 240 in the
    |      hourly ceiling rather than 140, which is the whole allowance — so the
    |      limiter would have to drop to ten an hour, and a long evening of
    |      browsing would start being refused.
    |
    | WHAT THAT COSTS, stated: a route that is looked up and not watched has no
    | fares in months 7 to 11. The lookup screens do not draw a calendar, so
    | nothing shows the gap — and the first `--far` run after it is watched fills
    | it in. See App\Application\Routes\FareFreshness.
    |
    */

    'lookup' => [
        'fresh_for_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | How much history the screens get
    |--------------------------------------------------------------------------
    |
    | The watchlist sparkline is 14 points and the detail chart is 60 (design
    | README §1 and §2). BACKFILL_DAYS is how far Database\Seeders\
    | FakeHistorySeeder simulates backwards — see that file for why simulated
    | history is defensible for a fake provider and would not be for a real one.
    |
    */

    'history' => [
        'sparkline_days' => 14,
        'chart_days' => 60,
        'backfill_days' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar heat thresholds
    |--------------------------------------------------------------------------
    |
    | design/README.md §3: a day is "cheap" at or below lo + 28% of the month's
    | range and "pricey" at or above lo + 66%. The API returns the verdict
    | rather than the thresholds so the calendar screen and a future alert
    | cannot disagree about what a cheap day is.
    |
    */

    'calendar' => [
        'cheap_at' => 0.28,
        'pricey_at' => 0.66,
    ],

    /*
    |--------------------------------------------------------------------------
    | Booking
    |--------------------------------------------------------------------------
    |
    | There is no booking API and there will not be one — docs/PLAN.md settles
    | on deep links into somebody else's search.
    |
    | TWO OF THEM NOW, AND AVIASALES IS FIRST. Orbit showed DUS→AGP at €29 on a
    | date whose cheapest on Skyscanner was €68, and the arithmetic was fine:
    | fares come from Travelpayouts, which is AVIASALES' cache, and the app then
    | handed the reader to SKYSCANNER — a different meta-search, a different set
    | of agencies, and no reason at all to be holding the fare Orbit quoted.
    | Quoting one shop's price and pointing at another's till is a way to look
    | wrong while being right. The primary hand-off is now the site the price
    | came from; Skyscanner stays as the quiet second opinion.
    |
    | THE AVIASALES PARAMS SHAPE — `{ORIGIN}{DDMM}{DEST}{passengers}`, upper
    | case, day before month — is Travelpayouts' documented format, verified
    | against the live site rather than remembered. App\Application\Routes\
    | BookingLink carries the evidence and the traps.
    |
    | ONLY THE HOSTS ARE HERE. The path shapes are BookingLink's, because they
    | are a format rather than a setting: an .env that could half-change one of
    | them is a way to ship a link that 404s.
    |
    */

    'booking' => [
        /*
         * NO PATH ON THIS ONE, deliberately: Aviasales has two entry points off
         * the same host — `/search/PARAMS` for a dated search and `/?params=`
         * for the pre-filled form a route with no fares yet lands on — and
         * BookingLink picks between them.
         */
        'aviasales_base' => 'https://www.aviasales.com',

        'skyscanner_base' => 'https://www.skyscanner.nl/transport/flights',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reading a rule written in English
    |--------------------------------------------------------------------------
    |
    | design/README.md §4 is a textarea, not a form: the owner types "cheap
    | weekend somewhere sunny in spring, leaving Friday from any NL airport,
    | under €80" and the app turns it into removable chips. Two adapters answer
    | App\Application\Ports\RuleTextParser, chosen by name here exactly like the
    | fare providers above.
    |
    | `parser` DEFAULTS TO THE ONE THAT WORKS WITHOUT A KEY. docs/PLAN.md's
    | pending-owner-actions list still has "dedicated Anthropic API key" on it,
    | so the regex adapter is what production runs today and is written to be
    | good enough to ship rather than as a stub. The moment a key lands in .env
    | the anthropic adapter takes over with no other change — and it composes
    | the regex one as its fallback, so a refusal or a truncated answer is a
    | slightly dumber parse rather than a 500 on the create screen.
    |
    | ORBIT_NLP_PARSER OVERRIDES BOTH, which is how a box with a key can still
    | be pinned to the deterministic parser for a demo or a bisect.
    |
    */

    'nlp' => [
        'parser' => env('ORBIT_NLP_PARSER') ?: (env('ANTHROPIC_API_KEY') ? 'anthropic' : 'regex'),

        'api_key' => env('ANTHROPIC_API_KEY'),

        /*
         * Haiku, and not by accident. The job is one short sentence in and one
         * small JSON document out, the schema does the structural work, and
         * the whole thing has to answer inside a 500 ms debounce while
         * somebody is still typing. Reaching for a larger model here would buy
         * nothing the schema does not already guarantee and would spend the
         * latency budget the screen is built around.
         */
        'model' => env('ORBIT_NLP_MODEL', 'claude-haiku-4-5-20251001'),

        /*
         * The JSON this asks for is a handful of fields, so 1024 is generous.
         * It is a ceiling and not a target: the adapter treats a max-tokens
         * stop as a failure and falls back, because a truncated JSON document
         * is not a small problem, it is unparseable.
         */
        'max_tokens' => 1024,

        /*
         * THE TIMEOUTS ARE THE PSR-18 CLIENT'S, not the SDK's. The SDK's own
         * `timeout` option is advisory — its source never reads it — so the
         * only thing that will actually stop a hung request is the transporter
         * we hand it (see App\Providers\AppServiceProvider). A parse that
         * hangs is a create screen that never says anything.
         *
         * One retry, from the SDK, covering 429 / 5xx / connection errors. The
         * caller is a person typing, and a second attempt is the most a
         * keystroke can wait for.
         */
        'connect_timeout' => 5,
        'timeout' => 30,
        'max_retries' => 1,

        /*
         * WHAT A PERSON CALLS THE THREE AIRPORTS. The codes themselves come
         * from `origins` above and are not repeated here — these are the words
         * somebody types instead of the code, and they are a different fact.
         * tests/Feature/SeedersTest asserts every value is one of `origins`
         * and that the city names agree with the seeder's, the same drift
         * guard the origins list itself carries.
         *
         * `any nl airport` and friends are handled by the parser as "all of
         * them" rather than as an alias, because they name the SET.
         */
        'origin_aliases' => [
            'ams' => 'AMS',
            'amsterdam' => 'AMS',
            'schiphol' => 'AMS',
            'ein' => 'EIN',
            'eindhoven' => 'EIN',
            'dus' => 'DUS',
            'düsseldorf' => 'DUS',
            'dusseldorf' => 'DUS',
        ],

        /*
         * THE NINE-WORD VIBE VOCABULARY, and the words that mean each of them.
         *
         * The keys are exactly the vocabulary in
         * database/seeders/data/european_destinations.php — that file's header
         * explains why the set is closed, and SeedersTest asserts these keys
         * and the seeder's agree. The values are the open half: what somebody
         * might actually type. Adding a synonym is safe; adding a KEY is not,
         * because no destination carries it and the rule would match nothing.
         *
         * Longest phrases first within a vibe, so "city break" is not eaten by
         * "city" — App\Infrastructure\Nlp\RegexRuleTextParser relies on it.
         */
        'vibe_words' => [
            'sunny' => ['sunshine', 'sunny', 'warm', 'hot', 'sun'],
            'beach' => ['seaside', 'beaches', 'beach', 'coastal', 'coast', 'sand', 'swimming'],
            'city' => ['city break', 'citybreak', 'city', 'urban'],
            'culture' => ['cultural', 'culture', 'museums', 'museum', 'history', 'historic', 'art'],
            'food' => ['gastronomy', 'restaurants', 'foodie', 'food', 'cuisine', 'wine'],
            'islands' => ['archipelago', 'islands', 'island'],
            'nature' => ['mountains', 'mountain', 'outdoors', 'hiking', 'hike', 'nature', 'forest', 'scenery'],
            'party' => ['nightlife', 'clubbing', 'party', 'bars'],
            'ski' => ['snowboard', 'skiing', 'slopes', 'piste', 'snow', 'ski'],
        ],

        /*
         * The chip the screen draws for each vibe (design/README.md §4 shows
         * "☀ Sunny"). HERE AND NOT IN THE VUE COMPONENT for the same reason
         * the sensitivity blurbs are here: the label names a vocabulary this
         * file owns, and a hard-coded "Sunny" in a template is a word that
         * goes quietly wrong the day the vocabulary moves.
         */
        'vibe_labels' => [
            'sunny' => '☀ Sunny',
            'beach' => '🏖 Beach',
            'city' => 'City break',
            'culture' => 'Culture',
            'food' => 'Food',
            'islands' => 'Islands',
            'nature' => 'Nature',
            'party' => 'Nightlife',
            'ski' => 'Snow',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Matching a rule against the world
    |--------------------------------------------------------------------------
    |
    | WARM_AT is the `destinations.warmth` rating (1 "pack a coat" to 5
    | "beach", see the seeder data file) a place has to reach before it counts
    | as somewhere a WARM_VIBES rule would send you. It is checked against the
    | BEST month in the rule's date window rather than every month, because a
    | person flies on one date: "somewhere sunny in spring" is satisfied by a
    | place that is warm by May, and demanding March be warm too would leave
    | the Canaries and nothing else.
    |
    | THE GATE ONLY RUNS WHEN THE RULE ASKS FOR ONE OF WARM_VIBES AND NAMES A
    | WINDOW. A rule that just says "somewhere sunny" is already answered by
    | the `sunny` tag on the destination; a climate check with no window to
    | check it in would be inventing a season the person did not ask about.
    |
    | SWEEP_CAP is how many origin×destination pairs one rule may put on the
    | queue. A rule with no vibe at all is 3 origins × every curated destination
    | — 184 of them today, and the number only ever goes up as
    | database/seeders/data/ is edited — which is a rate limit spent on a
    | sentence somebody typed and may delete a minute later. (It read "3 × 77 =
    | 231" for months after world flights more than doubled the list, which is
    | why the arithmetic is now written as a shape with the count beside it
    | rather than as a product nobody recomputes.) The cap keeps the
    | best-fitting thirty and logs the rest — see App\Jobs\SweepRuleFares for
    | what "best" means.
    |
    | SWEEP_HORIZON_DAYS is how far ahead those speculative polls look, and it
    | is DELIBERATELY SHORTER THAN `poll.window_days`. The asymmetry is the
    | budget, and the budget is Travelpayouts' ~200 requests an hour per IP:
    |
    |   the daily poll  9 watched routes × ≤7 months  =  ≤63 requests
    |   one rule sweep  30 capped routes × ≤4 months  =  ≤120 requests
    |                                                    ------------
    |   06:10 and 06:40 land in the same clock hour       ≤183
    |
    | THE WEEKLY FAR RUN IS NOT IN THAT SUM, on purpose: it is scheduled into the
    | 04:00 hour precisely so that it never shares a clock hour with the sweep.
    | The whole table, including where it breaks, is in the `poll` section above.
    |
    | The watchlist is what somebody asked to be told about and gets the wide
    | window; a rule's candidate routes are a guess at what they might like and
    | get the near half of it. Sweeping them six months deep would be 30 × 7 =
    | 210 requests for ONE rule — over the hourly limit on its own, before the
    | watchlist poll that ran half an hour earlier is counted.
    |
    | 89 DAYS, NOT 90, for the reason `poll.window_days` is 181 and not 183: a
    | 90-day window reaches a fifth calendar month on three mornings a year and
    | 89 never does, so the 120 above is a ceiling rather than an average. It is
    | otherwise exactly the horizon every poll in this app had before the window
    | widened, which is the honest way to describe what this key changed: the
    | sweep still costs what it always cost.
    |
    | WHAT THE SHORTER HORIZON COSTS, stated plainly: a rule whose date window
    | names a month beyond it — "somewhere sunny in February", written in
    | August — still MATCHES on any route Orbit already holds fares for, because
    | App\Application\Rules\RuleMatches reads the calendar rather than the
    | provider, and a WATCHED route's calendar now runs ELEVEN months deep. What
    | the rule does not get is speculative fares for that month on routes nobody
    | watches: they are fetched three months out, and the far month fills in as
    | the calendar rolls toward it. See App\Jobs\SweepRuleFares.
    |
    | THE SWEEP DID NOT MOVE WHEN THE HORIZON DID, and that is the same decision
    | as before rather than an oversight: 30 × 12 = 360 requests for one rule is
    | not close to affordable, and a rule is still a guess at what somebody might
    | like where the watchlist is what they asked to be told about.
    |
    */

    'rules' => [
        'warm_at' => 4,
        'warm_vibes' => ['sunny', 'beach'],
        'sweep_cap' => 30,
        'sweep_horizon_days' => 89,
        /* The design's match banner shows a handful, not a list (§4). */
        'sample' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | The installed app
    |--------------------------------------------------------------------------
    |
    | What "Add to Home Screen" reads, and the browser chrome colour the shell
    | declares. Both come from here for one reason: the manifest is generated by
    | App\Http\Controllers\Pwa\ManifestController and the meta tag is written by
    | resources/views/app.blade.php, and a colour written twice is a colour that
    | eventually disagrees with itself — the status bar one shade off the app
    | behind it, on a phone, months later.
    |
    | THE COLOURS ARE design/README.md's DARK `--bg`. Dark is the default theme
    | and the one the shell ships with (`<html data-theme="dark">`), so it is
    | also what an install and a cold launch should look like. A user who has
    | chosen light gets their theme the moment the bundle boots and rewrites the
    | meta tag; the manifest cannot follow that, and should not try — a
    | background_color that flickers per user is worse than one that matches the
    | icon it appears next to.
    |
    | NO env() HERE, deliberately. None of this varies by environment: staging
    | and production are the same app with the same name and the same icon, and
    | an installed PWA that renames itself between deploys is one the OS treats
    | as a different app.
    |
    */

    'pwa' => [
        'name' => 'Orbit',
        'short_name' => 'Orbit',
        'description' => 'Watches the routes you care about and tells you when one gets insanely cheap.',
        'theme_color' => '#0a0f1e',
        'background_color' => '#0a0f1e',
    ],

];
