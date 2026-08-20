<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Orbit
|--------------------------------------------------------------------------
|
| Everything about THIS app rather than the framework it runs on.
| Why a config file rather than env() at the call site: docs/BUSINESS-LOGIC.md §18.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | The single account
    |--------------------------------------------------------------------------
    |
    | Null password = generate + print once; empty .env value is treated as
    | null. Why: docs/BUSINESS-LOGIC.md §19.
    |
    */

    'seed' => [
        'email'    => env('SEED_USER_EMAIL', 'ghie.cotcheza@gmail.com'),
        'name'     => env('SEED_USER_NAME', 'Ghie'),
        'password' => env('SEED_USER_PASSWORD') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | The clock the owner lives on
    |--------------------------------------------------------------------------
    |
    | Storage stays UTC; everything a person reads is local. Why:
    | docs/BUSINESS-LOGIC.md §20.
    |
    */

    'timezone' => env('ORBIT_TIMEZONE', 'Europe/Amsterdam'),

    /*
    |--------------------------------------------------------------------------
    | Fare providers
    |--------------------------------------------------------------------------
    |
    | Three ports plus a sweep switch, chosen by name, bound in AppServiceProvider.
    | Why four switches, why `fake` is still the default: docs/BUSINESS-LOGIC.md §21.
    |
    */

    'providers' => [
        'price'   => env('ORBIT_PRICE_PROVIDER', 'fake'),
        'stats'   => env('ORBIT_STATS_PROVIDER', 'fake'),
        'returns' => env('ORBIT_RETURNS_PROVIDER', 'fake'),

        // ⚠ Defaults to `price`, deliberately — see §21 for why a fake sweep
        // against real prices is the exact failure this switch prevents.
        'sweep' => env('ORBIT_SWEEP_PROVIDER') ?: env('ORBIT_PRICE_PROVIDER', 'fake'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Travelpayouts — the real fares
    |--------------------------------------------------------------------------
    |
    | Read when `providers.price` is `travelpayouts`. Endpoint choice, token
    | placement, timeouts and the currency guard: docs/BUSINESS-LOGIC.md §22.
    |
    */

    'travelpayouts' => [
        'base_url' => env('TRAVELPAYOUTS_BASE_URL', 'https://api.travelpayouts.com'),

        'token' => env('TRAVELPAYOUTS_TOKEN'),

        // Not sent by the fare adapter; appended to the Aviasales booking
        // link only. Unset is fine — see §22 and §32.
        'marker' => env('TRAVELPAYOUTS_MARKER'),

        'connect_timeout' => 5,
        'timeout'         => 15,

        'retries'        => 1,
        'retry_delay_ms' => 500,

        'warn_every_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Self-computed statistics — what a route usually costs, from our own data
    |--------------------------------------------------------------------------
    |
    | Read when `providers.stats` is `self`. The blend arithmetic and why each
    | horizon is capped where it is: docs/BUSINESS-LOGIC.md §23.
    |
    */

    'selfstats' => [
        'maturity_observations' => 30,
        'history_days'          => 365,
        'cross_section_days'    => 181,
    ],

    /*
    |--------------------------------------------------------------------------
    | Where the owner flies from
    |--------------------------------------------------------------------------
    |
    | Still the rule engine's origins and a request-budget bound, even though
    | it stopped being a search-input validation list. Why: docs/BUSINESS-LOGIC.md §24.
    |
    */

    'origins' => ['AMS', 'EIN', 'DUS'],

    /*
    |--------------------------------------------------------------------------
    | The deal score
    |--------------------------------------------------------------------------
    |
    | Read once into App\Domain\Pricing\ScoringPolicy, which is pure PHP.
    | Weights, tiers and trend saturation: docs/BUSINESS-LOGIC.md §25.
    |
    */

    'score' => [
        'weights' => [
            'percentile' => 60,
            'trend'      => 25,
            'absolute'   => 15,
        ],

        'tiers' => [
            'insane' => 80,
            'great'  => 65,
            'good'   => 50,
        ],

        'trend_days'               => 30,
        'trend_saturation_per_day' => 0.005,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | When Orbit is allowed to interrupt somebody — AlertPolicy's rule book, read once.
    | Why each number, and why `blurb`/`tier` live here: docs/BUSINESS-LOGIC.md §26.
    |
    */

    'alerts' => [
        // Below this many daily observations, the score is unpublished and
        // cannot alert -- the day-1 honesty rule. Why: docs/BUSINESS-LOGIC.md §26.
        'min_tracking_days' => 7,

        // One rule, two numbers: an alert is held only when BOTH the fare is
        // stale AND departure is near. Why: docs/BUSINESS-LOGIC.md §26.
        'max_fare_age_days'    => 2,
        'near_departure_weeks' => 3,

        'cooldown_hours' => 24,

        // What beats the cooldown: a further 5% drop is new information, not
        // a repeat. Why: docs/BUSINESS-LOGIC.md §26.
        'further_drop_percent' => 5,

        'mail_deals' => 6,

        'digest_days' => 7,

        'sensitivities' => [
            0 => [
                'name'  => 'Relaxed',
                'tier'  => 'insane',
                'blurb' => 'Only the truly insane deals — score %d and up. Rare, and worth clearing a weekend for.',
            ],
            1 => [
                'name'  => 'Balanced',
                'tier'  => 'great',
                'blurb' => 'Anything Orbit rates a great deal — score %d and up. A handful a month.',
            ],
            2 => [
                'name'  => 'Eager',
                'tier'  => 'good',
                'blurb' => 'Every fare scoring %d or better. More to look at, and more that turns out to be ordinary.',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Polling — eleven months, at two speeds
    |--------------------------------------------------------------------------
    |
    | `window_days` (near, daily) vs `horizon_days` (far, weekly) answer two questions.
    | Full budget table and staleness reasoning: docs/BUSINESS-LOGIC.md §27.
    |
    */

    'poll' => [
        'window_days'          => 181,
        'horizon_days'         => 334,
        'far_refresh_weekday'  => 6,
        'stagger_minutes'      => 3,
        'stale_after_days'     => 3,
        'far_stale_after_days' => 17,
    ],

    /*
    |--------------------------------------------------------------------------
    | Round trips — going and coming back
    |--------------------------------------------------------------------------
    |
    | The round-trip table nothing reads yet (polled daily since the foundation PR).
    | Budget, schedule reasoning and the duration bands: docs/BUSINESS-LOGIC.md §28.
    |
    */

    'returns' => [
        'window_days'      => 334,
        'stale_after_days' => 3,
        'max_nights'       => 60,

        // Must be sent -- its absence is a silent 91% data loss (API default
        // is 30 records, not this table's 1000). See §28.
        'limit' => 1000,

        'durations' => [
            [2, 3],
            [6, 8],
            [13, 15],
            [21, 28],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Looking a route up before watching it
    |--------------------------------------------------------------------------
    |
    | `fresh_for_hours` covers both "is the data fresh" and "did we already ask".
    | Why, and why the near window not the horizon: docs/BUSINESS-LOGIC.md §29.
    |
    */

    'lookup' => [
        'fresh_for_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery — the insanely cheap routes nobody is watching
    |--------------------------------------------------------------------------
    |
    | "Surprise me," not "find cheap fares" -- every default read off one real run.
    | Full reasoning, every threshold, the second lane's flywheel: docs/BUSINESS-LOGIC.md §30.
    |
    */

    'discovery' => [
        'min_kilometres' => 400,

        'max_price_eur' => 120,

        // Ranking floor in EUR/km, not the sort -- see §30 for why a floor
        // makes an empty discovery screen reachable.
        'max_eur_per_km' => 0.030,

        // A fare that won't say how old it is counts as too old here -- the
        // opposite of AlertPolicy's reading of the same null. See §30.
        'max_found_age_days' => 3,

        'shortlist' => 5,

        'max_percentile' => 10,

        'min_absolute_savings_eur' => 15,

        'expires_after_hours' => 36,

        'max_rows' => 12,

        // The near window, written out rather than referenced -- drift guard
        // is tests/Feature/DiscoveryRunTest. See §30.
        'verify_window_days' => 181,

        // Lane B: "cheap for THIS route," not "cheap, period" -- why a free
        // distance-band baseline fails, and the flywheel: docs/BUSINESS-LOGIC.md §30.
        'lanes' => [
            'relative' => [
                'max_price_eur' => 150,

                'min_discount' => 0.40,

                'min_baseline_days' => 10,

                'max_baseline_age_days' => 30,

                'shortlist' => 3,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SerpAPI — asking Google whether we are telling the truth
    |--------------------------------------------------------------------------
    |
    | `key` defaults to null; the feature degrades to "skip the check".
    | What the verdict compares, and the spend guardrails: docs/BUSINESS-LOGIC.md §31.
    |
    */

    'serpapi' => [
        'base_url' => env('SERPAPI_BASE_URL', 'https://serpapi.com'),

        // Null is an ordinary supported state here, unlike TRAVELPAYOUTS_TOKEN.
        // See §31.
        'key' => env('SERPAPI_KEY'),

        // Reserved for alert verification, a feature that doesn't exist yet --
        // see §31 for why the less important feature yields.
        'reserve' => 50,

        // Same number as discovery.shortlist but a separate decision -- the
        // guard against a verification-loop-turned-sweep. See §31.
        'max_per_run' => 5,

        'connect_timeout' => 5,
        'timeout'         => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | "Seen 3 days ago — may be gone", and the way to find out
    |--------------------------------------------------------------------------
    |
    | The demotion needs BOTH halves (48h old, 20% under usual); the cooldown
    | is how long one paid answer is worth. The reasoning: docs/BUSINESS-LOGIC.md §17.
    |
    */

    'live_check' => [
        'stale_after_hours'     => 48,
        'under_usual_percent'   => 20,
        'cooldown_hours'        => 6,
        'contradiction_percent' => 10,
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
        'chart_days'     => 60,
        'backfill_days'  => 60,
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
        'cheap_at'  => 0.28,
        'pricey_at' => 0.66,
    ],

    /*
    |--------------------------------------------------------------------------
    | Booking
    |--------------------------------------------------------------------------
    |
    | No booking API, deep links only — Aviasales primary, Skyscanner second opinion.
    | Only the hosts live here; path shapes belong to BookingLink: docs/BUSINESS-LOGIC.md §32.
    |
    */

    'booking' => [
        // No path, deliberately: BookingLink picks between two Aviasales entry
        // points off this host. See §32.
        'aviasales_base' => 'https://www.aviasales.com',

        'skyscanner_base' => 'https://www.skyscanner.nl/transport/flights',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reading a rule written in English
    |--------------------------------------------------------------------------
    |
    | `parser` defaults to regex; Anthropic takes over once a key lands in .env,
    | composing regex as its own fallback. Why, and why Haiku: docs/BUSINESS-LOGIC.md §33.
    |
    */

    'nlp' => [
        'parser' => env('ORBIT_NLP_PARSER') ?: (env('ANTHROPIC_API_KEY') ? 'anthropic' : 'regex'),

        'api_key' => env('ANTHROPIC_API_KEY'),

        'model' => env('ORBIT_NLP_MODEL', 'claude-haiku-4-5-20251001'),

        // Ceiling, not a target -- a max-tokens stop is treated as a failure
        // and falls back. See §33.
        'max_tokens' => 1024,

        // These are the PSR-18 client's timeouts, not the SDK's advisory one
        // -- see AppServiceProvider and §33.
        'connect_timeout' => 5,
        'timeout'         => 30,
        'max_retries'     => 1,

        // What a person types instead of the `origins` code above -- a
        // different fact, drift-guarded by tests/Feature/SeedersTest.
        'origin_aliases' => [
            'ams'        => 'AMS',
            'amsterdam'  => 'AMS',
            'schiphol'   => 'AMS',
            'ein'        => 'EIN',
            'eindhoven'  => 'EIN',
            'dus'        => 'DUS',
            'düsseldorf' => 'DUS',
            'dusseldorf' => 'DUS',
        ],

        // Keys are the closed vocabulary from european_destinations.php
        // (SeedersTest drift guard); adding a KEY (not a synonym) matches
        // nothing. Longest phrase first per vibe -- RegexRuleTextParser relies
        // on the order. See §33.
        'vibe_words' => [
            'sunny'   => ['sunshine', 'sunny', 'warm', 'hot', 'sun'],
            'beach'   => ['seaside', 'beaches', 'beach', 'coastal', 'coast', 'sand', 'swimming'],
            'city'    => ['city break', 'citybreak', 'city', 'urban'],
            'culture' => ['cultural', 'culture', 'museums', 'museum', 'history', 'historic', 'art'],
            'food'    => ['gastronomy', 'restaurants', 'foodie', 'food', 'cuisine', 'wine'],
            'islands' => ['archipelago', 'islands', 'island'],
            'nature'  => ['mountains', 'mountain', 'outdoors', 'hiking', 'hike', 'nature', 'forest', 'scenery'],
            'party'   => ['nightlife', 'clubbing', 'party', 'bars'],
            'ski'     => ['snowboard', 'skiing', 'slopes', 'piste', 'snow', 'ski'],
        ],

        // Lives here, not in the Vue component, for the same reason the
        // sensitivity blurbs do -- see §33.
        'vibe_labels' => [
            'sunny'   => '☀ Sunny',
            'beach'   => '🏖 Beach',
            'city'    => 'City break',
            'culture' => 'Culture',
            'food'    => 'Food',
            'islands' => 'Islands',
            'nature'  => 'Nature',
            'party'   => 'Nightlife',
            'ski'     => 'Snow',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Matching a rule against the world
    |--------------------------------------------------------------------------
    |
    | `warm_at`/`warm_vibes` gate on the rule window's best month. `sweep_cap` and
    | `sweep_horizon_days` are the sweep's request budget. Full arithmetic: docs/BUSINESS-LOGIC.md §34.
    |
    */

    'rules' => [
        'warm_at'            => 4,
        'warm_vibes'         => ['sunny', 'beach'],
        'sweep_cap'          => 30,
        'sweep_horizon_days' => 89,
        'sample'             => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | The installed app
    |--------------------------------------------------------------------------
    |
    | Manifest and meta tag both read from here so the colour never drifts.
    | No env() -- staging and production must look identical: docs/BUSINESS-LOGIC.md §35.
    |
    */

    'pwa' => [
        'name'             => 'Orbit',
        'short_name'       => 'Orbit',
        'description'      => 'Watches the routes you care about and tells you when one gets insanely cheap.',
        'theme_color'      => '#0a0f1e',
        'background_color' => '#0a0f1e',
    ],

];
