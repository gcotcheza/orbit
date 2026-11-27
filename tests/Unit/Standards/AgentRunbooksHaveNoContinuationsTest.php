<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use SplFileInfo;
use RecursiveIteratorIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use PHPUnit\Framework\Attributes\Test;

/**
 * The fleet's guard belt reads each fenced line on its own, so a command split
 * with a trailing backslash is judged in pieces (card 1192).
 */
final class AgentRunbooksHaveNoContinuationsTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    private const RUNBOOK = '.claude/commands/deploy.md';

    #[Test]
    public function no_line_an_agent_runs_from_ends_in_a_backslash(): void
    {
        $files = $this->agentFacingFiles();
        $offenders = [];

        $this->assertContains(self::RUNBOOK, $files, 'The deploy runbook was not scanned; the scan lost its files.');

        foreach ($files as $relative) {
            $lines = file(self::ROOT.'/'.$relative, FILE_IGNORE_NEW_LINES);

            $this->assertIsArray($lines, "{$relative} could not be read.");

            foreach ($lines as $index => $line) {
                if (str_ends_with(rtrim($line, "\r"), '\\')) {
                    $offenders[] = "{$relative}:".($index + 1).': '.trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Join each continued command onto one line; the guard belt judges every line alone:\n"
            .implode("\n", $offenders)
        );
    }

    /** @return list<string> CLAUDE.md and every file under .claude/, relative to the repo root. */
    private function agentFacingFiles(): array
    {
        $files = ['CLAUDE.md'];
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::ROOT.'/.claude', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile()) {
                $files[] = substr($file->getPathname(), strlen(self::ROOT) + 1);
            }
        }

        sort($files);

        return $files;
    }
}
