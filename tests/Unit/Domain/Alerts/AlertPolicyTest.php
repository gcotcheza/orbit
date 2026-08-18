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
 * Whether to interrupt somebody — every branch of it.
 *
 * A PLAIN PHPUnit TestCase and not Laravel's: the policy imports no framework
 * and the clock arrives as an argument, so there is nothing here to boot. That
 * is the property these tests are protecting as much as the arithmetic — a
 * later change that reached for `now()` inside the policy would fail here
 * before it failed at six in the morning.
 *
 * THE NUMBERS ARE THE SHIPPED ONES: 24 hours, 5%, a 7-day floor, and the
 * freshness guard's 2 days / 3 weeks — from config/orbit.php via
 * App\Providers\AppServiceProvider. They are passed explicitly so a reader can
 * check a boundary on paper.
 *
 * THE CANDIDATES IN THE OLDER TESTS CARRY NO DATES, and that is not an
 * oversight: a null `foundAt` means "how old this fare is, is not known", which
 * the freshness rule treats as fresh (see the test that defends that choice).
 * So every test that is not about freshness is unaffected by it, exactly as the
 * ones that pass `MATURE` are unaffected by the maturity gate.
 */
final class AlertPolicyTest extends TestCase
{
    private const COOLDOWN_HOURS = 24;

    private const DROP_PERCENT = 5;

    private const FLOOR_DAYS = 7;

    private const INSANE = 80;

