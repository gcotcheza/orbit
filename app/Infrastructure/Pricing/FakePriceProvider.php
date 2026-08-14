<?php

declare(strict_types=1);

namespace App\Infrastructure\Pricing;

use App\Application\Ports\PriceProvider;
use App\Domain\Pricing\DatedFare;
use DateTimeImmutable;
use Illuminate\Support\Facades\Date;

/**
 * Fares, until there are real ones.
 *
 * NOT A TEST DOUBLE. docs/PLAN.md ships Orbit before the Travelpayouts key
 * exists, so this is the adapter production runs — which is why it is a
 * plausible airline and not `return 42`. Swapping it for the real one is
 * `ORBIT_PRICE_PROVIDER=travelpayouts` in .env and a line in
 * AppServiceProvider's match(); nothing that calls the port changes, because
 * nothing that calls the port names an adapter.
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
        /*
         * "As of now" through the Date facade rather than `new
         * DateTimeImmutable`, because that is the clock Laravel's
         * `Date::setTestNow()` moves — and moving it is how
         * Database\Seeders\FakeHistorySeeder replays sixty past mornings
         * through the ordinary poller instead of writing history rows by hand.
         * A real adapter reads a wall clock it cannot move, which is exactly
         * why the seeder refuses to run against one.
         */
        $observedAt = Date::now()->toDateTimeImmutable();

        $routeCode = $originIata.'-'.$destinationIata;
        $fares = [];

        for ($day = $from->setTime(0, 0); $day <= $to; $day = $day->modify('+1 day')) {
            $fares[] = new DatedFare($day, $this->model->priceCents($routeCode, $day, $observedAt));
        }

        return $fares;
    }
}
