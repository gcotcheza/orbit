<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Discovery;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use App\Domain\Discovery\DealCandidate;
use App\Domain\Discovery\CandidateScorer;
use App\Domain\Discovery\DiscoveryPolicy;

/**
 * The cheap half of the discovery funnel, pinned here rather than inferred
 * from a job's output. Real rows from the 2026-08-16 sweep (docs/BUSINESS-LOGIC.md §16).
 */
final class CandidateScorerTest extends TestCase
{
    private const NOW = '2026-08-16 08:00:00';

    /**
     * config/orbit.php's shipped defaults, written out rather than read —
     * a pure unit test can't read config (docs/BUSINESS-LOGIC.md §16).
     */
    private function policy(
        float $minKilometres = 400.0,
        int $maxPriceCents = 12000,
        float $maxCentsPerKilometre = 3.0,
        int $maxFoundAgeDays = 3,
        int $shortlist = 5,
        float $maxPercentile = 10.0,
        int $minSavingsCents = 1500,
    ): DiscoveryPolicy {
        return new DiscoveryPolicy(
            minKilometres: $minKilometres,
            maxPriceCents: $maxPriceCents,
            maxCentsPerKilometre: $maxCentsPerKilometre,
            maxFoundAgeDays: $maxFoundAgeDays,
            shortlist: $shortlist,
            maxPercentile: $maxPercentile,
            minSavingsCents: $minSavingsCents,
            expiresAfterHours: 36,
            maxRows: 12,
        );
    }

    private function candidate(
        string $origin,
        string $destination,
        int $euros,
        float $km,
        string $foundAt = '2026-08-15 08:00:00',
        string $departure = '2026-09-15',
    ): DealCandidate {
        return new DealCandidate(
            originIata: $origin,
            destinationIata: $destination,
            departureDate: new DateTimeImmutable($departure),
            cents: $euros * 100,
            kilometres: $km,
            foundAt: $foundAt === '' ? null : new DateTimeImmutable($foundAt),
        );
    }

    /**
     * The real top of the 2026-08-16 sweep, in the order the config's defaults
     * put it in.
     */
    #[Test]
    public function it_ranks_the_real_sweep_by_what_a_kilometre_costs(): void
    {
        $scorer = new CandidateScorer($this->policy());

        $ranked = $scorer->admit([
            $this->candidate('DUS', 'AGP', 29, 1853),
            $this->candidate('DUS', 'RAK', 27, 2502),
            $this->candidate('EIN', 'VNO', 18, 1372),
            $this->candidate('DUS', 'TNG', 23, 2003),
            $this->candidate('DUS', 'PSR', 16, 1134),
        ], new DateTimeImmutable(self::NOW));

        $this->assertSame(
            ['DUS-RAK', 'DUS-TNG', 'EIN-VNO', 'DUS-PSR', 'DUS-AGP'],
            array_map(static fn (DealCandidate $c): string => $c->routeCode(), $ranked),
        );
    }

    /**
     * Singapore at €287 comfortably clears the ratio threshold — yet not
     * what this screen promises (docs/BUSINESS-LOGIC.md §16).
     */
    #[Test]
    public function the_price_ceiling_keeps_long_haul_off_a_screen_about_impulse_fares(): void
    {
        $scorer = new CandidateScorer($this->policy());
        $now = new DateTimeImmutable(self::NOW);

        $singapore = $this->candidate('AMS', 'SIN', 287, 10514);

        $this->assertLessThan(3.0, $singapore->centsPerKilometre(), 'It clears the ratio…');
        $this->assertSame([], $scorer->admit([$singapore], $now), '…and is still refused on price.');

        /* Raise only the ceiling and it is admitted — so nothing else rejected it. */
        $generous = new CandidateScorer($this->policy(maxPriceCents: 30000));

        $this->assertCount(1, $generous->admit([$singapore], $now));
    }

