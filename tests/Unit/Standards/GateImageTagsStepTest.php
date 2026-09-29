<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Half of this file reads scripts/check.sh and half runs it, in both runners.
 * docs/DECISIONS.md, the-image-tag-step-is-proved-by-running-it
 */
final class GateImageTagsStepTest extends TestCase
{
    use RunsGateScripts;

    private const CHECKER = '/srv/engineering-standards/scripts/gate-image-tags.sh';

    private const STEP = 'Image tags (T9)';

    /** The banner that may not appear once the step has refused. */
    private const NEXT_STEP = 'Deploy mutants (scripts/deploy-mutants.sh)';

    /** The host steps around this one, stubbed so a run is milliseconds. */
    private const HOST_STEPS = ['deploy-test.sh', 'verify-test.sh', 'worktree-test.sh', 'deploy-mutants.sh'];

    /** Neutralised in the copy: both reach outside the throwaway root. */
    private const CLEARS_THE_BOX = 'rm -rf /var/tmp/orbit-gate.*';

    private const HANDS_OVER = 'chown -R 115:119';

    private const OK_REPORT = <<<'REPORT'
        gate-image-tags: %s
          gate files:       docker-compose.ci.yml, docker-compose.e2e.yml
          production files: docker-compose.yml
          images:           14 resolved, 0 unresolved
          built tags:       2
        ok %s: 2 built image tag(s), none shared between the gate and production
        REPORT;

    private const REFUSAL_REPORT = <<<'REPORT'
        gate-image-tags: %s
        refused %s: orbit/app:latest is built by the gate and run in production
        REPORT;

    /** gate-image-tags.sh:41-42 — this one is stderr and rc 2, not a report at all. */
    private const NO_COMPOSE_REPORT = <<<'REPORT'
        gate-image-tags: %s
        gate-image-tags: no compose file beside this root — nothing was examined, so %s is refused, not passed (T9)
        REPORT;

    #[Test]
    public function the_step_runs_the_canonical_script_by_its_literal_path(): void
    {
        $script = $this->withoutComments($this->read('scripts/check.sh'));

        $this->assertSame(
            1,
            preg_match_all('/^tag_check='.preg_quote(self::CHECKER, '/').'$/m', $script),
            'The image-tag step must name the canonical clone once and plainly. A copy vendored '
            .'into this repository would be a second standard to keep in step with the first.'
        );

        $this->assertMatchesRegularExpression(
            '/^if \[ ! -x "\$tag_check" \]; then$/m',
            $script,
            'A missing canonical clone has to stop the gate. A check that cannot run and says '
            .'nothing is a silent pass, which is the failure T9 exists to catch.'
        );
    }

    #[Test]
    public function nothing_lets_the_step_be_pointed_somewhere_else(): void
    {
        $script = $this->withoutComments($this->read('scripts/check.sh'));

        $overrides = [];

        foreach (explode("\n", $script) as $line) {
            if (preg_match('/(^|[\s;&|])\w*(tag_check|image_tags)\w*=/i', $line) !== 1) {
                continue;
            }

            if (trim($line) !== 'tag_check='.self::CHECKER) {
                $overrides[] = trim($line);
            }
        }

        $this->assertSame(
            [],
            $overrides,
            'An environment override is a skip switch: one variable and the step passes without '
            .'reading a compose file. Both runners run this script on the host, where the clone is '
            ."always there, so there is nothing for a seam to serve:\n".implode("\n", $overrides)
        );
    }

    #[Test]
    public function the_step_belongs_to_neither_runner_and_sits_under_no_condition(): void
    {
        $script = $this->read('scripts/check.sh');

        $this->assertDoesNotMatchRegularExpression(
            '/\bmode\b/',
            $this->withoutComments($this->stepSlice($script)),
            'The step reads the runner. `if [ "$mode" = dev ]; then … fi` around it is a step the '
            .'deploy never runs — it gates with `overlay` — and it is invisible to a harness that '
            .'only drives one runner. T9 is about the compose files, which both runners share.'
        );

        $nesting = $this->nesting($script);

        $this->assertSame(
            0,
            $nesting['total'],
            'This scanner no longer balances scripts/check.sh to zero, so it has stopped '
            .'understanding the file and its verdict below means nothing. Fix the scanner.'
        );

        $this->assertSame(
            0,
            $nesting['atStep'],
            'The step sits inside a block opened earlier in the script. An `if`, a `case` or a '
            .'subshell wrapped around it is how the whole step stops running with every assertion '
            .'about its text still green.'
        );
    }

