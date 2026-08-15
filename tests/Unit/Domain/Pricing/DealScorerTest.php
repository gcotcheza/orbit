<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\DealScorer;
use App\Domain\Pricing\PriceHistory;
use App\Domain\Pricing\PricePoint;
use App\Domain\Pricing\PriceStats;
use App\Domain\Pricing\ScoringPolicy;
use App\Domain\Pricing\Verdict;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one piece of Orbit that is genuinely ours.
 *
 * The cases below are the ones that decide whether an alert fires: a fare at
 * the bottom of its range, one at its usual price, one above it, and every
 * combination of missing input the app will really see on a route it started
 * watching this morning.
 */
final class DealScorerTest extends TestCase
{
    /**
     * Mornings of history behind every score below, unless the test is about
     * the maturity floor itself.
     *
     * PASSED EXPLICITLY EVERYWHERE. The scorer declines to judge a route under
     * `ScoringPolicy::$minTrackingDays` days old, and an argument with a
     * default would let a test quietly assert the floor's behaviour while
     * appearing to assert the arithmetic — which is how the two got confused in
     * production in the first place.
     */
    private const MATURE = 30;

    /** €40 / €60 / €80 / €110 / €160. */
    private function stats(): PriceStats
    {
        return new PriceStats(4000, 6000, 8000, 11000, 16000);
    }

    /**
     * @param  list<int>  $cents  oldest first, one per day
     */
    private function history(array $cents): PriceHistory
    {
        $end = new DateTimeImmutable('2026-08-14');
        $points = [];

        foreach ($cents as $index => $value) {
            $points[] = new PricePoint($end->modify('-'.(count($cents) - 1 - $index).' days'), $value);
        }

        return new PriceHistory($points);
    }

    private function flat(int $cents, int $days = 10): PriceHistory
    {
        return $this->history(array_fill(0, $days, $cents));
    }

    // ------------------------------------------------------------ the score

    #[Test]
    public function the_cheapest_a_route_has_ever_been_scores_at_the_top(): void
    {
        $deal = (new DealScorer)->score(4000, $this->stats(), $this->flat(4000), self::MATURE);

        // percentile 100, absolute 100, trend 50 (flat) => 0.6*100 + 0.25*50 + 0.15*100
        $this->assertSame(88, $deal->score);
        $this->assertSame(ScoringPolicy::TIER_INSANE, $deal->tier);
        $this->assertTrue($deal->confident);
    }

    #[Test]
    public function a_fare_at_the_usual_price_scores_around_the_middle(): void
    {
        $deal = (new DealScorer)->score(8000, $this->stats(), $this->flat(8000), self::MATURE);

        // percentile 50, absolute 0, trend 50 => 30 + 12.5 + 0
        $this->assertSame(43, $deal->score);
        $this->assertSame(ScoringPolicy::TIER_NONE, $deal->tier);
    }

    #[Test]
    public function a_fare_above_the_usual_price_scores_low_and_says_wait(): void
    {
        $deal = (new DealScorer)->score(11000, $this->stats(), $this->flat(11000), self::MATURE);

        $this->assertLessThan(50, $deal->score);
        $this->assertSame(Verdict::TONE_WARN, $deal->verdict->tone);
        $this->assertSame('Wait', $deal->verdict->short);
    }

    #[Test]
    public function falling_beats_flat_at_the_same_price(): void
    {
        $scorer = new DealScorer;
        $stats = $this->stats();

        $falling = $scorer->score(6000, $stats, $this->history([7000, 6800, 6600, 6400, 6200, 6000]), self::MATURE);
        $steady = $scorer->score(6000, $stats, $this->flat(6000, 6), self::MATURE);
        $rising = $scorer->score(6000, $stats, $this->history([5000, 5200, 5400, 5600, 5800, 6000]), self::MATURE);

        $this->assertGreaterThan($steady->score, $falling->score);
        $this->assertGreaterThan($rising->score, $steady->score);
    }

    /**
     * The trend is capped, not unbounded: a fare in free-fall is still only
     * worth its 25 points, so it cannot rescue a fare that is dear.
     */
    #[Test]
    public function the_trend_cannot_outweigh_the_price(): void
    {
        $deal = (new DealScorer)->score(
            15000,
            $this->stats(),
            $this->history([30000, 26000, 22000, 19000, 17000, 15000]),
            self::MATURE,
        );

        $this->assertLessThan(50, $deal->score);
    }

    // ------------------------------------------------- missing-input degrades

    #[Test]
    public function a_route_with_no_history_is_scored_on_what_is_left(): void
    {
        $deal = (new DealScorer)->score(4000, $this->stats(), PriceHistory::empty(), self::MATURE);

        // Percentile and absolute only, renormalised over 75 of the weight —
        // both are 100 here, so the score is 100 and not 75.
        $this->assertSame(100, $deal->score);
        $this->assertTrue($deal->confident);
    }

