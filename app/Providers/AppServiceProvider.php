<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Application\Ports\DealNotifier;
use App\Application\Ports\OriginSweepProvider;
use App\Application\Ports\PriceProvider;
use App\Application\Ports\PriceStatsProvider;
use App\Application\Ports\ReturnTripProvider;
use App\Application\Ports\RuleTextParser;
use App\Domain\Alerts\AlertPolicy;
use App\Domain\Discovery\DiscoveryPolicy;
use App\Domain\Pricing\DealScorer;
use App\Domain\Pricing\ScoringPolicy;
use App\Domain\Rules\RuleMatcher;
use App\Domain\Rules\RuleVocabulary;
use App\Infrastructure\Discovery\FakeSweepProvider;
use App\Infrastructure\Discovery\TravelpayoutsSweepProvider;
use App\Infrastructure\Nlp\AnthropicRuleTextParser;
use App\Infrastructure\Nlp\RegexRuleTextParser;
use App\Infrastructure\Notify\MailDealNotifier;
use App\Infrastructure\Notify\MarkAlertsDelivered;
use App\Infrastructure\Pricing\FakePriceProvider;
use App\Infrastructure\Pricing\FakeReturnProvider;
use App\Infrastructure\Pricing\FakeStatsProvider;
use App\Infrastructure\Pricing\SelfStatsProvider;
use App\Infrastructure\Pricing\TravelpayoutsPriceProvider;
use App\Infrastructure\Pricing\TravelpayoutsReturnProvider;
use App\Infrastructure\Verify\GoogleFlightsCheck;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * THE THREE FARE PORTS, chosen by name in config/orbit.php.
         *
         * This is the whole of the hexagon's wiring: nothing in App\Domain or
         * App\Application knows an adapter exists, so swapping a fake for the
         * real thing is a class, a line in the match() below and a variable in
         * .env. No call site changes because no call site names an adapter —
         * which is how `self` could replace a paid statistics API that no
         * longer exists without a single reader of a "usual price" moving.
         *
         * AN UNKNOWN NAME THROWS AT RESOLUTION rather than silently falling
         * back to the fake. A production box that answers with invented prices
         * because somebody typo'd `travelpayots` is the single worst failure
         * mode this app has — it would send a real alert about a fare that
         * does not exist.
         */
        $this->app->bind(PriceProvider::class, fn (): PriceProvider => match ($name = config('orbit.providers.price')) {
            'fake' => new FakePriceProvider,
            'travelpayouts' => $this->travelpayoutsPrices(),
            default => throw new InvalidArgumentException(sprintf('Unknown price provider [%s].', is_string($name) ? $name : gettype($name))),
        });

        $this->app->bind(PriceStatsProvider::class, fn (): PriceStatsProvider => match ($name = config('orbit.providers.stats')) {
            'fake' => new FakeStatsProvider,
            'self' => $this->selfStats(),
            default => throw new InvalidArgumentException(sprintf('Unknown price statistics provider [%s].', is_string($name) ? $name : gettype($name))),
        });

        /*
         * ROUND TRIPS ARE THEIR OWN SWITCH, deliberately, even though the real
         * adapter talks to the same vendor as the one-way one. The two read
         * different endpoints with different coverage, and the return-trip half
         * is the newer and much thinner of them — so a box must be able to run
         * real one-way fares (which every score and alert depends on) while
         * returns are still coming from the fake. See config/orbit.php.
         */
        $this->app->bind(ReturnTripProvider::class, fn (): ReturnTripProvider => match ($name = config('orbit.providers.returns')) {
            'fake' => new FakeReturnProvider,
            'travelpayouts' => $this->travelpayoutsReturns(),
            default => throw new InvalidArgumentException(sprintf('Unknown return-trip provider [%s].', is_string($name) ? $name : gettype($name))),
        });

        /*
         * THE ORIGIN SWEEP — the fourth fare port, and the only one whose
         * question has no destination in it ("what is cheap from here, to
         * anywhere").
         *
         * ITS OWN SWITCH, even though the real adapter reads the SAME endpoint
         * as the return-trip one. `/v2/prices/latest` answers three different
         * questions depending on whether `destination` is present and what
         * `one_way` says, and the two adapters take opposite answers to both —
         * so they can fail, and be turned off, independently. See
         * config/orbit.php.
         */
        $this->app->bind(OriginSweepProvider::class, fn (): OriginSweepProvider => match ($name = config('orbit.providers.sweep')) {
            'fake' => new FakeSweepProvider,
            'travelpayouts' => $this->travelpayoutsSweep(),
            default => throw new InvalidArgumentException(sprintf('Unknown origin sweep provider [%s].', is_string($name) ? $name : gettype($name))),
        });

        /*
         * THE SECOND OPINION, and the only thing in this app that talks to
         * anyone but Travelpayouts and Anthropic.
         *
         * BOUND DIRECTLY RATHER THAN BY NAME, unlike the four fare ports above,
         * because there is nothing to choose between: SerpAPI is not one of two
         * ways to ask Google, it is the one that exists. The switch that would
         * otherwise live in config/orbit.php's `providers` is the KEY — absent,
         * and App\Infrastructure\Verify\GoogleFlightsCheck answers "no budget"
         * to everything and no verification happens. That is a supported state
         * and the default one, which is exactly why it is not an adapter name a
         * deploy could typo.
         *
         * NOT A SINGLETON. It holds no state between calls — the run's budget
         * is the caller's to count (see App\Jobs\DiscoverDeals) — and binding
         * it fresh keeps a config change during a test visible without a
         * container flush.
         */
        $this->app->bind(GoogleFlightsCheck::class, function (): GoogleFlightsCheck {
            /** @var array<string, mixed> $serpapi */
            $serpapi = config('orbit.serpapi');

            /** @var mixed $key */
            $key = $serpapi['key'] ?? null;

            return new GoogleFlightsCheck(
                http: $this->app->make(HttpFactory::class),
                logger: $this->app->make('log'),
                baseUrl: (string) $serpapi['base_url'],
                /*
                 * AN EMPTY STRING IS NULL. `SERPAPI_KEY=` in an .env file is
                 * somebody not setting it rather than somebody setting it to
                 * nothing — the same reading `seed.password` takes of the same
                 * shape — and the difference decides whether a run tries to
                 * authenticate with the empty string against a metered API.
                 */
                key: is_string($key) && trim($key) !== '' ? $key : null,
                reserve: (int) $serpapi['reserve'],
                maxPerRun: (int) $serpapi['max_per_run'],
                connectTimeout: (float) $serpapi['connect_timeout'],
                timeout: (float) $serpapi['timeout'],
            );
        });

        /*
         * THE DISCOVERY FUNNEL'S NUMBERS, read once and handed to a pure value
         * — the arrangement DealScorer, ScoringPolicy and AlertPolicy have, and
         * for the same reason: App\Domain\Discovery\CandidateScorer is
         * arithmetic and calls no config().
         *
         * THE TWO EURO FIGURES BECOME CENTS HERE, at the boundary, because
         * everything below HTTP in this app is integer cents and config is
         * where a person reads "€120". The `max_eur_per_km` conversion is the
         * one to look at twice: euros per kilometre × 100 is CENTS per
         * kilometre, so 0.030 €/km is 3.0 cents/km, which is what
         * DealCandidate::centsPerKilometre() answers in.
         */
        $this->app->singleton(DiscoveryPolicy::class, function (): DiscoveryPolicy {
            /** @var array<string, mixed> $discovery */
            $discovery = config('orbit.discovery');

            return new DiscoveryPolicy(
                minKilometres: (float) $discovery['min_kilometres'],
                maxPriceCents: (int) round(((float) $discovery['max_price_eur']) * 100),
                maxCentsPerKilometre: ((float) $discovery['max_eur_per_km']) * 100,
                maxFoundAgeDays: (int) $discovery['max_found_age_days'],
                shortlist: (int) $discovery['shortlist'],
                maxPercentile: (float) $discovery['max_percentile'],
                minSavingsCents: (int) round(((float) $discovery['min_absolute_savings_eur']) * 100),
                expiresAfterHours: (int) $discovery['expires_after_hours'],
                maxRows: (int) $discovery['max_rows'],
            );
        });

        /*
         * The scoring rule's numbers, read once and handed to a pure-PHP
         * value. App\Domain\Pricing\DealScorer calls no framework function,
         * config() included, so this is the only place the two meet.
         */
        $this->app->singleton(DealScorer::class, function (): DealScorer {
            /** @var array{weights: array{percentile: int|float, trend: int|float, absolute: int|float}, tiers: array{insane: int, great: int, good: int}, trend_days: int, trend_saturation_per_day: int|float} $score */
            $score = config('orbit.score');

            return new DealScorer(new ScoringPolicy(
                percentileWeight: (float) $score['weights']['percentile'],
                trendWeight: (float) $score['weights']['trend'],
                absoluteWeight: (float) $score['weights']['absolute'],
                insaneAt: $score['tiers']['insane'],
                greatAt: $score['tiers']['great'],
                goodAt: $score['tiers']['good'],
                trendDays: $score['trend_days'],
                trendSaturationPerDay: (float) $score['trend_saturation_per_day'],
                /*
                 * FROM THE ALERTS SECTION, ON PURPOSE. It is the same number
                 * App\Domain\Alerts\AlertPolicy is given below, because "young
                 * enough that we will not mail about it" and "young enough that
                 * we will not put a verdict on it" have to be one decision. See
                 * config/orbit.php.
                 */
                minTrackingDays: (int) config('orbit.alerts.min_tracking_days'),
            ));
        });

        /*
         * THE VOCABULARY, once. App\Domain is pure PHP and calls no config(),
         * so the words the rule parser works from are read here and handed in
         * — the same arrangement DealScorer and ScoringPolicy have above.
         *
         * A SINGLETON because it is immutable and three things want it (both
         * parsers and App\Application\Rules\RuleViews), not as an optimisation.
         */
        $this->app->singleton(RuleVocabulary::class, function (): RuleVocabulary {
            /** @var list<string> $origins */
            $origins = config('orbit.origins');
            /** @var array<string, string> $aliases */
            $aliases = config('orbit.nlp.origin_aliases');
            /** @var array<string, list<string>> $vibeWords */
            $vibeWords = config('orbit.nlp.vibe_words');
            /** @var array<string, string> $vibeLabels */
            $vibeLabels = config('orbit.nlp.vibe_labels');

            return new RuleVocabulary($origins, $aliases, $vibeWords, $vibeLabels);
        });

        /*
         * THE ALERT RULE BOOK, read once and handed to a pure value — the same
         * arrangement DealScorer and ScoringPolicy have above, and for the same
         * reason: App\Domain\Alerts\AlertPolicy decides whether to interrupt
         * somebody and calls no framework function, config() included.
         */
        $this->app->singleton(AlertPolicy::class, fn (): AlertPolicy => new AlertPolicy(
            cooldownHours: (int) config('orbit.alerts.cooldown_hours'),
            furtherDropPercent: (int) config('orbit.alerts.further_drop_percent'),
            minTrackingDays: (int) config('orbit.alerts.min_tracking_days'),
            /* The freshness guard's two halves — see config/orbit.php. */
            maxFareAgeDays: (int) config('orbit.alerts.max_fare_age_days'),
            nearDepartureWeeks: (int) config('orbit.alerts.near_departure_weeks'),
        ));

        /*
         * WHERE ALERTS GO — the fourth port, and the only one with a single
         * adapter today.
         *
         * BOUND DIRECTLY RATHER THAN BY NAME IN CONFIG, unlike the fare
         * providers and the rule parser above, because there is nothing to
         * choose between: mail is not one of two ways to send an alert, it is
         * the one that exists. docs/PLAN.md's web push arrives after the PWA
         * shell and will be an ADDITION rather than an alternative — both
         * channels at once, gated by their own switches — so the day it lands
         * this line becomes a small composite over the two adapters, and the
         * choice it makes will still not be a string in an .env file.
         *
         * Whether mail actually leaves the box is MAIL_MAILER's business: `log`
         * until ghiecode.io is verified as a sending domain in Resend.
         */
        $this->app->bind(DealNotifier::class, MailDealNotifier::class);

        $this->app->singleton(RuleMatcher::class, function (): RuleMatcher {
            /** @var list<string> $warmVibes */
            $warmVibes = config('orbit.rules.warm_vibes');

            return new RuleMatcher((int) config('orbit.rules.warm_at'), $warmVibes);
        });

        /*
         * THE RULE PARSER, chosen by name in config/orbit.php — the third port
         * this app has and the only one whose adapters are layered rather than
         * alternatives: the anthropic one COMPOSES the regex one and hands
         * over on any failure, so a refusal or an outage costs a smarter
         * reading rather than the screen.
         *
         * AN UNKNOWN NAME THROWS AT RESOLUTION, same as the fare providers. A
         * box that silently fell back to regex because somebody typo'd
         * `anthropc` would look like it was working while quietly reading
         * sentences worse than it was paid to.
         */
        $this->app->bind(RuleTextParser::class, function (): RuleTextParser {
            $regex = new RegexRuleTextParser($this->app->make(RuleVocabulary::class));

            return match ($name = config('orbit.nlp.parser')) {
                'regex' => $regex,
                'anthropic' => $this->anthropicParser($regex),
                default => throw new InvalidArgumentException(sprintf('Unknown rule parser [%s].', is_string($name) ? $name : gettype($name))),
            };
        });
    }

    /**
     * The real fare adapter, with its numbers read out of config once.
     *
     * THE SAME ARRANGEMENT `anthropicParser()` BELOW HAS, and for the same
     * reason: the adapter is handed scalars rather than being allowed to call
     * config() itself, so `php artisan config:cache` and a test that overrides
     * a timeout both behave the way a reader expects.
     *
     * A MISSING TOKEN THROWS FROM THE ADAPTER'S CONSTRUCTOR — i.e. here, at
     * resolution, exactly like the unknown-name arm above. That is deliberate:
     * `ORBIT_PRICE_PROVIDER=travelpayouts` on a box with no token is a deploy
     * that must fail loudly at the first poll rather than serve an app with a
     * calendar that is quietly, permanently empty.
     */
    private function travelpayoutsPrices(): PriceProvider
    {
        /** @var array<string, mixed> $travelpayouts */
        $travelpayouts = config('orbit.travelpayouts');

        return new TravelpayoutsPriceProvider(
            http: $this->app->make(HttpFactory::class),
            logger: $this->app->make('log'),
            /* The default store, which is redis in production and an array in the suite. */
            cache: $this->app->make('cache.store'),
            baseUrl: (string) $travelpayouts['base_url'],
            token: (string) $travelpayouts['token'],
            connectTimeout: (float) $travelpayouts['connect_timeout'],
            timeout: (float) $travelpayouts['timeout'],
            retries: (int) $travelpayouts['retries'],
            retryDelayMs: (int) $travelpayouts['retry_delay_ms'],
            warnEveryMinutes: (int) $travelpayouts['warn_every_minutes'],
        );
    }

    /**
     * The real round-trip adapter.
     *
     * IT SHARES THE `travelpayouts` CONNECTION SETTINGS AND HAS ITS OWN
     * BEHAVIOUR SETTINGS, which is the split the two config sections describe:
     * the base URL, token, timeouts, retries and warning interval are facts
     * about talking to that vendor and are the same for both adapters, while
     * `max_nights` and `limit` are facts about this endpoint's answer and live
     * in `orbit.returns`. Duplicating the connection half would mean a token
     * rotation that half-worked.
     *
     * A MISSING TOKEN THROWS FROM THE CONSTRUCTOR, here, at resolution — the
     * same rule `travelpayoutsPrices()` follows and for the same reason.
     */
    private function travelpayoutsReturns(): ReturnTripProvider
    {
        /** @var array<string, mixed> $travelpayouts */
        $travelpayouts = config('orbit.travelpayouts');
        /** @var array<string, mixed> $returns */
        $returns = config('orbit.returns');

        return new TravelpayoutsReturnProvider(
            http: $this->app->make(HttpFactory::class),
            logger: $this->app->make('log'),
            cache: $this->app->make('cache.store'),
            baseUrl: (string) $travelpayouts['base_url'],
            token: (string) $travelpayouts['token'],
            connectTimeout: (float) $travelpayouts['connect_timeout'],
            timeout: (float) $travelpayouts['timeout'],
            retries: (int) $travelpayouts['retries'],
            retryDelayMs: (int) $travelpayouts['retry_delay_ms'],
            warnEveryMinutes: (int) $travelpayouts['warn_every_minutes'],
            maxNights: (int) $returns['max_nights'],
            limit: (int) $returns['limit'],
        );
    }

    /**
     * The real origin-sweep adapter.
     *
     * IT SHARES THE `travelpayouts` CONNECTION SETTINGS, exactly as the
     * return-trip adapter does and for the same reason: base URL, token,
     * timeouts, retries and the warning interval are facts about talking to
     * that vendor and must move together, while `limit` is a fact about what
     * THIS endpoint does with a missing parameter.
     *
     * `limit` COMES FROM `orbit.returns` AND THAT IS NOT A MISTAKE. It is the
     * same parameter on the same endpoint — the one whose default of 30 silently
     * discarded 91% of AMS-BKK — and duplicating it into a `discovery.limit`
     * would be two keys for one fact about one API, with the copy that drifts
     * being the one nobody is looking at. The sweep is the more sensitive of
     * the two callers: 30 of 562 destinations is a "sweep the world" feature
     * quietly looking at 5% of it.
     *
     * A MISSING TOKEN THROWS FROM THE CONSTRUCTOR, here, at resolution — the
     * rule both sibling adapters follow.
     */
    private function travelpayoutsSweep(): OriginSweepProvider
    {
        /** @var array<string, mixed> $travelpayouts */
        $travelpayouts = config('orbit.travelpayouts');
        /** @var array<string, mixed> $returns */
        $returns = config('orbit.returns');

        return new TravelpayoutsSweepProvider(
            http: $this->app->make(HttpFactory::class),
            logger: $this->app->make('log'),
            cache: $this->app->make('cache.store'),
            baseUrl: (string) $travelpayouts['base_url'],
            token: (string) $travelpayouts['token'],
            connectTimeout: (float) $travelpayouts['connect_timeout'],
            timeout: (float) $travelpayouts['timeout'],
            retries: (int) $travelpayouts['retries'],
            retryDelayMs: (int) $travelpayouts['retry_delay_ms'],
            warnEveryMinutes: (int) $travelpayouts['warn_every_minutes'],
            limit: (int) $returns['limit'],
        );
    }

    /**
     * The statistics Orbit computes for itself.
     *
     * SCALARS OUT OF CONFIG, like every other adapter here. The blend's two
     * numbers are the whole of its behaviour — how much history makes a route
     * mature, and how far back "usual" reaches — and a test that wants to see
     * the immature end of the blend sets them rather than seeding a year.
     */
    private function selfStats(): PriceStatsProvider
    {
        /** @var array<string, mixed> $selfstats */
        $selfstats = config('orbit.selfstats');

        return new SelfStatsProvider(
            maturityObservations: (int) $selfstats['maturity_observations'],
            historyDays: (int) $selfstats['history_days'],
            crossSectionDays: (int) $selfstats['cross_section_days'],
        );
    }

    /**
     * The Claude-backed parser, with an explicit transporter.
     *
     * THE TRANSPORTER IS SUPPLIED RATHER THAN DISCOVERED, and that is the one
     * thing in this method that is not boilerplate. The SDK's own `timeout`
     * option is advisory — its source says so and never reads it — so the only
     * thing that will actually stop a hung request is the timeout on the
     * PSR-18 client we hand it. Left to php-http/discovery, a create screen
     * whose parse request never returns would sit on a spinner forever.
     *
     * `http_errors => false` because the SDK reads the status itself and turns
     * it into a typed exception; letting Guzzle throw first would replace the
     * API's own error text with a stack trace.
     */
    private function anthropicParser(RuleTextParser $fallback): RuleTextParser
    {
        /** @var array<string, mixed> $nlp */
        $nlp = config('orbit.nlp');

        return new AnthropicRuleTextParser(
            client: new AnthropicClient(
                apiKey: (string) $nlp['api_key'],
                requestOptions: [
                    'transporter' => new GuzzleClient([
                        'connect_timeout' => (float) $nlp['connect_timeout'],
                        'timeout' => (float) $nlp['timeout'],
                        'http_errors' => false,
                    ]),
                    'maxRetries' => (int) $nlp['max_retries'],
                ],
            ),
            fallback: $fallback,
            logger: $this->app->make('log'),
            vocabulary: $this->app->make(RuleVocabulary::class),
            model: (string) $nlp['model'],
            maxTokens: (int) $nlp['max_tokens'],
        );
    }

    public function boot(): void
    {
        /*
         * WHEN AN ALERT BECOMES DELIVERED. Laravel fires NotificationSent after
         * a channel has returned, which is the only moment in the pipeline that
         * the word honestly applies to — see App\Infrastructure\Notify\
         * MarkAlertsDelivered for why stamping the ledger at hand-off instead
         * would make `delivered_at` a synonym for `triggered_at`.
         *
         * REGISTERED EXPLICITLY rather than left to Laravel's listener
         * discovery: discovery scans app/Listeners, this app has no such
         * directory, and an alert pipeline whose delivery record depends on a
         * convention nothing else here follows is one refactor away from
         * silently never stamping anything.
         */
        Event::listen(NotificationSent::class, MarkAlertsDelivered::class);

        /*
         * The login throttle, keyed on the email AND the ip.
         *
         * On the email so that one address cannot be ground through a word
         * list from a botnet, and on the ip so that one host cannot walk a
         * list of addresses — either key alone leaves the other attack free.
         *
         * Five a minute. Orbit has ONE account, so this route is the entire
         * brute-force surface, and five is far more than a person mistypes
         * their own password while being a hard ceiling on a script.
         *
         * The email is lower-cased because addresses are case-insensitive in
         * practice and `Ghie@` / `ghie@` must not be two buckets.
         */
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        /*
         * The password-change throttle (`PUT /api/profile/password`).
         *
         * FIVE A MINUTE, THE SAME NUMBER THE LOGIN ROUTE ALLOWS, because it is
         * the same guess: `current_password` is the gate on that endpoint, so a
         * session left open on an unattended phone is otherwise a place to try
         * the current password as fast as the box will hash — with none of the
         * noise of a login form and none of the login limiter, which keys on an
         * email this request does not send. Five is far more than a person
         * mistypes a password they are about to retype twice more.
         *
         * KEYED ON THE ACCOUNT AND NOT THE IP, like the parser below and unlike
         * login above: the caller here is always authenticated, so there is a
         * better key than the address of a phone whose ip changes mid-sentence.
         * The ip fallback cannot be reached through the route — it is behind
         * `auth` — and is there so the limiter is total rather than relying on
         * that staying true.
         */
        RateLimiter::for('password-change', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * The rule parser's throttle (design/README.md §4).
         *
         * TWENTY A MINUTE, KEYED ON THE ACCOUNT. The create screen re-parses
         * on a 500 ms debounce, so continuous typing is roughly two calls a
         * second of which the debounce lets through a handful — twenty is
         * comfortably above what a person generates and comfortably below what
         * a stuck loop would.
         *
         * IT EXISTS BEFORE IT IS NEEDED, on purpose. Today the endpoint runs a
         * dozen regexes and could take any number of calls; the day an
         * Anthropic key lands in .env the same route becomes a metered
         * third-party request per keystroke, and nobody is going to remember
         * to add a limiter on that day.
         *
         * By user id and not by ip: this app has one account, several devices,
         * and a phone on mobile data whose ip changes mid-sentence.
         */
        RateLimiter::for('rules-parse', fn (Request $request): Limit => Limit::perMinute(20)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * The airport search's throttle (`GET /api/airports`).
         *
         * SIXTY A MINUTE, WHICH IS GENEROUS ON PURPOSE. This is the only
         * limiter in this file that does not stand in front of a cost: the
         * query is one indexed-ish scan of 3,270 rows and reaches no third
         * party. What it stands in front of is a CLIENT BUG — a debounce that
         * stopped debouncing, a watcher that re-fires on its own result — and
         * the number is chosen so that a person can never meet it: the box asks
         * at most once per 250 ms of typing (resources/js/stores/airports.js),
         * so four a second is the theoretical ceiling of continuous typing and
         * the debounce means the real figure is a handful.
         *
         * A limiter a person can trip is a feature that breaks while being
         * used, which is worse here than the thing it prevents.
         *
         * BY THE ACCOUNT, like everything else behind `auth` in this file.
         */
        RateLimiter::for('airport-search', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * The route lookup's throttle (`POST /api/routes/lookup`).
         *
         * THIS IS THE ONE ENDPOINT WHERE A TAP SPENDS THE FARE BUDGET DIRECTLY,
         * and the two limits below are that budget divided up rather than a
         * round number. A lookup that finds no fresh fares fetches the full
         * `orbit.poll.window_days` window, Travelpayouts bills one request per
         * calendar month it touches, and 181 days touches six or seven — so:
         *
         *     one miss                  ≈  7 provider requests
         *     6 a minute (the burst)    ≈ 42 in that minute
         *     20 an hour (the ceiling)  ≈ 140 in that hour
         *
         * against the ~200 requests an hour the token allows, of which the 06:10
         * poll and the 06:40 rule sweep already claim ≤176 in the one clock hour
         * they share (config/orbit.php, `rules`). A person looking routes up at
         * 06:15 is not a case worth designing for; every other hour of the day
         * has the whole allowance free, and 140 leaves room in it.
         *
         * BOTH LIMITS, NOT EITHER. The per-minute one alone would permit 360 an
         * hour — the entire allowance, twice over, from a stuck retry loop. The
         * hourly one alone would let all twenty land in four seconds and be
         * refused for the rest of the hour, which is the same outage with worse
         * manners. Six in a minute is a typo, a correction and a change of mind;
         * twenty in an hour is a long evening of browsing.
         *
         * ONLY MISSES REACH THE COUNTER IN PRACTICE. The detail screen asks for
         * a lookup when the route it is showing has no fresh fares and is not
         * watched (docs/API.md), so viewing a route Orbit priced this morning
         * costs nothing here — it never makes the request. A fetch that comes
         * back empty is remembered for `orbit.lookup.fresh_for_hours` too, so a
         * pair with no fares cannot be re-fetched view after view.
         *
         * BY THE ACCOUNT, like the two above: one account, several devices, and
         * a phone on mobile data whose ip changes mid-sentence.
         */
        RateLimiter::for('route-lookup', static function (Request $request): array {
            $key = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            /** @var list<Limit> $limits */
            $limits = [Limit::perMinute(6)->by($key), Limit::perHour(20)->by($key)];

            return $limits;
        });

        /*
         * ORBIT ISSUES NO API TOKENS, so a bearer token is never a credential
         * here — see bootstrap/app.php for why Sanctum is in cookie/session
         * mode.
         *
         * Saying so explicitly is not decoration. Sanctum's guard falls through
         * to token authentication whenever a request carries an Authorization
         * header, and that path reads `personal_access_tokens` — a table this
         * app never publishes a migration for. Left alone, any request with a
         * `Authorization: Bearer x` header would turn a 401 into a 500 from a
         * missing relation. This closes it at the guard instead of by creating
         * a table nothing writes to.
         */
        Sanctum::getAccessTokenFromRequestUsing(fn (): ?string => null);
    }
}
