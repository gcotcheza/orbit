<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * T9's check is a fleet script the gate calls by its one canonical path, and it
 * exits 0 having read nothing when it finds no compose file (docs/STANDARDS.md T9).
 */
final class GateImageTagsStepTest extends TestCase
{
    private const CHECKER = '/srv/engineering-standards/scripts/gate-image-tags.sh';

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
            if (preg_match('/^\s*\w*(tag_check|image_tags)\w*=/i', $line) !== 1) {
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
            'gate-image-tags.sh prints "no compose file beside this root" and exits 0 when it is '
            .'handed a root with nothing to read. The step therefore has to assert on the line '
            .'the check prints when it has counted, not on the exit status.'
        );

        $this->assertStringContainsString(
            'exit 1',
            $guard[1],
            'A report with no built-tag count means the check examined nothing, and that is a red.'
        );
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