    #[Test]
    public function a_route_with_no_statistics_is_scored_on_its_trend_alone(): void
    {
        $deal = (new DealScorer)->score(6000, null, $this->history([9000, 8000, 7000, 6000]), self::MATURE);

        $this->assertTrue($deal->confident);
        $this->assertGreaterThan(50, $deal->score);
        // The advice must not claim a bargain it has no way of knowing about.
        $this->assertStringContainsString('direction alone', $deal->advice->body);
    }

    #[Test]
    public function a_route_with_nothing_at_all_has_no_opinion(): void
    {
        $deal = (new DealScorer)->score(6000, null, PriceHistory::empty(), self::MATURE);

        $this->assertSame(0, $deal->score);
        $this->assertFalse($deal->confident);
        $this->assertSame(ScoringPolicy::TIER_NONE, $deal->tier);
        $this->assertSame('Not enough data yet', $deal->verdict->label);
    }

    /**
     * The one case a zero price must never fall into: "we do not know" and
     * "it is free" are different answers.
     */
    #[Test]
    public function no_opinion_is_not_a_score_of_a_free_flight(): void
    {
        $scorer = new DealScorer;

        $this->assertFalse($scorer->noOpinion()->confident);
        $this->assertSame(0, $scorer->noOpinion()->score);
        $this->assertTrue($scorer->score(0, $this->stats(), PriceHistory::empty(), self::MATURE)->confident);
    }

    #[Test]
    public function a_route_whose_price_never_moves_still_scores(): void
    {
        $flatStats = new PriceStats(7000, 7000, 7000, 7000, 7000);

        $deal = (new DealScorer)->score(7000, $flatStats, $this->flat(7000), self::MATURE);

        $this->assertTrue($deal->confident);
        /*
         * Percentile 50 and trend 50; the absolute component drops out
         * entirely because a route whose floor IS its usual price gives it
         * nothing to measure, and the remaining two are renormalised rather
         * than the score being docked 15 points.
         */
        $this->assertSame(50, $deal->score);
    }

    /**
     * The same flat route, but today's fare is under everything it has ever
     * been. The percentile component still sees that clearly.
     */
    #[Test]
    public function a_price_below_a_flat_routes_only_price_still_scores_high(): void
    {
        $flatStats = new PriceStats(7000, 7000, 7000, 7000, 7000);

        $deal = (new DealScorer)->score(5000, $flatStats, $this->flat(5000), self::MATURE);

        $this->assertGreaterThanOrEqual(80, $deal->score);
    }

    // ------------------------------------------------------- the day-1 floor

    /**
     * THE PRODUCTION BUG, AS A TEST. With `ORBIT_STATS_PROVIDER=self` the
     * statistics ARE the observations, so on a route's first morning the
     * current fare is its own minimum, median and maximum: every component
     * scores it perfectly, and the app announces an insane deal about a route
     * it knows one number about. The inputs below are exactly that shape —
     * today's price sitting on the floor of a distribution one price wide.
     */
    #[Test]
    public function a_route_watched_for_one_morning_has_no_opinion_however_well_it_scores(): void
    {
        $dayOne = new PriceStats(4400, 4400, 4400, 4400, 4400);

        $deal = (new DealScorer)->score(4400, $dayOne, $this->history([4400]), 1);

        $this->assertSame(0, $deal->score);
        $this->assertFalse($deal->confident);
        $this->assertSame(ScoringPolicy::TIER_NONE, $deal->tier);
        $this->assertSame('Not enough data yet', $deal->verdict->label);
        $this->assertSame(Verdict::TONE_NORMAL, $deal->verdict->tone);
    }

    /**
     * The verdict has to degrade with the flag and not merely beside it:
     * resources/js/Components/globe/SpotlightCard.vue prints `verdict.label`
     * without asking whether Orbit meant it, so "Good price — book" next to
     * `confident: false` would be a booking recommendation nobody could see was
     * a guess.
     */
    #[Test]
    public function the_verdict_degrades_with_the_confidence_and_not_beside_it(): void
    {
        $deal = (new DealScorer)->score(4000, $this->stats(), $this->flat(4000), 3);

        $this->assertNotSame('Good price — book', $deal->verdict->label);
        $this->assertSame('Not enough data yet', $deal->verdict->label);
        /*
         * "PRICING" AND NOT "WATCHING". The sentence is shown on the detail
         * screen of routes nobody watches — `POST /api/routes/lookup` opens it
         * on pairs the poller will never visit again — so "started watching"
         * was a promise the app does not keep, on the very screen offering a
         * "Watch this route" button. Flagged in PR #29.
         */
        $this->assertStringContainsString('only just started pricing', $deal->advice->body);
        $this->assertStringNotContainsString('watching', $deal->advice->body);
    }

