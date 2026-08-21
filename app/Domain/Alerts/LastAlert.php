<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use DateTimeImmutable;

/**
 * The last thing Orbit said about this route: WHEN IT WAS TRIGGERED, not delivered, and at
 * what price — the cooldown is not absolute (docs/BUSINESS-LOGIC.md §10).
 */
final readonly class LastAlert
{
    public function __construct(
        public DateTimeImmutable $triggeredAt,
        public int $priceCents,
    ) {}
}
