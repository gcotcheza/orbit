<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Pricing;

use Tests\TestCase;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Attributes\Test;
use App\Application\Pricing\TrackingDays;

/**
 * The one piece of arithmetic behind "tracking N days" — shared by a route and by a
 * round-trip band, so the two can never count differently (docs/BUSINESS-LOGIC.md §7, §15 R9).
 */
final class TrackingDaysTest extends TestCase
{
    private const ZONE = 'Europe/Amsterdam';

    #[Test]
    public function nothing_observed_is_nought_days_and_not_one(): void
    {
        $this->assertSame(0, TrackingDays::inclusive(null, self::ZONE, $this->midnight('2026-09-19')));
    }

    /** Inclusive: the morning of the first observation is day one. */
    #[Test]
    public function the_first_morning_itself_counts_as_a_day(): void
    {
        $this->assertSame(1, TrackingDays::inclusive('2026-09-19', self::ZONE, $this->midnight('2026-09-19')));
    }

    #[Test]
    public function six_days_ago_is_seven_days_of_tracking(): void
    {
        $this->assertSame(7, TrackingDays::inclusive('2026-09-13', self::ZONE, $this->midnight('2026-09-19')));
    }

    /**
     * The clocks go back inside this span, so one of its days is 25 hours long: parsing
     * either end outside the owner's zone returns a fraction, which truncates to 12.
     */
    #[Test]
    public function a_span_across_the_autumn_clock_change_is_still_whole_days(): void
    {
        $this->assertSame(13, TrackingDays::inclusive('2026-10-20', self::ZONE, $this->midnight('2026-11-01')));
    }

    private function midnight(string $date): CarbonInterface
    {
        return Date::parse($date, self::ZONE)->startOfDay();
    }
}
