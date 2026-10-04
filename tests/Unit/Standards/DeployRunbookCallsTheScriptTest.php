<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The runbook was 804 lines of hand-run shell and every deploy re-typed it.
 * docs/DECISIONS.md: the-deploy-script-is-the-runbook.
 */
final class DeployRunbookCallsTheScriptTest extends TestCase
{
    private const RUNBOOK = '.claude/commands/deploy.md';

    /** A deploy step, wherever it is written. The runbook may name these; it may not run them. */
    private const STEPS = ['docker compose', 'php artisan', 'composer install', 'composer audit'];

    /** Sections whose commands a person runs deliberately, by hand, and never unattended. */
    private const BY_HAND = ['## Authenticated writes', '## Rollback'];

    #[Test]
    public function the_runbook_runs_the_deploy_script(): void
    {
        $fenced = implode("\n", $this->fences($this->read(self::RUNBOOK)));

        $this->assertMatchesRegularExpression(
            '#^fleet-deploy orbit <PR\#>$#m',
            $fenced,
            'The runbook stopped calling scripts/deploy.sh through fleet-deploy. That script IS the '
            .'runbook: a deploy typed out again here is a second copy of it, and the copies drifted '
            .'for four months the last time this was two documents.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '#(^|[\s/])scripts/deploy\.sh\b#m',
            $fenced,
            "A fenced block runs scripts/deploy.sh directly. Root runs it only out of fleet-deploy's "
            .'export, and every other copy refuses: docs/DECISIONS.md, deploys-run-roots-export-never-the-checkout.'
        );
    }

    #[Test]
    public function the_runbook_runs_the_battery_only_out_of_roots_mirror(): void
    {
        $fenced = implode("\n", $this->fences($this->read(self::RUNBOOK)));

        $this->assertStringContainsString(
            'git --git-dir=/var/lib/fleet/deploy-src/orbit.git show main:scripts/verify.sh >"${VDIR:?}/verify.sh" || exit',
            $fenced,
            "The runbook lost the block that copies verify.sh out of root's mirror before root runs it."
        );
        $this->assertStringContainsString('bash "${VDIR:?}/verify.sh"', $fenced);
        $this->assertDoesNotMatchRegularExpression(
            '#(^|[\s/])scripts/verify\.sh\b#m',
            $fenced,
            'A fenced block has root run verify.sh out of a tree the orbit user can write: '
            .'docs/DECISIONS.md, deploys-run-roots-export-never-the-checkout.'
        );
    }

    #[Test]
    public function the_runbook_does_not_restate_a_single_deploy_step(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->fences($this->unattended()) as $fence) {
            foreach (explode("\n", $fence) as $line) {
                if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                    continue;
                }

                $scanned++;

                foreach (self::STEPS as $step) {
                    if (str_contains($line, $step)) {
                        $offenders[] = trim($line);
                    }
                }
            }
        }

        $this->assertGreaterThan(0, $scanned, 'No fenced command was scanned; the runbook lost its blocks.');
        $this->assertSame(
            [],
            $offenders,
            "A deploy step is written out here again, so there are two procedures and the gate only\n"
            ."holds one of them. Add it to scripts/deploy.sh, where scripts/deploy-test.sh reads it:\n"
            .implode("\n", $offenders)
        );
    }

    /** The runbook minus the sections a person works through by hand. */
    private function unattended(): string
    {
        $runbook = $this->read(self::RUNBOOK);
        $cut = strlen($runbook);

        foreach (self::BY_HAND as $heading) {
            $at = strpos($runbook, "\n".$heading);

            $this->assertIsInt($at, "The runbook has no '{$heading}' section; it is not optional.");

            $cut = min($cut, $at);
        }

        return substr($runbook, 0, $cut);
    }

    /** @return list<string> */
    private function fences(string $markdown): array
    {
        preg_match_all('/^[ \t]*```[a-z]*$(.*?)^[ \t]*```$/ms', $markdown, $found);

        return $found[1];
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
