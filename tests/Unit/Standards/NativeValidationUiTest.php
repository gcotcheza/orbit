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
 * C13, and the one screen that stated a limit the app never said out loud.
 * Reads files off disk, so it keeps working where nothing boots (docs/DECISIONS.md).
 */
final class NativeValidationUiTest extends TestCase
{
    private const OVER_LIMIT = "/^const OVER_LIMIT = '(.+)'$/m";

    private const LIMIT = '/^const LIMIT = (\d+)$/m';

    private const SERVER_SENTENCE = "/'text\.max'\s*=>\s*'([^']+)'/";

    private const SERVER_LIMIT = "/'text'\s*=>\s*\[[^\]]*'max:(\d+)'/";

    #[Test]
    public function no_screen_lets_the_browser_truncate_what_somebody_typed(): void
    {
        $offenders = [];

        foreach ($this->frontEnd() as $relative => $contents) {
            if (preg_match('/\b(?:max|min)length\s*=/i', $contents) === 1) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These files enforce a length through the browser, which truncates in silence: the '
            .'sentence simply stops, with no message and nothing for a screen reader to read. '
            .'State the limit in the app’s own words instead: '.implode(', ', $offenders)
        );
    }

    #[Test]
    public function the_create_screen_states_the_limit_the_server_enforces(): void
    {
        $screen = 'resources/js/Views/Create.vue';
        $server = 'app/Http/Requests/ParseRuleRequest.php';

        $sentence = $this->matched(self::OVER_LIMIT, $screen);
        $limit = $this->matched(self::LIMIT, $screen);

        $this->assertSame(
            $this->matched(self::SERVER_SENTENCE, $server),
            $sentence,
            'The create screen says one thing about an over-long rule and the server says another. '
            .'Somebody would be told the limit twice, in two voices, and only one of them decides.'
        );

        $this->assertSame(
            $this->matched(self::SERVER_LIMIT, $server),
            $limit,
            'The create screen refuses a rule at a different length than the server does — so it '
            .'either refuses one the server would have taken, or sends one the server will not.'
        );

        $this->assertStringContainsString(
            $limit,
            $sentence,
            "The sentence both sides show does not name the limit ({$limit}) they both enforce."
        );
    }

    private function matched(string $pattern, string $relative): string
    {
        if (preg_match($pattern, $this->read($relative), $found) !== 1) {
            $this->fail("{$relative} no longer states the rule where {$pattern} looks for it.");
        }

        return $found[1];
    }

    /** @return array<string, string> path, relative to the repository root, to its contents */
    private function frontEnd(): array
    {
        $root = $this->root();
        $directory = $root.'/resources/js';

        $this->assertDirectoryExists($directory, 'resources/js is missing: this test would scan nothing.');

        $files = [];

        $found = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($found as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $files[substr($file->getPathname(), strlen($root) + 1)] = $this->contents($file->getPathname());
        }

        // An empty scan is a failure, not a pass.
        $this->assertNotSame([], $files, 'resources/js listed no file to read.');

        return $files;
    }

    private function read(string $relative): string
    {
        $path = $this->root().'/'.$relative;

        $this->assertFileExists($path, "{$relative} is missing: the rule it states is not declared anywhere.");

        return $this->contents($path);
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($path);

        $this->assertIsString($contents, "{$path} could not be read.");

        return $contents;
    }

    private function root(): string
    {
        $root = realpath(__DIR__.'/../../..');

        $this->assertIsString($root, 'The repository root could not be resolved.');

        return $root;
    }
}
