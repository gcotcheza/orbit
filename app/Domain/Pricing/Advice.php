<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The route detail's callout: title, sentence and tone (design/README.md §2). Generated here,
 * from the same numbers as the score, so prose and gauge can never disagree.
 */
final readonly class Advice
{
    public function __construct(
        public string $title,
        public string $body,
        public string $tone,
    ) {}
}
