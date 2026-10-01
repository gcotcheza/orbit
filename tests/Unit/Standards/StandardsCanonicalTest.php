<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs scripts/standards-drift.sh for real against a throwaway canonical clone;
 * the real one is on this box and inside no container (docs/DECISIONS.md).
 */
final class StandardsCanonicalTest extends TestCase
{
    use RunsGateScripts;

    private const SCRIPT = 'scripts/standards-drift.sh';

    private const CANONICAL = '/srv/engineering-standards';

    private const STANDARD = "# Engineering standards — all projects\n\nC1. Extract on the third copy.\n";

    #[Test]
    public function the_gate_runs_it_on_the_host_as_a_step_of_its_own(): void
    {
        $gate = $this->read('scripts/check.sh');

        $this->assertStringContainsString(
            "step 'Standards version (the canonical clone)'",
            $gate,
            'The gate no longer names this step, so nothing in a run says whether the vendored '
            .'standard is still the standard.'
        );
        $this->assertStringContainsString(
            '"$here/scripts/standards-drift.sh" "$here"',
            $gate,
            'The step must run the script over THIS checkout: handed no argument it would judge '
            .'the tree the script itself sits in, which is the same tree only by luck.'
        );
    }

    #[Test]
    public function a_copy_of_the_canonical_file_at_the_canonical_version_passes(): void
    {
        $run = $this->runScript('2026-10-01', self::STANDARD, $this->vendored('2026-10-01', self::STANDARD));

        $this->assertSame(0, $run['status'], "A current copy must pass.\n".$run['output']);
        $this->assertStringContainsString(
            'is version 2026-10-01 and matches',
            $run['output'],
            'The step says what it compared and what it found; a silent pass is indistinguishable '
            ."from a step that read nothing.\n".$run['output']
        );
    }

    #[Test]
    public function a_header_left_on_an_earlier_version_is_refused(): void
    {
        $run = $this->runScript('2026-10-01', self::STANDARD, $this->vendored('2026-09-01', self::STANDARD));

        $this->assertNotSame(
            0,
            $run['status'],
            'The copy is three weeks behind the canonical clone and the gate let it through. This '
            ."is the only check that can see that at all.\n".$run['output']
        );
        $this->assertStringContainsString('declares version 2026-09-01', $run['output']);
        $this->assertStringContainsString('is on 2026-10-01', $run['output']);
    }

    #[Test]
    public function a_body_that_is_no_longer_the_canonical_file_is_refused(): void
    {
        $edited = self::STANDARD."C2. A rule somebody added here.\n";

        $run = $this->runScript('2026-10-01', self::STANDARD, $this->vendored('2026-10-01', $edited));

        $this->assertNotSame(
            0,
            $run['status'],
            'The body was edited and the header re-stamped over it, which is exactly the shape the '
            ."suite's own drift test is happy with.\n".$run['output']
        );
        $this->assertStringContainsString('is not the file it claims to be a copy of', $run['output']);
    }

    #[Test]
    public function a_canonical_file_this_box_cannot_read_is_refused(): void
    {
        $run = $this->runScript('2026-10-01', '', $this->vendored('2026-10-01', self::STANDARD));

        $this->assertNotSame(
            0,
            $run['status'],
            'An empty canonical file is an absence, not drift: taken as drift the remedy has you '
            ."re-vendor nothing at all, and a step that examined nothing is not a pass.\n".$run['output']
        );
        $this->assertStringContainsString('is missing, unreadable or empty', $run['output']);
    }

    #[Test]
    public function nothing_lets_the_canonical_clone_be_pointed_somewhere_else(): void
    {
        $script = $this->read(self::SCRIPT);

        $this->assertSame(
            1,
            preg_match_all('/^canonical='.preg_quote(self::CANONICAL, '/').'$/m', $script),
            'The canonical clone is named once, as a literal. That line is what the harness below '
            .'replaces, so a second one would leave half the script reading the real clone.'
        );

        foreach (['STANDARDS_CANONICAL', 'GATE_STANDARDS'] as $seam) {
            $this->assertStringNotContainsString(
                $seam,
                $script,
                'A variable this script reads for the canonical path is a skip switch at gate time: '
                .'one export and the step passes without comparing anything.'
            );
        }
    }

    /**
     * The script with its one canonical literal pointed at a throwaway clone, over a
     * throwaway checkout. The replacement is counted, so the literal cannot move
     * without this harness saying so. docs/DECISIONS.md
     *
     * @return array{status: int, output: string}
     */
    private function runScript(string $version, string $standard, string $vendored): array
    {
        $sandbox = sys_get_temp_dir().'/orbit-standards-'.bin2hex(random_bytes(6));
        $clone = $sandbox.'/canonical';
        $root = $sandbox.'/root';

        $this->assertTrue(mkdir($clone, 0o700, true), "Could not create {$clone}");
        $this->assertTrue(mkdir($root.'/docs', 0o700, true), "Could not create {$root}");
        $this->assertTrue(mkdir($root.'/scripts', 0o700, true), "Could not create {$root}/scripts");

        try {
            file_put_contents($clone.'/VERSION', $version."\n");
            file_put_contents($clone.'/ENGINEERING-STANDARDS.md', $standard);
            file_put_contents($root.'/docs/STANDARDS.md', $vendored);

            $script = str_replace(self::CANONICAL, $clone, $this->read(self::SCRIPT), $count);

            $this->assertSame(
                1,
                $count,
                'The canonical clone is no longer named exactly once in '.self::SCRIPT.', so this '
                .'harness is not running the script it says it is running.'
            );

            file_put_contents($path = $root.'/scripts/standards-drift.sh', $script);

            $result = $this->execute(['bash', $path, $root], [], $sandbox);
        } finally {
            $this->remove($sandbox);
        }

        return $result;
    }

    /** A vendored copy stamped the way the real one is: the version, then the body's own hash. */
    private function vendored(string $version, string $body): string
    {
        return '<!-- standards-version: '.$version.' · sha256: '.hash('sha256', $body)." -->\n".$body;
    }
}
