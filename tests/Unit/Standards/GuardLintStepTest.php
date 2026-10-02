<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The gate's guard-diff lint step. The fleet's linter lives on the box and in no
 * image, so the script is run here against fakes standing in for it.
 * docs/DECISIONS.md, the-gate-lints-every-guards-diff-reads
 */
final class GuardLintStepTest extends TestCase
{
    use RunsGateScripts;

    private const LINTER = '/usr/local/sbin/fleet-lint-guard-diff';

    private const CALL = '"$here/scripts/guard-lint.sh"';

    #[Test]
    public function the_gate_runs_the_step_unconditionally_after_pint_and_before_the_host_tests(): void
    {
        $lines = explode("\n", $this->withoutComments($this->read('scripts/check.sh')));

        $this->assertSame(
            [self::CALL],
            array_values(array_filter($lines, fn (string $line): bool => str_contains($line, 'guard-lint.sh') && ! str_starts_with($line, 'step '))),
            'scripts/check.sh must run the guard-diff lint exactly once, unindented, so no runner '
            .'and no condition can skip it: nothing else reads the hooks for a diff a repository can blind.'
        );

        $at = array_search(self::CALL, $lines, true);
        $pint = array_search("step 'Pint (code style)'", $lines, true);
        $deploy = array_search("step 'The deploy script (scripts/deploy-test.sh)'", $lines, true);

        $this->assertIsInt($at);
        $this->assertIsInt($pint);
        $this->assertIsInt($deploy);
        $this->assertGreaterThan($pint, $at, 'CheckGitSeamTest stops the gate at Pint with no linter on PATH.');
        $this->assertLessThan($deploy, $at, 'The lint takes milliseconds; it runs ahead of the slower host tests (T3).');
    }

    #[Test]
    public function the_script_names_the_fleet_linter_by_its_literal_path(): void
    {
        $script = $this->read('scripts/guard-lint.sh');

        $this->assertSame(
            1,
            substr_count($script, 'LINT='.self::LINTER."\n"),
            'The linter is named by a literal path: a variable a caller could set would let a '
            .'run point the lint at /bin/true and call that clean.'
        );
    }

    #[Test]
    public function a_linter_that_reads_the_flags_passes_this_tree(): void
    {
        $result = $this->runStep($this->honestLinter());

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertStringContainsString('scripts/ is clean, and a copy of the hook with --no-ext-diff deleted is not.', $result['output']);
    }

    #[Test]
    public function a_linter_that_passes_everything_fails_the_step(): void
    {
        $result = $this->runStep("#!/bin/sh\nexit 0\n");

        $this->assertSame(
            1,
            $result['status'],
            'A linter that never goes red passed the copy of the hook with --no-ext-diff deleted, '
            ."and the step called scripts/ clean on its word.\n".$result['output']
        );
        $this->assertStringContainsString('the lint passed a copy of scripts/hooks/pre-commit with --no-ext-diff deleted', $result['output']);
    }

    #[Test]
    public function a_finding_in_the_tree_fails_the_step(): void
    {
        $result = $this->runStep($this->honestLinter(), "git diff --cached --no-textconv\n");

        $this->assertSame(1, $result['status'], $result['output']);
        $this->assertStringContainsString('scripts/hooks/blind:1: reads patch text without --no-ext-diff', $result['output']);
    }

    #[Test]
    public function a_missing_linter_fails_the_step(): void
    {
        $result = $this->runStep(null);

        $this->assertSame(1, $result['status'], $result['output']);
        $this->assertStringContainsString('A skipped lint is a silent pass.', $result['output']);
    }

    /** Flags a `diff --cached` line without --no-ext-diff, in a file or anywhere below a directory. */
    private function honestLinter(): string
    {
        return <<<'SH'
            #!/bin/sh
            found=$(grep -rnH 'diff --cached' "$@" | grep -v -- '--no-ext-diff')
            [ -z "$found" ] && exit 0
            printf '%s\n' "$found" | sed 's/^\([^:]*:[0-9]*\):.*/\1: reads patch text without --no-ext-diff/'
            exit 1
            SH;
    }

    /**
     * @return array{status: int, output: string}
     */
    private function runStep(?string $linter, ?string $blindGuard = null): array
    {
        $sandbox = sys_get_temp_dir().'/orbit-guard-lint-'.bin2hex(random_bytes(6));
        $fake = $sandbox.'/fleet-lint-guard-diff';

        $this->assertTrue(mkdir($sandbox.'/scripts/hooks', 0o700, true), "Could not create {$sandbox}");

        try {
            $script = str_replace(self::LINTER, $fake, $this->read('scripts/guard-lint.sh'), $swapped);
            $this->assertSame(1, $swapped, 'The linter path is no longer in scripts/guard-lint.sh exactly once.');

            file_put_contents($sandbox.'/scripts/guard-lint.sh', $script);
            file_put_contents($sandbox.'/scripts/hooks/pre-commit', $this->read('scripts/hooks/pre-commit'));

            if ($blindGuard !== null) {
                file_put_contents($sandbox.'/scripts/hooks/blind', $blindGuard);
            }

            if ($linter !== null) {
                file_put_contents($fake, $linter);
                chmod($fake, 0o700);
            }

            return $this->execute(
                ['bash', $sandbox.'/scripts/guard-lint.sh'],
                ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'],
                $sandbox
            );
        } finally {
            $this->remove($sandbox);
        }
    }

    private function withoutComments(string $script): string
    {
        return implode("\n", preg_grep('/^\s*#/', explode("\n", $script), PREG_GREP_INVERT) ?: []);
    }
}
