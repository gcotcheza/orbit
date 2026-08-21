<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Alerts;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use App\Domain\Alerts\LastAlert;
use App\Domain\Alerts\AlertPolicy;
use App\Domain\Alerts\AlertDecision;
use App\Domain\Alerts\AlertCandidate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Whether to interrupt somebody — every branch of it. A plain PHPUnit
 * TestCase: the policy imports no framework (docs/BUSINESS-LOGIC.md §10).
 */
final class AlertPolicyTest extends TestCase
{
    private const COOLDOWN_HOURS = 24;

    private const DROP_PERCENT = 5;

    private const FLOOR_DAYS = 7;

    private const INSANE = 80;

    /**
     * Watched long enough for its score to mean something; used by every
     * test not about the maturity gate itself.
     */
    private const MATURE = 30;

    /** `orbit.alerts.max_fare_age_days` — older than this is a stale fare. */
    private const MAX_FARE_AGE_DAYS = 2;

    /** `orbit.alerts.near_departure_weeks` — inside this is "leaving soon". */
    private const NEAR_WEEKS = 3;

    private function policy(): AlertPolicy
    {
        return new AlertPolicy(
            self::COOLDOWN_HOURS,
            self::DROP_PERCENT,
            self::FLOOR_DAYS,
            self::MAX_FARE_AGE_DAYS,
            self::NEAR_WEEKS,
        );
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    /**
     * The morning this rule was written for — day-one statistics agree the
     * fare is the best ever seen (docs/BUSINESS-LOGIC.md §7 "The day-1 floor").
     */
    #[Test]
    public function a_route_watched_since_yesterday_says_nothing_however_well_it_scores(): void
    {
        $decision = $this->policy()->decide(
            AlertCandidate::watchedRoute(100, 4400, 1),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        );

        $this->assertSame(AlertDecision::ImmatureData, $decision);
        $this->assertFalse($decision->fires());
    }

    /**
     * The boundary, from both sides — inclusive, or the floor quietly
     * becomes eight (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    #[DataProvider('maturities')]
    public function the_floor_is_the_number_of_days_and_it_is_inclusive(int $trackingDays, AlertDecision $expected): void
    {
        $this->assertSame($expected, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, $trackingDays),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * @return array<string, array{int, AlertDecision}>
     */
    public static function maturities(): array
    {
        return [
            'nothing at all'                 => [0, AlertDecision::ImmatureData],
            'the first morning'              => [1, AlertDecision::ImmatureData],
            'one morning short of the floor' => [6, AlertDecision::ImmatureData],
            'exactly the floor'              => [7, AlertDecision::Fired],
            'a fortnight'                    => [14, AlertDecision::Fired],
        ];
    }

    /**
     * The asymmetry, asserted: a rule match is never held for being new
     * (docs/BUSINESS-LOGIC.md §10 "route_deal vs rule_match").
     */
    #[Test]
    public function a_rule_match_is_never_held_for_being_new(): void
    {
        $this->assertSame(AlertDecision::Fired, $this->policy()->decide(
            AlertCandidate::ruleMatch(3900),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * A day-one route usually scores ABOVE the sensitivity — "ordinary" would
     * be the wrong sentence in the log (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function immaturity_is_answered_before_the_threshold(): void
    {
        $this->assertSame(AlertDecision::ImmatureData, $this->policy()->decide(
            AlertCandidate::watchedRoute(12, 9900, 1),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * And before the cooldown: a route reset since is starting again, not
     * cooling down.
     */
    #[Test]
    public function immaturity_is_answered_before_the_cooldown(): void
    {
        $this->assertSame(AlertDecision::ImmatureData, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, 2),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 9000),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * A floor of zero is "trust the score from the first morning" — kept
     * reachable so the gate is a setting, not a hard-coded opinion.
     */
    #[Test]
    public function a_zero_floor_lets_a_day_old_route_fire(): void
    {
        $policy = new AlertPolicy(self::COOLDOWN_HOURS, self::DROP_PERCENT, 0);

        $this->assertSame(AlertDecision::Fired, $policy->decide(
            AlertCandidate::watchedRoute(94, 4400, 1),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_negative_floor_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlertPolicy(self::COOLDOWN_HOURS, self::DROP_PERCENT, -1);
    }

    #[Test]
    public function a_watched_route_at_exactly_the_sensitivity_fires(): void
    {
        $decision = $this->policy()->decide(
            AlertCandidate::watchedRoute(self::INSANE, 4400, self::MATURE),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        );

        $this->assertSame(AlertDecision::Fired, $decision);
        $this->assertTrue($decision->fires());
    }

    #[Test]
    public function one_point_short_of_the_sensitivity_is_held(): void
    {
        $decision = $this->policy()->decide(
            AlertCandidate::watchedRoute(self::INSANE - 1, 4400, self::MATURE),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        );

        $this->assertSame(AlertDecision::BelowThreshold, $decision);
        $this->assertFalse($decision->fires());
    }

    /**
     * The sensitivity is the whole of the difference between the three levels,
     * so the same score has to answer differently at each of them.
     */
    #[Test]
    #[DataProvider('sensitivities')]
    public function the_same_score_answers_to_each_sensitivity(int $minimum, int $score, AlertDecision $expected): void
    {
        $this->assertSame($expected, $this->policy()->decide(
            AlertCandidate::watchedRoute($score, 6000, self::MATURE),
            $minimum,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * @return array<string, array{int, int, AlertDecision}>
     */
    public static function sensitivities(): array
    {
        return [
            'Relaxed (80) holds a 72'     => [80, 72, AlertDecision::BelowThreshold],
            'Balanced (65) fires on it'   => [65, 72, AlertDecision::Fired],
            'Eager (50) fires on it'      => [50, 72, AlertDecision::Fired],
            'Eager (50) still holds a 43' => [50, 43, AlertDecision::BelowThreshold],
        ];
    }

    /**
     * A rule match has no score — sensitivity must not become a second
     * filter on top of the rule's own maximum price.
     */
    #[Test]
    public function a_rule_match_ignores_the_sensitivity_entirely(): void
    {
        $this->assertSame(AlertDecision::Fired, $this->policy()->decide(
            AlertCandidate::ruleMatch(3900),
            /* Higher than any score could ever be. */
            100,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * The order of the two rules is asserted, not assumed — held for the
     * threshold, not the cooldown, it never qualified.
     */
    #[Test]
    public function the_threshold_is_answered_before_the_cooldown(): void
    {
        $this->assertSame(AlertDecision::BelowThreshold, $this->policy()->decide(
            AlertCandidate::watchedRoute(40, 4400, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 9000),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_route_nobody_has_been_told_about_fires(): void
    {
        $this->assertSame(AlertDecision::Fired, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function the_same_price_inside_the_cooldown_is_held(): void
    {
        $decision = $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-14 18:55:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        );

        $this->assertSame(AlertDecision::CoolingDown, $decision);
        $this->assertFalse($decision->fires());
    }

    /**
     * The boundary is inclusive — an exclusive comparison would suppress
     * every second morning depending on queue timing (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function exactly_a_day_later_the_cooldown_is_over(): void
    {
        $this->assertSame(AlertDecision::Fired, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-14 06:55:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function one_second_short_of_a_day_it_is_not(): void
    {
        $this->assertSame(AlertDecision::CoolingDown, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-14 06:55:01'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_rule_match_cools_down_like_anything_else(): void
    {
        $this->assertSame(AlertDecision::CoolingDown, $this->policy()->decide(
            AlertCandidate::ruleMatch(3900),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 3900),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * €44 → €41.80 is exactly five percent, and it counts — integer cent
     * arithmetic, since a float comparison goes whichever way the bit falls.
     */
    #[Test]
    public function a_fare_at_exactly_the_drop_threshold_supersedes_the_cooldown(): void
    {
        $decision = $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4180, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        );

        $this->assertSame(AlertDecision::SupersededByDrop, $decision);
        $this->assertTrue($decision->fires());
    }

    #[Test]
    public function one_cent_short_of_the_drop_is_still_cooling_down(): void
    {
        $this->assertSame(AlertDecision::CoolingDown, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4181, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_fare_that_has_gone_up_inside_the_cooldown_says_nothing(): void
    {
        $this->assertSame(AlertDecision::CoolingDown, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 5200, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * A drop does not rescue something that never qualified — the threshold
     * is answered first.
     */
    #[Test]
    public function a_drop_does_not_beat_the_threshold(): void
    {
        $this->assertSame(AlertDecision::BelowThreshold, $this->policy()->decide(
            AlertCandidate::watchedRoute(40, 2000, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_rule_match_can_punch_through_its_own_cooldown_too(): void
    {
        $this->assertSame(AlertDecision::SupersededByDrop, $this->policy()->decide(
            AlertCandidate::ruleMatch(3600),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 3900),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * A cooldown of zero is a legitimate setting — "tell me every morning" —
     * and must not accidentally mean "never again".
     */
    #[Test]
    public function a_zero_cooldown_fires_every_time(): void
    {
        $policy = new AlertPolicy(0, self::DROP_PERCENT, self::FLOOR_DAYS);

        $this->assertSame(AlertDecision::Fired, $policy->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 06:54:59'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_zero_drop_makes_any_fall_at_all_worth_saying(): void
    {
        $policy = new AlertPolicy(self::COOLDOWN_HOURS, 0, self::FLOOR_DAYS);

        $this->assertSame(AlertDecision::SupersededByDrop, $policy->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_negative_cooldown_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlertPolicy(-1, self::DROP_PERCENT, self::FLOOR_DAYS);
    }

    #[Test]
    #[DataProvider('impossiblePercentages')]
    public function a_drop_that_is_not_a_percentage_is_refused(int $percent): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlertPolicy(self::COOLDOWN_HOURS, $percent, self::FLOOR_DAYS);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function impossiblePercentages(): array
    {
        return [
            'negative'       => [-1],
            'over a hundred' => [101],
        ];
    }

    /**
     * The four-way truth table, which is the entire rule: held only when
     * BOTH stale AND near (docs/BUSINESS-LOGIC.md §10 "The freshness guard").
     */
    #[Test]
    #[DataProvider('freshnessCorners')]
    public function a_stale_fare_is_held_only_when_the_flight_leaves_soon(
        string $foundAt,
        string $departure,
        AlertDecision $expected,
    ): void {
        $decision = $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE, $this->at($foundAt), $this->at($departure)),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        );

        $this->assertSame($expected, $decision);
    }

    /**
     * @return array<string, array{string, string, AlertDecision}>
     */
    public static function freshnessCorners(): array
    {
        return [
            /* Found four days ago, flying in a week: the one held case. */
            'stale fare, near departure' => [
                '2026-08-11 06:00:00', '2026-08-22 00:00:00', AlertDecision::StaleFare,
            ],
            /* This morning's price for the same flight. */
            'fresh fare, near departure' => [
                '2026-08-15 06:10:00', '2026-08-22 00:00:00', AlertDecision::Fired,
            ],
            /* The same four-day-old price, for a departure in December. */
            'stale fare, far departure' => [
                '2026-08-11 06:00:00', '2026-12-20 00:00:00', AlertDecision::Fired,
            ],
            'fresh fare, far departure' => [
                '2026-08-15 06:10:00', '2026-12-20 00:00:00', AlertDecision::Fired,
            ],
        ];
    }

    /**
     * Both boundaries lean opposite ways on purpose — toward saying something
     * rather than holding it (docs/BUSINESS-LOGIC.md §10 "The freshness guard").
     */
    #[Test]
    #[DataProvider('freshnessBoundaries')]
    public function the_two_boundaries_land_where_the_config_says(
        string $foundAt,
        string $departure,
        AlertDecision $expected,
    ): void {
        $this->assertSame($expected, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE, $this->at($foundAt), $this->at($departure)),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * @return array<string, array{string, string, AlertDecision}>
     */
    public static function freshnessBoundaries(): array
    {
        /* Exactly three weeks after the 06:55 clock below. */
        $near = '2026-09-05 06:55:00';

        return [
            'exactly two days old is not too old' => [
                '2026-08-13 06:55:00', $near, AlertDecision::Fired,
            ],
            'a second over two days is' => [
                '2026-08-13 06:54:59', $near, AlertDecision::StaleFare,
            ],
            'exactly three weeks out is near' => [
                '2026-08-11 06:00:00', $near, AlertDecision::StaleFare,
            ],
            'a second past three weeks is not' => [
                '2026-08-11 06:00:00', '2026-09-05 06:55:01', AlertDecision::Fired,
            ],
        ];
    }

    /**
     * A null `found_at` is treated as fresh, and the defence is the point —
     * reading it as stale would have switched off every alert (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function a_fare_of_unknown_age_is_not_treated_as_an_old_one(): void
    {
        $decision = $this->policy()->decide(
            /* Departing in a week — the near half of the rule is satisfied. */
            AlertCandidate::watchedRoute(94, 4400, self::MATURE, null, $this->at('2026-08-22 00:00:00')),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        );

        $this->assertSame(AlertDecision::Fired, $decision);
    }

    /**
     * And the same with no departure date at all — no "how soon" to be near,
     * so the rule has nothing to apply.
     */
    #[Test]
    public function an_alert_with_no_departure_date_is_not_held_for_staleness(): void
    {
        $this->assertSame(AlertDecision::Fired, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400, self::MATURE, $this->at('2026-01-01 00:00:00'), null),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * The asymmetry stops here, deliberately: this rule asks whether the fare
     * is REAL, not whether Orbit holds an opinion (docs/BUSINESS-LOGIC.md §10).
     */
    #[Test]
    public function a_rule_match_gets_the_same_freshness_guard_as_a_watched_route(): void
    {
        $held = $this->policy()->decide(
            AlertCandidate::ruleMatch(3800, $this->at('2026-08-11 06:00:00'), $this->at('2026-08-22 00:00:00')),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        );

        $this->assertSame(AlertDecision::StaleFare, $held);
        $this->assertFalse($held->fires());

        /* …and the same match on a fare found this morning still fires, so the
           guard is the age and not the kind. */
        $this->assertSame(AlertDecision::Fired, $this->policy()->decide(
            AlertCandidate::ruleMatch(3800, $this->at('2026-08-15 06:10:00'), $this->at('2026-08-22 00:00:00')),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * Order: the threshold is answered first — `stale-fare` for an ordinary
     * price would send somebody hunting a data problem.
     */
    #[Test]
    public function an_ordinary_price_is_below_threshold_rather_than_stale(): void
    {
        $this->assertSame(AlertDecision::BelowThreshold, $this->policy()->decide(
            AlertCandidate::watchedRoute(40, 4400, self::MATURE, $this->at('2026-08-01 06:00:00'), $this->at('2026-08-22 00:00:00')),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * Order: and maturity before that — a day-old route has nothing to say
     * whatever its fares look like.
     */
    #[Test]
    public function a_young_route_is_immature_rather_than_stale(): void
    {
        $this->assertSame(AlertDecision::ImmatureData, $this->policy()->decide(
            AlertCandidate::watchedRoute(100, 4400, 1, $this->at('2026-08-01 06:00:00'), $this->at('2026-08-22 00:00:00')),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    /**
     * Order: but before the cooldown, so the answer is a function of the
     * candidate alone — a still-falling stale price must not beat it.
     */
    #[Test]
    public function a_stale_fare_is_held_even_when_a_further_drop_would_have_beaten_the_cooldown(): void
    {
        $this->assertSame(AlertDecision::StaleFare, $this->policy()->decide(
            /* Half the last alerted price: comfortably past `further_drop_percent`. */
            AlertCandidate::watchedRoute(94, 2200, self::MATURE, $this->at('2026-08-11 06:00:00'), $this->at('2026-08-22 00:00:00')),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    #[DataProvider('impossibleFreshnessSettings')]
    public function a_freshness_setting_that_runs_backwards_is_refused(int $ageDays, int $weeks): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlertPolicy(self::COOLDOWN_HOURS, self::DROP_PERCENT, self::FLOOR_DAYS, $ageDays, $weeks);
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function impossibleFreshnessSettings(): array
    {
        return [
            'negative fare age' => [-1, 3],
            'negative horizon'  => [2, -1],
        ];
    }

    /**
     * The truth table the whole pipeline branches on, in one place.
     */
    #[Test]
    #[DataProvider('decisions')]
    public function only_two_of_the_six_decisions_send_anything(AlertDecision $decision, bool $fires): void
    {
        $this->assertSame($fires, $decision->fires());
    }

    /**
     * @return array<string, array{AlertDecision, bool}>
     */
    public static function decisions(): array
    {
        return [
            'fired'                => [AlertDecision::Fired, true],
            'superseded by a drop' => [AlertDecision::SupersededByDrop, true],
            'below the threshold'  => [AlertDecision::BelowThreshold, false],
            'immature data'        => [AlertDecision::ImmatureData, false],
            'cooling down'         => [AlertDecision::CoolingDown, false],
            'stale fare'           => [AlertDecision::StaleFare, false],
        ];
    }
}
