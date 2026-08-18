<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rules;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use App\Domain\Pricing\DatedFare;
use App\Domain\Rules\MonthWindow;
use App\Domain\Rules\RuleMatcher;
use App\Domain\Rules\RuleCriteria;
use PHPUnit\Framework\Attributes\Test;
use App\Domain\Rules\DestinationProfile;

/**
 * Which places, and which fares.
 *
 * THE CLIMATE GATE IS THE INTERESTING PART. "Somewhere sunny in spring" is
 * answered by the BEST month in the window rather than every month, and the
 * two tests that pin that down are the difference between a rule that finds
 * Lisbon in May and a rule that finds the Canaries and nothing else — see
 * config/orbit.php for the reasoning and the seeder data for the ratings.
 */
final class RuleMatcherTest extends TestCase
{
    private RuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();

        /* config('orbit.rules.warm_at') and warm_vibes. */
        $this->matcher = new RuleMatcher(4, ['sunny', 'beach']);
    }

    /**
     * @param  list<string>  $vibes
     * @param  array<int, int>  $warmth
     */
    private function place(string $iata, array $vibes, array $warmth = []): DestinationProfile
    {
        return new DestinationProfile($iata, $vibes, $warmth + array_fill(1, 12, 3));
    }

    private function fare(string $date, int $cents): DatedFare
    {
        return new DatedFare(new DateTimeImmutable($date), $cents);
    }

    // -- Where ---------------------------------------------------------------

    #[Test]
    public function a_rule_with_no_vibe_is_about_everywhere(): void
    {
        $places = [$this->place('LIS', ['city']), $this->place('FAO', ['beach'])];

        $this->assertCount(2, $this->matcher->rank(new RuleCriteria, $places));
    }

    #[Test]
    public function a_vibe_keeps_only_the_places_that_carry_it(): void
    {
        $places = [$this->place('LIS', ['city']), $this->place('FAO', ['beach', 'sunny'])];

        $ranked = $this->matcher->rank(new RuleCriteria(vibes: ['beach']), $places);

        $this->assertSame(['FAO'], array_column($ranked, 'iata'));
    }

    #[Test]
    public function places_matching_more_of_the_asked_for_vibes_come_first(): void
    {
        $places = [
            $this->place('AAA', ['sunny']),
            $this->place('BBB', ['sunny', 'beach']),
            $this->place('CCC', ['beach']),
        ];

        $ranked = $this->matcher->rank(new RuleCriteria(vibes: ['sunny', 'beach']), $places);

        $this->assertSame('BBB', $ranked[0]->iata);
    }

    /**
     * The sweep spends a capped budget on this order (App\Jobs\SweepRuleFares),
     * so it has to be total: two places that tie on everything must still come
     * back the same way round on every run.
     */
    #[Test]
    public function a_tie_is_broken_alphabetically_so_the_order_never_moves(): void
    {
        $places = [$this->place('ZZZ', ['sunny']), $this->place('AAA', ['sunny'])];

        $ranked = $this->matcher->rank(new RuleCriteria(vibes: ['sunny']), $places);

        $this->assertSame(['AAA', 'ZZZ'], array_column($ranked, 'iata'));
    }

    #[Test]
    public function a_warm_rule_with_a_window_keeps_places_warm_in_their_best_month(): void
    {
        $places = [
            /* Warm by May, like the northern Mediterranean. */
            $this->place('LIS', ['sunny'], [3 => 2, 4 => 3, 5 => 4]),
            /* Never quite warm in spring, like the Atlantic coast. */
            $this->place('OPO', ['sunny'], [3 => 2, 4 => 3, 5 => 3]),
        ];

        $ranked = $this->matcher->rank(
            new RuleCriteria(dateWindow: MonthWindow::of(3, 5), vibes: ['sunny']),
            $places,
        );

        $this->assertSame(['LIS'], array_column($ranked, 'iata'));
    }

    /**
     * A rule with no season has no season to check a climate against, and
     * inventing one would answer a question nobody asked. The `sunny` tag on
     * the destination already carries the judgement.
     */
    #[Test]
    public function a_warm_rule_with_no_window_does_not_check_the_climate(): void
    {
        $places = [$this->place('OPO', ['sunny'], array_fill(1, 12, 1))];

        $this->assertCount(1, $this->matcher->rank(new RuleCriteria(vibes: ['sunny']), $places));
    }

    #[Test]
    public function a_window_alone_does_not_check_the_climate_either(): void
    {
        $places = [$this->place('OSL', ['city'], array_fill(1, 12, 1))];

        $ranked = $this->matcher->rank(
            new RuleCriteria(dateWindow: MonthWindow::of(3, 5), vibes: ['city']),
            $places,
        );

        $this->assertCount(1, $ranked);
    }

    // -- Which fare ----------------------------------------------------------

    #[Test]
    public function with_no_criteria_it_takes_the_cheapest_fare_there_is(): void
    {
        $cheapest = $this->matcher->cheapest(new RuleCriteria, [
            $this->fare('2026-09-01', 8000),
            $this->fare('2026-09-02', 4400),
            $this->fare('2026-09-03', 6000),
        ], new DateTimeImmutable('2026-08-15'));

        $this->assertSame(4400, $cheapest?->cents);
    }

    #[Test]
    public function a_ceiling_rules_out_everything_above_it(): void
    {
        $cheapest = $this->matcher->cheapest(new RuleCriteria(maxPriceCents: 5000), [
            $this->fare('2026-09-01', 8000),
            $this->fare('2026-09-02', 6000),
        ], new DateTimeImmutable('2026-08-15'));

        $this->assertNull($cheapest);
    }

    /**
     * The cheapest FRIDAY, not the cheapest fare. Quoting Tuesday's €38 next
     * to a rule about Fridays would be a price nobody can book.
     */
    #[Test]
    public function a_departure_day_is_honoured_even_when_it_costs_more(): void
    {
        $cheapest = $this->matcher->cheapest(new RuleCriteria(departDows: [5]), [
            /* Tuesday. */
            $this->fare('2026-09-01', 3800),
            /* Friday. */
            $this->fare('2026-09-04', 5200),
        ], new DateTimeImmutable('2026-08-15'));

        $this->assertSame('2026-09-04', $cheapest?->departureDate->format('Y-m-d'));
    }

    #[Test]
    public function a_window_rules_out_departures_outside_it(): void
    {
        $criteria = new RuleCriteria(dateWindow: MonthWindow::of(3, 5));
        $today = new DateTimeImmutable('2026-08-15');

        $cheapest = $this->matcher->cheapest($criteria, [
            /* This September is outside next spring. */
            $this->fare('2026-09-01', 3000),
            /* Next April is inside it. */
            $this->fare('2027-04-10', 9000),
        ], $today);

        $this->assertSame('2027-04-10', $cheapest?->departureDate->format('Y-m-d'));
    }

    #[Test]
    public function two_equally_cheap_fares_resolve_to_the_earlier_departure(): void
    {
        $cheapest = $this->matcher->cheapest(new RuleCriteria, [
            $this->fare('2026-09-02', 4400),
            $this->fare('2026-09-09', 4400),
        ], new DateTimeImmutable('2026-08-15'));

        $this->assertSame('2026-09-02', $cheapest?->departureDate->format('Y-m-d'));
    }

    #[Test]
    public function a_route_with_no_fares_matches_nothing(): void
    {
        $this->assertNull($this->matcher->cheapest(new RuleCriteria, [], new DateTimeImmutable('2026-08-15')));
    }
}
