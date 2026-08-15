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
    | Alert sensitivity
    |--------------------------------------------------------------------------
    |
    | The three positions of the segmented control on the alerts screen
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
