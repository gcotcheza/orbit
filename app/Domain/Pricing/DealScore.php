<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Everything the scorer concluded, in one value.
 *
 * `$confident` is the flag that keeps the app honest: it is false when the
 * route had neither statistics nor enough history to judge, and the score is
 * then a 0 that means "no opinion" rather than "terrible deal". The API passes
 * it through so a screen can show the design's "tracking N days" note instead
 * of a gauge that looks like a damning verdict on a route we started watching
 * yesterday.
 */
final readonly class DealScore
{
    public function __construct(
        public int $score,
        public string $tier,
        public Verdict $verdict,
        public Advice $advice,
        public bool $confident,
    ) {}
}
