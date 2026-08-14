<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The one-line judgement, in the two lengths the design asks for.
 *
 * `$label` is the sentence the spotlight card and the route detail show
 * ("Cheap & still falling"); `$short` is the single word the watchlist's status
 * pill has room for (Good / Falling / Normal / Wait — design/README.md §5).
 * BOTH ARE EMITTED BY THE API rather than the client deriving one from the
 * other, because the mapping is a product decision and four screens deriving
 * it independently is four places for it to drift.
 *
 * `$tone` is one of good | info | normal | warn and is the ONLY thing the
 * client is meant to switch colours on — it maps straight onto the token pairs
 * in resources/css/tokens.css.
 */
final readonly class Verdict
{
    public const TONE_GOOD = 'good';

    public const TONE_INFO = 'info';

    public const TONE_NORMAL = 'normal';

    public const TONE_WARN = 'warn';

    public function __construct(
        public string $label,
        public string $short,
        public string $tone,
    ) {}
}
