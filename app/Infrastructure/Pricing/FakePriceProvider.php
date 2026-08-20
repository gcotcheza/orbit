<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;
use Illuminate\Support\Facades\Date;
use App\Application\Ports\PriceProvider;

/**
 * Fares, until there are real ones.
 *
 * Not a test double: docs/PLAN.md ships Orbit before the Travelpayouts key exists, so this is production's adapter
 * until `ORBIT_PRICE_PROVIDER` swaps it (docs/BUSINESS-LOGIC.md §2).
 *
 * The prices come from FakeFareModel — see that file for the shape of the
 * simulation and for why it is deterministic.
 */
final readonly class FakePriceProvider implements PriceProvider
{
    public function __construct(private FakeFareModel $model = new FakeFareModel) {}

    /**
     * @return list<DatedFare>
     */
    public function cheapestPerDay(
        string $originIata,
        string $destinationIata,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        // Date::now(), not `new DateTimeImmutable`: it's the clock Date::setTestNow() moves, which is how FakeHistorySeeder
        // replays past mornings through the ordinary poller (docs/BUSINESS-LOGIC.md §2).
        $observedAt = Date::now()->toDateTimeImmutable();

        $routeCode = $originIata.'-'.$destinationIata;
        $fares = [];

        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            // `foundAt` = this instant: for a simulated fare that's honest (no cache, nothing found it first) — a claim the real
            // adapter usually can't make (docs/BUSINESS-LOGIC.md §2).

            // Not left null: null renders as no freshness line, so the fake would silently hide that feature from every sandbox,
            // e2e run and screenshot (docs/BUSINESS-LOGIC.md §2).
            $fares[] = new DatedFare($day, $this->model->priceCents($routeCode, $day, $observedAt), $observedAt);
        }

        return $fares;
    }
}
