<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;

/**
 * The numbers docs/PLAN.md locked, as a value the scorer can be handed.
 *
 * The scorer is pure PHP and never calls config(), so somebody has to carry
 * these across the boundary — that is this class, and App\Providers\
 * AppServiceProvider is the only thing that builds one from config/orbit.php.
 * Tests build their own, which is why the weights are tunable at all: a test
 * that wants to prove the trend component works can turn the other two off.
 */
final readonly class ScoringPolicy
{
    public const TIER_INSANE = 'insane';

    public const TIER_GREAT = 'great';

    public const TIER_GOOD = 'good';

    public const TIER_NONE = 'none';

    public function __construct(
        public float $percentileWeight = 60.0,
        public float $trendWeight = 25.0,
        public float $absoluteWeight = 15.0,
        public int $insaneAt = 80,
        public int $greatAt = 65,
        public int $goodAt = 50,
        public int $trendDays = 30,
        public float $trendSaturationPerDay = 0.005,
    ) {
        if ($percentileWeight < 0 || $trendWeight < 0 || $absoluteWeight < 0) {
            throw new InvalidArgumentException('Score weights cannot be negative.');
        }

        if ($percentileWeight + $trendWeight + $absoluteWeight <= 0) {
            throw new InvalidArgumentException('At least one score weight must be positive.');
        }

        if ($trendSaturationPerDay <= 0) {
            throw new InvalidArgumentException('Trend saturation must be a positive daily fraction.');
        }
    }

    /**
     * The alert tier a score falls in. PR11 fires on these; the API publishes
     * them so the UI can say WHY something was flagged.
     */
    public function tierFor(int $score): string
    {
        return match (true) {
            $score >= $this->insaneAt => self::TIER_INSANE,
            $score >= $this->greatAt => self::TIER_GREAT,
            $score >= $this->goodAt => self::TIER_GOOD,
            default => self::TIER_NONE,
        };
    }
}