    /**
     * A €51 Brussels is not a discovery at any price — see HaversineTest for
     * the provider figure that would have made it one.
     */
    #[Test]
    public function it_drops_hops_short_enough_to_be_a_train(): void
    {
        $scorer = new CandidateScorer($this->policy());

        $this->assertSame([], $scorer->admit([
            $this->candidate('AMS', 'BRU', 51, 158),
            $this->candidate('AMS', 'DUS', 40, 205),
        ], new DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function it_drops_fares_over_the_ratio_threshold(): void
    {
        $scorer = new CandidateScorer($this->policy());

        /* €90 over 1,000 km is 9.0 cents/km — three times the floor. */
        $this->assertSame([], $scorer->admit([
            $this->candidate('AMS', 'XXX', 90, 1000),
        ], new DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function it_discards_prices_older_than_the_policy_allows(): void
    {
        $scorer = new CandidateScorer($this->policy());
        $now = new DateTimeImmutable(self::NOW);

        $fresh = $this->candidate('DUS', 'RAK', 27, 2502, '2026-08-15 08:00:00');
        $edge = $this->candidate('DUS', 'TNG', 23, 2003, '2026-08-13 08:00:00');
        $stale = $this->candidate('EIN', 'VNO', 18, 1372, '2026-08-12 07:00:00');

        $codes = array_map(
            static fn (DealCandidate $c): string => $c->routeCode(),
            $scorer->admit([$fresh, $edge, $stale], $now),
        );

        $this->assertContains('DUS-RAK', $codes);
        $this->assertContains('DUS-TNG', $codes, 'Exactly at the threshold is inside it.');
        $this->assertNotContains('EIN-VNO', $codes);
    }

    /**
     * The opposite of what AlertPolicy does with the same fact, deliberately
     * — unknown vintage must not be shown (docs/BUSINESS-LOGIC.md §16).
     */
    #[Test]
    public function a_price_of_unknown_age_is_treated_as_too_old(): void
    {
        $scorer = new CandidateScorer($this->policy());

        $unknown = $this->candidate('DUS', 'RAK', 27, 2502, foundAt: '');

        $this->assertNull($unknown->ageInDays(new DateTimeImmutable(self::NOW)));
        $this->assertSame([], $scorer->admit([$unknown], new DateTimeImmutable(self::NOW)));
    }

    #[Test]
    public function it_shortlists_only_as_many_as_the_budget_allows(): void
    {
        $scorer = new CandidateScorer($this->policy(shortlist: 3));

        $admitted = $scorer->admit([
            $this->candidate('DUS', 'RAK', 27, 2502),
            $this->candidate('DUS', 'TNG', 23, 2003),
            $this->candidate('EIN', 'VNO', 18, 1372),
            $this->candidate('DUS', 'PSR', 16, 1134),
            $this->candidate('DUS', 'AGP', 29, 1853),
        ], new DateTimeImmutable(self::NOW));

        $this->assertCount(5, $admitted);
        $this->assertCount(3, $scorer->shortlist($admitted));
    }

    /**
     * Málaga appeared in both the DUS and EIN sweeps — verifying both wastes
     * two of five Google searches on one thing (docs/BUSINESS-LOGIC.md §16).
     */
    #[Test]
    public function it_never_spends_two_slots_on_the_same_city(): void
    {
        $scorer = new CandidateScorer($this->policy());

        $admitted = $scorer->admit([
            $this->candidate('DUS', 'AGP', 29, 1853),   // 15.6 m€/km — the better one
            $this->candidate('EIN', 'AGP', 31, 1819),   // 17.0
            $this->candidate('DUS', 'RAK', 27, 2502),
        ], new DateTimeImmutable(self::NOW));

        $codes = array_map(
            static fn (DealCandidate $c): string => $c->routeCode(),
            $scorer->shortlist($admitted),
        );

        $this->assertSame(['DUS-RAK', 'DUS-AGP'], $codes);
        $this->assertNotContains('EIN-AGP', $codes);
    }

    /**
     * A discovery list that reordered itself between two runs on identical
     * inputs would look like news.
     */
    #[Test]
    public function ties_are_broken_by_route_code_so_the_order_is_total(): void
    {
        $scorer = new CandidateScorer($this->policy());

        $ranked = $scorer->admit([
            $this->candidate('DUS', 'ZZZ', 20, 1000),
            $this->candidate('AMS', 'YYY', 20, 1000),
            $this->candidate('EIN', 'XXX', 20, 1000),
        ], new DateTimeImmutable(self::NOW));

        $this->assertSame(
            ['AMS-YYY', 'DUS-ZZZ', 'EIN-XXX'],
            array_map(static fn (DealCandidate $c): string => $c->routeCode(), $ranked),
        );
    }

    #[Test]
    public function it_places_a_fare_in_its_own_window(): void
    {
        /* The real DUS-AGP October window, 23 fares, recorded 2026-08-16. */
        $window = [29, 59, 59, 59, 67, 78, 78, 78, 78, 78, 78, 78, 78, 78, 78, 78, 78, 78, 78, 78, 88, 88, 89];

        $this->assertSame(0.0, CandidateScorer::percentile(29, $window), 'Cheapest date on the route.');
        $this->assertSame(78, CandidateScorer::median($window));

        /* €78 is the modal fare: 29, 59, 59, 59 and 67 are strictly cheaper. */
        $this->assertEqualsWithDelta(21.7, CandidateScorer::percentile(78, $window), 0.1);
        $this->assertEqualsWithDelta(95.7, CandidateScorer::percentile(89, $window), 0.1);
    }

    /**
     * Strictly cheaper: a flat window puts the candidate at 0, not 100 — the
     * savings floor is what refuses it (docs/BUSINESS-LOGIC.md §16).
     */
    #[Test]
    public function a_flat_window_scores_zero_and_is_refused_on_savings_instead(): void
    {
        $policy = $this->policy();
        $window = [40, 40, 40, 40, 40];

        $percentile = CandidateScorer::percentile(40, $window);
        $median = CandidateScorer::median($window);

        $this->assertSame(0.0, $percentile);
        $this->assertSame(40, $median);
        $this->assertFalse(
            $policy->isRemarkable($percentile, 0),
            'Bottom of its window and saving nothing is not a find.',
        );
    }

    #[Test]
    public function an_empty_window_proves_nothing_and_scores_worst(): void
    {
        $this->assertSame(100.0, CandidateScorer::percentile(29, []));
        $this->assertNull(CandidateScorer::median([]));
    }

    /**
     * The median must be a fare somebody was actually offered — it's
     * subtracted from a real price to produce the saving the screen states.
     */
    #[Test]
    public function the_median_of_an_even_window_is_an_observed_fare(): void
    {
        $this->assertSame(40, CandidateScorer::median([30, 40, 50, 60]));
        $this->assertContains(CandidateScorer::median([30, 40, 50, 60]), [30, 40, 50, 60]);
    }

    #[Test]
    public function remarkable_needs_both_halves(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->isRemarkable(0.0, 4900), 'DUS-AGP: 0th percentile, €49 under.');
        $this->assertFalse($policy->isRemarkable(40.0, 4900), 'Ordinary on its own route.');
        $this->assertFalse($policy->isRemarkable(0.0, 400), 'Cheapest of a flat window saves nothing.');
    }
}
