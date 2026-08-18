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
            /*
             * FOUND NOW, BECAUSE THIS ONE REALLY DID JUST INVENT THEM.
             *
             * `foundAt` is how old a price is (App\Domain\Pricing\DatedFare),
             * and the honest answer for a simulated fare is "this instant" — it
             * has no cache behind it and no other search found it first. That is
             * a claim the real adapter is usually NOT entitled to make, which is
             * the whole reason the field exists.
             *
             * IT IS NOT LEFT NULL, EVEN THOUGH NULL WOULD BE LESS CODE. Null
             * means "we do not know how old this is" and renders as no line at
             * all, so a sandbox, the e2e run and every screenshot taken against
             * the fake would silently exercise the one path where the freshness
             * feature is invisible. Stamping the clock keeps the fake a
             * plausible provider rather than a hole in the coverage — and it
             * moves with `Date::setTestNow()` exactly as `$observedAt` does,
             * which is what keeps FakeHistorySeeder's replayed mornings
             * internally consistent.
             */
            $fares[] = new DatedFare($day, $this->model->priceCents($routeCode, $day, $observedAt), $observedAt);
        }

        return $fares;
    }
}
