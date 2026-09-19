<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The deploy that ships the deploy script arrives WITH it, so the run has to come
 * from a clone. docs/DECISIONS.md: a-deploy-script-has-to-be-able-to-land-itself.
 */
final class DeployScriptLandsItselfTest extends TestCase
{
    private const SCRIPT = 'scripts/deploy.sh';

    /** The helpers the script runs. The vendored lib/ already resolves from $0. */
    private const HELPERS = ['docs-only.sh', 'verify.sh'];

    /** The battery defaults to /var/www/orbit, which is the wrong tree from a clone. */
    private const BATTERY = 'verify.sh';

    #[Test]
    public function no_helper_is_addressed_through_the_checkout_being_deployed(): void
    {
        $offenders = [];

        foreach ($this->lines() as $number => $line) {
            if (preg_match('/\$\{?ROOT\}?\/scripts\//', $line) === 1) {
                $offenders[] = $number.': '.trim($line);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "scripts/deploy.sh runs something out of the checkout it is deploying:\n"
            .implode("\n", $offenders)."\n"
            .'A deploy carries this script and its helpers in the same merge, so that checkout has '
            .'neither file until the merge lands: the run exits 127 and the deploy is finished by '
            .'hand. It happened on 2026-09-19 with PR #89.'
        );
    }

    #[Test]
    public function every_helper_is_resolved_from_the_script_directory(): void
    {
        foreach (self::HELPERS as $helper) {
            $calls = $this->callsTo($helper);

            $this->assertNotSame(
                [],
                $calls,
                "scripts/deploy.sh no longer runs {$helper} at all, so this test is clearing a "
                .'script that has stopped doing the thing it is guarding.'
            );

            foreach ($calls as $number => $line) {
                $this->assertStringContainsString(
                    '"$SCRIPT_DIR/'.$helper.'"',
                    $line,
                    "scripts/deploy.sh:{$number} addresses {$helper} through somewhere other than the "
                    ."directory the script is being read from:\n{$line}"
                );
            }
        }
    }

    #[Test]
    public function the_script_directory_is_absolute_and_taken_before_anything_changes_directory(): void
    {
        $assignments = [];
        $firstChdir = null;

        foreach ($this->lines() as $number => $line) {
            if (preg_match('/^SCRIPT_DIR=/', $line) === 1) {
                $assignments[$number] = $line;
            }

            if ($firstChdir === null && preg_match('/^\s*cd\s/', $line) === 1) {
                $firstChdir = $number;
            }
        }

        $this->assertCount(
            1,
            $assignments,
            'The script directory is named once, at the top, and nothing later redefines it.'
        );

        $number = (int) array_key_first($assignments);

        $this->assertStringContainsString(
            'pwd',
            $assignments[$number],
            'A bare dirname is relative to the working directory, and this script changes directory '
            .'twice. The value has to be resolved to an absolute path where it is taken.'
        );

        $this->assertIsInt($firstChdir, 'The script no longer changes directory; this guard is watching nothing.');
        $this->assertLessThan(
            $firstChdir,
            $number,
            "scripts/deploy.sh:{$number} takes the script directory after the cd at line {$firstChdir}, "
            .'by which point a relative path resolves against the checkout being deployed.'
        );
    }

    #[Test]
    public function the_battery_is_told_which_checkout_to_verify(): void
    {
        $calls = $this->callsTo(self::BATTERY);

        $this->assertNotSame([], $calls, 'The script no longer runs the battery; this guard is watching nothing.');

        foreach ($calls as $number => $line) {
            $this->assertStringContainsString(
                'ORBIT_DIR="$ROOT"',
                $line,
                "scripts/deploy.sh:{$number} runs the battery without naming the checkout:\n{$line}\n"
                .'Its own default is /var/www/orbit, so from a clone it would read the containers and '
                .'the bundle of a tree this run never touched and call the deploy green.'
            );
        }
    }

    /**
     * Call sites, not mentions: the script quotes every path it runs, and a helper
     * named inside a sentence the script prints is prose.
     *
     * @return array<int, string>
     */
    private function callsTo(string $helper): array
    {
        $calls = [];

        foreach ($this->lines() as $number => $line) {
            if (str_contains($line, '/'.$helper.'"')) {
                $calls[$number] = trim($line);
            }
        }

        return $calls;
    }

    /** @return array<int, string> */
    private function lines(): array
    {
        $path = dirname(__DIR__, 3).'/'.self::SCRIPT;

        $this->assertFileExists($path, self::SCRIPT.' is missing.');

        $contents = file_get_contents($path);

        $this->assertIsString($contents, self::SCRIPT.' could not be read.');

        $lines = [];

        foreach (explode("\n", $contents) as $index => $line) {
            $lines[$index + 1] = $line;
        }

        return $lines;
    }
}
