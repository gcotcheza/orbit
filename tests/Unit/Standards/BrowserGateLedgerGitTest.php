<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * scripts/e2e.sh runs as root over a tree only uid 115 may own, where root's git refuses
 * to name HEAD; the ledger must read it through git-as, set by the script itself.
 */
final class BrowserGateLedgerGitTest extends TestCase
{
    use RunsGateScripts;

    private const GATE = 'scripts/e2e.sh';

    private const SEAM = 'GATE_LEDGER_GIT="git-as orbit -C ${ROOT}"';

    #[Test]
    public function the_browser_gate_names_its_head_as_the_trees_owner_before_arming_the_ledger(): void
    {
        $lines = [];

        foreach (explode("\n", $this->read(self::GATE)) as $index => $line) {
            $lines[$index] = trim((string) preg_replace('/(^|\s)#.*$/', '', $line));
        }

        $assignments = array_keys(array_filter($lines, static fn (string $code): bool => preg_match('/^(export\s+)?GATE_LEDGER_GIT=/', $code) === 1));
        $root = array_search('ROOT="$PWD"', $lines, true);
        $arm = array_search('gate_ledger_arm', $lines, true);

        $this->assertCount(1, $assignments, self::GATE.' must set GATE_LEDGER_GIT exactly once, or the ledger reads HEAD with root\'s git.');
        $this->assertSame(self::SEAM, $lines[$assignments[0]]);
        $this->assertIsInt($root, self::GATE.' no longer pins ROOT="$PWD".');
        $this->assertIsInt($arm, self::GATE.' no longer calls gate_ledger_arm.');
        $this->assertGreaterThan($root, $assignments[0], 'GATE_LEDGER_GIT is set before ROOT exists.');
        $this->assertLessThan($arm, $assignments[0], 'gate_ledger_arm runs before GATE_LEDGER_GIT is set, so it arms with root\'s git.');
    }
}