    #[Test]
    public function the_step_fails_unless_the_check_says_what_it_counted(): void
    {
        $script = $this->withoutComments($this->read('scripts/check.sh'));

        $this->assertMatchesRegularExpression(
            '/^if ! tag_report=\$\("\$tag_check" "\$here" 2>&1\); then$/m',
            $script,
            'The step must capture the report and stop on a non-zero status, rather than let a '
            .'shared tag print itself into a log nobody reads.'
        );

        if (preg_match('/^case "\$tag_report" in$(.*?)^esac$/ms', $script, $guard) !== 1) {
            $this->fail('The image-tag step no longer reads what the check printed.');
        }

        $this->assertStringContainsString(
            "*'built tags:'*",
            $guard[1],
            'The step asserts on what the check printed, not on its status: gate-image-tags.sh '
            .'exits 0 over a report that counted nothing worth counting. A report with no '
            .'"built tags:" line at all is output this step can no longer read, and that is a red.'
        );

        $this->assertStringContainsString(
            'exit 1',
            $guard[1],
            'A report with no built-tag count means the check examined nothing, and that is a red.'
        );
    }

    #[Test]
    public function the_step_fails_unless_a_built_tag_was_counted(): void
    {
        $script = $this->withoutComments($this->read('scripts/check.sh'));

        $this->assertStringContainsString(
            'built tags: *\([0-9][0-9]*\)',
            $script,
            'The step must read the number on the "built tags:" line. That line is printed even '
            .'when the count is zero, so asserting the words appear says only that the report '
            .'reached its summary — which it always does.'
        );

        if (preg_match('/^if ! printf [^\n]*\$built[^\n]*\n(.*?)^fi$/ms', $script, $guard) !== 1) {
            $this->fail(
                'Nothing refuses a built-tag count of zero. This repository builds an app image '
                .'on both the gate and the production side, so a zero means the check read some '
                .'other tree, and its exit code then says nothing about T9.'
            );
        }

        $this->assertStringContainsString(
            'exit 1',
            $guard[1],
            'A count of zero, or one this step cannot read, has to stop the gate.'
        );

        $this->assertStringContainsString(
            "grep -qE '^[0-9]+\$'",
            $guard[0],
            'The count is proved to be a number before it is compared, because an empty capture '
            .'means the line is gone and `[ "" -eq 0 ]` errors where a refusal is wanted.'
        );
    }

    #[Test]
    public function the_step_fails_unless_every_image_value_was_judged(): void
    {
        $script = $this->withoutComments($this->read('scripts/check.sh'));

        $this->assertStringContainsString(
            'resolved, *\([0-9][0-9]*\) unresolved',
            $script,
            'The step must read the "images: N resolved, M unresolved" count line. The check '
            .'appends "unresolved and not judged" only to an ok line that counted a built tag, so '
            .'a step pinned to that phrase alone reads nothing when no built tag was counted.'
        );

        if (preg_match('/^if \[ "\$unresolved" != 0 \]; then$(.*?)^fi$/ms', $script, $guard) !== 1) {
            $this->fail(
                'Nothing refuses an unresolved image value. gate-image-tags.sh prints how many '
                .'values it could not resolve and still exits 0, so an unjudged value — exactly '
                .'where a gate tag aimed at production would sit — passes this gate unseen.'
            );
        }

        $this->assertStringContainsString(
            'exit 1',
            $guard[1],
            'A value the check could not judge has to stop the gate, not print itself into a log.'
        );

        $this->assertStringNotContainsString(
            '-eq',
            $guard[0],
            'The comparison is a string one on purpose: an empty capture means the count line is '
            .'gone, and `[ "" -eq 0 ]` errors where `!= 0` refuses.'
        );
    }

    #[Test]
    #[DataProvider('bothRunners')]
    public function a_run_hands_this_checkout_to_the_check_and_prints_what_came_back(string $runner): void
    {
        $run = $this->runGate(self::OK_REPORT, 0, mode: $runner);

        $this->assertSame(
            [$run['root']],
            $run['checker'],
            'The check was not run, or was not run over this checkout. Every assertion above about '
            .'the text of the step is equally true of a step wrapped in `if false`, so this is the '
            ."one that says it executed — and it says so for the runner the deploy uses too:\n".$run['output']
        );

        $this->assertSame(
            0,
            $run['status'],
            "A check that counted a built tag and left nothing unjudged must let the gate on.\n".$run['output']
        );

        $this->assertStringContainsString(
            'built tags:       2',
            $run['output'],
            'The report goes to the operator on the way past. A step that reads it silently leaves '
            .'nobody the file and line on the day it is not a pass.'
        );
    }

    /** @return array<string, array{string}> */
    public static function bothRunners(): array
    {
        return ['the dev runner' => ['dev'], 'the overlay runner the deploy gates with' => ['overlay']];
    }