    /**
     * The boundary. Six mornings is not a week and seven is — the same floor
     * App\Domain\Alerts\AlertPolicy gates alerts on, from the same config key,
     * so a screen cannot say "book it" about a route the alert engine considers
     * too young to mention.
     */
    #[Test]
    #[DataProvider('maturities')]
    public function confidence_arrives_exactly_at_the_floor(int $trackingDays, bool $confident): void
    {
        $deal = (new DealScorer)->score(4000, $this->stats(), $this->flat(4000), $trackingDays);

        $this->assertSame($confident, $deal->confident);

        /*
         * The same 88 the top-of-file case computes (percentile 100, trend 50,
         * absolute 100), asserted here so the floor is seen to switch between
         * "the real answer" and "no answer" rather than between two scores.
         */
        $this->assertSame($confident ? 88 : 0, $deal->score);
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function maturities(): array
    {
        return [
            'nothing at all' => [0, false],
            'the first morning' => [1, false],
            'one morning short' => [6, false],
            'exactly the floor' => [7, true],
            'a fortnight' => [14, true],
        ];
    }

    /**
     * The floor is the policy's, not a literal in the scorer: a deployment that
     * wants the old behaviour back sets it to zero rather than editing a class.
     */
    #[Test]
    public function a_zero_floor_scores_the_first_morning(): void
    {
        $scorer = new DealScorer(new ScoringPolicy(minTrackingDays: 0));

        $this->assertTrue($scorer->score(4000, $this->stats(), $this->flat(4000), 1)->confident);
    }

    // ---------------------------------------------------------- the verdicts

    #[Test]
    public function a_great_score_that_is_still_falling_says_so(): void
    {
        $deal = (new DealScorer)->score(4500, $this->stats(), $this->history([7000, 6500, 6000, 5500, 5000, 4500]), self::MATURE);

        $this->assertSame('Cheap & still falling', $deal->verdict->label);
        $this->assertSame('Falling', $deal->verdict->short);
        $this->assertSame(Verdict::TONE_INFO, $deal->verdict->tone);
    }

    #[Test]
    public function a_great_score_that_has_settled_says_book_it(): void
    {
        $deal = (new DealScorer)->score(4500, $this->stats(), $this->flat(4500), self::MATURE);

        $this->assertSame('Good price — book', $deal->verdict->label);
        $this->assertSame('Good', $deal->verdict->short);
        $this->assertSame(Verdict::TONE_GOOD, $deal->verdict->tone);
    }

    #[Test]
    public function the_advice_quotes_the_same_numbers_the_score_used(): void
    {
        $deal = (new DealScorer)->score(5000, $this->stats(), $this->flat(5000), self::MATURE);

        $this->assertSame($deal->verdict->label, $deal->advice->title);
        $this->assertSame($deal->verdict->tone, $deal->advice->tone);
        // €50 against a usual €80 is 38% under.
        $this->assertStringContainsString('€50', $deal->advice->body);
        $this->assertStringContainsString('38%', $deal->advice->body);
        $this->assertStringContainsString('€80', $deal->advice->body);
    }

    #[Test]
    public function every_verdict_tone_is_one_the_design_has_a_colour_for(): void
    {
        $scorer = new DealScorer;
        $stats = $this->stats();
        $tones = [Verdict::TONE_GOOD, Verdict::TONE_INFO, Verdict::TONE_NORMAL, Verdict::TONE_WARN];

        foreach ([3000, 4500, 6000, 8000, 10000, 14000] as $price) {
            foreach ([$this->flat($price), $this->history([$price * 2, $price]), $this->history([intdiv($price, 2), $price])] as $history) {
                $this->assertContains($scorer->score($price, $stats, $history, self::MATURE)->verdict->tone, $tones);
            }
        }
    }

    // ------------------------------------------------------------ the policy

    #[Test]
    public function the_weights_are_configurable_and_are_actually_used(): void
    {
        $trendOnly = new DealScorer(new ScoringPolicy(
            percentileWeight: 0.0,
            trendWeight: 100.0,
            absoluteWeight: 0.0,
        ));

        // A fare at the very bottom of its range, but perfectly flat: with the
        // price components switched off this is the trend's 50 and nothing else.
        $this->assertSame(50, $trendOnly->score(4000, $this->stats(), $this->flat(4000), self::MATURE)->score);
    }

    #[Test]
    public function the_tiers_come_from_the_policy(): void
    {
        $policy = new ScoringPolicy;

        $this->assertSame(ScoringPolicy::TIER_INSANE, $policy->tierFor(80));
        $this->assertSame(ScoringPolicy::TIER_GREAT, $policy->tierFor(79));
        $this->assertSame(ScoringPolicy::TIER_GREAT, $policy->tierFor(65));
        $this->assertSame(ScoringPolicy::TIER_GOOD, $policy->tierFor(50));
        $this->assertSame(ScoringPolicy::TIER_NONE, $policy->tierFor(49));
    }

    #[Test]
    public function a_score_is_always_between_zero_and_one_hundred(): void
    {
        $scorer = new DealScorer;
        $stats = $this->stats();

        foreach ([0, 1000, 4000, 8000, 16000, 99000] as $price) {
            foreach ([PriceHistory::empty(), $this->flat($price ?: 1), $this->history([100000, $price ?: 1])] as $history) {
                $score = $scorer->score($price, $stats, $history, self::MATURE)->score;

                $this->assertGreaterThanOrEqual(0, $score);
                $this->assertLessThanOrEqual(100, $score);
            }
        }
    }
}
