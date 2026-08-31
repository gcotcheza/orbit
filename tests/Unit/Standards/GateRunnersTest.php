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
        'deptrac',
        'phpstan',
        'npm advisories',
        'eslint',
        'vitest',
        'phpunit',
    ];

    /** Commands that mean a gate step has been restated outside the script. */
    private const RESTATED = [
        'vendor/bin/', 'gitleaks', 'phpstan', 'npm run ', 'npm audit', 'npm ci',
        'composer audit', 'artisan test',
    ];

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

        $this->assertMatchesRegularExpression(
            '/^\s*bash .*scripts\/check\.sh overlay\s*$/m',
            $commands,
            'The deploy runbook no longer runs the gate script. Pre-flight step 4 is the only '
            .'run the merge commit ever gets, and a mention in a comment is not a run.'
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

    #[Test]
    public function only_the_overlay_step_is_a_step_a_runner_can_skip(): void
    {
        $script = $this->read('scripts/check.sh');

        if (preg_match('/^if \[ "\$mode" = overlay \]; then$(.*?)^fi$/ms', $script, $branch) !== 1) {
            $this->fail('scripts/check.sh no longer has an overlay-only branch to read.');
        }

        preg_match_all("/step '([^']+)'/", $branch[1], $guarded);

        $this->assertSame(
            ['overlay'],
            array_map(
                static fn (string $label): string => strtolower(trim(explode('(', $label)[0])),
                $guarded[1]
            ),
            'The nine checks must run in both runners; only the overlay itself is conditional. '
            .'A check that moved inside this branch stopped running for the developer, or a '
            .'ninth step was added outside it and now runs twice in overlay mode.'
        );
        $this->assertCount(
            count(self::ORDER) - 1,
            array_diff($this->stepLabels(), ['overlay']),
            'dev runs the checks; overlay runs the checks plus its own setup step.'
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
