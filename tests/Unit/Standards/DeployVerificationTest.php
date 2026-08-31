<?php

declare(strict_types=1);

namespace Tests\Unit\Standards;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The post-deploy battery is repeatable because it does not write, and a
 * documented pause is documented with its restore (docs/DECISIONS.md).
 */
final class DeployVerificationTest extends TestCase
{
    private const RUNBOOK = '.claude/commands/deploy.md';

    private const VERIFICATION = 'Post-deploy verification';

    private const WRITES = '/-X (POST|PUT|PATCH|DELETE)\b/';

    #[Test]
    public function the_post_deploy_battery_makes_no_authenticated_write(): void
    {
        foreach ($this->curlCommands($this->section(self::VERIFICATION)) as $command) {
            if (preg_match(self::WRITES, $command) !== 1) {
                continue;
            }

            $this->assertStringNotContainsString(
                '$AUTHED',
                $command,
                'A command in the post-deploy battery signs a write with the logged-in session. '
                ."The section tells its reader every check is safe to repeat, and that promise is\n"
                ."what gets it run top to bottom against production:\n  {$command}\n"
                .'Writes belong under "## Authenticated writes", outside the numbered list.'
            );
        }
    }

    #[Test]
    public function a_documented_pause_is_documented_with_the_command_that_restores_it(): void
    {
        $commands = $this->curlCommands($this->read(self::RUNBOOK));

        foreach ($this->routeCodes($commands, 'false') as $code) {
            $this->assertContains(
                $code,
                $this->routeCodes($commands, 'true'),
                "The runbook pauses {$code} and never puts it back. A paused route is skipped by "
                .'the morning poll in silence — no alert fires and nothing says so — so the '
                .'restore is not optional and cannot live in the operator\'s head.'
            );
        }
    }

    /**
     * @param  list<string>  $commands
     * @return list<string>
     */
    private function routeCodes(array $commands, string $active): array
    {
        $codes = [];

        foreach ($commands as $command) {
            if (preg_match('/"active"\s*:\s*'.$active.'\b/', $command) !== 1) {
                continue;
            }

            if (preg_match('#/api/watchlist/([A-Z]{3}-[A-Z]{3})#', $command, $found) === 1) {
                $codes[] = $found[1];
            }
        }

        return array_values(array_unique($codes));
    }

    /** @return list<string> */
    private function curlCommands(string $markdown): array
    {
        preg_match_all('/^[ \t]*```[a-z]*$(.*?)^[ \t]*```$/ms', $markdown, $blocks);

        $joined = preg_replace('/\\\\\n\s*/', ' ', implode("\n", $blocks[1])) ?? '';

        preg_match_all('/^\s*curl .*$/m', $joined, $found);

        return array_map(trim(...), $found[0]);
    }

    private function section(string $title): string
    {
        $pattern = '/^## '.preg_quote($title, '/').'\b.*?$(.*?)(?=^## |\z)/ms';

        if (preg_match($pattern, $this->read(self::RUNBOOK), $found) !== 1) {
            $this->fail("The deploy runbook has no '## {$title}' section to read.");
        }

        return $found[1];
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
