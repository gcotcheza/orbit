<?php

declare(strict_types=1);

namespace App\Providers;

use Anthropic\Client as AnthropicClient;
use App\Application\Ports\DealNotifier;
use App\Application\Ports\PriceProvider;
use App\Application\Ports\PriceStatsProvider;
use App\Application\Ports\RuleTextParser;
use App\Domain\Alerts\AlertPolicy;
use App\Domain\Pricing\DealScorer;
use App\Domain\Pricing\ScoringPolicy;
use App\Domain\Rules\RuleMatcher;
use App\Domain\Rules\RuleVocabulary;
use App\Infrastructure\Nlp\AnthropicRuleTextParser;
use App\Infrastructure\Nlp\RegexRuleTextParser;
use App\Infrastructure\Notify\MailDealNotifier;
use App\Infrastructure\Notify\MarkAlertsDelivered;
use App\Infrastructure\Pricing\FakePriceProvider;
use App\Infrastructure\Pricing\FakeStatsProvider;
use App\Infrastructure\Pricing\TravelpayoutsPriceProvider;
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
         * THE TWO FARE PORTS, chosen by name in config/orbit.php.
         *
         * This is the whole of the hexagon's wiring: nothing in App\Domain or
         * App\Application knows an adapter exists, and swapping the fakes for
         * Travelpayouts and Amadeus when the keys arrive is a class each, a
         * line each in the match() below, and two variables in .env. No call
         * site changes because no call site names an adapter.
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
            default => throw new InvalidArgumentException(sprintf('Unknown price statistics provider [%s].', is_string($name) ? $name : gettype($name))),
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
