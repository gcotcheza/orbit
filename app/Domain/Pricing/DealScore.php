<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Everything the scorer concluded. `$confident` is the flag that keeps the app honest: false
 * makes the 0 mean "no opinion" rather than "terrible deal" (docs/BUSINESS-LOGIC.md §7).
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
