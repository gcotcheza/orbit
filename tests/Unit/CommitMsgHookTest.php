<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs scripts/hooks/commit-msg for real against a stubbed `git`, which is not in
 * the app image, and a fixture fleet directory standing in for the system one.
 */
final class CommitMsgHookTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().'/orbit-commit-msg-'.bin2hex(random_bytes(6));

        mkdir($this->sandbox.'/bin', 0755, true);
        mkdir($this->sandbox.'/fleet', 0755, true);

        file_put_contents($this->sandbox.'/fleetdir', $this->sandbox."/fleet\n");
        file_put_contents($this->sandbox.'/bin/git', implode("\n", [
            '#!/bin/sh',
            'case "$1 $2" in',
            "  'config --system') [ -f '{$this->sandbox}/fleetdir' ] || exit 1",
            "     cat '{$this->sandbox}/fleetdir' ;;",
            '  *) exit 1 ;;',
            'esac',
            '',
        ]));
        chmod($this->sandbox.'/bin/git', 0755);
    }

    protected function tearDown(): void
    {
        foreach (['bin/git', 'fleet/commit-msg', 'fleetdir', 'fleet.args', 'fleet.stdin'] as $file) {
            if (is_file($this->sandbox.'/'.$file)) {
                unlink($this->sandbox.'/'.$file);
            }
        }

        foreach (['bin', 'fleet', ''] as $directory) {
            if (is_dir($this->sandbox.'/'.$directory)) {
                rmdir($this->sandbox.'/'.$directory);
            }
        }
    }

    #[Test]
    public function the_fleet_hook_gets_the_message_file_and_stdin_and_its_refusal_stands(): void
    {
        $this->plantFleetHook(1);

        $result = $this->runHook('.git/COMMIT_EDITMSG', "piped through\n");

        $this->assertSame(
            1,
            $result['status'],
            'The fleet commit-msg refused and the commit went ahead: this hook replaces it through '
            ."core.hooksPath, so its refusal has to be this hook's exit status.\n".$result['output']
        );
        $this->assertStringContainsString('FLEET COMMIT-MSG REFUSED', $result['output']);
        $this->assertSame(".git/COMMIT_EDITMSG\n", (string) file_get_contents($this->sandbox.'/fleet.args'));
        $this->assertSame("piped through\n", (string) file_get_contents($this->sandbox.'/fleet.stdin'));
    }

    #[Test]
    public function a_message_the_fleet_hook_accepts_passes_without_a_word(): void
    {
        $this->plantFleetHook(0);

        $result = $this->runHook('.git/COMMIT_EDITMSG');

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertSame('', $result['output']);
    }

    #[Test]
    public function with_no_fleet_hook_to_hand_over_to_it_passes_and_says_so(): void
    {
        $result = $this->runHook('.git/COMMIT_EDITMSG');

        $this->assertSame(0, $result['status'], $result['output']);
        $this->assertStringContainsString(
            'no fleet commit-msg at '.$this->sandbox.'/fleet, so nothing checked this message.',
            $result['output'],
            'A box with no fleet hook must not block every commit, and must not let one through '
            ."as if the fleet hook had run either.\n".$result['output']
        );
    }

    #[Test]
    public function the_hook_is_executable_because_git_silently_skips_one_that_is_not(): void
    {
        $this->assertTrue(is_executable($this->hook()));
    }

    /** A fleet commit-msg that records its argv and stdin, then exits as told. */
    private function plantFleetHook(int $exit): void
    {
        $hook = $this->sandbox.'/fleet/commit-msg';

        file_put_contents($hook, implode("\n", [
            '#!/bin/sh',
            "printf '%s\\n' \"\$*\" > '{$this->sandbox}/fleet.args'",
            "cat > '{$this->sandbox}/fleet.stdin'",
            $exit === 0 ? 'exit 0' : "echo 'FLEET COMMIT-MSG REFUSED' >&2; exit {$exit}",
            '',
        ]));
        chmod($hook, 0755);
    }

    private function hook(): string
    {
        return dirname(__DIR__, 2).'/scripts/hooks/commit-msg';
    }

    /**
     * @return array{status: int, output: string}
     */
    private function runHook(string $messageFile, string $stdin = ''): array
    {
        $pipes = [];

        $process = proc_open(
            [$this->hook(), $messageFile],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->sandbox,
            [
                'PATH' => $this->sandbox.'/bin:/usr/local/bin:/usr/bin:/bin',
                'HOME' => $this->sandbox,
            ]
        );

        if ($process === false) {
            $this->fail('Could not start '.$this->hook());
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);

        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'output' => $output];
    }
}
