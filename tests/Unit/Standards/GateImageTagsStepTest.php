<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * This file reads scripts/check.sh; scripts/check-test.sh runs the step, in both runners.
 * docs/DECISIONS.md, the-image-tag-step-is-proved-by-running-it
 */
final class GateImageTagsStepTest extends TestCase
{
    use RunsGateScripts;

    private const CHECKER = '/srv/engineering-standards/scripts/gate-image-tags.sh';

    private const STEP = 'Image tags (T9)';

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
