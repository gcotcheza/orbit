<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The gate is one script with two runners, and these are the three ways it
 * stopped being that before (docs/DECISIONS.md: the-gate-is-one-script-two-runners).
 */
final class GateRunnersTest extends TestCase
{
    private const ORDER = [
        'overlay',
        'gitleaks',
        'pint',
        'composer advisories',
        'phpstan',
        'npm advisories',
        'eslint',
        'vitest',
        'phpunit',
    ];

    /** Commands that mean a gate step has been restated outside the script. */
    private const RESTATED = ['vendor/bin/', 'zricethezav/', 'npm run ', 'composer audit', 'artisan test'];

    #[Test]
    public function the_steps_run_cheapest_first(): void
    {
        $this->assertSame(
            self::ORDER,
            $this->stepLabels(),
            'The gate reordered itself. Cheapest first is the rule (docs/STANDARDS.md T3): a '
            .'four-second style failure must not be found after a ninety-second analysis run.'
        );
    }

    #[Test]
    public function the_deploy_runbook_calls_the_gate_instead_of_restating_it(): void
    {
        $commands = $this->fencedCommands('.claude/commands/deploy.md');

        $this->assertStringContainsString(
            'scripts/check.sh overlay',
            $commands,
            'The deploy runbook no longer runs the gate script. Pre-flight step 4 is the only '
            .'run the merge commit ever gets.'
        );

        foreach (self::RESTATED as $restated) {
            $this->assertStringNotContainsString(
                $restated,
                $commands,
                "The deploy runbook runs '{$restated}' itself. That second copy of the gate is the "
                .'debt this script was written to end; add the step to scripts/check.sh instead.'
            );
        }
    }

    #[Test]
    public function the_node_steps_wait_for_a_finished_install_not_a_directory(): void
    {
        $script = $this->withoutComments($this->read('scripts/check.sh'));

        $this->assertStringNotContainsString(
            '-d node_modules',
            $script,
            'An empty node_modules/ satisfies a directory test, so npm ci is skipped and the step '
            .'lints nothing and exits 127.'
        );
        $this->assertStringContainsString(
            'node_modules/.package-lock.json',
            $script,
            'The node steps must guard on the marker npm writes when an install finishes.'
        );
    }

    /** @return list<string> */
    private function stepLabels(): array
    {
        preg_match_all("/^\s*step '([^']+)'/m", $this->read('scripts/check.sh'), $found);

        return array_map(
            static fn (string $label): string => strtolower(trim(explode('(', $label)[0])),
            $found[1]
        );
    }

    private function fencedCommands(string $relative): string
    {
        preg_match_all('/^[ \t]*```[a-z]*$(.*?)^[ \t]*```$/ms', $this->read($relative), $found);

        return implode("\n", $found[1]);
    }

    private function withoutComments(string $script): string
    {
        return implode("\n", preg_grep('/^\s*#/', explode("\n", $script), PREG_GREP_INVERT) ?: []);
    }

    private function read(string $relative): string
    {
        $path = __DIR__.'/../../../'.$relative;

        $this->assertFileExists($path, "{$relative} is missing.");

        $contents = file_get_contents($path);

        $this->assertIsString($contents, "{$relative} could not be read.");

        return $contents;
    }
}
