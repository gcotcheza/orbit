<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use SplFileInfo;
use FilesystemIterator;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The two stack guards stay two, and the gate's guard never waits on docker unbounded;
 * scripts/check-test.sh runs the guard and the lint on the host.
 */
final class CheckPreflightTest extends TestCase
{
    use RunsGateScripts;

    private const SCRIPT = 'scripts/check.sh';

    #[Test]
    public function the_stack_guard_is_not_the_browser_gates_guard(): void
    {
        $check = $this->withoutComments($this->read(self::SCRIPT));
        $e2e = $this->withoutComments($this->read('scripts/e2e.sh'));

        $this->assertStringContainsString(
            'stack_is_foreign() {',
            $check,
            'The gate has lost the guard that keeps it off a stack another directory started.'
        );
        $this->assertStringContainsString(
            'checkout_is_live() {',
            $e2e,
            'The browser gate has lost the guard that keeps it out of the served checkout.'
        );

        $merged = 'Each guard\'s name has turned up in the other script, which is the visible half '
            .'of merging them; a merge under some third name would walk straight past this test, and '
            .'what holds the two apart is docs/DECISIONS.md, the-two-stack-guards-are-not-one. They '
            .'only look alike (fleet standard C2): scripts/check.sh asks whether the stack it is '
            .'about to gate was started from this directory, scripts/e2e.sh whether this checkout is '
            .'the one being served. Sharing them makes one caller inherit the other question.';

        $mention = $merged."\nA pointer to the other guard is allowed in a FULL-LINE comment, which "
            .'is all this test strips; a trailing # on a line of code fails it.';

        $this->assertStringNotContainsString('checkout_is_live', $check, $mention);
        $this->assertStringNotContainsString('stack_is_foreign', $e2e, $mention);

        foreach ($this->shellFilesOnDisk() as $file) {
            if ($file === self::SCRIPT || $file === 'scripts/e2e.sh') {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                '/^(stack_is_foreign|checkout_is_live)\(\) \{/m',
                $this->read($file),
                $merged."\n".$file.' now defines one of them.'
            );
        }
    }

    #[Test]
    public function every_docker_call_in_the_stack_guard_has_a_timeout(): void
    {
        if (preg_match('/^stack_is_foreign\(\) \{$(.*?)^\}$/ms', $this->read(self::SCRIPT), $found) !== 1) {
            $this->fail('scripts/check.sh no longer has a stack_is_foreign function to read.');
        }

        foreach (explode("\n", $found[1]) as $line) {
            $code = (string) preg_replace('/(^|\s)#.*$/', '', $line);
            $code = (string) preg_replace('/"[^"]*"|\'[^\']*\'/', ' ', $code);
            $calls = (int) preg_match_all('/\bdocker\s+[a-z]/', $code);

            if ($calls === 0) {
                continue;
            }

            $this->assertSame(
                $calls,
                (int) preg_match_all('/\btimeout\s+\d+\s+docker\s+[a-z]/', $code),
                'A docker call in the guard has no timeout — this counts every subcommand, not a '
                .'list of the ones somebody thought of, so a later `docker info` is covered too. A '
                .'daemon that REFUSES leaves the guard fail-closed, which is correct; a daemon that '
                ."HANGS hangs the gate:\n  ".trim($line)
            );
        }
    }

    /**
     * Every file under scripts/ the gate must lint: a .sh, or a shell declared in its first
     * two lines.
     *
     * @return list<string>
     */
    private function shellFilesOnDisk(): array
    {
        $files = [];

        foreach ($this->treeUnderScripts() as $file) {
            $path = substr($file->getPathname(), strlen($this->root()) + 1);

            if (str_ends_with($path, '.sh')) {
                $files[] = $path;

                continue;
            }

            $lines = file($file->getPathname());

            if ($lines === false) {
                $this->fail($file->getPathname().' could not be read.');
            }

            if (preg_match('/^#!.*sh|^# shellcheck shell=/m', implode('', array_slice($lines, 0, 2))) === 1) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    /** @return list<SplFileInfo> */
    private function treeUnderScripts(): array
    {
        $files = [];

        /** @var iterable<string, SplFileInfo> $tree */
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root().'/scripts', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->isFile()) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function withoutComments(string $script): string
    {
        return implode("\n", preg_grep('/^\s*#/', explode("\n", $script), PREG_GREP_INVERT) ?: []);
    }
}
