<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs scripts/hooks/pre-commit for real. Most cases stub `git`, which is not in
 * the app image; the linked-worktree case needs the real thing and says so.
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
        mkdir($this->sandbox.'/nobin', 0755, true);

        file_put_contents($this->sandbox.'/toplevel', $this->sandbox."\n");

        $stub = $this->sandbox.'/bin/git';

        file_put_contents($stub, implode("\n", [
            '#!/bin/sh',
            'case "$1 $2" in',
            "  'rev-parse --show-toplevel') cat '{$this->sandbox}/toplevel' ;;",
            "  'rev-parse --git-common-dir') [ -f '{$this->sandbox}/commondir' ] || exit 1",
            "     cat '{$this->sandbox}/commondir' ;;",
            "  'diff --cached') cat '{$this->sandbox}/staged.diff' ;;",
            '  *) exit 1 ;;',
            'esac',
            '',
        ]));

        chmod($stub, 0755);

        foreach (['bash', 'grep', 'sed', 'cut', 'cat'] as $tool) {
            $real = trim((string) shell_exec('command -v '.$tool.' 2>/dev/null'));

            if ($real !== '') {
                symlink($real, $this->sandbox.'/nobin/'.$tool);
            }
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->sandbox);
    }

    #[Test]
    public function it_refuses_a_commit_that_stages_a_value_from_this_repository_env(): void
    {
        $this->plantEnv($this->sandbox);
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
    public function it_finds_the_env_through_the_shared_git_dir_when_the_worktree_has_none(): void
    {
        mkdir($this->sandbox.'/main/.git', 0755, true);
        mkdir($this->sandbox.'/wt', 0755, true);

        $this->plantEnv($this->sandbox.'/main');
        file_put_contents($this->sandbox.'/toplevel', $this->sandbox."/wt\n");
        file_put_contents($this->sandbox.'/commondir', $this->sandbox."/main/.git\n");

        $this->plantDiff('config/orbit.php', "<?php return ['token' => '".self::TOKEN."'];");

        $result = $this->runHook();

        $this->assertNotSame(
            0,
            $result['status'],
            'A linked worktree has no .env of its own; resolving it through --git-common-dir is what keeps layer two alive there.'
        );
        $this->assertStringContainsString('TRAVELPAYOUTS_TOKEN found in config/orbit.php', $result['output']);
        $this->assertStringNotContainsString(self::TOKEN, $result['output']);
    }

    #[Test]
    public function it_refuses_when_it_cannot_read_an_env_at_all(): void
    {
        $this->plantDiff('README.md', 'An ordinary line.');

        $result = $this->runHook();

        $this->assertNotSame(0, $result['status'], 'No .env means layer two cannot run, and a half-check must not pass.');
        $this->assertStringContainsString('refusing rather than half-checking', $result['output']);
    }

    #[Test]
    public function it_refuses_when_a_tool_it_needs_is_missing(): void
    {
        $this->plantEnv($this->sandbox);
        $this->plantDiff('deploy/aws.env', 'AWS_ACCESS_KEY_ID='.self::AWS_KEY);

        $result = $this->runHook($this->sandbox.'/bin:'.$this->sandbox.'/nobin');

        $this->assertNotSame(0, $result['status'], 'With awk off PATH the hook scanned nothing; it must refuse, not pass.');
        $this->assertStringContainsString('awk is not on PATH', $result['output']);
    }

    #[Test]
    public function it_refuses_a_key_shaped_string_that_is_in_no_env(): void
    {
        $this->plantEnv($this->sandbox);
        $this->plantDiff('deploy/aws.env', 'AWS_ACCESS_KEY_ID='.self::AWS_KEY);

        $result = $this->runHook();

        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('AWS_ACCESS_KEY_ID found in deploy/aws.env', $result['output']);
        $this->assertStringNotContainsString(self::AWS_KEY, $result['output']);
    }

    #[Test]
    public function it_guards_a_key_name_the_suffix_list_alone_would_miss(): void
    {
        $this->plantEnv($this->sandbox);
        $this->plantDiff('config/db.php', "<?php return ['pass' => 'shortpassbutlongenough'];");

        $result = $this->runHook();

        $this->assertNotSame(0, $result['status']);
        $this->assertStringContainsString('DB_PASS found in config/db.php', $result['output']);
    }

    #[Test]
    public function it_blocks_the_password_embedded_in_a_connection_string(): void
    {
        $this->plantEnv($this->sandbox);
        $this->plantDiff('config/queue.php', "<?php return ['pw' => 'dsnpasswordpart'];");

        $result = $this->runHook();

        $this->assertNotSame(0, $result['status'], 'A DSN leaks by its password component, not by the whole URL.');
        $this->assertStringContainsString('SENTRY_DSN (embedded password) found in config/queue.php', $result['output']);
        $this->assertStringNotContainsString('dsnpasswordpart', $result['output']);
    }

    #[Test]
    public function it_lets_an_ordinary_change_through(): void
    {
        $this->plantEnv($this->sandbox);
        $this->plantDiff('README.md', 'Orbit watches a handful of routes and says when one is cheap.');

        $result = $this->runHook();

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame('', $result['output']);
    }

    #[Test]
    public function it_does_not_block_the_files_that_describe_it(): void
    {
        file_put_contents($this->sandbox.'/.env', 'UNRELATED_TOKEN='.bin2hex(random_bytes(16))."\n");

        $this->plantDiffOfRepositoryFiles([
            'scripts/hooks/pre-commit',
            'scripts/install-hooks.sh',
            'tests/Unit/PreCommitHookTest.php',
            'docs/DECISIONS.md',
        ]);

        $result = $this->runHook();

        $this->assertSame(0, $result['status'], $result['output']);
    }

    #[Test]
    public function it_blocks_from_a_real_linked_worktree(): void
    {
        $git = trim((string) shell_exec('command -v git 2>/dev/null'));

        if ($git === '') {
            $this->markTestSkipped('No git in this image, so the linked-worktree path cannot be exercised for real here.');
        }

        $script = str_replace(
            ['{SANDBOX}', '{HOOKS}', '{TOKEN}'],
            [$this->sandbox, dirname(__DIR__, 2).'/scripts/hooks', self::TOKEN],
            <<<'SH'
                set -e
                export HOME={SANDBOX} GIT_CONFIG_NOSYSTEM=1
                git init -q -b main {SANDBOX}/main
                cd {SANDBOX}/main
                git config user.name T
                git config user.email t@example.invalid
                git config core.hooksPath {HOOKS}
                printf 'TRAVELPAYOUTS_TOKEN=%s\n' '{TOKEN}' > .env
                printf '.env\n' > .gitignore
                git add .gitignore
                git commit -q -m init
                git worktree add -q {SANDBOX}/wt -b side
                cd {SANDBOX}/wt
                printf "<?php return ['t' => '%s'];\n" '{TOKEN}' > leak.php
                git add leak.php
                git commit -m leak
                SH
        );

        $result = $this->execute(['sh', '-c', $script], $this->sandbox, dirname($git).':/usr/local/bin:/usr/bin:/bin');

        $this->assertNotSame(0, $result['status'], 'A real linked worktree committed a live .env value.');
        $this->assertStringContainsString('TRAVELPAYOUTS_TOKEN', $result['output']);
        $this->assertStringNotContainsString(self::TOKEN, $result['output']);
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

    private function plantEnv(string $directory): void
    {
        file_put_contents($directory.'/.env', implode("\n", [
            'APP_NAME=Orbit',
            'SESSION_DRIVER=database',
            'DB_PASS=shortpassbutlongenough',
            'SENTRY_DSN=https://someuser:dsnpasswordpart@sentry.example.invalid/42',
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
    private function runHook(?string $path = null): array
    {
        $toplevel = trim((string) file_get_contents($this->sandbox.'/toplevel'));

        return $this->execute([$this->hook()], $toplevel, $path);
    }

    /**
     * @param  list<string>  $command
     * @return array{status: int, output: string}
     */
    private function execute(array $command, string $cwd, ?string $path): array
    {
        $pipes = [];

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            [
                'PATH' => $path ?? $this->sandbox.'/bin:/usr/local/bin:/usr/bin:/bin',
                'HOME' => $this->sandbox,
            ]
        );

        if ($process === false) {
            $this->fail('Could not start '.implode(' ', $command));
        }

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'output' => $output];
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
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
