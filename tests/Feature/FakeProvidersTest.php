<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use App\Domain\Pricing\DatedFare;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Ports\PriceProvider;
use App\Application\Ports\PriceStatsProvider;

/**
 * The two adapters, and the wiring that chooses them.
 *
 * The binding tests matter more than they look: a typo in
 * ORBIT_PRICE_PROVIDER that silently fell back to the fake would put invented
 * prices into a real alert, which is the worst thing this app could do.
 */
final class FakeProvidersTest extends TestCase
{
    #[Test]
    public function the_configured_adapters_are_what_the_container_hands_out(): void
    {
        $this->assertInstanceOf(PriceProvider::class, $this->app->make(PriceProvider::class));
        $this->assertInstanceOf(PriceStatsProvider::class, $this->app->make(PriceStatsProvider::class));
    }

    #[Test]
    public function an_unknown_provider_name_refuses_to_resolve(): void
    {
        config(['orbit.providers.price' => 'travelpayots']);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(PriceProvider::class);
    }

    #[Test]
    public function an_unknown_stats_provider_name_refuses_to_resolve(): void
    {
        config(['orbit.providers.stats' => 'amadeuss']);

        $this->expectException(InvalidArgumentException::class);

        $this->app->make(PriceStatsProvider::class);
    }

    #[Test]
    public function the_price_provider_answers_one_fare_per_day_in_order(): void
    {
        $from = new DateTimeImmutable('2026-09-01');
        $to = new DateTimeImmutable('2026-09-30');

        $fares = $this->app->make(PriceProvider::class)->cheapestPerDay('AMS', 'LIS', $from, $to);

        $this->assertCount(30, $fares);
        $this->assertSame('2026-09-01', $fares[0]->departureDate->format('Y-m-d'));
        $this->assertSame('2026-09-30', $fares[29]->departureDate->format('Y-m-d'));

        $dates = array_map(static fn (DatedFare $fare): string => $fare->departureDate->format('Y-m-d'), $fares);
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    /**
     * The provider reads the application clock, which is what lets
     * Database\Seeders\FakeHistorySeeder replay past mornings through the real
     * poller. If it read a wall clock instead, the backfill would write sixty
     * identical rows.
     */
    #[Test]
    public function the_price_provider_follows_the_application_clock(): void
    {
        $provider = $this->app->make(PriceProvider::class);
        $from = new DateTimeImmutable('2026-11-01');
        $to = new DateTimeImmutable('2026-11-07');

        Date::setTestNow('2026-08-14 06:10:00');
        $today = $provider->cheapestPerDay('AMS', 'LIS', $from, $to);

        Date::setTestNow('2026-07-14 06:10:00');
        $lastMonth = $provider->cheapestPerDay('AMS', 'LIS', $from, $to);

        Date::setTestNow();

        $this->assertNotSame(
            array_map(static fn (DatedFare $fare): int => $fare->cents, $today),
            array_map(static fn (DatedFare $fare): int => $fare->cents, $lastMonth),
        );
    }

    #[Test]
    public function the_stats_provider_answers_a_sorted_five_number_summary(): void
    {
        /*
         * Held as the PORT, not as the adapter: the assertions below are the
         * contract every future provider has to satisfy, including the null
         * that a real one is allowed to answer.
         */
        /** @var PriceStatsProvider $provider */
        $provider = $this->app->make(PriceStatsProvider::class);
        $stats = $provider->statsFor('AMS', 'LIS');

        $this->assertNotNull($stats);

        $knots = [$stats->minCents, $stats->p25Cents, $stats->medianCents, $stats->p75Cents, $stats->maxCents];
        $sorted = $knots;
        sort($sorted);

        $this->assertSame($sorted, $knots);
        $this->assertGreaterThanOrEqual(2900, $stats->minCents);
        $this->assertLessThanOrEqual(18000, $stats->maxCents);
        $this->assertSame($stats->medianCents, $stats->usualCents());
    }

    /**
     * The subtlest property in the whole fake: the usual price must NOT move
     * with the route-wide swing that today's fare carries, or the two cancel
     * and every route is scored as permanently average. See FakeFareModel.
     */
    #[Test]
    public function todays_cheapest_fare_can_sit_well_below_the_usual_price(): void
    {
        /** @var PriceProvider $prices */
        $prices = $this->app->make(PriceProvider::class);
        /** @var PriceStatsProvider $stats */
        $stats = $this->app->make(PriceStatsProvider::class);

        $from = Date::now()->toDateTimeImmutable();
        $to = $from->modify('+90 days');

        $gaps = [];

        foreach (['AMS-LIS', 'AMS-OPO', 'AMS-NAP', 'EIN-BCN', 'AMS-FAO', 'DUS-AGP'] as $code) {
            [$origin, $destination] = explode('-', $code);

            $cheapest = array_reduce(
                $prices->cheapestPerDay($origin, $destination, $from, $to),
                static fn (?int $carry, DatedFare $fare): int => $carry === null ? $fare->cents : min($carry, $fare->cents),
            );
            $this->assertNotNull($cheapest);

            $usual = $stats->statsFor($origin, $destination)?->usualCents();
            $this->assertNotNull($usual);

            $gaps[$code] = ($usual - $cheapest) / $usual;
        }

        $this->assertGreaterThan(0.2, max($gaps), 'No route is ever a real bargain.');
        $this->assertLessThan(0.15, min($gaps), 'Every route is a bargain, so none of them is.');
    }
}
