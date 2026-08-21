<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * The one-line judgement in the two lengths the design asks for; both are emitted by the API
 * rather than derived client-side, and `$tone` is the only thing colours switch on.
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
