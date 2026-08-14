<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use App\Infrastructure\Pricing\FakeFareModel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pretend airline.
 *
 * WHAT IS WORTH ASSERTING ABOUT MADE-UP PRICES: not the prices themselves, but
 * the properties the rest of the app relies on. Determinism is the big one —
 * a feature test that asserts €44 and a deploy that shows €51 for the same
 * route would both be "working" if this were random, and neither could be
 * trusted.
 */
final class FakeFareModelTest extends TestCase
{
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
     * Friday and Sunday cost more than the Tuesday in between. This is what
     * gives the calendar heatmap its stripes.
     *
     * ASSERTED OVER A WHOLE YEAR, not on one pair of dates: a route's sale
     * windows are five consecutive days at 38% off, and a Friday inside one is
     * genuinely cheaper than the Tuesday after it. The premium is a property of
     * the distribution, and that is what is checked.
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
     * Without the occasional deep sale the deal score would never reach the
     * "insane" tier on any route, and the alerting the app exists for would
     * never be exercised.
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
     * The same departure date, looked at on two different mornings, is a
     * different price — which is the only reason our accruing history has a
     * shape at all.
     */
    #[Test]
    public function the_price_moves_with_when_you_looked(): void
    {
        $departure = new DateTimeImmutable('2026-11-20');

        $today = $this->model->priceCents('AMS-LIS', $departure, $this->observedAt);
        $lastMonth = $this->model->priceCents('AMS-LIS', $departure, $this->observedAt->modify('-30 days'));

        $this->assertNotSame($today, $lastMonth);
    }

    #[Test]
    public function a_year_of_prices_covers_a_year_from_several_booking_moments(): void
    {
        $samples = $this->model->yearOfPrices('AMS-LIS', new DateTimeImmutable('2026-08-14'));

        $this->assertCount(365 * 6, $samples);
        $this->assertGreaterThan(1, count(array_unique($samples)));
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
