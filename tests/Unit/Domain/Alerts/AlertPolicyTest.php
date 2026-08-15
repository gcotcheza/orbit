<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Alerts;

use App\Domain\Alerts\AlertCandidate;
use App\Domain\Alerts\AlertDecision;
use App\Domain\Alerts\AlertPolicy;
use App\Domain\Alerts\LastAlert;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Whether to interrupt somebody — every branch of it.
 *
 * A PLAIN PHPUnit TestCase and not Laravel's: the policy imports no framework
 * and the clock arrives as an argument, so there is nothing here to boot. That
 * is the property these tests are protecting as much as the arithmetic — a
 * later change that reached for `now()` inside the policy would fail here
 * before it failed at six in the morning.
 *
 * THE NUMBERS ARE THE SHIPPED ONES: 24 hours and 5%, from config/orbit.php via
 * App\Providers\AppServiceProvider. They are passed explicitly so a reader can
 * check a boundary on paper.
 */
final class AlertPolicyTest extends TestCase
{
    private const COOLDOWN_HOURS = 24;

    private const DROP_PERCENT = 5;

    private const INSANE = 80;

    private function policy(): AlertPolicy
    {
        return new AlertPolicy(self::COOLDOWN_HOURS, self::DROP_PERCENT);
    }

    private function at(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time);
    }

    // -- The threshold -------------------------------------------------------

    #[Test]
    public function a_watched_route_at_exactly_the_sensitivity_fires(): void
    {
        $decision = $this->policy()->decide(
            AlertCandidate::watchedRoute(self::INSANE, 4400),
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
            AlertCandidate::watchedRoute(self::INSANE - 1, 4400),
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
            AlertCandidate::watchedRoute($score, 6000),
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
            'Relaxed (80) holds a 72' => [80, 72, AlertDecision::BelowThreshold],
            'Balanced (65) fires on it' => [65, 72, AlertDecision::Fired],
            'Eager (50) fires on it' => [50, 72, AlertDecision::Fired],
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
            AlertCandidate::watchedRoute(40, 4400),
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
            AlertCandidate::watchedRoute(94, 4400),
            self::INSANE,
            null,
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function the_same_price_inside_the_cooldown_is_held(): void
    {
        $decision = $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400),
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
            AlertCandidate::watchedRoute(94, 4400),
            self::INSANE,
            new LastAlert($this->at('2026-08-14 06:55:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function one_second_short_of_a_day_it_is_not(): void
    {
        $this->assertSame(AlertDecision::CoolingDown, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 4400),
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
            AlertCandidate::watchedRoute(94, 4180),
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
            AlertCandidate::watchedRoute(94, 4181),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_fare_that_has_gone_up_inside_the_cooldown_says_nothing(): void
    {
        $this->assertSame(AlertDecision::CoolingDown, $this->policy()->decide(
            AlertCandidate::watchedRoute(94, 5200),
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
            AlertCandidate::watchedRoute(40, 2000),
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
        $policy = new AlertPolicy(0, self::DROP_PERCENT);

        $this->assertSame(AlertDecision::Fired, $policy->decide(
            AlertCandidate::watchedRoute(94, 4400),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 06:54:59'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_zero_drop_makes_any_fall_at_all_worth_saying(): void
    {
        $policy = new AlertPolicy(self::COOLDOWN_HOURS, 0);

        $this->assertSame(AlertDecision::SupersededByDrop, $policy->decide(
            AlertCandidate::watchedRoute(94, 4400),
            self::INSANE,
            new LastAlert($this->at('2026-08-15 00:00:00'), 4400),
            $this->at('2026-08-15 06:55:00'),
        ));
    }

    #[Test]
    public function a_negative_cooldown_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlertPolicy(-1, self::DROP_PERCENT);
    }

    #[Test]
    #[DataProvider('impossiblePercentages')]
    public function a_drop_that_is_not_a_percentage_is_refused(int $percent): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AlertPolicy(self::COOLDOWN_HOURS, $percent);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function impossiblePercentages(): array
    {
        return [
            'negative' => [-1],
            'over a hundred' => [101],
        ];
    }

    /**
     * The truth table the whole pipeline branches on, in one place.
     */
    #[Test]
    #[DataProvider('decisions')]
    public function only_two_of_the_four_decisions_send_anything(AlertDecision $decision, bool $fires): void
    {
        $this->assertSame($fires, $decision->fires());
    }

    /**
     * @return array<string, array{AlertDecision, bool}>
     */
    public static function decisions(): array
    {
        return [
            'fired' => [AlertDecision::Fired, true],
            'superseded by a drop' => [AlertDecision::SupersededByDrop, true],
            'below the threshold' => [AlertDecision::BelowThreshold, false],
            'cooling down' => [AlertDecision::CoolingDown, false],
        ];
    }
}
