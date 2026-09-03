<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Pricing\NightsBand;
use App\Domain\Pricing\ReturnTrip;
use PHPUnit\Framework\Attributes\Test;
use App\Domain\Pricing\ReturnBandPrice;

/**
 * What a round trip costs now and usually — the definition, as pure PHP
 * (docs/BUSINESS-LOGIC.md §15, R1-R6).
 */
final class ReturnBandPriceTest extends TestCase
{
    private const MIN_SAMPLES = 5;

    /** R1 */
    #[Test]
    public function only_the_stay_lengths_inside_the_band_are_counted(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(6, 8), [
            $this->trip(nights: 5, cents: 1000),
            $this->trip(nights: 6, cents: 9000),
            $this->trip(nights: 8, cents: 7000),
            $this->trip(nights: 9, cents: 2000),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertSame(2, $price->sampleCount, 'The 5- and 9-night fares belong to no band here.');
        $this->assertSame(7000, $price->currentCents, 'The cheapest fare OUTSIDE the band must not win.');
    }

    /** R1 — both ends of a band are inside it. */
    #[Test]
    public function a_band_is_inclusive_at_both_ends(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(2, 3), [
            $this->trip(nights: 2, cents: 8000),
            $this->trip(nights: 3, cents: 8100),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertSame(2, $price->sampleCount);
    }

    /** R2 */
    #[Test]
    public function the_current_price_is_the_cheapest_fare_in_the_band(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(6, 8), [
            $this->trip(nights: 6, cents: 48400),
            $this->trip(nights: 7, cents: 33400),
            $this->trip(nights: 8, cents: 41000),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertSame(33400, $price->currentCents);
    }

    /** R2 — a tie is broken by the fare we know was found most recently. */
    #[Test]
    public function two_fares_at_the_same_price_are_separated_by_their_find_time(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(6, 8), [
            $this->trip(nights: 6, cents: 33400, foundAt: '2026-08-28 06:00:00'),
            $this->trip(nights: 7, cents: 33400, foundAt: '2026-09-02 06:00:00'),
            $this->trip(nights: 8, cents: 33400, foundAt: null),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertSame(7, $price->nights);
        $this->assertSame('2026-09-02', $price->foundAt?->format('Y-m-d'));
    }

    /** R2 — an unknown find time is the oldest there is, and never wins a tie. */
    #[Test]
    public function an_unknown_find_time_loses_a_tie_to_any_known_one(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(6, 8), [
            $this->trip(nights: 6, cents: 33400, foundAt: null),
            $this->trip(nights: 7, cents: 33400, foundAt: '2026-08-20 06:00:00'),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertSame(7, $price->nights);
    }

    /** R2 — with nothing else to separate them, the shorter stay wins. */
    #[Test]
    public function a_tie_with_no_find_times_at_all_goes_to_the_shorter_stay(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(6, 8), [
            $this->trip(nights: 8, cents: 33400),
            $this->trip(nights: 6, cents: 33400),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertSame(6, $price->nights);
    }

    /** R3 */
    #[Test]
    public function the_current_price_carries_the_stay_length_and_age_of_the_fare_that_won(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(13, 15), [
            $this->trip(nights: 15, cents: 51000, foundAt: '2026-08-30 11:22:33'),
            $this->trip(nights: 13, cents: 62000, foundAt: '2026-09-03 04:40:00'),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertSame(15, $price->nights, 'A band is a range: the winning length is a fact of its own.');
        $this->assertSame('2026-08-30 11:22:33', $price->foundAt?->format('Y-m-d H:i:s'));
    }

    /** R3 — null is "not known", never "found this morning". */
    #[Test]
    public function a_fare_with_no_find_time_keeps_none(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(2, 3), [
            $this->trip(nights: 2, cents: 8000, foundAt: null),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertNull($price->foundAt);
    }

    /** R4 */
    #[Test]
    public function the_usual_price_is_the_five_number_summary_of_the_same_fares(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(6, 8), [
            $this->trip(nights: 6, cents: 10000),
            $this->trip(nights: 7, cents: 20000),
            $this->trip(nights: 8, cents: 30000),
            $this->trip(nights: 6, cents: 40000),
            $this->trip(nights: 7, cents: 50000),
            $this->trip(nights: 8, cents: 60000),
            $this->trip(nights: 6, cents: 70000),
            $this->trip(nights: 7, cents: 80000),
            $this->trip(nights: 9, cents: 90000),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertNotNull($price->usual);
        $this->assertSame(8, $price->sampleCount, 'The 9-night fare is outside the band.');
        $this->assertSame(
            [10000, 20000, 40000, 60000, 80000],
            [
                $price->usual->minCents,
                $price->usual->p25Cents,
                $price->usual->medianCents,
                $price->usual->p75Cents,
                $price->usual->maxCents,
            ],
        );
        $this->assertSame($price->usual->minCents, $price->currentCents, 'Today\'s price is one of the fares summarised.');
    }

    /** R5 */
    #[Test]
    public function four_fares_are_a_current_price_and_no_usual_one(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(6, 8), [
            $this->trip(nights: 6, cents: 40000),
            $this->trip(nights: 7, cents: 30000),
            $this->trip(nights: 8, cents: 50000),
            $this->trip(nights: 6, cents: 60000),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertSame(30000, $price->currentCents);
        $this->assertNull($price->usual, 'Four fares cannot fill five knots with prices that were really quoted.');
        $this->assertSame(4, $price->sampleCount);
    }

    /** R5 — the floor is inclusive: five passes. */
    #[Test]
    public function the_fifth_fare_is_what_makes_a_usual_price(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(6, 8), [
            $this->trip(nights: 6, cents: 40000),
            $this->trip(nights: 7, cents: 30000),
            $this->trip(nights: 8, cents: 50000),
            $this->trip(nights: 6, cents: 60000),
            $this->trip(nights: 7, cents: 70000),
        ], self::MIN_SAMPLES);

        $this->assertNotNull($price);
        $this->assertNotNull($price->usual);
        $this->assertSame(50000, $price->usual->usualCents());
    }

    /** R6 */
    #[Test]
    public function a_band_with_no_fares_has_no_answer_at_all(): void
    {
        $price = ReturnBandPrice::from(new NightsBand(21, 28), [
            $this->trip(nights: 7, cents: 30000),
            $this->trip(nights: 14, cents: 40000),
        ], self::MIN_SAMPLES);

        $this->assertNull($price, 'A fortnight is not three weeks, and neither is a guess.');
    }

    /** R6 */
    #[Test]
    public function a_route_with_no_fares_at_all_has_no_answer(): void
    {
        $this->assertNull(ReturnBandPrice::from(new NightsBand(6, 8), [], self::MIN_SAMPLES));
    }

    private function trip(int $nights, int $cents, ?string $foundAt = null): ReturnTrip
    {
        return new ReturnTrip(
            new DateTimeImmutable('2026-11-03'),
            $nights,
            $cents,
            $foundAt === null ? null : new DateTimeImmutable($foundAt),
        );
    }
}
