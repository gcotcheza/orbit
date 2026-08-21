<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use DateTimeImmutable;
use App\Domain\Pricing\DatedFare;
use Illuminate\Support\Facades\Date;
use App\Application\Ports\PriceProvider;

/**
 * Fares, until there are real ones — not a test double but production's adapter until
 * `ORBIT_PRICE_PROVIDER` swaps it. The prices come from FakeFareModel.
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
        // Date::now(), not `new DateTimeImmutable`: it is the clock Date::setTestNow() moves,
        // which is how FakeHistorySeeder replays past mornings.
        $observedAt = Date::now()->toDateTimeImmutable();

        $routeCode = $originIata.'-'.$destinationIata;
        $fares = [];

        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            // `foundAt` = this instant: for a simulated fare that is honest, and a claim the real
            // adapter usually cannot make.

            // Not left null: null renders as no freshness line, which would hide that feature from
            // every sandbox, e2e run and screenshot.
            $fares[] = new DatedFare($day, $this->model->priceCents($routeCode, $day, $observedAt), $observedAt);
        }

        return $fares;
    }
}
