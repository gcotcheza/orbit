<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The app never opens a documentation file, which is what lets a docs-only
 * merge land without a deploy (.claude/commands/deploy.md, "Docs-only landing").
 */
final class DocsAreNotServedTest extends TestCase
{
    private const TREES = [
        'app', 'config', 'database', 'routes', 'resources/js', 'resources/views',
        'resources/css', 'public', 'bootstrap', 'docker',
    ];

    /** Not a tree: the files that build and wire the image, which can serve a path too. */
    private const INFRASTRUCTURE = [
        'docker-compose.yml', 'docker-compose.e2e.yml', 'vite.config.js', 'composer.json',
    ];

    private const SOURCE = '/(\.(php|js|ts|mjs|json|vue|css|txt|htaccess|conf|ini|yml)$|^Dockerfile)/';

    /** A quoted run with no whitespace in it is a path; a sentence that mentions a doc is prose. */
    private const DOCUMENTATION_PATH = '#[\'"`]([^\'"`\s]*(?:\bdocs\b|\bdesign\b|README|CHANGELOG|LICENSE)[^\'"`\s]*)[\'"`]#';

    private const ANY_LITERAL = '#[\'"`][^\'"`\n]*[\'"`]#';

    #[Test]
    public function no_shipped_file_names_a_documentation_path(): void
    {
        $found = [];

        foreach ($this->sources() as $path => $code) {
            foreach (explode("\n", $code) as $number => $line) {
                if (preg_match_all(self::DOCUMENTATION_PATH, $line, $matches) === 0) {
                    continue;
                }

                foreach ($matches[1] as $literal) {
                    $found[] = $path.':'.($number + 1).'  '.$literal;
                }
            }
        }

        $this->assertSame(
            [],
            $found,
            "The app names a documentation file as a path it could open or serve:\n  "
            .implode("\n  ", $found)."\n"
            .'A docs-only merge lands without a gate, a build or a restart precisely because no '
            .'running process reads a Markdown file. One path like this and that stops being true, '
            .'and the landing ships an untested change. Move what the code needs into config/ or a '
            .'fixture; a prose pointer to a document is fine, a path to one is not.'
        );
    }

    #[Test]
    public function the_scan_reaches_the_code_it_claims_to_clear(): void
    {
        $files = 0;
        $literals = 0;

        foreach ($this->sources() as $code) {
            $files++;
            $literals += (int) preg_match_all(self::ANY_LITERAL, $code);
        }

        $this->assertGreaterThan(
            200,
            $files,
            'The scan found almost no source files, so it cleared almost nothing. A tree that '
            .'moved out from under these paths reports clean for the wrong reason.'
        );
        $this->assertGreaterThan(
            1000,
            $literals,
            'The scan read hardly any string literals. Whatever it is stripping as a comment is '
            .'stripping the code as well, and it would pass on a file that opens a document.'
        );
    }

    /** @return iterable<string, string> repository-relative path => code with comments stripped */
    private function sources(): iterable
    {
        $root = dirname(__DIR__, 3);

        foreach (self::INFRASTRUCTURE as $file) {
            $path = $root.'/'.$file;

            $this->assertFileExists($path, "{$file} is missing, so it was not scanned.");

            yield $file => $this->withoutComments((string) file_get_contents($path));
        }

        foreach (self::TREES as $tree) {
            $directory = $root.'/'.$tree;

            $this->assertDirectoryExists($directory, "{$tree}/ is missing, so it was not scanned.");

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || preg_match(self::SOURCE, $file->getFilename()) !== 1) {
                    continue;
                }

                $relative = substr($file->getPathname(), strlen($root) + 1);

                yield $relative => $this->withoutComments((string) file_get_contents($file->getPathname()));
            }
        }
    }

    private function withoutComments(string $code): string
    {
        $stripped = preg_replace(
            ['#/\*.*?\*/#s', '#<!--.*?-->#s', '#\{\{--.*?--\}\}#s'],
            '',
            $code
        ) ?? $code;

        return implode("\n", preg_grep('#^\s*(//|\#)#', explode("\n", $stripped), PREG_GREP_INVERT) ?: []);
    }
}
