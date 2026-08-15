<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that remembers, for the tests that are about what an adapter SAYS.
 *
 * WHY NOT `Log::spy()`. Mockery's spy answers `shouldHaveReceived('warning')`
 * and stops there — it cannot say "exactly once across nine failed requests",
 * which is the assertion App\Infrastructure\Pricing\TravelpayoutsPriceProvider
 * actually needs, because the rate limit on its warning is a feature and not an
 * implementation detail. A list of what was said is a better fixture than a
 * mock's expectation grammar, and it reads as one.
 *
 * `AbstractLogger` supplies the eight level methods on top of `log()`, so this
 * is the one method that matters plus the array it fills.
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
            'level' => is_string($level) ? $level : gettype($level),
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