    #[Test]
    #[DataProvider('refusalsThatStopTheGate')]
    public function a_check_that_refuses_stops_the_gate_where_it_stands(string $report, int $exit, bool $onStderr, string $reason): void
    {
        $run = $this->runGate($report, $exit, onStderr: $onStderr);

        $this->assertNotSame(
            0,
            $run['status'],
            "A refused image-tag check has to fail the gate.\n".$run['output']
        );

        $this->assertStringContainsString(
            self::STEP,
            $run['output'],
            "The run never reached the step at all.\n".$run['output']
        );

        $this->assertStringNotContainsString(
            self::NEXT_STEP,
            $run['output'],
            'The gate carried on past a refusal, which is what `( … ) || true` around this step '
            ."buys and what a status nobody reads costs.\n".$run['output']
        );

        $this->assertStringContainsString(
            $reason,
            $run['output'],
            'What the check refused over is the whole reason to stop, and it is captured with '
            ."2>&1 so that a refusal it wrote to stderr reaches the log too.\n".$run['output']
        );
    }

    /** @return array<string, array{string, int, bool, string}> */
    public static function refusalsThatStopTheGate(): array
    {
        return [
            'a tag the gate builds and production runs' => [
                self::REFUSAL_REPORT,
                1,
                false,
                'is built by the gate and run in production',
            ],
            'a root with no compose file beside it' => [
                self::NO_COMPOSE_REPORT,
                2,
                true,
                'no compose file beside this root',
            ],
        ];
    }

    #[Test]
    #[DataProvider('reportsThatExaminedNothing')]
    public function a_check_that_examined_nothing_stops_the_gate_where_it_stands(string $report, string $refusal): void
    {
        $run = $this->runGate($report, 0);

        $this->assertNotSame(
            0,
            $run['status'],
            "gate-image-tags.sh exits 0 over a report like this, so its status alone is not the gate.\n".$run['output']
        );

        $this->assertStringContainsString(
            $refusal,
            $run['output'],
            "The refusal has to name what was read, or a silent pass reads like a pass.\n".$run['output']
        );

        $this->assertStringNotContainsString(
            self::NEXT_STEP,
            $run['output'],
            "A step that examined nothing may not hand the gate on.\n".$run['output']
        );
    }

    /** @return array<string, array{string, string}> */
    public static function reportsThatExaminedNothing(): array
    {
        return [
            'a summary that counted no built tag' => [
                str_replace(['built tags:       2', 'ok %s: 2 built'], ['built tags:       0', 'ok %s: 0 built'], self::OK_REPORT),
                'counted 0 built image tag(s) in',
            ],
            'an image value it could not judge' => [
                str_replace('14 resolved, 0 unresolved', '13 resolved, 1 unresolved', self::OK_REPORT),
                'left 1 image value(s) unjudged in',
            ],
        ];
    }

    /**
     * The real scripts/check.sh against a throwaway root, with the paths it must not reach
     * swapped out and counted. docs/DECISIONS.md, the-image-tag-step-is-proved-by-running-it
     *
     * @return array{status: int, output: string, checker: list<string>, root: string}
     */
    private function runGate(string $report, int $exit, bool $onStderr = false, string $mode = 'dev'): array
    {
        $sandbox = sys_get_temp_dir().'/orbit-image-tags-'.bin2hex(random_bytes(6));
        $bin = $sandbox.'/bin';
        $root = $sandbox.'/root';

        $this->assertTrue(mkdir($bin, 0o700, true), "Could not create {$bin}");
        $this->assertTrue(mkdir($root.'/scripts/lib/deploy', 0o700, true), "Could not create {$root}");

        try {
            $here = (string) realpath($root);
            $checker = $bin.'/gate-image-tags';

            $this->writeFakeGit($bin.'/git');
            $this->writeFakeDocker($bin.'/docker');
            $this->writeFakeChecker($checker, $bin.'/checker.log', sprintf($report, $here, $here), $exit, $onStderr);

            $script = $this->read('scripts/check.sh');
            $script = $this->swap(
                $script,
                self::CHECKER,
                $checker,
                1,
                'The canonical checker path is no longer in scripts/check.sh exactly once, so this '
                .'harness is not running the step it says it is running.'
            );
            $script = $this->swap(
                $script,
                self::CLEARS_THE_BOX,
                ':',
                1,
                "The overlay runner's first act clears every /var/tmp/orbit-gate.* on this box, a "
                ."real gate's included. It is neutralised here by name, so it has to be there by "
                .'that name.'
            );
            $script = $this->swap(
                $script,
                self::HANDS_OVER,
                ':',
                2,
                'The overlay runner hands its overlay and this checkout\'s storage/ to the app '
                .'user. Both are neutralised here; a third one would run for real, as whatever '
                .'user the suite happens to be.'
            );

            file_put_contents($root.'/scripts/check.sh', $script);
            file_put_contents($root.'/scripts/lib/deploy/ledger.sh', $this->read('scripts/lib/deploy/ledger.sh'));
            file_put_contents($root.'/composer.json', "{}\n");

            foreach (self::HOST_STEPS as $step) {
                file_put_contents($root.'/scripts/'.$step, "#!/bin/sh\nexit 0\n");
                chmod($root.'/scripts/'.$step, 0o700);
            }

            $result = $this->execute(
                ['bash', $root.'/scripts/check.sh', $mode],
                [
                    'PATH'        => $bin.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
                    'CI_GIT'      => $bin.'/git',
                    'GATE_LEDGER' => $sandbox.'/ledger',
                ],
                $root
            );

            $calls = $this->readLog($bin.'/checker.log');
        } finally {
            $this->remove($sandbox);
        }

        return [
            'status'  => $result['status'],
            'output'  => $result['output'],
            'checker' => $calls,
            'root'    => $here,
        ];
    }

