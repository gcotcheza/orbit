<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use DateTimeImmutable;

/**
 * Whether a fare has probably gone — old AND well under usual, both halves required, and a
 * null `foundAt` is never demoted (docs/BUSINESS-LOGIC.md §17).
 */
final class MayBeGone
{
    public static function decide(
        int $cents,
        ?DateTimeImmutable $foundAt,
        ?PriceStats $usual,
        DateTimeImmutable $now,
        int $staleAfterHours,
        int $underUsualPercent,
    ): bool {
        if ($foundAt === null || $usual === null) {
            return false;
        }

        $ageHours = ($now->getTimestamp() - $foundAt->getTimestamp()) / 3600;

        if ($ageHours <= $staleAfterHours) {
            return false;
        }

        return $usual->percentUnderUsual($cents) >= $underUsualPercent;
    }
}
