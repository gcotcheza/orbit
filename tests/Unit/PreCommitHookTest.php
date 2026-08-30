<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs scripts/hooks/pre-commit for real against a planted diff; `git` is not in
 * the app image, so a stub on PATH answers it (docs/DECISIONS.md).
 */
final class PreCommitHookTest extends TestCase
{
    private const TOKEN = 'fixturetokenvalue00000000000000f';

    /** Split so that this file does not trip the pattern layer it is testing. */
    private const AWS_KEY = 'AKIA'.'IOSFODNN7EXAMPLE';

    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/orbit-pre-commit-'.bin2hex(random_bytes(6));

        mkdir($this->sandbox.'/bin', 0755, true);

        $stub = $this->sandbox.'/bin/git';

        file_put_contents($stub, implode("\n", [
            '#!/bin/sh',
            'case "$1 $2" in',
            "  'rev-parse --show-toplevel') printf '%s\\n' '{$this->sandbox}' ;;",
            "  'diff --cached') cat '{$this->sandbox}/staged.diff' ;;",
            '  *) exit 1 ;;',
            'esac',
            '',
        ]));

        chmod($stub, 0755);
    }

    protected function tearDown(): void
    {
        $this->remove($this->sandbox);
    }

    #[Test]
    public function it_refuses_a_commit_that_stages_a_value_from_this_repository_env(): void
    {
        $this->plantEnv();
        $this->plantDiff('config/orbit.php', "<?php return ['token' => '".self::TOKEN."'];");

        $result = $this->runHook();

        $this->assertNotSame(0, $result['status'], 'The hook let a staged .env value through.');
        $this->assertStringContainsString('TRAVELPAYOUTS_TOKEN found in config/orbit.php', $result['output']);
        $this->assertStringNotContainsString(
            self::TOKEN,
            $result['output'],
            'The hook printed the secret it caught — a guard that echoes a value has published it (S2).'
        );
    }

    #[Test]
    public function it_refuses_a_key_shaped_string_that_is_in_no_env(): void
    {
        $this->plantEnv();
        $this->plantDiff('deploy/aws.env', 'AWS_ACCESS_KEY_ID='.self::AWS_KEY);

        $result = $this->runHook();

        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('AWS_ACCESS_KEY_ID found in deploy/aws.env', $result['output']);
        $this->assertStringNotContainsString(self::AWS_KEY, $result['output']);
    }

    #[Test]
    public function it_still_runs_the_pattern_layer_when_there_is_no_readable_env(): void
    {
        $this->plantDiff('deploy/aws.env', 'AWS_ACCESS_KEY_ID='.self::AWS_KEY);

        $result = $this->runHook();

        $this->assertNotSame(0, $result['status'], 'An unreadable .env must not turn the guard off.');
        $this->assertStringContainsString('no readable .env', $result['output']);
        $this->assertStringContainsString('AWS_ACCESS_KEY_ID found in deploy/aws.env', $result['output']);
    }

    #[Test]
    public function it_lets_an_ordinary_change_through(): void
    {
        $this->plantEnv();
        $this->plantDiff('README.md', 'Orbit watches a handful of routes and says when one is cheap.');

        $result = $this->runHook();

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame('', $result['output']);
    }

    #[Test]
    public function it_does_not_block_the_files_that_describe_it(): void
    {
        $this->plantDiffOfRepositoryFiles([
            'scripts/hooks/pre-commit',
            'tests/Unit/PreCommitHookTest.php',
            'docs/DECISIONS.md',
        ]);

        $result = $this->runHook();

        $this->assertSame(0, $result['status'], $result['output']);
    }

    #[Test]
    public function the_hook_is_executable_because_git_silently_skips_one_that_is_not(): void
    {
        $this->assertTrue(is_executable($this->hook()));
    }

    private function hook(): string
    {
        return dirname(__DIR__, 2).'/scripts/hooks/pre-commit';
    }

    private function plantEnv(): void
    {
        file_put_contents($this->sandbox.'/.env', implode("\n", [
            'APP_NAME=Orbit',
            'SESSION_DRIVER=database',
            'DB_PASSWORD=fixturepass',
            'TRAVELPAYOUTS_TOKEN='.self::TOKEN,
            '',
        ]));
    }

    private function plantDiff(string $path, string $line): void
    {
        file_put_contents($this->sandbox.'/staged.diff', implode("\n", [
            "diff --git a/{$path} b/{$path}",
            'new file mode 100644',
            '--- /dev/null',
            "+++ b/{$path}",
            '@@ -0,0 +1 @@',
            '+'.$line,
            '',
        ]));
    }

    /**
     * @param  list<string>  $paths
     */
    private function plantDiffOfRepositoryFiles(array $paths): void
    {
        $diff = '';

        foreach ($paths as $path) {
            $lines = file(dirname(__DIR__, 2).'/'.$path, FILE_IGNORE_NEW_LINES);

            $diff .= "diff --git a/{$path} b/{$path}\n--- /dev/null\n+++ b/{$path}\n@@ -0,0 +1 @@\n";
            $diff .= '+'.implode("\n+", $lines === false ? [] : $lines)."\n";
        }

        file_put_contents($this->sandbox.'/staged.diff', $diff);
    }

    /**
     * @return array{status: int, output: string}
     */
    private function runHook(): array
    {
        $pipes = [];

        $process = proc_open(
            [$this->hook()],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->sandbox,
            ['PATH' => $this->sandbox.'/bin:/usr/local/bin:/usr/bin:/bin', 'HOME' => $this->sandbox]
        );

        if ($process === false) {
            $this->fail('Could not start '.$this->hook());
        }

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'output' => $output];
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path.'/'.$entry);
            }
        }

        rmdir($path);
    }
}
