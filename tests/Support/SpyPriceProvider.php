<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;
use App\Application\Ports\PriceProvider;

/**
 * A fare provider that answers whatever a test tells it to, and remembers
 * being asked — the counter is the point (docs/BUSINESS-LOGIC.md §36).
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
