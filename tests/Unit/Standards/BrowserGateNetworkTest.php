<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs scripts/e2e-network-test.sh from the suite, so the gate's PHPUnit step is what
 * keeps the browser off the host network (docs/DECISIONS.md, the-browser-gate-runs-on-the-sandbox-bridge).
 */
final class BrowserGateNetworkTest extends TestCase
{
    use RunsGateScripts;

    private const HARNESS = 'scripts/e2e-network-test.sh';

    private const GATE = 'scripts/e2e.sh';

    #[Test]
    public function the_browser_gate_keeps_its_browser_on_the_sandbox_network(): void
    {
        $result = $this->execute(['bash', $this->root().'/'.self::HARNESS], [], $this->root());

        $this->assertSame(0, $result['status'], self::HARNESS." refused the browser gate:\n".$result['output']);
        $this->assertStringContainsString('e2e-network-test: all checks passed', $result['output']);
    }

    /** @return array<string, array{string, string, string}> */
    public static function mutants(): array
    {
        return [
            'host networking back' => [
                '    --network "$E2E_NETWORK" \\',
                '    --network host \\',
                'nothing in the gate puts the browser on the host network',
            ],
            'the one-network refusal deleted' => [
                '[ "$(printf \'%s\' "$E2E_NETWORK" | grep -c .)" -eq 1 ] \\',
                '',
                'anything but exactly one network refuses',
            ],
        ];
    }

    #[Test]
    #[DataProvider('mutants')]
    public function the_harness_refuses_a_gate_that_lost_its_network_guard(string $line, string $mutant, string $check): void
    {
        $gate = $this->read(self::GATE);

        $this->assertSame(1, substr_count($gate, $line."\n"), self::GATE." no longer carries the line this mutant replaces:\n{$line}");

        $copy = tempnam(sys_get_temp_dir(), 'e2e-sh-');
        $this->assertIsString($copy);

        try {
            file_put_contents($copy, str_replace($line."\n", $mutant."\n", $gate));
            $result = $this->execute(['bash', $this->root().'/'.self::HARNESS], ['E2E_SH' => $copy], $this->root());
        } finally {
            unlink($copy);
        }

        $this->assertSame(1, $result['status'], self::HARNESS." passed a gate it exists to refuse:\n".$result['output']);
        $this->assertStringContainsString('FAIL '.$check, $result['output']);
    }
}
