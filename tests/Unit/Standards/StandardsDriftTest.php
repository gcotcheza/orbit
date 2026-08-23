<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PHPUnit's TestCase, not Tests\TestCase — this reads files off disk and must
 * keep working in a checkout where nothing boots (docs/DECISIONS.md).
 */
final class StandardsDriftTest extends TestCase
{
    private const HEADER = '/^<!-- standards-version: (\S+) · sha256: ([0-9a-f]{64}) -->$/';

    #[Test]
    public function the_vendored_standard_matches_the_hash_in_its_own_header(): void
    {
        [, $declared] = $this->header();

        $this->assertSame(
            $declared,
            hash('sha256', $this->body()),
            'docs/STANDARDS.md has been edited locally. The fleet standard is vendored, '
            .'not authored here: change it in the engineering-standards repository and '
            .'re-vendor the whole file, header included.'
        );
    }

    #[Test]
    public function the_declared_version_is_a_date(): void
    {
        [$version] = $this->header();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            $version,
            "docs/STANDARDS.md declares version '{$version}'; it is the canonical VERSION file, a date."
        );
    }

    #[Test]
    public function the_rule_file_is_a_symlink_to_the_vendored_standard(): void
    {
        $link = $this->path('.claude/rules/standards.md');

        $this->assertTrue(
            is_link($link),
            '.claude/rules/standards.md must be a symlink to docs/STANDARDS.md, not a second copy: '
            .'one set of bytes is what makes the hash above mean anything.'
        );

        $this->assertSame('../../docs/STANDARDS.md', readlink($link));

        $resolved = realpath($link);

        $this->assertIsString($resolved, '.claude/rules/standards.md points at nothing.');
        $this->assertSame(realpath($this->path('docs/STANDARDS.md')), $resolved);
    }

    /** @return array{string, string} the declared version and sha256 */
    private function header(): array
    {
        $first = strstr($this->contents(), "\n", true);

        $this->assertIsString($first, 'docs/STANDARDS.md is a single line and cannot be the vendored standard.');

        if (preg_match(self::HEADER, $first, $found) !== 1) {
            $this->fail("docs/STANDARDS.md's first line is not the vendoring header: {$first}");
        }

        return [$found[1], $found[2]];
    }

    private function body(): string
    {
        $contents = $this->contents();
        $break = strpos($contents, "\n");

        $this->assertIsInt($break);

        return substr($contents, $break + 1);
    }

    private function contents(): string
    {
        $vendored = $this->path('docs/STANDARDS.md');

        $this->assertFileExists($vendored, 'docs/STANDARDS.md is missing: the fleet standard is not vendored here.');

        $contents = file_get_contents($vendored);

        $this->assertIsString($contents, 'docs/STANDARDS.md could not be read.');

        return $contents;
    }

    private function path(string $relative): string
    {
        return __DIR__.'/../../../'.$relative;
    }
}
