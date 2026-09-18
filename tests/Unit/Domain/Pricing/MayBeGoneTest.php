<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Pricing\MayBeGone;
use App\Domain\Pricing\PriceStats;
use PHPUnit\Framework\Attributes\Test;

/**
 * The demotion rule, shared by the headline fare and by every return band
 * (docs/BUSINESS-LOGIC.md §17, docs/API.md `cheapest.mayBeGone`).
 */
final class MayBeGoneTest extends TestCase
{
    private const STALE_AFTER_HOURS = 48;

    private const UNDER_USUAL_PERCENT = 20;

    private const NOW = '2026-09-03 09:00:00';

    #[Test]
    public function an_old_fare_well_under_usual_may_be_gone(): void
    {
        $this->assertTrue($this->decide(3600, foundAt: '2026-08-29 20:11:25'));
    }

    #[Test]
    public function a_cheap_fare_found_this_morning_is_left_alone(): void
    {
        $this->assertFalse($this->decide(3600, foundAt: '2026-09-03 06:00:00'));
    }

    /** Both halves are required: age alone is the ordinary state of a quiet route. */
    #[Test]
    public function an_old_fare_near_its_usual_price_is_left_alone(): void
    {
        $this->assertFalse($this->decide(5500, foundAt: '2026-08-29 20:11:25'));
    }

    #[Test]
    public function not_knowing_when_a_fare_was_found_is_never_a_demotion(): void
    {
        $this->assertFalse($this->decide(3600, foundAt: null));
    }

    #[Test]
    public function a_band_with_no_usual_price_has_nothing_to_be_under(): void
    {
        $this->assertFalse(MayBeGone::decide(
            3600,
            new DateTimeImmutable('2026-08-29 20:11:25'),
            null,
            new DateTimeImmutable(self::NOW),
            self::STALE_AFTER_HOURS,
            self::UNDER_USUAL_PERCENT,
        ));
    }

    /** Exactly at the age threshold is not past it; exactly at the gap is. */
    #[Test]
    public function the_two_thresholds_are_read_the_way_the_config_words_them(): void
    {
        $this->assertFalse($this->decide(3600, foundAt: '2026-09-01 09:00:00'));
        $this->assertTrue($this->decide(3600, foundAt: '2026-09-01 08:59:00'));
        $this->assertTrue($this->decide(4000, foundAt: '2026-08-29 20:11:25'));
        $this->assertFalse($this->decide(4100, foundAt: '2026-08-29 20:11:25'));
    }

    private function decide(int $cents, ?string $foundAt): bool
    {
        return MayBeGone::decide(
            $cents,
            $foundAt === null ? null : new DateTimeImmutable($foundAt),
            new PriceStats(3000, 4000, 5000, 6000, 7000),
            new DateTimeImmutable(self::NOW),
            self::STALE_AFTER_HOURS,
            self::UNDER_USUAL_PERCENT,
        );
    }
}
