<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use DateTimeImmutable;

/**
 * The last thing Orbit said about this route, as far as the cooldown is
 * concerned: when it said it, and at what price.
 *
 * WHEN IT WAS TRIGGERED, NOT WHEN IT WAS DELIVERED. Quiet hours can hold a mail
 * back until eight in the morning, and if the cooldown ran from the delivery
 * the window would silently stretch by however long somebody was asleep. The
 * decision is what was made once; the delivery is a scheduling detail of it.
 *
 * THE PRICE IS HERE BECAUSE THE COOLDOWN IS NOT ABSOLUTE. A route announced at
 * €44 that is €38 the next morning is a different piece of news, and the only
 * way to know that is to remember what was announced.
 */
final readonly class LastAlert
{
    public function __construct(
        public DateTimeImmutable $triggeredAt,
        public int $priceCents,
    ) {}
}
