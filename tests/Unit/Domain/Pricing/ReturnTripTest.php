<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two round-trip values, which are pure PHP and are tested as such.
 *
 * `Tests\TestCase` boots the framework and is what a test needs when it touches
 * a database, a container binding or the HTTP client. Neither of these types
 * touches any of that, so this extends PHPUnit's own case — the same choice
 * tests/Unit/Domain/Pricing/PriceStatsTest makes.
 */
final class ReturnTripTest extends TestCase
{
    // -------------------------------------------------------------- the return date

    #[Test]
    public function the_return_date_is_derived_from_the_stay_length(): void
    {
        $trip = new ReturnTrip(new DateTimeImmutable('2026-11-03'), 7, 13400);

        $this->assertSame('2026-11-10', $trip->returnDate()->format('Y-m-d'));
    }

    #[Test]
    public function a_same_day_return_is_zero_nights_and_is_perfectly_legal(): void
    {
        /*
         * NOT A DEGENERATE CASE. Three of the 198 entries recorded from the live
         * API on 2026-08-16 had `return_date == depart_date`, so a floor of one
         * night would be the app inventing a rule the airline does not have.
         */
        $trip = new ReturnTrip(new DateTimeImmutable('2026-11-03'), 0, 9900);

        $this->assertSame('2026-11-03', $trip->returnDate()->format('Y-m-d'));
    }

    #[Test]
    public function the_return_date_crosses_a_month_a_year_and_a_dst_boundary_without_drifting(): void
    {
        /*
         * THE CLOCKS GO BACK ON 2026-10-25 IN EUROPE/AMSTERDAM. A derivation
         * done in seconds rather than in days would land this one at 23:00 on
         * the 30th and print the wrong calendar day.
         */
        $autumn = new ReturnTrip(new DateTimeImmutable('2026-10-24 00:00:00', new DateTimeZone('Europe/Amsterdam')), 7, 12000);
        $this->assertSame('2026-10-31', $autumn->returnDate()->format('Y-m-d'));

        $newYear = new ReturnTrip(new DateTimeImmutable('2026-12-28'), 14, 30000);
        $this->assertSame('2027-01-11', $newYear->returnDate()->format('Y-m-d'));
    }

    #[Test]
    public function a_return_leg_before_its_outbound_is_refused(): void
    {
        /*
         * A CORRUPT ROW RATHER THAN AN UNUSUAL TRIP. The column is unsigned, so
         * this would fail at the database with a message about a constraint;
         * failing here says what is actually wrong.
         */
        $this->expectException(InvalidArgumentException::class);

        new ReturnTrip(new DateTimeImmutable('2026-11-03'), -1, 13400);
    }

    // -------------------------------------------------------------------- the band

    #[Test]
    #[DataProvider('bandMembership')]
    public function a_band_is_inclusive_at_both_ends(int $nights, bool $inside): void
    {
        /*
         * BOTH ENDS, which is how "6 to 8 nights" reads and how
         * RuleCriteria::$tripLengthNights has been documented since the rules
         * engine shipped. Exclusive at the top would quietly stop every [2, 3]
         * weekend rule matching a Sunday return.
         */
        $this->assertSame($inside, (new NightsBand(6, 8))->contains($nights));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function bandMembership(): iterable
    {
        yield 'below' => [5, false];
        yield 'the lower end itself' => [6, true];
        yield 'inside' => [7, true];
        yield 'the upper end itself' => [8, true];
        yield 'above' => [9, false];
    }

    #[Test]
    public function a_single_night_band_is_allowed(): void
    {
        $band = new NightsBand(1, 1);

        $this->assertTrue($band->contains(1));
        $this->assertFalse($band->contains(2));
    }

    #[Test]
    public function a_band_may_start_at_zero_nights(): void
    {
        $this->assertTrue((new NightsBand(0, 3))->contains(0));
    }

    #[Test]
    public function a_backwards_band_is_refused_when_it_is_written_rather_than_when_it_matches_nothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('[8, 6]');

        NightsBand::of([8, 6]);
    }

    #[Test]
    public function a_negative_band_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NightsBand(-2, 3);
    }

    #[Test]
    public function it_reads_the_pair_shape_config_and_the_rules_engine_both_use(): void
    {
        $band = NightsBand::of([13, 15]);

        $this->assertSame(13, $band->min);
        $this->assertSame(15, $band->max);
    }
}
