<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Ports\PriceProvider;
use App\Domain\Pricing\DatedFare;
use DateTimeImmutable;

/**
 * A fare provider that answers whatever a test tells it to, and remembers being
 * asked.
 *
 * WHY NOT App\Infrastructure\Pricing\FakePriceProvider, which is what
 * `.env.testing` binds and what every other poller test runs on. That one is a
 * deterministic MODEL of a fare market — it answers for every day of the window
 * with plausible prices — and it is exactly right for tests about what the app
 * does with fares. It cannot answer the question tests/Feature/RouteLookupTest
 * is actually asking, which is HOW MANY TIMES the provider was called: a lookup
 * that quietly re-fetched a route it had priced an hour ago would look identical
 * through the fake, and would be six or seven metered requests a day per curious
 * tap in production.
 *
 * SO THE COUNTER IS THE POINT, and the fares are whatever the test needs to make
 * the assertion after it readable.
 */
final class SpyPriceProvider implements PriceProvider
{
    /** How many times the port has been called. */
    public int $calls = 0;

    /** @var list<DatedFare> what to answer with — empty is a real answer */
    public array $answer = [];

    /** @var array{0: string, 1: string} the window of the last call, 'Y-m-d' */
    public array $window = ['', ''];

    /**
     * @return list<DatedFare>
     */
    public function cheapestPerDay(
        string $originIata,
        string $destinationIata,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $this->calls++;
        $this->window = [$from->format('Y-m-d'), $to->format('Y-m-d')];

        return $this->answer;
    }
}
