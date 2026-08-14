<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\PriceHistory;
use App\Domain\Pricing\PricePoint;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Our own observations, and the trend read out of them.
 */
final class PriceHistoryTest extends TestCase
{
    /**
     * @param  list<int>  $cents  oldest first, one per day, ending today
     */
    private function daily(array $cents, string $endingOn = '2026-08-14'): PriceHistory
    {
        $end = new DateTimeImmutable($endingOn);
        $points = [];

        foreach ($cents as $index => $value) {
            $points[] = new PricePoint($end->modify('-'.(count($cents) - 1 - $index).' days'), $value);
        }

        return new PriceHistory($points);
    }

    #[Test]
    public function an_empty_history_has_no_trend(): void
    {
        $history = PriceHistory::empty();

        $this->assertTrue($history->isEmpty());
        $this->assertNull($history->latest());
        $this->assertNull($history->dailyDrift());
    }

    #[Test]
    public function one_observation_is_not_a_trend(): void
    {
        $this->assertNull($this->daily([5000])->dailyDrift());
    }

    #[Test]
    public function a_flat_history_drifts_by_nothing(): void
    {
        $this->assertSame(0.0, $this->daily([5000, 5000, 5000, 5000])->dailyDrift());
    }

    #[Test]
    public function a_falling_history_drifts_negative(): void
    {
        $drift = $this->daily([10000, 9800, 9600, 9400, 9200])->dailyDrift();

        $this->assertNotNull($drift);
        $this->assertLessThan(0, $drift);
        // €200 a day off a €96 mean: a shade over 2% a day.
        $this->assertEqualsWithDelta(-0.0208, $drift, 0.001);
    }

    #[Test]
    public function a_rising_history_drifts_positive(): void
    {
        $drift = $this->daily([9200, 9400, 9600, 9800, 10000])->dailyDrift();

        $this->assertNotNull($drift);
        $this->assertGreaterThan(0, $drift);
    }

    /**
     * The reason it is a least-squares fit and not first-versus-last: a month
     * of steady falls with one day of noise on the end is still a fall.
     */
    #[Test]
    public function one_days_bounce_does_not_flip_a_month_of_falling(): void
    {
        $drift = $this->daily([12000, 11500, 11000, 10500, 10000, 9500, 9600])->dailyDrift();

        $this->assertNotNull($drift);
        $this->assertLessThan(0, $drift);
    }

    /**
     * lastDays() counts CALENDAR days back from the newest point, so a gap in
     * the series cannot quietly drag an older observation into the window.
     */
    #[Test]
    public function the_window_is_calendar_days_and_not_a_row_count(): void
    {
        $end = new DateTimeImmutable('2026-08-14');

        $history = new PriceHistory([
            new PricePoint($end->modify('-40 days'), 20000),
            new PricePoint($end->modify('-2 days'), 5000),
            new PricePoint($end->modify('-1 day'), 5100),
            new PricePoint($end, 5200),
        ]);

        $this->assertSame(4, $history->count());
        $this->assertSame([5000, 5100, 5200], $history->lastDays(7)->cents());
        $this->assertSame([20000, 5000, 5100, 5200], $history->lastDays(60)->cents());
        $this->assertSame([], $history->lastDays(0)->cents());
    }

    #[Test]
    public function observations_must_arrive_oldest_first(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceHistory([
            new PricePoint(new DateTimeImmutable('2026-08-14'), 5000),
            new PricePoint(new DateTimeImmutable('2026-08-13'), 5100),
        ]);
    }

    /**
     * Two prices recorded on the same date give a vertical line with no slope,
     * which is not a trend and must not be reported as an infinite one.
     */
    #[Test]
    public function observations_on_one_date_have_no_slope(): void
    {
        $day = new DateTimeImmutable('2026-08-14');

        $history = new PriceHistory([
            new PricePoint($day, 5000),
            new PricePoint($day, 4000),
        ]);

        $this->assertNull($history->dailyDrift());
    }
}
