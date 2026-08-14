<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Ports\PriceProvider;
use App\Application\Ports\PriceStatsProvider;
use App\Domain\Pricing\DealScorer;
use App\Domain\Pricing\ScoringPolicy;
use App\Infrastructure\Pricing\FakePriceProvider;
use App\Infrastructure\Pricing\FakeStatsProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }

    public function boot(): void
    {
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
