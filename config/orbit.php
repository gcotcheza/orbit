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
    | Two ports (App\Application\Ports\PriceProvider, PriceStatsProvider), one
    | adapter each, chosen by name here and bound in AppServiceProvider.
    |
    | `fake` is the DEFAULT and is not a test double: docs/PLAN.md ships the
    | app before the Travelpayouts and Amadeus keys exist, so the fake is the
    | adapter production actually runs until they arrive. It is deterministic
    | per route, so the same route shows the same prices on every deploy and a
    | feature test can assert real numbers.
    |
    | Adding the real ones later is a class plus a line in the match() in
    | AppServiceProvider plus ORBIT_PRICE_PROVIDER=travelpayouts in .env. No
    | call site changes, because no call site names an adapter.
    |
    */

    'providers' => [
        'price' => env('ORBIT_PRICE_PROVIDER', 'fake'),
        'stats' => env('ORBIT_STATS_PROVIDER', 'fake'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the owner flies from
    |--------------------------------------------------------------------------
    |
    | The three airports within a sensible drive, and therefore the only
    | origins the "add a route" form (design/README.md §5) offers and the only
    | ones App\Http\Requests\AddWatchedRouteRequest accepts. A destination can
    | be anywhere Orbit knows; an origin cannot, because a fare from Malaga is
    | not a flight this person can take.
    |
    | THE SAME THREE ARE FLAGGED `is_origin` BY DestinationSeeder, from
    | database/seeders/data/european_destinations.php. Two lists of one fact is
    | a drift waiting to happen, so tests/Feature/SeedersTest asserts they
    | agree — the seeder's list is the one that carries the coordinates, and
    | this one is what a request is validated against without a query.
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
    | Polling
    |--------------------------------------------------------------------------
    |
    | WINDOW_DAYS is how far ahead a poll looks, and it is the definition of
    | "the current price": the cheapest fare in the next 90 days. Widening it
    | makes every route look cheaper, so it is one number in one place rather
    | than a literal in the job and a different literal in the calendar.
    |
    | STAGGER_MINUTES spaces the per-route jobs so six providers calls do not
    | arrive as a burst — the real APIs are rate-limited per minute.
    |
    */

    'poll' => [
        'window_days' => 90,
        'stagger_minutes' => 3,
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
    | on Skyscanner deep links. The path shape is
    | `/transport/flights/{origin}/{dest}/{yymmdd}/` with lower-case IATA.
    |
    */

    'booking' => [
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
    | queue. A rule with no vibe at all is 3 origins × 77 destinations = 231
    | provider calls, which is a rate limit spent on a sentence somebody typed
    | and may delete a minute later. The cap keeps the best-fitting thirty and
    | logs the rest — see App\Jobs\SweepRuleFares for what "best" means.
    |
    */

    'rules' => [
        'warm_at' => 4,
        'warm_vibes' => ['sunny', 'beach'],
        'sweep_cap' => 30,
        /* The design's match banner shows a handful, not a list (§4). */
        'sample' => 6,
    ],

];
