<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Pricing\PriceStats;
use PHPUnit\Framework\Attributes\Test;
use App\Infrastructure\Pricing\FakeFareModel;

/**
 * The pretend airline: asserts the properties the app relies on, not the
 * made-up prices themselves (docs/BUSINESS-LOGIC.md §14).
 */
final class FakeFareModelTest extends TestCase
{
    private const ROUTES = ['AMS-LIS', 'AMS-OPO', 'AMS-NAP', 'EIN-BCN', 'AMS-FAO', 'DUS-AGP'];

    private FakeFareModel $model;

    private DateTimeImmutable $observedAt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->model = new FakeFareModel;
        $this->observedAt = new DateTimeImmutable('2026-08-14 06:10:00');
    }

    private function price(string $route, string $departure): int
    {
        return $this->model->priceCents($route, new DateTimeImmutable($departure), $this->observedAt);
    }

    #[Test]
    public function the_same_question_always_gets_the_same_answer(): void
    {
        $first = $this->price('AMS-LIS', '2026-09-12');
        $second = (new FakeFareModel)->priceCents(
            'AMS-LIS',
            new DateTimeImmutable('2026-09-12'),
            $this->observedAt,
        );

        $this->assertSame($first, $second);
    }

    #[Test]
    public function different_routes_are_priced_differently(): void
    {
        $prices = [];

        foreach (['AMS-LIS', 'AMS-OPO', 'EIN-BCN', 'DUS-AGP', 'AMS-NAP', 'AMS-FAO'] as $route) {
            $prices[$route] = $this->price($route, '2026-09-12');
        }

        $this->assertCount(6, array_unique($prices), 'Six routes should not share a price band.');
    }

    #[Test]
    public function every_fare_lands_in_the_european_short_haul_band(): void
    {
        $day = new DateTimeImmutable('2026-01-01');

        for ($i = 0; $i < 365; $i++) {
            foreach (['AMS-LIS', 'DUS-AGP', 'EIN-BCN'] as $route) {
                $cents = $this->model->priceCents($route, $day, $this->observedAt);

                $this->assertGreaterThanOrEqual(2900, $cents);
                $this->assertLessThanOrEqual(18000, $cents);
                $this->assertSame(0, $cents % 100, 'Fake fares are whole euros.');
            }

            $day = $day->modify('+1 day');
        }
    }

    /**
     * Friday/Sunday cost more than Tuesday — asserted over a full year, since
     * a sale-window Friday can be cheaper than a plain Tuesday.
     */
    #[Test]
    public function the_weekend_costs_more(): void
    {
        $day = new DateTimeImmutable('2026-01-01');
        /** @var array<int, list<int>> $byWeekday */
        $byWeekday = array_fill_keys(range(1, 7), []);

        for ($i = 0; $i < 365; $i++) {
            $byWeekday[(int) $day->format('N')][] = $this->model->priceCents('AMS-LIS', $day, $this->observedAt);
            $day = $day->modify('+1 day');
        }

        $tuesday = $this->median($byWeekday[2]);

        $this->assertGreaterThan($tuesday, $this->median($byWeekday[5]), 'Fridays should cost more.');
        $this->assertGreaterThan($tuesday, $this->median($byWeekday[7]), 'Sundays should cost more.');
    }

    /**
     * Without deep sales, no route would ever reach the "insane" tier the
     * alerting exists to exercise.
     */
    #[Test]
    public function every_route_has_sale_windows_somewhere_in_the_year(): void
    {
        foreach (['AMS-LIS', 'AMS-OPO', 'EIN-BCN'] as $route) {
            $day = new DateTimeImmutable('2026-01-01');
            $prices = [];

            for ($i = 0; $i < 365; $i++) {
                $prices[] = $this->model->priceCents($route, $day, $this->observedAt);
                $day = $day->modify('+1 day');
            }

            $median = $this->median($prices);

            $this->assertLessThan(
                $median * 0.75,
                min($prices),
                "{$route} never goes properly on sale.",
            );
        }
    }

    /**
     * Same departure date, different observation mornings, must price
     * differently — the only reason price history has shape.
     */
    #[Test]
    public function the_price_moves_with_when_you_looked(): void
    {
        $departure = new DateTimeImmutable('2026-11-20');

        $today = $this->model->priceCents('AMS-LIS', $departure, $this->observedAt);
        $lastMonth = $this->model->priceCents('AMS-LIS', $departure, $this->observedAt->modify('-30 days'));

        $this->assertNotSame($today, $lastMonth);
    }

    /**
     * AMS-NAP is the steady route because no other test leans on its sales or drift;
     * gap = how far the cheapest fare in the next 90 days sits below the usual price.
     */
    #[Test]
    public function one_route_always_stays_dear_while_another_always_has_a_deal(): void
    {
        $this->assertSame('AMS-NAP', FakeFareModel::STEADY_ROUTE);

        $highestMinGap = -1.0;
        $highestSteadyGap = -1.0;
        $lowestMaxGap = 1.0;
        $end = new DateTimeImmutable('2030-12-31 06:10:00');

        for ($day = new DateTimeImmutable('2026-01-01 06:10:00'); $day <= $end; $day = $day->modify('+7 days')) {
            $gaps = [];

            foreach (self::ROUTES as $route) {
                $gaps[$route] = $this->gap($route, $day);
            }

            $highestMinGap = max($highestMinGap, min($gaps));
            $highestSteadyGap = max($highestSteadyGap, $gaps[FakeFareModel::STEADY_ROUTE]);
            $lowestMaxGap = min($lowestMaxGap, max($gaps));
        }

        $this->assertLessThanOrEqual(0.14, $highestMinGap, 'Some week has no route that stays dear.');
        $this->assertLessThanOrEqual(0.14, $highestSteadyGap, 'The steady route looked like a deal.');
        $this->assertGreaterThanOrEqual(0.21, $lowestMaxGap, 'Some week has no route with a deal.');
    }

    #[Test]
    public function a_year_of_prices_covers_a_year_from_several_booking_moments(): void
    {
        $samples = $this->model->yearOfPrices('AMS-LIS', new DateTimeImmutable('2026-08-14'));

        $this->assertCount(365 * 6, $samples);
        $this->assertGreaterThan(1, count(array_unique($samples)));
    }

    private function gap(string $route, DateTimeImmutable $day): float
    {
        $usual = PriceStats::fromSamples($this->model->yearOfPrices($route, $day))->usualCents();
        $cheapest = PHP_INT_MAX;

        for ($d = $day->setTime(0, 0), $to = $day->modify('+90 days'); $d <= $to; $d = $d->modify('+1 day')) {
            $cheapest = min($cheapest, $this->model->priceCents($route, $d, $day));
        }

        return ($usual - $cheapest) / $usual;
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): float
    {
        sort($values);

        return (float) $values[intdiv(count($values), 2)];
    }
}