    /**
     * A route that has been watched long enough for its score to mean
     * something. Every test that is NOT about the maturity gate passes this, so
     * that the gate cannot quietly become the reason one of them passes.
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

    // -- The data ------------------------------------------------------------

    /**
     * THE MORNING THIS RULE WAS WRITTEN FOR. The statistics provider computes a
     * route's usual price from the fares Orbit has already fetched, so on day
     * one the current fare is the route's own minimum, median and maximum —
     * every component of the score agrees it is the best price ever seen, and
     * every route on the watchlist comes back at 100/insane/confident. Eight
     * "insane deal" mails about eight routes nobody has learned anything about
     * is how a person decides this app cries wolf, once, permanently.
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
     * The boundary, from both sides. Six mornings is not a week; seven is, and
     * the seventh is the morning the score starts counting — an exclusive
     * comparison here would quietly make the floor eight.
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
     * THE ASYMMETRY, ASSERTED. A rule match is a fare at or below a maximum
     * price the owner wrote down, and that comparison is exactly as true on a
     * route's first morning as on its hundredth — no distribution is involved,
     * so there is nothing for a week of data to make more trustworthy. Gating
     * rules on maturity would silence the one feature whose whole purpose is
     * finding routes Orbit has never watched.
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
     * A day-one route is usually scoring ABOVE the sensitivity rather than
     * below it, so the two reasons are not interchangeable and the order that
     * produces the honest one is asserted rather than assumed. "We looked and
     * it was ordinary" would be the wrong sentence in the morning's log.
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
     * And before the cooldown, for the same reason: a route mailed about
     * yesterday and reset since (`orbit:reset-history`) is not "cooling down",
     * it is starting again.
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
     * A floor of zero is "trust the score from the first morning" — the
     * behaviour this app shipped with, kept reachable so that the gate is a
     * setting rather than a hard-coded opinion.
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

    // -- The threshold -------------------------------------------------------

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
     * A rule match has no score at all, and the sensitivity must not silently
     * become a second filter on it: the rule's own maximum price is its
     * threshold, and App\Application\Rules\RuleMatches applied it already.
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
     * THE ORDER OF THE TWO RULES IS ASSERTED, not assumed. A route that is both
     * under the threshold and inside its cooldown was not suppressed by the
     * cooldown — it never qualified — and the reason is the only thing this
     * value carries.
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

    // -- The cooldown --------------------------------------------------------

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
     * THE BOUNDARY IS INCLUSIVE, and it has to be. The run is scheduled daily,
     * so consecutive runs are 86,400 seconds apart to the second; an exclusive
     * comparison would suppress every second morning depending on how long the
     * queue happened to take to pick the job up.
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

    // -- The drop that beats the cooldown ------------------------------------

    /**
     * €44 → €41.80 is exactly five percent, and exactly five percent counts.
     * The threshold is written as integer arithmetic on cents for precisely
     * this case: a float comparison here goes whichever way the last bit falls.
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
     * A drop does not rescue something that never qualified: the threshold is
     * answered first, and a route scoring 40 is not news at any price.
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

    // -- The rule book itself ------------------------------------------------

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

    // -- The freshness of the evidence ---------------------------------------

    /**
     * THE FOUR-WAY TRUTH TABLE, WHICH IS THE ENTIRE RULE.
     *
     * A fare is held only when it is BOTH older than `max_fare_age_days` AND
     * for a departure inside `near_departure_weeks`. Each of the other three
     * corners fires, and each of them is a corner somebody would plausibly
     * write an `||` for:
     *
     *   - a STALE fare for a FAR-OUT departure fires, because prices five
     *     months out sit still for weeks and holding them would silence the
     *     alerts most likely to be true, about the trips there is most time to
     *     act on;
     *   - a FRESH fare for a NEAR departure is the ordinary good case and is
     *     exactly what this app exists to send;
     *   - a FRESH far-out fare has nothing wrong with it at all.
     *
     * The prices and the clock are the same in all four; only the two dates
     * move.
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
     * BOTH BOUNDARIES, AND THEY LEAN OPPOSITE WAYS ON PURPOSE — toward saying
     * something rather than holding it, which is the right bias for a rule
     * whose failure mode is silence about a real deal.
     *
     * The age is EXCLUSIVE: `max_fare_age_days` reads as "up to this old is
     * fine", so a fare found exactly 48 hours ago is two days old rather than
     * more than two. The daily poll lands at the same minute every morning, so
     * this is a case that really happens rather than a hypothetical.
     *
     * The horizon is INCLUSIVE: a departure exactly three weeks out counts as
     * near.
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
     * A NULL `found_at` IS TREATED AS FRESH, AND THE DEFENCE IS THE POINT.
     *
     * Null means "Orbit does not know how old this price is" — the state of
     * every row written before the column existed, and of any provider that
     * will not say. Reading it as STALE looks like the cautious choice and is
     * the wrong one twice over: it fails in the direction that breaks the
     * product (on the morning this shipped every row was null, so a
     * stale-by-default rule would have silently switched the whole alert system
     * off until the poller had rewritten the calendar), and it turns an absence
     * into a claim, which this app refuses to do everywhere else — a missing
     * fare is absent rather than €0, and missing statistics produce no verdict
     * rather than a bad one.
     *
     * The UI treatment is the matching half: null prints NO "Seen …" line, so
     * nothing anywhere pretends to know an age it does not have.
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
     * And the same for an alert that names no departure at all — a route with
     * no calendar fare behind it. There is no "how soon" to be near, so the
     * rule has nothing to apply and says so by not applying.
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
     * THE ASYMMETRY STOPS HERE, AND THAT IS DELIBERATE.
     *
     * A rule match is exempt from the maturity gate and from the sensitivity
     * threshold, because both of those ask whether Orbit knows enough to hold
     * an OPINION and a rule needs no opinion — its threshold is a maximum price
     * a person wrote down. This rule asks something else: whether the fare is
     * REAL. A rule match names one departure at one price with a booking link
     * under it exactly as a route deal does, and its fares are if anything the
     * stalest in the app, because they come from the speculative sweep over
     * routes nobody watches.
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
     * ORDER: THE THRESHOLD IS ANSWERED FIRST.
     *
     * A fare that was never worth mentioning is not a fare being suppressed for
     * its age. Reporting `stale-fare` for an ordinary price would send somebody
     * hunting a data problem behind a route that is simply not cheap.
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
     * ORDER: AND MATURITY BEFORE THAT. A route Orbit has watched for a day has
     * nothing to say about it whatever its fares look like.
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
     * ORDER: BUT BEFORE THE COOLDOWN, so the answer is a function of the
     * candidate alone. The same stale fare is held for the same reason whatever
     * the ledger says — which is what makes this rule checkable on paper, and
     * what stops a still-falling stale price from beating the cooldown and
     * going out anyway.
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
