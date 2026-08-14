<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The route detail's callout: a title, a sentence, and the tone that colours
 * the tinted box behind them (design/README.md §2).
 *
 * It is generated HERE, from the same numbers that produced the score, so the
 * prose and the gauge can never disagree — a card reading "a clear bargain"
 * next to a 31 is the kind of thing that costs a user their trust in the whole
 * app.
 */
final readonly class Advice
{
    public function __construct(
        public string $title,
        public string $body,
        public string $tone,
    ) {}
}
