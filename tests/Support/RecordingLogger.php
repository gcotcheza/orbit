<?php

declare(strict_types=1);

namespace Tests\Support;

use Stringable;
use Psr\Log\AbstractLogger;

/**
 * A logger that remembers, for tests about what an adapter SAYS — not
 * `Log::spy()`, which cannot count (docs/BUSINESS-LOGIC.md §36).
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $lines = [];

    /**
     * @param  mixed  $level
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = [
            'level'   => is_string($level) ? $level : gettype($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function warnings(): array
    {
        return array_values(array_filter($this->lines, static fn (array $line): bool => $line['level'] === 'warning'));
    }
}
