<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use InvalidArgumentException;
use App\Domain\Rules\RuleMatcher;
use App\Domain\Alerts\AlertPolicy;
use App\Domain\Pricing\DealScorer;
use App\Domain\Rules\RuleVocabulary;
use App\Domain\Pricing\ScoringPolicy;
use Illuminate\Support\Facades\Event;
use GuzzleHttp\Client as GuzzleClient;
use App\Application\Ports\DealNotifier;
use Illuminate\Support\ServiceProvider;
use Anthropic\Client as AnthropicClient;
use App\Application\Ports\PriceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use App\Application\Ports\RuleTextParser;
use App\Domain\Discovery\DiscoveryPolicy;
use Illuminate\Support\Facades\RateLimiter;
use App\Domain\Discovery\RelativeLanePolicy;
use App\Application\Ports\PriceStatsProvider;
use App\Application\Ports\ReturnTripProvider;
use App\Application\Ports\OriginSweepProvider;
use App\Infrastructure\Nlp\RegexRuleTextParser;
use App\Infrastructure\Notify\MailDealNotifier;
use App\Infrastructure\Pricing\FakePriceProvider;
use App\Infrastructure\Pricing\FakeStatsProvider;
use App\Infrastructure\Pricing\SelfStatsProvider;
use App\Infrastructure\Verify\GoogleFlightsCheck;
use App\Infrastructure\Notify\MarkAlertsDelivered;
use App\Infrastructure\Pricing\FakeReturnProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use App\Infrastructure\Discovery\FakeSweepProvider;
use App\Infrastructure\Nlp\AnthropicRuleTextParser;
use Illuminate\Notifications\Events\NotificationSent;
use App\Infrastructure\Pricing\TravelpayoutsPriceProvider;
use App\Infrastructure\Pricing\TravelpayoutsReturnProvider;
use App\Infrastructure\Discovery\TravelpayoutsSweepProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Fare ports are chosen by name in config/orbit.php; an unknown name throws at resolution rather than silently falling
         * back to a fake (docs/BUSINESS-LOGIC.md §36).
         */
        $this->app->bind(PriceProvider::class, fn (): PriceProvider => match ($name = config('orbit.providers.price')) {
            'fake'          => new FakePriceProvider,
            'travelpayouts' => $this->travelpayoutsPrices(),
            default         => throw new InvalidArgumentException(sprintf('Unknown price provider [%s].', is_string($name) ? $name : gettype($name))),
        });

        $this->app->bind(PriceStatsProvider::class, fn (): PriceStatsProvider => match ($name = config('orbit.providers.stats')) {
            'fake'  => new FakeStatsProvider,
            'self'  => $this->selfStats(),
            default => throw new InvalidArgumentException(sprintf('Unknown price statistics provider [%s].', is_string($name) ? $name : gettype($name))),
        });

        /*
         * Own switch on purpose, though it hits the same vendor as the price port — lets real one-way fares run while returns
         * still use the fake (docs/BUSINESS-LOGIC.md §36).
         */
        $this->app->bind(ReturnTripProvider::class, fn (): ReturnTripProvider => match ($name = config('orbit.providers.returns')) {
            'fake'          => new FakeReturnProvider,
            'travelpayouts' => $this->travelpayoutsReturns(),
            default         => throw new InvalidArgumentException(sprintf('Unknown return-trip provider [%s].', is_string($name) ? $name : gettype($name))),
        });

        /*
         * Own switch though it reads the same endpoint as the return-trip adapter — the two must be able to fail and be turned
         * off independently (docs/BUSINESS-LOGIC.md §36).
         */
        $this->app->bind(OriginSweepProvider::class, fn (): OriginSweepProvider => match ($name = config('orbit.providers.sweep')) {
            'fake'          => new FakeSweepProvider,
            'travelpayouts' => $this->travelpayoutsSweep(),
            default         => throw new InvalidArgumentException(sprintf('Unknown origin sweep provider [%s].', is_string($name) ? $name : gettype($name))),
        });

        /*
         * Bound directly rather than by config name — SerpAPI is the only such adapter. NOT a singleton (holds no state across
         * calls) (docs/BUSINESS-LOGIC.md §36).
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
                 * Empty string reads as unset (same convention as seed.password), not as a literal key to authenticate a metered API
                 * with (docs/BUSINESS-LOGIC.md §36).
                 */
                key: is_string($key) && trim($key) !== '' ? $key : null,
                reserve: (int) $serpapi['reserve'],
                maxPerRun: (int) $serpapi['max_per_run'],
                connectTimeout: (float) $serpapi['connect_timeout'],
                timeout: (float) $serpapi['timeout'],
            );
        });

        /*
         * Config read once here into a pure value (Domain calls no config()). `max_eur_per_km` × 100 = cents/km, not euros ×
         * 100 — check twice (docs/BUSINESS-LOGIC.md §36).
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
         * Own value, not folded into DiscoveryPolicy — two products, not one lane. `min_discount` is a FRACTION (0.40 = 40%),
         * not a euro figure (docs/BUSINESS-LOGIC.md §36).
         */
        $this->app->singleton(RelativeLanePolicy::class, function (): RelativeLanePolicy {
            /** @var array<string, mixed> $relative */
            $relative = config('orbit.discovery.lanes.relative');

            /** @var array<string, mixed> $discovery */
            $discovery = config('orbit.discovery');

            return new RelativeLanePolicy(
                maxPriceCents: (int) round(((float) $relative['max_price_eur']) * 100),
                minDiscount: (float) $relative['min_discount'],
                /*
                 * Same €15 as the absolute lane's, read from the same key — one decision today, so one setting (docs/BUSINESS-LOGIC.md
                 * §36).
                 */
                minSavingsCents: (int) round(((float) $discovery['min_absolute_savings_eur']) * 100),
                minBaselineDays: (int) $relative['min_baseline_days'],
                maxBaselineAgeDays: (int) $relative['max_baseline_age_days'],
                shortlist: (int) $relative['shortlist'],
            );
        });

        /*
         * Read once here, not in Domain — DealScorer calls no config().
         * Why: docs/BUSINESS-LOGIC.md §36.
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
                 * Same number AlertPolicy uses below, on purpose — "too young to alert" and "too young to score" are one decision
                 * (docs/BUSINESS-LOGIC.md §36).
                 */
                minTrackingDays: (int) config('orbit.alerts.min_tracking_days'),
            ));
        });

        /*
         * Read once, handed in, same boundary as DealScorer above. Singleton because three consumers share it, not as a perf
         * optimisation (docs/BUSINESS-LOGIC.md §36).
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
         * Read once here, not in Domain — AlertPolicy calls no config(), same boundary as DealScorer/ScoringPolicy above
         * (docs/BUSINESS-LOGIC.md §36).
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
         * Bound directly rather than by config name — mail is the only channel today; web push arrives later as an ADDITION
         * (docs/PLAN.md) (docs/BUSINESS-LOGIC.md §36).
         */
        $this->app->bind(DealNotifier::class, MailDealNotifier::class);

        $this->app->singleton(RuleMatcher::class, function (): RuleMatcher {
            /** @var list<string> $warmVibes */
            $warmVibes = config('orbit.rules.warm_vibes');

            return new RuleMatcher((int) config('orbit.rules.warm_at'), $warmVibes);
        });

        /*
         * Chosen by name in config/orbit.php; anthropic COMPOSES regex and falls back to it on any failure. Unknown name
         * throws at resolution (docs/BUSINESS-LOGIC.md §36).
         */
        $this->app->bind(RuleTextParser::class, function (): RuleTextParser {
            $regex = new RegexRuleTextParser($this->app->make(RuleVocabulary::class));

            return match ($name = config('orbit.nlp.parser')) {
                'regex'     => $regex,
                'anthropic' => $this->anthropicParser($regex),
                default     => throw new InvalidArgumentException(sprintf('Unknown rule parser [%s].', is_string($name) ? $name : gettype($name))),
            };
        });
    }

    /**
     * The real fare adapter. Handed scalars rather than reading config() itself, so config:cache and test overrides behave
     * as expected. Missing token throws from the constructor, here, at resolution (docs/BUSINESS-LOGIC.md §36).
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
     * The real round-trip adapter. Shares `orbit.travelpayouts` connection settings; `max_nights`/`limit` are this endpoint's own, in `orbit.returns`.
     * Missing token throws from the constructor, at resolution (docs/BUSINESS-LOGIC.md §36).
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
     * The real origin-sweep adapter. Shares `orbit.travelpayouts` connection settings; `limit` deliberately reads
     * `orbit.returns` too (same endpoint, not a mistake). Missing token throws at resolution (docs/BUSINESS-LOGIC.md §36).
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
     * The statistics Orbit computes for itself. Scalars out of config, like every adapter here — lets a test set the
     * immature end of the blend directly rather than seeding a year of history (docs/BUSINESS-LOGIC.md §36).
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
     * The Claude-backed parser. Transporter is supplied, not discovered: the SDK's own `timeout` is advisory and never read, so only the PSR-18 client's
     * timeout stops a hung request. `http_errors => false` so the SDK's own typed exception wins over a raw Guzzle throw (docs/BUSINESS-LOGIC.md §36).
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
                        'timeout'         => (float) $nlp['timeout'],
                        'http_errors'     => false,
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
         * NotificationSent fires once a channel returns — the only honest moment for `delivered_at` (see MarkAlertsDelivered).
         * Registered explicitly: no app/Listeners dir exists for discovery (docs/BUSINESS-LOGIC.md §36).
         */
        Event::listen(NotificationSent::class, MarkAlertsDelivered::class);

        /*
         * Keyed on email AND ip (either alone leaves the other attack free). 5/min: this app has one account, so this route is
         * the whole brute-force surface. Email lower-cased — case is not a bucket (docs/BUSINESS-LOGIC.md §36).
         */
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        /*
         * Same 5/min as login, on purpose: `current_password` makes this the cheapest way to brute-force an unattended
         * session. Keyed on account not ip — caller is always authenticated here (docs/BUSINESS-LOGIC.md §36).
         */
        RateLimiter::for('password-change', fn (Request $request): Limit => Limit::perMinute(5)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * 20/min, keyed on account. Exists before it is needed: the day an Anthropic key lands in .env this becomes a metered
         * call per keystroke (docs/BUSINESS-LOGIC.md §36).
         */
        RateLimiter::for('rules-parse', fn (Request $request): Limit => Limit::perMinute(20)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * 60/min, deliberately generous — guards a CLIENT BUG (debounce failing), not a cost; a limiter a person can trip is
         * worse than what it prevents. No third party or real cost behind this one (docs/BUSINESS-LOGIC.md §36).
         */
        RateLimiter::for('airport-search', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        /*
         * The one endpoint where a tap spends the fare budget directly. BOTH limits matter: 6/min stops a burst, 20/hour stops a stuck loop from draining the
         * ~200/hour token allowance the daily poll+sweep already uses most of. Keyed on account (docs/BUSINESS-LOGIC.md §36).
         */
        RateLimiter::for('route-lookup', static function (Request $request): array {
            $key = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            /** @var list<Limit> $limits */
            $limits = [Limit::perMinute(6)->by($key), Limit::perHour(20)->by($key)];

            return $limits;
        });

        /*
         * ⚠ NOT what rations the SerpAPI month — the reserve and the cooldown
         * are (docs/BUSINESS-LOGIC.md §17). This catches a retry loop.
         */
        RateLimiter::for('live-check', static function (Request $request): array {
            $key = (string) ($request->user()?->getAuthIdentifier() ?? $request->ip());

            /** @var list<Limit> $limits */
            $limits = [Limit::perMinute(3)->by($key), Limit::perHour(10)->by($key)];

            return $limits;
        });

        /*
         * DO NOT remove: closes bearer-token auth at the guard. Sanctum falls through to `personal_access_tokens`, a table
         * this app never migrates — left open, a Bearer header turns a 401 into a 500 (docs/BUSINESS-LOGIC.md §36).
         */
        Sanctum::getAccessTokenFromRequestUsing(fn (): ?string => null);
    }
}
