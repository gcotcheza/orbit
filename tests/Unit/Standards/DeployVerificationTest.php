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

    /** curl names a method two ways, and a body with no method named is a POST regardless. */
    private const WRITES = '/(?:-X|--request)\s*(?:POST|PUT|PATCH|DELETE)\b'
        .'|(?:^|\s)(?:-d|--data|--data-raw|--data-binary|--data-urlencode|--json)\b/';

    private const AUTHENTICATED = '/\$\{?AUTHED\b/';

    #[Test]
    public function the_post_deploy_battery_makes_no_authenticated_write(): void
    {
        foreach ($this->curlCommands($this->section(self::VERIFICATION)) as $command) {
            if (preg_match(self::WRITES, $command) !== 1) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                self::AUTHENTICATED,
                $command,
                'A command in the post-deploy battery signs a write with the logged-in session. '
                ."The section tells its reader no check changes application data, and that promise\n"
                ."is what gets it run top to bottom against production:\n  {$command}\n"
                .'Writes belong under "## Authenticated writes", outside the numbered list.'
            );
        }
    }

    #[Test]
    public function a_documented_pause_is_documented_with_the_command_that_restores_it(): void
    {
        $commands = $this->curlCommands($this->read(self::RUNBOOK));
        $restores = $this->watchlistWrites($commands, 'true');

        foreach ($this->watchlistWrites($commands, 'false') as $code => $pauses) {
            $this->assertArrayHasKey(
                $code,
                $restores,
                "The runbook pauses {$code} and never puts it back. A paused route is skipped by "
                .'the morning poll in silence — no alert fires and nothing says so — so the '
                ."restore is not optional and cannot live in the operator's head."
            );
            $this->assertGreaterThan(
                min($pauses),
                max($restores[$code]),
                "Every restore for {$code} is written above the pause that needs it. An operator "
                .'working down the block runs the pause last and walks away from a paused route.'
            );
        }
    }

    /**
     * @param  list<string>  $commands
     * @return array<string, non-empty-list<int>> route code => positions of the commands setting it
     */
    private function watchlistWrites(array $commands, string $active): array
    {
        $found = [];

        foreach ($commands as $position => $command) {
            if (preg_match('/"active"\s*:\s*'.$active.'\b/', $command) !== 1) {
                continue;
            }

            if (preg_match('#/api/watchlist/([^"\s/]+)#', $command, $route) === 1) {
                $found[$route[1]][] = $position;
            }
        }

        return $found;
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