    private function swap(string $script, string $find, string $replace, int $expected, string $why): string
    {
        $swapped = str_replace($find, $replace, $script, $count);

        $this->assertSame($expected, $count, $why);

        return $swapped;
    }

    /** A git that lists the two files the steps ahead of this one lint and copy. */
    private function writeFakeGit(string $path): void
    {
        $script = <<<'SH'
            #!/bin/sh
            case "$*" in
                *'-- scripts') printf 'scripts/check.sh\0' ;;
                *ls-files*)    printf 'composer.json\0scripts/check.sh\0' ;;
                *rev-parse*)   printf '%s\n' 0000000000000000000000000000000000000000 ;;
                *status*)      : ;;
                *)
                    printf 'FAKE GIT: unexpected subcommand %s\n' "$*" >&2
                    exit 98
                    ;;
            esac
            SH;

        file_put_contents($path, $script);
        chmod($path, 0o700);
    }

    /** A docker that answers every call: the step under test is a host step. */
    private function writeFakeDocker(string $path): void
    {
        file_put_contents($path, "#!/bin/sh\nexit 0\n");
        chmod($path, 0o700);
    }

    /** The stand-in check: it records the root it was handed, then answers as asked. */
    private function writeFakeChecker(string $path, string $log, string $report, int $exit, bool $onStderr): void
    {
        $stream = $onStderr ? ' >&2' : '';

        $script = <<<SH
            #!/bin/sh
            printf '%s\\n' "\$*" >> '{$log}'
            cat <<'REPORT'{$stream}
            {$report}
            REPORT
            exit {$exit}
            SH;

        file_put_contents($path, $script);
        chmod($path, 0o700);
    }

    /** The step's own lines: from its banner to the next one. */
    private function stepSlice(string $script): string
    {
        $lines = explode("\n", $script);
        $slice = [];

        foreach ($lines as $line) {
            if ($slice !== [] && preg_match("/^step '/", $line) === 1) {
                return implode("\n", $slice);
            }

            if ($slice !== [] || str_contains($line, "step '".self::STEP."'")) {
                $slice[] = $line;
            }
        }

        $this->assertNotSame([], $slice, "scripts/check.sh has no step '".self::STEP."' any more.");

        return implode("\n", $slice);
    }

    /**
     * Block depth at the step's banner, and `total` as the scanner's own self-check.
     * docs/DECISIONS.md, the-image-tag-step-is-proved-by-running-it
     *
     * @return array{total: int, atStep: int|null}
     */
    private function nesting(string $script): array
    {
        $depth = 0;
        $atStep = null;

        foreach (explode("\n", $script) as $line) {
            $code = (string) preg_replace('/(^|\s)#.*$/', '', $line);

            if (trim($code) === '') {
                continue;
            }

            if (str_contains($code, "step '".self::STEP."'")) {
                $atStep = $depth;
            }

            $bare = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', ' ', $code);
            $trimmed = trim($bare);

            $open = preg_match_all('/(?:^|[\s;&|(])(?:if|case)\b/', $bare)
                + preg_match_all('/(?:^|[\s;])do(?:$|[\s;])/', $bare)
                + (int) (preg_match('/^\S.*\(\)\s*\{$/', $trimmed) === 1)
                + (int) ($trimmed === '{' || $trimmed === '(');

            $close = preg_match_all('/(?:^|[\s;&|])(?:fi|esac)(?:$|[\s;&|)])/', $bare)
                + preg_match_all('/(?:^|[\s;])done(?:$|[\s;<&|)])/', $bare)
                + (int) (preg_match('/^[})]/', $trimmed) === 1);

            $depth += $open - $close;
        }

        return ['total' => $depth, 'atStep' => $atStep];
    }

    private function withoutComments(string $script): string
    {
        return implode("\n", preg_grep('/^\s*#/', explode("\n", $script), PREG_GREP_INVERT) ?: []);
    }
}
