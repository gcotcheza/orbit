<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Orbit
|--------------------------------------------------------------------------
|
| Everything about THIS app, not the framework. Why not env(): docs/BUSINESS-LOGIC.md §18.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | The single account
    |--------------------------------------------------------------------------
    |
    | Null password = generate + print once; empty .env value is null. docs/BUSINESS-LOGIC.md §19.
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
    | Storage stays UTC; what a person reads is local. docs/BUSINESS-LOGIC.md §20.
    |
    */

    'timezone' => env('ORBIT_TIMEZONE', 'Europe/Amsterdam'),

    /*
    |--------------------------------------------------------------------------
    | Fare providers
    |--------------------------------------------------------------------------
    |
    | Four switches, bound by name in AppServiceProvider. docs/BUSINESS-LOGIC.md §21.
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
    | Read when `providers.price` is `travelpayouts`. docs/BUSINESS-LOGIC.md §22.
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
    | Self-computed statistics — what a route usually costs
    |--------------------------------------------------------------------------
    |
    | Read when `providers.stats` is `self`. docs/BUSINESS-LOGIC.md §23.
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
    | Rule-engine origins and a request budget, not a validation list. docs/BUSINESS-LOGIC.md §24.
    |
    */

    'origins' => ['AMS', 'EIN', 'DUS'],

    /*
    |--------------------------------------------------------------------------
    | The deal score
    |--------------------------------------------------------------------------
    |
    | Read once into App\Domain\Pricing\ScoringPolicy, pure PHP. docs/BUSINESS-LOGIC.md §25.
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
    | AlertPolicy's rule book for when Orbit may interrupt somebody. docs/BUSINESS-LOGIC.md §26.
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
    | `window_days` (near, daily) vs `horizon_days` (far, weekly). docs/BUSINESS-LOGIC.md §27.
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
    | Polled daily; nothing reads the table yet. docs/BUSINESS-LOGIC.md §28.
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
    | `fresh_for_hours` is both "is it fresh" and "did we already ask". docs/BUSINESS-LOGIC.md §29.
    |
    */

    'lookup' => [
        'fresh_for_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery — the routes nobody is watching
    |--------------------------------------------------------------------------
    |
    | "Surprise me," not "find cheap fares". docs/BUSINESS-LOGIC.md §30.
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
    | `key` defaults to null; the check degrades to skipped. docs/BUSINESS-LOGIC.md §31.
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

        // The quota probe again, on the deadline a SCREEN may wait rather than
        // the one a nightly run may. See §31.
        'settings_timeout' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | "Seen 3 days ago — may be gone", and the way to find out
    |--------------------------------------------------------------------------
    |
    | The demotion needs BOTH halves (48h old, 20% under usual). docs/BUSINESS-LOGIC.md §17.
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
    | Sparkline 14, detail chart 60 (design/README.md §1–§2); backfill feeds FakeHistorySeeder.
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
    | design/README.md §3; the API returns the verdict, not these thresholds.
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
    | Deep links only; hosts here, path shapes in BookingLink. docs/BUSINESS-LOGIC.md §32.
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
    | `parser` defaults to regex; Anthropic takes over when a key lands. docs/BUSINESS-LOGIC.md §33.
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

        // Closed vocabulary; a new KEY (not a synonym) matches nothing.
        // Longest phrase first per vibe — the regex parser needs it. docs/BUSINESS-LOGIC.md §33.
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
    | `warm_at` gates the best month; `sweep_cap` bounds the sweep. docs/BUSINESS-LOGIC.md §34.
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
    | The browser sandbox's frozen clock
    |--------------------------------------------------------------------------
    |
    | Both keys are .env.e2e's and nothing else's. docs/E2E.md "A frozen clock".
    |
    */

    'e2e' => [
        'enabled'   => (bool) env('ORBIT_E2E', false),
        'fixed_now' => env('E2E_FIXED_NOW') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | The installed app
    |--------------------------------------------------------------------------
    |
    | No env() — staging and production must look identical. docs/BUSINESS-LOGIC.md §35.
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
